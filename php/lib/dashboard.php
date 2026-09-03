<?php
/**
 * Server-rendered dashboard, ported from src/dashboard.ts. Same markup, same
 * exercise-book styling as the original tracker artifact.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const STATUS_COLOUR = [
    'gap'        => '#ef4444',
    'notstarted' => '#d6d3d1',
    'developing' => '#fbbf24',
    'secure'     => '#10b981',
    'examready'  => '#0ea5e9',
];

const DASH_CSS = <<<'CSS'
:root{--ink:#1c1917;--muted:#78716c;--line:#d6d3d1;--card:#fff}
*{box-sizing:border-box}
body{margin:0;color:var(--ink);background-color:#fcfcf9;
  background-image:linear-gradient(rgba(96,140,200,.12) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(96,140,200,.12) 1px,transparent 1px);
  background-size:24px 24px;
  font-family:Georgia,"Times New Roman",serif;line-height:1.5}
.wrap{max-width:56rem;margin:0 auto;padding:2rem 1rem 4rem}
.mono{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace}
header{display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;justify-content:space-between;
  border-bottom:2px solid #292524;padding-bottom:1rem}
h1{font-size:1.9rem;margin:.2rem 0}
.kicker{font-size:.7rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin:0}
.countdown{display:inline-block;border:2px solid #dc2626;border-radius:999px;padding:.5rem 1rem;
  transform:rotate(2deg);color:#b91c1c}
.countdown b{font-size:1.5rem}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem;margin-top:1.5rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem}
.card p.label{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:0}
.card p.big{font-size:1.9rem;margin:.25rem 0 0;font-weight:700}
.track{height:8px;background:#e7e5e4;border-radius:999px;overflow:hidden;margin-top:.5rem}
.track>div{height:100%;background:#10b981}
h2{font-size:1.15rem;margin:2rem 0 .5rem}
.strand{display:flex;align-items:center;gap:.75rem;margin:.35rem 0}
.strand .nm{width:11rem;flex-shrink:0;font-size:.85rem}
.bar{flex:1;height:20px;background:#fff;border:1px solid var(--line);border-radius:4px;
  overflow:hidden;display:flex}
.bar span{height:100%}
.count{width:3rem;text-align:right;font-size:.75rem;color:var(--muted)}
.chips{display:flex;flex-wrap:wrap;gap:.35rem;margin:.4rem 0 0}
.chip{display:inline-flex;align-items:center;gap:.4rem;font-size:.75rem;border:1px solid var(--line);
  border-radius:6px;padding:.2rem .45rem;background:#fff}
.dot{width:8px;height:8px;border-radius:999px;flex-shrink:0}
.legend{display:flex;flex-wrap:wrap;gap:.75rem;font-size:.75rem;color:var(--muted);margin-top:.6rem}
.item{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.7rem 1rem;
  margin-top:.5rem;display:flex;gap:.75rem;align-items:flex-start}
.item .grow{flex:1;min-width:0}
.item .num{text-align:right;flex-shrink:0}
small{color:var(--muted)}
.flag{background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:.7rem 1rem;
  margin-top:.75rem;font-size:.9rem;color:#78350f}
footer{margin-top:2.5rem;padding-top:1rem;border-top:1px solid var(--line);
  font-size:.75rem;color:var(--muted)}
a{color:#1c1917}
CSS;

function dash_shell(string $title, string $body): string
{
    $t   = h($title);
    $css = DASH_CSS;
    return "<!doctype html><html lang=\"en-GB\"><head><meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<title>$t</title><style>$css</style></head>\n"
        . "<body><div class=\"wrap\">$body</div></body></html>";
}

function render_index(Store $store): string
{
    $subjects = $store->listSubjects();
    $body     = '';
    if ($subjects) {
        foreach ($subjects as $s) {
            $p    = progressFor($store, $s['slug']);
            $n    = count($p['topics']);
            $body .= '<a class="item" href="/s/' . h($s['slug']) . '" style="text-decoration:none">'
                . '<div class="grow"><strong>' . h($s['name']) . '</strong>'
                . '<div><small>' . h($s['spec_code'] ?? '') . ' ' . h($s['tier'] ?? '') . ' · ' . $n . ' topics</small></div>'
                . '</div>'
                . '<div class="num"><strong class="mono">' . $p['pct'] . '%</strong><div><small>covered</small></div></div>'
                . '</a>';
        }
    } else {
        $body = '<p><small>No subjects yet. Ask Claude to create one.</small></p>';
    }

    return dash_shell(
        'Study trackers',
        '<header><div><p class="kicker">Study tracker</p><h1>Subjects</h1></div></header>' . $body
    );
}

function render_subject(Store $store, array $subject): string
{
    $topics = $store->listTopics($subject['slug']);
    $pts    = 0;
    foreach ($topics as $t) {
        $pts += STATUS_POINTS[$t['status']] ?? 0;
    }
    $pct         = $topics ? (int) round(($pts / (count($topics) * 3)) * 100) : 0;
    $assessments = $store->listAssessments($subject['slug']);

    $lastPaper = null;
    foreach ($assessments as $x) {
        if ($x['kind'] === 'paper') {
            $lastPaper = $x;
            break;
        }
    }

    $lower       = array_values(array_filter($topics, static fn($t) => $t['tier'] !== 'H'));
    $lowerSecure = count(array_filter($lower, static fn($t) => $t['status'] === 'secure' || $t['status'] === 'examready'));

    $days = null;
    if ($subject['exam_date']) {
        $when = strtotime($subject['exam_date'] . 'T09:00:00Z');
        if ($when !== false) {
            $days = max(0, (int) ceil(($when - time()) / 86400));
        }
    }

    $strandRows = '';
    $chipGroups = '';
    foreach ($subject['strands'] as $key => $label) {
        $rows = array_values(array_filter($topics, static fn($t) => $t['strand'] === $key));
        if (!$rows) {
            continue;
        }
        $secure = count(array_filter($rows, static fn($t) => $t['status'] === 'secure' || $t['status'] === 'examready'));
        $segs   = '';
        $width  = 100 / count($rows);
        foreach ($rows as $t) {
            $segs .= '<span style="width:' . $width . '%;background:' . (STATUS_COLOUR[$t['status']] ?? '#d6d3d1')
                . '" title="' . h($t['ref'] . ' ' . $t['name']) . '"></span>';
        }
        $strandRows .= '<div class="strand"><span class="nm">' . h($label) . '</span>'
            . '<div class="bar">' . $segs . '</div>'
            . '<span class="count mono">' . $secure . '/' . count($rows) . '</span></div>';

        $chips = '';
        foreach ($rows as $t) {
            $chips .= '<span class="chip" title="' . h(STATUS_LABEL[$t['status']] ?? $t['status']) . '">'
                . '<span class="dot" style="background:' . (STATUS_COLOUR[$t['status']] ?? '#d6d3d1') . '"></span>'
                . '<b class="mono">' . h($t['ref']) . '</b> ' . h($t['name'])
                . ($t['watch'] ? '<b style="color:#d97706">!</b>' : '')
                . ($t['tier'] === 'H' ? '<b style="color:#a8a29e">H</b>' : '')
                . '</span>';
        }
        $chipGroups .= '<h3 class="kicker mono" style="margin:1rem 0 0">' . h($label) . '</h3>'
            . '<div class="chips">' . $chips . '</div>';
    }

    $loose     = array_values(array_filter($topics, static fn($t) => (bool) $t['watch']));
    $looseHtml = '';
    if ($loose) {
        $looseHtml = '<h2>Loose ends</h2>'
            . '<p><small>Secure means it held up independently, not that it is finished. '
            . 'Feed these into starters rather than reteaching.</small></p>';
        foreach ($loose as $t) {
            $looseHtml .= '<div class="item">'
                . '<span class="dot" style="margin-top:.4rem;background:' . (STATUS_COLOUR[$t['status']] ?? '#d6d3d1') . '"></span>'
                . '<div class="grow"><strong class="mono">' . h($t['ref']) . '</strong> ' . h($t['name'])
                . '<div><small>' . h($t['watch']) . '</small></div></div></div>';
        }
    }

    $ageing = [];
    foreach ($topics as $t) {
        if ($t['status'] === 'secure' || $t['status'] === 'examready') {
            $w = weeksSince($t['last_touched']);
            if ($w !== null && $w >= 8) {
                $ageing[] = h($t['ref']) . " ($w weeks)";
            }
        }
    }
    $ageingHtml = $ageing
        ? '<div class="flag"><strong>Due a retrieval check:</strong> ' . implode(', ', $ageing)
            . '. If one fails in a starter, demote it to developing.</div>'
        : '';

    $assessHtml = '';
    if ($assessments) {
        foreach ($assessments as $x) {
            $outcome = $x['kind'] === 'check'
                ? round(($x['score'] / $x['max']) * 100) . '% · no grade'
                : '≈ grade ' . h(gradeFor($subject, (float) $x['score'], (float) $x['max'], (string) $x['tier']));
            $meta = h($x['date']) . ' · tier ' . h($x['tier']);
            if ($x['blanks'] !== null) {
                $meta .= ' · ' . (int) $x['blanks'] . ' blank' . ((int) $x['blanks'] === 1 ? '' : 's');
            }
            $assessHtml .= '<div class="item"><div class="grow"><strong>' . h($x['name']) . '</strong>'
                . '<div><small>' . $meta . '</small></div>'
                . ($x['note'] ? '<div><small>' . h($x['note']) . '</small></div>' : '')
                . '</div><div class="num"><strong class="mono">' . num($x['score']) . '/' . num($x['max']) . '</strong>'
                . '<div><small>' . $outcome . '</small></div></div></div>';
        }
    } else {
        $assessHtml = '<p><small>Nothing logged yet.</small></p>';
    }

    $sessions    = $store->listSessions($subject['slug'], 5);
    $sessionHtml = '';
    if ($sessions) {
        foreach ($sessions as $s) {
            $sessionHtml .= '<div class="item"><div class="grow"><strong>' . h($s['date']) . '</strong>'
                . '<div><small>' . h($s['summary']) . '</small></div>'
                . ($s['next_steps'] ? '<div><small><em>Next: ' . h($s['next_steps']) . '</em></small></div>' : '')
                . '</div></div>';
        }
    } else {
        $sessionHtml = '<p><small>No sessions logged yet.</small></p>';
    }

    $legend = '';
    foreach (STATUS_LABEL as $k => $label) {
        $legend .= '<span><span class="dot" style="display:inline-block;background:'
            . (STATUS_COLOUR[$k] ?? '#d6d3d1') . '"></span> ' . h($label) . '</span>';
    }

    $countdown = $days !== null
        ? '<div style="text-align:right"><div class="countdown"><b class="mono">' . $days . '</b> days to the exam</div>'
            . '<div><small>' . h($subject['exam_date']) . '</small></div></div>'
        : '';

    $latest = $lastPaper
        ? '<p class="big mono">' . num($lastPaper['score']) . '<small>/' . num($lastPaper['max']) . '</small></p>'
            . '<p><small>≈ grade ' . h(gradeFor($subject, (float) $lastPaper['score'], (float) $lastPaper['max'], (string) $lastPaper['tier']))
            . ' · ' . h($lastPaper['date']) . '</small></p>'
        : '<p><small>No full paper logged.</small></p>';

    $kicker      = h($subject['spec_code'] ?? '') . ($subject['tier'] ? ' · ' . h($subject['tier']) : '');
    $when        = h(gmdate('Y-m-d H:i'));
    $notes       = $subject['notes'] ? '<br>' . h($subject['notes']) : '';
    $subjectName = h($subject['name']);
    $lowerCount  = count($lower);

    $body = <<<HTML
<header>
  <div><p class="kicker">{$kicker}</p><h1>{$subjectName}</h1></div>
  {$countdown}
</header>

<div class="stats">
  <div class="card"><p class="label">Spec conquered</p><p class="big mono">{$pct}%</p>
    <div class="track"><div style="width:{$pct}%"></div></div></div>
  <div class="card"><p class="label">Lower-tier secure</p>
    <p class="big mono">{$lowerSecure}<small>/{$lowerCount}</small></p>
    <p><small>topics secure or better</small></p></div>
  <div class="card"><p class="label">Latest paper</p>
    {$latest}</div>
</div>

{$ageingHtml}

<h2>Strands</h2>{$strandRows}
<div class="legend">{$legend}</div>

<h2>Every topic</h2>{$chipGroups}

{$looseHtml}

<h2>Papers &amp; checks</h2>{$assessHtml}

<h2>Recent sessions</h2>{$sessionHtml}

<footer>Generated live from the tracker database at {$when} UTC.
  {$notes}</footer>
HTML;

    return dash_shell($subject['name'] . ' tracker', $body);
}
