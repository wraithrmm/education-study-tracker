<?php
/**
 * The MCP endpoint: stateless JSON-RPC over a single POST, which is all the
 * Node version did too (it used the SDK's streamable transport with
 * enableJsonResponse and no session id). Implementing the wire format directly
 * costs less than it sounds — initialize, tools/list, tools/call, ping — and
 * removes the last reason to need a JIT on this host.
 *
 * The tool set, descriptions and validation rules are ported from src/mcp.ts
 * unchanged, including the two rules the schema enforces rather than leaving to
 * good intentions: evidence of at least ten characters on every status change,
 * and topic checks never being grade-converted.
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

    return [
        [
            'name'  => 'tracker_list_subjects',
            'title' => 'List tracked subjects',
            'description' => "List every subject in the study tracker with a one-line progress summary.\n\n"
                . "Call this first when you do not already know the subject slug. Returns, per subject: slug, display name, spec code, tier, exam date, topic count, and the percentage of the specification covered (weighted: developing = 1, secure = 2, exam-ready = 3, out of 3 per topic).\n\n"
                . 'Takes no arguments. Read-only.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_get_state',
            'title' => 'Get topic state for a subject',
            'description' => "Return the current RAG state of every topic in a subject — the authoritative record of what has been learned.\n\n"
                . "Consult this before teaching anything, so you do not reteach secure material or teach a topic whose prerequisite is still a gap.\n\n"
                . "Args:\n  - subject (string): subject slug, e.g. \"maths\"\n  - status (array, optional): filter to these statuses only\n  - strand (string, optional): filter to one strand key, e.g. \"A\" for Algebra\n\n"
                . 'Returns a markdown table of Ref, Topic, Strand, Tier, Status, Last touched, Loose end, preceded by a status count summary. Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => ['type' => 'string', 'minLength' => 1, 'description' => "Subject slug, e.g. 'maths'"],
                    'status'  => ['type' => 'array', 'items' => $statusEnum, 'description' => 'Optional status filter'],
                    'strand'  => ['type' => 'string', 'description' => "Optional strand key, e.g. 'A'"],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_review_queue',
            'title' => 'What to work on next',
            'description' => "Build a prioritised review queue for a subject. Use this to open a session.\n\n"
                . "Three groups are returned:\n"
                . "  1. AGEING — topics marked secure or exam-ready that have not been touched for the ageing threshold. These belong in a retrieval starter; if one fails there, demote it to developing.\n"
                . "  2. LOOSE ENDS — topics that are secure but carry a specific unresolved note. Feed these into starters rather than reteaching the whole topic.\n"
                . "  3. PRIORITY GAPS — topics marked gap, lower tier first, since a foundation gap outranks a higher-tier one.\n\n"
                . "Args:\n  - subject (string): subject slug\n  - ageing_weeks (number, optional, default 8): weeks after which a secure topic is considered due for a retrieval check\n\n"
                . 'Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'      => ['type' => 'string', 'minLength' => 1, 'description' => 'Subject slug'],
                    'ageing_weeks' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 52, 'default' => 8,
                        'description' => 'Weeks before a secure topic is due a check'],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_list_assessments',
            'title' => 'List papers and checks',
            'description' => "List logged assessments for a subject, newest first, with grade conversions.\n\n"
                . "Full papers are scaled to the subject's boundary maximum and converted using its stored grade boundaries. Topic checks return a percentage only and are never grade-converted — a check on a handful of topics cannot stand in for a whole paper.\n\n"
                . "Args:\n  - subject (string): subject slug\n  - limit (number, optional, default 20)\n\n"
                . 'Also reports the most recent blank count, where recorded. Read-only.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => ['type' => 'string', 'minLength' => 1, 'description' => 'Subject slug'],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_export_markdown',
            'title' => 'Export topic state as markdown',
            'description' => "Render the whole current state of a subject as a markdown document, in the same shape as a hand-maintained topic-state file.\n\n"
                . "Use this when someone wants a document to file, print, or paste into project knowledge. The database is the source of truth; this export is generated from it, so never edit the export and expect the tracker to follow.\n\n"
                . "Args:\n  - subject (string): subject slug\n\nRead-only.",
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['subject' => ['type' => 'string', 'minLength' => 1, 'description' => 'Subject slug']],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_update_topic',
            'title' => "Update one topic's status",
            'description' => "Change a single topic's status, loose-end note, or last-touched date, recording the evidence for the change.\n\n"
                . "Evidence is mandatory and should say what was done, how it scored, and when — for example \"harder independent retest 4/4, 13 Aug\". A status change without evidence is not auditable, so the tool refuses one.\n\n"
                . "Args:\n  - subject (string): subject slug\n  - ref (string): topic reference, e.g. \"A17\"\n  - status (optional): new status. Omit to record evidence or a note without changing status.\n  - evidence (string): what justifies this update (10-500 characters)\n  - watch (string or null, optional): a specific loose end to carry forward, or null to clear it\n  - last_touched (optional): YYYY-MM-DD, defaults to today\n\n"
                . 'Returns the previous and new status. To update several topics at once, use tracker_log_session instead.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'      => ['type' => 'string', 'minLength' => 1],
                    'ref'          => ['type' => 'string', 'minLength' => 1, 'description' => "Topic reference, e.g. 'A17'"],
                    'status'       => $statusEnum,
                    'evidence'     => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500,
                        'description' => 'Evidence must say what was done and how it went'],
                    'watch'        => ['type' => ['string', 'null'], 'maxLength' => 500],
                    'last_touched' => $isoDate,
                ],
                'required'   => ['subject', 'ref', 'evidence'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_log_session',
            'title' => 'Log a session and its topic updates',
            'description' => "Record a teaching session and apply any status changes it produced, in one call. This is the normal way to close a session.\n\n"
                . "Args:\n  - subject (string): subject slug\n  - date (optional): YYYY-MM-DD, defaults to today\n  - summary (string): what was covered and how it went\n  - next_steps (string, optional): what the next session should open with\n  - updates (array, optional): topic updates, each { ref, status?, evidence, watch? }\n\n"
                . "Each update carries its own evidence. Topics that do not exist are reported back rather than silently skipped, so a typo in a reference is visible.\n\n"
                . 'Returns a confirmation listing each topic that moved.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject'    => ['type' => 'string', 'minLength' => 1],
                    'date'       => $isoDate,
                    'summary'    => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000],
                    'next_steps' => ['type' => 'string', 'maxLength' => 1000],
                    'updates'    => [
                        'type'     => 'array',
                        'maxItems' => 50,
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
            'description' => "Record an assessment result.\n\n"
                . "Args:\n  - subject (string): subject slug\n  - name (string): what was sat, e.g. \"8300/1H Jun-23\"\n  - kind ('paper' | 'check'): a full past paper, or a topic check. Only papers are grade-converted.\n  - score (number), max (number)\n  - tier (string, optional, default 'F')\n  - date (optional): YYYY-MM-DD, defaults to today\n  - blanks (number, optional): questions left blank — worth logging every time, since a blank scores nothing while working earns method marks\n  - note (string, optional)\n\n"
                . 'Returns the stored entry with its grade conversion where applicable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => ['type' => 'string', 'minLength' => 1],
                    'name'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'kind'    => ['type' => 'string', 'enum' => ['paper', 'check'], 'default' => 'paper'],
                    'score'   => ['type' => 'number', 'minimum' => 0],
                    'max'     => ['type' => 'number', 'minimum' => 1],
                    'tier'    => ['type' => 'string', 'maxLength' => 4, 'default' => 'F'],
                    'date'    => $isoDate,
                    'blanks'  => ['type' => 'integer', 'minimum' => 0],
                    'note'    => ['type' => 'string', 'maxLength' => 1000],
                ],
                'required'   => ['subject', 'name', 'score', 'max'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_create_subject',
            'title' => 'Create or update a subject',
            'description' => "Create a new subject with its strands, grade boundaries and topic list, or update an existing one.\n\n"
                . "Re-running this for an existing subject updates the subject metadata and adds any new topics, but never resets the status of a topic that already exists — progress is not lost by re-seeding.\n\n"
                . "Args:\n  - slug (string): url-safe key, e.g. \"english-language\"\n  - name (string): display name\n  - spec_code, tier, exam_date, notes (optional)\n  - strands (object): strand key to display name, e.g. { \"N\": \"Number\", \"A\": \"Algebra\" }\n  - boundary_max (number, optional, default 240): total marks the boundaries are expressed against\n  - boundaries (object, optional): tier to [[grade, mark], ...], e.g. { \"H\": [[7,164],[6,130]] }\n  - topics (array): each { ref, name, strand, tier?, status?, watch? }\n\n"
                . 'Returns a summary of what was created or added.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug'         => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$', 'maxLength' => 60,
                        'description' => 'Lowercase letters, numbers and hyphens only'],
                    'name'         => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'spec_code'    => ['type' => 'string', 'maxLength' => 60],
                    'tier'         => ['type' => 'string', 'maxLength' => 30],
                    'exam_date'    => $isoDate,
                    'notes'        => ['type' => 'string', 'maxLength' => 2000],
                    'strands'      => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    'boundary_max' => ['type' => 'integer', 'minimum' => 1, 'default' => 240],
                    'boundaries'   => ['type' => 'object', 'additionalProperties' => [
                        'type'  => 'array',
                        'items' => ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 2, 'maxItems' => 2],
                    ]],
                    'topics'       => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 400,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'ref'    => ['type' => 'string', 'minLength' => 1],
                                'name'   => ['type' => 'string', 'minLength' => 1],
                                'strand' => ['type' => 'string', 'minLength' => 1],
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

            if ($ageing) {
                $lines = array_map(
                    static fn($x) => '- **' . $x['t']['ref'] . '** ' . $x['t']['name'] . ' — '
                        . ($x['w'] === null ? 'no date recorded' : $x['w'] . ' weeks since last touched'),
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
                    static fn($t) => '- **' . $t['ref'] . '** ' . $t['name'] . ' (' . $t['strand'] . ', tier ' . $t['tier'] . ')',
                    $gaps
                );
                $parts[] = "\n### Priority gaps (lower tier first)\n" . implode("\n", $lines);
            } else {
                $parts[] = "\n### Priority gaps\nNone — no topic is marked as a gap.";
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
