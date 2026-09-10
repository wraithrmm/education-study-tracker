<?php
/**
 * The lesson review on the pages. Private by default: the review, the
 * signals and the error history render only behind the parent gate; the
 * public dashboard shows one boolean per session — reviewed or not — and
 * nothing else.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const DASH_REVIEW_CSS = <<<'CSS'
.rv-chip{display:inline-block;font-size:.7rem;border-radius:999px;padding:.1rem .55rem;border:1px solid transparent;
  white-space:nowrap;vertical-align:middle}
.rv-progress{background:#d1fae5;color:#065f46;border-color:#6ee7b7}
.rv-progress_with_retrieval{background:#ccfbf1;color:#115e59;border-color:#5eead4}
.rv-consolidate{background:#fef3c7;color:#92400e;border-color:#fcd34d}
.rv-partial_reteach{background:#ffedd5;color:#9a3412;border-color:#fdba74}
.rv-significant_reteach{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.rv-tick{color:#065f46;font-size:.8rem;white-space:nowrap}
.rv-miss{color:#9a3412;font-size:.8rem;white-space:nowrap}
.rv-box{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem 1.2rem;margin-top:.75rem}
.rv-box h3{font-size:.95rem;margin:1rem 0 .25rem}
.rv-box h3:first-child{margin-top:0}
.rv-box ul{margin:.25rem 0 .5rem 1.1rem;padding:0}
.rv-box li{margin:.15rem 0;font-size:.9rem}
.rv-planner{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.5rem .9rem;margin:.4rem 0 .6rem}
.rv-planner div{background:#fffdf5;border:1px solid #fde68a;border-radius:8px;padding:.5rem .7rem;font-size:.85rem}
.rv-planner b{display:block;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.rv-vers{display:flex;flex-wrap:wrap;gap:.4rem;margin:.4rem 0;font-size:.8rem}
.rv-vers a,.rv-vers span{border:1px solid var(--line);border-radius:6px;padding:.15rem .5rem;background:#fff}
.rv-vers span{background:#292524;color:#fff;border-color:#292524}
.rv-quote{font-style:italic;border-left:3px solid #d6d3d1;padding-left:.7rem;margin:.3rem 0}
.rv-str{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.7rem;border-radius:4px;padding:.05rem .35rem;
  background:#e7e5e4;color:#292524;white-space:nowrap}
.rv-str.established{background:#292524;color:#fff}
.rv-str.emerging{background:#fde68a}
.rv-ev{font-size:.8rem;color:var(--muted);margin:.1rem 0 .1rem 1rem}
CSS;

/** The readiness call as a chip. */
function rv_chip(?string $readiness): string
{
    if ($readiness === null || $readiness === '') {
        return '';
    }
    return '<span class="rv-chip rv-' . h($readiness) . '">' . h(review_readiness_label($readiness)) . '</span>';
}

/** The public boolean: a tick when a session has a review, a quiet mark when it is owed one. */
function rv_public_mark(array $session): string
{
    if (($session['review_id'] ?? null) !== null) {
        return ' <span class="rv-tick" title="Lesson review saved">✓ reviewed</span>';
    }
    return '';
}

/** A strength as a small tag. */
function rv_strength(string $s): string
{
    return '<span class="rv-str ' . h($s) . '">' . h($s) . '</span>';
}

/** One planner as the six boxes the next session reads. */
function rv_planner_html(array $planner): string
{
    $out = '<div class="rv-planner">';
    foreach (REVIEW_PLANNER as $k) {
        $out .= '<div><b>' . h(str_replace('_', ' ', $k)) . '</b>' . h((string) ($planner[$k] ?? '—')) . '</div>';
    }
    return $out . '</div>';
}

/** A list of entries as bullets, each rendered by $fn. */
function rv_list(array $items, callable $fn, string $empty = 'none'): string
{
    if (!$items) {
        return '<p><small>' . h($empty) . '</small></p>';
    }
    $out = '<ul>';
    foreach ($items as $x) {
        $out .= '<li>' . $fn($x) . '</li>';
    }
    return $out . '</ul>';
}

