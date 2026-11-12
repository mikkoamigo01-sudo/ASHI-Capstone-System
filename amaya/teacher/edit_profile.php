<?php
// edit_profile.php
session_start();
if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../db_connection/ashi_dbcnnt.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname       = trim($_POST['fullname'] ?? '');
    $birthdate      = trim($_POST['birthdate'] ?? '');
    $gender         = trim($_POST['gender'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $education      = trim($_POST['education'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $bio            = trim($_POST['bio'] ?? '');
    $theme_color    = trim($_POST['theme_color'] ?? '#2563eb');

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_color)) $theme_color = '#2563eb';

    if (empty($fullname) || empty($birthdate) || empty($gender) || empty($phone) || empty($address) || empty($education) || empty($specialization)) {
        $error = 'Please fill in all required fields.';
    } elseif (strlen(preg_replace('/[^0-9]/', '', $phone)) !== 11) {
        $error = 'Phone number must be exactly 11 digits.';
    } else {
        $birthdateObj = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$birthdateObj) {
            $error = 'Invalid date format.';
        } else {
            $age = (new DateTime())->diff($birthdateObj)->y;
            if ($age < 19) $error = 'You must be at least 19 years old.';
        }
    }

    if (empty($error)) {
        $currentStmt = $conn->prepare("SELECT profile_picture, password, email FROM teachers WHERE id = ?");
        $currentStmt->bind_param("i", $_SESSION['teacher_id']);
        $currentStmt->execute();
        $currentTeacher = $currentStmt->get_result()->fetch_assoc();
        $currentStmt->close();
        $profile_picture_path = $currentTeacher['profile_picture'];

        if (($_POST['remove_photo'] ?? '0') === '1') {
            if ($profile_picture_path && file_exists($profile_picture_path)) unlink($profile_picture_path);
            $profile_picture_path = null;
        }

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file          = $_FILES['profile_picture'];
            $allowed_types = ['image/jpeg','image/jpg','image/png','image/gif','image/webp'];
            if (!in_array($file['type'], $allowed_types)) {
                $error = 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = 'Image size must be less than 5MB.';
            } else {
                $upload_dir = 'upload/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext          = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = 'teacher_' . time() . '_' . uniqid() . '.' . $ext;
                $upload_path  = $upload_dir . $new_filename;
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    if ($profile_picture_path && file_exists($profile_picture_path)) unlink($profile_picture_path);
                    $profile_picture_path = $upload_path;
                } else {
                    $error = 'Failed to upload profile picture.';
                }
            }
        }
    }

    if (empty($error)) {
        $password_hash = null;
        $new_password  = $_POST['new_password'] ?? '';
        $confirm_pw    = $_POST['confirm_password'] ?? '';
        $current_pw    = $_POST['current_password'] ?? '';

        if (!empty($new_password)) {
            if (strlen($new_password) < 6)
                $error = 'New password must be at least 6 characters long.';
            elseif ($new_password !== $confirm_pw)
                $error = 'New passwords do not match.';
            elseif (!password_verify($current_pw, $currentTeacher['password']))
                $error = 'Current password is incorrect.';
            else
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        }

        if (empty($error)) {
            if ($password_hash) {
                $s = $conn->prepare("UPDATE teachers SET fullname=?,birthdate=?,gender=?,phone=?,address=?,education=?,specialization=?,bio=?,profile_picture=?,password=?,theme_color=? WHERE id=?");
                $s->bind_param("sssssssssssi", $fullname,$birthdate,$gender,$phone,$address,$education,$specialization,$bio,$profile_picture_path,$password_hash,$theme_color,$_SESSION['teacher_id']);
            } else {
                $s = $conn->prepare("UPDATE teachers SET fullname=?,birthdate=?,gender=?,phone=?,address=?,education=?,specialization=?,bio=?,profile_picture=?,theme_color=? WHERE id=?");
                $s->bind_param("ssssssssssi", $fullname,$birthdate,$gender,$phone,$address,$education,$specialization,$bio,$profile_picture_path,$theme_color,$_SESSION['teacher_id']);
            }
            $s->execute();
            $s->close();
            $_SESSION['teacher_name'] = $fullname;
            $_SESSION['theme_color']  = $theme_color;
            header('Location: teacher_dashb.php?profile_updated=1');
            exit();
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->bind_param("i", $_SESSION['teacher_id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) { header('Location: teacher_dashb.php'); exit(); }

$conn->close();

$nameParts = explode(' ', $teacher['fullname'] ?? '');
$initials  = '';
foreach ($nameParts as $p) if (!empty($p)) $initials .= strtoupper($p[0]);
$initials = substr($initials, 0, 2);

$currentTheme = $teacher['theme_color'] ?? '#2563eb';
$maxBirthdate = (new DateTime())->modify('-19 years')->format('Y-m-d');

$colorPresets = [
    'Blue'     => '#2563eb',
    'Indigo'   => '#4f46e5',
    'Violet'   => '#7c3aed',
    'Fuchsia'  => '#a21caf',
    'Rose'     => '#e11d48',
    'Red'      => '#dc2626',
    'Amber'    => '#d97706',
    'Emerald'  => '#059669',
    'Teal'     => '#0d9488',
    'Cyan'     => '#0891b2',
    'Slate'    => '#475569',
    'Charcoal' => '#1e293b',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="img/ashi.jpg" type="image/x-icon">
<title>Edit Profile — Ashi Amaya</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --primary:       <?= htmlspecialchars($currentTheme) ?>;
    --primary-faint: color-mix(in srgb, <?= htmlspecialchars($currentTheme) ?> 10%, #ffffff);
    --bg:            #f8f9fa;
    --surface:       #ffffff;
    --divider:       #e8eaed;
    --text-1:        #202124;
    --text-2:        #5f6368;
    --text-3:        #9aa0a6;
    --hover:         #f1f3f4;
    --error:         #d93025;
    --error-bg:      #fce8e6;
    --topbar-h:      64px;
    --nav-w:         224px;
    --ease:          cubic-bezier(0.4,0,0.2,1);
}

body {
    font-family: 'Roboto', sans-serif;
    background: var(--bg);
    color: var(--text-1);
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
}

/* ── Top bar ── */
.topbar {
    position: fixed;
    inset: 0 0 auto 0;
    height: var(--topbar-h);
    background: var(--surface);
    border-bottom: 1px solid var(--divider);
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 16px;
    z-index: 300;
}

.icon-btn {
    width: 40px; height: 40px;
    border-radius: 50%;
    border: none;
    background: transparent;
    display: grid; place-items: center;
    cursor: pointer;
    color: var(--text-2);
    font-size: 16px;
    flex-shrink: 0;
    transition: background 0.15s var(--ease);
    text-decoration: none;
}
.icon-btn:hover { background: var(--hover); }

.topbar-title {
    font-family: 'Google Sans', sans-serif;
    font-size: 20px;
    font-weight: 400;
    color: var(--text-1);
    margin-left: 4px;
}

/* ── Layout ── */
.layout {
    display: grid;
    grid-template-columns: var(--nav-w) 1fr;
    padding-top: var(--topbar-h);
    min-height: 100vh;
    max-width: 1080px;
    margin: 0 auto;
}

/* ── Sidebar ── */
.sidebar {
    padding: 16px 0;
    position: sticky;
    top: var(--topbar-h);
    height: calc(100vh - var(--topbar-h));
    overflow-y: auto;
}

.nav-label {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-3);
    padding: 10px 24px 6px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 24px;
    margin-right: 8px;
    border-radius: 0 100px 100px 0;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-2);
    cursor: pointer;
    user-select: none;
    transition: background 0.15s var(--ease), color 0.15s var(--ease);
}
.nav-link i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
.nav-link:hover { background: var(--hover); color: var(--text-1); }
.nav-link.active {
    background: var(--primary-faint);
    color: var(--primary);
    font-weight: 600;
}

/* ── Main ── */
.main {
    padding: 40px 56px 96px 40px;
    min-width: 0;
}

/* ── Alert ── */
.alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 32px;
    font-size: 14px;
    animation: fadein .2s ease;
}
@keyframes fadein { from { opacity:0; transform:translateY(-4px) } to { opacity:1; transform:none } }
.alert-error { background: var(--error-bg); color: var(--error); }

/* ── Section ── */
.section {
    padding-bottom: 40px;
    margin-bottom: 0;
    scroll-margin-top: calc(var(--topbar-h) + 16px);
}
.section + .section {
    padding-top: 36px;
    border-top: 1px solid var(--divider);
}

.section-head { margin-bottom: 28px; }
.section-title {
    font-family: 'Google Sans', sans-serif;
    font-size: 16px;
    font-weight: 500;
    color: var(--text-1);
    margin-bottom: 4px;
}
.section-desc { font-size: 13px; color: var(--text-2); }

/* ── Photo ── */
.photo-row {
    display: flex;
    align-items: center;
    gap: 32px;
}

.avatar-wrap {
    position: relative;
    width: 80px; height: 80px;
    border-radius: 50%;
    flex-shrink: 0;
    cursor: pointer;
}
.avatar-img, .avatar-initials {
    width: 80px; height: 80px;
    border-radius: 50%;
    object-fit: cover;
    display: block;
}
.avatar-initials {
    background: var(--primary);
    display: grid; place-items: center;
    font-family: 'Google Sans', sans-serif;
    font-size: 26px; font-weight: 500;
    color: #fff;
    transition: background .3s var(--ease);
}
.avatar-overlay {
    position: absolute; inset: 0;
    border-radius: 50%;
    background: rgba(0,0,0,.48);
    display: grid; place-items: center;
    opacity: 0;
    transition: opacity .15s var(--ease);
}
.avatar-wrap:hover .avatar-overlay { opacity: 1; }
.avatar-overlay i { color: #fff; font-size: 18px; }

.photo-meta p { font-size: 13px; color: var(--text-2); margin-bottom: 12px; }
.photo-btns { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 36px; padding: 0 16px;
    border-radius: 4px;
    font-family: 'Google Sans', sans-serif;
    font-size: 13px; font-weight: 500;
    cursor: pointer; border: none;
    transition: background .15s var(--ease), box-shadow .15s var(--ease);
    white-space: nowrap;
    text-decoration: none;
}
.btn-fill {
    background: var(--primary);
    color: #fff;
}
.btn-fill:hover { filter: brightness(1.1); box-shadow: 0 1px 4px rgba(0,0,0,.22); }

.btn-outline {
    background: transparent;
    color: var(--primary);
    border: 1px solid color-mix(in srgb, var(--primary) 45%, transparent);
}
.btn-outline:hover { background: var(--primary-faint); }

.btn-plain {
    background: transparent;
    color: var(--error);
    padding: 0 12px;
}
.btn-plain:hover { background: var(--error-bg); }

.btn-ghost {
    background: transparent;
    color: var(--text-2);
    border: 1px solid var(--divider);
}
.btn-ghost:hover { background: var(--hover); color: var(--text-1); }

.btn:disabled { opacity: .45; cursor: not-allowed; filter: none !important; }

/* ── Material filled text field ── */
.field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 24px;
}
.span2 { grid-column: 1/-1; }

.field { position: relative; margin-top: 8px; }

.mfield {
    position: relative;
    background: var(--hover);
    border-radius: 4px 4px 0 0;
    border-bottom: 1px solid #bdc1c6;
    transition: border-color .18s var(--ease), background .18s var(--ease);
}
.mfield:hover { background: #e8eaed; }
.mfield:focus-within {
    background: color-mix(in srgb, var(--primary) 6%, var(--hover));
    border-bottom: 2px solid var(--primary);
}

.mfield input,
.mfield select,
.mfield textarea {
    width: 100%;
    padding: 20px 12px 6px;
    border: none;
    background: transparent;
    font-family: 'Roboto', sans-serif;
    font-size: 14px;
    color: var(--text-1);
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    line-height: 1.5;
}

.mfield textarea {
    resize: vertical;
    min-height: 88px;
    padding-top: 22px;
}

.mfield label {
    position: absolute;
    left: 12px; top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    color: var(--text-2);
    pointer-events: none;
    transition: top .18s var(--ease), font-size .18s var(--ease), color .18s var(--ease), transform .18s var(--ease);
    transform-origin: left top;
    white-space: nowrap;
}

/* Floated state */
.mfield:focus-within label,
.mfield input:not(:placeholder-shown) ~ label,
.mfield select ~ label,
.mfield textarea:not(:placeholder-shown) ~ label {
    top: 8px;
    transform: none;
    font-size: 11px;
}
.mfield:focus-within label { color: var(--primary); }
.mfield input:not(:focus):not(:placeholder-shown) ~ label,
.mfield select ~ label { color: var(--text-3); }

/* date always floated */
.mfield input[type="date"] ~ label { top: 8px; transform: none; font-size: 11px; }
.mfield input[type="date"]:focus ~ label { color: var(--primary); }

/* textarea label always floated */
.mfield.ta-wrap label { top: 10px; transform: none; font-size: 11px; color: var(--text-3); }
.mfield.ta-wrap:focus-within label { color: var(--primary); }

.field-hint {
    font-size: 11px;
    color: var(--text-3);
    padding: 4px 12px 0;
}

/* Locked email */
.mfield-locked {
    background: var(--hover);
    border-radius: 4px 4px 0 0;
    border-bottom: 1px solid #bdc1c6;
    padding: 8px 12px 8px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.mfield-locked .locked-label { font-size: 11px; color: var(--text-3); }
.mfield-locked .locked-val   { font-size: 14px; color: var(--text-2); display: flex; align-items: center; gap: 8px; }
.mfield-locked i              { font-size: 12px; color: var(--text-3); }

/* Char count */
.char-count { font-size: 11px; color: var(--text-3); text-align: right; padding: 3px 12px 0; }

/* ── Theme color ── */
.swatch-row {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 24px;
}

.swatch {
    width: 34px; height: 34px;
    border-radius: 50%;
    cursor: pointer;
    position: relative;
    transition: transform .18s var(--ease), box-shadow .18s var(--ease);
    flex-shrink: 0;
}
.swatch:hover { transform: scale(1.18); box-shadow: 0 2px 8px rgba(0,0,0,.22); }
.swatch.active {
    transform: scale(1.1);
    box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px currentColor;
}
.swatch.active::after {
    content: '\f00c';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    position: absolute; inset: 0;
    display: grid; place-items: center;
    color: #fff;
    font-size: 11px;
}

.custom-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.native-picker {
    width: 44px; height: 40px;
    padding: 3px;
    border: 1px solid var(--divider);
    border-radius: 4px;
    background: var(--surface);
    cursor: pointer;
    flex-shrink: 0;
    transition: border-color .15s;
}
.native-picker:hover { border-color: var(--primary); }

.hex-field {
    position: relative;
    background: var(--hover);
    border-radius: 4px 4px 0 0;
    border-bottom: 1px solid #bdc1c6;
    transition: border-color .18s;
    width: 130px;
}
.hex-field:focus-within { border-bottom: 2px solid var(--primary); }
.hex-field input {
    width: 100%;
    padding: 10px 12px;
    border: none;
    background: transparent;
    font-family: monospace;
    font-size: 14px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--text-1);
    outline: none;
}

.preview-chip {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 16px 7px 10px;
    background: var(--hover);
    border-radius: 100px;
}
.preview-dot {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: var(--primary);
    flex-shrink: 0;
    transition: background .25s var(--ease);
}
.preview-chip span {
    font-size: 12px;
    color: var(--text-2);
    font-family: monospace;
    letter-spacing: 1px;
}

/* ── Actions ── */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 36px;
}

/* ── Responsive ── */
@media (max-width: 860px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar { display: none; }
    .main { padding: 28px 20px 72px; }
}
@media (max-width: 520px) {
    .field-grid { grid-template-columns: 1fr; }
    .span2 { grid-column: 1; }
    .form-actions { flex-direction: column-reverse; }
    .form-actions .btn { width: 100%; justify-content: center; }
}
</style>
</head>
<body>

<header class="topbar">
    <a href="teacher_dashb.php" class="icon-btn" title="Back to dashboard">
        <i class="fas fa-arrow-left"></i>
    </a>
    <span class="topbar-title">Edit profile</span>
</header>

<div class="layout">

    <!-- Sidebar nav -->
    <nav class="sidebar">
        <div class="nav-label">Profile settings</div>
        <div class="nav-link active" data-target="s-photo" onclick="navClick(this,'s-photo')">
            <i class="fas fa-camera-retro"></i> Photo
        </div>
        <div class="nav-link" data-target="s-personal" onclick="navClick(this,'s-personal')">
            <i class="fas fa-user"></i> Personal info
        </div>
        <div class="nav-link" data-target="s-contact" onclick="navClick(this,'s-contact')">
            <i class="fas fa-phone"></i> Contact
        </div>
        <div class="nav-link" data-target="s-professional" onclick="navClick(this,'s-professional')">
            <i class="fas fa-briefcase"></i> Professional
        </div>
        <div class="nav-link" data-target="s-theme" onclick="navClick(this,'s-theme')">
            <i class="fas fa-palette"></i> Theme
        </div>
    </nav>

    <!-- Content -->
    <main class="main">

        <?php if (!empty($error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="editForm" novalidate>

            <!-- Photo -->
            <div class="section" id="s-photo">
                <div class="section-head">
                    <div class="section-title">Profile photo</div>
                    <div class="section-desc">A photo helps people recognize you more easily.</div>
                </div>
                <div class="photo-row">
                    <div class="avatar-wrap" onclick="document.getElementById('filePick').click()" title="Change photo">
                        <?php if (!empty($teacher['profile_picture'])): ?>
                            <?php $pic = $teacher['profile_picture']; if (!str_starts_with($pic,'upload')) $pic = 'upload/'.$pic; ?>
                            <img class="avatar-img" src="<?= htmlspecialchars($pic) ?>" alt="Profile" id="avatarEl">
                        <?php else: ?>
                            <div class="avatar-initials" id="avatarEl"><?= htmlspecialchars($initials) ?></div>
                        <?php endif; ?>
                        <div class="avatar-overlay"><i class="fas fa-camera"></i></div>
                    </div>
                    <div class="photo-meta">
                        <p>JPG, PNG, GIF or WEBP · max 5 MB</p>
                        <div class="photo-btns">
                            <button type="button" class="btn btn-outline" onclick="document.getElementById('filePick').click()">
                                <i class="fas fa-upload"></i> Upload photo
                            </button>
                            <button type="button" class="btn btn-plain" id="removeBtn" onclick="removePhoto()"
                                <?= empty($teacher['profile_picture']) ? 'style="display:none"' : '' ?>>
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
                <input type="file" id="filePick" name="profile_picture" accept="image/*" style="display:none" onchange="previewPhoto(event)">
                <input type="hidden" name="remove_photo" id="removeFlag" value="0">
            </div>

            <!-- Personal -->
            <div class="section" id="s-personal">
                <div class="section-head">
                    <div class="section-title">Personal information</div>
                    <div class="section-desc">Your name, date of birth, and gender.</div>
                </div>
                <div class="field-grid">

                    <div class="field span2">
                        <div class="mfield">
                            <input type="text" id="fullname" name="fullname" placeholder=" "
                                   value="<?= htmlspecialchars($teacher['fullname'] ?? '') ?>" required autocomplete="name">
                            <label for="fullname">Full name</label>
                        </div>
                    </div>

                    <div class="field">
                        <div class="mfield">
                            <input type="date" id="birthdate" name="birthdate"
                                   value="<?= htmlspecialchars($teacher['birthdate'] ?? '') ?>"
                                   max="<?= $maxBirthdate ?>" required>
                            <label for="birthdate">Date of birth</label>
                        </div>
                        <div class="field-hint">Must be at least 19 years old</div>
                    </div>

                    <div class="field">
                        <div class="mfield">
                            <select id="gender" name="gender" required>
                                <option value="" disabled <?= empty($teacher['gender']) ? 'selected':'' ?>></option>
                                <option value="Male"              <?= ($teacher['gender']??'')==='Male'             ?'selected':'' ?>>Male</option>
                                <option value="Female"            <?= ($teacher['gender']??'')==='Female'           ?'selected':'' ?>>Female</option>
                                <option value="Non-binary"        <?= ($teacher['gender']??'')==='Non-binary'       ?'selected':'' ?>>Non-binary</option>
                                <option value="Prefer not to say" <?= ($teacher['gender']??'')==='Prefer not to say'?'selected':'' ?>>Prefer not to say</option>
                            </select>
                            <label for="gender">Gender</label>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Contact -->
            <div class="section" id="s-contact">
                <div class="section-head">
                    <div class="section-title">Contact information</div>
                    <div class="section-desc">How students and staff can reach you.</div>
                </div>
                <div class="field-grid">

                    <div class="field">
                        <div class="mfield-locked">
                            <span class="locked-label">Email address</span>
                            <span class="locked-val">
                                <i class="fas fa-lock"></i>
                                <?= htmlspecialchars($teacher['email'] ?? '') ?>
                            </span>
                        </div>
                        <div class="field-hint">Managed by your administrator · cannot be changed</div>
                    </div>

                    <div class="field">
                        <div class="mfield">
                            <input type="tel" id="phone" name="phone" placeholder=" "
                                   value="<?= htmlspecialchars($teacher['phone'] ?? '') ?>"
                                   maxlength="13" required>
                            <label for="phone">Phone number</label>
                        </div>
                        <div class="field-hint">09XXXXXXXXX — 11 digits</div>
                    </div>

                    <div class="field span2">
                        <div class="mfield">
                            <input type="text" id="address" name="address" placeholder=" "
                                   value="<?= htmlspecialchars($teacher['address'] ?? '') ?>" required>
                            <label for="address">Address</label>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Professional -->
            <div class="section" id="s-professional">
                <div class="section-head">
                    <div class="section-title">Professional information</div>
                    <div class="section-desc">Your academic background and area of expertise.</div>
                </div>
                <div class="field-grid">

                    <div class="field">
                        <div class="mfield">
                            <input type="text" id="education" name="education" placeholder=" "
                                   value="<?= htmlspecialchars($teacher['education'] ?? '') ?>" required>
                            <label for="education">Education</label>
                        </div>
                        <div class="field-hint">e.g. BS in Education</div>
                    </div>

                    <div class="field">
                        <div class="mfield">
                            <input type="text" id="specialization" name="specialization" placeholder=" "
                                   value="<?= htmlspecialchars($teacher['specialization'] ?? '') ?>" required>
                            <label for="specialization">Specialization</label>
                        </div>
                        <div class="field-hint">e.g. Mathematics, Science</div>
                    </div>

                    <div class="field span2">
                        <div class="mfield ta-wrap">
                            <textarea id="bio" name="bio" placeholder=" " maxlength="500"><?= htmlspecialchars($teacher['bio'] ?? '') ?></textarea>
                            <label for="bio">Bio (optional)</label>
                        </div>
                        <div class="char-count"><span id="bioCount">0</span> / 500</div>
                    </div>

                </div>
            </div>

            <!-- Theme -->
            <div class="section" id="s-theme">
                <div class="section-head">
                    <div class="section-title">Theme color</div>
                    <div class="section-desc">Choose the accent color used across your dashboard.</div>
                </div>

                <div class="swatch-row" id="swatchRow">
                    <?php foreach ($colorPresets as $label => $hex): ?>
                    <div class="swatch <?= strtolower($currentTheme)===strtolower($hex)?'active':'' ?>"
                         data-color="<?= htmlspecialchars($hex) ?>"
                         style="background:<?= htmlspecialchars($hex) ?>;color:<?= htmlspecialchars($hex) ?>;"
                         title="<?= htmlspecialchars($label) ?>"
                         onclick="selectColor('<?= htmlspecialchars($hex) ?>')"></div>
                    <?php endforeach; ?>
                </div>

                <div class="custom-row">
                    <input type="color" class="native-picker" id="nativePicker"
                           value="<?= htmlspecialchars($currentTheme) ?>"
                           oninput="onNative(this.value)" title="Custom color">
                    <div class="hex-field">
                        <input type="text" id="hexIn"
                               value="<?= htmlspecialchars(strtoupper($currentTheme)) ?>"
                               placeholder="#2563EB" maxlength="7"
                               oninput="onHex(this.value)" spellcheck="false">
                    </div>
                    <div class="preview-chip">
                        <div class="preview-dot" id="previewDot"></div>
                        <span id="previewHex"><?= htmlspecialchars(strtoupper($currentTheme)) ?></span>
                    </div>
                </div>

                <input type="hidden" name="theme_color" id="themeHidden" value="<?= htmlspecialchars($currentTheme) ?>">
            </div>

            <!-- Actions -->
            <div class="form-actions">
                <a href="teacher_dashb.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-fill" id="saveBtn">Save changes</button>
            </div>

        </form>
    </main>
</div>

<script>
// Bio counter
const bio = document.getElementById('bio');
const cnt = document.getElementById('bioCount');
const syncBio = () => cnt.textContent = bio.value.length;
syncBio(); bio.addEventListener('input', syncBio);

// Photo preview
function previewPhoto(e) {
    const file = e.target.files[0]; if (!file) return;
    const r = new FileReader();
    r.onload = ev => {
        const wrap = document.querySelector('.avatar-wrap');
        document.getElementById('avatarEl')?.remove();
        const img = Object.assign(document.createElement('img'), { className:'avatar-img', id:'avatarEl', alt:'Profile', src:ev.target.result });
        wrap.insertBefore(img, wrap.querySelector('.avatar-overlay'));
        document.getElementById('removeBtn').style.display = 'inline-flex';
        document.getElementById('removeFlag').value = '0';
    };
    r.readAsDataURL(file);
}

function removePhoto() {
    if (!confirm('Remove your profile photo?')) return;
    const wrap = document.querySelector('.avatar-wrap');
    document.getElementById('avatarEl')?.remove();
    const d = Object.assign(document.createElement('div'), { className:'avatar-initials', id:'avatarEl', textContent:'<?= htmlspecialchars($initials) ?>' });
    d.style.background = document.getElementById('themeHidden').value;
    wrap.insertBefore(d, wrap.querySelector('.avatar-overlay'));
    document.getElementById('filePick').value = '';
    document.getElementById('removeFlag').value = '1';
    document.getElementById('removeBtn').style.display = 'none';
}

// Nav click + smooth scroll
function navClick(el, id) {
    document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(id)?.scrollIntoView({ behavior:'smooth', block:'start' });
}

// Scrollspy
const spy = new IntersectionObserver(entries => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            const id = e.target.id;
            document.querySelectorAll('.nav-link').forEach(n => {
                n.classList.toggle('active', n.dataset.target === id);
            });
        }
    });
}, { rootMargin:'-25% 0px -65% 0px' });
document.querySelectorAll('.section').forEach(s => spy.observe(s));

