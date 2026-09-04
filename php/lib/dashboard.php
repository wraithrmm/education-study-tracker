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
table{width:100%;border-collapse:collapse;margin-top:.75rem;background:var(--card);
  border:1px solid var(--line);border-radius:10px;overflow:hidden;font-size:.85rem}
th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;
  color:var(--muted);font-weight:400;padding:.5rem .6rem;border-bottom:1px solid var(--line)}
td{padding:.5rem .6rem;border-bottom:1px solid #ededea;vertical-align:top}
tr:last-child td{border-bottom:0}
tr.full td:first-child{box-shadow:inset 3px 0 0 #10b981}
tr.part td:first-child{box-shadow:inset 3px 0 0 #fbbf24}
tr.none td:first-child{box-shadow:inset 3px 0 0 #ef4444}
.tablewrap{overflow-x:auto}
.chartbox{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.75rem}
.chartdata{margin-top:.4rem}
.chartdata summary{cursor:pointer;color:var(--muted)}
/* The activity picker: cards in place of a dropdown, chips in place of two
   date boxes, and the date boxes themselves kept behind a disclosure. */
.picker{margin:1.2rem 0 .2rem}
.picker .plabel{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);
  margin:0 0 .5rem}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(11.5rem,1fr));gap:.7rem}
.tile{position:relative;isolation:isolate;display:grid;gap:.3rem;align-content:start;
  padding:.8rem .9rem;border:1px solid var(--line);border-radius:12px;background:var(--card);
  text-decoration:none;color:inherit;transition:transform .16s ease,box-shadow .16s ease}
.tile:hover{transform:translateY(-3px);box-shadow:0 8px 18px -10px rgba(28,25,23,.5)}
.tile .tname{display:flex;justify-self:start;align-items:center;gap:.45rem;
  font-weight:700;font-size:.95rem}
.tile .tname svg{flex:none}
.tile .tnum{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:1.5rem;
  font-weight:700;line-height:1.1}
.tile .tsub{font-size:.74rem;color:var(--muted)}
.tile[aria-current] .tname{position:relative}
.tile[aria-current] .tname::before{content:"";position:absolute;z-index:-1;
  inset:-.15em -.3em -.1em -.3em;background:#fde68a;border-radius:3px;transform:rotate(-.7deg)}
.tile[aria-current]{border-color:#292524;box-shadow:inset 0 0 0 1px #292524}
.tile.empty{border-style:dashed;background:transparent;opacity:.7}
.tile.empty:hover{transform:none;box-shadow:none}
.meter{height:6px;border-radius:999px;background:#eceae5;overflow:hidden;margin-top:.15rem}
.meter>i{display:block;height:100%;background:#7c3aed;border-radius:999px}
.ranges{display:flex;flex-wrap:wrap;gap:.4rem;align-items:flex-start;margin-top:.65rem}
.rangechip,.pickdates>summary{font-size:.8rem;padding:.28rem .7rem;border:1px solid var(--line);
  border-radius:999px;background:var(--card);text-decoration:none;color:var(--ink);cursor:pointer}
.rangechip:hover,.pickdates>summary:hover{border-color:#a8a29e}
.rangechip[aria-current]{background:#292524;border-color:#292524;color:#fcfcf9}
.pickdates>summary{list-style:none;display:inline-block}
.pickdates>summary::-webkit-details-marker{display:none}
.pickdates[open]{flex-basis:100%}
.pickdates[open]>summary{background:#f5f5f4}
.pickdates .filters{margin:.6rem 0 0}
.ranges .clear{margin-left:.2rem}
.tile:focus-visible,.rangechip:focus-visible,.pickdates>summary:focus-visible{
  outline:2px solid #7c3aed;outline-offset:2px}
.filters{display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin:1rem 0}
.filters label{display:flex;flex-direction:column;gap:.15rem}
.filters input,.filters select,.filters button{font:inherit;font-size:.85rem;padding:.3rem .5rem;
  border:1px solid var(--line);border-radius:6px;background:#fff}
.filters button{cursor:pointer;padding:.35rem .8rem}
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
    $attempts = $store->listAttempts($subject['slug'], 50);

    $lastPaper = null;
    foreach ($attempts as $x) {
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

    $withResources = $store->refsWithResources($subject['slug']);

    // The first four panels of the subject's scoreboard, plus a link to the
    // rest. Empty when nothing has been practised, rather than an empty board.
    $practiceHtml = practice_dashboard_section($store, $subject);

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
            $chips .= '<a class="chip" href="/s/' . h($subject['slug']) . '/t/'
                . rawurlencode((string) $t['ref']) . '" style="text-decoration:none"'
                . ' title="' . h(STATUS_LABEL[$t['status']] ?? $t['status']) . ' — click for its history">'
                . '<span class="dot" style="background:' . (STATUS_COLOUR[$t['status']] ?? '#d6d3d1') . '"></span>'
                . '<b class="mono">' . h($t['ref']) . '</b> ' . h($t['name'])
                . ($t['watch'] ? '<b style="color:#d97706">!</b>' : '')
                . (in_array($t['ref'], $withResources, true) ? '<b style="color:#0ea5e9" title="has resources">&#9633;</b>' : '')
                . ($t['tier'] === 'H' ? '<b style="color:#a8a29e">H</b>' : '')
                . '</a>';
        }
        $chipGroups .= '<h3 class="kicker mono" style="margin:1rem 0 0">' . h($label) . '</h3>'
            . '<div class="chips">' . $chips . '</div>';
    }

    $resourceHtml = '';
    $allResources = $store->listResources($subject['slug']);
    if ($allResources) {
        $byRef = [];
        foreach ($allResources as $r) {
            $byRef[$r['ref']][] = $r;
        }
        $topicName = [];
        foreach ($topics as $t) {
            $topicName[$t['ref']] = $t['name'];
        }
        ksort($byRef);
        $resourceHtml = '<h2>Resources</h2>';
        foreach ($byRef as $ref => $rows) {
            $heading = $ref === ''
                ? 'For the whole subject'
                : h($ref) . ' · ' . h($topicName[$ref] ?? 'unknown topic');
            $items = '';
            foreach ($rows as $r) {
                $label = $r['url']
                    ? '<a href="' . h($r['url']) . '" rel="noopener noreferrer">' . h($r['title']) . '</a>'
                    : h($r['title']);
                $items .= '<div><small><b class="mono">' . h($r['kind']) . '</b> ' . $label
                    . ($r['note'] ? ' — ' . h($r['note']) : '') . '</small></div>';
            }
            $resourceHtml .= '<div class="item"><div class="grow"><strong>' . $heading . '</strong>'
                . $items . '</div></div>';
        }
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
                . '<div class="grow"><strong class="mono"><a href="/s/' . h($subject['slug']) . '/t/'
                . rawurlencode((string) $t['ref']) . '">' . h($t['ref']) . '</a></strong> ' . h($t['name'])
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
    if ($attempts) {
        foreach ($attempts as $x) {
            $outcome = $x['kind'] === 'check'
                ? round(($x['score'] / max((float) $x['max'], 1)) * 100) . '% · no grade'
                : '≈ grade ' . h(gradeFor($subject, (float) $x['score'], (float) $x['max'], (string) $x['tier']));
            $meta = h($x['date']) . ' · tier ' . h($x['tier']);
            if ($x['blanks'] !== null) {
                $meta .= ' · ' . (int) $x['blanks'] . ' blank' . ((int) $x['blanks'] === 1 ? '' : 's');
            }
            // Papers are listed under the attempt they belong to; the grade
            // sits on the attempt because that is the only level it means
            // anything at.
            $papers = '';
            foreach ($x['papers'] as $paper) {
                $nq = count($store->listQuestions((int) $paper['id']));
                $papers .= '<div><small><b class="mono">' . h($paper['code']) . '</b> '
                    . num($paper['score']) . '/' . num($paper['max'])
                    . ($paper['blanks'] !== null ? ' · ' . (int) $paper['blanks'] . ' blank' : '')
                    . ($nq ? ' · ' . $nq . ' questions recorded' : '')
                    . ($paper['note'] ? ' — ' . h($paper['note']) : '')
                    . '</small></div>';
            }
            $assessHtml .= '<div class="item"><div class="grow"><strong><a href="/s/' . h($subject['slug'])
                . '/a/' . (int) $x['id'] . '">' . h($x['name']) . '</a></strong>'
                . '<div><small>' . $meta . '</small></div>'
                . $papers
                . ($x['note'] ? '<div><small>' . h($x['note']) . '</small></div>' : '')
                . '</div><div class="num"><strong class="mono">' . num($x['score']) . '/' . num($x['max']) . '</strong>'
                . '<div><small>' . $outcome . '</small></div></div></div>';
        }
    } else {
        $assessHtml = '<p><small>Nothing logged yet.</small></p>';
    }

    // Grouped by ISO week so the page reads as a timeline of what actually
    // happened, with the status changes each session produced underneath it.
    $sessions    = $store->listSessions($subject['slug'], 40);
    $sessionHtml = '';
    if ($sessions) {
        $weeks = [];
        foreach ($sessions as $x) {
            $w = Store::weekOf($x['date']);
            $weeks[$w['label']]['monday'] = $w['monday'];
            $weeks[$w['label']]['rows'][] = $x;
        }
        krsort($weeks);
        foreach ($weeks as $label => $wk) {
            $sessionHtml .= '<h3 class="kicker mono" style="margin:1rem 0 0">' . h($label)
                . ' · week of ' . h($wk['monday']) . '</h3>';
            foreach ($wk['rows'] as $x) {
                $changes = $store->changesForSession((int) $x['id']);
                $moved   = '';
                foreach ($changes as $c) {
                    $from = $c['from_status'] ? (STATUS_LABEL[$c['from_status']] ?? $c['from_status']) : '—';
                    $to   = STATUS_LABEL[$c['to_status']] ?? $c['to_status'];
                    $moved .= '<div><small><a class="mono" href="/s/' . h($subject['slug']) . '/t/'
                        . rawurlencode((string) $c['ref']) . '"><b>' . h($c['ref']) . '</b></a> '
                        . h($from) . ' → ' . h($to) . ' — ' . h($c['evidence']) . '</small></div>';
                }
                $void = $x['void_reason'] ?? null;
                $sessionHtml .= '<div class="item"><div class="grow"><strong><a href="/s/'
                    . h($subject['slug']) . '/session/' . (int) $x['id'] . '">' . h($x['date'])
                    . '</a></strong>'
                    . ($void ? ' <b style="color:#b91c1c">VOID</b>' : '')
                    . '<div><small>' . h($x['summary']) . '</small></div>'
                    . ($void ? '<div><small>Voided: ' . h($void) . '</small></div>' : '')
                    . $moved
                    . ($x['next_steps'] ? '<div><small><em>Next: ' . h($x['next_steps']) . '</em></small></div>' : '')
                    . '</div></div>';
            }
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
        ? '<a href="/s/' . h($subject['slug']) . '/a/' . (int) $lastPaper['id'] . '" style="text-decoration:none">'
            . '<p class="big mono">' . num($lastPaper['score']) . '<small>/' . num($lastPaper['max']) . '</small></p>'
            . '<p><small>≈ grade ' . h(gradeFor($subject, (float) $lastPaper['score'], (float) $lastPaper['max'], (string) $lastPaper['tier']))
            . ' · ' . h($lastPaper['date']) . '</small></p></a>'
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

{$practiceHtml}

<h2>Strands</h2>{$strandRows}
<div class="legend">{$legend}</div>

<h2>Every topic</h2>{$chipGroups}

{$looseHtml}

{$resourceHtml}

<h2>Papers &amp; checks</h2>{$assessHtml}

<h2>Sessions, by week</h2>{$sessionHtml}

<footer>Generated live from the tracker database at {$when} UTC.
  {$notes}</footer>
HTML;

    return dash_shell($subject['name'] . ' tracker', $body);
}

// ---- detail pages -------------------------------------------------------
//
// The tools could always return the question-by-question record; the dashboard
// could not show it. These three pages are the auditable views: one sitting in
// full, one session in full, and one topic's whole history.

/** Shared page furniture: a back link, a title, and a subtitle line. */
function detail_head(array $subject, string $title, string $sub): string
{
    return '<header><div><p class="kicker"><a href="/s/' . h($subject['slug']) . '">← '
        . h($subject['name']) . '</a></p><h1>' . h($title) . '</h1>'
        . '<p><small>' . $sub . '</small></p></div></header>';
}

function render_attempt(Store $store, array $subject, array $x): string
{
    $slug    = $subject['slug'];
    $outcome = $x['kind'] === 'check'
        ? round(((float) $x['score'] / max((float) $x['max'], 1)) * 100) . '% · not grade-converted'
        : '≈ grade ' . h(gradeFor($subject, (float) $x['score'], (float) $x['max'], (string) $x['tier']))
            . ' on tier ' . h((string) $x['tier']);

    $body = detail_head($subject, (string) $x['name'],
        h((string) $x['date']) . ' · ' . h((string) $x['kind']) . ' · '
        . count($x['papers']) . ' paper' . (count($x['papers']) === 1 ? '' : 's')
        . ' · <strong class="mono">' . num($x['score']) . '/' . num($x['max']) . '</strong> · ' . $outcome);

    if ($x['note']) {
        $body .= '<div class="flag">' . h((string) $x['note']) . '</div>';
    }

    foreach ($x['papers'] as $paper) {
        $body .= '<h2>' . h((string) $paper['code']) . ' <span class="mono">'
            . num($paper['score']) . '/' . num($paper['max']) . '</span></h2>';
        $bits = [];
        if (!empty($paper['sat_on'])) {
            $bits[] = 'sat ' . h((string) $paper['sat_on']);
        }
        if ($paper['blanks'] !== null) {
            $bits[] = (int) $paper['blanks'] . ' left blank';
        }
        if ($paper['note']) {
            $bits[] = h((string) $paper['note']);
        }
        if ($bits) {
            $body .= '<p><small>' . implode(' · ', $bits) . '</small></p>';
        }

        if (!$paper['questions']) {
            $body .= '<p><small>No question breakdown was recorded for this paper — only the total.</small></p>';
            continue;
        }

        $body .= '<div class="tablewrap"><table><thead><tr><th>Q</th><th>Topic</th><th>Marks</th>'
            . '<th>Question</th><th>Answer given</th><th>Note</th></tr></thead><tbody>';
        foreach ($paper['questions'] as $q) {
            $lost  = (float) $q['max'] - (float) $q['score'];
            $klass = $lost <= 0 ? 'full' : ((float) $q['score'] > 0 ? 'part' : 'none');
            $ref   = $q['topic_ref'] ?? null;
            $body .= '<tr class="' . $klass . '"><td class="mono">' . h((string) $q['number']) . '</td>'
                . '<td class="mono">' . ($ref !== null
                    ? '<a href="/s/' . h($slug) . '/t/' . rawurlencode($ref) . '">' . h($ref) . '</a>'
                    : '—') . '</td>'
                . '<td class="mono">' . num($q['score']) . '/' . num($q['max']) . '</td>'
                . '<td>' . h((string) ($q['question'] ?? '')) . '</td>'
                . '<td>' . h((string) ($q['answer'] ?? '')) . '</td>'
                . '<td><small>' . h((string) ($q['note'] ?? '')) . '</small></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    // What the marks actually say about the teaching, rather than the score.
    $breakdown = $store->attemptTopicBreakdown($slug, (int) $x['id']);
    if ($breakdown) {
        $body .= '<h2>Marks by topic</h2>'
            . '<p><small>Ordered by marks lost. These are the candidates for reteaching.</small></p>'
            . '<div class="tablewrap"><table><thead><tr><th>Topic</th><th>Name</th><th>Marks</th><th>Lost</th></tr></thead><tbody>';
        foreach ($breakdown as $b) {
            $lost  = (float) $b['max'] - (float) $b['score'];
            $body .= '<tr class="' . ($lost > 0 ? 'part' : 'full') . '">'
                . '<td class="mono"><a href="/s/' . h($slug) . '/t/' . rawurlencode((string) $b['ref']) . '">'
                . h((string) $b['ref']) . '</a></td>'
                . '<td>' . h((string) $b['name']) . '</td>'
                . '<td class="mono">' . num($b['score']) . '/' . num($b['max']) . '</td>'
                . '<td class="mono">' . num($lost) . '</td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    return dash_shell($x['name'] . ' — ' . $subject['name'], $body);
}

function render_session(Store $store, array $subject, array $x): string
{
    $slug = $subject['slug'];
    $void = $x['void_reason'] ?? null;

    $body = detail_head($subject, 'Session ' . $x['id'],
        h((string) $x['date']) . ' · ' . h(Store::weekOf((string) $x['date'])['label'])
        . ($void ? ' · <b style="color:#b91c1c">VOID</b>' : ''));

    if ($void) {
        $body .= '<div class="flag">Voided: ' . h((string) $void)
            . '<br><small>The row is kept rather than deleted, and no longer counts towards'
            . ' the review queue or the export.</small></div>';
    }
    $body .= '<h2>What happened</h2><p>' . h((string) $x['summary']) . '</p>';
    if ($x['next_steps']) {
        $body .= '<h2>Planned next</h2><p>' . h((string) $x['next_steps']) . '</p>';
    }

    $changes = $store->changesForSession((int) $x['id']);
    $body   .= '<h2>What this session changed</h2>';
    if (!$changes) {
        $body .= '<p><small>No topic statuses were changed in this session.</small></p>';
    } else {
        $body .= '<div class="tablewrap"><table><thead><tr><th>Topic</th><th>Name</th><th>Change</th><th>Evidence recorded</th></tr>'
            . '</thead><tbody>';
        foreach ($changes as $c) {
            $from  = $c['from_status'] ? (STATUS_LABEL[$c['from_status']] ?? $c['from_status']) : '—';
            $to    = STATUS_LABEL[$c['to_status']] ?? $c['to_status'];
            $body .= '<tr><td class="mono"><a href="/s/' . h($slug) . '/t/'
                . rawurlencode((string) $c['ref']) . '">' . h((string) $c['ref']) . '</a></td>'
                . '<td>' . h((string) ($c['topic_name'] ?? '')) . '</td>'
                . '<td><small>' . h($from) . ' → <strong>' . h($to) . '</strong></small></td>'
                . '<td><small>' . h((string) $c['evidence']) . '</small></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    return dash_shell('Session ' . $x['id'] . ' — ' . $subject['name'], $body);
}

function render_topic_history(Store $store, array $subject, array $topic): string
{
    $slug = $subject['slug'];
    $ref  = (string) $topic['ref'];
    $body = detail_head($subject, $ref . ' ' . (string) $topic['name'],
        'Currently <strong>' . h(STATUS_LABEL[$topic['status']] ?? (string) $topic['status']) . '</strong>'
        . ($topic['last_touched'] ? ' · last touched ' . h((string) $topic['last_touched']) : ''));

    if (!empty($topic['watch'])) {
        $body .= '<div class="flag">' . h((string) $topic['watch']) . '</div>';
    }

    // Every status this topic has held, and why — the whole point of keeping
    // evidence on each change rather than only a current status.
    $h    = $store->history($slug, 520, $ref);
    $body .= '<h2>Every change</h2>';
    if (!$h['changes']) {
        $body .= '<p><small>No recorded changes. The status is where it was seeded.</small></p>';
    } else {
        $body .= '<div class="tablewrap"><table><thead><tr><th>When</th><th>Change</th><th>Evidence</th><th>Session</th></tr>'
            . '</thead><tbody>';
        foreach ($h['changes'] as $c) {
            $from  = $c['from_status'] ? (STATUS_LABEL[$c['from_status']] ?? $c['from_status']) : '—';
            $to    = STATUS_LABEL[$c['to_status']] ?? $c['to_status'];
            $body .= '<tr><td class="mono"><small>' . h(substr((string) $c['changed_at'], 0, 10)) . '</small></td>'
                . '<td><small>' . h($from) . ' → <strong>' . h($to) . '</strong></small></td>'
                . '<td><small>' . h((string) $c['evidence']) . '</small></td>'
                . '<td>' . ($c['session_id']
                    ? '<a href="/s/' . h($slug) . '/session/' . (int) $c['session_id'] . '">session '
                        . (int) $c['session_id'] . '</a>'
                    : '<small>standalone</small>') . '</td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    // Where this topic has been examined, pulled back out of the question rows.
    $qs    = $store->questionsForTopic($slug, $ref);
    $body .= '<h2>In papers</h2>';
    if (!$qs) {
        $body .= '<p><small>No marked question has been recorded against this topic yet.</small></p>';
    } else {
        $body .= '<div class="tablewrap"><table><thead><tr><th>Attempt</th><th>Paper</th><th>Q</th><th>Marks</th><th>Note</th></tr>'
            . '</thead><tbody>';
        foreach ($qs as $q) {
            $lost  = (float) $q['max'] - (float) $q['score'];
            $body .= '<tr class="' . ($lost <= 0 ? 'full' : ((float) $q['score'] > 0 ? 'part' : 'none')) . '">'
                . '<td><small><a href="/s/' . h($slug) . '/a/' . (int) $q['attempt_id'] . '">'
                . h((string) $q['attempt_name']) . '</a></small></td>'
                . '<td class="mono"><small>' . h((string) $q['code']) . '</small></td>'
                . '<td class="mono">' . h((string) $q['number']) . '</td>'
                . '<td class="mono">' . num($q['score']) . '/' . num($q['max']) . '</td>'
                . '<td><small>' . h((string) ($q['note'] ?? '')) . '</small></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    $resources = $store->resourcesForTopic($slug, $ref);
    if ($resources) {
        $body .= '<h2>Materials</h2>';
        foreach ($resources as $r) {
            $body .= '<div class="item"><div class="grow"><strong>'
                . ($r['url'] ? '<a href="' . h((string) $r['url']) . '">' . h((string) $r['title']) . '</a>'
                    : h((string) $r['title'])) . '</strong>'
                . ($r['note'] ? '<div><small>' . h((string) $r['note']) . '</small></div>' : '')
                . '</div><div class="num"><small>' . h((string) $r['kind']) . '</small></div></div>';
        }
    }

    return dash_shell($ref . ' — ' . $subject['name'], $body);
}