/**
 * The full review, section by section, for the parent's session page.
 *
 * @param array $review a hydrated lesson_reviews row
 */
function rv_review_html(Store $store, array $subject, array $session, array $review, array $versions): string
{
    $slug = $subject['slug'];
    $s    = $review['sections'];
    $snap = $review['snapshot'];
    $sid  = (int) $session['id'];
    $out  = '<h2>Lesson review ' . rv_chip($s['big_picture']['readiness'] ?? null) . '</h2>';

    $current = (int) $review['version'];
    $out .= '<div class="rv-vers">';
    foreach ($versions as $v) {
        $label = 'v' . $v['version'] . ' ' . $v['stage'];
        $title = review_written_by((string) $v['written_by']) . ', ' . mcp_review_when((string) $v['written_at'])
            . ($v['note'] ? ' — ' . $v['note'] : '');
        $out .= (int) $v['version'] === $current
            ? '<span title="' . h($title) . '">' . h($label) . '</span>'
            : '<a href="/s/' . h($slug) . '/session/' . $sid . '?v=' . (int) $v['version'] . '" title="' . h($title)
                . '">' . h($label) . '</a>';
    }
    $out .= '</div>';
    $out .= '<p><small>Version ' . $current . ' of ' . count($versions) . ', '
        . h(review_written_by((string) $review['written_by'])) . ' ' . h(mcp_review_when((string) $review['written_at']))
        . '.' . ($review['note'] ? ' Note on this version: ' . h((string) $review['note']) : '') . '</small></p>';

    $out .= '<div class="rv-box">';
    $out .= '<h3>One sentence</h3><p>' . h((string) ($s['one_sentence'] ?? '')) . '</p>';
    $out .= '<h3>Planner — what the next session reads</h3>' . rv_planner_html($s['planner'] ?? []);
    $out .= '<h3>Big picture</h3><p>' . rv_chip($s['big_picture']['readiness'] ?? null) . ' '
        . h((string) ($s['big_picture']['why'] ?? '')) . '</p>';

    $out .= '<h3>Progress</h3>' . rv_list($s['progress'] ?? [], static fn(array $p): string =>
        '<a class="mono" href="/s/' . h($slug) . '/t/' . rawurlencode($p['ref']) . '"><b>' . h($p['ref']) . '</b></a> seen <b>'
        . h(STATUS_LABEL[$p['status_seen']] ?? $p['status_seen']) . '</b>'
        . (isset($p['proposed_status']) ? ' · <em>proposed ' . h(STATUS_LABEL[$p['proposed_status']] ?? $p['proposed_status']) . '</em>' : '')
        . ' — ' . h($p['evidence']) . '<div class="rv-ev">→ ' . h($p['implication']) . '</div>');
    $out .= '<h3>Independent</h3><p>' . h((string) ($s['independent'] ?? '')) . '</p>';
    $out .= '<h3>Supported</h3><p>' . h((string) ($s['supported'] ?? '')) . '</p>';
    $out .= '<h3>Errors</h3>' . rv_list($s['errors'] ?? [], static fn(array $e): string =>
        '<b class="mono">' . h($e['ref']) . '</b> <span class="rv-str">' . h($e['error_type']) . '</span> ' . h($e['what'])
        . '<div class="rv-ev">why: ' . h($e['why_type']) . ' · response: ' . h($e['response']) . '</div>', 'no errors recorded');

    $ret = $s['retention'] ?? [];
    $out .= '<h3>Retention</h3><ul>';
    foreach (['retrieved' => 'Retrieved', 'prompted' => 'Prompted', 'not_retrieved' => 'Not retrieved', 'schedule' => 'Schedule'] as $k => $w) {
        $items = $ret[$k] ?? [];
        $out .= '<li><b>' . $w . ':</b> ' . ($items ? h(implode('; ', array_map(
            static fn(array $x): string => $x['ref'] . ' (' . $x['evidence'] . ')', $items
        ))) : '—') . '</li>';
    }
    $out .= '</ul>';

    $out .= '<h3>Learning process</h3>' . rv_list($s['process'] ?? [], static fn(array $p): string =>
        '<span class="rv-str">' . h($p['area']) . '</span> <small>[' . h($p['basis']) . ']</small> ' . h($p['evidence'])
        . '<div class="rv-ev">' . h($p['interpretation']) . ' → ' . h($p['implication']) . '</div>');
    $out .= '<h3>What helped</h3>' . rv_list($s['helped'] ?? [], static fn(array $x): string =>
        '<span class="rv-str">' . h($x['method']) . '</span> ' . h($x['effect']) . ' — ' . h($x['evidence']));
    $out .= '<h3>What hindered</h3>' . rv_list($s['hindered'] ?? [], static fn(array $x): string =>
        '<span class="rv-str">' . h($x['method']) . '</span> ' . h($x['effect']) . ' — ' . h($x['evidence']));
    if (array_key_exists('confidence', $s)) {
        $out .= '<h3>Confidence against accuracy</h3>' . rv_list($s['confidence'], static fn(array $c): string =>
            '<b class="mono">' . h($c['ref']) . '</b> confidence ' . h($c['confidence']) . ', accuracy ' . h($c['accuracy'])
            . ' — ' . h($c['evidence']) . '<div class="rv-ev">→ ' . h($c['implication']) . '</div>', 'no evidence');
    }

    $out .= '<h3>Next — what</h3><ul>';
    foreach (REVIEW_NEXT_WHAT as $k) {
        $refs = $s['next_what'][$k] ?? [];
        if ($refs) {
            $out .= '<li><b>' . h(str_replace('_', ' ', $k)) . ':</b> ' . h(implode(', ', $refs)) . '</li>';
        }
    }
    $out .= '</ul>';
    $out .= '<h3>Next — how</h3>' . rv_list($s['next_how']['stages'] ?? [], static fn(array $st): string =>
        '<b>' . h(str_replace('_', ' ', $st['stage'])) . '</b> · <span class="rv-str">' . h($st['method']) . '</span> — ' . h($st['why']), 'not planned');
    $out .= '<h3>Do differently</h3>' . rv_list($s['do_differently'] ?? [], static fn(string $x): string => h($x));
    $out .= '<h3>Continue</h3>' . rv_list($s['continue'] ?? [], static fn(string $x): string => h($x));
    $out .= '<h3>Things to watch</h3>' . rv_list($s['watch'] ?? [], static fn(array $w): string =>
        '<span class="rv-str">' . h($w['key']) . '</span> ' . h($w['what_to_observe']));
    $out .= '<h3>Learner voice</h3>';
    if (empty($s['learner_voice'])) {
        $out .= '<p><small>Nothing quotable was recorded.</small></p>';
    }
    foreach ($s['learner_voice'] ?? [] as $v) {
        $out .= '<p class="rv-quote">“' . h($v['quote']) . '”' . (!empty($v['context']) ? ' <small>— ' . h($v['context']) . '</small>' : '') . '</p>';
    }
    $out .= '<h3>Missing evidence</h3>' . rv_list($s['missing_evidence'] ?? [], static fn(string $x): string => h($x), 'nothing listed');
    $out .= '</div>';

    // The snapshot and the drift: the record then, and how it has moved.
    $drift = $store->lessonReviewDrift($review);
    $out .= '<h2>Then and now</h2><div class="rv-box"><p><small>Snapshot taken '
        . h(mcp_review_when((string) ($snap['captured_at'] ?? $review['written_at']))) . '.'
        . (!empty($snap['block']) ? ' Block ' . (int) $snap['block']['block_key'] . ' ' . h($snap['block']['label'])
            . ' was judged <b>' . h($snap['block']['status']) . '</b>'
            . (($snap['block']['shape'] ?? null) === 'unmet' ? ' (shape unmet)' : '') . '.' : '')
        . '</small></p>';
    if (!empty($snap['statuses'])) {
        $out .= '<div class="tablewrap"><table><thead><tr><th>Topic</th><th>Before</th><th>After</th><th>Now</th><th>Retrieval</th></tr></thead><tbody>';
        $now = [];
        foreach ($drift['refs'] as $r) {
            $now[$r['ref']] = $r;
        }
        foreach ($snap['statuses'] as $ref => $st) {
            $n = $now[$ref] ?? null;
            $out .= '<tr><td class="mono"><a href="/s/' . h($slug) . '/t/' . rawurlencode((string) $ref) . '">' . h((string) $ref) . '</a></td>'
                . '<td><small>' . h(STATUS_LABEL[$st['before'] ?? ''] ?? (string) ($st['before'] ?? '—')) . '</small></td>'
                . '<td><small>' . h(STATUS_LABEL[$st['after'] ?? ''] ?? (string) ($st['after'] ?? '—')) . '</small></td>'
                . '<td><small>' . ($n && $n['moved'] ? '<b>' : '') . h(STATUS_LABEL[$n['now'] ?? ''] ?? (string) ($n['now'] ?? '—'))
                . ($n && $n['moved'] ? '</b>' : '') . '</small></td>'
                . '<td><small>' . h((string) ($snap['outcomes'][$ref] ?? '—')) . '</small></td></tr>';
        }
        $out .= '</tbody></table></div>';
    }
    $out .= '<ul>';
    foreach ($drift['lines'] as $l) {
        $out .= '<li><small>' . h($l) . '</small></li>';
    }
    $out .= '</ul></div>';

    // The signals this session touched, with the direction each row took.
    $touched = $store->signalsTouchedBy($sid);
    $out .= '<h2>Signals this session touched</h2>';
    if (!$touched) {
        $out .= '<p><small>None.</small></p>';
    } else {
        $out .= '<div class="rv-box"><ul>';
        foreach ($touched as $g) {
            $out .= '<li>' . rv_strength($g['strength']) . ' <span class="rv-str">' . h($g['kind']) . '</span> <b>' . h($g['key'])
                . '</b> — ' . h($g['statement']) . '<div class="rv-ev">' . ($g['touched_direction'] === 'supports' ? '+' : '−')
                . ' ' . h($g['touched_evidence']) . ($g['next_test'] ? ' · next test: ' . h($g['next_test']) : '')
                . ' · <a href="/signals#g' . (int) $g['id'] . '">#' . (int) $g['id'] . '</a></div></li>';
        }
        $out .= '</ul></div>';
    }
    return $out;
}

