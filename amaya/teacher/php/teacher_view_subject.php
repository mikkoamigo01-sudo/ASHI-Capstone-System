<?php
session_start();
// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}
// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';

// Create PDO instance using the existing mysqli constants
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (!isset($_GET['id'])) {
    header("Location: teacher_dashb.php");
    exit();
}
$subject_id = $_GET['id'];
$teacher_id = $_SESSION['teacher_id'];
// Get subject details and verify ownership
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND teacher_id = ?");
$stmt->execute([$subject_id, $teacher_id]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subject) {
    die("Subject not found or you don't have permission to view it.");
}
// Get teacher details
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);
// Get enrolled students
$stmt = $pdo->prepare("
    SELECT s.*, ss.enrollment_date, ss.status as enrollment_status, ss.final_grade
    FROM student_subjects ss
    JOIN students s ON ss.student_id = s.id
    WHERE ss.subject_id = ? AND ss.status = 'active'
    ORDER BY s.last_name ASC, s.first_name ASC
");
$stmt->execute([$subject_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Get assignments with submission counts
$stmt = $pdo->prepare("
    SELECT a.*, 
           COUNT(DISTINCT sub.id) as submission_count,
           COUNT(DISTINCT CASE WHEN sub.status = 'graded' THEN sub.id END) as graded_count
    FROM assignments a
    LEFT JOIN submissions sub ON a.id = sub.assignment_id
    WHERE a.subject_id = ? AND a.status != 'draft'
    GROUP BY a.id
    ORDER BY a.created_at DESC
");
$stmt->execute([$subject_id]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Get materials
$stmt = $pdo->prepare("
    SELECT * FROM materials 
    WHERE subject_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$subject_id]);
$materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Get announcements
$stmt = $pdo->prepare("
    SELECT * FROM announcements 
    WHERE subject_id = ? 
    ORDER BY is_pinned DESC, created_at DESC
");
$stmt->execute([$subject_id]);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Get quizzes with attempt counts
$stmt = $pdo->prepare("
    SELECT q.*, 
           COUNT(DISTINCT qa.student_id) as attempt_count,
           COUNT(DISTINCT CASE WHEN qa.status = 'graded' THEN qa.student_id END) as graded_count,
           COUNT(DISTINCT CASE WHEN qa.status IN ('submitted', 'graded') THEN qa.student_id END) as students_taken
    FROM quizzes q
    LEFT JOIN quiz_attempts qa ON q.id = qa.quiz_id
    WHERE q.subject_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
");
$stmt->execute([$subject_id]);
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Calculate statistics
$total_students = count($students);
$total_assignments = count($assignments);
$total_materials = count($materials);
$total_quizzes = count($quizzes);
// Get active tab from URL or default to classwork
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'classwork';
// Helper function for color adjustment
function adjustColor($color, $amount) {
    $color = str_replace('#', '', $color);
    $num = hexdec($color);
    $r = max(0, min(255, ($num >> 16) + $amount));
    $g = max(0, min(255, (($num >> 8) & 0x00FF) + $amount));
    $b = max(0, min(255, ($num & 0x0000FF) + $amount));
    return '#' . str_pad(dechex(($r << 16) | ($g << 8) | $b), 6, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($subject['subject_name']); ?> - <?php echo htmlspecialchars($subject['section'] ?: 'No Section'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="teacher_view.css">
</head>
<body>
<style type="text/css">
                                    :root {
            --theme-color: <?php echo $subject['theme_color']; ?>;
            --theme-color-light: <?php echo adjustColor($subject['theme_color'], 40); ?>;
            --theme-color-dark: <?php echo adjustColor($subject['theme_color'], -30); ?>;
        }

        :root {
    /* Colors */
    --color-primary: #2563eb;
    --color-primary-light: #3b82f6;
    --color-primary-dark: #1d4ed8;
    
    --color-success: #10b981;
    --color-success-light: #d1fae5;
    --color-warning: #f59e0b;
    --color-warning-light: #fef3c7;
    --color-danger: #ef4444;
    --color-danger-light: #fee2e2;
    
    --color-bg-primary: #ffffff;
    --color-bg-secondary: #f8fafc;
    --color-bg-tertiary: #f1f5f9;
    
    --color-text-primary: #0f172a;
    --color-text-secondary: #475569;
    --color-text-tertiary: #94a3b8;
    
    --color-border: #e2e8f0;
    --color-border-light: #f1f5f9;
    
    /* Shadows */
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    
    /* Border Radius */
    --radius-sm: 6px;
    --radius-md: 10px;
    --radius-lg: 14px;
    --radius-xl: 20px;
    
    /* Spacing */
    --spacing-xs: 8px;
    --spacing-sm: 12px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
    --spacing-2xl: 48px;
    
    /* Typography */
    --font-display: 'Outfit', sans-serif;
    --font-body: 'Plus Jakarta Sans', sans-serif;
}

/* ================================
   Base Styles
   ================================ */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: var(--font-body);
    background: var(--color-bg-secondary);
    color: var(--color-text-primary);
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 var(--spacing-lg);
}

/* ================================
   Navigation Bar
   ================================ */
.navbar {
    background: var(--color-bg-primary);
    border-bottom: 1px solid var(--color-border);
    position: sticky;
    top: 0;
    z-index: 1000;
    backdrop-filter: blur(8px);
    background: rgba(255, 255, 255, 0.95);
}

.navbar-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--spacing-md) var(--spacing-lg);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.navbar-left {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.back-button {
    width: 40px;
    height: 40px;
    border: none;
    background: var(--color-bg-secondary);
    border-radius: var(--radius-md);
    color: var(--color-text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.back-button:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
    transform: translateX(-2px);
}

.back-button:active {
    transform: translateX(-4px);
}

.navbar-brand {
    display: flex;
    align-items: center;
}

.brand-text {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.navbar-right {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.class-code {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    color: var(--color-primary);
    padding: 8px 16px;
    border-radius: var(--radius-lg);
    font-size: 13px;
    font-weight: 600;
    border: 1px solid rgba(37, 99, 235, 0.1);
}

/* ================================
   Subject Header Card with Waves
   ================================ */
.subject-header {
    padding: var(--spacing-xl) 0;
}

.header-card {
    background: linear-gradient(135deg, var(--theme-color, #2563eb) 0%, var(--theme-color-dark, #1d4ed8) 100%);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-xl);
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative;
    min-height: 240px;
}

.header-bg {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 0;
    overflow: hidden;
}

.header-pattern {
    position: absolute;
    inset: 0;
    opacity: 0.15;
    background-image: 
        radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.3) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.2) 0%, transparent 50%),
        linear-gradient(45deg, transparent 45%, rgba(255, 255, 255, 0.05) 50%, transparent 55%);
    background-size: 100% 100%, 100% 100%, 40px 40px;
    animation: patternShift 20s linear infinite;
}

/* Wave Layer 1 - Bottom */
.header-pattern::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 120px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 120' preserveAspectRatio='none'%3E%3Cpath d='M0,0 Q300,90 600,60 T1200,40 L1200,120 L0,120 Z' fill='rgba(255,255,255,0.1)'/%3E%3C/svg%3E");
    background-size: cover;
    background-position: bottom;
    background-repeat: no-repeat;
    animation: waveFloat 8s ease-in-out infinite;
}

/* Wave Layer 2 - Middle */
.header-pattern::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 100px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 100' preserveAspectRatio='none'%3E%3Cpath d='M0,20 Q400,80 800,50 T1200,30 L1200,100 L0,100 Z' fill='rgba(255,255,255,0.08)'/%3E%3C/svg%3E");
    background-size: cover;
    background-position: bottom;
    background-repeat: no-repeat;
    animation: waveFloat 10s ease-in-out infinite reverse;
}

@keyframes patternShift {
    0% {
        background-position: 0% 0%, 0% 0%, 0 0;
    }
    100% {
        background-position: 100% 100%, 100% 100%, 40px 40px;
    }
}

@keyframes waveFloat {
    0%, 100% {
        transform: translateY(0) translateX(0);
    }
    50% {
        transform: translateY(-10px) translateX(10px);
    }
}

.header-content {
    position: relative;
    z-index: 1;
    padding: var(--spacing-xl);
    display: flex;
    gap: var(--spacing-lg);
    align-items: flex-start;
}

.subject-icon-wrapper {
    flex-shrink: 0;
}

.subject-icon {
    width: 96px;
    height: 96px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    border: 3px solid rgba(255, 255, 255, 0.2);
    overflow: hidden;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
}

.subject-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.icon-letter {
    font-family: var(--font-display);
    font-size: 40px;
    font-weight: 700;
    background: linear-gradient(135deg, var(--theme-color, #2563eb) 0%, var(--theme-color-light, #3b82f6) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.subject-info-main {
    flex: 1;
    padding-top: var(--spacing-xs);
}

.subject-tags {
    display: flex;
    gap: var(--spacing-xs);
    margin-bottom: var(--spacing-sm);
}

.tag {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border-radius: var(--radius-lg);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.subject-title {
    font-family: var(--font-display);
    font-size: 36px;
    font-weight: 700;
    color: white;
    margin-bottom: var(--spacing-md);
    line-height: 1.2;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.teacher-info {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.teacher-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(10px);
}

.teacher-details {
    display: flex;
    flex-direction: column;
}

.teacher-label {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.teacher-name {
    font-size: 14px;
    font-weight: 600;
    color: white;
}

/* ================================
   Navigation Tabs
   ================================ */
.tabs-wrapper {
    background: var(--color-bg-primary);
    border-bottom: 1px solid var(--color-border);
    position: sticky;
    top: 73px;
    z-index: 999;
}

.tabs-nav {
    display: flex;
    gap: var(--spacing-xs);
    position: relative;
    overflow-x: auto;
    scrollbar-width: none;
}

.tabs-nav::-webkit-scrollbar {
    display: none;
}

.tab-button {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: var(--spacing-md) var(--spacing-lg);
    background: none;
    border: none;
    color: var(--color-text-secondary);
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
}

.tab-button svg,
.tab-button i {
    opacity: 0.7;
    transition: opacity 0.2s ease;
}

.tab-button:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-secondary);
}

.tab-button:hover svg,
.tab-button:hover i {
    opacity: 1;
}

.tab-button.active {
    color: var(--theme-color, var(--color-primary));
    border-bottom-color: var(--theme-color, var(--color-primary));
}

.tab-button.active svg,
.tab-button.active i {
    opacity: 1;
}

/* ================================
   Main Content Area
   ================================ */
.main-wrapper {
    padding: var(--spacing-xl) 0 var(--spacing-2xl);
}

.tab-content {
    display: none;
    animation: fadeIn 0.3s ease;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ================================
   Content Cards
   ================================ */
.content-card {
    background: var(--color-bg-primary);
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: var(--spacing-lg);
}

.card-header {
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-title {
    font-family: var(--font-display);
    font-size: 20px;
    font-weight: 700;
    color: var(--color-text-primary);
}

.card-body {
    padding: var(--spacing-lg);
}

/* ================================
   Buttons
   ================================ */
.btn {
    padding: 10px 20px;
    border-radius: var(--radius-md);
    border: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: var(--font-body);
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    white-space: nowrap;
}

.btn-primary {
    background: var(--theme-color, var(--color-primary));
    color: white;
}

.btn-primary:hover {
    background: var(--theme-color-dark, var(--color-primary-dark));
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.btn-primary:active {
    transform: translateY(0);
}

.btn-outlined {
    background: white;
    color: var(--color-text-primary);
    border: 1px solid var(--color-border);
}

.btn-outlined:hover {
    background: var(--color-bg-secondary);
    border-color: var(--color-text-secondary);
}

.btn-text {
    background: none;
    color: var(--theme-color, var(--color-primary));
    padding: 8px 16px;
}

.btn-text:hover {
    background: rgba(37, 99, 235, 0.08);
}

.btn-danger {
    background: var(--color-danger);
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
    box-shadow: var(--shadow-md);
}

/* ================================
   Dropdown Menu
   ================================ */
.create-menu {
    position: relative;
    display: inline-block;
}

.dropdown-menu {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    background: white;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-xl);
    min-width: 220px;
    z-index: 1000;
    overflow: hidden;
}

.dropdown-menu.show {
    display: block;
    animation: dropdownSlide 0.2s ease;
}

@keyframes dropdownSlide {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.dropdown-item {
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: var(--color-text-primary);
    border: none;
    background: none;
    width: 100%;
    text-align: left;
    transition: background 0.15s ease;
}

.dropdown-item:hover {
    background: var(--color-bg-secondary);
}

.dropdown-item i {
    width: 20px;
    color: var(--color-text-secondary);
}

/* ================================
   Assignments List
   ================================ */
.assignments-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.assignment-card {
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border);
    border-left: 4px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--spacing-lg);
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    cursor: pointer;
    transition: all 0.2s ease;
}

.assignment-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateX(4px);
}

.assignment-card.homework {
    border-left-color: var(--color-primary);
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.03) 0%, transparent 100%);
}

.assignment-card.quiz {
    border-left-color: #9334e6;
    background: linear-gradient(90deg, rgba(147, 52, 230, 0.03) 0%, transparent 100%);
}

.assignment-card.material {
    border-left-color: var(--color-success);
    background: linear-gradient(90deg, rgba(16, 185, 129, 0.03) 0%, transparent 100%);
}

.assignment-type-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: transform 0.2s ease;
}

.assignment-card:hover .assignment-type-icon {
    transform: scale(1.1);
}

.assignment-type-icon[data-type="homework"] {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: var(--color-primary);
}

.assignment-type-icon[data-type="quiz"] {
    background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
    color: #9334e6;
}

.assignment-type-icon[data-type="document"] {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: var(--color-success);
}

.assignment-details {
    flex: 1;
}

.assignment-meta {
    margin-bottom: var(--spacing-xs);
}

.assignment-title {
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 6px;
}

.assignment-info {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.assignment-info > span {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--color-text-secondary);
}

.assignment-info i {
    opacity: 0.6;
}

.assignment-status-badge {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: 8px 16px;
    border-radius: var(--radius-lg);
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: var(--color-primary);
}

.status-icon {
    display: flex;
    align-items: center;
    justify-content: center;
}

.assignment-arrow {
    color: var(--color-text-tertiary);
    opacity: 0;
    transition: all 0.2s ease;
}

.assignment-card:hover .assignment-arrow {
    opacity: 1;
    transform: translateX(4px);
}

/* ================================
   People Section
   ================================ */
.class-code-display {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-lg);
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-radius: var(--radius-md);
    border: 1px solid rgba(37, 99, 235, 0.1);
}

.class-code-info h3 {
    font-family: var(--font-display);
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-secondary);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.class-code-value {
    font-family: var(--font-display);
    font-size: 28px;
    font-weight: 700;
    color: var(--color-primary);
    letter-spacing: 3px;
}

.student-count {
    font-size: 14px;
    color: var(--color-text-secondary);
}

.people-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
    background: var(--color-border-light);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.people-item {
    background: var(--color-bg-primary);
    padding: var(--spacing-lg);
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.people-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--theme-color, var(--color-primary)) 0%, var(--theme-color-light, var(--color-primary-light)) 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    flex-shrink: 0;
}

.people-info {
    flex: 1;
}

.people-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 2px;
}

.people-email {
    font-size: 12px;
    color: var(--color-text-secondary);
}

.people-role {
    padding: 4px 12px;
    border-radius: var(--radius-lg);
    font-size: 12px;
    font-weight: 600;
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

/* ================================
   Grades Table
   ================================ */
.grades-table-wrapper {
    overflow-x: auto;
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
}

.grades-table {
    width: 100%;
    border-collapse: collapse;
}

.grades-table thead {
    background: var(--color-bg-tertiary);
}

.grades-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-secondary);
    border-bottom: 1px solid var(--color-border);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.grades-table td {
    padding: 12px 16px;
    font-size: 14px;
    color: var(--color-text-primary);
    border-bottom: 1px solid var(--color-border-light);
}

.grades-table tbody tr:hover {
    background: var(--color-bg-secondary);
}

.grades-table tbody tr:last-child td {
    border-bottom: none;
}

.student-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.student-avatar-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--theme-color, var(--color-primary)) 0%, var(--theme-color-light, var(--color-primary-light)) 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 12px;
    flex-shrink: 0;
}

.grade-cell {
    text-align: center;
}

.grade-value {
    font-weight: 600;
    color: var(--color-success);
}

.grade-overall {
    font-size: 16px;
    color: var(--color-primary);
}

.grade-empty {
    color: var(--color-text-tertiary);
    font-style: italic;
}

/* ================================
   Settings Section
   ================================ */
.settings-list {
    display: flex;
    flex-direction: column;
}

.settings-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-lg) 0;
    border-bottom: 1px solid var(--color-border-light);
}

.settings-item:last-child {
    border-bottom: none;
}

.settings-label {
    font-size: 14px;
    font-weight: 500;
    color: var(--color-text-primary);
}

.settings-label.danger {
    color: var(--color-danger);
}

.settings-value {
    font-size: 14px;
    color: var(--color-text-secondary);
}

.code-value {
    font-family: var(--font-display);
    font-weight: 600;
    letter-spacing: 1px;
    color: var(--color-primary);
}

.settings-description {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 4px;
}

/* ================================
   Empty States
   ================================ */
.empty-state {
    text-align: center;
    padding: var(--spacing-2xl) var(--spacing-lg);
}

.empty-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto var(--spacing-lg);
    border-radius: var(--radius-xl);
    background: var(--color-bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-tertiary);
}

