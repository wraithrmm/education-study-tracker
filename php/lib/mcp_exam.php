<?php
/**
 * The exam-skills tools: the question bank, the scheduled test and its
 * marking. Definitions in mcp_exam_tools(), dispatch in mcp_exam_call();
 * mcp.php splices both in. The vocabulary and state rules are exam.php.
 *
 * One rule runs through every tool here: the bank is the parent's. The
 * connector cannot tell whose chat is calling, so the descriptions say
 * which project each tool belongs to, and tracker_exam_get_test holds the
 * mark schemes back until the sitting is over unless asked for them.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** @return array<int,array> the tool definitions */
function mcp_exam_tools(array $readOnly, array $write, array $isoDate, array $subjectArg): array
{
    $questionStatus = ['type' => 'string', 'enum' => EXAM_QUESTION_STATUSES];
    $testStatus     = ['type' => 'string', 'enum' => EXAM_TEST_STATUSES];
    $md = static fn(string $what, int $max): array => [
        'type' => 'string', 'minLength' => 1, 'maxLength' => $max,
        'description' => $what . ' Restricted Markdown: paragraphs, **bold**, *italic*, `code`, fenced code, - lists, x^2 superscripts. No images.',
    ];

    return [
        [
            'name'  => 'tracker_exam_add_questions',
            'title' => 'Add exam-style questions to the bank',
            'description' =>
                "Stores exam-style questions, each with its mark scheme, tagged by subject and topic refs, as drafts in the bank.\n\n"
                . "USE WHEN: the parent's exam-question project has written questions in the style of a subject's past papers and wants them kept for a future timed test. "
                . "This is the parent's tool: the student's exam project never calls it and never sees a question before she sits it.\n\n"
                . "Each question is one exam question in the house style of its paper (paper_style names it, e.g. '8300/1H', '8700/1', '8702/2', '8525/1'), "
                . "with its marks, the command word, whether a calculator is allowed, a time guide, and a mark scheme in that paper's code system "
                . "(M/A/B/ft for maths; level descriptors and indicative content for English; mark points for computer science). "
                . "topic_refs must be refs the tracker holds for the subject (tracker_get_state) — an unknown ref refuses the call, because the ref is what turns a mark into teaching information. "
                . "The subject may not be exam-skills: questions are always for a real subject.\n\n"
                . "client_key is yours and stable ('maths-A17-20260923-1'); re-sending a key already stored is a silent no-op returning the existing id, so a retried call cannot duplicate a question.\n\n"
                . "Every question lands as 'draft'. Vet it with tracker_exam_update_question before it can be scheduled.\n\n"
                . 'Args: questions[] of { client_key, subject, topic_refs[], marks, question_md, mark_scheme_md, model_answer_md?, paper_style?, calculator?, command_word?, time_guide_seconds?, tags?[], source_note? }. At most 50 per call.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'questions' => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 50,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'client_key'         => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64,
                                    'description' => 'Your stable id for this question; repeating it is a no-op'],
                                'subject'            => $subjectArg,
                                'topic_refs'         => ['type' => 'array', 'minItems' => 1, 'maxItems' => 6,
                                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
                                    'description' => 'Refs the tracker holds; the first is the one the mark is attributed to'],
                                'marks'              => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
                                'question_md'        => $md('The question exactly as the paper would print it.', EXAM_QUESTION_MAX),
                                'mark_scheme_md'     => $md('The mark scheme in the paper\'s code system, every creditable route named.', EXAM_QUESTION_MAX),
                                'model_answer_md'    => $md('A full-mark answer, for the marker.', EXAM_QUESTION_MAX),
                                'paper_style'        => ['type' => 'string', 'maxLength' => 40,
                                    'description' => "The paper whose style this follows, e.g. '8300/1H'"],
                                'calculator'         => ['type' => 'boolean', 'default' => false],
                                'command_word'       => ['type' => 'string', 'maxLength' => 40,
                                    'description' => "e.g. 'Work out', 'Explain', 'Compare', 'State'"],
                                'time_guide_seconds' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 3600],
                                'tags'               => ['type' => 'array', 'maxItems' => 10,
                                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40]],
                                'source_note'        => ['type' => 'string', 'maxLength' => 300,
                                    'description' => 'Which past paper or question it is modelled on'],
                            ],
                            'required'   => ['client_key', 'subject', 'topic_refs', 'marks', 'question_md', 'mark_scheme_md'],
                        ],
                    ],
                ],
                'required'   => ['questions'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_exam_list_questions',
            'title' => 'List the question bank',
            'description' =>
                "The bank, one line per question: id, subject, status, marks, refs, tags and the start of the text.\n\n"
                . "USE WHEN: the parent's exam-question project is deciding what to write next, what is vetted and unscheduled, or what has been sat. "
                . "Parent only: on the portal the bank is behind the parent's login, and the student's exam project never lists it.\n\n"
                . 'Args: optional subject, status (' . implode('|', EXAM_QUESTION_STATUSES) . '), tag, limit (default 100).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'status'  => $questionStatus,
                    'tag'     => ['type' => 'string', 'maxLength' => 40],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
                ],
                'required'   => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_exam_update_question',
            'title' => 'Vet, edit or retire a question',
            'description' =>
                "Moves one question through the bank: vet it (draft → vetted, so it can be scheduled), edit its fields, or retire it.\n\n"
                . "USE WHEN: the parent's exam-question project has checked a draft against its checklist and approves it; or a question needs a correction; or it should never be used. "
                . "Any question can be edited, at any status. In the bank (draft or vetted) an edit returns it to draft for re-vetting; inside a test (scheduled, answered or marked) it is corrected in place and keeps its place and status — a mark can never drop below a score already given. "
                . "A question inside a test cannot be retired until it is taken out with tracker_exam_update_test.\n\n"
                . 'Args: id, action (vet|edit|retire), optional note, and for edit a fields object with any of the add-questions fields except client_key and subject.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'     => ['type' => 'integer', 'minimum' => 1],
                    'action' => ['type' => 'string', 'enum' => ['vet', 'edit', 'retire']],
                    'note'   => ['type' => 'string', 'maxLength' => 300],
                    'fields' => ['type' => 'object', 'description' => 'For edit: the columns to change'],
                ],
                'required'   => ['id', 'action'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_exam_schedule_test',
            'title' => 'Build a timed test from vetted questions',
            'description' =>
                "Creates one timed test for a date: vetted questions in subject sections, one duration, one timer. The test is 'ready' and hidden until she presses Start on the portal.\n\n"
                . "USE WHEN: the parent's exam-question project is building the week's Wednesday paper. "
                . "Sections are sat in the order given; every question must be vetted, belong to its section's subject and be unused (a question is sat once). "
                . "Each section's minutes_guide is the time she is advised to give it; the guides should add up to the duration less a few minutes' reading time. "
                . "Pass the exam-practice block_key from tracker_get_timetable so the sitting fulfils the block. One ready or open test per date.\n\n"
                . 'Args: name, scheduled_for, duration_minutes (' . EXAM_MIN_MINUTES . '-' . EXAM_MAX_MINUTES . '), sections[] of { subject, question_ids[], minutes_guide? }, optional block_key, instructions, note.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'name'             => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120,
                        'description' => "e.g. 'Exam practice — week 39'"],
                    'scheduled_for'    => $isoDate,
                    'duration_minutes' => ['type' => 'integer', 'minimum' => EXAM_MIN_MINUTES, 'maximum' => EXAM_MAX_MINUTES],
                    'block_key'        => ['type' => 'integer', 'minimum' => 1],
                    'instructions'     => ['type' => 'string', 'maxLength' => 2000,
                        'description' => 'Shown before Start and during the sitting: marks per section, calculator rules, reminders'],
                    'note'             => ['type' => 'string', 'maxLength' => 500],
                    'sections'         => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 8,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'subject'       => $subjectArg,
                                'question_ids'  => ['type' => 'array', 'minItems' => 1, 'maxItems' => 40,
                                    'items' => ['type' => 'integer', 'minimum' => 1]],
                                'minutes_guide' => ['type' => 'integer', 'minimum' => 1, 'maximum' => EXAM_MAX_MINUTES],
                            ],
                            'required'   => ['subject', 'question_ids'],
                        ],
                    ],
                ],
                'required'   => ['name', 'scheduled_for', 'duration_minutes', 'sections'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_exam_update_test',
            'title' => 'Edit or cancel a scheduled test',
            'description' =>
                "Changes a test after it was scheduled, or cancels it.\n\n"
                . "USE WHEN: the parent's exam-question project wants a booked paper changed — a longer or shorter timer, a different date or block, new instructions, questions swapped in or out — or a paper dropped. "
                . "Parent's tool: the student's exam project never calls it.\n\n"
                . "action \"edit\" takes a fields object. What a test may change depends on how far it has gone:\n"
                . "- ready (not started): anything — name, scheduled_for, duration_minutes (" . EXAM_MIN_MINUTES . '-' . EXAM_MAX_MINUTES . "), block_key (null to clear), instructions, note, and sections (the whole new list, validated as in tracker_exam_schedule_test; questions already in this test may stay, questions dropped go back to vetted).\n"
                . "- open (she is sitting it): name, instructions, note and duration_minutes — the deadline moves to start + the new duration and her page picks it up within 20 seconds. A duration that puts the deadline in the past ends the sitting now.\n"
                . "- closed or marked: name, instructions and note.\n"
                . "To change a question's own text or mark scheme, use tracker_exam_update_question — that works inside a test too.\n\n"
                . "action \"cancel\" deletes a test that is not marked, with any answers saved in it. Questions from a test she never started go back to vetted; from one she started they are retired (she has seen them) unless requeue is true. A marked test cannot be cancelled: its attempts are the record.\n\n"
                . 'Args: id, action (edit|cancel), fields? (for edit), requeue? (for cancel), note? (why, kept on the record).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'      => ['type' => 'integer', 'minimum' => 1],
                    'action'  => ['type' => 'string', 'enum' => ['edit', 'cancel']],
                    'fields'  => [
                        'type'       => 'object',
                        'description' => 'For edit: the columns to change',
                        'properties' => [
                            'name'             => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                            'scheduled_for'    => $isoDate,
                            'duration_minutes' => ['type' => 'integer', 'minimum' => EXAM_MIN_MINUTES, 'maximum' => EXAM_MAX_MINUTES],
                            'block_key'        => ['type' => ['integer', 'null'], 'minimum' => 1],
                            'instructions'     => ['type' => 'string', 'maxLength' => 2000],
                            'note'             => ['type' => 'string', 'maxLength' => 500],
                            'sections'         => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8,
                                'description' => 'The whole new list of { subject, question_ids[], minutes_guide? }, as for tracker_exam_schedule_test'],
                        ],
                    ],
                    'requeue' => ['type' => 'boolean', 'default' => false,
                        'description' => 'For cancel of a started test: put its questions back to vetted rather than retiring them'],
                    'note'    => ['type' => 'string', 'maxLength' => 300],
                ],
                'required'   => ['id', 'action'],
            ],
            'annotations' => $write,
        ],
        [
            'name'  => 'tracker_exam_list_tests',
            'title' => 'List exam-practice tests',
            'description' =>
                "Every test, newest first: id, name, date, status, questions, marks, and when it was sat.\n\n"
                . "USE WHEN: the student asks what her exam practice is (status 'ready' is the one waiting for her; give her the link /exam/{id}), "
                . "when a project needs to know whether a sitting is closed and awaiting marking, or for the weekly review. "
                . "Safe in either project: it names tests, never questions.\n\n"
                . 'Args: optional status (' . implode('|', EXAM_TEST_STATUSES) . '), from, to (dates), limit.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'status' => $testStatus,
                    'from'   => $isoDate,
                    'to'     => $isoDate,
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
                'required'   => [],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_exam_get_test',
            'title' => 'Read one test in full',
            'description' =>
                "One test: its sections and questions, and once it has been sat, every answer she typed, her working, which questions she flagged, how long each took, and which were left blank — with the mark schemes and model answers beside them for marking.\n\n"
                . "USE WHEN: the student's exam project has been told the test is ready for marking (status 'closed'), or a marked test is being reviewed. "
                . "The mark schemes are withheld while the test is 'ready' or 'open' — a sitting in progress must not have its answers in any chat — unless include_bank is true, which is the parent's project checking a paper before the day.\n\n"
                . 'Args: id, optional include_bank.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'           => ['type' => 'integer', 'minimum' => 1],
                    'include_bank' => ['type' => 'boolean', 'default' => false,
                        'description' => "Parent only: return schemes for a test not yet sat"],
                ],
                'required'   => ['id'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_exam_mark_test',
            'title' => 'Mark a sat test',
            'description' =>
                "Records a mark, a marker's note and a line of student feedback for every question of a closed test, then writes one ordinary attempt per subject (kind 'check', one paper, every question with its topic ref) so the attempt pages, the per-topic breakdown and the weekly review read the sitting like any other marked work.\n\n"
                . "USE WHEN: the student's exam project has marked every answer of a closed test against its stored scheme, in the subject's own convention. "
                . "Every question must be marked in one call; a score may not exceed the question's marks; a blank scores what the scheme says (usually 0) and is counted as a blank. "
                . "student_feedback is what she will read on the portal: the topic and a method hint, never the answer. note is the marker's and stays parent-side.\n\n"
                . "After this call, log the sessions: one tracker_log_session per subject that had questions, carrying a review, with NO block_key; and one for exam-skills carrying the technique observations, with NO block_key and NO duration_minutes — the sitting itself is already the block's evidence and its hours.\n\n"
                . 'Args: id, marks[] of { question_id, score, note?, student_feedback? }, optional note for the test.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'    => ['type' => 'integer', 'minimum' => 1],
                    'note'  => ['type' => 'string', 'maxLength' => 1000],
                    'marks' => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 200,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'question_id'      => ['type' => 'integer', 'minimum' => 1],
                                'score'            => ['type' => 'number', 'minimum' => 0],
                                'note'             => ['type' => 'string', 'maxLength' => 500,
                                    'description' => 'Why the marks went the way they did — parent side'],
                                'student_feedback' => ['type' => 'string', 'maxLength' => EXAM_FEEDBACK_MAX,
                                    'description' => 'Topic and method hint for her; never the answer'],
                            ],
                            'required'   => ['question_id', 'score'],
                        ],
                    ],
                ],
                'required'   => ['id', 'marks'],
            ],
            'annotations' => $write,
        ],
    ];
}

