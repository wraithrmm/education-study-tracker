<?php
/**
 * Acceptance tests for the weekly learning synthesis.
 *
 *   php deploy/synthesis-test.php
 *
 * Drives the tools against a throwaway database with the clock frozen at
 * Saturday 26 September 2026 — the morning the routine runs, after the
 * week's sessions were audited. Covers migration 13, the inputs opener, the
 * §6 refusals, the atomic save and what it writes back (plans, tests, model
 * rows, watch and cross-subject signals), the queue's this_week block, the
 * week report's decisions, re-versioning guards, the learner model tool and
 * the parent gate on the pages.
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
    str_contains($haystack, $needle) ? pass($what) : fail($what, "missing: $needle\n        in: " . substr($haystack, 0, 900));
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

function logged_id(string $reply): int
{
    return preg_match('/Session (\d+) logged/', $reply, $m) ? (int) $m[1] : 0;
}

/** A valid lesson review for maths on P8 and A17. */
function review(array $over = []): array
{
    $base = [
        'topic_refs'   => ['P8', 'A17'],
        'one_sentence' => 'She chose add/multiply correctly in 5 of 6 unaided but stalled on the wording of "at least one".',
        'progress' => [
            ['ref' => 'P8', 'status_seen' => 'developing', 'evidence' => 'Q1-Q6 exit ticket: 5 of 6 correct unaided, Q4 prompted.',
             'implication' => 'One more unaided run on complement wording before the bar is claimed.'],
            ['ref' => 'A17', 'status_seen' => 'secure', 'evidence' => 'Starter: 4 of 4 linear equations unaided in under 3 minutes.',
             'implication' => 'Keep in retrieval rotation; nothing to teach.'],
        ],
        'independent' => 'Q1, Q2, Q3, Q5 and Q6 of the exit ticket, all unaided, all correct.',
        'supported'   => 'Q4 after one prompt: "what is the opposite of at least one?"',
        'errors' => [
            ['ref' => 'P8', 'error_type' => 'instruction_misread', 'what' => 'Read "at least one" as "exactly one" in Q4.',
             'why_type' => 'The arithmetic that followed was correct for the event she had in mind.',
             'response' => 'Open the next session with three complement-wording items.'],
        ],
        'retention' => ['retrieved' => [['ref' => 'A17', 'evidence' => 'starter, 4/4 unaided']], 'prompted' => [], 'not_retrieved' => [], 'schedule' => []],
        'process' => [
            ['area' => 'avoidance', 'basis' => 'observed', 'evidence' => '0 blanks in 6 exit-ticket questions.',
             'interpretation' => 'No avoidance on this topic today.', 'implication' => 'Keep the exit ticket at six items.'],
        ],
        'helped'   => [['method' => 'worked_example', 'effect' => 'helpful', 'evidence' => 'After the worked example on Q2 she did Q3 unaided.']],
        'hindered' => [],
        'signals' => [
            ['key' => 'model-then-immediate-practice', 'kind' => 'teaching_method',
             'statement' => 'One worked example followed immediately by a matched item transfers on the first try.',
             'strength' => 'one_off', 'direction' => 'supports', 'evidence' => 'Q2 modelled, Q3 unaided and correct in the same form.',
             'next_test' => 'Give the matched item after a two-item gap and see whether it still transfers.'],
        ],
        'confidence' => [['ref' => 'P8', 'confidence' => 'high', 'accuracy' => 'low', 'evidence' => 'Said "easy" before Q4, then misread it.',
                          'implication' => 'Check confidence against the item before moving on.']],
        'big_picture' => ['readiness' => 'progress_with_retrieval', 'why' => 'Method is there; the wording trap needs a retrieval check.'],
        'next_what' => ['opening_retrieval' => ['P8'], 'new' => ['P6-P7']],
        'next_how'  => ['stages' => [['stage' => 'start', 'method' => 'retrieval_questions', 'why' => 'three complement items']]],
        'do_differently' => [], 'continue' => [], 'watch' => [],
        'learner_voice'  => [['quote' => 'oh, it is one minus', 'context' => 'Q4, after the prompt']],
        'planner' => ['priority' => 'Complement wording on P8', 'start_with' => 'Three "at least one" items', 'teach_using' => 'One worked tree',
                      'avoid' => 'Four-line statements', 'check_whether' => 'She reads "at least one" as the complement', 'success' => '3 of 3 unaided'],
        'missing_evidence' => [],
    ];
    return array_replace($base, $over);
}

