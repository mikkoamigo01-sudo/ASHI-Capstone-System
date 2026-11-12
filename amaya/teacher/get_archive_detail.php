<?php
/* ============================================================
   get_archive_detail.php
   Returns full read-only data for one deactivated subject.
   Called by archive_section.js when teacher opens a card.
   ============================================================ */

session_start();
header('Content-Type: application/json');

/* ── Auth guard ──────────────────────────────────────────── */
if (empty($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$teacher_id = (int) $_SESSION['teacher_id'];
$subject_id = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : 0;

if ($subject_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid subject.']);
    exit;
}

/* ── DB connection ───────────────────────────────────────── */
require_once '../db_connection/ashi_dbcnnt.php';

/* ── 1. Verify subject belongs to this teacher & is deactivated ── */
$stmt = $conn->prepare("
    SELECT id, subject_name, section, grade, class_code,
           description, theme_color, logo_path, archived_at, status
    FROM subjects
    WHERE id = ? AND teacher_id = ? AND status = 'deactivated'
    LIMIT 1
");
$stmt->bind_param('ii', $subject_id, $teacher_id);
$stmt->execute();
$subResult = $stmt->get_result();

if ($subResult->num_rows === 0) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Subject not found or not accessible.']);
    exit;
}

$subject = $subResult->fetch_assoc();
$stmt->close();

/* ── 2. Assignments ──────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT id, title, assignment_type, due_date, max_points, status
    FROM assignments
    WHERE subject_id = ?
    ORDER BY due_date DESC
");
$stmt->bind_param('i', $subject_id);
$stmt->execute();
$res = $stmt->get_result();
$assignments = [];
while ($row = $res->fetch_assoc()) {
    $assignments[] = $row;
}
$stmt->close();

/* ── 3. Quizzes ──────────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT id, title, due_date, time_limit, total_points, passing_score, status
    FROM quizzes
    WHERE subject_id = ?
    ORDER BY due_date DESC
");
$stmt->bind_param('i', $subject_id);
$stmt->execute();
$res = $stmt->get_result();
$quizzes = [];
while ($row = $res->fetch_assoc()) {
    $quizzes[] = $row;
}
$stmt->close();

/* ── 4. Materials ────────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT id, title, material_type, file_path, url, created_at
    FROM materials
    WHERE subject_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param('i', $subject_id);
$stmt->execute();
$res = $stmt->get_result();
$materials = [];
while ($row = $res->fetch_assoc()) {
    $materials[] = $row;
}
$stmt->close();

/* ── 5. Students enrolled ────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT s.id, s.first_name, s.last_name, s.grade_level, s.section, s.profile_picture
    FROM students s
    INNER JOIN student_subjects ss ON ss.student_id = s.id
    WHERE ss.subject_id = ?
    ORDER BY s.last_name ASC, s.first_name ASC
");
$stmt->bind_param('i', $subject_id);
$stmt->execute();
$res = $stmt->get_result();
$students = [];
while ($row = $res->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

$conn->close();

/* ── Response ────────────────────────────────────────────── */
echo json_encode([
    'success'     => true,
    'subject'     => $subject,
    'assignments' => $assignments,
    'quizzes'     => $quizzes,
    'materials'   => $materials,
    'students'    => $students,
]);
exit;