<?php
// ============================================================
// archive_grade.php — Student Quarterly Grade Archive
// One row per student, modal for full grade breakdown
// ============================================================

$host = 'localhost'; $dbname = 'users'; $user = 'root'; $pass = ''; $charset = 'utf8mb4';
$dsn  = "mysql:host=$host;dbname=$dbname;charset=$charset";
$opts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => true,
];
try { $pdo = new PDO($dsn, $user, $pass, $opts); }
catch (\PDOException $e) { die('<p style="color:red;">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>'); }

// ── AJAX: Fetch full grade detail for one student ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'student_detail') {
    header('Content-Type: application/json');

    $student_id  = (int)($_POST['student_id']  ?? 0);
    $school_year = trim($_POST['school_year']   ?? '');

    if (!$student_id || !$school_year) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']); exit;
    }

    // Student info
    $stmt = $pdo->prepare("
        SELECT lrn, first_name, last_name, middle_name, grade_level, section, email, guardian_name, relationship, guardian_contact
        FROM students WHERE id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']); exit;
    }

    // Adviser — match by grade_level + section
    $adv_stmt = $pdo->prepare("
        SELECT fullname, email, phone
        FROM teachers
        WHERE advisory_grade = ? AND advisory_section = ? AND status = 'active'
        LIMIT 1
    ");
    $adv_stmt->execute([$student['grade_level'], $student['section']]);
    $adviser = $adv_stmt->fetch();

    // All archived grades for this student + school year
    $grade_stmt = $pdo->prepare("
        SELECT
            a.quarter, a.grade, a.is_locked, a.archived_at,
            a.subject_id,
            sub.subject_name,
            t.fullname AS teacher_name
        FROM student_quarterly_grades_archive a
        LEFT JOIN subjects sub ON a.subject_id = sub.id
        LEFT JOIN teachers t   ON sub.teacher_id = t.id
        WHERE a.student_id = ? AND a.school_year = ?
        ORDER BY a.quarter ASC, sub.subject_name ASC
    ");
    $grade_stmt->execute([$student_id, $school_year]);
    $grade_rows = $grade_stmt->fetchAll();

    // Group by quarter
    $quarters = [];
    foreach ($grade_rows as $row) {
        $q = $row['quarter'];
        if (!isset($quarters[$q])) {
            $quarters[$q] = ['quarter' => $q, 'subjects' => [], 'is_locked' => false];
        }
        if ($row['is_locked']) $quarters[$q]['is_locked'] = true;
        $quarters[$q]['subjects'][] = [
            'subject_name' => $row['subject_name'] ?? 'Unknown Subject',
            'teacher_name' => $row['teacher_name'] ?? null,
            'grade'        => $row['grade'],
            'is_locked'    => $row['is_locked'],
        ];
    }
    ksort($quarters);

    echo json_encode([
        'success'     => true,
        'student'     => $student,
        'adviser'     => $adviser ?: null,
        'quarters'    => array_values($quarters),
        'school_year' => $school_year,
    ]);
    exit;
}

// ── Input sanitization ────────────────────────────────────────
$filter_sy     = preg_replace('/[^0-9\-]/', '', $_GET['school_year'] ?? '');
$filter_search = mb_substr(trim($_GET['search'] ?? ''), 0, 60);

// ── Pagination ────────────────────────────────────────────────
$allowed_pp   = [20, 50, 100];
$raw_pp       = (int)($_GET['per_page'] ?? 20);
$per_page     = in_array($raw_pp, $allowed_pp, true) ? $raw_pp : 20;
$current_page = max(1, (int)($_GET['page'] ?? 1));

// ── School years dropdown ─────────────────────────────────────
$school_years = $pdo->query(
    "SELECT DISTINCT school_year FROM student_quarterly_grades_archive ORDER BY school_year DESC"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Build WHERE clause ────────────────────────────────────────
// Group by student_id + school_year — one row per student
$where  = [];
$params = [];

if ($filter_sy !== '') {
    $where[]  = 'a.school_year = ?';
    $params[] = $filter_sy;
}

$need_student = $filter_search !== '';
$join_type    = $need_student ? 'INNER JOIN' : 'LEFT JOIN';

if ($need_student) {
    $like     = $filter_search . '%';
    $where[]  = '(s.last_name LIKE ? OR s.first_name LIKE ? OR s.lrn LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── COUNT distinct students ───────────────────────────────────
$cnt = $pdo->prepare("
    SELECT COUNT(DISTINCT CONCAT(a.student_id, '|', a.school_year))
    FROM student_quarterly_grades_archive a
    {$join_type} students s ON a.student_id = s.id
    $whereSQL
");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$cnt->closeCursor();

$total_pages  = max(1, (int)ceil($total / $per_page));
$current_page = min($current_page, $total_pages);
$offset       = ($current_page - 1) * $per_page;

// ── Main query — one row per student per school year ──────────
$stmt = $pdo->prepare("
    SELECT
        a.student_id,
        a.school_year,
        s.lrn,
        s.first_name,
        s.last_name,
        s.middle_name,
        s.grade_level,
        s.section,
        COUNT(a.id)                                          AS total_records,
        COUNT(DISTINCT a.quarter)                            AS quarters_count,
        ROUND(AVG(CASE WHEN a.grade IS NOT NULL THEN a.grade END), 2) AS avg_grade,
        MAX(a.archived_at)                                   AS archived_at
    FROM student_quarterly_grades_archive a
    {$join_type} students s ON a.student_id = s.id
    $whereSQL
    GROUP BY a.student_id, a.school_year, s.lrn, s.first_name, s.last_name, s.middle_name, s.grade_level, s.section
    ORDER BY s.last_name ASC, s.first_name ASC, a.school_year DESC
    LIMIT ? OFFSET ?
");
foreach ($params as $k => $v) { $stmt->bindValue($k + 1, $v); }
$stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset,   PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
$stmt->closeCursor();

// ── Helpers ───────────────────────────────────────────────────
function pageUrl(int $page, int $per_page): string {
    $carry = ['school_year', 'search'];
    $p = ['page' => $page, 'per_page' => $per_page];
    foreach ($carry as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $p[$k] = $_GET[$k];
    }
    return '?' . http_build_query($p);
}

function pageRange(int $cur, int $total, int $wing = 2): array {
    $s = max(1, min($cur - $wing, $total - $wing * 2));
    $e = min($total, max($cur + $wing, $wing * 2 + 1));
    return range($s, $e);
}

$showing_from = $total > 0 ? $offset + 1 : 0;
$showing_to   = min($offset + $per_page, $total);
$clear_url    = 'archive_grade.php';

$quarter_labels = [1 => '1st Quarter', 2 => '2nd Quarter', 3 => '3rd Quarter', 4 => '4th Quarter'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grades Archive — ASHI AMAYA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --blue:    #1a73e8;
  --blue-lt: #e8f0fe;
  --blue-dk: #1557b0;
  --green:   #34a853;
  --red:     #ea4335;
  --yellow:  #f9ab00;
  --text:    #202124;
  --text-2:  #5f6368;
  --text-3:  #80868b;
  --border:  #dadce0;
  --surf:    #ffffff;
  --bg:      #f8f9fa;
  --r:       8px;
  --r-lg:    12px;
  --sh:      0 1px 2px rgba(60,64,67,.08),0 2px 6px rgba(60,64,67,.08);
  --t:       .14s cubic-bezier(.4,0,.2,1);
}

html{-webkit-font-smoothing:antialiased}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh}

/* App Bar */
.appbar{
  position:sticky;top:0;z-index:100;
  background:var(--surf);border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:14px;
  padding:0 28px;height:60px;
}
.appbar-title{font-size:16px;font-weight:600;letter-spacing:-.02em}
.appbar-dot{width:4px;height:4px;border-radius:50%;background:var(--border)}
.appbar-sub{font-size:12px;color:var(--text-3)}
.appbar-spacer{flex:1}
.btn-dashboard{
  font-family:inherit;font-size:12px;font-weight:500;
  padding:7px 14px;border-radius:var(--r);border:1.5px solid var(--border);
  background:transparent;color:var(--text-2);cursor:pointer;
  display:inline-flex;align-items:center;gap:6px;
  text-decoration:none;white-space:nowrap;
  transition:background var(--t),border-color var(--t),color var(--t);
}
.btn-dashboard:hover{background:var(--blue-lt);border-color:var(--blue);color:var(--blue)}

/* Page */
.page{max-width:1100px;margin:0 auto;padding:22px 24px 80px}

/* Filter Card */
.fcard{
  background:var(--surf);border:1px solid var(--border);
  border-radius:var(--r-lg);padding:18px 22px 20px;
  margin-bottom:16px;box-shadow:var(--sh);
}
.fcard-hd{
  font-size:10px;font-weight:700;letter-spacing:.09em;
  text-transform:uppercase;color:var(--text-3);margin-bottom:14px;
  display:flex;align-items:center;gap:6px;
}
.frow{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.field{display:flex;flex-direction:column;gap:5px;flex:1;min-width:150px}
.field.f-search{flex:2;min-width:220px}
.field label{font-size:10px;font-weight:600;color:var(--blue);text-transform:uppercase;letter-spacing:.05em}
.field input,.field select{
  font-family:inherit;font-size:13px;padding:9px 12px;
  border:1.5px solid var(--border);border-radius:var(--r);
  background:var(--surf);color:var(--text);outline:none;appearance:none;
  transition:border-color var(--t),box-shadow var(--t);width:100%;
}
.field input:focus,.field select:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,115,232,.12)}
.field .sel-wrap{position:relative}
.field .sel-wrap::after{content:'▾';position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--text-3);pointer-events:none}
.field input::placeholder{color:var(--text-3)}
.search-wrap{position:relative}
.search-wrap input{padding-right:32px}
.search-clear{position:absolute;right:9px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:14px;color:var(--text-3);line-height:1;padding:0;display:none}
.search-clear:hover{color:var(--text)}
.search-clear.visible{display:block}
.btn{font-family:inherit;font-size:13px;font-weight:500;padding:9px 20px;border-radius:var(--r);border:none;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;transition:background var(--t),box-shadow var(--t);text-decoration:none;height:38px}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue-dk);box-shadow:0 1px 4px rgba(26,115,232,.35)}
.btn-ghost{background:transparent;color:var(--text-2);border:1.5px solid var(--border)}
.btn-ghost:hover{background:var(--bg);color:var(--text);border-color:#bbb}
.btn-actions{display:flex;gap:8px;align-items:center;flex-shrink:0}

/* Summary bar */
.sbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.sbar-left{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.sbar-right{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--text-3)}
.chip{font-size:11px;font-weight:500;padding:4px 11px;border-radius:999px;display:inline-flex;align-items:center;gap:4px}
.chip-blue{background:var(--blue-lt);color:var(--blue)}
.chip-grey{background:#f1f3f4;color:var(--text-2)}
.chip-yellow{background:#fef7e0;color:#7b5000}
.pp-sel{font-family:inherit;font-size:12px;padding:4px 24px 4px 8px;border:1.5px solid var(--border);border-radius:6px;background:var(--surf);color:var(--text-2);appearance:none;outline:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2380868b'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 6px center}
.pp-sel:focus{border-color:var(--blue)}

/* Table */
.tcard{background:var(--surf);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh)}
.tscroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:700px}
thead tr{border-bottom:2px solid var(--border)}
thead th{padding:11px 16px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-3);background:#fafafa;text-align:left;white-space:nowrap}
thead th:first-child{padding-left:20px}
tbody tr{border-bottom:1px solid #f1f3f4;transition:background var(--t)}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#f8f9fa}
tbody td{padding:12px 16px;font-size:13px;color:var(--text);vertical-align:middle}
tbody td:first-child{padding-left:20px}
.dim{color:var(--text-3);font-size:12px}

/* Student cell */
.stu-cell{display:flex;align-items:center;gap:10px}
.avatar{width:34px;height:34px;border-radius:50%;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;background:linear-gradient(135deg,#4285f4 0%,#34a853 100%)}
.stu-name{font-weight:600;font-size:13px}
.lrn-txt{font-size:11px;color:var(--text-3);font-variant-numeric:tabular-nums;letter-spacing:.03em;margin-top:1px}

/* Grade badge */
.gbadge{display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;min-width:54px;padding:3px 10px;border-radius:999px;white-space:nowrap}
.g-high{background:#e6f4ea;color:#137333}
.g-pass{background:#fef7e0;color:#7b5000}
.g-fail{background:#fce8e6;color:#c5221f}
.g-none{background:#f1f3f4;color:var(--text-3)}

/* Eye button */
.btn-eye{background:var(--blue-lt);border:none;color:var(--blue);border-radius:var(--r);padding:7px 12px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:5px;font-family:inherit;font-weight:500;transition:background var(--t),color var(--t)}
.btn-eye:hover{background:var(--blue);color:#fff}

/* Pagination */
.pag{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:13px 20px;border-top:1px solid var(--border);background:#fafafa}
.pag-info{font-size:12px;color:var(--text-3)}
.pag-nav{display:flex;align-items:center;gap:3px}
.plink{font-family:inherit;font-size:13px;font-weight:500;min-width:34px;height:34px;padding:0 5px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;border:none;background:transparent;cursor:pointer;color:var(--text-2);transition:background var(--t);text-decoration:none}
.plink:hover{background:var(--bg);color:var(--text)}
.plink.active{background:var(--blue);color:#fff;pointer-events:none}
.plink.off{opacity:.3;pointer-events:none}
.plink.wide{border-radius:var(--r);padding:0 12px;min-width:auto}
.pag-dots{color:var(--text-3);padding:0 4px;font-size:13px;user-select:none}

/* Empty */
.empty{display:flex;flex-direction:column;align-items:center;padding:72px 24px;gap:10px;text-align:center}
.empty-ico{font-size:44px;opacity:.3}
.empty h3{font-size:15px;font-weight:500;color:var(--text-2)}
.empty p{font-size:13px;color:var(--text-3)}

/* Loading bar */
.lbar{position:fixed;top:0;left:0;width:0;height:3px;background:var(--blue);z-index:999;pointer-events:none;transition:width .3s ease}
.lbar.run{width:75%}
.lbar.done{width:100%;opacity:0;transition:width .2s,opacity .3s .1s}

/* Row animation */
@keyframes rowIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
tbody tr{animation:rowIn .18s ease both}
<?php for($i=1;$i<=20;$i++): ?>
tbody tr:nth-child(<?=$i?>){animation-delay:<?=round($i*.018,3)?>s}
<?php endfor; ?>

/* ── Modal ────────────────────────────────────────────────── */
.modal-overlay{
  display:none;position:fixed;inset:0;z-index:200;
  background:rgba(32,33,36,.55);backdrop-filter:blur(3px);
  align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open{display:flex}
.modal-box{
  background:var(--surf);border-radius:var(--r-lg);
  width:100%;max-width:660px;max-height:90vh;
  display:flex;flex-direction:column;
  box-shadow:0 8px 40px rgba(0,0,0,.18);
  animation:modalIn .18s ease;
}
@keyframes modalIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:none}}
.modal-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:18px 22px 14px;border-bottom:1px solid var(--border);flex-shrink:0;
}
.modal-head h2{font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px}
.modal-close{background:none;border:none;cursor:pointer;font-size:18px;color:var(--text-3);line-height:1;padding:4px;border-radius:50%;transition:background var(--t),color var(--t)}
.modal-close:hover{background:var(--bg);color:var(--text)}
.modal-body{overflow-y:auto;padding:20px 22px;flex:1}

/* Modal student info */
.modal-student{
  display:flex;align-items:center;gap:14px;
  padding:14px 16px;background:var(--bg);
  border-radius:var(--r);margin-bottom:16px;border:1px solid var(--border);
}
.modal-avatar{
  width:46px;height:46px;border-radius:50%;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:16px;font-weight:700;color:#fff;
  background:linear-gradient(135deg,#4285f4 0%,#34a853 100%);
}
.modal-stu-name{font-size:15px;font-weight:700}
.modal-stu-meta{font-size:12px;color:var(--text-3);margin-top:2px}

/* Info grid */
.modal-info-grid{
  display:grid;grid-template-columns:1fr 1fr;gap:8px;
  margin-bottom:16px;
}
.modal-info-item{
  background:var(--bg);border:1px solid var(--border);
  border-radius:var(--r);padding:10px 12px;
}
.modal-info-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:3px}
.modal-info-val{font-size:13px;font-weight:500;color:var(--text)}

/* Adviser card */
.modal-adviser{
  display:flex;align-items:center;gap:10px;
  background:#e8f0fe;border:1px solid #c5d8f7;
  border-radius:var(--r);padding:10px 14px;margin-bottom:16px;
}
.modal-adviser i{color:var(--blue);font-size:14px;flex-shrink:0}
.modal-adviser-info{flex:1}
.modal-adviser-name{font-size:13px;font-weight:600;color:var(--blue-dk)}
.modal-adviser-sub{font-size:11px;color:var(--text-3);margin-top:1px}

/* Quarter blocks */
.modal-quarter{margin-bottom:16px}
.modal-quarter:last-child{margin-bottom:0}
.modal-q-head{
  display:flex;align-items:center;gap:8px;
  font-size:11px;font-weight:700;text-transform:uppercase;
  letter-spacing:.05em;color:var(--text-2);
  margin-bottom:8px;padding-bottom:6px;
  border-bottom:2px solid var(--border);
}
.modal-q-badge{
  font-size:10px;font-weight:700;padding:2px 8px;
  border-radius:4px;background:var(--blue-lt);color:var(--blue);
}
.modal-q-avg{
  margin-left:auto;font-size:12px;font-weight:700;
  padding:2px 10px;border-radius:999px;
}

/* Subject rows in modal */
.modal-subj-row{
  display:flex;align-items:center;
  padding:8px 10px;border-radius:6px;
  transition:background var(--t);
}
.modal-subj-row:hover{background:var(--bg)}
.modal-subj-info{flex:1}
.modal-subj-name{font-size:13px;font-weight:500}
.modal-subj-teacher{font-size:11px;color:var(--text-3);margin-top:1px}
.modal-subj-grade{flex-shrink:0;margin-left:12px}

/* Loading spinner in modal */
.modal-loading{
  display:flex;flex-direction:column;align-items:center;
  padding:48px 24px;gap:12px;color:var(--text-3);
}
.modal-loading i{font-size:24px;color:var(--blue)}

/* No adviser */
.modal-no-adviser{
  font-size:12px;color:var(--text-3);
  padding:8px 12px;background:var(--bg);
  border-radius:var(--r);border:1px solid var(--border);
  margin-bottom:16px;display:flex;align-items:center;gap:6px;
}

@media(max-width:640px){
  .appbar{padding:0 16px}
  .appbar-dot,.appbar-sub{display:none}
  .page{padding:14px 12px 60px}
  .fcard{padding:14px 14px 16px}
  .field{min-width:100%}
  .btn-actions{width:100%}
  .btn-actions .btn{flex:1;justify-content:center}
  .modal-info-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="lbar" id="lbar"></div>

<!-- App Bar -->
<header class="appbar">
  <i class="fas fa-archive" style="color:var(--blue);font-size:18px;"></i>
  <span class="appbar-title">Grades Archive</span>
  <span class="appbar-dot"></span>
  <span class="appbar-sub">ASHI AMAYA · Quarterly Records</span>
  <div class="appbar-spacer"></div>
  <a href="../admin_dash.php" class="btn-dashboard">
    <i class="fas fa-house" style="font-size:12px;"></i>
    <span>Dashboard</span>
  </a>
</header>

<main class="page">

  <!-- Filter Card -->
  <div class="fcard">
    <div class="fcard-hd">
      <i class="fas fa-filter" style="font-size:10px;"></i>
      Filters
    </div>
    <form method="GET" action="" id="filter-form">
      <input type="hidden" name="per_page" value="<?= $per_page ?>">
      <input type="hidden" name="page" value="1" id="page-input">
      <div class="frow">

        <!-- Search -->
        <div class="field f-search">
          <label for="f-search">Search Student</label>
          <div class="search-wrap">
            <input type="text" id="f-search" name="search"
              placeholder="Name or LRN…"
              value="<?= htmlspecialchars($filter_search) ?>"
              autocomplete="off" spellcheck="false">
            <button type="button" class="search-clear <?= $filter_search ? 'visible' : '' ?>" id="clear-search" title="Clear">✕</button>
          </div>
        </div>

        <!-- School Year -->
        <div class="field">
          <label for="f-sy">School Year</label>
          <div class="sel-wrap">
            <select id="f-sy" name="school_year">
              <option value="">All Years</option>
              <?php foreach ($school_years as $sy): ?>
                <option value="<?= htmlspecialchars($sy) ?>" <?= $filter_sy === $sy ? 'selected' : '' ?>>
                  SY <?= htmlspecialchars($sy) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Buttons -->
        <div class="btn-actions">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search" style="font-size:12px;"></i> Search
          </button>
          <a href="<?= $clear_url ?>" class="btn btn-ghost">
            <i class="fas fa-times" style="font-size:12px;"></i> Clear
          </a>
        </div>

      </div>
    </form>
  </div>

  <!-- Summary Bar -->
  <div class="sbar">
    <div class="sbar-left">
      <span class="chip chip-blue">
        <i class="fas fa-users" style="font-size:10px;"></i>
        <?= number_format($total) ?> student<?= $total !== 1 ? 's' : '' ?>
      </span>
      <?php if ($filter_sy): ?>
        <span class="chip chip-grey">📅 SY <?= htmlspecialchars($filter_sy) ?></span>
      <?php endif; ?>
      <?php if ($filter_search): ?>
        <span class="chip chip-yellow">🔍 "<?= htmlspecialchars($filter_search) ?>"</span>
      <?php endif; ?>
    </div>
    <div class="sbar-right">
      <label for="pp-sel" style="font-size:12px;color:var(--text-3)">Rows:</label>
      <select id="pp-sel" class="pp-sel" onchange="changePerPage(this.value)">
        <?php foreach ([20, 50, 100] as $pp): ?>
          <option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?> / page</option>
        <?php endforeach; ?>
      </select>
      <span>Page <?= number_format($current_page) ?> of <?= number_format($total_pages) ?></span>
    </div>
  </div>

  <!-- Table Card -->
  <div class="tcard">
    <?php if (empty($rows)): ?>
      <div class="empty">
        <div class="empty-ico">🗂</div>
        <h3>No archived records found</h3>
        <p>Try adjusting your filters, or no end-of-year reset has been run yet.</p>
      </div>
    <?php else: ?>
    <div class="tscroll">
      <table>
        <thead>
          <tr>
            <th style="width:46px">#</th>
            <th>Student</th>
            <th>LRN</th>
            <th>Grade &amp; Section</th>
            <th>School Year</th>
            <th>Quarters</th>
            <th>Avg Grade</th>
            <th style="width:80px">View</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $row):
            $fn       = $row['first_name'] ?? '';
            $ln       = $row['last_name']  ?? '';
            $initials = strtoupper(mb_substr($fn,0,1).mb_substr($ln,0,1));
            $rowNum   = $offset + $i + 1;
            $avg      = $row['avg_grade'];
            $gc       = !is_numeric($avg) ? 'g-none'
                      : ((float)$avg >= 85 ? 'g-high'
                      : ((float)$avg >= 75 ? 'g-pass' : 'g-fail'));
          ?>
          <tr>
            <td class="dim"><?= $rowNum ?></td>
            <td>
              <div class="stu-cell">
                <div class="avatar"><?= $initials ?: '?' ?></div>
                <div>
                  <div class="stu-name">
                    <?= ($fn && $ln) ? htmlspecialchars("$ln, $fn") : '<span style="color:var(--text-3)">Unknown</span>' ?>
                  </div>
                  <div class="lrn-txt"><?= htmlspecialchars($row['lrn'] ?? '—') ?></div>
                </div>
              </div>
            </td>
            <td class="dim"><?= htmlspecialchars($row['lrn'] ?? '—') ?></td>
            <td style="color:var(--text-2)">
              <?= htmlspecialchars($row['grade_level'] ?? '—') ?>
              <?php if (!empty($row['section'])): ?>
                <span style="color:var(--text-3)"> · </span><?= htmlspecialchars($row['section']) ?>
              <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($row['school_year']) ?></strong></td>
            <td>
              <span style="font-size:12px;color:var(--text-2);">
                <?= $row['quarters_count'] ?> quarter<?= $row['quarters_count'] != 1 ? 's' : '' ?>
              </span>
            </td>
            <td>
              <span class="gbadge <?= $gc ?>">
                <?= is_numeric($avg) ? number_format((float)$avg, 2) : '—' ?>
              </span>
            </td>
            <td>
              <button class="btn-eye"
                onclick="openModal(<?= $row['student_id'] ?>, '<?= htmlspecialchars(addslashes($row['school_year'])) ?>', '<?= htmlspecialchars(addslashes("$ln, $fn")) ?>')">
                <i class="fas fa-eye"></i> View
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pag">
      <div class="pag-info">
        Showing <strong><?= number_format($showing_from) ?></strong>–<strong><?= number_format($showing_to) ?></strong>
        of <strong><?= number_format($total) ?></strong> students
      </div>
      <nav class="pag-nav" aria-label="Pagination">

        <?php if ($current_page > 1): ?>
          <a href="<?= pageUrl(1, $per_page) ?>" class="plink wide" title="First">«</a>
          <a href="<?= pageUrl($current_page - 1, $per_page) ?>" class="plink wide">‹ Prev</a>
        <?php else: ?>
          <span class="plink wide off">«</span>
          <span class="plink wide off">‹ Prev</span>
        <?php endif; ?>

        <?php
          $range      = pageRange($current_page, $total_pages);
          $range_copy = $range;
          if ($range_copy[0] > 1):
        ?>
          <a href="<?= pageUrl(1, $per_page) ?>" class="plink">1</a>
          <?php if ($range_copy[0] > 2): ?><span class="pag-dots">…</span><?php endif; ?>
        <?php endif; ?>

        <?php foreach ($range as $p): ?>
          <a href="<?= pageUrl($p, $per_page) ?>"
             class="plink <?= $p === $current_page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endforeach; ?>

        <?php $last = end($range_copy); if ($last < $total_pages): ?>
          <?php if ($last < $total_pages - 1): ?><span class="pag-dots">…</span><?php endif; ?>
          <a href="<?= pageUrl($total_pages, $per_page) ?>" class="plink"><?= $total_pages ?></a>
        <?php endif; ?>

        <?php if ($current_page < $total_pages): ?>
          <a href="<?= pageUrl($current_page + 1, $per_page) ?>" class="plink wide">Next ›</a>
          <a href="<?= pageUrl($total_pages, $per_page) ?>" class="plink wide" title="Last">»</a>
        <?php else: ?>
          <span class="plink wide off">Next ›</span>
          <span class="plink wide off">»</span>
        <?php endif; ?>

      </nav>
    </div>
    <?php endif; ?>
  </div>

</main>

<!-- ── Modal ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal" onclick="closeModalOutside(event)">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-id-card" style="color:var(--blue);"></i> <span id="modal-title">Student Grade Record</span></h2>
      <button class="modal-close" onclick="closeModal()" title="Close">✕</button>
    </div>
    <div class="modal-body" id="modal-body">
      <div class="modal-loading">
        <i class="fas fa-spinner fa-spin"></i>
        <span>Loading grade records…</span>
      </div>
    </div>
  </div>
</div>

<script>
const lbar = document.getElementById('lbar');
function runBar(){ lbar.classList.add('run'); }
function doneBar(){ lbar.classList.remove('run'); lbar.classList.add('done'); setTimeout(()=>lbar.classList.remove('done'),400); }
document.querySelectorAll('a[href]').forEach(a=>{
  if(a.hostname===location.hostname) a.addEventListener('click', runBar);
});
window.addEventListener('pageshow', doneBar);

// Search clear
const si = document.getElementById('f-search');
const sc = document.getElementById('clear-search');
si?.addEventListener('input', ()=> sc.classList.toggle('visible', si.value.length > 0));
sc?.addEventListener('click', ()=>{
  si.value = '';
  sc.classList.remove('visible');
  document.getElementById('page-input').value = 1;
  runBar();
  document.getElementById('filter-form').submit();
});

// Dropdowns auto-submit
['f-sy'].forEach(id=>{
  document.getElementById(id)?.addEventListener('change', ()=>{
    document.getElementById('page-input').value = 1;
    runBar();
    document.getElementById('filter-form').submit();
  });
});

document.getElementById('filter-form')?.addEventListener('submit', ()=>{
  document.getElementById('page-input').value = 1;
  runBar();
});

function changePerPage(val){
  const u = new URL(location.href);
  u.searchParams.set('per_page', val);
  u.searchParams.set('page', 1);
  runBar();
  location.href = u.toString();
}

// Press "/" to focus search
document.addEventListener('keydown', e=>{
  if(e.key==='/' && document.activeElement?.tagName!=='INPUT'){
    e.preventDefault(); si?.focus();
  }
});

// ── Modal ─────────────────────────────────────────────────────
const QUARTER_LABELS = ['1st Quarter','2nd Quarter','3rd Quarter','4th Quarter'];

function openModal(studentId, schoolYear, name) {
  document.getElementById('modal-title').textContent = name + ' · SY ' + schoolYear;
  document.getElementById('modal-body').innerHTML = `
    <div class="modal-loading">
      <i class="fas fa-spinner fa-spin"></i>
      <span>Loading grade records…</span>
    </div>`;
  document.getElementById('modal').classList.add('open');
  document.body.style.overflow = 'hidden';

  const fd = new FormData();
  fd.append('action',      'student_detail');
  fd.append('student_id',  studentId);
  fd.append('school_year', schoolYear);

  fetch('archive_grade.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (!d.success) {
        document.getElementById('modal-body').innerHTML =
          `<p style="color:var(--red);font-size:13px;padding:16px;">Error: ${d.message || 'Failed to load.'}</p>`;
        return;
      }
      renderModal(d);
    })
    .catch(() => {
      document.getElementById('modal-body').innerHTML =
        `<p style="color:var(--red);font-size:13px;padding:16px;">Network error. Please try again.</p>`;
    });
}

function renderModal(d) {
  const st  = d.student;
  const adv = d.adviser;
  const fn  = st.first_name || '';
  const ln  = st.last_name  || '';
  const mn  = st.middle_name ? ' ' + st.middle_name.charAt(0).toUpperCase() + '.' : '';
  const initials = (fn.charAt(0) + ln.charAt(0)).toUpperCase();

  let html = '';

  // Student info card
  html += `
    <div class="modal-student">
      <div class="modal-avatar">${initials || '?'}</div>
      <div>
        <div class="modal-stu-name">${esc(ln + ', ' + fn + mn)}</div>
        <div class="modal-stu-meta">
          ${st.lrn ? 'LRN: ' + esc(st.lrn) + ' &nbsp;·&nbsp; ' : ''}
          ${esc(st.grade_level || '—')} — ${esc(st.section || '—')}
          &nbsp;·&nbsp; SY ${esc(d.school_year)}
        </div>
      </div>
    </div>`;

  // Info grid
  html += `<div class="modal-info-grid">`;
  html += infoItem('Guardian', st.guardian_name || '—');
  html += infoItem('Relationship', st.relationship || '—');
  html += infoItem('Guardian Contact', st.guardian_contact || '—');
  html += infoItem('Email', st.email || '—');
  html += `</div>`;

  // Adviser
  if (adv) {
    html += `
      <div class="modal-adviser">
        <i class="fas fa-chalkboard-teacher"></i>
        <div class="modal-adviser-info">
          <div class="modal-adviser-name">Adviser: ${esc(adv.fullname)}</div>
          <div class="modal-adviser-sub">${adv.email ? esc(adv.email) : ''}${adv.phone ? ' &nbsp;·&nbsp; ' + esc(adv.phone) : ''}</div>
        </div>
      </div>`;
  } else {
    html += `
      <div class="modal-no-adviser">
        <i class="fas fa-info-circle"></i>
        No adviser record found for this grade level and section.
      </div>`;
  }

  // Quarters
  if (!d.quarters.length) {
    html += `<p style="font-size:13px;color:var(--text-3);text-align:center;padding:24px;">No grade records found for this student.</p>`;
  } else {
    d.quarters.forEach(q => {
      const qLabel = QUARTER_LABELS[q.quarter - 1] || ('Quarter ' + q.quarter);
      const grades = q.subjects.map(s => s.grade).filter(g => g !== null && g !== '');
      const avg    = grades.length ? (grades.reduce((a,b) => a + parseFloat(b), 0) / grades.length) : null;
      const avgCls = avg === null ? 'g-none' : avg >= 85 ? 'g-high' : avg >= 75 ? 'g-pass' : 'g-fail';

      html += `<div class="modal-quarter">`;
      html += `<div class="modal-q-head">
        <span class="modal-q-badge">${esc(qLabel)}</span>
        <span style="color:var(--text-3);font-size:10px;">${q.is_locked ? '🔒 Finalized' : '🔓 Unlocked'}</span>
        ${avg !== null ? `<span class="modal-q-avg gbadge ${avgCls}" style="margin-left:auto;">Avg: ${avg.toFixed(2)}</span>` : ''}
      </div>`;

      if (!q.subjects.length) {
        html += `<p style="font-size:12px;color:var(--text-3);padding:8px 0;">No subjects recorded.</p>`;
      } else {
        q.subjects.forEach(sub => {
          const g   = sub.grade;
          const gc  = g === null ? 'g-none' : parseFloat(g) >= 85 ? 'g-high' : parseFloat(g) >= 75 ? 'g-pass' : 'g-fail';
          const desc = g === null ? '—' : parseFloat(g) >= 90 ? 'Outstanding'
                     : parseFloat(g) >= 85 ? 'Very Satisfactory'
                     : parseFloat(g) >= 80 ? 'Satisfactory'
                     : parseFloat(g) >= 75 ? 'Fairly Satisfactory'
                     : 'Did Not Meet';
          html += `
            <div class="modal-subj-row">
              <div class="modal-subj-info">
                <div class="modal-subj-name">${esc(sub.subject_name)}</div>
                ${sub.teacher_name
                  ? `<div class="modal-subj-teacher"><i class="fas fa-user" style="font-size:9px;margin-right:3px;"></i>${esc(sub.teacher_name)}</div>`
                  : `<div class="modal-subj-teacher" style="color:#ccc;">Teacher info unavailable</div>`}
              </div>
              <div class="modal-subj-grade">
                <span class="gbadge ${gc}">${g !== null ? parseFloat(g).toFixed(2) : '—'}</span>
                <div style="font-size:10px;color:var(--text-3);text-align:right;margin-top:2px;">${desc}</div>
              </div>
            </div>`;
        });
      }

      html += `</div>`;
    });
  }

  document.getElementById('modal-body').innerHTML = html;
}

function infoItem(label, val) {
  return `<div class="modal-info-item">
    <div class="modal-info-label">${label}</div>
    <div class="modal-info-val">${esc(String(val))}</div>
  </div>`;
}

function closeModal() {
  document.getElementById('modal').classList.remove('open');
  document.body.style.overflow = '';
}

function closeModalOutside(e) {
  if (e.target === document.getElementById('modal')) closeModal();
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModal();
});

function esc(str) {
  return String(str || '').replace(/[&<>"']/g, m =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])
  );
}
</script>
</body>
</html>