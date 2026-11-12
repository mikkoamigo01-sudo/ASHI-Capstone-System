<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$teacher_id    = $_SESSION['teacher_id'];
$submission_id = (int)$_POST['submission_id'];
$score         = floatval($_POST['score']);
$feedback      = isset($_POST['feedback']) ? trim($_POST['feedback']) : '';

if (empty($submission_id) || $score < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

require_once '../db_connection/ashi_dbcnnt.php';

$conn->begin_transaction();

$verify_stmt = $conn->prepare("
    SELECT 
        sub.id, sub.student_id, sub.assignment_id,
        a.max_points, a.title as assignment_title, a.subject_id,
        s.subject_name,
        st.first_name, st.last_name, st.email
    FROM submissions sub
    INNER JOIN assignments a ON sub.assignment_id = a.id
    INNER JOIN subjects s ON a.subject_id = s.id
    INNER JOIN students st ON sub.student_id = st.id
    WHERE sub.id = ? AND a.teacher_id = ?
");
$verify_stmt->bind_param("ii", $submission_id, $teacher_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Unauthorized access or submission not found']);
    exit;
}

$submission_data = $verify_result->fetch_assoc();

if ($score > $submission_data['max_points']) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Score cannot exceed maximum points (' . $submission_data['max_points'] . ')']);
    exit;
}

$update_stmt = $conn->prepare("
    UPDATE submissions
    SET score = ?, teacher_feedback = ?, status = 'graded', graded_at = NOW(), graded_by = ?
    WHERE id = ?
");
$update_stmt->bind_param("dsii", $score, $feedback, $teacher_id, $submission_id);

if (!$update_stmt->execute()) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to update submission']);
    exit;
}

$notification_title   = 'Assignment Graded: ' . $submission_data['assignment_title'];
$notification_message = 'Your submission for "' . $submission_data['assignment_title'] . '" in ' . $submission_data['subject_name'] . ' has been graded. Score: ' . $score . '/' . $submission_data['max_points'];

$notification_stmt = $conn->prepare("
    INSERT INTO notifications 
    (user_type, user_id, notification_type, title, message, related_id, created_at)
    VALUES ('student', ?, 'grade', ?, ?, ?, NOW())
");
$notification_stmt->bind_param("issi",
    $submission_data['student_id'],
    $notification_title,
    $notification_message,
    $submission_id
);

if (!$notification_stmt->execute()) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to create notification']);
    exit;
}

$conn->commit();

echo json_encode([
    'success' => true,
    'message' => 'Grade submitted successfully',
    'data' => [
        'submission_id' => $submission_id,
        'score'         => $score,
        'max_points'    => $submission_data['max_points'],
        'student_name'  => $submission_data['first_name'] . ' ' . $submission_data['last_name']
    ]
]);

$conn->close();
?>