<?php
/**
 * Acceptance tests for practice telemetry and the scoreboards.
 *
 *   php deploy/practice-test.php            # run them
 *   php deploy/practice-test.php --update   # rewrite the golden snapshots
 *
 * This is a second entry point into the same libraries the front controller
 * uses — it boots a throwaway database, drives the MCP tools directly rather
 * than over HTTP, and renders the boards. The smoke test covers the wire; this
 * covers the arithmetic, the rollup rule and the pinned Spanish view, which is
 * where a regression would actually hurt.
 *
 * Every check here maps to a numbered acceptance criterion in the spec, and
 * the three blocking ones are marked BLOCKING.
 */
declare(strict_types=1);

define('TRACKER', true);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// The same helper the front controller gives the libraries.
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

$UPDATE   = in_array('--update', $argv, true);
$GOLDEN   = __DIR__ . '/../tests/golden';
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
    str_contains($haystack, $needle) ? pass($what) : fail($what, "missing: $needle");
}

function lacks(string $what, string $haystack, string $needle): void
{
    str_contains($haystack, $needle) ? fail($what, "unexpectedly present: $needle") : pass($what);
}

/**
 * One golden file: written under --update, compared with a readable diff
 * otherwise. Shared by the scoreboards and the weekly report so there is one
 * mechanism, and --update stays a deliberate act whose diff is read.
 */
function golden(string $file, string $rendered): void
{
    global $UPDATE, $GOLDEN;
    $path = "$GOLDEN/$file";
    if ($UPDATE) {
        file_put_contents($path, $rendered . "\n");
        printf("  wrote %s\n", $file);
        return;
    }
    if (!is_file($path)) {
        fail("$file has a committed reference", 'no golden file; run with --update to create one');
        return;
    }
    $want = rtrim((string) file_get_contents($path), "\n");
    if ($want === $rendered) {
        pass("$file matches its committed reference exactly");
        return;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'golden');
    file_put_contents($tmp, $rendered . "\n");
    $diff = shell_exec('diff -u ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    fail("$file matches its committed reference exactly", (string) $diff);
}

/** Call a tool the way the MCP endpoint would, and return its text. */
function call(Store $store, string $name, array $args): string
{
    try {
        $res = mcp_call_tool($store, $name, $args);
    } catch (McpError $e) {
        return $e->getMessage();
    }
    return $res['content'][0]['text'] ?? '';
}

$dbPath = sys_get_temp_dir() . '/practice-test-' . getmypid() . '.db';
@unlink($dbPath);
$store = new Store($dbPath);
register_shutdown_function(static function () use ($dbPath) {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
});

echo "== schema ==\n";
$counts = $store->counts();
check('the practice tables exist and are counted by /healthz', isset($counts['practice_run']), true);
check('a fresh database reaches the current schema version', $store->meta('schema_version'), '5');
$sources = array_column($store->listPracticeSources(), 'key');
check('the source registry is seeded', $sources,
    ['maths_session', 'spanish_chat', 'spanish_flashcards', 'spanish_gallery']);

// Two subjects, seeded exactly as tracker_create_subject would.
call($store, 'tracker_create_subject', [
    'slug' => 'spanish', 'name' => 'GCSE Spanish', 'spec_code' => 'AQA 8692',
    'strands' => ['T' => 'Themes'],
    'topics'  => [
        ['ref' => 'T04', 'name' => 'Free time activities', 'strand' => 'T', 'status' => 'developing'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'maths', 'name' => 'GCSE Mathematics', 'spec_code' => 'AQA 8300',
    'strands' => ['G' => 'Geometry'],
    'topics'  => [
        ['ref' => 'G14', 'name' => 'Circle theorems', 'strand' => 'G', 'status' => 'developing'],
        ['ref' => 'G15', 'name' => 'Circle geometry proofs', 'strand' => 'G', 'status' => 'gap'],
        ['ref' => 'G16', 'name' => 'Arcs and sectors', 'strand' => 'G', 'status' => 'secure'],
    ],
]);

// The Spanish scoreboard is seeded by the migration for a subject that exists
// at migration time; this database gained the subject afterwards, so it falls
// back to the same configuration held in code. Store it so the stored path is
// what the snapshot exercises.
$store->setScoreboard('spanish', SPANISH_SCOREBOARD, 'Seeded for the test fixture.');
$store->setScoreboard('maths', MATHS_SCOREBOARD, 'Seeded for the test fixture.');

// ---- fixtures -----------------------------------------------------------
// Spanish: the real figures from the spec. Pooled accuracy 142/185 = 76.8%,
// while the mean of the four per-run percentages is 77.2% — the board must
// show the pooled one.
$spanishRuns = [
    ['client_run_id' => 'sp-1', 'source' => 'spanish_gallery', 'label' => 'Set 1 Sports',
     'played_at' => '2026-08-13T18:00:00Z', 'attempted' => 69, 'correct' => 49, 'incorrect' => 20,
     'metrics' => ['top_speed' => 8.3]],
    ['client_run_id' => 'sp-2', 'source' => 'spanish_gallery', 'label' => 'Set 1 Sports',
     'played_at' => '2026-08-18T17:00:00Z', 'attempted' => 30, 'correct' => 24, 'incorrect' => 6,
     'metrics' => ['top_speed' => 8.1]],
    ['client_run_id' => 'sp-3', 'source' => 'spanish_gallery', 'label' => 'Set 1 Sports',
     'played_at' => '2026-08-18T18:00:00Z', 'attempted' => 69, 'correct' => 56, 'incorrect' => 13,
     'metrics' => ['top_speed' => 8.4]],
    ['client_run_id' => 'sp-4', 'source' => 'spanish_gallery', 'label' => 'Set 1 Sports',
     'played_at' => '2026-09-04T09:00:00Z', 'attempted' => 17, 'correct' => 13, 'incorrect' => 4,
     'metrics' => ['top_speed' => 7.2]],
];

echo "\n== idempotency (acceptance 2, BLOCKING) ==\n";
$body = call($store, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => array_slice($spanishRuns, 0, 3)]);
contains('three runs report as stored', $body, '3 stored, 0 already recorded');
check('three runs create three rows', count($store->listPracticeRuns('spanish')), 3);

$body = call($store, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => array_slice($spanishRuns, 0, 3)]);
contains('replaying the identical call stores nothing further', $body, '0 stored, 3 already recorded');
contains('and reports each run as a duplicate', $body, 'duplicate — already recorded');
check('the row count is unchanged by the replay', count($store->listPracticeRuns('spanish')), 3);

call($store, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => [$spanishRuns[3]]]);
check('the fourth run stores', count($store->listPracticeRuns('spanish')), 4);

