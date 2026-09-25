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

// A stray notice printed into a response would corrupt the JSON an MCP client
// is parsing, or inject text above the doctype. Errors go to the log instead;
// the log is where a deploy failure is diagnosed from anyway.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---- small helpers the libraries rely on --------------------------------

function h(mixed $s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        // One byte of malformed UTF-8 anywhere in a stored note would
        // otherwise turn the whole reply into an empty body, which the
        // client reports as a failed tool with nothing to act on. Substitute
        // the bad byte and say so in the log; the reply still arrives.
        error_log('tracker: json_encode failed (' . json_last_error_msg() . '); substituting invalid UTF-8');
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }
    echo $body === false ? '{"error":"internal_error","error_description":"The reply could not be encoded."}' : $body;
    exit;
}

// A fatal error inside a tool call — the memory limit, the time limit, an
// engine error nothing catches — used to end the request with an empty 500
// body. Every MCP client renders that as "Tool execution failed", with no
// tool named and no reason. mcp_handle notes the call in flight; if PHP dies
// under it, this answers with a JSON-RPC result that names the tool, the
// subject and the error, so the caller has something to act on.
$GLOBALS['mcp_inflight'] = null;
register_shutdown_function(static function (): void {
    $call = $GLOBALS['mcp_inflight'] ?? null;
    $err  = error_get_last();
    if ($call === null || $err === null) {
        return;
    }
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    $text = $call['name'] . $call['subject'] . ' failed: fatal error: ' . $err['message']
        . ' (' . basename((string) $err['file']) . ':' . $err['line'] . ')';
    error_log('tracker: ' . $text);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $call['id'],
        'result'  => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});

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

require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/practice.php';
require_once __DIR__ . '/lib/seed.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/mcp.php';
require_once __DIR__ . '/lib/parent.php';
require_once __DIR__ . '/lib/dashboard.php';
require_once __DIR__ . '/lib/timetable_edit.php';
require_once __DIR__ . '/lib/dashboard_exam.php';
require_once __DIR__ . '/lib/dashboard_progress.php';

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
    // Counts, not just "ok": a deploy that boots against a database whose
    // migration silently produced nothing would otherwise look healthy.
    send_json([
        'ok'       => true,
        'subjects' => count($store->listSubjects()),
        'counts'   => $store->counts(),
    ]);
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
        'attempts'    => $store->listAttempts($slug, 50),
        'sessions'    => $store->listSessions($slug),
        'changes'     => $store->listChanges($slug, 100),
        'resources'   => $store->listResources($slug),
    ]);
}

// ---- dashboard ----------------------------------------------------------

// ---- the parent's own controls ------------------------------------------
//
// The board stays readable to anyone with the link. Writing to it does not:
// these are the parent's, behind a signed cookie obtained with the service
// password, with a CSRF token on every post.

if ($path === '/login') {
    if ($method === 'GET') {
        send_html(render_login($store, $password, $_GET['next'] ?? '/', false));
    }
    if ($method !== 'POST') {
        send_json(['error' => 'method_not_allowed'], 405);
    }
    $sent = (string) (body()['password'] ?? '');
    if ($sent === '' || !hash_equals($password, $sent)) {
        // A small delay on every failure. The passphrase is long enough that
        // guessing is hopeless anyway, but an unthrottled login form on a
        // public URL is an invitation to try.
        usleep(400000);
        send_html(render_login($store, $password, body()['next'] ?? '/', true), 401);
    }
    parent_set_cookie(parent_issue($store, $password), PARENT_TTL);
    parent_redirect(parent_safe_next(body()['next'] ?? '/'));
}

if ($path === '/logout' && $method === 'POST') {
    parent_set_cookie('', -1);
    parent_redirect('/');
}

/** Every write below is the parent's, and says so. */
$requireParent = static function () use ($store, $password): void {
    if (!parent_signed_in($store, $password) || !parent_check_csrf($store, body())) {
        send_json(['error' => 'forbidden',
            'error_description' => 'Sign in as the parent first.'], 403);
    }
};

