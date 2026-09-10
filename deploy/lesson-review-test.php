<?php
/**
 * Acceptance tests for lesson reviews.
 *
 *   php deploy/lesson-review-test.php
 *
 * Drives the MCP tools directly against a throwaway database with the clock
 * frozen at Monday 21 September 2026. Covers the atomic log-plus-review
 * write, the whole-or-nothing section validation, the signal strength rule,
 * re-versioning and its guards, the audit queue's flags, the extensions to
 * the tools a session already calls, and the parent gate on the pages.
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

function logged_id(string $reply): int
{
    return preg_match('/Session (\d+) logged/', $reply, $m) ? (int) $m[1] : 0;
}

function sessions(Store $store, string $slug): int
{
    return count($store->listSessions($slug, 1000));
}

/** A valid review for a maths session on P8 and A17, with overrides merged over the top. */
function review(array $over = []): array
{
    $base = [
        'topic_refs'   => ['P8', 'A17'],
        'objective'    => 'Combined events: add or multiply, decided from the wording',
        'one_sentence' => 'She chose add/multiply correctly in 5 of 6 unaided but stalled on the wording of "at least one".',
        'progress' => [
            ['ref' => 'P8', 'status_seen' => 'developing',
             'evidence' => 'Q1-Q6 exit ticket: 5 of 6 correct unaided, Q4 needed a prompt on "at least one".',
             'implication' => 'One more unaided run on complement wording before the bar is claimed.'],
            ['ref' => 'A17', 'status_seen' => 'secure',
             'evidence' => 'Starter: 4 of 4 linear equations solved unaided in under 3 minutes.',
             'implication' => 'Keep in retrieval rotation; nothing to teach.'],
        ],
        'independent' => 'Q1, Q2, Q3, Q5 and Q6 of the exit ticket, all unaided, all correct.',
        'supported'   => 'Q4 after one prompt: "what is the opposite of at least one?"',
        'errors' => [
            ['ref' => 'P8', 'error_type' => 'instruction_misread',
             'what' => 'Read "at least one" as "exactly one" in Q4 and multiplied the single branch.',
             'why_type' => 'The arithmetic that followed was correct for the event she had in mind.',
             'response' => 'Open the next session with three complement-wording items before any new content.'],
        ],
        'retention' => [
            'retrieved'     => [['ref' => 'A17', 'evidence' => 'starter, 4/4 unaided']],
            'prompted'      => [],
            'not_retrieved' => [],
            'schedule'      => [['ref' => 'P8', 'evidence' => 'complement wording, 3 days']],
        ],
        'process' => [
            ['area' => 'avoidance', 'basis' => 'observed',
             'evidence' => '0 blanks in 6 exit-ticket questions; every question attempted.',
             'interpretation' => 'No avoidance on this topic today.',
             'implication' => 'Keep the exit ticket at six items.'],
            ['area' => 'self_correction', 'basis' => 'observed',
             'evidence' => 'Q4: after the prompt she said "oh, it is one minus" and re-did it herself.',
             'interpretation' => 'The prompt unlocked a method she already had.',
             'implication' => 'Prompt with a question, not a method, next time.'],
        ],
        'helped'   => [['method' => 'worked_example', 'effect' => 'helpful',
                        'evidence' => 'After the one worked example on Q2 she did Q3 unaided in the same form.']],
        'hindered' => [['method' => 'long_text', 'effect' => 'difficulty',
                        'evidence' => 'The four-line problem statement in Q4 is where the misread happened.']],
        'signals' => [
            ['key' => 'model-then-immediate-practice', 'kind' => 'teaching_method',
             'statement' => 'One worked example followed immediately by a matched item transfers on the first try.',
             'strength' => 'one_off', 'direction' => 'supports',
             'evidence' => 'Q2 modelled, Q3 unaided and correct in the same form.',
             'next_test' => 'Next session: give the matched item after a two-item gap and see whether it still transfers.'],
        ],
        'big_picture' => ['readiness' => 'progress_with_retrieval',
                          'why' => 'Method is there; the wording trap needs a retrieval check before tree diagrams.'],
        'next_what' => ['opening_retrieval' => ['P8'], 'new' => ['P6-P7'], 'misconception_check' => ['P8']],
        'next_how'  => ['stages' => [
            ['stage' => 'start', 'method' => 'retrieval_questions', 'why' => 'three complement-wording items'],
            ['stage' => 'teach', 'method' => 'diagram', 'why' => 'tree diagrams are visual first'],
        ]],
        'do_differently' => ['Shorten the problem statements to two lines.'],
        'continue'       => ['One worked example, then a matched item straight after.'],
        'watch'          => [['key' => 'at-least-one-wording', 'what_to_observe' => 'Whether "at least one" is read as the complement without a prompt.']],
        'learner_voice'  => [['quote' => 'oh, it is one minus', 'context' => 'Q4, after the prompt']],
        'planner' => [
            'priority'      => 'Complement wording on P8, then start tree diagrams',
            'start_with'    => 'Three "at least one" items, unaided',
            'teach_using'   => 'One worked tree, then a matched item',
            'avoid'         => 'Four-line problem statements',
            'check_whether' => 'She reads "at least one" as 1 − P(none) without a prompt',
            'success'       => '3 of 3 complement items unaided',
        ],
        'missing_evidence' => ['No timing recorded for the exit ticket.'],
    ];
    return array_replace($base, $over);
}

