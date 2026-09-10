<?php
/**
 * Lesson reviews: the vocabulary, the strength rule and the text rendering.
 *
 * A lesson review is the written half of one taught session, the way the
 * weekly review is the written half of one week. It is versioned, staged,
 * signed and frozen against a server-built snapshot, and its nineteen
 * sections are held as fields rather than prose so the service can validate
 * them and the pages can render them without parsing anything.
 *
 * Two things here are rules rather than conventions. A *signal* — an
 * observation about how the learner learns or how a teaching method lands —
 * may not claim a strength the evidence rows do not support: one_off needs one
 * supporting session, emerging two distinct sessions, established three and no
 * uncontradicted contradiction newer than the last support. And a review never
 * moves a topic: statuses move only through a session's updates[], and the
 * review's `proposed_status` is a proposal for the tracker skill to adjudicate.
 *
 * The enums below are the prompt's own lists, re-mapped onto the tracker's
 * vocabulary. mcp.php validates against them; the store checks the strength
 * rule; dashboard.php renders from the same field names.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const REVIEW_STAGES     = ['draft', 'audited', 'parent'];
const REVIEW_WRITTEN_BY = ['session', 'audit', 'chat'];

const SIGNAL_KINDS      = ['learning_process', 'teaching_method', 'misconception', 'confidence', 'retention', 'watch'];
const SIGNAL_STRENGTHS  = ['one_off', 'emerging', 'established'];
const SIGNAL_STATUSES   = ['open', 'resolved', 'refuted'];
const SIGNAL_DIRECTIONS = ['supports', 'contradicts'];
/** Distinct supporting sessions each strength needs. */
const SIGNAL_STRENGTH_NEEDS = ['one_off' => 1, 'emerging' => 2, 'established' => 3];

const REVIEW_ERROR_TYPES = [
    'knowledge_gap', 'misconception', 'forgotten_prior', 'procedure', 'calculation', 'vocabulary',
    'instruction_misread', 'missed_information', 'working_memory', 'sequencing', 'rushed',
    'transcription', 'unchecked', 'right_reasoning_wrong_execution', 'undetermined',
];

const REVIEW_PROCESS_AREAS = [
    'readiness', 'task_initiation', 'instructions', 'attention', 'persistence', 'response_to_error',
    'retry', 'self_correction', 'checking', 'organisation', 'use_of_notes', 'use_of_examples',
    'resource_selection', 'help_seeking', 'independence', 'processing_time', 'cognitive_load', 'pace',
    'accuracy', 'recall', 'confidence', 'frustration', 'avoidance', 'resilience', 'metacognition',
    'transfer', 'uncued_retrieval',
];

const REVIEW_BASES = ['observed', 'interpretation', 'unknown'];

const REVIEW_METHODS = [
    'direct_explanation', 'short_text', 'long_text', 'video', 'diagram', 'visual_model',
    'worked_example', 'modelling', 'step_by_step', 'discovery', 'questioning', 'retrieval_questions',
    'multiple_choice', 'scaffolded_task', 'independent_task', 'immediate_feedback', 'delayed_feedback',
    'repetition', 'interleaving', 'quiz', 'writing', 'speaking', 'practical', 'note_taking', 'copying',
    'timed_task', 'organiser', 'say_it_back', 'checklist', 'chunk_break',
];

const REVIEW_EFFECTS    = ['helpful', 'difficulty', 'neutral'];
const REVIEW_READINESS  = ['progress', 'progress_with_retrieval', 'consolidate', 'partial_reteach', 'significant_reteach'];
const REVIEW_NEXT_WHAT  = ['opening_retrieval', 'reteach', 'consolidate', 'new', 'misconception_check', 'challenge'];
/** Lesson stages: the reviews' next_how and the synthesis's Part 15 architecture share this one enum. */
const REVIEW_STAGE_KEYS = ['start', 'orientate', 'teach', 'model', 'check', 'practise_scaffolded',
                           'practise_independent', 'review', 'exit', 'finish'];