echo "\n== the arithmetic (acceptance 3, BLOCKING) ==\n";
$body = call($store, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => [
    ['client_run_id' => 'bad-1', 'source' => 'spanish_gallery', 'label' => 'Does not add up',
     'attempted' => 12, 'correct' => 7, 'correct_after_retry' => 3, 'incorrect' => 1],
]]);
contains('a run that does not add up is refused', $body, 'does not add up');
contains('and the message names the discrepancy', $body, 'out by 1');
check('nothing was stored by the refused call', count($store->listPracticeRuns('spanish')), 4);

echo "\n== empty and odd runs (acceptance 4, 5) ==\n";
$body = call($store, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => [
    ['client_run_id' => 'sp-empty', 'source' => 'spanish_gallery', 'label' => 'Abandoned at the title screen',
     'played_at' => '2026-09-04T10:00:00Z', 'attempted' => 0, 'correct' => 0, 'incorrect' => 0],
]]);
contains('a run with nothing attempted stores', $body, '1 stored');
$empty = $store->listPracticeRuns('spanish', ['limit' => 1]);
check('and its accuracy is null rather than zero', practice_run_metric($empty[0], 'accuracy'), null);
contains('and it renders as an em dash, dividing by zero nowhere',
    practice_format(practice_run_metric($empty[0], 'accuracy'), 'percent1'), '—');
$store->voidPracticeRun('spanish', (int) $empty[0]['id'], 'Test fixture: not a real run.');

$body = call($store, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => [
    ['client_run_id' => 'sp-odd', 'source' => 'spanish_gallery', 'label' => 'Typos everywhere',
     'played_at' => '2026-09-04T11:00:00Z', 'attempted' => 2, 'correct' => 2, 'incorrect' => 0,
     'topic_refs' => ['NOPE'], 'metrics' => ['top_speed' => 6.0, 'wpm' => 40]],
]]);
contains('an unknown topic ref is flagged, not refused', $body, 'no topic with reference NOPE');
contains('an unknown metric key is flagged, not refused', $body, 'wpm on spanish_gallery');
contains('and the run still stores', $body, '1 stored');
$odd = $store->listPracticeRuns('spanish', ['limit' => 1]);
check('the undeclared metric is kept rather than dropped', $odd[0]['metrics']['wpm'], 40);
$store->voidPracticeRun('spanish', (int) $odd[0]['id'], 'Test fixture: not a real run.');

echo "\n== voiding (acceptance 6) ==\n";
$runs = $store->listPracticeRuns('spanish');
check('voided runs are excluded from the figures', count($runs), 4);
check('pooled accuracy over the four real runs is 142/185',
    round((float) practice_stat_value($runs, 'accuracy', 'pooled') * 1000) / 1000, 0.768);
$mean = practice_stat_value($runs, 'accuracy', 'mean');
check('and it is not the mean of the per-run percentages, which differs',
    round((float) $mean * 1000) / 1000, 0.772);
$body = call($store, 'tracker_list_practice', ['subject' => 'spanish']);
contains('a voided run is still listed', $body, 'Abandoned at the title screen');
contains('marked VOID', $body, 'VOID');
$body = call($store, 'tracker_practice_stats', ['subject' => 'spanish', 'days' => 3650]);
lacks('and it is absent from the statistics', $body, 'Abandoned at the title screen');
contains('the stats report the pooled accuracy', $body, '76.8%');
contains('the stats name the best run', $body, 'Best run: 56 right');
contains('the stats report the best top speed', $body, 'top_speed 8.4');

