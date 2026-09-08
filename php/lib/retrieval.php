<?php
/**
 * Retrieval items and spacing, in the service.
 *
 * The spacing arithmetic and the item selection used to live in the model's
 * head: a skill reconstructed "she got this wrong a fortnight ago" by reading
 * evidence prose, expensively and differently each sitting. This moves both
 * into the record. It adds *identity and scheduling* over the practice rows
 * that tracker_log_practice already stores; it is not a second write path.
 *
 * Two grains, both always maintained where the data allows:
 *   item  — where the client supplied an item_key (a registry id, or a hash
 *           of the canonical prompt), unique per subject.
 *   topic — always, from topic_ref. This is what makes the feature work on
 *           day one for a subject with no item bank at all.
 *
 * Update rules, applied when a run is logged (the date is the run's own):
 *   correct    streak++, wrong = 0, difficulty up one (cap 3),
 *              next_due = date + interval[streak]
 *   retry      streaks and difficulty held, next_due = date + 3 days
 *   incorrect  streak = 0, wrong++, difficulty down one (floor 1),
 *              next_due = tomorrow, needs_scaffold at wrong >= 2
 * The interval ladder is a config row per subject (retrieval_config), read
 * through Store::retrievalIntervals(); the last value repeats. Retired at
 * difficulty 3 with four consecutive correct; a retired row comes back when
 * it is answered wrongly or its topic is demoted.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** Days. The seed for the '*' config row; per-subject rows override it. */
const RETRIEVAL_DEFAULT_INTERVALS = [1, 3, 7, 14, 30, 60];
const RETRIEVAL_RETRY_DAYS = 3;
const RETRIEVAL_SCAFFOLD_AT = 2;
const RETRIEVAL_RETIRE_LEVEL = 3;
const RETRIEVAL_RETIRE_STREAK = 4;
/** Dated outcomes kept per row, newest last — enough to write the "why" line. */
const RETRIEVAL_HISTORY_KEEP = 8;
/** The window "instability" and "recently taught" are read over. */
const RETRIEVAL_WINDOW_DAYS = 14;
const RETRIEVAL_GRAINS = ['item', 'topic'];
const RETRIEVAL_OUTCOMES = ['correct', 'retry', 'incorrect'];

/**
 * Apply one outcome to one (subject, grain, key) row, creating it if new.
 *
 * @param string  $date  the local date the item was asked on (YYYY-MM-DD)
 * @return array<string,mixed> the row as it now stands
 */
