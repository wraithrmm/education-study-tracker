<?php
/**
 * Acceptance tests for last-session continuity, unfinished work, kind-aware
 * block shape checks and retrieval scheduling.
 *
 *   php deploy/continuity-test.php
 *
 * Drives the MCP tools directly against a throwaway database with the clock
 * frozen at Monday 21 September 2026, so "days open", "days ago", "overdue"
 * and the week under judgement are fixed rather than whatever the runner's
 * wall clock says. Every check maps to an acceptance line in the spec.
 */
declare(strict_types=1);

define('TRACKER', true);
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!function_exists('h')) {
    function h(mixed $s): string
    {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../php/lib/store.php';
require_once __DIR__ . '/../php/lib/practice.php';
require_once __DIR__ . '/../php/lib/mcp.php';
require_once __DIR__ . '/../php/lib/dashboard.php';

$failures = 0;
$checks   = 0;

function pass(string $what): void
{
    global $checks;
    $checks++;
    printf("  ok    %s\n", $what);
}

function fail(string $what, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    $failures++;
    printf("  FAIL  %s%s\n", $what, $detail === '' ? '' : "\n        $detail");
}

function check(string $what, mixed $got, mixed $want): void
{
    $got === $want ? pass($what) : fail($what, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

function contains(string $what, string $haystack, string $needle): void
{
    str_contains($haystack, $needle) ? pass($what) : fail($what, "missing: $needle\n        in: " . substr($haystack, 0, 600));
}

function lacks(string $what, string $haystack, string $needle): void
{
    str_contains($haystack, $needle) ? fail($what, "unexpectedly present: $needle") : pass($what);
}

/** A tool call the way the endpoint makes it; a refusal comes back as its message. */
function call(Store $store, string $name, array $args): string
{
    try {
        $res = mcp_call_tool($store, $name, $args);
    } catch (McpError $e) {
        return 'REFUSED: ' . $e->getMessage();
    }
    return $res['content'][0]['text'] ?? '';
}

/** The session id a tracker_log_session reply reports. */
function logged_id(string $reply): int
{
    return preg_match('/Session (\d+) logged/', $reply, $m) ? (int) $m[1] : 0;
}

/** The JSON object printed under a heading, decoded. */
function json_block(string $text, string $heading): mixed
{
    $at = strpos($text, $heading);
    if ($at === false) {
        return 'NO SUCH HEADING';
    }
    $start = strpos($text, "```json\n", $at);
    $end   = strpos($text, "\n```", $start + 8);
    return json_decode(substr($text, $start + 8, $end - $start - 8), true);
}

/** The judged block for a date and key out of judgeWeek(). */
function block_on(array $week, string $date, int $key): ?array
{
    foreach ($week['days'] as $day) {
        if ($day['date'] !== $date) {
            continue;
        }
        foreach ($day['blocks'] as $b) {
            if ($b['block_key'] === $key) {
                return $b;
            }
        }
    }
    return null;
}

function touched(Store $store, string $slug, string $ref, ?string $date): void
{
    $st = $store->db->prepare('UPDATE topics SET last_touched = ? WHERE subject_slug = ? AND ref = ?');
    $st->execute([$date, $slug, $ref]);
}

function state(Store $store, string $slug, string $grain, string $key): ?array
{
    $st = $store->db->prepare('SELECT * FROM retrieval_state WHERE subject_slug = ? AND grain = ? AND key = ?');
    $st->execute([$slug, $grain, $key]);
    return $st->fetch() ?: null;
}

putenv('TRACKER_NOW=2026-09-21 10:00');
$dbPath = sys_get_temp_dir() . '/continuity-test-' . getmypid() . '.db';
@unlink($dbPath);
$store = new Store($dbPath);
register_shutdown_function(static function () use ($dbPath) {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
});

echo "== schema ==\n";
check('a fresh database reaches the current schema version', $store->meta('schema_version'), '13');
foreach (['unfinished', 'unfinished_refs', 'unfinished_closed_at', 'unfinished_closed_by_session_id',
          'unfinished_closed_reason', 'consolidates'] as $col) {
    $cols = array_column($store->db->query('PRAGMA table_info(sessions)')->fetchAll(), 'name');
    check("sessions has $col", in_array($col, $cols, true), true);
}
$rules = $store->blockKindRules();
check('block_kind_rules is seeded for every kind in the spec',
    array_diff(['retrieval', 'teach', 'practise', 'consolidate', 'timed_handwritten', 'coding', 'writing', '*'],
        array_keys($rules)), []);
check('the interval ladder is a config row', $store->retrievalIntervals('maths'), [1, 3, 7, 14, 30, 60]);
$sources = array_column($store->listPracticeSources(), 'key');
foreach (['retrieval_warmup', 'retrieval_mixed', 'retrieval_subject'] as $src) {
    check("the $src source is registered", in_array($src, $sources, true), true);
}

// ---- fixture -------------------------------------------------------------
call($store, 'tracker_create_subject', [
    'slug' => 'maths', 'name' => 'GCSE Mathematics', 'strands' => ['A' => 'Algebra', 'N' => 'Number', 'P' => 'Probability', 'R' => 'Ratio'],
    'topics' => [
        ['ref' => 'A17', 'name' => 'Solving linear equations', 'strand' => 'A', 'status' => 'secure'],
        ['ref' => 'A4', 'name' => 'Simplify, expand, factorise', 'strand' => 'A', 'status' => 'developing',
            'watch' => 'retest outstanding'],
        ['ref' => 'P8', 'name' => 'Combined events', 'strand' => 'P', 'status' => 'developing'],
        ['ref' => 'P6-P7', 'name' => 'Tree diagrams', 'strand' => 'P', 'status' => 'developing'],
        ['ref' => 'N4', 'name' => 'HCF and LCM', 'strand' => 'N', 'status' => 'secure'],
        ['ref' => 'R9', 'name' => 'Reverse percentages', 'strand' => 'R', 'status' => 'examready'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'english-literature', 'name' => 'GCSE English Literature', 'strands' => ['P' => 'Poetry', 'K' => 'Skills'],
    'topics' => [
        ['ref' => 'P1.01', 'name' => 'Ozymandias', 'strand' => 'P', 'status' => 'developing', 'watch' => 'context outstanding'],
        ['ref' => 'K1', 'name' => 'What–how–why paragraph', 'strand' => 'K', 'status' => 'developing'],
        ['ref' => 'K5', 'name' => 'Writer as subject', 'strand' => 'K', 'status' => 'secure'],
        ['ref' => 'K3.2', 'name' => 'Structure → effect', 'strand' => 'K', 'status' => 'developing'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'computer-science', 'name' => 'GCSE Computer Science', 'strands' => ['C' => 'Computing'],
    'topics' => [
        ['ref' => 'C1', 'name' => 'Binary', 'strand' => 'C', 'status' => 'secure'],
        ['ref' => 'C2', 'name' => 'Iteration', 'strand' => 'C', 'status' => 'developing', 'watch' => 'nested loops'],
        ['ref' => 'C3', 'name' => 'Networks', 'strand' => 'C', 'status' => 'secure'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'english-language', 'name' => 'GCSE English Language', 'strands' => ['L' => 'Language'],
    'topics' => [
        ['ref' => 'L1', 'name' => 'Comma splice', 'strand' => 'L', 'status' => 'secure'],
        ['ref' => 'L2', 'name' => 'Descriptive openings', 'strand' => 'L', 'status' => 'developing'],
        ['ref' => 'L3', 'name' => 'Paragraphing', 'strand' => 'L', 'status' => 'secure', 'watch' => 'still long'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'spanish', 'name' => 'GCSE Spanish', 'strands' => ['T' => 'Themes'],
    'topics' => [['ref' => 'T1', 'name' => 'Free time', 'strand' => 'T', 'status' => 'developing']],
]);
foreach ([
    ['maths', 'A17', '2026-08-13'], ['maths', 'A4', '2026-09-15'], ['maths', 'P8', '2026-09-18'],
    ['maths', 'P6-P7', '2026-09-17'], ['maths', 'N4', '2026-07-01'], ['maths', 'R9', '2026-06-01'],
    ['english-literature', 'P1.01', '2026-09-15'], ['english-literature', 'K1', '2026-09-16'],
    ['english-literature', 'K5', '2026-08-01'], ['english-literature', 'K3.2', '2026-09-17'],
    ['computer-science', 'C1', '2026-08-01'], ['computer-science', 'C2', '2026-09-14'],
    ['computer-science', 'C3', '2026-07-15'],
    ['english-language', 'L1', '2026-08-10'], ['english-language', 'L2', '2026-09-16'],
    ['english-language', 'L3', '2026-06-20'],
] as [$slug, $ref, $date]) {
    touched($store, $slug, $ref, $date);
}

$mixed = ['maths', 'english-literature', 'english-language', 'computer-science'];
call($store, 'tracker_set_timetable', ['valid_from' => '2026-09-14', 'note' => 'continuity fixture', 'blocks' => [
    ['block_key' => 2, 'weekday' => 1, 'start' => '09:00', 'end' => '09:15', 'kind' => 'retrieval',
        'label' => 'Retrieval warm-up (mixed)', 'subjects' => $mixed, 'tracking' => 'evidence'],
    ['block_key' => 3, 'weekday' => 1, 'start' => '09:15', 'end' => '10:30', 'kind' => 'teach',
        'label' => 'Maths — new topic', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 5, 'weekday' => 1, 'start' => '11:00', 'end' => '12:00', 'kind' => 'teach',
        'label' => 'English Literature — set text', 'subjects' => ['english-literature'], 'tracking' => 'evidence'],
    ['block_key' => 7, 'weekday' => 1, 'start' => '13:00', 'end' => '14:00', 'kind' => 'coding',
        'label' => 'Computer Science — Python', 'subjects' => ['computer-science'], 'tracking' => 'evidence'],
    ['block_key' => 12, 'weekday' => 2, 'start' => '09:15', 'end' => '10:30', 'kind' => 'practise',
        'label' => 'Maths — interleaved practice', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 16, 'weekday' => 2, 'start' => '13:00', 'end' => '14:00', 'kind' => 'timed_handwritten',
        'label' => 'Timed handwritten practice', 'subjects' => $mixed, 'tracking' => 'evidence'],
    ['block_key' => 20, 'weekday' => 3, 'start' => '09:45', 'end' => '10:00', 'kind' => 'spanish',
        'label' => 'Spanish — vocab + listening', 'subjects' => ['spanish'], 'tracking' => 'evidence'],
    ['block_key' => 22, 'weekday' => 4, 'start' => '09:15', 'end' => '10:20', 'kind' => 'teach',
        'label' => 'Deep block — Maths', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 28, 'weekday' => 5, 'start' => '09:15', 'end' => '10:30', 'kind' => 'consolidate',
        'label' => 'Maths — consolidate + fix errors', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 33, 'weekday' => 5, 'start' => '13:00', 'end' => '13:45', 'kind' => 'consolidate',
        'label' => 'Computer Science — quiz + fix code', 'subjects' => ['computer-science'], 'tracking' => 'evidence'],
]]);

echo "\n== 0. a failure names the tool and the subject ==\n";
$res = mcp_handle($store, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
    'params' => ['name' => 'tracker_log_session', 'arguments' => ['subject' => 'maths']]]);
check('a refusal is an isError result', $res['result']['isError'] ?? null, true);
contains('and its text names the tool and the subject', $res['result']['content'][0]['text'],
    'tracker_log_session for subject "maths" refused: summary is required.');
$res = mcp_handle($store, ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
    'params' => ['name' => 'tracker_retrieval_due', 'arguments' => ['subjects' => ['maths', 'spanish'], 'limit' => 'x']]]);
contains('a multi-subject refusal names the subjects', $res['result']['content'][0]['text'],
    'tracker_retrieval_due for subjects maths, spanish refused: limit must be a number.');
check('an unknown tool is a refusal with the tool named', str_starts_with(
    mcp_handle($store, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
        'params' => ['name' => 'tracker_nope', 'arguments' => []]])['result']['content'][0]['text'],
    'tracker_nope refused: Unknown tool'), true);

echo "\n== A. last-session continuity ==\n";
$body = call($store, 'tracker_review_queue', ['subject' => 'maths']);
contains('a subject with no sessions returns last_session: null', $body, "### last_session\n```json\nnull\n```");
contains('and says so rather than erroring', $body, 'No sessions logged for this subject yet');
check('reviewQueue carries an empty unfinished group', $store->reviewQueue('maths')['unfinished'], []);

$next1 = "Finish the P8 exit ticket — Q5 was not attempted (one criticism of \"the probability of rolling a 3 is 1/4\" from 15 in 60 rolls). Then teach TREE DIAGRAMS.";
$s1 = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-14',
    'summary' => 'P8 combined events taught; exit ticket cut short at Q4.', 'block_key' => 3,
    'duration_minutes' => 70, 'next_steps' => $next1,
    'updates' => [['ref' => 'P8', 'status' => 'developing', 'evidence' => 'taught 14 Sep, add/multiply decision correct']]]));
check('the first session logs', $s1 > 0, true);
$next2 = 'Re-run Q5 of the P8 exit ticket, then tree diagrams.';
$s2 = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-18',
    'summary' => 'Consolidation Friday: went over the week, nothing named.', 'block_key' => 28,
    'duration_minutes' => 60, 'next_steps' => $next2]));
$s3 = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-20',
    'summary' => 'Logged against the wrong subject entirely.', 'next_steps' => 'nothing']));
