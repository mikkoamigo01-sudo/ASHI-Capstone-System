<?php
session_start();

require_once '../db_connection/ashi_dbcnnt.php';

$teacher_id = $_SESSION['teacher_id'] ?? 1;

$teacherStmt = $conn->prepare("SELECT id, fullname, advisory_grade, advisory_section, theme_color FROM teachers WHERE id = ?");
$teacherStmt->bind_param("i", $teacher_id);
$teacherStmt->execute();
$teacher = $teacherStmt->get_result()->fetch_assoc();
$teacherStmt->close();
if (!$teacher) die("Unauthorized");

$advisory_grade = $teacher['advisory_grade'];
$theme_color    = $teacher['theme_color'] ?? '#2563eb';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'get_subjects') {
        $stmt = $conn->prepare("SELECT id, subject_name, class_code, grade, section FROM subjects WHERE teacher_id = ? AND status = 'active' ORDER BY subject_name");
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        exit;
    }

    if ($action === 'get_sections') {
        $stmt = $conn->prepare("SELECT id, section_name FROM sections WHERE grade_level = ? AND status = 'active' ORDER BY section_name");
        $stmt->bind_param("s", $advisory_grade);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        exit;
    }

    if ($action === 'get_left_students') {
        $subject_id   = (int)$_POST['subject_id'];
        $section_name = trim($_POST['section'] ?? '');
        $search       = '%' . trim($_POST['search'] ?? '') . '%';
        if (!$section_name) { echo json_encode([]); exit; }
        $stmt = $conn->prepare("
            SELECT s.id, s.lrn, s.first_name, s.last_name, s.email, s.profile_picture, s.section
            FROM students s
            WHERE s.grade_level = ? AND s.section = ? AND s.status = 'active'
              AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.email LIKE ?)
              AND s.id NOT IN (SELECT student_id FROM student_subjects WHERE subject_id = ? AND status IN ('active','completed'))
            ORDER BY s.last_name, s.first_name
        ");
        $stmt->bind_param("ssssssi", $advisory_grade, $section_name, $search, $search, $search, $search, $subject_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        exit;
    }

    if ($action === 'get_right_students') {
        $subject_id   = (int)$_POST['subject_id'];
        $section_name = trim($_POST['section'] ?? '');
        $search       = '%' . trim($_POST['search'] ?? '') . '%';

        if ($section_name) {
            $stmt = $conn->prepare("
                SELECT s.id, s.lrn, s.first_name, s.last_name, s.email,
                       s.profile_picture, s.section, ss.enrollment_date, ss.id AS enrollment_id
                FROM students s
                JOIN student_subjects ss ON ss.student_id = s.id
                WHERE ss.subject_id = ? AND ss.status = 'active'
                  AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.email LIKE ?)
                  AND s.section = ?
                ORDER BY s.section, s.last_name, s.first_name
            ");
            $stmt->bind_param("isssss", $subject_id, $search, $search, $search, $search, $section_name);
        } else {
            $stmt = $conn->prepare("
                SELECT s.id, s.lrn, s.first_name, s.last_name, s.email,
                       s.profile_picture, s.section, ss.enrollment_date, ss.id AS enrollment_id
                FROM students s
                JOIN student_subjects ss ON ss.student_id = s.id
                WHERE ss.subject_id = ? AND ss.status = 'active'
                  AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.email LIKE ?)
                ORDER BY s.section, s.last_name, s.first_name
            ");
            $stmt->bind_param("issss", $subject_id, $search, $search, $search, $search);
        }
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        exit;
    }

    if ($action === 'enroll') {
        $subject_id  = (int)$_POST['subject_id'];
        $student_ids = json_decode($_POST['student_ids'], true);
        if (!is_array($student_ids) || empty($student_ids)) {
            echo json_encode(['success'=>false,'message'=>'No students selected.']); exit;
        }
        $stmt = $conn->prepare("INSERT INTO student_subjects (student_id, subject_id, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active'");
        $enrolled = 0;
        foreach ($student_ids as $sid) {
            $sid = (int)$sid;
            $stmt->bind_param("ii", $sid, $subject_id);
            $stmt->execute();
            $enrolled++;
        }
        $stmt->close();
        echo json_encode(['success'=>true,'message'=>"$enrolled student(s) enrolled successfully."]);
        exit;
    }

    if ($action === 'drop') {
        $subject_id = (int)$_POST['subject_id'];
        $student_id = (int)$_POST['student_id'];
        $stmt = $conn->prepare("UPDATE student_subjects SET status = 'dropped' WHERE student_id = ? AND subject_id = ?");
        $stmt->bind_param("ii", $student_id, $subject_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success'=>true,'message'=>'Student removed from subject.']);
        exit;
    }

    if ($action === 'get_student') {
        $student_id = (int)$_POST['student_id'];
        $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        unset($data['password'], $data['plain_password']);
        echo json_encode($data);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="img/ashi.jpg" type="image/x-icon">
<title>Enroll Students — Ashi Amaya</title>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --primary:       <?= htmlspecialchars($theme_color) ?>;
    --primary-faint: color-mix(in srgb, <?= htmlspecialchars($theme_color) ?> 10%, #ffffff);
    --primary-mid:   color-mix(in srgb, <?= htmlspecialchars($theme_color) ?> 22%, #ffffff);
    --bg:            #f8f9fa;
    --surface:       #ffffff;
    --divider:       #e8eaed;
    --text-1:        #202124;
    --text-2:        #5f6368;
    --text-3:        #9aa0a6;
    --hover:         #f1f3f4;
    --error:         #d93025;
    --error-bg:      #fce8e6;
    --success:       #1e8e3e;
    --success-bg:    #e6f4ea;
    --topbar-h:      64px;
    --ease:          cubic-bezier(0.4,0,0.2,1);
}

html, body {
    font-family: 'Roboto', sans-serif;
    font-size: 14px;
    color: var(--text-1);
    min-height: 100vh;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
}

/* ══════════════════════════════════════════
   ANIMATED LETTER BACKGROUND
══════════════════════════════════════════ */
.bg-canvas {
    position: fixed;
    inset: 0;
    z-index: 0;
    background: var(--bg);
    overflow: hidden;
    pointer-events: none;
}

.bg-canvas svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.bg-letter {
    font-family: 'Google Sans', sans-serif;
    font-weight: 700;
    fill: var(--primary);
    opacity: 0;
    animation: letterFloat linear infinite;
    transform-origin: center;
}

@keyframes letterFloat {
    0%   { opacity: 0;    transform: translateY(0px)    rotate(var(--r)); }
    8%   { opacity: 0.045; }
    85%  { opacity: 0.045; }
    100% { opacity: 0;    transform: translateY(-120px) rotate(calc(var(--r) + 15deg)); }
}

/* ══════════════════════════════════════════
   TOP BAR
══════════════════════════════════════════ */
.topbar {
    position: fixed;
    inset: 0 0 auto 0;
    height: var(--topbar-h);
    background: var(--surface);
    border-bottom: 1px solid var(--divider);
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 24px;
    z-index: 300;
}

.topbar-back {
    width: 40px; height: 40px;
    border-radius: 50%;
    border: none; background: transparent;
    display: grid; place-items: center;
    cursor: pointer; color: var(--text-2);
    font-size: 16px;
    transition: background .15s var(--ease);
    text-decoration: none; flex-shrink: 0;
}
.topbar-back:hover { background: var(--hover); }

.topbar-title {
    font-family: 'Google Sans', sans-serif;
    font-size: 20px; font-weight: 400;
    color: var(--text-1);
}

.topbar-right {
    margin-left: auto;
    display: flex; align-items: center; gap: 10px;
}

.adviser-pill {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--primary-faint);
    border-radius: 100px;
    padding: 6px 14px;
    font-size: 12px; font-weight: 500;
    color: var(--primary);
}
.adviser-pill .dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--success);
    flex-shrink: 0;
}

/* ══════════════════════════════════════════
   PAGE LAYOUT
══════════════════════════════════════════ */
.page {
    position: relative;
    z-index: 1;
    padding-top: var(--topbar-h);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.page-inner {
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
    padding: 32px 24px 64px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    flex: 1;
}

/* ══════════════════════════════════════════
   SUBJECT SELECTOR SECTION
══════════════════════════════════════════ */
.subject-bar {
    background: var(--surface);
    border: 1px solid var(--divider);
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.subject-bar-label {
    font-family: 'Google Sans', sans-serif;
    font-size: 13px; font-weight: 500;
    color: var(--text-2);
    white-space: nowrap;
}

/* Material select */
.mselect-wrap {
    position: relative;
    background: var(--hover);
    border-radius: 4px 4px 0 0;
    border-bottom: 1px solid #bdc1c6;
    transition: border-color .18s var(--ease);
    flex: 1; min-width: 260px; max-width: 400px;
}
.mselect-wrap:focus-within {
    border-bottom: 2px solid var(--primary);
    background: color-mix(in srgb, var(--primary) 5%, var(--hover));
}
.mselect-wrap select {
    width: 100%;
    padding: 11px 36px 11px 12px;
    border: none; background: transparent;
    font-family: 'Roboto', sans-serif;
    font-size: 14px; color: var(--text-1);
    outline: none; appearance: none; cursor: pointer;
}
.mselect-wrap::after {
    content: '';
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    width: 0; height: 0;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    border-top: 5px solid var(--text-3);
    pointer-events: none;
}

.class-code-chip {
    display: none;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: var(--primary-faint);
    color: var(--primary);
    border-radius: 100px;
    font-size: 12px; font-weight: 600;
    letter-spacing: .5px;
}

/* ══════════════════════════════════════════
   DUAL-PANEL AREA
══════════════════════════════════════════ */
.panels-wrap {
    display: grid;
    grid-template-columns: 1fr 48px 1fr;
    gap: 0;
    flex: 1;
    min-height: 560px;
}

/* Center arrow column */
.panel-divider {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 0 4px;
}
.panel-divider-line {
    flex: 1; width: 1px;
    background: var(--divider);
}
.panel-divider-icon {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: var(--surface);
    border: 1px solid var(--divider);
    display: grid; place-items: center;
    color: var(--text-3);
    font-size: 13px;
}

/* ══════════════════════════════════════════
   PANEL
══════════════════════════════════════════ */
.panel {
    background: var(--surface);
    border: 1px solid var(--divider);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 560px;
}

/* Panel top strip — title row */
.panel-top {
    padding: 16px 20px 12px;
    border-bottom: 1px solid var(--divider);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}

.panel-title {
    font-family: 'Google Sans', sans-serif;
    font-size: 14px; font-weight: 500;
    color: var(--text-1);
    display: flex; align-items: center; gap: 8px;
}
.panel-title i { font-size: 14px; color: var(--primary); }

.count-pill {
    display: inline-flex; align-items: center;
    padding: 2px 10px;
    background: var(--hover);
    border-radius: 100px;
    font-size: 12px; color: var(--text-2);
}

/* Panel filter row */
.panel-filters {
    padding: 10px 16px;
    border-bottom: 1px solid var(--divider);
    background: color-mix(in srgb, var(--bg) 60%, white);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-label {
    font-size: 11px; font-weight: 500;
    color: var(--text-3); white-space: nowrap;
    text-transform: uppercase; letter-spacing: .05em;
}

.grade-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: var(--primary-faint);
    color: var(--primary);
    border-radius: 4px;
    padding: 5px 10px;
    font-size: 12px; font-weight: 500;
    white-space: nowrap; flex-shrink: 0;
}

/* Inline material select (compact) */
.sel-inline {
    flex: 1; min-width: 120px;
    background: var(--hover);
    border: none;
    border-bottom: 1px solid #bdc1c6;
    border-radius: 4px 4px 0 0;
    padding: 8px 28px 8px 10px;
    font-family: 'Roboto', sans-serif;
    font-size: 13px; color: var(--text-1);
    appearance: none; cursor: pointer; outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%239aa0a6' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    transition: border-color .15s;
}
.sel-inline:focus { border-bottom: 2px solid var(--primary); background-color: color-mix(in srgb, var(--primary) 5%, var(--hover)); }
.sel-inline:disabled { opacity: .45; cursor: not-allowed; }

/* Search row */
.panel-search {
    padding: 10px 16px;
    border-bottom: 1px solid var(--divider);
}
.search-field {
    position: relative;
}
.search-field i {
    position: absolute; left: 10px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-3); font-size: 13px;
    pointer-events: none;
}
.search-field input {
    width: 100%;
    background: var(--hover);
    border: none;
    border-bottom: 1px solid #bdc1c6;
    border-radius: 4px 4px 0 0;
    padding: 9px 12px 9px 32px;
    font-family: 'Roboto', sans-serif;
    font-size: 13px; color: var(--text-1);
    outline: none;
    transition: border-color .15s, background .15s;
}
.search-field input::placeholder { color: var(--text-3); }
.search-field input:focus {
    border-bottom: 2px solid var(--primary);
    background: color-mix(in srgb, var(--primary) 5%, var(--hover));
}
.search-field input:disabled { opacity: .45; cursor: not-allowed; }

/* Actions row */
.panel-actions {
    padding: 8px 16px;
    border-bottom: 1px solid var(--divider);
    background: color-mix(in srgb, var(--bg) 40%, white);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.select-all-row {
    display: flex; align-items: center; gap: 8px;
    cursor: pointer; user-select: none;
}
.select-all-row input[type=checkbox] {
    accent-color: var(--primary); cursor: pointer; width: 15px; height: 15px;
}
.select-all-row span { font-size: 13px; color: var(--text-2); }

/* ══════════════════════════════════════════
   BUTTONS
══════════════════════════════════════════ */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 34px; padding: 0 16px;
    border-radius: 4px;
    font-family: 'Google Sans', sans-serif;
    font-size: 13px; font-weight: 500;
    cursor: pointer; border: none;
    transition: background .15s var(--ease), box-shadow .15s var(--ease), filter .15s;
    white-space: nowrap; text-decoration: none;
}
.btn i { font-size: 12px; }
.btn:disabled { opacity: .4; cursor: not-allowed; filter: none !important; box-shadow: none !important; }

.btn-fill   { background: var(--primary); color: #fff; }
.btn-fill:hover:not(:disabled) { filter: brightness(1.1); box-shadow: 0 1px 4px rgba(0,0,0,.2); }

.btn-outline {
    background: transparent; color: var(--primary);
    border: 1px solid color-mix(in srgb, var(--primary) 40%, transparent);
}
.btn-outline:hover:not(:disabled) { background: var(--primary-faint); }

.btn-ghost {
    background: transparent; color: var(--text-2);
    border: 1px solid var(--divider);
}
.btn-ghost:hover:not(:disabled) { background: var(--hover); color: var(--text-1); }

.btn-danger-text {
    background: transparent; color: var(--error);
    border: none; padding: 0 10px;
}
.btn-danger-text:hover:not(:disabled) { background: var(--error-bg); }

/* ══════════════════════════════════════════
   STUDENT LIST
══════════════════════════════════════════ */
.student-list {
    flex: 1; overflow-y: auto;
}
.student-list::-webkit-scrollbar { width: 4px; }
.student-list::-webkit-scrollbar-thumb { background: var(--divider); border-radius: 4px; }

.s-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--divider);
    cursor: pointer;
    transition: background .12s var(--ease);
}
.s-row:hover { background: var(--hover); }
.s-row.selected { background: var(--primary-faint); }
.s-row:last-child { border-bottom: none; }

.s-row input[type=checkbox] {
    accent-color: var(--primary);
    cursor: pointer; flex-shrink: 0;
    width: 15px; height: 15px;
}

.s-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--primary);
    display: grid; place-items: center;
    font-family: 'Google Sans', sans-serif;
    font-size: 13px; font-weight: 500; color: #fff;
    flex-shrink: 0; overflow: hidden;
}
.s-avatar img { width: 100%; height: 100%; object-fit: cover; }

