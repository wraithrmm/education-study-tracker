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
    } elseif ($status === 'excused') {
        $status = 'excused — ' . ($b['reason'] ?? 'no reason given');
    } elseif ($status === 'day_off') {
        $status = 'day off — ' . ($b['reason'] ?? '');
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
        $day = TIMETABLE_DAYS[(int) (new DateTimeImmutable($date, tt_zone()))->format('N')];
        throw new McpError(
            "No block $key runs on $day $date. Either that key is not in the timetable in force "
            . 'then, or it belongs to another day of the week. Get the keys for that date from '
            . 'tracker_today, and check the date is the day the work was actually done.'
        );
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
                . "Args: subject (slug). Optional status (array) and strand to filter.\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'status'  => ['type' => 'array', 'items' => $statusEnum,
                        'description' => 'Only these statuses, e.g. ["gap","developing"]'],
                    'strand'  => ['type' => 'string', 'description' => "Only this strand key, e.g. 'A' for Algebra"],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_review_queue',
            'title' => 'What to work on next',
            'description' =>
                "The prioritised queue for a subject, in three groups: ageing secures due a retrieval check, loose ends on otherwise-secure topics, and priority gaps (lower tier first). "
                . "Each entry lists the teaching resources attached to it, so this alone is enough to plan a session.\n\n"
                . "USE WHEN: opening a study or tutoring session, or asked \"what should we do today\", \"what next\", \"what needs work\". "
                . "Call this FIRST in any session, before deciding what to teach.\n\n"
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
                "The audit trail for a subject, week by week: each session logged, and every topic status change it produced, with the evidence recorded at the time.\n\n"
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
                . "Pass block_key from tracker_today when the work ran against a timetable block; the date must be the day the work was actually done.\n\n"
                . 'Args: subject, summary. Optional date (defaults today), next_steps, block_key, duration_minutes, updates[] of { ref, status?, evidence, watch? }.',
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
                            ],
                            'required'   => ['ref', 'evidence'],
                        ],
                    ],
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
                . 'Args: subject, session_id (from tracker_history). Any of date, summary, next_steps, void_reason.',
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
            'title' => 'Create a subject, or extend its syllabus',
            'description' =>
                "Sets up a subject with its strands, grade boundaries and full topic list — the syllabus.\n\n"
                . "USE WHEN: asked to start tracking a new subject, or to add topics to an existing one.\n\n"
                . "Re-running it for an existing subject updates the metadata and adds new topics, but NEVER resets the status of a topic that already exists — "
                . "progress cannot be lost by re-seeding, so extending a syllabus is safe.\n\n"
                . "Args: slug, name, strands (key to display name), topics[] of { ref, name, strand, tier?, status?, watch? }. "
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
                'required'   => ['slug', 'name', 'strands', 'topics'],
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
                . "Each item is { outcome (correct|retry|incorrect), prompt?, topic_ref?, attempts_taken?, position?, note? } — "
                . "supply items where you know them per question; the per-topic breakdown is built from them.\n\n"
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
                "Which block the student is in right now, what is next, and what has already been missed today.\n\n"
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
                $lines[] = '  ' . mcp_block_line($b);
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
                return mcp_text("Block $key ({$block['label']}) is a break — there is nothing to tick.");
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
            $rows = $store->listTopics($slug, $filter);
            if (!$rows) {
                return mcp_text('No topics match that filter.');
            }
            $p       = progressFor($store, $slug);
            $summary = [];
            foreach ($p['counts'] as $k => $n) {
                $summary[] = (STATUS_LABEL[$k] ?? $k) . ": $n";
            }
            $head = '**' . $s['name'] . '** (' . ($s['spec_code'] ?? 'no spec code')
                . ($s['tier'] ? ', ' . $s['tier'] : '') . ') — ' . $p['pct'] . '% of the specification covered.';
            return mcp_text($head . "\n" . implode(' · ', $summary) . "\n\n" . TOPIC_HEADER . "\n"
                . implode("\n", array_map('mcp_topic_line', $rows)));
        }

        case 'tracker_review_queue': {
            $slug = mcp_str($a, 'subject', true, 1);
            $weeks = (int) mcp_num($a, 'ageing_weeks', false, 1, 52, 8);
            $r    = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s   = $r['subject'];
            $all = $store->listTopics($slug);

            $ageing = [];
            foreach ($all as $t) {
                if ($t['status'] === 'secure' || $t['status'] === 'examready') {
                    $w = weeksSince($t['last_touched']);
                    if ($w === null || $w >= $weeks) {
                        $ageing[] = ['t' => $t, 'w' => $w];
                    }
                }
            }
            usort($ageing, static fn($x, $y) => ($y['w'] ?? 999) <=> ($x['w'] ?? 999));

            $loose = array_values(array_filter($all, static fn($t) => $t['watch'] && $t['status'] !== 'gap'));
            $gaps  = array_values(array_filter($all, static fn($t) => $t['status'] === 'gap'));
            usort($gaps, static fn($x, $y) => strcmp($x['tier'], $y['tier']));

            $parts = ['**Review queue — ' . $s['name'] . '**'];

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
                        '- **' . $x['t']['ref'] . '** ' . $x['t']['name'] . ' — '
                            . ($x['w'] === null ? 'no date recorded' : $x['w'] . ' weeks since last touched'),
                        $x['t']['ref']
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
                    . implode("\n", array_map(static fn($r) => mcp_resource_line($r), $general));
            }

            $sessions = array_values(array_filter(
                $store->listSessions($slug, 10),
                static fn($x) => empty($x['void_reason'])
            ));
            $parts[]  = $sessions
                ? "\nLast session logged: " . $sessions[0]['date'] . ' — ' . $sessions[0]['summary']
                    . ($sessions[0]['next_steps'] ? "\nPlanned next: " . $sessions[0]['next_steps'] : '')
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
                    $parts[] = '- **Session ' . $x['id'] . '** ' . $x['date']
                        . ($void ? ' — VOID: ' . $void : '') . ' — ' . $x['summary'];
                    if ($x['next_steps']) {
                        $parts[] = '  - Planned next: ' . $x['next_steps'];
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

            $sessionId = $store->addSession([
                'subject_slug'     => $slug,
                'date'             => $when,
                'summary'          => $summary,
                'topics_touched'   => null,
                'next_steps'       => $next,
                'block_key'        => $blockKey ?: null,
                'duration_minutes' => $minutes,
            ]);

            foreach ($updates as $u) {
                if (!is_array($u)) {
                    throw new McpError('each update must be an object.');
                }
                $uref = mcp_str($u, 'ref', true, 1);
                $uev  = mcp_str($u, 'evidence', true, 10, 500);
                $ust  = mcp_status($u, 'status', false);
                $refs[] = $uref;
                $args = [
                    'subject_slug' => $slug,
                    'ref'          => $uref,
                    'status'       => $ust,
                    'evidence'     => $uev,
                    'last_touched' => $when,
                    'session_id'   => $sessionId,
                ];
                if (array_key_exists('watch', $u)) {
                    $args['watch'] = $u['watch'] === null ? null : mcp_str($u, 'watch', false, 0, 500);
                }
                $res = $store->updateTopicStatus($args);
                if (!$res) {
                    $missing[] = $uref;
                } else {
                    $applied[] = $res['previous'] === $res['current']
                        ? "$uref (still " . STATUS_LABEL[$res['current']] . ')'
                        : "$uref: " . STATUS_LABEL[$res['previous']] . ' → ' . STATUS_LABEL[$res['current']];
                }
            }

            if ($refs) {
                $store->setSessionTopics($sessionId, implode(', ', $refs));
            }

            $lines = ["Session $sessionId logged for " . $s['name'] . " on $when."];
            if ($applied) {
                $lines[] = 'Updated: ' . implode('; ', $applied) . '.';
            }
            if ($missing) {
                $lines[] = 'Not found, so not updated: ' . implode(', ', $missing)
                    . '. Check the references with tracker_get_state.';
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
            if (!$store->getSession($slug, $id)) {
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
            if (!$fields) {
                throw new McpError('Give at least one of date, summary, next_steps or void_reason to change.');
            }
            $store->amendSession($slug, $id, $fields);
            $what = [];
            foreach ($fields as $k => $v) {
                $what[] = $k === 'void_reason'
                    ? ($v === null ? 'un-voided' : 'voided (' . $v . ')')
                    : "$k updated";
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
            $name = mcp_str($a, 'name', true, 1, 120);
            if (!is_array($a['strands'] ?? null) || !$a['strands']) {
                throw new McpError('strands must be an object of strand key to display name.');
            }
            if (!is_array($a['topics'] ?? null) || !$a['topics']) {
                throw new McpError('topics must be a non-empty array.');
            }
            if (count($a['topics']) > 400) {
                throw new McpError('topics may contain at most 400 entries.');
            }

            $existing = $store->getSubject($slug);
            $before   = $existing ? count($store->listTopics($slug)) : 0;

            $store->upsertSubject([
                'slug'         => $slug,
                'name'         => $name,
                'spec_code'    => mcp_str($a, 'spec_code', false, 0, 60),
                'tier'         => mcp_str($a, 'tier', false, 0, 30),
                'exam_date'    => mcp_date($a, 'exam_date'),
                'strands'      => $a['strands'],
                'boundaries'   => is_array($a['boundaries'] ?? null) ? $a['boundaries'] : [],
                'boundary_max' => (int) mcp_num($a, 'boundary_max', false, 1, null, 240),
                'notes'        => mcp_str($a, 'notes', false, 0, 2000),
            ]);

            foreach (array_values($a['topics']) as $i => $t) {
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

            $after = count($store->listTopics($slug));
            return mcp_text($existing
                ? "Updated $name. Topics: $before → $after (" . ($after - $before) . ' added; existing statuses untouched).'
                : "Created $name with $after topics. Dashboard: /s/$slug");
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
            foreach ($clean as $run) {
                $res = $store->addPracticeRun($run);
                $res['status'] === 'stored' ? $stored++ : $dupes++;
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
            try {
                return $ok(mcp_call_tool($store, $name, $args));
            } catch (McpError $e) {
                // A tool-level failure is a result with isError, not a protocol
                // error: the model should see the message and correct itself.
                return $ok(array_merge(mcp_text($e->getMessage()), ['isError' => true]));
            } catch (Throwable $e) {
                error_log('tracker tool ' . $name . ' failed: ' . $e->getMessage());
                return $ok(array_merge(mcp_text('The tool failed: ' . $e->getMessage()), ['isError' => true]));
            }
        }
    }

    return $err(-32601, "Method not found: $method");
}
