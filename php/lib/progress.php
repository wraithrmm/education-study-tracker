<?php
/**
 * Progress over time, and the forecast: the aimline and the cone.
 *
 * Everything here is a pure function of one input bundle
 * (Store::progressInputs), so the page, the MCP tool and the tests all read
 * the same numbers. Nothing is stored: the daily series is coverage replayed
 * from topic_changes, and a corrected status corrects the whole history.
 *
 * The unit is the step — one topic moving up one level, the same points the
 * headline percentage is built from (STATUS_POINTS). A subject with N topics
 * has 3N steps; the aimline is the straight line from the first tracked day
 * to the goal (every step, on exam day, unless the parent set another); the
 * cone re-runs the future thousands of times from the subject's own past
 * weeks, in steps per timetabled block, so a holiday week with no blocks
 * gets no steps. The design deck under design/progress-forecast says why.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** Weeks of pace the cone needs before it is drawn at all. */
const PROGRESS_MIN_WEEKS = 4;

/** Weeks of pace the cone reads once it has them; fewer while the record is younger. */
const PROGRESS_WINDOW_WEEKS = 8;

/** Weeks of pace before the cone stops calling itself provisional. */
const PROGRESS_SETTLED_WEEKS = 8;

/** Weeks of weekly steps before natural process limits are shown. */
const PROGRESS_LIMITS_WEEKS = 6;

/** Simulated futures per forecast. */
const PROGRESS_RUNS = 5000;

/**
 * Block kinds that never move a topic on their own — a retrieval warm-up
 * checks what is already known — so they are not blocks the cone paces by.
 */
const PROGRESS_QUIET_KINDS = ['retrieval', 'review', 'movement', 'break', 'lunch', 'group'];