$id = (int) $store->listPracticeRuns('spanish', ['limit' => 1, 'include_void' => true])[0]['id'];
$body = call($store, 'tracker_void_practice', ['subject' => 'spanish', 'run_id' => $id, 'void_reason' => null]);
contains('voiding can be undone', $body, 'un-voided');
check('and the run counts again', count($store->listPracticeRuns('spanish')), 5);
call($store, 'tracker_void_practice', ['subject' => 'spanish', 'run_id' => $id,
    'void_reason' => 'Test fixture: not a real run.']);
check('and re-voiding removes it again', count($store->listPracticeRuns('spanish')), 4);

echo "\n== the maths worked example (acceptance 11) ==\n";
// One session, twelve questions: 7 right first time, 3 after a retry, 2 never.
// Accuracy 58.3%, solve rate 83.3%. Dated well in the past so the "this week"
// tile is deterministic: it is empty, for good, whenever this test runs.
$items = [];
foreach ([
    ['G14', 'correct', 1], ['G14', 'correct', 1], ['G14', 'retry', 2], ['G14', 'incorrect', 3],
    ['G15', 'correct', 1], ['G15', 'correct', 1], ['G15', 'correct', 1], ['G15', 'retry', 2],
    ['G16', 'correct', 1], ['G16', 'correct', 1], ['G16', 'retry', 3], ['G16', 'incorrect', 2],
] as $i => [$ref, $outcome, $taken]) {
    $items[] = ['topic_ref' => $ref, 'outcome' => $outcome, 'attempts_taken' => $taken, 'position' => $i];
}
$body = call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [
    ['client_run_id' => 'ms-1', 'source' => 'maths_session', 'label' => 'Circle theorems',
     'played_at' => '2026-06-01T10:00:00Z', 'attempted' => 12, 'correct' => 7,
     'correct_after_retry' => 3, 'incorrect' => 2, 'duration_seconds' => 2700,
     'metrics' => ['hints_used' => 4, 'topics_covered' => 3], 'items' => $items],
]]);
contains('the maths session stores', $body, '1 stored');

$mathsRuns = $store->listPracticeRuns('maths');
check('accuracy is 7/12', practice_format(practice_run_metric($mathsRuns[0], 'accuracy'), 'percent1'), '58.3%');
check('solve rate is 10/12', practice_format(practice_run_metric($mathsRuns[0], 'solve_rate'), 'percent1'), '83.3%');

$roll = $store->practiceTopicRollup('maths');
check('every topic is rolled up', array_keys($roll), ['G14', 'G15', 'G16']);
check('G14 is 2 of 4 right first time', [$roll['G14']['correct'], $roll['G14']['attempted']], [2.0, 4.0]);
check('G15 is 3 of 4', [$roll['G15']['correct'], $roll['G15']['attempted']], [3.0, 4.0]);
check('G16 is 2 of 4', [$roll['G16']['correct'], $roll['G16']['attempted']], [2.0, 4.0]);
check('G16 counts one retry', $roll['G16']['retry'], 1.0);

$body = call($store, 'tracker_practice_stats', ['subject' => 'maths', 'days' => 3650]);
$order = [];
foreach (explode("\n", $body) as $line) {
    if (preg_match('/^\| (G1[456]) /', $line, $m)) {
        $order[] = $m[1];
    }
}
// G14 and G16 both sit at 50.0%; the tie breaks on the ref ascending, so the
// order is deterministic and the snapshot below is stable.
check('the per-topic table is weakest first, ties broken on the ref', $order, ['G14', 'G16', 'G15']);
contains('G14 accuracy matches the hand calculation', $body, '| G14 | Circle theorems | 1 | 4 | 50.0% | 75.0% |');
contains('G15 accuracy matches the hand calculation', $body, '| G15 | Circle geometry proofs | 1 | 4 | 75.0% | 100.0% |');
contains('G16 accuracy matches the hand calculation', $body, '| G16 | Arcs and sectors | 1 | 4 | 50.0% | 75.0% |');
contains('the per-topic table carries the current topic status', $body, 'Developing');

echo "\n== the rollup rule (acceptance 7) ==\n";
// A run carrying BOTH per-item refs and run-level refs must roll up from the
// items only. Doing both would double the counts.
call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [
    ['client_run_id' => 'ms-both', 'source' => 'maths_session', 'label' => 'Both routes',
     'played_at' => '2026-06-02T10:00:00Z', 'attempted' => 4, 'correct' => 4, 'incorrect' => 0,
     'topic_refs' => ['G15'],
     'items' => [
         ['topic_ref' => 'G14', 'outcome' => 'correct'],
         ['topic_ref' => 'G14', 'outcome' => 'correct'],
         ['topic_ref' => 'G14', 'outcome' => 'correct'],
         ['topic_ref' => 'G14', 'outcome' => 'correct'],
     ]],
]]);
$roll = $store->practiceTopicRollup('maths');
check('items win: G14 gains the four items', $roll['G14']['attempted'], 8.0);
check('and the run-level ref on the same run adds nothing', $roll['G15']['attempted'], 4.0);