function retrieval_apply(
    Store $store,
    string $slug,
    string $grain,
    string $key,
    ?string $topicRef,
    ?string $prompt,
    string $outcome,
    string $date
): array {
    if (!in_array($grain, RETRIEVAL_GRAINS, true)) {
        throw new InvalidArgumentException("grain must be item or topic, not '$grain'.");
    }
    if (!in_array($outcome, RETRIEVAL_OUTCOMES, true)) {
        throw new InvalidArgumentException("outcome must be correct, retry or incorrect, not '$outcome'.");
    }
    $db  = $store->db;
    $st  = $db->prepare('SELECT * FROM retrieval_state WHERE subject_slug = ? AND grain = ? AND key = ?');
    $st->execute([$slug, $grain, $key]);
    $row = $st->fetch() ?: [
        'subject_slug' => $slug, 'grain' => $grain, 'key' => $key, 'topic_ref' => $topicRef,
        'prompt' => null, 'last_asked' => null, 'next_due' => null,
        'consecutive_correct' => 0, 'consecutive_wrong' => 0, 'difficulty_level' => 1,
        'needs_scaffold' => 0, 'retired' => 0, 'asked' => 0, 'history' => '[]',
    ];

    $correct = (int) $row['consecutive_correct'];
    $wrong   = (int) $row['consecutive_wrong'];
    $level   = (int) $row['difficulty_level'];
    $scaff   = (int) $row['needs_scaffold'];
    $retired = (int) $row['retired'];
    $ladder  = $store->retrievalIntervals($slug);

    switch ($outcome) {
        case 'correct':
            $correct++;
            $wrong = 0;
            $level = min(3, $level + 1);
            $step  = $ladder[min($correct, count($ladder)) - 1];
            $next  = tt_add_days($date, $step);
            // Two clean answers in a row is what lifts the scaffold: one
            // could be the scaffold working.
            if ($correct >= 2) {
                $scaff = 0;
            }
            if ($level >= RETRIEVAL_RETIRE_LEVEL && $correct >= RETRIEVAL_RETIRE_STREAK) {
                $retired = 1;
            }
            break;
        case 'retry':
            $next = tt_add_days($date, RETRIEVAL_RETRY_DAYS);
            break;
        default:
            $correct = 0;
            $wrong++;
            $level   = max(1, $level - 1);
            $next    = tt_add_days($date, 1);
            if ($wrong >= RETRIEVAL_SCAFFOLD_AT) {
                $scaff = 1;
            }
            // A retired item answered wrongly is back in play.
            $retired = 0;
            break;
    }

    $history   = json_decode((string) $row['history'], true);
    $history   = is_array($history) ? $history : [];
    $history[] = ['d' => $date, 'o' => $outcome];
    $history   = array_slice($history, -RETRIEVAL_HISTORY_KEEP);

    $st = $db->prepare(
        'INSERT INTO retrieval_state
           (subject_slug, grain, key, topic_ref, prompt, last_asked, next_due, consecutive_correct,
            consecutive_wrong, difficulty_level, needs_scaffold, retired, asked, history, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))
         ON CONFLICT(subject_slug, grain, key) DO UPDATE SET
           topic_ref = COALESCE(excluded.topic_ref, retrieval_state.topic_ref),
           prompt = COALESCE(excluded.prompt, retrieval_state.prompt),
           last_asked = excluded.last_asked, next_due = excluded.next_due,
           consecutive_correct = excluded.consecutive_correct,
           consecutive_wrong = excluded.consecutive_wrong,
           difficulty_level = excluded.difficulty_level,
           needs_scaffold = excluded.needs_scaffold, retired = excluded.retired,
           asked = excluded.asked, history = excluded.history, updated_at = excluded.updated_at'
    );
    $st->execute([
        $slug, $grain, $key, $topicRef ?? $row['topic_ref'], $prompt ?? $row['prompt'],
        $date, $next, $correct, $wrong, $level, $scaff, $retired, (int) $row['asked'] + 1,
        json_encode($history, JSON_UNESCAPED_UNICODE),
    ]);
    $st = $db->prepare('SELECT * FROM retrieval_state WHERE subject_slug = ? AND grain = ? AND key = ?');
    $st->execute([$slug, $grain, $key]);
    return $st->fetch() ?: $row;
}

/**
 * A topic was demoted: every retired row on it comes back into play. The
 * topic row itself is handled by the incorrect outcome that goes with a
 * demotion; this reaches the item rows under it.
 */
function retrieval_unretire_topic(Store $store, string $slug, string $topicRef): int
{
    $st = $store->db->prepare(
        'UPDATE retrieval_state SET retired = 0, updated_at = datetime(\'now\')
         WHERE subject_slug = ? AND topic_ref = ? AND retired = 1'
    );
    $st->execute([$slug, $topicRef]);
    return $st->rowCount();
}

/**
 * Apply a logged practice run's items to the schedule. Only items carry an
 * outcome, so run-level topic_refs without items schedule nothing.
 *
 * @param array<int,array<string,mixed>> $items the cleaned items of the run
 * @return array{item:int,topic:int} rows touched at each grain
 */
function retrieval_apply_run(Store $store, string $slug, string $date, array $items): array
{
    $n = ['item' => 0, 'topic' => 0];
    foreach ($items as $item) {
        $outcome = (string) ($item['outcome'] ?? '');
        if (!in_array($outcome, RETRIEVAL_OUTCOMES, true)) {
            continue;
        }
        $ref = isset($item['topic_ref']) && $item['topic_ref'] !== '' ? (string) $item['topic_ref'] : null;
        $key = isset($item['item_key']) && $item['item_key'] !== '' ? (string) $item['item_key'] : null;
        if ($key !== null) {
            retrieval_apply($store, $slug, 'item', $key, $ref, $item['prompt'] ?? null, $outcome, $date);
            $n['item']++;
        }
        if ($ref !== null) {
            retrieval_apply($store, $slug, 'topic', $ref, $ref, null, $outcome, $date);
            $n['topic']++;
        }
    }
    return $n;
}

/**
 * Outcome inferred from a session's topic update, or null when the update
 * says nothing certain. A rise is a correct; a fall is an incorrect. An
 * update that leaves the status where it was is NOT read as correct: on the
 * real record those are as often "left blank, walked through" as "held" —
 * the caller says which with retrieval_outcome.
 */
function retrieval_infer_outcome(?string $from, ?string $to): ?string
{
    if ($from === null || $to === null) {
        return null;
    }
    $a = array_search($from, STATUS_ORDER, true);
    $b = array_search($to, STATUS_ORDER, true);
    if ($a === false || $b === false || $a === $b) {
        return null;
    }
    return $b > $a ? 'correct' : 'incorrect';
}

