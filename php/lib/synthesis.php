<?php
/**
 * The weekly learning synthesis: vocabulary, the learner-model rule and the
 * text rendering.
 *
 * The synthesis is the learning half of a week, beside the weekly review's
 * adherence half. It reads the week's audited lesson reviews, signals,
 * errors, retrieval outcomes and attempts, and writes its decisions back
 * where next week's sessions are fed them without asking: hypotheses become
 * test designs on signals, per-subject instructions become week plans the
 * review queue prints, observation items become watch signals, and the
 * learner model is a table that evolves by deltas.
 *
 * Strength is still derived. The prompt's pattern levels map onto the three
 * signal strengths, a learner-model row's status may not exceed what its
 * signals support, and a cross-subject claim needs sessions in two subjects.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

const SYNTH_STAGES     = ['draft', 'parent'];
const SYNTH_WRITTEN_BY = ['routine', 'chat'];

const SYNTH_RETENTION_VERDICTS = ['retaining', 'needs_spacing', 'false_secure'];
const SYNTH_RETENTION_ACTIONS  = ['retrieve', 'reteach', 'none'];
const SYNTH_METHOD_VERDICTS    = ['use', 'use_and_test', 'insufficient'];
const SYNTH_INDEPENDENCE       = ['more', 'less', 'unchanged', 'mixed'];
const SYNTH_MODEL_CHANGES      = ['new', 'strengthened', 'weakened', 'disproved', 'uncertain'];
const SYNTH_MODEL_STATUSES     = ['hypothesis', 'supported', 'established', 'weakened', 'disproved'];
const SYNTH_WEEK_CHANGES       = ['improved', 'unchanged', 'harder', 'new_pattern', 'pattern_stronger',
                                  'hypothesis_unsupported', 'change_worked', 'change_did_not_help'];
const SYNTH_JUDGEMENTS         = ['one_off_untested', 'one_off', 'emerging', 'established'];
const SYNTH_PLANNER            = ['learning_priority', 'teaching_priority', 'retrieve', 'reteach', 'ready',
                                  'use_more', 'use_less', 'test', 'watch', 'success'];
const SYNTH_BIG_PICTURE        = ['coverage', 'secure', 'fragile', 'retention', 'application', 'independence',
                                  'pace', 'coverage_vs_mastery', 'efficiency'];
/** The week plan's fields, in the order the queue prints them. reteach_if is optional. */
const WEEK_PLAN_FIELDS         = ['next_content', 'retrieve_first', 'reteach_if', 'approach', 'scaffolding',
                                  'independent', 'check_for', 'exit_check', 'watch_for'];
/** Who set a signal's current test, and who opened a signal. */
const SIGNAL_SETTERS           = ['review', 'synthesis', 'parent'];
/** The decision the parent records on Friday about the synthesis's changes. */
const SYNTH_VERDICTS           = ['change_worked', 'change_did_not_help', 'mixed', 'untested'];

/**
 * The study principles' headings, so Part 15 can name which principle each
 * stage rests on. The principles themselves live with the skills and are the
 * parent's to revise; this is the list the server checks a `why` against.
 * Overridden by the meta key `study_principles` (a JSON list) when set.
 */
const STUDY_PRINCIPLE_HEADINGS = [
    'One instruction per message',
    'No walls of text',
    'Warm before hard',
    'Never blank',
    'Model then practise',
    'Retrieval first',
    'Organiser',
    'Say it back',
    'Checklist',
    'Chunk break',
];

/** Caps from the prompt. */
const SYNTH_MAX_HYPOTHESES   = 3;
const SYNTH_MAX_PRIORITIES   = 5;
const SYNTH_MAX_PRACTICES    = 5;
const SYNTH_MAX_OBSERVATIONS = 5;
/** Part 9 may name an error type only when this many rows of it exist in the week. */
const SYNTH_ERROR_MIN_ROWS   = 2;

/** Who says they wrote a synthesis, printed as the claim it is. */
function synth_written_by(string $by): string
{
    return $by === 'routine' ? 'written by the Saturday routine' : 'written in chat';
}

