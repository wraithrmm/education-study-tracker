import {
  gradeFor,
  weeksSince,
  STATUS_LABEL,
  STATUS_POINTS,
  type Status,
  type Store,
  type Subject,
} from "./db.js";

/** Matches the exercise-book look of the original tracker artifact. */
const COLOUR: Record<Status, string> = {
  gap: "#ef4444",
  notstarted: "#d6d3d1",
  developing: "#fbbf24",
  secure: "#10b981",
  examready: "#0ea5e9",
};

const esc = (s: unknown) =>
  String(s ?? "").replace(/[&<>"']/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]!,
  );

const CSS = `
:root{--ink:#1c1917;--muted:#78716c;--line:#d6d3d1;--card:#fff}
*{box-sizing:border-box}
body{margin:0;color:var(--ink);background-color:#fcfcf9;
  background-image:linear-gradient(rgba(96,140,200,.12) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(96,140,200,.12) 1px,transparent 1px);
  background-size:24px 24px;
  font-family:Georgia,"Times New Roman",serif;line-height:1.5}
.wrap{max-width:56rem;margin:0 auto;padding:2rem 1rem 4rem}
.mono{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace}
header{display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;justify-content:space-between;
  border-bottom:2px solid #292524;padding-bottom:1rem}
h1{font-size:1.9rem;margin:.2rem 0}
.kicker{font-size:.7rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin:0}
.countdown{display:inline-block;border:2px solid #dc2626;border-radius:999px;padding:.5rem 1rem;
  transform:rotate(2deg);color:#b91c1c}
.countdown b{font-size:1.5rem}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem;margin-top:1.5rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem}
.card p.label{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:0}
.card p.big{font-size:1.9rem;margin:.25rem 0 0;font-weight:700}
.track{height:8px;background:#e7e5e4;border-radius:999px;overflow:hidden;margin-top:.5rem}
.track>div{height:100%;background:#10b981}
h2{font-size:1.15rem;margin:2rem 0 .5rem}
.strand{display:flex;align-items:center;gap:.75rem;margin:.35rem 0}
.strand .nm{width:11rem;flex-shrink:0;font-size:.85rem}
.bar{flex:1;height:20px;background:#fff;border:1px solid var(--line);border-radius:4px;
  overflow:hidden;display:flex}
.bar span{height:100%}
.count{width:3rem;text-align:right;font-size:.75rem;color:var(--muted)}
.chips{display:flex;flex-wrap:wrap;gap:.35rem;margin:.4rem 0 0}
.chip{display:inline-flex;align-items:center;gap:.4rem;font-size:.75rem;border:1px solid var(--line);
  border-radius:6px;padding:.2rem .45rem;background:#fff}
.dot{width:8px;height:8px;border-radius:999px;flex-shrink:0}
.legend{display:flex;flex-wrap:wrap;gap:.75rem;font-size:.75rem;color:var(--muted);margin-top:.6rem}
.item{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.7rem 1rem;
  margin-top:.5rem;display:flex;gap:.75rem;align-items:flex-start}
.item .grow{flex:1;min-width:0}
.item .num{text-align:right;flex-shrink:0}
small{color:var(--muted)}
.flag{background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:.7rem 1rem;
  margin-top:.75rem;font-size:.9rem;color:#78350f}
footer{margin-top:2.5rem;padding-top:1rem;border-top:1px solid var(--line);
  font-size:.75rem;color:var(--muted)}
a{color:#1c1917}
`;

function shell(title: string, body: string): string {
  return `<!doctype html><html lang="en-GB"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)}</title><style>${CSS}</style></head>
<body><div class="wrap">${body}</div></body></html>`;
}

export function renderIndex(store: Store): string {
  const subjects = store.listSubjects();
  const body = subjects.length
    ? subjects
        .map((s) => {
          const topics = store.listTopics(s.slug);
          const pts = topics.reduce((n, t) => n + STATUS_POINTS[t.status as Status], 0);
          const pct = topics.length ? Math.round((pts / (topics.length * 3)) * 100) : 0;
          return `<a class="item" href="/s/${esc(s.slug)}" style="text-decoration:none">
            <div class="grow"><strong>${esc(s.name)}</strong>
              <div><small>${esc(s.spec_code ?? "")} ${esc(s.tier ?? "")} · ${topics.length} topics</small></div>
            </div>
            <div class="num"><strong class="mono">${pct}%</strong><div><small>covered</small></div></div>
          </a>`;
        })
        .join("")
    : `<p><small>No subjects yet. Ask Claude to create one.</small></p>`;

  return shell(
    "Study trackers",
    `<header><div><p class="kicker">Study tracker</p><h1>Subjects</h1></div></header>${body}`,
  );
}

export function renderSubject(store: Store, subject: Subject): string {
  const topics = store.listTopics(subject.slug);
  const pts = topics.reduce((n, t) => n + STATUS_POINTS[t.status as Status], 0);
  const pct = topics.length ? Math.round((pts / (topics.length * 3)) * 100) : 0;
  const assessments = store.listAssessments(subject.slug);
  const lastPaper = assessments.find((a) => a.kind === "paper");
  const lower = topics.filter((t) => t.tier !== "H");
  const lowerSecure = lower.filter(
    (t) => t.status === "secure" || t.status === "examready",
  ).length;

  const days = subject.exam_date
    ? Math.max(
        0,
        Math.ceil(
          (new Date(`${subject.exam_date}T09:00:00Z`).getTime() - Date.now()) / 86_400_000,
        ),
      )
    : null;

  const strandRows = Object.entries(subject.strands)
    .map(([key, label]) => {
      const rows = topics.filter((t) => t.strand === key);
      if (!rows.length) return "";
      const secure = rows.filter(
        (t) => t.status === "secure" || t.status === "examready",
      ).length;
      const segs = rows
        .map(
          (t) =>
            `<span style="width:${100 / rows.length}%;background:${COLOUR[t.status as Status]}" title="${esc(
              `${t.ref} ${t.name}`,
            )}"></span>`,
        )
        .join("");
      return `<div class="strand"><span class="nm">${esc(label)}</span>
        <div class="bar">${segs}</div>
        <span class="count mono">${secure}/${rows.length}</span></div>`;
    })
    .join("");

  const chipGroups = Object.entries(subject.strands)
    .map(([key, label]) => {
      const rows = topics.filter((t) => t.strand === key);
      if (!rows.length) return "";
      const chips = rows
        .map(
          (t) =>
            `<span class="chip" title="${esc(STATUS_LABEL[t.status as Status])}">
              <span class="dot" style="background:${COLOUR[t.status as Status]}"></span>
              <b class="mono">${esc(t.ref)}</b> ${esc(t.name)}
              ${t.watch ? '<b style="color:#d97706">!</b>' : ""}
              ${t.tier === "H" ? '<b style="color:#a8a29e">H</b>' : ""}
            </span>`,
        )
        .join("");
      return `<h3 class="kicker mono" style="margin:1rem 0 0">${esc(label)}</h3><div class="chips">${chips}</div>`;
    })
    .join("");

  const loose = topics.filter((t) => t.watch);
  const looseHtml = loose.length
    ? `<h2>Loose ends</h2>
       <p><small>Secure means it held up independently, not that it is finished. Feed these into starters rather than reteaching.</small></p>
       ${loose
         .map(
           (t) => `<div class="item">
             <span class="dot" style="margin-top:.4rem;background:${COLOUR[t.status as Status]}"></span>
             <div class="grow"><strong class="mono">${esc(t.ref)}</strong> ${esc(t.name)}
             <div><small>${esc(t.watch)}</small></div></div></div>`,
         )
         .join("")}`
    : "";

  const ageing = topics
    .filter((t) => t.status === "secure" || t.status === "examready")
    .map((t) => ({ t, w: weeksSince(t.last_touched) }))
    .filter((x) => x.w !== null && x.w >= 8);
  const ageingHtml = ageing.length
    ? `<div class="flag"><strong>Due a retrieval check:</strong> ${ageing
        .map(({ t, w }) => `${esc(t.ref)} (${w} weeks)`)
        .join(", ")}. If one fails in a starter, demote it to developing.</div>`
    : "";

  const assessHtml = assessments.length
    ? assessments
        .map(
          (a) => `<div class="item"><div class="grow"><strong>${esc(a.name)}</strong>
            <div><small>${esc(a.date)} · tier ${esc(a.tier)}${
              a.blanks !== null ? ` · ${a.blanks} blank${a.blanks === 1 ? "" : "s"}` : ""
            }</small></div>
            ${a.note ? `<div><small>${esc(a.note)}</small></div>` : ""}</div>
            <div class="num"><strong class="mono">${a.score}/${a.max}</strong>
            <div><small>${
              a.kind === "check"
                ? `${Math.round((a.score / a.max) * 100)}% · no grade`
                : `≈ grade ${esc(gradeFor(subject, a.score, a.max, a.tier))}`
            }</small></div></div></div>`,
        )
        .join("")
    : `<p><small>Nothing logged yet.</small></p>`;

  const sessions = store.listSessions(subject.slug, 5);
  const sessionHtml = sessions.length
    ? sessions
        .map(
          (s) => `<div class="item"><div class="grow"><strong>${esc(s.date)}</strong>
            <div><small>${esc(s.summary)}</small></div>
            ${s.next_steps ? `<div><small><em>Next: ${esc(s.next_steps)}</em></small></div>` : ""}
          </div></div>`,
        )
        .join("")
    : `<p><small>No sessions logged yet.</small></p>`;

  const legend = Object.entries(STATUS_LABEL)
    .map(
      ([k, label]) =>
        `<span><span class="dot" style="display:inline-block;background:${
          COLOUR[k as Status]
        }"></span> ${esc(label)}</span>`,
    )
    .join("");

  const body = `
<header>
  <div><p class="kicker">${esc(subject.spec_code ?? "")} ${
    subject.tier ? `· ${esc(subject.tier)}` : ""
  }</p><h1>${esc(subject.name)}</h1></div>
  ${
    days !== null
      ? `<div style="text-align:right"><div class="countdown"><b class="mono">${days}</b> days to the exam</div>
         <div><small>${esc(subject.exam_date)}</small></div></div>`
      : ""
  }
</header>

<div class="stats">
  <div class="card"><p class="label">Spec conquered</p><p class="big mono">${pct}%</p>
    <div class="track"><div style="width:${pct}%"></div></div></div>
  <div class="card"><p class="label">Lower-tier secure</p>
    <p class="big mono">${lowerSecure}<small>/${lower.length}</small></p>
    <p><small>topics secure or better</small></p></div>
  <div class="card"><p class="label">Latest paper</p>
    ${
      lastPaper
        ? `<p class="big mono">${lastPaper.score}<small>/${lastPaper.max}</small></p>
           <p><small>≈ grade ${esc(
             gradeFor(subject, lastPaper.score, lastPaper.max, lastPaper.tier),
           )} · ${esc(lastPaper.date)}</small></p>`
        : `<p><small>No full paper logged.</small></p>`
    }</div>
</div>

${ageingHtml}

<h2>Strands</h2>${strandRows}
<div class="legend">${legend}</div>

<h2>Every topic</h2>${chipGroups}

${looseHtml}

<h2>Papers &amp; checks</h2>${assessHtml}

<h2>Recent sessions</h2>${sessionHtml}

<footer>Generated live from the tracker database at ${esc(
    new Date().toISOString().slice(0, 16).replace("T", " "),
  )} UTC.
  ${subject.notes ? `<br>${esc(subject.notes)}` : ""}</footer>`;

  return shell(`${subject.name} tracker`, body);
}
