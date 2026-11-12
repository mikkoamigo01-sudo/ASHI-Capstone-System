<?php
session_start();
if (!isset($_SESSION['teacher_id'])) { header('Location: login.php'); exit(); }
$teacher_id = $_SESSION['teacher_id'];

require_once '../db_connection/ashi_dbcnnt.php';

// ── Ensure tables exist ──────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS grading_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ww_percent  DECIMAL(5,2) NOT NULL DEFAULT 30.00,
        pt_percent  DECIMAL(5,2) NOT NULL DEFAULT 50.00,
        qa_percent  DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    INSERT INTO grading_config (id, ww_percent, pt_percent, qa_percent)
    SELECT 1, 30, 50, 20 FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM grading_config WHERE id = 1)
");

$conn->query("
    CREATE TABLE IF NOT EXISTS subject_component_weights (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        subject_name VARCHAR(150) NOT NULL,
        grade_level  VARCHAR(50)  NOT NULL,
        ww_percent   DECIMAL(5,2) NOT NULL DEFAULT 30.00,
        pt_percent   DECIMAL(5,2) NOT NULL DEFAULT 50.00,
        qa_percent   DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_subject_grade (subject_name, grade_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS qa_config (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        subject_id   INT NOT NULL,
        school_year  VARCHAR(20) NOT NULL,
        quarter      TINYINT NOT NULL,
        max_score    DECIMAL(5,2) NOT NULL DEFAULT 100.00,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_qa_cfg (subject_id, school_year, quarter),
        INDEX idx_subject (subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS student_qa_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        school_year VARCHAR(20) NOT NULL,
        quarter TINYINT NOT NULL,
        qa_score DECIMAL(5,2) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_qa (student_id, subject_id, school_year, quarter),
        INDEX idx_student (student_id),
        INDEX idx_subject (subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS subject_logical_groups (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        grade_level     VARCHAR(50)   NOT NULL,
        display_name    VARCHAR(150)  NOT NULL,
        weight_percent  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
        sort_order      INT           NOT NULL DEFAULT 0,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_group (grade_level, display_name),
        INDEX idx_grade (grade_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS subject_logical_group_aliases (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        group_id     INT           NOT NULL,
        subject_name VARCHAR(150)  NOT NULL,
        FOREIGN KEY (group_id) REFERENCES subject_logical_groups(id) ON DELETE CASCADE,
        UNIQUE KEY unique_alias (group_id, subject_name),
        INDEX idx_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Fetch global grading config (fallback only) ───────────
$gcfg   = $conn->query("SELECT * FROM grading_config WHERE id=1")->fetch_assoc();
$WW_PCT = floatval($gcfg['ww_percent'] ?? 30);
$PT_PCT = floatval($gcfg['pt_percent'] ?? 50);
$QA_PCT = floatval($gcfg['qa_percent'] ?? 20);

// ══════════════════════════════════════════════════════════
// HELPER: Get per-subject WW/PT/QA weights
// ══════════════════════════════════════════════════════════
function getSubjectWeights($conn, $subject_name, $grade_level) {
    $stmt = $conn->prepare("
        SELECT ww_percent, pt_percent, qa_percent
        FROM subject_component_weights
        WHERE subject_name = ? AND grade_level = ?
    ");
    $stmt->bind_param("ss", $subject_name, $grade_level);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return [
            'ww'      => floatval($row['ww_percent']),
            'pt'      => floatval($row['pt_percent']),
            'qa'      => floatval($row['qa_percent']),
            'is_custom' => true
        ];
    }

    $cfg = $conn->query("SELECT * FROM grading_config WHERE id=1")->fetch_assoc();
    return [
        'ww'      => floatval($cfg['ww_percent'] ?? 30),
        'pt'      => floatval($cfg['pt_percent'] ?? 50),
        'qa'      => floatval($cfg['qa_percent'] ?? 20),
        'is_custom' => false
    ];
}

// ══════════════════════════════════════════════════════════
// HELPER: Build subject weight map for a grade level
// ══════════════════════════════════════════════════════════
function buildSubjectWeightMap($conn, $grade_level, $subject_names) {
    if (empty($subject_names)) return [];

    $stmt = $conn->prepare("
        SELECT g.id, g.weight_percent
        FROM subject_logical_groups g
        WHERE g.grade_level = ?
    ");
    $stmt->bind_param("s", $grade_level);
    $stmt->execute();
    $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($groups)) {
        $share = 100.0 / count($subject_names);
        $map = [];
        foreach ($subject_names as $name) $map[strtolower($name)] = $share;
        return $map;
    }

    $groupIds     = array_column($groups, 'id');
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $types        = str_repeat('i', count($groupIds));
    $stmt         = $conn->prepare("
        SELECT group_id, subject_name
        FROM subject_logical_group_aliases
        WHERE group_id IN ($placeholders)
    ");
    $stmt->bind_param($types, ...$groupIds);
    $stmt->execute();
    $aliasRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $groupWeightById = array_column($groups, 'weight_percent', 'id');
    $aliasToWeight   = [];
    foreach ($aliasRows as $row) {
        $aliasToWeight[strtolower($row['subject_name'])] = floatval($groupWeightById[$row['group_id']]);
    }

    $map = [];
    $unconfigured       = [];
    $configuredWeightSum = 0;

    foreach ($subject_names as $name) {
        $key = strtolower($name);
        if (isset($aliasToWeight[$key])) {
            $map[$key] = $aliasToWeight[$key];
            $configuredWeightSum += $aliasToWeight[$key];
        } else {
            $unconfigured[] = $key;
        }
    }

    if (!empty($unconfigured)) {
        $remaining = max(0, 100.0 - $configuredWeightSum);
        $share     = count($unconfigured) > 0 ? $remaining / count($unconfigured) : 0;
        foreach ($unconfigured as $key) $map[$key] = $share;
    }

    return $map;
}

// ══════════════════════════════════════════════════════════
// HELPER: Compute weighted overall grade from subject grades
// ══════════════════════════════════════════════════════════
function computeWeightedOverall($subjectGrades, $weightMap) {
    if (empty($subjectGrades) || empty($weightMap)) return null;
    $weightedSum = 0;
    $totalWeight = 0;
    foreach ($subjectGrades as $sg) {
        if ($sg['grade'] === null) continue;
        $key    = strtolower($sg['subject_name']);
        $weight = $weightMap[$key] ?? 0;
        if ($weight <= 0) continue;
        $weightedSum += floatval($sg['grade']) * ($weight / 100);
        $totalWeight += $weight;
    }
    if ($totalWeight <= 0) return null;
    return (int) round(($weightedSum / $totalWeight) * 100);
}

// ══════════════════════════════════════════════════════════
// HELPER: Compute final grade
// ══════════════════════════════════════════════════════════
function computeFinalGrade($ww_score, $pt_score, $qa_pct, $WW_PCT, $PT_PCT, $QA_PCT) {
    $ww  = ($ww_score !== null) ? floatval($ww_score) : null;
    $pt  = ($pt_score !== null) ? floatval($pt_score) : null;
    $qa  = ($qa_pct   !== null) ? floatval($qa_pct)   : null;
    $total_weight = 0;
    $weighted_sum = 0;
    if ($ww !== null) { $weighted_sum += $ww * ($WW_PCT / 100); $total_weight += $WW_PCT; }
    if ($pt !== null) { $weighted_sum += $pt * ($PT_PCT / 100); $total_weight += $PT_PCT; }
    if ($qa !== null) { $weighted_sum += $qa * ($QA_PCT / 100); $total_weight += $QA_PCT; }
    if ($total_weight == 0) return null;
    return round(($weighted_sum / $total_weight) * 100, 2);
}

// ══════════════════════════════════════════════════════════
// HELPER: Save quarterly grade
// ══════════════════════════════════════════════════════════
function saveQuarterlyGrade($conn, $student_id, $subject_id, $final_grade, $teacher_id) {
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    if (!$sy) return false;
    $school_year     = $sy['school_year'];
    $current_quarter = intval($sy['current_quarter']);
    $check = $conn->prepare("SELECT id, is_locked FROM student_quarterly_grades WHERE student_id=? AND subject_id=? AND school_year=? AND quarter=?");
    $check->bind_param("iisi", $student_id, $subject_id, $school_year, $current_quarter);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();
    if ($row && $row['is_locked']) return false;
    $upsert = $conn->prepare("
        INSERT INTO student_quarterly_grades (student_id, subject_id, school_year, quarter, grade, saved_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE grade=VALUES(grade), saved_by=VALUES(saved_by), saved_at=CURRENT_TIMESTAMP
    ");
    $upsert->bind_param("iisidd", $student_id, $subject_id, $school_year, $current_quarter, $final_grade, $teacher_id);
    $ok = $upsert->execute();
    $upsert->close();
    return $ok;
}

// ══════════════════════════════════════════════════════════
// AJAX: Get per-subject weights
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_subject_weights') {
    header('Content-Type: application/json');
    $subject_id = intval($_POST['subject_id']);
    $stmt = $conn->prepare("SELECT subject_name, grade FROM subjects WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $subject_id, $teacher_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sub) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); $conn->close(); exit(); }
    $weights = getSubjectWeights($conn, $sub['subject_name'], $sub['grade']);
    echo json_encode([
        'success'   => true,
        'ww'        => $weights['ww'],
        'pt'        => $weights['pt'],
        'qa'        => $weights['qa'],
        'is_custom' => $weights['is_custom'],
        'subject_name' => $sub['subject_name'],
        'grade_level'  => $sub['grade']
    ]);
    $conn->close(); exit();
}

// ══════════════════════════════════════════════════════════
// AJAX: Save QA max score
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_qa_max') {
    header('Content-Type: application/json');
    $subject_id = intval($_POST['subject_id']);
    $max_score  = floatval($_POST['max_score']);
    if ($max_score <= 0) { echo json_encode(['success'=>false,'message'=>'Max score must be greater than 0']); exit(); }
    $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
    $s->bind_param("ii", $subject_id, $teacher_id);
    $s->execute(); $s->store_result();
    if ($s->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
    $s->close();
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year     = $sy['school_year'];
    $current_quarter = intval($sy['current_quarter']);
    $stmt = $conn->prepare("
        INSERT INTO qa_config (subject_id, school_year, quarter, max_score)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE max_score=VALUES(max_score), updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("isid", $subject_id, $school_year, $current_quarter, $max_score);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'max_score' => $max_score]);
    $conn->close(); exit();
}

// ══════════════════════════════════════════════════════════
// AJAX: Save QA raw score for a student
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_qa') {
    header('Content-Type: application/json');
    $student_id = intval($_POST['student_id']);
    $subject_id = intval($_POST['subject_id']);
    $qa_raw     = ($_POST['qa_score'] !== '' && $_POST['qa_score'] !== null) ? floatval($_POST['qa_score']) : null;
    $s = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
    $s->bind_param("ii", $subject_id, $teacher_id);
    $s->execute(); $s->store_result();
    if ($s->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
    $s->close();
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year     = $sy['school_year'];
    $current_quarter = intval($sy['current_quarter']);
    $stmt = $conn->prepare("
        INSERT INTO student_qa_scores (student_id, subject_id, school_year, quarter, qa_score)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE qa_score=VALUES(qa_score), updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("iisid", $student_id, $subject_id, $school_year, $current_quarter, $qa_raw);
    $ok = $stmt->execute();
    $stmt->close();
    $max_score = 100.0;
    $cfg = $conn->prepare("SELECT max_score FROM qa_config WHERE subject_id=? AND school_year=? AND quarter=?");
    $cfg->bind_param("isi", $subject_id, $school_year, $current_quarter);
    $cfg->execute();
    $cfgRow = $cfg->get_result()->fetch_assoc();
    $cfg->close();
    if ($cfgRow) $max_score = floatval($cfgRow['max_score']);
    $qa_pct = ($qa_raw !== null && $max_score > 0) ? round(($qa_raw / $max_score) * 100, 2) : null;
    echo json_encode(['success' => $ok, 'qa_pct' => $qa_pct]);
    $conn->close(); exit();
}

// ══════════════════════════════════════════════════════════
// AJAX: Update Final Grade (kept for compatibility)
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_grade') {
    header('Content-Type: application/json');
    $student_id  = intval($_POST['student_id']);
    $subject_id  = intval($_POST['subject_id']);
    $final_grade = intval(round(floatval($_POST['final_grade'])));
    $stmt = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
    $stmt->bind_param("ii", $subject_id, $teacher_id);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
    $stmt->close();
    $stmt = $conn->prepare("UPDATE student_subjects SET final_grade=? WHERE student_id=? AND subject_id=?");
    $stmt->bind_param("dii", $final_grade, $student_id, $subject_id);
    $ok = $stmt->execute();
    $stmt->close();
    saveQuarterlyGrade($conn, $student_id, $subject_id, $final_grade, $teacher_id);
    echo json_encode(['success' => $ok]);
    $conn->close(); exit();
}

// ── AJAX: Get Subjects for section ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_subjects') {
    header('Content-Type: application/json');
    $grade   = $_POST['grade']   ?? '';
    $section = $_POST['section'] ?? '';
    $stmt = $conn->prepare("SELECT id, subject_name FROM subjects WHERE teacher_id=? AND grade=? AND section=? AND status='active'");
    $stmt->bind_param("iss", $teacher_id, $grade, $section);
    $stmt->execute();
    echo json_encode(['success'=>true,'data'=>$stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
    $stmt->close(); $conn->close(); exit();
}

// ── AJAX: Get Students + Grades (kept for compatibility) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_students') {
    header('Content-Type: application/json');
    $subject_id = intval($_POST['subject_id']);
    $stmt = $conn->prepare("SELECT id, subject_name, grade FROM subjects WHERE id=? AND teacher_id=?");
    $stmt->bind_param("ii", $subject_id, $teacher_id);
    $stmt->execute();
    $subjectRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$subjectRow) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
    $weights = getSubjectWeights($conn, $subjectRow['subject_name'], $subjectRow['grade']);
    $WW_PCT  = $weights['ww'];
    $PT_PCT  = $weights['pt'];
    $QA_PCT  = $weights['qa'];
    $IS_CUSTOM = $weights['is_custom'];
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year     = $sy['school_year'] ?? '';
    $current_quarter = intval($sy['current_quarter'] ?? 1);
    $cfgStmt = $conn->prepare("SELECT max_score FROM qa_config WHERE subject_id=? AND school_year=? AND quarter=?");
    $cfgStmt->bind_param("isi", $subject_id, $school_year, $current_quarter);
    $cfgStmt->execute();
    $cfgRow = $cfgStmt->get_result()->fetch_assoc();
    $cfgStmt->close();
    $qa_max = $cfgRow ? floatval($cfgRow['max_score']) : null;
    $stmt = $conn->prepare("
        SELECT s.id, s.first_name, s.last_name, s.email, s.grade_level, s.section,
            COALESCE(
                (SELECT sqg.grade FROM student_quarterly_grades sqg
                 WHERE sqg.student_id=s.id AND sqg.subject_id=? AND sqg.school_year=? AND sqg.quarter=?),
                ss.final_grade
            ) AS final_grade,
            COALESCE((SELECT AVG(qa2.percentage)
                FROM quizzes q2
                INNER JOIN quiz_attempts qa2 ON q2.id=qa2.quiz_id AND qa2.student_id=s.id
                WHERE q2.subject_id=? AND q2.status='published' AND qa2.status='graded'),NULL) AS ww_score,
            COALESCE(
                CASE WHEN SUM(CASE WHEN a.subject_id=? AND a.status='published' THEN a.max_points ELSE 0 END) > 0
                THEN (SUM(CASE WHEN a.subject_id=? AND a.status='published' THEN COALESCE(sub2.score,0) ELSE 0 END) /
                      SUM(CASE WHEN a.subject_id=? AND a.status='published' THEN a.max_points ELSE 0 END)) * 100
                ELSE NULL END, NULL
            ) AS pt_score,
            (SELECT COUNT(*) FROM assignments a2
                INNER JOIN submissions sub3 ON a2.id=sub3.assignment_id AND sub3.student_id=s.id
                WHERE a2.subject_id=? AND a2.status='published' AND sub3.status='graded') AS graded_assignments,
            (SELECT COUNT(*) FROM assignments a3 WHERE a3.subject_id=? AND a3.status='published') AS total_assignments,
            (SELECT COUNT(*) FROM quizzes q3
                INNER JOIN quiz_attempts qa3 ON q3.id=qa3.quiz_id AND qa3.student_id=s.id
                WHERE q3.subject_id=? AND q3.status='published' AND qa3.status='graded') AS graded_quizzes,
            (SELECT COUNT(*) FROM quizzes q4 WHERE q4.subject_id=? AND q4.status='published') AS total_quizzes,
            (SELECT sqa.qa_score FROM student_qa_scores sqa
                WHERE sqa.student_id=s.id AND sqa.subject_id=? AND sqa.school_year=? AND sqa.quarter=?) AS qa_raw
        FROM students s
        INNER JOIN student_subjects ss ON s.id=ss.student_id AND ss.subject_id=? AND ss.status='active'
        LEFT JOIN assignments a ON a.subject_id=? AND a.status='published'
        LEFT JOIN submissions sub2 ON a.id=sub2.assignment_id AND sub2.student_id=s.id AND sub2.status='graded'
        GROUP BY s.id, s.first_name, s.last_name, s.email, s.grade_level, s.section, ss.final_grade
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->bind_param(
        "isiiiiiiiiiisiii",
        $subject_id, $school_year, $current_quarter,
        $subject_id,
        $subject_id, $subject_id, $subject_id,
        $subject_id, $subject_id, $subject_id, $subject_id,
        $subject_id, $school_year, $current_quarter,
        $subject_id, $subject_id
    );
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($students as &$st) {
        $ww     = $st['ww_score'] !== null ? floatval($st['ww_score']) : null;
        $pt     = $st['pt_score'] !== null ? floatval($st['pt_score']) : null;
        $qa_raw = $st['qa_raw']   !== null ? floatval($st['qa_raw'])   : null;
        $qa_pct = ($qa_raw !== null && $qa_max !== null && $qa_max > 0)
                    ? round(($qa_raw / $qa_max) * 100, 2) : null;
        $computed = computeFinalGrade($ww, $pt, $qa_pct, $WW_PCT, $PT_PCT, $QA_PCT);
        $st['ww_score'] = $ww  !== null ? round($ww, 2) : null;
        $st['pt_score'] = $pt  !== null ? round($pt, 2) : null;
        $st['qa_raw']   = $qa_raw;
        $st['qa_pct']   = $qa_pct !== null ? round($qa_pct, 2) : null;
        $st['computed'] = $computed;
    }
    unset($st);
    echo json_encode([
        'success'         => true,
        'data'            => $students,
        'ww_pct'          => $WW_PCT,
        'pt_pct'          => $PT_PCT,
        'qa_pct'          => $QA_PCT,
        'is_custom'       => $IS_CUSTOM,
        'subject_name'    => $subjectRow['subject_name'],
        'grade_level'     => $subjectRow['grade'],
        'qa_max'          => $qa_max,
        'school_year'     => $school_year,
        'current_quarter' => $current_quarter
    ]);
    $conn->close(); exit();
}

// ── AJAX: Advisory ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_advisory') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT advisory_grade, advisory_section FROM teachers WHERE id=?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$t['advisory_grade']) { echo json_encode(['success'=>false,'message'=>'No advisory class assigned']); $conn->close(); exit(); }
    $advisory_grade = $t['advisory_grade'];
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year     = $sy['school_year'] ?? '';
    $current_quarter = intval($sy['current_quarter'] ?? 1);
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM students WHERE grade_level=? AND section=? AND status='active' ORDER BY last_name, first_name");
    $stmt->bind_param("ss", $advisory_grade, $t['advisory_section']);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $subjectNamesStmt = $conn->prepare("
        SELECT DISTINCT sub.subject_name
        FROM student_subjects ss
        INNER JOIN subjects sub ON ss.subject_id = sub.id
        INNER JOIN students st ON ss.student_id = st.id
        WHERE st.grade_level = ? AND st.section = ? AND ss.status = 'active' AND sub.status = 'active'
        ORDER BY sub.subject_name
    ");
    $subjectNamesStmt->bind_param("ss", $advisory_grade, $t['advisory_section']);
    $subjectNamesStmt->execute();
    $allSubjectNames = array_column($subjectNamesStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_name');
    $subjectNamesStmt->close();
    $weightMap = buildSubjectWeightMap($conn, $advisory_grade, $allSubjectNames);
    foreach ($students as &$st) {
        $stmt = $conn->prepare("
            SELECT sub.id as subject_id, sub.subject_name, sub.teacher_id as stid,
                COALESCE(
                    (SELECT sqg.grade FROM student_quarterly_grades sqg
                     WHERE sqg.student_id=? AND sqg.subject_id=sub.id AND sqg.school_year=? AND sqg.quarter=?),
                    ss.final_grade
                ) AS final_grade
            FROM student_subjects ss
            INNER JOIN subjects sub ON ss.subject_id=sub.id
            WHERE ss.student_id=? AND ss.status='active'
            ORDER BY sub.subject_name
        ");
        $stmt->bind_param("isii", $st['id'], $school_year, $current_quarter, $st['id']);
        $stmt->execute();
        $st['subjects'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($st['subjects'] as &$subj) {
            $subj['can_edit'] = ($subj['stid'] == $teacher_id);
        }
        unset($subj);
        $subjectGradesForWeight = array_map(fn($s) => [
            'subject_name' => $s['subject_name'],
            'grade'        => $s['final_grade'] !== null ? floatval($s['final_grade']) : null
        ], $st['subjects']);
        $st['weighted_overall'] = computeWeightedOverall($subjectGradesForWeight, $weightMap);
    }
    unset($st);
    echo json_encode([
        'success'    => true,
        'data'       => $students,
        'grade'      => $advisory_grade,
        'section'    => $t['advisory_section'],
        'weight_map' => $weightMap
    ]);
    $conn->close(); exit();
}

// ── AJAX: Save advisory grade ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_adv_grade') {
    header('Content-Type: application/json');
    $student_id  = intval($_POST['student_id']);
    $subject_id  = intval($_POST['subject_id']);
    $final_grade = intval(round(floatval($_POST['final_grade'])));
    $stmt = $conn->prepare("SELECT advisory_grade FROM teachers WHERE id=?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$t['advisory_grade']) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
    $stmt = $conn->prepare("UPDATE student_subjects SET final_grade=? WHERE student_id=? AND subject_id=?");
    $stmt->bind_param("dii", $final_grade, $student_id, $subject_id);
    $ok = $stmt->execute();
    $stmt->close();
    saveQuarterlyGrade($conn, $student_id, $subject_id, $final_grade, $teacher_id);
    echo json_encode(['success' => $ok]);
    $conn->close(); exit();
}

// ── AJAX: Student Work modal ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_work') {
    header('Content-Type: application/json');
    $sid   = intval($_POST['student_id']);
    $subid = intval($_POST['subject_id']);
    $stmt = $conn->prepare("SELECT a.title, a.assignment_type, a.max_points, sub.score, sub.status, sub.submitted_at FROM assignments a LEFT JOIN submissions sub ON a.id=sub.assignment_id AND sub.student_id=? WHERE a.subject_id=? AND a.status='published' ORDER BY a.assignment_type, a.created_at DESC");
    $stmt->bind_param("ii", $sid, $subid);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare("SELECT q.title, q.total_points, qa.score, qa.percentage, qa.status, qa.submitted_at FROM quizzes q LEFT JOIN quiz_attempts qa ON q.id=qa.quiz_id AND qa.student_id=? WHERE q.subject_id=? AND q.status='published' ORDER BY q.created_at DESC");
    $stmt->bind_param("ii", $sid, $subid);
    $stmt->execute();
    $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success'=>true,'assignments'=>$assignments,'quizzes'=>$quizzes]);
    $conn->close(); exit();
}

// ── AJAX: Grade Card — students list ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gc_get_students') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT advisory_grade, advisory_section FROM teachers WHERE id=?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$t['advisory_grade']) { echo json_encode(['success'=>false,'message'=>'No advisory class assigned']); exit(); }
    $stmt = $conn->prepare("SELECT id, first_name, last_name, lrn, grade_level, section, birthdate, guardian_name, relationship, guardian_contact FROM students WHERE grade_level=? AND section=? AND status='active' ORDER BY last_name, first_name");
    $stmt->bind_param("ss", $t['advisory_grade'], $t['advisory_section']);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($students as &$st) {
        $st['age'] = $st['birthdate'] ? (int)date_diff(date_create($st['birthdate']),date_create('today'))->y : null;
    }
    unset($st);
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    echo json_encode(['success'=>true,'students'=>$students,'grade'=>$t['advisory_grade'],'section'=>$t['advisory_section'],'sy'=>$sy['school_year']??'—','quarter'=>$sy['current_quarter']??1]);
    $conn->close(); exit();
}

// ── AJAX: Grade Card — quarterly grades ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gc_get_grades') {
    header('Content-Type: application/json');
    $student_id = intval($_POST['student_id']);
    $stmt = $conn->prepare("SELECT advisory_grade, advisory_section FROM teachers WHERE id=?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$t['advisory_grade']) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year     = $sy['school_year'] ?? date('Y').'-'.(date('Y')+1);
    $current_quarter = intval($sy['current_quarter'] ?? 1);

    // Fetch subject logical groups for categorization
    $gradeStmt = $conn->prepare("SELECT advisory_grade FROM teachers WHERE id=?");
    $gradeStmt->bind_param("i", $teacher_id);
    $gradeStmt->execute();
    $tRow = $gradeStmt->get_result()->fetch_assoc();
    $gradeStmt->close();
    $advisory_grade = $tRow['advisory_grade'] ?? '';

    $stmt = $conn->prepare("
        SELECT sub.id AS subject_id, sub.subject_name, sub.teacher_id, t.fullname AS teacher_name, ss.final_grade
        FROM student_subjects ss
        INNER JOIN subjects sub ON ss.subject_id=sub.id
        INNER JOIN teachers t ON sub.teacher_id=t.id
        WHERE ss.student_id=? AND ss.status='active'
        ORDER BY sub.subject_name
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get subject groups/categories for this grade level
    $groupsStmt = $conn->prepare("
        SELECT g.id, g.display_name, g.sort_order,
               GROUP_CONCAT(a.subject_name SEPARATOR '||') as aliases
        FROM subject_logical_groups g
        LEFT JOIN subject_logical_group_aliases a ON a.group_id = g.id
        WHERE g.grade_level = ?
        GROUP BY g.id
        ORDER BY g.sort_order
    ");
    $groupsStmt->bind_param("s", $advisory_grade);
    $groupsStmt->execute();
    $groups = $groupsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $groupsStmt->close();

    // Build alias → category map
    $subjectCategoryMap = [];
    foreach ($groups as $g) {
        if ($g['aliases']) {
            foreach (explode('||', $g['aliases']) as $alias) {
                $subjectCategoryMap[strtolower(trim($alias))] = $g['display_name'];
            }
        }
    }

    $quarters = [];
    for ($q = 1; $q <= 4; $q++) {
        $locked = false;
        $stmt = $conn->prepare("SELECT sqg.subject_id, sqg.grade, sqg.is_locked FROM student_quarterly_grades sqg WHERE sqg.student_id=? AND sqg.school_year=? AND sqg.quarter=?");
        $stmt->bind_param("isi", $student_id, $school_year, $q);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $gradeMap = [];
        foreach ($rows as $r) { $gradeMap[$r['subject_id']] = ['grade'=>$r['grade'],'is_locked'=>$r['is_locked']]; if($r['is_locked'])$locked=true; }
        $subjectGrades = [];
        foreach ($subjects as $sub) {
            $saved = $gradeMap[$sub['subject_id']] ?? null;
            if ($saved !== null) { $grade=$saved['grade']; $is_saved=true; $is_prefill=false; }
            elseif ($q === $current_quarter && $sub['final_grade'] !== null) { $grade=$sub['final_grade']; $is_saved=false; $is_prefill=true; }
            else { $grade=null; $is_saved=false; $is_prefill=false; }
            $category = $subjectCategoryMap[strtolower($sub['subject_name'])] ?? 'Other Subjects';
            $subjectGrades[] = [
                'subject_id'   => $sub['subject_id'],
                'subject_name' => $sub['subject_name'],
                'teacher_name' => $sub['teacher_name'],
                'grade'        => $grade,
                'is_saved'     => $is_saved,
                'is_prefilled' => $is_prefill,
                'category'     => $category
            ];
        }
        $quarters[$q] = ['quarter'=>$q,'is_locked'=>$locked,'is_current'=>($q===$current_quarter),'is_future'=>($q>$current_quarter),'subjects'=>$subjectGrades];
    }
    echo json_encode([
        'success'         => true,
        'quarters'        => array_values($quarters),
        'school_year'     => $school_year,
        'current_quarter' => $current_quarter,
        'subject_categories' => $groups
    ]);
    $conn->close(); exit();
}

// ── AJAX: Grade Card save quarter ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gc_save_quarter') {
    header('Content-Type: application/json');
    $student_id = intval($_POST['student_id']);
    $quarter    = intval($_POST['quarter']);
    $grades     = json_decode($_POST['grades'], true);
    $stmt = $conn->prepare("SELECT advisory_grade FROM teachers WHERE id=?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$t['advisory_grade']) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year     = $sy['school_year'] ?? date('Y').'-'.(date('Y')+1);
    $current_quarter = intval($sy['current_quarter'] ?? 1);
    if ($quarter !== $current_quarter) { echo json_encode(['success'=>false,'message'=>'This quarter cannot be edited']); exit(); }
    $errors = 0;
    foreach ($grades as $item) {
        $subject_id = intval($item['subject_id']);
        $grade      = ($item['grade'] !== null && $item['grade'] !== '') ? intval(round(floatval($item['grade']))) : null;
        if ($grade === null) continue;
        $check = $conn->prepare("SELECT is_locked FROM student_quarterly_grades WHERE student_id=? AND subject_id=? AND school_year=? AND quarter=?");
        $check->bind_param("iisi", $student_id, $subject_id, $school_year, $quarter);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();
        if ($row && $row['is_locked']) continue;
        $upsert = $conn->prepare("INSERT INTO student_quarterly_grades (student_id,subject_id,school_year,quarter,grade,saved_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE grade=VALUES(grade),saved_by=VALUES(saved_by),saved_at=CURRENT_TIMESTAMP");
        $upsert->bind_param("iisidd", $student_id, $subject_id, $school_year, $quarter, $grade, $teacher_id);
        if (!$upsert->execute()) $errors++;
        $upsert->close();
        $upd = $conn->prepare("UPDATE student_subjects SET final_grade=? WHERE student_id=? AND subject_id=?");
        $upd->bind_param("dii", $grade, $student_id, $subject_id);
        $upd->execute(); $upd->close();
    }
    echo json_encode(['success' => $errors === 0]);
    $conn->close(); exit();
}

// ── AJAX: Past Quarters ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pq_get_quarters') {
    header('Content-Type: application/json');
    $sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $admin_q     = intval($sy['current_quarter'] ?? 1);
    $school_year = $sy['school_year'] ?? '';
    $maxq_stmt = $conn->prepare("SELECT MAX(quarter) AS max_q FROM student_quarterly_grades WHERE school_year=?");
    $maxq_stmt->bind_param("s", $school_year);
    $maxq_stmt->execute();
    $maxq_row   = $maxq_stmt->get_result()->fetch_assoc();
    $maxq_stmt->close();
    $actual_max        = intval($maxq_row['max_q'] ?? 0);
    $effective_current = max($admin_q, $actual_max > 0 ? $actual_max : $admin_q);
    $dist_stmt = $conn->prepare("SELECT DISTINCT quarter FROM student_quarterly_grades WHERE school_year=? AND quarter<? ORDER BY quarter ASC");
    $dist_stmt->bind_param("si", $school_year, $effective_current);
    $dist_stmt->execute();
    $dist_rows             = $dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dist_stmt->close();
    $quarters_with_records = array_column($dist_rows, 'quarter');
    $all_past              = array_unique(array_merge($quarters_with_records, $admin_q > 1 ? range(1,$admin_q-1) : []));
    sort($all_past);
    $labels = ['1st','2nd','3rd','4th'];
    $past   = [];
    foreach ($all_past as $q) {
        if ($q<1||$q>4) continue;
        $past[] = ['quarter'=>$q,'label'=>$labels[$q-1].' Quarter','school_year'=>$school_year,'has_records'=>in_array($q,$quarters_with_records)];
    }
    echo json_encode(['success'=>true,'past_quarters'=>$past,'current_quarter'=>$admin_q,'school_year'=>$school_year]);
    $conn->close(); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pq_subject_grades') {
    header('Content-Type: application/json');
    $subject_id = intval($_POST['subject_id']);
    $quarter    = intval($_POST['quarter']);
    $stmt = $conn->prepare("SELECT id, subject_name, grade, section FROM subjects WHERE id=? AND teacher_id=?");
    $stmt->bind_param("ii", $subject_id, $teacher_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sub) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); $conn->close(); exit(); }
    $sy_stmt = $conn->prepare("SELECT school_year FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year = $sy['school_year'];
    $stmt = $conn->prepare("
        SELECT s.id, s.first_name, s.last_name, s.lrn,
               sqg.grade, sqg.is_locked, sqg.saved_at, t.fullname AS saved_by_name
        FROM students s
        INNER JOIN student_subjects ss ON s.id=ss.student_id AND ss.subject_id=?
        LEFT JOIN student_quarterly_grades sqg ON sqg.student_id=s.id AND sqg.subject_id=? AND sqg.school_year=? AND sqg.quarter=?
        LEFT JOIN teachers t ON sqg.saved_by=t.id
        WHERE s.status='active'
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->bind_param("iisi", $subject_id, $subject_id, $school_year, $quarter);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success'=>true,'students'=>$students,'subject'=>$sub,'school_year'=>$school_year,'quarter'=>$quarter]);
    $conn->close(); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pq_advisory_grades') {
    header('Content-Type: application/json');
    $quarter = intval($_POST['quarter']);
    $stmt = $conn->prepare("SELECT advisory_grade, advisory_section FROM teachers WHERE id=?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$t['advisory_grade']) { echo json_encode(['success'=>false,'message'=>'No advisory class assigned']); $conn->close(); exit(); }
    $sy_stmt = $conn->prepare("SELECT school_year FROM school_year_settings ORDER BY id DESC LIMIT 1");
    $sy_stmt->execute();
    $sy = $sy_stmt->get_result()->fetch_assoc();
    $sy_stmt->close();
    $school_year = $sy['school_year'];
    $stmt = $conn->prepare("SELECT id, first_name, last_name, lrn FROM students WHERE grade_level=? AND section=? AND status='active' ORDER BY last_name, first_name");
    $stmt->bind_param("ss", $t['advisory_grade'], $t['advisory_section']);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $subjectMap = [];
    foreach ($students as &$st) {
        $stmt = $conn->prepare("
            SELECT sub.id AS subject_id, sub.subject_name, sub.teacher_id, te.fullname AS teacher_name,
                   sqg.grade, sqg.is_locked, sqg.saved_at
            FROM student_subjects ss
            INNER JOIN subjects sub ON ss.subject_id=sub.id
            INNER JOIN teachers te ON sub.teacher_id=te.id
            LEFT JOIN student_quarterly_grades sqg ON sqg.student_id=? AND sqg.subject_id=sub.id AND sqg.school_year=? AND sqg.quarter=?
            WHERE ss.student_id=? AND ss.status='active'
            ORDER BY sub.subject_name
        ");
        $stmt->bind_param("isii", $st['id'], $school_year, $quarter, $st['id']);
        $stmt->execute();
        $subjectRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $st['subjects'] = $subjectRows;
        $grades  = array_filter(array_column($subjectRows,'grade'),fn($g)=>$g!==null);
        $st['avg'] = count($grades) ? round(array_sum($grades)/count($grades)) : null;
        foreach ($subjectRows as $sr) { if (!isset($subjectMap[$sr['subject_id']])) $subjectMap[$sr['subject_id']] = $sr['subject_name']; }
    }
    unset($st);
    asort($subjectMap);
    echo json_encode(['success'=>true,'students'=>$students,'subjects'=>$subjectMap,'grade'=>$t['advisory_grade'],'section'=>$t['advisory_section'],'school_year'=>$school_year,'quarter'=>$quarter]);
    $conn->close(); exit();
}

// ── Page load ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT fullname, advisory_grade, advisory_section, theme_color FROM teachers WHERE id=?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT DISTINCT grade, section FROM subjects WHERE teacher_id=? AND status='active' ORDER BY grade, section");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$sections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sy_stmt = $conn->prepare("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
$sy_stmt->execute();
$sy_settings = $sy_stmt->get_result()->fetch_assoc();
$sy_stmt->close();

$conn->close();
$theme         = $teacher['theme_color'] ?? '#1e40af';
$current_q     = intval($sy_settings['current_quarter'] ?? 1);
$QUARTER_LABEL = ['1st','2nd','3rd','4th'][$current_q - 1];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="img/ashi.jpg" type="image/x-icon">
<title>Grades — Ashi Amaya</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style type="text/css">
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --brand:      <?= $theme ?>;
    --brand-dim:  color-mix(in srgb, <?= $theme ?> 14%, #fff);
    --brand-mid:  color-mix(in srgb, <?= $theme ?> 28%, #fff);
    --ink:        #0f172a;
    --ink2:       #1e293b;
    --ink3:       #475569;
    --ink4:       #94a3b8;
    --line:       #e2e8f0;
    --line2:      #f1f5f9;
    --bg:         #f8fafc;
    --white:      #ffffff;
    --ok:         #16a34a;
    --ok-bg:      #f0fdf4;
    --warn:       #d97706;
    --warn-bg:    #fffbeb;
    --bad:        #dc2626;
    --bad-bg:     #fef2f2;
    --ww:         #3b82f6;
    --pt:         #8b5cf6;
    --qa:         #f59e0b;
    --r:          3px;
    --mono:       'JetBrains Mono', monospace;
    --sans:       'DM Sans', sans-serif;
    --cell-h:     38px;
    --head-h:     44px;
}
html, body { font-family: var(--sans); background: var(--bg); color: var(--ink); font-size: 13px; line-height: 1.4; }

/* ══ TOPBAR ══ */
.topbar { height:40px; background:var(--ink2); display:flex; align-items:center; justify-content:space-between; padding:0 12px; position:sticky; top:0; z-index:200; gap:10px; }
.tb-left { display:flex; align-items:center; gap:8px; }
.tb-right { display:flex; align-items:center; gap:6px; }
.tb-logo { font-family:var(--mono); font-size:11px; font-weight:700; color:#fff; letter-spacing:.5px; display:flex; align-items:center; gap:6px; }
.tb-logo-dot { width:8px; height:8px; border-radius:50%; background:var(--brand); }
.tb-divider { width:1px; height:18px; background:#334155; }
.tb-title { font-size:12px; font-weight:500; color:#94a3b8; }
.tb-badge { display:inline-flex; align-items:center; gap:5px; padding:3px 8px; border-radius:2px; background:#1e293b; border:1px solid #334155; font-size:10px; font-weight:600; color:#94a3b8; font-family:var(--mono); white-space:nowrap; }
.tb-badge .hi { color:color-mix(in srgb,var(--brand) 80%,#fff); }
.tb-btn { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:var(--r); border:1px solid #334155; background:transparent; color:#94a3b8; font-size:11px; font-weight:500; font-family:var(--sans); cursor:pointer; text-decoration:none; transition:all .12s; }
.tb-btn:hover { border-color:#64748b; color:#e2e8f0; }

/* ══ RIBBON ══ */
.ribbon { height:34px; background:var(--white); border-bottom:2px solid var(--line); display:flex; align-items:stretch; overflow-x:auto; position:sticky; top:40px; z-index:190; }
.ribbon::-webkit-scrollbar { height:0; }
.rtab { display:inline-flex; align-items:center; gap:6px; padding:0 16px; font-size:12px; font-weight:500; color:var(--ink3); border:none; border-bottom:2px solid transparent; margin-bottom:-2px; background:none; cursor:pointer; font-family:var(--sans); white-space:nowrap; transition:all .1s; }
.rtab:hover { color:var(--ink); background:var(--bg); }
.rtab.on { color:var(--brand); border-bottom-color:var(--brand); font-weight:600; background:var(--brand-dim); }
.rtab-chip { font-size:9px; padding:1px 5px; border-radius:2px; background:var(--brand-dim); color:var(--brand); font-weight:700; font-family:var(--mono); }

/* ══ FORMULA BAR ══ */
.formula-bar { height:26px; background:var(--white); border-bottom:1px solid var(--line); display:flex; align-items:center; font-family:var(--mono); font-size:11px; }
.fb-cell-ref { width:64px; min-width:64px; height:100%; display:flex; align-items:center; justify-content:center; border-right:1px solid var(--line); color:var(--ink3); font-size:10px; font-weight:600; background:var(--bg); }
.fb-fx { width:28px; display:flex; align-items:center; justify-content:center; color:var(--brand); font-size:11px; font-weight:700; border-right:1px solid var(--line); height:100%; }
.fb-formula { flex:1; padding:0 10px; color:var(--ink3); font-size:10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; }

/* ══ PAGE ══ */
.page { padding:10px 14px 40px; }

/* ══ FILTER ROW ══ */
.filter-row { display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap; margin-bottom:8px; background:var(--white); border:1px solid var(--line); padding:8px 10px; border-radius:var(--r); }
.fr-group { display:flex; flex-direction:column; gap:2px; }
.fr-label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--ink4); font-family:var(--mono); }
.fr-select, .fr-input { height:26px; padding:0 8px; border:1px solid var(--line); border-radius:var(--r); font-size:12px; font-family:var(--sans); color:var(--ink); background:var(--white); min-width:150px; transition:border-color .12s; }
.fr-select:focus, .fr-input:focus { outline:none; border-color:var(--brand); }
.fr-select:disabled { opacity:.4; background:var(--bg); cursor:not-allowed; }
.fr-divider { width:1px; height:30px; background:var(--line); align-self:flex-end; }

/* ══ STATS STRIP ══ */
.stats-strip { display:flex; gap:0; background:var(--white); border:1px solid var(--line); border-radius:var(--r); margin-bottom:8px; overflow:hidden; }
.ss-item { flex:1; padding:7px 14px; border-right:1px solid var(--line); display:flex; flex-direction:column; gap:1px; }
.ss-item:last-child { border-right:none; }
.ss-label { font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--ink4); font-family:var(--mono); }
.ss-val { font-size:16px; font-weight:700; color:var(--ink); font-family:var(--mono); }
.ss-val.ac { color:var(--brand); } .ss-val.ok { color:var(--ok); } .ss-val.warn { color:var(--warn); }

/* ══ EXCEL TABLE ══ */
.xl-wrap { background:var(--white); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; }
.xl-scroll { overflow-x:auto; overflow-y:auto; max-height:calc(100vh - 260px); }
.xl-scroll::-webkit-scrollbar { width:8px; height:8px; }
.xl-scroll::-webkit-scrollbar-track { background:var(--bg); }
.xl-scroll::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:4px; }
table.xl { width:100%; border-collapse:collapse; font-size:12px; }
.xl thead th.rn, .xl tbody td.rn { width:36px; min-width:36px; text-align:center; background:var(--bg); border-right:2px solid var(--line); font-family:var(--mono); font-size:9px; font-weight:500; color:var(--ink4); position:sticky; left:0; z-index:5; }
.xl thead th.rn { border-bottom:2px solid var(--line); z-index:15; }
.xl thead th.fc, .xl tbody td.fc { position:sticky; left:36px; background:var(--white); z-index:4; border-right:2px solid var(--line); min-width:180px; }
.xl thead th.fc { z-index:14; }
.xl thead { position:sticky; top:0; z-index:10; }
.xl thead tr.col-header th { height:var(--head-h); background:var(--ink2); color:#e2e8f0; font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; padding:0 10px; border-right:1px solid #334155; border-bottom:2px solid var(--line); white-space:nowrap; font-family:var(--mono); vertical-align:bottom; padding-bottom:8px; user-select:none; }
.xl thead tr.col-header th.fc { background:#1e293b; color:#e2e8f0; }
.xl thead tr.col-header th.rn { background:#1e293b; color:#64748b; }
.xl tbody tr { transition:background .08s; }
.xl tbody tr:hover { background:#f0f7ff !important; }
.xl tbody tr:hover td.fc { background:#e8f2ff; }
.xl tbody tr:nth-child(even) { background:#fafcff; }
.xl tbody tr:nth-child(even) td.rn { background:#f1f5f9; }
.xl tbody tr:nth-child(even) td.fc { background:#fafcff; }
.xl tbody td { height:var(--cell-h); padding:0 10px; border-right:1px solid var(--line2); border-bottom:1px solid var(--line2); vertical-align:middle; }
.xl tbody td:last-child { border-right:none; }
.xl-name { font-weight:600; font-size:12px; color:var(--ink); }
.xl-sub  { font-size:10px; color:var(--ink4); margin-top:1px; font-family:var(--mono); }
.xl-score { font-family:var(--mono); font-size:11px; font-weight:500; text-align:center; white-space:nowrap; }
.xl-score.ok { color:var(--ok); } .xl-score.warn { color:var(--warn); } .xl-score.bad { color:var(--bad); } .xl-score.na { color:#d1d5db; }
.xl-empty { padding:56px 0; text-align:center; color:var(--ink4); border-top:1px solid var(--line); }
.xl-empty i { font-size:28px; opacity:.15; display:block; margin-bottom:10px; }
.xl-empty h3 { font-size:13px; font-weight:600; color:var(--ink3); margin-bottom:4px; }
.xl-empty p  { font-size:12px; }
.xl-loader { padding:40px; text-align:center; color:var(--ink4); }
.xl-loader i { font-size:16px; animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* Advisory pills */
.adv-pill-wrap { display:flex; flex-wrap:wrap; gap:3px; }
.adv-pill { display:inline-flex; align-items:center; gap:4px; padding:2px 6px; border-radius:2px; font-size:11px; border:1px solid var(--line); background:var(--bg); font-family:var(--mono); }
.adv-pill.edit { border-color:var(--brand-mid); background:var(--brand-dim); }
.adv-pill-name { font-weight:600; color:var(--ink); font-family:var(--sans); font-size:11px; }
.adv-pill-g { font-weight:700; color:var(--ink3); }
.adv-pill-input { width:36px; border:none; border-bottom:1.5px solid var(--brand); background:none; font-size:11px; font-weight:700; color:var(--brand); text-align:center; outline:none; font-family:var(--mono); }

/* Modal */
.overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:500; align-items:center; justify-content:center; padding:16px; }
.overlay.on { display:flex; }
.modal { background:var(--white); border-radius:var(--r); width:100%; max-width:680px; max-height:84vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.15); overflow:hidden; border:1px solid var(--line); }
.m-head { padding:10px 14px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; background:var(--ink2); }
.m-head h3 { font-size:12px; font-weight:600; color:#e2e8f0; font-family:var(--mono); }
.m-close { width:22px; height:22px; border-radius:2px; border:1px solid #334155; background:none; color:#94a3b8; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:10px; transition:all .1s; }
.m-close:hover { background:#dc2626; border-color:#dc2626; color:#fff; }
.m-body { padding:14px; overflow-y:auto; }

/* Grade Card */
.gc-selector { display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:8px 10px; background:var(--bg); border:1px solid var(--line); border-radius:var(--r); margin-bottom:8px; }
.gc-sel-wrap { position:relative; flex:1; min-width:240px; }
.gc-sel-wrap .ico { position:absolute; left:8px; top:50%; transform:translateY(-50%); color:var(--ink4); font-size:11px; pointer-events:none; }
.gc-sel-wrap select { width:100%; height:28px; padding:0 8px 0 26px; border:1px solid var(--line); border-radius:var(--r); font-size:12px; font-family:var(--sans); background:var(--white); color:var(--ink); }
.gc-sel-wrap select:focus { outline:none; border-color:var(--brand); }
.gc-adv-badge { display:flex; align-items:center; gap:5px; padding:4px 9px; background:var(--brand-dim); border:1px solid var(--brand-mid); border-radius:var(--r); font-size:10px; font-weight:700; color:var(--brand); white-space:nowrap; font-family:var(--mono); }
.gc-layout { display:grid; grid-template-columns:260px 1fr; gap:12px; align-items:start; }
@media(max-width:800px) { .gc-layout { grid-template-columns:1fr; } }
.gc-info-card { background:var(--white); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; }
.gc-info-hd { background:var(--ink2); padding:14px; }
.gc-ava { width:36px; height:36px; background:var(--brand); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; color:#fff; margin-bottom:6px; font-family:var(--mono); }
.gc-sname { font-size:13px; font-weight:700; color:#fff; margin-bottom:2px; }
.gc-smeta { font-size:10px; color:#94a3b8; display:flex; gap:4px; align-items:center; }
.gc-gwa-box { margin:10px; background:var(--bg); border:1px solid var(--line); border-radius:var(--r); padding:8px 10px; display:flex; align-items:center; justify-content:space-between; }
.gc-gwa-lbl { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--ink4); font-family:var(--mono); }
.gc-gwa-val { font-size:20px; font-weight:700; color:var(--brand); font-family:var(--mono); }
.gc-rows { padding:6px 10px 2px; }
.gc-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid var(--line2); gap:8px; }
.gc-row:last-child { border-bottom:none; }
.gc-key { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--ink4); font-family:var(--mono); white-space:nowrap; }
.gc-val { font-size:11px; font-weight:500; color:var(--ink); text-align:right; }
.gc-val.mono { font-family:var(--mono); font-size:10px; color:var(--brand); }
.gc-btn-group { padding:8px 10px 10px; }
.gc-print-btn { width:100%; display:flex; align-items:center; justify-content:center; gap:6px; padding:6px 10px; background:var(--ink2); color:#fff; border:none; border-radius:var(--r); font-size:11px; font-weight:600; font-family:var(--sans); cursor:pointer; transition:background .12s; }
.gc-print-btn:hover { background:var(--ink); }
.gc-panel { background:var(--white); border:1px solid var(--line); border-radius:var(--r); overflow:hidden; }
.gc-qtabs { display:flex; border-bottom:1px solid var(--line); background:var(--bg); overflow-x:auto; }
.gc-qtab { padding:7px 14px; font-size:11px; font-weight:600; border:none; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; color:var(--ink3); background:none; font-family:var(--sans); display:flex; align-items:center; gap:4px; transition:all .12s; white-space:nowrap; }
.gc-qtab:hover:not(.on) { color:var(--ink); background:var(--brand-dim); }
.gc-qtab.on { color:var(--brand); border-bottom-color:var(--brand); }
.gc-qtab.locked-tab { color:var(--ink4); }
.q-dot { width:5px; height:5px; border-radius:50%; background:var(--brand); }
.gc-qbody { padding:14px; display:none; }
.gc-qbody.on { display:block; }
.gc-qtopbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:6px; }
.gc-qtopbar-l { display:flex; align-items:center; gap:6px; }
.gc-qtitle { font-size:12px; font-weight:700; }
.gc-qbadge { padding:1px 6px; border-radius:2px; font-size:9px; font-weight:700; font-family:var(--mono); }
.gc-qbadge.locked-b { background:var(--line2); color:var(--ink3); }
.gc-qbadge.active-b { background:var(--brand-dim); color:var(--brand); }
.gc-qbadge.future-b { background:var(--bg); color:var(--ink4); border:1px dashed var(--line); }
.gc-save-btn { display:inline-flex; align-items:center; gap:4px; padding:5px 12px; background:var(--brand); color:#fff; border:none; border-radius:var(--r); font-size:11px; font-weight:600; font-family:var(--sans); cursor:pointer; }
.gc-save-btn:hover { opacity:.88; }
.gc-lock-notice { display:flex; align-items:center; gap:5px; padding:5px 8px; background:var(--bg); border:1px solid var(--line); border-radius:var(--r); font-size:10px; color:var(--ink3); margin-bottom:10px; }
.gc-subj-table { width:100%; border-collapse:collapse; }
.gc-subj-table th { padding:6px 8px; text-align:left; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--ink4); background:var(--bg); border-bottom:1px solid var(--line); font-family:var(--mono); }
.gc-subj-table td { padding:8px 8px; border-bottom:1px solid var(--line2); vertical-align:middle; }
.gc-subj-table tr:last-child td { border-bottom:none; }
.gc-subj-table tbody tr:hover { background:var(--brand-dim); }
.gc-category-row td { background:#f1f5f9; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--ink3); padding:5px 8px; }
.gc-sn { font-weight:600; font-size:12px; }
.gc-hint { font-size:9px; color:var(--brand); margin-top:1px; font-family:var(--mono); }
.gc-hint-empty { font-size:9px; color:var(--ink4); margin-top:1px; font-family:var(--mono); }
.gc-gi { width:58px; padding:4px 6px; border:1px solid var(--line); border-radius:2px; font-size:12px; font-weight:700; text-align:center; font-family:var(--mono); background:var(--white); color:var(--ink); transition:all .12s; }
.gc-gi:focus { outline:none; border-color:var(--brand); background:var(--brand-dim); }
.gc-gi:disabled { background:var(--bg); color:var(--ink3); cursor:not-allowed; border-color:transparent; }
.gc-gi.ok-g { color:var(--ok); } .gc-gi.warn-g { color:var(--warn); } .gc-gi.bad-g { color:var(--bad); }
.gc-avg-bar { background:var(--brand-dim); border-top:1px solid var(--brand-mid); padding:7px 10px; display:flex; align-items:center; justify-content:space-between; }
.gc-avg-lbl { font-size:10px; font-weight:700; color:var(--brand); font-family:var(--mono); }
.gc-avg-val { font-size:15px; font-weight:700; color:var(--brand); font-family:var(--mono); }
.gc-future-empty { text-align:center; padding:32px; color:var(--ink4); }
.gc-future-empty i { font-size:22px; margin-bottom:6px; display:block; opacity:.15; }
.gc-placeholder { text-align:center; padding:48px 16px; color:var(--ink4); }
.gc-placeholder i { font-size:28px; opacity:.12; display:block; margin-bottom:8px; }
.gc-placeholder h3 { font-size:13px; font-weight:600; color:var(--ink3); margin-bottom:4px; }
.gc-placeholder p  { font-size:12px; }

/* Past Quarters */
.pq-subtabs { display:flex; gap:5px; margin-bottom:8px; flex-wrap:wrap; }
.pq-stab { display:inline-flex; align-items:center; gap:5px; padding:5px 14px; border-radius:var(--r); border:1px solid var(--line); background:var(--white); color:var(--ink3); font-size:11px; font-weight:600; font-family:var(--sans); cursor:pointer; transition:all .12s; }
.pq-stab:hover { border-color:var(--brand); color:var(--brand); }
.pq-stab.on { background:var(--brand); color:#fff; border-color:var(--brand); }
.pq-adv-chip { font-size:9px; background:rgba(255,255,255,.2); padding:1px 5px; border-radius:2px; font-weight:700; }
.pq-stab:not(.on) .pq-adv-chip { background:var(--brand-dim); color:var(--brand); }
.pq-grade { display:inline-block; font-family:var(--mono); font-size:11px; font-weight:700; padding:2px 8px; border-radius:2px; min-width:44px; text-align:center; }
.pq-grade.os { background:#dcfce7; color:#15803d; } .pq-grade.vs { background:#dbeafe; color:#1d4ed8; }
.pq-grade.sa { background:#fef9c3; color:#92400e; } .pq-grade.fs { background:#ffedd5; color:#c2410c; }
.pq-grade.dn { background:#fee2e2; color:#b91c1c; } .pq-grade.na { background:var(--line2); color:var(--ink4); }
.pq-lock-banner { display:flex; align-items:center; gap:6px; padding:6px 12px; background:var(--bg); border-bottom:1px solid var(--line); font-size:11px; color:var(--ink3); }
.pq-lock-banner i { color:var(--brand); }
.pq-adv-table { width:100%; border-collapse:collapse; }
.pq-adv-table th { padding:7px 10px; text-align:left; font-size:9px; font-weight:700; color:var(--ink4); text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid var(--line); background:var(--bg); white-space:nowrap; font-family:var(--mono); }
.pq-adv-table th.center { text-align:center; }
.pq-adv-table td { padding:8px 10px; border-bottom:1px solid var(--line2); vertical-align:middle; }
.pq-adv-table tbody tr { cursor:pointer; transition:background .08s; }
.pq-adv-table tbody tr:hover { background:#f0f7ff; }
.pq-adv-table td.center { text-align:center; }
.pq-expand-ico { width:20px; height:20px; border-radius:2px; border:1px solid var(--line); background:var(--white); color:var(--ink4); display:inline-flex; align-items:center; justify-content:center; font-size:9px; transition:all .12s; }
.pq-adv-table tbody tr:hover .pq-expand-ico { border-color:var(--brand); color:var(--brand); }

/* Grade descriptor badges */
.gc { display:inline-block; padding:1px 6px; border-radius:2px; font-size:10px; font-weight:700; font-family:var(--mono); }
.gc.os { background:#dcfce7; color:#15803d; } .gc.vs { background:#dbeafe; color:#1d4ed8; }
.gc.sa { background:#fef9c3; color:#92400e; } .gc.fs { background:#ffedd5; color:#c2410c; }
.gc.dn { background:#fee2e2; color:#b91c1c; } .gc.na { background:#f1f5f9; color:#9ca3af; }

/* Toast */
.toast { position:fixed; top:48px; right:14px; padding:7px 12px; border-radius:var(--r); font-size:11px; font-weight:600; opacity:0; transform:translateY(-4px); transition:all .15s; z-index:999; display:flex; align-items:center; gap:5px; font-family:var(--mono); }
.toast.on { opacity:1; transform:none; }
.toast.ok  { background:var(--ok-bg); color:var(--ok); border:1px solid #bbf7d0; }
.toast.err { background:var(--bad-bg); color:var(--bad); border:1px solid #fecaca; }

/* ══════════════════════════════════════════════════════
   DEPED PRINT CARD STYLES
   ══════════════════════════════════════════════════════ */
#printArea {
    position: fixed; left: -9999px; top: 0;
    width: 1050px; background: #fff; z-index: -1;
    pointer-events: none; font-family: Arial, sans-serif;
    font-size: 11px; color: #000;
}

.deped-card {
    width: 1050px;
    background: #fff;
    padding: 18px 20px;
    font-family: Arial, sans-serif;
    font-size: 10.5px;
    color: #000;
    line-height: 1.3;
}

/* Header */
.deped-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    margin-bottom: 6px;
    text-align: center;
}
.deped-header-logo {
    width: 52px; height: 52px;
    background: var(--brand);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 900; color: #fff;
    flex-shrink: 0; font-family: Arial, sans-serif;
}
.deped-header-text .school-name {
    font-size: 13px; font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.deped-header-text .school-sub {
    font-size: 9.5px; color: #444;
}
.deped-card-title {
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    border-top: 2px solid #000;
    border-bottom: 1px solid #000;
    padding: 3px 0;
    margin-bottom: 8px;
}

/* Student Info Strip */
.deped-student-strip {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
    gap: 0;
    border: 1px solid #000;
    margin-bottom: 8px;
}
.deped-si-cell {
    padding: 3px 6px;
    border-right: 1px solid #000;
}
.deped-si-cell:last-child { border-right: none; }
.deped-si-label { font-size: 8.5px; color: #555; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.deped-si-value { font-size: 11px; font-weight: 700; min-height: 14px; }

/* Two-column layout */
.deped-two-col {
    display: grid;
    grid-template-columns: 500px 1fr;
    gap: 10px;
    align-items: start;
}

/* Semester block */
.deped-sem-block { margin-bottom: 8px; }
.deped-sem-title {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 0;
    border-bottom: 1px solid #000;
    margin-bottom: 0;
}

/* Grade table */
.deped-grade-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #000;
}
.deped-grade-table th,
.deped-grade-table td {
    border: 1px solid #000;
    padding: 2px 5px;
    text-align: center;
    vertical-align: middle;
}
.deped-grade-table th {
    background: #e8e8e8;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
}
.deped-grade-table td {
    font-size: 10.5px;
}
.deped-grade-table td.subj-name {
    text-align: left;
    font-size: 10px;
    min-width: 200px;
    padding-left: 6px;
}
.deped-grade-table td.category-header {
    text-align: left;
    font-weight: 700;
    font-size: 10px;
    background: #f0f0f0;
    padding-left: 6px;
    font-style: italic;
}
.deped-grade-table .gen-avg-row td {
    font-weight: 700;
    background: #f5f5f5;
    font-size: 10.5px;
}
.deped-grade-table .gen-avg-row td:first-child {
    text-align: right;
    padding-right: 8px;
}

/* Observed Values table */
.deped-ov-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    text-align: center;
    padding: 3px 0;
    border: 1px solid #000;
    border-bottom: none;
    background: #e8e8e8;
}
.deped-ov-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #000;
}
.deped-ov-table th,
.deped-ov-table td {
    border: 1px solid #000;
    padding: 2px 4px;
    vertical-align: middle;
}
.deped-ov-table th {
    background: #e8e8e8;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
}
.deped-ov-table td.core-val {
    font-weight: 700;
    font-size: 9.5px;
    vertical-align: top;
    padding-top: 4px;
}
.deped-ov-table td.behavior {
    font-size: 9px;
}
.deped-ov-table td.q-cell {
    text-align: center;
    width: 22px;
    font-size: 9.5px;
}

/* Legend section */
.deped-legend {
    margin-top: 8px;
    border: 1px solid #000;
}
.deped-legend-title {
    font-size: 9.5px;
    font-weight: 700;
    background: #e8e8e8;
    padding: 2px 5px;
    border-bottom: 1px solid #000;
    text-transform: uppercase;
}
.deped-legend-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
}
.deped-legend-col { padding: 3px 5px; }
.deped-legend-col:first-child { border-right: 1px solid #000; }
.deped-legend-row {
    display: flex;
    gap: 6px;
    font-size: 9px;
    margin-bottom: 1px;
}
.deped-legend-code { font-weight: 700; min-width: 22px; }

/* Scale table in legend */
.deped-scale-table { width: 100%; border-collapse: collapse; margin-top: 3px; }
.deped-scale-table th { font-size: 8.5px; font-weight: 700; background: #f0f0f0; border: 1px solid #ccc; padding: 1px 4px; text-align: center; }
.deped-scale-table td { font-size: 8.5px; border: 1px solid #ccc; padding: 1px 4px; text-align: center; }
.deped-scale-table td:first-child { text-align: left; }

/* Signature area */
.deped-signatures {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
    margin-top: 14px;
    padding-top: 8px;
    border-top: 1px solid #000;
}
.deped-sig-block { text-align: center; }
.deped-sig-name { font-size: 11px; font-weight: 700; border-bottom: 1px solid #000; min-height: 28px; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 2px; }
.deped-sig-lbl { font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px; color: #555; margin-top: 2px; }

/* Footer */
.deped-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 6px;
    font-size: 8px;
    color: #777;
    border-top: 1px solid #e0e0e0;
    padding-top: 4px;
}

@media print {
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    body > *:not(#printArea) { display: none !important; }
    #printArea {
        position: static !important;
        left: auto !important;
        width: 100% !important;
        z-index: auto !important;
        pointer-events: auto !important;
        padding: 0;
        background: #fff;
    }
    .deped-card { width: 100% !important; padding: 10px 14px; }
    .deped-two-col { grid-template-columns: 48% 1fr; }
}
</style>

<!-- TOPBAR -->
<div class="topbar">
    <div class="tb-left">
        <div class="tb-logo"><div class="tb-logo-dot"></div> ASHI AMAYA</div>
        <div class="tb-divider"></div>
        <span class="tb-title">Grade Management</span>
        <div class="tb-badge">
            <span class="hi"><?= $QUARTER_LABEL ?> Quarter</span>
            <span style="color:#334155;">·</span>
            S.Y. <?= htmlspecialchars($sy_settings['school_year'] ?? '—') ?>
        </div>
    </div>
    <div class="tb-right">
        <a href="teacher_dashb.php" class="tb-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
</div>

<!-- RIBBON TABS — My Subjects removed -->
<div class="ribbon">
    <button class="rtab on" id="t-adv" onclick="switchTab('adv')">
        <i class="fas fa-chalkboard-teacher"></i> Advisory
        <?php if ($teacher['advisory_section']): ?>
            <span class="rtab-chip"><?= htmlspecialchars($teacher['advisory_grade'].' · '.$teacher['advisory_section']) ?></span>
        <?php endif; ?>
    </button>
    <button class="rtab" id="t-gc" onclick="switchTab('gc')">
        <i class="fas fa-id-card"></i> Grade Card
        <?php if ($teacher['advisory_section']): ?>
            <span class="rtab-chip"><?= htmlspecialchars($teacher['advisory_grade'].' · '.$teacher['advisory_section']) ?></span>
        <?php endif; ?>
    </button>
    <button class="rtab" id="t-pq" onclick="switchTab('pq')"><i class="fas fa-history"></i> Past Quarters</button>
</div>

<!-- FORMULA BAR -->
<div class="formula-bar">
    <div class="fb-cell-ref" id="fbRef">—</div>
    <div class="fb-fx">ƒx</div>
    <div class="fb-formula" id="fbFormula">Select a student or subject to see grade details</div>
</div>

<div class="page">

    <!-- ADVISORY -->
    <div id="p-adv">
        <div class="filter-row">
            <div class="fr-group">
                <span class="fr-label">Search</span>
                <input class="fr-input" type="text" id="advSrch" placeholder="Name…" oninput="filterAdv()">
            </div>
            <div class="fr-group" style="margin-left:auto;">
                <span class="fr-label" style="color:var(--brand);">
                    <i class="fas fa-weight-hanging"></i> Overall = Weighted by subject importance
                </span>
            </div>
        </div>
        <div class="xl-wrap" id="advCon">
            <div class="xl-loader"><i class="fas fa-spinner"></i></div>
        </div>
    </div>

    <!-- GRADE CARD -->
    <div id="p-gc" style="display:none;">
        <div class="gc-selector">
            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink4);white-space:nowrap;font-family:var(--mono);">
                <i class="fas fa-user-graduate" style="color:var(--brand);margin-right:3px;"></i>Student
            </span>
            <div class="gc-sel-wrap">
                <i class="ico fas fa-search"></i>
                <select id="gcStudentSel" onchange="gcSelectStudent(this.value)">
                    <option value="">— Choose a student —</option>
                </select>
            </div>
            <?php if ($teacher['advisory_section']): ?>
                <div class="gc-adv-badge">
                    <i class="fas fa-users"></i>
                    <?= htmlspecialchars($teacher['advisory_grade'].' — '.$teacher['advisory_section']) ?>
                </div>
            <?php endif; ?>
        </div>
        <div id="gcContent">
            <div class="gc-placeholder">
                <i class="fas fa-id-card"></i>
                <h3>No Student Selected</h3>
                <p>Choose a student from the dropdown above.</p>
            </div>
        </div>
    </div>

    <!-- PAST QUARTERS -->
    <div id="p-pq" style="display:none;">
        <div class="pq-subtabs">
            <button class="pq-stab on" id="pqst-sub" onclick="pqSwitch('sub')">
                <i class="fas fa-table"></i> My Subjects
            </button>
            <?php if ($teacher['advisory_section']): ?>
            <button class="pq-stab" id="pqst-adv" onclick="pqSwitch('adv')">
                <i class="fas fa-chalkboard-teacher"></i> Advisory
                <span class="pq-adv-chip"><?= htmlspecialchars($teacher['advisory_grade'].' · '.$teacher['advisory_section']) ?></span>
            </button>
            <?php endif; ?>
        </div>

        <div id="pq-sub">
            <div class="filter-row" style="margin-bottom:8px;">
                <div class="fr-group">
                    <span class="fr-label">Quarter</span>
                    <select class="fr-select" id="pqSubQ" onchange="pqSubOnQuarter()">
                        <option value="">— Quarter —</option>
                    </select>
                </div>
                <div class="fr-group">
                    <span class="fr-label">Section</span>
                    <select class="fr-select" id="pqSubSec" onchange="pqSubOnSection()" disabled>
                        <option value="">— Section —</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= htmlspecialchars($s['grade'].'|'.$s['section']) ?>">
                                <?= htmlspecialchars($s['grade'].' - '.$s['section']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fr-group">
                    <span class="fr-label">Subject</span>
                    <select class="fr-select" id="pqSubSubject" onchange="pqSubLoad()" disabled>
                        <option value="">— Subject —</option>
                    </select>
                </div>
                <div class="fr-group">
                    <span class="fr-label">Search</span>
                    <input class="fr-input" type="text" id="pqSubSearch" placeholder="Name…" oninput="pqSubFilter()" disabled style="min-width:120px;">
                </div>
            </div>
            <div class="stats-strip" id="pqSubStats" style="display:none;">
                <div class="ss-item"><div class="ss-label">Students</div><div class="ss-val ac" id="pqSTotal">0</div></div>
                <div class="ss-item"><div class="ss-label">Graded</div><div class="ss-val" id="pqSGraded">0</div></div>
                <div class="ss-item"><div class="ss-label">Class Avg</div><div class="ss-val ac" id="pqSAvg">—</div></div>
                <div class="ss-item"><div class="ss-label">Passing ≥75</div><div class="ss-val ok" id="pqSPass">0</div></div>
                <div class="ss-item"><div class="ss-label">Outstanding ≥90</div><div class="ss-val ac" id="pqSOut">0</div></div>
            </div>
            <div class="xl-wrap" id="pqSubCon">
                <div class="xl-empty"><i class="fas fa-history"></i><h3>Select a Past Quarter & Subject</h3><p>Use the filters above.</p></div>
            </div>
        </div>

        <?php if ($teacher['advisory_section']): ?>
        <div id="pq-adv" style="display:none;">
            <div class="filter-row" style="margin-bottom:8px;">
                <div class="fr-group">
                    <span class="fr-label">Quarter</span>
                    <select class="fr-select" id="pqAdvQ" onchange="pqAdvLoad()">
                        <option value="">— Quarter —</option>
                    </select>
                </div>
                <div class="fr-group">
                    <span class="fr-label">Search</span>
                    <input class="fr-input" type="text" id="pqAdvSearch" placeholder="Name…" oninput="pqAdvFilter()" disabled style="min-width:120px;">
                </div>
            </div>
            <div class="stats-strip" id="pqAdvStats" style="display:none;">
                <div class="ss-item"><div class="ss-label">Students</div><div class="ss-val ac" id="pqATotal">0</div></div>
                <div class="ss-item"><div class="ss-label">Class Avg</div><div class="ss-val ac" id="pqAAvg">—</div></div>
                <div class="ss-item"><div class="ss-label">Passing ≥75</div><div class="ss-val ok" id="pqAPass">0</div></div>
                <div class="ss-item"><div class="ss-label">Outstanding ≥90</div><div class="ss-val ac" id="pqAOut">0</div></div>
            </div>
            <div class="xl-wrap" id="pqAdvCon">
                <div class="xl-empty"><i class="fas fa-history"></i><h3>Select a Past Quarter</h3><p>Choose a quarter to view records.</p></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- end .page -->

<!-- PQ Student Modal -->
<div class="overlay" id="pqModal">
    <div class="modal" style="max-width:500px;">
        <div class="m-head">
            <h3 id="pqModalTitle">Student Detail</h3>
            <button class="m-close" onclick="document.getElementById('pqModal').classList.remove('on')"><i class="fas fa-times"></i></button>
        </div>
        <div class="m-body" id="pqModalBody"></div>
    </div>
</div>

<!-- DepEd Print Area -->
<div id="printArea">
    <div class="deped-card" id="depedCardEl">
        <!-- Dynamically built by gcBuildCard() -->
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let gcStudents = [], gcCurrentStudent = null, gcData = null;
let pqQuarters = [], pqSubCache = [], pqAdvCache = null;
let advLoaded = false, advWeightMap = {};
const QUARTER_LABELS = ['1st','2nd','3rd','4th'];
const CURRENT_Q = <?= $current_q ?>;
const SY = <?= json_encode($sy_settings['school_year'] ?? '') ?>;

// ── Tabs ──────────────────────────────────────────────────
function switchTab(t) {
    ['adv','gc','pq'].forEach(id => {
        const p = document.getElementById('p-'+id);
        if (p) p.style.display = t===id ? '' : 'none';
        const btn = document.getElementById('t-'+id);
        if (btn) btn.classList.toggle('on', t===id);
    });
    if (t==='adv' && !advLoaded) loadAdvisory();
    if (t==='gc'  && !gcStudents.length) gcLoadStudents();
    if (t==='pq'  && !pqQuarters.length) pqInit();
}

// ── Helpers ───────────────────────────────────────────────
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    return fetch('', {method:'POST', body:fd}).then(r => r.json());
}
function toast(msg, type) {
    const t = document.getElementById('toast');
    t.innerHTML = (type==='ok'?'✓ ':'✕ ') + msg;
    t.className = 'toast on '+type;
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('on'), 2800);
}
function gradeCls(g) {
    if (g===null||g===undefined) return 'na';
    g = parseFloat(g);
    if (g>=90) return 'os'; if (g>=85) return 'vs';
    if (g>=80) return 'sa'; if (g>=75) return 'fs';
    return 'dn';
}
function gradeDesc(g) {
    if (g===null) return '—';
    g = parseFloat(g);
    if (g>=90) return 'Outstanding';
    if (g>=85) return 'Very Satisfactory';
    if (g>=80) return 'Satisfactory';
    if (g>=75) return 'Fairly Satisfactory';
    return 'Did Not Meet Expectations';
}
function gradeBadge(g) {
    const c = gradeCls(g);
    return `<span class="gc ${c}">${c==='na'?'—':gradeDesc(g)}</span>`;
}
function scoreClass(v) {
    v = parseFloat(v);
    return isNaN(v)||v===0 ? 'na' : v>=85 ? 'ok' : v>=60 ? 'warn' : 'bad';
}
function inputColorClass(g) {
    if (g===null||g===undefined||g==='') return '';
    g = parseFloat(g);
    if (isNaN(g)) return '';
    return g>=75 ? 'ok-g' : g>=60 ? 'warn-g' : 'bad-g';
}
function computeWeightedOverall(subjectGrades, weightMap) {
    let weightedSum = 0, totalWeight = 0;
    subjectGrades.forEach(sg => {
        if (sg.grade === null || sg.grade === undefined || sg.grade === '') return;
        const key    = sg.subject_name.toLowerCase();
        const weight = weightMap[key] ?? 0;
        if (weight <= 0) return;
        weightedSum += parseFloat(sg.grade) * (weight / 100);
        totalWeight += weight;
    });
    if (totalWeight <= 0) return null;
    return Math.round((weightedSum / totalWeight) * 100);
}
function showXLEmpty(id, icon, title, msg) {
    document.getElementById(id).innerHTML = `<div class="xl-empty"><i class="fas fa-${icon}"></i><h3>${title}</h3><p>${msg}</p></div>`;
}

// ══════ ADVISORY ════════════════════════════════════════
function loadAdvisory() {
    advLoaded = true;
    post({action:'get_advisory'}).then(d => {
        if (!d.success) { showXLEmpty('advCon','chalkboard','No Advisory Class',d.message); return; }
        advWeightMap = d.weight_map || {};
        renderAdvisory(d);
    });
}
function renderAdvisory(data) {
    if (!data.data.length) { showXLEmpty('advCon','users-slash','No Students','No active students in your advisory.'); return; }
    const hasWeights = Object.keys(advWeightMap).length > 0;
    const weightNote = hasWeights
        ? `<span style="color:var(--ok);"><i class="fas fa-check-circle"></i> Subject weights configured</span>`
        : `<span style="color:var(--warn);"><i class="fas fa-exclamation-triangle"></i> No subject weights set — using equal share</span>`;
    let html = `<div style="padding:5px 10px;background:var(--bg);border-bottom:1px solid var(--line);font-size:10px;color:var(--ink3);font-family:var(--mono);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:4px;">
        <span><strong style="color:var(--brand);">${esc(data.grade)} — ${esc(data.section)}</strong> &nbsp;·&nbsp; ${data.data.length} students &nbsp;·&nbsp;
        <span style="color:var(--brand);">Highlighted</span> = your subjects (editable)</span>
        <span style="font-size:10px;">${weightNote}</span>
    </div>
    <div style="overflow-x:auto;"><table class="xl">
        <thead><tr class="col-header">
            <th class="rn">#</th>
            <th class="fc">Student</th>
            <th>Subject Grades</th>
        </tr></thead><tbody>`;
    data.data.forEach((st, i) => {
        const pills = st.subjects.map(subj => {
            const key    = subj.subject_name.toLowerCase();
            const weight = advWeightMap[key];
            const wLabel = weight !== undefined ? ` <span style="font-size:9px;opacity:.7;">${Math.round(weight)}%</span>` : '';
                return `<div class="adv-pill">
                    <span class="adv-pill-name" data-subject-name="${esc(subj.subject_name)}">${esc(subj.subject_name)}${wLabel}</span>
                    <span class="adv-pill-g">${subj.final_grade !== null ? parseInt(subj.final_grade) : '—'}</span>
                </div>`;
        }).join('');
        html += `<tr class="ar" data-id="${st.id}" data-n="${(st.first_name+' '+st.last_name).toLowerCase()}">
            <td class="rn">${i+1}</td>
            <td class="fc">
                <div class="xl-name">${esc(st.last_name)}, ${esc(st.first_name)}</div>
                <div class="xl-sub">${esc(st.email)}</div>
            </td>
            <td><div class="adv-pill-wrap">${pills||'<span style="color:var(--ink4);font-size:11px;">No subjects</span>'}</div></td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    document.getElementById('advCon').innerHTML = html;
}
function saveAdvGrade(input, sid, subid) {
    const g = parseInt(input.value);
    if (isNaN(g)||g<0||g>100) { toast('Valid grade 0–100','err'); input.value=''; return; }
    post({action:'save_adv_grade', student_id:sid, subject_id:subid, final_grade:g}).then(d => {
        if (d.success) { toast('Saved!','ok'); recomputeAdvOverall(sid); }
        else toast(d.message||'Failed','err');
    });
}
function recomputeAdvOverall(studentId) {
    const row = document.querySelector(`.ar[data-id="${studentId}"]`);
    if (!row) return;
    const subjectGrades = [];
    row.querySelectorAll('.adv-pill').forEach(pill => {
        const nameEl   = pill.querySelector('.adv-pill-name');
        const inputEl  = pill.querySelector('.adv-pill-input');
        const staticEl = pill.querySelector('.adv-pill-g');
        if (!nameEl) return;
        const rawName = (nameEl.dataset.subjectName || nameEl.textContent).trim().replace(/\s*\d+%\s*$/, '').trim();
        let grade = null;
        if (inputEl && inputEl.value !== '') grade = parseFloat(inputEl.value);
        else if (staticEl && staticEl.textContent !== '—') grade = parseFloat(staticEl.textContent);
        subjectGrades.push({ subject_name: rawName, grade });
    });
}
function filterAdv() {
    const q = document.getElementById('advSrch').value.toLowerCase();
    document.querySelectorAll('.ar').forEach(r => r.style.display = r.dataset.n.includes(q)?'':'none');
}

// ══════ GRADE CARD ══════════════════════════════════════
function gcLoadStudents() {
    post({action:'gc_get_students'}).then(d => {
        if (!d.success) {
            document.getElementById('gcContent').innerHTML = `<div class="gc-placeholder"><i class="fas fa-chalkboard"></i><h3>No Advisory Class</h3><p>${d.message}</p></div>`;
            return;
        }
        gcStudents = d.students;
        const sel = document.getElementById('gcStudentSel');
        sel.innerHTML = '<option value="">— Choose a student —</option>';
        d.students.forEach(s => sel.innerHTML += `<option value="${s.id}">${esc(s.last_name)}, ${esc(s.first_name)}${s.lrn?' · '+s.lrn:''}</option>`);
    });
}
function gcSelectStudent(id) {
    if (!id) {
        document.getElementById('gcContent').innerHTML = `<div class="gc-placeholder"><i class="fas fa-id-card"></i><h3>No Student Selected</h3><p>Choose a student above.</p></div>`;
        gcCurrentStudent=null; gcData=null; return;
    }
    gcCurrentStudent = gcStudents.find(s => s.id==id);
    document.getElementById('gcContent').innerHTML = '<div class="xl-loader" style="padding:40px;"><i class="fas fa-spinner"></i></div>';
    post({action:'gc_get_grades', student_id:id}).then(d => {
        if (!d.success) { toast(d.message||'Error','err'); return; }
        gcData = d; gcRender(d);
    });
}
function gcGradeClass(g) {
    if (g===null||g==='') return '';
    g = parseFloat(g);
    return g>=85?'ok-g':g>=75?'warn-g':'bad-g';
}
function gcRender(d) {
    const st = gcCurrentStudent;
    const initials = ((st.first_name||'')[0]+(st.last_name||'')[0]).toUpperCase();
    const completedAvgs = [];
    d.quarters.forEach(q => {
        if (q.is_locked) {
            const vals = q.subjects.map(s=>s.grade).filter(g=>g!==null);
            if (vals.length) completedAvgs.push(vals.reduce((a,b)=>a+parseFloat(b),0)/vals.length);
        }
    });
    const gwa = completedAvgs.length ? Math.round(completedAvgs.reduce((a,b)=>a+b,0)/completedAvgs.length) : null;

    let html = `<div class="gc-layout">
    <div>
        <div class="gc-info-card">
            <div class="gc-info-hd">
                <div class="gc-ava">${esc(initials)}</div>
                <div class="gc-sname">${esc(st.first_name+' '+st.last_name)}</div>
                <div class="gc-smeta"><span>${esc(st.grade_level)}</span> · <span>${esc(st.section)}</span></div>
            </div>
            <div class="gc-gwa-box">
                <div>
                    <div class="gc-gwa-lbl">Current GWA</div>
                    <div style="font-size:9px;color:var(--ink4);margin-top:1px;">${gwa!==null?gradeDesc(gwa):'No completed quarters'}</div>
                </div>
                <div class="gc-gwa-val">${gwa!==null?gwa:'—'}</div>
            </div>
            <div class="gc-rows">
                <div class="gc-row"><span class="gc-key">LRN</span><span class="gc-val mono">${esc(st.lrn||'—')}</span></div>
                <div class="gc-row"><span class="gc-key">Age</span><span class="gc-val">${st.age!==null?st.age+' yrs':'—'}</span></div>
                <div class="gc-row"><span class="gc-key">Grade Level</span><span class="gc-val">${esc(st.grade_level)}</span></div>
                <div class="gc-row"><span class="gc-key">Section</span><span class="gc-val">${esc(st.section)}</span></div>
                <div class="gc-row"><span class="gc-key">School Year</span><span class="gc-val">${esc(d.school_year)}</span></div>
                <div class="gc-row"><span class="gc-key">Guardian</span><span class="gc-val">${esc(st.guardian_name||'—')}</span></div>
                <div class="gc-row"><span class="gc-key">Contact</span><span class="gc-val mono">${esc(st.guardian_contact||'—')}</span></div>
                <div class="gc-row"><span class="gc-key">Quarter</span><span class="gc-val" style="color:var(--brand);font-weight:700;">${QUARTER_LABELS[d.current_quarter-1]} Grading</span></div>
            </div>
            <div class="gc-btn-group">
                <button class="gc-print-btn" onclick="gcPrint()"><i class="fas fa-print"></i> Print Grade Card</button>
            </div>
        </div>
    </div>
    <div>
        <div class="gc-panel">
            <div class="gc-qtabs">`;

    d.quarters.forEach((q,i) => {
        html += `<button class="gc-qtab ${q.is_locked?'locked-tab':''}${i===d.current_quarter-1?' on':''}" onclick="gcSwitchQ(${i})">
            ${q.is_locked?'<i class="fas fa-lock" style="font-size:9px;"></i>':''}
            ${q.is_current?'<div class="q-dot"></div>':''}
            ${QUARTER_LABELS[i]}Q
        </button>`;
    });
    html += `</div>`;

    d.quarters.forEach((q,i) => {
        // Group subjects by category
        const categories = {};
        q.subjects.forEach(sub => {
            const cat = sub.category || 'Other Subjects';
            if (!categories[cat]) categories[cat] = [];
            categories[cat].push(sub);
        });

        html += `<div class="gc-qbody${i===d.current_quarter-1?' on':''}" id="gcq-${i}">
            <div class="gc-qtopbar">
                <div class="gc-qtopbar-l">
                    <span class="gc-qtitle">${QUARTER_LABELS[i]} Quarter</span>
                    ${q.is_locked?'<span class="gc-qbadge locked-b"><i class="fas fa-lock" style="font-size:8px;margin-right:2px;"></i>Locked</span>':''}
                    ${q.is_current?'<span class="gc-qbadge active-b">Current</span>':''}
                    ${q.is_future?'<span class="gc-qbadge future-b">Not yet</span>':''}
                </div>
                ${q.is_current?`<button class="gc-save-btn" onclick="gcSaveQuarter(${i},${st.id})"><i class="fas fa-save"></i> Save</button>`:''}
            </div>`;

        if (q.is_future) {
            html += `<div class="gc-future-empty"><i class="fas fa-hourglass-half"></i><p>Not yet available.</p></div>`;
        } else {
            if (q.is_locked) html += `<div class="gc-lock-notice"><i class="fas fa-lock"></i> Finalized — read only.</div>`;
            html += `<table class="gc-subj-table">
                <thead><tr><th>Subject</th><th>Teacher</th><th style="text-align:center;">Grade</th><th style="text-align:center;">Descriptor</th></tr></thead>
                <tbody id="gcqbody-${i}">`;

            Object.entries(categories).forEach(([catName, subs]) => {
                html += `<tr class="gc-category-row"><td colspan="4">${esc(catName)}</td></tr>`;
                subs.forEach(sub => {
                    const g = sub.grade;
                    html += `<tr>
                        <td><div class="gc-sn">${esc(sub.subject_name)}</div>
                            ${sub.is_prefilled?'<div class="gc-hint">↑ pre-filled</div>':''}
                            ${sub.is_saved?'<div class="gc-hint" style="color:var(--ok);">✓ saved</div>':''}
                            ${(!sub.is_saved&&!sub.is_prefilled&&!q.is_locked)?'<div class="gc-hint-empty">no grade</div>':''}
                        </td>
                        <td style="font-size:10px;color:var(--ink4);">${esc(sub.teacher_name)}</td>
                        <td style="text-align:center;">
                            <input class="gc-gi ${gcGradeClass(g)}" type="number" min="60" max="100" step="1"
                                value="${g!==null?Math.round(g):''}" placeholder="—"
                                data-subject-id="${sub.subject_id}"
                                data-subject-name="${esc(sub.subject_name)}"
                                data-category="${esc(sub.category||'')}"
                                ${q.is_locked?'disabled':''}
                                oninput="gcOnInput(this,'gcqavg-${i}')">
                        </td>
                        <td style="text-align:center;" id="gcqdesc-${i}-${sub.subject_id}">${gradeBadge(g!==null?Math.round(g):null)}</td>
                    </tr>`;
                });
            });

            const vals = q.subjects.map(s=>s.grade).filter(g=>g!==null&&g!=='');
            const qAvg = vals.length ? Math.round(vals.reduce((a,b)=>a+parseFloat(b),0)/vals.length) : null;
            html += `</tbody></table>
            <div class="gc-avg-bar">
                <span class="gc-avg-lbl">Quarter Average</span>
                <span class="gc-avg-val" id="gcqavg-${i}">${qAvg!==null?qAvg:'—'}</span>
            </div>`;
        }
        html += `</div>`;
    });
    html += `</div></div></div>`;
    document.getElementById('gcContent').innerHTML = html;
}
function gcSwitchQ(i) {
    document.querySelectorAll('.gc-qtab').forEach((t,idx) => t.classList.toggle('on',idx===i));
    document.querySelectorAll('.gc-qbody').forEach((b,idx) => b.classList.toggle('on',idx===i));
}
function gcOnInput(input, avgId) {
    const g = parseFloat(input.value);
    input.className = 'gc-gi '+(isNaN(g)?'':gcGradeClass(g));
    const qi = avgId.split('-')[1];
    const descCell = document.getElementById(`gcqdesc-${qi}-${input.dataset.subjectId}`);
    if (descCell) descCell.innerHTML = gradeBadge(isNaN(g)?null:Math.round(g));
    const inputs = document.querySelectorAll(`#gcqbody-${qi} .gc-gi:not(:disabled)`);
    const vals = Array.from(inputs).map(i=>parseFloat(i.value)).filter(v=>!isNaN(v));
    const a = vals.length ? Math.round(vals.reduce((a,b)=>a+b,0)/vals.length) : null;
    document.getElementById(avgId).textContent = a!==null?a:'—';
}
function gcSaveQuarter(qi, studentId) {
    const inputs = document.querySelectorAll(`#gcqbody-${qi} .gc-gi:not(:disabled)`);
    const grades = [];
    inputs.forEach(inp => { const g=inp.value.trim(); if(g!=='') grades.push({subject_id:parseInt(inp.dataset.subjectId),grade:Math.round(parseFloat(g))}); });
    if (!grades.length) { toast('No grades to save','err'); return; }
    post({action:'gc_save_quarter', student_id:studentId, quarter:qi+1, grades:JSON.stringify(grades)}).then(d => {
        if (d.success) {
            toast(`${QUARTER_LABELS[qi]} Quarter saved!`,'ok');
            document.querySelectorAll(`#gcqbody-${qi} tr`).forEach(row => {
                const hint = row.querySelector('.gc-hint, .gc-hint-empty');
                if (hint) { hint.className='gc-hint'; hint.style.color='var(--ok)'; hint.textContent='✓ saved'; }
            });
        } else toast(d.message||'Failed','err');
    });
}

// ══════════════════════════════════════════════════════════
// BUILD DEPED PRINT CARD
// ══════════════════════════════════════════════════════════
function gcBuildCard() {
    if (!gcCurrentStudent || !gcData) return false;
    const st   = gcCurrentStudent;
    const d    = gcData;
    const now  = new Date();
    const initials = ((st.first_name||'')[0]+(st.last_name||'')[0]).toUpperCase();

    // Compute GWA from locked quarters
    const lockedAvgs = [];
    d.quarters.forEach(q => {
        if (q.is_locked) {
            const vals = q.subjects.map(s=>s.grade).filter(g=>g!==null&&g!=='');
            if (vals.length) lockedAvgs.push(vals.reduce((a,b)=>a+parseFloat(b),0)/vals.length);
        }
    });
    const gwa     = lockedAvgs.length ? Math.round(lockedAvgs.reduce((a,b)=>a+b,0)/lockedAvgs.length) : null;
    const gwaDesc = gwa !== null ? gradeDesc(gwa) : '—';

    // Helper: get grades for a specific quarter
    function getQData(qIdx) {
        const q = d.quarters[qIdx];
        if (!q || q.is_future) return { subjects: [], avg: null };
        // Get values from live DOM inputs (respects unsaved edits)
        const inputMap = {};
        document.querySelectorAll(`#gcqbody-${qIdx} .gc-gi`).forEach(inp => {
            const g = inp.value.trim();
            if (g !== '') inputMap[inp.dataset.subjectId] = Math.round(parseFloat(g));
        });
        const subjects = q.subjects.map(sub => ({
            subject_name : sub.subject_name,
            teacher_name : sub.teacher_name,
            category     : sub.category || 'Other Subjects',
            grade        : inputMap[sub.subject_id] ?? (sub.grade !== null ? Math.round(parseFloat(sub.grade)) : null)
        }));
        const gradeVals = subjects.map(s=>s.grade).filter(g=>g!==null);
        const avg       = gradeVals.length ? Math.round(gradeVals.reduce((a,b)=>a+b,0)/gradeVals.length) : null;
        return { subjects, avg };
    }

    // Gather categories (union across all quarters)
    const allCategories = new Set();
    d.quarters.forEach(q => q.subjects.forEach(s => allCategories.add(s.category || 'Other Subjects')));

    // Build semester table HTML
    function buildSemTable(q1Idx, q2Idx) {
        const q1 = getQData(q1Idx);
        const q2 = getQData(q2Idx);

        // Merge subjects from both quarters (by category)
        const categoryMap = {};
        const allSubjects = [...(d.quarters[q1Idx]?.subjects||[]), ...(d.quarters[q2Idx]?.subjects||[])];
        const seenSubjects = new Set();
        allSubjects.forEach(s => {
            if (seenSubjects.has(s.subject_id)) return;
            seenSubjects.add(s.subject_id);
            const cat = s.category || 'Other Subjects';
            if (!categoryMap[cat]) categoryMap[cat] = [];
            categoryMap[cat].push(s);
        });

        let rows = '';
        Object.entries(categoryMap).forEach(([cat, subs]) => {
            rows += `<tr><td class="category-header" colspan="4">${cat}</td></tr>`;
            subs.forEach(sub => {
                const g1 = q1.subjects.find(s=>s.subject_name===sub.subject_name)?.grade ?? null;
                const g2 = q2.subjects.find(s=>s.subject_name===sub.subject_name)?.grade ?? null;
                const semFG = (g1!==null && g2!==null) ? Math.round((g1+g2)/2) : (g1??g2);
                rows += `<tr>
                    <td class="subj-name">${sub.subject_name}</td>
                    <td>${g1!==null?g1:''}</td>
                    <td>${g2!==null?g2:''}</td>
                    <td style="font-weight:700;">${semFG!==null?semFG:''}</td>
                </tr>`;
            });
        });

        // General average
        const q1avg = q1.avg; const q2avg = q2.avg;
        const semAvg = (q1avg!==null && q2avg!==null) ? Math.round((q1avg+q2avg)/2)
                     : (q1avg ?? q2avg);
        rows += `<tr class="gen-avg-row">
            <td class="subj-name" style="text-align:right;font-weight:700;">General Average for the Semester</td>
            <td>${q1avg!==null?q1avg:''}</td>
            <td>${q2avg!==null?q2avg:''}</td>
            <td style="font-weight:700;">${semAvg!==null?semAvg:'#DIV/0!'}</td>
        </tr>`;
        return rows;
    }

    // DepEd Observed Values data
    const observedValues = [
        { core: '1. Maka-Diyos', behaviors: [
            'Expresses one\'s spiritual beliefs while respecting the spiritual beliefs of others',
            'Shows adherence to ethical principles by upholding truth in all undertakings'
        ]},
        { core: '2. Makatao', behaviors: [
            'Is sensitive to individual, social and cultural differences; resists stereotyping people'
        ]},
        { core: '3. Makakalikasan', behaviors: [
            'Demonstrates contributions toward solidarity',
            'Cares for the environment and utilizes resources wisely, judiciously and economically'
        ]},
        { core: '4. Makabansa', behaviors: [
            'Demonstrates pride in being a Filipino; exercises the rights and responsibilities of a Filipino citizen',
            'Demonstrates appropriate behavior in carrying out activities in the school, community and country'
        ]}
    ];

    let ovRows = '';
    observedValues.forEach(ov => {
        ov.behaviors.forEach((beh, bi) => {
            ovRows += `<tr>
                ${bi===0 ? `<td class="core-val" rowspan="${ov.behaviors.length}">${ov.core}</td>` : ''}
                <td class="behavior">${beh}</td>
                <td class="q-cell"></td>
                <td class="q-cell"></td>
                <td class="q-cell"></td>
                <td class="q-cell"></td>
            </tr>`;
        });
    });

    const cardHTML = `
    <div class="deped-header">
        <div class="deped-header-logo">${initials}</div>
        <div class="deped-header-text">
            <div class="school-name">Ashi Amaya School</div>
            <div class="school-sub">Department of Education · Republic of the Philippines</div>
            <div class="school-sub">School Year ${d.school_year}</div>
        </div>
    </div>
    <div class="deped-card-title">Learner's Progress Report Card</div>

    <!-- Student Info Strip -->
    <div class="deped-student-strip">
        <div class="deped-si-cell">
            <div class="deped-si-label">Learner's Name</div>
            <div class="deped-si-value">${st.last_name}, ${st.first_name}</div>
        </div>
        <div class="deped-si-cell">
            <div class="deped-si-label">LRN</div>
            <div class="deped-si-value">${st.lrn||'—'}</div>
        </div>
        <div class="deped-si-cell">
            <div class="deped-si-label">Grade Level &amp; Section</div>
            <div class="deped-si-value">${st.grade_level} — ${st.section}</div>
        </div>
        <div class="deped-si-cell">
            <div class="deped-si-label">School Year</div>
            <div class="deped-si-value">${d.school_year}</div>
        </div>
    </div>

    <!-- Two-column layout: Grade Tables | Observed Values -->
    <div class="deped-two-col">
        <!-- LEFT: Semester Grade Tables -->
        <div>
            <!-- First Semester -->
            <div class="deped-sem-block">
                <div class="deped-sem-title">First Semester</div>
                <table class="deped-grade-table">
                    <thead>
                        <tr>
                            <th rowspan="2" style="text-align:left;padding-left:6px;">Subjects</th>
                            <th colspan="2">Quarter</th>
                            <th rowspan="2">Semester<br>Final Grade</th>
                        </tr>
                        <tr>
                            <th>1</th>
                            <th>2</th>
                        </tr>
                    </thead>
                    <tbody>${buildSemTable(0, 1)}</tbody>
                </table>
            </div>

            <!-- Second Semester -->
            <div class="deped-sem-block">
                <div class="deped-sem-title">Second Semester</div>
                <table class="deped-grade-table">
                    <thead>
                        <tr>
                            <th rowspan="2" style="text-align:left;padding-left:6px;">Subjects</th>
                            <th colspan="2">Quarter</th>
                            <th rowspan="2">Semester<br>Final Grade</th>
                        </tr>
                        <tr>
                            <th>3</th>
                            <th>4</th>
                        </tr>
                    </thead>
                    <tbody>${buildSemTable(2, 3)}</tbody>
                </table>
            </div>

            <!-- GWA -->
            <div style="margin-top:6px;padding:4px 6px;border:1px solid #000;display:flex;justify-content:space-between;align-items:center;font-size:10.5px;">
                <strong>General Weighted Average:</strong>
                <span style="font-size:13px;font-weight:700;">${gwa!==null?gwa:'—'}</span>
                <span style="font-size:9.5px;color:#444;">${gwaDesc}</span>
            </div>
        </div>

        <!-- RIGHT: Observed Values + Legend -->
        <div>
            <div class="deped-ov-title">Report on Learner's Observed Values</div>
            <table class="deped-ov-table">
                <thead>
                    <tr>
                        <th style="width:80px;">Core Values</th>
                        <th>Behavior Statements</th>
                        <th>1</th><th>2</th><th>3</th><th>4</th>
                    </tr>
                    <tr>
                        <th colspan="2" style="text-align:right;font-style:italic;">Quarter →</th>
                        <th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th>
                    </tr>
                </thead>
                <tbody>${ovRows}</tbody>
            </table>

            <!-- Observed Values Legend -->
            <div class="deped-legend">
                <div class="deped-legend-title">Observed Values</div>
                <div class="deped-legend-grid">
                    <div class="deped-legend-col">
                        <div style="font-size:8.5px;font-weight:700;margin-bottom:2px;text-transform:uppercase;letter-spacing:.3px;">Marking</div>
                        <div class="deped-legend-row"><span class="deped-legend-code">AO</span><span>Always Observed</span></div>
                        <div class="deped-legend-row"><span class="deped-legend-code">SO</span><span>Sometimes Observed</span></div>
                        <div class="deped-legend-row"><span class="deped-legend-code">RO</span><span>Rarely Observed</span></div>
                        <div class="deped-legend-row"><span class="deped-legend-code">NO</span><span>Not Observed</span></div>
                    </div>
                    <div class="deped-legend-col">
                        <div style="font-size:8.5px;font-weight:700;margin-bottom:2px;text-transform:uppercase;letter-spacing:.3px;">Learner Progress &amp; Achievement</div>
                        <table class="deped-scale-table">
                            <thead><tr><th>Descriptors</th><th>Grading Scale</th><th>Remarks</th></tr></thead>
                            <tbody>
                                <tr><td>Outstanding</td><td>90–100</td><td>Passed</td></tr>
                                <tr><td>Very Satisfactory</td><td>85–89</td><td>Passed</td></tr>
                                <tr><td>Satisfactory</td><td>80–84</td><td>Passed</td></tr>
                                <tr><td>Fairly Satisfactory</td><td>75–79</td><td>Passed</td></tr>
                                <tr><td>Did Not Meet Expectation</td><td>Below 75</td><td>Failed</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="deped-signatures">
        <div class="deped-sig-block">
            <div class="deped-sig-name">&nbsp;</div>
            <div class="deped-sig-lbl">Advisory Teacher Signature over Printed Name</div>
        </div>
        <div class="deped-sig-block">
            <div class="deped-sig-name">&nbsp;</div>
            <div class="deped-sig-lbl">Parent / Guardian Signature over Printed Name</div>
        </div>
        <div class="deped-sig-block">
            <div class="deped-sig-name">&nbsp;</div>
            <div class="deped-sig-lbl">Date</div>
        </div>
    </div>

    <div class="deped-footer">
        <span>Computer-generated document · For official use only · Ashi Amaya School</span>
        <span>Printed: ${now.toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'})}</span>
    </div>`;

    document.getElementById('depedCardEl').innerHTML = cardHTML;
    return true;
}

function gcPrint() {
    if (!gcBuildCard()) { toast('No student selected','err'); return; }
    window.print();
}

// ══════ PAST QUARTERS ══════════════════════════════════
function pqInit() {
    post({action:'pq_get_quarters'}).then(d => {
        if (!d.success) return;
        pqQuarters = d.past_quarters;
        if (!pqQuarters.length) {
            const noData = '<div class="xl-empty"><i class="fas fa-hourglass-start"></i><h3>No Past Quarters</h3><p>Past data will appear once the quarter advances.</p></div>';
            document.getElementById('pqSubCon').innerHTML = noData;
            const ac = document.getElementById('pqAdvCon');
            if (ac) ac.innerHTML = noData;
            return;
        }
        pqQuarters.forEach(q => {
            const opt = `<option value="${q.quarter}">${q.label} — ${q.school_year}${q.has_records?'':' (no records)'}</option>`;
            document.getElementById('pqSubQ').innerHTML += opt;
            const advQ = document.getElementById('pqAdvQ');
            if (advQ) advQ.innerHTML += opt;
        });
    });
}
function pqSwitch(v) {
    ['sub','adv'].forEach(id => {
        const el = document.getElementById('pq-'+id);
        if (el) el.style.display = v===id?'':'none';
        const btn = document.getElementById('pqst-'+id);
        if (btn) btn.classList.toggle('on', v===id);
    });
}
function pqSubOnQuarter() {
    const q = document.getElementById('pqSubQ').value;
    document.getElementById('pqSubSec').disabled = !q;
    document.getElementById('pqSubSec').value = '';
    document.getElementById('pqSubSubject').innerHTML = '<option value="">— Subject —</option>';
    document.getElementById('pqSubSubject').disabled = true;
    document.getElementById('pqSubSearch').disabled = true;
    document.getElementById('pqSubStats').style.display = 'none';
    document.getElementById('pqSubCon').innerHTML = '<div class="xl-empty"><i class="fas fa-book"></i><h3>Select Section &amp; Subject</h3></div>';
}
function pqSubOnSection() {
    const q   = document.getElementById('pqSubQ').value;
    const v   = document.getElementById('pqSubSec').value;
    const sub = document.getElementById('pqSubSubject');
    sub.innerHTML = '<option value="">— Subject —</option>';
    sub.disabled = true;
    if (!v||!q) return;
    const [grade, section] = v.split('|');
    post({action:'get_subjects', grade:grade, section:section}).then(d => {
        d.data.forEach(item => sub.innerHTML += `<option value="${item.id}">${esc(item.subject_name)}</option>`);
        sub.disabled = false;
    });
}
function pqSubLoad() {
    const q = document.getElementById('pqSubQ').value;
    const s = document.getElementById('pqSubSubject').value;
    if (!q||!s) return;
    document.getElementById('pqSubStats').style.display = 'none';
    document.getElementById('pqSubCon').innerHTML = '<div class="xl-loader"><i class="fas fa-spinner"></i></div>';
    post({action:'pq_subject_grades', subject_id:s, quarter:q}).then(d => {
        if (!d.success) { showXLEmpty('pqSubCon','exclamation-circle','Error',d.message); return; }
        pqSubCache = d.students;
        document.getElementById('pqSubSearch').disabled = false;
        pqSubRender(d);
    });
}
function pqSubRender(d) {
    const students = d.students;
    if (!students.length) { showXLEmpty('pqSubCon','users-slash','No Students','No enrolled students.'); return; }
    const graded  = students.filter(s=>s.grade!==null);
    const grades  = graded.map(s=>parseFloat(s.grade));
    const avg     = grades.length ? Math.round(grades.reduce((a,b)=>a+b,0)/grades.length) : null;
    document.getElementById('pqSTotal').textContent  = students.length;
    document.getElementById('pqSGraded').textContent = graded.length;
    document.getElementById('pqSAvg').textContent    = avg!==null?avg:'—';
    document.getElementById('pqSPass').textContent   = grades.filter(g=>g>=75).length;
    document.getElementById('pqSOut').textContent    = grades.filter(g=>g>=90).length;
    document.getElementById('pqSubStats').style.display = '';
    const rows = students.map((s,i) => {
        const g = s.grade!==null ? Math.round(parseFloat(s.grade)) : null;
        return `<tr class="pqsr" data-n="${(s.first_name+' '+s.last_name+' '+(s.lrn||'')).toLowerCase()}">
            <td class="rn">${i+1}</td>
            <td><div class="xl-name">${esc(s.last_name)}, ${esc(s.first_name)}</div><div class="xl-sub">${s.lrn?'LRN: '+esc(s.lrn):''}</div></td>
            <td style="text-align:center;">${g!==null?`<span class="pq-grade ${gradeCls(g)}">${g}</span>`:'<span class="pq-grade na">—</span>'}</td>
            <td style="text-align:center;">${g!==null?gradeBadge(g):'<span style="color:var(--ink4);">No grade</span>'}</td>
            <td style="font-size:11px;color:var(--ink4);">${esc(s.saved_by_name||'—')}</td>
            <td style="text-align:center;font-size:10px;font-family:var(--mono);">
                <span style="color:${s.is_locked?'var(--ok)':'var(--warn)'};font-weight:700;">
                    ${s.is_locked?'🔒 Locked':'🔓 Unlocked'}
                </span>
            </td>
        </tr>`;
    }).join('');
    document.getElementById('pqSubCon').innerHTML = `
        <div class="pq-lock-banner"><i class="fas fa-lock"></i>
            <strong>${esc(d.subject.subject_name)}</strong> &nbsp;·&nbsp; ${QUARTER_LABELS[d.quarter-1]} Quarter &nbsp;·&nbsp; ${esc(d.school_year)} &nbsp;·&nbsp; Read-only
        </div>
        <div style="overflow-x:auto;"><table class="xl">
            <thead><tr class="col-header">
                <th class="rn">#</th><th class="fc">Student</th>
                <th style="text-align:center;">Grade</th><th style="text-align:center;">Descriptor</th>
                <th>Saved By</th><th style="text-align:center;">Lock</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table></div>`;
}
function pqSubFilter() {
    const q = document.getElementById('pqSubSearch').value.toLowerCase();
    document.querySelectorAll('.pqsr').forEach(r => r.style.display = r.dataset.n.includes(q)?'':'none');
}
function pqAdvLoad() {
    const q = document.getElementById('pqAdvQ').value;
    if (!q) return;
    document.getElementById('pqAdvSearch').disabled = true;
    document.getElementById('pqAdvStats').style.display = 'none';
    document.getElementById('pqAdvCon').innerHTML = '<div class="xl-loader"><i class="fas fa-spinner"></i></div>';
    post({action:'pq_advisory_grades', quarter:q}).then(d => {
        if (!d.success) { showXLEmpty('pqAdvCon','exclamation-circle','Error',d.message); return; }
        pqAdvCache = d;
        document.getElementById('pqAdvSearch').disabled = false;
        pqAdvRender(d);
    });
}
function pqAdvRender(d) {
    const students = d.students;
    if (!students.length) { showXLEmpty('pqAdvCon','users-slash','No Students','No active students.'); return; }
    const avgs = students.map(s=>s.avg).filter(a=>a!==null).map(Number);
    const classAvg = avgs.length ? Math.round(avgs.reduce((a,b)=>a+b,0)/avgs.length) : null;
    document.getElementById('pqATotal').textContent = students.length;
    document.getElementById('pqAAvg').textContent   = classAvg!==null?classAvg:'—';
    document.getElementById('pqAPass').textContent  = avgs.filter(a=>a>=75).length;
    document.getElementById('pqAOut').textContent   = avgs.filter(a=>a>=90).length;
    document.getElementById('pqAdvStats').style.display = '';
    const subjectIds = Object.keys(d.subjects);
    const subIds = subjectIds.slice(0,7);
    const subHeaders = subIds.map(id => `<th class="center" style="max-width:90px;font-size:9px;" title="${esc(d.subjects[id])}">${esc(d.subjects[id].length>11?d.subjects[id].slice(0,11)+'…':d.subjects[id])}</th>`).join('');
    const hasMore = subjectIds.length > 7;
    const rows = students.map((st,i) => {
        const subCells = subIds.map(sid => {
            const found = st.subjects.find(s=>s.subject_id==sid);
            const g = found?.grade ?? null;
            const gr = g!==null ? Math.round(parseFloat(g)) : null;
            return `<td class="center">${gr!==null?`<span class="pq-grade ${gradeCls(gr)}">${gr}</span>`:'<span class="pq-grade na">—</span>'}</td>`;
        }).join('');
        const avg = st.avg!==null ? Math.round(parseFloat(st.avg)) : null;
        return `<tr class="pq-adv-row" data-id="${st.id}" data-n="${(st.first_name+' '+st.last_name+' '+(st.lrn||'')).toLowerCase()}" onclick="pqAdvOpenModal(${st.id})">
            <td class="rn">${i+1}</td>
            <td>
                <div style="display:flex;align-items:center;gap:6px;">
                    <div class="pq-expand-ico"><i class="fas fa-expand-alt"></i></div>
                    <div><div class="xl-name">${esc(st.last_name)}, ${esc(st.first_name)}</div><div class="xl-sub">${st.lrn?'LRN: '+esc(st.lrn):''}</div></div>
                </div>
            </td>
            ${subCells}
            ${hasMore?`<td class="center"><span style="font-size:9px;color:var(--brand);font-weight:700;font-family:var(--mono);">+${subjectIds.length-7}</span></td>`:''}
            <td class="center">${avg!==null?`<span class="pq-grade ${gradeCls(avg)}" style="font-size:13px;">${avg}</span>`:'<span class="pq-grade na">—</span>'}</td>
        </tr>`;
    }).join('');
    document.getElementById('pqAdvCon').innerHTML = `
        <div class="pq-lock-banner"><i class="fas fa-lock"></i>
            <strong>${esc(d.grade)} — ${esc(d.section)}</strong> &nbsp;·&nbsp; ${QUARTER_LABELS[d.quarter-1]} Quarter &nbsp;·&nbsp; ${esc(d.school_year)} &nbsp;·&nbsp; Click row for details
        </div>
        <div style="overflow-x:auto;"><table class="pq-adv-table">
            <thead><tr>
                <th class="rn">#</th><th>Student</th>
                ${subHeaders}
                ${hasMore?'<th class="center">…</th>':''}
                <th class="center">Average</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table></div>`;
}
function pqAdvFilter() {
    const q = document.getElementById('pqAdvSearch').value.toLowerCase();
    document.querySelectorAll('.pq-adv-row').forEach(r => r.style.display = r.dataset.n.includes(q)?'':'none');
}
function pqAdvOpenModal(studentId) {
    if (!pqAdvCache) return;
    const st = pqAdvCache.students.find(s=>s.id==studentId);
    if (!st) return;
    const initials = ((st.first_name||'')[0]+(st.last_name||'')[0]).toUpperCase();
    const avg = st.avg!==null ? Math.round(parseFloat(st.avg)) : null;
    document.getElementById('pqModalTitle').innerHTML = `${esc(st.first_name+' '+st.last_name)} — ${QUARTER_LABELS[pqAdvCache.quarter-1]}Q`;
    const subRows = st.subjects.map(sub => {
        const g = sub.grade!==null ? Math.round(parseFloat(sub.grade)) : null;
        return `<div style="display:flex;justify-content:space-between;align-items:center;padding:7px 8px;border:1px solid var(--line);border-radius:var(--r);margin-bottom:3px;">
            <div><div style="font-weight:600;font-size:12px;">${esc(sub.subject_name)}</div><div style="font-size:10px;color:var(--ink4);">${esc(sub.teacher_name)}</div></div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span class="pq-grade ${gradeCls(g)}">${g!==null?g:'—'}</span>
                ${g!==null?gradeBadge(g):''}
            </div>
        </div>`;
    }).join('');
    document.getElementById('pqModalBody').innerHTML = `
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid var(--line);">
            <div style="width:36px;height:36px;border-radius:50%;background:var(--brand);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;font-family:var(--mono);">${esc(initials)}</div>
            <div>
                <div style="font-size:13px;font-weight:700;">${esc(st.first_name+' '+st.last_name)}</div>
                <div style="font-size:10px;color:var(--ink4);font-family:var(--mono);">${st.lrn?'LRN: '+esc(st.lrn)+' · ':''} ${esc(pqAdvCache.grade)} — ${esc(pqAdvCache.section)}</div>
            </div>
        </div>
        ${subRows || '<p style="font-size:12px;color:var(--ink4);">No subjects.</p>'}
        <div style="margin-top:10px;background:var(--brand-dim);border:1px solid var(--brand-mid);border-radius:var(--r);padding:8px 12px;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-size:10px;font-weight:700;color:var(--brand);font-family:var(--mono);">Quarter Average (simple)</div>
                <div style="font-size:9px;color:var(--ink4);">${QUARTER_LABELS[pqAdvCache.quarter-1]}Q · ${esc(pqAdvCache.school_year)}</div>
            </div>
            <div style="display:flex;align-items:center;gap:7px;">
                <span style="font-size:18px;font-weight:700;color:var(--brand);font-family:var(--mono);">${avg!==null?avg:'—'}</span>
                ${avg!==null?gradeBadge(avg):''}
            </div>
        </div>`;
    document.getElementById('pqModal').classList.add('on');
}

// Close modals on Escape
document.getElementById('pqModal').addEventListener('click', e => { if(e.target===document.getElementById('pqModal'))document.getElementById('pqModal').classList.remove('on'); });
document.addEventListener('keydown', e => { if(e.key==='Escape') document.getElementById('pqModal').classList.remove('on'); });

// Auto-load Advisory on first visit
loadAdvisory();
</script>
</body>
</html>