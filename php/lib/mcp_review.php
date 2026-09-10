<?php
/**
 * The lesson review's validation and its write, shared by tracker_log_session
 * (which carries a review alongside the session) and
 * tracker_save_lesson_review (which re-versions one).
 *
 * Validation is whole-or-nothing and names the field, as the weekly review's
 * is: a key that silently does nothing is how a review ends up not saying
 * what its author thought they wrote. Nothing here moves a topic status.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** A free-text field within the review's limits: min 10 / max 600 unless noted. */
function mcp_rv_text(array $o, string $key, string $at, bool $required = true, int $min = 10, int $max = 600): ?string
{
    $v = $o[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) {
            throw new McpError("$at.$key is required. Nothing was written.");
        }
        return null;
    }
    if (!is_string($v)) {
        throw new McpError("$at.$key must be a string. Nothing was written.");
    }
    $s = trim($v);
    $n = mb_strlen($s);
    if ($n < $min || $n > $max) {
        throw new McpError("$at.$key is $n characters; it must be $min to $max. Nothing was written.");
    }
    return $s;
}

/** A value from an enum, named on refusal. */
function mcp_rv_enum(array $o, string $key, string $at, array $allowed, bool $required = true, ?string $default = null): ?string
{
    $v = $o[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) {
            throw new McpError("$at.$key is required; one of: " . implode(', ', $allowed) . '. Nothing was written.');
        }
        return $default;
    }
    if (!is_string($v) || !in_array($v, $allowed, true)) {
        throw new McpError("$at.$key is '" . (is_scalar($v) ? $v : gettype($v)) . "'; it must be one of: "
            . implode(', ', $allowed) . '. Nothing was written.');
    }
    return $v;
}

/** A list under a key: absent means empty; anything but a list is refused. */
function mcp_rv_list(array $o, string $key, string $at, int $max = 50): array
{
    $v = $o[$key] ?? [];
    if ($v === null) {
        return [];
    }
    if (!is_array($v) || (!array_is_list($v) && $v)) {
        throw new McpError("$at.$key must be a list. Nothing was written.");
    }
    if (count($v) > $max) {
        throw new McpError("$at.$key holds " . count($v) . ' entries; the most is ' . $max . '. Nothing was written.');
    }
    return array_values($v);
}

/** Each entry of a list must be an object. */
function mcp_rv_object(mixed $v, string $at): array
{
    // An empty JSON object decodes to [] in PHP, which is also an empty
    // list; only a non-empty list is refused.
    if (!is_array($v) || ($v && array_is_list($v))) {
        throw new McpError("$at must be an object. Nothing was written.");
    }
    return $v;
}

/** A topic ref that exists in the subject. */
function mcp_rv_ref(array $o, string $at, array $known, string $key = 'ref'): string
{
    $ref = trim((string) ($o[$key] ?? ''));
    if ($ref === '') {
        throw new McpError("$at.$key is required. Nothing was written.");
    }
    if (!in_array($ref, $known, true)) {
        throw new McpError("$at.$key names \"$ref\", which is not a topic in this subject. Check it with "
            . 'tracker_get_state. Nothing was written.');
    }
    return $ref;
}

/** A list of short strings, each within bounds. */
function mcp_rv_strings(array $o, string $key, string $at, int $max, int $minLen = 3, int $maxLen = 300): array
{
    $out = [];
    foreach (mcp_rv_list($o, $key, $at, $max) as $i => $s) {
        if (!is_string($s) || trim($s) === '') {
            throw new McpError("$at.$key" . "[$i] must be a non-empty string. Nothing was written.");
        }
        $n = mb_strlen(trim($s));
        if ($n < $minLen || $n > $maxLen) {
            throw new McpError("$at.$key" . "[$i] is $n characters; it must be $minLen to $maxLen. Nothing was written.");
        }
        $out[] = trim($s);
    }
    return $out;
}

/**
 * The sections of a lesson review, validated whole.
 *
 * `$ctx` is what the review is checked against:
 *   known     every topic ref of the subject
 *   topics    ref => topic row (for status checks on next_what.new)
 *   resources stored resource titles for the subject
 *   moved     ref => true where this session's updates changed the status
 *   outcomes  ref => retrieval_outcome this session recorded
 *
 * @return array<string,mixed> the cleaned sections, ready to store
 */
