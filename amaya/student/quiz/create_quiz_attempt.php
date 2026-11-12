<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$conn = new mysqli("localhost", "u304756553_ashi_classroom", "Ashi_Classroom17", "u304756553_ashi_classroom");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$student_id = (int)$_SESSION['student_id'];
$input      = json_decode(file_get_contents('php://input'), true);
$quiz_id    = isset($input['quiz_id']) ? (int)$input['quiz_id'] : 0;

if (!$quiz_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid quiz ID']);
    exit;
}

try {
    // 1. Check quiz exists and is published
    $stmt = $conn->prepare("SELECT id, max_attempts FROM quizzes WHERE id = ? AND status = 'published'");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $quiz = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$quiz) {
        echo json_encode(['success' => false, 'message' => 'Quiz not found']);
        exit;
    }

    // 2. Check if ANY attempt exists at all (regardless of status)
    // Opening the quiz = 1 attempt consumed, no resume, no retry
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM quiz_attempts
        WHERE quiz_id = ? AND student_id = ?
    ");
    $stmt->bind_param("ii", $quiz_id, $student_id);
    $stmt->execute();
    $attempts_count = (int)$stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    if ($attempts_count > 0) {
        echo json_encode(['success' => false, 'message' => 'You have already used your attempt for this quiz']);
        exit;
    }

    // 3. Create new attempt (only 1 ever allowed)
    $attempt_number = 1;

    $stmt = $conn->prepare("
        INSERT INTO quiz_attempts (quiz_id, student_id, attempt_number, status, started_at)
        VALUES (?, ?, 1, 'in-progress', NOW())
    ");
    $stmt->bind_param("ii", $quiz_id, $student_id);

    if ($stmt->execute()) {
        $attempt_id = $conn->insert_id;
        $stmt->close();
        $conn->close();
        echo json_encode([
            'success'        => true,
            'attempt_id'     => $attempt_id,
            'attempt_number' => 1
        ]);
    } else {
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Failed to create attempt']);
    }

} catch (Exception $e) {
    if ($conn) $conn->close();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>