/** A valid synthesis for 2026-W39, planning 2026-W40, with overrides. */
function synthesis(array $over = []): array
{
    $base = [
        'glance' => [
            'picture' => 'Two maths sessions and one English session ran this week. The complement-wording trap on P8 was the week\'s only recurring error. '
                . 'One worked example followed by a matched item transferred in both maths sessions. Retention on A17 held across a four-day gap. '
                . 'English produced one usable quotation and one instruction misread.',
            'most_important' => 'Modelled-then-matched practice transfers; the wording trap is the thing to test next week.',
        ],
        'subjects' => [
            ['slug' => 'maths', 'topics' => ['P8', 'A17'], 'secure' => [['ref' => 'A17', 'evidence' => 'starter 4/4 twice, unaided']],
             'developing' => [['ref' => 'P8', 'evidence' => '5/6 then 3/3 on complement items after prompting once']], 'fragile' => [], 'gaps' => [],
             'retention' => 'A17 retrieved unaided after a four-day gap; P8 not yet re-tested after a delay.',
             'independence' => 'Five of six items unaided on Monday; three of three on Thursday after one prompt on Monday.',
             'readiness' => 'progress_with_retrieval', 'why' => 'The method is there; the wording needs one unaided run before tree diagrams.'],
            ['slug' => 'english-literature', 'topics' => ['P1.01'], 'secure' => [], 'developing' => [['ref' => 'P1.01', 'evidence' => 'one quotation retrieved, context not yet']],
             'fragile' => [], 'gaps' => [], 'retention' => 'One quotation retrieved unaided; the context point was prompted.',
             'independence' => 'Paragraph structure held with the frame; the frame is still needed.',
             'readiness' => 'consolidate', 'why' => 'Context before a second poem.'],
        ],
        'cross_subject' => [
            ['key' => 'model-then-immediate-practice', 'observation' => 'A worked example followed at once by a matched item transfers in both subjects.',
             'evidence' => 'Maths Q2→Q3 and Q5→Q6; English modelled sentence → her own in the same form.', 'sessions' => [],
             'judgement' => 'emerging', 'implication' => 'Default to model-then-match in every subject next week.'],
        ],
        'helped' => [['method' => 'worked_example', 'evidence' => 'Both maths sessions: unaided success on the item after the modelled one.',
                      'subjects' => ['maths', 'english-literature'], 'improved' => 'First-try transfer on the matched item.', 'verdict' => 'use_and_test']],
        'hindered' => [['issue' => 'Four-line problem statements', 'evidence' => 'Both misreads this week were on the longest statements.',
                        'confidence' => 'emerging', 'change' => 'Shorten every worded item to two lines.']],
        'retention' => [['ref' => 'A17', 'subject' => 'maths', 'verdict' => 'retaining', 'evidence' => 'Retrieved unaided on Monday and Thursday.', 'action' => 'none']],
        'confidence' => [['belief' => 'Called P8 easy before Q4', 'performance' => 'Misread Q4', 'meaning' => 'Confidence ran ahead of reading.',
                          'response' => 'Ask her to restate the question before answering.', 'evidence' => 'Review of session 1, confidence entry on P8.']],
        'independence' => ['trend' => 'more', 'prompts_evidence' => 'One prompt on Monday, none on Thursday.',
                           'fade' => [['support' => 'The complement prompt', 'why' => 'Not needed on Thursday.']],
                           'keep' => [['support' => 'The paragraph frame', 'why' => 'Still needed in English.']],
                           'reasoning' => 'Fade what was not needed twice; keep what was.'],
        'errors' => [['error_type' => 'instruction_misread', 'examples' => ['P8 Q4 "at least one" read as "exactly one"', 'English task read as one paragraph not two'],
                      'explanation' => 'Long statements are skimmed; the arithmetic and the writing that follow are sound.',
                      'response' => 'Restate the instruction aloud before starting any worded item.']],
        'hypotheses' => [['signal_key' => 'model-then-immediate-practice',
                          'hypothesis' => 'Transfer holds when the matched item comes after a two-item gap.',
                          'evidence' => 'It has only been tested with the matched item immediately after.',
                          'how' => 'Give the matched item two items after the modelled one, in two sessions.',
                          'collect' => ['first-try correct or not', 'whether she referred back to the model'],
                          'supports' => 'First-try correct in both sessions.', 'challenges' => 'Needs a prompt in either.']],
        'learner_voice' => ['groups' => [['theme' => 'Seeing the method', 'quotes' => ['oh, it is one minus']]],
                            'perception_vs_evidence' => 'She names the method once she is prompted; the evidence says she has it.'],
        'model' => [['key' => 'model-then-match', 'change' => 'new', 'statement' => 'She transfers a modelled step to a matched item on the first try.',
                     'signal_keys' => ['model-then-immediate-practice']]],
        'priorities' => [
            ['rank' => 1, 'priority' => 'Complement wording unaided', 'why' => 'The one recurring error.', 'evidence' => 'Two misreads this week.', 'action' => 'Three items Monday.'],
            ['rank' => 2, 'priority' => 'Tree diagrams', 'why' => 'P8 is ready to build on.', 'evidence' => '3/3 Thursday.', 'action' => 'Teach P6-P7 Thursday.'],
        ],
        'week_plans' => [
            ['subject_slug' => 'maths', 'next_content' => 'P6-P7 tree diagrams after a P8 wording check', 'retrieve_first' => 'A17 ×2 no notes; P8 ×3 complement',
             'reteach_if' => 'fewer than 2/3 on P8 wording → complement before trees', 'approach' => 'one step modelled → one item practised, ×3',
             'scaffolding' => 'organiser headings only', 'independent' => 'the third item of each set', 'check_for' => 'restates the question before answering',
             'exit_check' => 'two-branch tree unaided', 'watch_for' => 'blank count on the worded item', 'refs' => ['P8', 'P6-P7', 'A17']],
            ['subject_slug' => 'english-literature', 'next_content' => 'Ozymandias context, then a second poem', 'retrieve_first' => 'the quotation, unaided',
             'approach' => 'model one context sentence, then hers', 'scaffolding' => 'the paragraph frame', 'independent' => 'the second paragraph',
             'check_for' => 'context linked to a quotation', 'exit_check' => 'one what-how-why paragraph', 'watch_for' => 'whether the frame is still needed', 'refs' => ['P1.01']],
        ],
        'architecture' => ['sufficient_evidence' => true, 'stages' => [
            ['stage' => 'start', 'why' => 'Retrieval first — three items before anything new.'],
            ['stage' => 'model', 'why' => 'evidence this week: session 1 — the modelled step transferred.'],
            ['stage' => 'exit', 'why' => 'Never blank — the exit ticket counts blanks against attempts.'],
        ]],
        'stop_start_continue' => ['stop' => [['practice' => 'Four-line worded items', 'method' => 'long_text', 'why' => 'Both misreads were on them.']],
                                  'start' => [['practice' => 'Restating the instruction aloud', 'why' => 'Targets the misread directly.']],
                                  'continue' => [['practice' => 'Model then matched item', 'method' => 'worked_example', 'why' => 'Transferred every time.']]],
        'observe' => [['key' => 'restates-before-answering', 'look_for' => 'Whether she restates a worded question before starting it.', 'why' => 'The misreads.']],
        'big_picture' => ['coverage' => 'Two new topics touched.', 'secure' => 'A17 holds.', 'fragile' => 'P8 wording.', 'retention' => 'Good on A17; untested on P8.',
                          'application' => 'Not yet applied to a worded tree problem.', 'independence' => 'More than last week.', 'pace' => 'On plan.',
                          'coverage_vs_mastery' => 'Mastery ahead of coverage this week.', 'efficiency' => 'Two sessions produced two movements.'],
        'planner' => ['learning_priority' => 'Complement wording unaided', 'teaching_priority' => 'Model then matched item, everywhere',
                      'retrieve' => 'A17, P8 wording, the quotation', 'reteach' => 'Complement wording if under 2/3', 'ready' => 'P6-P7 trees',
                      'use_more' => 'Worked example then matched item', 'use_less' => 'Long worded statements',
                      'test' => 'Matched item after a two-item gap', 'watch' => 'Restating before answering', 'success' => '3/3 complement items unaided by Thursday'],
        'collect_next_week' => ['Blank count on every worded item', 'Whether the frame was used in English'],
    ];
    return array_replace($base, $over);
}