.s-info { flex: 1; min-width: 0; }
.s-name {
    font-size: 13px; font-weight: 500; color: var(--text-1);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.s-meta {
    display: flex; align-items: center; gap: 6px;
    margin-top: 1px; flex-wrap: wrap;
}
.s-lrn   { font-size: 11px; color: var(--text-3); }
.s-email { font-size: 11px; color: var(--text-3); overflow: hidden; text-overflow: ellipsis; max-width: 150px; }

.section-chip {
    font-size: 10px; font-weight: 500;
    background: var(--primary-faint); color: var(--primary);
    border-radius: 100px; padding: 1px 8px;
    white-space: nowrap;
}

.enroll-date-chip {
    font-size: 11px; color: var(--text-3);
    background: var(--hover); border-radius: 100px;
    padding: 2px 9px; white-space: nowrap; flex-shrink: 0;
}

.s-actions { display: flex; align-items: center; gap: 2px; flex-shrink: 0; }

.icon-btn {
    width: 30px; height: 30px; border-radius: 50%;
    border: none; background: transparent; color: var(--text-2);
    display: grid; place-items: center; cursor: pointer; font-size: 13px;
    transition: background .12s, color .12s;
}
.icon-btn:hover         { background: var(--hover); color: var(--text-1); }
.icon-btn.remove:hover  { background: var(--error-bg); color: var(--error); }

/* ══════════════════════════════════════════
   EMPTY / PLACEHOLDER / SKELETON
══════════════════════════════════════════ */
.empty-state, .placeholder-state {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 10px; padding: 48px 24px;
    color: var(--text-3); text-align: center;
}
.empty-icon {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--hover);
    display: grid; place-items: center;
    font-size: 18px; color: var(--text-3);
    margin-bottom: 4px;
}
.empty-state p, .placeholder-state p {
    font-size: 13px; line-height: 1.6; max-width: 200px;
}

