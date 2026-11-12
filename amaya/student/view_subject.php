<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Get subject ID from URL
$subject_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$subject_id) {
    header("Location: student_dashb.php");
    exit();
}

// Verify student is enrolled in this subject and get subject details
$stmt = $conn->prepare("
    SELECT s.*, 
           t.fullname AS teacher_name, 
           t.profile_picture AS teacher_profile_picture,
           ss.enrollment_date, 
           ss.status AS enrollment_status
    FROM subjects s
    JOIN teachers t ON s.teacher_id = t.id
    JOIN student_subjects ss ON s.id = ss.subject_id
    WHERE s.id = ? AND ss.student_id = ? AND ss.status = 'active'
");

if (!$stmt) {
    header("Location: student_dashb.php");
    exit();
}

$stmt->bind_param("ii", $subject_id, $student_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subject) {
    header("Location: student_dashb.php");
    exit();
}

// Get announcements for this subject
$stmt = $conn->prepare("
    SELECT a.*, 
           t.fullname AS author_name, 
           t.profile_picture
    FROM announcements a
    JOIN teachers t ON a.teacher_id = t.id
    WHERE a.subject_id = ?
    ORDER BY a.is_pinned DESC, a.created_at DESC
    LIMIT 10
");

if (!$stmt) {
    $announcements = [];
} else {
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get upcoming assignments
$stmt = $conn->prepare("
    SELECT a.*, 
           s.submission_text,
           s.status AS submission_status,
           s.score,
           s.submitted_at
    FROM assignments a
    LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = ?
    WHERE a.subject_id = ? AND a.status = 'published'
    ORDER BY a.due_date ASC
    LIMIT 5
");

if (!$stmt) {
    $assignments = [];
} else {
    $stmt->bind_param("ii", $student_id, $subject_id);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get class materials
$stmt = $conn->prepare("
    SELECT m.*, 
           t.fullname AS author_name
    FROM materials m
    JOIN teachers t ON m.teacher_id = t.id
    WHERE m.subject_id = ?
    ORDER BY m.created_at DESC
    LIMIT 5
");

if (!$stmt) {
    $materials = [];
} else {
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $materials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Helper function to get correct teacher profile picture path
function getTeacherProfilePicture($profile_picture) {
    if (empty($profile_picture)) return null;

    $cleanPath = preg_replace('#^(upload/|upload|uploads/|uploads)#', '', $profile_picture);
    return '../teacher/upload/' . $cleanPath;
}

// Helper function to get teacher initials for avatar fallback
function getInitials($fullname) {
    $initials = '';
    foreach (explode(' ', $fullname) as $part) {
        if (!empty($part)) $initials .= strtoupper($part[0]);
    }
    return substr($initials, 0, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($subject['subject_name']); ?> - Classroom</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/view_subject.css">
</head>
<body>
<style type="text/css">
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
   Modern Navigation Bar
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
   Subject Header Card
   ================================ */
.subject-header {
    padding: var(--spacing-xl) 0;
}

.header-card {
    background: var(--color-bg-primary);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    position: relative;
}

.header-bg {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 160px;
    z-index: 0;
    overflow: hidden;
}

.header-pattern {
    position: absolute;
    inset: 0;
    opacity: 0.6;
}

/* Wave Animation */
.header-pattern::before,
.header-pattern::after {
    content: '';
    position: absolute;
    width: 200%;
    height: 100%;
    top: 0;
    left: 0;
    background-repeat: repeat-x;
}

.header-pattern::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 120' preserveAspectRatio='none'%3E%3Cpath d='M0,0V46.29c47.79,22.2,103.59,32.17,158,28,70.36-5.37,136.33-33.31,206.8-37.5C438.64,32.43,512.34,53.67,583,72.05c69.27,18,138.3,24.88,209.4,13.08,36.15-6,69.85-17.84,104.45-29.34C989.49,25,1113-14.29,1200,52.47V0Z' opacity='.25'/%3E%3Cpath d='M0,0V15.81C13,36.92,27.64,56.86,47.69,72.05,99.41,111.27,165,111,224.58,91.58c31.15-10.15,60.09-26.07,89.67-39.8,40.92-19,84.73-46,130.83-49.67,36.26-2.85,70.9,9.42,98.6,31.56,31.77,25.39,62.32,62,103.63,73,40.44,10.79,81.35-6.69,119.13-24.28s75.16-39,116.92-43.05c59.73-5.85,113.28,22.88,168.9,38.84,30.2,8.66,59,6.17,87.09-7.5,22.43-10.89,48-26.93,60.65-49.24V0Z' opacity='.5'/%3E%3Cpath d='M0,0V5.63C149.93,59,314.09,71.32,475.83,42.57c43-7.64,84.23-20.12,127.61-26.46,59-8.63,112.48,12.24,165.56,35.4C827.93,77.22,886,95.24,951.2,90c86.53-7,172.46-45.71,248.8-84.81V0Z'/%3E%3C/svg%3E");
    background-size: 50% 100%;
    animation: wave-slow 25s linear infinite;
    opacity: 0.15;
}

.header-pattern::after {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 120' preserveAspectRatio='none'%3E%3Cpath d='M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z'/%3E%3C/svg%3E");
    background-size: 50% 100%;
    animation: wave-fast 20s linear infinite reverse;
    opacity: 0.1;
}

@keyframes wave-slow {
    0% {
        transform: translateX(0);
    }
    100% {
        transform: translateX(-50%);
    }
}

@keyframes wave-fast {
    0% {
        transform: translateX(0);
    }
    100% {
        transform: translateX(50%);
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
    box-shadow: var(--shadow-lg);
    border: 3px solid var(--color-bg-primary);
    overflow: hidden;
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
    color: white;
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
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
    border-radius: var(--radius-lg);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.subject-title {
    font-family: var(--font-display);
    font-size: 32px;
    font-weight: 700;
    color: var(--color-text-primary);
    margin-bottom: var(--spacing-md);
    line-height: 1.2;
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
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-light) 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    overflow: hidden;
}

.teacher-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.teacher-details {
    display: flex;
    flex-direction: column;
}

.teacher-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.teacher-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-primary);
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

.tab-button svg {
    opacity: 0.7;
    transition: opacity 0.2s ease;
}

.tab-button:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-secondary);
}

.tab-button:hover svg {
    opacity: 1;
}

.tab-button.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
}

.tab-button.active svg {
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
}

.card-header {
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--color-border);
}

.card-title {
    font-family: var(--font-display);
    font-size: 20px;
    font-weight: 700;
    color: var(--color-text-primary);
}

.card-body {
    padding: var(--spacing-md);
}

/* ================================
   Announcements
   ================================ */
.announcements-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.announcement-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-light);
    border-radius: var(--radius-md);
    padding: var(--spacing-lg);
    transition: all 0.2s ease;
    position: relative;
}

.announcement-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--color-border);
}

