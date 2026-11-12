<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

header('Content-Type: application/json');

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$student_id   = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'] ?? 'Student';
$class_code   = strtoupper(trim($_POST['class_code'] ?? ''));

// Validate class code
if (empty($class_code)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a class code']);
    exit();
}

// Check if class code exists and is active
$stmt = $conn->prepare("SELECT * FROM subjects WHERE class_code = ? AND status = 'active' LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit();
}
$stmt->bind_param("s", $class_code);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subject) {
    echo json_encode(['success' => false, 'message' => 'Invalid or inactive class code']);
    exit();
}

// Get the student's grade level
$stmt = $conn->prepare("SELECT grade_level FROM students WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit();
}
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student record not found']);
    exit();
}

// Compare student's grade level with the subject's grade
if ($student['grade_level'] !== $subject['grade']) {
    echo json_encode(['success' => false, 'message' => 'You are not allowed to join this class. It is not assigned to your grade level.']);
    exit();
}

// Check if student is already enrolled
$stmt = $conn->prepare("SELECT * FROM student_subjects WHERE student_id = ? AND subject_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit();
}
$stmt->bind_param("ii", $student_id, $subject['id']);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    if ($existing['status'] === 'dropped') {
        // Re-enroll if previously dropped
        $stmt = $conn->prepare("UPDATE student_subjects SET status = 'active', enrollment_date = NOW() WHERE id = ?");
        $stmt->bind_param("i", $existing['id']);
        $stmt->execute();
        $stmt->close();

        // Notify teacher
        $notif_title   = "Student Re-enrolled";
        $notif_message = "$student_name has re-joined your class: " . $subject['subject_name'];
        $stmt = $conn->prepare("INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id) VALUES ('teacher', ?, 'enrollment', ?, ?, ?)");
        $stmt->bind_param("issi", $subject['teacher_id'], $notif_title, $notif_message, $subject['id']);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Successfully re-enrolled in ' . $subject['subject_name']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'You are already enrolled in this class']);
    }
    exit();
}

// Enroll student
$stmt = $conn->prepare("INSERT INTO student_subjects (student_id, subject_id, status) VALUES (?, ?, 'active')");
$stmt->bind_param("ii", $student_id, $subject['id']);
$stmt->execute();
$stmt->close();

// Notify teacher
$notif_title   = "New Student Enrolled";
$notif_message = "$student_name has joined your class: " . $subject['subject_name'];
$stmt = $conn->prepare("INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id) VALUES ('teacher', ?, 'enrollment', ?, ?, ?)");
$stmt->bind_param("issi", $subject['teacher_id'], $notif_title, $notif_message, $subject['id']);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Successfully joined ' . $subject['subject_name'] . '!']);
?>