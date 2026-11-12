<?php
session_start();

if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../db_connection/ashi_dbcnnt.php';

if (!isset($_GET['id'])) {
    $_SESSION['error'] = "Comment ID is required.";
    header("Location: teacher_dashb.php");
    exit();
}

$comment_id = (int)$_GET['id'];
$teacher_id = $_SESSION['teacher_id'];
$return_url = $_GET['return_url'] ?? 'teacher_dashb.php';

// Get comment details
$stmt = $conn->prepare("
    SELECT c.*
    FROM comments c
    WHERE c.id = ?
");
$stmt->bind_param("i", $comment_id);
$stmt->execute();
$comment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$comment) {
    $_SESSION['error'] = "Comment not found.";
    header("Location: " . $return_url);
    exit();
}

// Check permissions: teacher owns the comment
$can_delete = ($comment['user_type'] === 'teacher' && $comment['user_id'] == $teacher_id);

// Check if teacher owns the parent item
if (!$can_delete) {
    $owner_id = null;
    $commentable_id = (int)$comment['commentable_id'];

    if ($comment['commentable_type'] === 'material') {
        $stmt = $conn->prepare("SELECT teacher_id FROM materials WHERE id = ?");
        $stmt->bind_param("i", $commentable_id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $owner_id = $item['teacher_id'] ?? null;
        $stmt->close();
    } elseif ($comment['commentable_type'] === 'assignment') {
        $stmt = $conn->prepare("SELECT teacher_id FROM assignments WHERE id = ?");
        $stmt->bind_param("i", $commentable_id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $owner_id = $item['teacher_id'] ?? null;
        $stmt->close();
    } elseif ($comment['commentable_type'] === 'announcement') {
        $stmt = $conn->prepare("SELECT teacher_id FROM announcements WHERE id = ?");
        $stmt->bind_param("i", $commentable_id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $owner_id = $item['teacher_id'] ?? null;
        $stmt->close();
    }

    if ($owner_id == $teacher_id) $can_delete = true;
}

if (!$can_delete) {
    $_SESSION['error'] = "You don't have permission to delete this comment.";
    header("Location: " . $return_url);
    exit();
}

// Delete the comment
$stmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
$stmt->bind_param("i", $comment_id);
if ($stmt->execute()) {
    $_SESSION['success'] = "Comment deleted successfully.";
} else {
    $_SESSION['error'] = "Error deleting comment.";
}
$stmt->close();
$conn->close();

header("Location: " . $return_url);
exit();
?>