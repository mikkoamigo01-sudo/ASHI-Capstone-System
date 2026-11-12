<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../login.php");
    exit();
}

// Get comment ID and material ID
if (!isset($_GET['id']) || !isset($_GET['material_id'])) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: student_dashb.php");
    exit();
}

$comment_id  = intval($_GET['id']);
$material_id = intval($_GET['material_id']);
$student_id  = $_SESSION['student_id'];

// Verify the comment exists and belongs to this student
$stmt = $conn->prepare("
    SELECT * FROM comments 
    WHERE id = ? AND user_type = 'student' AND user_id = ?
");
if (!$stmt) {
    header("Location: view_subject_material.php?id=" . $material_id . "&error=" . urlencode("Server error."));
    exit();
}
$stmt->bind_param("ii", $comment_id, $student_id);
$stmt->execute();
$comment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$comment) {
    header("Location: view_subject_material.php?id=" . $material_id . "&error=" . urlencode("You can only delete your own comments."));
    exit();
}

// Verify the comment belongs to this material
if ($comment['commentable_type'] !== 'material' || $comment['commentable_id'] != $material_id) {
    header("Location: view_subject_material.php?id=" . $material_id . "&error=" . urlencode("Invalid comment."));
    exit();
}

// All checks passed — delete the comment
$stmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
if (!$stmt) {
    header("Location: view_subject_material.php?id=" . $material_id . "&error=" . urlencode("Error deleting comment."));
    exit();
}
$stmt->bind_param("i", $comment_id);

if ($stmt->execute()) {
    header("Location: view_subject_material.php?id=" . $material_id . "&success=" . urlencode("Comment deleted successfully."));
} else {
    header("Location: view_subject_material.php?id=" . $material_id . "&error=" . urlencode("Error deleting comment."));
}

$stmt->close();
exit();
?>