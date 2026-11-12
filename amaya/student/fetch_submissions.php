<?php
// fetch_submissions.php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$student_id = $_SESSION['student_id'];

// Fetch student's submissions with related assignment and subject information
// Only include submissions where the teacher status is 'active'
$stmt = $conn->prepare("
    SELECT 
        s.id AS submission_id,
        s.assignment_id,
        s.submitted_at,
        s.status,
        s.score,
        s.teacher_feedback,
        s.graded_at,
        a.title AS assignment_title,
        a.description AS assignment_description,
        a.max_points,
        a.due_date,
        a.assignment_type,
        sub.subject_name,
        sub.section,
        sub.theme_color,
        t.fullname AS teacher_name,
        t.profile_picture AS teacher_picture,
        t.status AS teacher_status
    FROM submissions s
    INNER JOIN assignments a ON s.assignment_id = a.id
    INNER JOIN subjects sub ON a.subject_id = sub.id
    INNER JOIN teachers t ON a.teacher_id = t.id
    WHERE s.student_id = ?
    AND t.status = 'active'
    ORDER BY s.submitted_at DESC
");

if (!$stmt) {
    echo json_encode(['error' => 'Query preparation failed']);
    exit();
}

$stmt->bind_param("i", $student_id);
$stmt->execute();
$submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats = [
    'total'   => count($submissions),
    'graded'  => count(array_filter($submissions, function($s) {
        return $s['status'] === 'graded' || $s['status'] === 'returned';
    })),
    'pending' => count(array_filter($submissions, function($s) {
        return $s['status'] === 'submitted' || $s['status'] === 'late';
    })),
    'late'    => count(array_filter($submissions, function($s) {
        return $s['status'] === 'late';
    }))
];

// Calculate average grade
$gradedSubmissions = array_filter($submissions, function($s) {
    return ($s['status'] === 'graded' || $s['status'] === 'returned') && $s['score'] !== null;
});

$averageGrade = 0;
if (count($gradedSubmissions) > 0) {
    $totalScore     = 0;
    $totalMaxPoints = 0;
    foreach ($gradedSubmissions as $sub) {
        $totalScore     += $sub['score'];
        $totalMaxPoints += $sub['max_points'];
    }
    $averageGrade = $totalMaxPoints > 0 ? ($totalScore / $totalMaxPoints) * 100 : 0;
}
$stats['average_grade'] = round($averageGrade, 2);

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success'     => true,
    'submissions' => $submissions,
    'stats'       => $stats
]);
?>