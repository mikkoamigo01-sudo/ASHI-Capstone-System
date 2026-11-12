<?php
session_start();

$host    = 'localhost';
$dbname  = 'u304756553_ashi_classroom';
$db_user = 'u304756553_ashi_classroom';
$db_pass = 'Ashi_Classroom17';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]));
}

function respond(bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit();
}
function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}
function randCode(int $len = 7): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    for ($i = 0; $i < $len; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

// ════════════════════════════════════════════════════════════════
//  AJAX
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'get_teachers') {
        $rows = $pdo->query(
            "SELECT id, fullname, email, specialization, advisory_grade, advisory_section, theme_color
             FROM teachers WHERE status='active' ORDER BY fullname ASC"
        )->fetchAll();
        respond(true, 'ok', ['teachers' => $rows]);
    }

    if ($action === 'get_subjects') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $grade     = clean($_POST['grade']      ?? '');
        $section   = clean($_POST['section']    ?? '');
        $where = ['1=1'];
        $params = [];
        if ($teacherId) { $where[] = 's.teacher_id = ?'; $params[] = $teacherId; }
        if ($grade)     { $where[] = 's.grade = ?';      $params[] = $grade; }
        if ($section)   { $where[] = 's.section = ?';    $params[] = $section; }
        $sql = "SELECT s.*, t.fullname AS teacher_name, t.theme_color AS teacher_color,
                       (SELECT COUNT(*) FROM student_subjects ss WHERE ss.subject_id = s.id AND ss.status='active') AS enrolled_count
                FROM subjects s
                JOIN teachers t ON t.id = s.teacher_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY CAST(REGEXP_REPLACE(s.grade, '[^0-9]', '') AS UNSIGNED) ASC, s.subject_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond(true, 'ok', ['subjects' => $stmt->fetchAll()]);
    }

    if ($action === 'get_sections') {
        $grade = clean($_POST['grade'] ?? '');
        if (!$grade) respond(false, 'Grade required.');
        $stmt = $pdo->prepare(
            "SELECT id, section_name FROM sections WHERE grade_level=? AND status='active' ORDER BY section_name ASC"
        );
        $stmt->execute([$grade]);
        respond(true, 'ok', ['sections' => $stmt->fetchAll()]);
    }

    if ($action === 'get_students_for_enrollment') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $section   = clean($_POST['section']    ?? '');
        if (!$subjectId) respond(false, 'Subject ID required.');
        $subjRow = $pdo->prepare("SELECT grade, section FROM subjects WHERE id=? LIMIT 1");
        $subjRow->execute([$subjectId]);
        $subj  = $subjRow->fetch();
        $grade = $subj ? $subj['grade'] : '';
        $where  = ["st.status='active'"];
        $params = [];
        if ($grade)   { $where[] = "st.grade_level=?"; $params[] = $grade; }
        if ($section) { $where[] = "st.section=?";     $params[] = $section; }
        $sql = "SELECT st.id, st.lrn, st.first_name, st.middle_name, st.last_name,
                       st.grade_level, st.section,
                       IF(ss.id IS NOT NULL AND ss.status='active', 1, 0) AS already_enrolled
                FROM students st
                LEFT JOIN student_subjects ss ON ss.student_id = st.id AND ss.subject_id = ?
                WHERE " . implode(' AND ', $where) . "
                ORDER BY st.section ASC, st.last_name ASC, st.first_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$subjectId], $params));
        respond(true, 'ok', ['students' => $stmt->fetchAll(), 'subject_grade' => $grade]);
    }

    if ($action === 'get_enrolled') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        if (!$subjectId) respond(false, 'Subject ID required.');
        $stmt = $pdo->prepare(
            "SELECT st.id, st.lrn, st.first_name, st.middle_name, st.last_name,
                    st.grade_level, st.section, ss.enrollment_date, ss.id AS enrollment_id
             FROM student_subjects ss
             JOIN students st ON st.id = ss.student_id
             WHERE ss.subject_id=? AND ss.status='active'
             ORDER BY st.last_name ASC, st.first_name ASC"
        );
        $stmt->execute([$subjectId]);
        respond(true, 'ok', ['enrolled' => $stmt->fetchAll()]);
    }

    // ── GET SCHOOL YEAR INFO ──────────────────────────────────
    if ($action === 'get_school_year') {
        $r = $pdo->query("SELECT school_year, current_quarter FROM school_year_settings ORDER BY id DESC LIMIT 1");
        $row = $r ? $r->fetch() : null;
        respond(true, 'ok', [
            'school_year'     => $row['school_year']     ?? '—',
            'current_quarter' => $row['current_quarter'] ?? 1,
        ]);
    }

    // ── GET SUBJECT STATS (quiz avg, assignment avg) ──────────
    if ($action === 'get_subject_stats') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        if (!$subjectId) respond(false, 'Subject ID required.');

        $qa = $pdo->prepare("SELECT ROUND(AVG(qa.percentage),1) AS avg FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id=q.id WHERE q.subject_id=? AND qa.status IN('submitted','graded')");
        $qa->execute([$subjectId]);
        $quizAvg = $qa->fetchColumn();

        $aa = $pdo->prepare("SELECT ROUND(AVG(s.score/a.max_points*100),1) AS avg FROM submissions s JOIN assignments a ON s.assignment_id=a.id WHERE a.subject_id=? AND s.status IN('graded','returned') AND a.max_points>0");
        $aa->execute([$subjectId]);
        $asgnAvg = $aa->fetchColumn();

        $qc = $pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE subject_id=? AND status!='deactivated'");
        $qc->execute([$subjectId]);
        $quizCount = $qc->fetchColumn();

        $ac = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE subject_id=? AND status!='deactivated'");
        $ac->execute([$subjectId]);
        $asgnCount = $ac->fetchColumn();

        $fg = $pdo->prepare("SELECT COUNT(*) FROM student_subjects WHERE subject_id=? AND final_grade IS NOT NULL AND status='active'");
        $fg->execute([$subjectId]);
        $gradedCount = $fg->fetchColumn();

        respond(true, 'ok', [
            'quiz_avg'     => is_numeric($quizAvg) ? round($quizAvg, 1) : null,
            'asgn_avg'     => is_numeric($asgnAvg) ? round($asgnAvg, 1) : null,
            'quiz_count'   => (int)$quizCount,
            'asgn_count'   => (int)$asgnCount,
            'graded_count' => (int)$gradedCount,
        ]);
    }

    if ($action === 'create_subject') {
        $teacherId   = (int)($_POST['teacher_id']   ?? 0);
        $name        = clean($_POST['subject_name'] ?? '');
        $grade       = clean($_POST['grade']        ?? '');
        $section     = clean($_POST['section']      ?? '');
        $description = clean($_POST['description']  ?? '');
        $color       = clean($_POST['theme_color']  ?? '#2563eb');
        if (!$teacherId || !$name || !$grade) respond(false, 'Teacher, subject name, and grade are required.');
        $code = '';
        do {
            $code = randCode(7);
            $chk  = $pdo->prepare("SELECT id FROM subjects WHERE class_code=? LIMIT 1");
            $chk->execute([$code]);
        } while ($chk->fetch());
        $ins = $pdo->prepare(
            "INSERT INTO subjects (teacher_id, subject_name, section, grade, class_code, description, theme_color, logo_path, status, created_at)
             VALUES (?,?,?,?,?,?,?,'default','active',NOW())"
        );
        $ins->execute([$teacherId, $name, $section ?: null, $grade, $code, $description ?: null, $color]);
        $newId = $pdo->lastInsertId();
        $t = $pdo->prepare("SELECT fullname, theme_color FROM teachers WHERE id=? LIMIT 1");
        $t->execute([$teacherId]);
        $teacher = $t->fetch();
        respond(true, 'Subject created successfully.', [
            'subject' => [
                'id' => $newId, 'teacher_id' => $teacherId,
                'teacher_name' => $teacher['fullname'],
                'teacher_color' => $teacher['theme_color'],
                'subject_name' => $name, 'grade' => $grade,
                'section' => $section, 'class_code' => $code,
                'description' => $description, 'theme_color' => $color,
                'logo_path' => 'default', 'status' => 'active', 'enrolled_count' => 0,
            ]
        ]);
    }

    if ($action === 'update_subject') {
        $id          = (int)($_POST['subject_id']   ?? 0);
        $name        = clean($_POST['subject_name'] ?? '');
        $grade       = clean($_POST['grade']        ?? '');
        $section     = clean($_POST['section']      ?? '');
        $description = clean($_POST['description']  ?? '');
        $color       = clean($_POST['theme_color']  ?? '#2563eb');
        $status      = clean($_POST['status']       ?? 'active');
        if (!$id || !$name || !$grade) respond(false, 'Missing required fields.');
        $pdo->prepare(
            "UPDATE subjects SET subject_name=?, grade=?, section=?, description=?, theme_color=?, status=?, updated_at=NOW() WHERE id=?"
        )->execute([$name, $grade, $section ?: null, $description ?: null, $color, $status, $id]);
        respond(true, 'Subject updated successfully.');
    }

    if ($action === 'archive_subject') {
        $id = (int)($_POST['subject_id'] ?? 0);
        if (!$id) respond(false, 'Invalid ID.');
        $pdo->prepare(
            "UPDATE subjects SET status='deactivated', archived_at=NOW(), updated_at=NOW() WHERE id=?"
        )->execute([$id]);
        respond(true, 'Subject archived.');
    }

    if ($action === 'unarchive_subject') {
        $id = (int)($_POST['subject_id'] ?? 0);
        if (!$id) respond(false, 'Invalid ID.');
        $pdo->prepare(
            "UPDATE subjects SET status='active', archived_at=NULL, updated_at=NOW() WHERE id=?"
        )->execute([$id]);
        respond(true, 'Subject restored.');
    }

    if ($action === 'enroll_students') {
        $subjectId  = (int)($_POST['subject_id'] ?? 0);
        $studentIds = json_decode($_POST['student_ids'] ?? '[]', true);
        if (!$subjectId || empty($studentIds)) respond(false, 'Subject and students required.');
        $enrolled = 0; $skipped = 0;
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO student_subjects (student_id, subject_id, enrollment_date, status)
             VALUES (?, ?, NOW(), 'active')
             ON DUPLICATE KEY UPDATE status='active', enrollment_date=NOW()"
        );
        foreach ($studentIds as $sid) {
            $sid = (int)$sid;
            if (!$sid) continue;
            $ins->execute([$sid, $subjectId]);
            if ($ins->rowCount()) $enrolled++; else $skipped++;
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM student_subjects WHERE subject_id=? AND status='active'");
        $cnt->execute([$subjectId]);
        respond(true, "Enrolled $enrolled student(s)." . ($skipped ? " $skipped already enrolled." : ''), [
            'enrolled' => $enrolled, 'skipped' => $skipped, 'new_count' => $cnt->fetchColumn()
        ]);
    }

    if ($action === 'unenroll_student') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        if (!$subjectId || !$studentId) respond(false, 'Invalid IDs.');
        $pdo->prepare("UPDATE student_subjects SET status='dropped' WHERE subject_id=? AND student_id=?")->execute([$subjectId, $studentId]);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM student_subjects WHERE subject_id=? AND status='active'");
        $cnt->execute([$subjectId]);
        respond(true, 'Student unenrolled.', ['new_count' => $cnt->fetchColumn()]);
    }

    if ($action === 'unenroll_all') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        if (!$subjectId) respond(false, 'Invalid ID.');
        $pdo->prepare("UPDATE student_subjects SET status='dropped' WHERE subject_id=?")->execute([$subjectId]);
        respond(true, 'All students unenrolled.');
    }

    respond(false, 'Unknown action.');
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../../img/ashi.jpg" type="image/x-icon">
<title>ASHI Amaya — Subject Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ── LIGHT THEME ── */
:root {
  --content-bg:   #f7f7f7;
  --card-bg:      #ffffff;
  --border:       #e5e5e5;
  --text-primary: #111111;
  --text-secondary:#666666;
  --text-muted:   #999999;
  --shadow:       0 1px 4px rgba(0,0,0,0.07);
  --shadow-lg:    0 4px 20px rgba(0,0,0,0.09);
  --radius:       8px;
  --table-head:   #f9f9f9;
  --hover-bg:     #f3f3f3;

  --green:  #16a34a; --gbg: #f0fdf4; --gmid: #dcfce7; --gdk: #15803d;
  --amber:  #b45309; --abg: #fffbeb; --abdr: #fde68a;
  --red:    #dc2626; --rbg: #fef2f2; --rbdr: #fecaca;
  --blue:   #2563eb; --bbg: #eff6ff; --bmid: #bfdbfe;
  --violet: #7c3aed; --vbg: #f5f3ff; --vmid: #ddd6fe;
}