// A run with run-level refs and no items apportions its totals across them.
call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [
    ['client_run_id' => 'ms-refs', 'source' => 'maths_session', 'label' => 'Refs only',
     'played_at' => '2026-06-03T10:00:00Z', 'attempted' => 10, 'correct' => 6,
     'correct_after_retry' => 2, 'incorrect' => 2, 'topic_refs' => ['G15', 'G16']],
]]);
$roll = $store->practiceTopicRollup('maths');
check('a run with no items apportions across its refs', $roll['G15']['attempted'], 9.0);
check('evenly', $roll['G16']['attempted'], 9.0);
check('and the totals are never counted twice',
    $roll['G14']['attempted'] + $roll['G15']['attempted'] + $roll['G16']['attempted'], 26.0);

echo "\n== practice never moves a status (acceptance 10) ==\n";
$before = array_column($store->listTopics('maths'), 'status', 'ref');
call($store, 'tracker_log_practice', ['subject' => 'maths', 'runs' => [
    ['client_run_id' => 'ms-status', 'source' => 'maths_session', 'label' => 'A disastrous session',
     'played_at' => '2026-06-04T10:00:00Z', 'attempted' => 6, 'correct' => 0, 'incorrect' => 6,
     'items' => array_fill(0, 6, ['topic_ref' => 'G16', 'outcome' => 'incorrect'])],
]]);
$after = array_column($store->listTopics('maths'), 'status', 'ref');
check('every topic status is untouched', $after, $before);
check('and no topic change was recorded', count($store->listChanges('maths')), 0);

echo "\n== scoreboard configuration (acceptance 9) ==\n";
$stored = $store->getScoreboard('maths');
$bad    = $stored;
$bad['panels'][] = ['type' => 'sparkline', 'title' => 'Not a panel type'];
$bad['panels'][] = ['type' => 'stat', 'title' => 'No metric at all'];
$body = call($store, 'tracker_set_scoreboard', ['subject' => 'maths', 'config' => $bad]);
contains('an invalid panel rejects the configuration', $body, 'Rejected');
contains('naming the unknown type', $body, 'sparkline');
contains('and every other problem in the same reply', $body, 'needs a metric');
check('the stored configuration is untouched', $store->getScoreboard('maths'), $stored);

$body = call($store, 'tracker_set_scoreboard', ['subject' => 'maths', 'config' => [
    'version' => 1,
    'panels'  => [['type' => 'stat', 'title' => 'Runs', 'metric' => 'count', 'window' => 'all']],
], 'note' => 'Trimmed for the test.']);
contains('a valid configuration saves', $body, 'saved as version');
check('and it is what reads back', count($store->getScoreboard('maths')['panels']), 1);
$store->setScoreboard('maths', $stored, 'Restored after the test.');
check('the previous version is still readable, because rows are appended',
    count($store->getScoreboard('maths')['panels']), count($stored['panels']));

$body = call($store, 'tracker_set_scoreboard', ['subject' => 'maths', 'config' => [
    'version' => 1, 'panels' => [['type' => 'line', 'metric' => 'correct', 'source' => 'spanish_gallery']],
]]);
contains('a source that could never match is refused', $body, 'not a registered source');

$body = call($store, 'tracker_get_scoreboard', ['subject' => 'spanish']);
contains('the scoreboard reads back as JSON', $body, '"type": "line"');
contains('and says where the configuration came from', $body, 'Stored configuration');

// Every configuration this code ships has to survive its own validator, or a
// board could be seeded in a state tracker_set_scoreboard would refuse.
$sourceKeys = array_column($store->listPracticeSources(), 'key');
foreach (['Spanish' => SPANISH_SCOREBOARD, 'maths' => MATHS_SCOREBOARD,
          'fallback' => PRACTICE_FALLBACK_SCOREBOARD] as $name => $config) {
    check("the built-in $name configuration passes validation",
        practice_validate_scoreboard($config, $sourceKeys), []);
}

// A key the renderer does not read would be accepted and then quietly do
// nothing, which is how a board ends up not matching the config someone
// believes they wrote.
$body = call($store, 'tracker_set_scoreboard', ['subject' => 'maths', 'config' => [
    'version' => 1,
    'panels'  => [['type' => 'stat', 'title' => 'Runs', 'metric' => 'count', 'windwo' => 'all']],
]]);
contains('a misspelled panel option is refused rather than ignored', $body, 'does not understand "windwo"');
$body = call($store, 'tracker_set_scoreboard', ['subject' => 'maths', 'config' => [
    'version' => 1,
    'panels'  => [['type' => 'split', 'title' => 'Split', 'metric' => 'accuracy']],
]]);
contains('and so is an option that belongs to another panel type', $body, 'does not understand "metric"');

echo "\n== panel options ==\n";
$body = call($store, 'tracker_set_scoreboard', ['subject' => 'maths', 'config' => [
    'version' => 1,
    'panels'  => [['type' => 'topics', 'title' => 'Topics', 'metrics' => ['items', 'crumpets']]],
]]);
contains('an unknown topics column is refused', $body, 'is not one of');

$topicsPanel = practice_render_topics($store, $store->getSubject('maths'),
    ['type' => 'topics', 'title' => 'Topics', 'metrics' => ['items', 'retry', 'incorrect']], []);
