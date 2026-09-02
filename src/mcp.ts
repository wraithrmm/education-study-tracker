import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import {
  gradeFor,
  weeksSince,
  STATUS_LABEL,
  STATUS_POINTS,
  type Status,
  type Store,
  type Subject,
} from "./db.js";

const StatusEnum = z.enum([
  "notstarted",
  "gap",
  "developing",
  "secure",
  "examready",
]);

const ISO_DATE = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}$/, "Use YYYY-MM-DD");

const text = (s: string) => ({ content: [{ type: "text" as const, text: s }] });

/** Tools that need a subject share this lookup and its error message. */
function resolve(store: Store, slug: string): Subject | string {
  const subject = store.getSubject(slug);
  if (subject) return subject;
  const known = store.listSubjects().map((s) => s.slug);
  return known.length
    ? `No subject "${slug}". Known subjects: ${known.join(", ")}. Call tracker_list_subjects first.`
    : `No subject "${slug}", and none exist yet. Create one with tracker_create_subject.`;
}

function progress(store: Store, slug: string) {
  const topics = store.listTopics(slug);
  const points = topics.reduce((n, t) => n + STATUS_POINTS[t.status as Status], 0);
  const pct = topics.length ? Math.round((points / (topics.length * 3)) * 100) : 0;
  const counts: Record<string, number> = {};
  for (const t of topics) counts[t.status] = (counts[t.status] ?? 0) + 1;
  return { topics, pct, counts };
}

const topicLine = (t: {
  ref: string;
  name: string;
  strand: string;
  tier: string;
  status: string;
  last_touched: string | null;
  watch: string | null;
}) =>
  `| ${t.ref} | ${t.name} | ${t.strand} | ${t.tier} | ${STATUS_LABEL[t.status as Status]} | ${
    t.last_touched ?? "—"
  } | ${t.watch ? t.watch.replace(/\|/g, "/") : ""} |`;

const TOPIC_HEADER =
  "| Ref | Topic | Strand | Tier | Status | Last touched | Loose end |\n|---|---|---|---|---|---|---|";

