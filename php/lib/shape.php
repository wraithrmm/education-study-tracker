<?php
/**
 * Kind-aware block shape checks.
 *
 * The board already decides *whether* a block was done — a record exists for
 * one of its subjects on its date. This decides whether the work had the
 * shape the block asked for: a retrieval block satisfied by a three-item
 * run, a consolidation block that re-worked nothing, a teach block whose
 * session moved no topic. A block done in the wrong shape stays done: it
 * counts for adherence and hours, and it is never a miss. It is listed
 * apart, with a reason that describes the work and never the student.
 *
 * The rules are rows in block_kind_rules, seeded from SHAPE_RULE_SEED below,
 * so a new kind is a row and a rule that turns out wrong is an UPDATE. A row
 * holds a list of alternatives; each alternative is an object of conditions
 * that must all hold. An empty list is always met. The condition vocabulary:
 *
 *   min_items                 practice run attempted ≥ n
 *   min_updates               session made ≥ n topic updates
 *   min_updates_with_evidence session made ≥ n topic updates carrying evidence
 *   min_distinct_topics       those updates touch ≥ n distinct topics
 *   consolidates_nonempty     the session's `consolidates` list is non-empty
 *   recent_error_update_days  ≥ 1 update whose topic had a demotion, a blank
 *                             or a prompted answer recorded in the prior n days
 *   min_duration_minutes      a recorded duration ≥ n minutes
 *   min_duration_fraction     a recorded duration ≥ this fraction of the block
 *   evidence_type             the record is of this type (session|attempt|practice)
 *
 * A row also says whether a session logged against the kind needs a lesson
 * review (review_required): the kinds a tutor skill owns do, retrieval,
 * Spanish maintenance, movement and the review block do not, and a session
 * with no block needs one when it ran REVIEW_EXTRA_MINUTES or longer.
 *
 * A condition the evaluator does not know can never be met, and the reason
 * says so — a typo in a rule row surfaces on the board instead of quietly
 * passing everything.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** Evidence text that records a blank or a prompted answer. */
const SHAPE_ERROR_EVIDENCE_PATTERN =
    '/\b(blank|prompted|with a prompt|after a prompt|with prompting|needed a prompt|scaffold)/i';

const SHAPE_RULE_SEED = [
    ['kind' => 'retrieval', 'satisfied_by' => 'retrieval_practice',
     'shape' => [['min_items' => 5], ['min_updates' => 3]],
     'expects' => 'at least 5 items attempted, or a session with 3 or more topic updates'],
    ['kind' => 'teach', 'satisfied_by' => 'any', 'review_required' => true,
     'shape' => [['min_updates_with_evidence' => 1]],
     'expects' => 'at least one topic update carrying evidence'],
    ['kind' => 'practise', 'satisfied_by' => 'any', 'review_required' => true,
     'shape' => [['min_updates' => 1, 'min_distinct_topics' => 2]],
     'expects' => 'at least one topic update, and updates touching 2 or more distinct topics (interleaving)'],
    ['kind' => 'consolidate', 'satisfied_by' => 'any', 'review_required' => true,
     'shape' => [['consolidates_nonempty' => true], ['recent_error_update_days' => 21]],
     'expects' => 'a consolidates list naming the errors re-worked, or an update on a topic that had '
        . 'a demotion, a blank or a prompted answer in the prior 21 days'],
    ['kind' => 'timed_handwritten', 'satisfied_by' => 'attempt',
     'shape' => [['evidence_type' => 'attempt'], ['min_duration_minutes' => 15]],
     'expects' => 'a marked attempt, or a session with 15 minutes or more recorded'],
    ['kind' => 'coding', 'satisfied_by' => 'any', 'review_required' => true,
     'shape' => [['min_duration_fraction' => 0.5]],
     'expects' => 'a recorded duration of at least half the block'],
    ['kind' => 'writing', 'satisfied_by' => 'any', 'review_required' => true,
     'shape' => [['min_duration_fraction' => 0.5]],
     'expects' => 'a recorded duration of at least half the block'],
    // The fallback for a kind with no row of its own: any record, always
    // met. The verdict still says the kind has no rule, so it can be added
    // rather than improvised twice.
    ['kind' => '*', 'satisfied_by' => 'any', 'shape' => [],
     'expects' => null, 'note' => 'Fallback for any kind without a row.'],
];

