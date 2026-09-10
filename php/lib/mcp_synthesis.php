<?php
/**
 * The weekly synthesis's validation and write, and the blocks other tools
 * print from it: the queue's this_week, the week report's decisions.
 *
 * Validation is whole-or-nothing and names the part and field. The rules
 * the server owns (§6 of docs/weekly-synthesis.md) are enforced here or in
 * the store; the two it cannot — no diagnosis, no learning-style labels —
 * are the skill's and the parent's.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** Sentences in a paragraph: terminators followed by space or the end. */
function mcp_sy_sentences(string $s): int
{
    return preg_match_all('/[.!?]["”’)]?(\s|$)/u', trim($s));
}

/**
 * The twenty parts, validated whole against the week's inputs.
 *
 * @param array $inputs Store::synthesisInputs()
 * @return array<string,mixed> the cleaned sections
 */
function mcp_synth_sections(Store $store, string $week, mixed $raw, array $inputs): array
{
    if (!is_array($raw) || ($raw && array_is_list($raw))) {
        throw new McpError('sections must be an object holding the twenty parts (glance, subjects, cross_subject, helped, '
            . 'hindered, retention, confidence, independence, errors, hypotheses, learner_voice, model, priorities, '
            . 'week_plans, architecture, stop_start_continue, observe, big_picture, planner, change, collect_next_week). '
            . 'Nothing was written.');
    }
    $known = ['glance', 'subjects', 'cross_subject', 'helped', 'hindered', 'retention', 'confidence', 'independence',
              'errors', 'hypotheses', 'learner_voice', 'model', 'priorities', 'week_plans', 'architecture',
              'stop_start_continue', 'observe', 'big_picture', 'planner', 'change', 'changes_worked', 'collect_next_week'];
    foreach (array_keys($raw) as $k) {
        if (!in_array($k, $known, true)) {
            throw new McpError("sections has an unknown key \"$k\". The keys are: " . implode(', ', $known) . '. Nothing was written.');
        }
    }
    $at  = 'sections';
    $out = [];

    // What the week holds, from the inputs.
    $subjects = [];
    foreach ($store->listSubjects() as $s) {
        $subjects[$s['slug']] = array_column($store->listTopics($s['slug']), 'ref');
    }
    $taughtSubjects = [];
    $sessionIds     = [];
    $quotes         = [];
    $confRefs       = [];
    $retentionRefs  = [];
    foreach ($inputs['sessions'] as $s) {
        $taughtSubjects[(string) $s['subject_slug']] = true;
        $sessionIds[(int) $s['id']] = (string) $s['subject_slug'];
    }
    foreach ($inputs['reviews'] as $sid => $rv) {
        $slug = $sessionIds[(int) $sid] ?? null;
        foreach ($rv['sections']['learner_voice'] ?? [] as $v) {
            $quotes[trim((string) $v['quote'])] = true;
        }
        foreach ($rv['sections']['confidence'] ?? [] as $c) {
            $confRefs[(string) $c['ref']] = true;
        }
        foreach ($rv['sections']['retention'] ?? [] as $list) {
            foreach ($list as $x) {
                $retentionRefs[$slug . '#' . $x['ref']] = true;
            }
        }
    }
    $signalKeys = [];
    foreach ($inputs['signals'] as $g) {
        $signalKeys[(string) $g['key']] = true;
    }
    foreach ($store->signals([]) as $g) {
        $signalKeys[(string) $g['key']] = true;
    }
    $opened = [];

    $ref = static function (string $slug, mixed $r, string $where) use ($subjects): string {
        $r = trim((string) $r);
        if ($r === '' || !in_array($r, $subjects[$slug] ?? [], true)) {
            throw new McpError("$where names \"$r\", which is not a topic in $slug. Nothing was written.");
        }
        return $r;
    };
    $slugOk = static function (mixed $v, string $where) use ($subjects): string {
        $v = trim((string) $v);
        if (!isset($subjects[$v])) {
            throw new McpError("$where names subject \"$v\", which is not tracked. Known: " . implode(', ', array_keys($subjects))
                . '. Nothing was written.');
        }
        return $v;
    };
    $refEvidence = static function (array $o, string $key, string $where, string $slug) use ($ref): array {
        $list = [];
        foreach (mcp_rv_list($o, $key, $where, 30) as $i => $x) {
            $x = mcp_rv_object($x, "$where.$key" . "[$i]");
            $list[] = ['ref' => $ref($slug, $x['ref'] ?? '', "$where.$key" . "[$i].ref"),
                       'evidence' => mcp_rv_text($x, 'evidence', "$where.$key" . "[$i]", true, 5, 400)];
        }
        return $list;
    };

    // 1
    $g = mcp_rv_object($raw['glance'] ?? null, "$at.glance");
    $picture = mcp_rv_text($g, 'picture', "$at.glance", true, 40, 1200);
    $n = mcp_sy_sentences($picture);
    if ($n < 4 || $n > 6) {
        throw new McpError("$at.glance.picture is $n sentences; it must be 4 to 6. Nothing was written.");
    }
    $out['glance'] = ['picture' => $picture, 'most_important' => mcp_rv_text($g, 'most_important', "$at.glance", true, 10, 300)];

    // 2 — one per subject with a taught session this week, no more, no fewer.
    $subs = [];
    $seen = [];
    foreach (mcp_rv_list($raw, 'subjects', $at, 12) as $i => $x) {
        $w    = "$at.subjects[$i]";
        $x    = mcp_rv_object($x, $w);
        $slug = $slugOk($x['slug'] ?? '', "$w.slug");
        if (!isset($taughtSubjects[$slug])) {
            throw new McpError("$w is for $slug, which had no taught session in $week. Part 2 covers the subjects taught. Nothing was written.");
        }
        if (isset($seen[$slug])) {
            throw new McpError("$w repeats $slug. Nothing was written.");
        }
        $seen[$slug] = true;
        $topics = [];
        foreach (mcp_rv_list($x, 'topics', $w, 30) as $j => $r) {
            $topics[] = $ref($slug, $r, "$w.topics[$j]");
        }
        $entry = ['slug' => $slug, 'topics' => array_values(array_unique($topics))];
        foreach (['secure', 'developing', 'fragile', 'gaps'] as $k) {
            $entry[$k] = $refEvidence($x, $k, $w, $slug);
        }
        $entry['retention']    = mcp_rv_text($x, 'retention', $w, true, 10, 800);
        $entry['independence'] = mcp_rv_text($x, 'independence', $w, true, 10, 800);
        $entry['readiness']    = mcp_rv_enum($x, 'readiness', $w, REVIEW_READINESS);
        $entry['why']          = mcp_rv_text($x, 'why', $w, true, 10, 800);
        $pve = mcp_rv_text($x, 'platform_vs_evidence', $w, false, 10, 800);
        if ($pve !== null) {
            $entry['platform_vs_evidence'] = $pve;
        }
        $subs[] = $entry;
    }
    $missingSubs = array_diff(array_keys($taughtSubjects), array_keys($seen));
    if ($missingSubs) {
        throw new McpError("$at.subjects has no entry for " . implode(', ', $missingSubs) . ', which had a taught session in '
            . "$week. Every taught subject gets a Part 2 entry. Nothing was written.");
    }
    $out['subjects'] = $subs;

    // 3 — cross-subject means sessions in at least two subjects.
    $cross = [];
    foreach (mcp_rv_list($raw, 'cross_subject', $at, 12) as $i => $x) {
        $w   = "$at.cross_subject[$i]";
        $x   = mcp_rv_object($x, $w);
        $key = mcp_rv_signal_key($x['key'] ?? null, "$w.key");
        $ids = [];
        $subjOf = [];
        foreach (mcp_rv_list($x, 'sessions', $w, 30) as $j => $sid) {
            if (!is_int($sid) && !(is_numeric($sid) && (int) $sid == $sid)) {
                throw new McpError("$w.sessions[$j] must be a session id. Nothing was written.");
            }
            $row = $store->sessionById((int) $sid);
            if (!$row || $row['void_reason'] !== null) {
                throw new McpError("$w.sessions names session $sid, which does not exist or is void. Nothing was written.");
            }
            $ids[] = (int) $sid;
            $subjOf[(string) $row['subject_slug']] = true;
        }
        $judgement = mcp_rv_enum($x, 'judgement', $w, SYNTH_JUDGEMENTS);
        if (in_array($judgement, ['emerging', 'established'], true) && count($subjOf) < 2) {
            throw new McpError("$w claims $judgement across subjects but its sessions are all in "
                . (array_keys($subjOf)[0] ?? 'no subject') . '. Cross-subject means sessions in at least two subjects; '
                . 'cite them, or judge it one_off. Nothing was written.');
        }
        if (!$ids) {
            throw new McpError("$w cites no sessions. Nothing was written.");
        }
        // The strength the rows would allow once this synthesis's evidence is added.
        $rows = [];
        foreach ($store->signalsByKeyAnySubject($key) as $g) {
            foreach ($store->signalEvidence($g['id']) as $e) {
                $rows[$e['session_id']] = ['direction' => $e['direction'], 'date' => $e['date'], 'session_id' => $e['session_id']];
            }
        }
        foreach ($ids as $sid) {
            $rows[$sid] = ['direction' => 'supports', 'date' => (string) $store->sessionById($sid)['date'], 'session_id' => $sid];
        }
        $claim = $judgement === 'one_off_untested' ? 'one_off' : $judgement;
        $why   = signal_strength_refusal($claim, array_values($rows));
        if ($why !== null) {
            throw new McpError("$w judges $key $judgement, but $why. Nothing was written.");
        }
        $opened[$key] = true;
        $cross[] = [
            'key'         => $key,
            'observation' => mcp_rv_text($x, 'observation', $w, true, 10, 800),
            'evidence'    => mcp_rv_text($x, 'evidence', $w, true, 10, 800),
            'sessions'    => array_values(array_unique($ids)),
            'subjects'    => array_keys($subjOf),
            'judgement'   => $judgement,
            'implication' => mcp_rv_text($x, 'implication', $w, true, 10, 800),
        ];
    }
    $out['cross_subject'] = $cross;

    // 4, 5
    $helped = [];
    foreach (mcp_rv_list($raw, 'helped', $at, 12) as $i => $x) {
        $w = "$at.helped[$i]";
        $x = mcp_rv_object($x, $w);
        $slugs = [];
        foreach (mcp_rv_list($x, 'subjects', $w, 12) as $j => $sl) {
            $slugs[] = $slugOk($sl, "$w.subjects[$j]");
        }
        $helped[] = ['method' => mcp_rv_enum($x, 'method', $w, REVIEW_METHODS), 'evidence' => mcp_rv_text($x, 'evidence', $w),
                     'subjects' => array_values(array_unique($slugs)), 'improved' => mcp_rv_text($x, 'improved', $w),
                     'verdict' => mcp_rv_enum($x, 'verdict', $w, SYNTH_METHOD_VERDICTS)];
    }
    $out['helped'] = $helped;
    $hindered = [];
    foreach (mcp_rv_list($raw, 'hindered', $at, 12) as $i => $x) {
        $w = "$at.hindered[$i]";
        $x = mcp_rv_object($x, $w);
        $hindered[] = ['issue' => mcp_rv_text($x, 'issue', $w), 'evidence' => mcp_rv_text($x, 'evidence', $w),
                       'confidence' => mcp_rv_enum($x, 'confidence', $w, SIGNAL_STRENGTHS), 'change' => mcp_rv_text($x, 'change', $w)];
    }
    $out['hindered'] = $hindered;

    // 6 — retention over completion.
    $retention = [];
    foreach (mcp_rv_list($raw, 'retention', $at, 40) as $i => $x) {
        $w    = "$at.retention[$i]";
        $x    = mcp_rv_object($x, $w);
        $slug = $slugOk($x['subject'] ?? '', "$w.subject");
        $r    = $ref($slug, $x['ref'] ?? '', "$w.ref");
        $row  = $store->db->prepare("SELECT * FROM retrieval_state WHERE subject_slug = ? AND grain = 'topic' AND key = ?");
        $row->execute([$slug, $r]);
        $state = $row->fetch() ?: null;
        if ($state === null && !isset($retentionRefs["$slug#$r"])) {
            throw new McpError("$w judges retention on $slug $r, which has no retrieval record and no lesson-review retention "
                . 'entry this week. A retention verdict needs retrieval evidence. Nothing was written.');
        }
        $verdict = mcp_rv_enum($x, 'verdict', $w, SYNTH_RETENTION_VERDICTS);
        if ($verdict === 'retaining' && $state !== null) {
            // Retaining means retrieved without support after a delay: a
            // wrong streak, or a last outcome of retry or incorrect, says
            // otherwise whatever the lesson felt like.
            $history = json_decode((string) $state['history'], true) ?: [];
            $last    = $history ? (string) ($history[count($history) - 1]['o'] ?? '') : '';
            if ((int) $state['consecutive_wrong'] > 0 || in_array($last, ['retry', 'incorrect'], true)) {
                throw new McpError("$w calls $r retaining, but its retrieval record shows "
                    . ((int) $state['consecutive_wrong'] > 0 ? (int) $state['consecutive_wrong'] . ' consecutive wrong' : "a last outcome of $last")
                    . '. Nothing was written.');
            }
        }
        $retention[] = ['ref' => $r, 'subject' => $slug, 'verdict' => $verdict, 'evidence' => mcp_rv_text($x, 'evidence', $w),
                        'action' => mcp_rv_enum($x, 'action', $w, SYNTH_RETENTION_ACTIONS)];
    }
    $out['retention'] = $retention;

    // 7 — omitted when there is no evidence.
    if (array_key_exists('confidence', $raw) && $raw['confidence'] !== null) {
        $conf = [];
        foreach (mcp_rv_list($raw, 'confidence', $at, 12) as $i => $x) {
            $w = "$at.confidence[$i]";
            $x = mcp_rv_object($x, $w);
            $ev = mcp_rv_text($x, 'evidence', $w);
            $cites = preg_match('/\bsession \d+\b/i', $ev) === 1;
            foreach (array_keys($confRefs) as $cr) {
                $cites = $cites || str_contains($ev, $cr);
            }
            foreach (array_keys($quotes) as $q) {
                $cites = $cites || ($q !== '' && str_contains($ev, $q));
            }
            if (!$cites) {
                throw new McpError("$w.evidence must cite a lesson review's confidence entry (its ref), a learner_voice quote, "
                    . 'or "session N". Nothing was written.');
            }
            $conf[] = ['belief' => mcp_rv_text($x, 'belief', $w), 'performance' => mcp_rv_text($x, 'performance', $w),
                       'meaning' => mcp_rv_text($x, 'meaning', $w), 'response' => mcp_rv_text($x, 'response', $w), 'evidence' => $ev];
        }
        $out['confidence'] = $conf;
    }

    // 8
    if (array_key_exists('independence', $raw) && $raw['independence'] !== null) {
        $w = "$at.independence";
        $x = mcp_rv_object($raw['independence'], $w);
        $sup = static function (array $o, string $key) use ($w): array {
            $list = [];
            foreach (mcp_rv_list($o, $key, $w, 10) as $i => $f) {
                $f = mcp_rv_object($f, "$w.$key" . "[$i]");
                $list[] = ['support' => mcp_rv_text($f, 'support', "$w.$key" . "[$i]", true, 3, 300),
                           'why' => mcp_rv_text($f, 'why', "$w.$key" . "[$i]", true, 5, 400)];
            }
            return $list;
        };
        $out['independence'] = ['trend' => mcp_rv_enum($x, 'trend', $w, SYNTH_INDEPENDENCE),
            'prompts_evidence' => mcp_rv_text($x, 'prompts_evidence', $w), 'fade' => $sup($x, 'fade'), 'keep' => $sup($x, 'keep'),
            'reasoning' => mcp_rv_text($x, 'reasoning', $w)];
    }

    // 9 — do not claim a recurring pattern.
    $errors = [];
    foreach (mcp_rv_list($raw, 'errors', $at, 15) as $i => $x) {
        $w    = "$at.errors[$i]";
        $x    = mcp_rv_object($x, $w);
        $type = mcp_rv_enum($x, 'error_type', $w, REVIEW_ERROR_TYPES);
        $n    = count($inputs['errors'][$type] ?? []);
        if ($n < SYNTH_ERROR_MIN_ROWS) {
            throw new McpError("$w names $type as a recurring error, but only $n error row" . ($n === 1 ? '' : 's')
                . " of that type " . ($n === 1 ? 'was' : 'were') . " recorded in $week (" . SYNTH_ERROR_MIN_ROWS
                . ' needed). One occurrence is not a pattern. Nothing was written.');
        }
        $errors[] = ['error_type' => $type, 'examples' => mcp_rv_strings($x, 'examples', $w, 10, 5, 400),
                     'explanation' => mcp_rv_text($x, 'explanation', $w), 'response' => mcp_rv_text($x, 'response', $w)];
    }
    $out['errors'] = $errors;

    // 15 first: its sufficient_evidence flag decides whether 10 may be empty.
    $arch = mcp_rv_object($raw['architecture'] ?? [], "$at.architecture");
    $sufficient = array_key_exists('sufficient_evidence', $arch) ? (bool) $arch['sufficient_evidence'] : true;
    $stages = [];
    $principles = $inputs['principles'];
    foreach (mcp_rv_list($arch, 'stages', "$at.architecture", 12) as $i => $st) {
        $w  = "$at.architecture.stages[$i]";
        $st = mcp_rv_object($st, $w);
        $why = mcp_rv_text($st, 'why', $w, true, 5, 400);
        $named = preg_match('/evidence this week: session \d+/i', $why) === 1;
        foreach ($principles as $ph) {
            $named = $named || stripos($why, $ph) !== false;
        }
        if (!$named) {
            throw new McpError("$w.why must name a study-principle heading (" . implode('; ', $principles)
                . ') or say "evidence this week: session N". Nothing was written.');
        }
        $stages[] = ['stage' => mcp_rv_enum($st, 'stage', $w, REVIEW_STAGE_KEYS), 'why' => $why];
    }
    if ($sufficient && !$stages) {
        throw new McpError("$at.architecture has no stages; either give the lesson architecture or set sufficient_evidence: false. "
            . 'Nothing was written.');
    }
    $out['architecture'] = ['stages' => $stages, 'sufficient_evidence' => $sufficient];

    // 17 before 10 and 12: its keys may be cited there.
    $observe = [];
    foreach (mcp_rv_list($raw, 'observe', $at, SYNTH_MAX_OBSERVATIONS) as $i => $x) {
        $w   = "$at.observe[$i]";
        $x   = mcp_rv_object($x, $w);
        $key = mcp_rv_signal_key($x['key'] ?? null, "$w.key");
        $opened[$key] = true;
        $observe[] = ['key' => $key, 'look_for' => mcp_rv_text($x, 'look_for', $w, true, 10, 400), 'why' => mcp_rv_text($x, 'why', $w, true, 5, 400)];
    }
    $out['observe'] = $observe;

    // 10
    $hyps = [];
    foreach (mcp_rv_list($raw, 'hypotheses', $at, SYNTH_MAX_HYPOTHESES) as $i => $x) {
        $w   = "$at.hypotheses[$i]";
        $x   = mcp_rv_object($x, $w);
        $key = mcp_rv_signal_key($x['signal_key'] ?? null, "$w.signal_key");
        if (!isset($signalKeys[$key]) && !isset($opened[$key])) {
            throw new McpError("$w.signal_key \"$key\" is not an existing signal and is not opened by this synthesis (Part 3 or 17). "
                . 'Nothing was written.');
        }
        $hyps[] = ['signal_key' => $key, 'hypothesis' => mcp_rv_text($x, 'hypothesis', $w), 'evidence' => mcp_rv_text($x, 'evidence', $w),
                   'how' => mcp_rv_text($x, 'how', $w), 'collect' => mcp_rv_strings($x, 'collect', $w, 8, 3, 300),
                   'supports' => mcp_rv_text($x, 'supports', $w), 'challenges' => mcp_rv_text($x, 'challenges', $w)];
    }
    if (!$hyps && $sufficient) {
        throw new McpError("$at.hypotheses is empty. At least one hypothesis is required unless architecture.sufficient_evidence "
            . 'is false. Nothing was written.');
    }
    $out['hypotheses'] = $hyps;

    // 11 — every quote must be one a lesson review recorded this week.
    if (array_key_exists('learner_voice', $raw) && $raw['learner_voice'] !== null) {
        $w  = "$at.learner_voice";
        $lv = mcp_rv_object($raw['learner_voice'], $w);
        $groups = [];
        foreach (mcp_rv_list($lv, 'groups', $w, 10) as $i => $g) {
            $g  = mcp_rv_object($g, "$w.groups[$i]");
            $qs = mcp_rv_strings($g, 'quotes', "$w.groups[$i]", 12, 2, 300);
            foreach ($qs as $q) {
                if (!isset($quotes[$q])) {
                    throw new McpError("$w.groups[$i] quotes \"$q\", which no lesson review of $week recorded as her words. "
                        . 'Quotes must match exactly; never paraphrase. Nothing was written.');
                }
            }
            $groups[] = ['theme' => mcp_rv_text($g, 'theme', "$w.groups[$i]", true, 3, 200), 'quotes' => $qs];
        }
        $entry = ['groups' => $groups];
        $pve = mcp_rv_text($lv, 'perception_vs_evidence', $w, false, 10, 800);
        if ($pve !== null) {
            $entry['perception_vs_evidence'] = $pve;
        }
        $out['learner_voice'] = $entry;
    }

    // 12 — deltas to the learner model; the status rule is applied on save.
    $model = [];
    foreach (mcp_rv_list($raw, 'model', $at, 20) as $i => $x) {
        $w    = "$at.model[$i]";
        $x    = mcp_rv_object($x, $w);
        $keys = [];
        foreach (mcp_rv_list($x, 'signal_keys', $w, 10) as $j => $k) {
            $k = mcp_rv_signal_key($k, "$w.signal_keys[$j]");
            if (!isset($signalKeys[$k]) && !isset($opened[$k])) {
                throw new McpError("$w.signal_keys names \"$k\", which is not a signal. Nothing was written.");
            }
            $keys[] = $k;
        }
        if (!$keys) {
            throw new McpError("$w.signal_keys is empty; a model row rests on at least one signal. Nothing was written.");
        }
        $entry = ['key' => mcp_rv_signal_key($x['key'] ?? null, "$w.key"), 'change' => mcp_rv_enum($x, 'change', $w, SYNTH_MODEL_CHANGES),
                  'statement' => mcp_rv_text($x, 'statement', $w, true, 10, 400), 'signal_keys' => array_values(array_unique($keys))];
        $note = mcp_rv_text($x, 'note', $w, false, 3, 400);
        if ($note !== null) {
            $entry['note'] = $note;
        }
        $model[] = $entry;
    }
    $out['model'] = $model;

    // 13
    $prios = [];
    foreach (mcp_rv_list($raw, 'priorities', $at, SYNTH_MAX_PRIORITIES) as $i => $x) {
        $w = "$at.priorities[$i]";
        $x = mcp_rv_object($x, $w);
        $rank = (int) ($x['rank'] ?? 0);
        if ($rank !== $i + 1) {
            throw new McpError("$w.rank is $rank; ranks must run 1.." . count($raw['priorities']) . ' in order. Nothing was written.');
        }
        $prios[] = ['rank' => $rank, 'priority' => mcp_rv_text($x, 'priority', $w, true, 5, 300), 'why' => mcp_rv_text($x, 'why', $w),
                    'evidence' => mcp_rv_text($x, 'evidence', $w), 'action' => mcp_rv_text($x, 'action', $w)];
    }
    if (!$prios) {
        throw new McpError("$at.priorities is empty; at least one priority is required. Nothing was written.");
    }
    $out['priorities'] = $prios;

    // 14 — every subject taught next week gets a plan.
    $nextBlocks = $inputs['next_blocks'];
    $needPlan   = [];
    foreach ($nextBlocks as $slug => $blocks) {
        foreach ($blocks as $b) {
            if ($b['taught']) {
                $needPlan[$slug] = true;
            }
        }
    }
    $plans = [];
    $seenPlan = [];
    foreach (mcp_rv_list($raw, 'week_plans', $at, 12) as $i => $x) {
        $w    = "$at.week_plans[$i]";
        $x    = mcp_rv_object($x, $w);
        $slug = $slugOk($x['subject_slug'] ?? '', "$w.subject_slug");
        if (!isset($nextBlocks[$slug])) {
            throw new McpError("$w plans $slug, which has no block in {$inputs['next_week']}. Plan the subjects that run. Nothing was written.");
        }
        if (isset($seenPlan[$slug])) {
            throw new McpError("$w repeats $slug. Nothing was written.");
        }
        $seenPlan[$slug] = true;
        $entry = ['subject_slug' => $slug];
        foreach (WEEK_PLAN_FIELDS as $f) {
            $v = mcp_rv_text($x, $f, $w, $f !== 'reteach_if', 5, 400);
            if ($v !== null) {
                $entry[$f] = $v;
            }
        }
        $refs = [];
        foreach (mcp_rv_list($x, 'refs', $w, 20) as $j => $r) {
            $refs[] = $ref($slug, $r, "$w.refs[$j]");
        }
        $entry['refs'] = array_values(array_unique($refs));
        $plans[] = $entry;
    }
    $noPlan = array_diff(array_keys($needPlan), array_keys($seenPlan));
    if ($noPlan) {
        throw new McpError("$at.week_plans has no plan for " . implode(', ', $noPlan) . ', which ' . (count($noPlan) === 1 ? 'has' : 'have')
            . " a taught block in {$inputs['next_week']}. Nothing was written.");
    }
    $out['week_plans'] = $plans;

    // 16
    $ssc = mcp_rv_object($raw['stop_start_continue'] ?? [], "$at.stop_start_continue");
    $sscOut = [];
    foreach (['stop', 'start', 'continue'] as $k) {
        $list = [];
        foreach (mcp_rv_list($ssc, $k, "$at.stop_start_continue", SYNTH_MAX_PRACTICES) as $i => $x) {
            $w = "$at.stop_start_continue.$k" . "[$i]";
            $x = mcp_rv_object($x, $w);
            $e = ['practice' => mcp_rv_text($x, 'practice', $w, true, 5, 300), 'why' => mcp_rv_text($x, 'why', $w, true, 5, 400)];
            $m = mcp_rv_enum($x, 'method', $w, REVIEW_METHODS, false);
            if ($m !== null) {
                $e['method'] = $m;
            }
            $list[] = $e;
        }
        $sscOut[$k] = $list;
    }
    $out['stop_start_continue'] = $sscOut;

    // 18 — no grades without an assessment.
    $bp = mcp_rv_object($raw['big_picture'] ?? null, "$at.big_picture");
    $graded = [];
    foreach ($inputs['attempts'] as $a) {
        if (($a['kind'] ?? '') === 'paper') {
            $graded[] = $a['name'] . ' ' . $a['score'] . '/' . $a['max'];
        }
    }
    $bpOut = [];
    foreach (SYNTH_BIG_PICTURE as $k) {
        $v = mcp_rv_text($bp, $k, "$at.big_picture", true, 3, 400);
        if (!$graded && preg_match('/\bgrade\s*[1-9]\b|\b[1-9]\s*\/\s*9\b|\bgrade [A-U]\b|\bgrade (?:boundary|projection)/i', $v)) {
            throw new McpError("$at.big_picture.$k names a grade, and no graded paper was sat in $week. Grades only follow an assessment. "
                . 'Nothing was written.');
        }
        $bpOut[$k] = $v;
    }
    $bpOut['grade_basis'] = $graded ? 'graded attempt: ' . implode('; ', $graded) : 'no graded attempt this week';
    $out['big_picture'] = $bpOut;

    // 19
    $planner = mcp_rv_object($raw['planner'] ?? null, "$at.planner");
    foreach (array_keys($planner) as $k) {
        if (!in_array($k, SYNTH_PLANNER, true)) {
            throw new McpError("$at.planner has an unknown key \"$k\"; the ten are " . implode(', ', SYNTH_PLANNER) . '. Nothing was written.');
        }
    }
    $pl = [];
    foreach (SYNTH_PLANNER as $k) {
        $pl[$k] = mcp_review_line($planner[$k] ?? null, "$at.planner.$k", 3, 200);
    }
    $out['planner'] = $pl;

    // 20 — only when there is a last week.
    $previous = $inputs['previous'] !== null;
    $hasChange = array_key_exists('change', $raw) && $raw['change'] !== null;
    if ($previous && !$hasChange) {
        throw new McpError("$at.change is required: a synthesis for {$inputs['previous']['week']} exists to compare against. Nothing was written.");
    }
    if (!$previous && ($hasChange || isset($raw['changes_worked']))) {
        throw new McpError("$at.change is refused: no previous synthesis exists to compare against. Nothing was written.");
    }
    if ($previous) {
        $changes = [];
        foreach (mcp_rv_list($raw, 'change', $at, 20) as $i => $x) {
            $w = "$at.change[$i]";
            $x = mcp_rv_object($x, $w);
            $changes[] = ['category' => mcp_rv_enum($x, 'category', $w, SYNTH_WEEK_CHANGES), 'what' => mcp_rv_text($x, 'what', $w),
                          'evidence' => mcp_rv_text($x, 'evidence', $w)];
        }
        $out['change']         = $changes;
        $out['changes_worked'] = mcp_rv_text($raw, 'changes_worked', $at, true, 10, 600);
    }

    if (!array_key_exists('collect_next_week', $raw)) {
        throw new McpError("$at.collect_next_week is required — what to collect next week. An empty list is allowed. Nothing was written.");
    }
    $out['collect_next_week'] = mcp_rv_strings($raw, 'collect_next_week', $at, 20);
    return $out;
}

