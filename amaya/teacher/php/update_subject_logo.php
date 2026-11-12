<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$host     = 'localhost';
$dbname   = 'u304756553_ashi_classroom';
$db_user  = 'u304756553_ashi_classroom';
$db_pass  = 'Ashi_Classroom17';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$subject_id = intval($_POST['subject_id'] ?? 0);
$logo_data  = $_POST['logo_data'] ?? '';

if (!$subject_id || empty($logo_data)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Verify teacher owns this subject
$stmt = $pdo->prepare("SELECT id, logo_path, class_code FROM subjects WHERE id = ? AND teacher_id = ?");
$stmt->execute([$subject_id, $teacher_id]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$subject) {
    echo json_encode(['success' => false, 'message' => 'Subject not found or permission denied']);
    exit;
}

// Parse base64
if (!preg_match('/^data:image\/(\w+);base64,/', $logo_data, $type)) {
    echo json_encode(['success' => false, 'message' => 'Invalid image data']);
    exit;
}

$ext      = strtolower($type[1]);
$imgData  = substr($logo_data, strpos($logo_data, ',') + 1);
$imgData  = base64_decode($imgData);

if ($imgData === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to decode image']);
    exit;
}

if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
    echo json_encode(['success' => false, 'message' => 'Unsupported image type']);
    exit;
}

// Size check (5MB)
if (strlen($imgData) > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Image exceeds 5MB limit']);
    exit;
}

$upload_dir = 'uploads/banner/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$filename    = $upload_dir . $subject['class_code'] . '_' . time() . '.' . $ext;
$old_logo    = $subject['logo_path'];

if (!file_put_contents($filename, $imgData)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save image file']);
    exit;
}

// Update DB
$stmt = $pdo->prepare("UPDATE subjects SET logo_path = ?, updated_at = NOW() WHERE id = ? AND teacher_id = ?");
$stmt->execute([$filename, $subject_id, $teacher_id]);

// Delete old logo if it exists and is different
if ($old_logo && $old_logo !== $filename && file_exists($old_logo)) {
    unlink($old_logo);
}

echo json_encode([
    'success'   => true,
    'message'   => 'Logo updated successfully',
    'logo_path' => $filename
]);