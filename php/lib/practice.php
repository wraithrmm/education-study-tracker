<?php
/**
 * Practice telemetry: the storage-neutral half of the scoreboard.
 *
 * A practice run is one bounded stretch of practice — one Shooting Gallery
 * game, one maths tutoring session. Both are the same shape of thing, so they
 * share one storage model and one scoreboard engine, with the panels a subject
 * shows driven by configuration rather than by code: panel *types* are code,
 * panel *instances* are data.
 *
 * The constraint that outranks the generalisation: the Spanish scoreboard must
 * look and behave exactly as it does in the app. SPANISH_SCOREBOARD and the
 * pinned chart geometry below are a fixture, not a starting point, and the
 * golden snapshot in tests/golden fails the build on any drift. If a generic
 * change cannot reproduce the Spanish chart exactly, the generic change is
 * what bends.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/**
 * Sources are declared data, not a hardcoded enum, so a new activity needs no
 * schema change. metrics_schema is advisory: an unknown key is stored and
 * flagged rather than rejected, so a client that sends a new metric loses the
 * metric and not the run.
 */
const PRACTICE_SOURCE_SEED = [
    ['key' => 'spanish_gallery',    'display_name' => 'Shooting Gallery',  'subject_slug' => 'spanish',
     'metrics_schema' => ['top_speed' => 'number']],
    ['key' => 'spanish_flashcards', 'display_name' => 'Flashcards',        'subject_slug' => 'spanish',
     'metrics_schema' => []],
    ['key' => 'spanish_chat',       'display_name' => 'AI Conversation',   'subject_slug' => 'spanish',
     'metrics_schema' => []],
    ['key' => 'maths_session',      'display_name' => 'Tutoring session',  'subject_slug' => 'maths',
     'metrics_schema' => ['hints_used' => 'number', 'topics_covered' => 'number']],
];

/**
 * Reproduces the current Spanish app view exactly. `metric: "correct"` on the
 * line chart is deliberate: the app's chart plots the raw score, and `correct`
 * IS the score, so the generic model reproduces it without translation.
 */
const SPANISH_SCOREBOARD = [
    'version' => 1,
    'panels'  => [
        ['type' => 'stat', 'title' => 'Total games', 'metric' => 'count',    'window' => 'all'],
        ['type' => 'stat', 'title' => 'Best score',  'metric' => 'correct',  'window' => 'all', 'agg' => 'max'],
        ['type' => 'stat', 'title' => 'Accuracy',    'metric' => 'accuracy', 'window' => 'last10', 'format' => 'percent1'],
        ['type' => 'stat', 'title' => 'Top speed',   'metric' => 'metrics.top_speed', 'window' => 'all',
         'agg' => 'max', 'format' => 'speed'],
        ['type' => 'line', 'title' => 'Score trend', 'metric' => 'correct', 'limit' => 20,
         'source' => 'spanish_gallery', 'label_points' => true],
        ['type' => 'table', 'title' => 'Recent games', 'limit' => 20,
         'source' => 'spanish_gallery',
         'columns' => ['date', 'label', 'correct', 'incorrect', 'accuracy', 'metrics.top_speed']],
        ['type' => 'topics', 'title' => 'Practice by topic', 'sort' => 'accuracy_asc'],
    ],
];

/**
 * The split panel is what answers "how many needed retries" at a glance, and
 * the topics panel sorted weakest-first is what turns a session into teaching
 * information.
 */
const MATHS_SCOREBOARD = [
    'version' => 1,
    'panels'  => [
        ['type' => 'stat', 'title' => 'Questions this week', 'metric' => 'attempted', 'window' => '7d', 'agg' => 'sum'],
        ['type' => 'stat', 'title' => 'Right first time',    'metric' => 'accuracy',   'window' => 'last10', 'format' => 'percent0'],
        ['type' => 'stat', 'title' => 'Got there in the end', 'metric' => 'solve_rate', 'window' => 'last10', 'format' => 'percent0'],
        ['type' => 'split', 'title' => 'How questions went', 'limit' => 12],
        ['type' => 'line',  'title' => 'Right first time',   'metric' => 'accuracy', 'limit' => 20,
         'y_axis' => 'percent', 'label_points' => false],
        ['type' => 'topics', 'title' => 'Topics practised', 'sort' => 'accuracy_asc', 'limit' => 15],
        ['type' => 'table', 'title' => 'Recent sessions', 'limit' => 20,
         'columns' => ['date', 'label', 'attempted', 'correct', 'correct_after_retry', 'solve_rate']],
    ],
];

/** What a subject with no stored configuration gets. */
const PRACTICE_FALLBACK_SCOREBOARD = [
    'version' => 1,
    'panels'  => [
        ['type' => 'stat', 'title' => 'Runs',      'metric' => 'count',     'window' => 'all'],
        ['type' => 'stat', 'title' => 'Attempted', 'metric' => 'attempted', 'window' => 'all', 'agg' => 'sum'],
        ['type' => 'stat', 'title' => 'Accuracy',  'metric' => 'accuracy',  'window' => 'last10', 'format' => 'percent1'],
        ['type' => 'line',  'title' => 'Accuracy trend', 'metric' => 'accuracy', 'limit' => 20, 'y_axis' => 'percent'],
        ['type' => 'table', 'title' => 'Recent practice', 'limit' => 20,
         'columns' => ['date', 'label', 'attempted', 'correct', 'accuracy']],
        ['type' => 'topics', 'title' => 'By topic', 'sort' => 'accuracy_asc'],
    ],
];

/** @return array<string,array> slug to configuration, seeded by migration. */
function practice_seeded_scoreboards(): array
{
    return ['spanish' => SPANISH_SCOREBOARD, 'maths' => MATHS_SCOREBOARD];
}

const PRACTICE_PANEL_TYPES = ['stat', 'line', 'table', 'topics', 'split'];
const PRACTICE_BASE_METRICS = ['count', 'correct', 'attempted', 'incorrect',
                               'correct_after_retry', 'accuracy', 'solve_rate', 'duration_seconds'];
const PRACTICE_RATIO_METRICS = ['accuracy', 'solve_rate'];
const PRACTICE_AGGS    = ['max', 'min', 'sum', 'mean', 'pooled', 'last'];
const PRACTICE_FORMATS = ['integer', 'decimal1', 'percent0', 'percent1', 'speed', 'duration'];
const PRACTICE_SORTS   = ['accuracy_asc', 'accuracy_desc', 'solve_rate_asc', 'solve_rate_desc',
                          'items_desc', 'runs_desc', 'ref_asc'];
const PRACTICE_COLUMNS = ['date', 'label', 'source', 'attempted', 'correct', 'correct_after_retry',
                          'incorrect', 'accuracy', 'solve_rate', 'duration_seconds'];
const PRACTICE_OUTCOMES = ['correct', 'retry', 'incorrect'];
/** Columns a topics panel can show, beyond the ref and name that identify the row. */
const PRACTICE_TOPIC_METRICS = ['runs', 'items', 'correct', 'retry', 'incorrect',
                                'accuracy', 'solve_rate', 'status'];