call($store, 'tracker_amend_session', ['subject' => 'maths', 'session_id' => $s3, 'void_reason' => 'wrong subject']);

$body = call($store, 'tracker_review_queue', ['subject' => 'maths']);
$last = json_block($body, '### last_session');
check('the queue opens on the most recent non-void session, not the void one', $last['session_id'] ?? null, $s2);
check('with days_ago counted from the frozen clock', $last['days_ago'] ?? null, 3);
check('and next_steps verbatim', $last['next_steps'] ?? null, $next2);
check('and the block it ran against', $last['block_key'] ?? null, 28);
check('and its duration', $last['duration_minutes'] ?? null, 60);
check('and stale false inside a fortnight', $last['stale'] ?? null, false);
check('and the summary tail', $last['summary_tail'] ?? null, 'Consolidation Friday: went over the week, nothing named.');
$posLast = strpos($body, '### last_session');
$posAge  = strpos($body, '### Ageing');
check('last_session comes before the three groups', $posLast !== false && $posAge !== false && $posLast < $posAge, true);
$block = $store->lastSessionBlock('maths');
check('the store block matches the tool', $block['next_steps'], $next2);
// Stale is a flag, not a filter: an old plan is still returned.
$store->db->exec("UPDATE sessions SET date = '2026-09-01' WHERE id = $s2");
$store->db->exec("UPDATE sessions SET date = '2026-08-20' WHERE id = $s1");
$block = $store->lastSessionBlock('maths');
check('a last session over 14 days old is flagged stale', $block['stale'], true);
check('and still returned', $block['session_id'], $s2);
$store->db->exec("UPDATE sessions SET date = '2026-09-18' WHERE id = $s2");
$store->db->exec("UPDATE sessions SET date = '2026-09-14' WHERE id = $s1");
$body = call($store, 'tracker_review_queue', ['subject' => 'spanish']);
contains('a subject with zero sessions still answers, with null', $body, "```json\nnull\n```");

