<?php
session_start();

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

// Validate required parameters
if (!isset($_GET['student_id']) || !isset($_GET['subject_id'])) {
    header("Location: teacher_dashb.php");
    exit();
}

$student_id = (int) $_GET['student_id'];
$subject_id = (int) $_GET['subject_id'];
$teacher_id = (int) $_SESSION['teacher_id'];

// Verify teacher owns this subject
$stmt = $conn->prepare("SELECT * FROM subjects WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("ii", $subject_id, $teacher_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subject) {
    die("Subject not found or you don't have permission to view it.");
}

// Verify student is enrolled in this subject
$stmt = $conn->prepare("
    SELECT s.*, ss.enrollment_date, ss.status AS enrollment_status, ss.final_grade
    FROM students s
    JOIN student_subjects ss ON s.id = ss.student_id
    WHERE s.id = ? AND ss.subject_id = ? AND ss.status = 'active'
");
$stmt->bind_param("ii", $student_id, $subject_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die("Student not found or not enrolled in this subject.");
}

// Get all assignments for this subject
$stmt = $conn->prepare("
    SELECT * FROM assignments
    WHERE subject_id = ? AND status != 'draft'
    ORDER BY due_date DESC, created_at DESC
");
$stmt->bind_param("i", $subject_id);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get student's submissions with grades
$stmt = $conn->prepare("
    SELECT
        s.*,
        a.title          AS assignment_title,
        a.max_points,
        a.assignment_type,
        a.due_date,
        t.fullname       AS graded_by_name
    FROM submissions s
    JOIN assignments a  ON s.assignment_id = a.id
    LEFT JOIN teachers t ON s.graded_by    = t.id
    WHERE s.student_id = ? AND a.subject_id = ?
    ORDER BY a.due_date DESC
");
$stmt->bind_param("ii", $student_id, $subject_id);
$stmt->execute();
$submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$total_assignments = count($assignments);
$submitted_count   = count($submissions);
$graded_count      = 0;
$total_score       = 0;

foreach ($submissions as $submission) {
    if ($submission['score'] !== null) {
        $graded_count++;
        $total_score += $submission['score'];
    }
}

$average_grade   = $graded_count > 0 ? round($total_score / $graded_count, 1) : null;
$submission_rate = $total_assignments > 0 ? round(($submitted_count / $total_assignments) * 100) : 0;

// Map submissions by assignment_id for easy lookup
$submissions_map = [];
foreach ($submissions as $submission) {
    $submissions_map[$submission['assignment_id']] = $submission;
}

// Get teacher details
$stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Helper ────────────────────────────────────────────────────────────────────
function adjustColor(string $color, int $amount): string {
    $color = str_replace('#', '', $color);
    $num   = hexdec($color);
    $r     = max(0, min(255, ($num >> 16) + $amount));
    $g     = max(0, min(255, (($num >> 8) & 0x00FF) + $amount));
    $b     = max(0, min(255, ($num & 0x0000FF) + $amount));
    return '#' . str_pad(dechex(($r << 16) | ($g << 8) | $b), 6, '0', STR_PAD_LEFT);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> - Grades</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --theme-color: <?php echo $subject['theme_color']; ?>;
            --theme-color-light: <?php echo adjustColor($subject['theme_color'], 40); ?>;
            --theme-color-dark: <?php echo adjustColor($subject['theme_color'], -30); ?>;
            
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

        /* Navigation Bar */
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

        .navbar-title {
            flex: 1;
        }

        .navbar-title h1 {
            font-family: var(--font-display);
            font-size: 18px;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: 2px;
        }

        .navbar-subtitle {
            font-size: 13px;
            color: var(--color-text-secondary);
        }

        /* Main Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--spacing-xl) var(--spacing-lg);
        }

        /* Student Header Card */
        .student-header {
            background: linear-gradient(135deg, var(--theme-color) 0%, var(--theme-color-dark) 100%);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            margin-bottom: var(--spacing-xl);
            box-shadow: var(--shadow-xl);
            position: relative;
            overflow: hidden;
        }

        .student-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        .student-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: var(--spacing-lg);
        }

        .student-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            border: 3px solid rgba(255, 255, 255, 0.3);
            flex-shrink: 0;
            backdrop-filter: blur(10px);
        }

        .student-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin-bottom: var(--spacing-xs);
        }

        .student-meta {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            flex-wrap: wrap;
            margin-bottom: var(--spacing-sm);
        }

        .student-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
        }

        .student-meta i {
            opacity: 0.8;
        }

        .subject-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: var(--radius-lg);
            font-size: 13px;
            font-weight: 600;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }

        .stat-card {
            background: var(--color-bg-primary);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing-sm);
        }

        .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .stat-icon.success {
            background: var(--color-success-light);
            color: var(--color-success);
        }

        .stat-icon.primary {
            background: #dbeafe;
            color: var(--color-primary);
        }

        .stat-icon.warning {
            background: var(--color-warning-light);
            color: var(--color-warning);
        }

        .stat-value {
            font-family: var(--font-display);
            font-size: 36px;
            font-weight: 700;
            color: var(--color-text-primary);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-description {
            font-size: 13px;
            color: var(--color-text-secondary);
        }

        /* Content Card */
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

        /* Grades List */
        .grades-list {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
        }

        .grade-item {
            background: var(--color-bg-primary);
            border: 1px solid var(--color-border);
            border-left: 4px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: var(--spacing-lg);
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            transition: all 0.2s ease;
        }

        .grade-item:hover {
            box-shadow: var(--shadow-md);
            transform: translateX(4px);
        }

        .grade-item.graded {
            border-left-color: var(--color-success);
            background: linear-gradient(90deg, rgba(16, 185, 129, 0.03) 0%, transparent 100%);
        }

        .grade-item.submitted {
            border-left-color: var(--color-primary);
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.03) 0%, transparent 100%);
        }

        .grade-item.missing {
            border-left-color: var(--color-danger);
            background: linear-gradient(90deg, rgba(239, 68, 68, 0.03) 0%, transparent 100%);
        }

        .grade-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 20px;
        }

        .grade-icon.homework {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: var(--color-primary);
        }

        .grade-icon.quiz {
            background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
            color: #9334e6;
        }

        .grade-icon.project {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: var(--color-warning);
        }

        .grade-icon.exam {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: var(--color-danger);
        }

        .grade-details {
            flex: 1;
        }

        .grade-title {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 600;
            color: var(--color-text-primary);
            margin-bottom: 6px;
        }

        .grade-meta {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            flex-wrap: wrap;
        }

        .grade-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--color-text-secondary);
        }

        .grade-meta i {
            opacity: 0.6;
        }

        .grade-score {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .score-value {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
        }

        .score-value.excellent {
            color: var(--color-success);
        }

        .score-value.good {
            color: var(--color-primary);
        }

        .score-value.fair {
            color: var(--color-warning);
        }

        .score-value.poor {
            color: var(--color-danger);
        }

        .score-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--color-text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: var(--radius-lg);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.graded {
            background: var(--color-success-light);
            color: var(--color-success);
        }

        .status-badge.submitted {
            background: #dbeafe;
            color: var(--color-primary);
        }

        .status-badge.missing {
            background: var(--color-danger-light);
            color: var(--color-danger);
        }

        .status-badge.late {
            background: var(--color-warning-light);
            color: var(--color-warning);
        }

        /* Buttons */
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
            background: var(--theme-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--theme-color-dark);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
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

        /* Empty State */
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

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: var(--spacing-lg) var(--spacing-md);
            }

            .navbar-container {
                padding: var(--spacing-sm) var(--spacing-md);
            }

            .student-header-content {
                flex-direction: column;
                text-align: center;
            }

            .student-name {
                font-size: 24px;
            }

            .student-meta {
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .grade-item {
                flex-wrap: wrap;
            }

            .grade-score {
                width: 100%;
                align-items: flex-start;
                margin-top: var(--spacing-sm);
            }

            .grade-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
        }

        .student-header {
    background: linear-gradient(135deg, var(--theme-color) 0%, var(--theme-color-dark) 100%);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow:
        0 20px 60px rgba(0, 0, 0, 0.18),
        0 4px 16px rgba(0, 0, 0, 0.10);
    position: relative;
    overflow: hidden;
    min-height: 200px;
}