putenv('TRACKER_NOW=2026-09-21 10:00');
$dbPath = sys_get_temp_dir() . '/lesson-review-test-' . getmypid() . '.db';
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
foreach (['lesson_reviews', 'review_signals', 'review_signal_evidence', 'review_signal_events', 'review_errors'] as $t) {
    check("table $t exists", in_array($t, $tables, true), true);
}
$cols = array_column($store->db->query('PRAGMA table_info(sessions)')->fetchAll(), 'name');
check('sessions has review_required', in_array('review_required', $cols, true), true);
check('sessions has review_id', in_array('review_id', $cols, true), true);
$rules = $store->blockKindRules();
check('teach requires a review', $rules['teach']['review_required'], true);
check('retrieval does not', $rules['retrieval']['review_required'], false);
check('the fallback does not', $rules['*']['review_required'], false);

// ---- fixture -------------------------------------------------------------
call($store, 'tracker_create_subject', [
    'slug' => 'maths', 'name' => 'GCSE Mathematics', 'strands' => ['A' => 'Algebra', 'P' => 'Probability'],
    'topics' => [
        ['ref' => 'A17', 'name' => 'Solving linear equations', 'strand' => 'A', 'status' => 'secure'],
        ['ref' => 'P8', 'name' => 'Combined events', 'strand' => 'P', 'status' => 'developing'],
        ['ref' => 'P6-P7', 'name' => 'Tree diagrams', 'strand' => 'P', 'status' => 'notstarted'],
        ['ref' => 'N4', 'name' => 'HCF and LCM', 'strand' => 'A', 'status' => 'gap'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'spanish', 'name' => 'GCSE Spanish', 'strands' => ['T' => 'Themes'],
    'topics' => [['ref' => 'T1', 'name' => 'Free time', 'strand' => 'T', 'status' => 'developing']],
]);
call($store, 'tracker_add_resource', ['subject' => 'maths', 'resources' => [
    ['ref' => 'P8', 'title' => 'Corbettmaths — combined events', 'kind' => 'video']]]);
call($store, 'tracker_set_timetable', ['valid_from' => '2026-09-14', 'note' => 'review fixture', 'blocks' => [
    ['block_key' => 2, 'weekday' => 1, 'start' => '09:00', 'end' => '09:15', 'kind' => 'retrieval',
        'label' => 'Retrieval warm-up', 'subjects' => ['maths', 'spanish'], 'tracking' => 'evidence'],
    ['block_key' => 3, 'weekday' => 1, 'start' => '09:15', 'end' => '10:30', 'kind' => 'teach',
        'label' => 'Maths — new topic', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 20, 'weekday' => 3, 'start' => '09:45', 'end' => '10:00', 'kind' => 'spanish',
        'label' => 'Spanish — vocab', 'subjects' => ['spanish'], 'tracking' => 'evidence'],
    ['block_key' => 22, 'weekday' => 4, 'start' => '09:15', 'end' => '10:20', 'kind' => 'teach',
        'label' => 'Deep block — Maths', 'subjects' => ['maths'], 'tracking' => 'evidence'],
]]);

echo "\n== A. a taught session logged without its review ==\n";
$reply = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-14', 'block_key' => 3,
    'duration_minutes' => 70, 'summary' => 'P8 combined events taught; exit ticket 5 of 6.',
    'next_steps' => 'Complement wording, then tree diagrams.',
    'updates' => [['ref' => 'P8', 'status' => 'developing', 'evidence' => 'exit ticket 5/6 unaided, Q4 prompted']]]);
