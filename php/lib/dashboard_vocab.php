<?php
/**
 * The vocabulary app on the page: /s/<subject>/play.
 *
 * The games are the single-file React app's, ported to plain JavaScript so
 * the page needs no build step and nothing from a CDN — the same rule as the
 * class bell and the exam sitting. The look is the app's (big gradient
 * panels, a word that rises up the screen, three answers), not the
 * exercise book's, because it is her game; the steps under it are the
 * exercise book's, because they are the record.
 *
 * The page writes nothing itself. A finished game POSTs to
 * /s/<subject>/play/run, which stores it as a practice run (vocab.php), and
 * a mark-off is a parent's form post to /s/<subject>/play/mark.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const DASH_VOCAB_CSS = <<<'CSS'
/* ---- the vocabulary app ------------------------------------------------- */
.va{margin-top:1.2rem;border-radius:18px;overflow:hidden;min-height:60vh;
  background:linear-gradient(135deg,#4f46e5,#9333ea 50%,#db2777);color:#1c1917;
  font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;position:relative}
.va *{box-sizing:border-box}
.va .panel{background:#fff;border-radius:22px;box-shadow:0 20px 50px -20px rgba(0,0,0,.5);
  padding:1.6rem;margin:1.5rem auto;max-width:60rem;width:calc(100% - 2rem)}
.va h1.va-title{font-size:clamp(1.6rem,4vw,2.6rem);text-align:center;margin:0 0 .3rem;font-weight:800;
  background:linear-gradient(90deg,#9333ea,#db2777);-webkit-background-clip:text;background-clip:text;color:transparent}
.va .lede{text-align:center;color:#57534e;margin:0 0 1.2rem;font-size:1.05rem}
.va .hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:1rem;align-items:stretch;
  background:linear-gradient(90deg,#eff6ff,#f5f3ff);border-radius:18px;padding:1rem 1.1rem}
.va .now{display:grid;gap:.25rem;align-content:center;min-width:0}
.va .now .sn{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:#57534e}
.va .now .nm{font-weight:800;font-size:1.35rem;line-height:1.2;color:#1c1917}
.va .now .meta{font-size:.82rem;color:#57534e}
.va .now .meta b{color:#7c3aed}
.va .due{display:inline-flex;align-items:center;gap:.4rem;font:inherit;font-size:.8rem;font-weight:700;color:#7c3aed;
  background:#fff;border:1px solid #c4b5fd;border-radius:999px;padding:.25rem .7rem;cursor:pointer;justify-self:start;margin-top:.3rem}
.va .due:hover{background:#f5f3ff}
.va .stars{font-size:.95rem;letter-spacing:.06em;color:#f59e0b}
.va .stars .off{color:#d6d3d1;opacity:.7}
.va .modes.compact{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin:0;align-content:center;min-width:16rem}
.va .modes.compact .mode{padding:.9rem .8rem;border-radius:14px}
.va .modes.compact .mode .ic{font-size:1.8rem;margin-bottom:.2rem}
.va .modes.compact .mode h2{font-size:1.05rem;margin:0}
.va .modes.compact .mode p{font-size:.78rem;margin:.15rem 0 0;opacity:.9}
@media (max-width:640px){.va .hero{grid-template-columns:1fr}.va .modes.compact{min-width:0}}
.va details.pick{margin-top:.8rem;border:1px solid #e7e5e4;border-radius:14px;background:#fff}
.va details.pick>summary{cursor:pointer;list-style:none;padding:.7rem 1rem;font-weight:700;color:#1c1917;display:flex;justify-content:space-between;align-items:center;gap:.6rem}
.va details.pick>summary::-webkit-details-marker{display:none}
.va details.pick>summary::after{content:"\25BE";color:#7c3aed}
.va details.pick[open]>summary::after{content:"\25B4"}
.va details.pick>summary small{font-weight:400;color:#57534e}
.va .pickbar{display:grid;grid-template-columns:minmax(10rem,1fr) auto minmax(8rem,1fr);gap:.5rem;padding:0 1rem .6rem;align-items:center}
@media (max-width:640px){.va .pickbar{grid-template-columns:1fr}}
.va .pickbar select,.va .pickbar input{font:inherit;font-size:.9rem;padding:.45rem .6rem;border:1px solid #d6d3d1;border-radius:10px;background:#fff;min-width:0;width:100%}
.va .tiers{display:flex;gap:.3rem}
.va .tiers button{font:inherit;font-size:.78rem;font-weight:700;padding:.35rem .7rem;border:1px solid #d6d3d1;border-radius:999px;background:#fff;cursor:pointer;color:#57534e}
.va .tiers button[aria-pressed="true"]{background:#1c1917;color:#fff;border-color:#1c1917}
.va .list{max-height:38vh;overflow:auto;border-top:1px solid #e7e5e4;border-radius:0 0 14px 14px}
.va .row-set{display:grid;grid-template-columns:3rem minmax(0,1fr) auto auto;gap:.6rem;align-items:center;width:100%;text-align:left;
  font:inherit;border:0;border-bottom:1px solid #f5f5f4;background:#fff;padding:.55rem 1rem;cursor:pointer;color:#1c1917}
.va .row-set:hover{background:#f5f3ff}
.va .row-set[aria-pressed="true"]{background:#ede9fe;box-shadow:inset 3px 0 0 #7c3aed}
.va .row-set .n{font-family:ui-monospace,Menlo,monospace;font-size:.8rem;color:#57534e}
.va .row-set .nm{font-weight:600;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.va .row-set .w{font-size:.75rem;color:#57534e;white-space:nowrap}
.va .row-set .stars{font-size:.85rem}
.va .empty{padding:1rem;color:#57534e;font-size:.9rem}
.va .modes{display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem;margin-top:1.3rem}
.va .mode{font:inherit;border:0;border-radius:18px;padding:1.4rem 1.2rem;color:#fff;text-align:center;cursor:pointer;
  transition:transform .15s,box-shadow .15s}
.va .mode:hover{transform:scale(1.03);box-shadow:0 20px 40px -18px rgba(0,0,0,.6)}
.va .mode.cards{background:linear-gradient(135deg,#60a5fa,#2563eb)}
.va .mode.gallery{background:linear-gradient(135deg,#a855f7,#db2777)}
.va .mode .ic{font-size:2.6rem;line-height:1;margin-bottom:.5rem}
.va .mode h2{margin:0 0 .4rem;font-size:1.5rem;color:#fff}
.va .mode p{margin:0 0 .6rem;opacity:.9}
.va .mode .tips{background:rgba(255,255,255,.2);border-radius:10px;padding:.5rem .7rem;font-size:.82rem;text-align:left}
.va .picked{text-align:center;color:#57534e;font-size:.9rem;margin-top:.9rem}
.va .picked b{color:#7c3aed}
.va .btn{font:inherit;font-weight:700;border:0;border-radius:999px;cursor:pointer;padding:.7rem 1.4rem;color:#fff;
  background:#57534e;box-shadow:0 6px 14px -8px rgba(0,0,0,.6);display:inline-flex;align-items:center;gap:.4rem}
.va .btn:hover{filter:brightness(1.08)}
.va .btn.go{background:linear-gradient(90deg,#7c3aed,#2563eb);font-size:1.3rem;padding:.9rem 2.2rem}
.va .btn.violet{background:#7c3aed}
.va .btn.green{background:#059669}
.va .btn.small{padding:.4rem .9rem;font-size:.85rem}
.va .btn:disabled{opacity:.5;cursor:default}
.va .row{display:flex;flex-wrap:wrap;gap:.7rem;justify-content:center;align-items:center}
.va .center{text-align:center}
/* flashcards */
.va.cards-bg{background:linear-gradient(135deg,#eff6ff,#f5f3ff)}
.va .fc-head{text-align:center;margin-bottom:1rem}
.va .fc-head h1{font-size:2rem;margin:.6rem 0 .2rem;color:#1c1917}
.va .pill{background:#fff;border-radius:999px;padding:.6rem 1.2rem;box-shadow:0 4px 12px -6px rgba(0,0,0,.4);font-weight:600}
.va .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:1.2rem;padding:0 1rem 1.5rem}
.va .card3d{perspective:1000px;cursor:pointer}
.va .card3d .inner{position:relative;height:13rem;transition:transform .5s;transform-style:preserve-3d}
.va .card3d.flipped .inner{transform:rotateY(180deg)}
.va .face{position:absolute;inset:0;backface-visibility:hidden;border-radius:18px;box-shadow:0 10px 26px -14px rgba(0,0,0,.5);
  padding:1.2rem;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.va .face.front{background:#fff;border:4px solid #67e8f9}
.va .face.back{background:linear-gradient(135deg,#4ade80,#10b981);border:4px solid #059669;color:#fff;transform:rotateY(180deg)}
.va .face .cat{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;font-weight:700;margin-bottom:.4rem;color:#0891b2}
.va .face.back .cat{color:rgba(255,255,255,.9)}
.va .face .big{font-size:1.7rem;font-weight:800;line-height:1.2}
.va .face .pron{font-style:italic;color:#78716c;font-size:.9rem;margin-top:.3rem}
.va .face .hint{margin-top:.8rem;font-size:.8rem;color:#78716c}
.va .face.back .hint{color:rgba(255,255,255,.9)}
.va .mark{position:absolute;top:.6rem;right:.7rem;font-size:1.3rem}
.va .judge{display:flex;gap:.5rem;margin-top:.7rem}
.va .judge button{font:inherit;font-weight:700;border:0;border-radius:999px;padding:.4rem .9rem;cursor:pointer;color:#1c1917;background:#fff}
.va .judge button.no{background:#fee2e2;color:#991b1b}
.va .judge button.yes{background:#fef3c7;color:#92400e}
.va .done{margin:0 1rem 1.5rem;background:linear-gradient(90deg,#4ade80,#10b981);color:#fff;border-radius:18px;padding:1.2rem;text-align:center}
.va .done h2{margin:0 0 .3rem;color:#fff}
/* gallery */
.va.gal-bg{background:linear-gradient(135deg,#7c3aed,#2563eb)}
.va.over-bg{background:linear-gradient(135deg,#dc2626,#ea580c)}
.va .how{background:#f5f3ff;border-radius:14px;padding:1rem 1.2rem;text-align:left;margin:1rem 0 1.4rem}
.va .how h2{margin:0 0 .4rem;font-size:1.05rem}
.va .how ul{margin:0;padding-left:1.2rem;color:#44403c}
.va .score{font-size:4rem;font-weight:800;color:#7c3aed;line-height:1}
.va .tiles3{display:grid;grid-template-columns:repeat(auto-fit,minmax(8rem,1fr));gap:.8rem;margin:1rem 0;text-align:left}
.va .tiles3 div{border-radius:12px;padding:.8rem 1rem}
.va .tiles3 .c{background:#dcfce7}.va .tiles3 .m{background:#fee2e2}.va .tiles3 .s{background:#fef3c7}.va .tiles3 .a{background:#dbeafe}
.va .tiles3 small{display:block;color:#57534e}
.va .tiles3 b{font-size:1.9rem}
.va .logline{margin:.6rem 0 1rem;padding:.7rem 1rem;border-radius:12px;background:#f5f5f4;color:#44403c;font-size:.95rem}
.va .logline.ok{background:#dcfce7;color:#065f46}
.va .logline.warn{background:#fef3c7;color:#92400e}
.va .logline a{color:inherit}
.play{position:fixed;inset:0;z-index:50;background:linear-gradient(135deg,#1e3a8a,#581c87);color:#fff;overflow:hidden;
  font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.play .top{position:absolute;top:0;left:0;right:0;background:rgba(0,0,0,.5);padding:.8rem 1rem;z-index:2}
.play .top .in{max-width:60rem;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
.play .stat{text-align:center}
.play .stat small{display:block;opacity:.75;font-size:.75rem}
.play .stat b{font-size:1.7rem}
.play .stat.f b{color:#f87171}.play .stat.s b{color:#fbbf24}.play .stat.a b{color:#60a5fa}
.play .lives{display:flex;gap:.4rem}
.play .lives i{width:14px;height:14px;border-radius:999px;background:#57534e;display:block}
.play .lives i.lost{background:#ef4444}
.play .field{position:absolute;top:6.5rem;bottom:8.5rem;left:0;right:0}
.play .word{position:absolute;left:50%;transform:translateX(-50%);font-size:clamp(1.6rem,5vw,3.4rem);font-weight:800;
  padding:.6rem 1.4rem;border-radius:18px;background:#fff;color:#1c1917;box-shadow:0 20px 40px -16px rgba(0,0,0,.7);white-space:nowrap;
  max-width:96vw;overflow:hidden;text-overflow:ellipsis}
.play .word.ok{background:#22c55e;color:#fff;transform:translateX(-50%) scale(1.1);transition:all .3s}
.play .word.bad{background:#ef4444;color:#fff;opacity:.6;transform:translateX(-50%) scale(.9);transition:all .3s}
.play .answers{position:absolute;bottom:1.2rem;left:0;right:0;padding:0 1rem}
.play .answers .in{max-width:60rem;margin:0 auto;display:grid;grid-template-columns:repeat(3,1fr);gap:.8rem}
.play .answers button{font:inherit;font-weight:700;font-size:clamp(.9rem,2.4vw,1.25rem);padding:1.2rem .6rem;border:0;border-radius:14px;
  background:#fff;color:#1c1917;cursor:pointer;box-shadow:0 10px 20px -12px rgba(0,0,0,.8);transition:transform .12s}
.play .answers button:hover:not(:disabled){transform:scale(1.04);background:#dbeafe}
.play .answers button:disabled{cursor:default}
.play .answers button.ok{background:#22c55e;color:#fff}
.play .answers button.bad{background:#ef4444;color:#fff}
.play .keys{text-align:center;opacity:.6;font-size:.75rem;margin-top:.5rem}
@media (max-width:600px){.play .top .stat b{font-size:1.2rem}.play .field{top:8rem}}
/* the steps */
.steps-all>summary{cursor:pointer;font-weight:700;margin:1rem 0 .3rem}
.steps-all .tablewrap{max-height:24rem;overflow:auto;border:1px solid var(--line);border-radius:10px}
.steps-all table{margin-top:0;border:0}
.steps-sets td.stars{color:#f59e0b;letter-spacing:.08em;white-space:nowrap}
.steps-sets td.stars .off{color:#d6d3d1}
.step{display:grid;grid-template-columns:auto 1fr;gap:.2rem .75rem;align-items:start}
.step .st{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:4px;padding:.1rem .4rem;
  border:1px solid var(--line);background:#f5f5f4;color:#57534e;white-space:nowrap;margin-top:.15rem}
.step form{grid-column:1/-1;margin-top:.5rem;display:grid;gap:.4rem}
.step textarea{font:inherit;font-size:.85rem;width:100%;border:1px solid var(--line);border-radius:8px;padding:.45rem .6rem;background:#fff}
.step button{font:inherit;font-weight:700;border:1px solid #292524;border-radius:999px;padding:.4rem 1rem;background:#292524;color:#fcfcf9;cursor:pointer;justify-self:start}
.step button:hover{background:#1c1917}
.step .ready{color:#065f46;font-weight:700}
CSS;

const VOCAB_APP_JS = <<<'JS'
(function(){
var root=document.getElementById('vocab');if(!root)return;
var data=JSON.parse(document.getElementById('vocab-data').textContent);
var sets=data.sets.slice().sort(function(a,b){return a.number-b.number;});
var byNum={};sets.forEach(function(s){byNum[s.number]=s;s.words.forEach(function(w){w.set=s.number;});});
var selected=parseInt(localStorage.getItem('vocab-set-'+data.slug)||'0',10);
if(!byNum[selected])selected=byNum[data.due]?data.due:(sets.length?sets[0].number:0);
var PENDING='vocab-pending-'+data.slug;
function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function uuid(){if(window.crypto&&crypto.randomUUID)return 'web-'+crypto.randomUUID();
  return 'web-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,12);}
function shuffle(a){for(var i=a.length-1;i>0;i--){var j=Math.floor(Math.random()*(i+1));var t=a[i];a[i]=a[j];a[j]=t;}return a;}
function stars(n){var s='';for(var i=0;i<3;i++)s+=i<n?'★':'<span class="off">☆</span>';return '<span class="stars" title="'+n+' of 3 stars">'+s+'</span>';}

/* ---- sounds: a ba-bing for a hit, a berh-berh for a miss; synthesised, so nothing is fetched ---- */
var sfx=(function(){var ctx=null;
  function ac(){if(ctx)return ctx;var C=window.AudioContext||window.webkitAudioContext;if(!C)return null;try{ctx=new C();}catch(e){ctx=null;}return ctx;}
  function tone(type,f0,f1,at,len,vol){var c=ac();if(!c)return;try{var o=c.createOscillator(),g=c.createGain(),t=c.currentTime+at;
    o.type=type;o.frequency.setValueAtTime(f0,t);o.frequency.exponentialRampToValueAtTime(f1,t+len);
    g.gain.setValueAtTime(0.0001,t);g.gain.exponentialRampToValueAtTime(vol,t+0.015);g.gain.exponentialRampToValueAtTime(0.0001,t+len);
    o.connect(g);g.connect(c.destination);o.start(t);o.stop(t+len+0.05);}catch(e){}}
  return {
    /* browsers only let a page make a sound after a click; the Start button is that click */
    unlock:function(){var c=ac();if(c&&c.state==='suspended'){try{c.resume();}catch(e){}}},
    ding:function(){tone('triangle',660,660,0,0.14,0.3);tone('triangle',990,990,0.12,0.35,0.3);},
    buzz:function(){tone('sawtooth',170,110,0,0.2,0.2);tone('sawtooth',170,110,0.28,0.28,0.2);}};})();

/* ---- logging: POST the run; keep it on this device if the post fails and send it next time ---- */
function post(run){return fetch(data.runUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(run),keepalive:true})
  .then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.error_description||('HTTP '+r.status));return j;});});}
function pending(){try{return JSON.parse(localStorage.getItem(PENDING)||'[]');}catch(e){return [];}}
function savePending(list){try{localStorage.setItem(PENDING,JSON.stringify(list.slice(-20)));}catch(e){}}
function flush(){var list=pending();if(!list.length)return;savePending([]);
  list.forEach(function(run){post(run).catch(function(){var l=pending();l.push(run);savePending(l);});});}
function logRun(run,onDone){post(run).then(function(j){onDone({ok:true,res:j});}).catch(function(e){
  var l=pending();l.push(run);savePending(l);onDone({ok:false,err:e.message});});}
function logLine(st){if(!st)return '<div class="logline">Logging this game…</div>';
  if(st.ok){var j=st.res;return '<div class="logline ok">✓ '+esc(j.message)+' <a href="'+esc(j.board)+'">See the board →</a></div>';}
  return '<div class="logline warn">Could not reach the tracker ('+esc(st.err)+'). The game is saved on this device and will be sent the next time this page opens.</div>';}

/* ---- the vocabulary a game runs over: 80% this set, 20% earlier sets ---- */
function current(){return byNum[selected]?byNum[selected].words:[];}
function previous(){var out=[];sets.forEach(function(s){if(s.number<selected)out=out.concat(s.words);});return out;}
function mixed(pct){var cur=current(),prev=previous();if(!prev.length)return cur.slice();
  var n=Math.floor(cur.length*pct),m=cur.length-n,out=cur.slice(0,n);
  for(var i=0;i<m;i++)out.push(prev[Math.floor(Math.random()*prev.length)]);return out;}

/* ---- screens ---- */
function setBg(cls){root.className='va'+(cls?' '+cls:'');}
var pick={open:false,theme:null,tier:'all',q:''};
function themeOf(s){return (s.topic_refs[0]&&data.topicNames[s.topic_refs[0]])||'Other sets';}
function tierOf(s){if(/\[H\]|\(Higher\)/.test(s.name))return 'H';var w=s.words[0];return (w&&w.tier==='H')?'H':'F';}
function welcome(){setBg('');
  var h='<div class="panel"><h1 class="va-title">'+esc(data.title)+' 🎮</h1><p class="lede">Pick a game. Every finished game is logged for you.</p>';
  if(!sets.length){h+='<p class="center">No sets yet. Dad adds them with <code>tracker_save_vocab_sets</code>.</p></div>';root.innerHTML=h;return;}
  var cur=byNum[selected],due=byNum[data.due];
  /* the set in hand and the two games, above the fold */
  h+='<div class="hero"><div class="now"><span class="sn">Set '+cur.number+' · '+esc(themeOf(cur))+'</span><span class="nm">'+esc(cur.name)+'</span>'
    +'<span class="meta">'+cur.words.length+' words'+(cur.runs?' · '+cur.runs+' game'+(cur.runs===1?'':'s'):'')+(cur.best!==null?' · best '+Math.round(cur.best*100)+'%':'')
    +(cur.next?' · next: <b>'+esc(cur.next)+'</b>':'')+'</span>'+stars(cur.stars)
    +(due&&due.number!==cur.number?'<button type="button" class="due" data-set="'+due.number+'">→ Up next for you: Set '+due.number+' '+esc(due.name)+'</button>':'')
    +'</div>'
    +'<div class="modes compact"><button type="button" class="mode cards" data-go="cards"><div class="ic">📚</div><h2>Flashcards</h2><p>Flip, then say if you knew it</p></button>'
    +'<button type="button" class="mode gallery" data-go="gallery"><div class="ic">🎯</div><h2>Shooting Gallery</h2><p>Shoot the words before they escape</p></button></div></div>';
  /* the picker: a theme, a tier, a search, and a short list */
  var themes=[],seen={};sets.forEach(function(s){var t=themeOf(s);if(!seen[t]){seen[t]=1;themes.push(t);}});
  if(pick.theme===null)pick.theme=themeOf(cur);
  var rows=sets.filter(function(s){
    if(pick.q){var q=pick.q.toLowerCase();return s.name.toLowerCase().indexOf(q)>=0||('set '+s.number).indexOf(q)>=0||String(s.number)===q
      ||s.words.some(function(w){return w.word.toLowerCase().indexOf(q)>=0||w.english.toLowerCase().indexOf(q)>=0;});}
    if(pick.theme!=='*'&&themeOf(s)!==pick.theme)return false;
    return pick.tier==='all'||tierOf(s)===pick.tier;});
  h+='<details class="pick"'+(pick.open?' open':'')+'><summary><span>Change set <small>· '+sets.length+' sets in '+themes.length+' groups</small></span></summary>'
    +'<div class="pickbar"><select data-theme><option value="*"'+(pick.theme==='*'?' selected':'')+'>All groups</option>'
    +themes.map(function(t){return '<option value="'+esc(t)+'"'+(pick.theme===t?' selected':'')+'>'+esc(t)+'</option>';}).join('')+'</select>'
    +'<div class="tiers">'+[['all','All'],['F','Foundation'],['H','Higher']].map(function(t){return '<button type="button" data-tier="'+t[0]+'" aria-pressed="'+(pick.tier===t[0])+'">'+t[1]+'</button>';}).join('')+'</div>'
    +'<input type="search" data-q placeholder="Search a set or a word…" value="'+esc(pick.q)+'"></div>'
    +'<div class="list">'+(rows.length?rows.map(function(s){return '<button type="button" class="row-set" data-set="'+s.number+'" aria-pressed="'+(s.number===selected)+'">'
      +'<span class="n">'+s.number+'</span><span class="nm">'+esc(s.name)+'</span><span class="w">'+s.words.length+' words'+(s.runs?' · '+s.runs+' game'+(s.runs===1?'':'s'):'')+'</span>'+stars(s.stars)+'</button>';}).join('')
      :'<div class="empty">Nothing matches. Try another group or clear the search.</div>')+'</div></details>';
  h+='<p class="center" style="margin:1rem 0 0;color:#57534e;font-size:.9rem">'+esc(data.subjectName)+' · <a href="#steps">your steps ↓</a></p></div>';
  root.innerHTML=h;
  var sel=root.querySelector('.row-set[aria-pressed="true"]');if(sel&&pick.open)sel.scrollIntoView({block:'nearest'});
  var d=root.querySelector('details.pick');if(d)d.addEventListener('toggle',function(){pick.open=d.open;});
  var th=root.querySelector('[data-theme]');if(th)th.addEventListener('change',function(){pick.theme=th.value;pick.open=true;welcome();});
  var q=root.querySelector('[data-q]');if(q){q.addEventListener('input',function(){pick.q=q.value;pick.open=true;var at=q.selectionStart;welcome();
    var nq=root.querySelector('[data-q]');if(nq){nq.focus();try{nq.setSelectionRange(at,at);}catch(e){}}});}}

/* flashcards: flip, then say whether you knew it; the set is logged when every card is judged, or on Finish */
function flashcards(){setBg('cards-bg');
  var words=current(),flipped={},judged={},runId=uuid(),started=new Date(),state=null,finished=false;
  function render(){var n=Object.keys(judged).length;
    var h='<div class="fc-head" style="padding-top:1.4rem"><button type="button" class="btn small" data-go="welcome">⬅ Back to menu</button>'
      +'<h1>'+esc(data.subjectName)+' Flashcards 📚</h1><p style="color:#57534e;margin:0 0 .8rem">Click a card to reveal the English, then say whether you knew it.</p>'
      +'<div class="row"><span class="pill">Progress: '+n+' / '+words.length+'</span><button type="button" class="btn violet" data-act="reset">↺ Reset</button>'
      +(n&&!finished?'<button type="button" class="btn green" data-act="finish">✓ Finish &amp; log</button>':'')+'</div></div>';
    if(finished){h+='<div class="done"><h2>🎉 ¡Excelente! Well done!</h2><p style="margin:0">You judged '+n+' of '+words.length+' cards.</p>'+logLine(state)+'</div>';}
    h+='<div class="grid">';
    words.forEach(function(w,i){var j=judged[i];
      h+='<div class="card3d'+(flipped[i]?' flipped':'')+'" data-card="'+i+'"><div class="inner"><div class="face front">'
        +(j?'<span class="mark">'+(j==='correct'?'✅':'❌')+'</span>':'')
        +'<div class="cat">'+esc(w.word_class||'word')+'</div><div class="big">'+esc(w.word)+'</div>'+(w.pronunciation?'<div class="pron">('+esc(w.pronunciation)+')</div>':'')+'<div class="hint">Click to reveal</div></div>'
        +'<div class="face back"><div class="cat">English</div><div class="big">'+esc(w.english)+'</div>'
        +(finished?'':'<div class="judge"><button type="button" class="yes" data-judge="correct" data-i="'+i+'">✓ Knew it</button><button type="button" class="no" data-judge="incorrect" data-i="'+i+'">✗ Not yet</button></div>')
        +'<div class="hint">Click to flip back</div></div></div></div>';});
    h+='</div>';root.innerHTML=h;}
  function finish(){if(finished)return;finished=true;var items=[];
    words.forEach(function(w,i){if(judged[i])items.push({word:w.word,set:w.set,outcome:judged[i]});});
    if(!items.length){render();return;}
    render();logRun({client_run_id:runId,game:'flashcards',set:selected,played_at:started.toISOString(),
      duration_seconds:Math.round((Date.now()-started.getTime())/1000),items:items},function(st){state=st;render();});}
  root.onclick=function(e){var t=e.target.closest('[data-judge]');
    if(t){e.stopPropagation();judged[t.dataset.i]=t.dataset.judge;if(Object.keys(judged).length===words.length)finish();else render();return;}
    var a=e.target.closest('[data-act]');if(a){if(a.dataset.act==='reset'){flipped={};judged={};finished=false;state=null;runId=uuid();started=new Date();}
      if(a.dataset.act==='finish')return finish();render();return;}
    var g=e.target.closest('[data-go]');if(g){root.onclick=null;return go(g.dataset.go);}
    var c=e.target.closest('[data-card]');if(c){flipped[c.dataset.card]=!flipped[c.dataset.card];render();}};
  render();}

/* the Shooting Gallery, as the app plays it */
function gallery(){setBg('gal-bg');
  root.innerHTML='<div class="panel center" style="max-width:40rem"><button type="button" class="btn small" data-go="welcome">⬅ Back to menu</button>'
    +'<div style="font-size:4.5rem;line-height:1;margin:1rem 0 .4rem">🎯</div><h1 style="font-size:2.2rem;margin:0 0 .5rem">'+esc(data.subjectName)+' Shooting Gallery!</h1>'
    +'<p style="color:#57534e;font-size:1.1rem">Shoot down the '+esc(data.subjectName)+' words by clicking the correct English before they escape! Playing <b>Set '+selected+': '+esc(byNum[selected].name)+'</b>'+(selected>sets[0].number?', with a few words from earlier sets mixed in':'')+'.</p>'
    +'<div class="how"><h2>How to play</h2><ul><li>Words rise up the screen</li><li>Click the right English translation (or use ← ↓ →) to shoot them down</li><li>It gets faster every 3 points</li><li>Three misses in a row and the game ends</li><li>A word that escapes off the top counts as a miss</li></ul></div>'
    +'<button type="button" class="btn go" data-act="start">Start Game!</button></div>';
  root.onclick=function(e){var g=e.target.closest('[data-go]');if(g){root.onclick=null;return go(g.dataset.go);}
    if(e.target.closest('[data-act="start"]')){root.onclick=null;sfx.unlock();play();}};}

function play(){
  var vocab=mixed(0.8);if(vocab.length<1){return go('welcome');}
  var pool=[];sets.forEach(function(s){pool=pool.concat(s.words);});
  var score=0,fails=0,streak=0,speed=6,topSpeed=0,pos=100,feedback=null,chosen=null,word=null,options=[],timer=null,items=[],over=false;
  var runId=uuid(),started=new Date();
  var el=document.createElement('div');el.className='play';document.body.appendChild(el);document.body.style.overflow='hidden';
  function distinct(list,w){return list.filter(function(x){return x.english!==w.english;});}
  function pickOptions(w){var opts=[w.english],others=distinct(vocab,w);if(others.length<2)others=distinct(pool,w);
    var seen={};seen[w.english]=1;others=shuffle(others.slice());
    for(var i=0;i<others.length&&opts.length<3;i++){if(!seen[others[i].english]){seen[others[i].english]=1;opts.push(others[i].english);}}
    return shuffle(opts);}
  function next(){pos=100;feedback=null;chosen=null;word=vocab[Math.floor(Math.random()*vocab.length)];options=pickOptions(word);draw();}
  function dispSpeed(){return 10-speed;}
  function miss(){fails++;streak++;if(streak>=3){end();return true;}return false;}
  function draw(){var lives='';for(var i=0;i<3;i++)lives+='<i class="'+(i<streak?'lost':'')+'"></i>';
    var acc=score+fails>0?((score/(score+fails))*100).toFixed(1):'0.0';
    el.innerHTML='<div class="top"><div class="in"><button type="button" class="btn small" data-act="quit">⬅ Menu</button>'
      +'<div style="display:flex;gap:1.4rem"><div class="stat"><small>Score</small><b>'+score+'</b></div><div class="stat f"><small>Misses</small><b>'+fails+'</b></div>'
      +'<div class="stat a"><small>Accuracy</small><b>'+acc+'%</b></div><div class="stat s"><small>Speed</small><b>'+dispSpeed().toFixed(1)+'x</b></div></div><div class="lives">'+lives+'</div></div></div>'
      +'<div class="field">'+(word?'<div class="word'+(feedback==='correct'?' ok':feedback==='wrong'?' bad':'')+'" style="bottom:'+pos+'%">'+esc(word.word)+(feedback==='correct'?' ✓':feedback==='wrong'?' ✗':'')+'</div>':'')+'</div>'
      +'<div class="answers"><div class="in">'+options.map(function(o){var cls=chosen===o?(feedback==='correct'?' class="ok"':' class="bad"'):'';
        return '<button type="button"'+cls+' data-ans="'+esc(o)+'"'+(feedback?' disabled':'')+'>'+esc(o)+'</button>';}).join('')+'</div><div class="keys">← left · ↓ middle → right</div></div>';}
  function moveWord(){var w=el.querySelector('.word');if(w)w.style.bottom=pos+'%';}
  function tick(){if(over||feedback)return;pos-=1;if(pos<-10){items.push({word:word.word,set:word.set,outcome:'incorrect'});sfx.buzz();if(miss())return;next();return;}moveWord();}
  function schedule(){if(timer)clearInterval(timer);timer=setInterval(tick,speed*10);}
  function answer(a){if(over||feedback||!word)return;chosen=a;
    if(a===word.english){score++;streak=0;feedback='correct';sfx.ding();items.push({word:word.word,set:word.set,outcome:'correct'});
      if(score%3===0){speed=Math.max(2.5,speed-0.8);}topSpeed=Math.max(topSpeed,dispSpeed());draw();
      setTimeout(function(){if(!over){next();schedule();}},800);}
    else{feedback='wrong';sfx.buzz();items.push({word:word.word,set:word.set,outcome:'incorrect'});draw();
      setTimeout(function(){if(over)return;if(miss())return;next();schedule();},800);}}
  function keys(e){if(over)return;var i={ArrowLeft:0,ArrowDown:1,ArrowRight:2}[e.key];if(i!==undefined&&options[i]){e.preventDefault();answer(options[i]);}
    if(e.key==='Escape')quit();}
  function teardown(){over=true;if(timer)clearInterval(timer);window.removeEventListener('keydown',keys);el.remove();document.body.style.overflow='';}
  function quit(){var had=items.length;teardown();if(had>=1)finishScreen(true);else go('welcome');}
  function end(){teardown();finishScreen(false);}
  function finishScreen(quitEarly){setBg('over-bg');var st=null;var acc=items.length?Math.round(score/items.length*100):0;
    function render(){root.innerHTML='<div class="panel center" style="max-width:40rem"><div style="font-size:4.5rem;line-height:1;margin:.4rem 0">🏆</div>'
      +'<h1 style="font-size:2.2rem;margin:0 0 .6rem">'+(quitEarly?'Stopped early':'Game Over!')+'</h1>'
      +'<div style="background:#fafaf9;border-radius:16px;padding:1.2rem"><div class="score">'+score+'</div><div style="color:#57534e;font-size:1.1rem">Final score · Set '+selected+': '+esc(byNum[selected].name)+'</div>'
      +'<div class="tiles3"><div class="c"><small>Correct</small><b style="color:#059669">'+score+'</b></div><div class="m"><small>Missed</small><b style="color:#dc2626">'+fails+'</b></div>'
      +'<div class="a"><small>Accuracy</small><b style="color:#1d4ed8">'+acc+'%</b></div><div class="s"><small>Top speed</small><b style="color:#b45309">'+topSpeed.toFixed(1)+'x</b></div></div>'
      +logLine(st)+'</div><div class="row" style="margin-top:1.2rem"><button type="button" class="btn go" data-act="again">Play Again!</button><button type="button" class="btn" data-go="welcome">Main Menu</button></div></div>';}
    render();
    logRun({client_run_id:runId,game:'gallery',set:selected,played_at:started.toISOString(),duration_seconds:Math.round((Date.now()-started.getTime())/1000),
      top_speed:Number(topSpeed.toFixed(1)),items:items},function(s){st=s;render();if(s.ok)refreshSteps();});
    root.onclick=function(e){if(e.target.closest('[data-act="again"]')){root.onclick=null;return play();}
      var g=e.target.closest('[data-go]');if(g){root.onclick=null;go(g.dataset.go);}};}
  el.addEventListener('click',function(e){var b=e.target.closest('[data-ans]');if(b)return answer(b.dataset.ans);if(e.target.closest('[data-act="quit"]'))quit();});
  window.addEventListener('keydown',keys);
  next();schedule();}

/* after a logged game the steps under the app are stale; reload them quietly */
function refreshSteps(){fetch(location.pathname,{headers:{'Accept':'text/html'}}).then(function(r){return r.text();}).then(function(html){
  var doc=new DOMParser().parseFromString(html,'text/html');var fresh=doc.getElementById('steps-body'),cur=document.getElementById('steps-body');
  if(fresh&&cur)cur.innerHTML=fresh.innerHTML;
  var d=doc.getElementById('vocab-data');if(d){var nd=JSON.parse(d.textContent);nd.sets.forEach(function(s){if(byNum[s.number]){byNum[s.number].stars=s.stars;byNum[s.number].runs=s.runs;byNum[s.number].best=s.best;byNum[s.number].next=s.next;}});}
}).catch(function(){});}

function go(screen){root.onclick=null;if(screen==='cards')return flashcards();if(screen==='gallery')return gallery();welcome();
  root.onclick=function(e){var t=e.target.closest('[data-tier]');if(t){pick.tier=t.dataset.tier;pick.open=true;return welcome();}
    var s=e.target.closest('[data-set]');if(s){selected=parseInt(s.dataset.set,10);try{localStorage.setItem('vocab-set-'+data.slug,String(selected));}catch(x){}
      if(s.classList.contains('due'))pick.theme=null;return go('welcome');}
    var g=e.target.closest('[data-go]');if(g)go(g.dataset.go);};}
flush();go('welcome');
})();
JS;

/** The page. `$flash` is the outcome of a mark-off just posted, shown once under the steps. */
function render_vocab_app(Store $store, array $subject, bool $isParent, ?array $flash = null): string
{
    $slug  = $subject['slug'];
    $sets  = vocab_list_sets($store, $slug);
    $steps = vocab_steps($store, $subject, $sets);
    $short = preg_replace('/^GCSE\s+/i', '', (string) $subject['name']);

    $topicNames = [];
    foreach ($store->listTopics($slug) as $t) {
        $topicNames[$t['ref']] = $t['name'];
    }
    $jsSets = [];
    foreach ($sets as $s) {
        $p = $steps['sets'][$s['number']];
        $jsSets[] = [
            'number' => $s['number'], 'name' => $s['name'], 'topic_refs' => $s['topic_refs'], 'words' => $s['words'],
            'stars' => $p['stars'], 'runs' => $p['runs'], 'best' => $p['best'], 'next' => $p['next'], 'last' => $p['last'],
        ];
    }
    $data = [
        'due'         => vocab_due_set($steps['sets']),
        'slug'        => $slug,
        'subjectName' => $short,
        'title'       => $short . ' Learning Platform!',
        'runUrl'      => "/s/$slug/play/run",
        'sets'        => $jsSets,
        'topicNames'  => $topicNames,
    ];
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $body = detail_head($subject, $short . ' practice',
        'Flashcards and the Shooting Gallery over ' . count($sets) . ' set' . (count($sets) === 1 ? '' : 's')
        . '. Every finished game is logged as practice: it counts for the day\'s slot, feeds the '
        . '<a href="/s/' . h($slug) . '/practice">board</a>, and earns the stars below.');

    // What the page shows with JavaScript off: the sets themselves.
    $noscript = '';
    foreach ($sets as $s) {
        $noscript .= '<li><b>Set ' . $s['number'] . '</b> ' . h($s['name']) . ' — ' . count($s['words']) . ' words</li>';
    }
    $body .= '<div class="va" id="vocab"><noscript><div class="panel"><p>The games need JavaScript. The sets:</p><ul>'
        . $noscript . '</ul></div></noscript></div>';
    $body .= '<script type="application/json" id="vocab-data">' . $json . '</script>';

    $body .= '<h2 id="steps">Your steps</h2>'
        . '<p><small>A set earns a star for being played, two for a game at 80% or better, three for a second 80%+ game at least '
        . VOCAB_SPACING_DAYS . ' days after the first &mdash; the same bar a topic has to meet to be called secure. '
        . 'Games of fewer than ' . VOCAB_MIN_ITEMS . ' words do not count.</small></p>'
        . '<div id="steps-body">' . vocab_steps_html($subject, $sets, $steps, $isParent, $store, $flash) . '</div>';

    return dash_shell($short . ' practice — ' . $subject['name'], $body,
        '<style>' . DASH_VOCAB_CSS . '</style>') . '<script>' . VOCAB_APP_JS . '</script>';
}

/** The steps section: the sets table, then one card per topic with the parent's mark-off where the evidence allows one. */
function vocab_steps_html(array $subject, array $sets, array $steps, bool $isParent, Store $store, ?array $flash): string
{
    $slug = $subject['slug'];
    $h    = '';
    if ($flash) {
        $h .= '<div class="flag"' . ($flash['ok'] ? ' style="background:#ecfdf5;border-color:#6ee7b7;color:#065f46"' : '') . '>'
            . nl2br(h($flash['text'])) . '</div>';
    }
    if (!$sets) {
        return $h . '<p><small>No sets yet. The parent adds them with tracker_save_vocab_sets.</small></p>';
    }

    // The sets she has actually played lead; the whole list sits behind a
    // disclosure, because 138 rows between the game and the topics is a
    // scroll nobody asked for.
    $played = array_values(array_filter($sets, static fn($s) => $steps['sets'][$s['number']]['runs'] > 0));
    if ($played) {
        $h .= '<h3 class="kicker mono" style="margin:1rem 0 .3rem">Sets in play</h3>' . vocab_sets_table($slug, $played, $steps);
    } else {
        $h .= '<p><small>No games yet. Play a set and it appears here.</small></p>';
    }
    $h .= '<details class="steps-all"><summary>All ' . count($sets) . ' sets</summary>' . vocab_sets_table($slug, $sets, $steps) . '</details>';

    if (!$steps['topics']) {
        return $h;
    }
    return $h . vocab_topics_html($subject, $steps, $isParent, $store);
}

/** The sets table: one row per set with its games, best, stars and next step. */
function vocab_sets_table(string $slug, array $sets, array $steps): string
{
    $h = '<div class="tablewrap"><table class="steps-sets"><thead><tr><th>Set</th><th>Topics</th><th>Games</th><th>Best</th>'
        . '<th>Stars</th><th>Next step</th></tr></thead><tbody>';
    foreach ($sets as $s) {
        $p    = $steps['sets'][$s['number']];
        $refs = implode(' ', array_map(static fn($r) => '<a class="mono" href="/s/' . h($slug) . '/t/' . rawurlencode($r) . '">' . h($r) . '</a>', $s['topic_refs']));
        $star = '';
        for ($i = 0; $i < 3; $i++) {
            $star .= $i < $p['stars'] ? '&#9733;' : '<span class="off">&#9734;</span>';
        }
        $h .= '<tr><td><b class="mono">' . $s['number'] . '</b> ' . h($s['name']) . ' <small>' . count($s['words']) . ' words</small></td>'
            . '<td>' . ($refs ?: '<small>none</small>') . '</td>'
            . '<td class="mono">' . $p['runs'] . ($p['last'] ? ' <small>last ' . h($p['last']) . '</small>' : '') . '</td>'
            . '<td class="mono">' . ($p['best'] === null ? '—' : (int) round($p['best'] * 100) . '%') . '</td>'
            . '<td class="stars" title="' . $p['stars'] . ' of 3">' . $star . '</td>'
            . '<td><small>' . h($p['next']) . '</small></td></tr>';
    }
    return $h . '</tbody></table></div>';
}

/** The per-topic steps, with the parent's mark-off where the evidence allows one. */
function vocab_topics_html(array $subject, array $steps, bool $isParent, Store $store): string
{
    $slug = $subject['slug'];
    $h    = '<h3 class="kicker mono" style="margin:1.2rem 0 .3rem">Topics the sets teach</h3>';
    if (!$isParent) {
        $h .= '<p><small>When a topic\'s games meet the bar, Dad can mark the step off from here once he is signed in.</small></p>';
    }
    $csrf = $isParent ? parent_csrf($store) : '';
    foreach ($steps['topics'] as $t) {
        $colour = STATUS_COLOUR[$t['status'] ?? ''] ?? '#d6d3d1';
        $h .= '<div class="item"><span class="dot" style="margin-top:.45rem;background:' . $colour . '"></span><div class="grow step">'
            . '<span class="st">' . h(STATUS_LABEL[$t['status'] ?? ''] ?? ($t['status'] ?? 'unknown')) . '</span>'
            . '<div><strong class="mono"><a href="/s/' . h($slug) . '/t/' . rawurlencode($t['ref']) . '">' . h($t['ref']) . '</a></strong> '
            . h((string) ($t['name'] ?? 'no such topic')) . ' <small>&middot; sets ' . h(implode(', ', $t['sets'])) . ' &middot; '
            . $t['runs'] . ' game' . ($t['runs'] === 1 ? '' : 's') . ', ' . $t['good'] . ' at 80%+</small>'
            . '<div><small>' . ($t['suggest'] ? '<span class="ready">Ready to mark ' . h($t['suggest']) . '.</span> ' : '') . h((string) $t['why']) . '</small></div></div>';
        if ($t['suggest'] && $isParent) {
            $h .= '<form method="post" action="/s/' . h($slug) . '/play/mark">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="ref" value="' . h($t['ref']) . '">'
                . '<input type="hidden" name="status" value="' . h($t['suggest']) . '">'
                . '<label><small>Evidence, as it will be written into the record</small>'
                . '<textarea name="evidence" rows="3">' . h((string) $t['evidence']) . '</textarea></label>'
                . '<button type="submit">Mark ' . h($t['ref']) . ' ' . h($t['suggest']) . '</button></form>';
        }
        $h .= '</div></div>';
    }
    return $h;
}