/* Radial glow overlay — replaces the old ::before */
.student-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 55% 80% at 8% 40%,  rgba(255,255,255,0.13) 0%, transparent 60%),
        radial-gradient(ellipse 40% 60% at 92% 75%, rgba(255,255,255,0.09) 0%, transparent 55%);
    pointer-events: none;
    z-index: 0;
}

/* ── Math Symbols SVG ─────────────────────────────────────── */
.math-symbols-layer {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    overflow: hidden;
    z-index: 0;
}

.math-sym {
    font-family: 'Georgia', 'Times New Roman', serif;
    fill: rgba(255, 255, 255, 0.12);
    user-select: none;
    will-change: transform;
}

.math-sym.bold {
    fill: rgba(255, 255, 255, 0.17);
    font-weight: 700;
}

/* ── Keyframe animations ──────────────────────────────────── */
@keyframes mFloat1 {
    0%, 100% { transform: translateY(0px)   rotate(0deg);  opacity: 0.9; }
    50%       { transform: translateY(-20px) rotate(7deg);  opacity: 0.55; }
}
@keyframes mFloat2 {
    0%, 100% { transform: translateY(0px)   rotate(0deg);  opacity: 0.8; }
    50%       { transform: translateY(-14px) rotate(-5deg); opacity: 0.5; }
}
@keyframes mDrift {
    0%   { transform: translateX(0px)   translateY(0px); }
    25%  { transform: translateX(9px)   translateY(-11px); }
    50%  { transform: translateX(-7px)  translateY(-18px); }
    75%  { transform: translateX(-11px) translateY(-7px); }
    100% { transform: translateX(0px)   translateY(0px); }
}
@keyframes mPulse {
    0%, 100% { opacity: 0.85; transform: scale(1); }
    50%       { opacity: 0.35; transform: scale(1.14); }
}
@keyframes mSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
@keyframes mFloat3 {
    0%, 100% { transform: translateY(0px)  translateX(0px); opacity: 0.9; }
    33%       { transform: translateY(-16px) translateX(7px); opacity: 0.6; }
    66%       { transform: translateY(-7px)  translateX(-6px); opacity: 0.8; }
}