$body = call($store, 'tracker_today', []);
contains('tracker_today carries a last_session block per subject with a block today', $body, '## maths');
contains('with the same plan verbatim', $body, $next2);
contains('and null for a subject with a block but no session', $body, "## english-literature\n### last_session\n```json\nnull");

echo "\n== B. unfinished work ==\n";
$e1 = logged_id($r = call($store, 'tracker_log_session', ['subject' => 'english-literature', 'date' => '2026-09-21',
    'summary' => 'Ozymandias: form and framing. Paragraph started, second half not written.', 'block_key' => 5,
    'duration_minutes' => 55, 'next_steps' => 'Finish the paragraph before anything new.',
    'unfinished' => 'Ozymandias analysis, second half not written', 'unfinished_refs' => ['P1.01', 'K1'],
    'updates' => [['ref' => 'P1.01', 'evidence' => 'framing worked out largely unaided']]]));
contains('logging with unfinished says it was recorded', $r, "Unfinished work recorded against session $e1");
$body = call($store, 'tracker_review_queue', ['subject' => 'english-literature']);
contains('the queue returns it in the UNFINISHED group', $body, '### UNFINISHED');
contains('with days_open 0', $body, "**Session $e1** (2026-09-21, 0 days open, block 5): Ozymandias analysis, second half not written — refs P1.01, K1");
check('and the group comes first, before ageing', strpos($body, '### UNFINISHED') < strpos($body, '### Ageing'), true);
$last = json_block($body, '### last_session');
check('and last_session carries the same item', $last['unfinished'] ?? null, 'Ozymandias analysis, second half not written');
$open = $store->openUnfinished('english-literature');
check('the store lists one open item', count($open), 1);
check('with days_open 0 and stale false', [$open[0]['days_open'], $open[0]['stale']], [0, false]);
$body = call($store, 'tracker_today', []);
contains('tracker_today lists open unfinished items for a subject with a block today', $body,
    "### unfinished (open — finish these before starting something new)\n- **Session $e1**");