contains('a topics panel renders the columns it asks for', $topicsPanel, '<th>After retry</th>');
contains('and the ones it asks for only', $topicsPanel, '<th>Not got</th>');
lacks('leaving out the ones it does not', $topicsPanel, '<th>Solve rate</th>');
contains('with the ref and name always there to identify the row', $topicsPanel, '<th>Topic</th><th>Name</th>');

$defaultPanel = practice_render_topics($store, $store->getSubject('maths'),
    ['type' => 'topics', 'title' => 'Topics'], []);
contains('and the default set is unchanged when none is asked for', $defaultPanel,
    '<th>Topic</th><th>Name</th><th>Runs</th><th>Items</th><th>Right first time</th><th>Solve rate</th><th>Status</th>');

$tile = practice_render_stat(['type' => 'stat', 'title' => 'Best score', 'metric' => 'correct',
    'window' => 'all', 'agg' => 'max', 'label' => 'since she started'], $store->listPracticeRuns('maths'));
contains('a stat label replaces the caption the window would have written', $tile, 'since she started');
lacks('so the generated one is gone', $tile, 'all time');

$store->setScoreboard('maths', $stored, 'Restored after the panel-option tests.');

echo "\n== unknown panel types are skipped, not fatal ==\n";
$subject = $store->getSubject('spanish');
$runs    = $store->listPracticeRuns('spanish');
$html    = practice_render_panels($store, $subject, [
    'version' => 1,
    'panels'  => [
        ['type' => 'jetpack', 'title' => 'From a newer version'],
        ['type' => 'stat', 'title' => 'Total games', 'metric' => 'count', 'window' => 'all'],
    ],
], $runs);
contains('a board with an unknown panel still renders the rest', $html, 'Total games');
lacks('and the unknown panel is skipped', $html, 'From a newer version');

echo "\n== rendering at 0, 1 and 25 runs (acceptance 8) ==\n";
$empty = practice_render_panels($store, $store->getSubject('maths'), SPANISH_SCOREBOARD, []);
lacks('a board with no runs draws no chart', $empty, '<svg');
contains('and says so in the table panel', $empty, 'Nothing logged yet');

$oneRun = [$runs[0]];
$one    = practice_render_panels($store, $subject, SPANISH_SCOREBOARD, $oneRun);
lacks('one run is not a trend, so no chart is drawn', $one, '<svg');
check('and the table holds exactly one row', substr_count($one, '<tr>') - substr_count($one, '<thead><tr>'), 1);

$many = [];
for ($i = 0; $i < 25; $i++) {
    $many[] = ['client_run_id' => 'bulk-' . $i, 'source' => 'spanish_gallery',
               'label' => 'Set 2 Food ' . $i, 'played_at' => sprintf('2026-07-%02dT10:00:00Z', $i + 1),
               'attempted' => 20, 'correct' => 10 + ($i % 8), 'incorrect' => 10 - ($i % 8),
               'metrics' => ['top_speed' => 5 + $i / 10]];
}
call($store, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => array_slice($many, 0, 25)]);
$bulkRuns = $store->listPracticeRuns('spanish');
check('twenty-nine runs are stored', count($bulkRuns), 29);
$board = practice_render_panels($store, $subject, SPANISH_SCOREBOARD, $bulkRuns);
check('the chart is capped at its limit of 20 points',
    substr_count($board, '<path class="star'), 20);
check('and at twenty points it labels only the record and the latest run',
    substr_count($board, 'font-size="15" font-weight="700"'), 2);
check('and the table at its limit of 20 rows',
    substr_count($board, '<tr>') - substr_count($board, '<thead><tr>') - substr_count($board, '<tr><td'), 0);
foreach ($bulkRuns as $r) {
    if (str_starts_with((string) $r['client_run_id'], 'bulk-')) {
        $store->voidPracticeRun('spanish', (int) $r['id'], 'Test fixture: bulk render check.');
    }
}
check('the bulk runs are out of the way again', count($store->listPracticeRuns('spanish')), 4);

echo "\n== the activity picker ==\n";
$picker = practice_picker($store, $subject, []);
contains('every registered activity gets a card', $picker, 'Shooting Gallery');
contains('the card carries the run count', $picker, '<span class="tnum">4</span>');
contains('and how many she got right first time', $picker, '76.8% right first time');
contains('Everything is selected when nothing is filtered', $picker,
    'href="/s/spanish/practice" aria-current="page"');
contains('an activity with nothing logged is an invitation', $picker,
    'not played yet &mdash; give it a go');
lacks('and is not a link to a guaranteed empty board', $picker, 'source=spanish_flashcards');
lacks('the dropdown is gone', $picker, '<select');

// The counts have to ignore the narrowing the board already has, or every
// card but the selected one reads zero — which is the one thing they are for.
$narrowed = practice_picker($store, $subject, ['source' => 'spanish_gallery']);
contains('narrowing to an activity leaves the other counts intact', $narrowed,
    '<span class="tnum">4</span>');
contains('and marks that card as the current one', $narrowed,
    'href="/s/spanish/practice?source=spanish_gallery" aria-current="page"');

check('a date chip is a rolling window, not a frozen date',
    practice_board_url('spanish', [], ['window' => '30d']),
    '/s/spanish/practice?window=30d');
