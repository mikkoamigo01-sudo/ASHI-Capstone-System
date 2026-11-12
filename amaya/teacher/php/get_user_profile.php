<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

$teacher_id = (int) $_SESSION['teacher_id'];

$stmt = $conn->prepare("
    SELECT
        id, fullname, birthdate, gender, phone, email,
        address, education, specialization, bio,
        profile_picture, theme_color, created_at
    FROM teachers
    WHERE id = ?
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    echo json_encode(['success' => false, 'message' => 'Profile not found']);
    exit();
}

// Calculate age from birthdate
$teacher['age'] = (new DateTime())->diff(new DateTime($teacher['birthdate']))->y;

// Get initials (max 2 chars)
$initials = '';
foreach (explode(' ', $teacher['fullname']) as $part) {
    if (!empty($part)) $initials .= strtoupper($part[0]);
}
$teacher['initials'] = substr($initials, 0, 2);

// Format member since date
$teacher['member_since'] = date('F Y', strtotime($teacher['created_at']));

echo json_encode(['success' => true, 'data' => $teacher]);
?>