// The nudge: a later session that neither resolves nor sets one.
$r  = call($store, 'tracker_log_session', ['subject' => 'english-literature', 'date' => '2026-09-21',
    'summary' => 'Started a new poem, London, without finishing the last one.']);
$e2 = logged_id($r);
check('the write still succeeds', $e2 > 0, true);
contains('and the result carries the warning, in the spec\'s words', $r,
    "warning: Session $e1 (2026-09-21) is still marked unfinished: 'Ozymandias analysis, second half not written'. This session did not resolve it. If it was completed, log it with resolves: [$e1]");
contains('and names the amend call that closes it against this session', $r,
    "tracker_amend_session(subject: \"english-literature\", session_id: $e2, resolves: [$e1])");

// Resolution.
$r  = call($store, 'tracker_log_session', ['subject' => 'english-literature', 'date' => '2026-09-21',
    'summary' => 'Finished the Ozymandias paragraph: second half written, one angle held.', 'resolves' => [$e1]]);
$e3 = logged_id($r);
contains('a session with resolves closes the item', $r, "Resolved: unfinished work from session $e1 is now closed against this session");
lacks('and carries no warning', $r, 'warning:');
$body = call($store, 'tracker_review_queue', ['subject' => 'english-literature']);
lacks('the item leaves the queue', $body, '### UNFINISHED');
contains('which says nothing is open', $body, "### Unfinished\nNothing open.");
$body = call($store, 'tracker_history', ['subject' => 'english-literature', 'weeks' => 260]);
contains('history shows the closure on the session that opened it', $body,
    "Unfinished: Ozymandias analysis, second half not written (refs P1.01, K1) — closed 2026-09-21 by session $e3: completed in session $e3");
contains('and on the session that closed it', $body, "Resolved session $e1's unfinished work (2026-09-21): Ozymandias analysis");
$row = $store->sessionById($e1);
check('the closure fields are set', [(int) $row['unfinished_closed_by_session_id'], $row['unfinished_closed_reason']],
    [$e3, "completed in session $e3"]);

// Idempotent: resolving again is a no-op.
$r = call($store, 'tracker_log_session', ['subject' => 'english-literature', 'date' => '2026-09-21',
    'summary' => 'A fourth session, resolving the same thing again.', 'resolves' => [$e1]]);
contains('resolves applied twice is a no-op, not a refusal', $r, "session $e1 was already closed by session $e3 (completed in session $e3) — resolving it again is a no-op");
check('and the closing session is unchanged', (int) $store->sessionById($e1)['unfinished_closed_by_session_id'], $e3);

// Refusals name the offending id.
$before = count($store->listSessions('maths', 100));
$r = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-21',
    'summary' => 'Trying to resolve a Literature item from maths.', 'resolves' => [$e1]]);
contains('resolves naming another subject\'s session is refused, with the id named', $r,
    "REFUSED: resolves names session $e1, which belongs to english-literature, not maths");
check('and nothing was written', count($store->listSessions('maths', 100)), $before);
$r = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-21',
    'summary' => 'Trying to resolve a session that has nothing unfinished.', 'resolves' => [$s1]]);
contains('a session with no unfinished work cannot be resolved', $r, "REFUSED: resolves names session $s1, which has no unfinished work recorded");
$r = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-21',
    'summary' => 'Trying to resolve a session that does not exist.', 'resolves' => [9999]]);
contains('an unknown id is refused by name', $r, 'REFUSED: resolves names session 9999, and there is no such session');

// Ageing: stale at 22 days, auto-closed at 57.
$mOld = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-08-30',
    'summary' => 'Twenty-two days ago; the exit ticket stopped at Q4.',
    'unfinished' => 'P8 exit ticket stopped at Q4 — Q5 not attempted']));
$open = $store->openUnfinished('maths');
check('an item 22 days open comes back stale', [$open[0]['days_open'] ?? null, $open[0]['stale'] ?? null], [22, true]);
$body = call($store, 'tracker_review_queue', ['subject' => 'maths']);
contains('and the queue says STALE, still listed first', $body, "**Session $mOld** (2026-08-30, 22 days open, STALE): P8 exit ticket stopped at Q4");
$mAncient = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-07-26',
    'summary' => 'Fifty-seven days ago; something left over.', 'unfinished' => 'an item nobody came back to']));