// ---- selection ------------------------------------------------------------

/**
 * How unstable a subject has been over the window: demotions, failed
 * practice items, and rows needing a scaffold. Weights the spare slots.
 */
function retrieval_instability(Store $store, string $slug, string $today): int
{
    $db    = $store->db;
    $since = tt_add_days($today, -RETRIEVAL_WINDOW_DAYS);
    $n     = 0;
    $st = $db->prepare(
        'SELECT from_status, to_status FROM topic_changes
         WHERE subject_slug = ? AND changed_at >= ?'
    );
    $st->execute([$slug, $since . ' 00:00:00']);
    foreach ($st->fetchAll() as $c) {
        if (retrieval_infer_outcome($c['from_status'], $c['to_status']) === 'incorrect') {
            $n++;
        }
    }
    $st = $db->prepare(
        "SELECT COUNT(*) AS n FROM practice_item i
         JOIN practice_run r ON r.id = i.practice_run_id
         WHERE r.subject_slug = ? AND r.void_reason IS NULL AND i.outcome = 'incorrect'
           AND r.played_at >= ?"
    );
    $st->execute([$slug, tt_add_days($since, -1) . ' 00:00:00']);
    $n += (int) ($st->fetch()['n'] ?? 0);
    $st = $db->prepare(
        'SELECT COUNT(*) AS n FROM retrieval_state WHERE subject_slug = ? AND needs_scaffold = 1 AND retired = 0'
    );
    $st->execute([$slug]);
    $n += (int) ($st->fetch()['n'] ?? 0);
    return $n;
}

/** 'failed twice, 4 Sep and 8 Sep' — the last outcomes as a clause. */
function retrieval_why_history(array $history): ?string
{
    $wrong = [];
    foreach ($history as $h) {
        if (($h['o'] ?? '') === 'incorrect') {
            $wrong[] = $h['d'];
        }
    }
    $recent = array_slice($wrong, -3);
    $pretty = static fn(string $d): string => (new DateTimeImmutable($d, tt_zone()))->format('j M');
    if ($recent) {
        $n = count($wrong);
        return 'failed ' . ($n === 1 ? 'once' : ($n === 2 ? 'twice' : "$n times")) . ', '
            . implode(' and ', array_map($pretty, $recent));
    }
    return null;
}

/**
 * The candidates for one subject, best first. Each is one output entry
 * minus the subject-level fields, with a `tier` (lower is more urgent) and
 * a `sort` key inside the tier.
 *
 * Tiers: 0 needs_scaffold · 1 overdue · 2 due today · 3 loose end (watch)
 * · 4 recently taught and still developing · 5 longest-untouched secure ·
 * 6 scheduled but not yet due (filler only).
 *
 * @return array<int,array<string,mixed>>
 */
