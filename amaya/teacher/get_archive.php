<?php
/* ============================================================
   get_archive.php
   Returns all DEACTIVATED subjects owned by the logged-in teacher.
   Called via AJAX by archive_section.js
   ============================================================ */

session_start();
header('Content-Type: application/json');

/* ── Auth guard ──────────────────────────────────────────── */
if (empty($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$teacher_id = (int) $_SESSION['teacher_id'];

/* ── DB connection ───────────────────────────────────────── */
require_once '../db_connection/ashi_dbcnnt.php';

/* ── Query ───────────────────────────────────────────────── */
$sql = "
    SELECT
        s.id,
        s.subject_name,
        s.section,
        s.grade,
        s.class_code,
        s.description,
        s.theme_color,
        s.logo_path,
        s.archived_at,
        s.status,
        s.created_at
    FROM subjects s
    WHERE s.teacher_id = ?
      AND s.status     = 'deactivated'
    ORDER BY s.archived_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB prepare error.']);
    exit;
}

$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

$subjects = [];
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    'success'  => true,
    'subjects' => $subjects,
]);
exit;