.skel-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; border-bottom: 1px solid var(--divider);
}
.skeleton {
    background: linear-gradient(90deg,#f0f0f0 25%,#e4e4e4 50%,#f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 4px;
}
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
.skel-av  { width:36px;height:36px;border-radius:50%;flex-shrink:0; }
.skel-txt { height:10px; }

/* ══════════════════════════════════════════
   MODAL
══════════════════════════════════════════ */
.overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.38);
    z-index: 1000;
    display: none; align-items: center; justify-content: center;
    padding: 20px;
    animation: fadeIn .18s var(--ease);
}
.overlay.open { display: flex; }
@keyframes fadeIn { from{opacity:0}to{opacity:1} }

.modal {
    background: var(--surface);
    border-radius: 12px;
    width: 100%; max-width: 460px;
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
    overflow: hidden;
    animation: scaleIn .2s var(--ease);
}
@keyframes scaleIn { from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1} }

.modal-head {
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--divider);
    display: flex; align-items: center; justify-content: space-between;
}
.modal-head h3 {
    font-family: 'Google Sans', sans-serif;
    font-size: 16px; font-weight: 500; color: var(--text-1);
}
.modal-close {
    width: 30px; height: 30px; border-radius: 50%;
    border: none; background: transparent; color: var(--text-2);
    display: grid; place-items: center; cursor: pointer; font-size: 14px;
    transition: background .12s;
}
.modal-close:hover { background: var(--hover); }

