<?php
session_start();

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'u304756553_ashi_classroom';
$db_username = 'u304756553_ashi_classroom';
$db_password = 'Ashi_Classroom17';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get material ID from URL
if (!isset($_GET['id'])) {
    header("Location: teacher_dashb.php");
    exit();
}

$material_id = $_GET['id'];
$teacher_id = $_SESSION['teacher_id'];

// Get material details with subject info
$stmt = $pdo->prepare("
    SELECT m.*, s.subject_name, s.section, s.grade, s.class_code, s.theme_color
    FROM materials m
    JOIN subjects s ON m.subject_id = s.id
    WHERE m.id = ? AND m.teacher_id = ?
");
$stmt->execute([$material_id, $teacher_id]);
$material = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$material) {
    die("Material not found or you don't have permission to view it.");
}

$subject_id = $material['subject_id'];

// Get teacher details
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);

// Get enrolled students count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as student_count
    FROM student_subjects
    WHERE subject_id = ? AND status = 'active'
");
$stmt->execute([$subject_id]);
$student_stats = $stmt->fetch(PDO::FETCH_ASSOC);
$total_students = $student_stats['student_count'];

// Get comments for this material
$stmt = $pdo->prepare("
    SELECT c.*, 
           CASE 
               WHEN c.user_type = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
               WHEN c.user_type = 'teacher' THEN t.fullname
           END as user_name,
           CASE 
               WHEN c.user_type = 'student' THEN s.profile_picture
               WHEN c.user_type = 'teacher' THEN t.profile_picture
           END as user_picture
    FROM comments c
    LEFT JOIN students s ON c.user_type = 'student' AND c.user_id = s.id
    LEFT JOIN teachers t ON c.user_type = 'teacher' AND c.user_id = t.id
    WHERE c.commentable_type = 'material' AND c.commentable_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$material_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to format file size
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

// Helper function to get file icon
function getFileIcon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word',
        'docx' => 'fa-file-word',
        'ppt' => 'fa-file-powerpoint',
        'pptx' => 'fa-file-powerpoint',
        'xls' => 'fa-file-excel',
        'xlsx' => 'fa-file-excel',
        'jpg' => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png' => 'fa-file-image',
        'gif' => 'fa-file-image',
        'txt' => 'fa-file-alt',
    ];
    return $icons[$extension] ?? 'fa-file';
}

