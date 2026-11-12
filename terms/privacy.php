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
    <title>Privacy Policy — ASHI System</title>
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

/* ── Simple top bar (static, not the animated hero navbar) ── */
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

/* ── Page header ── */
.page-header {
    background: var(--off);
    border-bottom:1px solid var(--border);
    padding: 56px 0 40px;
}
.page-eyebrow {
    font-size:.75rem; font-weight:700; letter-spacing:.12em;
    text-transform:uppercase; color:var(--green); margin-bottom:10px;
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
.updated-badge i { color:var(--green); }

/* ── Content ── */
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
    font-family:'DM Serif Display',serif; color: var(--green);
    font-size:.85rem; width:18px;
}
.policy-nav li a:hover { color: var(--indigo); }

.policy-section { margin-bottom: 40px; scroll-margin-top: 90px; }
.policy-section h2 {
    font-family:'DM Serif Display',serif;
    font-size:1.4rem; margin-bottom:14px;
    display:flex; align-items:center; gap:12px;
}
.policy-section h2 .num {
    width:32px; height:32px; border-radius:50%;
    background: var(--indigo); color:#fff;
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
    background: #f0fdf4;
    border:1px solid #bbf7d0;
    border-radius: var(--radius);
    padding: 18px 20px;
    display:flex; gap:14px;
    margin-top:16px;
}
.note-box i { color: var(--green-d); font-size:1.1rem; margin-top:2px; }
.note-box p { color: #166534; font-size:.875rem; margin:0; }

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
        <h1>Privacy Policy</h1>
        <p>This page explains what information the ASHI System collects from its students and teachers, how it is used, and how it is protected.</p>
        <div class="updated-badge"><i class="fas fa-circle"></i> Last updated: <?= date('F j, Y') ?></div>
    </div>
</div>

<div class="container">
    <div class="policy-wrap">

        <div class="policy-nav">
            <h6>On this page</h6>
            <ol>
                <li><a href="#scope">Who this policy covers</a></li>
                <li><a href="#collect">Information we collect</a></li>
                <li><a href="#use">How we use your information</a></li>
                <li><a href="#storage">Data storage & security</a></li>
                <li><a href="#sharing">Sharing of information</a></li>
                <li><a href="#access">Account access & approval</a></li>
                <li><a href="#rights">Your rights</a></li>
                <li><a href="#retention">Data retention</a></li>
                <li><a href="#contact">Contact us</a></li>
            </ol>
        </div>

        <div class="policy-section" id="scope">
            <h2><span class="num">1</span> Who this Policy Covers</h2>
            <p>The ASHI System is a private, internal platform built exclusively for the <strong>Amaya School of Home Industries (ASHI)</strong>. It is not open to the general public.</p>
            <ul>
                <li>Only currently enrolled ASHI students, verified and approved by an administrator, may hold a student account.</li>
                <li>Only ASHI faculty members, verified and approved by an administrator, may hold a teacher account.</li>
                <li>Access is not available to individuals outside of the ASHI community.</li>
            </ul>
        </div>

        <div class="policy-section" id="collect">
            <h2><span class="num">2</span> Information We Collect</h2>
            <p>To operate the platform, we collect and store the following information:</p>
            <ul>
                <li><strong>Identity information</strong> — full name, student/teacher ID number, year level or subject assignment, and contact details submitted during registration.</li>
                <li><strong>Account credentials</strong> — username/email and a securely stored password.</li>
                <li><strong>Academic records</strong> — subject enrollments, submitted task/activity outputs, attachments, and grades.</li>
                <li><strong>Activity data</strong> — task creation and submission timestamps, login sessions, and system usage needed to keep the platform running smoothly.</li>
            </ul>
        </div>

        <div class="policy-section" id="use">
            <h2><span class="num">3</span> How We Use Your Information</h2>
            <p>Information collected is used strictly to operate the school's academic workflow, including to:</p>
            <ul>
                <li>Create and manage student and teacher accounts.</li>
                <li>Enroll students into the correct subjects and classes.</li>
                <li>Allow teachers to post tasks/activities and lecture materials, and to record grades.</li>
                <li>Allow students to view, complete, and submit assigned tasks, and to check their own grades.</li>
                <li>Send system notifications such as deadline reminders and submission alerts.</li>
                <li>Maintain the security and integrity of the platform.</li>
            </ul>
        </div>

        <div class="policy-section" id="storage">
            <h2><span class="num">4</span> Data Storage & Security</h2>
            <p>Your account and academic data are stored on ASHI's system database and protected through role-based authentication, encrypted sessions, and admin-controlled account approval.</p>
            <div class="note-box">
                <i class="fas fa-shield-alt"></i>
                <p>We take reasonable technical measures to safeguard your information. However, no online system can guarantee absolute security, and users are encouraged to keep their login credentials confidential.</p>
            </div>
        </div>

        <div class="policy-section" id="sharing">
            <h2><span class="num">5</span> Sharing of Information</h2>
            <p>Your information is used only within the ASHI System and is not sold or shared with outside companies or advertisers. Data may only be visible to:</p>
            <ul>
                <li>You, for your own account.</li>
                <li>Teachers, for students enrolled in their subject (tasks, submissions, and grades).</li>
                <li>School administrators, for approving accounts and maintaining academic records.</li>
            </ul>
        </div>

        <div class="policy-section" id="access">
            <h2><span class="num">6</span> Account Access & Approval</h2>
            <p>All new registrations are reviewed by a school administrator before access is granted. This step exists to confirm that only actual ASHI students and teachers are able to use the system. Accounts found to be falsified, unauthorized, or misused may be suspended or removed.</p>
        </div>

        <div class="policy-section" id="rights">
            <h2><span class="num">7</span> Your Rights</h2>
            <p>As a user of the ASHI System, you may:</p>
            <ul>
                <li>Request to view the personal information currently on file for your account.</li>
                <li>Request correction of inaccurate personal details (e.g., misspelled name).</li>
                <li>Request account deactivation upon graduation, resignation, or transfer, subject to the school's record-keeping requirements.</li>
            </ul>
            <p>Requests can be made through the school administrator listed in the Contact section below.</p>
        </div>

        <div class="policy-section" id="retention">
            <h2><span class="num">8</span> Data Retention</h2>
            <p>Academic records such as grades and submitted work may be retained in line with the school's standard record-keeping practices, even after an account becomes inactive, for academic and reporting purposes.</p>
        </div>

        <div class="policy-section" id="contact">
            <h2><span class="num">9</span> Contact Us</h2>
            <p>If you have questions or requests regarding this Privacy Policy or your data, please reach out to the ASHI administration office.</p>
            <div class="contact-box">
                <h3>Amaya School of Home Industries</h3>
                <p>Sahud-Ulan, Tanza, Cavite, Philippines</p>
                <p>Email: <a href="mailto:depedcavite.ashi@gmail.com">depedcavite.ashi@gmail.com</a></p>
            </div>
        </div>

    </div>
</div>

<div class="footer-mini">
    &copy; <?= date('Y') ?> ASHI School Management System &nbsp;|&nbsp; <a href="terms.php">Terms & Conditions</a> &nbsp;|&nbsp; <a href="../index.php">Home</a>
</div>

</body>
</html>