putenv('TRACKER_NOW=2026-09-26 06:00');
$dbPath = sys_get_temp_dir() . '/synthesis-test-' . getmypid() . '.db';
@unlink($dbPath);
$store = new Store($dbPath);
register_shutdown_function(static function () use ($dbPath) {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
});

echo "== schema ==\n";
check('a fresh database reaches schema 13', $store->meta('schema_version'), '13');
$tables = array_column($store->db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(), 'name');
foreach (['weekly_syntheses', 'week_plans', 'learner_model'] as $t) {
    check("table $t exists", in_array($t, $tables, true), true);
}
$cols = array_column($store->db->query('PRAGMA table_info(review_signals)')->fetchAll(), 'name');
foreach (['test_design_json', 'test_set_by', 'test_week', 'opened_by', 'opened_week', 'promoted_from_json'] as $c) {
    check("review_signals has $c", in_array($c, $cols, true), true);
}
$info = array_values(array_filter($store->db->query('PRAGMA table_info(review_signals)')->fetchAll(), static fn($c) => $c['name'] === 'opened_session'));
check('opened_session is nullable', (int) $info[0]['notnull'], 0);
check('the stage enum carries orientate, model and exit', array_diff(['orientate', 'model', 'exit'], REVIEW_STAGE_KEYS), []);
check('no _old table is left behind', count(array_filter($tables, static fn($t) => str_ends_with($t, '_old'))), 0);

// ---- fixture -------------------------------------------------------------
call($store, 'tracker_create_subject', ['slug' => 'maths', 'name' => 'GCSE Mathematics', 'strands' => ['A' => 'Algebra', 'P' => 'Probability'],
    'topics' => [
        ['ref' => 'A17', 'name' => 'Solving linear equations', 'strand' => 'A', 'status' => 'secure'],
        ['ref' => 'P8', 'name' => 'Combined events', 'strand' => 'P', 'status' => 'developing'],
        ['ref' => 'P6-P7', 'name' => 'Tree diagrams', 'strand' => 'P', 'status' => 'notstarted'],
    ]]);
call($store, 'tracker_create_subject', ['slug' => 'english-literature', 'name' => 'GCSE English Literature', 'strands' => ['P' => 'Poetry'],
    'topics' => [['ref' => 'P1.01', 'name' => 'Ozymandias', 'strand' => 'P', 'status' => 'developing']]]);
call($store, 'tracker_create_subject', ['slug' => 'spanish', 'name' => 'GCSE Spanish', 'strands' => ['T' => 'Themes'],
    'topics' => [['ref' => 'T1', 'name' => 'Free time', 'strand' => 'T', 'status' => 'developing']]]);
call($store, 'tracker_set_timetable', ['valid_from' => '2026-09-14', 'note' => 'synthesis fixture', 'blocks' => [
    ['block_key' => 3, 'weekday' => 1, 'start' => '09:15', 'end' => '10:30', 'kind' => 'teach', 'label' => 'Maths — new topic', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 5, 'weekday' => 1, 'start' => '11:00', 'end' => '12:00', 'kind' => 'teach', 'label' => 'English Literature', 'subjects' => ['english-literature'], 'tracking' => 'evidence'],
    ['block_key' => 20, 'weekday' => 3, 'start' => '09:45', 'end' => '10:00', 'kind' => 'spanish', 'label' => 'Spanish — vocab', 'subjects' => ['spanish'], 'tracking' => 'evidence'],
    ['block_key' => 22, 'weekday' => 4, 'start' => '09:15', 'end' => '10:20', 'kind' => 'teach', 'label' => 'Deep block — Maths', 'subjects' => ['maths'], 'tracking' => 'evidence'],
]]);