/** The whole model for one subject. See the sections below for each key. */
function progress_compute(array $in, array $opt = []): array
{
    $subject = $in['subject'];
    $today   = (string) $in['today'];
    $topics  = $in['topics'];
    $n       = count($topics);
    $max     = 3 * $n;

    $pointsNow = 0;
    foreach ($topics as $t) {
        $pointsNow += STATUS_POINTS[$t['status']] ?? 0;
    }
    $pct = static fn(float|int $p): float => $max > 0 ? round(($p / $max) * 100, 1) : 0.0;

    // ---- the changes, dated ------------------------------------------
    //
    // A change is dated by its session where it has one, because sessions
    // are logged after the fact and the wall-clock stamp can land a day (or a
    // weekend) late; a standalone update is dated by when it was made.
    $sessionDate = [];
    $sessionsByDate = [];
    foreach ($in['sessions'] as $s) {
        $sessionDate[(int) $s['id']] = (string) $s['date'];
        $sessionsByDate[(string) $s['date']] = ($sessionsByDate[(string) $s['date']] ?? 0) + 1;
    }
    $changes = [];
    foreach ($in['changes'] as $c) {
        $sid  = $c['session_id'] === null ? null : (int) $c['session_id'];
        $date = $sid !== null && isset($sessionDate[$sid])
            ? $sessionDate[$sid]
            : tt_local((string) $c['changed_at'])[0];
        $from = (string) ($c['from_status'] ?? 'notstarted');
        $to   = (string) $c['to_status'];
        $changes[] = [
            'date'  => $date,
            'ref'   => (string) $c['ref'],
            'from'  => $from,
            'to'    => $to,
            'delta' => (STATUS_POINTS[$to] ?? 0) - (STATUS_POINTS[$from] ?? 0),
            'session_id' => $sid,
        ];
    }
    usort($changes, static fn($a, $b) => strcmp($a['date'], $b['date']));

    // The first tracked day: the earliest session or change. Nothing before it
    // is history the page can show, so the series starts on its Monday.
    $first = null;
    foreach ($in['sessions'] as $s) {
        if ($first === null || (string) $s['date'] < $first) {
            $first = (string) $s['date'];
        }
    }
    foreach ($changes as $c) {
        if ($first === null || $c['date'] < $first) {
            $first = $c['date'];
        }
    }
    $tracked = $first !== null && $first <= $today;
    if (!$tracked) {
        $first = $today;
    }
    $startMonday = tt_monday($first);

    // Net steps per day, and per-day touches (a change that moved nothing).
    $byDay = [];
    foreach ($changes as $c) {
        $byDay[$c['date']] = ($byDay[$c['date']] ?? 0) + $c['delta'];
    }
    // Points at the end of a day: now, less every change dated after it.
    $pointsAt = static function (string $day) use ($pointsNow, $byDay): int {
        $after = 0;
        foreach ($byDay as $d => $v) {
            if ($d > $day) {
                $after += $v;
            }
        }
        return $pointsNow - $after;
    };

    // ---- the calendar: blocks per day -----------------------------------
    $blocksOn = static function (string $date) use ($in, $subject): int {
        foreach ($in['days_off'] as $off) {
            if ($off['date_from'] <= $date && $date <= $off['date_to']) {
                return 0;
            }
        }
        $version = null;
        foreach ($in['versions'] as $v) {
            if ((string) $v['valid_from'] <= $date) {
                $version = $v;
            }
        }
        if ($version === null) {
            return 0;
        }
        $weekday = (int) (new DateTimeImmutable($date, tt_zone()))->format('N');
        $count   = 0;
        foreach ($version['blocks'] as $b) {
            if ((int) $b['weekday'] !== $weekday || ($b['tracking'] ?? 'evidence') === 'none') {
                continue;
            }
            if (in_array((string) $b['kind'], PROGRESS_QUIET_KINDS, true)) {
                continue;
            }
            if (tt_subjects_for($b, $date) === [$subject['slug']]) {
                $count++;
            }
        }
        return $count;
    };

    // ---- the goal and the aimline -------------------------------------
    $goalPct  = $subject['goal_pct'] ?? null;
    $goalDate = $subject['goal_date'] ?? $subject['exam_date'] ?? null;
    $goalPct  = $goalPct === null ? 100 : (int) $goalPct;
    $goalPts  = (int) round($max * $goalPct / 100);
    $baseDay  = $tracked ? tt_add_days($first, -1) : null;
    $basePts  = $tracked ? $pointsAt($baseDay) : $pointsNow;
    $aimline  = null;
    if ($tracked && $goalDate !== null && $goalDate > $first) {
        $span    = tt_days_between($baseDay, $goalDate);
        $aimline = [
            'from_date' => $first,
            'from_pts'  => $basePts,
            'from_pct'  => $pct($basePts),
            'to_date'   => $goalDate,
            'to_pts'    => $goalPts,
            'to_pct'    => (float) $goalPct,
            'goal_set'  => ($subject['goal_pct'] ?? null) !== null || ($subject['goal_date'] ?? null) !== null,
        ];
        $aimAt = static function (string $day) use ($baseDay, $basePts, $goalPts, $span): float {
            if ($span <= 0) {
                return (float) $goalPts;
            }
            $f = tt_days_between($baseDay, $day) / $span;
            return $basePts + max(0.0, min(1.0, $f)) * ($goalPts - $basePts);
        };
    } else {
        $aimAt = static fn(string $day): ?float => null;
    }

    // ---- week by week ---------------------------------------------------
    $weeks   = [];
    $monday  = $startMonday;
    $thisMon = tt_monday($today);
    while ($monday <= $thisMon) {
        $sunday = tt_add_days($monday, 6);
        $end    = min($sunday, $today);
        $blocks = 0;
        $blocksSoFar = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = tt_add_days($monday, $i);
            $b = $blocksOn($d);
            $blocks += $b;
            if ($d <= $today) {
                $blocksSoFar += $b;
            }
        }
        $net = 0;
        $touches = 0;
        $ups = 0;
        $downs = 0;
        $opened = 0;
        $moves = [];
        foreach ($changes as $c) {
            if ($c['date'] < $monday || $c['date'] > $sunday) {
                continue;
            }
            $net += $c['delta'];
            if ($c['from'] === $c['to']) {
                $touches++;
            } elseif ($c['delta'] > 0) {
                $ups++;
                $moves[] = $c;
            } else {
                $downs++;
                $moves[] = $c;
            }
            if ((STATUS_POINTS[$c['from']] ?? 0) === 0 && (STATUS_POINTS[$c['to']] ?? 0) > 0) {
                $opened++;
            }
        }
        $sessions = 0;
        foreach ($sessionsByDate as $d => $k) {
            if ($d >= $monday && $d <= $sunday) {
                $sessions += $k;
            }
        }
        $endPts = $pointsAt($end);
        $aim    = $aimAt($end);
        $under  = $aim !== null && $endPts < $aim;
        $flag   = 'quiet';
        if ($blocks === 0) {
            $flag = 'no_blocks';
        } elseif ($sessions === 0 && $end === $sunday) {
            $flag = 'missed';
        } elseif ($net < 0) {
            $flag = 'slipped';
        } elseif ($net === 0 && $sessions > 0) {
            $flag = 'stalled';
        } elseif ($aim !== null) {
            $flag = $under ? 'behind' : 'ahead';
        } elseif ($net > 0) {
            $flag = 'moved';
        }
        $weeks[] = [
            'week'        => tt_iso_week($monday),
            'monday'      => $monday,
            'end'         => $end,
            'complete'    => $sunday <= $today,
            'blocks'      => $blocks,
            'blocks_so_far' => $blocksSoFar,
            'sessions'    => $sessions,
            'touches'     => $touches,
            'ups'         => $ups,
            'downs'       => $downs,
            'opened'      => $opened,
            'net'         => $net,
            'end_pts'     => $endPts,
            'end_pct'     => $pct($endPts),
            'aim_pct'     => $aim === null ? null : round($pct($aim), 1),
            'vs_aim'      => $aim === null ? null : (int) round($endPts - $aim),
            'under'       => $under,
            'flag'        => $flag,
            'moves'       => $moves,
        ];
        $monday = tt_add_days($monday, 7);
    }

    // Consecutive weeks (latest first) that ended under the aimline; the
    // curriculum-based-measurement rule fires at four.
    $behind = 0;
    for ($i = count($weeks) - 1; $i >= 0; $i--) {
        if ($weeks[$i]['under'] && $weeks[$i]['blocks'] > 0) {
            $behind++;
        } else {
            break;
        }
    }
    // Consecutive weeks with blocks and nothing gained (stalled, missed or
    // slipped). A holiday week neither stalls nor breaks a run, and neither
    // does the current week while nothing has been logged in it yet.
    $stalled = 0;
    for ($i = count($weeks) - 1; $i >= 0; $i--) {
        $w = $weeks[$i];
        if ($w['blocks'] === 0 || (!$w['complete'] && $w['sessions'] === 0)) {
            continue;
        }
        if ($w['net'] <= 0) {
            $stalled++;
        } else {
            break;
        }
    }

    // ---- pace: steps per block, per week in the window -----------------
    $rates = [];
    foreach ($weeks as $w) {
        $blocksDone = $w['complete'] ? $w['blocks'] : $w['blocks_so_far'];
        if ($blocksDone > 0) {
            $rates[] = ['week' => $w['week'], 'net' => $w['net'], 'blocks' => $blocksDone,
                        'rate' => $w['net'] / $blocksDone];
        }
    }
    $rates = array_slice($rates, -PROGRESS_WINDOW_WEEKS);
    $rateValues = array_column($rates, 'rate');
    $meanRate   = $rateValues ? array_sum($rateValues) / count($rateValues) : 0.0;

    // ---- the future: blocks per week to the horizon --------------------
    $horizon = $goalDate !== null && $goalDate > $today ? $goalDate : null;
    if (($subject['exam_date'] ?? null) !== null && $subject['exam_date'] > $today) {
        $horizon = $horizon === null ? $subject['exam_date'] : max($horizon, $subject['exam_date']);
    }
    $future = [];
    $futureBlocks = 0;
    if ($horizon !== null) {
        $d = tt_add_days($today, 1);
        while ($d <= $horizon) {
            $sunday = min(tt_add_days(tt_monday($d), 6), $horizon);
            $blocks = 0;
            for ($x = $d; $x <= $sunday; $x = tt_add_days($x, 1)) {
                $blocks += $blocksOn($x);
            }
            $future[] = ['end' => $sunday, 'blocks' => $blocks];
            $futureBlocks += $blocks;
            $d = tt_add_days($sunday, 1);
        }
    }
    $weeksAhead     = $future ? max(1, count($future)) : 0;
    $blocksPerWeek  = $rates ? array_sum(array_column($rates, 'blocks')) / count($rates) : 0.0;
    $remaining      = max(0, $goalPts - $pointsNow);
    $neededPerBlock = $futureBlocks > 0 ? $remaining / $futureBlocks : null;
    $pace = [
        'weeks_of_pace'    => count($rates),
        'per_block'        => round($meanRate, 3),
        'per_week'         => round($meanRate * $blocksPerWeek, 1),
        'blocks_per_week'  => round($blocksPerWeek, 1),
        'needed_per_week'  => $neededPerBlock === null ? null : round($neededPerBlock * $blocksPerWeek, 1),
        'needed_per_block' => $neededPerBlock === null ? null : round($neededPerBlock, 3),
        'remaining_steps'  => $remaining,
        'future_blocks'    => $futureBlocks,
        'last_two_lands'   => null,
        'window_lands'     => null,
    ];
    if ($futureBlocks > 0 && $rates) {
        $last2 = array_slice($rateValues, -2);
        $pace['last_two_lands'] = $pct(min($max, max(0, $pointsNow + (array_sum($last2) / count($last2)) * $futureBlocks)));
        $pace['window_lands']   = $pct(min($max, max(0, $pointsNow + $meanRate * $futureBlocks)));
    }

    // ---- the cone -------------------------------------------------------
    $cone = null;
    $wantCone = $opt['cone'] ?? true;
    if ($wantCone && $future && count($rates) >= PROGRESS_MIN_WEEKS && $max > 0) {
        $cone = progress_cone($rateValues, $future, $pointsNow, $max, $goalPts, (int) round($max * 2 / 3),
            (int) ($opt['runs'] ?? PROGRESS_RUNS), (string) $subject['slug'] . '|' . $today);
        $cone['provisional'] = count($rates) < PROGRESS_SETTLED_WEEKS;
        $cone['weeks_of_pace'] = count($rates);
        foreach ($cone['bands'] as &$b) {
            foreach (['p5', 'p15', 'p50', 'p85', 'p95'] as $k) {
                $b[$k . '_pct'] = $pct($b[$k]);
            }
        }
        unset($b);
        $examEnd = $subject['exam_date'] ?? null;
        $cone['exam_day'] = null;
        if ($examEnd !== null) {
            foreach ($cone['bands'] as $b) {
                if ($b['end'] >= $examEnd) {
                    $cone['exam_day'] = $b;
                    break;
                }
            }
        }
    }

    // ---- the flow: topics by status at each week end -------------------
    $cfd = [];
    $statusAt = static function (string $day) use ($topics, $changes): array {
        $st = [];
        foreach ($topics as $t) {
            $st[$t['ref']] = $t['status'];
        }
        for ($i = count($changes) - 1; $i >= 0; $i--) {
            if ($changes[$i]['date'] > $day && isset($st[$changes[$i]['ref']])) {
                $st[$changes[$i]['ref']] = $changes[$i]['from'];
            }
        }
        $counts = array_fill_keys(STATUS_ORDER, 0);
        foreach ($st as $s) {
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        return $counts;
    };
    if ($weeks) {
        $before = tt_add_days($weeks[0]['monday'], -1);
        $cfd[]  = ['date' => $before] + $statusAt($before);
        foreach ($weeks as $w) {
            $cfd[] = ['date' => $w['end']] + $statusAt($w['end']);
        }
    }

    // ---- in flight: age and touches of what is open ---------------------
    $inFlight = [];
    $enteredAt = [];   // ref => date it last entered its current status, within the record
    $touchedSince = [];
    foreach ($topics as $t) {
        $enteredAt[$t['ref']] = null;
        $touchedSince[$t['ref']] = 0;
    }
    foreach ($changes as $c) {
        if (!array_key_exists($c['ref'], $enteredAt)) {
            continue;
        }
        if ($c['from'] !== $c['to']) {
            $enteredAt[$c['ref']] = $c['date'];
            $touchedSince[$c['ref']] = 0;
        } else {
            $touchedSince[$c['ref']]++;
        }
    }
    foreach ($topics as $t) {
        if (!in_array($t['status'], ['developing', 'gap'], true)) {
            continue;
        }
        $since = $enteredAt[$t['ref']];
        $age   = $since === null
            ? ($t['last_touched'] ? tt_days_between((string) $t['last_touched'], $today) : null)
            : tt_days_between($since, $today);
        $inFlight[] = [
            'ref'      => $t['ref'],
            'name'     => $t['name'],
            'status'   => $t['status'],
            'since'    => $since,
            'seeded'   => $since === null,
            'age_days' => $age,
            'touches'  => $touchedSince[$t['ref']],
            'last_touched' => $t['last_touched'],
            'watch'    => $t['watch'] ?? null,
        ];
    }
    usort($inFlight, static function ($a, $b) {
        return [$b['touches'], $b['age_days'] ?? -1] <=> [$a['touches'], $a['age_days'] ?? -1];
    });

    // ---- cycle time: first taught to secure, over topics that made it ---
    $cycle = ['fresh' => [], 'seeded' => []];
    $openedOn = [];
    foreach ($changes as $c) {
        $fromPts = STATUS_POINTS[$c['from']] ?? 0;
        $toPts   = STATUS_POINTS[$c['to']] ?? 0;
        if ($fromPts === 0 && $toPts >= 1 && !isset($openedOn[$c['ref']])) {
            $openedOn[$c['ref']] = $c['date'];
        }
        if ($fromPts < 2 && $toPts >= 2) {
            if (isset($openedOn[$c['ref']])) {
                $cycle['fresh'][] = tt_days_between($openedOn[$c['ref']], $c['date']);
            } else {
                $cycle['seeded'][] = tt_days_between($weeks ? $weeks[0]['monday'] : $c['date'], $c['date']);
            }
            unset($openedOn[$c['ref']]);
        }
    }
    $median = static function (array $v): ?float {
        if (!$v) {
            return null;
        }
        sort($v);
        $n = count($v);
        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    };
    $cycleTime = [
        'fresh_median_days'  => $median($cycle['fresh']),
        'fresh_count'        => count($cycle['fresh']),
        'seeded_median_days' => $median($cycle['seeded']),
        'seeded_count'       => count($cycle['seeded']),
    ];

    // ---- the daily line, for the chart ---------------------------------
    $daily = [];
    if ($tracked) {
        for ($d = $startMonday; $d <= $today; $d = tt_add_days($d, 1)) {
            $daily[] = ['date' => $d, 'pts' => $pointsAt($d), 'pct' => $pct($pointsAt($d))];
        }
    }

    // ---- three weeks ago, and the start, for the headline card ---------
    $threeWeeksAgo = $pointsAt(tt_add_days($today, -21));

    $counts = array_fill_keys(STATUS_ORDER, 0);
    foreach ($topics as $t) {
        $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
    }
    $limits = progress_limits(array_map(static fn($w) => $w['net'],
        array_values(array_filter($weeks, static fn($w) => $w['blocks'] > 0 && $w['complete']))));

    $model = [
        'subject'     => $subject,
        'today'       => $today,
        'tracked'     => $tracked,
        'first_day'   => $tracked ? $first : null,
        'unit'        => ['topics' => $n, 'max' => $max, 'points' => $pointsNow, 'pct' => $pct($pointsNow),
                          'three_weeks_ago_pct' => $pct($threeWeeksAgo), 'start_pct' => $pct($basePts),
                          'counts' => $counts],
        'goal'        => ['pct' => $goalPct, 'pts' => $goalPts, 'date' => $goalDate,
                          'set' => $aimline['goal_set'] ?? false],
        'aimline'     => $aimline,
        'behind_run'  => $behind,
        'stalled_run' => $stalled,
        'daily'       => $daily,
        'weeks'       => $weeks,
        'rates'       => $rates,
        'pace'        => $pace,
        'future'      => $future,
        'cone'        => $cone,
        'cfd'         => $cfd,
        'in_flight'   => $inFlight,
        'cycle_time'  => $cycleTime,
        'limits'      => $limits,
        'changes'     => $changes,
    ];
    $model['signals'] = progress_signals($model);
    return $model;
}

