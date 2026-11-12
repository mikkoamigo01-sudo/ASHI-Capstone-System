<?php
session_start();
// fetch_student_subjects.php
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$student_id = $_SESSION['student_id'];

// Get student's enrolled subjects (only active enrollments and active subjects)
$stmt = $conn->prepare("
    SELECT 
        s.*, 
        t.fullname AS teacher_name, 
        ss.enrollment_date, 
        ss.status AS enrollment_status,
        CASE 
            WHEN t.advisory_grade = st.grade_level 
             AND t.advisory_section = st.section 
            THEN 1 
            ELSE 0 
        END AS is_adviser
    FROM student_subjects ss
    JOIN subjects s ON ss.subject_id = s.id
    JOIN teachers t ON s.teacher_id = t.id
    JOIN students st ON st.id = ss.student_id
    WHERE ss.student_id = ? 
        AND ss.status = 'active' 
        AND s.status = 'active'
        AND t.status = 'active'
    ORDER BY ss.enrollment_date DESC
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    exit();
}

$stmt->bind_param("i", $student_id);
$stmt->execute();
$enrolled_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success'  => true,
    'subjects' => $enrolled_subjects,
    'count'    => count($enrolled_subjects)
]);
?>