.pinned-indicator {
    position: absolute;
    top: var(--spacing-md);
    right: var(--spacing-md);
    display: flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
    padding: 6px 12px;
    border-radius: var(--radius-lg);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.announcement-header {
    margin-bottom: var(--spacing-md);
}

.author-info {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.author-avatar-small {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-light) 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    flex-shrink: 0;
    overflow: hidden;
}

.author-avatar-small img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.author-meta {
    display: flex;
    flex-direction: column;
}

.author-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.post-time {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.announcement-body {
    padding-left: 48px;
}

.announcement-title {
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: var(--spacing-xs);
}

.announcement-text {
    font-size: 14px;
    line-height: 1.7;
    color: var(--color-text-secondary);
}

/* ================================
   Assignments
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

.assignment-card.submitted {
    border-left-color: var(--color-success);
    background: linear-gradient(90deg, rgba(16, 185, 129, 0.03) 0%, transparent 100%);
}

.assignment-card.graded {
    border-left-color: var(--color-primary);
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.03) 0%, transparent 100%);
}

.assignment-card.missing {
    border-left-color: var(--color-danger);
    background: linear-gradient(90deg, rgba(239, 68, 68, 0.03) 0%, transparent 100%);
}

.assignment-card.upcoming {
    border-left-color: var(--color-warning);
    background: linear-gradient(90deg, rgba(245, 158, 11, 0.03) 0%, transparent 100%);
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
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: var(--color-danger);
}

.assignment-type-icon[data-type="project"] {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: var(--color-success);
}

.assignment-type-icon[data-type="exam"] {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: var(--color-warning);
}

.assignment-type-icon[data-type="classwork"] {
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #4f46e5;
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

.assignment-info svg {
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
}

.assignment-card.graded .assignment-status-badge {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: var(--color-primary);
}

.assignment-card.submitted .assignment-status-badge {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: var(--color-success);
}

.assignment-card.missing .assignment-status-badge {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: var(--color-danger);
}

.assignment-card.upcoming .assignment-status-badge {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
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
   Materials
   ================================ */
.materials-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--spacing-md);
}

