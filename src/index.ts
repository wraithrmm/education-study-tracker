import express from "express";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { Store } from "./db.js";
import { buildMcpServer } from "./mcp.js";
import { mountOAuth, requireToken } from "./oauth.js";
import { renderIndex, renderSubject } from "./dashboard.js";
import { seedIfEmpty } from "./seed.js";

const PORT = Number(process.env.PORT ?? 8080);
const DB_PATH = process.env.DB_PATH ?? "/data/tracker.db";
const PUBLIC_URL = (process.env.PUBLIC_URL ?? `http://localhost:${PORT}`).replace(/\/$/, "");
const PASSWORD = process.env.TRACKER_PASSWORD ?? "";
const DASHBOARD_PUBLIC = process.env.DASHBOARD_PUBLIC !== "false";

if (!PASSWORD) {
  console.error(
    "TRACKER_PASSWORD is not set. Set it to a long random string before starting — it is the only thing standing between the internet and your tracker.",
  );
  process.exit(1);
}

const store = new Store(DB_PATH);
seedIfEmpty(store);

const app = express();
app.set("trust proxy", true);
app.use(express.json({ limit: "1mb" }));
app.use(express.urlencoded({ extended: true }));

mountOAuth(app, { publicUrl: PUBLIC_URL, password: PASSWORD, store });

// ---- MCP endpoint -----------------------------------------------------
// Stateless: a fresh transport per request avoids request-id collisions and
// means the process can restart without breaking an in-flight connection.

const mcpServer = buildMcpServer(store);

app.post("/mcp", requireToken(store, PUBLIC_URL), async (req, res) => {
  try {
    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: undefined,
      enableJsonResponse: true,
    });
    res.on("close", () => void transport.close());
    await mcpServer.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch (err) {
    console.error("MCP request failed:", err);
    if (!res.headersSent) res.status(500).json({ error: "internal_error" });
  }
});

// GET and DELETE exist so clients probing the endpoint get a clear answer
// rather than an Express 404 they have to guess at.
app.get("/mcp", requireToken(store, PUBLIC_URL), (_req, res) =>
  res.status(405).json({
    error: "method_not_allowed",
    error_description: "This server is stateless: POST JSON-RPC to /mcp.",
  }),
);

// ---- dashboard --------------------------------------------------------

const dashboardGuard = DASHBOARD_PUBLIC
  ? (_req: express.Request, _res: express.Response, next: express.NextFunction) => next()
  : requireToken(store, PUBLIC_URL);

app.get("/", dashboardGuard, (_req, res) => res.type("html").send(renderIndex(store)));

app.get("/s/:slug", dashboardGuard, (req, res) => {
  const slug = String(req.params.slug);
  const subject = store.getSubject(slug);
  if (!subject) return res.status(404).type("html").send(renderIndex(store));
  res.type("html").send(renderSubject(store, subject));
});

// ---- JSON API ---------------------------------------------------------
// Read-only and token-guarded: this is what the scheduled email task pulls.

app.get("/api/subjects", requireToken(store, PUBLIC_URL), (_req, res) =>
  res.json(store.listSubjects()),
);

app.get("/api/subjects/:slug", requireToken(store, PUBLIC_URL), (req, res) => {
  const slug = String(req.params.slug);
  const subject = store.getSubject(slug);
  if (!subject) return res.status(404).json({ error: "not_found" });
  res.json({
    subject,
    topics: store.listTopics(slug),
    assessments: store.listAssessments(slug),
    sessions: store.listSessions(slug),
    changes: store.listChanges(slug, 25),
  });
});

app.get("/healthz", (_req, res) =>
  res.json({ ok: true, subjects: store.listSubjects().length }),
);

app.listen(PORT, () => {
  console.log(`Tracker listening on ${PUBLIC_URL}`);
  console.log(`  dashboard  ${PUBLIC_URL}/`);
  console.log(`  MCP        ${PUBLIC_URL}/mcp`);
});