/* Assign per symbol */
.sym-pi       { animation: mFloat1  9s  0.0s ease-in-out infinite; }
.sym-sigma    { animation: mFloat3 11s  0.6s ease-in-out infinite; }
.sym-sqrt     { animation: mFloat2  8s  1.3s ease-in-out infinite; }
.sym-integral { animation: mDrift  14s  0.3s ease-in-out infinite; }
.sym-delta    { animation: mFloat1  7s  1.9s ease-in-out infinite; }
.sym-theta    { animation: mFloat2 10s  0.8s ease-in-out infinite; }
.sym-inf      { animation: mFloat3 12s  2.2s ease-in-out infinite; }
.sym-plus     { animation: mPulse   6s  0.5s ease-in-out infinite; }
.sym-equals   { animation: mFloat2  9s  1.6s ease-in-out infinite; }
.sym-alpha    { animation: mDrift  13s  1.1s ease-in-out infinite; }
.sym-beta     { animation: mFloat1 10s  2.6s ease-in-out infinite; }
.sym-lambda   { animation: mFloat2  7s  0.2s ease-in-out infinite; }
.sym-omega    { animation: mFloat3 11s  1.8s ease-in-out infinite; }
.sym-nabla    { animation: mFloat1  9s  0.7s ease-in-out infinite; }
.sym-approx   { animation: mPulse   8s  2.1s ease-in-out infinite; }
.sym-psi      { animation: mDrift  12s  1.4s ease-in-out infinite; }
.sym-mu       { animation: mFloat3 10s  0.9s ease-in-out infinite; }
.sym-percent  { animation: mFloat2  8s  0.4s ease-in-out infinite; }

