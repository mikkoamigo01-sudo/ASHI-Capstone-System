<?php
session_start();
header('Content-Type: application/json');

// Database configuration
require_once '../config/database.php';

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate required fields
if (!isset($data['quiz_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing quiz_id']);
    exit;
}

$quiz_id = intval($data['quiz_id']);

try {
    // Verify quiz belongs to this teacher
    $stmt = $conn->prepare("SELECT id FROM quizzes WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $quiz_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Quiz not found or unauthorized']);
        exit;
    }
    $stmt->close();
    
    // Delete quiz (CASCADE will delete related questions, options, answers)
    $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $quiz_id, $teacher_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Quiz deleted successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