$ids = array_column($store->openUnfinished('maths'), 'session_id');
check('an item 57 days open is auto-closed and off the queue', in_array($mAncient, $ids, true), false);
$row = $store->sessionById($mAncient);
check('with the auto reason and no closing session',
    [$row['unfinished_closed_reason'], $row['unfinished_closed_by_session_id']], [UNFINISHED_AUTO_CLOSE_REASON, null]);
check('and the text kept', $row['unfinished'], 'an item nobody came back to');
$body = call($store, 'tracker_history', ['subject' => 'maths', 'weeks' => 260]);
contains('and it is still visible in history', $body, 'Unfinished: an item nobody came back to — closed 2026-09-21: auto-closed, stale');

// By hand.
$r = call($store, 'tracker_amend_session', ['subject' => 'maths', 'session_id' => $mOld, 'unfinished' => null]);
contains('amend with unfinished: null closes the item by hand', $r, 'unfinished item closed by hand');
check('and it leaves the queue', $store->openUnfinished('maths'), []);
check('but the row still shows it existed', $store->sessionById($mOld)['unfinished'], 'P8 exit ticket stopped at Q4 — Q5 not attempted');
$r = call($store, 'tracker_amend_session', ['subject' => 'maths', 'session_id' => $mOld,
    'unfinished' => 'P8 exit ticket stopped at Q4 — Q5 not attempted (criticise the 1/4 claim)']);
contains('amend with text sets it', $r, 'unfinished set');
check('and re-opens it', count($store->openUnfinished('maths')), 1);
$r = call($store, 'tracker_amend_session', ['subject' => 'maths', 'session_id' => $mOld, 'resolves' => [$mOld]]);
contains('a session cannot resolve itself', $r, "REFUSED: resolves names session $mOld itself");
$r = call($store, 'tracker_amend_session', ['subject' => 'maths', 'session_id' => $s2, 'resolves' => [$mOld]]);
contains('amend can resolve after the fact', $r, "resolved session $mOld's unfinished work");
check('closing it against the amended session', (int) $store->sessionById($mOld)['unfinished_closed_by_session_id'], $s2);

$body = call($store, 'tracker_week_report', ['week' => '2026-W39']);
contains('the week report counts unfinished work per subject', $body, '- unfinished: 1 opened this week, 1 closed this week, 0 open now.');
$snap = $store->weekSnapshot('2026-09-21');
check('and the snapshot carries it', count($snap['unfinished']['english-literature']['closed']), 1);

echo "\n== D. kind-aware block shape checks ==\n";
// Week 2026-W38, 14–18 September, entirely in the past. Every block below
// is done — by a record of the shape the block asked for, or not.
call($store, 'tracker_log_session', ['subject' => 'english-literature', 'date' => '2026-09-14',
    'summary' => 'Read the poem aloud twice, no topic moved.', 'block_key' => 5, 'duration_minutes' => 50]);
$r = call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [[
    'client_run_id' => 'shape-retr-3', 'source' => 'retrieval_mixed', 'label' => 'Three quick questions',
    'played_at' => '2026-09-14T08:05:00Z', 'attempted' => 3, 'correct' => 3, 'incorrect' => 0, 'block_key' => 2,
    'items' => [['topic_ref' => 'A17', 'outcome' => 'correct'], ['topic_ref' => 'N4', 'outcome' => 'correct'],
                ['topic_ref' => 'R9', 'outcome' => 'correct']]]]]);
contains('a three-item retrieval run stores', $r, '1 stored');
call($store, 'tracker_log_session', ['subject' => 'computer-science', 'date' => '2026-09-14',
    'summary' => 'Twenty minutes of Python before the call.', 'block_key' => 7, 'duration_minutes' => 20]);
call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-15',
    'summary' => 'Interleaved practice: expansion and combined events.', 'block_key' => 12, 'duration_minutes' => 70,
    'updates' => [
        ['ref' => 'A4', 'status' => 'gap', 'evidence' => 'sign error again on 3(2x−5) − 2(x+4); demoted'],
        ['ref' => 'P8', 'evidence' => 'both-the-same-colour products correct unprompted'],
    ]]);
call($store, 'tracker_log_attempt', ['subject' => 'maths', 'name' => 'Timed Q-set', 'kind' => 'check',
    'date' => '2026-09-15', 'papers' => [['code' => 'Set 3', 'score' => 8, 'max' => 10, 'blanks' => 0,
        'sat_on' => '2026-09-15', 'block_key' => 16]]]);
call($store, 'tracker_log_session', ['subject' => 'spanish', 'date' => '2026-09-16',
    'summary' => 'Vocab set 4 and a short listening.', 'block_key' => 20, 'duration_minutes' => 15]);
$r = call($store, 'tracker_log_session', ['subject' => 'computer-science', 'date' => '2026-09-18',
    'summary' => 'Quiz and fix: nested loop bug from Monday re-worked.', 'block_key' => 33, 'duration_minutes' => 45,
    'consolidates' => [['ref' => 'C2']]]);
contains('a session can state what it consolidated', $r, 'Consolidated: C2.');

$week = $store->judgeWeek('2026-09-14');
$b = block_on($week, '2026-09-18', 28);
check('a consolidate block met by a session naming nothing is done', $b['status'], 'done');
check('and done_shape_unmet', $b['shape'], 'unmet');
contains('with a reason naming what was expected', (string) $b['shape_reason'],
    'expected a consolidates list naming the errors re-worked, or an update on a topic that had a demotion, a blank or a prompted answer in the prior 21 days; got a session with no topic updates, 60 min recorded');