/* ── DARK THEME ── */
[data-theme="dark"] {
  --content-bg:    #141414;
  --card-bg:       #1e1e1e;
  --border:        #2e2e2e;
  --text-primary:  #f0f0f0;
  --text-secondary:#aaaaaa;
  --text-muted:    #555555;
  --shadow:        0 1px 4px rgba(0,0,0,0.4);
  --shadow-lg:     0 4px 20px rgba(0,0,0,0.4);
  --table-head:    #242424;
  --hover-bg:      #2a2a2a;

  --green:  #22c55e; --gbg: rgba(22,163,74,0.1);  --gmid: rgba(22,163,74,0.2);  --gdk: #4ade80;
  --amber:  #fbbf24; --abg: rgba(180,83,9,0.12);  --abdr: rgba(180,83,9,0.3);
  --red:    #f87171; --rbg: rgba(220,38,38,0.1);   --rbdr: rgba(220,38,38,0.3);
  --blue:   #60a5fa; --bbg: rgba(37,99,235,0.12);  --bmid: rgba(37,99,235,0.3);
  --violet: #a78bfa; --vbg: rgba(124,58,237,0.12); --vmid: rgba(124,58,237,0.3);
}

html, body { height: 100%; font-family: 'Poppins', sans-serif; font-size: 12px; background: var(--content-bg); color: var(--text-primary); transition: background .3s, color .3s; }

/* ── TOPBAR ── */
.topbar {
  height: 52px; background: var(--card-bg); border-bottom: 1px solid var(--border);
  display: flex; align-items: center; padding: 0 20px; gap: 12px;
  box-shadow: var(--shadow); position: sticky; top: 0; z-index: 100; transition: background .3s;
}
.topbar-logo { font-size: 13px; font-weight: 700; color: var(--text-primary); letter-spacing: .3px; }
.topbar-sep  { width: 1px; height: 18px; background: var(--border); }
.topbar-page { font-size: 12px; color: var(--text-muted); }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }

/* Academic period pill */
.acad-pill {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--table-head); border: 1px solid var(--border);
  border-radius: 20px; padding: 4px 12px; font-size: 11px; color: var(--text-secondary);
}
.acad-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,.3); flex-shrink: 0; }

/* Unarchive button */
.btn-unarchive {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 11px; font-weight: 500; padding: 5px 12px;
  border-radius: var(--radius); border: 1px solid var(--gmid);
  background: var(--gbg); color: var(--gdk); cursor: pointer;
  font-family: inherit; transition: all .15s;
}
.btn-unarchive:hover { background: var(--gmid); }

.btn-back {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 11px; font-weight: 500; padding: 5px 12px;
  border-radius: var(--radius); text-decoration: none; border: 1px solid var(--border);
  background: var(--card-bg); color: var(--text-secondary); transition: all .15s;
}
.btn-back:hover { color: var(--text-primary); border-color: #aaa; }

/* ── PAGE LAYOUT ── */
.page-wrap { display: flex; height: calc(100vh - 52px); overflow: hidden; }

/* ── LEFT PANEL ── */
.left-panel {
  width: 340px; min-width: 300px; display: flex; flex-direction: column;
  border-right: 1px solid var(--border); background: var(--card-bg);
  flex-shrink: 0; transition: background .3s;
}
.left-header { padding: 12px 14px 10px; border-bottom: 1px solid var(--border); }
.left-title-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.left-title { font-size: 13px; font-weight: 700; color: var(--text-primary); }
.count-pill { background: var(--text-primary); color: var(--content-bg); border-radius: 20px; padding: 1px 8px; font-size: 10px; font-weight: 700; }

/* ── FILTERS ── */
.filter-stack { display: flex; flex-direction: column; gap: 6px; }
.filter-row   { display: flex; gap: 5px; }
.fsel-wrap    { position: relative; flex: 1; }
.fsel-wrap i  { position: absolute; left: 8px; top: 50%; transform: translateY(-50%); font-size: 10px; color: var(--text-muted); pointer-events: none; }
.fsel {
  width: 100%; height: 30px; padding: 0 22px 0 24px;
  border: 1px solid var(--border); border-radius: var(--radius);
  font-family: inherit; font-size: 11px; color: var(--text-primary);
  background: var(--content-bg); appearance: none; cursor: pointer; transition: border .15s;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5'%3E%3Cpath d='M0 0l4 5 4-5z' fill='%23999'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 7px center;
}
.fsel:focus { outline: none; border-color: var(--blue); }
.fsel.active { border-color: var(--green); color: var(--green); font-weight: 600; }
[data-theme="dark"] .fsel { background-color: var(--table-head); }

.search-wrap { position: relative; flex: 1; }
.search-wrap i { position: absolute; left: 9px; top: 50%; transform: translateY(-50%); font-size: 10px; color: var(--text-muted); pointer-events: none; }
.search-input {
  width: 100%; height: 30px; padding: 0 30px 0 28px;
  border: 1px solid var(--border); border-radius: var(--radius);
  font-family: inherit; font-size: 11px; color: var(--text-primary);
  background: var(--content-bg); transition: border .15s;
}
.search-input:focus { outline: none; border-color: var(--blue); }
[data-theme="dark"] .search-input { background: var(--table-head); }
.btn-clear { height: 30px; padding: 0 10px; background: var(--content-bg); border: 1px solid var(--border); border-radius: var(--radius); font-family: inherit; font-size: 11px; color: var(--text-muted); cursor: pointer; transition: all .15s; display: flex; align-items: center; gap: 4px; white-space: nowrap; }
.btn-clear:hover { background: var(--rbg); border-color: var(--rbdr); color: var(--red); }

/* ── SUBJECT LIST ── */
.subj-list-wrap { flex: 1; overflow-y: auto; padding: 6px 8px; }
.subj-card {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: var(--radius);
  border: 1px solid transparent; cursor: pointer;
  transition: all .15s; margin-bottom: 3px; background: var(--content-bg);
}
.subj-card:hover   { border-color: var(--border); background: var(--hover-bg); }
.subj-card.selected { border-color: var(--blue); background: var(--bbg); }
[data-theme="dark"] .subj-card.selected { background: rgba(37,99,235,0.1); }

.subj-logo {
  width: 36px; height: 36px; border-radius: var(--radius); flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; font-weight: 700; color: #fff;
  box-shadow: 0 2px 6px rgba(0,0,0,.2);
}
.subj-card-info { flex: 1; min-width: 0; }
.subj-card-name { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary); }
.subj-card-meta { font-size: 10px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; margin-top: 2px; flex-wrap: wrap; }
.subj-card-teacher { font-size: 10px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }

.tag { display: inline-flex; align-items: center; gap: 3px; border-radius: 4px; padding: 1px 6px; font-size: 10px; font-weight: 600; white-space: nowrap; }
.tag-blue   { background: var(--bbg); color: var(--blue); border: 1px solid var(--bmid); }
.tag-violet { background: var(--vbg); color: var(--violet); border: 1px solid var(--vmid); }
.tag-green  { background: var(--gbg); color: var(--gdk); border: 1px solid var(--gmid); }
.tag-amber  { background: var(--abg); color: var(--amber); border: 1px solid var(--abdr); }
.tag-red    { background: var(--rbg); color: var(--red); border: 1px solid var(--rbdr); }
.tag-gray   { background: var(--table-head); color: var(--text-muted); border: 1px solid var(--border); }
.tag-enroll { background: var(--gbg); color: var(--gdk); border: 1px solid var(--gmid); font-weight: 700; }

.code-mono { font-family: monospace; background: var(--table-head); border: 1px solid var(--border); border-radius: 4px; padding: 1px 5px; font-size: 10px; letter-spacing: .5px; color: var(--text-secondary); }

.list-empty { padding: 40px 20px; text-align: center; color: var(--text-muted); }
.list-empty i { font-size: 28px; display: block; margin-bottom: 10px; opacity: .2; }
.list-empty p { font-size: 11.5px; line-height: 1.7; }

/* ── LEFT FOOTER ── */
.left-footer { padding: 10px 10px; border-top: 1px solid var(--border); }
.btn-new-subj {
  width: 100%; height: 34px; background: var(--text-primary); color: var(--content-bg);
  border: none; border-radius: var(--radius); font-family: inherit; font-size: 12px;
  font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center;
  gap: 6px; transition: opacity .15s;
}
.btn-new-subj:hover { opacity: .85; }

/* ── RIGHT PANEL ── */
.right-panel { flex: 1; display: flex; flex-direction: column; min-width: 0; overflow: hidden; background: var(--content-bg); transition: background .3s; }
.right-body  { flex: 1; overflow-y: auto; }

/* Placeholder */
.placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted); text-align: center; gap: 14px; }
.placeholder-icon { width: 72px; height: 72px; background: var(--table-head); border: 1px solid var(--border); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; color: var(--text-muted); }
.placeholder h2 { font-size: 15px; font-weight: 700; color: var(--text-primary); }
.placeholder p  { font-size: 12px; line-height: 1.7; max-width: 320px; }

/* ── SUBJECT HEADER ── */
.subj-detail-header {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 16px 20px; background: var(--card-bg);
  border-bottom: 1px solid var(--border); flex-shrink: 0; transition: background .3s;
}
.subj-logo-lg {
  width: 52px; height: 52px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 24px; font-weight: 700; color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,.2);
}
.subj-detail-info { flex: 1; min-width: 0; }
.subj-detail-name { font-size: 18px; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
.subj-detail-meta { display: flex; align-items: center; gap: 6px; margin-top: 5px; flex-wrap: wrap; }
.subj-detail-teacher { font-size: 11px; color: var(--text-secondary); margin-top: 3px; display: flex; align-items: center; gap: 5px; }
.subj-detail-actions { display: flex; gap: 6px; flex-shrink: 0; align-items: flex-start; }

/* ── STATS GRID ── */
.stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; padding: 14px 20px; }
.stat-card {
  background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 12px 14px; transition: background .3s;
}
.stat-val { font-size: 22px; font-weight: 700; line-height: 1; margin-bottom: 4px; }
.stat-key { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); }
.stat-sub { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

/* ── PANELS GRID (enrolled + picker side by side) ── */
.panels-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; padding: 0 20px 20px; }
@media (max-width: 1100px) { .panels-grid { grid-template-columns: 1fr; } }

/* ── SECTION CARD ── */
.section-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; transition: background .3s; display: flex; flex-direction: column; }
.section-head { padding: 10px 14px; border-bottom: 1px solid var(--border); background: var(--table-head); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.section-head-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; }
.section-body { flex: 1; overflow-y: auto; max-height: 320px; }

/* ── ENROLLED LIST ── */
.enr-item { display: flex; align-items: center; gap: 10px; padding: 8px 14px; border-bottom: 1px solid var(--border); transition: background .12s; }
.enr-item:last-child { border-bottom: none; }
.enr-item:hover { background: var(--hover-bg); }
.enr-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--text-primary); color: var(--content-bg); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.enr-name { font-size: 11.5px; font-weight: 600; color: var(--text-primary); }
.enr-meta { font-size: 10px; color: var(--text-muted); }
.enr-empty { padding: 30px; text-align: center; color: var(--text-muted); font-size: 11.5px; }
.enr-empty i { font-size: 22px; display: block; margin-bottom: 8px; opacity: .2; }

/* ── PICKER ── */
.picker-toolbar { display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-bottom: 1px solid var(--border); flex-wrap: wrap; background: var(--table-head); }
.picker-search-wrap { position: relative; flex: 1; min-width: 120px; }
.picker-search-wrap i { position: absolute; left: 8px; top: 50%; transform: translateY(-50%); font-size: 10px; color: var(--text-muted); pointer-events: none; }
.picker-search { width: 100%; height: 27px; padding: 0 8px 0 26px; border: 1px solid var(--border); border-radius: 5px; font-family: inherit; font-size: 11px; background: var(--card-bg); color: var(--text-primary); }
.picker-search:focus { outline: none; border-color: var(--blue); }
.grade-lock-badge { display: none; align-items: center; gap: 4px; background: var(--bbg); border: 1px solid var(--bmid); border-radius: 5px; padding: 3px 8px; font-size: 10px; font-weight: 700; color: var(--blue); white-space: nowrap; }
.picker-fsel-wrap { position: relative; }
.picker-fsel-wrap i { position: absolute; left: 7px; top: 50%; transform: translateY(-50%); font-size: 10px; color: var(--text-muted); pointer-events: none; }
.picker-fsel { height: 27px; padding: 0 18px 0 20px; border: 1px solid var(--border); border-radius: 5px; font-family: inherit; font-size: 11px; background: var(--card-bg); color: var(--text-primary); appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5'%3E%3Cpath d='M0 0l4 5 4-5z' fill='%23999'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 5px center; }
.picker-fsel:focus { outline: none; border-color: var(--blue); }

/* ── STUDENT TABLE ── */
.stud-table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 11px; }
thead tr { background: var(--table-head); position: sticky; top: 0; z-index: 2; }
th { padding: 6px 10px; text-align: left; font-size: 9.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 6px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: var(--hover-bg); }
tbody tr.enr-row td { background: var(--gbg); }
tbody tr.enr-row:hover td { background: var(--gmid); }
.cb-cell { width: 30px; text-align: center; }
input[type=checkbox] { width: 14px; height: 14px; accent-color: var(--blue); cursor: pointer; }
.td-mono { font-family: monospace; font-size: 10px; color: var(--text-muted); }
.td-name { font-weight: 600; color: var(--text-primary); }
.td-sub  { font-size: 10px; color: var(--text-muted); }
.already-tag { display: inline-flex; align-items: center; gap: 3px; background: var(--gbg); border: 1px solid var(--gmid); color: var(--gdk); border-radius: 4px; padding: 1px 6px; font-size: 10px; font-weight: 600; }
.unenroll-btn { background: var(--rbg); border: 1px solid var(--rbdr); color: var(--red); border-radius: 4px; padding: 2px 7px; font-size: 10px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .15s; display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
.unenroll-btn:hover { background: var(--rbdr); }

.all-check-row { display: flex; align-items: center; gap: 6px; padding: 5px 10px; background: var(--bbg); border-bottom: 1px solid var(--bmid); font-size: 10.5px; font-weight: 600; color: var(--blue); }

.picker-actions { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-top: 1px solid var(--border); background: var(--table-head); flex-wrap: wrap; }
.sel-count { font-size: 11px; color: var(--blue); font-weight: 600; display: flex; align-items: center; gap: 4px; }

/* ── BUTTONS ── */
.btn { padding: 6px 12px; border: none; border-radius: var(--radius); font-family: inherit; font-size: 11px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all .15s; text-decoration: none; white-space: nowrap; }
.btn:disabled { opacity: .45; cursor: not-allowed; }
.btn-primary { background: var(--text-primary); color: var(--content-bg); }
.btn-primary:hover:not(:disabled) { opacity: .85; }
.btn-green   { background: var(--card-bg); color: var(--gdk); border: 1px solid var(--gmid); }
.btn-green:hover:not(:disabled)   { background: var(--gbg); }
.btn-ghost   { background: var(--card-bg); border: 1px solid var(--border); color: var(--text-secondary); }
.btn-ghost:hover:not(:disabled)   { border-color: #aaa; color: var(--text-primary); }
.btn-danger  { background: var(--card-bg); border: 1px solid var(--rbdr); color: var(--red); }
.btn-danger:hover:not(:disabled)  { background: var(--rbg); }
.btn-warn    { background: var(--card-bg); border: 1px solid var(--abdr); color: var(--amber); }
.btn-warn:hover { background: var(--abg); }
.btn-sm { padding: 4px 9px; font-size: 10.5px; }

/* ── MODAL ── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 300; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.show { opacity: 1; pointer-events: all; }
.modal-box { background: var(--card-bg); border-radius: 10px; width: 90%; max-width: 480px; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-lg); transform: translateY(12px); transition: transform .2s, background .3s; }
.modal-overlay.show .modal-box { transform: translateY(0); }
.modal-header { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.modal-title { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 7px; color: var(--text-primary); }
.modal-close { background: none; border: none; font-size: 16px; cursor: pointer; color: var(--text-muted); padding: 2px; transition: color .15s; }
.modal-close:hover { color: var(--text-primary); }
.modal-body { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
.modal-footer { padding: 12px 18px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; background: var(--table-head); }

/* Form fields */
.field { display: flex; flex-direction: column; gap: 4px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
label { font-size: 10.5px; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 4px; }
.req { color: var(--red); }
input[type=text], select, textarea { padding: 6px 9px; border: 1px solid var(--border); border-radius: var(--radius); font-family: inherit; font-size: 11.5px; color: var(--text-primary); background: var(--content-bg); width: 100%; transition: border .15s; }
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--blue); }
textarea { resize: vertical; min-height: 56px; }
[data-theme="dark"] input[type=text], [data-theme="dark"] select, [data-theme="dark"] textarea { background: var(--table-head); }

/* Color swatches */
.color-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 2px; }
.color-swatch { width: 24px; height: 24px; border-radius: 6px; border: 2px solid transparent; cursor: pointer; transition: transform .1s, border-color .1s; flex-shrink: 0; }
.color-swatch:hover { transform: scale(1.15); }
.color-swatch.selected { border-color: var(--text-primary); transform: scale(1.15); }
.preview-card { display: flex; align-items: center; gap: 12px; background: var(--table-head); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; }
.preview-logo { width: 38px; height: 38px; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; transition: background .2s; box-shadow: 0 2px 6px rgba(0,0,0,.2); }