/** /s/{slug}/reviews — every reviewed session of a subject, newest first. Parent only. */
function render_lesson_reviews(Store $store, array $subject, bool $isParent): string
{
    $slug = $subject['slug'];
    $body = detail_head($subject, 'Lesson reviews', 'One per taught session · newest first');
    $body .= tt_parent_line($store, $isParent, '/s/' . $slug . '/reviews');
    if (!$isParent) {
        $body .= '<div class="flag">Lesson reviews are for the parent. Sign in to read them.</div>';
        return dash_shell('Lesson reviews — ' . $subject['name'], $body);
    }
    $owed = $store->sessionsMissingReview($slug);
    if ($owed) {
        $body .= '<div class="flag">' . count($owed) . ' session' . (count($owed) === 1 ? '' : 's')
            . ' still owed a review: ' . h(implode(', ', array_map(
                static fn(array $x): string => $x['date'] . ' (session ' . $x['id'] . ')', array_slice($owed, 0, 6)
            ))) . (count($owed) > 6 ? ', …' : '') . '. The audit writes them.</div>';
    }
    $pairs = $store->listLessonReviews($slug, ['limit' => 100]);
    if (!$pairs) {
        $body .= '<p><small>No lesson review yet. A taught session saves one through tracker_log_session.</small></p>';
    }
    foreach ($pairs as $pair) {
        $x  = $pair['session'];
        $rv = $pair['review'];
        $body .= '<div class="item"><div class="grow"><strong><a href="/s/' . h($slug) . '/session/' . (int) $x['id'] . '">'
            . h($x['date']) . '</a></strong> ' . rv_chip($rv['sections']['big_picture']['readiness'] ?? null)
            . ' <small>· block ' . ($x['block_key'] === null ? 'extra' : (int) $x['block_key']) . ' · ' . h($rv['stage'])
            . ' v' . (int) $rv['version'] . '</small>'
            . '<div>' . h((string) ($rv['sections']['one_sentence'] ?? '')) . '</div>'
            . '<div><small><em>Priority next: ' . h((string) ($rv['sections']['planner']['priority'] ?? '')) . '</em></small></div>'
            . '</div></div>';
    }
    return dash_shell('Lesson reviews — ' . $subject['name'], $body);
}

