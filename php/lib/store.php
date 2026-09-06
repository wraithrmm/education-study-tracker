<?php
/**
 * SQLite store: schema, queries, and the two derived calculations the rest of
 * the service leans on (grade conversion and ageing).
 *
 * A direct port of src/db.ts. The schema is byte-for-byte the same, so a
 * database written by the Node version opens here unchanged.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

// The practice scoreboard's configuration constants are read during migration,
// so they have to be loaded before a Store is ever constructed.
require_once __DIR__ . '/practice.php';

const STATUS_ORDER = ['notstarted', 'gap', 'developing', 'secure', 'examready'];

const STATUS_LABEL = [
    'notstarted' => 'Not started',
    'gap'        => 'Gap',
    'developing' => 'Developing',
    'secure'     => 'Secure',
    'examready'  => 'Exam-ready',
];

/** Points used for the headline "spec conquered" percentage. */
const STATUS_POINTS = [
    'notstarted' => 0,
    'gap'        => 0,
    'developing' => 1,
    'secure'     => 2,
    'examready'  => 3,
];

const SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS subjects (
  slug          TEXT PRIMARY KEY,
  name          TEXT NOT NULL,
  spec_code     TEXT,
  tier          TEXT,
  exam_date     TEXT,
  strands       TEXT NOT NULL DEFAULT '{}',
  boundaries    TEXT NOT NULL DEFAULT '{}',
  boundary_max  INTEGER NOT NULL DEFAULT 240,
  notes         TEXT
);

CREATE TABLE IF NOT EXISTS topics (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  ref           TEXT NOT NULL,
  name          TEXT NOT NULL,
  strand        TEXT NOT NULL,
  tier          TEXT NOT NULL DEFAULT 'F',
  status        TEXT NOT NULL DEFAULT 'notstarted',
  watch         TEXT,
  evidence      TEXT,
  last_touched  TEXT,
  sort_order    INTEGER NOT NULL DEFAULT 0,
  updated_at    TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (subject_slug, ref)
);

CREATE TABLE IF NOT EXISTS assessments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  date          TEXT NOT NULL,
  name          TEXT NOT NULL,
  kind          TEXT NOT NULL DEFAULT 'paper',
  tier          TEXT NOT NULL DEFAULT 'F',
  score         REAL NOT NULL,
  max           REAL NOT NULL,
  blanks        INTEGER,
  note          TEXT
);

-- An attempt is one sitting: a mock might be three papers, a topic check one.
-- The grade belongs here, at the top, because it is only meaningful across the
-- whole sitting — a single paper of a three-paper mock does not carry a grade.
CREATE TABLE IF NOT EXISTS attempts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  date          TEXT NOT NULL,
  name          TEXT NOT NULL,
  kind          TEXT NOT NULL DEFAULT 'paper',
  tier          TEXT NOT NULL DEFAULT 'F',
  note          TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS attempt_papers (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id    INTEGER NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
  code          TEXT NOT NULL,
  score         REAL NOT NULL,
  max           REAL NOT NULL,
  blanks        INTEGER,
  note          TEXT,
  -- Papers of one sitting are not always sat on one day at home. Null means
  -- "the same day as the attempt".
  sat_on        TEXT,
  sort_order    INTEGER NOT NULL DEFAULT 0
);

-- topic_ref is what turns a marked paper into teaching information: it says
-- which topic each lost mark belongs to.
CREATE TABLE IF NOT EXISTS attempt_questions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  paper_id      INTEGER NOT NULL REFERENCES attempt_papers(id) ON DELETE CASCADE,
  number        TEXT NOT NULL,
  topic_ref     TEXT,
  score         REAL NOT NULL,
  max           REAL NOT NULL,
  question      TEXT,
  answer        TEXT,
  note          TEXT,
  sort_order    INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS meta (
  key    TEXT PRIMARY KEY,
  value  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug   TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  date           TEXT NOT NULL,
  summary        TEXT NOT NULL,
  topics_touched TEXT,
  next_steps     TEXT,
  created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS topic_changes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL,
  ref           TEXT NOT NULL,
  from_status   TEXT,
  to_status     TEXT NOT NULL,
  evidence      TEXT NOT NULL,
  changed_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Teaching materials, either for one topic or for the whole subject.
-- ref = '' means subject-wide; SQLite treats NULLs as distinct in a UNIQUE
-- index, so an empty string is what makes "add the same thing twice" a no-op.
CREATE TABLE IF NOT EXISTS resources (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  subject_slug  TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
  ref           TEXT NOT NULL DEFAULT '',
  title         TEXT NOT NULL,
  url           TEXT,
  kind          TEXT NOT NULL DEFAULT 'other',
  note          TEXT,
  sort_order    INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (subject_slug, ref, title)
);

CREATE TABLE IF NOT EXISTS oauth_clients (
  client_id      TEXT PRIMARY KEY,
  client_secret  TEXT,
  redirect_uris  TEXT NOT NULL,
  client_name    TEXT,
  created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS oauth_codes (
  code            TEXT PRIMARY KEY,
  client_id       TEXT NOT NULL,
  redirect_uri    TEXT NOT NULL,
  code_challenge  TEXT NOT NULL,
  scope           TEXT,
  expires_at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS oauth_tokens (
  token       TEXT PRIMARY KEY,
  kind        TEXT NOT NULL,
  client_id   TEXT NOT NULL,
  scope       TEXT,
  expires_at  INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_topics_subject ON topics(subject_slug, sort_order);
CREATE INDEX IF NOT EXISTS idx_assessments_subject ON assessments(subject_slug, date);
CREATE INDEX IF NOT EXISTS idx_changes_subject ON topic_changes(subject_slug, changed_at);
CREATE INDEX IF NOT EXISTS idx_resources_subject ON resources(subject_slug, ref, sort_order);
CREATE INDEX IF NOT EXISTS idx_attempts_subject ON attempts(subject_slug, date);
CREATE INDEX IF NOT EXISTS idx_papers_attempt ON attempt_papers(attempt_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_questions_paper ON attempt_questions(paper_id, sort_order);
SQL;

/** The block kinds the timetable understands, in the order the day runs. */
const TIMETABLE_KINDS = [
    'movement', 'retrieval', 'teach', 'practise', 'timed_handwritten',
    'coding', 'writing', 'consolidate', 'spanish', 'review', 'break',
];

/**
 * How a block is judged.
 *   evidence    — done only when a session, attempt or practice run exists.
 *   self_report — done when someone ticks it (movement, the weekly review).
 *   none        — not judged at all (breaks).
 */
const TIMETABLE_TRACKING = ['evidence', 'self_report', 'none'];

const TIMETABLE_DAYS = [
    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
    5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
];

/**
 * Everything here works in Europe/London, because that is the clock the
 * student is actually looking at. Stored timestamps are UTC; the conversion
 * happens once, on the way in.
 */
function tt_zone(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/London');
}

function tt_now(): DateTimeImmutable
{
    // TRACKER_NOW freezes the clock so that `now`, `pending` and `missed` can
    // be asserted at a chosen minute instead of only ever being whatever the
    // test runner's wall clock says. Test-only: never set it in a deployed
    // .env, or the whole board will judge against a date that is not today.
    $fixed = getenv('TRACKER_NOW');
    if (is_string($fixed) && $fixed !== '') {
        try {
            return new DateTimeImmutable($fixed, tt_zone());
        } catch (Throwable) {
            // A malformed override is ignored rather than taking the service
            // down; the real clock is always a safe answer.
        }
    }
    return new DateTimeImmutable('now', tt_zone());
}

function tt_today(): string
{
    return tt_now()->format('Y-m-d');
}

/** Minutes since midnight, for a 'HH:MM'. */
function tt_mins(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

/** Validate and normalise a 'HH:MM', naming the field when it is wrong. */
function tt_hhmm(mixed $v, string $what): string
{
    $s = trim((string) $v);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
        throw new InvalidArgumentException("$what is '$s'; it must be a 24-hour time like 09:45.");
    }
    return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
}

function tt_add_days(string $date, int $n): string
{
    return (new DateTimeImmutable($date, tt_zone()))->modify(($n >= 0 ? '+' : '') . $n . ' days')
        ->format('Y-m-d');
}

/** The Monday of the ISO week a date falls in. */
function tt_monday(string $date): string
{
    $d = new DateTimeImmutable($date, tt_zone());
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
}

/** 'YYYY-Www' for a date. */
function tt_iso_week(string $date): string
{
    $d = new DateTimeImmutable($date, tt_zone());
    return $d->format('o') . '-W' . $d->format('W');
}

/** '10 September 2026', for a line a person reads. */
function tt_pretty(string $date): string
{
    return (new DateTimeImmutable($date, tt_zone()))->format('j F Y');
}

/** Monday of a 'YYYY-Www'. Returns null when the string is not one. */
function tt_week_monday(string $iso): ?string
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', trim($iso), $m)) {
        return null;
    }
    $week = (int) $m[2];
    if ($week < 1 || $week > 53) {
        return null;
    }
    $d = new DateTimeImmutable('now', tt_zone());
    return $d->setISODate((int) $m[1], $week, 1)->format('Y-m-d');
}

/** Which half of the alternation a date falls in, by ISO week number. */
function tt_parity(string $date): string
{
    return ((int) (new DateTimeImmutable($date, tt_zone()))->format('W')) % 2 === 1 ? 'odd' : 'even';
}

/** A stored UTC 'Y-m-d H:i:s' as a local ['date', 'time']. */
function tt_local(string $utc): array
{
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return [substr($utc, 0, 10), '00:00'];
    }
    $l = $d->setTimezone(tt_zone());
    return [$l->format('Y-m-d'), $l->format('H:i')];
}

/**
 * What changed between two block sets, keyed by block_key, so a re-cut can be
 * echoed to the parent before it is written.
 *
 * @return array{added:array<int,string>,removed:array<int,string>,changed:array<int,string>}
 */
function tt_diff(array $before, array $after): array
{
    $line = static fn(array $b): string => TIMETABLE_DAYS[$b['weekday']] . ' ' . $b['start'] . '–'
        . $b['end'] . ' ' . $b['label'];
    $index = static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['block_key']] = $r;
        }
        return $out;
    };
    $a = $index($before);
    $b = $index($after);

    $diff = ['added' => [], 'removed' => [], 'changed' => []];
    foreach ($b as $key => $row) {
        if (!isset($a[$key])) {
            $diff['added'][] = "$key · " . $line($row);
            continue;
        }
        $was = $a[$key];
        $fields = [];
        foreach (['weekday', 'start', 'end', 'kind', 'label', 'tracking'] as $f) {
            if ((string) $was[$f] !== (string) $row[$f]) {
                $fields[] = "$f " . $was[$f] . ' → ' . $row[$f];
            }
        }
        $wasSubjects = implode(',', $was['subjects'] ?? []);
        $nowSubjects = implode(',', $row['subjects'] ?? []);
        if ($wasSubjects !== $nowSubjects) {
            $fields[] = 'subjects ' . ($wasSubjects ?: '—') . ' → ' . ($nowSubjects ?: '—');
        }
        if ($fields) {
            $diff['changed'][] = "$key · " . $line($was) . ': ' . implode('; ', $fields);
        }
    }
    foreach ($a as $key => $row) {
        if (!isset($b[$key])) {
            $diff['removed'][] = "$key · " . $line($row);
        }
    }
    return $diff;
}

