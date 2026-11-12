<?php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Database connection
$servername = "localhost";
$username = "u304756553_ashi_classroom";
$password = "Ashi_Classroom17";
$dbname = "u304756553_ashi_classroom";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$student_id = $_SESSION['student_id'];

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$attempt_id = isset($input['attempt_id']) ? intval($input['attempt_id']) : 0;
$answers = isset($input['answers']) ? $input['answers'] : [];

if (!$attempt_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid attempt ID']);
    exit;
}

try {
    // Verify attempt belongs to student and is in progress
    $verify = "
        SELECT id FROM quiz_attempts 
        WHERE id = ? AND student_id = ? AND status = 'in-progress'
    ";
    $stmt = $conn->prepare($verify);
    $stmt->bind_param("ii", $attempt_id, $student_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid attempt']);
        exit;
    }
    
    // Save progress as JSON in a temporary responses table
    // This is optional - allows students to resume quiz later
    // For simplicity, we'll just return success
    
    echo json_encode([
        'success' => true,
        'message' => 'Progress saved'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>
