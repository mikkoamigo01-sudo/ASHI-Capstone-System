<?php
session_start();
header('Content-Type: application/json');

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

$student_id = (int) $_SESSION['student_id'];

// Get all materials from subjects the student is enrolled in
$stmt = $conn->prepare("
    SELECT
        m.id,
        m.title,
        m.description,
        m.material_type,
        m.file_path,
        m.url,
        m.created_at,
        s.subject_name,
        s.section,
        s.theme_color,
        s.id            AS subject_id,
        t.fullname      AS teacher_name,
        t.profile_picture AS teacher_picture
    FROM materials m
    JOIN subjects         s  ON m.subject_id  = s.id
    JOIN teachers         t  ON m.teacher_id  = t.id
    JOIN student_subjects ss ON ss.subject_id = s.id
    WHERE ss.student_id = ? AND ss.status = 'active'
    ORDER BY m.created_at DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$materials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Process materials
foreach ($materials as &$material) {
    // Add file info for file-type materials
    if ($material['material_type'] === 'file' && $material['file_path']) {
        if (file_exists($material['file_path'])) {
            $material['file_size']      = filesize($material['file_path']);
            $material['file_name']      = basename($material['file_path']);
            $material['file_extension'] = strtolower(pathinfo($material['file_path'], PATHINFO_EXTENSION));
        }
    }

    $material['formatted_date'] = date('M d, Y', strtotime($material['created_at']));
    $material['time_ago']       = timeAgo($material['created_at']);
}
unset($material); // break reference

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'count'     => count($materials),
]);

// ── Helper ────────────────────────────────────────────────────────────────────
function timeAgo(string $datetime): string {
    $now  = new DateTime();
    $ago  = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->d === 0) {
        if ($diff->h === 0) {
            if ($diff->i === 0) return 'Just now';
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d === 1) {
        return 'Yesterday';
    } elseif ($diff->d < 7) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d < 30) {
        $weeks = (int) floor($diff->d / 7);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    }

    return date('M d, Y', strtotime($datetime));
}
?>