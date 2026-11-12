<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Get assignment ID from URL
$assignment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$assignment_id) {
    header("Location: student_dashb.php");
    exit();
}

// Get assignment details with subject info
$stmt = $conn->prepare("
    SELECT a.*, 
           s.subject_name, s.class_code, s.theme_color, s.id AS subject_id,
           t.fullname AS teacher_name
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    JOIN teachers t ON a.teacher_id = t.id
    WHERE a.id = ?
");

if (!$stmt) {
    header("Location: student_dashb.php");
    exit();
}

$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assignment) {
    header("Location: student_dashb.php");
    exit();
}

// Verify student is enrolled in this subject
$stmt = $conn->prepare("
    SELECT id FROM student_subjects 
    WHERE student_id = ? AND subject_id = ? AND status = 'active'
");

if (!$stmt) {
    header("Location: student_dashb.php");
    exit();
}

$stmt->bind_param("ii", $student_id, $assignment['subject_id']);
$stmt->execute();
$enrolled = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$enrolled) {
    header("Location: student_dashb.php");
    exit();
}

// Get assignment attachments
$stmt = $conn->prepare("
    SELECT * FROM assignment_attachments 
    WHERE assignment_id = ?
    ORDER BY uploaded_at ASC
");

if (!$stmt) {
    $attachments = [];
} else {
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $attachments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get student's submission if exists
$stmt = $conn->prepare("
    SELECT * FROM submissions 
    WHERE assignment_id = ? AND student_id = ?
");

if (!$stmt) {
    $submission = null;
} else {
    $stmt->bind_param("ii", $assignment_id, $student_id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Get submission attachments if submission exists
$submission_files = [];
if ($submission) {
    $stmt = $conn->prepare("
        SELECT * FROM submission_attachments 
        WHERE submission_id = ?
        ORDER BY uploaded_at ASC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $submission['id']);
        $stmt->execute();
        $submission_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Calculate assignment status
$due_date    = $assignment['due_date'] ? new DateTime($assignment['due_date']) : null;
$now         = new DateTime();
$is_overdue  = $due_date && $now > $due_date && !$submission;
$is_submitted = $submission !== null;
$is_graded   = $submission && $submission['score'] !== null;
$is_late     = $submission && $submission['status'] === 'late';

// Handle unsubmit
if (isset($_GET['unsubmit']) && $submission && !$is_graded) {
    $stmt = $conn->prepare("DELETE FROM submissions WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $submission['id']);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: view_assignment.php?id=$assignment_id");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    $submission_text = $_POST['submission_text'] ?? '';
    $status          = ($due_date && $now > $due_date) ? 'late' : 'submitted';

    $conn->begin_transaction();

    try {
        if ($submission) {
            // Update existing submission
            $stmt = $conn->prepare("
                UPDATE submissions 
                SET submission_text = ?, status = ?, submitted_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("ssi", $submission_text, $status, $submission['id']);
            $stmt->execute();
            $submission_id = $submission['id'];
            $stmt->close();
        } else {
            // Create new submission
            $stmt = $conn->prepare("
                INSERT INTO submissions (assignment_id, student_id, submission_text, status)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("iiss", $assignment_id, $student_id, $submission_text, $status);
            $stmt->execute();
            $submission_id = $conn->insert_id;
            $stmt->close();
        }

        // Handle file uploads
        if (isset($_FILES['submission_files']) && !empty($_FILES['submission_files']['name'][0])) {
            $upload_dir = 'upload/submissions/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            foreach ($_FILES['submission_files']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['submission_files']['error'][$key] === 0) {
                    $file_name = $_FILES['submission_files']['name'][$key];
                    $file_size = $_FILES['submission_files']['size'][$key];
                    $file_tmp  = $_FILES['submission_files']['tmp_name'][$key];
                    $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $new_file_name = uniqid() . '_' . $file_name;
                    $file_path = $upload_dir . $new_file_name;

                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $stored_path = 'student/' . $file_path;
                        $stmt = $conn->prepare("
                            INSERT INTO submission_attachments (submission_id, file_name, file_path, file_type, file_size)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("isssi", $submission_id, $file_name, $stored_path, $file_ext, $file_size);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }

        $conn->commit();
        header("Location: view_assignment.php?id=$assignment_id&submitted=1");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Failed to submit assignment. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($assignment['title']); ?> - Assignment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Success Modal Styles */
        .success-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
            animation: slideDown 0.5s ease-out, fadeOut 0.5s ease-in 2.5s forwards;
        }

        .success-modal-content {
            background: white;
            padding: 30px 50px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            text-align: center;
            min-width: 300px;
        }

        .success-icon {
            font-size: 60px;
            color: #34a853;
            margin-bottom: 15px;
            animation: scaleIn 0.5s ease-out;
        }

        .success-modal-content h3 {
            margin: 0 0 10px 0;
            font-size: 24px;
            color: #202124;
        }

        .success-modal-content p {
            margin: 0;
            font-size: 16px;
            color: #5f6368;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translate(-50%, -60%);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%);
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }
            to {
                opacity: 0;
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        /* Modal overlay (optional dark background) */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.3);
            z-index: 9998;
            animation: fadeOut 0.5s ease-in 2.5s forwards;
        }
    </style>
</head>
<body>
    <!-- Success Modal -->
    <?php if (isset($_GET['submitted'])): ?>
    <div class="modal-overlay" id="modalOverlay"></div>
    <div class="success-modal" id="successModal">
        <div class="success-modal-content">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Success!</h3>
            <p>Your work has been submitted successfully!</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <button class="back-btn" onclick="window.location.href='view_subject.php?id=<?php echo $assignment['subject_id']; ?>'">
                <i class="fas fa-arrow-left fa-lg"></i>
            </button>
            <div class="header-info">
                <div class="subject-name"><?php echo htmlspecialchars($assignment['subject_name']); ?></div>
                <div class="assignment-title-header"><?php echo htmlspecialchars($assignment['title']); ?></div>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Left Column - Assignment Details -->
        <div>
            <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle fa-lg"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
            <?php endif; ?>

            <div class="assignment-card">
                <div class="assignment-header">
                    <div class="assignment-type-badge">
                        <?php 
                        $icons = [
                            'homework' => 'fa-book',
                            'quiz' => 'fa-question-circle',
                            'project' => 'fa-project-diagram',
                            'exam' => 'fa-file-alt',
                            'classwork' => 'fa-clipboard'
                        ];
                        $icon = $icons[$assignment['assignment_type']] ?? 'fa-file';
                        ?>
                        <i class="fas <?php echo $icon; ?>"></i>
                        <?php echo ucfirst(htmlspecialchars($assignment['assignment_type'])); ?>
                    </div>
                    <h1 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h1>
                    <div class="assignment-meta">
                        <div class="meta-item">
                            <i class="fas fa-user-tie"></i>
                            <?php echo htmlspecialchars($assignment['teacher_name']); ?>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-star"></i>
                            <?php echo htmlspecialchars($assignment['max_points']); ?> points
                        </div>
                        <?php if ($assignment['due_date']): 
                            $due = new DateTime($assignment['due_date']);
                        ?>
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            Due <?php echo $due->format('M j, Y \a\t g:i A'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="assignment-body">
                    <?php if ($assignment['description']): ?>
                    <div class="section-title">Description</div>
                    <div class="assignment-description"><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></div>
                    <?php endif; ?>

                    <?php if ($assignment['instructions']): ?>
                    <div class="section-title">Instructions</div>
                    <div class="instructions-box">
                        <div class="instructions-text"><?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($attachments) > 0): ?>
                    <div class="section-title">Attachments</div>
                    <div class="attachments-list">
                        <?php foreach ($attachments as $file): 
                            // Handle both old and new path formats
                            $file_path = $file['file_path'];
                            
                            // If path already starts with 'teacher/', use it as is from student folder
                            if (strpos($file_path, 'teacher/') === 0) {
                                $file_url = '../' . $file_path;
                            }
                            // If path is old format (upload/assignments/), add teacher prefix
                            else if (strpos($file_path, 'upload/') === 0) {
                                $file_url = '../teacher/' . $file_path;
                            }
                            // Otherwise use as is
                            else {
                                $file_url = $file_path;
                            }
                        ?>
                        <div class="attachment-item" onclick="window.location.href='<?php echo htmlspecialchars($file_url); ?>'">
                            <div class="attachment-icon">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="attachment-info">
                                <div class="attachment-name"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                <div class="attachment-size">
                                    <?php echo $file['file_size'] ? number_format($file['file_size'] / 1024, 2) . ' KB' : 'Unknown size'; ?>
                                </div>
                            </div>
                            <i class="fas fa-download" style="color: #5f6368;"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Your Work Section -->
            <div class="work-section">
                <div class="work-header">
                    <h2 class="work-title">Your work</h2>
                    <?php if ($is_submitted && !$is_graded): ?>
                    <button class="btn btn-secondary" onclick="if(confirm('Are you sure you want to unsubmit? Your work will be marked as not turned in.')) window.location.href='?id=<?php echo $assignment_id; ?>&unsubmit=1'">
                        <i class="fas fa-undo"></i>
                        Unsubmit
                    </button>
                    <?php endif; ?>
                </div>

                <?php if ($is_graded): ?>
                    <!-- Show graded work -->
                    <?php if ($submission['submission_text']): ?>
                    <div class="form-group">
                        <div class="form-label">Your Answer</div>
                        <div class="submitted-content">
                            <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($submission_files) > 0): ?>
                    <div class="form-group">
                        <div class="form-label">Submitted Files</div>
                        <div class="uploaded-files">
                            <?php foreach ($submission_files as $file): 
                                // Submission files path
                                $file_url = '../' . $file['file_path'];
                            ?>
                            <div class="attachment-item" onclick="window.location.href='<?php echo htmlspecialchars($file_url); ?>'">
                                <div class="attachment-icon">
                                    <i class="fas fa-file"></i>
                                </div>
                                <div class="attachment-info">
                                    <div class="attachment-name"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                </div>
                                <i class="fas fa-download" style="color: #5f6368;"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($submission['teacher_feedback']): ?>
                    <div class="feedback-box">
                        <div class="feedback-title">Teacher Feedback</div>
                        <div class="feedback-text"><?php echo nl2br(htmlspecialchars($submission['teacher_feedback'])); ?></div>
                    </div>
                    <?php endif; ?>

                <?php elseif ($is_submitted): ?>
                    <!-- Show submitted but not graded work -->
                    <?php if ($submission['submission_text']): ?>
                    <div class="form-group">
                        <div class="form-label">Your Answer</div>
                        <div class="submitted-content">
                            <?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($submission_files) > 0): ?>
                    <div class="form-group">
                        <div class="form-label">Submitted Files</div>
                        <div class="uploaded-files">
                            <?php foreach ($submission_files as $file): 
                                // Submission files path
                                $file_url = '../' . $file['file_path'];
                            ?>
                            <div class="attachment-item" onclick="window.location.href='<?php echo htmlspecialchars($file_url); ?>'">
                                <div class="attachment-icon">
                                    <i class="fas fa-file"></i>
                                </div>
                                <div class="attachment-info">
                                    <div class="attachment-name"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                </div>
                                <i class="fas fa-download" style="color: #5f6368;"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- Submission form -->
                    <form method="POST" enctype="multipart/form-data" id="submissionForm">
                        <div class="form-group">
                            <label class="form-label">Add or create</label>
                            <textarea 
                                name="submission_text" 
                                class="form-textarea" 
                                placeholder="Type your answer here..."
                            ><?php echo $submission ? htmlspecialchars($submission['submission_text']) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Attach files</label>
                            <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                                <div class="upload-icon">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <div class="upload-text">
                                    <strong>Click to upload</strong> or drag and drop
                                </div>
                                <input 
                                    type="file" 
                                    id="fileInput" 
                                    name="submission_files[]" 
                                    class="file-input" 
                                    multiple
                                    onchange="displaySelectedFiles(this)">
                            </div>
                            <div class="uploaded-files" id="filesList"></div>
                        </div>

                        <div class="work-actions">
                            <?php if ($is_overdue && !$assignment['allow_late_submission']): ?>
                                <p style="color: #d93025; font-size: 14px;">This assignment is past due and does not accept late submissions.</p>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary" onclick="document.getElementById('submissionForm').reset(); document.getElementById('filesList').innerHTML = '';">
                                    <i class="fas fa-times"></i>
                                    Cancel
                                </button>
                                <button type="submit" name="submit_assignment" class="btn btn-primary">
                                    <i class="fas fa-check"></i>
                                    <?php echo $is_overdue ? 'Turn in late' : 'Turn in'; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Google Sans', 'Roboto', Arial, sans-serif;
            background: #f1f3f4;
            color: #202124;
            line-height: 1.5;
        }

        /* Header */
        .header {
            background: #fff;
            border-bottom: 1px solid #dadce0;
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
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
            transition: background 0.2s;
        }

        .back-btn:hover {
            background: #f1f3f4;
        }

        .header-info {
            flex: 1;
        }

        .subject-name {
            font-size: 14px;
            color: #5f6368;
            margin-bottom: 2px;
        }

        .assignment-title-header {
            font-size: 18px;
            font-weight: 400;
            color: #202124;
        }

        /* Main Layout */
        .main-container {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 24px;
        }

        /* Assignment Details Card */
        .assignment-card {
            background: #fff;
            border: 1px solid #dadce0;
            border-radius: 8px;
            overflow: hidden;
        }

        .assignment-header {
            padding: 24px;
            border-bottom: 1px solid #dadce0;
        }

        .assignment-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e8f0fe;
            color: #1967d2;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 12px;
        }

        .assignment-title {
            font-size: 28px;
            font-weight: 400;
            color: #202124;
            margin-bottom: 12px;
        }

        .assignment-meta {
            display: flex;
            gap: 24px;
            font-size: 14px;
            color: #5f6368;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .assignment-body {
            padding: 24px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 500;
            color: #5f6368;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .assignment-description {
            font-size: 14px;
            color: #202124;
            line-height: 1.6;
            margin-bottom: 24px;
            white-space: pre-wrap;
        }

        .instructions-box {
            background: #f8f9fa;
            border-left: 4px solid #1967d2;
            padding: 16px;
            border-radius: 4px;
            margin-bottom: 24px;
        }

        .instructions-text {
            font-size: 14px;
            color: #202124;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        /* Attachments */
        .attachments-list {
            margin-top: 16px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border: 1px solid #dadce0;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .attachment-item:hover {
            background: #f8f9fa;
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: #fef7e0;
            color: #f9ab00;
        }

        .attachment-info {
            flex: 1;
        }

        .attachment-name {
            font-size: 14px;
            font-weight: 500;
            color: #202124;
        }

        .attachment-size {
            font-size: 12px;
            color: #5f6368;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .sidebar-card {
            background: #fff;
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 20px;
        }

        .sidebar-title {
            font-size: 16px;
            font-weight: 500;
            color: #202124;
            margin-bottom: 16px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 16px;
        }

        .status-submitted {
            background: #e6f4ea;
            color: #1e8e3e;
        }

        .status-graded {
            background: #e8f0fe;
            color: #1967d2;
        }

        .status-missing {
            background: #fce8e6;
            color: #d93025;
        }

        .status-assigned {
            background: #f1f3f4;
            color: #5f6368;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f1f3f4;
            font-size: 14px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #5f6368;
        }

        .info-value {
            color: #202124;
            font-weight: 500;
        }

        .score-display {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 16px;
        }

        .score-value {
            font-size: 36px;
            font-weight: 400;
            color: #1967d2;
        }

        .score-label {
            font-size: 14px;
            color: #5f6368;
            margin-top: 4px;
        }

        /* Your Work Section */
        .work-section {
            background: #fff;
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 24px;
            margin-top: 24px;
        }

        .work-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .work-title {
            font-size: 18px;
            font-weight: 500;
            color: #202124;
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

        .form-textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            transition: border-color 0.2s;
        }

        .form-textarea:focus {
            outline: none;
            border-color: #1967d2;
        }

        .file-upload-area {
            border: 2px dashed #dadce0;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .file-upload-area:hover {
            border-color: #1967d2;
            background: #f8f9fa;
        }

        .file-upload-area.drag-over {
            border-color: #1967d2;
            background: #e8f0fe;
        }

        .upload-icon {
            font-size: 48px;
            color: #5f6368;
            margin-bottom: 12px;
        }

        .upload-text {
            font-size: 14px;
            color: #5f6368;
        }

        .file-input {
            display: none;
        }

        .uploaded-files {
            margin-top: 16px;
        }

        .uploaded-file {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .file-remove-btn {
            background: none;
            border: none;
            color: #d93025;
            cursor: pointer;
            padding: 4px;
        }

        .work-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
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
        }

        .btn-primary {
            background: #1967d2;
            color: white;
        }

        .btn-primary:hover {
            background: #1557b0;
        }

        .btn-primary:disabled {
            background: #dadce0;
            color: #5f6368;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #fff;
            color: #5f6368;
            border: 1px solid #dadce0;
        }

        .btn-secondary:hover {
            background: #f8f9fa;
        }

        .btn-danger {
            background: #d93025;
            color: white;
        }

        .btn-danger:hover {
            background: #b31412;
        }

        /* Submitted Work View */
        .submitted-content {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .feedback-box {
            background: #fff9e6;
            border-left: 4px solid #f9ab00;
            padding: 16px;
            border-radius: 4px;
            margin-top: 16px;
        }

        .feedback-title {
            font-size: 14px;
            font-weight: 500;
            color: #202124;
            margin-bottom: 8px;
        }

        .feedback-text {
            font-size: 14px;
            color: #202124;
            line-height: 1.6;
        }

        /* Alert Messages */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #e6f4ea;
            color: #1e8e3e;
        }

        .alert-error {
            background: #fce8e6;
            color: #d93025;
        }

        /* Responsive */
        @media (max-width: 968px) {
            .main-container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 0 16px;
            }

            .assignment-title {
                font-size: 22px;
            }

            .assignment-meta {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
        <!-- Right Sidebar -->
        <div class="sidebar">
            <!-- Status Card -->
            <div class="sidebar-card">
                <h3 class="sidebar-title">Status</h3>
                <?php if ($is_graded): ?>
                    <div class="status-badge status-graded">
                        <i class="fas fa-check-circle"></i>
                        Graded
                    </div>
                <?php elseif ($is_submitted): ?>
                    <div class="status-badge status-submitted">
                        <i class="fas fa-check"></i>
                        <?php echo $is_late ? 'Turned in late' : 'Turned in'; ?>
                    </div>
                <?php elseif ($is_overdue): ?>
                    <div class="status-badge status-missing">
                        <i class="fas fa-exclamation-circle"></i>
                        Missing
                    </div>
                <?php else: ?>
                    <div class="status-badge status-assigned">
                        <i class="fas fa-clipboard"></i>
                        Assigned
                    </div>
                <?php endif; ?>

                <?php if ($is_submitted): ?>
                <div class="info-row">
                    <span class="info-label">Submitted</span>
                    <span class="info-value">
                        <?php 
                        $submitted = new DateTime($submission['submitted_at']);
                        echo $submitted->format('M j, g:i A');
                        ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if ($is_graded): ?>
                <div class="score-display">
                    <div class="score-value">
                        <?php echo number_format($submission['score'], 2); ?>/<?php echo number_format($assignment['max_points'], 2); ?>
                    </div>
                    <div class="score-label">Your grade</div>
                </div>
                <?php endif; ?>

                <?php if ($assignment['due_date']): ?>
                <div class="info-row">
                    <span class="info-label">Due</span>
                    <span class="info-value" style="<?php echo $is_overdue && !$is_submitted ? 'color: #d93025;' : ''; ?>">
                        <?php echo $due_date->format('M j, g:i A'); ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="info-row">
                    <span class="info-label">Points</span>
                    <span class="info-value"><?php echo number_format($assignment['max_points'], 2); ?></span>
                </div>
            </div>

            <!-- Class Info Card -->
            <div class="sidebar-card">
                <h3 class="sidebar-title">Class</h3>
                <div class="info-row">
                    <span class="info-label">Subject</span>
                    <span class="info-value"><?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Teacher</span>
                    <span class="info-value"><?php echo htmlspecialchars($assignment['teacher_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Class code</span>
                    <span class="info-value"><?php echo htmlspecialchars($assignment['class_code']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-hide success modal after 3 seconds
        <?php if (isset($_GET['submitted'])): ?>
        setTimeout(function() {
            const modal = document.getElementById('successModal');
            const overlay = document.getElementById('modalOverlay');
            if (modal) modal.remove();
            if (overlay) overlay.remove();
        }, 3000);

        // Replace the current history entry so the back button skips
        // the stale pre-submit form page and goes straight to view_subject
        history.replaceState(null, '', window.location.href);
        <?php endif; ?>

        // Display selected files
        function displaySelectedFiles(input) {
            const filesList = document.getElementById('filesList');
            filesList.innerHTML = '';
            
            if (input.files.length > 0) {
                Array.from(input.files).forEach((file, index) => {
                    const fileItem = document.createElement('div');
                    fileItem.className = 'uploaded-file';
                    fileItem.innerHTML = `
                        <div class="attachment-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <div class="attachment-info">
                            <div class="attachment-name">${escapeHtml(file.name)}</div>
                            <div class="attachment-size">${(file.size / 1024).toFixed(2)} KB</div>
                        </div>
                        <button type="button" class="file-remove-btn" onclick="removeFile(${index})">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    filesList.appendChild(fileItem);
                });
            }
        }

        // Remove file from selection
        function removeFile(index) {
            const input = document.getElementById('fileInput');
            const dt = new DataTransfer();
            const files = input.files;
            
            for (let i = 0; i < files.length; i++) {
                if (i !== index) {
                    dt.items.add(files[i]);
                }
            }
            
            input.files = dt.files;
            displaySelectedFiles(input);
        }

        // Drag and drop functionality
        const uploadArea = document.querySelector('.file-upload-area');
        
        if (uploadArea) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                uploadArea.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                uploadArea.addEventListener(eventName, () => {
                    uploadArea.classList.add('drag-over');
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                uploadArea.addEventListener(eventName, () => {
                    uploadArea.classList.remove('drag-over');
                }, false);
            });

            uploadArea.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                document.getElementById('fileInput').files = files;
                displaySelectedFiles(document.getElementById('fileInput'));
            }, false);
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Confirm before leaving if form has content
        let formChanged = false;
        const form = document.getElementById('submissionForm');
        
        if (form) {
            form.addEventListener('input', () => {
                formChanged = true;
            });

            window.addEventListener('beforeunload', (e) => {
                if (formChanged) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            form.addEventListener('submit', () => {
                formChanged = false;
            });
        }
    </script>
</body>
</html>