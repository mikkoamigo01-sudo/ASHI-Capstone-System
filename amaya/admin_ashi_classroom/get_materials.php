<?php
// ASHI AMAYA — get_materials.php

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized. Admin login required.']));
}

require_once 'db.php';

$db = getDB();

$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$type       = $db->real_escape_string(trim($_GET['type']   ?? ''));
$search     = $db->real_escape_string(trim($_GET['search'] ?? ''));

// ── SUBJECTS IN SCOPE ─────────────────────────────────────────
$sub_where = ["sub.status = 'active'"];
if ($teacher_id > 0) $sub_where[] = "sub.teacher_id = {$teacher_id}";
if ($subject_id > 0) $sub_where[] = "sub.id = {$subject_id}";
$sub_where_sql = implode(' AND ', $sub_where);

$subjects = [];
$r = $db->query("SELECT sub.id, sub.subject_name, sub.grade, sub.section, t.id AS teacher_id, t.fullname AS teacher_name FROM subjects sub JOIN teachers t ON sub.teacher_id = t.id WHERE {$sub_where_sql} ORDER BY t.fullname ASC, sub.subject_name ASC");
if ($r) while ($row = $r->fetch_assoc()) $subjects[] = $row;

if (empty($subjects)) {
    // No subjects — return empty but still provide filter options
    $teachers = [];
    $r2 = $db->query("SELECT id, fullname FROM teachers WHERE status='active' ORDER BY fullname");
    if ($r2) while ($row = $r2->fetch_assoc()) $teachers[] = $row;

    echo json_encode(['groups' => [], 'filters' => ['teachers' => $teachers, 'subjects' => []], 'total_groups' => 0]);
    exit;
}

$id_list = implode(',', array_column($subjects, 'id'));

// ── FETCH MATERIALS ───────────────────────────────────────────
$materials_map = [];
if ($type === '' || $type === 'materials') {
    $s_cond = $search !== '' ? "AND m.title LIKE '%{$search}%'" : '';
    $r = $db->query("SELECT m.id, m.subject_id, m.title, m.material_type AS content_type, m.file_path, m.url, m.created_at, 'material' AS item_type, NULL AS due_date, NULL AS status FROM materials m WHERE m.subject_id IN ({$id_list}) {$s_cond} ORDER BY m.created_at DESC");
    if ($r) while ($row = $r->fetch_assoc()) $materials_map[$row['subject_id']][] = $row;
}

// ── FETCH ASSIGNMENTS ─────────────────────────────────────────
$assignments_map = [];
if ($type === '' || $type === 'assignments') {
    $s_cond = $search !== '' ? "AND a.title LIKE '%{$search}%'" : '';
    $r = $db->query("SELECT a.id, a.subject_id, a.title, a.assignment_type AS content_type, a.due_date, a.max_points, a.status, a.created_at, 'assignment' AS item_type, (SELECT COUNT(*) FROM submissions sm WHERE sm.assignment_id = a.id) AS submission_count FROM assignments a WHERE a.subject_id IN ({$id_list}) {$s_cond} ORDER BY a.created_at DESC");
    if ($r) while ($row = $r->fetch_assoc()) $assignments_map[$row['subject_id']][] = $row;
}

// ── FETCH QUIZZES ─────────────────────────────────────────────
$quizzes_map = [];
if ($type === '' || $type === 'quizzes') {
    $s_cond = $search !== '' ? "AND q.title LIKE '%{$search}%'" : '';
    $r = $db->query("SELECT q.id, q.subject_id, q.title, q.due_date, q.total_points, q.status, q.created_at, 'quiz' AS item_type, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count, (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempt_count FROM quizzes q WHERE q.subject_id IN ({$id_list}) {$s_cond} ORDER BY q.created_at DESC");
    if ($r) while ($row = $r->fetch_assoc()) $quizzes_map[$row['subject_id']][] = $row;
}

// ── BUILD GROUPS ──────────────────────────────────────────────
$groups = [];
foreach ($subjects as $sub) {
    $sid  = $sub['id'];
    $mats = $materials_map[$sid]   ?? [];
    $asgs = $assignments_map[$sid] ?? [];
    $qzs  = $quizzes_map[$sid]     ?? [];

    if (($search !== '' || $type !== '') && empty($mats) && empty($asgs) && empty($qzs)) continue;

    $groups[] = [
        'subject'     => $sub,
        'materials'   => $mats,
        'assignments' => $asgs,
        'quizzes'     => $qzs,
        'total_items' => count($mats) + count($asgs) + count($qzs),
    ];
}

// ── FILTER OPTIONS ────────────────────────────────────────────
$teacher_opts = [];
$r = $db->query("SELECT id, fullname FROM teachers WHERE status='active' ORDER BY fullname");
if ($r) while ($row = $r->fetch_assoc()) $teacher_opts[] = $row;

$subject_opts = [];
$r = $db->query("SELECT id, subject_name, grade FROM subjects WHERE status='active' ORDER BY subject_name");
if ($r) while ($row = $r->fetch_assoc()) $subject_opts[] = $row;

echo json_encode([
    'groups'       => $groups,
    'filters'      => ['teachers' => $teacher_opts, 'subjects' => $subject_opts],
    'total_groups' => count($groups),
]);