// The week under synthesis: 2026-W39, 21–27 September. Two maths sessions with reviews, one English.
$s1 = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-21', 'block_key' => 3, 'duration_minutes' => 70,
    'summary' => 'P8 combined events; exit ticket 5 of 6.',
    'updates' => [['ref' => 'P8', 'evidence' => 'exit ticket 5/6 unaided'], ['ref' => 'A17', 'evidence' => 'starter 4/4', 'retrieval_outcome' => 'correct']],
    'review' => review()]));
$r2 = review();
$r2['one_sentence'] = 'Complement wording 3 of 3 unaided; the matched item transferred again.';
$r2['signals'][0]['strength'] = 'emerging';
$r2['signals'][0]['evidence'] = 'Q5 modelled, Q6 unaided in the same form, second session running.';
$r2['errors'][] = ['ref' => 'P8', 'error_type' => 'instruction_misread', 'what' => 'Read the four-line worded item as one event, not two.',
    'why_type' => 'Skimmed the statement; the arithmetic was right for what she read.', 'response' => 'Shorten worded items to two lines.'];
$r2['learner_voice'] = [['quote' => 'I can do these now', 'context' => 'after Q6']];
$s2 = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-24', 'block_key' => 22, 'duration_minutes' => 65,
    'summary' => 'P8 complement wording, then tree diagram start.',
    'updates' => [['ref' => 'P8', 'evidence' => 'complement items 3/3 unaided'], ['ref' => 'A17', 'evidence' => 'starter 3/3', 'retrieval_outcome' => 'correct']],
    'review' => $r2]));
$re = review(['topic_refs' => ['P1.01'], 'progress' => [['ref' => 'P1.01', 'status_seen' => 'developing', 'evidence' => 'One quotation retrieved, context prompted twice.',
    'implication' => 'Context before a second poem.']], 'errors' => [], 'retention' => [], 'confidence' => [], 'next_what' => ['consolidate' => ['P1.01']],
    'learner_voice' => [], 'signals' => [['key' => 'model-then-immediate-practice', 'kind' => 'teaching_method',
        'statement' => 'One worked example followed immediately by a matched item transfers on the first try.', 'strength' => 'emerging',
        'direction' => 'supports', 'evidence' => 'Modelled context sentence, then her own in the same shape, unaided.']],
    'process' => [['area' => 'avoidance', 'basis' => 'observed', 'evidence' => '0 blanks in 4 questions.', 'interpretation' => 'No avoidance today.', 'implication' => 'Keep the four questions.']],
    'planner' => ['priority' => 'Context', 'start_with' => 'The quotation', 'teach_using' => 'One modelled sentence', 'avoid' => 'Two poems at once',
        'check_whether' => 'Context links to a quotation', 'success' => 'One paragraph unaided']]);
$s3 = logged_id(call($store, 'tracker_log_session', ['subject' => 'english-literature', 'date' => '2026-09-21', 'block_key' => 5, 'duration_minutes' => 60,
    'summary' => 'Ozymandias: quotation retrieved, context prompted.',
    'updates' => [['ref' => 'P1.01', 'evidence' => 'quotation retrieved; context prompted twice', 'retrieval_outcome' => 'retry']],
    'review' => $re]));
call($store, 'tracker_log_session', ['subject' => 'spanish', 'date' => '2026-09-23', 'block_key' => 20, 'duration_minutes' => 15, 'summary' => 'Spanish vocab set, ten words.']);
check('three taught sessions logged with reviews', $s1 > 0 && $s2 > 0 && $s3 > 0, true);
$sig = $store->signalByKey('maths', 'model-then-immediate-practice');
check('the maths signal is emerging after two sessions', $sig['strength'], 'emerging');
check('its review-set test records the setter', $sig['test_set_by'], 'review');

echo "\n== A. the inputs opener ==\n";
$in = call($store, 'tracker_week_synthesis_inputs', ['week' => '2026-W39']);
contains('it opens on the week and the week it plans', $in, 'Weekly synthesis inputs — 2026-W39** (21 September 2026 to 27 September 2026) · plans for 2026-W40');
contains('lists the taught sessions with review stages', $in, "- session $s1 · maths · 2026-09-21 · block 3 · 70 min · review v1 draft");
lacks('the Spanish maintenance slot is not a taught session', $in, 'spanish · 2026-09-23');
contains('renders the reviews in full', $in, '=== LESSON REVIEW — 2026-09-21 — maths — block 3 (teach) — 70 min ===');
contains('lists open signals with their trail', $in, '[emerging, 2 sessions] teaching_method model-then-immediate-practice');
contains('and the English signal separately', $in, 'english-literature');
contains('says no test was due this week', $in, '- none were set for 2026-W39.');
contains('groups the errors by type with counts', $in, '- instruction_misread × 3:');
contains('lists the retrieval rows touched', $in, 'maths topic A17: next due');
contains('says no attempt was sat', $in, 'none sat this week; no grade may be named in Part 18');
contains('says the model is empty', $in, 'empty; Part 12 opens rows with change: new');
contains('says there is no previous synthesis', $in, 'none; Part 20 must be omitted');
contains("lists next week's timetable by subject", $in, '- maths: Mon #3 teach, Thu #22 teach — taught blocks, a Part 14 plan is required');
contains('and the Spanish slot as not taught', $in, '- spanish: Wed #20 spanish — no taught block');
contains('and the study-principle headings', $in, 'One instruction per message');
$default = call($store, 'tracker_week_synthesis_inputs', []);
contains('with no week it takes the week that ended most recently', $default, 'Weekly synthesis inputs — 2026-W38');

