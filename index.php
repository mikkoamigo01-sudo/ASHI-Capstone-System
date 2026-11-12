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
    <title>ASHI — School Task & Workload Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

    <style>
/* ── Reset & Tokens ─────────────────────────────────── */
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
    --indigo-d:  #4f46e5;
    --radius:    14px;
    --nav-h:     68px;
}

html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    color: var(--ink);
    background: var(--surface);
    overflow-x: hidden;
}

/* ── Utility ─────────────────────────────────────────── */
.serif { font-family: 'DM Serif Display', serif; }

/* ── Scrolled navbar state ───────────────────────────── */
.navbar {
    position: fixed; top:0; left:0; right:0; z-index:1000;
    padding: 0;
    height: var(--nav-h);
    background: transparent;
    transition: background .35s ease, box-shadow .35s ease, height .35s ease;
}
.navbar.scrolled {
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 1px 0 var(--border), 0 4px 24px rgba(0,0,0,.06);
    height: 60px;
}
.navbar .container {
    display:flex; align-items:center; justify-content:space-between;
    height:100%;
}
.navbar-brand {
    display:flex; align-items:center; gap:10px;
    font-family:'DM Serif Display',serif;
    font-size:1.25rem; color:#fff;
    text-decoration:none;
    transition: color .3s;
}
.navbar.scrolled .navbar-brand { color: var(--ink); }
.navbar-brand img {
    width:38px; height:38px; border-radius:50%; object-fit:cover;
    border: 2px solid rgba(255,255,255,.4);
    transition: transform .3s, border-color .3s;
}
.navbar.scrolled .navbar-brand img { border-color: var(--border); }
.navbar-brand:hover img { transform: rotate(6deg) scale(1.06); }

.nav-links { display:flex; align-items:center; gap:4px; }
.nav-link-item {
    font-size:.875rem; font-weight:500;
    color: rgba(255,255,255,.85);
    padding: 6px 14px; border-radius:8px;
    text-decoration:none;
    transition: color .25s, background .25s;
}
.nav-link-item:hover { color:#fff; background:rgba(255,255,255,.12); }
.navbar.scrolled .nav-link-item { color: var(--ink-soft); }
.navbar.scrolled .nav-link-item:hover { color: var(--ink); background: var(--off); }

.btn-nav {
    display:inline-flex; align-items:center; gap:7px;
    padding: 8px 22px; border-radius:30px; border:none;
    font-family:'DM Sans',sans-serif; font-size:.875rem; font-weight:600;
    background: var(--green); color:#fff;
    cursor:pointer;
    transition: background .25s, transform .2s, box-shadow .25s;
}
.btn-nav:hover {
    background: var(--green-d);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(16,185,129,.35);
}
.navbar-toggler {
    border:none; background:none; color:#fff; font-size:1.4rem; cursor:pointer;
    display:none;
}
.navbar.scrolled .navbar-toggler { color: var(--ink); }

/* ── Hero ─────────────────────────────────────────────── */
.hero {
    position:relative;
    min-height:100vh;
    display:flex; align-items:center;
    overflow:hidden;
}

/* Image slideshow */
.hero-slides {
    position:absolute; inset:0; z-index:0;
}
.hero-slide {
    position:absolute; inset:0;
    background-size:cover; background-position:center;
    opacity:0;
    transition: opacity 1.4s ease;
}
.hero-slide.active { opacity:1; }
.hero-slide:nth-child(1) { background-image: url('https://images.unsplash.com/photo-1580582932707-520aed937b7b?w=1600&q=80'); }
.hero-slide:nth-child(2) { background-image: url('amaya/img/amaya.jpg'); }
.hero-slide:nth-child(3) { background-image: url('amaya/img/bg.jpg'); }

.hero-overlay {
    position:absolute; inset:0; z-index:1;
    background: linear-gradient(135deg, rgba(10,20,40,.78) 0%, rgba(10,20,60,.55) 60%, transparent 100%);
}

/* Wave bottom */
.hero-wave {
    position:absolute; bottom:-1px; left:0; right:0; z-index:2;
    line-height:0;
}
.hero-wave svg { display:block; width:100%; height:90px; }

.hero-body {
    position:relative; z-index:3;
    padding: calc(var(--nav-h) + 60px) 0 120px;
    width:100%;
}

.hero-badge {
    display:inline-flex; align-items:center; gap:8px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2);
    backdrop-filter: blur(6px);
    color:#fff; font-size:.8rem; font-weight:600;
    letter-spacing:.08em; text-transform:uppercase;
    padding: 6px 16px; border-radius:30px;
    margin-bottom:22px;
    opacity:0; animation: riseUp .7s ease .1s forwards;
}
.hero-badge span { width:7px; height:7px; border-radius:50%; background:var(--green); display:inline-block; }

