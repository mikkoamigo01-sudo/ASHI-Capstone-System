<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$student_id = $_SESSION['student_id'];

// Get all materials from subjects the student is enrolled in
// Only include materials from teachers with 'active' status
// Exclude deactivated materials and deactivated subjects
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
        s.id AS subject_id,
        t.fullname AS teacher_name,
        t.profile_picture AS teacher_picture,
        t.status AS teacher_status
    FROM materials m
    JOIN subjects s ON m.subject_id = s.id
    JOIN teachers t ON m.teacher_id = t.id
    JOIN student_subjects ss ON ss.subject_id = s.id
    WHERE ss.student_id = ? 
    AND ss.status = 'active'
    AND t.status = 'active'
    AND s.status != 'deactivated'
    AND m.status != 'deactivated'
    ORDER BY m.created_at DESC
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit();
}

$stmt->bind_param("i", $student_id);
$stmt->execute();
$materials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Process materials to add additional info
foreach ($materials as &$material) {
    // Get file info if it's a file type
    if ($material['material_type'] === 'file' && $material['file_path']) {
        if (file_exists($material['file_path'])) {
            $material['file_size']      = filesize($material['file_path']);
            $material['file_name']      = basename($material['file_path']);
            $material['file_extension'] = strtolower(pathinfo($material['file_path'], PATHINFO_EXTENSION));
        }
    }
    // Format date
    $material['formatted_date'] = date('M d, Y', strtotime($material['created_at']));
    $material['time_ago']       = timeAgo($material['created_at']);
}

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'count'     => count($materials)
]);

// Helper function for time ago
function timeAgo($datetime) {
    $now  = new DateTime();
    $ago  = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->d == 0) {
        if ($diff->h == 0) {
            if ($diff->i == 0) {
                return 'Just now';
            }
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d == 1) {
        return 'Yesterday';
    } elseif ($diff->d < 7) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d < 30) {
        $weeks = floor($diff->d / 7);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', strtotime($datetime));
    }
}
?>