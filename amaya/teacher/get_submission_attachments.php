<?php
session_start();
header('Content-Type: application/json');

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!isset($_GET['submission_id'])) {
    echo json_encode(['success' => false, 'message' => 'Submission ID required']);
    exit;
}

$submission_id = (int)$_GET['submission_id'];
$teacher_id = $_SESSION['teacher_id'];

require_once '../db_connection/ashi_dbcnnt.php';

// Verify that this submission belongs to this teacher's assignment
$verify_stmt = $conn->prepare("
    SELECT sub.id
    FROM submissions sub
    INNER JOIN assignments a ON sub.assignment_id = a.id
    WHERE sub.id = ? AND a.teacher_id = ?
");
$verify_stmt->bind_param("ii", $submission_id, $teacher_id);
$verify_stmt->execute();
$verify_stmt->store_result();

if ($verify_stmt->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get attachments
$stmt = $conn->prepare("
    SELECT id, file_name, file_path, file_type, file_size, uploaded_at
    FROM submission_attachments
    WHERE submission_id = ?
    ORDER BY uploaded_at ASC
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();

$attachments = [];
while ($row = $result->fetch_assoc()) {
    $attachments[] = $row;
}

echo json_encode(['success' => true, 'attachments' => $attachments]);

$conn->close();
?>