/** One line of the bank. */
function mcp_exam_question_line(array $q): string
{
    $text = preg_replace('/\s+/', ' ', trim((string) $q['question_md'])) ?? '';
    if (mb_strlen($text) > 80) {
        $text = mb_substr($text, 0, 77) . '…';
    }
    return sprintf('#%d %s [%s] %s · %s%s%s · %s — %s',
        $q['id'], $q['subject_slug'], $q['status'], exam_marks_word($q['marks']),
        implode('/', $q['topic_refs']),
        $q['paper_style'] ? ' · ' . $q['paper_style'] : '',
        $q['tags'] ? ' · ' . implode(', ', $q['tags']) : '',
        $q['client_key'], $text);
}

/** The subject's display tier letter for an attempt row. */
function mcp_exam_tier(array $subject): string
{
    $t = strtoupper(substr((string) ($subject['tier'] ?? ''), 0, 1));
    return $t === '' ? 'U' : $t;
}

/** The blocks of a test, one per section, as lines. */
function mcp_exam_section_lines(array $test, bool $withAnswers, bool $withSchemes): array
{
    $lines = [];
    foreach ($test['sections'] as $s) {
        $tot = exam_section_totals($s);
        $lines[] = '';
        $lines[] = '## ' . $s['subject'] . ' — ' . exam_marks_word($tot['max'])
            . ($tot['minutes'] !== null ? ', guide ' . $tot['minutes'] . ' min' : '')
            . ($tot['score'] !== null ? ', scored ' . num($tot['score']) : '')
            . ($withAnswers ? ', ' . $tot['blanks'] . ' blank' : '');
        foreach ($s['questions'] as $q) {
            $lines[] = 'Q' . $q['label'] . ' (question #' . $q['id'] . ', ' . exam_marks_word($q['marks'])
                . ', ' . implode('/', $q['topic_refs'])
                . ($q['command_word'] ? ', ' . $q['command_word'] : '')
                . ($q['calculator'] ? ', calculator' : '')
                . ($q['time_guide_seconds'] ? ', guide ' . (int) round($q['time_guide_seconds'] / 60) . ' min' : '')
                . ')';
            $lines[] = '  ' . str_replace("\n", "\n  ", trim((string) $q['question_md']));
            if ($withAnswers) {
                $a = $q['answer'];
                if ($a === null || exam_is_blank($a['answer'])) {
                    $lines[] = '  ANSWER: (blank)' . ($a && !exam_is_blank($a['working']) ? ' — working only: ' . trim((string) $a['working']) : '');
                } else {
                    $lines[] = '  ANSWER: ' . str_replace("\n", "\n    ", trim((string) $a['answer']));
                    if (!exam_is_blank($a['working'] ?? null)) {
                        $lines[] = '  WORKING: ' . str_replace("\n", "\n    ", trim((string) $a['working']));
                    }
                }
                if ($a !== null) {
                    $lines[] = '  time ' . (int) round($a['time_spent_seconds'] / 60) . ' min'
                        . ($a['flagged'] ? ', flagged to come back to' : '')
                        . ($a['score'] !== null ? ', scored ' . num($a['score']) . '/' . $q['marks'] : '');
                    if ($a['score'] !== null && $a['marker_note']) {
                        $lines[] = '  NOTE: ' . $a['marker_note'];
                    }
                    if ($a['score'] !== null && $a['student_feedback']) {
                        $lines[] = '  FEEDBACK: ' . $a['student_feedback'];
                    }
                }
            }
            if ($withSchemes) {
                $lines[] = '  MARK SCHEME: ' . str_replace("\n", "\n    ", trim((string) $q['mark_scheme_md']));
                if ($q['model_answer_md']) {
                    $lines[] = '  MODEL ANSWER: ' . str_replace("\n", "\n    ", trim((string) $q['model_answer_md']));
                }
            }
        }
    }
    return $lines;
}

