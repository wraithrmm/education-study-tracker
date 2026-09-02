import { createHash, randomBytes, timingSafeEqual } from "node:crypto";
import type { Request, Response, NextFunction, Express } from "express";
import type { Store } from "./db.js";

/**
 * A deliberately small OAuth 2.1 authorisation server.
 *
 * Claude.ai custom connectors do not accept a static bearer token on a personal
 * account — `static_headers` is a beta feature gated behind an organisation
 * administrator — but OAuth with Dynamic Client Registration is supported out of
 * the box. So this implements exactly the subset Claude's connector flow needs:
 *
 *   - RFC 9728 protected resource metadata
 *   - RFC 8414 authorisation server metadata
 *   - RFC 7591 dynamic client registration
 *   - authorisation code grant with mandatory PKCE (S256)
 *   - refresh tokens with rotation
 *
 * There is exactly one human user (you), so "authentication" is a single shared
 * password on the consent screen rather than a user table.
 */

const CODE_TTL_MS = 10 * 60 * 1000;
const ACCESS_TTL_MS = 60 * 60 * 1000;
const SCOPE = "tracker.read tracker.write";

const token = () => randomBytes(32).toString("base64url");

const s256 = (verifier: string) =>
  createHash("sha256").update(verifier).digest("base64url");

function safeEqual(a: string, b: string): boolean {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  if (ab.length !== bb.length) return false;
  return timingSafeEqual(ab, bb);
}

