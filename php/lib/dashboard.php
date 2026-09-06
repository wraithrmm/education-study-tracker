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
/* ---- the weekly timetable ------------------------------------------------
   One status vocabulary, three layouts. Shape differs per status as well as
   colour, so the board is readable without colour vision; every mark carries
   an aria-label saying the block and its status in words. */
.tt{margin:1.5rem 0 0}
.tt-head{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:baseline;justify-content:space-between}
.tt-head h2{margin:0;font-size:1.15rem}
.tt-stamp{font-size:.78rem;color:var(--muted);margin:0}
.tt .mk{flex:none;display:block}
.tt .markwrap{display:flex;flex-direction:column;align-items:center;gap:0;flex:none;line-height:1}
.tt .shortcap{font-size:.56rem;font-weight:700;letter-spacing:.04em;color:#b45309;
  font-family:ui-monospace,"Cascadia Mono",Menlo,monospace}
.mk.pulse{animation:ttpulse 2.2s ease-in-out infinite;transform-origin:center}
@keyframes ttpulse{0%,100%{opacity:1}50%{opacity:.3}}
@media (prefers-reduced-motion:reduce){.mk.pulse{animation:none}}
.tt-ribbon{display:block;font-size:.7rem;line-height:1.35;padding:.25rem .45rem;border-radius:5px;
  background:#f5f5f4;border:1px solid var(--line);color:#57534e;margin:0 0 .35rem}
.tt-ribbon.req{background:transparent;border-style:dashed}
.tt-ribbon b{font-size:.62rem;letter-spacing:.08em;text-transform:uppercase;margin-right:.3rem}
.tt-quiet{background:var(--card);border:1px dashed var(--line);border-radius:10px;
  padding:.8rem 1rem;font-size:.9rem;color:#57534e;margin:.5rem 0 0}
.tt-total{margin:.9rem 0 0;padding-top:.6rem;border-top:1px solid var(--line);
  font-size:.78rem;color:#57534e}
.tt-total .sep{color:#a8a29e;padding:0 .25rem}
.tt-total b{color:var(--ink)}
.tt-legend{display:flex;flex-wrap:wrap;gap:.5rem .9rem;margin-top:.6rem;font-size:.7rem;color:var(--muted)}
.tt-legend span{display:flex;align-items:center;gap:.25rem}

/* Design A — the week strip. Five columns on a laptop; on a phone one
   snap-scrolling strip that opens on today. */
.wk{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.55rem;margin-top:.5rem;
  align-items:start}
.wkcol{background:var(--card);border:1px solid var(--line);border-radius:9px;
  padding:.45rem .45rem .5rem;position:relative}
.wkcol.is-today{border-color:#292524;box-shadow:0 1px 0 #292524,3px 4px 10px -6px rgba(28,25,23,.55)}
.wkcol.is-past{background:#fdfdfc}
.daytab{position:absolute;top:-.62rem;left:.5rem;font-size:.58rem;font-weight:700;letter-spacing:.14em;
  font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;color:#b91c1c;background:#fcfcf9;
  border:1.5px solid #dc2626;border-radius:4px;padding:0 .3rem;transform:rotate(-1.6deg)}
.dhead{display:flex;align-items:baseline;justify-content:space-between;gap:.3rem;
  padding:.15rem .15rem .35rem;border-bottom:1px solid #ededea;margin-bottom:.15rem}
.dhead .dn{font-weight:700;font-size:.82rem}
.dhead .dd{font-size:.66rem;color:var(--muted)}
.blk{display:flex;align-items:center;gap:.4rem;padding:.28rem .3rem .28rem .45rem;
  border-left:3px solid var(--acc,#d6d3d1);border-radius:0 4px 4px 0;margin:.14rem 0;
  text-decoration:none;color:inherit}
.blk .bt{font-size:.62rem;color:var(--muted);flex:none;width:2.15rem;
  font-variant-numeric:tabular-nums}
.blk .bl{flex:1;min-width:0;font-size:.72rem;line-height:1.22;overflow:hidden;
  display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}
.blk.s-missed{background:rgba(220,38,38,.055)}
.blk.s-now{background:rgba(124,58,237,.07);box-shadow:inset 0 0 0 1px rgba(124,58,237,.28);
  border-radius:4px}
.blk.s-done .bl,.blk.s-excused .bl{color:#57534e}
.blk.s-excused .bl{text-decoration:line-through;text-decoration-color:#a8a29e}
.blk.s-upcoming,.blk.s-pending{opacity:.66}
a.blk:hover{background:#faf9f5}
.brk{height:1px;background:#ededea;margin:.3rem .3rem .3rem 0}
.tt-extras{margin:.3rem 0 0;padding:.3rem .2rem 0;border-top:1px dotted #e7e5e4;
  font-size:.66rem;color:#57534e}
.tt-extras b{display:block;font-size:.6rem;letter-spacing:.08em;text-transform:uppercase;
  color:var(--muted)}
.tt-extras div{display:flex;gap:.3rem;align-items:center;margin-top:.15rem}
.tt-extras a{color:inherit}
/* Phone first in behaviour, if not in source order: below 40rem the five
   columns become one strip, and the page scrolls it to today. */
@media (max-width:40rem){
  .wk{display:flex;align-items:flex-start;overflow-x:auto;gap:.5rem;padding:.7rem 0 .5rem;
    scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
  .wkcol{flex:0 0 78%;scroll-snap-align:center}
}
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

// ---- the weekly timetable ------------------------------------------------
//
// One judge_week() call, one status vocabulary, three layouts. Only the markup
// differs between designs, so a status can never mean one thing on design A
// and something else on design B.

/**
 * Accent hue per subject. The five tracked subjects get chosen hues that stay
 * clear of the semantic status colours — emerald, amber and red mean done,
 * short and missed, and a subject that borrowed one of them would read as a
 * status. Anything added later gets a stable hue off its slug.
 */
const SUBJECT_ACCENT = [
    'maths'              => '#7c3aed',
    'english-literature' => '#0f766e',
    'english-language'   => '#1d4ed8',
    'computer-science'   => '#4d7c0f',
    'spanish'            => '#a21caf',
];

/**
 * Slug to the name a person would say. "GCSE " is dropped because the board
 * has no room for it and every subject on it is a GCSE.
 *
 * @return array<string,string>
 */
function tt_subject_names(Store $store): array
{
    $out = [];
    foreach ($store->listSubjects() as $s) {
        $out[$s['slug']] = preg_replace('/^GCSE\s+/i', '', (string) $s['name']);
    }
    return $out;
}

function tt_accent(array $subjects): string
{
    if (count($subjects) !== 1) {
        return '#a8a29e';
    }
    $slug = $subjects[0];
    if (isset(SUBJECT_ACCENT[$slug])) {
        return SUBJECT_ACCENT[$slug];
    }
    return 'hsl(' . (crc32($slug) % 360) . ' 45% 40%)';
}

/** Which design this request renders. ?design= wins, for one request only. */
function tt_design(Store $store, array $query): string
{
    $d = strtolower((string) ($query['design'] ?? ''));
    if (in_array($d, ['a', 'b', 'c'], true)) {
        return $d;
    }
    $stored = strtolower((string) ($store->meta('timetable_design') ?? 'a'));
    return in_array($stored, ['a', 'b', 'c'], true) ? $stored : 'a';
}

/**
 * A status mark. Shape carries the status as well as colour, and the
 * aria-label says it in words, so neither colour vision nor the legend is
 * needed to read the board.
 */
function tt_mark(array $b): string
{
    $name = $b['label'] . ' ' . $b['start'];
    $svg  = static function (string $inner, string $label, string $cls = ''): string {
        return '<svg class="mk' . ($cls ? ' ' . $cls : '') . '" width="16" height="16" '
            . 'viewBox="0 0 18 18" role="img" aria-label="' . h($label) . '">' . $inner . '</svg>';
    };
    $wrap = static fn(string $inner): string => '<span class="markwrap">' . $inner . '</span>';

    switch ($b['status']) {
        case 'done':
            if (!empty($b['short'])) {
                return $wrap($svg(
                    '<path d="M3.2 9.6 6.8 13.4 14.8 4.3" fill="none" stroke="#d97706" '
                    . 'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
                    "$name — done, but short: {$b['minutes']} minutes of {$b['length']}"
                ) . '<span class="shortcap">short</span>');
            }
            return $wrap($svg(
                '<path d="M3.2 9.6 6.8 13.4 14.8 4.3" fill="none" stroke="#059669" '
                . 'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
                "$name — done"
            ));
        case 'now':
            return $wrap($svg(
                '<circle cx="9" cy="9" r="6.3" fill="none" stroke="' . h($b['accent'])
                . '" stroke-width="2.2"/><circle cx="9" cy="9" r="2" fill="' . h($b['accent']) . '"/>',
                "$name — happening now, ends {$b['end']}", 'pulse'
            ));
        case 'pending':
            return $wrap($svg(
                '<circle cx="9" cy="9" r="6.3" fill="none" stroke="#a8a29e" stroke-width="1.5"/>',
                "$name — later today, nothing logged yet"
            ));
        case 'missed':
            return $wrap($svg(
                '<g transform="rotate(-2 9 9)">'
                . '<path d="M4.1 4.5 13.9 13.7" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round"/>'
                . '<path d="M13.7 4.3 4.3 13.9" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round"/>'
                . '</g>',
                "$name — missed"
            ));
        case 'excused':
            return $wrap($svg(
                '<path d="M3.2 9.2 14.8 8.8" stroke="#a8a29e" stroke-width="2.2" stroke-linecap="round"/>',
                "$name — excused: " . ($b['reason'] ?? 'no reason given')
            ));
        case 'day_off':
            return $wrap($svg(
                '<path d="M4.4 9 13.6 9" stroke="#d6d3d1" stroke-width="2" stroke-linecap="round"/>',
                "$name — day off"
            ));
        case 'upcoming':
            return $wrap($svg(
                '<circle cx="9" cy="9" r="2.2" fill="#d6d3d1"/>', "$name — still to come"
            ));
        default:
            return '';
    }
}

function tt_extra_mark(): string
{
    return '<svg class="mk" width="16" height="16" viewBox="0 0 18 18" role="img" '
        . 'aria-label="extra work, outside the timetable">'
        . '<circle cx="9" cy="9" r="6.3" fill="none" stroke="#059669" stroke-width="1.4"/>'
        . '<path d="M9 5.6 9 12.4M5.6 9 12.4 9" stroke="#059669" stroke-width="1.6" '
        . 'stroke-linecap="round"/></svg>';
}

/** A day off, labelled. Approved is solid; merely requested is outlined. */
function tt_ribbon(?array $off): string
{
    if (!$off || $off['status'] === 'declined') {
        return '';
    }
    $approved = $off['status'] === 'approved';
    $text     = $approved
        ? $off['reason']
        : $off['reason'] . ' — awaiting Dad';
    return '<p class="tt-ribbon' . ($approved ? '' : ' req') . '" title="'
        . h(($approved ? 'Day off: ' : 'Day off requested: ') . $text) . '"><b>'
        . ($approved ? 'Day off' : 'Requested') . '</b>' . h($text) . '</p>';
}

/**
 * Where a mark points. A done block links to the work that made it done; a
 * missed one links nowhere. The page is public and unauthenticated, so it
 * must never offer "log a session" — that would be an invitation to anyone.
 */
function tt_evidence_href(array $ev, ?string $subject): ?string
{
    if ($subject === null || !isset($ev['type'])) {
        return null;
    }
    return match ($ev['type']) {
        'session'  => '/s/' . rawurlencode($subject) . '/session/' . (int) $ev['id'],
        'attempt'  => '/s/' . rawurlencode($subject) . '/a/' . (int) $ev['id'],
        'practice' => '/s/' . rawurlencode($subject) . '/practice',
        default    => null,
    };
}

/** The one line under the board. Blocks done, blocks left, hours by subject. */
function tt_total_line(array $w, array $names): string
{
    $c     = $w['counts'];
    $soFar = $c['done'] + $c['missed'] + $c['excused'] + $c['now'] + $c['pending'];
    $ahead = $c['upcoming'] + $c['day_off'];
    $parts = ['<b>this week</b>', $c['done'] . ' of ' . $soFar . ' blocks so far'];
    if ($ahead) {
        $parts[] = $ahead . ' still to come';
    }
    foreach ($w['hours_by_subject'] as $slug => $hours) {
        $parts[] = h($names[$slug] ?? $slug) . ' ' . number_format($hours, 1) . 'h';
    }
    return '<p class="tt-total mono">' . implode('<span class="sep">·</span>', $parts) . '</p>';
}

function tt_legend(): string
{
    $rows = [
        ['done', 'done'], ['short', ''], ['now', 'now'], ['pending', 'pending'],
        ['missed', 'missed'], ['excused', 'excused'], ['upcoming', 'to come'],
    ];
    $out = '<p class="tt-legend">';
    foreach ($rows as [$status, $text]) {
        $b = ['status' => $status === 'short' ? 'done' : $status, 'short' => $status === 'short',
              'label' => $text ?: 'short', 'start' => '', 'end' => '', 'accent' => '#7c3aed',
              'minutes' => 0, 'length' => 0, 'reason' => null];
        $out .= '<span>' . tt_mark($b) . h($text) . '</span>';
    }
    return $out . '<span>' . tt_extra_mark() . 'extra</span></p>';
}

/**
 * Design A — the week strip.
 *
 * Five columns of thin chips, today's lifted off the paper under a red-pencil
 * tab. The parent's question is comparative across five days, so a vertical
 * run of marks answers it before a word is read.
 */
function tt_design_a(array $w, array $names): string
{
    $out = '<div class="wk">';
    foreach ($w['days'] as $day) {
        $judged = array_values(array_filter(
            $day['blocks'], static fn(array $b): bool => $b['status'] !== 'n/a'
        ));
        if (!$judged && !$day['extras']) {
            continue;   // Saturday and Sunday, until the timetable has blocks there.
        }
        $out .= '<div class="wkcol' . ($day['is_today'] ? ' is-today' : '')
            . ($day['date'] < tt_today() ? ' is-past' : '') . '">';
        if ($day['is_today']) {
            $out .= '<span class="daytab">TODAY</span>';
        }
        $out .= '<p class="dhead"><span class="dn">' . substr($day['day_name'], 0, 3) . '</span>'
            . '<span class="dd mono">' . h(tt_short_date($day['date'])) . '</span></p>'
            . tt_ribbon($day['day_off']);

        foreach ($day['blocks'] as $b) {
            if ($b['status'] === 'n/a') {
                $out .= '<div class="brk" role="separator" aria-label="'
                    . h($b['label'] . ' ' . $b['start'] . '–' . $b['end']) . '"></div>';
                continue;
            }
            $b['accent'] = tt_accent($b['subjects']);
            $href = null;
            foreach ($b['evidence'] as $ev) {
                $href = tt_evidence_href($ev, $b['subject']);
            }
            $tag   = $href ? 'a' : 'div';
            $attrs = 'class="blk s-' . h($b['status']) . '" style="--acc:' . h($b['accent']) . '"';
            if ($href) {
                $attrs .= ' href="' . h($href) . '"';
            }
            if ($b['status'] === 'excused' && $b['reason']) {
                $attrs .= ' title="' . h('Excused: ' . $b['reason']) . '"';
            }
            $out .= "<$tag $attrs><span class=\"bt mono\">" . h($b['start']) . '</span>'
                . '<span class="bl">' . h($b['label']) . '</span>' . tt_mark($b) . "</$tag>";
        }

        if ($day['extras']) {
            $out .= '<div class="tt-extras"><b>extra</b>';
            foreach ($day['extras'] as $e) {
                $href = tt_evidence_href($e, $e['subject']);
                $text = h(($names[$e['subject']] ?? $e['subject']) . ' — ' . $e['label']);
                $out .= '<div>' . tt_extra_mark()
                    . ($href ? '<a href="' . h($href) . '">' . $text . '</a>' : $text) . '</div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}

/** '7 Sep', for a column head. */
function tt_short_date(string $date): string
{
    return (new DateTimeImmutable($date, tt_zone()))->format('j M');
}

/**
 * The whole section: heading, the chosen design, the totals line and the
 * legend. `$week` is any date inside the week to render.
 */
function render_timetable_section(Store $store, string $dateInWeek, string $design, bool $isThisWeek): string
{
    $version = $store->timetableVersionOn($dateInWeek);
    if (!$version) {
        return '';   // No timetable set yet: show nothing rather than an empty grid.
    }
    $w   = $store->judgeWeek($dateInWeek);
    $now = tt_now();

    $names = tt_subject_names($store);
    $body  = '<section class="tt" aria-label="Weekly timetable">';

    // Saturday and Sunday have no blocks, so say what is next instead of
    // rendering an empty board.
    if ($isThisWeek && $w['days'][(int) $now->format('N') - 1]['blocks'] === []) {
        $body .= '<p class="tt-quiet">No blocks today — <b>Monday 09:00</b> next.</p>';
    }

    // Designs B and C land next; until then the switch resolves to A, so
    // ?design= is already live and the stored setting already means something.
    $body .= match ($design) {
        default => tt_design_a($w, $names),
    };

    $body .= tt_total_line($w, $names) . tt_legend();
    if ($isThisWeek) {
        $body .= '<p><small><a href="/week/' . h($w['week']) . '">This week as its own page</a>'
            . ' · <a href="/week/' . h(tt_iso_week(tt_add_days($w['monday'], -7)))
            . '">last week</a></small></p>';
    }
    // Enhancement only: without it the strip simply starts on Monday, which
    // is correct, just not where she is.
    if ($isThisWeek) {
        $body .= '<script>(function(){var s=document.currentScript.previousElementSibling;'
            . 'while(s&&!s.classList.contains("wk"))s=s.previousElementSibling;'
            . 'if(!s)return;var t=s.querySelector(".wkcol.is-today");'
            . 'if(t&&s.scrollWidth>s.clientWidth)s.scrollLeft=t.offsetLeft-s.offsetLeft-8;})();</script>';
    }
    return $body . '</section>';
}

/**
 * The date the page thinks it is, for the page header. `$monday` is only
 * needed for a week that is not this one — asking for it unconditionally
 * would mean judging the week twice on the busiest page on the site.
 */
function tt_stamp(?string $monday, bool $isThisWeek): string
{
    if ($isThisWeek) {
        $now = tt_now();
        return $now->format('l j F Y') . ' · ' . $now->format('H:i') . ' · Europe/London';
    }
    return tt_pretty($monday) . ' to ' . tt_pretty(tt_add_days($monday, 6));
}

/** /week/{iso} — the same component, for a week that is not this one. */
function render_week_page(Store $store, string $iso, array $query): string
{
    $monday = tt_week_monday($iso);
    if ($monday === null) {
        return dash_shell('Study trackers', '<header><div><p class="kicker">Study tracker</p>'
            . '<h1>Not a week</h1></div></header><p>Weeks look like <code>2026-W37</code>.</p>'
            . '<p><a href="/">Back to the subjects</a></p>');
    }
    $isThisWeek = $monday === tt_monday(tt_today());
    $section    = render_timetable_section($store, $monday, tt_design($store, $query), $isThisWeek);
    if ($section === '') {
        $section = '<p><small>No timetable was in force that week.</small></p>';
    }
    $stamp = $section === ''
        ? ''
        : '<p class="tt-stamp mono">' . h(tt_stamp($monday, $isThisWeek)) . '</p>';
    return dash_shell(
        'Week ' . $iso,
        '<header><div><p class="kicker">Study tracker</p><h1>Week ' . h($iso) . '</h1></div>'
        . '<div>' . $stamp . '<p><small><a href="/">All subjects</a></small></p></div></header>'
        . $section
    );
}

function render_index(Store $store, array $query = []): string
{
    $subjects = $store->listSubjects();
    // The timetable goes above the subjects list: what she is in now is a more
    // urgent question than how far through a syllabus she is. It also takes
    // over the page heading, because that is what the page now leads with.
    $timetable = render_timetable_section(
        $store, tt_today(), tt_design($store, $query), true
    );
    $title = $timetable === '' ? 'Subjects' : 'This week';
    $stamp = '';
    if ($timetable !== '') {
        $stamp = '<p class="tt-stamp mono">'
            . h(tt_stamp(null, true)) . '</p>';
    }
    $body = $timetable . ($timetable === '' ? '' : '<h2>Subjects</h2>');
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
        '<header><div><p class="kicker">Study tracker</p><h1>' . h($title) . '</h1></div>'
        . '<div>' . $stamp . '</div></header>' . $body
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

    // Every other page carries its way back in the kicker — a topic returns to
    // its subject, the practice board to its subject — and the subject page was
    // the one dead end. Same treatment, pointing at the index. Joining the
    // parts rather than concatenating them also drops the stray separator a
    // subject with a tier but no spec code used to grow.
    $trail       = array_filter([h((string) ($subject['spec_code'] ?? '')),
                                 h((string) ($subject['tier'] ?? ''))], static fn($p) => $p !== '');
    $kicker      = '<a href="/">← All subjects</a>'
                 . ($trail ? ' · ' . implode(' · ', $trail) : '');
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
