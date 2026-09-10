<?php
/**
 * The weekly synthesis on the pages: beneath the weekly review on the week
 * page, the learner model at /learner, the week plan on the subject page,
 * the tests on the signals page. All parent-only; public surfaces gain
 * nothing.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const DASH_SYNTH_CSS = <<<'CSS'
.sy-planner{display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:.4rem .8rem;margin:.4rem 0 .8rem}
.sy-planner div{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:.45rem .65rem;font-size:.85rem}
.sy-planner b{display:block;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.sy-part{margin:.9rem 0 .2rem;font-size:.95rem}
.sy-part small{color:var(--muted);font-weight:400}
.sy-status{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.7rem;border-radius:4px;padding:.05rem .35rem;white-space:nowrap}
.sy-established{background:#292524;color:#fff}
.sy-supported{background:#d1fae5;color:#065f46}
.sy-hypothesis{background:#e7e5e4;color:#292524}
.sy-weakened{background:#fef3c7;color:#92400e}
.sy-disproved{background:#fee2e2;color:#991b1b}
.sy-strip{display:flex;gap:.25rem;flex-wrap:wrap;margin:.3rem 0}
.sy-strip span{font-size:.65rem;border:1px solid var(--line);border-radius:4px;padding:.05rem .35rem;background:#fff}
.sy-plan{background:#fffdf5;border:1px solid #fde68a;border-radius:10px;padding:.8rem 1rem;margin-top:1rem}
.sy-plan dl{margin:.3rem 0 0;display:grid;grid-template-columns:max-content 1fr;gap:.15rem .7rem;font-size:.85rem}
.sy-plan dt{color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;padding-top:.15rem}
.sy-plan dd{margin:0}
.sy-answered{color:#065f46;font-size:.75rem}
.sy-untested{color:#9a3412;font-size:.75rem}
CSS;

/** A learner-model status as a tag. */
function sy_status(string $s): string
{
    return '<span class="sy-status sy-' . h($s) . '">' . h($s) . '</span>';
}

/** The ten planner fields as boxes. */
function sy_planner_html(array $planner): string
{
    $out = '<div class="sy-planner">';
    foreach (SYNTH_PLANNER as $k) {
        $out .= '<div><b>' . h(str_replace('_', ' ', $k)) . '</b>' . h((string) ($planner[$k] ?? '—')) . '</div>';
    }
    return $out . '</div>';
}

/** A week plan as the card the subject page shows. */
function sy_plan_card(array $plan, ?array $syn): string
{
    $out = '<div class="sy-plan"><p class="kicker">This week\'s plan · ' . h($plan['week'])
        . ($syn ? ' · from the synthesis of ' . h($syn['week']) . ' v' . (int) $syn['version'] : '')
        . (!empty($plan['stale']) ? ' · <b>stale</b> — no newer synthesis' : '')
        . ($plan['read_at'] !== null ? ' · <span class="rv-tick">✓ read by the queue ' . h(tt_local((string) $plan['read_at'])[0]) . '</span>'
            : ' · <span class="rv-miss">not yet read by the queue</span>') . '</p><dl>';
    foreach (WEEK_PLAN_FIELDS as $f) {
        if (empty($plan[$f])) {
            continue;
        }
        $out .= '<dt>' . h(str_replace('_', ' ', $f)) . '</dt><dd>' . h((string) $plan[$f]) . '</dd>';
    }
    return $out . '</dl></div>';
}

/**
 * The synthesis beneath the weekly review on the week page: the planner
 * pinned first, then the parts, then Part 20 as decided / happened.
 */
