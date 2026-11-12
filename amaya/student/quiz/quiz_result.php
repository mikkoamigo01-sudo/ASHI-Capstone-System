<?php
session_start();

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

// Database connection
$servername = "localhost";
$username = "u304756553_ashi_classroom";
$password = "Ashi_Classroom17";
$dbname = "u304756553_ashi_classroom";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$student_id = $_SESSION['student_id'];
$attempt_id = isset($_GET['attempt']) ? intval($_GET['attempt']) : 0;

if (!$attempt_id) {
    header('Location: student_dashboard.php');
    exit;
}

// Fetch attempt details with quiz info
$attempt_query = "
    SELECT 
        qa.*,
        q.title as quiz_title,
        q.show_results,
        q.total_points as max_points,
        s.subject_name,
        t.fullname as teacher_name
    FROM quiz_attempts qa
    INNER JOIN quizzes q ON qa.quiz_id = q.id
    INNER JOIN subjects s ON q.subject_id = s.id
    INNER JOIN teachers t ON q.teacher_id = t.id
    WHERE qa.id = ? AND qa.student_id = ?
";

$stmt = $conn->prepare($attempt_query);
$stmt->bind_param("ii", $attempt_id, $student_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();

if (!$attempt) {
    header('Location: student_dashboard.php');
    exit;
}

// Calculate grade letter
function getGradeLetter($percentage) {
    if ($percentage >= 97) return 'A+';
    if ($percentage >= 93) return 'A';
    if ($percentage >= 90) return 'A-';
    if ($percentage >= 87) return 'B+';
    if ($percentage >= 83) return 'B';
    if ($percentage >= 80) return 'B-';
    if ($percentage >= 77) return 'C+';
    if ($percentage >= 73) return 'C';
    if ($percentage >= 70) return 'C-';
    if ($percentage >= 67) return 'D+';
    if ($percentage >= 63) return 'D';
    if ($percentage >= 60) return 'D-';
    return 'F';
}

// Calculate performance message
function getPerformanceMessage($percentage) {
    if ($percentage >= 90) return ['Excellent Work!', 'Outstanding performance!', 'success'];
    if ($percentage >= 80) return ['Great Job!', 'You did very well!', 'success'];
    if ($percentage >= 70) return ['Good Effort!', 'Solid performance!', 'good'];
    if ($percentage >= 60) return ['Keep Trying!', 'You can do better!', 'average'];
    return ['Need Improvement', 'Keep studying and try again!', 'low'];
}

$percentage = round($attempt['percentage'], 2);
$grade_letter = getGradeLetter($percentage);
$performance = getPerformanceMessage($percentage);

// Format time taken
$time_taken_formatted = 'N/A';
if ($attempt['time_taken']) {
    $minutes = floor($attempt['time_taken'] / 60);
    $seconds = $attempt['time_taken'] % 60;
    $time_taken_formatted = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
}

// Get submission date
$submitted_date = date('F j, Y', strtotime($attempt['submitted_at']));
$submitted_time = date('g:i A', strtotime($attempt['submitted_at']));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Results - <?php echo htmlspecialchars($attempt['quiz_title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="quiz_result.css">
</head>
<body>
    <div class="results-container">
        <!-- Background Decoration -->
        <div class="bg-decoration"></div>
        
        <!-- Results Card -->
        <div class="results-card">
            <!-- Header -->
            <div class="results-header">
                <div class="icon-wrapper <?php echo $performance[2]; ?>">
                    <?php if ($percentage >= 60): ?>
                        <i class="fas fa-trophy"></i>
                    <?php else: ?>
                        <i class="fas fa-chart-line"></i>
                    <?php endif; ?>
                </div>
                <h1 class="results-title"><?php echo $performance[0]; ?></h1>
                <p class="results-subtitle"><?php echo $performance[1]; ?></p>
            </div>

            <!-- Main Score Display -->
            <div class="score-display">
                <div class="score-circle <?php echo $performance[2]; ?>">
                    <svg class="progress-ring" width="200" height="200">
                        <circle class="progress-ring-bg" cx="100" cy="100" r="90" />
                        <circle class="progress-ring-fill" cx="100" cy="100" r="90" 
                                style="stroke-dashoffset: <?php echo 565.48 - (565.48 * $percentage / 100); ?>" />
                    </svg>
                    <div class="score-content">
                        <div class="percentage"><?php echo $percentage; ?>%</div>
                        <div class="grade-letter"><?php echo $grade_letter; ?></div>
                    </div>
                </div>
            </div>

            <!-- Score Breakdown -->
            <div class="score-breakdown">
                <div class="breakdown-item">
                    <div class="breakdown-label">Your Score</div>
                    <div class="breakdown-value"><?php echo $attempt['score']; ?> / <?php echo $attempt['max_score']; ?></div>
                </div>
                <div class="breakdown-divider"></div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Percentage</div>
                    <div class="breakdown-value"><?php echo $percentage; ?>%</div>
                </div>
            </div>

            <!-- Quiz Information -->
            <div class="quiz-info">
                <h3 class="info-title"><?php echo htmlspecialchars($attempt['quiz_title']); ?></h3>
                <div class="info-details">
                    <div class="info-item">
                        <i class="fas fa-book"></i>
                        <span><?php echo htmlspecialchars($attempt['subject_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-user"></i>
                        <span><?php echo htmlspecialchars($attempt['teacher_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar"></i>
                        <span><?php echo $submitted_date; ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-clock"></i>
                        <span><?php echo $submitted_time; ?></span>
                    </div>
                    <?php if ($attempt['time_taken']): ?>
                    <div class="info-item">
                        <i class="fas fa-hourglass-half"></i>
                        <span>Time Taken: <?php echo $time_taken_formatted; ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <i class="fas fa-redo"></i>
                        <span>Attempt #<?php echo $attempt['attempt_number']; ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="../student_dashb.php" class="btn-primary">
                    <i class="fas fa-home"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Footer Note -->
        <div class="footer-note">
            <?php if ($attempt['status'] === 'submitted'): ?>
                <i class="fas fa-info-circle"></i>
                Your quiz is being reviewed. Final scores may change after manual grading.
            <?php else: ?>
                <i class="fas fa-check-circle"></i>
                This is your final score for this attempt.
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