.empty-icon i {
    font-size: 32px;
}

.empty-state h3 {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: var(--spacing-xs);
}

.empty-state p {
    font-size: 14px;
    color: var(--color-text-secondary);
}

/* ================================
   Responsive Design
   ================================ */
@media (max-width: 768px) {
    .container {
        padding: 0 var(--spacing-md);
    }
    
    .navbar-container {
        padding: var(--spacing-sm) var(--spacing-md);
    }
    
    .brand-text {
        font-size: 16px;
    }
    
    .class-code {
        font-size: 11px;
        padding: 6px 12px;
    }
    
    .subject-header {
        padding: var(--spacing-lg) 0;
    }
    
    .header-content {
        flex-direction: column;
        padding: var(--spacing-lg);
        text-align: center;
        align-items: center;
    }
    
    .subject-icon {
        width: 80px;
        height: 80px;
    }
    
    .icon-letter {
        font-size: 32px;
    }
    
    .subject-title {
        font-size: 28px;
    }
    
    .subject-tags {
        justify-content: center;
    }
    
    .teacher-info {
        justify-content: center;
    }
    
    .tabs-wrapper {
        top: 65px;
    }
    
    .tab-button {
        padding: var(--spacing-sm) var(--spacing-md);
        font-size: 13px;
    }
    
    .main-wrapper {
        padding: var(--spacing-lg) 0;
    }
    
    .card-header {
        flex-direction: column;
        gap: var(--spacing-sm);
        align-items: flex-start;
    }
    
    .card-body {
        padding: var(--spacing-md);
    }
    
    .class-code-display {
        flex-direction: column;
        gap: var(--spacing-md);
        align-items: flex-start;
    }
    
    .class-code-value {
        font-size: 24px;
    }
    
    .assignment-card {
        flex-wrap: wrap;
    }
    
    .assignment-arrow {
        display: none;
    }
    
    .grades-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .grades-table {
        min-width: 600px;
    }
    
    .settings-item {
        flex-direction: column;
        gap: var(--spacing-sm);
        align-items: flex-start;
    }
    
    .settings-item .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .subject-title {
        font-size: 24px;
    }
    
    .assignment-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .assignment-status-badge {
        width: 100%;
        justify-content: center;
    }
}

        .teacher-avatar img,
        .people-avatar img,
        .student-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        /* ================================
   Section Filter Styles
   ================================ */

