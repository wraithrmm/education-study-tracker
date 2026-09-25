<?php
/**
 * tracker_progress_forecast: the progress page as text a model can read —
 * signals first, then the pace, the cone, the weeks, what is in flight, and
 * the chart data behind /s/{slug}/progress. Read-only. The calculation is
 * progress_compute(); this file only prints it.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

function mcp_progress_tools(array $readOnly, array $subjectArg): array
{
    return [
        [
            'name'  => 'tracker_progress_forecast',
            'title' => 'Progress over time, the aimline and the forecast cone',
            'description' =>
                "How a subject's coverage has moved week by week, whether it is on the straight line to its goal, "
                . "where the last weeks' pace lands it by exam day, and the signals that say a week stalled: "
                . "the numbers behind /s/{slug}/progress.\n\n"
                . "USE WHEN: asked whether she is on track, how progress compares with a few weeks ago, whether progress "
                . "has stalled, what she will have covered by the exam, or whether new topics are being opened — and in "
                . "every Friday weekly review and Saturday synthesis, once per subject, so the SIGNALS block is considered "
                . "each week. The progress-forecast skill says how to read it.\n\n"
                . "The unit is the step: one topic moving up one level (notstarted/gap 0, developing 1, secure 2, exam-ready 3), "
                . "so a subject with N topics has 3N steps and the headline percentage is steps done over steps possible. "
                . "SIGNALS lists what the record is saying, most serious first, each with a code: stalled (weeks running with "
                . "blocks and no steps), behind_aimline (consecutive weeks under the line; the rule fires at four), off_target "
                . "(the cone's odds of the goal), no_new_topics, regressions, stuck_topics, idle_developing, wip_high, "
                . "too_early, provisional, on_track. The aimline runs from coverage on the first tracked day to the goal "
                . "(every step on exam day unless goal_pct/goal_date is set on the subject). The cone re-runs the future "
                . "5,000 times from the subject's own past weeks in steps per timetabled block, so booked days off carry no "
                . "steps; it needs four weeks of pace and calls itself provisional until eight.\n\n"
                . "Args: subject (slug). Optional format 'text' (default) or 'json' for the whole model; weeks (default 12) "
                . 'caps the week-by-week table. Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'format'  => ['type' => 'string', 'enum' => ['text', 'json'], 'default' => 'text'],
                    'weeks'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 12,
                        'description' => 'How many of the most recent weeks to print in the table'],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
    ];
}

function mcp_progress_call(Store $store, array $a): array
{
    $slug = mcp_str($a, 'subject', true, 1);
    $r    = mcp_resolve($store, $slug);
    if (isset($r['error'])) {
        return mcp_text($r['error']);
    }
    $format = mcp_str($a, 'format', false, 0, 10, 'text');
    $weeks  = (int) mcp_num($a, 'weeks', false, 1, 60, 12);
    $m      = progress_compute($store->progressInputs($slug));

    if ($format === 'json') {
        $out = $m;
        unset($out['changes'], $out['daily'], $out['future']);
        foreach ($out['weeks'] as &$w) {
            unset($w['moves']);
        }
        unset($w);
        $out['weeks'] = array_slice($out['weeks'], -$weeks);
        return mcp_text(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    return mcp_text(mcp_progress_text($m, $weeks));
}

/** The page as text. */
function mcp_progress_text(array $m, int $weeks): string
{
    $s     = $m['subject'];
    $u     = $m['unit'];
    $pace  = $m['pace'];
    $cone  = $m['cone'];
    $pct   = static fn(?float $v): string => $v === null ? '—' : (string) round($v) . '%';
    $date  = static fn(?string $d): string => $d === null ? '—' : tt_pretty($d);
    $signed = static fn(int $n): string => ($n > 0 ? '+' : '') . $n;

    $lines = ['**Progress and forecast — ' . $s['name'] . '** · ' . tt_pretty($m['today'])
        . ($s['exam_date'] ? ' · ' . tt_days_between($m['today'], $s['exam_date']) . ' days to the exam' : ' · no exam date set')];
    if (!$m['tracked']) {
        $lines[] = 'Nothing logged yet: no sessions and no status changes. The line starts with the first one.';
        return implode("\n", $lines);
    }
    $lines[] = "Steps: {$u['points']} of {$u['max']} (" . $pct($u['pct']) . ') · ' . $pct($u['three_weeks_ago_pct'])
        . ' three weeks ago · ' . $pct($u['start_pct']) . ' on ' . tt_pretty($m['first_day'])
        . ($m['aimline'] !== null && $m['weeks'] ? ' · aimline says ' . $pct(end($m['weeks'])['aim_pct']) . ' today' : '');
    $goalWord = $m['goal']['pct'] >= 100 ? 'exam-ready everywhere' : $m['goal']['pct'] . '% of the syllabus';
    $lines[] = "Goal: $goalWord ({$m['goal']['pts']} steps)" . ($m['goal']['date'] ? ' by ' . $date($m['goal']['date']) : ', no date')
        . ($m['goal']['set'] ? ' (set by the parent)' : ' (default; goal_pct / goal_date on tracker_create_subject change it)');
    $counts = $u['counts'];
    $lines[] = 'Now: ' . implode(' · ', array_map(
        static fn($k) => (STATUS_LABEL[$k] ?? $k) . ' ' . ($counts[$k] ?? 0), STATUS_ORDER));

    $lines[] = "\n### SIGNALS (read these first)";
    foreach ($m['signals'] as $sig) {
        $lines[] = '- ' . strtoupper($sig['level']) . ' ' . $sig['code'] . ' — ' . $sig['text'];
    }

    $lines[] = "\n### PACE";
    if ($pace['weeks_of_pace'] > 0) {
        $ran = implode(' · ', array_map(static fn($r) => $signed((int) $r['net']), $m['rates']));
        $lines[] = "- Last {$pace['weeks_of_pace']} weeks with blocks: {$pace['per_week']} steps a week "
            . "({$pace['per_block']} per block, {$pace['blocks_per_week']} blocks a week; weeks ran $ran)";
    } else {
        $lines[] = '- No week with timetabled blocks yet, so no pace.';
    }
    if ($pace['needed_per_week'] !== null) {
        $lines[] = "- Needed: {$pace['needed_per_week']} a week over {$pace['future_blocks']} blocks to the goal ({$pace['remaining_steps']} steps to go)";
    } elseif ($pace['remaining_steps'] === 0) {
        $lines[] = '- The goal is met.';
    } else {
        $lines[] = "- Needed: {$pace['remaining_steps']} steps to go, but no timetabled blocks before the goal date, so no rate can be named.";
    }
    if ($pace['last_two_lands'] !== null) {
        $lines[] = "- If the last two weeks' pace holds: " . $pct($pace['last_two_lands']) . ' by the horizon; the whole window\'s pace: '
            . $pct($pace['window_lands']);
    }

    $lines[] = "\n### THE CONE";
    if ($cone === null) {
        $lines[] = $pace['weeks_of_pace'] < PROGRESS_MIN_WEEKS
            ? '- Not drawn: ' . PROGRESS_MIN_WEEKS . ' weeks of pace needed, ' . $pace['weeks_of_pace'] . ' so far.'
            : '- Not drawn: no goal or exam date ahead to run to.';
    } else {
        $lines[] = '- ' . number_format($cone['runs']) . ' runs from ' . $cone['weeks_of_pace'] . ' weeks of pace'
            . ($cone['provisional'] ? ' (provisional until ' . PROGRESS_SETTLED_WEEKS . ')' : '');
        if ($cone['exam_day'] !== null) {
            $e = $cone['exam_day'];
            $lines[] = '- Exam day ' . $date($s['exam_date']) . ': most likely ' . $pct($e['p50_pct']) . ' · likely '
                . $pct($e['p15_pct']) . '–' . $pct($e['p85_pct']) . ' (15th–85th) · possible ' . $pct($e['p5_pct']) . '–' . $pct($e['p95_pct']) . ' (5th–95th)';
        }
        $g = $cone['goal'];
        $lines[] = "- Goal ({$g['pts']} steps): " . round($g['p_by_horizon'] * 100) . '% chance by ' . $date($m['goal']['date'] ?? $s['exam_date'])
            . ' · typically reached ' . $date($g['p50_date']) . ' · 85% sure by ' . $date($g['p85_date']);
        $sec = $cone['secure'];
        $lines[] = "- Everything secure ({$sec['pts']} steps): " . round($sec['p_by_horizon'] * 100) . '% chance by the horizon'
            . ' · typically ' . $date($sec['p50_date']) . ' · 85% sure by ' . $date($sec['p85_date']);
    }

    $lines[] = "\n### WEEK BY WEEK (most recent $weeks)";
    $lines[] = '| Week | Blocks | Sessions | Touches | Opened | Steps | End | Aimline | Reading |';
    $lines[] = '|---|---|---|---|---|---|---|---|---|';
    foreach (array_slice($m['weeks'], -$weeks) as $w) {
        $lines[] = '| ' . $w['week'] . ' | ' . $w['blocks'] . ' | ' . $w['sessions'] . ' | ' . $w['touches'] . ' | '
            . $w['opened'] . ' | ' . $signed((int) $w['net']) . ' | ' . $pct($w['end_pct']) . ' | ' . $pct($w['aim_pct'])
            . ' | ' . progress_flag_word($w) . ($w['complete'] ? '' : ' (in progress)') . ' |';
    }
    $lines[] = 'Touches are status changes where nothing moved. Opened counts topics taught for the first time '
        . '(not started or gap → developing or better).';
    if ($m['limits'] !== null) {
        $l = $m['limits'];
        $lines[] = "Natural process limits on weekly steps ({$l['weeks']} weeks): mean {$l['mean']}, upper {$l['upper']}, lower {$l['lower']}. "
            . 'A week outside them is a signal; inside is routine variation.';
    } else {
        $lines[] = 'Natural process limits appear once ' . PROGRESS_LIMITS_WEEKS . ' complete weeks with blocks are in.';
    }

    $lines[] = "\n### IN FLIGHT (developing and gap)";
    if (!$m['in_flight']) {
        $lines[] = 'Nothing developing and no known gaps.';
    } else {
        $lines[] = '| Ref | Topic | Status | In this status | Touched, unmoved | Note |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($m['in_flight'] as $t) {
            $age = $t['age_days'] === null ? '—'
                : ($t['seeded'] ? 'since the seed, ' : '') . ($t['age_days'] >= 14 ? intdiv((int) $t['age_days'], 7) . ' weeks' : $t['age_days'] . ' days');
            $lines[] = '| ' . $t['ref'] . ' | ' . $t['name'] . ' | ' . (STATUS_LABEL[$t['status']] ?? $t['status']) . ' | ' . $age
                . ' | ' . $t['touches'] . ' | ' . ($t['watch'] ?? '') . ' |';
        }
    }
    $ct = $m['cycle_time'];
    if ($ct['fresh_count'] > 0) {
        $lines[] = "A freshly opened topic takes {$ct['fresh_median_days']} days from first taught to secure, typically ({$ct['fresh_count']} topics)."
            . ($ct['seeded_count'] > 0 ? " Topics developing since the seed that made it took {$ct['seeded_median_days']} days from the start of the record ({$ct['seeded_count']})." : '');
    }

    $lines[] = "\n### CHART DATA";
    $lines[] = '| Week end | Actual | Aimline | Likely low | Most likely | Likely high |';
    $lines[] = '|---|---|---|---|---|---|';
    foreach (array_slice($m['weeks'], -$weeks) as $w) {
        $lines[] = '| ' . $w['end'] . ' | ' . $pct($w['end_pct']) . ' | ' . $pct($w['aim_pct']) . ' | — | — | — |';
    }
    if ($cone !== null) {
        foreach ($cone['bands'] as $b) {
            $lines[] = '| ' . $b['end'] . ' | — | ' . $pct(mcp_progress_aim_at($m, $b['end'])) . ' | ' . $pct($b['p15_pct'])
                . ' | ' . $pct($b['p50_pct']) . ' | ' . $pct($b['p85_pct']) . ' |';
        }
    }
    $lines[] = '';
    $lines[] = '| Week end | ' . implode(' | ', array_map(static fn($k) => STATUS_LABEL[$k] ?? $k, STATUS_ORDER)) . ' |';
    $lines[] = '|---|' . str_repeat('---|', count(STATUS_ORDER));
    foreach (array_slice($m['cfd'], -($weeks + 1)) as $row) {
        $lines[] = '| ' . $row['date'] . ' | ' . implode(' | ', array_map(static fn($k) => $row[$k] ?? 0, STATUS_ORDER)) . ' |';
    }
    $lines[] = "\nPage: /s/{$s['slug']}/progress";
    return implode("\n", $lines);
}

/** The aimline's percentage on a date, from the model's endpoints. */
function mcp_progress_aim_at(array $m, string $day): ?float
{
    $a = $m['aimline'];
    if ($a === null) {
        return null;
    }
    $span = tt_days_between(tt_add_days($a['from_date'], -1), $a['to_date']);
    if ($span <= 0) {
        return $a['to_pct'];
    }
    $f = max(0.0, min(1.0, tt_days_between(tt_add_days($a['from_date'], -1), $day) / $span));
    return round($a['from_pct'] + $f * ($a['to_pct'] - $a['from_pct']), 1);
}