check('picking a window clears an explicit range',
    practice_board_url('spanish', ['since' => '2026-01-01', 'until' => '2026-02-01'], ['window' => '7d']),
    '/s/spanish/practice?window=7d');
check('picking an activity keeps the window it was picked in',
    practice_board_url('spanish', ['window' => '30d', 'since' => '2026-08-05'],
        ['source' => 'spanish_gallery']),
    '/s/spanish/practice?source=spanish_gallery&window=30d');
check('and All time clears the window without losing the activity',
    practice_board_url('spanish', ['window' => '30d', 'since' => '2026-08-05',
        'source' => 'spanish_gallery'], ['window' => null]),
    '/s/spanish/practice?source=spanish_gallery');

$w = practice_filter_from_query(['window' => '7d']);
check('?window=7d resolves to a since date', $w['since'], gmdate('Y-m-d', time() - 7 * 86400));
check('and keeps the window for the chips to read', $w['window'], '7d');
check('an explicit range beats a window',
    practice_filter_from_query(['window' => '7d', 'from' => '2026-01-01'])['window'] ?? null, null);
check('a nonsense window is ignored', practice_filter_from_query(['window' => 'lol']), []);

echo "\n== the Spanish golden snapshot (acceptance 1, BLOCKING) ==\n";
$spanishHtml = practice_render_panels($store, $subject, practice_scoreboard_for($store, 'spanish'),
    $store->listPracticeRuns('spanish'));
// The chart on its own as well, because the geometry is what is pinned and a
// diff in the SVG should say so without a wall of table markup around it.
preg_match('#<svg .*?</svg>#s', $spanishHtml, $svgMatch);
$spanishSvg = $svgMatch[0] ?? '';

$mathsHtml = practice_render_panels($store, $store->getSubject('maths'),
    practice_scoreboard_for($store, 'maths'), $store->listPracticeRuns('maths'));

$snapshots = [
    'spanish-scoreboard.html' => $spanishHtml,
    'spanish-score-trend.svg' => $spanishSvg,
    'maths-scoreboard.html'   => $mathsHtml,
];
foreach ($snapshots as $file => $rendered) {
    golden($file, $rendered);
}

if (!$UPDATE) {
    // The figures the snapshot is asserting, named, so a diff says what broke.
    contains('the board shows four games', $spanishHtml, '<p class="big mono">4</p>');
    contains('the best score is 56', $spanishHtml, '<p class="big mono">56</p>');
    contains('the pooled accuracy is 76.8%, not the 77.2% mean', $spanishHtml, '<p class="big mono">76.8%</p>');
    lacks('and the mean is nowhere on the board', $spanishHtml, '77.2%');
    contains('the top speed is 8.4', $spanishHtml, '<p class="big mono">8.4</p>');
    contains('the chart plots the raw score, labelled', $spanishHtml, '>56</text>');
    contains('the chart is pinned to its viewBox', $spanishHtml, 'viewBox="0 0 600 232"');
    contains('the line is the pinned purple', $spanishHtml, 'stroke="#7c3aed" stroke-width="3"');
    contains('the best score sits on the top of the plot at y=60', $spanishHtml,
        '<line class="record" x1="34" y1="60"');
    contains('every run is a star', $spanishHtml, '<path class="star"');
    contains('and the best one is gold', $spanishHtml, '<path class="star best"');
    contains('the record line says what to beat', $spanishHtml, '>56 TO BEAT</text>');
    contains('the run dates label the axis', $spanishHtml, '>4 Sep</text>');
    contains('and the record is annotated', $spanishHtml, '>best yet!</text>');
    // Two charts on one page would collide on a shared gradient id, so each
    // chart derives its own from its title.
    preg_match('/id="(c[0-9a-f]{8})w"/', $spanishHtml, $gid);
    lacks('each chart owns its gradient ids', $mathsHtml, $gid[1] ?? 'no-id-found');
    contains('every chart carries a title', $spanishHtml, '<title>Score trend');
    contains('and the same numbers appear as a table', $spanishHtml, 'Chart data');
    contains('the maths board draws its split bar', $mathsHtml, 'How questions went');
    contains('the maths accuracy line is fixed to a 0-100 scale', $mathsHtml, 'Right first time');
}

echo "\n== the weekly report golden ==\n";
// A second throwaway database, because the report has to be pinned against a
// week that cannot move. The clock is frozen with TRACKER_NOW, the note's
// timestamps and its snapshot's are set rather than taken from the wall
// clock, and no topic is left secure — a queue line that counts weeks since
// today would put the real date into a committed file.
$weekDb = sys_get_temp_dir() . '/week-report-test-' . getmypid() . '.db';
@unlink($weekDb);
register_shutdown_function(static function () use ($weekDb) {
    @unlink($weekDb);
    @unlink($weekDb . '-wal');
    @unlink($weekDb . '-shm');
});
putenv('TRACKER_NOW=2026-09-06 20:00');
$wstore = new Store($weekDb);