$b = block_on($week, '2026-09-14', 2);
check('a retrieval block met by a 3-item run is done, not missed', $b['status'], 'done');
check('and done_shape_unmet', $b['shape'], 'unmet');
contains('with the count in the reason', (string) $b['shape_reason'], 'got a practice run of 3 items (retrieval_mixed)');
$b = block_on($week, '2026-09-14', 3);
check('a teach block with an evidenced update is met', [$b['status'], $b['shape']], ['done', 'met']);
$b = block_on($week, '2026-09-14', 5);
check('a teach block whose session moved nothing is unmet', [$b['status'], $b['shape']], ['done', 'unmet']);
$b = block_on($week, '2026-09-14', 7);
check('a coding block with 20 of 60 minutes is unmet', [$b['status'], $b['shape']], ['done', 'unmet']);
contains('and the reason says the minutes wanted', (string) $b['shape_reason'], 'expected a recorded duration of at least half the block; got a session with no topic updates, 20 min recorded');
$b = block_on($week, '2026-09-15', 12);
check('a practise block with updates on two topics is met', [$b['status'], $b['shape']], ['done', 'met']);
$b = block_on($week, '2026-09-15', 16);
check('a timed block met by an attempt is met', [$b['status'], $b['shape']], ['done', 'met']);
$b = block_on($week, '2026-09-16', 20);
check('a kind with no rule is done and met', [$b['status'], $b['shape']], ['done', 'met']);
check('and says it has no rule', $b['shape_rule'], false);
contains('naming the kind', (string) $b['shape_reason'], "no shape rule for kind 'spanish'");
$b = block_on($week, '2026-09-18', 33);
check('a consolidate block with consolidates is met', [$b['status'], $b['shape']], ['done', 'met']);
$b = block_on($week, '2026-09-17', 22);
check('a block with no record at all is still missed', $b['status'], 'missed');
check('the week counts every done block as done, shape or not', $week['counts']['done'], 9);
check('and counts the unmet ones beside it', $week['counts']['shape_unmet'], 4);
check('and no block became missed for its shape', $week['counts']['missed'], 1);
check('hours include the block done in the wrong shape', $week['hours_by_subject']['maths'] >= 3.0, true);

$body = call($store, 'tracker_week_report', ['week' => '2026-W38']);
contains('the week report names the done-but-unmet blocks apart', $body,
    "DONE, BUT NOT THE SHAPE THE BLOCK ASKED FOR (done_shape_unmet — still counted as done)\n- Mon 09:00 Retrieval warm-up (mixed) (retrieval) — expected at least 5 items attempted");
contains('including the consolidation', $body, 'Fri 09:15 Maths — consolidate + fix errors (consolidate) — expected a consolidates list');
contains('the headline still counts them as done', $body, 'Study blocks 9 of 10 done (1 short, 4 not in shape), 1 missed');
contains('the block line says done_shape_unmet', $body, '#28  09:15-10:30  Maths — consolidate + fix errors             [maths]  done_shape_unmet — expected');
contains('and the kinds with no rule are named so one can be added', $body,
    'kinds with no shape rule, judged as always met: spanish. Add a row to block_kind_rules');
$page = week_report_sections($store, $store->weekSnapshot('2026-09-14'), null, [], '2026-W38');
contains('the week page lists them under their own heading', $page, '<p class="sublab">Done, but not the shape the block asked for</p>');
contains('with the reason', $page, '<b>Retrieval warm-up (mixed)</b> — expected at least 5 items attempted');
$strip = render_timetable_section($store, '2026-09-14', false);
contains('the board marks it as done, but not in shape, in words', $strip,
    'aria-label="Maths — consolidate + fix errors 09:15 — done, but not the shape the block asked for: expected a consolidates list');
contains('with its own caption', $strip, '<span class="shapecap">shape</span>');
lacks('and never as missed', $strip, 'aria-label="Maths — consolidate + fix errors 09:15 — missed"');

// Config, not code: loosen the retrieval rule and the same run is met.
$store->setBlockKindRule(['kind' => 'retrieval', 'satisfied_by' => 'retrieval_practice',
    'shape' => [['min_items' => 3]], 'expects' => 'at least 3 items attempted']);
$b = block_on($store->judgeWeek('2026-09-14'), '2026-09-14', 2);
check('changing a block_kind_rules row changes the judging with no code change', $b['shape'], 'met');
$store->setBlockKindRule(['kind' => 'retrieval', 'satisfied_by' => 'retrieval_practice',
    'shape' => [['min_items' => 5], ['min_updates' => 3]],
    'expects' => 'at least 5 items attempted, or a session with 3 or more topic updates']);
$b = block_on($store->judgeWeek('2026-09-14'), '2026-09-14', 2);
check('and back again', $b['shape'], 'unmet');
$store->setBlockKindRule(['kind' => 'spanish', 'satisfied_by' => 'any',
    'shape' => [['min_duration_minutes' => 10]], 'expects' => 'ten minutes recorded']);
$b = block_on($store->judgeWeek('2026-09-14'), '2026-09-16', 20);
check('adding a row for a kind starts judging it', [$b['shape'], $b['shape_rule']], ['met', true]);
$store->setBlockKindRule(['kind' => 'coding', 'satisfied_by' => 'any',
    'shape' => [['min_duration_fractoin' => 0.5]], 'expects' => 'a typo']);
$b = block_on($store->judgeWeek('2026-09-14'), '2026-09-14', 7);
contains('a rule with a condition the code does not know surfaces on the board', (string) $b['shape_reason'], 'expected a typo');
$store->setBlockKindRule(['kind' => 'coding', 'satisfied_by' => 'any',
    'shape' => [['min_duration_fraction' => 0.5]], 'expects' => 'a recorded duration of at least half the block']);