.card-header .header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.section-filter {
    padding: 8px 16px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    color: #1e293b;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    outline: none;
    min-width: 180px;
}

.section-filter:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}

.section-filter:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.section-filter option {
    padding: 8px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* People Item with Section Badge */
.people-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    border-radius: 12px;
    transition: all 0.2s ease;
}

.people-item:hover {
    background: #f8fafc;
}

.people-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
}

.section-badge {
    padding: 4px 12px;
    background: #eff6ff;
    color: #2563eb;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

/* Responsive Design */
@media (max-width: 768px) {
    .card-header .header-actions {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }
    
    .section-filter {
        width: 100%;
    }
    
    .people-meta {
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }
    
    .section-badge {
        font-size: 11px;
        padding: 3px 10px;
    }
}

/* Animation for filtered items */
.people-item {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Quiz Tab Styles
================================ */

.quiz-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.quiz-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    transition: all 0.3s ease;
}

.quiz-card:hover {
    border-color: #667eea;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
    transform: translateY(-2px);
}

.quiz-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
}

.quiz-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.quiz-icon i {
    font-size: 24px;
    color: white;
}

.quiz-main-info {
    flex: 1;
}

.quiz-title {
    font-size: 18px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 8px 0;
}

.quiz-description {
    font-size: 14px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}

.quiz-status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.quiz-status-badge.status-draft {
    background: #fef3c7;
    color: #92400e;
}

.quiz-status-badge.status-published {
    background: #d1fae5;
    color: #065f46;
}

.quiz-status-badge.status-closed {
    background: #fee2e2;
    color: #991b1b;
}

.quiz-details {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 20px;
    padding: 16px;
    background: #f8fafc;
    border-radius: 8px;
}

.quiz-detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #475569;
}

.quiz-detail-item i {
    color: #667eea;
    font-size: 16px;
}

.quiz-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
    padding: 20px;
    background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
    border-radius: 8px;
}

.quiz-stat {
    text-align: center;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.quiz-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 13px;
}

/* Empty State for Quiz */
#quiz-tab .empty-state {
    text-align: center;
    padding: 60px 20px;
}

#quiz-tab .empty-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

#quiz-tab .empty-icon i {
    font-size: 36px;
    color: white;
}

#quiz-tab .empty-state h3 {
    font-size: 20px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 8px 0;
}

#quiz-tab .empty-state p {
    font-size: 14px;
    color: #64748b;
    margin: 0;
}

/* Responsive Design for Quiz Tab */
@media (max-width: 768px) {
    .quiz-header {
        flex-direction: column;
    }
    
    .quiz-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .quiz-actions {
        flex-direction: column;
    }
    
    .quiz-actions .btn {
        width: 100%;
    }
    
    .quiz-detail-item {
        flex: 1 1 45%;
    }
}

@media (max-width: 480px) {
    .quiz-stats {
        grid-template-columns: 1fr;
    }
    
    .quiz-detail-item {
        flex: 1 1 100%;
    }
}



/* Quiz Tab Styles - Minimal Design
================================ */

.quiz-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.quiz-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.quiz-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.quiz-card:hover {
    border-color: #667eea;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
    transform: translateY(-2px);
}

.quiz-card:hover::before {
    transform: scaleX(1);
}

.quiz-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 16px;
}

.quiz-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.quiz-icon i {
    font-size: 20px;
    color: white;
}

.quiz-status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.quiz-status-badge.status-draft {
    background: #fef3c7;
    color: #92400e;
}

.quiz-status-badge.status-published {
    background: #d1fae5;
    color: #065f46;
}

.quiz-status-badge.status-closed {
    background: #fee2e2;
    color: #991b1b;
}

.quiz-title {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 12px 0;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.quiz-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}

.quiz-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #64748b;
}

.quiz-meta-item i {
    color: #667eea;
    font-size: 14px;
    width: 16px;
}

.quiz-stats-compact {
    display: flex;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
}

.stat-compact {
    text-align: center;
    flex: 1;
}

.stat-compact-value {
    font-size: 20px;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 2px;
}

.stat-compact-label {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.quiz-actions-footer {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.quiz-action-btn {
    flex: 1;
    padding: 8px 12px;
    font-size: 12px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: white;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.quiz-action-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #475569;
}

.quiz-action-btn.delete-btn {
    color: #ef4444;
    border-color: #fecaca;
}

.quiz-action-btn.delete-btn:hover {
    background: #fef2f2;
    border-color: #fca5a5;
}

/* Empty State for Quiz */
#quiz-tab .empty-state {
    text-align: center;
    padding: 60px 20px;
}

#quiz-tab .empty-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

#quiz-tab .empty-icon i {
    font-size: 36px;
    color: white;
}

#quiz-tab .empty-state h3 {
    font-size: 20px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 8px 0;
}

#quiz-tab .empty-state p {
    font-size: 14px;
    color: #64748b;
    margin: 0;
}

/* Lock indicator for quizzes with student attempts */
.quiz-lock-indicator {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 24px;
    height: 24px;
    background: #fef3c7;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.quiz-card.has-attempts .quiz-lock-indicator {
    opacity: 1;
}

.quiz-lock-indicator i {
    font-size: 12px;
    color: #92400e;
}

/* Responsive Design for Quiz Tab */
@media (max-width: 768px) {
    .quiz-list {
        grid-template-columns: 1fr;
    }
    
    .quiz-stats-compact {
        flex-wrap: wrap;
    }
    
    .stat-compact {
        flex: 1 1 50%;
        margin-bottom: 8px;
    }
}