/**
 * Whether a learner-model status is allowed by the signals it rests on.
 *
 * `$signals` are hydrated signal rows (strength, status, contradicting).
 * Returns null when the status stands, or the reason when it does not.
 */
function learner_model_status_refusal(string $status, array $signals): ?string
{
    if (!$signals) {
        return 'a learner-model row needs at least one signal to rest on';
    }
    $best = 'one_off';
    $hasContra = false;
    foreach ($signals as $g) {
        if (review_status_rank_of_strength((string) $g['strength']) > review_status_rank_of_strength($best)) {
            $best = (string) $g['strength'];
        }
        if ((int) ($g['contradicting'] ?? 0) > 0 || ($g['status'] ?? '') === 'refuted') {
            $hasContra = true;
        }
    }
    return match ($status) {
        'established' => $best === 'established' ? null
            : "established needs at least one established signal; the strongest is $best",
        'supported'   => in_array($best, ['emerging', 'established'], true) ? null
            : "supported needs at least one emerging signal; the strongest is $best",
        'hypothesis'  => null,
        'weakened', 'disproved' => $hasContra ? null
            : "$status needs a contradicting evidence row or a refuted signal among the signals it rests on",
        default       => "unknown status '$status'",
    };
}

/**
 * The status a Part 12 change produces on a row, given its signals.
 * `uncertain` leaves the status where it was.
 */
function learner_model_next_status(string $change, ?string $current, array $signals): string
{
    switch ($change) {
        case 'new':
            return 'hypothesis';
        case 'strengthened':
            foreach (['established', 'supported'] as $s) {
                if (learner_model_status_refusal($s, $signals) === null) {
                    return $s;
                }
            }
            return 'hypothesis';
        case 'weakened':
            return 'weakened';
        case 'disproved':
            return 'disproved';
        default:
            return $current ?? 'hypothesis';
    }
}

/** The plan's fields as the lines the review queue prints under this_week. */
function week_plan_lines(array $plan): array
{
    $l = [];
    $l[] = '  next content: ' . $plan['next_content'];
    $l[] = '  retrieve first: ' . $plan['retrieve_first'];
    if (!empty($plan['reteach_if'])) {
        $l[] = '  reteach if: ' . $plan['reteach_if'];
    }
    $l[] = '  approach: ' . $plan['approach'];
    $l[] = '  scaffolding: ' . $plan['scaffolding'] . '   independent: ' . $plan['independent'];
    $l[] = '  check: ' . $plan['check_for'] . '   exit: ' . $plan['exit_check'];
    $l[] = '  watch for: ' . $plan['watch_for'];
    return $l;
}

/** The ten planner lines. */
function synth_planner_lines(array $planner): array
{
    $out = [];
    foreach (SYNTH_PLANNER as $k) {
        $out[] = '  ' . $k . ': ' . ($planner[$k] ?? '—');
    }
    return $out;
}

/**
 * The stored synthesis as text, twenty parts in the prompt's order.
 *
 * @return array<int,string>
 */