/* Grade lock notice */
.grade-lock-notice { display: none; align-items: center; gap: 5px; background: var(--bbg); border: 1px solid var(--bmid); border-radius: 5px; padding: 5px 10px; font-size: 10.5px; font-weight: 600; color: var(--blue); margin-top: 3px; }

/* ── CONFIRM MODAL ── */
.confirm-box { max-width: 360px; }
.confirm-icon { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 20px; }
.ci-danger { background: var(--rbg); color: var(--red); }
.ci-warn   { background: var(--abg); color: var(--amber); }
.ci-green  { background: var(--gbg); color: var(--gdk); }
.confirm-title { font-size: 14px; font-weight: 700; text-align: center; margin-bottom: 5px; color: var(--text-primary); }
.confirm-msg   { font-size: 11.5px; color: var(--text-secondary); text-align: center; line-height: 1.6; margin-bottom: 16px; }

/* ── TOAST ── */
#toast { position: fixed; bottom: 20px; right: 20px; padding: 9px 16px; border-radius: var(--radius); font-size: 12px; font-weight: 600; color: #fff; box-shadow: var(--shadow-lg); display: flex; align-items: center; gap: 7px; z-index: 500; transform: translateY(70px); opacity: 0; transition: transform .3s, opacity .3s; pointer-events: none; }
#toast.show { transform: translateY(0); opacity: 1; }
#toast.ok   { background: var(--green); }
#toast.err  { background: var(--red); }
#toast.info { background: var(--blue); }

/* ── SPINNER ── */
.spinner { display: inline-block; width: 13px; height: 13px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
.spinner-dark { border-color: var(--border); border-top-color: var(--blue); }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── ARCHIVED BANNER (shows on deactivated subject detail) ── */
.archived-banner {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 20px; font-size: 11px;
  background: var(--table-head); border-bottom: 1px solid var(--border);
  color: var(--text-secondary);
}
.archived-banner strong { color: var(--text-primary); }
.archived-banner-icon { color: var(--text-muted); font-size: 14px; }

@media (max-width: 900px) {
  .page-wrap { flex-direction: column; height: auto; }
  .left-panel { width: 100%; max-width: 100%; min-width: 0; max-height: 45vh; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .panels-grid { padding: 0 12px 16px; }
}
</style>
</head>
<body>

<script>
(function(){ document.documentElement.setAttribute('data-theme', localStorage.getItem('ashi-theme')||'light'); })();
</script>

<div id="toast"></div>

<!-- ── TOPBAR ── -->
<div class="topbar">
  <span class="topbar-logo">ASHI AMAYA</span>
  <div class="topbar-sep"></div>
  <span class="topbar-page">Subject &amp; Enrollment Manager</span>
  <div class="topbar-right">
    <div class="acad-pill" id="acad-pill">
      <span class="acad-dot"></span>
      <span id="acad-text">Loading...</span>
    </div>
    <button class="btn-unarchive" id="btn-topbar-unarchive" style="display:none" onclick="unarchiveSelected()">
      <i class="fa fa-box-open"></i> Unarchive
    </button>
    <a href="../admin_dash.php" class="btn-back"><i class="fa fa-arrow-left"></i> Back</a>
  </div>
</div>

<!-- ── MAIN LAYOUT ── -->
<div class="page-wrap">

  <!-- ── LEFT PANEL ── -->
  <div class="left-panel">
    <div class="left-header">
      <div class="left-title-row">
        <span class="left-title">Subjects</span>
        <span class="count-pill" id="subj-count">0</span>
      </div>
      <div class="filter-stack">
        <div class="filter-row">
          <div class="fsel-wrap">
            <i class="fa fa-chalkboard-user"></i>
            <select class="fsel" id="f-teacher" onchange="applyFilters()">
              <option value="">All Teachers</option>
            </select>
          </div>
        </div>
        <div class="filter-row">
          <div class="fsel-wrap">
            <i class="fa fa-layer-group"></i>
            <select class="fsel" id="f-grade" onchange="onFilterGrade(); applyFilters()">
              <option value="">All Grades</option>
              <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
              <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
            </select>
          </div>
          <div class="fsel-wrap">
            <i class="fa fa-door-open"></i>
            <select class="fsel" id="f-section" onchange="applyFilters()" disabled>
              <option value="">All Sections</option>
            </select>
          </div>
          <button class="btn-clear" onclick="clearFilters()"><i class="fa fa-rotate-left"></i></button>
        </div>
        <div class="filter-row">
          <div class="search-wrap" style="flex:1">
            <i class="fa fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="f-search" placeholder="Search by name or code..." oninput="applyFilters()">
          </div>
        </div>
      </div>
    </div>

    <div class="subj-list-wrap" id="subject-list">
      <div class="list-empty"><i class="fa fa-spinner fa-spin"></i><p>Loading subjects...</p></div>
    </div>

    <div class="left-footer">
      <button class="btn-new-subj" onclick="openCreateModal()">
        <i class="fa fa-plus"></i> Create New Subject
      </button>
    </div>
  </div>

  <!-- ── RIGHT PANEL ── -->
  <div class="right-panel">
    <div id="right-body" class="right-body">
      <div class="placeholder">
        <div class="placeholder-icon"><i class="fa fa-book-open"></i></div>
        <h2>Select a Subject</h2>
        <p>Choose a subject from the list on the left to view details, manage enrollment, and add or remove students.</p>
      </div>
    </div>
  </div>

</div>

<!-- ── CREATE / EDIT SUBJECT MODAL ── -->
<div class="modal-overlay" id="subj-modal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title" id="subj-modal-title"><i class="fa fa-plus"></i> Create Subject</div>
      <button class="modal-close" onclick="closeSubjModal()"><i class="fa fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="edit-subj-id">
      <div class="preview-card">
        <div class="preview-logo" id="preview-logo">A</div>
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--text-primary)" id="preview-name">Subject Name</div>
          <div style="font-size:11px;color:var(--text-muted)">Preview</div>
        </div>
      </div>
      <div class="field">
        <label><i class="fa fa-chalkboard-user"></i> Teacher <span class="req">*</span></label>
        <select id="m-teacher" onchange="onModalTeacherChange()">
          <option value="">Select teacher...</option>
        </select>
      </div>
      <div class="field">
        <label><i class="fa fa-book"></i> Subject Name <span class="req">*</span></label>
        <input type="text" id="m-name" placeholder="e.g. Mathematics" oninput="updatePreview()">
      </div>
      <div class="field-row">
        <div class="field">
          <label><i class="fa fa-layer-group"></i> Grade Level <span class="req">*</span></label>
          <select id="m-grade" onchange="onModalGrade()">
            <option value="">Select grade...</option>
            <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
            <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
          </select>
          <div class="grade-lock-notice" id="grade-lock-notice"></div>
        </div>
        <div class="field">
          <label><i class="fa fa-door-open"></i> Section <span style="color:var(--text-muted);font-size:9.5px;font-weight:400">(optional)</span></label>
          <select id="m-section" disabled>
            <option value="">All Sections</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label><i class="fa fa-align-left"></i> Description <span style="color:var(--text-muted);font-size:9.5px;font-weight:400">(optional)</span></label>
        <textarea id="m-desc" placeholder="Short description..."></textarea>
      </div>
      <div class="field">
        <label><i class="fa fa-palette"></i> Theme Color</label>
        <div class="color-row" id="color-swatches"></div>
      </div>
      <div class="field">
        <label><i class="fa fa-signal"></i> Status</label>
        <select id="m-status">
          <option value="active">Active</option>
          <option value="disabled">Disabled</option>
          <option value="closed">Closed</option>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeSubjModal()">Cancel</button>
      <button class="btn btn-primary" id="subj-modal-save" onclick="saveSubject()"><i class="fa fa-check"></i> Create Subject</button>
    </div>
  </div>
</div>

<!-- ── CONFIRM MODAL ── -->
<div class="modal-overlay" id="confirm-modal">
  <div class="modal-box confirm-box">
    <div class="modal-body" style="padding:24px 20px 16px">
      <div class="confirm-icon" id="ci-icon"></div>
      <div class="confirm-title" id="ci-title">Confirm</div>
      <div class="confirm-msg" id="ci-msg">Are you sure?</div>
      <div style="display:flex;gap:8px;justify-content:center">
        <button class="btn btn-ghost" onclick="closeConfirm()"><i class="fa fa-xmark"></i> Cancel</button>
        <button class="btn btn-primary" id="ci-confirm-btn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
// ── STATE ─────────────────────────────────────────────────────────
let allSubjects    = [];
let allTeachers    = [];
let filteredSubjs  = [];
let selectedSubjId = null;
let pickerStudents = [];
let enrolledStudents = [];
const sectionCache = {};
let schoolYear     = '—';
let currentQuarter = 1;
const quarterLabels = {1:'1st Grading',2:'2nd Grading',3:'3rd Grading',4:'4th Grading'};

const COLORS = [
  '#2563eb','#0ea5e9','#0d9488','#16a34a','#65a30d',
  '#ca8a04','#d97706','#dc2626','#db2777','#9333ea',
  '#7c3aed','#4f46e5','#0891b2','#059669','#b45309',
];
let selectedColor = '#2563eb';

// ── INIT ──────────────────────────────────────────────────────────
(function init() {
  buildColorSwatches();
  loadSchoolYear();
  loadTeachers();
  loadSubjects();
})();

// ── AJAX ──────────────────────────────────────────────────────────
function post(data, cb) {
  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data).toString()
  }).then(r => r.json()).then(cb)
    .catch(e => { console.error(e); showToast('Network error.', 'err'); });
}