function mcp_lesson_sections(mixed $raw, array $ctx): array
{
    if (!is_array($raw) || ($raw && array_is_list($raw))) {
        throw new McpError('review must be an object holding the review\'s sections (one_sentence, progress, '
            . 'independent, supported, big_picture, planner, missing_evidence and the rest). Nothing was written.');
    }
    $known = [
        'topic_refs', 'objective', 'resources',
        'one_sentence', 'progress', 'independent', 'supported', 'errors', 'retention', 'process', 'helped',
        'hindered', 'confidence', 'signals', 'big_picture', 'next_what', 'next_how', 'do_differently', 'continue',
        'watch', 'learner_voice', 'planner', 'missing_evidence',
    ];
    foreach (array_keys($raw) as $k) {
        if (!in_array($k, $known, true)) {
            throw new McpError("review has an unknown key \"$k\". The keys are: " . implode(', ', $known)
                . '. Nothing was written.');
        }
    }
    $refs  = $ctx['known'];
    $at    = 'review';
    $out   = [];

    // Header. topic_refs bound section 2; the rest of the review may cite
    // any ref of the subject.
    $topicRefs = [];
    foreach (mcp_rv_list($raw, 'topic_refs', $at, 20) as $i => $r) {
        $topicRefs[] = mcp_rv_ref(['ref' => $r], "$at.topic_refs[$i]", $refs);
    }
    $out['topic_refs'] = array_values(array_unique($topicRefs));
    $objective = mcp_rv_text($raw, 'objective', $at, false, 5, 300);
    if ($objective !== null) {
        $out['objective'] = $objective;
    }
    $resources = [];
    foreach (mcp_rv_list($raw, 'resources', $at, 20) as $i => $r) {
        $rAt = "$at.resources[$i]";
        if (is_string($r)) {
            $r = ['title' => $r];
        }
        $r = mcp_rv_object($r, $rAt);
        $title = mcp_rv_text($r, 'title', $rAt, true, 1, 200);
        $unlisted = !empty($r['unlisted']);
        if (!$unlisted && !in_array($title, $ctx['resources'], true)) {
            throw new McpError("$rAt \"$title\" is not a stored resource of this subject. Either name a stored title "
                . '(tracker_list_resources) or mark it { title, unlisted: true }. Nothing was written.');
        }
        $resources[] = $unlisted ? ['title' => $title, 'unlisted' => true] : ['title' => $title];
    }
    if ($resources) {
        $out['resources'] = $resources;
    }

    // 1
    $out['one_sentence'] = mcp_rv_text($raw, 'one_sentence', $at, true, 10, 200);

    // 2
    $progress = [];
    foreach (mcp_rv_list($raw, 'progress', $at, 20) as $i => $p) {
        $pAt = "$at.progress[$i]";
        $p   = mcp_rv_object($p, $pAt);
        $ref = mcp_rv_ref($p, $pAt, $refs);
        if ($out['topic_refs'] && !in_array($ref, $out['topic_refs'], true)) {
            throw new McpError("$pAt.ref \"$ref\" is not in topic_refs; add it there or drop the entry. Nothing was written.");
        }
        $entry = [
            'ref'         => $ref,
            'status_seen' => mcp_rv_enum($p, 'status_seen', $pAt, STATUS_ORDER),
            'evidence'    => mcp_rv_text($p, 'evidence', $pAt),
            'implication' => mcp_rv_text($p, 'implication', $pAt),
        ];
        $proposed = mcp_rv_enum($p, 'proposed_status', $pAt, STATUS_ORDER, false);
        if ($proposed !== null) {
            if (!empty($ctx['moved'][$ref])) {
                throw new McpError("$pAt carries proposed_status, but this session's updates[] already moved $ref. "
                    . 'A proposal is for a status the updates did not move. Nothing was written.');
            }
            $entry['proposed_status'] = $proposed;
        }
        $progress[] = $entry;
    }
    $out['progress'] = $progress;
    if (!$out['topic_refs']) {
        $out['topic_refs'] = array_values(array_unique(array_column($progress, 'ref')));
    }

    // 3, 4
    $out['independent'] = mcp_rv_text($raw, 'independent', $at);
    $out['supported']   = mcp_rv_text($raw, 'supported', $at);

    // 5
    $errors = [];
    foreach (mcp_rv_list($raw, 'errors', $at, 30) as $i => $e) {
        $eAt = "$at.errors[$i]";
        $e   = mcp_rv_object($e, $eAt);
        $errors[] = [
            'ref'        => mcp_rv_ref($e, $eAt, $refs),
            'error_type' => mcp_rv_enum($e, 'error_type', $eAt, REVIEW_ERROR_TYPES),
            'what'       => mcp_rv_text($e, 'what', $eAt),
            'why_type'   => mcp_rv_text($e, 'why_type', $eAt),
            'response'   => mcp_rv_text($e, 'response', $eAt),
        ];
    }
    $out['errors'] = $errors;

    // 6 — every retention judgement is backed by a retrieval_outcome in
    // updates[], so the spacing schedule always hears it.
    $retRaw = $raw['retention'] ?? [];
    if ($retRaw === null) {
        $retRaw = [];
    }
    $retRaw = mcp_rv_object($retRaw ?: [], "$at.retention");
    foreach (array_keys($retRaw) as $k) {
        if (!in_array($k, ['retrieved', 'prompted', 'not_retrieved', 'schedule'], true)) {
            throw new McpError("$at.retention has an unknown key \"$k\"; the keys are retrieved, prompted, "
                . 'not_retrieved, schedule. Nothing was written.');
        }
    }
    $retention = [];
    foreach (['retrieved', 'prompted', 'not_retrieved', 'schedule'] as $list) {
        $items = [];
        foreach (mcp_rv_list($retRaw, $list, "$at.retention", 20) as $i => $x) {
            $xAt = "$at.retention.$list" . "[$i]";
            $x   = mcp_rv_object($x, $xAt);
            $ref = mcp_rv_ref($x, $xAt, $refs);
            if (isset(REVIEW_RETENTION[$list])) {
                $want = REVIEW_RETENTION[$list];
                $got  = $ctx['outcomes'][$ref] ?? null;
                if ($got !== $want) {
                    throw new McpError("$xAt lists $ref as $list, which needs retrieval_outcome '$want' on that ref in "
                        . 'updates[]' . ($got === null ? ' — none was given' : " — '$got' was given")
                        . '. Retention is scheduled through the outcome, so the two must agree. Nothing was written.');
                }
            }
            $items[] = ['ref' => $ref, 'evidence' => mcp_rv_text($x, 'evidence', $xAt, true, 5, 300)];
        }
        $retention[$list] = $items;
    }
    $out['retention'] = $retention;

    // 7
    $process = [];
    foreach (mcp_rv_list($raw, 'process', $at, 30) as $i => $p) {
        $pAt = "$at.process[$i]";
        $p   = mcp_rv_object($p, $pAt);
        $entry = [
            'area'           => mcp_rv_enum($p, 'area', $pAt, REVIEW_PROCESS_AREAS),
            'basis'          => mcp_rv_enum($p, 'basis', $pAt, REVIEW_BASES),
            'evidence'       => mcp_rv_text($p, 'evidence', $pAt),
            'interpretation' => mcp_rv_text($p, 'interpretation', $pAt),
            'implication'    => mcp_rv_text($p, 'implication', $pAt),
        ];
        // Study principle 5: blanks against attempts is a count, and the
        // avoidance entry is where it lives.
        if ($entry['area'] === 'avoidance' && !preg_match('/\d/', $entry['evidence'])) {
            throw new McpError("$pAt is the avoidance entry and its evidence carries no number. State blanks against "
                . 'attempts numerically (e.g. "0 blanks in 6 questions"). Nothing was written.');
        }
        $process[] = $entry;
    }
    $out['process'] = $process;

    // 8, 9
    foreach (['helped' => ['helpful', 'neutral'], 'hindered' => ['difficulty', 'neutral']] as $key => $effects) {
        $list = [];
        foreach (mcp_rv_list($raw, $key, $at, 20) as $i => $h) {
            $hAt = "$at.$key" . "[$i]";
            $h   = mcp_rv_object($h, $hAt);
            $list[] = [
                'method'   => mcp_rv_enum($h, 'method', $hAt, REVIEW_METHODS),
                'effect'   => mcp_rv_enum($h, 'effect', $hAt, $effects, false, $effects[0]),
                'evidence' => mcp_rv_text($h, 'evidence', $hAt),
            ];
        }
        $out[$key] = $list;
    }

    // 10 — omitted when there is no evidence; an empty list is the honest value.
    if (array_key_exists('confidence', $raw) && $raw['confidence'] !== null) {
        $conf = [];
        foreach (mcp_rv_list($raw, 'confidence', $at, 20) as $i => $c) {
            $cAt = "$at.confidence[$i]";
            $c   = mcp_rv_object($c, $cAt);
            $conf[] = [
                'ref'         => mcp_rv_ref($c, $cAt, $refs),
                'confidence'  => mcp_rv_enum($c, 'confidence', $cAt, REVIEW_LEVELS),
                'accuracy'    => mcp_rv_enum($c, 'accuracy', $cAt, REVIEW_LEVELS),
                'evidence'    => mcp_rv_text($c, 'evidence', $cAt),
                'implication' => mcp_rv_text($c, 'implication', $cAt),
            ];
        }
        $out['confidence'] = $conf;
    }

    // 11
    $signals = [];
    $seenKeys = [];
    foreach (mcp_rv_list($raw, 'signals', $at, 20) as $i => $g) {
        $gAt = "$at.signals[$i]";
        $g   = mcp_rv_object($g, $gAt);
        $key = mcp_rv_signal_key($g['key'] ?? null, "$gAt.key");
        if (isset($seenKeys[$key])) {
            throw new McpError("$gAt.key \"$key\" appears twice in signals. Nothing was written.");
        }
        $seenKeys[$key] = true;
        $entry = [
            'key'       => $key,
            'kind'      => mcp_rv_enum($g, 'kind', $gAt, SIGNAL_KINDS),
            'statement' => mcp_rv_text($g, 'statement', $gAt, true, 10, 300),
            'strength'  => mcp_rv_enum($g, 'strength', $gAt, SIGNAL_STRENGTHS),
            'direction' => mcp_rv_enum($g, 'direction', $gAt, SIGNAL_DIRECTIONS, false, 'supports'),
            'evidence'  => mcp_rv_text($g, 'evidence', $gAt),
        ];
        $next = mcp_rv_text($g, 'next_test', $gAt, false, 10, 300);
        if ($next !== null) {
            $entry['next_test'] = $next;
        }
        $signals[] = $entry;
    }
    $out['signals'] = $signals;

    // 12
    $bp = mcp_rv_object($raw['big_picture'] ?? null, "$at.big_picture");
    $out['big_picture'] = [
        'readiness' => mcp_rv_enum($bp, 'readiness', "$at.big_picture", REVIEW_READINESS),
        'why'       => mcp_rv_text($bp, 'why', "$at.big_picture"),
    ];

    // 13
    $nwRaw = $raw['next_what'] ?? [];
    $nwRaw = mcp_rv_object($nwRaw === null ? [] : ($nwRaw ?: []), "$at.next_what");
    foreach (array_keys($nwRaw) as $k) {
        if (!in_array($k, REVIEW_NEXT_WHAT, true)) {
            throw new McpError("$at.next_what has an unknown key \"$k\"; the keys are " . implode(', ', REVIEW_NEXT_WHAT)
                . '. Nothing was written.');
        }
    }
    $nextWhat = [];
    foreach (REVIEW_NEXT_WHAT as $k) {
        $list = [];
        foreach (mcp_rv_list($nwRaw, $k, "$at.next_what", 20) as $i => $r) {
            $ref = mcp_rv_ref(['ref' => $r], "$at.next_what.$k" . "[$i]", $refs);
            if ($k === 'new') {
                $status = (string) ($ctx['topics'][$ref]['status'] ?? '');
                if (in_array($status, ['secure', 'examready'], true)) {
                    throw new McpError("$at.next_what.new names $ref, which is already $status — it is not new "
                        . 'material. Put it under opening_retrieval or challenge. Nothing was written.');
                }
            }
            $list[] = $ref;
        }
        $nextWhat[$k] = array_values(array_unique($list));
    }
    $out['next_what'] = $nextWhat;

    // 14
    $nhRaw  = $raw['next_how'] ?? [];
    $nhRaw  = mcp_rv_object($nhRaw === null ? [] : ($nhRaw ?: []), "$at.next_how");
    $stages = [];
    foreach (mcp_rv_list($nhRaw, 'stages', "$at.next_how", 12) as $i => $st) {
        $sAt = "$at.next_how.stages[$i]";
        $st  = mcp_rv_object($st, $sAt);
        $stages[] = [
            'stage'  => mcp_rv_enum($st, 'stage', $sAt, REVIEW_STAGE_KEYS),
            'method' => mcp_rv_enum($st, 'method', $sAt, REVIEW_METHODS),
            'why'    => mcp_rv_text($st, 'why', $sAt, true, 5, 300),
        ];
    }
    $out['next_how'] = ['stages' => $stages];

    // 15, 16
    $out['do_differently'] = mcp_rv_strings($raw, 'do_differently', $at, 3);
    $out['continue']       = mcp_rv_strings($raw, 'continue', $at, 3);

    // 17
    $watch = [];
    foreach (mcp_rv_list($raw, 'watch', $at, 3) as $i => $w) {
        $wAt = "$at.watch[$i]";
        $w   = mcp_rv_object($w, $wAt);
        $watch[] = [
            'key'             => mcp_rv_signal_key($w['key'] ?? null, "$wAt.key"),
            'what_to_observe' => mcp_rv_text($w, 'what_to_observe', $wAt, true, 10, 300),
        ];
    }
    $out['watch'] = $watch;

    // 18 — a quote or nothing; never a paraphrase.
    $voice = [];
    foreach (mcp_rv_list($raw, 'learner_voice', $at, 10) as $i => $v) {
        $vAt = "$at.learner_voice[$i]";
        $v   = mcp_rv_object($v, $vAt);
        $entry = ['quote' => mcp_rv_text($v, 'quote', $vAt, true, 2, 300)];
        $context = mcp_rv_text($v, 'context', $vAt, false, 3, 300);
        if ($context !== null) {
            $entry['context'] = $context;
        }
        $voice[] = $entry;
    }
    $out['learner_voice'] = $voice;

    // 19 — the six lines the next session reads first.
    $planner = mcp_rv_object($raw['planner'] ?? null, "$at.planner");
    foreach (array_keys($planner) as $k) {
        if (!in_array($k, REVIEW_PLANNER, true)) {
            throw new McpError("$at.planner has an unknown key \"$k\"; the six are " . implode(', ', REVIEW_PLANNER)
                . '. Nothing was written.');
        }
    }
    $clean = [];
    foreach (REVIEW_PLANNER as $k) {
        $clean[$k] = mcp_review_line($planner[$k] ?? null, "$at.planner.$k", 3, 200);
    }
    $out['planner'] = $clean;

    // The closing list. Required, may be empty: "nothing missing" is a claim too.
    if (!array_key_exists('missing_evidence', $raw)) {
        throw new McpError("$at.missing_evidence is required — the list of what the review could not see. "
            . 'An empty list is allowed. Nothing was written.');
    }
    $out['missing_evidence'] = mcp_rv_strings($raw, 'missing_evidence', $at, 20);

    return $out;
}