.hero h1 {
    font-family:'DM Serif Display',serif;
    font-size: clamp(2.6rem, 5.5vw, 4.2rem);
    line-height:1.15;
    color:#fff;
    margin-bottom:22px;
    opacity:0; animation: riseUp .8s ease .25s forwards;
}
.hero h1 em {
    font-style:italic;
    background: linear-gradient(90deg, #a5f3d0, #6ee7b7);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}

.hero-sub {
    font-size:1.05rem; color:rgba(255,255,255,.78); line-height:1.75;
    max-width:480px; margin-bottom:36px;
    opacity:0; animation: riseUp .8s ease .4s forwards;
}

.hero-ctas {
    display:flex; flex-wrap:wrap; gap:14px;
    opacity:0; animation: riseUp .8s ease .55s forwards;
}

.btn-primary-cta {
    display:inline-flex; align-items:center; gap:9px;
    background:#fff; color:var(--indigo);
    padding:13px 30px; border-radius:30px; border:none;
    font-family:'DM Sans',sans-serif; font-weight:600; font-size:.95rem;
    cursor:pointer;
    transition: transform .25s, box-shadow .25s, background .25s;
}
.btn-primary-cta:hover {
    transform:translateY(-3px);
    box-shadow:0 12px 30px rgba(0,0,0,.2);
    background:#f0fdf4; color:var(--green-d);
}

.btn-ghost-cta {
    display:inline-flex; align-items:center; gap:9px;
    background:transparent; color:#fff;
    border:1.5px solid rgba(255,255,255,.4);
    padding:13px 30px; border-radius:30px;
    font-family:'DM Sans',sans-serif; font-weight:600; font-size:.95rem;
    cursor:pointer;
    transition: border-color .25s, background .25s, transform .25s;
}
.btn-ghost-cta:hover {
    border-color:#fff; background:rgba(255,255,255,.1);
    transform:translateY(-3px);
}

/* slide indicators */
.slide-dots {
    position:absolute; bottom:110px; left:50%; transform:translateX(-50%);
    z-index:4; display:flex; gap:8px;
}
.slide-dot {
    width:8px; height:8px; border-radius:30px;
    background:rgba(255,255,255,.35); border:none; cursor:pointer; padding:0;
    transition: width .35s, background .35s;
}
.slide-dot.active { width:24px; background:#fff; }

/* ── Stats strip ─────────────────────────────────────── */
.stats-strip {
    background: var(--ink);
    padding: 28px 0;
}
.stat-item { text-align:center; }
.stat-num {
    font-family:'DM Serif Display',serif;
    font-size:2rem; color:#fff;
    line-height:1.1;
}
.stat-num span { color: var(--green); }
.stat-label { font-size:.8rem; color:var(--ink-muted); letter-spacing:.06em; text-transform:uppercase; margin-top:2px; }
.stat-divider { width:1px; background:rgba(255,255,255,.1); }

/* ── Sections shared ─────────────────────────────────── */
.section { padding: 96px 0; }
.section-alt { background: var(--off); }

.eyebrow {
    font-size:.75rem; font-weight:700; letter-spacing:.12em;
    text-transform:uppercase; color:var(--green);
    margin-bottom:12px;
}
.section-heading {
    font-family:'DM Serif Display',serif;
    font-size: clamp(1.9rem, 3.5vw, 2.8rem);
    line-height:1.2; color:var(--ink);
    margin-bottom:14px;
}
.section-sub {
    color:var(--ink-soft); font-size:1rem; line-height:1.7;
    max-width:520px;
}

/* ── Feature cards ───────────────────────────────────── */
.feat-grid { display:grid; grid-template-columns: repeat(3,1fr); gap:20px; }
@media(max-width:900px){ .feat-grid{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:560px){ .feat-grid{ grid-template-columns:1fr; } }

.feat-card {
    background:var(--surface);
    border:1.5px solid var(--border);
    border-radius:var(--radius);
    padding:32px 28px;
    transition: border-color .3s, transform .3s, box-shadow .3s;
}
.feat-card:hover {
    border-color: var(--indigo);
    transform:translateY(-6px);
    box-shadow: 0 16px 40px rgba(99,102,241,.1);
}
.feat-icon {
    width:52px; height:52px; border-radius:12px;
    background: linear-gradient(135deg, var(--indigo), #818cf8);
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:1.3rem;
    margin-bottom:20px;
    transition: transform .3s;
}
.feat-card:hover .feat-icon { transform: scale(1.08) rotate(4deg); }
.feat-card h4 {
    font-size:1.05rem; font-weight:600; margin-bottom:10px;
}
.feat-card p { font-size:.9rem; color:var(--ink-soft); line-height:1.65; }

/* ── Steps ───────────────────────────────────────────── */
.steps-row { display:grid; grid-template-columns:repeat(3,1fr); gap:0; position:relative; }
.steps-row::before {
    content:'';
    position:absolute; top:28px; left:16.66%; right:16.66%;
    height:1.5px; background:var(--border); z-index:0;
}
@media(max-width:768px){
    .steps-row{ grid-template-columns:1fr; }
    .steps-row::before{ display:none; }
}
.step-item { text-align:center; padding:0 20px; position:relative; z-index:1; }
.step-circle {
    width:56px; height:56px; border-radius:50%;
    background: var(--surface); border:2px solid var(--indigo);
    color:var(--indigo); font-family:'DM Serif Display',serif;
    font-size:1.4rem; display:flex; align-items:center; justify-content:center;
    margin:0 auto 20px;
    transition: background .3s, color .3s, transform .3s;
}
.step-item:hover .step-circle {
    background:var(--indigo); color:#fff; transform:scale(1.1);
}
.step-item h5 { font-size:1rem; font-weight:600; margin-bottom:8px; }
.step-item p { font-size:.875rem; color:var(--ink-soft); line-height:1.65; }

/* ── Roles ───────────────────────────────────────────── */
.role-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
@media(max-width:900px){ .role-cards{ grid-template-columns:1fr; } }

.role-card {
    border:1.5px solid var(--border);
    border-radius:var(--radius);
    padding:36px 30px;
    transition: border-color .3s, box-shadow .3s, transform .3s;
    background:var(--surface);
}
.role-card:hover {
    border-color:var(--green); transform:translateY(-5px);
    box-shadow:0 20px 50px rgba(16,185,129,.1);
}
.role-emoji { font-size:2.6rem; margin-bottom:18px; display:block; transition:transform .3s; }
.role-card:hover .role-emoji { transform: scale(1.12); }
.role-card h3 { font-family:'DM Serif Display',serif; font-size:1.45rem; margin-bottom:18px; }
.role-card ul { list-style:none; padding:0; }
.role-card ul li {
    font-size:.9rem; color:var(--ink-soft);
    padding:7px 0; border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:10px;
}
.role-card ul li:last-child { border-bottom:none; }
.role-card ul li i { color:var(--green); font-size:.75rem; flex-shrink:0; }

/* ── Map section ─────────────────────────────────────── */
.map-section { background:var(--off); }
.map-wrap {
    border-radius:var(--radius); overflow:hidden;
    box-shadow:0 8px 30px rgba(0,0,0,.1);
    border:1.5px solid var(--border);
    height:420px;
}
.map-wrap iframe { width:100%; height:100%; border:none; display:block; }
.contact-item { display:flex; gap:14px; margin-bottom:22px; align-items:flex-start; }
.contact-icon {
    width:40px; height:40px; border-radius:10px;
    background:var(--green); color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:.9rem; flex-shrink:0; margin-top:2px;
}
.contact-item strong { display:block; font-size:.875rem; margin-bottom:2px; }
.contact-item span, .contact-item a { font-size:.875rem; color:var(--ink-soft); text-decoration:none; }

/* ── CTA banner ──────────────────────────────────────── */
.cta-banner {
    position:relative; overflow:hidden;
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f2027 100%);
    padding: 100px 0 80px;
    text-align:center;
}
.cta-banner::before {
    content:'';
    position:absolute; top:0; left:0; right:0;
    height:100px;
    background:url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none"><path d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z" fill="%23f8fafc"/></svg>') no-repeat;
    background-size:cover;
}
.cta-banner::after {
    content:'';
    position:absolute; bottom:0; left:0; right:0;
    height:100px;
    background:url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none"><path d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z" fill="%230f172a"/></svg>') no-repeat;
    background-size:cover; transform:rotate(180deg);
}
.cta-banner .inner { position:relative; z-index:1; }
.cta-banner h2 {
    font-family:'DM Serif Display',serif;
    font-size:clamp(2rem,4vw,3rem); color:#fff; margin-bottom:14px;
}
.cta-banner p { color:rgba(255,255,255,.65); font-size:1rem; margin-bottom:30px; }
.btn-cta-green {
    display:inline-flex; align-items:center; gap:9px;
    background:var(--green); color:#fff;
    padding:14px 36px; border-radius:30px; border:none;
    font-family:'DM Sans',sans-serif; font-weight:600; font-size:.95rem;
    cursor:pointer;
    transition: background .25s, transform .25s, box-shadow .25s;
}
.btn-cta-green:hover {
    background:var(--green-d); transform:translateY(-3px);
    box-shadow:0 10px 28px rgba(16,185,129,.35);
}

/* ── Footer ──────────────────────────────────────────── */
.footer {
    background: #0f172a;
    padding: 60px 0 0;
    color:#cbd5e1;
}
.footer-brand {
    font-family:'DM Serif Display',serif;
    font-size:1.35rem; color:#fff; margin-bottom:12px;
    display:flex; align-items:center; gap:10px;
}
.footer-brand img { width:42px; height:42px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.15); }
.footer p { font-size:.875rem; line-height:1.75; color:#94a3b8; }
.footer h6 { font-size:.75rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#fff; margin-bottom:18px; }
.footer ul { list-style:none; padding:0; }
.footer ul li { margin-bottom:10px; }
.footer ul li a {
    font-size:.875rem; color:#94a3b8; text-decoration:none;
    display:inline-flex; align-items:center; gap:7px;
    transition:color .25s, gap .25s;
}
.footer ul li a:hover { color: var(--green); gap:10px; }
.footer ul li a i { font-size:.7rem; }
.social-row { display:flex; gap:10px; margin-top:18px; }
.soc-btn {
    width:38px; height:38px; border-radius:50%;
    background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1);
    color:#cbd5e1; display:flex; align-items:center; justify-content:center;
    font-size:1rem; text-decoration:none;
    transition:background .3s, color .3s, transform .3s, box-shadow .3s;
}
.soc-btn:hover { transform:translateY(-4px); color:#fff; }
.soc-btn.fb:hover { background:#1877f2; border-color:#1877f2; box-shadow:0 8px 20px rgba(24,119,242,.4); }
.soc-btn.tw:hover { background:#1da1f2; border-color:#1da1f2; box-shadow:0 8px 20px rgba(29,161,242,.4); }
.soc-btn.ig:hover { background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); border-color:#dc2743; box-shadow:0 8px 20px rgba(220,39,67,.4); }
.soc-btn.yt:hover { background:#ff0000; border-color:#ff0000; box-shadow:0 8px 20px rgba(255,0,0,.4); }
.footer-bottom {
    margin-top:50px; padding:20px 0;
    border-top:1px solid rgba(255,255,255,.07);
    text-align:center; font-size:.8rem; color:#475569;
}
.footer-bottom i { color:#ef4444; }

/* ── Animations ──────────────────────────────────────── */
@keyframes riseUp {
    from { opacity:0; transform:translateY(22px); }
    to   { opacity:1; transform:translateY(0); }
}
.reveal {
    opacity:0; transform:translateY(24px);
    transition: opacity .6s ease, transform .6s ease;
}
.reveal.visible { opacity:1; transform:none; }
.reveal-delay-1 { transition-delay:.1s; }
.reveal-delay-2 { transition-delay:.2s; }
.reveal-delay-3 { transition-delay:.3s; }

/* ── Responsive nav ──────────────────────────────────── */
@media(max-width:768px){
    .navbar-toggler { display:block; }
    .nav-links {
        display:none; flex-direction:column; align-items:flex-start;
        position:absolute; top:var(--nav-h); left:0; right:0;
        background:rgba(15,23,42,.97);
        padding:20px 24px; gap:6px;
    }
    .navbar.scrolled .nav-links { background:rgba(255,255,255,.97); }
    .nav-links.open { display:flex; }
    .nav-link-item { color:rgba(255,255,255,.85); width:100%; }
    .navbar.scrolled .nav-link-item { color:var(--ink-soft); }
    .feat-grid, .steps-row, .role-cards { grid-template-columns:1fr; }
}
    </style>
</head>
<body>

<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar" id="mainNav">
    <div class="container">
        <a class="navbar-brand" href="#">
            <img src="amaya/img/ashi.jpg" alt="ASHI Logo">
            ASHI System
        </a>
        <button class="navbar-toggler" id="navToggler" aria-label="Menu">
            <i class="fas fa-bars"></i>
        </button>
        <div class="nav-links" id="navLinks">
            <a class="nav-link-item" href="#features">Features</a>
            <a class="nav-link-item" href="#how-it-works">How It Works</a>
            <a class="nav-link-item" href="#roles">User Roles</a>
            <a class="nav-link-item" href="#about">About</a>
            <?php if ($loggedIn): ?>
                <button class="btn-nav" onclick="window.location.href='<?= htmlspecialchars($dashboardUrl) ?>'">
                    <i class="fas fa-arrow-right"></i> Proceed
                </button>
            <?php else: ?>
                <button class="btn-nav" onclick="window.location.href='amaya/login.php'">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- ══════════ HERO ══════════ -->
<section class="hero">
    <div class="hero-slides">
        <div class="hero-slide active"></div>
        <div class="hero-slide"></div>
        <div class="hero-slide"></div>
    </div>
    <div class="hero-overlay"></div>

    <div class="hero-wave">
        <svg viewBox="0 0 1200 90" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,60 C300,100 900,20 1200,60 L1200,90 L0,90 Z" fill="#ffffff"/>
        </svg>
    </div>

    <div class="slide-dots" id="slideDots"></div>

    <div class="container hero-body">
        <div class="row">
            <div class="col-lg-7">
                <div class="hero-badge">
                    <span></span> Amaya School of Home Industries
                </div>
                <h1>
                    Where Learning Meets<br>
                    <em>Smart Management</em>
                </h1>
                <p class="hero-sub">
                    A unified platform for students and teachers to manage tasks, track grades, share materials, and stay ahead — all in one place.
                </p>
                <div class="hero-ctas">
                    <?php if ($loggedIn): ?>
                        <button class="btn-primary-cta" onclick="window.location.href='<?= htmlspecialchars($dashboardUrl) ?>'">
                            <i class="fas fa-arrow-right"></i> Go to Dashboard
                        </button>
                    <?php else: ?>
                        <button class="btn-primary-cta" onclick="window.location.href='amaya/login.php'">
                            <i class="fas fa-user-plus"></i> Get Started
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ STATS STRIP ══════════ -->
<div class="stats-strip">
    <div class="container">
        <div class="row g-0 align-items-center">
            <div class="col stat-item reveal">
                <div class="stat-num">60<span>+</span></div>
                <div class="stat-label">Years of Excellence</div>
            </div>
            <div class="col-auto stat-divider d-none d-md-block" style="height:48px;"></div>
            <div class="col stat-item reveal reveal-delay-1">
                <div class="stat-num">3<span>k+</span></div>
                <div class="stat-label">Students Served</div>
            </div>
            <div class="col-auto stat-divider d-none d-md-block" style="height:48px;"></div>
            <div class="col stat-item reveal reveal-delay-2">
                <div class="stat-num">6</div>
                <div class="stat-label">Core Modules</div>
            </div>
            <div class="col-auto stat-divider d-none d-md-block" style="height:48px;"></div>
            <div class="col stat-item reveal reveal-delay-3">
                <div class="stat-num">24<span>/7</span></div>
                <div class="stat-label">System Access</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════ FEATURES ══════════ -->
<section class="section" id="features">
    <div class="container">
        <div class="row align-items-end mb-5">
            <div class="col-md-7 reveal">
                <div class="eyebrow">Platform Features</div>
                <h2 class="section-heading">Everything you need,<br>nothing you don't.</h2>
            </div>
            <div class="col-md-5 reveal reveal-delay-1">
                <p class="section-sub" style="margin-left:auto;">Built specifically for ASHI's academic workflow — lean, focused, and effective.</p>
            </div>
        </div>
        <div class="feat-grid">
            <div class="feat-card reveal">
                <div class="feat-icon"><i class="fas fa-tasks"></i></div>
                <h4>Task Management</h4>
                <p>Create, assign, and track tasks with deadlines. Upload attachments and monitor submissions in real time.</p>
            </div>
            <div class="feat-card reveal reveal-delay-1">
                <div class="feat-icon"><i class="fas fa-book-reader"></i></div>
                <h4>Lecture Materials</h4>
                <p>Teachers upload resources once — students access anytime, anywhere, at their own pace.</p>
            </div>
            <div class="feat-card reveal reveal-delay-2">
                <div class="feat-icon"><i class="fas fa-chart-line"></i></div>
                <h4>Grade Tracking</h4>
                <p>Transparent, real-time grade records. Students and teachers stay on the same page.</p>
            </div>
            <div class="feat-card reveal">
                <div class="feat-icon"><i class="fas fa-shield-alt"></i></div>
                <h4>Secure Access</h4>
                <p>Role-based authentication with admin approval. Encrypted sessions keep your data safe.</p>
            </div>
            <div class="feat-card reveal reveal-delay-1">
                <div class="feat-icon"><i class="fas fa-bell"></i></div>
                <h4>Smart Notifications</h4>
                <p>Deadline reminders, submission alerts, and important announcements — never miss a thing.</p>
            </div>
            <div class="feat-card reveal reveal-delay-2">
                <div class="feat-icon"><i class="fas fa-search"></i></div>
                <h4>Advanced Search</h4>
                <p>Powerful search, sort, and filter across all modules. Find anything in seconds.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ HOW IT WORKS ══════════ -->
<section class="section section-alt" id="how-it-works">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <div class="eyebrow">Process</div>
            <h2 class="section-heading">Three steps to get started</h2>
        </div>
        <div class="steps-row">
            <div class="step-item reveal">
                <div class="step-circle">1</div>
                <h5>Register Your Account</h5>
                <p>Sign up as a student or teacher. An administrator reviews and approves your access.</p>
            </div>
            <div class="step-item reveal reveal-delay-1">
                <div class="step-circle">2</div>
                <h5>Access Your Dashboard</h5>
                <p>Log in to your personalized dashboard with role-specific tools and information.</p>
            </div>
            <div class="step-item reveal reveal-delay-2">
                <div class="step-circle">3</div>
                <h5>Start Managing</h5>
                <p>Create assignments, submit work, check grades, and collaborate with your class.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ USER ROLES ══════════ -->
<section class="section" id="roles">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <div class="eyebrow">User Roles</div>
            <h2 class="section-heading">Made for everyone on campus</h2>
        </div>
        <div class="role-cards">
            <div class="role-card reveal">
                <span class="role-emoji">🎓</span>
                <h3>Students</h3>
                <ul>
                    <li><i class="fas fa-circle"></i> View assigned tasks & deadlines</li>
                    <li><i class="fas fa-circle"></i> Submit task outputs online</li>
                    <li><i class="fas fa-circle"></i> Access lecture materials</li>
                    <li><i class="fas fa-circle"></i> Track your grades live</li>
                    <li><i class="fas fa-circle"></i> Manage your profile</li>
                </ul>
            </div>
            <div class="role-card reveal reveal-delay-1">
                <span class="role-emoji">📚</span>
                <h3>Teachers</h3>
                <ul>
                    <li><i class="fas fa-circle"></i> Create and manage tasks</li>
                    <li><i class="fas fa-circle"></i> Upload lecture materials</li>
                    <li><i class="fas fa-circle"></i> Track student submissions</li>
                    <li><i class="fas fa-circle"></i> Record and update grades</li>
                    <li><i class="fas fa-circle"></i> Monitor class performance</li>
                </ul>
            </div>
            <div class="role-card reveal reveal-delay-2">
                <span class="role-emoji">🛡️</span>
                <h3>Administrators</h3>
                <ul>
                    <li><i class="fas fa-circle"></i> Approve user registrations</li>
                    <li><i class="fas fa-circle"></i> Manage student records</li>
                    <li><i class="fas fa-circle"></i> Assign teachers to subjects</li>
                    <li><i class="fas fa-circle"></i> Monitor system activity</li>
                    <li><i class="fas fa-circle"></i> Review academic records</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ MAP ══════════ -->
<section class="section map-section">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5 reveal">
                <div class="eyebrow">Location</div>
                <h2 class="section-heading">Visit ASHI School</h2>
                <p class="section-sub mb-4">Located in the heart of Tanza, Cavite, ASHI has been delivering quality vocational education since 1964.</p>
                <div>
                    <div class="contact-item">
                        <div class="contact-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <strong>Address</strong>
                            <span>Sahud-Ulan, Tanza, Cavite, Philippines</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon"><i class="fas fa-phone"></i></div>
                        <div>
                            <strong>Contact</strong>
                            <span>(046) XXX-XXXX</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon"><i class="fas fa-envelope"></i></div>
                        <div>
                            <strong>Email</strong>
                            <a href="mailto:depedcavite.ashi@gmail.com">depedcavite.ashi@gmail.com</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7 reveal reveal-delay-1">
                <div class="map-wrap">
                    <iframe
                        src="https://www.google.com/maps?q=Amaya+Elementary+School,Sahud-Ulan,Tanza,Cavite&ll=14.383234,120.833684&z=17&output=embed"
                        allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════ CTA BANNER ══════════ -->
<div class="cta-banner">
    <div class="container inner">
        <div class="reveal">
            <h2>Ready to get started?</h2>
            <p>Join the ASHI platform and experience a smarter way to manage your academic life.</p>
            <button class="btn-cta-green" onclick="window.location.href='amaya/login.php'">
                <i class="fas fa-rocket"></i> Get Started Now
            </button>
        </div>
    </div>
</div>

<!-- ══════════ FOOTER ══════════ -->
<footer class="footer" id="about">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="footer-brand">
                    <img src="amaya/img/ashi.jpg" alt="ASHI Logo">
                    ASHI System
                </div>
                <p>Amaya School of Home Industries — empowering education through technology since 1964.</p>
                <div class="social-row">
                    <a href="https://www.facebook.com/DepEdTayoASHI301167/" class="soc-btn fb" title="Facebook" target="_blank" rel="noopener noreferrer"><i class="fab fa-facebook-f"></i></a>
                </div>
            </div>
            <div class="col-md-2 offset-md-1">
                <h6>Navigation</h6>
                <ul>
                    <li><a href="#features"><i class="fas fa-chevron-right"></i> Features</a></li>
                    <li><a href="#how-it-works"><i class="fas fa-chevron-right"></i> How It Works</a></li>
                    <li><a href="#roles"><i class="fas fa-chevron-right"></i> User Roles</a></li>
                    <li><a href="amaya/login.php"><i class="fas fa-chevron-right"></i> Login</a></li>
                </ul>
            </div>
            <div class="col-md-2">
                <h6>Resources</h6>
                <ul>
                    <li><a href="terms/privacy.php"><i class="fas fa-shield-alt"></i> Privacy Policy</a></li>
                    <li><a href="terms/terms.php"><i class="fas fa-gavel"></i> Terms of Use</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6>Contact</h6>
                <ul>
                    <li><a href="#"><i class="fas fa-map-marker-alt"></i> Sahud-Ulan, Tanza, Cavite</a></li>
                    <li><a href="mailto:depedcavite.ashi@gmail.com"><i class="fas fa-envelope"></i> depedcavite.ashi@gmail.com</a></li>
                    <li><a href="#"><i class="fas fa-phone"></i> (046) XXX-XXXX</a></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; <?php echo date('Y'); ?> ASHI School Management System. All rights reserved. &nbsp;|&nbsp; Made with <i class="fas fa-heart"></i> for ASHI</p>
    </div>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Navbar scroll ───────────────────────────────────── */
const nav = document.getElementById('mainNav');
window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 40);
}, { passive: true });

/* ── Mobile nav toggle ───────────────────────────────── */
document.getElementById('navToggler').addEventListener('click', () => {
    document.getElementById('navLinks').classList.toggle('open');
});

/* ── Hero slideshow ──────────────────────────────────── */
const slides = document.querySelectorAll('.hero-slide');
const dotsContainer = document.getElementById('slideDots');
let current = 0, timer;

slides.forEach((_, i) => {
    const d = document.createElement('button');
    d.className = 'slide-dot' + (i === 0 ? ' active' : '');
    d.addEventListener('click', () => goTo(i));
    dotsContainer.appendChild(d);
});

function goTo(n) {
    slides[current].classList.remove('active');
    dotsContainer.children[current].classList.remove('active');
    current = n;
    slides[current].classList.add('active');
    dotsContainer.children[current].classList.add('active');
    resetTimer();
}

function next() { goTo((current + 1) % slides.length); }

function resetTimer() {
    clearInterval(timer);
    timer = setInterval(next, 5000);
}

resetTimer();

/* ── Scroll reveal ───────────────────────────────────── */
const revealEls = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
}, { threshold: 0.12 });
revealEls.forEach(el => io.observe(el));
</script>
</body>
</html>