.material-card {
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--spacing-lg);
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.material-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, transparent 0%, var(--color-primary) 100%);
    opacity: 0;
    transition: opacity 0.2s ease;
}

.material-card:hover {
    box-shadow: var(--shadow-lg);
    transform: translateY(-4px);
    border-color: var(--color-primary-light);
}

.material-card:hover::before {
    opacity: 1;
}

.material-icon-wrapper {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease;
    flex-shrink: 0;
}

.material-card:hover .material-icon-wrapper {
    transform: scale(1.1);
}

.material-icon-wrapper[data-type="file"] {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}

.material-icon-wrapper[data-type="link"] {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: var(--color-primary);
}

.material-icon-wrapper[data-type="video"] {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: var(--color-danger);
}

.material-icon-wrapper[data-type="document"] {
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #4f46e5;
}

.material-content {
    flex: 1;
    min-width: 0;
}

.material-title {
    font-family: var(--font-display);
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 6px;
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    word-wrap: break-word;
    word-break: break-word;
}

.material-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.material-separator {
    opacity: 0.5;
}

.material-action {
    position: absolute;
    top: var(--spacing-md);
    right: var(--spacing-md);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-bg-secondary);
    color: var(--color-text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: all 0.2s ease;
}

.material-card:hover .material-action {
    opacity: 1;
    background: var(--color-primary);
    color: white;
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
    
    .subject-title {
        font-size: 24px;
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
    
    .card-body {
        padding: var(--spacing-sm);
    }
    
    .announcement-body {
        padding-left: 0;
    }
    
    .materials-grid {
        grid-template-columns: 1fr;
    }
    
    .assignment-card {
        flex-wrap: wrap;
    }
    
    .assignment-arrow {
        display: none;
    }
}

@media (max-width: 480px) {
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

.subject-header {
    padding: var(--spacing-xl) 0;
}

.header-card {
    /* Full gradient background using theme color — like teacher view */
    background: linear-gradient(135deg,
        var(--banner-color, #2563eb) 0%,
        var(--banner-color-dark, #1d4ed8) 100%
    );
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow:
        0 20px 60px rgba(0, 0, 0, 0.18),
        0 4px 16px rgba(0, 0, 0, 0.10);
    position: relative;
    min-height: 240px;
    border: none !important; /* remove old thin border */
}

/* Background layer container */
.header-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    /* reset old inline background */
    background: none !important;
}

/* Radial glow overlays for depth */
.header-pattern {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 60% 80% at 10% 40%, rgba(255,255,255,0.12) 0%, transparent 60%),
        radial-gradient(ellipse 40% 60% at 90% 80%, rgba(255,255,255,0.08) 0%, transparent 55%);
    /* kill old wave animations */
    animation: none !important;
}

.header-pattern::before,
.header-pattern::after {
    display: none !important;
}

/* ── Math Symbols SVG Layer ─────────────────────────────────── */
.math-symbols-layer {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    overflow: hidden;
}

/* Each symbol text element */
.math-sym {
    font-family: 'Georgia', 'Times New Roman', serif;
    fill: rgba(255, 255, 255, 0.13);
    user-select: none;
    will-change: transform;
}

/* Slightly bolder for operators */
.math-sym.bold {
    fill: rgba(255, 255, 255, 0.18);
    font-weight: 700;
}

/* ── Float animations (varied offsets for organic feel) ─────── */
@keyframes mathFloat1 {
    0%, 100% { transform: translateY(0px)   rotate(0deg);   opacity: 0.9; }
    50%       { transform: translateY(-22px) rotate(6deg);   opacity: 0.6; }
}
@keyframes mathFloat2 {
    0%, 100% { transform: translateY(0px)   rotate(0deg);   opacity: 0.8; }
    50%       { transform: translateY(-15px) rotate(-5deg);  opacity: 0.5; }
}
@keyframes mathFloat3 {
    0%, 100% { transform: translateY(0px)   translateX(0px);  opacity: 0.9; }
    33%       { transform: translateY(-18px) translateX(8px);  opacity: 0.6; }
    66%       { transform: translateY(-8px)  translateX(-6px); opacity: 0.8; }
}
@keyframes mathFloat4 {
    0%, 100% { transform: translateY(0px)  scale(1);    opacity: 0.7; }
    50%       { transform: translateY(-12px) scale(1.1); opacity: 0.5; }
}
@keyframes mathSpin {
    0%   { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@keyframes mathPulse {
    0%, 100% { opacity: 0.9; transform: scale(1); }
    50%       { opacity: 0.4; transform: scale(1.15); }
}
@keyframes mathDrift {
    0%   { transform: translateX(0px)   translateY(0px); }
    25%  { transform: translateX(10px)  translateY(-12px); }
    50%  { transform: translateX(-6px)  translateY(-20px); }
    75%  { transform: translateX(-12px) translateY(-8px); }
    100% { transform: translateX(0px)   translateY(0px); }
}

/* Assign animations per symbol class */
.sym-pi       { animation: mathFloat1  9s 0.0s ease-in-out infinite; }
.sym-sigma    { animation: mathFloat3 11s 0.5s ease-in-out infinite; }
.sym-sqrt     { animation: mathFloat2  8s 1.2s ease-in-out infinite; }
.sym-integral { animation: mathDrift  14s 0.3s ease-in-out infinite; }
.sym-delta    { animation: mathFloat4  7s 1.8s ease-in-out infinite; }
.sym-theta    { animation: mathFloat1 10s 0.9s ease-in-out infinite; }
.sym-inf      { animation: mathFloat3 12s 2.1s ease-in-out infinite; }
.sym-plus     { animation: mathPulse   6s 0.4s ease-in-out infinite; }
.sym-equals   { animation: mathFloat2  9s 1.5s ease-in-out infinite; }
.sym-div      { animation: mathFloat4  8s 0.7s ease-in-out infinite; }
.sym-alpha    { animation: mathDrift  13s 1.0s ease-in-out infinite; }
.sym-beta     { animation: mathFloat1 10s 2.5s ease-in-out infinite; }
.sym-lambda   { animation: mathFloat2  7s 0.2s ease-in-out infinite; }
.sym-omega    { animation: mathFloat3 11s 1.7s ease-in-out infinite; }
.sym-nabla    { animation: mathFloat4  9s 0.6s ease-in-out infinite; }
.sym-approx   { animation: mathPulse   8s 2.0s ease-in-out infinite; }
.sym-psi      { animation: mathFloat1 12s 1.3s ease-in-out infinite; }
.sym-mu       { animation: mathDrift  10s 0.8s ease-in-out infinite; }

/* ── Header Content (matches teacher view layout) ─────────── */
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
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
    border: 3px solid rgba(255, 255, 255, 0.25);
    overflow: hidden;
    /* Override old inline gradient — keep white bg for logo clarity */
    background: rgba(255, 255, 255, 0.95) !important;
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
    /* colored letter matching theme */
    background: linear-gradient(135deg, var(--banner-color, #2563eb), var(--banner-color-light, #3b82f6));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.subject-info-main {
    flex: 1;
    padding-top: var(--spacing-xs);
}

/* Tags — white pill style like teacher view */
.subject-tags {
    display: flex;
    gap: var(--spacing-xs);
    margin-bottom: var(--spacing-sm);
}

.tag {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.20);
    color: white;
    border-radius: var(--radius-lg);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.18);
}

/* Title — white on gradient */
.subject-title {
    font-family: var(--font-display);
    font-size: 34px;
    font-weight: 700;
    color: white;
    margin-bottom: var(--spacing-md);
    line-height: 1.2;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

/* Teacher info */
.teacher-info {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.teacher-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.20);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.30);
    backdrop-filter: blur(8px);
}

.teacher-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.teacher-label {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.teacher-name {
    font-size: 14px;
    font-weight: 600;
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: var(--spacing-lg);
    }
    .subject-tags  { justify-content: center; }
    .teacher-info  { justify-content: center; }
    .subject-title { font-size: 26px; }
}    
</style>
    <!-- Modern Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-left">
                <button class="back-button" onclick="window.location.href='student_dashb.php'" aria-label="Back to dashboard">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="navbar-brand">
                    <span class="brand-text"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                </div>
            </div>
            <div class="navbar-right">
                <div class="class-code">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M8 1L3 4V7C3 10.5 5.5 13.5 8 14C10.5 13.5 13 10.5 13 7V4L8 1Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span><?php echo htmlspecialchars($subject['class_code']); ?></span>
                </div>
            </div>
        </div>
    </nav>

<div class="subject-header">
    <div class="container">
        <div class="header-card" style="
            --banner-color: <?php echo htmlspecialchars($subject['theme_color']); ?>;
            --banner-color-dark: <?php echo htmlspecialchars($subject['theme_color']); ?>cc;
            --banner-color-light: <?php echo htmlspecialchars($subject['theme_color']); ?>99;
        ">

            <!-- Background layers -->
            <div class="header-bg" style="background: none !important;">
                <div class="header-pattern"></div>

                <!-- ✦ Floating Math Symbols SVG ✦ -->
                <svg class="math-symbols-layer"
                     xmlns="http://www.w3.org/2000/svg"
                     viewBox="0 0 1200 260"
                     preserveAspectRatio="xMidYMid slice">

                    <!-- π  pi -->
                    <text class="math-sym bold sym-pi"    x="60"   y="80"  font-size="52">π</text>

                    <!-- Σ  sigma -->
                    <text class="math-sym bold sym-sigma" x="180"  y="200" font-size="46">Σ</text>

                    <!-- √  square root -->
                    <text class="math-sym sym-sqrt"       x="290"  y="55"  font-size="40">√</text>

                    <!-- ∫  integral -->
                    <text class="math-sym bold sym-integral" x="400" y="190" font-size="58">∫</text>

                    <!-- Δ  delta -->
                    <text class="math-sym sym-delta"      x="490"  y="70"  font-size="38">Δ</text>

                    <!-- θ  theta -->
                    <text class="math-sym sym-theta"      x="580"  y="220" font-size="44">θ</text>

                    <!-- ∞  infinity -->
                    <text class="math-sym bold sym-inf"   x="660"  y="50"  font-size="42">∞</text>

                    <!-- +  plus -->
                    <text class="math-sym bold sym-plus"  x="760"  y="185" font-size="48">+</text>

                    <!-- =  equals -->
                    <text class="math-sym sym-equals"     x="835"  y="90"  font-size="36">=</text>

                    <!-- ÷  division -->
                    <text class="math-sym sym-div"        x="910"  y="215" font-size="40">÷</text>

                    <!-- α  alpha -->
                    <text class="math-sym sym-alpha"      x="980"  y="65"  font-size="38">α</text>

                    <!-- β  beta -->
                    <text class="math-sym sym-beta"       x="1055" y="195" font-size="42">β</text>

                    <!-- λ  lambda -->
                    <text class="math-sym bold sym-lambda" x="1130" y="100" font-size="44">λ</text>

                    <!-- ω  omega — lower left -->
                    <text class="math-sym sym-omega"      x="120"  y="235" font-size="36">ω</text>

                    <!-- ∇  nabla -->
                    <text class="math-sym sym-nabla"      x="340"  y="235" font-size="34">∇</text>

                    <!-- ≈  approx -->
                    <text class="math-sym sym-approx"     x="720"  y="240" font-size="34">≈</text>

                    <!-- ψ  psi -->
                    <text class="math-sym bold sym-psi"   x="1085" y="55"  font-size="40">ψ</text>

                    <!-- μ  mu — far right bottom -->
                    <text class="math-sym sym-mu"         x="1165" y="245" font-size="36">μ</text>

                </svg>
            </div>

            <!-- Card Content -->
            <div class="header-content">
                <div class="subject-icon-wrapper">
                    <div class="subject-icon">
                        <?php
                        $subjectLogoPath = '../teacher/php/' . htmlspecialchars($subject['logo_path']);
                        ?>
                        <img src="<?php echo $subjectLogoPath; ?>"
                             alt="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <span class="icon-letter" style="display:none;">
                            <?php echo strtoupper(substr($subject['subject_name'], 0, 1)); ?>
                        </span>
                    </div>
                </div>

                <div class="subject-info-main">
                    <div class="subject-tags">
                        <span class="tag">Grade <?php echo htmlspecialchars($subject['grade']); ?></span>
                        <?php if ($subject['section']): ?>
                        <span class="tag"><?php echo htmlspecialchars($subject['section']); ?></span>
                        <?php endif; ?>
                    </div>

                    <h1 class="subject-title">
                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                    </h1>

                    <div class="teacher-info">
                        <div class="teacher-avatar">
                            <?php
                            $teacherProfilePic = getTeacherProfilePicture($subject['teacher_profile_picture']);
                            if ($teacherProfilePic && file_exists($teacherProfilePic)):
                            ?>
                                <img src="<?php echo htmlspecialchars($teacherProfilePic); ?>"
                                     alt="<?php echo htmlspecialchars($subject['teacher_name']); ?>">
                            <?php else: ?>
                                <?php echo getInitials($subject['teacher_name']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="teacher-details">
                            <span class="teacher-label">Instructor</span>
                            <span class="teacher-name">
                                <?php echo htmlspecialchars($subject['teacher_name']); ?>
                            </span>
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
                <button class="tab-button active" data-tab="stream">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span>Stream</span>
                </button>
                <button class="tab-button" data-tab="classwork">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Classwork</span>
                </button>
                <button class="tab-button" data-tab="materials">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Materials</span>
                </button>
                <div class="tab-indicator"></div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="container">
            <!-- Stream Tab -->
            <div class="tab-content active" id="tab-stream">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Recent Announcements</h2>
                    </div>
                    <div class="card-body">
                        <?php if (count($announcements) > 0): ?>
                            <div class="announcements-list">
                                <?php foreach ($announcements as $announcement): ?>
                                <div class="announcement-card">
                                    <?php if ($announcement['is_pinned']): ?>
                                    <div class="pinned-indicator">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5v6h2v-6h5v-2l-2-2z"/>
                                        </svg>
                                        <span>Pinned</span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="announcement-header">
                                        <div class="author-info">
                                            <div class="author-avatar-small">
                                                <?php 
                                                $authorProfilePic = getTeacherProfilePicture($announcement['profile_picture']);
                                                if ($authorProfilePic && file_exists($authorProfilePic)): 
                                                ?>
                                                    <img src="<?php echo htmlspecialchars($authorProfilePic); ?>" alt="<?php echo htmlspecialchars($announcement['author_name']); ?>">
                                                <?php else: ?>
                                                    <?php echo getInitials($announcement['author_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="author-meta">
                                                <span class="author-name"><?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                                <span class="post-time">
                                                    <?php 
                                                    $date = new DateTime($announcement['created_at']);
                                                    echo $date->format('M j, Y • g:i A');
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="announcement-body">
                                        <?php if ($announcement['title']): ?>
                                            <h3 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                        <?php endif; ?>
                                        <p class="announcement-text"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none">
                                        <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z" fill="currentColor"/>
                                    </svg>
                                </div>
                                <h3>No announcements yet</h3>
                                <p>Your instructor hasn't posted any announcements</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Classwork Tab -->
            <div class="tab-content" id="tab-classwork">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Assignments</h2>
                    </div>
                    <div class="card-body">
                        <?php if (count($assignments) > 0): ?>
                            <div class="assignments-list">
                                <?php foreach ($assignments as $assignment): 
                                    $is_submitted = $assignment['submission_status'] !== null;
                                    $is_graded = $assignment['score'] !== null;
                                    $is_late = false;
                                    
                                    if ($assignment['due_date']) {
                                        $due = new DateTime($assignment['due_date']);
                                        $now = new DateTime();
                                        $is_late = !$is_submitted && $now > $due;
                                    }
                                    
                                    $status_class = $is_graded ? 'graded' : ($is_submitted ? 'submitted' : ($is_late ? 'missing' : 'upcoming'));
                                ?>
                                <div class="assignment-card <?php echo $status_class; ?>" onclick="window.location.href='view_assignment.php?id=<?php echo $assignment['id']; ?>'">
                                    <div class="assignment-type-icon" data-type="<?php echo htmlspecialchars($assignment['assignment_type']); ?>">
                                        <?php 
                                        $icons = [
                                            'homework' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="currentColor" stroke-width="2"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" stroke="currentColor" stroke-width="2"/>',
                                            'quiz' => '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3m.08 4h.01" stroke="currentColor" stroke-width="2"/>',
                                            'project' => '<rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 9h18M9 21V9" stroke="currentColor" stroke-width="2"/>',
                                            'exam' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/>',
                                            'classwork' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" stroke="currentColor" stroke-width="2"/><rect x="8" y="2" width="8" height="4" rx="1" stroke="currentColor" stroke-width="2"/>'
                                        ];
                                        $svg_path = $icons[$assignment['assignment_type']] ?? $icons['classwork'];
                                        echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none">' . $svg_path . '</svg>';
                                        ?>
                                    </div>
                                    
                                    <div class="assignment-details">
                                        <div class="assignment-meta">
                                            <h3 class="assignment-title"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                            <div class="assignment-info">
                                                <?php if ($assignment['due_date']): 
                                                    $due = new DateTime($assignment['due_date']);
                                                ?>
                                                <span class="due-date">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                                        <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                                                        <path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2"/>
                                                    </svg>
                                                    Due <?php echo $due->format('M j, g:i A'); ?>
                                                </span>
                                                <?php endif; ?>
                                                <span class="points">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                                        <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" stroke="currentColor" stroke-width="2"/>
                                                    </svg>
                                                    <?php echo htmlspecialchars($assignment['max_points']); ?> pts
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="assignment-status-badge">
                                            <?php if ($is_graded): ?>
                                                <span class="status-icon graded">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                                                        <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                                                    </svg>
                                                </span>
                                                <span class="status-text"><?php echo htmlspecialchars($assignment['score']) . '/' . htmlspecialchars($assignment['max_points']); ?></span>
                                            <?php elseif ($is_submitted): ?>
                                                <span class="status-icon submitted">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                                                        <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                                                    </svg>
                                                </span>
                                                <span class="status-text">Submitted</span>
                                            <?php elseif ($is_late): ?>
                                                <span class="status-icon missing">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                                                        <path d="M12 8v4M12 16h.01" stroke="currentColor" stroke-width="2"/>
                                                    </svg>
                                                </span>
                                                <span class="status-text">Missing</span>
                                            <?php else: ?>
                                                <span class="status-icon upcoming">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                                                        <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2"/>
                                                    </svg>
                                                </span>
                                                <span class="status-text">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="assignment-arrow">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                            <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none">
                                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                </div>
                                <h3>No assignments yet</h3>
                                <p>Your instructor hasn't posted any assignments</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Materials Tab -->
            <div class="tab-content" id="tab-materials">
                <div class="content-card">
                    <div class="card-header">
                        <h2 class="card-title">Class Materials</h2>
                    </div>
                    <div class="card-body">
                        <?php if (count($materials) > 0): ?>
                            <div class="materials-grid">
                                <?php foreach ($materials as $material): ?>
                                <div class="material-card" onclick="openMaterial(<?php echo $material['id']; ?>)">
                                    <div class="material-icon-wrapper" data-type="<?php echo htmlspecialchars($material['material_type']); ?>">
                                        <?php 
                                        $icons = [
                                            'file' => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" stroke="currentColor" stroke-width="2"/><polyline points="13 2 13 9 20 9" stroke="currentColor" stroke-width="2"/>',
                                            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2"/>',
                                            'video' => '<path d="M23 7l-7 5 7 5V7z" stroke="currentColor" stroke-width="2"/><rect x="1" y="5" width="15" height="14" rx="2" stroke="currentColor" stroke-width="2"/>',
                                            'document' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/><line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2"/><line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2"/><polyline points="10 9 9 9 8 9" stroke="currentColor" stroke-width="2"/>'
                                        ];
                                        $svg_path = $icons[$material['material_type']] ?? $icons['file'];
                                        echo '<svg width="28" height="28" viewBox="0 0 24 24" fill="none">' . $svg_path . '</svg>';
                                        ?>
                                    </div>
                                    <div class="material-content">
                                        <h3 class="material-title"><?php echo htmlspecialchars($material['title']); ?></h3>
                                        <div class="material-meta">
                                            <span class="material-author"><?php echo htmlspecialchars($material['author_name']); ?></span>
                                            <span class="material-separator">•</span>
                                            <span class="material-date">
                                                <?php 
                                                $date = new DateTime($material['created_at']);
                                                echo $date->format('M j, Y');
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="material-action">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                                            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none">
                                        <path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                </div>
                                <h3>No materials yet</h3>
                                <p>Your instructor hasn't shared any materials</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>

function initTabs() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tabName = button.getAttribute('data-tab');
            
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked button and corresponding content
            button.classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');
            
            // Smooth scroll to content
            setTimeout(() => {
                const contentTop = document.querySelector('.main-wrapper').offsetTop;
                window.scrollTo({
                    top: contentTop - 150,
                    behavior: 'smooth'
                });
            }, 100);
            
            // Save active tab to localStorage
            localStorage.setItem('activeTab', tabName);
        });
    });
    
    // Restore active tab from localStorage
    const savedTab = localStorage.getItem('activeTab');
    if (savedTab) {
        const savedButton = document.querySelector(`[data-tab="${savedTab}"]`);
        if (savedButton) {
            savedButton.click();
        }
    }
}

