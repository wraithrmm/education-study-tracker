// The two pure-logic fixes, lifted verbatim from the patched template.
const OLD = (current, previous, pct) => {
  if (previous.length === 0) return current;
  const currentCount = Math.floor(current.length * pct);
  const previousCount = current.length - currentCount;
  const mixed = [...current.slice(0, currentCount)];
  for (let i = 0; i < previousCount; i++) mixed.push(previous[i % previous.length]);
  return mixed;
};
const NEW = (current, previous, pct) => {
  if (previous.length === 0) return current;
  const extra = Math.max(0, Math.ceil(current.length * (1 / pct - 1)));
  const mixed = [...current];
  for (let i = 0; i < extra; i++) mixed.push(previous[i % previous.length]);
  return mixed;
};

const set = n => Array.from({length: n}, (_, i) => `w${i + 1}`);
let pass = true;
const check = (name, cond, detail) => { console.log((cond ? 'ok   ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); if (!cond) pass = false; };

for (const [n, pct, label] of [[10, 0.8, 'gallery'], [10, 0.6, 'chat'], [20, 0.8, 'gallery 20'], [7, 0.8, 'gallery 7']]) {
  const cur = set(n), prev = ['old1', 'old2', 'old3'];
  const missingOld = cur.filter(w => !OLD(cur, prev, pct).includes(w));
  const missingNew = cur.filter(w => !NEW(cur, prev, pct).includes(w));
  check(`${label} n=${n} pct=${pct}: old drops words`, missingOld.length > 0, `dropped ${missingOld.join(',') || 'none'}`);
  check(`${label} n=${n} pct=${pct}: new drops none`, missingNew.length === 0, `pool ${NEW(cur, prev, pct).length}, current share ${(n / NEW(cur, prev, pct).length * 100).toFixed(0)}%`);
}

// Speed ramp: does it fire on the 3rd correct answer, not the 4th?
const ramp = (readScore) => {
  const hits = [];
  let speed = 6;
  for (let answered = 1; answered <= 9; answered++) {
    const scoreSeen = readScore === 'stale' ? answered - 1 : answered;
    if ((10 - speed) < 7.5) { if (scoreSeen > 0 && scoreSeen % 3 === 0) { speed = Math.max(-2, speed - 0.8); hits.push(answered); } }
  }
  return hits;
};
check('old ramp fires late', JSON.stringify(ramp('stale')) === JSON.stringify([4, 7]), 'fired after answers ' + ramp('stale').join(','));
check('new ramp fires on every 3rd', JSON.stringify(ramp('fresh')) === JSON.stringify([3, 6, 9]), 'fires after answers ' + ramp('fresh').join(','));

console.log(pass ? '\nALL CHECKS PASS' : '\nFAILURES ABOVE');
process.exit(pass ? 0 : 1);
