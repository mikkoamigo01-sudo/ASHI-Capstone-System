<?php
session_start();

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

if (!isset($_GET['subject_id'])) {
    header("Location: teacher_dashb.php");
    exit();
}

$subject_id      = (int) $_GET['subject_id'];
$teacher_id      = (int) $_SESSION['teacher_id'];
$assignment_type = isset($_GET['type']) ? $_GET['type'] : 'homework';

// Verify subject ownership
$stmt = $conn->prepare("SELECT * FROM subjects WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $subject_id, $teacher_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subject) {
    die("Subject not found or you don't have permission.");
}

// Get enrolled students count
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_subjects WHERE subject_id = ? AND status = 'active'");
$stmt->bind_param("i", $subject_id);
$stmt->execute();
$students_count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

// Get current school year settings
$sy_result = $conn->query("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
$sy_settings = $sy_result ? $sy_result->fetch_assoc() : ['school_year' => date('Y') . '-' . (date('Y') + 1), 'current_quarter' => 1];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim($_POST['title']);
    $description     = trim($_POST['description']);
    $instructions    = trim($_POST['instructions']);
    $due_date        = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $max_points      = floatval($_POST['max_points']);
    $assignment_type = $_POST['assignment_type'];
    $allow_late      = isset($_POST['allow_late_submission']) ? 1 : 0;
    $status          = 'published';
    // Quarter and school year are set automatically from school_year_settings — never from the form
    $quarter         = (int) $sy_settings['current_quarter'];
    $school_year     = $sy_settings['school_year'];

    // Validate required fields
    if (empty($title)) {
        $error = "Assignment title is required.";
    } elseif (!in_array($max_points, [10, 50, 100])) {
        $error = "Points must be 10, 50, or 100.";
    } else {
        // Insert assignment with quarter and school_year
        $stmt = $conn->prepare("
            INSERT INTO assignments (subject_id, teacher_id, title, description, instructions,
                                    due_date, max_points, assignment_type, status, allow_late_submission,
                                    quarter, school_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iissssdssiis",
            $subject_id, $teacher_id, $title, $description, $instructions,
            $due_date, $max_points, $assignment_type, $status, $allow_late,
            $quarter, $school_year
        );

        if ($stmt->execute()) {
            $assignment_id = $conn->insert_id;
            $stmt->close();

            // Handle file uploads
            if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                $upload_dir = '../upload/assignments/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                foreach ($_FILES['attachments']['name'] as $key => $filename) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_tmp  = $_FILES['attachments']['tmp_name'][$key];
                        $file_size = (int) $_FILES['attachments']['size'][$key];
                        $file_ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                        $new_filename = uniqid() . '_' . $filename;
                        $file_path    = $upload_dir . $new_filename;
                        $db_path      = 'upload/assignments/' . $new_filename;

                        if (move_uploaded_file($file_tmp, $file_path)) {
                            $att_stmt = $conn->prepare("
                                INSERT INTO assignment_attachments (assignment_id, file_name, file_path, file_type, file_size)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $att_stmt->bind_param("isssi", $assignment_id, $filename, $db_path, $file_ext, $file_size);
                            $att_stmt->execute();
                            $att_stmt->close();
                        }
                    }
                }
            }

            // Create notifications for enrolled students
            $stmt = $conn->prepare("SELECT student_id FROM student_subjects WHERE subject_id = ? AND status = 'active'");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $notif_title   = 'New Assignment: ' . $title;
            $notif_message = 'A new assignment has been posted in ' . $subject['subject_name'];

            $notif_stmt = $conn->prepare("
                INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
                VALUES ('student', ?, 'assignment', ?, ?, ?)
            ");

            foreach ($students as $student) {
                $notif_stmt->bind_param("issi", $student['student_id'], $notif_title, $notif_message, $assignment_id);
                $notif_stmt->execute();
            }
            $notif_stmt->close();

            $_SESSION['success_message'] = "Assignment created successfully!";
            header("Location: view_assignment.php?id=" . $assignment_id);
            exit();

        } else {
            $error = "Error creating assignment: " . $conn->error;
            $stmt->close();
        }
    }
}