const PRACTICE_TOPIC_METRICS_DEFAULT = ['runs', 'items', 'accuracy', 'solve_rate', 'status'];

/**
 * The options each panel type understands. Anything else in a panel is a typo
 * or a setting from a newer version, and either way it would be silently
 * ignored at render time — so tracker_set_scoreboard refuses it instead. Every
 * key here is one this file actually reads.
 */
const PRACTICE_PANEL_KEYS = [
    'stat'   => ['metric', 'window', 'agg', 'format', 'label'],
    'line'   => ['metric', 'limit', 'y_axis', 'label_points', 'format'],
    'table'  => ['columns', 'limit'],
    'topics' => ['sort', 'limit', 'metrics'],
    'split'  => ['limit', 'group_by'],
];
/** Accepted on every panel, whatever its type. */
const PRACTICE_COMMON_KEYS = ['type', 'title', 'source'];

/** Colours for the split bar, kept in step with the dashboard's status palette. */
const PRACTICE_OUTCOME_COLOUR = [
    'correct'   => '#10b981',
    'retry'     => '#fbbf24',
    'incorrect' => '#ef4444',
];

// ---- configuration ------------------------------------------------------

function practice_scoreboard_for(Store $store, string $slug): array
{
    $stored = $store->getScoreboard($slug);
    if ($stored !== null) {
        return $stored;
    }
    return practice_seeded_scoreboards()[$slug] ?? PRACTICE_FALLBACK_SCOREBOARD;
}

function practice_metric_is_valid(string $metric): bool
{
    if (in_array($metric, PRACTICE_BASE_METRICS, true)) {
        return true;
    }
    return (bool) preg_match('/^metrics\.[A-Za-z0-9_]{1,40}$/', $metric);
}

function practice_window_is_valid(string $window): bool
{
    return $window === 'all'
        || (bool) preg_match('/^last\d{1,3}$/', $window)
        || (bool) preg_match('/^\d{1,4}d$/', $window);
}

/**
 * Every problem with a configuration, as human-readable strings.
 *
 * tracker_set_scoreboard rejects the WHOLE config if this returns anything, so
 * a bad edit cannot half-apply and leave a broken board.
 *
 * @return array<int,string>
 */
function practice_validate_scoreboard(array $config, array $sourceKeys = []): array
{
    $errors = [];
    if (!isset($config['version']) || !is_int($config['version']) || $config['version'] < 1) {
        $errors[] = 'version must be a positive integer (currently 1).';
    }
    if (!isset($config['panels']) || !is_array($config['panels']) || !array_is_list($config['panels'])) {
        $errors[] = 'panels must be an array of panel objects.';
        return $errors;
    }
    if (count($config['panels']) > 20) {
        $errors[] = 'panels may hold at most 20 entries.';
    }

    foreach ($config['panels'] as $i => $panel) {
        $at = 'panel ' . ($i + 1);
        if (!is_array($panel) || array_is_list($panel)) {
            $errors[] = "$at must be an object.";
            continue;
        }
        $type = $panel['type'] ?? null;
        if (!is_string($type) || !in_array($type, PRACTICE_PANEL_TYPES, true)) {
            $errors[] = "$at has type " . json_encode($panel['type'] ?? null)
                . '; must be one of ' . implode(', ', PRACTICE_PANEL_TYPES) . '.';
            continue;
        }
        $at .= " ($type)";

        // A key this renderer does not read would be accepted and then quietly
        // do nothing, which is how a board ends up not matching the config
        // someone believes they wrote.
        $allowed = array_merge(PRACTICE_COMMON_KEYS, PRACTICE_PANEL_KEYS[$type]);
        foreach (array_keys($panel) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = "$at does not understand \"$key\"; a $type panel takes "
                    . implode(', ', $allowed) . '.';
            }
        }

        if (isset($panel['title']) && !is_string($panel['title'])) {
            $errors[] = "$at title must be a string.";
        }
        if (isset($panel['source'])) {
            if (!is_string($panel['source'])) {
                $errors[] = "$at source must be a string.";
            } elseif ($sourceKeys && !in_array($panel['source'], $sourceKeys, true)) {
                $errors[] = "$at source \"{$panel['source']}\" is not a registered source ("
                    . implode(', ', $sourceKeys) . '); the panel would always be empty.';
            }
        }
        foreach (['limit'] as $k) {
            if (isset($panel[$k]) && (!is_int($panel[$k]) || $panel[$k] < 1 || $panel[$k] > 200)) {
                $errors[] = "$at $k must be an integer between 1 and 200.";
            }
        }
        if (isset($panel['window']) && (!is_string($panel['window']) || !practice_window_is_valid($panel['window']))) {
            $errors[] = "$at window must be \"all\", \"lastN\" or \"Nd\" (e.g. last10, 7d).";
        }
        if (isset($panel['format']) && !in_array($panel['format'], PRACTICE_FORMATS, true)) {
            $errors[] = "$at format must be one of " . implode(', ', PRACTICE_FORMATS) . '.';
        }
        if (isset($panel['agg']) && !in_array($panel['agg'], PRACTICE_AGGS, true)) {
            $errors[] = "$at agg must be one of " . implode(', ', PRACTICE_AGGS) . '.';
        }

        if ($type === 'stat' || $type === 'line') {
            $metric = $panel['metric'] ?? null;
            if (!is_string($metric) || !practice_metric_is_valid($metric)) {
                $errors[] = "$at needs a metric: one of " . implode(', ', PRACTICE_BASE_METRICS)
                    . ', or metrics.<key>.';
            } elseif ($type === 'line' && $metric === 'count') {
                $errors[] = "$at cannot plot count: every run counts once, so the line would be flat.";
            }
        }
        if ($type === 'line' && isset($panel['y_axis']) && !in_array($panel['y_axis'], ['auto', 'percent'], true)) {
            $errors[] = "$at y_axis must be \"auto\" or \"percent\".";
        }
        if ($type === 'line' && isset($panel['label_points']) && !is_bool($panel['label_points'])) {
            $errors[] = "$at label_points must be true or false.";
        }
        if ($type === 'table') {
            $columns = $panel['columns'] ?? null;
            if (!is_array($columns) || !$columns || !array_is_list($columns)) {
                $errors[] = "$at needs a non-empty columns array.";
            } else {
                foreach ($columns as $c) {
                    if (!is_string($c) || !(in_array($c, PRACTICE_COLUMNS, true)
                        || preg_match('/^metrics\.[A-Za-z0-9_]{1,40}$/', $c))) {
                        $errors[] = "$at column " . json_encode($c) . ' is not one of '
                            . implode(', ', PRACTICE_COLUMNS) . ', or metrics.<key>.';
                    }
                }
            }
        }
        if ($type === 'topics' && isset($panel['sort']) && !in_array($panel['sort'], PRACTICE_SORTS, true)) {
            $errors[] = "$at sort must be one of " . implode(', ', PRACTICE_SORTS) . '.';
        }
        if ($type === 'topics' && isset($panel['metrics'])) {
            if (!is_array($panel['metrics']) || !$panel['metrics'] || !array_is_list($panel['metrics'])) {
                $errors[] = "$at metrics must be a non-empty array of column names.";
            } else {
                foreach ($panel['metrics'] as $c) {
                    if (!is_string($c) || !in_array($c, PRACTICE_TOPIC_METRICS, true)) {
                        $errors[] = "$at metrics entry " . json_encode($c) . ' is not one of '
                            . implode(', ', PRACTICE_TOPIC_METRICS) . '.';
                    }
                }
            }
        }
        if ($type === 'stat' && isset($panel['label']) && !is_string($panel['label'])) {
            $errors[] = "$at label must be a string.";
        }
        if ($type === 'split' && isset($panel['group_by'])
            && !in_array($panel['group_by'], ['run', 'source', 'topic'], true)) {
            $errors[] = "$at group_by must be \"run\", \"source\" or \"topic\".";
        }
    }
    return $errors;
}