const REVIEW_PLANNER    = ['priority', 'start_with', 'teach_using', 'avoid', 'check_whether', 'success'];
/** Retention lists and the retrieval_outcome each one must be backed by. */
const REVIEW_RETENTION  = ['retrieved' => 'correct', 'prompted' => 'retry', 'not_retrieved' => 'incorrect'];
const REVIEW_LEVELS     = ['high', 'low'];

/** A session with no block that ran at least this long needs a review. */
const REVIEW_EXTRA_MINUTES = 30;
/** A signal's next_test left untested for this many sessions is flagged by the audit. */
const REVIEW_TEST_STALE_SESSIONS = 3;
/** A watch signal never referenced again after this many sessions is flagged by the audit. */
const REVIEW_WATCH_STALE_SESSIONS = 5;

/** The prompt's readiness word, for a chip or a line. */
function review_readiness_label(string $r): string
{
    return match ($r) {
        'progress'                => 'ready to progress',
        'progress_with_retrieval' => 'progress, with retrieval',
        'consolidate'             => 'consolidate',
        'partial_reteach'         => 'partial re-teach',
        'significant_reteach'     => 'significant re-teach',
        default                   => $r,
    };
}

/** Who says they wrote a lesson review, printed as the claim it is. */
function review_written_by(string $by): string
{
    return match ($by) {
        'session' => 'written by the session',
        'audit'   => 'written by the audit',
        default   => 'written in chat',
    };
}

/**
 * Whether a strength claim is allowed by a signal's evidence rows.
 *
 * `$evidence` is the rows for one signal, each with direction and the
 * session's date and id. Returns null when the claim stands, or the reason
 * with the counts when it does not — the caller refuses with it rather than
 * quietly downgrading.
 *
 * @param array<int,array{direction:string,date:string,session_id:int}> $evidence
 */
function signal_strength_refusal(string $strength, array $evidence): ?string
{
    $supports    = [];
    $lastSupport = null;
    $contraAfter = null;
    usort($evidence, static fn(array $x, array $y): int =>
        [$x['date'], $x['session_id']] <=> [$y['date'], $y['session_id']]);
    foreach ($evidence as $e) {
        if ($e['direction'] === 'supports') {
            $supports[(int) $e['session_id']] = true;
            $lastSupport = $e;
            $contraAfter = null;
        } else {
            $contraAfter = $e;
        }
    }
    $n    = count($supports);
    $need = SIGNAL_STRENGTH_NEEDS[$strength] ?? 1;
    if ($n < $need) {
        return "$strength needs $need distinct supporting session" . ($need === 1 ? '' : 's')
            . ", and this signal has $n";
    }
    if ($strength === 'established' && $contraAfter !== null) {
        return 'established needs no uncontradicted contradiction newer than the last support, and session '
            . $contraAfter['session_id'] . ' (' . $contraAfter['date'] . ') contradicts it'
            . ($lastSupport ? ' after the last support in session ' . $lastSupport['session_id'] : '');
    }
    return null;
}

/** The highest strength the evidence rows allow; what the record would derive on its own. */
function signal_strength_allowed(array $evidence): string
{
    $best = 'one_off';
    foreach (SIGNAL_STRENGTHS as $s) {
        if (signal_strength_refusal($s, $evidence) === null) {
            $best = $s;
        }
    }
    return $best;
}

/**
 * The stored review as text, in the prompt's output order. Every reader —
 * the tool, the audit, the page's plain view — gets the same layout from
 * this one function.
 *
 * @param array<string,mixed> $s   sections_json, decoded
 * @param array<string,mixed> $snap snapshot_json, decoded
 * @return array<int,string>
 */
