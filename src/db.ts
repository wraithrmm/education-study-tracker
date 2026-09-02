import Database from "better-sqlite3";
import { mkdirSync } from "node:fs";
import { dirname } from "node:path";

export type Status =
  | "notstarted"
  | "gap"
  | "developing"
  | "secure"
  | "examready";

export const STATUS_ORDER: Status[] = [
  "notstarted",
  "gap",
  "developing",
  "secure",
  "examready",
];

export const STATUS_LABEL: Record<Status, string> = {
  notstarted: "Not started",
  gap: "Gap",
  developing: "Developing",
  secure: "Secure",
  examready: "Exam-ready",
};

/** Points used for the headline "spec conquered" percentage. */
export const STATUS_POINTS: Record<Status, number> = {
  notstarted: 0,
  gap: 0,
  developing: 1,
  secure: 2,
  examready: 3,
};

export interface Subject {
  slug: string;
  name: string;
  spec_code: string | null;
  tier: string | null;
  exam_date: string | null;
  strands: Record<string, string>;
  boundaries: Record<string, [number, number][]>;
  boundary_max: number;
  notes: string | null;
}

export interface Topic {
  id: number;
  subject_slug: string;
  ref: string;
  name: string;
  strand: string;
  tier: string;
  status: Status;
  watch: string | null;
  evidence: string | null;
  last_touched: string | null;
  sort_order: number;
  updated_at: string;
}

export interface Assessment {
  id: number;
  subject_slug: string;
  date: string;
  name: string;
  kind: "paper" | "check";
  tier: string;
  score: number;
  max: number;
  blanks: number | null;
  note: string | null;
}

export interface SessionLog {
  id: number;
  subject_slug: string;
  date: string;
  summary: string;
  topics_touched: string | null;
  next_steps: string | null;
  created_at: string;
}

export interface TopicChange {
  id: number;
  subject_slug: string;
  ref: string;
  from_status: string | null;
  to_status: string;
  evidence: string;
  changed_at: string;
}

const SCHEMA = `
CREATE TABLE IF NOT EXISTS subjects (
  slug          TEXT PRIMARY KEY,
  name          TEXT NOT NULL,
  spec_code     TEXT,
  tier          TEXT,
  exam_date     TEXT,
  strands       TEXT NOT NULL DEFAULT '{}',
  boundaries    TEXT NOT NULL DEFAULT '{}',
  boundary_max  INTEGER NOT NULL DEFAULT 240,
  notes         TEXT
);

CREATE TABLE IF NOT EXISTS topics (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  ref           TEXT NOT NULL,
  name          TEXT NOT NULL,
  strand        TEXT NOT NULL,
  tier          TEXT NOT NULL DEFAULT 'F',
  status        TEXT NOT NULL DEFAULT 'notstarted',
  watch         TEXT,
  evidence      TEXT,
  last_touched  TEXT,
  sort_order    INTEGER NOT NULL DEFAULT 0,
  updated_at    TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (subject_slug, ref)
);

CREATE TABLE IF NOT EXISTS assessments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  date          TEXT NOT NULL,
  name          TEXT NOT NULL,
  kind          TEXT NOT NULL DEFAULT 'paper',
  tier          TEXT NOT NULL DEFAULT 'F',
  score         REAL NOT NULL,
  max           REAL NOT NULL,
  blanks        INTEGER,
  note          TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug   TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  date           TEXT NOT NULL,
  summary        TEXT NOT NULL,
  topics_touched TEXT,
  next_steps     TEXT,
  created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS topic_changes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL,
  ref           TEXT NOT NULL,
  from_status   TEXT,
  to_status     TEXT NOT NULL,
  evidence      TEXT NOT NULL,
  changed_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- OAuth state for the built-in authorisation server.
CREATE TABLE IF NOT EXISTS oauth_clients (
  client_id      TEXT PRIMARY KEY,
  client_secret  TEXT,
  redirect_uris  TEXT NOT NULL,
  client_name    TEXT,
  created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS oauth_codes (
  code            TEXT PRIMARY KEY,
  client_id       TEXT NOT NULL,
  redirect_uri    TEXT NOT NULL,
  code_challenge  TEXT NOT NULL,
  scope           TEXT,
  expires_at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS oauth_tokens (
  token       TEXT PRIMARY KEY,
  kind        TEXT NOT NULL,
  client_id   TEXT NOT NULL,
  scope       TEXT,
  expires_at  INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_topics_subject ON topics(subject_slug, sort_order);
CREATE INDEX IF NOT EXISTS idx_assessments_subject ON assessments(subject_slug, date);
CREATE INDEX IF NOT EXISTS idx_changes_subject ON topic_changes(subject_slug, changed_at);
`;

