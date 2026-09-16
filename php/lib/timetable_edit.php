<?php
/**
 * The timetable editor: the board's blocks, moved by hand.
 *
 * Every re-cut of the week used to go through the chat — describe the change,
 * have the diff read back, have it written. That is right for a new shape and
 * wrong for "swap those two" or "make Maths fifteen minutes longer". So the
 * parent gets a page that looks like the board, where a block can be dragged
 * to another slot or another day, and its length changed with a stepper. The
 * day closes up behind every move, from the time in its header, and no block
 * ever loses a minute.
 *
 * What the page writes is deliberately narrow: a day and two times per block,
 * through Store::retimeTimetable(), which keeps every other field from the
 * version being edited and refuses to add or drop a block. Adding, dropping
 * and re-labelling stay with tracker_set_timetable, where the whole shape is
 * stated and the diff is echoed first. The editor shows its own diff before
 * the save for the same reason.
 *
 * Parent-only, like every other write on the board: the page needs the
 * cookie and the save needs the CSRF token it carries.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const TT_EDIT_CSS = <<<'CSS'
.tt-edit-intro{margin:.9rem 0 0;font-size:.9rem;color:#57534e;max-width:52rem}
.tt-edit-from{display:flex;flex-wrap:wrap;gap:.4rem .8rem;align-items:baseline;margin:.8rem 0 0;font-size:.88rem}
.tt-edit-from select{font:inherit;font-size:.86rem;border:1px solid var(--line);border-radius:6px;padding:.15rem .3rem;background:#fff}
.tt-edit-from button{font:inherit;font-size:.8rem;border:1px solid var(--line);border-radius:6px;background:#fff;padding:.1rem .5rem}
.tt-edit-from .vnote{color:var(--muted);font-size:.8rem}
.tt-edit.is-dragging{scroll-snap-type:none}
.tt-edit .wkcol{min-height:9rem;display:flex;flex-direction:column}
.tt-edit .dhead{align-items:center}
.tt-edit .daystart{margin-left:auto;font-size:.72rem;color:var(--muted);white-space:nowrap}
.tt-edit .daystart input{font:inherit;font-size:.74rem;border:1px solid var(--line);border-radius:5px;
  padding:.02rem .2rem;margin-left:.2rem;background:#fff;color:var(--ink)}
.ttlist{flex:1;min-height:2.5rem;display:flex;flex-direction:column;border-radius:6px}
.ttlist:empty{border:1px dashed var(--line)}
.tt-edit .dayend{font-size:.72rem;color:var(--muted);text-align:right;margin:.35rem .15rem 0;
  font-variant-numeric:tabular-nums}
.eblk{flex-wrap:wrap;row-gap:.15rem;cursor:grab;user-select:none;-webkit-user-select:none;
  background:#fff;border:1px solid #ededea;border-left:3px solid var(--acc,#a8a29e);border-radius:0 6px 6px 0;
  padding:.28rem .35rem .3rem .3rem;margin:.16rem 0}
.eblk.k-break,.eblk.k-lunch,.eblk.k-group,.eblk.k-movement{background:#f7f6f2;border-left-style:dotted}
.eblk.k-break .bl{color:#57534e;font-size:.8rem}
.eblk .bt{width:auto;font-size:.74rem;white-space:nowrap}
.eblk .bl{flex:1 1 6rem;-webkit-line-clamp:3}
.eblk.moved{box-shadow:inset 0 0 0 1px #7c3aed,0 0 0 1px rgba(124,58,237,.25)}
.eblk.late{background:rgba(220,38,38,.08)}
.eblk.dragging{opacity:.5;pointer-events:none;box-shadow:0 10px 20px -10px rgba(28,25,23,.7);cursor:grabbing}
.ehandle{flex:none;font:inherit;font-size:.95rem;line-height:1;color:#a8a29e;background:none;border:0;
  padding:.1rem .15rem;cursor:grab;touch-action:none;border-radius:4px;letter-spacing:-.1em}
.ehandle:hover{color:var(--ink)}
.ehandle:focus-visible{outline:2px solid #7c3aed;outline-offset:1px}
.etools{display:flex;align-items:center;gap:.2rem;margin-left:auto;flex:none}
.elen{display:inline-flex;align-items:center;gap:.1rem}
.elen input{width:3.2rem;font:inherit;font-size:.74rem;text-align:right;padding:.05rem .2rem;
  border:1px solid var(--line);border-radius:5px;background:#fff;color:var(--ink);
  font-variant-numeric:tabular-nums;-moz-appearance:textfield}
.elen input::-webkit-outer-spin-button,.elen input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.elen button{font:inherit;font-size:.9rem;line-height:1;width:1.4rem;height:1.4rem;padding:0;
  border:1px solid var(--line);border-radius:5px;background:#fff;cursor:pointer;color:var(--ink)}
.elen button:hover{border-color:#292524}
.elen .emin{font-size:.66rem;color:var(--muted);margin-left:.1rem}
.eday{font:inherit;font-size:.72rem;border:1px solid var(--line);border-radius:5px;padding:.05rem .1rem;
  background:#fff;color:var(--ink)}
.tt-edit-bar{position:sticky;bottom:.5rem;background:var(--card);border:1px solid var(--line);border-radius:10px;
  padding:.7rem 1rem .8rem;margin-top:1rem;box-shadow:0 6px 18px -10px rgba(28,25,23,.55)}
.tt-edit-changes{margin:0;font-size:.85rem;color:#57534e}
.tt-edit-changes p{margin:0}
.tt-edit-changes ul{margin:.25rem 0 .2rem;padding-left:1.1rem;max-height:9rem;overflow:auto}
.tt-edit-changes li{margin:.1rem 0}
.tt-edit-changes .flag{margin-top:.5rem}
.tt-edit-actions{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:.6rem}
.tt-edit-actions button{font:inherit;font-size:.86rem;border:1px solid #292524;border-radius:7px;padding:.3rem .8rem;
  background:#292524;color:#fff;cursor:pointer}
.tt-edit-actions button:disabled{background:#e7e5e4;border-color:#e7e5e4;color:#78716c;cursor:default}
.tt-edit-actions button.tt-edit-reset{background:#fff;color:var(--ink);border-color:var(--line)}
.tt-edit-actions button.tt-edit-reset:hover{border-color:#292524}
.tt-edit-result{margin:.5rem 0 0;font-size:.86rem}
.tt-edit-result.ok{color:#065f46}
.tt-edit-result.err{color:#991b1b}
.tt-edit-nojs{margin:.8rem 0 0}
@media (max-width:40rem){
  .tt-edit .wkcol{flex:0 0 86%}
}
CSS;

/**
 * Everything the editor does happens in the DOM until Save: the chips are the
 * state, their data-* attributes the times, and one reflow per touched day
 * keeps them honest. Nothing here writes to the record except the one POST.
 */