function review_render_text(array $s, array $snap): array
{
    $l = [];
    $sess = $snap['session'] ?? [];
    $l[] = '=== LESSON REVIEW — ' . ($sess['date'] ?? '?') . ' — ' . ($sess['subject_slug'] ?? '?')
        . ' — block ' . (isset($sess['block_key']) && $sess['block_key'] !== null
            ? $sess['block_key'] . ($sess['block_kind'] ? ' (' . $sess['block_kind'] . ')' : '')
            : 'extra')
        . ' — ' . ($sess['duration_minutes'] !== null && $sess['duration_minutes'] !== ''
            ? $sess['duration_minutes'] . ' min' : 'no duration') . ' ===';
    if (!empty($s['topic_refs'])) {
        $l[] = 'TOPICS: ' . implode(', ', $s['topic_refs']);
    }
    if (!empty($s['objective'])) {
        $l[] = 'OBJECTIVE: ' . $s['objective'];
    }
    if (!empty($s['resources'])) {
        $l[] = 'RESOURCES: ' . implode('; ', array_map(
            static fn(array $r): string => $r['title'] . (!empty($r['unlisted']) ? ' (unlisted)' : ''),
            $s['resources']
        ));
    }
    $l[] = '';
    $l[] = '1. ONE SENTENCE: ' . ($s['one_sentence'] ?? '');

    $l[] = '2. PROGRESS';
    foreach ($s['progress'] ?? [] as $p) {
        $l[] = '   - ' . $p['ref'] . ' — seen ' . $p['status_seen']
            . (isset($p['proposed_status']) ? ' — PROPOSED ' . $p['proposed_status'] : '')
            . ' — ' . $p['evidence'] . ' → ' . $p['implication'];
    }
    if (empty($s['progress'])) {
        $l[] = '   (none)';
    }
    $l[] = '3. INDEPENDENT: ' . ($s['independent'] ?? '');
    $l[] = '4. SUPPORTED: ' . ($s['supported'] ?? '');

    $l[] = '5. ERRORS';
    foreach ($s['errors'] ?? [] as $e) {
        $l[] = '   - ' . $e['ref'] . ' ' . $e['error_type'] . ' — ' . $e['what'] . ' — why: ' . $e['why_type']
            . ' → ' . $e['response'];
    }
    if (empty($s['errors'])) {
        $l[] = '   (none recorded)';
    }

    $l[] = '6. RETENTION';
    $ret = $s['retention'] ?? [];
    foreach (['retrieved' => 'retrieved', 'prompted' => 'prompted', 'not_retrieved' => 'not retrieved',
              'schedule' => 'schedule'] as $key => $word) {
        $items = $ret[$key] ?? [];
        $l[] = '   ' . $word . ': ' . ($items
            ? implode('; ', array_map(static fn(array $x): string => $x['ref'] . ' (' . $x['evidence'] . ')', $items))
            : '—');
    }

    $l[] = '7. PROCESS';
    foreach ($s['process'] ?? [] as $p) {
        $l[] = '   - ' . $p['area'] . ' [' . $p['basis'] . '] — ' . $p['evidence'] . ' — ' . $p['interpretation']
            . ' → ' . $p['implication'];
    }
    if (empty($s['process'])) {
        $l[] = '   (none)';
    }
    $l[] = '8. HELPED: ' . (!empty($s['helped'])
        ? implode('; ', array_map(static fn(array $h): string => $h['method'] . ' (' . $h['effect'] . ') — ' . $h['evidence'], $s['helped']))
        : '—');
    $l[] = '9. HINDERED: ' . (!empty($s['hindered'])
        ? implode('; ', array_map(static fn(array $h): string => $h['method'] . ' (' . $h['effect'] . ') — ' . $h['evidence'], $s['hindered']))
        : '—');

    $l[] = '10. CONFIDENCE';
    if (!array_key_exists('confidence', $s)) {
        $l[] = '   (no evidence — section omitted)';
    } elseif (!$s['confidence']) {
        $l[] = '   (no evidence)';
    }
    foreach ($s['confidence'] ?? [] as $c) {
        $l[] = '   - ' . $c['ref'] . ' confidence ' . $c['confidence'] . ', accuracy ' . $c['accuracy'] . ' — '
            . $c['evidence'] . ' → ' . $c['implication'];
    }

    $l[] = '11. SIGNALS';
    foreach ($s['signals'] ?? [] as $g) {
        $l[] = '   - ' . $g['key'] . ' [' . $g['kind'] . ', ' . $g['strength'] . ', ' . ($g['direction'] ?? 'supports')
            . '] ' . $g['statement'] . ' — ' . $g['evidence']
            . (!empty($g['next_test']) ? ' — next test: ' . $g['next_test'] : '');
    }
    if (empty($s['signals'])) {
        $l[] = '   (none)';
    }

    $bp = $s['big_picture'] ?? [];
    $l[] = '12. BIG PICTURE: ' . review_readiness_label((string) ($bp['readiness'] ?? '')) . ' — ' . ($bp['why'] ?? '');

    $l[] = '13. NEXT — WHAT';
    foreach (REVIEW_NEXT_WHAT as $k) {
        $refs = $s['next_what'][$k] ?? [];
        if ($refs) {
            $l[] = '   ' . $k . ': ' . implode(', ', $refs);
        }
    }
    $l[] = '14. NEXT — HOW';
    foreach ($s['next_how']['stages'] ?? [] as $st) {
        $l[] = '   ' . $st['stage'] . ': ' . $st['method'] . ' — ' . $st['why'];
    }
    if (empty($s['next_how']['stages'])) {
        $l[] = '   (not planned)';
    }
    $l[] = '15. DO DIFFERENTLY: ' . (!empty($s['do_differently']) ? implode(' | ', $s['do_differently']) : '—');
    $l[] = '16. CONTINUE: ' . (!empty($s['continue']) ? implode(' | ', $s['continue']) : '—');
    $l[] = '17. WATCH: ' . (!empty($s['watch'])
        ? implode(' | ', array_map(static fn(array $w): string => $w['key'] . ' — ' . $w['what_to_observe'], $s['watch']))
        : '—');
    $l[] = '18. LEARNER VOICE: ' . (!empty($s['learner_voice'])
        ? implode(' | ', array_map(static fn(array $v): string => '"' . $v['quote'] . '"'
            . (!empty($v['context']) ? ' (' . $v['context'] . ')' : ''), $s['learner_voice']))
        : '(none — nothing quotable was said)');

    $l[] = '19. PLANNER';
    foreach (REVIEW_PLANNER as $k) {
        $l[] = '   ' . $k . ': ' . ($s['planner'][$k] ?? '');
    }
    $l[] = 'MISSING EVIDENCE: ' . (!empty($s['missing_evidence']) ? implode(' | ', $s['missing_evidence']) : 'none listed');
    $l[] = '=== END ===';
    return $l;
}