/**
 * Judge the shape of the record bound to a block.
 *
 * @param array<string,mixed> $block  a timetable block
 * @param array<string,mixed> $ev     an evidenceBetween() row
 * @param array<string,array> $rules  Store::blockKindRules()
 * @return array{shape:?string,reason:?string,has_rule:bool}
 */
function shape_judge(Store $store, array $block, array $ev, int $length, string $date, array $rules): array
{
    $kind = (string) $block['kind'];
    $rule = $rules[$kind] ?? null;
    if ($rule === null) {
        $fallback = $rules['*'] ?? null;
        $note = "no shape rule for kind '$kind' — add a row to block_kind_rules";
        if ($fallback === null || !$fallback['shape']) {
            return ['shape' => 'met', 'reason' => $note, 'has_rule' => false];
        }
        $rule = $fallback;
    }
    if (!$rule['shape']) {
        return ['shape' => 'met', 'reason' => null, 'has_rule' => true];
    }

    $facts = shape_facts($store, $ev, $length, $date);
    $failed = [];
    foreach ($rule['shape'] as $alternative) {
        if (!is_array($alternative)) {
            continue;
        }
        $miss = shape_alternative_miss($alternative, $facts);
        if ($miss === null) {
            return ['shape' => 'met', 'reason' => null, 'has_rule' => true];
        }
        $failed[] = $miss;
    }
    $reason = 'expected ' . ($rule['expects'] ?: implode(' or ', $failed))
        . '; got ' . shape_facts_summary($facts);
    return ['shape' => 'unmet', 'reason' => $reason, 'has_rule' => true];
}

/**
 * What the record actually was, in the terms the conditions read.
 *
 * @return array<string,mixed>
 */
function shape_facts(Store $store, array $ev, int $length, string $date): array
{
    $facts = [
        'type'              => (string) $ev['type'],
        'attempted'         => null,
        'updates'           => 0,
        'updates_evidence'  => 0,
        'distinct_topics'   => 0,
        'consolidates'      => 0,
        'duration_minutes'  => $ev['minutes'] ?? null,
        'block_length'      => $length,
        'recent_error_days' => null,
        'recent_error_hits' => 0,
        'source'            => $ev['source'] ?? null,
        // The store, for the one condition that needs a query of its own.
        '_store'            => $store,
    ];
    if ($facts['type'] === 'practice') {
        $facts['attempted'] = (int) ($ev['attempted'] ?? 0);
        return $facts;
    }
    if ($facts['type'] !== 'session') {
        return $facts;
    }
    $session = $store->sessionById((int) $ev['id']);
    $changes = $store->changesForSession((int) $ev['id']);
    $refs    = [];
    foreach ($changes as $c) {
        $refs[(string) $c['ref']] = true;
        if (trim((string) $c['evidence']) !== '') {
            $facts['updates_evidence']++;
        }
    }
    $facts['updates']          = count($changes);
    $facts['distinct_topics']  = count($refs);
    $facts['refs']             = array_keys($refs);
    $facts['subject']          = $session['subject_slug'] ?? ($ev['subject'] ?? null);
    $facts['date']             = $session['date'] ?? $date;
    $cons = json_decode((string) ($session['consolidates'] ?? ''), true);
    $facts['consolidates']     = is_array($cons) ? count($cons) : 0;
    if ($session && $session['duration_minutes'] !== null) {
        $facts['duration_minutes'] = (int) $session['duration_minutes'];
    }
    return $facts;
}

/**
 * Why one alternative was not met, or null when every condition holds.
 *
 * @param array<string,mixed> $conditions
 */
