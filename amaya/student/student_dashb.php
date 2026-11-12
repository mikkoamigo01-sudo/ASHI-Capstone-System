<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['student_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

// Fetch student information
$student_id = $_SESSION['student_id'];
$query = "SELECT * FROM students WHERE id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Query preparation failed: " . $conn->error);
}
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

$student = $result->fetch_assoc();

// Get student's full name
$full_name = $student['first_name'] . ' ' . $student['last_name'];

// Get initials for avatar
$first_initial = !empty($student['first_name']) ? strtoupper(substr($student['first_name'], 0, 1)) : 'S';
$last_initial  = !empty($student['last_name'])  ? strtoupper(substr($student['last_name'],  0, 1)) : 'T';
$initials = $first_initial . $last_initial;

// Get profile picture or use default initials
$profile_picture = !empty($student['profile_picture']) ? $student['profile_picture'] : null;

// Format grade and section
$grade_section = $student['grade_level'] . ' - ' . $student['section'];

// Get student email and username
$student_email    = $student['email'];
$student_username = $student['username'];

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title>Student Dashboard - ASHI System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="dash_board.css" rel="stylesheet">
    <link href="my_profile.css" rel="stylesheet">
    <link href="task_asignment.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="stud_sub.css">
    <link rel="stylesheet" type="text/css" href="my_submission.css">
    <link rel="stylesheet" type="text/css" href="my_lectures.css">
    <link rel="stylesheet" type="text/css" href="task_notif.css">
    <link rel="stylesheet" type="text/css" href="stud_dashboard.css">
    <link rel="stylesheet" type="text/css" href="my_quiz.css">
</head>
<body>
    <style>

.messenger-icon-link {
    text-decoration: none;
    color: inherit;
    display: inline-block;
    position: relative;
    cursor: pointer;
    transition: transform 0.2s ease;
}

.messenger-icon-link:hover {
    transform: scale(1.05);
}

.messenger-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    transition: all 0.3s ease;
}

.messenger-icon-link:hover .messenger-icon {
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
    transform: translateY(-2px);
}

.messenger-icon i {
    font-size: 24px;
    color: white;
    animation: pulse 2s ease-in-out infinite;
}

.messenger-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
    color: white;
    font-size: 11px;
    font-weight: 700;
    min-width: 20px;
    height: 20px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    border: 2px solid white;
    box-shadow: 0 2px 8px rgba(238, 90, 111, 0.4);
    animation: bounce 0.5s ease;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

@keyframes bounce {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.2);
    }
}


    </style>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../img/ashi.jpg" alt="ASHI Logo" class="sidebar-logo">
            <div class="sidebar-title">
                <h4>ASHI</h4>
                <p>Student Portal</p>
            </div>
        </div>

        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-chevron-left"></i>
        </button>

        <ul class="sidebar-menu">
            <!--li class="menu-item">
                <a class="menu-link active" data-section="dashboard">
                    <i class="fas fa-home"></i>
                    <span class="menu-text">Dashboard</span>
                </a>
            </li-->

            <div class="menu-section">Academic</div>

            <li class="menu-item">
                <a class="menu-link" data-section="subjects">
                    <i class="fas fa-book"></i>
                    <span class="menu-text">My Subjects</span>
                </a>
            </li>

            <li class="menu-item">
                <a class="menu-link" data-section="tasks">
                    <i class="fas fa-tasks"></i>
                    <span class="menu-text">Tasks</span>
                    <span class="badge-notification">0</span>
                </a>
            </li>

            <li class="menu-item">
                <a href="#" class="menu-link" data-section="quiz">
                    <i class="fas fa-question-circle"></i>
                    <span class="menu-text">Quiz</span>
                    <span class="badge-quiz-notification">0</span>
                </a>
            </li>

            <li class="menu-item">
                <a class="menu-link" data-section="submissions">
                    <i class="fas fa-file-upload"></i>
                    <span class="menu-text">My Submissions</span>
                </a>
            </li>

            <li class="menu-item">
                <a class="menu-link" data-section="lectures">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span class="menu-text">Lectures</span>
                </a>
            </li>

            <li class="menu-item">
                <a class="menu-link" data-section="grades">
                    <i class="fas fa-chart-line"></i>
                    <span class="menu-text">My Grades</span>
                </a>
            </li>

            <div class="menu-section">Personal</div>

            <li class="menu-item">
                <a class="menu-link" data-section="profile">
                    <i class="fas fa-user-circle"></i>
                    <span class="menu-text">My Profile</span>
                </a>
            </li>

            <div class="menu-section">System</div>

            <li class="menu-item">
                <a class="menu-link" data-section="logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="menu-text">Logout</span>
                </a>
            </li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Top Navbar -->
        <nav class="top-navbar">
            <div>
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title" id="pageTitle">Dashboard</h1>
            </div>
            <div class="top-navbar-right">
                <!--div class="notification-icon">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </div-->
                <!--a href="../messages/" class="messenger-icon-link">
                    <div class="messenger-icon">
                        <i class="fa-regular fa-message"></i>
                        <span class="messenger-badge" id="messengerBadge" style="display: none;">0</span>
                    </div>
                </a-->
                <div class="user-profile-top">
                    <?php if ($profile_picture): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="user-avatar-top">
                    <?php else: ?>
                        <div class="user-avatar-top"><?php echo htmlspecialchars($initials); ?></div>
                    <?php endif; ?>
                    <div class="user-info-top">
                        <p class="user-name-top"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="user-role-top"><?php echo htmlspecialchars($grade_section); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Content Area -->
        <div class="content-area">
