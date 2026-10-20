#!/usr/bin/env php
<?php
/**
 * Turn the AQA 8692 master vocabulary list into vocabulary-app sets.
 *
 *   php deploy/import-vocab-csv.php aqa8692mastervocabulary.csv [--size=15] [--start=7] > sets.json
 *
 * The list has one row per word — item, english, word_class, tier,
 * aqa_units, tracker_topic — either as a real CSV with that header, or as
 * the same six fields one per line (the shape a copy out of a spreadsheet
 * viewer arrives in). Rows are grouped by tracker topic, tier and word
 * class, and each group cut into sets of --size words, named the way the
 * old app named them ("Identity 8: Adjectives", "Identity 14: Verbs
 * (Higher)"), numbered from --start so they append after the sets a subject
 * already has. Every set carries its tracker topic as topic_refs, and every
 * word keeps its tier and word class.
 *
 * The output is the JSON tracker_save_vocab_sets takes, and the shape of
 * php/lib/seed/<slug>-vocab.json. Nothing is written to any database here.
 */
declare(strict_types=1);

$args  = array_slice($argv, 1);
$file  = null;
$size  = 15;
$start = 1;
foreach ($args as $a) {
    if (preg_match('/^--size=(\d+)$/', $a, $m)) {
        $size = max(1, (int) $m[1]);
    } elseif (preg_match('/^--start=(\d+)$/', $a, $m)) {
        $start = max(1, (int) $m[1]);
    } else {
        $file = $a;
    }
}
if ($file === null || !is_readable($file)) {
    fwrite(STDERR, "usage: php deploy/import-vocab-csv.php <master.csv> [--size=15] [--start=1]\n");
    exit(2);
}

const FIELDS = ['item', 'english', 'word_class', 'tier', 'aqa_units', 'tracker_topic'];
const THEMES = [
    'T01' => 'Identity', 'T02' => 'Healthy living', 'T03' => 'Education & work', 'T04' => 'Free time',
    'T05' => 'Customs & festivals', 'T06' => 'Celebrity culture', 'T07' => 'Travel & tourism',
    'T08' => 'Media & technology', 'T09' => 'Environment', 'T10' => 'Core vocabulary',
    'T11' => 'Multi-word phrases', 'T12' => 'Places & culture',
];
/** Word classes in the order a theme's sets run; anything else lands in Other. */
const CLASSES = [
    'adj' => 'Adjectives', 'noun' => 'Nouns', 'verb' => 'Verbs', 'adv' => 'Adverbs',
    'mwp' => 'Phrases', 'geo' => 'Places & names', 'prep' => 'Prepositions', 'conj' => 'Connectives',
    'pron' => 'Pronouns', 'num' => 'Numbers', 'other' => 'Other',
];

/** @return array<int,array<string,string>> */
function read_rows(string $file): array
{
    $text  = (string) file_get_contents($file);
    $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
    $first = strtolower(trim($lines[0] ?? ''));

    // A real CSV, with the header naming the columns.
    if (str_contains($first, ',') && str_contains($first, 'item')) {
        $fh   = fopen($file, 'r');
        $head = array_map(static fn($h) => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (count(array_filter($r, static fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($head as $i => $h) {
                $row[$h] = trim((string) ($r[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    // Six fields, one per line, with or without the header lines first.
    $lines = array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
    if (count($lines) >= 6 && strtolower($lines[0]) === 'item' && strtolower($lines[5]) === 'tracker_topic') {
        $lines = array_slice($lines, 6);
    }
    $rows = [];
    foreach (array_chunk($lines, 6) as $chunk) {
        if (count($chunk) < 6) {
            fwrite(STDERR, 'warning: a trailing partial record was dropped: ' . implode(' / ', $chunk) . "\n");
            break;
        }
        $rows[] = array_combine(FIELDS, $chunk);
    }
    return $rows;
}

function class_key(string $wordClass): string
{
    $wc = strtolower(trim($wordClass));
    foreach (array_keys(CLASSES) as $k) {
        if ($wc === $k || str_starts_with($wc, $k . '-') || str_starts_with($wc, $k . '/')) {
            return $k;
        }
    }
    return 'other';
}

$rows = read_rows($file);
if (!$rows) {
    fwrite(STDERR, "no rows read from $file\n");
    exit(1);
}

// Group: topic → tier (F before H) → class, keeping the list's own order inside a group.
$groups = [];
foreach ($rows as $r) {
    $topic = strtoupper(trim((string) ($r['tracker_topic'] ?? '')));
    $word  = trim((string) ($r['item'] ?? ''));
    $eng   = trim((string) ($r['english'] ?? ''));
    if ($word === '' || $eng === '') {
        continue;
    }
    $tier = strtoupper(substr(trim((string) ($r['tier'] ?? 'F')), 0, 1)) === 'H' ? 'H' : 'F';
    $cls  = class_key((string) ($r['word_class'] ?? ''));
    $groups[$topic][$tier][$cls][] = [
        'word' => $word, 'english' => $eng, 'tier' => $tier,
        'word_class' => trim((string) ($r['word_class'] ?? '')),
    ];
}
ksort($groups);

$sets   = [];
$number = $start;
foreach ($groups as $topic => $tiers) {
    $theme = THEMES[$topic] ?? ($topic === '' ? 'Unsorted' : $topic);
    $n     = 1;
    foreach (['F', 'H'] as $tier) {
        foreach (array_keys(CLASSES) as $cls) {
            $words = $tiers[$tier][$cls] ?? [];
            if (!$words) {
                continue;
            }
            foreach (array_chunk($words, $size) as $chunk) {
                $sets[] = [
                    'number'     => $number++,
                    'name'       => $theme . ' ' . $n++ . ': ' . CLASSES[$cls] . ($tier === 'H' ? ' (Higher)' : ''),
                    'topic_refs' => $topic !== '' ? [$topic] : [],
                    'words'      => array_values($chunk),
                    'note'       => 'AQA 8692 vocabulary list, ' . ($tier === 'H' ? 'Higher' : 'Foundation') . ' tier.',
                ];
            }
        }
    }
}

echo json_encode($sets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
fwrite(STDERR, count($rows) . ' rows → ' . count($sets) . ' sets, numbered ' . $start . '–' . ($number - 1) . "\n");