function openMaterial(materialId) {
    // Add click animation
    const event = window.event;
    const card = event.currentTarget;
    card.style.transform = 'scale(0.98)';
    
    setTimeout(() => {
        card.style.transform = '';
        // Navigate to the material detail page
        window.location.href = 'view_subject_material.php?id=' + materialId;
    }, 150);
}

function initSmoothScroll() {
    // Add smooth scrolling behavior
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

function initCardEffects() {
    const cards = document.querySelectorAll('.assignment-card, .material-card, .announcement-card');
    
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transition = 'all 0.2s ease';
        });
    });
}

function showLoadingState() {
    document.body.style.opacity = '0';
    setTimeout(() => {
        document.body.style.transition = 'opacity 0.3s ease';
        document.body.style.opacity = '1';
    }, 50);
}

function initNavbarScroll() {
    const navbar = document.querySelector('.navbar');
    let lastScroll = 0;
    
    window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            navbar.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.08)';
        } else {
            navbar.style.boxShadow = 'none';
        }
        
        lastScroll = currentScroll;
    });
}

function animateAnnouncements() {
    const announcements = document.querySelectorAll('.announcement-card');
    
    announcements.forEach((announcement, index) => {
        announcement.style.opacity = '0';
        announcement.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            announcement.style.transition = 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
            announcement.style.opacity = '1';
            announcement.style.transform = 'translateY(0)';
        }, index * 100);
    });
}

