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
$quiz_id    = isset($input['quiz_id'])    ? (int)$input['quiz_id']    : 0;
$attempt_id = isset($input['attempt_id']) ? (int)$input['attempt_id'] : 0;
$answers    = isset($input['answers'])    ? $input['answers']         : [];
$time_taken = isset($input['time_taken']) && $input['time_taken'] !== null
                ? (int)$input['time_taken'] : null;

if (!$quiz_id || !$attempt_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

try {
    // 1. Verify attempt
    $stmt = $conn->prepare("
        SELECT id FROM quiz_attempts
        WHERE id = ? AND student_id = ? AND quiz_id = ? AND status = 'in-progress'
    ");
    $stmt->bind_param("iii", $attempt_id, $student_id, $quiz_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close(); $conn->close();
        echo json_encode(['success' => false, 'message' => 'Invalid or already submitted attempt']);
        exit;
    }
    $stmt->close();

    // 2. Get quiz settings
    $stmt = $conn->prepare("SELECT show_results FROM quizzes WHERE id = ?");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $quiz = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 3. Load all questions
    $stmt = $conn->prepare("
        SELECT id, question_type, points
        FROM quiz_questions WHERE quiz_id = ?
        ORDER BY question_order ASC
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 4. Prepare the option-check statement ONCE (separate variable from loop)
    $opt_stmt = $conn->prepare("
        SELECT is_correct FROM quiz_question_options
        WHERE id = ? AND question_id = ?
    ");

    $total_score = 0.0;
    $max_score   = 0.0;
    $needs_manual = false;

    foreach ($questions as $question) {
        $qid   = (int)$question['id'];
        $qtype = $question['question_type'];
        $pts   = (float)$question['points'];
        $max_score += $pts;

        $is_correct         = 0;
        $selected_option_id = null;
        $answer_text        = null;
        $points_earned      = 0.0;

        // --- Score per type ---
        if ($qtype === 'multiple-choice' || $qtype === 'true-false') {

            $key = 'question_' . $qid;
            if (!empty($answers[$key])) {
                $selected_option_id = (int)$answers[$key];
                $opt_stmt->bind_param("ii", $selected_option_id, $qid);
                $opt_stmt->execute();
                $opt = $opt_stmt->get_result()->fetch_assoc();
                if ($opt && (int)$opt['is_correct'] === 1) {
                    $is_correct    = 1;
                    $points_earned = $pts;
                    $total_score  += $pts;
                }
            }

        } elseif ($qtype === 'modified-true-false') {
            $needs_manual = true;
            $tf_key   = 'question_' . $qid . '_tf';
            $text_key = 'question_' . $qid . '_text';

            if (!empty($answers[$tf_key])) {
                $selected_option_id = (int)$answers[$tf_key];
                $answer_text = isset($answers[$text_key]) ? trim($answers[$text_key]) : '';

                $opt_stmt->bind_param("ii", $selected_option_id, $qid);
                $opt_stmt->execute();
                $opt = $opt_stmt->get_result()->fetch_assoc();
                // Only auto-credit if they picked "True" AND it IS correct
                if ($opt && (int)$opt['is_correct'] === 1) {
                    $is_correct    = 1;
                    $points_earned = $pts;
                    $total_score  += $pts;
                }
                // else awaits teacher manual grading
            }

        } elseif ($qtype === 'short-answer') {
            $needs_manual = true;
            $key = 'question_' . $qid;
            if (isset($answers[$key])) {
                $answer_text = trim($answers[$key]);

                // Attempt auto-grade from quiz_question_answers table
                $sa_stmt = $conn->prepare("
                    SELECT answer_text FROM quiz_question_answers
                    WHERE question_id = ? LIMIT 1
                ");
                $sa_stmt->bind_param("i", $qid);
                $sa_stmt->execute();
                $stored = $sa_stmt->get_result()->fetch_assoc();
                $sa_stmt->close();

                if ($stored &&
                    strtolower(trim($stored['answer_text'])) === strtolower($answer_text)
                ) {
                    $is_correct    = 1;
                    $points_earned = $pts;
                    $total_score  += $pts;
                    $needs_manual  = false; // exact match — no manual needed
                }
            }
        }

        // --- Insert response using direct query to handle NULL option safely ---
        $esc_text = ($answer_text !== null)
            ? "'" . $conn->real_escape_string($answer_text) . "'"
            : "NULL";
        $opt_val  = ($selected_option_id !== null) ? (int)$selected_option_id : "NULL";

        $conn->query("
            INSERT INTO quiz_responses
                (attempt_id, question_id, selected_option_id, answer_text, is_correct, points_earned)
            VALUES
                ($attempt_id, $qid, $opt_val, $esc_text, $is_correct, $points_earned)
            ON DUPLICATE KEY UPDATE
                selected_option_id = $opt_val,
                answer_text        = $esc_text,
                is_correct         = $is_correct,
                points_earned      = $points_earned
        ");
    }

    $opt_stmt->close();

    // 5. Calculate percentage
    $percentage  = $max_score > 0 ? round(($total_score / $max_score) * 100, 2) : 0.0;
    $new_status  = $needs_manual ? 'submitted' : 'graded';

    // 6. Update attempt
    $upd = $conn->prepare("
        UPDATE quiz_attempts
        SET submitted_at = NOW(),
            time_taken   = ?,
            score        = ?,
            max_score    = ?,
            percentage   = ?,
            status       = ?
        WHERE id = ?
    ");
    $upd->bind_param("idddsi",
        $time_taken, $total_score, $max_score, $percentage, $new_status, $attempt_id
    );

    if ($upd->execute()) {
        $upd->close();
        $conn->close();
        echo json_encode([
            'success'      => true,
            'attempt_id'   => $attempt_id,
            'score'        => $total_score,
            'max_score'    => $max_score,
            'percentage'   => $percentage,
            'status'       => $new_status,
            'show_results' => (bool)$quiz['show_results']
        ]);
    } else {
        $upd->close();
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Failed to update attempt']);
    }

} catch (Exception $e) {
    if ($conn) $conn->close();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>