$s1 = logged_id($reply);
check('the session logs — the review never blocks "logged"', $s1 > 0, true);
contains('and the reply says the review is required and missing', $reply, 'REVIEW REQUIRED and not written');
contains('naming the tool to use', $reply, "tracker_save_lesson_review (subject: \"maths\", session_id: $s1)");
$row = $store->sessionById($s1);
check('review_required is set from the block kind', (int) $row['review_required'], 1);
check('and review_id is null', $row['review_id'], null);
$queue = call($store, 'tracker_review_queue', ['subject' => 'maths']);
contains('the queue says the last review is MISSING', $queue, "### last_review — MISSING for session $s1");
$today = call($store, 'tracker_today', ['date' => '2026-09-14']);
contains('tracker_today marks the block review missing', $today, 'review missing');

$reply = call($store, 'tracker_log_session', ['subject' => 'spanish', 'date' => '2026-09-16', 'block_key' => 20,
    'duration_minutes' => 15, 'summary' => 'Spanish vocab set, ten words retrieved.']);
$sp = logged_id($reply);
lacks('a Spanish maintenance slot requires no review', $reply, 'REVIEW REQUIRED');
check('and review_required is 0', (int) $store->sessionById($sp)['review_required'], 0);
$reply = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-15', 'duration_minutes' => 45,
    'summary' => 'Extra maths at the kitchen table, forty-five minutes.']);
contains('an extra of 30+ minutes requires one', $reply, 'REVIEW REQUIRED');
$extra = logged_id($reply);
$reply = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-15', 'duration_minutes' => 20,
    'summary' => 'Twenty minutes of quick practice, no block.']);
lacks('an extra under 30 minutes does not', $reply, 'REVIEW REQUIRED');

echo "\n== B. validation refuses the whole call, naming the field ==\n";
$before = sessions($store, 'maths');
$base   = ['subject' => 'maths', 'date' => '2026-09-17', 'block_key' => 22, 'duration_minutes' => 65,
    'summary' => 'P8 consolidated, A17 in the starter.',
    'updates' => [
        ['ref' => 'P8', 'evidence' => 'exit ticket 5 of 6 unaided; Q4 prompted'],
        ['ref' => 'A17', 'evidence' => 'starter 4/4 unaided', 'retrieval_outcome' => 'correct'],
    ]];
$reply = call($store, 'tracker_log_session', $base + ['review' => review(['bogus' => 1])]);
contains('an unknown key is refused', $reply, 'REFUSED: review has an unknown key "bogus"');
$reply = call($store, 'tracker_log_session', $base + ['review' => review(['planner' => ['priority' => 'Complement wording first']])]);
contains('a planner missing a field is refused', $reply, 'review.planner.start_with is required');
$r = review();
unset($r['missing_evidence']);
$reply = call($store, 'tracker_log_session', $base + ['review' => $r]);
contains('missing_evidence must be present, even empty', $reply, 'review.missing_evidence is required');
$r = review();
$r['retention']['prompted'] = [['ref' => 'P8', 'evidence' => 'Q4 after a prompt']];
$reply = call($store, 'tracker_log_session', $base + ['review' => $r]);
contains('a retention entry without its retrieval_outcome is refused', $reply,
    "review.retention.prompted[0] lists P8 as prompted, which needs retrieval_outcome 'retry'");
$r = review();
$r['next_what']['new'] = ['A17'];
$reply = call($store, 'tracker_log_session', $base + ['review' => $r]);
contains('new[] may not name a secure topic', $reply, 'review.next_what.new names A17, which is already secure');
$r = review();
$r['process'][0]['evidence'] = 'No blanks that I noticed.';
$reply = call($store, 'tracker_log_session', $base + ['review' => $r]);
contains('the avoidance entry must carry a number', $reply, 'avoidance entry and its evidence carries no number');
$r = review(['resources' => ['Some worksheet nobody stored']]);
$reply = call($store, 'tracker_log_session', $base + ['review' => $r]);
contains('a resource not stored must be marked unlisted', $reply, 'is not a stored resource of this subject');
$r = review();
$r['progress'][0]['proposed_status'] = 'secure';
$moved = $base;
$moved['updates'][0]['status'] = 'secure';
$reply = call($store, 'tracker_log_session', $moved + ['review' => $r]);
contains('proposed_status on a ref the updates move is refused', $reply, "already moved P8");
$r = review();
$r['signals'][0]['key'] = 'Not A Slug';
$reply = call($store, 'tracker_log_session', $base + ['review' => $r]);
contains('a signal key must be a slug', $reply, 'review.signals[0].key must be a stable slug');
$r = review();
$r['learner_voice'] = [['context' => 'no quote given']];
$reply = call($store, 'tracker_log_session', $base + ['review' => $r]);
contains('learner voice needs a quote', $reply, 'review.learner_voice[0].quote is required');
check('none of those logged a session', sessions($store, 'maths'), $before);