// ---- figures ------------------------------------------------------------

/** One run's value for a metric, or null where the run cannot supply it. */
function practice_run_metric(array $run, string $metric): ?float
{
    $attempted = (float) $run['attempted'];
    switch ($metric) {
        case 'count':
            return 1.0;
        case 'attempted':
        case 'correct':
        case 'incorrect':
        case 'correct_after_retry':
            return (float) $run[$metric];
        case 'duration_seconds':
            return $run['duration_seconds'] === null ? null : (float) $run['duration_seconds'];
        // A run with nothing attempted has no accuracy — null, not zero, and
        // nothing divides by it.
        case 'accuracy':
            return $attempted > 0 ? (float) $run['correct'] / $attempted : null;
        case 'solve_rate':
            return $attempted > 0
                ? ((float) $run['correct'] + (float) $run['correct_after_retry']) / $attempted
                : null;
    }
    if (str_starts_with($metric, 'metrics.')) {
        $key = substr($metric, 8);
        $v   = $run['metrics'][$key] ?? null;
        return is_numeric($v) ? (float) $v : null;
    }
    return null;
}

/**
 * Runs are newest first; a window narrows them without reordering.
 */
function practice_window_runs(array $runs, ?string $window): array
{
    $window = $window ?: 'all';
    if ($window === 'all') {
        return $runs;
    }
    if (preg_match('/^last(\d{1,3})$/', $window, $m)) {
        return array_slice($runs, 0, max(1, (int) $m[1]));
    }
    if (preg_match('/^(\d{1,4})d$/', $window, $m)) {
        $since = gmdate('Y-m-d', time() - ((int) $m[1]) * 86400);
        return array_values(array_filter(
            $runs,
            static fn($r) => substr((string) $r['played_at'], 0, 10) >= $since
        ));
    }
    return $runs;
}

/** The default aggregate for a metric when a panel does not name one. */
function practice_default_agg(string $metric): string
{
    if (in_array($metric, PRACTICE_RATIO_METRICS, true)) {
        return 'pooled';
    }
    if (str_starts_with($metric, 'metrics.')) {
        return 'max';
    }
    return 'sum';
}

/**
 * One number for a stat tile.
 *
 * Pooled is the default for a ratio and it matters: over four Spanish games of
 * 69, 30, 69 and 17 words the pooled accuracy is 142/185 = 76.8%, while the
 * mean of the four percentages is 77.2%. Pooled is the honest one, so it is
 * what the board shows unless a panel explicitly asks for the mean.
 */
function practice_stat_value(array $runs, string $metric, ?string $agg): ?float
{
    if ($metric === 'count') {
        return (float) count($runs);
    }
    $agg = $agg ?: practice_default_agg($metric);

    if ($agg === 'pooled') {
        $attempted = 0.0;
        $top       = 0.0;
        foreach ($runs as $r) {
            $attempted += (float) $r['attempted'];
            $top += $metric === 'solve_rate'
                ? (float) $r['correct'] + (float) $r['correct_after_retry']
                : (float) $r['correct'];
        }
        return $attempted > 0 ? $top / $attempted : null;
    }

    $values = [];
    foreach ($runs as $r) {
        $v = practice_run_metric($r, $metric);
        if ($v !== null) {
            $values[] = $v;
        }
    }
    if (!$values) {
        return null;
    }
    return match ($agg) {
        'max'  => max($values),
        'min'  => min($values),
        'mean' => array_sum($values) / count($values),
        'last' => $values[0],
        default => array_sum($values),
    };
}

/** The date a run belongs to on the board. Everything here is UTC. */
function practice_run_date(array $run): string
{
    return substr((string) $run['played_at'], 0, 10);
}

function practice_format(?float $value, string $format): string
{
    if ($value === null) {
        return '—';
    }
    switch ($format) {
        case 'percent0':
            return number_format($value * 100, 0) . '%';
        case 'percent1':
            return number_format($value * 100, 1) . '%';
        case 'speed':
        case 'decimal1':
            return number_format($value, 1);
        case 'duration':
            $s = (int) round($value);
            return $s >= 60 ? intdiv($s, 60) . 'm ' . ($s % 60) . 's' : $s . 's';
    }
    return floor($value) === $value
        ? number_format($value, 0)
        : number_format($value, 1);
}

/** The format a metric takes when a panel does not name one. */
function practice_default_format(string $metric): string
{
    if (in_array($metric, PRACTICE_RATIO_METRICS, true)) {
        return 'percent1';
    }
    if ($metric === 'duration_seconds') {
        return 'duration';
    }
    if (str_starts_with($metric, 'metrics.')) {
        return 'decimal1';
    }
    return 'integer';
}

/** "2026-08-18" reads as "18 Aug" under a star. */
function practice_axis_date(string $ymd): string
{
    $t = strtotime($ymd);
    return $t === false ? $ymd : date('j M', $t);
}

/** "metrics.top_speed" reads as "Top speed" in a heading. */
function practice_metric_label(string $metric): string
{
    $key = str_starts_with($metric, 'metrics.') ? substr($metric, 8) : $metric;
    return ucfirst(str_replace('_', ' ', $key));
}

// ---- rendering ----------------------------------------------------------
//
// Server-rendered inline SVG, still no charting dependency: the whole chart
// is one function and a star path. The geometry below is pinned and the
// Spanish golden snapshot fails the build on any drift, so a refactor that
// improves the maths board and shifts the Spanish chart by two pixels is a
// failed change. Re-pinning is a deliberate act with --update, not a side
// effect.

