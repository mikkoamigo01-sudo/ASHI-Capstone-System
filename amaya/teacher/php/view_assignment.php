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

// Check if assignment ID is provided
if (!isset($_GET['id'])) {
    header("Location: ../teacher_dashb.php");
    exit();
}

$assignment_id = $_GET['id'];
$teacher_id = $_SESSION['teacher_id'];

// Get assignment details
$stmt = $pdo->prepare("
    SELECT a.*, s.subject_name, s.section, s.theme_color, s.id as subject_id
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.id = ? AND a.teacher_id = ?
");
$stmt->execute([$assignment_id, $teacher_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    die("Assignment not found or you don't have permission to view it.");
}

// Get assignment attachments
$stmt = $pdo->prepare("SELECT * FROM assignment_attachments WHERE assignment_id = ?");
$stmt->execute([$assignment_id]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get submission statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT ss.student_id) as total_students,
        COUNT(DISTINCT s.id) as submitted_count,
        COUNT(DISTINCT CASE WHEN s.status = 'graded' THEN s.id END) as graded_count
    FROM student_subjects ss
    LEFT JOIN submissions s ON ss.student_id = s.student_id AND s.assignment_id = ?
    WHERE ss.subject_id = ? AND ss.status = 'active'
");
$stmt->execute([$assignment_id, $assignment['subject_id']]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all submissions with student details
$stmt = $pdo->prepare("
    SELECT 
        st.id as student_id,
        st.first_name,
        st.last_name,
        st.profile_picture,
        s.id as submission_id,
        s.submission_text,
        s.submitted_at,
        s.status,
        s.score,
        s.teacher_feedback,
        s.graded_at
    FROM student_subjects ss
    JOIN students st ON ss.student_id = st.id
    LEFT JOIN submissions s ON st.id = s.student_id AND s.assignment_id = ?
    WHERE ss.subject_id = ? AND ss.status = 'active'
    ORDER BY s.submitted_at DESC, st.last_name ASC
");
$stmt->execute([$assignment_id, $assignment['subject_id']]);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Success message
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;
unset($_SESSION['success_message']);

// Helper function to get profile picture path
function getProfilePicturePath($profile_picture) {
    if (!$profile_picture) {
        return '../../student/uploads/profiles/default-avatar.png';
    }
    
    // Check if it's already a full path or just filename
    if (strpos($profile_picture, 'uploads/profiles/') === 0) {
        return '../../student/' . $profile_picture;
    } elseif (strpos($profile_picture, '/') === false) {
        return '../../student/uploads/profiles/' . $profile_picture;
    } else {
        return '../../student/' . $profile_picture;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($assignment['title']); ?> - Assignment</title>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f5f5f5;
            color: #202124;
            line-height: 1.6;
        }

        /* Header */
        .header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 2px rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
            width: 40px;
            height: 40px;
        }

        .back-btn:hover {
            background-color: #f1f3f4;
        }

        .back-btn i {
            font-size: 20px;
            color: #5f6368;
        }

        .header-title h1 {
            font-family: 'Google Sans', sans-serif;
            font-size: 22px;
            font-weight: 400;
            color: #202124;
        }

        .header-subtitle {
            font-size: 14px;
            color: #5f6368;
            margin-top: 2px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        /* Buttons */
        .btn {
            padding: 10px 24px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
            font-family: 'Google Sans', sans-serif;
        }

        .btn-primary {
            background-color: #1967d2;
            color: white;
        }

        .btn-primary:hover {
            background-color: #1557b0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        .btn-outlined {
            background: white;
            color: #1967d2;
            border: 1px solid #dadce0;
        }

        .btn-outlined:hover {
            background-color: #f8f9fa;
            border-color: #5f6368;
        }

        .btn-danger {
            background-color: #d93025;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c5221f;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        /* Container */
        .container {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 24px;
        }

        /* Success Message */
        .success-message {
            background: #e6f4ea;
            color: #137333;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #34a853;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .success-message i {
            font-size: 20px;
        }

        /* Assignment Card */
        .assignment-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .assignment-header {
            padding: 28px;
            background: linear-gradient(135deg, <?php echo htmlspecialchars($assignment['theme_color']); ?> 0%, <?php echo htmlspecialchars($assignment['theme_color']); ?>dd 100%);
            color: white;
        }

        .assignment-title {
            font-family: 'Google Sans', sans-serif;
            font-size: 28px;
            font-weight: 500;
            color: white;
            margin-bottom: 12px;
        }

        .assignment-meta {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            color: rgba(255, 255, 255, 0.95);
            font-size: 14px;
            margin-top: 16px;
        }

        .assignment-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            padding: 6px 14px;
            border-radius: 16px;
            backdrop-filter: blur(10px);
        }

        .assignment-meta-item i {
            font-size: 16px;
        }

        .assignment-body {
            padding: 28px;
        }

        .section-title {
            font-family: 'Google Sans', sans-serif;
            font-size: 18px;
            font-weight: 500;
            color: #202124;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #5f6368;
            font-size: 20px;
        }

        .assignment-content {
            color: #5f6368;
            line-height: 1.8;
            margin-bottom: 24px;
            white-space: pre-wrap;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        /* Attachments */
        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: white;
            border: 1px solid #dadce0;
            border-radius: 8px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .attachment-item:hover {
            background: #f8f9fa;
            border-color: #1967d2;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .attachment-icon {
            font-size: 32px;
            flex-shrink: 0;
        }

        .attachment-icon.pdf { color: #d93025; }
        .attachment-icon.doc { color: #1967d2; }
        .attachment-icon.image { color: #137333; }
        .attachment-icon.zip { color: #f9ab00; }
        .attachment-icon.file { color: #5f6368; }

        .attachment-info {
            flex: 1;
            min-width: 0;
        }

        .attachment-name {
            font-size: 14px;
            color: #202124;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 4px;
        }

        .attachment-size {
            font-size: 12px;
            color: #5f6368;
        }

        .attachment-download {
            color: #1967d2;
            text-decoration: none;
            font-size: 14px;
            padding: 8px 12px;
            border-radius: 4px;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .attachment-download:hover {
            background: rgba(26, 115, 232, 0.1);
        }

        /* Stats */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
            text-align: center;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-card:nth-child(1) .stat-icon {
            background: #e8f0fe;
            color: #1967d2;
        }

        .stat-card:nth-child(2) .stat-icon {
            background: #fef7e0;
            color: #f9ab00;
        }

        .stat-card:nth-child(3) .stat-icon {
            background: #e6f4ea;
            color: #34a853;
        }

        .stat-card:nth-child(4) .stat-icon {
            background: #fce8e6;
            color: #d93025;
        }

        .stat-value {
            font-family: 'Google Sans', sans-serif;
            font-size: 36px;
            font-weight: 700;
            color: #202124;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: #5f6368;
            font-weight: 500;
        }

        /* Submissions */
        .submissions-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }

        .submissions-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
            background: #fafafa;
        }

        .submissions-list {
            padding: 16px;
        }

        .submission-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 8px;
            transition: all 0.2s;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .submission-item:hover {
            background: #f8f9fa;
            border-color: #e0e0e0;
        }

        .student-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
            font-family: 'Google Sans', sans-serif;
            flex-shrink: 0;
            border: 3px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .submission-info {
            flex: 1;
        }

        .student-name {
            font-size: 15px;
            font-weight: 500;
            color: #202124;
            margin-bottom: 4px;
            font-family: 'Google Sans', sans-serif;
        }

        .submission-status {
            font-size: 13px;
            color: #5f6368;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .submission-status i {
            font-size: 12px;
        }

        .submission-badge {
            padding: 6px 14px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }

        .badge-submitted {
            background: #e8f0fe;
            color: #1967d2;
        }

        .badge-graded {
            background: #e6f4ea;
            color: #137333;
        }

        .badge-pending {
            background: #fef7e0;
            color: #f9ab00;
        }

        .badge-late {
            background: #fce8e6;
            color: #d93025;
        }

        .empty-state {
            text-align: center;
            padding: 64px 24px;
            color: #5f6368;
        }

        .empty-state i {
            font-size: 64px;
            color: #dadce0;
            margin-bottom: 16px;
        }

        .empty-state p {
            font-size: 16px;
            margin-top: 12px;
        }

        /* SVG Illustration */
        .illustration-container {
            width: 100%;
            max-width: 200px;
            margin: 0 auto 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .header-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .assignment-meta {
                flex-direction: column;
                gap: 8px;
            }

            .btn {
                padding: 8px 16px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="back-btn" onclick="goBack()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($assignment['title']); ?></h1>
                    <div class="header-subtitle"><?php echo htmlspecialchars($assignment['subject_name']); ?> • <?php echo htmlspecialchars($assignment['section']); ?></div>
                </div>
            </div>
            <!--
            <div class="header-actions">
                <a href="edit_assignment.php?id=<?php echo $assignment_id; ?>" class="btn btn-outlined">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button onclick="deleteAssignment()" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
            -->
        </div>
    </div>

    <!-- Main Container -->
    <div class="container">
        <?php if ($success_message): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>

        <!-- Assignment Details -->
        <div class="assignment-card">
            <div class="assignment-header">
                <h2 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h2>
                <div class="assignment-meta">
                    <div class="assignment-meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>
                            <?php 
                            if ($assignment['due_date']) {
                                echo 'Due: ' . date('F j, Y g:i A', strtotime($assignment['due_date']));
                            } else {
                                echo 'No due date';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="assignment-meta-item">
                        <i class="fas fa-award"></i>
                        <span><?php echo number_format($assignment['max_points'], 0); ?> points</span>
                    </div>
                    <div class="assignment-meta-item">
                        <i class="fas fa-tag"></i>
                        <span><?php echo ucfirst($assignment['assignment_type']); ?></span>
                    </div>
                    <div class="assignment-meta-item">
                        <i class="fas fa-clock"></i>
                        <span>Posted <?php echo date('M j, Y', strtotime($assignment['created_at'])); ?></span>
                    </div>
                </div>
            </div>

            <div class="assignment-body">
                <?php if ($assignment['description']): ?>
                <div style="margin-bottom: 28px;">
                    <h3 class="section-title">
                        <i class="fas fa-align-left"></i>
                        Description
                    </h3>
                    <div class="assignment-content"><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($assignment['instructions']): ?>
                <div style="margin-bottom: 28px;">
                    <h3 class="section-title">
                        <i class="fas fa-list-ul"></i>
                        Instructions
                    </h3>
                    <div class="assignment-content"><?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if (count($attachments) > 0): ?>
                <div>
                    <h3 class="section-title">
                        <i class="fas fa-paperclip"></i>
                        Attachments
                    </h3>
                    <div class="attachments-grid">
                        <?php foreach ($attachments as $attachment): 
                            $ext = strtolower(pathinfo($attachment['file_name'], PATHINFO_EXTENSION));
                            $icon_class = 'file';
                            if (in_array($ext, ['pdf'])) $icon_class = 'pdf';
                            elseif (in_array($ext, ['doc', 'docx'])) $icon_class = 'doc';
                            elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon_class = 'image';
                            elseif (in_array($ext, ['zip', 'rar'])) $icon_class = 'zip';
                        ?>
                        <a href="../<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                           download 
                           class="attachment-item"
                           style="text-decoration: none; color: inherit;">
                            <i class="fas fa-file attachment-icon <?php echo $icon_class; ?>"></i>
                            <div class="attachment-info">
                                <div class="attachment-name"><?php echo htmlspecialchars($attachment['file_name']); ?></div>
                                <div class="attachment-size">
                                    <?php 
                                    $size = $attachment['file_size'];
                                    if ($size < 1024) {
                                        echo $size . ' B';
                                    } elseif ($size < 1048576) {
                                        echo round($size / 1024, 2) . ' KB';
                                    } else {
                                        echo round($size / 1048576, 2) . ' MB';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="attachment-download">
                                <i class="fas fa-download"></i>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="stat-value"><?php echo $stats['submitted_count']; ?></div>
                <div class="stat-label">Submitted</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['graded_count']; ?></div>
                <div class="stat-label">Graded</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo ($stats['total_students'] - $stats['submitted_count']); ?></div>
                <div class="stat-label">Not Submitted</div>
            </div>
        </div>

        <!-- Submissions -->
        <div class="submissions-card">
            <div class="submissions-header">
                <h3 class="section-title">
                    <i class="fas fa-file-alt"></i>
                    Student Submissions
                </h3>
            </div>
            <div class="submissions-list">
                <?php if (count($submissions) > 0): ?>
                    <?php foreach ($submissions as $submission): 
                        $profile_picture = getProfilePicturePath($submission['profile_picture']);
                    ?>
                    <div class="submission-item" onclick="viewSubmission(<?php echo $submission['submission_id'] ?? 'null'; ?>)">
                        <div class="student-avatar">
                            <?php if ($submission['profile_picture']): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" 
                                     alt="Profile"
                                     onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo strtoupper(substr($submission['first_name'], 0, 1)); ?>';">
                            <?php else: ?>
                                <?php echo strtoupper(substr($submission['first_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="submission-info">
                            <div class="student-name">
                                <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?>
                            </div>
                            <div class="submission-status">
                                <?php 
                                if ($submission['submission_id']) {
                                    if ($submission['status'] === 'graded') {
                                        echo '<i class="fas fa-check-circle"></i> Submitted on ' . date('M j, g:i A', strtotime($submission['submitted_at'])) . ' • Graded: ' . number_format($submission['score'], 0) . '/' . number_format($assignment['max_points'], 0);
                                    } else {
                                        echo '<i class="fas fa-paper-plane"></i> Submitted on ' . date('M j, g:i A', strtotime($submission['submitted_at']));
                                    }
                                } else {
                                    echo '<i class="fas fa-clock"></i> Not submitted yet';
                                }
                                ?>
                            </div>
                        </div>
                        <?php if ($submission['submission_id']): ?>
                            <span class="submission-badge <?php echo $submission['status'] === 'graded' ? 'badge-graded' : 'badge-submitted'; ?>">
                                <?php echo ucfirst($submission['status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="submission-badge badge-pending">Pending</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <!-- SVG Illustration -->
                        <svg class="illustration-container" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="100" cy="100" r="80" fill="#f1f3f4"/>
                            <path d="M70 90 L130 90 L130 140 L70 140 Z" fill="white" stroke="#dadce0" stroke-width="2"/>
                            <circle cx="85" cy="105" r="8" fill="#dadce0"/>
                            <circle cx="115" cy="105" r="8" fill="#dadce0"/>
                            <path d="M85 120 Q100 130 115 120" stroke="#dadce0" stroke-width="2" fill="none"/>
                            <circle cx="100" cy="60" r="20" fill="white" stroke="#dadce0" stroke-width="2"/>
                            <circle cx="100" cy="55" r="8" fill="#dadce0"/>
                            <path d="M90 65 L110 65" stroke="#dadce0" stroke-width="2"/>
                        </svg>
                        <p>No students enrolled in this class yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function goBack() {
            window.location.href = 'teacher_view_subject.php?id=<?php echo $assignment['subject_id']; ?>&tab=classwork';
        }

        function viewSubmission(submissionId) {
            if (submissionId) {
                window.location.href = 'grade_submission.php?id=' + submissionId;
            }
        }

        function deleteAssignment() {
            const assignmentTitle = '<?php echo addslashes($assignment['title']); ?>';
            const submissionCount = <?php echo $stats['submitted_count']; ?>;
            
            let confirmMessage = `Are you sure you want to delete "${assignmentTitle}"?\n\n`;
            
            if (submissionCount > 0) {
                confirmMessage += `⚠️ This assignment has ${submissionCount} submission(s).\n`;
            }
            
            confirmMessage += 'This action cannot be undone and will delete:\n';
            confirmMessage += '• The assignment and all its details\n';
            confirmMessage += '• All student submissions\n';
            confirmMessage += '• All attachments and files\n';
            confirmMessage += '• All related notifications\n\n';
            confirmMessage += 'Type "DELETE" to confirm:';
            
            const userInput = prompt(confirmMessage);
            
            if (userInput === 'DELETE') {
                // Show loading indicator
                const deleteBtn = event.target.closest('.btn-danger');
                if (deleteBtn) {
                    deleteBtn.disabled = true;
                    deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
                }
                
                window.location.href = 'delete_assignment.php?id=<?php echo $assignment_id; ?>';
            } else if (userInput !== null) {
                alert('Deletion cancelled. You must type "DELETE" exactly to confirm.');
            }
        }
    </script>
</body>
</html>