export function buildMcpServer(store: Store): McpServer {
  const server = new McpServer({ name: "tracker-mcp-server", version: "1.0.0" });

  // ---- read -----------------------------------------------------------

  server.registerTool(
    "tracker_list_subjects",
    {
      title: "List tracked subjects",
      description: `List every subject in the study tracker with a one-line progress summary.

Call this first when you do not already know the subject slug. Returns, per subject: slug, display name, spec code, tier, exam date, topic count, and the percentage of the specification covered (weighted: developing = 1, secure = 2, exam-ready = 3, out of 3 per topic).

Takes no arguments. Read-only.`,
      inputSchema: {},
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    },
    async () => {
      const subjects = store.listSubjects();
      if (!subjects.length) {
        return text(
          "No subjects yet. Create one with tracker_create_subject (slug, name, strands, topics).",
        );
      }
      const rows = subjects.map((s) => {
        const { topics, pct } = progress(store, s.slug);
        return `| ${s.slug} | ${s.name} | ${s.spec_code ?? "—"} | ${s.tier ?? "—"} | ${
          s.exam_date ?? "—"
        } | ${topics.length} | ${pct}% |`;
      });
      return text(
        `| Slug | Subject | Spec | Tier | Exam | Topics | Covered |\n|---|---|---|---|---|---|---|\n${rows.join("\n")}`,
      );
    },
  );

  server.registerTool(
    "tracker_get_state",
    {
      title: "Get topic state for a subject",
      description: `Return the current RAG state of every topic in a subject — the authoritative record of what has been learned.

Consult this before teaching anything, so you do not reteach secure material or teach a topic whose prerequisite is still a gap.

Args:
  - subject (string): subject slug, e.g. "maths"
  - status (array, optional): filter to these statuses only
  - strand (string, optional): filter to one strand key, e.g. "A" for Algebra

Returns a markdown table of Ref, Topic, Strand, Tier, Status, Last touched, Loose end, preceded by a status count summary. Read-only.`,
      inputSchema: {
        subject: z.string().min(1).describe("Subject slug, e.g. 'maths'"),
        status: z
          .array(StatusEnum)
          .optional()
          .describe("Optional status filter"),
        strand: z.string().optional().describe("Optional strand key, e.g. 'A'"),
      },
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    },
    async ({ subject, status, strand }) => {
      const s = resolve(store, subject);
      if (typeof s === "string") return text(s);
      const rows = store.listTopics(subject, {
        status: status as Status[] | undefined,
        strand,
      });
      if (!rows.length) return text("No topics match that filter.");
      const { pct, counts } = progress(store, subject);
      const summary = Object.entries(counts)
        .map(([k, n]) => `${STATUS_LABEL[k as Status]}: ${n}`)
        .join(" · ");
      return text(
        `**${s.name}** (${s.spec_code ?? "no spec code"}${s.tier ? `, ${s.tier}` : ""}) — ${pct}% of the specification covered.\n${summary}\n\n${TOPIC_HEADER}\n${rows
          .map(topicLine)
          .join("\n")}`,
      );
    },
  );

  server.registerTool(
    "tracker_review_queue",
    {
      title: "What to work on next",
      description: `Build a prioritised review queue for a subject. Use this to open a session.

Three groups are returned:
  1. AGEING — topics marked secure or exam-ready that have not been touched for the ageing threshold. These belong in a retrieval starter; if one fails there, demote it to developing.
  2. LOOSE ENDS — topics that are secure but carry a specific unresolved note. Feed these into starters rather than reteaching the whole topic.
  3. PRIORITY GAPS — topics marked gap, lower tier first, since a foundation gap outranks a higher-tier one.

Args:
  - subject (string): subject slug
  - ageing_weeks (number, optional, default 8): weeks after which a secure topic is considered due for a retrieval check

Read-only.`,
      inputSchema: {
        subject: z.string().min(1).describe("Subject slug"),
        ageing_weeks: z
          .number()
          .int()
          .min(1)
          .max(52)
          .default(8)
          .describe("Weeks before a secure topic is due a check"),
      },
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    },
    async ({ subject, ageing_weeks }) => {
      const s = resolve(store, subject);
      if (typeof s === "string") return text(s);
      const all = store.listTopics(subject);

      const ageing = all
        .filter((t) => t.status === "secure" || t.status === "examready")
        .map((t) => ({ t, w: weeksSince(t.last_touched) }))
        .filter((x) => x.w === null || x.w >= ageing_weeks)
        .sort((a, b) => (b.w ?? 999) - (a.w ?? 999));

      const loose = all.filter((t) => t.watch && t.status !== "gap");
      const gaps = all
        .filter((t) => t.status === "gap")
        .sort((a, b) => a.tier.localeCompare(b.tier));

      const parts: string[] = [`**Review queue — ${s.name}**`];

      parts.push(
        ageing.length
          ? `\n### Ageing (${ageing_weeks}+ weeks, put in a starter)\n${ageing
              .map(
                ({ t, w }) =>
                  `- **${t.ref}** ${t.name} — ${
                    w === null ? "no date recorded" : `${w} weeks since last touched`
                  }`,
              )
              .join("\n")}`
          : "\n### Ageing\nNothing overdue.",
      );

      parts.push(
        loose.length
          ? `\n### Loose ends on secure topics\n${loose
              .map((t) => `- **${t.ref}** ${t.name} — ${t.watch}`)
              .join("\n")}`
          : "\n### Loose ends\nNone recorded.",
      );

      parts.push(
        gaps.length
          ? `\n### Priority gaps (lower tier first)\n${gaps
              .map((t) => `- **${t.ref}** ${t.name} (${t.strand}, tier ${t.tier})`)
              .join("\n")}`
          : "\n### Priority gaps\nNone — no topic is marked as a gap.",
      );

      const sessions = store.listSessions(subject, 1);
      parts.push(
        sessions.length
          ? `\nLast session logged: ${sessions[0].date} — ${sessions[0].summary}${
              sessions[0].next_steps ? `\nPlanned next: ${sessions[0].next_steps}` : ""
            }`
          : "\nNo sessions logged yet.",
      );

      return text(parts.join("\n"));
    },
  );

  server.registerTool(
    "tracker_list_assessments",
    {
      title: "List papers and checks",
      description: `List logged assessments for a subject, newest first, with grade conversions.

Full papers are scaled to the subject's boundary maximum and converted using its stored grade boundaries. Topic checks return a percentage only and are never grade-converted — a check on a handful of topics cannot stand in for a whole paper.

Args:
  - subject (string): subject slug
  - limit (number, optional, default 20)

Also reports the most recent blank count, where recorded. Read-only.`,
      inputSchema: {
        subject: z.string().min(1).describe("Subject slug"),
        limit: z.number().int().min(1).max(100).default(20),
      },
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    },
    async ({ subject, limit }) => {
      const s = resolve(store, subject);
      if (typeof s === "string") return text(s);
      const rows = store.listAssessments(subject).slice(0, limit);
      if (!rows.length) return text("No assessments logged for this subject yet.");
      const body = rows
        .map((a) => {
          const outcome =
            a.kind === "check"
              ? `${Math.round((a.score / a.max) * 100)}% (no grade)`
              : `≈ grade ${gradeFor(s, a.score, a.max, a.tier)}`;
          return `| ${a.date} | ${a.name} | ${a.kind} | ${a.tier} | ${a.score}/${a.max} | ${outcome} | ${
            a.blanks ?? "—"
          } | ${a.note ?? ""} |`;
        })
        .join("\n");
      return text(
        `| Date | Assessment | Kind | Tier | Score | Outcome | Blanks | Note |\n|---|---|---|---|---|---|---|---|\n${body}`,
      );
    },
  );

  server.registerTool(
    "tracker_export_markdown",
    {
      title: "Export topic state as markdown",
      description: `Render the whole current state of a subject as a markdown document, in the same shape as a hand-maintained topic-state file.

Use this when someone wants a document to file, print, or paste into project knowledge. The database is the source of truth; this export is generated from it, so never edit the export and expect the tracker to follow.

Args:
  - subject (string): subject slug

Read-only.`,
      inputSchema: { subject: z.string().min(1).describe("Subject slug") },
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    },
    async ({ subject }) => {
      const s = resolve(store, subject);
      if (typeof s === "string") return text(s);
      const { topics, pct } = progress(store, subject);
      const today = new Date().toISOString().slice(0, 10);
      const parts = [
        `# ${s.name} — topic state`,
        `*Generated ${today} from the tracker database. ${pct}% of the specification covered.*`,
        "",
      ];
      for (const [key, label] of Object.entries(s.strands)) {
        const rows = topics.filter((t) => t.strand === key);
        if (!rows.length) continue;
        parts.push(`## ${label}`, "", TOPIC_HEADER, rows.map(topicLine).join("\n"), "");
      }
      const assessments = store.listAssessments(subject);
      if (assessments.length) {
        parts.push(
          "## Assessment log",
          "",
          "| Date | Assessment | Score | Outcome |",
          "|---|---|---|---|",
          assessments
            .map(
              (a) =>
                `| ${a.date} | ${a.name} | ${a.score}/${a.max} | ${
                  a.kind === "check"
                    ? `${Math.round((a.score / a.max) * 100)}% (check, not grade-converted)`
                    : `≈ grade ${gradeFor(s, a.score, a.max, a.tier)}`
                } |`,
            )
            .join("\n"),
          "",
        );
      }
      return text(parts.join("\n"));
    },
  );

  // ---- write ----------------------------------------------------------

  server.registerTool(
    "tracker_update_topic",
    {
      title: "Update one topic's status",
      description: `Change a single topic's status, loose-end note, or last-touched date, recording the evidence for the change.

Evidence is mandatory and should say what was done, how it scored, and when — for example "harder independent retest 4/4, 13 Aug". A status change without evidence is not auditable, so the tool refuses one.

Args:
  - subject (string): subject slug
  - ref (string): topic reference, e.g. "A17"
  - status (optional): new status. Omit to record evidence or a note without changing status.
  - evidence (string): what justifies this update (10-500 characters)
  - watch (string or null, optional): a specific loose end to carry forward, or null to clear it
  - last_touched (optional): YYYY-MM-DD, defaults to today

Returns the previous and new status. To update several topics at once, use tracker_log_session instead.`,
      inputSchema: {
        subject: z.string().min(1),
        ref: z.string().min(1).describe("Topic reference, e.g. 'A17'"),
        status: StatusEnum.optional(),
        evidence: z
          .string()
          .min(10, "Evidence must say what was done and how it went")
          .max(500),
        watch: z.string().max(500).nullable().optional(),
        last_touched: ISO_DATE.optional(),
      },
      annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: false,
        openWorldHint: false,
      },
    },
    async ({ subject, ref, status, evidence, watch, last_touched }) => {
      const s = resolve(store, subject);
      if (typeof s === "string") return text(s);
      const result = store.updateTopicStatus({
        subject_slug: subject,
        ref,
        status: status as Status | undefined,
        evidence,
        watch,
        last_touched,
      });
      if (!result) {
        return text(
          `No topic "${ref}" in ${subject}. Call tracker_get_state to see valid references.`,
        );
      }
      const moved =
        result.previous === result.current
          ? `unchanged at ${STATUS_LABEL[result.current]}`
          : `${STATUS_LABEL[result.previous]} → ${STATUS_LABEL[result.current]}`;
      return text(`${ref} updated: ${moved}. Evidence recorded.`);
    },
  );

  server.registerTool(
    "tracker_log_session",
    {
      title: "Log a session and its topic updates",
      description: `Record a teaching session and apply any status changes it produced, in one call. This is the normal way to close a session.

Args:
  - subject (string): subject slug
  - date (optional): YYYY-MM-DD, defaults to today
  - summary (string): what was covered and how it went
  - next_steps (string, optional): what the next session should open with
  - updates (array, optional): topic updates, each { ref, status?, evidence, watch? }

Each update carries its own evidence. Topics that do not exist are reported back rather than silently skipped, so a typo in a reference is visible.

Returns a confirmation listing each topic that moved.`,
      inputSchema: {
        subject: z.string().min(1),
        date: ISO_DATE.optional(),
        summary: z.string().min(10).max(2000),
        next_steps: z.string().max(1000).optional(),
        updates: z
          .array(
            z.object({
              ref: z.string().min(1),
              status: StatusEnum.optional(),
              evidence: z.string().min(10).max(500),
              watch: z.string().max(500).nullable().optional(),
            }),
          )
          .max(50)
          .optional(),
      },
      annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: false,
        openWorldHint: false,
      },
    },
    async ({ subject, date, summary, next_steps, updates }) => {
      const s = resolve(store, subject);
      if (typeof s === "string") return text(s);
      const when = date ?? new Date().toISOString().slice(0, 10);
      const applied: string[] = [];
      const missing: string[] = [];

      for (const u of updates ?? []) {
        const r = store.updateTopicStatus({
          subject_slug: subject,
          ref: u.ref,
          status: u.status as Status | undefined,
          evidence: u.evidence,
          watch: u.watch,
          last_touched: when,
        });
        if (!r) missing.push(u.ref);
        else
          applied.push(
            r.previous === r.current
              ? `${u.ref} (still ${STATUS_LABEL[r.current]})`
              : `${u.ref}: ${STATUS_LABEL[r.previous]} → ${STATUS_LABEL[r.current]}`,
          );
      }

      store.addSession({
        subject_slug: subject,
        date: when,
        summary,
        topics_touched: (updates ?? []).map((u) => u.ref).join(", ") || null,
        next_steps: next_steps ?? null,
      });

      const lines = [`Session logged for ${s.name} on ${when}.`];
      if (applied.length) lines.push(`Updated: ${applied.join("; ")}.`);
      if (missing.length)
        lines.push(
          `Not found, so not updated: ${missing.join(", ")}. Check the references with tracker_get_state.`,
        );
      return text(lines.join("\n"));
    },
  );

  server.registerTool(
    "tracker_log_assessment",
    {
      title: "Log a paper or topic check",
      description: `Record an assessment result.

Args:
  - subject (string): subject slug
  - name (string): what was sat, e.g. "8300/1H Jun-23"
  - kind ('paper' | 'check'): a full past paper, or a topic check. Only papers are grade-converted.
  - score (number), max (number)
  - tier (string, optional, default 'F')
  - date (optional): YYYY-MM-DD, defaults to today
  - blanks (number, optional): questions left blank — worth logging every time, since a blank scores nothing while working earns method marks
  - note (string, optional)

Returns the stored entry with its grade conversion where applicable.`,
      inputSchema: {
        subject: z.string().min(1),
        name: z.string().min(1).max(200),
        kind: z.enum(["paper", "check"]).default("paper"),
        score: z.number().min(0),
        max: z.number().min(1),
        tier: z.string().max(4).default("F"),
        date: ISO_DATE.optional(),
        blanks: z.number().int().min(0).optional(),
        note: z.string().max(1000).optional(),
      },
      annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: false,
        openWorldHint: false,
      },
    },
    async ({ subject, name, kind, score, max, tier, date, blanks, note }) => {
      const s = resolve(store, subject);
      if (typeof s === "string") return text(s);
      if (score > max) {
        return text(`Score ${score} exceeds the maximum ${max}. Check the figures.`);
      }
      const when = date ?? new Date().toISOString().slice(0, 10);
      store.addAssessment({
        subject_slug: subject,
        date: when,
        name,
        kind,
        tier,
        score,
        max,
        blanks: blanks ?? null,
        note: note ?? null,
      });
      const outcome =
        kind === "check"
          ? `${Math.round((score / max) * 100)}% — a topic check, so not grade-converted`
          : `≈ grade ${gradeFor(s, score, max, tier)} on tier ${tier}`;
      return text(
        `Logged ${name} (${when}): ${score}/${max}, ${outcome}.${
          blanks === undefined
            ? " No blank count recorded — worth counting next time."
            : ` ${blanks} blank${blanks === 1 ? "" : "s"}.`
        }`,
      );
    },
  );

  server.registerTool(
    "tracker_create_subject",
    {
      title: "Create or update a subject",
      description: `Create a new subject with its strands, grade boundaries and topic list, or update an existing one.

Re-running this for an existing subject updates the subject metadata and adds any new topics, but never resets the status of a topic that already exists — progress is not lost by re-seeding.

Args:
  - slug (string): url-safe key, e.g. "english-language"
  - name (string): display name
  - spec_code, tier, exam_date, notes (optional)
  - strands (object): strand key to display name, e.g. { "N": "Number", "A": "Algebra" }
  - boundary_max (number, optional, default 240): total marks the boundaries are expressed against
  - boundaries (object, optional): tier to [[grade, mark], ...], e.g. { "H": [[7,164],[6,130]] }
  - topics (array): each { ref, name, strand, tier?, status?, watch? }

Returns a summary of what was created or added.`,
      inputSchema: {
        slug: z
          .string()
          .regex(/^[a-z0-9-]+$/, "Lowercase letters, numbers and hyphens only")
          .max(60),
        name: z.string().min(1).max(120),
        spec_code: z.string().max(60).optional(),
        tier: z.string().max(30).optional(),
        exam_date: ISO_DATE.optional(),
        notes: z.string().max(2000).optional(),
        strands: z.record(z.string(), z.string()),
        boundary_max: z.number().int().min(1).default(240),
        boundaries: z
          .record(z.string(), z.array(z.tuple([z.number(), z.number()])))
          .optional(),
        topics: z
          .array(
            z.object({
              ref: z.string().min(1),
              name: z.string().min(1),
              strand: z.string().min(1),
              tier: z.string().max(4).default("F"),
              status: StatusEnum.default("notstarted"),
              watch: z.string().max(500).optional(),
            }),
          )
          .min(1)
          .max(400),
      },
      annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    },
    async (args) => {
      const existing = store.getSubject(args.slug);
      const before = existing ? store.listTopics(args.slug).length : 0;
      store.upsertSubject({
        slug: args.slug,
        name: args.name,
        spec_code: args.spec_code,
        tier: args.tier,
        exam_date: args.exam_date,
        strands: args.strands,
        boundaries: (args.boundaries ?? {}) as Record<string, [number, number][]>,
        boundary_max: args.boundary_max,
        notes: args.notes,
      });
      args.topics.forEach((t, i) =>
        store.upsertTopic({
          subject_slug: args.slug,
          ref: t.ref,
          name: t.name,
          strand: t.strand,
          tier: t.tier,
          status: t.status as Status,
          watch: t.watch ?? null,
          sort_order: i,
        }),
      );
      const after = store.listTopics(args.slug).length;
      return text(
        existing
          ? `Updated ${args.name}. Topics: ${before} → ${after} (${after - before} added; existing statuses untouched).`
          : `Created ${args.name} with ${after} topics. Dashboard: /s/${args.slug}`,
      );
    },
  );

  return server;
}
