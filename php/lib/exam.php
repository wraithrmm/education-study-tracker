<?php
/**
 * Exam skills — the constants, state rules and small helpers behind the
 * question bank and the timed test.
 *
 * Loaded by store.php, because the migration needs the vocabulary, so this
 * file depends on nothing but the clock helpers in store.php and h(). The
 * tools live in mcp_exam.php and the pages in dashboard_exam.php.
 *
 * Vocabulary:
 *   question — one exam-style question with its mark scheme, written by the
 *              parent's project and tagged by subject and topic refs.
 *   test     — one Wednesday sitting: a set of vetted questions in subject
 *              sections, one duration, one timer.
 *   sitting  — the test between Start and the deadline.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

/** The subject the Wednesday block belongs to. Questions are never for it. */
const EXAM_SUBJECT = 'exam-skills';

/** A question's life: written, checked, put in a test, sat, marked; or dropped. */
const EXAM_QUESTION_STATUSES = ['draft', 'vetted', 'scheduled', 'answered', 'marked', 'retired'];

/** A test's life. `open` is the sitting; `closed` is awaiting marking. */
const EXAM_TEST_STATUSES = ['ready', 'open', 'closed', 'marked'];

/** Who ended the sitting. The timer needs nobody. */
const EXAM_CLOSED_BY = ['timer', 'student', 'parent'];

/** Seconds after the deadline in which an in-flight save is still taken. */
const EXAM_GRACE_SECONDS = 10;

const EXAM_QUESTION_MAX  = 4000;
const EXAM_ANSWER_MAX    = 20000;
const EXAM_FEEDBACK_MAX  = 600;
const EXAM_MIN_MINUTES   = 10;
const EXAM_MAX_MINUTES   = 120;

/** Subjects whose questions get a "working" box beside the answer. */
const EXAM_WORKING_SUBJECTS = ['maths', 'computer-science'];

/** The `status` CHECK, built from the list so the two can never disagree. */
function exam_check_sql(string $col, array $values): string
{
    return "CHECK ($col IN (" . implode(',', array_map(
        static fn(string $v): string => "'" . $v . "'", $values
    )) . '))';
}