function synth_render_text(array $s, array $snap): array
{
    $l   = [];
    $ref = static fn(array $x): string => $x['ref'] . ' (' . $x['evidence'] . ')';
    $l[] = '=== WEEKLY LEARNING SYNTHESIS — ' . ($snap['week'] ?? '?') . ' ===';
    $l[] = 'Sessions read: ' . implode(', ', array_map(
        static fn(array $r): string => $r['session_id'] . ' ' . $r['subject_slug'] . ' ' . $r['date'] . ' (' . $r['stage'] . ' v' . $r['version'] . ')',
        $snap['reviews'] ?? []
    ));
    if (!empty($snap['missing'])) {
        $l[] = 'REVIEW MISSING: ' . implode(', ', array_map(static fn(array $m): string => $m['subject_slug'] . ' session ' . $m['id'], $snap['missing']));
    }

    $l[] = '';
    $l[] = '1. AT A GLANCE';
    $l[] = '   ' . ($s['glance']['picture'] ?? '');
    $l[] = '   Most important: ' . ($s['glance']['most_important'] ?? '');

    $l[] = '2. SUBJECT BY SUBJECT';
    foreach ($s['subjects'] ?? [] as $sub) {
        $l[] = '   ' . $sub['slug'] . ' — ' . review_readiness_label($sub['readiness']) . ' — ' . $sub['why'];
        $l[] = '     topics: ' . implode(', ', $sub['topics']);
        foreach (['secure', 'developing', 'fragile', 'gaps'] as $k) {
            if (!empty($sub[$k])) {
                $l[] = '     ' . $k . ': ' . implode('; ', array_map($ref, $sub[$k]));
            }
        }
        $l[] = '     retention: ' . $sub['retention'];
        $l[] = '     independence: ' . $sub['independence'];
        if (!empty($sub['platform_vs_evidence'])) {
            $l[] = '     platform vs evidence: ' . $sub['platform_vs_evidence'];
        }
    }

    $l[] = '3. CROSS-SUBJECT';
    foreach ($s['cross_subject'] ?? [] as $c) {
        $l[] = '   - ' . $c['key'] . ' [' . $c['judgement'] . '] ' . $c['observation'] . ' — ' . $c['evidence']
            . ' (sessions ' . implode(', ', $c['sessions']) . ') → ' . $c['implication'];
    }
    if (empty($s['cross_subject'])) {
        $l[] = '   (none this week)';
    }
    $l[] = '4. WHAT HELPED';
    foreach ($s['helped'] ?? [] as $h) {
        $l[] = '   - ' . $h['method'] . ' [' . $h['verdict'] . '] ' . $h['evidence'] . ' (' . implode(', ', $h['subjects']) . ') — improved: ' . $h['improved'];
    }
    if (empty($s['helped'])) {
        $l[] = '   (none named)';
    }
    $l[] = '5. WHAT HINDERED';
    foreach ($s['hindered'] ?? [] as $h) {
        $l[] = '   - ' . $h['issue'] . ' [' . $h['confidence'] . '] ' . $h['evidence'] . ' → ' . $h['change'];
    }
    if (empty($s['hindered'])) {
        $l[] = '   (none named)';
    }
    $l[] = '6. RETENTION';
    foreach ($s['retention'] ?? [] as $r) {
        $l[] = '   - ' . $r['subject'] . ' ' . $r['ref'] . ' ' . $r['verdict'] . ' — ' . $r['evidence'] . ' → ' . $r['action'];
    }
    if (empty($s['retention'])) {
        $l[] = '   (no verdicts)';
    }
    $l[] = '7. CONFIDENCE';
    if (!array_key_exists('confidence', $s)) {
        $l[] = '   (no evidence — omitted)';
    }
    foreach ($s['confidence'] ?? [] as $c) {
        $l[] = '   - belief: ' . $c['belief'] . ' · performance: ' . $c['performance'] . ' · meaning: ' . $c['meaning']
            . ' → ' . $c['response'] . ' (' . $c['evidence'] . ')';
    }
    $ind = $s['independence'] ?? null;
    $l[] = '8. INDEPENDENCE' . ($ind ? ': ' . $ind['trend'] . ' — ' . $ind['prompts_evidence'] : ': (not assessed)');
    if ($ind) {
        foreach ($ind['fade'] as $f) {
            $l[] = '   fade: ' . $f['support'] . ' — ' . $f['why'];
        }
        foreach ($ind['keep'] as $f) {
            $l[] = '   keep: ' . $f['support'] . ' — ' . $f['why'];
        }
        $l[] = '   ' . $ind['reasoning'];
    }
    $l[] = '9. ERRORS';
    foreach ($s['errors'] ?? [] as $e) {
        $l[] = '   - ' . $e['error_type'] . ': ' . implode('; ', $e['examples']) . ' — ' . $e['explanation'] . ' → ' . $e['response'];
    }
    if (empty($s['errors'])) {
        $l[] = '   (no recurring type this week)';
    }
    $l[] = '10. HYPOTHESES';
    foreach ($s['hypotheses'] ?? [] as $h) {
        $l[] = '   - ' . $h['signal_key'] . ': ' . $h['hypothesis'] . ' — ' . $h['evidence'];
        $l[] = '     how: ' . $h['how'] . ' · collect: ' . implode('; ', $h['collect']);
        $l[] = '     supports if: ' . $h['supports'] . ' · challenges if: ' . $h['challenges'];
    }
    if (empty($s['hypotheses'])) {
        $l[] = '   (none — insufficient evidence)';
    }
    $l[] = '11. LEARNER VOICE';
    foreach ($s['learner_voice']['groups'] ?? [] as $g) {
        $l[] = '   ' . $g['theme'] . ': ' . implode(' | ', array_map(static fn(string $q): string => '"' . $q . '"', $g['quotes']));
    }
    if (!empty($s['learner_voice']['perception_vs_evidence'])) {
        $l[] = '   perception vs evidence: ' . $s['learner_voice']['perception_vs_evidence'];
    }
    if (empty($s['learner_voice']['groups'])) {
        $l[] = '   (nothing quotable this week)';
    }
    $l[] = '12. LEARNER MODEL — changes';
    foreach ($s['model'] ?? [] as $m) {
        $l[] = '   - ' . $m['key'] . ' [' . $m['change'] . '] ' . $m['statement'] . ' (signals ' . implode(', ', $m['signal_keys']) . ')'
            . (!empty($m['note']) ? ' — ' . $m['note'] : '');
    }
    if (empty($s['model'])) {
        $l[] = '   (no change)';
    }
    $l[] = '13. PRIORITIES';
    foreach ($s['priorities'] ?? [] as $p) {
        $l[] = '   ' . $p['rank'] . '. ' . $p['priority'] . ' — ' . $p['why'] . ' (' . $p['evidence'] . ') → ' . $p['action'];
    }
    $l[] = '14. WEEK PLANS';
    foreach ($s['week_plans'] ?? [] as $p) {
        $l[] = '   ' . $p['subject_slug'];
        foreach (week_plan_lines($p) as $line) {
            $l[] = '   ' . $line;
        }
    }
    $arch = $s['architecture'] ?? [];
    $l[] = '15. LESSON ARCHITECTURE' . (empty($arch['sufficient_evidence']) ? ' (insufficient evidence)' : '');
    foreach ($arch['stages'] ?? [] as $st) {
        $l[] = '   ' . $st['stage'] . ' — ' . $st['why'];
    }
    $l[] = '16. STOP / START / CONTINUE';
    foreach (['stop', 'start', 'continue'] as $k) {
        foreach ($s['stop_start_continue'][$k] ?? [] as $x) {
            $l[] = '   ' . $k . ': ' . $x['practice'] . (!empty($x['method']) ? ' [' . $x['method'] . ']' : '') . ' — ' . $x['why'];
        }
    }
    $l[] = '17. OBSERVE NEXT WEEK';
    foreach ($s['observe'] ?? [] as $o) {
        $l[] = '   - ' . $o['key'] . ': ' . $o['look_for'] . ' — ' . $o['why'];
    }
    if (empty($s['observe'])) {
        $l[] = '   (none)';
    }
    $l[] = '18. BIG PICTURE' . (!empty($s['big_picture']['grade_basis']) ? ' — ' . $s['big_picture']['grade_basis'] : '');
    foreach (SYNTH_BIG_PICTURE as $k) {
        $l[] = '   ' . $k . ': ' . ($s['big_picture'][$k] ?? '—');
    }
    $l[] = '19. PLANNER';
    foreach (synth_planner_lines($s['planner'] ?? []) as $line) {
        $l[] = $line;
    }
    $l[] = '20. CHANGE FROM LAST WEEK';
    foreach ($s['change'] ?? [] as $c) {
        $l[] = '   - [' . $c['category'] . '] ' . $c['what'] . ' — ' . $c['evidence'];
    }
    if (isset($s['changes_worked'])) {
        $l[] = '   Did the changes work: ' . $s['changes_worked'];
    }
    if (empty($s['change']) && !isset($s['changes_worked'])) {
        $l[] = '   (no previous synthesis to compare against)';
    }
    $l[] = 'COLLECT NEXT WEEK: ' . (!empty($s['collect_next_week']) ? implode(' | ', $s['collect_next_week']) : 'nothing listed');
    $l[] = '=== END ===';
    return $l;
}