.modal-body { padding: 20px; }

.modal-student-header {
    display: flex; align-items: center; gap: 14px;
    padding-bottom: 16px; margin-bottom: 16px;
    border-bottom: 1px solid var(--divider);
}
.modal-avatar {
    width: 52px; height: 52px; border-radius: 50%;
    background: var(--primary);
    display: grid; place-items: center;
    font-family: 'Google Sans', sans-serif;
    font-size: 20px; font-weight: 500; color: #fff;
    flex-shrink: 0; overflow: hidden;
}
.modal-avatar img { width:100%;height:100%;object-fit:cover; }
.modal-s-name {
    font-family: 'Google Sans', sans-serif;
    font-size: 16px; font-weight: 500;
}
.modal-s-grade { font-size: 13px; color: var(--text-2); margin-top: 2px; }

.detail-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
}
.detail-item label {
    font-size: 11px; font-weight: 500; color: var(--text-3);
    text-transform: uppercase; letter-spacing: .05em;
    display: block; margin-bottom: 3px;
}
.detail-item span { font-size: 13px; color: var(--text-1); }
.detail-item.full { grid-column: 1/-1; }

/* Confirm modal */
.confirm-icon {
    width: 44px; height: 44px; border-radius: 50%;
    background: var(--error-bg);
    display: grid; place-items: center;
    margin: 0 auto 12px;
    color: var(--error); font-size: 18px;
}
.confirm-text {
    text-align: center; font-size: 13px;
    color: var(--text-2); line-height: 1.7; margin-bottom: 20px;
}
.confirm-text strong { color: var(--text-1); }
.confirm-actions { display: flex; gap: 8px; justify-content: flex-end; }