function animateAssignments() {
    const assignments = document.querySelectorAll('.assignment-card');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateX(0)';
                }, index * 80);
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });
    
    assignments.forEach(assignment => {
        assignment.style.opacity = '0';
        assignment.style.transform = 'translateX(-20px)';
        assignment.style.transition = 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
        observer.observe(assignment);
    });
}

function animateMaterials() {
    const materials = document.querySelectorAll('.material-card');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, index * 80);
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });
    
    materials.forEach(material => {
        material.style.opacity = '0';
        material.style.transform = 'translateY(20px)';
        material.style.transition = 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
        observer.observe(material);
    });
}

function initKeyboardNav() {
    document.addEventListener('keydown', (e) => {
        // ESC key to go back
        if (e.key === 'Escape') {
            window.location.href = 'student_dashb.php';
        }
        
        // Arrow keys for tab navigation
        const tabButtons = Array.from(document.querySelectorAll('.tab-button'));
        const activeTab = document.querySelector('.tab-button.active');
        const currentIndex = tabButtons.indexOf(activeTab);
        
        if (e.key === 'ArrowRight' && currentIndex < tabButtons.length - 1) {
            tabButtons[currentIndex + 1].click();
        } else if (e.key === 'ArrowLeft' && currentIndex > 0) {
            tabButtons[currentIndex - 1].click();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Show loading state
    showLoadingState();
    
    // Initialize features
    initTabs();
    initSmoothScroll();
    initCardEffects();
    initNavbarScroll();
    initKeyboardNav();
    
    // Animate elements
    setTimeout(() => {
        animateAnnouncements();
        animateAssignments();
        animateMaterials();
    }, 200);
    
    // Add ripple effect to buttons
    document.querySelectorAll('button').forEach(button => {
        button.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            ripple.classList.add('ripple');
            
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
    });
});

const style = document.createElement('style');
style.textContent = `
    button {
        position: relative;
        overflow: hidden;
    }
    
    .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.6);
        transform: scale(0);
        animation: ripple-animation 0.6s ease-out;
        pointer-events: none;
    }
    
    @keyframes ripple-animation {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Debounce function for scroll events
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Optimize scroll handlers
window.addEventListener('scroll', debounce(() => {
    // Your scroll handling code here
}, 10));
    </script>
</body>
</html>