// ── TOAST ─────────────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t = document.getElementById('toast');
  const icons = { ok: 'circle-check', err: 'circle-xmark', info: 'circle-info' };
  t.innerHTML = `<i class="fa fa-${icons[type]||'circle-info'}"></i> ${msg}`;
  t.className = `show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.className = '', 3500);
}

// ── SCHOOL YEAR ───────────────────────────────────────────────────
function loadSchoolYear() {
  post({ action: 'get_school_year' }, res => {
    if (!res.success) return;
    schoolYear     = res.school_year;
    currentQuarter = parseInt(res.current_quarter) || 1;
    const label    = quarterLabels[currentQuarter] || ('Q' + currentQuarter);
    document.getElementById('acad-text').textContent = `${schoolYear} · ${label}`;
  });
}

// ── TEACHERS ──────────────────────────────────────────────────────
function loadTeachers() {
  post({ action: 'get_teachers' }, res => {
    if (!res.success) return;
    allTeachers = res.teachers;
    const fsel = document.getElementById('f-teacher');
    const msel = document.getElementById('m-teacher');
    allTeachers.forEach(t => {
      [fsel, msel].forEach(sel => {
        const o = document.createElement('option');
        o.value = t.id; o.textContent = t.fullname;
        o.dataset.color         = t.theme_color      || '#2563eb';
        o.dataset.advisoryGrade = t.advisory_grade   || '';
        sel.appendChild(o);
      });
    });
  });
}

// ── SUBJECTS ──────────────────────────────────────────────────────
function loadSubjects(keepSelection = false) {
  post({ action: 'get_subjects' }, res => {
    if (!res.success) return;
    allSubjects = res.subjects;
    applyFilters();
    if (keepSelection && selectedSubjId) {
      const s = allSubjects.find(x => x.id == selectedSubjId);
      if (s) renderRightPanel(s);
    }
  });
}

// ── FILTERS ───────────────────────────────────────────────────────
function onFilterGrade() {
  const grade = document.getElementById('f-grade').value;
  const secEl = document.getElementById('f-section');
  secEl.innerHTML = '<option value="">All Sections</option>';
  secEl.disabled  = true;
  if (!grade) return;
  const populate = (secs) => {
    secs.forEach(s => {
      const o = document.createElement('option');
      o.value = s.section_name; o.textContent = s.section_name;
      secEl.appendChild(o);
    });
    secEl.disabled = false;
  };
  if (sectionCache[grade]) { populate(sectionCache[grade]); return; }
  post({ action: 'get_sections', grade }, res => {
    if (res.success) { sectionCache[grade] = res.sections; populate(res.sections); }
  });
}

function applyFilters() {
  const teacherId = document.getElementById('f-teacher').value;
  const grade     = document.getElementById('f-grade').value;
  const section   = document.getElementById('f-section').value;
  const search    = document.getElementById('f-search').value.trim().toLowerCase();
  ['f-teacher','f-grade','f-section'].forEach(id => {
    const el = document.getElementById(id);
    el.classList.toggle('active', !!el.value);
  });
  filteredSubjs = allSubjects.filter(s => {
    if (teacherId && s.teacher_id != teacherId) return false;
    if (grade     && s.grade !== grade)          return false;
    if (section   && s.section !== section)      return false;
    if (search    && !s.subject_name.toLowerCase().includes(search) &&
                     !s.class_code.toLowerCase().includes(search)) return false;
    return true;
  });
  document.getElementById('subj-count').textContent = filteredSubjs.length;
  renderSubjectList();
}

function clearFilters() {
  ['f-teacher','f-grade','f-section','f-search'].forEach(id => {
    const el = document.getElementById(id);
    el.value = ''; el.classList.remove('active');
  });
  document.getElementById('f-section').innerHTML = '<option value="">All Sections</option>';
  document.getElementById('f-section').disabled  = true;
  applyFilters();
}

// ── SUBJECT LIST ──────────────────────────────────────────────────
function statusTag(status) {
  if (status === 'active')      return '<span class="tag tag-green"><i class="fa fa-circle" style="font-size:7px"></i> Active</span>';
  if (status === 'disabled')    return '<span class="tag tag-amber"><i class="fa fa-pause-circle"></i> Disabled</span>';
  if (status === 'deactivated') return '<span class="tag tag-gray"><i class="fa fa-box-archive"></i> Archived</span>';
  return '<span class="tag tag-red"><i class="fa fa-lock"></i> Closed</span>';
}

function renderSubjectList() {
  const wrap = document.getElementById('subject-list');
  if (!filteredSubjs.length) {
    wrap.innerHTML = `<div class="list-empty"><i class="fa fa-book-open"></i><p>No subjects found.<br>Adjust your filters or create a new subject.</p></div>`;
    return;
  }
  wrap.innerHTML = filteredSubjs.map(s => {
    const letter  = s.subject_name.trim().charAt(0).toUpperCase() || '?';
    const color   = s.theme_color || '#2563eb';
    const sel     = s.id == selectedSubjId ? 'selected' : '';
    const dimmed  = s.status === 'deactivated' ? 'opacity:.6' : '';
    return `<div class="subj-card ${sel}" onclick="selectSubject(${s.id})" id="scard-${s.id}" style="${dimmed}">
      <div class="subj-logo" style="background:${color}">${letter}</div>
      <div class="subj-card-info">
        <div class="subj-card-name">${s.subject_name}</div>
        <div class="subj-card-teacher"><i class="fa fa-user-tie" style="font-size:9px;color:var(--blue)"></i> ${s.teacher_name}</div>
        <div class="subj-card-meta">
          <span class="code-mono">${s.class_code}</span>
          <span class="tag tag-blue" style="font-size:9px">${s.grade}</span>
          ${s.section ? `<span class="tag tag-violet" style="font-size:9px">${s.section}</span>` : ''}
          <span class="tag tag-enroll"><i class="fa fa-users" style="font-size:9px"></i> ${s.enrolled_count}</span>
          ${statusTag(s.status)}
        </div>
      </div>
    </div>`;
  }).join('');
}

// ── SELECT SUBJECT ────────────────────────────────────────────────
function selectSubject(id) {
  selectedSubjId = id;
  const s = allSubjects.find(x => x.id == id);
  if (!s) return;
  document.querySelectorAll('.subj-card').forEach(c => c.classList.remove('selected'));
  const card = document.getElementById('scard-' + id);
  if (card) card.classList.add('selected');

  // Show Unarchive button only when subject is archived (deactivated)
  const unarchiveBtn = document.getElementById('btn-topbar-unarchive');
  if (unarchiveBtn) unarchiveBtn.style.display = s.status === 'deactivated' ? '' : 'none';

  renderRightPanel(s);
}

function renderRightPanel(s) {
  const color  = s.theme_color || '#2563eb';
  const letter = s.subject_name.trim().charAt(0).toUpperCase() || '?';
  const isArchived = s.status === 'deactivated';

  document.getElementById('right-body').innerHTML = `

    ${isArchived ? `
    <div class="archived-banner">
      <i class="fa fa-box-archive archived-banner-icon"></i>
      <span>This subject is <strong>archived</strong>. Students cannot access it. Use the <strong>Unarchive</strong> button in the top bar to restore it.</span>
    </div>` : ''}

    <!-- Subject header -->
    <div class="subj-detail-header" style="background:linear-gradient(135deg,${color}14,${color}06,var(--card-bg))">
      <div class="subj-logo-lg" style="background:${color};${isArchived?'opacity:.6':''};">${letter}</div>
      <div class="subj-detail-info">
        <div class="subj-detail-name" style="${isArchived?'opacity:.7':''}">${s.subject_name}</div>
        <div class="subj-detail-meta">
          <span class="code-mono">${s.class_code}</span>
          <span class="tag tag-blue"><i class="fa fa-layer-group" style="font-size:9px"></i> ${s.grade}</span>
          ${s.section ? `<span class="tag tag-violet"><i class="fa fa-door-open" style="font-size:9px"></i> ${s.section}</span>` : '<span class="tag tag-gray">All Sections</span>'}
          ${statusTag(s.status)}
        </div>
        <div class="subj-detail-teacher">
          <i class="fa fa-chalkboard-user" style="color:var(--blue)"></i>
          <strong>${s.teacher_name}</strong>
          ${s.description ? `<span style="color:var(--border);margin:0 3px">|</span><span style="color:var(--text-muted)">${s.description}</span>` : ''}
        </div>
      </div>
      <div class="subj-detail-actions">
        <button class="btn btn-ghost btn-sm" onclick="openEditModal(${s.id})"><i class="fa fa-pen"></i> Edit</button>
        ${!isArchived
          ? `<button class="btn btn-danger btn-sm" onclick="confirmArchive(${s.id},'${s.subject_name.replace(/'/g,"\\'")}')"><i class="fa fa-box-archive"></i> Archive</button>`
          : `<button class="btn btn-green btn-sm" onclick="unarchiveSelected()"><i class="fa fa-box-open"></i> Unarchive</button>`
        }
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-val" style="color:${color}" id="stat-enrolled">${s.enrolled_count}</div>
        <div class="stat-key">Enrolled</div>
        <div class="stat-sub">active students</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:var(--violet)" id="stat-quizzes">—</div>
        <div class="stat-key">Quizzes</div>
        <div class="stat-sub" id="stat-quiz-avg">avg loading...</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:var(--blue)" id="stat-asgns">—</div>
        <div class="stat-key">Assignments</div>
        <div class="stat-sub" id="stat-asgn-avg">avg loading...</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:var(--gdk)" id="stat-graded">—</div>
        <div class="stat-key">Graded</div>
        <div class="stat-sub">with final grade</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:var(--text-muted);font-size:14px;font-family:monospace">${s.grade.replace('Grade ','G')}</div>
        <div class="stat-key">Grade Level</div>
        <div class="stat-sub">${s.section || 'All Sections'}</div>
      </div>
    </div>

    <!-- Main panels -->
    <div class="panels-grid">

      <!-- ENROLLED -->
      <div class="section-card">
        <div class="section-head">
          <div class="section-head-title"><i class="fa fa-users"></i> Enrolled Students <span class="count-pill" id="enrolled-count-badge" style="background:var(--green);color:#fff">0</span></div>
          <button class="btn btn-danger btn-sm" onclick="confirmUnenrollAll(${s.id})" id="btn-unenroll-all" style="display:none">
            <i class="fa fa-user-minus"></i> Remove All
          </button>
        </div>
        <div class="section-body">
          <div class="enrolled-list" id="enrolled-list">
            <div class="enr-empty"><i class="fa fa-spinner fa-spin"></i><p>Loading...</p></div>
          </div>
        </div>
      </div>

      <!-- ENROLLMENT PICKER -->
      <div class="section-card">
        <div class="section-head">
          <div class="section-head-title"><i class="fa fa-user-plus"></i> Enroll Students</div>
          <div style="font-size:10px;color:var(--text-muted)">Select students to add</div>
        </div>

        <div class="picker-toolbar">
          <div class="picker-search-wrap">
            <i class="fa fa-magnifying-glass"></i>
            <input type="text" class="picker-search" id="picker-search" placeholder="Search name or LRN..." oninput="filterPicker()">
          </div>
          <div class="grade-lock-badge" id="picker-grade-lock">
            <i class="fa fa-lock" style="font-size:9px"></i>
            <span id="picker-grade-lock-text"></span>
          </div>
          <div class="picker-fsel-wrap">
            <i class="fa fa-door-open"></i>
            <select class="picker-fsel" id="picker-section" onchange="filterPicker()" disabled>
              <option value="">All Sections</option>
            </select>
          </div>
          <button class="btn btn-ghost btn-sm" onclick="clearPickerFilters()"><i class="fa fa-rotate-left"></i></button>
        </div>

        <div class="all-check-row">
          <input type="checkbox" id="check-all" onchange="toggleCheckAll(this.checked)">
          <label for="check-all" style="cursor:pointer;margin:0;font-size:10.5px;font-weight:600;color:var(--blue)">Select All Visible</label>
          <span id="sel-count-label" style="margin-left:auto;font-size:10px;color:var(--text-muted)">0 selected</span>
        </div>

        <div class="section-body stud-table-wrap">
          <table>
            <thead>
              <tr>
                <th class="cb-cell"></th>
                <th>LRN</th>
                <th>Name</th>
                <th>Section</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="picker-tbody">
              <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted)">
                <i class="fa fa-spinner fa-spin"></i> Loading...
              </td></tr>
            </tbody>
          </table>
        </div>

        <div class="picker-actions">
          <div class="sel-count"><i class="fa fa-check-square"></i> <span id="sel-count">0</span> selected</div>
          <button class="btn btn-ghost btn-sm" onclick="selectUnenrolledVisible()"><i class="fa fa-check-double"></i> Select Not-Enrolled</button>
          <button class="btn btn-green" id="btn-enroll" onclick="enrollSelected(${s.id})" disabled>
            <i class="fa fa-user-plus"></i> Enroll Selected
          </button>
        </div>
      </div>

    </div>`;

  loadEnrolled(s.id);
  loadPickerStudents(s.id);
  loadSubjectStats(s.id);
}

// ── SUBJECT STATS ─────────────────────────────────────────────────
function loadSubjectStats(subjectId) {
  post({ action: 'get_subject_stats', subject_id: subjectId }, res => {
    if (!res.success) return;
    const qEl  = document.getElementById('stat-quizzes');
    const aEl  = document.getElementById('stat-asgns');
    const gEl  = document.getElementById('stat-graded');
    const qaEl = document.getElementById('stat-quiz-avg');
    const aaEl = document.getElementById('stat-asgn-avg');
    if (qEl)  qEl.textContent  = res.quiz_count;
    if (aEl)  aEl.textContent  = res.asgn_count;
    if (gEl)  gEl.textContent  = res.graded_count;
    if (qaEl) qaEl.textContent = res.quiz_avg !== null ? `${res.quiz_avg}% class avg` : 'no data yet';
    if (aaEl) aaEl.textContent = res.asgn_avg !== null ? `${res.asgn_avg}% class avg` : 'no data yet';
  });
}

// ── ENROLLED LIST ─────────────────────────────────────────────────
function loadEnrolled(subjectId) {
  post({ action: 'get_enrolled', subject_id: subjectId }, res => {
    if (!res.success) return;
    enrolledStudents = res.enrolled;
    renderEnrolledList(subjectId);
    const cnt = enrolledStudents.length;
    const statEl = document.getElementById('stat-enrolled');
    if (statEl) statEl.textContent = cnt;
    const s = allSubjects.find(x => x.id == subjectId);
    if (s) s.enrolled_count = cnt;
    document.getElementById('enrolled-count-badge').textContent = cnt;
    const unaBtn = document.getElementById('btn-unenroll-all');
    if (unaBtn) unaBtn.style.display = cnt ? '' : 'none';
    // Refresh card
    const card = document.getElementById('scard-' + subjectId);
    if (card && s) {
      const dimmed = s.status === 'deactivated' ? 'opacity:.6' : '';
      card.outerHTML = (() => {
        const letter = s.subject_name.trim().charAt(0).toUpperCase() || '?';
        const color  = s.theme_color || '#2563eb';
        const sel    = s.id == selectedSubjId ? 'selected' : '';
        return `<div class="subj-card ${sel}" onclick="selectSubject(${s.id})" id="scard-${s.id}" style="${dimmed}">
          <div class="subj-logo" style="background:${color}">${letter}</div>
          <div class="subj-card-info">
            <div class="subj-card-name">${s.subject_name}</div>
            <div class="subj-card-teacher"><i class="fa fa-user-tie" style="font-size:9px;color:var(--blue)"></i> ${s.teacher_name}</div>
            <div class="subj-card-meta">
              <span class="code-mono">${s.class_code}</span>
              <span class="tag tag-blue" style="font-size:9px">${s.grade}</span>
              ${s.section ? `<span class="tag tag-violet" style="font-size:9px">${s.section}</span>` : ''}
              <span class="tag tag-enroll"><i class="fa fa-users" style="font-size:9px"></i> ${s.enrolled_count}</span>
              ${statusTag(s.status)}
            </div>
          </div>
        </div>`;
      })();
    }
  });
}

function renderEnrolledList(subjectId) {
  const wrap = document.getElementById('enrolled-list');
  if (!wrap) return;
  if (!enrolledStudents.length) {
    wrap.innerHTML = `<div class="enr-empty"><i class="fa fa-users"></i><p>No students enrolled yet.</p></div>`;
    return;
  }
  wrap.innerHTML = enrolledStudents.map(st => {
    const full     = `${st.first_name}${st.middle_name?' '+st.middle_name:''} ${st.last_name}`;
    const initials = (st.first_name[0]||'') + (st.last_name[0]||'');
    return `<div class="enr-item">
      <div class="enr-avatar">${initials.toUpperCase()}</div>
      <div style="flex:1;min-width:0">
        <div class="enr-name">${full}</div>
        <div class="enr-meta"><i class="fa fa-hashtag" style="font-size:9px"></i> ${st.lrn||'—'} &nbsp;·&nbsp; ${st.grade_level} ${st.section}</div>
      </div>
      <button class="unenroll-btn" onclick="unenrollStudent(${subjectId},${st.id},'${full.replace(/'/g,"\\'")}')">
        <i class="fa fa-user-minus"></i> Remove
      </button>
    </div>`;
  }).join('');
}

// ── PICKER ────────────────────────────────────────────────────────
let pickerLockedGrade = '';
let pickerFiltered    = [];

function loadPickerStudents(subjectId) {
  post({ action: 'get_students_for_enrollment', subject_id: subjectId }, res => {
    if (!res.success) return;
    pickerStudents    = res.students;
    pickerLockedGrade = res.subject_grade || '';
    const badge    = document.getElementById('picker-grade-lock');
    const badgeTxt = document.getElementById('picker-grade-lock-text');
    if (badge && badgeTxt && pickerLockedGrade) {
      badgeTxt.textContent = pickerLockedGrade + ' only';
      badge.style.display  = 'flex';
    }
    const secEl = document.getElementById('picker-section');
    if (secEl && pickerLockedGrade) {
      secEl.innerHTML = '<option value="">All Sections</option>';
      secEl.disabled  = true;
      const populate = (secs) => {
        secs.forEach(s => {
          const o = document.createElement('option');
          o.value = s.section_name; o.textContent = s.section_name;
          secEl.appendChild(o);
        });
        secEl.disabled = secs.length === 0;
      };
      if (sectionCache[pickerLockedGrade]) {
        populate(sectionCache[pickerLockedGrade]);
      } else {
        post({ action: 'get_sections', grade: pickerLockedGrade }, r => {
          if (r.success) { sectionCache[pickerLockedGrade] = r.sections; populate(r.sections); }
        });
      }
    }
    filterPicker();
  });
}

function clearPickerFilters() {
  const ps = document.getElementById('picker-search');
  const pf = document.getElementById('picker-section');
  if (ps) ps.value = '';
  if (pf) pf.value = '';
  filterPicker();
}

function filterPicker() {
  const section = (document.getElementById('picker-section') || {value:''}).value;
  const search  = (document.getElementById('picker-search')  || {value:''}).value.trim().toLowerCase();
  pickerFiltered = pickerStudents.filter(st => {
    const full = `${st.first_name} ${st.middle_name||''} ${st.last_name}`.toLowerCase();
    if (pickerLockedGrade && st.grade_level !== pickerLockedGrade) return false;
    if (section && st.section !== section)                          return false;
    if (search  && !full.includes(search) && !(st.lrn||'').includes(search)) return false;
    return true;
  });
  renderPickerTable();
  updateSelCount();
}

function renderPickerTable() {
  const tb = document.getElementById('picker-tbody');
  if (!tb) return;
  if (!pickerFiltered.length) {
    tb.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted)"><i class="fa fa-filter"></i> No students match.</td></tr>`;
    return;
  }
  tb.innerHTML = pickerFiltered.map(st => {
    const full = `${st.first_name}${st.middle_name?' '+st.middle_name:''} ${st.last_name}`;
    const isEnr = st.already_enrolled == 1;
    return `<tr class="${isEnr?'enr-row':''}">
      <td class="cb-cell">${isEnr
        ? `<i class="fa fa-circle-check" style="color:var(--green);font-size:12px"></i>`
        : `<input type="checkbox" class="picker-cb" data-id="${st.id}" onchange="updateSelCount()">`}</td>
      <td class="td-mono">${st.lrn||'—'}</td>
      <td class="td-name">${full}</td>
      <td><div class="td-sub">${st.section}</div></td>
      <td>${isEnr
        ? '<span class="already-tag"><i class="fa fa-check"></i> Enrolled</span>'
        : '<span style="color:var(--text-muted);font-size:10px">Not enrolled</span>'}</td>
    </tr>`;
  }).join('');
}