// Color helpers
const h2r = h => [parseInt(h.slice(1,3),16), parseInt(h.slice(3,5),16), parseInt(h.slice(5,7),16)];

function applyColor(hex) {
    hex = hex.toLowerCase();
    const root = document.documentElement;
    root.style.setProperty('--primary', hex);
    const [R,G,B] = h2r(hex);
    const faint = `rgb(${Math.round(R+(.9*(255-R)))},${Math.round(G+(.9*(255-G)))},${Math.round(B+(.9*(255-B)))})`;
    root.style.setProperty('--primary-faint', faint);

    document.getElementById('previewDot').style.background = hex;
    document.getElementById('previewHex').textContent = hex.toUpperCase();
    document.getElementById('themeHidden').value = hex;
    document.getElementById('nativePicker').value = hex;
    if (document.activeElement !== document.getElementById('hexIn'))
        document.getElementById('hexIn').value = hex.toUpperCase();

    document.querySelectorAll('.swatch').forEach(s => {
        const match = s.dataset.color.toLowerCase() === hex;
        s.classList.toggle('active', match);
        s.style.color = s.dataset.color;
    });

    // Live update initials avatar bg
    const av = document.getElementById('avatarEl');
    if (av && av.classList.contains('avatar-initials')) av.style.background = hex;
}