function sy_week_section(Store $store, string $iso, ?int $version): string
{
    $versions = $store->weekSynthesisVersions($iso);
    $out      = '<h2 class="panel-title">The learning synthesis</h2>';
    if (!$versions) {
        return $out . '<p><small>No synthesis for this week yet. The Saturday routine writes it after the audit.</small></p>';
    }
    $syn = $store->weekSynthesis($iso, $version) ?? $store->weekSynthesis($iso);
    $s   = $syn['sections'];
    $current = (int) $syn['version'];
    $out .= '<div class="rv-vers">';
    foreach ($versions as $v) {
        $label = 'v' . $v['version'] . ' ' . $v['stage'];
        $title = synth_written_by((string) $v['written_by']) . ', ' . mcp_review_when((string) $v['written_at']) . ($v['note'] ? ' — ' . $v['note'] : '');
        $out  .= (int) $v['version'] === $current
            ? '<span title="' . h($title) . '">' . h($label) . '</span>'
            : '<a href="/week/' . h($iso) . '?sv=' . (int) $v['version'] . '" title="' . h($title) . '">' . h($label) . '</a>';
    }
    $out .= '</div><p><small>Version ' . $current . ' of ' . count($versions) . ', ' . h(synth_written_by((string) $syn['written_by'])) . ' '
        . h(mcp_review_when((string) $syn['written_at'])) . '.' . ($syn['note'] ? ' Note: ' . h((string) $syn['note']) : '') . '</small></p>';

    $out .= '<div class="rv-box">';
    $out .= '<h3 class="sy-part">Planner <small>— pinned; what next week reads</small></h3>' . sy_planner_html($s['planner'] ?? []);
    $out .= '<h3 class="sy-part">1 · At a glance</h3><p>' . h((string) ($s['glance']['picture'] ?? '')) . '</p><p><b>'
        . h((string) ($s['glance']['most_important'] ?? '')) . '</b></p>';
    $out .= '<h3 class="sy-part">2 · Subject by subject</h3><ul>';
    foreach ($s['subjects'] ?? [] as $sub) {
        $out .= '<li><b>' . h($sub['slug']) . '</b> ' . rv_chip($sub['readiness']) . ' ' . h($sub['why'])
            . '<div class="rv-ev">retention: ' . h($sub['retention']) . ' · independence: ' . h($sub['independence']) . '</div>';
        foreach (['secure', 'developing', 'fragile', 'gaps'] as $k) {
            if (!empty($sub[$k])) {
                $out .= '<div class="rv-ev">' . $k . ': ' . h(implode('; ', array_map(static fn(array $x): string => $x['ref'] . ' (' . $x['evidence'] . ')', $sub[$k]))) . '</div>';
            }
        }
        $out .= '</li>';
    }
    $out .= '</ul>';
    $out .= '<h3 class="sy-part">3 · Cross-subject</h3>' . rv_list($s['cross_subject'] ?? [], static fn(array $c): string =>
        rv_strength($c['judgement'] === 'one_off_untested' ? 'one_off' : $c['judgement']) . ' <b>' . h($c['key']) . '</b> ' . h($c['observation'])
        . '<div class="rv-ev">' . h($c['evidence']) . ' (sessions ' . h(implode(', ', $c['sessions'])) . ') → ' . h($c['implication']) . '</div>', 'none this week');
    $out .= '<h3 class="sy-part">4 · What helped</h3>' . rv_list($s['helped'] ?? [], static fn(array $x): string =>
        '<span class="rv-str">' . h($x['method']) . '</span> ' . h($x['verdict']) . ' — ' . h($x['evidence']) . '<div class="rv-ev">improved: ' . h($x['improved']) . '</div>');
    $out .= '<h3 class="sy-part">5 · What hindered</h3>' . rv_list($s['hindered'] ?? [], static fn(array $x): string =>
        rv_strength($x['confidence']) . ' ' . h($x['issue']) . ' — ' . h($x['evidence']) . '<div class="rv-ev">→ ' . h($x['change']) . '</div>');
    $out .= '<h3 class="sy-part">6 · Retention</h3>' . rv_list($s['retention'] ?? [], static fn(array $r): string =>
        '<b class="mono">' . h($r['subject'] . ' ' . $r['ref']) . '</b> <span class="rv-str">' . h($r['verdict']) . '</span> ' . h($r['evidence']) . ' → ' . h($r['action']));
    if (array_key_exists('confidence', $s)) {
        $out .= '<h3 class="sy-part">7 · Confidence</h3>' . rv_list($s['confidence'], static fn(array $c): string =>
            h($c['belief']) . ' / ' . h($c['performance']) . '<div class="rv-ev">' . h($c['meaning']) . ' → ' . h($c['response']) . ' (' . h($c['evidence']) . ')</div>', 'no evidence');
    }
    if (!empty($s['independence'])) {
        $i = $s['independence'];
        $out .= '<h3 class="sy-part">8 · Independence <small>' . h($i['trend']) . '</small></h3><p>' . h($i['prompts_evidence']) . '</p><ul>';
        foreach ($i['fade'] as $f) {
            $out .= '<li>fade: ' . h($f['support']) . ' — ' . h($f['why']) . '</li>';
        }
        foreach ($i['keep'] as $f) {
            $out .= '<li>keep: ' . h($f['support']) . ' — ' . h($f['why']) . '</li>';
        }
        $out .= '</ul><p>' . h($i['reasoning']) . '</p>';
    }
    $out .= '<h3 class="sy-part">9 · Errors</h3>' . rv_list($s['errors'] ?? [], static fn(array $e): string =>
        '<span class="rv-str">' . h($e['error_type']) . '</span> ' . h(implode('; ', $e['examples'])) . '<div class="rv-ev">' . h($e['explanation']) . ' → ' . h($e['response']) . '</div>', 'no recurring type');
    $out .= '<h3 class="sy-part">10 · Hypotheses</h3>' . rv_list($s['hypotheses'] ?? [], static fn(array $x): string =>
        '<b>' . h($x['signal_key']) . '</b> ' . h($x['hypothesis']) . '<div class="rv-ev">how: ' . h($x['how']) . ' · collect: ' . h(implode('; ', $x['collect']))
        . '<br>supports if: ' . h($x['supports']) . ' · challenges if: ' . h($x['challenges']) . '</div>', 'none — insufficient evidence');
    if (!empty($s['learner_voice']['groups'])) {
        $out .= '<h3 class="sy-part">11 · Learner voice</h3>';
        foreach ($s['learner_voice']['groups'] as $g) {
            $out .= '<p><b>' . h($g['theme']) . '</b></p>';
            foreach ($g['quotes'] as $q) {
                $out .= '<p class="rv-quote">“' . h($q) . '”</p>';
            }
        }
        if (!empty($s['learner_voice']['perception_vs_evidence'])) {
            $out .= '<p><small>' . h($s['learner_voice']['perception_vs_evidence']) . '</small></p>';
        }
    }
    $out .= '<h3 class="sy-part">12 · Learner model changes</h3>' . rv_list($s['model'] ?? [], static fn(array $m): string =>
        '<b>' . h($m['key']) . '</b> <span class="rv-str">' . h($m['change']) . '</span> ' . h($m['statement'])
        . '<div class="rv-ev">signals: ' . h(implode(', ', $m['signal_keys'])) . (!empty($m['note']) ? ' — ' . h($m['note']) : '') . '</div>', 'no change');
    $out .= '<h3 class="sy-part">13 · Priorities</h3><ol>';
    foreach ($s['priorities'] ?? [] as $p) {
        $out .= '<li><b>' . h($p['priority']) . '</b> — ' . h($p['why']) . '<div class="rv-ev">' . h($p['evidence']) . ' → ' . h($p['action']) . '</div></li>';
    }
    $out .= '</ol>';
    $out .= '<h3 class="sy-part">14 · Week plans <small>for ' . h((string) ($syn['snapshot']['plans_for'] ?? '')) . '</small></h3>';
    foreach ($s['week_plans'] ?? [] as $p) {
        $live = $store->weekPlan((string) ($syn['snapshot']['plans_for'] ?? ''), $p['subject_slug']);
        $out .= '<p><b><a href="/s/' . h($p['subject_slug']) . '">' . h($p['subject_slug']) . '</a></b>'
            . ($live && $live['read_at'] !== null ? ' <span class="rv-tick">✓ read by the queue</span>' : '') . '</p><ul>';
        foreach (week_plan_lines($p) as $line) {
            $out .= '<li><small>' . h(trim($line)) . '</small></li>';
        }
        $out .= '</ul>';
    }
    $arch = $s['architecture'] ?? [];
    $out .= '<h3 class="sy-part">15 · Lesson architecture' . (empty($arch['sufficient_evidence']) ? ' <small>insufficient evidence</small>' : '') . '</h3>'
        . rv_list($arch['stages'] ?? [], static fn(array $st): string => '<b>' . h(str_replace('_', ' ', $st['stage'])) . '</b> — ' . h($st['why']), 'not proposed');
    $out .= '<h3 class="sy-part">16 · Stop / start / continue</h3><ul>';
    foreach (['stop', 'start', 'continue'] as $k) {
        foreach ($s['stop_start_continue'][$k] ?? [] as $x) {
            $out .= '<li><b>' . $k . '</b> ' . h($x['practice']) . (!empty($x['method']) ? ' <span class="rv-str">' . h($x['method']) . '</span>' : '') . ' — ' . h($x['why']) . '</li>';
        }
    }
    $out .= '</ul>';
    $out .= '<h3 class="sy-part">17 · Observe next week</h3>' . rv_list($s['observe'] ?? [], static fn(array $o): string =>
        '<span class="rv-str">' . h($o['key']) . '</span> ' . h($o['look_for']) . ' — ' . h($o['why']));
    $out .= '<h3 class="sy-part">18 · Big picture <small>' . h((string) ($s['big_picture']['grade_basis'] ?? '')) . '</small></h3><ul>';
    foreach (SYNTH_BIG_PICTURE as $k) {
        $out .= '<li><b>' . h(str_replace('_', ' ', $k)) . ':</b> ' . h((string) ($s['big_picture'][$k] ?? '')) . '</li>';
    }
    $out .= '</ul>';

    // Part 20 as decided / happened: the previous synthesis's decisions on
    // the left, this synthesis's account and the tests' answers on the right.
    $out .= '<h3 class="sy-part">20 · Decided / happened</h3>';
    $prev = $store->previousSynthesis($iso);
    if (!$prev) {
        $out .= '<p><small>No previous synthesis; nothing was decided for this week.</small></p>';
    } else {
        $out .= '<div class="tablewrap"><table><thead><tr><th>Decided (' . h($prev['week']) . ')</th><th>Happened (' . h($iso) . ')</th></tr></thead><tbody>';
        $out .= '<tr><td><small>' . h((string) ($prev['sections']['planner']['success'] ?? '')) . '</small></td><td><small>'
            . h((string) ($s['changes_worked'] ?? '')) . '</small></td></tr>';
        foreach ($store->testsDue($iso) as $t) {
            if (($t['signal']['test_set_by'] ?? null) === 'review') {
                continue;
            }
            $out .= '<tr><td><small>test <b>' . h($t['signal']['key']) . '</b>: ' . h((string) $t['signal']['next_test']) . '</small></td><td>'
                . ($t['answered'] ? '<span class="sy-answered">✓ answered by session ' . (int) $t['by_session'] . '</span>' : '<span class="sy-untested">untested</span>') . '</td></tr>';
        }
        foreach ($s['change'] ?? [] as $c) {
            $out .= '<tr><td><small>' . h($c['category']) . '</small></td><td><small>' . h($c['what']) . ' — ' . h($c['evidence']) . '</small></td></tr>';
        }
        $out .= '</tbody></table></div>';
    }
    $out .= '<p><small>Collect next week: ' . h(!empty($s['collect_next_week']) ? implode(' | ', $s['collect_next_week']) : 'nothing listed') . '</small></p>';
    $out .= '</div>';
    return $out;
}