export class Store {
  readonly db: Database.Database;

  constructor(path: string) {
    mkdirSync(dirname(path), { recursive: true });
    this.db = new Database(path);
    this.db.pragma("journal_mode = WAL");
    this.db.pragma("foreign_keys = ON");
    this.db.exec(SCHEMA);
  }

  // ---- subjects -------------------------------------------------------

  listSubjects(): Subject[] {
    const rows = this.db.prepare("SELECT * FROM subjects ORDER BY name").all();
    return rows.map((r) => this.hydrateSubject(r as Record<string, unknown>));
  }

  getSubject(slug: string): Subject | null {
    const row = this.db
      .prepare("SELECT * FROM subjects WHERE slug = ?")
      .get(slug);
    return row ? this.hydrateSubject(row as Record<string, unknown>) : null;
  }

  private hydrateSubject(r: Record<string, unknown>): Subject {
    return {
      slug: String(r.slug),
      name: String(r.name),
      spec_code: (r.spec_code as string) ?? null,
      tier: (r.tier as string) ?? null,
      exam_date: (r.exam_date as string) ?? null,
      strands: JSON.parse(String(r.strands || "{}")),
      boundaries: JSON.parse(String(r.boundaries || "{}")),
      boundary_max: Number(r.boundary_max ?? 240),
      notes: (r.notes as string) ?? null,
    };
  }

  upsertSubject(s: {
    slug: string;
    name: string;
    spec_code?: string | null;
    tier?: string | null;
    exam_date?: string | null;
    strands?: Record<string, string>;
    boundaries?: Record<string, [number, number][]>;
    boundary_max?: number;
    notes?: string | null;
  }): Subject {
    this.db
      .prepare(
        `INSERT INTO subjects (slug, name, spec_code, tier, exam_date, strands, boundaries, boundary_max, notes)
         VALUES (@slug, @name, @spec_code, @tier, @exam_date, @strands, @boundaries, @boundary_max, @notes)
         ON CONFLICT(slug) DO UPDATE SET
           name = excluded.name,
           spec_code = COALESCE(excluded.spec_code, subjects.spec_code),
           tier = COALESCE(excluded.tier, subjects.tier),
           exam_date = COALESCE(excluded.exam_date, subjects.exam_date),
           strands = excluded.strands,
           boundaries = excluded.boundaries,
           boundary_max = excluded.boundary_max,
           notes = COALESCE(excluded.notes, subjects.notes)`,
      )
      .run({
        slug: s.slug,
        name: s.name,
        spec_code: s.spec_code ?? null,
        tier: s.tier ?? null,
        exam_date: s.exam_date ?? null,
        strands: JSON.stringify(s.strands ?? {}),
        boundaries: JSON.stringify(s.boundaries ?? {}),
        boundary_max: s.boundary_max ?? 240,
        notes: s.notes ?? null,
      });
    return this.getSubject(s.slug)!;
  }

  // ---- topics ---------------------------------------------------------

  listTopics(slug: string, filter?: { status?: Status[]; strand?: string }): Topic[] {
    let sql = "SELECT * FROM topics WHERE subject_slug = ?";
    const params: unknown[] = [slug];
    if (filter?.strand) {
      sql += " AND strand = ?";
      params.push(filter.strand);
    }
    if (filter?.status?.length) {
      sql += ` AND status IN (${filter.status.map(() => "?").join(",")})`;
      params.push(...filter.status);
    }
    sql += " ORDER BY sort_order, ref";
    return this.db.prepare(sql).all(...params) as Topic[];
  }

  getTopic(slug: string, ref: string): Topic | null {
    return (this.db
      .prepare("SELECT * FROM topics WHERE subject_slug = ? AND ref = ?")
      .get(slug, ref) as Topic) ?? null;
  }

  upsertTopic(t: {
    subject_slug: string;
    ref: string;
    name: string;
    strand: string;
    tier?: string;
    status?: Status;
    watch?: string | null;
    evidence?: string | null;
    last_touched?: string | null;
    sort_order?: number;
  }): void {
    this.db
      .prepare(
        `INSERT INTO topics (subject_slug, ref, name, strand, tier, status, watch, evidence, last_touched, sort_order)
         VALUES (@subject_slug, @ref, @name, @strand, @tier, @status, @watch, @evidence, @last_touched, @sort_order)
         ON CONFLICT(subject_slug, ref) DO UPDATE SET
           name = excluded.name,
           strand = excluded.strand,
           tier = excluded.tier,
           sort_order = excluded.sort_order,
           updated_at = datetime('now')`,
      )
      .run({
        subject_slug: t.subject_slug,
        ref: t.ref,
        name: t.name,
        strand: t.strand,
        tier: t.tier ?? "F",
        status: t.status ?? "notstarted",
        watch: t.watch ?? null,
        evidence: t.evidence ?? null,
        last_touched: t.last_touched ?? null,
        sort_order: t.sort_order ?? 0,
      });
  }