/**
 * The cone: the future re-run many times from the past weeks' rates.
 *
 * Two levels of resampling. Each run first re-draws which past weeks count
 * (n draws with replacement from the n rates), then gives every future week
 * the rate of one of those, scaled to that week's blocks. The outer draw
 * carries the uncertainty in the rate itself, which with four weeks of
 * record is most of the uncertainty there is; the inner draw carries the
 * week-to-week noise. Seeded by subject and date, so the page holds still
 * within a day and a test can pin it.
 *
 * @param array<int,float> $rates steps per block, one per past week
 * @param array<int,array{end:string,blocks:int}> $future
 */
function progress_cone(array $rates, array $future, int $now, int $max, int $goalPts, int $securePts, int $runs, string $seed): array
{
    $n = count($rates);
    $k = count($future);
    mt_srand(crc32($seed), MT_RAND_MT19937);
    $paths = [];
    $reachGoal = [];
    $reachSecure = [];
    for ($r = 0; $r < $runs; $r++) {
        $pool = [];
        for ($i = 0; $i < $n; $i++) {
            $pool[] = $rates[mt_rand(0, $n - 1)];
        }
        $p = $now;
        $rg = null;
        $rs = null;
        $path = [];
        for ($w = 0; $w < $k; $w++) {
            $p += (int) round($pool[mt_rand(0, $n - 1)] * $future[$w]['blocks']);
            $p = max(0, min($max, $p));
            $path[] = $p;
            if ($rg === null && $p >= $goalPts) {
                $rg = $w;
            }
            if ($rs === null && $p >= $securePts) {
                $rs = $w;
            }
        }
        $paths[] = $path;
        $reachGoal[] = $rg;
        $reachSecure[] = $rs;
    }
    $q = static function (array $sorted, float $f) use ($runs) {
        return $sorted[(int) floor($f * ($runs - 1))];
    };
    $bands = [];
    for ($w = 0; $w < $k; $w++) {
        $col = array_column($paths, $w);
        sort($col);
        $bands[] = ['end' => $future[$w]['end'], 'blocks' => $future[$w]['blocks'],
            'p5' => $q($col, .05), 'p15' => $q($col, .15), 'p50' => $q($col, .5),
            'p85' => $q($col, .85), 'p95' => $q($col, .95)];
    }
    $milestone = static function (array $reach, int $pts) use ($future, $runs, $k, $q): array {
        $got = array_values(array_filter($reach, static fn($x) => $x !== null));
        sort($got);
        $count = count($got);
        $byEnd = $count / $runs;
        $date  = static function (float $f) use ($got, $count, $runs, $future): ?string {
            $idx = (int) floor($f * ($runs - 1));
            return $idx < $count ? $future[$got[$idx]]['end'] : null;
        };
        return ['pts' => $pts, 'p_by_horizon' => round($byEnd, 3),
                'p50_date' => $date(.5), 'p85_date' => $date(.85)];
    };
    return [
        'runs'   => $runs,
        'bands'  => $bands,
        'goal'   => $milestone($reachGoal, $goalPts),
        'secure' => $milestone($reachSecure, $securePts),
    ];
}

