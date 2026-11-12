<?php
session_start();
header('Content-Type: application/json');

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

require_once '../db_connection/ashi_dbcnnt.php';

$stmt = $conn->prepare("
    SELECT COUNT(*) as ungraded_count
    FROM submissions sub
    INNER JOIN assignments a ON sub.assignment_id = a.id
    WHERE a.teacher_id = ?
    AND sub.status IN ('submitted', 'late')
    AND (sub.score IS NULL OR sub.graded_at IS NULL)
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

echo json_encode([
    'success' => true,
    'count' => (int)$row['ungraded_count']
]);

$conn->close();
?>