<!-- Dashboard Section -->
<div class="content-section active" id="dashboard">
    <div class="section-header">
        <h2 class="section-title"></h2>
        <p class="section-description">Welcome back, <?php echo htmlspecialchars($full_name); ?>!</p>
    </div>

    <div class="dashboard-simple">
        <!-- Welcome Message with Image -->
        <div class="dashboard-welcome">
            <div class="welcome-left">
                <h3>Hello, <?php echo htmlspecialchars($full_name); ?>! 👋</h3>
                <p>Ready to learn and grow today?</p>
                <p class="welcome-subtitle">Access your classes, check assignments, and stay connected with your teachers all in one place.</p>
                <div class="student-grade-badge">
                    <i class="fas fa-graduation-cap"></i>
                    <span><?php echo htmlspecialchars($student['grade_level']); ?></span>
                </div>
            </div>
            <div class="welcome-right">
                <img src="../img/ashi.jpg" alt="Welcome" class="welcome-image">
            </div>
        </div>

        <!-- Quick Info Cards with Borders -->
        <div class="dashboard-cards">
            <div class="dashboard-card card-border-blue">
                <div class="card-icon blue">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="card-info">
                    <h4>My Classes</h4>
                    <p>View and access all your enrolled classes</p>
                </div>
            </div>

            <div class="dashboard-card card-border-green">
                <div class="card-icon green">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="card-info">
                    <h4>Assignments</h4>
                    <p>Check and submit your assignments</p>
                </div>
            </div>

            <div class="dashboard-card card-border-purple">
                <div class="card-icon purple">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-info">
                    <h4>My Progress</h4>
                    <p>Track your academic performance</p>
                </div>
            </div>

            <div class="dashboard-card card-border-orange">
                <div class="card-icon orange">
                    <i class="fas fa-comments"></i>
                </div>
                <div class="card-info">
                    <h4>Messages</h4>
                    <p>Communicate with your teachers</p>
                </div>
            </div>
        </div>

        <!-- ASHI Amaya Banner -->
        <div class="ashi-banner">
            <div class="banner-overlay"></div>
            <div class="banner-content">
                <div class="banner-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h2>ASHI Amaya School</h2>
                <p class="banner-tagline">Your Journey to Excellence Starts Here</p>
                <div class="banner-stats">
                    <div class="stat-item">
                        <div class="stat-number">500+</div>
                        <div class="stat-label">Students</div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-item">
                        <div class="stat-number">50+</div>
                        <div class="stat-label">Teachers</div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-item">
                        <div class="stat-number">100+</div>
                        <div class="stat-label">Classes</div>
                    </div>
                </div>
                <p class="banner-description">
                    Welcome to ASHI Amaya's student portal. Connect with your teachers, complete assignments, 
                    and achieve your academic goals with our comprehensive learning platform.
                </p>
            </div>
        </div>

        <!-- Getting Started Section -->
        <div class="dashboard-steps">
            <h3>Quick Start Guide</h3>
            <div class="steps-container">
                <div class="step">
                    <div class="step-num">1</div>
                    <h4>Join Classes</h4>
                    <p>Enter class codes provided by your teachers</p>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <h4>Check Assignments</h4>
                    <p>View upcoming tasks and deadlines</p>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <h4>Stay Connected</h4>
                    <p>Engage with teachers and track your progress</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>

</style>

<!-- My Subjects Section -->
<div class="content-section" id="subjects">

    <!-- Loading State -->
    <div id="subjects-loading" class="loading-state" style="display: none;">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Loading your subjects...</p>
    </div>

    <!-- Error State -->
    <div id="subjects-error" class="error-state" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i>
        <h3>Error Loading Subjects</h3>
        <p id="subjects-error-message"></p>
        <button onclick="loadSubjects()" class="btn-retry">
            <i class="fas fa-redo"></i> Retry
        </button>
    </div>

    <!-- Empty State -->
    <div id="subjects-empty" class="empty-state" style="display: none;">
        <i class="fas fa-book"></i>
        <h3>No Subjects Enrolled</h3>
        <p>You haven't enrolled in any subjects yet. Join a class to get started!</p>
    </div>

    <!-- Subjects Grid -->
    <div id="subjects-grid" class="subjects-grid" style="display: none;">
        <!-- Subject cards will be dynamically inserted here -->
    </div>

    <!-- Floating Action Button - Only in Subjects Section -->
    <button class="fab-join-class" onclick="openJoinModal()" title="Join a Class">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Join Class Modal -->
    <div class="join-modal" id="joinClassModal">
        <div class="join-modal-overlay" onclick="closeJoinModal()"></div>
        <div class="join-modal-content">
            <div class="join-modal-header">
                <i class="fas fa-graduation-cap"></i>
                <h2>Join a Class</h2>
                <p>Enter the class code provided by your teacher</p>
                <button class="modal-close-btn" onclick="closeJoinModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="joinClassForm" onsubmit="handleJoinClass(event)">
                <div class="form-group">
                    <label for="classCode">
                        <i class="fas fa-key"></i> Class Code
                    </label>
                    <input 
                        type="text" 
                        id="classCode" 
                        name="class_code" 
                        placeholder="Enter class code (e.g., ABC123)" 
                        maxlength="10" 
                        required 
                        autofocus
                        style="text-transform: uppercase;">
                    <small>Ask your teacher for the class code</small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-modal-cancel" onclick="closeJoinModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn-modal-submit">
                        <i class="fas fa-check"></i> Join Class
                    </button>
                </div>
            </form>
            
            <!-- Success/Error Messages in Modal -->
            <div id="modalMessage" class="modal-message" style="display: none;"></div>
        </div>
    </div>
</div>

<!-- Tasks Section -->
<div class="content-section" id="tasks">
    
    <!-- Tasks Interface -->
    <div class="tasks-interface">
        <!-- Filter and Sort Controls -->
        <div class="controls-bar">
            <div class="filter-tabs">
                <button class="filter-tab active" data-filter="all">All</button>
                <button class="filter-tab" data-filter="pending">Pending</button>
                <button class="filter-tab" data-filter="overdue">Overdue</button>
            </div>
            
            <select class="sort-select" id="sortBy">
                <option value="due_date">Sort by: Due Date</option>
                <option value="subject">Sort by: Subject</option>
                <option value="points">Sort by: Points</option>
                <option value="created">Sort by: Recently Added</option>
            </select>
        </div>
        
        <!-- Tasks Grid -->
        <div class="tasks-grid" id="tasksGrid">
            <!-- Loading State -->
            <div class="loading-state" id="loadingState">
                <div class="loader"></div>
                <p>Loading tasks...</p>
            </div>
            
            <!-- Empty State -->
            <div class="empty-state hidden" id="emptyState">
                <i class="fas fa-inbox"></i>
                <p>No tasks found</p>
            </div>
        </div>
    </div>