echo "\n== B. the refusals ==\n";
$base = ['week' => '2026-W39', 'stage' => 'draft', 'written_by' => 'routine'];
$syn  = synthesis(['cross_subject' => []]);
$syn['glance']['picture'] = 'This picture is far too short for the part. It has only two sentences in it, whatever their length.';
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('Part 1 needs four to six sentences', $reply, 'REFUSED: sections.glance.picture is 2 sentences; it must be 4 to 6');
$syn = synthesis(['cross_subject' => []]);
array_pop($syn['subjects']);
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('Part 2 must cover every taught subject', $reply, 'sections.subjects has no entry for english-literature');
$syn = synthesis(['cross_subject' => []]);
$syn['subjects'][] = ['slug' => 'spanish', 'topics' => [], 'retention' => 'not this week', 'independence' => 'not this week', 'readiness' => 'consolidate', 'why' => 'no taught session'];
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('and only the taught subjects', $reply, 'is for spanish, which had no taught session');
$syn = synthesis();
$syn['cross_subject'][0]['sessions'] = [$s1, $s2];
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('cross-subject needs two subjects', $reply, 'claims emerging across subjects but its sessions are all in maths');
$syn = synthesis(['cross_subject' => []]);
$syn['errors'][] = ['error_type' => 'calculation', 'examples' => ['none really'], 'explanation' => 'Claimed with no rows behind it at all.', 'response' => 'Nothing.'];
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('a recurring error needs two rows', $reply, 'names calculation as a recurring error, but only 0 error rows of that type were recorded');
$syn = synthesis(['cross_subject' => []]);
$syn['learner_voice']['groups'][0]['quotes'] = ['it is one minus, I think'];
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('a quote must match a review exactly', $reply, 'quotes "it is one minus, I think", which no lesson review of 2026-W39 recorded');
$syn = synthesis(['cross_subject' => []]);
$syn['big_picture']['coverage'] = 'On track for grade 6.';
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('no grade without a graded paper', $reply, 'sections.big_picture.coverage names a grade, and no graded paper was sat');
$syn = synthesis(['cross_subject' => []]);
$syn['retention'][] = ['ref' => 'P6-P7', 'subject' => 'maths', 'verdict' => 'retaining', 'evidence' => 'never asked', 'action' => 'none'];
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('a retention verdict needs retrieval evidence', $reply, 'judges retention on maths P6-P7, which has no retrieval record');
$syn = synthesis(['cross_subject' => []]);
$syn['retention'][] = ['ref' => 'P1.01', 'subject' => 'english-literature', 'verdict' => 'retaining', 'evidence' => 'retrieved after prompting', 'action' => 'none'];
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('retaining is refused on a wrong streak', $reply, 'calls P1.01 retaining, but its retrieval record shows');
$syn = synthesis(['cross_subject' => []]);
array_pop($syn['week_plans']);
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('every subject taught next week needs a plan', $reply, 'sections.week_plans has no plan for english-literature');
$syn = synthesis(['cross_subject' => []]);
$syn['change'] = [['category' => 'improved', 'what' => 'Something improved.', 'evidence' => 'Made up entirely.']];
$syn['changes_worked'] = 'There was nothing to compare against.';
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('Part 20 is refused with no previous synthesis', $reply, 'sections.change is refused: no previous synthesis exists');
$syn = synthesis(['cross_subject' => []]);
$syn['architecture']['stages'][0]['why'] = 'Because it seemed a good idea at the time.';
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('a stage must name a principle or the evidence', $reply, 'must name a study-principle heading');
$syn = synthesis(['cross_subject' => []]);
$syn['priorities'][1]['rank'] = 3;
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('ranks must be contiguous', $reply, 'sections.priorities[1].rank is 3; ranks must run 1..2');
$syn = synthesis(['cross_subject' => []]);
$syn['hypotheses'][0]['signal_key'] = 'no-such-signal';
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('a hypothesis names an existing or opened signal', $reply, 'is not an existing signal and is not opened by this synthesis');
$syn = synthesis(['cross_subject' => []]);
$syn['model'][0]['change'] = 'strengthened';
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('a model row cannot be strengthened before it exists', $reply, "model row 'model-then-match' does not exist; open it with change: new");
check('none of that saved anything', $store->weekSynthesis('2026-W39'), null);
check('nor wrote a plan', $store->weekPlansFor('2026-W40'), []);