/** A stable slug for a signal or watch: lower-case, digits and hyphens. */
function mcp_rv_signal_key(mixed $v, string $at): string
{
    if (!is_string($v) || !preg_match('/^[a-z0-9][a-z0-9-]{1,60}$/', $v)) {
        throw new McpError("$at must be a stable slug of 2-61 lower-case letters, digits and hyphens, "
            . "e.g. 'model-then-immediate-practice'. Nothing was written.");
    }
    return $v;
}

/**
 * Every ref a review mentions, for the snapshot.
 *
 * @return array<int,string>
 */
function mcp_review_refs(array $sections): array
{
    $refs = $sections['topic_refs'] ?? [];
    foreach (['progress', 'errors', 'confidence'] as $k) {
        foreach ($sections[$k] ?? [] as $x) {
            $refs[] = $x['ref'];
        }
    }
    foreach ($sections['retention'] ?? [] as $list) {
        foreach ($list as $x) {
            $refs[] = $x['ref'];
        }
    }
    foreach ($sections['next_what'] ?? [] as $list) {
        foreach ($list as $r) {
            $refs[] = $r;
        }
    }
    return array_values(array_unique($refs));
}

/**
 * The context a review is validated against, from the record: topics,
 * resource titles, and which refs this session moved and with what
 * retrieval outcome. `$updates` are the cleaned updates of a
 * tracker_log_session call; for a re-versioning they are derived from the
 * session's topic_changes and its previous snapshot.
 *
 * @param array<int,array{ref:string,status:?string,outcome:?string}> $updates
 */