/** The planner as the six lines the next session reads first. */
function review_planner_lines(array $planner): array
{
    $pairs = [['priority', 'start_with'], ['teach_using', 'avoid'], ['check_whether', 'success']];
    $out   = [];
    foreach ($pairs as [$a, $b]) {
        $out[] = '  ' . $a . ': ' . ($planner[$a] ?? '—');
        $out[] = '  ' . $b . ': ' . ($planner[$b] ?? '—');
    }
    return $out;
}

/** The planner as one paragraph, for sessions.next_steps when the caller left it empty. */
function review_planner_text(array $planner): string
{
    $bits = [];
    foreach (REVIEW_PLANNER as $k) {
        if (!empty($planner[$k])) {
            $bits[] = str_replace('_', ' ', ucfirst($k)) . ': ' . $planner[$k];
        }
    }
    return implode(' ', $bits);
}

/** One signal row as the line the queue and the tools print. */
function signal_line(array $g): string
{
    $n = (int) ($g['supporting'] ?? 0);
    return '[' . $g['strength'] . ', ' . $n . ' session' . ($n === 1 ? '' : 's')
        . ($g['status'] !== 'open' ? ', ' . $g['status'] : '') . '] '
        . ($g['subject_slug'] === null ? '(cross-subject) ' : '')
        . $g['kind'] . ' ' . $g['key'] . ': ' . $g['statement']
        . (!empty($g['next_test']) ? ' — next test: ' . $g['next_test'] : '')
        . ' (#' . $g['id'] . ')';
}

/** Rank of a strength in SIGNAL_STRENGTHS: one_off 0, emerging 1, established 2. */
function review_status_rank_of_strength(string $strength): int
{
    $i = array_search($strength, SIGNAL_STRENGTHS, true);
    return $i === false ? 0 : (int) $i;
}

/** Rank in STATUS_ORDER, or -1 for an unknown or missing status. */
function review_status_rank(?string $status): int
{
    $i = array_search((string) $status, STATUS_ORDER, true);
    return $i === false ? -1 : (int) $i;
}