// Mark a whole day off, or undo it. The record keeps who asked and why, the
// same as a day off booked through the tools.
if ($path === '/tt/day' && $method === 'POST') {
    $requireParent();
    $b    = body();
    $date = mcp_date($b, 'date', null);
    if ($date === null) {
        send_json(['error' => 'bad_request', 'error_description' => 'date required'], 400);
    }
    if (($b['action'] ?? '') === 'clear') {
        $existing = $store->dayOffCovering($date);
        if ($existing) {
            $store->decideDayOff((int) $existing['id'], 'decline', 'Cleared from the board.');
        }
    } else {
        $reason = trim((string) ($b['reason'] ?? ''));
        $store->addDayOff([
            'date_from' => $date, 'date_to' => $date, 'kind' => 'day_off',
            'reason'    => $reason !== '' ? $reason : 'Day off',
            'requested_by' => 'parent',
        ]);
    }
    parent_redirect(parent_safe_next($b['next'] ?? '/'));
}

// One block: marked done without evidence, skipped with a reason, or cleared
// back to whatever the evidence says.
if ($path === '/tt/block' && $method === 'POST') {
    $requireParent();
    $b    = body();
    $date = mcp_date($b, 'date', null);
    $key  = (int) ($b['block_key'] ?? 0);
    if ($date === null || $key < 1) {
        send_json(['error' => 'bad_request', 'error_description' => 'date and block_key required'], 400);
    }
    $note = trim((string) ($b['note'] ?? ''));
    switch ($b['action'] ?? '') {
        case 'done':
            $store->setTick($date, $key, 'parent', $note !== '' ? $note : null);
            $store->setExcusal($date, $key, null);
            break;
        case 'skip':
            $store->setExcusal($date, $key, $note !== '' ? $note : 'Skipped');
            break;
        case 'clear':
            $store->setExcusal($date, $key, null);
            $store->clearTick($date, $key);
            break;
        default:
            send_json(['error' => 'bad_request', 'error_description' => 'unknown action'], 400);
    }
    parent_redirect(parent_safe_next($b['next'] ?? '/'));
}

// The timetable editor: the board's blocks dragged into a new order or given
// a new length, saved as a new version from a Monday. The page is the
// parent's, so signed out it goes to the login rather than a 403 — it is a
// link a person follows, not a form a script posts.
if ($path === '/tt/edit' && $method === 'GET') {
    if (!parent_signed_in($store, $password)) {
        parent_redirect('/login?next=' . rawurlencode('/tt/edit'));
    }
    send_html(render_timetable_editor($store, tt_edit_from($_GET['from'] ?? null)));
}

// The save. Only a day and two times per block; everything else about a
// block is kept from the version being edited, and no block may be added or
// dropped this way. The store refuses an overlap, a block that ends before it
// starts, and a set of keys that is not exactly the version's.
if ($path === '/tt/edit' && $method === 'POST') {
    $requireParent();
    $b    = body();
    $from = tt_edit_from($b['valid_from'] ?? null);
    if (!isset($b['valid_from']) || $b['valid_from'] !== $from) {
        send_json(['error' => 'bad_request',
            'error_description' => 'valid_from must be a Monday, this week or later.'], 400);
    }
    if (!is_array($b['blocks'] ?? null) || !$b['blocks']) {
        send_json(['error' => 'bad_request', 'error_description' => 'blocks must be a non-empty list.'], 400);
    }
    try {
        $res = $store->retimeTimetable($b['blocks'], $from, 'Edited on the board.');
    } catch (InvalidArgumentException $e) {
        send_json(['error' => 'bad_request', 'error_description' => $e->getMessage() . ' Nothing was written.'], 400);
    }
    send_json([
        'ok'                => true,
        'version_id'        => $res['version_id'],
        'valid_from'        => $from,
        'valid_from_pretty' => tt_pretty($from),
        'blocks'            => $res['blocks'],
        'diff'              => $res['diff'],
    ]);
}

// ---- exam practice ------------------------------------------------------
//
// The timed paper. Reading is as public as the board; the three student
// writes (start, answer, submit) need no login — she has none — and are
// guarded instead by the test's state, a token issued at Start, and the
// deadline. The bank is the parent's alone.