/** Trim a coordinate so the committed snapshot does not churn on float noise. */
function practice_coord(float $v): string
{
    $s = number_format($v, 2, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/**
 * A five-pointed star, first point straight up.
 *
 * @param float $inner the waist, as a fraction of the outer radius
 */
function practice_star_path(float $cx, float $cy, float $r, int $points, float $inner): string
{
    $d    = '';
    $step = M_PI / $points;
    $a    = -M_PI / 2;
    for ($i = 0; $i < $points * 2; $i++) {
        $rr  = $i % 2 ? $r * $inner : $r;
        $d  .= ($i ? 'L' : 'M') . practice_coord($cx + cos($a) * $rr)
             . ' ' . practice_coord($cy + sin($a) * $rr) . ' ';
        $a  += $step;
    }
    return $d . 'Z';
}

/**
 * The score chart, geometry pinned:
 *
 *   viewBox 0 0 600 232, left/right padding 62, baseline y=192, top y=60,
 *   y = 192 - (value / maxValue) * 132, polyline #7c3aed width 3 round joins
 *   over a violet wash, one star per run, value labels 15px bold #4b5563
 *   above each star and the run date 11px #78716c along the bottom at y=215.
 *
 * The star is the point of the design rather than decoration on top of it: it
 * encodes the score twice, as height and as area, so a good run is visibly
 * bigger and not merely higher up. The best run in view is drawn in gold under
 * a dashed record line, which is the one thing on the board that says what to
 * aim at rather than what already happened.
 *
 * Crowding is handled by geometry, never by dropping runs. Once the stars are
 * closer than 34px they shrink to fit, only the record and the latest run keep
 * their value label, and the dates thin to six: a chart nobody can read is
 * worse than one that labels less.
 *
 * Hidden entirely below 2 runs: one point is not a trend.
 *
 * @param array<int,array{value:float,text:string,label:string,axis?:string}> $points oldest first
 */
function practice_line_svg(array $points, string $title, bool $labelPoints, bool $percent): string
{
    $n = count($points);
    if ($n < 2) {
        return '';
    }
    $values = array_map(static fn($p) => (float) $p['value'], $points);
    // For a percentage axis the scale is fixed 0-100 rather than to the data
    // max, so a run of 70-80% does not look like wild swings.
    $maxValue = $percent ? 1.0 : max($values);
    if ($maxValue <= 0) {
        $maxValue = 1.0;
    }

    $coords = [];
    foreach ($points as $p) {
        $coords[] = min((float) $p['value'], $maxValue) / $maxValue;
    }
    $spacing = 476 / ($n - 1);
    $xs      = [];
    $ys      = [];
    foreach ($coords as $i => $frac) {
        $xs[] = 62 + $i * $spacing;
        $ys[] = 192 - $frac * 132;
    }

    // Stars sized to the gaps between them, so twenty runs are small and four
    // are generous, and neither overlaps its neighbour.
    $rmax  = max(5.0, min(16.0, $spacing * 0.42));
    $rmin  = max(4.0, $rmax * 0.42);
    $dense = $spacing < 34;

    // Every run at the maximum is a record; a board where they all match has
    // no record at all, and the gold would be meaningless.
    $best    = max($values);
    $hasBest = $best > min($values);
    $bestY   = 192 - (min($best, $maxValue) / $maxValue) * 132;
    $bestAt  = $hasBest ? array_keys($values, $best, true) : [];

    // Gradient ids have to be unique on a page that draws two charts, and
    // stable across renders or the snapshot churns: derive them from the
    // title rather than from a counter.
    $id = 'c' . substr(md5($title), 0, 8);

    $svg = '<svg class="chart" viewBox="0 0 600 232" width="100%" style="max-height:232px" role="img">'
        . '<title>' . h($title) . '</title>'
        . '<defs><linearGradient id="' . $id . 'w" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="#7c3aed" stop-opacity=".22"/>'
        . '<stop offset="1" stop-color="#7c3aed" stop-opacity="0"/></linearGradient>'
        . '<linearGradient id="' . $id . 'g" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="#ffd257"/><stop offset="1" stop-color="#f5a623"/>'
        . '</linearGradient></defs>';

    $line = [];
    foreach ($xs as $i => $x) {
        $line[] = practice_coord($x) . ',' . practice_coord($ys[$i]);
    }
    $area = [];
    foreach ($xs as $i => $x) {
        $area[] = practice_coord($x) . ' ' . practice_coord($ys[$i]);
    }
    $svg .= '<path d="M' . practice_coord($xs[0]) . ' 192 L' . implode(' L', $area)
        . ' L' . practice_coord($xs[$n - 1]) . ' 192 Z" fill="url(#' . $id . 'w)"/>';

    if ($hasBest) {
        $svg .= '<line class="record" x1="34" y1="' . practice_coord($bestY)
            . '" x2="566" y2="' . practice_coord($bestY)
            . '" stroke="#f5a623" stroke-width="1.5" stroke-dasharray="5 5"/>'
            . '<text x="566" y="' . practice_coord($bestY - 8) . '" text-anchor="end" font-size="12"'
            . ' font-weight="700" letter-spacing="1" fill="#b45309">'
            . h($points[$bestAt[count($bestAt) - 1]]['text']) . ' TO BEAT</text>';
    }

    $svg .= '<polyline points="' . implode(' ', $line) . '" fill="none" stroke="#7c3aed" stroke-width="3"'
        . ' stroke-linejoin="round" stroke-linecap="round"/>';

    foreach ($xs as $i => $x) {
        $y      = $ys[$i];
        $isBest = in_array($i, $bestAt, true);
        $r      = $rmin + $coords[$i] * ($rmax - $rmin);
        if ($isBest) {
            $svg .= '<path d="' . practice_star_path($x, $y, $r + 7, 5, 0.38)
                . '" fill="#fde68a" opacity=".55"/>';
        }
        $svg .= '<path class="star' . ($isBest ? ' best' : '') . '" d="'
            . practice_star_path($x, $y, $r, 5, 0.45) . '" fill="'
            . ($isBest ? 'url(#' . $id . 'g)' : '#7c3aed')
            . '" stroke="#1c1917" stroke-width="1" stroke-linejoin="round"/>';
        if ($labelPoints && (!$dense || $isBest || $i === $n - 1)) {
            $svg .= '<text x="' . practice_coord($x) . '" y="' . practice_coord($y - $r - 9)
                . '" text-anchor="middle" font-size="15" font-weight="700" fill="#4b5563">'
                . h($points[$i]['text']) . '</text>';
        }
    }

    // Dates along the bottom, thinned to at most six, always including the
    // latest run: the right-hand end is the one everybody looks at.
    $step = (int) max(1, (int) ceil($n / 6));
    foreach ($xs as $i => $x) {
        $axis = (string) ($points[$i]['axis'] ?? '');
        if ($axis === '' || ($i % $step !== 0 && $i !== $n - 1)) {
            continue;
        }
        $svg .= '<text x="' . practice_coord($x) . '" y="215" text-anchor="middle" font-size="11"'
            . ' fill="#78716c">' . h($axis) . '</text>';
    }

    // One margin note, in the page's own italic rather than a webfont, and
    // only when there is room for it.
    if ($hasBest && !$dense) {
        $bi   = $bestAt[count($bestAt) - 1];
        $side = $xs[$bi] > 300 ? -1 : 1;
        $svg .= '<text x="' . practice_coord($xs[$bi] + $side * 32) . '" y="'
            . practice_coord($ys[$bi] + 26) . '" text-anchor="' . ($side > 0 ? 'start' : 'end')
            . '" font-family="Georgia,serif" font-style="italic" font-size="19" fill="#b45309"'
            . ' stroke="#fff" stroke-width="4" paint-order="stroke" stroke-linejoin="round">'
            . 'best yet!</text>';
    }
    return $svg . '</svg>';
}

/** The pinned panel heading: uppercase, letter-spaced, 12.5px, #374151. */
function practice_heading(string $title): string
{
    return '<p class="panel-title" style="text-transform:uppercase;letter-spacing:.12em;'
        . 'font-size:12.5px;color:#374151;font-weight:700;margin:1.5rem 0 .5rem">' . h($title) . '</p>';
}

/** Chart numbers as a table too. The chart is never the only route to the data. */
function practice_chart_table(array $head, array $rows): string
{
    $out = '<details class="chartdata"><summary><small>Chart data</small></summary>'
        . '<div class="tablewrap"><table><thead><tr>';
    foreach ($head as $cell) {
        $out .= '<th>' . h($cell) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach ($row as $cell) {
            $out .= '<td>' . h($cell) . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div></details>';
}

/** One cell of a runs table. */
function practice_cell(array $run, string $column, array $sourceNames): string
{
    if ($column === 'date') {
        return practice_run_date($run);
    }
    if ($column === 'label') {
        return (string) $run['label'];
    }
    if ($column === 'source') {
        return $sourceNames[$run['source']] ?? (string) $run['source'];
    }
    return practice_format(
        practice_run_metric($run, $column),
        practice_default_format($column)
    );
}

function practice_column_label(string $column): string
{
    return match ($column) {
        'date'                => 'Date',
        'label'               => 'Label',
        'source'              => 'Source',
        'correct_after_retry' => 'After retry',
        'solve_rate'          => 'Solve rate',
        'duration_seconds'    => 'Time',
        default               => practice_metric_label($column),
    };
}

/**
 * Every panel of a board.
 *
 * Consecutive stat panels are grouped into one tile row, which is what makes
 * the Spanish board's four tiles sit side by side as they do in the app.
 * An unknown panel type is skipped with a logged warning rather than breaking
 * the page: a board that half-renders still tells her how she is doing.
 */
function practice_render_panels(
    Store $store,
    array $subject,
    array $config,
    array $runs,
    array $filter = [],
    ?int $panelLimit = null
): string {
    $panels = $config['panels'] ?? [];
    if ($panelLimit !== null) {
        $panels = array_slice($panels, 0, $panelLimit);
    }
    $sourceNames = [];
    foreach ($store->listPracticeSources($subject['slug']) as $s) {
        $sourceNames[$s['key']] = $s['display_name'];
    }

    $out   = '';
    $tiles = [];
    $flush = static function () use (&$tiles, &$out): void {
        if ($tiles) {
            $out .= '<div class="stats">' . implode('', $tiles) . '</div>';
            $tiles = [];
        }
    };

    foreach ($panels as $panel) {
        if (!is_array($panel) || !in_array($panel['type'] ?? '', PRACTICE_PANEL_TYPES, true)) {
            error_log('tracker: skipping unknown scoreboard panel type '
                . json_encode($panel['type'] ?? null) . ' for ' . $subject['slug']);
            continue;
        }
        // A panel's own source narrows the board's filter; when the two name
        // different sources nothing can match, and the panel is empty rather
        // than wrong.
        $panelRuns = $runs;
        if (!empty($panel['source'])) {
            $panelRuns = array_values(array_filter(
                $runs,
                static fn($r) => $r['source'] === $panel['source']
            ));
        }

        if ($panel['type'] === 'stat') {
            $tiles[] = practice_render_stat($panel, $panelRuns);
            continue;
        }
        $flush();
        $out .= match ($panel['type']) {
            'line'   => practice_render_line($panel, $panelRuns),
            'table'  => practice_render_table($panel, $panelRuns, $sourceNames),
            'split'  => practice_render_split($store, $subject, $panel, $panelRuns, $filter, $sourceNames),
            'topics' => practice_render_topics($store, $subject, $panel, $filter),
            default  => '',
        };
    }
    $flush();
    return $out;
}

function practice_render_stat(array $panel, array $runs): string
{
    $metric = (string) ($panel['metric'] ?? 'count');
    $window = isset($panel['window']) ? (string) $panel['window'] : 'all';
    $value  = practice_stat_value(practice_window_runs($runs, $window), $metric, $panel['agg'] ?? null);
    $format = (string) ($panel['format'] ?? practice_default_format($metric));

    $note = match (true) {
        $window === 'all'                      => 'all time',
        (bool) preg_match('/^last(\d+)$/', $window, $m) => 'last ' . $m[1] . ' runs',
        (bool) preg_match('/^(\d+)d$/', $window, $m)    => 'last ' . $m[1] . ' days',
        default                                => $window,
    };
    if (in_array($metric, PRACTICE_RATIO_METRICS, true)
        && ($panel['agg'] ?? 'pooled') === 'pooled') {
        $note .= ', pooled';
    }
    // An explicit label replaces the caption the window would have generated.
    if (isset($panel['label']) && is_string($panel['label'])) {
        $note = $panel['label'];
    }

    return '<div class="card"><p class="label">' . h((string) ($panel['title'] ?? practice_metric_label($metric)))
        . '</p><p class="big mono">' . h(practice_format($value, $format)) . '</p>'
        . '<p><small>' . h($note) . '</small></p></div>';
}

function practice_render_line(array $panel, array $runs): string
{
    $metric  = (string) ($panel['metric'] ?? 'correct');
    $limit   = (int) ($panel['limit'] ?? 20);
    $percent = ($panel['y_axis'] ?? 'auto') === 'percent';
    $format  = (string) ($panel['format'] ?? practice_default_format($metric));
    $title   = (string) ($panel['title'] ?? practice_metric_label($metric));

    // Newest first out of the store, so take the window then put it back into
    // reading order: oldest on the left.
    $window = array_reverse(array_slice($runs, 0, max(1, $limit)));
    $points = [];
    foreach ($window as $run) {
        $v = practice_run_metric($run, $metric);
        if ($v === null) {
            continue;
        }
        $points[] = [
            'value' => $v,
            'text'  => practice_format($v, $format),
            'label' => practice_run_date($run) . ' ' . $run['label'],
            'axis'  => practice_axis_date(practice_run_date($run)),
        ];
    }
    if (count($points) < 2) {
        return '';
    }

    $svg = practice_line_svg(
        $points,
        $title . ' — ' . practice_metric_label($metric) . ' across the last ' . count($points) . ' runs',
        (bool) ($panel['label_points'] ?? false),
        $percent
    );
    $rows = array_map(static fn($p) => [$p['label'], $p['text']], $points);
    return practice_heading($title) . '<div class="chartbox">' . $svg . '</div>'
        . practice_chart_table(['Run', practice_metric_label($metric)], $rows);
}

function practice_render_table(array $panel, array $runs, array $sourceNames): string
{
    $columns = $panel['columns'] ?? ['date', 'label', 'attempted', 'correct', 'accuracy'];
    $limit   = (int) ($panel['limit'] ?? 20);
    $rows    = array_slice($runs, 0, max(1, $limit));
    $title   = (string) ($panel['title'] ?? 'Recent practice');

    $out = practice_heading($title);
    if (!$rows) {
        return $out . '<p><small>Nothing logged yet.</small></p>';
    }
    $out .= '<div class="tablewrap"><table><thead><tr>';
    foreach ($columns as $c) {
        $out .= '<th>' . h(practice_column_label((string) $c)) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $run) {
        $out .= '<tr>';
        foreach ($columns as $i => $c) {
            $cell = practice_cell($run, (string) $c, $sourceNames);
            $out .= '<td' . ($i === 0 ? ' class="mono"' : '') . '>' . h($cell)
                . ($i === 0 && $run['void_reason'] ? ' <b style="color:#b91c1c">VOID</b>' : '')
                . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div>';
}

/**
 * The stacked bar that answers "how many needed retries" at a glance.
 * HTML rather than SVG, matching the strand bars the dashboard already draws.
 */
function practice_render_split(
    Store $store,
    array $subject,
    array $panel,
    array $runs,
    array $filter,
    array $sourceNames
): string {
    $limit   = (int) ($panel['limit'] ?? 12);
    $groupBy = (string) ($panel['group_by'] ?? 'run');
    $title   = (string) ($panel['title'] ?? 'How it went');

    $groups = [];
    if ($groupBy === 'topic') {
        $roll = $store->practiceTopicRollup($subject['slug'], practice_panel_filter($filter, $panel));
        foreach ($roll as $ref => $r) {
            $groups[] = ['label' => $ref, 'correct' => $r['correct'],
                         'retry' => $r['retry'], 'incorrect' => $r['incorrect']];
        }
    } elseif ($groupBy === 'source') {
        $acc = [];
        foreach ($runs as $run) {
            $key = (string) $run['source'];
            $acc[$key] ??= ['label' => $sourceNames[$key] ?? $key,
                            'correct' => 0.0, 'retry' => 0.0, 'incorrect' => 0.0];
            $acc[$key]['correct']   += (float) $run['correct'];
            $acc[$key]['retry']     += (float) $run['correct_after_retry'];
            $acc[$key]['incorrect'] += (float) $run['incorrect'];
        }
        ksort($acc);
        $groups = array_values($acc);
    } else {
        foreach (array_slice($runs, 0, max(1, $limit)) as $run) {
            $groups[] = [
                'label'     => practice_run_date($run) . ' · ' . $run['label'],
                'correct'   => (float) $run['correct'],
                'retry'     => (float) $run['correct_after_retry'],
                'incorrect' => (float) $run['incorrect'],
            ];
        }
    }
    $groups = array_slice($groups, 0, max(1, $limit));

    $out = practice_heading($title);
    if (!$groups) {
        return $out . '<p><small>Nothing logged yet.</small></p>';
    }
    $rows = [];
    foreach ($groups as $g) {
        $total = $g['correct'] + $g['retry'] + $g['incorrect'];
        $segs  = '';
        if ($total > 0) {
            foreach (PRACTICE_OUTCOMES as $outcome) {
                $key = $outcome === 'retry' ? 'retry' : $outcome;
                $n   = (float) $g[$key];
                if ($n <= 0) {
                    continue;
                }
                $segs .= '<span style="width:' . practice_coord($n / $total * 100) . '%;background:'
                    . PRACTICE_OUTCOME_COLOUR[$outcome] . '" title="' . h(practice_format($n, 'integer')
                    . ' ' . $outcome) . '"></span>';
            }
        }
        $out .= '<div class="strand"><span class="nm">' . h($g['label']) . '</span>'
            . '<div class="bar">' . $segs . '</div>'
            . '<span class="count mono">' . h(practice_format($total, 'integer')) . '</span></div>';
        $rows[] = [
            $g['label'],
            practice_format($g['correct'], 'integer'),
            practice_format($g['retry'], 'integer'),
            practice_format($g['incorrect'], 'integer'),
        ];
    }
    $out .= '<div class="legend">';
    foreach (['correct' => 'Right first time', 'retry' => 'Right after a retry', 'incorrect' => 'Not yet'] as $k => $label) {
        $out .= '<span><span class="dot" style="display:inline-block;background:'
            . PRACTICE_OUTCOME_COLOUR[$k] . '"></span> ' . h($label) . '</span>';
    }
    $out .= '</div>';
    return $out . practice_chart_table(['Run', 'First time', 'After retry', 'Not yet'], $rows);
}

/**
 * Per-topic practice, joined to each topic's current status.
 *
 * Practice never moves a status — status moves through sessions only, where
 * the thresholds and the no-downgrade rule live — so the status here is
 * reported, never written.
 */
function practice_render_topics(Store $store, array $subject, array $panel, array $filter): string
{
    $slug  = $subject['slug'];
    $title = (string) ($panel['title'] ?? 'By topic');
    $sort  = (string) ($panel['sort'] ?? 'accuracy_asc');
    $limit = (int) ($panel['limit'] ?? 50);

    $roll = $store->practiceTopicRollup($slug, practice_panel_filter($filter, $panel));
    $out  = practice_heading($title);
    if (!$roll) {
        return $out . '<p><small>No practice carries a topic reference yet.</small></p>';
    }

    $topics = [];
    foreach ($store->listTopics($slug) as $t) {
        $topics[$t['ref']] = $t;
    }

    $rows = [];
    foreach ($roll as $ref => $r) {
        $rows[] = $r + [
            'accuracy'   => $r['attempted'] > 0 ? $r['correct'] / $r['attempted'] : null,
            'solve_rate' => $r['attempted'] > 0 ? ($r['correct'] + $r['retry']) / $r['attempted'] : null,
            'name'       => $topics[$ref]['name'] ?? '(not in the syllabus)',
            'status'     => $topics[$ref]['status'] ?? null,
        ];
    }

    // Ties always break on the topic ref ascending, so the order is
    // deterministic and the snapshot is stable.
    usort($rows, static function ($a, $b) use ($sort) {
        $cmp = match ($sort) {
            'accuracy_desc'   => practice_nullcmp($b['accuracy'], $a['accuracy']),
            'solve_rate_asc'  => practice_nullcmp($a['solve_rate'], $b['solve_rate']),
            'solve_rate_desc' => practice_nullcmp($b['solve_rate'], $a['solve_rate']),
            'items_desc'      => $b['attempted'] <=> $a['attempted'],
            'runs_desc'       => $b['runs'] <=> $a['runs'],
            'ref_asc'         => 0,
            default           => practice_nullcmp($a['accuracy'], $b['accuracy']),
        };
        return $cmp !== 0 ? $cmp : strcmp((string) $a['ref'], (string) $b['ref']);
    });
    $rows = array_slice($rows, 0, max(1, $limit));

    // The ref and the name identify the row, so they are always there; the
    // rest is the panel's choice.
    $columns = $panel['metrics'] ?? PRACTICE_TOPIC_METRICS_DEFAULT;
    $columns = array_values(array_filter(
        (array) $columns,
        static fn($c) => in_array($c, PRACTICE_TOPIC_METRICS, true)
    ));

    $out .= '<div class="tablewrap"><table><thead><tr><th>Topic</th><th>Name</th>';
    foreach ($columns as $c) {
        $out .= '<th>' . h(practice_topic_column_label($c)) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr><td class="mono"><a href="/s/' . h($slug) . '/t/' . rawurlencode((string) $r['ref'])
            . '">' . h((string) $r['ref']) . '</a></td>'
            . '<td>' . h((string) $r['name']) . '</td>';
        foreach ($columns as $c) {
            $out .= $c === 'status'
                ? '<td><small>' . h($r['status'] === null ? '—' : (STATUS_LABEL[$r['status']] ?? $r['status']))
                    . '</small></td>'
                : '<td class="mono">' . h(practice_topic_cell($r, $c)) . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div>';
}

/**
 * The store filter for one panel: the panel's own source narrows the board's.
 * When the two name different sources nothing can match, so the filter is made
 * to match nothing rather than quietly widening back to the board's source.
 */
function practice_panel_filter(array $filter, array $panel): array
{
    $panelSource = $panel['source'] ?? null;
    if ($panelSource === null) {
        return $filter;
    }
    $boardSource = $filter['source'] ?? null;
    $filter['source'] = ($boardSource === null || $boardSource === $panelSource)
        ? $panelSource
        : '\0no-such-source';
    return $filter;
}

function practice_topic_column_label(string $column): string
{
    return match ($column) {
        'items'      => 'Items',
        'accuracy'   => 'Right first time',
        'solve_rate' => 'Solve rate',
        'retry'      => 'After retry',
        'incorrect'  => 'Not got',
        default      => ucfirst($column),
    };
}

function practice_topic_cell(array $row, string $column): string
{
    return match ($column) {
        'accuracy', 'solve_rate' => practice_format($row[$column], 'percent1'),
        'items'                  => practice_format((float) $row['attempted'], 'integer'),
        default                  => practice_format((float) ($row[$column] ?? 0), 'integer'),
    };
}

/** Sort helper: a topic with nothing attempted sorts last, not first. */
function practice_nullcmp(?float $a, ?float $b): int
{
    if ($a === null && $b === null) {
        return 0;
    }
    if ($a === null) {
        return 1;
    }
    if ($b === null) {
        return -1;
    }
    return $a <=> $b;
}

// ---- pages --------------------------------------------------------------

/** The board's own filters, read off the query string. */
function practice_filter_from_query(array $q): array
{
    $filter = [];
    foreach (['source' => 'source', 'ref' => 'ref'] as $key => $param) {
        if (!empty($q[$param]) && is_string($q[$param])) {
            $filter[$key] = substr($q[$param], 0, 64);
        }
    }
    foreach (['from' => 'since', 'to' => 'until'] as $param => $key) {
        if (!empty($q[$param]) && is_string($q[$param]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q[$param])) {
            $filter[$key] = $q[$param];
        }
    }
    // "?window=30d" is the spelling the date chips use, so a bookmarked board
    // still means "the last thirty days" next month rather than a fixed date
    // that quietly ages. An explicit range wins: the two are alternatives.
    if (!isset($filter['since']) && !isset($filter['until'])
        && !empty($q['window']) && is_string($q['window'])
        && preg_match('/^([1-9]\d{0,3})d$/', $q['window'], $m)) {
        $filter['window'] = $m[1] . 'd';
        $filter['since']  = gmdate('Y-m-d', time() - ((int) $m[1]) * 86400);
    }
    return $filter;
}

/**
 * A board URL with part of the filter replaced. Passing null for a key drops
 * it, which is how the "all time" chip clears a date range.
 */
function practice_board_url(string $slug, array $filter, array $over = []): string
{
    $windowed = isset($filter['window']);
    $q = [
        'source' => $filter['source'] ?? null,
        'window' => $filter['window'] ?? null,
        // When a window is in force the dates it implies are derived, not the
        // user's, and echoing them back would freeze a rolling window.
        'from'   => $windowed ? null : ($filter['since'] ?? null),
        'to'     => $windowed ? null : ($filter['until'] ?? null),
        'ref'    => $filter['ref'] ?? null,
    ];
    // A window and an explicit range are two spellings of one filter, so
    // setting either clears the other.
    if (array_key_exists('window', $over)) {
        $q['from'] = $q['to'] = null;
    }
    if (array_key_exists('from', $over) || array_key_exists('to', $over)) {
        $q['window'] = null;
    }
    foreach ($over as $k => $v) {
        $q[$k] = $v;
    }
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    return '/s/' . rawurlencode($slug) . '/practice' . ($q ? '?' . http_build_query($q) : '');
}

/** Runs grouped by source, plus '*' for the lot. */
function practice_source_tally(array $runs): array
{
    $out = [];
    foreach ($runs as $r) {
        foreach (['*', (string) $r['source']] as $k) {
            $out[$k] ??= ['runs' => 0, 'attempted' => 0.0, 'correct' => 0.0];
            $out[$k]['runs']++;
            $out[$k]['attempted'] += (float) $r['attempted'];
            $out[$k]['correct']   += (float) $r['correct'];
        }
    }
    return $out;
}

/** Line-art glyphs, drawn rather than pulled in: the page loads no icon font. */
const PRACTICE_ICONS = [
    'all'    => '<circle cx="9" cy="9" r="7.2"/><path d="M9 4.4v9.2M4.4 9h9.2"/>',
    'target' => '<circle cx="9" cy="9" r="7.2"/><circle cx="9" cy="9" r="3.6"/>',
    'cards'  => '<rect x="2.2" y="4.6" width="11" height="8.4" rx="1.6"/>'
              . '<path d="M5.4 3.2h8.2a1.6 1.6 0 0 1 1.6 1.6v7"/>',
    'speech' => '<path d="M2.6 4.4h12.8v7.4H8.4L5 14.6v-2.8H2.6z"/>',
    'book'   => '<path d="M2.6 3.4h5a2 2 0 0 1 2 2v9a1.6 1.6 0 0 0-1.6-1.6H2.6z"/>'
              . '<path d="M15.4 3.4h-5a2 2 0 0 0-2 2v9a1.6 1.6 0 0 1 1.6-1.6h5.4z"/>',
    'dot'    => '<circle cx="9" cy="9" r="6"/>',
];

/** Sources are data, so the glyph is matched on the key rather than enumerated. */
function practice_source_icon(string $key): string
{
    return match (true) {
        str_contains($key, 'gallery')   => 'target',
        str_contains($key, 'flashcard') => 'cards',
        str_contains($key, 'chat')      => 'speech',
        str_contains($key, 'session')   => 'book',
        default                         => 'dot',
    };
}

function practice_icon(string $name): string
{
    return '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor"'
        . ' stroke-width="1.4" stroke-linejoin="round" aria-hidden="true">'
        . (PRACTICE_ICONS[$name] ?? PRACTICE_ICONS['dot']) . '</svg>';
}

/**
 * One activity card. A source with nothing behind it is not a link: filtering
 * to a guaranteed empty page is a dead end, so it renders as an invitation to
 * go and play it instead.
 */
function practice_source_card(
    string $href,
    string $name,
    string $icon,
    ?array $tally,
    bool $everPlayed,
    bool $selected
): string {
    $head = '<span class="tname">' . practice_icon($icon) . h($name) . '</span>';

    if ($tally === null || $tally['runs'] === 0) {
        return '<span class="tile empty">' . $head
            . '<span class="tnum">' . ($everPlayed ? '0' : 'new') . '</span>'
            . '<span class="tsub">' . ($everPlayed
                ? 'nothing in this window'
                : 'not played yet &mdash; give it a go') . '</span></span>';
    }

    $acc = $tally['attempted'] > 0 ? $tally['correct'] / $tally['attempted'] : null;
    return '<a class="tile" href="' . h($href) . '"' . ($selected ? ' aria-current="page"' : '') . '>'
        . $head
        . '<span class="tnum">' . $tally['runs'] . '</span>'
        . '<span class="tsub">' . ($tally['runs'] === 1 ? 'run' : 'runs')
        . ($acc === null ? '' : ' &middot; ' . h(practice_format($acc, 'percent1')) . ' right first time')
        . '</span>'
        . ($acc === null ? '' : '<span class="meter"><i style="width:'
            . practice_coord($acc * 100) . '%"></i></span>')
        . '</a>';
}

/** The date chips, in the order they read: widest first. */
const PRACTICE_RANGES = [
    [null,  'All time'],
    ['30d', 'Last 30 days'],
    ['7d',  'Last 7 days'],
];

/**
 * The activity picker, in place of the old dropdown row.
 *
 * A dropdown makes you commit before it tells you anything. These cards carry
 * the two figures that decide whether the click is worth making &mdash; how many
 * runs are in view, and how many she got right first time &mdash; so choosing an
 * activity confirms something already on the screen. They are ordinary links,
 * so the board still works with JavaScript off, and the date range collapses
 * to three chips with the two date boxes behind a disclosure, because a fixed
 * range is the rarest of the four things anyone wants from this row.
 *
 * The counts deliberately ignore the source the board is already narrowed to.
 * Counting within it would leave every card but the selected one reading zero,
 * which is exactly the information the cards exist to give.
 */
function practice_picker(Store $store, array $subject, array $filter): string
{
    $slug = $subject['slug'];

    $unsourced = $filter;
    unset($unsourced['source']);
    $inView = practice_source_tally($store->listPracticeRuns($slug, $unsourced));
    // "Nothing in this window" and "never played" read differently on a card,
    // so the second tally is needed — but only when there is a window at all.
    $ever = $unsourced ? practice_source_tally($store->listPracticeRuns($slug)) : $inView;

    $cards = [practice_source_card(
        practice_board_url($slug, $filter, ['source' => null]),
        'Everything',
        'all',
        $inView['*'] ?? null,
        isset($ever['*']),
        !isset($filter['source'])
    )];
    foreach ($store->listPracticeSources($slug) as $src) {
        $key     = (string) $src['key'];
        $cards[] = practice_source_card(
            practice_board_url($slug, $filter, ['source' => $key]),
            (string) $src['display_name'],
            practice_source_icon($key),
            $inView[$key] ?? null,
            isset($ever[$key]),
            ($filter['source'] ?? null) === $key
        );
    }

    $window = $filter['window'] ?? null;
    $fixed  = $window === null && (isset($filter['since']) || isset($filter['until']));
    $chips  = '';
    foreach (PRACTICE_RANGES as [$key, $label]) {
        $on = $key === null ? ($window === null && !$fixed) : $window === $key;
        $chips .= '<a class="rangechip" href="'
            . h(practice_board_url($slug, $filter, ['window' => $key]))
            . '"' . ($on ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
    }

    // The dates and the topic box, kept but got out of the way. Open when they
    // are what is actually filtering the board.
    $open  = $fixed || isset($filter['ref']);
    $chips .= '<details class="pickdates"' . ($open ? ' open' : '') . '>'
        . '<summary>Pick dates &amp; topic</summary>'
        . '<form class="filters" method="get" action="/s/' . h($slug) . '/practice">'
        . (isset($filter['source'])
            ? '<input type="hidden" name="source" value="' . h($filter['source']) . '">' : '')
        . '<label><small>From</small><input type="date" name="from" value="'
        . h($window === null ? ($filter['since'] ?? '') : '') . '"></label>'
        . '<label><small>To</small><input type="date" name="to" value="'
        . h($window === null ? ($filter['until'] ?? '') : '') . '"></label>'
        . '<label><small>Topic</small><input type="text" name="ref" size="6" placeholder="G14" value="'
        . h($filter['ref'] ?? '') . '"></label>'
        . '<button type="submit">Filter</button>'
        . '</form></details>';

    if ($filter) {
        $chips .= '<a class="clear" href="/s/' . h($slug) . '/practice"><small>clear</small></a>';
    }

    return '<div class="picker"><p class="plabel">Pick an activity</p>'
        . '<div class="tiles">' . implode('', $cards) . '</div>'
        . '<div class="ranges">' . $chips . '</div></div>';
}

/** The full board at /s/{subject}/practice. */
function render_practice_board(Store $store, array $subject, array $query = []): string
{
    $slug   = $subject['slug'];
    $filter = practice_filter_from_query($query);
    $config = practice_scoreboard_for($store, $slug);
    $runs   = $store->listPracticeRuns($slug, $filter);

    $body = detail_head($subject, 'Practice', 'Games and sessions logged against ' . h($subject['name'])
        . '. Practice never moves a topic status &mdash; that happens through sessions only.');
    $body .= practice_picker($store, $subject, $filter);

    if (!$runs) {
        $body .= '<p><small>' . ($filter
            ? 'No practice matches those filters.'
            : 'No practice logged yet. Runs arrive through tracker_log_practice.') . '</small></p>';
    } else {
        $body .= practice_render_panels($store, $subject, $config, $runs, $filter);
    }

    // Voided runs are excluded from every figure above, but the row is kept
    // and shown: a record that can lose entries is not one.
    $voided = array_values(array_filter(
        $store->listPracticeRuns($slug, $filter + ['include_void' => true]),
        static fn($r) => $r['void_reason'] !== null
    ));
    if ($voided) {
        $body .= practice_heading('Voided runs');
        $body .= '<p><small>Excluded from every figure above. The rows stay.</small></p>';
        $body .= '<div class="tablewrap"><table><thead><tr><th>Date</th><th>Label</th>'
            . '<th>Attempted</th><th>Why it was voided</th></tr></thead><tbody>';
        foreach ($voided as $r) {
            $body .= '<tr><td class="mono">' . h(practice_run_date($r)) . ' <b style="color:#b91c1c">VOID</b></td>'
                . '<td>' . h((string) $r['label']) . '</td>'
                . '<td class="mono">' . (int) $r['attempted'] . '</td>'
                . '<td><small>' . h((string) $r['void_reason']) . '</small></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    return dash_shell('Practice — ' . $subject['name'], $body);
}

/**
 * The first four panels, for the subject dashboard, with a link to the rest.
 * Nothing at all when there is no practice: an empty board on every subject
 * page would be clutter, and the tools say how to start one.
 */
function practice_dashboard_section(Store $store, array $subject): string
{
    $runs = $store->listPracticeRuns($subject['slug']);
    if (!$runs) {
        return '';
    }
    $config = practice_scoreboard_for($store, $subject['slug']);
    return '<h2>Practice <a href="/s/' . h($subject['slug']) . '/practice" style="font-size:.8rem">'
        . 'the whole board →</a></h2>'
        . practice_render_panels($store, $subject, $config, $runs, [], 4);
}