function selectColor(h) { applyColor(h); }
function onNative(h)     { applyColor(h); document.getElementById('hexIn').value = h.toUpperCase(); }
function onHex(v) {
    v = v.trim(); if (!v.startsWith('#')) v = '#'+v;
    if (/^#[0-9A-Fa-f]{6}$/.test(v)) applyColor(v);
}

applyColor('<?= htmlspecialchars($currentTheme) ?>');

// Validation
document.getElementById('editForm').addEventListener('submit', function(e) {
    const req = ['fullname','birthdate','gender','phone','address','education','specialization'];
    for (const id of req) {
        const f = document.getElementById(id);
        if (!f?.value.trim()) { f?.focus(); e.preventDefault(); return; }
    }
    const bd = new Date(document.getElementById('birthdate').value);
    const today = new Date();
    let age = today.getFullYear() - bd.getFullYear();
    if (today.getMonth()-bd.getMonth() < 0 || (today.getMonth()===bd.getMonth() && today.getDate()<bd.getDate())) age--;
    if (age < 19) { alert('You must be at least 19 years old.'); document.getElementById('birthdate').focus(); e.preventDefault(); return; }
    if (document.getElementById('phone').value.replace(/\D/g,'').length !== 11) {
        alert('Phone number must be exactly 11 digits.'); document.getElementById('phone').focus(); e.preventDefault(); return;
    }
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
});
</script>
</body>
</html>