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

// ---- tool definitions ---------------------------------------------------

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
            'name'  => 'tracker_list_assessments',
            'title' => 'List papers and checks',
            'description' =>
                "Logged assessments for a subject, newest first, with grade conversions.\n\n"
                . "USE WHEN: asked about marks, grades, mocks, past papers, or \"what grade is she on\".\n\n"
                . "Full papers are scaled to the subject's boundary maximum and converted using its stored boundaries. "
                . "Topic checks report a percentage only and are never grade-converted — a check on a handful of topics cannot stand in for a whole paper.\n\n"
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
                . 'Args: subject, summary. Optional date (defaults today), next_steps, updates[] of { ref, status?, evidence, watch? }.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'    => $subjectArg,
                    'date'       => $isoDate,
                    'summary'    => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000,
                        'description' => 'What was covered and how it went'],
                    'next_steps' => ['type' => 'string', 'maxLength' => 1000,
                        'description' => 'What the next session should open with'],
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
            'name'  => 'tracker_log_assessment',
            'title' => 'Log a paper or topic check',
            'description' =>
                "Records an assessment result and converts it to a grade where that is meaningful.\n\n"
                . "USE WHEN: a past paper, mock or topic check has been marked. Log it as soon as you have the score, including when you marked it yourself.\n\n"
                . "kind 'paper' is grade-converted against the subject's boundaries; kind 'check' returns a percentage only and is never grade-converted. "
                . "Log blanks every time you know it — a blank scores nothing while working earns method marks, so the count is worth watching.\n\n"
                . 'Args: subject, name, score, max. Optional kind (paper|check, default paper), tier, date, blanks, note.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'name'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200,
                        'description' => "What was sat, e.g. '8300/1H Jun-23'"],
                    'kind'    => ['type' => 'string', 'enum' => ['paper', 'check'], 'default' => 'paper',
                        'description' => 'A full past paper, or a topic check. Only papers are grade-converted.'],
                    'score'   => ['type' => 'number', 'minimum' => 0],
                    'max'     => ['type' => 'number', 'minimum' => 1],
                    'tier'    => ['type' => 'string', 'maxLength' => 4, 'default' => 'F'],
                    'date'    => $isoDate,
                    'blanks'  => ['type' => 'integer', 'minimum' => 0, 'description' => 'Questions left blank'],
                    'note'    => ['type' => 'string', 'maxLength' => 1000],
                ],
                'required'   => ['subject', 'name', 'score', 'max'],
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
    ];
}

// ---- tool implementations -----------------------------------------------

