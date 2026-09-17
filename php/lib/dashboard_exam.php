<?php
/**
 * Exam skills on the pages: the list of tests, the timed sitting, the
 * closed and marked views, and the parent's question bank.
 *
 * Two rules hold everywhere here. The questions of a test are in the HTML
 * only while the test is open — before Start the page shows the shape of
 * the paper and nothing else, and once the timer has ended it shows what
 * she wrote. And every render that is not the parent's goes through
 * exam_student_view(), so a mark scheme cannot leak by a template
 * forgetting to leave it out.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const DASH_EXAM_CSS = <<<'CSS'
/* ---- exam practice ------------------------------------------------------ */
.ex-list .item{align-items:center}
.ex-status{display:inline-block;font-size:.66rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  border-radius:4px;padding:.05rem .4rem;border:1px solid var(--line);background:#f5f5f4;color:#57534e;white-space:nowrap}
.ex-status.ready{background:#fef3c7;border-color:#fcd34d;color:#92400e}
.ex-status.open{background:#dbeafe;border-color:#93c5fd;color:#1e3a8a}
.ex-status.closed{background:#ede9fe;border-color:#c4b5fd;color:#4c1d95}
.ex-status.marked{background:#d1fae5;border-color:#6ee7b7;color:#065f46}
.ex-front{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:1.2rem 1.4rem;margin-top:1rem}
.ex-front h2{margin:0 0 .5rem}
.ex-front ul{margin:.4rem 0 .8rem 1.1rem;padding:0}
.ex-front .instr{white-space:pre-wrap;background:#fffdf5;border:1px solid #fde68a;border-radius:8px;padding:.7rem .9rem;margin:.6rem 0}
.ex-start{font:inherit;font-size:1.1rem;font-weight:700;padding:.7rem 1.6rem;border-radius:999px;border:2px solid #292524;
  background:#292524;color:#fcfcf9;cursor:pointer}
.ex-start:hover{background:#1c1917}
.ex-start:focus-visible{outline:3px solid #7c3aed;outline-offset:3px}
.ex-wait{color:var(--muted);font-size:.9rem}
/* The sitting */
.ex-bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;
  background:#fcfcf9;border-bottom:2px solid #292524;padding:.5rem 0;margin-top:.5rem}
.ex-timer{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:1.7rem;font-weight:700;
  border:2px solid #292524;border-radius:10px;padding:.15rem .7rem;min-width:6.5rem;text-align:center;background:#fff}
.ex-timer.warn{border-color:#d97706;color:#b45309}
.ex-timer.danger{border-color:#dc2626;color:#b91c1c;animation:expulse 1s ease-in-out infinite}
@keyframes expulse{0%,100%{opacity:1}50%{opacity:.55}}
@media (prefers-reduced-motion:reduce){.ex-timer.danger{animation:none}}
.ex-live{flex:1 1 12rem;font-size:.85rem;color:var(--muted);min-width:0}
.ex-save{font-size:.72rem;color:var(--muted);white-space:nowrap}
.ex-nav{display:flex;flex-wrap:wrap;gap:.3rem;margin:.6rem 0 0}
.ex-pill{font:inherit;font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.78rem;line-height:1;
  padding:.35rem .55rem;border-radius:6px;border:1px solid var(--line);background:#fff;cursor:pointer;color:var(--ink)}
.ex-pill.answered{background:#d1fae5;border-color:#6ee7b7}
.ex-pill.flagged{box-shadow:inset 0 -3px 0 #f59e0b}
.ex-pill:focus-visible{outline:2px solid #7c3aed;outline-offset:2px}
.ex-section{margin-top:1.6rem;padding-top:.6rem;border-top:2px solid #292524}
.ex-section h2{margin:0;display:flex;flex-wrap:wrap;gap:.4rem 1rem;align-items:baseline}
.ex-section h2 small{font-size:.8rem;font-weight:400}
.ex-q{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:1rem 1.2rem;margin-top:.8rem;scroll-margin-top:5rem}
.ex-q.is-flagged{border-color:#f59e0b;box-shadow:inset 4px 0 0 #f59e0b}
.ex-qhead{display:flex;flex-wrap:wrap;gap:.3rem .8rem;align-items:baseline;justify-content:space-between}
.ex-qhead .qn{font-weight:700;font-size:1.05rem}
.ex-qhead .qm{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.8rem;color:var(--muted)}
.ex-text{margin:.5rem 0 .8rem;font-size:1.02rem}
.ex-text p{margin:.4rem 0}
.ex-text pre.ex-code{background:#f5f5f4;border:1px solid var(--line);border-radius:8px;padding:.6rem .8rem;overflow-x:auto;
  font-size:.88rem}
.ex-text code{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.92em}
.ex-text sup{font-size:.72em}
.ex-q label{display:block;font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:.6rem 0 .2rem}
.ex-q textarea{width:100%;font:inherit;font-size:1rem;line-height:1.45;padding:.6rem .7rem;border:1px solid var(--line);
  border-radius:8px;background:#fff;resize:vertical;min-height:4.5rem}
.ex-q textarea.working{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.92rem;min-height:6rem}
.ex-q textarea:focus{outline:2px solid #7c3aed;outline-offset:1px}
.ex-flag{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;margin-top:.5rem;cursor:pointer;
  text-transform:none;letter-spacing:0;color:var(--ink)}
.ex-flag input{width:1rem;height:1rem}
.ex-finish{margin:1.5rem 0;padding:1rem 1.2rem;background:var(--card);border:1px dashed var(--line);border-radius:12px}
.ex-finish button{font:inherit;font-size:.95rem;padding:.5rem 1.1rem;border-radius:8px;border:1px solid #292524;background:#fff;cursor:pointer}
.ex-locked textarea,.ex-locked input,.ex-locked button.ex-pill{opacity:.55;pointer-events:none}
.ex-over{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:10px;padding:.7rem 1rem;margin-top:.8rem;font-weight:700}
/* After the sitting */
.ex-ans{white-space:pre-wrap;background:#fafaf9;border:1px solid var(--line);border-radius:8px;padding:.5rem .7rem;font-size:.95rem;margin:.2rem 0 .4rem}
.ex-ans.blank{color:#991b1b;font-style:italic;background:#fff1f2;border-color:#fecdd3}
.ex-ans.working{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-size:.88rem}
.ex-score{font-family:ui-monospace,"Cascadia Mono",Menlo,monospace;font-weight:700;font-size:1.05rem}
.ex-score.full{color:#065f46}.ex-score.part{color:#92400e}.ex-score.none{color:#991b1b}
.ex-fb{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:.5rem .7rem;margin-top:.5rem;font-size:.92rem}
.ex-parent{background:#fffdf5;border:1px solid #fde68a;border-radius:8px;padding:.5rem .7rem;margin-top:.5rem;font-size:.88rem}
.ex-parent b{display:block;font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:.3rem}
.ex-parent b:first-child{margin-top:0}
.ex-meta{font-size:.78rem;color:var(--muted);margin-top:.3rem}
.ex-totals{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.7rem;margin-top:1rem}
.ex-totals .card p.big{font-size:1.6rem}
.ex-bank td{font-size:.85rem}
.ex-bank .ex-text{font-size:.9rem;margin:.2rem 0}
.ex-bank details summary{cursor:pointer;color:var(--muted);font-size:.8rem}
@media print{.ex-bar,.ex-nav,.ex-finish{display:none}}
CSS;

/**
 * The sitting's script. Plain JavaScript, no library. The clock is the
 * server's: the page carries the deadline and the server's now, keeps the
 * offset, and re-reads the server's now on every save, so a laptop that
 * slept still locks at the right second. Answers are saved as she types
 * (debounced), on an interval, and on the way out of the page with a
 * beacon; a 409 from the server means time is up and the page reloads into
 * the closed view. Nothing lives in browser storage — a reload mid-test
 * gets its answers back from the server.
 */
const EXAM_SIT_JS = <<<'JS'
(function(){
var root=document.getElementById('exsit');if(!root)return;
var data;try{data=JSON.parse(document.getElementById('exsit-data').textContent);}catch(e){return;}
var offset=data.now*1000-Date.now();
var deadline=data.deadline*1000;
var timerEl=root.querySelector('.ex-timer'),live=root.querySelector('.ex-live'),saveEl=root.querySelector('.ex-save');
var nav=root.querySelector('.ex-nav');
var locked=false,dirty={},timers={},spent={},active=null,activeSince=null,warned5=false,warned1=false;
var qs=Array.prototype.slice.call(root.querySelectorAll('.ex-q'));
qs.forEach(function(q){var id=q.getAttribute('data-q');spent[id]=+(q.getAttribute('data-spent')||0);});
function now(){return Date.now()+offset;}
function pad(n){return (n<10?'0':'')+n;}
function fmt(ms){var s=Math.max(0,Math.ceil(ms/1000));var m=Math.floor(s/60);return pad(m)+':'+pad(s%60);}
function say(t){if(live)live.textContent=t;}
function q(id){return root.querySelector('.ex-q[data-q="'+id+'"]');}
function val(id,cls){var el=q(id).querySelector('textarea.'+cls);return el?el.value:'';}
function flagged(id){var el=q(id).querySelector('.ex-flag input');return !!(el&&el.checked);}
function answered(id){return val(id,'answer').trim()!=='';}
function tickSpent(){if(active!==null&&activeSince!==null){var t=Date.now();spent[active]+= (t-activeSince)/1000;activeSince=t;}}
function paint(){qs.forEach(function(el){var id=el.getAttribute('data-q');var pill=nav&&nav.querySelector('[data-for="'+id+'"]');
  var f=flagged(id),a=answered(id);el.classList.toggle('is-flagged',f);
  if(pill){pill.classList.toggle('answered',a);pill.classList.toggle('flagged',f);
    pill.setAttribute('aria-label','Question '+pill.textContent+(a?', answered':', not answered')+(f?', flagged':''));}});}
function payload(ids){tickSpent();return JSON.stringify({token:data.token,answers:ids.map(function(id){
  return {question_id:+id,answer:val(id,'answer'),working:val(id,'working'),flagged:flagged(id),time_spent_seconds:Math.round(spent[id]||0)};})});}
function allIds(){return qs.map(function(el){return el.getAttribute('data-q');});}
function flush(sync){var ids=Object.keys(dirty);if(!ids.length)return Promise.resolve();dirty={};
  var body=payload(ids);
  if(sync&&navigator.sendBeacon){navigator.sendBeacon(data.answerUrl,new Blob([body],{type:'application/json'}));return Promise.resolve();}
  return fetch(data.answerUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true})
    .then(function(r){if(r.status===409){lock('Time is up.');return;}return r.json().then(function(j){
      if(j&&j.server_now){offset=j.server_now*1000-Date.now();}
      if(j&&j.deadline){deadline=j.deadline*1000;}
      if(saveEl){var d=new Date();saveEl.textContent='saved '+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());}});})
    .catch(function(){ids.forEach(function(id){dirty[id]=true;});if(saveEl)saveEl.textContent='not saved — retrying';});}
function schedule(id){dirty[id]=true;paint();if(timers[id])clearTimeout(timers[id]);timers[id]=setTimeout(function(){delete timers[id];flush(false);},1500);}
function lock(msg){if(locked)return;locked=true;tickSpent();active=null;
  root.classList.add('ex-locked');if(timerEl){timerEl.textContent='00:00';timerEl.classList.add('danger');}
  say(msg||'Time is up. Your answers are being handed in.');
  var over=document.createElement('div');over.className='ex-over';over.setAttribute('role','alert');over.textContent=msg||'Time is up — pens down. Handing in…';
  root.insertBefore(over,root.firstChild.nextSibling);
  if(active!==null)dirty[active]=true;
  var ids=Object.keys(dirty);dirty={};
  var body=payload(ids);
  (ids.length?fetch(data.answerUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true}):Promise.resolve())
    .catch(function(){})
    .then(function(){return fetch(data.submitUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:data.token,by:'timer'}),keepalive:true}).catch(function(){});})
    .then(function(){setTimeout(function(){location.reload();},1200);});}
function tick(){if(locked)return;var left=deadline-now();
  if(timerEl){timerEl.textContent=fmt(left);timerEl.classList.toggle('warn',left<=5*60*1000&&left>60*1000);timerEl.classList.toggle('danger',left<=60*1000);}
  if(left<=5*60*1000&&!warned5){warned5=true;say('Five minutes left. Finish the question you are on, then check for blanks.');}
  if(left<=60*1000&&!warned1){warned1=true;say('One minute. Write something on every question — a blank scores nothing.');}
  if(left<=0){lock();}}
root.addEventListener('input',function(e){var el=e.target.closest('.ex-q');if(!el||locked)return;schedule(el.getAttribute('data-q'));});
root.addEventListener('change',function(e){var el=e.target.closest('.ex-q');if(!el||locked)return;if(e.target.matches('.ex-flag input'))schedule(el.getAttribute('data-q'));});
root.addEventListener('focusin',function(e){var el=e.target.closest('.ex-q');tickSpent();if(el){active=el.getAttribute('data-q');activeSince=Date.now();}else{active=null;activeSince=null;}});
document.addEventListener('visibilitychange',function(){if(document.hidden){tickSpent();activeSince=null;if(active!==null)dirty[active]=true;flush(true);}else if(active!==null){activeSince=Date.now();}});
window.addEventListener('pagehide',function(){tickSpent();if(active!==null)dirty[active]=true;flush(true);});
if(nav){nav.addEventListener('click',function(e){var b=e.target.closest('.ex-pill');if(!b)return;var el=q(b.getAttribute('data-for'));if(el){el.scrollIntoView({behavior:'smooth',block:'start'});var ta=el.querySelector('textarea');if(ta&&!locked)ta.focus({preventScroll:true});}});}
var finish=root.querySelector('.ex-finish form');
if(finish){finish.addEventListener('submit',function(e){if(locked){e.preventDefault();return;}
  var blanks=allIds().filter(function(id){return !answered(id);}).length;
  if(blanks>0&&!confirm(blanks+' question'+(blanks===1?' is':'s are')+' still blank. A blank scores nothing — hand in anyway?')){e.preventDefault();return;}
  e.preventDefault();locked=true;tickSpent();if(active!==null)dirty[active]=true;var ids=Object.keys(dirty);dirty={};var body=payload(ids);
  (ids.length?fetch(data.answerUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true}):Promise.resolve()).catch(function(){}).then(function(){finish.submit();});});}
/* Every 20 s: retry anything a failed save left dirty, and keep the active question's time fresh. */
setInterval(function(){if(active!==null)dirty[active]=true;flush(false);},20000);
setInterval(tick,250);
paint();tick();
})();
JS;

/** The status as a chip. */
function ex_status(string $s): string
{
    $word = ['ready' => 'waiting', 'open' => 'in progress', 'closed' => 'handed in', 'marked' => 'marked'][$s] ?? $s;
    return '<span class="ex-status ' . h($s) . '">' . h($word) . '</span>';
}

/** The names of the tracked subjects, slug → name, with a fallback to the slug. */
function ex_names(Store $store): array
{
    $names = [];
    foreach ($store->listSubjects() as $s) {
        $names[$s['slug']] = preg_replace('/^GCSE\s+/', '', (string) $s['name']) ?? $s['name'];
    }
    return $names;
}

function ex_name(array $names, string $slug): string
{
    return $names[$slug] ?? $slug;
}

function ex_head(string $title, string $sub, string $kickerHref = '/exam', string $kicker = '← Exam practice'): string
{
    return '<header><div><p class="kicker"><a href="' . h($kickerHref) . '">' . h($kicker) . '</a></p>'
        . '<h1>' . h($title) . '</h1><p><small>' . $sub . '</small></p></div></header>';
}

/** /exam — every test, newest first. */
function render_exam_index(Store $store, bool $isParent): string
{
    foreach ($store->listExamTests(['status' => 'open']) as $t) {
        $store->lazyCloseExamTest($t);
    }
    $tests = $store->listExamTests(['limit' => 60]);
    $body  = '<header><div><p class="kicker"><a href="/">← This week</a></p><h1>Exam practice</h1>'
        . '<p><small>A timed paper in exam conditions, once a week. The questions stay hidden until you press Start; '
        . 'the timer runs from then and cannot be paused.</small></p></div>'
        . ($isParent ? '<div><p class="tt-signin"><small><a href="/exam/bank">Question bank</a> · <a href="/">board</a></small></p></div>' : '')
        . '</header>';
    if (!$tests) {
        $body .= '<p class="tt-quiet">No tests yet. Dad\'s project builds one from the bank with <code>tracker_exam_schedule_test</code>.</p>';
    }
    $body .= '<div class="ex-list">';
    foreach ($tests as $t) {
        $sub = [];
        $sub[] = $t['question_count'] . ' question' . ($t['question_count'] === 1 ? '' : 's');
        $sub[] = exam_marks_word($t['marks']);
        $sub[] = exam_minutes_word($t['duration_minutes']);
        if ($t['started_at']) {
            [$d, $at] = tt_local((string) $t['started_at']);
            $sub[] = 'sat ' . $at . ($t['sat_minutes'] !== null ? ', ' . $t['sat_minutes'] . ' min' : '');
        }
        $body .= '<a class="item" href="/exam/' . (int) $t['id'] . '" style="text-decoration:none">'
            . '<div class="grow"><strong>' . h($t['name']) . '</strong> ' . ex_status($t['status'])
            . '<div><small>' . h($t['scheduled_for']) . ' · ' . h(implode(' · ', $sub)) . '</small></div></div>'
            . '<div class="num"><small>' . ($t['status'] === 'ready' ? 'open it →' : ($t['status'] === 'marked' ? 'see marks →' : '→')) . '</small></div>'
            . '</a>';
    }
    $body .= '</div>';
    return dash_shell('Exam practice', $body);
}

/**
 * /exam/{id} — one test, in whichever state it is in. The student's view is
 * built from exam_student_view(); the parent's from the whole record.
 */
function render_exam_test(Store $store, array $test, bool $isParent): string
{
    $names = ex_names($store);
    $view  = $isParent ? $test : exam_student_view($test);
    return match ($test['status']) {
        'ready'  => ex_render_front($store, $view, $names, $isParent),
        'open'   => ex_render_sitting($view, $names, $isParent),
        default  => ex_render_after($store, $view, $names, $isParent),
    };
}

/** Before Start: the shape of the paper and one button. No question text. */
function ex_render_front(Store $store, array $t, array $names, bool $isParent): string
{
    $today   = tt_today();
    $canSit  = $t['scheduled_for'] === $today || $isParent;
    $body    = ex_head($t['name'], h($t['scheduled_for']) . ' · ' . ex_status($t['status']) . ' · '
        . h(exam_minutes_word($t['duration_minutes'])) . ' · ' . h(exam_marks_word($t['marks'])));
    $body .= '<div class="ex-front"><h2>Before you start</h2><ul>';
    foreach ($t['sections'] as $s) {
        $tot = exam_section_totals($s);
        $body .= '<li><b>' . h(ex_name($names, $s['subject'])) . '</b> — ' . $tot['questions'] . ' question'
            . ($tot['questions'] === 1 ? '' : 's') . ', ' . h(exam_marks_word($tot['max']))
            . ($tot['minutes'] !== null ? ', about ' . (int) $tot['minutes'] . ' min' : '') . '</li>';
    }
    $body .= '</ul>';
    if ($t['instructions']) {
        $body .= '<div class="instr">' . h((string) $t['instructions']) . '</div>';
    }
    $body .= '<ul><li>The timer starts when you press <b>Start</b> and runs for '
        . h(exam_minutes_word($t['duration_minutes'])) . '. It cannot be paused.</li>'
        . '<li>When it reaches zero the paper is handed in as it stands. If you close the page by mistake, open it again — the timer keeps running and your answers are kept.</li>'
        . '<li>Answers save as you type. Flag a question to come back to it; never leave one blank.</li></ul>';
    if ($canSit) {
        $body .= '<form method="post" action="/exam/' . (int) $t['id'] . '/start">'
            . '<button type="submit" class="ex-start">Start — ' . h(exam_minutes_word($t['duration_minutes'])) . '</button></form>';
    } else {
        $body .= '<p class="ex-wait">This paper is for <b>' . h(tt_pretty($t['scheduled_for'])) . '</b>. It opens on the day.</p>';
    }
    $body .= '</div>';
    return dash_shell($t['name'] . ' — exam practice', $body);
}

/** The sitting: questions, answer boxes, the timer, the nav strip. */
function ex_render_sitting(array $t, array $names, bool $isParent): string
{
    $id   = (int) $t['id'];
    $data = [
        'now'       => exam_now(),
        'deadline'  => exam_epoch($t['deadline_at']),
        'token'     => (string) ($t['sit_token'] ?? ''),
        'testId'    => $id,
        'answerUrl' => "/exam/$id/answer",
        'submitUrl' => "/exam/$id/submit",
    ];
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    [$d, $at] = tt_local((string) $t['started_at']);
    $body = ex_head($t['name'], 'Started ' . h($at) . ' · ends ' . h(tt_local((string) $t['deadline_at'])[1])
        . ' · ' . h(exam_marks_word($t['marks'])), '/exam', '← Exam practice');
    $body .= '<div id="exsit"><div class="ex-bar"><div class="ex-timer" role="timer" aria-live="off">--:--</div>'
        . '<div class="ex-live" aria-live="polite">Answers save as you type.</div><span class="ex-save"></span></div>';
    $body .= '<div class="ex-nav" aria-label="Questions">';
    foreach ($t['sections'] as $s) {
        foreach ($s['questions'] as $q) {
            $body .= '<button type="button" class="ex-pill" data-for="' . (int) $q['id'] . '">' . h($q['label']) . '</button>';
        }
    }
    $body .= '</div>';
    if ($t['instructions']) {
        $body .= '<div class="flag">' . h((string) $t['instructions']) . '</div>';
    }
    foreach ($t['sections'] as $s) {
        $tot = exam_section_totals($s);
        $body .= '<section class="ex-section"><h2>' . h(ex_name($names, $s['subject']))
            . ' <small>' . h(exam_marks_word($tot['max']))
            . ($tot['minutes'] !== null ? ' · about ' . (int) $tot['minutes'] . ' min' : '') . '</small></h2>';
        $working = in_array($s['subject'], EXAM_WORKING_SUBJECTS, true);
        foreach ($s['questions'] as $q) {
            $a   = $q['answer'];
            $qid = (int) $q['id'];
            $body .= '<div class="ex-q' . (!empty($a['flagged']) ? ' is-flagged' : '') . '" data-q="' . $qid . '" data-spent="'
                . (int) ($a['time_spent_seconds'] ?? 0) . '" id="q' . $qid . '">'
                . '<div class="ex-qhead"><span class="qn">' . h($q['label']) . '</span><span class="qm">'
                . h(exam_marks_word($q['marks'])) . ($q['calculator'] ? ' · calculator' : '')
                . ($q['time_guide_seconds'] ? ' · about ' . max(1, (int) round($q['time_guide_seconds'] / 60)) . ' min' : '')
                . '</span></div>'
                . '<div class="ex-text">' . exam_md($q['question_md']) . '</div>';
            if ($working) {
                $body .= '<label for="w' . $qid . '">Working</label>'
                    . '<textarea class="working" id="w' . $qid . '" name="working[' . $qid . ']" rows="4">' . h((string) ($a['working'] ?? '')) . '</textarea>';
            }
            $body .= '<label for="a' . $qid . '">Answer</label>'
                . '<textarea class="answer" id="a' . $qid . '" name="answer[' . $qid . ']" rows="3">' . h((string) ($a['answer'] ?? '')) . '</textarea>'
                . '<label class="ex-flag"><input type="checkbox" name="flag[' . $qid . ']"' . (!empty($a['flagged']) ? ' checked' : '')
                . '> Flag — come back to this one</label>';
            if ($isParent) {
                $body .= '<div class="ex-parent"><b>Mark scheme</b><div class="ex-text">' . exam_md($q['mark_scheme_md'] ?? '') . '</div>'
                    . (!empty($q['model_answer_md']) ? '<b>Model answer</b><div class="ex-text">' . exam_md($q['model_answer_md']) . '</div>' : '')
                    . '</div>';
            }
            $body .= '</div>';
        }
        $body .= '</section>';
    }
    $body .= '<div class="ex-finish"><form method="post" action="/exam/' . $id . '/submit">'
        . '<input type="hidden" name="token" value="' . h($data['token']) . '"><input type="hidden" name="by" value="student">'
        . '<p>Finished early? Check every question has something written, then hand in. There is no getting it back.</p>'
        . '<button type="submit">Hand in now</button></form></div>';
    $body .= '<script type="application/json" id="exsit-data">' . $json . '</script>'
        . '<script>' . EXAM_SIT_JS . '</script></div>';
    return dash_shell($t['name'] . ' — sitting', $body);
}

/** After the sitting: handed in, or marked. */
function ex_render_after(Store $store, array $t, array $names, bool $isParent): string
{
    $id     = (int) $t['id'];
    $marked = $t['status'] === 'marked';
    [$d, $at] = tt_local((string) $t['started_at']);
    $sub = h($d) . ' · ' . ex_status($t['status']) . ' · sat ' . h((string) ($t['sat_minutes'] ?? $t['duration_minutes']))
        . ' of ' . (int) $t['duration_minutes'] . ' min'
        . ($t['closed_by'] === 'timer' ? ' · time ran out' : ($t['closed_by'] === 'student' ? ' · handed in early' : ''));
    $body = ex_head($t['name'], $sub);

    $totalScore = 0.0;
    $totalMax   = 0.0;
    $blanks     = 0;
    foreach ($t['sections'] as $s) {
        $tot = exam_section_totals($s);
        $totalMax   += $tot['max'];
        $blanks     += $tot['blanks'];
        $totalScore += $tot['score'] ?? 0;
    }
    if (!$marked) {
        $body .= '<div class="flag">Handed in. Tell Claude in your exam-practice project that it is <b>ready for marking</b>; '
            . 'the marks and feedback will appear here once it is marked.'
            . ($blanks ? ' <b>' . $blanks . ' question' . ($blanks === 1 ? ' was' : 's were') . ' left blank.</b>' : ' Nothing was left blank.')
            . '</div>';
    } else {
        $body .= '<div class="ex-totals"><div class="card"><p class="label">Whole paper</p><p class="big">'
            . h(num($totalScore)) . '<small> / ' . h(num($totalMax)) . '</small></p></div>';
        foreach ($t['sections'] as $s) {
            $tot = exam_section_totals($s);
            $body .= '<div class="card"><p class="label">' . h(ex_name($names, $s['subject'])) . '</p><p class="big">'
                . h(num((float) $tot['score'])) . '<small> / ' . h(num($tot['max'])) . '</small></p>'
                . ($tot['blanks'] ? '<small>' . $tot['blanks'] . ' blank</small>' : '') . '</div>';
        }
        $body .= '</div>';
        if ($t['note'] && $isParent) {
            $body .= '<div class="flag">' . h((string) $t['note']) . '</div>';
        }
    }

    foreach ($t['sections'] as $s) {
        $tot = exam_section_totals($s);
        $body .= '<section class="ex-section"><h2>' . h(ex_name($names, $s['subject']))
            . ' <small>' . h(exam_marks_word($tot['max'])) . '</small></h2>';
        $attemptId = null;
        foreach ($s['questions'] as $q) {
            $a     = $q['answer'];
            $blank = $a === null || exam_is_blank($a['answer']);
            $body .= '<div class="ex-q" id="q' . (int) $q['id'] . '"><div class="ex-qhead"><span class="qn">' . h($q['label']) . '</span>';
            if ($marked && $a !== null && isset($a['score'])) {
                $lost  = (float) $q['marks'] - (float) $a['score'];
                $klass = $lost <= 0 ? 'full' : ((float) $a['score'] > 0 ? 'part' : 'none');
                $body .= '<span class="ex-score ' . $klass . '">' . h(num((float) $a['score'])) . ' / ' . (int) $q['marks'] . '</span>';
            } else {
                $body .= '<span class="qm">' . h(exam_marks_word($q['marks'])) . '</span>';
            }
            $body .= '</div><div class="ex-text">' . exam_md($q['question_md']) . '</div>';
            if (!$blank) {
                $body .= '<div class="ex-ans">' . h((string) $a['answer']) . '</div>';
            } else {
                $body .= '<div class="ex-ans blank">Left blank</div>';
            }
            if ($a !== null && !exam_is_blank($a['working'] ?? null)) {
                $body .= '<div class="ex-ans working">' . h((string) $a['working']) . '</div>';
            }
            if ($a !== null) {
                $body .= '<div class="ex-meta">' . (int) round($a['time_spent_seconds'] / 60) . ' min on this question'
                    . ($a['flagged'] ? ' · flagged' : '') . '</div>';
            }
            if ($marked && $a !== null && !empty($a['student_feedback'])) {
                $body .= '<div class="ex-fb">' . h((string) $a['student_feedback']) . '</div>';
            }
            if ($isParent) {
                $body .= '<div class="ex-parent"><b>Mark scheme</b><div class="ex-text">' . exam_md($q['mark_scheme_md'] ?? '') . '</div>';
                if (!empty($q['model_answer_md'])) {
                    $body .= '<b>Model answer</b><div class="ex-text">' . exam_md($q['model_answer_md']) . '</div>';
                }
                if ($marked && !empty($a['marker_note'])) {
                    $body .= '<b>Marker\'s note</b>' . h((string) $a['marker_note']);
                }
                $body .= '<b>Bank</b>#' . (int) $q['id'] . ' · ' . h(implode('/', $q['topic_refs']))
                    . ($q['paper_style'] ? ' · ' . h($q['paper_style']) : '')
                    . (!empty($q['source_note']) ? ' · ' . h((string) $q['source_note']) : '') . '</div>';
            }
            if ($marked && $a !== null && $a['attempt_question_id'] !== null && $attemptId === null) {
                $attemptId = $store->attemptIdOfQuestion((int) $a['attempt_question_id']);
            }
            $body .= '</div>';
        }
        if ($attemptId !== null) {
            $body .= '<p><small><a href="/s/' . h($s['subject']) . '/a/' . $attemptId . '">This section on the '
                . h(ex_name($names, $s['subject'])) . ' record</a></small></p>';
        }
        $body .= '</section>';
    }
    return dash_shell($t['name'] . ' — exam practice', $body);
}

/** /exam/bank — parent only, every question with its scheme. */
function render_exam_bank(Store $store, array $query): string
{
    $names  = ex_names($store);
    $filter = [
        'subject' => isset($query['subject']) && $query['subject'] !== '' ? (string) $query['subject'] : null,
        'status'  => isset($query['status']) && in_array($query['status'], EXAM_QUESTION_STATUSES, true) ? (string) $query['status'] : null,
        'tag'     => isset($query['tag']) && $query['tag'] !== '' ? (string) $query['tag'] : null,
    ];
    $rows = $store->listExamQuestions($filter);
    $all  = $store->listExamQuestions();
    $counts = [];
    foreach ($all as $q) {
        $counts[$q['status']] = ($counts[$q['status']] ?? 0) + 1;
    }
    $body = '<header><div><p class="kicker"><a href="/exam">← Exam practice</a></p><h1>Question bank</h1>'
        . '<p><small>' . count($all) . ' question' . (count($all) === 1 ? '' : 's') . ' · '
        . h(implode(' · ', array_map(static fn($k, $v) => "$v $k", array_keys($counts), $counts)))
        . '. Parent only: nothing here is shown to her until she has sat it.</small></p></div>'
        . '<div>' . tt_parent_line($store, true, '/exam/bank') . '</div></header>';
    $body .= '<form class="filters" method="get" action="/exam/bank">'
        . '<label>Subject<select name="subject"><option value="">all</option>';
    foreach ($names as $slug => $name) {
        if ($slug === EXAM_SUBJECT) {
            continue;
        }
        $body .= '<option value="' . h($slug) . '"' . ($filter['subject'] === $slug ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    $body .= '</select></label><label>Status<select name="status"><option value="">all</option>';
    foreach (EXAM_QUESTION_STATUSES as $st) {
        $body .= '<option value="' . h($st) . '"' . ($filter['status'] === $st ? ' selected' : '') . '>' . h($st) . '</option>';
    }
    $body .= '</select></label><label>Tag<input type="text" name="tag" value="' . h((string) ($filter['tag'] ?? '')) . '"></label>'
        . '<button type="submit">Filter</button></form>';
    if (!$rows) {
        $body .= '<p class="tt-quiet">No questions match.</p>';
    }
    $body .= '<div class="ex-bank">';
    foreach ($rows as $q) {
        $body .= '<div class="ex-q" id="q' . (int) $q['id'] . '"><div class="ex-qhead"><span class="qn">#' . (int) $q['id']
            . ' · ' . h(ex_name($names, $q['subject_slug'])) . ' ' . ex_status($q['status']) . '</span>'
            . '<span class="qm">' . h(exam_marks_word($q['marks'])) . ' · ' . h(implode('/', $q['topic_refs']))
            . ($q['paper_style'] ? ' · ' . h($q['paper_style']) : '') . ($q['calculator'] ? ' · calculator' : '')
            . ($q['command_word'] ? ' · ' . h($q['command_word']) : '') . '</span></div>'
            . '<div class="ex-text">' . exam_md($q['question_md']) . '</div>'
            . '<details><summary>Mark scheme' . (!empty($q['model_answer_md']) ? ' and model answer' : '') . '</summary>'
            . '<div class="ex-parent"><b>Mark scheme</b><div class="ex-text">' . exam_md($q['mark_scheme_md']) . '</div>'
            . (!empty($q['model_answer_md']) ? '<b>Model answer</b><div class="ex-text">' . exam_md($q['model_answer_md']) . '</div>' : '')
            . '</div></details>'
            . '<div class="ex-meta">' . h($q['client_key']) . ($q['tags'] ? ' · ' . h(implode(', ', $q['tags'])) : '')
            . (!empty($q['source_note']) ? ' · ' . h((string) $q['source_note']) : '')
            . (!empty($q['note']) ? ' · ' . h(str_replace("\n", ' / ', (string) $q['note'])) : '') . '</div></div>';
    }
    $body .= '</div>';
    return dash_shell('Question bank', $body);
}

/** A refused student write, as a page rather than a bare JSON error. */
function render_exam_locked(int $id, string $why): string
{
    $body = ex_head('Not accepted', h($why))
        . '<p><a href="/exam/' . $id . '">Back to the test</a></p>';
    return dash_shell('Exam practice', $body);
}
