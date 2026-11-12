<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: student_dashb.php");
    exit();
}

$material_id = intval($_GET['id']);
$student_id  = $_SESSION['student_id'];

// Get material details with subject info and verify student is enrolled
$stmt = $conn->prepare("
    SELECT m.*, 
           s.subject_name, s.section, s.grade, s.theme_color, s.class_code, s.id AS subject_id,
           t.fullname AS teacher_name, t.profile_picture AS teacher_picture
    FROM materials m
    JOIN subjects s ON m.subject_id = s.id
    JOIN teachers t ON m.teacher_id = t.id
    JOIN student_subjects ss ON ss.subject_id = s.id
    WHERE m.id = ? AND ss.student_id = ? AND ss.status = 'active'
");

if (!$stmt) {
    die("Material not found or you don't have permission to view it.");
}

$stmt->bind_param("ii", $material_id, $student_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$material) {
    die("Material not found or you don't have permission to view it.");
}

$subject_id = $material['subject_id'];

// Get student info
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Handle delete comment
if (isset($_POST['delete_comment'])) {
    $comment_id = intval($_POST['comment_id']);

    $stmt = $conn->prepare("SELECT * FROM comments WHERE id = ? AND user_type = 'student' AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $comment_id, $student_id);
        $stmt->execute();
        $comment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($comment) {
            $stmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $comment_id);
                $stmt->execute();
                $stmt->close();
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $material_id . "&deleted=1");
            exit();
        }
    }
}

