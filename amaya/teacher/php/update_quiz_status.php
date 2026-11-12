<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to user, but log them
ini_set('log_errors', 1);
error_log("=== Quiz Status Update API Called ===");

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    error_log("ERROR: No teacher session found");
    echo json_encode(['success' => false, 'message' => 'Unauthorized - No session found']);
    exit();
}

error_log("Teacher ID from session: " . $_SESSION['teacher_id']);

// Database connection
$host = 'localhost';
$dbname = 'u304756553_ashi_classroom';
$db_username = 'u304756553_ashi_classroom';
$db_password = 'Ashi_Classroom17';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("Database connection successful");
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Get JSON input
$raw_input = file_get_contents('php://input');
error_log("Raw input: " . $raw_input);

$input = json_decode($raw_input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

error_log("Decoded input: " . print_r($input, true));

if (!isset($input['quiz_id']) || !isset($input['status'])) {
    error_log("Missing required fields. quiz_id: " . (isset($input['quiz_id']) ? $input['quiz_id'] : 'NOT SET') . ", status: " . (isset($input['status']) ? $input['status'] : 'NOT SET'));
    echo json_encode(['success' => false, 'message' => 'Missing required fields (quiz_id or status)']);
    exit();
}

$quiz_id = $input['quiz_id'];
$status = $input['status'];
$teacher_id = $_SESSION['teacher_id'];

error_log("Processing: quiz_id=$quiz_id, status=$status, teacher_id=$teacher_id");

// Validate status
$allowed_statuses = ['draft', 'published', 'closed'];
if (!in_array($status, $allowed_statuses)) {
    error_log("Invalid status value: $status");
    echo json_encode(['success' => false, 'message' => 'Invalid status. Allowed: draft, published, closed']);
    exit();
}

try {
    // Verify quiz ownership
    error_log("Verifying quiz ownership...");
    $stmt = $pdo->prepare("SELECT id, title, status as current_status FROM quizzes WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$quiz_id, $teacher_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quiz) {
        error_log("Quiz not found or unauthorized. quiz_id=$quiz_id, teacher_id=$teacher_id");
        echo json_encode(['success' => false, 'message' => 'Quiz not found or you do not have permission']);
        exit();
    }
    
    error_log("Quiz found: " . print_r($quiz, true));
    
    // Additional validation for publishing
    if ($status === 'published') {
        error_log("Validating quiz for publishing...");
        
        // Check if quiz has questions
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM quiz_questions WHERE quiz_id = ?");
        $stmt->execute([$quiz_id]);
        $question_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        error_log("Question count: $question_count");
        
        if ($question_count == 0) {
            error_log("Cannot publish: no questions");
            echo json_encode(['success' => false, 'message' => 'Cannot publish quiz without questions']);
            exit();
        }
        
        // Check if all questions have correct answers
        $stmt = $pdo->prepare("
            SELECT qq.id, qq.question_type, qq.question_text
            FROM quiz_questions qq
            WHERE qq.quiz_id = ?
        ");
        $stmt->execute([$quiz_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Validating " . count($questions) . " questions...");
        
        foreach ($questions as $question) {
            if ($question['question_type'] === 'multiple-choice' || $question['question_type'] === 'true-false') {
                // Check if question has at least one correct option
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM quiz_question_options 
                    WHERE question_id = ? AND is_correct = 1
                ");
                $stmt->execute([$question['id']]);
                $correct_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                error_log("Question {$question['id']} ({$question['question_type']}): $correct_count correct answers");
                
                if ($correct_count == 0) {
                    error_log("Cannot publish: Question {$question['id']} has no correct answer");
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Question "' . substr($question['question_text'], 0, 50) . '..." has no correct answer'
                    ]);
                    exit();
                }
            } else if ($question['question_type'] === 'short-answer' || $question['question_type'] === 'modified-true-false') {
                // Check if question has an answer
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM quiz_question_answers 
                    WHERE question_id = ?
                ");
                $stmt->execute([$question['id']]);
                $answer_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                error_log("Question {$question['id']} ({$question['question_type']}): $answer_count answers");
                
                if ($answer_count == 0) {
                    error_log("Cannot publish: Question {$question['id']} has no answer");
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Question "' . substr($question['question_text'], 0, 50) . '..." has no correct answer'
                    ]);
                    exit();
                }
            }
        }
        
        error_log("All questions validated successfully");
    }
    
    // Update quiz status
    error_log("Updating quiz status to: $status");
    $stmt = $pdo->prepare("UPDATE quizzes SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $result = $stmt->execute([$status, $quiz_id]);
    
    if ($result) {
        $affected_rows = $stmt->rowCount();
        error_log("Update successful. Affected rows: $affected_rows");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Quiz status updated to ' . $status,
            'status' => $status,
            'previous_status' => $quiz['current_status']
        ]);
    } else {
        error_log("Update failed. No rows affected");
        echo json_encode(['success' => false, 'message' => 'Failed to update quiz status']);
    }
    
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

error_log("=== API Call Completed ===");
?>