/**
 * The subjects a block resolves to on a date. A block with an `alternate`
 * runs one subject in odd ISO weeks and another in even ones, so the answer
 * depends on when you ask.
 *
 * @param  array<string,mixed> $block
 * @return array<int,string>
 */
function tt_subjects_for(array $block, string $date): array
{
    if (!empty($block['alternate'])) {
        return $block['alternate'][tt_parity($date)] ?? $block['subjects'];
    }
    return $block['subjects'];
}

/**
 * Whether a record is the kind of work a block asks for.
 *
 * Two blocks are fussy on purpose. A timed handwritten block is not satisfied
 * by a typed session that happened to be about the same subject — the point of
 * it is a marked paper. A retrieval block wants retrieval practice, not an
 * hour of new teaching. In both cases an explicit block_key overrides the
 * refinement, because someone has then said which block the work was for.
 *
 * @param array<string,mixed> $block
 * @param array<string,mixed> $ev
 */
function tt_kind_accepts(array $block, array $ev): bool
{
    if ($ev['block_key'] !== null && $ev['block_key'] === $block['block_key']) {
        return true;
    }
    return match ($block['kind']) {
        'timed_handwritten' => $ev['type'] === 'attempt',
        'retrieval'         => $ev['type'] === 'practice' && str_starts_with((string) $ev['source'], 'retrieval_'),
        default             => true,
    };
}

/** What a resource is for, used to sort and label it. */
const RESOURCE_KINDS = ['video', 'notes', 'practice', 'paper', 'book', 'other'];