/** The header lines of a test. */
function mcp_exam_test_head(array $t): array
{
    $lines = ['Test #' . $t['id'] . ' "' . $t['name'] . '" — ' . $t['scheduled_for'] . ' · ' . $t['status']
        . ' · ' . $t['question_count'] . ' question' . ($t['question_count'] === 1 ? '' : 's')
        . ' · ' . exam_marks_word($t['marks']) . ' · ' . exam_minutes_word($t['duration_minutes'])
        . ($t['block_key'] ? ' · block #' . $t['block_key'] : '') . ' · /exam/' . $t['id']];
    if ($t['started_at']) {
        [$d, $at] = tt_local((string) $t['started_at']);
        $lines[] = 'Started ' . $d . ' ' . $at . ' Europe/London'
            . ($t['closed_at'] ? ', closed by ' . $t['closed_by'] . ' after ' . $t['sat_minutes'] . ' min' : ', deadline ' . tt_local((string) $t['deadline_at'])[1])
            . ($t['marked_at'] ? ', marked ' . tt_local((string) $t['marked_at'])[0] : '') . '.';
    }
    if ($t['instructions']) {
        $lines[] = 'Instructions: ' . $t['instructions'];
    }
    if ($t['note']) {
        $lines[] = 'Note: ' . $t['note'];
    }
    return $lines;
}

/**
 * Validate the sections of a test being scheduled or rebuilt. Every question
 * must exist, belong to its section's subject and be vetted — or, when
 * $testId names the test being rebuilt, already be in that test.
 *
 * @return array{0:array<int,array{subject:string,question_ids:array<int,int>,minutes_guide:?int}>,1:int,2:int}
 *         the clean sections, the guide minutes and the marks
 */