echo "\n== C. one save writes the decisions back ==\n";
$syn = synthesis();
$syn['cross_subject'][0]['sessions'] = [$s1, $s2, $s3];
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('the synthesis saves as version 1 draft', $reply, 'Saved version 1 (draft) for 2026-W39');
contains('the cross-subject signal is promoted from both subjects', $reply, 'Promoted model-then-immediate-practice to cross-subject #');
contains('naming the subjects it spans', $reply, '(english-literature, maths)');
contains('the watch signal is opened for next week', $reply, 'Opened watch restates-before-answering #');
contains('the test is set on the signal for next week', $reply, 'Test set on #');
contains('the model row is opened as a hypothesis, whatever its signals', $reply, 'Model model-then-match: opened as hypothesis');
contains('the plans are written per subject', $reply, 'Plan for maths (2026-W40): P6-P7 tree diagrams after a P8 wording check');
contains('and for English', $reply, 'Plan for english-literature (2026-W40)');
$cross = $store->signalByKey(null, 'model-then-immediate-practice');
check('the cross-subject twin exists', $cross !== null, true);
check('with the per-subject ids it was promoted from', count($cross['promoted_from']), 2);
check('at the strength the evidence allows', $cross['strength'], 'established');
check('opened by the synthesis', $cross['opened_by'], 'synthesis');
$maths = $store->signalByKey('maths', 'model-then-immediate-practice');
check('the maths signal carries the test for W40', [$maths['test_set_by'], $maths['test_week']], ['synthesis', '2026-W40']);
check('with its design', $maths['test_design']['supports'] ?? null, 'First-try correct in both sessions.');
$watch = $store->signalByKey(null, 'restates-before-answering');
check('the watch has no session behind it', $watch['opened_session'], null);
$model = $store->learnerModelRow('model-then-match');
check('a new row starts as a hypothesis', $model['status'], 'hypothesis');
check('with one history entry', count($model['history']), 1);
check('two week plans exist for W40', count($store->weekPlansFor('2026-W40')), 2);
check('the routine stamps last_synthesis_week', $store->meta('last_synthesis_week'), '2026-W39');
$reply = call($store, 'tracker_save_week_synthesis', $base + ['sections' => $syn]);
contains('a second save needs a note', $reply, 'a new version needs a note saying what changed');

echo "\n== D. the queue prints this_week ==\n";
putenv('TRACKER_NOW=2026-09-28 09:00');
$q = call($store, 'tracker_review_queue', ['subject' => 'maths']);
contains('this_week names the synthesis and the week it plans', $q, '### this_week  (synthesis 2026-W39 v1, for 2026-W40)');
contains('with the next content', $q, 'next content: P6-P7 tree diagrams after a P8 wording check');
contains('and the reteach condition', $q, 'reteach if: fewer than 2/3 on P8 wording');
contains('and the test set for the week', $q, 'TEST THIS WEEK: #' . $maths['id'] . ' model-then-immediate-practice — Give the matched item two items after the modelled one');
lacks('and no stale line while the week matches', $q, 'stale: true');
$posRev = strpos($q, '### last_review');
$posWk  = strpos($q, '### this_week');
check('this_week comes after last_review', $posRev !== false && $posWk > $posRev, true);
$plan = $store->weekPlan('2026-W40', 'maths');
check('the queue recorded the read', $plan['read_at'] !== null, true);
$qs = call($store, 'tracker_review_queue', ['subject' => 'spanish']);
lacks('a subject with no plan has no this_week block', $qs, '### this_week');
putenv('TRACKER_NOW=2026-10-06 09:00');
$q = call($store, 'tracker_review_queue', ['subject' => 'maths']);
contains('a week later with no new synthesis the plan is stale', $q, 'stale: true — this plan was written for 2026-W40');
putenv('TRACKER_NOW=2026-09-28 09:00');

echo "\n== E. answering the test, and the audit ==\n";
$r4 = review();
$r4['signals'][0] = ['key' => 'model-then-immediate-practice', 'kind' => 'teaching_method',
    'statement' => 'One worked example followed by a matched item transfers, even after a gap.', 'strength' => 'emerging', 'direction' => 'supports',
    'evidence' => 'Matched item given two items after the model: first-try correct.'];
$r4['learner_voice'] = [];
$s4 = logged_id(call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-28', 'block_key' => 3, 'duration_minutes' => 70,
    'summary' => 'P8 wording check, then trees.',
    'updates' => [['ref' => 'P8', 'evidence' => 'complement items 3/3'], ['ref' => 'A17', 'evidence' => 'starter 4/4', 'retrieval_outcome' => 'correct']],
    'review' => $r4]));
$due = $store->testsDue('2026-W40');
$hit = array_values(array_filter($due, static fn(array $t): bool => $t['signal']['id'] === $maths['id']));
check('the test is answered by the session that cited the key', [$hit[0]['answered'], $hit[0]['by_session']], [true, $s4]);
$maths = $store->signalByKey('maths', 'model-then-immediate-practice');
check('the review did not overwrite the synthesis-set test', $maths['test_set_by'], 'synthesis');
$lr = call($store, 'tracker_get_lesson_review', ['subject' => 'maths', 'session_id' => $s4]);
contains('the review drift says the synthesis test was answered', $lr, 'Synthesis test #' . $maths['id'] . ' model-then-immediate-practice due 2026-W40: answered by this session');
$sig = call($store, 'tracker_signals', ['subject' => 'maths']);
contains('tracker_signals prints who set the test and for which week', $sig, 'test: Give the matched item two items after the modelled one, in two sessions. (set by synthesis for 2026-W40)');
contains('and its design', $sig, 'supports if: First-try correct in both sessions.');
contains('and the cross-subject twin with its opener', $sig, '(cross-subject) teaching_method model-then-immediate-practice');
$aq = call($store, 'tracker_review_audit_queue', ['subject' => 'maths']);
lacks('an answered synthesis test is not flagged', $aq, 'synthesis_test_unanswered');
putenv('TRACKER_NOW=2026-10-05 09:00');
$store->db->exec("UPDATE review_signals SET test_week = '2026-W40', test_set_by = 'synthesis', next_test = 'an unanswered test of some kind' WHERE id = " . $watch['id']);
$aq = call($store, 'tracker_review_audit_queue', ['subject' => 'maths']);
contains('an unanswered synthesis test past its week is its own flag', $aq, '[synthesis_test_unanswered] signal #' . $watch['id'] . ' restates-before-answering: the test the synthesis set for 2026-W40');
putenv('TRACKER_NOW=2026-09-28 09:00');