function mcp_call_tool(Store $store, string $name, array $a): array
{
    switch ($name) {
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

            $sessions = $store->listSessions($slug, 1);
            $parts[]  = $sessions
                ? "\nLast session logged: " . $sessions[0]['date'] . ' — ' . $sessions[0]['summary']
                    . ($sessions[0]['next_steps'] ? "\nPlanned next: " . $sessions[0]['next_steps'] : '')
                : "\nNo sessions logged yet.";

            return mcp_text(implode("\n", $parts));
        }

        case 'tracker_list_assessments': {
            $slug  = mcp_str($a, 'subject', true, 1);
            $limit = (int) mcp_num($a, 'limit', false, 1, 100, 20);
            $r     = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s    = $r['subject'];
            $rows = array_slice($store->listAssessments($slug), 0, $limit);
            if (!$rows) {
                return mcp_text('No assessments logged for this subject yet.');
            }
            $body = [];
            foreach ($rows as $x) {
                $outcome = $x['kind'] === 'check'
                    ? round(($x['score'] / $x['max']) * 100) . '% (no grade)'
                    : '≈ grade ' . gradeFor($s, (float) $x['score'], (float) $x['max'], (string) $x['tier']);
                $body[] = '| ' . $x['date'] . ' | ' . $x['name'] . ' | ' . $x['kind'] . ' | ' . $x['tier']
                    . ' | ' . num($x['score']) . '/' . num($x['max']) . ' | ' . $outcome
                    . ' | ' . ($x['blanks'] ?? '—') . ' | ' . ($x['note'] ?? '') . ' |';
            }
            return mcp_text("| Date | Assessment | Kind | Tier | Score | Outcome | Blanks | Note |\n"
                . "|---|---|---|---|---|---|---|---|\n" . implode("\n", $body));
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

            $assessments = $store->listAssessments($slug);
            if ($assessments) {
                $rows = array_map(
                    static function ($x) use ($s) {
                        $outcome = $x['kind'] === 'check'
                            ? round(($x['score'] / $x['max']) * 100) . '% (check, not grade-converted)'
                            : '≈ grade ' . gradeFor($s, (float) $x['score'], (float) $x['max'], (string) $x['tier']);
                        return '| ' . $x['date'] . ' | ' . $x['name'] . ' | ' . num($x['score']) . '/' . num($x['max']) . ' | ' . $outcome . ' |';
                    },
                    $assessments
                );
                $parts[] = '## Assessment log';
                $parts[] = '';
                $parts[] = '| Date | Assessment | Score | Outcome |';
                $parts[] = '|---|---|---|---|';
                $parts[] = implode("\n", $rows);
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

            $store->addSession([
                'subject_slug'   => $slug,
                'date'           => $when,
                'summary'        => $summary,
                'topics_touched' => $refs ? implode(', ', $refs) : null,
                'next_steps'     => $next,
            ]);

            $lines = ['Session logged for ' . $s['name'] . " on $when."];
            if ($applied) {
                $lines[] = 'Updated: ' . implode('; ', $applied) . '.';
            }
            if ($missing) {
                $lines[] = 'Not found, so not updated: ' . implode(', ', $missing)
                    . '. Check the references with tracker_get_state.';
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_log_assessment': {
            $slug  = mcp_str($a, 'subject', true, 1);
            $name  = mcp_str($a, 'name', true, 1, 200);
            $kind  = $a['kind'] ?? 'paper';
            if (!in_array($kind, ['paper', 'check'], true)) {
                throw new McpError("kind must be 'paper' or 'check'.");
            }
            $score = mcp_num($a, 'score', true, 0);
            $max   = mcp_num($a, 'max', true, 1);
            $tier  = mcp_str($a, 'tier', false, 0, 4, 'F');
            $when  = mcp_date($a, 'date', gmdate('Y-m-d'));
            $hasBlanks = array_key_exists('blanks', $a) && $a['blanks'] !== null;
            $blanks    = $hasBlanks ? (int) mcp_num($a, 'blanks', false, 0) : null;
            $note      = mcp_str($a, 'note', false, 0, 1000);

            $r = mcp_resolve($store, $slug);
            if (isset($r['error'])) {
                return mcp_text($r['error']);
            }
            $s = $r['subject'];
            if ($score > $max) {
                return mcp_text('Score ' . num($score) . ' exceeds the maximum ' . num($max) . '. Check the figures.');
            }
            $store->addAssessment([
                'subject_slug' => $slug,
                'date'         => $when,
                'name'         => $name,
                'kind'         => $kind,
                'tier'         => $tier,
                'score'        => $score,
                'max'          => $max,
                'blanks'       => $blanks,
                'note'         => $note,
            ]);
            $outcome = $kind === 'check'
                ? round(($score / $max) * 100) . '% — a topic check, so not grade-converted'
                : '≈ grade ' . gradeFor($s, $score, $max, $tier) . " on tier $tier";
            $blankNote = !$hasBlanks
                ? ' No blank count recorded — worth counting next time.'
                : " $blanks blank" . ($blanks === 1 ? '' : 's') . '.';
            return mcp_text("Logged $name ($when): " . num($score) . '/' . num($max) . ", $outcome." . $blankNote);
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
