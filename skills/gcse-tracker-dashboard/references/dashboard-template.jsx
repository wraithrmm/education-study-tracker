// SUPERSEDED — kept as the visual ancestor of the hosted tracker, not as a template.
//
// Per-subject dashboards are now served by the Education Tracker service at
// https://education.rmmann.co.uk (repo: wraithrmm/education-study-tracker), which
// renders from a shared database on every request. Add a subject with the
// tracker_create_subject tool; change the dashboard by editing php/lib/dashboard.php.
//
// Do not instantiate this file for a new subject: window.storage data is
// per-account, unreadable by chat Claude, and makes every artifact a separate
// copy of the truth. See ../SKILL.md.

import { useState, useEffect, useCallback } from "react";

// ---------- seed data: mirrors 03-TOPIC-STATE.md v1.0 (3 Aug 2026) ----------
const SEED_TOPICS = [
  // Number
  { ref: "N1-3", name: "Place value & four operations", strand: "N", tier: "F", status: "secure" },
  { ref: "N4", name: "HCF / LCM / primes", strand: "N", tier: "F", status: "gap" },
  { ref: "N5", name: "Counting & listing", strand: "N", tier: "F/H", status: "notstarted" },
  { ref: "N6-7", name: "Powers, roots & index laws", strand: "N", tier: "F/H", status: "developing" },
  { ref: "N8", name: "Surds", strand: "N", tier: "H", status: "notstarted" },
  { ref: "N9", name: "Standard form", strand: "N", tier: "F", status: "notstarted" },
  { ref: "N10", name: "Fractions ↔ decimals", strand: "N", tier: "F/H", status: "gap" },
  { ref: "N11-12", name: "Fractions & percentages of amounts", strand: "N", tier: "F", status: "secure" },
  { ref: "N13", name: "Units & compound measures", strand: "N", tier: "F", status: "secure" },
  { ref: "N14", name: "Estimation & rounding", strand: "N", tier: "F", status: "developing" },
  { ref: "N15", name: "Error intervals & bounds", strand: "N", tier: "F/H", status: "gap" },
  { ref: "N16", name: "Number in context (money etc.)", strand: "N", tier: "F", status: "secure" },
  // Algebra
  { ref: "A1-3", name: "Notation & substitution", strand: "A", tier: "F", status: "secure" },
  { ref: "A4", name: "Simplify, expand, factorise", strand: "A", tier: "F/H", status: "gap" },
  { ref: "A5", name: "Rearranging formulae", strand: "A", tier: "F/H", status: "gap" },
  { ref: "A6", name: "Identities & proof", strand: "A", tier: "F/H", status: "notstarted" },
  { ref: "A7", name: "Functions (composite/inverse)", strand: "A", tier: "H", status: "notstarted" },
  { ref: "A8", name: "Coordinates", strand: "A", tier: "F", status: "gap" },
  { ref: "A9-10", name: "Linear graphs & gradient", strand: "A", tier: "F/H", status: "gap" },
  { ref: "A11", name: "Quadratic graphs & turning points", strand: "A", tier: "F/H", status: "gap" },
  { ref: "A12-14", name: "Cubic, reciprocal & other graphs", strand: "A", tier: "F/H", status: "notstarted" },
  { ref: "A15-16", name: "Circle equations & areas under curves", strand: "A", tier: "H", status: "notstarted" },
  { ref: "A17", name: "Solving linear equations", strand: "A", tier: "F", status: "gap" },
  { ref: "A18", name: "Solving quadratics", strand: "A", tier: "F/H", status: "notstarted" },
  { ref: "A19", name: "Simultaneous equations", strand: "A", tier: "F/H", status: "notstarted" },
  { ref: "A20", name: "Iteration", strand: "A", tier: "H", status: "notstarted" },
  { ref: "A21", name: "Forming equations from context", strand: "A", tier: "F", status: "gap" },
  { ref: "A22", name: "Inequalities", strand: "A", tier: "F/H", status: "developing" },
  { ref: "A23-25", name: "Sequences & nth term", strand: "A", tier: "F/H", status: "gap" },
  // Ratio
  { ref: "R1-3", name: "Scale & unit conversion", strand: "R", tier: "F", status: "secure" },
  { ref: "R4-6", name: "Ratio notation & direction", strand: "R", tier: "F", status: "gap" },
  { ref: "R7-8", name: "Ratio ↔ fractions ↔ functions", strand: "R", tier: "F", status: "gap" },
  { ref: "R9", name: "Percentage problems", strand: "R", tier: "F", status: "secure" },
  { ref: "R10", name: "Direct & inverse proportion", strand: "R", tier: "F/H", status: "developing" },
  { ref: "R11", name: "Speed, density, pressure", strand: "R", tier: "F", status: "secure" },
  { ref: "R12-15", name: "Growth, decay & interest", strand: "R", tier: "F/H", status: "notstarted" },
  { ref: "R16", name: "Rates of change (gradients)", strand: "R", tier: "H", status: "notstarted" },
  // Geometry
  { ref: "G1", name: "Symmetry & conventions", strand: "G", tier: "F", status: "gap" },
  { ref: "G2", name: "Constructions & loci", strand: "G", tier: "F", status: "notstarted" },
  { ref: "G3", name: "Angles & parallel lines", strand: "G", tier: "F", status: "gap" },
  { ref: "G4-6", name: "Triangles, quadrilaterals, congruence", strand: "G", tier: "F", status: "developing" },
  { ref: "G7-8", name: "Transformations", strand: "G", tier: "F/H", status: "notstarted" },
  { ref: "G9-12", name: "Circles: area, circumference, parts", strand: "G", tier: "F", status: "developing" },
  { ref: "G10H", name: "Circle theorems", strand: "G", tier: "H", status: "notstarted" },
  { ref: "G13", name: "Plans & elevations", strand: "G", tier: "F", status: "notstarted" },
  { ref: "G14-16", name: "Perimeter & area", strand: "G", tier: "F", status: "developing" },
  { ref: "G17-18", name: "Composite shapes, volume, surface area", strand: "G", tier: "F/H", status: "gap" },
  { ref: "G19", name: "Similarity & scale factors", strand: "G", tier: "F/H", status: "notstarted" },
  { ref: "G20", name: "Pythagoras & trigonometry", strand: "G", tier: "F/H", status: "gap" },
  { ref: "G21", name: "Exact trig values", strand: "G", tier: "F", status: "notstarted" },
  { ref: "G22-23", name: "Vectors: arithmetic", strand: "G", tier: "F/H", status: "gap" },
  { ref: "G24-25", name: "Vector proof", strand: "G", tier: "H", status: "notstarted" },
  // Probability
  { ref: "P1", name: "Frequency trees", strand: "P", tier: "F", status: "gap" },
  { ref: "P2-3", name: "Expected & relative frequency", strand: "P", tier: "F", status: "gap" },
  { ref: "P4-5", name: "Probabilities sum to 1; sample size", strand: "P", tier: "F", status: "gap" },
  { ref: "P6-7", name: "Trees, sample spaces, Venn", strand: "P", tier: "F", status: "gap" },
  { ref: "P8", name: "Combined events: add vs multiply", strand: "P", tier: "F", status: "gap" },
  { ref: "P9", name: "Conditional probability", strand: "P", tier: "H", status: "notstarted" },
  // Statistics
  { ref: "S1", name: "Sampling", strand: "S", tier: "F/H", status: "notstarted" },
  { ref: "S2", name: "Charts & tables", strand: "S", tier: "F", status: "gap" },
  { ref: "S3", name: "Histograms, CF, box plots", strand: "S", tier: "H", status: "notstarted" },
  { ref: "S4", name: "Averages & frequency tables", strand: "S", tier: "F", status: "gap" },
  { ref: "S5", name: "Comparing distributions", strand: "S", tier: "F", status: "notstarted" },
  { ref: "S6", name: "Scatter graphs & correlation", strand: "S", tier: "F", status: "notstarted" },
];

