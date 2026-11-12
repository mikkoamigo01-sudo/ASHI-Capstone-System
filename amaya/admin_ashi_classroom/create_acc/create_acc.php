<?php
session_start();

$host   = 'localhost';
$dbname = 'u304756553_ashi_classroom';
$db_user = 'u304756553_ashi_classroom';
$db_pass = 'Ashi_Classroom17';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'DB connection failed: ' . $e->getMessage()]));
}

function respond(bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit();
}

function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'get_sections') {
        $grade = clean($_POST['grade'] ?? '');
        if (!$grade) respond(false, 'Grade is required.');
        $stmt = $pdo->prepare("SELECT id, section_name, theme FROM sections WHERE grade_level = ? AND status = 'active' ORDER BY section_name ASC");
        $stmt->execute([$grade]);
        respond(true, 'ok', ['sections' => $stmt->fetchAll()]);
    }

    if ($action === 'add_section') {
        $grade = clean($_POST['grade'] ?? '');
        $name  = clean($_POST['section_name'] ?? '');
        $theme = clean($_POST['theme'] ?? '');
        if (!$grade || !$name) respond(false, 'Grade and section name are required.');
        $chk = $pdo->prepare("SELECT id FROM sections WHERE grade_level = ? AND section_name = ? LIMIT 1");
        $chk->execute([$grade, $name]);
        if ($chk->fetch()) respond(false, "Section \"$name\" already exists for $grade.");
        $ins = $pdo->prepare("INSERT INTO sections (grade_level, section_name, theme, status) VALUES (?, ?, ?, 'active')");
        $ins->execute([$grade, $name, $theme ?: null]);
        respond(true, "Section \"$name\" added to $grade.", ['id' => $pdo->lastInsertId(), 'section_name' => $name, 'theme' => $theme]);
    }

    if ($action === 'get_students') {
        $rows = $pdo->query("SELECT id, lrn, first_name, middle_name, last_name, email, username, grade_level, section, birthdate, contact_number, guardian_name, relationship, guardian_contact, plain_password, status, created_at FROM students WHERE status = 'active' ORDER BY created_at DESC")->fetchAll();
        respond(true, 'ok', ['data' => $rows]);
    }

    if ($action === 'get_teachers') {
        $rows = $pdo->query("SELECT id, fullname, email, gender, phone, address, education, specialization, advisory_grade, advisory_section, plain_password, status, created_at FROM teachers WHERE status = 'active' ORDER BY created_at DESC")->fetchAll();
        respond(true, 'ok', ['data' => $rows]);
    }

    if ($action === 'archive_student') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) respond(false, 'Invalid ID.');
        $stmt = $pdo->prepare("UPDATE students SET status='archived' WHERE id=? AND status='active'");
        $stmt->execute([$id]);
        if ($stmt->rowCount()) respond(true, 'Student archived successfully.');
        respond(false, 'Student not found or already archived.');
    }

    if ($action === 'archive_teacher') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) respond(false, 'Invalid ID.');
        $stmt = $pdo->prepare("UPDATE teachers SET status='archived' WHERE id=? AND status='active'");
        $stmt->execute([$id]);
        if ($stmt->rowCount()) respond(true, 'Teacher archived successfully.');
        respond(false, 'Teacher not found or already archived.');
    }

    if ($action === 'reset_password') {
        $id       = (int)($_POST['id'] ?? 0);
        $type     = clean($_POST['type'] ?? '');
        $newplain = trim($_POST['new_pass'] ?? '');
        if (!$id || !in_array($type, ['student','teacher']) || strlen($newplain) < 6) respond(false, 'Invalid reset request.');
        $hashed = password_hash($newplain, PASSWORD_DEFAULT);
        $table  = $type === 'student' ? 'students' : 'teachers';
        $stmt = $pdo->prepare("UPDATE $table SET password=?, plain_password=? WHERE id=? AND status='active'");
        $stmt->execute([$hashed, $newplain, $id]);
        if ($stmt->rowCount()) respond(true, 'Password reset successfully.', ['plain_pass' => $newplain]);
        respond(false, 'Account not found or already archived.');
    }

    if ($action === 'update_student') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) respond(false, 'Invalid ID.');
        $lrn = clean($_POST['s_lrn']); $fname = clean($_POST['s_fn']); $lname = clean($_POST['s_ln']);
        $mname = clean($_POST['s_mn'] ?? ''); $bday = clean($_POST['s_bday']); $grade = clean($_POST['s_grade']);
        $section = clean($_POST['s_section']); $contact = clean($_POST['s_contact'] ?? '');
        $gname = clean($_POST['s_gname'] ?? ''); $rel = clean($_POST['s_rel'] ?? ''); $gc = clean($_POST['s_gc'] ?? '');
        if (!preg_match('/^\d{12}$/', $lrn)) respond(false, 'LRN must be exactly 12 digits.');
        $cutoff = new DateTime(); $cutoff->modify('-8 years');
        $bdayDT = DateTime::createFromFormat('Y-m-d', $bday);
        if (!$bdayDT || $bdayDT > $cutoff) respond(false, 'Student must be at least 8 years old.');
        $chk = $pdo->prepare("SELECT id FROM students WHERE lrn=? AND id<>? LIMIT 1");
        $chk->execute([$lrn, $id]);
        if ($chk->fetch()) respond(false, 'LRN already used by another student.');
        $oldRow = $pdo->prepare("SELECT section FROM students WHERE id=? LIMIT 1");
        $oldRow->execute([$id]); $old = $oldRow->fetch();
        $sectionChanged = $old && $old['section'] !== $section;
        $upd = $pdo->prepare("UPDATE students SET lrn=:lrn, first_name=:fn, middle_name=:mn, last_name=:ln, grade_level=:grade, section=:section, birthdate=:bday, contact_number=:contact, guardian_name=:gname, relationship=:rel, guardian_contact=:gc WHERE id=:id");
        $upd->execute([':lrn'=>$lrn,':fn'=>$fname,':mn'=>$mname?:null,':ln'=>$lname,':grade'=>$grade,':section'=>$section,':bday'=>$bday,':contact'=>$contact?:null,':gname'=>$gname?:null,':rel'=>$rel?:null,':gc'=>$gc?:null,':id'=>$id]);
        respond(true, 'Student updated successfully.', ['section_changed' => $sectionChanged]);
    }

    if ($action === 'update_teacher') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) respond(false, 'Invalid ID.');
        $fullname = clean($_POST['t_fn']); $bday = clean($_POST['t_bday']); $gender = clean($_POST['t_gender']);
        $phone = clean($_POST['t_phone']); $addr = clean($_POST['t_addr']); $edu = clean($_POST['t_edu']);
        $spec = clean($_POST['t_spec']); $adv_grade = clean($_POST['t_ag'] ?? ''); $adv_sec = clean($_POST['t_as'] ?? '');
        $bdayDT = DateTime::createFromFormat('Y-m-d', $bday);
        $cutoff = new DateTime(); $cutoff->modify('-18 years');
        if (!$bdayDT || $bdayDT > $cutoff) respond(false, 'Teacher must be at least 18 years old.');
        $chk = $pdo->prepare("SELECT id FROM teachers WHERE fullname=? AND id<>? LIMIT 1");
        $chk->execute([$fullname, $id]);
        if ($chk->fetch()) respond(false, 'Another teacher with this name already exists.');
        $upd = $pdo->prepare("UPDATE teachers SET fullname=:fn, birthdate=:bday, gender=:gender, phone=:phone, address=:addr, education=:edu, specialization=:spec, advisory_grade=:ag, advisory_section=:as_ WHERE id=:id");
        $upd->execute([':fn'=>$fullname,':bday'=>$bday,':gender'=>$gender,':phone'=>$phone,':addr'=>$addr,':edu'=>$edu,':spec'=>$spec,':ag'=>$adv_grade?:null,':as_'=>$adv_sec?:null,':id'=>$id]);
        respond(true, 'Teacher updated successfully.');
    }

    if ($action === 'create_student') {
        $required = ['s_lrn','s_fn','s_ln','s_bday','s_grade','s_section','s_email','s_uname','s_pass'];
        foreach ($required as $f) { if (empty($_POST[$f])) respond(false, "Missing required field: $f"); }
        $lrn = clean($_POST['s_lrn']); $fname = clean($_POST['s_fn']); $lname = clean($_POST['s_ln']);
        $mname = clean($_POST['s_mn'] ?? ''); $bday = clean($_POST['s_bday']); $grade = clean($_POST['s_grade']);
        $section = clean($_POST['s_section']); $contact = clean($_POST['s_contact'] ?? '');
        $gname = clean($_POST['s_gname'] ?? ''); $rel = clean($_POST['s_rel'] ?? ''); $gc = clean($_POST['s_gc'] ?? '');
        $email = strtolower(clean($_POST['s_email'])); $username = strtolower(clean($_POST['s_uname'])); $plain = $_POST['s_pass'];
        if (!preg_match('/^\d{12}$/', $lrn)) respond(false, 'LRN must be exactly 12 digits.');
        $cutoff = new DateTime(); $cutoff->modify('-8 years');
        $bdayDT = DateTime::createFromFormat('Y-m-d', $bday);
        if (!$bdayDT || $bdayDT > $cutoff) respond(false, 'Student must be at least 8 years old.');
        $chk = $pdo->prepare("SELECT id FROM students WHERE lrn=? OR email=? OR username=? LIMIT 1");
        $chk->execute([$lrn, $email, $username]);
        if ($chk->fetch()) respond(false, 'LRN, email, or username already exists.');
        $chkT = $pdo->prepare("SELECT id FROM teachers WHERE email=? LIMIT 1");
        $chkT->execute([$email]);
        if ($chkT->fetch()) respond(false, 'Email already used by a teacher account.');
        $hashed = password_hash($plain, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO students (lrn, first_name, middle_name, last_name, email, username, grade_level, section, birthdate, contact_number, guardian_name, relationship, guardian_contact, password, plain_password, status, created_at) VALUES (:lrn,:fn,:mn,:ln,:email,:uname,:grade,:section,:bday,:contact,:gname,:rel,:gc,:pass,:plain,'active',NOW())");
        $ins->execute([':lrn'=>$lrn,':fn'=>$fname,':mn'=>$mname?:null,':ln'=>$lname,':email'=>$email,':uname'=>$username,':grade'=>$grade,':section'=>$section,':bday'=>$bday,':contact'=>$contact?:null,':gname'=>$gname?:null,':rel'=>$rel?:null,':gc'=>$gc?:null,':pass'=>$hashed,':plain'=>$plain]);
        respond(true, 'Student account created successfully.', ['id'=>$pdo->lastInsertId(),'name'=>"$fname $lname",'email'=>$email,'username'=>$username,'plain_pass'=>$plain]);
    }

    if ($action === 'create_teacher') {
        $required = ['t_fn','t_bday','t_gender','t_phone','t_addr','t_edu','t_spec','t_email','t_pass'];
        foreach ($required as $f) { if (empty($_POST[$f])) respond(false, "Missing required field: $f"); }
        $fullname = clean($_POST['t_fn']); $bday = clean($_POST['t_bday']); $gender = clean($_POST['t_gender']);
        $phone = clean($_POST['t_phone']); $addr = clean($_POST['t_addr']); $edu = clean($_POST['t_edu']);
        $spec = clean($_POST['t_spec']); $adv_grade = clean($_POST['t_ag'] ?? ''); $adv_sec = clean($_POST['t_as'] ?? '');
        $email = strtolower(clean($_POST['t_email'])); $username = strtolower(clean($_POST['t_uname'])); $plain = $_POST['t_pass'];
        $bdayDT = DateTime::createFromFormat('Y-m-d', $bday);
        if (!$bdayDT) respond(false, 'Invalid birthdate.');
        $chkT = $pdo->prepare("SELECT id FROM teachers WHERE email=? OR fullname=? LIMIT 1");
        $chkT->execute([$email, $fullname]);
        if ($chkT->fetch()) respond(false, 'Email or full name already exists in teachers.');
        $chkS = $pdo->prepare("SELECT id FROM students WHERE email=? LIMIT 1");
        $chkS->execute([$email]);
        if ($chkS->fetch()) respond(false, 'Email already used by a student account.');
        $hashed = password_hash($plain, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO teachers (fullname,birthdate,gender,phone,email,address,education,specialization,advisory_grade,advisory_section,password,plain_password,theme_color,status,created_at) VALUES (:fn,:bday,:gender,:phone,:email,:addr,:edu,:spec,:ag,:as_,:pass,:plain,'#2563eb','active',NOW())");
        $ins->execute([':fn'=>$fullname,':bday'=>$bday,':gender'=>$gender,':phone'=>$phone,':email'=>$email,':addr'=>$addr,':edu'=>$edu,':spec'=>$spec,':ag'=>$adv_grade?:null,':as_'=>$adv_sec?:null,':pass'=>$hashed,':plain'=>$plain]);
        respond(true, 'Teacher account created successfully.', ['id'=>$pdo->lastInsertId(),'name'=>$fullname,'email'=>$email,'username'=>$username,'plain_pass'=>$plain]);
    }

    if ($action === 'get_all_sections') {
        $stmt = $pdo->query("SELECT s.id, s.grade_level, s.section_name, s.theme, s.status, COUNT(DISTINCT st.id) AS student_count, MAX(t.fullname) AS adviser_name FROM sections s LEFT JOIN students st ON st.section = s.section_name AND st.grade_level = s.grade_level AND st.status = 'active' LEFT JOIN teachers t ON t.advisory_grade = s.grade_level AND t.advisory_section = s.section_name AND t.status = 'active' WHERE s.status = 'active' GROUP BY s.id ORDER BY s.grade_level ASC, s.section_name ASC");
        respond(true, 'ok', ['sections' => $stmt->fetchAll()]);
    }

    if ($action === 'get_section_students') {
        $grade = clean($_POST['grade'] ?? ''); $section = clean($_POST['section'] ?? '');
        if (!$grade || !$section) respond(false, 'Grade and section required.');
        $stmt = $pdo->prepare("SELECT id, lrn, first_name, middle_name, last_name, email FROM students WHERE grade_level = ? AND section = ? AND status = 'active' ORDER BY last_name ASC, first_name ASC");
        $stmt->execute([$grade, $section]);
        respond(true, 'ok', ['students' => $stmt->fetchAll()]);
    }

    if ($action === 'edit_section') {
        $id = (int)($_POST['id'] ?? 0); $newName = clean($_POST['new_name'] ?? ''); $grade = clean($_POST['grade'] ?? '');
        if (!$id || !$newName || !$grade) respond(false, 'Missing fields.');
        $old = $pdo->prepare("SELECT section_name FROM sections WHERE id = ? LIMIT 1");
        $old->execute([$id]); $oldRow = $old->fetch();
        if (!$oldRow) respond(false, 'Section not found.');
        $oldName = $oldRow['section_name'];
        $chk = $pdo->prepare("SELECT id FROM sections WHERE grade_level = ? AND section_name = ? AND id <> ? LIMIT 1");
        $chk->execute([$grade, $newName, $id]);
        if ($chk->fetch()) respond(false, "Section \"$newName\" already exists for $grade.");
        $pdo->prepare("UPDATE sections SET section_name = ? WHERE id = ?")->execute([$newName, $id]);
        $upd = $pdo->prepare("UPDATE students SET section = ? WHERE grade_level = ? AND section = ? AND status = 'active'");
        $upd->execute([$newName, $grade, $oldName]); $affected = $upd->rowCount();
        $pdo->prepare("UPDATE teachers SET advisory_section = ? WHERE advisory_grade = ? AND advisory_section = ? AND status = 'active'")->execute([$newName, $grade, $oldName]);
        respond(true, 'Section updated successfully.', ['old_name'=>$oldName,'new_name'=>$newName,'students_updated'=>$affected]);
    }

    if ($action === 'remove_section') {
        $id = (int)($_POST['id'] ?? 0); $grade = clean($_POST['grade'] ?? '');
        if (!$id || !$grade) respond(false, 'Missing fields.');
        $row = $pdo->prepare("SELECT section_name FROM sections WHERE id = ? LIMIT 1");
        $row->execute([$id]); $secRow = $row->fetch();
        if (!$secRow) respond(false, 'Section not found.');
        $secName = $secRow['section_name'];
        $upd = $pdo->prepare("UPDATE students SET section = 'Unassigned' WHERE grade_level = ? AND section = ? AND status = 'active'");
        $upd->execute([$grade, $secName]); $affected = $upd->rowCount();
        $pdo->prepare("UPDATE teachers SET advisory_grade = NULL, advisory_section = NULL WHERE advisory_grade = ? AND advisory_section = ? AND status = 'active'")->execute([$grade, $secName]);
        $pdo->prepare("UPDATE sections SET status = 'inactive' WHERE id = ?")->execute([$id]);
        respond(true, 'Section removed.', ['students_unassigned' => $affected]);
    }

    respond(false, 'Unknown action.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../../img/ashi.jpg" type="image/x-icon">
<title>ASHI Amaya — Account Management</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --green:#16a34a; --green-light:#dcfce7; --green-dark:#15803d; --green-mid:#bbf7d0;
  --blue:#0ea5e9; --blue-light:#e0f2fe; --blue-dark:#0284c7; --blue-mid:#bae6fd;
  --violet:#7c3aed; --violet-light:#ede9fe; --violet-dark:#6d28d9; --violet-mid:#ddd6fe;
  --accent:var(--blue); --accent2:var(--blue-dark);
  --danger:#dc2626; --warn:#d97706; --ok:var(--green);
  --bg:#f0f7ff; --card:#ffffff; --border:#e2e8f0; --text:#1e293b; --muted:#64748b; --radius:8px;
  --topbar-bg:linear-gradient(135deg,#15803d 0%,#0ea5e9 55%,#7c3aed 100%);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;font-size:13px;background:var(--bg);color:var(--text);min-height:100vh;}
.topbar{background:var(--topbar-bg);color:#fff;padding:7px 18px;display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;letter-spacing:.3px;}
.topbar i{font-size:14px;}
.tabs{display:flex;gap:3px;padding:9px 18px 0;}
.tab-btn{padding:5px 12px;border:none;border-radius:6px 6px 0 0;background:#dde8f5;color:var(--muted);font-family:inherit;font-size:11px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:5px;transition:background .2s,color .2s;}
.tab-btn:hover{background:#c5d8ef;}
.tab-btn.active{background:var(--card);color:var(--blue);font-weight:700;box-shadow:0 -2px 0 var(--blue) inset;}
.tab-panel{display:none;padding:0 14px 20px;}
.tab-panel.active{display:block;}
.split{display:flex;gap:14px;align-items:flex-start;}
.form-card{flex:0 0 340px;width:340px;min-width:340px;max-width:340px;background:var(--card);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;}
.table-panel{flex:1;min-width:0;}
.form-section{padding:10px 12px;border-bottom:1px solid var(--border);}
.form-section:last-of-type{border-bottom:none;}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:7px;display:flex;align-items:center;gap:5px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.field{display:flex;flex-direction:column;gap:3px;}
.field.span2{grid-column:span 2;}
label{font-size:11.5px;font-weight:500;color:var(--muted);display:flex;align-items:center;gap:4px;}
.req{color:var(--danger);} .opt{color:var(--muted);font-weight:400;font-size:11px;}
.opt-tag{background:#f1f5f9;border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px;font-weight:400;color:var(--muted);margin-left:5px;}
input,select,textarea{padding:6px 9px;border:1px solid var(--border);border-radius:5px;font-family:inherit;font-size:12px;color:var(--text);background:#fff;transition:border .15s,box-shadow .15s;width:100%;}
input:focus,select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 2px rgba(14,165,233,.14);}
input.gen{background:#f8fafc;color:var(--muted);font-size:12px;font-family:monospace;}
input.invalid,select.invalid{border-color:var(--danger);}
.err-msg{font-size:11px;color:var(--danger);display:none;align-items:center;gap:3px;}
.err-msg.show{display:flex;}
.hint{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:3px;}
.section-wrap{display:flex;flex-direction:column;gap:4px;}
.section-row{display:flex;gap:5px;}
.section-row select{flex:1;}
.btn-add-section{padding:7px 11px;background:var(--green-light);color:var(--green);border:1px solid var(--green-mid);border-radius:6px;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:4px;transition:background .15s;}
.btn-add-section:hover{background:#bbf7d0;}
.section-theme-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--blue);font-weight:500;background:var(--blue-light);border:1px solid var(--blue-mid);border-radius:99px;padding:2px 9px;margin-top:1px;width:fit-content;}
.add-section-form{display:none;flex-direction:column;gap:6px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:9px;margin-top:3px;}
.add-section-form.show{display:flex;}
.add-section-row{display:flex;gap:6px;align-items:flex-end;}
.add-section-row input{flex:1;}
.btn-save-section{padding:7px 12px;background:var(--blue);color:#fff;border:none;border-radius:6px;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:4px;}
.btn-save-section:hover{background:var(--blue-dark);}
.btn-cancel-section{padding:7px 10px;background:#f1f5f9;color:var(--muted);border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:12px;cursor:pointer;}
.btn-cancel-section:hover{background:#e2e8f0;}
.section-warning{display:none;align-items:flex-start;gap:6px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:7px 10px;font-size:11.5px;color:#92400e;margin-top:3px;}
.section-warning.show{display:flex;}
.notice{display:none;align-items:flex-start;gap:7px;padding:7px 11px;border-radius:6px;font-size:12px;margin:0 10px 9px;line-height:1.5;}
.notice.show{display:flex;}
.notice.warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.notice.info{background:var(--blue-light);border:1px solid var(--blue-mid);color:var(--blue-dark);}
.notice.success{background:var(--green-light);border:1px solid var(--green-mid);color:var(--green-dark);}
.notice.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.notice i{margin-top:2px;flex-shrink:0;}
.form-footer{padding:8px 12px;background:#f8fafc;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.footer-note{font-size:11px;color:var(--muted);}
.btn-row{display:flex;gap:6px;}
.btn{padding:7px 13px;border:none;border-radius:5px;font-family:inherit;font-size:12px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:background .15s,opacity .15s;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.btn-primary{background:var(--blue);color:#fff;}
.btn-primary:hover:not(:disabled){background:var(--blue-dark);}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border);}
.btn-ghost:hover{background:#f1f5f9;}
.btn-print{background:#0f172a;color:#fff;}
.btn-print:hover{background:#1e293b;}
.table-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding-top:2px;}
.table-title{font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px;}
.count-pill{background:var(--blue);color:#fff;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:700;}
.table-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;}
thead tr{background:#f0f7ff;}
th{padding:7px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--blue-dark);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--blue-mid);white-space:nowrap;}
th i{margin-right:3px;}
td{padding:7px 10px;border-bottom:1px solid var(--border);vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr{cursor:pointer;transition:background .12s;}
tbody tr:hover{background:#f0f7ff;}
.td-mono{font-family:monospace;font-size:11px;}
.td-name{font-weight:600;font-size:12px;}
.td-sub{font-size:11px;color:var(--muted);}
.empty-state{text-align:center;padding:32px 16px;color:var(--muted);}
.empty-state i{font-size:26px;margin-bottom:8px;display:block;opacity:.35;}
.empty-state p{font-size:12px;line-height:1.6;}
.role-pill{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:600;}
.pill-t{background:var(--blue-light);color:var(--blue-dark);}
.pill-a{background:var(--violet-light);color:var(--violet-dark);}
.detail-overlay{position:fixed;inset:0;background:rgba(0,0,0,.38);opacity:0;pointer-events:none;transition:opacity .25s;z-index:100;}
.detail-overlay.show{opacity:1;pointer-events:all;}
.detail-panel{position:fixed;top:0;right:0;bottom:0;width:400px;background:var(--card);box-shadow:-4px 0 28px rgba(0,0,0,.14);display:flex;flex-direction:column;transform:translateX(100%);transition:transform .28s cubic-bezier(.4,0,.2,1);z-index:101;}
.detail-panel.open{transform:translateX(0);}
.detail-header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;background:linear-gradient(135deg,var(--blue-light),var(--violet-light));}
.detail-title{font-size:14px;font-weight:700;}
.detail-role{font-size:11.5px;color:var(--blue-dark);font-weight:600;margin-top:2px;display:flex;align-items:center;gap:4px;}
.detail-role.adv{color:var(--violet-dark);}
.close-btn{background:none;border:none;font-size:17px;cursor:pointer;color:var(--muted);padding:3px;transition:color .15s;}
.close-btn:hover{color:var(--text);}
.detail-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:14px;}
.detail-actions{padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:7px;justify-content:flex-end;}
.slip{border:2px solid var(--blue);border-radius:8px;overflow:hidden;background:#fff;}
.slip-head{background:linear-gradient(135deg,var(--blue-dark),var(--violet));color:#fff;padding:11px 14px;display:flex;align-items:center;gap:10px;}
.slip-logo{width:34px;height:34px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:18px;flex-shrink:0;}
.slip-school{font-weight:700;font-size:13px;}
.slip-tag{font-size:11px;opacity:.8;margin-top:1px;}
.slip-body-inner{padding:12px 14px;}
.slip-fullname{font-size:15px;font-weight:700;margin-bottom:3px;}
.slip-role-tag{font-size:12px;color:var(--blue-dark);font-weight:600;margin-bottom:11px;display:flex;align-items:center;gap:4px;}
.slip-creds{display:flex;flex-direction:column;gap:6px;}
.slip-row{display:flex;align-items:baseline;gap:7px;font-size:12px;}
.slip-key{min-width:95px;font-weight:700;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px;flex-shrink:0;}
.slip-val{font-family:monospace;font-size:12px;color:var(--text);word-break:break-all;}
.slip-foot{background:#fef3c7;border-top:1px solid #fcd34d;padding:7px 14px;font-size:11px;color:#92400e;display:flex;align-items:center;gap:6px;}
.info-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--violet-dark);margin-bottom:9px;display:flex;align-items:center;gap:5px;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.info-item{background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:8px 10px;}
.info-item.span2{grid-column:span 2;}
.info-key{font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;}
.info-val{font-size:13px;font-weight:600;}
#toast{position:fixed;bottom:18px;right:18px;padding:9px 15px;border-radius:7px;font-size:12.5px;font-weight:500;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.16);display:flex;align-items:center;gap:6px;z-index:200;transform:translateY(70px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;}
#toast.show{transform:translateY(0);opacity:1;}
#toast.ok{background:var(--green);}
#toast.err{background:var(--danger);}
.pass-pill{display:inline-block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;font-family:monospace;font-size:11.5px;color:#0f172a;letter-spacing:.3px;white-space:nowrap;}
.action-btn{width:26px;height:26px;border:none;border-radius:5px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:11.5px;transition:background .15s,transform .1s;}
.action-btn:hover{transform:scale(1.1);}
.btn-view{background:var(--blue-light);color:var(--blue-dark);}
.btn-view:hover{background:var(--blue-mid);}
.btn-archive{background:#fef2f2;color:var(--danger);}
.btn-archive:hover{background:#fecaca;}
.btn-edit{background:var(--green-light);color:var(--green-dark);}
.btn-edit:hover{background:var(--green-mid);}
.actions-cell{display:flex;gap:5px;justify-content:center;}
.edit-banner{display:none;align-items:center;gap:7px;padding:6px 13px;background:#fffbeb;border-bottom:2px solid #f59e0b;font-size:12px;font-weight:500;color:#92400e;}
.edit-banner.show{display:flex;}
.edit-banner i{font-size:13px;}
.edit-banner .cancel-edit{margin-left:auto;background:none;border:1px solid #f59e0b;border-radius:5px;color:#92400e;padding:3px 10px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.edit-banner .cancel-edit:hover{background:#fef3c7;}
.confirm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.42);display:flex;align-items:center;justify-content:center;z-index:300;opacity:0;pointer-events:none;transition:opacity .2s;}
.confirm-overlay.show{opacity:1;pointer-events:all;}
.confirm-box{background:#fff;border-radius:10px;padding:18px 20px 14px;max-width:340px;width:90%;box-shadow:0 8px 28px rgba(0,0,0,.18);transform:translateY(10px);transition:transform .2s;text-align:center;}
.confirm-overlay.show .confirm-box{transform:translateY(0);}
.confirm-icon{width:50px;height:50px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 13px;font-size:21px;color:var(--danger);}
.confirm-title{font-size:13px;font-weight:700;margin-bottom:5px;}
.confirm-msg{font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:13px;}
.confirm-btns{display:flex;gap:9px;justify-content:center;}
.btn-cancel-c{background:#f1f5f9;color:var(--text);border:none;padding:7px 15px;border-radius:5px;font-family:inherit;font-size:12px;font-weight:500;cursor:pointer;}
.btn-cancel-c:hover{background:#e2e8f0;}
.btn-confirm-c{background:var(--danger);color:#fff;border:none;padding:7px 15px;border-radius:5px;font-family:inherit;font-size:12px;font-weight:500;cursor:pointer;}
.btn-confirm-c:hover{background:#b91c1c;}
.section-loading{display:none;align-items:center;gap:5px;font-size:12px;color:var(--muted);}
.section-loading.show{display:flex;}

/* ── FILTER BAR ── */
.filter-wrap{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-top{display:flex;align-items:center;border-bottom:1px solid var(--border);}
.filter-search{flex:1;position:relative;display:flex;align-items:center;}
.filter-search-icon{position:absolute;left:11px;color:var(--muted);font-size:13px;pointer-events:none;z-index:1;}
.filter-search input{width:100%;height:34px;padding:0 10px 0 32px;border:none;border-radius:0;font-size:12px;font-family:inherit;color:var(--text);background:transparent;outline:none;}
.filter-search input::placeholder{color:#94a3b8;}
.filter-search input:focus{background:#f8fcff;}
.filter-divider-v{width:1px;height:22px;background:var(--border);flex-shrink:0;}
.filter-result-pill{display:flex;align-items:center;gap:5px;padding:0 13px;font-size:12px;color:var(--muted);white-space:nowrap;height:34px;}
.filter-result-pill .pill-num{background:var(--blue);color:#fff;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:700;min-width:24px;text-align:center;}
.filter-result-pill.hidden{display:none;}
.filter-active-tag{display:none;align-items:center;gap:4px;background:var(--green-light);border:1px solid var(--green-mid);color:var(--green-dark);border-radius:99px;padding:2px 10px;font-size:11px;font-weight:700;white-space:nowrap;}
.filter-active-tag.show{display:flex;}
.filter-bottom{display:flex;align-items:center;background:#f8fafc;}
.filter-label-group{display:flex;align-items:center;gap:6px;padding:6px 11px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;border-right:1px solid var(--border);flex-shrink:0;}
.filter-controls{display:flex;align-items:center;gap:7px;padding:6px 10px;flex:1;flex-wrap:wrap;}
.filter-select-wrap{position:relative;display:flex;align-items:center;}
.filter-select-wrap i{position:absolute;left:8px;color:var(--muted);font-size:12px;pointer-events:none;z-index:1;}
.filter-select{height:30px;padding:0 28px 0 24px;font-size:12px;border:1px solid var(--border);border-radius:6px;background:#fff;color:var(--text);cursor:pointer;font-family:inherit;min-width:135px;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2394a3b8'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;transition:border-color .15s,box-shadow .15s;}
.filter-select:focus{border-color:var(--blue);box-shadow:0 0 0 2px rgba(14,165,233,.1);outline:none;}
.filter-select:disabled{background-color:#f1f5f9;color:#94a3b8;cursor:not-allowed;}
.filter-select.active{border-color:var(--green);background-color:var(--green-light);color:var(--green-dark);font-weight:700;}
.filter-arrow{display:flex;align-items:center;color:#cbd5e1;font-size:11px;flex-shrink:0;}
.filter-clear-btn{margin-left:auto;height:30px;padding:0 11px;background:transparent;border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:12px;font-weight:500;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;transition:all .15s;flex-shrink:0;}
.filter-clear-btn:hover{background:#fef2f2;border-color:#fca5a5;color:var(--danger);}
.filter-clear-btn i{font-size:11px;}

/* ── PAGINATION ── */
.pag-container{display:flex;align-items:center;justify-content:space-between;padding:8px 11px;background:#f8fafc;border-top:1px solid var(--border);min-height:38px;flex-wrap:wrap;gap:6px;}
.pag-info{font-size:11.5px;color:var(--muted);white-space:nowrap;}
.pag-info strong{color:var(--text);}
.pag-wrap{display:flex;align-items:center;gap:3px;}
.pag-btn{min-width:28px;height:28px;padding:0 6px;border:1px solid var(--border);border-radius:5px;background:#fff;color:var(--text);font-family:inherit;font-size:12px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .14s;}
.pag-btn:hover:not(:disabled):not(.active){background:var(--blue-light);border-color:var(--blue-mid);color:var(--blue-dark);}
.pag-btn.active{background:var(--blue);border-color:var(--blue);color:#fff;font-weight:700;cursor:default;}
.pag-btn:disabled{opacity:.35;cursor:not-allowed;}
.pag-ellipsis{font-size:13px;color:var(--muted);padding:0 3px;line-height:28px;}
.pag-size-select{height:28px;padding:0 22px 0 8px;font-size:12px;border:1px solid var(--border);border-radius:5px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5' viewBox='0 0 8 5'%3E%3Cpath d='M0 0l4 5 4-5z' fill='%2394a3b8'/%3E%3C/svg%3E") no-repeat right 6px center;color:var(--text);font-family:inherit;cursor:pointer;appearance:none;-webkit-appearance:none;}
.pag-size-select:focus{outline:none;border-color:var(--blue);}

/* ── SETUP TAB ── */
.setup-split{display:flex;gap:18px;align-items:flex-start;width:100%;min-width:0;}
.setup-form-card{flex:0 0 330px;width:330px;min-width:330px;max-width:330px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;position:sticky;top:8px;align-self:flex-start;}
.setup-list-panel{flex:1 1 0%;min-width:0;max-width:calc(100% - 348px);overflow:hidden;}
.setup-form-body{padding:10px 13px;display:flex;flex-direction:column;gap:8px;}
.setup-form-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;margin-bottom:1px;}
.btn-add-full{width:100%;padding:8px;background:var(--blue);color:#fff;border:none;border-radius:5px;font-family:inherit;font-size:12.5px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .15s;margin-top:2px;}
.btn-add-full:hover{background:var(--blue-dark);}
.btn-add-full:disabled{opacity:.5;cursor:not-allowed;}
.grade-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
@media(max-width:1000px){.grade-grid{grid-template-columns:1fr;}}
.grade-group{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.grade-group-header{display:flex;align-items:center;justify-content:space-between;padding:7px 11px;background:linear-gradient(135deg,var(--blue-dark) 0%,var(--violet) 100%);color:#fff;}
.grade-group-title{font-size:12px;font-weight:700;letter-spacing:.3px;display:flex;align-items:center;gap:5px;}
.grade-group-meta{font-size:10.5px;opacity:.75;}
.section-row-item{display:flex;align-items:center;padding:6px 11px;border-bottom:1px solid var(--border);gap:0;transition:background .12s;min-height:34px;}
.section-row-item:last-child{border-bottom:none;}
.section-row-item:hover{background:#f0f7ff;}
.section-name-display{font-weight:700;font-size:11.5px;letter-spacing:.5px;text-transform:uppercase;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.section-dash{color:#cbd5e1;font-size:12px;padding:0 6px;flex-shrink:0;}
.section-count-num{font-size:11.5px;font-weight:700;color:var(--muted);flex-shrink:0;min-width:20px;text-align:right;margin-right:7px;}
.section-count-num.has-students{color:var(--green);}
.section-adviser{display:flex;align-items:center;gap:3px;font-size:10.5px;white-space:nowrap;padding:2px 7px;border-radius:99px;margin-right:5px;flex-shrink:0;max-width:115px;overflow:hidden;text-overflow:ellipsis;}
.section-adviser.assigned{background:var(--green-light);border:1px solid var(--green-mid);color:var(--green-dark);font-weight:700;}
.section-adviser.unassigned{background:#f8fafc;border:1px solid var(--border);color:#94a3b8;font-style:italic;}
.section-adviser i{flex-shrink:0;font-size:10px;}
.section-actions{display:flex;gap:3px;flex-shrink:0;}
.sec-btn{width:26px;height:26px;padding:0;border:1px solid var(--border);border-radius:5px;font-size:11.5px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .14s;background:#fff;flex-shrink:0;}
.sec-btn:hover{transform:scale(1.1);}
.sec-btn-view{color:var(--blue-dark);border-color:var(--blue-mid);background:var(--blue-light);}
.sec-btn-view:hover{background:var(--blue-mid);}
.sec-btn-edit{color:var(--green-dark);border-color:var(--green-mid);background:var(--green-light);}
.sec-btn-edit:hover{background:var(--green-mid);}
.sec-btn-remove{color:var(--danger);border-color:#fecaca;background:#fef2f2;}
.sec-btn-remove:hover{background:#fee2e2;}
.section-edit-inline{display:none;align-items:center;gap:5px;flex:1;}
.section-edit-inline.show{display:flex;}
.section-edit-inline input{flex:1;height:26px;padding:0 7px;font-size:12px;border:1px solid var(--blue);border-radius:5px;font-family:inherit;font-weight:700;text-transform:uppercase;letter-spacing:.5px;min-width:0;}
.section-edit-inline input:focus{outline:none;box-shadow:0 0 0 2px rgba(14,165,233,.18);}
.sec-btn-save{color:#fff;background:var(--blue);border-color:var(--blue);}
.sec-btn-save:hover{background:var(--blue-dark);}
.sec-btn-cancel-edit{color:var(--muted);}
.empty-grade-group{padding:13px;text-align:center;color:var(--muted);font-size:12px;display:flex;align-items:center;justify-content:center;gap:5px;}
.enrol-overlay{position:fixed;inset:0;background:rgba(0,0,0,.38);opacity:0;pointer-events:none;transition:opacity .25s;z-index:100;}
.enrol-overlay.show{opacity:1;pointer-events:all;}
.enrol-panel{position:fixed;top:0;right:0;bottom:0;width:410px;background:var(--card);box-shadow:-4px 0 28px rgba(0,0,0,.14);display:flex;flex-direction:column;transform:translateX(100%);transition:transform .28s cubic-bezier(.4,0,.2,1);z-index:101;}
.enrol-panel.open{transform:translateX(0);}
.enrol-header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;background:linear-gradient(135deg,var(--green-light),var(--blue-light));}
.enrol-title{font-size:13.5px;font-weight:700;}
.enrol-sub{font-size:12px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:4px;}
.enrol-body{flex:1;overflow-y:auto;}
.enrol-list-item{display:flex;align-items:center;gap:9px;padding:8px 14px;border-bottom:1px solid var(--border);}
.enrol-list-item:last-child{border-bottom:none;}
.enrol-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--violet));color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.enrol-name{font-weight:600;font-size:12.5px;}
.enrol-lrn{font-size:11px;color:var(--muted);font-family:monospace;}
.enrol-empty{padding:40px 16px;text-align:center;color:var(--muted);}
.enrol-empty i{font-size:28px;display:block;margin-bottom:9px;opacity:.28;}
.enrol-footer{padding:11px 16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;}
.setup-empty{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:40px 16px;text-align:center;color:var(--muted);}
.setup-empty i{font-size:30px;display:block;margin-bottom:10px;opacity:.28;}
.setup-empty p{font-size:12px;line-height:1.7;}
.confirm-warn-icon{background:#fffbeb !important;color:var(--warn) !important;}
#setup-stats>div{background:#fff;border:1px solid var(--border);border-radius:6px;padding:8px 10px;}
#s-reset-btn,#t-reset-btn{background:var(--violet)!important;font-size:12px!important;padding:7px 12px!important;border-radius:6px!important;}
#s-reset-btn:hover,#t-reset-btn:hover{background:var(--violet-dark)!important;}
#s-apply-btn,#t-apply-btn{background:#f59e0b!important;color:#fff!important;font-size:12px;}
#panel-teacher .notice.info.show{background:var(--violet-light);border-color:var(--violet-mid);color:var(--violet-dark);}
@media print{body>*:not(.detail-panel){display:none!important;}.detail-panel{position:static!important;transform:none!important;box-shadow:none!important;width:100%!important;}.detail-actions,.detail-header .close-btn{display:none!important;}}
@media(max-width:900px){.split{flex-direction:column;}.form-card{flex:none;width:100%;max-width:100%;min-width:0;}.detail-panel,.enrol-panel{width:100%;}.grade-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<div class="topbar">
  <i class="fa fa-shield-halved"></i>
  ASHI Amaya — Admin Account Management
</div>

<div id="toast"></div>

<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('setup',this)"><i class="fa fa-layer-group"></i> Grd &amp; Sctn Setup</button>
  <button class="tab-btn" onclick="switchTab('student',this)"><i class="fa fa-graduation-cap"></i> Student</button>
  <button class="tab-btn" onclick="switchTab('teacher',this)"><i class="fa fa-chalkboard-teacher"></i> Teacher</button>
</div>

<!-- SETUP TAB -->
<div class="tab-panel active" id="panel-setup">
  <div class="setup-split">
    <div class="setup-form-card">
      <div class="form-section" style="border-bottom:none">
        <div class="section-label"><i class="fa fa-plus-circle"></i> Add New Section</div>
        <div class="setup-form-body">
          <div class="field">
            <label><i class="fa fa-layer-group" style="color:var(--accent)"></i> Grade Level <span class="req">*</span></label>
            <select id="setup-grade">
              <option value="">Select grade...</option>
              <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
              <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
            </select>
          </div>
          <div class="field">
            <label><i class="fa fa-door-open" style="color:var(--accent)"></i> Section Name <span class="req">*</span></label>
            <input type="text" id="setup-section-name" placeholder="e.g. Mahogany" onkeydown="if(event.key==='Enter') addSetupSection()">
            <span class="hint"><i class="fa fa-circle-info"></i> e.g. Trees, Gems, Flowers themed names</span>
          </div>
          <div class="field">
            <label><i class="fa fa-tag" style="color:var(--accent)"></i> Theme <span class="opt">(optional)</span></label>
            <input type="text" id="setup-theme" placeholder="e.g. Trees">
          </div>
          <button class="btn-add-full" id="setup-add-btn" onclick="addSetupSection()"><i class="fa fa-plus"></i> Add Section</button>
        </div>
      </div>
      <div style="padding:14px 22px;background:#f8fafc;border-top:1px solid var(--border)">
        <div class="section-label" style="margin-bottom:10px"><i class="fa fa-chart-bar"></i> Overview</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px" id="setup-stats">
          <div style="background:#fff;border:1px solid var(--border);border-radius:7px;padding:10px 12px;">
            <div style="font-size:10px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px">Total Sections</div>
            <div style="font-size:18px;font-weight:700;color:var(--accent)" id="stat-total-sections">—</div>
          </div>
          <div style="background:#fff;border:1px solid var(--border);border-radius:7px;padding:10px 12px;">
            <div style="font-size:10px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px">Total Students</div>
            <div style="font-size:18px;font-weight:700;color:#16a34a" id="stat-total-students">—</div>
          </div>
        </div>
      </div>
    </div>
    <div class="setup-list-panel">
      <div class="table-top" style="margin-bottom:14px">
        <div class="table-title"><i class="fa fa-layer-group"></i> Grade &amp; Section Directory</div>
      </div>
      <div id="setup-section-list">
        <div class="setup-empty"><i class="fa fa-layer-group"></i><p>No sections added yet.<br>Use the form on the left to get started.</p></div>
      </div>
    </div>
  </div>
</div>

<!-- STUDENT TAB -->
<div class="tab-panel" id="panel-student">
  <div class="split">
    <div class="form-card">
      <div class="notice warn" id="dup-s"><i class="fa fa-triangle-exclamation"></i><div><strong>Duplicate first name detected.</strong> A student named "<span id="dup-sn"></span>" already exists. Credentials auto-adjusted.</div></div>
      <div class="edit-banner" id="s-edit-banner"><i class="fa fa-pen-to-square"></i>Editing: <strong id="s-edit-name">—</strong><button class="cancel-edit" onclick="cancelEditStudent()"><i class="fa fa-xmark"></i> Cancel Edit</button></div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-id-card"></i> Personal Information</div>
        <div class="form-grid">
          <div class="field span2"><label><i class="fa fa-hashtag" style="color:var(--accent)"></i> LRN <span class="req">*</span></label><input type="text" id="s-lrn" maxlength="12" placeholder="12-digit Learner Reference Number" oninput="this.value=this.value.replace(/\D/g,'').slice(0,12);clearErr(this)"><span class="err-msg" id="e-s-lrn"><i class="fa fa-circle-xmark"></i> LRN is required (12 digits)</span></div>
          <div class="field"><label><i class="fa fa-user" style="color:var(--accent)"></i> First Name <span class="req">*</span></label><input type="text" id="s-fn" placeholder="e.g. Mildred" oninput="genStudent();clearErr(this)"><span class="err-msg" id="e-s-fn"><i class="fa fa-circle-xmark"></i> First name is required</span></div>
          <div class="field"><label><i class="fa fa-user" style="color:var(--accent)"></i> Last Name <span class="req">*</span></label><input type="text" id="s-ln" placeholder="e.g. Santos" oninput="clearErr(this)"><span class="err-msg" id="e-s-ln"><i class="fa fa-circle-xmark"></i> Last name is required</span></div>
          <div class="field"><label>Middle Name <span class="opt">(optional)</span></label><input type="text" id="s-mn" placeholder="e.g. Reyes"></div>
          <div class="field"><label><i class="fa fa-calendar" style="color:var(--accent)"></i> Birthdate <span class="req">*</span></label><input type="date" id="s-bday" oninput="clearErr(this)" onchange="validateBday()"><span class="err-msg" id="e-s-bday"><i class="fa fa-circle-xmark"></i> Student must be at least 8 years old</span></div>
          <div class="field span2"><label><i class="fa fa-phone" style="color:var(--accent)"></i> Contact No. <span class="opt">(optional)</span></label><input type="text" id="s-contact" placeholder="09171234567" maxlength="11"></div>
        </div>
      </div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-school"></i> Academic Information</div>
        <div class="form-grid">
          <div class="field">
            <label><i class="fa fa-layer-group" style="color:var(--accent)"></i> Grade Level <span class="req">*</span></label>
            <select id="s-grade" onchange="onGradeChange('student');clearErr(this)">
              <option value="">Select grade...</option>
              <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
              <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
            </select>
            <span class="err-msg" id="e-s-grade"><i class="fa fa-circle-xmark"></i> Grade level is required</span>
          </div>
          <div class="field">
            <label><i class="fa fa-door-open" style="color:var(--accent)"></i> Section <span class="req">*</span></label>
            <div class="section-wrap">
              <div class="section-row"><select id="s-section-sel" onchange="onSectionSelect('student')" disabled><option value="">Select grade first...</option></select></div>
              <div class="section-loading" id="s-section-loading"><i class="fa fa-spinner fa-spin"></i> Loading sections...</div>
              <span class="section-theme-badge" id="s-section-theme" style="display:none"><i class="fa fa-tag"></i> <span id="s-section-theme-text"></span></span>
              <input type="hidden" id="s-section">
              <div class="add-section-form" id="s-add-form">
                <div style="font-size:12px;font-weight:600;color:var(--accent);display:flex;align-items:center;gap:6px;"><i class="fa fa-plus-circle"></i> Add New Section</div>
                <div class="add-section-row">
                  <div style="flex:1;display:flex;flex-direction:column;gap:4px;"><label style="font-size:11.5px">Section Name <span class="req">*</span></label><input type="text" id="s-new-section-name" placeholder="e.g. Acacia"></div>
                  <div style="display:flex;gap:6px;align-self:flex-end;"><button class="btn-save-section" onclick="saveNewSection('student')"><i class="fa fa-check"></i> Save</button><button class="btn-cancel-section" onclick="cancelAddSection('student')"><i class="fa fa-xmark"></i></button></div>
                </div>
              </div>
              <div class="section-warning" id="s-section-warn"><i class="fa fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0"></i><span>Changing the section will update this student's record. Other students in the old section are <strong>not affected</strong>.</span></div>
            </div>
            <span class="err-msg" id="e-s-section"><i class="fa fa-circle-xmark"></i> Section is required</span>
          </div>
        </div>
      </div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-people-roof"></i> Guardian Information <span class="opt-tag">(optional)</span></div>
        <div class="form-grid">
          <div class="field"><label><i class="fa fa-user-tie" style="color:var(--accent)"></i> Guardian Name</label><input type="text" id="s-gname" placeholder="Full name"></div>
          <div class="field"><label><i class="fa fa-heart" style="color:var(--accent)"></i> Relationship</label><select id="s-rel"><option value="">Select...</option><option>Mother</option><option>Father</option><option>Parent</option><option>Grandparent</option><option>Sibling</option><option>Relative</option><option>Legal Guardian</option></select></div>
          <div class="field span2"><label><i class="fa fa-phone" style="color:var(--accent)"></i> Guardian Contact</label><input type="text" id="s-gc" placeholder="09171234567" maxlength="11"></div>
        </div>
      </div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-key"></i> Auto-Generated Credentials</div>
        <div class="form-grid">
          <div class="field"><label><i class="fa fa-envelope" style="color:var(--accent)"></i> Email</label><input type="text" id="s-email" class="gen" readonly placeholder="Type name above..."></div>
          <div class="field"><label><i class="fa fa-at" style="color:var(--accent)"></i> Username</label><input type="text" id="s-uname" class="gen" readonly placeholder="Type name above..."></div>
          <div class="field span2"><label><i class="fa fa-lock" style="color:var(--accent)"></i> Password</label>
            <div style="display:flex;gap:8px;align-items:center;">
              <input type="text" id="s-pass" class="gen" readonly placeholder="Type name above..." style="flex:1">
              <button type="button" id="s-reset-btn" onclick="resetPassword('student')" style="display:none;white-space:nowrap;padding:9px 13px;background:#7c3aed;color:#fff;border:none;border-radius:7px;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;align-items:center;gap:6px;"><i class="fa fa-arrows-rotate"></i> Reset Password</button>
            </div>
            <span class="hint" id="s-pass-hint"><i class="fa fa-circle-info"></i> Student must change this after first login.</span>
          </div>
        </div>
      </div>
      <div class="form-footer">
        <span class="footer-note"><span style="color:var(--danger)">*</span> required fields</span>
        <div class="btn-row">
          <button class="btn btn-ghost" onclick="resetStudent()"><i class="fa fa-rotate-left"></i> Reset</button>
          <button class="btn btn-primary" id="s-submit-btn" onclick="submitStudent()"><i class="fa fa-check"></i> Create Account</button>
          <button class="btn" id="s-apply-btn" onclick="applyEditStudent()" style="display:none;background:#f59e0b;color:#fff;"><i class="fa fa-floppy-disk"></i> Apply Edit</button>
        </div>
      </div>
    </div>

    <!-- STUDENT TABLE PANEL -->
    <div class="table-panel">
      <div class="table-top">
        <div class="table-title"><i class="fa fa-users"></i> Student Accounts <span class="count-pill" id="s-count">0</span></div>
      </div>
      <div class="filter-wrap">
        <div class="filter-top">
          <div class="filter-search"><i class="fa fa-magnifying-glass filter-search-icon"></i><input type="text" id="sf-search" placeholder="Search by name, LRN, or email address..." oninput="applyStudentFilters()"></div>
          <div class="filter-divider-v"></div>
          <div class="filter-result-pill hidden" id="sf-count-label"><span>Showing</span><span class="pill-num" id="sf-pill-num">0</span><span id="sf-pill-of">of 0</span></div>
          <div class="filter-active-tag" id="sf-active-tag"><i class="fa fa-filter"></i> Filtered</div>
        </div>
        <div class="filter-bottom">
          <div class="filter-label-group"><i class="fa fa-sliders"></i> Filter by</div>
          <div class="filter-controls">
            <div class="filter-select-wrap"><i class="fa fa-layer-group"></i><select class="filter-select" id="sf-grade" onchange="onFilterGradeChange('student');applyStudentFilters()"><option value="">All Grades</option><option>Grade 7</option><option>Grade 8</option><option>Grade 9</option><option>Grade 10</option><option>Grade 11</option><option>Grade 12</option></select></div>
            <div class="filter-arrow"><i class="fa fa-chevron-right"></i></div>
            <div class="filter-select-wrap"><i class="fa fa-door-open"></i><select class="filter-select" id="sf-section" onchange="applyStudentFilters()" disabled><option value="">All Sections</option></select></div>
            <button class="filter-clear-btn" onclick="clearStudentFilters()"><i class="fa fa-rotate-left"></i> Reset Filters</button>
          </div>
        </div>
      </div>
      <div class="table-card">
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th><i class="fa fa-hashtag"></i> LRN</th>
              <th><i class="fa fa-user"></i> Name</th>
              <th><i class="fa fa-school"></i> Grade / Section</th>
              <th><i class="fa fa-envelope"></i> Email</th>
              <th><i class="fa fa-lock"></i> Password</th>
              <th style="text-align:center;width:100px"><i class="fa fa-bolt"></i> Actions</th>
            </tr></thead>
            <tbody id="s-tbody">
              <tr><td colspan="6"><div class="empty-state"><i class="fa fa-user-graduate"></i><p>No student accounts yet.<br>Fill the form to get started.</p></div></td></tr>
            </tbody>
          </table>
        </div>
        <!-- STUDENT PAGINATION -->
        <div class="pag-container" id="s-pag-bar" style="display:none">
          <div class="pag-info" id="s-pag-info">—</div>
          <div style="display:flex;align-items:center;gap:8px;">
            <select class="pag-size-select" id="s-pag-size" onchange="onStudentPageSizeChange()">
              <option value="15">15 / page</option>
              <option value="25">25 / page</option>
              <option value="50">50 / page</option>
              <option value="100">100 / page</option>
            </select>
            <div class="pag-wrap" id="s-pag-wrap"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- TEACHER TAB -->
<div class="tab-panel" id="panel-teacher">
  <div class="split">
    <div class="form-card">
      <div class="notice warn" id="dup-t"><i class="fa fa-triangle-exclamation"></i><div><strong>Duplicate name detected.</strong> A teacher named "<span id="dup-tn"></span>" already exists. Credentials auto-adjusted.</div></div>
      <div class="edit-banner" id="t-edit-banner"><i class="fa fa-pen-to-square"></i>Editing: <strong id="t-edit-name">—</strong><button class="cancel-edit" onclick="cancelEditTeacher()"><i class="fa fa-xmark"></i> Cancel Edit</button></div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-id-card"></i> Personal Information</div>
        <div class="form-grid">
          <div class="field span2"><label><i class="fa fa-user" style="color:var(--accent)"></i> Full Name <span class="req">*</span></label><input type="text" id="t-fn" placeholder="e.g. Maria Santos" oninput="genTeacher();clearErr(this)"><span class="err-msg" id="e-t-fn"><i class="fa fa-circle-xmark"></i> Full name is required</span></div>
          <div class="field"><label><i class="fa fa-calendar" style="color:var(--accent)"></i> Birthdate <span class="req">*</span></label><input type="date" id="t-bday" onchange="clearErr(this)"><span class="err-msg" id="e-t-bday"><i class="fa fa-circle-xmark"></i> Birthdate is required</span></div>
          <div class="field"><label><i class="fa fa-venus-mars" style="color:var(--accent)"></i> Gender <span class="req">*</span></label><select id="t-gender" onchange="clearErr(this)"><option value="">Select...</option><option>Male</option><option>Female</option><option>Prefer not to say</option></select><span class="err-msg" id="e-t-gender"><i class="fa fa-circle-xmark"></i> Gender is required</span></div>
          <div class="field"><label><i class="fa fa-phone" style="color:var(--accent)"></i> Phone <span class="req">*</span></label><input type="text" id="t-phone" placeholder="09171234567" maxlength="11" oninput="clearErr(this)"><span class="err-msg" id="e-t-phone"><i class="fa fa-circle-xmark"></i> Phone number is required</span></div>
          <div class="field"><label><i class="fa fa-envelope" style="color:var(--accent)"></i> Email (auto)</label><input type="text" id="t-email" class="gen" readonly placeholder="Enter name first..."></div>
          <div class="field span2"><label><i class="fa fa-map-marker-alt" style="color:var(--accent)"></i> Address <span class="req">*</span></label><input type="text" id="t-addr" placeholder="Full home address" oninput="clearErr(this)"><span class="err-msg" id="e-t-addr"><i class="fa fa-circle-xmark"></i> Address is required</span></div>
        </div>
      </div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-briefcase"></i> Professional Information</div>
        <div class="form-grid">
          <div class="field"><label><i class="fa fa-graduation-cap" style="color:var(--accent)"></i> Education <span class="req">*</span></label><input type="text" id="t-edu" placeholder="e.g. BSEd Major in Math" oninput="clearErr(this)"><span class="err-msg" id="e-t-edu"><i class="fa fa-circle-xmark"></i> Education is required</span></div>
          <div class="field"><label><i class="fa fa-star" style="color:var(--accent)"></i> Specialization <span class="req">*</span></label><input type="text" id="t-spec" placeholder="e.g. Mathematics" oninput="clearErr(this)"><span class="err-msg" id="e-t-spec"><i class="fa fa-circle-xmark"></i> Specialization is required</span></div>
        </div>
      </div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-chalkboard"></i> Advisory Class <span class="opt-tag">(optional)</span></div>
        <div class="notice info show" style="margin:0 0 12px;display:flex;"><i class="fa fa-circle-info"></i><div>Fill only if this teacher is also an <strong>Adviser</strong>. Leave blank for subject teachers.</div></div>
        <div class="form-grid">
          <div class="field">
            <label><i class="fa fa-layer-group" style="color:var(--accent)"></i> Advisory Grade</label>
            <select id="t-ag" onchange="onGradeChange('teacher')">
              <option value="">None / Not an Adviser</option>
              <option>Grade 7</option><option>Grade 8</option><option>Grade 9</option>
              <option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
            </select>
          </div>
          <div class="field">
            <label><i class="fa fa-door-open" style="color:var(--accent)"></i> Advisory Section</label>
            <div class="section-wrap">
              <div class="section-row"><select id="t-section-sel" onchange="onSectionSelect('teacher')" disabled><option value="">Select grade first...</option></select></div>
              <div class="section-loading" id="t-section-loading"><i class="fa fa-spinner fa-spin"></i> Loading sections...</div>
              <span class="section-theme-badge" id="t-section-theme" style="display:none"><i class="fa fa-tag"></i> <span id="t-section-theme-text"></span></span>
              <input type="hidden" id="t-as">
              <div class="add-section-form" id="t-add-form">
                <div style="font-size:12px;font-weight:600;color:var(--accent);display:flex;align-items:center;gap:6px;"><i class="fa fa-plus-circle"></i> Add New Section</div>
                <div class="add-section-row">
                  <div style="flex:1;display:flex;flex-direction:column;gap:4px;"><label style="font-size:11.5px">Section Name <span class="req">*</span></label><input type="text" id="t-new-section-name" placeholder="e.g. Topaz"></div>
                  <div style="display:flex;gap:6px;align-self:flex-end;"><button class="btn-save-section" onclick="saveNewSection('teacher')"><i class="fa fa-check"></i> Save</button><button class="btn-cancel-section" onclick="cancelAddSection('teacher')"><i class="fa fa-xmark"></i></button></div>
                </div>
              </div>
              <div class="section-warning" id="t-section-warn" style="display:none"><i class="fa fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0"></i><span>Changing the advisory section will update this teacher's record.</span></div>
            </div>
            <span class="hint">Leave blank if not an adviser</span>
          </div>
        </div>
      </div>
      <div class="form-section">
        <div class="section-label"><i class="fa fa-key"></i> Auto-Generated Credentials</div>
        <div class="form-grid">
          <div class="field"><label><i class="fa fa-at" style="color:var(--accent)"></i> Username</label><input type="text" id="t-uname" class="gen" readonly placeholder="Enter name first..."></div>
          <div class="field"><label><i class="fa fa-lock" style="color:var(--accent)"></i> Password</label>
            <div style="display:flex;gap:8px;align-items:center;">
              <input type="text" id="t-pass" class="gen" readonly placeholder="Enter name first..." style="flex:1">
              <button type="button" id="t-reset-btn" onclick="resetPassword('teacher')" style="display:none;white-space:nowrap;padding:9px 13px;background:#7c3aed;color:#fff;border:none;border-radius:7px;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;align-items:center;gap:6px;"><i class="fa fa-arrows-rotate"></i> Reset Password</button>
            </div>
            <span class="hint" id="t-pass-hint"><i class="fa fa-circle-info"></i> Must change after first login.</span>
          </div>
        </div>
      </div>
      <div class="form-footer">
        <span class="footer-note"><span style="color:var(--danger)">*</span> required fields</span>
        <div class="btn-row">
          <button class="btn btn-ghost" onclick="resetTeacher()"><i class="fa fa-rotate-left"></i> Reset</button>
          <button class="btn btn-primary" id="t-submit-btn" onclick="submitTeacher()"><i class="fa fa-check"></i> Create Account</button>
          <button class="btn" id="t-apply-btn" onclick="applyEditTeacher()" style="display:none;background:#f59e0b;color:#fff;"><i class="fa fa-floppy-disk"></i> Apply Edit</button>
        </div>
      </div>
    </div>

    <!-- TEACHER TABLE PANEL -->
    <div class="table-panel">
      <div class="table-top">
        <div class="table-title"><i class="fa fa-chalkboard-teacher"></i> Teacher Accounts <span class="count-pill" id="t-count">0</span></div>
      </div>
      <div class="filter-wrap">
        <div class="filter-top">
          <div class="filter-search"><i class="fa fa-magnifying-glass filter-search-icon"></i><input type="text" id="tf-search" placeholder="Search by teacher name or email address..." oninput="applyTeacherFilters()"></div>
          <div class="filter-divider-v"></div>
          <div class="filter-result-pill hidden" id="tf-count-label"><span>Showing</span><span class="pill-num" id="tf-pill-num">0</span><span id="tf-pill-of">of 0</span></div>
          <div class="filter-active-tag" id="tf-active-tag"><i class="fa fa-filter"></i> Filtered</div>
        </div>
        <div class="filter-bottom">
          <div class="filter-label-group"><i class="fa fa-sliders"></i> Filter by</div>
          <div class="filter-controls">
            <div class="filter-select-wrap"><i class="fa fa-layer-group"></i><select class="filter-select" id="tf-grade" onchange="onFilterGradeChange('teacher');applyTeacherFilters()"><option value="">All Grades</option><option>Grade 7</option><option>Grade 8</option><option>Grade 9</option><option>Grade 10</option><option>Grade 11</option><option>Grade 12</option></select></div>
            <div class="filter-arrow"><i class="fa fa-chevron-right"></i></div>
            <div class="filter-select-wrap"><i class="fa fa-door-open"></i><select class="filter-select" id="tf-section" onchange="applyTeacherFilters()" disabled><option value="">All Sections</option></select></div>
            <button class="filter-clear-btn" onclick="clearTeacherFilters()"><i class="fa fa-rotate-left"></i> Reset Filters</button>
          </div>
        </div>
      </div>
      <div class="table-card">
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th><i class="fa fa-user"></i> Name</th>
              <th><i class="fa fa-tag"></i> Role</th>
              <th><i class="fa fa-envelope"></i> Email</th>
              <th><i class="fa fa-lock"></i> Password</th>
              <th><i class="fa fa-chalkboard"></i> Advisory</th>
              <th style="text-align:center;width:100px"><i class="fa fa-bolt"></i> Actions</th>
            </tr></thead>
            <tbody id="t-tbody">
              <tr><td colspan="6"><div class="empty-state"><i class="fa fa-chalkboard-teacher"></i><p>No teacher accounts yet.<br>Fill the form to get started.</p></div></td></tr>
            </tbody>
          </table>
        </div>
        <!-- TEACHER PAGINATION -->
        <div class="pag-container" id="t-pag-bar" style="display:none">
          <div class="pag-info" id="t-pag-info">—</div>
          <div style="display:flex;align-items:center;gap:8px;">
            <select class="pag-size-select" id="t-pag-size" onchange="onTeacherPageSizeChange()">
              <option value="15">15 / page</option>
              <option value="25">25 / page</option>
              <option value="50">50 / page</option>
              <option value="100">100 / page</option>
            </select>
            <div class="pag-wrap" id="t-pag-wrap"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ENROLMENT SLIDE PANEL -->
<div class="enrol-overlay" id="enrol-overlay" onclick="closeEnrolPanel()"></div>
<div class="enrol-panel" id="enrol-panel">
  <div class="enrol-header">
    <div><div class="enrol-title" id="enrol-title">Enrolment</div><div class="enrol-sub"><i class="fa fa-users"></i> <span id="enrol-sub">0 students</span></div></div>
    <button class="close-btn" onclick="closeEnrolPanel()"><i class="fa fa-xmark"></i></button>
  </div>
  <div id="enrol-adviser-card" style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border);background:#f8fafc;">
    <div id="enrol-adviser-avatar" style="width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;"></div>
    <div style="min-width:0"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:2px">Class Adviser</div><div id="enrol-adviser-name" style="font-size:13.5px;font-weight:600;color:var(--text)"></div></div>
  </div>
  <div class="enrol-body" id="enrol-body"><div class="enrol-empty"><i class="fa fa-users"></i><p>Loading...</p></div></div>
  <div class="enrol-footer"><button class="btn btn-ghost" onclick="closeEnrolPanel()"><i class="fa fa-xmark"></i> Close</button></div>
</div>

<!-- CONFIRM MODAL -->
<div class="confirm-overlay" id="confirm-overlay">
  <div class="confirm-box">
    <div class="confirm-icon" id="confirm-icon"><i class="fa fa-box-archive"></i></div>
    <div class="confirm-title" id="confirm-title">Archive Account?</div>
    <div class="confirm-msg" id="confirm-msg">This account will be archived.</div>
    <div class="confirm-btns">
      <button class="btn-cancel-c" onclick="closeConfirm()"><i class="fa fa-xmark"></i> Cancel</button>
      <button class="btn-confirm-c" id="confirm-yes-btn"><i class="fa fa-box-archive"></i> Archive</button>
    </div>
  </div>
</div>

<!-- DETAIL PANEL -->
<div class="detail-overlay" id="detail-overlay" onclick="closeDetail()"></div>
<div class="detail-panel" id="detail-panel">
  <div class="detail-header">
    <div><div class="detail-title" id="d-name">—</div><div class="detail-role" id="d-role-tag"><i class="fa fa-graduation-cap"></i> Student</div></div>
    <button class="close-btn" onclick="closeDetail()"><i class="fa fa-xmark"></i></button>
  </div>
  <div class="detail-body">
    <div class="printable">
      <div class="slip">
        <div class="slip-head"><div class="slip-logo">A</div><div><div class="slip-school">ASHI Amaya</div><div class="slip-tag">Login Credentials — Confidential</div></div></div>
        <div class="slip-body-inner">
          <div class="slip-fullname" id="sl-name">—</div>
          <div class="slip-role-tag" id="sl-role"><i class="fa fa-graduation-cap"></i> Student</div>
          <div class="slip-creds">
            <div class="slip-row"><span class="slip-key">Email</span><span class="slip-val" id="sl-email">—</span></div>
            <div class="slip-row"><span class="slip-key">Username</span><span class="slip-val" id="sl-uname">—</span></div>
            <div class="slip-row"><span class="slip-key">Password</span><span class="slip-val" id="sl-pass">—</span></div>
            <div class="slip-row" id="sl-lrn-row"><span class="slip-key">LRN</span><span class="slip-val" id="sl-lrn">—</span></div>
            <div class="slip-row" id="sl-sec-row"><span class="slip-key">Grade &amp; Section</span><span class="slip-val" id="sl-sec">—</span></div>
            <div class="slip-row" id="sl-adv-row"><span class="slip-key">Advisory Class</span><span class="slip-val" id="sl-adv">—</span></div>
          </div>
        </div>
        <div class="slip-foot"><i class="fa fa-triangle-exclamation"></i> Change password after first login. Keep confidential.</div>
      </div>
    </div>
    <div id="d-extra-info"></div>
  </div>
  <div class="detail-actions">
    <button class="btn btn-ghost" onclick="closeDetail()"><i class="fa fa-xmark"></i> Close</button>
    <button class="btn btn-print" onclick="window.print()"><i class="fa fa-print"></i> Print Slip</button>
  </div>
</div>

<script>
// ── STATE ────────────────────────────────────────────────────────
let students = [];
let teachers = [];
const sectionCache = {};

// ── PAGINATION STATE ─────────────────────────────────────────────
let sPage = 1, sPageSize = 15, sDisplayList = [];
let tPage = 1, tPageSize = 15, tDisplayList = [];

// ── INIT ─────────────────────────────────────────────────────────
(function init() {
  loadStudents();
  loadTeachers();
  const d = new Date(); d.setFullYear(d.getFullYear() - 8);
  document.getElementById('s-bday').max = d.toISOString().split('T')[0];
  const t = new Date(); t.setFullYear(t.getFullYear() - 18);
  document.getElementById('t-bday').max = t.toISOString().split('T')[0];
})();

// ── AJAX ──────────────────────────────────────────────────────────
function postAjax(data, callback) {
  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data).toString()
  }).then(r => r.json()).then(callback)
    .catch(err => { console.error(err); showToast('Network error. Try again.', 'err'); });
}

// ── TOAST ─────────────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t = document.getElementById('toast');
  t.innerHTML = `<i class="fa fa-${type==='ok'?'circle-check':'circle-xmark'}"></i> ${msg}`;
  t.className = `show ${type}`;
  setTimeout(() => { t.className = ''; }, 3500);
}

// ── TAB ───────────────────────────────────────────────────────────
function switchTab(tab, btn) {
  if (tab === 'setup') loadSetupSections();
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('panel-' + tab).classList.add('active');
}

// ── SECTIONS ──────────────────────────────────────────────────────
function gradeIdFor(prefix) { return prefix === 'student' ? 's-grade' : 't-ag'; }

function onGradeChange(prefix) {
  const grade = document.getElementById(gradeIdFor(prefix)).value;
  const sel   = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
  const hidden = document.getElementById(prefix === 'student' ? 's-section' : 't-as');
  const theme  = document.getElementById(prefix === 'student' ? 's-section-theme' : 't-section-theme');
  const addForm = document.getElementById(prefix === 'student' ? 's-add-form' : 't-add-form');
  sel.innerHTML = '<option value="">Select section...</option>';
  hidden.value = ''; theme.style.display = 'none'; addForm.classList.remove('show');
  if (!grade) { sel.disabled = true; sel.innerHTML = '<option value="">Select grade first...</option>'; return; }
  if (sectionCache[grade]) populateSectionDropdown(prefix, sectionCache[grade]);
  else fetchSections(prefix, grade);
}

function fetchSections(prefix, grade) {
  const loadEl = document.getElementById(prefix === 'student' ? 's-section-loading' : 't-section-loading');
  const sel    = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
  sel.disabled = true; loadEl.classList.add('show');
  postAjax({ action: 'get_sections', grade }, res => {
    loadEl.classList.remove('show');
    if (res.success) { sectionCache[grade] = res.sections; populateSectionDropdown(prefix, res.sections); }
    else showToast('Failed to load sections.', 'err');
  });
}

function populateSectionDropdown(prefix, sections) {
  const sel = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
  sel.disabled = false;
  sel.innerHTML = '<option value="">Select section...</option>';
  sections.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s.section_name; opt.textContent = s.section_name; opt.dataset.theme = s.theme || '';
    sel.appendChild(opt);
  });
  const addOpt = document.createElement('option'); addOpt.value = '__add__'; addOpt.textContent = '+ Add new section...';
  sel.appendChild(addOpt);
}

function onSectionSelect(prefix) {
  const sel    = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
  const hidden = document.getElementById(prefix === 'student' ? 's-section' : 't-as');
  const theme  = document.getElementById(prefix === 'student' ? 's-section-theme' : 't-section-theme');
  const themeText = document.getElementById(prefix === 'student' ? 's-section-theme-text' : 't-section-theme-text');
  const addForm = document.getElementById(prefix === 'student' ? 's-add-form' : 't-add-form');
  const warn   = document.getElementById(prefix === 'student' ? 's-section-warn' : 't-section-warn');
  const val = sel.value;
  if (val === '__add__') { addForm.classList.add('show'); hidden.value = ''; theme.style.display = 'none'; return; }
  addForm.classList.remove('show');
  if (val) {
    hidden.value = val;
    const themeVal = sel.options[sel.selectedIndex].dataset.theme || '';
    if (themeVal) { themeText.textContent = themeVal + ' Theme'; theme.style.display = ''; }
    else theme.style.display = 'none';
    const isEditing = prefix === 'student' ? sEditId !== null : tEditId !== null;
    if (isEditing && warn) warn.classList.add('show');
  } else { hidden.value = ''; theme.style.display = 'none'; if (warn) warn.classList.remove('show'); }
  const errEl = document.getElementById(prefix === 'student' ? 'e-s-section' : null);
  if (errEl) errEl.classList.remove('show');
}

function saveNewSection(prefix) {
  const grade   = document.getElementById(gradeIdFor(prefix)).value;
  const nameInp = document.getElementById(prefix === 'student' ? 's-new-section-name' : 't-new-section-name');
  const name    = nameInp.value.trim();
  if (!grade) { showToast('Please select a grade first.', 'err'); return; }
  if (!name)  { showToast('Section name cannot be empty.', 'err'); return; }
  postAjax({ action: 'add_section', grade, section_name: name }, res => {
    if (res.success) {
      showToast(`Section "${name}" added!`, 'ok');
      if (!sectionCache[grade]) sectionCache[grade] = [];
      sectionCache[grade].push({ id: res.id, section_name: res.section_name, theme: res.theme || '' });
      sectionCache[grade].sort((a,b) => a.section_name.localeCompare(b.section_name));
      populateSectionDropdown(prefix, sectionCache[grade]);
      const sel    = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
      const hidden = document.getElementById(prefix === 'student' ? 's-section' : 't-as');
      sel.value = res.section_name; hidden.value = res.section_name;
      document.getElementById(prefix === 'student' ? 's-section-theme' : 't-section-theme').style.display = 'none';
      cancelAddSection(prefix);
    } else showToast(res.message || 'Failed to add section.', 'err');
  });
}

function cancelAddSection(prefix) {
  const addForm = document.getElementById(prefix === 'student' ? 's-add-form' : 't-add-form');
  const nameInp = document.getElementById(prefix === 'student' ? 's-new-section-name' : 't-new-section-name');
  const sel     = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
  addForm.classList.remove('show'); nameInp.value = ''; sel.value = '';
}

function setSectionInDropdown(prefix, grade, sectionName, callback) {
  if (!grade || !sectionName) { if (callback) callback(); return; }
  const doSet = () => {
    const sel    = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
    const hidden = document.getElementById(prefix === 'student' ? 's-section' : 't-as');
    let found = false;
    for (let o of sel.options) { if (o.value === sectionName) { o.selected = true; found = true; break; } }
    if (!found) {
      const opt = document.createElement('option');
      opt.value = sectionName; opt.textContent = sectionName; opt.selected = true;
      sel.insertBefore(opt, sel.options[sel.options.length - 1]);
    }
    hidden.value = sectionName;
    if (callback) callback();
  };
  if (sectionCache[grade]) { populateSectionDropdown(prefix, sectionCache[grade]); doSet(); }
  else {
    const loadEl = document.getElementById(prefix === 'student' ? 's-section-loading' : 't-section-loading');
    const sel    = document.getElementById(prefix === 'student' ? 's-section-sel' : 't-section-sel');
    sel.disabled = true; loadEl.classList.add('show');
    postAjax({ action: 'get_sections', grade }, res => {
      loadEl.classList.remove('show');
      if (res.success) { sectionCache[grade] = res.sections; populateSectionDropdown(prefix, res.sections); }
      doSet();
    });
  }
}

// ── LOAD ──────────────────────────────────────────────────────────
function loadStudents() {
  postAjax({ action: 'get_students' }, res => {
    if (res.success) {
      students = res.data.map(r => ({
        dbId: r.id, lrn: r.lrn,
        name: `${r.first_name}${r.middle_name?' '+r.middle_name:''} ${r.last_name}`,
        fn: r.first_name, ln: r.last_name, mn: r.middle_name||'',
        bday: r.birthdate, contact: r.contact_number||'',
        grade: r.grade_level, section: r.section,
        gname: r.guardian_name||'', rel: r.relationship||'', gc: r.guardian_contact||'',
        email: r.email, uname: r.username, pass: r.plain_password||'—', type: 'student'
      }));
      renderStudents();
    }
  });
}

function loadTeachers() {
  postAjax({ action: 'get_teachers' }, res => {
    if (res.success) {
      teachers = res.data.map(r => ({
        dbId: r.id, name: r.fullname, bday: r.birthdate, gender: r.gender,
        phone: r.phone, addr: r.address, edu: r.education, spec: r.specialization,
        ag: r.advisory_grade||'', as: r.advisory_section||'',
        email: r.email, uname: r.email, pass: r.plain_password||'—',
        isAdv: !!(r.advisory_grade && r.advisory_section), type: 'teacher'
      }));
      renderTeachers();
    }
  });
}

// ── VALIDATION ────────────────────────────────────────────────────
function clearErr(el) {
  el.classList.remove('invalid');
  const err = el.parentElement.querySelector('.err-msg');
  if (err) err.classList.remove('show');
}
function checkRequired(id, errId) {
  const el = document.getElementById(id);
  if (!el.value.trim()) { el.classList.add('invalid'); document.getElementById(errId).classList.add('show'); return false; }
  return true;
}
function validateBday() {
  const el = document.getElementById('s-bday'); const err = document.getElementById('e-s-bday');
  if (!el.value) return;
  const cutoff = new Date(); cutoff.setFullYear(cutoff.getFullYear() - 8);
  if (new Date(el.value) > cutoff) { el.classList.add('invalid'); err.innerHTML = '<i class="fa fa-circle-xmark"></i> Student must be at least 8 years old'; err.classList.add('show'); }
  else { el.classList.remove('invalid'); err.classList.remove('show'); }
}

// ── CREDENTIAL GENERATOR ──────────────────────────────────────────
function makeCreds(firstname, existingPool) {
  const fn = firstname.trim().toLowerCase().replace(/[^a-z]/g,'');
  if (!fn) return null;
  const isDup = existingPool.some(n => n.toLowerCase() === fn);
  const suffix = isDup ? '2' : '';
  return { email: `ashi.${fn}${suffix}@gmail.com`, username: `ashi.${fn}${suffix}`, password: `${fn.charAt(0).toUpperCase()+fn.slice(1)}${suffix}@Ashi1`, isDup, fn };
}
function studentFirstNames() { return students.map(s => s.fn.toLowerCase().replace(/[^a-z]/g,'')); }
function teacherFirstNames()  { return teachers.map(t => t.name.split(' ')[0].toLowerCase().replace(/[^a-z]/g,'')); }

function genStudent() {
  const fn = document.getElementById('s-fn').value.trim();
  const c  = makeCreds(fn, studentFirstNames());
  if (!c) { ['s-email','s-uname','s-pass'].forEach(i => document.getElementById(i).value=''); return; }
  document.getElementById('s-email').value = c.email;
  document.getElementById('s-uname').value = c.username;
  document.getElementById('s-pass').value  = c.password;
  const d = document.getElementById('dup-s');
  if (c.isDup) { document.getElementById('dup-sn').textContent = fn; d.classList.add('show'); }
  else d.classList.remove('show');
}
function genTeacher() {
  const full = document.getElementById('t-fn').value.trim();
  const c    = makeCreds(full.split(' ')[0]||'', teacherFirstNames());
  if (!c) { ['t-email','t-uname','t-pass'].forEach(i => document.getElementById(i).value=''); return; }
  document.getElementById('t-email').value = c.email;
  document.getElementById('t-uname').value = c.username;
  document.getElementById('t-pass').value  = c.password;
  const d = document.getElementById('dup-t');
  if (c.isDup) { document.getElementById('dup-tn').textContent = full.split(' ')[0]; d.classList.add('show'); }
  else d.classList.remove('show');
}

// ── SUBMIT STUDENT ─────────────────────────────────────────────────
function submitStudent() {
  let valid = true;
  const lrnEl = document.getElementById('s-lrn');
  if (!lrnEl.value.trim() || lrnEl.value.length < 12) { lrnEl.classList.add('invalid'); document.getElementById('e-s-lrn').classList.add('show'); valid = false; }
  valid = checkRequired('s-fn','e-s-fn') && valid;
  valid = checkRequired('s-ln','e-s-ln') && valid;
  valid = checkRequired('s-grade','e-s-grade') && valid;
  const sectionVal = document.getElementById('s-section').value;
  if (!sectionVal) { document.getElementById('e-s-section').classList.add('show'); valid = false; }
  const bday = document.getElementById('s-bday').value;
  if (!bday) { document.getElementById('s-bday').classList.add('invalid'); document.getElementById('e-s-bday').innerHTML='<i class="fa fa-circle-xmark"></i> Birthdate is required'; document.getElementById('e-s-bday').classList.add('show'); valid = false; }
  else { const cutoff=new Date(); cutoff.setFullYear(cutoff.getFullYear()-8); if(new Date(bday)>cutoff){document.getElementById('s-bday').classList.add('invalid');document.getElementById('e-s-bday').innerHTML='<i class="fa fa-circle-xmark"></i> Student must be at least 8 years old';document.getElementById('e-s-bday').classList.add('show');valid=false;}}
  const email = document.getElementById('s-email').value;
  if (!email) { showToast('Enter a first name to generate credentials.', 'err'); return; }
  if (!valid) return;
  const btn = document.getElementById('s-submit-btn'); btn.disabled = true;
  postAjax({action:'create_student',s_lrn:document.getElementById('s-lrn').value.trim(),s_fn:document.getElementById('s-fn').value.trim(),s_ln:document.getElementById('s-ln').value.trim(),s_mn:document.getElementById('s-mn').value.trim(),s_bday:bday,s_grade:document.getElementById('s-grade').value,s_section:sectionVal,s_contact:document.getElementById('s-contact').value.trim(),s_gname:document.getElementById('s-gname').value.trim(),s_rel:document.getElementById('s-rel').value,s_gc:document.getElementById('s-gc').value.trim(),s_email:email,s_uname:document.getElementById('s-uname').value,s_pass:document.getElementById('s-pass').value}, res => {
    btn.disabled = false;
    if (res.success) {
      showToast('Student account created!', 'ok');
      const fn=document.getElementById('s-fn').value.trim(),mn=document.getElementById('s-mn').value.trim(),ln=document.getElementById('s-ln').value.trim();
      const rec={dbId:res.id,lrn:document.getElementById('s-lrn').value.trim(),name:`${fn}${mn?' '+mn:''} ${ln}`,fn,ln,mn,bday,contact:document.getElementById('s-contact').value.trim(),grade:document.getElementById('s-grade').value,section:sectionVal,gname:document.getElementById('s-gname').value.trim(),rel:document.getElementById('s-rel').value,gc:document.getElementById('s-gc').value.trim(),email,uname:document.getElementById('s-uname').value,pass:res.plain_pass,type:'student'};
      students.unshift(rec); renderStudents(); openDetail(rec); resetStudent();
    } else showToast(res.message||'Error creating account.', 'err');
  });
}

// ── SUBMIT TEACHER ─────────────────────────────────────────────────
function submitTeacher() {
  let valid = true;
  valid = checkRequired('t-fn','e-t-fn') && valid;
  valid = checkRequired('t-gender','e-t-gender') && valid;
  valid = checkRequired('t-phone','e-t-phone') && valid;
  valid = checkRequired('t-addr','e-t-addr') && valid;
  valid = checkRequired('t-edu','e-t-edu') && valid;
  valid = checkRequired('t-spec','e-t-spec') && valid;
  const bday = document.getElementById('t-bday').value;
  if (!bday) { document.getElementById('t-bday').classList.add('invalid'); document.getElementById('e-t-bday').classList.add('show'); valid = false; }
  const email = document.getElementById('t-email').value;
  if (!email) { showToast('Enter a name first to generate credentials.', 'err'); return; }
  if (!valid) return;
  const btn = document.getElementById('t-submit-btn'); btn.disabled = true;
  postAjax({action:'create_teacher',t_fn:document.getElementById('t-fn').value.trim(),t_bday:bday,t_gender:document.getElementById('t-gender').value,t_phone:document.getElementById('t-phone').value.trim(),t_addr:document.getElementById('t-addr').value.trim(),t_edu:document.getElementById('t-edu').value.trim(),t_spec:document.getElementById('t-spec').value.trim(),t_ag:document.getElementById('t-ag').value,t_as:document.getElementById('t-as').value,t_email:email,t_uname:document.getElementById('t-uname').value,t_pass:document.getElementById('t-pass').value}, res => {
    btn.disabled = false;
    if (res.success) {
      showToast('Teacher account created!', 'ok');
      const ag=document.getElementById('t-ag').value,as_=document.getElementById('t-as').value;
      const rec={dbId:res.id,name:document.getElementById('t-fn').value.trim(),bday,gender:document.getElementById('t-gender').value,phone:document.getElementById('t-phone').value.trim(),addr:document.getElementById('t-addr').value.trim(),edu:document.getElementById('t-edu').value.trim(),spec:document.getElementById('t-spec').value.trim(),ag,as:as_,email,uname:document.getElementById('t-uname').value,pass:res.plain_pass,isAdv:!!(ag&&as_),type:'teacher'};
      teachers.unshift(rec); renderTeachers(); openDetail(rec); resetTeacher();
    } else showToast(res.message||'Error creating account.', 'err');
  });
}

// ════════════════════════════════════════════════════════════════
//  PAGINATION CORE
// ════════════════════════════════════════════════════════════════

function getTotalPages(list, pageSize) { return Math.max(1, Math.ceil(list.length / pageSize)); }

function buildPagHTML(currentPage, totalPages, onPageFn) {
  if (totalPages <= 1) return '';
  let html = '';
  // Prev
  html += `<button class="pag-btn" onclick="${onPageFn}(${currentPage-1})" ${currentPage===1?'disabled':''}><i class="fa fa-chevron-left"></i></button>`;
  // Build smart range
  const pages = new Set([1, totalPages]);
  for (let p = Math.max(1, currentPage-2); p <= Math.min(totalPages, currentPage+2); p++) pages.add(p);
  const sorted = [...pages].sort((a,b)=>a-b);
  let prev = null;
  sorted.forEach(p => {
    if (prev !== null && p - prev > 1) html += '<span class="pag-ellipsis">…</span>';
    html += `<button class="pag-btn${p===currentPage?' active':''}" onclick="${onPageFn}(${p})">${p}</button>`;
    prev = p;
  });
  // Next
  html += `<button class="pag-btn" onclick="${onPageFn}(${currentPage+1})" ${currentPage===totalPages?'disabled':''}><i class="fa fa-chevron-right"></i></button>`;
  return html;
}

function updatePagBar(prefix, list, page, pageSize) {
  const bar      = document.getElementById(`${prefix}-pag-bar`);
  const infoEl   = document.getElementById(`${prefix}-pag-info`);
  const wrapEl   = document.getElementById(`${prefix}-pag-wrap`);
  const total    = list.length;
  const pages    = getTotalPages(list, pageSize);
  const start    = total === 0 ? 0 : (page-1)*pageSize + 1;
  const end      = Math.min(page*pageSize, total);
  const onPageFn = prefix === 's' ? 'goStudentPage' : 'goTeacherPage';

  if (total === 0) { bar.style.display = 'none'; return; }
  bar.style.display = 'flex';
  infoEl.innerHTML = total > 0
    ? `Showing <strong>${start}–${end}</strong> of <strong>${total}</strong> record${total!==1?'s':''}`
    : 'No records';
  wrapEl.innerHTML = buildPagHTML(page, pages, onPageFn);
}

// ── STUDENT PAGINATION ─────────────────────────────────────────────
function goStudentPage(p) {
  const total = getTotalPages(sDisplayList, sPageSize);
  sPage = Math.max(1, Math.min(p, total));
  renderStudentRows();
}
function onStudentPageSizeChange() {
  sPageSize = parseInt(document.getElementById('s-pag-size').value);
  sPage = 1;
  renderStudentRows();
}
function renderStudentRows() {
  const slice = sDisplayList.slice((sPage-1)*sPageSize, sPage*sPageSize);
  const tb = document.getElementById('s-tbody');
  if (!sDisplayList.length) {
    const isFiltered = document.getElementById('sf-search').value || document.getElementById('sf-grade').value || document.getElementById('sf-section').value;
    tb.innerHTML = `<tr><td colspan="6"><div class="empty-state"><i class="fa fa-${isFiltered?'filter':'user-graduate'}"></i><p>${isFiltered?'No students match the current filters.':'No student accounts yet.<br>Fill the form to get started.'}</p></div></td></tr>`;
    document.getElementById('s-pag-bar').style.display = 'none';
    return;
  }
  tb.innerHTML = slice.map(s => {
    const i = students.indexOf(s);
    return `<tr>
      <td class="td-mono">${s.lrn}</td>
      <td><div class="td-name">${s.name}</div></td>
      <td><div class="td-sub">${s.grade}</div><div class="td-sub">${s.section}</div></td>
      <td class="td-mono" style="font-size:11px">${s.email}</td>
      <td><span class="pass-pill">${s.pass}</span></td>
      <td><div class="actions-cell">
        <button class="action-btn btn-view" title="View" onclick="openDetail(students[${i}])"><i class="fa fa-eye"></i></button>
        <button class="action-btn btn-edit" title="Edit" onclick="editStudent(${i})"><i class="fa fa-pen"></i></button>
        <button class="action-btn btn-archive" title="Archive" onclick="confirmArchive('student',${s.dbId},'${s.name.replace(/'/g,"&#39;")}',${i})"><i class="fa fa-box-archive"></i></button>
      </div></td>
    </tr>`;
  }).join('');
  updatePagBar('s', sDisplayList, sPage, sPageSize);
}

function renderStudents() {
  document.getElementById('s-count').textContent = students.length;
  sDisplayList = [...students];
  sPage = 1;
  renderStudentRows();
}

// ── TEACHER PAGINATION ─────────────────────────────────────────────
function goTeacherPage(p) {
  const total = getTotalPages(tDisplayList, tPageSize);
  tPage = Math.max(1, Math.min(p, total));
  renderTeacherRows();
}
function onTeacherPageSizeChange() {
  tPageSize = parseInt(document.getElementById('t-pag-size').value);
  tPage = 1;
  renderTeacherRows();
}
function renderTeacherRows() {
  const slice = tDisplayList.slice((tPage-1)*tPageSize, tPage*tPageSize);
  const tb = document.getElementById('t-tbody');
  if (!tDisplayList.length) {
    const isFiltered = document.getElementById('tf-search').value || document.getElementById('tf-grade').value || document.getElementById('tf-section').value;
    tb.innerHTML = `<tr><td colspan="6"><div class="empty-state"><i class="fa fa-${isFiltered?'filter':'chalkboard-teacher'}"></i><p>${isFiltered?'No teachers match the current filters.':'No teacher accounts yet.<br>Fill the form to get started.'}</p></div></td></tr>`;
    document.getElementById('t-pag-bar').style.display = 'none';
    return;
  }
  tb.innerHTML = slice.map(t => {
    const i = teachers.indexOf(t);
    return `<tr>
      <td><div class="td-name">${t.name}</div></td>
      <td>${t.isAdv?'<span class="role-pill pill-a"><i class="fa fa-chalkboard-teacher"></i> Teacher &amp; Adviser</span>':'<span class="role-pill pill-t"><i class="fa fa-chalkboard-teacher"></i> Teacher</span>'}</td>
      <td class="td-mono" style="font-size:11px">${t.email}</td>
      <td><span class="pass-pill">${t.pass}</span></td>
      <td class="td-sub">${t.isAdv?`${t.ag} — ${t.as}`:'<span style="color:var(--muted)">—</span>'}</td>
      <td><div class="actions-cell">
        <button class="action-btn btn-view" title="View" onclick="openDetail(teachers[${i}])"><i class="fa fa-eye"></i></button>
        <button class="action-btn btn-edit" title="Edit" onclick="editTeacher(${i})"><i class="fa fa-pen"></i></button>
        <button class="action-btn btn-archive" title="Archive" onclick="confirmArchive('teacher',${t.dbId},'${t.name.replace(/'/g,"&#39;")}',${i})"><i class="fa fa-box-archive"></i></button>
      </div></td>
    </tr>`;
  }).join('');
  updatePagBar('t', tDisplayList, tPage, tPageSize);
}

function renderTeachers() {
  document.getElementById('t-count').textContent = teachers.length;
  tDisplayList = [...teachers];
  tPage = 1;
  renderTeacherRows();
}

// ── DETAIL PANEL ───────────────────────────────────────────────────
function openDetail(rec) {
  document.getElementById('d-name').textContent = rec.name;
  document.getElementById('sl-name').textContent = rec.name;
  document.getElementById('sl-email').textContent = rec.email;
  document.getElementById('sl-uname').textContent = rec.uname;
  document.getElementById('sl-pass').textContent  = rec.pass;
  const lrnRow=document.getElementById('sl-lrn-row'),secRow=document.getElementById('sl-sec-row'),advRow=document.getElementById('sl-adv-row');
  const roleTag=document.getElementById('d-role-tag'),slRole=document.getElementById('sl-role');
  if (rec.type === 'student') {
    document.getElementById('sl-lrn').textContent = rec.lrn;
    document.getElementById('sl-sec').textContent = `${rec.grade} — ${rec.section}`;
    lrnRow.style.display='flex';secRow.style.display='flex';advRow.style.display='none';
    roleTag.innerHTML='<i class="fa fa-graduation-cap"></i> Student';roleTag.className='detail-role';
    slRole.innerHTML='<i class="fa fa-graduation-cap"></i> Student';
    let extra=`<div class="info-section-title"><i class="fa fa-circle-info"></i> Additional Details</div><div class="info-grid"><div class="info-item"><div class="info-key">Birthdate</div><div class="info-val">${rec.bday||'—'}</div></div><div class="info-item"><div class="info-key">Contact</div><div class="info-val">${rec.contact||'—'}</div></div>`;
    if(rec.gname)extra+=`<div class="info-item"><div class="info-key">Guardian</div><div class="info-val">${rec.gname}</div></div><div class="info-item"><div class="info-key">Relationship</div><div class="info-val">${rec.rel||'—'}</div></div><div class="info-item span2"><div class="info-key">Guardian Contact</div><div class="info-val">${rec.gc||'—'}</div></div>`;
    extra+=`</div>`;document.getElementById('d-extra-info').innerHTML=extra;
  } else {
    lrnRow.style.display='none';secRow.style.display='none';
    if(rec.isAdv){document.getElementById('sl-adv').textContent=`${rec.ag} — ${rec.as}`;advRow.style.display='flex';roleTag.innerHTML='<i class="fa fa-chalkboard-teacher"></i> Teacher &amp; Adviser';roleTag.className='detail-role adv';slRole.innerHTML='<i class="fa fa-chalkboard-teacher"></i> Teacher &amp; Adviser';}
    else{advRow.style.display='none';roleTag.innerHTML='<i class="fa fa-chalkboard-teacher"></i> Teacher';roleTag.className='detail-role';slRole.innerHTML='<i class="fa fa-chalkboard-teacher"></i> Teacher';}
    document.getElementById('d-extra-info').innerHTML=`<div class="info-section-title"><i class="fa fa-circle-info"></i> Additional Details</div><div class="info-grid"><div class="info-item"><div class="info-key">Birthdate</div><div class="info-val">${rec.bday||'—'}</div></div><div class="info-item"><div class="info-key">Gender</div><div class="info-val">${rec.gender||'—'}</div></div><div class="info-item"><div class="info-key">Phone</div><div class="info-val">${rec.phone||'—'}</div></div><div class="info-item"><div class="info-key">Education</div><div class="info-val">${rec.edu||'—'}</div></div><div class="info-item"><div class="info-key">Specialization</div><div class="info-val">${rec.spec||'—'}</div></div><div class="info-item span2"><div class="info-key">Address</div><div class="info-val">${rec.addr||'—'}</div></div></div>`;
  }
  document.getElementById('detail-overlay').classList.add('show');
  setTimeout(() => document.getElementById('detail-panel').classList.add('open'), 10);
}
function closeDetail() {
  document.getElementById('detail-panel').classList.remove('open');
  document.getElementById('detail-overlay').classList.remove('show');
}

// ── RESET ──────────────────────────────────────────────────────────
function resetStudent() {
  ['s-fn','s-ln','s-mn','s-contact','s-lrn','s-gname','s-gc'].forEach(i=>{const el=document.getElementById(i);el.value='';el.classList.remove('invalid');});
  ['s-grade','s-rel'].forEach(i=>{const el=document.getElementById(i);el.value='';el.classList.remove('invalid');});
  document.getElementById('s-bday').value='';document.getElementById('s-bday').classList.remove('invalid');
  ['s-email','s-uname','s-pass'].forEach(i=>document.getElementById(i).value='');
  const sel=document.getElementById('s-section-sel');sel.innerHTML='<option value="">Select grade first...</option>';sel.disabled=true;
  document.getElementById('s-section').value='';document.getElementById('s-section-theme').style.display='none';
  document.getElementById('s-add-form').classList.remove('show');document.getElementById('s-section-warn').classList.remove('show');
  document.getElementById('s-new-section-name').value='';
  document.querySelectorAll('#panel-student .err-msg').forEach(e=>e.classList.remove('show'));
  document.getElementById('dup-s').classList.remove('show');
}
function resetTeacher() {
  ['t-fn','t-phone','t-addr','t-edu','t-spec'].forEach(i=>{const el=document.getElementById(i);el.value='';el.classList.remove('invalid');});
  ['t-gender','t-ag'].forEach(i=>{const el=document.getElementById(i);el.value='';el.classList.remove('invalid');});
  document.getElementById('t-bday').value='';document.getElementById('t-bday').classList.remove('invalid');
  ['t-email','t-uname','t-pass'].forEach(i=>document.getElementById(i).value='');
  const sel=document.getElementById('t-section-sel');sel.innerHTML='<option value="">Select grade first...</option>';sel.disabled=true;
  document.getElementById('t-as').value='';document.getElementById('t-section-theme').style.display='none';
  document.getElementById('t-add-form').classList.remove('show');document.getElementById('t-new-section-name').value='';
  document.querySelectorAll('#panel-teacher .err-msg').forEach(e=>e.classList.remove('show'));
  document.getElementById('dup-t').classList.remove('show');
}

// ── EDIT STATE ─────────────────────────────────────────────────────
let sEditId=null,sEditIdx=null,tEditId=null,tEditIdx=null;

function editStudent(idx) {
  const s=students[idx];sEditId=s.dbId;sEditIdx=idx;
  document.getElementById('s-lrn').value=s.lrn;document.getElementById('s-fn').value=s.fn;
  document.getElementById('s-ln').value=s.ln;document.getElementById('s-mn').value=s.mn||'';
  document.getElementById('s-bday').value=s.bday;document.getElementById('s-contact').value=s.contact||'';
  document.getElementById('s-gname').value=s.gname||'';document.getElementById('s-rel').value=s.rel||'';
  document.getElementById('s-gc').value=s.gc||'';document.getElementById('s-email').value=s.email;
  document.getElementById('s-uname').value=s.uname;
  document.getElementById('s-grade').value=s.grade;setSectionInDropdown('student',s.grade,s.section);
  const fn=s.fn.replace(/[^a-zA-Z]/g,'');const cap=fn.charAt(0).toUpperCase()+fn.slice(1).toLowerCase();
  document.getElementById('s-pass').value=s.pass&&s.pass!=='(hidden)'?s.pass:`${cap}@Ashi1`;
  document.getElementById('s-reset-btn').style.display='none';
  document.getElementById('s-pass-hint').innerHTML='<i class="fa fa-circle-info"></i> Student must change this after first login.';
  document.getElementById('s-edit-name').textContent=s.name;document.getElementById('s-edit-banner').classList.add('show');
  document.getElementById('s-submit-btn').style.display='none';document.getElementById('s-apply-btn').style.display='';
  document.querySelector('#panel-student .form-card').scrollIntoView({behavior:'smooth',block:'start'});
}
function cancelEditStudent() {
  sEditId=null;sEditIdx=null;document.getElementById('s-edit-banner').classList.remove('show');
  document.getElementById('s-submit-btn').style.display='';document.getElementById('s-apply-btn').style.display='none';
  document.getElementById('s-reset-btn').style.display='none';
  document.getElementById('s-pass-hint').innerHTML='<i class="fa fa-circle-info"></i> Student must change this after first login.';
  resetStudent();
}
function applyEditStudent() {
  if(!sEditId)return;let valid=true;
  const lrnEl=document.getElementById('s-lrn');
  if(!lrnEl.value.trim()||lrnEl.value.length<12){lrnEl.classList.add('invalid');document.getElementById('e-s-lrn').classList.add('show');valid=false;}
  valid=checkRequired('s-fn','e-s-fn')&&valid;valid=checkRequired('s-ln','e-s-ln')&&valid;valid=checkRequired('s-grade','e-s-grade')&&valid;
  const sectionVal=document.getElementById('s-section').value;
  if(!sectionVal){document.getElementById('e-s-section').classList.add('show');valid=false;}
  const bday=document.getElementById('s-bday').value;
  if(!bday){document.getElementById('s-bday').classList.add('invalid');document.getElementById('e-s-bday').innerHTML='<i class="fa fa-circle-xmark"></i> Birthdate is required';document.getElementById('e-s-bday').classList.add('show');valid=false;}
  else{const cutoff=new Date();cutoff.setFullYear(cutoff.getFullYear()-8);if(new Date(bday)>cutoff){document.getElementById('s-bday').classList.add('invalid');document.getElementById('e-s-bday').innerHTML='<i class="fa fa-circle-xmark"></i> Student must be at least 8 years old';document.getElementById('e-s-bday').classList.add('show');valid=false;}}
  if(!valid)return;
  const btn=document.getElementById('s-apply-btn');btn.disabled=true;
  postAjax({action:'update_student',id:sEditId,s_lrn:document.getElementById('s-lrn').value.trim(),s_fn:document.getElementById('s-fn').value.trim(),s_ln:document.getElementById('s-ln').value.trim(),s_mn:document.getElementById('s-mn').value.trim(),s_bday:bday,s_grade:document.getElementById('s-grade').value,s_section:sectionVal,s_contact:document.getElementById('s-contact').value.trim(),s_gname:document.getElementById('s-gname').value.trim(),s_rel:document.getElementById('s-rel').value,s_gc:document.getElementById('s-gc').value.trim()},res=>{
    btn.disabled=false;
    if(res.success){
      if(res.section_changed)showToast('Student updated. Section was changed.','ok');else showToast('Student updated successfully!','ok');
      const fn=document.getElementById('s-fn').value.trim(),mn=document.getElementById('s-mn').value.trim(),ln=document.getElementById('s-ln').value.trim();
      students[sEditIdx]={...students[sEditIdx],lrn:document.getElementById('s-lrn').value.trim(),fn,mn,ln,name:`${fn}${mn?' '+mn:''} ${ln}`,bday,grade:document.getElementById('s-grade').value,section:sectionVal,contact:document.getElementById('s-contact').value.trim(),gname:document.getElementById('s-gname').value.trim(),rel:document.getElementById('s-rel').value,gc:document.getElementById('s-gc').value.trim()};
      renderStudents();cancelEditStudent();
    }else showToast(res.message||'Update failed.','err');
  });
}

function editTeacher(idx) {
  const t=teachers[idx];tEditId=t.dbId;tEditIdx=idx;
  document.getElementById('t-fn').value=t.name;document.getElementById('t-bday').value=t.bday;
  document.getElementById('t-gender').value=t.gender;document.getElementById('t-phone').value=t.phone;
  document.getElementById('t-addr').value=t.addr;document.getElementById('t-edu').value=t.edu;
  document.getElementById('t-spec').value=t.spec;document.getElementById('t-ag').value=t.ag||'';
  document.getElementById('t-email').value=t.email;document.getElementById('t-uname').value=t.uname;
  if(t.ag&&t.as)setSectionInDropdown('teacher',t.ag,t.as);
  const tFn=t.name.split(' ')[0].replace(/[^a-zA-Z]/g,'');const tCap=tFn.charAt(0).toUpperCase()+tFn.slice(1).toLowerCase();
  document.getElementById('t-pass').value=t.pass&&t.pass!=='(hidden)'?t.pass:`${tCap}@Ashi1`;
  document.getElementById('t-reset-btn').style.display='none';
  document.getElementById('t-pass-hint').innerHTML='<i class="fa fa-circle-info"></i> Must change after first login.';
  document.getElementById('t-edit-name').textContent=t.name;document.getElementById('t-edit-banner').classList.add('show');
  document.getElementById('t-submit-btn').style.display='none';document.getElementById('t-apply-btn').style.display='';
  document.querySelector('#panel-teacher .form-card').scrollIntoView({behavior:'smooth',block:'start'});
}
function cancelEditTeacher() {
  tEditId=null;tEditIdx=null;document.getElementById('t-edit-banner').classList.remove('show');
  document.getElementById('t-submit-btn').style.display='';document.getElementById('t-apply-btn').style.display='none';
  document.getElementById('t-reset-btn').style.display='none';
  document.getElementById('t-pass-hint').innerHTML='<i class="fa fa-circle-info"></i> Must change after first login.';
  resetTeacher();
}
function applyEditTeacher() {
  if(!tEditId)return;let valid=true;
  valid=checkRequired('t-fn','e-t-fn')&&valid;valid=checkRequired('t-gender','e-t-gender')&&valid;
  valid=checkRequired('t-phone','e-t-phone')&&valid;valid=checkRequired('t-addr','e-t-addr')&&valid;
  valid=checkRequired('t-edu','e-t-edu')&&valid;valid=checkRequired('t-spec','e-t-spec')&&valid;
  const bday=document.getElementById('t-bday').value;
  if(!bday){document.getElementById('t-bday').classList.add('invalid');document.getElementById('e-t-bday').classList.add('show');valid=false;}
  if(!valid)return;
  const btn=document.getElementById('t-apply-btn');btn.disabled=true;
  postAjax({action:'update_teacher',id:tEditId,t_fn:document.getElementById('t-fn').value.trim(),t_bday:bday,t_gender:document.getElementById('t-gender').value,t_phone:document.getElementById('t-phone').value.trim(),t_addr:document.getElementById('t-addr').value.trim(),t_edu:document.getElementById('t-edu').value.trim(),t_spec:document.getElementById('t-spec').value.trim(),t_ag:document.getElementById('t-ag').value,t_as:document.getElementById('t-as').value},res=>{
    btn.disabled=false;
    if(res.success){
      showToast('Teacher updated successfully!','ok');
      const ag=document.getElementById('t-ag').value,as_=document.getElementById('t-as').value;
      teachers[tEditIdx]={...teachers[tEditIdx],name:document.getElementById('t-fn').value.trim(),bday,gender:document.getElementById('t-gender').value,phone:document.getElementById('t-phone').value.trim(),addr:document.getElementById('t-addr').value.trim(),edu:document.getElementById('t-edu').value.trim(),spec:document.getElementById('t-spec').value.trim(),ag,as:as_,isAdv:!!(ag&&as_)};
      renderTeachers();cancelEditTeacher();
    }else showToast(res.message||'Update failed.','err');
  });
}

// ── RESET PASSWORD ─────────────────────────────────────────────────
function resetPassword(type) {
  const isStudent=type==='student';const editId=isStudent?sEditId:tEditId;const editIdx=isStudent?sEditIdx:tEditIdx;
  if(!editId)return;
  const nameVal=isStudent?document.getElementById('s-fn').value.trim():document.getElementById('t-fn').value.trim().split(' ')[0];
  const fn=nameVal.replace(/[^a-zA-Z]/g,'');const cap=fn.charAt(0).toUpperCase()+fn.slice(1).toLowerCase();
  const newPass=`${cap}@Ashi1`;const p=type[0];
  const btn=document.getElementById(`${p}-reset-btn`);btn.disabled=true;btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Resetting...';
  postAjax({action:'reset_password',type,id:editId,new_pass:newPass},res=>{
    btn.disabled=false;btn.innerHTML='<i class="fa fa-arrows-rotate"></i> Reset Password';
    if(res.success){
      document.getElementById(`${p}-pass`).value=res.plain_pass;
      document.getElementById(`${p}-pass-hint`).innerHTML='<i class="fa fa-circle-check" style="color:#16a34a"></i> <span style="color:#16a34a">Password reset! Give it to the user.</span>';
      if(isStudent)students[editIdx].pass=res.plain_pass;else teachers[editIdx].pass=res.plain_pass;
      showToast('Password reset successfully!','ok');
    }else showToast(res.message||'Reset failed.','err');
  });
}

// ── ARCHIVE ────────────────────────────────────────────────────────
let _archivePending=null;
function confirmArchive(type,dbId,name,idx) {
  _archivePending={type,dbId,idx};
  document.getElementById('confirm-msg').textContent=`"${name}" will be archived and can no longer log in. This can only be undone from the database.`;
  const icon=document.getElementById('confirm-icon');icon.className='confirm-icon';icon.innerHTML='<i class="fa fa-box-archive"></i>';
  document.getElementById('confirm-title').textContent='Archive Account?';
  const yesBtn=document.getElementById('confirm-yes-btn');yesBtn.style.background='';yesBtn.innerHTML='<i class="fa fa-box-archive"></i> Archive';
  document.getElementById('confirm-overlay').classList.add('show');
  document.getElementById('confirm-yes-btn').onclick=doArchive;
}
function closeConfirm() { document.getElementById('confirm-overlay').classList.remove('show');_archivePending=null; }
function doArchive() {
  if(!_archivePending)return;const{type,dbId,idx}=_archivePending;closeConfirm();
  postAjax({action:`archive_${type}`,id:dbId},res=>{
    if(res.success){
      showToast(`${type==='student'?'Student':'Teacher'} archived.`,'ok');
      if(type==='student'){students.splice(idx,1);renderStudents();}else{teachers.splice(idx,1);renderTeachers();}
      closeDetail();
    }else showToast(res.message||'Archive failed.','err');
  });
}

// ════════════════════════════════════════════════════════════════
//  FILTER SYSTEM  (resets to page 1 on filter change)
// ════════════════════════════════════════════════════════════════
function onFilterGradeChange(prefix) {
  const isStudent=prefix==='student';
  const gradeEl=document.getElementById(isStudent?'sf-grade':'tf-grade');
  const secEl  =document.getElementById(isStudent?'sf-section':'tf-section');
  secEl.innerHTML='<option value="">All Sections</option>';secEl.disabled=true;
  const grade=gradeEl.value;if(!grade)return;
  const populate=(sections)=>{sections.forEach(s=>{const o=document.createElement('option');o.value=s.section_name;o.textContent=s.section_name;secEl.appendChild(o);});secEl.disabled=false;};
  if(sectionCache[grade])populate(sectionCache[grade]);
  else postAjax({action:'get_sections',grade},res=>{if(res.success){sectionCache[grade]=res.sections;populate(res.sections);}});
}

function applyStudentFilters() {
  const search=document.getElementById('sf-search').value.trim().toLowerCase();
  const grade =document.getElementById('sf-grade').value;
  const section=document.getElementById('sf-section').value;
  sDisplayList=students.filter(s=>{
    const ms=!search||[s.name,s.lrn,s.email,s.section].some(v=>v&&v.toLowerCase().includes(search));
    return ms&&(!grade||s.grade===grade)&&(!section||s.section===section);
  });
  updateFilterCount('student',sDisplayList.length,students.length);
  markFilterActive('sf-grade');markFilterActive('sf-section');
  sPage=1;renderStudentRows();
}

function applyTeacherFilters() {
  const search=document.getElementById('tf-search').value.trim().toLowerCase();
  const grade =document.getElementById('tf-grade').value;
  const section=document.getElementById('tf-section').value;
  tDisplayList=teachers.filter(t=>{
    const ms=!search||[t.name,t.email,t.ag,t.as].some(v=>v&&v.toLowerCase().includes(search));
    return ms&&(!grade||t.ag===grade)&&(!section||t.as===section);
  });
  updateFilterCount('teacher',tDisplayList.length,teachers.length);
  markFilterActive('tf-grade');markFilterActive('tf-section');
  tPage=1;renderTeacherRows();
}

function clearStudentFilters() {
  document.getElementById('sf-search').value='';
  const gEl=document.getElementById('sf-grade');gEl.value='';gEl.classList.remove('active');
  const sEl=document.getElementById('sf-section');sEl.innerHTML='<option value="">All Sections</option>';sEl.disabled=true;sEl.classList.remove('active');
  document.getElementById('sf-count-label').classList.add('hidden');document.getElementById('sf-active-tag').classList.remove('show');
  renderStudents();
}
function clearTeacherFilters() {
  document.getElementById('tf-search').value='';
  const gEl=document.getElementById('tf-grade');gEl.value='';gEl.classList.remove('active');
  const sEl=document.getElementById('tf-section');sEl.innerHTML='<option value="">All Sections</option>';sEl.disabled=true;sEl.classList.remove('active');
  document.getElementById('tf-count-label').classList.add('hidden');document.getElementById('tf-active-tag').classList.remove('show');
  renderTeachers();
}

function updateFilterCount(prefix,shown,total) {
  const isStudent=prefix==='student';
  const pillEl=document.getElementById(isStudent?'sf-count-label':'tf-count-label');
  const numEl =document.getElementById(isStudent?'sf-pill-num':'tf-pill-num');
  const ofEl  =document.getElementById(isStudent?'sf-pill-of':'tf-pill-of');
  const tagEl =document.getElementById(isStudent?'sf-active-tag':'tf-active-tag');
  if(shown===total){pillEl.classList.add('hidden');tagEl.classList.remove('show');}
  else{numEl.textContent=shown;ofEl.textContent=`of ${total}`;pillEl.classList.remove('hidden');tagEl.classList.add('show');}
}
function markFilterActive(selId) {
  const el=document.getElementById(selId);if(!el)return;el.classList.toggle('active',el.value!=='');
}

// ════════════════════════════════════════════════════════════════
//  GRADE & SECTION SETUP
// ════════════════════════════════════════════════════════════════
let setupSections=[];
const GRADE_ORDER=['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'];

function loadSetupSections() {
  postAjax({action:'get_all_sections'},res=>{
    if(res.success){setupSections=res.sections;renderSetupList();updateSetupStats();}
  });
}

function renderSetupList() {
  const container=document.getElementById('setup-section-list');
  const grouped={};GRADE_ORDER.forEach(g=>{grouped[g]=[];});
  setupSections.forEach(s=>{if(grouped[s.grade_level]!==undefined)grouped[s.grade_level].push(s);});
  if(!setupSections.length){container.innerHTML=`<div class="setup-empty"><i class="fa fa-layer-group"></i><p>No sections added yet.<br>Use the form on the left to get started.</p></div>`;return;}
  let html='<div class="grade-grid">';
  GRADE_ORDER.forEach(grade=>{
    const secs=grouped[grade];if(!secs.length)return;
    const totalStudents=secs.reduce((a,s)=>a+parseInt(s.student_count||0),0);
    html+=`<div class="grade-group" id="grade-group-${grade.replace(' ','-')}"><div class="grade-group-header"><div class="grade-group-title"><i class="fa fa-layer-group"></i> ${grade}</div><div class="grade-group-meta">${secs.length} section${secs.length!==1?'s':''} &nbsp;·&nbsp; ${totalStudents} student${totalStudents!==1?'s':''}</div></div><div class="grade-group-body">${secs.map(s=>renderSectionRow(s)).join('')}</div></div>`;
  });
  html+='</div>';container.innerHTML=html;
}

function renderSectionRow(s) {
  const count=parseInt(s.student_count||0),hasStuds=count>0,adviser=s.adviser_name||'';
  const adviserHTML=adviser?`<span class="section-adviser assigned" title="Adviser: ${adviser}"><i class="fa fa-chalkboard-teacher"></i> ${adviser}</span>`:`<span class="section-adviser unassigned"><i class="fa fa-user-slash"></i> No Adviser</span>`;
  return `<div class="section-row-item" id="sec-row-${s.id}">
    <div class="section-name-display" id="sec-display-${s.id}">${s.section_name}</div>
    <span class="section-dash" id="sec-dash-${s.id}">—</span>
    <span class="section-count-num${hasStuds?' has-students':''}" id="sec-count-${s.id}">${count}</span>
    <span id="sec-adviser-${s.id}">${adviserHTML}</span>
    <div class="section-edit-inline" id="sec-edit-${s.id}">
      <input type="text" id="sec-edit-input-${s.id}" value="${s.section_name}" placeholder="Section name"
             onkeydown="if(event.key==='Enter')confirmSaveEdit(${s.id},'${s.grade_level}',${hasStuds},${count});if(event.key==='Escape')cancelEditRow(${s.id})">
      <button class="sec-btn sec-btn-save" onclick="confirmSaveEdit(${s.id},'${s.grade_level}',${hasStuds},${count})"><i class="fa fa-check"></i></button>
      <button class="sec-btn sec-btn-cancel-edit" onclick="cancelEditRow(${s.id})"><i class="fa fa-xmark"></i></button>
    </div>
    <div class="section-actions">
      <button class="sec-btn sec-btn-view" onclick="openEnrolPanel('${s.grade_level}','${s.section_name}',${count})"><i class="fa fa-eye"></i></button>
      <button class="sec-btn sec-btn-edit" onclick="startEditRow(${s.id})"><i class="fa fa-pen"></i></button>
      <button class="sec-btn sec-btn-remove" onclick="confirmRemoveSection(${s.id},'${s.grade_level}','${s.section_name}',${count})"><i class="fa fa-trash"></i></button>
    </div>
  </div>`;
}

function updateSetupStats() {
  document.getElementById('stat-total-sections').textContent=setupSections.length;
  document.getElementById('stat-total-students').textContent=setupSections.reduce((a,s)=>a+parseInt(s.student_count||0),0);
}

function addSetupSection() {
  const grade=document.getElementById('setup-grade').value;
  const name=document.getElementById('setup-section-name').value.trim();
  const theme=document.getElementById('setup-theme').value.trim();
  if(!grade){showToast('Please select a grade level.','err');return;}
  if(!name){showToast('Please enter a section name.','err');return;}
  const btn=document.getElementById('setup-add-btn');btn.disabled=true;
  postAjax({action:'add_section',grade,section_name:name,theme},res=>{
    btn.disabled=false;
    if(res.success){
      showToast(`Section "${name}" added to ${grade}!`,'ok');
      document.getElementById('setup-section-name').value='';document.getElementById('setup-theme').value='';
      setupSections.push({id:res.id,grade_level:grade,section_name:res.section_name,theme:res.theme||'',student_count:0});
      setupSections.sort((a,b)=>GRADE_ORDER.indexOf(a.grade_level)-GRADE_ORDER.indexOf(b.grade_level)||a.section_name.localeCompare(b.section_name));
      renderSetupList();updateSetupStats();
      if(!sectionCache[grade])sectionCache[grade]=[];
      sectionCache[grade].push({id:res.id,section_name:res.section_name,theme:res.theme||''});
      sectionCache[grade].sort((a,b)=>a.section_name.localeCompare(b.section_name));
    }else showToast(res.message||'Failed to add section.','err');
  });
}

function startEditRow(id) {
  ['display','dash','count','adviser'].forEach(k=>document.getElementById(`sec-${k}-${id}`).style.display='none');
  document.getElementById(`sec-edit-${id}`).classList.add('show');
  document.getElementById(`sec-edit-input-${id}`).focus();document.getElementById(`sec-edit-input-${id}`).select();
}
function cancelEditRow(id) {
  ['display','dash','count','adviser'].forEach(k=>document.getElementById(`sec-${k}-${id}`).style.display='');
  document.getElementById(`sec-edit-${id}`).classList.remove('show');
  const s=setupSections.find(x=>x.id==id);if(s)document.getElementById(`sec-edit-input-${id}`).value=s.section_name;
}
function confirmSaveEdit(id,grade,hasStudents,count) {
  const newName=document.getElementById(`sec-edit-input-${id}`).value.trim();
  const s=setupSections.find(x=>x.id==id);
  if(!newName){showToast('Section name cannot be empty.','err');return;}
  if(s&&newName===s.section_name){cancelEditRow(id);return;}
  if(hasStudents)showSectionConfirm('warn','Rename Section?',`This section has <strong>${count} student${count!==1?'s':''}</strong> enrolled. Renaming it will update all their records.`,()=>doSaveEdit(id,grade,newName));
  else doSaveEdit(id,grade,newName);
}
function doSaveEdit(id,grade,newName) {
  postAjax({action:'edit_section',id,grade,new_name:newName},res=>{
    if(res.success){
      showToast(`Section renamed to "${newName}".`,'ok');
      const s=setupSections.find(x=>x.id==id);const oldName=s.section_name;if(s)s.section_name=newName;
      if(sectionCache[grade]){const cached=sectionCache[grade].find(x=>x.section_name===oldName);if(cached)cached.section_name=newName;}
      renderSetupList();updateSetupStats();
      students.forEach(st=>{if(st.grade===grade&&st.section===oldName)st.section=newName;});
      renderStudents();
    }else showToast(res.message||'Rename failed.','err');
  });
}

function confirmRemoveSection(id,grade,name,count) {
  const msg=count>0?`<strong>"${name}"</strong> has <strong>${count} student${count!==1?'s':''}</strong> enrolled. They will be set to <strong>"Unassigned"</strong>. This cannot be undone easily.`:`Are you sure you want to remove <strong>"${name}"</strong> from ${grade}?`;
  showSectionConfirm('danger','Remove Section?',msg,()=>doRemoveSection(id,grade,name));
}
function doRemoveSection(id,grade,name) {
  postAjax({action:'remove_section',id,grade},res=>{
    if(res.success){
      showToast(res.students_unassigned>0?`Section removed. ${res.students_unassigned} student${res.students_unassigned!==1?'s':''} set to "Unassigned".`:'Section removed successfully.','ok');
      const idx=setupSections.findIndex(x=>x.id==id);if(idx!==-1)setupSections.splice(idx,1);
      if(sectionCache[grade])sectionCache[grade]=sectionCache[grade].filter(x=>x.section_name!==name);
      renderSetupList();updateSetupStats();
      students.forEach(st=>{if(st.grade===grade&&st.section===name)st.section='Unassigned';});
      renderStudents();
    }else showToast(res.message||'Remove failed.','err');
  });
}

let _sectionConfirmCallback=null;
function showSectionConfirm(type,title,msg,callback) {
  _sectionConfirmCallback=callback;
  const icon=document.getElementById('confirm-icon');const titleEl=document.getElementById('confirm-title');
  const msgEl=document.getElementById('confirm-msg');const yesBtn=document.getElementById('confirm-yes-btn');
  titleEl.textContent=title;msgEl.innerHTML=msg;
  if(type==='warn'){icon.className='confirm-icon confirm-warn-icon';icon.innerHTML='<i class="fa fa-triangle-exclamation"></i>';yesBtn.style.background='#d97706';yesBtn.innerHTML='<i class="fa fa-check"></i> Confirm';}
  else{icon.className='confirm-icon';icon.innerHTML='<i class="fa fa-trash"></i>';yesBtn.style.background='';yesBtn.innerHTML='<i class="fa fa-trash"></i> Remove';}
  yesBtn.onclick=()=>{closeConfirm();_sectionConfirmCallback&&_sectionConfirmCallback();};
  document.getElementById('confirm-overlay').classList.add('show');
}

function openEnrolPanel(grade,section,count) {
  const secData=setupSections.find(s=>s.grade_level===grade&&s.section_name===section);
  const adviser=secData?(secData.adviser_name||''):'';
  document.getElementById('enrol-title').textContent=section;
  document.getElementById('enrol-sub').textContent=`${grade} · ${count} student${count!==1?'s':''}`;
  document.getElementById('enrol-body').innerHTML='<div class="enrol-empty"><i class="fa fa-spinner fa-spin"></i><p>Loading students...</p></div>';
  const advCard=document.getElementById('enrol-adviser-card');
  const advAvatar=document.getElementById('enrol-adviser-avatar');
  const advName=document.getElementById('enrol-adviser-name');
  if(adviser){const parts=adviser.trim().split(' ');const initials=parts.length>=2?(parts[0][0]+parts[parts.length-1][0]).toUpperCase():adviser.slice(0,2).toUpperCase();advAvatar.textContent=initials;advAvatar.style.background='linear-gradient(135deg,#16a34a,#15803d)';advName.textContent=adviser;advName.style.color='var(--text)';}
  else{advAvatar.textContent='?';advAvatar.style.background='#cbd5e1';advName.textContent='No Adviser Assigned';advName.style.color='var(--muted)';}
  advCard.style.display='flex';
  document.getElementById('enrol-overlay').classList.add('show');
  setTimeout(()=>document.getElementById('enrol-panel').classList.add('open'),10);
  postAjax({action:'get_section_students',grade,section},res=>{
    if(!res.success){document.getElementById('enrol-body').innerHTML='<div class="enrol-empty"><i class="fa fa-circle-xmark"></i><p>Failed to load.</p></div>';return;}
    const studs=res.students;
    if(!studs.length){document.getElementById('enrol-body').innerHTML='<div class="enrol-empty"><i class="fa fa-users"></i><p>No students in this section yet.</p></div>';return;}
    document.getElementById('enrol-body').innerHTML=studs.map((st,i)=>{const fullName=`${st.first_name}${st.middle_name?' '+st.middle_name:''} ${st.last_name}`;const initials=(st.first_name[0]+st.last_name[0]).toUpperCase();return`<div class="enrol-list-item"><div class="enrol-avatar">${initials}</div><div><div class="enrol-name">${fullName}</div><div class="enrol-lrn"><i class="fa fa-hashtag" style="font-size:10px"></i> ${st.lrn||'—'}</div></div><div style="margin-left:auto;font-size:11.5px;color:var(--muted)">#${i+1}</div></div>`;}).join('');
  });
}
function closeEnrolPanel() {
  document.getElementById('enrol-panel').classList.remove('open');
  document.getElementById('enrol-overlay').classList.remove('show');
}

loadSetupSections();
</script>
</body>
</html>