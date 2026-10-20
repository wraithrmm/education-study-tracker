<?php
/**
 * The vocabulary app: the sets the games run over, how a game's result is
 * taken into the record, and what the results say about her steps.
 *
 * This is the single-file Spanish app (flashcards and the Shooting Gallery
 * over a `vocabularySets` object) brought onto the site. Three things change
 * by living here rather than in a browser artifact:
 *
 *   - the sets are data in the database, listed and edited through two tools
 *     (tracker_list_vocab_sets, tracker_save_vocab_sets), so a new set is a
 *     tool call and not a redeploy, and every set names the syllabus topics
 *     it teaches;
 *   - every finished game reports itself as a practice run through the same
 *     validation tracker_log_practice applies, with one item per word shown,
 *     so the block it was played in shows done, the retrieval schedule
 *     advances, and the practice board fills without anyone typing a score
 *     into a chat;
 *   - the results are read against the subject's promotion bars and shown as
 *     steps: a set earns stars, and a topic whose sets have met the bar is
 *     offered to the parent to mark off — a click that logs a session with
 *     the evidence written out, through tracker_log_session, so a status
 *     still moves only by a session with evidence and never by a game alone.
 *
 * Nothing here moves a status on its own. The app writes practice; the
 * parent writes the status, from what the practice shows.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** The two activities the app plays. The registry key is <subject>_<kind>. */
const VOCAB_GAMES = [
    'gallery'    => ['display_name' => 'Shooting Gallery', 'metrics_schema' => ['top_speed' => 'number', 'set' => 'number']],
    'flashcards' => ['display_name' => 'Flashcards',       'metrics_schema' => ['set' => 'number']],
];

/** A game run counts towards a step only when it was long enough to say anything. */
const VOCAB_MIN_ITEMS = 5;
/** The accuracy a run needs to be a "good" run — the subject's secure bar, 80% unaided. */
const VOCAB_GOOD_ACCURACY = 0.8;
/** Two good runs this many days apart: retention, not recency. */
const VOCAB_SPACING_DAYS = 3;

const VOCAB_MAX_SETS       = 200;
const VOCAB_MAX_WORDS      = 60;
const VOCAB_MAX_WORD_CHARS = 120;

/**
 * The seed for a subject: php/lib/seed/<slug>-vocab.json, written by
 * deploy/import-vocab.js from the single-file app the sets used to live in.
 * For Spanish that is the app as it stood when it moved here, word for
 * word and numbered as it numbered them, each set tagged with the AQA 8692
 * refs it teaches. The file is the import, not the record: once seeded the
 * sets live in the database and are edited there.
 *
 * @return array<int,array>|null
 */
function vocab_seed_file(string $slug): ?array
{
    $path = __DIR__ . '/seed/' . preg_replace('/[^a-z0-9-]/', '', $slug) . '-vocab.json';
    if (!is_file($path)) {
        return null;
    }
    $sets = json_decode((string) file_get_contents($path), true);
    return is_array($sets) && $sets ? $sets : null;
}

// ---- sets -----------------------------------------------------------------

/**
 * Put the app's sets into the record for a subject that has none. Runs on
 * every request, like seedIfEmpty, and does nothing after the first: the
 * meta key marks the subject as seeded, so a parent who deletes every set
 * does not get them back on the next page load.
 */
function vocab_seed_if_empty(Store $store): void
{
    foreach ($store->listSubjects() as $subject) {
        $slug = (string) $subject['slug'];
        if ($store->meta("vocab_seeded_$slug") !== null) {
            continue;
        }
        $sets = vocab_seed_file($slug);
        if ($sets === null) {
            continue;
        }
        // The seed's refs are not checked against the subject's topics: a
        // subject created with fewer topics than the seed names still gets
        // its sets, and an unmatched ref simply joins to nothing. And a bad
        // seed file is logged, never a 500 on every page: the record is
        // what matters, the seed is a convenience.
        try {
            if (!vocab_list_sets($store, $slug)) {
                vocab_save_sets($store, $slug, $sets, false);
            }
            $store->setMeta("vocab_seeded_$slug", tt_now_utc());
        } catch (Throwable $e) {
            error_log("tracker: vocab seed for $slug failed: " . $e->getMessage());
            $store->setMeta("vocab_seeded_$slug", 'failed: ' . $e->getMessage());
        }
    }
}