const SEED_ASSESSMENTS = [
  { id: 1, date: "2026-07-01", name: "8300/2F Jun-22", tier: "F", score: 49, max: 80, blanks: 8 },
  { id: 2, date: "2026-07-15", name: "8300/3F Jun-22", tier: "F", score: 41, max: 80, blanks: 12 },
  { id: 3, date: "2026-08-01", name: "8300/1F Jun-22", tier: "F", score: 58, max: 80, blanks: null },
];

const STATUS = {
  gap: { label: "Gap", dot: "bg-red-500", chip: "bg-red-50 border-red-300 text-red-900", bar: "bg-red-400" },
  notstarted: { label: "Not started", dot: "bg-stone-300", chip: "bg-stone-100 border-stone-300 text-stone-600", bar: "bg-stone-300" },
  developing: { label: "Developing", dot: "bg-amber-400", chip: "bg-amber-50 border-amber-300 text-amber-900", bar: "bg-amber-400" },
  secure: { label: "Secure", dot: "bg-emerald-500", chip: "bg-emerald-50 border-emerald-400 text-emerald-900", bar: "bg-emerald-500" },
  examready: { label: "Exam-ready", dot: "bg-sky-500", chip: "bg-sky-50 border-sky-400 text-sky-900", bar: "bg-sky-500" },
};
const CYCLE = ["notstarted", "gap", "developing", "secure", "examready"];
const STRANDS = { N: "Number", A: "Algebra", R: "Ratio & proportion", G: "Geometry & measures", P: "Probability", S: "Statistics" };
const PTS = { notstarted: 0, gap: 0, developing: 1, secure: 2, examready: 3 };