const escapeHtml = (s: string) =>
  s.replace(/[&<>"']/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]!,
  );

export interface OAuthConfig {
  publicUrl: string;
  password: string;
  store: Store;
}

export function mountOAuth(app: Express, cfg: OAuthConfig): void {
  const { store, publicUrl } = cfg;
  const db = store.db;
  const resource = `${publicUrl}/mcp`;

  const prm = {
    resource,
    authorization_servers: [publicUrl],
    scopes_supported: SCOPE.split(" "),
    bearer_methods_supported: ["header"],
  };

  // RFC 9728. Claude probes the path-suffixed form first, then the bare form.
  app.get("/.well-known/oauth-protected-resource", (_req, res) => res.json(prm));
  app.get("/.well-known/oauth-protected-resource/mcp", (_req, res) => res.json(prm));

  // RFC 8414.
  app.get("/.well-known/oauth-authorization-server", (_req, res) =>
    res.json({
      issuer: publicUrl,
      authorization_endpoint: `${publicUrl}/oauth/authorize`,
      token_endpoint: `${publicUrl}/oauth/token`,
      registration_endpoint: `${publicUrl}/oauth/register`,
      scopes_supported: SCOPE.split(" "),
      response_types_supported: ["code"],
      grant_types_supported: ["authorization_code", "refresh_token"],
      token_endpoint_auth_methods_supported: ["none", "client_secret_post"],
      code_challenge_methods_supported: ["S256"],
    }),
  );
  // Some clients look for the OIDC path instead.
  app.get("/.well-known/openid-configuration", (_req, res) =>
    res.redirect(302, "/.well-known/oauth-authorization-server"),
  );

  // ---- dynamic client registration (RFC 7591) -------------------------

  app.post("/oauth/register", (req, res) => {
    const body = (req.body ?? {}) as Record<string, unknown>;
    const uris = Array.isArray(body.redirect_uris) ? body.redirect_uris : [];
    if (!uris.length) {
      return res.status(400).json({
        error: "invalid_redirect_uri",
        error_description: "redirect_uris must contain at least one URI",
      });
    }
    const clientId = `c_${randomBytes(12).toString("hex")}`;
    db.prepare(
      `INSERT INTO oauth_clients (client_id, client_secret, redirect_uris, client_name)
       VALUES (?, NULL, ?, ?)`,
    ).run(clientId, JSON.stringify(uris), String(body.client_name ?? "unknown"));

    res.status(201).json({
      client_id: clientId,
      client_id_issued_at: Math.floor(Date.now() / 1000),
      redirect_uris: uris,
      token_endpoint_auth_method: "none",
      grant_types: ["authorization_code", "refresh_token"],
      response_types: ["code"],
      client_name: body.client_name ?? "unknown",
    });
  });

  // ---- authorisation --------------------------------------------------

  app.get("/oauth/authorize", (req, res) => {
    const q = req.query as Record<string, string | undefined>;
    const client = db
      .prepare("SELECT * FROM oauth_clients WHERE client_id = ?")
      .get(q.client_id ?? "") as { redirect_uris: string } | undefined;

    if (!client) return res.status(400).send("Unknown client_id.");
    const allowed: string[] = JSON.parse(client.redirect_uris);
    if (!q.redirect_uri || !allowed.includes(q.redirect_uri)) {
      return res.status(400).send("redirect_uri does not match registration.");
    }
    if (q.code_challenge_method !== "S256" || !q.code_challenge) {
      return res.status(400).send("PKCE with S256 is required.");
    }

    const hidden = (["client_id", "redirect_uri", "state", "code_challenge", "scope"] as const)
      .map(
        (k) =>
          `<input type="hidden" name="${k}" value="${escapeHtml(q[k] ?? "")}">`,
      )
      .join("");

    res.type("html").send(`<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Authorise tracker access</title>
<style>
 body{font-family:Georgia,serif;background:#fcfcf9;color:#1c1917;display:flex;
      min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1rem}
 .card{background:#fff;border:1px solid #d6d3d1;border-radius:12px;padding:2rem;max-width:26rem;width:100%}
 h1{font-size:1.25rem;margin:0 0 .5rem}
 p{color:#57534e;font-size:.9rem;line-height:1.5}
 input[type=password]{width:100%;padding:.6rem;border:1px solid #d6d3d1;border-radius:6px;
      font-size:1rem;box-sizing:border-box;margin:.75rem 0}
 button{width:100%;padding:.65rem;background:#1c1917;color:#fff;border:0;border-radius:6px;
      font-size:1rem;cursor:pointer}
 code{background:#f5f5f4;padding:.1rem .3rem;border-radius:3px;font-size:.8rem}
</style></head><body>
<div class="card">
  <h1>Authorise tracker access</h1>
  <p>A client is asking to read and update your study tracker at
     <code>${escapeHtml(publicUrl)}</code>. Enter the tracker password to allow it.</p>
  <form method="POST" action="/oauth/authorize">
    ${hidden}
    <input type="password" name="password" placeholder="Tracker password" autofocus required>
    <button type="submit">Allow access</button>
  </form>
</div></body></html>`);
  });

  app.post("/oauth/authorize", (req, res) => {
    const b = (req.body ?? {}) as Record<string, string>;
    if (!b.password || !safeEqual(b.password, cfg.password)) {
      return res.status(401).send("Incorrect password. Go back and try again.");
    }
    const client = db
      .prepare("SELECT * FROM oauth_clients WHERE client_id = ?")
      .get(b.client_id ?? "") as { redirect_uris: string } | undefined;
    if (!client) return res.status(400).send("Unknown client_id.");
    const allowed: string[] = JSON.parse(client.redirect_uris);
    if (!allowed.includes(b.redirect_uri)) {
      return res.status(400).send("redirect_uri does not match registration.");
    }

    const code = token();
    db.prepare(
      `INSERT INTO oauth_codes (code, client_id, redirect_uri, code_challenge, scope, expires_at)
       VALUES (?, ?, ?, ?, ?, ?)`,
    ).run(
      code,
      b.client_id,
      b.redirect_uri,
      b.code_challenge,
      b.scope || SCOPE,
      Date.now() + CODE_TTL_MS,
    );

    const url = new URL(b.redirect_uri);
    url.searchParams.set("code", code);
    if (b.state) url.searchParams.set("state", b.state);
    res.redirect(302, url.toString());
  });

  // ---- token ----------------------------------------------------------

  const issue = (clientId: string, scope: string) => {
    const access = token();
    const refresh = token();
    const insert = db.prepare(
      `INSERT INTO oauth_tokens (token, kind, client_id, scope, expires_at) VALUES (?, ?, ?, ?, ?)`,
    );
    insert.run(access, "access", clientId, scope, Date.now() + ACCESS_TTL_MS);
    insert.run(refresh, "refresh", clientId, scope, null);
    return {
      access_token: access,
      token_type: "Bearer",
      expires_in: Math.floor(ACCESS_TTL_MS / 1000),
      refresh_token: refresh,
      scope,
    };
  };

  app.post("/oauth/token", (req, res) => {
    const b = (req.body ?? {}) as Record<string, string>;

    if (b.grant_type === "authorization_code") {
      const row = db
        .prepare("SELECT * FROM oauth_codes WHERE code = ?")
        .get(b.code ?? "") as
        | {
            client_id: string;
            redirect_uri: string;
            code_challenge: string;
            scope: string;
            expires_at: number;
          }
        | undefined;

      // Single use, whatever happens next.
      db.prepare("DELETE FROM oauth_codes WHERE code = ?").run(b.code ?? "");

      if (!row || row.expires_at < Date.now()) {
        return res
          .status(400)
          .json({ error: "invalid_grant", error_description: "Code invalid or expired." });
      }
      if (row.client_id !== b.client_id || row.redirect_uri !== b.redirect_uri) {
        return res
          .status(400)
          .json({ error: "invalid_grant", error_description: "Client or redirect mismatch." });
      }
      if (!b.code_verifier || s256(b.code_verifier) !== row.code_challenge) {
        return res
          .status(400)
          .json({ error: "invalid_grant", error_description: "PKCE verification failed." });
      }
      return res.json(issue(row.client_id, row.scope));
    }

    if (b.grant_type === "refresh_token") {
      const row = db
        .prepare("SELECT * FROM oauth_tokens WHERE token = ? AND kind = 'refresh'")
        .get(b.refresh_token ?? "") as
        | { client_id: string; scope: string }
        | undefined;
      if (!row) {
        return res
          .status(400)
          .json({ error: "invalid_grant", error_description: "Refresh token not recognised." });
      }
      // Rotate: public clients must not reuse refresh tokens.
      db.prepare("DELETE FROM oauth_tokens WHERE token = ?").run(b.refresh_token);
      return res.json(issue(row.client_id, row.scope));
    }

    res.status(400).json({
      error: "unsupported_grant_type",
      error_description: "Use authorization_code or refresh_token.",
    });
  });

  // Housekeeping: drop expired codes and access tokens hourly.
  setInterval(() => {
    const now = Date.now();
    db.prepare("DELETE FROM oauth_codes WHERE expires_at < ?").run(now);
    db.prepare(
      "DELETE FROM oauth_tokens WHERE kind = 'access' AND expires_at IS NOT NULL AND expires_at < ?",
    ).run(now);
  }, 3_600_000).unref();
}

/**
 * Bearer auth guard. Returns the 401 + WWW-Authenticate shape Claude needs in
 * order to discover where the authorisation server lives.
 */
export function requireToken(store: Store, publicUrl: string) {
  return (req: Request, res: Response, next: NextFunction): void => {
    const challenge = `Bearer resource_metadata="${publicUrl}/.well-known/oauth-protected-resource"`;
    const header = req.get("authorization") ?? "";
    const match = /^Bearer\s+(.+)$/i.exec(header);
    if (!match) {
      res.set("WWW-Authenticate", challenge).status(401).json({
        error: "unauthorized",
        error_description: "Bearer token required.",
      });
      return;
    }
    const row = store.db
      .prepare("SELECT * FROM oauth_tokens WHERE token = ? AND kind = 'access'")
      .get(match[1]) as { expires_at: number | null } | undefined;
    if (!row || (row.expires_at !== null && row.expires_at < Date.now())) {
      res.set("WWW-Authenticate", challenge).status(401).json({
        error: "invalid_token",
        error_description: "Token expired or unknown.",
      });
      return;
    }
    next();
  };
}