call($wstore, 'tracker_create_subject', [
    'slug' => 'maths', 'name' => 'GCSE Mathematics', 'spec_code' => 'AQA 8300', 'tier' => 'Higher',
    'strands' => ['A' => 'Algebra'],
    'topics'  => [
        ['ref' => 'A4', 'name' => 'Simplify, expand, factorise', 'strand' => 'A',
            'status' => 'developing', 'watch' => 'retest 2 of 2 still outstanding'],
        ['ref' => 'A17', 'name' => 'Solving linear equations', 'strand' => 'A', 'status' => 'notstarted'],
        ['ref' => 'A21', 'name' => 'Simultaneous equations', 'strand' => 'A', 'status' => 'gap'],
    ],
]);
call($wstore, 'tracker_create_subject', [
    'slug' => 'spanish', 'name' => 'GCSE Spanish', 'spec_code' => 'AQA 8692',
    'strands' => ['T' => 'Themes'],
    'topics'  => [
        ['ref' => 'T04', 'name' => 'Free time activities', 'strand' => 'T', 'status' => 'developing'],
        ['ref' => 'T05', 'name' => 'Food and drink', 'strand' => 'T', 'status' => 'gap'],
    ],
]);

// Eight blocks: six study, one movement, one review — the same three-way
// partition the real board has, small enough to read in a diff.
call($wstore, 'tracker_set_timetable', ['valid_from' => '2026-08-31', 'note' => 'golden fixture',
    'blocks' => [
        ['block_key' => 1, 'weekday' => 1, 'start' => '09:00', 'end' => '09:30', 'kind' => 'movement',
            'label' => 'Move — walk, bike or dance', 'subjects' => [], 'tracking' => 'self_report'],
        ['block_key' => 2, 'weekday' => 1, 'start' => '09:45', 'end' => '11:00', 'kind' => 'teach',
            'label' => 'Maths — new topic', 'subjects' => ['maths'], 'tracking' => 'evidence'],
        ['block_key' => 3, 'weekday' => 1, 'start' => '11:15', 'end' => '12:00', 'kind' => 'spanish',
            'label' => 'Spanish — vocab + listening', 'subjects' => ['spanish'], 'tracking' => 'evidence'],
        ['block_key' => 4, 'weekday' => 2, 'start' => '13:00', 'end' => '14:00',
            'kind' => 'timed_handwritten', 'label' => 'Timed handwritten practice',
            'subjects' => ['maths'], 'tracking' => 'evidence'],
        ['block_key' => 5, 'weekday' => 3, 'start' => '09:45', 'end' => '10:00', 'kind' => 'spanish',
            'label' => 'Spanish — vocab review', 'subjects' => ['spanish'], 'tracking' => 'evidence'],
        ['block_key' => 6, 'weekday' => 4, 'start' => '09:25', 'end' => '10:30', 'kind' => 'teach',
            'label' => 'Deep block — Maths', 'subjects' => ['maths'], 'tracking' => 'evidence'],
        ['block_key' => 7, 'weekday' => 5, 'start' => '09:45', 'end' => '11:00',
            'kind' => 'consolidate', 'label' => 'Maths — consolidate + fix errors',
            'subjects' => ['maths'], 'tracking' => 'evidence'],
        ['block_key' => 8, 'weekday' => 5, 'start' => '14:15', 'end' => '15:00', 'kind' => 'review',
            'label' => 'Weekly review with Dad', 'subjects' => [], 'tracking' => 'self_report'],
    ],
]);

// Monday held, and a gallery run after it that no block claims — an extra,
// which never offsets a miss. Tuesday's timed piece was sat; Wednesday's
// Spanish went nowhere; Thursday's deep block was missed; Friday ran 25 of 75.
call($wstore, 'tracker_tick_block', ['date' => '2026-08-31', 'block_key' => 1, 'by' => 'student']);
call($wstore, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-08-31',
    'summary' => 'Surds: intro, worked examples and an exit ticket', 'block_key' => 2,
    'duration_minutes' => 70,
    'updates' => [['ref' => 'A17', 'status' => 'developing',
        'evidence' => 'first pass, worked with support']]]);
call($wstore, 'tracker_log_session', ['subject' => 'spanish', 'date' => '2026-08-31',
    'summary' => 'Vocabulary set 1 and a short listening', 'block_key' => 3,
    'duration_minutes' => 45]);
call($wstore, 'tracker_log_attempt', ['subject' => 'maths', 'name' => 'Phase 1 exit check',
    'kind' => 'paper', 'tier' => 'H', 'date' => '2026-09-01',
    'papers' => [['code' => '8300/1H', 'score' => 22, 'max' => 25, 'blanks' => 0,
        'sat_on' => '2026-09-01']]]);
call($wstore, 'tracker_log_practice', ['subject' => 'spanish', 'runs' => [[
    'client_run_id' => 'golden-week-1', 'source' => 'spanish_gallery',
    'label' => 'Shooting Gallery', 'played_at' => '2026-08-31T15:10:00Z',
    'attempted' => 20, 'correct' => 14, 'correct_after_retry' => 3, 'incorrect' => 3,
    'duration_seconds' => 720]]]);
call($wstore, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-04',
    'summary' => 'Consolidation, cut short', 'block_key' => 7, 'duration_minutes' => 25]);
call($wstore, 'tracker_tick_block', ['date' => '2026-09-04', 'block_key' => 8, 'by' => 'parent']);
call($wstore, 'tracker_request_day_off', ['date_from' => '2026-09-07', 'date_to' => '2026-09-07',
    'reason' => "friend's birthday", 'requested_by' => 'student']);

