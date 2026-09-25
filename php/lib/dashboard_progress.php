<?php
/**
 * The progress page: /s/{slug}/progress.
 *
 * Summary first, then the chart, then the three stall signals, then the
 * topics in flight. Everything is progress_compute() drawn; the page holds
 * no arithmetic of its own. The line and the aimline are public, like the
 * rest of the subject page; the cone and the in-flight table are the
 * parent's, because a probability of missing the goal is his to read, not
 * hers. The design deck under design/progress-forecast is the reference.
 *
 * The SVGs are server-rendered with pinned geometry, in the style of the
 * practice board: a viewBox, hairline gridlines, 2px lines, and a "Chart
 * data" table under every chart so the numbers never depend on the picture.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const DASH_PROGRESS_CSS = <<<'CSS'
.pg-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.75rem;margin-top:1.5rem}
.pg-stats .card p.big small{font-size:.8rem;font-weight:400;color:var(--muted)}
.pg-stats .card p.sub{margin:.35rem 0 0;font-size:.76rem;color:#57534e}
.pg-track{height:7px;background:#e7e5e4;border-radius:999px;overflow:hidden;margin-top:.5rem;position:relative}
.pg-track>i{position:absolute;inset:0 auto 0 0;background:#7c3aed;border-radius:999px}
.pg-track>b{position:absolute;top:-2px;bottom:-2px;width:2px;background:#1c1917}
.pg-flag{border-radius:10px;padding:.7rem 1rem;margin-top:1rem;font-size:.92rem;border:1px solid}
.pg-flag.warn{background:#fffbeb;border-color:#fcd34d}
.pg-flag.warn b{color:#92400e}
.pg-flag.alert{background:#fef2f2;border-color:#fca5a5}
.pg-flag.alert b{color:#991b1b}
.pg-flag.ok{background:#ecfdf5;border-color:#6ee7b7}
.pg-flag.ok b{color:#065f46}
.pg-flag p{margin:0}
.pg-flag p+p{margin-top:.3rem}
.pg-flag .rule{display:block;margin-top:.3rem;font-size:.78rem;color:#78350f}
.pg-chart{width:100%;height:auto;display:block}
.pg-chart .tk{font:11px ui-monospace,"Cascadia Mono",Menlo,monospace;fill:#78716c}
.pg-chart .lb{font:600 12px Georgia,serif;fill:#1c1917}
.pg-legend{display:flex;flex-wrap:wrap;gap:.35rem 1rem;font-size:.72rem;color:var(--muted);margin:.5rem 0 0}
.pg-legend i{display:inline-block;width:18px;height:0;border-top:2px solid #7c3aed;vertical-align:middle;margin-right:.35rem}
.pg-legend i.dash{border-top-style:dashed}
.pg-legend i.dot{border-top:2px dotted #78716c}
.pg-legend i.band{height:10px;border:0;background:rgba(124,58,237,.18);border-radius:2px}
.pg-legend i.band2{height:10px;border:0;background:rgba(124,58,237,.09);border-radius:2px}
.pg-legend i.secl{border-top:2px dashed #10b981}
.pg-legend i.sw{height:10px;border:0;border-radius:2px}
.pg-cap{font-size:.76rem;color:var(--muted);margin:.45rem 0 0;max-width:72ch}
.pg-table{width:100%;border-collapse:collapse;font-size:.84rem;background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;min-width:38rem}
.pg-table th{text-align:left;font-size:.64rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);padding:.5rem .6rem;border-bottom:1px solid var(--line);font-weight:600}
.pg-table td{padding:.5rem .6rem;border-bottom:1px solid #ededea;vertical-align:top}
.pg-table tr:last-child td{border-bottom:0}
.pg-table td.n{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
.pg-table td.w{white-space:nowrap}
.pg-table td.w small{display:block;font-size:.68rem}
.pg-pill{display:inline-block;font:600 .62rem/1.5 ui-monospace,"Cascadia Mono",Menlo,monospace;letter-spacing:.06em;text-transform:uppercase;padding:.05rem .45rem;border-radius:999px;border:1px solid;white-space:nowrap}
.pg-pill.ahead,.pg-pill.moved{color:#065f46;border-color:#6ee7b7;background:#ecfdf5}
.pg-pill.behind{color:#92400e;border-color:#fcd34d;background:#fffbeb}
.pg-pill.stalled,.pg-pill.slipped,.pg-pill.missed{color:#991b1b;border-color:#fca5a5;background:#fef2f2}
.pg-pill.no_blocks,.pg-pill.quiet{color:var(--muted);border-color:var(--line);background:#fafaf9}
.pg-age{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-variant-numeric:tabular-nums}
.pg-age.old{color:#b45309;font-weight:600}
.pg-method{margin-top:1.6rem;font-size:.86rem;color:#44403c}
.pg-method summary{cursor:pointer;font-weight:700;color:var(--ink)}
.pg-method ol{padding-left:1.2rem;margin:.4rem 0 0}
.pg-method li{margin:.3rem 0}
.pg-tip{position:absolute;pointer-events:none;display:none;background:#1c1917;color:#fcfcf9;font:11px/1.45 ui-monospace,"Cascadia Mono",Menlo,monospace;padding:.4rem .55rem;border-radius:6px;white-space:nowrap;z-index:5}
.pg-signin{font-size:.8rem;color:var(--muted);margin:.6rem 0 0}
CSS;

/** Trim a coordinate so the markup does not churn on float noise. */
function pg_coord(float $v): string
{
    $s = number_format($v, 2, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

function pg_title(string $t): string
{
    return '<h2 class="panel-title">' . h($t) . '</h2>';
}

/** Chart numbers as a table too: the chart is never the only route to the data. */
function pg_data_table(array $head, array $rows): string
{
    $out = '<details class="chartdata"><summary><small>Chart data</small></summary>'
        . '<div class="tablewrap" style="overflow-x:auto"><table class="pg-table" style="min-width:0;margin-top:.4rem"><thead><tr>';
    foreach ($head as $cell) {
        $out .= '<th>' . h($cell) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach ($row as $cell) {
            $out .= '<td>' . h((string) $cell) . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div></details>';
}

function pg_pct(?float $v): string
{
    return $v === null ? '—' : (string) (int) round($v) . '%';
}

function pg_date(?string $d): string
{
    if ($d === null) {
        return '—';
    }
    return (new DateTimeImmutable($d, tt_zone()))->format('j M Y');
}

function pg_short(string $d): string
{
    return (new DateTimeImmutable($d, tt_zone()))->format('j M');
}

/**
 * The burn-up: the daily line, the aimline, and (for the parent) the cone.
 * Geometry pinned: viewBox 720×300, plot from x=40 to 656 and y=16 to 268,
 * the y axis 0–100%, the x axis from the series' first Monday to the
 * horizon (goal or exam day) or, without one, a week past today.
 */
function pg_burnup_svg(array $m, string $accent, bool $withCone): string
{
    $W = 720;
    $H = 300;
    $L = 40;
    $R = 64;
    $T = 16;
    $B = 32;
    if (!$m['daily']) {
        return '';
    }
    $x0 = $m['daily'][0]['date'];
    $horizon = $m['goal']['date'] ?? null;
    $exam    = $m['subject']['exam_date'] ?? null;
    if ($exam !== null && $exam > $m['today'] && ($horizon === null || $exam > $horizon)) {
        $horizon = $exam;
    }
    $x1 = $horizon !== null && $horizon > $m['today'] ? tt_add_days($horizon, 2) : tt_add_days($m['today'], 7);
    $span = max(1, tt_days_between($x0, $x1));
    $x = static fn(string $d): float => $L + tt_days_between($x0, $d) / $span * ($W - $L - $R);
    $y = static fn(float $p): float => $T + (1 - $p / 100) * ($H - $T - $B);
    $c = 'pg_coord';

    $s = ['<svg class="pg-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="pg-bu-t">'
        . '<title id="pg-bu-t">Coverage since ' . h(pg_short($x0)) . ' with the aimline'
        . ($withCone && $m['cone'] ? ' and the forecast cone' : '') . '</title>'];
    // Days off, past and future, as bands.
    foreach ($m['days_off'] ?? [] as $off) {
        $a = max($off['date_from'], $x0);
        $b = min($off['date_to'], $x1);
        if ($a > $b) {
            continue;
        }
        $s[] = '<rect x="' . $c($x($a)) . '" y="' . $T . '" width="' . $c(max(1.0, $x(tt_add_days($b, 1)) - $x($a)))
            . '" height="' . $c($H - $T - $B) . '" fill="#e7e5e4" fill-opacity=".55"/>';
    }
    foreach ([0, 25, 50, 75, 100] as $g) {
        $s[] = '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $c($y($g)) . '" y2="' . $c($y($g)) . '" stroke="#e7e5e4" stroke-width="1"/>';
        $s[] = '<text x="' . ($L - 6) . '" y="' . $c($y($g) + 4) . '" text-anchor="end" class="tk">' . $g . '%</text>';
    }
    // Month ticks.
    $mth = (new DateTimeImmutable($x0, tt_zone()))->modify('first day of next month');
    $last = new DateTimeImmutable($x1, tt_zone());
    $months = 0;
    while ($mth <= $last && $months < 24) {
        $d = $mth->format('Y-m-d');
        $s[] = '<line x1="' . $c($x($d)) . '" x2="' . $c($x($d)) . '" y1="' . $c($y(0)) . '" y2="' . $c($y(0) + 4) . '" stroke="#78716c" stroke-width="1"/>';
        $s[] = '<text x="' . $c($x($d) + 3) . '" y="' . $c($y(0) + 16) . '" class="tk">' . $mth->format('M') . '</text>';
        $mth = $mth->modify('first day of next month');
        $months++;
    }
    // Everything secure.
    $s[] = '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $c($y(66.7)) . '" y2="' . $c($y(66.7)) . '" stroke="#10b981" stroke-width="1" stroke-dasharray="2 4"/>';
    $s[] = '<text x="' . ($L + 4) . '" y="' . $c($y(66.7) - 4) . '" class="tk" fill="#047857">everything secure · 67%</text>';

    $nowPct = (float) $m['unit']['pct'];
    $today  = $m['today'];
    // The cone.
    if ($withCone && $m['cone'] !== null) {
        $bands = $m['cone']['bands'];
        $poly  = static function (string $hi, string $lo) use ($bands, $x, $y, $c, $today, $nowPct): string {
            $pts = [$c($x($today)) . ',' . $c($y($nowPct))];
            foreach ($bands as $b) {
                $pts[] = $c($x($b['end'])) . ',' . $c($y((float) $b[$hi]));
            }
            foreach (array_reverse($bands) as $b) {
                $pts[] = $c($x($b['end'])) . ',' . $c($y((float) $b[$lo]));
            }
            return implode(' ', $pts);
        };
        $s[] = '<polygon points="' . $poly('p95_pct', 'p5_pct') . '" fill="' . h($accent) . '" fill-opacity=".08"/>';
        $s[] = '<polygon points="' . $poly('p85_pct', 'p15_pct') . '" fill="' . h($accent) . '" fill-opacity=".14"/>';
        $med = [$c($x($today)) . ',' . $c($y($nowPct))];
        foreach ($bands as $b) {
            $med[] = $c($x($b['end'])) . ',' . $c($y((float) $b['p50_pct']));
        }
        $s[] = '<polyline points="' . implode(' ', $med) . '" fill="none" stroke="' . h($accent) . '" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round"/>';
    }
    // The aimline.
    if ($m['aimline'] !== null) {
        $a = $m['aimline'];
        $ax0 = tt_add_days($a['from_date'], -1);
        $s[] = '<line x1="' . $c($x($ax0)) . '" y1="' . $c($y((float) $a['from_pct'])) . '" x2="' . $c($x($a['to_date'])) . '" y2="' . $c($y((float) $a['to_pct']))
            . '" stroke="#78716c" stroke-width="1.5" stroke-dasharray="1 4" stroke-linecap="round"/>';
        $need = $m['pace']['needed_per_week'];
        if ($need !== null) {
            $mid  = tt_add_days($ax0, (int) (tt_days_between($ax0, $a['to_date']) * 0.3));
            $midY = $a['from_pct'] + 0.3 * ($a['to_pct'] - $a['from_pct']) + 12;
            $ang  = -rad2deg(atan2(($y((float) $a['to_pct']) - $y((float) $a['from_pct'])) * -1, $x($a['to_date']) - $x($ax0)));
            $s[] = '<text x="' . $c($x($mid)) . '" y="' . $c($y((float) $midY)) . '" class="tk" fill="#78716c" transform="rotate(' . $c($ang) . ' '
                . $c($x($mid)) . ' ' . $c($y((float) $midY)) . ')">aimline · ' . h((string) $need) . ' steps a week</text>';
        }
    }
    // The line.
    $pts = [];
    foreach ($m['daily'] as $d) {
        $pts[] = $c($x($d['date'])) . ',' . $c($y((float) $d['pct']));
    }
    $s[] = '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . h($accent) . '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>';
    $s[] = '<circle cx="' . $c($x($today)) . '" cy="' . $c($y($nowPct)) . '" r="4" fill="' . h($accent) . '" stroke="#fff" stroke-width="2"/>';
    $s[] = '<text x="' . $c($x($today) - 8) . '" y="' . $c($y($nowPct) - 9) . '" text-anchor="end" class="lb">' . (int) round($nowPct) . '% today</text>';
    $s[] = '<line x1="' . $c($x($today)) . '" x2="' . $c($x($today)) . '" y1="' . $T . '" y2="' . $c($y(0)) . '" stroke="#78716c" stroke-width="1"/>';
    if ($exam !== null && $exam >= $x0 && $exam <= $x1) {
        $s[] = '<line x1="' . $c($x($exam)) . '" x2="' . $c($x($exam)) . '" y1="' . $T . '" y2="' . $c($y(0)) . '" stroke="#dc2626" stroke-width="1"/>';
        $s[] = '<text x="' . $c($x($exam) - 5) . '" y="' . $c($y(4)) . '" text-anchor="end" class="tk" fill="#b91c1c">exam · ' . h(pg_short($exam)) . '</text>';
    }
    if ($withCone && $m['cone'] !== null) {
        $last = end($m['cone']['bands']);
        $ex   = $x($last['end']);
        $s[] = '<text x="' . $c($ex + 6) . '" y="' . $c($y((float) $last['p50_pct']) + 4) . '" class="lb">' . (int) round($last['p50_pct']) . '% likely</text>';
        if ((int) round($last['p15_pct']) !== (int) round($last['p50_pct'])) {
            $s[] = '<text x="' . $c($ex + 6) . '" y="' . $c($y((float) $last['p15_pct']) + 4) . '" class="tk">' . (int) round($last['p15_pct']) . '%</text>';
        }
        if ((int) round($last['p85_pct']) !== (int) round($last['p50_pct'])) {
            $s[] = '<text x="' . $c($ex + 6) . '" y="' . $c($y((float) $last['p85_pct']) + 4) . '" class="tk">' . (int) round($last['p85_pct']) . '%</text>';
        }
    }
    $s[] = '</svg>';
    return implode("\n", $s);
}

/** The cumulative flow: topics by status at each week end, done at the bottom. */
function pg_cfd_svg(array $m): string
{
    $W = 720;
    $H = 200;
    $L = 34;
    $R = 112;
    $T = 12;
    $B = 26;
    $rows = $m['cfd'];
    $n    = $m['unit']['topics'];
    if (count($rows) < 2 || $n === 0) {
        return '';
    }
    $c   = 'pg_coord';
    $x0  = $rows[0]['date'];
    $x1  = end($rows)['date'];
    $span = max(1, tt_days_between($x0, $x1));
    $x = static fn(string $d): float => $L + tt_days_between($x0, $d) / $span * ($W - $L - $R);
    $y = static fn(float $k): float => $T + (1 - $k / $n) * ($H - $T - $B);
    $layers = [['examready', '#0ea5e9'], ['secure', '#10b981'], ['developing', '#fbbf24'], ['gap', '#ef4444'], ['notstarted', '#d6d3d1']];
    $s = ['<svg class="pg-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="pg-cfd-t">'
        . '<title id="pg-cfd-t">Topics by status, week by week</title>'];
    foreach ([0, .25, .5, .75, 1] as $f) {
        $g = (int) round($n * $f);
        $s[] = '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $c($y($g)) . '" y2="' . $c($y($g)) . '" stroke="#e7e5e4"/>';
        $s[] = '<text x="' . ($L - 6) . '" y="' . $c($y($g) + 4) . '" text-anchor="end" class="tk">' . $g . '</text>';
    }
    $base = array_fill(0, count($rows), 0);
    foreach ($layers as [$status, $colour]) {
        $top = [];
        foreach ($rows as $i => $r) {
            $top[$i] = $base[$i] + ($r[$status] ?? 0);
        }
        $pts = [];
        foreach ($rows as $i => $r) {
            $pts[] = $c($x($r['date'])) . ',' . $c($y((float) $top[$i]));
        }
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $pts[] = $c($x($rows[$i]['date'])) . ',' . $c($y((float) $base[$i]));
        }
        $s[] = '<polygon points="' . implode(' ', $pts) . '" fill="' . $colour . '" fill-opacity=".85" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/>';
        $lastI = count($rows) - 1;
        $count = $rows[$lastI][$status] ?? 0;
        if ($count > 0) {
            $mid = ($top[$lastI] + $base[$lastI]) / 2;
            $s[] = '<text x="' . $c($W - $R + 8) . '" y="' . $c($y((float) $mid) + 4) . '" class="tk">' . $count . ' ' . h(strtolower(STATUS_LABEL[$status] ?? $status)) . '</text>';
        }
        $base = $top;
    }
    $step = max(1, (int) ceil(count($rows) / 8));
    foreach ($rows as $i => $r) {
        if ($i % $step === 0 || $i === count($rows) - 1) {
            $s[] = '<text x="' . $c($x($r['date'])) . '" y="' . ($H - 8) . '" text-anchor="middle" class="tk">' . h(pg_short($r['date'])) . '</text>';
        }
    }
    $s[] = '</svg>';
    return implode("\n", $s);
}

/** Steps gained each week as columns, against the pace needed and the natural limits. */
function pg_weekly_svg(array $m, string $accent): string
{
    $W = 720;
    $H = 150;
    $L = 34;
    $R = 120;
    $T = 18;
    $B = 26;
    $weeks = array_slice($m['weeks'], -12);
    if (!$weeks) {
        return '';
    }
    $c    = 'pg_coord';
    $top  = 7;
    foreach ($weeks as $w) {
        $top = max($top, abs((int) $w['net']));
    }
    if ($m['limits'] !== null) {
        $top = max($top, (int) ceil($m['limits']['upper']));
    }
    $top  = (int) (ceil($top / 7) * 7);
    $y    = static fn(float $v): float => $T + (1 - $v / $top) * ($H - $T - $B);
    $slot = ($W - $L - $R) / count($weeks);
    $s = ['<svg class="pg-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="pg-wk-t">'
        . '<title id="pg-wk-t">Steps gained each week against the pace needed</title>'];
    foreach ([0, $top / 2, $top] as $g) {
        $s[] = '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $c($y($g)) . '" y2="' . $c($y($g)) . '" stroke="#e7e5e4"/>';
        $s[] = '<text x="' . ($L - 6) . '" y="' . $c($y($g) + 4) . '" text-anchor="end" class="tk">' . (int) $g . '</text>';
    }
    foreach ($weeks as $i => $w) {
        $cx = $L + $slot * ($i + .5);
        $v  = (int) $w['net'];
        if ($v > 0) {
            $s[] = '<rect x="' . $c($cx - 11) . '" y="' . $c($y($v)) . '" width="22" height="' . $c($y(0) - $y($v)) . '" rx="3" fill="' . h($accent) . '"/>';
        } elseif ($v < 0) {
            $s[] = '<rect x="' . $c($cx - 11) . '" y="' . $c($y(0)) . '" width="22" height="' . $c(min(8.0, abs($v) * 4)) . '" rx="3" fill="#dc2626"/>';
        }
        if ($w['blocks'] === 0) {
            $s[] = '<text x="' . $c($cx) . '" y="' . $c($y(0) - 5) . '" text-anchor="middle" class="tk">no blocks</text>';
        } else {
            $s[] = '<text x="' . $c($cx) . '" y="' . $c($y(max($v, 0)) - 5) . '" text-anchor="middle" class="lb">' . ($v > 0 ? '+' : '') . $v . '</text>';
        }
        $s[] = '<text x="' . $c($cx) . '" y="' . ($H - 8) . '" text-anchor="middle" class="tk">' . h(substr($w['week'], 5)) . '</text>';
    }
    // Reference lines, with their labels nudged apart when two sit close.
    $refs = [];
    $need = $m['pace']['needed_per_week'];
    if ($need !== null && $need <= $top) {
        $refs[] = ['v' => (float) $need, 'label' => 'needed · ' . $need . ' a week', 'colour' => '#78716c', 'dash' => '1 4', 'width' => '1.5'];
    }
    if ($m['limits'] !== null) {
        foreach (['upper' => 'upper limit', 'lower' => 'lower limit'] as $k => $label) {
            $v = (float) $m['limits'][$k];
            if ($v <= $top) {
                $refs[] = ['v' => $v, 'label' => $label . ' ' . $m['limits'][$k], 'colour' => '#b45309', 'dash' => '4 3', 'width' => '1'];
            }
        }
    }
    usort($refs, static fn($a, $b) => $b['v'] <=> $a['v']);
    $lastLabelY = -100.0;
    foreach ($refs as $r) {
        $ly = $y($r['v']);
        $s[] = '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $c($ly) . '" y2="' . $c($ly) . '" stroke="' . $r['colour'] . '" stroke-width="' . $r['width'] . '" stroke-dasharray="' . $r['dash'] . '" stroke-linecap="round"/>';
        $labelY = max($ly + 4, $lastLabelY + 12);
        $s[] = '<text x="' . ($W - $R + 8) . '" y="' . $c($labelY) . '" class="tk" fill="' . $r['colour'] . '">' . h($r['label']) . '</text>';
        $lastLabelY = $labelY;
    }
    $s[] = '</svg>';
    return implode("\n", $s);
}

/** The one-line summary the subject page, the index and the week page carry. */
function pg_summary_line(array $m, bool $isParent): string
{
    if (!$m['tracked']) {
        return 'nothing logged yet';
    }
    $parts = [];
    if ($isParent && $m['cone'] !== null && $m['cone']['exam_day'] !== null) {
        $parts[] = (int) round($m['cone']['exam_day']['p50_pct']) . '% likely on exam day';
    } elseif ($isParent && $m['cone'] === null && $m['pace']['weeks_of_pace'] < PROGRESS_MIN_WEEKS) {
        $parts[] = 'too early to forecast · ' . $m['pace']['weeks_of_pace'] . ' of ' . PROGRESS_MIN_WEEKS . ' weeks of pace';
    }
    if ($m['aimline'] !== null) {
        if ($m['behind_run'] > 0) {
            $parts[] = $m['behind_run'] . ' week' . ($m['behind_run'] === 1 ? '' : 's') . ' under the aimline';
        } else {
            $parts[] = 'on the aimline';
        }
    }
    if ($m['stalled_run'] >= 2) {
        $parts[] = 'stalled ' . $m['stalled_run'] . ' weeks';
    }
    return implode(' · ', $parts);
}

function render_subject_progress(Store $store, array $subject, bool $isParent = false): string
{
    $slug   = $subject['slug'];
    $m      = progress_compute($store->progressInputs($slug), ['cone' => $isParent]);
    $in     = $store->progressInputs($slug);
    $m['days_off'] = $in['days_off'];
    $accent = tt_accent([$slug]);
    $u      = $m['unit'];
    $pace   = $m['pace'];
    $cone   = $m['cone'];

    $days = $subject['exam_date'] ? tt_days_between($m['today'], $subject['exam_date']) : null;
    $sub  = 'Replayed from ' . count($m['changes']) . ' status change' . (count($m['changes']) === 1 ? '' : 's')
        . ' in ' . count($in['sessions']) . ' session' . (count($in['sessions']) === 1 ? '' : 's')
        . ($m['first_day'] ? ' since ' . h(pg_short($m['first_day'])) : '')
        . ($days !== null ? ' · ' . $days . ' days to the exam' : ' · no exam date set')
        . ' · built from <strong>' . $pace['weeks_of_pace'] . ' week' . ($pace['weeks_of_pace'] === 1 ? '' : 's') . '</strong> of pace'
        . ($pace['weeks_of_pace'] < PROGRESS_SETTLED_WEEKS ? ', provisional until ' . PROGRESS_SETTLED_WEEKS : '');

    $body = detail_head($subject, 'Progress and forecast', $sub);

    if (!$m['tracked']) {
        $body .= '<p>Nothing has been logged for this subject yet. The line starts with the first session or status change.</p>';
        return dash_shell($subject['name'] . ' progress', $body, '<style>' . DASH_PROGRESS_CSS . '</style>');
    }

    // ---- the cards -------------------------------------------------------
    $aimToday = $m['weeks'] ? end($m['weeks'])['aim_pct'] : null;
    $cards = '<div class="card"><p class="label">Today</p><p class="big mono">' . pg_pct($u['pct'])
        . '<small> · ' . $u['points'] . ' of ' . $u['max'] . ' steps</small></p>'
        . '<div class="pg-track"><i style="width:' . pg_coord((float) $u['pct']) . '%"></i>'
        . ($aimToday !== null ? '<b style="left:' . pg_coord((float) $aimToday) . '%" title="aimline today: ' . pg_pct($aimToday) . '"></b>' : '')
        . '</div><p class="sub">' . pg_pct($u['three_weeks_ago_pct']) . ' three weeks ago · ' . pg_pct($u['start_pct']) . ' on '
        . h(pg_short($m['first_day'])) . ($aimToday !== null ? ' · aimline says ' . pg_pct($aimToday) . ' today' : '') . '</p></div>';

    $ran = implode(' · ', array_map(static fn($r) => ($r['net'] > 0 ? '+' : '') . $r['net'], $m['rates']));
    $cards .= '<div class="card"><p class="label">Pace, last ' . $pace['weeks_of_pace'] . ' week' . ($pace['weeks_of_pace'] === 1 ? '' : 's') . '</p>'
        . '<p class="big mono">' . h((string) $pace['per_week']) . '<small> steps a week</small></p><p class="sub">'
        . ($pace['needed_per_week'] !== null
            ? 'needs <strong>' . h((string) $pace['needed_per_week']) . '</strong> to be ' . ($m['goal']['pct'] >= 100 ? 'exam-ready everywhere' : $m['goal']['pct'] . '%')
              . ' by ' . h(pg_short($m['goal']['date']))
            : ($pace['remaining_steps'] === 0 ? 'the goal is met' : 'no blocks timetabled before the goal'))
        . ($ran !== '' ? ' · weeks ran ' . h($ran) : '') . '</p></div>';

    if ($isParent) {
        if ($cone !== null && $cone['exam_day'] !== null) {
            $e = $cone['exam_day'];
            $cards .= '<div class="card"><p class="label">On exam day, at this pace</p><p class="big mono">' . pg_pct($e['p50_pct'])
                . '<small> likely</small></p><p class="sub">probably between <strong>' . pg_pct($e['p15_pct']) . '</strong> and <strong>'
                . pg_pct($e['p85_pct']) . '</strong>'
                . ($pace['last_two_lands'] !== null ? ' · the last two weeks\' pace alone lands at ' . pg_pct($pace['last_two_lands']) : '') . '</p></div>';
            $sec = $cone['secure'];
            $cards .= '<div class="card"><p class="label">Everything secure</p><p class="big mono">' . (int) round($sec['p_by_horizon'] * 100)
                . '%<small> chance by exam day</small></p><p class="sub">typically reached <strong>' . h(pg_date($sec['p50_date'])) . '</strong>'
                . ' · ' . ($m['goal']['pct'] >= 100 ? 'everything exam-ready' : 'the goal') . ' by ' . h(pg_short($m['goal']['date'] ?? $subject['exam_date']))
                . ': ' . (int) round($cone['goal']['p_by_horizon'] * 100) . '% chance</p></div>';
        } else {
            $cards .= '<div class="card"><p class="label">The cone</p><p class="big mono">—</p><p class="sub">'
                . ($pace['weeks_of_pace'] < PROGRESS_MIN_WEEKS
                    ? 'needs ' . PROGRESS_MIN_WEEKS . ' weeks of pace and has ' . $pace['weeks_of_pace']
                    : 'no exam or goal date ahead to run to') . '</p></div>';
        }
    }
    $body .= '<div class="pg-stats">' . $cards . '</div>';

    // ---- the flag ---------------------------------------------------------
    $shown = array_values(array_filter($m['signals'], static function ($sig) use ($isParent) {
        if (in_array($sig['code'], ['off_target', 'provisional', 'too_early', 'wip_high'], true) && !$isParent) {
            return false;
        }
        return $sig['level'] !== 'info' || $sig['code'] === 'on_track';
    }));
    if ($shown) {
        $level = $shown[0]['level'] === 'info' ? 'ok' : $shown[0]['level'];
        $body .= '<div class="pg-flag ' . $level . '">';
        foreach (array_slice($shown, 0, 3) as $sig) {
            $body .= '<p><b>' . h(ucfirst(str_replace('_', ' ', $sig['code']))) . '.</b> ' . h($sig['text']) . '</p>';
        }
        if ($m['aimline'] !== null && $m['behind_run'] > 0 && $m['behind_run'] < 4) {
            $body .= '<span class="rule">The rule that says change the teaching fires at four consecutive weeks below the aimline. '
                . 'The week page names what was cut short.</span>';
        }
        $body .= '</div>';
    }
    if (!$isParent) {
        $body .= '<p class="pg-signin"><a href="/login?next=' . h(rawurlencode("/s/$slug/progress")) . '">Sign in</a> to see the forecast cone and the topics in flight.</p>';
    }

    // ---- the burn-up ------------------------------------------------------
    $body .= pg_title('Where she is, where she\'s headed')
        . '<div class="chartbox" id="pg-bubox" style="position:relative">' . pg_burnup_svg($m, $accent, $isParent) . '<div class="pg-tip" id="pg-butip"></div></div>'
        . '<div class="pg-legend"><span><i></i>coverage, day by day</span>'
        . ($m['aimline'] !== null ? '<span><i class="dot"></i>aimline: ' . pg_pct($m['aimline']['from_pct']) . ' on ' . h(pg_short($m['aimline']['from_date']))
            . ' to ' . pg_pct($m['aimline']['to_pct']) . ' on ' . h(pg_short($m['aimline']['to_date'])) . '</span>' : '')
        . ($isParent && $cone !== null ? '<span><i class="dash"></i>most likely path</span><span><i class="band"></i>likely (15–85%)</span><span><i class="band2"></i>possible (5–95%)</span>' : '')
        . '<span><i class="secl"></i>everything secure</span>'
        . ($in['days_off'] ? '<span><i class="sw" style="background:#e7e5e4"></i>days off</span>' : '') . '</div>';
    $body .= '<p class="pg-cap">' . ($in['days_off']
            ? 'Booked days off are shaded: no steps are expected on a day with no ' . h($subject['name']) . ' block, and the cone routes around them.'
            : 'No days off are booked yet. Book holidays under days off and the cone routes around them: no steps are expected on a day with no block.')
        . ' The cone stops at 100% because the syllabus does; it does not know that later topics may be harder than the ones already taught.</p>';
    $rows = [];
    foreach ($m['weeks'] as $w) {
        $rows[] = [$w['end'], pg_pct($w['end_pct']), pg_pct($w['aim_pct']), '—', '—', '—'];
    }
    if ($isParent && $cone !== null) {
        foreach ($cone['bands'] as $b) {
            $rows[] = [$b['end'], '—', pg_pct(mcp_progress_aim_at($m, $b['end'])), pg_pct($b['p15_pct']), pg_pct($b['p50_pct']), pg_pct($b['p85_pct'])];
        }
    }
    $body .= pg_data_table(['Week ending', 'Actual', 'Aimline', 'Likely low', 'Most likely', 'Likely high'], $rows);

    // ---- the flow ---------------------------------------------------------
    $cfd = pg_cfd_svg($m);
    if ($cfd !== '') {
        $counts = $u['counts'];
        $body .= pg_title('How the colours have moved') . '<div class="chartbox">' . $cfd . '</div><div class="pg-legend">';
        foreach ([['examready', '#0ea5e9'], ['secure', '#10b981'], ['developing', '#fbbf24'], ['gap', '#ef4444'], ['notstarted', '#d6d3d1']] as [$k, $col]) {
            $body .= '<span><i class="sw" style="background:' . $col . '"></i>' . h(strtolower(STATUS_LABEL[$k])) . '</span>';
        }
        $body .= '</div><p class="pg-cap">Green grows from the bottom. The yellow band is what is in flight: ' . ($counts['developing'] ?? 0)
            . ' developing now. A flat picture is a stalled week; green shrinking is a regression. '
            . (($counts['notstarted'] ?? 0) + ($counts['gap'] ?? 0)) . ' topics have not been opened.</p>';
        $rows = [];
        foreach ($m['cfd'] as $r) {
            $rows[] = array_merge([$r['date']], array_map(static fn($k) => $r[$k] ?? 0, STATUS_ORDER));
        }
        $body .= pg_data_table(array_merge(['Week ending'], array_map(static fn($k) => STATUS_LABEL[$k], STATUS_ORDER)), $rows);
    }

    // ---- week by week -----------------------------------------------------
    $body .= pg_title('Week by week') . '<div class="chartbox">' . pg_weekly_svg($m, $accent) . '</div>'
        . '<div class="tablewrap" style="overflow-x:auto;margin-top:.6rem"><table class="pg-table"><thead><tr><th>Week</th><th>Blocks</th><th>Sessions</th>'
        . '<th>Touches</th><th>Opened</th><th>Steps</th><th>Aimline</th><th>Reading</th></tr></thead><tbody>';
    foreach (array_slice($m['weeks'], -12) as $w) {
        $moved = [];
        foreach (array_slice($w['moves'], 0, 4) as $c) {
            $moved[] = $c['ref'] . ' ' . ($c['delta'] > 0 ? '↑' : '↓');
        }
        $body .= '<tr><td class="w">' . h($w['week']) . '<small>' . h(pg_short($w['monday'])) . '</small></td><td class="n">' . $w['blocks'] . '</td>'
            . '<td class="n">' . $w['sessions'] . '</td><td class="n">' . $w['touches'] . '</td><td class="n">' . $w['opened'] . '</td>'
            . '<td class="n">' . ($w['net'] > 0 ? '+' : '') . $w['net'] . '</td>'
            . '<td><span class="pg-pill ' . h($w['flag']) . '">' . h(progress_flag_word($w)) . '</span>' . ($w['complete'] ? '' : ' <small>in progress</small>') . '</td>'
            . '<td>' . ($moved ? h(implode(', ', $moved)) . (count($w['moves']) > 4 ? ' …' : '') : ($w['touches'] > 0 ? 'evidence only' : '')) . '</td></tr>';
    }
    $body .= '</tbody></table></div><p class="pg-cap">Touches are status changes where nothing moved: evidence logged against a topic that '
        . 'stayed where it was. Opened counts topics taught for the first time. '
        . ($m['limits'] !== null
            ? 'Natural process limits from ' . $m['limits']['weeks'] . ' weeks: a week above ' . h((string) $m['limits']['upper']) . ' or below ' . h((string) $m['limits']['lower']) . ' is a signal, anything between is routine variation.'
            : 'Natural process limits on the weekly steps appear once ' . PROGRESS_LIMITS_WEEKS . ' complete weeks with blocks are in.') . '</p>';

    // ---- in flight (parent) -----------------------------------------------
    if ($isParent && $m['in_flight']) {
        $body .= pg_title('In flight') . '<div class="tablewrap" style="overflow-x:auto"><table class="pg-table"><thead><tr><th>Topic</th><th>Status</th>'
            . '<th>In this status</th><th>Touched, unmoved</th><th>Note</th></tr></thead><tbody>';
        foreach ($m['in_flight'] as $t) {
            $age = $t['age_days'] === null ? '—'
                : ($t['age_days'] >= 14 ? intdiv((int) $t['age_days'], 7) . ' weeks' : ($t['age_days'] === 0 ? 'new, ' . pg_short($m['today']) : $t['age_days'] . ' days'));
            $old = ($t['age_days'] ?? 0) >= 42;
            $body .= '<tr><td><a href="/s/' . h($slug) . '/t/' . h(rawurlencode($t['ref'])) . '"><b class="mono">' . h($t['ref']) . '</b></a> ' . h($t['name']) . '</td>'
                . '<td><span class="dot" style="background:' . (STATUS_COLOUR[$t['status']] ?? '#d6d3d1') . '"></span>' . h(strtolower(STATUS_LABEL[$t['status']] ?? $t['status'])) . '</td>'
                . '<td class="pg-age' . ($old ? ' old' : '') . '">' . h($age) . ($t['seeded'] ? ' <small>since the seed</small>' : '') . '</td>'
                . '<td class="n">' . $t['touches'] . '</td><td>' . h((string) ($t['watch'] ?? '')) . '</td></tr>';
        }
        $body .= '</tbody></table></div>';
        $ct = $m['cycle_time'];
        if ($ct['fresh_count'] > 0) {
            $body .= '<p class="pg-cap">A freshly opened topic takes <strong>' . h((string) $ct['fresh_median_days']) . ' days</strong> from first taught to secure, typically ('
                . $ct['fresh_count'] . ' topic' . ($ct['fresh_count'] === 1 ? '' : 's') . ').'
                . ($ct['seeded_count'] > 0 ? ' Topics developing since the seed that made it took ' . h((string) $ct['seeded_median_days']) . ' days from the start of the record (' . $ct['seeded_count'] . ').' : '')
                . ' ' . ($u['counts']['developing'] ?? 0) . ' in flight now.</p>';
        }
    }

    // ---- method -----------------------------------------------------------
    $body .= '<details class="pg-method"><summary>How this is worked out</summary><ol>'
        . '<li><b>The line</b> is coverage replayed from every status change, one point per day, in steps: one topic moving up one level.</li>'
        . '<li><b>The aimline</b> runs from coverage on the first tracked day to the goal (every step on exam day unless the parent set another). '
        . 'Two weeks under it is a warning; four weeks running is the rule to change the teaching.</li>'
        . '<li><b>The cone</b> re-runs the future ' . number_format(PROGRESS_RUNS) . ' times. Each run first re-draws which of the last ' . PROGRESS_WINDOW_WEEKS
        . ' weeks count, then gives every future week the pace of one of those, scaled to the blocks the timetable has that week. Days off carry no blocks. '
        . 'The shaded band is where 70% of runs land; the paler band, 90%.</li>'
        . '<li><b>Stalled</b> is a week with blocks and sessions but no steps. <b>Behind</b> is a week that ended under the aimline. '
        . '<b>Touched, unmoved</b> counts evidence logged against a topic that stayed put.</li>'
        . '<li>The cone does not know that later topics may be harder, that exam-ready needs revision-phase work, or about holidays nobody has booked. It narrows as weeks are added.</li>'
        . '</ol></details>';
    $body .= '<footer>Generated live from the tracker database. The cone is seeded by the date, so the page reads the same all day.</footer>';

    // Hover on the burn-up: the week under the cursor.
    $hover = [];
    foreach ($m['weeks'] as $w) {
        $hover[] = ['d' => $w['end'], 'a' => (int) round($w['end_pct']), 'm' => $w['aim_pct'] === null ? null : (int) round($w['aim_pct']), 'p' => null];
    }
    if ($isParent && $cone !== null) {
        foreach ($cone['bands'] as $b) {
            $hover[] = ['d' => $b['end'], 'a' => null, 'm' => ($v = mcp_progress_aim_at($m, $b['end'])) === null ? null : (int) round($v),
                        'p' => [(int) round($b['p15_pct']), (int) round($b['p50_pct']), (int) round($b['p85_pct'])]];
        }
    }
    $x0 = $m['daily'][0]['date'];
    $horizon = $m['goal']['date'] ?? null;
    if (($subject['exam_date'] ?? null) !== null && $subject['exam_date'] > $m['today'] && ($horizon === null || $subject['exam_date'] > $horizon)) {
        $horizon = $subject['exam_date'];
    }
    $x1 = $horizon !== null && $horizon > $m['today'] ? tt_add_days($horizon, 2) : tt_add_days($m['today'], 7);
    $script = '<script>(function(){var rows=' . json_encode($hover) . ';var box=document.getElementById("pg-bubox");if(!box)return;'
        . 'var svg=box.querySelector("svg"),tip=document.getElementById("pg-butip");if(!svg||!tip)return;'
        . 'var X0=new Date("' . $x0 . 'T00:00:00Z").getTime(),X1=new Date("' . $x1 . 'T00:00:00Z").getTime(),L=40,R=64,W=720;'
        . 'function show(ev){var r=svg.getBoundingClientRect();var fx=(ev.clientX-r.left)/r.width*W;if(fx<L||fx>W-R){tip.style.display="none";return;}'
        . 'var day=X0+(fx-L)/(W-L-R)*(X1-X0);var best=null,bd=1e18;rows.forEach(function(row){var d=Math.abs(new Date(row.d+"T00:00:00Z").getTime()-day);if(d<bd){bd=d;best=row;}});'
        . 'if(!best){tip.style.display="none";return;}var t="<b>w/e "+best.d+"</b><br>";if(best.a!==null)t+="actual "+best.a+"%<br>";if(best.m!==null)t+="aimline "+best.m+"%";'
        . 'if(best.p)t+="<br>likely "+best.p[0]+"–"+best.p[2]+"% · median "+best.p[1]+"%";tip.innerHTML=t;tip.style.display="block";'
        . 'var bx=box.getBoundingClientRect();var left=ev.clientX-bx.left+12;if(left>bx.width-200)left=ev.clientX-bx.left-200;tip.style.left=left+"px";tip.style.top=(ev.clientY-bx.top-10)+"px";}'
        . 'svg.addEventListener("mousemove",show);svg.addEventListener("mouseleave",function(){tip.style.display="none";});})();</script>';

    return dash_shell($subject['name'] . ' progress', $body . $script, '<style>' . DASH_PROGRESS_CSS . '</style>');
}