function mcp_review_context(Store $store, string $slug, array $updates): array
{
    $topics = [];
    foreach ($store->listTopics($slug) as $t) {
        $topics[(string) $t['ref']] = $t;
    }
    $moved    = [];
    $outcomes = [];
    foreach ($updates as $u) {
        $ref = (string) $u['ref'];
        if (!isset($topics[$ref])) {
            continue;
        }
        if ($u['status'] !== null && $u['status'] !== (string) $topics[$ref]['status']) {
            $moved[$ref] = true;
        }
        $outcome = $u['outcome'] ?? retrieval_infer_outcome((string) $topics[$ref]['status'], $u['status'] ?? (string) $topics[$ref]['status']);
        if ($outcome !== null) {
            $outcomes[$ref] = $outcome;
        }
    }
    return [
        'known'     => array_keys($topics),
        'topics'    => $topics,
        'resources' => array_values(array_unique(array_column($store->listResources($slug), 'title'))),
        'moved'     => $moved,
        'outcomes'  => $outcomes,
    ];
}

/** The same context for a session already logged, read back from the record. */
function mcp_review_context_for_session(Store $store, string $slug, int $sessionId): array
{
    $ctx   = mcp_review_context($store, $slug, []);
    $moved = [];
    foreach ($store->changesForSession($sessionId) as $c) {
        if ((string) ($c['from_status'] ?? '') !== (string) $c['to_status']) {
            $moved[(string) $c['ref']] = true;
        }
    }
    $ctx['moved'] = $moved;
    // Outcomes: the previous version's snapshot knows them exactly; without
    // one, the retrieval history dated the session's day is the best the
    // record can say.
    $previous = $store->lessonReview($sessionId);
    if ($previous !== null && is_array($previous['snapshot']['outcomes'] ?? null)) {
        $ctx['outcomes'] = $previous['snapshot']['outcomes'];
    } else {
        $ctx['outcomes'] = $store->lessonSnapshot($sessionId, [])['outcomes'];
    }
    return $ctx;
}

