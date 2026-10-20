<?php
/**
 * Acceptance tests for the vocabulary app: the sets and their two tools,
 * a game's result taken in as a practice run through the same validation
 * tracker_log_practice applies, the block it ticks, the stars and the
 * per-topic steps, the parent's mark-off through tracker_log_session, and
 * the page. Against a frozen clock, like the continuity tests.
 *
 *   php deploy/vocab-test.php
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
require_once __DIR__ . '/../php/lib/parent.php';
require_once __DIR__ . '/../php/lib/dashboard.php';
require_once __DIR__ . '/../php/lib/vocab.php';
require_once __DIR__ . '/../php/lib/dashboard_vocab.php';

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

function call(Store $store, string $name, array $args): string
{
    try {
        $res = mcp_call_tool($store, $name, $args);
    } catch (McpError $e) {
        return $e->getMessage();
    }
    return $res['content'][0]['text'] ?? '';
}

// 2026-09-16 is a Wednesday; 09:50 is inside the 09:45–10:00 Spanish block.
putenv('TRACKER_NOW=2026-09-16 09:50');

$dbPath = sys_get_temp_dir() . '/vocab-test-' . getmypid() . '.db';
@unlink($dbPath);
$store = new Store($dbPath);
register_shutdown_function(static function () use ($dbPath) {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
});

echo "== 1. schema and seed ==\n";
check('a fresh database reaches schema 17', $store->meta('schema_version'), '17');
$tables = array_column($store->db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(), 'name');
check('table vocab_sets exists', in_array('vocab_sets', $tables, true), true);

vocab_seed_if_empty($store);
check('nothing is seeded while the subject does not exist', vocab_list_sets($store, 'spanish'), []);
check('and the seed marker is not set either', $store->meta('vocab_seeded_spanish'), null);

call($store, 'tracker_create_subject', [
    'slug' => 'spanish', 'name' => 'GCSE Spanish', 'spec_code' => 'AQA 8692',
    'strands' => ['T' => 'Themes', 'G' => 'Grammar'],
    'topics'  => [
        ['ref' => 'T04', 'name' => 'Free-time activities', 'strand' => 'T', 'status' => 'developing'],
        ['ref' => 'T10', 'name' => 'Core high-frequency vocabulary', 'strand' => 'T'],
        ['ref' => 'G21', 'name' => 'Present tense regular', 'strand' => 'G'],
        ['ref' => 'G22', 'name' => 'Present stem-change clusters', 'strand' => 'G'],
        ['ref' => 'G23', 'name' => 'Present irregular verbs', 'strand' => 'G'],
        ['ref' => 'G26', 'name' => 'Gustar and interesar verbs', 'strand' => 'G'],
        ['ref' => 'G29', 'name' => 'Preterite tense', 'strand' => 'G', 'status' => 'secure'],
    ],
]);
vocab_seed_if_empty($store);
$sets = vocab_list_sets($store, 'spanish');
check('the six sets of the app are seeded once the subject exists', count($sets), 6);
check('numbered as the app numbered them', array_column($sets, 'number'), [1, 2, 3, 4, 5, 6]);
check('word for word', $sets[2]['words'][8]['word'] ?? null, 'colgar fotos en las redes sociales');
check('each tagged with the topics it teaches', $sets[0]['topic_refs'], ['T04', 'G22']);
check('the seed marker is set', $store->meta('vocab_seeded_spanish') !== null, true);
vocab_delete_set($store, 'spanish', 6);
vocab_seed_if_empty($store);
check('a deleted set does not come back on the next request', count(vocab_list_sets($store, 'spanish')), 5);

echo "\n== 2. the tools ==\n";
$body = call($store, 'tracker_list_vocab_sets', ['subject' => 'spanish']);
contains('tracker_list_vocab_sets tables the sets', $body, '| 1 | Sports - Juego al | 10 | T04 G22 |');
contains('and lists the words', $body, '- juego al — I play');
contains('and names the app', $body, '/s/spanish/play');
contains('and says where to append', $body, 'Append new sets after number 5');
$body = call($store, 'tracker_list_vocab_sets', ['subject' => 'spanish', 'words' => false]);
lacks('words: false leaves the word lists out', $body, '- juego al');
$body = call($store, 'tracker_list_vocab_sets', ['subject' => 'nope']);
contains('an unknown subject is reported', $body, 'No subject "nope"');

$body = call($store, 'tracker_save_vocab_sets', ['subject' => 'spanish', 'sets' => [
    ['number' => 6, 'name' => 'Hablar - Past Tense', 'topic_refs' => ['G29'],
     'words' => [['spanish' => 'hablé', 'english' => 'I spoke'], ['spanish' => 'hablaste', 'english' => 'you spoke', 'pronunciation' => 'ab-LAS-tay']]],
    ['number' => 7, 'name' => 'Family', 'topic_refs' => ['T04'], 'words' => [['word' => 'el marido', 'english' => 'husband']]],
]]);
contains('tracker_save_vocab_sets saves by number', $body, 'saved 2 (numbers 6, 7)');
$six = vocab_get_set($store, 'spanish', 6);
check('the app\'s own word shape (spanish:) is accepted as word', $six['words'][0]['word'] ?? null, 'hablé');
check('and a pronunciation is kept', $six['words'][1]['pronunciation'] ?? null, 'ab-LAS-tay');

$body = call($store, 'tracker_save_vocab_sets', ['subject' => 'spanish', 'sets' => [
    ['number' => 8, 'name' => 'Bad ref', 'topic_refs' => ['ZZ9'], 'words' => [['word' => 'x', 'english' => 'y']]],
]]);
contains('a topic ref the subject does not have is refused', $body, 'names topic "ZZ9"');
check('and nothing was written', vocab_get_set($store, 'spanish', 8), null);
$body = call($store, 'tracker_save_vocab_sets', ['subject' => 'spanish', 'sets' => [
    ['number' => 8, 'name' => 'Good', 'words' => [['word' => 'x', 'english' => 'y']]],
    ['number' => 9, 'name' => 'Empty', 'words' => []],
]]);
contains('a bad set in a batch saves none of it', $body, 'set 2 needs 1 to');
check('so the good set beside it was not written either', vocab_get_set($store, 'spanish', 8), null);
$body = call($store, 'tracker_save_vocab_sets', ['subject' => 'spanish', 'sets' => [
    ['number' => 8, 'name' => 'No refs', 'words' => [['word' => 'x', 'english' => 'y']]],
]]);
contains('a set without topic refs is saved with a note', $body, 'no topic_refs');
$body = call($store, 'tracker_save_vocab_sets', ['subject' => 'spanish', 'sets' => [['number' => 8, 'delete' => true], ['number' => 7, 'delete' => true]]]);
contains('delete removes sets', $body, 'deleted 8, 7');
check('leaving the six', count(vocab_list_sets($store, 'spanish')), 6);

echo "\n== 3. a game's result ==\n";
call($store, 'tracker_set_timetable', ['valid_from' => '2026-09-14', 'note' => 'vocab fixture', 'blocks' => [
    ['block_key' => 20, 'weekday' => 3, 'start' => '09:45', 'end' => '10:00', 'kind' => 'spanish',
        'label' => 'Spanish — vocab + listening', 'subjects' => ['spanish'], 'tracking' => 'evidence'],
]]);
$subject = $store->getSubject('spanish');

$items = [];
foreach (['juego al', 'juegas al', 'juega al', 'criquet', 'baloncesto', 'fútbol', 'rugby', 'tenis', 'netball'] as $w) {
    $items[] = ['word' => $w, 'set' => 1, 'outcome' => 'correct'];
}
$items[] = ['word' => 'tenis de mesa', 'set' => 1, 'outcome' => 'incorrect'];
$game = ['game' => 'gallery', 'set' => 1, 'client_run_id' => 'web-test-0001', 'played_at' => '2026-09-16T08:48:00Z',
         'duration_seconds' => 95, 'top_speed' => 6.4, 'items' => $items];
[$status, $out] = vocab_take_run($store, $subject, $game);
check('a finished gallery game is accepted', $status, 200);
check('and stored as a practice run', $out['status'] ?? null, 'stored');
check('with one item per word shown', $out['attempted'] ?? null, 10);
check('and the right-first-time count', $out['correct'] ?? null, 9);
check('played inside the Spanish block, it counts as the slot', $out['block']['label'] ?? null, 'Spanish — vocab + listening');
contains('and the reply says so in words', (string) $out['message'], "counts as today's Spanish — vocab + listening slot");
$runId = (int) $out['run_id'];

$run = $store->getPracticeRun('spanish', $runId);
check('the run logs under the app\'s registry key', $run['source'], 'spanish_gallery');
check('with the set in its label', $run['label'], 'Shooting Gallery — Set 1: Sports - Juego al');
check('and the set number in its metrics', $run['metrics']['set'] ?? null, 1);
check('and the top speed', $run['metrics']['top_speed'] ?? null, 6.4);
check('the items carry the set\'s first topic ref', $run['items'][0]['topic_ref'] ?? null, 'T04');
$keyed = $store->db->query("SELECT COUNT(*) AS n FROM retrieval_state WHERE subject_slug = 'spanish' AND grain = 'item' AND key = 'vocab:1:futbol'")->fetch()['n'] ?? 0;
check('and a stable item key for the retrieval schedule', (int) $keyed, 1);
$topics = $run['topics']; sort($topics);
check('the run-level refs are the set\'s', $topics, ['G22', 'T04']);
$sched = $store->db->query("SELECT COUNT(*) AS n FROM retrieval_state WHERE subject_slug = 'spanish'")->fetch()['n'] ?? 0;
check('the retrieval schedule advanced on the items', (int) $sched > 0, true);
$day = $store->judgeDay('2026-09-16');
check('the board shows the block done', $day['blocks'][0]['status'], 'done');
check('with the game as its evidence', $day['blocks'][0]['evidence'][0]['id'] ?? null, $runId);

[$status, $out] = vocab_take_run($store, $subject, $game);
check('the same client_run_id again is a no-op', $out['status'] ?? null, 'duplicate');
check('returning the existing run', (int) $out['run_id'], $runId);
check('with no second row', count($store->listPracticeRuns('spanish')), 1);

[$status, $out] = vocab_take_run($store, $subject, ['game' => 'darts', 'set' => 1, 'client_run_id' => 'web-test-0002', 'items' => $items]);
check('an unknown game is refused', $status, 400);
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'gallery', 'set' => 99, 'client_run_id' => 'web-test-0002', 'items' => $items]);
check('an unknown set is refused', $status, 400);
contains('naming it', (string) $out['error_description'], 'No set 99');
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'gallery', 'set' => 1, 'client_run_id' => 'x', 'items' => $items]);
check('a short client_run_id is refused', $status, 400);
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'gallery', 'set' => 1, 'client_run_id' => 'web-test-0002', 'items' => []]);
check('a game with no words shown is not a run', $status, 400);
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'gallery', 'set' => 1, 'client_run_id' => 'web-test-0002',
    'items' => [['word' => 'x', 'outcome' => 'maybe']]]);
check('an unknown outcome is refused', $status, 400);
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'gallery', 'set' => 1, 'client_run_id' => 'web-test-0003',
    'played_at' => '2020-01-01T00:00:00Z', 'items' => [['word' => 'rugby', 'set' => 1, 'outcome' => 'correct']]]);
check('a played_at far in the past is replaced by now', $status, 200);
$r3 = $store->getPracticeRun('spanish', (int) $out['run_id']);
check('so a wrong clock cannot file a game under a judged day', substr((string) $r3['played_at'], 0, 10), '2026-09-16');
check('and a one-word game is stored but, below the minimum, earns nothing', vocab_steps($store, $subject, vocab_list_sets($store, 'spanish'))['sets'][1]['counted'], 1);

// Flashcards: her own judgement per card.
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'flashcards', 'set' => 5, 'client_run_id' => 'web-test-0004',
    'items' => [['word' => 'hablo', 'set' => 5, 'outcome' => 'correct'], ['word' => 'hablas', 'set' => 5, 'outcome' => 'incorrect'],
                ['word' => 'habla', 'set' => 5, 'outcome' => 'correct']]]);
check('a flashcards round logs under its own key', $store->getPracticeRun('spanish', (int) $out['run_id'])['source'], 'spanish_flashcards');
$src = $store->practiceSource('spanish_flashcards');
check('and the source carries the set metric in its schema', json_decode((string) $src['metrics_schema'], true)['set'] ?? null, 'number');

echo "\n== 4. stars and steps ==\n";
$steps = vocab_steps($store, $subject, vocab_list_sets($store, 'spanish'));
check('one game at 90% is two stars', $steps['sets'][1]['stars'], 2);
check('its best is kept', (int) round($steps['sets'][1]['best'] * 100), 90);
check('an unplayed set has none', $steps['sets'][2]['stars'], 0);
check('a three-card flashcards round is below the minimum and earns nothing', $steps['sets'][5]['stars'], 0);
$t04 = null;
foreach ($steps['topics'] as $t) {
    if ($t['ref'] === 'T04') {
        $t04 = $t;
    }
}
check('T04, developing with one good run, has no move yet', $t04['suggest'], null);
contains('and says what it needs', (string) $t04['why'], 'needs a second 80%+ game at least 3 days later');
$g22 = null;
foreach ($steps['topics'] as $t) {
    if ($t['ref'] === 'G22') {
        $g22 = $t;
    }
}
check('G22, not started, is offered developing on a first good run', $g22['suggest'] ?? null, 'developing');
contains('with the evidence written for the record', (string) $g22['evidence'], 'Set 1 (Sports - Juego al) 9/10 (90%) on 2026-09-16');
contains('and honest about what a game shows', (string) $g22['evidence'], 'production not yet shown');

// A second good game two days later: recency, not retention.
putenv('TRACKER_NOW=2026-09-18 16:00');
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'gallery', 'set' => 1, 'client_run_id' => 'web-test-0005',
    'items' => array_merge(array_slice($items, 0, 9), [['word' => 'tenis de mesa', 'set' => 1, 'outcome' => 'correct']])]);
check('a game outside any block is stored as an extra', $out['block'], null);
$steps = vocab_steps($store, $subject, vocab_list_sets($store, 'spanish'));
check('two good games two days apart are still two stars', $steps['sets'][1]['stars'], 2);

// A third, three days after the first: the bar.
putenv('TRACKER_NOW=2026-09-19 16:00');
[$status, $out] = vocab_take_run($store, $subject, ['game' => 'gallery', 'set' => 1, 'client_run_id' => 'web-test-0006', 'items' => $items]);
$steps = vocab_steps($store, $subject, vocab_list_sets($store, 'spanish'));
check('a good game three days after the first is the third star', $steps['sets'][1]['stars'], 3);
foreach ($steps['topics'] as $t) {
    if ($t['ref'] === 'T04') {
        $t04 = $t;
    }
}
check('and T04, developing, is now offered secure', $t04['suggest'] ?? null, 'secure');
contains('quoting both runs and the gap', (string) $t04['evidence'], '9/10 (90%) on 2026-09-16 and 9/10 (90%) on 2026-09-19, 3 days apart');
foreach ($steps['topics'] as $t) {
    if ($t['ref'] === 'G29') {
        contains('a secure topic is never moved by a game', (string) $t['why'], 'needs production in a slot');
        check('and has no suggestion', $t['suggest'], null);
    }
}

$body = call($store, 'tracker_list_vocab_sets', ['subject' => 'spanish', 'words' => false]);
contains('the tool shows the stars', $body, '★★★');
contains('and the steps', $body, 'T04 Free-time activities — Developing; sets 1, 2, 3, 4; 3 games, 3 at 80%+ → READY to mark secure');

echo "\n== 5. the parent's mark-off ==\n";
$res = vocab_mark_off($store, $subject, ['ref' => 'T04', 'status' => 'secure', 'evidence' => $t04['evidence']]);
check('marking T04 secure from the games succeeds', $res['ok'], true);
check('and the topic is secure', $store->getTopic('spanish', 'T04')['status'], 'secure');
$sessions = $store->listSessions('spanish');
check('through a session', count($sessions), 1);
contains('whose summary says where it came from', (string) $sessions[0]['summary'], "Marked off from the vocabulary app's results: T04 to secure");
$changes = $store->listChanges('spanish', 5);
contains('and whose change carries the evidence', (string) $changes[0]['evidence'], '3 days apart');
check('stamped with the session', (int) $changes[0]['session_id'], (int) $sessions[0]['id']);

$res = vocab_mark_off($store, $subject, ['ref' => 'T04', 'status' => 'secure', 'evidence' => 'already secure, this is a repeat']);
check('marking it again is refused', $res['ok'], false);
contains('saying so', $res['text'], 'already Secure');
$res = vocab_mark_off($store, $subject, ['ref' => 'G22', 'status' => 'secure', 'evidence' => 'two levels in one go is not allowed']);
check('never two levels in one step', $res['ok'], false);
$res = vocab_mark_off($store, $subject, ['ref' => 'G22', 'status' => 'examready', 'evidence' => 'games cannot do this']);
check('exam-ready is not a game\'s to give', $res['ok'], false);
$res = vocab_mark_off($store, $subject, ['ref' => 'G22', 'status' => 'developing', 'evidence' => 'short']);
check('evidence under ten characters is refused', $res['ok'], false);
$res = vocab_mark_off($store, $subject, ['ref' => 'G22', 'status' => 'developing', 'evidence' => 'Set 1 at 90% three times in the gallery; recognition only.']);
check('developing from a first good run is allowed', $res['ok'], true);
check('and recorded', $store->getTopic('spanish', 'G22')['status'], 'developing');
$steps = vocab_steps($store, $subject, vocab_list_sets($store, 'spanish'));
foreach ($steps['topics'] as $t) {
    if ($t['ref'] === 'G22') {
        check('after which the steps offer G22 secure, from the same spaced pair', $t['suggest'], 'secure');
    }
}

echo "\n== 6. the page ==\n";
$page = render_vocab_app($store, $subject, false);
contains('the page has its title', $page, '<h1>Spanish practice</h1>');
contains('and the app root', $page, 'id="vocab"');
contains('and the sets as data', $page, '"name":"Sports - Juego al"');
contains('with the stars in the data', $page, '"stars":3');
contains('and the run endpoint', $page, '"runUrl":"/s/spanish/play/run"');
contains('and the steps table', $page, '<table class="steps-sets">');
contains('and the topic steps', $page, 'Topics the sets teach');
contains('with a step ready to mark', $page, 'Ready to mark secure.');
lacks('but no mark-off form for the student', $page, 'action="/s/spanish/play/mark"');
contains('and tells her who marks it', $page, 'Dad can mark the step off');
lacks('a word with markup in it is escaped in the data block', $page, '<script>alert');
$page = render_vocab_app($store, $subject, true);
contains('the parent gets the mark-off form', $page, 'action="/s/spanish/play/mark"');
contains('with the evidence prefilled', $page, 'name="evidence"');
contains('and the CSRF token', $page, 'name="csrf"');
$page = render_vocab_app($store, $subject, true, ['ok' => true, 'text' => 'Session 9 logged for GCSE Spanish.']);
contains('a mark-off outcome is shown once', $page, 'Session 9 logged for GCSE Spanish.');
$subjectPage = render_subject($store, $subject);
contains('the subject page links to the app', $subjectPage, 'play the vocabulary app');

echo "\n== 7. the tool list ==\n";
$names = array_column(mcp_tools(), 'name');
check('tools/list advertises tracker_list_vocab_sets', in_array('tracker_list_vocab_sets', $names, true), true);
check('and tracker_save_vocab_sets', in_array('tracker_save_vocab_sets', $names, true), true);
check('58 tools in all', count($names), 58);
$n = 0;
foreach (mcp_tools() as $t) {
    if (str_contains($t['description'], 'USE WHEN')) {
        $n++;
    }
}
check('every description leads with USE WHEN', $n, 58);

echo "\n";
if ($failures === 0) {
    echo "VOCAB PASS — $checks checks\n";
    exit(0);
}
echo "VOCAB FAIL — $failures of $checks checks failed\n";
exit(1);
