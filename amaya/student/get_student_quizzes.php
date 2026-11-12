<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$student_id = $_SESSION['student_id'];

$stmt = $conn->prepare("
    SELECT 
        q.id,
        q.title,
        q.description,
        q.due_date,
        q.time_limit,
        q.max_attempts,
        q.total_points,
        q.status AS quiz_status,
        s.subject_name,
        s.theme_color,
        t.fullname AS teacher_name,
        (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) AS question_count,

        -- Count ANY attempt regardless of status (opening = attempt consumed)
        (SELECT COUNT(*) FROM quiz_attempts 
         WHERE quiz_id = q.id AND student_id = ?) AS attempts_used,

        -- Status of the attempt (to know if submitted/graded/in-progress)
        (SELECT status FROM quiz_attempts 
         WHERE quiz_id = q.id AND student_id = ? 
         ORDER BY started_at DESC LIMIT 1) AS attempt_status,

        -- Score and percentage only from submitted/graded attempts
        (SELECT MAX(percentage) FROM quiz_attempts 
         WHERE quiz_id = q.id AND student_id = ?
         AND status IN ('submitted', 'graded')) AS best_percentage,

        (SELECT MAX(score) FROM quiz_attempts 
         WHERE quiz_id = q.id AND student_id = ?
         AND status IN ('submitted', 'graded')) AS best_score

    FROM quizzes q
    INNER JOIN subjects s ON q.subject_id = s.id
    INNER JOIN teachers t ON q.teacher_id = t.id
    INNER JOIN student_subjects ss ON s.id = ss.subject_id
    WHERE ss.student_id = ?
    AND q.status = 'published'
    AND ss.status = 'active'
    AND s.status = 'active'
    AND t.status = 'active'
    ORDER BY q.due_date ASC
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit;
}

$stmt->bind_param("iiiii", $student_id, $student_id, $student_id, $student_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

$quizzes       = [];
$pending_count = 0;
$now           = new DateTime();

while ($row = $result->fetch_assoc()) {
    $attempts_used  = (int)$row['attempts_used'];
    $attempt_status = $row['attempt_status']; // in-progress / submitted / graded / null

    // ── Determine student-facing status ──────────────────────────────
    // Rule: ANY attempt existing = locked out (opening = attempt consumed)
    if ($attempts_used > 0) {
        if ($attempt_status === 'graded') {
            $status = 'graded';       // fully scored, final
        } elseif ($attempt_status === 'submitted') {
            $status = 'submitted';    // done, pending manual review
        } else {
            // in-progress = opened but abandoned, still locked
            $status = 'abandoned';
        }
    } else {
        // No attempt at all
        $due_date = $row['due_date'] ? new DateTime($row['due_date']) : null;
        if ($due_date && $due_date < $now) {
            $status = 'overdue';
        } else {
            $status = 'pending';
            $pending_count++;
        }
    }

    $quizzes[] = [
        'id'             => (int)$row['id'],
        'title'          => $row['title'],
        'description'    => $row['description'],
        'due_date'       => $row['due_date'],
        'time_limit'     => $row['time_limit'],
        'max_attempts'   => (int)$row['max_attempts'],
        'total_points'   => (float)$row['total_points'],
        'subject_name'   => $row['subject_name'],
        'subject_color'  => $row['theme_color'],
        'teacher_name'   => $row['teacher_name'],
        'question_count' => (int)$row['question_count'],
        'attempts_used'  => $attempts_used,
        'attempt_status' => $attempt_status,
        'status'         => $status,
        'score'          => $row['best_score']      ? (float)$row['best_score']                : null,
        'percentage'     => $row['best_percentage'] ? round((float)$row['best_percentage'], 1) : null,
    ];
}

$stmt->close();

echo json_encode([
    'success'       => true,
    'quizzes'       => $quizzes,
    'pending_count' => $pending_count,
    'total_count'   => count($quizzes)
]);

$conn->close();
?>