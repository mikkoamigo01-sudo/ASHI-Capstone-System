<?php
ob_start();
session_start();

// Always output JSON, no matter what
ob_clean();
header('Content-Type: application/json');

// Suppress error display so PHP errors don't corrupt JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Catch any fatal errors and return as JSON
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' line ' . $error['line']
        ]);
    }
    ob_end_flush();
});

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in.']);
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

// ── DB connection ──────────────────────────────────────────────────────────────
// Adjust this path to match where ashi_dbcnnt.php actually lives relative to THIS file
require_once '../../db_connection/ashi_dbcnnt.php';

// Safety check: make sure $conn exists and is a valid mysqli connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Check db path and credentials.']);
    exit();
}
// ──────────────────────────────────────────────────────────────────────────────

// Parse JSON input
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input: ' . json_last_error_msg()]);
    exit();
}

if (!isset($input['type']) || !isset($input['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters (type or id)']);
    exit();
}

$type = $input['type'];
$id   = intval($input['id']);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit();
}

// Helper: rollback and return error
function fail($conn, $type, $id, $message) {
    $conn->rollback();
    error_log("Delete item error - Type: $type, ID: $id - $message");
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

$conn->begin_transaction();

switch ($type) {

    case 'assignment':
        // Verify ownership
        $stmt = $conn->prepare("SELECT a.id FROM assignments a JOIN subjects s ON a.subject_id = s.id WHERE a.id = ? AND s.teacher_id = ?");
        if (!$stmt) fail($conn, $type, $id, 'DB prepare error: ' . $conn->error);
        $stmt->bind_param("ii", $id, $teacher_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) fail($conn, $type, $id, 'Assignment not found or permission denied');
        $stmt->close();

        // Delete assignment attachments
        $stmt = $conn->prepare("DELETE FROM assignment_attachments WHERE assignment_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        // Get submission IDs
        $stmt = $conn->prepare("SELECT id FROM submissions WHERE assignment_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute();
        $submissions = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();

        if (!empty($submissions)) {
            $placeholders = implode(',', array_fill(0, count($submissions), '?'));
            $types        = str_repeat('i', count($submissions));
            $stmt = $conn->prepare("DELETE FROM submission_attachments WHERE submission_id IN ($placeholders)");
            $stmt->bind_param($types, ...$submissions);
            $stmt->execute(); $stmt->close();
        }

        // Delete submissions
        $stmt = $conn->prepare("DELETE FROM submissions WHERE assignment_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        // Delete comments
        $stmt = $conn->prepare("DELETE FROM comments WHERE commentable_type = 'assignment' AND commentable_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        // Delete assignment
        $stmt = $conn->prepare("DELETE FROM assignments WHERE id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        break;

    case 'material':
        // Verify ownership
        $stmt = $conn->prepare("SELECT m.id FROM materials m JOIN subjects s ON m.subject_id = s.id WHERE m.id = ? AND s.teacher_id = ?");
        if (!$stmt) fail($conn, $type, $id, 'DB prepare error: ' . $conn->error);
        $stmt->bind_param("ii", $id, $teacher_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) fail($conn, $type, $id, 'Material not found or permission denied');
        $stmt->close();

        // Delete comments
        $stmt = $conn->prepare("DELETE FROM comments WHERE commentable_type = 'material' AND commentable_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        // Get file path before deleting
        $stmt = $conn->prepare("SELECT file_path FROM materials WHERE id = ?");
        $stmt->bind_param("i", $id); $stmt->execute();
        $material = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Delete material record
        $stmt = $conn->prepare("DELETE FROM materials WHERE id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        // Delete physical file if it exists
        if ($material && !empty($material['file_path']) && file_exists($material['file_path'])) {
            @unlink($material['file_path']);
        }
        break;

    case 'quiz':
        // Verify ownership
        $stmt = $conn->prepare("SELECT q.id FROM quizzes q JOIN subjects s ON q.subject_id = s.id WHERE q.id = ? AND s.teacher_id = ?");
        if (!$stmt) fail($conn, $type, $id, 'DB prepare error: ' . $conn->error);
        $stmt->bind_param("ii", $id, $teacher_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) fail($conn, $type, $id, 'Quiz not found or permission denied');
        $stmt->close();

        // Get question IDs
        $stmt = $conn->prepare("SELECT id FROM quiz_questions WHERE quiz_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute();
        $questions = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();

        if (!empty($questions)) {
            $placeholders = implode(',', array_fill(0, count($questions), '?'));
            $types        = str_repeat('i', count($questions));

            $stmt = $conn->prepare("DELETE FROM quiz_question_options WHERE question_id IN ($placeholders)");
            $stmt->bind_param($types, ...$questions); $stmt->execute(); $stmt->close();

            $stmt = $conn->prepare("DELETE FROM quiz_question_answers WHERE question_id IN ($placeholders)");
            $stmt->bind_param($types, ...$questions); $stmt->execute(); $stmt->close();
        }

        // Get attempt IDs
        $stmt = $conn->prepare("SELECT id FROM quiz_attempts WHERE quiz_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute();
        $attempts = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();

        if (!empty($attempts)) {
            $placeholders = implode(',', array_fill(0, count($attempts), '?'));
            $types        = str_repeat('i', count($attempts));

            $stmt = $conn->prepare("DELETE FROM quiz_responses WHERE attempt_id IN ($placeholders)");
            $stmt->bind_param($types, ...$attempts); $stmt->execute(); $stmt->close();
        }

        // Delete attempts, questions, comments, quiz
        $stmt = $conn->prepare("DELETE FROM quiz_attempts WHERE quiz_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        $stmt = $conn->prepare("DELETE FROM comments WHERE commentable_type = 'quiz' AND commentable_id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

        $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        break;

    default:
        fail($conn, $type, $id, 'Invalid type: ' . htmlspecialchars($type));
}

$conn->commit();
$conn->close();

ob_clean();
echo json_encode([
    'success'    => true,
    'message'    => ucfirst($type) . ' deleted successfully',
    'deleted_id' => $id
]);
ob_end_flush();
?>