if ($path === '/exam/bank') {
    if (!parent_signed_in($store, $password)) {
        parent_redirect('/login?next=' . rawurlencode('/exam/bank'));
    }
    $dashboardGuard();
    send_html(render_exam_bank($store, $_GET));
}

if ($path === '/exam') {
    $dashboardGuard();
    send_html(render_exam_index($store, parent_signed_in($store, $password)));
}

if (preg_match('#^/exam/(\d+)(?:/(start|answer|submit))?$#', $path, $m)) {
    $examId = (int) $m[1];
    $action = $m[2] ?? null;
    $test   = $store->examTestRow($examId);
    if (!$test) {
        if ($action !== null) {
            send_json(['error' => 'not_found'], 404);
        }
        $dashboardGuard();
        send_html(render_exam_index($store, parent_signed_in($store, $password)), 404);
    }
    // A timer that has run out ended the sitting whether or not anyone was
    // looking: every request closes an overdue test before doing anything.
    $test     = $store->lazyCloseExamTest($test);
    $isParent = parent_signed_in($store, $password);

    if ($action === null) {
        $dashboardGuard();
        send_html(render_exam_test($store, $store->examTest($examId), $isParent));
    }
    if ($method !== 'POST') {
        send_json(['error' => 'method_not_allowed'], 405);
    }
    $b = body();

    if ($action === 'start') {
        // Only the day's paper opens for her; the parent can open any.
        if ($test['status'] !== 'ready') {
            parent_redirect("/exam/$examId");
        }
        if ($test['scheduled_for'] !== tt_today() && !$isParent) {
            send_html(render_exam_locked($examId, 'This paper is for ' . tt_pretty($test['scheduled_for']) . '; it opens on the day.'), 403);
        }
        $store->startExamTest($examId);
        parent_redirect("/exam/$examId");
    }

    if ($action === 'answer') {
        $why = exam_guard_write($test, isset($b['token']) ? (string) $b['token'] : null);
        if ($why !== null) {
            send_json(['error' => 'locked', 'reason' => $why, 'status' => $test['status']], 409);
        }
        $full  = $store->examTest($examId);
        $known = [];
        foreach ($full['sections'] as $sec) {
            foreach ($sec['questions'] as $q) {
                $known[(int) $q['id']] = true;
            }
        }
        $answers = is_array($b['answers'] ?? null) ? $b['answers'] : [$b];
        $saved   = 0;
        foreach ($answers as $ans) {
            if (!is_array($ans)) {
                continue;
            }
            $qid = (int) ($ans['question_id'] ?? 0);
            if (!isset($known[$qid])) {
                continue;
            }
            $store->saveExamAnswer($examId, $qid, [
                'answer'             => mb_substr((string) ($ans['answer'] ?? ''), 0, EXAM_ANSWER_MAX),
                'working'            => mb_substr((string) ($ans['working'] ?? ''), 0, EXAM_ANSWER_MAX),
                'flagged'            => !empty($ans['flagged']),
                'time_spent_seconds' => (int) ($ans['time_spent_seconds'] ?? 0),
            ]);
            $saved++;
        }
        send_json([
            'ok'         => true,
            'saved'      => $saved,
            'server_now' => exam_now(),
            'deadline'   => exam_epoch($test['deadline_at']),
        ]);
    }

    if ($action === 'submit') {
        // Handing in: hers with the token, at any time after the deadline
        // too (it then closes as the timer); the parent's with the login.
        $token = isset($b['token']) ? (string) $b['token'] : null;
        $hers  = $test['status'] === 'open' && $token !== null && $token !== ''
            && hash_equals((string) ($test['sit_token'] ?? ''), $token);
        if ($test['status'] === 'open' && ($hers || $isParent)) {
            $by = $hers ? ((($b['by'] ?? '') === 'timer') ? 'timer' : 'student') : 'parent';
            $store->closeExamTest($examId, $by);
        } elseif ($test['status'] === 'open') {
            send_json(['error' => 'forbidden', 'reason' => 'the token does not match this sitting'], 403);
        }
        parent_redirect("/exam/$examId");
    }
}

if ($path === '/') {
    $dashboardGuard();
    send_html(render_index($store, parent_signed_in($store, $password)));
}