const TT_EDIT_JS = <<<'JS'
(function(){
var root=document.getElementById('ttedit');if(!root)return;
var bar=document.getElementById('ttedit-bar');
var saveBtn=bar.querySelector('.tt-edit-save'),resetBtn=bar.querySelector('.tt-edit-reset'),
    changes=bar.querySelector('.tt-edit-changes'),result=bar.querySelector('.tt-edit-result');
var DAYS=['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
var dirty=false;
function mins(s){var p=s.split(':');return p[0]*60+ +p[1];}
function hhmm(m){var h=Math.floor(m/60),n=m%60;return (h<10?'0':'')+h+':'+(n<10?'0':'')+n;}
function cols(){return [].slice.call(root.querySelectorAll('.wkcol'));}
function chips(col){return [].slice.call(col.querySelectorAll('.eblk'));}
function colOf(el){return el.closest('.wkcol');}
function colFor(day){return root.querySelector('.wkcol[data-day="'+day+'"]');}
function changed(c){
  return c.getAttribute('data-day')!==c.getAttribute('data-day0')
    ||c.getAttribute('data-start')!==c.getAttribute('data-start0')
    ||c.getAttribute('data-end')!==c.getAttribute('data-end0');
}

/* One day, back to back from the time in its header. Every block keeps its
   length; only where it sits changes. A day that would run past midnight is
   flagged rather than wrapped. */
function reflow(col){
  var inp=col.querySelector('.daystart input');
  var t=mins(inp.value||inp.defaultValue||'09:00');
  var day=col.getAttribute('data-day'),list=chips(col);
  list.forEach(function(c){
    var len=+c.getAttribute('data-min'),s=t,e=t+len;t=e;
    c.setAttribute('data-day',day);c.setAttribute('data-start',hhmm(s));
    c.setAttribute('data-end',e>1439?'24:00':hhmm(e));
    c.classList.toggle('late',e>1439);
    c.querySelector('.bt').textContent=hhmm(s)+'–'+(e>1439?'--:--':hhmm(e));
    c.querySelector('.eday').value=day;
    c.classList.toggle('moved',changed(c));
  });
  col.querySelector('.dayend').textContent=list.length?'ends '+hhmm(Math.min(t,1439)):'no blocks';
}

/* The diff, before the save, in the same words the tool would use. */
function review(){
  var lines=[],late=false;
  root.querySelectorAll('.eblk').forEach(function(c){
    if(c.classList.contains('late'))late=true;
    if(!changed(c))return;
    lines.push(c.querySelector('.bl').textContent.trim()+': '
      +DAYS[c.getAttribute('data-day0')]+' '+c.getAttribute('data-start0')+'–'+c.getAttribute('data-end0')
      +' → '+DAYS[c.getAttribute('data-day')]+' '+c.getAttribute('data-start')+'–'+c.getAttribute('data-end'));
  });
  dirty=lines.length>0;
  changes.textContent='';
  if(!lines.length){changes.textContent='No changes yet.';}
  else{
    var p=document.createElement('p');
    p.textContent=lines.length+(lines.length===1?' block changes:':' blocks change:');
    var ul=document.createElement('ul');
    lines.forEach(function(l){var li=document.createElement('li');li.textContent=l;ul.appendChild(li);});
    changes.appendChild(p);changes.appendChild(ul);
  }
  if(late){
    var w=document.createElement('p');w.className='flag';
    w.textContent='A day runs past midnight. Shorten something, or start it earlier, before saving.';
    changes.appendChild(w);
  }
  saveBtn.disabled=!dirty||late;
  if(dirty){result.className='tt-edit-result';result.textContent='';}
}

function moveTo(chip,col,before){
  var from=colOf(chip),list=col.querySelector('.ttlist');
  list.insertBefore(chip,before||null);
  reflow(from);if(col!==from)reflow(col);
}

/* Dragging, with pointer events so a finger works as well as a mouse. With a
   mouse any part of the chip is a handle; on touch only the grip is, so the
   page can still be scrolled by a finger on a chip. */
var drag=null;
root.addEventListener('pointerdown',function(e){
  var chip=e.target.closest('.eblk');
  if(!chip||(e.button!==undefined&&e.button!==0))return;
  if(e.target.closest('input,select,a')||(e.target.closest('button')&&!e.target.closest('.ehandle')))return;
  if(e.pointerType!=='mouse'&&!e.target.closest('.ehandle'))return;
  drag={chip:chip,x:e.clientX,y:e.clientY,active:false,id:e.pointerId};
  try{chip.setPointerCapture(e.pointerId);}catch(err){}
});
root.addEventListener('pointermove',function(e){
  if(!drag||e.pointerId!==drag.id)return;
  if(!drag.active){
    if(Math.abs(e.clientX-drag.x)+Math.abs(e.clientY-drag.y)<5)return;
    drag.active=true;drag.chip.classList.add('dragging');root.classList.add('is-dragging');
  }
  e.preventDefault();
  var under=document.elementFromPoint(e.clientX,e.clientY);if(!under)return;
  var target=under.closest('.eblk'),col=under.closest('.wkcol');
  if(target&&target!==drag.chip){
    var r=target.getBoundingClientRect();
    moveTo(drag.chip,colOf(target),e.clientY<r.top+r.height/2?target:target.nextElementSibling);
  }else if(col&&!target){
    var cs=chips(col);
    if(!cs.length||e.clientY>cs[cs.length-1].getBoundingClientRect().bottom)moveTo(drag.chip,col,null);
    else if(e.clientY<cs[0].getBoundingClientRect().top)moveTo(drag.chip,col,cs[0]);
  }
});
function endDrag(e){
  if(!drag||e.pointerId!==drag.id)return;
  drag.chip.classList.remove('dragging');root.classList.remove('is-dragging');
  if(drag.active)review();
  drag=null;
}
root.addEventListener('pointerup',endDrag);
root.addEventListener('pointercancel',endDrag);

/* The grip takes the arrow keys: up and down within the day, left and right
   to the day beside it. */
root.addEventListener('keydown',function(e){
  var h=e.target.closest('.ehandle');if(!h)return;
  var chip=h.closest('.eblk'),col=colOf(chip),k=e.key;
  if(k==='ArrowUp'||k==='ArrowDown'){
    var sib=k==='ArrowUp'?chip.previousElementSibling:chip.nextElementSibling;
    if(!sib)return;e.preventDefault();
    moveTo(chip,col,k==='ArrowUp'?sib:sib.nextElementSibling);
  }else if(k==='ArrowLeft'||k==='ArrowRight'){
    var all=cols(),i=all.indexOf(col)+(k==='ArrowLeft'?-1:1);
    if(i<0||i>=all.length)return;e.preventDefault();
    moveTo(chip,all[i],null);
  }else return;
  h.focus();review();
});

/* Length: the stepper and the number itself. Never below five minutes. */
function setLen(chip,n){
  n=Math.round(+n);if(!(n>=5))n=5;if(n>600)n=600;
  chip.setAttribute('data-min',String(n));chip.querySelector('.elen input').value=n;
  reflow(colOf(chip));review();
}
root.addEventListener('click',function(e){
  var b=e.target.closest('.eless,.emore');if(!b)return;
  var chip=b.closest('.eblk');
  setLen(chip,+chip.getAttribute('data-min')+(b.classList.contains('emore')?5:-5));
});
root.addEventListener('change',function(e){
  var t=e.target;
  if(t.matches('.elen input')){setLen(t.closest('.eblk'),t.value);}
  else if(t.matches('.daystart input')){if(!t.value)t.value=t.defaultValue;reflow(colOf(t));review();}
  else if(t.matches('.eday')){var chip=t.closest('.eblk'),col=colFor(t.value);if(col&&col!==colOf(chip)){moveTo(chip,col,null);review();}}
});

saveBtn.addEventListener('click',function(){
  var blocks=[].map.call(root.querySelectorAll('.eblk'),function(c){
    return {block_key:+c.getAttribute('data-key'),weekday:+c.getAttribute('data-day'),
      start:c.getAttribute('data-start'),end:c.getAttribute('data-end')};
  });
  saveBtn.disabled=true;result.className='tt-edit-result';result.textContent='Saving…';
  fetch('/tt/edit',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:root.getAttribute('data-csrf'),valid_from:root.getAttribute('data-from'),blocks:blocks})})
  .then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j};},function(){return {ok:false,j:null};});})
  .then(function(x){
    if(!x.ok){
      result.className='tt-edit-result err';
      result.textContent='Not saved. '+((x.j&&x.j.error_description)||'The server refused the request.');
      saveBtn.disabled=false;return;
    }
    root.querySelectorAll('.eblk').forEach(function(c){
      ['day','start','end'].forEach(function(k){c.setAttribute('data-'+k+'0',c.getAttribute('data-'+k));});
      c.classList.remove('moved');
    });
    dirty=false;review();
    var n=((x.j.diff||{}).changed||[]).length;
    result.className='tt-edit-result ok';
    result.textContent='Saved as version '+x.j.version_id+', in force from '+x.j.valid_from_pretty+'. '
      +n+(n===1?' block changed. ':' blocks changed. ');
    var a=document.createElement('a');a.href=root.getAttribute('data-next');a.textContent='Back to the board';
    result.appendChild(a);
  })
  .catch(function(){result.className='tt-edit-result err';result.textContent='Not saved: the request did not get through. Try again.';saveBtn.disabled=false;});
});
resetBtn.addEventListener('click',function(){
  if(!dirty||window.confirm('Drop every unsaved change?')){dirty=false;window.location.reload();}
});
window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue='';}});
review();
})();
JS;