/**
 * Write a validated review: its error rows, its signals and watch signals,
 * the review row with the server's snapshot, and the planner into
 * next_steps when the session has none. Returns the lines the tool prints.
 *
 * Must run inside the caller's transaction when it accompanies a session
 * log, so a session never lands half-reviewed.
 *
 * @param ?array<string,?string> $before   ref => status before this session's updates (log time only)
 * @param ?array<string,string>  $outcomes ref => retrieval_outcome recorded (log time only)
 * @return array{row:array,status:string,lines:array<int,string>}
 */
function mcp_apply_review(
    Store $store,
    string $slug,
    int $sessionId,
    array $sections,
    string $stage,
    string $writtenBy,
    ?string $note,
    ?array $before = null,
    ?array $outcomes = null
): array {
    $lines = [];

    $store->addReviewErrors($sessionId, $slug, $sections['errors']);

    $entries = $sections['signals'];
    foreach ($sections['watch'] as $w) {
        $entries[] = [
            'key' => $w['key'], 'kind' => 'watch', 'statement' => $w['what_to_observe'], 'strength' => 'one_off',
            'direction' => 'supports', 'evidence' => $w['what_to_observe'],
        ];
    }
    foreach ($entries as $g) {
        $res = $store->upsertSignal([
            'subject_slug' => $slug,
            'key'          => $g['key'],
            'kind'         => $g['kind'],
            'statement'    => $g['statement'],
            'strength'     => $g['strength'],
            'next_test'    => $g['next_test'] ?? null,
            'session_id'   => $sessionId,
            'direction'    => $g['direction'] ?? 'supports',
            'evidence'     => $g['evidence'],
        ]);
        if ($res['created']) {
            $lines[] = 'Signal opened: ' . $g['key'] . ' (' . $g['kind'] . ') at one_off, #' . $res['id'] . '.';
        } elseif ($res['strength_from'] !== $res['strength_to']) {
            $lines[] = 'Signal ' . $g['key'] . ' #' . $res['id'] . ': ' . $res['strength_from'] . ' → '
                . $res['strength_to'] . '.';
        }
        if ($res['refused'] !== null) {
            $lines[] = 'REFUSED strength on ' . $g['key'] . ' #' . $res['id'] . ': claimed ' . $g['strength'] . '; '
                . $res['refused'] . '. Held at ' . $res['strength_to'] . '; the evidence row was kept.';
        }
    }

    $snapshot = $store->lessonSnapshot($sessionId, mcp_review_refs($sections), $before, $outcomes);
    $res      = $store->addLessonReview([
        'session_id'   => $sessionId,
        'subject_slug' => $slug,
        'stage'        => $stage,
        'written_by'   => $writtenBy,
        'snapshot'     => $snapshot,
        'sections'     => $sections,
        'note'         => $note,
    ]);

    // The planner is the field the next session reads. Copied into
    // next_steps when the caller left it empty, so the existing continuity
    // path carries it with no change to any tutor skill.
    $session = $store->sessionById($sessionId);
    if ($session && ($session['next_steps'] === null || trim((string) $session['next_steps']) === '')) {
        $store->amendSession($slug, $sessionId, ['next_steps' => review_planner_text($sections['planner'])]);
        $lines[] = 'next_steps was empty, so the planner was copied into it.';
    }
    return ['row' => $res['row'], 'status' => $res['status'], 'lines' => $lines];
}

