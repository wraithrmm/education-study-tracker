<?php
/**
 * Acceptance tests for exam skills.
 *
 *   php deploy/exam-test.php
 *
 * Drives the MCP tools and the page renderers directly against a throwaway
 * database with the clock frozen at Wednesday 23 September 2026, 14:20.
 * Covers the schema step, the bank's refusals and idempotency, vetting,
 * scheduling, the student's page before and during the sitting, the write
 * guard and the grace, the timer closing the test, the block judged from
 * the sitting, marking into one attempt per subject, and what each of the
 * two viewers sees afterwards.
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
    str_contains($haystack, $needle) ? pass($what) : fail($what, "missing: $needle\n        in: " . substr($haystack, 0, 800));
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
        return 'REFUSED: ' . $e->getMessage();
    }
    return $res['content'][0]['text'] ?? '';
}

function ids(string $reply): array
{
    preg_match_all('/^#(\d+) /m', $reply, $m);
    return array_map('intval', $m[1]);
}

putenv('TRACKER_NOW=2026-09-23 14:20');
$dbPath = sys_get_temp_dir() . '/exam-test-' . getmypid() . '.db';
@unlink($dbPath);
$store = new Store($dbPath);
register_shutdown_function(static function () use ($dbPath) {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
});

echo "== schema ==\n";
check('a fresh database reaches schema 17', $store->meta('schema_version'), '17');
$tables = array_column($store->db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(), 'name');
foreach (['exam_questions', 'exam_tests', 'exam_test_questions', 'exam_answers'] as $t) {
    check("table $t exists", in_array($t, $tables, true), true);
}
$blocksSql = (string) $store->db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'timetable_blocks'")->fetchColumn();
contains('the block kind CHECK allows exam_practice', $blocksSql, "'exam_practice'");
$rules = $store->blockKindRules();
check('exam_practice has a shape rule', isset($rules['exam_practice']), true);
check('bound by any record', $rules['exam_practice']['satisfied_by'] ?? null, 'any');
check('met only by a sat test', $rules['exam_practice']['shape'] ?? null, [['evidence_type' => 'exam']]);
check('and requires no review', $rules['exam_practice']['review_required'] ?? null, false);
contains('the migration rebuilt the blocks table in place', $blocksSql, 'UNIQUE (version_id, block_key)');

// ---- fixture -------------------------------------------------------------
call($store, 'tracker_create_subject', [
    'slug' => 'maths', 'name' => 'GCSE Mathematics', 'tier' => 'Higher', 'strands' => ['A' => 'Algebra'],
    'topics' => [
        ['ref' => 'A17', 'name' => 'Solving linear equations', 'strand' => 'A', 'status' => 'secure'],
        ['ref' => 'A4', 'name' => 'Simplify, expand, factorise', 'strand' => 'A', 'status' => 'developing'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'english-language', 'name' => 'GCSE English Language', 'strands' => ['L' => 'Language'],
    'topics' => [['ref' => 'L2', 'name' => 'Language analysis', 'strand' => 'L', 'status' => 'developing']],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'computer-science', 'name' => 'GCSE Computer Science', 'strands' => ['C' => 'Computing'],
    'topics' => [['ref' => 'C1', 'name' => 'Binary', 'strand' => 'C', 'status' => 'secure']],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'exam-skills', 'name' => 'Exam Skills', 'strands' => ['T' => 'Timing', 'R' => 'Reading the question'],
    'topics' => [
        ['ref' => 'T2', 'name' => 'Pacing by marks per minute', 'strand' => 'T'],
        ['ref' => 'R3', 'name' => 'Show your working', 'strand' => 'R'],
    ],
]);
$tt = call($store, 'tracker_set_timetable', ['valid_from' => '2026-09-14', 'note' => 'exam fixture', 'blocks' => [
    ['block_key' => 3, 'weekday' => 1, 'start' => '09:15', 'end' => '10:30', 'kind' => 'teach',
        'label' => 'Maths — new topic', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 39, 'weekday' => 3, 'start' => '14:30', 'end' => '15:30', 'kind' => 'exam_practice',
        'label' => 'Exam practice — timed paper', 'subjects' => ['exam-skills'], 'tracking' => 'evidence'],
    ['block_key' => 20, 'weekday' => 3, 'start' => '15:30', 'end' => '15:45', 'kind' => 'spanish',
        'label' => 'Spanish — vocab', 'subjects' => ['maths'], 'tracking' => 'evidence'],
]]);

echo "\n== A. the timetable knows the kind ==\n";
contains('tracker_set_timetable accepts exam_practice', $tt, '3 blocks');
$reply = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-23', 'block_key' => 39,
    'summary' => 'Trying to claim the exam block with a maths session.']);
contains('a maths session cannot name the exam block', $reply, 'runs exam-skills, not maths');
$today = call($store, 'tracker_today', []);
contains('tracker_today lists the exam block', $today, '#39  14:30-15:30  Exam practice');

echo "\n== B. the bank refuses what it should, and repeats are no-ops ==\n";
$q = static fn(string $key, string $subject, array $refs, int $marks, array $over = []): array => array_replace([
    'client_key' => $key, 'subject' => $subject, 'topic_refs' => $refs, 'marks' => $marks,
    'question_md' => "Solve 3x + 4 = 19\n\nYou must show your working.",
    'mark_scheme_md' => "M1 for 3x = 15 oe\nA1 for x = 5",
    'model_answer_md' => '3x = 15, x = 5', 'paper_style' => '8300/1H', 'command_word' => 'Solve',
    'time_guide_seconds' => 120, 'tags' => ['linear', 'non-calc'],
], $over);
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [$q('bad-ref', 'maths', ['Z99'], 2)]]);
contains('an unknown topic ref is refused', $reply, "names 'Z99', which maths does not hold");
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [$q('bad-sub', 'exam-skills', ['T2'], 2)]]);
contains('a question for exam-skills is refused', $reply, 'never exam-skills');
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [$q('dup', 'maths', ['A17'], 2), $q('dup', 'maths', ['A4'], 2)]]);
contains('a client_key repeated in one call is refused', $reply, "repeats client_key 'dup'");
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [$q('zero', 'maths', ['A17'], 0)]]);
contains('zero marks is refused', $reply, 'marks');
check('and nothing was written by the refusals', count($store->listExamQuestions()), 0);

$batch = ['questions' => [
    $q('maths-A17-1', 'maths', ['A17'], 2),
    $q('maths-A4-1', 'maths', ['A4', 'A17'], 3, ['question_md' => "Factorise fully 6x^2 + 8x", 'mark_scheme_md' => "B2 for 2x(3x + 4); B1 for 2(3x^2 + 4x) or x(6x + 8)", 'model_answer_md' => '2x(3x + 4)', 'command_word' => 'Factorise']),
    $q('lang-L2-1', 'english-language', ['L2'], 4, ['question_md' => "How does the writer use language to describe the storm?\n\n- words and phrases\n- language features and techniques", 'mark_scheme_md' => "Level 2 (3-4): clear explanation of effects, relevant terminology.\nLevel 1 (1-2): simple comment.", 'paper_style' => '8700/1', 'command_word' => 'Explain', 'time_guide_seconds' => 480, 'tags' => ['AO2']]),
    $q('cs-C1-1', 'computer-science', ['C1'], 1, ['question_md' => 'State the denary value of the binary number 1011.', 'mark_scheme_md' => '11 (1 mark)', 'model_answer_md' => '11', 'paper_style' => '8525/1', 'command_word' => 'State', 'time_guide_seconds' => 60, 'tags' => []]),
]];
$reply = call($store, 'tracker_exam_add_questions', $batch);
contains('a good batch lands as draft', $reply, '4 questions added to the bank as draft');
[$q1, $q2, $q3, $q4] = ids($reply);
check('with sequential ids', [$q1, $q2, $q3, $q4], [1, 2, 3, 4]);
$again = call($store, 'tracker_exam_add_questions', $batch);
contains('re-sending the batch is a no-op', $again, '0 questions added');
contains('naming the stored id', $again, "#$q1 maths-A17-1 — already stored as draft, unchanged");
check('and the bank still holds four', count($store->listExamQuestions()), 4);
$list = call($store, 'tracker_exam_list_questions', ['subject' => 'maths']);
contains('the list shows the maths questions', $list, "#$q2 maths [draft] 3 marks · A4/A17 · 8300/1H · linear, non-calc · maths-A4-1 — Factorise");
contains('and says it is the parent\'s', $list, 'Parent only');
$list = call($store, 'tracker_exam_list_questions', ['tag' => 'AO2']);
contains('a tag filter finds the English question', $list, "#$q3 english-language");
lacks('and nothing else', $list, "#$q1 ");

echo "\n== C. vet, edit, retire ==\n";
foreach ([$q1, $q2, $q3, $q4] as $id) {
    $reply = call($store, 'tracker_exam_update_question', ['id' => $id, 'action' => 'vet', 'note' => 'checked against the checklist']);
}
contains('vetting moves a draft to vetted', $reply, "#$q4 vetted");
check('and stamps vetted_at', $store->examQuestion($q4)['vetted_at'] !== null, true);
$reply = call($store, 'tracker_exam_update_question', ['id' => $q1, 'action' => 'edit', 'fields' => ['marks' => 3]]);
contains('an edit returns a vetted question to draft', $reply, 'returned to draft');
check('with the new marks', $store->examQuestion($q1)['marks'], 3);
call($store, 'tracker_exam_update_question', ['id' => $q1, 'action' => 'vet']);
$reply = call($store, 'tracker_exam_update_question', ['id' => $q1, 'action' => 'edit', 'fields' => ['subject' => 'maths']]);
contains('the subject cannot be edited', $reply, 'cannot be edited here');
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [$q('spare', 'maths', ['A17'], 1)]]);
$spare = ids($reply)[0];
$reply = call($store, 'tracker_exam_update_question', ['id' => $spare, 'action' => 'retire', 'note' => 'duplicate']);
contains('a draft can be retired', $reply, "#$spare retired");
$reply = call($store, 'tracker_exam_update_question', ['id' => $spare, 'action' => 'vet']);
contains('and a retired question cannot be vetted', $reply, 'only a draft can be vetted');

echo "\n== D. scheduling ==\n";
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [$q('draft-only', 'maths', ['A17'], 1)]]);
$draft = ids($reply)[0];
$sections = [
    ['subject' => 'maths', 'question_ids' => [$q1, $q2], 'minutes_guide' => 15],
    ['subject' => 'english-language', 'question_ids' => [$q3], 'minutes_guide' => 20],
    ['subject' => 'computer-science', 'question_ids' => [$q4], 'minutes_guide' => 5],
];
$base = ['name' => 'Exam practice — week 39', 'scheduled_for' => '2026-09-23', 'duration_minutes' => 60,
    'block_key' => 39, 'instructions' => 'Maths: no calculator. Write something for every question.', 'sections' => $sections];
$reply = call($store, 'tracker_exam_schedule_test', array_replace($base, ['sections' => [['subject' => 'maths', 'question_ids' => [$draft]]]]));
contains('a draft question cannot be scheduled', $reply, "question #$draft is draft, not vetted");
$reply = call($store, 'tracker_exam_schedule_test', array_replace($base, ['sections' => [['subject' => 'maths', 'question_ids' => [$q3]]]]));
contains('a question in the wrong section is refused', $reply, "is the maths section but question #$q3 is english-language");
$reply = call($store, 'tracker_exam_schedule_test', array_replace($base, ['scheduled_for' => '2026-09-24']));
contains('the exam block on a Thursday is refused', $reply, 'REFUSED');
$reply = call($store, 'tracker_exam_schedule_test', array_replace($base, ['duration_minutes' => 5]));
contains('too short a paper is refused', $reply, 'duration_minutes');
check('and no test was written by the refusals', count($store->listExamTests()), 0);
$reply = call($store, 'tracker_exam_schedule_test', $base);
contains('a good paper is scheduled', $reply, 'Test #1 "Exam practice — week 39" scheduled for 2026-09-23: 4 questions, 11 marks, 1 h, block #39');
contains('with labels running across the sections', $reply, '- computer-science: Q4 — 1 mark, guide 5 min');
contains('and it says where she starts it', $reply, '/exam/1');
check('every question is now scheduled', $store->examQuestion($q3)['status'], 'scheduled');
$reply = call($store, 'tracker_exam_schedule_test', array_replace($base, ['name' => 'Second']));
contains('a second test on the date is refused', $reply, 'is already ready on 2026-09-23');
$reply = call($store, 'tracker_exam_schedule_test', array_replace($base, ['scheduled_for' => '2026-09-30', 'sections' => [['subject' => 'maths', 'question_ids' => [$q1]]]]));
contains('a question is sat once', $reply, "question #$q1 is scheduled, not vetted");
$reply = call($store, 'tracker_exam_update_question', ['id' => $q1, 'action' => 'edit', 'fields' => ['command_word' => 'Solve'], 'note' => 'house style']);
contains('a scheduled question is edited in place', $reply, "#$q1 edited (command_word) in place; it stays in test #1 as scheduled.");
check('and keeps its status', $store->examQuestion($q1)['status'], 'scheduled');
$reply = call($store, 'tracker_exam_update_question', ['id' => $q1, 'action' => 'retire']);
contains('but cannot be retired while in the test', $reply, 'take it out first with tracker_exam_update_test');
$reply = call($store, 'tracker_exam_list_tests', []);
contains('the test lists as waiting', $reply, '#1 Exam practice — week 39 — 2026-09-23 · ready · 4 q · 11 marks · 1 h · block #39 · waiting: /exam/1');
$today = call($store, 'tracker_today', []);
contains('tracker_today points the tutor chat at it', $today, 'test #1 ready at /exam/1');

echo "\n== E. before Start: the shape of the paper, no questions ==\n";
$page = render_exam_test($store, $store->examTest(1), false);
contains('the front page offers Start', $page, 'Start — 1 h');
contains('and lists the sections', $page, '<b>Mathematics</b> — 2 questions, 6 marks, about 15 min');
contains('and the instructions', $page, 'Maths: no calculator.');
lacks('but no question text', $page, 'Solve 3x');
lacks('and no mark scheme', $page, 'M1 for');
$reply = call($store, 'tracker_exam_get_test', ['id' => 1]);
contains('get_test withholds the schemes before the sitting', $reply, 'The mark schemes are withheld');
lacks('really withholds them', $reply, 'M1 for 3x');
$reply = call($store, 'tracker_exam_get_test', ['id' => 1, 'include_bank' => true]);
contains('unless the parent asks for the bank', $reply, 'MARK SCHEME: M1 for 3x = 15 oe');
$index = render_index($store, false);
contains('the Wednesday chip links to the paper', $index, 'href="/exam/1"');
contains('and the index links to exam practice', $index, 'href="/exam">Exam practice</a>');
contains('and to next week', $index, 'href="/?week=2026-W40"');
contains('and to last week', $index, 'href="/?week=2026-W38"');
lacks('with no way back to a week it is already on', $index, '<a href="/">this week</a>');
$ahead = render_index($store, false, '2026-W40');
contains('next week renders on the same board', $ahead, 'class="wk"');
contains('named by its number', $ahead, '<h1>Week 40</h1>');
contains('dated by its span', $ahead, '28 September 2026 to 4 October 2026');
contains('with the arrows moved along', $ahead, 'href="/?week=2026-W41"');
contains('and a way back', $ahead, '<a href="/">this week</a>');
contains('its totals name the week', $ahead, '<b>week 40</b>');
lacks('nothing in it is today', $ahead, 'class="daytab"');
lacks('and there is no bell to ring', $ahead, 'id="ttbell"');
lacks('nor a report page for a week that has not started', $ahead, 'as its own page');
$behind = render_index($store, false, '2026-W38');
contains('last week has its report page', $behind, 'href="/week/2026-W38">2026-W38 as its own page</a>');
contains('an impossible week is this week', render_index($store, false, 'nope'), '<h1>This week</h1>');
contains('and so is this week by name', render_index($store, false, '2026-W39'), '<h1>This week</h1>');
$list = render_exam_index($store, false);
contains('the list shows the test as waiting', $list, 'waiting');
$reply = call($store, 'tracker_exam_mark_test', ['id' => 1, 'marks' => [['question_id' => $q1, 'score' => 1]]]);
contains('a test not yet sat cannot be marked', $reply, 'She has not sat it yet');

echo "\n== F. the sitting ==\n";
$t = $store->startExamTest(1);
check('Start opens the test', $t['status'], 'open');
check('with a deadline the duration after the start', $t['deadline_at'], exam_add_seconds($t['started_at'], 3600));
check('at the frozen clock', tt_local($t['started_at']), ['2026-09-23', '14:20']);
check('and a sitting token', strlen((string) $t['sit_token']), 32);
$token = (string) $t['sit_token'];
$page = render_exam_test($store, $store->examTest(1), false);
contains('the sitting page carries the questions', $page, 'Solve 3x + 4 = 19');
contains('rendered from the restricted markdown', $page, '<p>Solve 3x + 4 = 19</p><p>You must show your working.</p>');
contains('with a superscript', $page, '6x<sup>2</sup> + 8x');
contains('and a list', $page, '<ul><li>words and phrases</li>');
contains('a working box for maths', $page, 'name="working[' . $q1 . ']"');
lacks('no working box for English', $page, 'name="working[' . $q3 . ']"');
contains('the clock data', $page, '"deadline":' . exam_epoch($t['deadline_at']));
contains('the token', $page, $token);
lacks('and no mark scheme for the student', $page, 'M1 for');
$ppage = render_exam_test($store, $store->examTest(1), true);
contains('the parent sees the scheme beside it', $ppage, 'M1 for 3x = 15');
check('a wrong token is refused', exam_guard_write($t, 'nope'), 'the token does not match this sitting');
check('the right one is not', exam_guard_write($t, $token), null);
$store->saveExamAnswer(1, $q1, ['answer' => 'x = 5', 'working' => '3x = 15', 'time_spent_seconds' => 90]);
$store->saveExamAnswer(1, $q2, ['answer' => '2(3x^2 + 4x)', 'flagged' => true, 'time_spent_seconds' => 200]);
$store->saveExamAnswer(1, $q3, ['answer' => 'The writer uses violent verbs like "lashed" to make the storm feel alive.', 'time_spent_seconds' => 700]);
$store->saveExamAnswer(1, $q2, ['answer' => '2(3x^2 + 4x)', 'flagged' => true, 'time_spent_seconds' => 150]);
check('time spent never winds back', $store->examTest(1)['sections'][0]['questions'][1]['answer']['time_spent_seconds'], 200);
$page = render_exam_test($store, $store->examTest(1), false);
contains('a reload gets the answers back', $page, '>x = 5</textarea>');
contains('and the flag', $page, 'name="flag[' . $q2 . ']" checked');
$reply = call($store, 'tracker_exam_get_test', ['id' => 1]);
lacks('get_test still withholds the scheme while open', $reply, 'M1 for 3x');
$reply = call($store, 'tracker_exam_mark_test', ['id' => 1, 'marks' => [['question_id' => $q1, 'score' => 1]]]);
contains('an open test cannot be marked', $reply, 'The sitting is still running');

echo "\n== G. the timer ==\n";
putenv('TRACKER_NOW=2026-09-23 15:20:05');
check('a save five seconds past the deadline is taken', exam_guard_write($store->examTestRow(1), $token), null);
putenv('TRACKER_NOW=2026-09-23 15:20:15');
check('fifteen seconds past it is not', exam_guard_write($store->examTestRow(1), $token), 'time is up');
$day = $store->judgeDay('2026-09-23');
$b39 = array_values(array_filter($day['blocks'], static fn($b) => $b['block_key'] === 39))[0];
check('the block is done while the test is still open past its deadline', $b39['status'], 'done');
check('read from the sitting', $b39['evidence'][0]['type'] ?? null, 'exam');
check('the test row itself is still open (an inference, not a write)', $store->examTestRow(1)['status'], 'open');
$t = $store->lazyCloseExamTest($store->examTestRow(1));
check('the next request closes it', $t['status'], 'closed');
check('by the timer', $t['closed_by'], 'timer');
check('at the deadline exactly', $t['closed_at'], $t['deadline_at']);
check('and the questions are answered', $store->examQuestion($q1)['status'], 'answered');
$day = $store->judgeDay('2026-09-23');
$b39 = array_values(array_filter($day['blocks'], static fn($b) => $b['block_key'] === 39))[0];
check('the block is done', $b39['status'], 'done');
check('for the full hour', $b39['minutes'], 60);
check('in the shape the block asked for', $b39['shape'] ?? null, 'met');
$page = render_exam_test($store, $store->examTest(1), false);
contains('the closed page says it is handed in', $page, 'ready for marking');
contains('and counts the blank', $page, '1 question was left blank');
contains('and shows what she wrote', $page, '<div class="ex-ans">x = 5</div>');
lacks('but no scores yet', $page, 'class="ex-score');
lacks('and no scheme', $page, 'M1 for');
$reply = call($store, 'tracker_exam_list_tests', ['status' => 'closed']);
contains('the list flags it for marking', $reply, 'READY FOR MARKING');
$today = call($store, 'tracker_today', []);
contains('tracker_today says the sitting awaits marking', $today, 'closed, awaiting marking');
$reply = call($store, 'tracker_exam_get_test', ['id' => 1]);
contains('get_test now gives the scheme', $reply, 'MARK SCHEME: M1 for 3x = 15 oe');
contains('and her answer', $reply, 'ANSWER: x = 5');
contains('and her working', $reply, 'WORKING: 3x = 15');
contains('and the blank', $reply, 'Q4 (question #' . $q4 . ', 1 mark, C1, State, guide 1 min)' . "\n  State the denary value of the binary number 1011.\n  ANSWER: (blank)");
contains('and the flag', $reply, 'flagged to come back to');

echo "\n== H. a session about the sitting never steals the block ==\n";
putenv('TRACKER_NOW=2026-09-24 09:00');
$reply = call($store, 'tracker_log_session', ['subject' => 'exam-skills', 'date' => '2026-09-23',
    'summary' => 'Technique notes on the week 39 paper: spent 12 of 15 maths minutes on Q2.',
    'updates' => [['ref' => 'T2', 'status' => 'developing', 'evidence' => 'Q2 took 200 s against a 60 s guide; Q4 left blank with 5 min unused.']]]);
contains('the technique session logs', $reply, 'logged');
$day = $store->judgeDay('2026-09-23');
$b39 = array_values(array_filter($day['blocks'], static fn($b) => $b['block_key'] === 39))[0];
check('the block is still the sitting\'s', $b39['evidence'][0]['type'] ?? null, 'exam');
check('and still in shape', $b39['shape'] ?? null, 'met');
check('the session is an extra', count($day['extras']), 1);
$week = $store->judgeWeek('2026-09-23');
check('and the hour is counted once', $week['hours_by_subject']['exam-skills'] ?? null, 1.0);
putenv('TRACKER_NOW=2026-09-23 15:25');

echo "\n== I. marking ==\n";
$marks = [
    ['question_id' => $q1, 'score' => 2, 'note' => 'M1 A1', 'student_feedback' => 'Full marks — working shown clearly.'],
    ['question_id' => $q2, 'score' => 1, 'note' => 'B1: common factor 2 taken out, x not', 'student_feedback' => 'Factorising fully: take out every common factor, including the letter. Check by expanding.'],
    ['question_id' => $q3, 'score' => 3, 'note' => 'Level 2: clear effect, one technique named', 'student_feedback' => 'Good: effect explained. Add a second technique with its own quotation for the top of Level 2.'],
    ['question_id' => $q4, 'score' => 0, 'note' => 'blank', 'student_feedback' => 'Left blank with time to spare. Binary to denary: write the place values 8 4 2 1 above the digits and add.'],
];
$reply = call($store, 'tracker_exam_mark_test', ['id' => 1, 'marks' => array_slice($marks, 0, 3)]);
contains('every question must be marked', $reply, "missing question #$q4 (Q4)");
$reply = call($store, 'tracker_exam_mark_test', ['id' => 1, 'marks' => array_replace($marks, [0 => ['question_id' => $q1, 'score' => 5]])]);
contains('a score above the marks is refused', $reply, "scores 5 on question #$q1 (Q1), which carries 3 marks");
check('and nothing was written', count($store->listAttempts('maths')), 0);
$reply = call($store, 'tracker_exam_mark_test', ['id' => 1, 'marks' => $marks, 'note' => 'First paper. Pace was the story.']);
contains('marking succeeds', $reply, 'Test #1 "Exam practice — week 39" marked. Sat 2026-09-23, 60 of 60 min.');
contains('with one attempt per subject', $reply, '- maths: 3/6 over 2 questions, 0 blank — attempt #1, /s/maths/a/1');
contains('including the blank one', $reply, '- computer-science: 0/1 over 1 question, 1 blank — attempt #3');
contains('and the question-by-question facts', $reply, '- Q2 maths A4/A17 1/3 · attempted · 3 min, flagged — B1: common factor 2 taken out, x not');
contains('and tells the skill what to log', $reply, 'with NO block_key and NO duration_minutes');
check('the test is marked', $store->examTestRow(1)['status'], 'marked');
check('and its questions', $store->examQuestion($q4)['status'], 'marked');
$attempt = $store->getAttempt('maths', 1);
check('the maths attempt is a check', $attempt['kind'], 'check');
check('dated the sitting', $attempt['date'], '2026-09-23');
check('on the subject tier', $attempt['tier'], 'H');
check('with the labels as question numbers', array_column($attempt['papers'][0]['questions'], 'number'), ['1', '2']);
check('the first ref as the topic', $attempt['papers'][0]['questions'][1]['topic_ref'], 'A4');
contains('and her working in the answer', (string) $attempt['papers'][0]['questions'][0]['answer'], "x = 5\n\nWorking:\n3x = 15");
check('the CS attempt records the blank', $store->getAttempt('computer-science', 3)['papers'][0]['blanks'], 1);
check('and the blank answer', $store->getAttempt('computer-science', 3)['papers'][0]['questions'][0]['answer'], '(blank)');
check('every answer points at its attempt row', $store->examTest(1)['sections'][2]['questions'][0]['answer']['attempt_question_id'] !== null, true);
$reply = call($store, 'tracker_get_attempt', ['subject' => 'maths', 'attempt_id' => 1]);
contains('tracker_get_attempt breaks it down by topic', $reply, 'A4');
$reply = call($store, 'tracker_list_attempts', ['subject' => 'maths']);
contains('and the list never grade-converts it', $reply, 'Exam practice — week 39 — GCSE Mathematics section');
$reply = call($store, 'tracker_exam_mark_test', ['id' => 1, 'marks' => $marks]);
contains('a second marking is refused', $reply, 'already marked');

echo "\n== J. what each viewer sees afterwards ==\n";
$page = render_exam_test($store, $store->examTest(1), false);
contains('the student sees her score', $page, '<span class="ex-score part">1 / 3</span>');
contains('the whole-paper total', $page, '<p class="label">Whole paper</p><p class="big">6<small> / 11</small></p>');
contains('and the feedback', $page, 'Factorising fully: take out every common factor');
contains('and a link to the subject record', $page, 'href="/s/maths/a/1"');
lacks('but not the marker\'s note', $page, 'common factor 2 taken out, x not');
lacks('nor the scheme', $page, 'B2 for 2x(3x + 4)');
lacks('nor the model answer', $page, '2x(3x + 4)</p>');
lacks('nor the test note', $page, 'Pace was the story');
$ppage = render_exam_test($store, $store->examTest(1), true);
contains('the parent sees the marker\'s note', $ppage, 'common factor 2 taken out, x not');
contains('and the scheme', $ppage, 'B2 for 2x(3x + 4)');
contains('and the test note', $ppage, 'Pace was the story');
$bank = render_exam_bank($store, []);
contains('the bank page lists every question', $bank, 'id="q' . $spare . '"');
contains('with its scheme', $bank, 'B2 for 2x(3x + 4)');
$reply = call($store, 'tracker_week_report', ['date' => '2026-09-23']);
contains('the week report carries the sitting', $reply, 'EXAM PRACTICE');
contains('with its section marks', $reply, '2026-09-23 Exam practice — week 39 — marked, sat 60 of 60 min, closed by timer: maths 3/6 · english-language 3/4 · computer-science 0/1 (1 blank)');
contains('and the hour against exam-skills', $reply, 'exam-skills 1.00');
$today = call($store, 'tracker_today', []);
contains('tracker_today says marked', $today, '<- exam #1  · marked');

echo "\n== K. a block with no sitting, and the markdown ==\n";
putenv('TRACKER_NOW=2026-09-30 16:00');
$day = $store->judgeDay('2026-09-30');
$b39 = array_values(array_filter($day['blocks'], static fn($b) => $b['block_key'] === 39))[0];
check('the next Wednesday with nothing sat is missed', $b39['status'], 'missed');
check('in the board\'s words', mcp_absent($b39), 'no exam-practice test sat');
check('a script tag is escaped', exam_md('<script>alert(1)</script>'), '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
check('bold and code render', exam_md('Write **exactly** `print(x)`'), '<p>Write <b>exactly</b> <code>print(x)</code></p>');
check('fenced code renders', exam_md("```\nfor i in range(3):\n    print(i)\n```"), '<pre class="ex-code"><code>for i in range(3):' . "\n" . '    print(i)</code></pre>');
check('a bracketed superscript', exam_md('2^(n+1)'), '<p>2<sup>n+1</sup></p>');
check('a numbered list', exam_md("1. first\n2. second"), '<ol><li>first</li><li>second</li></ol>');

echo "\n== L. editing and cancelling a booked test ==\n";
putenv('TRACKER_NOW=2026-10-06 10:00');
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [
    $q('edit-m1', 'maths', ['A17'], 2), $q('edit-m2', 'maths', ['A4'], 3), $q('edit-m3', 'maths', ['A17'], 4),
    $q('edit-c1', 'computer-science', ['C1'], 1),
]]);
[$e1, $e2, $e3, $e4] = ids($reply);
foreach ([$e1, $e2, $e3, $e4] as $id) {
    call($store, 'tracker_exam_update_question', ['id' => $id, 'action' => 'vet']);
}
$reply = call($store, 'tracker_exam_schedule_test', ['name' => 'Week 41', 'scheduled_for' => '2026-10-07', 'duration_minutes' => 45,
    'block_key' => 39, 'sections' => [['subject' => 'maths', 'question_ids' => [$e1, $e2]]]]);
preg_match('/Test #(\d+)/', $reply, $m);
$tid = (int) $m[1];
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['duration_minutes' => 90, 'name' => 'Week 41 — long paper'], 'note' => 'more time']);
contains('a ready test takes a new timer', $reply, "Test #$tid edited (duration_minutes, name).");
check('stored', $store->examTestRow($tid)['duration_minutes'], 90);
contains('and the reason is noted', (string) $store->examTestRow($tid)['note'], 'edited duration_minutes, name: more time');
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['duration_minutes' => 500]]);
contains('the timer still has its range', $reply, 'duration_minutes');
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['sections' => [
    ['subject' => 'maths', 'question_ids' => [$e1, $e3], 'minutes_guide' => 30],
    ['subject' => 'computer-science', 'question_ids' => [$e4], 'minutes_guide' => 10],
]]]);
contains('the questions can be swapped', $reply, "- maths: Q1 (#$e1), Q2 (#$e3) — 6 marks, guide 30 min");
contains('and a section added', $reply, "- computer-science: Q3 (#$e4) — 1 mark, guide 10 min");
check('the dropped question is free again', $store->examQuestion($e2)['status'], 'vetted');
check('the new one is scheduled', $store->examQuestion($e3)['status'], 'scheduled');
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['scheduled_for' => '2026-10-08']]);
contains('a date the block does not run on is refused', $reply, 'REFUSED');
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['scheduled_for' => '2026-10-08', 'block_key' => null]]);
contains('unless the block is cleared too', $reply, "Test #$tid edited (scheduled_for, block_key).");
check('moved', $store->examTestRow($tid)['scheduled_for'], '2026-10-08');
call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['scheduled_for' => '2026-10-07', 'block_key' => 39]]);
check('and back', [$store->examTestRow($tid)['scheduled_for'], $store->examTestRow($tid)['block_key']], ['2026-10-07', 39]);

putenv('TRACKER_NOW=2026-10-07 14:30');
$store->startExamTest($tid);
$started = $store->examTestRow($tid);
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['sections' => [['subject' => 'maths', 'question_ids' => [$e1]]]]]);
contains('an open test keeps its questions', $reply, 'is open, so fields.sections can no longer change');
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['duration_minutes' => 120]]);
contains('an open test can be given more time', $reply, 'picks up the new deadline');
check('the deadline moves from the start', $store->examTestRow($tid)['deadline_at'], exam_add_seconds($started['started_at'], 120 * 60));
$reply = call($store, 'tracker_exam_update_question', ['id' => $e3, 'action' => 'edit', 'fields' => ['mark_scheme_md' => 'B4 corrected']]);
contains('a question can be corrected mid-sitting', $reply, 'she sees the change when her page next reloads');
putenv('TRACKER_NOW=2026-10-07 15:00');
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['duration_minutes' => 20]]);
contains('cutting the timer below the time gone closes it', $reply, 'the sitting is now closed');
check('closed at the new deadline', $store->examTestRow($tid)['closed_at'], exam_add_seconds($started['started_at'], 20 * 60));
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'edit', 'fields' => ['duration_minutes' => 60]]);
contains('a closed test keeps its timer', $reply, 'is closed, so fields.duration_minutes can no longer change');
$reply = call($store, 'tracker_exam_update_test', ['id' => $tid, 'action' => 'cancel', 'note' => 'wrong paper']);
contains('a sat test can be cancelled', $reply, 'cancelled, with the answers saved in it');
contains('its questions retired', $reply, 'set to retired because she has seen them');
check('the test is gone', $store->examTestRow($tid), null);
contains('the question notes say why', (string) $store->examQuestion($e1)['note'], "cancelled after she started it: wrong paper");

putenv('TRACKER_NOW=2026-10-12 10:00');
$reply = call($store, 'tracker_exam_schedule_test', ['name' => 'Week 42', 'scheduled_for' => '2026-10-14', 'duration_minutes' => 30,
    'sections' => [['subject' => 'maths', 'question_ids' => [$e2]]]]);
preg_match('/Test #(\d+)/', $reply, $m);
$reply = call($store, 'tracker_exam_update_test', ['id' => (int) $m[1], 'action' => 'cancel']);
contains('an unstarted test cancels back to vetted', $reply, 'set to vetted and free to go into another test');
check('the question is usable again', $store->examQuestion($e2)['status'], 'vetted');
$reply = call($store, 'tracker_exam_update_test', ['id' => 1, 'action' => 'cancel']);
contains('a marked test cannot be cancelled', $reply, 'its attempts are the record');
$reply = call($store, 'tracker_exam_update_test', ['id' => 1, 'action' => 'edit', 'fields' => ['name' => 'Week 39 (first paper)']]);
contains('but can be renamed', $reply, 'Test #1 edited (name).');
$reply = call($store, 'tracker_exam_update_question', ['id' => $q1, 'action' => 'edit', 'fields' => ['marks' => 1]]);
contains('a marked question cannot drop below its score', $reply, 'cannot go below the 2 already scored');
$reply = call($store, 'tracker_exam_update_question', ['id' => $q2, 'action' => 'edit', 'fields' => ['model_answer_md' => '2x(3x + 4), fully factorised']]);
contains('but can otherwise be corrected', $reply, 'The attempt written at marking keeps its copy');
check('and stays marked', $store->examQuestion($q2)['status'], 'marked');

echo "\n== M. homework: the same bank, on paper ==\n";
putenv('TRACKER_NOW=2026-10-09 18:00');
check('a sitting answer typed "skip" and declared not attempted is blank', exam_answer_blank(['answer' => 'skip', 'not_attempted' => true]), true);
check('a sitting answer with text and no declaration is not', exam_answer_blank(['answer' => 'x = 5', 'not_attempted' => false]), false);
check('a homework question not yet marked is not blank', exam_answer_blank(null, 'homework'), false);
check('a homework question the marker declared is', exam_answer_blank(['answer' => null, 'not_attempted' => true], 'homework'), true);
$reply = call($store, 'tracker_exam_add_questions', ['questions' => [
    $q('hw-m1', 'maths', ['A17'], 2, ['question_md' => 'A line has the equation y = 3x + 4. Work out y when x = 0, 1, 2 and 5. Write each sum.', 'mark_scheme_md' => 'B2 for all four correct (4, 7, 10, 19); B1 for two or three', 'model_answer_md' => '4, 7, 10, 19']),
    $q('hw-m2', 'maths', ['A4'], 3, ['question_md' => 'A line passes through (0, 4) and (2, 14). Work out the gradient, then write the equation of the line.', 'mark_scheme_md' => 'M1 for (14 − 4)/(2 − 0); A1 gradient 5; A1 y = 5x + 4', 'model_answer_md' => 'gradient 5, y = 5x + 4']),
    $q('hw-m3', 'maths', ['A17'], 2, ['question_md' => 'A line has the equation 2y = 8x + 6. Write it in the form y = …', 'mark_scheme_md' => 'B2 for y = 4x + 3; B1 for y = (8x + 6)/2', 'model_answer_md' => 'y = 4x + 3']),
    $q('hw-l1', 'english-language', ['L2'], 4, ['question_md' => 'Write one paragraph on how the writer presents the sea.', 'mark_scheme_md' => 'Level 2 (3-4) clear; Level 1 (1-2) simple.', 'paper_style' => '8700/1']),
]]);
[$h1, $h2, $h3, $h4] = ids($reply);
foreach ([$h1, $h2, $h3, $h4] as $id) {
    call($store, 'tracker_exam_update_question', ['id' => $id, 'action' => 'vet']);
}
$hwArgs = ['name' => 'Straight lines: weekend homework', 'mode' => 'homework', 'scheduled_for' => '2026-10-09', 'due_on' => '2026-10-11',
    'duration_minutes' => 25, 'instructions' => "On Tuesday the two numbers in a line's equation were getting swapped. These questions make that stick.\nOn paper, by hand. No calculator. Write every line of working.",
    'sections' => [['subject' => 'maths', 'question_ids' => [$h1, $h2]]]];
$reply = call($store, 'tracker_exam_schedule_test', array_diff_key($hwArgs, ['due_on' => 1]));
contains('a homework needs a due date', $reply, 'needs due_on');
$reply = call($store, 'tracker_exam_schedule_test', array_replace($hwArgs, ['due_on' => '2026-10-08']));
contains('due before set is refused', $reply, 'before scheduled_for');
$reply = call($store, 'tracker_exam_schedule_test', array_replace($hwArgs, ['block_key' => 3]));
contains('a homework takes no block', $reply, 'takes no block_key');
$reply = call($store, 'tracker_exam_schedule_test', array_replace($hwArgs, ['mode' => 'sitting', 'duration_minutes' => 30]));
contains('a sitting takes no due date', $reply, 'due_on is for mode homework');
$reply = call($store, 'tracker_exam_schedule_test', array_replace($hwArgs, ['duration_minutes' => 4]));
contains('the guide has its own range', $reply, 'duration_minutes');
check('and nothing was written by the refusals', count($store->listExamTests(['mode' => 'homework'])), 0);

$reply = call($store, 'tracker_exam_schedule_test', $hwArgs);
contains('a homework is set', $reply, 'set for 2026-10-09, due 2026-10-11: 2 questions, 5 marks, about 25 min, on paper.');
contains('and the reply says where the sheet is', $reply, 'The sheet is on /exam/');
contains('and how it comes back', $reply, 'photographs every page and hands them in');
preg_match('/Homework #(\d+)/', $reply, $m);
$hw = (int) $m[1];
check('its questions are scheduled', [$store->examQuestion($h1)['status'], $store->examQuestion($h2)['status']], ['scheduled', 'scheduled']);
$row = $store->examTestRow($hw);
check('stored as homework with its due date', [$row['mode'], $row['due_on'], $row['status']], ['homework', '2026-10-11', 'ready']);
$reply = call($store, 'tracker_exam_schedule_test', array_replace($hwArgs, ['name' => 'Second maths sheet', 'sections' => [['subject' => 'maths', 'question_ids' => [$h3]]]]));
contains('a second maths homework due the same day is refused', $reply, 'One homework per subject per due date');
$reply = call($store, 'tracker_exam_schedule_test', ['name' => 'Timed paper same day', 'scheduled_for' => '2026-10-11', 'duration_minutes' => 30,
    'sections' => [['subject' => 'maths', 'question_ids' => [$h3]]]]);
contains('but a sitting on the due date does not clash with it', $reply, 'Test #');
preg_match('/Test #(\d+)/', $reply, $m);
call($store, 'tracker_exam_update_test', ['id' => (int) $m[1], 'action' => 'cancel']);
$reply = call($store, 'tracker_exam_schedule_test', array_replace($hwArgs, ['name' => 'Sea paragraph', 'scheduled_for' => '2026-10-10',
    'sections' => [['subject' => 'english-language', 'question_ids' => [$h4]]]]));
contains('another subject due the same day is fine', $reply, 'Homework #');
preg_match('/Homework #(\d+)/', $reply, $m);
$hw2 = (int) $m[1];
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw2, 'action' => 'edit', 'fields' => ['mode' => 'sitting']]);
contains('a mode is fixed', $reply, "mode is fixed");
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw2, 'action' => 'edit', 'fields' => ['block_key' => 3]]);
contains('a homework cannot be given a block later either', $reply, 'not a homework field');
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw2, 'action' => 'edit', 'fields' => ['due_on' => '2026-10-12']]);
contains('a set homework can move its due date', $reply, "Homework #$hw2 edited (due_on).");
contains('and the head reads the new date', $reply, 'due 2026-10-12');
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw2, 'action' => 'cancel', 'note' => 'not this week']);
contains('a homework set for tomorrow cancels back to vetted', $reply, 'set to vetted');
check('because she has not seen it', $store->examQuestion($h4)['status'], 'vetted');
$reply = call($store, 'tracker_exam_update_test', ['id' => 1, 'action' => 'hand_in']);
contains('a sitting is not handed in through the tool', $reply, 'timed sitting');

$list = call($store, 'tracker_exam_list_tests', ['mode' => 'homework']);
contains('the list names the homework in its own words', $list, "#$hw Straight lines: weekend homework — homework · set 2026-10-09, due 2026-10-11 · set · 2 q · 5 marks · about 25 min · sheet: /exam/$hw");
lacks('and no sitting', $list, 'Week 39');
$list = call($store, 'tracker_exam_list_tests', ['mode' => 'sitting']);
lacks('the sitting list has no homework', $list, 'Straight lines');
$sheet = render_exam_test($store, $store->examTest($hw), false);
contains('the sheet shows the questions from the day it is set', $sheet, 'Work out y when x = 0, 1, 2 and 5');
contains('and the instructions', $sheet, 'two numbers in a line');
contains('and the due date', $sheet, '11 October 2026');
contains('and a tick per question', $sheet, 'class="hw-tick"');
contains('and the hand-in button', $sheet, "/exam/$hw/handin");
lacks('but no scheme', $sheet, 'B2 for all four');
lacks('and no Start', $sheet, "/exam/$hw/start");
lacks('and no answer box', $sheet, '<textarea');
$sheetP = render_exam_test($store, $store->examTest($hw), true);
contains('the parent sees the scheme on the sheet', $sheetP, 'B2 for all four');
$index = render_exam_index($store, false);
contains('/exam lists the homework with its due date', $index, 'due 2026-10-11');
contains('as homework', $index, '>homework</span>');
try {
    $store->startExamTest($hw);
    fail('a homework cannot be started');
} catch (InvalidArgumentException $e) {
    contains('a homework cannot be started', $e->getMessage(), 'has no Start');
}

putenv('TRACKER_NOW=2026-10-10 11:00');
$today = call($store, 'tracker_today', []);
contains('Saturday has no blocks', $today, 'No blocks today.');
contains('but names the homework out', $today, "- #$hw Straight lines: weekend homework — due 2026-10-11, set 2026-10-09 · set · /exam/$hw");
$board = render_index($store, false, '/', null);
contains('the board shows the homework under the week', $board, 'class="tt-homework"');
contains('with its due date', $board, 'due 11 October 2026');
$day = $store->judgeDay('2026-10-09');
check('a set homework is not evidence of anything', array_filter($day['extras'], static fn($e) => $e['type'] === 'exam'), []);
$reply = call($store, 'tracker_week_report', ['date' => '2026-10-09']);
contains('the week report has a HOMEWORK block', $reply, 'HOMEWORK');
contains('with the sheet set', $reply, '- due 2026-10-11 Straight lines: weekend homework (maths, 5 marks) — set');
$get = call($store, 'tracker_exam_get_test', ['id' => $hw]);
contains('get_test withholds the schemes while the sheet is out', $get, 'withheld while the homework is out');
lacks('really', $get, 'B2 for all four');
$get = call($store, 'tracker_exam_get_test', ['id' => $hw, 'include_bank' => true]);
contains('the parent reads them with include_bank', $get, 'B2 for all four');
contains('and is told marking hands it in', $get, 'hands it in and marks it in one call');

putenv('TRACKER_NOW=2026-10-12 09:00');
$reply = call($store, 'tracker_exam_mark_test', ['id' => $hw, 'handed_in_on' => '2026-10-13', 'marks' => [
    ['question_id' => $h1, 'score' => 2], ['question_id' => $h2, 'score' => 0]]]);
contains('a hand-in date in the future is refused', $reply, 'in the future');
$reply = call($store, 'tracker_exam_mark_test', ['id' => 1, 'marks' => [['question_id' => $q1, 'score' => 1, 'answer' => 'x']]]);
contains('a sitting takes no transcription', $reply, 'already marked');
$reply = call($store, 'tracker_exam_mark_test', ['id' => $hw, 'handed_in_on' => '2026-10-10', 'note' => 'Done Saturday morning; 20 min by her timer.', 'marks' => [
    ['question_id' => $h1, 'score' => 2, 'answer' => "y = 4, 7, 10, 19\nsums written for each", 'student_feedback' => 'All four right, every sum written. That is the habit.'],
    ['question_id' => $h2, 'score' => 0, 'not_attempted' => true, 'note' => 'Nothing on the page for Q2.', 'student_feedback' => 'Not attempted. The gradient sum is always a safe first line.'],
]]);
contains('a set homework is handed in and marked in one call', $reply, "Homework #$hw \"Straight lines: weekend homework\" marked. Handed in 2026-10-10 (due 2026-10-11).");
contains('one attempt for maths', $reply, '- maths: 2/5 over 2 questions, 1 not attempted — attempt #');
contains('question by question', $reply, "- Q2 maths A4 0/3 · NOT ATTEMPTED — Nothing on the page for Q2.");
contains('and no technique session is asked for', $reply, 'No exam-skills session');
lacks('nor a block', $reply, 'block\'s evidence');
$row = $store->examTestRow($hw);
check('the row is marked, handed in by the parent on the stated day', [$row['status'], $row['closed_by'], tt_local((string) $row['closed_at'])[0]], ['marked', 'parent', '2026-10-10']);
check('its questions are marked', [$store->examQuestion($h1)['status'], $store->examQuestion($h2)['status']], ['marked', 'marked']);
preg_match('/attempt #(\d+)/', $reply, $m);
$att = $store->getAttempt('maths', (int) $m[1]);
check('the attempt is a check dated the hand-in day', [$att['kind'], $att['date']], ['check', '2026-10-10']);
check('coded as homework', $att['papers'][0]['code'], "homework #$hw");
check('with the non-attempt counted as a blank', (int) $att['papers'][0]['blanks'], 1);
$aq = $store->listQuestions((int) $att['papers'][0]['id']);
check('the transcription is the attempt\'s answer', $aq[0]['answer'], "y = 4, 7, 10, 19\nsums written for each");
check('and the non-attempt says so', $aq[1]['answer'], '(not attempted)');
$day = $store->judgeDay('2026-10-10');
check('the attempt is an extra on the Saturday', count(array_filter($day['extras'], static fn($e) => $e['type'] === 'attempt' && $e['subject'] === 'maths')), 1);
$sheet = render_exam_test($store, $store->examTest($hw), false);
contains('the marked sheet shows the score', $sheet, '2 / 2');
contains('what she wrote', $sheet, 'What you wrote, as read from the photo');
contains('the non-attempt', $sheet, 'Not attempted');
contains('and the feedback', $sheet, 'safe first line');
lacks('but not the marker\'s note', $sheet, 'Nothing on the page');
lacks('nor the test note', $sheet, 'Done Saturday morning');
$sheetP = render_exam_test($store, $store->examTest($hw), true);
contains('the parent sees the marker\'s note', $sheetP, 'Nothing on the page');
contains('and the attempt link', $sheetP, '/s/maths/a/');
$reply = call($store, 'tracker_week_report', ['date' => '2026-10-09']);
contains('the week report carries the marks', $reply, '- due 2026-10-11 Straight lines: weekend homework (maths, 5 marks) — marked, handed in 2026-10-10: maths 2/5 (1 not attempted)');
lacks('and does not file it under exam practice', $reply, 'EXAM PRACTICE');
$today = call($store, 'tracker_today', []);
lacks('nothing is owed any more', $today, 'Homework out:');

// A second sheet: handed in through the tool, then overdue reporting.
$reply = call($store, 'tracker_exam_schedule_test', ['name' => 'Rearranging: one more go', 'mode' => 'homework', 'scheduled_for' => '2026-10-12', 'due_on' => '2026-10-13',
    'duration_minutes' => 15, 'sections' => [['subject' => 'maths', 'question_ids' => [$h3]]]]);
preg_match('/Homework #(\d+)/', $reply, $m);
$hw3 = (int) $m[1];
putenv('TRACKER_NOW=2026-10-14 09:00');
$today = call($store, 'tracker_today', []);
contains('a sheet past its due date is overdue', $today, "due 2026-10-13, set 2026-10-12 · set — OVERDUE");
$reply = call($store, 'tracker_week_report', ['date' => '2026-10-14']);
contains('and the week report says so', $reply, 'OVERDUE, nothing handed in');
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw3, 'action' => 'hand_in', 'fields' => ['handed_in_on' => '2026-10-13'], 'note' => 'photos came Tuesday night']);
contains('the tool hands it in, dated', $reply, "Homework #$hw3 \"Rearranging: one more go\" handed in, dated 2026-10-13.");
$list = call($store, 'tracker_exam_list_tests', ['mode' => 'homework', 'status' => 'closed']);
contains('and the list says it is ready for marking', $list, 'READY FOR MARKING from the photographs');
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw3, 'action' => 'hand_in']);
contains('handing in twice is a no-op', $reply, 'already handed in');
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw3, 'action' => 'edit', 'fields' => ['due_on' => '2026-10-20']]);
contains('a handed-in sheet keeps its due date', $reply, 'is handed in, so fields.due_on can no longer change');
$reply = call($store, 'tracker_exam_mark_test', ['id' => $hw3, 'handed_in_on' => '2026-10-14', 'marks' => [['question_id' => $h3, 'score' => 2]]]);
contains('a hand-in date that contradicts the record is refused', $reply, 'does not match');
$reply = call($store, 'tracker_exam_mark_test', ['id' => $hw3, 'marks' => [['question_id' => $h3, 'score' => 2, 'answer' => 'y = 4x + 3']]]);
contains('marked from the photographs', $reply, "Homework #$hw3 \"Rearranging: one more go\" marked. Handed in 2026-10-13.");
$reply = call($store, 'tracker_exam_update_test', ['id' => $hw3, 'action' => 'cancel']);
contains('a marked homework cannot be cancelled', $reply, 'its attempts are the record');

echo "\n";
if ($failures > 0) {
    echo "EXAM FAIL — $failures of $checks checks failed\n";
    exit(1);
}
echo "EXAM PASS — $checks checks\n";
