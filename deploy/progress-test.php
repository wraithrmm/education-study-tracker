<?php
/**
 * Acceptance tests for progress over time and the forecast.
 *
 *   php deploy/progress-test.php
 *
 * One subject with eight topics, a four-block week, a week off, and three
 * points in time under a frozen clock: three weeks in (too early for a cone,
 * one regression, one stalled week), seven weeks in (the cone drawn, a
 * three-week stall, no new topic opened, a topic touched five times without
 * moving), and twelve weeks in (the aimline rule firing, weeks with nothing
 * logged, the goal out of reach). Then the goal override, determinism, the
 * tool's two formats and the page for the parent and for everyone else.
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
    str_contains($haystack, $needle) ? pass($what) : fail($what, "missing: $needle\n        in: " . substr($haystack, 0, 600));
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

function codes(array $m): array
{
    return array_column($m['signals'], 'code');
}

function week(array $m, string $iso): ?array
{
    foreach ($m['weeks'] as $w) {
        if ($w['week'] === $iso) {
            return $w;
        }
    }
    return null;
}

$dbPath = sys_get_temp_dir() . '/progress-test-' . getmypid() . '.db';
@unlink($dbPath);
register_shutdown_function(static function () use ($dbPath) {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
});
putenv('TRACKER_NOW=2026-09-25 14:00');
$store = new Store($dbPath);

echo "== A. the fixture ==\n";
call($store, 'tracker_create_subject', [
    'slug' => 'maths', 'name' => 'GCSE Mathematics', 'spec_code' => 'AQA 8300', 'tier' => 'Higher',
    'exam_date' => '2027-05-14',
    'strands' => ['T' => 'Topics'],
    'topics'  => [
        ['ref' => 'T1', 'name' => 'One', 'strand' => 'T', 'status' => 'secure'],
        ['ref' => 'T2', 'name' => 'Two', 'strand' => 'T', 'status' => 'developing'],
        ['ref' => 'T3', 'name' => 'Three', 'strand' => 'T', 'status' => 'gap'],
        ['ref' => 'T4', 'name' => 'Four', 'strand' => 'T'],
        ['ref' => 'T5', 'name' => 'Five', 'strand' => 'T'],
        ['ref' => 'T6', 'name' => 'Six', 'strand' => 'T'],
        ['ref' => 'T7', 'name' => 'Seven', 'strand' => 'T'],
        ['ref' => 'T8', 'name' => 'Eight', 'strand' => 'T'],
    ],
]);
call($store, 'tracker_create_subject', [
    'slug' => 'spanish', 'name' => 'GCSE Spanish', 'strands' => ['V' => 'Vocab'],
    'topics' => [['ref' => 'V1', 'name' => 'Greetings', 'strand' => 'V']],
]);
// Four maths blocks a week. The Wednesday retrieval names two subjects and
// the Tuesday timed block names two subjects; neither is a maths block the
// cone paces by.
$reply = call($store, 'tracker_set_timetable', ['valid_from' => '2026-08-31', 'note' => 'progress fixture', 'blocks' => [
    ['block_key' => 1, 'weekday' => 1, 'start' => '09:00', 'end' => '10:15', 'kind' => 'teach', 'label' => 'Maths — new topic', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 2, 'weekday' => 2, 'start' => '09:00', 'end' => '10:15', 'kind' => 'practise', 'label' => 'Maths — practice', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 3, 'weekday' => 2, 'start' => '13:00', 'end' => '14:00', 'kind' => 'timed_handwritten', 'label' => 'Timed', 'subjects' => ['maths', 'spanish'], 'tracking' => 'evidence'],
    ['block_key' => 4, 'weekday' => 3, 'start' => '09:00', 'end' => '09:30', 'kind' => 'retrieval', 'label' => 'Retrieval', 'subjects' => ['maths', 'spanish'], 'tracking' => 'evidence'],
    ['block_key' => 5, 'weekday' => 4, 'start' => '09:00', 'end' => '10:15', 'kind' => 'teach', 'label' => 'Maths — deep', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 6, 'weekday' => 5, 'start' => '09:00', 'end' => '10:15', 'kind' => 'consolidate', 'label' => 'Maths — consolidate', 'subjects' => ['maths'], 'tracking' => 'evidence'],
    ['block_key' => 7, 'weekday' => 5, 'start' => '11:00', 'end' => '11:30', 'kind' => 'spanish', 'label' => 'Spanish', 'subjects' => ['spanish'], 'tracking' => 'evidence'],
]]);
contains('the timetable is in force', $reply, 'version');
$reply = call($store, 'tracker_request_day_off', ['date_from' => '2026-09-14', 'date_to' => '2026-09-18',
    'kind' => 'holiday', 'reason' => 'Half term', 'requested_by' => 'parent']);
contains('a week off is booked', $reply, 'approved');

$log = static function (string $date, array $updates, string $summary = 'A session, logged for the fixture.') use ($store): string {
    return call($store, 'tracker_log_session', ['subject' => 'maths', 'date' => $date, 'summary' => $summary,
        'updates' => $updates]);
};
$up = static fn(string $ref, string $status): array => ['ref' => $ref, 'status' => $status, 'evidence' => "Evidence for $ref on the day."];

// W36: two steps, one topic opened, one touch.
$log('2026-08-31', [$up('T3', 'developing'), $up('T2', 'developing')]);
$log('2026-09-01', [$up('T2', 'secure')]);
// A standalone update on the Saturday, stamped by hand so its date is known.
call($store, 'tracker_update_topic', ['subject' => 'maths', 'ref' => 'T6', 'status' => 'gap', 'evidence' => 'Diagnosed from the June paper.']);
(new PDO('sqlite:' . $dbPath))->exec("UPDATE topic_changes SET changed_at = '2026-09-05 10:00:00' WHERE ref = 'T6' AND subject_slug = 'maths'");
// W37: two opened, two secured, one regression.
$log('2026-09-07', [$up('T4', 'developing'), $up('T5', 'developing')]);
$log('2026-09-10', [$up('T4', 'secure'), $up('T3', 'secure')]);
$log('2026-09-11', [$up('T1', 'developing')]);
// W38: the week off. W39: two sessions, three touches, nothing moved.
$log('2026-09-21', [$up('T5', 'developing'), $up('T2', 'secure')]);
$log('2026-09-22', [$up('T5', 'developing')]);

$m = progress_compute($store->progressInputs('maths'));
check('eight topics make 24 steps', $m['unit']['max'], 24);
check('eight steps are done', $m['unit']['points'], 8);
check('which is a third', $m['unit']['pct'], 33.3);
check('the record starts on the first session', $m['first_day'], '2026-08-31');
check('the baseline is the day before, at 3 steps', $m['aimline']['from_pts'], 3);
check('the aimline runs to every step on exam day', [$m['aimline']['to_pts'], $m['aimline']['to_date']], [24, '2027-05-14']);
check('the goal is the default', $m['goal']['set'], false);

echo "\n== B. week by week, three weeks in ==\n";
$w36 = week($m, '2026-W36');
check('W36 has four maths blocks (not the retrieval, not the timed block)', $w36['blocks'], 4);
check('W36 gained two steps', $w36['net'], 2);
check('W36 opened one topic', $w36['opened'], 1);
check('W36 counts the touch', $w36['touches'], 1);
check('W36 counts its sessions', $w36['sessions'], 2);
check('W36 ended ahead of the aimline', $w36['flag'], 'ahead');
$w37 = week($m, '2026-W37');
check('W37 gained three steps net of the regression', [$w37['net'], $w37['ups'], $w37['downs']], [3, 4, 1]);
check('W37 opened two topics', $w37['opened'], 2);
$w38 = week($m, '2026-W38');
check('the week off has no blocks', [$w38['blocks'], $w38['flag']], [0, 'no_blocks']);
$w39 = week($m, '2026-W39');
check('W39 is in progress with four blocks so far', [$w39['complete'], $w39['blocks_so_far']], [false, 4]);
check('W39 stalled: sessions, touches, no steps', [$w39['flag'], $w39['touches'], $w39['net']], ['stalled', 3, 0]);
check('one stalled week running', $m['stalled_run'], 1);
check('no week under the aimline yet', $m['behind_run'], 0);
check('three weeks of pace, so no cone', [$m['pace']['weeks_of_pace'], $m['cone']], [3, null]);
check('the standalone update is dated by its stamp', $m['cfd'][1]['gap'] ?? null, 1);
$codes = codes($m);
check('too early is said', in_array('too_early', $codes, true), true);
check('the regression is named', in_array('regressions', $codes, true), true);
check('nothing is called stalled at one week', in_array('stalled', $codes, true), false);
check('the daily line runs from the first Monday to today', [$m['daily'][0]['date'], end($m['daily'])['date']], ['2026-08-31', '2026-09-25']);
check('the flow starts the day before', $m['cfd'][0]['date'], '2026-08-30');
check('and reads the seed statuses', [$m['cfd'][0]['secure'], $m['cfd'][0]['developing'], $m['cfd'][0]['gap'], $m['cfd'][0]['notstarted']], [1, 1, 1, 5]);
check('and today', [$m['cfd'][count($m['cfd']) - 1]['secure'], $m['cfd'][count($m['cfd']) - 1]['developing']], [3, 2]);
check('T5 has been touched twice without moving', $m['in_flight'][0]['ref'] . ':' . $m['in_flight'][0]['touches'], 'T5:2');
check('fresh cycle time: T3 ten days, T4 three days → median 6.5', [$m['cycle_time']['fresh_median_days'], $m['cycle_time']['fresh_count']], [6.5, 2]);

echo "\n== C. seven weeks in: the cone, a stall four weeks long ==\n";
putenv('TRACKER_NOW=2026-10-16 14:00');
$log('2026-09-28', [$up('T5', 'developing')]);
$log('2026-10-05', [$up('T5', 'developing')]);
$log('2026-10-12', [$up('T5', 'developing')]);
$m = progress_compute($store->progressInputs('maths'));
check('six weeks of pace', $m['pace']['weeks_of_pace'], 6);
check('the cone is drawn', $m['cone'] !== null, true);
check('and provisional', $m['cone']['provisional'], true);
check('one band per future week to the exam', end($m['cone']['bands'])['end'], '2027-05-14');
$mono = true;
$prev = 0;
foreach ($m['cone']['bands'] as $b) {
    if ($b['p50'] < $prev || $b['p15'] > $b['p50'] || $b['p50'] > $b['p85'] || $b['p5'] > $b['p15'] || $b['p85'] > $b['p95'] || $b['p95'] > 24) {
        $mono = false;
    }
    $prev = $b['p50'];
}
check('the bands are ordered and within the syllabus', $mono, true);
check('exam day is reported', $m['cone']['exam_day']['end'], '2027-05-14');
check('four stalled weeks running, W39 included', $m['stalled_run'], 4);
$codes = codes($m);
check('stalled is an alert', $m['signals'][0]['code'] . ':' . $m['signals'][0]['level'], 'stalled:alert');
check('no new topic opened is said', in_array('no_new_topics', $codes, true), true);
check('T5 is stuck', in_array('stuck_topics', $codes, true), true);
check('the regression has left the four-week window', in_array('regressions', $codes, true), false);
check('the limits wait for six complete weeks', $m['limits'], null);
$again = progress_compute($store->progressInputs('maths'));
check('the cone is the same on a second run', $again['cone']['bands'] === $m['cone']['bands'], true);
$fewer = progress_compute($store->progressInputs('maths'), ['runs' => 500]);
check('fewer runs still order the bands', $fewer['cone']['bands'][3]['p15'] <= $fewer['cone']['bands'][3]['p85'], true);

echo "\n== D. twelve weeks in: the rule fires, nothing logged ==\n";
putenv('TRACKER_NOW=2026-11-20 14:00');
$m = progress_compute($store->progressInputs('maths'));
$w44 = week($m, '2026-W44');
check('a complete week with blocks and no sessions is missed', $w44['flag'], 'missed');
check('the stall run counts missed weeks too', $m['stalled_run'], 8);
check('four weeks under the aimline', $m['behind_run'], 4);
$codes = codes($m);
check('the aimline rule fires', in_array('behind_aimline', $codes, true), true);
$byCode = [];
foreach ($m['signals'] as $sig) {
    $byCode[$sig['code']] = $sig;
}
check('as an alert', $byCode['behind_aimline']['level'], 'alert');
check('the goal is off target', $byCode['off_target']['level'], 'alert');
check('T1 is developing and idle', in_array('idle_developing', $codes, true), true);
check('the window is the last eight weeks with blocks', count($m['rates']), 8);
check('the limits now have six complete weeks', $m['limits']['weeks'] >= 6, true);
check('their lower limit is floored at zero', $m['limits']['lower'], 0.0);

echo "\n== E. the goal override ==\n";
$reply = call($store, 'tracker_create_subject', ['slug' => 'maths', 'goal_pct' => 50, 'goal_date' => '2027-01-15']);
contains('the subject is updated', $reply, 'Updated');
$m = progress_compute($store->progressInputs('maths'));
check('the aimline runs to half the steps on the goal date', [$m['aimline']['to_pts'], $m['aimline']['to_date'], $m['goal']['set']], [12, '2027-01-15', true]);
check('the exam is still the horizon', end($m['cone']['bands'])['end'], '2027-05-14');
$reply = call($store, 'tracker_create_subject', ['slug' => 'maths', 'goal_pct' => null, 'goal_date' => null]);
$m = progress_compute($store->progressInputs('maths'));
check('null puts the goal back on exam day', [$m['aimline']['to_pts'], $m['aimline']['to_date']], [24, '2027-05-14']);
$reply = call($store, 'tracker_create_subject', ['slug' => 'maths', 'goal_pct' => 140]);
contains('a goal over 100 is refused', $reply, 'at most 100');

echo "\n== F. the tool ==\n";
$text = call($store, 'tracker_progress_forecast', ['subject' => 'maths']); if (getenv('PG_DEBUG')) { echo substr($text, 0, 1800), "
"; }
contains('the tool leads with the subject', $text, '**Progress and forecast — GCSE Mathematics**');
contains('signals come first', $text, '### SIGNALS (read these first)');
contains('with their level and code', $text, '- ALERT stalled —');
contains('the pace block', $text, '### PACE');
contains('the cone block', $text, '### THE CONE');
contains('exam day odds', $text, 'Exam day 14 May 2027: most likely');
contains('the week table', $text, '| 2026-W37 | 4 | 3 | 0 | 2 | +3 |');
contains('the missed week reads plainly', $text, '| 2026-W44 | 4 | 0 | 0 | 0 | 0 |');
contains('in flight lists the stuck topic', $text, '| T5 | Five | Developing |');
contains('chart data carries the bands', $text, '| 2027-05-14 | — |');
contains('and the flow', $text, '| Week end | Not started | Gap | Developing | Secure | Exam-ready |');
contains('and points at the page', $text, 'Page: /s/maths/progress');
$json = call($store, 'tracker_progress_forecast', ['subject' => 'maths', 'format' => 'json', 'weeks' => 3]);
$decoded = json_decode($json, true);
check('json decodes', is_array($decoded), true);
check('and carries the signals', $decoded['signals'][0]['code'] ?? null, 'stalled');
check('and only the weeks asked for', count($decoded['weeks'] ?? []), 3);
check('and the cone', isset($decoded['cone']['bands']), true);
$reply = call($store, 'tracker_progress_forecast', ['subject' => 'nope']);
contains('an unknown subject is named', $reply, 'No subject "nope"');
$fresh = call($store, 'tracker_progress_forecast', ['subject' => 'spanish']);
contains('a subject with no record says so', $fresh, 'Nothing logged yet');

echo "\n== G. the page ==\n";
$subject = $store->getSubject('maths');
$parent  = render_subject_progress($store, $subject, true);
contains('the parent page has the title', $parent, '<h1>Progress and forecast</h1>');
contains('the today card', $parent, 'Today</p>');
contains('the exam-day card', $parent, 'On exam day, at this pace');
contains('the everything-secure card', $parent, 'Everything secure</p>');
contains('the flag leads with the stall', $parent, '<b>Stalled.</b>');
contains('the burn-up', $parent, 'aria-labelledby="pg-bu-t"');
contains('with the cone', $parent, 'fill-opacity=".14"');
contains('the flow chart', $parent, 'aria-labelledby="pg-cfd-t"');
contains('the weekly columns', $parent, 'aria-labelledby="pg-wk-t"');
contains('the in-flight table', $parent, 'In flight</h2>');
contains('the week off is shaded', $parent, 'fill="#e7e5e4" fill-opacity=".55"');
contains('the hover data', $parent, 'pg-butip');
$public = render_subject_progress($store, $subject, false);
contains('the public page has the line', $public, 'aria-labelledby="pg-bu-t"');
lacks('but no cone', $public, 'fill-opacity=".14"');
lacks('no exam-day card', $public, 'On exam day, at this pace');
lacks('no in-flight table', $public, 'In flight</h2>');
contains('and says what sign-in adds', $public, 'to see the forecast cone');
contains('the aimline is public', $public, 'aimline:');
$spanishPage = render_subject_progress($store, $store->getSubject('spanish'), true);
contains('an empty subject renders a plain line', $spanishPage, 'Nothing has been logged for this subject yet');
$subjectPage = render_subject($store, $subject, true);
contains('the subject page links to the progress page', $subjectPage, '/s/maths/progress');
$index = render_index($store, true);
contains('the index carries the aimline reading', $index, 'under the aimline');

printf("\nPROGRESS %s — %d of %d checks failed\n", $failures ? 'FAIL' : 'PASS', $failures, $checks);
exit($failures ? 1 : 0);
