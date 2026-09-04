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

/** "metrics.top_speed" reads as "Top speed" in a heading. */
function practice_metric_label(string $metric): string
{
    $key = str_starts_with($metric, 'metrics.') ? substr($metric, 8) : $metric;
    return ucfirst(str_replace('_', ' ', $key));
}

// ---- rendering ----------------------------------------------------------
//
// Server-rendered inline SVG. The app's chart is thirty lines of SVG, so the
// tracker matches it rather than adding a charting dependency, and the
// geometry below is pinned: any change to it has to re-run the Spanish golden
// snapshot. A refactor that improves the maths board and shifts the Spanish
// chart by two pixels is a failed change.

/** Trim a coordinate so the committed snapshot does not churn on float noise. */
function practice_coord(float $v): string
{
    $s = number_format($v, 2, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/**
 * The line chart, geometry pinned:
 *
 *   viewBox 0 0 600 200, left/right padding 45, baseline y=165, top y=45,
 *   y = 165 - (value / maxValue) * 120, a single point centred at x=300,
 *   polyline #7c3aed width 3 round joins, circles r=5, value labels 14px
 *   above the point at font-size 15 bold #4b5563.
 *
 * Hidden entirely below 2 runs: one point is not a trend.
 *
 * @param array<int,array{value:float,text:string,label:string}> $points oldest first
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
    foreach ($points as $i => $p) {
        $x = $n === 1 ? 300.0 : 45 + $i * (510 / ($n - 1));
        $y = 165 - (min((float) $p['value'], $maxValue) / $maxValue) * 120;
        $coords[] = [$x, $y];
    }

    $poly = implode(' ', array_map(
        static fn($c) => practice_coord($c[0]) . ',' . practice_coord($c[1]),
        $coords
    ));

    $svg = '<svg class="chart" viewBox="0 0 600 200" width="100%" style="max-height:200px" role="img">'
        . '<title>' . h($title) . '</title>'
        . '<polyline points="' . $poly . '" fill="none" stroke="#7c3aed" stroke-width="3"'
        . ' stroke-linejoin="round" stroke-linecap="round"/>';

    foreach ($coords as $i => $c) {
        $svg .= '<circle cx="' . practice_coord($c[0]) . '" cy="' . practice_coord($c[1])
            . '" r="5" fill="#7c3aed"/>';
        if ($labelPoints) {
            $svg .= '<text x="' . practice_coord($c[0]) . '" y="' . practice_coord($c[1] - 14)
                . '" text-anchor="middle" font-size="15" font-weight="700" fill="#4b5563">'
                . h($points[$i]['text']) . '</text>';
        }
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

    $out .= '<div class="tablewrap"><table><thead><tr><th>Topic</th><th>Name</th><th>Runs</th>'
        . '<th>Items</th><th>Right first time</th><th>Solve rate</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr><td class="mono"><a href="/s/' . h($slug) . '/t/' . rawurlencode((string) $r['ref'])
            . '">' . h((string) $r['ref']) . '</a></td>'
            . '<td>' . h((string) $r['name']) . '</td>'
            . '<td class="mono">' . h(practice_format((float) $r['runs'], 'integer')) . '</td>'
            . '<td class="mono">' . h(practice_format((float) $r['attempted'], 'integer')) . '</td>'
            . '<td class="mono">' . h(practice_format($r['accuracy'], 'percent1')) . '</td>'
            . '<td class="mono">' . h(practice_format($r['solve_rate'], 'percent1')) . '</td>'
            . '<td><small>' . h($r['status'] === null ? '—' : (STATUS_LABEL[$r['status']] ?? $r['status']))
            . '</small></td></tr>';
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
    return $filter;
}

function practice_filter_form(Store $store, array $subject, array $filter): string
{
    $options = '<option value="">Every activity</option>';
    foreach ($store->listPracticeSources($subject['slug']) as $s) {
        $options .= '<option value="' . h($s['key']) . '"'
            . (($filter['source'] ?? '') === $s['key'] ? ' selected' : '') . '>'
            . h($s['display_name']) . '</option>';
    }
    return '<form class="filters" method="get" action="/s/' . h($subject['slug']) . '/practice">'
        . '<label><small>Activity</small><select name="source">' . $options . '</select></label>'
        . '<label><small>From</small><input type="date" name="from" value="' . h($filter['since'] ?? '') . '"></label>'
        . '<label><small>To</small><input type="date" name="to" value="' . h($filter['until'] ?? '') . '"></label>'
        . '<label><small>Topic</small><input type="text" name="ref" size="6" placeholder="G14" value="'
        . h($filter['ref'] ?? '') . '"></label>'
        . '<button type="submit">Filter</button>'
        . ($filter ? ' <a href="/s/' . h($subject['slug']) . '/practice"><small>clear</small></a>' : '')
        . '</form>';
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
    $body .= practice_filter_form($store, $subject, $filter);

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
