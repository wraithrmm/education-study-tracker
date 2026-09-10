<?php
/**
 * The MCP endpoint: stateless JSON-RPC over a single POST, which is all the
 * Node version did too (it used the SDK's streamable transport with
 * enableJsonResponse and no session id). Implementing the wire format directly
 * costs less than it sounds — initialize, tools/list, tools/call, ping — and
 * removes the last reason to need a JIT on this host.
 *
 * Two rules live in the schema rather than in good intentions: every status
 * change needs an evidence string of at least ten characters, and topic checks
 * are never grade-converted.
 *
 * Tool descriptions lead with a USE WHEN line naming the situations that should
 * trigger them, because a description that only says what a tool does leaves the
 * model to guess when it matters.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

const TOPIC_HEADER =
    "| Ref | Topic | Strand | Tier | Status | Last touched | Loose end |\n|---|---|---|---|---|---|---|";

final class McpError extends Exception
{
}

// The lesson review's validation and write, shared by tracker_log_session
// and tracker_save_lesson_review.
require_once __DIR__ . '/mcp_review.php';

function mcp_text(string $s): array
{
    return ['content' => [['type' => 'text', 'text' => $s]]];
}

function mcp_topic_line(array $t): string
{
    return '| ' . $t['ref'] . ' | ' . $t['name'] . ' | ' . $t['strand'] . ' | ' . $t['tier']
        . ' | ' . (STATUS_LABEL[$t['status']] ?? $t['status'])
        . ' | ' . ($t['last_touched'] ?? '—')
        . ' | ' . ($t['watch'] ? str_replace('|', '/', $t['watch']) : '') . ' |';
}

/** One resource as a bullet: kind, title, link, and how to use it. */
function mcp_resource_line(array $r, bool $showRef = false): string
{
    $bits = '- [' . $r['kind'] . '] **' . $r['title'] . '**';
    if ($showRef && $r['ref'] !== '') {
        $bits .= ' (' . $r['ref'] . ')';
    }
    if ($r['url']) {
        $bits .= ' — ' . $r['url'];
    }
    if ($r['note']) {
        $bits .= ' — ' . $r['note'];
    }
    return $bits;
}

/**
 * The subject-wide resources under a queue, kept to a size a session can
 * read. Past papers and mark schemes are the bulk of a subject's library —
 * seventy lines for English Literature — and a session opening on the queue
 * needs the notes and the spec, not every series since 2017. Over the cap,
 * papers are counted and pointed at rather than listed.
 *
 * @param array<int,array> $rows
 * @return array<int,string>
 */
function mcp_resource_group(string $slug, array $rows): array
{
    if (count($rows) <= 12) {
        return array_map(static fn($r) => mcp_resource_line($r), $rows);
    }
    $papers = array_values(array_filter($rows, static fn($r) => $r['kind'] === 'paper'));
    $rest   = array_values(array_filter($rows, static fn($r) => $r['kind'] !== 'paper'));
    $lines  = array_map(static fn($r) => mcp_resource_line($r), array_slice($rest, 0, 12));
    if (count($rest) > 12) {
        $lines[] = '- …and ' . (count($rest) - 12) . ' more — tracker_list_resources(subject: "' . $slug . '") lists them.';
    }
    if ($papers) {
        $lines[] = '- [paper] ' . count($papers) . ' past papers and mark schemes are stored — '
            . 'tracker_list_resources(subject: "' . $slug . '") for the list, or ask for a series by name.';
    }
    return $lines;
}

/**
 * The last-session block: what the previous session planned, as a JSON
 * object so next_steps arrives verbatim and a caller can read it without
 * parsing prose. Null when there is no session.
 */
function mcp_last_session_text(Store $store, string $slug): string
{
    $block = $store->lastSessionBlock($slug);
    $json  = json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $lines = ['### last_session', '```json', $json, '```'];
    if ($block === null) {
        $lines[] = 'No sessions logged for this subject yet.';
        return implode("\n", $lines);
    }
    if ($block['stale']) {
        $lines[] = 'stale: the last session was ' . $block['days_ago'] . ' days ago. Its plan is still shown; '
            . 'decide whether it still holds.';
    }
    $lines[] = 'Open on next_steps' . ($block['unfinished'] !== null ? ' and on the unfinished item' : '')
        . '; both are the previous session\'s own words.';
    return implode("\n", $lines);
}

/**
 * Open unfinished items as lines. The ids are what resolves: [] takes.
 *
 * @param array<int,array> $items Store::openUnfinished()
 * @return array<int,string>
 */
function mcp_unfinished_lines(array $items): array
{
    $out = [];
    foreach ($items as $u) {
        $out[] = '- **Session ' . $u['session_id'] . '** (' . $u['date'] . ', ' . $u['days_open'] . ' day'
            . ($u['days_open'] === 1 ? '' : 's') . ' open'
            . ($u['block_key'] !== null ? ', block ' . $u['block_key'] : '')
            . ($u['stale'] ? ', STALE' : '') . '): ' . $u['text']
            . ($u['refs'] ? ' — refs ' . implode(', ', $u['refs']) : '');
    }
    return $out;
}

/** A session's unfinished item as one line: open, closed by whom, or auto-closed. */
function mcp_unfinished_status(array $x): string
{
    $text = 'Unfinished: ' . $x['unfinished'];
    $refs = Store::decodeRefs($x['unfinished_refs'] ?? null);
    if ($refs) {
        $text .= ' (refs ' . implode(', ', $refs) . ')';
    }
    if (($x['unfinished_closed_at'] ?? null) === null) {
        $days = tt_days_between((string) $x['date'], tt_today());
        return $text . ' — OPEN, ' . $days . ' day' . ($days === 1 ? '' : 's')
            . ($days > UNFINISHED_STALE_DAYS ? ', STALE' : '');
    }
    [$on] = tt_local((string) $x['unfinished_closed_at']);
    $by   = $x['unfinished_closed_by_session_id'] ?? null;
    return $text . ' — closed ' . $on . ($by ? ' by session ' . $by : '')
        . ': ' . ($x['unfinished_closed_reason'] ?? '');
}

/**
 * Validate a resolves[] list against the record before anything is written.
 *
 * Each id must be a session of this subject with an open unfinished item.
 * One already closed by a session is not an error: applying resolves twice
 * is a no-op, the rule client_run_id already keeps for practice. Anything
 * else is refused naming the id, so a typo cannot close the wrong item.
 *
 * @return array{close:array<int,int>,already:array<int,string>}
 */
function mcp_check_resolves(Store $store, string $slug, mixed $raw): array
{
    if ($raw === null) {
        return ['close' => [], 'already' => []];
    }
    if (!is_array($raw)) {
        throw new McpError('resolves must be an array of session ids.');
    }
    if (count($raw) > 20) {
        throw new McpError('resolves may hold at most 20 session ids.');
    }
    $close   = [];
    $already = [];
    foreach ($raw as $id) {
        if (!is_int($id) && !(is_numeric($id) && (int) $id == $id)) {
            throw new McpError('resolves entries must be integer session ids.');
        }
        $id  = (int) $id;
        $row = $store->sessionById($id);
        if (!$row) {
            throw new McpError("resolves names session $id, and there is no such session. "
                . 'The ids are in the UNFINISHED group of tracker_review_queue. Nothing was written.');
        }
        if ((string) $row['subject_slug'] !== $slug) {
            throw new McpError("resolves names session $id, which belongs to " . $row['subject_slug']
                . ", not $slug. A session can only resolve unfinished work in its own subject. Nothing was written.");
        }
        if ($row['unfinished'] === null) {
            throw new McpError("resolves names session $id, which has no unfinished work recorded. "
                . 'Check the UNFINISHED group of tracker_review_queue. Nothing was written.');
        }
        if ($row['void_reason'] !== null) {
            throw new McpError("resolves names session $id, which is void. Nothing was written.");
        }
        if ($row['unfinished_closed_at'] !== null) {
            $already[] = "session $id was already closed"
                . ($row['unfinished_closed_by_session_id'] ? ' by session ' . $row['unfinished_closed_by_session_id'] : '')
                . ' (' . $row['unfinished_closed_reason'] . ')';
            continue;
        }
        $close[] = $id;
    }
    return ['close' => array_values(array_unique($close)), 'already' => $already];
}

/**
 * The nudge. When a subject still has open unfinished work and the session
 * just logged neither resolved it nor set a new item, say so in the result.
 * A warning, never an error: the service never gates teaching.
 */
function mcp_unfinished_warning(Store $store, string $slug, int $newSessionId): ?string
{
    $open = array_values(array_filter(
        $store->openUnfinished($slug),
        static fn(array $u): bool => $u['session_id'] !== $newSessionId
    ));
    if (!$open) {
        return null;
    }
    $u = $open[0];
    $more = count($open) > 1 ? ' (' . (count($open) - 1) . ' more open — see tracker_review_queue)' : '';
    return "warning: Session {$u['session_id']} ({$u['date']}) is still marked unfinished: '{$u['text']}'. "
        . "This session did not resolve it$more. If it was completed, log it with resolves: [{$u['session_id']}] "
        . "— tracker_amend_session(subject: \"$slug\", session_id: $newSessionId, resolves: [{$u['session_id']}]) "
        . 'closes it against this session.';
}

/**
 * The grade for a whole attempt. Only a full paper sitting converts: a check
 * on a handful of topics cannot stand in for a paper, so it reports a
 * percentage and says so.
 */
function mcp_attempt_outcome(array $subject, array $attempt): string
{
    $max = (float) $attempt['max'];
    if ($max <= 0) {
        return '—';
    }
    if ($attempt['kind'] === 'check') {
        return round(((float) $attempt['score'] / $max) * 100) . '% (no grade)';
    }
    return '≈ grade ' . gradeFor($subject, (float) $attempt['score'], $max, (string) $attempt['tier']);
}

/** Shared subject lookup and its error message. */
function mcp_resolve(Store $store, string $slug): array
{
    $subject = $store->getSubject($slug);
    if ($subject) {
        return ['subject' => $subject];
    }
    $known = array_column($store->listSubjects(), 'slug');
    return ['error' => $known
        ? "No subject \"$slug\". Known subjects: " . implode(', ', $known) . '. Call tracker_list_subjects first.'
        : "No subject \"$slug\", and none exist yet. Create one with tracker_create_subject."];
}

// ---- validation ---------------------------------------------------------

function mcp_str(array $a, string $k, bool $required, int $min = 0, int $max = PHP_INT_MAX, ?string $default = null): ?string
{
    if (!isset($a[$k]) || $a[$k] === null || $a[$k] === '') {
        if ($required) {
            throw new McpError("$k is required.");
        }
        return $default;
    }
    if (!is_string($a[$k])) {
        throw new McpError("$k must be a string.");
    }
    $len = mb_strlen($a[$k]);
    if ($len < $min) {
        throw new McpError("$k must be at least $min characters.");
    }
    if ($len > $max) {
        throw new McpError("$k must be at most $max characters.");
    }
    return $a[$k];
}

function mcp_num(array $a, string $k, bool $required, ?float $min = null, ?float $max = null, ?float $default = null): ?float
{
    if (!isset($a[$k]) || $a[$k] === null) {
        if ($required) {
            throw new McpError("$k is required.");
        }
        return $default;
    }
    if (!is_numeric($a[$k])) {
        throw new McpError("$k must be a number.");
    }
    $v = (float) $a[$k];
    if ($min !== null && $v < $min) {
        throw new McpError("$k must be at least $min.");
    }
    if ($max !== null && $v > $max) {
        throw new McpError("$k must be at most $max.");
    }
    return $v;
}

function mcp_status(array $a, string $k, bool $required, ?string $default = null): ?string
{
    $v = $a[$k] ?? null;
    if ($v === null) {
        if ($required) {
            throw new McpError("$k is required.");
        }
        return $default;
    }
    if (!in_array($v, STATUS_ORDER, true)) {
        throw new McpError("$k must be one of: " . implode(', ', STATUS_ORDER) . '.');
    }
    return (string) $v;
}

function mcp_date(array $a, string $k, ?string $default = null): ?string
{
    $v = $a[$k] ?? null;
    if ($v === null || $v === '') {
        return $default;
    }
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        throw new McpError("$k must be a date in YYYY-MM-DD form.");
    }
    return $v;
}

/**
 * A practice run's played_at. The clients send ISO 8601 with a zone; the
 * record is UTC throughout, and the board's date is the first ten characters
 * of it, so the conversion happens once, here.
 */
function mcp_practice_when(mixed $v, string $at): string
{
    if ($v === null || $v === '') {
        return gmdate('Y-m-d H:i:s');
    }
    if (!is_string($v)) {
        throw new McpError("$at played_at must be an ISO 8601 string.");
    }
    $t = strtotime($v);
    if ($t === false) {
        throw new McpError("$at played_at \"$v\" is not a date I can read. Use ISO 8601, e.g. 2026-09-04T18:30:00Z.");
    }
    return gmdate('Y-m-d H:i:s', $t);
}

// ---- tool definitions ---------------------------------------------------

/** One judged block as a line an LLM and a person can both read. */
function mcp_block_line(array $b): string
{
    $status = $b['status'];
    if ($status === 'done' && !empty($b['short'])) {
        $status = 'done but SHORT (' . $b['minutes'] . ' min of ' . $b['length'] . ')';
    }
    if ($status !== 'done' && str_starts_with($status, 'done') && ($b['shape'] ?? null) === 'unmet') {
        $status .= ' — done_shape_unmet: ' . $b['shape_reason'];
    } elseif ($status === 'done' && ($b['shape'] ?? null) === 'unmet') {
        // Still done. The word says what the work was not.
        $status = 'done_shape_unmet — ' . $b['shape_reason'];
    } elseif ($status === 'done' && empty($b['shape_rule']) && !empty($b['shape_reason'])) {
        $status = 'done (' . $b['shape_reason'] . ')';
    } elseif ($status === 'excused') {
        $status = 'excused — ' . ($b['reason'] ?? 'no reason given');
    } elseif ($status === 'day_off') {
        $status = 'day off — ' . ($b['reason'] ?? '');
    } elseif ($status === 'declared') {
        $status = 'marked by hand — Dad says it happened; no work was logged'
            . ($b['reason'] ? ': ' . $b['reason'] : '');
    } elseif ($status === 'optional') {
        $status = 'optional — not ticked, and nothing counted against it';
    }
    $subs = $b['subjects'] ? implode('/', $b['subjects']) : '—';
    $ev   = '';
    foreach ($b['evidence'] ?? [] as $e) {
        $ev = '  <- ' . $e['type'] . ($e['type'] === 'tick' ? ' by ' . $e['by'] : ' #' . $e['id']);
    }
    return sprintf('#%-3d %s-%s  %s [%s]  %s',
        $b['block_key'], $b['start'], $b['end'], mcp_pad($b['label'], 44), $subs, $status) . $ev;
}

/**
 * The block with this key that actually runs on a date, or null.
 *
 * The weekday matters as much as the key: block 28 is Friday's maths block,
 * so naming it on a Monday is a mistake whichever way it was meant, and
 * accepting it would bind the work to a block that never ran that day.
 */
function mcp_find_block(Store $store, int $key, string $date): ?array
{
    $v = $store->timetableVersionOn($date);
    if (!$v) {
        return null;
    }
    $weekday = (int) (new DateTimeImmutable($date, tt_zone()))->format('N');
    foreach ($store->timetableBlocks((int) $v['id']) as $b) {
        if ($b['block_key'] === $key && $b['weekday'] === $weekday) {
            return $b;
        }
    }
    return null;
}

/** Pad to a width in characters, not bytes — these labels are full of dashes. */
function mcp_pad(string $s, int $width): string
{
    $n = mb_strlen($s, 'UTF-8');
    return $n >= $width ? $s : $s . str_repeat(' ', $width - $n);
}

/**
 * The two-sided refusal for a block_key that does not run on a date. Shared,
 * so a session logged against the wrong block and a review decision naming the
 * wrong block are refused in the same words.
 */
function mcp_no_such_block(int $key, string $date): string
{
    $day = TIMETABLE_DAYS[(int) (new DateTimeImmutable($date, tt_zone()))->format('N')];
    return "No block $key runs on $day $date. Either that key is not in the timetable in force "
        . 'then, or it belongs to another day of the week. Get the keys for that date from '
        . 'tracker_today, and check the date is the day the work was actually done.';
}

/**
 * Check a block_key supplied alongside a logged record.
 *
 * Both halves matter. A key that is not in the version in force on that date
 * would bind the work to nothing; a key whose block does not run that subject
 * would re-label the work onto the wrong block, which is exactly what the
 * explicit link exists to prevent. Either way the mismatch is named, so the
 * caller can see which of the two it got wrong.
 */
function mcp_check_block(Store $store, int $key, string $date, string $slug): array
{
    $block = mcp_find_block($store, $key, $date);
    if ($block === null) {
        throw new McpError(mcp_no_such_block($key, $date));
    }
    $subjects = tt_subjects_for($block, $date);
    if ($subjects && !in_array($slug, $subjects, true)) {
        throw new McpError(
            "Block $key on $date is \"{$block['label']}\" and runs "
            . implode('/', $subjects) . ", not $slug. A $slug record cannot fulfil it. "
            . 'Either drop block_key and let it bind by subject and date, or pass the block this work '
            . 'really was.'
        );
    }
    return $block;
}

// ---- the weekly review ---------------------------------------------------

/**
 * The week a tool was asked about: an ISO label, any date inside it, or this
 * week. Returns [iso, monday].
 *
 * @return array{0:string,1:string}
 */
function mcp_week_of(array $a, bool $required = false): array
{
    $week = mcp_str($a, 'week', $required, 1, 10);
    if ($week !== null && $week !== '') {
        $monday = tt_week_monday($week);
        if ($monday === null) {
            throw new McpError("week must look like '2026-W37'.");
        }
        return [tt_iso_week($monday), $monday];
    }
    $monday = tt_monday(mcp_date($a, 'date', null) ?: tt_today());
    return [tt_iso_week($monday), $monday];
}

/** A local stamp a person reads: 'Fri 11 Sep 14:52' from a stored UTC time. */
function mcp_review_when(string $utc): string
{
    [$date, $time] = tt_local($utc);
    return (new DateTimeImmutable($date, tt_zone()))->format('D j M') . ' ' . $time;
}

/** One written line of a review: present, single-line and within its bounds. */
function mcp_review_line(mixed $v, string $what, int $min, int $max): string
{
    if (!is_string($v) || trim($v) === '') {
        throw new McpError("$what is required and must be a non-empty line. Nothing was written.");
    }
    $s = trim($v);
    if (preg_match('/[\r\n]/', $s)) {
        throw new McpError("$what must be a single line — no line breaks. Nothing was written.");
    }
    $n = mb_strlen($s);
    if ($n < $min || $n > $max) {
        throw new McpError("$what is $n characters; it must be $min to $max. Nothing was written.");
    }
    return $s;
}

/**
 * The sections of a weekly review, validated whole.
 *
 * One bad section refuses the entire call rather than being dropped, the way
 * one invalid panel rejects a scoreboard: a key that silently does nothing is
 * how a note ends up not saying what its author thought they wrote. Every
 * refusal names the offending key or slug.
 *
 * @return array<string,mixed> the cleaned sections, ready to store
 */
function mcp_review_sections(Store $store, mixed $raw, string $monday): array
{
    if (!is_array($raw) || array_is_list($raw)) {
        throw new McpError(
            'sections must be an object with held, slipped, next, carry_forward and rotation_next.'
        );
    }
    $known = ['held', 'slipped', 'next', 'carry_forward', 'rotation_next', 'decisions'];
    foreach (array_keys($raw) as $k) {
        if (!in_array($k, $known, true)) {
            throw new McpError(
                "sections has an unknown key \"$k\". The keys are: " . implode(', ', $known)
                . '. Nothing was written.'
            );
        }
    }

    $out = [
        // A review with nothing under `slipped` is a review that has not
        // looked; if a week genuinely slipped nowhere, say so in a sentence.
        'held'    => mcp_review_line($raw['held'] ?? null, 'sections.held', 10, 600),
        'slipped' => mcp_review_line($raw['slipped'] ?? null, 'sections.slipped', 10, 600),
        'next'    => mcp_review_line($raw['next'] ?? null, 'sections.next', 10, 600),
    ];

    $carry = $raw['carry_forward'] ?? null;
    if (!is_array($carry) || array_is_list($carry) || !$carry) {
        throw new McpError('sections.carry_forward must be an object of one line per subject slug.');
    }
    if (count($carry) > 12) {
        throw new McpError('sections.carry_forward holds ' . count($carry) . ' entries; the most is 12.');
    }
    $known = array_column($store->listSubjects(), 'slug');
    $lines = [];
    foreach ($carry as $slug => $line) {
        if (!in_array((string) $slug, $known, true)) {
            throw new McpError(
                "sections.carry_forward names \"$slug\", which is not a tracked subject. Known slugs: "
                . implode(', ', $known) . '. Nothing was written.'
            );
        }
        // One line per subject is what the card layout assumes; a paragraph
        // breaks the grid.
        $lines[(string) $slug] = mcp_review_line($line, "sections.carry_forward.$slug", 3, 200);
    }
    $out['carry_forward'] = $lines;
    $out['rotation_next'] = mcp_review_line($raw['rotation_next'] ?? null, 'sections.rotation_next', 1, 80);

    $decisions = $raw['decisions'] ?? [];
    if (!is_array($decisions) || (!array_is_list($decisions) && $decisions)) {
        throw new McpError('sections.decisions must be a list of { kind, ref, decision, note? }.');
    }
    if (count($decisions) > 20) {
        throw new McpError('sections.decisions holds ' . count($decisions) . ' entries; the most is 20.');
    }
    $sunday = tt_add_days($monday, 6);
    $clean  = [];
    foreach (array_values($decisions) as $i => $d) {
        $at = 'sections.decisions[' . $i . ']';
        if (!is_array($d)) {
            throw new McpError("$at must be an object with kind, ref and decision.");
        }
        $kind = (string) ($d['kind'] ?? '');
        if (!in_array($kind, ['day_off', 'excusal'], true)) {
            throw new McpError("$at kind is '$kind'; it must be day_off or excusal.");
        }
        $decision = (string) ($d['decision'] ?? '');
        $allowed  = ['approved', 'declined', 'excused', 'not_excused', 'deferred'];
        if (!in_array($decision, $allowed, true)) {
            throw new McpError("$at decision is '$decision'; it must be one of: " . implode(', ', $allowed) . '.');
        }
        $ref = trim((string) ($d['ref'] ?? ''));
        if ($kind === 'day_off') {
            if (!ctype_digit($ref) || $store->getDayOff((int) $ref) === null) {
                throw new McpError(
                    "$at names day off \"$ref\", and there is no such record. List them with "
                    . 'tracker_days_off and use the id it shows. Nothing was written.'
                );
            }
        } else {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})#(\d+)$/', $ref, $m)) {
                throw new McpError("$at ref is \"$ref\"; an excusal ref looks like '2026-09-09#20'.");
            }
            [$date, $key] = [$m[1], (int) $m[2]];
            if ($date < $monday || $date > $sunday) {
                throw new McpError(
                    "$at names $date, which is not in the week $monday to $sunday. Nothing was written."
                );
            }
            if (mcp_find_block($store, $key, $date) === null) {
                throw new McpError($at . ': ' . mcp_no_such_block($key, $date));
            }
        }
        $note = $d['note'] ?? null;
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 200)) {
            throw new McpError("$at note must be a string of at most 200 characters, or null.");
        }
        // A decision RECORDS what was decided; it never performs it. Excusals
        // go through tracker_excuse_block and days off through
        // tracker_decide_day_off, on the parent's word, in the review chat.
        $clean[] = ['kind' => $kind, 'ref' => $ref, 'decision' => $decision, 'note' => $note];
    }
    if ($clean) {
        $out['decisions'] = $clean;
    }
    return $out;
}

/**
 * The counts a snapshot captured, in one sentence, partitioned: study blocks,
 * then movement, then the review block. Everything that prints a count of a
 * week prints it this way — a walk and a maths block in one fraction make the
 * fraction mean nothing.
 */
function mcp_snapshot_counts_line(array $snapshot): string
{
    $by = $snapshot['counts_by_tracking'] ?? [];
    $ev = $by['evidence'] ?? ['done' => 0, 'judged' => 0, 'short' => 0, 'missed' => 0,
                              'excused' => 0, 'day_off' => 0, 'declared' => 0];
    $qual = [];
    if ($ev['short']) {
        $qual[] = $ev['short'] . ' short';
    }
    if (!empty($ev['shape_unmet'])) {
        $qual[] = $ev['shape_unmet'] . ' not in shape';
    }
    $s  = 'study blocks ' . $ev['done'] . ' of ' . $ev['judged'] . ' done'
        . ($qual ? ' (' . implode(', ', $qual) . ')' : '')
        . ', ' . $ev['missed'] . ' missed, ' . $ev['excused'] . ' excused';
    // Never inside the done fraction: the parent's word is accounted for
    // beside the evidence, never as evidence.
    if (!empty($ev['declared'])) {
        $s .= ', ' . $ev['declared'] . ' marked by hand';
    }
    if (!empty($ev['day_off'])) {
        $s .= ', ' . $ev['day_off'] . ' on a day off';
    }
    $s .= ', ' . (int) ($snapshot['counts']['extra'] ?? 0) . ' extra';
    // Movement is ticked or it is not; there is no miss to report, so the
    // fraction says what it is a fraction of.
    if (!empty($by['self_report']['judged'])) {
        $s .= '; movement ticked ' . $by['self_report']['done'] . ' of '
            . $by['self_report']['judged'];
    }
    if (!empty($by['review']['judged'])) {
        $s .= '; review block ' . mcp_review_block_word($by['review']);
    }
    return $s;
}

/**
 * The Friday review block in one word: ticked, still to come, or a week that
 * ran without it. Never "missed" — it is self-reported, so there is nothing
 * to derive a miss from.
 *
 * @param array<string,int> $c the review partition of counts_by_tracking
 */
function mcp_review_block_word(array $c): string
{
    if (!empty($c['done'])) {
        return 'ticked';
    }
    if (($c['upcoming'] ?? 0) + ($c['pending'] ?? 0) + ($c['now'] ?? 0) > 0) {
        return 'pending';
    }
    return 'not ticked';
}

/** What was absent from a block that was not done, in the words the board uses. */
function mcp_absent(array $b): string
{
    if (($b['tracking'] ?? '') !== 'evidence') {
        // A self-reported block is never absent. Nothing was logged because
        // nothing was ever going to be, so "not ticked" is the whole of it
        // and none of it is counted against her.
        return 'not ticked — nothing is counted against it';
    }
    return match ($b['kind']) {
        'timed_handwritten' => 'no attempt logged',
        'retrieval'         => 'no retrieval practice logged',
        'spanish'           => 'no practice logged',
        default             => 'no session logged',
    };
}

/**
 * Who says they wrote a note. The server cannot check this — the routine and
 * the chat arrive over one connector as one client — so it is printed as the
 * claim it is, never as proof.
 */
function mcp_written_by(string $by): string
{
    return $by === 'routine' ? 'written by the Friday routine' : 'written in the review chat';
}