/* Content sits above the SVG */
.student-header-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
}

/* Avatar */
.student-avatar-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.20);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 700;
    border: 3px solid rgba(255, 255, 255, 0.30);
    flex-shrink: 0;
    backdrop-filter: blur(10px);
    overflow: hidden;
}

.student-avatar-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

/* Text */
.student-name {
    font-family: var(--font-display);
    font-size: 28px;
    font-weight: 700;
    color: white;
    margin-bottom: var(--spacing-xs);
    text-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

.student-meta {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
    margin-bottom: var(--spacing-sm);
}

.student-meta span {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.88);
}

.student-meta i { opacity: 0.75; }

.subject-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.20);
    color: white;
    border-radius: var(--radius-lg);
    font-size: 13px;
    font-weight: 600;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.20);
}

/* Responsive */
@media (max-width: 768px) {
    .student-header-content {
        flex-direction: column;
        text-align: center;
    }
    .student-name  { font-size: 22px; }
    .student-meta  { justify-content: center; }
}
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="navbar-container">
            <button class="back-button" onclick="window.location.href='teacher_view_subject.php?id=<?php echo $subject_id; ?>&tab=people'">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="navbar-title">
                <h1><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h1>
                <div class="navbar-subtitle"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">
        <!-- Student Header -->
<div class="student-header">

    <!-- Floating Math Symbols -->
    <svg class="math-symbols-layer"
         xmlns="http://www.w3.org/2000/svg"
         viewBox="0 0 1200 200"
         preserveAspectRatio="xMidYMid slice">

        <!-- π  pi — top-left -->
        <text class="math-sym bold sym-pi"    x="40"   y="70"  font-size="50">π</text>

        <!-- Σ  sigma -->
        <text class="math-sym bold sym-sigma" x="160"  y="160" font-size="44">Σ</text>

        <!-- √  square root -->
        <text class="math-sym sym-sqrt"       x="270"  y="48"  font-size="38">√</text>

        <!-- ∫  integral -->
        <text class="math-sym bold sym-integral" x="375" y="170" font-size="54">∫</text>

        <!-- Δ  delta -->
        <text class="math-sym sym-delta"      x="460"  y="60"  font-size="36">Δ</text>

        <!-- θ  theta -->
        <text class="math-sym sym-theta"      x="545"  y="175" font-size="42">θ</text>

        <!-- ∞  infinity -->
        <text class="math-sym bold sym-inf"   x="625"  y="45"  font-size="40">∞</text>

        <!-- +  plus -->
        <text class="math-sym bold sym-plus"  x="720"  y="155" font-size="46">+</text>

        <!-- =  equals -->
        <text class="math-sym sym-equals"     x="800"  y="80"  font-size="34">=</text>

        <!-- α  alpha -->
        <text class="math-sym sym-alpha"      x="875"  y="168" font-size="36">α</text>

        <!-- β  beta -->
        <text class="math-sym sym-beta"       x="950"  y="55"  font-size="40">β</text>

        <!-- λ  lambda -->
        <text class="math-sym bold sym-lambda" x="1030" y="165" font-size="42">λ</text>

        <!-- ω  omega -->
        <text class="math-sym sym-omega"      x="1110" y="70"  font-size="36">ω</text>

        <!-- ∇  nabla — left-mid -->
        <text class="math-sym sym-nabla"      x="110"  y="100" font-size="32">∇</text>

        <!-- ≈  approx -->
        <text class="math-sym sym-approx"     x="680"  y="115" font-size="30">≈</text>

        <!-- ψ  psi -->
        <text class="math-sym bold sym-psi"   x="1150" y="140" font-size="38">ψ</text>

        <!-- μ  mu -->
        <text class="math-sym sym-mu"         x="310"  y="185" font-size="34">μ</text>

        <!-- %  percent — fits the grades context -->
        <text class="math-sym bold sym-percent" x="830" y="185" font-size="36">%</text>

    </svg>

    <!-- Card Content -->
    <div class="student-header-content">
        <div class="student-avatar-large">
            <?php if ($student['profile_picture']): ?>
                <img src="../../student/<?php echo htmlspecialchars($student['profile_picture']); ?>"
                     alt="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <span style="display:none;"><?php echo strtoupper(substr($student['first_name'], 0, 1)); ?></span>
            <?php else: ?>
                <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
            <?php endif; ?>
        </div>

        <div class="student-info">
            <h2 class="student-name">
                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
            </h2>
            <div class="student-meta">
                <span>
                    <i class="fas fa-envelope"></i>
                    <?php echo htmlspecialchars($student['email']); ?>
                </span>
                <span>
                    <i class="fas fa-graduation-cap"></i>
                    Grade <?php echo htmlspecialchars($student['grade_level']); ?>
                    – <?php echo htmlspecialchars($student['section']); ?>
                </span>
                <span>
                    <i class="fas fa-calendar"></i>
                    Enrolled <?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?>
                </span>
            </div>
            <span class="subject-badge">
                <i class="fas fa-book"></i>
                <?php echo htmlspecialchars($subject['subject_name']); ?>
            </span>
        </div>
    </div>