</div>
<!-- My Submissions Section -->
<div class="content-section" id="submissions">

    <!-- Statistics Overview -->
    <div class="submissions-stats">
        <div class="stat-card">
            <div class="stat-icon" style="background-color: #1a73e8;">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-content">
                <h3 class="stat-number" id="totalSubmissions">0</h3>
                <p class="stat-label">Total Submissions</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background-color: #34a853;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <h3 class="stat-number" id="gradedSubmissions">0</h3>
                <p class="stat-label">Graded</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background-color: #fbbc04;">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
                <h3 class="stat-number" id="pendingSubmissions">0</h3>
                <p class="stat-label">Pending Review</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background-color: #9334e9;">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-content">
                <h3 class="stat-number" id="averageGrade">0%</h3>
                <p class="stat-label">Average Grade</p>
            </div>
        </div>
    </div>

    <!-- Filter and Sort Options -->
    <div class="submissions-controls">
        <div class="filter-group">
            <div class="filter-item">
                <label for="statusFilter">
                    <i class="fas fa-filter"></i> Status
                </label>
                <select id="statusFilter" class="filter-select">
                    <option value="all">All Status</option>
                    <option value="graded">Graded</option>
                    <option value="returned">Returned</option>
                    <option value="submitted">Submitted</option>
                    <option value="late">Late</option>
                </select>
            </div>
            <div class="filter-item">
                <label for="subjectFilter">
                    <i class="fas fa-book"></i> Subject
                </label>
                <select id="subjectFilter" class="filter-select">
                    <option value="all">All Subjects</option>
                </select>
            </div>
            <div class="filter-item">
                <label for="typeFilter">
                    <i class="fas fa-tasks"></i> Type
                </label>
                <select id="typeFilter" class="filter-select">
                    <option value="all">All Types</option>
                    <option value="homework">Homework</option>
                    <option value="quiz">Quiz</option>
                    <option value="project">Project</option>
                    <option value="exam">Exam</option>
                    <option value="classwork">Classwork</option>
                </select>
            </div>
        </div>
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchSubmissions" placeholder="Search assignments...">
        </div>
    </div>

    <!-- Submissions List -->
    <div class="submissions-container" id="submissionsContainer">
        <!-- Submissions will be dynamically loaded here -->
    </div>

    <!-- Empty State (shown when no submissions) -->
    <div class="empty-state" id="emptyState" style="display: none;">
        <i class="fas fa-file-upload"></i>
        <h3>No Submissions Yet</h3>
        <p>Your submission history will be displayed here once you submit assignments.</p>
    </div>

    <!-- No Results State (shown when filters return no results) -->
    <div class="empty-state" id="noResultsState" style="display: none;">
        <i class="fas fa-search"></i>
        <h3>No Results Found</h3>
        <p>Try adjusting your filters or search terms.</p>
    </div>

    <!-- Loading State -->
    <div class="loading-state" id="loadingState">
        <div class="spinner"></div>
        <p>Loading submissions...</p>
    </div>
</div>



<div class="content-section" id="lectures">
    
    <!-- Loading state will be shown initially, then replaced by JavaScript -->
    <div class="lectures-loading">
        <div class="loading-spinner"></div>
        <p class="loading-text">Loading materials...</p>
    </div>
</div>

<!-- My Quiz Section -->
<div class="content-section" id="quiz">
    <!--div class="section-header">
        <h2 class="section-title">My Quizzes</h2>
        <p class="section-description">View and take your assigned quizzes</p>
    </div-->

    <!-- Quiz Container -->
    <div class="quiz-container">
        <!-- Controls Bar -->
        <div class="controls-bar">
            <div class="filter-tabs">
                <button class="filter-tab active" data-filter="all">All Quizzes</button>
                <button class="filter-tab" data-filter="pending">Pending</button>
                <button class="filter-tab" data-filter="completed">Completed</button>
                <button class="filter-tab" data-filter="overdue">Overdue</button>
            </div>
            <select class="sort-select" id="quizSort">
                <option value="due_date">Due Date</option>
                <option value="subject">Subject</option>
                <option value="title">Title</option>
                <option value="points">Points</option>
            </select>
        </div>

        <!-- Quiz Grid -->
        <div class="quiz-grid">
            <!-- Loading State -->
            <div class="loading-state">
                <div class="loader"></div>
                <p>Loading quizzes...</p>
            </div>
        </div>
    </div>
</div>

<style type="text/css">

</style>

<!-- My Grades Section -->
<div class="content-section" id="grades">
    <div class="student-grades-header">
        <h2 class="student-grades-title">My Grades</h2>
        <p class="student-grades-description">Your grades for this school year</p>
    </div>

    <!-- Quarter Tabs -->
    <div class="sgc-quarter-tabs" id="sgc-quarter-tabs" style="display:none;">
        <button class="sgc-qtab" data-quarter="1">
            <span class="sgc-qtab-label">1st Quarter</span>
        </button>
        <button class="sgc-qtab" data-quarter="2">
            <span class="sgc-qtab-label">2nd Quarter</span>
        </button>
        <button class="sgc-qtab" data-quarter="3">
            <span class="sgc-qtab-label">3rd Quarter</span>
        </button>
        <button class="sgc-qtab" data-quarter="4">
            <span class="sgc-qtab-label">4th Quarter</span>
        </button>
    </div>

    <div id="student-grades-loading" class="student-grades-loading">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Loading your grades...</p>
    </div>

    <div id="student-grades-empty" class="student-grades-empty" style="display: none;">
        <i class="fas fa-chart-line"></i>
        <h3>No Grades Yet</h3>
        <p>Your grades will appear here once your teachers have graded your work.</p>
    </div>

    <div id="student-grades-grid" class="student-grades-grid" style="display: none;">
    </div>
</div>

<style>
.student-grades-header {
    margin-bottom: 2rem;
    text-align: center;
}
.student-grades-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 0.5rem 0;
}
.student-grades-description {
    font-size: 1rem;
    color: #6b7280;
    margin: 0;
}