/* ══════════════════════════════════════════
   TOAST
══════════════════════════════════════════ */
.toast-wrap {
    position: fixed; bottom: 24px; left: 50%;
    transform: translateX(-50%); z-index: 9999;
    display: flex; flex-direction: column; gap: 8px;
    align-items: center; pointer-events: none;
}
.toast {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 20px; border-radius: 4px;
    background: #323232; color: #fff;
    font-size: 13px; pointer-events: all;
    box-shadow: 0 2px 8px rgba(0,0,0,.22);
    animation: toastIn .22s var(--ease), toastOut .3s var(--ease) 2.8s forwards;
}
.toast i { font-size: 14px; }
.toast.success i { color: #81c995; }
.toast.error   i { color: #f28b82; }
@keyframes toastIn  { from{transform:translateY(8px);opacity:0}to{transform:none;opacity:1} }
@keyframes toastOut { to{opacity:0;transform:translateY(6px)} }

/* ══════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════ */
@media (max-width: 860px) {
    .panels-wrap { grid-template-columns: 1fr; }
    .panel-divider { flex-direction: row; padding: 8px 0; }
    .panel-divider-line { flex: 1; width: auto; height: 1px; }
    .panel { min-height: 400px; }
}
@media (max-width: 560px) {
    .page-inner { padding: 20px 12px 48px; }
    .subject-bar { padding: 14px 16px; }
    .topbar { padding: 0 12px; }
}
</style>
</head>
<body>

<!-- ══ ANIMATED LETTER BACKGROUND ══ -->
<div class="bg-canvas">
<svg id="bgSvg" xmlns="http://www.w3.org/2000/svg"></svg>
</div>

<!-- ══ TOP BAR ══ -->
<header class="topbar">
    <a href="teacher_dashb.php" class="topbar-back" title="Back to dashboard">
        <i class="fas fa-arrow-left"></i>
    </a>
    <span class="topbar-title">Enroll Students</span>
    <div class="topbar-right">
        <?php if ($teacher['advisory_grade'] && $teacher['advisory_section']): ?>
        <div class="adviser-pill">
            <span class="dot"></span>
            Advisory: <?= htmlspecialchars($teacher['advisory_grade'] . ' — ' . $teacher['advisory_section']) ?>
        </div>
        <?php endif; ?>
    </div>
</header>

<div class="page">
<div class="page-inner">

    <!-- Subject selector -->
    <div class="subject-bar">
        <span class="subject-bar-label">Subject</span>
        <div class="mselect-wrap">
            <select id="subjectSelect">
                <option value="">— choose a subject —</option>
            </select>
        </div>
        <span class="class-code-chip" id="classCodeChip">
            <i class="fas fa-hashtag" style="font-size:10px;"></i>
            <span id="classCodeText"></span>
        </span>
    </div>

    <!-- Dual panels -->
    <div class="panels-wrap">

        <!-- LEFT PANEL: Available Students -->
        <div class="panel">
            <div class="panel-top">
                <div class="panel-title">
                    <i class="fas fa-users"></i>
                    Available Students
                    <span class="count-pill" id="leftCount">0</span>
                </div>
            </div>

            <div class="panel-filters">
                <span class="grade-badge">
                    <i class="fas fa-graduation-cap" style="font-size:10px;"></i>
                    <?= htmlspecialchars($advisory_grade) ?>
                </span>
                <span class="filter-label">Section</span>
                <select class="sel-inline" id="leftSection" disabled>
                    <option value="">— pick section —</option>
                </select>
            </div>

            <div class="panel-search">
                <div class="search-field">
                    <i class="fas fa-search"></i>
                    <input type="text" id="leftSearch" placeholder="Search name, LRN, email…" disabled>
                </div>
            </div>

            <div class="panel-actions">
                <label class="select-all-row">
                    <input type="checkbox" id="selectAll" disabled>
                    <span>Select all</span>
                </label>
                <button class="btn btn-fill" id="enrollSelectedBtn" disabled>
                    <i class="fas fa-user-plus"></i>
                    <span>Enroll Selected</span>
                </button>
            </div>

            <div class="student-list" id="leftPanel">
                <div class="placeholder-state">
                    <div class="empty-icon"><i class="fas fa-users"></i></div>
                    <p>Select a subject and section to view available students</p>
                </div>
            </div>
        </div>

        <!-- Divider -->
        <div class="panel-divider">
            <div class="panel-divider-line"></div>
            <div class="panel-divider-icon"><i class="fas fa-arrow-right"></i></div>
            <div class="panel-divider-line"></div>
        </div>

        <!-- RIGHT PANEL: Enrolled -->
        <div class="panel">
            <div class="panel-top">
                <div class="panel-title">
                    <i class="fas fa-check-circle"></i>
                    Enrolled
                    <span class="count-pill" id="rightCount">0</span>
                </div>
                <button class="btn btn-outline" id="enrollAllBtn" disabled>
                    <i class="fas fa-user-plus"></i> Enroll All
                </button>
            </div>

            <div class="panel-filters">
                <span class="filter-label">Filter by section</span>
                <select class="sel-inline" id="rightSection" disabled>
                    <option value="">All Sections</option>
                </select>
            </div>

            <div class="panel-search">
                <div class="search-field">
                    <i class="fas fa-search"></i>
                    <input type="text" id="rightSearch" placeholder="Search enrolled students…" disabled>
                </div>
            </div>

            <div class="panel-actions" style="justify-content:flex-end;">
                <span style="font-size:12px;color:var(--text-3);">Enrolled in this subject</span>
            </div>

            <div class="student-list" id="rightPanel">
                <div class="placeholder-state">
                    <div class="empty-icon"><i class="fas fa-check"></i></div>
                    <p>Enrolled students will appear here</p>
                </div>
            </div>
        </div>

    </div>
</div>
</div>

<!-- Student detail modal -->
<div class="overlay" id="detailOverlay">
    <div class="modal">
        <div class="modal-head">
            <h3>Student Details</h3>
            <button class="modal-close" onclick="closeModal('detailOverlay')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="detailBody">
            <p style="text-align:center;color:var(--text-3);padding:24px 0;">Loading…</p>
        </div>
    </div>
</div>

<!-- Confirm remove modal -->
<div class="overlay" id="confirmOverlay">
    <div class="modal">
        <div class="modal-head">
            <h3>Remove Student</h3>
            <button class="modal-close" onclick="closeModal('confirmOverlay')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="confirm-icon"><i class="fas fa-trash-alt"></i></div>
            <p class="confirm-text">
                Remove <strong id="confirmName">this student</strong> from the subject?
                They can be re-enrolled later.
            </p>
            <div class="confirm-actions">
                <button class="btn btn-ghost" onclick="closeModal('confirmOverlay')">Cancel</button>
                <button class="btn" style="background:var(--error);color:#fff;" id="confirmRemoveBtn">Remove</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
// ══════════════════════════════════════════
//  ANIMATED SVG LETTER BACKGROUND
// ══════════════════════════════════════════
(function() {
    const svg   = document.getElementById('bgSvg');
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'.split('');
    const count = 55;

    function rand(min, max) { return Math.random() * (max - min) + min; }

    for (let i = 0; i < count; i++) {
        const text  = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        const char  = chars[Math.floor(Math.random() * chars.length)];
        const size  = rand(18, 64);
        const cx    = rand(1, 99);
        const cy    = rand(5, 100);
        const dur   = rand(12, 26);
        const delay = rand(0, dur);
        const rot   = rand(-30, 30);

        text.textContent = char;
        text.setAttribute('class', 'bg-letter');
        text.setAttribute('font-size', size);
        text.setAttribute('x', cx + '%');
        text.setAttribute('y', cy + '%');
        text.setAttribute('text-anchor', 'middle');
        text.style.setProperty('--r', rot + 'deg');
        text.style.animationDuration = dur + 's';
        text.style.animationDelay    = '-' + delay + 's';

        svg.appendChild(text);
    }
})();

// ══════════════════════════════════════════
//  STATE
// ══════════════════════════════════════════
const state = {
    subjectId:    null,
    leftSection:  null,
    leftStudents: [],
    rightStudents:[],
    selected:     new Set(),
    pendingDrop:  null,
};

// ══════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    loadSubjects();

    document.getElementById('subjectSelect').addEventListener('change', onSubjectChange);
    document.getElementById('leftSection').addEventListener('change', onLeftSectionChange);
    document.getElementById('rightSection').addEventListener('change', () => loadRight());
    document.getElementById('leftSearch').addEventListener('input', debounce(() => loadLeft(), 280));
    document.getElementById('rightSearch').addEventListener('input', debounce(() => loadRight(), 280));
    document.getElementById('selectAll').addEventListener('change', onSelectAll);
    document.getElementById('enrollSelectedBtn').addEventListener('click', enrollSelected);
    document.getElementById('enrollAllBtn').addEventListener('click', enrollAll);
    document.getElementById('confirmRemoveBtn').addEventListener('click', doRemove);

    // Close modals on backdrop click
    document.querySelectorAll('.overlay').forEach(o =>
        o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); })
    );
});

