<?php
session_start();
require_once '../../db_connection/ashi_dbcnnt.php';

// ── Ensure tables exist ───────────────────────────────────────────────────────

$conn->query("
    CREATE TABLE IF NOT EXISTS subject_component_weights (
        id          INT AUTO_INCREMENT PRIMARY KEY,
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
    CREATE TABLE IF NOT EXISTS grading_config (
        id          INT AUTO_INCREMENT PRIMARY KEY,
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
    CREATE TABLE IF NOT EXISTS student_qa_scores (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        student_id  INT NOT NULL,
        subject_id  INT NOT NULL,
        school_year VARCHAR(20) NOT NULL,
        quarter     TINYINT NOT NULL,
        qa_score    DECIMAL(5,2) DEFAULT NULL,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_qa (student_id, subject_id, school_year, quarter),
        INDEX idx_student (student_id),
        INDEX idx_subject (subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Subject groups table ──────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS subject_groups (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        grade_level  VARCHAR(50)  NOT NULL,
        group_name   VARCHAR(150) NOT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_group (grade_level, group_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS subject_group_members (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        group_id     INT NOT NULL,
        subject_name VARCHAR(150) NOT NULL,
        grade_level  VARCHAR(50)  NOT NULL,
        FOREIGN KEY (group_id) REFERENCES subject_groups(id) ON DELETE CASCADE,
        UNIQUE KEY unique_member (group_id, subject_name, grade_level),
        INDEX idx_group_id (group_id),
        INDEX idx_subject_name (subject_name),
        INDEX idx_grade_level (grade_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = trim($_POST['ajax_action']);

    if ($action === 'get_subjects') {
        $grade = trim($_POST['grade_level'] ?? '');
        $stmt  = $conn->prepare("SELECT DISTINCT subject_name FROM subjects WHERE grade = ? AND status = 'active' ORDER BY subject_name");
        $stmt->bind_param("s", $grade);
        $stmt->execute();
        $subjects = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_name');
        $stmt->close();
        echo json_encode(['ok' => true, 'subjects' => $subjects]);
        exit;
    }

    if ($action === 'get_subject_weights') {
        $grade   = trim($_POST['grade_level'] ?? '');
        $subject = trim($_POST['subject_name'] ?? '');
        $stmt = $conn->prepare("SELECT ww_percent, pt_percent, qa_percent, updated_at FROM subject_component_weights WHERE subject_name = ? AND grade_level = ?");
        $stmt->bind_param("ss", $subject, $grade);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            echo json_encode(['ok' => true, 'ww' => (float)$row['ww_percent'], 'pt' => (float)$row['pt_percent'], 'qa' => (float)$row['qa_percent'], 'updated_at' => $row['updated_at'], 'saved' => true]);
        } else {
            $cfg = $conn->query("SELECT * FROM grading_config WHERE id=1")->fetch_assoc();
            echo json_encode(['ok' => true, 'ww' => (float)($cfg['ww_percent'] ?? 30), 'pt' => (float)($cfg['pt_percent'] ?? 50), 'qa' => (float)($cfg['qa_percent'] ?? 20), 'saved' => false]);
        }
        exit;
    }

    if ($action === 'save_subject_weights') {
        $grade   = trim($_POST['grade_level'] ?? '');
        $subject = trim($_POST['subject_name'] ?? '');
        $ww      = (float)($_POST['ww'] ?? 0);
        $pt      = (float)($_POST['pt'] ?? 0);
        $qa      = (float)($_POST['qa'] ?? 0);
        if (round($ww + $pt + $qa, 2) != 100.00) { echo json_encode(['ok' => false, 'msg' => 'Weights must total 100%.']); exit; }

        $groupMembers = [];
        $stmtGrp = $conn->prepare("
            SELECT sgm.subject_name FROM subject_group_members sgm
            JOIN subject_groups sg ON sg.id = sgm.group_id
            WHERE sg.grade_level = ? AND sgm.group_id = (
                SELECT group_id FROM subject_group_members sgm2
                JOIN subject_groups sg2 ON sg2.id = sgm2.group_id
                WHERE sg2.grade_level = ? AND sgm2.subject_name = ?
                LIMIT 1
            )
        ");
        $stmtGrp->bind_param("sss", $grade, $grade, $subject);
        $stmtGrp->execute();
        $grpRes = $stmtGrp->get_result();
        while ($row = $grpRes->fetch_assoc()) { $groupMembers[] = $row['subject_name']; }
        $stmtGrp->close();

        $targets = count($groupMembers) > 0 ? $groupMembers : [$subject];

        $stmt = $conn->prepare("
            INSERT INTO subject_component_weights (subject_name, grade_level, ww_percent, pt_percent, qa_percent)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ww_percent=VALUES(ww_percent), pt_percent=VALUES(pt_percent), qa_percent=VALUES(qa_percent), updated_at=CURRENT_TIMESTAMP
        ");
        $allOk = true;
        foreach ($targets as $t) {
            $stmt->bind_param("ssddd", $t, $grade, $ww, $pt, $qa);
            if (!$stmt->execute()) { $allOk = false; }
        }
        $stmt->close();

        if ($allOk) {
            $msg = count($targets) > 1
                ? 'Weights saved for group (' . implode(', ', $targets) . ').'
                : 'Weights saved for ' . $subject . '.';
            echo json_encode(['ok' => true, 'msg' => $msg, 'synced_subjects' => $targets]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'get_all_weights') {
        $grade = trim($_POST['grade_level'] ?? '');
        $stmt  = $conn->prepare("SELECT subject_name, ww_percent, pt_percent, qa_percent, updated_at FROM subject_component_weights WHERE grade_level = ? ORDER BY subject_name");
        $stmt->bind_param("s", $grade);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['ok' => true, 'weights' => $rows]);
        exit;
    }

    // ── Group CRUD ──────────────────────────────────────────────────────────

    if ($action === 'get_groups') {
        $grade = trim($_POST['grade_level'] ?? '');
        $stmt  = $conn->prepare("SELECT sg.id, sg.group_name, GROUP_CONCAT(sgm.subject_name ORDER BY sgm.subject_name SEPARATOR '||') AS members FROM subject_groups sg LEFT JOIN subject_group_members sgm ON sgm.group_id = sg.id WHERE sg.grade_level = ? GROUP BY sg.id ORDER BY sg.group_name");
        $stmt->bind_param("s", $grade);
        $stmt->execute();
        $rows   = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $groups = array_map(function($r) {
            return ['id' => (int)$r['id'], 'group_name' => $r['group_name'], 'members' => $r['members'] ? explode('||', $r['members']) : []];
        }, $rows);
        echo json_encode(['ok' => true, 'groups' => $groups]);
        exit;
    }

    if ($action === 'save_group') {
        $grade      = trim($_POST['grade_level'] ?? '');
        $groupName  = trim($_POST['group_name'] ?? '');
        $members    = json_decode($_POST['members'] ?? '[]', true);
        $groupId    = (int)($_POST['group_id'] ?? 0);

        if (!$groupName || empty($members)) { echo json_encode(['ok' => false, 'msg' => 'Group name and at least one subject required.']); exit; }

        if ($groupId > 0) {
            $stmt = $conn->prepare("UPDATE subject_groups SET group_name=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND grade_level=?");
            $stmt->bind_param("sis", $groupName, $groupId, $grade);
            $stmt->execute();
            $stmt->close();
            $conn->query("DELETE FROM subject_group_members WHERE group_id=$groupId");
        } else {
            $stmt = $conn->prepare("INSERT INTO subject_groups (grade_level, group_name) VALUES (?,?) ON DUPLICATE KEY UPDATE group_name=group_name");
            $stmt->bind_param("ss", $grade, $groupName);
            $stmt->execute();
            $groupId = $conn->insert_id ?: (function() use ($conn, $grade, $groupName) {
                $r = $conn->query("SELECT id FROM subject_groups WHERE grade_level='".mysqli_real_escape_string($conn,$grade)."' AND group_name='".mysqli_real_escape_string($conn,$groupName)."' LIMIT 1")->fetch_assoc();
                return $r ? (int)$r['id'] : 0;
            })();
            $stmt->close();
        }

        if (!$groupId) { echo json_encode(['ok' => false, 'msg' => 'Could not create group.']); exit; }

        $stmt = $conn->prepare("INSERT IGNORE INTO subject_group_members (group_id, subject_name, grade_level) VALUES (?,?,?)");
        foreach ($members as $m) {
            $m = trim($m);
            $stmt->bind_param("iss", $groupId, $m, $grade);
            $stmt->execute();
        }
        $stmt->close();

        echo json_encode(['ok' => true, 'msg' => 'Group "' . $groupName . '" saved.', 'group_id' => $groupId]);
        exit;
    }

    if ($action === 'delete_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $grade   = trim($_POST['grade_level'] ?? '');
        $stmt    = $conn->prepare("DELETE FROM subject_groups WHERE id=? AND grade_level=?");
        $stmt->bind_param("is", $groupId, $grade);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => true, 'msg' => 'Group deleted.']);
        exit;
    }

    if ($action === 'remove_from_group') {
        $grade   = trim($_POST['grade_level'] ?? '');
        $subject = trim($_POST['subject_name'] ?? '');
        $stmt    = $conn->prepare("DELETE sgm FROM subject_group_members sgm JOIN subject_groups sg ON sg.id=sgm.group_id WHERE sg.grade_level=? AND sgm.subject_name=?");
        $stmt->bind_param("ss", $grade, $subject);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

$gradeRes    = $conn->query("SELECT DISTINCT grade FROM subjects WHERE status = 'active' ORDER BY grade");
$gradeLevels = $gradeRes ? array_column($gradeRes->fetch_all(MYSQLI_ASSOC), 'grade') : [];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grading Configuration — Ashi Amaya</title>
<link rel="icon" href="../../img/ashi.jpg" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400;500;600&family=Geist:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --ink:      #0a0f1e;
    --ink2:     #1e293b;
    --ink3:     #475569;
    --ink4:     #94a3b8;
    --ink5:     #cbd5e1;
    --line:     #e8edf5;
    --line2:    #f1f5f9;
    --surface:  #f7f9fc;
    --white:    #ffffff;
    --ww:       #3b82f6;
    --ww-light: #eff6ff;
    --ww-mid:   #bfdbfe;
    --pt:       #8b5cf6;
    --pt-light: #f5f3ff;
    --pt-mid:   #ddd6fe;
    --qa:       #f59e0b;
    --qa-light: #fffbeb;
    --qa-mid:   #fde68a;
    --ok:       #10b981;
    --ok-bg:    #ecfdf5;
    --ok-line:  #a7f3d0;
    --ok-text:  #065f46;
    --err:      #ef4444;
    --err-bg:   #fef2f2;
    --err-line: #fecaca;
    --err-text: #991b1b;
    --grp:      #0ea5e9;
    --grp-bg:   #f0f9ff;
    --grp-line: #bae6fd;
    --grp-text: #0c4a6e;
    --r:        8px;
    --r-lg:     12px;
    --mono:     'Geist Mono', monospace;
    --sans:     'Geist', sans-serif;
}

html, body { height: 100%; font-family: var(--sans); background: var(--surface); color: var(--ink); font-size: 13px; line-height: 1.5; overflow: hidden; }
.page { height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

.alert-strip { flex-shrink: 0; display: none; align-items: center; gap: 10px; padding: 10px 24px; font-size: 12px; font-weight: 500; }
.alert-strip.show    { display: flex; }
.alert-strip.success { background: var(--ok-bg);  border-bottom: 1px solid var(--ok-line);  color: var(--ok-text); }
.alert-strip.error   { background: var(--err-bg); border-bottom: 1px solid var(--err-line); color: var(--err-text); }

.body { flex: 1; display: grid; grid-template-columns: 340px 1fr; overflow: hidden; min-height: 0; }

/* ═══ LEFT ═══ */
.left { background: var(--white); border-right: 1px solid var(--line); display: flex; flex-direction: column; overflow: hidden; }
.left-head { padding: 20px 22px 16px; border-bottom: 1px solid var(--line2); flex-shrink: 0; }
.lh-label { font-size: 10px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); margin-bottom: 5px; }
.lh-title { font-size: 17px; font-weight: 600; color: var(--ink); letter-spacing: -.3px; margin-bottom: 3px; }
.lh-sub   { font-size: 11px; color: var(--ink4); line-height: 1.5; }

.left-grade-select-wrap { padding: 14px 22px 10px; border-bottom: 1px solid var(--line2); flex-shrink: 0; display: flex; flex-direction: column; gap: 5px; }
.left-grade-select-wrap label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); }
.left-grade-select { padding: 8px 10px; border: 1px solid var(--line); border-radius: var(--r); font-family: var(--sans); font-size: 12px; color: var(--ink); background: var(--white); cursor: pointer; width: 100%; }
.left-grade-select:focus { outline: none; border-color: var(--ink3); }

/* ── Group mode toolbar ── */
.group-toolbar { padding: 8px 22px 10px; border-bottom: 1px solid var(--line2); flex-shrink: 0; display: none; flex-direction: column; gap: 6px; }
.group-toolbar.visible { display: flex; }
.btn-group-toggle { display: flex; align-items: center; gap: 7px; padding: 7px 11px; border: 1px solid var(--line); border-radius: var(--r); font-family: var(--sans); font-size: 11px; font-weight: 500; color: var(--ink3); background: var(--white); cursor: pointer; transition: all .15s; width: 100%; }
.btn-group-toggle:hover { background: var(--surface); border-color: var(--ink5); }
.btn-group-toggle.active { background: var(--grp-bg); border-color: var(--grp-line); color: var(--grp-text); }
.btn-group-toggle .toggle-icon { font-size: 13px; }
.btn-group-toggle .toggle-label { flex: 1; text-align: left; }
.btn-group-toggle .toggle-badge { font-size: 10px; font-family: var(--mono); font-weight: 600; padding: 1px 6px; border-radius: 20px; background: var(--grp-bg); color: var(--grp); border: 1px solid var(--grp-line); display: none; }
.btn-group-toggle.active .toggle-badge { display: inline-flex; }

/* Group name input row (shown in group mode) */
.group-name-row { display: none; flex-direction: column; gap: 4px; }
.group-name-row.visible { display: flex; }
.group-name-row label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); }
.group-name-wrap { display: flex; gap: 6px; }
.group-name-input { flex: 1; padding: 7px 9px; border: 1px solid var(--line); border-radius: var(--r); font-family: var(--sans); font-size: 12px; color: var(--ink); background: var(--white); }
.group-name-input:focus { outline: none; border-color: var(--grp); }
.group-name-input::placeholder { color: var(--ink5); }
.btn-create-group { display: flex; align-items: center; gap: 4px; padding: 7px 11px; background: var(--ink); color: #fff; border: none; border-radius: var(--r); font-family: var(--sans); font-size: 11px; font-weight: 500; cursor: pointer; flex-shrink: 0; transition: background .12s; white-space: nowrap; }
.btn-create-group:hover:not(:disabled) { background: var(--ink2); }
.btn-create-group:disabled { opacity: .35; cursor: not-allowed; }
.group-sel-hint { font-size: 10px; color: var(--ink4); font-family: var(--mono); }

.left-content { flex: 1; overflow-y: auto; }

/* Subject list */
.subj-list-label { padding: 12px 22px 6px; font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); display: flex; align-items: center; justify-content: space-between; }
.subj-list-badge { font-size: 10px; font-family: var(--mono); font-weight: 500; padding: 2px 7px; border-radius: 20px; background: var(--surface); border: 1px solid var(--line); color: var(--ink4); }

/* Checkbox shown in group mode */
.subj-checkbox { width: 16px; height: 16px; border-radius: 4px; border: 1.5px solid var(--ink5); background: var(--white); display: none; align-items: center; justify-content: center; flex-shrink: 0; transition: all .12s; }
.group-mode .subj-checkbox { display: flex; }
.subj-item.sel-checked .subj-checkbox { background: var(--grp); border-color: var(--grp); }
.subj-item.sel-checked .subj-checkbox::after { content: ''; display: block; width: 8px; height: 5px; border-left: 1.5px solid #fff; border-bottom: 1.5px solid #fff; transform: rotate(-45deg) translateY(-1px); }

/* Regular flat subject item */
.subj-item { display: flex; align-items: center; gap: 10px; padding: 10px 22px; border-bottom: 1px solid var(--line2); cursor: pointer; transition: background .1s; position: relative; }
.subj-item:hover { background: var(--surface); }
.subj-item.active { background: var(--ww-light); border-left: 3px solid var(--ww); padding-left: 19px; }

.subj-item-icon { width: 32px; height: 32px; border-radius: var(--r); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; font-family: var(--mono); color: #fff; flex-shrink: 0; }
.subj-item-info { flex: 1; min-width: 0; }
.subj-item-name { font-size: 12px; font-weight: 500; color: var(--ink2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.subj-item-meta { font-size: 10px; color: var(--ink4); font-family: var(--mono); margin-top: 1px; }
.subj-item-saved   { width: 6px; height: 6px; border-radius: 50%; background: var(--ok); flex-shrink: 0; }
.subj-item-unsaved { width: 6px; height: 6px; border-radius: 50%; background: var(--line); flex-shrink: 0; }

/* ── Accordion group styles ── */
.grp-accordion { border-bottom: 1px solid var(--line2); }

/* The group header row — looks like a subject item */
.grp-accordion-header {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 22px; cursor: pointer;
    transition: background .1s; position: relative;
    border-left: 3px solid transparent;
}
.grp-accordion-header:hover { background: var(--grp-bg); }
.grp-accordion-header.active {
    background: var(--grp-bg);
    border-left-color: var(--grp);
    padding-left: 19px;
}

/* Checkbox in group mode on the accordion header */
.group-mode .grp-accordion-header .subj-checkbox { display: flex; }
.grp-accordion-header.sel-checked .subj-checkbox { background: var(--grp); border-color: var(--grp); }
.grp-accordion-header.sel-checked .subj-checkbox::after {
    content: ''; display: block; width: 8px; height: 5px;
    border-left: 1.5px solid #fff; border-bottom: 1.5px solid #fff;
    transform: rotate(-45deg) translateY(-1px);
}

.grp-accordion-icon {
    width: 32px; height: 32px; border-radius: var(--r);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; font-family: var(--mono);
    color: #fff; flex-shrink: 0;
}
.grp-accordion-info { flex: 1; min-width: 0; }
.grp-accordion-name {
    font-size: 12px; font-weight: 600; color: var(--ink2);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.grp-accordion-meta { font-size: 10px; color: var(--grp); font-family: var(--mono); margin-top: 1px; }

/* Arrow chevron */
.grp-accordion-arrow {
    font-size: 9px; color: var(--ink4);
    transition: transform .2s ease;
    flex-shrink: 0; user-select: none;
}
.grp-accordion-arrow.open { transform: rotate(180deg); }

/* Delete button on accordion header */
.grp-accordion-del {
    width: 20px; height: 20px; border-radius: 4px;
    border: 1px solid var(--grp-line); background: transparent;
    color: var(--grp); font-size: 14px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .1s; flex-shrink: 0; line-height: 1;
}
.grp-accordion-del:hover { background: var(--err-bg); color: var(--err); border-color: var(--err-line); }

/* Collapsible body */
.grp-accordion-body {
    display: none;
    background: var(--grp-bg);
    border-top: 1px solid var(--grp-line);
}
.grp-accordion-body.open { display: block; }

/* Member subject items inside accordion */
.grp-member-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 22px 9px 40px;
    border-bottom: 1px solid var(--grp-line);
    cursor: pointer; transition: background .1s; position: relative;
    border-left: 3px solid transparent;
}
.grp-member-item:last-child { border-bottom: none; }
.grp-member-item:hover { background: #e0f2fe; }
.grp-member-item.active {
    background: var(--ww-light);
    border-left-color: var(--ww);
    padding-left: 37px;
}

/* Checkbox in group mode for members */
.group-mode .grp-member-item .subj-checkbox { display: flex; }
.grp-member-item.sel-checked .subj-checkbox { background: var(--grp); border-color: var(--grp); }
.grp-member-item.sel-checked .subj-checkbox::after {
    content: ''; display: block; width: 8px; height: 5px;
    border-left: 1.5px solid #fff; border-bottom: 1.5px solid #fff;
    transform: rotate(-45deg) translateY(-1px);
}

.grp-member-icon {
    width: 28px; height: 28px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; font-family: var(--mono);
    color: #fff; flex-shrink: 0;
}
.grp-member-info { flex: 1; min-width: 0; }
.grp-member-name {
    font-size: 12px; font-weight: 500; color: var(--ink2);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.grp-member-meta { font-size: 10px; color: var(--ink4); font-family: var(--mono); margin-top: 1px; }

.no-subj-msg { padding: 30px 22px; text-align: center; color: var(--ink4); font-size: 12px; line-height: 1.7; }
.no-grade-selected { padding: 30px 22px; text-align: center; color: var(--ink4); font-size: 12px; line-height: 1.7; }

/* ═══ RIGHT ═══ */
.right { background: var(--surface); display: flex; flex-direction: column; overflow: hidden; }
.right-head { flex-shrink: 0; padding: 16px 20px 14px; background: var(--white); border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.right-head-text { flex: 1; }
.rh-label { font-size: 10px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); margin-bottom: 3px; }
.rh-title { font-size: 15px; font-weight: 600; letter-spacing: -.2px; margin-bottom: 2px; }
.rh-sub   { font-size: 11px; color: var(--ink4); }
.grade-select-wrap { display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; }
.grade-select-wrap label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); }
.grade-select { padding: 7px 10px; border: 1px solid var(--line); border-radius: var(--r); font-family: var(--sans); font-size: 12px; color: var(--ink); background: var(--white); cursor: pointer; }
.grade-select:focus { outline: none; border-color: var(--ink3); }

.right-body { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; min-height: 0; }

/* Component Weight Editor */
.cw-panel { background: var(--white); border: 1px solid var(--line); border-radius: var(--r-lg); overflow: hidden; flex-shrink: 0; }
.cw-panel-head { padding: 12px 16px 10px; border-bottom: 1px solid var(--line2); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.cw-panel-label { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); }
.cw-panel-title { font-size: 14px; font-weight: 600; color: var(--ink); letter-spacing: -.2px; margin-top: 2px; }
.cw-panel-subtitle { font-size: 11px; color: var(--ink4); }
.cw-saved-tag { font-size: 10px; font-family: var(--mono); font-weight: 600; padding: 3px 9px; border-radius: 20px; background: var(--ok-bg); color: var(--ok-text); border: 1px solid var(--ok-line); display: none; }
.cw-saved-tag.show { display: inline-flex; align-items: center; gap: 4px; }
.cw-default-tag { font-size: 10px; font-family: var(--mono); font-weight: 500; padding: 3px 9px; border-radius: 20px; background: var(--surface); color: var(--ink4); border: 1px solid var(--line); display: none; }
.cw-default-tag.show { display: inline-flex; align-items: center; gap: 4px; }
.cw-group-tag { font-size: 10px; font-family: var(--mono); font-weight: 600; padding: 3px 9px; border-radius: 20px; background: var(--grp-bg); color: var(--grp-text); border: 1px solid var(--grp-line); display: none; }
.cw-group-tag.show { display: inline-flex; align-items: center; gap: 4px; }

.cw-body { padding: 14px 16px; }
.cw-no-subject { padding: 28px 16px; text-align: center; color: var(--ink4); font-size: 12px; line-height: 1.7; }
.cw-no-subject-icon { font-size: 24px; color: var(--ink5); margin-bottom: 8px; }

/* Group sync notice in editor */
.cw-group-notice { display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; background: var(--grp-bg); border: 1px solid var(--grp-line); border-radius: var(--r); margin-bottom: 12px; }
.cw-group-notice-icon { font-size: 13px; flex-shrink: 0; margin-top: 1px; }
.cw-group-notice-text { font-size: 11px; color: var(--grp-text); line-height: 1.5; }
.cw-group-notice-members { font-family: var(--mono); font-size: 10px; color: var(--grp); margin-top: 3px; }

.w-section-label { padding: 0 0 8px; font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); }
.w-row { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: var(--r); border: 1px solid var(--line2); transition: background .1s; margin-bottom: 6px; }
.w-row:hover { background: var(--surface); border-color: var(--line); }
.w-swatch { width: 36px; height: 36px; border-radius: var(--r); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; font-family: var(--mono); color: #fff; flex-shrink: 0; }
.w-swatch.ww { background: var(--ww); } .w-swatch.pt { background: var(--pt); } .w-swatch.qa { background: var(--qa); }
.w-info  { flex: 1; }
.w-name  { font-size: 13px; font-weight: 500; }
.w-desc  { font-size: 11px; color: var(--ink4); margin-top: 1px; }
.w-input-wrap { display: flex; align-items: center; gap: 5px; flex-shrink: 0; }
.w-input { width: 58px; padding: 7px 8px; border: 1px solid var(--line); border-radius: var(--r); font-family: var(--mono); font-size: 15px; font-weight: 500; color: var(--ink); text-align: right; background: var(--white); -moz-appearance: textfield; transition: border-color .12s; }
.w-input::-webkit-outer-spin-button, .w-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.w-input:focus { outline: none; border-color: var(--ink3); }
.w-input.err  { border-color: var(--err); background: var(--err-bg); color: var(--err-text); }
.w-pct { font-size: 13px; color: var(--ink4); font-family: var(--mono); }

.split-section { padding: 12px 0 0; border-top: 1px solid var(--line2); margin-top: 6px; }
.split-section-label { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); margin-bottom: 10px; }
.bar-meta { display: flex; justify-content: space-between; margin-bottom: 7px; font-size: 11px; font-family: var(--mono); color: var(--ink4); }
.bar-track { height: 10px; background: var(--line2); border-radius: 6px; overflow: hidden; display: flex; border: 1px solid var(--line); }
.bar-seg { height: 100%; transition: width .25s ease; }
.bar-seg.ww { background: var(--ww); } .bar-seg.pt { background: var(--pt); } .bar-seg.qa { background: var(--qa); }
.legend { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
.leg-item { flex: 1; min-width: 70px; display: flex; align-items: center; gap: 6px; padding: 6px 8px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); font-size: 11px; font-family: var(--mono); }
.leg-swatch { width: 7px; height: 7px; border-radius: 2px; flex-shrink: 0; }
.leg-swatch.ww { background: var(--ww); } .leg-swatch.pt { background: var(--pt); } .leg-swatch.qa { background: var(--qa); }
.leg-name { color: var(--ink4); flex: 1; }
.leg-val  { color: var(--ink2); font-weight: 600; }
.total-row { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--line2); }
.total-lbl { font-size: 11px; font-weight: 600; color: var(--ink4); font-family: var(--mono); text-transform: uppercase; letter-spacing: .8px; }
.total-val { font-size: 18px; font-weight: 600; font-family: var(--mono); transition: color .15s; }
.total-val.ok { color: var(--ok); } .total-val.err { color: var(--err); }
.status-pill { font-size: 10px; font-family: var(--mono); font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.status-pill.ok  { background: var(--ok-bg);  color: var(--ok-text);  border: 1px solid var(--ok-line); }
.status-pill.err { background: var(--err-bg); color: var(--err-text); border: 1px solid var(--err-line); }

.cw-foot { padding: 12px 16px; border-top: 1px solid var(--line2); display: flex; align-items: center; justify-content: space-between; gap: 10px; background: var(--white); }
.cw-foot-info { display: flex; flex-direction: column; gap: 2px; }
.cw-foot-label { font-size: 10px; color: var(--ink4); font-family: var(--mono); }
.cw-foot-time  { font-size: 11px; color: var(--ink3); font-family: var(--mono); font-weight: 500; }
.btn-save-weights { padding: 8px 18px; background: var(--ink); color: #fff; border: none; border-radius: var(--r); font-family: var(--sans); font-size: 12px; font-weight: 500; cursor: pointer; transition: background .12s; }
.btn-save-weights:hover:not(:disabled) { background: var(--ink2); }
.btn-save-weights:disabled { opacity: .35; cursor: not-allowed; }

/* Subjects Overview */
.subj-overview-card { background: var(--white); border: 1px solid var(--line); border-radius: var(--r-lg); overflow: hidden; flex-shrink: 0; }
.subj-overview-head { padding: 11px 16px 9px; border-bottom: 1px solid var(--line2); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.subj-overview-title { font-size: 11px; font-weight: 600; color: var(--ink2); display: flex; align-items: center; gap: 7px; }
.subj-overview-body { padding: 0; }
.sovw-table { width: 100%; border-collapse: collapse; }
.sovw-table thead tr { background: var(--surface); }
.sovw-table thead th { padding: 7px 14px; font-size: 10px; font-weight: 600; letter-spacing: .9px; text-transform: uppercase; color: var(--ink4); font-family: var(--mono); text-align: left; border-bottom: 1px solid var(--line2); white-space: nowrap; }
.sovw-table thead th.th-center { text-align: center; }
.sovw-table tbody tr { border-bottom: 1px solid var(--line2); transition: background .1s; }
.sovw-table tbody tr:last-child { border-bottom: none; }
.sovw-table tbody tr:hover { background: var(--surface); }
.sovw-table tbody tr.ovw-group-row { background: var(--grp-bg); }
.sovw-table tbody tr.ovw-group-row:hover { background: #e0f2fe; }
.sovw-table tbody tr.ovw-alias-row td { padding-top: 0; padding-bottom: 0; }
.sovw-td-name { padding: 9px 14px; display: flex; align-items: center; gap: 8px; }
.sovw-icon { width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 700; font-family: var(--mono); color: #fff; flex-shrink: 0; }
.sovw-name { font-size: 12px; font-weight: 500; color: var(--ink2); }
.sovw-name-sub { font-size: 10px; color: var(--ink4); font-family: var(--mono); margin-top: 1px; }
.sovw-td-pill { padding: 9px 14px; text-align: center; vertical-align: middle; }
.sovw-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 46px; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; font-family: var(--mono); }
.sovw-pill.ww { background: var(--ww-light); color: var(--ww); border: 1px solid var(--ww-mid); }
.sovw-pill.pt { background: var(--pt-light); color: var(--pt); border: 1px solid var(--pt-mid); }
.sovw-pill.qa { background: var(--qa-light); color: var(--qa); border: 1px solid var(--qa-mid); }
.sovw-td-bar { padding: 9px 14px; vertical-align: middle; min-width: 90px; }
.sovw-mini-bar { height: 6px; border-radius: 4px; overflow: hidden; display: flex; background: var(--line2); }
.sovw-mini-seg { height: 100%; }
.sovw-status-cell { padding: 9px 14px; text-align: center; vertical-align: middle; }
.sovw-dot-saved   { width: 7px; height: 7px; border-radius: 50%; background: var(--ok);  display: inline-block; }
.sovw-dot-default { width: 7px; height: 7px; border-radius: 50%; background: var(--ink5); display: inline-block; }
.sovw-dot-group   { width: 7px; height: 7px; border-radius: 50%; background: var(--grp); display: inline-block; }
.sovw-empty  { padding: 24px; text-align: center; color: var(--ink4); font-size: 12px; }
.sovw-loading { padding: 20px; text-align: center; color: var(--ink4); font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; }

/* Aliases row inside overview */
.ovw-aliases-wrap { display: flex; flex-wrap: wrap; gap: 4px; padding: 4px 14px 8px 50px; }
.ovw-alias-chip { display: flex; align-items: center; gap: 4px; padding: 2px 7px; background: var(--white); border: 1px solid var(--grp-line); border-radius: 20px; font-size: 10px; color: var(--grp-text); font-family: var(--mono); }
.ovw-alias-chip-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--grp); flex-shrink: 0; }
.ovw-group-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-family: var(--mono); font-weight: 600; padding: 2px 7px; border-radius: 20px; background: var(--grp-bg); color: var(--grp); border: 1px solid var(--grp-line); }

.card-title-badge { font-size: 10px; font-family: var(--mono); font-weight: 500; padding: 2px 7px; border-radius: 20px; background: var(--surface); border: 1px solid var(--line); color: var(--ink4); }

.loader-overlay { display: none; position: fixed; inset: 0; background: rgba(10,15,30,.45); z-index: 999; align-items: center; justify-content: center; }
.loader-overlay.show { display: flex; }
.loader-box { background: var(--white); border-radius: var(--r-lg); padding: 20px 28px; display: flex; align-items: center; gap: 12px; font-size: 13px; color: var(--ink2); font-weight: 500; border: 1px solid var(--line); }
.spinner { width: 16px; height: 16px; border-radius: 50%; border: 2px solid var(--line); border-top-color: var(--ink); animation: spin .7s linear infinite; flex-shrink: 0; }
@keyframes spin { to { transform: rotate(360deg); } }

.left-content::-webkit-scrollbar, .right-body::-webkit-scrollbar { width: 4px; }
.left-content::-webkit-scrollbar-track, .right-body::-webkit-scrollbar-track { background: transparent; }
.left-content::-webkit-scrollbar-thumb, .right-body::-webkit-scrollbar-thumb { background: var(--line); border-radius: 4px; }
</style>
</head>
<body>
<div class="page">

    <div class="alert-strip" id="alertStrip">
        <span id="alertIco"></span>
        <span id="alertMsg"></span>
    </div>

    <div class="body">

        <!-- ════ LEFT ════ -->
        <div class="left">
            <div class="left-head">
                <div class="lh-label">Per-Subject Configuration</div>
                <div class="lh-title">Component Weights</div>
                <div class="lh-sub">Select a grade, then click a subject to set its WW, PT, and QA weights. Group subjects that share the same weights.</div>
            </div>

            <div class="left-grade-select-wrap">
                <label for="leftGradeSelect">Grade Level</label>
                <select class="left-grade-select" id="leftGradeSelect" onchange="onLeftGradeChange()">
                    <option value="">— Select Grade —</option>
                    <?php foreach ($gradeLevels as $gl): ?>
                    <option value="<?= htmlspecialchars($gl) ?>"><?= htmlspecialchars($gl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Group mode toolbar (shown after grade selected) -->
            <div class="group-toolbar" id="groupToolbar">
                <button class="btn-group-toggle" id="btnGroupToggle" onclick="toggleGroupMode()">
                    <span class="toggle-icon">⊕</span>
                    <span class="toggle-label">Group Subjects</span>
                    <span class="toggle-badge" id="groupCountBadge">0 groups</span>
                </button>
                <div class="group-name-row" id="groupNameRow">
                    <label>Group Name</label>
                    <div class="group-name-wrap">
                        <input class="group-name-input" type="text" id="groupNameInput" placeholder="e.g. English" maxlength="100" oninput="onGroupNameInput()">
                        <button class="btn-create-group" id="btnCreateGroup" onclick="createGroup()" disabled>
                            ＋ Create
                        </button>
                    </div>
                    <div class="group-sel-hint" id="groupSelHint">Check subjects to include in this group.</div>
                </div>
            </div>

            <div class="left-content" id="leftContent">
                <div id="leftNoGrade" class="no-grade-selected">
                    Select a grade level to view subjects.
                </div>
                <div id="leftSubjectList" style="display:none;">
                    <div class="subj-list-label">
                        Subjects
                        <span class="subj-list-badge" id="leftSubjBadge">0</span>
                    </div>
                    <div id="leftSubjItems"></div>
                </div>
            </div>
        </div>

        <!-- ════ RIGHT ════ -->
        <div class="right">
            <div class="right-head">
                <div class="right-head-text">
                    <div class="rh-label">Grading Configuration</div>
                    <div class="rh-title" id="rhTitle">Select a subject</div>
                    <div class="rh-sub" id="rhSub">Choose a grade and subject from the left panel to configure its component weights.</div>
                </div>
                <div class="grade-select-wrap">
                    <label for="gradeSelect">Grade Level</label>
                    <select class="grade-select" id="gradeSelect" onchange="onRightGradeChange()">
                        <option value="">— Select Grade —</option>
                        <?php foreach ($gradeLevels as $gl): ?>
                        <option value="<?= htmlspecialchars($gl) ?>"><?= htmlspecialchars($gl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="right-body">
                <!-- Subjects Overview -->
                <div class="subj-overview-card" id="subjOverviewCard" style="display:none;">
                    <div class="subj-overview-head">
                        <div class="subj-overview-title">
                            Subjects &amp; Component Weights
                            <span class="card-title-badge" id="ovwBadge">0</span>
                        </div>
                        <span style="font-size:11px;color:var(--ink4);" id="ovwGradeLabel"></span>
                    </div>
                    <div class="subj-overview-body" id="subjOverviewBody">
                        <div class="sovw-loading">
                            <span style="width:12px;height:12px;border:2px solid var(--line);border-top-color:var(--ink);border-radius:50%;display:inline-block;animation:spin .7s linear infinite;"></span>
                            Loading…
                        </div>
                    </div>
                </div>

                <!-- Component Weight Editor -->
                <div class="cw-panel" id="cwPanel">
                    <div class="cw-panel-head">
                        <div>
                            <div class="cw-panel-label">Component Weights</div>
                            <div class="cw-panel-title" id="cwTitle">No subject selected</div>
                            <div class="cw-panel-subtitle" id="cwSubtitle">Click a subject on the left to begin.</div>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                            <span class="cw-group-tag"   id="cwGroupTag">⊕ Grouped</span>
                            <span class="cw-saved-tag"   id="cwSavedTag">✓ Saved</span>
                            <span class="cw-default-tag" id="cwDefaultTag">◈ Default</span>
                        </div>
                    </div>
                    <div class="cw-body" id="cwBody">
                        <div class="cw-no-subject">
                            <div class="cw-no-subject-icon">◈</div>
                            Select a subject from the left panel to configure its WW, PT, and QA component weights.
                        </div>
                    </div>
                    <div class="cw-foot" id="cwFoot" style="display:none;">
                        <div class="cw-foot-info">
                            <div class="cw-foot-label">Last saved</div>
                            <div class="cw-foot-time" id="cwLastSaved">Not yet saved</div>
                        </div>
                        <button class="btn-save-weights" id="btnSaveWeights" onclick="saveSubjectWeights()" disabled>Save Weights</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="loader-overlay" id="loader">
    <div class="loader-box">
        <div class="spinner"></div>
        <span id="loaderMsg">Saving…</span>
    </div>
</div>

<script>
const SUBJ_COLORS = ['#6366f1','#10b981','#f59e0b','#ef4444','#3b82f6','#ec4899','#14b8a6','#f97316','#8b5cf6','#06b6d4'];
const GRP_COLORS  = ['#0ea5e9','#6366f1','#10b981','#f59e0b','#ec4899','#8b5cf6','#ef4444','#14b8a6'];

let leftGrade       = '';
let leftSubjects    = [];   // [{name, saved}]
let activeSubject   = null; // subject name OR '__group__<id>' for a selected group
let savedWeights    = {};   // {subjectName: {ww,pt,qa,updated_at}}
let currentGrade    = '';
let allSubjects     = [];

// Group state
let groupMode       = false;
let checkedSubjects = new Set();
let groups          = [];   // [{id, group_name, members:[]}]
let expandedGroups  = new Set(); // which group IDs are accordion-open

/* ═══════════════════════════════════════════════════════
   LEFT — Grade & Subject
═══════════════════════════════════════════════════════ */
function onLeftGradeChange() {
    leftGrade       = document.getElementById('leftGradeSelect').value;
    activeSubject   = null;
    leftSubjects    = [];
    savedWeights    = {};
    groups          = [];
    expandedGroups  = new Set();
    groupMode       = false;
    checkedSubjects = new Set();
    resetCWPanel();

    const toolbar = document.getElementById('groupToolbar');
    const togBtn  = document.getElementById('btnGroupToggle');

    if (!leftGrade) {
        document.getElementById('leftNoGrade').style.display     = 'block';
        document.getElementById('leftSubjectList').style.display = 'none';
        toolbar.classList.remove('visible');
        document.getElementById('leftContent').classList.remove('group-mode');
        return;
    }

    document.getElementById('leftNoGrade').style.display     = 'none';
    document.getElementById('leftSubjectList').style.display = 'block';
    document.getElementById('leftSubjItems').innerHTML =
        '<div style="padding:20px 22px;color:var(--ink4);font-size:12px;">Loading subjects…</div>';
    toolbar.classList.add('visible');
    togBtn.classList.remove('active');
    document.getElementById('groupNameRow').classList.remove('visible');
    document.getElementById('leftContent').classList.remove('group-mode');

    Promise.all([
        post('get_subjects',    { grade_level: leftGrade }),
        post('get_all_weights', { grade_level: leftGrade }),
        post('get_groups',      { grade_level: leftGrade })
    ]).then(([subjD, wD, grpD]) => {
        leftSubjects = (subjD.subjects || []).map(name => ({ name, saved: false }));
        document.getElementById('leftSubjBadge').textContent = leftSubjects.length;

        if (wD.ok && wD.weights) {
            wD.weights.forEach(w => {
                savedWeights[w.subject_name] = {
                    ww: parseFloat(w.ww_percent), pt: parseFloat(w.pt_percent),
                    qa: parseFloat(w.qa_percent), updated_at: w.updated_at
                };
            });
        }
        if (grpD.ok && grpD.groups) { groups = grpD.groups; }
        updateGroupBadge();
        renderLeftSubjects();
    });
}

function getSubjectGroup(subjectName) {
    return groups.find(g => g.members.includes(subjectName)) || null;
}

/* ═══════════════════════════════════════════════════════
   RENDER LEFT SUBJECT LIST (with accordions for groups)
═══════════════════════════════════════════════════════ */
function renderLeftSubjects() {
    const container = document.getElementById('leftSubjItems');
    if (!leftSubjects.length) {
        container.innerHTML = '<div class="no-subj-msg">No active subjects found for <strong>' + leftGrade + '</strong>.</div>';
        return;
    }

    const assignedNames = new Set(groups.flatMap(g => g.members));
    const ungrouped     = leftSubjects.filter(s => !assignedNames.has(s.name));
    const html          = [];

    // ── Ungrouped subjects (flat list, same as before) ──
    ungrouped.forEach((s, i) => {
        html.push(renderSubjItem(s, i));
    });

    // ── Grouped subjects — one accordion per group ──
    groups.forEach((grp, gi) => {
        const grpColor  = GRP_COLORS[gi % GRP_COLORS.length];
        const members   = leftSubjects.filter(s => grp.members.includes(s.name));
        if (!members.length) return;

        const isOpen     = expandedGroups.has(grp.id);
        const isActive   = activeSubject === '__group__' + grp.id;
        const sw         = savedWeights[members[0].name]; // all members share same weights
        const isSaved    = !!sw;
        const meta       = isSaved
            ? `WW ${sw.ww}% · PT ${sw.pt}% · QA ${sw.qa}%`
            : 'Using default weights';
        const ini        = grp.group_name.substring(0, 3).toUpperCase();

        // Build member rows
        const memberRows = members.map((s, mi) => {
            const mActive  = activeSubject === s.name;
            const mColor   = SUBJ_COLORS[(ungrouped.length + mi) % SUBJ_COLORS.length];
            const mIni     = s.name.split(' ').map(w => w[0]).join('').substring(0, 3).toUpperCase();
            const mChecked = checkedSubjects.has(s.name);
            return `
                <div class="grp-member-item${mActive ? ' active' : ''}${mChecked ? ' sel-checked' : ''}"
                     data-name="${escHtml(s.name)}"
                     onclick="onMemberClick('${escHtml(s.name).replace(/'/g,"\\'")}', event)">
                    <div class="subj-checkbox"></div>
                    <div class="grp-member-icon" style="background:${mColor};">${mIni}</div>
                    <div class="grp-member-info">
                        <div class="grp-member-name">${escHtml(s.name)}</div>
                        <div class="grp-member-meta">${meta}</div>
                    </div>
                    <div class="${isSaved ? 'subj-item-saved' : 'subj-item-unsaved'}"></div>
                </div>`;
        }).join('');

        html.push(`
            <div class="grp-accordion" data-group-id="${grp.id}">
                <div class="grp-accordion-header${isActive ? ' active' : ''}"
                     onclick="onGroupAccordionClick(${grp.id}, event)">
                    <div class="subj-checkbox"></div>
                    <div class="grp-accordion-icon" style="background:${grpColor};">${ini}</div>
                    <div class="grp-accordion-info">
                        <div class="grp-accordion-name">${escHtml(grp.group_name)}</div>
                        <div class="grp-accordion-meta">⊕ ${members.length} subject${members.length !== 1 ? 's' : ''} · ${meta}</div>
                    </div>
                    <div class="${isSaved ? 'subj-item-saved' : 'subj-item-unsaved'}"></div>
                    <div class="grp-accordion-arrow${isOpen ? ' open' : ''}">▼</div>
                    <button class="grp-accordion-del" title="Delete group"
                            onclick="deleteGroup(${grp.id}, event)">×</button>
                </div>
                <div class="grp-accordion-body${isOpen ? ' open' : ''}">
                    ${memberRows}
                </div>
            </div>`);
    });

    container.innerHTML = html.join('');
}

/* Render a regular flat subject item (ungrouped) */
function renderSubjItem(s, colorIdx) {
    const isSaved  = !!savedWeights[s.name];
    const color    = SUBJ_COLORS[colorIdx % SUBJ_COLORS.length];
    const initials = s.name.split(' ').map(w => w[0]).join('').substring(0, 3).toUpperCase();
    const sw       = savedWeights[s.name];
    const meta     = isSaved ? `WW ${sw.ww}% · PT ${sw.pt}% · QA ${sw.qa}%` : 'Using default weights';
    const isActive = activeSubject === s.name;
    const isChecked= checkedSubjects.has(s.name);

    return `
        <div class="subj-item${isActive ? ' active' : ''}${isChecked ? ' sel-checked' : ''}"
             data-name="${escHtml(s.name)}"
             onclick="onSubjItemClick('${escHtml(s.name).replace(/'/g,"\\'")}')">
            <div class="subj-checkbox"></div>
            <div class="subj-item-icon" style="background:${color};">${initials}</div>
            <div class="subj-item-info">
                <div class="subj-item-name">${escHtml(s.name)}</div>
                <div class="subj-item-meta">${meta}</div>
            </div>
            <div class="${isSaved ? 'subj-item-saved' : 'subj-item-unsaved'}"
                 title="${isSaved ? 'Weights saved' : 'No custom weights'}"></div>
        </div>`;
}

/* ── Click handlers ── */

function onSubjItemClick(name) {
    if (groupMode) {
        checkedSubjects.has(name) ? checkedSubjects.delete(name) : checkedSubjects.add(name);
        renderLeftSubjects();
        updateGroupSelHint();
        document.getElementById('btnCreateGroup').disabled =
            checkedSubjects.size === 0 || !document.getElementById('groupNameInput').value.trim();
    } else {
        selectSubject(name);
    }
}

/* Clicking the accordion group header — toggles expand AND selects for editing */
function onGroupAccordionClick(groupId, evt) {
    // Don't fire if the delete button was clicked
    if (evt.target.classList.contains('grp-accordion-del') ||
        evt.target.closest('.grp-accordion-del')) return;

    // Toggle accordion open/closed
    if (expandedGroups.has(groupId)) {
        expandedGroups.delete(groupId);
    } else {
        expandedGroups.add(groupId);
    }

    // Select the group itself for weight editing
    activeSubject = '__group__' + groupId;
    renderLeftSubjects();

    const grp = groups.find(g => g.id === groupId);
    if (!grp || !grp.members.length) return;

    document.getElementById('rhTitle').textContent = grp.group_name;
    document.getElementById('rhSub').textContent   =
        leftGrade + ' · Group of ' + grp.members.length +
        ' subject' + (grp.members.length !== 1 ? 's' : '') + ' — Editing shared weights';

    // Load weights from first member (all are in sync)
    const firstMember = leftSubjects.find(s => grp.members.includes(s.name));
    if (!firstMember) return;

    if (savedWeights[firstMember.name]) {
        const sw = savedWeights[firstMember.name];
        renderCWEditor(firstMember.name, sw.ww, sw.pt, sw.qa, sw.updated_at, true, grp);
    } else {
        post('get_subject_weights', { grade_level: leftGrade, subject_name: firstMember.name })
            .then(d => renderCWEditor(firstMember.name, d.ww, d.pt, d.qa, null, d.saved, grp));
    }
}

/* Clicking a member subject inside an expanded accordion */
function onMemberClick(name, evt) {
    evt.stopPropagation(); // prevent bubbling to accordion header

    if (groupMode) {
        checkedSubjects.has(name) ? checkedSubjects.delete(name) : checkedSubjects.add(name);
        renderLeftSubjects();
        updateGroupSelHint();
        document.getElementById('btnCreateGroup').disabled =
            checkedSubjects.size === 0 || !document.getElementById('groupNameInput').value.trim();
    } else {
        selectSubject(name);
    }
}

function selectSubject(name) {
    activeSubject = name;
    renderLeftSubjects();

    const grp = getSubjectGroup(name);

    document.getElementById('rhTitle').textContent = name;
    document.getElementById('rhSub').textContent   =
        leftGrade + (grp ? ' · Group: ' + grp.group_name : '') + ' — Configure WW, PT, and QA weights.';

    if (savedWeights[name]) {
        const sw = savedWeights[name];
        renderCWEditor(name, sw.ww, sw.pt, sw.qa, sw.updated_at, true, grp);
    } else {
        post('get_subject_weights', { grade_level: leftGrade, subject_name: name })
            .then(d => renderCWEditor(name, d.ww, d.pt, d.qa, null, d.saved, grp));
    }
}

/* ═══════════════════════════════════════════════════════
   GROUP MODE
═══════════════════════════════════════════════════════ */
function toggleGroupMode() {
    groupMode = !groupMode;
    checkedSubjects.clear();

    const btn      = document.getElementById('btnGroupToggle');
    const nameRow  = document.getElementById('groupNameRow');
    const content  = document.getElementById('leftContent');

    btn.classList.toggle('active', groupMode);
    nameRow.classList.toggle('visible', groupMode);
    content.classList.toggle('group-mode', groupMode);

    document.getElementById('btnGroupToggle').querySelector('.toggle-label').textContent =
        groupMode ? 'Exit Group Mode' : 'Group Subjects';

    document.getElementById('groupNameInput').value = '';
    document.getElementById('btnCreateGroup').disabled = true;
    renderLeftSubjects();
    if (groupMode) updateGroupSelHint();
}

function onGroupNameInput() {
    const hasName = document.getElementById('groupNameInput').value.trim().length > 0;
    document.getElementById('btnCreateGroup').disabled = !(hasName && checkedSubjects.size > 0);
}

function updateGroupSelHint() {
    const n = checkedSubjects.size;
    document.getElementById('groupSelHint').textContent =
        n === 0 ? 'Check subjects to include in this group.'
        : n === 1 ? '1 subject selected. Check more to group them.'
        : n + ' subjects selected.';
}

function updateGroupBadge() {
    const badge = document.getElementById('groupCountBadge');
    badge.textContent = groups.length + (groups.length === 1 ? ' group' : ' groups');
    const tog = document.getElementById('btnGroupToggle');
    if (groups.length > 0) {
        tog.classList.add('active');
        document.getElementById('groupCountBadge').style.display = 'inline-flex';
    } else if (!groupMode) {
        tog.classList.remove('active');
    }
}

function createGroup() {
    const groupName = document.getElementById('groupNameInput').value.trim();
    if (!groupName || checkedSubjects.size === 0) return;

    const conflicting = [...checkedSubjects].filter(s => {
        const g = getSubjectGroup(s);
        return g && g.group_name !== groupName;
    });
    if (conflicting.length > 0) {
        showAlert('error', 'Some subjects are already in another group: ' + conflicting.join(', ') + '. Remove them first.');
        return;
    }

    setLoader(true, 'Creating group "' + groupName + '"…');
    post('save_group', {
        grade_level: leftGrade,
        group_name:  groupName,
        members:     JSON.stringify([...checkedSubjects])
    }).then(d => {
        setLoader(false);
        if (d.ok) {
            post('get_groups', { grade_level: leftGrade }).then(gd => {
                groups = gd.groups || [];
                updateGroupBadge();
                checkedSubjects.clear();
                document.getElementById('groupNameInput').value = '';
                document.getElementById('btnCreateGroup').disabled = true;
                renderLeftSubjects();
                updateGroupSelHint();
                showAlert('success', d.msg);
                if (currentGrade === leftGrade) refreshOverview();
            });
        } else {
            showAlert('error', d.msg);
        }
    });
}

function deleteGroup(groupId, evt) {
    evt.stopPropagation();
    if (!confirm('Delete this group? Subjects will remain but lose their grouping.')) return;
    setLoader(true, 'Deleting group…');
    post('delete_group', { group_id: groupId, grade_level: leftGrade }).then(d => {
        setLoader(false);
        if (d.ok) {
            groups = groups.filter(g => g.id !== groupId);
            expandedGroups.delete(groupId);
            // If the deleted group was selected, reset panel
            if (activeSubject === '__group__' + groupId) {
                activeSubject = null;
                resetCWPanel();
            }
            updateGroupBadge();
            renderLeftSubjects();
            showAlert('success', d.msg);
            if (currentGrade === leftGrade) refreshOverview();
        } else {
            showAlert('error', d.msg || 'Error deleting group.');
        }
    });
}

/* ═══════════════════════════════════════════════════════
   COMPONENT WEIGHT EDITOR
═══════════════════════════════════════════════════════ */
function renderCWEditor(subjName, ww, pt, qa, updatedAt, isSaved, grp) {
    document.getElementById('cwTitle').textContent    = grp ? grp.group_name : subjName;
    document.getElementById('cwSubtitle').textContent =
        leftGrade + (grp ? ' · Group: ' + grp.group_name : '') + ' — Component weight distribution';

    document.getElementById('cwSavedTag').className   = 'cw-saved-tag'   + (isSaved  ? ' show' : '');
    document.getElementById('cwDefaultTag').className = 'cw-default-tag' + (!isSaved ? ' show' : '');
    document.getElementById('cwGroupTag').className   = 'cw-group-tag'   + (grp      ? ' show' : '');
    document.getElementById('cwFoot').style.display   = 'flex';
    document.getElementById('cwLastSaved').textContent = updatedAt ? formatDate(updatedAt) : (isSaved ? 'Saved' : 'Not yet saved');

    const groupNotice = grp ? `
        <div class="cw-group-notice">
            <span class="cw-group-notice-icon">⊕</span>
            <div>
                <div class="cw-group-notice-text">This subject belongs to the group <strong>${escHtml(grp.group_name)}</strong>. Saving weights here will apply to all members:</div>
                <div class="cw-group-notice-members">${grp.members.map(escHtml).join(' · ')}</div>
            </div>
        </div>` : '';

    document.getElementById('cwBody').innerHTML = `
        ${groupNotice}
        <div class="w-section-label">Grade Components</div>
        <div class="w-row">
            <div class="w-swatch ww">WW</div>
            <div class="w-info"><div class="w-name">Written Work</div><div class="w-desc">Quizzes · long tests · written outputs</div></div>
            <div class="w-input-wrap">
                <input class="w-input" type="number" id="cwWW" value="${ww}" min="0" max="100" step="1" oninput="onCWChange()">
                <span class="w-pct">%</span>
            </div>
        </div>
        <div class="w-row">
            <div class="w-swatch pt">PT</div>
            <div class="w-info"><div class="w-name">Performance Task</div><div class="w-desc">Projects · homework · classwork</div></div>
            <div class="w-input-wrap">
                <input class="w-input" type="number" id="cwPT" value="${pt}" min="0" max="100" step="1" oninput="onCWChange()">
                <span class="w-pct">%</span>
            </div>
        </div>
        <div class="w-row">
            <div class="w-swatch qa">QA</div>
            <div class="w-info"><div class="w-name">Quarterly Assessment</div><div class="w-desc">Finals · manually entered per student</div></div>
            <div class="w-input-wrap">
                <input class="w-input" type="number" id="cwQA" value="${qa}" min="0" max="100" step="1" oninput="onCWChange()">
                <span class="w-pct">%</span>
            </div>
        </div>
        <div class="split-section">
            <div class="split-section-label">Component Split</div>
            <div class="bar-meta">
                <span>Weight distribution</span>
                <span id="cwBarTotalText">${ww}% + ${pt}% + ${qa}%</span>
            </div>
            <div class="bar-track">
                <div class="bar-seg ww" id="cwBarWW" style="width:${ww}%"></div>
                <div class="bar-seg pt" id="cwBarPT" style="width:${pt}%"></div>
                <div class="bar-seg qa" id="cwBarQA" style="width:${qa}%"></div>
            </div>
            <div class="legend">
                <div class="leg-item"><div class="leg-swatch ww"></div><span class="leg-name">Written Work</span><span class="leg-val" id="cwLgWW">${ww}%</span></div>
                <div class="leg-item"><div class="leg-swatch pt"></div><span class="leg-name">Perf. Task</span><span class="leg-val" id="cwLgPT">${pt}%</span></div>
                <div class="leg-item"><div class="leg-swatch qa"></div><span class="leg-name">Quarterly</span><span class="leg-val" id="cwLgQA">${qa}%</span></div>
            </div>
            <div class="total-row">
                <span class="total-lbl">Total</span>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="status-pill" id="cwStatusPill"></span>
                    <span class="total-val"   id="cwTotalVal"></span>
                </div>
            </div>
        </div>
    `;
    onCWChange();
}

function resetCWPanel() {
    document.getElementById('rhTitle').textContent    = 'Select a subject';
    document.getElementById('rhSub').textContent      = 'Choose a grade and subject from the left panel to configure its component weights.';
    document.getElementById('cwTitle').textContent    = 'No subject selected';
    document.getElementById('cwSubtitle').textContent = 'Click a subject on the left to begin.';
    document.getElementById('cwSavedTag').className   = 'cw-saved-tag';
    document.getElementById('cwDefaultTag').className = 'cw-default-tag';
    document.getElementById('cwGroupTag').className   = 'cw-group-tag';
    document.getElementById('cwFoot').style.display   = 'none';
    document.getElementById('cwBody').innerHTML = `
        <div class="cw-no-subject">
            <div class="cw-no-subject-icon">◈</div>
            Select a subject from the left panel to configure its WW, PT, and QA component weights.
        </div>`;
}

function onCWChange() {
    const ww    = parseInt(document.getElementById('cwWW')?.value) || 0;
    const pt    = parseInt(document.getElementById('cwPT')?.value) || 0;
    const qa    = parseInt(document.getElementById('cwQA')?.value) || 0;
    const total = ww + pt + qa;
    const isOk  = total === 100;
    document.getElementById('cwBarWW').style.width = Math.min(ww, 100) + '%';
    document.getElementById('cwBarPT').style.width = Math.min(pt, 100) + '%';
    document.getElementById('cwBarQA').style.width = Math.min(qa, 100) + '%';
    document.getElementById('cwLgWW').textContent  = ww + '%';
    document.getElementById('cwLgPT').textContent  = pt + '%';
    document.getElementById('cwLgQA').textContent  = qa + '%';
    document.getElementById('cwBarTotalText').textContent = ww + '% + ' + pt + '% + ' + qa + '%';
    const tv = document.getElementById('cwTotalVal');
    tv.textContent = total + '%';
    tv.className   = 'total-val ' + (isOk ? 'ok' : 'err');
    const sp = document.getElementById('cwStatusPill');
    sp.className   = 'status-pill ' + (isOk ? 'ok' : 'err');
    sp.textContent = isOk ? '100% — valid' : total + '% — needs 100';
    ['cwWW','cwPT','cwQA'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('err', !isOk);
    });
    const btn = document.getElementById('btnSaveWeights');
    if (btn) btn.disabled = !isOk;
}

function saveSubjectWeights() {
    if (!activeSubject || !leftGrade) return;
    const ww = parseInt(document.getElementById('cwWW').value) || 0;
    const pt = parseInt(document.getElementById('cwPT').value) || 0;
    const qa = parseInt(document.getElementById('cwQA').value) || 0;
    if (ww + pt + qa !== 100) { showAlert('error', 'Weights must total 100%.'); return; }

    // Resolve the actual subject name to use for the save call
    // If a group header is selected, use the first member
    let subjectNameForSave = activeSubject;
    if (activeSubject && activeSubject.startsWith('__group__')) {
        const groupId = parseInt(activeSubject.replace('__group__', ''));
        const grp     = groups.find(g => g.id === groupId);
        if (!grp || !grp.members.length) return;
        subjectNameForSave = grp.members[0];
    }

    const grp = getSubjectGroup(subjectNameForSave);
    const msg = grp
        ? 'Saving weights for group "' + grp.group_name + '"…'
        : 'Saving weights for ' + subjectNameForSave + '…';

    setLoader(true, msg);
    post('save_subject_weights', { grade_level: leftGrade, subject_name: subjectNameForSave, ww, pt, qa })
        .then(d => {
            setLoader(false);
            if (d.ok) {
                const synced = d.synced_subjects || [subjectNameForSave];
                const now    = new Date().toISOString();
                synced.forEach(s => { savedWeights[s] = { ww, pt, qa, updated_at: now }; });
                document.getElementById('cwSavedTag').className   = 'cw-saved-tag show';
                document.getElementById('cwDefaultTag').className = 'cw-default-tag';
                document.getElementById('cwLastSaved').textContent = 'Just now';
                renderLeftSubjects();
                if (currentGrade === leftGrade) refreshOverview();
                showAlert('success', d.msg);
            } else {
                showAlert('error', d.msg);
            }
        }).catch(() => { setLoader(false); showAlert('error', 'Network error.'); });
}

/* ═══════════════════════════════════════════════════════
   RIGHT — Overview
═══════════════════════════════════════════════════════ */
function onRightGradeChange() {
    currentGrade = document.getElementById('gradeSelect').value;
    const ovwCard = document.getElementById('subjOverviewCard');
    if (!currentGrade) { ovwCard.style.display = 'none'; allSubjects = []; return; }

    ovwCard.style.display = 'block';
    document.getElementById('ovwGradeLabel').textContent = currentGrade;
    document.getElementById('subjOverviewBody').innerHTML = `
        <div class="sovw-loading">
            <span style="width:12px;height:12px;border:2px solid var(--line);border-top-color:var(--ink);border-radius:50%;display:inline-block;animation:spin .7s linear infinite;"></span>
            Loading subjects…
        </div>`;

    Promise.all([
        post('get_subjects',    { grade_level: currentGrade }),
        post('get_all_weights', { grade_level: currentGrade }),
        post('get_groups',      { grade_level: currentGrade })
    ]).then(([sD, wD, gD]) => {
        allSubjects = sD.subjects || [];
        const wLookup = {};
        if (wD.ok && wD.weights) {
            wD.weights.forEach(w => {
                wLookup[w.subject_name] = { ww: parseFloat(w.ww_percent), pt: parseFloat(w.pt_percent), qa: parseFloat(w.qa_percent), updated_at: w.updated_at };
            });
        }
        const ovwGroups = (gD.ok && gD.groups) ? gD.groups : [];
        renderSubjectsOverview(allSubjects, wLookup, ovwGroups);
    });
}

function refreshOverview() {
    if (!currentGrade) return;
    Promise.all([
        post('get_all_weights', { grade_level: currentGrade }),
        post('get_groups',      { grade_level: currentGrade })
    ]).then(([wD, gD]) => {
        const wLookup = {};
        if (wD.ok && wD.weights) {
            wD.weights.forEach(w => {
                wLookup[w.subject_name] = { ww: parseFloat(w.ww_percent), pt: parseFloat(w.pt_percent), qa: parseFloat(w.qa_percent) };
            });
        }
        const ovwGroups = (gD.ok && gD.groups) ? gD.groups : [];
        renderSubjectsOverview(allSubjects, wLookup, ovwGroups);
    });
}

function renderSubjectsOverview(subjects, wLookup, ovwGroups) {
    const body  = document.getElementById('subjOverviewBody');
    const badge = document.getElementById('ovwBadge');
    badge.textContent = subjects.length;
    if (!subjects.length) { body.innerHTML = '<div class="sovw-empty">No active subjects found for this grade.</div>'; return; }

    const DEF = { ww: 30, pt: 50, qa: 20 };
    const assignedNames = new Set(ovwGroups.flatMap(g => g.members));
    const ungrouped     = subjects.filter(n => !assignedNames.has(n));
    const rows          = [];

    ungrouped.forEach((name, i) => {
        const sw      = wLookup[name] || null;
        const ww      = sw ? sw.ww : DEF.ww;
        const pt      = sw ? sw.pt : DEF.pt;
        const qa      = sw ? sw.qa : DEF.qa;
        const isSaved = !!sw;
        const color   = SUBJ_COLORS[i % SUBJ_COLORS.length];
        const ini     = name.split(' ').map(w => w[0]).join('').substring(0, 3).toUpperCase();
        rows.push(`
            <tr>
                <td><div class="sovw-td-name"><div class="sovw-icon" style="background:${color};">${ini}</div><span class="sovw-name">${escHtml(name)}</span></div></td>
                <td class="sovw-td-pill"><span class="sovw-pill ww">${ww}%</span></td>
                <td class="sovw-td-pill"><span class="sovw-pill pt">${pt}%</span></td>
                <td class="sovw-td-pill"><span class="sovw-pill qa">${qa}%</span></td>
                <td class="sovw-td-bar"><div class="sovw-mini-bar"><div class="sovw-mini-seg" style="width:${ww}%;background:var(--ww);"></div><div class="sovw-mini-seg" style="width:${pt}%;background:var(--pt);"></div><div class="sovw-mini-seg" style="width:${qa}%;background:var(--qa);"></div></div></td>
                <td class="sovw-status-cell"><span class="${isSaved ? 'sovw-dot-saved' : 'sovw-dot-default'}" title="${isSaved ? 'Custom weights' : 'Default weights'}"></span></td>
            </tr>`);
    });

    ovwGroups.forEach((grp, gi) => {
        const members   = subjects.filter(n => grp.members.includes(n));
        if (!members.length) return;
        const grpColor  = GRP_COLORS[gi % GRP_COLORS.length];
        const first     = members[0];
        const sw        = wLookup[first] || null;
        const ww        = sw ? sw.ww : DEF.ww;
        const pt        = sw ? sw.pt : DEF.pt;
        const qa        = sw ? sw.qa : DEF.qa;
        const isSaved   = !!sw;

        const aliasChips = members.map(m =>
            `<div class="ovw-alias-chip"><div class="ovw-alias-chip-dot" style="background:${grpColor};"></div>${escHtml(m)}</div>`
        ).join('');

        rows.push(`
            <tr class="ovw-group-row">
                <td>
                    <div class="sovw-td-name">
                        <div class="sovw-icon" style="background:${grpColor};">${grp.group_name.substring(0,3).toUpperCase()}</div>
                        <div>
                            <div class="sovw-name">${escHtml(grp.group_name)}</div>
                            <div class="sovw-name-sub">${members.length} subjects grouped</div>
                        </div>
                    </div>
                </td>
                <td class="sovw-td-pill"><span class="sovw-pill ww">${ww}%</span></td>
                <td class="sovw-td-pill"><span class="sovw-pill pt">${pt}%</span></td>
                <td class="sovw-td-pill"><span class="sovw-pill qa">${qa}%</span></td>
                <td class="sovw-td-bar"><div class="sovw-mini-bar"><div class="sovw-mini-seg" style="width:${ww}%;background:var(--ww);"></div><div class="sovw-mini-seg" style="width:${pt}%;background:var(--pt);"></div><div class="sovw-mini-seg" style="width:${qa}%;background:var(--qa);"></div></div></td>
                <td class="sovw-status-cell"><span class="sovw-dot-group" title="Grouped"></span></td>
            </tr>
            <tr class="ovw-alias-row">
                <td colspan="6">
                    <div class="ovw-aliases-wrap">${aliasChips}</div>
                </td>
            </tr>`);
    });

    body.innerHTML = `
        <table class="sovw-table">
            <thead><tr>
                <th>Subject / Group</th>
                <th class="th-center">WW</th>
                <th class="th-center">PT</th>
                <th class="th-center">QA</th>
                <th>Split</th>
                <th class="th-center">Status</th>
            </tr></thead>
            <tbody>${rows.join('')}</tbody>
        </table>
        <div style="padding:8px 14px;border-top:1px solid var(--line2);display:flex;align-items:center;gap:14px;font-size:10px;color:var(--ink4);font-family:var(--mono);flex-wrap:wrap;">
            <span><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--ok);margin-right:5px;vertical-align:middle;"></span>Custom saved</span>
            <span><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--grp);margin-right:5px;vertical-align:middle;"></span>Grouped (shared weights)</span>
            <span><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--ink5);margin-right:5px;vertical-align:middle;"></span>Using default (WW 30% · PT 50% · QA 20%)</span>
        </div>`;
}

/* ═══════════════════════════════════════════════════════
   Helpers
═══════════════════════════════════════════════════════ */
function post(action, data) {
    const body = 'ajax_action=' + encodeURIComponent(action) +
        Object.entries(data).map(([k, v]) => '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('');
    return fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }).then(r => r.json());
}

function showAlert(type, msg) {
    const s = document.getElementById('alertStrip');
    document.getElementById('alertIco').textContent = type === 'success' ? '✓ ' : '✕ ';
    document.getElementById('alertMsg').textContent = msg;
    s.className = 'alert-strip show ' + type;
    clearTimeout(s._t);
    s._t = setTimeout(() => { s.className = 'alert-strip'; }, 4500);
}

function setLoader(show, msg) {
    document.getElementById('loader').classList.toggle('show', show);
    if (msg) document.getElementById('loaderMsg').textContent = msg;
}

function formatDate(dt) {
    try {
        const d = new Date(dt);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
             + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    } catch { return dt; }
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>
</body>
</html>