/** /signals — every signal across subjects, grouped by strength then kind. Parent only. */
function render_signals(Store $store, bool $isParent): string
{
    $body = '<header><div><p class="kicker"><a href="/">← Subjects</a></p><h1>How she learns</h1>'
        . '<p><small>Every signal the lesson reviews have raised, strongest first, with its evidence trail and the test '
        . 'that would confirm or refute it.</small></p></div><div>' . tt_parent_line($store, $isParent, '/signals') . '</div></header>';
    if (!$isParent) {
        $body .= '<div class="flag">The signals page is for the parent. Sign in to read it.</div>';
        return dash_shell('Signals', $body);
    }
    $all = $store->signals([]);
    if (!$all) {
        $body .= '<p><small>No signals yet. Lesson reviews raise them (signals[] and watch[]).</small></p>';
        return dash_shell('Signals', $body);
    }
    $groups = [];
    foreach ($all as $g) {
        $groups[$g['strength']][$g['kind']][] = $g;
    }
    foreach (array_reverse(SIGNAL_STRENGTHS) as $strength) {
        if (empty($groups[$strength])) {
            continue;
        }
        $n = array_sum(array_map('count', $groups[$strength]));
        $body .= '<h2>' . rv_strength($strength) . ' ' . $n . ' signal' . ($n === 1 ? '' : 's') . '</h2>';
        foreach ($groups[$strength] as $kind => $rows) {
            $body .= '<h3 class="kicker" style="margin:1rem 0 .25rem">' . h(str_replace('_', ' ', $kind)) . '</h3>';
            foreach ($rows as $g) {
                $ev = $store->signalEvidence($g['id']);
                $body .= '<div class="item" id="g' . (int) $g['id'] . '"><div class="grow"><strong>' . h($g['key']) . '</strong>'
                    . ' <small>· ' . h($g['subject_slug'] ?? 'cross-subject') . ' · ' . h($g['status'])
                    . ' · ' . $g['supporting'] . ' supporting' . ($g['contradicting'] ? ', ' . $g['contradicting'] . ' contradicting' : '')
                    . ' · #' . (int) $g['id'] . '</small>'
                    . '<div>' . h($g['statement']) . '</div>'
                    . ($g['next_test'] ? '<div><small><em>Next test: ' . h($g['next_test']) . '</em></small></div>' : '');
                foreach ($ev as $e) {
                    $sess = $store->sessionById($e['session_id']);
                    $body .= '<div class="rv-ev">' . ($e['direction'] === 'supports' ? '+' : '−') . ' '
                        . ($sess ? '<a href="/s/' . h($sess['subject_slug']) . '/session/' . $e['session_id'] . '">' . h($e['date']) . '</a>'
                            : h($e['date'])) . ' — ' . h($e['evidence']) . '</div>';
                }
                $body .= '</div></div>';
            }
        }
    }
    return dash_shell('Signals', $body);
}