echo "\n== F. the parent's test and promotion ==\n";
$reply = call($store, 'tracker_update_signal', ['id' => $maths['id'], 'next_test' => 'Parent: try it with a three-item gap instead.',
    'test_design' => ['how' => 'Three items between the model and the match.', 'collect' => ['first-try correct'], 'supports' => 'Correct in two sessions.',
    'challenges' => 'A prompt in either.'], 'test_week' => '2026-W41']);
contains('the parent sets a test from chat', $reply, 'test set by the parent for 2026-W41 with a design');
$maths = $store->signalByKey('maths', 'model-then-immediate-practice');
check('and it is the parent\'s', [$maths['test_set_by'], $maths['test_week']], ['parent', '2026-W41']);
$reply = call($store, 'tracker_update_signal', ['id' => $watch['id'], 'promote' => true]);
contains('a cross-subject signal cannot be promoted again', $reply, 'is already cross-subject');
$eng = $store->signalByKey('english-literature', 'model-then-immediate-practice');
$reply = call($store, 'tracker_update_signal', ['id' => $eng['id'], 'promote' => true]);
contains('promoting a key whose twin exists is refused by the unique key', $reply, 'already exists');

echo "\n== G. re-versioning and the second week ==\n";
putenv('TRACKER_NOW=2026-10-03 06:00');
$syn2 = synthesis();
$syn2['cross_subject'] = [];
$syn2['hypotheses'] = [['signal_key' => 'model-then-immediate-practice', 'hypothesis' => 'Routine tries to reset the parent\'s test.',
    'evidence' => 'The gap test transferred in W40.', 'how' => 'A four-item gap next.', 'collect' => ['first-try correct'],
    'supports' => 'Correct twice running.', 'challenges' => 'A prompt in either session.']];
$syn2['subjects'] = [$syn2['subjects'][0]];
$syn2['subjects'][0]['topics'] = ['P8', 'A17'];
$syn2['errors'] = [];
$syn2['model'] = [['key' => 'model-then-match', 'change' => 'strengthened', 'statement' => 'She transfers a modelled step to a matched item, even after a gap.',
    'signal_keys' => ['model-then-immediate-practice']]];
$syn2['learner_voice'] = ['groups' => []];
$syn2['confidence'] = [];
$syn2['change'] = [['category' => 'change_worked', 'what' => 'The gap test transferred.', 'evidence' => 'Session ' . $s4 . ' answered it first try.']];
$syn2['changes_worked'] = 'Yes: the matched item transferred after a two-item gap, as the hypothesis predicted.';
$syn2['week_plans'] = [$syn2['week_plans'][0], $syn2['week_plans'][1]];
$reply = call($store, 'tracker_save_week_synthesis', ['week' => '2026-W40', 'stage' => 'draft', 'written_by' => 'routine', 'sections' => $syn2]);
contains('the second week saves', $reply, 'Saved version 1 (draft) for 2026-W40');
contains('and leaves the parent-set test in place', $reply, 'Left in place: test on #' . $maths['id'] . ' model-then-immediate-practice was set by the parent');
contains('the model row is strengthened to what its signals allow', $reply, 'Model model-then-match: hypothesis → established (strengthened)');
$syn2b = $syn2;
unset($syn2b['change'], $syn2b['changes_worked']);
$reply = call($store, 'tracker_save_week_synthesis', ['week' => '2026-W40', 'stage' => 'parent', 'written_by' => 'chat', 'sections' => $syn2b, 'note' => 'try without part 20']);
contains('Part 20 is required when a previous synthesis exists', $reply, 'sections.change is required: a synthesis for 2026-W39 exists');
$reply = call($store, 'tracker_save_week_synthesis', ['week' => '2026-W40', 'stage' => 'parent', 'written_by' => 'chat', 'sections' => $syn2, 'note' => 'Parent confirmed the reading of the gap test.']);
contains('the parent saves version 2', $reply, 'Saved version 2 (parent) for 2026-W40');
$reply = call($store, 'tracker_save_week_synthesis', ['week' => '2026-W40', 'stage' => 'draft', 'written_by' => 'routine', 'sections' => $syn2, 'note' => 'late routine']);
contains('a draft cannot land over a parent version', $reply, 'already has a parent version (version 2');
$in = call($store, 'tracker_week_synthesis_inputs', ['week' => '2026-W40']);
contains('the inputs say a parent version exists', $in, 'The parent has reviewed it; a routine draft cannot be saved over it');
contains('and carry the previous planner for Part 20', $in, "- 2026-W39 v1 (draft). Part 20 is required.");
contains('and its hypotheses', $in, 'hypothesis model-then-immediate-practice: Transfer holds when the matched item comes after a two-item gap.');

