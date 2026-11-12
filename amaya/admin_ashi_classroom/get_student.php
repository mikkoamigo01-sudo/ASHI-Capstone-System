<?php
// ASHI AMAYA — get_student.php

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized. Admin login required.']));
}

require_once 'db.php';

$db     = getDB();
$action = isset($_GET['action']) ? trim($_GET['action']) : 'list';
$id     = isset($_GET['id'])     ? (int)$_GET['id']     : 0;

// ── LIST ─────────────────────────────────────────────────────
if ($action === 'list') {

    $search  = $db->real_escape_string(trim($_GET['search']  ?? ''));
    $grade   = $db->real_escape_string(trim($_GET['grade']   ?? ''));
    $section = $db->real_escape_string(trim($_GET['section'] ?? ''));

    // Pagination
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 10;
    $offset   = ($page - 1) * $per_page;

    $where = ["s.status = 'active'"];
    if ($search !== '') {
        $s = $db->real_escape_string($search);
        $where[] = "(
            s.first_name LIKE '%{$s}%'
            OR s.last_name LIKE '%{$s}%'
            OR s.middle_name LIKE '%{$s}%'
            OR CONCAT(s.first_name,' ',s.last_name) LIKE '%{$s}%'
            OR CONCAT(s.last_name,' ',s.first_name) LIKE '%{$s}%'
            OR CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) LIKE '%{$s}%'
            OR s.lrn LIKE '%{$s}%'
            OR s.email LIKE '%{$s}%'
            OR s.username LIKE '%{$s}%'
        )";
    }
    if ($grade   !== '') $where[] = "s.grade_level = '{$grade}'";
    if ($section !== '') $where[] = "s.section = '{$section}'";

    $where_sql = implode(' AND ', $where);

    // Total count for pagination
    $count_r = $db->query("SELECT COUNT(*) AS total FROM students s WHERE {$where_sql}");
    $total   = $count_r ? (int)$count_r->fetch_assoc()['total'] : 0;
    $total_pages = max(1, (int)ceil($total / $per_page));

    $students = [];
    $r = $db->query("
        SELECT s.id, s.lrn, s.first_name, s.last_name, s.middle_name,
               s.email, s.grade_level, s.section, s.status, s.created_at,
               s.contact_number, s.guardian_name, s.relationship,
               s.guardian_contact, s.birthdate, s.profile_picture
        FROM students s
        WHERE {$where_sql}
        ORDER BY s.grade_level ASC, s.section ASC, s.last_name ASC, s.first_name ASC
        LIMIT {$per_page} OFFSET {$offset}
    ");
    if ($r) while ($row = $r->fetch_assoc()) $students[] = $row;

    // Grade list (always from full active students, unfiltered)
    $grades = [];
    $r2 = $db->query("SELECT DISTINCT grade_level FROM students WHERE status='active' ORDER BY grade_level ASC");
    if ($r2) while ($row = $r2->fetch_assoc()) $grades[] = $row['grade_level'];

    echo json_encode([
        'students'    => $students,
        'filters'     => ['grades' => $grades],
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ]);

// ── SECTIONS (dependent dropdown) ────────────────────────────
} elseif ($action === 'sections') {

    $grade = $db->real_escape_string(trim($_GET['grade'] ?? ''));
    $sections = [];
    if ($grade !== '') {
        $r = $db->query("
            SELECT DISTINCT section FROM students
            WHERE status = 'active' AND grade_level = '{$grade}'
            ORDER BY section ASC
        ");
        if ($r) while ($row = $r->fetch_assoc()) $sections[] = $row['section'];
    }
    echo json_encode(['sections' => $sections]);

// ── INFO ─────────────────────────────────────────────────────
} elseif ($action === 'info' && $id > 0) {

    $r = $db->query("
        SELECT id, lrn, first_name, last_name, middle_name, email, username,
               grade_level, section, birthdate, contact_number,
               guardian_name, relationship, guardian_contact,
               bio, profile_picture, status, created_at
        FROM students WHERE id = {$id} LIMIT 1
    ");
    if ($r && $r->num_rows > 0) {
        echo json_encode(['student' => $r->fetch_assoc()]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Student not found']);
    }

// ── PROGRESS ─────────────────────────────────────────────────
} elseif ($action === 'progress' && $id > 0) {

    // Overview stats
    $overview = [];
    $r = $db->query("SELECT
        (SELECT COUNT(*) FROM student_subjects WHERE student_id={$id} AND status='active') AS enrolled_subjects,
        (SELECT ROUND(AVG(sm.score),2) FROM submissions sm JOIN assignments a ON sm.assignment_id=a.id
            WHERE sm.student_id={$id} AND sm.status IN ('graded','returned')) AS avg_assignment_score,
        (SELECT ROUND(AVG(qa.percentage),2) FROM quiz_attempts qa
            WHERE qa.student_id={$id} AND qa.status IN ('submitted','graded')) AS avg_quiz_pct,
        (SELECT COUNT(*) FROM submissions WHERE student_id={$id}) AS total_submissions,
        (SELECT COUNT(*) FROM submissions WHERE student_id={$id} AND status='late') AS late_submissions
    ");
    if ($r) $overview = $r->fetch_assoc();

    // Enrolled subjects
    $subjects = [];
    $r2 = $db->query("
        SELECT sub.id AS subject_id, sub.subject_name, sub.grade, sub.section,
               t.fullname AS teacher_name, ss.status AS enrollment_status, ss.final_grade,
               (SELECT COUNT(*) FROM assignments a WHERE a.subject_id = sub.id AND a.status != 'draft') AS total_assignments,
               (SELECT COUNT(*) FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
                   WHERE a.subject_id = sub.id AND sm.student_id = {$id}) AS submitted_assignments,
               (SELECT ROUND(AVG(sm.score),2) FROM submissions sm JOIN assignments a ON sm.assignment_id = a.id
                   WHERE a.subject_id = sub.id AND sm.student_id = {$id} AND sm.status IN ('graded','returned')) AS avg_assignment_score,
               (SELECT COUNT(*) FROM quizzes q WHERE q.subject_id = sub.id AND q.status != 'draft') AS total_quizzes,
               (SELECT ROUND(AVG(qa.percentage),2) FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id
                   WHERE q.subject_id = sub.id AND qa.student_id = {$id} AND qa.status IN ('submitted','graded')) AS avg_quiz_pct
        FROM student_subjects ss
        JOIN subjects sub ON ss.subject_id = sub.id
        JOIN teachers t ON sub.teacher_id = t.id
        WHERE ss.student_id = {$id} AND ss.status = 'active'
        ORDER BY sub.subject_name ASC
    ");
    if ($r2) while ($row = $r2->fetch_assoc()) $subjects[] = $row;

    // Submission history (last 30)
    $history = [];
    $r3 = $db->query("
        SELECT sm.id, sm.submitted_at, sm.status, sm.score,
               a.title AS assignment_title, a.max_points, sub.subject_name
        FROM submissions sm
        JOIN assignments a ON sm.assignment_id = a.id
        JOIN subjects sub ON a.subject_id = sub.id
        WHERE sm.student_id = {$id}
        ORDER BY sm.submitted_at DESC LIMIT 30
    ");
    if ($r3) while ($row = $r3->fetch_assoc()) $history[] = $row;

    echo json_encode([
        'overview' => $overview ?: (object)[],
        'subjects' => $subjects,
        'history'  => $history,
    ]);

// ── ARCHIVE ──────────────────────────────────────────────────
} elseif ($action === 'archive' && $id > 0) {

    $db->query("UPDATE students SET status = 'archived' WHERE id = {$id} AND status = 'active'");
    if ($db->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Student archived successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not archive. Already archived or not found.']);
    }

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or missing id parameter.']);
}