function retrieval_candidates(Store $store, string $slug, string $today, bool $includeRetired): array
{
    $db     = $store->db;
    $topics = [];
    foreach ($store->listTopics($slug) as $t) {
        $topics[(string) $t['ref']] = $t;
    }

    $out       = [];
    $seenTopic = [];
    $st = $db->prepare(
        'SELECT * FROM retrieval_state WHERE subject_slug = ? ORDER BY grain DESC, key'
    );
    $st->execute([$slug]);
    foreach ($st->fetchAll() as $r) {
        if ((int) $r['retired'] === 1 && !$includeRetired) {
            continue;
        }
        $ref     = $r['topic_ref'] === null ? null : (string) $r['topic_ref'];
        $due     = $r['next_due'] === null ? null : (string) $r['next_due'];
        $overdue = $due !== null && $due < $today ? tt_days_between($due, $today) : 0;
        $history = json_decode((string) $r['history'], true) ?: [];
        $why     = retrieval_why_history($history);
        if ((int) $r['needs_scaffold'] === 1) {
            $tier = 0;
            $why  = ($why ? $why . '; ' : '') . 'needs a scaffold';
        } elseif ($overdue > 0) {
            $tier = 1;
            $why  = ($why ? $why . '; ' : '') . "overdue by $overdue day" . ($overdue === 1 ? '' : 's');
        } elseif ($due === $today) {
            $tier = 2;
            $why  = ($why ? $why . '; ' : '') . 'due today';
        } else {
            $tier = 6;
            $why  = ($why ? $why . '; ' : '') . 'not due until ' . ($due ?? 'unscheduled');
        }
        if ((int) $r['retired'] === 1) {
            $why .= ' (retired)';
        }
        $out[] = [
            'grain'            => (string) $r['grain'],
            'key'              => (string) $r['key'],
            'topic_ref'        => $ref,
            'topic_name'       => $ref !== null ? ($topics[$ref]['name'] ?? null) : null,
            'prompt'           => $r['prompt'] === null ? null : (string) $r['prompt'],
            'difficulty_level' => (int) $r['difficulty_level'],
            'needs_scaffold'   => (int) $r['needs_scaffold'] === 1,
            'last_asked'       => $r['last_asked'] === null ? null : (string) $r['last_asked'],
            'days_overdue'     => $overdue,
            'why'              => $why,
            'tier'             => $tier,
            // Within a tier the more overdue first, then item grain before
            // topic grain — the item is the specific thing to ask; the topic
            // row is the aggregate that stands in when there is no item.
            'sort'             => [-$overdue, (string) $r['grain'] === 'item' ? 0 : 1,
                                   (string) $r['next_due'], (string) $r['key']],
        ];
        if ($ref !== null && $tier <= 2) {
            $seenTopic[$ref] = true;
        }
    }

    // Topic-grain entries synthesised from the topic table, for what the
    // schedule has never seen: loose ends, recent teaching, ageing secures.
    $since = tt_add_days($today, -RETRIEVAL_WINDOW_DAYS);
    foreach ($topics as $ref => $t) {
        if (isset($seenTopic[$ref])) {
            continue;
        }
        $state = retrieval_topic_state($store, $slug, $ref);
        if ($state && (int) $state['retired'] === 1 && !$includeRetired) {
            continue;
        }
        $base = [
            'grain'            => 'topic',
            'key'              => $ref,
            'topic_ref'        => $ref,
            'topic_name'       => (string) $t['name'],
            'prompt'           => null,
            'difficulty_level' => $state ? (int) $state['difficulty_level'] : 1,
            'needs_scaffold'   => false,
            'last_asked'       => $state && $state['last_asked'] !== null
                ? (string) $state['last_asked'] : ($t['last_touched'] ?? null),
            'days_overdue'     => 0,
        ];
        if ($t['watch'] && $t['status'] !== 'gap' && $t['status'] !== 'notstarted') {
            $out[] = $base + ['why' => 'loose end: ' . $t['watch'], 'tier' => 3,
                              'sort' => [(string) ($t['last_touched'] ?? ''), $ref]];
            $seenTopic[$ref] = true;
            continue;
        }
        if ($t['status'] === 'developing' && $t['last_touched'] !== null && $t['last_touched'] >= $since) {
            $out[] = $base + ['why' => 'taught ' . retrieval_pretty((string) $t['last_touched'])
                . ', still developing', 'tier' => 4,
                'sort' => [(string) $t['last_touched'], $ref]];
            $seenTopic[$ref] = true;
            continue;
        }
        if ($t['status'] === 'secure' || $t['status'] === 'examready') {
            $w = weeksSince($t['last_touched']);
            $out[] = $base + ['why' => 'secure, ' . ($w === null ? 'no date recorded'
                : "untouched $w week" . ($w === 1 ? '' : 's')), 'tier' => 5,
                'sort' => [(string) ($t['last_touched'] ?? ''), $ref]];
            $seenTopic[$ref] = true;
        }
    }

    usort($out, static fn(array $a, array $b): int => [$a['tier'], $a['sort']] <=> [$b['tier'], $b['sort']]);
    return $out;
}

/** The topic-grain schedule row for a ref, if one exists. */
function retrieval_topic_state(Store $store, string $slug, string $ref): ?array
{
    $st = $store->db->prepare(
        "SELECT * FROM retrieval_state WHERE subject_slug = ? AND grain = 'topic' AND key = ?"
    );
    $st->execute([$slug, $ref]);
    return $st->fetch() ?: null;
}

function retrieval_pretty(string $date): string
{
    try {
        return (new DateTimeImmutable($date, tt_zone()))->format('j M');
    } catch (Throwable) {
        return $date;
    }
}

/**
 * The ordered, mixed set for a block.
 *
 * Every subject named gets at least two slots where it has candidates; the
 * rest weight toward the subject with the most instability in the last
 * fortnight. The order never places two consecutive entries from one subject
 * and never two on the same topic, and a topic is not repeated while another
 * remains. Deterministic: the same record gives the same list.
 *
 * @param array<int,string> $subjects
 * @return array{entries:array<int,array<string,mixed>>,slots:array<string,int>,
 *               instability:array<string,int>,candidates:array<string,int>}
 */