echo "\n== C. one write closes a session ==\n";
$r = review(['resources' => ['Corbettmaths — combined events', ['title' => 'Own worksheet', 'unlisted' => true]]]);
$r['progress'][0]['proposed_status'] = 'secure';
$withReview = $base;
unset($withReview['next_steps']);
$reply = call($store, 'tracker_log_session', $withReview + ['review' => $r]);
$s2 = logged_id($reply);
check('the session logs with its review', $s2 > 0, true);
contains('and the reply names the version and readiness', $reply, 'Lesson review saved: version 1 (draft), readiness progress_with_retrieval');
contains('the signal is opened at one_off', $reply, 'Signal opened: model-then-immediate-practice (teaching_method) at one_off');
contains('the proposal is echoed, not applied', $reply, 'Proposed for adjudication (not applied): P8 → secure');
contains('the planner was copied into next_steps', $reply, 'next_steps was empty, so the planner was copied into it');
lacks('and REVIEW REQUIRED is not raised', $reply, 'REVIEW REQUIRED');
$row = $store->sessionById($s2);
check('the session points at its review', $row['review_id'] !== null, true);
contains('next_steps carries the planner', (string) $row['next_steps'], 'Priority: Complement wording on P8');
check('P8 was not moved by the proposal', $store->getTopic('maths', 'P8')['status'], 'developing');
$rv = $store->lessonReview($s2);
check('version 1, draft, written by the session', [$rv['version'], $rv['stage'], $rv['written_by']], [1, 'draft', 'session']);
check('the snapshot froze A17 before and after', [$rv['snapshot']['statuses']['A17']['before'], $rv['snapshot']['statuses']['A17']['after']], ['secure', 'secure']);
check('and the retrieval outcome recorded', $rv['snapshot']['outcomes']['A17'] ?? null, 'correct');
check('the block was judged in the snapshot', $rv['snapshot']['block']['status'] ?? null, 'done');
check('error rows were written', count($store->reviewErrorsForSession($s2)), 1);
check('the watch became a watch-kind signal', $store->signalByKey('maths', 'at-least-one-wording')['kind'] ?? null, 'watch');
$g = $store->signalByKey('maths', 'model-then-immediate-practice');
check('the signal holds its next_test', $g['next_test'] !== null, true);

echo "\n== D. the queue opens on the review ==\n";
$queue = call($store, 'tracker_review_queue', ['subject' => 'maths']);
contains('last_review names the session and stage', $queue, "### last_review  (session $s2, 2026-09-17, stage draft, v1)");
contains('the planner comes first', $queue, 'priority: Complement wording on P8, then start tree diagrams');
contains('things to watch are listed', $queue, 'Whether "at least one" is read as the complement without a prompt.  (opened session ' . $s2);
contains('open signals with strength and count', $queue, '[one_off, 1 session] teaching_method model-then-immediate-practice');
contains('errors last time', $queue, 'P8 instruction_misread — Read "at least one" as "exactly one"');
contains('and the readiness call', $queue, 'READINESS: progress_with_retrieval');
$posLast = strpos($queue, '### last_session');
$posRev  = strpos($queue, '### last_review');
$posUnf  = strpos($queue, '### Unfinished');
check('last_review sits between last_session and the groups', $posLast < $posRev && $posRev < $posUnf, true);
$today = call($store, 'tracker_today', ['date' => '2026-09-17']);
contains('tracker_today marks the block reviewed', $today, '· reviewed');

echo "\n== E. the strength rule ==\n";
$r = review();
$r['signals'][0]['strength'] = 'established';
$r['signals'][0]['evidence'] = 'Q5 modelled, Q6 unaided in the same form, second session running.';
$reply = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-18', 'duration_minutes' => 40,
    'summary' => 'Extra P8 session, complement wording.',
    'updates' => [['ref' => 'P8', 'evidence' => '3 of 3 complement items unaided'],
                  ['ref' => 'A17', 'evidence' => 'starter 3/3', 'retrieval_outcome' => 'correct']],
    'review' => $r]);