/** @return array<int,array{number:int,name:string,topic_refs:array<int,string>,words:array<int,array>,note:?string,updated_at:string}> */
function vocab_list_sets(Store $store, string $slug): array
{
    $st = $store->db->prepare('SELECT * FROM vocab_sets WHERE subject_slug = ? ORDER BY number');
    $st->execute([$slug]);
    return array_map('vocab_row', $st->fetchAll());
}

function vocab_get_set(Store $store, string $slug, int $number): ?array
{
    $st = $store->db->prepare('SELECT * FROM vocab_sets WHERE subject_slug = ? AND number = ?');
    $st->execute([$slug, $number]);
    $row = $st->fetch();
    return $row ? vocab_row($row) : null;
}

function vocab_row(array $r): array
{
    return [
        'number'     => (int) $r['number'],
        'name'       => (string) $r['name'],
        'topic_refs' => array_values(json_decode((string) $r['topic_refs'], true) ?: []),
        'words'      => array_values(json_decode((string) $r['words'], true) ?: []),
        'note'       => $r['note'] === null ? null : (string) $r['note'],
        'updated_at' => (string) $r['updated_at'],
    ];
}

/**
 * Validate one set as sent to tracker_save_vocab_sets. Accepts the app's
 * own word shape too: `spanish` (or `french`, `german` …) in place of
 * `word`, so a `vocabularySets` entry pasted from the old file goes in
 * unchanged. Throws InvalidArgumentException naming the field.
 *
 * @return array{number:int,name:string,topic_refs:array<int,string>,words:array<int,array{word:string,english:string,pronunciation?:string}>,note:?string}
 */
