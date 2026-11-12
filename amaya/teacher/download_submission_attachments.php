<?php
session_start();

if (!isset($_SESSION['teacher_id'])) {
    die('Not authenticated');
}
if (!isset($_GET['submission_id'])) {
    die('Submission ID required');
}

$submission_id = (int)$_GET['submission_id'];
$teacher_id    = $_SESSION['teacher_id'];

require_once '../db_connection/ashi_dbcnnt.php';

// Verify submission belongs to this teacher's assignment
$verify_stmt = $conn->prepare("
    SELECT sub.id
    FROM submissions sub
    INNER JOIN assignments a ON sub.assignment_id = a.id
    WHERE sub.id = ? AND a.teacher_id = ?
");
$verify_stmt->bind_param("ii", $submission_id, $teacher_id);
$verify_stmt->execute();
$verify_stmt->store_result();

if ($verify_stmt->num_rows === 0) {
    die('Unauthorized access');
}
$verify_stmt->close();

// Get all attachments for this submission
$stmt = $conn->prepare("
    SELECT file_name, file_path
    FROM submission_attachments
    WHERE submission_id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('No attachments found');
}

// Single file — download directly
if ($result->num_rows === 1) {
    $row       = $result->fetch_assoc();
    $file_path = $row['file_path'];

    if (file_exists($file_path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($row['file_name']) . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        $conn->close();
        exit;
    } else {
        die('File not found');
    }
}

// Multiple files — create a ZIP archive
$zip          = new ZipArchive();
$zip_filename = 'submission_' . $submission_id . '_attachments_' . date('YmdHis') . '.zip';
$zip_path     = sys_get_temp_dir() . '/' . $zip_filename;

if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
    die('Could not create ZIP file');
}

while ($row = $result->fetch_assoc()) {
    $file_path = $row['file_path'];
    if (file_exists($file_path)) {
        $zip->addFile($file_path, $row['file_name']);
    }
}
$zip->close();
$conn->close();

if (file_exists($zip_path)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    unlink($zip_path);
    exit;
} else {
    die('Could not create ZIP file');
}
?>