$s3 = logged_id($reply);
contains('a claim above the evidence is refused with the counts', $reply,
    'REFUSED strength on model-then-immediate-practice');
contains('naming what established needs', $reply, 'established needs 3 distinct supporting sessions, and this signal has 2');
contains('and held where it was', $reply, 'Held at one_off; the evidence row was kept');
check('the row is still one_off', $store->signalByKey('maths', 'model-then-immediate-practice')['strength'], 'one_off');
check('with two supporting sessions', $store->signalByKey('maths', 'model-then-immediate-practice')['supporting'], 2);

$r['signals'][0]['strength'] = 'emerging';
$r['signals'][0]['evidence'] = 'Third session: modelled item then matched item, transferred again.';
$reply = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-19', 'duration_minutes' => 35,
    'summary' => 'Weekend extra: P8 and tree diagram start.',
    'updates' => [['ref' => 'P8', 'evidence' => 'complement items 3/3 again'],
                  ['ref' => 'A17', 'evidence' => 'starter 2/2', 'retrieval_outcome' => 'correct']],
    'review' => $r]);
$s4 = logged_id($reply);
contains('a claim the counts allow is applied', $reply, 'model-then-immediate-practice #' . $g['id'] . ': one_off → emerging');
$g = $store->signalByKey('maths', 'model-then-immediate-practice');
check('three supporting sessions now', $g['supporting'], 3);
$reply = call($store, 'tracker_update_signal', ['id' => $g['id'], 'session_id' => $s4, 'direction' => 'contradicts',
    'evidence' => 'Same session, later: the matched item did not transfer after a gap.']);
contains('an evidence row can be added outside a review', $reply, "evidence row added from session $s4 (contradicts)");
$g = $store->signalByKey('maths', 'model-then-immediate-practice');
check('strength was not raised by it', $g['strength'], 'emerging');
$reply = call($store, 'tracker_update_signal', ['id' => $g['id'], 'status' => 'refuted']);
contains('refuting needs evidence', $reply, 'REFUSED: Marking a signal refuted needs evidence');
$reply = call($store, 'tracker_update_signal', ['id' => $g['id'], 'next_test' => null]);
contains('next_test can be cleared', $reply, 'next_test cleared');
$sig = call($store, 'tracker_signals', ['subject' => 'maths']);
contains('tracker_signals lists the subject signal with its trail', $sig, '[emerging, 2 sessions] teaching_method model-then-immediate-practice');
contains('the contradiction replaced that session\'s supporting row (one row per session)', $sig, '− session ' . $s4);
contains('and shows the contradiction', $sig, '− session ' . $s4);
contains('and the watch', $sig, 'watch at-least-one-wording');

echo "\n== F. re-versioning ==\n";
$reply = call($store, 'tracker_save_lesson_review', ['subject' => 'maths', 'session_id' => $s2, 'stage' => 'draft',
    'written_by' => 'session', 'sections' => review()]);
contains('a second version needs a note', $reply, 'a new version needs a note saying what changed');
$audited = review();
$audited['progress'][0]['status_seen'] = 'developing';
$audited['one_sentence'] = 'Audit: 5 of 6 unaided stands; the "secure" proposal was premature on one run.';
$reply = call($store, 'tracker_save_lesson_review', ['subject' => 'maths', 'session_id' => $s2, 'stage' => 'audited',
    'written_by' => 'audit', 'sections' => $audited, 'note' => 'Removed the secure proposal: one unaided run is not the bar.']);
contains('the audit saves version 2', $reply, "Saved version 2 (audited) for session $s2");
$reply = call($store, 'tracker_save_lesson_review', ['subject' => 'maths', 'session_id' => $s2, 'stage' => 'audited',
    'written_by' => 'audit', 'sections' => $audited, 'note' => 'again']);
contains('an identical re-save adds no version', $reply, 'already says exactly this');
$reply = call($store, 'tracker_save_lesson_review', ['subject' => 'maths', 'session_id' => $s2, 'stage' => 'draft',
    'written_by' => 'session', 'sections' => review(), 'note' => 'late routine']);
