<?php
session_start();

if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../../db_connection/ashi_dbcnnt.php';

if (!isset($_GET['id']) || !isset($_GET['subject_id'])) {
    $_SESSION['error'] = 'Invalid request.';
    header("Location: teacher_dashb.php");
    exit();
}

$material_id = (int)$_GET['id'];
$subject_id  = (int)$_GET['subject_id'];
$teacher_id  = $_SESSION['teacher_id'];

// Get material details and verify ownership
$stmt = $conn->prepare("
    SELECT m.*, s.teacher_id as subject_teacher_id
    FROM materials m
    JOIN subjects s ON m.subject_id = s.id
    WHERE m.id = ? AND m.teacher_id = ?
");
$stmt->bind_param("ii", $material_id, $teacher_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$material) {
    $_SESSION['error'] = 'Material not found or you don\'t have permission to delete it.';
    header("Location: teacher_view_subject.php?id=" . $subject_id . "&tab=classwork");
    exit();
}

$conn->begin_transaction();

// Delete associated comments
$stmt = $conn->prepare("DELETE FROM comments WHERE commentable_type = 'material' AND commentable_id = ?");
$stmt->bind_param("i", $material_id);
if (!$stmt->execute()) {
    $conn->rollback();
    $_SESSION['error'] = 'Failed to delete material comments.';
    header("Location: view_material.php?id=" . $material_id);
    exit();
}
$stmt->close();

// Delete the material from database
$stmt = $conn->prepare("DELETE FROM materials WHERE id = ?");
$stmt->bind_param("i", $material_id);
if (!$stmt->execute()) {
    $conn->rollback();
    $_SESSION['error'] = 'Failed to delete material.';
    header("Location: view_material.php?id=" . $material_id);
    exit();
}
$stmt->close();

// Delete the physical file if it exists
if (!empty($material['file_path']) && file_exists($material['file_path'])) {
    if (!unlink($material['file_path'])) {
        error_log("Failed to delete file: " . $material['file_path']);
    }
}

$conn->commit();
$conn->close();

$_SESSION['success'] = 'Material deleted successfully!';
header("Location: teacher_view_subject.php?id=" . $subject_id . "&tab=classwork");
exit();
?>