/* Loading */
.student-grades-loading {
    text-align: center;
    padding: 4rem 2rem;
}
.student-grades-loading i {
    font-size: 3rem;
    color: #3b82f6;
    margin-bottom: 1rem;
}
.student-grades-loading p {
    font-size: 1rem;
    color: #6b7280;
}

/* Empty */
.student-grades-empty {
    text-align: center;
    padding: 4rem 2rem;
    background: #f9fafb;
    border-radius: 1rem;
    border: 2px dashed #d1d5db;
}
.student-grades-empty i {
    font-size: 4rem;
    color: #9ca3af;
    margin-bottom: 1rem;
    display: block;
}
.student-grades-empty h3 {
    font-size: 1.5rem;
    color: #374151;
    margin: 0 0 0.5rem 0;
}
.student-grades-empty p {
    font-size: 1rem;
    color: #6b7280;
    margin: 0;
}

/* Grid */
.student-grades-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 1.5rem;
    padding: 1rem 0;
}

/* Card */
.student-grades-card {
    background: white;
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 2px solid #e5e7eb;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.student-grades-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.10);
}

/* Card Top: subject + teacher */
.sgc-subject {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}
.sgc-teacher {
    font-size: 0.875rem;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
}
.sgc-teacher i { font-size: 0.8rem; color: #9ca3af; }

/* Divider */
.sgc-divider {
    border: none;
    border-top: 1px solid #f3f4f6;
    margin: 0;
}

/* Official Grade Block */
.sgc-final-block {
    text-align: center;
    padding: 0.75rem 0;
}
.sgc-final-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #9ca3af;
    margin-bottom: 0.25rem;
}
.sgc-final-value {
    font-size: 3.5rem;
    font-weight: 800;
    line-height: 1;
}
.sgc-final-value.excellent { color: #10b981; }
.sgc-final-value.good      { color: #3b82f6; }
.sgc-final-value.fair      { color: #f59e0b; }
.sgc-final-value.poor      { color: #ef4444; }
.sgc-final-value.no-grade  { color: #d1d5db; font-size: 2.5rem; }

.sgc-badge {
    display: inline-block;
    margin-top: 0.4rem;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
}
.sgc-badge.excellent { background: #dcfce7; color: #15803d; }
.sgc-badge.good      { background: #dbeafe; color: #1d4ed8; }
.sgc-badge.fair      { background: #fef9c3; color: #92400e; }
.sgc-badge.poor      { background: #fee2e2; color: #b91c1c; }
.sgc-badge.pending   { background: #f3f4f6; color: #6b7280; }

/* Running Estimate Row */
.sgc-estimate {
    background: #f9fafb;
    border-radius: 0.6rem;
    padding: 0.65rem 0.9rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.sgc-estimate-left {
    font-size: 0.8rem;
    color: #6b7280;
    font-weight: 500;
}
.sgc-estimate-left span {
    display: block;
    font-size: 0.7rem;
    color: #9ca3af;
    margin-top: 1px;
}
.sgc-estimate-right {
    font-size: 1.2rem;
    font-weight: 700;
    color: #3b82f6;
}

/* PS Breakdown */
.sgc-breakdown {
    display: flex;
    gap: 0.5rem;
}
.sgc-ps {
    flex: 1;
    background: #f9fafb;
    border-radius: 0.6rem;
    padding: 0.5rem 0.75rem;
    text-align: center;
}
.sgc-ps-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #9ca3af;
    margin-bottom: 2px;
}
.sgc-ps-value {
    font-size: 1rem;
    font-weight: 700;
    color: #374151;
}
.sgc-ps-value.ok   { color: #10b981; }
.sgc-ps-value.warn { color: #f59e0b; }
.sgc-ps-value.bad  { color: #ef4444; }
.sgc-ps-value.na   { color: #d1d5db; }

/* Progress counts */
.sgc-progress {
    display: flex;
    gap: 0.5rem;
}
.sgc-prog-item {
    flex: 1;
    font-size: 0.7rem;
    color: #9ca3af;
    text-align: center;
}
.sgc-prog-item strong {
    display: block;
    font-size: 0.85rem;
    font-weight: 700;
    color: #374151;
}

/* Responsive */
@media (max-width: 768px) {
    .student-grades-grid {
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 1rem;
    }
}
@media (max-width: 480px) {
    .student-grades-grid { grid-template-columns: 1fr; }
    .student-grades-title { font-size: 1.5rem; }
}

/* ── Quarter Tabs ─────────────────────────────────────────── */
.sgc-quarter-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.sgc-qtab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    border: 1.5px solid var(--border, #e5e7eb);
    background: var(--bg, #fff);
    color: var(--text-muted, #6b7280);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all .18s ease;
    font-family: inherit;
    position: relative;
    flex-direction: column;
    align-items: flex-start;
}

.sgc-qtab:hover:not(.disabled):not(.active) {
    border-color: var(--brand, #2563eb);
    color: var(--brand, #2563eb);
    background: var(--brand-lt, #eff6ff);
}

.sgc-qtab.active {
    border-color: var(--brand, #2563eb);
    background: var(--brand, #2563eb);
    color: #fff;
}

.sgc-qtab.active .sgc-qtab-badge {
    background: rgba(255,255,255,0.25);
    color: #fff;
}

.sgc-qtab.disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: var(--bg-muted, #f9fafb);
}

.sgc-qtab-label {
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
}

.sgc-qtab-badge {
    font-size: 9px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    letter-spacing: .3px;
    text-transform: uppercase;
    line-height: 1.4;
}

.current-badge {
    background: #dcfce7;
    color: #15803d;
}

.sgc-qtab.active .current-badge {
    background: rgba(255,255,255,0.25);
    color: #fff;
}

.past-badge {
    background: #f1f5f9;
    color: #64748b;
}

.sgc-qtab.active .past-badge {
    background: rgba(255,255,255,0.25);
    color: #fff;
}

.future-badge {
    background: #f1f5f9;
    color: #9ca3af;
}

/* ── Card Header ──────────────────────────────────────────── */
.sgc-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 2px;
}

/* ── Quarter Status ───────────────────────────────────────── */
.sgc-qstatus {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 7px;
    border-radius: 5px;
    letter-spacing: .3px;
    text-transform: uppercase;
    white-space: nowrap;
    flex-shrink: 0;
}

.sgc-qstatus.locked {
    background: #dcfce7;
    color: #15803d;
}

.sgc-qstatus.current {
    background: #dbeafe;
    color: #1d4ed8;
}

.sgc-qstatus.no-record {
    background: #f1f5f9;
    color: #94a3b8;
}

/* ── Final Grade Block ────────────────────────────────────── */
.sgc-final-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 12px 0;
}

.sgc-final-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--text-muted, #6b7280);
}

.sgc-final-value {
    font-size: 40px;
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.sgc-final-value.excellent { color: #059669; }
.sgc-final-value.good      { color: #0284c7; }
.sgc-final-value.fair      { color: #d97706; }
.sgc-final-value.poor      { color: #dc2626; }
.sgc-final-value.no-grade  { color: #d1d5db; }

.sgc-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    letter-spacing: .3px;
}

.sgc-badge.excellent { background: #dcfce7; color: #15803d; }
.sgc-badge.good      { background: #dbeafe; color: #1d4ed8; }
.sgc-badge.fair      { background: #fef3c7; color: #92400e; }
.sgc-badge.poor      { background: #fee2e2; color: #991b1b; }
.sgc-badge.pending   { background: #f1f5f9; color: #94a3b8; }

/* ── Divider ──────────────────────────────────────────────── */
.sgc-divider {
    border: none;
    border-top: 1px solid var(--border, #e5e7eb);
    margin: 10px 0;
}

/* ── Running Estimate ─────────────────────────────────────── */
.sgc-estimate {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.sgc-estimate-left {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text, #111827);
}

.sgc-estimate-left span {
    font-size: 10px;
    font-weight: 400;
    color: var(--text-muted, #6b7280);
}

.sgc-estimate-right {
    font-size: 18px;
    font-weight: 800;
    color: var(--brand, #2563eb);
    white-space: nowrap;
}

/* ── PS Breakdown ─────────────────────────────────────────── */
.sgc-breakdown {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 10px;
}

.sgc-ps {
    background: var(--bg, #f9fafb);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 6px;
    padding: 8px;
    text-align: center;
}

.sgc-ps-label {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--text-muted, #6b7280);
    margin-bottom: 3px;
}

.sgc-ps-value {
    font-size: 14px;
    font-weight: 800;
}

.sgc-ps-value.ok   { color: #059669; }
.sgc-ps-value.warn { color: #d97706; }
.sgc-ps-value.bad  { color: #dc2626; }
.sgc-ps-value.na   { color: #d1d5db; }

/* ── Progress ─────────────────────────────────────────────── */
.sgc-progress {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.sgc-prog-item {
    font-size: 10px;
    color: var(--text-muted, #6b7280);
    display: flex;
    align-items: center;
    gap: 4px;
}

.sgc-prog-item strong {
    color: var(--text, #111827);
    font-weight: 700;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    loadStudentGrades();
});

// ── State ─────────────────────────────────────────────────────
let _gradesData      = [];
let _currentQuarter  = 1;
let _activeQuarter   = 1;
const QUARTER_LABELS = ['1st', '2nd', '3rd', '4th'];

// ── Load ──────────────────────────────────────────────────────
function loadStudentGrades() {
    const loadingDiv = document.getElementById('student-grades-loading');
    const emptyDiv   = document.getElementById('student-grades-empty');
    const gridDiv    = document.getElementById('student-grades-grid');
    const tabsDiv    = document.getElementById('sgc-quarter-tabs');

    loadingDiv.style.display = 'block';
    emptyDiv.style.display   = 'none';
    gridDiv.style.display    = 'none';
    gridDiv.innerHTML        = '';
    tabsDiv.style.display    = 'none';

    fetch('get_student_grades.php')
        .then(r => r.json())
        .then(data => {
            loadingDiv.style.display = 'none';

            if (!data.success || !data.grades.length) {
                emptyDiv.style.display = 'block';
                return;
            }

            _gradesData     = data.grades;
            _currentQuarter = data.current_quarter || 1;
            _activeQuarter  = _currentQuarter;

            // Build and show tabs
            buildQuarterTabs(data.current_quarter);
            tabsDiv.style.display = 'flex';

            // Render current quarter by default
            renderQuarter(_activeQuarter);
        })
        .catch(err => {
            loadingDiv.style.display = 'none';
            emptyDiv.style.display   = 'block';
            console.error('Error loading grades:', err);
        });
}

// ── Build Tabs ────────────────────────────────────────────────
function buildQuarterTabs(currentQ) {
    const tabs = document.querySelectorAll('.sgc-qtab');
    tabs.forEach(tab => {
        const q = parseInt(tab.dataset.quarter);

        // Clear old state and badges completely
        tab.classList.remove('active', 'current', 'past', 'disabled');
        tab.disabled = false;
        tab.querySelectorAll('.sgc-qtab-badge').forEach(b => b.remove());
        tab.onclick = null;

        if (q === currentQ) {
            // Current quarter — always enabled and marked as current
            tab.classList.add('current');
            tab.disabled = false;
            const badge = document.createElement('span');
            badge.className   = 'sgc-qtab-badge current-badge';
            badge.textContent = 'Current';
            tab.appendChild(badge);

        } else if (q < currentQ) {
            // Past quarter — clickable, show Done badge
            tab.classList.add('past');
            tab.disabled = false;
            const badge = document.createElement('span');
            badge.className   = 'sgc-qtab-badge past-badge';
            badge.textContent = 'Done';
            tab.appendChild(badge);

        } else {
            // Future quarter — grayed out, not clickable
            tab.classList.add('disabled');
            tab.disabled = true;
            const badge = document.createElement('span');
            badge.className   = 'sgc-qtab-badge future-badge';
            badge.textContent = 'Upcoming';
            tab.appendChild(badge);
        }

        // Attach click handler only to current and past tabs
        if (q <= currentQ) {
            tab.onclick = function () {
                _activeQuarter = q;
                document.querySelectorAll('.sgc-qtab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                renderQuarter(q);
            };
        }
    });

    // Default active tab = current quarter
    const activeTab = document.querySelector(`.sgc-qtab[data-quarter="${currentQ}"]`);
    if (activeTab) activeTab.classList.add('active');
}

// ── Render Quarter ────────────────────────────────────────────
function renderQuarter(q) {
    const gridDiv = document.getElementById('student-grades-grid');
    gridDiv.innerHTML = '';
    gridDiv.style.display = 'grid';

    const isCurrent = q === _currentQuarter;
    const isPast    = q < _currentQuarter;

    _gradesData.forEach(subject => {
        const qData = subject.quarters.find(qq => qq.quarter === q);
        gridDiv.appendChild(createGradeCard(subject, qData, isCurrent, isPast));
    });
}

// ── Create Card ───────────────────────────────────────────────
function createGradeCard(s, qData, isCurrent, isPast) {
    const card = document.createElement('div');
    card.className = 'student-grades-card';

    const grade    = qData ? qData.grade : null;
    const isLocked = qData ? qData.is_locked : false;

    const hasFinal     = grade !== null;
    const finalVal     = hasFinal ? parseFloat(grade) : null;
    const finalClass   = hasFinal ? getGradeClass(finalVal) : 'no-grade';
    const finalDisplay = hasFinal ? finalVal.toFixed(1) : '—';
    const badgeLabel   = hasFinal ? getBadgeLabel(finalVal) : (isPast ? 'No grade recorded' : 'Not yet graded');
    const badgeClass   = hasFinal ? finalClass : 'pending';

    // Quarter status indicator
    let statusHtml = '';
    if (isLocked) {
        statusHtml = `<span class="sgc-qstatus locked"><i class="fas fa-lock"></i> Finalized</span>`;
    } else if (isCurrent) {
        statusHtml = `<span class="sgc-qstatus current"><i class="fas fa-pen"></i> In Progress</span>`;
    } else if (isPast) {
        statusHtml = `<span class="sgc-qstatus no-record"><i class="fas fa-circle-minus"></i> No Record</span>`;
    }

    // Running estimate — only show on current quarter
    let estimateHtml = '';
    if (isCurrent) {
        const hasSugg    = s.suggested > 0;
        const suggDisplay = hasSugg ? s.suggested + '%' : '—';
        const apsCls     = psClass(s.assignment_ps);
        const qpsCls     = psClass(s.quiz_ps);
        const apsDisplay = s.assignment_ps > 0 ? s.assignment_ps + '%' : '—';
        const qpsDisplay = s.quiz_ps > 0 ? s.quiz_ps + '%' : '—';

        estimateHtml = `
            <hr class="sgc-divider">

            <!-- Running Estimate -->
            <div class="sgc-estimate">
                <div class="sgc-estimate-left">
                    Running Estimate
                    <span>Based on graded work so far</span>
                </div>
                <div class="sgc-estimate-right">${suggDisplay}</div>
            </div>

            <!-- PS Breakdown -->
            <div class="sgc-breakdown">
                <div class="sgc-ps">
                    <div class="sgc-ps-label">Assignment PS</div>
                    <div class="sgc-ps-value ${apsCls}">${apsDisplay}</div>
                </div>
                <div class="sgc-ps">
                    <div class="sgc-ps-label">Quiz PS</div>
                    <div class="sgc-ps-value ${qpsCls}">${qpsDisplay}</div>
                </div>
            </div>

            <!-- Submission Counts -->
            <div class="sgc-progress">
                <div class="sgc-prog-item">
                    <strong>${s.graded_assignments}/${s.total_assignments}</strong>
                    Assignments graded
                </div>
                <div class="sgc-prog-item">
                    <strong>${s.graded_quizzes}/${s.total_quizzes}</strong>
                    Quizzes graded
                </div>
            </div>
        `;
    }

    card.innerHTML = `
        <div class="sgc-card-header">
            <p class="sgc-subject">${esc(s.subject_name)}</p>
            ${statusHtml}
        </div>
        <p class="sgc-teacher"><i class="fas fa-user"></i>${esc(s.teacher_name)}</p>

        <hr class="sgc-divider">

        <!-- Official Grade -->
        <div class="sgc-final-block">
            <div class="sgc-final-label">
                ${isPast ? QUARTER_LABELS[qData.quarter - 1] + ' Quarter Grade' : 'Official Grade'}
            </div>
            <div class="sgc-final-value ${finalClass}">${finalDisplay}</div>
            <span class="sgc-badge ${badgeClass}">${badgeLabel}</span>
        </div>

        ${estimateHtml}
    `;

    return card;
}

// ── Helpers ───────────────────────────────────────────────────
function getGradeClass(g) {
    if (g >= 90) return 'excellent';
    if (g >= 85) return 'good';
    if (g >= 75) return 'fair';
    return 'poor';
}

function getBadgeLabel(g) {
    if (g >= 90) return 'Outstanding';
    if (g >= 85) return 'Very Satisfactory';
    if (g >= 80) return 'Satisfactory';
    if (g >= 75) return 'Fairly Satisfactory';
    return 'Did Not Meet';
}

function psClass(v) {
    v = parseFloat(v);
    if (v >= 85) return 'ok';
    if (v >= 60) return 'warn';
    if (v > 0)   return 'bad';
    return 'na';
}

function esc(str) {
    return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
</script>

<!-- My Profile Section -->
<div class="content-section" id="profile">
    
    <div class="profile-container">
        <div class="profile-card">
            <!-- Profile Header with Avatar (Left Aligned) -->
            <div class="profile-header-section">
                <div class="profile-avatar-wrapper">
                    <?php if ($profile_picture): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="profile-avatar-large">
                    <?php else: ?>
                        <div class="profile-avatar-large profile-avatar-initials">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="profile-header-info">
                    <h3 class="profile-name"><?php echo htmlspecialchars($full_name); ?></h3>
                    <p class="profile-grade"><?php echo htmlspecialchars($grade_section); ?></p>
                    <span class="profile-badge">Student</span>
                </div>
            </div>
            
            <!-- Profile Information Grid -->
            <div class="profile-info-grid">
                <h4 class="section-subtitle">
                    <i class="fas fa-user-circle"></i> Personal Information
                </h4>
                
                <div class="info-row">
                    <div class="info-group">
                        <label><i class="fas fa-id-card"></i> Student ID</label>
                        <p><?php echo htmlspecialchars($student['id']); ?></p>
                    </div>
                    
                    <div class="info-group">
                        <label><i class="fas fa-user"></i> First Name</label>
                        <p><?php echo htmlspecialchars($student['first_name']); ?></p>
                    </div>
                </div>
                
                <div class="info-row">
                    <div class="info-group">
                        <label><i class="fas fa-user"></i> Last Name</label>
                        <p><?php echo htmlspecialchars($student['last_name']); ?></p>
                    </div>
                    
                    <div class="info-group">
                        <label><i class="fas fa-birthday-cake"></i> Birthdate</label>
                        <p><?php echo date('F d, Y', strtotime($student['birthdate'])); ?></p>
                    </div>
                </div>
                
                <h4 class="section-subtitle">
                    <i class="fas fa-envelope"></i> Contact Information
                </h4>
                
                <div class="info-row">
                    <div class="info-group">
                        <label><i class="fas fa-envelope"></i> Email Address</label>
                        <p><?php echo htmlspecialchars($student['email']); ?></p>
                    </div>
                    
                    <div class="info-group">
                        <label><i class="fas fa-at"></i> Username</label>
                        <p><?php echo htmlspecialchars($student['username']); ?></p>
                    </div>
                </div>
                
                <?php if (!empty($student['contact_number'])): ?>
                <div class="info-row">
                    <div class="info-group full-width">
                        <label><i class="fas fa-phone"></i> Contact Number</label>
                        <p><?php echo htmlspecialchars($student['contact_number']); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <h4 class="section-subtitle">
                    <i class="fas fa-graduation-cap"></i> Academic Information
                </h4>
                
                <div class="info-row">
                    <div class="info-group">
                        <label><i class="fas fa-layer-group"></i> Grade Level</label>
                        <p><?php echo htmlspecialchars($student['grade_level']); ?></p>
                    </div>
                    
                    <div class="info-group">
                        <label><i class="fas fa-users"></i> Section</label>
                        <p><?php echo htmlspecialchars($student['section']); ?></p>
                    </div>
                </div>
                
                <h4 class="section-subtitle">
                    <i class="fas fa-user-friends"></i> Guardian Information
                </h4>
                
                <div class="info-row">
                    <div class="info-group">
                        <label><i class="fas fa-user-shield"></i> Guardian Name</label>
                        <p><?php echo htmlspecialchars($student['guardian_name']); ?></p>
                    </div>
                    
                    <div class="info-group">
                        <label><i class="fas fa-heart"></i> Relationship</label>
                        <p><?php echo htmlspecialchars($student['relationship']); ?></p>
                    </div>
                </div>
                
                <?php if (!empty($student['guardian_contact'])): ?>
                <div class="info-row">
                    <div class="info-group full-width">
                        <label><i class="fas fa-phone-alt"></i> Guardian Contact Number</label>
                        <p><?php echo htmlspecialchars($student['guardian_contact']); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($student['bio'])): ?>
                <h4 class="section-subtitle">
                    <i class="fas fa-info-circle"></i> About Me
                </h4>
                
                <div class="info-row">
                    <div class="info-group full-width">
                        <div class="bio-text">
                            <p><?php echo nl2br(htmlspecialchars($student['bio'])); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="profile-footer">
                    <small class="text-muted">
                        <i class="fas fa-clock"></i> 
                        Account created on <?php echo date('F d, Y', strtotime($student['created_at'])); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Adviser Badge */
.adviser-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.4);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-top: 6px;
    width: fit-content;
}

.adviser-badge i {
    font-size: 0.6rem;
    color: #fde68a;
}

/* Adviser card subtle highlight */
.adviser-subject {
    border-color: rgba(99, 102, 241, 0.35);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08), 0 0 0 2px rgba(99, 102, 241, 0.2);
}

.adviser-subject:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 0 0 2px rgba(99, 102, 241, 0.4);
}
</style>

            <!-- Logout Section -->
            <div class="content-section" id="logout">
                <div class="section-header">
                    <h2 class="section-title">Logout</h2>
                    <p class="section-description">Sign out from your account safely.</p>
                </div>
                <div class="empty-state">
                    <i class="fas fa-sign-out-alt"></i>
                    <h3>Logout</h3>
                    <p>Click the button below to logout from your account</p>
                    <button onclick="handleLogout()" class="btn btn-danger mt-3">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </main>
    <script src="subjects-section.js"></script>
    <script src="task_assign.js"></script>
    <script src="my_lectures.js"></script>
    <script src="task_badge_updater.js"></script>
    <script>
// Quiz Section JavaScript
document.addEventListener('DOMContentLoaded', function() {
    let allQuizzes = [];
    let currentFilter = 'all';
    let currentSort = 'due_date';

    // Initialize Quiz Section
    function initQuizSection() {
        loadQuizzes();
        setupEventListeners();
    }

    // Setup Event Listeners
    function setupEventListeners() {
        // Filter tabs
        const filterTabs = document.querySelectorAll('.filter-tab');
        filterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                filterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                currentFilter = this.dataset.filter;
                filterAndDisplayQuizzes();
            });
        });

        // Sort select
        const sortSelect = document.getElementById('quizSort');
        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                currentSort = this.value;
                filterAndDisplayQuizzes();
            });
        }
    }

    // Load Quizzes from Server
    function loadQuizzes() {
        const quizGrid = document.querySelector('.quiz-grid');
        
        fetch('get_student_quizzes.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allQuizzes = data.quizzes;
                    updateQuizBadge(data.pending_count);
                    filterAndDisplayQuizzes();
                } else {
                    showEmptyState(data.message || 'No quizzes available');
                }
            })
            .catch(error => {
                console.error('Error loading quizzes:', error);
                showEmptyState('Failed to load quizzes. Please try again.');
            });
    }

    // Filter and Display Quizzes
    function filterAndDisplayQuizzes() {
        let filteredQuizzes = [...allQuizzes];

        // Apply filter
        if (currentFilter !== 'all') {
            filteredQuizzes = filteredQuizzes.filter(quiz => quiz.status === currentFilter);
        }

        // Separate completed and incomplete quizzes
        let incompleteQuizzes = filteredQuizzes.filter(quiz => quiz.status !== 'completed');
        let completedQuizzes = filteredQuizzes.filter(quiz => quiz.status === 'completed');

        // Apply sort to incomplete quizzes
        incompleteQuizzes.sort((a, b) => {
            switch (currentSort) {
                case 'due_date':
                    return new Date(a.due_date) - new Date(b.due_date);
                case 'subject':
                    return a.subject_name.localeCompare(b.subject_name);
                case 'title':
                    return a.title.localeCompare(b.title);
                case 'points':
                    return b.total_points - a.total_points;
                default:
                    return 0;
            }
        });

        // Apply sort to completed quizzes
        completedQuizzes.sort((a, b) => {
            switch (currentSort) {
                case 'due_date':
                    return new Date(a.due_date) - new Date(b.due_date);
                case 'subject':
                    return a.subject_name.localeCompare(b.subject_name);
                case 'title':
                    return a.title.localeCompare(b.title);
                case 'points':
                    return b.total_points - a.total_points;
                default:
                    return 0;
            }
        });

        // Combine: incomplete quizzes first, then completed quizzes
        const sortedQuizzes = [...incompleteQuizzes, ...completedQuizzes];

        displayQuizzes(sortedQuizzes);
    }

    // Display Quizzes
    function displayQuizzes(quizzes) {
        const quizGrid = document.querySelector('.quiz-grid');

        if (quizzes.length === 0) {
            showEmptyState('No quizzes found for this filter');
            return;
        }

        quizGrid.innerHTML = quizzes.map(quiz => createQuizCard(quiz)).join('');
    }

    // Create Quiz Card HTML
    function createQuizCard(quiz) {
        const dueDate = new Date(quiz.due_date);
        const now = new Date();
        const timeUntilDue = dueDate - now;
        const daysUntilDue = Math.ceil(timeUntilDue / (1000 * 60 * 60 * 24));
        
        let dueDateText = formatDueDate(dueDate);
        let dueDateClass = '';
        
        if (quiz.status === 'pending' && daysUntilDue <= 2) {
            dueDateClass = 'due-soon';
            dueDateText = daysUntilDue === 0 ? 'Due Today' : `Due in ${daysUntilDue} day${daysUntilDue > 1 ? 's' : ''}`;
        }

        const buttonText = quiz.status === 'completed' ? 'View Results' : 'Take Quiz';
        const buttonClass = quiz.status === 'completed' ? 'take-quiz-btn completed' : 'take-quiz-btn';
        
        return `
            <div class="quiz-card" data-quiz-id="${quiz.id}">
                <div class="card-header">
                    <span class="subject-tag">${escapeHtml(quiz.subject_name)}</span>
                    <span class="status-badge ${quiz.status}">${quiz.status.replace('-', ' ')}</span>
                </div>
                
                <h3 class="quiz-title">${escapeHtml(quiz.title)}</h3>
                
                ${quiz.description ? `<p class="quiz-description">${escapeHtml(quiz.description)}</p>` : ''}
                
                <div class="quiz-meta">
                    <div class="meta-item teacher">
                        <i class="fas fa-user"></i>
                        <span>${escapeHtml(quiz.teacher_name)}</span>
                    </div>
                    <div class="meta-item ${dueDateClass}">
                        <i class="fas fa-calendar"></i>
                        <span>${dueDateText}</span>
                    </div>
                    ${quiz.time_limit ? `
                    <div class="meta-item">
                        <i class="fas fa-clock"></i>
                        <span>${quiz.time_limit} min</span>
                    </div>
                    ` : ''}
                    <div class="meta-item">
                        <i class="fas fa-question-circle"></i>
                        <span>${quiz.question_count} question${quiz.question_count > 1 ? 's' : ''}</span>
                    </div>
                </div>
                
                <div class="card-footer">
                    <div class="quiz-info">
                        <span class="info-badge">
                            <i class="fas fa-star"></i>
                            ${quiz.total_points} pts
                        </span>
                        ${quiz.max_attempts > 1 ? `
                        <span class="info-badge">
                            <i class="fas fa-redo"></i>
                            ${quiz.attempts_used}/${quiz.max_attempts}
                        </span>
                        ` : ''}
                    </div>
                    
                    ${quiz.status === 'completed' ? `
                    <div class="score-display">
                        Score: ${quiz.score}/${quiz.total_points} (${quiz.percentage}%)
                    </div>
                    ` : ''}
                    
                    <a href="quiz/take_quiz.php?id=${quiz.id}" class="${buttonClass}">
                        <i class="fas fa-${quiz.status === 'completed' ? 'eye' : 'play'}"></i>
                        ${buttonText}
                    </a>
                </div>
            </div>
        `;
    }

    // Format Due Date
    function formatDueDate(date) {
        const options = { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' };
        return date.toLocaleDateString('en-US', options);
    }

    // Update Quiz Badge Notification
    function updateQuizBadge(count) {
        const badge = document.querySelector('.badge-quiz-notification');
        if (badge) {
            badge.textContent = count;
            if (count === 0) {
                badge.classList.add('zero');
            } else {
                badge.classList.remove('zero');
            }
        }
    }

    // Show Empty State
    function showEmptyState(message) {
        const quizGrid = document.querySelector('.quiz-grid');
        quizGrid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <h3>No Quizzes</h3>
                <p>${escapeHtml(message)}</p>
            </div>
        `;
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when DOM is ready
    initQuizSection();
});

// Function to fetch unread message count
function updateMessengerBadge() {
    fetch('../messages/api.php?action=get_unread_count')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.count > 0) {
                const badge = document.getElementById('messengerBadge');
                badge.textContent = data.count > 99 ? '99+' : data.count;
                badge.style.display = 'flex';
            } else {
                document.getElementById('messengerBadge').style.display = 'none';
            }
        })
        .catch(err => console.error('Error fetching unread count:', err));
}

// Update badge on page load
updateMessengerBadge();

// Update badge every 10 seconds
setInterval(updateMessengerBadge, 10000);


    </script>
</body>
</html>
<?php
// Close database connection
$conn->close();
?>