@media (max-width: 480px) {
    .quiz-actions-footer {
        flex-direction: column;
    }
    
    .quiz-action-btn {
        width: 100%;
    }
}

        /* Delete Button Styles */
        .delete-card-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.2s ease;
            z-index: 10;
            font-size: 14px;
        }

        .assignment-card:hover .delete-card-btn,
        .quiz-card:hover .delete-card-btn {
            opacity: 1;
        }

        .delete-card-btn:hover {
            background: #ef4444;
            color: white;
            transform: scale(1.05);
        }

        .delete-card-btn:active {
            transform: scale(0.95);
        }

        .assignment-card,
        .quiz-card {
            position: relative;
        }

        /* Adjust quiz card for delete button */
        .quiz-card {
            position: relative;
        }

        .quiz-card .delete-card-btn {
            top: 16px;
            right: 16px;
        }
        .subject-icon-wrapper {
            position: relative;
            cursor: pointer;
        }

        .logo-edit-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            border-radius: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease;
            border-radius: 16px;
            z-index: 2;
        }

        .subject-icon-wrapper:hover .logo-edit-overlay {
            opacity: 1;
        }

        .logo-edit-overlay i {
            color: #fff;
            font-size: 22px;
            filter: drop-shadow(0 1px 3px rgba(0,0,0,0.4));
        }

        /* Picker modal */
        .logo-picker-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 5000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s ease;
        }

        .logo-picker-backdrop.open {
            opacity: 1;
            pointer-events: all;
        }

        .logo-picker-modal {
            background: #fff;
            border-radius: 20px;
            padding: 28px 28px 24px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.22);
            transform: translateY(20px) scale(0.97);
            transition: transform 0.25s cubic-bezier(.34,1.56,.64,1);
        }

        .logo-picker-backdrop.open .logo-picker-modal {
            transform: translateY(0) scale(1);
        }

        .logo-picker-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .logo-picker-header h3 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 17px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .logo-picker-close {
            background: #f1f5f9;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            transition: background 0.15s;
        }

        .logo-picker-close:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        /* Drop zone */
        .logo-drop-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 14px;
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            background: #f8fafc;
            position: relative;
        }

        .logo-drop-zone:hover,
        .logo-drop-zone.drag-over {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .logo-drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .logo-drop-icon {
            font-size: 36px;
            margin-bottom: 10px;
            color: #94a3b8;
        }

        .logo-drop-zone p {
            margin: 0 0 4px;
            font-size: 14px;
            font-weight: 600;
            color: #475569;
        }

        .logo-drop-zone span {
            font-size: 12px;
            color: #94a3b8;
        }

        /* Preview */
        .logo-preview-area {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            margin-top: 18px;
        }

        .logo-preview-area.visible {
            display: flex;
        }

        .logo-preview-img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 18px;
            border: 3px solid #e2e8f0;
            box-shadow: 0 4px 14px rgba(0,0,0,0.1);
        }

        .logo-preview-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }

        /* Actions */
        .logo-picker-actions {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }

        .logo-picker-actions .btn-cancel-logo {
            flex: 1;
            padding: 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .logo-picker-actions .btn-cancel-logo:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .logo-picker-actions .btn-save-logo {
            flex: 2;
            padding: 10px;
            border: none;
            border-radius: 10px;
            background: #2563eb;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s;
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: none;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .logo-picker-actions .btn-save-logo.visible {
            display: flex;
        }

        .logo-picker-actions .btn-save-logo:hover {
            background: #1d4ed8;
        }

        .logo-picker-actions .btn-save-logo:active {
            transform: scale(0.97);
        }

        .logo-picker-actions .btn-save-logo:disabled {
            background: #93c5fd;
            cursor: not-allowed;
        }

        /* Change image link */
        .logo-change-link {
            font-size: 12px;
            color: #2563eb;
            cursor: pointer;
            text-decoration: underline;
            font-weight: 500;
        }

        .floating-shapes {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
}

/* Base for all shapes */
.shape { will-change: transform; }

/* ── Individual animations ─────────────────────────────────── */

/* Large blurred circle — slow gentle bob */
.shape-circle-lg {
    animation: floatUp 9s ease-in-out infinite;
    filter: blur(2px);
}

/* Rotating square */
.shape-square {
    transform-origin: 1090px 50px;
    animation: spinSlow 14s linear infinite, floatUp 8s ease-in-out infinite;
}

/* Dot cluster */
.shape-dot-1 { animation: floatUp   7s 0.5s ease-in-out infinite; }
.shape-dot-2 { animation: floatSide 6s 1.2s ease-in-out infinite; }
.shape-dot-3 { animation: floatUp   8s 0.8s ease-in-out infinite; }

/* Triangle */
.shape-triangle {
    transform-origin: 980px 55px;
    animation: floatUp 10s 1s ease-in-out infinite, spinSlow 20s linear infinite reverse;
}

/* Outlined ring */
.shape-ring-1 { animation: pulse 6s 0.3s ease-in-out infinite; }

/* Cross — pulses in/out */
.shape-cross { animation: pulse 5s 2s ease-in-out infinite; }

/* Diamond */
.shape-diamond {
    transform-origin: 610px 60px;
    animation: spinSlow 18s linear infinite, floatUp 9s 1.5s ease-in-out infinite;
}

/* Bottom dots */
.shape-dot-b1 { animation: floatSide 7s 0.6s ease-in-out infinite; }
.shape-dot-b2 { animation: floatUp   8s 1.8s ease-in-out infinite; }
.shape-dot-b3 { animation: floatSide 6s 0.2s ease-in-out infinite; }

/* Hexagon */
.shape-hex    { animation: floatUp 11s 0.9s ease-in-out infinite; }

/* Large faint ring */
.shape-ring-2 { animation: pulse 8s 0s ease-in-out infinite; filter: blur(1px); }

/* Wave line */
.shape-wave   { animation: floatUp 9s 2s ease-in-out infinite; }

/* ── Keyframes ─────────────────────────────────────────────── */

@keyframes floatUp {
    0%, 100% { transform: translateY(0px);   opacity: 1; }
    50%       { transform: translateY(-18px); opacity: 0.7; }
}

@keyframes floatSide {
    0%, 100% { transform: translateX(0px);  opacity: 1; }
    50%       { transform: translateX(14px); opacity: 0.75; }
}

@keyframes spinSlow {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

@keyframes pulse {
    0%, 100% { opacity: 1;    transform: scale(1); }
    50%       { opacity: 0.4; transform: scale(1.08); }
}
</style>
    <!-- Logo Image Picker Modal -->
    <div class="logo-picker-backdrop" id="logoPickerBackdrop">
        <div class="logo-picker-modal">
            <div class="logo-picker-header">
                <h3><i class="fas fa-image" style="margin-right:8px;color:#2563eb;"></i>Change Subject Logo</h3>
                <button class="logo-picker-close" id="logoPickerClose"><i class="fas fa-times"></i></button>
            </div>

            <div class="logo-drop-zone" id="logoDropZone">
                <input type="file" id="logoFileInput" accept="image/png,image/jpeg,image/gif,image/webp">
                <div class="logo-drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <p>Click or drag & drop an image</p>
                <span>PNG, JPG, GIF, WEBP — max 5MB</span>
            </div>

            <div class="logo-preview-area" id="logoPreviewArea">
                <img src="" alt="Preview" class="logo-preview-img" id="logoPreviewImg">
                <span class="logo-preview-label">Preview</span>
                <span class="logo-change-link" id="logoChangeLink">Choose a different image</span>
            </div>

            <div class="logo-picker-actions">
                <button class="btn-cancel-logo" id="logoCancelBtn">Cancel</button>
                <button class="btn-save-logo" id="logoSaveBtn">
                    <i class="fas fa-save"></i> Save Logo
                </button>
            </div>
        </div>
    </div>

    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="navbar-container">
            <div class="navbar-left">
                <button class="back-button" onclick="window.location.href='../teacher_dashb.php'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="navbar-brand">
                    <span class="brand-text">ClassroomHub</span>
                </div>
            </div>
            <div class="navbar-right">
                <div class="class-code">
                    <i class="fas fa-key"></i>
                    <span><?php echo htmlspecialchars($subject['class_code']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Subject Header -->
    <div class="subject-header">
        <div class="container">
            <div class="header-card">
                <div class="header-bg">
                    <div class="header-pattern"></div>
                    <svg class="floating-shapes" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 300" preserveAspectRatio="xMidYMid slice">
                        <circle class="shape shape-circle-lg" cx="80" cy="60" r="90" fill="rgba(255,255,255,0.07)"/>
                        <rect class="shape shape-square" x="1060" y="20" width="60" height="60" rx="10" fill="none" stroke="rgba(255,255,255,0.18)" stroke-width="2"/>
                        <circle class="shape shape-dot-1" cx="320" cy="40" r="10" fill="rgba(255,255,255,0.15)"/>
                        <circle class="shape shape-dot-2" cx="345" cy="65" r="6"  fill="rgba(255,255,255,0.10)"/>
                        <circle class="shape shape-dot-3" cx="300" cy="72" r="4"  fill="rgba(255,255,255,0.12)"/>
                        <polygon class="shape shape-triangle" points="980,30 1010,80 950,80" fill="none" stroke="rgba(255,255,255,0.20)" stroke-width="1.5"/>
                        <circle class="shape shape-ring-1" cx="220" cy="180" r="50" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="1.5"/>
                        <line class="shape shape-cross" x1="1120" y1="200" x2="1120" y2="260" stroke="rgba(255,255,255,0.18)" stroke-width="2" stroke-linecap="round"/>
                        <line class="shape shape-cross" x1="1090" y1="230" x2="1150" y2="230" stroke="rgba(255,255,255,0.18)" stroke-width="2" stroke-linecap="round"/>
                        <rect class="shape shape-diamond" x="590" y="40" width="40" height="40" rx="4" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1.5" transform="rotate(45 610 60)"/>
                        <circle class="shape shape-dot-b1" cx="500" cy="240" r="5"  fill="rgba(255,255,255,0.10)"/>
                        <circle class="shape shape-dot-b2" cx="650" cy="260" r="3"  fill="rgba(255,255,255,0.08)"/>
                        <circle class="shape shape-dot-b3" cx="780" cy="230" r="7"  fill="rgba(255,255,255,0.09)"/>
                        <polygon class="shape shape-hex" points="140,170 160,158 180,170 180,194 160,206 140,194" fill="none" stroke="rgba(255,255,255,0.14)" stroke-width="1.5"/>
                        <circle class="shape shape-ring-2" cx="1050" cy="200" r="80" fill="none" stroke="rgba(255,255,255,0.07)" stroke-width="3"/>
                        <polyline class="shape shape-wave" points="400,280 430,260 460,280 490,260 520,280 550,260 580,280" fill="none" stroke="rgba(255,255,255,0.13)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="header-content">
                    <div class="subject-icon-wrapper" id="logoPickerTrigger" title="Click to change subject logo">
                        <div class="subject-icon">
                            <?php $logo_filename = basename($subject['logo_path']); ?>
                            <img id="subjectLogoImg"
                                 src="uploads/banner/<?php echo htmlspecialchars($logo_filename); ?>" 
                                 alt="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="icon-letter" style="display: none;">
                                <?php echo strtoupper(substr($subject['subject_name'], 0, 1)); ?>
                            </div>
                        </div>
                        <div class="logo-edit-overlay">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    <div class="subject-info-main">
                        <div class="subject-tags">
                            <?php if ($subject['section']): ?>
                            <span class="tag"><?php echo htmlspecialchars($subject['section']); ?></span>
                            <?php endif; ?>
                            <span class="tag">Grade <?php echo htmlspecialchars($subject['grade']); ?></span>
                        </div>
                        <h1 class="subject-title"><?php echo htmlspecialchars($subject['subject_name']); ?></h1>
                        <div class="teacher-info">
                            <div class="teacher-avatar">
                                <?php if ($teacher['profile_picture']): ?>
                                    <img src="../<?php echo htmlspecialchars($teacher['profile_picture']); ?>" 
                                         alt="<?php echo htmlspecialchars($teacher['fullname']); ?>"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <span style="display: none;"><?php echo strtoupper(substr($teacher['fullname'], 0, 1)); ?></span>
                                <?php else: ?>
                                    <?php echo strtoupper(substr($teacher['fullname'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="teacher-details">
                                <span class="teacher-label">Teacher</span>
                                <span class="teacher-name"><?php echo htmlspecialchars($teacher['fullname']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="tabs-wrapper">
        <div class="container">
            <div class="tabs-nav">
                <button class="tab-button <?php echo $active_tab === 'classwork' ? 'active' : ''; ?>" 
                        data-tab="classwork">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Classwork</span>
                </button>
                <button class="tab-button <?php echo $active_tab === 'quiz' ? 'active' : ''; ?>" 
                        data-tab="quiz">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Quiz</span>
                </button>
                <!-- ★ NEW Stream Tab ★ -->
                <button class="tab-button <?php echo $active_tab === 'stream' ? 'active' : ''; ?>" 
                        data-tab="stream">
                    <i class="fas fa-bullhorn"></i>
                    <span>Stream</span>
                </button>
                <button class="tab-button <?php echo $active_tab === 'people' ? 'active' : ''; ?>" 
                        data-tab="people">
                    <i class="fas fa-users"></i>
                    <span>People</span>
                </button>
                <button class="tab-button <?php echo $active_tab === 'grades' ? 'active' : ''; ?>" 
                        data-tab="grades">
                    <i class="fas fa-chart-bar"></i>
                    <span>Grades</span>
                </button>
                <button class="tab-button <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" 
                        data-tab="settings">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="container">

            <!-- Classwork Tab -->
            <div class="tab-content <?php echo $active_tab === 'classwork' ? 'active' : ''; ?>" id="classwork-tab">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Classwork</h2>
                        <div class="create-menu">
                            <button class="btn btn-primary" id="createMenuBtn">
                                <i class="fas fa-plus"></i> Create
                            </button>
                            <div class="dropdown-menu" id="createMenu">
                                <button class="dropdown-item" data-action="assignment">
                                    <i class="fas fa-file-alt"></i>
                                    <span>Assignment</span>
                                </button>
                                <button class="dropdown-item" data-action="quiz">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span>Quiz Assignment</span>
                                </button>
                                <button class="dropdown-item" data-action="material">
                                    <i class="fas fa-book"></i>
                                    <span>Material</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($assignments) && empty($materials)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
                            <h3>No classwork yet</h3>
                            <p>Create assignments, quizzes, or share materials with your students</p>
                        </div>
                        <?php else: ?>
                        <div class="assignments-list">
                            <?php foreach ($assignments as $assignment): ?>
                            <div class="assignment-card <?php echo $assignment['assignment_type'] === 'quiz' ? 'quiz' : 'homework'; ?>" 
                                 data-id="<?php echo $assignment['id']; ?>"
                                 data-type="assignment">
                                <button class="delete-card-btn" onclick="deleteItem(event, 'assignment', <?php echo $assignment['id']; ?>)" title="Delete assignment">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <div class="assignment-type-icon" data-type="<?php echo $assignment['assignment_type']; ?>">
                                    <i class="fas fa-<?php echo $assignment['assignment_type'] === 'quiz' ? 'clipboard-check' : 'file-alt'; ?>"></i>
                                </div>
                                <div class="assignment-details">
                                    <div class="assignment-meta">
                                        <h3 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                        <div class="assignment-info">
                                            <span><i class="fas fa-calendar"></i> Due: <?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'No due date'; ?></span>
                                            <span><i class="fas fa-star"></i> <?php echo $assignment['max_points']; ?> points</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="assignment-status-badge">
                                    <div class="status-icon"><i class="fas fa-check-circle"></i></div>
                                    <span><?php echo $assignment['submission_count']; ?>/<?php echo $total_students; ?> Turned in</span>
                                </div>
                                <div class="assignment-arrow"><i class="fas fa-chevron-right"></i></div>
                            </div>
                            <?php endforeach; ?>

                            <?php foreach ($materials as $material): ?>
                            <div class="assignment-card material" data-id="<?php echo $material['id']; ?>" data-type="material">
                                <button class="delete-card-btn" onclick="deleteItem(event, 'material', <?php echo $material['id']; ?>)" title="Delete material">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <div class="assignment-type-icon" data-type="document">
                                    <i class="fas fa-<?php echo $material['material_type'] === 'video' ? 'video' : ($material['material_type'] === 'link' ? 'link' : 'file'); ?>"></i>
                                </div>
                                <div class="assignment-details">
                                    <div class="assignment-meta">
                                        <h3 class="assignment-title"><?php echo htmlspecialchars($material['title']); ?></h3>
                                        <div class="assignment-info">
                                            <span><i class="fas fa-clock"></i> Posted <?php echo date('M d, Y', strtotime($material['created_at'])); ?></span>
                                            <span><i class="fas fa-book"></i> <?php echo ucfirst($material['material_type']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="assignment-arrow"><i class="fas fa-chevron-right"></i></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quiz Tab -->
            <div class="tab-content <?php echo $active_tab === 'quiz' ? 'active' : ''; ?>" id="quiz-tab">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Quizzes</h2>
                        <button class="btn btn-primary" onclick="createQuiz()">
                            <i class="fas fa-plus"></i> Create Quiz
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($quizzes)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-clipboard-check"></i></div>
                            <h3>No quizzes yet</h3>
                            <p>Create your first quiz to assess your students</p>
                        </div>
                        <?php else: ?>
                        <div class="quiz-list">
                            <?php foreach ($quizzes as $quiz): ?>
                            <div class="quiz-card <?php echo $quiz['students_taken'] > 0 ? 'has-attempts' : ''; ?>" 
                                 data-quiz-id="<?php echo $quiz['id']; ?>"
                                 onclick="viewQuiz(<?php echo $quiz['id']; ?>, <?php echo $quiz['students_taken']; ?>)">
                                <button class="delete-card-btn" onclick="deleteItem(event, 'quiz', <?php echo $quiz['id']; ?>)" title="Delete quiz">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php if ($quiz['students_taken'] > 0): ?>
                                <div class="quiz-lock-indicator" title="Students have taken this quiz - editing is locked">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <?php endif; ?>
                                <div class="quiz-header">
                                    <div class="quiz-icon"><i class="fas fa-clipboard-check"></i></div>
                                    <div class="quiz-status-badge status-<?php echo $quiz['status']; ?>"><?php echo ucfirst($quiz['status']); ?></div>
                                </div>
                                <h3 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                                <div class="quiz-meta">
                                    <div class="quiz-meta-item">
                                        <i class="fas fa-calendar"></i>
                                        <span><?php echo $quiz['due_date'] ? date('M d, Y', strtotime($quiz['due_date'])) : 'No due date'; ?></span>
                                    </div>
                                    <div class="quiz-meta-item">
                                        <i class="fas fa-star"></i>
                                        <span><?php echo $quiz['total_points']; ?> points</span>
                                    </div>
                                </div>
                                <div class="quiz-stats-compact">
                                    <div class="stat-compact">
                                        <div class="stat-compact-value"><?php echo $quiz['attempt_count']; ?></div>
                                        <div class="stat-compact-label">Attempts</div>
                                    </div>
                                    <div class="stat-compact">
                                        <div class="stat-compact-value"><?php echo $quiz['students_taken']; ?>/<?php echo $total_students; ?></div>
                                        <div class="stat-compact-label">Students</div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ★ Stream Tab ★ -->
            <div class="tab-content <?php echo $active_tab === 'stream' ? 'active' : ''; ?>" id="stream-tab">

                <!-- Floating + Button (only visible on stream tab) -->
                <button id="streamFab" onclick="openAnnouncementModal()" title="New Announcement" style="
                    position:fixed;
                    bottom:36px;
                    right:36px;
                    width:56px;
                    height:56px;
                    border-radius:50%;
                    background:#2563eb;
                    color:#fff;
                    border:none;
                    font-size:26px;
                    cursor:pointer;
                    display:none;
                    align-items:center;
                    justify-content:center;
                    box-shadow:0 4px 18px rgba(37,99,235,0.45);
                    z-index:1000;
                    transition:transform .2s, box-shadow .2s;
                " onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 24px rgba(37,99,235,0.55)'"
                   onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 18px rgba(37,99,235,0.45)'">
                    <i class="fas fa-plus"></i>
                </button>

                <!-- Announcement Modal -->
                <div id="announcementModalBackdrop" style="
                    display:none;
                    position:fixed;
                    inset:0;
                    background:rgba(15,23,42,0.45);
                    z-index:2000;
                    align-items:center;
                    justify-content:center;
                    backdrop-filter:blur(3px);
                ">
                    <div id="announcementModal" style="
                        background:#fff;
                        border-radius:16px;
                        width:100%;
                        max-width:520px;
                        margin:0 20px;
                        box-shadow:0 20px 60px rgba(0,0,0,0.15);
                        transform:scale(0.95) translateY(10px);
                        opacity:0;
                        transition:all 0.25s cubic-bezier(0.4,0,0.2,1);
                    ">
                        <!-- Modal Header -->
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 24px 0;">
                            <h3 style="margin:0;font-size:17px;font-weight:700;color:#1e293b;">
                                <i class="fas fa-bullhorn" style="color:#2563eb;margin-right:8px;"></i>
                                New Announcement
                            </h3>
                            <button onclick="closeAnnouncementModal()" style="
                                background:none;border:none;cursor:pointer;
                                color:#94a3b8;font-size:18px;padding:4px;
                                border-radius:6px;transition:color .2s;
                            " onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <!-- Divider -->
                        <div style="height:1px;background:#f1f5f9;margin:16px 0 0;"></div>

                        <!-- Modal Body -->
                        <div style="padding:20px 24px;display:flex;flex-direction:column;gap:14px;">
                            <input 
                                type="text" 
                                id="announcementTitle" 
                                placeholder="Title (optional)"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s;width:100%;box-sizing:border-box;"
                                onfocus="this.style.borderColor='#2563eb'" 
                                onblur="this.style.borderColor='#e2e8f0'"
                            >
                            <textarea 
                                id="announcementContent" 
                                placeholder="Share something with your class..."
                                rows="5"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;outline:none;resize:vertical;transition:border-color .2s;width:100%;box-sizing:border-box;"
                                onfocus="this.style.borderColor='#2563eb'" 
                                onblur="this.style.borderColor='#e2e8f0'"
                            ></textarea>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#64748b;cursor:pointer;user-select:none;">
                                <input type="checkbox" id="announcementPinned" style="width:15px;height:15px;accent-color:#2563eb;">
                                <i class="fas fa-thumbtack" style="color:#f59e0b;"></i>
                                Pin this announcement
                            </label>
                        </div>

                        <!-- Modal Footer -->
                        <div style="display:flex;justify-content:flex-end;gap:10px;padding:0 24px 20px;">
                            <button onclick="closeAnnouncementModal()" style="
                                padding:9px 18px;border:1.5px solid #e2e8f0;background:#fff;
                                border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;
                                color:#64748b;font-family:inherit;transition:all .2s;
                            " onmouseover="this.style.borderColor='#94a3b8'" onmouseout="this.style.borderColor='#e2e8f0'">
                                Cancel
                            </button>
                            <button type="button" onclick="postAnnouncement()" class="btn btn-primary" id="postAnnouncementBtn">
                                <i class="fas fa-paper-plane"></i> Post
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Announcements list -->
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Announcements</h2>
                        <span class="student-count" id="announcementCount">
                            <?php echo count($announcements); ?> post<?php echo count($announcements) != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div id="announcementsList">
                            <?php if (empty($announcements)): ?>
                            <div class="empty-state" id="streamEmptyState">
                                <div class="empty-icon"><i class="fas fa-bullhorn"></i></div>
                                <h3>No announcements yet</h3>
                                <p>Post something to keep your class informed</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($announcements as $ann): ?>
                            <div class="announcement-item" id="ann-<?php echo $ann['id']; ?>" style="border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:14px;background:#fff;position:relative;">
                                <?php if ($ann['is_pinned']): ?>
                                <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#f59e0b;font-weight:600;margin-bottom:8px;">
                                    <i class="fas fa-thumbtack"></i> Pinned
                                </div>
                                <?php endif; ?>
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                                    <div style="flex:1;">
                                        <?php if ($ann['title']): ?>
                                        <h4 style="margin:0 0 6px;font-size:15px;font-weight:600;color:#1e293b;"><?php echo htmlspecialchars($ann['title']); ?></h4>
                                        <?php endif; ?>
                                        <p style="margin:0 0 10px;font-size:14px;color:#475569;line-height:1.6;white-space:pre-wrap;"><?php echo htmlspecialchars($ann['content']); ?></p>
                                        <span style="font-size:12px;color:#94a3b8;">
                                            <?php echo (new DateTime($ann['created_at']))->format('M j, Y • g:i A'); ?>
                                        </span>
                                    </div>
                                    <button 
                                        onclick="deleteAnnouncement(<?php echo $ann['id']; ?>)" 
                                        title="Delete"
                                        style="background:none;border:none;cursor:pointer;color:#cbd5e1;padding:4px;border-radius:6px;flex-shrink:0;transition:color .2s;"
                                        onmouseover="this.style.color='#ef4444'" 
                                        onmouseout="this.style.color='#cbd5e1'">
                                        <i class="fas fa-trash" style="font-size:13px;"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- People Tab -->
            <div class="tab-content <?php echo $active_tab === 'people' ? 'active' : ''; ?>" id="people-tab">
                <div class="content-card" style="margin-bottom: 24px;">
                    <div class="card-body">
                        <div class="class-code-display">
                            <div class="class-code-info">
                                <h3>Class Code</h3>
                                <div class="class-code-value"><?php echo htmlspecialchars($subject['class_code']); ?></div>
                            </div>
                            <button class="btn btn-outlined" id="copyCodeBtn">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                    </div>
                </div>

                <div class="content-card" style="margin-bottom: 24px;">
                    <div class="card-header">
                        <h2 class="card-title">Teachers</h2>
                    </div>
                    <div class="card-body">
                        <div class="people-list">
                            <div class="people-item">
                                <div class="people-avatar">
                                    <?php if ($teacher['profile_picture']): ?>
                                        <img src="../<?php echo htmlspecialchars($teacher['profile_picture']); ?>" 
                                             alt="<?php echo htmlspecialchars($teacher['fullname']); ?>"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <span style="display: none;"><?php echo strtoupper(substr($teacher['fullname'], 0, 1)); ?></span>
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($teacher['fullname'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="people-info">
                                    <div class="people-name"><?php echo htmlspecialchars($teacher['fullname']); ?></div>
                                    <div class="people-email"><?php echo htmlspecialchars($teacher['email']); ?></div>
                                </div>
                                <span class="people-role">Owner</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Students</h2>
                        <div class="header-actions">
                            <?php 
                            $sections = array_unique(array_filter(array_column($students, 'section')));
                            sort($sections);
                            ?>
                            <?php if (!empty($sections) && count($sections) > 1): ?>
                            <select class="section-filter" id="sectionFilter">
                                <option value="all">All Sections (<?php echo $total_students; ?>)</option>
                                <?php foreach ($sections as $section): ?>
                                    <?php $section_count = count(array_filter($students, fn($s) => $s['section'] === $section)); ?>
                                    <option value="<?php echo htmlspecialchars($section); ?>">
                                        <?php echo htmlspecialchars($section); ?> (<?php echo $section_count; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <span class="student-count"><?php echo $total_students; ?> student<?php echo $total_students != 1 ? 's' : ''; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($students)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-user-friends"></i></div>
                            <h3>No students yet</h3>
                            <p>Share the class code with your students so they can join</p>
                        </div>
                        <?php else: ?>
                        <div class="people-list">
                            <?php foreach ($students as $student): ?>
                            <div class="people-item" data-section="<?php echo htmlspecialchars($student['section']); ?>">
                                <div class="people-avatar">
                                    <?php if ($student['profile_picture']): ?>
                                        <img src="../../student/<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                             alt="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <span style="display: none;"><?php echo strtoupper(substr($student['first_name'], 0, 1)); ?></span>
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="people-info">
                                    <div class="people-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                    <div class="people-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                </div>
                                <div class="people-meta">
                                    <span class="section-badge"><?php echo htmlspecialchars($student['section']); ?></span>
                                    <button class="btn btn-text" data-student-id="<?php echo $student['id']; ?>">
                                        <i class="fas fa-chart-line"></i> View grades
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Grades Tab -->
            <div class="tab-content <?php echo $active_tab === 'grades' ? 'active' : ''; ?>" id="grades-tab">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Grades</h2>
                        <button class="btn btn-outlined" id="exportGradesBtn">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($students)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-chart-bar"></i></div>
                            <h3>No students enrolled</h3>
                            <p>Grades will appear here once students join your class</p>
                        </div>
                        <?php else: ?>
                        <div class="grades-table-wrapper">
                            <table class="grades-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <?php foreach ($assignments as $assignment): ?>
                                        <th><?php echo htmlspecialchars(substr($assignment['title'], 0, 20)); ?><?php echo strlen($assignment['title']) > 20 ? '...' : ''; ?></th>
                                        <?php endforeach; ?>
                                        <th>Overall</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <div class="student-cell">
                                                <div class="student-avatar-small">
                                                    <?php if ($student['profile_picture']): ?>
                                                        <img src="../../student/<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                             alt="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>"
                                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                        <span style="display: none;"><?php echo strtoupper(substr($student['first_name'], 0, 1)); ?></span>
                                                    <?php else: ?>
                                                        <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <span><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></span>
                                            </div>
                                        </td>
                                        <?php foreach ($assignments as $assignment):
                                            $stmt = $pdo->prepare("SELECT score FROM submissions WHERE assignment_id = ? AND student_id = ?");
                                            $stmt->execute([$assignment['id'], $student['id']]);
                                            $submission = $stmt->fetch(PDO::FETCH_ASSOC);
                                        ?>
                                        <td class="grade-cell">
                                            <?php if ($submission && $submission['score'] !== null): ?>
                                                <span class="grade-value"><?php echo number_format($submission['score'], 0); ?>%</span>
                                            <?php else: ?>
                                                <span class="grade-empty">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                        <td class="grade-cell">
                                            <?php if ($student['final_grade']): ?>
                                                <span class="grade-value grade-overall"><?php echo number_format($student['final_grade'], 1); ?>%</span>
                                            <?php else: ?>
                                                <span class="grade-empty">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Settings Tab -->
            <div class="tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="settings-tab">
                <div class="content-card" style="margin-bottom: 16px;">
                    <div class="card-header">
                        <h2 class="card-title">Class Details</h2>
                    </div>
                    <div class="card-body">
                        <div class="settings-list">
                            <div class="settings-item">
                                <span class="settings-label">Class name</span>
                                <span class="settings-value"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                            </div>
                            <div class="settings-item">
                                <span class="settings-label">Section</span>
                                <span class="settings-value"><?php echo htmlspecialchars($subject['section'] ?: 'None'); ?></span>
                            </div>
                            <div class="settings-item">
                                <span class="settings-label">Grade</span>
                                <span class="settings-value">Grade <?php echo htmlspecialchars($subject['grade']); ?></span>
                            </div>
                            <div class="settings-item">
                                <span class="settings-label">Description</span>
                                <span class="settings-value"><?php echo htmlspecialchars($subject['description'] ?: 'No description'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card" style="margin-bottom: 16px;">
                    <div class="card-header">
                        <h2 class="card-title">Class Code</h2>
                    </div>
                    <div class="card-body">
                        <div class="settings-list">
                            <div class="settings-item">
                                <span class="settings-label">Current code</span>
                                <span class="settings-value code-value"><?php echo htmlspecialchars($subject['class_code']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Manage Class</h2>
                    </div>
                    <div class="card-body">
                        <div class="settings-list">
                            <div class="settings-item">
                                <div>
                                    <div class="settings-label danger">Delete class</div>
                                    <div class="settings-description">Permanently delete this class</div>
                                </div>
                                <button class="btn btn-danger" id="deleteClassBtn">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        window.subjectData = {
            id: <?php echo $subject_id; ?>,
            classCode: '<?php echo $subject['class_code']; ?>'
        };
    </script>

    <script>
    // ================================
    // Logo Image Picker
    // ================================
    (function () {
        const backdrop    = document.getElementById('logoPickerBackdrop');
        const trigger     = document.getElementById('logoPickerTrigger');
        const closeBtn    = document.getElementById('logoPickerClose');
        const cancelBtn   = document.getElementById('logoCancelBtn');
        const dropZone    = document.getElementById('logoDropZone');
        const fileInput   = document.getElementById('logoFileInput');
        const previewArea = document.getElementById('logoPreviewArea');
        const previewImg  = document.getElementById('logoPreviewImg');
        const changeLink  = document.getElementById('logoChangeLink');
        const saveBtn     = document.getElementById('logoSaveBtn');
        const headerLogo  = document.getElementById('subjectLogoImg');

        let selectedFile = null, selectedBase64 = null;

        function openPicker()  { backdrop.classList.add('open'); resetPicker(); }
        function closePicker() { backdrop.classList.remove('open'); }
        function resetPicker() {
            selectedFile = selectedBase64 = null;
            fileInput.value = '';
            previewArea.classList.remove('visible');
            dropZone.style.display = '';
            saveBtn.classList.remove('visible');
        }

        function handleFile(file) {
            if (!file) return;
            const allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
            if (!allowed.includes(file.type)) { showNotification('Please select a PNG, JPG, GIF, or WEBP image.', 'error'); return; }
            if (file.size > 5 * 1024 * 1024) { showNotification('Image must be under 5MB.', 'error'); return; }
            selectedFile = file;
            const reader = new FileReader();
            reader.onload = e => {
                selectedBase64 = e.target.result;
                previewImg.src = selectedBase64;
                previewArea.classList.add('visible');
                dropZone.style.display = 'none';
                saveBtn.classList.add('visible');
            };
            reader.readAsDataURL(file);
        }

        trigger.addEventListener('click', openPicker);
        closeBtn.addEventListener('click', closePicker);
        cancelBtn.addEventListener('click', closePicker);
        backdrop.addEventListener('click', e => { if (e.target === backdrop) closePicker(); });
        fileInput.addEventListener('change', function() { handleFile(this.files[0]); });
        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
        dropZone.addEventListener('drop', e => { e.preventDefault(); dropZone.classList.remove('drag-over'); handleFile(e.dataTransfer.files[0]); });
        changeLink.addEventListener('click', () => {
            previewArea.classList.remove('visible');
            dropZone.style.display = '';
            saveBtn.classList.remove('visible');
            fileInput.value = '';
            selectedFile = selectedBase64 = null;
        });

        saveBtn.addEventListener('click', function() {
            if (!selectedBase64) return;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            const formData = new FormData();
            formData.append('action', 'update_logo');
            formData.append('subject_id', window.subjectData.id);
            formData.append('logo_data', selectedBase64);
            fetch('update_subject_logo.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (headerLogo) { headerLogo.src = selectedBase64; headerLogo.style.display = ''; headerLogo.nextElementSibling && (headerLogo.nextElementSibling.style.display = 'none'); }
                        showNotification('Logo updated successfully!', 'success');
                        closePicker();
                    } else {
                        showNotification('Failed to save logo: ' + (data.message || 'Unknown error'), 'error');
                    }
                })
                .catch(err => showNotification('Error saving logo: ' + err.message, 'error'))
                .finally(() => { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Logo'; });
        });
    })();

    // ================================
    // Tab Management
    // ================================
    const tabButtons  = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    tabButtons.forEach(button => {
        button.addEventListener('click', () => switchTab(button.getAttribute('data-tab')));
    });

    function switchTab(tabName) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.pushState({}, '', url);
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabContents.forEach(content => content.classList.remove('active'));
        document.querySelector(`[data-tab="${tabName}"]`)?.classList.add('active');
        document.getElementById(`${tabName}-tab`)?.classList.add('active');

        // Show FAB only on stream tab
        const fab = document.getElementById('streamFab');
        if (fab) fab.style.display = tabName === 'stream' ? 'flex' : 'none';
    }

    // ================================
    // Announcement Modal
    // ================================
    function openAnnouncementModal() {
        const backdrop = document.getElementById('announcementModalBackdrop');
        const modal    = document.getElementById('announcementModal');
        backdrop.style.display = 'flex';
        requestAnimationFrame(() => {
            modal.style.opacity   = '1';
            modal.style.transform = 'scale(1) translateY(0)';
        });
        setTimeout(() => document.getElementById('announcementContent').focus(), 200);
    }

    function closeAnnouncementModal() {
        const backdrop = document.getElementById('announcementModalBackdrop');
        const modal    = document.getElementById('announcementModal');
        modal.style.opacity   = '0';
        modal.style.transform = 'scale(0.95) translateY(10px)';
        setTimeout(() => {
            backdrop.style.display = 'none';
            document.getElementById('announcementTitle').value    = '';
            document.getElementById('announcementContent').value  = '';
            document.getElementById('announcementPinned').checked = false;
        }, 250);
    }

    // Close modal on backdrop click
    document.getElementById('announcementModalBackdrop')?.addEventListener('click', function(e) {
        if (e.target === this) closeAnnouncementModal();
    });

    // Close modal on ESC (extend existing keydown handler below)

    // ================================
    // Create Menu Dropdown
    // ================================
    const createMenuBtn = document.getElementById('createMenuBtn');
    const createMenu    = document.getElementById('createMenu');

    if (createMenuBtn && createMenu) {
        createMenuBtn.addEventListener('click', e => { e.stopPropagation(); createMenu.classList.toggle('show'); });
        document.addEventListener('click', e => { if (!createMenu.contains(e.target) && !createMenuBtn.contains(e.target)) createMenu.classList.remove('show'); });
        createMenu.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', () => { handleCreateAction(item.getAttribute('data-action')); createMenu.classList.remove('show'); });
        });
    }

    function handleCreateAction(action) {
        const id = window.subjectData?.id;
        if (action === 'assignment') window.location.href = `create_assignment.php?subject_id=${id}`;
        else if (action === 'quiz')   window.location.href = `create_assignment.php?subject_id=${id}&type=quiz`;
        else if (action === 'material') window.location.href = `create_material.php?subject_id=${id}`;
    }

    // ================================
    // Delete Item (assignments / materials / quizzes)
    // ================================
    function deleteItem(event, type, id) {
        event.stopPropagation();
        if (!confirm(`Are you sure you want to delete this ${type}? This action cannot be undone.`)) return;
        if (!confirm(`This is your final warning. All related data will be permanently deleted. Continue?`)) return;
        showLoading();
        fetch('delete_item.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type, id })
        })
        .then(r => r.text().then(text => { try { return JSON.parse(text); } catch(e) { throw new Error('Invalid JSON: ' + text.substring(0, 200)); } }))
        .then(data => {
            hideLoading();
            if (data.success) {
                showNotification(`${type.charAt(0).toUpperCase() + type.slice(1)} deleted successfully!`, 'success');
                const card = event.target.closest('.assignment-card, .quiz-card');
                if (card) {
                    card.style.transition = 'all 0.3s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(-20px)';
                    setTimeout(() => { card.remove(); const list = document.querySelector('.assignments-list, .quiz-list'); if (list && !list.children.length) location.reload(); }, 300);
                }
            } else {
                showNotification('Failed to delete: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(err => { hideLoading(); showNotification('Error: ' + err.message, 'error'); });
    }

    // ================================
    // Quiz Functions
    // ================================
    function createQuiz() { window.location.href = `../../quiz/quiz_creator.php?subject_id=${window.subjectData?.id}`; }
    function viewQuiz(quizId) { window.location.href = `quiz_view.php?quiz_id=${quizId}`; }

    // ================================
    // Copy Class Code
    // ================================
    document.getElementById('copyCodeBtn')?.addEventListener('click', () => {
        navigator.clipboard.writeText(window.subjectData?.classCode)
            .then(() => {
                showNotification('Class code copied!', 'success');
                const btn = document.getElementById('copyCodeBtn');
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => btn.innerHTML = orig, 2000);
            })
            .catch(() => showNotification('Failed to copy class code', 'error'));
    });

    // ================================
    // Section Filter
    // ================================
    document.getElementById('sectionFilter')?.addEventListener('change', e => {
        const selected = e.target.value;
        document.querySelectorAll('.people-item[data-section]').forEach(item => {
            item.style.display = (selected === 'all' || item.getAttribute('data-section') === selected) ? 'flex' : 'none';
        });
    });

    // ================================
    // Assignment Card Navigation
    // ================================
    document.querySelectorAll('.assignment-card').forEach(card => {
        card.addEventListener('click', e => {
            if (e.target.closest('.delete-card-btn')) return;
            const id = card.getAttribute('data-id');
            window.location.href = card.classList.contains('material') ? `view_material.php?id=${id}` : `view_assignment.php?id=${id}`;
        });
    });

    // ================================
    // Student Grades Navigation
    // ================================
    document.querySelectorAll('[data-student-id]').forEach(btn => {
        btn.addEventListener('click', e => { e.stopPropagation(); window.location.href = `student_grades.php?student_id=${btn.getAttribute('data-student-id')}&subject_id=${window.subjectData?.id}`; });
    });

    // ================================
    // Export Grades
    // ================================
    document.getElementById('exportGradesBtn')?.addEventListener('click', () => {
        window.location.href = `export_grades.php?subject_id=${window.subjectData?.id}`;
    });

    // ================================
    // Delete Class
    // ================================
    document.getElementById('deleteClassBtn')?.addEventListener('click', () => {
        if (confirm('Are you sure you want to delete this class? This action cannot be undone.')) {
            if (confirm('This is your final warning. All assignments, materials, and grades will be permanently deleted. Are you absolutely sure?')) {
                window.location.href = `delete_subject.php?id=${window.subjectData?.id}`;
            }
        }
    });

    // ================================
    // ★ Stream / Announcements ★
    // ================================
    function postAnnouncement() {
        const title   = document.getElementById('announcementTitle').value.trim();
        const content = document.getElementById('announcementContent').value.trim();
        const pinned  = document.getElementById('announcementPinned').checked;
        const btn     = document.getElementById('postAnnouncementBtn');

        if (!content) {
            showNotification('Please write something before posting.', 'error');
            document.getElementById('announcementContent').focus();
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';

        fetch('post_announcement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ subject_id: window.subjectData.id, title, content, is_pinned: pinned ? 1 : 0 })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeAnnouncementModal();
                prependAnnouncement(data.announcement);
                updateAnnouncementCount(1);
                showNotification('Announcement posted!', 'success');
            } else {
                showNotification('Failed to post: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(err => showNotification('Error: ' + err.message, 'error'))
        .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Post'; });
    }

    function prependAnnouncement(ann) {
        const list  = document.getElementById('announcementsList');
        const empty = document.getElementById('streamEmptyState');
        if (empty) empty.remove();

        const div = document.createElement('div');
        div.id = 'ann-' + ann.id;
        div.style.cssText = 'border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:14px;background:#fff;position:relative;opacity:0;transform:translateY(-10px);transition:all 0.4s cubic-bezier(0.4,0,0.2,1);';

        const pinnedBadge = ann.is_pinned
            ? `<div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#f59e0b;font-weight:600;margin-bottom:8px;"><i class="fas fa-thumbtack"></i> Pinned</div>`
            : '';
        const titleHTML = ann.title
            ? `<h4 style="margin:0 0 6px;font-size:15px;font-weight:600;color:#1e293b;">${escapeHtml(ann.title)}</h4>`
            : '';

        div.innerHTML = `
            ${pinnedBadge}
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                <div style="flex:1;">
                    ${titleHTML}
                    <p style="margin:0 0 10px;font-size:14px;color:#475569;line-height:1.6;white-space:pre-wrap;">${escapeHtml(ann.content)}</p>
                    <span style="font-size:12px;color:#94a3b8;">${ann.created_at}</span>
                </div>
                <button onclick="deleteAnnouncement(${ann.id})" title="Delete"
                    style="background:none;border:none;cursor:pointer;color:#cbd5e1;padding:4px;border-radius:6px;flex-shrink:0;transition:color .2s;"
                    onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#cbd5e1'">
                    <i class="fas fa-trash" style="font-size:13px;"></i>
                </button>
            </div>`;

        list.insertBefore(div, list.firstChild);
        requestAnimationFrame(() => { div.style.opacity = '1'; div.style.transform = 'translateY(0)'; });
    }

    function deleteAnnouncement(id) {
        if (!confirm('Delete this announcement?')) return;

        fetch('delete_announcement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const el = document.getElementById('ann-' + id);
                if (el) {
                    el.style.transition = 'all 0.3s ease';
                    el.style.opacity = '0';
                    el.style.transform = 'translateX(20px)';
                    setTimeout(() => {
                        el.remove();
                        updateAnnouncementCount(-1);
                        if (!document.getElementById('announcementsList').children.length) {
                            document.getElementById('announcementsList').innerHTML = `
                                <div class="empty-state" id="streamEmptyState">
                                    <div class="empty-icon"><i class="fas fa-bullhorn"></i></div>
                                    <h3>No announcements yet</h3>
                                    <p>Post something to keep your class informed</p>
                                </div>`;
                        }
                    }, 300);
                }
                showNotification('Announcement deleted.', 'success');
            } else {
                showNotification('Failed to delete.', 'error');
            }
        })
        .catch(err => showNotification('Error: ' + err.message, 'error'));
    }

    function updateAnnouncementCount(delta) {
        const el = document.getElementById('announcementCount');
        if (!el) return;
        const next = (parseInt(el.textContent) || 0) + delta;
        el.textContent = next + ' post' + (next !== 1 ? 's' : '');
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    // ================================
    // Keyboard Shortcuts
    // ================================
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); createMenu?.classList.toggle('show'); }
        if (e.key === 'Escape') { createMenu?.classList.remove('show'); document.getElementById('logoPickerBackdrop').classList.remove('open'); closeAnnouncementModal(); }
        if (e.altKey && e.key >= '1' && e.key <= '6') {
            e.preventDefault();
            ['classwork','quiz','stream','people','grades','settings'][parseInt(e.key) - 1] && switchTab(['classwork','quiz','stream','people','grades','settings'][parseInt(e.key) - 1]);
        }
    });

    // ================================
    // Notification System
    // ================================
    function showNotification(message, type = 'info') {
        document.querySelector('.notification')?.remove();
        const n = document.createElement('div');
        n.className = `notification notification-${type}`;
        n.innerHTML = `<div style="display:flex;align-items:center;gap:10px;"><i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i><span>${message}</span></div>`;
        n.style.cssText = `position:fixed;top:80px;right:24px;background:${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#2563eb'};color:white;padding:12px 20px;border-radius:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,.1);z-index:10000;font-size:14px;font-weight:500;animation:slideIn .3s ease;`;
        const s = document.createElement('style');
        s.textContent = `@keyframes slideIn{from{transform:translateX(400px);opacity:0}to{transform:translateX(0);opacity:1}}@keyframes slideOut{from{opacity:1}to{transform:translateX(400px);opacity:0}}`;
        document.head.appendChild(s);
        document.body.appendChild(n);
        setTimeout(() => { n.style.animation = 'slideOut .3s ease'; setTimeout(() => n.remove(), 300); }, 3000);
    }

    // ================================
    // Loading State
    // ================================
    function showLoading() {
        const l = document.createElement('div');
        l.id = 'global-loader';
        l.style.cssText = 'position:fixed;inset:0;background:rgba(255,255,255,.9);display:flex;align-items:center;justify-content:center;z-index:99999;';
        l.innerHTML = '<div style="width:40px;height:40px;border:4px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin .8s linear infinite;"></div>';
        const s = document.createElement('style');
        s.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(s);
        document.body.appendChild(l);
    }

    function hideLoading() { document.getElementById('global-loader')?.remove(); }

    // ================================
    // Init
    // ================================
    document.addEventListener('DOMContentLoaded', () => {
        const params = new URLSearchParams(window.location.search);
        const successMsg = params.get('success');
        const errorMsg   = params.get('error');
        if (successMsg) showNotification(decodeURIComponent(successMsg), 'success');
        if (errorMsg)   showNotification(decodeURIComponent(errorMsg), 'error');

        // Show FAB if stream tab is already active on load
        const activeTab = params.get('tab') || 'classwork';
        const fab = document.getElementById('streamFab');
        if (fab && activeTab === 'stream') fab.style.display = 'flex';
    });

    window.TeacherView = { switchTab, showNotification, showLoading, hideLoading };
    </script>
</body>
</html>