/** A stored UTC 'Y-m-d H:i:s' as seconds since the epoch. */
function exam_epoch(?string $utc): ?int
{
    if ($utc === null || $utc === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

/** Seconds since the epoch, on the tracker's clock (honours TRACKER_NOW). */
function exam_now(): int
{
    return tt_now()->getTimestamp();
}

/** A UTC stamp `$seconds` after another. */
function exam_add_seconds(string $utc, int $seconds): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')
        ->format('Y-m-d H:i:s');
}

/** Has the deadline (plus a grace) gone by? A test with no deadline has not started. */
function exam_deadline_passed(array $test, int $grace = 0): bool
{
    $deadline = exam_epoch($test['deadline_at'] ?? null);
    if ($deadline === null) {
        return false;
    }
    return exam_now() > $deadline + $grace;
}

/**
 * How long the sitting actually ran, in minutes. A test the timer closed
 * ran its full duration; one she submitted early ran less; one still open
 * past its deadline is treated as having run the full duration.
 */
function exam_sat_minutes(array $test): ?int
{
    $start = exam_epoch($test['started_at'] ?? null);
    if ($start === null) {
        return null;
    }
    $end = exam_epoch($test['closed_at'] ?? null) ?? exam_epoch($test['deadline_at'] ?? null);
    if ($end === null) {
        return null;
    }
    return max(1, (int) round(($end - $start) / 60));
}

/** The local date the sitting happened on — the date every record of it carries. */
function exam_sat_date(array $test): string
{
    if (!empty($test['started_at'])) {
        return tt_local((string) $test['started_at'])[0];
    }
    return (string) $test['scheduled_for'];
}

/** A blank is nothing written, or only whitespace. Working alone is not an answer. */
function exam_is_blank(?string $answer): bool
{
    return $answer === null || trim($answer) === '';
}

function exam_sit_token(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * The question labels of a test: 1..n continuous across the sections in
 * order, the way a paper numbers its questions. Labels are what the nav
 * strip shows and what the attempt records as the question number.
 *
 * @param  array<int,array{subject:string,question_ids:array<int,int>,minutes_guide?:?int}> $sections
 * @return array<int,array{section:string,question_id:int,position:int,label:string,minutes_guide:?int}>
 */
function exam_labels(array $sections): array
{
    $out = [];
    $n   = 0;
    foreach ($sections as $s) {
        foreach ($s['question_ids'] as $qid) {
            $n++;
            $out[] = [
                'section'       => (string) $s['subject'],
                'question_id'   => (int) $qid,
                'position'      => $n,
                'label'         => (string) $n,
                'minutes_guide' => isset($s['minutes_guide']) ? (int) $s['minutes_guide'] : null,
            ];
        }
    }
    return $out;
}

/**
 * The test as the student may see it. Everything the marker holds — the
 * scheme, the model answer, the marker's note, where the question came
 * from — is removed, and the score too until the test is marked. Every
 * render that is not the parent's goes through here, so a template cannot
 * leak by forgetting.
 */
function exam_student_view(array $test): array
{
    $marked = ($test['status'] ?? '') === 'marked';
    foreach ($test['sections'] ?? [] as &$section) {
        foreach ($section['questions'] as &$q) {
            unset($q['mark_scheme_md'], $q['model_answer_md'], $q['source_note'], $q['note']);
            if (isset($q['answer'])) {
                unset($q['answer']['marker_note']);
                if (!$marked) {
                    unset($q['answer']['score'], $q['answer']['student_feedback']);
                }
            }
        }
        unset($q);
    }
    unset($section);
    return $test;
}

/**
 * Why a student write (an answer, a submit) must be refused, or null when
 * it may go ahead. The rules: the test must be open, the token must be the
 * one issued at Start, and the deadline plus grace must not have passed.
 * The token is in the page, not a cookie, so a stranger with the link
 * cannot write into a sitting they did not start.
 */
function exam_guard_write(array $test, ?string $token, int $grace = EXAM_GRACE_SECONDS): ?string
{
    if (($test['status'] ?? '') !== 'open') {
        return 'the test is ' . ($test['status'] ?? 'unknown') . ', not open';
    }
    $issued = (string) ($test['sit_token'] ?? '');
    if ($issued === '' || $token === null || !hash_equals($issued, $token)) {
        return 'the token does not match this sitting';
    }
    if (exam_deadline_passed($test, $grace)) {
        return 'time is up';
    }
    return null;
}

/**
 * Restricted Markdown for question text, mark schemes and feedback.
 *
 * Escaped first, then a handful of forms are recognised on the escaped
 * text: fenced code, paragraphs, `-` and `1.` lists, **bold**, *italic*,
 * `code`, ^superscript and hard line breaks. No raw HTML survives, which
 * is the point: the text comes from a model writing into the record.
 */
function exam_md(?string $md): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $md);
    $text = h($text);
    $out  = '';
    $lines = explode("\n", $text);
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if (trim($line) === '') {
            $i++;
            continue;
        }
        // Fenced code, kept verbatim.
        if (preg_match('/^\s*```/', $line)) {
            $i++;
            $code = [];
            while ($i < $n && !preg_match('/^\s*```/', $lines[$i])) {
                $code[] = $lines[$i];
                $i++;
            }
            $i++;
            $out .= '<pre class="ex-code"><code>' . implode("\n", $code) . '</code></pre>';
            continue;
        }
        // A list: consecutive lines starting with "- " or "1. ".
        if (preg_match('/^\s*(-|\d+\.)\s+/', $line, $m)) {
            $ordered = $m[1] !== '-';
            $tag     = $ordered ? 'ol' : 'ul';
            $out    .= "<$tag>";
            while ($i < $n && preg_match('/^\s*(-|\d+\.)\s+(.*)$/', $lines[$i], $mm)) {
                $out .= '<li>' . exam_md_inline($mm[2]) . '</li>';
                $i++;
            }
            $out .= "</$tag>";
            continue;
        }
        // A paragraph: up to the next blank line, with hard breaks kept.
        $para = [];
        while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^\s*(```|-\s|\d+\.\s)/', $lines[$i])) {
            $para[] = exam_md_inline($lines[$i]);
            $i++;
        }
        $out .= '<p>' . implode('<br>', $para) . '</p>';
    }
    return $out;
}

/** The inline forms, on already-escaped text. */
function exam_md_inline(string $s): string
{
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
    $s = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $s) ?? $s;
    $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<i>$1</i>', $s) ?? $s;
    // x^2, x^(n+1), 10^-3: a caret then a token or a bracketed group.
    $s = preg_replace('/\^\(([^()]+)\)/', '<sup>$1</sup>', $s) ?? $s;
    $s = preg_replace('/\^(-?[\w.]+)/', '<sup>$1</sup>', $s) ?? $s;
    return $s;
}

/** Marks as a word, for replies and pages. */
function exam_marks_word(int|float $n): string
{
    $n = (float) $n;
    $s = $n == (int) $n ? (string) (int) $n : (string) $n;
    return $s . ' mark' . ($n == 1 ? '' : 's');
}

/** "45 min" or "1 h 15 min". */
function exam_minutes_word(int $m): string
{
    if ($m < 60) {
        return $m . ' min';
    }
    $h = intdiv($m, 60);
    $r = $m % 60;
    return $h . ' h' . ($r ? ' ' . $r . ' min' : '');
}

/** Section totals of a hydrated test: marks available, scored, blanks, guide minutes. */
function exam_section_totals(array $section): array
{
    $max = 0.0;
    $score = 0.0;
    $blanks = 0;
    $scored = true;
    foreach ($section['questions'] as $q) {
        $max += (float) $q['marks'];
        $ans  = $q['answer'] ?? null;
        if ($ans === null || !isset($ans['score'])) {
            $scored = false;
        } else {
            $score += (float) $ans['score'];
        }
        if ($ans === null || exam_is_blank($ans['answer'] ?? null)) {
            $blanks++;
        }
    }
    return [
        'max'       => $max,
        'score'     => $scored ? $score : null,
        'blanks'    => $blanks,
        'questions' => count($section['questions']),
        'minutes'   => $section['minutes_guide'] ?? null,
    ];
}
