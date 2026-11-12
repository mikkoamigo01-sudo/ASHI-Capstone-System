<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Set response header to JSON
header('Content-Type: application/json');

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode([
        'success'  => false,
        'message'  => 'User not logged in',
        'redirect' => 'login.php'
    ]);
    exit();
}

$student_id = $_SESSION['student_id'];

// Fetch only assignments that haven't been submitted yet (pending and overdue only)
$stmt = $conn->prepare("
    SELECT 
        a.id,
        a.title,
        a.description,
        a.instructions,
        a.due_date,
        a.max_points,
        a.assignment_type,
        a.status AS assignment_status,
        a.allow_late_submission,
        a.created_at,
        a.updated_at,
        s.id AS subject_id,
        s.subject_name,
        s.section,
        s.grade,
        s.theme_color,
        t.fullname AS teacher_name
    FROM assignments a
    INNER JOIN subjects s ON a.subject_id = s.id
    INNER JOIN student_subjects ss ON s.id = ss.subject_id
    INNER JOIN teachers t ON a.teacher_id = t.id
    LEFT JOIN submissions sub ON a.id = sub.assignment_id AND sub.student_id = ?
    WHERE ss.student_id = ? 
        AND ss.status = 'active'
        AND s.status = 'active'
        AND t.status = 'active'
        AND a.status = 'published'
        AND sub.id IS NULL
    ORDER BY a.due_date ASC
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit();
}

$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

$tasks = [];
$stats = [
    'total'   => 0,
    'pending' => 0,
    'overdue' => 0
];

while ($row = $result->fetch_assoc()) {
    $task_id = $row['id'];

    // Fetch assignment attachments
    $attachments = [];
    $att_stmt = $conn->prepare("
        SELECT id, file_name, file_path, file_type, file_size 
        FROM assignment_attachments 
        WHERE assignment_id = ?
    ");
    if ($att_stmt) {
        $att_stmt->bind_param("i", $task_id);
        $att_stmt->execute();
        $attachments = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $att_stmt->close();
    }

    // Determine task status (only pending or overdue)
    $now      = new DateTime();
    $due_date = new DateTime($row['due_date']);

    if ($due_date < $now) {
        $stats['overdue']++;
    } else {
        $stats['pending']++;
    }

    // Build task object
    $tasks[] = [
        'id'                   => $row['id'],
        'title'                => $row['title'],
        'description'          => $row['description'],
        'instructions'         => $row['instructions'],
        'due_date'             => $row['due_date'],
        'max_points'           => $row['max_points'],
        'assignment_type'      => $row['assignment_type'],
        'assignment_status'    => $row['assignment_status'],
        'allow_late_submission' => (bool)$row['allow_late_submission'],
        'created_at'           => $row['created_at'],
        'updated_at'           => $row['updated_at'],
        'subject_id'           => $row['subject_id'],
        'subject_name'         => $row['subject_name'],
        'section'              => $row['section'],
        'grade'                => $row['grade'],
        'theme_color'          => $row['theme_color'],
        'teacher_name'         => $row['teacher_name'],
        'attachments'          => $attachments,
        'submission'           => null
    ];

    $stats['total']++;
}

$stmt->close();

echo json_encode([
    'success'    => true,
    'tasks'      => $tasks,
    'stats'      => $stats,
    'student_id' => $student_id
], JSON_PRETTY_PRINT);

$conn->close();
?>