/** A stored review's sections, in the order the page shows them. */
function mcp_review_sections_text(array $sections): array
{
    $lines = [
        'Held: ' . ($sections['held'] ?? ''),
        'Slipped: ' . ($sections['slipped'] ?? ''),
        'Next week: ' . ($sections['next'] ?? ''),
        'Rotation next: ' . ($sections['rotation_next'] ?? ''),
    ];
    if (!empty($sections['carry_forward'])) {
        $lines[] = 'Carry-forward:';
        foreach ($sections['carry_forward'] as $slug => $line) {
            $lines[] = "  - $slug: $line";
        }
    }
    if (!empty($sections['decisions'])) {
        $lines[] = 'Decisions recorded (recorded here, performed by the tools that write them):';
        foreach ($sections['decisions'] as $d) {
            $lines[] = '  - ' . $d['kind'] . ' ' . $d['ref'] . ': ' . $d['decision']
                . (($d['note'] ?? null) ? ' — ' . $d['note'] : '');
        }
    }
    return $lines;
}

function mcp_tools(): array
{
    $readOnly = [
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    ];
    $write = [
        'readOnlyHint'    => false,
        'destructiveHint' => false,
        'idempotentHint'  => false,
        'openWorldHint'   => false,
    ];
    $statusEnum = ['type' => 'string', 'enum' => STATUS_ORDER];
    $isoDate    = ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'description' => 'YYYY-MM-DD'];
    $subjectArg = ['type' => 'string', 'minLength' => 1, 'description' => "Subject slug, e.g. 'maths'"];
    $refList    = ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40]];
    $refEvidence = ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object',
        'properties' => ['ref' => ['type' => 'string'], 'evidence' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 300]],
        'required' => ['ref', 'evidence']]];
    $methodEntry = ['type' => 'object', 'properties' => [
        'method'   => ['type' => 'string', 'enum' => REVIEW_METHODS],
        'effect'   => ['type' => 'string', 'enum' => REVIEW_EFFECTS],
        'evidence' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
    ], 'required' => ['method', 'evidence']];
    // The lesson review, as the nineteen sections of the educator's prompt
    // held as fields. Validated whole by mcp_lesson_sections(); the schema
    // here is the shape, the validator is the rules.
    $reviewArg = [
        'type' => 'object',
        'description' => 'The lesson review for this session: the 19 sections as fields. See the tool description.',
        'properties' => [
            'topic_refs' => $refList,
            'objective'  => ['type' => 'string', 'maxLength' => 300],
            'resources'  => ['type' => 'array', 'maxItems' => 20, 'items' => ['anyOf' => [
                ['type' => 'string'],
                ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'unlisted' => ['type' => 'boolean']],
                 'required' => ['title']],
            ]], 'description' => 'Stored resource titles, or { title, unlisted: true } for one not stored'],
            'one_sentence' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 200],
            'progress' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                'ref'             => ['type' => 'string'],
                'status_seen'     => $statusEnum,
                'evidence'        => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'implication'     => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'proposed_status' => $statusEnum,
            ], 'required' => ['ref', 'status_seen', 'evidence', 'implication']]],
            'independent' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
            'supported'   => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
            'errors' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'properties' => [
                'ref'        => ['type' => 'string'],
                'error_type' => ['type' => 'string', 'enum' => REVIEW_ERROR_TYPES],
                'what'       => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'why_type'   => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'response'   => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
            ], 'required' => ['ref', 'error_type', 'what', 'why_type', 'response']]],
            'retention' => ['type' => 'object', 'properties' => [
                'retrieved' => $refEvidence, 'prompted' => $refEvidence,
                'not_retrieved' => $refEvidence, 'schedule' => $refEvidence,
            ], 'description' => 'retrieved needs retrieval_outcome correct on the ref in updates[]; prompted retry; not_retrieved incorrect'],
            'process' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'properties' => [
                'area'           => ['type' => 'string', 'enum' => REVIEW_PROCESS_AREAS],
                'basis'          => ['type' => 'string', 'enum' => REVIEW_BASES],
                'evidence'       => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600,
                    'description' => 'Quote or cite the transcript; the avoidance entry states blanks against attempts as numbers'],
                'interpretation' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'implication'    => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
            ], 'required' => ['area', 'basis', 'evidence', 'interpretation', 'implication']]],
            'helped'   => ['type' => 'array', 'maxItems' => 20, 'items' => $methodEntry],
            'hindered' => ['type' => 'array', 'maxItems' => 20, 'items' => $methodEntry],
            'confidence' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                'ref'         => ['type' => 'string'],
                'confidence'  => ['type' => 'string', 'enum' => REVIEW_LEVELS],
                'accuracy'    => ['type' => 'string', 'enum' => REVIEW_LEVELS],
                'evidence'    => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'implication' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
            ], 'required' => ['ref', 'confidence', 'accuracy', 'evidence', 'implication']],
                'description' => 'Omit the key when there is no evidence; an empty list is the honest value'],
            'signals' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                'key'       => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,60}$',
                    'description' => "Stable slug, e.g. 'model-then-immediate-practice'; reuse it to strengthen"],
                'kind'      => ['type' => 'string', 'enum' => SIGNAL_KINDS],
                'statement' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 300],
                'strength'  => ['type' => 'string', 'enum' => SIGNAL_STRENGTHS,
                    'description' => 'one_off: 1 session; emerging: 2 distinct; established: 3 and no newer contradiction'],
                'direction' => ['type' => 'string', 'enum' => SIGNAL_DIRECTIONS, 'default' => 'supports'],
                'evidence'  => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                'next_test' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 300,
                    'description' => 'The teaching experiment that would confirm or refute it'],
            ], 'required' => ['key', 'kind', 'statement', 'strength', 'evidence']]],
            'big_picture' => ['type' => 'object', 'properties' => [
                'readiness' => ['type' => 'string', 'enum' => REVIEW_READINESS],
                'why'       => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
            ], 'required' => ['readiness', 'why']],
            'next_what' => ['type' => 'object', 'properties' => [
                'opening_retrieval' => $refList, 'reteach' => $refList, 'consolidate' => $refList,
                'new' => $refList, 'misconception_check' => $refList, 'challenge' => $refList,
            ]],
            'next_how' => ['type' => 'object', 'properties' => ['stages' => ['type' => 'array', 'maxItems' => 12,
                'items' => ['type' => 'object', 'properties' => [
                    'stage'  => ['type' => 'string', 'enum' => REVIEW_STAGE_KEYS],
                    'method' => ['type' => 'string', 'enum' => REVIEW_METHODS],
                    'why'    => ['type' => 'string', 'minLength' => 5, 'maxLength' => 300],
                ], 'required' => ['stage', 'method', 'why']]]]],
            'do_differently' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'continue'       => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'watch' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object', 'properties' => [
                'key'             => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,60}$'],
                'what_to_observe' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 300],
            ], 'required' => ['key', 'what_to_observe']]],
            'learner_voice' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'object', 'properties' => [
                'quote'   => ['type' => 'string', 'minLength' => 2, 'maxLength' => 300, 'description' => 'Her exact words'],
                'context' => ['type' => 'string', 'maxLength' => 300],
            ], 'required' => ['quote']], 'description' => 'Exact quotes only; an empty list when nothing quotable was said'],
            'planner' => ['type' => 'object', 'properties' => [
                'priority'      => ['type' => 'string', 'maxLength' => 200],
                'start_with'    => ['type' => 'string', 'maxLength' => 200],
                'teach_using'   => ['type' => 'string', 'maxLength' => 200],
                'avoid'         => ['type' => 'string', 'maxLength' => 200],
                'check_whether' => ['type' => 'string', 'maxLength' => 200],
                'success'       => ['type' => 'string', 'maxLength' => 200],
            ], 'required' => REVIEW_PLANNER],
            'missing_evidence' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 300]],
        ],
        'required' => ['one_sentence', 'independent', 'supported', 'big_picture', 'planner', 'missing_evidence'],
    ];

    return [
        [
            'name'  => 'tracker_list_subjects',
            'title' => 'List tracked subjects',
            'description' =>
                "Every subject in the tracker, with its slug, spec code, tier, exam date, topic count and coverage percentage.\n\n"
                . "USE WHEN: you need a subject slug and do not already know one, or you are asked what is being tracked. "
                . "Call this before any other tracker tool if the slug is uncertain — the others need an exact slug.\n\n"
                . 'No arguments. Read-only.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_get_state',
            'title' => 'Get topic state for a subject',
            'description' =>
                "The authoritative record of what has and has not been learned: every topic with its status, tier, last-touched date and any loose end.\n\n"
                . "USE WHEN: teaching, explaining, planning, setting practice, or answering anything about progress in a tracked subject — "
                . "\"what do we know\", \"what's left\", \"how is she doing\", \"is X secure yet\". "
                . "Consult it BEFORE teaching so you neither reteach secure material nor teach a topic whose prerequisite is still a gap. "
                . "Do not rely on memory or on what was said earlier in the conversation; this tool is the source of truth.\n\n"
                . "Args: subject (slug). Optional status (array) and strand to filter; ref for one topic, "
                . "which also reports the error-type tally its lesson reviews have built.\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'status'  => ['type' => 'array', 'items' => $statusEnum,
                        'description' => 'Only these statuses, e.g. ["gap","developing"]'],
                    'strand'  => ['type' => 'string', 'description' => "Only this strand key, e.g. 'A' for Algebra"],
                    'ref'     => ['type' => 'string', 'maxLength' => 40,
                        'description' => 'One topic only, with its error-type tally from lesson reviews'],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_review_queue',
            'title' => 'What to work on next',
            'description' =>
                "The prioritised queue for a subject, and the plan the last session left. It opens with a `last_session` block — "
                . "the most recent non-void session's id, date, days_ago, its next_steps verbatim, the tail of its summary, "
                . "any unfinished item it carries, and stale: true when it is more than 14 days old (a flag, not a filter). "
                . "Then the groups, in priority order: UNFINISHED (work a session stopped before the end of — first, always), "
                . "ageing secures due a retrieval check, loose ends on otherwise-secure topics, and priority gaps (lower tier first). "
                . "Each entry lists the teaching resources attached to it, so this alone is enough to plan a session.\n\n"
                . "USE WHEN: opening a study or tutoring session, or asked \"what should we do today\", \"what next\", \"what needs work\". "
                . "Call this FIRST in any session, before deciding what to teach. YOU ARE EXPECTED TO OPEN ON last_session.next_steps "
                . "and on any UNFINISHED item: finish what was started before starting something new, and when you do finish it, "
                . "close it with tracker_log_session resolves: [session_id].\n\n"
                . "Args: subject (slug). Optional ageing_weeks (default 8).\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'      => $subjectArg,
                    'ageing_weeks' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 52, 'default' => 8,
                        'description' => 'Weeks before a secure topic is due a check'],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_list_resources',
            'title' => 'Get the materials for a topic or subject',
            'description' =>
                "The stored teaching materials — BBC Bitesize pages, Corbettmaths videos, worksheets, textbooks, past papers — either for one topic or for the whole subject.\n\n"
                . "USE WHEN: about to teach or revise something and you want the materials already chosen for it, or asked \"what should we use for X\", "
                . "\"what resources do we have\". Prefer these over suggesting arbitrary links: they are the ones this household has picked. "
                . "Omit ref to see everything in the subject.\n\n"
                . "Args: subject (slug). Optional ref for one topic, e.g. 'A17' — subject-wide materials are always included.\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'ref'     => ['type' => 'string', 'description' => "Topic reference, e.g. 'A17'. Omit for the whole subject."],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_list_attempts',
            'title' => 'List attempts (mocks, papers, checks)',
            'description' =>
                "Every logged attempt for a subject, newest first, each with its papers and the grade for the sitting as a whole.\n\n"
                . "USE WHEN: asked about marks, grades, mocks, past papers, or \"what grade is she on\".\n\n"
                . "An attempt is one sitting and may hold several papers — a three-paper mock is one attempt, not three. "
                . "The grade belongs to the attempt, computed across all its papers together, because one paper of three does not carry a grade. "
                . "Attempts of kind 'check' report a percentage only and are never grade-converted.\n\n"
                . "Call tracker_get_attempt for the question-by-question breakdown of any one of these.\n\n"
                . "Args: subject (slug). Optional limit (default 20).\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_get_attempt',
            'title' => 'Question-by-question breakdown of one attempt',
            'description' =>
                "One attempt in full: every paper, every question with its marks, the answer given where recorded, and marks lost per topic.\n\n"
                . "USE WHEN: reviewing a marked paper, asked why a grade came out as it did, deciding what to reteach after a mock, "
                . "or asked about a specific question. The per-topic breakdown turns a score into teaching information — "
                . "it names the topics that actually lost the marks, so use it before planning post-mock work.\n\n"
                . "Args: subject (slug), attempt_id (from tracker_list_attempts).\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'    => $subjectArg,
                    'attempt_id' => ['type' => 'integer', 'minimum' => 1,
                        'description' => 'The id shown by tracker_list_attempts'],
                ],
                'required'   => ['subject', 'attempt_id'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_history',
            'title' => 'Progress over time, grouped by week',
            'description' =>
                "The audit trail for a subject, week by week: each session logged, and every topic status change it produced, with the evidence recorded at the time. "
                . "A session row shows its unfinished item (open, closed by which session, or auto-closed) and the items it resolved.\n\n"
                . "USE WHEN: asked how progress has gone over time, what happened in a period, what changed recently, "
                . "\"what did we do last month\", \"when did X become secure\", or when writing a progress report. "
                . "Also use it to check a record before correcting one.\n\n"
                . "Pass ref to follow one topic's whole history — every status it has held and why.\n\n"
                . "Args: subject (slug). Optional weeks (default 12) and ref for a single topic.\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'weeks'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 260, 'default' => 12,
                        'description' => 'How far back to look'],
                    'ref'     => ['type' => 'string', 'maxLength' => 40,
                        'description' => "Follow one topic only, e.g. 'A17'"],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_export_markdown',
            'title' => 'Export the whole subject as markdown',
            'description' =>
                "The entire state of a subject as one markdown document: topics by strand, resources, and the assessment log.\n\n"
                . "USE WHEN: asked for a document to file, print, share or paste into project knowledge — a progress report, a topic-state file, \"write up where we are\".\n\n"
                . "The database is the source of truth and this is generated from it, so editing the export changes nothing.\n\n"
                . "Args: subject (slug).\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['subject' => $subjectArg],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_update_topic',
            'title' => "Update one topic's status",
            'description' =>
                "Change one topic's status, loose-end note or last-touched date, recording why.\n\n"
                . "USE WHEN: a single topic moved and you are not logging a whole session — a starter question passed or failed, a topic was secured, a gap closed. "
                . "For several topics at once use tracker_log_session instead.\n\n"
                . "Evidence is mandatory (10-500 chars) and should say what was done, how it scored and when: "
                . "\"harder independent retest 4/4, 13 Aug\". A status change without evidence is refused.\n\n"
                . 'Args: subject, ref, evidence. Optional status (omit to record evidence without moving it), watch (null clears the loose end), last_touched.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'      => $subjectArg,
                    'ref'          => ['type' => 'string', 'minLength' => 1, 'description' => "Topic reference, e.g. 'A17'"],
                    'status'       => $statusEnum,
                    'evidence'     => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500,
                        'description' => 'What was done and how it went. Required.'],
                    'watch'        => ['type' => ['string', 'null'], 'maxLength' => 500,
                        'description' => 'A loose end to carry forward, or null to clear it'],
                    'last_touched' => $isoDate,
                ],
                'required'   => ['subject', 'ref', 'evidence'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_log_session',
            'title' => 'Log a session and its topic updates',
            'description' =>
                "Records a teaching session and applies all its status changes in one call.\n\n"
                . "USE WHEN: closing a study or tutoring session. This is the normal way to end one — do it before the conversation finishes, "
                . "or the work is not in the record. Also use when told what was covered in a past session.\n\n"
                . "Each update carries its own evidence (10-500 chars). Unknown topic references are reported back rather than silently skipped, so a typo is visible.\n\n"
                . "UNFINISHED WORK: if the session stopped before the end — an exit ticket at Q4 of 5, a paragraph half written — "
                . "pass unfinished (≤ 300 chars saying what is outstanding) and optionally unfinished_refs. It becomes a queryable item "
                . "that leads tracker_review_queue until closed. When a session completes an earlier session's unfinished work, pass "
                . "resolves: [that session's id] — the ids come from the queue's UNFINISHED group. If the subject has an open item and "
                . "this call neither resolves it nor sets a new one, the write still succeeds and the result carries a warning.\n\n"
                . "RETRIEVAL: an update may carry retrieval_outcome (correct|retry|incorrect) to feed the topic's spacing schedule; "
                . "a status rise counts as correct and a demotion as incorrect without it. A consolidation block should pass "
                . "consolidates: [{ ref, error_session_id? }] naming the errors it re-worked.\n\n"
                . "Pass block_key from tracker_today when the work ran against a timetable block; the date must be the day the work was actually done.\n\n"
                . "LESSON REVIEW: a taught session (a block whose kind requires one, or an extra of 30+ minutes) closes with a review "
                . "in the same call — pass `review`, the object the lesson-review skill produces (one_sentence, progress[], independent, "
                . "supported, errors[], retention, process[], helped[], hindered[], confidence[]?, signals[], big_picture, next_what, "
                . "next_how, do_differently[], continue[], watch[], learner_voice[], planner, missing_evidence[]). The session, its "
                . "updates, its retrieval outcomes and the review are written in one transaction; a review that fails validation refuses "
                . "the whole call naming the field. A review never moves a status — statuses move only through updates[]; "
                . "progress[].proposed_status is a proposal for adjudication. Every retention entry needs the matching retrieval_outcome "
                . "on that ref in updates[]. If a review is required and none is sent the session still logs, and the reply says so; "
                . "save one later with tracker_save_lesson_review. The student sees only your one-line 'logged'; never print the review in her chat.\n\n"
                . 'Args: subject, summary. Optional date (defaults today), next_steps, block_key, duration_minutes, '
                . 'unfinished, unfinished_refs[], resolves[], consolidates[], updates[] of { ref, status?, evidence, watch?, retrieval_outcome? }, review.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'    => $subjectArg,
                    'date'       => $isoDate,
                    'summary'    => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000,
                        'description' => 'What was covered and how it went'],
                    'next_steps' => ['type' => 'string', 'maxLength' => 1000,
                        'description' => 'What the next session should open with'],
                    'block_key'  => ['type' => 'integer', 'minimum' => 1,
                        'description' => 'The timetable block this session fulfilled, from tracker_today'],
                    'duration_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600,
                        'description' => 'How long it actually ran, so a short sitting reads as short'],
                    'unfinished' => ['type' => 'string', 'maxLength' => 300,
                        'description' => 'What is outstanding, if the session stopped before the end. Presence is the flag.'],
                    'unfinished_refs' => ['type' => 'array', 'maxItems' => 10,
                        'items' => ['type' => 'string', 'maxLength' => 40],
                        'description' => 'Topic refs the unfinished work belongs to'],
                    'resolves'   => ['type' => 'array', 'maxItems' => 20,
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'description' => 'Session ids whose unfinished work this session completed'],
                    'consolidates' => [
                        'type' => 'array', 'maxItems' => 30,
                        'description' => 'For a consolidation block: the errors re-worked, by topic',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'ref'              => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
                                'error_session_id' => ['type' => 'integer', 'minimum' => 1,
                                    'description' => 'The session the error was recorded in, if known'],
                            ],
                            'required' => ['ref'],
                        ],
                    ],
                    'updates'    => [
                        'type'     => 'array',
                        'maxItems' => 50,
                        'description' => 'Topic status changes this session produced',
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'ref'      => ['type' => 'string', 'minLength' => 1],
                                'status'   => $statusEnum,
                                'evidence' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
                                'watch'    => ['type' => ['string', 'null'], 'maxLength' => 500],
                                'retrieval_outcome' => ['type' => 'string', 'enum' => RETRIEVAL_OUTCOMES,
                                    'description' => 'How a retrieval check on this topic went, for the spacing schedule'],
                            ],
                            'required'   => ['ref', 'evidence'],
                        ],
                    ],
                    'review'     => $reviewArg,
                ],
                'required'   => ['subject', 'summary'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_amend_session',
            'title' => 'Correct a logged session',
            'description' =>
                "Corrects the record of a session already logged: its date, summary or next steps, or voids it with a reason if it was logged in error.\n\n"
                . "USE WHEN: a session was recorded wrongly, on the wrong date, or should not have been recorded at all.\n\n"
                . "Voiding keeps the row and its reason — an audit trail that can lose entries is not one — and marks it VOID in the history, so it stops counting towards the review queue and the export. "
                . "To correct a TOPIC's status instead, call tracker_update_topic with the right status and evidence saying it is a correction: "
                . "that appends to the trail rather than rewriting it.\n\n"
                . "UNFINISHED WORK: set unfinished to record that this session stopped before the end (or to correct the text); "
                . "pass unfinished: null to close its open item by hand — the item stays visible in the history, stamped 'cleared by hand'. "
                . "Pass resolves: [ids] to record that this session completed those sessions' unfinished work.\n\n"
                . 'Args: subject, session_id (from tracker_history). Any of date, summary, next_steps, void_reason, unfinished, unfinished_refs, resolves.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'     => $subjectArg,
                    'session_id'  => ['type' => 'integer', 'minimum' => 1,
                        'description' => 'The id shown by tracker_history'],
                    'date'        => $isoDate,
                    'summary'     => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000],
                    'next_steps'  => ['type' => 'string', 'maxLength' => 1000],
                    'void_reason' => ['type' => ['string', 'null'], 'maxLength' => 500,
                        'description' => 'Why this session should not count. null un-voids it.'],
                    'unfinished'  => ['type' => ['string', 'null'], 'maxLength' => 300,
                        'description' => 'What is outstanding, or null to close the open item by hand'],
                    'unfinished_refs' => ['type' => 'array', 'maxItems' => 10,
                        'items' => ['type' => 'string', 'maxLength' => 40]],
                    'resolves'    => ['type' => 'array', 'maxItems' => 20,
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'description' => 'Session ids whose unfinished work this session completed'],
                ],
                'required'   => ['subject', 'session_id'],
            ],
            'annotations' => [
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ],
        ],
        [
            'name'  => 'tracker_log_attempt',
            'title' => 'Log a mock, paper or check',
            'description' =>
                "Records one sitting — an attempt — with its papers and, where you have them, every question, mark, answer and the topic it tests.\n\n"
                . "USE WHEN: a past paper, mock or topic check has been marked. Log it as soon as you have the marks, including when you marked it yourself. "
                . "If you have just marked a script question by question, record those questions here rather than only the total: "
                . "the per-question topic refs are what later tell you which topics lost the marks.\n\n"
                . "One attempt holds every paper sat together: a three-paper mock is ONE call with three papers, not three calls. "
                . "The grade is computed across the whole attempt. kind 'check' is never grade-converted.\n\n"
                . "Args: subject, name, papers[] of { code, score, max, blanks?, note?, sat_on?, questions?[] }. "
                . "Each question is { number, max, score, topic_ref?, question?, answer?, note? }. "
                . "Give a paper its own sat_on when the papers of one sitting were not all sat on the same day.\n\n"
                . "Pass block_key from tracker_today when the work ran against a timetable block; the date must be the day the work was actually done.\n\n"
                . 'Optional kind (paper|check, default paper), tier, date, note.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'name'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200,
                        'description' => "What was sat, e.g. 'June 2023 Higher mock'"],
                    'kind'    => ['type' => 'string', 'enum' => ['paper', 'check'], 'default' => 'paper',
                        'description' => 'Full paper(s), or a topic check. Only papers are grade-converted.'],
                    'tier'    => ['type' => 'string', 'maxLength' => 4, 'default' => 'F'],
                    'date'    => $isoDate,
                    'note'    => ['type' => 'string', 'maxLength' => 1000],
                    'papers'  => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 10,
                        'description' => 'Every paper sat in this attempt',
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'code'   => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60,
                                    'description' => "e.g. '8300/1H'"],
                                'score'  => ['type' => 'number', 'minimum' => 0],
                                'max'    => ['type' => 'number', 'minimum' => 1],
                                'blanks' => ['type' => 'integer', 'minimum' => 0,
                                    'description' => 'Questions left blank on this paper'],
                                'note'   => ['type' => 'string', 'maxLength' => 1000],
                                'sat_on' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
                                    'description' => 'Date this paper was sat, if not all on one day'],
                                'block_key' => ['type' => 'integer', 'minimum' => 1,
                                    'description' => 'The timed timetable block this paper fulfilled'],
                                'questions' => [
                                    'type'     => 'array',
                                    'maxItems' => 200,
                                    'items'    => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'number'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20,
                                                'description' => "e.g. '4a'"],
                                            'score'     => ['type' => 'number', 'minimum' => 0],
                                            'max'       => ['type' => 'number', 'minimum' => 0],
                                            'topic_ref' => ['type' => 'string', 'maxLength' => 40,
                                                'description' => "Topic this question tests, e.g. 'A17'"],
                                            'question'  => ['type' => 'string', 'maxLength' => 1000],
                                            'answer'    => ['type' => 'string', 'maxLength' => 1000,
                                                'description' => 'What was written'],
                                            'note'      => ['type' => 'string', 'maxLength' => 500,
                                                'description' => 'Why the marks went the way they did'],
                                        ],
                                        'required'   => ['number', 'score', 'max'],
                                    ],
                                ],
                            ],
                            'required'   => ['code', 'score', 'max'],
                        ],
                    ],
                ],
                'required'   => ['subject', 'name', 'papers'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_add_resource',
            'title' => 'Attach teaching materials to a topic or subject',
            'description' =>
                "Stores materials against a topic, or against the whole subject when ref is omitted: BBC Bitesize pages, Corbettmaths or Sparx videos, worksheets, textbooks, past papers.\n\n"
                . "USE WHEN: someone shares a link or names a resource to use, or asks you to set up the materials for a subject or topic. "
                . "Add them as they come up rather than keeping them in the conversation — they are then available in every future session.\n\n"
                . "Re-adding the same title against the same topic updates it instead of duplicating, so this is safe to repeat.\n\n"
                . 'Args: subject, resources[] of { title, ref?, url?, kind?, note? }. kind is one of ' . implode(', ', RESOURCE_KINDS) . '.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'   => $subjectArg,
                    'resources' => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 200,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200,
                                    'description' => "e.g. 'BBC Bitesize: Solving linear equations'"],
                                'ref'   => ['type' => 'string', 'maxLength' => 40,
                                    'description' => "Topic reference, e.g. 'A17'. Omit for a subject-wide resource."],
                                'url'   => ['type' => 'string', 'maxLength' => 500],
                                'kind'  => ['type' => 'string', 'enum' => RESOURCE_KINDS, 'default' => 'other'],
                                'note'  => ['type' => 'string', 'maxLength' => 500,
                                    'description' => 'How to use it, e.g. "questions 4-9 only"'],
                            ],
                            'required'   => ['title'],
                        ],
                    ],
                ],
                'required'   => ['subject', 'resources'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_remove_resource',
            'title' => 'Remove a stored resource',
            'description' =>
                "Deletes one stored resource by its exact title.\n\n"
                . "USE WHEN: a link is dead, superseded, or was added by mistake.\n\n"
                . "Args: subject, title. Optional ref — omit it only if the resource is subject-wide, since the same title can exist against different topics.\n"
                . 'Removes the material, never the topic or its progress.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'title'   => ['type' => 'string', 'minLength' => 1, 'description' => 'Exact stored title'],
                    'ref'     => ['type' => 'string', 'maxLength' => 40,
                        'description' => 'Topic it is attached to. Omit for a subject-wide resource.'],
                ],
                'required'   => ['subject', 'title'],
            ],
            'annotations' => [
                'readOnlyHint'    => false,
                'destructiveHint' => true,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ],
        ],
        [
            'name'  => 'tracker_create_subject',
            'title' => 'Create a subject, extend its syllabus, or correct its details',
            'description' =>
                "Sets up a subject with its strands, grade boundaries and full topic list — the syllabus — "
                . "and is also how one field of an existing subject gets corrected.\n\n"
                . "USE WHEN: asked to start tracking a new subject, to add topics to an existing one, or to "
                . "fix a subject's details — an exam date for the wrong year, a spec code, a note.\n\n"
                . "Creating needs slug, name, strands and topics. Amending needs only slug and the fields you "
                . "are changing: anything you leave out keeps the value it has, so correcting an exam date is "
                . "one call and does not disturb a hundred topics. Re-running it for an existing subject adds "
                . "new topics but NEVER resets the status of one that already exists — progress cannot be lost "
                . "by re-seeding, so extending a syllabus is safe.\n\n"
                . "DO NOT re-send a whole syllabus to change one field, and do not send topics at all unless "
                . "you mean to add or update them.\n\n"
                . "Args to create: slug, name, strands (key to display name), topics[] of { ref, name, strand, tier?, status?, watch? }. "
                . 'Optional spec_code, tier, exam_date, notes, boundary_max (default 240), boundaries (tier to [[grade, mark], …]).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug'         => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$', 'maxLength' => 60,
                        'description' => "URL-safe key, e.g. 'english-language'. Lowercase, numbers and hyphens only."],
                    'name'         => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'spec_code'    => ['type' => 'string', 'maxLength' => 60, 'description' => "e.g. 'AQA 8300'"],
                    'tier'         => ['type' => 'string', 'maxLength' => 30],
                    'exam_date'    => $isoDate,
                    'notes'        => ['type' => 'string', 'maxLength' => 2000],
                    'strands'      => ['type' => 'object', 'additionalProperties' => ['type' => 'string'],
                        'description' => 'Strand key to display name, e.g. { "N": "Number", "A": "Algebra" }'],
                    'boundary_max' => ['type' => 'integer', 'minimum' => 1, 'default' => 240,
                        'description' => 'Total marks the boundaries are expressed against'],
                    'boundaries'   => ['type' => 'object', 'additionalProperties' => [
                        'type'  => 'array',
                        'items' => ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 2, 'maxItems' => 2],
                    ], 'description' => 'Tier to [[grade, mark], …], e.g. { "H": [[7,164],[6,130]] }'],
                    'topics'       => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 400,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'ref'    => ['type' => 'string', 'minLength' => 1, 'description' => "Spec reference, e.g. 'A17'"],
                                'name'   => ['type' => 'string', 'minLength' => 1],
                                'strand' => ['type' => 'string', 'minLength' => 1, 'description' => 'A key from strands'],
                                'tier'   => ['type' => 'string', 'maxLength' => 4, 'default' => 'F'],
                                'status' => array_merge($statusEnum, ['default' => 'notstarted']),
                                'watch'  => ['type' => 'string', 'maxLength' => 500],
                            ],
                            'required'   => ['ref', 'name', 'strand'],
                        ],
                    ],
                ],
                'required'   => ['slug'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_log_practice',
            'title' => 'Log practice runs (games, drills, tutoring sessions)',
            'description' =>
                "Records practice: one row per run, where a run is one bounded stretch of practice — one Shooting Gallery game, one maths tutoring session — with how many items were attempted, right first time, right after a retry, and never got.\n\n"
                . "USE WHEN: a practice session or a set of app games has finished and you are reporting what happened. "
                . "Send every run of the session in ONE call at the end, not one call per game.\n\n"
                . "FOR PRACTICE ONLY. A marked paper is not practice: mocks, past papers and topic checks go to tracker_log_attempt, "
                . "which carries a mark scheme and drives the grade projection. Practice arrives dozens per week and would drown that record.\n\n"
                . "Every run needs a client_run_id: a stable id made by the client at the start of the run. Repeating a call with the same "
                . "client_run_id is a silent no-op that returns the existing row, so a retry after a failed report is always safe.\n\n"
                . "attempted must equal correct + correct_after_retry + incorrect, or the whole call is refused naming the discrepancy.\n\n"
                . "Args: subject, runs[] of { client_run_id, source, label, attempted, correct, incorrect, correct_after_retry?, "
                . "played_at?, duration_seconds?, metrics?, topic_refs?, items?[] }. "
                . "source is a key from the registry (tracker_practice_stats lists them). "
                . "Each item is { outcome (correct|retry|incorrect), prompt?, topic_ref?, item_key?, attempts_taken?, position?, note? } — "
                . "supply items where you know them per question; the per-topic breakdown is built from them.\n\n"
                . "RETRIEVAL SCHEDULING: every item with a topic_ref updates that topic's spacing schedule (correct: next due at the "
                . "ladder interval; retry: +3 days; incorrect: tomorrow, and a scaffold after two). Give an item a stable item_key "
                . "(≤ 64 chars, unique per subject — a registry id, or the key tracker_retrieval_due handed you) and it is scheduled "
                . "at item grain too. Retrieval runs use a source starting retrieval_ (retrieval_warmup, retrieval_mixed, "
                . "retrieval_subject, retrieval_quotes): that is what satisfies a retrieval block and keeps them apart from app games.\n\n"
                . "Pass block_key from tracker_today when the work ran against a timetable block; the date must be the day the work was actually done.\n\n"
                . 'Logging practice never changes a topic status: that happens through tracker_log_session only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'runs'    => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 50,
                        'description' => 'Every run of this session, in one call',
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'client_run_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64,
                                    'description' => 'Stable id from the client. Repeats are a no-op.'],
                                'source'        => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60,
                                    'description' => "Registry key, e.g. 'spanish_gallery' or 'maths_session'"],
                                'label'         => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200,
                                    'description' => "Human name, e.g. 'Set 34: Free Time 1' or 'Circle theorems'"],
                                'attempted'     => ['type' => 'integer', 'minimum' => 0,
                                    'description' => 'Items presented. Must equal correct + correct_after_retry + incorrect.'],
                                'correct'       => ['type' => 'integer', 'minimum' => 0,
                                    'description' => 'Right without help or retry'],
                                'correct_after_retry' => ['type' => 'integer', 'minimum' => 0, 'default' => 0,
                                    'description' => 'Right eventually, after a retry, hint or reteach'],
                                'incorrect'     => ['type' => 'integer', 'minimum' => 0,
                                    'description' => 'Never got there'],
                                'played_at'     => ['type' => 'string', 'maxLength' => 40,
                                    'description' => 'ISO 8601. Defaults to now.'],
                                'duration_seconds' => ['type' => 'integer', 'minimum' => 0],
                                'block_key'     => ['type' => 'integer', 'minimum' => 1,
                                    'description' => 'The timetable block this run fulfilled, from tracker_today'],
                                'metrics'       => ['type' => 'object',
                                    'description' => 'Source-specific numbers, e.g. { "top_speed": 8.4 }'],
                                'topic_refs'    => ['type' => 'array', 'maxItems' => 20,
                                    'items' => ['type' => 'string', 'maxLength' => 40],
                                    'description' => 'Topics this run covered, when you know them for the run but not per item'],
                                'items'         => [
                                    'type'     => 'array',
                                    'maxItems' => 200,
                                    'items'    => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'prompt'    => ['type' => 'string', 'maxLength' => 500,
                                                'description' => 'The word shown, or the question asked'],
                                            'topic_ref' => ['type' => 'string', 'maxLength' => 40],
                                            'item_key'  => ['type' => 'string', 'maxLength' => 64,
                                                'description' => 'Stable id for this item, unique per subject; schedules it at item grain'],
                                            'outcome'   => ['type' => 'string', 'enum' => PRACTICE_OUTCOMES],
                                            'attempts_taken' => ['type' => 'integer', 'minimum' => 1,
                                                'description' => '1 = first time'],
                                            'position'  => ['type' => 'integer', 'minimum' => 0],
                                            'note'      => ['type' => 'string', 'maxLength' => 300,
                                                'description' => 'Why it went the way it did'],
                                        ],
                                        'required'   => ['outcome'],
                                    ],
                                ],
                            ],
                            'required'   => ['client_run_id', 'source', 'label', 'attempted', 'correct', 'incorrect'],
                        ],
                    ],
                ],
                'required'   => ['subject', 'runs'],
            ],
            'annotations' => [
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                // Replaying the same client_run_ids stores nothing further.
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ],
        ],
        [
            'name'  => 'tracker_list_practice',
            'title' => 'List practice runs',
            'description' =>
                "Practice runs for a subject, newest first: date, activity, label, the counts and the accuracy of each.\n\n"
                . "USE WHEN: asked what practice has been done lately, which games or sessions were played, or to check a run before voiding it.\n\n"
                . "Voided runs are listed and marked VOID; they count towards nothing. For the figures rather than the list, use tracker_practice_stats.\n\n"
                . "Args: subject. Optional source (registry key), since (YYYY-MM-DD), ref (only runs touching that topic), limit (default 20).\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'source'  => ['type' => 'string', 'maxLength' => 60,
                        'description' => "Registry key, e.g. 'spanish_gallery'"],
                    'since'   => $isoDate,
                    'ref'     => ['type' => 'string', 'maxLength' => 40,
                        'description' => "Only runs touching this topic, e.g. 'G14'"],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_practice_stats',
            'title' => 'How practice is going',
            'description' =>
                "The figures behind a subject's practice scoreboard: totals, best run, accuracy and solve rate over the window and over the last 10, "
                . "the best of each source-specific metric, and a per-topic table joined to each topic's current status.\n\n"
                . "USE WHEN: asked how she is getting on with the app or with practice, whether it is improving, which topics practice keeps catching out, "
                . "or when planning what to drill next. The per-topic table sorted weakest-first is the teaching information.\n\n"
                . "Accuracy is right-first-time (correct / attempted); solve rate counts the ones she got to with a retry or a hint. "
                . "Both are pooled over all the runs in the window, not the mean of per-run percentages.\n\n"
                . "Args: subject. Optional days (default 90), source, ref.\n"
                . 'Read-only. Practice figures never affect a grade projection.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'days'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'default' => 90],
                    'source'  => ['type' => 'string', 'maxLength' => 60],
                    'ref'     => ['type' => 'string', 'maxLength' => 40],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_void_practice',
            'title' => 'Void a practice run',
            'description' =>
                "Marks one practice run as not counting, with a reason. The row is kept and still listed, marked VOID, and drops out of every statistic.\n\n"
                . "USE WHEN: a run was logged in error, someone else played it, or the numbers are wrong. Pass void_reason null to un-void one.\n\n"
                . "Practice is never hard-deleted, for the same reason a session is not: a record that can lose entries is not one.\n\n"
                . 'Args: subject, run_id (from tracker_list_practice), void_reason (null un-voids).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'     => $subjectArg,
                    'run_id'      => ['type' => 'integer', 'minimum' => 1,
                        'description' => 'The id shown by tracker_list_practice'],
                    'void_reason' => ['type' => ['string', 'null'], 'maxLength' => 500,
                        'description' => 'Why it should not count. null un-voids it.'],
                ],
                'required'   => ['subject', 'run_id'],
            ],
            'annotations' => [
                'readOnlyHint'    => false,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ],
        ],
        [
            'name'  => 'tracker_get_scoreboard',
            'title' => "Read a subject's scoreboard configuration",
            'description' =>
                "The ordered list of panels a subject's practice board renders, as JSON, and whether it is the stored configuration or the built-in default.\n\n"
                . "USE WHEN: about to change what a board shows, or asked why a board shows what it does. Always read before writing: "
                . "tracker_set_scoreboard replaces the whole configuration.\n\n"
                . "Panel types are code, panel instances are configuration: " . implode(', ', PRACTICE_PANEL_TYPES) . ".\n\n"
                . "Args: subject.\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['subject' => $subjectArg],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_set_scoreboard',
            'title' => "Write a subject's scoreboard configuration",
            'description' =>
                "Replaces the panels a subject's practice board renders. This is how a new chart is added for a new activity or a new need, without a deploy.\n\n"
                . "USE WHEN: asked to change, add or reorder what the practice board shows. Call tracker_get_scoreboard first and send the whole "
                . "configuration back with your change applied — this replaces it, it does not merge.\n\n"
                . "One invalid panel rejects the WHOLE configuration and leaves the stored one untouched, so a bad edit cannot half-apply.\n\n"
                . "Panel types: stat (one tile), line (time series), table (recent runs), topics (per-topic breakdown), split (correct / retry / incorrect). "
                . "Every panel takes title and an optional source filter. Metrics are " . implode(', ', PRACTICE_BASE_METRICS)
                . ", or metrics.<key> for a source-specific number.\n\n"
                . 'Args: subject, config { version, panels[] }. Optional note saying why it changed.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'config'  => ['type' => 'object',
                        'description' => 'The whole configuration: { "version": 1, "panels": [ … ] }'],
                    'note'    => ['type' => 'string', 'maxLength' => 500,
                        'description' => 'Why it changed, kept with the stored version'],
                ],
                'required'   => ['subject', 'config'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_today',
            'title' => "Today's timetable and what has been missed",
            'description' =>
                "Which block the student is in right now, what is next, and what has already been missed today. "
                . "For each subject with a block today it also carries that subject's `last_session` block (the plan the "
                . "previous session left, verbatim) and any open UNFINISHED items, so a session that opens on this call "
                . "alone still sees what it should continue from. A done block whose work was not the shape the block "
                . "asked for is shown as done_shape_unmet with the reason — it still counts as done.\n\n"
                . "USE WHEN: call this after tracker_review_queue at the start of every session. It tells you which block "
                . "she is in and what has already been missed today.\n\n"
                . "DO NOT use it to decide whether to help — the timetable is a plan, not a gate. If she wants maths at "
                . "16:00 on a Sunday, teach her maths. Blocks with tracking 'evidence' are judged from logged work only, "
                . "so never report one as done because she says it is; log the work instead.\n\n"
                . 'Args: optional date (defaults to today, Europe/London). Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['date' => $isoDate],
                'required' => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_week_status',
            'title' => 'A whole week, block by block',
            'description' =>
                "Every block of a week with its status, the extra work logged outside the timetable, the counts and the "
                . "hours by subject against target.\n\n"
                . "USE WHEN: running the parent's weekly review, answering \"what did she miss this week\", or checking "
                . "adherence over a named week. This is the tool the Friday review is built on.\n\n"
                . "DO NOT use it to judge a single day — tracker_today is cheaper and answers that. Do not treat a missed "
                . "block as a verdict on her: it means nothing was logged, which is sometimes a logging failure.\n\n"
                . "Args: optional week (ISO, e.g. '2026-W37') or date (any day in the week). Defaults to this week. Read-only.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'week' => ['type' => 'string', 'pattern' => '^\\d{4}-W\\d{2}$',
                        'description' => "ISO week, e.g. '2026-W37'"],
                    'date' => $isoDate,
                ],
                'required' => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_get_timetable',
            'title' => 'The timetable in force',
            'description' =>
                "The timetable version in force on a date, with every block: key, day, times, kind, label, subjects and "
                . "how it is tracked.\n\n"
                . "USE WHEN: you need block keys, you are about to propose a change, or you are explaining the shape of "
                . "the week. ALWAYS call this before tracker_set_timetable — that tool replaces the whole timetable, so "
                . "writing without reading first drops every block you did not send.\n\n"
                . "DO NOT use it to judge whether work was done; it is the plan, not the record. tracker_week_status "
                . "judges.\n\n"
                . 'Args: optional valid_on (defaults to today). Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['valid_on' => $isoDate],
                'required' => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_days_off',
            'title' => 'Days off, requested and decided',
            'description' =>
                "Holidays, days off and sick days in a date range, each with who asked, what was decided and any note.\n\n"
                . "USE WHEN: checking whether a gap in the week was agreed, or listing what the parent still has to "
                . "decide. Pending requests are the ones with status 'requested' — surface those to the parent rather "
                . "than deciding them.\n\n"
                . "DO NOT use it to excuse a single block; that is tracker_excuse_block.\n\n"
                . 'Args: optional from, to (dates), status. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'from'   => $isoDate,
                    'to'     => $isoDate,
                    'status' => ['type' => 'string', 'enum' => ['requested', 'approved', 'declined']],
                ],
                'required' => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_request_day_off',
            'title' => 'Ask for a day off',
            'description' =>
                "Books a day off. A student's request is only a request — say so, and never call tracker_decide_day_off "
                . "on her behalf.\n\n"
                . "USE WHEN: either of them asks for time off. requested_by 'parent' is approved at once, because it is "
                . "his call to make. requested_by 'student' is recorded as 'requested' and the blocks keep being judged "
                . "until he approves it, so tell her plainly that it is not agreed yet and that the board will show "
                . "misses in the meantime.\n\n"
                . "DO NOT approve it yourself, do not imply it is settled, and do not use it to tidy away a day that has "
                . "already gone badly — that is what an excusal with a reason is for. A student request longer than 14 "
                . "days is refused.\n\n"
                . 'Args: date_from, date_to, reason, requested_by. Optional kind.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'date_from'    => $isoDate,
                    'date_to'      => $isoDate,
                    'reason'       => ['type' => 'string', 'minLength' => 3, 'maxLength' => 300,
                        'description' => 'Why — shown on the board'],
                    'requested_by' => ['type' => 'string', 'enum' => ['student', 'parent']],
                    'kind'         => ['type' => 'string', 'enum' => ['holiday', 'day_off', 'sick', 'other']],
                ],
                'required' => ['date_from', 'date_to', 'reason', 'requested_by'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_decide_day_off',
            'title' => 'Approve or decline a day off',
            'description' =>
                "Parent-only. Only from the parent's own chat, only on their explicit word. Declined and un-approved "
                . "records are kept with the note.\n\n"
                . "USE WHEN: the parent has said, in this conversation, to approve, decline or un-approve a specific "
                . "request. Approving clears that day's misses; un-approving brings them back.\n\n"
                . "DO NOT call this in the student's chat, on her say-so, or because a request has been sitting there a "
                . "while. If you are not certain the person speaking is the parent, list the request and stop.\n\n"
                . 'Args: id (from tracker_days_off), decision. Optional note.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id'       => ['type' => 'integer', 'minimum' => 1],
                    'decision' => ['type' => 'string', 'enum' => ['approve', 'decline', 'unapprove']],
                    'note'     => ['type' => 'string', 'maxLength' => 300],
                ],
                'required' => ['id', 'decision'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_set_timetable',
            'title' => 'Replace the timetable from a date',
            'description' =>
                "Replaces the whole timetable from valid_from. Parent-only by convention — relay a student's request, "
                . "don't apply it. Always tracker_get_timetable first and echo the diff before writing.\n\n"
                . "USE WHEN: the parent has approved a specific re-cut of the week. Send EVERY block you want to keep: "
                . "this writes a new version, and a block you leave out is gone from that version on. Keep each surviving "
                . "block's block_key the same, so excusals, ticks and logged work still resolve against it.\n\n"
                . "Refused, with the clash named, if two blocks overlap on a day, an end is not after its start, a "
                . "subject slug is not tracked, or a block_key is used twice.\n\n"
                . "DO NOT use it to record that a block did not happen — that is tracker_excuse_block or a day off.\n\n"
                . 'Args: blocks[] of { block_key, weekday 1-7, start, end, kind, label, subjects[], tracking, note?, '
                . 'alternate? }. Optional valid_from (defaults to next Monday), note.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'valid_from' => $isoDate,
                    'note'       => ['type' => 'string', 'maxLength' => 300],
                    'targets'    => ['type' => 'object',
                        'description' => 'Target hours per week by subject slug, e.g. { "maths": 5.0 }'],
                    'blocks'     => [
                        'type' => 'array', 'minItems' => 1, 'maxItems' => 200,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'block_key' => ['type' => 'integer', 'minimum' => 1,
                                    'description' => "Stable id; the seed's `id`. Keep it across versions."],
                                'weekday'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                                'start'     => ['type' => 'string', 'description' => 'HH:MM'],
                                'end'       => ['type' => 'string', 'description' => 'HH:MM'],
                                'kind'      => ['type' => 'string', 'enum' => TIMETABLE_KINDS],
                                'label'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                                'note'      => ['type' => ['string', 'null'], 'maxLength' => 300],
                                'subjects'  => ['type' => 'array', 'items' => ['type' => 'string']],
                                'alternate' => ['type' => ['object', 'null'],
                                    'description' => 'e.g. {"odd":["maths"],"even":["computer-science"]}'],
                                'tracking'  => ['type' => 'string', 'enum' => TIMETABLE_TRACKING],
                            ],
                            'required' => ['block_key', 'weekday', 'start', 'end', 'kind', 'label', 'tracking'],
                        ],
                    ],
                ],
                'required' => ['blocks'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_excuse_block',
            'title' => 'Excuse a block on a date',
            'description' =>
                "Only when the parent states a reason. Never call on your own initiative, never to tidy the board. "
                . "A null reason un-excuses.\n\n"
                . "USE WHEN: the parent has said why a specific block on a specific date did not happen. The reason is "
                . "shown on the board next to the block, so write it as he said it.\n\n"
                . "DO NOT excuse a block because she was busy, because the day looks bad, because it was missed several "
                . "weeks running, or on her request. A missed block that stays missed is the point of the board.\n\n"
                . 'Args: date, block_key (from tracker_today), reason — or reason: null to un-excuse.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'date'      => $isoDate,
                    'block_key' => ['type' => 'integer', 'minimum' => 1],
                    'reason'    => ['type' => ['string', 'null'], 'maxLength' => 300,
                        'description' => "The parent's reason, or null to un-excuse"],
                ],
                'required' => ['date', 'block_key', 'reason'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_tick_block',
            'title' => 'Tick a self-reported block',
            'description' =>
                "Marks a self-reported block done: the movement blocks and the weekly review, which leave no logged work "
                . "behind.\n\n"
                . "USE WHEN: she says she did her movement block, or the parent has finished the weekly review.\n\n"
                . "DO NOT use it on a study block. Blocks with tracking 'evidence' are refused, because they are judged "
                . "from the session, attempt or practice run that was logged against them — log the work instead, and "
                . "the block ticks itself.\n\n"
                . 'Args: date, block_key, by (student|parent). Optional note.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'date'      => $isoDate,
                    'block_key' => ['type' => 'integer', 'minimum' => 1],
                    'by'        => ['type' => 'string', 'enum' => ['student', 'parent']],
                    'note'      => ['type' => 'string', 'maxLength' => 300],
                ],
                'required' => ['date', 'block_key', 'by'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_week_report',
            'title' => 'Everything a week is judged on, in one call',
            'description' =>
                "One read for a whole week: every block with its status, the extras, the counts, the hours "
                . "against what the timetable planned, each subject's topic movement with the evidence behind it, anything sat, "
                . "the practice runs, the top of each review queue, pending days off, and any weekly review "
                . "already saved for that week. Blocks done in the wrong shape (done_shape_unmet — a retrieval block met by a "
                . "three-item run, a consolidation that re-worked nothing) are listed apart with the reason; they still count as done. "
                . "Each subject reports its unfinished work: opened this week, closed this week, and open now.\n\n"
                . "USE WHEN: writing or preparing the Friday weekly review, or answering \"how did the week "
                . "go\" across all subjects. This replaces the dozen calls that used to open a review — "
                . "week_status, then history, attempts, practice and review_queue per subject — with one.\n\n"
                . "DO NOT use it for a single day (tracker_today is cheaper) or for one subject's progress "
                . "(tracker_get_state and tracker_review_queue answer that). Do not treat a missed block as "
                . "a verdict on her: it means nothing was logged, which is sometimes a logging failure.\n\n"
                . "Args: optional week (ISO, e.g. '2026-W37') or date (any day in it). Defaults to the "
                . 'current week. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'week' => ['type' => 'string', 'pattern' => '^\\d{4}-W\\d{2}$',
                        'description' => "ISO week, e.g. '2026-W37'"],
                    'date' => $isoDate,
                ],
                'required' => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_save_weekly_review',
            'title' => 'Save the written half of a week',
            'description' =>
                "Stores the margin note for a week — what held, what slipped, one carry-forward per subject, "
                . "what next week starts with, and the decisions the parent made — as a new version against "
                . "that week. The tracker attaches its own snapshot of the counts, hours, blocks, movement, "
                . "attempts and practice as they stand at save time, so the note can never disagree with the "
                . "record about the week it describes.\n\n"
                . "USE WHEN: the Friday routine has read tracker_week_report and written the draft, or the "
                . "review with the parent is finished and the decisions are settled — then stage 'reviewed'.\n\n"
                . "DO NOT use it to change the record. Excusing a block is tracker_excuse_block, deciding a "
                . "day off is tracker_decide_day_off, ticking the review block is tracker_tick_block; this "
                . "tool only records what was decided. Do not restate counts inside the sections — they are "
                . "computed beside your words and will be right when yours have aged.\n\n"
                . 'Args: week, stage, written_by, sections { held, slipped, next, carry_forward{slug: line}, '
                . 'rotation_next, decisions[]? }, optional note.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'week'       => ['type' => 'string', 'pattern' => '^\\d{4}-W\\d{2}$'],
                    'stage'      => ['type' => 'string', 'enum' => ['draft', 'reviewed']],
                    'written_by' => ['type' => 'string', 'enum' => ['routine', 'chat'],
                        'description' => "'routine' from the Friday scheduled run, 'chat' when a person is in the conversation"],
                    'note'       => ['type' => 'string', 'maxLength' => 300],
                    'sections'   => [
                        'type' => 'object',
                        'properties' => [
                            'held'          => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                            'slipped'       => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                            'next'          => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                            'carry_forward' => ['type' => 'object',
                                'description' => 'One line per subject slug, 3-200 characters'],
                            'rotation_next' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                            'decisions'     => [
                                'type' => 'array', 'maxItems' => 20,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'kind'     => ['type' => 'string', 'enum' => ['day_off', 'excusal']],
                                        'ref'      => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40,
                                            'description' => "day_off: the id. excusal: 'YYYY-MM-DD#block_key'"],
                                        'decision' => ['type' => 'string',
                                            'enum' => ['approved', 'declined', 'excused', 'not_excused', 'deferred']],
                                        'note'     => ['type' => ['string', 'null'], 'maxLength' => 200],
                                    ],
                                    'required' => ['kind', 'ref', 'decision'],
                                ],
                            ],
                        ],
                        'required' => ['held', 'slipped', 'next', 'carry_forward', 'rotation_next'],
                    ],
                ],
                'required' => ['week', 'stage', 'written_by', 'sections'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_get_weekly_review',
            'title' => 'Read a saved weekly review',
            'description' =>
                "The margin note stored against a week: its sections, its stage, who wrote it and when, the "
                . "snapshot of the record it was written against, and how that snapshot now differs from the "
                . "live record.\n\n"
                . "USE WHEN: continuing a review the routine drafted, checking what was said about an earlier "
                . "week, or before saving a new version — read what is there rather than writing over the "
                . "top of it.\n\n"
                . "DO NOT use it for the week's figures; those are computed live by tracker_week_report and "
                . "the snapshot here is deliberately frozen at the moment it was written.\n\n"
                . 'Args: week. Optional version (defaults to the latest). Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'week'    => ['type' => 'string', 'pattern' => '^\\d{4}-W\\d{2}$'],
                    'version' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required' => ['week'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_save_lesson_review',
            'title' => 'Save or re-version a lesson review',
            'description' =>
                "Stores a lesson review against a session already logged, as a new version. The normal path is the "
                . "`review` argument of tracker_log_session; this tool is for the two other cases — a session that was "
                . "logged without its review, and the audit or the parent re-versioning one.\n\n"
                . "USE WHEN: the audit (lesson-review Mode B) writes a review for a session that has none, or has re-derived "
                . "a draft from the transcript and is saving the verified version (stage 'audited', note listing the "
                . "corrections); or the parent corrects a review in chat (stage 'parent').\n\n"
                . "DO NOT use it to change a topic status — the review proposes, tracker_update_topic adjudicates, with "
                . "evidence saying the audit found the bar unmet. A draft cannot be saved over an audited or parent version. "
                . "An identical re-save adds no version. The snapshot is the server's; there is no argument for it. "
                . "note is required from version 2 on and must say what changed.\n\n"
                . 'Args: subject, session_id, stage (draft|audited|parent), written_by (session|audit|chat), sections '
                . '(the review object, as tracker_log_session\'s `review`), optional note.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'subject'    => $subjectArg,
                    'session_id' => ['type' => 'integer', 'minimum' => 1],
                    'stage'      => ['type' => 'string', 'enum' => REVIEW_STAGES],
                    'written_by' => ['type' => 'string', 'enum' => REVIEW_WRITTEN_BY],
                    'sections'   => $reviewArg,
                    'note'       => ['type' => 'string', 'maxLength' => 600,
                        'description' => 'What this version changed and why. Required from version 2.'],
                ],
                'required' => ['subject', 'session_id', 'stage', 'written_by', 'sections'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_get_lesson_review',
            'title' => 'Read a lesson review',
            'description' =>
                "One session's review, rendered in the standard layout so every reader sees the same thing: the nineteen "
                . "sections, the snapshot of the record it was written against, and the drift — how the mentioned topics' "
                . "statuses have moved since, whether each watch signal has been observed, and whether the following "
                . "session's review answered the planner's check_whether.\n\n"
                . "USE WHEN: the parent asks what a lesson's review said (\"review today's maths\"), the audit re-reads a "
                . "draft before verifying it, or a session wants more than the planner block tracker_review_queue already "
                . "carries.\n\n"
                . "DO NOT call it at the start of every session — the queue's last_review block is the opener. "
                . 'planner_only: true returns just the planner and the snapshot header.\n\n'
                . 'Args: subject, session_id. Optional version (defaults to the latest), planner_only. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'subject'      => $subjectArg,
                    'session_id'   => ['type' => 'integer', 'minimum' => 1],
                    'version'      => ['type' => 'integer', 'minimum' => 1],
                    'planner_only' => ['type' => 'boolean', 'default' => false],
                ],
                'required' => ['subject', 'session_id'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_list_lesson_reviews',
            'title' => 'List lesson reviews, or the sessions still owed one',
            'description' =>
                "One line per reviewed session, newest first: session id, date, block, stage, readiness and the one-sentence "
                . "summary. With missing: true, instead the sessions that require a review and have none.\n\n"
                . "USE WHEN: the parent asks how recent lessons went, the audit wants the backlog, or you need a session id "
                . "for tracker_get_lesson_review.\n\n"
                . 'Args: subject. Optional since (YYYY-MM-DD), limit (default 10), stage, missing. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'since'   => $isoDate,
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
                    'stage'   => ['type' => 'string', 'enum' => REVIEW_STAGES],
                    'missing' => ['type' => 'boolean', 'default' => false],
                ],
                'required' => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_signals',
            'title' => 'Read the signals — how she learns, what lands',
            'description' =>
                "The longitudinal spine: every observation the lesson reviews have made about how the learner learns and "
                . "how teaching methods land, each with its strength (one_off, emerging, established — derived from the "
                . "count of distinct sessions citing it), its status, its evidence trail and its pending test.\n\n"
                . "USE WHEN: planning how to teach rather than what (the established signals are the ones to trust), the "
                . "parent asks \"how does she learn\", the weekly review rolls up patterns, or before writing a signal in a "
                . "review — reuse an existing key rather than coining a near-duplicate.\n\n"
                . "DO NOT treat a one_off as a pattern: one occurrence is an observation, and the strength says so.\n\n"
                . 'Args: all optional — subject (its signals, then the cross-subject ones), kind, status (default open), '
                . 'min_strength. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'subject'      => $subjectArg,
                    'kind'         => ['type' => 'string', 'enum' => SIGNAL_KINDS],
                    'status'       => ['type' => 'string', 'enum' => array_merge(SIGNAL_STATUSES, ['any']), 'default' => 'open'],
                    'min_strength' => ['type' => 'string', 'enum' => SIGNAL_STRENGTHS],
                ],
                'required' => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_update_signal',
            'title' => 'Move a signal: resolve, refute, set its test, add evidence',
            'description' =>
                "Changes one signal's status (resolved or refuted, with the evidence that decided it), sets or clears its "
                . "next_test, or adds an evidence row from a session outside a review. Strength is never an argument — it is "
                . "derived from the evidence rows, and a contradiction can lower it.\n\n"
                . "USE WHEN: a watch has been answered, a test has been run and the signal held or fell, the parent decides "
                . "an open test, or the weekly review promotes a learning-process signal to cross-subject (that is a new "
                . "signal with no subject; say so in its statement).\n\n"
                . "DO NOT use it to raise a strength; write the evidence in a review's signals[] and let the count rule decide.\n\n"
                . 'Args: id. Optional status (resolved|refuted, needs evidence), evidence, next_test (null clears), '
                . 'session_id and direction (supports|contradicts) to add an evidence row.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id'         => ['type' => 'integer', 'minimum' => 1],
                    'status'     => ['type' => 'string', 'enum' => ['resolved', 'refuted', 'open']],
                    'evidence'   => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                    'next_test'  => ['type' => ['string', 'null'], 'maxLength' => 300],
                    'session_id' => ['type' => 'integer', 'minimum' => 1],
                    'direction'  => ['type' => 'string', 'enum' => SIGNAL_DIRECTIONS],
                ],
                'required' => ['id'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_review_audit_queue',
            'title' => 'The auditor\'s opener: what to verify since the last audit',
            'description' =>
                "Everything the scheduled review audit needs, computed here so it is never told what to look for: sessions "
                . "since the last audit stamp that require a review and have none, reviews still at draft, the consistency "
                . "flags (a secure seen without a proposal, a promotion with no number in its evidence, a two-level rise, a "
                . "retention entry with no outcome, a test untested for 3+ sessions, a watch unreferenced for 5+, a quote that "
                . "also appears in the session summary), and the previous audit's note.\n\n"
                . "USE WHEN: opening the audit (lesson-review Mode B). Work the queue — locate each session's chat, write or "
                . "re-derive its review with tracker_save_lesson_review, correct statuses through tracker_update_topic with "
                . "evidence, move signals with tracker_update_signal — then close with tracker_audit_stamp.\n\n"
                . 'Args: subject. Read-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['subject' => $subjectArg],
                'required' => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_audit_stamp',
            'title' => 'Close an audit',
            'description' =>
                "Sets the audit stamp for a subject and stores the audit's note — what it verified, what it corrected, which "
                . "sessions' chats could not be found. The queue is then empty until new sessions arrive.\n\n"
                . "USE WHEN: the audit has worked through tracker_review_audit_queue. Stamp even when nothing needed "
                . "correcting; the note says so.\n\n"
                . 'Args: subject, note (10-1000 chars).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'note'    => ['type' => 'string', 'minLength' => 10, 'maxLength' => 1000],
                ],
                'required' => ['subject', 'note'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_retrieval_due',
            'title' => 'What to ask in a retrieval block, in order',
            'description' =>
                "An already-ordered, already-mixed set of retrieval items for a block: the service does the spacing "
                . "arithmetic and the interleaving, so the list is deterministic and identical between sittings. Ask the "
                . "items in the order returned — no two consecutive entries share a subject, and no two share a topic.\n\n"
                . "USE WHEN: running any retrieval block — the daily warm-up, the mixed quiz, a single-subject retrieval slot — "
                . "or opening a session with a starter. Call it instead of reconstructing \"what did she get wrong a fortnight "
                . "ago\" from evidence prose.\n\n"
                . "Every subject named gets at least 2 slots; the rest weight toward the subject with the most instability in "
                . "the last 14 days. Within a subject: needs_scaffold, then overdue, due today, loose ends, recently taught and "
                . "still developing, then the longest-untouched secure topics. Each entry carries grain (item or topic), key, "
                . "topic_ref, difficulty_level (1 plain · 2 varied · 3 exam), needs_scaffold, last_asked, days_overdue and a "
                . "short `why` you can put in the evidence string. Log the outcomes with tracker_log_practice (source "
                . "retrieval_warmup / retrieval_mixed / retrieval_subject), passing each entry's key back as item_key where "
                . "grain is item and topic_ref always — that is what advances the schedule.\n\n"
                . "Args: subjects[] (from the block; one or many — or block_key alone to take the block's subjects). "
                . 'Optional limit (default 8, about one item per 2 minutes), block_key, include_retired (default false). Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subjects'  => ['type' => 'array', 'maxItems' => 12,
                        'items' => ['type' => 'string', 'minLength' => 1],
                        'description' => 'Subject slugs, from the block'],
                    'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 8],
                    'block_key' => ['type' => 'integer', 'minimum' => 1,
                        'description' => "The block being run, from tracker_today; supplies the subjects when subjects is omitted"],
                    'include_retired' => ['type' => 'boolean', 'default' => false,
                        'description' => 'Include items retired at difficulty 3 after four straight correct answers'],
                ],
                'required'   => [],
            ],
            'annotations' => $readOnly,
        ],
    ];
}

