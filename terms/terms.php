<?php
session_start();

$loggedIn = false;
$dashboardUrl = '';

if (isset($_SESSION['teacher_id'])) {
    $loggedIn = true;
    $dashboardUrl = 'amaya/teacher/teacher_dashb.php';
} else if (isset($_SESSION['student_id'])) {
    $loggedIn = true;
    $dashboardUrl = 'amaya/student/student_dashb.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="amaya/img/ashi.jpg" type="image/x-icon">
    <title>Terms & Conditions — ASHI System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

    <style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --ink:       #0f172a;
    --ink-soft:  #475569;
    --ink-muted: #94a3b8;
    --surface:   #ffffff;
    --off:       #f8fafc;
    --border:    #e2e8f0;
    --green:     #10b981;
    --green-d:   #059669;
    --indigo:    #6366f1;
    --radius:    14px;
    --nav-h:     68px;
}

body {
    font-family: 'DM Sans', sans-serif;
    color: var(--ink);
    background: var(--surface);
}

.serif { font-family: 'DM Serif Display', serif; }

.topbar {
    background: var(--ink);
    height: var(--nav-h);
    display:flex; align-items:center;
}
.topbar .container { display:flex; align-items:center; justify-content:space-between; }
.topbar-brand {
    display:flex; align-items:center; gap:10px;
    font-family:'DM Serif Display',serif;
    font-size:1.2rem; color:#fff; text-decoration:none;
}
.topbar-brand img { width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.25); }
.topbar-back {
    font-size:.875rem; font-weight:600; color:rgba(255,255,255,.85);
    text-decoration:none; display:flex; align-items:center; gap:8px;
    padding:8px 16px; border-radius:30px; border:1px solid rgba(255,255,255,.2);
    transition: background .25s, color .25s;
}
.topbar-back:hover { background:rgba(255,255,255,.1); color:#fff; }

.page-header {
    background: var(--off);
    border-bottom:1px solid var(--border);
    padding: 56px 0 40px;
}
.page-eyebrow {
    font-size:.75rem; font-weight:700; letter-spacing:.12em;
    text-transform:uppercase; color:var(--indigo); margin-bottom:10px;
}
.page-header h1 {
    font-family:'DM Serif Display',serif;
    font-size: clamp(1.9rem, 4vw, 2.6rem);
    margin-bottom:12px;
}
.page-header p { color:var(--ink-soft); font-size:.95rem; max-width:640px; }
.updated-badge {
    display:inline-flex; align-items:center; gap:8px;
    font-size:.8rem; color:var(--ink-muted);
    margin-top:16px;
}
.updated-badge i { color: var(--indigo); }

.policy-wrap { max-width: 780px; margin: 0 auto; padding: 56px 0 100px; }

.policy-nav {
    background: var(--surface);
    border:1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 22px 24px;
    margin-bottom: 48px;
}
.policy-nav h6 {
    font-size:.75rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
    color: var(--ink-muted); margin-bottom:14px;
}
.policy-nav ol { list-style:none; padding:0; counter-reset: item; }
.policy-nav li { counter-increment: item; margin-bottom:8px; }
.policy-nav li a {
    font-size:.9rem; color:var(--ink-soft); text-decoration:none;
    display:flex; gap:10px; align-items:baseline;
}
.policy-nav li a::before {
    content: counter(item);
    font-family:'DM Serif Display',serif; color: var(--indigo);
    font-size:.85rem; width:18px;
}
.policy-nav li a:hover { color: var(--green-d); }

.policy-section { margin-bottom: 40px; scroll-margin-top: 90px; }
.policy-section h2 {
    font-family:'DM Serif Display',serif;
    font-size:1.4rem; margin-bottom:14px;
    display:flex; align-items:center; gap:12px;
}
.policy-section h2 .num {
    width:32px; height:32px; border-radius:50%;
    background: var(--green); color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:.85rem; font-family:'DM Sans',sans-serif; font-weight:700;
    flex-shrink:0;
}
.policy-section p, .policy-section li {
    font-size:.925rem; color: var(--ink-soft); line-height:1.75;
}
.policy-section ul { padding-left: 20px; margin-top:8px; margin-bottom:8px; }
.policy-section li { margin-bottom:6px; }
.policy-section p { margin-bottom:10px; }

.note-box {
    background: #eef2ff;
    border:1px solid #c7d2fe;
    border-radius: var(--radius);
    padding: 18px 20px;
    display:flex; gap:14px;
    margin-top:16px;
}
.note-box i { color: var(--indigo); font-size:1.1rem; margin-top:2px; }
.note-box p { color: #3730a3; font-size:.875rem; margin:0; }

.contact-box {
    background: var(--ink); color:#fff;
    border-radius: var(--radius);
    padding: 32px 28px;
    margin-top: 20px;
}
.contact-box h3 { font-family:'DM Serif Display',serif; font-size:1.2rem; margin-bottom:10px; }
.contact-box p { color:#cbd5e1; font-size:.9rem; margin-bottom:14px; }
.contact-box a { color: var(--green); text-decoration:none; font-weight:600; }

.footer-mini {
    background:#0f172a; color:#94a3b8; text-align:center;
    padding: 24px 0; font-size:.8rem;
}
.footer-mini a { color:#cbd5e1; text-decoration:none; }
.footer-mini a:hover { color: var(--green); }
    </style>
</head>
<body>

<div class="topbar">
    <div class="container">
        <a class="topbar-brand" href="../index.php">
            <img src="../amaya/img/ashi.jpg" alt="ASHI Logo"> ASHI System
        </a>
        <a class="topbar-back" href="../index.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
    </div>
</div>

<div class="page-header">
    <div class="container">
        <div class="page-eyebrow">Legal</div>
        <h1>Terms & Conditions</h1>
        <p>These Terms govern the use of the ASHI System, the digital task and workload management platform of Amaya School of Home Industries.</p>
        <div class="updated-badge"><i class="fas fa-circle"></i> Last updated: <?= date('F j, Y') ?></div>
    </div>
</div>

<div class="container">
    <div class="policy-wrap">

        <div class="policy-nav">
            <h6>On this page</h6>
            <ol>
                <li><a href="#acceptance">Acceptance of terms</a></li>
                <li><a href="#eligibility">Who may use this system</a></li>
                <li><a href="#accounts">Account responsibility</a></li>
                <li><a href="#conduct">Acceptable use</a></li>
                <li><a href="#content">Content & submissions</a></li>
                <li><a href="#grading">Tasks & grading</a></li>
                <li><a href="#suspension">Suspension & termination</a></li>
                <li><a href="#availability">System availability</a></li>
                <li><a href="#changes">Changes to these terms</a></li>
                <li><a href="#contact">Contact us</a></li>
            </ol>
        </div>

        <div class="policy-section" id="acceptance">
            <h2><span class="num">1</span> Acceptance of Terms</h2>
            <p>By logging into or using the ASHI System in any way, you agree to follow these Terms & Conditions. If you do not agree with them, please do not use the platform and inform the school administration.</p>
        </div>

        <div class="policy-section" id="eligibility">
            <h2><span class="num">2</span> Who May Use This System</h2>
            <p>The ASHI System is the exclusive property of the <strong>Amaya School of Home Industries</strong> and is intended solely for its academic community.</p>
            <ul>
                <li>Only individuals currently enrolled as students of ASHI may register and use a student account.</li>
                <li>Only individuals currently employed as teachers/faculty of ASHI may register and use a teacher account.</li>
                <li>All registrations require review and approval by a school administrator before access is granted.</li>
            </ul>
            <div class="note-box">
                <i class="fas fa-user-shield"></i>
                <p>Impersonating another user, registering under false information, or attempting to gain access without a genuine ASHI affiliation is strictly prohibited.</p>
            </div>
        </div>

        <div class="policy-section" id="accounts">
            <h2><span class="num">3</span> Account Responsibility</h2>
            <ul>
                <li>You are responsible for keeping your login credentials confidential.</li>
                <li>You are responsible for all activity that occurs under your account.</li>
                <li>Notify the school administrator immediately if you suspect unauthorized access to your account.</li>
                <li>Do not share your account with another person, including classmates or co-teachers.</li>
            </ul>
        </div>

        <div class="policy-section" id="conduct">
            <h2><span class="num">4</span> Acceptable Use</h2>
            <p>While using the ASHI System, you agree not to:</p>
            <ul>
                <li>Upload or submit content that is offensive, plagiarized, or violates school policy.</li>
                <li>Attempt to access another student's or teacher's account or data without permission.</li>
                <li>Interfere with or disrupt the normal operation of the system.</li>
                <li>Use the platform for any purpose unrelated to ASHI academic activities.</li>
            </ul>
        </div>

        <div class="policy-section" id="content">
            <h2><span class="num">5</span> Content & Submissions</h2>
            <p>Lecture materials uploaded by teachers remain the property of the respective teacher and/or ASHI, and are made available only for the academic use of enrolled students. Task outputs and submissions made by students remain the student's own work and are used by ASHI solely for grading and academic recordkeeping purposes.</p>
        </div>

        <div class="policy-section" id="grading">
            <h2><span class="num">6</span> Tasks & Grading</h2>
            <ul>
                <li>Teachers are responsible for creating tasks, setting deadlines, and recording grades accurately.</li>
                <li>Students are responsible for submitting their own task outputs on or before the given deadline.</li>
                <li>Grades displayed on the system reflect records entered by teachers and are subject to the school's official grading and appeal procedures.</li>
                <li>The ASHI System is a management tool and does not replace the school's official academic policies.</li>
            </ul>
        </div>

        <div class="policy-section" id="suspension">
            <h2><span class="num">7</span> Suspension & Termination</h2>
            <p>ASHI administration reserves the right to suspend or terminate any account that:</p>
            <ul>
                <li>Was registered with false or unauthorized information.</li>
                <li>Violates any part of these Terms or the school's code of conduct.</li>
                <li>Belongs to a student or teacher no longer affiliated with ASHI (e.g., graduated, transferred, resigned).</li>
            </ul>
        </div>

        <div class="policy-section" id="availability">
            <h2><span class="num">8</span> System Availability</h2>
            <p>While we aim to keep the ASHI System accessible at all times, occasional downtime may occur for maintenance, updates, or technical issues. We are not liable for any inconvenience caused by such interruptions.</p>
        </div>

        <div class="policy-section" id="changes">
            <h2><span class="num">9</span> Changes to These Terms</h2>
            <p>These Terms may be updated from time to time to reflect changes in school policy or system features. Continued use of the ASHI System after updates are posted constitutes acceptance of the revised Terms.</p>
        </div>

        <div class="policy-section" id="contact">
            <h2><span class="num">10</span> Contact Us</h2>
            <p>For questions about these Terms & Conditions, please reach out to the ASHI administration office.</p>
            <div class="contact-box">
                <h3>Amaya School of Home Industries</h3>
                <p>Sahud-Ulan, Tanza, Cavite, Philippines</p>
                <p>Email: <a href="mailto:depedcavite.ashi@gmail.com">depedcavite.ashi@gmail.com</a></p>
            </div>
        </div>

    </div>
</div>

<div class="footer-mini">
    &copy; <?= date('Y') ?> ASHI School Management System &nbsp;|&nbsp; <a href="privacy.php">Privacy Policy</a> &nbsp;|&nbsp; <a href="../index.php">Home</a>
</div>

</body>
</html>
