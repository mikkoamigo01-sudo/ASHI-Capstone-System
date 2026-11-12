<?php
session_start();

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header('Location: ../login.php'); // Adjust path as needed
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

// Internal database connection
$servername = "localhost";
$username = "u304756553_ashi_classroom"; // Change to your database username
$password = "Ashi_Classroom17"; // Change to your database password
$dbname = "u304756553_ashi_classroom"; // Your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

// Get current school year and quarter from settings
$sy_result = $conn->query("SELECT school_year, current_quarter FROM school_year_settings LIMIT 1");
$sy_settings = $sy_result ? $sy_result->fetch_assoc() : ['school_year' => 'N/A', 'current_quarter' => 'N/A'];
$current_school_year = $sy_settings['school_year'];
$current_quarter     = $sy_settings['current_quarter'];

// Quarter label helper
$quarter_labels = [1 => '1st Quarter', 2 => '2nd Quarter', 3 => '3rd Quarter', 4 => '4th Quarter'];
$current_quarter_label = $quarter_labels[$current_quarter] ?? "Quarter $current_quarter";

// Get teacher information
$stmt = $conn->prepare("SELECT fullname, email, profile_picture FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher_result = $stmt->get_result();
$teacher_info = $teacher_result->fetch_assoc();
$stmt->close();

// Get teacher's subjects
$stmt = $conn->prepare("
    SELECT 
        id, 
        subject_name, 
        section, 
        grade, 
        class_code,
        theme_color,
        description
    FROM subjects 
    WHERE teacher_id = ? AND status = 'active'
    ORDER BY subject_name, section
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

// Check if subject_id is passed from subject card
$preselected_subject_id = null;
if (isset($_GET['subject_id'])) {
    $preselected_subject_id = intval($_GET['subject_id']);
    
    // Verify this subject belongs to the teacher
    $stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $preselected_subject_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $preselected_subject_id = null; // Invalid subject
    }
    $stmt->close();
}

// Check if editing existing quiz
$editing_quiz = false;
$quiz_data = null;
if (isset($_GET['quiz_id'])) {
    $quiz_id = intval($_GET['quiz_id']);
    
    // Fetch quiz data
    $stmt = $conn->prepare("
        SELECT * FROM quizzes 
        WHERE id = ? AND teacher_id = ?
    ");
    $stmt->bind_param("ii", $quiz_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $editing_quiz = true;
        $quiz_data = $result->fetch_assoc();
        
        // Fetch questions
        $stmt2 = $conn->prepare("
            SELECT * FROM quiz_questions 
            WHERE quiz_id = ? 
            ORDER BY question_order
        ");
        $stmt2->bind_param("i", $quiz_id);
        $stmt2->execute();
        $questions_result = $stmt2->get_result();
        
        $quiz_data['questions'] = [];
        while ($question = $questions_result->fetch_assoc()) {
            $question_id = $question['id'];
            
            // Fetch options
            $stmt3 = $conn->prepare("
                SELECT * FROM quiz_question_options 
                WHERE question_id = ? 
                ORDER BY option_order
            ");
            $stmt3->bind_param("i", $question_id);
            $stmt3->execute();
            $options_result = $stmt3->get_result();
            
            $question['options'] = [];
            while ($option = $options_result->fetch_assoc()) {
                $question['options'][] = $option;
            }
            $stmt3->close();
            
            // Fetch answer text (for short-answer and modified-true-false)
            $stmt4 = $conn->prepare("
                SELECT answer_text FROM quiz_question_answers 
                WHERE question_id = ? 
                LIMIT 1
            ");
            $stmt4->bind_param("i", $question_id);
            $stmt4->execute();
            $answer_result = $stmt4->get_result();
            if ($answer_result->num_rows > 0) {
                $answer_row = $answer_result->fetch_assoc();
                $question['answer_text'] = $answer_row['answer_text'];
            }
            $stmt4->close();
            
            $quiz_data['questions'][] = $question;
        }
        $stmt2->close();
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../teacher/img/ashi.jpg" type="image/x-icon">
    <title><?php echo $editing_quiz ? 'Edit Quiz' : 'Create Quiz'; ?> - Teacher Edition</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-image: url("data:image/svg+xml,%3Csvg width='54' height='54' viewBox='0 0 54 54' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cpath d='M27 0v27M54 27H27M27 54V27M0 27h27' stroke='%2320bf6b' stroke-width='2' stroke-opacity='0.12'/%3E%3Cpath d='M13.5 13.5h27v27h-27z' stroke='%2326de81' stroke-width='1.5' stroke-opacity='0.12'/%3E%3C/g%3E%3C/svg%3E");
            min-height: 100vh;
            padding-bottom: 40px;
        }

        /* Google Classroom Header */
        .top-bar {
            background: #1967d2;
            color: white;
            padding: 16px 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .top-bar-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .teacher-details h3 {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 2px;
        }

        .teacher-details p {
            font-size: 13px;
            opacity: 0.9;
        }

        .back-btn {
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            max-width: 900px;
            margin: 24px auto;
            padding: 0 16px;
        }

        .page-header {
            background: white;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
        }

        .page-title {
            font-size: 28px;
            color: #202124;
            margin-bottom: 8px;
            font-weight: 400;
        }

        .page-subtitle {
            font-size: 14px;
            color: #5f6368;
            margin-bottom: 0;
        }

        .success-message,
        .error-message {
            padding: 16px 24px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: none;
            font-size: 14px;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .quiz-card {
            background: white;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
        }

        .header input,
        .header textarea,
        .header select {
            width: 100%;
            padding: 12px 16px;
            margin-bottom: 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: border-color 0.3s;
            background: white;
        }

        .header input:focus,
        .header textarea:focus,
        .header select:focus {
            outline: none;
            border-color: #1967d2;
            box-shadow: 0 0 0 2px rgba(25,103,210,0.1);
        }

        .header input[type="text"]:first-child {
            font-size: 24px;
            font-weight: 400;
            color: #202124;
        }

        .quiz-settings {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .quiz-settings label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #3c4043;
            font-size: 13px;
        }

        .quiz-settings input,
        .quiz-settings select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            background: white;
        }

        .required-field::after {
            content: " *";
            color: #d93025;
        }

        /* School Year & Quarter badge panel */
        .sy-badge-panel {
            grid-column: 1 / -1;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .sy-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            flex: 1;
            min-width: 180px;
        }

        .sy-badge.school-year {
            background: #e8f0fe;
            border: 1px solid #c5d8fb;
            color: #1967d2;
        }

        .sy-badge.quarter {
            background: #e6f4ea;
            border: 1px solid #b7dfc2;
            color: #137333;
        }

        .sy-badge .badge-icon {
            font-size: 18px;
        }

        .sy-badge .badge-info {
            display: flex;
            flex-direction: column;
        }

        .sy-badge .badge-label {
            font-size: 11px;
            opacity: 0.75;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 400;
        }

        .sy-badge .badge-value {
            font-size: 14px;
            font-weight: 600;
        }

        .subject-preview {
            grid-column: 1 / -1;
            padding: 16px;
            background: #e8f0fe;
            border-radius: 4px;
            border-left: 4px solid #1967d2;
            display: none;
        }

        .subject-preview.active {
            display: block;
        }

        .subject-preview h4 {
            font-size: 14px;
            color: #1967d2;
            margin-bottom: 12px;
            font-weight: 500;
        }

        .subject-preview p {
            font-size: 13px;
            color: #3c4043;
            margin: 6px 0;
        }

        .question-card {
            background: white;
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 16px;
            transition: all 0.3s;
        }

        .question-card:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.12), 0 2px 4px rgba(0,0,0,0.08);
        }

        .question-card.focused {
            border-color: #1967d2;
            box-shadow: 0 0 0 2px rgba(25,103,210,0.1);
        }

        .question-card.invalid {
            border-color: #d93025;
            background: #fce8e6;
        }

        .question-header {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .question-input {
            flex: 1;
            min-width: 250px;
            padding: 12px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
        }

        .question-type {
            padding: 12px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            min-width: 200px;
            background: white;
        }

        .correct-answer-section {
            margin-bottom: 16px;
        }

        .correct-answer-label {
            font-size: 13px;
            font-weight: 500;
            color: #3c4043;
            margin-bottom: 12px;
        }

        .options-container {
            margin-bottom: 16px;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .option-radio {
            width: 20px;
            height: 20px;
            border: 2px solid #5f6368;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .option-radio:hover {
            border-color: #1967d2;
        }

        .option-radio.selected {
            background: #1967d2;
            border-color: #1967d2;
            box-shadow: inset 0 0 0 3px white;
        }

        .option-input {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
        }

        .remove-option {
            background: #d93025;
            color: white;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .remove-option:hover {
            background: #b31412;
        }

        .add-option-btn {
            background: white;
            border: 1px dashed #dadce0;
            color: #1967d2;
            padding: 10px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }

        .add-option-btn:hover {
            border-color: #1967d2;
            background: #e8f0fe;
        }

        .tf-options {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .tf-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            background: white;
            border: 1px solid #dadce0;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .tf-option:hover {
            background: #e8f0fe;
        }

        .short-answer-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
        }

        .question-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #dadce0;
            flex-wrap: wrap;
            gap: 12px;
        }

        .delete-btn {
            background: #d93025;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 24px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .delete-btn:hover {
            background: #b31412;
            transform: scale(1.1);
        }

        .required-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toggle-switch {
            width: 48px;
            height: 24px;
            background: #dadce0;
            border-radius: 12px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
        }

        .toggle-switch.active {
            background: #1967d2;
        }

        .toggle-slider {
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            position: absolute;
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        .toggle-switch.active .toggle-slider {
            transform: translateX(24px);
        }

        /* Animated Floating Button */
        .add-question-btn {
            position: fixed;
            bottom: 40px;
            right: 40px;
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #10b981 0%, #8b5cf6 50%, #667eea 100%);
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 48px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.5), 0 5px 15px rgba(16, 185, 129, 0.4);
            transition: all 0.3s ease;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: floatBounce 3s ease-in-out infinite;
        }

        @keyframes floatBounce {
            0%, 100% {
                transform: translateY(0) scale(1) rotate(0deg);
                box-shadow: 0 10px 30px rgba(139, 92, 246, 0.5), 0 5px 15px rgba(16, 185, 129, 0.4);
            }
            25% {
                transform: translateY(-25px) scale(1.08) rotate(8deg);
                box-shadow: 0 15px 40px rgba(139, 92, 246, 0.6), 0 8px 20px rgba(16, 185, 129, 0.5);
            }
            50% {
                transform: translateY(-35px) scale(1.12) rotate(-8deg);
                box-shadow: 0 20px 50px rgba(102, 126, 234, 0.6), 0 10px 25px rgba(139, 92, 246, 0.6);
            }
            75% {
                transform: translateY(-25px) scale(1.08) rotate(8deg);
                box-shadow: 0 15px 40px rgba(16, 185, 129, 0.6), 0 8px 20px rgba(139, 92, 246, 0.5);
            }
        }

        .add-question-btn:hover {
            transform: scale(1.2) rotate(0deg);
            box-shadow: 0 15px 45px rgba(139, 92, 246, 0.7), 0 10px 30px rgba(16, 185, 129, 0.6);
            animation: pulse 1.5s ease-in-out infinite;
            background: linear-gradient(135deg, #059669 0%, #7c3aed 50%, #5568d3 100%);
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1.2); }
            50% { transform: scale(1.25); }
        }

        .add-question-btn::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(139, 92, 246, 0.3));
            animation: ripple 2.5s ease-out infinite;
        }

        @keyframes ripple {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Roboto', sans-serif;
        }

        .btn-primary {
            background: #1967d2;
            color: white;
        }

        .btn-primary:hover {
            background: #1557b0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .btn-secondary {
            background: white;
            color: #1967d2;
            border: 1px solid #dadce0;
        }

        .btn-secondary:hover {
            background: #e8f0fe;
        }

        .points-input-wrapper {
            margin-bottom: 16px;
        }

        .points-input-wrapper label {
            font-size: 13px;
            color: #3c4043;
            font-weight: 500;
            display: block;
            margin-bottom: 6px;
        }

        .points-input-wrapper input {
            width: 120px;
            padding: 8px 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-bar { padding: 12px 16px; }
            .top-bar-content { flex-direction: column; align-items: flex-start; }
            .back-btn { width: 100%; justify-content: center; }
            .container { padding: 0 12px; }
            .page-header { padding: 16px; }
            .page-title { font-size: 24px; }
            .quiz-card { padding: 16px; }
            .question-card { padding: 16px; }
            .question-header { flex-direction: column; }
            .question-type { min-width: 100%; }
            .quiz-settings { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column-reverse; }
            .btn { width: 100%; }
            .add-question-btn { bottom: 24px; right: 24px; width: 70px; height: 70px; font-size: 36px; }
            .question-footer { flex-direction: column; align-items: flex-start; }
            .sy-badge-panel { flex-direction: column; }
            .sy-badge { min-width: 100%; }
        }

        @media (max-width: 480px) {
            .page-title { font-size: 20px; }
            .header input[type="text"]:first-child { font-size: 20px; }
            .tf-options { flex-direction: column; }
            .tf-option { width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Google Classroom Style Header -->
    <div class="top-bar">
        <div class="top-bar-content">
            <div class="teacher-info">
                <div class="teacher-details">
                    <h3><?php echo htmlspecialchars($teacher_info['fullname']); ?></h3>
                    <p><?php echo htmlspecialchars($teacher_info['email']); ?></p>
                </div>
            </div>
            <a href="../teacher/teacher_dashb.php" class="back-btn">
                <span class="material-icons" style="font-size: 18px;">arrow_back</span>
                Back to Dashboard
            </a>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><?php echo $editing_quiz ? 'Edit Quiz' : 'Create New Quiz'; ?></h1>
            <p class="page-subtitle">Create engaging quizzes for your students</p>
        </div>

        <div class="success-message" id="successMessage"></div>
        <div class="error-message" id="errorMessage"></div>

        <div class="quiz-card">
            <div class="header">
                <input type="text" placeholder="Untitled Quiz" id="quizTitle" value="<?php echo $editing_quiz ? htmlspecialchars($quiz_data['title']) : 'Untitled Quiz'; ?>">
                <textarea placeholder="Quiz description (optional)" id="quizDescription" rows="3"><?php echo $editing_quiz ? htmlspecialchars($quiz_data['description']) : ''; ?></textarea>
                
                <!-- Quiz Settings -->
                <div class="quiz-settings">

                    <!-- ── School Year & Quarter (auto, read-only) ── -->
                    <div class="sy-badge-panel">
                        <div class="sy-badge school-year">
                            <span class="material-icons badge-icon">calendar_today</span>
                            <div class="badge-info">
                                <span class="badge-label">School Year</span>
                                <span class="badge-value"><?php echo htmlspecialchars($current_school_year); ?></span>
                            </div>
                        </div>
                        <div class="sy-badge quarter">
                            <span class="material-icons badge-icon">format_list_numbered</span>
                            <div class="badge-info">
                                <span class="badge-label">Current Quarter</span>
                                <span class="badge-value"><?php echo htmlspecialchars($current_quarter_label); ?></span>
                            </div>
                        </div>
                    </div>
                    <!-- ─────────────────────────────────────────────── -->

                    <div>
                        <label for="subjectId" class="required-field">Subject</label>
                        <select id="subjectId" required onchange="updateSubjectPreview()">
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option 
                                    value="<?php echo $subject['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($subject['subject_name']); ?>"
                                    data-section="<?php echo htmlspecialchars($subject['section']); ?>"
                                    data-grade="<?php echo htmlspecialchars($subject['grade']); ?>"
                                    data-code="<?php echo htmlspecialchars($subject['class_code']); ?>"
                                    data-description="<?php echo htmlspecialchars($subject['description']); ?>"
                                    <?php 
                                        if ($editing_quiz && $quiz_data['subject_id'] == $subject['id']) {
                                            echo 'selected';
                                        } elseif (!$editing_quiz && $preselected_subject_id && $preselected_subject_id == $subject['id']) {
                                            echo 'selected';
                                        }
                                    ?>
                                >
                                    <?php echo htmlspecialchars($subject['subject_name']); ?> - 
                                    <?php echo htmlspecialchars($subject['section']); ?> 
                                    (Grade <?php echo htmlspecialchars($subject['grade']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="dueDate">Due Date</label>
                        <input type="datetime-local" id="dueDate" value="<?php echo $editing_quiz && $quiz_data['due_date'] ? date('Y-m-d\TH:i', strtotime($quiz_data['due_date'])) : ''; ?>">
                    </div>
                    
                    <div>
                        <label for="timeLimit">Time Limit (minutes)</label>
                        <input type="number" id="timeLimit" placeholder="No limit" min="1" value="<?php echo $editing_quiz ? $quiz_data['time_limit'] : ''; ?>">
                    </div>
                    
                    <div>
                        <label for="maxAttempts">Max Attempts</label>
                        <input type="number" id="maxAttempts" value="<?php echo $editing_quiz ? $quiz_data['max_attempts'] : '1'; ?>" min="1">
                    </div>
                    
                    <div>
                        <label for="passingScore">Passing Score (%)</label>
                        <input type="number" id="passingScore" value="<?php echo $editing_quiz ? $quiz_data['passing_score'] : '60'; ?>" min="0" max="100">
                    </div>
                    
                    <div>
                        <label for="quizStatus">Status</label>
                        <select id="quizStatus">
                            <option value="draft" <?php echo ($editing_quiz && $quiz_data['status'] == 'draft') ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo ($editing_quiz && $quiz_data['status'] == 'published') ? 'selected' : (!$editing_quiz ? 'selected' : ''); ?>>Published</option>
                            <option value="closed" <?php echo ($editing_quiz && $quiz_data['status'] == 'closed') ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>

                    <!-- Subject Preview -->
                    <div class="subject-preview" id="subjectPreview">
                        <h4>📚 Selected Subject Details</h4>
                        <p><strong>Subject:</strong> <span id="previewSubject">-</span></p>
                        <p><strong>Section:</strong> <span id="previewSection">-</span></p>
                        <p><strong>Grade:</strong> <span id="previewGrade">-</span></p>
                        <p><strong>Class Code:</strong> <span id="previewCode">-</span></p>
                    </div>
                </div>
            </div>
        </div>

        <div id="questionsContainer"></div>

        <button class="add-question-btn" onclick="addQuestion()" title="Add question">+</button>

        <div class="quiz-card">
            <div class="action-buttons">
                <button class="btn btn-secondary" onclick="previewQuiz()">Preview Quiz</button>
                <button class="btn btn-primary" onclick="saveQuiz()"><?php echo $editing_quiz ? 'Update Quiz' : 'Save Quiz'; ?></button>
            </div>
        </div>
    </div>

    <script>
        // PHP data passed to JavaScript
        const teacherId    = <?php echo $teacher_id; ?>;
        const editingQuiz  = <?php echo $editing_quiz ? 'true' : 'false'; ?>;
        const quizId       = <?php echo $editing_quiz ? $quiz_data['id'] : 'null'; ?>;

        // Auto-assigned school year and quarter (read from server, not editable)
        const currentSchoolYear = <?php echo json_encode($current_school_year); ?>;
        const currentQuarter    = <?php echo (int)$current_quarter; ?>;
        
        let questionCount = 0;
        let questions = [];

        // Update subject preview
        function updateSubjectPreview() {
            const select = document.getElementById('subjectId');
            const preview = document.getElementById('subjectPreview');
            
            if (select.value) {
                const option = select.options[select.selectedIndex];
                document.getElementById('previewSubject').textContent = option.dataset.name;
                document.getElementById('previewSection').textContent = option.dataset.section;
                document.getElementById('previewGrade').textContent   = option.dataset.grade;
                document.getElementById('previewCode').textContent    = option.dataset.code;
                preview.classList.add('active');
            } else {
                preview.classList.remove('active');
            }
        }

        function createQuestion(type = 'multiple-choice') {
            questionCount++;
            const questionId = `q${questionCount}`;
            
            const question = {
                id: questionId,
                type: type,
                question: '',
                required: true,
                points: 1,
                options: type === 'multiple-choice' ? ['Option 1'] : 
                         type === 'true-false' ? ['True', 'False'] :
                         type === 'modified-true-false' ? ['True', 'False'] : [],
                correctAnswer: type === 'modified-true-false' ? '' : null,
                correctAnswerIndex: null
            };
            
            questions.push(question);
            renderQuestion(question);
            
            setTimeout(() => {
                const newCard = document.getElementById(questionId);
                if (newCard) {
                    document.querySelectorAll('.question-card').forEach(card => card.classList.remove('focused'));
                    newCard.classList.add('focused');
                    newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 100);
        }

        function renderQuestion(question) {
            const container = document.getElementById('questionsContainer');
            const questionCard = document.createElement('div');
            questionCard.className = 'question-card';
            questionCard.id = question.id;
            
            questionCard.addEventListener('click', function(e) {
                document.querySelectorAll('.question-card').forEach(card => card.classList.remove('focused'));
                this.classList.add('focused');
            });
            
            let optionsHTML = '';
            
            if (question.type === 'multiple-choice') {
                optionsHTML = `
                    <div class="correct-answer-section">
                        <div class="correct-answer-label">Select the correct answer:</div>
                    </div>
                    <div class="options-container" id="${question.id}-options">
                        ${question.options.map((opt, idx) => `
                            <div class="option-item">
                                <div class="option-radio ${question.correctAnswerIndex === idx ? 'selected' : ''}" 
                                    onclick="selectCorrectAnswer('${question.id}', ${idx})"></div>
                                <input type="text" class="option-input" placeholder="Option ${idx + 1}" value="${opt}" 
                                    onchange="updateOption('${question.id}', ${idx}, this.value)">
                                ${idx > 0 ? `<button class="remove-option" onclick="removeOption('${question.id}', ${idx})">×</button>` : ''}
                            </div>
                        `).join('')}
                    </div>
                    <button class="add-option-btn" onclick="addOption('${question.id}')">
                        <span>+</span> Add option
                    </button>
                `;
            } else if (question.type === 'true-false') {
                optionsHTML = `
                    <div class="correct-answer-section">
                        <div class="correct-answer-label">Select the correct answer:</div>
                    </div>
                    <div class="tf-options">
                        <div class="tf-option">
                            <div class="option-radio ${question.correctAnswerIndex === 0 ? 'selected' : ''}" 
                                onclick="selectCorrectAnswer('${question.id}', 0)"></div>
                            <span>True</span>
                        </div>
                        <div class="tf-option">
                            <div class="option-radio ${question.correctAnswerIndex === 1 ? 'selected' : ''}" 
                                onclick="selectCorrectAnswer('${question.id}', 1)"></div>
                            <span>False</span>
                        </div>
                    </div>
                `;
            } else if (question.type === 'modified-true-false') {
                optionsHTML = `
                    <div class="correct-answer-section">
                        <div class="correct-answer-label">Select the correct answer:</div>
                    </div>
                    <div class="tf-options">
                        <div class="tf-option">
                            <div class="option-radio ${question.correctAnswerIndex === 0 ? 'selected' : ''}" 
                                onclick="selectCorrectAnswer('${question.id}', 0)"></div>
                            <span>True</span>
                        </div>
                        <div class="tf-option">
                            <div class="option-radio ${question.correctAnswerIndex === 1 ? 'selected' : ''}" 
                                onclick="selectCorrectAnswer('${question.id}', 1)"></div>
                            <span>False</span>
                        </div>
                    </div>
                    <div style="margin-top: 20px;">
                        <div class="correct-answer-label">If False, provide the correct answer:</div>
                        <input type="text" class="question-input" placeholder="Correct answer if false" 
                            value="${question.correctAnswer || ''}"
                            onchange="updateCorrectAnswer('${question.id}', this.value)">
                    </div>
                `;
            } else if (question.type === 'short-answer') {
                optionsHTML = `
                    <div class="correct-answer-section">
                        <div class="correct-answer-label">Expected answer:</div>
                        <input type="text" class="short-answer-input" placeholder="Enter the expected answer" 
                            value="${question.correctAnswer || ''}"
                            onchange="updateCorrectAnswer('${question.id}', this.value)">
                    </div>
                `;
            }
            
            questionCard.innerHTML = `
                <div class="question-header">
                    <input type="text" class="question-input" placeholder="Question" 
                        value="${question.question}"
                        onchange="updateQuestion('${question.id}', this.value)">
                    <select class="question-type" onchange="changeQuestionType('${question.id}', this.value)">
                        <option value="multiple-choice" ${question.type === 'multiple-choice' ? 'selected' : ''}>Multiple Choice</option>
                        <option value="true-false" ${question.type === 'true-false' ? 'selected' : ''}>True or False</option>
                        <option value="modified-true-false" ${question.type === 'modified-true-false' ? 'selected' : ''}>Modified True or False</option>
                        <option value="short-answer" ${question.type === 'short-answer' ? 'selected' : ''}>Short Answer</option>
                    </select>
                </div>
                
                <div class="points-input-wrapper">
                    <label>Points:</label>
                    <input type="number" min="0" step="0.5" value="${question.points}" 
                        onchange="updatePoints('${question.id}', this.value)">
                </div>
                
                ${optionsHTML}
                
                <div class="question-footer">
                    <button class="delete-btn" onclick="deleteQuestion('${question.id}')" title="Delete question">×</button>
                    <div class="required-toggle">
                        <span>Required</span>
                        <div class="toggle-switch ${question.required ? 'active' : ''}" onclick="toggleRequired('${question.id}')">
                            <div class="toggle-slider"></div>
                        </div>
                    </div>
                </div>
            `;
            
            container.appendChild(questionCard);
        }

        function selectCorrectAnswer(id, index) {
            const question = questions.find(q => q.id === id);
            if (question) {
                question.correctAnswerIndex = index;
                const card = document.getElementById(id);
                card.innerHTML = '';
                renderQuestionContent(question, card);
                validateQuestion(id);
            }
        }

        function updateQuestion(id, value) {
            const question = questions.find(q => q.id === id);
            if (question) { question.question = value; validateQuestion(id); }
        }

        function updatePoints(id, value) {
            const question = questions.find(q => q.id === id);
            if (question) question.points = parseFloat(value) || 1;
        }

        function validateQuestion(id) {
            const question = questions.find(q => q.id === id);
            const card = document.getElementById(id);
            if (!question || !card) return;
            
            let isValid = true;
            if (!question.question || question.question.trim() === '') isValid = false;
            
            if (question.type === 'multiple-choice' || question.type === 'true-false') {
                if (question.correctAnswerIndex === null || question.correctAnswerIndex === undefined) isValid = false;
            } else if (question.type === 'modified-true-false') {
                if (question.correctAnswerIndex === null || question.correctAnswerIndex === undefined) isValid = false;
                if (question.correctAnswerIndex === 1 && (!question.correctAnswer || question.correctAnswer.trim() === '')) isValid = false;
            } else if (question.type === 'short-answer') {
                if (!question.correctAnswer || question.correctAnswer.trim() === '') isValid = false;
            }
            
            card.classList.toggle('invalid', !isValid);
            return isValid;
        }

        function validateAllQuestions() {
            let allValid = true;
            questions.forEach(q => { if (!validateQuestion(q.id)) allValid = false; });
            return allValid;
        }

        function updateOption(id, index, value) {
            const question = questions.find(q => q.id === id);
            if (question) question.options[index] = value;
        }

        function updateCorrectAnswer(id, value) {
            const question = questions.find(q => q.id === id);
            if (question) { question.correctAnswer = value; validateQuestion(id); }
        }

        function addOption(id) {
            const question = questions.find(q => q.id === id);
            if (question) {
                question.options.push(`Option ${question.options.length + 1}`);
                const card = document.getElementById(id);
                card.innerHTML = '';
                renderQuestionContent(question, card);
            }
        }

        function removeOption(id, index) {
            const question = questions.find(q => q.id === id);
            if (question && question.options.length > 1) {
                question.options.splice(index, 1);
                if (question.correctAnswerIndex === index) question.correctAnswerIndex = null;
                else if (question.correctAnswerIndex > index) question.correctAnswerIndex--;
                const card = document.getElementById(id);
                card.innerHTML = '';
                renderQuestionContent(question, card);
            }
        }

        function renderQuestionContent(question, card) {
            renderQuestion(question);
            const newCard = document.getElementById(question.id);
            card.innerHTML = newCard.innerHTML;
            newCard.remove();
        }

        function changeQuestionType(id, type) {
            const question = questions.find(q => q.id === id);
            if (question) {
                question.type = type;
                question.options = type === 'multiple-choice' ? ['Option 1'] : 
                                   type === 'true-false' ? ['True', 'False'] :
                                   type === 'modified-true-false' ? ['True', 'False'] : [];
                question.correctAnswer = (type === 'modified-true-false' || type === 'short-answer') ? '' : null;
                question.correctAnswerIndex = null;
                const card = document.getElementById(id);
                card.innerHTML = '';
                renderQuestionContent(question, card);
                validateQuestion(id);
            }
        }

        function toggleRequired(id) {
            const question = questions.find(q => q.id === id);
            if (question) {
                question.required = !question.required;
                document.querySelector(`#${id} .toggle-switch`).classList.toggle('active');
            }
        }

        function deleteQuestion(id) {
            if (confirm('Delete this question?')) {
                questions = questions.filter(q => q.id !== id);
                document.getElementById(id).remove();
            }
        }

        function addQuestion() { createQuestion('multiple-choice'); }

        function showMessage(message, isError = false) {
            const successMsg = document.getElementById('successMessage');
            const errorMsg   = document.getElementById('errorMessage');
            
            if (isError) {
                errorMsg.textContent = message;
                errorMsg.style.display = 'block';
                successMsg.style.display = 'none';
            } else {
                successMsg.textContent = message;
                successMsg.style.display = 'block';
                errorMsg.style.display = 'none';
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => {
                successMsg.style.display = 'none';
                errorMsg.style.display = 'none';
            }, 5000);
        }

        function previewQuiz() {
            validateAllQuestions();
            
            const title       = document.getElementById('quizTitle').value;
            const description = document.getElementById('quizDescription').value;
            const select      = document.getElementById('subjectId');
            const subjectName = select.options[select.selectedIndex]?.text || 'No subject selected';
            
            let preview = `<h1>${title}</h1>`;
            preview += `<p><strong>Subject:</strong> ${subjectName}</p>`;
            preview += `<p><strong>School Year:</strong> ${currentSchoolYear} &nbsp;|&nbsp; <strong>Quarter:</strong> Q${currentQuarter}</p>`;
            preview += `<p>${description}</p><hr>`;
            
            questions.forEach((q, idx) => {
                preview += `<h3>Question ${idx + 1}${q.required ? ' *' : ''} (${q.points} points)</h3>`;
                preview += `<p>${q.question || 'Untitled Question'}</p>`;
                
                if (q.type === 'multiple-choice') {
                    preview += '<ul>';
                    q.options.forEach((opt, optIdx) => {
                        preview += `<li>${opt}${q.correctAnswerIndex === optIdx ? ' ✓' : ''}</li>`;
                    });
                    preview += '</ul>';
                } else if (q.type === 'true-false') {
                    preview += `<ul><li>True${q.correctAnswerIndex === 0 ? ' ✓' : ''}</li><li>False${q.correctAnswerIndex === 1 ? ' ✓' : ''}</li></ul>`;
                } else if (q.type === 'modified-true-false') {
                    preview += `<ul><li>True${q.correctAnswerIndex === 0 ? ' ✓' : ''}</li><li>False${q.correctAnswerIndex === 1 ? ' ✓' : ''}</li></ul>`;
                    preview += `<p><em>If False, correct answer: ${q.correctAnswer || 'Not specified'}</em></p>`;
                } else if (q.type === 'short-answer') {
                    preview += `<p><em>Expected answer: ${q.correctAnswer || 'Not specified'}</em></p>`;
                }
                preview += '<hr>';
            });
            
            const win = window.open('', '_blank');
            win.document.write(`
                <html>
                    <head>
                        <title>Quiz Preview - ${title}</title>
                        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
                        <style>
                            body { font-family:'Roboto',sans-serif; max-width:800px; margin:40px auto; padding:20px; background:#f5f5f5; }
                            h1 { color:#1967d2; margin-bottom:10px; }
                            hr { margin:20px 0; border:none; border-top:1px solid #dadce0; }
                            ul { margin:10px 0; } li { margin:5px 0; } p { color:#3c4043; }
                        </style>
                    </head>
                    <body>${preview}</body>
                </html>
            `);
        }

        function saveQuiz() {
            const title     = document.getElementById('quizTitle').value;
            const subjectId = document.getElementById('subjectId').value;
            
            if (!title || title.trim() === '' || title === 'Untitled Quiz') {
                showMessage('Please enter a quiz title before saving.', true);
                document.getElementById('quizTitle').focus();
                return;
            }

            if (!subjectId) {
                showMessage('Please select a subject before saving.', true);
                document.getElementById('subjectId').focus();
                return;
            }
            
            if (!validateAllQuestions()) {
                showMessage('Please complete all required fields in your questions.', true);
                return;
            }
            
            if (questions.length === 0) {
                showMessage('Please add at least one question before saving.', true);
                return;
            }

            const totalPoints = questions.reduce((sum, q) => sum + parseFloat(q.points), 0);

            const quizData = {
                quiz_id:      quizId,
                title:        title,
                description:  document.getElementById('quizDescription').value,
                subject_id:   subjectId,
                teacher_id:   teacherId,
                due_date:     document.getElementById('dueDate').value || null,
                time_limit:   document.getElementById('timeLimit').value || null,
                max_attempts: document.getElementById('maxAttempts').value || 1,
                passing_score: document.getElementById('passingScore').value || 60,
                total_points: totalPoints,
                status:       document.getElementById('quizStatus').value,
                // Auto-assigned — not user-editable
                school_year:  currentSchoolYear,
                quarter:      currentQuarter,
                questions: questions.map((q, index) => ({
                    question_order:       index + 1,
                    question_text:        q.question,
                    question_type:        q.type,
                    points:               q.points,
                    is_required:          q.required,
                    options:              q.options,
                    correct_answer_index: q.correctAnswerIndex,
                    correct_answer_text:  q.correctAnswer
                }))
            };

            fetch('save_quiz.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(quizData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(editingQuiz ? 'Quiz updated successfully!' : 'Quiz saved successfully!');
                    setTimeout(() => { window.location.href = '../teacher/teacher_dashb.php'; }, 2000);
                } else {
                    showMessage('Error saving quiz: ' + data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Error saving quiz. Please try again.', true);
            });
        }

        // Initialize
        window.onload = () => {
            <?php if ($editing_quiz && !empty($quiz_data['questions'])): ?>
                <?php foreach ($quiz_data['questions'] as $qindex => $qdata): ?>
                    questionCount++;
                    const q<?php echo $qindex; ?> = {
                        id: `q${questionCount}`,
                        type: '<?php echo $qdata['question_type']; ?>',
                        question: <?php echo json_encode($qdata['question_text']); ?>,
                        required: <?php echo $qdata['is_required'] ? 'true' : 'false'; ?>,
                        points: <?php echo $qdata['points']; ?>,
                        options: <?php echo json_encode(array_map(function($opt) { return $opt['option_text']; }, $qdata['options'])); ?>,
                        correctAnswer: <?php echo isset($qdata['answer_text']) ? json_encode($qdata['answer_text']) : 'null'; ?>,
                        correctAnswerIndex: <?php 
                            $correctIdx = null;
                            foreach ($qdata['options'] as $idx => $opt) {
                                if ($opt['is_correct']) { $correctIdx = $idx; break; }
                            }
                            echo $correctIdx !== null ? $correctIdx : 'null';
                        ?>
                    };
                    questions.push(q<?php echo $qindex; ?>);
                    renderQuestion(q<?php echo $qindex; ?>);
                <?php endforeach; ?>
            <?php else: ?>
                createQuestion('multiple-choice');
            <?php endif; ?>
            
            updateSubjectPreview();
            
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.question-card')) {
                    document.querySelectorAll('.question-card').forEach(card => card.classList.remove('focused'));
                }
            });
        };
    </script>
</body>
</html>