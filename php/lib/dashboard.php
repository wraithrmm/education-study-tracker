<?php
/**
 * Server-rendered dashboard, ported from src/dashboard.ts. Same markup, same
 * exercise-book styling as the original tracker artifact.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

// The lesson review on the pages: parent-gated sections, two parent-only
// pages, and the public "reviewed" tick.
require_once __DIR__ . '/dashboard_review.php';
// The weekly synthesis, the learner model and the week plans: parent-only.
require_once __DIR__ . '/dashboard_synthesis.php';

const STATUS_COLOUR = [
    'gap'        => '#ef4444',
    'notstarted' => '#d6d3d1',
    'developing' => '#fbbf24',
    'secure'     => '#10b981',
    'examready'  => '#0ea5e9',
];

const DASH_CSS = <<<'CSS'
/* --muted is the quiet text: times, dates, captions. Stone-600 rather than
   stone-500, because on this off-white paper the paler grey read as faded. */
:root{--ink:#1c1917;--muted:#57534e;--line:#d6d3d1;--card:#fff}
*{box-sizing:border-box}
body{margin:0;color:var(--ink);background-color:#fcfcf9;
  background-image:linear-gradient(rgba(96,140,200,.12) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(96,140,200,.12) 1px,transparent 1px);
  background-size:24px 24px;
  font-family:Georgia,"Times New Roman",serif;line-height:1.5}
.wrap{max-width:66rem;margin:0 auto;padding:2rem 1rem 4rem}
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
.tt-stamp{font-size:.9rem;color:var(--muted);margin:0}
.tt .mk{flex:none;display:block}
.tt .markwrap{display:flex;flex-direction:column;align-items:center;gap:0;flex:none;line-height:1}
.tt .shapecap{font-size:.62rem;font-weight:700;letter-spacing:.04em;color:#475569;line-height:1;margin-top:-1px}
.blk.s-shape .bl{color:#334155}
/* Unfinished work: a badge on the session row and the subject header. */
.unfin{display:inline-block;font-size:.66rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
  color:#9a3412;background:#ffedd5;border:1px solid #fdba74;border-radius:4px;padding:0 .35rem;vertical-align:middle}
.unfin.stale{color:#991b1b;background:#fee2e2;border-color:#fca5a5}
.unfin.closed{color:#57534e;background:#f5f5f4;border-color:#d6d3d1;text-decoration:line-through}
.tt .shortcap{font-size:.66rem;font-weight:700;letter-spacing:.04em;color:#b45309;
  font-family:ui-monospace,"Cascadia Mono",Menlo,monospace}
.mk.pulse{animation:ttpulse 2.2s ease-in-out infinite;transform-origin:center}
@keyframes ttpulse{0%,100%{opacity:1}50%{opacity:.3}}
@media (prefers-reduced-motion:reduce){.mk.pulse{animation:none}}
.tt-ribbon{display:block;font-size:.82rem;line-height:1.35;padding:.25rem .45rem;border-radius:5px;
  background:#f5f5f4;border:1px solid var(--line);color:#57534e;margin:0 0 .35rem}
.tt-ribbon.req{background:transparent;border-style:dashed}
.tt-ribbon b{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;margin-right:.3rem}
.tt-quiet{background:var(--card);border:1px dashed var(--line);border-radius:10px;
  padding:.8rem 1rem;font-size:.9rem;color:#57534e;margin:.5rem 0 0}
.tt-total{margin:.9rem 0 0;padding-top:.6rem;border-top:1px solid var(--line);
  font-size:.78rem;color:#57534e}
.tt-total .sep{color:#a8a29e;padding:0 .25rem}
.tt-total b{color:var(--ink)}
.tt-legend{display:flex;flex-wrap:wrap;gap:.5rem .9rem;margin-top:.6rem;font-size:.82rem;color:var(--muted)}
.tt-legend span{display:flex;align-items:center;gap:.25rem}
/* The class bell. Hers to switch, and quiet until she does: a switch, a
   button to hear it, and one line saying what it will ring for next. */
.tt-bell{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem .7rem;margin:.6rem 0 .8rem;
  padding:.45rem .7rem;background:var(--card);border:1px solid var(--line);border-radius:10px;
  font-size:.85rem;color:#57534e}
.tt-bell[hidden]{display:none}
.tt-bell-switch,.tt-bell-try{font:inherit;font-size:.82rem;padding:.25rem .7rem;
  border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink);cursor:pointer}
.tt-bell-switch b{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.68rem;
  letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-left:.15rem}
.tt-bell-switch:hover,.tt-bell-try:hover{border-color:#a8a29e}
.tt-bell-switch:focus-visible,.tt-bell-try:focus-visible{outline:2px solid #7c3aed;outline-offset:2px}
.tt-bell.is-on .tt-bell-switch{background:#292524;border-color:#292524;color:#fcfcf9}
.tt-bell.is-on .tt-bell-switch b{color:#fcd34d}
.tt-bell.is-blocked .tt-bell-switch{background:#78716c;border-color:#78716c}
.tt-bell-status{flex:1 1 14rem;min-width:0;line-height:1.35}
/* The parent's controls. Invisible to everyone else, and quiet even for him:
   the board is for reading, and these are for the times it is wrong. */
.tt-signin{margin:.3rem 0 0;text-align:right}
.tt-signin button{font:inherit;font-size:inherit;background:none;border:0;padding:0;
  color:var(--muted);text-decoration:underline;cursor:pointer}
.tt-ctl,.tt-ctl-menu{display:inline}
.dhead .tt-ctl,.dhead .tt-ctl-menu{margin-left:auto}
.dhead .tt-ctl button,.dhead .tt-ctl-menu>summary{font:inherit;font-size:.68rem;
  color:var(--muted);background:none;border:1px solid var(--line);border-radius:999px;
  padding:0 .4rem;cursor:pointer;list-style:none}
.tt summary{list-style:none}
.tt summary::marker{content:""}
.tt summary::-webkit-details-marker{display:none}
.dhead .tt-ctl button:hover,.dhead .tt-ctl-menu>summary:hover{border-color:#a8a29e;color:var(--ink)}
.tt-ctl-menu[open]>summary{border-color:#292524;color:var(--ink)}
.tt-ctl-menu form,.blockctl-body form{display:flex;flex-wrap:wrap;gap:.25rem;
  margin:.3rem 0 .1rem;padding:.35rem;background:#faf9f5;border:1px solid var(--line);
  border-radius:8px}
.dhead .tt-ctl-menu{position:relative}
.dhead .tt-ctl-menu>form,.dhead .tt-ctl-menu form{position:absolute;right:0;
  top:calc(100% + .3rem);z-index:5;width:12.5rem;flex-direction:column;align-items:stretch;
  margin:0;box-shadow:0 6px 18px -8px rgba(28,25,23,.45)}
.dhead .tt-ctl-menu input[type=text]{flex:none;width:100%}
.tt-ctl-menu input[type=text],.blockctl-body input[type=text]{font:inherit;font-size:.76rem;
  padding:.2rem .35rem;border:1px solid var(--line);border-radius:5px;flex:1 1 7rem;min-width:0}
.tt-ctl-menu button[type=submit],.blockctl-body button{font:inherit;font-size:.74rem;
  padding:.2rem .5rem;border:1px solid var(--line);border-radius:5px;background:#fff;
  cursor:pointer;white-space:nowrap}
.tt-ctl-menu button[type=submit]:hover,.blockctl-body button:hover{border-color:#292524}
/* The mark itself is the trigger — the red cross is the button. */
.blockctl{flex:none;position:relative}
.blockctl>summary{list-style:none;cursor:pointer;display:block;border-radius:6px}
.blockctl>summary::-webkit-details-marker{display:none}
.blockctl>summary:hover{outline:2px solid #d6d3d1;outline-offset:1px}
.blockctl>summary:focus-visible{outline:2px solid #7c3aed;outline-offset:2px}
.blockctl[open]>summary{outline:2px solid #292524;outline-offset:1px}
/* A popover, so opening one never changes the height of its column — the
   whole point of the board is that the five columns compare. */
.blockctl-body{position:absolute;right:0;top:calc(100% + .3rem);z-index:5;width:12.5rem}
.blockctl-body form{margin:0;flex-direction:column;align-items:stretch;
  box-shadow:0 6px 18px -8px rgba(28,25,23,.45)}
.blockctl-body input[type=text]{flex:none;width:100%}
.blk.s-declared .bl{color:#57534e}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
  clip:rect(0 0 0 0);white-space:nowrap;border:0}

/* Design A — the week strip. Five columns on a laptop; on a phone one
   snap-scrolling strip that opens on today. */
.wk{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.55rem;margin-top:.5rem;
  align-items:start}
.wkcol{background:var(--card);border:1px solid var(--line);border-radius:9px;
  padding:.45rem .45rem .5rem;position:relative}
.wkcol.is-today{border-color:#292524;box-shadow:0 1px 0 #292524,3px 4px 10px -6px rgba(28,25,23,.55)}
.wkcol.is-past{background:#fdfdfc}
.daytab{position:absolute;top:-.66rem;left:.5rem;font-size:.68rem;font-weight:700;letter-spacing:.14em;
  font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;color:#b91c1c;background:#fcfcf9;
  border:1.5px solid #dc2626;border-radius:4px;padding:0 .3rem;transform:rotate(-1.6deg)}
.dhead{display:flex;align-items:baseline;justify-content:space-between;gap:.3rem;
  padding:.15rem .15rem .35rem;border-bottom:1px solid #ededea;margin-bottom:.15rem}
.dhead .dn{font-weight:700;font-size:.98rem}
.dhead .dd{font-size:.78rem;color:var(--muted)}
.blk{display:flex;align-items:center;gap:.4rem;padding:.28rem .3rem .28rem .45rem;
  border-left:3px solid var(--acc,#a8a29e);border-radius:0 4px 4px 0;margin:.14rem 0;
  text-decoration:none;color:inherit}
.blk .bt{font-size:.76rem;color:var(--muted);flex:none;width:2.6rem;
  font-variant-numeric:tabular-nums}
.blk .bl{flex:1;min-width:0;font-size:.88rem;line-height:1.25;overflow:hidden;
  display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical}
.blk.s-missed{background:rgba(220,38,38,.055)}
.blk.s-now{background:rgba(124,58,237,.07);box-shadow:inset 0 0 0 1px rgba(124,58,237,.28);
  border-radius:4px}
.blk.s-done .bl,.blk.s-excused .bl{color:#57534e}
.blk.s-excused .bl{text-decoration:line-through;text-decoration-color:#a8a29e}
.blk.s-upcoming,.blk.s-pending{opacity:.8}
.blk.s-optional{opacity:.76}
/* Lunch and the Thursday group: untracked, so no mark, but labelled — they
   are the slots a person looks for. The end time is shown because it is the
   question being asked: when is she back? */
.blk.s-quiet{border-left-style:dotted;background:#f7f6f2}
.blk.s-quiet .bl{color:#44403c}
.blk.s-quiet .til{color:var(--muted);white-space:nowrap}
a.blk:hover{background:#faf9f5}
.brk{height:1px;background:#ededea;margin:.3rem .3rem .3rem 0}
.tt-extras{margin:.35rem 0 0;padding:.3rem .2rem 0;border-top:1px dotted #e7e5e4;
  font-size:.78rem;color:#57534e}
.tt-extras>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:.3rem;
  color:var(--muted)}
.tt-extras>summary::-webkit-details-marker{display:none}
.tt-extras>summary:hover{color:var(--ink)}
.tt-extras>summary:focus-visible{outline:2px solid #7c3aed;outline-offset:2px;border-radius:4px}
.tt-extras>summary::after{content:"▸";margin-left:auto;font-size:.7em;color:var(--muted)}
.tt-extras[open]>summary::after{content:"▾"}
.tt-extras-list div{margin-top:.25rem;line-height:1.25}
.tt-extras a{color:inherit}
/* Phone first in behaviour, if not in source order: below 40rem the five
   columns become one strip, and the page scrolls it to today. */
@media (max-width:40rem){
  .wk{display:flex;align-items:flex-start;overflow-x:auto;gap:.5rem;padding:.7rem 0 .5rem;
    scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
  .wkcol{flex:0 0 78%;scroll-snap-align:center}
}
/* ---- the weekly report: /week/{iso} and /weeks --------------------------
   The ledger and the margin. Everything above the margin is computed on this
   request; everything set in the handwriting stack was written once, on a
   Friday, and carries its own timestamp. The two never share a typeface, so
   which half you are reading is never in doubt. */
.wkhead .weeknav{margin:.3rem 0 0;font-size:.78rem;color:var(--muted)}
.wkhead .weeknav a{text-decoration:none;border-bottom:1px solid var(--line)}
.wkhead .weeknav .off{color:#a8a29e}
/* The stamp — the one mark on the page a person or the routine put there. */
.stamp{display:inline-block;margin:0;border:2px solid #292524;color:#292524;border-radius:8px;
  padding:.34rem .65rem;transform:rotate(-2.4deg);background:rgba(252,252,249,.7);
  font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.6rem;font-weight:700;
  line-height:1.35;letter-spacing:.13em;text-transform:uppercase;text-align:center;white-space:nowrap}
.stamp.draft{border-style:dashed;border-color:#a8a29e;color:var(--muted);transform:rotate(1.8deg)}
.panel-title{text-transform:uppercase;letter-spacing:.12em;font-size:.72rem;color:#374151;
  font-weight:700;margin:1.6rem 0 .5rem}
.cap{font-size:.72rem;color:var(--muted);margin:.4rem 0 0}
.mstats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-top:1.1rem}
.mstats .card{padding:.8rem .9rem}
.mstats .card p.big{font-size:1.45rem;margin:.15rem 0 0;line-height:1.2}
.mstats .sub{margin:.3rem 0 0;font-size:.72rem;color:#57534e}
.segbar{display:flex;gap:2px;margin-top:.45rem}
.segbar span{height:9px;flex:1;border-radius:2px;background:#e7e5e4}
.segbar .d{background:#059669}
.segbar .s{background:#f59e0b}
.segbar .m{background:#dc2626}
.segbar .x{background:#a8a29e}
/* Marked by hand: stone and broken, the segment of the dashed tick the
   register draws. Never the emerald of a block the record can prove. */
.segbar .h{background:repeating-linear-gradient(90deg,#78716c 0 3px,#e0ddd6 3px 5px)}
/* Done, in the wrong shape: slate, so it is neither the emerald of a clean
   tick nor the red of a miss. */
.segbar .p{background:#475569}
.minibar{height:9px;border-radius:999px;background:#e7e5e4;overflow:hidden;margin-top:.45rem}
.minibar i{display:block;height:100%;background:#059669;border-radius:999px}
.minibar i.under{background:#f59e0b}
.named{margin:.2rem 0 0;padding:0;list-style:none}
.named li{background:var(--card);border:1px solid var(--line);border-left:3px solid #dc2626;
  border-radius:0 8px 8px 0;padding:.5rem .7rem;margin-top:.4rem;font-size:.88rem}
.named.shortlist li{border-left-color:#d97706}
.named.handlist li{border-left-color:#78716c;border-left-style:dashed}
.named.shapelist li{border-left-color:#475569;border-left-style:double}
.named .when{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.76rem;color:#57534e}
.decide{margin:.55rem 0 0;padding:0;list-style:none}
.decide li{background:var(--card);border:1px dashed var(--line);border-radius:8px;
  padding:.45rem .7rem;margin-top:.35rem;font-size:.85rem;display:flex;flex-wrap:wrap;
  gap:.3rem .6rem;justify-content:space-between;align-items:baseline}
.decide li.settled{border-style:solid;border-left:3px solid #059669}
.decide .ask,.decide .said{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;
  font-size:.7rem;font-weight:700;line-height:1.4}
.decide .ask{color:#b45309}
.decide .said{color:#166534}
.decide .said.no{color:#b91c1c}
.hrow{display:flex;align-items:center;gap:.6rem;margin:.3rem 0}
.hrow .nm{width:11rem;flex:none;font-size:.85rem}
.hrow .hbar{flex:1;height:15px;background:var(--card);border:1px solid var(--line);
  border-radius:4px;overflow:hidden}
.hrow .hbar i{display:block;height:100%;background:#059669}
.hrow .hbar i.under{background:#f59e0b}
.hrow .num{width:6.5rem;flex:none;text-align:right;font-size:.76rem;color:#44403c}
.subjgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}
.subj{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.75rem .85rem}
.subj .sh{display:flex;gap:.6rem;align-items:flex-start;justify-content:space-between}
.subj h4{font-size:1rem;font-weight:700;margin:0}
.subj .sspec{margin:.05rem 0 0;font-size:.72rem;color:var(--muted)}
.subj .cov{text-align:right;flex:none}
.subj .pc{font-weight:700;font-size:1.05rem}
.subj .delta{font-size:.72rem;color:#059669;margin-left:.25rem}
.subj .delta.flat{color:var(--muted)}
.subj .delta.down{color:#b91c1c}
.subj .lab,.kv dt,.marginbox h4,.marginbox .who{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;
  font-size:.62rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.subj .lab{margin:.6rem 0 .25rem}
.sublab{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.62rem;font-weight:700;
  letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:.9rem 0 0}
.subj .chips{margin:.25rem 0 0}
.subj .chip{font-size:.7rem;color:#44403c}
.subj .chip .ref{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-weight:700;color:var(--ink)}
.subj .chip .arrow{color:#a8a29e}
.spark{display:block;margin-top:.2rem}
.kv{margin:.5rem 0 0;font-size:.75rem;color:#57534e}
.kv dt{margin-top:.4rem}
.kv dd{margin:.1rem 0 0}
/* The margin. One typeface for everything written rather than computed. */
.hand{font-family:"Caveat","Segoe Print","Bradley Hand",cursive;color:#57534e;
  font-size:1.05rem;line-height:1.35}
.subj .hand{margin:.6rem 0 0;padding:.45rem 0 0 .6rem;border-top:1px dashed var(--line);
  border-left:2px solid #e7e5e4}
.sig{display:block;font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.58rem;
  letter-spacing:.06em;color:#a8a29e;margin-top:.15rem}
.marginbox{background:var(--card);border:1px solid var(--line);border-radius:10px;margin-top:.5rem;
  display:grid;grid-template-columns:12rem minmax(0,1fr)}
.marginbox .gutter{border-right:1px dashed var(--line);padding:.85rem .8rem;background:#fdfdfb;
  border-radius:10px 0 0 10px}
.marginbox .gutter p{margin:0;font-size:.72rem;color:var(--muted)}
.marginbox .who{display:block;color:#57534e;margin-bottom:.3rem}
.marginbox .mbody{padding:.85rem .95rem}
.marginbox h4{margin:.75rem 0 .2rem;letter-spacing:.14em}
.marginbox h4:first-child{margin-top:0}
.marginbox .hand{font-size:1.1rem;color:#44403c;margin:0}
.vers{display:flex;flex-wrap:wrap;gap:.3rem;margin:.15rem 0 .5rem;padding:0;list-style:none}
.vers li a,.vers li span{display:inline-block;font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;
  font-size:.62rem;font-weight:700;border:1px solid var(--line);border-radius:999px;
  padding:.15rem .5rem;text-decoration:none;color:#57534e}
.vers li span[aria-current]{background:#292524;border-color:#292524;color:#fcfcf9}
.driftline{margin:.7rem 0 0;padding-top:.5rem;border-top:1px solid #ededea;font-size:.74rem;
  color:#44403c;font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;line-height:1.5}
footer.wkfoot{margin-top:1.6rem}
/* the term ledger */
.ledger{min-width:52rem;font-size:.78rem}
.ledger th{font-weight:700}
.ledger .wkid{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-weight:700;
  white-space:nowrap;text-align:left;font-size:.78rem;text-transform:none;letter-spacing:0;
  color:var(--ink);padding:.5rem .6rem;border-bottom:1px solid #ededea}
.ledger .quiet{color:var(--muted);font-style:italic}
.ledger td.mono{white-space:nowrap}
.ledger .segbar{margin:0 0 .2rem;min-width:6rem}
.ledger .segbar span{height:7px}
.ledger tr.now td,.ledger tr.now th{background:#fefce8}
.ledger tr.now th:first-child{box-shadow:inset 3px 0 0 #292524}
.ministamp{display:inline-block;border:1.5px solid #292524;color:#292524;border-radius:5px;
  padding:.08rem .3rem;transform:rotate(-2deg);text-decoration:none;
  font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.56rem;font-weight:700;
  letter-spacing:.1em;text-transform:uppercase}
.ministamp.draft{border-style:dashed;border-color:#a8a29e;color:var(--muted)}
.ministamp.none{border:0;transform:none;color:#a8a29e;letter-spacing:.06em}
.driftdot{display:inline-block;width:7px;height:7px;border-radius:999px;background:#b45309;
  margin-left:.3rem;vertical-align:middle}
@media (max-width:52rem){
  .mstats{grid-template-columns:1fr 1fr}
  .subjgrid{grid-template-columns:1fr}
  .marginbox{grid-template-columns:1fr}
  .marginbox .gutter{border-right:0;border-bottom:1px dashed var(--line);
    border-radius:10px 10px 0 0}
  .hrow .nm{width:6.5rem;font-size:.78rem}
}
/* The board on paper: the week, its totals and its key, and nothing else. */
@media print{
  .wk{gap:.3rem}
  .wkcol{break-inside:avoid;box-shadow:none}
  .tt-legend,.tt-total{font-size:8pt}
  .tt-signin,.tt-bell,.tt-extras,.blockctl,.tt-ctl,.tt-ctl-menu,footer,.item,h2{display:none}
  .tt{margin:0}
}
CSS;

/**
 * The handwriting the margin is set in, loaded only by the two pages that
 * have a margin. It is an enhancement, not a dependency: the stack falls back
 * through two system faces to plain cursive, so the note still reads as
 * written-by-hand with the network off.
 */
const DASH_HAND_FONT = '<link rel="preconnect" href="https://fonts.googleapis.com">'
    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
    . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;600'
    . '&amp;display=swap">';

/**
 * `$head` is optional so a page can ask for something extra in the head —
 * today only the handwriting font — without every other page paying for it.
 */
function dash_shell(string $title, string $body, string $head = ''): string
{
    $t   = h($title);
    $css = DASH_CSS . "\n" . DASH_REVIEW_CSS . "\n" . DASH_SYNTH_CSS;
    return "<!doctype html><html lang=\"en-GB\"><head><meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
        . "<title>$t</title>$head<style>$css</style></head>\n"
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

/**
 * A status mark. Shape carries the status as well as colour, and the
 * aria-label says it in words, so neither colour vision nor the legend is
 * needed to read the board.
 */
function tt_mark(array $b): string
{
    $name = $b['label'] . ' ' . $b['start'];
    // 19px, not 16: the mark carries the status, so it has to be as legible as
    // the label beside it — including from across the room.
    $svg  = static function (string $inner, string $label, string $cls = ''): string {
        return '<svg class="mk' . ($cls ? ' ' . $cls : '') . '" width="19" height="19" '
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
            if (($b['shape'] ?? null) === 'unmet') {
                // Done, in the wrong shape. A tick — it was done — in slate
                // rather than emerald, with a small "shape" under it, so it
                // reads as neither a clean tick nor a miss. The reason
                // describes the work, never the student.
                return $wrap($svg(
                    '<path d="M3.2 9.6 6.8 13.4 14.8 4.3" fill="none" stroke="#475569" '
                    . 'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
                    . '<circle cx="14.6" cy="13.6" r="2.4" fill="none" stroke="#475569" stroke-width="1.3"/>',
                    "$name — done, but not the shape the block asked for: " . ($b['shape_reason'] ?? '')
                ) . '<span class="shapecap">shape</span>');
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
        case 'declared':
            // Hollow, and stone rather than emerald: the parent said so, the
            // record did not. The board must never pass one off as the other.
            return $wrap($svg(
                '<path d="M3.2 9.6 6.8 13.4 14.8 4.3" fill="none" stroke="#78716c" '
                . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
                . 'stroke-dasharray="2.6 2"/>',
                "$name — marked done by Dad; no work was logged"
                . ($b['reason'] ? ': ' . $b['reason'] : '')
            ));
        case 'optional':
            // Neither tick nor cross: this one is hers to take, and the board
            // has no evidence either way.
            return $wrap($svg(
                '<circle cx="9" cy="9" r="4.4" fill="none" stroke="#e0ddd6" stroke-width="1.3"/>',
                "$name — optional, nothing logged"
            ));
        default:
            return '';
    }
}

function tt_extra_mark(): string
{
    return '<svg class="mk" width="19" height="19" viewBox="0 0 18 18" role="img" '
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
    // Optional blocks are deliberately absent from the denominator: ticking one
    // counts towards done, not ticking one costs nothing. A block the parent
    // marked done is counted — it is accounted for — but named separately,
    // because the board should never quietly present an assertion as evidence.
    $done  = $c['done'] + $c['declared'];
    $soFar = $done + $c['missed'] + $c['excused'] + $c['now'] + $c['pending'];
    $ahead = $c['upcoming'] + $c['day_off'];
    $parts = ['<b>this week</b>', $done . ' of ' . $soFar . ' blocks so far'];
    if ($c['declared']) {
        $parts[] = $c['declared'] . ' marked by hand';
    }
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
        ['done', 'done'], ['short', ''], ['shape', 'not in shape'], ['declared', 'marked by hand'],
        ['now', 'now'], ['pending', 'pending'], ['missed', 'missed'], ['excused', 'excused'],
        ['optional', 'optional'], ['upcoming', 'to come'],
    ];
    $out = '<p class="tt-legend">';
    foreach ($rows as [$status, $text]) {
        $b = ['status' => in_array($status, ['short', 'shape'], true) ? 'done' : $status,
              'short' => $status === 'short',
              'shape' => $status === 'shape' ? 'unmet' : null,
              'shape_reason' => $status === 'shape' ? 'the work was not the shape the block asked for' : null,
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
function tt_design_a(array $w, array $names, ?array $ctl = null): string
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
        $out .= '<div class="dhead"><span class="dn">' . substr($day['day_name'], 0, 3) . '</span>'
            . '<span class="dd mono">' . h(tt_short_date($day['date'])) . '</span>'
            . ($ctl ? tt_day_control($day, $ctl['csrf'], $ctl['next']) : '') . '</div>'
            . tt_ribbon($day['day_off']);

        foreach ($day['blocks'] as $b) {
            if ($b['status'] === 'n/a') {
                $out .= tt_quiet_block($b);
                continue;
            }
            $b['accent'] = tt_accent($b['subjects']);
            $href = null;
            foreach ($b['evidence'] as $ev) {
                $href = tt_evidence_href($ev, $b['subject']);
            }
            $cls = 'class="blk s-' . h($b['status'])
                . (($b['shape'] ?? null) === 'unmet' ? ' s-shape' : '')
                . '" style="--acc:' . h($b['accent']) . '"';
            if ($b['status'] === 'excused' && $b['reason']) {
                $cls .= ' title="' . h('Excused: ' . $b['reason']) . '"';
            } elseif ($b['status'] === 'declared' && $b['reason']) {
                $cls .= ' title="' . h('Marked done by Dad: ' . $b['reason']) . '"';
            } elseif (($b['shape'] ?? null) === 'unmet' && !empty($b['shape_reason'])) {
                $cls .= ' title="' . h('Done, but not in shape: ' . $b['shape_reason']) . '"';
            }
            if ($ctl) {
                // Signed in, the mark becomes the control, so the chip cannot
                // also be one big link — the label carries the link instead.
                $label = $href
                    ? '<a class="bl" href="' . h($href) . '">' . h($b['label']) . '</a>'
                    : '<span class="bl">' . h($b['label']) . '</span>';
                $out .= "<div $cls><span class=\"bt mono\">" . h($b['start']) . '</span>'
                    . $label
                    . tt_block_control($b, $day['date'], $ctl['csrf'], $ctl['next'], tt_mark($b))
                    . '</div>';
            } else {
                $tag = $href ? 'a' : 'div';
                if ($href) {
                    $cls .= ' href="' . h($href) . '"';
                }
                $out .= "<$tag $cls><span class=\"bt mono\">" . h($b['start']) . '</span>'
                    . '<span class="bl">' . h($b['label']) . '</span>' . tt_mark($b) . "</$tag>";
            }
        }

        $out .= tt_extras_block($day['extras'], $names) . '</div>';
    }
    return $out . '</div>';
}

/**
 * An untracked block. A short break is a rule between chips — it is not a
 * thing anyone looks for. Lunch and the group session are: they are labelled,
 * with their end time, and carry no mark because nothing judges them.
 */
function tt_quiet_block(array $b): string
{
    $span = $b['start'] . '–' . $b['end'];
    if (($b['kind'] ?? '') === 'break') {
        return '<div class="brk" role="separator" aria-label="'
            . h($b['label'] . ' ' . $span) . '"></div>';
    }
    return '<div class="blk s-quiet" aria-label="' . h($b['label'] . ' ' . $span) . '">'
        . '<span class="bt mono">' . h($b['start']) . '</span>'
        . '<span class="bl">' . h($b['label'])
        . ' <span class="til mono">– ' . h($b['end']) . '</span></span></div>';
}

/**
 * The extra work of a day — logged, real, and outside the timetable.
 *
 * Collapsed by default. A busy day can carry several of these, and expanded
 * they made one column two or three times the height of its neighbours, which
 * wrecks the comparison across the week that the board exists for. The count
 * stays visible, so nothing is hidden — only folded.
 */
function tt_extras_block(array $extras, array $names): string
{
    if (!$extras) {
        return '';
    }
    $n   = count($extras);
    $out = '<details class="tt-extras"><summary>' . tt_extra_mark()
        . '<span>' . $n . ' extra</span></summary><div class="tt-extras-list">';
    foreach ($extras as $e) {
        $href = tt_evidence_href($e, $e['subject']);
        $text = h(($names[$e['subject']] ?? $e['subject']) . ' — ' . $e['label']);
        $out .= '<div>' . ($href ? '<a href="' . h($href) . '">' . $text . '</a>' : $text) . '</div>';
    }
    return $out . '</div></details>';
}

/** '7 Sep', for a column head. */
function tt_short_date(string $date): string
{
    return (new DateTimeImmutable($date, tt_zone()))->format('j M');
}

// ---- the class bell ------------------------------------------------------
//
// She loses track of time, and the model on the other end of the chat is no
// better at it. So the page keeps time instead: with the bell on, the browser
// plays a chime at every boundary in today's timetable — a block ending, the
// next one starting — and says in words what has just changed. It is hers to
// switch, it remembers her choice on this device, and it writes nothing to
// the record: nothing here can mark a block done, missed or anything else.

/**
 * The bell's script. Plain JavaScript, no library: the chime is synthesised
 * with the Web Audio API so there is no sound file to fetch and nothing to
 * cache. Everything it needs is in the JSON block beside it — today's blocks
 * and the server's clock — and everything it does is between the browser and
 * her ears.
 *
 * Time is kept as "minutes since midnight, Europe/London": the server's
 * reading at render plus however long the page has been open. That way the
 * browser's own timezone never enters into it, and a test that freezes the
 * clock with TRACKER_NOW freezes the bell along with the board.
 *
 * Browsers refuse to make a sound until the page has been clicked, so
 * switching the bell on is the click that unlocks it, and the switch chimes
 * once to prove it. If the page is reloaded with the bell already on, the
 * status line says so until she has clicked anywhere on the page.
 */
const TT_BELL_JS = <<<'JS'
(function(){
var box=document.getElementById('ttbell');if(!box)return;
var data;try{data=JSON.parse(document.getElementById('ttbell-data').textContent);}catch(e){return;}
var KEY='tt-bell';
var sw=box.querySelector('.tt-bell-switch'),tryBtn=box.querySelector('.tt-bell-try'),
    status=box.querySelector('.tt-bell-status');
var t0=Date.now();
function nowMin(){return data.minute+(Date.now()-t0)/60000;}
function mins(s){var p=s.split(':');return p[0]*60+ +p[1];}
function hhmm(m){m=Math.round(m);var h=Math.floor(m/60),n=m%60;return (h<10?'0':'')+h+':'+(n<10?'0':'')+n;}
function span(m){m=Math.ceil(m);if(m<1)return 'now';if(m<60)return 'in '+m+' min';
  var h=Math.floor(m/60),n=m%60;return 'in '+h+' h'+(n?' '+n+' min':'');}

/* Every boundary in the day, with what starts and what ends there. */
var marks={};
data.blocks.forEach(function(b){
  var s=mins(b.start),e=mins(b.end);
  (marks[s]=marks[s]||{at:s,starts:[],ends:[]}).starts.push(b);
  (marks[e]=marks[e]||{at:e,starts:[],ends:[]}).ends.push(b);
});
var bounds=Object.keys(marks).map(Number).sort(function(a,b){return a-b;}).map(function(k){return marks[k];});
function kindOf(mark){
  if(!mark.starts.length)return 'end';
  return mark.starts.every(function(b){return b.rest;})?'rest':'work';
}
function describe(mark){
  if(mark.starts.length){var b=mark.starts[0];return b.label+' until '+b.end;}
  return mark.ends[0].label+' is over: that is the day done';
}
function nextAfter(n){for(var i=0;i<bounds.length;i++){if(bounds[i].at>n)return bounds[i];}return null;}

/* The sound. Three partials per note, the upper two inharmonic, so it rings
   like a small bell rather than buzzing like a tone. A study block starting
   is a rising three-note chime; a break is a two-note ding-dong; the end of
   the day falls away over three. */
var ctx=null;
function audio(){
  if(!ctx){var AC=window.AudioContext||window.webkitAudioContext;if(!AC)return null;
    ctx=new AC();ctx.addEventListener('statechange',function(){render(nowMin());});}
  if(ctx.state==='suspended'){try{ctx.resume();}catch(e){}}
  return ctx;
}
function note(ac,freq,when,len){
  var g=ac.createGain();
  g.gain.setValueAtTime(0.0001,when);
  g.gain.exponentialRampToValueAtTime(0.28,when+0.012);
  g.gain.exponentialRampToValueAtTime(0.0001,when+len);
  g.connect(ac.destination);
  [[1,1],[2.01,0.35],[2.76,0.18]].forEach(function(p){
    var o=ac.createOscillator(),pg=ac.createGain();
    o.type='sine';o.frequency.value=freq*p[0];pg.gain.value=p[1];
    o.connect(pg);pg.connect(g);o.start(when);o.stop(when+len+0.05);
  });
}
function chime(kind,gesture){
  var ac=audio();if(!ac)return false;
  /* Outside a click the browser may still be holding the sound back; queued
     notes would then play at some later click, out of nowhere, so don't. */
  if(!gesture&&ac.state!=='running')return false;
  var t=ac.currentTime+0.03;
  if(kind==='rest'){note(ac,880,t,1.4);note(ac,659,t+0.38,1.9);}
  else if(kind==='end'){note(ac,880,t,1.2);note(ac,740,t+0.32,1.2);note(ac,587,t+0.64,2.2);}
  else{note(ac,784,t,1.1);note(ac,988,t+0.3,1.1);note(ac,1175,t+0.6,2.2);}
  return true;
}

/* Her choice, kept on this device only. */
var on=false;try{on=localStorage.getItem(KEY)==='1';}catch(e){}
var last=Math.floor(nowMin()),rung={},recent=null,timer=null;

function render(n){
  var blocked=on&&ctx&&ctx.state!=='running';
  sw.setAttribute('aria-checked',on?'true':'false');
  sw.querySelector('b').textContent=on?'on':'off';
  box.classList.toggle('is-on',on);
  box.classList.toggle('is-blocked',!!blocked);
  var msg,next=nextAfter(n);
  if(data.dayOff)msg='Day off: nothing to ring for.';
  else if(!bounds.length)msg='No blocks today: nothing to ring for.';
  else if(n>=1440)msg='That was yesterday’s timetable. Reload the page for today’s.';
  else if(!next)msg='The last block has ended: nothing more to ring for today.';
  else msg='Next '+(on?'chime':'change')+' '+hhmm(next.at)+', '+describe(next)+' ('+span(next.at-n)+').';
  if(recent&&n-recent.at<5)msg='Rang '+hhmm(recent.at)+': '+recent.text+'. '+msg;
  if(blocked)msg='The browser is holding the sound back until you click somewhere on the page. '+msg;
  else if(!on)msg='Off. Switch it on to hear a chime when a block starts or ends. '+msg;
  status.textContent=msg;
}
function tick(){
  var n=nowMin(),cur=Math.floor(n),hit=null;
  for(var i=0;i<bounds.length;i++){if(bounds[i].at>last&&bounds[i].at<=cur)hit=bounds[i];}
  last=cur;
  if(hit&&on&&!rung[hit.at]){
    rung[hit.at]=1;recent={at:hit.at,text:describe(hit)};
    chime(kindOf(hit),false);
  }
  render(n);schedule(n);
}
function schedule(n){
  if(timer)clearTimeout(timer);
  var next=nextAfter(n);
  var toMinute=(1-(n-Math.floor(n)))*60000+120;
  var toNext=next?(next.at-n)*60000+120:Infinity;
  timer=setTimeout(tick,Math.max(250,Math.min(toMinute,toNext,60000)));
}

sw.addEventListener('click',function(){
  on=!on;try{localStorage.setItem(KEY,on?'1':'0');}catch(e){}
  if(on)chime('work',true);
  render(nowMin());
});
tryBtn.addEventListener('click',function(){
  chime('work',true);setTimeout(function(){chime('rest',true);},2600);
  status.textContent='That is the start-of-block chime, then the break chime.';
});
/* Reloaded with the bell on: any click on the page is enough to unlock it. */
function unlock(){var ac=audio();if(ac&&ac.state==='running'){
  document.removeEventListener('pointerdown',unlock,true);document.removeEventListener('keydown',unlock,true);}}
if(on){audio();if(ctx&&ctx.state!=='running'){
  document.addEventListener('pointerdown',unlock,true);document.addEventListener('keydown',unlock,true);}}
/* A tab in the background gets its timers slowed to once a minute; coming
   back to it catches up at once, and a boundary crossed meanwhile still rings. */
document.addEventListener('visibilitychange',function(){if(!document.hidden)tick();});
setInterval(tick,30000);
box.hidden=false;
tick();
})();
JS;

/**
 * The bell's panel: the switch, a button to hear the sound, a status line
 * that says what is coming next, and the day's blocks as JSON for the script
 * to keep time against. Rendered hidden and unhidden by the script, so with
 * JavaScript off there is no dead control on the page. Only today's blocks
 * are carried: a day off has nothing to ring for, and neither does a weekend.
 */
function tt_bell(array $day): string
{
    $blocks = [];
    $dayOff = false;
    foreach ($day['blocks'] as $b) {
        if ($b['status'] === 'day_off') {
            $dayOff = true;
        }
        $blocks[] = [
            'start' => $b['start'],
            'end'   => $b['end'],
            'label' => $b['label'],
            // A break or a walk chimes differently from a study block, so she
            // can tell which it is from across the room.
            'rest'  => $b['tracking'] === 'none' || in_array($b['kind'], ['break', 'movement'], true),
        ];
    }
    $now  = tt_now();
    $data = [
        'minute' => (int) $now->format('G') * 60 + (int) $now->format('i') + (int) $now->format('s') / 60,
        'dayOff' => $dayOff,
        'blocks' => $dayOff ? [] : $blocks,
    ];
    // HEX_TAG so a label can never close the script element early.
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

    return '<div class="tt-bell" id="ttbell" hidden>'
        . '<button type="button" class="tt-bell-switch" role="switch" aria-checked="false">'
        . '<span aria-hidden="true">&#128276;</span> Class bell <b>off</b></button>'
        . '<button type="button" class="tt-bell-try">try the sound</button>'
        . '<span class="tt-bell-status" aria-live="polite"></span>'
        . '<script type="application/json" id="ttbell-data">' . $json . '</script>'
        . '<script>' . TT_BELL_JS . '</script></div>';
}

/**
 * The whole section: heading, the chosen design, the totals line and the
 * legend. `$week` is any date inside the week to render.
 */
function render_timetable_section(
    Store $store,
    string $dateInWeek,
    bool $isThisWeek,
    bool $isParent = false,
    string $selfPath = '/'
): string
{
    $version = $store->timetableVersionOn($dateInWeek);
    if (!$version) {
        return '';   // No timetable set yet: show nothing rather than an empty grid.
    }
    $w   = $store->judgeWeek($dateInWeek);
    $now = tt_now();

    $names = tt_subject_names($store);
    $ctl   = $isParent
        ? ['csrf' => parent_csrf($store), 'next' => $selfPath]
        : null;
    $body  = '<section class="tt" aria-label="Weekly timetable">';

    // Saturday and Sunday have no blocks, so say what is next instead of
    // rendering an empty board.
    if ($isThisWeek && $w['days'][(int) $now->format('N') - 1]['blocks'] === []) {
        $body .= '<p class="tt-quiet">No blocks today — <b>Monday 09:00</b> next.</p>';
    }

    // The bell keeps time for her on the day she is looking at, and no other.
    if ($isThisWeek) {
        $body .= tt_bell($w['days'][(int) $now->format('N') - 1]);
    }

    $body .= tt_design_a($w, $names, $ctl);

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

// ---- the weekly report ---------------------------------------------------
//
// /week/{iso} is the ledger and the margin: every figure computed from the
// record on this request, one written note underneath it, and never the two
// mixed. The page reads one weekSnapshot() and the saved review rows; it does
// not judge the week a second time to answer a question the snapshot already
// answers.

/**
 * The partition every count on these pages is read in: study blocks on their
 * own, movement on its own, the Friday review block on its own. The same rule
 * the store applies when it builds `counts_by_tracking` — "23 of 27" puts a
 * walk and a maths block in one fraction and the fraction stops meaning
 * anything to whoever is reading it.
 */
function week_partition(array $b): string
{
    if (($b['tracking'] ?? '') === 'evidence') {
        return 'evidence';
    }
    return ($b['kind'] ?? '') === 'review' ? 'review' : 'self_report';
}

/**
 * The Friday review block in one word. It is self-reported, so it is never
 * missed: it was ticked, it is still to come, or the week ran without it.
 *
 * @param array<string,int> $c the review partition of counts_by_tracking
 */
function week_review_block_word(array $c): string
{
    if ((int) ($c['done'] ?? 0) > 0) {
        return 'ticked';
    }
    if (($c['upcoming'] ?? 0) + ($c['pending'] ?? 0) + ($c['now'] ?? 0) > 0) {
        return 'pending';
    }
    return 'not ticked';
}

/**
 * `counts_by_tracking` for a caller holding judgeWeek()['days'] instead of a
 * snapshot — the term ledger, which judges twenty-six weeks and cannot afford
 * a snapshot for each. The week page reads the snapshot's own copy and never
 * recomputes it.
 *
 * @return array<string,array<string,int>>
 */
function week_counts(array $days): array
{
    $blank = ['done' => 0, 'short' => 0, 'missed' => 0, 'excused' => 0, 'day_off' => 0,
              'now' => 0, 'pending' => 0, 'upcoming' => 0, 'optional' => 0,
              'declared' => 0, 'judged' => 0, 'shape_unmet' => 0];
    $out = ['evidence' => $blank, 'self_report' => $blank, 'review' => $blank];
    foreach ($days as $day) {
        foreach ($day['blocks'] as $b) {
            if ($b['status'] === 'n/a') {
                continue;
            }
            $part = week_partition($b);
            $out[$part]['judged']++;
            $out[$part][$b['status']] = ($out[$part][$b['status']] ?? 0) + 1;
            if (!empty($b['short'])) {
                $out[$part]['short']++;
            }
            if (($b['shape'] ?? null) === 'unmet') {
                $out[$part]['shape_unmet']++;
            }
        }
    }
    return $out;
}

/** judgeWeek()'s days as one ordered list of judged blocks, each with its date. */
function week_blocks(array $days): array
{
    $out = [];
    foreach ($days as $day) {
        foreach ($day['blocks'] as $b) {
            if ($b['status'] === 'n/a') {
                continue;
            }
            $b['date']    = $day['date'];
            $b['weekday'] = $day['weekday'];
            $out[] = $b;
        }
    }
    return $out;
}

/** '9–15 Sep 2024', or '31 Aug – 6 Sep 2024' when the week straddles a month. */
function week_span(string $monday, string $sunday): string
{
    $a = new DateTimeImmutable($monday, tt_zone());
    $b = new DateTimeImmutable($sunday, tt_zone());
    return $a->format($a->format('M') === $b->format('M') ? 'j' : 'j M') . '–'
        . $b->format('j M Y');
}

/** 'Tue 10 Sep', for a line naming a block. */
function week_day_label(string $date): string
{
    return (new DateTimeImmutable($date, tt_zone()))->format('D j M');
}

/** The counts said in words, for the bar's aria-label. Colour is never the cue. */
function week_count_words(array $c): string
{
    $plainDone = max(0, ($c['done'] ?? 0) - ($c['short'] ?? 0));
    $bits = [];
    foreach ([
        [$plainDone, 'done'], [$c['short'] ?? 0, 'done but short'],
        // Inside the done count, not instead of it: said after it so the
        // fraction stays what it was and the shape is still in words.
        [$c['shape_unmet'] ?? 0, 'of the done not in shape'],
        [$c['declared'] ?? 0, 'marked by hand'],
        [$c['missed'] ?? 0, 'missed'], [$c['excused'] ?? 0, 'excused'],
        [$c['optional'] ?? 0, 'optional'],
        [$c['day_off'] ?? 0, 'on a day off'], [$c['now'] ?? 0, 'happening now'],
        [$c['pending'] ?? 0, 'still to come today'], [$c['upcoming'] ?? 0, 'still to come'],
    ] as [$n, $word]) {
        if ($n > 0) {
            $bits[] = $n . ' ' . $word;
        }
    }
    return ($c['judged'] ?? 0) . ' study blocks: ' . ($bits ? implode(', ', $bits) : 'none judged');
}

/** One segment per study block, in the order the week ran. */
function week_segbar(array $blocks, array $counts): string
{
    $class = ['done' => 'd', 'missed' => 'm', 'excused' => 'x', 'declared' => 'h'];
    $word  = ['declared' => 'marked done by Dad, no work logged',
              'optional' => 'optional, nothing counted against it'];
    $out   = '<div class="segbar" role="img" aria-label="' . h(week_count_words($counts)) . '">';
    foreach ($blocks as $b) {
        $short = $b['status'] === 'done' && !empty($b['short']);
        $shape = $b['status'] === 'done' && !$short && ($b['shape'] ?? null) === 'unmet';
        $c = $short ? 's' : ($shape ? 'p' : ($class[$b['status']] ?? ''));
        $out .= '<span' . ($c === '' ? '' : ' class="' . $c . '"') . ' title="'
            . h(week_day_label($b['date']) . ' ' . $b['start'] . ' ' . $b['label'] . ' — '
                . ($short ? 'done, but short'
                    : ($shape ? 'done, but not in shape: ' . ($b['shape_reason'] ?? '')
                        : ($word[$b['status']] ?? $b['status'])))) . '"></span>';
    }
    return $out . '</div>';
}

/**
 * Eight weeks of coverage as one line. Scaled to its own series rather than to
 * 0–100, because a subject sitting at 2% would otherwise be a flat line that
 * hides a doubling; the title says the real figures.
 *
 * @param array<int,int> $series percentages, oldest first
 */
function week_spark(array $series, string $accent, string $title): string
{
    $n = count($series);
    if ($n < 2) {
        return '';
    }
    $min = min($series);
    $max = max($series);
    $pts = [];
    foreach ($series as $i => $v) {
        $x = 2 + ($i * (60 / ($n - 1)));
        $y = $max === $min ? 11 : 18 - (($v - $min) / ($max - $min)) * 14;
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    $last = explode(',', $pts[$n - 1]);
    return '<svg class="spark" viewBox="0 0 64 20" width="72" height="20" role="img">'
        . '<title>' . h($title) . '</title>'
        . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . h($accent)
        . '" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>'
        . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2" fill="' . h($accent) . '"/></svg>';
}

/** A topic status as a coloured dot with its name on it. */
function week_dot(?string $status): string
{
    return '<span class="dot" style="background:' . (STATUS_COLOUR[$status] ?? '#d6d3d1')
        . '" title="' . h(STATUS_LABEL[$status] ?? (string) $status) . '"></span>';
}

/**
 * The stamp: solid for a reviewed note, dashed for a draft, nothing at all
 * when the week has none. The local time it was written, because that is the
 * clock whoever wrote it was looking at.
 */
function week_stamp_badge(?array $review): string
{
    if ($review === null) {
        return '';
    }
    [$on, $at]  = tt_local((string) $review['written_at']);
    $when       = (new DateTimeImmutable($on, tt_zone()))->format('D j M') . ' ' . $at;
    $byRoutine  = ($review['written_by'] ?? '') === 'routine';
    $reviewed   = ($review['stage'] ?? '') === 'reviewed';
    $who        = $byRoutine ? 'Friday routine' : 'review chat';
    $head       = $reviewed ? ($byRoutine ? 'Reviewed' : 'Reviewed with Dad') : 'Draft';
    return '<p class="stamp' . ($reviewed ? '' : ' draft') . '" title="'
        . h(($reviewed ? 'Reviewed' : 'Draft') . ', version ' . $review['version'] . ', '
            . mcp_written_by((string) $review['written_by']) . ' on ' . $when) . '">'
        . h($head) . '<br>' . h($when) . '<br>' . h($who) . '</p>';
}

/**
 * Everything below the register: what was missed, what is waiting on Dad, the
 * hours against the timetable, the five subject cards, and the margin note.
 * Split out from the page so the golden snapshot can pin the report without
 * the register, which has a golden of its own shape already.
 */
function week_report_sections(
    Store $store,
    array $snap,
    ?array $review,
    array $versions,
    string $iso
): string {
    $sections = is_array($review['sections'] ?? null) ? $review['sections'] : [];
    $subjects = $store->listSubjects();
    $names    = tt_subject_names($store);
    $hasBoard = $snap['timetable_version_id'] !== null;
    $out      = '';

    if ($hasBoard) {
        $out .= week_missed_section($snap);
        $out .= week_decisions_section($snap, $review, $sections);
        $out .= week_hours_section($snap, $names);
    }
    $out .= week_subject_section($store, $snap, $sections, $subjects, $review);
    $out .= week_margin_section($store, $snap, $review, $versions, $iso);
    return $out;
}

/**
 * Missed, named plainly: day, time, label, and what was absent.
 *
 * Only a study block can be missed. A movement block that was not ticked is
 * optional and belongs nowhere on this list, and a block the parent marked
 * done by hand is accounted for under its own heading below — it is his word
 * rather than the record's, but it is not a miss.
 */
function week_missed_section(array $snap): string
{
    $missed = $short = $hand = $shape = [];
    foreach ($snap['blocks'] as $b) {
        if ($b['status'] === 'missed') {
            $missed[] = $b;
        } elseif ($b['status'] === 'declared') {
            $hand[] = $b;
        } elseif ($b['status'] === 'done' && !empty($b['short'])) {
            $short[] = $b;
        }
        if ($b['status'] === 'done' && ($b['shape'] ?? null) === 'unmet') {
            $shape[] = $b;
        }
    }
    $when = static fn(array $b): string => '<span class="when">' . h(week_day_label($b['date']))
        . ' · ' . h($b['start']) . '–' . h($b['end']) . '</span><br>';

    $out = '<h2 class="panel-title">Missed</h2>';
    if (!$missed) {
        $out .= '<p><small>Nothing was missed this week.</small></p>';
    } else {
        $out .= '<ul class="named">';
        foreach ($missed as $b) {
            $out .= '<li>' . $when($b) . '<b>' . h($b['label']) . '</b> — '
                . h(mcp_absent($b)) . ' that day.</li>';
        }
        $out .= '</ul>';
    }
    if ($hand) {
        $out .= '<p class="sublab">Marked by hand</p><ul class="named handlist">';
        foreach ($hand as $b) {
            $out .= '<li>' . $when($b) . '<b>' . h($b['label'])
                . '</b> — marked done by Dad, no work was logged'
                . ($b['reason'] ? ': ' . h((string) $b['reason']) : '') . '.</li>';
        }
        $out .= '</ul>';
    }
    if ($short) {
        $out .= '<p class="sublab">Done, but short</p><ul class="named shortlist">';
        foreach ($short as $b) {
            $out .= '<li>' . $when($b) . '<b>' . h($b['label']) . '</b> — '
                . (int) $b['minutes'] . ' minutes of ' . (int) $b['length'] . ' logged.</li>';
        }
        $out .= '</ul>';
    }
    if ($shape) {
        // Done, and counted as done. Listed so the shape is visible: the
        // reason describes the work the block asked for and the work that
        // was logged, and nothing else.
        $out .= '<p class="sublab">Done, but not the shape the block asked for</p><ul class="named shapelist">';
        foreach ($shape as $b) {
            $out .= '<li>' . $when($b) . '<b>' . h($b['label']) . '</b> — '
                . h((string) ($b['shape_reason'] ?? '')) . '.</li>';
        }
        $out .= '</ul>';
    }
    return $out . '<p class="cap">A missed block means nothing was logged against it, which is '
        . 'sometimes a logging failure rather than a missed morning. Movement and the review '
        . 'block are never listed here: they are ticked or they are not, and not ticking one '
        . 'costs nothing. A block marked by hand is Dad\'s word that it happened, counted apart '
        . 'from the blocks the record proves. An extra never offsets a miss; it is counted '
        . 'beside it.</p>';
}

/**
 * What is waiting on Dad, or — once the week has been reviewed — what he
 * decided. Read-only either way: the page has no control that writes.
 */
function week_decisions_section(array $snap, ?array $review, array $sections): string
{
    $reviewed  = ($review['stage'] ?? '') === 'reviewed';
    $decisions = is_array($sections['decisions'] ?? null) ? $sections['decisions'] : [];
    $out       = '<h2 class="panel-title">Decisions for Dad</h2><ul class="decide">';
    $rows      = 0;

    if ($reviewed && $decisions) {
        $byRef = [];
        foreach ($snap['blocks'] as $b) {
            $byRef[$b['date'] . '#' . $b['block_key']] = $b;
        }
        $offs = [];
        foreach ($snap['days_off'] as $d) {
            $offs[(string) $d['id']] = $d;
        }
        $said = ['approved' => 'approved', 'declined' => 'declined', 'excused' => 'excused',
                 'not_excused' => 'not excused', 'deferred' => 'still open'];
        foreach ($decisions as $d) {
            $ref   = (string) ($d['ref'] ?? '');
            $what  = $ref;
            if (($d['kind'] ?? '') === 'day_off' && isset($offs[$ref])) {
                $o    = $offs[$ref];
                $span = $o['date_from'] === $o['date_to']
                    ? tt_pretty($o['date_from'])
                    : tt_pretty($o['date_from']) . ' to ' . tt_pretty($o['date_to']);
                $what = 'Day off <b>' . h($span) . '</b> — asked by ' . h($o['requested_by'])
                    . ', &ldquo;' . h($o['reason']) . '&rdquo;';
            } elseif (isset($byRef[$ref])) {
                $b    = $byRef[$ref];
                $what = h(week_day_label($b['date']) . ' ' . $b['start'] . ' ' . $b['label'])
                    . ' — excuse it?';
            } else {
                $what = h($ref);
            }
            $verdict = $said[$d['decision'] ?? ''] ?? (string) ($d['decision'] ?? '');
            $bad     = in_array($d['decision'] ?? '', ['declined', 'not_excused'], true);
            $out    .= '<li class="settled"><span>' . $what . '</span><span class="said'
                . ($bad ? ' no' : '') . '">' . h($verdict)
                . (isset($d['note']) && $d['note'] !== null && $d['note'] !== ''
                    ? ' — ' . h((string) $d['note']) : '') . '</span></li>';
            $rows++;
        }
    } else {
        foreach ($snap['days_off'] as $d) {
            if ($d['status'] !== 'requested') {
                continue;
            }
            $span = $d['date_from'] === $d['date_to']
                ? tt_pretty($d['date_from'])
                : tt_pretty($d['date_from']) . ' to ' . tt_pretty($d['date_to']);
            $out .= '<li><span>Day off <b>' . h($span) . '</b> — asked by ' . h($d['requested_by'])
                . ', &ldquo;' . h($d['reason']) . '&rdquo;</span>'
                . '<span class="ask">approve? — in the review chat</span></li>';
            $rows++;
        }
        foreach ($snap['blocks'] as $b) {
            if ($b['status'] !== 'missed') {
                continue;
            }
            $out .= '<li><span>' . h(week_day_label($b['date']) . ' ' . $b['start'] . ' '
                    . $b['label']) . '</span>'
                . '<span class="ask">excuse? — say so in the review chat</span></li>';
            $rows++;
        }
    }
    if ($rows === 0) {
        $out .= '<li><span>Nothing is waiting on a decision.</span></li>';
    }
    return $out . '</ul><p class="cap">Decided in the review chat, never here. This page has no '
        . 'button that changes the record — <code>tracker_excuse_block</code> and '
        . '<code>tracker_decide_day_off</code> are Dad\'s, in chat.</p>';
}

/** Hours logged against the hours the timetable planned. Never a percentage of one. */
function week_hours_section(array $snap, array $names): string
{
    $slugs = array_keys($snap['planned_by_subject'] + $snap['hours_by_subject']);
    sort($slugs);
    $out = '<h2 class="panel-title">Hours against the timetable</h2>';
    if (!$slugs) {
        $out .= '<p><small>Nothing planned and nothing logged.</small></p>';
    }
    foreach ($slugs as $slug) {
        $done    = (float) ($snap['hours_by_subject'][$slug] ?? 0);
        $planned = (float) ($snap['planned_by_subject'][$slug] ?? 0);
        $under   = $planned > 0 && $done + 0.005 < $planned;
        $width   = $planned > 0 ? min(100, (int) round($done / $planned * 100)) : ($done > 0 ? 100 : 0);
        $out    .= '<div class="hrow"><span class="nm">' . h($names[$slug] ?? $slug) . '</span>'
            . '<span class="hbar" role="img" aria-label="' . h(($names[$slug] ?? $slug) . ': '
                . number_format($done, 2) . ' hours logged of ' . number_format($planned, 2)
                . ' planned' . ($under ? ', under the timetable' : ', at or above the timetable'))
            . '"><i' . ($under ? ' class="under"' : '') . ' style="width:' . $width . '%"></i></span>'
            . '<span class="num mono">' . number_format($done, 2) . ' / '
            . number_format($planned, 2) . '</span></div>';
    }
    return $out . '<p class="cap">Planned is the timetable in force this week, single-subject '
        . 'blocks only; the mixed retrieval blocks and the timed rotation belong to no one '
        . 'subject and are in neither figure. A short block counts the minutes logged.</p>';
}

/**
 * One card per subject: coverage and its eight-week line, what moved with the
 * status either side of it, anything sat, the practice, the top of the queue,
 * and the one line the review carried forward for that subject.
 */
function week_subject_section(
    Store $store,
    array $snap,
    array $sections,
    array $subjects,
    ?array $review
): string {
    if (!$subjects) {
        return '';
    }
    $monday  = (string) $snap['monday'];
    $sunday  = tt_add_days($monday, 6);
    $carry   = is_array($sections['carry_forward'] ?? null) ? $sections['carry_forward'] : [];
    $signed  = '';
    if ($review !== null) {
        [$on, $at] = tt_local((string) $review['written_at']);
        $signed = '— Claude, ' . (new DateTimeImmutable($on, tt_zone()))->format('D j M') . ' ' . $at;
    }

    $out = '<h2 class="panel-title">By subject</h2><div class="subjgrid">';
    foreach ($subjects as $s) {
        $slug   = $s['slug'];
        $cov    = $snap['coverage'][$slug] ?? ['pct_start' => 0, 'pct_end' => 0, 'topics' => 0];
        $delta  = (int) $cov['pct_end'] - (int) $cov['pct_start'];
        $accent = tt_accent([$slug]);

        $out .= '<article class="subj"><div class="sh"><div>'
            . '<h4><a href="/s/' . h($slug) . '">' . h($s['name']) . '</a></h4>'
            . '<p class="sspec">' . h(trim(($s['spec_code'] ?? '') . ' ' . ($s['tier'] ?? '')))
            . ' · ' . (int) $cov['topics'] . ' topics</p></div>'
            . '<div class="cov"><span class="pc mono">' . (int) $cov['pct_end'] . '%</span>'
            . '<span class="delta mono' . ($delta > 0 ? '' : ($delta < 0 ? ' down' : ' flat')) . '">'
            . ($delta >= 0 ? '+' : '') . $delta . '</span>'
            . week_coverage_spark($store, $slug, $s['name'], $monday, $sunday, $cov, $accent)
            . '</div></div>';

        // What moved, with the status either side of it.
        $moved = array_values(array_filter(
            $snap['changes'], static fn($c) => $c['subject_slug'] === $slug
        ));
        $out .= '<p class="lab">Moved this week</p><div class="chips">';
        if (!$moved) {
            $out .= '<span class="chip">No topic movement this week</span>';
        }
        foreach (array_slice($moved, 0, 6) as $c) {
            $from = $c['from_status'];
            $to   = $c['to_status'];
            $out .= '<span class="chip"><b class="ref">' . h($c['ref']) . '</b> '
                . h($c['topic_name']) . ' ' . week_dot($from)
                . ($from === $to ? ' <span class="arrow">evidence only</span> '
                    : ' <span class="arrow">&rarr;</span> ' . week_dot($to) . ' ')
                . h((STATUS_LABEL[$from] ?? $from) . ($from === $to
                    ? '' : ' → ' . (STATUS_LABEL[$to] ?? $to))) . '</span>';
        }
        $out .= '</div><dl class="kv">';

        $sat = array_values(array_filter(
            $snap['attempts'], static fn($a) => $a['subject_slug'] === $slug
        ));
        $out .= '<dt>Sat this week</dt><dd>';
        if (!$sat) {
            $out .= 'None.';
        }
        foreach ($sat as $a) {
            $out .= h($a['name']) . ' — ' . (float) $a['score'] . '/' . (float) $a['max']
                . ($a['blanks'] === null ? '' : ', ' . (int) $a['blanks'] . ' blank'
                    . ((int) $a['blanks'] === 1 ? '' : 's'))
                . ' (' . h(tt_pretty($a['date'])) . '). ';
        }
        $out .= '</dd>';

        $p = $snap['practice'][$slug] ?? null;
        if ($p) {
            $out .= '<dt>Practice</dt><dd>' . (int) $p['runs'] . ' run'
                . ((int) $p['runs'] === 1 ? '' : 's') . ' · ' . (int) $p['attempted'] . ' items'
                . ($p['first_time_pct'] === null ? '' : ' · ' . $p['first_time_pct']
                    . '% right first time') . ' · best run ' . (int) $p['best_score'] . '.</dd>';
        }
        $q = $snap['queue_top'][$slug] ?? null;
        $out .= '<dt>Queue top</dt><dd>' . ($q ? h($q['line']) : 'Nothing waiting.') . '</dd>';
        $u = $snap['unfinished'][$slug] ?? null;
        if ($u && ($u['opened'] || $u['closed'] || $u['open_now'])) {
            $out .= '<dt>Unfinished</dt><dd>' . count($u['opened']) . ' opened, ' . count($u['closed'])
                . ' closed, ' . count($u['open_now']) . ' open now.';
            foreach ($u['open_now'] as $item) {
                $out .= '<br><span class="unfin' . ($item['stale'] ? ' stale' : '') . '">open</span> '
                    . h($item['text']) . ' <small>(session ' . (int) $item['session_id'] . ', '
                    . (int) $item['days_open'] . ' days)</small>';
            }
            $out .= '</dd>';
        }
        $out .= '</dl>';

        $line = $carry[$slug] ?? null;
        if (is_string($line) && $line !== '') {
            $out .= '<p class="hand">' . h($line)
                . ($signed === '' ? '' : '<span class="sig">' . h($signed) . '</span>') . '</p>';
        }
        $out .= '</article>';
    }
    return $out . '</div><p class="cap">Coverage is weighted by status and measured against '
        . 'today\'s syllabus, so an older week reads against the topics that exist now. The line '
        . 'is the eight weeks ending with this one, scaled to its own range.</p>';
}

/**
 * The eight-week coverage line, replayed from the topic changes rather than
 * stored: points as they stand, less every move made after each week's end.
 * The last point is the card's own figure, by construction.
 */
function week_coverage_spark(
    Store $store,
    string $slug,
    string $name,
    string $monday,
    string $sunday,
    array $cov,
    string $accent
): string {
    $topics = $store->listTopics($slug);
    $max    = count($topics) * 3;
    if ($max === 0) {
        return '';
    }
    $points = 0;
    foreach ($topics as $t) {
        $points += STATUS_POINTS[$t['status']] ?? 0;
    }
    $until   = max(tt_today(), $sunday);
    $changes = $store->changesBetween(tt_add_days($monday, -49), $until, $slug);
    $series  = [];
    for ($i = 7; $i >= 0; $i--) {
        $end   = tt_add_days($sunday, -7 * $i) . ' 23:59:59';
        $after = 0;
        foreach ($changes as $c) {
            if ((string) $c['changed_at'] > $end) {
                $after += (STATUS_POINTS[$c['to_status']] ?? 0)
                    - (STATUS_POINTS[$c['from_status'] ?? ''] ?? 0);
            }
        }
        $series[] = (int) round((($points - $after) / $max) * 100);
    }
    return week_spark($series, $accent, $name . ' coverage over eight weeks, '
        . $series[0] . '% to ' . $series[7] . '%');
}

/** The margin: the written half, its versions, and how far the record has moved since. */
function week_margin_section(
    Store $store,
    array $snap,
    ?array $review,
    array $versions,
    string $iso
): string {
    $out = '<h2 class="panel-title">In the margin — the week\'s review</h2>';
    if ($review === null) {
        return $out . '<div class="marginbox"><div class="gutter"><span class="who">Written, not '
            . 'computed</span><p>Nothing yet.</p></div><div class="mbody"><p class="hand">No note '
            . 'has been written for this week.</p><p class="cap">The Friday routine writes the '
            . 'draft with <code>tracker_save_weekly_review</code>; the review chat saves the '
            . 'reviewed version after it.</p></div></div>';
    }

    $current = (int) $review['version'];
    $out    .= '<ul class="vers">';
    foreach ($versions as $v) {
        $label = $v['stage'] . ' ' . tt_local((string) $v['written_at'])[1];
        $title = 'Version ' . $v['version'] . ', ' . mcp_written_by((string) $v['written_by']);
        $out  .= '<li>' . ((int) $v['version'] === $current
            ? '<span aria-current="true" title="' . h($title) . '">' . h($label) . '</span>'
            : '<a href="/week/' . h($iso) . '?v=' . (int) $v['version'] . '" title="' . h($title)
                . '">' . h($label) . '</a>') . '</li>';
    }
    $out .= '</ul>';

    [$on, $at] = tt_local((string) $review['written_at']);
    $when      = (new DateTimeImmutable($on, tt_zone()))->format('l j F') . ' at ' . $at;
    $sections  = is_array($review['sections'] ?? null) ? $review['sections'] : [];
    $drift     = $store->weekDrift(is_array($review['snapshot'] ?? null) ? $review['snapshot'] : $snap);

    $out .= '<div class="marginbox"><div class="gutter"><span class="who">Written, not computed</span>'
        . '<p>One note per week, append-only. Version ' . $current . ' of ' . count($versions)
        . ', ' . h(mcp_written_by((string) $review['written_by'])) . ' on ' . h($when) . '.</p>'
        . ($review['note'] === null || $review['note'] === ''
            ? '' : '<p>' . h((string) $review['note']) . '</p>')
        . '</div><div class="mbody">';
    foreach (['held' => 'Held', 'slipped' => 'Slipped', 'next' => 'Next week'] as $key => $head) {
        $text = (string) ($sections[$key] ?? '');
        if ($text === '') {
            continue;
        }
        $out .= '<h4>' . h($head) . '</h4><p class="hand">' . h($text) . '</p>';
    }
    if (isset($sections['rotation_next']) && $sections['rotation_next'] !== '') {
        $out .= '<h4>Rotation next</h4><p class="hand">' . h((string) $sections['rotation_next'])
            . '</p>';
    }
    $out .= '<p class="driftline">' . h($drift['line']) . '</p></div></div>';
    return $out;
}

/**
 * /week/{iso} — one week as the parent reads it. Everything above the margin
 * comes from one weekSnapshot(); the register is the same component the index
 * page draws, called rather than copied.
 */
function render_week_page(
    Store $store,
    string $iso,
    bool $isParent = false,
    ?int $version = null,
    ?int $synthVersion = null
): string
{
    $monday = tt_week_monday($iso);
    if ($monday === null) {
        // The route sends this with a 404: an impossible week falls back to
        // the ledger rather than to a dead end.
        return render_weeks($store);
    }
    $isThisWeek = $monday === tt_monday(tt_today());
    $snap       = $store->weekSnapshot($monday);
    $versions   = $store->weeklyReviewVersions($iso);
    $selfPath   = '/week/' . rawurlencode($iso);

    $review = $store->weeklyReview($iso, $version);
    if ($review === null && $version !== null) {
        $review = $store->weeklyReview($iso);
    }

    // Header. Prev and next are ±7 days through the ISO label; next is dead
    // rather than absent when the week has not happened yet.
    $prev   = tt_iso_week(tt_add_days($monday, -7));
    $next   = tt_iso_week(tt_add_days($monday, 7));
    $friday = tt_add_days($monday, 4);
    $m      = new DateTimeImmutable($monday, tt_zone());
    $f      = new DateTimeImmutable($friday, tt_zone());
    $kicker = 'Week ' . (int) substr($iso, 6) . ' · '
        . $m->format($m->format('M') === $f->format('M') ? 'D j' : 'D j M')
        . ' – ' . $f->format('D j M Y');
    $nav = '<a href="/week/' . h($prev) . '">&larr; ' . h($prev) . '</a> · '
        . '<a href="/weeks">all weeks</a> · '
        . (tt_add_days($monday, 7) > tt_today()
            ? '<span class="off">' . h($next) . ' &rarr;</span>'
            : '<a href="/week/' . h($next) . '">' . h($next) . ' &rarr;</a>');

    $body = '<header class="wkhead"><div><p class="kicker">' . h($kicker) . '</p>'
        . '<h1>The week</h1><p class="weeknav mono">' . $nav . '</p></div>'
        . '<div>' . week_stamp_badge($review)
        . tt_parent_line($store, $isParent, $selfPath) . '</div></header>';

    // The register is the component the index page draws, called rather than
    // copied, so the parent's controls live there and only there. Everything
    // this page adds below it reads the record and writes nothing to it.
    $register = render_timetable_section(
        $store, $monday, $isThisWeek, $isParent, $selfPath
    );
    if ($register === '') {
        $body .= '<p><small>No timetable was in force that week.</small></p>';
    } else {
        $body .= week_headline_cards($snap, tt_subject_names($store))
            . '<h2 class="panel-title">The register</h2>' . $register;
    }

    $body .= week_report_sections($store, $snap, $review, $versions, $iso);
    // The week's readiness calls and signal movement, beside the weekly
    // review: parent only, like the reviews themselves.
    if ($isParent) {
        $body .= rv_week_section($store, $monday);
        // The learning half of the week, beneath the adherence half.
        $body .= sy_week_section($store, $iso, $synthVersion);
    }

    $links = ['<a href="/weeks">All weeks</a>'];
    foreach ($store->listSubjects() as $s) {
        $links[] = '<a href="/s/' . h($s['slug']) . '">/s/' . h($s['slug']) . '</a>';
    }
    $body .= '<footer class="wkfoot">' . implode(' · ', $links) . '<br>'
        . h('Every figure above the margin is computed at request time, ' . tt_stamp(null, true)
            . '. The note carries its own timestamp.') . '</footer>';

    return dash_shell('Week ' . $iso, $body, DASH_HAND_FONT);
}

/** The four headline cards: study blocks, hours, timed handwritten, movement. */
function week_headline_cards(array $snap, array $names = []): string
{
    $ev   = $snap['counts_by_tracking']['evidence'];
    $self = $snap['counts_by_tracking']['self_report'];
    $rev  = $snap['counts_by_tracking']['review'];
    $blocks = array_values(array_filter(
        $snap['blocks'], static fn($b) => week_partition($b) === 'evidence'
    ));

    $sub1 = [];
    // "Marked by hand" is named beside the fraction, never inside it: the big
    // figure is what the record can prove, and Dad's word is accounted for
    // next to it in his own words.
    foreach (['missed' => 'missed', 'short' => 'short',
              'declared' => 'marked by hand', 'excused' => 'excused'] as $k => $w) {
        if (($ev[$k] ?? 0) > 0) {
            $sub1[] = $ev[$k] . ' ' . $w;
        }
    }
    if (count($snap['extras'])) {
        $sub1[] = count($snap['extras']) . ' extra';
    }
    $out = '<div class="mstats"><div class="card"><p class="label">Study blocks</p>'
        . '<p class="big mono">' . (int) $ev['done'] . ' of ' . (int) $ev['judged'] . '</p>'
        . week_segbar($blocks, $ev)
        . '<p class="sub mono">' . h($sub1 ? implode(' · ', $sub1) : 'nothing missed') . '</p>'
        . '<p class="sub mono">movement ticked ' . (int) $self['done'] . ' of '
        . (int) $self['judged']
        . ' · review block ' . h(week_review_block_word($rev)) . '</p></div>';

    $done = array_sum($snap['hours_by_subject']);
    $plan = array_sum($snap['planned_by_subject']);
    $under = [];
    foreach ($snap['planned_by_subject'] as $slug => $p) {
        if ((float) ($snap['hours_by_subject'][$slug] ?? 0) + 0.005 < (float) $p) {
            $under[] = $names[$slug] ?? $slug;
        }
    }
    if (count($under) > 2) {
        $under = [count($under) . ' subjects'];
    }
    $out .= '<div class="card"><p class="label">Hours</p><p class="big mono">'
        . number_format($done, 1) . ' of ' . number_format($plan, 1) . '</p>'
        . '<div class="minibar" role="img" aria-label="' . h(number_format($done, 2)
            . ' hours logged of the ' . number_format($plan, 2) . ' the timetable plans')
        . '"><i' . ($done + 0.005 < $plan ? ' class="under"' : '') . ' style="width:'
        . ($plan > 0 ? min(100, (int) round($done / $plan * 100)) : 0) . '%"></i></div>'
        . '<p class="sub mono">' . h($under
            ? implode(', ', $under) . ' under the timetable'
            : 'every subject on or above its planned hours') . '</p></div>';

    $timed = $snap['timed'];
    $out  .= '<div class="card"><p class="label">Timed handwritten</p>';
    if ($timed['this_week']) {
        $bits = [];
        foreach ($timed['this_week'] as $t) {
            $bits[] = $t['label'] . ' — ' . $t['minutes'] . ' min'
                . ($t['measured'] ? ' recorded' : ' (the block length, not a measured sitting)')
                . ($t['blanks'] === null ? '' : ' · ' . $t['blanks'] . ' blanks');
        }
        $out .= '<p class="big mono">' . count($timed['this_week']) . ' this week</p>'
            . '<p class="sub mono">' . h(implode(' · ', $bits)) . '</p>';
    } else {
        $l = $timed['last'];
        $out .= '<p class="big">None this week</p><p class="sub mono">' . h($l
            ? 'last: ' . $l['minutes'] . ' min'
                . ($l['measured'] ? '' : ' (the block length)')
                . ($l['blanks'] === null ? '' : ' · ' . $l['blanks'] . ' blanks')
                . ' · ' . tt_pretty($l['date'])
            : 'none in the eight weeks before it either, so stamina has no new reading')
            . '</p>';
    }
    $out .= '</div>';

    $up = $down = $flat = 0;
    foreach ($snap['changes'] as $c) {
        $d = (STATUS_POINTS[$c['to_status']] ?? 0) - (STATUS_POINTS[$c['from_status'] ?? ''] ?? 0);
        $d > 0 ? $up++ : ($d < 0 ? $down++ : $flat++);
    }
    $out .= '<div class="card"><p class="label">Topics moved</p>'
        . '<p class="big mono" role="img" aria-label="' . $up . ' topics promoted, ' . $down
        . ' demoted">' . $up . ' &uarr; · ' . $down . ' &darr;</p>'
        . '<p class="sub mono">' . h($flat === 0
            ? 'no evidence-only entries'
            : 'evidence only on ' . $flat . ' more') . '</p></div></div>';
    return $out;
}

/**
 * /weeks — the term as a ledger. One row per ISO week, newest first, one
 * judgeWeek() per row and one pass over the topic changes for the whole span,
 * rather than a query per week per subject.
 */
function render_weeks(Store $store, array $query = []): string
{
    $all  = $store->weeksWithActivity(1000);
    $from = isset($query['from']) ? tt_week_monday((string) $query['from']) : null;
    $skip = 0;
    if ($from !== null) {
        foreach ($all as $i => $w) {
            if ($w['monday'] === $from) {
                $skip = $i;
                break;
            }
        }
    }
    $rows   = array_slice($all, $skip, 26);
    $older  = count($all) > $skip + count($rows) ? $all[$skip + count($rows)]['week'] : null;
    $today  = tt_monday(tt_today());
    $subjects = $store->listSubjects();
    $reviews  = $store->latestWeeklyReviews(array_column($rows, 'week'));

    // Coverage, replayed once over the whole span rather than per week per
    // subject: points as they stand now, less every move made since.
    $span    = $rows ? $rows[count($rows) - 1]['monday'] : $today;
    $changes = $store->changesBetween($span, max(tt_today(), $today), null);
    $points  = [];
    $maxPts  = [];
    foreach ($subjects as $s) {
        $topics = $store->listTopics($s['slug']);
        $p      = 0;
        foreach ($topics as $t) {
            $p += STATUS_POINTS[$t['status']] ?? 0;
        }
        $points[$s['slug']] = $p;
        $maxPts[$s['slug']] = count($topics) * 3;
    }
    $pctAt = static function (string $slug, string $cutoff) use ($changes, $points, $maxPts): int {
        if (($maxPts[$slug] ?? 0) === 0) {
            return 0;
        }
        $after = 0;
        foreach ($changes as $c) {
            if ($c['subject_slug'] === $slug && (string) $c['changed_at'] > $cutoff) {
                $after += (STATUS_POINTS[$c['to_status']] ?? 0)
                    - (STATUS_POINTS[$c['from_status'] ?? ''] ?? 0);
            }
        }
        return (int) round((($points[$slug] - $after) / $maxPts[$slug]) * 100);
    };

    $head = '<tr><th scope="col">Week</th><th scope="col">Study blocks</th>'
        . '<th scope="col">Missed</th><th scope="col">Hours</th><th scope="col">Timed</th>'
        . '<th scope="col">Moved</th><th scope="col" aria-label="Coverage, one figure per subject '
        . 'in the order listed under the table">Coverage</th><th scope="col">Review</th></tr>';

    $bodyRows = '';
    foreach ($rows as $r) {
        $monday = $r['monday'];
        $sunday = tt_add_days($monday, 6);
        $w      = $store->judgeWeek($monday);
        $counts = week_counts($w['days']);
        $ev     = $counts['evidence'];
        $board  = $store->timetableVersionOn(tt_add_days($monday, 4)) !== null;
        $blocks = array_values(array_filter(
            week_blocks($w['days']), static fn($b) => week_partition($b) === 'evidence'
        ));
        $planned = array_sum($store->plannedBySubject($monday));
        $doneHrs = array_sum($w['hours_by_subject']);

        $cells = '<th scope="row" class="wkid"><a href="/week/' . h($r['week']) . '">'
            . h($r['week']) . '</a><br><small>' . h(week_span($monday, $sunday))
            . '</small></th>';

        if ($board) {
            $extras = 0;
            foreach ($w['days'] as $d) {
                $extras += count($d['extras']);
            }
            $cells .= '<td>' . week_segbar($blocks, $ev) . '<span class="mono">' . (int) $ev['done']
                . '/' . (int) $ev['judged'] . '</span>'
                . ((int) $ev['declared'] > 0
                    ? ' <small>' . (int) $ev['declared'] . ' by hand</small>' : '')
                . ($extras ? ' <small>' . $extras . ' extra</small>' : '') . '</td>'
                . '<td class="mono">' . (int) $ev['missed']
                . ((int) $ev['excused'] > 0 ? '<br><small>' . (int) $ev['excused'] . ' excused</small>' : '')
                . '</td><td class="mono">' . number_format($doneHrs, 1) . ' / '
                . number_format($planned, 1) . '</td>';
        } else {
            $cells .= '<td class="quiet">— no timetable yet</td><td class="quiet">—</td>'
                . '<td class="mono">' . number_format($doneHrs, 1) . ' / —</td>';
        }

        // Timed: the pieces by name. Minutes are not printed here, because the
        // block length is not a measured sitting and the week page is where
        // the measured figure lives.
        $timed = array_values(array_filter(
            week_blocks($w['days']),
            static fn($b) => $b['kind'] === 'timed_handwritten' && $b['status'] === 'done'
        ));
        $cells .= '<td class="mono">' . ($timed
            ? count($timed) . ' piece' . (count($timed) === 1 ? '' : 's')
                . '<br><small>' . h($timed[0]['label']) . '</small>'
            : '—') . '</td>';

        $up = $down = 0;
        foreach ($changes as $c) {
            $on = substr((string) $c['changed_at'], 0, 10);
            if ($on < $monday || $on > $sunday) {
                continue;
            }
            $d = (STATUS_POINTS[$c['to_status']] ?? 0) - (STATUS_POINTS[$c['from_status'] ?? ''] ?? 0);
            $d > 0 ? $up++ : ($d < 0 ? $down++ : null);
        }
        $cells .= '<td class="mono">&uarr;' . $up . ' &darr;' . $down . '</td>';

        $nums = [];
        foreach ($subjects as $s) {
            $end   = $pctAt($s['slug'], $sunday . ' 23:59:59');
            $start = $pctAt($s['slug'], tt_add_days($monday, -1) . ' 23:59:59');
            $nums[] = '<span title="' . h($s['name'] . ' ' . $end . '%, '
                . ($end - $start >= 0 ? '+' : '') . ($end - $start) . ' this week') . '">'
                . $end . '</span>';
        }
        $cells .= '<td class="mono">' . implode(' · ', $nums) . '</td>';

        $rev = $reviews[$r['week']] ?? null;
        if ($rev === null) {
            $cells .= '<td><span class="ministamp none">none</span></td>';
        } else {
            $drift = $store->weekDrift(is_array($rev['snapshot'] ?? null) ? $rev['snapshot'] : []);
            $cells .= '<td><a class="ministamp' . ($rev['stage'] === 'draft' ? ' draft' : '')
                . '" href="/week/' . h($r['week']) . '" title="' . h(ucfirst((string) $rev['stage'])
                    . ', version ' . $rev['version'] . ', '
                    . mcp_written_by((string) $rev['written_by'])) . '">' . h($rev['stage'])
                . '<br>' . h(tt_local((string) $rev['written_at'])[1]) . '</a>'
                . ($drift['changes']
                    ? '<span class="driftdot" role="img" aria-label="the record has moved since '
                        . 'this note was written"></span>' : '') . '</td>';
        }
        $bodyRows .= '<tr' . ($monday === $today ? ' class="now"' : '') . '>' . $cells . '</tr>';
    }

    $order = [];
    foreach ($subjects as $s) {
        $order[] = $s['name'];
    }
    $body = '<header class="wkhead"><div><p class="kicker">Study tracker</p><h1>All weeks</h1>'
        . '<p class="weeknav mono"><a href="/">&larr; subjects</a> · '
        . '<a href="/week/' . h(tt_iso_week($today)) . '">this week</a></p></div></header>'
        . '<div class="tablewrap"><table class="ledger">'
        . '<caption class="sr-only">Every ISO week on record, with study blocks, hours, timed '
        . 'work, movement, coverage and the review stamp</caption><thead>' . $head . '</thead>'
        . '<tbody>' . ($bodyRows ?: '<tr><td colspan="8">Nothing on record yet.</td></tr>')
        . '</tbody></table></div>'
        . '<p class="cap">Study blocks counts study blocks only; movement and the review block are '
        . 'shown on the week page and counted separately, and a movement block that was not '
        . 'ticked is not a miss. A block Dad marked by hand is counted apart from the ones the '
        . 'record proves, and extras never offset a miss. '
        . 'Coverage columns are, in order: ' . h(implode(' · ', $order)) . '. '
        . 'The ledger is computed on every request — the Review column is the only thing a person '
        . 'or the routine ever writes.</p>'
        . ($older === null
            ? ''
            : '<p><small><a href="/weeks?from=' . h($older) . '">Earlier weeks &rarr;</a></small></p>')
        . '<footer class="wkfoot"><a href="/">Subjects</a> · <a href="/week/'
        . h(tt_iso_week($today)) . '">This week</a></footer>';

    return dash_shell('All weeks', $body, DASH_HAND_FONT);
}

/**
 * The one line that says whether the controls are on. Signed out it is a quiet
 * link; nobody but the parent has any use for it.
 */
function tt_parent_line(Store $store, bool $isParent, string $selfPath): string
{
    if (!$isParent) {
        return '<p class="tt-signin"><small><a href="/login?next=' . h(rawurlencode($selfPath))
            . '">Sign in to edit</a></small></p>';
    }
    return '<form class="tt-signin" method="post" action="/logout">'
        . '<small>Signed in as Dad · <button type="submit">sign out</button></small></form>';
}

/** The sign-in page. Deliberately plain: it is a door, not a feature. */
function render_login(Store $store, string $password, mixed $next, bool $failed): string
{
    $n    = h(parent_safe_next($next));
    $note = $failed
        ? '<p class="flag">That is not the password. Try again.</p>'
        : '<p><small>The board is readable by anyone with the link. Signing in adds the '
          . 'controls that write to the record — marking a day off, or saying a block '
          . 'happened when the work was never logged.</small></p>';
    return dash_shell('Sign in',
        '<header><div><p class="kicker">Study tracker</p><h1>Sign in</h1></div></header>'
        . $note
        . '<form method="post" action="/login" class="filters" style="margin-top:1rem">'
        . '<input type="hidden" name="next" value="' . $n . '">'
        . '<label>Password<input type="password" name="password" autocomplete="current-password" '
        . 'autofocus style="min-width:18rem"></label>'
        . '<button type="submit">Sign in</button></form>'
        . '<p style="margin-top:1.5rem"><a href="/">Back to the board</a></p>');
}

/**
 * The controls the parent sees on the board, and nobody else does.
 *
 * They are plain forms. No JavaScript is involved in changing the record —
 * a POST, a write, a redirect — so nothing here can half-happen.
 */
function tt_day_control(array $day, string $csrf, string $next): string
{
    $off = $day['day_off'] ?? null;
    if ($off && $off['status'] !== 'declined') {
        return '<form class="tt-ctl" method="post" action="/tt/day">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
            . '<input type="hidden" name="date" value="' . h($day['date']) . '">'
            . '<input type="hidden" name="next" value="' . h($next) . '">'
            . '<input type="hidden" name="action" value="clear">'
            . '<button type="submit" title="Undo the day off">not off</button></form>';
    }
    return '<details class="tt-ctl-menu"><summary title="Mark this day off">off</summary>'
        . '<form method="post" action="/tt/day">'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="date" value="' . h($day['date']) . '">'
        . '<input type="hidden" name="next" value="' . h($next) . '">'
        . '<input type="text" name="reason" maxlength="120" placeholder="reason, optional">'
        . '<button type="submit">Mark the day off</button></form></details>';
}

/**
 * The per-block menu, hung off the status mark. Only on blocks the parent can
 * actually say something about — a break has nothing to say, and a block with
 * real evidence behind it is already answered.
 */
function tt_block_control(array $b, string $date, string $csrf, string $next, string $mark): string
{
    $hidden = '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="date" value="' . h($date) . '">'
        . '<input type="hidden" name="block_key" value="' . (int) $b['block_key'] . '">'
        . '<input type="hidden" name="next" value="' . h($next) . '">';

    $buttons = '<button type="submit" name="action" value="done">Done anyway</button>'
        . '<button type="submit" name="action" value="skip">Skipped</button>';
    if (in_array($b['status'], ['declared', 'excused'], true)) {
        $buttons .= '<button type="submit" name="action" value="clear">Clear</button>';
    }

    return '<details class="blockctl"><summary title="' . h($b['label'] . ' — mark this block')
        . '">' . $mark . '<span class="sr-only">Mark ' . h($b['label'] . ' on ' . $date)
        . '</span></summary><div class="blockctl-body">'
        . '<form method="post" action="/tt/block">' . $hidden
        . '<input type="text" name="note" maxlength="120" placeholder="note, optional">'
        . $buttons . '</form></div></details>';
}

function render_index(Store $store, bool $isParent = false): string
{
    $subjects = $store->listSubjects();
    // The timetable goes above the subjects list: what she is in now is a more
    // urgent question than how far through a syllabus she is. It also takes
    // over the page heading, because that is what the page now leads with.
    $timetable = render_timetable_section(
        $store, tt_today(), true, $isParent, '/'
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
        . '<div>' . $stamp . tt_parent_line($store, $isParent, '/') . '</div></header>' . $body
    );
}

function render_subject(Store $store, array $subject, bool $isParent = false): string
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

    // Open unfinished work leads the header: it is the one thing a session
    // must not start over the top of.
    $openUnfinished = $store->openUnfinished($subject['slug']);
    $unfinishedCard = '';
    if ($openUnfinished) {
        $stale = count(array_filter($openUnfinished, static fn($u) => $u['stale']));
        $unfinishedCard = '<div class="card"><p class="label">Unfinished work</p><p class="big mono">'
            . count($openUnfinished) . ' <span class="unfin' . ($stale ? ' stale' : '') . '">open</span></p>';
        foreach (array_slice($openUnfinished, 0, 3) as $u) {
            $unfinishedCard .= '<p><small><a href="/s/' . h($subject['slug']) . '/session/'
                . (int) $u['session_id'] . '">Session ' . (int) $u['session_id'] . '</a> · '
                . (int) $u['days_open'] . ' days: ' . h($u['text']) . '</small></p>';
        }
        $unfinishedCard .= '</div>';
    }

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
    // The week's plan for the subject, from the synthesis: parent only,
    // above the sessions it is meant to shape.
    $planCard = '';
    if ($isParent) {
        $plan = $store->weekPlanForQueue($subject['slug']);
        if ($plan !== null) {
            $planCard = sy_plan_card($plan, $store->weekSynthesisById($plan['synthesis_id']));
        }
    }

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
                    . rv_public_mark($x)
                    . dash_unfinished_badge($x)
                    . '<div><small>' . h($x['summary']) . '</small></div>'
                    . ($void ? '<div><small>Voided: ' . h($void) . '</small></div>' : '')
                    . $moved
                    . ($x['next_steps'] ? '<div><small><em>Next: ' . h($x['next_steps']) . '</em></small></div>' : '')
                    . dash_unfinished_line($x)
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
  {$unfinishedCard}
</div>

{$ageingHtml}

{$practiceHtml}

<h2>Strands</h2>{$strandRows}
<div class="legend">{$legend}</div>

<h2>Every topic</h2>{$chipGroups}

{$looseHtml}

{$resourceHtml}

<h2>Papers &amp; checks</h2>{$assessHtml}

{$planCard}<h2>Sessions, by week</h2>{$sessionHtml}

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

/**
 * The unfinished badge on a session row: OPEN (stale after three weeks),
 * or closed with the reason in the title. Nothing when the session has no
 * item. The text describes the work; it never characterises the student.
 */
function dash_unfinished_badge(array $x): string
{
    if (($x['unfinished'] ?? null) === null) {
        return '';
    }
    if (($x['unfinished_closed_at'] ?? null) === null) {
        $days = tt_days_between((string) $x['date'], tt_today());
        return ' <span class="unfin' . ($days > UNFINISHED_STALE_DAYS ? ' stale' : '') . '" title="'
            . h('Unfinished work, open ' . $days . ' days') . '">unfinished</span>';
    }
    return ' <span class="unfin closed" title="' . h('Closed: ' . ($x['unfinished_closed_reason'] ?? ''))
        . '">unfinished · closed</span>';
}

/** The unfinished item under a session row, with its closure where there is one. */
function dash_unfinished_line(array $x): string
{
    if (($x['unfinished'] ?? null) === null) {
        return '';
    }
    $refs = Store::decodeRefs($x['unfinished_refs'] ?? null);
    $line = '<div><small><b>Unfinished:</b> ' . h((string) $x['unfinished'])
        . ($refs ? ' <span class="mono">(' . h(implode(', ', $refs)) . ')</span>' : '');
    if (($x['unfinished_closed_at'] ?? null) !== null) {
        [$on] = tt_local((string) $x['unfinished_closed_at']);
        $by   = $x['unfinished_closed_by_session_id'] ?? null;
        $line .= ' — closed ' . h($on) . ($by ? ' by session ' . (int) $by : '')
            . ': ' . h((string) ($x['unfinished_closed_reason'] ?? ''));
    }
    return $line . '</small></div>';
}

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

function render_session(Store $store, array $subject, array $x, bool $isParent = false, ?int $version = null): string
{
    $slug = $subject['slug'];
    $void = $x['void_reason'] ?? null;

    // The public surface gains one boolean per session and nothing else.
    $body = detail_head($subject, 'Session ' . $x['id'],
        h((string) $x['date']) . ' · ' . h(Store::weekOf((string) $x['date'])['label'])
        . ($void ? ' · <b style="color:#b91c1c">VOID</b>' : '') . rv_public_mark($x));

    if ($void) {
        $body .= '<div class="flag">Voided: ' . h((string) $void)
            . '<br><small>The row is kept rather than deleted, and no longer counts towards'
            . ' the review queue or the export.</small></div>';
    }
    $body .= '<h2>What happened</h2><p>' . h((string) $x['summary']) . '</p>';
    if ($x['next_steps']) {
        $body .= '<h2>Planned next</h2><p>' . h((string) $x['next_steps']) . '</p>';
    }
    if (($x['unfinished'] ?? null) !== null) {
        $body .= '<h2>Unfinished' . dash_unfinished_badge($x) . '</h2><p>' . h((string) $x['unfinished'])
            . '</p>' . dash_unfinished_line($x);
    }
    $resolved = $store->sessionsClosedBy((int) $x['id']);
    if ($resolved) {
        $body .= '<h2>Resolved</h2><ul>';
        foreach ($resolved as $r) {
            $body .= '<li><a href="/s/' . h($slug) . '/session/' . (int) $r['id'] . '">Session '
                . (int) $r['id'] . '</a> (' . h((string) $r['date']) . '): ' . h((string) $r['unfinished']) . '</li>';
        }
        $body .= '</ul>';
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

    // The review itself, the drift and the signals: parent only. Everyone
    // else has already seen the tick in the header, and that is all.
    if ($isParent) {
        $versions = $store->lessonReviewVersions((int) $x['id']);
        if ($versions) {
            $review = $store->lessonReview((int) $x['id'], $version) ?? $store->lessonReview((int) $x['id']);
            $body  .= rv_review_html($store, $subject, $x, $review, $versions);
        } elseif ((int) ($x['review_required'] ?? 0) === 1 && !$void) {
            $body .= '<h2>Lesson review</h2><div class="flag">This session requires a review and has none yet. '
                . 'The audit writes it, or save one with tracker_save_lesson_review.</div>';
        }
        $body .= '<p><small><a href="/s/' . h($slug) . '/reviews">All lesson reviews for ' . h($subject['name'])
            . '</a> · <a href="/signals">Signals</a></small></p>';
    }

    return dash_shell('Session ' . $x['id'] . ' — ' . $subject['name'], $body);
}

function render_topic_history(Store $store, array $subject, array $topic, bool $isParent = false): string
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

    // The error history the lesson reviews have built: parent only.
    if ($isParent) {
        $body .= rv_topic_errors_html($store, $slug, $ref);
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