// Helper function to get file color
function getFileColor($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $colors = [
        'pdf' => '#dc2626',
        'doc' => '#2563eb',
        'docx' => '#2563eb',
        'ppt' => '#ea580c',
        'pptx' => '#ea580c',
        'xls' => '#059669',
        'xlsx' => '#059669',
        'jpg' => '#7c3aed',
        'jpeg' => '#7c3aed',
        'png' => '#7c3aed',
        'gif' => '#7c3aed',
        'txt' => '#64748b',
    ];
    return $colors[$extension] ?? '#6b7280';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($material['title']); ?> - Material</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #3b82f6;
            --primary-dark: #2563eb;
            --text-primary: #1a1a1a;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --background: #fafafa;
            --surface: #ffffff;
            --border: #e5e7eb;
            --border-light: #f3f4f6;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
            background: var(--background);
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 15px;
        }

        /* Navigation Bar */
        .navbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 14px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.9);
        }

        .navbar-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-button {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .back-button:hover {
            background: var(--background);
            color: var(--text-primary);
            border-color: var(--text-muted);
        }

        .navbar-brand {
            font-size: 17px;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }

        .navbar-right {
            display: flex;
            gap: 10px;
        }

        /* Container */
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 24px;
        }

        /* Material Header with Wave Animation */
        .material-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 48px 32px 32px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            color: white;
        }

        /* Animated Wave Background */
        .material-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='rgba(255,255,255,0.1)' d='M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,165.3C1248,171,1344,149,1392,138.7L1440,128L1440,0L1392,0C1344,0,1248,0,1152,0C1056,0,960,0,864,0C768,0,672,0,576,0C480,0,384,0,288,0C192,0,96,0,48,0L0,0Z'%3E%3C/path%3E%3C/svg%3E") no-repeat;
            background-size: cover;
            opacity: 0.3;
            animation: wave 15s ease-in-out infinite;
        }

        .material-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='rgba(255,255,255,0.08)' d='M0,224L48,213.3C96,203,192,181,288,181.3C384,181,480,203,576,213.3C672,224,768,224,864,213.3C960,203,1056,181,1152,170.7C1248,160,1344,160,1392,160L1440,160L1440,0L1392,0C1344,0,1248,0,1152,0C1056,0,960,0,864,0C768,0,672,0,576,0C480,0,384,0,288,0C192,0,96,0,48,0L0,0Z'%3E%3C/path%3E%3C/svg%3E") no-repeat;
            background-size: cover;
            opacity: 0.4;
            animation: wave 12s ease-in-out infinite reverse;
        }

        @keyframes wave {
            0%, 100% {
                transform: translateX(0) translateY(0);
            }
            50% {
                transform: translateX(-25px) translateY(-10px);
            }
        }

        .material-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .material-title {
            font-size: 28px;
            font-weight: 600;
            color: white;
            margin-bottom: 12px;
            letter-spacing: -0.03em;
            position: relative;
            z-index: 1;
        }

        .material-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 13px;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .material-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .material-description {
            color: rgba(255, 255, 255, 0.95);
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 1;
        }

        .material-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
            max-width: 100%;
        }

        /* Card */
        .card {
            background: var(--surface);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .card-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.01em;
        }

        .card-body {
            padding: 24px;
        }

        /* File Preview */
        .file-preview {
            background: var(--border-light);
            border-radius: 10px;
            padding: 32px;
            text-align: center;
            margin-bottom: 16px;
            border: 1px solid var(--border);
        }

        .file-icon-large {
            font-size: 72px;
            margin-bottom: 16px;
            opacity: 0.7;
        }

        .file-name {
            font-size: 15px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 6px;
            word-break: break-word;
        }

        .file-size {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .file-download-btn {
            margin-top: 20px;
        }

        /* URL Preview */
        .url-preview {
            background: var(--border-light);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid var(--border);
        }

        .url-icon {
            width: 44px;
            height: 44px;
            background: var(--text-primary);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 14px;
        }

        .url-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 13px;
            word-break: break-all;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .url-link:hover {
            text-decoration: underline;
        }

        /* Stats Box */
        .stats-box {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-item {
            background: var(--border-light);
            padding: 20px 16px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid var(--border);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
            letter-spacing: -0.02em;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* Subject Info */
        .subject-info {
            background: var(--text-primary);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .subject-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: -0.01em;
        }

        .subject-meta {
            display: flex;
            gap: 12px;
            font-size: 12px;
            opacity: 0.85;
        }

        /* Comments Section */
        .comments-section {
            margin-top: 24px;
        }

        .comment-form {
            background: var(--border-light);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }

        .comment-textarea {
            width: 100%;
            min-height: 90px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
            margin-bottom: 12px;
            background: var(--surface);
            color: var(--text-primary);
        }

        .comment-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .comment-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .comment-item {
            background: var(--border-light);
            padding: 16px;
            border-radius: 10px;
            display: flex;
            gap: 12px;
            border: 1px solid var(--border);
            position: relative;
        }

        .comment-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--text-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 14px;
            flex-shrink: 0;
        }

        .comment-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .comment-content {
            flex: 1;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 6px;
        }

        .comment-author {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 14px;
        }

        .comment-role {
            color: var(--text-secondary);
            font-size: 12px;
            margin-left: 6px;
            font-weight: 400;
        }

        .comment-time {
            color: var(--text-muted);
            font-size: 12px;
        }

        .comment-text {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.6;
        }

        .comment-actions {
            position: absolute;
            top: 12px;
            right: 12px;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .comment-item:hover .comment-actions {
            opacity: 1;
        }
        
        .btn-delete-comment {
            background: none;
            border: none;
            color: #dc2626;
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        
        .btn-delete-comment:hover {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outlined {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-primary);
        }

        .btn-outlined:hover {
            border-color: var(--text-secondary);
            background: var(--border-light);
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
        }

        .btn-success {
            background: #059669;
            color: white;
        }

        .btn-success:hover {
            background: #047857;
            transform: translateY(-1px);
        }

        /* Header Buttons Light Style */
        .material-header .btn-primary {
            background: white;
            color: var(--primary-color);
        }

        .material-header .btn-primary:hover {
            background: rgba(255, 255, 255, 0.95);
        }

        .material-header .btn-outlined {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
        }

        .material-header .btn-outlined:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: var(--text-secondary);
        }

        .empty-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        /* Alert */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            border: 1px solid;
        }

        .alert-success {
            background: #f0fdf4;
            color: #15803d;
            border-color: #86efac;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fca5a5;
        }

        /* Responsive */
        @media (max-width: 968px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats-box {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .navbar-brand {
                font-size: 15px;
            }

            .material-title {
                font-size: 22px;
            }

            .material-header {
                padding: 40px 24px 24px;
            }

            .material-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .container {
                padding: 24px 16px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="navbar-container">
            <div class="navbar-left">
                <button class="back-button" onclick="window.location.href='teacher_view_subject.php?id=<?php echo $subject_id; ?>&tab=classwork'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="navbar-brand">
                    Material View
                </div>
            </div>
            <div class="navbar-right">
                <button class="btn btn-outlined" onclick="window.location.href='teacher_view_subject.php?id=<?php echo $subject_id; ?>'">
                    <i class="fas fa-th-large"></i> Back to Class
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>

        <!-- Material Header -->
        <div class="material-header">
            <div class="material-type-badge">
                <i class="fas fa-<?php echo $material['material_type'] === 'video' ? 'video' : ($material['material_type'] === 'link' ? 'link' : 'file'); ?>"></i>
                <?php echo ucfirst($material['material_type']); ?>
            </div>
            
            <h1 class="material-title"><?php echo htmlspecialchars($material['title']); ?></h1>
            
            <div class="material-meta">
                <div class="material-meta-item">
                    <i class="fas fa-user"></i>
                    <?php echo htmlspecialchars($teacher['fullname']); ?>
                </div>
                <div class="material-meta-item">
                    <i class="fas fa-calendar"></i>
                    Posted <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                </div>
                <div class="material-meta-item">
                    <i class="fas fa-clock"></i>
                    <?php echo date('g:i A', strtotime($material['created_at'])); ?>
                </div>
            </div>

            <?php if ($material['description']): ?>
            <div class="material-description">
                <?php echo nl2br(htmlspecialchars($material['description'])); ?>
            </div>
            <?php endif; ?>

            <div class="material-actions">
                <button class="btn btn-danger" onclick="deleteMaterial()">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Main Content -->
            <div>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-file"></i>
                            Material Content
                        </h2>
                    </div>
                    <div class="card-body">
                        <?php if ($material['material_type'] === 'file' || $material['material_type'] === 'document'): ?>
                            <?php if ($material['file_path'] && file_exists($material['file_path'])): ?>
                                <?php 
                                $filename = basename($material['file_path']);
                                $filesize = filesize($material['file_path']);
                                $fileIcon = getFileIcon($filename);
                                $fileColor = getFileColor($filename);
                                ?>
                                <div class="file-preview">
                                    <i class="fas <?php echo $fileIcon; ?> file-icon-large" style="color: <?php echo $fileColor; ?>;"></i>
                                    <div class="file-name"><?php echo htmlspecialchars($filename); ?></div>
                                    <div class="file-size"><?php echo formatFileSize($filesize); ?></div>
                                    <a href="<?php echo htmlspecialchars($material['file_path']); ?>" target="_blank" class="btn btn-primary file-download-btn">
                                        <i class="fas fa-eye"></i> View File
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <p>File not found</p>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($material['material_type'] === 'link' || $material['material_type'] === 'video'): ?>
                            <div class="url-preview">
                                <div class="url-icon">
                                    <i class="fas fa-<?php echo $material['material_type'] === 'video' ? 'video' : 'link'; ?>"></i>
                                </div>
                                <a href="<?php echo htmlspecialchars($material['url']); ?>" target="_blank" class="url-link">
                                    <?php echo htmlspecialchars($material['url']); ?>
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <a href="<?php echo htmlspecialchars($material['url']); ?>" target="_blank" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class="fas fa-external-link-alt"></i> Open Link
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Comments Section -->
                <div class="card comments-section">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-comments"></i>
                            Class Comments
                        </h2>
                        <span style="color: var(--text-secondary); font-size: 14px;">
                            <?php echo count($comments); ?> comment<?php echo count($comments) != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Comment Form -->
                        <form method="POST" action="add_comment.php" class="comment-form">
                            <input type="hidden" name="commentable_type" value="material">
                            <input type="hidden" name="commentable_id" value="<?php echo $material_id; ?>">
                            <input type="hidden" name="return_url" value="<?php echo $_SERVER['REQUEST_URI']; ?>">
                            <textarea name="comment_text" class="comment-textarea" placeholder="Add a class comment..." required></textarea>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Post Comment
                            </button>
                        </form>

                        <!-- Comment List -->
                        <?php if (empty($comments)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-comments"></i>
                            </div>
                            <p>No comments yet</p>
                        </div>
                        <?php else: ?>
                        <div class="comment-list">
                            <?php foreach ($comments as $comment): ?>
                            <div class="comment-item">
                                <div class="comment-avatar">
                                    <?php if ($comment['user_picture']): ?>
                                        <img src="<?php echo $comment['user_type'] === 'teacher' ? '../' : '../../student/'; ?><?php echo htmlspecialchars($comment['user_picture']); ?>" 
                                             alt="<?php echo htmlspecialchars($comment['user_name']); ?>">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($comment['user_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-content">
                                    <div class="comment-header">
                                        <div>
                                            <span class="comment-author"><?php echo htmlspecialchars($comment['user_name']); ?></span>
                                            <span class="comment-role"><?php echo ucfirst($comment['user_type']); ?></span>
                                        </div>
                                        <span class="comment-time"><?php echo date('M d, Y g:i A', strtotime($comment['created_at'])); ?></span>
                                    </div>
                                    <div class="comment-text">
                                        <?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?>
                                    </div>
                                </div>
                                <!-- Delete Button -->
                                <div class="comment-actions">
                                    <button class="btn-delete-comment" onclick="deleteComment(<?php echo $comment['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div>
                <!-- Subject Info Card -->
                <div class="card">
                    <div class="card-body">
                        <div class="subject-info">
                            <div class="subject-name"><?php echo htmlspecialchars($material['subject_name']); ?></div>
                            <div class="subject-meta">
                                <?php if ($material['section']): ?>
                                <span><?php echo htmlspecialchars($material['section']); ?></span>
                                <?php endif; ?>
                                <span>Grade <?php echo htmlspecialchars($material['grade']); ?></span>
                            </div>
                        </div>

                        <div class="stats-box">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $total_students; ?></div>
                                <div class="stat-label">Students</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo count($comments); ?></div>
                                <div class="stat-label">Comments</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function deleteMaterial() {
            if (confirm('Are you sure you want to delete this material? This action cannot be undone.')) {
                window.location.href = 'delete_material.php?id=<?php echo $material_id; ?>&subject_id=<?php echo $subject_id; ?>';
            }
        }

        function deleteComment(commentId) {
            if (confirm('Are you sure you want to delete this comment? This action cannot be undone.')) {
                window.location.href = 'delete_comment_teacher.php?id=' + commentId + '&return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>';
            }
        }

        // Show notification if present in URL
        const urlParams = new URLSearchParams(window.location.search);
        const successMsg = urlParams.get('success');
        const errorMsg = urlParams.get('error');

        if (successMsg) {
            showNotification(decodeURIComponent(successMsg), 'success');
        }
        if (errorMsg) {
            showNotification(decodeURIComponent(errorMsg), 'error');
        }

        function showNotification(message, type = 'info') {
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <div class="notification-content">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;

            notification.style.cssText = `
                position: fixed;
                top: 80px;
                right: 24px;
                background: ${type === 'success' ? '#10b981' : '#ef4444'};
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                z-index: 10000;
                animation: slideIn 0.3s ease;
                font-size: 14px;
                font-weight: 500;
            `;

            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(400px); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                .notification-content {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
            `;
            document.head.appendChild(style);

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>