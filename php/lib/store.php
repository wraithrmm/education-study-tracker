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
    private const SCHEMA_VERSION = 3;

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

    public function upsertSubject(array $s): array
    {
        $st = $this->db->prepare(
            'INSERT INTO subjects (slug, name, spec_code, tier, exam_date, strands, boundaries, boundary_max, notes)
             VALUES (:slug, :name, :spec_code, :tier, :exam_date, :strands, :boundaries, :boundary_max, :notes)
             ON CONFLICT(slug) DO UPDATE SET
               name = excluded.name,
               spec_code = COALESCE(excluded.spec_code, subjects.spec_code),
               tier = COALESCE(excluded.tier, subjects.tier),
               exam_date = COALESCE(excluded.exam_date, subjects.exam_date),
               strands = excluded.strands,
               boundaries = excluded.boundaries,
               boundary_max = excluded.boundary_max,
               notes = COALESCE(excluded.notes, subjects.notes)'
        );
        $st->execute([
            ':slug'         => $s['slug'],
            ':name'         => $s['name'],
            ':spec_code'    => $s['spec_code'] ?? null,
            ':tier'         => $s['tier'] ?? null,
            ':exam_date'    => $s['exam_date'] ?? null,
            // JSON_FORCE_OBJECT keeps an empty map as {} rather than [], which
            // is what the Node version wrote and what hydrateSubject expects.
            ':strands'      => json_encode((object) ($s['strands'] ?? [])),
            ':boundaries'   => json_encode((object) ($s['boundaries'] ?? [])),
            ':boundary_max' => $s['boundary_max'] ?? 240,
            ':notes'        => $s['notes'] ?? null,
        ]);
        return $this->getSubject($s['slug']);
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
                       (attempt_id, code, score, max, blanks, note, sat_on, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $attemptId, $paper['code'], $paper['score'], $paper['max'],
                    $paper['blanks'] ?? null, $paper['note'] ?? null, $paper['sat_on'] ?? null, $i,
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
            'INSERT INTO sessions (subject_slug, date, summary, topics_touched, next_steps)
             VALUES (:subject_slug, :date, :summary, :topics_touched, :next_steps)'
        );
        $st->execute([
            ':subject_slug'   => $s['subject_slug'],
            ':date'           => $s['date'],
            ':summary'        => $s['summary'],
            ':topics_touched' => $s['topics_touched'] ?? null,
            ':next_steps'     => $s['next_steps'] ?? null,
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
                    correct_after_retry, incorrect, duration_seconds, metrics)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $slug, $clientId, $r['source'], $r['label'],
                $r['played_at'] ?? gmdate('Y-m-d H:i:s'),
                (int) $r['attempted'], (int) $r['correct'],
                (int) ($r['correct_after_retry'] ?? 0), (int) $r['incorrect'],
                $r['duration_seconds'] ?? null,
                json_encode((object) ($r['metrics'] ?? [])),
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
/**
 * The household's clock. Stored timestamps stay UTC — that is right for an
 * audit trail — but "today" and "this week" on a page have to agree with the
 * clock on the wall, or a session at half past midnight in summer files itself
 * under yesterday and a weekly count rolls over an hour late.
 */
const TRACKER_TZ = 'Europe/London';

function local_today(?int $at = null): string
{
    $now = new DateTimeImmutable('@' . ($at ?? time()));
    return $now->setTimezone(new DateTimeZone(TRACKER_TZ))->format('Y-m-d');
}

/** Monday of the local ISO week containing $date (defaults to today). */
function local_week_monday(?string $date = null): string
{
    $d = new DateTimeImmutable(($date ?? local_today()) . ' 12:00:00', new DateTimeZone(TRACKER_TZ));
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
}

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
