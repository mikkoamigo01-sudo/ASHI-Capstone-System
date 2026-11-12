<?php
session_start();

if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

if (!isset($_GET['id'])) {
    header("Location: teacher_dashb.php");
    exit();
}

$submission_id = (int) $_GET['id'];
$teacher_id    = (int) $_SESSION['teacher_id'];

// Fetch submission with all related details
$stmt = $conn->prepare("
    SELECT
        sub.*,
        s.first_name, s.last_name, s.email AS student_email,
        a.title AS assignment_title, a.description AS assignment_description,
        a.instructions, a.max_points, a.due_date, a.assignment_type,
        subj.subject_name, subj.id AS subject_id, subj.theme_color,
        t.fullname AS teacher_name
    FROM submissions sub
    JOIN students    s    ON sub.student_id   = s.id
    JOIN assignments a    ON sub.assignment_id = a.id
    JOIN subjects    subj ON a.subject_id      = subj.id
    JOIN teachers    t    ON a.teacher_id      = t.id
    WHERE sub.id = ? AND a.teacher_id = ?
");
$stmt->bind_param("ii", $submission_id, $teacher_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$submission) {
    die("Submission not found or you don't have permission to grade it.");
}

// Fetch submission attachments
$stmt = $conn->prepare("SELECT * FROM submission_attachments WHERE submission_id = ? ORDER BY uploaded_at ASC");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$attachments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch assignment attachments
$assignment_id = (int) $submission['assignment_id'];
$stmt = $conn->prepare("SELECT * FROM assignment_attachments WHERE assignment_id = ? ORDER BY uploaded_at ASC");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$assignment_attachments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action   = $_POST['action'];
    $feedback = trim($_POST['feedback'] ?? '');

    if ($action === 'grade') {
        $score = floatval($_POST['score']);

        if ($score < 0 || $score > 100) {
            $error = "Score must be between 0 and 100.";
        } else {
            $stmt = $conn->prepare("
                UPDATE submissions
                SET score = ?, teacher_feedback = ?, status = 'graded', graded_at = NOW(), graded_by = ?
                WHERE id = ?
            ");
            $stmt->bind_param("dsii", $score, $feedback, $teacher_id, $submission_id);
            $stmt->execute();
            $stmt->close();

            $notif_title   = "Grade Posted: " . $submission['assignment_title'];
            $notif_message = "Your teacher has graded your submission. Score: " . $score . "%";
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
                VALUES ('student', ?, 'grade', ?, ?, ?)
            ");
            $stmt->bind_param("issi", $submission['student_id'], $notif_title, $notif_message, $assignment_id);
            $stmt->execute();
            $stmt->close();

            $success = "Grade submitted successfully!";

            // Refresh submission data
            $stmt = $conn->prepare("SELECT * FROM submissions WHERE id = ?");
            $stmt->bind_param("i", $submission_id);
            $stmt->execute();
            $submission = array_merge($submission, $stmt->get_result()->fetch_assoc());
            $stmt->close();
        }

    } elseif ($action === 'return') {
        $stmt = $conn->prepare("
            UPDATE submissions
            SET teacher_feedback = ?, status = 'returned', graded_by = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sii", $feedback, $teacher_id, $submission_id);
        $stmt->execute();
        $stmt->close();

        $notif_title   = "Submission Returned: " . $submission['assignment_title'];
        $notif_message = "Your teacher has returned your submission with feedback.";
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_type, user_id, notification_type, title, message, related_id)
            VALUES ('student', ?, 'submission', ?, ?, ?)
        ");
        $stmt->bind_param("issi", $submission['student_id'], $notif_title, $notif_message, $assignment_id);
        $stmt->execute();
        $stmt->close();

        $success = "Submission returned to student with feedback.";

        // Refresh submission data
        $stmt = $conn->prepare("SELECT * FROM submissions WHERE id = ?");
        $stmt->bind_param("i", $submission_id);
        $stmt->execute();
        $submission = array_merge($submission, $stmt->get_result()->fetch_assoc());
        $stmt->close();
    }
}

// ── Late check ────────────────────────────────────────────────────────────────
$is_late = false;
if ($submission['due_date']) {
    $is_late = strtotime($submission['submitted_at']) > strtotime($submission['due_date']);
}

// ── Navigation: prev/next submissions for this assignment ─────────────────────
$stmt = $conn->prepare("
    SELECT sub.id, s.first_name, s.last_name, sub.status, sub.score
    FROM submissions sub
    JOIN students s ON sub.student_id = s.id
    WHERE sub.assignment_id = ?
    ORDER BY s.last_name ASC, s.first_name ASC
");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$all_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$current_index = 0;
foreach ($all_submissions as $index => $sub) {
    if ((int) $sub['id'] === $submission_id) {
        $current_index = $index;
        break;
    }
}
$prev_submission = $current_index > 0                            ? $all_submissions[$current_index - 1] : null;
$next_submission = $current_index < count($all_submissions) - 1 ? $all_submissions[$current_index + 1] : null;

// ── Avatar ────────────────────────────────────────────────────────────────────
$first_initial = strtoupper(mb_substr($submission['first_name'], 0, 1));
$last_initial  = strtoupper(mb_substr($submission['last_name'],  0, 1));
$initials      = $first_initial . $last_initial;
$avatar_colors = ['#1E88E5','#43A047','#E53935','#8E24AA','#F4511E','#00897B','#3949AB','#D81B60','#039BE5','#7CB342'];
$avatar_bg     = $avatar_colors[(ord($first_initial) + ord($last_initial)) % count($avatar_colors)];

$theme = !empty($submission['theme_color']) ? $submission['theme_color'] : '#1E88E5';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title>Grade Submission — <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --theme:      <?php echo $theme; ?>;
            --theme-lt:   <?php echo $theme; ?>18;
            --theme-mid:  <?php echo $theme; ?>2e;
            --bg:         #F8F9FA;
            --surface:    #FFFFFF;
            --border:     #E8EAED;
            --border-2:   #DADCE0;
            --t1:         #202124;
            --t2:         #5F6368;
            --t3:         #9AA0A6;
            --r:          10px;
            --r-sm:       6px;
            --r-lg:       14px;
            --sh-sm:      0 1px 3px rgba(60,64,67,.08),0 1px 2px rgba(60,64,67,.05);
            --sh:         0 2px 8px rgba(60,64,67,.09),0 1px 3px rgba(60,64,67,.05);
            --nav-h:      58px;
            --font:       'DM Sans', sans-serif;
            --mono:       'DM Mono', monospace;
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        html{scroll-behavior:smooth;}
        body{font-family:var(--font);background:var(--bg);color:var(--t1);font-size:14px;line-height:1.6;-webkit-font-smoothing:antialiased;}

        /* TOPBAR */
        .topbar{
            position:sticky;top:0;z-index:200;
            height:var(--nav-h);
            background:var(--surface);
            border-bottom:1px solid var(--border);
            display:flex;align-items:center;
            padding:0 20px;gap:12px;
        }
        .icon-btn{
            width:36px;height:36px;border-radius:50%;border:none;
            background:transparent;color:var(--t2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            font-size:15px;transition:background .15s;flex-shrink:0;
        }
        .icon-btn:hover{background:var(--bg);}
        .topbar-meta{flex:1;min-width:0;}
        .topbar-title{
            font-size:15px;font-weight:600;color:var(--t1);
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3;
        }
        .topbar-sub{font-size:12px;color:var(--t2);margin-top:1px;}
        .topbar-right{display:flex;align-items:center;gap:8px;flex-shrink:0;}
        .nav-count{font-size:13px;color:var(--t2);font-variant-numeric:tabular-nums;padding:0 10px;}
        .nav-btn{
            height:32px;padding:0 12px;
            border:1px solid var(--border-2);border-radius:var(--r-sm);
            background:var(--surface);color:var(--t2);
            font-family:var(--font);font-size:13px;font-weight:500;
            cursor:pointer;display:flex;align-items:center;gap:5px;
            transition:all .15s;white-space:nowrap;
        }
        .nav-btn:hover:not(:disabled){background:var(--bg);border-color:var(--t3);color:var(--t1);}
        .nav-btn:disabled{opacity:.35;cursor:not-allowed;}

        /* SUBJECT STRIP */
        .strip{
            background:var(--theme);
            padding:12px 24px;
            display:flex;align-items:center;gap:8px;
        }
        .strip-text{font-size:13px;font-weight:500;color:rgba(255,255,255,.85);}
        .strip-name{font-size:13px;font-weight:600;color:#fff;}
        .strip-dot{width:3px;height:3px;border-radius:50%;background:rgba(255,255,255,.45);}

        /* LAYOUT */
        .layout{
            max-width:1140px;margin:22px auto;
            padding:0 22px 56px;
            display:grid;
            grid-template-columns:1fr 330px;
            gap:18px;align-items:start;
        }

        /* CARD */
        .card{
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:var(--r-lg);
            overflow:hidden;
            box-shadow:var(--sh-sm);
        }
        .card+.card{margin-top:14px;}
        .card-head{
            padding:14px 18px;
            border-bottom:1px solid var(--border);
            display:flex;align-items:center;gap:10px;
        }
        .ch-icon{
            width:28px;height:28px;border-radius:7px;
            background:var(--theme-lt);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;color:var(--theme);flex-shrink:0;
        }
        .ch-title{font-size:14px;font-weight:600;color:var(--t1);}
        .card-body{padding:18px;}

        /* STUDENT ROW */
        .student-row{display:flex;align-items:center;gap:14px;}
        .avatar{
            width:50px;height:50px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
            font-size:17px;font-weight:700;color:#fff;
            flex-shrink:0;letter-spacing:.5px;
        }
        .s-name{font-size:16px;font-weight:600;color:var(--t1);line-height:1.3;}
        .s-email{font-size:13px;color:var(--t2);margin-top:3px;display:flex;align-items:center;gap:5px;}

        /* PILLS */
        .pill-row{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);}
        .pill{
            display:inline-flex;align-items:center;gap:5px;
            padding:3px 10px;border-radius:20px;
            font-size:12px;font-weight:500;letter-spacing:.15px;
        }
        .p-blue  {background:#E8F0FE;color:#1967D2;}
        .p-green {background:#E6F4EA;color:#137333;}
        .p-red   {background:#FCE8E6;color:#C5221F;}
        .p-amber {background:#FEF7E0;color:#B06000;}
        .p-gray  {background:#F1F3F4;color:#5F6368;}

        /* DETAIL LIST */
        .detail-list{display:flex;flex-direction:column;gap:13px;}
        .detail-row{display:flex;gap:11px;align-items:flex-start;}
        .dr-icon{
            width:30px;height:30px;border-radius:7px;
            background:var(--bg);border:1px solid var(--border);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;color:var(--t2);flex-shrink:0;margin-top:1px;
        }
        .dr-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.65px;color:var(--t3);margin-bottom:3px;}
        .dr-value{font-size:14px;color:var(--t1);line-height:1.55;}

        /* DIVIDER + SECTION LABEL */
        .divider{border:none;border-top:1px solid var(--border);margin:16px 0;}
        .sec-lbl{
            font-size:11px;font-weight:600;text-transform:uppercase;
            letter-spacing:.65px;color:var(--t3);margin-bottom:9px;
            display:flex;align-items:center;gap:5px;
        }

        /* ATTACHMENTS */
        .attach-list{display:flex;flex-direction:column;gap:7px;}
        .attach-item{
            display:flex;align-items:center;gap:11px;
            padding:9px 13px;border:1px solid var(--border);
            border-radius:var(--r);text-decoration:none;color:var(--t1);
            transition:all .15s;background:var(--surface);
        }
        .attach-item:hover{border-color:var(--theme);background:var(--theme-lt);}
        .a-icon{
            width:32px;height:32px;border-radius:var(--r-sm);
            display:flex;align-items:center;justify-content:center;
            font-size:14px;flex-shrink:0;
        }
        .a-pdf  {background:#FCE8E6;color:#C5221F;}
        .a-doc  {background:#E8F0FE;color:#1967D2;}
        .a-img  {background:#E6F4EA;color:#137333;}
        .a-zip  {background:#FEF7E0;color:#B06000;}
        .a-file {background:#F1F3F4;color:#5F6368;}
        .a-name{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .a-size{font-size:11px;color:var(--t3);margin-top:1px;}

        /* RESPONSE */
        .response-box{
            background:var(--bg);border:1px solid var(--border);
            border-radius:var(--r);padding:15px 17px;
            font-size:14px;color:var(--t1);line-height:1.8;white-space:pre-wrap;
        }
        .empty-resp{text-align:center;padding:36px 20px;color:var(--t3);}
        .empty-resp svg{display:block;margin:0 auto 11px;opacity:.4;}
        .empty-resp p{font-size:13px;}

        /* SIDEBAR */
        .sidebar{position:sticky;top:calc(var(--nav-h) + 18px);}

        /* GRADE DISPLAY */
        .grade-box{
            border-radius:var(--r);padding:18px 20px;
            margin-bottom:16px;
            background:var(--theme);
            position:relative;overflow:hidden;
        }
        .grade-box::after{
            content:'';position:absolute;inset:0;
            background:linear-gradient(130deg,rgba(255,255,255,.1) 0%,transparent 55%);
        }
        .gb-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.75px;color:rgba(255,255,255,.7);margin-bottom:5px;position:relative;z-index:1;}
        .gb-score{font-size:44px;font-weight:700;color:#fff;font-variant-numeric:tabular-nums;line-height:1;position:relative;z-index:1;}
        .gb-score span{font-size:18px;font-weight:400;opacity:.7;margin-left:2px;}
        .gb-date{font-size:11px;color:rgba(255,255,255,.6);margin-top:8px;display:flex;align-items:center;gap:4px;position:relative;z-index:1;}
        .gb-feedback{
            margin-top:13px;padding-top:13px;
            border-top:1px solid rgba(255,255,255,.18);
            font-size:13px;color:rgba(255,255,255,.88);
            line-height:1.6;position:relative;z-index:1;
        }
        .gb-feedback strong{display:block;margin-bottom:3px;color:#fff;}

        /* INFO BANNER */
        .info-banner{
            display:flex;gap:9px;align-items:flex-start;
            padding:11px 13px;border-radius:var(--r);
            background:var(--theme-lt);border:1px solid var(--theme-mid);
            margin-bottom:16px;
        }
        .info-banner i{color:var(--theme);font-size:13px;margin-top:2px;flex-shrink:0;}
        .info-banner p{font-size:13px;color:var(--t1);line-height:1.55;}

        /* FORM */
        .form-field{margin-bottom:14px;}
        .f-label{
            display:block;font-size:11px;font-weight:600;
            color:var(--t2);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px;
        }
        .f-input{
            width:100%;padding:9px 13px;
            border:1px solid var(--border-2);border-radius:var(--r);
            font-family:var(--font);font-size:14px;color:var(--t1);
            background:var(--surface);transition:border .15s,box-shadow .15s;
            appearance:none;
        }
        .f-input:focus{outline:none;border-color:var(--theme);box-shadow:0 0 0 3px var(--theme-lt);}
        textarea.f-input{min-height:100px;resize:vertical;line-height:1.6;}
        .score-wrap{position:relative;display:flex;align-items:center;}
        .score-input{
            font-size:30px;font-weight:700;font-family:var(--mono);
            text-align:center;padding:11px 65px 11px 13px;letter-spacing:-1px;
        }
        .score-sfx{position:absolute;right:13px;font-size:14px;font-weight:500;color:var(--t3);pointer-events:none;}

        /* BUTTONS */
        .btn-col{display:flex;flex-direction:column;gap:7px;}
        .btn{
            width:100%;height:38px;border-radius:var(--r);border:none;
            font-family:var(--font);font-size:13px;font-weight:600;
            cursor:pointer;display:flex;align-items:center;justify-content:center;
            gap:7px;transition:all .15s;letter-spacing:.1px;
        }
        .btn-primary{background:var(--theme);color:#fff;}
        .btn-primary:hover{filter:brightness(1.07);box-shadow:0 2px 8px var(--theme-mid);}
        .btn-ghost{background:var(--surface);color:var(--t2);border:1px solid var(--border-2);}
        .btn-ghost:hover{background:var(--bg);border-color:var(--t3);color:var(--t1);}

        /* ALERT */
        .alert{
            padding:9px 13px;border-radius:var(--r);
            margin-bottom:14px;font-size:13px;
            display:flex;align-items:center;gap:7px;
        }
        .a-ok {background:#E6F4EA;color:#137333;border:1px solid #CEEAD6;}
        .a-err{background:#FCE8E6;color:#C5221F;border:1px solid #F5C6C6;}

        /* RESPONSIVE */
        @media(max-width:880px){
            .layout{grid-template-columns:1fr;}
            .sidebar{position:static;}
            .strip{display:none;}
        }
        @media(max-width:560px){
            .topbar{padding:0 12px;}
            .layout{padding:0 12px 36px;margin-top:14px;}
            .nav-btn .nav-lbl{display:none;}
        }
    </style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
    <button class="icon-btn" onclick="window.history.back()" title="Back">
        <i class="fas fa-arrow-left"></i>
    </button>
    <div class="topbar-meta">
        <div class="topbar-title"><?php echo htmlspecialchars($submission['assignment_title']); ?></div>
        <div class="topbar-sub"><?php echo htmlspecialchars($submission['subject_name']); ?></div>
    </div>
    <div class="topbar-right">
        <button class="nav-btn" <?php echo !$prev_submission ? 'disabled' : ''; ?>
                onclick="<?php echo $prev_submission ? "window.location.href='grade_submission.php?id={$prev_submission['id']}'" : ''; ?>">
            <i class="fas fa-chevron-left"></i><span class="nav-lbl"> Prev</span>
        </button>
        <span class="nav-count"><?php echo $current_index + 1; ?> / <?php echo count($all_submissions); ?></span>
        <button class="nav-btn" <?php echo !$next_submission ? 'disabled' : ''; ?>
                onclick="<?php echo $next_submission ? "window.location.href='grade_submission.php?id={$next_submission['id']}'" : ''; ?>">
            <span class="nav-lbl">Next </span><i class="fas fa-chevron-right"></i>
        </button>
    </div>
</header>

<!-- SUBJECT STRIP -->
<div class="strip">
    <span class="strip-text">Subject</span>
    <div class="strip-dot"></div>
    <span class="strip-name"><?php echo htmlspecialchars($submission['subject_name']); ?></span>
    <div class="strip-dot"></div>
    <span class="strip-text"><?php echo ucfirst($submission['assignment_type']); ?></span>
</div>

<!-- LAYOUT -->
<div class="layout">

    <!-- MAIN COLUMN -->
    <div>

        <!-- Student Card -->
        <div class="card">
            <div class="card-body">
                <div class="student-row">
                    <div class="avatar" style="background:<?php echo $avatar_bg; ?>">
                        <?php echo htmlspecialchars($initials); ?>
                    </div>
                    <div>
                        <div class="s-name"><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></div>
                        <div class="s-email">
                            <i class="fas fa-envelope" style="font-size:11px;"></i>
                            <?php echo htmlspecialchars($submission['student_email']); ?>
                        </div>
                    </div>
                </div>
                <div class="pill-row">
                    <?php
                    $s = $submission['status'];
                    $pc = $s === 'graded' ? 'p-green' : ($s === 'returned' ? 'p-amber' : 'p-blue');
                    $pi = $s === 'graded' ? 'check-circle' : ($s === 'returned' ? 'undo' : 'clock');
                    ?>
                    <span class="pill <?php echo $pc; ?>">
                        <i class="fas fa-<?php echo $pi; ?>"></i>
                        <?php echo ucfirst($s); ?>
                    </span>
                    <?php if ($is_late): ?>
                    <span class="pill p-red"><i class="fas fa-exclamation-circle"></i> Turned in late</span>
                    <?php endif; ?>
                    <span class="pill p-gray">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo date('M j, Y · g:i A', strtotime($submission['submitted_at'])); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Assignment Details -->
        <div class="card">
            <div class="card-head">
                <div class="ch-icon"><i class="fas fa-clipboard-list"></i></div>
                <span class="ch-title">Assignment Details</span>
            </div>
            <div class="card-body">
                <div class="detail-list">
                    <?php if ($submission['instructions']): ?>
                    <div class="detail-row">
                        <div class="dr-icon"><i class="fas fa-align-left"></i></div>
                        <div>
                            <div class="dr-label">Instructions</div>
                            <div class="dr-value"><?php echo nl2br(htmlspecialchars($submission['instructions'])); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <div class="dr-icon"><i class="fas fa-star-half-alt"></i></div>
                        <div>
                            <div class="dr-label">Points</div>
                            <div class="dr-value"><?php echo $submission['max_points']; ?> pts</div>
                        </div>
                    </div>
                    <?php if ($submission['due_date']): ?>
                    <div class="detail-row">
                        <div class="dr-icon"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="dr-label">Due Date</div>
                            <div class="dr-value"><?php echo date('l, F j, Y \a\t g:i A', strtotime($submission['due_date'])); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <div class="dr-icon"><i class="fas fa-tag"></i></div>
                        <div>
                            <div class="dr-label">Type</div>
                            <div class="dr-value"><?php echo ucfirst($submission['assignment_type']); ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($assignment_attachments)): ?>
                <hr class="divider">
                <div class="sec-lbl"><i class="fas fa-paperclip"></i> Assignment Materials</div>
                <div class="attach-list">
                    <?php foreach ($assignment_attachments as $att):
                        $ext = strtolower(pathinfo($att['file_name'], PATHINFO_EXTENSION));
                        $ic  = in_array($ext,['pdf']) ? 'a-pdf' : (in_array($ext,['doc','docx']) ? 'a-doc' : (in_array($ext,['jpg','jpeg','png','gif']) ? 'a-img' : (in_array($ext,['zip','rar']) ? 'a-zip' : 'a-file')));
                        $fa  = $ic==='a-pdf' ? 'file-pdf' : ($ic==='a-doc' ? 'file-word' : ($ic==='a-img' ? 'file-image' : ($ic==='a-zip' ? 'file-archive' : 'file')));
                    ?>
                    <a href="../<?php echo htmlspecialchars($att['file_path']); ?>" class="attach-item" target="_blank">
                        <div class="a-icon <?php echo $ic; ?>"><i class="fas fa-<?php echo $fa; ?>"></i></div>
                        <div style="min-width:0;flex:1;">
                            <div class="a-name"><?php echo htmlspecialchars($att['file_name']); ?></div>
                            <div class="a-size"><?php echo round($att['file_size']/1024,1); ?> KB</div>
                        </div>
                        <i class="fas fa-external-link-alt" style="color:var(--t3);font-size:11px;flex-shrink:0;"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Student Response -->
        <div class="card">
            <div class="card-head">
                <div class="ch-icon"><i class="fas fa-file-alt"></i></div>
                <span class="ch-title">Student Response</span>
            </div>
            <div class="card-body">
                <?php if ($submission['submission_text']): ?>
                <div class="response-box"><?php echo nl2br(htmlspecialchars($submission['submission_text'])); ?></div>
                <?php else: ?>
                <div class="empty-resp">
                    <svg width="52" height="52" viewBox="0 0 52 52" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="8" y="6" width="36" height="40" rx="4" fill="#E8EAED"/>
                        <rect x="14" y="16" width="24" height="3" rx="1.5" fill="#BDC1C6"/>
                        <rect x="14" y="23" width="18" height="3" rx="1.5" fill="#BDC1C6"/>
                        <rect x="14" y="30" width="21" height="3" rx="1.5" fill="#BDC1C6"/>
                    </svg>
                    <p>No written response provided</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($attachments)): ?>
                <hr class="divider">
                <div class="sec-lbl"><i class="fas fa-paperclip"></i> Submitted Files</div>
                <div class="attach-list">
                    <?php foreach ($attachments as $att):
                        $ext = strtolower(pathinfo($att['file_name'], PATHINFO_EXTENSION));
                        $ic  = in_array($ext,['pdf']) ? 'a-pdf' : (in_array($ext,['doc','docx']) ? 'a-doc' : (in_array($ext,['jpg','jpeg','png','gif']) ? 'a-img' : (in_array($ext,['zip','rar']) ? 'a-zip' : 'a-file')));
                        $fa  = $ic==='a-pdf' ? 'file-pdf' : ($ic==='a-doc' ? 'file-word' : ($ic==='a-img' ? 'file-image' : ($ic==='a-zip' ? 'file-archive' : 'file')));
                    ?>
                    <a href="../../<?php echo htmlspecialchars($att['file_path']); ?>" class="attach-item" target="_blank">
                        <div class="a-icon <?php echo $ic; ?>"><i class="fas fa-<?php echo $fa; ?>"></i></div>
                        <div style="min-width:0;flex:1;">
                            <div class="a-name"><?php echo htmlspecialchars($att['file_name']); ?></div>
                            <div class="a-size"><?php echo round($att['file_size']/1024,1); ?> KB</div>
                        </div>
                        <i class="fas fa-external-link-alt" style="color:var(--t3);font-size:11px;flex-shrink:0;"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /main col -->

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="card">
            <div class="card-head">
                <div class="ch-icon"><i class="fas fa-check-double"></i></div>
                <span class="ch-title">Grade</span>
            </div>
            <div class="card-body">

                <?php if (isset($success)): ?>
                <div class="alert a-ok"><i class="fas fa-check-circle"></i><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                <div class="alert a-err"><i class="fas fa-exclamation-circle"></i><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if ($submission['status'] === 'graded' && $submission['score'] !== null): ?>
                <div class="grade-box">
                    <div class="gb-label">Current Score</div>
                    <div class="gb-score"><?php echo number_format($submission['score'],0); ?><span>/ 100</span></div>
                    <?php if ($submission['teacher_feedback']): ?>
                    <div class="gb-feedback">
                        <strong>Feedback</strong>
                        <?php echo nl2br(htmlspecialchars($submission['teacher_feedback'])); ?>
                    </div>
                    <?php endif; ?>
                    <div class="gb-date">
                        <i class="fas fa-clock"></i>
                        Graded <?php echo date('M j, Y · g:i A', strtotime($submission['graded_at'])); ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i>
                    <p>Enter a score and optional feedback. The student will be notified once graded.</p>
                </div>
                <?php endif; ?>

                <form method="POST" id="gradeForm">
                    <div class="form-field">
                        <label class="f-label" for="score">Score</label>
                        <div class="score-wrap">
                            <input type="number" id="score" name="score"
                                   class="f-input score-input"
                                   min="0" max="100" step="0.01"
                                   value="<?php echo $submission['score'] ?? ''; ?>"
                                   placeholder="—" required>
                            <span class="score-sfx">/ 100</span>
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="f-label" for="feedback">
                            Feedback <span style="font-weight:400;color:var(--t3);text-transform:none;letter-spacing:0;">(optional)</span>
                        </label>
                        <textarea id="feedback" name="feedback" class="f-input"
                                  placeholder="Add private feedback for this student…"><?php echo htmlspecialchars($submission['teacher_feedback'] ?? ''); ?></textarea>
                    </div>
                    <div class="btn-col">
                        <button type="submit" name="action" value="grade" class="btn btn-primary">
                            <i class="fas fa-check"></i>
                            <?php echo $submission['status'] === 'graded' ? 'Update Grade' : 'Submit Grade'; ?>
                        </button>
                        <button type="submit" name="action" value="return" class="btn btn-ghost">
                            <i class="fas fa-undo"></i>
                            Return without Grade
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </aside>

</div><!-- /layout -->

<script>
    document.getElementById('gradeForm').addEventListener('submit', function(e) {
        const score = parseFloat(document.getElementById('score').value);
        if (isNaN(score) || score < 0 || score > 100) {
            e.preventDefault();
            alert('Please enter a valid score between 0 and 100.');
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.altKey && e.key === 'ArrowLeft') {
            <?php if ($prev_submission): ?>
            window.location.href = 'grade_submission.php?id=<?php echo $prev_submission['id']; ?>';
            <?php endif; ?>
        }
        if (e.altKey && e.key === 'ArrowRight') {
            <?php if ($next_submission): ?>
            window.location.href = 'grade_submission.php?id=<?php echo $next_submission['id']; ?>';
            <?php endif; ?>
        }
    });
</script>
</body>
</html>