// Handle add comment
if (isset($_POST['add_comment'])) {
    $comment_text = trim($_POST['comment_text']);

    if (!empty($comment_text)) {
        $stmt = $conn->prepare("
            INSERT INTO comments (commentable_type, commentable_id, user_type, user_id, comment_text)
            VALUES ('material', ?, 'student', ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("iis", $material_id, $student_id, $comment_text);
            $stmt->execute();
            $stmt->close();
        }

        // PRG pattern — prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $material_id . "&success=1");
        exit();
    }
}

// Get comments for this material
$stmt = $conn->prepare("
    SELECT c.*, 
           CASE 
               WHEN c.user_type = 'teacher' THEN t.fullname
               WHEN c.user_type = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
           END AS user_name,
           CASE 
               WHEN c.user_type = 'teacher' THEN t.profile_picture
               WHEN c.user_type = 'student' THEN s.profile_picture
           END AS user_picture
    FROM comments c
    LEFT JOIN teachers t ON c.user_type = 'teacher' AND c.user_id = t.id
    LEFT JOIN students s ON c.user_type = 'student' AND c.user_id = s.id
    WHERE c.commentable_type = 'material' AND c.commentable_id = ?
    ORDER BY c.created_at ASC
");

if (!$stmt) {
    $comments = [];
} else {
    $stmt->bind_param("i", $material_id);
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// Get file icon
function getFileIcon($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $icons = [
        'pdf'  => 'fa-file-pdf',
        'doc'  => 'fa-file-word',
        'docx' => 'fa-file-word',
        'xls'  => 'fa-file-excel',
        'xlsx' => 'fa-file-excel',
        'ppt'  => 'fa-file-powerpoint',
        'pptx' => 'fa-file-powerpoint',
        'jpg'  => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png'  => 'fa-file-image',
        'gif'  => 'fa-file-image',
        'zip'  => 'fa-file-archive',
        'rar'  => 'fa-file-archive',
        'txt'  => 'fa-file-alt',
        'mp4'  => 'fa-file-video',
        'mp3'  => 'fa-file-audio',
    ];
    return $icons[$extension] ?? 'fa-file';
}

// Time ago function
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);

    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   { $m = floor($diff / 60);    return $m . ' minute' . ($m > 1 ? 's' : '') . ' ago'; }
    if ($diff < 86400)  { $h = floor($diff / 3600);  return $h . ' hour'   . ($h > 1 ? 's' : '') . ' ago'; }
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) { $d = floor($diff / 86400); return $d . ' day'    . ($d > 1 ? 's' : '') . ' ago'; }
    return date('M d, Y', strtotime($datetime));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($material['title']); ?> - ClassroomHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: <?php echo $material['theme_color']; ?>;
            --primary-dark: #1967d2;
            --primary-light: #e8f0fe;
            --text-primary: #202124;
            --text-secondary: #5f6368;
            --border-color: #dadce0;
            --bg-color: #f8f9fa;
            --white: #ffffff;
            --success: #1e8e3e;
            --danger: #d93025;
        }

        body {
            font-family: 'Roboto', Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-primary);
            line-height: 1.6;
        }

        /* Navigation Bar */
        .navbar {
            background: var(--white);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }

        .navbar-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-button {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }

        .back-button:hover {
            background-color: var(--bg-color);
            color: var(--text-primary);
        }

        .navbar-brand {
            font-family: 'Google Sans', sans-serif;
            font-size: 22px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        /* Main Container */
        .container {
            max-width: 1000px;
            margin: 24px auto;
            padding: 0 24px;
        }

        /* Material Header Card */
        .material-header {
            background: var(--white);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .material-header-banner {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            padding: 24px;
            color: var(--white);
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            margin-bottom: 12px;
            opacity: 0.9;
        }

        .breadcrumb a {
            color: var(--white);
            text-decoration: none;
            transition: opacity 0.2s;
        }

        .breadcrumb a:hover {
            opacity: 0.8;
        }

        .material-title {
            font-family: 'Google Sans', sans-serif;
            font-size: 32px;
            font-weight: 400;
            margin-bottom: 8px;
        }

        .material-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
            opacity: 0.9;
        }

        .material-meta i {
            margin-right: 4px;
        }

        .material-body {
            padding: 24px;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .teacher-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 16px;
            overflow: hidden;
        }

        .teacher-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .teacher-details {
            flex: 1;
        }

        .teacher-name {
            font-weight: 500;
            font-size: 14px;
            color: var(--text-primary);
        }

        .post-time {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .material-description {
            margin-bottom: 24px;
            line-height: 1.6;
            color: var(--text-primary);
            white-space: pre-wrap;
        }

        /* Material Content */
        .material-content {
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
        }

        .content-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            font-weight: 500;
        }

        /* File Attachment */
        .file-attachment {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .file-attachment:hover {
            box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3);
            border-color: var(--primary-color);
        }

        .file-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            border-radius: 8px;
            color: var(--primary-color);
            font-size: 24px;
        }

        .file-details {
            flex: 1;
        }

        .file-name {
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .file-meta {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .file-action {
            color: var(--primary-color);
            font-size: 20px;
        }

        /* Link Attachment */
        .link-attachment {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .link-attachment:hover {
            box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3);
            border-color: var(--primary-color);
        }

        .link-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8f5e9;
            border-radius: 8px;
            color: #1e8e3e;
            font-size: 24px;
        }

        .link-details {
            flex: 1;
        }

        .link-url {
            font-weight: 500;
            color: var(--primary-color);
            margin-bottom: 4px;
            word-break: break-all;
        }

        .link-description {
            font-size: 12px;
            color: var(--text-secondary);
        }

        /* Video Embed */
        .video-embed {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }

        .video-container {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
        }

        .video-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }

        /* Comments Section */
        .comments-section {
            background: var(--white);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .comments-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 500;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .comment-count {
            color: var(--text-secondary);
            font-weight: 400;
            font-size: 14px;
        }

        .add-comment-form {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .comment-input-wrapper {
            display: flex;
            gap: 12px;
        }

        .comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 16px;
            flex-shrink: 0;
            overflow: hidden;
        }

        .comment-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .comment-form-field {
            flex: 1;
        }

        .comment-input {
            width: 100%;
            border: none;
            border-bottom: 1px solid var(--border-color);
            padding: 8px 0;
            font-size: 14px;
            font-family: 'Roboto', Arial, sans-serif;
            resize: none;
            outline: none;
            transition: border-color 0.2s;
        }

        .comment-input:focus {
            border-bottom-color: var(--primary-color);
        }

        .comment-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 12px;
        }

        .btn {
            padding: 8px 24px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            font-family: 'Google Sans', sans-serif;
        }

        .btn-text {
            background: none;
            color: var(--primary-color);
        }

        .btn-text:hover {
            background: var(--primary-light);
        }

        .btn-primary {
            background: var(--primary-color);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3);
        }

        .btn-primary:disabled {
            background: var(--border-color);
            cursor: not-allowed;
        }

        .comments-list {
            padding: 24px;
        }

        .comment-item {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            position: relative;
        }

        .comment-item:last-child {
            margin-bottom: 0;
        }

        .comment-content {
            flex: 1;
            min-width: 0; /* Important for text overflow to work */
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .comment-author {
            font-weight: 500;
            font-size: 14px;
            color: var(--text-primary);
        }

        .comment-text {
            font-size: 14px;
            color: var(--text-primary);
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }

        .comment-delete {
            position: absolute;
            top: 0;
            right: 0;
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.2s;
            opacity: 0;
        }

        .comment-item:hover .comment-delete {
            opacity: 1;
        }

        .comment-delete:hover {
            background: #fce8e6;
            color: var(--danger);
        }

        .empty-comments {
            padding: 40px;
            text-align: center;
            color: var(--text-secondary);
        }

        .empty-comments i {
            font-size: 48px;
            color: var(--border-color);
            margin-bottom: 16px;
        }

        .notification {
            position: fixed;
            top: 80px;
            right: 24px;
            background: var(--white);
            border-radius: 8px;
            padding: 16px 24px;
            box-shadow: 0 2px 6px 2px rgba(60,64,67,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10000;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            border-left: 4px solid var(--success);
        }

        .notification.error {
            border-left: 4px solid var(--danger);
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Delete Confirmation Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.2s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--white);
            border-radius: 8px;
            padding: 24px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 16px;
            color: var(--text-primary);
        }

        .modal-body {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }

        .btn-danger:hover {
            background: #c5221f;
            box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3);
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 16px;
            }

            .material-title {
                font-size: 24px;
            }

            .navbar-brand {
                font-size: 18px;
            }

            .breadcrumb {
                font-size: 12px;
            }

            .comment-delete {
                opacity: 1; /* Always show on mobile */
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-container">
            <div class="navbar-left">
                <button class="back-button" onclick="window.location.href='student_dashb.php'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="navbar-brand">
                    ClassroomHub
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="material-header">
            <div class="material-header-banner">
                <div class="breadcrumb">
                    <a href="student_dash.php">Dashboard</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="student_dash.php#lectures">Materials</a>
                    <i class="fas fa-chevron-right"></i>
                    <span><?php echo htmlspecialchars($material['subject_name']); ?></span>
                </div>
                <h1 class="material-title"><?php echo htmlspecialchars($material['title']); ?></h1>
                <div class="material-meta">
                    <span>
                        <i class="fas fa-book"></i>
                        <?php echo ucfirst($material['material_type']); ?>
                    </span>
                    <span>
                        <i class="fas fa-calendar"></i>
                        Posted <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                    </span>
                </div>
            </div>

            <div class="material-body">
                <div class="teacher-info">
                    <div class="teacher-avatar">
                        <?php if ($material['teacher_picture']): ?>
                            <img src="../teacher/<?php echo htmlspecialchars($material['teacher_picture']); ?>" 
                                 alt="<?php echo htmlspecialchars($material['teacher_name']); ?>"
                                 onerror="this.style.display='none'; this.parentElement.textContent='<?php echo strtoupper(substr($material['teacher_name'], 0, 1)); ?>';">
                        <?php else: ?>
                            <?php echo strtoupper(substr($material['teacher_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="teacher-details">
                        <div class="teacher-name"><?php echo htmlspecialchars($material['teacher_name']); ?></div>
                        <div class="post-time"><?php echo timeAgo($material['created_at']); ?></div>
                    </div>
                </div>

                <?php if ($material['description']): ?>
                <div class="material-description">
                    <?php echo nl2br(htmlspecialchars($material['description'])); ?>
                </div>
                <?php endif; ?>

                <div class="material-content">
                    <div class="content-label">
                        <?php 
                        switch($material['material_type']) {
                            case 'file': echo 'Attached File'; break;
                            case 'link': echo 'Link'; break;
                            case 'video': echo 'Video'; break;
                            default: echo 'Content';
                        }
                        ?>
                    </div>

                    <?php if ($material['material_type'] === 'file' && $material['file_path']): ?>
                        <a href="../teacher/php/<?php echo htmlspecialchars($material['file_path']); ?>" 
                           target="_blank" 
                           class="file-attachment">
                            <div class="file-icon">
                                <i class="fas <?php echo getFileIcon($material['file_path']); ?>"></i>
                            </div>
                            <div class="file-details">
                                <div class="file-name"><?php echo basename($material['file_path']); ?></div>
                                <div class="file-meta">
                                    <?php 
                                    $fullPath = '../teacher/php/' . $material['file_path'];
                                    if (file_exists($fullPath)) {
                                        echo formatFileSize(filesize($fullPath));
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="file-action">
                                <i class="fas fa-eye"></i>
                            </div>
                        </a>

                    <?php elseif ($material['material_type'] === 'link' && $material['url']): ?>
                        <a href="<?php echo htmlspecialchars($material['url']); ?>" 
                           target="_blank" 
                           class="link-attachment">
                            <div class="link-icon">
                                <i class="fas fa-link"></i>
                            </div>
                            <div class="link-details">
                                <div class="link-url"><?php echo htmlspecialchars($material['url']); ?></div>
                                <div class="link-description">Click to open in new tab</div>
                            </div>
                            <div class="file-action">
                                <i class="fas fa-external-link-alt"></i>
                            </div>
                        </a>

                    <?php elseif ($material['material_type'] === 'video' && $material['url']): ?>
                        <div class="video-embed">
                            <div class="video-container">
                                <?php
                                $video_url = $material['url'];
                                $embed_url = '';
                                
                                if (strpos($video_url, 'youtube.com') !== false || strpos($video_url, 'youtu.be') !== false) {
                                    preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/', $video_url, $matches);
                                    if (isset($matches[1])) {
                                        $embed_url = 'https://www.youtube.com/embed/' . $matches[1];
                                    }
                                }
                                ?>
                                <?php if ($embed_url): ?>
                                    <iframe src="<?php echo htmlspecialchars($embed_url); ?>" 
                                            allowfullscreen></iframe>
                                <?php else: ?>
                                    <iframe src="<?php echo htmlspecialchars($video_url); ?>" 
                                            allowfullscreen></iframe>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="comments-section">
            <div class="comments-header">
                <i class="fas fa-comment"></i>
                Class comments
                <span class="comment-count">(<?php echo count($comments); ?>)</span>
            </div>

            <div class="add-comment-form">
                <form method="POST" id="commentForm">
                    <div class="comment-input-wrapper">
                        <div class="comment-avatar">
                            <?php if ($student['profile_picture']): ?>
                                <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                     alt="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>"
                                     onerror="this.style.display='none'; this.parentElement.textContent='<?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>';">
                            <?php else: ?>
                                <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="comment-form-field">
                            <textarea 
                                name="comment_text" 
                                class="comment-input" 
                                placeholder="Add a class comment..."
                                rows="1"
                                id="commentInput"
                                required
                            ></textarea>
                            <div class="comment-actions" id="commentActions" style="display: none;">
                                <button type="button" class="btn btn-text" onclick="cancelComment()">Cancel</button>
                                <button type="submit" name="add_comment" class="btn btn-primary" id="postBtn" disabled>Post</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="comments-list">
                <?php if (empty($comments)): ?>
                    <div class="empty-comments">
                        <i class="fas fa-comments"></i>
                        <p>No comments yet. Be the first to comment!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item">
                            <div class="comment-avatar">
                                <?php if ($comment['user_picture']): ?>
                                    <img src="<?php echo $comment['user_type'] === 'teacher' ? '../teacher/' : ''; ?><?php echo htmlspecialchars($comment['user_picture']); ?>" 
                                         alt="<?php echo htmlspecialchars($comment['user_name']); ?>"
                                         onerror="this.style.display='none'; this.parentElement.textContent='<?php echo strtoupper(substr($comment['user_name'], 0, 1)); ?>';">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($comment['user_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="comment-content">
                                <div class="comment-header">
                                    <span class="comment-author"><?php echo htmlspecialchars($comment['user_name']); ?></span>
                                </div>
                                <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?></div>
                            </div>
                            <?php if ($comment['user_type'] === 'student' && $comment['user_id'] == $student_id): ?>
                                <button class="comment-delete" onclick="confirmDelete(<?php echo $comment['id']; ?>)" title="Delete comment">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Delete Comment</div>
            <div class="modal-body">
                Are you sure you want to delete this comment? This action cannot be undone.
            </div>
            <div class="modal-actions">
                <button class="btn btn-text" onclick="closeDeleteModal()">Cancel</button>
                <form method="POST" id="deleteForm" style="display: inline;">
                    <input type="hidden" name="comment_id" id="deleteCommentId">
                    <button type="submit" name="delete_comment" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const commentInput = document.getElementById('commentInput');
        const commentActions = document.getElementById('commentActions');
        const postBtn = document.getElementById('postBtn');
        const deleteModal = document.getElementById('deleteModal');

        if (commentInput) {
            commentInput.addEventListener('focus', () => {
                commentActions.style.display = 'flex';
            });

            commentInput.addEventListener('input', () => {
                postBtn.disabled = commentInput.value.trim() === '';
                commentInput.style.height = 'auto';
                commentInput.style.height = commentInput.scrollHeight + 'px';
            });
        }

        function cancelComment() {
            commentInput.value = '';
            commentInput.style.height = 'auto';
            commentActions.style.display = 'none';
            postBtn.disabled = true;
        }

        function confirmDelete(commentId) {
            document.getElementById('deleteCommentId').value = commentId;
            deleteModal.classList.add('show');
        }

        function closeDeleteModal() {
            deleteModal.classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && deleteModal.classList.contains('show')) {
                closeDeleteModal();
            }
        });

        <?php if (isset($_GET['success'])): ?>
            showNotification('Comment added successfully', 'success');
            // Clean URL to remove success parameter
            if (window.history.replaceState) {
                const url = new URL(window.location);
                url.searchParams.delete('success');
                window.history.replaceState({}, document.title, url);
            }
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            showNotification('Comment deleted successfully', 'success');
            // Clean URL to remove deleted parameter
            if (window.history.replaceState) {
                const url = new URL(window.location);
                url.searchParams.delete('deleted');
                window.history.replaceState({}, document.title, url);
            }
        <?php endif; ?>

        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            notification.innerHTML = `
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        }
    </script>
</body>
</html>