// The term as a ledger: one row per ISO week, newest first, with ?from= for
// the terms before the twenty-six the table caps at.
if ($path === '/weeks') {
    $dashboardGuard();
    send_html(render_weeks($store, $_GET));
}

// Any week, past or present, on the same component as the index page. The
// ISO week is the identifier because that is what the review talks in. An
// impossible week (2026-W99) falls back to the ledger with a 404, the way a
// missing attempt falls back to its subject rather than to a dead end.
if (preg_match('#^/week/(\d{4}-W\d{2})$#', $path, $m)) {
    $dashboardGuard();
    if (tt_week_monday($m[1]) === null) {
        send_html(render_weeks($store, $_GET), 404);
    }
    // ?v= reads an earlier version of the week's written note; anything that
    // is not a version number is simply the latest one.
    $version = isset($_GET['v']) && ctype_digit((string) $_GET['v']) ? (int) $_GET['v'] : null;
    // ?sv= does the same for the week's learning synthesis.
    $synth = isset($_GET['sv']) && ctype_digit((string) $_GET['sv']) ? (int) $_GET['sv'] : null;
    send_html(render_week_page($store, $m[1], parent_signed_in($store, $password), $version, $synth));
}

if (preg_match('#^/s/([^/]+)$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    send_html(render_subject($store, $subject, parent_signed_in($store, $password)));
}

// Progress over time and the forecast: the line, the aimline, the cone.
if (preg_match('#^/s/([^/]+)/progress$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    send_html(render_subject_progress($store, $subject, parent_signed_in($store, $password)));
}

// The practice scoreboard: every panel the subject's configuration asks for,
// filterable by activity, date range and topic.
if (preg_match('#^/s/([^/]+)/practice$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    send_html(render_practice_board($store, $subject, $_GET));
}

// Drill-downs. Everything the tools can report, the page can now show: one
// sitting question by question, one session and what it changed, and one
// topic's whole history. A missing id falls back to the subject page rather
// than a dead end.
if (preg_match('#^/s/([^/]+)/a/(\d+)$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    $attempt = $store->getAttempt($subject['slug'], (int) $m[2]);
    if (!$attempt) {
        send_html(render_subject($store, $subject), 404);
    }
    send_html(render_attempt($store, $subject, $attempt));
}

if (preg_match('#^/s/([^/]+)/session/(\d+)$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    $session = $store->getSession($subject['slug'], (int) $m[2]);
    if (!$session) {
        send_html(render_subject($store, $subject), 404);
    }
    // ?v= reads an earlier version of the session's lesson review; the
    // review renders only for the parent.
    $version = isset($_GET['v']) && ctype_digit((string) $_GET['v']) ? (int) $_GET['v'] : null;
    send_html(render_session($store, $subject, $session, parent_signed_in($store, $password), $version));
}

// The lesson reviews of one subject, and the signals across every subject:
// both parent-only, both read the record and write nothing to it.
if (preg_match('#^/s/([^/]+)/reviews$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    send_html(render_lesson_reviews($store, $subject, parent_signed_in($store, $password)));
}

if ($path === '/signals') {
    $dashboardGuard();
    send_html(render_signals($store, parent_signed_in($store, $password)));
}

// The learner model: what the weekly syntheses hold about how she learns.
if ($path === '/learner') {
    $dashboardGuard();
    $filter = isset($_GET['status']) && in_array($_GET['status'], SYNTH_MODEL_STATUSES, true) ? (string) $_GET['status'] : null;
    send_html(render_learner($store, parent_signed_in($store, $password), $filter));
}

if (preg_match('#^/s/([^/]+)/t/([^/]+)$#', $path, $m)) {
    $dashboardGuard();
    $subject = $store->getSubject(urldecode($m[1]));
    if (!$subject) {
        send_html(render_index($store), 404);
    }
    $topic = $store->getTopic($subject['slug'], urldecode($m[2]));
    if (!$topic) {
        send_html(render_subject($store, $subject), 404);
    }
    send_html(render_topic_history($store, $subject, $topic, parent_signed_in($store, $password)));
}

send_json(['error' => 'not_found', 'error_description' => "No route for $path"], 404);