/**
 * The last_review block of tracker_review_queue: the planner first, then the
 * things to watch, the open signals, last time's errors and the readiness
 * call. Or the MISSING line when the last session owed a review and has none.
 *
 * @return array<int,string>
 */
function mcp_last_review_lines(Store $store, string $slug): array
{
    $last = $store->lastSession($slug);
    if ($last === null) {
        return [];
    }
    $review = $store->lessonReview((int) $last['id']);
    if ($review === null) {
        if ((int) ($last['review_required'] ?? 0) === 1) {
            return ["### last_review — MISSING for session {$last['id']}; plan from last_session.next_steps and note "
                . 'the gap in this session\'s review.'];
        }
        // The last session did not need one; the most recent review still
        // carries the standing plan, so it is shown with its session named.
        $pair = $store->lastLessonReview($slug);
        if ($pair === null) {
            return ['### last_review', 'No lesson review yet for this subject.'];
        }
        $review = $pair['review'];
        $last   = $pair['session'];
    }
    $s     = $review['sections'];
    $lines = ["### last_review  (session {$last['id']}, {$last['date']}, stage {$review['stage']}, v{$review['version']})"];
    $lines[] = 'PLANNER';
    foreach (review_planner_lines($s['planner'] ?? []) as $l) {
        $lines[] = $l;
    }
    $lines[] = 'THINGS TO WATCH';
    $watch = $store->signals(['subject' => $slug, 'kind' => 'watch', 'status' => 'open']);
    if (!$watch) {
        $lines[] = '  - none open';
    }
    foreach ($watch as $g) {
        $lines[] = '  - ' . $g['statement'] . '  (opened session ' . $g['opened_session'] . ', #' . $g['id'] . ')';
    }
    $lines[] = 'OPEN SIGNALS (this subject, then cross-subject)';
    $open = array_values(array_filter(
        $store->signals(['subject' => $slug, 'status' => 'open', 'include_cross' => true]),
        static fn(array $g): bool => $g['kind'] !== 'watch'
    ));
    if (!$open) {
        $lines[] = '  - none open';
    }
    foreach (array_slice($open, 0, 12) as $g) {
        $lines[] = '  - ' . signal_line($g);
    }
    if (count($open) > 12) {
        $lines[] = '  - …and ' . (count($open) - 12) . ' more — tracker_signals(subject: "' . $slug . '").';
    }
    $lines[] = 'ERRORS LAST TIME';
    $errors = $store->reviewErrorsForSession((int) $last['id']);
    if (!$errors) {
        $lines[] = '  - none recorded';
    }
    foreach ($errors as $e) {
        $lines[] = '  - ' . $e['ref'] . ' ' . $e['error_type'] . ' — ' . $e['what'] . '  → ' . $e['response'];
    }
    $bp = $s['big_picture'] ?? [];
    $lines[] = 'READINESS: ' . ($bp['readiness'] ?? '?') . ' — ' . ($bp['why'] ?? '');
    $lines[] = 'Say in one line what this session will observe (check_whether and the things to watch).';
    return $lines;
}

/** A session's review state in a word, for tracker_today's block lines. */
function mcp_review_word(?array $session): string
{
    if ($session === null) {
        return '';
    }
    if ($session['review_id'] !== null) {
        return 'reviewed';
    }
    return (int) ($session['review_required'] ?? 0) === 1 ? 'review missing' : '—';
}
