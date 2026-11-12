<?php
// ASHI AMAYA — dashboard_stats.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized. Admin login required.']));
}

require_once 'db.php';
$db = getDB();

// ── Helper: safe count query ──────────────────────────────────
function safe_count($db, $sql) {
    $r = $db->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return $row ? (int)$row['c'] : 0;
}

// ── STAT COUNTS ──────────────────────────────────────────────
$stats = [
    'students'    => safe_count($db, "SELECT COUNT(*) AS c FROM students    WHERE status = 'active'"),
    'teachers'    => safe_count($db, "SELECT COUNT(*) AS c FROM teachers    WHERE status = 'active'"),
    'subjects'    => safe_count($db, "SELECT COUNT(*) AS c FROM subjects    WHERE status = 'active'"),
    'assignments' => safe_count($db, "SELECT COUNT(*) AS c FROM assignments WHERE status != 'draft'"),
    'quizzes'     => safe_count($db, "SELECT COUNT(*) AS c FROM quizzes     WHERE status != 'draft'"),
];

// ── ACADEMIC PERIOD (school year + quarter) ──────────────────
$academic = ['school_year' => '—', 'current_quarter' => 1, 'updated_at' => null];
$r = $db->query("SELECT school_year, current_quarter, updated_at FROM school_year_settings ORDER BY id DESC LIMIT 1");
if ($r) {
    $row = $r->fetch_assoc();
    if ($row) {
        $academic['school_year']      = $row['school_year'];
        $academic['current_quarter']  = (int)$row['current_quarter'];
        $academic['updated_at']       = $row['updated_at'];
    }
}

// ── BAR CHART — Enrollment per Grade Level ───────────────────
$enrollment = ['labels' => [], 'data' => []];
$r = $db->query("SELECT grade_level, COUNT(*) AS total FROM students WHERE status = 'active' GROUP BY grade_level ORDER BY grade_level ASC");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $enrollment['labels'][] = $row['grade_level'];
        $enrollment['data'][]   = (int)$row['total'];
    }
}

// ── LINE CHART — Submission Activity (last 7 days) ───────────
$submission_map = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $submission_map[$day] = 0;
}
$r = $db->query("SELECT DATE(submitted_at) AS day, COUNT(*) AS total FROM submissions WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(submitted_at) ORDER BY day ASC");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        if (array_key_exists($row['day'], $submission_map)) {
            $submission_map[$row['day']] = (int)$row['total'];
        }
    }
}
$submissions = ['labels' => [], 'data' => []];
foreach ($submission_map as $day => $count) {
    $submissions['labels'][] = date('D', strtotime($day));
    $submissions['data'][]   = $count;
}

// ── DOUGHNUT CHART — Quiz Pass / Fail ────────────────────────
$pie = ['passed' => 0, 'failed' => 0];
$r = $db->query("SELECT SUM(CASE WHEN qa.percentage >= q.passing_score THEN 1 ELSE 0 END) AS passed, SUM(CASE WHEN qa.percentage < q.passing_score THEN 1 ELSE 0 END) AS failed FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id = q.id WHERE qa.status IN ('submitted','graded')");
if ($r) {
    $row = $r->fetch_assoc();
    if ($row) {
        $pie['passed'] = (int)($row['passed'] ?? 0);
        $pie['failed'] = (int)($row['failed'] ?? 0);
    }
}

// ── OUTPUT ───────────────────────────────────────────────────
echo json_encode([
    'stats'       => $stats,
    'academic'    => $academic,
    'enrollment'  => $enrollment,
    'submissions' => $submissions,
    'quiz_ratio'  => $pie,
]);