/**
 * Write a validated synthesis: open the signals it declares, set its tests,
 * apply its model deltas, save the row with the server's snapshot, replace
 * next week's plans. One transaction; the store's refusals become the
 * tool's.
 *
 * @return array{row:array,status:string,lines:array<int,string>}
 */
function mcp_apply_synthesis(Store $store, string $week, array $sections, string $stage, string $by, ?string $note, array $inputs): array
{
    $lines    = [];
    $nextWeek = $inputs['next_week'];
    try {
        return $store->transaction(function () use ($store, $week, $sections, $stage, $by, $note, $inputs, $nextWeek, &$lines): array {
            $opener = $by === 'routine' ? 'synthesis' : 'parent';

            // Part 3: cross-subject signals — new keys open, existing per-subject
            // keys promote, an existing cross-subject key gains evidence rows.
            foreach ($sections['cross_subject'] as $c) {
                $existing = $store->signalByKey(null, $c['key']);
                $evidence = array_map(static fn(int $sid): array => ['session_id' => $sid, 'direction' => 'supports', 'evidence' => $c['evidence']],
                    $c['sessions']);
                if ($existing !== null) {
                    foreach ($evidence as $e) {
                        $store->addSignalEvidence($existing['id'], $e['session_id'], 'supports', $e['evidence']);
                    }
                    $lines[] = 'Cross-subject signal ' . $c['key'] . ' #' . $existing['id'] . ': ' . count($evidence) . ' evidence row'
                        . (count($evidence) === 1 ? '' : 's') . ' added.';
                    continue;
                }
                $perSubject = array_filter($store->signalsByKeyAnySubject($c['key']), static fn(array $g): bool => $g['subject_slug'] !== null);
                if ($perSubject) {
                    $res = $store->promoteSignal($c['key'], $opener, $week);
                    foreach ($evidence as $e) {
                        $store->addSignalEvidence($res['signal']['id'], $e['session_id'], 'supports', $e['evidence']);
                    }
                    $lines[] = 'Promoted ' . $c['key'] . ' to cross-subject #' . $res['signal']['id'] . ' from #' . implode(', #', $res['from'])
                        . ' (' . implode(', ', $res['subjects']) . ').';
                } else {
                    $g = $store->openSignalBy(null, $c['key'], 'learning_process', $c['observation'], $opener, $week, null, $evidence);
                    $lines[] = 'Opened cross-subject signal ' . $c['key'] . ' #' . $g['id'] . ' at ' . $g['strength'] . '.';
                }
            }

            // Part 17: watch signals, opened with no session behind them.
            foreach ($sections['observe'] as $o) {
                $found = $store->signalsByKeyAnySubject($o['key']);
                if ($found) {
                    $lines[] = 'Observation ' . $o['key'] . ' attaches to existing signal #' . $found[0]['id'] . '.';
                    continue;
                }
                $g = $store->openSignalBy(null, $o['key'], 'watch', $o['look_for'], $opener, $nextWeek, null, []);
                $lines[] = 'Opened watch ' . $o['key'] . ' #' . $g['id'] . ' for ' . $nextWeek . '.';
            }

            // Part 10: tests on signals. A parent-set test is never overwritten by the routine.
            $left = [];
            foreach ($sections['hypotheses'] as $h) {
                $targets = $store->signalsByKeyAnySubject($h['signal_key']);
                if (!$targets) {
                    throw new InvalidArgumentException("hypothesis names signal '{$h['signal_key']}', which does not exist");
                }
                $design = ['how' => $h['how'], 'collect' => $h['collect'], 'supports' => $h['supports'], 'challenges' => $h['challenges']];
                foreach ($targets as $g) {
                    $res = $store->setSignalTest($g['id'], $h['how'], $design, $opener, $nextWeek);
                    if ($res['set']) {
                        $lines[] = 'Test set on #' . $g['id'] . ' ' . $g['key'] . ' for ' . $nextWeek . '.';
                    } else {
                        $left[]  = $res['reason'];
                    }
                }
            }
            foreach ($left as $l) {
                $lines[] = 'Left in place: ' . $l . '.';
            }

            // Part 12: learner-model deltas under the status rule.
            foreach ($store->applyModelChanges($week, $sections['model']) as $l) {
                $lines[] = ucfirst($l) . '.';
            }

            // The row, with what it knew.
            $snapshot = $store->synthesisSnapshot($week, $sections, $inputs);
            $res      = $store->addWeekSynthesis(['week' => $week, 'stage' => $stage, 'written_by' => $by,
                'snapshot' => $snapshot, 'sections' => $sections, 'note' => $note]);
            if ($res['status'] === 'stored') {
                // Part 14: next week's plans, owned by this version.
                $n = $store->replaceWeekPlans($res['row']['id'], $nextWeek, $sections['week_plans']);
                foreach ($sections['week_plans'] as $p) {
                    $lines[] = 'Plan for ' . $p['subject_slug'] . ' (' . $nextWeek . '): ' . $p['next_content'];
                }
                if ($n === 0) {
                    $lines[] = 'No week plans: no subject has a block in ' . $nextWeek . '.';
                }
            }
            if ($by === 'routine') {
                $store->setMeta('last_synthesis_week', $week);
            }
            return ['row' => $res['row'], 'status' => $res['status'], 'lines' => $lines];
        });
    } catch (InvalidArgumentException $e) {
        throw new McpError($e->getMessage() . '. Nothing was written.');
    }
}

