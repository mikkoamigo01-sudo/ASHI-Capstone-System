<?php
session_start();

// Check if teacher is logged in
if (!isset($_SESSION['teacher_id'])) {
    header('Location: ../login.php');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

// Include database connection
require_once '../config/database.php';

// Get teacher information
$stmt = $conn->prepare("SELECT fullname, email FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher_result = $stmt->get_result();
$teacher_info = $teacher_result->fetch_assoc();
$stmt->close();

// Get all quizzes for this teacher with subject info
$stmt = $conn->prepare("
    SELECT 
        q.*,
        s.subject_name,
        s.section,
        s.grade,
        s.class_code,
        COUNT(DISTINCT qa.student_id) as total_attempts,
        COUNT(DISTINCT CASE WHEN qa.status = 'submitted' THEN qa.student_id END) as completed_attempts
    FROM quizzes q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN quiz_attempts qa ON q.id = qa.quiz_id
    WHERE q.teacher_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$quizzes = [];
while ($row = $result->fetch_assoc()) {
    $quizzes[] = $row;
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../teacher/img/ashi.jpg" type="image/x-icon">
    <title>My Quizzes - Teacher Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #333;
            font-size: 32px;
        }

        .header p {
            color: #666;
            margin-top: 5px;
        }

        .create-btn {
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .create-btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .number {
            font-size: 36px;
            font-weight: 700;
            color: #667eea;
        }

        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .filters select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }

        .quiz-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .quiz-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
        }

        .quiz-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .quiz-status {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .status-closed {
            background: #f8d7da;
            color: #721c24;
        }

        .quiz-card h3 {
            font-size: 20px;
            color: #333;
            margin-bottom: 10px;
            padding-right: 100px;
        }

        .quiz-subject {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            margin-bottom: 15px;
        }

        .quiz-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .quiz-meta {
            display: flex;
            gap: 20px;
            margin: 15px 0;
            padding: 15px 0;
            border-top: 1px solid #e0e0e0;
            border-bottom: 1px solid #e0e0e0;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .meta-item label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-item span {
            font-size: 14px;
            color: #333;
            font-weight: 500;
        }

        .quiz-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-edit {
            background: #667eea;
            color: white;
        }

        .btn-edit:hover {
            background: #5568d3;
        }

        .btn-view {
            background: #f8f9fa;
            color: #333;
            border: 1px solid #e0e0e0;
        }

        .btn-view:hover {
            background: #e9ecef;
        }

        .btn-delete {
            background: #f44336;
            color: white;
            margin-left: auto;
        }

        .btn-delete:hover {
            background: #d32f2f;
        }

        .empty-state {
            background: white;
            padding: 60px 30px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .empty-state h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>My Quizzes</h1>
                <p>Welcome back, <?php echo htmlspecialchars($teacher_info['fullname']); ?></p>
            </div>
            <a href="quiz_creator.php" class="create-btn">+ Create New Quiz</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Quizzes</h3>
                <div class="number"><?php echo count($quizzes); ?></div>
            </div>
            <div class="stat-card">
                <h3>Published</h3>
                <div class="number">
                    <?php echo count(array_filter($quizzes, function($q) { return $q['status'] === 'published'; })); ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Drafts</h3>
                <div class="number">
                    <?php echo count(array_filter($quizzes, function($q) { return $q['status'] === 'draft'; })); ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Total Attempts</h3>
                <div class="number">
                    <?php echo array_sum(array_column($quizzes, 'total_attempts')); ?>
                </div>
            </div>
        </div>

        <div class="filters">
            <label>Filter by status:</label>
            <select id="statusFilter" onchange="filterQuizzes()">
                <option value="all">All Quizzes</option>
                <option value="draft">Drafts</option>
                <option value="published">Published</option>
                <option value="closed">Closed</option>
            </select>
        </div>

        <?php if (empty($quizzes)): ?>
            <div class="empty-state">
                <h2>No Quizzes Yet</h2>
                <p>Create your first quiz to get started with assessments</p>
                <a href="quiz_creator.php" class="create-btn">Create Your First Quiz</a>
            </div>
        <?php else: ?>
            <div class="quiz-grid" id="quizGrid">
                <?php foreach ($quizzes as $quiz): ?>
                    <div class="quiz-card" data-status="<?php echo $quiz['status']; ?>">
                        <span class="quiz-status status-<?php echo $quiz['status']; ?>">
                            <?php echo ucfirst($quiz['status']); ?>
                        </span>
                        
                        <h3><?php echo htmlspecialchars($quiz['title']); ?></h3>
                        
                        <span class="quiz-subject">
                            <?php echo htmlspecialchars($quiz['subject_name']); ?> - 
                            <?php echo htmlspecialchars($quiz['section']); ?>
                        </span>
                        
                        <?php if (!empty($quiz['description'])): ?>
                            <p class="quiz-description">
                                <?php echo htmlspecialchars(substr($quiz['description'], 0, 150)); ?>
                                <?php echo strlen($quiz['description']) > 150 ? '...' : ''; ?>
                            </p>
                        <?php endif; ?>
                        
                        <div class="quiz-meta">
                            <div class="meta-item">
                                <label>Total Points</label>
                                <span><?php echo $quiz['total_points']; ?></span>
                            </div>
                            <div class="meta-item">
                                <label>Attempts</label>
                                <span><?php echo $quiz['total_attempts']; ?></span>
                            </div>
                            <div class="meta-item">
                                <label>Passing Score</label>
                                <span><?php echo $quiz['passing_score']; ?>%</span>
                            </div>
                            <?php if ($quiz['due_date']): ?>
                                <div class="meta-item">
                                    <label>Due Date</label>
                                    <span><?php echo date('M j, Y', strtotime($quiz['due_date'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="quiz-actions">
                            <a href="quiz_creator.php?quiz_id=<?php echo $quiz['id']; ?>" class="btn btn-edit">
                                Edit
                            </a>
                            <a href="quiz_results.php?quiz_id=<?php echo $quiz['id']; ?>" class="btn btn-view">
                                View Results
                            </a>
                            <button class="btn btn-delete" onclick="deleteQuiz(<?php echo $quiz['id']; ?>, '<?php echo htmlspecialchars($quiz['title']); ?>')">
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterQuizzes() {
            const filter = document.getElementById('statusFilter').value;
            const cards = document.querySelectorAll('.quiz-card');
            
            cards.forEach(card => {
                if (filter === 'all' || card.dataset.status === filter) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function deleteQuiz(quizId, quizTitle) {
            if (confirm(`Are you sure you want to delete "${quizTitle}"? This action cannot be undone.`)) {
                fetch('../api/delete_quiz.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ quiz_id: quizId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Quiz deleted successfully');
                        location.reload();
                    } else {
                        alert('Error deleting quiz: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting quiz. Please try again.');
                });
            }
        }
    </script>
</body>
</html>