/**
 * Natural process limits on the weekly steps (Wheeler's XmR chart): the mean
 * and ±2.66 × the mean moving range. Null until there are enough weeks for
 * the limits to say anything; with four they would flag nothing.
 *
 * @param array<int,int> $nets complete weeks with blocks, oldest first
 */
function progress_limits(array $nets): ?array
{
    $n = count($nets);
    if ($n < PROGRESS_LIMITS_WEEKS) {
        return null;
    }
    $mean = array_sum($nets) / $n;
    $mr   = 0.0;
    for ($i = 1; $i < $n; $i++) {
        $mr += abs($nets[$i] - $nets[$i - 1]);
    }
    $mr /= ($n - 1);
    return [
        'weeks' => $n,
        'mean'  => round($mean, 2),
        'upper' => round($mean + 2.66 * $mr, 2),
        'lower' => round(max(0.0, $mean - 2.66 * $mr), 2),
    ];
}

/**
 * What the record is saying, as a list a person or a model can act on.
 * Each signal carries a code, a level (info · warn · alert) and one line.
 * Ordered most serious first.
 */
function progress_signals(array $m): array
{
    $out  = [];
    $pace = $m['pace'];
    $cone = $m['cone'];
    $name = $m['subject']['name'];
    $goalWord = $m['goal']['pct'] >= 100 ? 'exam-ready everywhere' : $m['goal']['pct'] . '%';
    $goalDate = $m['goal']['date'] ? ' by ' . tt_pretty($m['goal']['date']) : '';

    if (!$m['tracked']) {
        $out[] = ['code' => 'no_record', 'level' => 'info',
            'text' => "Nothing has been logged for $name yet, so there is no line to draw."];
        return $out;
    }

    // Stalled: consecutive weeks with blocks and no steps.
    if ($m['stalled_run'] >= 3) {
        $out[] = ['code' => 'stalled', 'level' => 'alert',
            'text' => "Stalled {$m['stalled_run']} weeks running: blocks on the timetable, nothing moved up."];
    } elseif ($m['stalled_run'] === 2) {
        $out[] = ['code' => 'stalled', 'level' => 'warn',
            'text' => 'Two weeks running with blocks and no steps gained.'];
    }

    // The aimline rule.
    if ($m['aimline'] !== null) {
        if ($m['behind_run'] >= 4) {
            $out[] = ['code' => 'behind_aimline', 'level' => 'alert',
                'text' => "{$m['behind_run']} consecutive weeks under the aimline: the rule says change the teaching, not wait."];
        } elseif ($m['behind_run'] >= 2) {
            $out[] = ['code' => 'behind_aimline', 'level' => 'warn',
                'text' => "{$m['behind_run']} consecutive weeks under the aimline; the rule to change the teaching fires at four."];
        }
    }

    // Off target: the cone's odds, or the plain pace when there is no cone.
    if ($cone !== null) {
        $p = $cone['goal']['p_by_horizon'];
        if ($p < 0.5) {
            $out[] = ['code' => 'off_target', 'level' => $p < 0.25 ? 'alert' : 'warn',
                'text' => 'At the pace of the last ' . $cone['weeks_of_pace'] . ' weeks there is a ' . round($p * 100)
                    . "% chance of being $goalWord$goalDate; needs {$pace['needed_per_week']} steps a week, delivering {$pace['per_week']}."];
        }
    } elseif ($pace['needed_per_week'] !== null && $pace['weeks_of_pace'] > 0
        && $pace['per_week'] < $pace['needed_per_week']) {
        $out[] = ['code' => 'off_target', 'level' => 'warn',
            'text' => "Delivering {$pace['per_week']} steps a week against {$pace['needed_per_week']} needed to be $goalWord$goalDate"
                . ' (too early for a cone: ' . $pace['weeks_of_pace'] . ' of ' . PROGRESS_MIN_WEEKS . ' weeks).'];
    }

    // No new topics opened.
    $sinceOpened = null;
    $run = 0;
    for ($i = count($m['weeks']) - 1; $i >= 0; $i--) {
        $w = $m['weeks'][$i];
        if ($w['opened'] > 0) {
            break;
        }
        if ($w['blocks'] > 0 && ($w['complete'] || $w['sessions'] > 0)) {
            $run++;
        }
    }
    $unopened = ($m['unit']['counts']['notstarted'] ?? 0) + ($m['unit']['counts']['gap'] ?? 0);
    if ($run >= 3 && $unopened > 0) {
        $out[] = ['code' => 'no_new_topics', 'level' => 'warn',
            'text' => "No new topic opened for $run weeks with blocks; $unopened topics have never been taught."];
    }

    // Regressions in the last four weeks.
    $downs = [];
    foreach (array_slice($m['weeks'], -4) as $w) {
        foreach ($w['moves'] as $c) {
            if ($c['delta'] < 0) {
                $downs[] = $c['ref'] . ' ' . (STATUS_LABEL[$c['from']] ?? $c['from']) . ' → ' . (STATUS_LABEL[$c['to']] ?? $c['to']) . ' (' . $c['date'] . ')';
            }
        }
    }
    if ($downs) {
        $out[] = ['code' => 'regressions', 'level' => count($downs) >= 2 ? 'warn' : 'info',
            'text' => count($downs) . ' topic' . (count($downs) === 1 ? '' : 's') . ' moved down in the last four weeks: ' . implode('; ', $downs) . '.'];
    }

    // Stuck: open topics touched repeatedly without moving, or open and untouched.
    $stuck = [];
    $idle  = [];
    foreach ($m['in_flight'] as $t) {
        if ($t['status'] !== 'developing') {
            continue;
        }
        if ($t['touches'] >= 3 && ($t['age_days'] ?? 0) >= 21) {
            $stuck[] = $t['ref'] . ' (' . $t['touches'] . ' touches, ' . intdiv((int) $t['age_days'], 7) . ' weeks)';
        } elseif ($t['touches'] === 0 && $t['last_touched'] !== null && tt_days_between($t['last_touched'], $m['today']) >= 42) {
            $idle[] = $t['ref'];
        }
    }
    if ($stuck) {
        $out[] = ['code' => 'stuck_topics', 'level' => 'warn',
            'text' => 'Developing and not moving despite repeated work: ' . implode(', ', $stuck) . '.'];
    }
    if ($idle) {
        $out[] = ['code' => 'idle_developing', 'level' => 'info',
            'text' => 'Developing and untouched for six weeks or more: ' . implode(', ', $idle) . '.'];
    }

    // Work in progress against throughput.
    $wip = $m['unit']['counts']['developing'] ?? 0;
    if ($wip > 0 && $pace['per_week'] > 0) {
        $wait = round($wip / $pace['per_week'], 1);
        if ($wait >= 4) {
            $out[] = ['code' => 'wip_high', 'level' => 'info',
                'text' => "$wip topics developing at {$pace['per_week']} steps a week is about $wait weeks of work in flight; opening more before these close makes each wait longer."];
        }
    }

    if ($cone === null && $pace['weeks_of_pace'] < PROGRESS_MIN_WEEKS) {
        $out[] = ['code' => 'too_early', 'level' => 'info',
            'text' => 'The cone needs ' . PROGRESS_MIN_WEEKS . ' weeks of pace and has ' . $pace['weeks_of_pace'] . '; the line and the aimline are drawn.'];
    } elseif ($cone !== null && $cone['provisional']) {
        $out[] = ['code' => 'provisional', 'level' => 'info',
            'text' => 'The cone is built from ' . $cone['weeks_of_pace'] . ' weeks of pace and is provisional until ' . PROGRESS_SETTLED_WEEKS . '; it narrows on its own as weeks are added.'];
    }

    if (!$out) {
        $out[] = ['code' => 'on_track', 'level' => 'info',
            'text' => 'On or above the aimline, nothing stalled, nothing stuck.'];
    }
    $rank = ['alert' => 0, 'warn' => 1, 'info' => 2];
    usort($out, static fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return $out;
}

/** One-word reading of a week's flag, for a pill or a table cell. */
function progress_flag_word(array $w): string
{
    return match ($w['flag']) {
        'no_blocks' => 'no blocks',
        'missed'    => 'nothing logged',
        'slipped'   => 'slipped',
        'stalled'   => 'stalled',
        'ahead'     => ($w['vs_aim'] ?? 0) . ' ahead',
        'behind'    => abs((int) ($w['vs_aim'] ?? 0)) . ' behind',
        'moved'     => 'moved',
        default     => 'in progress',
    };
}
