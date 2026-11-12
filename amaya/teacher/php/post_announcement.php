<?php
session_start();
require_once '../../db_connection/ashi_dbcnnt.php';

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data       = json_decode(file_get_contents('php://input'), true);
$subject_id = intval($data['subject_id'] ?? 0);
$title      = trim($data['title'] ?? '');
$content    = trim($data['content'] ?? '');
$is_pinned  = intval($data['is_pinned'] ?? 0);
$teacher_id = $_SESSION['teacher_id'];

if (!$subject_id || !$content) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Verify teacher owns the subject
$stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $subject_id, $teacher_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    $stmt->close();
    exit();
}
$stmt->close();

// Insert announcement
$title_val = $title !== '' ? $title : null;
$stmt = $conn->prepare("
    INSERT INTO announcements (subject_id, teacher_id, title, content, is_pinned, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
");
$stmt->bind_param("iissi", $subject_id, $teacher_id, $title_val, $content, $is_pinned);
$stmt->execute();
$new_id = $conn->insert_id;
$stmt->close();

$created_at = (new DateTime())->format('M j, Y • g:i A');

echo json_encode([
    'success' => true,
    'announcement' => [
        'id'         => (int)$new_id,
        'title'      => $title,
        'content'    => $content,
        'is_pinned'  => $is_pinned,
        'created_at' => $created_at
    ]
]);