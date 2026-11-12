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

// Get submission ID from URL
if (!isset($_GET['id'])) {
    header("Location: teacher_dashb.php");
    exit();
}

$submission_id = $_GET['id'];
$teacher_id = $_SESSION['teacher_id'];

// Get submission details with student, assignment, and subject info
$stmt = $pdo->prepare("
    SELECT 
        sub.*,
        s.first_name, s.last_name, s.email as student_email, s.profile_picture,
        a.title as assignment_title, a.description as assignment_description, 
        a.instructions, a.max_points, a.due_date, a.assignment_type,
        subj.subject_name, subj.id as subject_id, subj.theme_color,
        t.fullname as teacher_name
    FROM submissions sub
    JOIN students s ON sub.student_id = s.id
    JOIN assignments a ON sub.assignment_id = a.id
    JOIN subjects subj ON a.subject_id = subj.id
    JOIN teachers t ON a.teacher_id = t.id
    WHERE sub.id = ? AND a.teacher_id = ?
");
$stmt->execute([$submission_id, $teacher_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    die("Submission not found or you don't have permission to grade it.");
}

// Get submission attachments
$stmt = $pdo->prepare("SELECT * FROM submission_attachments WHERE submission_id = ? ORDER BY uploaded_at ASC");
$stmt->execute([$submission_id]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get assignment attachments (for reference)
$stmt = $pdo->prepare("SELECT * FROM assignment_attachments WHERE assignment_id = ? ORDER BY uploaded_at ASC");
$stmt->execute([$submission['assignment_id']]);
$assignment_attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission for grading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'grade') {
        $score = floatval($_POST['score']);
        $feedback = trim($_POST['feedback']);
        
        // Validate score
        if ($score < 0 || $score > 100) {
            $error = "Score must be between 0 and 100.";
        } else {
            // Update submission with grade
            $stmt = $pdo->prepare("
                UPDATE submissions 
                SET score = ?, 
                    teacher_feedback = ?, 
                    status = 'graded',
                    graded_at = NOW(),
                    graded_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$score, $feedback, $teacher_id, $submission_id]);
            
            // Create notification for student
            $notification_title = "Grade Posted: " . $submission['assignment_title'];
            $notification_message = "Your teacher has graded your submission. Score: " . $score . "%";
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
                VALUES ('student', ?, 'grade', ?, ?, ?)
            ");
            $stmt->execute([
                $submission['student_id'],
                $notification_title,
                $notification_message,
                $submission['assignment_id']
            ]);
            
            $success = "Grade submitted successfully!";
            
            // Refresh submission data
            $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
            $stmt->execute([$submission_id]);
            $submission = array_merge($submission, $stmt->fetch(PDO::FETCH_ASSOC));
        }
    } elseif ($_POST['action'] === 'return') {
        // Return without grading
        $feedback = trim($_POST['feedback']);
        
        $stmt = $pdo->prepare("
            UPDATE submissions 
            SET teacher_feedback = ?, 
                status = 'returned',
                graded_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$feedback, $teacher_id, $submission_id]);
        
        // Create notification for student
        $notification_title = "Submission Returned: " . $submission['assignment_title'];
        $notification_message = "Your teacher has returned your submission with feedback.";
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
            VALUES ('student', ?, 'submission', ?, ?, ?)
        ");
        $stmt->execute([
            $submission['student_id'],
            $notification_title,
            $notification_message,
            $submission['assignment_id']
        ]);
        
        $success = "Submission returned to student with feedback.";
        
        // Refresh submission data
        $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
        $stmt->execute([$submission_id]);
        $submission = array_merge($submission, $stmt->fetch(PDO::FETCH_ASSOC));
    }
}

// Calculate if submission was late
$is_late = false;
if ($submission['due_date']) {
    $due_date = strtotime($submission['due_date']);
    $submitted_date = strtotime($submission['submitted_at']);
    $is_late = $submitted_date > $due_date;
}

