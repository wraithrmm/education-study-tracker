<?php
/**
 * Front controller. Every request reaches here via .htaccess, so this file owns
 * the routing table that src/index.ts owned before.
 *
 * The service runs per-request under Apache's PHP rather than as a long-lived
 * Node process, because DreamHost's shared hosting refuses V8 the executable
 * memory a JIT needs (ENOMEM in OS::SetPermissions, from a script as small as
 * opening a database). PHP needs no JIT, no Passenger and no supervision, so
 * the same features run with far fewer moving parts. See DEPLOYMENT.md.
 */
declare(strict_types=1);

define('TRACKER', true);

// ---- small helpers the libraries rely on --------------------------------

function h(mixed $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function send_text(string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

function send_html(string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $body;
    exit;
}

// ---- configuration -------------------------------------------------------

/**
 * Config lives outside the document root so a release rsync can never take the
 * password or the database with it, and so the web server can never serve them.
 */
function load_env(): void
{
    $candidates = array_filter([
        getenv('TRACKER_ENV_FILE') ?: null,
        dirname(__DIR__) . '/tracker-shared/.env',
        __DIR__ . '/../tracker-shared/.env',
    ]);
    foreach ($candidates as $file) {
        if (!is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if (strlen($val) >= 2
                && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            if (getenv($key) === false) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
            }
        }
        return;
    }
}

load_env();

$publicUrl = rtrim((string) (getenv('PUBLIC_URL') ?: ''), '/');
if ($publicUrl === '') {
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $publicUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

$password = (string) (getenv('TRACKER_PASSWORD') ?: '');
if ($password === '') {
    // The same refusal the Node version made at startup: without this there is
    // nothing between the internet and the tracker.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("TRACKER_PASSWORD is not set. Set it in tracker-shared/.env before using this service.\n");
}

$dashboardPublic = (getenv('DASHBOARD_PUBLIC') ?: 'true') !== 'false';
$dbPath = (string) (getenv('DB_PATH') ?: (dirname(__DIR__) . '/tracker-shared/data/tracker.db'));

require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/seed.php';
require __DIR__ . '/lib/oauth.php';
require __DIR__ . '/lib/mcp.php';
require __DIR__ . '/lib/dashboard.php';

try {
    $store = new Store($dbPath);
    seedIfEmpty($store);
} catch (Throwable $e) {
    error_log('tracker: store unavailable: ' . $e->getMessage());
    send_json(['error' => 'internal_error', 'error_description' => 'The database could not be opened.'], 500);
}

oauth_sweep_expired($store);

// ---- request ------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($path === '') {
    $path = '/';
}

/** Parsed request body: JSON when sent as JSON, form encoding otherwise. */
function body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== false) {
        $raw     = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $cached  = is_array($decoded) ? $decoded : [];
    } else {
        $cached = $_POST;
    }
    return $cached;
}

$dashboardGuard = static function () use ($dashboardPublic, $store, $publicUrl): void {
    if (!$dashboardPublic) {
        require_token($store, $publicUrl);
    }
};

// ---- routes -------------------------------------------------------------

if ($path === '/healthz') {
    send_json(['ok' => true, 'subjects' => count($store->listSubjects())]);
}

// RFC 9728. Claude probes the path-suffixed form first, then the bare form.
if ($path === '/.well-known/oauth-protected-resource'
    || $path === '/.well-known/oauth-protected-resource/mcp') {
    send_json(oauth_protected_resource_metadata($publicUrl));
}

// RFC 8414.
if ($path === '/.well-known/oauth-authorization-server') {
    send_json(oauth_authorization_server_metadata($publicUrl));
}

// Some clients look for the OIDC path instead.
if ($path === '/.well-known/openid-configuration') {
    header('Location: /.well-known/oauth-authorization-server', true, 302);
    exit;
}

if ($path === '/oauth/register') {
    if ($method !== 'POST') {
        send_json(['error' => 'method_not_allowed'], 405);
    }
    oauth_register($store, body());
}

if ($path === '/oauth/authorize') {
    if ($method === 'GET') {
        oauth_authorize_form($store, $publicUrl, $_GET);
    }
    if ($method === 'POST') {
        oauth_authorize_submit($store, $password, body());
    }
    send_json(['error' => 'method_not_allowed'], 405);
}

if ($path === '/oauth/token') {
    if ($method !== 'POST') {
        send_json(['error' => 'method_not_allowed'], 405);
    }
    oauth_token_endpoint($store, body());
}

// ---- MCP ----------------------------------------------------------------

if ($path === '/mcp') {
    require_token($store, $publicUrl);

    // GET and DELETE exist so clients probing the endpoint get a clear answer
    // rather than a bare 404 they have to guess at.
    if ($method !== 'POST') {
        send_json([
            'error'             => 'method_not_allowed',
            'error_description' => 'This server is stateless: POST JSON-RPC to /mcp.',
        ], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        send_json([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => -32700, 'message' => 'Parse error'],
        ], 400);
    }

    // A batch is an array of requests; a single call is one object.
    if (array_is_list($req)) {
        $out = [];
        foreach ($req as $one) {
            if (!is_array($one)) {
                continue;
            }
            $res = mcp_handle($store, $one);
            if ($res !== null) {
                $out[] = $res;
            }
        }
        if (!$out) {
            http_response_code(202);
            exit;
        }
        send_json($out);
    }

    $res = mcp_handle($store, $req);
    if ($res === null) {
        // A notification: accepted, nothing to say back.
        http_response_code(202);
        exit;
    }
    send_json($res);
}

// ---- JSON API -----------------------------------------------------------
// Read-only and token-guarded: this is what a scheduled job pulls.

if ($path === '/api/subjects') {
    require_token($store, $publicUrl);
    send_json($store->listSubjects());
}

if (preg_match('#^/api/subjects/([^/]+)$#', $path, $m)) {
    require_token($store, $publicUrl);
    $slug    = urldecode($m[1]);
    $subject = $store->getSubject($slug);
    if (!$subject) {
        send_json(['error' => 'not_found'], 404);
    }
    send_json([
        'subject'     => $subject,
        'topics'      => $store->listTopics($slug),
        'assessments' => $store->listAssessments($slug),
        'sessions'    => $store->listSessions($slug),
        'changes'     => $store->listChanges($slug, 25),
    ]);
}

// ---- dashboard ----------------------------------------------------------

if ($path === '/') {
    $dashboardGuard();
    send_html(render_index($store));
}

if (preg_match('#^/s/([^/]+)$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    send_html(render_subject($store, $subject));
}

send_json(['error' => 'not_found', 'error_description' => "No route for $path"], 404);
