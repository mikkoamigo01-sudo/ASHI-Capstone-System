<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

header('Content-Type: application/json');

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'count' => 0]);
    exit();
}

$student_id = $_SESSION['student_id'];

// Count only pending/unsubmitted assignments for this student
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT a.id) AS task_count
    FROM assignments a
    INNER JOIN subjects s ON a.subject_id = s.id
    INNER JOIN student_subjects ss ON s.id = ss.subject_id
    LEFT JOIN submissions sub ON a.id = sub.assignment_id AND sub.student_id = ?
    WHERE ss.student_id = ? 
        AND ss.status = 'active'
        AND a.status = 'published'
        AND sub.id IS NULL
");

if (!$stmt) {
    echo json_encode(['success' => false, 'count' => 0]);
    exit();
}

$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$row        = $stmt->get_result()->fetch_assoc();
$task_count = (int)$row['task_count'];
$stmt->close();

echo json_encode(['success' => true, 'count' => $task_count]);

$conn->close();
?>