echo "\n== H. reading it back ==\n";
$g = call($store, 'tracker_get_week_synthesis', ['week' => '2026-W39']);
contains('the synthesis renders in the twenty-part layout', $g, '=== WEEKLY LEARNING SYNTHESIS — 2026-W39 ===');
contains('with the sessions it read', $g, "Sessions read: $s1 maths 2026-09-21 (draft v1)");
contains('and the planner', $g, 'learning_priority: Complement wording unaided');
contains('the snapshot names the previous synthesis', $g, 'previous synthesis: none');
contains('and the drift reads the answer from its own hypotheses, whatever was re-set since', $g, "Tests set for 2026-W40: 1 of 1 answered");
contains('by the session that cited the key', $g, "model-then-immediate-practice answered by session $s4");
contains('and that the plans were read', $g, 'Week plans for 2026-W40: 1 of 2 read by the queue');
contains('and how the model moved', $g, 'Learner model since: model-then-match hypothesis → established');
$po = call($store, 'tracker_get_week_synthesis', ['week' => '2026-W40', 'planner_only' => true]);
contains('planner_only shows the latest version', $po, 'version 2 of 2 · parent');
lacks('and nothing else', $po, '7. CONFIDENCE');
$list = call($store, 'tracker_list_week_syntheses', []);
contains('the list has one line per week', $list, '- 2026-W40 · v2 parent');
contains('with tests answered', $list, '- 2026-W39 · v1 draft · tests 1/1 answered');
$lm = call($store, 'tracker_learner_model', []);
contains('the learner model lists the row with its status', $lm, '- [established] model-then-match:');
contains('and the signals it rests on', $lm, 'rests on: model-then-immediate-practice (');
contains('and its history by week', $lm, '2026-W39 new → hypothesis');
$wr = call($store, 'tracker_week_report', ['week' => '2026-W40']);
contains('the week report says the synthesis is saved', $wr, "SYNTHESIS\n- saved: version 2 (parent");
contains("and lists last week's decisions", $wr, "LAST WEEK'S DECISIONS\n- from the synthesis of 2026-W39 v1:");
contains('with the test answered', $wr, "answered by session $s4");
contains('and puts the question to the parent', $wr, 'ask the parent: did the changes work?');
$reply = call($store, 'tracker_save_weekly_review', ['week' => '2026-W40', 'stage' => 'reviewed', 'written_by' => 'chat', 'sections' => [
    'held' => 'The gap test transferred as predicted.', 'slipped' => 'Nothing slipped this week.', 'next' => 'Tree diagrams, then context.',
    'carry_forward' => ['maths' => 'trees', 'english-literature' => 'context', 'spanish' => 'vocab'], 'rotation_next' => 'maths',
    'decisions' => [['kind' => 'synthesis_verdict', 'ref' => 'model-then-immediate-practice', 'decision' => 'change_worked', 'note' => 'first try after the gap']]]]);
contains('the weekly review records the parent\'s verdict', $reply, 'Saved version 1 (reviewed) for 2026-W40');
$reply = call($store, 'tracker_save_weekly_review', ['week' => '2026-W40', 'stage' => 'reviewed', 'written_by' => 'chat', 'sections' => [
    'held' => 'The gap test transferred as predicted.', 'slipped' => 'Nothing slipped this week.', 'next' => 'Tree diagrams, then context.',
    'carry_forward' => ['maths' => 'trees'], 'rotation_next' => 'maths',
    'decisions' => [['kind' => 'synthesis_verdict', 'ref' => 'no-such-key', 'decision' => 'mixed']]]]);
contains('a verdict must name a signal key or the week', $reply, 'is neither a signal key nor \'week\'');

echo "\n== I. the pages ==\n";
$public = render_week_page($store, '2026-W39', false);
lacks('the public week page has no synthesis', $public, 'The learning synthesis');
$parent = render_week_page($store, '2026-W39', true);
contains('the parent week page has the synthesis', $parent, 'The learning synthesis');
contains('with the planner pinned', $parent, 'pinned; what next week reads');
contains('and the decided / happened table on the following week', render_week_page($store, '2026-W40', true), 'Decided (2026-W39)');
$v1 = render_week_page($store, '2026-W40', true, null, 1);
contains('?sv=1 renders the draft', $v1, 'Version 1 of 2');
$learner = render_learner($store, false);
contains('/learner is gated', $learner, 'Sign in to read it');
$learner = render_learner($store, true);
contains('and lists the model for the parent', $learner, 'model-then-match');
contains('with its history strip', $learner, '2026-W40 strengthened → established');
$subjectPublic = render_subject($store, $store->getSubject('maths'), false);
lacks('the public subject page has no plan card', $subjectPublic, "This week's plan");
$subjectParent = render_subject($store, $store->getSubject('maths'), true);
contains('the parent subject page carries the week plan', $subjectParent, "This week's plan · 2026-W40");
contains('with the read tick', $subjectParent, 'read by the queue');
$signals = render_signals($store, true);
contains('the signals page shows who set each test (the parent version of W40 re-set it)', $signals, 'set by parent for 2026-W41');
contains('and the design', $signals, 'supports if: Correct twice running.');

echo "\n";
if ($failures) {
    echo "SYNTHESIS FAIL — $failures of $checks checks failed\n";
    exit(1);
}
echo "SYNTHESIS PASS — $checks checks\n";