// ══════════════════════════════════════════
//  SUBJECTS
// ══════════════════════════════════════════
async function loadSubjects() {
    const data = await post({ action: 'get_subjects' });
    const sel  = document.getElementById('subjectSelect');
    if (!data.length) { sel.innerHTML = '<option value="">No subjects found</option>'; return; }
    data.forEach(s => {
        const o = document.createElement('option');
        o.value = s.id; o.textContent = s.subject_name;
        o.dataset.code = s.class_code;
        sel.appendChild(o);
    });
}

// ══════════════════════════════════════════
//  SECTIONS
// ══════════════════════════════════════════
async function loadSections() {
    const data = await post({ action: 'get_sections' });
    const lSel = document.getElementById('leftSection');
    const rSel = document.getElementById('rightSection');

    lSel.innerHTML = '<option value="">— pick section —</option>';
    rSel.innerHTML = '<option value="">All Sections</option>';
    data.forEach(s => {
        [lSel, rSel].forEach(el => {
            const o = document.createElement('option');
            o.value = s.section_name; o.textContent = s.section_name;
            el.appendChild(o);
        });
    });
    lSel.disabled = false;
    rSel.disabled = false;
}

// ══════════════════════════════════════════
//  SUBJECT CHANGE
// ══════════════════════════════════════════
async function onSubjectChange() {
    const sel = document.getElementById('subjectSelect');
    const opt = sel.options[sel.selectedIndex];
    state.subjectId = sel.value || null;
    state.selected.clear();
    updateEnrollBtn();

    const chip = document.getElementById('classCodeChip');
    if (state.subjectId) {
        document.getElementById('classCodeText').textContent = opt.dataset.code;
        chip.style.display = 'inline-flex';

        await loadSections();
        enableControls(true);

        document.getElementById('leftSection').value  = '';
        document.getElementById('leftSearch').value   = '';
        document.getElementById('rightSearch').value  = '';
        document.getElementById('rightSection').value = '';

        setPlaceholder('leftPanel',  '<i class="fas fa-users"></i>', 'Pick a section to see available students');
        setCount('leftCount', 0);
        loadRight();
    } else {
        chip.style.display = 'none';
        enableControls(false);
        resetSections();
        setPlaceholder('leftPanel',  '<i class="fas fa-users"></i>', 'Select a subject and section to view students');
        setPlaceholder('rightPanel', '<i class="fas fa-check"></i>', 'Enrolled students will appear here');
        setCount('leftCount', 0);
        setCount('rightCount', 0);
    }
}

function enableControls(on) {
    ['leftSearch','rightSearch'].forEach(id => document.getElementById(id).disabled = !on);
    document.getElementById('selectAll').disabled     = !on;
    document.getElementById('enrollAllBtn').disabled  = !on;
}