function mcp_exam_clean_sections(Store $store, mixed $sections, ?int $testId): array
{
    $sections = is_array($sections) ? $sections : [];
    if (!$sections) {
        throw new McpError('sections must be a non-empty array of { subject, question_ids[] }.');
    }
    $clean   = [];
    $seenQ   = [];
    $seenSub = [];
    $guides  = 0;
    $marks   = 0;
    foreach (array_values($sections) as $i => $s) {
        $at = "sections[$i]";
        if (!is_array($s)) {
            throw new McpError("$at must be an object.");
        }
        $slug = mcp_str($s, 'subject', true, 1);
        if ($slug === EXAM_SUBJECT) {
            throw new McpError("$at: a section is a real subject, never " . EXAM_SUBJECT . '.');
        }
        $r = mcp_resolve($store, $slug);
        if (isset($r['error'])) {
            throw new McpError("$at: " . $r['error']);
        }
        if (isset($seenSub[$slug])) {
            throw new McpError("$at repeats subject $slug; one section per subject.");
        }
        $seenSub[$slug] = true;
        $ids = is_array($s['question_ids'] ?? null) ? $s['question_ids'] : [];
        if (!$ids) {
            throw new McpError("$at.question_ids must be a non-empty array.");
        }
        $guide = isset($s['minutes_guide']) ? (int) $s['minutes_guide'] : null;
        if ($guide !== null && ($guide < 1 || $guide > EXAM_MAX_MINUTES)) {
            throw new McpError("$at.minutes_guide must be between 1 and " . EXAM_MAX_MINUTES . '.');
        }
        $guides += $guide ?? 0;
        $qids = [];
        foreach ($ids as $qid) {
            $qid = (int) $qid;
            if (isset($seenQ[$qid])) {
                throw new McpError("$at repeats question #$qid.");
            }
            $seenQ[$qid] = true;
            $q = $store->examQuestion($qid);
            if (!$q) {
                throw new McpError("$at names question #$qid, which is not in the bank.");
            }
            if ($q['subject_slug'] !== $slug) {
                throw new McpError("$at is the $slug section but question #$qid is {$q['subject_slug']}.");
            }
            $inThisTest = $testId !== null && $store->examTestIdOfQuestion($qid) === $testId;
            if ($q['status'] !== 'vetted' && !$inThisTest) {
                throw new McpError("question #$qid is {$q['status']}, not vetted"
                    . ($q['status'] === 'draft' ? ' — vet it first with tracker_exam_update_question' : ' — a question is sat once') . '.');
            }
            $marks += $q['marks'];
            $qids[] = $qid;
        }
        $clean[] = ['subject' => $slug, 'question_ids' => $qids, 'minutes_guide' => $guide];
    }
    return [$clean, $guides, $marks];
}

/** One line per section of a hydrated test: its labels, marks and guide. */
function mcp_exam_section_summary(array $t): array
{
    $lines = [];
    foreach ($t['sections'] as $s) {
        $tot = exam_section_totals($s);
        $lines[] = '- ' . $s['subject'] . ': '
            . implode(', ', array_map(static fn($q) => 'Q' . $q['label'] . ' (#' . $q['id'] . ')', $s['questions']))
            . ' — ' . exam_marks_word($tot['max'])
            . ($tot['minutes'] !== null ? ', guide ' . $tot['minutes'] . ' min' : '');
    }
    return $lines;
}