function shape_alternative_miss(array $conditions, array &$facts): ?string
{
    foreach ($conditions as $key => $want) {
        switch ($key) {
            case 'min_items':
                if ($facts['attempted'] === null || $facts['attempted'] < (int) $want) {
                    return "at least $want items attempted";
                }
                break;
            case 'min_updates':
                if ($facts['updates'] < (int) $want) {
                    return "at least $want topic update" . ((int) $want === 1 ? '' : 's');
                }
                break;
            case 'min_updates_with_evidence':
                if ($facts['updates_evidence'] < (int) $want) {
                    return "at least $want topic update" . ((int) $want === 1 ? '' : 's') . ' carrying evidence';
                }
                break;
            case 'min_distinct_topics':
                if ($facts['distinct_topics'] < (int) $want) {
                    return "updates touching at least $want distinct topics";
                }
                break;
            case 'consolidates_nonempty':
                if ($want && $facts['consolidates'] < 1) {
                    return 'a non-empty consolidates list';
                }
                break;
            case 'recent_error_update_days':
                if (shape_recent_error_updates($facts, (int) $want) < 1) {
                    return "an update on a topic with a demotion, blank or prompted answer in the prior $want days";
                }
                break;
            case 'min_duration_minutes':
                if ($facts['duration_minutes'] === null || $facts['duration_minutes'] < (int) $want) {
                    return "a recorded duration of at least $want minutes";
                }
                break;
            case 'min_duration_fraction':
                $need = (int) ceil($facts['block_length'] * (float) $want);
                if ($facts['duration_minutes'] === null || $facts['duration_minutes'] < $need) {
                    return "a recorded duration of at least $need minutes (" . round((float) $want * 100)
                        . '% of the block)';
                }
                break;
            case 'evidence_type':
                if ($facts['type'] !== (string) $want) {
                    return "a record of type $want";
                }
                break;
            default:
                return "a condition this service does not understand ('$key' — fix the rule row)";
        }
    }
    return null;
}

/**
 * How many of a session's updated topics had a demotion, a blank or a
 * prompted answer recorded in the window before the session. Counted once
 * per session and cached on the facts, so two alternatives asking the same
 * question run one query.
 */
function shape_recent_error_updates(array &$facts, int $days): int
{
    if (($facts['recent_error_days'] ?? null) === $days) {
        return (int) $facts['recent_error_hits'];
    }
    $facts['recent_error_days'] = $days;
    $facts['recent_error_hits'] = 0;
    $refs = $facts['refs'] ?? [];
    if (!$refs || empty($facts['subject'])) {
        return 0;
    }
    $store = $facts['_store'] ?? null;
    if (!$store instanceof Store) {
        return 0;
    }
    $date  = (string) $facts['date'];
    $from  = tt_add_days($date, -$days) . ' 00:00:00';
    $to    = $date . ' 23:59:59';
    $in    = implode(',', array_fill(0, count($refs), '?'));
    $st    = $store->db->prepare(
        "SELECT ref, from_status, to_status, evidence FROM topic_changes
         WHERE subject_slug = ? AND ref IN ($in) AND changed_at BETWEEN ? AND ?"
    );
    $st->execute(array_merge([$facts['subject']], $refs, [$from, $to]));
    $hit = [];
    foreach ($st->fetchAll() as $c) {
        $fromRank = array_search((string) ($c['from_status'] ?? ''), STATUS_ORDER, true);
        $toRank   = array_search((string) $c['to_status'], STATUS_ORDER, true);
        $demoted  = $fromRank !== false && $toRank !== false && $toRank < $fromRank;
        if ($demoted || preg_match(SHAPE_ERROR_EVIDENCE_PATTERN, (string) $c['evidence'])) {
            $hit[(string) $c['ref']] = true;
        }
    }
    $facts['recent_error_hits'] = count($hit);
    return count($hit);
}

/** The record in a phrase, for the reason line. */
function shape_facts_summary(array $facts): string
{
    if ($facts['type'] === 'practice') {
        $n = (int) ($facts['attempted'] ?? 0);
        return 'a practice run of ' . $n . ' item' . ($n === 1 ? '' : 's')
            . ($facts['source'] ? ' (' . $facts['source'] . ')' : '');
    }
    if ($facts['type'] === 'attempt') {
        return 'a marked attempt';
    }
    $bits = [];
    $u = (int) $facts['updates'];
    $bits[] = $u === 0 ? 'no topic updates'
        : $u . ' topic update' . ($u === 1 ? '' : 's') . ' on ' . (int) $facts['distinct_topics']
            . ' topic' . ((int) $facts['distinct_topics'] === 1 ? '' : 's');
    if ((int) $facts['consolidates'] > 0) {
        $bits[] = (int) $facts['consolidates'] . ' consolidated';
    }
    $bits[] = $facts['duration_minutes'] === null
        ? 'no duration recorded'
        : (int) $facts['duration_minutes'] . ' min recorded';
    return 'a session with ' . implode(', ', $bits);
}