function resetSections() {
    ['leftSection','rightSection'].forEach(id => {
        const el = document.getElementById(id);
        el.innerHTML = id === 'leftSection' ? '<option value="">— pick section —</option>' : '<option value="">All Sections</option>';
        el.disabled = true;
    });
}

// ══════════════════════════════════════════
//  LEFT SECTION CHANGE
// ══════════════════════════════════════════
function onLeftSectionChange() {
    state.leftSection = document.getElementById('leftSection').value || null;
    state.selected.clear();
    document.getElementById('selectAll').checked = false;
    document.getElementById('leftSearch').value  = '';
    updateEnrollBtn();

    if (state.leftSection) loadLeft();
    else {
        setPlaceholder('leftPanel', '<i class="fas fa-users"></i>', 'Pick a section to see available students');
        setCount('leftCount', 0);
    }
}

// ══════════════════════════════════════════
//  LOAD LEFT
// ══════════════════════════════════════════
async function loadLeft() {
    if (!state.subjectId || !state.leftSection) return;
    renderSkeletons('leftPanel');
    const data = await post({
        action: 'get_left_students',
        subject_id: state.subjectId,
        section: state.leftSection,
        search: document.getElementById('leftSearch').value
    });
    state.leftStudents = data;
    state.selected.clear();
    document.getElementById('selectAll').checked = false;
    setCount('leftCount', data.length);
    updateEnrollBtn();
    renderLeft();
}

// ══════════════════════════════════════════
//  LOAD RIGHT
// ══════════════════════════════════════════
async function loadRight() {
    if (!state.subjectId) return;
    renderSkeletons('rightPanel');
    const data = await post({
        action: 'get_right_students',
        subject_id: state.subjectId,
        section: document.getElementById('rightSection').value,
        search: document.getElementById('rightSearch').value
    });
    state.rightStudents = data;
    setCount('rightCount', data.length);
    renderRight();
}

// ══════════════════════════════════════════
//  RENDER LEFT
// ══════════════════════════════════════════
function renderLeft() {
    const panel = document.getElementById('leftPanel');
    if (!state.leftStudents.length) {
        panel.innerHTML = emptyHTML('<i class="fas fa-user-slash"></i>', 'No available students in this section');
        return;
    }
    panel.innerHTML = state.leftStudents.map(s => `
        <div class="s-row ${state.selected.has(s.id) ? 'selected' : ''}" onclick="toggleRow(${s.id}, event)">
            <input type="checkbox" data-id="${s.id}" ${state.selected.has(s.id) ? 'checked' : ''} onclick="event.stopPropagation()">
            ${avatarEl(s)}
            <div class="s-info">
                <div class="s-name">${x(s.last_name)}, ${x(s.first_name)}</div>
                <div class="s-meta">
                    <span class="s-lrn">${x(s.lrn||'—')}</span>
                    <span class="s-email">${x(s.email)}</span>
                </div>
            </div>
            <div class="s-actions">
                <button class="icon-btn" title="View details" onclick="event.stopPropagation();viewStudent(${s.id})">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>
    `).join('');
}

// ══════════════════════════════════════════
//  RENDER RIGHT
// ══════════════════════════════════════════
function renderRight() {
    const panel = document.getElementById('rightPanel');
    if (!state.rightStudents.length) {
        panel.innerHTML = emptyHTML('<i class="fas fa-inbox"></i>', 'No students enrolled yet');
        return;
    }
    panel.innerHTML = state.rightStudents.map(s => `
        <div class="s-row">
            ${avatarEl(s)}
            <div class="s-info">
                <div class="s-name">${x(s.last_name)}, ${x(s.first_name)}</div>
                <div class="s-meta">
                    <span class="s-lrn">${x(s.lrn||'—')}</span>
                    <span class="s-email">${x(s.email)}</span>
                    <span class="section-chip">${x(s.section)}</span>
                </div>
            </div>
            <div class="s-actions">
                <span class="enroll-date-chip">${fmtDate(s.enrollment_date)}</span>
                <button class="icon-btn" title="View" onclick="viewStudent(${s.id})">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="icon-btn remove" title="Remove" onclick="promptRemove(${s.id},'${x(s.first_name+' '+s.last_name)}')">
                    <i class="fas fa-user-minus"></i>
                </button>
            </div>
        </div>
    `).join('');
}

// ══════════════════════════════════════════
//  SELECTION
// ══════════════════════════════════════════
function toggleRow(id, e) {
    const tag = e.target.tagName;
    if (['BUTTON','I','INPUT'].includes(tag)) return;
    state.selected.has(id) ? state.selected.delete(id) : state.selected.add(id);
    updateEnrollBtn();
    renderLeft();
}

function onSelectAll() {
    const chk = document.getElementById('selectAll').checked;
    if (chk) state.leftStudents.forEach(s => state.selected.add(s.id));
    else state.selected.clear();
    updateEnrollBtn();
    renderLeft();
}

function updateEnrollBtn() {
    const c   = state.selected.size;
    const btn = document.getElementById('enrollSelectedBtn');
    btn.disabled = c === 0 || !state.subjectId || !state.leftSection;
    btn.innerHTML = `<i class="fas fa-user-plus"></i> ${c > 0 ? 'Enroll (' + c + ')' : 'Enroll Selected'}`;
}

