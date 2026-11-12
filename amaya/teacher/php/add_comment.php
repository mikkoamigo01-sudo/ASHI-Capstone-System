<?php
session_start();

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

// Check if user is logged in (either teacher or student)
$user_type = null;
$user_id = null;

if (isset($_SESSION['teacher_id'])) {
    $user_type = 'teacher';
    $user_id = $_SESSION['teacher_id'];
} elseif (isset($_SESSION['student_id'])) {
    $user_type = 'student';
    $user_id = $_SESSION['student_id'];
} else {
    $_SESSION['error'] = "You must be logged in to post a comment.";
    header("Location: ../login.php");
    exit();
}

// Validate POST data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: " . ($_POST['return_url'] ?? 'teacher_dashb.php'));
    exit();
}

// Get form data
$commentable_type = $_POST['commentable_type'] ?? null;
$commentable_id   = $_POST['commentable_id']   ?? null;
$comment_text     = trim($_POST['comment_text'] ?? '');
$return_url       = $_POST['return_url']        ?? null;

// Validate required fields
if (empty($commentable_type) || empty($commentable_id) || empty($comment_text)) {
    $_SESSION['error'] = "All fields are required.";
    header("Location: " . ($return_url ?? 'teacher_dashb.php'));
    exit();
}

// Validate commentable_type
$valid_types = ['material', 'assignment', 'announcement', 'submission'];
if (!in_array($commentable_type, $valid_types)) {
    $_SESSION['error'] = "Invalid comment type.";
    header("Location: " . ($return_url ?? 'teacher_dashb.php'));
    exit();
}

// Helper: run a prepared statement and return first row (or false)
function fetch_one(mysqli $conn, string $sql, string $types, array $params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

// Helper: run an INSERT prepared statement
function execute_stmt(mysqli $conn, string $sql, string $types, array $params): bool {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ── Access check ─────────────────────────────────────────────────────────────
$access_granted = false;

switch ($commentable_type) {
    case 'material':
        if ($user_type === 'teacher') {
            $row = fetch_one($conn,
                "SELECT id FROM materials WHERE id = ? AND teacher_id = ?",
                "ii", [$commentable_id, $user_id]);
        } else {
            $row = fetch_one($conn,
                "SELECT m.id
                 FROM materials m
                 JOIN student_subjects ss ON m.subject_id = ss.subject_id
                 WHERE m.id = ? AND ss.student_id = ? AND ss.status = 'active'",
                "ii", [$commentable_id, $user_id]);
        }
        $access_granted = $row !== false && $row !== null;
        break;

    case 'assignment':
        if ($user_type === 'teacher') {
            $row = fetch_one($conn,
                "SELECT id FROM assignments WHERE id = ? AND teacher_id = ?",
                "ii", [$commentable_id, $user_id]);
        } else {
            $row = fetch_one($conn,
                "SELECT a.id
                 FROM assignments a
                 JOIN student_subjects ss ON a.subject_id = ss.subject_id
                 WHERE a.id = ? AND ss.student_id = ? AND ss.status = 'active'",
                "ii", [$commentable_id, $user_id]);
        }
        $access_granted = $row !== false && $row !== null;
        break;

    case 'announcement':
        if ($user_type === 'teacher') {
            $row = fetch_one($conn,
                "SELECT id FROM announcements WHERE id = ? AND teacher_id = ?",
                "ii", [$commentable_id, $user_id]);
        } else {
            $row = fetch_one($conn,
                "SELECT an.id
                 FROM announcements an
                 JOIN student_subjects ss ON an.subject_id = ss.subject_id
                 WHERE an.id = ? AND ss.student_id = ? AND ss.status = 'active'",
                "ii", [$commentable_id, $user_id]);
        }
        $access_granted = $row !== false && $row !== null;
        break;

    case 'submission':
        if ($user_type === 'teacher') {
            $row = fetch_one($conn,
                "SELECT s.id
                 FROM submissions s
                 JOIN assignments a ON s.assignment_id = a.id
                 WHERE s.id = ? AND a.teacher_id = ?",
                "ii", [$commentable_id, $user_id]);
        } else {
            $row = fetch_one($conn,
                "SELECT id FROM submissions WHERE id = ? AND student_id = ?",
                "ii", [$commentable_id, $user_id]);
        }
        $access_granted = $row !== false && $row !== null;
        break;
}

if (!$access_granted) {
    $_SESSION['error'] = "You don't have permission to comment on this item.";
    header("Location: " . ($return_url ?? 'teacher_dashb.php'));
    exit();
}

// ── Insert comment ────────────────────────────────────────────────────────────
$inserted = execute_stmt($conn,
    "INSERT INTO comments (commentable_type, commentable_id, user_type, user_id, comment_text)
     VALUES (?, ?, ?, ?, ?)",
    "sisis", [$commentable_type, $commentable_id, $user_type, $user_id, $comment_text]);

if (!$inserted) {
    $_SESSION['error'] = "Error posting comment: " . $conn->error;
    header("Location: " . ($return_url ?? 'teacher_dashb.php'));
    exit();
}

// ── Notifications ─────────────────────────────────────────────────────────────
$table_map = [
    'material'     => 'materials',
    'announcement' => 'announcements',
    'assignment'   => 'assignments',
];

if (isset($table_map[$commentable_type])) {
    $table = $table_map[$commentable_type];

    $item = fetch_one($conn,
        "SELECT subject_id, teacher_id FROM $table WHERE id = ?",
        "i", [$commentable_id]);

    if ($item) {
        if ($user_type === 'student' && $item['teacher_id']) {
            // Notify teacher
            execute_stmt($conn,
                "INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
                 VALUES ('teacher', ?, 'comment', 'New Comment', ?, ?)",
                "isi", [$item['teacher_id'], "A student commented on your $commentable_type", $commentable_id]);
        }

        if ($user_type === 'teacher' && $item['subject_id']) {
            // Notify all enrolled students
            $stmt = $conn->prepare(
                "SELECT student_id FROM student_subjects WHERE subject_id = ? AND status = 'active'"
            );
            $stmt->bind_param("i", $item['subject_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();

            while ($srow = $result->fetch_assoc()) {
                execute_stmt($conn,
                    "INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
                     VALUES ('student', ?, 'comment', 'New Comment', ?, ?)",
                    "isi", [$srow['student_id'], "Your teacher commented on a $commentable_type", $commentable_id]);
            }
        }
    }

} elseif ($commentable_type === 'submission') {
    $submission = fetch_one($conn,
        "SELECT s.student_id, a.teacher_id
         FROM submissions s
         JOIN assignments a ON s.assignment_id = a.id
         WHERE s.id = ?",
        "i", [$commentable_id]);

    if ($submission) {
        if ($user_type === 'teacher') {
            execute_stmt($conn,
                "INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
                 VALUES ('student', ?, 'comment', 'New Comment', 'Your teacher commented on your submission', ?)",
                "ii", [$submission['student_id'], $commentable_id]);
        } else {
            execute_stmt($conn,
                "INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
                 VALUES ('teacher', ?, 'comment', 'New Comment', 'A student commented on their submission', ?)",
                "ii", [$submission['teacher_id'], $commentable_id]);
        }
    }
}

$_SESSION['success'] = "Comment posted successfully!";

// ── Redirect ──────────────────────────────────────────────────────────────────
if ($return_url) {
    header("Location: " . $return_url);
} else {
    $default_page = $user_type === 'teacher' ? 'teacher_dashb.php' : '../student/student_dashb.php';
    header("Location: " . $default_page);
}
exit();