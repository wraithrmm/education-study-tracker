<?php
/**
 * The vocabulary app's two tools: read the sets, write the sets. The games
 * themselves log through /s/<subject>/play/run, not through a tool, because
 * the browser is the one that saw the game. Definitions in
 * mcp_vocab_tools(), dispatch in mcp_vocab_call().
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

function mcp_vocab_tools(array $readOnly, array $write, array $subjectArg): array
{
    $wordSchema = [
        'type'       => 'object',
        'properties' => [
            'word'          => ['type' => 'string', 'maxLength' => VOCAB_MAX_WORD_CHARS,
                'description' => 'The target-language word or phrase. `spanish`, `french` or `german` is accepted in its place, so a set pasted from the old app goes in as it was.'],
            'english'       => ['type' => 'string', 'maxLength' => VOCAB_MAX_WORD_CHARS],
            'pronunciation' => ['type' => 'string', 'maxLength' => VOCAB_MAX_WORD_CHARS,
                'description' => "Optional UK-friendly guide, e.g. 'ab-LAR'"],
        ],
        'required'   => ['english'],
    ];
    return [
        [
            'name'  => 'tracker_list_vocab_sets',
            'title' => 'List the vocabulary app\'s sets',
            'description' =>
                "The sets the vocabulary app at /s/{subject}/play runs over: number, name, the topics each teaches, "
                . "its words, and how far she has got with it — games played, best accuracy, stars.\n\n"
                . "USE WHEN: planning a Spanish slot (which sets she has met, which are due), deciding what the next set should be, "
                . "answering \"which set is she on\", or before tracker_save_vocab_sets so you append after the last number and never renumber.\n\n"
                . "Stars are the subject's secure bar applied to the game: one for played, two for a game at 80%+, three for a second 80%+ game "
                . "at least " . VOCAB_SPACING_DAYS . " days later. The per-topic steps say which topic the evidence could move and how.\n\n"
                . "Args: subject. Optional words: false to leave the word lists out.\nRead-only.",
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'words'   => ['type' => 'boolean', 'default' => true, 'description' => 'Include every word'],
                ],
                'required'   => ['subject'],
            ],
            'annotations' => $readOnly,
        ],
        [
            'name'  => 'tracker_save_vocab_sets',
            'title' => 'Add or replace vocabulary sets',
            'description' =>
                "Writes sets for the vocabulary app: one object per set, upserted by number. A number that exists is replaced whole; "
                . "a new number is added. Nothing else is touched, and nothing is written if any set is malformed.\n\n"
                . "USE WHEN: the language-learning-platform skill has built a new set (append after the last number from tracker_list_vocab_sets), "
                . "a word in a set needs correcting, or a set should name the syllabus topics it teaches. "
                . "To remove a set pass { number, delete: true }.\n\n"
                . "Every set should carry topic_refs: the refs from tracker_get_state the set teaches (the theme, and the grammar point where "
                . "the set is one — e.g. [\"T04\", \"G22\"]). They are what joins a game to the record: each word shown becomes a practice item "
                . "under the set's first ref, so the per-topic board and the retrieval schedule see it, and the steps on the page read the games "
                . "against that topic's status. A set with no refs is playable but moves nothing.\n\n"
                . "Keep sets at 8–20 words for a 15-minute slot; never renumber existing sets, because the practice log and her stars are keyed on the number.\n\n"
                . "Args: subject, sets[] of { number, name, topic_refs?, words[] of { word, english, pronunciation? }, note?, delete? }. Up to 50 sets per call.",
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'subject' => $subjectArg,
                    'sets'    => [
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 50,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'number'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => VOCAB_MAX_SETS,
                                    'description' => 'The set\'s position. Append after the last; never renumber.'],
                                'name'       => ['type' => 'string', 'maxLength' => 80],
                                'topic_refs' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'string', 'maxLength' => 40],
                                    'description' => 'Topic refs this set teaches, theme first. trackerRefs, as the app file spelt it, is accepted too.'],
                                'words'      => ['type' => 'array', 'minItems' => 1, 'maxItems' => VOCAB_MAX_WORDS, 'items' => $wordSchema],
                                'note'       => ['type' => 'string', 'maxLength' => 300, 'description' => 'Where the list came from, or how to use it'],
                                'delete'     => ['type' => 'boolean', 'description' => 'true removes the set with this number'],
                            ],
                            'required'   => ['number'],
                        ],
                    ],
                ],
                'required'   => ['subject', 'sets'],
            ],
            'annotations' => $write,
        ],
    ];
}

function mcp_vocab_call(Store $store, string $name, array $a): array
{
    $slug = mcp_str($a, 'subject', true, 1);
    $r    = mcp_resolve($store, $slug);
    if (isset($r['error'])) {
        return mcp_text($r['error']);
    }
    $subject = $r['subject'];

    if ($name === 'tracker_list_vocab_sets') {
        $sets = vocab_list_sets($store, $slug);
        if (!$sets) {
            return mcp_text("No vocabulary sets for {$subject['name']} yet. Add them with tracker_save_vocab_sets; "
                . "the app at /s/$slug/play plays whatever is stored.");
        }
        $withWords = !array_key_exists('words', $a) || $a['words'] !== false;
        $steps     = vocab_steps($store, $subject, $sets);
        $lines     = ["**Vocabulary sets — {$subject['name']}** · " . count($sets) . ' sets · app at /s/' . $slug . '/play', ''];
        $lines[]   = '| Set | Name | Words | Topics | Games | Best | Stars | Next step |';
        $lines[]   = '|---|---|---|---|---|---|---|---|';
        foreach ($sets as $s) {
            $p = $steps['sets'][$s['number']];
            $lines[] = '| ' . $s['number'] . ' | ' . str_replace('|', '/', $s['name']) . ' | ' . count($s['words'])
                . ' | ' . (implode(' ', $s['topic_refs']) ?: '—') . ' | ' . $p['runs']
                . ($p['last'] ? ' (last ' . $p['last'] . ')' : '')
                . ' | ' . ($p['best'] === null ? '—' : (int) round($p['best'] * 100) . '%')
                . ' | ' . str_repeat('★', $p['stars']) . str_repeat('☆', 3 - $p['stars'])
                . ' | ' . $p['next'] . ' |';
        }
        if ($steps['topics']) {
            $lines[] = '';
            $lines[] = 'Topic steps (what the games say, against each topic\'s status):';
            foreach ($steps['topics'] as $t) {
                $lines[] = '- ' . $t['ref'] . ' ' . ($t['name'] ?? '?') . ' — ' . (STATUS_LABEL[$t['status'] ?? ''] ?? 'unknown')
                    . '; sets ' . implode(', ', $t['sets']) . '; ' . $t['runs'] . ' games, ' . $t['good'] . ' at 80%+'
                    . ($t['suggest'] ? ' → READY to mark ' . $t['suggest'] . ' (the parent does it on the page, or log a session with: '
                        . $t['evidence'] . ')' : ' — ' . $t['why']);
            }
        }
        if ($withWords) {
            foreach ($sets as $s) {
                $lines[] = '';
                $lines[] = '### Set ' . $s['number'] . ': ' . $s['name'] . ($s['note'] ? ' — ' . $s['note'] : '');
                foreach ($s['words'] as $w) {
                    $lines[] = '- ' . $w['word'] . ' — ' . $w['english'] . (isset($w['pronunciation']) ? ' (' . $w['pronunciation'] . ')' : '');
                }
            }
        }
        $lines[] = '';
        $lines[] = 'Append new sets after number ' . max(array_column($sets, 'number')) . '. Game runs log themselves from the app; '
            . 'a status still moves only through a session — the page offers the parent the move the games support.';
        return mcp_text(implode("\n", $lines));
    }

    if ($name === 'tracker_save_vocab_sets') {
        $sets = is_array($a['sets'] ?? null) ? $a['sets'] : [];
        if (!$sets) {
            throw new McpError('sets must be a non-empty array.');
        }
        $deletes = [];
        $saves   = [];
        foreach ($sets as $i => $set) {
            if (!is_array($set)) {
                throw new McpError('set ' . ($i + 1) . ' must be an object.');
            }
            if (!empty($set['delete'])) {
                $n = (int) ($set['number'] ?? 0);
                if ($n < 1) {
                    throw new McpError('set ' . ($i + 1) . ': delete needs the set\'s number.');
                }
                $deletes[] = $n;
            } else {
                $saves[] = $set;
            }
        }
        try {
            $written = $saves ? vocab_save_sets($store, $slug, $saves) : [];
        } catch (InvalidArgumentException $e) {
            throw new McpError($e->getMessage());
        }
        $gone = [];
        foreach ($deletes as $n) {
            if (vocab_delete_set($store, $slug, $n)) {
                $gone[] = $n;
            }
        }
        $out = ['Vocabulary sets for ' . $subject['name'] . ': '
            . ($written ? 'saved ' . count($written) . ' (numbers ' . implode(', ', $written) . ')' : 'nothing saved')
            . ($deletes ? '; deleted ' . (count($gone) ? implode(', ', $gone) : 'none') : '') . '.'];
        $missingRefs = array_filter($saves, static fn($s) => empty($s['topic_refs']));
        if ($missingRefs) {
            $out[] = 'Note: ' . count($missingRefs) . ' set' . (count($missingRefs) === 1 ? ' has' : 's have') . ' no topic_refs, so games on '
                . (count($missingRefs) === 1 ? 'it' : 'them') . ' will not join to a topic. Add the refs when you know them.';
        }
        $out[] = 'The app at /s/' . $slug . '/play shows the change on its next load.';
        return mcp_text(implode("\n", $out));
    }

    throw new McpError("Unknown tool \"$name\".");
}
