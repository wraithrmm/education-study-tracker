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
SQL;

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
        $watch = array_key_exists('watch', $args) && $args['watch'] !== null
            ? $args['watch']
            : (array_key_exists('watch', $args) ? null : $existing['watch']);

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare(
                'UPDATE topics SET status = ?, evidence = ?, watch = ?, last_touched = ?, updated_at = datetime(\'now\')
                 WHERE subject_slug = ? AND ref = ?'
            );
            $st->execute([$next, $args['evidence'], $watch, $touched, $args['subject_slug'], $args['ref']]);

            $st = $this->db->prepare(
                'INSERT INTO topic_changes (subject_slug, ref, from_status, to_status, evidence)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$args['subject_slug'], $args['ref'], $existing['status'], $next, $args['evidence']]);
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

    // ---- assessments and sessions ---------------------------------------

    public function listAssessments(string $slug): array
    {
        return $this->all(
            'SELECT * FROM assessments WHERE subject_slug = ? ORDER BY date DESC, id DESC',
            [$slug]
        );
    }

    public function addAssessment(array $a): int
    {
        $st = $this->db->prepare(
            'INSERT INTO assessments (subject_slug, date, name, kind, tier, score, max, blanks, note)
             VALUES (:subject_slug, :date, :name, :kind, :tier, :score, :max, :blanks, :note)'
        );
        $st->execute([
            ':subject_slug' => $a['subject_slug'],
            ':date'         => $a['date'],
            ':name'         => $a['name'],
            ':kind'         => $a['kind'],
            ':tier'         => $a['tier'],
            ':score'        => $a['score'],
            ':max'          => $a['max'],
            ':blanks'       => $a['blanks'] ?? null,
            ':note'         => $a['note'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function listSessions(string $slug, int $limit = 20): array
    {
        return $this->all(
            'SELECT * FROM sessions WHERE subject_slug = ? ORDER BY date DESC, id DESC LIMIT ?',
            [$slug, $limit]
        );
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
