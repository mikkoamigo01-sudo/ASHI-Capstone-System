<?php
session_start();

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'u304756553_ashi_classroom';
$db_username = 'u304756553_ashi_classroom';
$db_password = 'Ashi_Classroom17';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$teacher_id = $_SESSION['teacher_id'];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: view_quizzes.php");
    exit();
}

// Get form data
$quiz_id = isset($_POST['quiz_id']) ? intval($_POST['quiz_id']) : 0;
$subject_id = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : 0;
$quiz_title = isset($_POST['quiz_title']) ? trim($_POST['quiz_title']) : '';
$quiz_description = isset($_POST['quiz_description']) ? trim($_POST['quiz_description']) : '';
$quiz_type = isset($_POST['quiz_type']) ? $_POST['quiz_type'] : '';
$questions = isset($_POST['questions']) ? $_POST['questions'] : [];

// Validate required fields
if (empty($quiz_id) || empty($subject_id) || empty($quiz_title) || empty($quiz_type) || empty($questions)) {
    echo "<script>alert('Missing required fields!'); window.history.back();</script>";
    exit();
}

// Verify quiz ownership
$stmt = $pdo->prepare("SELECT id FROM quizzes WHERE id = ? AND teacher_id = ?");
$stmt->execute([$quiz_id, $teacher_id]);
if (!$stmt->fetch()) {
    echo "<script>alert('Access denied!'); window.location.href='view_quizzes.php';</script>";
    exit();
}

try {
    // Begin transaction
    $pdo->beginTransaction();

    // Calculate total points
    $total_points = 0;
    foreach ($questions as $q) {
        $total_points += 1; // Default 1 point per question, can be customized
    }

    // Update quiz details
    $stmt = $pdo->prepare("
        UPDATE quizzes 
        SET title = ?, 
            description = ?, 
            quiz_type = ?, 
            total_points = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND teacher_id = ?
    ");
    $stmt->execute([
        $quiz_title,
        $quiz_description,
        $quiz_type,
        $total_points,
        $quiz_id,
        $teacher_id
    ]);

    // Get existing question IDs
    $stmt = $pdo->prepare("SELECT id FROM quiz_questions WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);
    $existing_question_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $updated_question_ids = [];

    // Process each question
    foreach ($questions as $q_num => $question_data) {
        $question_text = trim($question_data['text']);
        $is_required = isset($question_data['required']) ? intval($question_data['required']) : 1;
        $correction_text = isset($question_data['correction']) ? trim($question_data['correction']) : null;
        
        // Check if this is an existing question or new one
        $question_id = isset($question_data['id']) ? intval($question_data['id']) : 0;
        
        if ($question_id > 0 && in_array($question_id, $existing_question_ids)) {
            // Update existing question
            $stmt = $pdo->prepare("
                UPDATE quiz_questions 
                SET question_text = ?, 
                    question_number = ?, 
                    is_required = ?,
                    correction_text = ?
                WHERE id = ? AND quiz_id = ?
            ");
            $stmt->execute([
                $question_text,
                $q_num,
                $is_required,
                $correction_text,
                $question_id,
                $quiz_id
            ]);
            
            $updated_question_ids[] = $question_id;
        } else {
            // Insert new question
            $stmt = $pdo->prepare("
                INSERT INTO quiz_questions (quiz_id, question_number, question_text, question_type, points, is_required, correction_text)
                VALUES (?, ?, ?, ?, 1.00, ?, ?)
            ");
            $stmt->execute([
                $quiz_id,
                $q_num,
                $question_text,
                $quiz_type,
                $is_required,
                $correction_text
            ]);
            
            $question_id = $pdo->lastInsertId();
            $updated_question_ids[] = $question_id;
        }

        // Delete existing options for this question (we'll re-insert them)
        $stmt = $pdo->prepare("DELETE FROM quiz_question_options WHERE question_id = ?");
        $stmt->execute([$question_id]);

        // Handle different question types
        if ($quiz_type === 'multiple-choice' || $quiz_type === 'checkboxes') {
            if (isset($question_data['options']) && is_array($question_data['options'])) {
                $correct_answers = [];
                
                // Get correct answers
                if ($quiz_type === 'multiple-choice' && isset($question_data['correct'])) {
                    $correct_answers = [intval($question_data['correct'])];
                } elseif ($quiz_type === 'checkboxes' && isset($question_data['correct']) && is_array($question_data['correct'])) {
                    $correct_answers = array_map('intval', $question_data['correct']);
                }
                
                // Insert options
                foreach ($question_data['options'] as $opt_index => $option_text) {
                    if (!empty(trim($option_text))) {
                        $is_correct = in_array($opt_index, $correct_answers) ? 1 : 0;
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO quiz_question_options (question_id, option_text, is_correct, option_order)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $question_id,
                            trim($option_text),
                            $is_correct,
                            $opt_index
                        ]);
                    }
                }
            }
        } elseif ($quiz_type === 'true-false' || $quiz_type === 'modified-true-false') {
            if (isset($question_data['correct'])) {
                $correct_answer = $question_data['correct']; // 'true' or 'false'
                
                $stmt = $pdo->prepare("
                    INSERT INTO quiz_question_options (question_id, option_text, is_correct, option_order)
                    VALUES (?, ?, 1, 0)
                ");
                $stmt->execute([
                    $question_id,
                    $correct_answer
                ]);
            }
        }
        // For short-answer and paragraph, no options needed
    }

    // Delete questions that were removed
    $questions_to_delete = array_diff($existing_question_ids, $updated_question_ids);
    if (!empty($questions_to_delete)) {
        $placeholders = str_repeat('?,', count($questions_to_delete) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM quiz_questions WHERE id IN ($placeholders) AND quiz_id = ?");
        $stmt->execute(array_merge($questions_to_delete, [$quiz_id]));
    }

    // Commit transaction
    $pdo->commit();

    // Redirect to quiz list with success message
    echo "<script>
        alert('Quiz updated successfully!');
        window.location.href = 'view_quizzes.php';
    </script>";

} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    echo "<script>
        alert('Error updating quiz: " . addslashes($e->getMessage()) . "');
        window.history.back();
    </script>";
}
?>