/** The week page's parent-only section: the week's readiness chips and the signal movement. */
function rv_week_section(Store $store, string $monday): string
{
    $sunday = tt_add_days($monday, 6);
    $pairs  = $store->lessonReviewsBetween($monday, $sunday);
    $events = $store->signalEventsBetween($monday, $sunday);
    $out    = '<h2 class="panel-title">Lesson reviews this week</h2>';
    if (!$pairs) {
        $out .= '<p><small>No taught session this week has a review saved.</small></p>';
    } else {
        $out .= '<div class="rv-box"><ul>';
        foreach ($pairs as $pair) {
            $x  = $pair['session'];
            $rv = $pair['review'];
            $out .= '<li><a href="/s/' . h($x['subject_slug']) . '/session/' . (int) $x['id'] . '">' . h($x['date']) . ' ' . h($x['subject_slug'])
                . ($x['block_key'] !== null ? ' · block ' . (int) $x['block_key'] : '') . '</a> '
                . rv_chip($rv['sections']['big_picture']['readiness'] ?? null) . ' <small>' . h($rv['stage']) . '</small>'
                . '<div class="rv-ev">' . h((string) ($rv['sections']['one_sentence'] ?? '')) . '</div></li>';
        }
        $out .= '</ul></div>';
    }
    $owed = [];
    foreach ($store->listSubjects() as $s) {
        foreach ($store->sessionsMissingReview($s['slug'], $monday) as $x) {
            if ($x['date'] <= $sunday) {
                $owed[] = $x;
            }
        }
    }
    if ($owed) {
        $out .= '<p><small class="rv-miss">Owed a review: ' . h(implode(', ', array_map(
            static fn(array $x): string => $x['subject_slug'] . ' ' . $x['date'] . ' (session ' . $x['id'] . ')', $owed
        ))) . '.</small></p>';
    }
    $out .= '<h2 class="panel-title">Signal movement</h2>';
    if (!$events) {
        $out .= '<p><small>No signal opened, strengthened, resolved or refuted this week.</small></p>';
        return $out;
    }
    $out .= '<div class="rv-box"><ul>';
    foreach ($events as $ev) {
        $who = '<a href="/signals#g' . (int) $ev['signal_id'] . '">' . h(($ev['subject_slug'] ?? 'cross-subject') . ' ' . $ev['key']) . '</a>';
        $out .= '<li>' . match ((string) $ev['change']) {
            'opened'   => $who . ' opened ' . rv_strength('one_off') . ' — ' . h($ev['statement']),
            'strength' => $who . ' ' . rv_strength((string) $ev['from_value']) . ' → ' . rv_strength((string) $ev['to_value']),
            default    => $who . ' ' . h((string) $ev['from_value']) . ' → <b>' . h((string) $ev['to_value']) . '</b>'
                . ($ev['detail'] ? ' — ' . h((string) $ev['detail']) : ''),
        } . '</li>';
    }
    return $out . '</ul></div>';
}

/** The parent's topic-page section: the error-type tally and the last three errors. */
function rv_topic_errors_html(Store $store, string $slug, string $ref): string
{
    $tally = $store->errorTally($slug, $ref);
    $out   = '<h2>Errors recorded by lesson reviews</h2>';
    if (!$tally) {
        return $out . '<p><small>None yet.</small></p>';
    }
    $total = array_sum($tally);
    $out  .= '<p><small>' . $total . ' error' . ($total === 1 ? '' : 's') . ' recorded: ' . h(implode(', ', array_map(
        static fn($k, $n) => str_replace('_', ' ', $k) . ' ' . $n, array_keys($tally), $tally
    ))) . '.</small></p><div class="rv-box"><ul>';
    foreach ($store->reviewErrorsForRef($slug, $ref, 3) as $e) {
        $out .= '<li><a href="/s/' . h($slug) . '/session/' . (int) $e['session_id'] . '">' . h($e['date']) . '</a> '
            . '<span class="rv-str">' . h($e['error_type']) . '</span> ' . h($e['what'])
            . '<div class="rv-ev">why: ' . h($e['why_type']) . ' · response: ' . h($e['response']) . '</div></li>';
    }
    return $out . '</ul></div>';
}
