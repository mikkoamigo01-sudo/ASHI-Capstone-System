<?php
session_start();
header('Content-Type: application/json');

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

// Internal database connection
$servername = "localhost";
$username = "u304756553_ashi_classroom"; // Change to your database username
$password = "Ashi_Classroom17"; // Change to your database password
$dbname = "u304756553_ashi_classroom"; // Your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

// ── Fetch current school year and quarter from settings (server-authoritative) ──
$sy_result = $conn->query("SELECT school_year, current_quarter FROM school_year_settings LIMIT 1");
if (!$sy_result || $sy_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'School year settings not found. Please configure school year settings first.']);
    exit;
}
$sy_row         = $sy_result->fetch_assoc();
$school_year    = $sy_row['school_year'];
$current_quarter = (int)$sy_row['current_quarter'];
// ─────────────────────────────────────────────────────────────────────────────

// Get JSON input
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

// Validate required fields
if (!isset($data['title']) || !isset($data['subject_id']) || !isset($data['questions'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Verify that the subject belongs to this teacher
$stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $data['subject_id'], $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid subject or unauthorized access']);
    exit;
}
$stmt->close();

try {
    // Start transaction
    $conn->begin_transaction();
    
    $isUpdate = isset($data['quiz_id']) && $data['quiz_id'] !== null;
    
    if ($isUpdate) {
        // Verify quiz belongs to this teacher
        $stmt = $conn->prepare("SELECT id FROM quizzes WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("ii", $data['quiz_id'], $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception('Quiz not found or unauthorized');
        }
        $stmt->close();
        
        // Update existing quiz — school_year and quarter are always refreshed from settings
        $stmt = $conn->prepare("
            UPDATE quizzes SET
                subject_id    = ?,
                title         = ?,
                description   = ?,
                due_date      = ?,
                time_limit    = ?,
                max_attempts  = ?,
                passing_score = ?,
                total_points  = ?,
                status        = ?,
                school_year   = ?,
                quarter       = ?,
                updated_at    = CURRENT_TIMESTAMP
            WHERE id = ? AND teacher_id = ?
        ");
        
        $stmt->bind_param(
            "isssiiidssii",
            $data['subject_id'],
            $data['title'],
            $data['description'],
            $data['due_date'],
            $data['time_limit'],
            $data['max_attempts'],
            $data['passing_score'],
            $data['total_points'],
            $data['status'],
            $school_year,
            $current_quarter,
            $data['quiz_id'],
            $teacher_id
        );
        
        $stmt->execute();
        $quiz_id = $data['quiz_id'];
        $stmt->close();
        
        // Delete existing questions and their related data
        $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        $stmt->close();
        
    } else {
        // Insert new quiz — school_year and quarter come from school_year_settings
        $stmt = $conn->prepare("
            INSERT INTO quizzes (
                subject_id,
                teacher_id,
                title,
                description,
                due_date,
                time_limit,
                max_attempts,
                passing_score,
                total_points,
                status,
                school_year,
                quarter
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            "iisssiiidssi",
            $data['subject_id'],
            $teacher_id,
            $data['title'],
            $data['description'],
            $data['due_date'],
            $data['time_limit'],
            $data['max_attempts'],
            $data['passing_score'],
            $data['total_points'],
            $data['status'],
            $school_year,
            $current_quarter
        );
        
        $stmt->execute();
        $quiz_id = $conn->insert_id;
        $stmt->close();
    }
    
    // Insert questions
    foreach ($data['questions'] as $question) {
        $stmt = $conn->prepare("
            INSERT INTO quiz_questions (
                quiz_id,
                question_order,
                question_text,
                question_type,
                points,
                is_required
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param(
            "iissdi",
            $quiz_id,
            $question['question_order'],
            $question['question_text'],
            $question['question_type'],
            $question['points'],
            $question['is_required']
        );
        
        $stmt->execute();
        $question_id = $conn->insert_id;
        $stmt->close();
        
        // Insert options for multiple-choice, true-false, modified-true-false
        if (in_array($question['question_type'], ['multiple-choice', 'true-false', 'modified-true-false'])) {
            foreach ($question['options'] as $index => $option_text) {
                $is_correct = ($index == $question['correct_answer_index']) ? 1 : 0;
                
                $stmt = $conn->prepare("
                    INSERT INTO quiz_question_options (
                        question_id,
                        option_text,
                        option_order,
                        is_correct
                    ) VALUES (?, ?, ?, ?)
                ");
                
                $stmt->bind_param("isii", $question_id, $option_text, $index, $is_correct);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Insert correct answer for short-answer and modified-true-false
        if ($question['question_type'] === 'short-answer' || 
            ($question['question_type'] === 'modified-true-false' && !empty($question['correct_answer_text']))) {
            
            $stmt = $conn->prepare("
                INSERT INTO quiz_question_answers (question_id, answer_text) VALUES (?, ?)
            ");
            
            $stmt->bind_param("is", $question_id, $question['correct_answer_text']);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success'     => true,
        'message'     => $isUpdate ? 'Quiz updated successfully' : 'Quiz saved successfully',
        'quiz_id'     => $quiz_id,
        'is_update'   => $isUpdate,
        'school_year' => $school_year,
        'quarter'     => $current_quarter
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>