// Theme color with fallback
$theme_color = !empty($subject['theme_color']) ? $subject['theme_color'] : '#1a73e8';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title>Create Assignment — <?php echo htmlspecialchars($subject['subject_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Google+Sans+Display:wght@400;500&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --theme:       <?php echo $theme_color; ?>;
            --theme-light: <?php echo $theme_color; ?>18;
            --theme-hover: <?php echo $theme_color; ?>12;
            --surface:     #ffffff;
            --bg:          #f8f9fa;
            --border:      #e0e0e0;
            --text-primary:   #202124;
            --text-secondary: #5f6368;
            --text-hint:      #9aa0a6;
            --error:    #d93025;
            --error-bg: #fce8e6;
            --info:     #1967d2;
            --info-bg:  #e8f0fe;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-1: 0 1px 2px rgba(60,64,67,.15), 0 1px 3px 1px rgba(60,64,67,.1);
            --shadow-2: 0 1px 3px rgba(60,64,67,.2), 0 4px 8px 3px rgba(60,64,67,.1);
            --transition: .18s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Roboto', sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* ── HEADER ── */
        .header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            height: 64px;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: none;
            color: var(--text-secondary);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background var(--transition), color var(--transition);
        }
        .icon-btn:hover { background: var(--bg); color: var(--text-primary); }

        .subject-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .subject-dot {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--theme);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 15px;
            flex-shrink: 0;
        }

        .subject-info { min-width: 0; }
        .subject-info h1 {
            font-family: 'Google Sans', sans-serif;
            font-size: 17px;
            font-weight: 500;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .subject-info span {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .header-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 0 20px;
            height: 38px;
            border-radius: 100px;
            border: none;
            font-size: 14px;
            font-family: 'Google Sans', sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: background var(--transition), box-shadow var(--transition), color var(--transition);
            white-space: nowrap;
        }
        .btn-filled {
            background: var(--theme);
            color: #fff;
        }
        .btn-filled:hover { box-shadow: var(--shadow-1); filter: brightness(1.06); }
        .btn-tonal {
            background: var(--theme-light);
            color: var(--theme);
        }
        .btn-tonal:hover { background: var(--theme-hover); box-shadow: var(--shadow-1); }
        .btn-outlined {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }
        .btn-outlined:hover { background: var(--bg); }

        /* ── LAYOUT ── */
        .page {
            max-width: 780px;
            margin: 0 auto;
            padding: 28px 20px 60px;
        }

        /* ── ALERTS ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }
        .alert-error  { background: var(--error-bg); color: var(--error); border-left: 4px solid var(--error); }
        .alert-info   { background: var(--info-bg);  color: var(--info);  border-left: 4px solid var(--info); }

        /* ── SECTION LABELS ── */
        .section-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text-hint);
            margin: 28px 0 10px;
        }
        .section-label:first-child { margin-top: 0; }

        /* ── CARDS ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 16px;
            transition: box-shadow var(--transition);
        }
        .card:focus-within { box-shadow: var(--shadow-1); }

        /* ── TITLE ROW ── */
        .title-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
        }
        .type-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0 14px;
            height: 36px;
            border-radius: 100px;
            background: var(--theme-light);
            color: var(--theme);
            font-size: 13px;
            font-weight: 500;
            font-family: 'Google Sans', sans-serif;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .title-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 22px;
            font-family: 'Google Sans Display', sans-serif;
            font-weight: 400;
            color: var(--text-primary);
            background: transparent;
            min-width: 0;
        }
        .title-input::placeholder { color: var(--text-hint); }

        /* ── CARD BODY ── */
        .card-body { padding: 20px 24px; }
        .card-body + .card-body { padding-top: 0; }

        /* ── FORM FIELDS ── */
        .field { margin-bottom: 18px; }
        .field:last-child { margin-bottom: 0; }

        .field-label {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 6px;
            display: block;
        }

        .field-input,
        .field-textarea,
        .field-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            color: var(--text-primary);
            background: var(--surface);
            transition: border-color var(--transition), box-shadow var(--transition);
            appearance: none;
        }
        .field-input:focus,
        .field-textarea:focus,
        .field-select:focus {
            outline: none;
            border-color: var(--theme);
            box-shadow: 0 0 0 3px var(--theme-light);
        }
        .field-textarea {
            min-height: 100px;
            resize: vertical;
            line-height: 1.6;
        }
        .field-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='7' viewBox='0 0 12 7'%3E%3Cpath fill='%235f6368' d='M6 7L0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px; cursor: pointer; }

        /* ── GRID ── */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }

        /* ── FILE UPLOAD ── */
        .upload-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius-md);
            padding: 28px 20px;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition);
        }
        .upload-zone:hover, .upload-zone.drag-over {
            border-color: var(--theme);
            background: var(--theme-hover);
        }
        .upload-zone i {
            font-size: 36px;
            color: var(--text-hint);
            margin-bottom: 10px;
            display: block;
            transition: color var(--transition);
        }
        .upload-zone:hover i { color: var(--theme); }
        .upload-zone-text {
            font-size: 14px;
            color: var(--text-secondary);
        }
        .upload-zone-text strong { color: var(--theme); }
        .upload-zone-hint {
            font-size: 12px;
            color: var(--text-hint);
            margin-top: 4px;
        }
        .file-input { display: none; }

        .file-list { margin-top: 12px; display: flex; flex-direction: column; gap: 6px; }
        .file-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            transition: background var(--transition);
        }
        .file-chip:hover { background: #f1f3f4; }
        .file-chip-icon { color: var(--text-secondary); font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
        .file-chip-name { flex: 1; font-size: 13px; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-chip-size { font-size: 12px; color: var(--text-hint); flex-shrink: 0; }
        .file-chip-remove {
            width: 28px; height: 28px; border-radius: 50%; border: none;
            background: none; color: var(--text-hint); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background var(--transition), color var(--transition);
            flex-shrink: 0;
        }
        .file-chip-remove:hover { background: #e8eaed; color: var(--error); }

        /* ── TOGGLE ── */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-top: 1px solid var(--border);
            margin-top: 4px;
        }
        .toggle-label { font-size: 14px; color: var(--text-primary); }
        .toggle-sub   { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }

        .toggle {
            position: relative;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle-track {
            position: absolute;
            inset: 0;
            border-radius: 100px;
            background: var(--border);
            cursor: pointer;
            transition: background var(--transition);
        }
        .toggle-track::after {
            content: '';
            position: absolute;
            top: 3px; left: 3px;
            width: 18px; height: 18px;
            border-radius: 50%;
            background: #fff;
            transition: transform var(--transition);
            box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .toggle input:checked + .toggle-track { background: var(--theme); }
        .toggle input:checked + .toggle-track::after { transform: translateX(20px); }

        /* ── DIVIDER ── */
        .divider { height: 1px; background: var(--border); margin: 4px 0 18px; }

        /* ── SUBMIT AREA ── */
        .submit-area {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }


        /* ── SY BADGE ── */
        .sy-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin-bottom: 18px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .sy-badge i { color: var(--theme); font-size: 15px; flex-shrink: 0; }
        .sy-badge strong { color: var(--text-primary); }
        .sy-badge-note {
            margin-left: auto;
            font-size: 11px;
            color: var(--text-hint);
            white-space: nowrap;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 600px) {
            .header { padding: 0 12px; }
            .page { padding: 16px 12px 60px; }
            .title-row { padding: 16px; flex-wrap: wrap; }
            .card-body { padding: 16px; }
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
            .subject-info h1 { font-size: 15px; }
            .btn span { display: none; }
            .btn { padding: 0 14px; }
        }
    </style>
</head>
<body>

<!-- ══ HEADER ══ -->
<header class="header">
    <div class="header-left">
        <button class="icon-btn" onclick="goBack()" title="Back">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="subject-badge">
            <div class="subject-dot">
                <i class="fas fa-book-open"></i>
            </div>
            <div class="subject-info">
                <h1><?php echo htmlspecialchars($subject['subject_name']); ?></h1>
                <span>Create Assignment</span>
            </div>
        </div>
    </div>
    <div class="header-right">
        <button type="button" class="btn btn-outlined" onclick="goBack()">
            <i class="fas fa-times"></i>
            <span>Cancel</span>
        </button>
        <button type="submit" form="assignmentForm" class="btn btn-filled">
            <i class="fas fa-paper-plane"></i>
            <span>Assign</span>
        </button>
    </div>
</header>

<!-- ══ PAGE ══ -->
<main class="page">

    <?php if (isset($error)): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($students_count === 0): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <span>No students enrolled yet. Students will see this assignment once they join the class.</span>
    </div>
    <?php endif; ?>

    <form id="assignmentForm" method="POST" enctype="multipart/form-data">

        <!-- ── TITLE & DETAILS ── -->
        <p class="section-label">Assignment Details</p>
        <div class="card">

            <div class="title-row">
                <div class="type-chip" id="typeChip">
                    <i class="fas fa-file-alt" id="typeIcon"></i>
                    <span id="typeLabel">Homework</span>
                </div>
                <input type="text"
                       name="title"
                       class="title-input"
                       placeholder="Assignment title"
                       required autofocus>
            </div>

            <div class="card-body">
                <div class="field">
                    <label class="field-label">Description <span style="color:var(--text-hint)">(optional)</span></label>
                    <textarea name="description" class="field-textarea"
                              placeholder="Give students an overview of this assignment…"></textarea>
                </div>
                <div class="field">
                    <label class="field-label">Instructions</label>
                    <textarea name="instructions" class="field-textarea"
                              placeholder="Provide step-by-step instructions for completing this assignment…"></textarea>
                </div>
            </div>
        </div>

        <!-- ── ATTACHMENTS ── -->
        <p class="section-label">Attachments</p>
        <div class="card">
            <div class="card-body">
                <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <div class="upload-zone-text">
                        <strong>Click to upload</strong> or drag & drop files here
                    </div>
                    <div class="upload-zone-hint">PDF, Word, Excel, Images — up to 25 MB each</div>
                </div>
                <input type="file" id="fileInput" name="attachments[]"
                       class="file-input" multiple onchange="handleFileSelect(event)">
                <div id="fileList" class="file-list"></div>
            </div>
        </div>

        <!-- ── SETTINGS ── -->
        <p class="section-label">Settings</p>
        <div class="card">
            <div class="card-body">

                <!-- Row 1: Type | Points | Due Date -->
                <div class="grid-3" style="margin-bottom:18px">
                    <div class="field" style="margin-bottom:0">
                        <label class="field-label">Assignment Type</label>
                        <select name="assignment_type" class="field-select" id="typeSelect">
                            <option value="homework"  <?php echo $assignment_type === 'homework'  ? 'selected' : ''; ?>>Homework</option>
                            <option value="quiz"      <?php echo $assignment_type === 'quiz'      ? 'selected' : ''; ?>>Quiz</option>
                            <option value="project"   <?php echo $assignment_type === 'project'   ? 'selected' : ''; ?>>Project</option>
                            <option value="exam"      <?php echo $assignment_type === 'exam'      ? 'selected' : ''; ?>>Exam</option>
                            <option value="classwork" <?php echo $assignment_type === 'classwork' ? 'selected' : ''; ?>>Classwork</option>
                        </select>
                    </div>

                    <div class="field" style="margin-bottom:0">
                        <label class="field-label">Points</label>
                        <select name="max_points" class="field-select">
                            <option value="10">10 pts</option>
                            <option value="50">50 pts</option>
                            <option value="100" selected>100 pts</option>
                        </select>
                    </div>

                    <div class="field" style="margin-bottom:0">
                        <label class="field-label">Due Date</label>
                        <input type="datetime-local" name="due_date" class="field-input">
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Auto-assigned School Year & Quarter (read-only) -->
                <div class="sy-badge">
                    <i class="fas fa-calendar-check"></i>
                    <span>
                        Filing under <strong><?php echo htmlspecialchars($sy_settings['school_year']); ?></strong>
                        &mdash; <strong>Quarter <?php echo (int)$sy_settings['current_quarter']; ?></strong>
                    </span>
                    <span class="sy-badge-note">Set by Admin &rsaquo; School Year Settings</span>
                </div>

                <!-- Late submission toggle -->
                <div class="toggle-row">
                    <div>
                        <div class="toggle-label">Allow late submissions</div>
                        <div class="toggle-sub">Students can submit after the due date</div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="allow_late_submission" id="allowLate">
                        <span class="toggle-track"></span>
                    </label>
                </div>

            </div>
        </div>

        <!-- ── SUBMIT ── -->
        <div class="submit-area">
            <button type="button" class="btn btn-outlined" onclick="goBack()">Cancel</button>
            <button type="submit" class="btn btn-filled">
                <i class="fas fa-paper-plane"></i> Assign
            </button>
        </div>

    </form>
</main>

<script>
    /* ── Type chip sync ── */
    const typeIcons = {
        homework:  { icon: 'fa-file-alt',      label: 'Homework'  },
        quiz:      { icon: 'fa-question-circle',label: 'Quiz'      },
        project:   { icon: 'fa-layer-group',    label: 'Project'   },
        exam:      { icon: 'fa-scroll',         label: 'Exam'      },
        classwork: { icon: 'fa-pencil-alt',     label: 'Classwork' },
    };

    const typeSelect = document.getElementById('typeSelect');
    const typeIcon   = document.getElementById('typeIcon');
    const typeLabel  = document.getElementById('typeLabel');

    function updateTypeChip(val) {
        const t = typeIcons[val] || typeIcons.homework;
        typeIcon.className  = 'fas ' + t.icon;
        typeLabel.textContent = t.label;
    }
    typeSelect.addEventListener('change', () => updateTypeChip(typeSelect.value));
    updateTypeChip(typeSelect.value);

    /* ── File handling ── */
    let selectedFiles = [];

    const uploadZone = document.getElementById('uploadZone');

    uploadZone.addEventListener('dragover',  e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
    uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
    uploadZone.addEventListener('drop', e => {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
        addFiles(e.dataTransfer.files);
    });

    function handleFileSelect(e) { addFiles(e.target.files); }

    function addFiles(files) {
        for (const f of files) selectedFiles.push(f);
        renderFiles();
    }

    function renderFiles() {
        const list = document.getElementById('fileList');
        list.innerHTML = '';
        selectedFiles.forEach((file, i) => {
            const chip = document.createElement('div');
            chip.className = 'file-chip';
            chip.innerHTML = `
                <span class="file-chip-icon"><i class="fas fa-${getFileIcon(file.name)}"></i></span>
                <span class="file-chip-name">${file.name}</span>
                <span class="file-chip-size">${formatSize(file.size)}</span>
                <button type="button" class="file-chip-remove" onclick="removeFile(${i})" title="Remove">
                    <i class="fas fa-times"></i>
                </button>
            `;
            list.appendChild(chip);
        });
    }

    function removeFile(i) {
        selectedFiles.splice(i, 1);
        renderFiles();
        document.getElementById('fileInput').value = '';
    }

    function formatSize(b) {
        if (b === 0) return '0 B';
        const k = 1024, s = ['B','KB','MB','GB'];
        const i = Math.floor(Math.log(b) / Math.log(k));
        return (b / Math.pow(k, i)).toFixed(1) + ' ' + s[i];
    }

    function getFileIcon(name) {
        const e = name.split('.').pop().toLowerCase();
        const m = { pdf:'file-pdf', doc:'file-word', docx:'file-word',
                    xls:'file-excel', xlsx:'file-excel', ppt:'file-powerpoint', pptx:'file-powerpoint',
                    jpg:'file-image', jpeg:'file-image', png:'file-image', gif:'file-image', webp:'file-image',
                    zip:'file-archive', rar:'file-archive', mp4:'file-video', mp3:'file-audio' };
        return m[e] || 'file';
    }

    /* ── Unsaved-changes guard ── */
    let dirty = false;
    document.getElementById('assignmentForm').addEventListener('input', () => dirty = true);
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    document.getElementById('assignmentForm').addEventListener('submit', () => dirty = false);

    /* ── Back navigation ── */
    function goBack() {
        if (dirty && !confirm('Leave this page? Unsaved changes will be lost.')) return;
        window.location.href = 'teacher_view_subject.php?id=<?php echo $subject_id; ?>&tab=classwork';
    }
</script>
</body>
</html>