/**
 * The queue's this_week block for a subject: the current week's plan (or
 * the last one, flagged stale) and the tests set for this week that bear
 * on the subject. Records the read.
 *
 * @return array<int,string>
 */
function mcp_this_week_lines(Store $store, string $slug): array
{
    $week = tt_iso_week(tt_today());
    $plan = $store->weekPlanForQueue($slug, $week);
    if ($plan === null) {
        return [];
    }
    $syn   = $store->weekSynthesisById($plan['synthesis_id']);
    $lines = ['### this_week  (synthesis ' . ($syn['week'] ?? '?') . ' v' . ($syn['version'] ?? '?') . ', for ' . $plan['week'] . ')'];
    if ($plan['stale']) {
        $lines[] = 'stale: true — this plan was written for ' . $plan['week'] . ' and no newer synthesis exists; decide whether it still holds.';
    }
    foreach (week_plan_lines($plan) as $l) {
        $lines[] = $l;
    }
    $tests = [];
    foreach ($store->testsDue($plan['week']) as $t) {
        $g = $t['signal'];
        if ($g['subject_slug'] !== null && $g['subject_slug'] !== $slug) {
            continue;
        }
        $tests[] = 'TEST THIS WEEK: #' . $g['id'] . ' ' . $g['key'] . ' — ' . $g['next_test'] . ' (set by ' . ($g['test_set_by'] ?? 'review') . ')'
            . ($t['answered'] ? ' — answered by session ' . $t['by_session'] : '');
    }
    foreach ($tests as $t) {
        $lines[] = $t;
    }
    $store->markWeekPlanRead($plan['id']);
    return $lines;
}

/** The tests a signal carries, for tracker_signals and the pages. */
function mcp_signal_test_lines(array $g): array
{
    if (empty($g['next_test'])) {
        return [];
    }
    $out = ['    test: ' . $g['next_test'] . ' (set by ' . ($g['test_set_by'] ?? 'review')
        . (!empty($g['test_week']) ? ' for ' . $g['test_week'] : '') . ')'];
    $d = $g['test_design'] ?? null;
    if (is_array($d)) {
        $out[] = '    collect: ' . implode('; ', $d['collect'] ?? []) . ' · supports if: ' . ($d['supports'] ?? '—')
            . ' · challenges if: ' . ($d['challenges'] ?? '—');
    }
    return $out;
}
