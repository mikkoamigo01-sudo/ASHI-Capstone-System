<?php
session_start();
require_once '../../db_connection/ashi_dbcnnt.php';

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data       = json_decode(file_get_contents('php://input'), true);
$id         = intval($data['id'] ?? 0);
$teacher_id = $_SESSION['teacher_id'];

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit();
}

// Verify teacher owns this announcement
$stmt = $conn->prepare("SELECT id FROM announcements WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $id, $teacher_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    $stmt->close();
    exit();
}
$stmt->close();

// Delete
$stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);