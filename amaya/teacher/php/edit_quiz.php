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

$teacher_id = (int) $_SESSION['teacher_id'];

// Check if quiz_id is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: view_quizzes.php");
    exit();
}

$quiz_id = (int) $_GET['id'];

// Fetch quiz details and verify ownership
$stmt = $conn->prepare("
    SELECT q.*, s.subject_name, s.theme_color, t.fullname AS teacher_name
    FROM quizzes  q
    INNER JOIN subjects s ON q.subject_id = s.id
    INNER JOIN teachers t ON q.teacher_id = t.id
    WHERE q.id = ? AND q.teacher_id = ?
");
$stmt->bind_param("ii", $quiz_id, $teacher_id);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    echo "<script>alert('Quiz not found or access denied!'); window.location.href='view_quizzes.php';</script>";
    exit();
}

// Fetch all questions for this quiz
$stmt = $conn->prepare("
    SELECT * FROM quiz_questions
    WHERE quiz_id = ?
    ORDER BY question_order ASC
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch options for each question (prepare once, reuse)
$opt_stmt = $conn->prepare("
    SELECT * FROM quiz_question_options
    WHERE question_id = ?
    ORDER BY option_order ASC
");

foreach ($questions as &$question) {
    $qid = (int) $question['id'];
    $opt_stmt->bind_param("i", $qid);
    $opt_stmt->execute();
    $question['options'] = $opt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
unset($question); // break reference

$opt_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title>Edit Quiz - <?php echo htmlspecialchars($quiz['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Google Sans', 'Roboto', Arial, sans-serif;
            background: #f8f9fa;
            color: #202124;
            line-height: 1.6;
        }

        .header {
            background: #fff;
            border-bottom: 1px solid #dadce0;
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.1);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            background: transparent;
            color: #5f6368;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .back-btn:hover {
            background: #f8f9fa;
            border-color: #1a73e8;
            color: #1a73e8;
        }

        .header h1 {
            font-size: 20px;
            font-weight: 400;
            color: #202124;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: #1a73e8;
        }

        .subject-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
        }

        .container {
            max-width: 900px;
            margin: 32px auto;
            padding: 0 24px;
        }

        .quiz-header {
            background: #fff;
            border-radius: 8px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }

        .quiz-header input[type="text"],
        .quiz-header textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 16px;
            font-family: inherit;
            margin-bottom: 16px;
            transition: border-color 0.2s;
        }

        .quiz-header input[type="text"]:focus,
        .quiz-header textarea:focus {
            outline: none;
            border-color: #1a73e8;
        }

        .quiz-header textarea {
            min-height: 80px;
            resize: vertical;
        }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #e8f0fe;
            color: #1a73e8;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }

        .question-card {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }

        .question-header {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }

        .question-number {
            width: 36px;
            height: 36px;
            background: #1a73e8;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }

        .question-input {
            flex: 1;
        }

        .question-input input {
            width: 100%;
            padding: 12px;
            border: none;
            border-bottom: 2px solid #dadce0;
            font-size: 16px;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .question-input input:focus {
            outline: none;
            border-bottom-color: #1a73e8;
        }

        .options-container {
            margin-left: 52px;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .option-item input[type="radio"],
        .option-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .option-item input[type="text"] {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }

        .option-item input[type="text"]:focus {
            outline: none;
            border-color: #1a73e8;
        }

        .delete-option {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: #5f6368;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .delete-option:hover {
            background: #f8f9fa;
            color: #d93025;
        }

        .add-option-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            background: transparent;
            color: #1a73e8;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 8px;
        }

        .add-option-btn:hover {
            background: #f8f9fa;
        }

        .true-false-options {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }

        .tf-option {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            border: 2px solid #dadce0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tf-option:hover {
            border-color: #1a73e8;
            background: #f8f9fa;
        }

        .tf-option.selected {
            border-color: #1a73e8;
            background: #e8f0fe;
        }

        .tf-option input[type="radio"] {
            width: 20px;
            height: 20px;
        }

        .tf-option i {
            font-size: 24px;
        }

        .tf-option label {
            flex: 1;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
        }

        .explanation-field {
            margin-top: 16px;
        }

        .explanation-field label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #5f6368;
            margin-bottom: 8px;
        }

        .explanation-field textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            min-height: 80px;
            resize: vertical;
        }

        .explanation-field textarea:focus {
            outline: none;
            border-color: #1a73e8;
        }

        .short-answer-preview,
        .paragraph-preview {
            padding: 16px;
            background: #f8f9fa;
            border-radius: 4px;
            color: #5f6368;
            text-align: center;
            font-size: 14px;
        }

        .short-answer-preview i,
        .paragraph-preview i {
            font-size: 24px;
            display: block;
            margin-bottom: 8px;
        }

        .question-footer {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #dadce0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .delete-question-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            background: transparent;
            color: #d93025;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .delete-question-btn:hover {
            background: #fce8e6;
            border-color: #d93025;
        }

        .required-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: #5f6368;
        }

        .toggle-switch {
            width: 44px;
            height: 24px;
            background: #dadce0;
            border-radius: 12px;
            position: relative;
            cursor: pointer;
            transition: background 0.2s;
        }

        .toggle-switch.active {
            background: #1a73e8;
        }

        .toggle-slider {
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            position: absolute;
            top: 2px;
            left: 2px;
            transition: transform 0.2s;
        }

        .toggle-switch.active .toggle-slider {
            transform: translateX(20px);
        }

        .add-question-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: 2px dashed #dadce0;
            border-radius: 8px;
            background: transparent;
            color: #1a73e8;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
            justify-content: center;
            margin-bottom: 24px;
        }

        .add-question-btn:hover {
            background: #f8f9fa;
            border-color: #1a73e8;
        }

        .action-buttons {
            display: flex;
            gap: 16px;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-cancel {
            background: transparent;
            color: #5f6368;
            border: 1px solid #dadce0;
        }

        .btn-cancel:hover {
            background: #f8f9fa;
        }

        .btn-primary {
            background: #1a73e8;
            color: white;
        }

        .btn-primary:hover {
            background: #1765cc;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }

        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="header-left">
                <a href="view_quiz.php?id=<?php echo $quiz_id; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Quiz
                </a>
                <h1><i class="fas fa-edit"></i> Edit Quiz</h1>
            </div>
            <span class="subject-badge" style="background: <?php echo htmlspecialchars($quiz['theme_color']); ?>20; color: <?php echo htmlspecialchars($quiz['theme_color']); ?>;">
                <i class="fas fa-book"></i> <?php echo htmlspecialchars($quiz['subject_name']); ?>
            </span>
        </div>
    </div>

    <div class="container">
        <form id="quizForm" method="POST" action="update_quiz.php">
            <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
            <input type="hidden" name="subject_id" value="<?php echo $quiz['subject_id']; ?>">
            <input type="hidden" name="teacher_id" value="<?php echo $teacher_id; ?>">
            <input type="hidden" name="quiz_type" value="<?php echo htmlspecialchars($quiz['quiz_type']); ?>">
            
            <div class="quiz-header">
                <input type="text" name="quiz_title" id="quizTitle" placeholder="Untitled Quiz" value="<?php echo htmlspecialchars($quiz['title']); ?>" required />
                <textarea name="quiz_description" id="quizDescription" placeholder="Quiz description (optional)"><?php echo htmlspecialchars($quiz['description'] ?? ''); ?></textarea>
                <div class="type-badge">
                    <i class="fas fa-info-circle"></i>
                    <span><?php echo ucwords(str_replace('-', ' ', $quiz['quiz_type'])); ?></span>
                </div>
            </div>

            <div id="questionsContainer">
                <?php foreach ($questions as $index => $question): ?>
                <div class="question-card" id="question-<?php echo $index + 1; ?>">
                    <input type="hidden" name="questions[<?php echo $index + 1; ?>][id]" value="<?php echo $question['id']; ?>">
                    <div class="question-header">
                        <div class="question-number"><?php echo $index + 1; ?></div>
                        <div class="question-input">
                            <input type="text" name="questions[<?php echo $index + 1; ?>][text]" value="<?php echo htmlspecialchars($question['question_text']); ?>" placeholder="Enter your question here..." required />
                            <input type="hidden" name="questions[<?php echo $index + 1; ?>][number]" value="<?php echo $index + 1; ?>" />
                        </div>
                    </div>
                    <div class="options-container" id="question-<?php echo $index + 1; ?>-options">
                        <?php
                        $quiz_type = $quiz['quiz_type'];
                        
                        if ($quiz_type === 'multiple-choice'):
                            foreach ($question['options'] as $opt_index => $option):
                        ?>
                            <div class="option-item">
                                <input type="radio" name="questions[<?php echo $index + 1; ?>][correct]" value="<?php echo $opt_index; ?>" <?php echo $option['is_correct'] ? 'checked' : ''; ?> />
                                <input type="text" name="questions[<?php echo $index + 1; ?>][options][<?php echo $opt_index; ?>]" value="<?php echo htmlspecialchars($option['option_text']); ?>" placeholder="Option <?php echo $opt_index + 1; ?>" required />
                                <input type="hidden" name="questions[<?php echo $index + 1; ?>][option_ids][<?php echo $opt_index; ?>]" value="<?php echo $option['id']; ?>">
                                <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                            </div>
                        <?php
                            endforeach;
                        ?>
                            <button type="button" class="add-option-btn" onclick="addOption('question-<?php echo $index + 1; ?>', 'radio', <?php echo $index + 1; ?>)">
                                <i class="fas fa-plus"></i> Add option
                            </button>
                        <?php
                        elseif ($quiz_type === 'checkboxes'):
                            foreach ($question['options'] as $opt_index => $option):
                        ?>
                            <div class="option-item">
                                <input type="checkbox" name="questions[<?php echo $index + 1; ?>][correct][]" value="<?php echo $opt_index; ?>" <?php echo $option['is_correct'] ? 'checked' : ''; ?> />
                                <input type="text" name="questions[<?php echo $index + 1; ?>][options][<?php echo $opt_index; ?>]" value="<?php echo htmlspecialchars($option['option_text']); ?>" placeholder="Option <?php echo $opt_index + 1; ?>" required />
                                <input type="hidden" name="questions[<?php echo $index + 1; ?>][option_ids][<?php echo $opt_index; ?>]" value="<?php echo $option['id']; ?>">
                                <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                            </div>
                        <?php
                            endforeach;
                        ?>
                            <button type="button" class="add-option-btn" onclick="addOption('question-<?php echo $index + 1; ?>', 'checkbox', <?php echo $index + 1; ?>)">
                                <i class="fas fa-plus"></i> Add option
                            </button>
                        <?php
                        elseif ($quiz_type === 'true-false'):
                            $correct_answer = !empty($question['options']) ? $question['options'][0]['option_text'] : '';
                        ?>
                            <div class="true-false-options">
                                <div class="tf-option <?php echo $correct_answer === 'true' ? 'selected' : ''; ?>" onclick="selectTrueFalse(this, <?php echo $index + 1; ?>)">
                                    <input type="radio" name="questions[<?php echo $index + 1; ?>][correct]" value="true" <?php echo $correct_answer === 'true' ? 'checked' : ''; ?> />
                                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                    <label>True</label>
                                </div>
                                <div class="tf-option <?php echo $correct_answer === 'false' ? 'selected' : ''; ?>" onclick="selectTrueFalse(this, <?php echo $index + 1; ?>)">
                                    <input type="radio" name="questions[<?php echo $index + 1; ?>][correct]" value="false" <?php echo $correct_answer === 'false' ? 'checked' : ''; ?> />
                                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                                    <label>False</label>
                                </div>
                            </div>
                        <?php
                        elseif ($quiz_type === 'modified-true-false'):
                            $correct_answer = !empty($question['options']) ? $question['options'][0]['option_text'] : '';
                        ?>
                            <div class="true-false-options">
                                <div class="tf-option <?php echo $correct_answer === 'true' ? 'selected' : ''; ?>" onclick="selectTrueFalse(this, <?php echo $index + 1; ?>)">
                                    <input type="radio" name="questions[<?php echo $index + 1; ?>][correct]" value="true" <?php echo $correct_answer === 'true' ? 'checked' : ''; ?> />
                                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                    <label>True</label>
                                </div>
                                <div class="tf-option <?php echo $correct_answer === 'false' ? 'selected' : ''; ?>" onclick="selectTrueFalse(this, <?php echo $index + 1; ?>)">
                                    <input type="radio" name="questions[<?php echo $index + 1; ?>][correct]" value="false" <?php echo $correct_answer === 'false' ? 'checked' : ''; ?> />
                                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                                    <label>False</label>
                                </div>
                            </div>
                            <div class="explanation-field">
                                <label><i class="fas fa-lightbulb"></i> If false, provide the correct statement:</label>
                                <textarea name="questions[<?php echo $index + 1; ?>][correction]" placeholder="Enter the correct statement if the answer is false..."><?php echo htmlspecialchars($question['correction_text'] ?? ''); ?></textarea>
                            </div>
                        <?php
                        elseif ($quiz_type === 'short-answer'):
                        ?>
                            <div class="short-answer-preview">
                                <i class="fas fa-keyboard"></i> Students will type a short text answer
                            </div>
                        <?php
                        elseif ($quiz_type === 'paragraph'):
                        ?>
                            <div class="paragraph-preview">
                                <i class="fas fa-file-alt"></i> Students will type a long text answer
                            </div>
                        <?php
                        endif;
                        ?>
                    </div>
                    <div class="question-footer">
                        <button type="button" class="delete-question-btn" onclick="deleteQuestion('question-<?php echo $index + 1; ?>')">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                        <div class="required-toggle">
                            <span>Required</span>
                            <input type="hidden" name="questions[<?php echo $index + 1; ?>][required]" value="<?php echo $question['is_required'] ? '1' : '0'; ?>" class="required-input" />
                            <div class="toggle-switch <?php echo $question['is_required'] ? 'active' : ''; ?>" onclick="toggleRequired(this, <?php echo $index + 1; ?>)">
                                <div class="toggle-slider"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="add-question-btn" onclick="addQuestion()">
                <i class="fas fa-plus-circle"></i>
                <span>Add Question</span>
            </button>

            <div class="action-buttons">
                <a href="view_quiz.php?id=<?php echo $quiz_id; ?>" class="btn btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>

    <script>
        let questionCount = <?php echo count($questions); ?>;
        const quizType = '<?php echo $quiz['quiz_type']; ?>';

        function addQuestion() {
            questionCount++;
            const questionId = `question-${questionCount}`;
            
            const questionCard = document.createElement('div');
            questionCard.className = 'question-card';
            questionCard.id = questionId;
            questionCard.innerHTML = `
                <div class="question-header">
                    <div class="question-number">${questionCount}</div>
                    <div class="question-input">
                        <input type="text" name="questions[${questionCount}][text]" placeholder="Enter your question here..." required />
                        <input type="hidden" name="questions[${questionCount}][number]" value="${questionCount}" />
                    </div>
                </div>
                <div class="options-container" id="${questionId}-options">
                    ${renderOptions(quizType, questionId, questionCount)}
                </div>
                <div class="question-footer">
                    <button type="button" class="delete-question-btn" onclick="deleteQuestion('${questionId}')">
                        <i class="fas fa-trash-alt"></i> Delete
                    </button>
                    <div class="required-toggle">
                        <span>Required</span>
                        <input type="hidden" name="questions[${questionCount}][required]" value="1" class="required-input" />
                        <div class="toggle-switch active" onclick="toggleRequired(this, ${questionCount})">
                            <div class="toggle-slider"></div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('questionsContainer').appendChild(questionCard);
        }

        function renderOptions(type, questionId, questionNum) {
            switch(type) {
                case 'multiple-choice':
                    return `
                        <div class="option-item">
                            <input type="radio" name="questions[${questionNum}][correct]" value="0" />
                            <input type="text" name="questions[${questionNum}][options][0]" placeholder="Option 1" required />
                            <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="option-item">
                            <input type="radio" name="questions[${questionNum}][correct]" value="1" />
                            <input type="text" name="questions[${questionNum}][options][1]" placeholder="Option 2" required />
                            <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                        </div>
                        <button type="button" class="add-option-btn" onclick="addOption('${questionId}', 'radio', ${questionNum})">
                            <i class="fas fa-plus"></i> Add option
                        </button>
                    `;
                
                case 'checkboxes':
                    return `
                        <div class="option-item">
                            <input type="checkbox" name="questions[${questionNum}][correct][]" value="0" />
                            <input type="text" name="questions[${questionNum}][options][0]" placeholder="Option 1" required />
                            <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="option-item">
                            <input type="checkbox" name="questions[${questionNum}][correct][]" value="1" />
                            <input type="text" name="questions[${questionNum}][options][1]" placeholder="Option 2" required />
                            <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                        </div>
                        <button type="button" class="add-option-btn" onclick="addOption('${questionId}', 'checkbox', ${questionNum})">
                            <i class="fas fa-plus"></i> Add option
                        </button>
                    `;
                
                case 'true-false':
                    return `
                        <div class="true-false-options">
                            <div class="tf-option" onclick="selectTrueFalse(this, ${questionNum})">
                                <input type="radio" name="questions[${questionNum}][correct]" value="true" />
                                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                <label>True</label>
                            </div>
                            <div class="tf-option" onclick="selectTrueFalse(this, ${questionNum})">
                                <input type="radio" name="questions[${questionNum}][correct]" value="false" />
                                <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                                <label>False</label>
                            </div>
                        </div>
                    `;
                
                case 'modified-true-false':
                    return `
                        <div class="true-false-options">
                            <div class="tf-option" onclick="selectTrueFalse(this, ${questionNum})">
                                <input type="radio" name="questions[${questionNum}][correct]" value="true" />
                                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                <label>True</label>
                            </div>
                            <div class="tf-option" onclick="selectTrueFalse(this, ${questionNum})">
                                <input type="radio" name="questions[${questionNum}][correct]" value="false" />
                                <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                                <label>False</label>
                            </div>
                        </div>
                        <div class="explanation-field">
                            <label><i class="fas fa-lightbulb"></i> If false, provide the correct statement:</label>
                            <textarea name="questions[${questionNum}][correction]" placeholder="Enter the correct statement if the answer is false..."></textarea>
                        </div>
                    `;
                
                case 'short-answer':
                    return `
                        <div class="short-answer-preview">
                            <i class="fas fa-keyboard"></i> Students will type a short text answer
                        </div>
                    `;
                
                case 'paragraph':
                    return `
                        <div class="paragraph-preview">
                            <i class="fas fa-file-alt"></i> Students will type a long text answer
                        </div>
                    `;
                
                default:
                    return '';
            }
        }

        function addOption(questionId, inputType, questionNum) {
            const optionsContainer = document.getElementById(`${questionId}-options`);
            const addButton = optionsContainer.querySelector('.add-option-btn');
            const optionCount = optionsContainer.querySelectorAll('.option-item').length;
            
            const newOption = document.createElement('div');
            newOption.className = 'option-item';
            
            if (inputType === 'radio') {
                newOption.innerHTML = `
                    <input type="radio" name="questions[${questionNum}][correct]" value="${optionCount}" />
                    <input type="text" name="questions[${questionNum}][options][${optionCount}]" placeholder="Option ${optionCount + 1}" required />
                    <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                `;
            } else {
                newOption.innerHTML = `
                    <input type="checkbox" name="questions[${questionNum}][correct][]" value="${optionCount}" />
                    <input type="text" name="questions[${questionNum}][options][${optionCount}]" placeholder="Option ${optionCount + 1}" required />
                    <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="fas fa-times"></i></button>
                `;
            }
            
            optionsContainer.insertBefore(newOption, addButton);
        }

        function deleteOption(button) {
            const optionItem = button.closest('.option-item');
            const container = optionItem.parentElement;
            const options = container.querySelectorAll('.option-item');
            
            if (options.length > 2) {
                optionItem.remove();
            } else {
                alert('A question must have at least 2 options');
            }
        }

        function selectTrueFalse(element, questionNum) {
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;
            
            const siblings = element.parentElement.querySelectorAll('.tf-option');
            siblings.forEach(sib => sib.classList.remove('selected'));
            element.classList.add('selected');
        }

        function toggleRequired(toggle, questionNum) {
            toggle.classList.toggle('active');
            const card = toggle.closest('.question-card');
            const hiddenInput = card.querySelector('.required-input');
            hiddenInput.value = toggle.classList.contains('active') ? '1' : '0';
        }

        function deleteQuestion(questionId) {
            if (confirm('Are you sure you want to delete this question?')) {
                document.getElementById(questionId).remove();
                updateQuestionNumbers();
            }
        }

        function updateQuestionNumbers() {
            const cards = document.querySelectorAll('.question-card');
            cards.forEach((card, index) => {
                const numberDiv = card.querySelector('.question-number');
                if (numberDiv) {
                    numberDiv.textContent = index + 1;
                }
            });
            questionCount = cards.length;
        }

        document.getElementById('quizForm').addEventListener('submit', function(e) {
            const questions = document.querySelectorAll('.question-card');
            if (questions.length === 0) {
                e.preventDefault();
                alert('Please add at least one question to the quiz.');
                return false;
            }
            
            let allValid = true;
            questions.forEach(q => {
                const questionText = q.querySelector('.question-input input[type="text"]');
                if (!questionText.value.trim()) {
                    allValid = false;
                }
            });
            
            if (!allValid) {
                e.preventDefault();
                alert('Please fill in all question texts.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>