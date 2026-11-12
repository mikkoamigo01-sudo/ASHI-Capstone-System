<?php
session_start();
header('Content-Type: application/json');

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

if (!isset($_GET['attempt_id'])) {
    echo json_encode(['success' => false, 'message' => 'Attempt ID required']);
    exit();
}

$attempt_id = (int) $_GET['attempt_id'];
$teacher_id = (int) $_SESSION['teacher_id'];

// Get attempt details with verification
$stmt = $conn->prepare("
    SELECT qa.*,
           s.first_name, s.last_name,
           q.teacher_id,
           q.passing_score
    FROM quiz_attempts qa
    JOIN students s ON qa.student_id = s.id
    JOIN quizzes  q ON qa.quiz_id    = q.id
    WHERE qa.id = ? AND q.teacher_id = ?
");
$stmt->bind_param("ii", $attempt_id, $teacher_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    echo json_encode(['success' => false, 'message' => 'Attempt not found or access denied']);
    exit();
}

// Get all responses with question details
$stmt = $conn->prepare("
    SELECT qr.*,
           qq.question_text,
           qq.question_type,
           qq.points          AS max_points,
           qo.option_text     AS selected_option_text,
           qqa.answer_text    AS correct_answer_text
    FROM quiz_responses        qr
    JOIN  quiz_questions        qq  ON qr.question_id       = qq.id
    LEFT JOIN quiz_question_options  qo  ON qr.selected_option_id = qo.id
    LEFT JOIN quiz_question_answers  qqa ON qq.id                 = qqa.question_id
    WHERE qr.attempt_id = ?
    ORDER BY qq.question_order ASC
");
$stmt->bind_param("i", $attempt_id);
$stmt->execute();
$responses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Prepare correct-option lookup (reused in loop)
$correct_opt_stmt = $conn->prepare("
    SELECT option_text
    FROM quiz_question_options
    WHERE question_id = ? AND is_correct = 1
    LIMIT 1
");

// Format responses
$formatted_responses = [];

foreach ($responses as $response) {
    $q_type = $response['question_type'];

    // Student's answer display
    if ($q_type === 'multiple-choice' || $q_type === 'true-false') {
        $student_answer = $response['selected_option_text'] ?: 'No answer selected';
    } else {
        $student_answer = $response['answer_text'] ?: 'No answer provided';
    }

    // Correct answer display
    if ($q_type === 'multiple-choice' || $q_type === 'true-false') {
        $qid = (int) $response['question_id'];
        $correct_opt_stmt->bind_param("i", $qid);
        $correct_opt_stmt->execute();
        $correct_row    = $correct_opt_stmt->get_result()->fetch_assoc();
        $correct_answer = $correct_row ? $correct_row['option_text'] : '';
    } else {
        $correct_answer = $response['correct_answer_text'] ?: '';
    }

    $formatted_responses[] = [
        'question_text'    => $response['question_text'],
        'question_type'    => $q_type,
        'student_answer'   => htmlspecialchars($student_answer),
        'correct_answer'   => htmlspecialchars($correct_answer),
        'is_correct'       => (bool)  $response['is_correct'],
        'points_earned'    => (float) $response['points_earned'],
        'max_points'       => (float) $response['max_points'],
        'teacher_feedback' => $response['teacher_feedback']
                                ? htmlspecialchars($response['teacher_feedback'])
                                : null,
    ];
}

$correct_opt_stmt->close();

// Format attempt data
$formatted_attempt = [
    'student_name'       => htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']),
    'attempt_number'     => $attempt['attempt_number'],
    'started_at'         => $attempt['started_at'],
    'submitted_at'       => $attempt['submitted_at'],
    'score'              => (float) $attempt['score'],
    'max_score'          => (float) $attempt['max_score'],
    'percentage'         => round((float) $attempt['percentage'], 1),
    'status'             => $attempt['status'],
    'status_display'     => ucfirst(str_replace('-', ' ', $attempt['status'])),
    'time_taken_display' => $attempt['time_taken']
                                ? gmdate("i:s", $attempt['time_taken']) . ' min'
                                : null,
    'passing_score'      => (float) $attempt['passing_score'],
];

echo json_encode([
    'success'   => true,
    'attempt'   => $formatted_attempt,
    'responses' => $formatted_responses,
]);
?>