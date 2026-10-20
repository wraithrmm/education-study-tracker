#!/usr/bin/env node
/**
 * Read the `vocabularySets` object out of a single-file language app (the
 * React artifact the sets used to live in) and print it as the JSON the
 * tracker's vocab seed and tracker_save_vocab_sets take: one entry per
 * set, numbered as the file numbered them, with `trackerRefs` carried
 * over as topic_refs and every word as { word, english, pronunciation }.
 *
 *   node deploy/import-vocab.js path/to/app.jsx > php/lib/seed/spanish-vocab.json
 *
 * The object is evaluated in a bare VM rather than parsed by regex, because
 * the file is JavaScript: unquoted keys, both quote styles, trailing commas
 * and apostrophes inside strings are all normal there and all break a
 * hand-written parser. Nothing else in the file runs: only the object
 * literal is extracted, and the sandbox has no require, no globals.
 */
const fs = require('fs');
const vm = require('vm');

const file = process.argv[2];
if (!file) {
  console.error('usage: node deploy/import-vocab.js <app.jsx>');
  process.exit(2);
}
const src = fs.readFileSync(file, 'utf8');
const m = /vocabularySets\s*=\s*\{/.exec(src);
if (!m) {
  console.error('no `vocabularySets = {` in ' + file);
  process.exit(1);
}
// Walk the braces from the opening one, skipping string and comment bodies,
// so a brace inside a word never ends the object early.
let i = m.index + m[0].length - 1, depth = 0, inStr = null, j = i;
for (; j < src.length; j++) {
  const c = src[j], n = src[j + 1];
  if (inStr) {
    if (c === '\\') { j++; continue; }
    if (c === inStr) inStr = null;
    continue;
  }
  if (c === '"' || c === "'" || c === '`') { inStr = c; continue; }
  if (c === '/' && n === '/') { j = src.indexOf('\n', j); if (j < 0) j = src.length; continue; }
  if (c === '/' && n === '*') { j = src.indexOf('*/', j) + 1; continue; }
  if (c === '{') depth++;
  if (c === '}') { depth--; if (depth === 0) break; }
}
const literal = src.slice(i, j + 1);
const sets = vm.runInNewContext('(' + literal + ')', Object.create(null), { timeout: 2000 });

const out = [];
Object.keys(sets).map(Number).sort((a, b) => a - b).forEach(num => {
  const s = sets[num];
  if (!s || !Array.isArray(s.words)) return;
  const words = s.words.map(w => {
    const term = w.word ?? w.spanish ?? w.french ?? w.german ?? w.italian ?? w.term;
    const entry = { word: String(term ?? '').trim(), english: String(w.english ?? '').trim() };
    if (w.pronunciation) entry.pronunciation = String(w.pronunciation).trim();
    return entry;
  }).filter(w => w.word && w.english);
  const refs = s.trackerRefs ?? s.topic_refs ?? s.topicRefs ?? [];
  out.push({ number: num, name: String(s.name ?? ('Set ' + num)).trim(), topic_refs: Array.isArray(refs) ? refs.map(String) : [], words });
});
process.stdout.write(JSON.stringify(out, null, 1) + '\n');
console.error(out.length + ' sets, ' + out.reduce((n, s) => n + s.words.length, 0) + ' words');
