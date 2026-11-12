<?php
session_start();
header('Content-Type: application/json');

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

require_once '../db_connection/ashi_dbcnnt.php';

// Get all ungraded submissions for this teacher's subjects
$stmt = $conn->prepare("
    SELECT 
        sub.id,
        sub.assignment_id,
        sub.student_id,
        sub.submission_text,
        sub.submitted_at,
        sub.status,
        a.title as assignment_title,
        a.max_points,
        a.due_date,
        s.id as subject_id,
        s.subject_name,
        st.first_name,
        st.last_name,
        st.email as student_email,
        st.profile_picture,
        CONCAT(st.first_name, ' ', st.last_name) as student_name,
        (SELECT COUNT(*) FROM submission_attachments sa WHERE sa.submission_id = sub.id) as attachment_count
    FROM submissions sub
    INNER JOIN assignments a ON sub.assignment_id = a.id
    INNER JOIN subjects s ON a.subject_id = s.id
    INNER JOIN students st ON sub.student_id = st.id
    WHERE a.teacher_id = ?
    AND sub.status IN ('submitted', 'late')
    AND (sub.score IS NULL OR sub.graded_at IS NULL)
    ORDER BY sub.submitted_at DESC
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();

$submissions = [];
while ($row = $result->fetch_assoc()) {
    // Check if submission is late
    if (!empty($row['due_date']) && new DateTime($row['submitted_at']) > new DateTime($row['due_date'])) {
        $row['status'] = 'late';
    }

    // Strip 'uploads/profiles/' prefix from profile_picture
    if (!empty($row['profile_picture'])) {
        $row['profile_picture'] = str_replace('uploads/profiles/', '', $row['profile_picture']);
    }

    $submissions[] = $row;
}

// Get unique subjects for filter dropdown
$subjects_stmt = $conn->prepare("
    SELECT DISTINCT s.id, s.subject_name
    FROM subjects s
    INNER JOIN assignments a ON s.id = a.subject_id
    WHERE s.teacher_id = ?
    AND s.status = 'active'
    ORDER BY s.subject_name
");
$subjects_stmt->bind_param("i", $teacher_id);
$subjects_stmt->execute();
$subjects_result = $subjects_stmt->get_result();

$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = $row;
}

echo json_encode([
    'success' => true,
    'submissions' => $submissions,
    'subjects' => $subjects,
    'count' => count($submissions)
]);

$conn->close();
?>