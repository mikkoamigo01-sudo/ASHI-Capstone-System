<?php
session_start();
header('Content-Type: application/json');

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Teacher not logged in'
    ]);
    exit();
}

$teacher_id = (int) $_SESSION['teacher_id'];

// Get all distinct students enrolled in this teacher's active subjects
$stmt = $conn->prepare("
    SELECT DISTINCT
        s.id,
        s.first_name,
        s.last_name,
        s.email,
        s.username,
        s.grade_level,
        s.section,
        s.birthdate,
        s.contact_number,
        s.guardian_name,
        s.relationship,
        s.guardian_contact,
        s.bio,
        s.profile_picture,
        s.created_at
    FROM students s
    INNER JOIN student_subjects ss  ON s.id       = ss.student_id
    INNER JOIN subjects        sub  ON ss.subject_id = sub.id
    WHERE sub.teacher_id = ?
      AND ss.status  = 'active'
      AND sub.status = 'active'
    ORDER BY s.last_name, s.first_name
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// For each student, get their subjects under this teacher
$subject_stmt = $conn->prepare("
    SELECT
        sub.id   AS subject_id,
        sub.subject_name,
        sub.section,
        sub.grade,
        sub.class_code
    FROM subjects sub
    INNER JOIN student_subjects ss ON sub.id = ss.subject_id
    WHERE ss.student_id  = ?
      AND sub.teacher_id = ?
      AND ss.status      = 'active'
      AND sub.status     = 'active'
    ORDER BY sub.subject_name
");

$final_data = [];

foreach ($students as $student) {
    // Fix profile picture path
    if (!empty($student['profile_picture'])) {
        $student['profile_picture'] = '../student/uploads/profiles/' . basename($student['profile_picture']);
    } else {
        $student['profile_picture'] = null;
    }

    $student_id = (int) $student['id'];
    $subject_stmt->bind_param("ii", $student_id, $teacher_id);
    $subject_stmt->execute();
    $student['subjects'] = $subject_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $final_data[] = $student;
}

$subject_stmt->close();

echo json_encode([
    'success'  => true,
    'students' => $final_data,
    'total'    => count($final_data)
]);
?>