function retrieval_due(Store $store, array $subjects, int $limit, bool $includeRetired = false): array
{
    $today = tt_today();
    $subjects = array_values(array_unique($subjects));
    $cands = [];
    $inst  = [];
    foreach ($subjects as $slug) {
        $cands[$slug] = retrieval_candidates($store, $slug, $today, $includeRetired);
        $inst[$slug]  = retrieval_instability($store, $slug, $today);
    }

    // Slots: two each, then the remainder by weight, capped by what each
    // subject can actually supply.
    $slots = [];
    foreach ($subjects as $slug) {
        $slots[$slug] = min(2, count($cands[$slug]));
    }
    $remaining = max(0, $limit - array_sum($slots));
    $weight = [];
    foreach ($subjects as $slug) {
        $weight[$slug] = 1 + $inst[$slug];
    }
    $totalWeight = array_sum($weight) ?: 1;
    // Largest remainder, ties by instability then slug.
    $share = [];
    $given = 0;
    foreach ($subjects as $slug) {
        $exact = $remaining * $weight[$slug] / $totalWeight;
        $floor = (int) floor($exact);
        $room  = count($cands[$slug]) - $slots[$slug];
        $take  = max(0, min($floor, $room));
        $share[$slug] = ['take' => $take, 'frac' => $exact - $floor];
        $given += $take;
    }
    $order = $subjects;
    usort($order, static fn(string $a, string $b): int =>
        [$share[$b]['frac'], $inst[$b], $a] <=> [$share[$a]['frac'], $inst[$a], $b]);
    // Anything still unassigned goes round in weight order while someone has room.
    $left = $remaining - $given;
    $progress = true;
    while ($left > 0 && $progress) {
        $progress = false;
        foreach ($order as $slug) {
            if ($left <= 0) {
                break;
            }
            $room = count($cands[$slug]) - $slots[$slug] - $share[$slug]['take'];
            if ($room > 0) {
                $share[$slug]['take']++;
                $left--;
                $progress = true;
            }
        }
    }
    foreach ($subjects as $slug) {
        $slots[$slug] += $share[$slug]['take'];
    }

    // Interleave. At each step take the subject with the most quota left
    // that is not the previous subject, and its first candidate whose topic
    // is not the previous topic — preferring one not yet used at all.
    $quota    = $slots;
    $queues   = $cands;
    $entries  = [];
    $prevSubj = null;
    $prevRef  = null;
    $usedRef  = [];
    $total    = array_sum($quota);
    for ($i = 0; $i < $total; $i++) {
        $pick = null;
        $pool = array_filter($subjects, static fn(string $s): bool => $quota[$s] > 0 && $s !== $prevSubj);
        if (!$pool) {
            $pool = array_filter($subjects, static fn(string $s): bool => $quota[$s] > 0);
        }
        $pool = array_values($pool);
        usort($pool, static fn(string $a, string $b): int =>
            [$quota[$b], $inst[$b], $a] <=> [$quota[$a], $inst[$a], $b]);
        foreach ($pool as $slug) {
            $idx = retrieval_pick_index($queues[$slug], $prevRef, $usedRef);
            if ($idx !== null) {
                $pick = [$slug, $idx];
                break;
            }
        }
        if ($pick === null) {
            break;
        }
        [$slug, $idx] = $pick;
        $c = $queues[$slug][$idx];
        array_splice($queues[$slug], $idx, 1);
        $quota[$slug]--;
        unset($c['tier'], $c['sort']);
        $entries[] = ['subject' => $slug] + $c;
        $prevSubj  = $slug;
        $prevRef   = $c['topic_ref'];
        if ($c['topic_ref'] !== null) {
            $usedRef[$c['topic_ref']] = true;
        }
    }

    $counts = [];
    foreach ($subjects as $slug) {
        $counts[$slug] = count($cands[$slug]);
    }
    return ['entries' => $entries, 'slots' => $slots, 'instability' => $inst, 'candidates' => $counts];
}

/**
 * The index of the best candidate not on the previous topic: one on an
 * unused topic first, then any that merely differs from the previous one.
 *
 * @param array<int,array<string,mixed>> $queue
 * @param array<string,bool>             $used
 */
function retrieval_pick_index(array $queue, ?string $prevRef, array $used): ?int
{
    foreach ($queue as $i => $c) {
        $ref = $c['topic_ref'];
        if ($ref === null || (!isset($used[$ref]) && $ref !== $prevRef)) {
            return $i;
        }
    }
    foreach ($queue as $i => $c) {
        if ($c['topic_ref'] !== $prevRef) {
            return $i;
        }
    }
    return null;
}