</div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Average Grade</span>
                    <div class="stat-icon success">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $average_grade !== null ? $average_grade . '%' : 'N/A'; ?></div>
                <div class="stat-description">
                    <?php 
                    if ($average_grade !== null) {
                        if ($average_grade >= 90) echo 'Excellent performance';
                        elseif ($average_grade >= 80) echo 'Good performance';
                        elseif ($average_grade >= 70) echo 'Fair performance';
                        else echo 'Needs improvement';
                    } else {
                        echo 'No grades yet';
                    }
                    ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Assignments Graded</span>
                    <div class="stat-icon primary">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $graded_count; ?>/<?php echo $total_assignments; ?></div>
                <div class="stat-description">
                    <?php 
                    $ungraded = $total_assignments - $graded_count;
                    echo $ungraded > 0 ? $ungraded . ' pending' : 'All graded';
                    ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Submission Rate</span>
                    <div class="stat-icon warning">
                        <i class="fas fa-percent"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $submission_rate; ?>%</div>
                <div class="stat-description">
                    <?php echo $submitted_count; ?> of <?php echo $total_assignments; ?> submitted
                </div>
            </div>
        </div>

        <!-- Grades List -->
        <div class="content-card">
            <div class="card-header">
                <h2 class="card-title">Assignment Grades</h2>
                <button class="btn btn-outlined" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($assignments)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3>No assignments yet</h3>
                    <p>Grades will appear here once assignments are created</p>
                </div>
                <?php else: ?>
                <div class="grades-list">
                    <?php foreach ($assignments as $assignment): 
                        $submission = isset($submissions_map[$assignment['id']]) ? $submissions_map[$assignment['id']] : null;
                        $has_submission = $submission !== null;
                        $is_graded = $has_submission && $submission['score'] !== null;
                        $score = $is_graded ? $submission['score'] : null;
                        
                        // Determine status
                        if ($is_graded) {
                            $status = 'graded';
                            $status_label = 'Graded';
                            $status_class = 'graded';
                        } elseif ($has_submission) {
                            $status = 'submitted';
                            $status_label = $submission['status'] === 'late' ? 'Late' : 'Submitted';
                            $status_class = $submission['status'] === 'late' ? 'late' : 'submitted';
                        } else {
                            $status = 'missing';
                            $status_label = 'Missing';
                            $status_class = 'missing';
                        }
                        
                        // Determine score class
                        $score_class = '';
                        if ($score !== null) {
                            if ($score >= 90) $score_class = 'excellent';
                            elseif ($score >= 80) $score_class = 'good';
                            elseif ($score >= 70) $score_class = 'fair';
                            else $score_class = 'poor';
                        }
                    ?>
                    <div class="grade-item <?php echo $status_class; ?>">
                        <div class="grade-icon <?php echo $assignment['assignment_type']; ?>">
                            <i class="fas fa-<?php 
                                echo $assignment['assignment_type'] === 'quiz' ? 'clipboard-check' : 
                                     ($assignment['assignment_type'] === 'exam' ? 'file-alt' : 
                                     ($assignment['assignment_type'] === 'project' ? 'folder' : 'file-alt')); 
                            ?>"></i>
                        </div>
                        <div class="grade-details">
                            <h3 class="grade-title"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                            <div class="grade-meta">
                                <span>
                                    <i class="fas fa-tag"></i>
                                    <?php echo ucfirst($assignment['assignment_type']); ?>
                                </span>
                                <span>
                                    <i class="fas fa-calendar"></i>
                                    Due: <?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'No due date'; ?>
                                </span>
                                <span>
                                    <i class="fas fa-star"></i>
                                    <?php echo $assignment['max_points']; ?> points
                                </span>
                                <?php if ($is_graded && $submission['graded_at']): ?>
                                <span>
                                    <i class="fas fa-clock"></i>
                                    Graded <?php echo date('M d, Y', strtotime($submission['graded_at'])); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="grade-score">
                            <?php if ($is_graded): ?>
                                <span class="score-value <?php echo $score_class; ?>"><?php echo number_format($score, 0); ?>%</span>
                                <span class="score-label">Score</span>
                            <?php else: ?>
                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Feedback Section (if there are graded submissions with feedback) -->
        <?php 
        $submissions_with_feedback = array_filter($submissions, function($sub) {
            return !empty($sub['teacher_feedback']);
        });
        
        if (!empty($submissions_with_feedback)): 
        ?>
        <div class="content-card">
            <div class="card-header">
                <h2 class="card-title">Teacher Feedback</h2>
            </div>
            <div class="card-body">
                <div class="grades-list">
                    <?php foreach ($submissions_with_feedback as $submission): ?>
                    <div class="grade-item graded">
                        <div class="grade-icon homework">
                            <i class="fas fa-comment"></i>
                        </div>
                        <div class="grade-details">
                            <h3 class="grade-title"><?php echo htmlspecialchars($submission['assignment_title']); ?></h3>
                            <div class="grade-meta">
                                <span>
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($submission['graded_by_name'] ?: 'Teacher'); ?>
                                </span>
                                <span>
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M d, Y', strtotime($submission['graded_at'])); ?>
                                </span>
                            </div>
                            <p style="margin-top: 12px; padding: 12px; background: var(--color-bg-secondary); border-radius: var(--radius-md); font-size: 14px; color: var(--color-text-secondary); line-height: 1.6;">
                                <?php echo nl2br(htmlspecialchars($submission['teacher_feedback'])); ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Print functionality
        window.addEventListener('beforeprint', function() {
            document.querySelector('.navbar').style.display = 'none';
            document.querySelectorAll('.btn').forEach(btn => btn.style.display = 'none');
        });

        window.addEventListener('afterprint', function() {
            document.querySelector('.navbar').style.display = 'block';
            document.querySelectorAll('.btn').forEach(btn => btn.style.display = 'inline-flex');
        });

        // Log page view
        console.log('Student Grades page loaded');
    </script>
</body>
</html> 