<?php
session_start();

if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../../db_connection/ashi_dbcnnt.php';

if (!isset($_GET['subject_id'])) {
    header("Location: teacher_dashb.php");
    exit();
}

$subject_id = (int)$_GET['subject_id'];
$teacher_id = (int)$_SESSION['teacher_id'];

// Verify subject ownership
$stmt = $conn->prepare("SELECT * FROM subjects WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $subject_id, $teacher_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subject) {
    die("Subject not found or you don't have permission to add materials.");
}

// Get teacher details
$stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get current school year & quarter automatically from settings
$sy_result   = $conn->query("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
$sy_settings = $sy_result
    ? $sy_result->fetch_assoc()
    : ['school_year' => date('Y') . '-' . (date('Y') + 1), 'current_quarter' => 1];

$auto_school_year = $sy_settings['school_year'];
$auto_quarter     = (int) $sy_settings['current_quarter'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title         = trim($_POST['title']);
    $description   = trim($_POST['description']);
    $material_type = $_POST['material_type'];
    $url           = trim($_POST['url'] ?? '');

    if (empty($title)) {
        $_SESSION['error'] = 'Material title is required.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?subject_id=' . $subject_id);
        exit();
    }

    $file_path = null;

    // Handle file upload
    if (in_array($material_type, ['file', 'document']) && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/materials/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if ($_FILES['file']['size'] > 2 * 1024 * 1024) {
            $_SESSION['error'] = 'File size must be less than 2MB.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?subject_id=' . $subject_id);
            exit();
        }

        $allowed_types = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg', 'image/png', 'image/gif',
            'text/plain'
        ];

        if (!in_array($_FILES['file']['type'], $allowed_types)) {
            $_SESSION['error'] = 'Invalid file type. Allowed: PDF, Word, PowerPoint, Excel, Images, Text files.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?subject_id=' . $subject_id);
            exit();
        }

        $file_extension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $new_filename   = 'material_' . time() . '_' . uniqid() . '.' . $file_extension;
        $upload_path    = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $upload_path)) {
            $file_path = $upload_path;
        } else {
            $_SESSION['error'] = 'Failed to upload file.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?subject_id=' . $subject_id);
            exit();
        }
    }

    // Validate URL for link/video types
    if (in_array($material_type, ['link', 'video']) && empty($url)) {
        $_SESSION['error'] = 'URL is required for link/video materials.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?subject_id=' . $subject_id);
        exit();
    }

    // Insert material — quarter & school_year come from school_year_settings, never from the form
    $stmt = $conn->prepare("
        INSERT INTO materials (subject_id, teacher_id, title, description, material_type,
                               file_path, url, quarter, school_year, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param(
        "iisssssis",
        $subject_id, $teacher_id, $title, $description, $material_type,
        $file_path, $url, $auto_quarter, $auto_school_year
    );

    if ($stmt->execute()) {
        $stmt->close();
        $_SESSION['success'] = 'Material created successfully!';
        header('Location: teacher_view_subject.php?id=' . $subject_id . '&tab=classwork&success=' . urlencode('Material added successfully'));
        exit();
    } else {
        $stmt->close();
        $_SESSION['error'] = 'Failed to create material.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?subject_id=' . $subject_id);
        exit();
    }
}