final class Store
{
    public PDO $db;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        // PHP is per-request, so a writer can meet a reader mid-flight far more
        // often than the single long-lived Node process ever did.
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec(SCHEMA);
        $this->migrate();
    }

    /**
     * Schema and data changes that CREATE TABLE IF NOT EXISTS cannot express.
     *
     * A version ladder rather than one marker, because there will be more of
     * these: each step runs once, in order, and the stored version says where
     * a database got to. This runs against a live database holding the only
     * copy of the record, so every step checks the current shape rather than
     * assuming it.
     */
    private const SCHEMA_VERSION = 4;

    private function migrate(): void
    {
        // Fast path, taken on every request but the first after a deploy.
        if ($this->schemaVersion() >= self::SCHEMA_VERSION) {
            return;
        }

        // BEGIN IMMEDIATE takes the write lock before anything is read, so two
        // requests arriving together cannot both decide a step is still pending
        // and run it twice. Each step commits on its own: a step that fails
        // rolls back without discarding the ones already applied.
        for ($v = $this->schemaVersion() + 1; $v <= self::SCHEMA_VERSION; $v++) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                if ($this->schemaVersion() >= $v) {
                    $this->db->exec('COMMIT');
                    continue;
                }
                $this->migrateStep($v);
                $this->setMeta('schema_version', (string) $v);
                $this->db->exec('COMMIT');
            } catch (Throwable $e) {
                $this->db->exec('ROLLBACK');
                throw $e;
            }
        }
    }

    private function schemaVersion(): int
    {
        $v = $this->meta('schema_version');
        if ($v !== null) {
            return (int) $v;
        }
        // Databases migrated by the first build carry the original marker
        // instead of a version number. That marker is exactly version 1.
        return $this->meta('schema_attempts') !== null ? 1 : 0;
    }

    private function migrateStep(int $v): void
    {
        if ($v === 1) {
            // Attributes a topic change to the session that produced it, so the
            // history can show what each session actually moved. Changes made
            // by tracker_update_topic alone keep a null session_id.
            if (!$this->hasColumn('topic_changes', 'session_id')) {
                $this->db->exec('ALTER TABLE topic_changes ADD COLUMN session_id INTEGER');
            }
            // A session logged in error is voided with a reason rather than
            // deleted: an audit trail that can lose entries is not one.
            if (!$this->hasColumn('sessions', 'void_reason')) {
                $this->db->exec('ALTER TABLE sessions ADD COLUMN void_reason TEXT');
            }

            // Flat assessments become one attempt holding a single paper. The
            // assessments table itself is left untouched as the fallback copy.
            foreach ($this->all('SELECT * FROM assessments ORDER BY id') as $a) {
                $st = $this->db->prepare(
                    'INSERT INTO attempts (subject_slug, date, name, kind, tier, note)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $a['subject_slug'], $a['date'], $a['name'],
                    $a['kind'], $a['tier'], $a['note'],
                ]);
                $attemptId = (int) $this->db->lastInsertId();

                $st = $this->db->prepare(
                    'INSERT INTO attempt_papers (attempt_id, code, score, max, blanks, sort_order)
                     VALUES (?, ?, ?, ?, ?, 0)'
                );
                // The old rows carried no paper code, only the assessment name.
                $st->execute([$attemptId, $a['name'], $a['score'], $a['max'], $a['blanks']]);
            }
            return;
        }

        if ($v === 2) {
            // Papers of one sitting are not always sat on one day at home.
            if (!$this->hasColumn('attempt_papers', 'sat_on')) {
                $this->db->exec('ALTER TABLE attempt_papers ADD COLUMN sat_on TEXT');
            }
            $this->mergeSeededMathsPapers();
            return;
        }

        if ($v === 3) {
            // Practice: Spanish app games and maths tutoring sessions. Same
            // shape of thing — she did some practice, here is how it went — so
            // one storage model and one scoreboard engine serve both.
            $this->createPracticeTables();

            foreach (PRACTICE_SOURCE_SEED as $source) {
                $this->upsertPracticeSource($source);
            }

            // The Spanish board is seeded rather than left to the fallback,
            // because it has to reproduce the app's view exactly and that is
            // a fixture, not a starting point. A subject that does not exist
            // here yet picks the same configuration up from the code-side
            // default until someone writes one with tracker_set_scoreboard.
            foreach (practice_seeded_scoreboards() as $slug => $config) {
                if ($this->getSubject($slug) && $this->getScoreboard($slug) === null) {
                    $this->setScoreboard($slug, $config, 'Seeded by schema step 3.');
                }
            }
            return;
        }

        if ($v === 4) {
            // The weekly timetable. Nothing here rewrites an existing record:
            // sessions, attempts and practice runs each gain one nullable
            // column and are otherwise untouched.
            $this->createTimetableTables();

            // An explicit link from a logged record to the block it fulfilled.
            // Null is the normal case and means "bind me by subject and date"
            // — the link exists for the days two blocks share a subject and
            // the greedy pass would otherwise guess.
            if (!$this->hasColumn('sessions', 'block_key')) {
                $this->db->exec('ALTER TABLE sessions ADD COLUMN block_key INTEGER');
            }
            // How long the session actually ran, so a 20-minute sitting in a
            // 75-minute block reads as `short` rather than as done.
            if (!$this->hasColumn('sessions', 'duration_minutes')) {
                $this->db->exec('ALTER TABLE sessions ADD COLUMN duration_minutes INTEGER');
            }
            // On the paper rather than the attempt, because sat_on is there:
            // the paper is the thing with a date of its own.
            if (!$this->hasColumn('attempt_papers', 'block_key')) {
                $this->db->exec('ALTER TABLE attempt_papers ADD COLUMN block_key INTEGER');
            }
            if (!$this->hasColumn('practice_run', 'block_key')) {
                $this->db->exec('ALTER TABLE practice_run ADD COLUMN block_key INTEGER');
            }

            // Vestigial: three designs were built behind a switch, and only
            // the week strip was kept. Nothing reads this key any more. The
            // write stays because this step has already run against the live
            // database, and an applied migration is a record of what happened
            // rather than something to tidy up afterwards.
            if ($this->meta('timetable_design') === null) {
                $this->setMeta('timetable_design', 'a');
            }
            return;
        }
    }

    /**
     * The practice tables. `attempted` is held to equal correct +
     * correct_after_retry + incorrect by a CHECK constraint as well as by the
     * tool, because the arithmetic is the whole basis of every figure the
     * board shows.
     *
     * accuracy and solve_rate are generated columns so anyone reading the
     * database directly sees them, but nothing in this service depends on
     * them — every figure is computed from the raw counts — so a SQLite too
     * old for generated columns (pre-3.31) gets the same table without them
     * rather than a migration that throws and takes the service down.
     */
    private function createPracticeTables(): void
    {
        $version   = (string) $this->db->query('SELECT sqlite_version()')->fetchColumn();
        $generated = version_compare($version, '3.31.0', '>=')
            ? "               accuracy   NUMERIC GENERATED ALWAYS AS (CAST(correct AS REAL) / nullif(attempted, 0)) VIRTUAL,\n"
              . "               solve_rate NUMERIC GENERATED ALWAYS AS (CAST(correct + correct_after_retry AS REAL) / nullif(attempted, 0)) VIRTUAL,\n"
            : '';

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS practice_source (
               key            TEXT PRIMARY KEY,
               display_name   TEXT NOT NULL,
               -- Null means the source works in any subject: a general quiz
               -- activity should not need re-registering per subject.
               subject_slug   TEXT,
               metrics_schema TEXT NOT NULL DEFAULT '{}',
               created_at     TEXT NOT NULL DEFAULT (datetime('now'))
             )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS practice_run (
               id            INTEGER PRIMARY KEY AUTOINCREMENT,
               subject_slug  TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
               -- Idempotency key from the client, unique per subject.
               client_run_id TEXT NOT NULL,
               source        TEXT NOT NULL,
               label         TEXT NOT NULL,
               played_at     TEXT NOT NULL DEFAULT (datetime('now')),
               attempted     INTEGER NOT NULL CHECK (attempted >= 0),
               correct       INTEGER NOT NULL CHECK (correct >= 0),
               correct_after_retry INTEGER NOT NULL DEFAULT 0 CHECK (correct_after_retry >= 0),
               incorrect     INTEGER NOT NULL CHECK (incorrect >= 0),
"
            . $generated
            . "               duration_seconds INTEGER,
               -- Source-specific numbers. top_speed is meaningful for a
               -- falling-word game and meaningless for maths, so it lives
               -- here rather than in a column most rows would ignore.
               metrics       TEXT NOT NULL DEFAULT '{}',
               -- Non-null excludes the run from every statistic; the row stays.
               void_reason   TEXT,
               created_at    TEXT NOT NULL DEFAULT (datetime('now')),
               -- The arithmetic every figure on the board is built on.
               CHECK (attempted = correct + correct_after_retry + incorrect),
               UNIQUE (subject_slug, client_run_id)
             )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS practice_item (
               id              INTEGER PRIMARY KEY AUTOINCREMENT,
               practice_run_id INTEGER NOT NULL REFERENCES practice_run(id) ON DELETE CASCADE,
               position        INTEGER,
               prompt          TEXT,
               topic_ref       TEXT,
               outcome         TEXT NOT NULL CHECK (outcome IN ('correct', 'retry', 'incorrect')),
               attempts_taken  INTEGER,
               note            TEXT
             )"
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS practice_run_topic (
               practice_run_id INTEGER NOT NULL REFERENCES practice_run(id) ON DELETE CASCADE,
               topic_ref       TEXT NOT NULL,
               PRIMARY KEY (practice_run_id, topic_ref)
             )'
        );

        // Panel instances are configuration, panel types are code. Rows are
        // appended, never replaced, so a bad edit can be read back out.
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS scoreboard_config (
               id           INTEGER PRIMARY KEY AUTOINCREMENT,
               subject_slug TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
               config       TEXT NOT NULL,
               note         TEXT,
               created_at   TEXT NOT NULL DEFAULT (datetime('now'))
             )"
        );

        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_practice_subject ON practice_run(subject_slug, played_at DESC)',
            'CREATE INDEX IF NOT EXISTS idx_practice_source ON practice_run(subject_slug, source, played_at DESC)',
            'CREATE INDEX IF NOT EXISTS idx_practice_item_run ON practice_item(practice_run_id, position)',
            'CREATE INDEX IF NOT EXISTS idx_practice_item_topic ON practice_item(topic_ref)',
            'CREATE INDEX IF NOT EXISTS idx_practice_run_topic_ref ON practice_run_topic(topic_ref)',
            'CREATE INDEX IF NOT EXISTS idx_scoreboard_subject ON scoreboard_config(subject_slug, id DESC)',
        ] as $sql) {
            $this->db->exec($sql);
        }
    }

    /**
     * The weekly timetable, its days off, and the per-date overrides.
     *
     * Created in a migration step rather than in SCHEMA so that the ALTER
     * TABLEs that go with it run under the same write lock: a database that
     * has the tables but not the block_key columns would judge every block as
     * missed, which is worse than not having the feature.
     */
    private function createTimetableTables(): void
    {
        // A timetable is versioned rather than edited, so a week judged in
        // September still resolves against the shape that was in force then.
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS timetable_versions (
               id         INTEGER PRIMARY KEY AUTOINCREMENT,
               valid_from TEXT NOT NULL,
               created_at TEXT NOT NULL DEFAULT (datetime('now')),
               note       TEXT
             )"
        );

        // block_key is the identity that survives a re-cut: an excusal written
        // against block 16 in week 37 still resolves after the timetable is
        // edited in week 40. The primary key does not, so nothing user-facing
        // is ever allowed to reference it.
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS timetable_blocks (
               id             INTEGER PRIMARY KEY AUTOINCREMENT,
               version_id     INTEGER NOT NULL REFERENCES timetable_versions(id) ON DELETE CASCADE,
               block_key      INTEGER NOT NULL,
               weekday        INTEGER NOT NULL CHECK (weekday BETWEEN 1 AND 7),
               start          TEXT NOT NULL,
               end            TEXT NOT NULL,
               kind           TEXT NOT NULL CHECK (kind IN (
                                'movement','retrieval','teach','practise','timed_handwritten',
                                'coding','writing','consolidate','spanish','review','break')),
               label          TEXT NOT NULL,
               note           TEXT,
               subjects_json  TEXT NOT NULL DEFAULT '[]',
               alternate_json TEXT,
               tracking       TEXT NOT NULL CHECK (tracking IN ('evidence','self_report','none')),
               sort           INTEGER NOT NULL,
               UNIQUE (version_id, block_key)
             )"
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_blocks_version ON timetable_blocks(version_id, weekday, sort)'
        );

        // Anyone may ask for a day off; only the parent decides. Declined and
        // un-approved records are kept with their note, so "we said no and
        // why" survives in the record rather than vanishing.
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS days_off (
               id            INTEGER PRIMARY KEY AUTOINCREMENT,
               date_from     TEXT NOT NULL,
               date_to       TEXT NOT NULL,
               kind          TEXT NOT NULL DEFAULT 'day_off'
                               CHECK (kind IN ('holiday','day_off','sick','other')),
               reason        TEXT NOT NULL,
               requested_by  TEXT NOT NULL CHECK (requested_by IN ('student','parent')),
               status        TEXT NOT NULL CHECK (status IN ('requested','approved','declined')),
               decided_at    TEXT,
               decision_note TEXT,
               created_at    TEXT NOT NULL DEFAULT (datetime('now'))
             )"
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_days_off_range ON days_off(date_from, date_to)'
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS timetable_excusals (
               id         INTEGER PRIMARY KEY AUTOINCREMENT,
               date       TEXT NOT NULL,
               block_key  INTEGER NOT NULL,
               reason     TEXT,
               created_at TEXT NOT NULL DEFAULT (datetime('now')),
               UNIQUE (date, block_key)
             )"
        );

        // Only for tracking = 'self_report'. A tick on an evidence block is
        // refused at the tool, because the whole point of the board is that
        // study blocks are derived from logged work rather than declared.
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS timetable_ticks (
               id         INTEGER PRIMARY KEY AUTOINCREMENT,
               date       TEXT NOT NULL,
               block_key  INTEGER NOT NULL,
               by         TEXT NOT NULL CHECK (by IN ('student','parent')),
               note       TEXT,
               created_at TEXT NOT NULL DEFAULT (datetime('now')),
               UNIQUE (date, block_key)
             )"
        );
    }

    /**
     * The three AQA 8300 Jun-22 foundation papers were one sitting, but the
     * pre-attempts record held them as three separate assessments, so step 1
     * turned them into three one-paper attempts. Three 80-mark papers were then
     * each scaled against the 240-mark boundary table on their own, showing
     * three grades for one exam.
     *
     * Narrowly guarded: it fires only on exactly the shape step 1 produces —
     * three single-paper attempts, matching names, none carrying questions —
     * so a database where these have already been corrected, edited or built
     * on is left alone.
     */
    private function mergeSeededMathsPapers(): void
    {
        $rows = $this->all(
            "SELECT a.id, a.date, a.name, a.tier, a.subject_slug
             FROM attempts a
             WHERE a.kind = 'paper' AND a.name LIKE '8300/_F Jun-22%'
             ORDER BY a.date, a.id"
        );
        if (count($rows) !== 3) {
            return;
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $in  = implode(',', $ids);

        // Every one of them must still be the single-paper, no-questions shape
        // step 1 created, and they must all belong to one subject.
        if (count(array_unique(array_column($rows, 'subject_slug'))) !== 1) {
            return;
        }
        $papers = $this->all(
            "SELECT p.* FROM attempt_papers p WHERE p.attempt_id IN ($in) ORDER BY p.attempt_id"
        );
        if (count($papers) !== 3) {
            return;
        }
        $paperIds = implode(',', array_map(static fn($p) => (int) $p['id'], $papers));
        $q        = $this->one("SELECT COUNT(*) AS n FROM attempt_questions WHERE paper_id IN ($paperIds)");
        if ((int) ($q['n'] ?? 0) !== 0) {
            return;
        }

        // Keep the earliest attempt as the sitting and hang the other two
        // papers off it, each keeping the date it was actually sat.
        $keep  = $rows[0];
        $byId  = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $order = 0;
        foreach ($papers as $p) {
            $src  = $byId[(int) $p['attempt_id']];
            // Paper 1 first, then 2, then 3, whatever order they were sat in.
            $code = preg_match('#(8300/\dF)#', (string) $src['name'], $m) ? $m[1] : (string) $p['code'];
            $st   = $this->db->prepare(
                'UPDATE attempt_papers SET attempt_id = ?, code = ?, sat_on = ?, sort_order = ? WHERE id = ?'
            );
            $st->execute([(int) $keep['id'], $code, $src['date'], $order++, (int) $p['id']]);
        }
        // Re-sort by paper number now the codes are known.
        $this->db->exec(
            "UPDATE attempt_papers SET sort_order = CAST(substr(code, 6, 1) AS INTEGER)
             WHERE attempt_id = " . (int) $keep['id'] . " AND code LIKE '8300/_F'"
        );

        $st = $this->db->prepare(
            'UPDATE attempts SET name = ?, note = ? WHERE id = ?'
        );
        $st->execute([
            'AQA 8300 Foundation, June 2022',
            'All three papers of one sitting, sat across several weeks; each paper keeps its own date.',
            (int) $keep['id'],
        ]);

        $gone = implode(',', array_slice($ids, 1));
        $this->db->exec("DELETE FROM attempts WHERE id IN ($gone)");
    }

    private function hasColumn(string $table, string $column): bool
    {
        foreach ($this->all("PRAGMA table_info($table)") as $c) {
            if (($c['name'] ?? null) === $column) {
                return true;
            }
        }
        return false;
    }

    public function meta(string $key): ?string
    {
        $row = $this->one('SELECT value FROM meta WHERE key = ?', [$key]);
        return $row ? (string) $row['value'] : null;
    }

    public function setMeta(string $key, string $value): void
    {
        $st = $this->db->prepare(
            'INSERT INTO meta (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $st->execute([$key, $value]);
    }

    /** @param array<int,mixed> $params */
    private function all(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @param array<int,mixed> $params */
    private function one(string $sql, array $params = []): ?array
    {
        $row = $this->all($sql, $params)[0] ?? null;
        return $row === null ? null : $row;
    }

    // ---- subjects -------------------------------------------------------

    /** @return array<int,array> */
    public function listSubjects(): array
    {
        return array_map(
            [$this, 'hydrateSubject'],
            $this->all('SELECT * FROM subjects ORDER BY name')
        );
    }

    public function getSubject(string $slug): ?array
    {
        $row = $this->one('SELECT * FROM subjects WHERE slug = ?', [$slug]);
        return $row ? $this->hydrateSubject($row) : null;
    }

    private function hydrateSubject(array $r): array
    {
        return [
            'slug'         => (string) $r['slug'],
            'name'         => (string) $r['name'],
            'spec_code'    => $r['spec_code'] ?? null,
            'tier'         => $r['tier'] ?? null,
            'exam_date'    => $r['exam_date'] ?? null,
            'strands'      => json_decode((string) ($r['strands'] ?: '{}'), true) ?: [],
            'boundaries'   => json_decode((string) ($r['boundaries'] ?: '{}'), true) ?: [],
            'boundary_max' => (int) ($r['boundary_max'] ?? 240),
            'notes'        => $r['notes'] ?? null,
        ];
    }

    /**
     * Create a subject, or amend the fields of one that exists.
     *
     * A key that is absent is left as it was; a key that is present is
     * written, empty or not. That is what lets one field be corrected — an
     * exam date that turned out to be for the wrong year — without re-sending
     * a hundred topics and a strand map just to stand still. The merge is done
     * here rather than in SQL because ON CONFLICT sees the defaulted value in
     * `excluded`, not the absent one, so COALESCE there silently overwrites.
     */
    public function upsertSubject(array $s): array
    {
        $slug = $s['slug'];
        $old  = $this->one('SELECT * FROM subjects WHERE slug = ?', [$slug]);

        $keep = static fn(string $key, mixed $fallback): mixed =>
            array_key_exists($key, $s) && $s[$key] !== null ? $s[$key] : $fallback;

        // JSON_FORCE_OBJECT keeps an empty map as {} rather than [], which is
        // what the Node version wrote and what hydrateSubject expects.
        $json = static fn(mixed $v): string => json_encode((object) ($v ?? []));

        $row = [
            ':slug'         => $slug,
            ':name'         => $keep('name', $old['name'] ?? $slug),
            ':spec_code'    => $keep('spec_code', $old['spec_code'] ?? null),
            ':tier'         => $keep('tier', $old['tier'] ?? null),
            ':exam_date'    => $keep('exam_date', $old['exam_date'] ?? null),
            ':strands'      => array_key_exists('strands', $s)
                ? $json($s['strands']) : ($old['strands'] ?? '{}'),
            ':boundaries'   => array_key_exists('boundaries', $s)
                ? $json($s['boundaries']) : ($old['boundaries'] ?? '{}'),
            ':boundary_max' => $keep('boundary_max', $old['boundary_max'] ?? 240),
            ':notes'        => $keep('notes', $old['notes'] ?? null),
        ];

        $st = $this->db->prepare(
            'INSERT INTO subjects
               (slug, name, spec_code, tier, exam_date, strands, boundaries, boundary_max, notes)
             VALUES (:slug, :name, :spec_code, :tier, :exam_date, :strands, :boundaries,
                     :boundary_max, :notes)
             ON CONFLICT(slug) DO UPDATE SET
               name = excluded.name,
               spec_code = excluded.spec_code,
               tier = excluded.tier,
               exam_date = excluded.exam_date,
               strands = excluded.strands,
               boundaries = excluded.boundaries,
               boundary_max = excluded.boundary_max,
               notes = excluded.notes'
        );
        $st->execute($row);
        return $this->getSubject($slug);
    }

    // ---- topics ---------------------------------------------------------

    /** @param array{status?:array<int,string>,strand?:string} $filter */
    public function listTopics(string $slug, array $filter = []): array
    {
        $sql    = 'SELECT * FROM topics WHERE subject_slug = ?';
        $params = [$slug];
        if (!empty($filter['strand'])) {
            $sql .= ' AND strand = ?';
            $params[] = $filter['strand'];
        }
        if (!empty($filter['status'])) {
            $sql .= ' AND status IN (' . implode(',', array_fill(0, count($filter['status']), '?')) . ')';
            foreach ($filter['status'] as $s) {
                $params[] = $s;
            }
        }
        $sql .= ' ORDER BY sort_order, ref';
        return $this->all($sql, $params);
    }

    public function getTopic(string $slug, string $ref): ?array
    {
        return $this->one('SELECT * FROM topics WHERE subject_slug = ? AND ref = ?', [$slug, $ref]);
    }

    public function upsertTopic(array $t): void
    {
        $st = $this->db->prepare(
            'INSERT INTO topics (subject_slug, ref, name, strand, tier, status, watch, evidence, last_touched, sort_order)
             VALUES (:subject_slug, :ref, :name, :strand, :tier, :status, :watch, :evidence, :last_touched, :sort_order)
             ON CONFLICT(subject_slug, ref) DO UPDATE SET
               name = excluded.name,
               strand = excluded.strand,
               tier = excluded.tier,
               sort_order = excluded.sort_order,
               updated_at = datetime(\'now\')'
        );
        $st->execute([
            ':subject_slug' => $t['subject_slug'],
            ':ref'          => $t['ref'],
            ':name'         => $t['name'],
            ':strand'       => $t['strand'],
            ':tier'         => $t['tier'] ?? 'F',
            ':status'       => $t['status'] ?? 'notstarted',
            ':watch'        => $t['watch'] ?? null,
            ':evidence'     => $t['evidence'] ?? null,
            ':last_touched' => $t['last_touched'] ?? null,
            ':sort_order'   => $t['sort_order'] ?? 0,
        ]);
    }

    /**
     * Update a topic's status and record the change with its evidence.
     * Returns ['previous' => ..., 'current' => ...], or null if there is no
     * such topic.
     */
    public function updateTopicStatus(array $args): ?array
    {
        $existing = $this->getTopic($args['subject_slug'], $args['ref']);
        if (!$existing) {
            return null;
        }
        $next    = $args['status'] ?? $existing['status'];
        $touched = $args['last_touched'] ?? gmdate('Y-m-d');
        // A key that is absent leaves the note alone; an explicit null clears it.
        $watch = array_key_exists('watch', $args) ? $args['watch'] : $existing['watch'];

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare(
                'UPDATE topics SET status = ?, evidence = ?, watch = ?, last_touched = ?, updated_at = datetime(\'now\')
                 WHERE subject_slug = ? AND ref = ?'
            );
            $st->execute([$next, $args['evidence'], $watch, $touched, $args['subject_slug'], $args['ref']]);

            $st = $this->db->prepare(
                'INSERT INTO topic_changes (subject_slug, ref, from_status, to_status, evidence, session_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $args['subject_slug'], $args['ref'], $existing['status'], $next,
                $args['evidence'], $args['session_id'] ?? null,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['previous' => $existing['status'], 'current' => $next];
    }

    public function listChanges(string $slug, int $limit = 50): array
    {
        return $this->all(
            'SELECT * FROM topic_changes WHERE subject_slug = ? ORDER BY changed_at DESC, id DESC LIMIT ?',
            [$slug, $limit]
        );
    }

    /** The changes one session produced. */
    public function changesForSession(int $sessionId): array
    {
        return $this->all(
            'SELECT * FROM topic_changes WHERE session_id = ? ORDER BY id',
            [$sessionId]
        );
    }

    /** Row counts for /healthz, so a deploy can be checked from outside. */
    public function counts(): array
    {
        $out = [];
        foreach (['topics', 'attempts', 'attempt_papers', 'attempt_questions',
                  'sessions', 'topic_changes', 'resources', 'practice_run',
                  'practice_item'] as $t) {
            $row     = $this->one("SELECT COUNT(*) AS n FROM $t");
            $out[$t] = (int) ($row['n'] ?? 0);
        }
        return $out;
    }

    // ---- sessions --------------------------------------------------------

    /**
     * The pre-attempts table. Nothing writes to it any more — migrate()
     * copied every row into attempts on first open and the copy is left in
     * place as the only fallback if that migration ever turns out wrong.
     */
    public function listAssessments(string $slug): array
    {
        return $this->all(
            'SELECT * FROM assessments WHERE subject_slug = ? ORDER BY date DESC, id DESC',
            [$slug]
        );
    }


    public function listSessions(string $slug, int $limit = 20): array
    {
        return $this->all(
            'SELECT * FROM sessions WHERE subject_slug = ? ORDER BY date DESC, id DESC LIMIT ?',
            [$slug, $limit]
        );
    }

    // ---- attempts, papers, questions ------------------------------------

    /** Attempt rows with their papers totalled; questions are not loaded. */
    public function listAttempts(string $slug, int $limit = 20): array
    {
        $rows = $this->all(
            'SELECT * FROM attempts WHERE subject_slug = ? ORDER BY date DESC, id DESC LIMIT ?',
            [$slug, $limit]
        );
        foreach ($rows as &$a) {
            $a['papers'] = $this->listPapers((int) $a['id']);
            $a['score']  = array_sum(array_column($a['papers'], 'score'));
            $a['max']    = array_sum(array_column($a['papers'], 'max'));
            $blanks      = array_filter(array_column($a['papers'], 'blanks'), static fn($b) => $b !== null);
            $a['blanks'] = $blanks ? array_sum($blanks) : null;
        }
        return $rows;
    }

    public function getAttempt(string $slug, int $id): ?array
    {
        $a = $this->one('SELECT * FROM attempts WHERE subject_slug = ? AND id = ?', [$slug, $id]);
        if (!$a) {
            return null;
        }
        $a['papers'] = $this->listPapers($id);
        foreach ($a['papers'] as &$p) {
            $p['questions'] = $this->listQuestions((int) $p['id']);
        }
        unset($p);
        $a['score']  = array_sum(array_column($a['papers'], 'score'));
        $a['max']    = array_sum(array_column($a['papers'], 'max'));
        $blanks      = array_filter(array_column($a['papers'], 'blanks'), static fn($b) => $b !== null);
        $a['blanks'] = $blanks ? array_sum($blanks) : null;
        return $a;
    }

    public function listPapers(int $attemptId): array
    {
        return $this->all(
            'SELECT * FROM attempt_papers WHERE attempt_id = ? ORDER BY sort_order, id',
            [$attemptId]
        );
    }

    public function listQuestions(int $paperId): array
    {
        return $this->all(
            'SELECT * FROM attempt_questions WHERE paper_id = ? ORDER BY sort_order, id',
            [$paperId]
        );
    }

    /**
     * Writes an attempt and everything under it in one transaction: a half
     * written sitting is worse than a rejected one.
     *
     * @param array $a subject_slug, date, name, kind, tier, note, papers[]
     */
    public function addAttempt(array $a): int
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare(
                'INSERT INTO attempts (subject_slug, date, name, kind, tier, note)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $a['subject_slug'], $a['date'], $a['name'],
                $a['kind'] ?? 'paper', $a['tier'] ?? 'F', $a['note'] ?? null,
            ]);
            $attemptId = (int) $this->db->lastInsertId();

            foreach (array_values($a['papers'] ?? []) as $i => $paper) {
                $st = $this->db->prepare(
                    'INSERT INTO attempt_papers
                       (attempt_id, code, score, max, blanks, note, sat_on, block_key, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $attemptId, $paper['code'], $paper['score'], $paper['max'],
                    $paper['blanks'] ?? null, $paper['note'] ?? null, $paper['sat_on'] ?? null,
                    $paper['block_key'] ?? null, $i,
                ]);
                $paperId = (int) $this->db->lastInsertId();

                foreach (array_values($paper['questions'] ?? []) as $j => $q) {
                    $st = $this->db->prepare(
                        'INSERT INTO attempt_questions
                           (paper_id, number, topic_ref, score, max, question, answer, note, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        $paperId, $q['number'], $q['topic_ref'] ?? null,
                        $q['score'], $q['max'], $q['question'] ?? null,
                        $q['answer'] ?? null, $q['note'] ?? null, $j,
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $attemptId;
    }

    /**
     * Marks lost per topic across one attempt — the thing a marked paper is
     * actually for. Only questions carrying a topic_ref can contribute.
     */
    public function attemptTopicBreakdown(string $slug, int $attemptId): array
    {
        return $this->all(
            "SELECT q.topic_ref AS ref,
                    COALESCE(t.name, '(unknown topic)') AS name,
                    SUM(q.score) AS score,
                    SUM(q.max) AS max,
                    COUNT(*) AS questions
             FROM attempt_questions q
             JOIN attempt_papers p ON p.id = q.paper_id
             JOIN attempts a ON a.id = p.attempt_id
             LEFT JOIN topics t ON t.subject_slug = a.subject_slug AND t.ref = q.topic_ref
             WHERE a.subject_slug = ? AND a.id = ? AND q.topic_ref IS NOT NULL AND q.topic_ref <> ''
             GROUP BY q.topic_ref
             ORDER BY (SUM(q.max) - SUM(q.score)) DESC, q.topic_ref",
            [$slug, $attemptId]
        );
    }

    // ---- history ---------------------------------------------------------

    /**
     * Sessions and topic changes for a subject, newest first, each tagged with
     * the ISO week it happened in so a caller can group by week without
     * parsing dates itself.
     */
    public function history(string $slug, int $weeks = 12, ?string $ref = null): array
    {
        $since = gmdate('Y-m-d', time() - $weeks * 7 * 86400);

        // Filtered to one topic, the trail should be that topic's trail: only
        // the sessions that actually moved it, or a session list that mostly
        // has nothing to do with the question being asked.
        $sessions = $ref === null
            ? $this->all(
                'SELECT * FROM sessions WHERE subject_slug = ? AND date >= ? ORDER BY date DESC, id DESC',
                [$slug, $since]
            )
            : $this->all(
                'SELECT * FROM sessions WHERE subject_slug = ? AND date >= ? AND id IN (
                     SELECT session_id FROM topic_changes
                     WHERE subject_slug = ? AND ref = ? AND session_id IS NOT NULL
                 ) ORDER BY date DESC, id DESC',
                [$slug, $since, $slug, $ref]
            );

        $sql    = "SELECT c.*, COALESCE(t.name, '') AS topic_name
                   FROM topic_changes c
                   LEFT JOIN topics t ON t.subject_slug = c.subject_slug AND t.ref = c.ref
                   WHERE c.subject_slug = ? AND date(c.changed_at) >= ?";
        $params = [$slug, $since];
        if ($ref !== null) {
            $sql .= ' AND c.ref = ?';
            $params[] = $ref;
        }
        $sql .= ' ORDER BY c.changed_at DESC, c.id DESC';
        $changes = $this->all($sql, $params);

        return ['sessions' => $sessions, 'changes' => $changes];
    }

    /**
     * Every marked question recorded against one topic, newest first, with the
     * paper and attempt it came from. This is the other half of a topic's
     * history: not just what we decided about it, but how it actually examined.
     */
    public function questionsForTopic(string $slug, string $ref): array
    {
        return $this->all(
            'SELECT q.*, p.code, a.id AS attempt_id, a.name AS attempt_name, a.date
             FROM attempt_questions q
             JOIN attempt_papers p ON p.id = q.paper_id
             JOIN attempts a ON a.id = p.attempt_id
             WHERE a.subject_slug = ? AND q.topic_ref = ?
             ORDER BY a.date DESC, p.sort_order, q.sort_order',
            [$slug, $ref]
        );
    }

    /** ISO week label for a date, e.g. 2026-W36, plus the Monday it starts. */
    public static function weekOf(string $date): array
    {
        $t = strtotime(substr($date, 0, 10) . ' 12:00:00 UTC');
        if ($t === false) {
            return ['label' => 'unknown', 'monday' => ''];
        }
        return [
            'label'  => gmdate('o-\WW', $t),
            'monday' => gmdate('Y-m-d', $t - ((int) gmdate('N', $t) - 1) * 86400),
        ];
    }

    public function getSession(string $slug, int $id): ?array
    {
        return $this->one('SELECT * FROM sessions WHERE subject_slug = ? AND id = ?', [$slug, $id]);
    }

    /** Set after the fact, once the session's updates have been applied. */
    public function setSessionTopics(int $id, string $topics): void
    {
        $st = $this->db->prepare('UPDATE sessions SET topics_touched = ? WHERE id = ?');
        $st->execute([$topics, $id]);
    }

    /** @param array<string,mixed> $fields date, summary, next_steps, void_reason */
    public function amendSession(string $slug, int $id, array $fields): bool
    {
        $allowed = ['date', 'summary', 'next_steps', 'void_reason'];
        $sets    = [];
        $params  = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $fields)) {
                $sets[]   = "$k = ?";
                $params[] = $fields[$k];
            }
        }
        if (!$sets) {
            return false;
        }
        $params[] = $slug;
        $params[] = $id;
        $st = $this->db->prepare(
            'UPDATE sessions SET ' . implode(', ', $sets) . ' WHERE subject_slug = ? AND id = ?'
        );
        $st->execute($params);
        return $st->rowCount() > 0;
    }

    // ---- resources ------------------------------------------------------

    /**
     * @param string|null $ref null for everything in the subject, '' for the
     *                         subject-wide ones only, or a topic reference.
     */
    public function listResources(string $slug, ?string $ref = null): array
    {
        if ($ref === null) {
            return $this->all(
                'SELECT * FROM resources WHERE subject_slug = ? ORDER BY ref, sort_order, id',
                [$slug]
            );
        }
        return $this->all(
            'SELECT * FROM resources WHERE subject_slug = ? AND ref = ? ORDER BY sort_order, id',
            [$slug, $ref]
        );
    }

    /**
     * Resources for a topic, plus the subject-wide ones — what you want when
     * asking "what should we use to teach this?".
     */
    public function resourcesForTopic(string $slug, string $ref): array
    {
        return $this->all(
            "SELECT * FROM resources WHERE subject_slug = ? AND ref IN (?, '')
             ORDER BY CASE WHEN ref = '' THEN 1 ELSE 0 END, sort_order, id",
            [$slug, $ref]
        );
    }

    /** Every topic ref in a subject that has at least one resource. */
    public function refsWithResources(string $slug): array
    {
        $rows = $this->all(
            "SELECT DISTINCT ref FROM resources WHERE subject_slug = ? AND ref <> ''",
            [$slug]
        );
        return array_column($rows, 'ref');
    }

    /** Adding the same title against the same topic twice updates it. */
    public function upsertResource(array $r): void
    {
        $st = $this->db->prepare(
            'INSERT INTO resources (subject_slug, ref, title, url, kind, note, sort_order)
             VALUES (:subject_slug, :ref, :title, :url, :kind, :note, :sort_order)
             ON CONFLICT(subject_slug, ref, title) DO UPDATE SET
               url = excluded.url,
               kind = excluded.kind,
               note = COALESCE(excluded.note, resources.note),
               sort_order = excluded.sort_order'
        );
        $st->execute([
            ':subject_slug' => $r['subject_slug'],
            ':ref'          => $r['ref'] ?? '',
            ':title'        => $r['title'],
            ':url'          => $r['url'] ?? null,
            ':kind'         => $r['kind'] ?? 'other',
            ':note'         => $r['note'] ?? null,
            ':sort_order'   => $r['sort_order'] ?? 0,
        ]);
    }

    /** Returns how many rows went. */
    public function deleteResource(string $slug, string $ref, string $title): int
    {
        $st = $this->db->prepare(
            'DELETE FROM resources WHERE subject_slug = ? AND ref = ? AND title = ?'
        );
        $st->execute([$slug, $ref, $title]);
        return $st->rowCount();
    }

    public function addSession(array $s): int
    {
        $st = $this->db->prepare(
            'INSERT INTO sessions
               (subject_slug, date, summary, topics_touched, next_steps, block_key, duration_minutes)
             VALUES (:subject_slug, :date, :summary, :topics_touched, :next_steps,
                     :block_key, :duration_minutes)'
        );
        $st->execute([
            ':subject_slug'     => $s['subject_slug'],
            ':date'             => $s['date'],
            ':summary'          => $s['summary'],
            ':topics_touched'   => $s['topics_touched'] ?? null,
            ':next_steps'       => $s['next_steps'] ?? null,
            ':block_key'        => $s['block_key'] ?? null,
            ':duration_minutes' => $s['duration_minutes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    // ---- practice --------------------------------------------------------
    //
    // A practice run is one bounded stretch of practice: one Shooting Gallery
    // game, one tutoring session. It is deliberately NOT an attempt — attempts
    // are marked papers that carry a grade and drive projections, and practice
    // arrives dozens per week with no mark scheme. Mixing them would drown the
    // mock history.

    /** The declared source registry. A source may be subject-scoped or global. */
    public function listPracticeSources(?string $slug = null): array
    {
        if ($slug === null) {
            return $this->all('SELECT * FROM practice_source ORDER BY key');
        }
        return $this->all(
            'SELECT * FROM practice_source WHERE subject_slug IS NULL OR subject_slug = ? ORDER BY key',
            [$slug]
        );
    }

    public function practiceSource(string $key): ?array
    {
        return $this->one('SELECT * FROM practice_source WHERE key = ?', [$key]);
    }

    public function upsertPracticeSource(array $s): void
    {
        $st = $this->db->prepare(
            'INSERT INTO practice_source (key, display_name, subject_slug, metrics_schema)
             VALUES (:key, :display_name, :subject_slug, :metrics_schema)
             ON CONFLICT(key) DO UPDATE SET
               display_name = excluded.display_name,
               subject_slug = excluded.subject_slug,
               metrics_schema = excluded.metrics_schema'
        );
        $st->execute([
            ':key'            => $s['key'],
            ':display_name'   => $s['display_name'],
            ':subject_slug'   => $s['subject_slug'] ?? null,
            ':metrics_schema' => json_encode((object) ($s['metrics_schema'] ?? [])),
        ]);
    }

    /**
     * Store one run, its items and its run-level topic refs.
     *
     * A duplicate client_run_id is a silent no-op returning the existing row,
     * never an error and never a second row: the Spanish app retries failed
     * reports and a model-driven tool call can fire twice, and one retry would
     * otherwise put a phantom spike in the trend line.
     *
     * @return array{status:'stored'|'duplicate', id:int}
     */
    public function addPracticeRun(array $r): array
    {
        $slug     = (string) $r['subject_slug'];
        $clientId = (string) $r['client_run_id'];

        $existing = $this->one(
            'SELECT id FROM practice_run WHERE subject_slug = ? AND client_run_id = ?',
            [$slug, $clientId]
        );
        if ($existing) {
            return ['status' => 'duplicate', 'id' => (int) $existing['id']];
        }

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare(
                'INSERT INTO practice_run
                   (subject_slug, client_run_id, source, label, played_at, attempted, correct,
                    correct_after_retry, incorrect, duration_seconds, metrics, block_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $slug, $clientId, $r['source'], $r['label'],
                $r['played_at'] ?? gmdate('Y-m-d H:i:s'),
                (int) $r['attempted'], (int) $r['correct'],
                (int) ($r['correct_after_retry'] ?? 0), (int) $r['incorrect'],
                $r['duration_seconds'] ?? null,
                json_encode((object) ($r['metrics'] ?? [])),
                $r['block_key'] ?? null,
            ]);
            $runId = (int) $this->db->lastInsertId();

            foreach (array_values($r['items'] ?? []) as $i => $item) {
                $st = $this->db->prepare(
                    'INSERT INTO practice_item
                       (practice_run_id, position, prompt, topic_ref, outcome, attempts_taken, note)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $runId,
                    $item['position'] ?? $i,
                    $item['prompt'] ?? null,
                    $item['topic_ref'] ?? null,
                    $item['outcome'],
                    $item['attempts_taken'] ?? null,
                    $item['note'] ?? null,
                ]);
            }

            foreach (array_unique($r['topic_refs'] ?? []) as $ref) {
                $st = $this->db->prepare(
                    'INSERT OR IGNORE INTO practice_run_topic (practice_run_id, topic_ref) VALUES (?, ?)'
                );
                $st->execute([$runId, $ref]);
            }

            $this->db->commit();
            return ['status' => 'stored', 'id' => $runId];
        } catch (Throwable $e) {
            $this->db->rollBack();
            // Two calls racing on the same client_run_id: the unique index is
            // what actually decides, and the loser reports the winner's row
            // rather than an error the client would retry forever.
            $row = $this->one(
                'SELECT id FROM practice_run WHERE subject_slug = ? AND client_run_id = ?',
                [$slug, $clientId]
            );
            if ($row) {
                return ['status' => 'duplicate', 'id' => (int) $row['id']];
            }
            throw $e;
        }
    }

    /**
     * Runs newest first.
     *
     * @param array{source?:string,since?:string,until?:string,ref?:string,
     *              limit?:int,include_void?:bool} $filter
     */
    public function listPracticeRuns(string $slug, array $filter = []): array
    {
        $sql    = 'SELECT * FROM practice_run WHERE subject_slug = ?';
        $params = [$slug];
        if (!empty($filter['source'])) {
            $sql .= ' AND source = ?';
            $params[] = $filter['source'];
        }
        if (!empty($filter['since'])) {
            $sql .= ' AND date(played_at) >= ?';
            $params[] = $filter['since'];
        }
        if (!empty($filter['until'])) {
            $sql .= ' AND date(played_at) <= ?';
            $params[] = $filter['until'];
        }
        if (!empty($filter['ref'])) {
            // A run "touches" a topic through either route: a per-item ref or a
            // run-level one.
            $sql .= ' AND (id IN (SELECT practice_run_id FROM practice_item WHERE topic_ref = ?)
                        OR id IN (SELECT practice_run_id FROM practice_run_topic WHERE topic_ref = ?))';
            $params[] = $filter['ref'];
            $params[] = $filter['ref'];
        }
        if (empty($filter['include_void'])) {
            $sql .= ' AND void_reason IS NULL';
        }
        $sql .= ' ORDER BY played_at DESC, id DESC';
        if (!empty($filter['limit'])) {
            $sql .= ' LIMIT ' . (int) $filter['limit'];
        }
        $rows = $this->all($sql, $params);
        foreach ($rows as &$row) {
            $row['metrics'] = json_decode((string) ($row['metrics'] ?: '{}'), true) ?: [];
        }
        return $rows;
    }

    public function getPracticeRun(string $slug, int $id): ?array
    {
        $row = $this->one('SELECT * FROM practice_run WHERE subject_slug = ? AND id = ?', [$slug, $id]);
        if (!$row) {
            return null;
        }
        $row['metrics'] = json_decode((string) ($row['metrics'] ?: '{}'), true) ?: [];
        $row['items']   = $this->practiceItems($id);
        $row['topics']  = array_column($this->practiceRunTopics($id), 'topic_ref');
        return $row;
    }

    public function practiceItems(int $runId): array
    {
        return $this->all(
            'SELECT * FROM practice_item WHERE practice_run_id = ? ORDER BY position, id',
            [$runId]
        );
    }

    public function practiceRunTopics(int $runId): array
    {
        return $this->all(
            'SELECT * FROM practice_run_topic WHERE practice_run_id = ? ORDER BY topic_ref',
            [$runId]
        );
    }

    /** Non-null reason excludes the run from every statistic; the row stays. */
    public function voidPracticeRun(string $slug, int $id, ?string $reason): bool
    {
        $st = $this->db->prepare(
            'UPDATE practice_run SET void_reason = ? WHERE subject_slug = ? AND id = ?'
        );
        $st->execute([$reason, $slug, $id]);
        return $st->rowCount() > 0;
    }

    /**
     * Per-topic practice counts.
     *
     * The rollup rule, in one place because two places would eventually
     * disagree: when a run has items carrying topic refs, roll up from the
     * items; otherwise apportion the run's totals evenly across its run-level
     * refs. Never both for the same run, or the counts double.
     *
     * @param array $filter as listPracticeRuns
     * @return array<string,array{ref:string,runs:int,attempted:float,correct:float,
     *                            retry:float,incorrect:float}>
     */
    public function practiceTopicRollup(string $slug, array $filter = []): array
    {
        $runs = $this->listPracticeRuns($slug, $filter);
        if (!$runs) {
            return [];
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $runs);
        $in  = implode(',', $ids);

        $itemsByRun = [];
        foreach ($this->all(
            "SELECT practice_run_id, topic_ref, outcome, COUNT(*) AS n
             FROM practice_item
             WHERE practice_run_id IN ($in) AND topic_ref IS NOT NULL AND topic_ref <> ''
             GROUP BY practice_run_id, topic_ref, outcome"
        ) as $row) {
            $itemsByRun[(int) $row['practice_run_id']][] = $row;
        }

        $refsByRun = [];
        foreach ($this->all("SELECT * FROM practice_run_topic WHERE practice_run_id IN ($in)") as $row) {
            $refsByRun[(int) $row['practice_run_id']][] = (string) $row['topic_ref'];
        }

        $out  = [];
        $bump = static function (array &$out, string $ref): void {
            if (!isset($out[$ref])) {
                $out[$ref] = ['ref' => $ref, 'runs' => 0, 'attempted' => 0.0,
                              'correct' => 0.0, 'retry' => 0.0, 'incorrect' => 0.0];
            }
        };

        foreach ($runs as $run) {
            $id = (int) $run['id'];

            if (!empty($itemsByRun[$id])) {
                $seen = [];
                foreach ($itemsByRun[$id] as $row) {
                    $ref = (string) $row['topic_ref'];
                    $n   = (float) $row['n'];
                    $bump($out, $ref);
                    if (!isset($seen[$ref])) {
                        $out[$ref]['runs']++;
                        $seen[$ref] = true;
                    }
                    $out[$ref]['attempted'] += $n;
                    $key = match ((string) $row['outcome']) {
                        'correct'   => 'correct',
                        'retry'     => 'retry',
                        default     => 'incorrect',
                    };
                    $out[$ref][$key] += $n;
                }
                continue;
            }

            $refs = $refsByRun[$id] ?? [];
            if (!$refs) {
                continue;
            }
            $share = 1 / count($refs);
            foreach ($refs as $ref) {
                $bump($out, $ref);
                $out[$ref]['runs']++;
                $out[$ref]['attempted'] += (float) $run['attempted'] * $share;
                $out[$ref]['correct']   += (float) $run['correct'] * $share;
                $out[$ref]['retry']     += (float) $run['correct_after_retry'] * $share;
                $out[$ref]['incorrect'] += (float) $run['incorrect'] * $share;
            }
        }

        ksort($out);
        return $out;
    }

    // ---- scoreboard configuration ---------------------------------------

    /**
     * The current configuration, or null if the subject has never had one
     * stored. Rows are appended rather than replaced, so a bad edit can be
     * read back out of the history.
     */
    public function getScoreboard(string $slug): ?array
    {
        $row = $this->one(
            'SELECT * FROM scoreboard_config WHERE subject_slug = ? ORDER BY id DESC LIMIT 1',
            [$slug]
        );
        if (!$row) {
            return null;
        }
        $config = json_decode((string) $row['config'], true);
        return is_array($config) ? $config : null;
    }

    public function setScoreboard(string $slug, array $config, ?string $note = null): int
    {
        $st = $this->db->prepare(
            'INSERT INTO scoreboard_config (subject_slug, config, note) VALUES (?, ?, ?)'
        );
        $st->execute([$slug, json_encode($config, JSON_UNESCAPED_SLASHES), $note]);
        return (int) $this->db->lastInsertId();
    }

    // ---- timetable -------------------------------------------------------
    //
    // The board is derived, never declared. A study block is done because a
    // session, attempt or practice run exists for one of its subjects on its
    // date — not because anyone ticked it. That is the whole design: it makes
    // the board honest, and it makes "she said she did it" unrepresentable.

    /** The version in force on a date: the greatest valid_from <= date. */
    public function timetableVersionOn(?string $date = null): ?array
    {
        $date = $date ?: tt_today();
        return $this->one(
            'SELECT * FROM timetable_versions WHERE valid_from <= ?
             ORDER BY valid_from DESC, id DESC LIMIT 1',
            [$date]
        );
    }

    /** @return array<int,array<string,mixed>> blocks of a version, decoded, in order. */
    public function timetableBlocks(int $versionId): array
    {
        $rows = $this->all(
            'SELECT * FROM timetable_blocks WHERE version_id = ? ORDER BY weekday, sort, start',
            [$versionId]
        );
        foreach ($rows as &$r) {
            $r['block_key'] = (int) $r['block_key'];
            $r['weekday']   = (int) $r['weekday'];
            $r['sort']      = (int) $r['sort'];
            $r['subjects']  = json_decode($r['subjects_json'] ?? '[]', true) ?: [];
            $r['alternate'] = $r['alternate_json'] ? (json_decode($r['alternate_json'], true) ?: null) : null;
        }
        return $rows;
    }

    /**
     * Replace the whole timetable from valid_from, as a new version.
     *
     * Validated before anything is written: end after start, no two blocks
     * overlapping on a weekday, every subject slug real, every block_key used
     * once. A timetable that fails any of these would produce a board that
     * cannot be judged, so it is refused rather than stored and worked around.
     *
     * @return array{version_id:int,diff:array<string,array<int,string>>,blocks:int}
     */
    public function setTimetable(array $blocks, string $validFrom, ?string $note = null): array
    {
        if (!$blocks) {
            throw new InvalidArgumentException('A timetable needs at least one block.');
        }
        $known = [];
        foreach ($this->listSubjects() as $s) {
            $known[$s['slug']] = true;
        }

        $seenKeys = [];
        $byDay    = [];
        $clean    = [];
        foreach ($blocks as $i => $b) {
            if (!is_array($b)) {
                throw new InvalidArgumentException('Each block must be an object.');
            }
            $key = (int) ($b['block_key'] ?? $b['id'] ?? 0);
            if ($key < 1) {
                throw new InvalidArgumentException("Block #$i has no block_key (the seed's `id`).");
            }
            if (isset($seenKeys[$key])) {
                throw new InvalidArgumentException("block_key $key is used twice; each block needs its own.");
            }
            $seenKeys[$key] = true;

            $weekday = (int) ($b['weekday'] ?? 0);
            if ($weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException("Block $key has weekday '$weekday'; it must be 1 (Monday) to 7.");
            }
            $start = tt_hhmm($b['start'] ?? '', "Block $key start");
            $end   = tt_hhmm($b['end'] ?? '', "Block $key end");
            if (tt_mins($end) <= tt_mins($start)) {
                throw new InvalidArgumentException("Block $key ends at $end, which is not after its start $start.");
            }
            $kind = (string) ($b['kind'] ?? '');
            if (!in_array($kind, TIMETABLE_KINDS, true)) {
                throw new InvalidArgumentException(
                    "Block $key has kind '$kind'. Known kinds: " . implode(', ', TIMETABLE_KINDS) . '.'
                );
            }
            $tracking = (string) ($b['tracking'] ?? '');
            if (!in_array($tracking, TIMETABLE_TRACKING, true)) {
                throw new InvalidArgumentException(
                    "Block $key has tracking '$tracking'. It must be evidence, self_report or none."
                );
            }
            $label = trim((string) ($b['label'] ?? ''));
            if ($label === '') {
                throw new InvalidArgumentException("Block $key has no label.");
            }

            $subjects = $b['subjects'] ?? [];
            if (!is_array($subjects)) {
                throw new InvalidArgumentException("Block $key: subjects must be a list of slugs.");
            }
            foreach ($subjects as $slug) {
                if (!isset($known[$slug])) {
                    throw new InvalidArgumentException(
                        "Block $key names subject '$slug', which is not a tracked subject. "
                        . 'Known slugs: ' . implode(', ', array_keys($known)) . '.'
                    );
                }
            }
            $alternate = $b['alternate'] ?? null;
            if ($alternate !== null) {
                if (!is_array($alternate) || !isset($alternate['odd'], $alternate['even'])) {
                    throw new InvalidArgumentException("Block $key: alternate needs both `odd` and `even`.");
                }
                foreach (['odd', 'even'] as $parity) {
                    if (!is_array($alternate[$parity]) || !$alternate[$parity]) {
                        throw new InvalidArgumentException("Block $key: alternate.$parity must be a non-empty list.");
                    }
                    foreach ($alternate[$parity] as $slug) {
                        if (!isset($known[$slug])) {
                            throw new InvalidArgumentException(
                                "Block $key: alternate.$parity names '$slug', which is not a tracked subject."
                            );
                        }
                    }
                }
            }

            // Overlap is checked per weekday against everything already
            // accepted, so the message can name both blocks rather than just
            // saying the timetable is invalid.
            foreach ($byDay[$weekday] ?? [] as $other) {
                if (tt_mins($start) < tt_mins($other['end']) && tt_mins($other['start']) < tt_mins($end)) {
                    throw new InvalidArgumentException(
                        'Blocks ' . $other['block_key'] . " ({$other['start']}–{$other['end']}) and $key "
                        . "({$start}\u{2013}{$end}) overlap on " . TIMETABLE_DAYS[$weekday] . '.'
                    );
                }
            }

            $row = [
                'block_key' => $key,
                'weekday'   => $weekday,
                'start'     => $start,
                'end'       => $end,
                'kind'      => $kind,
                'label'     => $label,
                'note'      => isset($b['note']) && $b['note'] !== '' ? (string) $b['note'] : null,
                'subjects'  => array_values($subjects),
                'alternate' => $alternate,
                'tracking'  => $tracking,
                'sort'      => array_key_exists('sort', $b) ? (int) $b['sort'] : tt_mins($start),
            ];
            $byDay[$weekday][] = $row;
            $clean[] = $row;
        }

        $previous = $this->timetableVersionOn($validFrom);
        $before   = $previous ? $this->timetableBlocks((int) $previous['id']) : [];

        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $st = $this->db->prepare(
                'INSERT INTO timetable_versions (valid_from, note) VALUES (?, ?)'
            );
            $st->execute([$validFrom, $note]);
            $versionId = (int) $this->db->lastInsertId();

            $ins = $this->db->prepare(
                'INSERT INTO timetable_blocks
                   (version_id, block_key, weekday, start, end, kind, label, note,
                    subjects_json, alternate_json, tracking, sort)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($clean as $r) {
                $ins->execute([
                    $versionId, $r['block_key'], $r['weekday'], $r['start'], $r['end'],
                    $r['kind'], $r['label'], $r['note'],
                    json_encode($r['subjects'], JSON_UNESCAPED_SLASHES),
                    $r['alternate'] === null ? null : json_encode($r['alternate'], JSON_UNESCAPED_SLASHES),
                    $r['tracking'], $r['sort'],
                ]);
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }

        return [
            'version_id' => $versionId,
            'blocks'     => count($clean),
            'diff'       => tt_diff($before, $clean),
        ];
    }

    // ---- days off ---------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function listDaysOff(?string $from = null, ?string $to = null, ?string $status = null): array
    {
        $sql    = 'SELECT * FROM days_off WHERE 1 = 1';
        $params = [];
        if ($from !== null) {
            $sql .= ' AND date_to >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND date_from <= ?';
            $params[] = $to;
        }
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        return $this->all($sql . ' ORDER BY date_from, id', $params);
    }

    /** The day off covering a date, if one is approved or still requested. */
    public function dayOffCovering(string $date): ?array
    {
        return $this->one(
            "SELECT * FROM days_off
             WHERE status != 'declined' AND date_from <= ? AND ? <= date_to
             ORDER BY id DESC LIMIT 1",
            [$date, $date]
        );
    }

    public function getDayOff(int $id): ?array
    {
        return $this->one('SELECT * FROM days_off WHERE id = ?', [$id]);
    }

    public function addDayOff(array $d): array
    {
        $status = $d['requested_by'] === 'parent' ? 'approved' : 'requested';
        $st = $this->db->prepare(
            'INSERT INTO days_off (date_from, date_to, kind, reason, requested_by, status, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $d['date_from'], $d['date_to'], $d['kind'] ?? 'day_off', $d['reason'],
            $d['requested_by'], $status,
            $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        return $this->getDayOff((int) $this->db->lastInsertId());
    }

    /** approve / decline / unapprove. The record is never deleted. */
    public function decideDayOff(int $id, string $decision, ?string $note = null): ?array
    {
        $row = $this->getDayOff($id);
        if (!$row) {
            return null;
        }
        $status = match ($decision) {
            'approve'   => 'approved',
            'decline'   => 'declined',
            'unapprove' => 'requested',
            default     => throw new InvalidArgumentException("Unknown decision '$decision'."),
        };
        $st = $this->db->prepare(
            'UPDATE days_off SET status = ?, decided_at = ?, decision_note = ? WHERE id = ?'
        );
        $st->execute([$status, gmdate('Y-m-d H:i:s'), $note, $id]);
        return $this->getDayOff($id);
    }

    // ---- per-date overrides ------------------------------------------------

    /** A null reason un-excuses: the row goes and the block reverts to missed. */
    public function setExcusal(string $date, int $blockKey, ?string $reason): ?array
    {
        if ($reason === null) {
            $st = $this->db->prepare('DELETE FROM timetable_excusals WHERE date = ? AND block_key = ?');
            $st->execute([$date, $blockKey]);
            return null;
        }
        $st = $this->db->prepare(
            'INSERT INTO timetable_excusals (date, block_key, reason) VALUES (?, ?, ?)
             ON CONFLICT(date, block_key) DO UPDATE SET reason = excluded.reason'
        );
        $st->execute([$date, $blockKey, $reason]);
        return $this->one(
            'SELECT * FROM timetable_excusals WHERE date = ? AND block_key = ?',
            [$date, $blockKey]
        );
    }

    public function setTick(string $date, int $blockKey, string $by, ?string $note = null): array
    {
        $st = $this->db->prepare(
            'INSERT INTO timetable_ticks (date, block_key, by, note) VALUES (?, ?, ?, ?)
             ON CONFLICT(date, block_key) DO UPDATE SET by = excluded.by, note = excluded.note'
        );
        $st->execute([$date, $blockKey, $by, $note]);
        return $this->one(
            'SELECT * FROM timetable_ticks WHERE date = ? AND block_key = ?',
            [$date, $blockKey]
        );
    }

    public function clearTick(string $date, int $blockKey): void
    {
        $st = $this->db->prepare('DELETE FROM timetable_ticks WHERE date = ? AND block_key = ?');
        $st->execute([$date, $blockKey]);
    }

    // ---- judging ----------------------------------------------------------

    /**
     * Judge every block of a week, Monday to Sunday.
     *
     * One pass, because the binding is greedy across the whole day: two maths
     * blocks on one day must not both be satisfied by one session, so the
     * earliest record claims the earliest block that will take it and later
     * blocks see a smaller pool.
     *
     * @return array{week:string,days:array<int,array<string,mixed>>,
     *               counts:array<string,int>,hours_by_subject:array<string,float>}
     */
    public function judgeWeek(string $dateInWeek): array
    {
        $monday = tt_monday($dateInWeek);
        $dates  = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = tt_add_days($monday, $i);
        }
        $days = $this->judgeDates($dates);

        $counts = [
            'done' => 0, 'short' => 0, 'missed' => 0, 'excused' => 0, 'day_off' => 0,
            'now' => 0, 'pending' => 0, 'upcoming' => 0, 'optional' => 0,
            'declared' => 0, 'extra' => 0, 'judged' => 0,
        ];
        $hours = [];
        foreach ($days as $day) {
            foreach ($day['blocks'] as $b) {
                if ($b['status'] === 'n/a') {
                    continue;
                }
                $counts['judged']++;
                $counts[$b['status']] = ($counts[$b['status']] ?? 0) + 1;
                if (!empty($b['short'])) {
                    $counts['short']++;
                }
                if ($b['status'] === 'done' && $b['subject'] !== null) {
                    $hours[$b['subject']] = ($hours[$b['subject']] ?? 0) + $b['minutes'];
                }
            }
            foreach ($day['extras'] as $e) {
                $counts['extra']++;
                $hours[$e['subject']] = ($hours[$e['subject']] ?? 0) + ($e['minutes'] ?? 0);
            }
        }
        foreach ($hours as $slug => $mins) {
            $hours[$slug] = round($mins / 60, 2);
        }
        ksort($hours);

        return [
            'week'             => tt_iso_week($monday),
            'monday'           => $monday,
            'days'             => $days,
            'counts'           => $counts,
            'hours_by_subject' => $hours,
        ];
    }

    /** One day, same shape as an entry of judgeWeek()['days']. */
    public function judgeDay(string $date): array
    {
        return $this->judgeDates([$date])[0];
    }

    /**
     * @param  array<int,string> $dates
     * @return array<int,array<string,mixed>>
     */
    private function judgeDates(array $dates): array
    {
        $from = $dates[0];
        $to   = $dates[count($dates) - 1];

        $today  = tt_today();
        $nowMin = tt_mins(tt_now()->format('H:i'));

        $evidence = $this->evidenceBetween($from, $to);
        $excusals = [];
        foreach ($this->all(
            'SELECT * FROM timetable_excusals WHERE date BETWEEN ? AND ?', [$from, $to]
        ) as $r) {
            $excusals[$r['date'] . '/' . (int) $r['block_key']] = $r;
        }
        $ticks = [];
        foreach ($this->all(
            'SELECT * FROM timetable_ticks WHERE date BETWEEN ? AND ?', [$from, $to]
        ) as $r) {
            $ticks[$r['date'] . '/' . (int) $r['block_key']] = $r;
        }
        $daysOff = $this->listDaysOff($from, $to);

        $versionCache = [];
        $out = [];
        foreach ($dates as $date) {
            $weekday = (int) (new DateTimeImmutable($date, tt_zone()))->format('N');

            // Resolved per date, not per week: a re-cut that starts on the
            // Wednesday must take effect on the Wednesday.
            if (!array_key_exists($date, $versionCache)) {
                $v = $this->timetableVersionOn($date);
                $versionCache[$date] = $v ? $this->timetableBlocks((int) $v['id']) : [];
            }
            $blocks = array_values(array_filter(
                $versionCache[$date],
                static fn(array $b): bool => $b['weekday'] === $weekday
            ));

            // The day off that covers this date, if any. A declined one does
            // not render at all; a requested one shows but does not excuse.
            $off = null;
            foreach ($daysOff as $d) {
                if ($d['status'] !== 'declined' && $d['date_from'] <= $date && $date <= $d['date_to']) {
                    $off = $d;
                    break;
                }
            }
            $approvedOff = $off !== null && $off['status'] === 'approved';

            $pool = $evidence[$date] ?? [];
            $claimed = [];
            $bound   = [];

            // Pass 1 — a record carrying block_key belongs to that block and
            // to no other. This is what stops work being re-labelled onto the
            // wrong block by the greedy pass below.
            foreach ($pool as $i => $e) {
                if ($e['block_key'] === null) {
                    continue;
                }
                foreach ($blocks as $b) {
                    if ($b['block_key'] === $e['block_key'] && !isset($bound[$b['block_key']])) {
                        $bound[$b['block_key']] = $e;
                        $claimed[$i] = true;
                        break;
                    }
                }
            }
            // Pass 2 — greedy: the earliest unclaimed record fills the
            // earliest block it matches.
            foreach ($blocks as $b) {
                if ($b['tracking'] !== 'evidence' || isset($bound[$b['block_key']])) {
                    continue;
                }
                $subjects = tt_subjects_for($b, $date);
                foreach ($pool as $i => $e) {
                    if (isset($claimed[$i]) || !in_array($e['subject'], $subjects, true)) {
                        continue;
                    }
                    if (!tt_kind_accepts($b, $e)) {
                        continue;
                    }
                    $bound[$b['block_key']] = $e;
                    $claimed[$i] = true;
                    break;
                }
            }

            $rows = [];
            foreach ($blocks as $b) {
                $key      = $b['block_key'];
                $subjects = tt_subjects_for($b, $date);
                $length   = tt_mins($b['end']) - tt_mins($b['start']);
                $row = [
                    'block_key' => $key,
                    'start'     => $b['start'],
                    'end'       => $b['end'],
                    'label'     => $b['label'],
                    'kind'      => $b['kind'],
                    'note'      => $b['note'],
                    'subjects'  => $subjects,
                    'tracking'  => $b['tracking'],
                    'status'    => 'upcoming',
                    'short'     => false,
                    'minutes'   => $length,
                    'length'    => $length,
                    'subject'   => count($subjects) === 1 ? $subjects[0] : null,
                    'evidence'  => [],
                    'reason'    => null,
                ];

                // Precedence, highest first. An excusal outranks a day off so
                // that a reason the parent actually wrote is the one shown.
                $ex = $excusals[$date . '/' . $key] ?? null;
                if ($ex) {
                    $row['status'] = 'excused';
                    $row['reason'] = $ex['reason'];
                    $rows[] = $row;
                    continue;
                }
                if ($b['tracking'] === 'none') {
                    $row['status'] = 'n/a';
                    $rows[] = $row;
                    continue;
                }
                if ($approvedOff) {
                    $row['status'] = 'day_off';
                    $row['reason'] = $off['reason'];
                    $rows[] = $row;
                    continue;
                }

                if ($b['tracking'] === 'self_report') {
                    $tick = $ticks[$date . '/' . $key] ?? null;
                    if ($tick) {
                        $row['status']   = 'done';
                        $row['evidence'] = [['type' => 'tick', 'id' => (int) $tick['id'], 'by' => $tick['by']]];
                        $rows[] = $row;
                        continue;
                    }
                    // A self-reported block is never marked missed. There is no
                    // evidence to derive from, so "not ticked" and "did not
                    // happen" are different things and the board must not
                    // conflate them — least of all for the movement blocks,
                    // which are hers to take and not work to be judged on.
                    // Ticking still counts towards done; not ticking costs
                    // nothing.
                    if (!($date === $today
                          && $nowMin >= tt_mins($b['start']) && $nowMin < tt_mins($b['end']))) {
                        $row['status'] = $date > $today ? 'upcoming' : 'optional';
                        $rows[] = $row;
                        continue;
                    }
                } elseif (isset($bound[$key])) {
                    $e = $bound[$key];
                    $row['status']   = 'done';
                    $row['subject']  = $e['subject'];
                    $row['evidence'] = [['type' => $e['type'], 'id' => $e['id'], 'label' => $e['label']]];
                    if ($e['minutes'] !== null && $e['minutes'] < $length / 2) {
                        $row['short']   = true;
                        $row['minutes'] = $e['minutes'];
                    }
                    $rows[] = $row;
                    continue;
                } else {
                    // The parent can say a study block happened when the work
                    // itself was never logged — she read the set text on the
                    // sofa, she did the maths at her grandmother's. That is a
                    // real thing and the board should carry it, but it is an
                    // assertion, not evidence, so it gets its own status and
                    // its own mark. Evidence, where it exists, always wins:
                    // this branch is only reached when nothing bound.
                    $tick = $ticks[$date . '/' . $key] ?? null;
                    if ($tick && $tick['by'] === 'parent') {
                        $row['status']   = 'declared';
                        $row['reason']   = $tick['note'] ?: null;
                        $row['evidence'] = [['type' => 'tick', 'id' => (int) $tick['id'],
                                             'by' => $tick['by']]];
                        $rows[] = $row;
                        continue;
                    }
                }

                // Nothing logged and nothing excusing it, so the clock decides.
                if ($date < $today) {
                    $row['status'] = 'missed';
                } elseif ($date > $today) {
                    $row['status'] = 'upcoming';
                } elseif ($nowMin < tt_mins($b['start'])) {
                    $row['status'] = 'pending';
                } elseif ($nowMin < tt_mins($b['end'])) {
                    $row['status'] = 'now';
                } else {
                    $row['status'] = 'missed';
                }
                $rows[] = $row;
            }

            $extras = [];
            foreach ($pool as $i => $e) {
                if (isset($claimed[$i])) {
                    continue;
                }
                $extras[] = [
                    'type' => $e['type'], 'id' => $e['id'], 'subject' => $e['subject'],
                    'label' => $e['label'], 'at' => $e['at'], 'minutes' => $e['minutes'] ?? 0,
                ];
            }

            $out[] = [
                'date'     => $date,
                'weekday'  => $weekday,
                'day_name' => TIMETABLE_DAYS[$weekday],
                'is_today' => $date === $today,
                'day_off'  => $off,
                'blocks'   => $rows,
                'extras'   => $extras,
            ];
        }
        return $out;
    }

    /**
     * Every candidate record in a date range, grouped by local date and sorted
     * earliest first — that ordering is what makes the greedy binding
     * deterministic.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function evidenceBetween(string $from, string $to): array
    {
        $rows = [];

        foreach ($this->all(
            'SELECT id, subject_slug, date, summary, block_key, duration_minutes, created_at
             FROM sessions
             WHERE date BETWEEN ? AND ? AND void_reason IS NULL',
            [$from, $to]
        ) as $r) {
            // A session carries a date but no time, so its creation time
            // orders it within the day. A session logged at 23:50 for a 09:45
            // block still counts: the date is what binds, not the clock.
            [$d, $t] = tt_local((string) $r['created_at']);
            $rows[] = [
                'type' => 'session', 'id' => (int) $r['id'], 'subject' => $r['subject_slug'],
                'date' => $r['date'], 'at' => $d === $r['date'] ? $t : '00:00',
                'label' => (string) $r['summary'],
                'minutes' => $r['duration_minutes'] === null ? null : (int) $r['duration_minutes'],
                'block_key' => $r['block_key'] === null ? null : (int) $r['block_key'],
                'source' => null,
            ];
        }

        // A paper's own sat_on wins; without one it was sat the day of the
        // attempt.
        foreach ($this->all(
            "SELECT p.id, p.code, p.block_key, COALESCE(p.sat_on, a.date) AS on_date,
                    a.subject_slug, a.name, a.created_at
             FROM attempt_papers p JOIN attempts a ON a.id = p.attempt_id
             WHERE COALESCE(p.sat_on, a.date) BETWEEN ? AND ?",
            [$from, $to]
        ) as $r) {
            [, $t] = tt_local((string) $r['created_at']);
            $rows[] = [
                'type' => 'attempt', 'id' => (int) $r['id'], 'subject' => $r['subject_slug'],
                'date' => $r['on_date'], 'at' => $t,
                'label' => trim($r['name'] . ' — ' . $r['code']),
                'minutes' => null,
                'block_key' => $r['block_key'] === null ? null : (int) $r['block_key'],
                'source' => null,
            ];
        }

        // played_at is UTC, so the range is widened by a day at each end and
        // the local date decided in PHP. Around a clock change that is the
        // difference between a block being done and being missed.
        foreach ($this->all(
            'SELECT id, subject_slug, source, label, played_at, duration_seconds, block_key
             FROM practice_run
             WHERE void_reason IS NULL AND played_at BETWEEN ? AND ?',
            [tt_add_days($from, -1) . ' 00:00:00', tt_add_days($to, 1) . ' 23:59:59']
        ) as $r) {
            [$d, $t] = tt_local((string) $r['played_at']);
            if ($d < $from || $d > $to) {
                continue;
            }
            $rows[] = [
                'type' => 'practice', 'id' => (int) $r['id'], 'subject' => $r['subject_slug'],
                'date' => $d, 'at' => $t, 'label' => (string) $r['label'],
                'minutes' => $r['duration_seconds'] === null
                    ? null : (int) round(((int) $r['duration_seconds']) / 60),
                'block_key' => $r['block_key'] === null ? null : (int) $r['block_key'],
                'source' => (string) $r['source'],
            ];
        }

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['date']][] = $r;
        }
        foreach ($byDate as &$list) {
            usort($list, static function (array $x, array $y): int {
                return [$x['at'], $x['type'], $x['id']] <=> [$y['at'], $y['type'], $y['id']];
            });
        }
        return $byDate;
    }

}

/** Convert a raw score to a grade using the subject's stored boundaries. */
function gradeFor(array $subject, float $score, float $max, string $tier): string
{
    $table = $subject['boundaries'][$tier] ?? null;
    if (!$table || !$max) {
        return '—';
    }
    $scaled = ($score / $max) * $subject['boundary_max'];
    $sorted = $table;
    usort($sorted, static fn($a, $b) => $b[1] <=> $a[1]);
    foreach ($sorted as $i => [$grade, $boundary]) {
        if ($scaled >= $boundary) {
            return $i === 0 ? "{$grade}+" : (string) $grade;
        }
    }
    return 'below ' . $sorted[count($sorted) - 1][0];
}

/** Whole weeks since an ISO date, or null if never touched. */
function weeksSince(?string $iso): ?int
{
    if (!$iso) {
        return null;
    }
    $then = strtotime($iso . 'T12:00:00Z');
    if ($then === false) {
        return null;
    }
    return (int) floor((time() - $then) / (7 * 86400));
}

/** Weighted percentage of the specification covered, plus per-status counts. */
function progressFor(Store $store, string $slug): array
{
    $topics = $store->listTopics($slug);
    $points = 0;
    $counts = [];
    foreach ($topics as $t) {
        $points += STATUS_POINTS[$t['status']] ?? 0;
        $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
    }
    $pct = $topics ? (int) round(($points / (count($topics) * 3)) * 100) : 0;
    return ['topics' => $topics, 'pct' => $pct, 'counts' => $counts];
}