function toggleCheckAll(checked) {
  document.querySelectorAll('.picker-cb').forEach(cb => cb.checked = checked);
  updateSelCount();
}

function selectUnenrolledVisible() {
  document.querySelectorAll('.picker-cb').forEach(cb => cb.checked = true);
  updateSelCount();
}

function updateSelCount() {
  const cbs   = document.querySelectorAll('.picker-cb:checked');
  const count  = cbs.length;
  const el     = document.getElementById('sel-count');
  const lbl    = document.getElementById('sel-count-label');
  const btn    = document.getElementById('btn-enroll');
  if (el)  el.textContent  = count;
  if (lbl) lbl.textContent = `${count} selected`;
  if (btn) btn.disabled    = !count;
  const allCbs = document.querySelectorAll('.picker-cb');
  const allChk = document.getElementById('check-all');
  if (allChk && allCbs.length) {
    allChk.indeterminate = count > 0 && count < allCbs.length;
    allChk.checked = count > 0 && count === allCbs.length;
  }
}

// ── ENROLL ────────────────────────────────────────────────────────
function enrollSelected(subjectId) {
  const ids = [...document.querySelectorAll('.picker-cb:checked')].map(cb => cb.dataset.id);
  if (!ids.length) return;
  const btn = document.getElementById('btn-enroll');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Enrolling...';
  post({ action: 'enroll_students', subject_id: subjectId, student_ids: JSON.stringify(ids) }, res => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa fa-user-plus"></i> Enroll Selected';
    if (res.success) {
      showToast(res.message, 'ok');
      ids.forEach(id => { const st = pickerStudents.find(x => x.id == id); if (st) st.already_enrolled = 1; });
      loadEnrolled(subjectId); filterPicker();
    } else showToast(res.message || 'Enrollment failed.', 'err');
  });
}