  /**
   * Update a topic's status and record the change with its evidence.
   * Returns the previous status, or null if the topic does not exist.
   */
  updateTopicStatus(args: {
    subject_slug: string;
    ref: string;
    status?: Status;
    evidence: string;
    watch?: string | null;
    last_touched?: string | null;
  }): { previous: Status; current: Status } | null {
    const existing = this.getTopic(args.subject_slug, args.ref);
    if (!existing) return null;
    const next = args.status ?? existing.status;
    const touched = args.last_touched ?? new Date().toISOString().slice(0, 10);

    const tx = this.db.transaction(() => {
      this.db
        .prepare(
          `UPDATE topics SET status = ?, evidence = ?, watch = ?, last_touched = ?, updated_at = datetime('now')
           WHERE subject_slug = ? AND ref = ?`,
        )
        .run(
          next,
          args.evidence,
          args.watch === undefined ? existing.watch : args.watch,
          touched,
          args.subject_slug,
          args.ref,
        );
      this.db
        .prepare(
          `INSERT INTO topic_changes (subject_slug, ref, from_status, to_status, evidence)
           VALUES (?, ?, ?, ?, ?)`,
        )
        .run(args.subject_slug, args.ref, existing.status, next, args.evidence);
    });
    tx();
    return { previous: existing.status, current: next };
  }

  listChanges(slug: string, limit = 50): TopicChange[] {
    return this.db
      .prepare(
        "SELECT * FROM topic_changes WHERE subject_slug = ? ORDER BY changed_at DESC, id DESC LIMIT ?",
      )
      .all(slug, limit) as TopicChange[];
  }

  // ---- assessments and sessions ---------------------------------------

  listAssessments(slug: string): Assessment[] {
    return this.db
      .prepare(
        "SELECT * FROM assessments WHERE subject_slug = ? ORDER BY date DESC, id DESC",
      )
      .all(slug) as Assessment[];
  }

  addAssessment(a: Omit<Assessment, "id">): number {
    const info = this.db
      .prepare(
        `INSERT INTO assessments (subject_slug, date, name, kind, tier, score, max, blanks, note)
         VALUES (@subject_slug, @date, @name, @kind, @tier, @score, @max, @blanks, @note)`,
      )
      .run(a as unknown as Record<string, unknown>);
    return Number(info.lastInsertRowid);
  }

  listSessions(slug: string, limit = 20): SessionLog[] {
    return this.db
      .prepare(
        "SELECT * FROM sessions WHERE subject_slug = ? ORDER BY date DESC, id DESC LIMIT ?",
      )
      .all(slug, limit) as SessionLog[];
  }

  addSession(s: {
    subject_slug: string;
    date: string;
    summary: string;
    topics_touched?: string | null;
    next_steps?: string | null;
  }): number {
    const info = this.db
      .prepare(
        `INSERT INTO sessions (subject_slug, date, summary, topics_touched, next_steps)
         VALUES (@subject_slug, @date, @summary, @topics_touched, @next_steps)`,
      )
      .run({
        subject_slug: s.subject_slug,
        date: s.date,
        summary: s.summary,
        topics_touched: s.topics_touched ?? null,
        next_steps: s.next_steps ?? null,
      });
    return Number(info.lastInsertRowid);
  }
}

/** Convert a raw score to a grade using the subject's stored boundaries. */
export function gradeFor(
  subject: Subject,
  score: number,
  max: number,
  tier: string,
): string {
  const table = subject.boundaries[tier];
  if (!table?.length || !max) return "—";
  const scaled = (score / max) * subject.boundary_max;
  const sorted = [...table].sort((a, b) => b[1] - a[1]);
  for (let i = 0; i < sorted.length; i++) {
    const [grade, boundary] = sorted[i];
    if (scaled >= boundary) return i === 0 ? `${grade}+` : String(grade);
  }
  const lowest = sorted[sorted.length - 1][0];
  return `below ${lowest}`;
}

/** Whole weeks since an ISO date, or null if never touched. */
export function weeksSince(iso: string | null): number | null {
  if (!iso) return null;
  const then = new Date(`${iso}T12:00:00Z`).getTime();
  if (Number.isNaN(then)) return null;
  return Math.floor((Date.now() - then) / (7 * 86_400_000));
}