function vocab_clean_set(array $set, string $at, array $knownRefs): array
{
    $number = $set['number'] ?? null;
    if (!is_int($number) && !(is_string($number) && ctype_digit($number))) {
        throw new InvalidArgumentException("$at needs an integer number (the set's position, from 1).");
    }
    $number = (int) $number;
    if ($number < 1 || $number > VOCAB_MAX_SETS) {
        throw new InvalidArgumentException("$at number must be between 1 and " . VOCAB_MAX_SETS . '.');
    }
    $name = trim((string) ($set['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        throw new InvalidArgumentException("$at needs a name of 1 to 80 characters.");
    }
    // trackerRefs is what the app's own file called them.
    $refs = $set['topic_refs'] ?? $set['trackerRefs'] ?? [];
    if (!is_array($refs) || count($refs) > 6) {
        throw new InvalidArgumentException("$at topic_refs must be an array of at most 6 topic refs.");
    }
    $cleanRefs = [];
    foreach ($refs as $ref) {
        if (!is_string($ref) || trim($ref) === '' || mb_strlen($ref) > 40) {
            throw new InvalidArgumentException("$at topic_refs entries must be non-empty strings.");
        }
        $ref = trim($ref);
        if ($knownRefs && !in_array($ref, $knownRefs, true)) {
            throw new InvalidArgumentException("$at names topic \"$ref\", which this subject does not have."
                . ' tracker_get_state lists the refs. Nothing was saved.');
        }
        $cleanRefs[] = $ref;
    }
    $words = $set['words'] ?? null;
    if (!is_array($words) || !$words || count($words) > VOCAB_MAX_WORDS) {
        throw new InvalidArgumentException("$at needs 1 to " . VOCAB_MAX_WORDS . ' words.');
    }
    $cleanWords = [];
    foreach (array_values($words) as $i => $w) {
        $wat = "$at word " . ($i + 1);
        if (!is_array($w)) {
            throw new InvalidArgumentException("$wat must be an object { word, english, pronunciation? }.");
        }
        // The target-language term under whichever key the file used.
        $term = null;
        foreach (['word', 'spanish', 'french', 'german', 'italian', 'term'] as $k) {
            if (isset($w[$k]) && is_string($w[$k]) && trim($w[$k]) !== '') {
                $term = trim($w[$k]);
                break;
            }
        }
        $english = isset($w['english']) && is_string($w['english']) ? trim($w['english']) : '';
        if ($term === null || $english === '') {
            throw new InvalidArgumentException("$wat needs both the word and its english.");
        }
        if (mb_strlen($term) > VOCAB_MAX_WORD_CHARS || mb_strlen($english) > VOCAB_MAX_WORD_CHARS) {
            throw new InvalidArgumentException("$wat is longer than " . VOCAB_MAX_WORD_CHARS . ' characters.');
        }
        $entry = ['word' => $term, 'english' => $english];
        if (isset($w['pronunciation']) && is_string($w['pronunciation']) && trim($w['pronunciation']) !== '') {
            $entry['pronunciation'] = mb_substr(trim($w['pronunciation']), 0, VOCAB_MAX_WORD_CHARS);
        }
        // From the master list: the flashcard shows the class, the tier is kept for later.
        foreach (['word_class', 'tier'] as $k) {
            if (isset($w[$k]) && is_string($w[$k]) && trim($w[$k]) !== '') {
                $entry[$k] = mb_substr(trim($w[$k]), 0, 20);
            }
        }
        $cleanWords[] = $entry;
    }
    $note = isset($set['note']) && is_string($set['note']) && trim($set['note']) !== ''
        ? mb_substr(trim($set['note']), 0, 300) : null;
    return ['number' => $number, 'name' => $name, 'topic_refs' => array_values(array_unique($cleanRefs)),
            'words' => $cleanWords, 'note' => $note];
}

/**
 * Upsert sets by number. Validated whole before anything is written, so a
 * bad set in a batch saves none of it. Returns the numbers written.
 *
 * @param array<int,array> $sets
 * @return array<int,int>
 */
function vocab_save_sets(Store $store, string $slug, array $sets, bool $checkRefs = true): array
{
    if (!$sets) {
        throw new InvalidArgumentException('sets must be a non-empty array.');
    }
    if (count($sets) > 50) {
        throw new InvalidArgumentException('sets may hold at most 50 entries per call.');
    }
    $knownRefs = $checkRefs ? array_column($store->listTopics($slug), 'ref') : [];
    $clean = [];
    $seen  = [];
    foreach (array_values($sets) as $i => $set) {
        if (!is_array($set)) {
            throw new InvalidArgumentException('set ' . ($i + 1) . ' must be an object.');
        }
        $c = vocab_clean_set($set, 'set ' . ($i + 1), $knownRefs);
        if (isset($seen[$c['number']])) {
            throw new InvalidArgumentException('set ' . ($i + 1) . " repeats number {$c['number']}.");
        }
        $seen[$c['number']] = true;
        $clean[] = $c;
    }
    $store->transaction(function () use ($store, $slug, $clean): void {
        $st = $store->db->prepare(
            'INSERT INTO vocab_sets (subject_slug, number, name, topic_refs, words, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(subject_slug, number) DO UPDATE SET
               name = excluded.name, topic_refs = excluded.topic_refs, words = excluded.words,
               note = excluded.note, updated_at = excluded.updated_at'
        );
        $now = tt_now_utc();
        foreach ($clean as $c) {
            $st->execute([
                $slug, $c['number'], $c['name'],
                json_encode($c['topic_refs'], JSON_UNESCAPED_UNICODE),
                json_encode($c['words'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $c['note'], $now, $now,
            ]);
        }
    });
    return array_map(static fn($c) => $c['number'], $clean);
}

function vocab_delete_set(Store $store, string $slug, int $number): bool
{
    $st = $store->db->prepare('DELETE FROM vocab_sets WHERE subject_slug = ? AND number = ?');
    $st->execute([$slug, $number]);
    return $st->rowCount() > 0;
}

/** Subjects that have sets, for the index page's link. @return array<int,string> */
function vocab_subjects_with_sets(Store $store): array
{
    return array_map(
        static fn($r) => (string) $r['subject_slug'],
        $store->db->query('SELECT DISTINCT subject_slug FROM vocab_sets ORDER BY subject_slug')->fetchAll()
    );
}

// ---- sources ------------------------------------------------------------

/**
 * The registry keys the app logs under for a subject, registering any that
 * are missing. For Spanish these are the keys the old app already used
 * (spanish_gallery, spanish_flashcards), so the trend line continues from
 * the runs it logged; the metrics schema gains `set`, the set number, which
 * is how a run is joined back to its set for the stars.
 *
 * @return array<string,string> game kind => registry key
 */
function vocab_sources(Store $store, string $slug): array
{
    $out = [];
    foreach (VOCAB_GAMES as $kind => $def) {
        $key      = $slug . '_' . $kind;
        $existing = $store->practiceSource($key);
        $schema   = $existing ? (json_decode((string) ($existing['metrics_schema'] ?: '{}'), true) ?: []) : [];
        if (!$existing || array_diff_key($def['metrics_schema'], $schema)) {
            $store->upsertPracticeSource([
                'key'            => $key,
                'display_name'   => $existing ? (string) $existing['display_name'] : $def['display_name'],
                'subject_slug'   => $slug,
                'metrics_schema' => $def['metrics_schema'] + $schema,
            ]);
        }
        $out[$kind] = $key;
    }
    return $out;
}

// ---- a game's result -----------------------------------------------------

/**
 * A finished game as the browser reports it, turned into the one practice
 * run tracker_log_practice would have been given, and stored through the
 * same validation. The browser is trusted with the outcomes — it is the
 * only witness — and with nothing else: the label, the topic refs and each
 * item's ref are taken from the set the server holds, played_at is held to
 * the recent past, and the counts are capped.
 *
 * @return array{status:int,body:array}
 */
function vocab_take_run(Store $store, array $subject, array $b): array
{
    $slug = $subject['slug'];
    $kind = (string) ($b['game'] ?? '');
    if (!isset(VOCAB_GAMES[$kind])) {
        return [400, ['error' => 'bad_request', 'error_description' => 'game must be gallery or flashcards.']];
    }
    $number = (int) ($b['set'] ?? 0);
    $set    = $number > 0 ? vocab_get_set($store, $slug, $number) : null;
    if (!$set) {
        return [400, ['error' => 'bad_request', 'error_description' => "No set $number for $slug."]];
    }
    $clientId = (string) ($b['client_run_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $clientId)) {
        return [400, ['error' => 'bad_request', 'error_description' => 'client_run_id must be 8 to 64 of A-Z a-z 0-9 _ . : -']];
    }

    // Every word shown is an item, and its set decides its topic. A word
    // mixed in from an earlier set carries that set's ref, not this one's.
    $allSets  = [];
    foreach (vocab_list_sets($store, $slug) as $s) {
        $allSets[$s['number']] = $s;
    }
    $rawItems = is_array($b['items'] ?? null) ? $b['items'] : [];
    if (count($rawItems) > 200) {
        return [400, ['error' => 'bad_request', 'error_description' => 'items may hold at most 200 entries.']];
    }
    $items   = [];
    $counts  = ['correct' => 0, 'retry' => 0, 'incorrect' => 0];
    $refsHit = array_fill_keys($set['topic_refs'], true);
    foreach (array_values($rawItems) as $i => $it) {
        if (!is_array($it)) {
            continue;
        }
        $outcome = (string) ($it['outcome'] ?? '');
        if (!in_array($outcome, PRACTICE_OUTCOMES, true)) {
            return [400, ['error' => 'bad_request', 'error_description' => 'item ' . ($i + 1) . ' outcome must be correct, retry or incorrect.']];
        }
        $from = (int) ($it['set'] ?? $number);
        $src  = $allSets[$from] ?? $set;
        $word = mb_substr(trim((string) ($it['word'] ?? '')), 0, VOCAB_MAX_WORD_CHARS);
        $ref  = $src['topic_refs'][0] ?? null;
        if ($ref !== null) {
            $refsHit[$ref] = true;
        }
        $counts[$outcome]++;
        $items[] = [
            'prompt'    => $word !== '' ? $word : null,
            'topic_ref' => $ref,
            'item_key'  => $word !== '' ? vocab_item_key($src['number'], $word) : null,
            'outcome'   => $outcome,
            'position'  => $i,
            'attempts_taken' => $outcome === 'correct' ? 1 : ($outcome === 'retry' ? 2 : null),
        ];
    }
    if (!$items) {
        return [400, ['error' => 'bad_request', 'error_description' => 'A game with no words shown is not a run. Nothing stored.']];
    }

    // played_at is when the game started, from the browser's clock, held to
    // the last six hours so a wrong clock cannot file a game under a day the
    // board has already judged.
    $now      = tt_now()->getTimestamp();
    $playedAt = isset($b['played_at']) && is_string($b['played_at']) ? strtotime($b['played_at']) : false;
    if ($playedAt === false || $playedAt > $now + 300 || $playedAt < $now - 6 * 3600) {
        $playedAt = $now;
    }
    $duration = isset($b['duration_seconds']) && is_numeric($b['duration_seconds'])
        ? max(0, min(4 * 3600, (int) $b['duration_seconds'])) : null;
    $metrics  = ['set' => $number];
    if ($kind === 'gallery' && isset($b['top_speed']) && is_numeric($b['top_speed'])) {
        $metrics['top_speed'] = round(max(0, min(20, (float) $b['top_speed'])), 1);
    }

    $sources = vocab_sources($store, $slug);
    $run = [
        'client_run_id'       => $clientId,
        'source'              => $sources[$kind],
        'label'               => VOCAB_GAMES[$kind]['display_name'] . ' — Set ' . $number . ': ' . $set['name'],
        'attempted'           => count($items),
        'correct'             => $counts['correct'],
        'correct_after_retry' => $counts['retry'],
        'incorrect'           => $counts['incorrect'],
        'played_at'           => gmdate('c', $playedAt),
        'duration_seconds'    => $duration,
        'metrics'             => $metrics,
        'topic_refs'          => array_keys($refsHit),
        'items'               => $items,
    ];

    try {
        $prep = mcp_practice_prepare($store, $slug, [$run]);
    } catch (McpError $e) {
        return [400, ['error' => 'bad_request', 'error_description' => $e->getMessage()]];
    }
    if (isset($prep['refused'])) {
        return [400, ['error' => 'bad_request', 'error_description' => $prep['refused']]];
    }
    $res   = mcp_practice_store($store, $slug, $prep['clean']);
    $runId = $res['ids'][0];

    // Did it count as today's slot? The board decides, the same way it
    // decides for a run logged from the chat: by subject and date, or by
    // the block the run claims. Read back rather than predicted.
    [$localDate] = tt_local($prep['clean'][0]['played_at']);
    $block = null;
    if ($store->timetableVersionOn($localDate)) {
        foreach ($store->judgeDay($localDate)['blocks'] as $blk) {
            foreach ($blk['evidence'] ?? [] as $ev) {
                if ($ev['type'] === 'practice' && (int) $ev['id'] === $runId) {
                    $block = ['label' => $blk['label'], 'start' => $blk['start'], 'end' => $blk['end'],
                              'short' => (bool) $blk['short']];
                }
            }
        }
    }
    $stored = $res['stored'] > 0;
    return [200, [
        'ok'        => true,
        'status'    => $stored ? 'stored' : 'duplicate',
        'run_id'    => $runId,
        'attempted' => $run['attempted'],
        'correct'   => $run['correct'],
        'block'     => $block,
        'message'   => ($stored ? 'Logged' : 'Already logged') . ' as run ' . $runId
            . ($block ? ' — that counts as today\'s ' . $block['label'] . ' slot' . ($block['short'] ? ' (a short one)' : '') : '')
            . '.',
        'board'     => "/s/$slug/practice",
    ]];
}

/** A stable per-word key for the retrieval schedule: the set and the word, ASCII-folded. */
function vocab_item_key(int $set, string $word): string
{
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', vocab_fold($word)), '-'));
    return substr("vocab:$set:" . ($slug === '' ? md5($word) : $slug), 0, 64);
}

function vocab_fold(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return is_string($t) && $t !== '' ? $t : $s;
}

// ---- steps ----------------------------------------------------------------

/**
 * What the games say about each set and each topic.
 *
 * Per set: its runs (non-void, app sources, joined on metrics.set), the
 * best accuracy, and a star count — one for having played it at all with
 * enough words to count, two for one good run (80%+), three for two good
 * runs at least three days apart, which is the subject's own secure bar
 * applied to the game.
 *
 * Per topic: the status it holds now, the good runs across its sets, and
 * the one move the evidence supports, if any — notstarted or gap to
 * developing on a first good run (the words are recognised, not yet
 * produced: the evidence says so), developing to secure on the spaced pair.
 * Secure and exam-ready are never proposed from a game; a maintenance slot
 * decides those with production, and the step says so.
 *
 * @return array{sets:array<int,array>,topics:array<int,array>}
 */
function vocab_steps(Store $store, array $subject, array $sets): array
{
    $slug    = $subject['slug'];
    $sources = array_values(vocab_sources($store, $slug));
    $runs    = [];
    foreach ($store->listPracticeRuns($slug, ['limit' => 1000]) as $r) {
        if (!in_array((string) $r['source'], $sources, true)) {
            continue;
        }
        $m = is_array($r['metrics'] ?? null) ? $r['metrics'] : (json_decode((string) ($r['metrics'] ?? '{}'), true) ?: []);
        $n = isset($m['set']) && is_numeric($m['set']) ? (int) $m['set'] : null;
        if ($n === null) {
            continue;
        }
        $attempted = (int) $r['attempted'];
        [$date] = tt_local((string) $r['played_at']);
        $runs[$n][] = [
            'id'        => (int) $r['id'],
            'date'      => $date,
            'source'    => (string) $r['source'],
            'attempted' => $attempted,
            'correct'   => (int) $r['correct'],
            'accuracy'  => $attempted > 0 ? (int) $r['correct'] / $attempted : null,
            'counts'    => $attempted >= VOCAB_MIN_ITEMS,
        ];
    }

    $topicsByRef = [];
    foreach ($store->listTopics($slug) as $t) {
        $topicsByRef[$t['ref']] = $t;
    }

    $outSets   = [];
    $byTopic   = [];
    foreach ($sets as $set) {
        $n     = $set['number'];
        $mine  = $runs[$n] ?? [];
        $count = array_values(array_filter($mine, static fn($r) => $r['counts']));
        $good  = array_values(array_filter($count, static fn($r) => $r['accuracy'] !== null && $r['accuracy'] >= VOCAB_GOOD_ACCURACY));
        $pair  = vocab_spaced_pair($good);
        $best  = null;
        foreach ($count as $r) {
            if ($r['accuracy'] !== null && ($best === null || $r['accuracy'] > $best)) {
                $best = $r['accuracy'];
            }
        }
        $last = null;
        foreach ($mine as $r) {
            if ($last === null || $r['date'] > $last) {
                $last = $r['date'];
            }
        }
        $stars = $pair ? 3 : ($good ? 2 : ($count ? 1 : 0));
        $outSets[$n] = [
            'number' => $n, 'name' => $set['name'], 'topic_refs' => $set['topic_refs'],
            'words' => count($set['words']), 'runs' => count($mine), 'counted' => count($count),
            'good' => count($good), 'best' => $best, 'last' => $last, 'stars' => $stars,
            'next' => match ($stars) {
                0 => 'play it once',
                1 => 'get 80% or more in a game',
                2 => 'another 80%+ game, at least ' . VOCAB_SPACING_DAYS . ' days after the first',
                default => 'done — keep it warm',
            },
        ];
        foreach ($set['topic_refs'] as $ref) {
            $byTopic[$ref]['sets'][] = $n;
            foreach ($good as $g) {
                $byTopic[$ref]['good'][] = $g + ['set' => $n];
            }
            foreach ($count as $c) {
                $byTopic[$ref]['runs'][] = $c + ['set' => $n];
            }
        }
    }

    $outTopics = [];
    foreach ($byTopic as $ref => $agg) {
        $topic  = $topicsByRef[$ref] ?? null;
        $status = $topic ? (string) $topic['status'] : null;
        $good   = $agg['good'] ?? [];
        usort($good, static fn($a, $b) => strcmp($a['date'], $b['date']));
        $pair   = vocab_spaced_pair($good);
        $runsN  = count($agg['runs'] ?? []);

        $suggest = null;
        $why     = null;
        if ($topic === null) {
            $why = 'no such topic in this subject';
        } elseif (in_array($status, ['notstarted', 'gap'], true) && $good) {
            $g = $good[count($good) - 1];
            $suggest = 'developing';
            $why = sprintf(
                'Vocabulary app: Set %d (%s) %d/%d (%d%%) on %s, recognition in the gallery%s. '
                . 'Recognises the words; production not yet shown — a maintenance slot owes that.',
                $g['set'], $sets[array_search($g['set'], array_column($sets, 'number'), true)]['name'] ?? '',
                $g['correct'], $g['attempted'], (int) round($g['accuracy'] * 100), $g['date'],
                count($good) > 1 ? ', and ' . (count($good) - 1) . ' other good run' . (count($good) > 2 ? 's' : '') : ''
            );
        } elseif ($status === 'developing' && $pair) {
            [$a, $b] = $pair;
            $suggest = 'secure';
            $why = sprintf(
                'Vocabulary app: %d/%d (%d%%) on %s and %d/%d (%d%%) on %s, %d days apart, both 80%%+ in the gallery '
                . '(sets %s). Two spaced runs at the bar.',
                $a['correct'], $a['attempted'], (int) round($a['accuracy'] * 100), $a['date'],
                $b['correct'], $b['attempted'], (int) round($b['accuracy'] * 100), $b['date'],
                tt_days_between($a['date'], $b['date']),
                implode(', ', array_unique(array_map(static fn($g) => $g['set'], $good)))
            );
        } elseif ($status === 'developing') {
            $why = $good
                ? 'one good run so far; needs a second 80%+ game at least ' . VOCAB_SPACING_DAYS . ' days later'
                : ($runsN ? 'played, but no game at 80% yet' : 'not played yet');
        } elseif (in_array($status, ['secure', 'examready'], true)) {
            $why = 'a game keeps it warm; the next step needs production in a slot, not recognition';
        } else {
            $why = $runsN ? 'played, but no game at 80% yet' : 'not played yet';
        }

        $outTopics[] = [
            'ref'      => $ref,
            'name'     => $topic ? (string) $topic['name'] : null,
            'status'   => $status,
            'sets'     => array_values(array_unique($agg['sets'])),
            'runs'     => $runsN,
            'good'     => count($good),
            'last_good' => $good ? $good[count($good) - 1]['date'] : null,
            'suggest'  => $suggest,
            'evidence' => $suggest ? mb_substr((string) $why, 0, 500) : null,
            'why'      => $why,
        ];
    }
    usort($outTopics, static fn($a, $b) => [$a['suggest'] === null, $a['ref']] <=> [$b['suggest'] === null, $b['ref']]);
    return ['sets' => $outSets, 'topics' => $outTopics];
}

/**
 * The earliest good run and the first later one at least the spacing apart,
 * or null. Runs sorted by date.
 *
 * @param array<int,array> $good
 * @return array{0:array,1:array}|null
 */
function vocab_spaced_pair(array $good): ?array
{
    usort($good, static fn($a, $b) => strcmp($a['date'], $b['date']));
    foreach ($good as $i => $a) {
        foreach (array_slice($good, $i + 1) as $b) {
            if (tt_days_between($a['date'], $b['date']) >= VOCAB_SPACING_DAYS) {
                return [$a, $b];
            }
        }
    }
    return null;
}

/**
 * The parent's mark-off: one topic, one status, the evidence as shown (or
 * as edited), logged as a session through tracker_log_session so the move
 * is a session with evidence like any other and the trail says which. Only
 * the two moves the steps propose are accepted; anything further is a
 * slot's to decide.
 *
 * @return array{ok:bool,text:string}
 */
function vocab_mark_off(Store $store, array $subject, array $b): array
{
    $slug   = $subject['slug'];
    $ref    = trim((string) ($b['ref'] ?? ''));
    $status = (string) ($b['status'] ?? '');
    $ev     = trim((string) ($b['evidence'] ?? ''));
    if (!in_array($status, ['developing', 'secure'], true)) {
        return ['ok' => false, 'text' => 'Only developing and secure can be marked off from a game.'];
    }
    $topic = $store->getTopic($slug, $ref);
    if (!$topic) {
        return ['ok' => false, 'text' => "No topic $ref in {$subject['name']}."];
    }
    $from = array_search($topic['status'], STATUS_ORDER, true);
    $to   = array_search($status, STATUS_ORDER, true);
    if ($to <= $from) {
        return ['ok' => false, 'text' => "$ref is already " . (STATUS_LABEL[$topic['status']] ?? $topic['status']) . '; nothing to mark off.'];
    }
    if ($status === 'secure' && $topic['status'] !== 'developing') {
        return ['ok' => false, 'text' => 'Never two levels in one step: ' . $ref . ' has to be developing before it can be secure.'];
    }
    if (mb_strlen($ev) < 10) {
        return ['ok' => false, 'text' => 'The evidence needs at least ten characters.'];
    }
    try {
        $res = mcp_call_tool($store, 'tracker_log_session', [
            'subject'  => $slug,
            'date'     => tt_today(),
            'summary'  => "Marked off from the vocabulary app's results: $ref to $status. " . mb_substr($ev, 0, 300),
            'updates'  => [['ref' => $ref, 'status' => $status, 'evidence' => mb_substr($ev, 0, 500)]],
        ]);
    } catch (McpError $e) {
        return ['ok' => false, 'text' => $e->getMessage()];
    }
    $text = (string) ($res['content'][0]['text'] ?? '');
    return ['ok' => !str_contains($text, 'does not') && !str_starts_with($text, 'No '), 'text' => $text];
}