// ── UNENROLL ──────────────────────────────────────────────────────
function unenrollStudent(subjectId, studentId, name) {
  showConfirm('warn', 'Remove Student?', `Remove <strong>${name}</strong> from this subject?`, 'var(--amber)', '<i class="fa fa-user-minus"></i> Remove', () => {
    post({ action: 'unenroll_student', subject_id: subjectId, student_id: studentId }, res => {
      if (res.success) {
        showToast('Student removed.', 'ok');
        const st = pickerStudents.find(x => x.id == studentId);
        if (st) st.already_enrolled = 0;
        loadEnrolled(subjectId); filterPicker();
      } else showToast(res.message || 'Failed.', 'err');
    });
  });
}

function confirmUnenrollAll(subjectId) {
  showConfirm('danger', 'Remove All Students?', 'All enrolled students will be removed from this subject.', 'var(--red)', '<i class="fa fa-user-minus"></i> Remove All', () => {
    post({ action: 'unenroll_all', subject_id: subjectId }, res => {
      if (res.success) {
        showToast('All students removed.', 'ok');
        pickerStudents.forEach(st => st.already_enrolled = 0);
        loadEnrolled(subjectId); filterPicker();
      } else showToast(res.message || 'Failed.', 'err');
    });
  });
}

// ── CREATE / EDIT MODAL ───────────────────────────────────────────
function buildColorSwatches() {
  const wrap = document.getElementById('color-swatches');
  COLORS.forEach(c => {
    const sw = document.createElement('div');
    sw.className = 'color-swatch' + (c === selectedColor ? ' selected' : '');
    sw.style.background = c; sw.title = c;
    sw.onclick = () => {
      document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('selected'));
      sw.classList.add('selected'); selectedColor = c;
      document.getElementById('preview-logo').style.background = c;
    };
    wrap.appendChild(sw);
  });
}

