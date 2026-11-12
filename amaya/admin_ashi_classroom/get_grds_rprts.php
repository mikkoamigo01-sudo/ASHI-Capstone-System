<?php
// ASHI AMAYA — get_grds_rprts.php
session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Unauthorized. Admin login required.']));
}

require_once 'db.php';

$db         = getDB();
$action     = isset($_GET['action'])     ? trim($_GET['action'])                            : 'summary';
$grade      = isset($_GET['grade'])      ? $db->real_escape_string(trim($_GET['grade']))   : '';
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id']                        : 0;

// ── SUMMARY ──────────────────────────────────────────────────
if ($action === 'summary') {
    header('Content-Type: application/json; charset=utf-8');

    $where = ["sub.status = 'active'"];
    if ($grade      !== '') $where[] = "sub.grade = '{$grade}'";
    if ($subject_id  > 0)   $where[] = "sub.id = {$subject_id}";
    $w = implode(' AND ', $where);

    // ── Main subject rows ────────────────────────────────────
    $subjects = [];
    $r = $db->query("
        SELECT
            sub.id AS subject_id,
            sub.subject_name,
            sub.grade,
            sub.section,
            sub.class_code,
            t.fullname AS teacher_name,
            (SELECT COUNT(*) FROM student_subjects ss  WHERE ss.subject_id  = sub.id AND ss.status = 'active') AS enrolled_students,
            (SELECT ROUND(AVG(sm.score / a.max_points * 100), 2)
             FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
             WHERE a.subject_id = sub.id AND sm.status IN ('graded','returned') AND a.max_points > 0) AS avg_assignment_pct,
            (SELECT ROUND(AVG(qa.percentage), 2)
             FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id
             WHERE q.subject_id = sub.id AND qa.status IN ('submitted','graded')) AS avg_quiz_pct,
            (SELECT ROUND(AVG(ss2.final_grade), 2)
             FROM student_subjects ss2
             WHERE ss2.subject_id = sub.id AND ss2.status = 'active' AND ss2.final_grade IS NOT NULL) AS class_final_avg,
            (SELECT MAX(ss3.final_grade) FROM student_subjects ss3 WHERE ss3.subject_id = sub.id AND ss3.final_grade IS NOT NULL) AS highest_grade,
            (SELECT MIN(ss4.final_grade) FROM student_subjects ss4 WHERE ss4.subject_id = sub.id AND ss4.final_grade IS NOT NULL) AS lowest_grade,
            (SELECT COUNT(*) FROM student_subjects ss5 WHERE ss5.subject_id = sub.id AND ss5.final_grade >= 75) AS students_passed,
            (SELECT COUNT(*) FROM student_subjects ss6 WHERE ss6.subject_id = sub.id AND ss6.final_grade IS NOT NULL AND ss6.final_grade < 75) AS students_failed
        FROM subjects sub
        JOIN teachers t ON sub.teacher_id = t.id
        WHERE {$w}
        ORDER BY sub.grade ASC, SUBSTRING_INDEX(t.fullname, ' ', 1) ASC, t.fullname ASC, sub.subject_name ASC
    ");

    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if ($row['class_final_avg'] === null) {
                $a = (float)($row['avg_assignment_pct'] ?? 0);
                $q = (float)($row['avg_quiz_pct']       ?? 0);
                $row['class_final_avg'] = ($a > 0 || $q > 0) ? round($a * 0.6 + $q * 0.4, 2) : null;
            }
            $subjects[] = $row;
        }
    }

    // ── Grade-level options (for the grade dropdown) ─────────
    $grade_opts = [];
    $r2 = $db->query("SELECT DISTINCT grade FROM subjects WHERE status = 'active' ORDER BY grade ASC");
    if ($r2) while ($row = $r2->fetch_assoc()) $grade_opts[] = $row['grade'];

    // ── Subject options filtered by selected grade ───────────
    // Sorted by teacher first name, then full name, then subject name
    $subject_opts = [];
    $sw = ["sub.status = 'active'"];
    if ($grade !== '') $sw[] = "sub.grade = '{$grade}'";
    $sw_str = implode(' AND ', $sw);

    $r3 = $db->query("
        SELECT sub.id, sub.subject_name, t.fullname AS teacher_name
        FROM subjects sub
        JOIN teachers t ON sub.teacher_id = t.id
        WHERE {$sw_str}
        ORDER BY SUBSTRING_INDEX(t.fullname, ' ', 1) ASC, t.fullname ASC, sub.subject_name ASC
    ");
    if ($r3) {
        while ($row = $r3->fetch_assoc()) {
            $subject_opts[] = [
                'id'           => (int)$row['id'],
                'subject_name' => $row['subject_name'],
                'teacher_name' => $row['teacher_name'],
            ];
        }
    }

    echo json_encode([
        'subjects'     => $subjects,
        'total'        => count($subjects),
        'grade_opts'   => $grade_opts,
        'subject_opts' => $subject_opts,
    ]);

// ── PER-STUDENT BREAKDOWN ─────────────────────────────────────
} elseif ($action === 'students' && $subject_id > 0) {
    header('Content-Type: application/json; charset=utf-8');

    $students = [];
    $r = $db->query("
        SELECT
            st.id AS student_id,
            st.lrn,
            CONCAT(st.first_name,' ',st.last_name) AS student_name,
            st.grade_level,
            st.section,
            ss.final_grade,
            (SELECT ROUND(AVG(sm.score / a.max_points * 100), 2)
             FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
             WHERE a.subject_id = {$subject_id} AND sm.student_id = st.id AND sm.status IN ('graded','returned') AND a.max_points > 0) AS avg_assignment_pct,
            (SELECT COUNT(*)
             FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
             WHERE a.subject_id = {$subject_id} AND sm.student_id = st.id) AS submitted_assignments,
            (SELECT COUNT(*) FROM assignments a WHERE a.subject_id = {$subject_id} AND a.status != 'draft') AS total_assignments,
            (SELECT ROUND(AVG(qa.percentage), 2)
             FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id
             WHERE q.subject_id = {$subject_id} AND qa.student_id = st.id AND qa.status IN ('submitted','graded')) AS avg_quiz_pct,
            (SELECT COUNT(*)
             FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
             WHERE a.subject_id = {$subject_id} AND sm.student_id = st.id AND sm.status = 'late') AS late_submissions
        FROM student_subjects ss
        JOIN students st ON ss.student_id = st.id
        WHERE ss.subject_id = {$subject_id} AND ss.status = 'active'
        ORDER BY st.last_name ASC, st.first_name ASC
    ");

    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if ($row['final_grade'] === null) {
                $a = (float)($row['avg_assignment_pct'] ?? 0);
                $q = (float)($row['avg_quiz_pct']       ?? 0);
                $row['computed_grade'] = ($a > 0 || $q > 0) ? round($a * 0.6 + $q * 0.4, 2) : null;
            } else {
                $row['computed_grade'] = (float)$row['final_grade'];
            }
            $row['passed'] = $row['computed_grade'] !== null && $row['computed_grade'] >= 75;
            $students[] = $row;
        }
    }

    echo json_encode(['students' => $students, 'total' => count($students)]);