// ══════════════════════════════════════════
//  ENROLL
// ══════════════════════════════════════════
async function enrollSelected() {
    if (!state.selected.size) return;
    const res = await post({ action:'enroll', subject_id:state.subjectId, student_ids:JSON.stringify([...state.selected]) });
    if (res.success) {
        toast(res.message, 'success');
        state.selected.clear();
        document.getElementById('selectAll').checked = false;
        await Promise.all([loadLeft(), loadRight()]);
    } else { toast(res.message||'Enrollment failed.','error'); }
}

async function enrollAll() {
    if (!state.subjectId) return;
    const ids = state.leftStudents.map(s => s.id);
    if (!ids.length) { toast('No students available.','error'); return; }
    const res = await post({ action:'enroll', subject_id:state.subjectId, student_ids:JSON.stringify(ids) });
    if (res.success) {
        toast(res.message,'success');
        state.selected.clear();
        document.getElementById('selectAll').checked = false;
        await Promise.all([loadLeft(), loadRight()]);
    } else { toast(res.message||'Enrollment failed.','error'); }
}

// ══════════════════════════════════════════
//  REMOVE / DROP
// ══════════════════════════════════════════
function promptRemove(id, name) {
    state.pendingDrop = id;
    document.getElementById('confirmName').textContent = name;
    openModal('confirmOverlay');
}

async function doRemove() {
    if (!state.pendingDrop) return;
    const res = await post({ action:'drop', subject_id:state.subjectId, student_id:state.pendingDrop });
    closeModal('confirmOverlay');
    if (res.success) {
        toast('Student removed.','success');
        await Promise.all([loadLeft(), loadRight()]);
    } else { toast('Failed to remove student.','error'); }
    state.pendingDrop = null;
}

// ══════════════════════════════════════════
//  STUDENT DETAIL
// ══════════════════════════════════════════
async function viewStudent(id) {
    openModal('detailOverlay');
    document.getElementById('detailBody').innerHTML = '<p style="text-align:center;color:var(--text-3);padding:24px 0;">Loading…</p>';
    const s = await post({ action:'get_student', student_id:id });
    if (!s || s.error) { document.getElementById('detailBody').innerHTML = '<p style="color:var(--error);text-align:center">Failed to load.</p>'; return; }
    const ini = (s.first_name?.[0]||'')+(s.last_name?.[0]||'');
    document.getElementById('detailBody').innerHTML = `
        <div class="modal-student-header">
            <div class="modal-avatar">${s.profile_picture?`<img src="${x(s.profile_picture)}" alt="">`:ini}</div>
            <div>
                <div class="modal-s-name">${x(s.first_name)} ${x(s.middle_name||'')} ${x(s.last_name)}</div>
                <div class="modal-s-grade">${x(s.grade_level)} — ${x(s.section)}</div>
            </div>
        </div>
        <div class="detail-grid">
            <div class="detail-item"><label>LRN</label><span>${x(s.lrn||'—')}</span></div>
            <div class="detail-item"><label>Username</label><span>${x(s.username||'—')}</span></div>
            <div class="detail-item full"><label>Email</label><span>${x(s.email||'—')}</span></div>
            <div class="detail-item"><label>Birthdate</label><span>${x(s.birthdate||'—')}</span></div>
            <div class="detail-item"><label>Contact</label><span>${x(s.contact_number||'—')}</span></div>
            <div class="detail-item"><label>Guardian</label><span>${x(s.guardian_name||'—')}</span></div>
            <div class="detail-item"><label>Relationship</label><span>${x(s.relationship||'—')}</span></div>
            <div class="detail-item full"><label>Guardian Contact</label><span>${x(s.guardian_contact||'—')}</span></div>
        </div>
    `;
}

// ══════════════════════════════════════════
//  MODALS
// ══════════════════════════════════════════
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ══════════════════════════════════════════
//  TOAST
// ══════════════════════════════════════════
function toast(msg, type='success') {
    const wrap = document.getElementById('toastWrap');
    const el   = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i> ${x(msg)}`;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3200);
}

// ══════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════
function avatarEl(s) {
    const ini = (s.first_name?.[0]||'')+(s.last_name?.[0]||'');
    return `<div class="s-avatar">${s.profile_picture?`<img src="${x(s.profile_picture)}" alt="">`:ini}</div>`;
}

function emptyHTML(icon, msg) {
    return `<div class="empty-state"><div class="empty-icon">${icon}</div><p>${x(msg)}</p></div>`;
}

function renderSkeletons(id) {
    document.getElementById(id).innerHTML = Array(5).fill(0).map(()=>`
        <div class="skel-row">
            <div class="skeleton skel-av"></div>
            <div style="flex:1;display:flex;flex-direction:column;gap:7px">
                <div class="skeleton skel-txt" style="width:45%"></div>
                <div class="skeleton skel-txt" style="width:65%"></div>
            </div>
        </div>
    `).join('');
}

function setPlaceholder(id, icon, msg) {
    document.getElementById(id).innerHTML = `
        <div class="placeholder-state">
            <div class="empty-icon">${icon}</div>
            <p>${x(msg)}</p>
        </div>`;
}

function setCount(id, n) { document.getElementById(id).textContent = n; }

function fmtDate(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
}

function x(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function debounce(fn, ms) {
    let t; return (...a) => { clearTimeout(t); t = setTimeout(()=>fn(...a), ms); };
}

async function post(data) {
    const f = new FormData();
    Object.entries(data).forEach(([k,v]) => f.append(k,v));
    const r = await fetch(window.location.href, {method:'POST',body:f});
    return r.json();
}
</script>
</body>
</html>