$theme_color = !empty($subject['theme_color']) ? $subject['theme_color'] : '#1a73e8';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title>Create Material — <?php echo htmlspecialchars($subject['subject_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Google+Sans+Display:wght@400;500&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --theme:          <?php echo $theme_color; ?>;
            --theme-light:    <?php echo $theme_color; ?>18;
            --theme-hover:    <?php echo $theme_color; ?>12;
            --surface:        #ffffff;
            --bg:             #f8f9fa;
            --border:         #e0e0e0;
            --text-primary:   #202124;
            --text-secondary: #5f6368;
            --text-hint:      #9aa0a6;
            --error:          #d93025;
            --error-bg:       #fce8e6;
            --success:        #1e8e3e;
            --success-bg:     #e6f4ea;
            --radius-sm:      6px;
            --radius-md:      10px;
            --radius-lg:      14px;
            --shadow-1:       0 1px 2px rgba(60,64,67,.15), 0 1px 3px 1px rgba(60,64,67,.1);
            --shadow-2:       0 1px 3px rgba(60,64,67,.2), 0 4px 8px 3px rgba(60,64,67,.1);
            --transition:     .18s cubic-bezier(.4,0,.2,1);
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

        .header-left { display: flex; align-items: center; gap: 12px; min-width: 0; }

        .icon-btn {
            width: 40px; height: 40px;
            border-radius: 50%; border: none;
            background: none; color: var(--text-secondary);
            font-size: 18px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: background var(--transition), color var(--transition);
        }
        .icon-btn:hover { background: var(--bg); color: var(--text-primary); }

        .subject-badge { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .subject-dot {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--theme);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 15px; flex-shrink: 0;
        }
        .subject-info { min-width: 0; }
        .subject-info h1 {
            font-family: 'Google Sans', sans-serif;
            font-size: 17px; font-weight: 500;
            color: var(--text-primary);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .subject-info span { font-size: 12px; color: var(--text-secondary); }

        .header-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 0 20px; height: 38px;
            border-radius: 100px; border: none;
            font-size: 14px; font-family: 'Google Sans', sans-serif; font-weight: 500;
            cursor: pointer;
            transition: background var(--transition), box-shadow var(--transition), filter var(--transition);
            white-space: nowrap;
        }
        .btn-filled  { background: var(--theme); color: #fff; }
        .btn-filled:hover  { box-shadow: var(--shadow-1); filter: brightness(1.06); }
        .btn-filled:disabled { background: var(--border); color: var(--text-hint); cursor: not-allowed; filter: none; box-shadow: none; }
        .btn-outlined { background: transparent; color: var(--text-secondary); border: 1px solid var(--border); }
        .btn-outlined:hover { background: var(--bg); }

        /* ── PAGE ── */
        .page { max-width: 780px; margin: 0 auto; padding: 28px 20px 60px; }

        /* ── ALERTS ── */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px; border-radius: var(--radius-md);
            margin-bottom: 20px; font-size: 14px;
            animation: slideDown .25s ease;
        }
        .alert i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }
        .alert-error   { background: var(--error-bg);   color: var(--error);   border-left: 4px solid var(--error); }
        .alert-success { background: var(--success-bg); color: var(--success); border-left: 4px solid var(--success); }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:none; } }

        /* ── SECTION LABELS ── */
        .section-label {
            font-size: 11px; font-weight: 600;
            letter-spacing: .08em; text-transform: uppercase;
            color: var(--text-hint); margin: 28px 0 10px;
        }
        .section-label:first-child { margin-top: 0; }

        /* ── CARDS ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden; margin-bottom: 16px;
            transition: box-shadow var(--transition);
        }
        .card:focus-within { box-shadow: var(--shadow-1); }
        .card-body { padding: 20px 24px; }

        /* ── FIELDS ── */
        .field { margin-bottom: 18px; }
        .field:last-child { margin-bottom: 0; }
        .field-label {
            font-size: 12px; font-weight: 500;
            color: var(--text-secondary); margin-bottom: 6px; display: block;
        }
        .field-label .req { color: var(--error); }
        .field-input,
        .field-textarea {
            width: 100%; padding: 10px 14px;
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            font-size: 14px; font-family: 'Roboto', sans-serif;
            color: var(--text-primary); background: var(--surface);
            transition: border-color var(--transition), box-shadow var(--transition);
        }
        .field-input:focus, .field-textarea:focus {
            outline: none;
            border-color: var(--theme);
            box-shadow: 0 0 0 3px var(--theme-light);
        }
        .field-textarea { min-height: 100px; resize: vertical; line-height: 1.6; }
        .field-hint { font-size: 12px; color: var(--text-hint); margin-top: 5px; }

        /* ── TYPE SELECTOR ── */
        .type-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        .type-opt { position: relative; }
        .type-opt input[type="radio"] { display: none; }
        .type-opt label {
            display: flex; flex-direction: column;
            align-items: center; gap: 8px;
            padding: 16px 10px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition);
            background: var(--surface);
        }
        .type-opt label:hover {
            border-color: var(--theme);
            background: var(--theme-hover);
        }
        .type-opt input[type="radio"]:checked + label {
            border-color: var(--theme);
            background: var(--theme-light);
        }
        .type-icon-wrap {
            width: 42px; height: 42px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            background: var(--bg);
            color: var(--text-secondary);
            transition: background var(--transition), color var(--transition);
        }
        .type-opt input[type="radio"]:checked + label .type-icon-wrap {
            background: var(--theme);
            color: #fff;
        }
        .type-name {
            font-size: 13px; font-weight: 500;
            color: var(--text-primary); font-family: 'Google Sans', sans-serif;
        }

        /* ── UPLOAD ZONE ── */
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
        .upload-zone i { font-size: 36px; color: var(--text-hint); margin-bottom: 10px; display: block; transition: color var(--transition); }
        .upload-zone:hover i { color: var(--theme); }
        .upload-zone-text { font-size: 14px; color: var(--text-secondary); }
        .upload-zone-text strong { color: var(--theme); }
        .upload-zone-hint { font-size: 12px; color: var(--text-hint); margin-top: 4px; }
        #file-input { display: none; }

        .file-chip {
            display: none;
            align-items: center; gap: 10px;
            padding: 11px 14px; margin-top: 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
        }
        .file-chip.show { display: flex; }
        .file-chip-icon { color: var(--theme); font-size: 18px; flex-shrink: 0; }
        .file-chip-info { flex: 1; min-width: 0; }
        .file-chip-name { font-size: 13px; font-weight: 500; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-chip-size { font-size: 12px; color: var(--text-hint); }
        .file-chip-remove {
            width: 30px; height: 30px; border-radius: 50%; border: none;
            background: none; color: var(--text-hint); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background var(--transition), color var(--transition);
        }
        .file-chip-remove:hover { background: var(--error-bg); color: var(--error); }

        /* ── URL SECTION ── */
        .conditional { display: none; }
        .conditional.show { display: block; animation: fadeIn .2s ease; }
        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }

        /* ── SY BADGE ── */
        .sy-badge {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px; color: var(--text-secondary);
        }
        .sy-badge i { color: var(--theme); font-size: 15px; flex-shrink: 0; }
        .sy-badge strong { color: var(--text-primary); }
        .sy-badge-note { margin-left: auto; font-size: 11px; color: var(--text-hint); white-space: nowrap; }

        /* ── SUBMIT ── */
        .submit-area {
            display: flex; justify-content: flex-end; gap: 10px;
            margin-top: 24px;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 600px) {
            .header { padding: 0 12px; }
            .page { padding: 16px 12px 60px; }
            .card-body { padding: 16px; }
            .type-grid { grid-template-columns: repeat(2, 1fr); }
            .subject-info h1 { font-size: 15px; }
            .btn span { display: none; }
            .btn { padding: 0 14px; }
            .sy-badge-note { display: none; }
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
                <span>Create Material</span>
            </div>
        </div>
    </div>
    <div class="header-right">
        <button type="button" class="btn btn-outlined" onclick="goBack()">
            <i class="fas fa-times"></i>
            <span>Cancel</span>
        </button>
        <button type="submit" form="materialForm" class="btn btn-filled" id="submitBtn">
            <i class="fas fa-check"></i>
            <span>Save Material</span>
        </button>
    </div>
</header>

<!-- ══ PAGE ══ -->
<main class="page">

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
    </div>
    <?php endif; ?>

    <form id="materialForm" method="POST" enctype="multipart/form-data">

        <!-- ── DETAILS ── -->
        <p class="section-label">Material Details</p>
        <div class="card">
            <div class="card-body">
                <div class="field">
                    <label class="field-label">Title <span class="req">*</span></label>
                    <input type="text" name="title" class="field-input"
                           placeholder="e.g., Chapter 5 Study Guide" required autofocus>
                </div>
                <div class="field">
                    <label class="field-label">Description <span style="color:var(--text-hint)">(optional)</span></label>
                    <textarea name="description" class="field-textarea"
                              placeholder="Add context or instructions for students…"></textarea>
                    <div class="field-hint">Helps students understand how to use this material.</div>
                </div>
            </div>
        </div>

        <!-- ── TYPE ── -->
        <p class="section-label">Material Type</p>
        <div class="card">
            <div class="card-body">
                <div class="type-grid">
                    <div class="type-opt">
                        <input type="radio" id="t-file" name="material_type" value="file" checked>
                        <label for="t-file">
                            <div class="type-icon-wrap"><i class="fas fa-file"></i></div>
                            <span class="type-name">File</span>
                        </label>
                    </div>
                    <div class="type-opt">
                        <input type="radio" id="t-document" name="material_type" value="document">
                        <label for="t-document">
                            <div class="type-icon-wrap"><i class="fas fa-file-alt"></i></div>
                            <span class="type-name">Document</span>
                        </label>
                    </div>
                    <div class="type-opt">
                        <input type="radio" id="t-link" name="material_type" value="link">
                        <label for="t-link">
                            <div class="type-icon-wrap"><i class="fas fa-link"></i></div>
                            <span class="type-name">Link</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── FILE UPLOAD ── -->
        <div id="fileSection" class="conditional show">
            <p class="section-label">Upload File</p>
            <div class="card">
                <div class="card-body">
                    <div class="upload-zone" id="uploadZone">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div class="upload-zone-text">
                            <strong>Click to upload</strong> or drag & drop
                        </div>
                        <div class="upload-zone-hint">PDF, Word, PowerPoint, Excel, Images, Text — max 2 MB</div>
                    </div>
                    <input type="file" id="file-input" name="file"
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt">
                    <div class="file-chip" id="fileChip">
                        <span class="file-chip-icon"><i class="fas fa-file"></i></span>
                        <div class="file-chip-info">
                            <div class="file-chip-name" id="chipName"></div>
                            <div class="file-chip-size" id="chipSize"></div>
                        </div>
                        <button type="button" class="file-chip-remove" id="removeFile" title="Remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── URL ── -->
        <div id="urlSection" class="conditional">
            <p class="section-label">Link URL</p>
            <div class="card">
                <div class="card-body">
                    <div class="field">
                        <label class="field-label">URL <span class="req">*</span></label>
                        <input type="url" name="url" id="urlInput" class="field-input"
                               placeholder="https://example.com">
                        <div class="field-hint">Include the full URL with https://</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── AUTO QUARTER / SCHOOL YEAR ── -->
        <p class="section-label">Filing Info</p>
        <div class="card">
            <div class="card-body">
                <div class="sy-badge">
                    <i class="fas fa-calendar-check"></i>
                    <span>
                        Filing under
                        <strong><?php echo htmlspecialchars($auto_school_year); ?></strong>
                        &mdash;
                        <strong>Quarter <?php echo $auto_quarter; ?></strong>
                    </span>
                    <span class="sy-badge-note">Set by Admin &rsaquo; School Year Settings</span>
                </div>
            </div>
        </div>

        <!-- ── SUBMIT ── -->
        <div class="submit-area">
            <button type="button" class="btn btn-outlined" onclick="goBack()">Cancel</button>
            <button type="submit" class="btn btn-filled" id="submitBtn2">
                <i class="fas fa-check"></i> Save Material
            </button>
        </div>

    </form>
</main>

<script>
    /* ── Type toggle ── */
    const typeRadios  = document.querySelectorAll('input[name="material_type"]');
    const fileSection = document.getElementById('fileSection');
    const urlSection  = document.getElementById('urlSection');
    const urlInput    = document.getElementById('urlInput');
    const fileInput   = document.getElementById('file-input');

    typeRadios.forEach(r => r.addEventListener('change', () => {
        const val = document.querySelector('input[name="material_type"]:checked').value;
        const isFile = (val === 'file' || val === 'document');

        fileSection.classList.toggle('show', isFile);
        urlSection.classList.toggle('show', !isFile);

        if (!isFile) {
            urlInput.setAttribute('required', 'required');
            clearFile();
        } else {
            urlInput.removeAttribute('required');
        }
    }));

    /* ── Upload zone ── */
    const uploadZone = document.getElementById('uploadZone');
    const fileChip   = document.getElementById('fileChip');
    const chipName   = document.getElementById('chipName');
    const chipSize   = document.getElementById('chipSize');

    uploadZone.addEventListener('click', () => fileInput.click());

    uploadZone.addEventListener('dragover', e => {
        e.preventDefault();
        uploadZone.classList.add('drag-over');
    });
    uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
    uploadZone.addEventListener('drop', e => {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
    });

    fileInput.addEventListener('change', e => {
        if (e.target.files.length) handleFile(e.target.files[0]);
    });

    function handleFile(file) {
        if (file.size > 2 * 1024 * 1024) {
            alert('File size must be less than 2 MB.');
            fileInput.value = '';
            return;
        }
        chipName.textContent = file.name;
        chipSize.textContent = fmtSize(file.size);
        fileChip.classList.add('show');
        uploadZone.style.display = 'none';
    }

    document.getElementById('removeFile').addEventListener('click', clearFile);

    function clearFile() {
        fileInput.value = '';
        fileChip.classList.remove('show');
        uploadZone.style.display = '';
    }

    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }

    /* ── Validation & submit ── */
    document.getElementById('materialForm').addEventListener('submit', function(e) {
        const val = document.querySelector('input[name="material_type"]:checked').value;
        const isFile = (val === 'file' || val === 'document');

        if (isFile && (!fileInput.files || !fileInput.files.length)) {
            e.preventDefault();
            alert('Please select a file to upload.');
            return;
        }
        if (!isFile && !urlInput.value.trim()) {
            e.preventDefault();
            alert('Please enter a URL.');
            return;
        }

        // Disable buttons to prevent double-submit
        document.getElementById('submitBtn').disabled  = true;
        document.getElementById('submitBtn2').disabled = true;
        document.getElementById('submitBtn').innerHTML  = '<i class="fas fa-spinner fa-spin"></i> <span>Saving…</span>';
        document.getElementById('submitBtn2').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    });

    /* ── Back ── */
    let dirty = false;
    document.getElementById('materialForm').addEventListener('input', () => dirty = true);
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    document.getElementById('materialForm').addEventListener('submit', () => dirty = false);

    function goBack() {
        if (dirty && !confirm('Leave this page? Unsaved changes will be lost.')) return;
        window.location.href = 'teacher_view_subject.php?id=<?php echo $subject_id; ?>&tab=classwork';
    }
</script>
</body>
</html>