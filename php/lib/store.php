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
     * Schema changes that CREATE TABLE IF NOT EXISTS cannot express, applied
     * once and guarded so they are safe on every request. This runs against a
     * live database holding the only copy of the record, so each step checks
     * the current shape rather than assuming it.
     */
    private function migrate(): void
    {
        // Fast path: the marker is set, so there is nothing to do and no lock
        // to take. This runs on every single request.
        if ($this->meta('schema_attempts') !== null) {
            return;
        }

        // BEGIN IMMEDIATE takes the write lock before anything is read, so two
        // requests arriving together cannot both decide the migration is still
        // pending and run it twice. Everything below is one transaction: on a
        // database holding the only copy of the record, a half-applied
        // migration is worse than a failed one.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->meta('schema_attempts') !== null) {
                $this->db->exec('COMMIT');
                return;
            }

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

            $this->setMeta('schema_attempts', gmdate('c'));
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
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
                    'INSERT INTO attempt_papers (attempt_id, code, score, max, blanks, note, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $attemptId, $paper['code'], $paper['score'], $paper['max'],
                    $paper['blanks'] ?? null, $paper['note'] ?? null, $i,
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