contains('a draft cannot land over an audited version', $reply, "already has a audited review (version 2");
check('two versions exist', count($store->lessonReviewVersions($s2)), 2);
check('the session points at the latest', (int) $store->sessionById($s2)['review_id'], $store->lessonReview($s2)['id']);
$reply = call($store, 'tracker_save_lesson_review', ['subject' => 'maths', 'session_id' => $s1, 'stage' => 'audited',
    'written_by' => 'audit', 'sections' => review(['retention' => []]), 'note' => 'transcript unavailable; verified against record only']);
contains('the audit writes a review for a session that had none', $reply, "Saved version 1 (audited) for session $s1");
$queue = call($store, 'tracker_review_queue', ['subject' => 'maths']);
lacks('and the MISSING line is gone', $queue, 'MISSING for session');

echo "\n== G. reading a review ==\n";
$text = call($store, 'tracker_get_lesson_review', ['subject' => 'maths', 'session_id' => $s2]);
contains('the latest version is rendered', $text, "version 2 of 2 · audited · written by the audit");
contains('in the standard layout', $text, '=== LESSON REVIEW — 2026-09-17 — maths — block 22 (teach) — 65 min ===');
contains('with the planner', $text, '19. PLANNER');
contains('with the versions and their notes', $text, 'Removed the secure proposal');
contains('the snapshot', $text, 'A17: secure → secure · retrieval correct');
contains('and the drift', $text, 'DRIFT (the record now against the snapshot)');
contains('naming the following session', $text, "The following session ($s3, 2026-09-18) reviewed:");
$v1 = call($store, 'tracker_get_lesson_review', ['subject' => 'maths', 'session_id' => $s2, 'version' => 1]);
contains('an earlier version can be read', $v1, 'version 1 of 2 · draft');
contains('and still shows the proposal it made', $v1, 'PROPOSED secure');
$po = call($store, 'tracker_get_lesson_review', ['subject' => 'maths', 'session_id' => $s2, 'planner_only' => true]);
contains('planner_only gives the planner', $po, 'start_with: Three "at least one" items, unaided');
lacks('and nothing else', $po, '7. PROCESS');
$list = call($store, 'tracker_list_lesson_reviews', ['subject' => 'maths']);
contains('the list is one line per reviewed session', $list, "- session $s4 · 2026-09-19 · block extra · draft v1 · progress_with_retrieval");
$missing = call($store, 'tracker_list_lesson_reviews', ['subject' => 'maths', 'missing' => true]);
contains('missing: true lists the extra still owed one', $missing, "- session $extra · 2026-09-15 · block extra · 45 min");

echo "\n== H. the tools a session already calls ==\n";
$hist = call($store, 'tracker_history', ['subject' => 'maths']);
contains('history tags reviewed sessions', $hist, "**Session $s2** 2026-09-17 [review v2 audited]");
contains('and the one still owed', $hist, "**Session $extra** 2026-09-15 [review MISSING]");
$hist = call($store, 'tracker_history', ['subject' => 'maths', 'ref' => 'P8']);
contains('ref mode appends the error rows', $hist, '### Errors recorded by lesson reviews on P8 — instruction_misread');
$state = call($store, 'tracker_get_state', ['subject' => 'maths', 'ref' => 'P8']);
contains('get_state with ref tallies error types', $state, 'Errors recorded by lesson reviews on P8: instruction_misread');
lacks('and shows only that topic', $state, '| A17 |');
$week = call($store, 'tracker_week_report', ['week' => '2026-W38']);
contains('the week report lists the reviews', $week, 'REVIEWS THIS WEEK');
contains('one line per session with readiness', $week, "- 2026-09-17 maths session $s2 · progress_with_retrieval (audited)");
contains('and names the session still owed one', $week, "REVIEW MISSING: maths session $extra");
contains('and the signal movement', $week, 'SIGNAL MOVEMENT');
contains('including the strength change', $week, 'maths model-then-immediate-practice one_off → emerging');