// Higher Jun-2025, Foundation Jun-2024 boundaries (out of 240)
const H_BOUNDS = [[9, 219], [8, 191], [7, 164], [6, 130], [5, 96], [4, 63]];
const F_BOUNDS = [[5, 186], [4, 157], [3, 117], [2, 77], [1, 37]];
const gradeFor = (score, max, tier) => {
  const scaled = (score / max) * 240;
  const table = tier === "H" ? H_BOUNDS : F_BOUNDS;
  for (const [g, b] of table) if (scaled >= b) return g;
  return tier === "H" ? "U" : "U";
};

const PAPER1 = new Date("2027-05-14T09:00:00");
const KEY = "gcse-maths-tracker-v1";
const mono = { fontFamily: "ui-monospace, 'Cascadia Mono', Menlo, monospace" };
const serif = { fontFamily: "Georgia, 'Times New Roman', serif" };

export default function MathsTracker() {
  const [topics, setTopics] = useState(null);
  const [assessments, setAssessments] = useState([]);
  const [editMode, setEditMode] = useState(false);
  const [saveState, setSaveState] = useState("idle"); // idle | saving | saved | error
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ name: "", tier: "F", score: "", max: "80", blanks: "", date: new Date().toISOString().slice(0, 10) });
  const [confirmReset, setConfirmReset] = useState(false);

  // load
  useEffect(() => {
    (async () => {
      try {
        const r = await window.storage.get(KEY);
        if (r && r.value) {
          const d = JSON.parse(r.value);
          setTopics(d.topics || SEED_TOPICS);
          setAssessments(d.assessments || SEED_ASSESSMENTS);
        } else {
          setTopics(SEED_TOPICS);
          setAssessments(SEED_ASSESSMENTS);
        }
      } catch {
        setTopics(SEED_TOPICS);
        setAssessments(SEED_ASSESSMENTS);
      }
    })();
  }, []);

  const persist = useCallback(async (t, a) => {
    setSaveState("saving");
    try {
      const ok = await window.storage.set(KEY, JSON.stringify({ topics: t, assessments: a, updatedAt: new Date().toISOString() }));
      setSaveState(ok ? "saved" : "error");
    } catch {
      setSaveState("error");
    }
    setTimeout(() => setSaveState("idle"), 2000);
  }, []);

  const cycleTopic = (ref) => {
    if (!editMode) return;
    const t = topics.map((x) =>
      x.ref === ref ? { ...x, status: CYCLE[(CYCLE.indexOf(x.status) + 1) % CYCLE.length] } : x
    );
    setTopics(t);
    persist(t, assessments);
  };

  const addAssessment = () => {
    if (!form.name || !form.score) return;
    const a = [
      ...assessments,
      { id: Date.now(), date: form.date, name: form.name, tier: form.tier, score: +form.score, max: +form.max || 80, blanks: form.blanks === "" ? null : +form.blanks },
    ];
    setAssessments(a);
    persist(topics, a);
    setForm({ name: "", tier: form.tier, score: "", max: "80", blanks: "", date: new Date().toISOString().slice(0, 10) });
    setShowForm(false);
  };

  const removeAssessment = (id) => {
    const a = assessments.filter((x) => x.id !== id);
    setAssessments(a);
    persist(topics, a);
  };

  const resetAll = async () => {
    setTopics(SEED_TOPICS);
    setAssessments(SEED_ASSESSMENTS);
    persist(SEED_TOPICS, SEED_ASSESSMENTS);
    setConfirmReset(false);
  };

  if (!topics)
    return (
      <div className="min-h-screen flex items-center justify-center bg-stone-50 text-stone-500" style={serif}>
        Opening your exercise book…
      </div>
    );

  // derived
  const days = Math.max(0, Math.ceil((PAPER1 - new Date()) / 86400000));
  const totalPts = topics.reduce((s, t) => s + PTS[t.status], 0);
  const maxPts = topics.length * 3;
  const pct = Math.round((totalPts / maxPts) * 100);
  const latest = [...assessments].sort((a, b) => (a.date < b.date ? 1 : -1))[0];
  const latestGrade = latest ? gradeFor(latest.score, latest.max, latest.tier) : null;
  const foundationTopics = topics.filter((t) => t.tier !== "H");
  const fSecure = foundationTopics.filter((t) => t.status === "secure" || t.status === "examready").length;

  const gridBg = {
    backgroundColor: "#fcfcf9",
    backgroundImage:
      "linear-gradient(rgba(96,140,200,0.12) 1px, transparent 1px), linear-gradient(90deg, rgba(96,140,200,0.12) 1px, transparent 1px)",
    backgroundSize: "24px 24px",
  };

  return (
    <div className="min-h-screen text-stone-900" style={gridBg}>
      <div className="max-w-3xl mx-auto px-4 py-8">
        {/* header */}
        <header className="flex flex-wrap items-end justify-between gap-4 border-b-2 border-stone-800 pb-4">
          <div>
            <p className="text-xs uppercase tracking-widest text-stone-500" style={mono}>AQA 8300 · Higher · June 2027</p>
            <h1 className="text-3xl font-bold" style={serif}>Maths Tracker</h1>
          </div>
          <div className="text-right">
            <div className="inline-block border-2 border-red-600 rounded-full px-4 py-2 rotate-2">
              <span className="text-2xl font-bold text-red-700" style={mono}>{days}</span>
              <span className="text-xs text-red-700 ml-1">days to Paper 1</span>
            </div>
          </div>
        </header>

        {/* headline stats */}
        <section className="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-6">
          <div className="bg-white border border-stone-300 rounded-lg p-4 shadow-sm">
            <p className="text-xs text-stone-500 uppercase tracking-wide">Spec conquered</p>
            <p className="text-3xl font-bold mt-1" style={mono}>{pct}%</p>
            <div className="h-2 bg-stone-200 rounded-full mt-2 overflow-hidden">
              <div className="h-full bg-emerald-500 rounded-full" style={{ width: pct + "%" }} />
            </div>
          </div>
          <div className="bg-white border border-stone-300 rounded-lg p-4 shadow-sm">
            <p className="text-xs text-stone-500 uppercase tracking-wide">Foundation secure</p>
            <p className="text-3xl font-bold mt-1" style={mono}>
              {fSecure}<span className="text-base text-stone-400">/{foundationTopics.length}</span>
            </p>
            <p className="text-xs text-stone-500 mt-1">topics green or better</p>
          </div>
          <div className="bg-white border border-stone-300 rounded-lg p-4 shadow-sm col-span-2 sm:col-span-1">
            <p className="text-xs text-stone-500 uppercase tracking-wide">Latest paper</p>
            {latest ? (
              <>
                <p className="text-3xl font-bold mt-1" style={mono}>
                  {latest.score}<span className="text-base text-stone-400">/{latest.max}</span>
                </p>
                <p className="text-xs text-stone-600 mt-1">
                  ≈ grade <span className="font-bold">{latestGrade}</span> on tier {latest.tier}
                </p>
              </>
            ) : (
              <p className="text-sm text-stone-500 mt-2">Log your first paper below</p>
            )}
          </div>
        </section>

        {/* strand bars */}
        <section className="mt-8">
          <h2 className="text-lg font-bold" style={serif}>The six strands</h2>
          <div className="mt-3 space-y-2">
            {Object.entries(STRANDS).map(([k, label]) => {
              const rows = topics.filter((t) => t.strand === k);
              return (
                <div key={k} className="flex items-center gap-3">
                  <span className="w-40 shrink-0 text-sm text-stone-700">{label}</span>
                  <div className="flex-1 h-5 bg-white border border-stone-300 rounded overflow-hidden flex">
                    {rows.map((t) => (
                      <div key={t.ref} className={"h-full " + STATUS[t.status].bar} style={{ width: 100 / rows.length + "%" }} title={t.ref + " " + t.name} />
                    ))}
                  </div>
                  <span className="w-10 text-right text-xs text-stone-500" style={mono}>
                    {rows.filter((t) => t.status === "secure" || t.status === "examready").length}/{rows.length}
                  </span>
                </div>
              );
            })}
          </div>
          <div className="flex flex-wrap gap-3 mt-3 text-xs text-stone-600">
            {Object.entries(STATUS).map(([k, v]) => (
              <span key={k} className="flex items-center gap-1">
                <span className={"w-2.5 h-2.5 rounded-full " + v.dot} /> {v.label}
              </span>
            ))}
          </div>
        </section>

        {/* topic grid */}
        <section className="mt-8">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-bold" style={serif}>Every topic</h2>
            <button
              onClick={() => setEditMode(!editMode)}
              className={
                "text-sm px-3 py-1.5 rounded-md border " +
                (editMode ? "bg-stone-800 text-white border-stone-800" : "bg-white border-stone-300 text-stone-700 hover:bg-stone-100")
              }
            >
              {editMode ? "Done updating" : "Update statuses"}
            </button>
          </div>
          {editMode && (
            <p className="text-xs text-stone-600 mt-1">Tap a topic to move it along: not started → gap → developing → secure → exam-ready. Changes save automatically.</p>
          )}
          {Object.entries(STRANDS).map(([k, label]) => (
            <div key={k} className="mt-4">
              <h3 className="text-xs uppercase tracking-widest text-stone-500" style={mono}>{label}</h3>
              <div className="flex flex-wrap gap-1.5 mt-1.5">
                {topics
                  .filter((t) => t.strand === k)
                  .map((t) => (
                    <button
                      key={t.ref}
                      onClick={() => cycleTopic(t.ref)}
                      disabled={!editMode}
                      className={
                        "text-left text-xs border rounded-md px-2 py-1 flex items-center gap-1.5 " +
                        STATUS[t.status].chip +
                        (editMode ? " cursor-pointer hover:shadow" : " cursor-default")
                      }
                      title={STATUS[t.status].label + (t.tier === "H" ? " · Higher-only" : "")}
                    >
                      <span className={"w-2 h-2 rounded-full shrink-0 " + STATUS[t.status].dot} />
                      <span style={mono} className="font-semibold">{t.ref}</span>
                      <span className="truncate max-w-40">{t.name}</span>
                      {t.tier === "H" && <span className="text-stone-400 font-bold">H</span>}
                    </button>
                  ))}
              </div>
            </div>
          ))}
        </section>

        {/* assessments */}
        <section className="mt-8">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-bold" style={serif}>Papers & mocks</h2>
            <button onClick={() => setShowForm(!showForm)} className="text-sm px-3 py-1.5 rounded-md bg-stone-800 text-white hover:bg-stone-700">
              {showForm ? "Cancel" : "Log a paper"}
            </button>
          </div>

          {showForm && (
            <div className="bg-white border border-stone-300 rounded-lg p-4 mt-3 grid grid-cols-2 sm:grid-cols-6 gap-2 text-sm">
              <input className="col-span-2 border border-stone-300 rounded px-2 py-1.5" placeholder="Paper name e.g. 8300/1H Jun-23" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              <select className="border border-stone-300 rounded px-2 py-1.5" value={form.tier} onChange={(e) => setForm({ ...form, tier: e.target.value })}>
                <option value="F">Foundation</option>
                <option value="H">Higher</option>
              </select>
              <input className="border border-stone-300 rounded px-2 py-1.5" type="number" placeholder="Score" value={form.score} onChange={(e) => setForm({ ...form, score: e.target.value })} />
              <input className="border border-stone-300 rounded px-2 py-1.5" type="number" placeholder="Out of" value={form.max} onChange={(e) => setForm({ ...form, max: e.target.value })} />
              <input className="border border-stone-300 rounded px-2 py-1.5" type="number" placeholder="Blanks" title="How many questions left blank" value={form.blanks} onChange={(e) => setForm({ ...form, blanks: e.target.value })} />
              <input className="col-span-2 border border-stone-300 rounded px-2 py-1.5" type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
              <button onClick={addAssessment} className="col-span-2 sm:col-span-1 bg-emerald-600 text-white rounded px-3 py-1.5 hover:bg-emerald-700">Save paper</button>
            </div>
          )}

          <div className="mt-3 space-y-2">
            {[...assessments]
              .sort((a, b) => (a.date < b.date ? 1 : -1))
              .map((a) => (
                <div key={a.id} className="bg-white border border-stone-300 rounded-lg px-4 py-2.5 flex items-center gap-3">
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold truncate">{a.name}</p>
                    <p className="text-xs text-stone-500">{a.date} · tier {a.tier}{a.blanks !== null && a.blanks !== undefined ? ` · ${a.blanks} blank${a.blanks === 1 ? "" : "s"}` : ""}</p>
                  </div>
                  <div className="text-right">
                    <p className="font-bold" style={mono}>{a.score}/{a.max}</p>
                    <p className="text-xs text-stone-500">≈ grade {gradeFor(a.score, a.max, a.tier)}</p>
                  </div>
                  {editMode && (
                    <button onClick={() => removeAssessment(a.id)} className="text-red-500 hover:text-red-700 text-lg leading-none px-1" title="Delete entry">×</button>
                  )}
                </div>
              ))}
          </div>

          {(() => {
            const withBlanks = [...assessments].filter((a) => a.blanks !== null && a.blanks !== undefined).sort((a, b) => (a.date > b.date ? 1 : -1));
            if (withBlanks.length < 1) return null;
            const last = withBlanks[withBlanks.length - 1];
            return (
              <div className="mt-3 bg-amber-50 border border-amber-300 rounded-lg px-4 py-3 text-sm text-amber-900">
                <span className="font-semibold">Attempt-everything meter:</span> {last.blanks === 0 ? "0 blanks on your last timed paper — that's the goal, keep it there! 🎯" : `${last.blanks} blank${last.blanks === 1 ? "" : "s"} on your last timed paper. Every blank is 0 marks; working earns method marks. Aim for zero next time.`}
              </div>
            );
          })()}
        </section>

        {/* footer */}
        <footer className="mt-10 pt-4 border-t border-stone-300 flex items-center justify-between text-xs text-stone-500">
          <span>
            {saveState === "saving" && "Saving…"}
            {saveState === "saved" && "Saved ✓"}
            {saveState === "error" && <span className="text-red-600">Couldn't save — try again</span>}
            {saveState === "idle" && "Data stays on this account between visits"}
          </span>
          {confirmReset ? (
            <span className="flex gap-2">
              <span className="text-red-600">Reset everything to the Aug-2026 baseline?</span>
              <button onClick={resetAll} className="text-red-600 underline">Yes, reset</button>
              <button onClick={() => setConfirmReset(false)} className="underline">Keep my data</button>
            </span>
          ) : (
            <button onClick={() => setConfirmReset(true)} className="underline hover:text-stone-700">Reset to baseline</button>
          )}
        </footer>
      </div>
    </div>
  );
}