/**
 * The Monday a board edit takes effect from. Only this week's or a later one:
 * a version starting in a week already gone would re-judge weeks that have
 * been reviewed and written about, and that is not a thing a drag should do.
 * Anything else — a mid-week date, a past date, nonsense — is this Monday.
 */
function tt_edit_from(mixed $from): string
{
    $thisMon = tt_monday(tt_today());
    $f = (string) ($from ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || $f < $thisMon) {
        return $thisMon;
    }
    try {
        return tt_monday($f) === $f ? $f : $thisMon;
    } catch (Throwable) {
        return $thisMon;
    }
}

/** One block as an editable chip. The times are data; the script re-derives them. */
function tt_edit_chip(array $b): string
{
    $mins  = tt_mins($b['end']) - tt_mins($b['start']);
    $label = $b['label'];
    $days  = '';
    foreach (TIMETABLE_DAYS as $n => $name) {
        $days .= '<option value="' . $n . '"' . ($n === (int) $b['weekday'] ? ' selected' : '') . '>'
            . substr($name, 0, 3) . '</option>';
    }
    return '<div class="blk eblk k-' . h($b['kind']) . '" data-key="' . (int) $b['block_key'] . '"'
        . ' data-min="' . $mins . '"'
        . ' data-day="' . (int) $b['weekday'] . '" data-day0="' . (int) $b['weekday'] . '"'
        . ' data-start="' . h($b['start']) . '" data-start0="' . h($b['start']) . '"'
        . ' data-end="' . h($b['end']) . '" data-end0="' . h($b['end']) . '"'
        . ' style="--acc:' . h(tt_accent($b['subjects'])) . '">'
        . '<button type="button" class="ehandle" aria-label="' . h("Move $label. Up and down arrows move it "
            . 'within the day; left and right move it to the day beside.') . '">&#8942;&#8942;</button>'
        . '<span class="bt mono">' . h($b['start']) . '&ndash;' . h($b['end']) . '</span>'
        . '<span class="bl">' . h($label) . '</span>'
        . '<span class="etools">'
        . '<span class="elen">'
        . '<button type="button" class="eless" aria-label="' . h("$label: five minutes shorter") . '">&minus;</button>'
        . '<input type="number" min="5" max="600" step="5" value="' . $mins . '" '
        . 'aria-label="' . h("Length of $label in minutes") . '">'
        . '<button type="button" class="emore" aria-label="' . h("$label: five minutes longer") . '">+</button>'
        . '<span class="emin">min</span></span>'
        . '<select class="eday" aria-label="' . h("Day for $label") . '">' . $days . '</select>'
        . '</span></div>';
}