// ── CSV EXPORT ────────────────────────────────────────────────
} elseif ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ashi_amaya_grades_' . date('Ymd') . '.csv"');

    $where = ["sub.status = 'active'"];
    if ($grade      !== '') $where[] = "sub.grade = '{$grade}'";
    if ($subject_id  > 0)   $where[] = "sub.id = {$subject_id}";
    $w = implode(' AND ', $where);

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Subject','Grade','Section','Teacher','Enrolled','Avg Assignment (%)','Avg Quiz (%)','Class Average','Passed','Failed']);

    $r = $db->query("
        SELECT
            sub.subject_name, sub.grade, sub.section, t.fullname,
            (SELECT COUNT(*) FROM student_subjects ss WHERE ss.subject_id = sub.id AND ss.status = 'active') AS enrolled,
            (SELECT ROUND(AVG(sm.score / a.max_points * 100), 2)
             FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
             WHERE a.subject_id = sub.id AND sm.status IN ('graded','returned') AND a.max_points > 0) AS avg_asgn,
            (SELECT ROUND(AVG(qa.percentage), 2)
             FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id
             WHERE q.subject_id = sub.id AND qa.status IN ('submitted','graded')) AS avg_quiz,
            (SELECT ROUND(AVG(ss2.final_grade), 2)
             FROM student_subjects ss2
             WHERE ss2.subject_id = sub.id AND ss2.status = 'active' AND ss2.final_grade IS NOT NULL) AS class_avg,
            (SELECT COUNT(*) FROM student_subjects ss5 WHERE ss5.subject_id = sub.id AND ss5.final_grade >= 75) AS passed,
            (SELECT COUNT(*) FROM student_subjects ss6 WHERE ss6.subject_id = sub.id AND ss6.final_grade IS NOT NULL AND ss6.final_grade < 75) AS failed
        FROM subjects sub
        JOIN teachers t ON sub.teacher_id = t.id
        WHERE {$w}
        ORDER BY sub.grade ASC, SUBSTRING_INDEX(t.fullname, ' ', 1) ASC, t.fullname ASC, sub.subject_name ASC
    ");

    if ($r) while ($row = $r->fetch_row()) fputcsv($out, $row);
    fclose($out);

} else {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Use: summary, students, export']);
}