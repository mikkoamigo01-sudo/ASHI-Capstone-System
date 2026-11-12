<?php
session_start();
if (!isset($_SESSION['teacher_id'])) { header('Location: login.php'); exit(); }
$teacher_id = $_SESSION['teacher_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../db_connection/ashi_dbcnnt.php';
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'get_subjects') {
        $grade   = $_POST['grade']   ?? '';
        $section = $_POST['section'] ?? '';
        $stmt = $conn->prepare("
            SELECT id, subject_name
            FROM subjects
            WHERE teacher_id=? AND grade=? AND section=? AND status='active'
            ORDER BY subject_name
        ");
        $stmt->bind_param("iss", $teacher_id, $grade, $section);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close(); $conn->close();
        echo json_encode(['success' => true, 'data' => $data]);
        exit();
    }

    if ($action === 'get_students') {
        $subject_id = intval($_POST['subject_id']);

        $s = $conn->prepare("SELECT id, subject_name, grade FROM subjects WHERE id=? AND teacher_id=?");
        $s->bind_param("ii", $subject_id, $teacher_id);
        $s->execute();
        $subject_row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$subject_row) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }

        $subject_name = $subject_row['subject_name'];
        $grade_level  = $subject_row['grade'];

        $sy_r = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
        $sy_r->execute();
        $sy = $sy_r->get_result()->fetch_assoc(); $sy_r->close();
        $school_year     = $sy['school_year'];
        $current_quarter = intval($sy['current_quarter']);

        // Load per-subject weights, fall back to grading_config defaults
        $wt_stmt = $conn->prepare("
            SELECT ww_percent, pt_percent, qa_percent
            FROM subject_component_weights
            WHERE subject_name = ? AND grade_level = ?
            LIMIT 1
        ");
        $wt_stmt->bind_param("ss", $subject_name, $grade_level);
        $wt_stmt->execute();
        $wt_row = $wt_stmt->get_result()->fetch_assoc();
        $wt_stmt->close();

        if ($wt_row) {
            $ww_pct    = floatval($wt_row['ww_percent']);
            $pt_pct    = floatval($wt_row['pt_percent']);
            $qa_pct    = floatval($wt_row['qa_percent']);
            $is_custom = true;
        } else {
            $cfg_r = $conn->query("SELECT * FROM grading_config WHERE id=1");
            $cfg   = $cfg_r ? $cfg_r->fetch_assoc() : [];
            $ww_pct    = floatval($cfg['ww_percent'] ?? 30);
            $pt_pct    = floatval($cfg['pt_percent'] ?? 50);
            $qa_pct    = floatval($cfg['qa_percent'] ?? 20);
            $is_custom = false;
        }

        $stmt = $conn->prepare("
            SELECT s.id, s.first_name, s.last_name
            FROM students s
            INNER JOIN student_subjects ss ON s.id=ss.student_id
                AND ss.subject_id=? AND ss.status='active'
            WHERE s.status='active'
            ORDER BY s.last_name, s.first_name
        ");
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Slot scores
        $slots_stmt = $conn->prepare("
            SELECT student_id, slot_type, slot_number, raw_score
            FROM stud_slot_scores
            WHERE subject_id=? AND school_year=? AND quarter=?
        ");
        $slots_stmt->bind_param("isi", $subject_id, $school_year, $current_quarter);
        $slots_stmt->execute();
        $all_slots = $slots_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $slots_stmt->close();

        $slotMap = [];
        foreach ($all_slots as $sl) {
            $slotMap[$sl['student_id']][$sl['slot_type']][$sl['slot_number']] = $sl['raw_score'];
        }

        // Max scores
        $max_stmt = $conn->prepare("
            SELECT slot_type, slot_number, max_score
            FROM slot_max_scores
            WHERE subject_id=? AND school_year=? AND quarter=?
        ");
        $max_stmt->bind_param("isi", $subject_id, $school_year, $current_quarter);
        $max_stmt->execute();
        $all_max = $max_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $max_stmt->close();

        $maxMap = [];
        foreach ($all_max as $m) {
            $maxMap[$m['slot_type']][$m['slot_number']] = $m['max_score'];
        }

        // Slot counts
        $sc_stmt = $conn->prepare("
            SELECT slot_type, slot_count
            FROM slot_counts
            WHERE subject_id=? AND school_year=? AND quarter=?
        ");
        $sc_stmt->bind_param("isi", $subject_id, $school_year, $current_quarter);
        $sc_stmt->execute();
        $sc_rows = $sc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sc_stmt->close();

        $slotCounts = ['WW' => 10, 'PT' => 10];
        foreach ($sc_rows as $scr) {
            $slotCounts[$scr['slot_type']] = intval($scr['slot_count']);
        }

        // Saved quarterly grades (is_overridden flag — no lock check)
        $student_ids = array_column($students, 'id');
        $savedGrades = [];
        if (!empty($student_ids)) {
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
            $types        = str_repeat('i', count($student_ids));
            $fg_stmt = $conn->prepare("
                SELECT student_id, grade, is_overridden
                FROM student_quarterly_grades
                WHERE subject_id=? AND school_year=? AND quarter=?
                AND student_id IN ($placeholders)
            ");
            $params     = array_merge([$subject_id, $school_year, $current_quarter], $student_ids);
            $types_full = 'isi' . $types;
            $fg_stmt->bind_param($types_full, ...$params);
            $fg_stmt->execute();
            $fg_rows = $fg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $fg_stmt->close();
            foreach ($fg_rows as $fr) {
                $savedGrades[$fr['student_id']] = [
                    'grade'         => $fr['grade'],
                    'is_overridden' => (bool)$fr['is_overridden'],
                ];
            }
        }

        foreach ($students as &$st) {
            $st['slots']         = $slotMap[$st['id']] ?? [];
            $st['saved_fg']      = $savedGrades[$st['id']]['grade']         ?? null;
            $st['is_overridden'] = $savedGrades[$st['id']]['is_overridden'] ?? false;
        }
        unset($st);

        $conn->close();
        echo json_encode([
            'success'         => true,
            'students'        => $students,
            'max_scores'      => $maxMap,
            'slot_counts'     => $slotCounts,
            'school_year'     => $school_year,
            'current_quarter' => $current_quarter,
            'ww_pct'          => $ww_pct,
            'pt_pct'          => $pt_pct,
            'qa_pct'          => $qa_pct,
            'is_custom'       => $is_custom,
            'subject_name'    => $subject_name,
            'grade_level'     => $grade_level,
        ]);
        exit();
    }

    if ($action === 'save_slot') {
        $student_id  = intval($_POST['student_id']);
        $subject_id  = intval($_POST['subject_id']);
        $slot_type   = $_POST['slot_type'];
        $slot_number = intval($_POST['slot_number']);
        $raw_score   = ($_POST['raw_score'] !== '') ? floatval($_POST['raw_score']) : null;

        if (!in_array($slot_type, ['WW','PT','QA'])) {
            echo json_encode(['success'=>false,'message'=>'Invalid type']); exit();
        }

        $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
        $s->bind_param("ii", $subject_id, $teacher_id);
        $s->execute(); $s->store_result();
        if ($s->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
        $s->close();

        $sy_r = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
        $sy_r->execute();
        $sy = $sy_r->get_result()->fetch_assoc(); $sy_r->close();
        $school_year     = $sy['school_year'];
        $current_quarter = intval($sy['current_quarter']);

        $stmt = $conn->prepare("
            INSERT INTO stud_slot_scores
                (student_id, subject_id, school_year, quarter, slot_type, slot_number, raw_score)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE raw_score=VALUES(raw_score), updated_at=CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("iisisid", $student_id, $subject_id, $school_year, $current_quarter, $slot_type, $slot_number, $raw_score);
        $ok = $stmt->execute();
        $stmt->close(); $conn->close();
        echo json_encode(['success' => $ok]);
        exit();
    }

    if ($action === 'save_max') {
        $subject_id  = intval($_POST['subject_id']);
        $slot_type   = $_POST['slot_type'];
        $slot_number = intval($_POST['slot_number']);
        $max_score   = ($_POST['max_score'] !== '') ? floatval($_POST['max_score']) : null;

        $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
        $s->bind_param("ii", $subject_id, $teacher_id);
        $s->execute(); $s->store_result();
        if ($s->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
        $s->close();

        $sy_r = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
        $sy_r->execute();
        $sy = $sy_r->get_result()->fetch_assoc(); $sy_r->close();
        $school_year     = $sy['school_year'];
        $current_quarter = intval($sy['current_quarter']);

        $stmt = $conn->prepare("
            INSERT INTO slot_max_scores
                (subject_id, school_year, quarter, slot_type, slot_number, max_score)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE max_score=VALUES(max_score)
        ");
        $stmt->bind_param("isisid", $subject_id, $school_year, $current_quarter, $slot_type, $slot_number, $max_score);
        $ok = $stmt->execute();
        $stmt->close(); $conn->close();
        echo json_encode(['success' => $ok]);
        exit();
    }

    if ($action === 'update_slot_count') {
        $subject_id = intval($_POST['subject_id']);
        $slot_type  = $_POST['slot_type'];
        $slot_count = intval($_POST['slot_count']);

        if (!in_array($slot_type, ['WW','PT']) || $slot_count < 1 || $slot_count > 30) {
            echo json_encode(['success'=>false,'message'=>'Invalid params']); exit();
        }

        $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
        $s->bind_param("ii", $subject_id, $teacher_id);
        $s->execute(); $s->store_result();
        if ($s->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
        $s->close();

        $sy_r = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
        $sy_r->execute();
        $sy = $sy_r->get_result()->fetch_assoc(); $sy_r->close();
        $school_year     = $sy['school_year'];
        $current_quarter = intval($sy['current_quarter']);

        $stmt = $conn->prepare("
            INSERT INTO slot_counts (subject_id, school_year, quarter, slot_type, slot_count)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE slot_count=VALUES(slot_count)
        ");
        $stmt->bind_param("isisi", $subject_id, $school_year, $current_quarter, $slot_type, $slot_count);
        $ok = $stmt->execute();
        $stmt->close();

        $chk = $conn->prepare("
            SELECT COUNT(*) as cnt FROM stud_slot_scores
            WHERE subject_id=? AND school_year=? AND quarter=? AND slot_type=? AND slot_number > ?
        ");
        $chk->bind_param("isisi", $subject_id, $school_year, $current_quarter, $slot_type, $slot_count);
        $chk->execute();
        $chk_r = $chk->get_result()->fetch_assoc(); $chk->close();
        $has_data = intval($chk_r['cnt']) > 0;

        $conn->close();
        echo json_encode(['success' => $ok, 'has_orphan_data' => $has_data]);
        exit();
    }

    // ── Save Final Grade ────────────────────────────────────────────────────────
    if ($action === 'save_final_grade') {
        $student_id    = intval($_POST['student_id']);
        $subject_id    = intval($_POST['subject_id']);
        $final_grade   = floatval($_POST['final_grade']);
        $is_overridden = intval($_POST['is_overridden'] ?? 0);

        $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
        $s->bind_param("ii", $subject_id, $teacher_id);
        $s->execute(); $s->store_result();
        if ($s->num_rows === 0) {
            echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit();
        }
        $s->close();

        $sy_r = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
        $sy_r->execute();
        $sy = $sy_r->get_result()->fetch_assoc(); $sy_r->close();
        $school_year     = $sy['school_year'];
        $current_quarter = intval($sy['current_quarter']);

        // Ensure is_overridden column exists (migration safety)
        $conn->query("
            ALTER TABLE student_quarterly_grades
            ADD COLUMN IF NOT EXISTS is_overridden TINYINT(1) DEFAULT 0
        ");

        $upsert = $conn->prepare("
            INSERT INTO student_quarterly_grades
                (student_id, subject_id, school_year, quarter, grade, is_overridden, saved_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                grade=VALUES(grade),
                is_overridden=VALUES(is_overridden),
                saved_by=VALUES(saved_by),
                saved_at=CURRENT_TIMESTAMP
        ");
        $upsert->bind_param("iisidii", $student_id, $subject_id, $school_year, $current_quarter, $final_grade, $is_overridden, $teacher_id);
        $ok = $upsert->execute();
        $upsert->close();

        // Keep student_subjects.final_grade in sync
        $upd = $conn->prepare("UPDATE student_subjects SET final_grade=? WHERE student_id=? AND subject_id=?");
        $upd->bind_param("dii", $final_grade, $student_id, $subject_id);
        $upd->execute(); $upd->close();

        $conn->close();
        echo json_encode(['success' => $ok]);
        exit();
    }

    // ── Reset Final Grade override (re-sync to computed QG) ─────────────────
    if ($action === 'reset_final_grade') {
        $student_id  = intval($_POST['student_id']);
        $subject_id  = intval($_POST['subject_id']);
        $computed_qg = floatval($_POST['computed_qg']);

        $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
        $s->bind_param("ii", $subject_id, $teacher_id);
        $s->execute(); $s->store_result();
        if ($s->num_rows === 0) {
            echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit();
        }
        $s->close();

        $sy_r = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
        $sy_r->execute();
        $sy = $sy_r->get_result()->fetch_assoc(); $sy_r->close();
        $school_year     = $sy['school_year'];
        $current_quarter = intval($sy['current_quarter']);

        $upsert = $conn->prepare("
            INSERT INTO student_quarterly_grades
                (student_id, subject_id, school_year, quarter, grade, is_overridden, saved_by)
            VALUES (?, ?, ?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE
                grade=VALUES(grade),
                is_overridden=0,
                saved_by=VALUES(saved_by),
                saved_at=CURRENT_TIMESTAMP
        ");
        $upsert->bind_param("iisidi", $student_id, $subject_id, $school_year, $current_quarter, $computed_qg, $teacher_id);
        $ok = $upsert->execute();
        $upsert->close();

        $upd = $conn->prepare("UPDATE student_subjects SET final_grade=? WHERE student_id=? AND subject_id=?");
        $upd->bind_param("dii", $computed_qg, $student_id, $subject_id);
        $upd->execute(); $upd->close();

        $conn->close();
        echo json_encode(['success' => $ok]);
        exit();
    }

    if ($action === 'fetch_work') {
        $student_id = intval($_POST['student_id']);
        $subject_id = intval($_POST['subject_id']);

        $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
        $s->bind_param("ii", $subject_id, $teacher_id);
        $s->execute(); $s->store_result();
        if ($s->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
        $s->close();

        $stmt = $conn->prepare("
            SELECT q.title, q.total_points, qa.score, qa.percentage, qa.status
            FROM quizzes q
            LEFT JOIN quiz_attempts qa ON q.id=qa.quiz_id AND qa.student_id=?
            WHERE q.subject_id=? AND q.status='published'
            ORDER BY q.created_at DESC
        ");
        $stmt->bind_param("ii", $student_id, $subject_id);
        $stmt->execute();
        $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT a.title, a.assignment_type, a.max_points, sub.score, sub.status
            FROM assignments a
            LEFT JOIN submissions sub ON a.id=sub.assignment_id AND sub.student_id=?
            WHERE a.subject_id=? AND a.status='published'
            ORDER BY a.assignment_type, a.created_at DESC
        ");
        $stmt->bind_param("ii", $student_id, $subject_id);
        $stmt->execute();
        $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $conn->close();
        echo json_encode(['success'=>true,'quizzes'=>$quizzes,'assignments'=>$assignments]);
        exit();
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    $conn->close(); exit();
}

require_once '../db_connection/ashi_dbcnnt.php';

// Ensure runtime tables exist
$conn->query("
    CREATE TABLE IF NOT EXISTS stud_slot_scores (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        student_id   INT NOT NULL,
        subject_id   INT NOT NULL,
        school_year  VARCHAR(20) NOT NULL,
        quarter      TINYINT NOT NULL,
        slot_type    ENUM('WW','PT','QA') NOT NULL,
        slot_number  TINYINT NOT NULL,
        raw_score    DECIMAL(6,2) DEFAULT NULL,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_slot (student_id, subject_id, school_year, quarter, slot_type, slot_number),
        INDEX idx_student (student_id),
        INDEX idx_subject (subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS slot_max_scores (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        subject_id  INT NOT NULL,
        school_year VARCHAR(20) NOT NULL,
        quarter     TINYINT NOT NULL,
        slot_type   ENUM('WW','PT','QA') NOT NULL,
        slot_number TINYINT NOT NULL,
        max_score   DECIMAL(6,2) DEFAULT NULL,
        UNIQUE KEY unique_max (subject_id, school_year, quarter, slot_type, slot_number),
        INDEX idx_subject (subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    ALTER TABLE student_quarterly_grades
    ADD COLUMN IF NOT EXISTS is_overridden TINYINT(1) DEFAULT 0
");

$sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
$sy_stmt->execute();
$sy_settings     = $sy_stmt->get_result()->fetch_assoc(); $sy_stmt->close();
$school_year     = $sy_settings['school_year']     ?? date('Y').'-'.(date('Y')+1);
$current_quarter = intval($sy_settings['current_quarter'] ?? 1);
$QUARTER_LABEL   = ['1st','2nd','3rd','4th'][$current_quarter - 1];

$cfg_r      = $conn->query("SELECT * FROM grading_config WHERE id=1");
$gcfg_r     = $cfg_r ? $cfg_r->fetch_assoc() : [];
$DEF_WW_PCT = floatval($gcfg_r['ww_percent'] ?? 30);
$DEF_PT_PCT = floatval($gcfg_r['pt_percent'] ?? 50);
$DEF_QA_PCT = floatval($gcfg_r['qa_percent'] ?? 20);

$stmt = $conn->prepare("SELECT fullname, advisory_grade, advisory_section, theme_color FROM teachers WHERE id=?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc(); $stmt->close();

$stmt = $conn->prepare("SELECT DISTINCT grade, section FROM subjects WHERE teacher_id=? AND status='active' ORDER BY grade, section");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$sections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$url_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : null;
$url_subject    = null;
if ($url_subject_id) {
    $s = $conn->prepare("SELECT id, subject_name, grade, section FROM subjects WHERE id=? AND teacher_id=? AND status='active'");
    $s->bind_param("ii", $url_subject_id, $teacher_id);
    $s->execute();
    $url_subject = $s->get_result()->fetch_assoc(); $s->close();
}

$theme = $teacher['theme_color'] ?? '#1e40af';
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="img/ashi.jpg" type="image/x-icon">
<title>Class Record — Ashi Amaya</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --brand:     <?= $theme ?>;
    --brand-dim: color-mix(in srgb, <?= $theme ?> 10%, transparent);
    --brand-mid: color-mix(in srgb, <?= $theme ?> 25%, transparent);
    --bg:        #f0f2f5;
    --surface:   #ffffff;
    --line:      #d0d7de;
    --line2:     #bbc4ce;
    --ink1:      #0f172a;
    --ink2:      #1e293b;
    --ink3:      #475569;
    --ink4:      #94a3b8;
    --ok:        #16a34a;
    --warn:      #d97706;
    --bad:       #dc2626;
    --ww:        #1d4ed8;
    --pt:        #6d28d9;
    --qa:        #0e7490;
    --fg:        #0f766e;
    --fg-over:   #b45309;
    --r:         4px;
    --mono:      'JetBrains Mono', monospace;
    --sans:      'DM Sans', sans-serif;
}
body { font-family: var(--sans); background: var(--bg); color: var(--ink1); font-size: 13px; }

input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; appearance: textfield; }

/* ── Topbar ── */
.topbar { position:sticky;top:0;z-index:100;height:44px;background:#fff;border-bottom:2px solid var(--brand);display:flex;align-items:center;padding:0 14px;gap:10px;box-shadow:0 1px 4px rgba(0,0,0,.08); }
.tb-logo { font-family:var(--mono);font-size:11px;font-weight:700;letter-spacing:1px;color:var(--brand);display:flex;align-items:center;gap:5px; }
.tb-dot  { width:7px;height:7px;border-radius:50%;background:var(--brand);animation:pulse 2s infinite; }
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.tb-div  { width:1px;height:18px;background:var(--line2); }
.tb-title { font-size:12px;font-weight:600;color:var(--ink2); }
.tb-badge { background:var(--bg);border:1px solid var(--line);border-radius:20px;padding:2px 10px;font-size:10px;color:var(--ink3);font-family:var(--mono);font-weight:500;display:flex;align-items:center;gap:4px; }
.tb-badge .hi { color:var(--brand);font-weight:700; }
.tb-right { margin-left:auto;display:flex;gap:7px;align-items:center; }
.tb-btn { display:flex;align-items:center;gap:5px;padding:5px 11px;border-radius:var(--r);border:1px solid var(--line2);background:#fff;font-size:11px;font-weight:600;color:var(--ink3);cursor:pointer;text-decoration:none;transition:all .15s; }
.tb-btn:hover { background:var(--bg);color:var(--brand);border-color:var(--brand); }
.save-strip { display:flex;align-items:center;gap:6px;font-size:10px;color:var(--ink4);font-family:var(--mono); }
.save-dot { width:6px;height:6px;border-radius:50%;background:var(--ok); }
.save-dot.dirty { background:var(--warn);animation:pulse 1s infinite; }

#weightBadge { transition:opacity .2s; }
#weightBadge.loading { opacity:.4; }

/* ── Filter Row ── */
.filter-row { display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 14px;background:#fff;border-bottom:1px solid var(--line); }
.fr-group { display:flex;align-items:center;gap:6px; }
.fr-label { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink4);white-space:nowrap;font-family:var(--mono); }
.fr-select { height:29px;padding:0 8px;border:1px solid var(--line2);border-radius:var(--r);background:#fff;color:var(--ink1);font-size:11px;font-family:var(--sans);outline:none;cursor:pointer;transition:border-color .15s; }
.fr-select:focus { border-color:var(--brand); }
.fr-select:disabled { opacity:.4;cursor:not-allowed; }
.fr-div { width:1px;height:20px;background:var(--line2); }

.weight-source { font-size:9px;font-family:var(--mono);padding:2px 7px;border-radius:20px;font-weight:600; }
.weight-source.custom  { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe; }
.weight-source.default { background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0; }

/* Subject banner */
.subj-banner { display:none;align-items:center;gap:10px;padding:5px 14px;background:var(--brand-dim);border-bottom:1px solid var(--brand-mid);font-size:11px; }
.subj-banner.show { display:flex; }
.sb-name { font-weight:700;color:var(--brand);font-size:12px; }
.sb-meta { color:var(--ink3);font-family:var(--mono);font-size:10px; }

/* QA Config Bar */
.qa-config-bar { display:none;align-items:center;gap:10px;background:#fffbeb;border-bottom:2px solid #fde68a;padding:7px 14px;flex-wrap:wrap; }
.qa-config-bar.show { display:flex; }
.qa-cfg-label { display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#92400e;font-family:var(--mono);white-space:nowrap; }
.qa-cfg-label i { color:var(--qa);font-size:12px; }
.qa-cfg-input-wrap { display:flex;align-items:center;gap:6px; }
.qa-cfg-input-wrap span { font-size:11px;color:var(--ink3);font-family:var(--mono);white-space:nowrap; }
.qa-cfg-input { width:72px;height:28px;padding:0 8px;border:1.5px solid #fcd34d;border-radius:var(--r);font-size:13px;font-weight:700;font-family:var(--mono);color:#92400e;background:#fff;text-align:center;outline:none;transition:border-color .12s,box-shadow .12s; }
.qa-cfg-input:focus { border-color:var(--qa);box-shadow:0 0 0 2px #fde68a; }
.qa-cfg-set-btn { height:28px;padding:0 12px;background:var(--qa);color:#fff;border:none;border-radius:var(--r);font-size:11px;font-weight:700;font-family:var(--sans);cursor:pointer;display:flex;align-items:center;gap:5px;transition:opacity .12s;white-space:nowrap; }
.qa-cfg-set-btn:hover { opacity:.85; }
.qa-cfg-set-btn:disabled { opacity:.5;cursor:not-allowed; }
.qa-cfg-status { font-size:10px;font-family:var(--mono);display:flex;align-items:center;gap:5px;font-weight:600; }
.qa-cfg-status.set   { color:var(--ok); }
.qa-cfg-status.unset { color:var(--warn); }
.qa-cfg-divider { width:1px;height:20px;background:#fcd34d;flex-shrink:0; }
.qa-cfg-hint { font-size:10px;color:#92400e;font-family:var(--mono);opacity:.7;white-space:nowrap; }

/* ── Page ── */
.page { padding:10px 14px 40px; }
.sheet-wrap { background:#fff;border:1px solid var(--line);border-radius:var(--r);overflow-x:auto;box-shadow:0 1px 4px rgba(0,0,0,.05); }

/* ── Grade Sheet Table ── */
.gs { border-collapse:collapse;width:max-content;min-width:100%;font-family:var(--mono);font-size:10px; }
.gs th, .gs td { border:1px solid #b8c4d0;padding:0;text-align:center;vertical-align:middle;white-space:nowrap; }

.gs thead .r1 th { background:#bdd7ee;color:#1f2d4e;font-weight:700;font-size:10px;padding:5px 4px;border:1px solid #8ea8c8; }
.gs thead .r1 th.h-ww { background:#bdd7ee; }
.gs thead .r1 th.h-pt { background:#e2d5f0; }
.gs thead .r1 th.h-qa { background:#c6e0f5; }
.gs thead .r1 th.h-fg { background:#ccfbf1; }

.gs thead .r2 th { background:#deeaf1;color:#2c3e6b;font-weight:600;font-size:9px;padding:3px 1px;border:1px solid #b8c4d0; }
.gs thead .r3 th { background:#edeff2;color:#5a6370;font-size:9px;font-weight:500;padding:2px 1px;border:1px solid #b8c4d0; }

.gs tbody .hps td { background:#fffde7;border:1px solid #d4c870; }
.gs tbody .hps td.hps-label { text-align:right;padding-right:6px;font-size:9px;font-weight:700;color:#5a5000;background:#fff9c4; }

.gs tbody .sec td { background:#d9dfe6;color:#2c3a4e;font-size:9px;font-weight:700;text-align:left;padding:3px 7px;border:1px solid #a8b4c0;letter-spacing:.4px;text-transform:uppercase; }

.gs tbody .sr td { background:#ffffff;height:22px;border:1px solid #c8d0da; }
.gs tbody .sr:nth-child(even) td { background:#f7f9fb; }
.gs tbody .sr:hover td { background:#eef4ff; }

td.rn-c  { width:22px;min-width:22px;font-size:9px;color:#9aabb8;padding:0 3px; }
td.act-c { width:28px;min-width:28px;padding:1px 3px; }
td.name-c { text-align:left!important;padding:0 7px;min-width:160px;max-width:210px;font-size:10px;font-weight:600;color:#1e293b; }

td.tot-c { background:#dce6f1!important;font-weight:700;font-size:9px;min-width:38px;padding:0 3px; }
td.ps-c  { background:#c6d9f1!important;font-weight:700;font-size:9px;color:#1a3a8f;min-width:46px;padding:0 3px; }
td.ws-c  { background:#c6efce!important;font-weight:700;font-size:9px;color:#1a5c2a;min-width:36px;padding:0 3px; }
td.ig-c  { background:#fff2cc!important;font-weight:700;font-size:10px;min-width:48px;padding:0 4px; }
td.qg-c  { background:#fce4d6!important;font-weight:700;font-size:10px;min-width:48px;padding:0 4px;color:#7f2000; }
td.qg-c.ok   { background:#c6efce!important;color:#1a5c2a!important; }
td.qg-c.warn { background:#fff2cc!important;color:#7a4a00!important; }
td.qg-c.bad  { background:#fce4d6!important;color:#7f2000!important; }
td.ig-c.ok   { color:#1a5c2a; }
td.ig-c.warn { color:#7a4a00; }
td.ig-c.bad  { color:#7f2000; }

/* ── Final Grade column ── */
td.fg-c {
    background:#f0fdfa!important;
    font-weight:700;
    min-width:72px;
    padding:1px 3px;
    border-left:2px solid #5eead4!important;
    text-align:center;
    position:relative;
}
td.fg-c.ok   { background:#d1fae5!important; }
td.fg-c.warn { background:#fef3c7!important; }
td.fg-c.bad  { background:#fee2e2!important; }
td.fg-c.over { background:#fef3c7!important;border-left:2px solid var(--fg-over)!important; }

th.h-fg {
    background:#ccfbf1!important;
    color:#0f766e!important;
    border-left:2px solid #5eead4!important;
}

/* FG inner layout */
.fg-inner { display:flex;flex-direction:column;align-items:center;gap:1px;padding:2px 0; }

/* The editable FG input — always enabled */
.fg-input {
    width:44px;
    height:18px;
    border:1.5px solid transparent;
    border-radius:3px;
    background:transparent;
    text-align:center;
    font-family:var(--mono);
    font-size:12px;
    font-weight:700;
    color:#065f46;
    padding:0;
    outline:none;
    transition:border-color .15s, background .15s;
    cursor:text;
}
.fg-input:hover:not(:focus) { border-color:#5eead4;background:rgba(94,234,212,.1); }
.fg-input:focus { border-color:var(--fg);background:#fff;box-shadow:0 0 0 2px rgba(15,118,110,.15); }
.fg-input.overridden { color:var(--fg-over); }

/* FG status/action row */
.fg-meta { display:flex;align-items:center;gap:3px;line-height:1; }
.fg-status {
    font-size:8px;
    font-family:var(--mono);
    font-weight:600;
    display:flex;
    align-items:center;
    gap:2px;
    white-space:nowrap;
}
.fg-status.saving  { color:var(--warn); }
.fg-status.saved   { color:var(--ok); }
.fg-status.error   { color:var(--bad); }
.fg-status.pending { color:var(--ink4); }
.fg-status.over    { color:var(--fg-over); }

/* Reset-to-QG button (shown only when overridden) */
.fg-reset-btn {
    display:none;
    align-items:center;
    justify-content:center;
    width:13px;height:13px;
    border:1px solid var(--fg-over);
    border-radius:2px;
    background:transparent;
    color:var(--fg-over);
    font-size:8px;
    cursor:pointer;
    padding:0;
    transition:all .12s;
    flex-shrink:0;
}
.fg-reset-btn:hover { background:var(--fg-over);color:#fff; }
.fg-reset-btn.show { display:flex; }

/* Score cell wrapper */
.sc-wrap { display:flex;align-items:center;justify-content:center;padding:0 2px;gap:0; }

/* Score inputs */
.si { width:28px;height:20px;border:none;outline:none;background:transparent;text-align:center;font-family:var(--mono);font-size:10px;font-weight:600;color:#1e293b;padding:0;display:block; }
.si:focus { background:#cfe2ff;border-radius:2px; }
.si:hover:not(:focus) { background:#e8f0fe; }
.si.dirty { color:#b45309; }
.si.saved { color:#166534; }
.si::placeholder { color:#c8d4dc;font-weight:400; }

/* /X limit label */
.lbl-limit { font-family:var(--mono);font-size:9px;font-weight:500;white-space:nowrap;line-height:1;margin-left:1px;transition:color .15s; }
.lbl-limit.unset { color:#c8d4dc; }
.lbl-limit.ww-set { color:var(--ww); }
.lbl-limit.pt-set { color:var(--pt); }
.lbl-limit.qa-set { color:var(--qa); }

/* HPS inputs */
.hi-inp { width:32px;height:18px;border:none;outline:none;background:transparent;text-align:center;font-family:var(--mono);font-size:9px;font-weight:700;color:#7a6200;padding:0;display:block;margin:auto; }
.hi-inp:focus { background:#fff3cd;border-radius:2px; }

/* Action button */
.act-btn { width:22px;height:20px;border:1px solid #c0ccda;border-radius:3px;background:#eef2f7;color:#506070;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:9px;padding:0;transition:all .12px; }
.act-btn:hover { background:var(--brand);color:#fff;border-color:var(--brand); }

/* Add/Remove slot buttons */
th.slot-add-th { background:#e8f4e8!important;border:1px dashed #7cb87c!important;min-width:28px;width:28px;padding:0!important;cursor:pointer;transition:background .15s; }
th.slot-add-th:hover { background:#c8e6c8!important; }
.slot-add-btn { width:100%;height:100%;min-height:20px;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#2e7d32;font-size:11px;font-weight:700;padding:0;transition:color .15s; }
.slot-add-btn:hover { color:#1b5e20; }
th.slot-rm-th { background:#fdecea!important;border:1px dashed #e57373!important;min-width:22px;width:22px;padding:0!important;cursor:pointer;transition:background .15s; }
th.slot-rm-th:hover { background:#f9c7c5!important; }
.slot-rm-btn { width:100%;height:100%;min-height:20px;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#c62828;font-size:11px;font-weight:700;padding:0;transition:color .15s; }
.slot-rm-btn:hover { color:#7f0000; }

/* Empty / Loader */
.xl-empty { display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;gap:10px;color:var(--ink4); }
.xl-empty i { font-size:34px; }
.xl-empty h3 { font-size:14px;font-weight:600;color:var(--ink3); }
.xl-empty p  { font-size:12px;text-align:center; }
.xl-loader { display:flex;justify-content:center;padding:50px;color:var(--brand);font-size:22px; }
.xl-loader i { animation:spin 1s linear infinite; }
@keyframes spin{to{transform:rotate(360deg)}}

/* Modal */
.overlay { position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);z-index:999;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .18s; }
.overlay.on { opacity:1;pointer-events:all; }
.modal { background:#fff;border-radius:8px;width:min(820px,95vw);max-height:82vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);transform:translateY(10px);transition:transform .18s;overflow:hidden; }
.overlay.on .modal { transform:translateY(0); }
.m-head { display:flex;align-items:center;justify-content:space-between;padding:12px 14px 10px;border-bottom:1px solid var(--line);background:var(--bg);flex-shrink:0; }
.m-head h3 { font-size:13px;font-weight:700;color:var(--ink1); }
.m-close { width:24px;height:24px;border-radius:50%;border:none;background:var(--line);color:var(--ink3);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:10px;transition:all .12s; }
.m-close:hover { background:var(--bad);color:#fff; }
.m-body { display:flex;gap:0;flex:1;overflow:hidden;min-height:0; }
.m-col { flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0; }
.m-col:first-child { border-right:1px solid var(--line); }
.m-col-head { display:flex;align-items:center;gap:6px;padding:8px 12px;background:var(--bg);border-bottom:1px solid var(--line);flex-shrink:0; }
.m-col-head span { font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink4);font-family:var(--mono); }
.m-col-head .m-count { background:var(--line);border-radius:10px;padding:1px 6px;font-size:9px;font-family:var(--mono);font-weight:700;color:var(--ink3); }
.m-col-list { flex:1;overflow-y:auto;padding:8px 10px;display:flex;flex-direction:column;gap:4px; }
.m-item { display:flex;flex-direction:column;gap:3px;padding:7px 8px;border:1px solid var(--line);border-radius:var(--r);background:#fff;transition:border-color .12s; }
.m-item:hover { border-color:var(--brand); }
.m-item-top { display:flex;justify-content:space-between;align-items:flex-start;gap:6px; }
.m-iname { font-size:10px;font-weight:600;color:var(--ink1);line-height:1.3;flex:1; }
.m-imeta { font-size:9px;color:var(--ink4);font-family:var(--mono);margin-top:1px; }
.m-score { font-family:var(--mono);font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;white-space:nowrap;flex-shrink:0; }
.m-score.ok   { background:#dcfce7;color:var(--ok); }
.m-score.warn { background:#fef3c7;color:var(--warn); }
.m-score.na   { background:var(--bg);color:var(--ink4); }
.m-none { font-size:11px;color:var(--ink4);padding:16px 0;text-align:center;width:100%; }

/* Confirm dialog */
.confirm-overlay { position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);z-index:1100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .15s; }
.confirm-overlay.on { opacity:1;pointer-events:all; }
.confirm-box { background:#fff;border-radius:8px;padding:20px 22px;width:min(340px,90vw);box-shadow:0 16px 48px rgba(0,0,0,.22);transform:scale(.96);transition:transform .15s; }
.confirm-overlay.on .confirm-box { transform:scale(1); }
.confirm-box h4 { font-size:13px;font-weight:700;margin-bottom:6px;color:var(--ink1); }
.confirm-box p  { font-size:11px;color:var(--ink3);line-height:1.5;margin-bottom:14px; }
.confirm-btns { display:flex;gap:8px;justify-content:flex-end; }
.confirm-btns button { padding:5px 14px;border-radius:var(--r);border:1px solid var(--line2);font-size:11px;font-weight:600;cursor:pointer;transition:all .12s; }
.cb-cancel { background:#fff;color:var(--ink3); }
.cb-cancel:hover { background:var(--bg); }
.cb-ok { background:var(--bad);color:#fff;border-color:var(--bad); }
.cb-ok:hover { opacity:.85; }

/* Toast */
.toast { position:fixed;bottom:16px;right:16px;z-index:9999;padding:7px 13px;border-radius:5px;font-size:11px;font-weight:600;background:var(--ink1);color:#fff;opacity:0;transform:translateY(6px);transition:all .18s;pointer-events:none;font-family:var(--mono); }
.toast.on { opacity:1;transform:translateY(0); }
.toast.ok  { background:var(--ok); }
.toast.err { background:var(--bad); }

@media print {
    .topbar,.filter-row,.subj-banner,.qa-config-bar,.act-btn,.toast,.slot-add-btn,.slot-rm-btn,.fg-reset-btn { display:none!important; }
    th.slot-add-th, th.slot-rm-th { display:none!important; }
    .page { padding:0; }
    .sheet-wrap { box-shadow:none;border:none; }
    .fg-status,.fg-meta { display:none!important; }
    .fg-input { border:none!important;background:transparent!important;box-shadow:none!important; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <div class="tb-logo"><div class="tb-dot"></div> ASHI AMAYA</div>
    <div class="tb-div"></div>
    <span class="tb-title">Class Record Sheet</span>
    <div class="tb-badge">
        <span class="hi"><?= $QUARTER_LABEL ?> Quarter</span>
        <span style="color:#ccc;">·</span>
        S.Y. <?= htmlspecialchars($school_year) ?>
    </div>
    <div class="tb-badge" id="weightBadge">
        <span style="color:var(--ww);font-weight:700;" id="wBadgeWW">WW <?= $DEF_WW_PCT ?>%</span>
        <span style="color:#ccc;">·</span>
        <span style="color:var(--pt);font-weight:700;" id="wBadgePT">PT <?= $DEF_PT_PCT ?>%</span>
        <span style="color:#ccc;">·</span>
        <span style="color:var(--qa);font-weight:700;" id="wBadgeQA">QA <?= $DEF_QA_PCT ?>%</span>
        <span class="weight-source default" id="wBadgeSource">default</span>
    </div>
    <div class="tb-right">
        <div class="save-strip">
            <div class="save-dot" id="saveDot"></div>
            <span id="saveStatus">All saved</span>
        </div>
        <button class="tb-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <a href="grade.php" class="tb-btn"><i class="fas fa-arrow-left"></i> Grades</a>
    </div>
</div>

<!-- FILTER ROW -->
<div class="filter-row">
    <div class="fr-group">
        <span class="fr-label">Section</span>
        <select class="fr-select" id="secSel" onchange="onSection()">
            <option value="">— Select —</option>
            <?php foreach ($sections as $s): ?>
                <option value="<?= htmlspecialchars($s['grade'].'|'.$s['section']) ?>"
                    <?php if ($url_subject && $url_subject['grade']===$s['grade'] && $url_subject['section']===$s['section']) echo 'selected'; ?>>
                    <?= htmlspecialchars($s['grade'].' - '.$s['section']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fr-group">
        <span class="fr-label">Subject</span>
        <select class="fr-select" id="subSel" onchange="onSubject()" disabled>
            <option value="">— Select —</option>
            <?php if ($url_subject): ?>
                <option value="<?= $url_subject['id'] ?>" selected><?= htmlspecialchars($url_subject['subject_name']) ?></option>
            <?php endif; ?>
        </select>
    </div>
    <div class="fr-div"></div>
    <div class="fr-group">
        <span class="fr-label">Quarter</span>
        <span style="font-family:var(--mono);font-size:11px;font-weight:700;color:var(--brand);">
            <?= $QUARTER_LABEL ?> &nbsp;·&nbsp; <?= htmlspecialchars($school_year) ?>
        </span>
    </div>
</div>

<!-- Subject banner -->
<div class="subj-banner" id="subjBanner">
    <i class="fas fa-book-open" style="color:var(--brand);font-size:12px;"></i>
    <span class="sb-name" id="sbName">—</span>
    <span class="sb-meta" id="sbMeta">—</span>
    <span style="margin-left:auto;font-size:10px;color:var(--ink4);">
        <i class="fas fa-eye" style="color:var(--brand);"></i> Click the eye button beside a student to view their quiz &amp; assignment records
        &nbsp;·&nbsp;
        <i class="fas fa-edit" style="color:var(--fg-over);"></i> Click the Final Grade cell to override it
    </span>
</div>

<!-- QA MAX SCORE CONFIG BAR -->
<div class="qa-config-bar" id="qaConfigBar">
    <span class="qa-cfg-label">
        <i class="fas fa-pen-ruler"></i>
        QA Max Score
    </span>
    <div class="qa-cfg-input-wrap">
        <span>Total items this quarter:</span>
        <input class="qa-cfg-input" type="number" id="qaMaxInput" min="1" max="999" step="1" placeholder="e.g. 50">
        <button class="qa-cfg-set-btn" id="qaMaxSetBtn" onclick="applyQAMax()">
            <i class="fas fa-check"></i> Set
        </button>
    </div>
    <div class="qa-cfg-divider"></div>
    <span class="qa-cfg-status unset" id="qaMaxStatus">
        <i class="fas fa-exclamation-circle"></i> Not set
    </span>
    <span class="qa-cfg-hint" id="qaMaxHint" style="display:none;">
        QA% = (raw ÷ max) × 100
    </span>
</div>

<div class="page">
    <div class="sheet-wrap" id="sheetWrap">
        <div class="xl-empty">
            <i class="fas fa-table"></i>
            <h3>No Subject Loaded</h3>
            <p>Select a section and subject above to load the class record.</p>
        </div>
    </div>
</div>

<!-- Work Modal -->
<div class="overlay" id="wModal">
    <div class="modal">
        <div class="m-head">
            <h3 id="wModalTitle">Activity Details</h3>
            <button class="m-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="m-body" id="wModalBody"></div>
    </div>
</div>

<!-- Confirm Dialog -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <h4 id="confirmTitle">Confirm</h4>
        <p id="confirmMsg"></p>
        <div class="confirm-btns">
            <button class="cb-cancel" onclick="closeConfirm()">Cancel</button>
            <button class="cb-ok" id="confirmOkBtn">OK</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* ══════════════════════════════════════════════════════
   STATE
══════════════════════════════════════════════════════ */
let WW_PCT = <?= $DEF_WW_PCT ?>;
let PT_PCT = <?= $DEF_PT_PCT ?>;
let QA_PCT = <?= $DEF_QA_PCT ?>;

const QTR_LBL = ['1st','2nd','3rd','4th'];

let curSubjectId = <?= $url_subject_id ?? 'null' ?>;
let students     = [];
let maxScores    = {};
let slotCounts   = { WW: 10, PT: 10 };

// { [studentId]: { computedQG: number|null } }
const fgState      = {};
const fgSaveTimers = {};

/* ══════════════════════════════════════════════════════
   UTILITIES
══════════════════════════════════════════════════════ */
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function post(data){
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    return fetch('', {method:'POST', body:fd}).then(r => r.json());
}

function toast(msg, type='ok'){
    const t = document.getElementById('toast');
    t.textContent = (type==='ok' ? '✓ ' : '✕ ') + msg;
    t.className   = 'toast on ' + type;
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('on'), 2600);
}

function setDirty(d){
    const dot = document.getElementById('saveDot');
    const st  = document.getElementById('saveStatus');
    dot.className  = 'save-dot' + (d ? ' dirty' : '');
    st.textContent = d ? 'Unsaved changes…' : 'All saved';
    st.style.color = d ? 'var(--warn)' : 'var(--ok)';
}

function updateWeightBadge(ww, pt, qa, isCustom){
    document.getElementById('wBadgeWW').textContent = 'WW ' + ww + '%';
    document.getElementById('wBadgePT').textContent = 'PT ' + pt + '%';
    document.getElementById('wBadgeQA').textContent = 'QA ' + qa + '%';
    const src = document.getElementById('wBadgeSource');
    src.textContent = isCustom ? 'custom' : 'default';
    src.className   = isCustom ? 'weight-source custom' : 'weight-source default';
    document.getElementById('weightBadge').classList.remove('loading');
}

/* ══════════════════════════════════════════════════════
   GRADE COMPUTATION — DepEd formula
══════════════════════════════════════════════════════ */
function computePS(slots, type){
    const mx    = maxScores[type] || {};
    const count = slotCounts[type] || 10;
    let sumRaw = 0, sumMax = 0, hasData = false;
    for(let i = 1; i <= count; i++){
        const raw = (slots[type] && slots[type][i] !== undefined && slots[type][i] !== null)
            ? parseFloat(slots[type][i]) : null;
        const max = (mx[i] !== undefined && mx[i] !== null) ? parseFloat(mx[i]) : null;
        if(raw !== null && max !== null && max > 0){ sumRaw += raw; sumMax += max; hasData = true; }
    }
    return hasData ? (sumRaw / sumMax) * 100 : null;
}

function computeQAPS(slots){
    const raw = (slots['QA'] && slots['QA'][1] !== undefined && slots['QA'][1] !== null)
        ? parseFloat(slots['QA'][1]) : null;
    const max = (maxScores['QA'] && maxScores['QA'][1] !== undefined && maxScores['QA'][1] !== null)
        ? parseFloat(maxScores['QA'][1]) : null;
    if(raw === null || max === null || max === 0) return null;
    return (raw / max) * 100;
}

function ws(ps, pct){ return ps === null ? null : ps * (pct / 100); }

/* ── FIXED: each PS is already 0–100; weighted score = PS × (weight/100) ── */
function computeIG(wwPS, ptPS, qaPS){
    let ig = 0, hasAny = false;
    if(wwPS !== null){ ig += wwPS * (WW_PCT / 100); hasAny = true; }
    if(ptPS !== null){ ig += ptPS * (PT_PCT / 100); hasAny = true; }
    if(qaPS !== null){ ig += qaPS * (QA_PCT / 100); hasAny = true; }
    return hasAny ? ig : null;
}

function transmute(ig){
    if(ig === null) return null;
    const T = [
        [100,100],[98.40,99],[96.80,98],[95.20,97],[93.60,96],[92.00,95],
        [90.40,94],[88.80,93],[87.20,92],[85.60,91],[84.00,90],[82.40,89],
        [80.80,88],[79.20,87],[77.60,86],[76.00,85],[74.40,84],[72.80,83],
        [71.20,82],[69.60,81],[68.00,80],[66.40,79],[64.80,78],[63.20,77],
        [61.60,76],[60.00,75],[56.00,74],[52.00,73],[48.00,72],[44.00,71],
        [40.00,70],[36.00,69],[32.00,68],[28.00,67],[24.00,66],[20.00,65],
        [16.00,64],[12.00,63],[8.00,62],[4.00,61],[0.00,60]
    ];
    for(const [mn, tr] of T) if(ig >= mn) return tr;
    return 60;
}

function gc(g){
    if(g === null || g === undefined || g === '') return '';
    g = parseFloat(g);
    return g >= 90 ? 'ok' : g >= 75 ? 'warn' : 'bad';
}

/* ══════════════════════════════════════════════════════
   QA CONFIG BAR
══════════════════════════════════════════════════════ */
function refreshQAConfigBar(){
    const bar    = document.getElementById('qaConfigBar');
    const input  = document.getElementById('qaMaxInput');
    const status = document.getElementById('qaMaxStatus');
    const hint   = document.getElementById('qaMaxHint');
    bar.classList.add('show');

    const current = (maxScores['QA'] && maxScores['QA'][1] !== undefined && maxScores['QA'][1] !== null)
        ? parseFloat(maxScores['QA'][1]) : null;

    if(current !== null && current > 0){
        input.value      = current;
        status.className = 'qa-cfg-status set';
        status.innerHTML = `<i class="fas fa-check-circle"></i> Max = ${current} &nbsp;·&nbsp; QA% = (raw ÷ ${current}) × 100`;
        hint.style.display = '';
    } else {
        input.value      = '';
        status.className = 'qa-cfg-status unset';
        status.innerHTML = '<i class="fas fa-exclamation-circle"></i> Not set — enter max items and click Set';
        hint.style.display = 'none';
    }
}

function applyQAMax(){
    const input = document.getElementById('qaMaxInput');
    const btn   = document.getElementById('qaMaxSetBtn');
    const val   = input.value !== '' ? parseInt(input.value) : null;
    if(!val || val <= 0){ toast('Enter a valid number > 0', 'err'); input.focus(); return; }
    if(!curSubjectId){ toast('No subject selected', 'err'); return; }

    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    post({ action:'save_max', subject_id:curSubjectId, slot_type:'QA', slot_number:1, max_score:val })
        .then(d => {
            if(d.success){
                if(!maxScores['QA']) maxScores['QA'] = {};
                maxScores['QA'][1] = val;
                const hpsEl = document.querySelector('.hi-inp[data-type="QA"][data-num="1"]');
                if(hpsEl) hpsEl.value = val;
                updateAllLabels('QA', 1, val);
                students.forEach(st => {
                    const inp = document.querySelector(`.si[data-type="QA"][data-num="1"][data-sid="${st.id}"]`);
                    if(inp){ inp.disabled = false; inp.style.opacity = ''; inp.style.cursor = ''; inp.placeholder = '—'; }
                });
                students.forEach(st => recompute(st));
                refreshQAConfigBar();
                toast(`QA max set to ${val}`, 'ok');
            } else { toast('Failed to save QA max', 'err'); }
        })
        .catch(() => toast('Network error', 'err'))
        .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Set'; });
}

/* ══════════════════════════════════════════════════════
   FINAL GRADE — computed only, read-only, auto-synced
══════════════════════════════════════════════════════ */

/**
 * Save Final Grade to DB (always computed QG, never overridden).
 * Debounced 700ms to avoid hammering DB on rapid score changes.
 */
function saveFinalGrade(studentId, fgValue){
    clearTimeout(fgSaveTimers[studentId]);
    fgSaveTimers[studentId] = setTimeout(() => {
        post({
            action:        'save_final_grade',
            subject_id:    curSubjectId,
            student_id:    studentId,
            final_grade:   fgValue,
            is_overridden: 0
        }).then(d => {
            if(!d.success) toast('Grade save failed', 'err');
        }).catch(() => toast('Network error', 'err'));
    }, 700);
}

/**
 * Called whenever the computed QG changes (on score input or load).
 * Updates the locked FG display cell and syncs to DB.
 */
function onQGChange(studentId, newQG){
    if(!fgState[studentId]) fgState[studentId] = {};
    fgState[studentId].computedQG = newQG;

    // Update the locked read-only display cell
    const cell = document.getElementById('fg-' + studentId);
    if(cell){
        const colorCls = newQG !== null ? gc(newQG) : '';
        cell.className = 'fg-c' + (colorCls ? ' ' + colorCls : '');
        cell.textContent = newQG !== null ? newQG : '—';
    }

    // Always persist computed QG to DB
    if(newQG !== null) saveFinalGrade(studentId, newQG);
}

/* ══════════════════════════════════════════════════════
   /X LABEL HELPERS
══════════════════════════════════════════════════════ */
function limitLabel(type, num, sid){
    const m = (maxScores[type] && maxScores[type][num] !== undefined && maxScores[type][num] !== null)
        ? parseInt(maxScores[type][num]) : null;
    const txt = m !== null ? '/' + m : '/—';
    const cls = m !== null ? (type==='WW'?'ww-set':type==='PT'?'pt-set':'qa-set') : 'unset';
    return `<span class="lbl-limit ${cls}" id="lbl-${type}-${num}-${sid}">${txt}</span>`;
}

function updateAllLabels(type, num, val){
    students.forEach(st => {
        const el = document.getElementById(`lbl-${type}-${num}-${st.id}`);
        if(!el) return;
        if(val !== null){ el.textContent = '/' + val; el.className = `lbl-limit ${type==='WW'?'ww-set':type==='PT'?'pt-set':'qa-set'}`; }
        else            { el.textContent = '/—';      el.className = 'lbl-limit unset'; }
    });
}

/* ══════════════════════════════════════════════════════
   SLOT COUNT CONTROLS
══════════════════════════════════════════════════════ */
function addSlot(type){
    if(slotCounts[type] >= 30){ toast('Max 30 slots', 'err'); return; }
    const newCount = slotCounts[type] + 1;
    post({ action:'update_slot_count', subject_id:curSubjectId, slot_type:type, slot_count:newCount })
        .then(d => { if(d.success){ slotCounts[type] = newCount; toast(`${type} slot ${newCount} added`, 'ok'); loadSheet(); } else toast('Failed','err'); })
        .catch(() => toast('Network error','err'));
}

function removeSlot(type){
    const current = slotCounts[type];
    if(current <= 1){ toast('Must have at least 1 slot','err'); return; }
    const newCount = current - 1;
    const hasData  = students.some(st => {
        const sl = st.slots || {};
        return sl[type] && sl[type][current] !== undefined && sl[type][current] !== null && sl[type][current] !== '';
    });
    const doRemove = () => {
        post({ action:'update_slot_count', subject_id:curSubjectId, slot_type:type, slot_count:newCount })
            .then(d => {
                if(d.success){ slotCounts[type] = newCount; if(maxScores[type]) delete maxScores[type][current]; toast(`${type} slot ${current} removed`,'ok'); loadSheet(); }
                else toast('Failed','err');
            }).catch(() => toast('Network error','err'));
    };
    if(hasData){
        showConfirm(`Remove ${type} #${current}?`,
            `A student has a score in ${type} #${current}. The data is NOT deleted — add the slot back to restore it.`,
            'Remove anyway', doRemove);
    } else { doRemove(); }
}

/* ══════════════════════════════════════════════════════
   CONFIRM DIALOG
══════════════════════════════════════════════════════ */
let _confirmCb = null;
function showConfirm(title, msg, okLabel, cb){
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMsg').textContent   = msg;
    document.getElementById('confirmOkBtn').textContent = okLabel;
    _confirmCb = cb;
    document.getElementById('confirmOverlay').classList.add('on');
}
function closeConfirm(){ document.getElementById('confirmOverlay').classList.remove('on'); _confirmCb = null; }
document.getElementById('confirmOkBtn').addEventListener('click', () => { if(_confirmCb) _confirmCb(); closeConfirm(); });
document.getElementById('confirmOverlay').addEventListener('click', e => { if(e.target===document.getElementById('confirmOverlay')) closeConfirm(); });

/* ══════════════════════════════════════════════════════
   DROPDOWNS
══════════════════════════════════════════════════════ */
function onSection(){
    const v   = document.getElementById('secSel').value;
    const sub = document.getElementById('subSel');
    sub.innerHTML = '<option value="">— Select —</option>';
    sub.disabled  = true;
    curSubjectId  = null;
    document.getElementById('qaConfigBar').classList.remove('show');
    renderEmpty();
    if(!v) return;
    const [g, s] = v.split('|');
    post({ action:'get_subjects', grade:g, section:s }).then(d => {
        if(!d.success || !d.data.length){ toast('No subjects found','err'); return; }
        d.data.forEach(item => sub.innerHTML += `<option value="${item.id}">${esc(item.subject_name)}</option>`);
        sub.disabled = false;
    }).catch(() => toast('Network error','err'));
}

function onSubject(){
    curSubjectId = document.getElementById('subSel').value || null;
    if(!curSubjectId){ document.getElementById('qaConfigBar').classList.remove('show'); renderEmpty(); return; }
    loadSheet();
}

window.addEventListener('DOMContentLoaded', () => {
    <?php if ($url_subject): ?>
    const sel = document.getElementById('secSel');
    const tgt = '<?= addslashes($url_subject['grade'].'|'.$url_subject['section']) ?>';
    for(let o of sel.options) if(o.value === tgt){ o.selected = true; break; }
    document.getElementById('subSel').disabled = false;
    document.getElementById('subSel').value    = '<?= $url_subject_id ?>';
    loadSheet();
    <?php endif; ?>
});

function renderEmpty(){
    document.getElementById('sheetWrap').innerHTML = '<div class="xl-empty"><i class="fas fa-table"></i><h3>No Subject Loaded</h3><p>Select a section and subject above.</p></div>';
    document.getElementById('subjBanner').classList.remove('show');
}

/* ══════════════════════════════════════════════════════
   LOAD SHEET
══════════════════════════════════════════════════════ */
function loadSheet(){
    document.getElementById('sheetWrap').innerHTML = '<div class="xl-loader"><i class="fas fa-spinner"></i></div>';
    document.getElementById('weightBadge').classList.add('loading');

    post({ action:'get_students', subject_id:curSubjectId }).then(d => {
        if(!d.success){
            document.getElementById('sheetWrap').innerHTML = `<div class="xl-empty"><i class="fas fa-exclamation-circle"></i><h3>Error</h3><p>${esc(d.message||'Failed')}</p></div>`;
            document.getElementById('weightBadge').classList.remove('loading');
            return;
        }

        students   = d.students;
        maxScores  = d.max_scores  || {};
        slotCounts = d.slot_counts || { WW:10, PT:10 };
        WW_PCT = parseFloat(d.ww_pct);
        PT_PCT = parseFloat(d.pt_pct);
        QA_PCT = parseFloat(d.qa_pct);

        updateWeightBadge(WW_PCT, PT_PCT, QA_PCT, !!d.is_custom);

        // Seed fgState with computedQG = null (will be set during renderSheet)
        students.forEach(st => {
            fgState[st.id] = { computedQG: null };
        });

        const subSel  = document.getElementById('subSel');
        const subName = subSel.options[subSel.selectedIndex]?.text || '—';
        const secSel  = document.getElementById('secSel');
        const secName = secSel.options[secSel.selectedIndex]?.text || '—';

        document.getElementById('subjBanner').classList.add('show');
        document.getElementById('sbName').textContent = subName;
        document.getElementById('sbMeta').textContent =
            secName + '  ·  ' + QTR_LBL[d.current_quarter - 1] + ' Quarter  ·  S.Y. ' + d.school_year +
            '  ·  ' + students.length + ' students' +
            '  ·  WW ' + WW_PCT + '% / PT ' + PT_PCT + '% / QA ' + QA_PCT + '%';

        refreshQAConfigBar();
        renderSheet();
    }).catch(() => {
        document.getElementById('sheetWrap').innerHTML = '<div class="xl-empty"><i class="fas fa-exclamation-circle"></i><h3>Network Error</h3></div>';
        document.getElementById('weightBadge').classList.remove('loading');
    });
}

/* ══════════════════════════════════════════════════════
   RENDER SHEET
══════════════════════════════════════════════════════ */
function renderSheet(){
    if(!students.length){
        document.getElementById('sheetWrap').innerHTML = '<div class="xl-empty"><i class="fas fa-users-slash"></i><h3>No Students</h3><p>No active students enrolled.</p></div>';
        return;
    }

    const wwCount  = slotCounts['WW'] || 10;
    const ptCount  = slotCounts['PT'] || 10;
    const wwSpan   = wwCount + 3;
    const ptSpan   = ptCount + 3;
    const qaMaxSet = !!(maxScores['QA'] && maxScores['QA'][1] !== undefined && maxScores['QA'][1] !== null && parseFloat(maxScores['QA'][1]) > 0);

    function totalMax(type){
        const count = type === 'WW' ? wwCount : ptCount;
        let t = 0, h = false;
        for(let i = 1; i <= count; i++){
            const v = (maxScores[type] && maxScores[type][i] !== undefined) ? parseFloat(maxScores[type][i]) : null;
            if(v !== null){ t += v; h = true; }
        }
        return h ? t.toFixed(0) : '';
    }
    function mv(type, num){
        return (maxScores[type] && maxScores[type][num] !== undefined && maxScores[type][num] !== null)
            ? maxScores[type][num] : '';
    }

    const wwTot = totalMax('WW'), ptTot = totalMax('PT');
    const qaMax = (maxScores['QA'] && maxScores['QA'][1] !== undefined) ? maxScores['QA'][1] : '';

    const wwRange = Array.from({length: wwCount}, (_,i) => i+1);
    const ptRange = Array.from({length: ptCount}, (_,i) => i+1);
    const f2 = v => v !== null ? v.toFixed(2) : '';

    let html = `<table class="gs" id="gsTable">
<thead>
  <tr class="r1">
    <th rowspan="3" style="min-width:22px;"></th>
    <th rowspan="3" style="min-width:28px;"></th>
    <th rowspan="3" style="min-width:165px;text-align:left;padding-left:6px;">LEARNERS' NAMES</th>
    <th colspan="${wwSpan}" class="h-ww">WRITTEN WORKS (${WW_PCT}%)</th>
    <th rowspan="3" class="slot-add-th h-ww" onclick="addSlot('WW')"><button class="slot-add-btn">＋</button></th>
    <th rowspan="3" class="slot-rm-th h-ww" onclick="removeSlot('WW')"><button class="slot-rm-btn">－</button></th>
    <th colspan="${ptSpan}" class="h-pt">PERFORMANCE TASKS (${PT_PCT}%)</th>
    <th rowspan="3" class="slot-add-th h-pt" onclick="addSlot('PT')"><button class="slot-add-btn" style="color:#5b21b6;">＋</button></th>
    <th rowspan="3" class="slot-rm-th h-pt" onclick="removeSlot('PT')"><button class="slot-rm-btn" style="color:#7c3aed;">－</button></th>
    <th colspan="3" class="h-qa">QUARTERLY ASSESSMENT (${QA_PCT}%)</th>
    <th rowspan="3" style="min-width:50px;background:#bdd7ee;font-size:9px;font-weight:700;">Initial<br>Grade</th>
    <th rowspan="3" style="min-width:50px;background:#bdd7ee;font-size:9px;font-weight:700;">Quarterly<br>Grade</th>
    <th rowspan="3" class="h-fg" style="min-width:78px;font-size:9px;font-weight:700;border-left:2px solid #5eead4;">Final<br>Grade</th>
  </tr>
  <tr class="r2">
    ${wwRange.map(n=>`<th style="min-width:52px;">${n}</th>`).join('')}
    <th style="min-width:40px;">Total</th><th style="min-width:48px;">PS</th><th style="min-width:38px;">WS</th>
    ${ptRange.map(n=>`<th style="min-width:52px;">${n}</th>`).join('')}
    <th style="min-width:40px;">Total</th><th style="min-width:48px;">PS</th><th style="min-width:38px;">WS</th>
    <th style="min-width:52px;">1</th><th style="min-width:48px;">PS</th><th style="min-width:38px;">WS</th>
  </tr>
  <tr class="r3">
    ${wwRange.map(()=>'<th style="font-size:8px;">HPS</th>').join('')}
    <th>${wwTot||'—'}</th><th style="color:var(--ww);">100.00</th><th style="color:var(--ok);">${WW_PCT}%</th>
    ${ptRange.map(()=>'<th style="font-size:8px;">HPS</th>').join('')}
    <th>${ptTot||'—'}</th><th style="color:var(--pt);">100.00</th><th style="color:var(--ok);">${PT_PCT}%</th>
    <th style="font-size:8px;">HPS</th><th style="color:var(--qa);">100.00</th><th style="color:var(--ok);">${QA_PCT}%</th>
  </tr>
</thead>
<tbody>
  <tr class="hps">
    <td></td><td></td>
    <td class="hps-label">HIGHEST POSSIBLE SCORE</td>
    ${wwRange.map(n=>`<td><input class="hi-inp" type="number" min="0" step="1" value="${mv('WW',n)}" data-type="WW" data-num="${n}" onchange="saveMax(this)"></td>`).join('')}
    <td class="tot-c" style="font-size:9px;">${wwTot}</td>
    <td class="ps-c" style="font-size:8px;">100.00</td>
    <td class="ws-c" style="font-size:8px;">${WW_PCT}%</td>
    <td></td><td></td>
    ${ptRange.map(n=>`<td><input class="hi-inp" type="number" min="0" step="1" value="${mv('PT',n)}" data-type="PT" data-num="${n}" onchange="saveMax(this)"></td>`).join('')}
    <td class="tot-c" style="font-size:9px;">${ptTot}</td>
    <td class="ps-c" style="font-size:8px;">100.00</td>
    <td class="ws-c" style="font-size:8px;">${PT_PCT}%</td>
    <td></td><td></td>
    <td><input class="hi-inp" type="number" min="0" step="1" value="${qaMax}" data-type="QA" data-num="1" onchange="saveMax(this)"></td>
    <td class="ps-c" style="font-size:8px;">100.00</td>
    <td class="ws-c" style="font-size:8px;">${QA_PCT}%</td>
    <td></td><td></td>
    <td class="fg-c" style="background:#e6fffa!important;font-size:8px;color:var(--fg);text-align:center;">Auto</td>
  </tr>`;

    function buildRows(label, group, offset){
        if(!group.length) return '';
        let rows = `<tr class="sec"><td colspan="${3 + wwSpan + 2 + ptSpan + 2 + 3 + 3}">${label} (${group.length})</td></tr>`;

        group.forEach((st, i) => {
            const sl = st.slots || {};

            // WW cells
            let wr = 0, wh = false;
            const ww = wwRange.map(n => {
                const v = (sl['WW'] && sl['WW'][n] !== undefined && sl['WW'][n] !== null) ? parseFloat(sl['WW'][n]) : null;
                if(v !== null){ wr += v; wh = true; }
                return `<td><div class="sc-wrap">
                    <input class="si${v!==null?' saved':''}" type="number" min="0" step="1" value="${v!==null?v:''}" placeholder="—"
                        data-sid="${st.id}" data-type="WW" data-num="${n}" oninput="onSC(this)" onblur="autosave(this)">
                    ${limitLabel('WW', n, st.id)}
                </div></td>`;
            }).join('');

            // PT cells
            let pr = 0, ph = false;
            const pt = ptRange.map(n => {
                const v = (sl['PT'] && sl['PT'][n] !== undefined && sl['PT'][n] !== null) ? parseFloat(sl['PT'][n]) : null;
                if(v !== null){ pr += v; ph = true; }
                return `<td><div class="sc-wrap">
                    <input class="si${v!==null?' saved':''}" type="number" min="0" step="1" value="${v!==null?v:''}" placeholder="—"
                        data-sid="${st.id}" data-type="PT" data-num="${n}" oninput="onSC(this)" onblur="autosave(this)">
                    ${limitLabel('PT', n, st.id)}
                </div></td>`;
            }).join('');

            // QA cell
            const qr = (sl['QA'] && sl['QA'][1] !== undefined && sl['QA'][1] !== null) ? parseFloat(sl['QA'][1]) : null;
            const qaDisabledAttr = qaMaxSet ? '' : 'disabled title="Set QA max score first"';

            // Compute grades
            const wwPS = computePS(sl, 'WW'), wwWS = ws(wwPS, WW_PCT);
            const ptPS = computePS(sl, 'PT'), ptWS = ws(ptPS, PT_PCT);
            const qaPS = computeQAPS(sl),     qaWS = ws(qaPS, QA_PCT);
            const ig   = computeIG(wwPS, ptPS, qaPS);
            const qg   = transmute(ig);

            // Seed fgState
            if(!fgState[st.id]) fgState[st.id] = {};
            fgState[st.id].computedQG = qg;

            // FG is always the computed QG — never stale saved_fg
            const fgDisplayVal = qg;
            const fgColorCls   = fgDisplayVal !== null ? gc(fgDisplayVal) : '';

            rows += `<tr class="sr" id="row-${st.id}">
                <td class="rn-c">${offset+i+1}</td>
                <td class="act-c"><button class="act-btn" onclick="viewWork(${st.id},'${esc(st.first_name+' '+st.last_name)}')" title="View quiz &amp; assignment details"><i class="fas fa-eye"></i></button></td>
                <td class="name-c">${esc(st.last_name)}, ${esc(st.first_name)}</td>
                ${ww}
                <td class="tot-c" id="ww-tot-${st.id}">${wh?wr.toFixed(0):''}</td>
                <td class="ps-c"  id="ww-ps-${st.id}">${f2(wwPS)}</td>
                <td class="ws-c"  id="ww-ws-${st.id}">${f2(wwWS)}</td>
                <td></td><td></td>
                ${pt}
                <td class="tot-c" id="pt-tot-${st.id}">${ph?pr.toFixed(0):''}</td>
                <td class="ps-c"  id="pt-ps-${st.id}">${f2(ptPS)}</td>
                <td class="ws-c"  id="pt-ws-${st.id}">${f2(ptWS)}</td>
                <td></td><td></td>
                <td><div class="sc-wrap">
                    <input class="si${qr!==null?' saved':''}" type="number" min="0" step="1"
                        value="${qr!==null?qr:''}" placeholder="${qaMaxSet?'—':'🔒'}"
                        data-sid="${st.id}" data-type="QA" data-num="1"
                        ${qaDisabledAttr}
                        oninput="onSC(this)" onblur="autosave(this)"
                        style="${qaMaxSet?'':'opacity:.4;cursor:not-allowed;'}">
                    ${limitLabel('QA', 1, st.id)}
                </div></td>
                <td class="ps-c"  id="qa-ps-${st.id}">${f2(qaPS)}</td>
                <td class="ws-c"  id="qa-ws-${st.id}">${f2(qaWS)}</td>
                <td class="ig-c ${gc(ig)}" id="ig-${st.id}">${ig!==null?ig.toFixed(2):''}</td>
                <td class="qg-c ${gc(qg)}" id="qg-${st.id}">${qg!==null?qg:''}</td>
                <td class="fg-c ${fgColorCls}" id="fg-${st.id}"
                    style="text-align:center;font-weight:700;font-family:var(--mono);font-size:13px;letter-spacing:.5px;cursor:default;user-select:none;">
                    ${fgDisplayVal !== null ? fgDisplayVal : '—'}
                </td>
            </tr>`;

            // Always sync computed QG to DB on every load (clears any stale value)
            if(qg !== null){
                setTimeout(() => saveFinalGrade(st.id, qg), 500 + i * 60);
            }
        });
        return rows;
    }

    html += buildRows('STUDENTS', students, 0);
    html += '</tbody></table>';
    document.getElementById('sheetWrap').innerHTML = html;
}

/* ══════════════════════════════════════════════════════
   INPUT HANDLERS (WW / PT / QA score cells)
══════════════════════════════════════════════════════ */
function onSC(input){
    if(input.value.includes('.')) input.value = Math.round(parseFloat(input.value));

    const sid  = input.dataset.sid;
    const type = input.dataset.type;
    const num  = parseInt(input.dataset.num);
    let val    = input.value !== '' ? parseInt(input.value) : null;

    const maxV = (maxScores[type] && maxScores[type][num] !== undefined) ? parseInt(maxScores[type][num]) : null;
    if(val !== null && maxV !== null && val > maxV){ val = maxV; input.value = maxV; }

    input.classList.add('dirty'); input.classList.remove('saved');
    setDirty(true);

    const st = students.find(s => s.id == sid);
    if(st){ if(!st.slots) st.slots={}; if(!st.slots[type]) st.slots[type]={}; st.slots[type][num] = val; recompute(st); }
}

function recompute(st){
    const sl      = st.slots || {};
    const sid     = st.id;
    const wwCount = slotCounts['WW'] || 10;
    const ptCount = slotCounts['PT'] || 10;
    const f2      = v => v !== null ? v.toFixed(2) : '';

    let wr=0, wh=false;
    for(let i=1;i<=wwCount;i++){
        const v=(sl['WW']&&sl['WW'][i]!==undefined&&sl['WW'][i]!==null)?parseFloat(sl['WW'][i]):null;
        if(v!==null){wr+=v;wh=true;}
    }
    let pr=0, ph=false;
    for(let i=1;i<=ptCount;i++){
        const v=(sl['PT']&&sl['PT'][i]!==undefined&&sl['PT'][i]!==null)?parseFloat(sl['PT'][i]):null;
        if(v!==null){pr+=v;ph=true;}
    }

    const wwPS = computePS(sl, 'WW'), wwWS = ws(wwPS, WW_PCT);
    const ptPS = computePS(sl, 'PT'), ptWS = ws(ptPS, PT_PCT);
    const qaPS = computeQAPS(sl),     qaWS = ws(qaPS, QA_PCT);
    const ig   = computeIG(wwPS, ptPS, qaPS);
    const qg   = transmute(ig);

    const set  = (id, v)      => { const e=document.getElementById(id); if(e) e.textContent=v; };
    const setC = (id, cls, v) => {
        const e = document.getElementById(id); if(!e) return;
        e.textContent = v;
        e.className   = e.className.replace(/\b(ok|warn|bad)\b/g,'').trim() + (cls ? ' '+cls : '');
    };

    set('ww-tot-'+sid, wh ? wr.toFixed(0) : '');
    set('ww-ps-'+sid,  f2(wwPS));
    set('ww-ws-'+sid,  f2(wwWS));
    set('pt-tot-'+sid, ph ? pr.toFixed(0) : '');
    set('pt-ps-'+sid,  f2(ptPS));
    set('pt-ws-'+sid,  f2(ptWS));
    set('qa-ps-'+sid,  f2(qaPS));
    set('qa-ws-'+sid,  f2(qaWS));
    setC('ig-'+sid, gc(ig), ig !== null ? ig.toFixed(2) : '');
    setC('qg-'+sid, gc(qg), qg !== null ? qg : '');

    // Update locked FG cell and sync to DB
    onQGChange(sid, qg);
}

function autosave(input){
    if(!input.classList.contains('dirty')) return;
    post({
        action:      'save_slot',
        subject_id:  curSubjectId,
        student_id:  input.dataset.sid,
        slot_type:   input.dataset.type,
        slot_number: input.dataset.num,
        raw_score:   input.value
    }).then(d => {
        if(d.success){ input.classList.remove('dirty'); input.classList.add('saved'); setDirty(false); }
        else toast('Save failed','err');
    }).catch(() => toast('Network error','err'));
}

function saveMax(input){
    if(input.value.includes('.')) input.value = Math.round(parseFloat(input.value));
    const type = input.dataset.type;
    const num  = parseInt(input.dataset.num);
    const val  = input.value !== '' ? parseInt(input.value) : null;

    if(!maxScores[type]) maxScores[type] = {};
    maxScores[type][num] = val;
    updateAllLabels(type, num, val);

    post({ action:'save_max', subject_id:curSubjectId, slot_type:type, slot_number:num, max_score:val!==null?val:'' })
        .then(d => {
            if(d.success){
                toast(`HPS for ${type} #${num} set to ${val !== null ? val : '—'}`, 'ok');
                if(type === 'QA' && num === 1){
                    const cfgInput = document.getElementById('qaMaxInput');
                    if(cfgInput) cfgInput.value = val !== null ? val : '';
                    const qaMaxNowSet = val !== null && val > 0;
                    students.forEach(st => {
                        const inp = document.querySelector(`.si[data-type="QA"][data-num="1"][data-sid="${st.id}"]`);
                        if(!inp) return;
                        inp.disabled      = !qaMaxNowSet;
                        inp.style.opacity = qaMaxNowSet ? '' : '.4';
                        inp.style.cursor  = qaMaxNowSet ? '' : 'not-allowed';
                        inp.placeholder   = qaMaxNowSet ? '—' : '🔒';
                    });
                    refreshQAConfigBar();
                }
                students.forEach(st => recompute(st));
                loadSheet();
            } else toast('Failed to save HPS','err');
        }).catch(() => toast('Network error','err'));
}

/* ══════════════════════════════════════════════════════
   WORK MODAL
══════════════════════════════════════════════════════ */
function viewWork(sid, name){
    document.getElementById('wModalTitle').textContent = name;
    document.getElementById('wModalBody').innerHTML    = '<div style="flex:1;display:flex;justify-content:center;align-items:center;color:var(--brand);font-size:22px;padding:40px;"><i class="fas fa-spinner" style="animation:spin 1s linear infinite;"></i></div>';
    document.getElementById('wModal').classList.add('on');

    post({ action:'fetch_work', student_id:sid, subject_id:curSubjectId }).then(d => {
        if(!d.success){ document.getElementById('wModalBody').innerHTML='<div style="flex:1;display:flex;justify-content:center;align-items:center;padding:20px;"><p class="m-none">Error loading.</p></div>'; return; }

        let qHtml = '';
        if(!d.quizzes.length){ qHtml = '<p class="m-none">No published quizzes.</p>'; }
        else d.quizzes.forEach(q => {
            const has  = q.score !== null;
            const sc   = has ? `${parseFloat(q.score).toFixed(1)} / ${parseFloat(q.total_points).toFixed(1)}` : 'Not taken';
            const pct  = has && q.percentage !== null ? `${parseFloat(q.percentage).toFixed(1)}%` : '';
            const cls  = !has ? 'na' : parseFloat(q.percentage) >= 75 ? 'ok' : 'warn';
            const sb   = q.status ? `<span style="padding:1px 5px;border-radius:3px;margin-left:4px;background:${q.status==='graded'?'#dcfce7':q.status==='submitted'?'#fef3c7':'#f1f5f9'};color:${q.status==='graded'?'var(--ok)':q.status==='submitted'?'var(--warn)':'var(--ink4)'};">${q.status}</span>` : '';
            qHtml += `<div class="m-item"><div class="m-item-top"><div class="m-iname">${esc(q.title)}</div><span class="m-score ${cls}">${pct||sc}</span></div><div class="m-imeta">Total: ${q.total_points} pts · ${sc}${sb}</div></div>`;
        });

        let aHtml = '';
        if(!d.assignments.length){ aHtml = '<p class="m-none">No published assignments.</p>'; }
        else d.assignments.forEach(a => {
            const has  = a.score !== null;
            const sc   = has ? `${parseFloat(a.score).toFixed(1)} / ${parseFloat(a.max_points).toFixed(1)}` : 'Not submitted';
            const pct  = has ? (parseFloat(a.score)/parseFloat(a.max_points)*100) : null;
            const cls  = !has ? 'na' : pct >= 75 ? 'ok' : 'warn';
            const tb   = `<span style="padding:1px 5px;border-radius:3px;margin-left:3px;background:var(--brand-dim);color:var(--brand);">${a.assignment_type}</span>`;
            const sb   = a.status ? `<span style="padding:1px 5px;border-radius:3px;margin-left:2px;background:${a.status==='graded'?'#dcfce7':a.status==='submitted'?'#fef3c7':'#f1f5f9'};color:${a.status==='graded'?'var(--ok)':a.status==='submitted'?'var(--warn)':'var(--ink4)'};">${a.status}</span>` : '';
            aHtml += `<div class="m-item"><div class="m-item-top"><div class="m-iname">${esc(a.title)}</div><span class="m-score ${cls}">${pct!==null?pct.toFixed(1)+'%':sc}</span></div><div class="m-imeta">Max: ${a.max_points} pts · ${sc}${tb}${sb}</div></div>`;
        });

        document.getElementById('wModalBody').innerHTML = `
            <div class="m-col">
                <div class="m-col-head"><i class="fas fa-question-circle" style="color:var(--ww);font-size:11px;"></i><span>Quizzes</span><span class="m-count">${d.quizzes.length}</span></div>
                <div class="m-col-list">${qHtml}</div>
            </div>
            <div class="m-col">
                <div class="m-col-head"><i class="fas fa-tasks" style="color:var(--pt);font-size:11px;"></i><span>Assignments</span><span class="m-count">${d.assignments.length}</span></div>
                <div class="m-col-list">${aHtml}</div>
            </div>`;
    }).catch(() => { document.getElementById('wModalBody').innerHTML='<p class="m-none" style="padding:20px;">Network error.</p>'; });
}

function closeModal(){ document.getElementById('wModal').classList.remove('on'); }
document.getElementById('wModal').addEventListener('click', e => { if(e.target===document.getElementById('wModal')) closeModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape'){ closeModal(); closeConfirm(); } });
</script>
</body>
</html>