<?php
session_start();

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Database connection
require_once '../../db_connection/ashi_dbcnnt.php';
// $conn (MySQLi) is now available

if (!isset($_GET['quiz_id'])) {
    header("Location: ../teacher_dashb.php");
    exit();
}

$quiz_id    = (int) $_GET['quiz_id'];
$teacher_id = (int) $_SESSION['teacher_id'];

// Get quiz details and verify ownership
$stmt = $conn->prepare("
    SELECT q.*, s.subject_name, s.section, s.id AS subject_id, s.theme_color
    FROM quizzes  q
    JOIN subjects s ON q.subject_id = s.id
    WHERE q.id = ? AND q.teacher_id = ?
");
$stmt->bind_param("ii", $quiz_id, $teacher_id);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    die("Quiz not found or you don't have permission to view it.");
}

// Get teacher details
$stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get all questions ordered
$stmt = $conn->prepare("
    SELECT * FROM quiz_questions
    WHERE quiz_id = ?
    ORDER BY question_order ASC
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Prepare option/answer lookups once, reuse per question
$opt_stmt = $conn->prepare("
    SELECT * FROM quiz_question_options
    WHERE question_id = ?
    ORDER BY option_order ASC
");
$ans_stmt = $conn->prepare("
    SELECT * FROM quiz_question_answers
    WHERE question_id = ?
");

foreach ($questions as &$question) {
    $qid = (int) $question['id'];

    $opt_stmt->bind_param("i", $qid);
    $opt_stmt->execute();
    $question['options'] = $opt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $ans_stmt->bind_param("i", $qid);
    $ans_stmt->execute();
    $question['answer'] = $ans_stmt->get_result()->fetch_assoc();
}
unset($question); // break reference

$opt_stmt->close();
$ans_stmt->close();

// Get student attempts with student details
$stmt = $conn->prepare("
    SELECT qa.*,
           s.first_name, s.last_name, s.email, s.profile_picture,
           COUNT(DISTINCT qr.id) AS answered_questions
    FROM quiz_attempts  qa
    JOIN students       s  ON qa.student_id = s.id
    LEFT JOIN quiz_responses qr ON qa.id    = qr.attempt_id
    WHERE qa.quiz_id = ?
    GROUP BY qa.id
    ORDER BY qa.submitted_at DESC, qa.started_at DESC
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$total_questions    = count($questions);
$total_attempts     = count($attempts);
$students_taken     = count(array_unique(array_column($attempts, 'student_id')));
$completed_attempts = count(array_filter($attempts, fn($a) => in_array($a['status'], ['graded', 'submitted'])));

$graded_attempts = array_filter($attempts, fn($a) => $a['status'] === 'graded');
$average_score   = 0;
if (count($graded_attempts) > 0) {
    $average_score = array_sum(array_column($graded_attempts, 'percentage')) / count($graded_attempts);
}

$can_edit = ($students_taken === 0);

// Get enrolled students count for this subject
$subject_id = (int) $quiz['subject_id'];
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total FROM student_subjects
    WHERE subject_id = ? AND status = 'active'
");
$stmt->bind_param("i", $subject_id);
$stmt->execute();
$total_students = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Get active tab
$active_tab = $_GET['tab'] ?? 'overview';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($quiz['title']); ?> - Quiz View</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: <?php echo $quiz['theme_color'] ?? '#2563eb'; ?>;
            --primary-dark: #1e40af;
            --primary-light: #dbeafe;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
        }

        /* Navbar */
        .navbar {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }

        .navbar-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-button {
            background: var(--gray-100);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--gray-700);
        }

        .back-button:hover {
            background: var(--gray-200);
            transform: translateX(-2px);
        }

        .navbar-brand {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .quiz-status-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .quiz-status-badge.status-draft {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .quiz-status-badge.status-published {
            background: #d1fae5;
            color: #065f46;
        }

        .quiz-status-badge.status-closed {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Header */
        .quiz-header {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 32px 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
        }

        .header-left {
            flex: 1;
        }

        .quiz-icon-wrapper {
            width: 56px;
            height: 56px;
            background: var(--primary-light);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .quiz-icon-wrapper i {
            font-size: 28px;
            color: var(--primary-color);
        }

        .quiz-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .quiz-subtitle {
            font-size: 16px;
            color: var(--gray-600);
            margin-bottom: 16px;
        }

        .quiz-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-bottom: 16px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-600);
            font-size: 14px;
        }

        .meta-item i {
            color: var(--primary-color);
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-outlined {
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .btn-outlined:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Lock Indicator */
        .lock-notice {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
        }

        .lock-notice i {
            color: #f59e0b;
            font-size: 18px;
        }

        .lock-notice-text {
            flex: 1;
            font-size: 14px;
            color: #92400e;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 24px 0;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .stat-label {
            font-size: 14px;
            color: var(--gray-600);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .stat-subtext {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 4px;
        }

        /* Tabs */
        .tabs-wrapper {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            position: sticky;
            top: 64px;
            z-index: 50;
        }

        .tabs-nav {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            gap: 8px;
        }

        .tab-button {
            background: none;
            border: none;
            padding: 16px 20px;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-600);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-button:hover {
            color: var(--gray-900);
            background: var(--gray-50);
        }

        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        /* Tab Content */
        .tab-content {
            display: none;
            padding: 32px 0;
        }

        .tab-content.active {
            display: block;
        }

        /* Content Card */
        .content-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .card-body {
            padding: 24px;
        }

        /* Questions List */
        .questions-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .question-card {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            padding: 20px;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .question-number {
            background: var(--primary-color);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }

        .question-type-badge {
            background: white;
            border: 1px solid var(--gray-300);
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .question-text {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .question-points {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fef3c7;
            color: #92400e;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .options-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 12px;
        }

        .option-item {
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
        }

        .option-item.correct {
            border-color: var(--success);
            background: #d1fae5;
        }

        .option-letter {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: var(--gray-200);
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
        }

        .option-item.correct .option-letter {
            background: var(--success);
            color: white;
        }

        .option-text {
            flex: 1;
            font-size: 14px;
            color: var(--gray-800);
        }

        .correct-indicator {
            color: var(--success);
            font-weight: 700;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .answer-box {
            background: #d1fae5;
            border: 2px solid var(--success);
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
        }

        .answer-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--success);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .answer-text {
            font-size: 14px;
            color: var(--gray-800);
            font-weight: 500;
        }

        /* Submission Indicator */
        .submission-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-left: 8px;
        }

        .submission-indicator.submitted {
            background: var(--success);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        .submission-indicator.pending {
            background: var(--warning);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
        }

        .submission-indicator.not-submitted {
            background: var(--gray-300);
        }

        /* Eye Icon Button */
        .view-attempt-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--primary-light);
            color: var(--primary-color);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .view-attempt-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.05);
        }

        /* Attempts List */
        .attempts-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .attempt-item {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s;
        }

        .attempt-item:hover {
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .student-avatar {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            overflow: hidden;
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .attempt-details {
            flex: 1;
        }

        .student-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .attempt-meta {
            font-size: 13px;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .attempt-score {
            text-align: right;
        }

        .score-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .score-label {
            font-size: 12px;
            color: var(--gray-600);
        }

        .attempt-status {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .attempt-status.in-progress {
            background: #fef3c7;
            color: #92400e;
        }

        .attempt-status.submitted {
            background: #dbeafe;
            color: #1e40af;
        }

        .attempt-status.graded {
            background: #d1fae5;
            color: #065f46;
        }

        /* Modal Overlay */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.2s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        /* Modal Container */
        .modal-container {
            background: white;
            border-radius: 16px;
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Modal Header */
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .modal-title-section {
            flex: 1;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .modal-subtitle {
            font-size: 14px;
            color: var(--gray-600);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--gray-100);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--gray-600);
        }

        .modal-close:hover {
            background: var(--gray-200);
            color: var(--gray-900);
        }

        /* Modal Body */
        .modal-body {
            padding: 24px;
            max-height: calc(90vh - 180px);
            overflow-y: auto;
        }

        /* Modal Footer */
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Attempt Info Grid */
        .attempt-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .attempt-info-item {
            background: var(--gray-50);
            padding: 16px;
            border-radius: 8px;
        }

        .attempt-info-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .attempt-info-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
        }

        /* Response Item */
        .response-item {
            background: var(--gray-50);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .response-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .response-question {
            flex: 1;
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            line-height: 1.5;
        }

        .response-points {
            background: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            margin-left: 12px;
        }

        .response-points.correct {
            color: var(--success);
            border: 1px solid var(--success);
        }

        .response-points.incorrect {
            color: var(--error);
            border: 1px solid var(--error);
        }

        .response-points.partial {
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .student-answer {
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
        }

        .student-answer.correct {
            border-color: var(--success);
            background: #d1fae5;
        }

        .student-answer.incorrect {
            border-color: var(--error);
            background: #fee2e2;
        }

        .correct-answer-display {
            background: #d1fae5;
            border: 2px solid var(--success);
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 8px;
        }

        .correct-answer-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--success);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        /* Loading State */
        .modal-loading {
            text-align: center;
            padding: 60px 20px;
        }

        .modal-loading i {
            font-size: 36px;
            color: var(--primary-color);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        /* Score Badge */
        .score-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 18px;
        }

        .score-badge.pass {
            background: #d1fae5;
            color: #065f46;
        }

        .score-badge.fail {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            width: 80px;
            height: 80px;
            background: var(--gray-100);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .empty-icon i {
            font-size: 36px;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 14px;
            color: var(--gray-600);
        }

        /* Quiz Settings */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: var(--gray-50);
            border-radius: 8px;
        }

        .setting-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
        }

        .setting-value {
            font-size: 14px;
            color: var(--gray-900);
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions .btn {
                flex: 1;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tabs-nav {
                overflow-x: auto;
            }

            .quiz-meta {
                flex-direction: column;
                gap: 12px;
            }

            .modal-container {
                max-width: 100%;
                max-height: 100vh;
                border-radius: 0;
            }

            .attempt-info-grid {
                grid-template-columns: 1fr;
            }

            .attempt-item {
                flex-wrap: wrap;
            }

            .attempt-meta {
                flex-wrap: wrap;
            }
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 80px;
            right: 24px;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            border-left: 4px solid var(--success);
        }

        .notification.error {
            border-left: 4px solid var(--error);
        }

        .notification.warning {
            border-left: 4px solid var(--warning);
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="navbar-container">
            <div class="navbar-left">
                <button class="back-button" onclick="window.location.href='teacher_view_subject.php?id=<?php echo $quiz['subject_id']; ?>&tab=quiz'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="navbar-brand">
                    <span>ClassroomHub</span>
                </div>
            </div>
            <div class="navbar-right">
                <div class="quiz-status-badge status-<?php echo $quiz['status']; ?>">
                    <?php echo ucfirst($quiz['status']); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quiz Header -->
    <div class="quiz-header">
        <div class="container">
            <div class="header-content">
                <div class="header-left">
                    <div class="quiz-icon-wrapper">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <h1 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h1>
                    <p class="quiz-subtitle">
                        <?php echo htmlspecialchars($quiz['subject_name']); ?>
                        <?php if ($quiz['section']): ?>
                            - <?php echo htmlspecialchars($quiz['section']); ?>
                        <?php endif; ?>
                    </p>
                    <div class="quiz-meta">
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <span><?php echo $quiz['due_date'] ? date('M d, Y g:i A', strtotime($quiz['due_date'])) : 'No due date'; ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-star"></i>
                            <span><?php echo $quiz['total_points']; ?> points</span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-question-circle"></i>
                            <span><?php echo $total_questions; ?> question<?php echo $total_questions != 1 ? 's' : ''; ?></span>
                        </div>
                        <?php if ($quiz['time_limit']): ?>
                        <div class="meta-item">
                            <i class="fas fa-clock"></i>
                            <span><?php echo $quiz['time_limit']; ?> minutes</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!$can_edit): ?>
                    <div class="lock-notice">
                        <i class="fas fa-lock"></i>
                        <div class="lock-notice-text">
                            <strong>Editing locked:</strong> Students have already taken this quiz. You can view results but cannot edit questions or settings.
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($quiz['status'] === 'closed'): ?>
                    <div class="lock-notice" style="background: #fee2e2; border-color: #fecaca;">
                        <i class="fas fa-ban" style="color: #dc2626;"></i>
                        <div class="lock-notice-text" style="color: #991b1b;">
                            <strong>Quiz Closed:</strong> This quiz is no longer accepting submissions. Students cannot take or continue this quiz.
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="header-actions">
                    <?php if ($can_edit): ?>
                    <!--a href="quiz_creator.php?quiz_id=<?php echo $quiz_id; ?>" class="btn btn-outlined">
                        <i class="fas fa-edit"></i>
                        Edit Quiz
                    </a-->
                    <?php endif; ?>
                    
                    <?php if ($quiz['status'] === 'draft'): ?>
                    <button class="btn btn-primary" onclick="publishQuiz()">
                        <i class="fas fa-paper-plane"></i>
                        Publish Quiz
                    </button>
                    <?php elseif ($quiz['status'] === 'published'): ?>
                    <button class="btn btn-outlined" onclick="closeQuiz()">
                        <i class="fas fa-ban"></i>
                        Close Quiz
                    </button>
                    <?php elseif ($quiz['status'] === 'closed'): ?>
                    <button class="btn btn-primary" onclick="reopenQuiz()">
                        <i class="fas fa-unlock"></i>
                        Reopen Quiz
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">
                        <i class="fas fa-users"></i>
                        Students Taken
                    </div>
                    <div class="stat-value"><?php echo $students_taken; ?></div>
                    <div class="stat-subtext">out of <?php echo $total_students; ?> enrolled</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">
                        <i class="fas fa-clipboard-list"></i>
                        Total Attempts
                    </div>
                    <div class="stat-value"><?php echo $total_attempts; ?></div>
                    <div class="stat-subtext"><?php echo $completed_attempts; ?> completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">
                        <i class="fas fa-chart-line"></i>
                        Average Score
                    </div>
                    <div class="stat-value"><?php echo number_format($average_score, 1); ?>%</div>
                    <div class="stat-subtext">from graded attempts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">
                        <i class="fas fa-check-circle"></i>
                        Pass Rate
                    </div>
                    <div class="stat-value">
                        <?php 
                        $passed = count(array_filter($graded_attempts, function($a) use ($quiz) { 
                            return $a['percentage'] >= $quiz['passing_score']; 
                        }));
                        echo count($graded_attempts) > 0 ? number_format(($passed / count($graded_attempts)) * 100, 0) : '0';
                        ?>%
                    </div>
                    <div class="stat-subtext"><?php echo $passed; ?> passed (<?php echo $quiz['passing_score']; ?>% needed)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="tabs-wrapper">
        <div class="tabs-nav">
            <button class="tab-button <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" data-tab="overview">
                <i class="fas fa-info-circle"></i>
                <span>Overview</span>
            </button>
            <button class="tab-button <?php echo $active_tab === 'questions' ? 'active' : ''; ?>" data-tab="questions">
                <i class="fas fa-list"></i>
                <span>Questions</span>
            </button>
            <button class="tab-button <?php echo $active_tab === 'attempts' ? 'active' : ''; ?>" data-tab="attempts">
                <i class="fas fa-users"></i>
                <span>Student Attempts</span>
            </button>
            <button class="tab-button <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">
        <!-- Overview Tab -->
        <div class="tab-content <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" id="overview-tab">
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">Quiz Description</h2>
                </div>
                <div class="card-body">
                    <?php if ($quiz['description']): ?>
                        <p style="color: var(--gray-700); line-height: 1.6;"><?php echo nl2br(htmlspecialchars($quiz['description'])); ?></p>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 40px 20px;">
                            <p style="color: var(--gray-500);">No description provided</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">Quick Stats</h2>
                </div>
                <div class="card-body">
                    <div class="settings-grid">
                        <div class="setting-item">
                            <span class="setting-label">Total Questions</span>
                            <span class="setting-value"><?php echo $total_questions; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Total Points</span>
                            <span class="setting-value"><?php echo $quiz['total_points']; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Time Limit</span>
                            <span class="setting-value"><?php echo $quiz['time_limit'] ? $quiz['time_limit'] . ' minutes' : 'No limit'; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Max Attempts</span>
                            <span class="setting-value"><?php echo $quiz['max_attempts']; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Passing Score</span>
                            <span class="setting-value"><?php echo $quiz['passing_score']; ?>%</span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Show Results</span>
                            <span class="setting-value"><?php echo $quiz['show_results'] ? 'Yes' : 'No'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Questions Tab -->
        <div class="tab-content <?php echo $active_tab === 'questions' ? 'active' : ''; ?>" id="questions-tab">
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">All Questions (<?php echo $total_questions; ?>)</h2>
                    <?php if ($can_edit): ?>
                    <a href="quiz_creator.php?quiz_id=<?php echo $quiz_id; ?>" class="btn btn-outlined">
                        <i class="fas fa-edit"></i>
                        Edit Questions
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($questions)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <h3>No questions yet</h3>
                        <p>Add questions to your quiz to get started</p>
                    </div>
                    <?php else: ?>
                    <div class="questions-list">
                        <?php foreach ($questions as $index => $question): ?>
                        <div class="question-card">
                            <div class="question-header">
                                <div class="question-number"><?php echo $index + 1; ?></div>
                                <div class="question-type-badge">
                                    <i class="fas fa-<?php 
                                        echo $question['question_type'] === 'multiple-choice' ? 'list-ul' : 
                                            ($question['question_type'] === 'true-false' ? 'check-circle' : 
                                            ($question['question_type'] === 'modified-true-false' ? 'edit' : 'keyboard')); 
                                    ?>"></i>
                                    <?php echo ucwords(str_replace('-', ' ', $question['question_type'])); ?>
                                </div>
                            </div>
                            
                            <div class="question-text"><?php echo htmlspecialchars($question['question_text']); ?></div>
                            
                            <div class="question-points">
                                <i class="fas fa-star"></i>
                                <?php echo $question['points']; ?> point<?php echo $question['points'] != 1 ? 's' : ''; ?>
                            </div>

                            <?php if ($question['question_type'] === 'multiple-choice' || $question['question_type'] === 'true-false'): ?>
                            <div class="options-list">
                                <?php 
                                $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
                                foreach ($question['options'] as $opt_index => $option): 
                                ?>
                                <div class="option-item <?php echo $option['is_correct'] ? 'correct' : ''; ?>">
                                    <div class="option-letter"><?php echo $letters[$opt_index]; ?></div>
                                    <div class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></div>
                                    <?php if ($option['is_correct']): ?>
                                    <div class="correct-indicator">
                                        <i class="fas fa-check-circle"></i>
                                        Correct
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif ($question['question_type'] === 'short-answer' || $question['question_type'] === 'modified-true-false'): ?>
                            <?php if ($question['answer']): ?>
                            <div class="answer-box">
                                <div class="answer-label">Correct Answer</div>
                                <div class="answer-text"><?php echo htmlspecialchars($question['answer']['answer_text']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

<!-- Attempts Tab -->
<div class="tab-content <?php echo $active_tab === 'attempts' ? 'active' : ''; ?>" id="attempts-tab">
    <div class="content-card">
        <div class="card-header">
            <h2 class="card-title">Student Attempts (<?php echo $students_taken; ?>)</h2>
            <?php if (!empty($attempts)): ?>
            <button class="btn btn-outlined" onclick="exportResults()">
                <i class="fas fa-download"></i>
                Export Results
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($attempts)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>No attempts yet</h3>
                <p>Students haven't started taking this quiz</p>
            </div>
            <?php else: ?>

            <?php
            // Group attempts by student_id
            $grouped = [];
            foreach ($attempts as $attempt) {
                $sid = $attempt['student_id'];
                if (!isset($grouped[$sid])) {
                    $grouped[$sid] = [
                        'first_name'      => $attempt['first_name'],
                        'last_name'       => $attempt['last_name'],
                        'email'           => $attempt['email'],
                        'profile_picture' => $attempt['profile_picture'],
                        'attempts'        => []
                    ];
                }
                $grouped[$sid]['attempts'][] = $attempt;
            }
            ?>

            <div class="attempts-list">
                <?php foreach ($grouped as $sid => $student):
                    $latest          = $student['attempts'][0];
                    $status          = $latest['status'];
                    $is_in_progress  = $status === 'in-progress';
                    $is_done         = $status === 'submitted' || $status === 'graded';
                ?>
                <div class="attempt-item" style="<?php echo $is_in_progress ? 'opacity:0.45; filter:grayscale(0.3);' : ''; ?>">

                    <!-- Avatar -->
                    <div class="student-avatar">
                        <?php if ($student['profile_picture']): ?>
                            <img src="../../student/<?php echo htmlspecialchars($student['profile_picture']); ?>"
                                 alt="<?php echo htmlspecialchars($student['first_name']); ?>">
                        <?php else: ?>
                            <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Student Details -->
                    <div class="attempt-details">
                        <div class="student-name">
                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                        </div>
                        <div class="attempt-meta">
                            <span>
                                <i class="fas fa-envelope"></i>
                                <?php echo htmlspecialchars($student['email']); ?>
                            </span>
                            <span>
                                <i class="fas fa-calendar"></i>
                                <?php echo date('M d, Y g:i A', strtotime($latest['submitted_at'] ?: $latest['started_at'])); ?>
                            </span>
                            <?php if ($latest['time_taken']): ?>
                            <span>
                                <i class="fas fa-clock"></i>
                                <?php echo gmdate("i:s", $latest['time_taken']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Attempt Status Badge -->
                    <div style="display:flex; flex-direction:column; align-items:center; gap:4px; min-width:90px;">
                        <?php
                            $badge_color = match($status) {
                                'graded'      => ['bg' => '#dcfce7', 'color' => '#16a34a', 'icon' => 'fa-check-circle'],
                                'submitted'   => ['bg' => '#fef9c3', 'color' => '#ca8a04', 'icon' => 'fa-hourglass-half'],
                                'in-progress' => ['bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'fa-spinner'],
                                default       => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'icon' => 'fa-circle'],
                            };
                        ?>
                        <div style="
                            background: <?php echo $badge_color['bg']; ?>;
                            color: <?php echo $badge_color['color']; ?>;
                            border-radius: 999px;
                            padding: 4px 12px;
                            font-size: 12px;
                            font-weight: 600;
                            display: flex;
                            align-items: center;
                            gap: 5px;
                            white-space: nowrap;
                        ">
                            <i class="fas <?php echo $badge_color['icon']; ?>"></i>
                            <?php echo ucfirst(str_replace('-', ' ', $status)); ?>
                        </div>
                    </div>

                    <!-- Score -->
                    <?php if ($status === 'graded'): ?>
                    <div class="attempt-score">
                        <div class="score-value"><?php echo number_format($latest['percentage'], 1); ?>%</div>
                        <div class="score-label"><?php echo $latest['score']; ?>/<?php echo $latest['max_score']; ?></div>
                    </div>
                    <?php elseif ($status === 'submitted'): ?>
                    <div class="attempt-score">
                        <div class="score-value" style="font-size:12px; color:var(--gray-500);">Pending</div>
                        <div class="score-label">Needs review</div>
                    </div>
                    <?php elseif ($status === 'in-progress'): ?>
                    <div class="attempt-score">
                        <div class="score-value" style="font-size:13px; color:var(--gray-400);">—</div>
                        <div class="score-label">Not submitted</div>
                    </div>
                    <?php endif; ?>

                    <!-- View Button: only for submitted/graded -->
                    <?php if ($is_done): ?>
                    <button class="view-attempt-btn"
                            onclick="viewAttemptDetails(<?php echo (int)$latest['id']; ?>)"
                            title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <?php else: ?>
                    <div style="width:36px;"></div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

        <!-- Settings Tab -->
        <div class="tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="settings-tab">
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">Quiz Settings</h2>
                </div>
                <div class="card-body">
                    <div class="settings-grid">
                        <div class="setting-item">
                            <span class="setting-label">Quiz Title</span>
                            <span class="setting-value"><?php echo htmlspecialchars($quiz['title']); ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Subject</span>
                            <span class="setting-value"><?php echo htmlspecialchars($quiz['subject_name']); ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Due Date</span>
                            <span class="setting-value"><?php echo $quiz['due_date'] ? date('M d, Y g:i A', strtotime($quiz['due_date'])) : 'None'; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Time Limit</span>
                            <span class="setting-value"><?php echo $quiz['time_limit'] ? $quiz['time_limit'] . ' minutes' : 'No limit'; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Max Attempts</span>
                            <span class="setting-value"><?php echo $quiz['max_attempts']; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Passing Score</span>
                            <span class="setting-value"><?php echo $quiz['passing_score']; ?>%</span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Show Results</span>
                            <span class="setting-value"><?php echo $quiz['show_results'] ? 'Yes' : 'No'; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Shuffle Questions</span>
                            <span class="setting-value"><?php echo $quiz['shuffle_questions'] ? 'Yes' : 'No'; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Shuffle Options</span>
                            <span class="setting-value"><?php echo $quiz['shuffle_options'] ? 'Yes' : 'No'; ?></span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Status</span>
                            <span class="setting-value"><?php echo ucfirst($quiz['status']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">Actions</h2>
                </div>
                <div class="card-body">
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php if ($quiz['status'] === 'draft'): ?>
                        <button class="btn btn-primary" onclick="publishQuiz()" style="justify-content: center;">
                            <i class="fas fa-paper-plane"></i>
                            Publish Quiz
                        </button>
                        <?php elseif ($quiz['status'] === 'published'): ?>
                        <button class="btn btn-outlined" onclick="closeQuiz()" style="justify-content: center;">
                            <i class="fas fa-ban"></i>
                            Close Quiz (Disable Submissions)
                        </button>
                        <?php elseif ($quiz['status'] === 'closed'): ?>
                        <button class="btn btn-outlined" onclick="reopenQuiz()" style="justify-content: center;">
                            <i class="fas fa-unlock"></i>
                            Reopen Quiz
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($can_edit): ?>
                        <button class="btn btn-danger" onclick="deleteQuiz()" style="justify-content: center;">
                            <i class="fas fa-trash"></i>
                            Delete Quiz
                        </button>
                        <?php else: ?>
                        <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; font-size: 13px; color: #92400e;">
                            <i class="fas fa-info-circle"></i> 
                            Cannot delete quiz because students have already taken it. You can close it to prevent further submissions.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attempt Details Modal -->
    <div class="modal-overlay" id="attemptModal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title-section">
                    <h2 class="modal-title" id="modalStudentName">Loading...</h2>
                    <p class="modal-subtitle" id="modalAttemptInfo">Please wait...</p>
                </div>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalContent">
                <div class="modal-loading">
                    <i class="fas fa-spinner"></i>
                    <p style="margin-top: 12px; color: var(--gray-600);">Loading attempt details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outlined" onclick="closeModal()">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Tab Management
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');

        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const tabName = button.getAttribute('data-tab');
                switchTab(tabName);
            });
        });

        function switchTab(tabName) {
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            // Update active states
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));

            const activeButton = document.querySelector(`[data-tab="${tabName}"]`);
            const activeContent = document.getElementById(`${tabName}-tab`);
            
            if (activeButton) activeButton.classList.add('active');
            if (activeContent) activeContent.classList.add('active');
        }

        // Modal Functions
        function viewAttemptDetails(attemptId) {
            const modal = document.getElementById('attemptModal');
            const modalContent = document.getElementById('modalContent');
            
            // Show modal with loading state
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Reset to loading state
            modalContent.innerHTML = `
                <div class="modal-loading">
                    <i class="fas fa-spinner"></i>
                    <p style="margin-top: 12px; color: var(--gray-600);">Loading attempt details...</p>
                </div>
            `;
            
            // Fetch attempt details
            fetch(`get_attempt_details.php?attempt_id=${attemptId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAttemptDetails(data);
                    } else {
                        modalContent.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class="fas fa-exclamation-circle"></i>
                                </div>
                                <h3>Error Loading Details</h3>
                                <p>${data.message || 'Could not load attempt details'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalContent.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-exclamation-circle"></i>
                            </div>
                            <h3>Error</h3>
                            <p>Failed to load attempt details</p>
                        </div>
                    `;
                });
        }

        function displayAttemptDetails(data) {
            const attempt = data.attempt;
            const responses = data.responses;
            
            // Update modal header
            document.getElementById('modalStudentName').textContent = attempt.student_name;
            document.getElementById('modalAttemptInfo').textContent = 
                `Attempt ${attempt.attempt_number} • Submitted ${new Date(attempt.submitted_at || attempt.started_at).toLocaleString()}`;
            
            // Build content
            let content = `
                <!-- Attempt Summary -->
                <div class="attempt-info-grid">
                    <div class="attempt-info-item">
                        <div class="attempt-info-label">Score</div>
                        <div class="attempt-info-value">
                            <span class="score-badge ${attempt.percentage >= <?php echo $quiz['passing_score']; ?> ? 'pass' : 'fail'}">
                                ${attempt.score}/${attempt.max_score} (${attempt.percentage}%)
                            </span>
                        </div>
                    </div>
                    <div class="attempt-info-item">
                        <div class="attempt-info-label">Status</div>
                        <div class="attempt-info-value">${attempt.status_display}</div>
                    </div>
                    <div class="attempt-info-item">
                        <div class="attempt-info-label">Time Taken</div>
                        <div class="attempt-info-value">${attempt.time_taken_display || 'N/A'}</div>
                    </div>
                    <div class="attempt-info-item">
                        <div class="attempt-info-label">Result</div>
                        <div class="attempt-info-value">
                            ${attempt.percentage >= <?php echo $quiz['passing_score']; ?> 
                                ? '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> Passed</span>' 
                                : '<span style="color: var(--error);"><i class="fas fa-times-circle"></i> Failed</span>'}
                        </div>
                    </div>
                </div>

                <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; color: var(--gray-900);">
                    <i class="fas fa-clipboard-list"></i> Responses (${responses.length})
                </h3>
            `;
            
            // Add responses
            responses.forEach((response, index) => {
                const isCorrect = response.is_correct;
                const earnedPoints = response.points_earned || 0;
                const maxPoints = response.max_points || 0;
                
                content += `
                    <div class="response-item">
                        <div class="response-header">
                            <div style="display: flex; align-items: flex-start; gap: 12px; flex: 1;">
                                <div class="question-number" style="min-width: 32px;">${index + 1}</div>
                                <div class="response-question">${response.question_text}</div>
                            </div>
                            <div class="response-points ${isCorrect ? 'correct' : (earnedPoints > 0 ? 'partial' : 'incorrect')}">
                                ${earnedPoints}/${maxPoints} pts
                            </div>
                        </div>
                        
                        <!-- Student's Answer -->
                        <div class="student-answer ${isCorrect ? 'correct' : 'incorrect'}">
                            <div class="answer-label" style="color: ${isCorrect ? 'var(--success)' : 'var(--error)'};">
                                ${isCorrect ? '<i class="fas fa-check-circle"></i> Student Answer (Correct)' : '<i class="fas fa-times-circle"></i> Student Answer (Incorrect)'}
                            </div>
                            <div class="answer-text">${response.student_answer || '<em style="color: var(--gray-500);">No answer provided</em>'}</div>
                        </div>
                        
                        <!-- Correct Answer (if student was wrong) -->
                        ${!isCorrect && response.correct_answer ? `
                            <div class="correct-answer-display">
                                <div class="correct-answer-label">
                                    <i class="fas fa-check-circle"></i> Correct Answer
                                </div>
                                <div class="answer-text">${response.correct_answer}</div>
                            </div>
                        ` : ''}
                        
                        ${response.teacher_feedback ? `
                            <div style="margin-top: 12px; padding: 12px; background: #fef3c7; border-radius: 8px;">
                                <div style="font-size: 11px; font-weight: 700; color: #92400e; text-transform: uppercase; margin-bottom: 4px;">
                                    <i class="fas fa-comment"></i> Teacher Feedback
                                </div>
                                <div style="font-size: 14px; color: #78350f;">${response.teacher_feedback}</div>
                            </div>
                        ` : ''}
                    </div>
                `;
            });
            
            document.getElementById('modalContent').innerHTML = content;
        }

        function closeModal() {
            const modal = document.getElementById('attemptModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('attemptModal');
            if (event.target === modal) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Quiz Actions
        function publishQuiz() {
            if (confirm('Publish this quiz? Students will be able to see and take it.')) {
                updateQuizStatus('published');
            }
        }

        function closeQuiz() {
            if (confirm('Close this quiz? Students will no longer be able to take it. This action cannot be undone.')) {
                updateQuizStatus('closed');
            }
        }

        function reopenQuiz() {
            if (confirm('Reopen this quiz? Students will be able to take it again.')) {
                updateQuizStatus('published');
            }
        }

        function updateQuizStatus(status) {
            fetch('update_quiz_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    quiz_id: <?php echo $quiz_id; ?>,
                    status: status
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Quiz status updated successfully!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showNotification('Failed to update quiz status: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error updating quiz status', 'error');
            });
        }

        function deleteQuiz() {
            if (confirm('Are you sure you want to delete this quiz? This action cannot be undone.')) {
                if (confirm('All questions and student attempts will be permanently deleted. Continue?')) {
                    fetch('api/delete_quiz.php?quiz_id=<?php echo $quiz_id; ?>', {
                        method: 'DELETE'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('Quiz deleted successfully!', 'success');
                            setTimeout(() => {
                                window.location.href = 'teacher_view_subject.php?id=<?php echo $quiz['subject_id']; ?>&tab=quiz';
                            }, 1500);
                        } else {
                            showNotification('Failed to delete quiz: ' + data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Error deleting quiz', 'error');
                    });
                }
            }
        }

        function exportResults() {
            window.location.href = 'api/export_quiz_results.php?quiz_id=<?php echo $quiz_id; ?>';
        }

        // Notification System
        function showNotification(message, type = 'info') {
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const successMsg = urlParams.get('success');
            const errorMsg = urlParams.get('error');
            
            if (successMsg) {
                showNotification(decodeURIComponent(successMsg), 'success');
            }
            
            if (errorMsg) {
                showNotification(decodeURIComponent(errorMsg), 'error');
            }
        });
    </script>
</body>
</html>