/**
 * The editor page. `$from` is the Monday the new version will run from and
 * the date whose version is edited — the same date, so what is shown is
 * exactly what the save will be a change to.
 */
function render_timetable_editor(Store $store, string $from): string
{
    $selfPath = '/tt/edit?from=' . $from;
    $thisMon  = tt_monday(tt_today());
    $nextMon  = tt_add_days($thisMon, 7);
    $version  = $store->timetableVersionOn($from);

    $header = '<header><div><p class="kicker">Study tracker</p><h1>Edit the timetable</h1></div>'
        . '<div>' . tt_parent_line($store, true, $selfPath) . '</div></header>';

    if (!$version) {
        return dash_shell('Edit the timetable', $header
            . '<p class="tt-quiet">No timetable is in force on ' . h(tt_pretty($from))
            . '. Set one with <code>tracker_set_timetable</code> first; this page only moves '
            . 'blocks that already exist.</p><p><a href="/">Back to the board</a></p>',
            '<style>' . TT_EDIT_CSS . '</style>');
    }

    $blocks = $store->timetableBlocks((int) $version['id']);
    $byDay  = array_fill_keys(array_keys(TIMETABLE_DAYS), []);
    foreach ($blocks as $b) {
        $byDay[(int) $b['weekday']][] = $b;
    }

    // Which Monday the save runs from. Two choices cover nearly every edit;
    // a further Monday reached by the URL keeps its own option.
    $options = [$thisMon => 'this week, from Mon ' . tt_short_date($thisMon),
                $nextMon => 'next week, from Mon ' . tt_short_date($nextMon)];
    if (!isset($options[$from])) {
        $options[$from] = 'from Mon ' . tt_short_date($from);
    }
    $select = '<form class="tt-edit-from" method="get" action="/tt/edit">'
        . '<label>Save the change <select name="from" onchange="this.form.submit()">';
    foreach ($options as $date => $text) {
        $select .= '<option value="' . h($date) . '"' . ($date === $from ? ' selected' : '') . '>'
            . h($text) . '</option>';
    }
    $select .= '</select></label><noscript><button type="submit">change</button></noscript>'
        . '<span class="vnote">Editing version ' . (int) $version['id'] . ', in force from '
        . h(tt_pretty($version['valid_from'])) . '.'
        . ($from === $thisMon
            ? ' Saving from this Monday re-judges the days of this week already gone against the new shape.'
            : '')
        . '</span></form>';

    $intro = '<p class="tt-edit-intro">Drag a block to move it, within its day or to another. '
        . 'The blocks after it close up behind it and every block keeps its length. '
        . 'Change a length with &minus; and +, or type the minutes; the rest of the day moves to fit. '
        . 'Each day runs from the time in its header. Nothing is written until you save, and the '
        . 'list under the board says exactly what the save will change.</p>';

    $next = $from === $thisMon ? '/' : '/week/' . tt_iso_week($from);
    $grid = '<div class="wk tt-edit" id="ttedit" data-csrf="' . h(parent_csrf($store)) . '"'
        . ' data-from="' . h($from) . '" data-next="' . h($next) . '">';
    foreach (TIMETABLE_DAYS as $n => $name) {
        // Monday to Friday always; a weekend day only once it has blocks,
        // matching the board, which draws no empty weekend column.
        if ($n > 5 && !$byDay[$n]) {
            continue;
        }
        $first = $byDay[$n][0]['start'] ?? '09:00';
        $last  = $byDay[$n] ? end($byDay[$n])['end'] : null;
        $grid .= '<div class="wkcol" data-day="' . $n . '"><div class="dhead">'
            . '<span class="dn">' . substr($name, 0, 3) . '</span>'
            . '<label class="daystart">from <input type="time" value="' . h($first) . '" step="300" '
            . 'aria-label="' . h("$name starts at") . '"></label></div>'
            . '<div class="ttlist">';
        foreach ($byDay[$n] as $b) {
            $grid .= tt_edit_chip($b);
        }
        $grid .= '</div><div class="dayend mono">' . ($last ? 'ends ' . h($last) : 'no blocks') . '</div></div>';
    }
    $grid .= '</div>';

    $bar = '<div class="tt-edit-bar" id="ttedit-bar">'
        . '<div class="tt-edit-changes" aria-live="polite">No changes yet.</div>'
        . '<div class="tt-edit-actions">'
        . '<button type="button" class="tt-edit-save" disabled>Save, from Mon ' . h(tt_short_date($from)) . '</button>'
        . '<button type="button" class="tt-edit-reset">Undo all</button>'
        . '<a href="' . h($next) . '">Back to the board</a></div>'
        . '<p class="tt-edit-result" role="status"></p>'
        . '<noscript><p class="flag tt-edit-nojs">Moving blocks needs JavaScript. Without it, ask in the chat '
        . 'and the change goes through <code>tracker_set_timetable</code>.</p></noscript>'
        . '</div>';

    return dash_shell('Edit the timetable',
        $header . $intro . $select . $grid . $bar . '<script>' . TT_EDIT_JS . '</script>',
        '<style>' . TT_EDIT_CSS . '</style>');
}
