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
// so they have to be loaded before a Store is ever constructed. The shape
// rules and the retrieval scheduler are seeded by migration too.
require_once __DIR__ . '/practice.php';
require_once __DIR__ . '/shape.php';
require_once __DIR__ . '/retrieval.php';
require_once __DIR__ . '/review.php';

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

/**
 * The block kinds the timetable understands, in the order the day runs.
 *
 * `break` is the short movement break: the board draws it as a rule between
 * chips. `lunch` and `group` are also untracked, but they are slots a person
 * looks for — when is lunch, when is she out — so the board labels them.
 */
const TIMETABLE_KINDS = [
    'movement', 'retrieval', 'teach', 'practise', 'timed_handwritten',
    'coding', 'writing', 'consolidate', 'spanish', 'review', 'break',
    'lunch', 'group',
];

/** The `kind` column's CHECK constraint, built from TIMETABLE_KINDS so the two can never disagree. */
function tt_kind_check_sql(): string
{
    return 'CHECK (kind IN (' . implode(',', array_map(
        static fn(string $k): string => "'" . $k . "'", TIMETABLE_KINDS
    )) . '))';
}

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

/** The tracker's clock as a stored UTC stamp — honours TRACKER_NOW, unlike gmdate(). */
function tt_now_utc(): string
{
    return tt_now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
function tt_kind_accepts(array $block, array $ev, array $rules = []): bool
{
    if ($ev['block_key'] !== null && $ev['block_key'] === $block['block_key']) {
        return true;
    }
    // The refinement is a row in block_kind_rules when the table has one for
    // the kind; the match below is the same rule for a database that has
    // not migrated yet, and the fallback for a kind with no row.
    $by = $rules[$block['kind']]['satisfied_by'] ?? match ($block['kind']) {
        'timed_handwritten' => 'attempt',
        'retrieval'         => 'retrieval_practice',
        default             => 'any',
    };
    return match ($by) {
        'attempt'            => $ev['type'] === 'attempt',
        'retrieval_practice' => $ev['type'] === 'practice'
            && str_starts_with((string) $ev['source'], 'retrieval_'),
        default              => true,
    };
}

/** What a resource is for, used to sort and label it. */
const RESOURCE_KINDS = ['video', 'notes', 'practice', 'paper', 'book', 'other'];

/** An unfinished item is flagged stale after this many days open, and auto-closed after the second. */
const UNFINISHED_STALE_DAYS      = 21;
const UNFINISHED_AUTO_CLOSE_DAYS = 56;
const UNFINISHED_AUTO_CLOSE_REASON = 'auto-closed, stale';
/** A last session older than this is flagged stale on the queue — a flag, never a filter. */
const LAST_SESSION_STALE_DAYS = 14;

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
    private const SCHEMA_VERSION = 12;

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

        if ($v === 5) {
            // Step 3 seeded the source registry and has already run, so a
            // source added to the seed afterwards reaches an existing database
            // only if something re-runs the seed. upsertPracticeSource is an
            // upsert, so re-running it over the whole list is idempotent and
            // also repairs a row someone has edited by hand.
            foreach (PRACTICE_SOURCE_SEED as $source) {
                $this->upsertPracticeSource($source);
            }
            return;
        }

        if ($v === 6) {
            // The weekly review: the one thing on these pages that a person or
            // the Friday routine writes rather than the tracker computing it.
            // Versions are appended and never edited, so "what did we think on
            // the Friday" survives the excusal that came after it.
            $this->createWeeklyReviewTable();
            return;
        }

        if ($v === 7) {
            // Two kinds joined the timetable: `lunch`, so the board can say
            // when it is, and `group`, Thursday's session away from the desk.
            // The kind column's CHECK constraint names every kind it allows
            // and SQLite cannot alter a constraint, so an existing table is
            // rebuilt around the new list. Every row is carried across with
            // its id; a fresh database already has the new constraint.
            $sql = (string) $this->db->query(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'timetable_blocks'"
            )->fetchColumn();
            if ($sql !== '' && !str_contains($sql, "'lunch'")) {
                // The index would otherwise follow the old table through the
                // rename, and IF NOT EXISTS would then skip creating it on
                // the new one.
                $this->db->exec('DROP INDEX IF EXISTS idx_blocks_version');
                $this->db->exec('ALTER TABLE timetable_blocks RENAME TO timetable_blocks_old');
                $this->createTimetableBlocksTable();
                $this->db->exec(
                    'INSERT INTO timetable_blocks
                       (id, version_id, block_key, weekday, start, end, kind, label, note,
                        subjects_json, alternate_json, tracking, sort)
                     SELECT id, version_id, block_key, weekday, start, end, kind, label, note,
                            subjects_json, alternate_json, tracking, sort
                     FROM timetable_blocks_old'
                );
                $this->db->exec('DROP TABLE timetable_blocks_old');
            }
            return;
        }

        if ($v === 8) {
            // cs_code_lab joined the seed. As at step 5: the registry was
            // seeded once, so a new source reaches a live database only by
            // re-running the upsert, which is idempotent over the whole list.
            foreach (PRACTICE_SOURCE_SEED as $source) {
                $this->upsertPracticeSource($source);
            }
            return;
        }

        if ($v === 9) {
            // Unfinished work as a field rather than a sentence in the
            // summary. Presence is the flag: a non-null `unfinished` means
            // something is outstanding and the text says what. Closure is
            // three nullable columns set on resolution, never a delete, so
            // the row still shows the item existed. No backfill: nothing here
            // scrapes old summaries for the word "unfinished".
            //
            // `consolidates` is the D change riding on the same step: a
            // consolidation session states which errors it re-worked, as
            // JSON, rather than the service inferring it from prose.
            foreach ([
                'unfinished'                      => 'TEXT',
                'unfinished_refs'                 => 'TEXT',
                'unfinished_closed_at'            => 'TEXT',
                'unfinished_closed_by_session_id' => 'INTEGER',
                'unfinished_closed_reason'        => 'TEXT',
                'consolidates'                    => 'TEXT',
            ] as $col => $type) {
                if (!$this->hasColumn('sessions', $col)) {
                    $this->db->exec("ALTER TABLE sessions ADD COLUMN $col $type");
                }
            }
            return;
        }

        if ($v === 10) {
            // How a block of each kind is judged, as rows rather than a match
            // statement: a new kind is a row, and a rule that turns out
            // wrong is an UPDATE. Seeded once; a row someone has edited by
            // hand since is left alone (INSERT OR IGNORE).
            $this->createBlockKindRulesTable();
            foreach (SHAPE_RULE_SEED as $rule) {
                $this->seedBlockKindRule($rule);
            }
            return;
        }

        if ($v === 11) {
            // Retrieval scheduling over the practice rows that already exist:
            // an optional stable id per item, a schedule row per (subject,
            // grain, key), and the interval ladder as a config row per
            // subject so the final phase can compress it without a deploy.
            if (!$this->hasColumn('practice_item', 'item_key')) {
                $this->db->exec('ALTER TABLE practice_item ADD COLUMN item_key TEXT');
                $this->db->exec(
                    'CREATE INDEX IF NOT EXISTS idx_practice_item_key ON practice_item(item_key)'
                );
            }
            $this->createRetrievalTables();
            // The retrieval sources the D rules are judged on, and that
            // tracker_practice_stats tells apart from app games.
            foreach (PRACTICE_SOURCE_SEED as $source) {
                $this->upsertPracticeSource($source);
            }
            return;
        }

        if ($v === 12) {
            // Lesson reviews: the written half of one taught session, beside
            // the weekly review's written half of one week. Three tables for
            // the review, its signals and its error rows, one for the
            // signal ledger, and two columns on sessions. Nothing existing
            // changes shape. Whether a block's kind requires a review is a
            // column on block_kind_rules, so the answer is a row and not a
            // match statement — the same rule as the shape checks.
            $this->createLessonReviewTables();
            if (!$this->hasColumn('sessions', 'review_required')) {
                $this->db->exec('ALTER TABLE sessions ADD COLUMN review_required INTEGER NOT NULL DEFAULT 0');
            }
            if (!$this->hasColumn('sessions', 'review_id')) {
                $this->db->exec('ALTER TABLE sessions ADD COLUMN review_id INTEGER');
            }
            if (!$this->hasColumn('block_kind_rules', 'review_required')) {
                $this->db->exec(
                    'ALTER TABLE block_kind_rules ADD COLUMN review_required INTEGER NOT NULL DEFAULT 0'
                );
            }
            // The seed's answer for each kind, on rows step 10 already wrote.
            // A row someone has edited since keeps its other fields; only
            // the new flag is set, and only from the seed.
            $st = $this->db->prepare('UPDATE block_kind_rules SET review_required = ? WHERE kind = ?');
            foreach (SHAPE_RULE_SEED as $rule) {
                $st->execute([(int) !empty($rule['review_required']), $rule['kind']]);
                $this->seedBlockKindRule($rule);
            }
            $this->rulesCache = null;
            return;
        }
    }

    /**
     * The lesson review tables. Mirrors weekly_reviews: versioned, staged,
     * signed, with a server-built snapshot beside validated sections.
     *
     * review_signals is the longitudinal spine — one row per observation
     * about how she learns or how a method lands, strengthened or refuted
     * over time and never rewritten. subject_slug NULL is cross-subject;
     * the unique index coalesces it so two cross-subject rows cannot share
     * a key the way NULLs otherwise would. review_signal_events is the
     * ledger of what moved and when, which is what a week's SIGNAL MOVEMENT
     * is read from.
     */
    private function createLessonReviewTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS lesson_reviews (
               id             INTEGER PRIMARY KEY AUTOINCREMENT,
               session_id     INTEGER NOT NULL REFERENCES sessions(id),
               subject_slug   TEXT    NOT NULL,
               version        INTEGER NOT NULL,
               stage          TEXT    NOT NULL CHECK (stage IN ('draft','audited','parent')),
               written_by     TEXT    NOT NULL CHECK (written_by IN ('session','audit','chat')),
               written_at     TEXT    NOT NULL DEFAULT (datetime('now')),
               snapshot_json  TEXT    NOT NULL,
               sections_json  TEXT    NOT NULL,
               note           TEXT,
               UNIQUE (session_id, version)
             )"
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_lesson_reviews_session ON lesson_reviews(session_id, version DESC)'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_lesson_reviews_subject ON lesson_reviews(subject_slug, written_at DESC)'
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS review_signals (
               id             INTEGER PRIMARY KEY AUTOINCREMENT,
               subject_slug   TEXT,
               kind           TEXT NOT NULL CHECK (kind IN
                                ('learning_process','teaching_method','misconception',
                                 'confidence','retention','watch')),
               key            TEXT NOT NULL,
               statement      TEXT NOT NULL,
               strength       TEXT NOT NULL CHECK (strength IN ('one_off','emerging','established')),
               status         TEXT NOT NULL DEFAULT 'open'
                                CHECK (status IN ('open','resolved','refuted')),
               next_test      TEXT,
               opened_session INTEGER NOT NULL REFERENCES sessions(id),
               updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
             )"
        );
        $this->db->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_signals_key ON review_signals(COALESCE(subject_slug, ''), key)"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS review_signal_evidence (
               signal_id   INTEGER NOT NULL REFERENCES review_signals(id),
               session_id  INTEGER NOT NULL REFERENCES sessions(id),
               direction   TEXT NOT NULL CHECK (direction IN ('supports','contradicts')),
               evidence    TEXT NOT NULL,
               PRIMARY KEY (signal_id, session_id)
             )"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS review_signal_events (
               id          INTEGER PRIMARY KEY AUTOINCREMENT,
               signal_id   INTEGER NOT NULL REFERENCES review_signals(id),
               session_id  INTEGER,
               at          TEXT NOT NULL DEFAULT (datetime('now')),
               change      TEXT NOT NULL CHECK (change IN ('opened','strength','status','next_test','evidence')),
               from_value  TEXT,
               to_value    TEXT,
               detail      TEXT
             )"
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_signal_events_at ON review_signal_events(at, id)'
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS review_errors (
               id           INTEGER PRIMARY KEY AUTOINCREMENT,
               session_id   INTEGER NOT NULL REFERENCES sessions(id),
               subject_slug TEXT NOT NULL,
               ref          TEXT NOT NULL,
               error_type   TEXT NOT NULL,
               what         TEXT NOT NULL,
               why_type     TEXT NOT NULL,
               response     TEXT NOT NULL
             )"
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_review_errors_ref ON review_errors(subject_slug, ref)'
        );
    }

    /**
     * One row per block kind: what binds to it and what shape the work must
     * have to count as met. `shape_json` is a list of alternatives, each an
     * object of conditions that must all hold; an empty list is always met.
     * The condition vocabulary is in shape.php.
     */
    private function createBlockKindRulesTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS block_kind_rules (
               kind         TEXT PRIMARY KEY,
               satisfied_by TEXT NOT NULL DEFAULT 'any'
                              CHECK (satisfied_by IN ('any','attempt','retrieval_practice')),
               shape_json   TEXT NOT NULL DEFAULT '[]',
               expects      TEXT,
               note         TEXT,
               updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
             )"
        );
    }

    /** Insert a seed rule unless a row for that kind already exists. */
    private function seedBlockKindRule(array $rule): void
    {
        // Step 10 created the table without review_required; step 12 adds
        // the column. The seed is written both ways so each step can call it.
        if ($this->hasColumn('block_kind_rules', 'review_required')) {
            $st = $this->db->prepare(
                'INSERT OR IGNORE INTO block_kind_rules
                   (kind, satisfied_by, shape_json, expects, note, review_required)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $rule['kind'], $rule['satisfied_by'] ?? 'any',
                json_encode($rule['shape'] ?? [], JSON_UNESCAPED_SLASHES),
                $rule['expects'] ?? null, $rule['note'] ?? null, (int) !empty($rule['review_required']),
            ]);
            return;
        }
        $st = $this->db->prepare(
            'INSERT OR IGNORE INTO block_kind_rules (kind, satisfied_by, shape_json, expects, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            $rule['kind'], $rule['satisfied_by'] ?? 'any',
            json_encode($rule['shape'] ?? [], JSON_UNESCAPED_SLASHES),
            $rule['expects'] ?? null, $rule['note'] ?? null,
        ]);
    }

    /**
     * The retrieval schedule. One row per (subject, grain, key): item grain
     * where the client supplied an item_key, topic grain always, from the
     * topic_ref. `history` is the last few dated outcomes, which is what the
     * "why" line on tracker_retrieval_due is written from.
     */
    private function createRetrievalTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS retrieval_state (
               subject_slug        TEXT NOT NULL REFERENCES subjects(slug) ON DELETE CASCADE,
               grain               TEXT NOT NULL CHECK (grain IN ('item','topic')),
               key                 TEXT NOT NULL,
               topic_ref           TEXT,
               prompt              TEXT,
               last_asked          TEXT,
               next_due            TEXT,
               consecutive_correct INTEGER NOT NULL DEFAULT 0,
               consecutive_wrong   INTEGER NOT NULL DEFAULT 0,
               difficulty_level    INTEGER NOT NULL DEFAULT 1 CHECK (difficulty_level BETWEEN 1 AND 3),
               needs_scaffold      INTEGER NOT NULL DEFAULT 0,
               retired             INTEGER NOT NULL DEFAULT 0,
               asked               INTEGER NOT NULL DEFAULT 0,
               history             TEXT NOT NULL DEFAULT '[]',
               updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
               PRIMARY KEY (subject_slug, grain, key)
             )"
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_retrieval_due ON retrieval_state(subject_slug, next_due)'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_retrieval_topic ON retrieval_state(subject_slug, topic_ref)'
        );
        // The interval ladder, per subject. '*' is the default every subject
        // without a row of its own reads. Changing a subject's row is how the
        // final phase compresses spacing: a config change, not a deploy.
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS retrieval_config (
               subject_slug   TEXT PRIMARY KEY,
               intervals_json TEXT NOT NULL,
               note           TEXT,
               updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
             )"
        );
        $st = $this->db->prepare(
            'INSERT OR IGNORE INTO retrieval_config (subject_slug, intervals_json, note) VALUES (?, ?, ?)'
        );
        $st->execute(['*', json_encode(RETRIEVAL_DEFAULT_INTERVALS), 'Default ladder, days. Last value repeats.']);
    }

    // ---- block kind rules -------------------------------------------------

    /**
     * Every rule, keyed by kind, decoded. Cached for the request: judging a
     * week reads it once per block otherwise.
     *
     * @return array<string,array{kind:string,satisfied_by:string,shape:array,expects:?string,note:?string}>
     */
    public function blockKindRules(): array
    {
        if ($this->rulesCache !== null) {
            return $this->rulesCache;
        }
        $cache = [];
        foreach ($this->all('SELECT * FROM block_kind_rules ORDER BY kind') as $r) {
            $shape = json_decode((string) $r['shape_json'], true);
            $cache[(string) $r['kind']] = [
                'kind'         => (string) $r['kind'],
                'satisfied_by' => (string) $r['satisfied_by'],
                'shape'        => is_array($shape) ? $shape : [],
                'expects'      => $r['expects'] ?? null,
                'note'         => $r['note'] ?? null,
                'review_required' => (int) ($r['review_required'] ?? 0) === 1,
            ];
        }
        $this->rulesCache = $cache;
        return $cache;
    }

    /** @var ?array<string,array> block_kind_rules, read once per request */
    private ?array $rulesCache = null;

    /** Write or replace one rule row. Used by tests; the table is the config. */
    public function setBlockKindRule(array $rule): void
    {
        $this->rulesCache = null;
        $st = $this->db->prepare(
            'INSERT INTO block_kind_rules
               (kind, satisfied_by, shape_json, expects, note, review_required, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))
             ON CONFLICT(kind) DO UPDATE SET satisfied_by = excluded.satisfied_by,
               shape_json = excluded.shape_json, expects = excluded.expects,
               note = excluded.note, review_required = excluded.review_required,
               updated_at = excluded.updated_at'
        );
        $st->execute([
            $rule['kind'], $rule['satisfied_by'] ?? 'any',
            json_encode($rule['shape'] ?? [], JSON_UNESCAPED_SLASHES),
            $rule['expects'] ?? null, $rule['note'] ?? null, (int) !empty($rule['review_required']),
        ]);
    }

    // ---- retrieval config -------------------------------------------------

    /**
     * The interval ladder in force for a subject: its own row, else '*'.
     *
     * @return array<int,int> days, ascending; the last value repeats
     */
    public function retrievalIntervals(string $slug): array
    {
        foreach ([$slug, '*'] as $key) {
            $row = $this->one('SELECT intervals_json FROM retrieval_config WHERE subject_slug = ?', [$key]);
            if ($row) {
                $v = json_decode((string) $row['intervals_json'], true);
                if (is_array($v) && $v) {
                    return array_values(array_map('intval', $v));
                }
            }
        }
        return RETRIEVAL_DEFAULT_INTERVALS;
    }

    /** @param array<int,int> $intervals */
    public function setRetrievalIntervals(string $slug, array $intervals, ?string $note = null): void
    {
        $st = $this->db->prepare(
            'INSERT INTO retrieval_config (subject_slug, intervals_json, note, updated_at)
             VALUES (?, ?, ?, datetime(\'now\'))
             ON CONFLICT(subject_slug) DO UPDATE SET intervals_json = excluded.intervals_json,
               note = excluded.note, updated_at = excluded.updated_at'
        );
        $st->execute([$slug, json_encode(array_values(array_map('intval', $intervals))), $note]);
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
    /**
     * The blocks of every timetable version. On its own because schema step 7
     * rebuilds it: SQLite cannot widen a CHECK constraint in place.
     *
     * block_key is the identity that survives a re-cut: an excusal written
     * against block 16 in week 37 still resolves after the timetable is
     * edited in week 40. The primary key does not, so nothing user-facing
     * is ever allowed to reference it.
     */
    private function createTimetableBlocksTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS timetable_blocks (
               id             INTEGER PRIMARY KEY AUTOINCREMENT,
               version_id     INTEGER NOT NULL REFERENCES timetable_versions(id) ON DELETE CASCADE,
               block_key      INTEGER NOT NULL,
               weekday        INTEGER NOT NULL CHECK (weekday BETWEEN 1 AND 7),
               start          TEXT NOT NULL,
               end            TEXT NOT NULL,
               kind           TEXT NOT NULL " . tt_kind_check_sql() . ",
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
    }

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

        $this->createTimetableBlocksTable();

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
     * Margin notes against a week. Append-only: one row per version, the
     * latest shown, the earlier ones still readable. snapshot_json is written
     * by the server from judgeWeek() and the week's record, never by the
     * caller, so a note cannot mis-state the week it was written about.
     */
    private function createWeeklyReviewTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS weekly_reviews (
               id            INTEGER PRIMARY KEY AUTOINCREMENT,
               week          TEXT NOT NULL,
               version       INTEGER NOT NULL,
               stage         TEXT NOT NULL CHECK (stage IN ('draft','reviewed')),
               written_by    TEXT NOT NULL CHECK (written_by IN ('routine','chat')),
               written_at    TEXT NOT NULL DEFAULT (datetime('now')),
               snapshot_json TEXT NOT NULL,
               sections_json TEXT NOT NULL,
               note          TEXT,
               UNIQUE (week, version)
             )"
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_weekly_reviews_week ON weekly_reviews(week, version DESC)'
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

        $this->transaction(function () use ($args, $next, $watch, $touched, $existing): void {
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
        });

        return ['previous' => $existing['status'], 'current' => $next];
    }

    /**
     * Run a callable inside one write transaction, joining the transaction
     * already open when there is one. tracker_log_session writes the session
     * row, its topic changes, its retrieval outcomes and its review as one
     * unit; the methods it calls each take a transaction of their own when
     * called alone, and must not try to open a second one when called from
     * inside it.
     */
    public function transaction(callable $fn): mixed
    {
        // Nesting is tracked here rather than asked of PDO: before PHP 8.4,
        // pdo_sqlite's inTransaction() reports only transactions opened with
        // beginTransaction(), not one opened with BEGIN IMMEDIATE, so a
        // nested call would try to open a second one and fail. PDO's own
        // flag is still honoured for callers that used beginTransaction().
        if ($this->txDepth > 0 || $this->db->inTransaction()) {
            $this->txDepth++;
            try {
                return $fn();
            } finally {
                $this->txDepth--;
            }
        }
        $this->db->exec('BEGIN IMMEDIATE');
        $this->txDepth = 1;
        try {
            $out = $fn();
            $this->db->exec('COMMIT');
            return $out;
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->txDepth = 0;
        }
    }

    /** How deep transaction() is nested; 0 outside one. */
    private int $txDepth = 0;

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
                  'practice_item', 'lesson_reviews', 'review_signals'] as $t) {
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

    /**
     * The most recent session that still counts — the one a new session
     * opens on. Void sessions are skipped rather than surfaced: a session
     * logged in error is exactly what the next one must not continue from.
     */
    public function lastSession(string $slug): ?array
    {
        return $this->one(
            'SELECT * FROM sessions WHERE subject_slug = ? AND void_reason IS NULL
             ORDER BY date DESC, id DESC LIMIT 1',
            [$slug]
        );
    }

    /** One session by id alone, any subject. Used where the subject is not yet known. */
    public function sessionById(int $id): ?array
    {
        return $this->one('SELECT * FROM sessions WHERE id = ?', [$id]);
    }

    // ---- unfinished work ----------------------------------------------------
    //
    // "We stopped before the end" as a field the next session cannot miss.
    // Open means: non-null `unfinished`, no closure, and the session is not
    // void. Closing is a stamp — who closed it, when, why — never a delete.

    /**
     * Open unfinished items for a subject, oldest first, each with how long
     * it has been open and whether that is stale. Runs the ageing sweep
     * first, so an item past the auto-close line is closed before it is
     * listed rather than listed and then closed on some later read.
     *
     * @return array<int,array{session_id:int,date:string,days_open:int,block_key:?int,
     *               text:string,refs:array<int,string>,stale:bool}>
     */
    public function openUnfinished(string $slug): array
    {
        $this->sweepUnfinished();
        $today = tt_today();
        $out   = [];
        foreach ($this->all(
            'SELECT * FROM sessions
             WHERE subject_slug = ? AND unfinished IS NOT NULL AND unfinished_closed_at IS NULL
               AND void_reason IS NULL
             ORDER BY date, id',
            [$slug]
        ) as $s) {
            $out[] = $this->unfinishedEntry($s, $today);
        }
        return $out;
    }

    /** One session row as a queue entry. */
    public function unfinishedEntry(array $s, ?string $today = null): array
    {
        $today ??= tt_today();
        $days = tt_days_between((string) $s['date'], $today);
        return [
            'session_id' => (int) $s['id'],
            'date'       => (string) $s['date'],
            'days_open'  => $days,
            'block_key'  => $s['block_key'] === null ? null : (int) $s['block_key'],
            'text'       => (string) $s['unfinished'],
            'refs'       => self::decodeRefs($s['unfinished_refs'] ?? null),
            'stale'      => $days > UNFINISHED_STALE_DAYS,
        ];
    }

    /** @return array<int,string> */
    public static function decodeRefs(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $v = json_decode((string) $json, true);
        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /**
     * Auto-close anything open past the line, with the reason and no closing
     * session. Idempotent: closed rows are not matched again.
     */
    public function sweepUnfinished(): int
    {
        $st = $this->db->prepare(
            'UPDATE sessions
             SET unfinished_closed_at = ?, unfinished_closed_reason = ?
             WHERE unfinished IS NOT NULL AND unfinished_closed_at IS NULL AND void_reason IS NULL
               AND date < ?'
        );
        // days_open > AUTO_CLOSE ⇔ date < today − AUTO_CLOSE days.
        $st->execute([
            tt_now_utc(), UNFINISHED_AUTO_CLOSE_REASON,
            tt_add_days(tt_today(), -UNFINISHED_AUTO_CLOSE_DAYS),
        ]);
        return $st->rowCount();
    }

    /**
     * Stamp one item closed. Returns false when there was nothing open to
     * close — already closed, or never unfinished — so a repeat is a no-op
     * rather than a re-stamp.
     */
    public function closeUnfinished(int $sessionId, ?int $bySessionId, string $reason): bool
    {
        $st = $this->db->prepare(
            'UPDATE sessions
             SET unfinished_closed_at = ?, unfinished_closed_by_session_id = ?, unfinished_closed_reason = ?
             WHERE id = ? AND unfinished IS NOT NULL AND unfinished_closed_at IS NULL'
        );
        $st->execute([tt_now_utc(), $bySessionId, $reason, $sessionId]);
        return $st->rowCount() > 0;
    }

    /** The sessions whose unfinished work a given session closed. */
    public function sessionsClosedBy(int $sessionId): array
    {
        return $this->all(
            'SELECT * FROM sessions WHERE unfinished_closed_by_session_id = ? ORDER BY date, id',
            [$sessionId]
        );
    }

    /**
     * The week's account of unfinished work for one subject: items opened
     * by sessions dated in the week, items closed during it, and whatever
     * is open now. `open_now` is the live list — the review names it, and
     * the snapshot freezes it as it stood.
     *
     * @return array{opened:array<int,array>,closed:array<int,array>,open_now:array<int,array>}
     */
    public function unfinishedForWeek(string $slug, string $monday, string $sunday): array
    {
        $today  = tt_today();
        $opened = [];
        foreach ($this->all(
            'SELECT * FROM sessions
             WHERE subject_slug = ? AND unfinished IS NOT NULL AND void_reason IS NULL
               AND date BETWEEN ? AND ? ORDER BY date, id',
            [$slug, $monday, $sunday]
        ) as $s) {
            $e = $this->unfinishedEntry($s, $today);
            $e['closed'] = $s['unfinished_closed_at'] !== null;
            $opened[] = $e;
        }
        $closed = [];
        foreach ($this->all(
            'SELECT * FROM sessions
             WHERE subject_slug = ? AND unfinished IS NOT NULL AND void_reason IS NULL
               AND unfinished_closed_at IS NOT NULL AND date(unfinished_closed_at) BETWEEN ? AND ?
             ORDER BY unfinished_closed_at, id',
            [$slug, $monday, $sunday]
        ) as $s) {
            $e = $this->unfinishedEntry($s, $today);
            $e['closed_at']            = (string) $s['unfinished_closed_at'];
            $e['closed_by_session_id'] = $s['unfinished_closed_by_session_id'] === null
                ? null : (int) $s['unfinished_closed_by_session_id'];
            $e['closed_reason']        = (string) $s['unfinished_closed_reason'];
            $closed[] = $e;
        }
        return ['opened' => $opened, 'closed' => $closed, 'open_now' => $this->openUnfinished($slug)];
    }

    /**
     * @param array<string,mixed> $fields date, summary, next_steps, void_reason,
     *                                    unfinished, unfinished_refs
     */
    public function amendSession(string $slug, int $id, array $fields): bool
    {
        $allowed = ['date', 'summary', 'next_steps', 'void_reason', 'unfinished', 'unfinished_refs',
                    'unfinished_closed_at', 'unfinished_closed_by_session_id', 'unfinished_closed_reason'];
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
               (subject_slug, date, summary, topics_touched, next_steps, block_key, duration_minutes,
                unfinished, unfinished_refs, consolidates)
             VALUES (:subject_slug, :date, :summary, :topics_touched, :next_steps,
                     :block_key, :duration_minutes, :unfinished, :unfinished_refs, :consolidates)'
        );
        $refs = $s['unfinished_refs'] ?? null;
        $cons = $s['consolidates'] ?? null;
        $st->execute([
            ':subject_slug'     => $s['subject_slug'],
            ':date'             => $s['date'],
            ':summary'          => $s['summary'],
            ':topics_touched'   => $s['topics_touched'] ?? null,
            ':next_steps'       => $s['next_steps'] ?? null,
            ':block_key'        => $s['block_key'] ?? null,
            ':duration_minutes' => $s['duration_minutes'] ?? null,
            ':unfinished'       => $s['unfinished'] ?? null,
            ':unfinished_refs'  => $refs ? json_encode(array_values($refs), JSON_UNESCAPED_UNICODE) : null,
            ':consolidates'     => $cons ? json_encode(array_values($cons), JSON_UNESCAPED_UNICODE) : null,
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
            'declared' => 0, 'extra' => 0, 'judged' => 0, 'shape_unmet' => 0,
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
                // Inside `done`, never instead of it: the block was done, in
                // the wrong shape, and both facts are counted.
                if (($b['shape'] ?? null) === 'unmet') {
                    $counts['shape_unmet']++;
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
        $rules   = $this->blockKindRules();

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
                    if (!tt_kind_accepts($b, $e, $rules)) {
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
                    // The shape verdict, D. `shape` is 'met', 'unmet' or null
                    // (nothing to judge, or the kind has no rule); the reason
                    // says what was expected and what was found. The status
                    // stays 'done' — a block done in the wrong shape counts
                    // for adherence and hours, and is never a miss.
                    'shape'        => null,
                    'shape_reason' => null,
                    'shape_rule'   => isset($rules[$b['kind']]) || isset($rules['*']),
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
                    $verdict = shape_judge($this, $b, $e, $length, $date, $rules);
                    $row['shape']        = $verdict['shape'];
                    $row['shape_reason'] = $verdict['reason'];
                    $row['shape_rule']   = $verdict['has_rule'];
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
            'SELECT id, subject_slug, source, label, played_at, duration_seconds, block_key, attempted
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
                'attempted' => (int) $r['attempted'],
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

    // ---- the weekly review -------------------------------------------------
    //
    // Computed on the left, written in the margin. Two kinds of thing live
    // here: the snapshot and the drift, which derive a week's figures from the
    // record, and the note, which is the one thing on these pages nobody can
    // derive — what a person made of the week. The snapshot is always built
    // here, from the database, so a note cannot mis-state the week it sits
    // beside.

    /**
     * Append one version of a week's margin note.
     *
     * The version is allocated inside the same BEGIN IMMEDIATE that writes the
     * row, so two writers cannot both produce a version 3; UNIQUE (week,
     * version) is the belt to that braces. An identical re-save — same stage,
     * same written_by, byte-identical sections — returns the version already
     * there rather than growing the ledger a phantom row, which is the rule
     * client_run_id already serves for practice.
     *
     * @param array{week:string,stage:string,written_by:string,snapshot:array,
     *              sections:array,note?:?string} $r
     * @return array{status:'stored'|'duplicate',row:array<string,mixed>}
     */
    public function addWeeklyReview(array $r): array
    {
        $week     = (string) $r['week'];
        $sections = self::reviewJson($r['sections']);
        $snapshot = self::reviewJson($r['snapshot']);

        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $latest = $this->one(
                'SELECT * FROM weekly_reviews WHERE week = ? ORDER BY version DESC LIMIT 1',
                [$week]
            );
            if (
                $latest !== null
                && (string) $latest['stage'] === (string) $r['stage']
                && (string) $latest['written_by'] === (string) $r['written_by']
                && (string) $latest['sections_json'] === $sections
            ) {
                $this->db->exec('COMMIT');
                return ['status' => 'duplicate', 'row' => $this->hydrateWeeklyReview($latest)];
            }
            $version = $latest === null ? 1 : (int) $latest['version'] + 1;
            $st = $this->db->prepare(
                'INSERT INTO weekly_reviews
                   (week, version, stage, written_by, snapshot_json, sections_json, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $week, $version, $r['stage'], $r['written_by'], $snapshot, $sections, $r['note'] ?? null,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        return [
            'status' => 'stored',
            'row'    => $this->hydrateWeeklyReview(
                $this->one('SELECT * FROM weekly_reviews WHERE id = ?', [$id])
            ),
        ];
    }

    /**
     * The latest version for a week, or one named version. Null when the week
     * has no note; that is not an error, most weeks do not have one yet.
     */
    public function weeklyReview(string $week, ?int $version = null): ?array
    {
        $row = $version === null
            ? $this->one('SELECT * FROM weekly_reviews WHERE week = ? ORDER BY version DESC LIMIT 1', [$week])
            : $this->one('SELECT * FROM weekly_reviews WHERE week = ? AND version = ?', [$week, $version]);
        return $row === null ? null : $this->hydrateWeeklyReview($row);
    }

    /** @return array<int,array{id:int,version:int,stage:string,written_at:string,written_by:string}> */
    public function weeklyReviewVersions(string $week): array
    {
        $rows = $this->all(
            'SELECT id, version, stage, written_at, written_by FROM weekly_reviews
             WHERE week = ? ORDER BY version',
            [$week]
        );
        foreach ($rows as &$r) {
            $r['id']      = (int) $r['id'];
            $r['version'] = (int) $r['version'];
        }
        return $rows;
    }

    /**
     * The latest note for each of several weeks, for the term ledger: one
     * query rather than one per row.
     *
     * @param  array<int,string> $weeks ISO labels
     * @return array<string,array<string,mixed>> week => latest row
     */
    public function latestWeeklyReviews(array $weeks): array
    {
        $weeks = array_values(array_unique($weeks));
        if (!$weeks) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($weeks), '?'));
        $out  = [];
        foreach ($this->all(
            "SELECT * FROM weekly_reviews WHERE week IN ($in) ORDER BY week, version",
            $weeks
        ) as $row) {
            // Ascending, so the last row written for a week wins.
            $out[(string) $row['week']] = $this->hydrateWeeklyReview($row);
        }
        return $out;
    }

    /** Canonical JSON, so "identical sections" is a byte comparison, not a guess. */
    private static function reviewJson(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function hydrateWeeklyReview(array $r): array
    {
        $r['id']       = (int) $r['id'];
        $r['version']  = (int) $r['version'];
        $r['snapshot'] = json_decode((string) $r['snapshot_json'], true) ?: [];
        $r['sections'] = json_decode((string) $r['sections_json'], true) ?: [];
        return $r;
    }

    /**
     * Planned hours per subject for one week, read off the timetable rather
     * than off any target.
     *
     * The sum of the lengths of the Monday–Friday blocks that resolve to
     * exactly one subject: a block that counts for nobody when it is done —
     * the mixed retrieval warm-ups, the Tuesday timed rotation — counts for
     * nobody when it is planned, so both sides of the comparison are built the
     * same way. Alternating blocks resolve by this week's parity, so an odd
     * week plans differently from an even one and the page compares like with
     * like.
     *
     * @return array<string,float> slug => hours, 2dp, ordered by slug
     */
    public function plannedBySubject(string $monday): array
    {
        $monday = tt_monday($monday);
        $cache  = [];
        $mins   = [];
        for ($i = 0; $i < 5; $i++) {
            $date    = tt_add_days($monday, $i);
            $version = $this->timetableVersionOn($date);
            if (!$version) {
                continue;
            }
            $id = (int) $version['id'];
            $cache[$id] ??= $this->timetableBlocks($id);
            $weekday = (int) (new DateTimeImmutable($date, tt_zone()))->format('N');
            foreach ($cache[$id] as $b) {
                if ($b['weekday'] !== $weekday || $b['tracking'] === 'none') {
                    continue;
                }
                $subjects = tt_subjects_for($b, $date);
                if (count($subjects) !== 1) {
                    continue;
                }
                $slug = $subjects[0];
                $mins[$slug] = ($mins[$slug] ?? 0) + (tt_mins($b['end']) - tt_mins($b['start']));
            }
        }
        foreach ($mins as $slug => $m) {
            $mins[$slug] = round($m / 60, 2);
        }
        ksort($mins);
        return $mins;
    }

    /**
     * Topic changes stamped inside a date range, newest first, with the topic
     * name joined — the query history() runs, bounded at both ends so a caller
     * can ask for one week rather than for the last N of them.
     */
    public function changesBetween(string $from, string $to, ?string $slug = null): array
    {
        $sql    = "SELECT c.*, COALESCE(t.name, '') AS topic_name
                   FROM topic_changes c
                   LEFT JOIN topics t ON t.subject_slug = c.subject_slug AND t.ref = c.ref
                   WHERE date(c.changed_at) BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($slug !== null) {
            $sql .= ' AND c.subject_slug = ?';
            $params[] = $slug;
        }
        return $this->all($sql . ' ORDER BY c.changed_at DESC, c.id DESC', $params);
    }

    /**
     * The ISO weeks the ledger has anything to say about: this week back to
     * the earliest week holding a session, an attempt, a practice run or a
     * timetable, newest first and capped at $limit.
     *
     * @return array<int,array{week:string,monday:string}>
     */
    public function weeksWithActivity(int $limit = 26): array
    {
        $earliest = null;
        foreach ([
            'SELECT MIN(date) AS d FROM sessions',
            'SELECT MIN(COALESCE(p.sat_on, a.date)) AS d
               FROM attempt_papers p JOIN attempts a ON a.id = p.attempt_id',
            'SELECT MIN(date(played_at)) AS d FROM practice_run',
            'SELECT MIN(valid_from) AS d FROM timetable_versions',
        ] as $sql) {
            $d = $this->one($sql)['d'] ?? null;
            if ($d !== null && $d !== '' && ($earliest === null || $d < $earliest)) {
                $earliest = (string) $d;
            }
        }
        $monday = tt_monday(tt_today());
        // An empty record still has this week: the ledger opens on the week
        // you are in rather than on nothing at all.
        $first = $earliest === null ? $monday : tt_monday(substr($earliest, 0, 10));
        $out   = [];
        while ($monday >= $first && count($out) < $limit) {
            $out[]  = ['week' => tt_iso_week($monday), 'monday' => $monday];
            $monday = tt_add_days($monday, -7);
        }
        return $out;
    }

    /**
     * What to work on next in one subject, in the three groups the queue has
     * always had: ageing secures due a retrieval check, loose ends on
     * otherwise-secure topics, and gaps with the lower tier first.
     *
     * Extracted from tracker_review_queue so the page and the tool select from
     * one place — a queue that says one thing in chat and another on the board
     * is worse than no queue at all.
     *
     * @return array{ageing:array<int,array{topic:array,weeks:?int}>,
     *               loose:array<int,array>,gaps:array<int,array>}
     */
    public function reviewQueue(string $slug, int $ageingWeeks = 8): array
    {
        // Unfinished work leads the queue: it is the one group that names
        // something already begun, and the case the group exists for is a
        // new poem started while yesterday's was still half-written.
        $unfinished = $this->openUnfinished($slug);
        $all        = $this->listTopics($slug);
        $ageing = [];
        foreach ($all as $t) {
            if ($t['status'] === 'secure' || $t['status'] === 'examready') {
                $w = weeksSince($t['last_touched']);
                // A topic with no date recorded is treated as the oldest
                // thing there is: not knowing when it was last checked is
                // not the same as having checked it recently.
                if ($w === null || $w >= $ageingWeeks) {
                    $ageing[] = ['topic' => $t, 'weeks' => $w];
                }
            }
        }
        usort($ageing, static fn($x, $y) => ($y['weeks'] ?? 999) <=> ($x['weeks'] ?? 999));

        $loose = array_values(array_filter($all, static fn($t) => $t['watch'] && $t['status'] !== 'gap'));
        $gaps  = array_values(array_filter($all, static fn($t) => $t['status'] === 'gap'));
        usort($gaps, static fn($x, $y) => strcmp($x['tier'], $y['tier']));

        return ['unfinished' => $unfinished, 'ageing' => $ageing, 'loose' => $loose, 'gaps' => $gaps];
    }

    /**
     * The last-session block a session opens on: the most recent non-void
     * session, its plan verbatim, the tail of its summary, and any open
     * unfinished item it carries. Null when the subject has no sessions.
     *
     * @return ?array{session_id:int,date:string,days_ago:int,block_key:?int,
     *                duration_minutes:?int,next_steps:?string,summary_tail:string,
     *                unfinished:?string,stale:bool}
     */
    public function lastSessionBlock(string $slug): ?array
    {
        $s = $this->lastSession($slug);
        if (!$s) {
            return null;
        }
        $days    = tt_days_between((string) $s['date'], tt_today());
        $summary = (string) $s['summary'];
        $tail    = mb_strlen($summary) > 300 ? '…' . mb_substr($summary, -300) : $summary;
        $open    = $s['unfinished'] !== null && $s['unfinished_closed_at'] === null;
        return [
            'session_id'       => (int) $s['id'],
            'date'             => (string) $s['date'],
            'days_ago'         => $days,
            'block_key'        => $s['block_key'] === null ? null : (int) $s['block_key'],
            'duration_minutes' => $s['duration_minutes'] === null ? null : (int) $s['duration_minutes'],
            'next_steps'       => $s['next_steps'] === null ? null : (string) $s['next_steps'],
            'summary_tail'     => $tail,
            'unfinished'       => $open ? (string) $s['unfinished'] : null,
            'stale'            => $days > LAST_SESSION_STALE_DAYS,
        ];
    }

    /** The top line of one subject's queue: ageing first, then a loose end, then a gap. */
    private function queueTop(string $slug): ?array
    {
        $q = $this->reviewQueue($slug);
        if ($q['ageing']) {
            $t = $q['ageing'][0]['topic'];
            $w = $q['ageing'][0]['weeks'];
            return ['kind' => 'ageing', 'ref' => $t['ref'], 'line' => $t['ref'] . ' ' . $t['name']
                . ' — ' . ($w === null ? 'no date recorded' : $w . ' weeks since last touched')];
        }
        if ($q['loose']) {
            $t = $q['loose'][0];
            return ['kind' => 'loose', 'ref' => $t['ref'],
                'line' => $t['ref'] . ' ' . $t['name'] . ' — ' . $t['watch']];
        }
        if ($q['gaps']) {
            $t = $q['gaps'][0];
            return ['kind' => 'gap', 'ref' => $t['ref'], 'line' => $t['ref'] . ' ' . $t['name']
                . ' — gap (' . $t['strand'] . ', tier ' . $t['tier'] . ')'];
        }
        return null;
    }

    /**
     * The server's account of one week, as it stands at the moment of asking.
     *
     * This is what gets frozen into snapshot_json beside a written note, and
     * it is also what the week page reads, so the two cannot disagree about
     * the figures. Every key is derived from the database; nothing here is
     * ever supplied by a caller.
     *
     * @return array{
     *   schema:int, week:string, monday:string, friday:string, captured_at:string,
     *   timetable_version_id:?int,
     *   counts:array<string,int>,
     *   counts_by_tracking:array{evidence:array<string,int>,self_report:array<string,int>,
     *                            review:array<string,int>},
     *   hours_by_subject:array<string,float>, planned_by_subject:array<string,float>,
     *   blocks:array<int,array<string,mixed>>, extras:array<int,array<string,mixed>>,
     *   days_off:array<int,array<string,mixed>>, changes:array<int,array<string,mixed>>,
     *   coverage:array<string,array{pct_start:int,pct_end:int,topics:int}>,
     *   attempts:array<int,array<string,mixed>>,
     *   practice:array<string,array<string,mixed>>,
     *   timed:array{this_week:array<int,array<string,mixed>>,last:?array<string,mixed>},
     *   queue_top:array<string,?array{kind:string,ref:string,line:string}>
     * }
     */
    public function weekSnapshot(string $monday): array
    {
        $monday = tt_monday($monday);
        $sunday = tt_add_days($monday, 6);
        $w      = $this->judgeWeek($monday);
        $flat   = $this->flattenWeek($w['days']);
        $version = $this->timetableVersionOn($monday);

        // Days off touching the week, plus everything still undecided on any
        // date: a request the parent has not answered is part of the week's
        // account even when it is for next month.
        $daysOff = [];
        foreach (array_merge(
            $this->listDaysOff($monday, $sunday),
            $this->listDaysOff(null, null, 'requested')
        ) as $d) {
            $daysOff[(int) $d['id']] = [
                'id'           => (int) $d['id'],
                'date_from'    => $d['date_from'],
                'date_to'      => $d['date_to'],
                'kind'         => $d['kind'],
                'reason'       => $d['reason'],
                'requested_by' => $d['requested_by'],
                'status'       => $d['status'],
            ];
        }
        ksort($daysOff);

        $coverage   = [];
        $practice   = [];
        $queueTop   = [];
        $unfinished = [];
        foreach ($this->listSubjects() as $s) {
            $slug            = $s['slug'];
            $coverage[$slug] = $this->coverageAcross($slug, $monday, $sunday);
            $queueTop[$slug] = $this->queueTop($slug);
            $unfinished[$slug] = $this->unfinishedForWeek($slug, $monday, $sunday);
            $runs = $this->listPracticeRuns($slug, ['since' => $monday, 'until' => $sunday]);
            if (!$runs) {
                continue;
            }
            $attempted = $correct = $retry = $best = 0;
            foreach ($runs as $r) {
                $attempted += (int) $r['attempted'];
                $correct   += (int) $r['correct'];
                $retry     += (int) $r['correct_after_retry'];
                $best       = max($best, (int) $r['correct']);
            }
            $practice[$slug] = [
                'runs'                => count($runs),
                'attempted'           => $attempted,
                'correct'             => $correct,
                'correct_after_retry' => $retry,
                // Pooled, never the mean of per-run percentages.
                'first_time_pct'      => $attempted ? round($correct / $attempted * 100, 1) : null,
                'best_score'          => $best,
            ];
        }

        return [
            'schema'               => 1,
            'week'                 => $w['week'],
            'monday'               => $monday,
            'friday'               => tt_add_days($monday, 4),
            'captured_at'          => gmdate('Y-m-d H:i:s'),
            'timetable_version_id' => $version ? (int) $version['id'] : null,
            'counts'               => $w['counts'],
            'counts_by_tracking'   => self::countsByTracking($flat['blocks']),
            'hours_by_subject'     => $w['hours_by_subject'],
            'planned_by_subject'   => $this->plannedBySubject($monday),
            'blocks'               => $flat['blocks'],
            'extras'               => $flat['extras'],
            'days_off'             => array_values($daysOff),
            'changes'              => $this->weekChanges($monday, $sunday),
            'coverage'             => $coverage,
            'attempts'             => $this->attemptsSatBetween($monday, $sunday),
            'practice'             => $practice,
            'timed'                => $this->timedFor($monday, $flat['blocks']),
            'queue_top'            => $queueTop,
            'unfinished'           => $unfinished,
        ];
    }

    /**
     * How a stored snapshot differs from the record as it stands now.
     *
     * The page and tracker_get_weekly_review render the same drift line from
     * this, so a note read in chat and the same note read on the board cannot
     * describe the week differently.
     *
     * `changes` is the ordered list of §6 differences, each
     * `['text' => 'Wed 09:45 Spanish — vocab + listening excused — dentist',
     *   'kind' => 'excused']`; `since` is those rendered as the second
     * sentence, at most three of them, and empty when nothing has moved —
     * silence means the note and the record still agree. `line` is the whole
     * drift line, and `counts` / `live` are the study-block counts then and
     * now.
     *
     * @param  array $snapshot a weekSnapshot(), as stored in snapshot_json
     * @return array{changes:array<int,array{text:string,kind:string}>,since:string,
     *               line:string,counts:array<string,int>,live:array<string,int>}
     */
    public function weekDrift(array $snapshot): array
    {
        $monday = (string) ($snapshot['monday'] ?? '');
        if ($monday === '') {
            $monday = tt_week_monday((string) ($snapshot['week'] ?? '')) ?? tt_monday(tt_today());
        }
        $live = $this->flattenWeek($this->judgeWeek($monday)['days']);

        $was = [];
        foreach ($snapshot['blocks'] ?? [] as $b) {
            $was[$b['date'] . '#' . $b['block_key']] = $b;
        }
        $now = [];
        foreach ($live['blocks'] as $b) {
            $now[$b['date'] . '#' . $b['block_key']] = $b;
        }

        $changes = [];
        $add = static function (array $b, string $verb, string $kind, ?string $reason = null) use (&$changes): void {
            $changes[] = [
                'kind' => $kind,
                'text' => substr(TIMETABLE_DAYS[$b['weekday']], 0, 3) . ' ' . $b['start'] . ' '
                    . $b['label'] . ' ' . $verb . ($reason ? ' — ' . $reason : ''),
            ];
        };

        // The fixed order of §6: what was forgiven, what was un-forgiven, what
        // turned up, what stopped counting, what the parent decided, what was
        // logged outside the plan, and last the timetable itself moving.
        foreach ($now as $key => $b) {
            if ($b['status'] === 'excused' && (($was[$key]['status'] ?? '') !== 'excused')) {
                $add($b, 'excused', 'excused', $b['reason']);
            }
        }
        foreach ($was as $key => $b) {
            if ($b['status'] === 'excused' && isset($now[$key]) && $now[$key]['status'] !== 'excused') {
                $add($b, 'no longer excused', 'unexcused');
            }
        }
        foreach ($now as $key => $b) {
            if (($was[$key]['status'] ?? '') === 'missed' && $b['status'] === 'done') {
                $add($b, 'now done', 'done');
            }
        }
        // The parent saying a block happened is a change to the week's account
        // and belongs in the drift, but it is his word rather than the
        // record's, so it is said in his words and never as "now done".
        foreach ($now as $key => $b) {
            if (($was[$key]['status'] ?? '') === 'missed' && $b['status'] === 'declared') {
                $add($b, 'marked done by Dad', 'declared', $b['reason']);
            }
        }
        foreach ($now as $key => $b) {
            if (($was[$key]['status'] ?? '') === 'done' && $b['status'] === 'missed') {
                $add($b, 'now missed', 'missed');
            }
        }

        // Day-off decisions are compared as decisions, not block by block, so
        // approving one day does not print five near-identical lines.
        $offWas = [];
        foreach ($snapshot['days_off'] ?? [] as $d) {
            $offWas[(int) $d['id']] = $d;
        }
        foreach ($this->listDaysOff($monday, tt_add_days($monday, 6)) as $d) {
            $offWas[(int) $d['id']] ??= null;
        }
        foreach ($offWas as $id => $before) {
            $after = $this->getDayOff((int) $id);
            if (!$after) {
                continue;
            }
            $status = (string) $after['status'];
            if ($before !== null && (string) $before['status'] === $status) {
                continue;
            }
            $span = $after['date_from'] === $after['date_to']
                ? tt_pretty($after['date_from'])
                : tt_pretty($after['date_from']) . ' to ' . tt_pretty($after['date_to']);
            $changes[] = [
                'kind' => 'day_off',
                'text' => 'Day off ' . $span . ' ' . $status . ' — ' . $after['reason'],
            ];
        }

        $wasExtra = [];
        foreach ($snapshot['extras'] ?? [] as $e) {
            $wasExtra[$e['date'] . '#' . $e['type'] . '#' . $e['id']] = true;
        }
        foreach ($live['extras'] as $e) {
            if (!isset($wasExtra[$e['date'] . '#' . $e['type'] . '#' . $e['id']])) {
                $changes[] = [
                    'kind' => 'extra',
                    'text' => substr(TIMETABLE_DAYS[(int) $e['weekday']], 0, 3) . ' ' . $e['at'] . ' '
                        . $e['label'] . ' logged outside the timetable',
                ];
            }
        }
        foreach ($now as $key => $b) {
            if (!isset($was[$key])) {
                $add($b, 'added to the timetable', 'added');
            }
        }
        foreach ($was as $key => $b) {
            if (!isset($now[$key])) {
                $add($b, 'no longer in the timetable', 'removed');
            }
        }

        $texts = array_column($changes, 'text');
        $since = '';
        if ($texts) {
            $since = 'Since then: ' . implode('; ', array_slice($texts, 0, 3))
                . (count($texts) > 3 ? '; and ' . (count($texts) - 3) . ' more changes.' : '.');
        }

        $counts = ($snapshot['counts_by_tracking']['evidence'] ?? null)
            ?: self::countsByTracking($snapshot['blocks'] ?? [])['evidence'];
        $liveCounts = self::countsByTracking($live['blocks'])['evidence'];

        // The stamp is local, like every time a person reads on these pages.
        $captured = ((string) ($snapshot['captured_at'] ?? '')) ?: gmdate('Y-m-d H:i:s');
        [$on, $at] = tt_local($captured);
        $line = 'Written from the record at '
            . (new DateTimeImmutable($on, tt_zone()))->format('D j M') . ' ' . $at
            . ' (' . $counts['missed'] . ' missed · ' . $counts['excused'] . ' excused).'
            . ($since === '' ? '' : ' ' . $since);

        return [
            'changes' => $changes,
            'since'   => $since,
            'line'    => $line,
            'counts'  => $counts,
            'live'    => $liveCounts,
        ];
    }

    /**
     * judgeWeek()'s days as two flat lists: every judged block with the date
     * it ran on, and every extra with its day. Breaks are not judged, so they
     * are in neither.
     *
     * Every judged status travels, `optional` and `declared` included: the
     * status is carried whole and read where it is printed, so nothing here
     * quietly turns a block the parent vouched for into a done one, or an
     * untaken walk into a miss.
     *
     * @return array{blocks:array<int,array<string,mixed>>,extras:array<int,array<string,mixed>>}
     */
    private function flattenWeek(array $days): array
    {
        $blocks = [];
        $extras = [];
        foreach ($days as $day) {
            foreach ($day['blocks'] as $b) {
                if ($b['status'] === 'n/a') {
                    continue;
                }
                $blocks[] = [
                    'date'      => $day['date'],
                    'weekday'   => $day['weekday'],
                    'block_key' => $b['block_key'],
                    'start'     => $b['start'],
                    'end'       => $b['end'],
                    'label'     => $b['label'],
                    'kind'      => $b['kind'],
                    'tracking'  => $b['tracking'],
                    'subjects'  => $b['subjects'],
                    'subject'   => $b['subject'],
                    'status'    => $b['status'],
                    'short'     => (bool) $b['short'],
                    'minutes'   => $b['minutes'],
                    'length'    => $b['length'],
                    'reason'    => $b['reason'],
                    'evidence'  => $b['evidence'],
                    'shape'        => $b['shape'] ?? null,
                    'shape_reason' => $b['shape_reason'] ?? null,
                    'shape_rule'   => $b['shape_rule'] ?? true,
                ];
            }
            foreach ($day['extras'] as $e) {
                $extras[] = [
                    'date'    => $day['date'],
                    'weekday' => $day['weekday'],
                    'type'    => $e['type'],
                    'id'      => $e['id'],
                    'subject' => $e['subject'],
                    'label'   => $e['label'],
                    'at'      => $e['at'],
                    'minutes' => $e['minutes'],
                ];
            }
        }
        return ['blocks' => $blocks, 'extras' => $extras];
    }

    /**
     * The counts partitioned the way the pages read them: study blocks on
     * their own, movement on its own, the Friday review block on its own.
     * "23 of 27" puts a walk and a maths block in one fraction and the
     * fraction then means nothing to whoever is reading it.
     *
     * `optional` and `declared` are counted like every other status and
     * folded into none of them: a movement block that was not ticked is not
     * a miss, and a block the parent marked done by hand is accounted for
     * without being passed off as evidence. Whoever prints these has to keep
     * them apart — `done + declared` is what happened, `done` is what the
     * record can show.
     *
     * @param  array<int,array<string,mixed>> $blocks flattenWeek()'s blocks
     * @return array<string,array<string,int>>
     */
    private static function countsByTracking(array $blocks): array
    {
        $blank = ['done' => 0, 'short' => 0, 'missed' => 0, 'excused' => 0, 'day_off' => 0,
                  'now' => 0, 'pending' => 0, 'upcoming' => 0, 'optional' => 0,
                  'declared' => 0, 'judged' => 0, 'shape_unmet' => 0];
        $out = ['evidence' => $blank, 'self_report' => $blank, 'review' => $blank];
        foreach ($blocks as $b) {
            $part = $b['tracking'] === 'evidence'
                ? 'evidence'
                : ($b['kind'] === 'review' ? 'review' : 'self_report');
            $out[$part]['judged']++;
            $out[$part][$b['status']] = ($out[$part][$b['status']] ?? 0) + 1;
            if (!empty($b['short'])) {
                $out[$part]['short']++;
            }
            if (($b['shape'] ?? null) === 'unmet') {
                $out[$part]['shape_unmet']++;
            }
        }
        return $out;
    }

    /**
     * Coverage at the start and the end of a week, replayed rather than
     * stored: points now, less every change made after the instant asked
     * about. The denominator is today's topic count, so an older week reads
     * as coverage against today's syllabus — say so wherever it is shown
     * rather than pretending otherwise.
     *
     * @return array{pct_start:int,pct_end:int,topics:int}
     */
    private function coverageAcross(string $slug, string $monday, string $sunday): array
    {
        $topics = $this->listTopics($slug);
        $points = 0;
        foreach ($topics as $t) {
            $points += STATUS_POINTS[$t['status']] ?? 0;
        }
        $sinceStart = 0;
        $sinceEnd   = 0;
        foreach ($this->all(
            'SELECT from_status, to_status, changed_at FROM topic_changes
             WHERE subject_slug = ? AND changed_at >= ?',
            [$slug, $monday . ' 00:00:00']
        ) as $c) {
            $delta = (STATUS_POINTS[$c['to_status']] ?? 0)
                - (STATUS_POINTS[$c['from_status'] ?? ''] ?? 0);
            $sinceStart += $delta;
            if ((string) $c['changed_at'] > $sunday . ' 23:59:59') {
                $sinceEnd += $delta;
            }
        }
        $max = count($topics) * 3;
        $pct = static fn(int $p): int => $max > 0 ? (int) round(($p / $max) * 100) : 0;
        return [
            'pct_start' => $pct($points - $sinceStart),
            'pct_end'   => $pct($points - $sinceEnd),
            'topics'    => count($topics),
        ];
    }

    /** Every subject's topic changes inside the week, newest first. */
    private function weekChanges(string $monday, string $sunday): array
    {
        $out = [];
        foreach ($this->changesBetween($monday, $sunday) as $c) {
            $out[] = [
                'subject_slug' => $c['subject_slug'],
                'ref'          => $c['ref'],
                'topic_name'   => (string) ($c['topic_name'] ?? ''),
                'from_status'  => $c['from_status'],
                'to_status'    => $c['to_status'],
                'evidence'     => $c['evidence'],
                'session_id'   => $c['session_id'] === null ? null : (int) $c['session_id'],
                'changed_at'   => $c['changed_at'],
            ];
        }
        return $out;
    }

    /**
     * Attempts whose papers were sat inside the week. A paper's own sat_on
     * wins; without one it was sat the day of the attempt — the same rule the
     * board judges by.
     */
    private function attemptsSatBetween(string $from, string $to): array
    {
        $rows = $this->all(
            'SELECT a.* FROM attempts a
             WHERE EXISTS (SELECT 1 FROM attempt_papers p
                           WHERE p.attempt_id = a.id AND COALESCE(p.sat_on, a.date) BETWEEN ? AND ?)
             ORDER BY a.date, a.id',
            [$from, $to]
        );
        $out = [];
        foreach ($rows as $a) {
            $papers = [];
            foreach ($this->listPapers((int) $a['id']) as $p) {
                $papers[] = [
                    'code'   => $p['code'],
                    'score'  => (float) $p['score'],
                    'max'    => (float) $p['max'],
                    'blanks' => $p['blanks'] === null ? null : (int) $p['blanks'],
                    'sat_on' => $p['sat_on'],
                ];
            }
            $blanks = array_filter(array_column($papers, 'blanks'), static fn($b) => $b !== null);
            $out[]  = [
                'subject_slug' => $a['subject_slug'],
                'attempt_id'   => (int) $a['id'],
                'name'         => $a['name'],
                'kind'         => $a['kind'],
                'date'         => $a['date'],
                'score'        => array_sum(array_column($papers, 'score')),
                'max'          => array_sum(array_column($papers, 'max')),
                'blanks'       => $blanks ? array_sum($blanks) : null,
                'papers'       => $papers,
            ];
        }
        return $out;
    }

    /**
     * The timed handwritten blocks that were actually done this week, and the
     * last one before it.
     *
     * `minutes` is measured only when the evidence is a session that recorded
     * a duration; against a marked paper there is no duration in the record,
     * so the block's length stands in and is flagged — a page that prints a
     * block length as "25 minutes sustained" is inventing stamina data. `last`
     * walks back at most eight weeks and stops at the first hit.
     *
     * @param array<int,array<string,mixed>> $blocks flattenWeek()'s blocks
     * @return array{this_week:array<int,array<string,mixed>>,last:?array<string,mixed>}
     */
    private function timedFor(string $monday, array $blocks): array
    {
        $pick = fn(array $rows): array => array_map(
            [$this, 'timedDetail'],
            array_values(array_filter(
                $rows,
                static fn(array $b): bool => $b['kind'] === 'timed_handwritten' && $b['status'] === 'done'
            ))
        );

        $last = null;
        for ($back = 1; $back <= 8 && $last === null; $back++) {
            $earlier = $pick($this->flattenWeek(
                $this->judgeWeek(tt_add_days($monday, -7 * $back))['days']
            )['blocks']);
            if ($earlier) {
                $last = $earlier[count($earlier) - 1];
            }
        }
        return ['this_week' => $pick($blocks), 'last' => $last];
    }

    /** One done timed block, with its minutes measured or estimated and its blanks. */
    private function timedDetail(array $b): array
    {
        $ev      = $b['evidence'][0] ?? null;
        $out     = [
            'date'     => $b['date'],
            'label'    => $b['label'],
            'evidence' => null,
            'minutes'  => $b['length'],
            'measured' => false,
            'blanks'   => null,
        ];
        if (!$ev) {
            return $out;
        }
        if ($ev['type'] === 'session') {
            $row = $this->one('SELECT duration_minutes FROM sessions WHERE id = ?', [(int) $ev['id']]);
            if ($row && $row['duration_minutes'] !== null) {
                $out['minutes']  = (int) $row['duration_minutes'];
                $out['measured'] = true;
            }
            $out['evidence'] = 'session #' . $ev['id'];
            return $out;
        }
        if ($ev['type'] === 'attempt') {
            // The evidence id of an attempt block is the paper's: the paper is
            // the thing with a date and a blanks count of its own.
            $row = $this->one(
                'SELECT p.blanks, p.code, a.name FROM attempt_papers p
                 JOIN attempts a ON a.id = p.attempt_id WHERE p.id = ?',
                [(int) $ev['id']]
            );
            $out['blanks']   = $row && $row['blanks'] !== null ? (int) $row['blanks'] : null;
            $out['evidence'] = 'attempt paper #' . $ev['id']
                . ($row ? ' — ' . trim($row['name'] . ' ' . $row['code']) : '');
            return $out;
        }
        $out['evidence'] = $ev['type'] . ' #' . ($ev['id'] ?? '');
        return $out;
    }


    // ---- lesson reviews ------------------------------------------------------
    //
    // The written half of one taught session, beside the weekly review's
    // written half of one week. Same rules: versions are appended and never
    // edited, the snapshot is the server's, and a draft cannot be saved over
    // a version the audit or the parent has already written. The review
    // proposes; the record adjudicates — nothing here moves a topic status.

    /**
     * Whether a session needs a lesson review, and why.
     *
     * Decided from the block's kind when the session names a block (a
     * block_kind_rules row, so the answer is configuration), and from the
     * duration when it does not: an extra that ran REVIEW_EXTRA_MINUTES or
     * longer was a taught session whatever the timetable said.
     *
     * @return array{required:bool,reason:string,kind:?string}
     */
    public function reviewRequiredFor(?int $blockKey, string $date, ?int $minutes): array
    {
        if ($blockKey !== null) {
            $kind = null;
            $v = $this->timetableVersionOn($date);
            if ($v) {
                $weekday = (int) (new DateTimeImmutable($date, tt_zone()))->format('N');
                foreach ($this->timetableBlocks((int) $v['id']) as $b) {
                    if ($b['block_key'] === $blockKey && $b['weekday'] === $weekday) {
                        $kind = (string) $b['kind'];
                        break;
                    }
                }
            }
            if ($kind !== null) {
                $rules = $this->blockKindRules();
                $rule  = $rules[$kind] ?? $rules['*'] ?? null;
                $req   = $rule !== null && !empty($rule['review_required']);
                return [
                    'required' => $req,
                    'reason'   => "block $blockKey is kind $kind, which " . ($req ? 'requires' : 'does not require')
                        . ' a review',
                    'kind'     => $kind,
                ];
            }
        }
        if ($minutes !== null && $minutes >= REVIEW_EXTRA_MINUTES) {
            return ['required' => true, 'kind' => null,
                    'reason' => "a session outside the timetable of $minutes minutes requires a review"];
        }
        return ['required' => false, 'kind' => null,
                'reason' => 'no block kind requires one and the session ran under ' . REVIEW_EXTRA_MINUTES . ' minutes'];
    }

    /** Set the flag on a session row; called once at log time. */
    public function setReviewRequired(int $sessionId, bool $required): void
    {
        $st = $this->db->prepare('UPDATE sessions SET review_required = ? WHERE id = ?');
        $st->execute([(int) $required, $sessionId]);
    }

    /**
     * The server's account of one session at the moment a review is saved.
     *
     * Frozen beside the review so it can never disagree with the record
     * about the lesson it describes: the session row, the block it ran
     * against and how the board judged it, the status of every ref the
     * review mentions before and after the session's updates, the retrieval
     * outcomes recorded, the practice runs logged that day for the subject,
     * and the open unfinished item.
     *
     * `$before` and `$outcomes` are supplied by tracker_log_session, which
     * has them in hand; a re-versioning derives them from the session's
     * topic_changes and its previous snapshot instead.
     *
     * @param array<int,string>          $refs     every ref the review mentions
     * @param ?array<string,?string>     $before   ref => status before the session's updates
     * @param ?array<string,string>      $outcomes ref => retrieval_outcome recorded
     */
    public function lessonSnapshot(int $sessionId, array $refs, ?array $before = null, ?array $outcomes = null): array
    {
        $s = $this->sessionById($sessionId);
        if (!$s) {
            throw new InvalidArgumentException("No session $sessionId.");
        }
        $slug = (string) $s['subject_slug'];
        $date = (string) $s['date'];

        $block = null;
        if ($s['block_key'] !== null) {
            $key = (int) $s['block_key'];
            foreach ($this->judgeDay($date)['blocks'] as $b) {
                if ($b['block_key'] === $key) {
                    $block = [
                        'block_key' => $key, 'kind' => $b['kind'], 'label' => $b['label'],
                        'status' => $b['status'], 'shape' => $b['shape'] ?? null,
                        'shape_reason' => $b['shape_reason'] ?? null, 'length' => $b['length'],
                    ];
                    break;
                }
            }
        }

        $changes = $this->changesForSession($sessionId);
        $first   = [];
        $last    = [];
        foreach ($changes as $c) {
            $first[(string) $c['ref']] ??= $c;
            $last[(string) $c['ref']]    = $c;
        }
        $statuses = [];
        foreach (array_values(array_unique(array_merge($refs, array_keys($last)))) as $ref) {
            $topic = $this->getTopic($slug, $ref);
            $now   = $topic ? (string) $topic['status'] : null;
            $statuses[$ref] = [
                'before' => $before !== null && array_key_exists($ref, $before)
                    ? $before[$ref]
                    : (isset($first[$ref]) ? $first[$ref]['from_status'] : $now),
                'after'  => isset($last[$ref]) ? (string) $last[$ref]['to_status'] : $now,
                'moved'  => isset($last[$ref]) && (string) $first[$ref]['from_status'] !== (string) $last[$ref]['to_status'],
                'watch'  => $topic ? ($topic['watch'] ?? null) : null,
            ];
        }

        if ($outcomes === null) {
            // The retrieval history is dated, not attributed to a session:
            // an outcome recorded on the session's date at topic grain is
            // the best the record can say.
            $outcomes = [];
            foreach (array_keys($statuses) as $ref) {
                $row = $this->one(
                    "SELECT history FROM retrieval_state WHERE subject_slug = ? AND grain = 'topic' AND key = ?",
                    [$slug, $ref]
                );
                foreach (json_decode((string) ($row['history'] ?? '[]'), true) ?: [] as $h) {
                    if (($h['d'] ?? null) === $date) {
                        $outcomes[$ref] = (string) $h['o'];
                    }
                }
            }
        }

        $practice = [];
        foreach ($this->listPracticeRuns($slug, ['since' => $date, 'until' => $date]) as $r) {
            $practice[] = [
                'id' => (int) $r['id'], 'source' => $r['source'], 'label' => $r['label'],
                'attempted' => (int) $r['attempted'], 'correct' => (int) $r['correct'],
                'correct_after_retry' => (int) $r['correct_after_retry'], 'incorrect' => (int) $r['incorrect'],
            ];
        }

        return [
            'schema'      => 1,
            'captured_at' => gmdate('Y-m-d H:i:s'),
            'session'     => [
                'id'               => $sessionId,
                'subject_slug'     => $slug,
                'date'             => $date,
                'block_key'        => $s['block_key'] === null ? null : (int) $s['block_key'],
                'block_kind'       => $block['kind'] ?? null,
                'duration_minutes' => $s['duration_minutes'] === null ? null : (int) $s['duration_minutes'],
                'review_required'  => (int) ($s['review_required'] ?? 0) === 1,
                'summary'          => (string) $s['summary'],
                'next_steps'       => $s['next_steps'],
                'void_reason'      => $s['void_reason'],
            ],
            'block'       => $block,
            'statuses'    => $statuses,
            'changes'     => array_map(static fn(array $c): array => [
                'ref' => $c['ref'], 'from' => $c['from_status'], 'to' => $c['to_status'], 'evidence' => $c['evidence'],
            ], $changes),
            'outcomes'    => $outcomes,
            'practice'    => $practice,
            'unfinished'  => $s['unfinished'] !== null && $s['unfinished_closed_at'] === null
                ? (string) $s['unfinished'] : null,
        ];
    }

    /**
     * Append one version of a session's review and point the session at it.
     *
     * As addWeeklyReview: the version is allocated under the same write lock
     * that inserts the row, and an identical re-save — same stage, same
     * written_by, byte-identical sections — returns the version already
     * there rather than growing the ledger.
     *
     * @param array{session_id:int,subject_slug:string,stage:string,written_by:string,
     *              snapshot:array,sections:array,note?:?string} $r
     * @return array{status:'stored'|'duplicate',row:array<string,mixed>}
     */
    public function addLessonReview(array $r): array
    {
        $sessionId = (int) $r['session_id'];
        $sections  = self::reviewJson($r['sections']);
        $snapshot  = self::reviewJson($r['snapshot']);

        return $this->transaction(function () use ($sessionId, $sections, $snapshot, $r): array {
            $latest = $this->one(
                'SELECT * FROM lesson_reviews WHERE session_id = ? ORDER BY version DESC LIMIT 1',
                [$sessionId]
            );
            if (
                $latest !== null
                && (string) $latest['stage'] === (string) $r['stage']
                && (string) $latest['written_by'] === (string) $r['written_by']
                && (string) $latest['sections_json'] === $sections
            ) {
                return ['status' => 'duplicate', 'row' => $this->hydrateLessonReview($latest)];
            }
            $version = $latest === null ? 1 : (int) $latest['version'] + 1;
            $st = $this->db->prepare(
                'INSERT INTO lesson_reviews
                   (session_id, subject_slug, version, stage, written_by, snapshot_json, sections_json, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $sessionId, $r['subject_slug'], $version, $r['stage'], $r['written_by'],
                $snapshot, $sections, $r['note'] ?? null,
            ]);
            $id = (int) $this->db->lastInsertId();
            $st = $this->db->prepare('UPDATE sessions SET review_id = ? WHERE id = ?');
            $st->execute([$id, $sessionId]);
            return [
                'status' => 'stored',
                'row'    => $this->hydrateLessonReview($this->one('SELECT * FROM lesson_reviews WHERE id = ?', [$id])),
            ];
        });
    }

    /** The latest version for a session, or one named version. Null when unreviewed. */
    public function lessonReview(int $sessionId, ?int $version = null): ?array
    {
        $row = $version === null
            ? $this->one('SELECT * FROM lesson_reviews WHERE session_id = ? ORDER BY version DESC LIMIT 1', [$sessionId])
            : $this->one('SELECT * FROM lesson_reviews WHERE session_id = ? AND version = ?', [$sessionId, $version]);
        return $row === null ? null : $this->hydrateLessonReview($row);
    }

    /** @return array<int,array{id:int,version:int,stage:string,written_at:string,written_by:string,note:?string}> */
    public function lessonReviewVersions(int $sessionId): array
    {
        $rows = $this->all(
            'SELECT id, version, stage, written_at, written_by, note FROM lesson_reviews
             WHERE session_id = ? ORDER BY version',
            [$sessionId]
        );
        foreach ($rows as &$r) {
            $r['id']      = (int) $r['id'];
            $r['version'] = (int) $r['version'];
        }
        return $rows;
    }

    /**
     * The latest review of each of several sessions, one query.
     *
     * @param  array<int,int> $sessionIds
     * @return array<int,array<string,mixed>> session_id => latest row
     */
    public function lessonReviewsForSessions(array $sessionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $sessionIds)));
        if (!$ids) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all(
            "SELECT * FROM lesson_reviews WHERE session_id IN ($in) ORDER BY session_id, version", $ids
        ) as $row) {
            $out[(int) $row['session_id']] = $this->hydrateLessonReview($row);
        }
        return $out;
    }

    /**
     * Reviews of a subject, newest session first, each with its session row.
     *
     * @param array{since?:string,limit?:int,stage?:string} $filter
     * @return array<int,array{session:array,review:array}>
     */
    public function listLessonReviews(string $slug, array $filter = []): array
    {
        $sql    = 'SELECT s.* FROM sessions s
                   WHERE s.subject_slug = ? AND s.review_id IS NOT NULL AND s.void_reason IS NULL';
        $params = [$slug];
        if (!empty($filter['since'])) {
            $sql .= ' AND s.date >= ?';
            $params[] = $filter['since'];
        }
        $sql .= ' ORDER BY s.date DESC, s.id DESC';
        $rows  = $this->all($sql, $params);
        $byId  = $this->lessonReviewsForSessions(array_map(static fn($s) => (int) $s['id'], $rows));
        $out   = [];
        $limit = (int) ($filter['limit'] ?? 10);
        foreach ($rows as $s) {
            $review = $byId[(int) $s['id']] ?? null;
            if ($review === null) {
                continue;
            }
            if (!empty($filter['stage']) && $review['stage'] !== $filter['stage']) {
                continue;
            }
            $out[] = ['session' => $s, 'review' => $review];
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Sessions that need a review and have none, oldest first. Void sessions
     * are skipped: a session logged in error is not owed a review.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sessionsMissingReview(string $slug, ?string $since = null, ?string $createdAfter = null): array
    {
        $sql    = 'SELECT * FROM sessions WHERE subject_slug = ? AND review_required = 1 AND review_id IS NULL
                   AND void_reason IS NULL';
        $params = [$slug];
        if ($since !== null) {
            $sql .= ' AND date >= ?';
            $params[] = $since;
        }
        if ($createdAfter !== null) {
            $sql .= ' AND created_at > ?';
            $params[] = $createdAfter;
        }
        return $this->all($sql . ' ORDER BY date, id', $params);
    }

    /** The most recent reviewed session of a subject, with its review: what the next session opens on. */
    public function lastLessonReview(string $slug): ?array
    {
        $rows = $this->listLessonReviews($slug, ['limit' => 1]);
        return $rows[0] ?? null;
    }

    /** The session that followed a given one in its subject, if any. */
    public function sessionAfter(array $s): ?array
    {
        return $this->one(
            'SELECT * FROM sessions WHERE subject_slug = ? AND void_reason IS NULL
               AND (date > ? OR (date = ? AND id > ?))
             ORDER BY date, id LIMIT 1',
            [$s['subject_slug'], $s['date'], $s['date'], (int) $s['id']]
        );
    }

    /** How many non-void sessions of a subject came after a given one. */
    public function sessionsSince(string $slug, string $date, int $id): int
    {
        $row = $this->one(
            'SELECT COUNT(*) AS n FROM sessions WHERE subject_slug = ? AND void_reason IS NULL
               AND (date > ? OR (date = ? AND id > ?))',
            [$slug, $date, $date, $id]
        );
        return (int) ($row['n'] ?? 0);
    }

    private function hydrateLessonReview(array $r): array
    {
        $r['id']         = (int) $r['id'];
        $r['session_id'] = (int) $r['session_id'];
        $r['version']    = (int) $r['version'];
        $r['snapshot']   = json_decode((string) $r['snapshot_json'], true) ?: [];
        $r['sections']   = json_decode((string) $r['sections_json'], true) ?: [];
        return $r;
    }

    /**
     * How the record has moved since a review was written: each mentioned
     * ref's status now against the snapshot's `after`, whether each watch
     * signal the review opened has been observed since, and whether a
     * following session has a review of its own to answer `check_whether`.
     *
     * @return array{refs:array<int,array{ref:string,then:?string,now:?string,moved:bool}>,
     *               watch:array<int,array{key:string,observed:bool,by_session:?int}>,
     *               following:?array{session_id:int,date:string,reviewed:bool,one_sentence:?string},
     *               lines:array<int,string>}
     */
    public function lessonReviewDrift(array $review): array
    {
        $snap = $review['snapshot'];
        $slug = (string) ($snap['session']['subject_slug'] ?? $review['subject_slug']);
        $sid  = (int) $review['session_id'];
        $refs = [];
        foreach ($snap['statuses'] ?? [] as $ref => $st) {
            $topic = $this->getTopic($slug, (string) $ref);
            $now   = $topic ? (string) $topic['status'] : null;
            $refs[] = ['ref' => (string) $ref, 'then' => $st['after'] ?? null, 'now' => $now,
                       'moved' => ($st['after'] ?? null) !== $now];
        }
        $watch = [];
        foreach ($review['sections']['watch'] ?? [] as $w) {
            $g = $this->signalByKey($slug, (string) $w['key']);
            $observed = null;
            if ($g) {
                $observed = $this->one(
                    'SELECT session_id FROM review_signal_evidence WHERE signal_id = ? AND session_id <> ?
                     ORDER BY session_id LIMIT 1',
                    [(int) $g['id'], $sid]
                );
            }
            $watch[] = ['key' => (string) $w['key'], 'observed' => $observed !== null,
                        'by_session' => $observed ? (int) $observed['session_id'] : null,
                        'status' => $g['status'] ?? null];
        }
        $session   = $this->sessionById($sid);
        $following = null;
        if ($session) {
            $next = $this->sessionAfter($session);
            if ($next) {
                $nr = $this->lessonReview((int) $next['id']);
                $following = [
                    'session_id'   => (int) $next['id'],
                    'date'         => (string) $next['date'],
                    'reviewed'     => $nr !== null,
                    'one_sentence' => $nr['sections']['one_sentence'] ?? null,
                ];
            }
        }

        $lines = [];
        $moved = array_values(array_filter($refs, static fn(array $r): bool => $r['moved']));
        $lines[] = $moved
            ? 'Statuses since: ' . implode('; ', array_map(
                static fn(array $r): string => $r['ref'] . ' ' . ($r['then'] ?? '—') . ' → ' . ($r['now'] ?? '—'), $moved
            )) . '.'
            : 'No mentioned topic has changed status since.';
        foreach ($watch as $w) {
            $lines[] = 'Watch ' . $w['key'] . ': ' . ($w['observed']
                ? 'observed in session ' . $w['by_session'] : 'not observed since')
                . ($w['status'] !== null && $w['status'] !== 'open' ? ' (' . $w['status'] . ')' : '') . '.';
        }
        $check = $review['sections']['planner']['check_whether'] ?? null;
        if ($following === null) {
            $lines[] = 'No later session yet' . ($check ? ", so check_whether ('$check') is still open" : '') . '.';
        } elseif (!$following['reviewed']) {
            $lines[] = 'The following session (' . $following['session_id'] . ', ' . $following['date']
                . ') has no review' . ($check ? ", so check_whether ('$check') is unanswered" : '') . '.';
        } else {
            $lines[] = 'The following session (' . $following['session_id'] . ', ' . $following['date']
                . ') reviewed: "' . $following['one_sentence'] . '"'
                . ($check ? " — read it against check_whether ('$check')" : '') . '.';
        }
        return ['refs' => $refs, 'watch' => $watch, 'following' => $following, 'lines' => $lines];
    }

    // ---- signals ---------------------------------------------------------

    /**
     * Create or strengthen one signal from a review.
     *
     * A new key opens the row at one_off with this session as its opening
     * evidence. An existing key gains an evidence row for this session and
     * the requested strength if the count rule allows it; a claim the rows
     * do not support is reported back with the counts and the strength is
     * left where it was — never raised past the evidence, never silently.
     *
     * @param array{subject_slug:?string,key:string,kind:string,statement:string,strength:string,
     *              next_test?:?string,session_id:int,direction?:string,evidence:string} $g
     * @return array{id:int,created:bool,strength_from:?string,strength_to:string,refused:?string}
     */
    public function upsertSignal(array $g): array
    {
        $slug      = $g['subject_slug'] ?? null;
        $key       = (string) $g['key'];
        $sessionId = (int) $g['session_id'];
        $direction = (string) ($g['direction'] ?? 'supports');
        $wanted    = (string) $g['strength'];

        return $this->transaction(function () use ($slug, $key, $sessionId, $direction, $wanted, $g): array {
            $row = $this->signalByKey($slug, $key);
            if ($row === null) {
                $st = $this->db->prepare(
                    'INSERT INTO review_signals
                       (subject_slug, kind, key, statement, strength, status, next_test, opened_session)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$slug, $g['kind'], $key, $g['statement'], 'one_off', 'open',
                              $g['next_test'] ?? null, $sessionId]);
                $id = (int) $this->db->lastInsertId();
                $this->writeSignalEvidence($id, $sessionId, $direction, (string) $g['evidence']);
                $this->signalEvent($id, $sessionId, 'opened', null, 'one_off', $g['statement']);
                $refused = null;
                if ($wanted !== 'one_off') {
                    $refused = signal_strength_refusal($wanted, $this->signalEvidence($id));
                }
                return ['id' => $id, 'created' => true, 'strength_from' => null, 'strength_to' => 'one_off',
                        'refused' => $refused];
            }

            $id   = (int) $row['id'];
            $from = (string) $row['strength'];
            $this->writeSignalEvidence($id, $sessionId, $direction, (string) $g['evidence']);
            $this->signalEvent($id, $sessionId, 'evidence', null, $direction, (string) $g['evidence']);
            $refused = signal_strength_refusal($wanted, $this->signalEvidence($id));
            $to      = $refused === null ? $wanted : $from;
            $sets    = ['updated_at' => tt_now_utc()];
            if ($to !== $from) {
                $sets['strength'] = $to;
                $this->signalEvent($id, $sessionId, 'strength', $from, $to, null);
            }
            if (array_key_exists('next_test', $g) && $g['next_test'] !== null && $g['next_test'] !== $row['next_test']) {
                $sets['next_test'] = $g['next_test'];
                $this->signalEvent($id, $sessionId, 'next_test', $row['next_test'], $g['next_test'], null);
            }
            // The statement is the claim in evidence language; a later
            // review may sharpen it. The key is what stays stable.
            if (!empty($g['statement']) && $g['statement'] !== $row['statement']) {
                $sets['statement'] = $g['statement'];
            }
            $assign = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($sets)));
            $st = $this->db->prepare("UPDATE review_signals SET $assign WHERE id = ?");
            $st->execute(array_merge(array_values($sets), [$id]));
            return ['id' => $id, 'created' => false, 'strength_from' => $from, 'strength_to' => $to,
                    'refused' => $refused];
        });
    }

    /** One evidence row per (signal, session); a second write from the same session replaces the first. */
    private function writeSignalEvidence(int $signalId, int $sessionId, string $direction, string $evidence): void
    {
        $st = $this->db->prepare(
            'INSERT INTO review_signal_evidence (signal_id, session_id, direction, evidence) VALUES (?, ?, ?, ?)
             ON CONFLICT(signal_id, session_id) DO UPDATE SET direction = excluded.direction,
               evidence = excluded.evidence'
        );
        $st->execute([$signalId, $sessionId, $direction, $evidence]);
    }

    private function signalEvent(int $signalId, ?int $sessionId, string $change, ?string $from, ?string $to, ?string $detail): void
    {
        $st = $this->db->prepare(
            'INSERT INTO review_signal_events (signal_id, session_id, at, change, from_value, to_value, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$signalId, $sessionId, tt_now_utc(), $change, $from, $to, $detail]);
    }

    /**
     * Add an evidence row to an existing signal, outside a review. The
     * strength is then re-derived downwards only: a contradiction can cost
     * an established signal its standing; nothing here raises one.
     *
     * @return array{strength_from:string,strength_to:string}
     */
    public function addSignalEvidence(int $signalId, int $sessionId, string $direction, string $evidence): array
    {
        return $this->transaction(function () use ($signalId, $sessionId, $direction, $evidence): array {
            $row = $this->signalById($signalId);
            if (!$row) {
                throw new InvalidArgumentException("No signal $signalId.");
            }
            $this->writeSignalEvidence($signalId, $sessionId, $direction, $evidence);
            $this->signalEvent($signalId, $sessionId, 'evidence', null, $direction, $evidence);
            $from    = (string) $row['strength'];
            $allowed = signal_strength_allowed($this->signalEvidence($signalId));
            $to      = review_status_rank_of_strength($allowed) < review_status_rank_of_strength($from) ? $allowed : $from;
            $st = $this->db->prepare('UPDATE review_signals SET strength = ?, updated_at = ? WHERE id = ?');
            $st->execute([$to, tt_now_utc(), $signalId]);
            if ($to !== $from) {
                $this->signalEvent($signalId, $sessionId, 'strength', $from, $to, 'contradicted');
            }
            return ['strength_from' => $from, 'strength_to' => $to];
        });
    }

    /**
     * Move a signal: resolve or refute it, or set its next test. Strength is
     * never an argument here — it is derived from the evidence rows.
     *
     * @param array{status?:string,evidence?:string,next_test?:?string,session_id?:?int} $c
     */
    public function updateSignal(int $signalId, array $c): array
    {
        return $this->transaction(function () use ($signalId, $c): array {
            $row = $this->signalById($signalId);
            if (!$row) {
                throw new InvalidArgumentException("No signal $signalId.");
            }
            $sets = ['updated_at' => tt_now_utc()];
            $sid  = isset($c['session_id']) ? (int) $c['session_id'] : null;
            if (isset($c['status']) && $c['status'] !== $row['status']) {
                $sets['status'] = $c['status'];
                $this->signalEvent($signalId, $sid, 'status', (string) $row['status'], (string) $c['status'],
                    $c['evidence'] ?? null);
            }
            if (array_key_exists('next_test', $c) && $c['next_test'] !== $row['next_test']) {
                $sets['next_test'] = $c['next_test'];
                $this->signalEvent($signalId, $sid, 'next_test', $row['next_test'], $c['next_test'], null);
            }
            $assign = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($sets)));
            $st = $this->db->prepare("UPDATE review_signals SET $assign WHERE id = ?");
            $st->execute(array_merge(array_values($sets), [$signalId]));
            return $this->signalById($signalId);
        });
    }

    public function signalById(int $id): ?array
    {
        $row = $this->one('SELECT * FROM review_signals WHERE id = ?', [$id]);
        return $row === null ? null : $this->hydrateSignal($row);
    }

    public function signalByKey(?string $slug, string $key): ?array
    {
        $row = $this->one(
            "SELECT * FROM review_signals WHERE COALESCE(subject_slug, '') = ? AND key = ?",
            [(string) $slug, $key]
        );
        return $row === null ? null : $this->hydrateSignal($row);
    }

    /**
     * The evidence rows of a signal with each session's date, oldest first —
     * the shape signal_strength_refusal() reads.
     *
     * @return array<int,array{signal_id:int,session_id:int,date:string,direction:string,evidence:string}>
     */
    public function signalEvidence(int $signalId): array
    {
        $rows = $this->all(
            'SELECT e.*, s.date FROM review_signal_evidence e JOIN sessions s ON s.id = e.session_id
             WHERE e.signal_id = ? ORDER BY s.date, e.session_id',
            [$signalId]
        );
        foreach ($rows as &$r) {
            $r['signal_id']  = (int) $r['signal_id'];
            $r['session_id'] = (int) $r['session_id'];
        }
        return $rows;
    }

    /**
     * The spine, filtered. A subject filter returns that subject's signals
     * and then the cross-subject ones, which belong to the learner rather
     * than to any subject.
     *
     * @param array{subject?:?string,kind?:string,status?:string,min_strength?:string,
     *              include_cross?:bool} $filter
     * @return array<int,array<string,mixed>>
     */
    public function signals(array $filter = []): array
    {
        $sql    = 'SELECT * FROM review_signals WHERE 1 = 1';
        $params = [];
        if (array_key_exists('subject', $filter) && $filter['subject'] !== null) {
            if (!empty($filter['include_cross'])) {
                $sql .= ' AND (subject_slug = ? OR subject_slug IS NULL)';
            } else {
                $sql .= ' AND subject_slug = ?';
            }
            $params[] = $filter['subject'];
        }
        if (!empty($filter['kind'])) {
            $sql .= ' AND kind = ?';
            $params[] = $filter['kind'];
        }
        if (!empty($filter['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $filter['status'];
        }
        $rows = array_map([$this, 'hydrateSignal'], $this->all(
            $sql . " ORDER BY CASE WHEN subject_slug IS NULL THEN 1 ELSE 0 END,
                     CASE strength WHEN 'established' THEN 0 WHEN 'emerging' THEN 1 ELSE 2 END,
                     kind, updated_at DESC, id DESC",
            $params
        ));
        if (!empty($filter['min_strength'])) {
            $min  = review_status_rank_of_strength((string) $filter['min_strength']);
            $rows = array_values(array_filter(
                $rows, static fn(array $g): bool => review_status_rank_of_strength($g['strength']) >= $min
            ));
        }
        return $rows;
    }

    /** Signals with an evidence row from one session, with each row's direction. */
    public function signalsTouchedBy(int $sessionId): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT g.*, e.direction AS touched_direction, e.evidence AS touched_evidence
             FROM review_signal_evidence e JOIN review_signals g ON g.id = e.signal_id
             WHERE e.session_id = ? ORDER BY g.kind, g.key',
            [$sessionId]
        ) as $r) {
            $g = $this->hydrateSignal($r);
            $g['touched_direction'] = (string) $r['touched_direction'];
            $g['touched_evidence']  = (string) $r['touched_evidence'];
            $out[] = $g;
        }
        return $out;
    }

    private function hydrateSignal(array $r): array
    {
        $r['id']             = (int) $r['id'];
        $r['opened_session'] = (int) $r['opened_session'];
        $r['subject_slug']   = $r['subject_slug'] === null || $r['subject_slug'] === '' ? null : (string) $r['subject_slug'];
        $counts = $this->one(
            "SELECT SUM(CASE WHEN direction = 'supports' THEN 1 ELSE 0 END) AS supporting,
                    SUM(CASE WHEN direction = 'contradicts' THEN 1 ELSE 0 END) AS contradicting,
                    MAX(session_id) AS last_session
             FROM review_signal_evidence WHERE signal_id = ?",
            [$r['id']]
        );
        $r['supporting']    = (int) ($counts['supporting'] ?? 0);
        $r['contradicting'] = (int) ($counts['contradicting'] ?? 0);
        $r['last_session']  = $counts['last_session'] === null ? null : (int) $counts['last_session'];
        return $r;
    }

    /**
     * Signal movement inside a date range, oldest first: openings, strength
     * changes, resolutions and refutations. Evidence rows and next_test
     * edits are not movement and are left out.
     *
     * @return array<int,array<string,mixed>>
     */
    public function signalEventsBetween(string $from, string $to, ?string $slug = null): array
    {
        // A movement made by a session's review belongs to the session's
        // date, not to the clock the review was written on: an audit that
        // strengthens a Thursday signal on Monday still reports under the
        // Thursday's week. Movement with no session (a parent's decision)
        // takes its own stamp.
        $sql    = "SELECT ev.*, g.key, g.kind, g.statement, g.subject_slug, COALESCE(s.date, date(ev.at)) AS on_date
                   FROM review_signal_events ev JOIN review_signals g ON g.id = ev.signal_id
                   LEFT JOIN sessions s ON s.id = ev.session_id
                   WHERE ev.change IN ('opened','strength','status') AND COALESCE(s.date, date(ev.at)) BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($slug !== null) {
            $sql .= ' AND (g.subject_slug = ? OR g.subject_slug IS NULL)';
            $params[] = $slug;
        }
        return $this->all($sql . ' ORDER BY on_date, ev.at, ev.id', $params);
    }

    // ---- error rows ------------------------------------------------------

    /**
     * Store a review's error analysis as rows, so a topic's page can say
     * "three of the last four errors on A17 were procedure errors".
     *
     * @param array<int,array{ref:string,error_type:string,what:string,why_type:string,response:string}> $errors
     */
    public function addReviewErrors(int $sessionId, string $slug, array $errors): int
    {
        return $this->transaction(function () use ($sessionId, $slug, $errors): int {
            // A re-versioned review replaces the session's error rows rather
            // than doubling them: the latest version is the analysis.
            $st = $this->db->prepare('DELETE FROM review_errors WHERE session_id = ?');
            $st->execute([$sessionId]);
            $ins = $this->db->prepare(
                'INSERT INTO review_errors (session_id, subject_slug, ref, error_type, what, why_type, response)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($errors as $e) {
                $ins->execute([$sessionId, $slug, $e['ref'], $e['error_type'], $e['what'], $e['why_type'], $e['response']]);
            }
            return count($errors);
        });
    }

    /** Error rows for one topic, newest session first. */
    public function reviewErrorsForRef(string $slug, string $ref, int $limit = 20): array
    {
        return $this->all(
            'SELECT e.*, s.date FROM review_errors e JOIN sessions s ON s.id = e.session_id
             WHERE e.subject_slug = ? AND e.ref = ? AND s.void_reason IS NULL
             ORDER BY s.date DESC, e.session_id DESC, e.id DESC LIMIT ?',
            [$slug, $ref, $limit]
        );
    }

    /** Error rows a session's review recorded. */
    public function reviewErrorsForSession(int $sessionId): array
    {
        return $this->all('SELECT * FROM review_errors WHERE session_id = ? ORDER BY id', [$sessionId]);
    }

    /** @return array<string,int> error_type => count, most common first */
    public function errorTally(string $slug, string $ref): array
    {
        $out = [];
        foreach ($this->all(
            'SELECT e.error_type, COUNT(*) AS n FROM review_errors e JOIN sessions s ON s.id = e.session_id
             WHERE e.subject_slug = ? AND e.ref = ? AND s.void_reason IS NULL
             GROUP BY e.error_type ORDER BY n DESC, e.error_type',
            [$slug, $ref]
        ) as $r) {
            $out[(string) $r['error_type']] = (int) $r['n'];
        }
        return $out;
    }

    // ---- the audit -------------------------------------------------------

    /** @return array{at:?string,note:?string} */
    public function auditStamp(string $slug): array
    {
        return ['at' => $this->meta("last_audit_$slug"), 'note' => $this->meta("last_audit_note_$slug")];
    }

    public function setAuditStamp(string $slug, string $note): string
    {
        $at = tt_now_utc();
        $this->setMeta("last_audit_$slug", $at);
        $this->setMeta("last_audit_note_$slug", $note);
        return $at;
    }

    /**
     * Everything the scheduled auditor needs, computed here so it is never
     * told what to look for: sessions owed a review, drafts to verify, the
     * consistency flags, and the previous audit's note.
     *
     * @return array{stamp:array{at:?string,note:?string},missing:array<int,array>,
     *               drafts:array<int,array{session:array,review:array}>,
     *               flags:array<int,array{code:string,text:string,session_id:?int,signal_id:?int}>}
     */
    public function reviewAuditQueue(string $slug): array
    {
        $stamp   = $this->auditStamp($slug);
        $since   = $stamp['at'];
        // Every session still owed a review is listed, whenever it was
        // logged: an audit that stamped past one must not hide it. The ones
        // from before the stamp are marked as carried over.
        $missing = $this->sessionsMissingReview($slug);
        foreach ($missing as &$m) {
            $m['carried_over'] = $since !== null && (string) $m['created_at'] <= $since;
        }
        unset($m);

        $drafts = [];
        foreach ($this->listLessonReviews($slug, ['limit' => 0, 'stage' => 'draft']) as $pair) {
            if ($since === null || (string) $pair['review']['written_at'] > $since) {
                $drafts[] = $pair;
            }
        }

        $flags = [];
        $flag  = static function (string $code, string $text, ?int $sid = null, ?int $gid = null) use (&$flags): void {
            $flags[] = ['code' => $code, 'text' => $text, 'session_id' => $sid, 'signal_id' => $gid];
        };

        // Review-versus-record checks, over every review written since the
        // stamp — a draft or an audited version alike, since the record can
        // move under either.
        foreach ($this->listLessonReviews($slug, ['limit' => 0]) as $pair) {
            $review = $pair['review'];
            if ($since !== null && (string) $review['written_at'] <= $since) {
                continue;
            }
            $sid      = (int) $review['session_id'];
            $sections = $review['sections'];
            $snap     = $review['snapshot'];
            $statuses = $snap['statuses'] ?? [];
            $byRef    = [];
            foreach ($sections['progress'] ?? [] as $p) {
                $byRef[(string) $p['ref']][] = $p;
                if (($p['status_seen'] ?? '') === 'secure' && empty($p['proposed_status'])
                    && empty($statuses[$p['ref']]['moved'])) {
                    $flag('secure_seen_not_moved', "session $sid: progress says {$p['ref']} was seen secure, the "
                        . 'status did not move and no proposed_status was given — either propose it or record what was seen',
                        $sid);
                }
            }
            foreach ($snap['changes'] ?? [] as $c) {
                $fromRank = review_status_rank($c['from']);
                $toRank   = review_status_rank($c['to']);
                if ($toRank > $fromRank) {
                    $numeric = false;
                    foreach ($byRef[(string) $c['ref']] ?? [] as $p) {
                        if (preg_match('/\d/', (string) $p['evidence'])) {
                            $numeric = true;
                        }
                    }
                    if (!$numeric) {
                        $flag('promotion_without_number', "session $sid: {$c['ref']} was promoted {$c['from']} → {$c['to']} "
                            . 'and the review\'s progress evidence carries no number — the bar cannot have been shown met',
                            $sid);
                    }
                    if ($toRank - $fromRank >= 2) {
                        $flag('two_level_promotion', "session $sid: {$c['ref']} rose two levels ({$c['from']} → {$c['to']}) "
                            . 'in one session', $sid);
                    }
                }
            }
            foreach (REVIEW_RETENTION as $list => $want) {
                foreach ($sections['retention'][$list] ?? [] as $x) {
                    if (($snap['outcomes'][$x['ref']] ?? null) !== $want) {
                        $flag('retention_without_outcome', "session $sid: retention lists {$x['ref']} as $list but the "
                            . "record holds no $want retrieval_outcome for it", $sid);
                    }
                }
            }
            $summary = mb_strtolower((string) ($snap['session']['summary'] ?? ''));
            foreach ($sections['learner_voice'] ?? [] as $v) {
                $q = mb_strtolower(trim((string) ($v['quote'] ?? '')));
                if ($q !== '' && mb_strlen($q) >= 12 && str_contains($summary, $q)) {
                    $flag('voice_in_summary', "session $sid: learner_voice quote \"{$v['quote']}\" also appears in the "
                        . 'session summary — check it is her words and not a paraphrase', $sid);
                }
            }
        }

        // Signal checks, over the subject's open signals and the cross-subject ones.
        foreach ($this->signals(['subject' => $slug, 'status' => 'open', 'include_cross' => true]) as $g) {
            $ev   = $this->signalEvidence($g['id']);
            $last = $ev ? $ev[count($ev) - 1] : null;
            if ($g['next_test'] !== null && $g['next_test'] !== '' && $last !== null) {
                $n = $this->sessionsSince($slug, (string) $last['date'], (int) $last['session_id']);
                if ($n >= REVIEW_TEST_STALE_SESSIONS) {
                    $flag('test_untested', "signal #{$g['id']} {$g['key']}: next_test '{$g['next_test']}' has been open "
                        . "for $n sessions with no evidence either way", null, $g['id']);
                }
            }
            if ($g['kind'] === 'watch' && count($ev) <= 1 && $last !== null) {
                $n = $this->sessionsSince($slug, (string) $last['date'], (int) $last['session_id']);
                if ($n >= REVIEW_WATCH_STALE_SESSIONS) {
                    $flag('watch_unreferenced', "signal #{$g['id']} watch {$g['key']} was opened $n sessions ago and "
                        . 'has never been referenced since — resolve it or observe it', null, $g['id']);
                }
            }
        }

        return ['stamp' => $stamp, 'missing' => $missing, 'drafts' => $drafts, 'flags' => $flags];
    }

    /**
     * Reviews of sessions dated inside a week, for the week report and the
     * week page, oldest first.
     *
     * @return array<int,array{session:array,review:array}>
     */
    public function lessonReviewsBetween(string $from, string $to, ?string $slug = null): array
    {
        $sql    = 'SELECT * FROM sessions WHERE review_id IS NOT NULL AND void_reason IS NULL AND date BETWEEN ? AND ?';
        $params = [$from, $to];
        if ($slug !== null) {
            $sql .= ' AND subject_slug = ?';
            $params[] = $slug;
        }
        $rows = $this->all($sql . ' ORDER BY date, id', $params);
        $byId = $this->lessonReviewsForSessions(array_map(static fn($s) => (int) $s['id'], $rows));
        $out  = [];
        foreach ($rows as $s) {
            if (isset($byId[(int) $s['id']])) {
                $out[] = ['session' => $s, 'review' => $byId[(int) $s['id']]];
            }
        }
        return $out;
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

/** Whole days from one local date to another; negative when $to is earlier. */
function tt_days_between(string $from, string $to): int
{
    try {
        $a = new DateTimeImmutable($from, tt_zone());
        $b = new DateTimeImmutable($to, tt_zone());
    } catch (Throwable) {
        return 0;
    }
    $d = $a->diff($b);
    return $d->invert ? -$d->days : $d->days;
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