function mcp_exam_call(Store $store, string $name, array $a): array
{
    switch ($name) {
        case 'tracker_exam_add_questions': {
            $raw = is_array($a['questions'] ?? null) ? $a['questions'] : [];
            if (!$raw) {
                throw new McpError('questions must be a non-empty array.');
            }
            if (count($raw) > 50) {
                throw new McpError('at most 50 questions per call.');
            }
            $known = [];
            $seen  = [];
            $clean = [];
            foreach (array_values($raw) as $i => $q) {
                $at = "questions[$i]";
                if (!is_array($q)) {
                    throw new McpError("$at must be an object.");
                }
                $key = mcp_str($q, 'client_key', true, 1, 64);
                if (isset($seen[$key])) {
                    throw new McpError("$at repeats client_key '$key' within this call.");
                }
                $seen[$key] = true;
                $slug = mcp_str($q, 'subject', true, 1);
                if ($slug === EXAM_SUBJECT) {
                    throw new McpError("$at: questions are for a real subject, never " . EXAM_SUBJECT . '.');
                }
                $r = mcp_resolve($store, $slug);
                if (isset($r['error'])) {
                    throw new McpError("$at: " . $r['error']);
                }
                $known[$slug] ??= array_column($store->listTopics($slug), 'ref');
                $refs = mcp_ref_list($q['topic_refs'] ?? null, "$at.topic_refs", 6);
                if (!$refs) {
                    throw new McpError("$at.topic_refs must name at least one topic ref.");
                }
                foreach ($refs as $ref) {
                    if (!in_array($ref, $known[$slug], true)) {
                        throw new McpError("$at.topic_refs names '$ref', which $slug does not hold. Take refs from tracker_get_state.");
                    }
                }
                $marks = mcp_num($q, 'marks', true, 1, 40);
                if ($marks != (int) $marks) {
                    throw new McpError("$at.marks must be a whole number.");
                }
                $guide = $q['time_guide_seconds'] ?? null;
                if ($guide !== null && (!is_numeric($guide) || $guide < 10 || $guide > 3600)) {
                    throw new McpError("$at.time_guide_seconds must be between 10 and 3600.");
                }
                $tags = [];
                foreach (is_array($q['tags'] ?? null) ? $q['tags'] : [] as $tag) {
                    if (!is_string($tag) || trim($tag) === '' || mb_strlen($tag) > 40) {
                        throw new McpError("$at.tags entries must be strings of at most 40 characters.");
                    }
                    $tags[] = trim($tag);
                }
                $clean[] = [
                    'client_key'         => $key,
                    'subject_slug'       => $slug,
                    'topic_refs'         => $refs,
                    'marks'              => (int) $marks,
                    'question_md'        => mcp_str($q, 'question_md', true, 1, EXAM_QUESTION_MAX),
                    'mark_scheme_md'     => mcp_str($q, 'mark_scheme_md', true, 1, EXAM_QUESTION_MAX),
                    'model_answer_md'    => mcp_str($q, 'model_answer_md', false, 0, EXAM_QUESTION_MAX),
                    'paper_style'        => mcp_str($q, 'paper_style', false, 0, 40),
                    'calculator'         => !empty($q['calculator']),
                    'command_word'       => mcp_str($q, 'command_word', false, 0, 40),
                    'time_guide_seconds' => $guide === null ? null : (int) $guide,
                    'tags'               => array_values(array_unique($tags)),
                    'source_note'        => mcp_str($q, 'source_note', false, 0, 300),
                ];
            }
            $lines = [];
            $added = 0;
            $store->transaction(function () use ($store, $clean, &$lines, &$added): void {
                foreach ($clean as $q) {
                    $existing = $store->examQuestionByClientKey($q['client_key']);
                    if ($existing) {
                        $lines[] = "#{$existing['id']} {$q['client_key']} — already stored as {$existing['status']}, unchanged";
                        continue;
                    }
                    $id = $store->addExamQuestion($q);
                    $added++;
                    $lines[] = "#$id {$q['client_key']} — {$q['subject_slug']} " . implode('/', $q['topic_refs'])
                        . ', ' . exam_marks_word($q['marks']) . ', draft';
                }
            });
            return mcp_text(($added === 1 ? '1 question' : "$added questions") . ' added to the bank as draft.'
                . "\n" . implode("\n", $lines)
                . "\n\nVet each with tracker_exam_update_question(id, action: \"vet\") before scheduling it.");
        }

        case 'tracker_exam_list_questions': {
            $filter = [
                'subject' => mcp_str($a, 'subject', false, 0, 80),
                'status'  => mcp_str($a, 'status', false, 0, 20),
                'tag'     => mcp_str($a, 'tag', false, 0, 40),
                'limit'   => (int) ($a['limit'] ?? 100),
            ];
            if ($filter['status'] !== null && !in_array($filter['status'], EXAM_QUESTION_STATUSES, true)) {
                throw new McpError('status must be one of ' . implode(', ', EXAM_QUESTION_STATUSES) . '.');
            }
            $rows = $store->listExamQuestions($filter);
            if (!$rows) {
                return mcp_text('No questions in the bank for that filter.');
            }
            $byStatus = [];
            foreach ($store->listExamQuestions(array_diff_key($filter, ['status' => 1, 'limit' => 1])) as $q) {
                $byStatus[$q['status']] = ($byStatus[$q['status']] ?? 0) + 1;
            }
            $counts = [];
            foreach (EXAM_QUESTION_STATUSES as $st) {
                if (isset($byStatus[$st])) {
                    $counts[] = $byStatus[$st] . ' ' . $st;
                }
            }
            $lines = ['Question bank' . ($filter['subject'] ? ' — ' . $filter['subject'] : '') . ': ' . implode(', ', $counts) . '.'];
            $lines[] = 'Parent only: never paste these into a chat the student can see.';
            $lines[] = '';
            foreach ($rows as $q) {
                $lines[] = mcp_exam_question_line($q);
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_exam_update_question': {
            $id     = (int) ($a['id'] ?? 0);
            $action = mcp_str($a, 'action', true, 1, 10);
            $note   = mcp_str($a, 'note', false, 0, 300);
            $q      = $id > 0 ? $store->examQuestion($id) : null;
            if (!$q) {
                throw new McpError("no question #$id in the bank.");
            }
            $testId = $store->examTestIdOfQuestion($id);
            $test   = $testId !== null ? $store->examTestRow($testId) : null;
            $appendNote = static fn(?string $old, string $line): string => trim(($old ? $old . "\n" : '') . $line);
            $stamp = tt_today();
            switch ($action) {
                case 'vet':
                    if (!in_array($q['status'], ['draft', 'vetted'], true)) {
                        throw new McpError("#$id is {$q['status']}; only a draft can be vetted.");
                    }
                    $store->updateExamQuestion($id, [
                        'status'    => 'vetted',
                        'vetted_at' => tt_now_utc(),
                        'note'      => $appendNote($q['note'], "$stamp vetted" . ($note ? ': ' . $note : '')),
                    ]);
                    return mcp_text("#$id vetted. It can now go into a test with tracker_exam_schedule_test.");
                case 'retire':
                    if ($test !== null) {
                        throw new McpError("#$id is in test #{$test['id']} ({$test['status']}); take it out first with "
                            . 'tracker_exam_update_test (action "edit" with new sections, or action "cancel"), then retire it.');
                    }
                    if ($q['status'] === 'retired') {
                        return mcp_text("#$id is already retired.");
                    }
                    $store->updateExamQuestion($id, [
                        'status' => 'retired',
                        'note'   => $appendNote($q['note'], "$stamp retired" . ($note ? ': ' . $note : '')),
                    ]);
                    return mcp_text("#$id retired. It stays in the bank for the record and will never be scheduled.");
                case 'edit':
                    $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
                    if (!$fields) {
                        throw new McpError('edit needs a fields object naming what to change.');
                    }
                    $allowed = ['topic_refs', 'marks', 'question_md', 'mark_scheme_md', 'model_answer_md', 'paper_style',
                        'calculator', 'command_word', 'time_guide_seconds', 'tags', 'source_note'];
                    $set = [];
                    foreach ($fields as $col => $val) {
                        if (!in_array($col, $allowed, true)) {
                            throw new McpError("fields.$col cannot be edited here (allowed: " . implode(', ', $allowed) . ').');
                        }
                        switch ($col) {
                            case 'topic_refs':
                                $refs  = mcp_ref_list($val, 'fields.topic_refs', 6);
                                $known = array_column($store->listTopics($q['subject_slug']), 'ref');
                                foreach ($refs as $ref) {
                                    if (!in_array($ref, $known, true)) {
                                        throw new McpError("fields.topic_refs names '$ref', which {$q['subject_slug']} does not hold.");
                                    }
                                }
                                if (!$refs) {
                                    throw new McpError('fields.topic_refs must name at least one ref.');
                                }
                                $set[$col] = $refs;
                                break;
                            case 'marks':
                                $m = mcp_num($fields, 'marks', true, 1, 40);
                                if ($m != (int) $m) {
                                    throw new McpError('fields.marks must be a whole number.');
                                }
                                $scored = $store->examQuestionScore($id);
                                if ($scored !== null && $m < $scored) {
                                    throw new McpError("fields.marks cannot go below the " . num($scored) . " already scored on #$id.");
                                }
                                $set[$col] = (int) $m;
                                break;
                            case 'time_guide_seconds':
                                if (!is_numeric($val) || $val < 10 || $val > 3600) {
                                    throw new McpError('fields.time_guide_seconds must be between 10 and 3600.');
                                }
                                $set[$col] = (int) $val;
                                break;
                            case 'calculator':
                                $set[$col] = !empty($val);
                                break;
                            case 'tags':
                                $set[$col] = mcp_ref_list($val, 'fields.tags', 10);
                                break;
                            default:
                                $max = in_array($col, ['question_md', 'mark_scheme_md', 'model_answer_md'], true) ? EXAM_QUESTION_MAX : 300;
                                $set[$col] = mcp_str($fields, $col, $col !== 'model_answer_md' && $col !== 'source_note' && $col !== 'paper_style' && $col !== 'command_word', 0, $max);
                        }
                    }
                    $changed = implode(', ', array_keys($fields));
                    $set['note'] = $appendNote($q['note'], "$stamp edited $changed" . ($note ? ': ' . $note : ''));
                    // In the bank, an edit sends a vetted question back to
                    // draft: what was checked is no longer what is stored.
                    // In a test, it is the parent correcting the paper, and
                    // the question keeps its place and its status.
                    if ($test === null) {
                        if (in_array($q['status'], ['draft', 'vetted'], true)) {
                            $set['status']    = 'draft';
                            $set['vetted_at'] = null;
                        }
                        $store->updateExamQuestion($id, $set);
                        return mcp_text("#$id edited ($changed)"
                            . ($q['status'] === 'retired' ? '; it stays retired.' : ' and returned to draft. Vet it again before scheduling.'));
                    }
                    $store->updateExamQuestion($id, $set);
                    $where = "#$id edited ($changed) in place; it stays in test #{$test['id']} as {$q['status']}.";
                    switch ($test['status']) {
                        case 'open':
                            $where .= ' The sitting is running: she sees the change when her page next reloads.';
                            break;
                        case 'closed':
                            $where .= ' Mark it against the corrected scheme.';
                            break;
                        case 'marked':
                            $where .= ' The attempt written at marking keeps its copy of the question and score; correct that with tracker_amend_session if it matters.';
                            break;
                    }
                    return mcp_text($where);
                default:
                    throw new McpError("action must be vet, edit or retire.");
            }
        }

        case 'tracker_exam_schedule_test': {
            $name     = mcp_str($a, 'name', true, 1, 120);
            $date     = mcp_date($a, 'scheduled_for', null);
            if ($date === null) {
                throw new McpError('scheduled_for is required (YYYY-MM-DD).');
            }
            $duration = mcp_num($a, 'duration_minutes', true, EXAM_MIN_MINUTES, EXAM_MAX_MINUTES);
            $duration = (int) $duration;
            $instr    = mcp_str($a, 'instructions', false, 0, 2000);
            $note     = mcp_str($a, 'note', false, 0, 500);
            $blockKey = isset($a['block_key']) ? (int) $a['block_key'] : null;
            if ($blockKey !== null) {
                mcp_check_block($store, $blockKey, $date, EXAM_SUBJECT);
            }
            $clash = $store->examTestOnDate($date, ['ready', 'open']);
            if ($clash) {
                throw new McpError("test #{$clash['id']} \"{$clash['name']}\" is already {$clash['status']} on $date. One test per date.");
            }
            [$clean, $guides, $marks] = mcp_exam_clean_sections($store, $a['sections'] ?? null, null);
            $rows = exam_labels($clean);
            $id   = $store->addExamTest([
                'name' => $name, 'scheduled_for' => $date, 'block_key' => $blockKey,
                'duration_minutes' => $duration, 'instructions' => $instr, 'note' => $note,
            ], $rows);
            $lines = ["Test #$id \"$name\" scheduled for $date: " . count($rows) . ' question' . (count($rows) === 1 ? '' : 's')
                . ', ' . exam_marks_word($marks) . ', ' . exam_minutes_word($duration)
                . ($blockKey ? ", block #$blockKey" : ', no block named') . '.'];
            foreach ($clean as $s) {
                $labels = [];
                $sMarks = 0;
                foreach ($rows as $r) {
                    if ($r['section'] === $s['subject']) {
                        $labels[] = 'Q' . $r['label'];
                        $sMarks  += $store->examQuestion($r['question_id'])['marks'];
                    }
                }
                $lines[] = '- ' . $s['subject'] . ': ' . implode(', ', $labels) . ' — ' . exam_marks_word($sMarks)
                    . ($s['minutes_guide'] !== null ? ', guide ' . $s['minutes_guide'] . ' min' : '');
            }
            if ($guides > $duration) {
                $lines[] = "WARNING: the section guides add up to $guides min, more than the $duration-minute duration.";
            } elseif ($guides > 0 && $guides < $duration - 10) {
                $lines[] = "Note: the guides add up to $guides min of $duration; the rest is reading and checking time.";
            }
            $lines[] = '';
            $lines[] = "She starts it at /exam/$id on the day. The questions stay hidden until she presses Start; the timer runs from then and cannot be paused.";
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_exam_update_test': {
            $id     = (int) ($a['id'] ?? 0);
            $action = mcp_str($a, 'action', true, 1, 10);
            $why    = mcp_str($a, 'note', false, 0, 300);
            $t      = $id > 0 ? $store->examTestRow($id) : null;
            if (!$t) {
                throw new McpError("no exam test #$id.");
            }
            $t     = $store->lazyCloseExamTest($t);
            $stamp = tt_today();

            if ($action === 'cancel') {
                if ($t['status'] === 'marked') {
                    throw new McpError("test #$id is marked; its attempts are the record and it cannot be cancelled. "
                        . 'Rename it or add a note with action "edit", or correct the attempts with tracker_amend_session.');
                }
                $seen    = $t['status'] !== 'ready';
                $requeue = !$seen || !empty($a['requeue']);
                $status  = $requeue ? 'vetted' : 'retired';
                $qids    = $store->cancelExamTest($id, $status,
                    "$stamp test #$id \"{$t['name']}\" cancelled" . ($seen ? ' after she started it' : '') . ($why ? ': ' . $why : ''));
                return mcp_text("Test #$id \"{$t['name']}\" ({$t['scheduled_for']}, was {$t['status']}) cancelled"
                    . ($seen ? ', with the answers saved in it' : '') . '. '
                    . count($qids) . ' question' . (count($qids) === 1 ? '' : 's')
                    . ($qids ? ' (#' . implode(', #', $qids) . ')' : '') . ' set to ' . $status
                    . ($requeue ? ' and free to go into another test.' : ' because she has seen them; pass requeue: true to reuse them.'));
            }
            if ($action !== 'edit') {
                throw new McpError('action must be edit or cancel.');
            }

            $fields = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            if (!$fields) {
                throw new McpError('edit needs a fields object naming what to change.');
            }
            $byStatus = [
                'ready'  => ['name', 'scheduled_for', 'duration_minutes', 'block_key', 'instructions', 'note', 'sections'],
                'open'   => ['name', 'duration_minutes', 'instructions', 'note'],
                'closed' => ['name', 'instructions', 'note'],
                'marked' => ['name', 'instructions', 'note'],
            ];
            $allowed = $byStatus[$t['status']] ?? [];
            foreach (array_keys($fields) as $col) {
                if (!in_array($col, $byStatus['ready'], true)) {
                    throw new McpError("fields.$col is not a test field (fields: " . implode(', ', $byStatus['ready']) . ').');
                }
                if (!in_array($col, $allowed, true)) {
                    throw new McpError("test #$id is {$t['status']}, so fields.$col can no longer change (a {$t['status']} test may change "
                        . implode(', ', $allowed) . '). To rebuild it, cancel it and schedule a new one.');
                }
            }

            $set = [];
            if (array_key_exists('name', $fields)) {
                $set['name'] = mcp_str($fields, 'name', true, 1, 120);
            }
            if (array_key_exists('instructions', $fields)) {
                $set['instructions'] = mcp_str($fields, 'instructions', false, 0, 2000);
            }
            if (array_key_exists('note', $fields)) {
                $set['note'] = mcp_str($fields, 'note', false, 0, 500);
            }
            if (array_key_exists('duration_minutes', $fields)) {
                $set['duration_minutes'] = (int) mcp_num($fields, 'duration_minutes', true, EXAM_MIN_MINUTES, EXAM_MAX_MINUTES);
                if ($t['status'] === 'open') {
                    $set['deadline_at'] = exam_add_seconds((string) $t['started_at'], $set['duration_minutes'] * 60);
                }
            }
            $date = $t['scheduled_for'];
            if (array_key_exists('scheduled_for', $fields)) {
                $date = mcp_date($fields, 'scheduled_for', null);
                if ($date === null) {
                    throw new McpError('fields.scheduled_for must be a date (YYYY-MM-DD).');
                }
                $clash = $store->examTestOnDate($date, ['ready', 'open']);
                if ($clash && $clash['id'] !== $id) {
                    throw new McpError("test #{$clash['id']} \"{$clash['name']}\" is already {$clash['status']} on $date. One test per date.");
                }
                $set['scheduled_for'] = $date;
            }
            $blockKey = array_key_exists('block_key', $fields)
                ? ($fields['block_key'] === null ? null : (int) $fields['block_key'])
                : $t['block_key'];
            if (array_key_exists('block_key', $fields) || array_key_exists('scheduled_for', $fields)) {
                if ($blockKey !== null) {
                    mcp_check_block($store, $blockKey, $date, EXAM_SUBJECT);
                }
                $set['block_key'] = $blockKey;
            }
            $rows = null;
            $guides = 0;
            if (array_key_exists('sections', $fields)) {
                [$clean, $guides] = mcp_exam_clean_sections($store, $fields['sections'], $id);
                $rows = exam_labels($clean);
            }
            if ($why !== null) {
                $set['note'] = trim((($set['note'] ?? $t['note']) ? ($set['note'] ?? $t['note']) . "\n" : '')
                    . "$stamp edited " . implode(', ', array_keys($fields)) . ': ' . $why);
            }

            $store->transaction(function () use ($store, $id, $set, $rows): void {
                $store->updateExamTest($id, $set);
                if ($rows !== null) {
                    $store->replaceExamTestQuestions($id, $rows);
                }
            });
            $row   = $store->lazyCloseExamTest($store->examTestRow($id));
            $fresh = $store->examTest($id);
            $lines = ["Test #$id edited (" . implode(', ', array_keys($fields)) . ').'];
            $lines = array_merge($lines, mcp_exam_test_head($fresh));
            if ($rows !== null) {
                $lines = array_merge($lines, mcp_exam_section_summary($fresh));
            }
            $duration = $fresh['duration_minutes'];
            if ($rows !== null && $guides > $duration) {
                $lines[] = "WARNING: the section guides add up to $guides min, more than the $duration-minute duration.";
            }
            if ($t['status'] === 'open' && isset($set['deadline_at'])) {
                $lines[] = $row['status'] === 'closed'
                    ? 'The new deadline had already passed, so the sitting is now closed and ready for marking.'
                    : 'Her page picks up the new deadline at its next save (within 20 seconds).';
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_exam_list_tests': {
            $status = mcp_str($a, 'status', false, 0, 20);
            if ($status !== null && !in_array($status, EXAM_TEST_STATUSES, true)) {
                throw new McpError('status must be one of ' . implode(', ', EXAM_TEST_STATUSES) . '.');
            }
            $filter = [
                'status' => $status,
                'from'   => mcp_date($a, 'from', null),
                'to'     => mcp_date($a, 'to', null),
                'limit'  => (int) ($a['limit'] ?? 20),
            ];
            // A timer that has run out ends the sitting whether or not the
            // page was reloaded: close before reporting.
            foreach ($store->listExamTests(['status' => 'open']) as $t) {
                $store->lazyCloseExamTest($t);
            }
            $rows = $store->listExamTests($filter);
            if (!$rows) {
                return mcp_text('No exam-practice tests' . ($status ? " with status $status" : '') . '.');
            }
            $lines = [];
            foreach ($rows as $t) {
                $when = '';
                if ($t['started_at']) {
                    [$d, $at] = tt_local((string) $t['started_at']);
                    $when = " · sat $d $at" . ($t['sat_minutes'] !== null ? ', ' . $t['sat_minutes'] . ' min' : '')
                        . ($t['closed_by'] ? ', closed by ' . $t['closed_by'] : '');
                }
                $lines[] = sprintf('#%d %s — %s · %s · %d q · %s · %s%s%s',
                    $t['id'], $t['name'], $t['scheduled_for'], $t['status'], $t['question_count'],
                    exam_marks_word($t['marks']), exam_minutes_word($t['duration_minutes']),
                    $t['block_key'] ? ' · block #' . $t['block_key'] : '', $when)
                    . ($t['status'] === 'closed' ? ' · READY FOR MARKING (tracker_exam_get_test then tracker_exam_mark_test)' : '')
                    . ($t['status'] === 'ready' ? " · waiting: /exam/{$t['id']}" : '');
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_exam_get_test': {
            $id = (int) ($a['id'] ?? 0);
            $t  = $id > 0 ? $store->examTestRow($id) : null;
            if (!$t) {
                throw new McpError("no exam test #$id.");
            }
            $t = $store->lazyCloseExamTest($t);
            $t = $store->examTest((int) $t['id']);
            $includeBank = !empty($a['include_bank']);
            $sat         = in_array($t['status'], ['closed', 'marked'], true);
            $withSchemes = $sat || $includeBank;
            $lines = mcp_exam_test_head($t);
            if (!$sat && !$includeBank) {
                $lines[] = 'The mark schemes are withheld until the sitting is over: the test is ' . $t['status'] . '. '
                    . '(The parent\'s project may pass include_bank: true to check the paper before the day.)';
            }
            if ($t['status'] === 'closed') {
                $lines[] = 'READY FOR MARKING: mark every question against its scheme in the subject\'s own convention, then call tracker_exam_mark_test.';
            }
            $lines = array_merge($lines, mcp_exam_section_lines($t, $sat, $withSchemes));
            if ($sat) {
                $order = [];
                foreach ($t['sections'] as $s) {
                    foreach ($s['questions'] as $q) {
                        if ($q['answer'] !== null) {
                            $order[] = ['label' => $q['label'], 'at' => $q['answer']['saved_at'], 't' => $q['answer']['time_spent_seconds']];
                        }
                    }
                }
                usort($order, static fn($x, $y) => $x['at'] <=> $y['at']);
                if ($order) {
                    $lines[] = '';
                    $lines[] = 'Last saved in order: ' . implode(', ', array_map(static fn($o) => 'Q' . $o['label'], $order))
                        . '. Time per question is what the page measured while each question had focus; treat it as a guide.';
                }
            }
            return mcp_text(implode("\n", $lines));
        }

        case 'tracker_exam_mark_test': {
            $id = (int) ($a['id'] ?? 0);
            $t  = $id > 0 ? $store->examTestRow($id) : null;
            if (!$t) {
                throw new McpError("no exam test #$id.");
            }
            $t = $store->lazyCloseExamTest($t);
            if ($t['status'] === 'marked') {
                throw new McpError("test #$id is already marked. The attempts it wrote are the record; correct one with tracker_amend_session or by logging a note.");
            }
            if ($t['status'] !== 'closed') {
                throw new McpError("test #$id is {$t['status']}; only a closed test can be marked."
                    . ($t['status'] === 'open' ? ' The sitting is still running.' : ' She has not sat it yet.'));
            }
            $t = $store->examTest($id);
            $testNote = mcp_str($a, 'note', false, 0, 1000);
            $raw = is_array($a['marks'] ?? null) ? $a['marks'] : [];
            if (!$raw) {
                throw new McpError('marks must be a non-empty array of { question_id, score }.');
            }
            $byQ = [];
            foreach ($t['sections'] as $s) {
                foreach ($s['questions'] as $q) {
                    $byQ[$q['id']] = $q;
                }
            }
            $marks = [];
            foreach (array_values($raw) as $i => $m) {
                $at = "marks[$i]";
                if (!is_array($m)) {
                    throw new McpError("$at must be an object.");
                }
                $qid = (int) ($m['question_id'] ?? 0);
                if (!isset($byQ[$qid])) {
                    throw new McpError("$at names question #$qid, which is not in test #$id.");
                }
                if (isset($marks[$qid])) {
                    throw new McpError("$at marks question #$qid twice.");
                }
                $score = mcp_num($m, 'score', true, 0);
                if ($score > $byQ[$qid]['marks']) {
                    throw new McpError("$at scores " . num($score) . " on question #$qid (Q{$byQ[$qid]['label']}), which carries "
                        . exam_marks_word($byQ[$qid]['marks']) . '.');
                }
                $marks[$qid] = [
                    'question_id'      => $qid,
                    'score'            => $score,
                    'note'             => mcp_str($m, 'note', false, 0, 500),
                    'student_feedback' => mcp_str($m, 'student_feedback', false, 0, EXAM_FEEDBACK_MAX),
                ];
            }
            $missing = array_diff(array_keys($byQ), array_keys($marks));
            if ($missing) {
                throw new McpError('every question must be marked in one call; missing question #'
                    . implode(', #', $missing) . ' (Q' . implode(', Q', array_map(static fn($q) => $byQ[$q]['label'], $missing)) . ').');
            }

            $sitDate = exam_sat_date($t);
            $satMin  = $t['sat_minutes'];
            $result  = $store->transaction(function () use ($store, $t, $marks, $sitDate, $satMin, $testNote): array {
                $store->saveExamMarks($t['id'], array_values($marks));
                $out = [];
                foreach ($t['sections'] as $s) {
                    $subject = $store->getSubject($s['subject']);
                    $qs      = [];
                    $score   = 0.0;
                    $max     = 0.0;
                    $blanks  = 0;
                    foreach ($s['questions'] as $q) {
                        $m   = $marks[$q['id']];
                        $ans = $q['answer'];
                        $blank = $ans === null || exam_is_blank($ans['answer']);
                        if ($blank) {
                            $blanks++;
                        }
                        $written = $blank ? '' : trim((string) $ans['answer']);
                        if ($ans !== null && !exam_is_blank($ans['working'] ?? null)) {
                            $written .= ($written === '' ? '' : "\n\n") . "Working:\n" . trim((string) $ans['working']);
                        }
                        $qs[] = [
                            'number'    => $q['label'],
                            'score'     => $m['score'],
                            'max'       => (float) $q['marks'],
                            'topic_ref' => $q['topic_refs'][0] ?? null,
                            'question'  => mb_substr(trim((string) $q['question_md']), 0, 1000),
                            'answer'    => $blank && $written === '' ? '(blank)' : mb_substr($written, 0, 1000),
                            'note'      => $m['note'] === null ? null : mb_substr($m['note'], 0, 500),
                        ];
                        $score += $m['score'];
                        $max   += (float) $q['marks'];
                    }
                    $attemptId = $store->addAttempt([
                        'subject_slug' => $s['subject'],
                        'date'         => $sitDate,
                        'name'         => $t['name'] . ' — ' . ($subject['name'] ?? $s['subject']) . ' section',
                        'kind'         => 'check',
                        'tier'         => mcp_exam_tier($subject ?? []),
                        'note'         => 'Exam Skills test #' . $t['id'] . ', typed on the portal, sat '
                            . ($satMin ?? $t['duration_minutes']) . ' min for the whole paper.',
                        'papers'       => [[
                            'code'      => 'exam-skills #' . $t['id'],
                            'score'     => $score,
                            'max'       => $max,
                            'blanks'    => $blanks,
                            'sat_on'    => $sitDate,
                            'questions' => $qs,
                        ]],
                    ]);
                    $aqIds = $store->examAttemptQuestionIds($attemptId);
                    foreach ($s['questions'] as $i => $q) {
                        if (isset($aqIds[$i])) {
                            $store->setExamAnswerAttemptQuestion($t['id'], $q['id'], $aqIds[$i]);
                        }
                    }
                    $out[] = ['subject' => $s['subject'], 'attempt_id' => $attemptId, 'score' => $score, 'max' => $max,
                        'blanks' => $blanks, 'questions' => count($qs)];
                }
                $store->setExamTestMarked($t['id'], $testNote);
                return $out;
            });

            $fresh = $store->examTest($id);
            $lines = ["Test #$id \"{$t['name']}\" marked. Sat $sitDate" . ($satMin !== null ? ", $satMin of {$t['duration_minutes']} min" : '') . '.'];
            $lines[] = '';
            $lines[] = 'One attempt per subject (kind check, never grade-converted):';
            foreach ($result as $r) {
                $lines[] = "- {$r['subject']}: " . num($r['score']) . '/' . num($r['max']) . " over {$r['questions']} question"
                    . ($r['questions'] === 1 ? '' : 's') . ", {$r['blanks']} blank — attempt #{$r['attempt_id']}, /s/{$r['subject']}/a/{$r['attempt_id']}";
            }
            $lines[] = '';
            $lines[] = 'Question by question:';
            foreach ($fresh['sections'] as $s) {
                foreach ($s['questions'] as $q) {
                    $ans = $q['answer'];
                    $lines[] = sprintf('- Q%s %s %s %s/%d · %s · %d min%s%s',
                        $q['label'], $s['subject'], implode('/', $q['topic_refs']),
                        num($ans['score']), $q['marks'],
                        exam_is_blank($ans['answer']) ? 'BLANK' : 'attempted',
                        (int) round($ans['time_spent_seconds'] / 60),
                        $ans['flagged'] ? ', flagged' : '',
                        $ans['marker_note'] ? ' — ' . $ans['marker_note'] : '');
                }
            }
            $lines[] = '';
            $lines[] = 'Now log the sessions, dated ' . $sitDate . ':';
            $lines[] = '- one tracker_log_session per subject above, with a review (errors typed per question, a retrieval_outcome per ref, no status change) and NO block_key;';
            $lines[] = '- one tracker_log_session for ' . EXAM_SUBJECT . ' with the technique observations (time per section against its guide, order, flags, blanks, marks lost to technique rather than knowledge), with NO block_key and NO duration_minutes — the sitting is already the block\'s evidence and its hours.';
            $lines[] = 'Then tell her the marks per section and the feedback lines; never the answers.';
            return mcp_text(implode("\n", $lines));
        }
    }

    throw new McpError("Unknown tool \"$name\".");
}