function openCreateModal() {
  document.getElementById('edit-subj-id').value = '';
  document.getElementById('subj-modal-title').innerHTML = '<i class="fa fa-plus"></i> Create Subject';
  document.getElementById('subj-modal-save').innerHTML  = '<i class="fa fa-check"></i> Create Subject';
  ['m-teacher','m-name','m-desc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('m-grade').value      = '';
  document.getElementById('m-grade').disabled   = false;
  document.getElementById('m-section').value    = '';
  document.getElementById('m-section').disabled = true;
  document.getElementById('m-section').innerHTML = '<option value="">All Sections</option>';
  document.getElementById('m-status').value     = 'active';
  document.getElementById('grade-lock-notice').style.display = 'none';
  setModalColor('#2563eb'); updatePreview();
  document.getElementById('subj-modal').classList.add('show');
}

function openEditModal(id) {
  const s = allSubjects.find(x => x.id == id);
  if (!s) return;
  document.getElementById('edit-subj-id').value = id;
  document.getElementById('subj-modal-title').innerHTML = '<i class="fa fa-pen"></i> Edit Subject';
  document.getElementById('subj-modal-save').innerHTML  = '<i class="fa fa-floppy-disk"></i> Save Changes';
  document.getElementById('m-teacher').value = s.teacher_id;
  document.getElementById('m-name').value    = s.subject_name;
  document.getElementById('m-desc').value    = s.description || '';
  document.getElementById('m-status').value  = s.status;
  setModalColor(s.theme_color || '#2563eb'); updatePreview();
  const tSel     = document.getElementById('m-teacher');
  const tOpt     = tSel.options[tSel.selectedIndex];
  const advGrade = tOpt ? (tOpt.dataset.advisoryGrade || '') : '';
  const gradeEl  = document.getElementById('m-grade');
  const notice   = document.getElementById('grade-lock-notice');
  if (advGrade) {
    gradeEl.value = advGrade; gradeEl.disabled = true;
    notice.style.display = 'flex';
    notice.innerHTML = `<i class="fa fa-lock"></i> Grade locked to <strong>${advGrade}</strong>`;
  } else {
    gradeEl.value = s.grade; gradeEl.disabled = false;
    notice.style.display = 'none';
  }
  if (s.grade) onModalGrade(() => { document.getElementById('m-section').value = s.section || ''; });
  document.getElementById('subj-modal').classList.add('show');
}

function setModalColor(color) {
  selectedColor = color;
  document.getElementById('preview-logo').style.background = color;
  document.querySelectorAll('.color-swatch').forEach(sw => sw.classList.toggle('selected', sw.title === color));
}

function onModalTeacherChange() {
  const sel      = document.getElementById('m-teacher');
  const opt      = sel.options[sel.selectedIndex];
  const advGrade = opt ? (opt.dataset.advisoryGrade || '') : '';
  const gradeEl  = document.getElementById('m-grade');
  const notice   = document.getElementById('grade-lock-notice');
  if (opt && opt.dataset.color) setModalColor(opt.dataset.color);
  if (advGrade) {
    gradeEl.value = advGrade; gradeEl.disabled = true;
    notice.style.display = 'flex';
    notice.innerHTML = `<i class="fa fa-lock"></i> Grade locked to <strong>${advGrade}</strong>`;
    onModalGrade();
  } else {
    gradeEl.disabled = false; gradeEl.value = '';
    notice.style.display = 'none';
    const secEl = document.getElementById('m-section');
    secEl.innerHTML = '<option value="">All Sections</option>'; secEl.disabled = true;
  }
}

function onModalGrade(callback) {
  const grade = document.getElementById('m-grade').value;
  const secEl = document.getElementById('m-section');
  secEl.innerHTML = '<option value="">All Sections</option>'; secEl.disabled = true;
  if (!grade) { if (callback) callback(); return; }
  const populate = (secs) => {
    secs.forEach(s => { const o = document.createElement('option'); o.value = s.section_name; o.textContent = s.section_name; secEl.appendChild(o); });
    secEl.disabled = false;
    if (callback) callback();
  };
  if (sectionCache[grade]) { populate(sectionCache[grade]); return; }
  post({ action: 'get_sections', grade }, res => {
    if (res.success) { sectionCache[grade] = res.sections; populate(res.sections); }
    else if (callback) callback();
  });
}

function updatePreview() {
  const name = document.getElementById('m-name').value.trim() || 'Subject';
  document.getElementById('preview-logo').textContent = name.charAt(0).toUpperCase();
  document.getElementById('preview-name').textContent = name;
}

function closeSubjModal() { document.getElementById('subj-modal').classList.remove('show'); }

function saveSubject() {
  const editId    = document.getElementById('edit-subj-id').value;
  const teacherId = document.getElementById('m-teacher').value;
  const name      = document.getElementById('m-name').value.trim();
  const grade     = document.getElementById('m-grade').value;
  const section   = document.getElementById('m-section').value;
  const desc      = document.getElementById('m-desc').value.trim();
  const status    = document.getElementById('m-status').value;
  if (!name)  { showToast('Subject name is required.', 'err'); return; }
  if (!grade) { showToast('Grade level is required.', 'err'); return; }
  const btn = document.getElementById('subj-modal-save');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Saving...';
  if (editId) {
    post({ action: 'update_subject', subject_id: editId, subject_name: name, grade, section, description: desc, theme_color: selectedColor, status }, res => {
      btn.disabled = false; btn.innerHTML = '<i class="fa fa-floppy-disk"></i> Save Changes';
      if (res.success) { showToast('Subject updated!', 'ok'); closeSubjModal(); loadSubjects(true); }
      else showToast(res.message || 'Failed.', 'err');
    });
  } else {
    if (!teacherId) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Create Subject'; showToast('Please select a teacher.', 'err'); return; }
    post({ action: 'create_subject', teacher_id: teacherId, subject_name: name, grade, section, description: desc, theme_color: selectedColor }, res => {
      btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Create Subject';
      if (res.success) {
        showToast('Subject created!', 'ok'); closeSubjModal();
        allSubjects.unshift(res.subject); applyFilters(); selectSubject(res.subject.id);
      } else showToast(res.message || 'Failed.', 'err');
    });
  }
}

// ── ARCHIVE ───────────────────────────────────────────────────────
function confirmArchive(id, name) {
  showConfirm('danger', 'Archive Subject?',
    `Archive <strong>${name}</strong>? Students will lose access until it is restored.`,
    'var(--red)', '<i class="fa fa-box-archive"></i> Archive',
    () => {
      post({ action: 'archive_subject', subject_id: id }, res => {
        if (res.success) {
          showToast('Subject archived.', 'ok');
          const s = allSubjects.find(x => x.id == id);
          if (s) s.status = 'deactivated';
          // Show unarchive button in topbar
          const unarchiveBtn = document.getElementById('btn-topbar-unarchive');
          if (unarchiveBtn) unarchiveBtn.style.display = '';
          loadSubjects(true);
        } else showToast(res.message || 'Failed.', 'err');
      });
    }
  );
}

// ── UNARCHIVE ─────────────────────────────────────────────────────
function unarchiveSelected() {
  const s = allSubjects.find(x => x.id == selectedSubjId);
  if (!s) return;
  showConfirm('green', 'Unarchive Subject?',
    `Restore <strong>${s.subject_name}</strong>? It will become active again and students will regain access.`,
    'var(--green)', '<i class="fa fa-box-open"></i> Unarchive',
    () => {
      post({ action: 'unarchive_subject', subject_id: s.id }, res => {
        if (res.success) {
          showToast('Subject restored to active.', 'ok');
          s.status = 'active';
          const unarchiveBtn = document.getElementById('btn-topbar-unarchive');
          if (unarchiveBtn) unarchiveBtn.style.display = 'none';
          loadSubjects(true);
        } else showToast(res.message || 'Failed.', 'err');
      });
    }
  );
}

// ── CONFIRM MODAL ─────────────────────────────────────────────────
function showConfirm(type, title, msg, btnColor, btnLabel, cb) {
  const icon = document.getElementById('ci-icon');
  icon.className = 'confirm-icon ci-' + type;
  const iconMap = { danger: 'triangle-exclamation', warn: 'question-circle', green: 'circle-check' };
  icon.innerHTML = `<i class="fa fa-${iconMap[type]||'question-circle'}"></i>`;
  document.getElementById('ci-title').textContent = title;
  document.getElementById('ci-msg').innerHTML     = msg;
  const btn = document.getElementById('ci-confirm-btn');
  btn.style.cssText = `background:${btnColor};color:#fff;border:none`;
  btn.innerHTML = btnLabel;
  btn.onclick = () => { closeConfirm(); cb(); };
  document.getElementById('confirm-modal').classList.add('show');
}
function closeConfirm() { document.getElementById('confirm-modal').classList.remove('show'); }
</script>
</body>
</html>