echo "\n== I. the audit ==\n";
$q = call($store, 'tracker_review_audit_queue', ['subject' => 'maths']);
contains('the audit queue lists the session owed a review', $q, "- session $extra · 2026-09-15");
contains('and the drafts', $q, "- session $s3 · 2026-09-18 · v1 draft");
contains('and flags a secure seen with no proposal and no move', $q, "[secure_seen_not_moved] session $s3: progress says A17 was seen secure");
$flagged = $store->reviewAuditQueue('maths');
$codes = array_unique(array_column($flagged['flags'], 'code'));
check('no retention flag: every retention entry had its outcome', in_array('retention_without_outcome', $codes, true), false);
// A promotion whose review evidence carries no number.
$r = review(['topic_refs' => ['P6-P7'], 'progress' => [['ref' => 'P6-P7', 'status_seen' => 'developing',
    'evidence' => 'Drew the first tree with help and the second on her own.', 'implication' => 'Keep going.']],
    'errors' => [], 'retention' => [], 'next_what' => ['new' => ['P6-P7']], 'watch' => [], 'signals' => []]);
$reply = call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => '2026-09-21', 'block_key' => 3,
    'duration_minutes' => 60, 'summary' => 'Tree diagrams started.',
    'updates' => [['ref' => 'P6-P7', 'status' => 'developing', 'evidence' => 'second tree drawn unaided']],
    'review' => $r]);
$s5 = logged_id($reply);
$q = call($store, 'tracker_review_audit_queue', ['subject' => 'maths']);
contains('a promotion with no number in its evidence is flagged', $q, "[promotion_without_number] session $s5: P6-P7 was promoted notstarted → developing");
$reply = call($store, 'tracker_audit_stamp', ['subject' => 'maths', 'note' => 'Verified the week; removed one premature proposal.']);
contains('the stamp closes the audit', $reply, 'Audit stamped for maths');
$q = call($store, 'tracker_review_audit_queue', ['subject' => 'maths']);
contains('the previous note is shown to the next audit', $q, 'Its note: Verified the week; removed one premature proposal.');
contains('drafts before the stamp are out of scope', $q, 'Drafts to verify (0)');
contains('but a session still owed a review is carried over', $q, "- session $extra · 2026-09-15 · block extra · 45 min");
contains('and marked as such', $q, '(carried over from before the last audit)');

echo "\n== J. the pages ==\n";
$subject = $store->getSubject('maths');
$session = $store->getSession('maths', $s2);
$public  = render_session($store, $subject, $session, false);
contains('the public session page shows the tick', $public, '✓ reviewed');
lacks('and nothing of the review itself (the planner reaches next_steps by design)', $public, 'Then and now');
lacks('not the learner voice', $public, 'oh, it is one minus');
lacks('nor the signals', $public, 'model-then-immediate-practice');
$parent = render_session($store, $subject, $session, true);
contains('the parent sees the review', $parent, 'Complement wording on P8, then start tree diagrams');
contains('with a version switcher', $parent, "/s/maths/session/$s2?v=1");
contains('the readiness chip', $parent, 'rv-chip rv-progress_with_retrieval');
contains('the drift', $parent, 'Then and now');
contains('and the signals the session touched', $parent, 'model-then-immediate-practice');
$v1page = render_session($store, $subject, $session, true, 1);
contains('?v=1 renders the draft', $v1page, 'proposed Secure');
$topicPublic = render_topic_history($store, $subject, $store->getTopic('maths', 'P8'), false);
lacks('the public topic page has no error history', $topicPublic, 'Errors recorded by lesson reviews');
$topicParent = render_topic_history($store, $subject, $store->getTopic('maths', 'P8'), true);
contains('the parent topic page has the tally', $topicParent, 'instruction misread');
$reviews = render_lesson_reviews($store, $subject, false);
contains('/s/maths/reviews is gated', $reviews, 'Sign in to read them');
$reviews = render_lesson_reviews($store, $subject, true);
contains('and lists the reviews for the parent', $reviews, "/s/maths/session/$s2");
$signals = render_signals($store, false);
contains('/signals is gated', $signals, 'Sign in to read it');
$signals = render_signals($store, true);
contains('and groups by strength for the parent', $signals, 'model-then-immediate-practice');
$weekPage = render_week_page($store, '2026-W38', true);
contains('the parent week page carries the readiness chips', $weekPage, 'Lesson reviews this week');
contains('and the signal movement', $weekPage, 'Signal movement');
$weekPublic = render_week_page($store, '2026-W38', false);
lacks('the public week page does not', $weekPublic, 'Lesson reviews this week');
$subjectPage = render_subject($store, $subject);
contains('the subject page ticks reviewed sessions', $subjectPage, '✓ reviewed');

echo "\n";
if ($failures) {
    echo "LESSON REVIEW FAIL — $failures of $checks checks failed\n";
    exit(1);
}
echo "LESSON REVIEW PASS — $checks checks\n";
