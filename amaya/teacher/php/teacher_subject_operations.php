<?php
session_start();
header('Content-Type: application/json');

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$teacher_id = (int) $_SESSION['teacher_id'];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        createTeacherSubject($conn, $teacher_id);
        break;
    case 'list':
        listTeacherSubjects($conn, $teacher_id);
        break;
    case 'update_status':
        updateTeacherSubjectStatus($conn, $teacher_id);
        break;
    case 'delete':
        deleteTeacherSubject($conn, $teacher_id);
        break;
    case 'get_sections':
        getSectionsByGrade($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ── Functions ─────────────────────────────────────────────────────────────────

function createTeacherSubject(mysqli $conn, int $teacher_id): void {
    $subject_name = trim($_POST['subject_name'] ?? '');
    $section      = trim($_POST['section']      ?? '');
    $grade        = trim($_POST['grade']        ?? '');
    $theme_color  = trim($_POST['theme_color']  ?? '#1a73e8');
    $logo_data    = $_POST['logo_data']         ?? '';

    if (empty($subject_name) || empty($grade) || empty($logo_data)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }

    $class_code = generateTeacherUniqueClassCode($conn);
    $logo_path  = saveTeacherLogoImage($logo_data, $class_code);

    if (!$logo_path) {
        echo json_encode(['success' => false, 'message' => 'Failed to save logo']);
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO subjects (teacher_id, subject_name, section, grade, class_code, theme_color, logo_path, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
    ");
    $stmt->bind_param("issssss", $teacher_id, $subject_name, $section, $grade, $class_code, $theme_color, $logo_path);

    if ($stmt->execute()) {
        $subject_id = $stmt->insert_id;
        echo json_encode([
            'success'    => true,
            'message'    => 'Subject created successfully',
            'subject_id' => $subject_id,
            'class_code' => $class_code,
            'logo_path'  => $logo_path,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create subject']);
    }

    $stmt->close();
}

function listTeacherSubjects(mysqli $conn, int $teacher_id): void {
    $stmt = $conn->prepare("
        SELECT
            s.*,
            COUNT(DISTINCT ss.student_id) AS student_count,
            COUNT(DISTINCT st.section)    AS section_count
        FROM subjects s
        LEFT JOIN student_subjects ss ON s.id = ss.subject_id AND ss.status = 'active'
        LEFT JOIN students         st ON ss.student_id = st.id
        WHERE s.teacher_id = ?
          AND s.status != 'deactivated'
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'subjects' => $subjects]);
}

function updateTeacherSubjectStatus(mysqli $conn, int $teacher_id): void {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $status     = $_POST['status'] ?? 'active';

    if (!in_array($status, ['active', 'disabled', 'archived', 'deactivated'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }

    $stmt = $conn->prepare("UPDATE subjects SET status = ? WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("sii", $status, $subject_id, $teacher_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Status updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }

    $stmt->close();
}

function deleteTeacherSubject(mysqli $conn, int $teacher_id): void {
    $subject_id = (int) ($_POST['subject_id'] ?? 0);

    // Fetch logo path before deleting
    $stmt = $conn->prepare("SELECT logo_path FROM subjects WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $subject_id, $teacher_id);
    $stmt->execute();
    $subject = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$subject) {
        echo json_encode(['success' => false, 'message' => 'Subject not found']);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $subject_id, $teacher_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        if (!empty($subject['logo_path']) && file_exists($subject['logo_path'])) {
            unlink($subject['logo_path']);
        }
        echo json_encode(['success' => true, 'message' => 'Subject deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete subject']);
    }

    $stmt->close();
}

function getSectionsByGrade(mysqli $conn): void {
    $grade = trim($_GET['grade'] ?? '');

    if (empty($grade)) {
        echo json_encode(['success' => false, 'message' => 'Grade is required']);
        return;
    }

    $stmt = $conn->prepare("
        SELECT section_name FROM sections
        WHERE grade_level = ? AND status = 'active'
        ORDER BY section_name ASC
    ");
    $stmt->bind_param("s", $grade);
    $stmt->execute();
    $sections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'sections' => $sections]);
}

function generateTeacherUniqueClassCode(mysqli $conn): string {
    $stmt = $conn->prepare("SELECT id FROM subjects WHERE class_code = ?");
    do {
        $code = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
    } while ($exists);
    $stmt->close();

    return $code;
}

function saveTeacherLogoImage(string $base64_data, string $class_code): string|false {
    $upload_dir = 'uploads/banner/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (!preg_match('/^data:image\/(\w+);base64,/', $base64_data, $type)) {
        return false;
    }

    $ext  = strtolower($type[1]);
    $data = base64_decode(substr($base64_data, strpos($base64_data, ',') + 1));

    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || $data === false) {
        return false;
    }

    $filename = $upload_dir . $class_code . '_' . time() . '.' . $ext;

    return file_put_contents($filename, $data) !== false ? $filename : false;
}
?>