/** /learner — the learner model, statements by status with their signals and history. Parent only. */
function render_learner(Store $store, bool $isParent, ?string $filter = null): string
{
    $body = '<header><div><p class="kicker"><a href="/">← Subjects</a> · <a href="/signals">Signals</a></p><h1>The learner model</h1>'
        . '<p><small>What the weekly syntheses currently hold about how she learns. Every statement rests on signals, and its '
        . 'status never exceeds what they support.</small></p></div><div>' . tt_parent_line($store, $isParent, '/learner') . '</div></header>';
    if (!$isParent) {
        $body .= '<div class="flag">The learner model is for the parent. Sign in to read it.</div>';
        return dash_shell('Learner model', $body);
    }
    $body .= '<p class="chips">';
    foreach (array_merge(['all'], SYNTH_MODEL_STATUSES) as $st) {
        $body .= ($st === ($filter ?? 'all') ? '<span class="chip"><b>' . h($st) . '</b></span>'
            : '<a class="chip" href="/learner' . ($st === 'all' ? '' : '?status=' . h($st)) . '">' . h($st) . '</a>') . ' ';
    }
    $body .= '</p>';
    $rows = $store->learnerModel($filter);
    if (!$rows) {
        $body .= '<p><small>Nothing here yet. The Saturday synthesis writes the model as deltas (Part 12).</small></p>';
    }
    foreach ($rows as $m) {
        $body .= '<div class="item"><div class="grow">' . sy_status($m['status']) . ' <strong>' . h($m['key']) . '</strong>'
            . '<div>' . h($m['statement']) . '</div><div class="rv-ev">rests on: ' . h(implode('; ', array_map(
                static fn(array $g): string => $g['key'] . ' (' . $g['strength'] . ', ' . $g['supporting'] . ' sessions' . ($g['contradicting'] ? ', ' . $g['contradicting'] . ' contra' : '') . ')',
                $m['signals']))) . '</div><div class="sy-strip">';
        foreach ($m['history'] as $h) {
            $body .= '<span title="' . h((string) ($h['note'] ?? '')) . '">' . h($h['week']) . ' ' . h($h['change']) . ' → ' . h($h['status']) . '</span>';
        }
        $body .= '</div></div></div>';
    }
    $body .= '<footer class="wkfoot"><a href="/signals">Signals</a> · <a href="/weeks">All weeks</a></footer>';
    return dash_shell('Learner model', $body);
}

/** The test lines for a signal on the signals page, with the answered tick for this week. */
function sy_signal_test_html(Store $store, array $g): string
{
    if (empty($g['next_test'])) {
        return '';
    }
    $out = '<div><small><em>Test: ' . h($g['next_test']) . '</em> · set by ' . h($g['test_set_by'] ?? 'review')
        . (!empty($g['test_week']) ? ' for ' . h($g['test_week']) : '');
    if (!empty($g['test_week']) && $g['test_week'] === tt_iso_week(tt_today())) {
        foreach ($store->testsDue($g['test_week']) as $t) {
            if ($t['signal']['id'] === $g['id']) {
                $out .= $t['answered'] ? ' · <span class="sy-answered">✓ answered this week</span>' : ' · <span class="sy-untested">not yet answered</span>';
            }
        }
    }
    $out .= '</small></div>';
    $d = $g['test_design'] ?? null;
    if (is_array($d)) {
        $out .= '<div class="rv-ev">collect: ' . h(implode('; ', $d['collect'] ?? [])) . ' · supports if: ' . h((string) ($d['supports'] ?? ''))
            . ' · challenges if: ' . h((string) ($d['challenges'] ?? '')) . '</div>';
    }
    return $out;
}