// A consolidation that re-works a recent demotion is met without a list.
call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-25',
    'summary' => 'Re-worked the expansion sign error from the 15th.', 'block_key' => 28, 'duration_minutes' => 60,
    'updates' => [['ref' => 'A4', 'status' => 'developing', 'evidence' => 'all four terms written out, 5/5']]]);
$b = block_on($store->judgeWeek('2026-09-21'), '2026-09-25', 28);
check('a consolidation updating a topic demoted in the prior 21 days is met', [$b['status'], $b['shape']], ['done', 'met']);

echo "\n== C. retrieval scheduling ==\n";
// Two wrong answers on consecutive days.
foreach (['2026-09-16', '2026-09-17'] as $i => $day) {
    call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [[
        'client_run_id' => "scaf-$i", 'source' => 'retrieval_warmup', 'label' => 'Starter',
        'played_at' => "{$day}T08:10:00Z", 'attempted' => 1, 'correct' => 0, 'incorrect' => 1,
        'items' => [['item_key' => 'q-hcf-84-360', 'topic_ref' => 'N4', 'prompt' => 'HCF of 84 and 360',
                     'outcome' => 'incorrect']]]]]);
}
$st = state($store, 'maths', 'item', 'q-hcf-84-360');
check('two incorrects on consecutive days set needs_scaffold', (int) $st['needs_scaffold'], 1);
check('with the streak and the level floored', [(int) $st['consecutive_wrong'], (int) $st['difficulty_level']], [2, 1]);
check('and next_due the day after the second', $st['next_due'], '2026-09-18');
check('the topic grain moved with it', (int) state($store, 'maths', 'topic', 'N4')['needs_scaffold'], 1);
$due = retrieval_due($store, ['maths'], 4);
check('and the scaffolded item leads tracker_retrieval_due', $due['entries'][0]['key'] ?? null, 'q-hcf-84-360');
check('flagged', $due['entries'][0]['needs_scaffold'] ?? null, true);
contains('with a why a caller can quote', (string) ($due['entries'][0]['why'] ?? ''), 'failed twice, 16 Sep and 17 Sep; needs a scaffold');
check('and its prompt', $due['entries'][0]['prompt'] ?? null, 'HCF of 84 and 360');

// Four correct answers: +1, +3, +7, +14.
$want = ['2026-09-02', '2026-09-05', '2026-09-10', '2026-09-18'];
foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $i => $day) {
    call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [[
        'client_run_id' => "lin-$i", 'source' => 'retrieval_warmup', 'label' => 'Starter',
        'played_at' => "{$day}T08:10:00Z", 'attempted' => 1, 'correct' => 1, 'incorrect' => 0,
        'items' => [['item_key' => 'q-lin-5x', 'topic_ref' => 'A17', 'outcome' => 'correct']]]]]);
    $st = state($store, 'maths', 'item', 'q-lin-5x');
    check('correct answer ' . ($i + 1) . ' schedules next_due at ' . $want[$i], $st['next_due'], $want[$i]);
}
check('four straight at level 3 retires the item', [(int) $st['difficulty_level'], (int) $st['retired']], [3, 1]);
$keys = array_column(retrieval_due($store, ['maths'], 20)['entries'], 'key');
check('a retired item is left out by default', in_array('q-lin-5x', $keys, true), false);
$keys = array_column(retrieval_due($store, ['maths'], 20, true)['entries'], 'key');
check('and included on request', in_array('q-lin-5x', $keys, true), true);
// A duplicate report schedules nothing twice.
call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [[
    'client_run_id' => 'lin-3', 'source' => 'retrieval_warmup', 'label' => 'Starter',
    'played_at' => '2026-09-04T08:10:00Z', 'attempted' => 1, 'correct' => 1, 'incorrect' => 0,
    'items' => [['item_key' => 'q-lin-5x', 'topic_ref' => 'A17', 'outcome' => 'correct']]]]]);
check('a replayed client_run_id advances nothing', (int) state($store, 'maths', 'item', 'q-lin-5x')['consecutive_correct'], 4);

// The ladder is a config row.
$store->setRetrievalIntervals('maths', [1, 2, 4], 'compressed for the final phase');
call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [[
    'client_run_id' => 'lin-5', 'source' => 'retrieval_warmup', 'label' => 'Starter',
    'played_at' => '2026-09-19T08:10:00Z', 'attempted' => 1, 'correct' => 1, 'incorrect' => 0,
    'items' => [['item_key' => 'q-lin-5x', 'topic_ref' => 'A17', 'outcome' => 'correct']]]]]);
check('compressing the subject\'s ladder changes the next next_due', state($store, 'maths', 'item', 'q-lin-5x')['next_due'], '2026-09-23');
check('without touching any other subject', $store->retrievalIntervals('english-literature'), [1, 3, 7, 14, 30, 60]);
$store->db->exec("DELETE FROM retrieval_config WHERE subject_slug = 'maths'");
check('and removing the row falls back to the default', $store->retrievalIntervals('maths'), [1, 3, 7, 14, 30, 60]);

// A retry holds the streaks.
call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [[
    'client_run_id' => 'retry-1', 'source' => 'retrieval_subject', 'label' => 'Maths retrieval',
    'played_at' => '2026-09-20T08:10:00Z', 'attempted' => 1, 'correct' => 0, 'correct_after_retry' => 1, 'incorrect' => 0,
    'items' => [['item_key' => 'q-lin-5x', 'topic_ref' => 'A17', 'outcome' => 'retry']]]]]);
