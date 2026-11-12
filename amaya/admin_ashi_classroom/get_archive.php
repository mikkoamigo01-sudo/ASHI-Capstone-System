<?php
// ASHI AMAYA — get_archive.php

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized. Admin login required.']));
}

require_once 'db.php';

$db     = getDB();
$action = isset($_GET['action']) ? trim($_GET['action']) : 'list';
$type   = isset($_GET['type'])   ? $db->real_escape_string(trim($_GET['type'])) : 'students';
$id     = isset($_GET['id'])     ? (int)$_GET['id'] : 0;
$search = isset($_GET['search']) ? $db->real_escape_string(trim($_GET['search'])) : '';
$grade  = isset($_GET['grade'])  ? $db->real_escape_string(trim($_GET['grade']))  : '';

if (!in_array($type, ['students', 'teachers'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type. Use: students or teachers']);
    exit;
}

// ── LIST ARCHIVED ─────────────────────────────────────────────
if ($action === 'list') {

    if ($type === 'students') {
        $where = ["s.status = 'archived'"];
        if ($search !== '') $where[] = "(s.first_name LIKE '%{$search}%' OR s.last_name LIKE '%{$search}%' OR CONCAT(s.first_name,' ',s.last_name) LIKE '%{$search}%' OR s.lrn LIKE '%{$search}%' OR s.email LIKE '%{$search}%')";
        if ($grade  !== '') $where[] = "s.grade_level = '{$grade}'";
        $w = implode(' AND ', $where);

        $records = [];
        $r = $db->query("SELECT s.id, s.lrn, s.first_name, s.last_name, s.email, s.grade_level, s.section, s.contact_number, s.guardian_name, s.relationship, s.guardian_contact, s.birthdate, s.profile_picture, s.status, s.created_at FROM students s WHERE {$w} ORDER BY s.last_name ASC, s.first_name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $records[] = $row;

        $grade_opts = [];
        $r2 = $db->query("SELECT DISTINCT grade_level FROM students WHERE status='archived' ORDER BY grade_level");
        if ($r2) while ($row = $r2->fetch_assoc()) $grade_opts[] = $row['grade_level'];

        echo json_encode(['records' => $records, 'total' => count($records), 'grade_opts' => $grade_opts]);

    } else {
        $where = ["t.status = 'archived'"];
        if ($search !== '') $where[] = "(t.fullname LIKE '%{$search}%' OR t.email LIKE '%{$search}%' OR t.specialization LIKE '%{$search}%')";
        $w = implode(' AND ', $where);

        $records = [];
        $r = $db->query("SELECT t.id, t.fullname, t.email, t.phone, t.gender, t.specialization, t.education, t.advisory_grade, t.advisory_section, t.profile_picture, t.status, t.created_at, t.birthdate, t.address FROM teachers t WHERE {$w} ORDER BY t.fullname ASC");
        if ($r) while ($row = $r->fetch_assoc()) $records[] = $row;

        echo json_encode(['records' => $records, 'total' => count($records)]);
    }

// ── INFO ─────────────────────────────────────────────────────
} elseif ($action === 'info' && $id > 0) {

    if ($type === 'students') {
        $r = $db->query("SELECT id, lrn, first_name, last_name, middle_name, email, username, grade_level, section, birthdate, contact_number, guardian_name, relationship, guardian_contact, bio, profile_picture, status, created_at FROM students WHERE id = {$id} AND status = 'archived' LIMIT 1");
    } else {
        $r = $db->query("SELECT id, fullname, email, phone, gender, birthdate, address, education, specialization, advisory_grade, advisory_section, bio, profile_picture, status, created_at FROM teachers WHERE id = {$id} AND status = 'archived' LIMIT 1");
    }

    if ($r && $r->num_rows > 0) {
        echo json_encode(['record' => $r->fetch_assoc()]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Archived record not found.']);
    }

// ── UNARCHIVE ────────────────────────────────────────────────
} elseif ($action === 'unarchive' && $id > 0) {

    $table = ($type === 'students') ? 'students' : 'teachers';
    $db->query("UPDATE {$table} SET status = 'active' WHERE id = {$id} AND status = 'archived'");

    if ($db->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Record restored to active successfully.']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not restore. Not archived or not found.']);
    }

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or missing parameters.']);
}