<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Get material ID from URL
$material_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$material_id) {
    header("Location: student_dashb.php");
    exit();
}

// Get material details and verify student access
$stmt = $conn->prepare("
    SELECT m.*, 
           s.subject_name, 
           s.theme_color,
           s.class_code,
           t.fullname AS teacher_name,
           t.profile_picture AS teacher_profile_picture
    FROM materials m
    JOIN subjects s ON m.subject_id = s.id
    JOIN teachers t ON m.teacher_id = t.id
    JOIN student_subjects ss ON s.id = ss.subject_id
    WHERE m.id = ? AND ss.student_id = ? AND ss.status = 'active'
");

if (!$stmt) {
    header("Location: student_dashb.php");
    exit();
}

$stmt->bind_param("ii", $material_id, $student_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$material) {
    header("Location: student_dashb.php");
    exit();
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
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

        // Post-Redirect-Get pattern
        header("Location: view_subject_material.php?id=" . $material_id . "&success=" . urlencode("Comment posted successfully."));
        exit();
    }
}

// Get comments for this material
$stmt = $conn->prepare("
    SELECT c.*,
           CASE 
               WHEN c.user_type = 'student' THEN s.first_name
               WHEN c.user_type = 'teacher' THEN t.fullname
           END AS commenter_name,
           CASE 
               WHEN c.user_type = 'student' THEN s.profile_picture
               WHEN c.user_type = 'teacher' THEN t.profile_picture
           END AS commenter_picture
    FROM comments c
    LEFT JOIN students s ON c.user_type = 'student' AND c.user_id = s.id
    LEFT JOIN teachers t ON c.user_type = 'teacher' AND c.user_id = t.id
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

// Helper function to get correct profile picture path
function getProfilePicture($profile_picture, $user_type) {
    if (empty($profile_picture)) return null;

    $cleanPath = preg_replace('#^(upload/|upload|uploads/|uploads)#', '', $profile_picture);
    return $user_type === 'teacher'
        ? '../teacher/upload/' . $cleanPath
        : 'upload/' . $cleanPath;
}

// Helper function to get initials
function getInitials($fullname) {
    $initials = '';
    foreach (explode(' ', $fullname) as $part) {
        if (!empty($part)) $initials .= strtoupper($part[0]);
    }
    return substr($initials, 0, 2);
}

// Helper function to format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// Get file extension and info if it's a file
$file_extension = '';
$file_size      = 0;
$file_path_full = '';

if ($material['material_type'] === 'file' && !empty($material['file_path'])) {
    $file_path_full = '../teacher/php/' . $material['file_path'];
    if (file_exists($file_path_full)) {
        $file_size      = filesize($file_path_full);
        $file_extension = strtoupper(pathinfo($material['file_path'], PATHINFO_EXTENSION));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($material['title']); ?> - Material</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
                :root {
            --color-primary: <?php echo htmlspecialchars($material['theme_color']); ?>;
            --color-primary-light: <?php echo htmlspecialchars($material['theme_color']) . 'dd'; ?>;
            --color-primary-dark: <?php echo htmlspecialchars($material['theme_color']) . 'bb'; ?>;
            
            --color-success: #10b981;
            --color-success-light: #d1fae5;
            --color-warning: #f59e0b;
            --color-warning-light: #fef3c7;
            --color-danger: #ef4444;
            --color-danger-light: #fee2e2;
            
            --color-bg-primary: #ffffff;
            --color-bg-secondary: #f8fafc;
            --color-bg-tertiary: #f1f5f9;
            
            --color-text-primary: #0f172a;
            --color-text-secondary: #475569;
            --color-text-tertiary: #94a3b8;
            
            --color-border: #e2e8f0;
            --color-border-light: #f1f5f9;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;
            
            --spacing-xs: 8px;
            --spacing-sm: 12px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
            --spacing-xl: 32px;
            --spacing-2xl: 48px;
            
            --font-display: 'Outfit', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-body);
            background: var(--color-bg-secondary);
            color: var(--color-text-primary);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 var(--spacing-lg);
        }

        /* Navigation Bar */
        .navbar {
            background: var(--color-bg-primary);
            border-bottom: 1px solid var(--color-border);
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.95);
        }

        .navbar-container {
            max-width: 900px;
            margin: 0 auto;
            padding: var(--spacing-md) var(--spacing-lg);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
        }

        .back-button {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--color-bg-secondary);
            border-radius: var(--radius-md);
            color: var(--color-text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .back-button:hover {
            background: var(--color-bg-tertiary);
            color: var(--color-text-primary);
            transform: translateX(-2px);
        }

        .navbar-title {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 600;
            color: var(--color-text-primary);
        }

        /* Material Header */
        .material-header {
            background: var(--color-bg-primary);
            border-radius: var(--radius-lg);
            padding: var(--spacing-xl);
            margin: var(--spacing-xl) auto;
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
        }

        .material-type-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-xs);
            padding: 8px 16px;
            border-radius: var(--radius-lg);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: var(--spacing-md);
        }

        .material-type-badge.file {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .material-type-badge.link {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: var(--color-primary);
        }

        .material-type-badge.video {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: var(--color-danger);
        }

        .material-type-badge.document {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #4f46e5;
        }

        .material-title {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            color: var(--color-text-primary);
            margin-bottom: var(--spacing-md);
            line-height: 1.3;
        }

        .material-meta {
            display: flex;
            align-items: center;
            gap: var(--spacing-lg);
            flex-wrap: wrap;
            padding-bottom: var(--spacing-md);
            border-bottom: 1px solid var(--color-border-light);
            margin-bottom: var(--spacing-lg);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: var(--spacing-xs);
            font-size: 14px;
            color: var(--color-text-secondary);
        }

        .meta-item svg {
            opacity: 0.7;
        }

        .teacher-info-inline {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }

        .teacher-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-light) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            overflow: hidden;
        }

        .teacher-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Description Section */
        .description-section {
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border-light);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-lg);
        }

        .section-title {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: var(--spacing-md);
            display: flex;
            align-items: center;
            gap: var(--spacing-xs);
        }

        .description-text {
            font-size: 14px;
            line-height: 1.7;
            color: var(--color-text-secondary);
        }

        /* File Info Card */
        .file-info-card {
            background: var(--color-bg-primary);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: var(--spacing-xl);
            margin-bottom: var(--spacing-lg);
            text-align: center;
        }

        .file-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto var(--spacing-md);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
        }

        .file-icon.pdf {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: var(--color-danger);
        }

        .file-icon.doc, .file-icon.docx {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: var(--color-primary);
        }

        .file-icon.xls, .file-icon.xlsx {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: var(--color-success);
        }

        .file-icon.ppt, .file-icon.pptx {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: var(--color-warning);
        }

        .file-icon.default {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #4f46e5;
        }

        .file-name {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: var(--spacing-xs);
        }

        .file-size {
            font-size: 14px;
            color: var(--color-text-tertiary);
            margin-bottom: var(--spacing-lg);
        }

        .action-buttons {
            display: flex;
            gap: var(--spacing-sm);
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-xs);
            padding: 12px 24px;
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-body);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-light) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: var(--color-bg-secondary);
            color: var(--color-text-primary);
            border: 1px solid var(--color-border);
        }

        .btn-secondary:hover {
            background: var(--color-bg-tertiary);
        }

        /* Link Card */
        .link-card {
            background: var(--color-bg-primary);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-lg);
        }

        .link-url {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: var(--spacing-md);
            background: var(--color-bg-secondary);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-md);
            word-break: break-all;
        }

        .link-url svg {
            flex-shrink: 0;
            color: var(--color-primary);
        }

        .link-text {
            font-family: monospace;
            font-size: 13px;
            color: var(--color-text-secondary);
        }

        /* Comments Section */
        .comments-section {
            background: var(--color-bg-primary);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
        }

        .comment-form {
            margin-bottom: var(--spacing-xl);
            padding-bottom: var(--spacing-xl);
            border-bottom: 1px solid var(--color-border-light);
        }

        .comment-textarea {
            width: 100%;
            max-width: 100%;
            min-height: 100px;
            padding: var(--spacing-md);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            font-family: var(--font-body);
            font-size: 14px;
            resize: vertical;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .comment-textarea:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .comment-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: var(--spacing-sm);
            margin-top: var(--spacing-md);
        }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
        }

        .comment-item {
            display: flex;
            gap: var(--spacing-md);
            padding: var(--spacing-md);
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border-light);
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
        }

        .comment-item:hover {
            background: var(--color-bg-tertiary);
            border-color: var(--color-border);
        }

        .comment-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-light) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
            overflow: hidden;
        }

        .comment-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .comment-content {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }

        .comment-header {
            display: flex;
            align-items: center;
            gap: var(--spacing-xs);
            margin-bottom: 6px;
        }

        .comment-author {
            font-size: 14px;
            font-weight: 600;
            color: var(--color-text-primary);
        }

        .comment-type {
            font-size: 11px;
            font-weight: 600;
            color: var(--color-text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .comment-time {
            font-size: 12px;
            color: var(--color-text-tertiary);
            margin-left: auto;
        }

        .comment-text {
            font-size: 14px;
            line-height: 1.6;
            color: var(--color-text-secondary);
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        .no-comments {
            text-align: center;
            padding: var(--spacing-xl);
            color: var(--color-text-tertiary);
            font-size: 14px;
        }

        /* Subject Breadcrumb */
        .subject-breadcrumb {
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-xs);
            padding: 6px 12px;
            background: var(--color-bg-secondary);
            border-radius: var(--radius-lg);
            font-size: 12px;
            font-weight: 600;
            color: var(--color-text-secondary);
            text-decoration: none;
            transition: all 0.2s ease;
            margin-bottom: var(--spacing-md);
            display: inline-block;
        }

        .subject-breadcrumb:hover {
            background: var(--color-bg-tertiary);
            color: var(--color-text-primary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 0 var(--spacing-md);
            }

            .navbar-container {
                padding: var(--spacing-sm) var(--spacing-md);
            }

            .material-header {
                padding: var(--spacing-lg);
            }

            .material-title {
                font-size: 22px;
            }

            .material-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--spacing-sm);
            }

            .action-buttons {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        
        .comment-item {
            position: relative;
        }
        
        .comment-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .comment-item:hover .comment-actions {
            opacity: 1;
        }
        
        .btn-delete-comment {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-delete-comment:hover {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .btn-delete-comment i {
            font-size: 13px;
        }

        /* Success/Error notification styles */
        .notification {
            position: fixed;
            top: 80px;
            right: 24px;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            z-index: 10000;
            animation: slideIn 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification.success {
            background: #10b981;
            color: white;
        }

        .notification.error {
            background: #ef4444;
            color: white;
        }

        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(400px); opacity: 0; }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-left">
                <button class="back-button" onclick="window.location.href='view_subject.php?id=<?php echo $material['subject_id']; ?>'" aria-label="Go back">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="navbar-title">Material Details</div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Breadcrumb -->
        <!--a href="view_subject.php?id=<?php echo $material['subject_id']; ?>" class="subject-breadcrumb">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                <path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Back to <?php echo htmlspecialchars($material['subject_name']); ?>
        </a-->

        <!-- Material Header -->
        <div class="material-header">
            <div class="material-type-badge <?php echo htmlspecialchars($material['material_type']); ?>">
                <?php
                $icons = [
                    'file' => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" stroke="currentColor" stroke-width="2"/><polyline points="13 2 13 9 20 9" stroke="currentColor" stroke-width="2"/>',
                    'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2"/>',
                    'video' => '<path d="M23 7l-7 5 7 5V7z" stroke="currentColor" stroke-width="2"/><rect x="1" y="5" width="15" height="14" rx="2" stroke="currentColor" stroke-width="2"/>',
                    'document' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/>'
                ];
                $svg_path = $icons[$material['material_type']] ?? $icons['file'];
                echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none">' . $svg_path . '</svg>';
                echo ucfirst($material['material_type']);
                ?>
            </div>

            <h1 class="material-title"><?php echo htmlspecialchars($material['title']); ?></h1>

            <div class="material-meta">
                <div class="meta-item teacher-info-inline">
                    <div class="teacher-avatar-small">
                        <?php 
                        $teacherProfilePic = getProfilePicture($material['teacher_profile_picture'], 'teacher');
                        if ($teacherProfilePic && file_exists($teacherProfilePic)): 
                        ?>
                            <img src="<?php echo htmlspecialchars($teacherProfilePic); ?>" alt="<?php echo htmlspecialchars($material['teacher_name']); ?>">
                        <?php else: ?>
                            <?php echo getInitials($material['teacher_name']); ?>
                        <?php endif; ?>
                    </div>
                    <span><?php echo htmlspecialchars($material['teacher_name']); ?></span>
                </div>

                <div class="meta-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        <path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    <?php 
                    $date = new DateTime($material['created_at']);
                    echo $date->format('M j, Y • g:i A');
                    ?>
                </div>

                <div class="meta-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    <?php echo htmlspecialchars($material['class_code']); ?>
                </div>
            </div>

            <?php if (!empty($material['description'])): ?>
            <div class="description-section">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Description
                </div>
                <div class="description-text"><?php echo nl2br(htmlspecialchars($material['description'])); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- File/Link Content -->
        <?php if ($material['material_type'] === 'file' && !empty($material['file_path'])): ?>
        <div class="file-info-card">
            <div class="file-icon <?php echo strtolower($file_extension); ?>">
                <?php echo $file_extension ?: 'FILE'; ?>
            </div>
            <div class="file-name"><?php echo htmlspecialchars(basename($material['file_path'])); ?></div>
            <?php if ($file_size > 0): ?>
            <div class="file-size"><?php echo formatFileSize($file_size); ?></div>
            <?php endif; ?>
            <div class="action-buttons">
                <a href="<?php echo htmlspecialchars($file_path_full); ?>" target="_blank" class="btn btn-primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2"/>
                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    View File
                </a>
                <a href="<?php echo htmlspecialchars($file_path_full); ?>" download class="btn btn-secondary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Download
                </a>
            </div>
        </div>

        <?php elseif (($material['material_type'] === 'link' || $material['material_type'] === 'video') && !empty($material['url'])): ?>
        <div class="link-card">
            <div class="section-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2"/>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2"/>
                </svg>
                <?php echo $material['material_type'] === 'video' ? 'Video Link' : 'External Link'; ?>
            </div>
            <div class="link-url">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 16v-4M12 8h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span class="link-text"><?php echo htmlspecialchars($material['url']); ?></span>
            </div>
            <div class="action-buttons">
                <a href="<?php 
                    $url = $material['url'];
                    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
                        $url = "https://" . $url;
                    }
                    echo htmlspecialchars($url);
                ?>" target="_blank" class="btn btn-primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Open Link
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Comments Section -->
        <div class="comments-section">
            <div class="section-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Comments (<?php echo count($comments); ?>)
            </div>

            <!-- Comment Form -->
            <form method="POST" class="comment-form">
                <textarea name="comment_text" class="comment-textarea" placeholder="Add a class comment..." required></textarea>
                <div class="comment-form-actions">
                    <button type="submit" name="add_comment" class="btn btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Post Comment
                    </button>
                </div>
            </form>

            <!-- Comments List -->
            <?php if (count($comments) > 0): ?>
            <div class="comments-list">
                <?php foreach ($comments as $comment): ?>
                <div class="comment-item">
                    <div class="comment-avatar">
                        <?php 
                        $commenterPic = getProfilePicture($comment['commenter_picture'], $comment['user_type']);
                        if ($commenterPic && file_exists($commenterPic)): 
                        ?>
                            <img src="<?php echo htmlspecialchars($commenterPic); ?>" alt="<?php echo htmlspecialchars($comment['commenter_name']); ?>">
                        <?php else: ?>
                            <?php echo getInitials($comment['commenter_name']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="comment-content">
                        <div class="comment-header">
                            <span class="comment-author"><?php echo htmlspecialchars($comment['commenter_name']); ?></span>
                            <span class="comment-type"><?php echo ucfirst($comment['user_type']); ?></span>
                            <span class="comment-time">
                                <?php 
                                $commentDate = new DateTime($comment['created_at']);
                                $now = new DateTime();
                                $diff = $now->diff($commentDate);
                                
                                if ($diff->days == 0) {
                                    if ($diff->h > 0) {
                                        echo $diff->h . 'h ago';
                                    } elseif ($diff->i > 0) {
                                        echo $diff->i . 'm ago';
                                    } else {
                                        echo 'Just now';
                                    }
                                } elseif ($diff->days == 1) {
                                    echo 'Yesterday';
                                } else {
                                    echo $commentDate->format('M j');
                                }
                                ?>
                            </span>
                        </div>
                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?></div>
                    </div>
                    
                    <!-- Delete Button - Only show for student's own comments -->
                    <?php if ($comment['user_type'] === 'student' && $comment['user_id'] == $student_id): ?>
                    <div class="comment-actions">
                        <button class="btn-delete-comment" onclick="deleteComment(<?php echo $comment['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-comments">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" style="margin: 0 auto var(--spacing-sm); display: block; opacity: 0.3;">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="currentColor" stroke-width="2"/>
                </svg>
                No comments yet. Be the first to comment!
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Clear form on page load to prevent showing old data when using back button
        window.addEventListener('pageshow', function(event) {
            // Check if page was loaded from cache (back/forward button)
            if (event.persisted || (window.performance && window.performance.navigation.type == 2)) {
                // Clear the comment textarea
                const textarea = document.querySelector('.comment-textarea');
                if (textarea) {
                    textarea.value = '';
                }
                // Reload the page to get fresh data
                window.location.reload();
            }
        });

        // Delete comment function
        function deleteComment(commentId) {
            if (confirm('Are you sure you want to delete this comment? This action cannot be undone.')) {
                window.location.href = 'delete_comment_student.php?id=' + commentId + '&material_id=<?php echo $material_id; ?>';
            }
        }

        // Show notification if present in URL
        const urlParams = new URLSearchParams(window.location.search);
        const successMsg = urlParams.get('success');
        const errorMsg = urlParams.get('error');

        if (successMsg) {
            showNotification(decodeURIComponent(successMsg), 'success');
            // Clean URL after showing notification
            window.history.replaceState({}, document.title, window.location.pathname + '?id=<?php echo $material_id; ?>');
        }
        if (errorMsg) {
            showNotification(decodeURIComponent(errorMsg), 'error');
            // Clean URL after showing notification
            window.history.replaceState({}, document.title, window.location.pathname + '?id=<?php echo $material_id; ?>');
        }

        function showNotification(message, type = 'info') {
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Auto-resize textarea
        const textarea = document.querySelector('.comment-textarea');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // Smooth scroll animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.material-header, .file-info-card, .link-card, .comments-section');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>