$st = state($store, 'maths', 'item', 'q-lin-5x');
check('a retry schedules +3 days and holds the streak', [$st['next_due'], (int) $st['consecutive_correct']], ['2026-09-23', 5]);

// Sessions feed the topic grain.
$st = state($store, 'maths', 'topic', 'A4');
$hist = json_decode((string) $st['history'], true);
check('a demotion in a session counts as incorrect at topic grain', $hist[0] ?? null, ['d' => '2026-09-15', 'o' => 'incorrect']);
check('and the later promotion as correct', $hist[1] ?? null, ['d' => '2026-09-25', 'o' => 'correct']);
call($store, 'tracker_log_session', ['subject' => 'english-literature', 'date' => '2026-09-21',
    'summary' => 'Starter: K1 paragraph attempted, stapled ideas again.',
    'updates' => [['ref' => 'K1', 'evidence' => 'four ideas, no line of argument; narrowing instructed',
                   'retrieval_outcome' => 'incorrect']]]);
$st = state($store, 'english-literature', 'topic', 'K1');
check('an explicit retrieval_outcome on an unchanged status is applied', [(int) $st['consecutive_wrong'], $st['next_due']], [1, '2026-09-22']);
check('a status-unchanged update without one schedules nothing', state($store, 'maths', 'topic', 'P8'), null);

// Four subjects, twelve slots.
$due = retrieval_due($store, $mixed, 12);
$entries = $due['entries'];
check('twelve entries come back', count($entries), 12);
$adjacentSubject = false;
$adjacentTopic   = false;
for ($i = 1; $i < count($entries); $i++) {
    if ($entries[$i]['subject'] === $entries[$i - 1]['subject']) {
        $adjacentSubject = true;
    }
    if ($entries[$i]['topic_ref'] !== null && $entries[$i]['topic_ref'] === $entries[$i - 1]['topic_ref']) {
        $adjacentTopic = true;
    }
}
check('no two adjacent entries share a subject', $adjacentSubject, false);
check('and no two adjacent entries share a topic', $adjacentTopic, false);
$per = array_count_values(array_column($entries, 'subject'));
check('every subject is represented at least twice',
    min($per['maths'] ?? 0, $per['english-literature'] ?? 0, $per['english-language'] ?? 0, $per['computer-science'] ?? 0) >= 2, true);
check('the most unstable subject gets the spare slots', array_keys($per, max($per))[0], 'maths');
$lit = array_values(array_filter($entries, static fn($e) => $e['subject'] === 'english-literature'));
check('a subject with no item_key anywhere still gets topic-grain entries',
    $lit && count(array_filter($lit, static fn($e) => $e['grain'] === 'topic')) === count($lit), true);
check('the list is the same on a second sitting', retrieval_due($store, $mixed, 12)['entries'], $entries);

$body = call($store, 'tracker_retrieval_due', ['subjects' => $mixed, 'limit' => 12]);
contains('the tool prints the order', $body, "Ask in this order:\n 1. [");
check('and the JSON block matches the store', json_block($body, 'Ask in this order'), $entries);
contains('and names the slots per subject', $body, 'Slots: maths ');
$body = call($store, 'tracker_retrieval_due', ['block_key' => 2, 'limit' => 6]);
contains('block_key alone takes the subjects from the block', $body, '**Retrieval due — maths, english-literature, english-language, computer-science** · block 2 Retrieval warm-up (mixed)');
$body = call($store, 'tracker_retrieval_due', []);
contains('no subjects and no block is refused with what to send', $body, 'REFUSED: subjects is required');

echo "\n== the board ==\n";
$page = render_subject($store, $store->getSubject('english-literature'));
contains('the subject page badges a session that left work unfinished', $page, 'unfinished · closed</span>');
contains('and links the closure', $page, "closed 2026-09-21 by session $e3: completed in session $e3");
$mathsPage = render_subject($store, $store->getSubject('maths'));
lacks('a subject with nothing open has no unfinished card', $mathsPage, '<p class="label">Unfinished work</p>');
$open = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-21',
    'summary' => 'Tree diagrams started; the second example not finished.', 'unfinished' => 'second tree diagram example']));
$mathsPage = render_subject($store, $store->getSubject('maths'));
contains('an open item puts a card in the subject header', $mathsPage, '<p class="label">Unfinished work</p><p class="big mono">1 <span class="unfin">open</span></p>');
contains('naming the item', $mathsPage, 'second tree diagram example');
$sessionPage = render_session($store, $store->getSubject('english-literature'), $store->getSession('english-literature', $e3));
contains('the session page lists what a session resolved', $sessionPage, "<h2>Resolved</h2><ul><li><a href=\"/s/english-literature/session/$e1\">Session $e1</a>");
call($store, 'tracker_amend_session', ['subject' => 'maths', 'session_id' => $open, 'unfinished' => null]);

echo "\n== back-compat ==\n";
$r = call($store, 'tracker_log_session', ['subject' => 'spanish', 'date' => '2026-09-21',
    'summary' => 'An old caller, sending nothing new.']);
contains('an old caller still logs a session', $r, 'logged for GCSE Spanish');
lacks('and sees no warning when nothing is open', $r, 'warning:');

echo "\n";
if ($failures === 0) {
    echo "CONTINUITY PASS — $checks checks\n";
    exit(0);
}
echo "CONTINUITY FAIL — $failures of $checks checks failed\n";
exit(1);
