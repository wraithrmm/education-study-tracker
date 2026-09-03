<?php
/**
 * Initial data, transcribed from 03-TOPIC-STATE.md v1.1 (state date 2 Sept 2026,
 * reconciled against the session update blocks of 11-19 August 2026).
 *
 * Vague "last touched" values in the source document are mapped to a concrete
 * date so the ageing rule can work: "Jun 26" -> 2026-06-30 (the diagnostics),
 * "Aug 26" -> 2026-08-19 (the Phase 1 exit check). Anything with an explicit
 * date keeps it. Where the source records no date at all, the field stays null
 * and the review queue reports it as such rather than inventing one.
 *
 * Seeding runs only when the database has no subjects, so a redeploy never
 * overwrites real progress.
 */
declare(strict_types=1);

if (!defined('TRACKER')) {
    exit;
}

function seedIfEmpty(Store $store): void
{
    if (count($store->listSubjects()) > 0) {
        return;
    }

    $DIAG = '2026-06-30';
    $EXIT = '2026-08-19';

    // [ref, name, strand, tier, status, last_touched, watch]
    $topics = [
        // Number
        ['N1-N3', 'Place value, four operations, negatives, order of operations', 'N', 'F', 'secure', $DIAG, null],
        ['N4', 'Factors, multiples, primes, HCF/LCM, prime factorisation', 'N', 'F', 'developing', '2026-08-18',
            'Red to green in one sitting on 18 Aug, and no HCF/LCM question appeared in the 19 Aug exit check. The jump is unverified.'],
        ['N5', 'Systematic listing; product rule for counting', 'N', 'F/H', 'notstarted', null, null],
        ['N6-N7', 'Powers, roots, index laws', 'N', 'F/H', 'developing', $DIAG, null],
        ['N8', 'Surds', 'N', 'H', 'notstarted', null, null],
        ['N9', 'Standard form', 'N', 'F', 'notstarted', null, null],
        ['N10', 'Fractions and decimals, recurring decimals', 'N', 'F/H', 'developing', '2026-08-18',
            'Simple conversions reliable in starters. Recurring decimals taught once on 18 Aug and never retested.'],
        ['N11-N12', 'Fractions of amounts, percentages as operators', 'N', 'F', 'secure', $EXIT, null],
        ['N13', 'Standard units, compound measures', 'N', 'F', 'secure', $EXIT, null],
        ['N14', 'Estimation and rounding', 'N', 'F', 'secure', '2026-08-17', null],
        ['N15', 'Error intervals and bounds', 'N', 'F/H', 'developing', $EXIT,
            'Exit check 1/2 — bounds correct, inequality notation missing. Teach alongside A22 rather than drilling notation alone.'],
        ['N16', 'Applying number in context', 'N', 'F', 'secure', $DIAG, null],
        // Algebra
        ['A1-A3', 'Notation, substitution, vocabulary', 'A', 'F', 'secure', $DIAG, null],
        ['A4', 'Simplify, expand, factorise', 'A', 'F/H', 'secure', $EXIT,
            'Squared-term factorising wobbled on 16 Aug and one collecting-terms slip on 19 Aug. Rotate a 6x²+8x factorisation through starters. Secured 13 Aug, so now due a spaced re-test for exam-ready.'],
        ['A5', 'Rearranging formulae', 'A', 'F/H', 'secure', $EXIT,
            'Cube-root rearrangement 2/3 — divided by r twice instead of taking a cube root. One starter question undoing a cube, then one undoing a square.'],
        ['A6', 'Identities and proof', 'A', 'F/H', 'notstarted', null, null],
        ['A7', 'Composite and inverse functions', 'A', 'H', 'notstarted', null, null],
        ['A8', 'Coordinates', 'A', 'F', 'secure', $EXIT,
            'Evidence is one oral check plus one 1-mark question. Ask a harder one: reading coordinates off a graph, or a midpoint.'],
        ['A9-A10', 'Linear graphs, y = mx + c, gradient', 'A', 'F/H', 'secure', $EXIT,
            'Equation-finding is solid, but plotting from a table, reading a real graph and perpendicular lines have never been taught. Schedule a dedicated session before Phase 3.'],
        ['A11', 'Quadratic graphs: roots and turning points', 'A', 'F/H', 'gap', $DIAG, null],
        ['A12-A14', 'Cubic, reciprocal and other graphs', 'A', 'F/H', 'notstarted', null, null],
        ['A15-A16', 'Equation of a circle, tangents, area under curves', 'A', 'H', 'notstarted', null, null],
        ['A17', 'Solving linear equations', 'A', 'F', 'secure', $EXIT,
            'Secured 13 Aug on a 5/5 harder retest and passed four ageing checks. Now past three weeks, so due a spaced re-test for exam-ready.'],
        ['A18', 'Solving quadratics', 'A', 'F/H', 'notstarted', null, null],
        ['A19', 'Simultaneous equations', 'A', 'F/H', 'notstarted', null, null],
        ['A20', 'Approximate solutions by iteration', 'A', 'H', 'notstarted', null, null],
        ['A21', 'Forming equations and formulae from context', 'A', 'F', 'secure', '2026-08-14', null],
        ['A22', 'Inequalities', 'A', 'F/H', 'developing', $DIAG,
            'Untouched since the diagnostics. Shares the missing inequality-notation skill with N15 — teach them together.'],
        ['A23-A25', 'Sequences and nth term', 'A', 'F/H', 'gap', $DIAG, null],
        // Ratio and proportion
        ['R1-R3', 'Scale and unit conversion', 'R', 'F', 'secure', $DIAG, null],
        ['R4-R6', 'Ratio notation, division, direction', 'R', 'F', 'secure', $EXIT, null],
        ['R7-R8', 'Ratio, fractions and linear functions', 'R', 'F', 'secure', '2026-08-18',
            'Taught and retested a day apart, so spacing is untested. Include in a starter after a two-week gap.'],
        ['R9', 'Percentage problems including reverse percentages', 'R', 'F', 'secure', $EXIT, null],
        ['R10', 'Direct and inverse proportion', 'R', 'F/H', 'developing', $DIAG, null],
        ['R11', 'Compound units: speed, density, pressure', 'R', 'F', 'secure', $EXIT, null],
        ['R12-R15', 'Growth, decay and compound interest', 'R', 'F/H', 'notstarted', null, null],
        ['R16', 'Gradients as rates of change', 'R', 'H', 'notstarted', null, null],
        // Geometry
        ['G1', 'Conventions, terms, symmetry', 'G', 'F', 'gap', $DIAG, null],
        ['G2', 'Constructions and loci', 'G', 'F', 'notstarted', null, null],
        ['G3', 'Angles: parallel lines and polygons', 'G', 'F', 'gap', $DIAG, null],
        ['G4-G6', 'Triangles, quadrilaterals, congruence', 'G', 'F', 'developing', $DIAG, null],
        ['G7-G8', 'Transformations', 'G', 'F/H', 'notstarted', null, null],
        ['G9-G12', 'Circles: parts, area, circumference', 'G', 'F', 'developing', $DIAG, null],
        ['G10H', 'Circle theorems', 'G', 'H', 'notstarted', null, null],
        ['G13', 'Plans and elevations', 'G', 'F', 'notstarted', null, null],
        ['G14-G16', 'Perimeter and area', 'G', 'F', 'developing', $DIAG, null],
        ['G17-G18', 'Composite shapes, volume, surface area', 'G', 'F/H', 'gap', $DIAG, null],
        ['G19', 'Similarity and scale factors', 'G', 'F/H', 'notstarted', null, null],
        ['G20', 'Pythagoras and trigonometry', 'G', 'F/H', 'gap', $DIAG, null],
        ['G21', 'Exact trig values', 'G', 'F', 'notstarted', null, null],
        ['G22-G23', 'Vectors: arithmetic', 'G', 'F/H', 'gap', $DIAG, null],
        ['G24-G25', 'Vector proof and arguments', 'G', 'H', 'notstarted', null, null],
        // Probability
        ['P1', 'Frequency trees, recording outcomes', 'P', 'F', 'gap', $DIAG, null],
        ['P2-P3', 'Expected and relative frequency', 'P', 'F', 'gap', $DIAG, null],
        ['P4-P5', 'Probabilities sum to 1; sample size', 'P', 'F', 'gap', $DIAG, null],
        ['P6-P7', 'Tree diagrams, sample spaces, Venn diagrams', 'P', 'F', 'gap', $DIAG, null],
        ['P8', 'Combined events: add versus multiply', 'P', 'F', 'gap', $DIAG, null],
        ['P9', 'Conditional probability', 'P', 'H', 'notstarted', null, null],
        // Statistics
        ['S1', 'Sampling', 'S', 'F/H', 'notstarted', null, null],
        ['S2', 'Charts and tables', 'S', 'F', 'gap', $DIAG, null],
        ['S3', 'Histograms, cumulative frequency, box plots', 'S', 'H', 'notstarted', null, null],
        ['S4', 'Averages from data and frequency tables', 'S', 'F', 'gap', $DIAG, null],
        ['S5', 'Comparing distributions', 'S', 'F', 'notstarted', null, null],
        ['S6', 'Scatter graphs and correlation', 'S', 'F', 'notstarted', null, null],
    ];

    $store->upsertSubject([
        'slug'         => 'maths',
        'name'         => 'GCSE Mathematics',
        'spec_code'    => 'AQA 8300',
        'tier'         => 'Higher',
        'exam_date'    => '2027-05-14',
        'boundary_max' => 240,
        // From 05-RESOURCE-DIRECTORY.md: Higher Jun 2025, Foundation Jun 2024.
        'boundaries'   => [
            'H' => [[7, 164], [6, 130], [5, 96], [4, 63]],
            'F' => [[5, 186], [4, 157]],
        ],
        'strands'      => [
            'N' => 'Number',
            'A' => 'Algebra',
            'R' => 'Ratio & proportion',
            'G' => 'Geometry & measures',
            'P' => 'Probability',
            'S' => 'Statistics',
        ],
        'notes' => 'Seeded from 03-TOPIC-STATE.md v1.1. Paper 1 date provisional. Exam-ready requires a spaced re-test at least three weeks after securing.',
    ]);

    foreach ($topics as $i => [$ref, $name, $strand, $tier, $status, $last, $watch]) {
        $store->upsertTopic([
            'subject_slug' => 'maths',
            'ref'          => $ref,
            'name'         => $name,
            'strand'       => $strand,
            'tier'         => $tier,
            'status'       => $status,
            'last_touched' => $last,
            'watch'        => $watch,
            'sort_order'   => $i,
        ]);
    }

    $assessments = [
        ['2026-07-01', '8300/2F Jun-22', 'paper', 49, 80, null, null],
        ['2026-07-15', '8300/3F Jun-22', 'paper', 41, 80, null,
            '27 of the 39 marks lost were on questions left blank.'],
        ['2026-08-01', '8300/1F Jun-22 (reported)', 'paper', 58, 80, null,
            'Reported as a percentage; per-question breakdown not on file.'],
        ['2026-08-19', 'Phase 1 exit check', 'check', 22, 25, 0,
            'Grade 4-5 algebra and ratio only. All ten questions attempted.'],
    ];

    foreach ($assessments as [$date, $name, $kind, $score, $max, $blanks, $note]) {
        $store->addAssessment([
            'subject_slug' => 'maths',
            'date'         => $date,
            'name'         => $name,
            'kind'         => $kind,
            'tier'         => 'F',
            'score'        => $score,
            'max'          => $max,
            'blanks'       => $blanks,
            'note'         => $note,
        ]);
    }

    $store->addSession([
        'subject_slug'   => 'maths',
        'date'           => '2026-08-19',
        'summary'        => 'Phase 1 exit check: 22/25 with zero blanks, above the 80% target. Algebra and ratio gap clusters confirmed closed.',
        'topics_touched' => 'A4, A5, A8, A9-A10, A17, R4-R6, R7-R8',
        'next_steps'     => 'Spaced-retrieval starter across the Phase 1 secures before any new teaching. A4 and A17 are due their exam-ready re-test.',
    ]);
}
