<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$student_id = $_SESSION['student_id'];

// ── Get current school year + quarter ────────────────────────
$sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
$sy_stmt->execute();
$sy_row          = $sy_stmt->get_result()->fetch_assoc();
$sy_stmt->close();
$school_year     = $sy_row['school_year']     ?? date('Y').'-'.(date('Y')+1);
$current_quarter = intval($sy_row['current_quarter'] ?? 1);

// ── Get all active subjects for this student ──────────────────
$stmt = $conn->prepare("
    SELECT
        s.id AS subject_id,
        s.subject_name,
        s.section,
        s.grade AS grade_level,
        t.fullname AS teacher_name,
        ss.final_grade,

        -- Assignment PS
        COALESCE((
            SELECT SUM(sub.score) / NULLIF(SUM(a.max_points), 0) * 100
            FROM assignments a
            INNER JOIN submissions sub ON a.id = sub.assignment_id AND sub.student_id = ?
            WHERE a.subject_id = s.id AND a.status = 'published' AND sub.status = 'graded'
        ), 0) AS assignment_ps,

        -- Quiz PS
        COALESCE((
            SELECT AVG(qa.percentage)
            FROM quizzes q
            INNER JOIN quiz_attempts qa ON q.id = qa.quiz_id AND qa.student_id = ?
            WHERE q.subject_id = s.id AND q.status = 'published' AND qa.status = 'graded'
        ), 0) AS quiz_ps,

        -- Graded assignments count
        (SELECT COUNT(*)
            FROM assignments a
            INNER JOIN submissions sub ON a.id = sub.assignment_id AND sub.student_id = ?
            WHERE a.subject_id = s.id AND a.status = 'published' AND sub.status = 'graded'
        ) AS graded_assignments,

        -- Total published assignments
        (SELECT COUNT(*) FROM assignments a
            WHERE a.subject_id = s.id AND a.status = 'published'
        ) AS total_assignments,

        -- Graded quizzes count
        (SELECT COUNT(*)
            FROM quizzes q
            INNER JOIN quiz_attempts qa ON q.id = qa.quiz_id AND qa.student_id = ?
            WHERE q.subject_id = s.id AND q.status = 'published' AND qa.status = 'graded'
        ) AS graded_quizzes,

        -- Total published quizzes
        (SELECT COUNT(*) FROM quizzes q
            WHERE q.subject_id = s.id AND q.status = 'published'
        ) AS total_quizzes

    FROM subjects s
    INNER JOIN student_subjects ss ON s.id = ss.subject_id AND ss.student_id = ?
    INNER JOIN teachers t ON s.teacher_id = t.id
    WHERE ss.status = 'active' AND s.status = 'active'
    ORDER BY s.subject_name
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit();
}

$stmt->bind_param("iiiii", $student_id, $student_id, $student_id, $student_id, $student_id);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Get all quarterly grades from student_quarterly_grades ────
// Fetch all 4 quarters in one query for this student + school year
$qg_stmt = $conn->prepare("
    SELECT subject_id, quarter, grade, is_locked
    FROM student_quarterly_grades
    WHERE student_id = ? AND school_year = ?
    ORDER BY quarter ASC
");
$qg_stmt->bind_param("is", $student_id, $school_year);
$qg_stmt->execute();
$qg_rows = $qg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$qg_stmt->close();

// Build a map: [subject_id][quarter] => [grade, is_locked]
$quarterly_map = [];
foreach ($qg_rows as $row) {
    $quarterly_map[$row['subject_id']][$row['quarter']] = [
        'grade'     => $row['grade'] !== null ? floatval($row['grade']) : null,
        'is_locked' => (bool) $row['is_locked'],
    ];
}

// ── Build grades data ─────────────────────────────────────────
$grades_data = [];
foreach ($subjects as $row) {
    $aPS = floatval($row['assignment_ps']);
    $qPS = floatval($row['quiz_ps']);

    // Mirror the exact logic from teacher's grade.php
    if ($aPS > 0 && $qPS > 0)  $initial = ($aPS + $qPS) / 2;
    elseif ($aPS > 0)           $initial = $aPS;
    elseif ($qPS > 0)           $initial = $qPS;
    else                        $initial = 0;

    $suggested  = round(min(100, 60 + ($initial * 0.40)), 1);
    $subject_id = $row['subject_id'];

    // Build per-quarter data for all 4 quarters
    $quarters = [];
    for ($q = 1; $q <= 4; $q++) {
        $saved = $quarterly_map[$subject_id][$q] ?? null;

        if ($q < $current_quarter) {
            // Past quarter — read from student_quarterly_grades only
            $quarters[$q] = [
                'quarter'    => $q,
                'grade'      => $saved ? $saved['grade'] : null,
                'is_locked'  => $saved ? $saved['is_locked'] : false,
                'is_past'    => true,
                'is_current' => false,
                'is_future'  => false,
            ];
        } elseif ($q === $current_quarter) {
            // Current quarter — only use explicitly saved quarterly grade.
            // Do NOT fall back to student_subjects.final_grade.
            // If no grade has been saved yet, show empty/not yet graded.
            $grade = ($saved && $saved['grade'] !== null) ? $saved['grade'] : null;
            $quarters[$q] = [
                'quarter'    => $q,
                'grade'      => $grade,
                'is_locked'  => $saved ? $saved['is_locked'] : false,
                'is_past'    => false,
                'is_current' => true,
                'is_future'  => false,
            ];
        } else {
            // Future quarter
            $quarters[$q] = [
                'quarter'    => $q,
                'grade'      => null,
                'is_locked'  => false,
                'is_past'    => false,
                'is_current' => false,
                'is_future'  => true,
            ];
        }
    }

    $grades_data[] = [
        'subject_id'         => $subject_id,
        'subject_name'       => $row['subject_name'],
        'section'            => $row['section'],
        'grade_level'        => $row['grade_level'],
        'teacher_name'       => $row['teacher_name'],
        'final_grade'        => $row['final_grade'] !== null ? floatval($row['final_grade']) : null,
        'assignment_ps'      => round($aPS, 1),
        'quiz_ps'            => round($qPS, 1),
        'initial'            => round($initial, 1),
        'suggested'          => $suggested,
        'graded_assignments' => intval($row['graded_assignments']),
        'total_assignments'  => intval($row['total_assignments']),
        'graded_quizzes'     => intval($row['graded_quizzes']),
        'total_quizzes'      => intval($row['total_quizzes']),
        'quarters'           => array_values($quarters),
    ];
}

echo json_encode([
    'success'         => true,
    'grades'          => $grades_data,
    'school_year'     => $school_year,
    'current_quarter' => $current_quarter,
]);

$conn->close();
?>