<?php
session_start();
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

$conn = new mysqli("localhost", "u304756553_ashi_classroom", "Ashi_Classroom17", "u304756553_ashi_classroom");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$student_id = (int)$_SESSION['student_id'];
$quiz_id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$quiz_id) { header('Location: student_dashboard.php'); exit; }

// Fetch quiz + subject + teacher
$stmt = $conn->prepare("
    SELECT q.*, s.subject_name, s.theme_color, t.fullname AS teacher_name
    FROM quizzes q
    INNER JOIN subjects  s ON q.subject_id = s.id
    INNER JOIN teachers  t ON q.teacher_id = t.id
    WHERE q.id = ?
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz || $quiz['status'] !== 'published') {
    header('Location: student_dashboard.php'); exit;
}

// Enrollment check
$stmt = $conn->prepare("
    SELECT COUNT(*) AS enrolled FROM student_subjects
    WHERE student_id = ? AND subject_id = ? AND status = 'active'
");
$stmt->bind_param("ii", $student_id, $quiz['subject_id']);
$stmt->execute();
$enrolled = (int)$stmt->get_result()->fetch_assoc()['enrolled'];
$stmt->close();

if (!$enrolled) { header('Location: student_dashboard.php'); exit; }

// ── UPDATED: Count ALL attempts regardless of status ──────────────────────
// Opening the quiz = 1 attempt consumed. No resume, no retry.
$stmt = $conn->prepare("
    SELECT COUNT(*) AS attempts_used, MAX(percentage) AS best_score
    FROM quiz_attempts
    WHERE quiz_id = ? AND student_id = ?
");
$stmt->bind_param("ii", $quiz_id, $student_id);
$stmt->execute();
$attempt_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$attempts_used  = (int)$attempt_data['attempts_used'];
$best_score     = $attempt_data['best_score'];

// ── UPDATED: Locked out if ANY attempt exists ─────────────────────────────
$quiz_completed = $attempts_used > 0;

// Question count
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM quiz_questions WHERE quiz_id = ?");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$question_count = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Questions
$stmt = $conn->prepare("
    SELECT id, question_text, question_type, points, question_order
    FROM quiz_questions WHERE quiz_id = ?
    ORDER BY question_order ASC
");
$stmt->bind_param("i", $quiz_id);
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Options per question
foreach ($questions as &$question) {
    if (in_array($question['question_type'], ['multiple-choice', 'true-false', 'modified-true-false'])) {
        $stmt = $conn->prepare("
            SELECT id, option_text, option_order
            FROM quiz_question_options WHERE question_id = ?
            ORDER BY option_order ASC
        ");
        $stmt->bind_param("i", $question['id']);
        $stmt->execute();
        $question['options'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
unset($question);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/ashi.jpg" type="image/x-icon">
    <title><?php echo htmlspecialchars($quiz['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="take_quiz.css">
</head>
<body>

<?php if ($quiz_completed): ?>
<!-- ── Quiz Already Completed ─────────────────────────────────────────── -->
<div class="quiz-container">
    <div class="quiz-header" style="background-color:<?php echo htmlspecialchars($quiz['theme_color']); ?>;">
        <div class="header-top">
            <h1 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h1>
            <p class="quiz-subtitle">
                <?php echo htmlspecialchars($quiz['subject_name']); ?> &bull;
                <?php echo htmlspecialchars($quiz['teacher_name']); ?>
            </p>
        </div>
    </div>
    <div class="quiz-body">
        <div class="completed-message">
            <div class="completed-icon"><i class="fas fa-check-circle"></i></div>
            <h2>Quiz Already Completed</h2>
            <p>You have used all <?php echo (int)$quiz['max_attempts']; ?> attempt(s) for this quiz.</p>
            <?php if ($best_score !== null): ?>
            <div class="best-score-display">
                <div class="score-label">Your Best Score</div>
                <div class="score-percentage"><?php echo round($best_score, 1); ?>%</div>
            </div>
            <?php endif; ?>
            <a href="../student_dashb.php" class="btn-return">
                <i class="fas fa-arrow-left"></i> Return to Dashboard
            </a>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── Quiz Form ───────────────────────────────────────────────────────── -->
<div class="quiz-container">

    <!-- Header -->
    <div class="quiz-header" style="background-color:<?php echo htmlspecialchars($quiz['theme_color']); ?>;">
        <div class="header-top">
            <h1 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h1>
            <p class="quiz-subtitle">
                <?php echo htmlspecialchars($quiz['subject_name']); ?> &bull;
                <?php echo htmlspecialchars($quiz['teacher_name']); ?>
            </p>
        </div>
        <?php if ($quiz['time_limit']): ?>
        <div class="timer-container" id="timerContainer" style="display:none;">
            <div class="timer-content">
                <i class="fas fa-clock"></i>
                <span id="timerDisplay"><?php echo (int)$quiz['time_limit']; ?>:00</span>
                <span class="timer-label">remaining</span>
            </div>
            <div class="timer-bar">
                <div class="timer-progress" id="timerProgress"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Instructions -->
    <div class="quiz-body" id="quizInstructions">
        <div class="instructions-card">
            <?php if ($quiz['description']): ?>
            <div class="description-section">
                <p class="description-text"><?php echo nl2br(htmlspecialchars($quiz['description'])); ?></p>
            </div>
            <div class="section-divider"></div>
            <?php endif; ?>

            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-list-ol"></i></div>
                    <div class="info-content">
                        <div class="info-label">Total Questions</div>
                        <div class="info-value"><?php echo $question_count; ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-star"></i></div>
                    <div class="info-content">
                        <div class="info-label">Total Points</div>
                        <div class="info-value"><?php echo $quiz['total_points']; ?></div>
                    </div>
                </div>
                <?php if ($quiz['time_limit']): ?>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-clock"></i></div>
                    <div class="info-content">
                        <div class="info-label">Time Limit</div>
                        <div class="info-value"><?php echo (int)$quiz['time_limit']; ?> min</div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-redo"></i></div>
                    <div class="info-content">
                        <div class="info-label">Attempts Remaining</div>
                        <div class="info-value">
                            <?php echo ($quiz['max_attempts'] - $attempts_used); ?> / <?php echo (int)$quiz['max_attempts']; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($best_score !== null): ?>
            <div class="previous-score">
                <i class="fas fa-chart-line"></i>
                <span>Previous best: <strong><?php echo round($best_score, 1); ?>%</strong></span>
            </div>
            <?php endif; ?>

            <div class="section-divider"></div>
            <div class="instructions-list">
                <h3>Instructions</h3>
                <ul>
                    <li><i class="fas fa-check-circle"></i> Answer all questions carefully</li>
                    <li><i class="fas fa-check-circle"></i> You can navigate between questions freely</li>
                    <?php if ($quiz['time_limit']): ?>
                    <li><i class="fas fa-check-circle"></i> The quiz will auto-submit when time expires</li>
                    <?php endif; ?>
                    <li><i class="fas fa-check-circle"></i> Click "Submit Quiz" when you're done</li>
                    <li><i class="fas fa-exclamation-triangle"></i> You cannot change answers after submission</li>
                </ul>
            </div>

            <button type="button" class="btn-start" onclick="startQuiz()">
                <i class="fas fa-play-circle"></i> Start Quiz
            </button>
            <a href="../student_dashb.php" class="btn-back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Quiz Questions (hidden until Start clicked) -->
    <div class="quiz-body" id="quizForm" style="display:none;">
        <form id="quizAnswerForm">
            <input type="hidden" name="quiz_id"    value="<?php echo $quiz_id; ?>">
            <input type="hidden" name="attempt_id" id="attemptId" value="">

            <?php foreach ($questions as $index => $question):
                $qid   = (int)$question['id'];
                $qtype = $question['question_type'];
            ?>
            <div class="question-card" data-question-id="<?php echo $qid; ?>">
                <div class="question-header">
                    <span class="question-number">Question <?php echo $index + 1; ?></span>
                    <span class="question-points">
                        <i class="fas fa-award"></i>
                        <?php echo $question['points']; ?> point<?php echo $question['points'] != 1 ? 's' : ''; ?>
                    </span>
                </div>

                <div class="question-text">
                    <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                </div>

                <?php if ($qtype === 'multiple-choice' || $qtype === 'true-false'): ?>
                <div class="options-container">
                    <?php foreach ($question['options'] as $option): ?>
                    <label class="option-item">
                        <input type="radio"
                               name="question_<?php echo $qid; ?>"
                               value="<?php echo (int)$option['id']; ?>">
                        <span class="option-circle"></span>
                        <span class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <?php elseif ($qtype === 'modified-true-false'): ?>
                <div class="options-container">
                    <?php foreach ($question['options'] as $option): ?>
                    <label class="option-item">
                        <input type="radio"
                               name="question_<?php echo $qid; ?>_tf"
                               value="<?php echo (int)$option['id']; ?>">
                        <span class="option-circle"></span>
                        <span class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <!-- Hidden by default; shown via JS only when False is selected -->
                <div class="text-answer-container" id="correction_<?php echo $qid; ?>" style="display:none;">
                    <label class="text-answer-label">
                        If False, write the correct answer:
                        <span class="uppercase-notice">
                            <i class="fas fa-font"></i> UPPERCASE LETTERS ONLY
                        </span>
                    </label>
                    <input type="text"
                           name="question_<?php echo $qid; ?>_text"
                           class="text-input"
                           placeholder="ENTER THE CORRECT ANSWER HERE..."
                           style="text-transform:uppercase;"
                           oninput="this.value=this.value.toUpperCase()">
                </div>

                <?php elseif ($qtype === 'short-answer'): ?>
                <div class="text-answer-container">
                    <textarea name="question_<?php echo $qid; ?>"
                              class="text-area"
                              rows="4"
                              placeholder="Type your answer here..."
                              required></textarea>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="submit-section">
                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> Submit Quiz
                </button>
                <p class="submit-note">
                    <i class="fas fa-info-circle"></i>
                    Make sure you've answered all questions before submitting
                </p>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const quizData = {
    quizId:         <?php echo $quiz_id; ?>,
    timeLimit:      <?php echo $quiz['time_limit'] ? (int)$quiz['time_limit'] : 'null'; ?>,
    totalQuestions: <?php echo count($questions); ?>,
    themeColor:     '<?php echo addslashes($quiz['theme_color']); ?>'
};
</script>
<script src="take_quiz.js"></script>
</body>
</html>