// Movement is stamped with the wall clock, so pin it into the week it belongs
// to rather than leaving the diff to change when the file is next read.
$wstore->db->exec("UPDATE topic_changes SET changed_at = '2026-08-31 11:05:00'");

$sections = [
    'held'    => 'Monday landed whole and the Tuesday paper was sat with no blanks; A17 opened '
        . 'on worked examples.',
    'slipped' => "Thursday's deep block went missing and Wednesday's Spanish never started; "
        . 'Friday ran 25 minutes of 75.',
    'next'    => 'Tuesday opens with the Lit essay in the timed rotation; the deep block moves '
        . 'to the morning.',
    'carry_forward' => [
        'maths'   => "The Thursday deep block, and A4's second retest.",
        'spanish' => "Wednesday's slot lost to the dentist; Monday and the gallery held.",
    ],
    'rotation_next' => 'Lit essay',
];

/** The server builds the snapshot; only its clock is pinned, for the diff. */
$pinned = static function (string $at) use ($wstore): array {
    $snap = $wstore->weekSnapshot('2026-08-31');
    $snap['captured_at'] = $at;
    return $snap;
};
$wstore->addWeeklyReview(['week' => '2026-W36', 'stage' => 'draft', 'written_by' => 'routine',
    'snapshot' => $pinned('2026-09-04 12:05:00'), 'sections' => $sections,
    'note' => 'written by the Friday routine before the review']);
$reviewed = $sections;
$reviewed['decisions'] = [
    ['kind' => 'day_off', 'ref' => '1', 'decision' => 'approved',
        'note' => 'Blocks moved to Saturday morning.'],
    ['kind' => 'excusal', 'ref' => '2026-09-02#5', 'decision' => 'excused', 'note' => 'dentist'],
    ['kind' => 'excusal', 'ref' => '2026-09-03#6', 'decision' => 'not_excused', 'note' => null],
];
$wstore->addWeeklyReview(['week' => '2026-W36', 'stage' => 'reviewed', 'written_by' => 'chat',
    'snapshot' => $pinned('2026-09-04 12:52:00'), 'sections' => $reviewed, 'note' => null]);
$wstore->db->exec(
    "UPDATE weekly_reviews SET written_at = CASE version
       WHEN 1 THEN '2026-09-04 12:05:00' ELSE '2026-09-04 12:52:00' END"
);
// The excusal lands after the note was saved — which is exactly the case the
// snapshot exists for, and what puts the drift line on the page.
$wstore->setExcusal('2026-09-02', 5, 'dentist');

$weekHtml = week_report_sections(
    $wstore,
    $wstore->weekSnapshot('2026-08-31'),
    $wstore->weeklyReview('2026-W36'),
    $wstore->weeklyReviewVersions('2026-W36'),
    '2026-W36'
);
golden('week-report.html', $weekHtml);

if (!$UPDATE) {
    // The figures the snapshot is asserting, named, so a diff says what broke.
    contains('the missed block is named with its day, time and label', $weekHtml,
        '<span class="when">Thu 3 Sep · 09:25–10:30</span><br><b>Deep block — Maths</b>');
    contains('and with what was absent', $weekHtml, 'no session logged that day');
    contains('the short block is named with the minutes it actually ran', $weekHtml,
        '<b>Maths — consolidate + fix errors</b> — 25 minutes of 75 logged.');
    lacks('and is not listed as missed', $weekHtml,
        '<b>Maths — consolidate + fix errors</b> — no session logged');
    contains('the recorded decision says what Dad decided, not what the page did', $weekHtml,
        'excused — dentist');
    contains('and a refusal is marked as one', $weekHtml, '<span class="said no">not excused</span>');
    contains('hours are read against the timetable, not against a target', $weekHtml,
        '<span class="num mono">2.67 / 4.58</span>');
    contains('and the subject under its planned hours says so in words', $weekHtml,
        'under the timetable');
    contains('the movement chip carries the status either side of the move', $weekHtml,
        'Not started → Developing');
    contains('the coverage sparkline is drawn from the replay, with its figures in the title',
        $weekHtml, 'GCSE Mathematics coverage over eight weeks');
    contains('the queue top is the loose end, not a count of weeks since today', $weekHtml,
        'A4 Simplify, expand, factorise — retest 2 of 2 still outstanding');
    contains("the margin carries the week's carry-forward line for each subject", $weekHtml,
        "The Thursday deep block, and A4&#039;s second retest.");
    contains('the reviewed version is the one shown, with the draft still a link', $weekHtml,
        '/week/2026-W36?v=1');
    contains('and the drift line says what has moved since the note was written', $weekHtml,
        'Written from the record at Fri 4 Sep 13:52 (2 missed · 0 excused). '
        . 'Since then: Wed 09:45 Spanish — vocab review excused — dentist.');
}
putenv('TRACKER_NOW=');

echo "\n";
if ($failures === 0) {
    echo "PRACTICE PASS — $checks checks\n";
    exit(0);
}
echo "PRACTICE FAIL — $failures of $checks checks failed\n";
exit(1);
