<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../login.php");
    exit();
}

// Check if subject ID is provided
if (!isset($_GET['id'])) {
    $_SESSION['error_message'] = "No subject specified";
    header("Location: student_dashb.php");
    exit();
}

$student_id   = $_SESSION['student_id'];
$subject_id   = intval($_GET['id']);
$student_name = $_SESSION['student_name'];

// Verify student is enrolled in this subject
$stmt = $conn->prepare("
    SELECT ss.*, s.subject_name, s.teacher_id 
    FROM student_subjects ss
    JOIN subjects s ON ss.subject_id = s.id
    WHERE ss.student_id = ? AND ss.subject_id = ? AND ss.status = 'active'
");

if (!$stmt) {
    $_SESSION['error_message'] = "Server error. Please try again.";
    header("Location: student_dashb.php");
    exit();
}

$stmt->bind_param("ii", $student_id, $subject_id);
$stmt->execute();
$enrollment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$enrollment) {
    $_SESSION['error_message'] = "You are not enrolled in this subject";
    header("Location: student_dashb.php");
    exit();
}

// Update enrollment status to 'dropped'
$stmt = $conn->prepare("UPDATE student_subjects SET status = 'dropped' WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $enrollment['id']);
    $stmt->execute();
    $stmt->close();
}

// Create notification for teacher
$notif_title   = "Student Left Class";
$notif_message = "$student_name has left your class: " . $enrollment['subject_name'];
$stmt = $conn->prepare("
    INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id) 
    VALUES ('teacher', ?, 'enrollment', ?, ?, ?)
");
if ($stmt) {
    $stmt->bind_param("issi", $enrollment['teacher_id'], $notif_title, $notif_message, $subject_id);
    $stmt->execute();
    $stmt->close();
}

$_SESSION['success_message'] = "Successfully left " . htmlspecialchars($enrollment['subject_name']);
header("Location: student_dashb.php");
exit();
?>