// ---- tool implementations -----------------------------------------------

function mcp_call_tool(Store $store, string $name, array $a): array
{
    switch ($name) {
        case 'tracker_today': {
            $date = mcp_date($a, 'date', tt_today());
            $day  = $store->judgeDay($date);
            $v    = $store->timetableVersionOn($date);
            if (!$v) {
                return mcp_text("No timetable is in force on $date. Set one with tracker_set_timetable.");
            }
            $lines = [
                $day['day_name'] . ' ' . tt_pretty($date) . ' · ' . tt_iso_week($date)
                . ($day['is_today'] ? ' · now ' . tt_now()->format('H:i') . ' Europe/London' : ''),
            ];
            if ($day['day_off']) {
                $d = $day['day_off'];
                $lines[] = $d['status'] === 'approved'
                    ? "Day off (" . $d['kind'] . "), approved: " . $d['reason']
                    : "Day off REQUESTED by " . $d['requested_by'] . " and not yet decided: " . $d['reason']
                      . ' — blocks are still being judged until the parent approves it.';
            }
            $judged = array_values(array_filter(
                $day['blocks'], static fn(array $b): bool => $b['status'] !== 'n/a'
            ));
            if (!$judged) {
                $lines[] = 'No blocks today.';
                return mcp_text(implode("\n", $lines));
            }
            $now  = array_values(array_filter($judged, static fn($b) => $b['status'] === 'now'));
            $next = null;
            foreach ($judged as $b) {
                if (tt_mins($b['start']) > tt_mins(tt_now()->format('H:i')) && $day['is_today']) {
                    $next = $b;
                    break;
                }
            }
            $lines[] = $now
                ? 'NOW: ' . mcp_block_line($now[0])
                : ($day['is_today'] ? 'NOW: between blocks.' : '');
            if ($next) {
                $lines[] = 'NEXT: ' . mcp_block_line($next);
            }
            $lines[] = '';
            $lines[] = 'Blocks:';
            foreach ($judged as $b) {
                // reviewed / review missing / — : whether the session that
                // did the block has its lesson review, when one is required.
                $tag = '';
                foreach ($b['evidence'] ?? [] as $e) {
                    if ($e['type'] === 'session') {
                        $word = mcp_review_word($store->sessionById((int) $e['id']));
                        $tag  = $word === '' ? '' : '  · ' . $word;
                    }
                }
                $lines[] = '  ' . mcp_block_line($b) . $tag;
            }
            $missed = array_values(array_filter($judged, static fn($b) => $b['status'] === 'missed'));
            if ($missed) {
                $lines[] = '';
                $lines[] = 'Missed so far: ' . implode(', ', array_map(
                    static fn($b) => '#' . $b['block_key'] . ' ' . $b['label'], $missed
                )) . '.';
            }
            if ($day['extras']) {
                $lines[] = 'Extra work today, outside the timetable: ' . implode('; ', array_map(
                    static fn($e) => $e['subject'] . ' ' . $e['type'] . ' #' . $e['id'] . ' ' . $e['label'],
                    $day['extras']
                )) . '.';
            }
            $unmet = array_values(array_filter($judged, static fn($b) => ($b['shape'] ?? null) === 'unmet'));
            if ($unmet) {
                $lines[] = 'Done, but not the shape the block asked for (still counted as done): '
                    . implode('; ', array_map(
                        static fn($b) => '#' . $b['block_key'] . ' ' . $b['label'] . ' — ' . $b['shape_reason'],
                        $unmet
                    )) . '.';
            }

            // Continuity, per subject with a block today: what the last
            // session planned, and anything it or an earlier one left
            // unfinished. A session that opens on this call alone still
            // sees it.
            $subjects = [];
            foreach ($judged as $b) {
                if ($b['tracking'] !== 'evidence') {
                    continue;
                }
                foreach ($b['subjects'] as $slug) {
                    $subjects[$slug] = true;
                }
            }
            foreach (array_keys($subjects) as $slug) {
                if (!$store->getSubject($slug)) {
                    continue;
                }
                $lines[] = '';
                $lines[] = "## $slug";
                $lines[] = mcp_last_session_text($store, $slug);
                $open = $store->openUnfinished($slug);
                if ($open) {
                    $lines[] = '### unfinished (open — finish these before starting something new)';
                    foreach (mcp_unfinished_lines($open) as $l) {
                        $lines[] = $l;
                    }
                }
            }
            $lines[] = '';
            $lines[] = 'The timetable is a plan, not a gate: help with whatever is actually being asked.';
            return mcp_text(implode("\n", array_filter($lines, static fn($l) => $l !== '' || true)));
        }

        case 'tracker_week_status': {
            $week = mcp_str($a, 'week', false, 0, 10);
            $date = mcp_date($a, 'date', null);
            if ($week !== null && $week !== '') {
                $monday = tt_week_monday($week);
                if ($monday === null) {
                    throw new McpError("week must look like '2026-W37'.");
                }
            } else {
                $monday = tt_monday($date ?: tt_today());
            }
            $w = $store->judgeWeek($monday);
            if (!$store->timetableVersionOn($monday)) {
                return mcp_text("No timetable was in force in week {$w['week']}.");
            }
            $lines = ["Week {$w['week']} — " . tt_pretty($w['monday']) . ' to '
                . tt_pretty(tt_add_days($w['monday'], 6))];
            foreach ($w['days'] as $day) {
                $judged = array_values(array_filter(
                    $day['blocks'], static fn($b) => $b['status'] !== 'n/a'
                ));
                if (!$judged && !$day['extras']) {
                    continue;
                }
                $done = count(array_filter($judged, static fn($b) => $b['status'] === 'done'));
                $head = "\n" . $day['day_name'] . ' ' . $day['date'] . " — $done/" . count($judged) . ' done';
                if ($day['day_off']) {
                    $head .= ' · day off ' . strtoupper($day['day_off']['status'])
                        . ' (' . $day['day_off']['reason'] . ')';
                }
                $lines[] = $head;
                foreach ($judged as $b) {
                    $lines[] = '  ' . mcp_block_line($b);
                }
                foreach ($day['extras'] as $e) {
                    $lines[] = '  + extra: ' . $e['subject'] . ' ' . $e['type'] . ' #' . $e['id']
                        . ' ' . $e['label'];
                }
            }
            $c = $w['counts'];
            $lines[] = "\nCounts: {$c['done']} done ({$c['short']} short), {$c['missed']} missed, "
                . "{$c['excused']} excused, {$c['day_off']} on a day off, {$c['extra']} extra"
                . ($c['upcoming'] + $c['pending'] + $c['now'] > 0
                    ? ', ' . ($c['upcoming'] + $c['pending'] + $c['now']) . ' still to come' : '')
                . ' — of ' . $c['judged'] . ' judged blocks.';

            $targets = json_decode((string) ($store->meta('timetable_targets') ?? '{}'), true) ?: [];
            $hours   = [];
            foreach ($w['hours_by_subject'] as $slug => $h) {
                $hours[] = $slug . ' ' . number_format($h, 1) . 'h'
                    . (isset($targets[$slug]) ? ' of ' . number_format((float) $targets[$slug], 2) . 'h' : '');
            }
            foreach ($targets as $slug => $t) {
                if (!isset($w['hours_by_subject'][$slug])) {
                    $hours[] = $slug . ' 0.0h of ' . number_format((float) $t, 2) . 'h';
                }
            }
            $lines[] = 'Hours: ' . ($hours ? implode(', ', $hours) : 'none logged') . '.';
            $lines[] = 'A missed block means nothing was logged against it — which is sometimes a '
                . 'logging failure rather than a missed lesson. Check before saying it was skipped.';
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_get_timetable': {
            $on = mcp_date($a, 'valid_on', tt_today());
            $v  = $store->timetableVersionOn($on);
            if (!$v) {
                return mcp_text("No timetable is in force on $on.");
            }
            $blocks = $store->timetableBlocks((int) $v['id']);
            $lines  = ["Timetable version {$v['id']}, in force from {$v['valid_from']}"
                . ($v['note'] ? " — {$v['note']}" : '') . '. ' . count($blocks) . ' blocks.'];
            $day = null;
            foreach ($blocks as $b) {
                if ($day !== $b['weekday']) {
                    $day = $b['weekday'];
                    $lines[] = "\n" . TIMETABLE_DAYS[$day];
                }
                $subs = $b['alternate']
                    ? 'odd: ' . implode('/', $b['alternate']['odd']) . ', even: ' . implode('/', $b['alternate']['even'])
                    : ($b['subjects'] ? implode('/', $b['subjects']) : '—');
                $lines[] = sprintf('  #%-3d %s-%s  %s %s %s',
                    $b['block_key'], $b['start'], $b['end'], mcp_pad($b['label'], 44),
                    mcp_pad($b['kind'], 18), $subs)
                    . '  [' . $b['tracking'] . ']';
            }
            $lines[] = "\nblock_key is the stable id: keep it when you re-cut, or excusals, ticks and "
                . 'logged work stop resolving.';
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_days_off': {
            $from   = mcp_date($a, 'from', null);
            $to     = mcp_date($a, 'to', null);
            $status = mcp_str($a, 'status', false, 0, 20);
            $rows   = $store->listDaysOff($from, $to, $status ?: null);
            if (!$rows) {
                return mcp_text('No days off recorded for that.');
            }
            $lines = [];
            foreach ($rows as $r) {
                $span = $r['date_from'] === $r['date_to']
                    ? $r['date_from'] : $r['date_from'] . ' to ' . $r['date_to'];
                $lines[] = "#{$r['id']}  $span  {$r['kind']}  " . strtoupper($r['status'])
                    . "  asked by {$r['requested_by']} — {$r['reason']}"
                    . ($r['decision_note'] ? ' (' . $r['decision_note'] . ')' : '');
            }
            $pending = count(array_filter($rows, static fn($r) => $r['status'] === 'requested'));
            if ($pending) {
                $lines[] = "\n$pending awaiting the parent's decision. Put those to him; do not decide them.";
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_request_day_off': {
            $from = mcp_date($a, 'date_from', null);
            $to   = mcp_date($a, 'date_to', null);
            if ($from === null || $to === null) {
                throw new McpError('date_from and date_to are both required, as YYYY-MM-DD.');
            }
            if ($to < $from) {
                throw new McpError("date_to ($to) is before date_from ($from).");
            }
            $by     = mcp_str($a, 'requested_by', true, 1);
            $reason = mcp_str($a, 'reason', true, 3, 300);
            $kind   = mcp_str($a, 'kind', false, 0, 20, 'day_off');
            if (!in_array($by, ['student', 'parent'], true)) {
                throw new McpError("requested_by must be 'student' or 'parent'.");
            }
            $span = (int) ((new DateTimeImmutable($to))->diff(new DateTimeImmutable($from))->days) + 1;
            if ($by === 'student' && $span > 14) {
                throw new McpError(
                    "That is a $span-day request. A student may ask for at most 14 days at a time — "
                    . 'anything longer is a conversation with the parent, not a booking.'
                );
            }
            $rec = $store->addDayOff([
                'date_from' => $from, 'date_to' => $to, 'kind' => $kind,
                'reason' => $reason, 'requested_by' => $by,
            ]);
            if ($rec['status'] === 'approved') {
                return mcp_text("Day off #{$rec['id']} booked and approved for $from to $to: $reason.");
            }
            return mcp_text(
                "Day off #{$rec['id']} REQUESTED for $from to $to: $reason.\n"
                . "This is a request, not a booking. Dad has to approve it. Until he does, those days keep "
                . "being judged and will show as missed on the board — say so plainly rather than letting her "
                . 'think it is settled.'
            );
        }

        case 'tracker_decide_day_off': {
            $id       = (int) mcp_num($a, 'id', true, 1);
            $decision = mcp_str($a, 'decision', true, 1);
            $note     = mcp_str($a, 'note', false, 0, 300);
            if (!in_array($decision, ['approve', 'decline', 'unapprove'], true)) {
                throw new McpError("decision must be approve, decline or unapprove.");
            }
            $rec = $store->decideDayOff($id, $decision, $note);
            if (!$rec) {
                return mcp_text("There is no day-off record #$id. List them with tracker_days_off.");
            }
            $effect = match ($rec['status']) {
                'approved' => "Those days no longer count as missed.",
                'declined' => "The record is kept with the reason; those days keep being judged.",
                default    => "Back to a request: those days are being judged again and misses will reappear.",
            };
            return mcp_text("Day off #{$rec['id']} ({$rec['date_from']} to {$rec['date_to']}) is now "
                . strtoupper($rec['status']) . ". $effect");
        }

        case 'tracker_set_timetable': {
            $blocks = $a['blocks'] ?? null;
            if (!is_array($blocks) || !$blocks) {
                throw new McpError('blocks must be a non-empty list.');
            }
            // Defaults to the next Monday, because a timetable that starts
            // mid-week leaves half a week judged against the old shape.
            $validFrom = mcp_date($a, 'valid_from', tt_add_days(tt_monday(tt_today()), 7));
            $note      = mcp_str($a, 'note', false, 0, 300);
            try {
                $res = $store->setTimetable($blocks, $validFrom, $note);
            } catch (InvalidArgumentException $e) {
                throw new McpError($e->getMessage() . ' Nothing was written.');
            }
            if (isset($a['targets']) && is_array($a['targets'])) {
                $store->setMeta('timetable_targets', json_encode($a['targets'], JSON_UNESCAPED_SLASHES));
            }
            $d     = $res['diff'];
            $lines = ["Timetable version {$res['version_id']} written, in force from $validFrom, "
                . "{$res['blocks']} blocks."];
            foreach (['added' => 'Added', 'removed' => 'Removed', 'changed' => 'Changed'] as $k => $label) {
                if ($d[$k]) {
                    $lines[] = "\n$label (" . count($d[$k]) . '):';
                    foreach ($d[$k] as $l) {
                        $lines[] = '  ' . $l;
                    }
                }
            }
            if (!$d['added'] && !$d['removed'] && !$d['changed']) {
                $lines[] = 'No change from the version that was already in force.';
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_excuse_block': {
            $date = mcp_date($a, 'date', null);
            if ($date === null) {
                throw new McpError('date is required, as YYYY-MM-DD.');
            }
            $key = (int) mcp_num($a, 'block_key', true, 1);
            if (!array_key_exists('reason', $a)) {
                throw new McpError('reason is required — pass null to un-excuse.');
            }
            $block = mcp_find_block($store, $key, $date);
            if ($block === null) {
                return mcp_text("There is no block $key in the timetable in force on $date. "
                    . 'Check the keys with tracker_get_timetable.');
            }
            if ($a['reason'] === null) {
                $store->setExcusal($date, $key, null);
                return mcp_text("Block $key ({$block['label']}) on $date is no longer excused. "
                    . 'It is judged again, so it will show as missed unless work is logged against it.');
            }
            $reason = mcp_str($a, 'reason', true, 1, 300);
            $store->setExcusal($date, $key, $reason);
            return mcp_text("Block $key ({$block['label']}) on $date is excused: $reason. "
                . 'The reason is shown on the board.');
        }

        case 'tracker_tick_block': {
            $date = mcp_date($a, 'date', null);
            if ($date === null) {
                throw new McpError('date is required, as YYYY-MM-DD.');
            }
            $key  = (int) mcp_num($a, 'block_key', true, 1);
            $by   = mcp_str($a, 'by', true, 1);
            $note = mcp_str($a, 'note', false, 0, 300);
            if (!in_array($by, ['student', 'parent'], true)) {
                throw new McpError("by must be 'student' or 'parent'.");
            }
            $block = mcp_find_block($store, $key, $date);
            if ($block === null) {
                return mcp_text("There is no block $key in the timetable in force on $date.");
            }
            if ($block['tracking'] === 'none') {
                return mcp_text("Block $key ({$block['label']}) is not tracked — a break, lunch or a slot away from the desk — so there is nothing to tick.");
            }
            if ($block['tracking'] !== 'self_report') {
                throw new McpError(
                    "Block $key ({$block['label']}) is judged from logged work — log the session instead. "
                    . 'Study blocks are never ticked: they are done when a session, attempt or practice run '
                    . 'exists for one of their subjects on that date. Use tracker_log_session, '
                    . 'tracker_log_attempt or tracker_log_practice, and the block ticks itself.'
                );
            }
            $store->setTick($date, $key, $by, $note);
            return mcp_text("Block $key ({$block['label']}) on $date ticked by $by.");
        }

        case 'tracker_week_report': {
            [$week, $monday] = mcp_week_of($a);
            // The whole report is read off one snapshot, which is the same
            // thing the page renders and the same thing a save freezes: the
            // model reading this and the parent reading the board are looking
            // at one set of figures.
            $snap  = $store->weekSnapshot($monday);
            $names = [];
            foreach ($store->listSubjects() as $s) {
                $names[$s['slug']] = $s['name'];
            }
            $short = static fn(array $b): string => substr(TIMETABLE_DAYS[(int) $b['weekday']], 0, 3);

            $lines = ["Week $week — " . tt_pretty($monday) . ' to ' . tt_pretty($snap['friday'])];
            $lines[] = ucfirst(mcp_snapshot_counts_line($snap)) . '.';
            if ($snap['timetable_version_id'] === null) {
                $lines[] = 'No timetable was in force in this week, so no block was judged. Everything '
                    . 'below is still the record: sessions, attempts, practice runs and topic movement.';
            } elseif ($monday > tt_today()) {
                $lines[] = 'This week has not run yet, so every block is upcoming. Nothing here is a miss.';
            }

            $byDate = [];
            foreach ($snap['blocks'] as $b) {
                $byDate[$b['date']][] = $b;
            }
            $extrasByDate = [];
            foreach ($snap['extras'] as $e) {
                $extrasByDate[$e['date']][] = $e;
            }
            foreach ($byDate as $date => $blocks) {
                // Study blocks first, then the movement and review blocks
                // under them: they are judged differently and counted apart.
                $study = array_values(array_filter($blocks, static fn($b) => $b['tracking'] === 'evidence'));
                $rest  = array_values(array_filter($blocks, static fn($b) => $b['tracking'] !== 'evidence'));
                $done  = count(array_filter($study, static fn($b) => $b['status'] === 'done'));
                $hand  = count(array_filter($study, static fn($b) => $b['status'] === 'declared'));
                $lines[] = "\n" . TIMETABLE_DAYS[(int) $blocks[0]['weekday']] . " $date — $done/"
                    . count($study) . ' study blocks done'
                    . ($hand ? ", $hand marked by hand" : '');
                foreach (array_merge($study, $rest) as $b) {
                    $lines[] = '  ' . mcp_block_line($b);
                }
                foreach ($extrasByDate[$date] ?? [] as $e) {
                    $lines[] = '  + extra: ' . $e['subject'] . ' ' . $e['type'] . ' #' . $e['id']
                        . ' ' . $e['label'];
                }
            }

            $missed = array_values(array_filter($snap['blocks'], static fn($b) => $b['status'] === 'missed'));
            $lines[] = "\nMISSED";
            if (!$missed) {
                $lines[] = '- nothing was missed.';
            }
            foreach ($missed as $b) {
                $lines[] = '- ' . $short($b) . ' ' . $b['start'] . ' ' . $b['label']
                    . ' — ' . mcp_absent($b);
            }
            // What the parent vouched for, listed apart from both: it is
            // accounted for, it is not a miss, and it is not evidence either.
            $hand = array_values(array_filter(
                $snap['blocks'], static fn($b) => $b['status'] === 'declared'
            ));
            if ($hand) {
                $lines[] = "\nMARKED BY HAND — Dad said these happened; no work was logged";
                foreach ($hand as $b) {
                    $lines[] = '- ' . $short($b) . ' ' . $b['start'] . ' ' . $b['label']
                        . ' — marked done by Dad'
                        . ($b['reason'] ? ': ' . $b['reason'] : '')
                        . '. It is counted apart from the blocks the record proves.';
                }
            }
            $shorts = array_values(array_filter($snap['blocks'], static fn($b) => !empty($b['short'])));
            foreach ($shorts as $b) {
                $lines[] = '- SHORT: ' . $short($b) . ' ' . $b['start'] . ' ' . $b['label']
                    . ' — ' . $b['minutes'] . ' min of ' . $b['length'];
            }

            // Done in the wrong shape: counted as done above, named here so
            // the Friday review can say so. The reason describes the work.
            $unmet = array_values(array_filter(
                $snap['blocks'], static fn($b) => ($b['shape'] ?? null) === 'unmet'
            ));
            $lines[] = "\nDONE, BUT NOT THE SHAPE THE BLOCK ASKED FOR (done_shape_unmet — still counted as done)";
            if (!$unmet) {
                $lines[] = '- none.';
            }
            foreach ($unmet as $b) {
                $lines[] = '- ' . $short($b) . ' ' . $b['start'] . ' ' . $b['label'] . ' (' . $b['kind'] . ') — '
                    . $b['shape_reason'];
            }
            $noRule = [];
            foreach ($snap['blocks'] as $b) {
                if ($b['status'] === 'done' && empty($b['shape_rule']) && ($b['tracking'] ?? '') === 'evidence') {
                    $noRule[$b['kind']] = true;
                }
            }
            if ($noRule) {
                $lines[] = '- kinds with no shape rule, judged as always met: ' . implode(', ', array_keys($noRule))
                    . '. Add a row to block_kind_rules to judge them.';
            }

            $lines[] = "\nEXTRA WORK, OUTSIDE THE TIMETABLE";
            if (!$snap['extras']) {
                $lines[] = '- none. An extra never offsets a miss; it is logged beside it.';
            }
            foreach ($snap['extras'] as $e) {
                $lines[] = '- ' . $short($e) . ' ' . $e['at'] . ' ' . $e['subject'] . ' ' . $e['type']
                    . ' #' . $e['id'] . ' ' . $e['label'];
            }

            $pending = array_values(array_filter(
                $snap['days_off'], static fn($d) => $d['status'] === 'requested'
            ));
            $lines[] = "\nDAYS OFF — pending the parent's decision";
            if (!$pending) {
                $lines[] = '- none waiting.';
            }
            foreach ($pending as $d) {
                $span = $d['date_from'] === $d['date_to']
                    ? tt_pretty($d['date_from'])
                    : tt_pretty($d['date_from']) . ' to ' . tt_pretty($d['date_to']);
                $lines[] = '- #' . $d['id'] . ' ' . $span . ', asked by ' . $d['requested_by']
                    . ': ' . $d['reason'] . ' → put it to him; do not decide it.';
            }

            $lines[] = "\nHOURS AGAINST THE TIMETABLE";
            $slugs = array_keys($snap['planned_by_subject'] + $snap['hours_by_subject']);
            sort($slugs);
            $bits = [];
            $dh   = 0.0;
            $ph   = 0.0;
            foreach ($slugs as $slug) {
                $h = (float) ($snap['hours_by_subject'][$slug] ?? 0);
                $p = (float) ($snap['planned_by_subject'][$slug] ?? 0);
                $dh += $h;
                $ph += $p;
                $bits[] = $slug . ' ' . number_format($h, 2) . '/' . number_format($p, 2);
            }
            $lines[] = $bits ? implode(' · ', $bits) : 'Nothing planned and nothing logged.';
            $lines[] = 'Total ' . number_format($dh, 2) . ' of ' . number_format($ph, 2)
                . ' planned hours. The planned figure is the timetable\'s, not the skills\' split, and '
                . 'counts only the blocks that resolve to one subject.';

            $lines[] = "\nTIMED / HANDWRITTEN";
            foreach ($snap['timed']['this_week'] as $t) {
                $lines[] = '- ' . $t['date'] . ' ' . $t['label'] . ' — ' . ($t['evidence'] ?? 'no evidence')
                    . ', ' . $t['minutes'] . ' min'
                    . ($t['measured'] ? ' recorded' : ' (the block length; no duration was recorded)')
                    . ($t['blanks'] === null ? '' : ', ' . $t['blanks'] . ' blank'
                        . ($t['blanks'] === 1 ? '' : 's'));
            }
            if (!$snap['timed']['this_week']) {
                $l = $snap['timed']['last'];
                $lines[] = '- none this week' . ($l
                    ? ' (last: ' . $l['date'] . ', ' . $l['label'] . ', ' . $l['minutes'] . ' min'
                        . ($l['measured'] ? '' : ' — the block length, not a measured sitting')
                        . ($l['blanks'] === null ? '' : ', ' . $l['blanks'] . ' blanks') . ')'
                    : ' — and none in the eight weeks before it, so stamina has no new reading') . '.';
            }

            foreach ($names as $slug => $name) {
                $lines[] = "\n### $slug — $name";
                $moved = array_values(array_filter(
                    $snap['changes'], static fn($c) => $c['subject_slug'] === $slug
                ));
                if (!$moved) {
                    $lines[] = '- no topic movement this week.';
                }
                foreach ($moved as $c) {
                    $from = $c['from_status'] ? (STATUS_LABEL[$c['from_status']] ?? $c['from_status']) : '—';
                    $to   = STATUS_LABEL[$c['to_status']] ?? $c['to_status'];
                    $lines[] = '- ' . $c['ref'] . ($c['topic_name'] ? ' ' . $c['topic_name'] : '')
                        . ' ' . $from . '→' . $to . ' — ' . $c['evidence'];
                }
                foreach ($snap['attempts'] as $x) {
                    if ($x['subject_slug'] !== $slug) {
                        continue;
                    }
                    $lines[] = '- sat: ' . $x['name'] . ' (' . $x['kind'] . ', ' . $x['date'] . ') '
                        . num($x['score']) . '/' . num($x['max'])
                        . ($x['blanks'] === null ? '' : ' · ' . $x['blanks'] . ' blank'
                            . ($x['blanks'] === 1 ? '' : 's'));
                }
                $p = $snap['practice'][$slug] ?? null;
                $lines[] = $p
                    ? '- practice: ' . $p['runs'] . ' run' . ($p['runs'] === 1 ? '' : 's') . ', '
                        . $p['attempted'] . ' attempted, ' . $p['correct'] . ' right first time'
                        . ($p['first_time_pct'] === null ? '' : ' (' . $p['first_time_pct'] . '%)')
                        . ', best run ' . $p['best_score'] . '.'
                    : '- practice: none logged this week.';
                $cov = $snap['coverage'][$slug] ?? null;
                if ($cov) {
                    $delta = $cov['pct_end'] - $cov['pct_start'];
                    $lines[] = '- coverage ' . $cov['pct_end'] . '% at the end of the week, '
                        . ($delta === 0 ? 'unchanged across it' : ($delta > 0 ? '+' : '') . $delta
                            . ' points across it')
                        . ', against today\'s ' . $cov['topics'] . ' topics.';
                }
                $q = $snap['queue_top'][$slug] ?? null;
                $lines[] = $q ? '- next in the queue: ' . $q['line'] : '- the review queue is empty.';
                $u = $snap['unfinished'][$slug] ?? null;
                if ($u !== null) {
                    $lines[] = '- unfinished: ' . count($u['opened']) . ' opened this week, '
                        . count($u['closed']) . ' closed this week, ' . count($u['open_now']) . ' open now.';
                    foreach ($u['open_now'] as $item) {
                        $lines[] = '  - OPEN: session ' . $item['session_id'] . ' (' . $item['date'] . ', '
                            . $item['days_open'] . ' days' . ($item['stale'] ? ', STALE' : '') . '): '
                            . $item['text'];
                    }
                    foreach ($u['closed'] as $item) {
                        $lines[] = '  - closed: session ' . $item['session_id'] . ' — ' . $item['text']
                            . ' (' . $item['closed_reason'] . ')';
                    }
                }
            }

            // What each taught session's review concluded, and how the
            // signals moved, so parent-weekly-review rolls patterns up
            // without re-deriving them from prose.
            $sunday = tt_add_days($monday, 6);
            $lines[] = "\nREVIEWS THIS WEEK";
            $pairs   = $store->lessonReviewsBetween($monday, $sunday);
            if (!$pairs) {
                $lines[] = '- no lesson review saved for a session this week.';
            }
            foreach ($pairs as $pair) {
                $x  = $pair['session'];
                $rv = $pair['review'];
                $lines[] = '- ' . $x['date'] . ' ' . $x['subject_slug'] . ' session ' . $x['id'] . ' · '
                    . ($rv['sections']['big_picture']['readiness'] ?? '?') . ' (' . $rv['stage'] . ') — '
                    . ($rv['sections']['one_sentence'] ?? '');
            }
            $owed = [];
            foreach ($names as $slug => $_) {
                foreach ($store->sessionsMissingReview($slug, $monday) as $x) {
                    if ($x['date'] <= $sunday) {
                        $owed[] = $x['subject_slug'] . ' session ' . $x['id'] . ' (' . $x['date'] . ')';
                    }
                }
            }
            if ($owed) {
                $lines[] = '- REVIEW MISSING: ' . implode(', ', $owed) . '.';
            }
            $lines[] = "\nSIGNAL MOVEMENT";
            $events = $store->signalEventsBetween($monday, $sunday);
            if (!$events) {
                $lines[] = '- no signal opened, strengthened, resolved or refuted this week.';
            }
            foreach ($events as $ev) {
                $who = ($ev['subject_slug'] ?? 'cross-subject') . ' ' . $ev['key'];
                $lines[] = '- ' . match ((string) $ev['change']) {
                    'opened'   => "$who opened (one_off): " . $ev['statement'],
                    'strength' => "$who {$ev['from_value']} → {$ev['to_value']}"
                        . ($ev['to_value'] === 'established' ? ' — ESTABLISHED, name it to the parent with its trail' : ''),
                    default    => "$who {$ev['from_value']} → {$ev['to_value']}" . ($ev['detail'] ? ' — ' . $ev['detail'] : ''),
                } . ($ev['session_id'] ? ' (session ' . $ev['session_id'] . ')' : '');
            }
            $stale = [];
            foreach ($store->signals(['status' => 'open']) as $g) {
                if ($g['next_test'] === null || $g['next_test'] === '' || $g['last_session'] === null) {
                    continue;
                }
                $lastEv = $store->sessionById((int) $g['last_session']);
                if ($lastEv && $store->sessionsSince((string) $lastEv['subject_slug'], (string) $lastEv['date'], (int) $lastEv['id'])
                    >= REVIEW_TEST_STALE_SESSIONS) {
                    $stale[] = '#' . $g['id'] . ' ' . $g['key'] . ': ' . $g['next_test'];
                }
            }
            if ($stale) {
                $lines[] = '- tests open ' . REVIEW_TEST_STALE_SESSIONS . '+ sessions, to put to the parent as decisions: '
                    . implode('; ', $stale) . '.';
            }

            $review    = $store->weeklyReview($week);
            $lastTimed = $snap['timed']['this_week']
                ? $snap['timed']['this_week'][count($snap['timed']['this_week']) - 1]
                : $snap['timed']['last'];
            $lines[] = "\nROTATION";
            $lines[] = '- last timed piece: ' . ($lastTimed
                ? $lastTimed['date'] . ' ' . $lastTimed['label']
                    . ($lastTimed['evidence'] ? ' — ' . $lastTimed['evidence'] : '')
                : 'none in the last eight weeks');
            $lines[] = '- next: ' . (($review['sections']['rotation_next'] ?? '') !== ''
                ? $review['sections']['rotation_next'] . ' (from the saved review)'
                : 'not named yet — read the attempts and name it in the review.');

            if ($review) {
                $lines[] = "\nWEEKLY REVIEW ALREADY SAVED — version " . $review['version'] . ', '
                    . $review['stage'] . ', ' . mcp_written_by($review['written_by']) . ', '
                    . mcp_review_when((string) $review['written_at']);
                foreach (mcp_review_sections_text($review['sections']) as $l) {
                    $lines[] = $l;
                }
                $lines[] = 'Read it in full with tracker_get_weekly_review, and save over it only as a '
                    . 'new version.';
            } else {
                $lines[] = "\nNo weekly review has been written for $week yet — that is "
                    . 'tracker_save_weekly_review.';
            }

            $lines[] = '';
            $lines[] = 'A missed block means nothing was logged against it — which is sometimes a '
                . 'logging failure rather than a missed lesson. Check before saying it was skipped.';
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_save_weekly_review': {
            [$week, $monday] = mcp_week_of($a, true);
            $stage = mcp_str($a, 'stage', true, 1, 20);
            if (!in_array($stage, ['draft', 'reviewed'], true)) {
                throw new McpError("stage must be 'draft' or 'reviewed'.");
            }
            $by = mcp_str($a, 'written_by', true, 1, 20);
            if (!in_array($by, ['routine', 'chat'], true)) {
                throw new McpError("written_by must be 'routine' or 'chat'.");
            }
            $note = mcp_str($a, 'note', false, 0, 300);

            if ($monday > tt_today()) {
                throw new McpError(
                    "Week $week has not started — it begins on " . tt_pretty($monday) . '. There is '
                    . 'nothing to review yet; write it once the week has run.'
                );
            }
            // A routine that fires late must not stamp a draft over a review
            // that has already happened.
            if ($stage === 'draft') {
                foreach ($store->weeklyReviewVersions($week) as $v) {
                    if ($v['stage'] === 'reviewed') {
                        throw new McpError(
                            "Week $week already has a reviewed version (version {$v['version']}, written "
                            . mcp_review_when((string) $v['written_at']) . "). Send stage 'reviewed' "
                            . 'instead, carrying these sections forward with whatever has changed. '
                            . 'Nothing was written.'
                        );
                    }
                }
            }

            // Everything is validated before the database is touched, and one
            // bad section refuses the whole call.
            $sections = mcp_review_sections($store, $a['sections'] ?? null, $monday);

            // The snapshot is the server's, always. The tool has no snapshot
            // argument — not even an ignored one — so a note can never
            // mis-state the week it sits beside.
            $res = $store->addWeeklyReview([
                'week'       => $week,
                'stage'      => $stage,
                'written_by' => $by,
                'snapshot'   => $store->weekSnapshot($monday),
                'sections'   => $sections,
                'note'       => $note,
            ]);
            $row  = $res['row'];
            $when = mcp_review_when((string) $row['written_at']);
            $head = $res['status'] === 'duplicate'
                ? "Version {$row['version']} ({$row['stage']}) for $week already says exactly this, "
                    . "written $when — no version was added."
                : "Saved version {$row['version']} ({$row['stage']}) for $week, written $when.";
            return mcp_text(
                $head . ' Snapshot: ' . mcp_snapshot_counts_line($row['snapshot'])
                . ". Read it at /week/$week."
            );
        }

        case 'tracker_get_weekly_review': {
            [$week] = mcp_week_of($a, true);
            $version  = isset($a['version']) ? (int) mcp_num($a, 'version', false, 1, 999) : null;
            $versions = $store->weeklyReviewVersions($week);
            if (!$versions) {
                return mcp_text(
                    "No weekly review has been written for $week. Write one with "
                    . 'tracker_save_weekly_review after reading tracker_week_report.'
                );
            }
            $row = $store->weeklyReview($week, $version);
            if (!$row) {
                return mcp_text(
                    "There is no version $version for $week. Versions that exist: "
                    . implode(', ', array_map(
                        static fn($v) => $v['version'] . ' (' . $v['stage'] . ')', $versions
                    )) . '.'
                );
            }
            $last  = $versions[count($versions) - 1]['version'];
            $lines = [
                'version ' . $row['version'] . ' of ' . $last . ' · ' . $row['stage'] . ' · '
                . mcp_written_by($row['written_by']) . ' · ' . mcp_review_when((string) $row['written_at']),
            ];
            if ($row['note']) {
                $lines[] = 'Note on the save: ' . $row['note'];
            }
            $lines[] = 'Versions: ' . implode(', ', array_map(
                static fn($v) => $v['version'] . ' ' . $v['stage'] . ' ('
                    . mcp_review_when((string) $v['written_at']) . ')',
                $versions
            ));
            $lines[] = '';
            foreach (mcp_review_sections_text($row['sections']) as $l) {
                $lines[] = $l;
            }

            $snap  = $row['snapshot'];
            $lines[] = '';
            $lines[] = 'Snapshot taken when it was written: ' . mcp_snapshot_counts_line($snap) . '.';
            $hours = [];
            foreach (array_keys(($snap['planned_by_subject'] ?? []) + ($snap['hours_by_subject'] ?? [])) as $slug) {
                $hours[] = $slug . ' ' . number_format((float) ($snap['hours_by_subject'][$slug] ?? 0), 2)
                    . '/' . number_format((float) ($snap['planned_by_subject'][$slug] ?? 0), 2);
            }
            sort($hours);
            if ($hours) {
                $lines[] = 'Hours then, against the timetable: ' . implode(' · ', $hours) . '.';
            }

            // The drift line, from the same comparison the page renders, so
            // the note read here and the note read on the board say the same
            // thing about how far the record has moved since.
            $drift   = $store->weekDrift($snap);
            $lines[] = '';
            $lines[] = $drift['line'];
            if ($drift['since'] === '') {
                $lines[] = 'The record has not moved since: the snapshot still matches it.';
            }
            $lines[] = '';
            $lines[] = 'The figures here are frozen. For the week as it stands now, call '
                . 'tracker_week_report.';
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_save_lesson_review': {
            $slug = mcp_str($a, 'subject', true, 1);
            $id   = (int) mcp_num($a, 'session_id', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $session = $store->getSession($slug, $id);
            if (!$session) {
                return mcp_text("No session $id in $slug. Call tracker_list_lesson_reviews(missing: true) or tracker_history for the ids.");
            }
            if ($session['void_reason'] !== null) {
                throw new McpError("Session $id is void ({$session['void_reason']}); a void session is not reviewed. Nothing was written.");
            }
            $stage = mcp_str($a, 'stage', true, 1, 20);
            if (!in_array($stage, REVIEW_STAGES, true)) {
                throw new McpError('stage must be one of: ' . implode(', ', REVIEW_STAGES) . '.');
            }
            $by = mcp_str($a, 'written_by', true, 1, 20);
            if (!in_array($by, REVIEW_WRITTEN_BY, true)) {
                throw new McpError('written_by must be one of: ' . implode(', ', REVIEW_WRITTEN_BY) . '.');
            }
            $note     = mcp_str($a, 'note', false, 0, 600);
            $versions = $store->lessonReviewVersions($id);
            // The late-routine guard: a draft cannot land over a version the
            // audit or the parent has already written.
            if ($stage === 'draft') {
                foreach ($versions as $v) {
                    if ($v['stage'] !== 'draft') {
                        throw new McpError("Session $id already has a {$v['stage']} review (version {$v['version']}, written "
                            . mcp_review_when((string) $v['written_at']) . "). Send stage '{$v['stage']}' or later, carrying "
                            . 'its sections forward with whatever changed. Nothing was written.');
                    }
                }
            }
            if ($versions && ($note === null || trim($note) === '')) {
                throw new McpError("Session $id already has version " . count($versions) . '; a new version needs a note '
                    . 'saying what changed and why. Nothing was written.');
            }
            $ctx      = mcp_review_context_for_session($store, $slug, $id);
            $sections = mcp_lesson_sections($a['sections'] ?? null, $ctx);
            $out      = $store->transaction(static fn(): array =>
                mcp_apply_review($store, $slug, $id, $sections, $stage, $by, $note));
            $row  = $out['row'];
            $when = mcp_review_when((string) $row['written_at']);
            $head = $out['status'] === 'duplicate'
                ? "Version {$row['version']} ({$row['stage']}) for session $id already says exactly this, written $when — no version was added."
                : "Saved version {$row['version']} ({$row['stage']}) for session $id, written $when.";
            $lines = array_merge([$head], $out['lines']);
            $lines[] = "Read it with tracker_get_lesson_review or at /s/$slug/session/$id.";
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_get_lesson_review': {
            $slug = mcp_str($a, 'subject', true, 1);
            $id   = (int) mcp_num($a, 'session_id', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $session = $store->getSession($slug, $id);
            if (!$session) {
                return mcp_text("No session $id in $slug.");
            }
            $version  = isset($a['version']) ? (int) mcp_num($a, 'version', false, 1, 999) : null;
            $versions = $store->lessonReviewVersions($id);
            if (!$versions) {
                return mcp_text("Session $id ($session[date]) has no lesson review"
                    . ((int) ($session['review_required'] ?? 0) === 1 ? ' and requires one' : '')
                    . '. Write one with tracker_save_lesson_review.');
            }
            $row = $store->lessonReview($id, $version);
            if (!$row) {
                return mcp_text("There is no version $version for session $id. Versions that exist: "
                    . implode(', ', array_map(static fn($v) => $v['version'] . ' (' . $v['stage'] . ')', $versions)) . '.');
            }
            $last  = $versions[count($versions) - 1]['version'];
            $lines = ["Lesson review — session $id, {$session['date']} · version {$row['version']} of $last · {$row['stage']} · "
                . review_written_by((string) $row['written_by']) . ' · ' . mcp_review_when((string) $row['written_at'])];
            if ($row['note']) {
                $lines[] = 'Note on the save: ' . $row['note'];
            }
            if (count($versions) > 1) {
                $lines[] = 'Versions: ' . implode(', ', array_map(
                    static fn($v) => $v['version'] . ' ' . $v['stage'] . ' (' . mcp_review_when((string) $v['written_at']) . ')'
                        . ($v['note'] ? ' — ' . $v['note'] : ''),
                    $versions
                ));
            }
            $lines[] = '';
            if (!empty($a['planner_only'])) {
                $lines[] = 'PLANNER';
                foreach (review_planner_lines($row['sections']['planner'] ?? []) as $l) {
                    $lines[] = $l;
                }
                $lines[] = 'READINESS: ' . ($row['sections']['big_picture']['readiness'] ?? '?');
                return mcp_text(implode("\n", $lines));
            }
            foreach (review_render_text($row['sections'], $row['snapshot']) as $l) {
                $lines[] = $l;
            }
            $snap = $row['snapshot'];
            $lines[] = '';
            $lines[] = 'SNAPSHOT (the record when this was written, ' . mcp_review_when((string) ($snap['captured_at'] ?? $row['written_at'])) . ')';
            if (!empty($snap['block'])) {
                $b = $snap['block'];
                $lines[] = '  block ' . $b['block_key'] . ' ' . $b['label'] . ' (' . $b['kind'] . ') judged ' . $b['status']
                    . (($b['shape'] ?? null) === 'unmet' ? ' — shape unmet: ' . $b['shape_reason'] : '');
            }
            foreach ($snap['statuses'] ?? [] as $ref => $st) {
                $lines[] = '  ' . $ref . ': ' . ($st['before'] ?? '—') . ' → ' . ($st['after'] ?? '—')
                    . (!empty($st['moved']) ? ' (moved by this session)' : '')
                    . (isset($snap['outcomes'][$ref]) ? ' · retrieval ' . $snap['outcomes'][$ref] : '');
            }
            if (!empty($snap['practice'])) {
                $lines[] = '  practice that day: ' . implode('; ', array_map(
                    static fn(array $p): string => $p['source'] . ' ' . $p['correct'] . '/' . $p['attempted'], $snap['practice']
                ));
            }
            if (!empty($snap['unfinished'])) {
                $lines[] = '  unfinished at the time: ' . $snap['unfinished'];
            }
            $lines[] = '';
            $lines[] = 'DRIFT (the record now against the snapshot)';
            foreach ($store->lessonReviewDrift($row)['lines'] as $l) {
                $lines[] = '  ' . $l;
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_list_lesson_reviews': {
            $slug  = mcp_str($a, 'subject', true, 1);
            $limit = (int) mcp_num($a, 'limit', false, 1, 100, 10);
            $since = mcp_date($a, 'since');
            $stage = mcp_str($a, 'stage', false, 1, 20);
            if ($stage !== null && !in_array($stage, REVIEW_STAGES, true)) {
                throw new McpError('stage must be one of: ' . implode(', ', REVIEW_STAGES) . '.');
            }
            $r = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            if (!empty($a['missing'])) {
                $rows = $store->sessionsMissingReview($slug, $since);
                if (!$rows) {
                    return mcp_text('No session in ' . $r['subject']['name'] . ' is owed a review.');
                }
                $lines = ['Sessions that require a review and have none — ' . $r['subject']['name']];
                foreach (array_slice($rows, 0, $limit) as $x) {
                    $lines[] = '- session ' . $x['id'] . ' · ' . $x['date'] . ' · block '
                        . ($x['block_key'] === null ? 'extra' : $x['block_key'])
                        . ($x['duration_minutes'] !== null ? ' · ' . $x['duration_minutes'] . ' min' : '')
                        . ' — ' . mb_substr((string) $x['summary'], 0, 100);
                }
                $lines[] = 'Save each with tracker_save_lesson_review(subject, session_id, stage, written_by, sections).';
                return mcp_text(implode("\n", $lines));
            }
            $pairs = $store->listLessonReviews($slug, ['since' => $since, 'limit' => $limit, 'stage' => $stage]);
            if (!$pairs) {
                return mcp_text('No lesson reviews for ' . $r['subject']['name'] . ($since ? " since $since" : '') . ' yet.');
            }
            $lines = ['Lesson reviews — ' . $r['subject']['name'] . ', newest first'];
            foreach ($pairs as $pair) {
                $x  = $pair['session'];
                $rv = $pair['review'];
                $lines[] = '- session ' . $x['id'] . ' · ' . $x['date'] . ' · block '
                    . ($x['block_key'] === null ? 'extra' : $x['block_key']) . ' · ' . $rv['stage'] . ' v' . $rv['version']
                    . ' · ' . ($rv['sections']['big_picture']['readiness'] ?? '?') . ' — '
                    . ($rv['sections']['one_sentence'] ?? '');
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_signals': {
            $slug = mcp_str($a, 'subject', false, 1);
            if ($slug !== null) {
                $r = mcp_resolve($store, $slug);
                if (isset($r['error'])) {
                    return mcp_text($r['error']);
                }
            }
            $kind = mcp_str($a, 'kind', false, 1, 30);
            if ($kind !== null && !in_array($kind, SIGNAL_KINDS, true)) {
                throw new McpError('kind must be one of: ' . implode(', ', SIGNAL_KINDS) . '.');
            }
            $status = mcp_str($a, 'status', false, 1, 20, 'open');
            if (!in_array($status, array_merge(SIGNAL_STATUSES, ['any']), true)) {
                throw new McpError('status must be one of: ' . implode(', ', SIGNAL_STATUSES) . ', any.');
            }
            $min = mcp_str($a, 'min_strength', false, 1, 20);
            if ($min !== null && !in_array($min, SIGNAL_STRENGTHS, true)) {
                throw new McpError('min_strength must be one of: ' . implode(', ', SIGNAL_STRENGTHS) . '.');
            }
            $rows = $store->signals([
                'subject' => $slug, 'include_cross' => true, 'kind' => $kind,
                'status' => $status === 'any' ? null : $status, 'min_strength' => $min,
            ]);
            if (!$rows) {
                return mcp_text('No signals match' . ($slug ? " for $slug" : '') . '. They are written by lesson reviews (signals[] and watch[]).');
            }
            $lines = ['Signals' . ($slug ? " — $slug, then cross-subject" : ' — every subject') . " · status $status"];
            $group = null;
            foreach ($rows as $g) {
                $head = ($g['subject_slug'] ?? 'cross-subject') . ' · ' . $g['strength'];
                if ($head !== $group) {
                    $group   = $head;
                    $lines[] = "\n### $head";
                }
                $lines[] = '- ' . signal_line($g);
                $ev = $store->signalEvidence($g['id']);
                foreach (array_slice($ev, -3) as $e) {
                    $lines[] = '    ' . ($e['direction'] === 'supports' ? '+' : '−') . ' session ' . $e['session_id']
                        . ' (' . $e['date'] . '): ' . $e['evidence'];
                }
                if (count($ev) > 3) {
                    $lines[] = '    …' . (count($ev) - 3) . ' earlier row' . (count($ev) - 3 === 1 ? '' : 's');
                }
            }
            $lines[] = '';
            $lines[] = 'Strength is derived: one_off 1 session, emerging 2 distinct, established 3 with no newer '
                . 'contradiction. Move a signal with tracker_update_signal; strengthen it by citing it in a review.';
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_update_signal': {
            $id = (int) mcp_num($a, 'id', true, 1);
            $g  = $store->signalById($id);
            if (!$g) {
                return mcp_text("No signal $id. List them with tracker_signals.");
            }
            $status   = mcp_str($a, 'status', false, 1, 20);
            $evidence = mcp_str($a, 'evidence', false, 10, 600);
            $sid      = isset($a['session_id']) ? (int) mcp_num($a, 'session_id', false, 1) : null;
            $dir      = mcp_str($a, 'direction', false, 1, 20);
            $what     = [];
            if ($status !== null) {
                if (!in_array($status, SIGNAL_STATUSES, true)) {
                    throw new McpError('status must be one of: ' . implode(', ', SIGNAL_STATUSES) . '.');
                }
                if ($status !== 'open' && $evidence === null) {
                    throw new McpError("Marking a signal $status needs evidence (10-600 chars) saying what decided it. Nothing was written.");
                }
            }
            if ($sid !== null) {
                $row = $store->sessionById($sid);
                if (!$row || ($g['subject_slug'] !== null && (string) $row['subject_slug'] !== $g['subject_slug'])) {
                    throw new McpError("session_id $sid is not a session" . ($g['subject_slug'] ? " of {$g['subject_slug']}" : '')
                        . '. Nothing was written.');
                }
                if ($evidence === null) {
                    throw new McpError('Adding an evidence row needs evidence (10-600 chars). Nothing was written.');
                }
                $dir ??= $status === 'refuted' ? 'contradicts' : 'supports';
                if (!in_array($dir, SIGNAL_DIRECTIONS, true)) {
                    throw new McpError('direction must be supports or contradicts.');
                }
            } elseif ($dir !== null) {
                throw new McpError('direction goes with session_id: an evidence row belongs to a session. Nothing was written.');
            }
            $changes = [];
            if ($status !== null) {
                $changes['status'] = $status;
                $changes['evidence'] = $evidence;
            }
            if (array_key_exists('next_test', $a)) {
                $changes['next_test'] = $a['next_test'] === null ? null : mcp_str($a, 'next_test', false, 10, 300);
            }
            if (!$changes && $sid === null) {
                throw new McpError('Give status, next_test, or session_id with evidence to change something.');
            }
            $store->transaction(function () use ($store, $id, $sid, $dir, $evidence, $changes, $g, $status, &$what): void {
                if ($sid !== null) {
                    $res = $store->addSignalEvidence($id, $sid, $dir, $evidence);
                    $what[] = "evidence row added from session $sid ($dir)";
                    if ($res['strength_from'] !== $res['strength_to']) {
                        $what[] = 'strength ' . $res['strength_from'] . ' → ' . $res['strength_to'] . ' (re-derived)';
                    }
                }
                if ($changes) {
                    $changes['session_id'] = $sid;
                    $store->updateSignal($id, $changes);
                    if ($status !== null && $status !== $g['status']) {
                        $what[] = 'status ' . $g['status'] . ' → ' . $status;
                    }
                    if (array_key_exists('next_test', $changes) && $changes['next_test'] !== $g['next_test']) {
                        $what[] = $changes['next_test'] === null ? 'next_test cleared' : 'next_test set';
                    }
                }
            });
            $now = $store->signalById($id);
            return mcp_text("Signal $id {$g['key']}: " . ($what ? implode('; ', $what) : 'nothing changed') . ".\n"
                . signal_line($now));
        }

        case 'tracker_review_audit_queue': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $q     = $store->reviewAuditQueue($slug);
            $lines = ['**Review audit — ' . $r['subject']['name'] . '** · ' . tt_today()];
            $lines[] = $q['stamp']['at'] === null
                ? 'No audit has run for this subject yet; everything is in scope.'
                : 'Last audit ' . mcp_review_when((string) $q['stamp']['at']) . '. Its note: ' . ($q['stamp']['note'] ?? '—');

            $lines[] = "\n### Sessions owed a review (" . count($q['missing']) . ')';
            if (!$q['missing']) {
                $lines[] = '- none.';
            }
            foreach ($q['missing'] as $x) {
                $lines[] = '- session ' . $x['id'] . ' · ' . $x['date'] . ' · block '
                    . ($x['block_key'] === null ? 'extra' : $x['block_key'])
                    . ($x['duration_minutes'] !== null ? ' · ' . $x['duration_minutes'] . ' min' : '')
                    . ' — ' . mb_substr((string) $x['summary'], 0, 120)
                    . (!empty($x['carried_over']) ? ' (carried over from before the last audit)' : '')
                    . ' → find the chat by date and block, write the review with written_by audit.';
            }

            $lines[] = "\n### Drafts to verify (" . count($q['drafts']) . ')';
            if (!$q['drafts']) {
                $lines[] = '- none.';
            }
            foreach ($q['drafts'] as $pair) {
                $x  = $pair['session'];
                $rv = $pair['review'];
                $lines[] = '- session ' . $x['id'] . ' · ' . $x['date'] . ' · v' . $rv['version'] . ' draft · '
                    . ($rv['sections']['big_picture']['readiness'] ?? '?') . ' — ' . ($rv['sections']['one_sentence'] ?? '')
                    . ' → re-derive from the transcript; save an audited version whose note lists the corrections.';
            }

            $lines[] = "\n### Consistency flags (" . count($q['flags']) . ')';
            if (!$q['flags']) {
                $lines[] = '- none.';
            }
            foreach ($q['flags'] as $f) {
                $lines[] = '- [' . $f['code'] . '] ' . $f['text'];
            }

            $lines[] = '';
            $lines[] = 'A draft is a claim to be tested, not a starting point to be polished. Status corrections go '
                . 'through tracker_update_topic with evidence saying the audit found the bar unmet. Close with '
                . "tracker_audit_stamp(subject: \"$slug\", note). If a session's chat cannot be found, say so in the note "
                . "and save its review audited with note 'transcript unavailable; verified against record only'.";
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_audit_stamp': {
            $slug = mcp_str($a, 'subject', true, 1);
            $note = mcp_str($a, 'note', true, 10, 1000);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $at = $store->setAuditStamp($slug, $note);
            $q  = $store->reviewAuditQueue($slug);
            return mcp_text("Audit stamped for $slug at " . mcp_review_when($at) . '. Note stored. '
                . 'Still owed a review: ' . count($q['missing']) . ' session' . (count($q['missing']) === 1 ? '' : 's')
                . ' (carried over to the next audit); ' . count($q['flags']) . ' signal flag'
                . (count($q['flags']) === 1 ? '' : 's') . ' still standing.');
        }

        case 'tracker_retrieval_due': {
            $limit    = (int) mcp_num($a, 'limit', false, 1, 60, 8);
            $retired  = !empty($a['include_retired']);
            $blockKey = isset($a['block_key']) ? (int) mcp_num($a, 'block_key', false, 1) : null;
            $subjects = $a['subjects'] ?? null;
            if ($subjects !== null && !is_array($subjects)) {
                throw new McpError('subjects must be an array of subject slugs.');
            }
            $subjects = array_values(array_filter(array_map(
                static fn($s) => is_string($s) ? trim($s) : '', $subjects ?? []
            ), static fn(string $s): bool => $s !== ''));
            $blockLabel = null;
            if ($blockKey !== null) {
                $block = mcp_find_block($store, $blockKey, tt_today());
                if ($block === null) {
                    throw new McpError(mcp_no_such_block($blockKey, tt_today()));
                }
                $blockLabel = $block['label'];
                if (!$subjects) {
                    $subjects = tt_subjects_for($block, tt_today());
                }
            }
            if (!$subjects) {
                throw new McpError('subjects is required: the slugs of the block being run, or a block_key '
                    . 'from tracker_today to take them from.');
            }
            foreach ($subjects as $slug) {
                $r = mcp_resolve($store, $slug);
                if (isset($r['error'])) {
                    return mcp_text($r['error']);
                }
            }

            $due = retrieval_due($store, $subjects, $limit, $retired);
            $lines = ['**Retrieval due — ' . implode(', ', $subjects) . '**'
                . ($blockLabel ? " · block $blockKey $blockLabel" : '') . ' · ' . tt_today()];
            $slotBits = [];
            foreach ($subjects as $slug) {
                $slotBits[] = $slug . ' ' . ($due['slots'][$slug] ?? 0) . ' of ' . $limit
                    . ' (instability ' . ($due['instability'][$slug] ?? 0) . ', '
                    . ($due['candidates'][$slug] ?? 0) . ' candidates)';
            }
            $lines[] = 'Slots: ' . implode(' · ', $slotBits) . '.';
            $lines[] = 'Intervals in force (days): ' . implode(', ', array_map(
                static fn(string $s): string => $s . ' [' . implode(',', $store->retrievalIntervals($s)) . ']',
                $subjects
            )) . '.';
            if (!$due['entries']) {
                $lines[] = '';
                $lines[] = 'Nothing to ask: no scheduled items, loose ends, recent teaching or secure topics '
                    . 'in these subjects yet. Log practice with topic_refs and items to build the schedule.';
                return mcp_text(implode("\n", $lines));
            }
            $lines[] = '';
            $lines[] = 'Ask in this order:';
            foreach ($due['entries'] as $i => $e) {
                $lines[] = sprintf(
                    '%2d. [%s] %s%s — %s · level %d%s · last asked %s · %s',
                    $i + 1,
                    $e['subject'],
                    $e['topic_ref'] ?? '(no topic)',
                    $e['topic_name'] ? ' ' . $e['topic_name'] : '',
                    $e['grain'] === 'item'
                        ? 'item ' . $e['key'] . ($e['prompt'] ? ': ' . str_replace("\n", ' ', $e['prompt']) : '')
                        : 'topic',
                    $e['difficulty_level'],
                    $e['needs_scaffold'] ? ' · NEEDS SCAFFOLD' : '',
                    $e['last_asked'] ?? 'never',
                    $e['why']
                );
            }
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = json_encode($due['entries'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $lines[] = '```';
            $lines[] = 'Log the outcomes with tracker_log_practice (source retrieval_warmup, retrieval_mixed or '
                . 'retrieval_subject; block_key from tracker_today), one item per entry: topic_ref always, '
                . 'and key as item_key where grain is item. The "why" is safe to quote in evidence.';
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_list_subjects':
            $subjects = $store->listSubjects();
            if (!$subjects) {
                return mcp_text('No subjects yet. Create one with tracker_create_subject (slug, name, strands, topics).');
            }
            $rows = [];
            foreach ($subjects as $s) {
                $p      = progressFor($store, $s['slug']);
                $rows[] = '| ' . $s['slug'] . ' | ' . $s['name'] . ' | ' . ($s['spec_code'] ?? '—')
                    . ' | ' . ($s['tier'] ?? '—') . ' | ' . ($s['exam_date'] ?? '—')
                    . ' | ' . count($p['topics']) . ' | ' . $p['pct'] . '% |';
            }
            return mcp_text("| Slug | Subject | Spec | Tier | Exam | Topics | Covered |\n|---|---|---|---|---|---|---|\n"
                . implode("\n", $rows));

        case 'tracker_get_state': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s      = $r['subject'];
            $filter = [];
            if (!empty($a['strand'])) {
                $filter['strand'] = (string) $a['strand'];
            }
            if (!empty($a['status']) && is_array($a['status'])) {
                foreach ($a['status'] as $st) {
                    if (!in_array($st, STATUS_ORDER, true)) {
                        throw new McpError('status entries must be one of: ' . implode(', ', STATUS_ORDER) . '.');
                    }
                }
                $filter['status'] = $a['status'];
            }
            $ref  = mcp_str($a, 'ref', false, 1, 40);
            $rows = $store->listTopics($slug, $filter);
            if ($ref !== null) {
                $rows = array_values(array_filter($rows, static fn(array $t): bool => (string) $t['ref'] === $ref));
            }
            if (!$rows) {
                return mcp_text($ref !== null ? "No topic \"$ref\" in $slug." : 'No topics match that filter.');
            }
            $p       = progressFor($store, $slug);
            $summary = [];
            foreach ($p['counts'] as $k => $n) {
                $summary[] = (STATUS_LABEL[$k] ?? $k) . ": $n";
            }
            $head = '**' . $s['name'] . '** (' . ($s['spec_code'] ?? 'no spec code')
                . ($s['tier'] ? ', ' . $s['tier'] : '') . ') — ' . $p['pct'] . '% of the specification covered.';
            $tail = '';
            if ($ref !== null) {
                // The error-type tally the lesson reviews have built for the
                // topic: "three of the last four errors were procedure".
                $tally = $store->errorTally($slug, $ref);
                $tail  = "\n\nErrors recorded by lesson reviews on $ref: " . ($tally
                    ? implode(', ', array_map(static fn($k, $n) => "$k $n", array_keys($tally), $tally))
                        . ' — tracker_history(ref) lists them.'
                    : 'none yet.');
            }
            return mcp_text($head . "\n" . implode(' · ', $summary) . "\n\n" . TOPIC_HEADER . "\n"
                . implode("\n", array_map('mcp_topic_line', $rows)) . $tail);
        }

        case 'tracker_review_queue': {
            $slug = mcp_str($a, 'subject', true, 1);
            $weeks = (int) mcp_num($a, 'ageing_weeks', false, 1, 52, 8);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s = $r['subject'];

            // The selection lives in the store, so the queue in chat and the
            // queue line on the week page are one decision made once.
            $queue  = $store->reviewQueue($slug, $weeks);
            $ageing = $queue['ageing'];
            $loose  = $queue['loose'];
            $gaps   = $queue['gaps'];

            $parts = ['**Review queue — ' . $s['name'] . '**'];

            // Continuity first: the plan the last session left, verbatim,
            // and then whatever was stopped before the end. Both come before
            // the three groups because both name work already begun.
            $parts[] = "\n" . mcp_last_session_text($store, $slug);
            // The last review's planner, things to watch, open signals and
            // errors: what the review decided, in the call the tutor already
            // makes, so no skill has to know reviews exist to benefit.
            $reviewLines = mcp_last_review_lines($store, $slug);
            if ($reviewLines) {
                $parts[] = "\n" . implode("\n", $reviewLines);
            }
            if ($queue['unfinished']) {
                $parts[] = "\n### UNFINISHED (open — finish these before starting something new)\n"
                    . implode("\n", mcp_unfinished_lines($queue['unfinished']))
                    . "\nWhen one is completed, close it: tracker_log_session with resolves: [session_id].";
            } else {
                $parts[] = "\n### Unfinished\nNothing open.";
            }

            // Resources are indented under the topic they belong to, so the
            // queue alone is enough to plan a session without a second call.
            $withResources = static function (string $line, string $ref) use ($store, $slug): string {
                $res = $store->listResources($slug, $ref);
                if (!$res) {
                    return $line;
                }
                return $line . "\n" . implode("\n", array_map(
                    static fn($r) => '  ' . mcp_resource_line($r),
                    $res
                ));
            };

            if ($ageing) {
                $lines = array_map(
                    static fn($x) => $withResources(
                        '- **' . $x['topic']['ref'] . '** ' . $x['topic']['name'] . ' — '
                            . ($x['weeks'] === null ? 'no date recorded' : $x['weeks'] . ' weeks since last touched'),
                        $x['topic']['ref']
                    ),
                    $ageing
                );
                $parts[] = "\n### Ageing ({$weeks}+ weeks, put in a starter)\n" . implode("\n", $lines);
            } else {
                $parts[] = "\n### Ageing\nNothing overdue.";
            }

            if ($loose) {
                $lines = array_map(
                    static fn($t) => '- **' . $t['ref'] . '** ' . $t['name'] . ' — ' . $t['watch'],
                    $loose
                );
                $parts[] = "\n### Loose ends on secure topics\n" . implode("\n", $lines);
            } else {
                $parts[] = "\n### Loose ends\nNone recorded.";
            }

            if ($gaps) {
                $lines = array_map(
                    static fn($t) => $withResources(
                        '- **' . $t['ref'] . '** ' . $t['name'] . ' (' . $t['strand'] . ', tier ' . $t['tier'] . ')',
                        $t['ref']
                    ),
                    $gaps
                );
                $parts[] = "\n### Priority gaps (lower tier first)\n" . implode("\n", $lines);
            } else {
                $parts[] = "\n### Priority gaps\nNone — no topic is marked as a gap.";
            }

            $general = $store->listResources($slug, '');
            if ($general) {
                $parts[] = "\n### Resources for the whole subject\n"
                    . implode("\n", mcp_resource_group($slug, $general));
            }

            $last = $store->lastSession($slug);
            $parts[] = $last
                ? "\nLast session logged: " . $last['date'] . ' — ' . $last['summary']
                    . ($last['next_steps'] ? "\nPlanned next: " . $last['next_steps'] : '')
                : "\nNo sessions logged yet.";

            return mcp_text(implode("\n", $parts));
        }

        case 'tracker_list_attempts': {
            $slug  = mcp_str($a, 'subject', true, 1);
            $limit = (int) mcp_num($a, 'limit', false, 1, 100, 20);
            $r     = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s    = $r['subject'];
            $rows = $store->listAttempts($slug, $limit);
            if (!$rows) {
                return mcp_text('No attempts logged for this subject yet. Record one with tracker_log_attempt.');
            }
            $out = ['| # | Date | Attempt | Kind | Tier | Papers | Score | Outcome | Blanks |',
                    '|---|---|---|---|---|---|---|---|---|'];
            foreach ($rows as $x) {
                $outcome = mcp_attempt_outcome($s, $x);
                $out[]   = '| ' . $x['id'] . ' | ' . $x['date'] . ' | ' . $x['name'] . ' | ' . $x['kind']
                    . ' | ' . $x['tier'] . ' | ' . count($x['papers'])
                    . ' | ' . num($x['score']) . '/' . num($x['max'])
                    . ' | ' . $outcome . ' | ' . ($x['blanks'] ?? '—') . ' |';
            }
            $out[] = '';
            $out[] = 'Call tracker_get_attempt with one of the # ids for the question-by-question breakdown.';
            return mcp_text(implode("\n", $out));
        }

        case 'tracker_get_attempt': {
            $slug = mcp_str($a, 'subject', true, 1);
            $id   = (int) mcp_num($a, 'attempt_id', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s = $r['subject'];
            $x = $store->getAttempt($slug, $id);
            if (!$x) {
                return mcp_text("No attempt $id in $slug. Call tracker_list_attempts to see the ids.");
            }

            $parts = [
                '**' . $x['name'] . '** — ' . $x['date'] . ', tier ' . $x['tier'] . ', ' . $x['kind'],
                'Total ' . num($x['score']) . '/' . num($x['max']) . ' — ' . mcp_attempt_outcome($s, $x)
                    . ($x['blanks'] !== null ? ' · ' . (int) $x['blanks'] . ' blank' . ((int) $x['blanks'] === 1 ? '' : 's') : ''),
            ];
            if ($x['note']) {
                $parts[] = $x['note'];
            }

            foreach ($x['papers'] as $paper) {
                $head = "\n### " . $paper['code'] . ' — ' . num($paper['score']) . '/' . num($paper['max']);
                if (!empty($paper['sat_on'])) {
                    $head .= ' · sat ' . $paper['sat_on'];
                }
                if ($paper['blanks'] !== null) {
                    $head .= ' · ' . (int) $paper['blanks'] . ' blank' . ((int) $paper['blanks'] === 1 ? '' : 's');
                }
                $parts[] = $head;
                if ($paper['note']) {
                    $parts[] = $paper['note'];
                }
                if ($paper['questions']) {
                    $parts[] = '| Q | Topic | Marks | Answer given | Note |';
                    $parts[] = '|---|---|---|---|---|';
                    foreach ($paper['questions'] as $q) {
                        $parts[] = '| ' . $q['number']
                            . ' | ' . ($q['topic_ref'] ?? '—')
                            . ' | ' . num($q['score']) . '/' . num($q['max'])
                            . ' | ' . str_replace('|', '/', (string) ($q['answer'] ?? ''))
                            . ' | ' . str_replace('|', '/', (string) ($q['note'] ?? '')) . ' |';
                    }
                } else {
                    $parts[] = '_No question breakdown recorded for this paper._';
                }
            }

            $breakdown = $store->attemptTopicBreakdown($slug, $id);
            if ($breakdown) {
                $parts[] = "\n### Marks by topic";
                $parts[] = '| Topic | Name | Marks | Lost |';
                $parts[] = '|---|---|---|---|';
                foreach ($breakdown as $b) {
                    $lost = (float) $b['max'] - (float) $b['score'];
                    $parts[] = '| ' . $b['ref'] . ' | ' . $b['name']
                        . ' | ' . num($b['score']) . '/' . num($b['max'])
                        . ' | ' . num($lost) . ' |';
                }
                $parts[] = '';
                $parts[] = 'Topics at the top lost the most marks — treat those as the candidates for reteaching.';
            }
            return mcp_text(implode("\n", $parts));
        }

        case 'tracker_history': {
            $slug  = mcp_str($a, 'subject', true, 1);
            $weeks = (int) mcp_num($a, 'weeks', false, 1, 260, 12);
            $ref   = mcp_str($a, 'ref', false, 0, 40);
            $r     = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $h = $store->history($slug, $weeks, $ref);
            $reviews = $store->lessonReviewsForSessions(array_map(static fn($x) => (int) $x['id'], $h['sessions']));

            // Everything is bucketed by ISO week so the trail reads as a
            // timeline rather than a flat list of rows.
            $buckets = [];
            foreach ($h['sessions'] as $x) {
                $w = Store::weekOf($x['date']);
                $buckets[$w['label']]['monday']     = $w['monday'];
                $buckets[$w['label']]['sessions'][] = $x;
            }
            foreach ($h['changes'] as $c) {
                $w = Store::weekOf($c['changed_at']);
                $buckets[$w['label']]['monday']    = $w['monday'];
                $buckets[$w['label']]['changes'][] = $c;
            }
            if (!$buckets) {
                return mcp_text($ref !== null
                    ? "Nothing recorded for $ref in " . $r['subject']['name'] . " in the last $weeks weeks."
                    : 'Nothing recorded for ' . $r['subject']['name'] . " in the last $weeks weeks.");
            }
            krsort($buckets);

            $parts = ['**History — ' . $r['subject']['name'] . '**'
                . ($ref !== null ? " · topic $ref" : '') . " · last $weeks weeks"];

            foreach ($buckets as $label => $b) {
                $parts[] = "\n### $label (week of " . ($b['monday'] ?? '?') . ')';

                foreach ($b['sessions'] ?? [] as $x) {
                    $void = $x['void_reason'] ?? null;
                    $rv   = $reviews[(int) $x['id']] ?? null;
                    $tag  = $rv ? ' [review v' . $rv['version'] . ' ' . $rv['stage'] . ']'
                        : ((int) ($x['review_required'] ?? 0) === 1 ? ' [review MISSING]' : '');
                    $parts[] = '- **Session ' . $x['id'] . '** ' . $x['date'] . $tag
                        . ($void ? ' — VOID: ' . $void : '') . ' — ' . $x['summary'];
                    if ($x['next_steps']) {
                        $parts[] = '  - Planned next: ' . $x['next_steps'];
                    }
                    if (($x['unfinished'] ?? null) !== null) {
                        $parts[] = '  - ' . mcp_unfinished_status($x);
                    }
                    foreach ($store->sessionsClosedBy((int) $x['id']) as $closed) {
                        $parts[] = '  - Resolved session ' . $closed['id'] . '\'s unfinished work ('
                            . $closed['date'] . '): ' . $closed['unfinished'];
                    }
                }

                foreach ($b['changes'] ?? [] as $c) {
                    $from = $c['from_status'] ? (STATUS_LABEL[$c['from_status']] ?? $c['from_status']) : '—';
                    $to   = STATUS_LABEL[$c['to_status']] ?? $c['to_status'];
                    $name = $c['topic_name'] ?? '';
                    $parts[] = '- ' . $c['ref'] . ($name ? ' ' . $name : '') . ': ' . $from . ' → ' . $to
                        . ($c['session_id'] ? ' (session ' . $c['session_id'] . ')' : ' (standalone update)')
                        . "\n  - " . $c['evidence'];
                }
            }
            // In ref mode, the error history the reviews have built for the
            // topic: what kind of error it keeps being, newest first.
            if ($ref !== null) {
                $errs = $store->reviewErrorsForRef($slug, $ref, 20);
                if ($errs) {
                    $tally = $store->errorTally($slug, $ref);
                    $parts[] = "\n### Errors recorded by lesson reviews on $ref — "
                        . implode(', ', array_map(static fn($k, $n) => "$k $n", array_keys($tally), $tally));
                    foreach ($errs as $e) {
                        $parts[] = '- ' . $e['date'] . ' (session ' . $e['session_id'] . ') ' . $e['error_type'] . ' — '
                            . $e['what'] . ' → ' . $e['response'];
                    }
                }
            }
            $parts[] = '';
            $parts[] = 'To correct a session use tracker_amend_session; to correct a topic status call '
                . 'tracker_update_topic with evidence saying it is a correction, which appends to this trail '
                . 'rather than rewriting it.';
            return mcp_text(implode("\n", $parts));
        }

        case 'tracker_export_markdown': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s     = $r['subject'];
            $p     = progressFor($store, $slug);
            $today = gmdate('Y-m-d');
            $parts = [
                '# ' . $s['name'] . ' — topic state',
                "*Generated $today from the tracker database. " . $p['pct'] . '% of the specification covered.*',
                '',
            ];
            foreach ($s['strands'] as $key => $label) {
                $rows = array_values(array_filter($p['topics'], static fn($t) => $t['strand'] === $key));
                if (!$rows) {
                    continue;
                }
                $parts[] = "## $label";
                $parts[] = '';
                $parts[] = TOPIC_HEADER;
                $parts[] = implode("\n", array_map('mcp_topic_line', $rows));
                $parts[] = '';
            }
            $resources = $store->listResources($slug);
            if ($resources) {
                $parts[] = '## Resources';
                $parts[] = '';
                $general = array_values(array_filter($resources, static fn($r) => $r['ref'] === ''));
                $perTopic = array_values(array_filter($resources, static fn($r) => $r['ref'] !== ''));
                if ($general) {
                    $parts[] = '### For the whole subject';
                    $parts[] = implode("\n", array_map(static fn($r) => mcp_resource_line($r), $general));
                    $parts[] = '';
                }
                if ($perTopic) {
                    $parts[] = '### By topic';
                    $parts[] = implode("\n", array_map(static fn($r) => mcp_resource_line($r, true), $perTopic));
                    $parts[] = '';
                }
            }

            $attempts = $store->listAttempts($slug, 100);
            if ($attempts) {
                $parts[] = '## Attempts';
                $parts[] = '';
                $parts[] = '| Date | Attempt | Papers | Score | Outcome |';
                $parts[] = '|---|---|---|---|---|';
                foreach ($attempts as $x) {
                    $codes = implode(', ', array_column($x['papers'], 'code'));
                    $parts[] = '| ' . $x['date'] . ' | ' . $x['name'] . ' | ' . $codes
                        . ' | ' . num($x['score']) . '/' . num($x['max'])
                        . ' | ' . mcp_attempt_outcome($s, $x) . ' |';
                }
                $parts[] = '';
            }

            $sessions = $store->listSessions($slug, 50);
            $sessions = array_values(array_filter($sessions, static fn($x) => empty($x['void_reason'])));
            if ($sessions) {
                $parts[] = '## Session log';
                $parts[] = '';
                foreach ($sessions as $x) {
                    $w = Store::weekOf($x['date']);
                    $parts[] = '- **' . $x['date'] . '** (' . $w['label'] . ') — ' . $x['summary'];
                    $changes = $store->changesForSession((int) $x['id']);
                    foreach ($changes as $c) {
                        $from = $c['from_status'] ? (STATUS_LABEL[$c['from_status']] ?? $c['from_status']) : '—';
                        $to   = STATUS_LABEL[$c['to_status']] ?? $c['to_status'];
                        $parts[] = '  - ' . $c['ref'] . ': ' . $from . ' → ' . $to . ' — ' . $c['evidence'];
                    }
                }
                $parts[] = '';
            }
            return mcp_text(implode("\n", $parts));
        }

        case 'tracker_update_topic': {
            $slug     = mcp_str($a, 'subject', true, 1);
            $ref      = mcp_str($a, 'ref', true, 1);
            $evidence = mcp_str($a, 'evidence', true, 10, 500);
            $status   = mcp_status($a, 'status', false);
            $when     = mcp_date($a, 'last_touched');
            $r        = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $args = [
                'subject_slug' => $slug,
                'ref'          => $ref,
                'status'       => $status,
                'evidence'     => $evidence,
                'last_touched' => $when,
            ];
            if (array_key_exists('watch', $a)) {
                $args['watch'] = $a['watch'] === null ? null : mcp_str($a, 'watch', false, 0, 500);
            }
            $result = $store->updateTopicStatus($args);
            if (!$result) {
                return mcp_text("No topic \"$ref\" in $slug. Call tracker_get_state to see valid references.");
            }
            $moved = $result['previous'] === $result['current']
                ? 'unchanged at ' . STATUS_LABEL[$result['current']]
                : STATUS_LABEL[$result['previous']] . ' → ' . STATUS_LABEL[$result['current']];
            return mcp_text("$ref updated: $moved. Evidence recorded.");
        }

        case 'tracker_log_session': {
            $slug    = mcp_str($a, 'subject', true, 1);
            $summary = mcp_str($a, 'summary', true, 10, 2000);
            $next    = mcp_str($a, 'next_steps', false, 0, 1000);
            $when    = mcp_date($a, 'date', gmdate('Y-m-d'));
            $r       = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s       = $r['subject'];
            $updates = is_array($a['updates'] ?? null) ? $a['updates'] : [];
            if (count($updates) > 50) {
                throw new McpError('updates may contain at most 50 entries.');
            }
            $applied = [];
            $missing = [];
            $refs    = [];

            // The session row is written first so every change it produces can
            // carry its id. Without that the history can list what changed but
            // not which session did it, which is most of the point.
            // Validated before the row is written: a block_key that names the
            // wrong block would re-label the work, and that is worse than
            // refusing the call.
            $blockKey = isset($a['block_key']) ? (int) mcp_num($a, 'block_key', false, 1) : null;
            if ($blockKey) {
                mcp_check_block($store, $blockKey, $when, $slug);
            }
            $minutes = isset($a['duration_minutes'])
                ? (int) mcp_num($a, 'duration_minutes', false, 1, 600) : null;

            // Unfinished work, and what this session resolves. Both are
            // checked before the row is written: a resolves id that belongs
            // to another subject would close the wrong item.
            $unfinished = mcp_str($a, 'unfinished', false, 1, 300);
            $unfinishedRefs = mcp_ref_list($a['unfinished_refs'] ?? null, 'unfinished_refs', 10);
            $resolves   = mcp_check_resolves($store, $slug, $a['resolves'] ?? null);
            $consolidates = mcp_consolidates($store, $slug, $a['consolidates'] ?? null);
            $knownRefs  = array_column($store->listTopics($slug), 'ref');

            // Updates are validated as a whole before the session row exists,
            // so a malformed update never leaves a session with half its
            // changes applied.
            $cleanUpdates = [];
            foreach ($updates as $u) {
                if (!is_array($u)) {
                    throw new McpError('each update must be an object.');
                }
                $cu = [
                    'ref'      => mcp_str($u, 'ref', true, 1),
                    'evidence' => mcp_str($u, 'evidence', true, 10, 500),
                    'status'   => mcp_status($u, 'status', false),
                    'outcome'  => null,
                ];
                if (array_key_exists('watch', $u)) {
                    $cu['watch'] = $u['watch'] === null ? null : mcp_str($u, 'watch', false, 0, 500);
                }
                if (isset($u['retrieval_outcome']) && $u['retrieval_outcome'] !== '') {
                    if (!in_array($u['retrieval_outcome'], RETRIEVAL_OUTCOMES, true)) {
                        throw new McpError("retrieval_outcome on {$cu['ref']} must be one of: "
                            . implode(', ', RETRIEVAL_OUTCOMES) . '.');
                    }
                    $cu['outcome'] = (string) $u['retrieval_outcome'];
                }
                $cleanUpdates[] = $cu;
            }

            // The review is validated whole before anything is written, against
            // the record as it stands: which refs the updates will move, and
            // which retrieval outcomes they carry. A review that fails names
            // the field and the session is not logged either — never half.
            $review = null;
            $before = [];
            $ctx    = null;
            if (array_key_exists('review', $a) && $a['review'] !== null) {
                $ctx    = mcp_review_context($store, $slug, $cleanUpdates);
                $review = mcp_lesson_sections($a['review'], $ctx);
                foreach ($cleanUpdates as $cu) {
                    $before[$cu['ref']] = $ctx['topics'][$cu['ref']]['status'] ?? null;
                }
            }
            $required = $store->reviewRequiredFor($blockKey ?: null, $when, $minutes);

            $sessionId = 0;
            $scheduled = 0;
            $closed    = [];
            $reviewOut = null;
            // One transaction: the session row, its topic changes, its
            // retrieval outcomes, what it resolves, and its review.
            $store->transaction(function () use (
                $store, $slug, $when, $summary, $next, $blockKey, $minutes, $unfinished, $unfinishedRefs,
                $consolidates, $cleanUpdates, $resolves, $required, $review, $before, $ctx,
                &$sessionId, &$applied, &$missing, &$refs, &$scheduled, &$closed, &$reviewOut
            ): void {
                $sessionId = $store->addSession([
                    'subject_slug'     => $slug,
                    'date'             => $when,
                    'summary'          => $summary,
                    'topics_touched'   => null,
                    'next_steps'       => $next,
                    'block_key'        => $blockKey ?: null,
                    'duration_minutes' => $minutes,
                    'unfinished'       => $unfinished,
                    'unfinished_refs'  => $unfinishedRefs,
                    'consolidates'     => $consolidates['clean'],
                ]);
                $store->setReviewRequired($sessionId, $required['required']);

                foreach ($cleanUpdates as $cu) {
                    $uref = $cu['ref'];
                    $refs[] = $uref;
                    $args = [
                        'subject_slug' => $slug,
                        'ref'          => $uref,
                        'status'       => $cu['status'],
                        'evidence'     => $cu['evidence'],
                        'last_touched' => $when,
                        'session_id'   => $sessionId,
                    ];
                    if (array_key_exists('watch', $cu)) {
                        $args['watch'] = $cu['watch'];
                    }
                    $res = $store->updateTopicStatus($args);
                    if (!$res) {
                        $missing[] = $uref;
                        continue;
                    }
                    $applied[] = $res['previous'] === $res['current']
                        ? "$uref (still " . STATUS_LABEL[$res['current']] . ')'
                        : "$uref: " . STATUS_LABEL[$res['previous']] . ' → ' . STATUS_LABEL[$res['current']];

                    // Retrieval evidence at topic grain. An explicit outcome
                    // wins; otherwise only a rise or a fall says anything.
                    $outcome = $cu['outcome'] ?? retrieval_infer_outcome($res['previous'], $res['current']);
                    if ($outcome !== null) {
                        retrieval_apply($store, $slug, 'topic', $uref, $uref, null, $outcome, $when);
                        $scheduled++;
                    }
                    if (retrieval_infer_outcome($res['previous'], $res['current']) === 'incorrect') {
                        // A demotion brings retired items on the topic back.
                        retrieval_unretire_topic($store, $slug, $uref);
                    }
                }

                if ($refs) {
                    $store->setSessionTopics($sessionId, implode(', ', $refs));
                }

                foreach ($resolves['close'] as $id) {
                    if ($store->closeUnfinished($id, $sessionId, "completed in session $sessionId")) {
                        $closed[] = $id;
                    }
                }

                if ($review !== null) {
                    $reviewOut = mcp_apply_review(
                        $store, $slug, $sessionId, $review, 'draft', 'session', null, $before, $ctx['outcomes']
                    );
                }
            });

            $lines = ["Session $sessionId logged for " . $s['name'] . " on $when."];
            if ($applied) {
                $lines[] = 'Updated: ' . implode('; ', $applied) . '.';
            }
            if ($missing) {
                $lines[] = 'Not found, so not updated: ' . implode(', ', $missing)
                    . '. Check the references with tracker_get_state.';
            }
            if ($unfinished !== null) {
                $lines[] = "Unfinished work recorded against session $sessionId: '$unfinished'. It leads "
                    . 'tracker_review_queue until a later session passes resolves: [' . $sessionId . '].';
                $unknownU = array_diff($unfinishedRefs, $knownRefs);
                if ($unknownU) {
                    $lines[] = 'Note: no topic with reference ' . implode(', ', $unknownU)
                        . ' exists in this subject; unfinished_refs is stored as sent.';
                }
            }
            if ($closed) {
                $lines[] = 'Resolved: unfinished work from session' . (count($closed) === 1 ? ' ' : 's ')
                    . implode(', ', $closed) . ' is now closed against this session.';
            }
            foreach ($resolves['already'] as $note) {
                $lines[] = 'Note: ' . $note . ' — resolving it again is a no-op.';
            }
            if ($consolidates['clean']) {
                $lines[] = 'Consolidated: ' . implode(', ', array_map(
                    static fn($c) => $c['ref'] . (isset($c['error_session_id']) ? ' (session ' . $c['error_session_id'] . ')' : ''),
                    $consolidates['clean']
                )) . '.';
            }
            if ($consolidates['unknown']) {
                $lines[] = 'Note: consolidates names ' . implode(', ', $consolidates['unknown'])
                    . ', which is not a topic in this subject. Stored as sent; check it with tracker_get_state.';
            }
            if ($scheduled) {
                $lines[] = "Retrieval schedule updated for $scheduled topic" . ($scheduled === 1 ? '' : 's') . '.';
            }
            if ($reviewOut !== null) {
                $row = $reviewOut['row'];
                $lines[] = "Lesson review saved: version {$row['version']} ({$row['stage']}), readiness "
                    . ($review['big_picture']['readiness'] ?? '?') . '. Read it with tracker_get_lesson_review or at '
                    . "/s/$slug/session/$sessionId.";
                foreach ($reviewOut['lines'] as $l) {
                    $lines[] = $l;
                }
                $proposals = array_values(array_filter($review['progress'], static fn(array $p): bool => isset($p['proposed_status'])));
                if ($proposals) {
                    $lines[] = 'Proposed for adjudication (not applied): ' . implode('; ', array_map(
                        static fn(array $p): string => $p['ref'] . ' → ' . $p['proposed_status'], $proposals
                    )) . '. gcse-progress-tracker decides against the promotion bar.';
                }
            }
            if ($unfinished === null && !$resolves['close'] && !$resolves['already']) {
                $warning = mcp_unfinished_warning($store, $slug, $sessionId);
                if ($warning !== null) {
                    $lines[] = '';
                    $lines[] = $warning;
                }
            }
            if ($required['required'] && $reviewOut === null) {
                $lines[] = '';
                $lines[] = 'REVIEW REQUIRED and not written — the session is logged, the block is done, and this will '
                    . 'appear in the audit queue until a review is saved with tracker_save_lesson_review '
                    . "(subject: \"$slug\", session_id: $sessionId). Reason: {$required['reason']}.";
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_amend_session': {
            $slug = mcp_str($a, 'subject', true, 1);
            $id   = (int) mcp_num($a, 'session_id', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $existing = $store->getSession($slug, $id);
            if (!$existing) {
                return mcp_text("No session $id in $slug. Call tracker_history to see the ids.");
            }
            $fields = [];
            if (array_key_exists('date', $a)) {
                $fields['date'] = mcp_date($a, 'date');
            }
            if (array_key_exists('summary', $a)) {
                $fields['summary'] = mcp_str($a, 'summary', true, 10, 2000);
            }
            if (array_key_exists('next_steps', $a)) {
                $fields['next_steps'] = $a['next_steps'] === null ? null : mcp_str($a, 'next_steps', false, 0, 1000);
            }
            if (array_key_exists('void_reason', $a)) {
                $fields['void_reason'] = $a['void_reason'] === null ? null : mcp_str($a, 'void_reason', false, 0, 500);
            }
            $what = [];
            if (array_key_exists('unfinished', $a)) {
                if ($a['unfinished'] === null) {
                    // Closing by hand. The text stays and the closure says
                    // who did it, so the row still shows the item existed.
                    if ($existing['unfinished'] !== null && $existing['unfinished_closed_at'] === null) {
                        $fields['unfinished_closed_at']     = tt_now_utc();
                        $fields['unfinished_closed_reason'] = 'cleared by hand (tracker_amend_session)';
                        $what[] = 'unfinished item closed by hand';
                    } else {
                        $what[] = 'no open unfinished item to clear';
                    }
                } else {
                    $fields['unfinished'] = mcp_str($a, 'unfinished', true, 1, 300);
                    // Setting text re-opens: a corrected item is an open one.
                    $fields['unfinished_closed_at']            = null;
                    $fields['unfinished_closed_by_session_id'] = null;
                    $fields['unfinished_closed_reason']        = null;
                    $what[] = 'unfinished set';
                }
            }
            if (array_key_exists('unfinished_refs', $a)) {
                $list = mcp_ref_list($a['unfinished_refs'], 'unfinished_refs', 10);
                $fields['unfinished_refs'] = $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null;
                $what[] = 'unfinished_refs updated';
            }
            $resolves = mcp_check_resolves($store, $slug, $a['resolves'] ?? null);
            foreach ($resolves['close'] as $target) {
                if ($target === $id) {
                    throw new McpError("resolves names session $id itself. A session cannot resolve its own "
                        . 'unfinished work; pass unfinished: null to clear it by hand. Nothing was written.');
                }
            }
            if (!$fields && !$resolves['close'] && !$resolves['already']) {
                throw new McpError('Give at least one of date, summary, next_steps, void_reason, unfinished, '
                    . 'unfinished_refs or resolves to change.');
            }
            if ($fields) {
                $store->amendSession($slug, $id, $fields);
            }
            foreach ($fields as $k => $v) {
                if (in_array($k, ['date', 'summary', 'next_steps'], true)) {
                    $what[] = "$k updated";
                } elseif ($k === 'void_reason') {
                    $what[] = $v === null ? 'un-voided' : 'voided (' . $v . ')';
                }
            }
            foreach ($resolves['close'] as $target) {
                if ($store->closeUnfinished($target, $id, "completed in session $id")) {
                    $what[] = "resolved session $target's unfinished work";
                }
            }
            foreach ($resolves['already'] as $note) {
                $what[] = $note . ' (no-op)';
            }
            return mcp_text("Session $id amended: " . implode('; ', $what) . '.');
        }

        case 'tracker_log_attempt': {
            $slug = mcp_str($a, 'subject', true, 1);
            $name = mcp_str($a, 'name', true, 1, 200);
            $kind = $a['kind'] ?? 'paper';
            if (!in_array($kind, ['paper', 'check'], true)) {
                throw new McpError("kind must be 'paper' or 'check'.");
            }
            $tier = mcp_str($a, 'tier', false, 0, 4, 'F');
            $when = mcp_date($a, 'date', gmdate('Y-m-d'));
            $note = mcp_str($a, 'note', false, 0, 1000);

            $r = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s = $r['subject'];

            $papers = is_array($a['papers'] ?? null) ? $a['papers'] : [];
            if (!$papers) {
                throw new McpError('papers must be a non-empty array of { code, score, max }.');
            }
            if (count($papers) > 10) {
                throw new McpError('an attempt may hold at most 10 papers.');
            }

            $known   = array_column($store->listTopics($slug), 'ref');
            $unknown = [];
            $clean   = [];

            foreach (array_values($papers) as $paper) {
                if (!is_array($paper)) {
                    throw new McpError('each paper must be an object.');
                }
                $code  = mcp_str($paper, 'code', true, 1, 60);
                $score = mcp_num($paper, 'score', true, 0);
                $max   = mcp_num($paper, 'max', true, 1);
                if ($score > $max) {
                    return mcp_text("Paper $code scores " . num($score) . ' out of ' . num($max)
                        . '. Check the figures.');
                }

                $questions = is_array($paper['questions'] ?? null) ? $paper['questions'] : [];
                if (count($questions) > 200) {
                    throw new McpError("paper $code may hold at most 200 questions.");
                }
                $qClean = [];
                $qTotal = 0.0;
                $qMax   = 0.0;
                foreach (array_values($questions) as $q) {
                    if (!is_array($q)) {
                        throw new McpError('each question must be an object.');
                    }
                    $qNumber = mcp_str($q, 'number', true, 1, 20);
                    $qScore  = mcp_num($q, 'score', true, 0);
                    $qMaxOne = mcp_num($q, 'max', true, 0);
                    if ($qScore > $qMaxOne) {
                        return mcp_text("Question $qNumber on $code scores " . num($qScore)
                            . ' out of ' . num($qMaxOne) . '. Check the figures.');
                    }
                    $topicRef = mcp_str($q, 'topic_ref', false, 0, 40);
                    if ($topicRef !== null && !in_array($topicRef, $known, true)) {
                        $unknown[] = $topicRef;
                    }
                    $qTotal += $qScore;
                    $qMax   += $qMaxOne;
                    $qClean[] = [
                        'number'    => $qNumber,
                        'score'     => $qScore,
                        'max'       => $qMaxOne,
                        'topic_ref' => $topicRef,
                        'question'  => mcp_str($q, 'question', false, 0, 1000),
                        'answer'    => mcp_str($q, 'answer', false, 0, 1000),
                        'note'      => mcp_str($q, 'note', false, 0, 500),
                    ];
                }

                // A question breakdown that does not add up to the paper total
                // means one of the two is wrong, and silently keeping both
                // would make the per-topic analysis lie.
                if ($qClean && (abs($qTotal - $score) > 0.001 || abs($qMax - $max) > 0.001)) {
                    return mcp_text("The questions on $code add up to " . num($qTotal) . '/' . num($qMax)
                        . ', but the paper is recorded as ' . num($score) . '/' . num($max)
                        . '. Fix whichever is wrong — a breakdown that does not reconcile would make the'
                        . ' per-topic analysis wrong.');
                }

                $clean[] = [
                    'code'      => $code,
                    'score'     => $score,
                    'max'       => $max,
                    'blanks'    => array_key_exists('blanks', $paper) && $paper['blanks'] !== null
                        ? (int) mcp_num($paper, 'blanks', false, 0) : null,
                    'note'      => mcp_str($paper, 'note', false, 0, 1000),
                    'sat_on'    => mcp_date($paper, 'sat_on', null),
                    'block_key' => isset($paper['block_key'])
                        ? (int) mcp_num($paper, 'block_key', false, 1) : null,
                    'questions' => $qClean,
                ];
                // The paper's own date is what the block is judged on, so that
                // is the date the key has to be valid for.
                if ($clean[count($clean) - 1]['block_key']) {
                    mcp_check_block(
                        $store,
                        $clean[count($clean) - 1]['block_key'],
                        $clean[count($clean) - 1]['sat_on'] ?? $when,
                        $slug
                    );
                }
            }

            $id = $store->addAttempt([
                'subject_slug' => $slug,
                'date'         => $when,
                'name'         => $name,
                'kind'         => $kind,
                'tier'         => $tier,
                'note'         => $note,
                'papers'       => $clean,
            ]);

            $total   = array_sum(array_column($clean, 'score'));
            $maxAll  = array_sum(array_column($clean, 'max'));
            $outcome = $kind === 'check'
                ? round(($total / max($maxAll, 1)) * 100) . '% — a check, so not grade-converted'
                : '≈ grade ' . gradeFor($s, $total, $maxAll, $tier) . " on tier $tier";
            $nQ = array_sum(array_map(static fn($p) => count($p['questions']), $clean));

            $lines = ["Logged $name ($when) as attempt $id: " . count($clean) . ' paper'
                . (count($clean) === 1 ? '' : 's') . ', ' . num($total) . '/' . num($maxAll) . ", $outcome."];
            $lines[] = $nQ
                ? "$nQ questions recorded — call tracker_get_attempt with id $id for the per-topic breakdown."
                : 'No question breakdown recorded. Adding one lets the tracker say which topics lost the marks.';
            if ($unknown) {
                $lines[] = 'Note: no topic with reference ' . implode(', ', array_unique($unknown))
                    . ' exists in this subject, so those questions will not appear in the per-topic breakdown.'
                    . ' Check the references with tracker_get_state.';
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_list_resources': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $ref = mcp_str($a, 'ref', false, 0, 40);

            // A topic lookup includes the subject-wide materials, because those
            // apply to it too and a caller asking "what do we use for A17"
            // wants the textbook as well as the Bitesize page.
            $rows = $ref === null
                ? $store->listResources($slug)
                : $store->resourcesForTopic($slug, $ref);

            if (!$rows) {
                return mcp_text($ref === null
                    ? "No resources stored for $slug yet. Add some with tracker_add_resource."
                    : "No resources for $ref, and none stored for $slug as a whole. Add some with tracker_add_resource.");
            }
            $head = $ref === null
                ? '**Resources — ' . $r['subject']['name'] . '**'
                : '**Resources for ' . $ref . '** (including subject-wide materials)';
            return mcp_text($head . "\n" . implode("\n", array_map(
                static fn($x) => mcp_resource_line($x, $ref === null),
                $rows
            )));
        }

        case 'tracker_add_resource': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $items = is_array($a['resources'] ?? null) ? $a['resources'] : [];
            if (!$items) {
                throw new McpError('resources must be a non-empty array of { title, ref?, url?, kind?, note? }.');
            }
            if (count($items) > 200) {
                throw new McpError('resources may contain at most 200 entries.');
            }

            $added   = [];
            $unknown = [];
            $existing = array_column($store->listTopics($slug), 'ref');

            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) {
                    throw new McpError('each resource must be an object.');
                }
                $title = mcp_str($item, 'title', true, 1, 200);
                $ref   = mcp_str($item, 'ref', false, 0, 40, '');
                $kind  = $item['kind'] ?? 'other';
                if (!in_array($kind, RESOURCE_KINDS, true)) {
                    throw new McpError('kind must be one of: ' . implode(', ', RESOURCE_KINDS) . '.');
                }
                // An unknown ref is reported rather than refused: the resource
                // is still stored, so a typo costs a correction, not the work.
                if ($ref !== '' && !in_array($ref, $existing, true)) {
                    $unknown[] = $ref;
                }
                $store->upsertResource([
                    'subject_slug' => $slug,
                    'ref'          => $ref,
                    'title'        => $title,
                    'url'          => mcp_str($item, 'url', false, 0, 500),
                    'kind'         => $kind,
                    'note'         => mcp_str($item, 'note', false, 0, 500),
                    'sort_order'   => $i,
                ]);
                $added[] = $ref === '' ? $title : "$title → $ref";
            }

            $lines = ['Stored ' . count($added) . ' resource' . (count($added) === 1 ? '' : 's')
                . ' for ' . $r['subject']['name'] . ': ' . implode('; ', $added) . '.'];
            if ($unknown) {
                $lines[] = 'Note: no topic with reference ' . implode(', ', array_unique($unknown))
                    . ' exists in this subject. The resource is stored, but it will not appear against a topic'
                    . ' until the reference matches — check it with tracker_get_state.';
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_remove_resource': {
            $slug  = mcp_str($a, 'subject', true, 1);
            $title = mcp_str($a, 'title', true, 1, 200);
            $ref   = mcp_str($a, 'ref', false, 0, 40, '');
            $r     = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $gone = $store->deleteResource($slug, $ref, $title);
            if (!$gone) {
                $where = $ref === '' ? 'the subject-wide resources' : "topic $ref";
                return mcp_text("No resource titled \"$title\" against $where in $slug. "
                    . 'Call tracker_list_resources to see the exact titles.');
            }
            return mcp_text("Removed \"$title\"" . ($ref === '' ? '' : " from $ref") . '.');
        }

        case 'tracker_create_subject': {
            $slug = mcp_str($a, 'slug', true, 1, 60);
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                throw new McpError('slug must use lowercase letters, numbers and hyphens only.');
            }
            $existing = $store->getSubject($slug);

            // Creating needs the whole picture. Amending one that exists does
            // not: a wrong exam date should be one call, not a re-send of a
            // hundred topics that only risks disturbing them.
            if (!$existing) {
                if (!is_array($a['strands'] ?? null) || !$a['strands']) {
                    throw new McpError('strands must be an object of strand key to display name.');
                }
                if (!is_array($a['topics'] ?? null) || !$a['topics']) {
                    throw new McpError('topics must be a non-empty array.');
                }
            }
            $name = mcp_str($a, 'name', !$existing, 1, 120);
            if (is_array($a['topics'] ?? null) && count($a['topics']) > 400) {
                throw new McpError('topics may contain at most 400 entries.');
            }
            $before = $existing ? count($store->listTopics($slug)) : 0;

            // Only the keys actually supplied are passed on, so everything
            // else keeps the value it already had.
            $fields = ['slug' => $slug];
            if ($name !== null) {
                $fields['name'] = $name;
            }
            foreach (['spec_code' => 60, 'tier' => 30, 'notes' => 2000] as $key => $max) {
                if (array_key_exists($key, $a)) {
                    $fields[$key] = mcp_str($a, $key, false, 0, $max);
                }
            }
            if (array_key_exists('exam_date', $a)) {
                $fields['exam_date'] = mcp_date($a, 'exam_date');
            }
            if (is_array($a['strands'] ?? null)) {
                $fields['strands'] = $a['strands'];
            }
            if (is_array($a['boundaries'] ?? null)) {
                $fields['boundaries'] = $a['boundaries'];
            }
            if (array_key_exists('boundary_max', $a)) {
                $fields['boundary_max'] = (int) mcp_num($a, 'boundary_max', false, 1);
            }
            $store->upsertSubject($fields);

            foreach (array_values($a['topics'] ?? []) as $i => $t) {
                if (!is_array($t)) {
                    throw new McpError('each topic must be an object.');
                }
                $store->upsertTopic([
                    'subject_slug' => $slug,
                    'ref'          => mcp_str($t, 'ref', true, 1),
                    'name'         => mcp_str($t, 'name', true, 1),
                    'strand'       => mcp_str($t, 'strand', true, 1),
                    'tier'         => mcp_str($t, 'tier', false, 0, 4, 'F'),
                    'status'       => mcp_status($t, 'status', false, 'notstarted'),
                    'watch'        => mcp_str($t, 'watch', false, 0, 500),
                    'sort_order'   => $i,
                ]);
            }

            $after   = count($store->listTopics($slug));
            $subject = $store->getSubject($slug);
            if (!$existing) {
                return mcp_text("Created {$subject['name']} with $after topics. Dashboard: /s/$slug");
            }
            $line = "Updated {$subject['name']}.";
            if ($after !== $before) {
                $line .= " Topics: $before → $after (" . ($after - $before)
                    . ' added; existing statuses untouched).';
            } elseif (!isset($a['topics'])) {
                $line .= " No topics were sent, so all $after are untouched.";
            } else {
                $line .= " Topics: $after, all already present and untouched.";
            }
            if (array_key_exists('exam_date', $a)) {
                $line .= ' Exam date is now ' . ($subject['exam_date'] ?? 'unset') . '.';
            }
            return mcp_text($line);
        }

        case 'tracker_log_practice': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s    = $r['subject'];
            $runs = is_array($a['runs'] ?? null) ? $a['runs'] : [];
            if (!$runs) {
                throw new McpError('runs must be a non-empty array of practice runs.');
            }
            if (count($runs) > 50) {
                throw new McpError('runs may contain at most 50 entries. Split the session across calls.');
            }

            $sources = [];
            foreach ($store->listPracticeSources($slug) as $src) {
                $sources[$src['key']] = json_decode((string) ($src['metrics_schema'] ?: '{}'), true) ?: [];
            }
            $knownRefs = array_column($store->listTopics($slug), 'ref');

            // Everything is validated before anything is written. A batch that
            // half-stores is worse than one that is refused: the client would
            // retry the whole batch and the idempotency key is the only thing
            // making that safe.
            $clean    = [];
            $unknownR = [];
            $unknownM = [];
            $dropped  = [];
            $seenIds  = [];

            foreach (array_values($runs) as $i => $run) {
                if (!is_array($run)) {
                    throw new McpError('each run must be an object.');
                }
                $at        = 'run ' . ($i + 1);
                $clientId  = mcp_str($run, 'client_run_id', true, 1, 64);
                $source    = mcp_str($run, 'source', true, 1, 60);
                $label     = mcp_str($run, 'label', true, 1, 200);
                $attempted = (int) mcp_num($run, 'attempted', true, 0);
                $correct   = (int) mcp_num($run, 'correct', true, 0);
                $retry     = (int) mcp_num($run, 'correct_after_retry', false, 0, null, 0);
                $incorrect = (int) mcp_num($run, 'incorrect', true, 0);

                if (isset($seenIds[$clientId])) {
                    throw new McpError("$at repeats client_run_id \"$clientId\" from earlier in the same call.");
                }
                $seenIds[$clientId] = true;

                if (!isset($sources[$source])) {
                    throw new McpError("$at has source \"$source\", which is not registered for $slug. "
                        . 'Registered sources: ' . (implode(', ', array_keys($sources)) ?: 'none') . '.');
                }

                // The arithmetic is the basis of every figure the board shows,
                // so a run that does not add up is refused, naming the gap.
                $sum = $correct + $retry + $incorrect;
                if ($sum !== $attempted) {
                    return mcp_text("$at (\"$label\") does not add up: attempted $attempted, but correct $correct"
                        . " + correct_after_retry $retry + incorrect $incorrect = $sum"
                        . ' — out by ' . abs($attempted - $sum) . '. Nothing was stored; fix the figures and send the call again.');
                }

                $metrics = [];
                if (isset($run['metrics'])) {
                    if (!is_array($run['metrics'])) {
                        throw new McpError("$at metrics must be an object of numbers.");
                    }
                    foreach ($run['metrics'] as $k => $v) {
                        if (!is_numeric($v)) {
                            // The metric is lost, not the run.
                            $dropped[] = "$k (not a number)";
                            continue;
                        }
                        if (!array_key_exists($k, $sources[$source])) {
                            $unknownM[] = "$k on $source";
                        }
                        $metrics[$k] = $v + 0;
                    }
                }

                $refs = [];
                if (isset($run['topic_refs'])) {
                    if (!is_array($run['topic_refs'])) {
                        throw new McpError("$at topic_refs must be an array of topic references.");
                    }
                    if (count($run['topic_refs']) > 20) {
                        throw new McpError("$at topic_refs may hold at most 20 entries.");
                    }
                    foreach ($run['topic_refs'] as $ref) {
                        if (!is_string($ref) || $ref === '') {
                            throw new McpError("$at topic_refs entries must be non-empty strings.");
                        }
                        if (!in_array($ref, $knownRefs, true)) {
                            $unknownR[] = $ref;
                        }
                        $refs[] = $ref;
                    }
                }

                $items = is_array($run['items'] ?? null) ? $run['items'] : [];
                if (count($items) > 200) {
                    throw new McpError("$at may hold at most 200 items.");
                }
                $cleanItems = [];
                foreach (array_values($items) as $j => $item) {
                    if (!is_array($item)) {
                        throw new McpError("$at item " . ($j + 1) . ' must be an object.');
                    }
                    $outcome = $item['outcome'] ?? null;
                    if (!in_array($outcome, PRACTICE_OUTCOMES, true)) {
                        throw new McpError("$at item " . ($j + 1) . ' outcome must be one of: '
                            . implode(', ', PRACTICE_OUTCOMES) . '.');
                    }
                    $itemRef = mcp_str($item, 'topic_ref', false, 0, 40);
                    if ($itemRef !== null && !in_array($itemRef, $knownRefs, true)) {
                        $unknownR[] = $itemRef;
                    }
                    $cleanItems[] = [
                        'prompt'         => mcp_str($item, 'prompt', false, 0, 500),
                        'topic_ref'      => $itemRef,
                        'item_key'       => mcp_str($item, 'item_key', false, 1, 64),
                        'outcome'        => (string) $outcome,
                        'attempts_taken' => isset($item['attempts_taken'])
                            ? (int) mcp_num($item, 'attempts_taken', false, 1) : null,
                        'position'       => isset($item['position'])
                            ? (int) mcp_num($item, 'position', false, 0) : $j,
                        'note'           => mcp_str($item, 'note', false, 0, 300),
                    ];
                }

                $clean[] = [
                    'subject_slug'        => $slug,
                    'client_run_id'       => $clientId,
                    'source'              => $source,
                    'label'               => $label,
                    'played_at'           => mcp_practice_when($run['played_at'] ?? null, $at),
                    'attempted'           => $attempted,
                    'correct'             => $correct,
                    'correct_after_retry' => $retry,
                    'incorrect'           => $incorrect,
                    'duration_seconds'    => isset($run['duration_seconds'])
                        ? (int) mcp_num($run, 'duration_seconds', false, 0) : null,
                    'metrics'             => $metrics,
                    'topic_refs'          => $refs,
                    'block_key'           => isset($run['block_key'])
                        ? (int) mcp_num($run, 'block_key', false, 1) : null,
                    'items'               => $cleanItems,
                ];
                // played_at is what the board judges the run on, so the key is
                // checked against that run's own local date.
                $last = $clean[count($clean) - 1];
                if ($last['block_key']) {
                    [$localDate] = tt_local($last['played_at']);
                    mcp_check_block($store, $last['block_key'], $localDate, $slug);
                }
            }

            $rows   = [];
            $stored = 0;
            $dupes  = 0;
            $sched  = ['item' => 0, 'topic' => 0];
            foreach ($clean as $run) {
                $res = $store->addPracticeRun($run);
                $res['status'] === 'stored' ? $stored++ : $dupes++;
                // The schedule advances once per stored run: a duplicate
                // client_run_id stores nothing and schedules nothing, so a
                // retried report cannot count an answer twice.
                if ($res['status'] === 'stored' && $run['items']) {
                    [$runDate] = tt_local($run['played_at']);
                    $n = retrieval_apply_run($store, $slug, $runDate, $run['items']);
                    $sched['item']  += $n['item'];
                    $sched['topic'] += $n['topic'];
                }
                $rows[] = '| ' . $res['id'] . ' | ' . str_replace('|', '/', $run['label'])
                    . ' | ' . $run['source'] . ' | ' . $run['attempted']
                    . ' | ' . $run['correct'] . '/' . $run['correct_after_retry'] . '/' . $run['incorrect']
                    . ' | ' . ($res['status'] === 'stored' ? 'stored' : 'duplicate — already recorded') . ' |';
            }

            $lines = [
                'Practice for ' . $s['name'] . ': ' . $stored . ' stored, ' . $dupes
                    . ' already recorded (a duplicate client_run_id is a no-op, never a second row).',
                '',
                '| Run | Label | Activity | Attempted | First/Retry/Not got | Result |',
                '|---|---|---|---|---|---|',
            ];
            $lines = array_merge($lines, $rows);
            if ($unknownR) {
                $lines[] = '';
                $lines[] = 'Note: no topic with reference ' . implode(', ', array_unique($unknownR))
                    . ' exists in this subject. The runs are stored, but those refs will not join to a topic'
                    . ' in the per-topic breakdown — check them with tracker_get_state.';
            }
            if ($unknownM) {
                $lines[] = 'Note: metric ' . implode(', ', array_unique($unknownM))
                    . ' is not declared in that source\'s metrics schema. It is stored anyway, so nothing is lost,'
                    . ' but check the key if a panel expects it.';
            }
            if ($dropped) {
                $lines[] = 'Note: dropped non-numeric metric ' . implode(', ', array_unique($dropped))
                    . '. The run is stored without it.';
            }
            if ($sched['item'] + $sched['topic'] > 0) {
                $lines[] = 'Retrieval schedule updated: ' . $sched['topic'] . ' topic-grain and '
                    . $sched['item'] . ' item-grain outcomes applied. tracker_retrieval_due reads it.';
            }
            $lines[] = '';
            $lines[] = 'No topic status was changed: practice never moves a status. Use tracker_log_session for that.';
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_list_practice': {
            $slug  = mcp_str($a, 'subject', true, 1);
            $limit = (int) mcp_num($a, 'limit', false, 1, 100, 20);
            $r     = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $filter = ['limit' => $limit, 'include_void' => true];
            if (!empty($a['source'])) {
                $filter['source'] = mcp_str($a, 'source', false, 0, 60);
            }
            if (!empty($a['ref'])) {
                $filter['ref'] = mcp_str($a, 'ref', false, 0, 40);
            }
            $since = mcp_date($a, 'since');
            if ($since !== null) {
                $filter['since'] = $since;
            }
            $rows = $store->listPracticeRuns($slug, $filter);
            if (!$rows) {
                return mcp_text('No practice runs match. Log some with tracker_log_practice, or widen the filters.');
            }
            $out = ['**Practice — ' . $r['subject']['name'] . '**', '',
                    '| # | Date | Activity | Label | Attempted | First time | After retry | Not got | Accuracy |',
                    '|---|---|---|---|---|---|---|---|---|'];
            foreach ($rows as $x) {
                $acc  = practice_run_metric($x, 'accuracy');
                $out[] = '| ' . $x['id'] . ' | ' . practice_run_date($x)
                    . ($x['void_reason'] ? ' VOID' : '')
                    . ' | ' . $x['source'] . ' | ' . str_replace('|', '/', (string) $x['label'])
                    . ' | ' . $x['attempted'] . ' | ' . $x['correct'] . ' | ' . $x['correct_after_retry']
                    . ' | ' . $x['incorrect'] . ' | ' . practice_format($acc, 'percent1') . ' |';
            }
            $voided = array_values(array_filter($rows, static fn($x) => $x['void_reason'] !== null));
            foreach ($voided as $x) {
                $out[] = '';
                $out[] = 'Run ' . $x['id'] . ' is VOID: ' . $x['void_reason'] . ' — it counts towards nothing.';
            }
            return mcp_text(implode("\n", $out));
        }

        case 'tracker_practice_stats': {
            $slug = mcp_str($a, 'subject', true, 1);
            $days = (int) mcp_num($a, 'days', false, 1, 3650, 90);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $filter = ['since' => gmdate('Y-m-d', time() - $days * 86400)];
            if (!empty($a['source'])) {
                $filter['source'] = mcp_str($a, 'source', false, 0, 60);
            }
            if (!empty($a['ref'])) {
                $filter['ref'] = mcp_str($a, 'ref', false, 0, 40);
            }
            $runs = $store->listPracticeRuns($slug, $filter);
            if (!$runs) {
                $registered = array_column($store->listPracticeSources($slug), 'key');
                return mcp_text('No practice logged for ' . $r['subject']['name'] . " in the last $days days."
                    . ($registered ? ' Registered activities: ' . implode(', ', $registered) . '.' : ''));
            }

            $totals = ['attempted' => 0, 'correct' => 0, 'correct_after_retry' => 0, 'incorrect' => 0];
            foreach ($runs as $x) {
                foreach ($totals as $k => $_) {
                    $totals[$k] += (int) $x[$k];
                }
            }
            $best = null;
            foreach ($runs as $x) {
                if ($best === null || (int) $x['correct'] > (int) $best['correct']) {
                    $best = $x;
                }
            }
            $last10 = practice_window_runs($runs, 'last10');

            $parts = [
                '**Practice — ' . $r['subject']['name'] . '**'
                    . (!empty($filter['source']) ? ' · ' . $filter['source'] : '')
                    . (!empty($filter['ref']) ? ' · topic ' . $filter['ref'] : '')
                    . " · last $days days",
                '',
                count($runs) . ' run' . (count($runs) === 1 ? '' : 's') . ' · '
                    . $totals['attempted'] . ' items attempted · ' . $totals['correct'] . ' right first time · '
                    . $totals['correct_after_retry'] . ' after a retry · ' . $totals['incorrect'] . ' not got.',
                'Accuracy ' . practice_format(practice_stat_value($runs, 'accuracy', 'pooled'), 'percent1')
                    . ' over the window, '
                    . practice_format(practice_stat_value($last10, 'accuracy', 'pooled'), 'percent1')
                    . ' over the last ' . count($last10) . '.',
                'Solve rate ' . practice_format(practice_stat_value($runs, 'solve_rate', 'pooled'), 'percent1')
                    . ' over the window, '
                    . practice_format(practice_stat_value($last10, 'solve_rate', 'pooled'), 'percent1')
                    . ' over the last ' . count($last10) . '.',
                'Both are pooled — total right ÷ total attempted — not the mean of the per-run percentages,'
                    . ' which differ and would flatter a run of short games.',
            ];
            if ($best) {
                $parts[] = 'Best run: ' . $best['correct'] . ' right in "' . $best['label'] . '" on '
                    . practice_run_date($best) . ' (' . $best['source'] . ').';
            }

            // Source-specific extremes: only where the source actually declares
            // the metric, so maths is never asked for a top speed.
            $extremes = [];
            foreach ($store->listPracticeSources($slug) as $src) {
                $schema = json_decode((string) ($src['metrics_schema'] ?: '{}'), true) ?: [];
                foreach (array_keys($schema) as $key) {
                    $ofSource = array_values(array_filter($runs, static fn($x) => $x['source'] === $src['key']));
                    $value    = practice_stat_value($ofSource, 'metrics.' . $key, 'max');
                    if ($value !== null) {
                        $extremes[] = 'best ' . $key . ' ' . practice_format($value, 'decimal1')
                            . ' (' . $src['display_name'] . ')';
                    }
                }
            }
            if ($extremes) {
                $parts[] = ucfirst(implode(', ', $extremes)) . '.';
            }

            $roll = $store->practiceTopicRollup($slug, $filter);
            if ($roll) {
                $topics = [];
                foreach ($store->listTopics($slug) as $t) {
                    $topics[$t['ref']] = $t;
                }
                $rows = [];
                foreach ($roll as $ref => $x) {
                    $acc   = $x['attempted'] > 0 ? $x['correct'] / $x['attempted'] : null;
                    $solve = $x['attempted'] > 0 ? ($x['correct'] + $x['retry']) / $x['attempted'] : null;
                    $rows[] = ['ref' => $ref, 'acc' => $acc, 'solve' => $solve, 'x' => $x,
                               'name' => $topics[$ref]['name'] ?? '(not in the syllabus)',
                               'status' => $topics[$ref]['status'] ?? null];
                }
                usort($rows, static function ($p, $q) {
                    $cmp = practice_nullcmp($p['acc'], $q['acc']);
                    return $cmp !== 0 ? $cmp : strcmp($p['ref'], $q['ref']);
                });
                $parts[] = '';
                $parts[] = '### By topic, weakest first';
                $parts[] = '| Topic | Name | Runs | Items | Right first time | Solve rate | Status |';
                $parts[] = '|---|---|---|---|---|---|---|';
                foreach ($rows as $row) {
                    $parts[] = '| ' . $row['ref'] . ' | ' . $row['name']
                        . ' | ' . practice_format((float) $row['x']['runs'], 'integer')
                        . ' | ' . practice_format((float) $row['x']['attempted'], 'integer')
                        . ' | ' . practice_format($row['acc'], 'percent1')
                        . ' | ' . practice_format($row['solve'], 'percent1')
                        . ' | ' . ($row['status'] === null ? '—' : (STATUS_LABEL[$row['status']] ?? $row['status'])) . ' |';
                }
                $parts[] = '';
                $parts[] = 'Topic figures roll up from per-item topic refs where a run has them, and are'
                    . ' apportioned across the run-level refs otherwise — never both, or the counts would double.';
            }
            $parts[] = '';
            $parts[] = 'Board: /s/' . $slug . '/practice';
            return mcp_text(implode("\n", $parts));
        }

        case 'tracker_void_practice': {
            $slug = mcp_str($a, 'subject', true, 1);
            $id   = (int) mcp_num($a, 'run_id', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            if (!$store->getPracticeRun($slug, $id)) {
                return mcp_text("No practice run $id in $slug. Call tracker_list_practice to see the ids.");
            }
            if (!array_key_exists('void_reason', $a)) {
                throw new McpError('void_reason is required: say why the run should not count, or pass null to un-void it.');
            }
            $reason = $a['void_reason'] === null ? null : mcp_str($a, 'void_reason', false, 0, 500);
            $store->voidPracticeRun($slug, $id, $reason);
            return mcp_text($reason === null
                ? "Run $id un-voided: it counts again."
                : "Run $id voided: $reason. The row stays and is still listed, marked VOID, but counts towards nothing.");
        }

        case 'tracker_get_scoreboard': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $stored = $store->getScoreboard($slug);
            $config = practice_scoreboard_for($store, $slug);
            $where  = $stored !== null
                ? 'Stored configuration for ' . $r['subject']['name'] . '.'
                : 'No configuration stored for ' . $r['subject']['name'] . '; this is the built-in default,'
                    . ' and writing it back with tracker_set_scoreboard is how you start editing it.';
            return mcp_text($where . "\n\n```json\n"
                . json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n"
                . 'Board: /s/' . $slug . '/practice');
        }

        case 'tracker_set_scoreboard': {
            $slug = mcp_str($a, 'subject', true, 1);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $config = $a['config'] ?? null;
            if (!is_array($config)) {
                throw new McpError('config must be an object of { version, panels }.');
            }
            $errors = practice_validate_scoreboard(
                $config,
                array_column($store->listPracticeSources($slug), 'key')
            );
            if ($errors) {
                // The whole configuration is rejected, so a bad edit cannot
                // half-apply and leave a broken board.
                return mcp_text('Rejected, and the stored configuration is unchanged:' . "\n- "
                    . implode("\n- ", $errors)
                    . "\n\nFix every line and send the whole configuration again.");
            }
            $note = mcp_str($a, 'note', false, 0, 500);
            $id   = $store->setScoreboard($slug, $config, $note);
            $types = array_map(static fn($p) => $p['type'], $config['panels']);
            return mcp_text('Scoreboard for ' . $r['subject']['name'] . ' saved as version ' . $id . ': '
                . count($config['panels']) . ' panels (' . implode(', ', $types) . ').'
                . "\nThe previous configuration is kept, so it can be read back if this one turns out wrong."
                . "\nBoard: /s/$slug/practice");
        }
    }

    throw new McpError("Unknown tool \"$name\".");
}

/** Trim a float that is really an integer, so 49.0 prints as 49. */
function num(float|int|string $n): string
{
    $f = (float) $n;
    return floor($f) === $f ? (string) (int) $f : (string) $f;
}

// ---- JSON-RPC dispatch ---------------------------------------------------

/** ' for subject "maths"' when the call named one, so a failure says which. */
function mcp_subject_tag(array $args): string
{
    $slug = $args['subject'] ?? ($args['slug'] ?? null);
    if (is_string($slug) && $slug !== '') {
        return ' for subject "' . $slug . '"';
    }
    if (is_array($args['subjects'] ?? null) && $args['subjects']) {
        return ' for subjects ' . implode(', ', array_map('strval', $args['subjects']));
    }
    return '';
}

/**
 * A list of topic refs from an argument: non-empty strings, bounded.
 *
 * @return array<int,string>
 */
function mcp_ref_list(mixed $raw, string $what, int $max): array
{
    if ($raw === null) {
        return [];
    }
    if (!is_array($raw)) {
        throw new McpError("$what must be an array of topic references.");
    }
    if (count($raw) > $max) {
        throw new McpError("$what may hold at most $max entries.");
    }
    $out = [];
    foreach ($raw as $ref) {
        if (!is_string($ref) || trim($ref) === '' || mb_strlen($ref) > 40) {
            throw new McpError("$what entries must be non-empty strings of at most 40 characters.");
        }
        $out[] = trim($ref);
    }
    return array_values(array_unique($out));
}

/**
 * A consolidates[] list, validated: every ref a string, every
 * error_session_id a session of this subject. Unknown refs are reported
 * back rather than refused, as an unknown topic ref is everywhere else.
 *
 * @return array{clean:array<int,array{ref:string,error_session_id?:int}>,unknown:array<int,string>}
 */
function mcp_consolidates(Store $store, string $slug, mixed $raw): array
{
    if ($raw === null) {
        return ['clean' => [], 'unknown' => []];
    }
    if (!is_array($raw)) {
        throw new McpError('consolidates must be an array of { ref, error_session_id? }.');
    }
    if (count($raw) > 30) {
        throw new McpError('consolidates may hold at most 30 entries.');
    }
    $known   = array_column($store->listTopics($slug), 'ref');
    $clean   = [];
    $unknown = [];
    foreach (array_values($raw) as $i => $c) {
        if (!is_array($c)) {
            throw new McpError('consolidates[' . $i . '] must be an object with ref.');
        }
        $ref = mcp_str($c, 'ref', true, 1, 40);
        if (!in_array($ref, $known, true)) {
            $unknown[] = $ref;
        }
        $entry = ['ref' => $ref];
        if (isset($c['error_session_id']) && $c['error_session_id'] !== null) {
            $sid = (int) mcp_num($c, 'error_session_id', false, 1);
            $row = $store->sessionById($sid);
            if (!$row || (string) $row['subject_slug'] !== $slug) {
                throw new McpError("consolidates[$i] names error_session_id $sid, which is not a session of $slug. "
                    . 'Nothing was written.');
            }
            $entry['error_session_id'] = $sid;
        }
        $clean[] = $entry;
    }
    return ['clean' => $clean, 'unknown' => array_values(array_unique($unknown))];
}

function mcp_handle(Store $store, array $req): ?array
{
    $id     = $req['id'] ?? null;
    $method = (string) ($req['method'] ?? '');
    $params = is_array($req['params'] ?? null) ? $req['params'] : [];

    $ok  = static fn($result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    $err = static fn(int $code, string $message) => [
        'jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message],
    ];

    // Notifications carry no id and expect no reply.
    if ($id === null && str_starts_with($method, 'notifications/')) {
        return null;
    }

    switch ($method) {
        case 'initialize': {
            $asked = (string) ($params['protocolVersion'] ?? '');
            return $ok([
                'protocolVersion' => in_array($asked, MCP_PROTOCOL_VERSIONS, true) ? $asked : MCP_PROTOCOL_VERSIONS[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'tracker-mcp-server', 'version' => '1.0.0'],
            ]);
        }

        case 'ping':
            return $ok((object) []);

        case 'tools/list':
            return $ok(['tools' => mcp_tools()]);

        case 'resources/list':
            return $ok(['resources' => []]);

        case 'prompts/list':
            return $ok(['prompts' => []]);

        case 'tools/call': {
            $name = (string) ($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            // Noted for the shutdown handler in index.php: a fatal error
            // mid-call (memory, time limit) otherwise answers with an empty
            // body, and the caller sees "Tool execution failed" and nothing
            // to act on.
            $GLOBALS['mcp_inflight'] = ['id' => $id, 'name' => $name, 'subject' => mcp_subject_tag($args)];
            try {
                $result = mcp_call_tool($store, $name, $args);
                $GLOBALS['mcp_inflight'] = null;
                return $ok($result);
            } catch (McpError $e) {
                $GLOBALS['mcp_inflight'] = null;
                // A tool-level failure is a result with isError, not a protocol
                // error: the model should see the message and correct itself.
                // The tool and the subject are named so the caller has
                // something to act on rather than something to guess at.
                return $ok(array_merge(
                    mcp_text($name . mcp_subject_tag($args) . ' refused: ' . $e->getMessage()),
                    ['isError' => true]
                ));
            } catch (Throwable $e) {
                $GLOBALS['mcp_inflight'] = null;
                error_log('tracker tool ' . $name . ' failed: ' . $e->getMessage());
                return $ok(array_merge(
                    mcp_text($name . mcp_subject_tag($args) . ' failed: ' . get_class($e) . ': '
                        . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'),
                    ['isError' => true]
                ));
            }
        }
    }

    return $err(-32601, "Method not found: $method");
}