// Get all submissions for this assignment (for navigation)
$stmt = $pdo->prepare("
    SELECT sub.id, s.first_name, s.last_name, sub.status, sub.score
    FROM submissions sub
    JOIN students s ON sub.student_id = s.id
    WHERE sub.assignment_id = ?
    ORDER BY s.last_name ASC, s.first_name ASC
");
$stmt->execute([$submission['assignment_id']]);
$all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Find current position and navigation
$current_index = 0;
foreach ($all_submissions as $index => $sub) {
    if ($sub['id'] == $submission_id) {
        $current_index = $index;
        break;
    }
}
$prev_submission = $current_index > 0 ? $all_submissions[$current_index - 1] : null;
$next_submission = $current_index < count($all_submissions) - 1 ? $all_submissions[$current_index + 1] : null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title>Grade Submission - <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></title>
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
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            background: none;
            border: none;
            color: #5f6368;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .back-btn:hover {
            background-color: #f1f3f4;
        }

        .header-title {
            display: flex;
            flex-direction: column;
        }

        .header-title h1 {
            font-family: 'Google Sans', sans-serif;
            font-size: 20px;
            font-weight: 400;
            color: #202124;
        }

        .header-subtitle {
            font-size: 14px;
            color: #5f6368;
            margin-top: 2px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Navigation buttons */
        .nav-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            padding-right: 12px;
            border-right: 1px solid #e0e0e0;
        }

        .nav-btn {
            background: none;
            border: 1px solid #dadce0;
            color: #5f6368;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 4px;
            transition: all 0.2s;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-btn:hover:not(:disabled) {
            background-color: #f1f3f4;
            border-color: #5f6368;
        }

        .nav-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        /* Main Container */
        .container {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
        }

        /* Main Content Area */
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .card {
            background: white;
            border-radius: 8px;
            border: 1px solid #dadce0;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
        }

        .card-header h2 {
            font-family: 'Google Sans', sans-serif;
            font-size: 18px;
            font-weight: 400;
            color: #202124;
            margin-bottom: 8px;
        }

        .card-body {
            padding: 24px;
        }

        /* Student Info Card */
        .student-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .student-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }

        .student-details h3 {
            font-family: 'Google Sans', sans-serif;
            font-size: 18px;
            font-weight: 400;
            color: #202124;
            margin-bottom: 4px;
        }

        .student-email {
            color: #5f6368;
            font-size: 14px;
        }

        .submission-status {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e0e0e0;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-badge.submitted {
            background-color: #e8f0fe;
            color: #1967d2;
        }

        .status-badge.late {
            background-color: #fce8e6;
            color: #d93025;
        }

        .status-badge.graded {
            background-color: #e6f4ea;
            color: #137333;
        }

        /* Assignment Details */
        .assignment-details {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .detail-item {
            display: flex;
            gap: 12px;
        }

        .detail-icon {
            color: #5f6368;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .detail-content {
            flex: 1;
        }

        .detail-label {
            font-size: 12px;
            color: #5f6368;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: 14px;
            color: #202124;
        }

        /* Submission Content */
        .submission-content {
            white-space: pre-wrap;
            line-height: 1.8;
            color: #202124;
            font-size: 14px;
        }

        /* Attachments */
        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .attachment-item {
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: #202124;
        }

        .attachment-item:hover {
            border-color: #1967d2;
            background-color: #f8f9fa;
        }

        .attachment-icon {
            font-size: 32px;
            color: #5f6368;
        }

        .attachment-icon.pdf { color: #d93025; }
        .attachment-icon.doc { color: #1967d2; }
        .attachment-icon.image { color: #137333; }
        .attachment-icon.zip { color: #f9ab00; }

        .attachment-info {
            flex: 1;
            min-width: 0;
        }

        .attachment-name {
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .attachment-size {
            font-size: 12px;
            color: #5f6368;
            margin-top: 2px;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Grading Form */
        .grading-form {
            position: sticky;
            top: 88px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #202124;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: #1967d2;
            box-shadow: 0 0 0 3px rgba(25, 103, 210, 0.1);
        }

        .score-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .score-input {
            padding-right: 80px;
            font-size: 24px;
            font-weight: 500;
            text-align: center;
        }

        .score-max {
            position: absolute;
            right: 16px;
            font-size: 14px;
            color: #5f6368;
        }

        textarea.form-input {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            font-family: 'Google Sans', sans-serif;
        }

        .btn-primary {
            background-color: #1967d2;
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            background-color: #1557b0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        .btn-secondary {
            background-color: white;
            color: #5f6368;
            border: 1px solid #dadce0;
            width: 100%;
            margin-top: 8px;
        }

        .btn-secondary:hover {
            background-color: #f8f9fa;
            border-color: #5f6368;
        }

        /* Current Grade Display */
        .current-grade {
            background: #e8f0fe;
            padding: 16px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }

        .current-grade-label {
            font-size: 12px;
            color: #1967d2;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .current-grade-value {
            font-size: 32px;
            font-weight: 500;
            color: #1967d2;
        }

        .current-grade-feedback {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(25, 103, 210, 0.2);
            font-size: 14px;
            color: #202124;
            text-align: left;
        }

        /* Alert Messages */
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background-color: #e6f4ea;
            color: #137333;
            border: 1px solid #137333;
        }

        .alert-error {
            background-color: #fce8e6;
            color: #d93025;
            border: 1px solid #d93025;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 32px;
            color: #5f6368;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 968px) {
            .container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                order: -1;
            }

            .grading-form {
                position: static;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="back-btn" onclick="window.history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($submission['assignment_title']); ?></h1>
                    <div class="header-subtitle"><?php echo htmlspecialchars($submission['subject_name']); ?></div>
                </div>
            </div>
            <div class="header-right">
                <div class="nav-buttons">
                    <button class="nav-btn" <?php echo !$prev_submission ? 'disabled' : ''; ?> 
                            onclick="<?php echo $prev_submission ? "window.location.href='grade_submission.php?id={$prev_submission['id']}'" : ''; ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <button class="nav-btn" <?php echo !$next_submission ? 'disabled' : ''; ?>
                            onclick="<?php echo $next_submission ? "window.location.href='grade_submission.php?id={$next_submission['id']}'" : ''; ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div style="color: #5f6368; font-size: 14px;">
                    <?php echo ($current_index + 1) . ' of ' . count($all_submissions); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="container">
        <!-- Main Content -->
        <div class="main-content">
            <!-- Student Info Card -->
            <div class="card">
                <div class="card-body">
                    <div class="student-info">
                        <img src="../upload/profiles/<?php echo htmlspecialchars($submission['profile_picture'] ?: 'default-avatar.png'); ?>" 
                             class="student-avatar" 
                             alt="<?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?>"
                             onerror="this.src='../upload/profiles/default-avatar.png'">
                        <div class="student-details">
                            <h3><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></h3>
                            <div class="student-email"><?php echo htmlspecialchars($submission['student_email']); ?></div>
                        </div>
                    </div>
                    <div class="submission-status">
                        <span class="status-badge <?php echo $submission['status']; ?>">
                            <i class="fas fa-<?php echo $submission['status'] === 'graded' ? 'check-circle' : 'clock'; ?>"></i>
                            <?php echo ucfirst($submission['status']); ?>
                        </span>
                        <?php if ($is_late): ?>
                        <span class="status-badge late">
                            <i class="fas fa-exclamation-triangle"></i>
                            Turned in late
                        </span>
                        <?php endif; ?>
                        <span class="status-badge submitted">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('M d, Y g:i A', strtotime($submission['submitted_at'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Assignment Details -->
            <div class="card">
                <div class="card-header">
                    <h2>Assignment Details</h2>
                </div>
                <div class="card-body">
                    <div class="assignment-details">
                        <?php if ($submission['instructions']): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-info-circle"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Instructions</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($submission['instructions'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-star"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Points</div>
                                <div class="detail-value"><?php echo $submission['max_points']; ?> points</div>
                            </div>
                        </div>

                        <?php if ($submission['due_date']): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-clock"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Due Date</div>
                                <div class="detail-value"><?php echo date('l, F j, Y \a\t g:i A', strtotime($submission['due_date'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($assignment_attachments)): ?>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                        <div class="detail-label" style="margin-bottom: 12px;">Assignment Materials</div>
                        <div class="attachments-grid">
                            <?php foreach ($assignment_attachments as $attachment): 
                                $ext = strtolower(pathinfo($attachment['file_name'], PATHINFO_EXTENSION));
                                $icon_class = 'file';
                                if (in_array($ext, ['pdf'])) $icon_class = 'pdf';
                                elseif (in_array($ext, ['doc', 'docx'])) $icon_class = 'doc';
                                elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon_class = 'image';
                                elseif (in_array($ext, ['zip', 'rar'])) $icon_class = 'zip';
                            ?>
                            <a href="../<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                               class="attachment-item" 
                               target="_blank">
                                <i class="fas fa-file attachment-icon <?php echo $icon_class; ?>"></i>
                                <div class="attachment-info">
                                    <div class="attachment-name"><?php echo htmlspecialchars($attachment['file_name']); ?></div>
                                    <div class="attachment-size"><?php echo round($attachment['file_size'] / 1024, 2); ?> KB</div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Student Submission -->
            <div class="card">
                <div class="card-header">
                    <h2>Student Response</h2>
                </div>
                <div class="card-body">
                    <?php if ($submission['submission_text']): ?>
                    <div class="submission-content">
                        <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <p>No text response provided</p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($attachments)): ?>
                    <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e0e0e0;">
                        <div class="detail-label" style="margin-bottom: 12px;">Attached Files</div>
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
                               class="attachment-item" 
                               target="_blank">
                                <i class="fas fa-file attachment-icon <?php echo $icon_class; ?>"></i>
                                <div class="attachment-info">
                                    <div class="attachment-name"><?php echo htmlspecialchars($attachment['file_name']); ?></div>
                                    <div class="attachment-size"><?php echo round($attachment['file_size'] / 1024, 2); ?> KB</div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar - Grading Form -->
        <div class="sidebar">
            <div class="card grading-form">
                <div class="card-header">
                    <h2>Grade Submission</h2>
                </div>
                <div class="card-body">
                    <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($submission['status'] === 'graded' && $submission['score'] !== null): ?>
                    <div class="current-grade">
                        <div class="current-grade-label">Current Grade</div>
                        <div class="current-grade-value"><?php echo number_format($submission['score'], 0); ?>%</div>
                        <?php if ($submission['teacher_feedback']): ?>
                        <div class="current-grade-feedback">
                            <strong>Feedback:</strong><br>
                            <?php echo nl2br(htmlspecialchars($submission['teacher_feedback'])); ?>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 12px; font-size: 12px; color: #5f6368;">
                            Graded on <?php echo date('M d, Y g:i A', strtotime($submission['graded_at'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="form-group">
                            <label class="form-label" for="score">Score (0-100%)</label>
                            <div class="score-input-group">
                                <input type="number" 
                                       id="score" 
                                       name="score" 
                                       class="form-input score-input" 
                                       min="0" 
                                       max="100" 
                                       step="0.01"
                                       value="<?php echo $submission['score'] ?? ''; ?>"
                                       required>
                                <span class="score-max">/ 100%</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="feedback">Private Feedback for Student</label>
                            <textarea id="feedback" 
                                      name="feedback" 
                                      class="form-input" 
                                      placeholder="Provide feedback on the student's work..."><?php echo htmlspecialchars($submission['teacher_feedback'] ?? ''); ?></textarea>
                        </div>

                        <button type="submit" name="action" value="grade" class="btn btn-primary">
                            <i class="fas fa-check"></i>
                            <?php echo $submission['status'] === 'graded' ? 'Update Grade' : 'Submit Grade'; ?>
                        </button>

                        <button type="submit" name="action" value="return" class="btn btn-secondary">
                            <i class="fas fa-undo"></i>
                            Return without Grade
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-save draft functionality (optional enhancement)
        let saveTimeout;
        const feedbackTextarea = document.getElementById('feedback');
        
        if (feedbackTextarea) {
            feedbackTextarea.addEventListener('input', function() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(() => {
                    // You can implement auto-save here if needed
                    console.log('Auto-saving feedback...');
                }, 2000);
            });
        }

        // Keyboard shortcuts for navigation
        document.addEventListener('keydown', function(e) {
            // Alt + Left Arrow: Previous submission
            if (e.altKey && e.key === 'ArrowLeft') {
                <?php if ($prev_submission): ?>
                window.location.href = 'grade_submission.php?id=<?php echo $prev_submission['id']; ?>';
                <?php endif; ?>
            }
            // Alt + Right Arrow: Next submission
            if (e.altKey && e.key === 'ArrowRight') {
                <?php if ($next_submission): ?>
                window.location.href = 'grade_submission.php?id=<?php echo $next_submission['id']; ?>';
                <?php endif; ?>
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const score = parseFloat(document.getElementById('score').value);
            if (isNaN(score) || score < 0 || score > 100) {
                e.preventDefault();
                alert('Please enter a valid score between 0 and 100.');
                return false;
            }
        });
    </script>
</body>
</html>