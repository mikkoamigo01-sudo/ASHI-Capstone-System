<?php
session_start();

if (!isset($_SESSION['teacher_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db_connection/ashi_dbcnnt.php';

$stmt = $conn->prepare("SELECT fullname, email, specialization, profile_picture, theme_color FROM teachers WHERE id = ?");
$stmt->bind_param("i", $_SESSION['teacher_id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Get initials for avatar fallback
$nameParts = explode(' ', $teacher['fullname']);
$initials = '';
foreach ($nameParts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper($part[0]);
    }
}
$initials = substr($initials, 0, 2);

// Set default theme color if not set
$themeColor = $teacher['theme_color'] ?? '#2563eb';

// Calculate darker shade for hover effects
$darkColors = [
    '#2563eb' => '#1d4ed8',
    '#16a34a' => '#059669',
    '#7c3aed' => '#6d28d9',
    '#dc2626' => '#b91c1c'
];
$themeDark = $darkColors[$themeColor] ?? '#1d4ed8';

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title>Teacher Dashboard - ASHI System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="style/dashboard_teacher.css">
    <link rel="stylesheet" type="text/css" href="style/sectionone.css">
    <link rel="stylesheet" type="text/css" href="style/sectiontwo.css">
    <link rel="stylesheet" type="text/css" href="style/sectionthree.css">
    <link rel="stylesheet" type="text/css" href="style/my_subject.css">
    <link rel="stylesheet" type="text/css" href="style/my_section.css">
    <link rel="stylesheet" type="text/css" href="style/dashboard.css">
    <link rel="stylesheet" type="text/css" href="style/help_support.css">
    <link rel="stylesheet" type="text/css" href="style/my_submission.css">
    <style>
        :root {
            --theme-color: <?php echo htmlspecialchars($themeColor); ?>;
            --theme-dark: <?php echo htmlspecialchars($themeDark); ?>;
        }

        /* Apply theme color to sidebar */
        .sidebar {
            background: linear-gradient(180deg, var(--theme-color) 0%, var(--theme-dark) 100%);
        }

        .menu-link.active {
            background-color: rgba(255, 255, 255, 0.2);
            border-left: 4px solid #fff;
        }

        .menu-link:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }

        .badge-notification {
            background-color: #ef4444;
        }

        .floating-add-btn {
            background: linear-gradient(135deg, var(--theme-color) 0%, var(--theme-dark) 100%);
        }

        .floating-add-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }
                /* Profile picture styles */
        .user-avatar-top {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--theme-color) 0%, var(--theme-dark) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            overflow: hidden;
        }

        .user-avatar-top img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Ensure profile picture displays correctly */
        .profile-picture-wrapper {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--theme-color);
        }

        .profile-picture-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        /* Profile Section Styles */
        .profile-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .profile-header {
            background: linear-gradient(135deg, <?php echo $themeColor; ?> 0%, <?php echo $themeDark; ?> 100%);
            border-radius: 16px;
            padding: 40px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .profile-header-content {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 700;
            color: <?php echo $themeColor; ?>;
            overflow: hidden;
            flex-shrink: 0;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-header-info {
            flex: 1;
        }

        .profile-header-info h2 {
            margin: 0 0 8px 0;
            font-size: 32px;
            font-weight: 700;
        }

        .profile-header-info p {
            margin: 0;
            opacity: 0.95;
            font-size: 16px;
        }

        .profile-stats {
            display: flex;
            gap: 30px;
            margin-top: 20px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            display: block;
        }

        .stat-label {
            font-size: 13px;
            opacity: 0.9;
        }

        .profile-body {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .profile-section {
            margin-bottom: 35px;
        }

        .profile-section:last-child {
            margin-bottom: 0;
        }

        .profile-section-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-section-title i {
            color: <?php echo $themeColor; ?>;
            font-size: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 15px;
            color: #1e293b;
            font-weight: 500;
        }

        .bio-content {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            color: #475569;
            line-height: 1.7;
            font-size: 15px;
        }

        .bio-content.empty {
            color: #94a3b8;
            font-style: italic;
        }

        .edit-profile-btn {
            background: <?php echo $themeColor; ?>;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .edit-profile-btn:hover {
            background: <?php echo $themeDark; ?>;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        /* Loading State */
        .profile-loading {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .profile-loading i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .profile-header {
                padding: 25px;
            }

            .profile-header-content {
                flex-direction: column;
                text-align: center;
            }

            .profile-avatar-large {
                width: 100px;
                height: 100px;
                font-size: 40px;
            }

            .profile-header-info h2 {
                font-size: 24px;
            }

            .profile-stats {
                justify-content: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

    </style>
</head>
<body>
    <!-- Sidebar -->
<aside class="sidebar" id="sidebar">
        <!-- Mobile Close Button -->
        <button class="mobile-close-btn" id="mobileCloseBtn">
            <i class="fas fa-times"></i>
        </button>

        <div class="sidebar-header">
            <img src="../img/ashi.jpg" alt="ASHI Logo" class="sidebar-logo">
            <div class="sidebar-title">
                <h4>ASHI System</h4>
                <p>Teacher Portal</p>
            </div>
        </div>
        
        <!-- Desktop Toggle Button -->
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-chevron-left"></i>
        </button>

        <!-- Rest of your sidebar content continues here -->
        <ul class="sidebar-menu">
            <!--li class="menu-item">
                <a class="menu-link active" data-section="dashboard">
                    <i class="fas fa-home"></i>
                    <span class="menu-text">Dashboard</span>
                </a>
            </li-->

            <div class="menu-section">Teaching</div>

            <li class="menu-item">
                <a class="menu-link" data-section="subjects">
                    <i class="fas fa-book-open"></i>
                    <span class="menu-text">My Subjects</span>
                </a>
            </li>

            <li class="menu-item">
                <a class="menu-link" data-section="students">
                    <i class="fas fa-user-graduate"></i>
                    <span class="menu-text">My Students</span>
                </a>
            </li>
<!--
            <li class="menu-item">
                <a class="menu-link" data-section="tasks">
                    <i class="fas fa-tasks"></i>
                    <span class="menu-text">Create Task</span>
                </a>
            </li>

            <li class="menu-item">
                <a class="menu-link" data-section="task-management">
                    <i class="fas fa-clipboard-list"></i>
                    <span class="menu-text">Manage Tasks</span>
                    <span class="badge-notification">8</span>
                </a>
            </li>
-->
            <li class="menu-item">
                <a class="menu-link" data-section="submissions">
                    <i class="fas fa-file-import"></i>
                    <span class="menu-text">Submissions</span>
                    <span class="badge-notification" id="submissionsBadge">0</span>
                </a>
            </li>

            <!--li class="menu-item">
                <a class="menu-link" data-section="lectures">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span class="menu-text">Lectures</span>
                </a>
            </li-->

            <div class="menu-section">Assessment</div>

<li class="menu-item">
    <a href="enroll_student.php" class="menu-link">
        <i class="fas fa-book"></i>
        <span class="menu-text">Enroll Student</span>
    </a>
</li>

<li class="menu-item">
    <a href="grade.php" class="menu-link">
        <i class="fas fa-star"></i>
        <span class="menu-text">Grade Management</span>
    </a>
</li>

<li class="menu-item">
    <a href="stud_qzpt.php" class="menu-link">
        <i class="fas fa-file-alt"></i>
        <span class="menu-text">Class Record Sheet</span>
    </a>
</li>

<div class="menu-section">Personalize</div>

            <!--li class="menu-item">
                <a class="menu-link" data-section="reports">
                    <i class="fas fa-chart-bar"></i>
                    <span class="menu-text">Reports</span>
                </a>
            </li-->

            <li class="menu-item">
                <a class="menu-link" data-section="profile">
                    <i class="fas fa-user-circle"></i>
                    <span class="menu-text">My Profile</span>
                </a>
            </li>

<li class="menu-item">
    <a class="menu-link" data-section="archive">
        <i class="fas fa-archive"></i>
        <span class="menu-text">Archive</span>
    </a>
</li>

            <div class="menu-section">System</div>

            <li class="menu-item">
                <a class="menu-link" data-section="help">
                    <i class="fas fa-question-circle"></i>
                    <span class="menu-text">Help & Support</span>
                </a>
            </li>

            <li class="menu-item">
                <a href="../logout.php" class="menu-link" onclick="return confirmLogout(event)">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="menu-text">Logout</span>
                </a>
            </li>
        </ul>

        <div class="sidebar-footer">
            <!-- Footer removed - user profile moved to top navbar -->
        </div>
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
                    <span class="notification-badge">7</span>
                </div-->
                <!--a href="../messages/" class="messenger-icon-link">
                    <div class="messenger-icon">
                        <i class="fa-regular fa-message"></i>
                        <span class="messenger-badge" id="messengerBadge" style="display: none;">0</span>
                    </div>
                </a-->
                <div class="user-profile-top">
                    <div class="user-avatar-top">
                        <?php 
                        // Handle profile picture path - check if it already contains 'upload' or 'uploads'
                        $profilePicPath = '';
                        if (!empty($teacher['profile_picture'])) {
                            // If the path already starts with upload/ or uploads/, use it as is
                            if (strpos($teacher['profile_picture'], 'upload') === 0) {
                                $profilePicPath = $teacher['profile_picture'];
                            } else {
                                // Otherwise, prepend upload/
                                $profilePicPath = 'upload/' . $teacher['profile_picture'];
                            }
                        }
                        
                        if (!empty($profilePicPath) && file_exists($profilePicPath)): 
                        ?>
                            <img src="<?php echo htmlspecialchars($profilePicPath); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <?php echo htmlspecialchars($initials); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-info-top">
                        <p class="user-name-top"><?php echo htmlspecialchars($teacher['fullname']); ?></p>
                        <p class="user-role-top"><?php echo htmlspecialchars($teacher['specialization']); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Content Area -->
        <div class="content-area">
<!-- Dashboard Section -->
<div class="content-section active" id="dashboard">

    <div class="dashboard-simple">
        <!-- Welcome Message with Image -->
        <div class="dashboard-welcome">
            <div class="welcome-left">
                <h3>Hello, Teacher! 👋</h3>
                <p>Ready to inspire and educate today?</p>
                <p class="welcome-subtitle">Manage your classes, track progress, and connect with students all in one place.</p>
            </div>
            <div class="welcome-right">
                <img src="../img/ashi.jpg" alt="Welcome" class="welcome-image">
            </div>
        </div>

        <!-- Quick Info Cards with Borders -->
        <div class="dashboard-cards">
            <div class="dashboard-card card-border-blue">
                <div class="card-icon blue">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="card-info">
                    <h4>Manage Classes</h4>
                    <p>Create and organize your classes with ease</p>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-icon green">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="card-info">
                    <h4>Assignments</h4>
                    <p>Create and review student submissions</p>
                </div>
            </div>

            <div class="dashboard-card card-border-purple">
                <div class="card-icon purple">
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-info">
                    <h4>Students</h4>
                    <p>Track student progress and performance</p>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="card-icon orange">
                    <i class="fas fa-comments"></i>
                </div>
                <div class="card-info">
                    <h4>Messages</h4>
                    <p>Communicate with students instantly</p>
                </div>
            </div>
        </div>

        <!-- ASHI Amaya Banner with Wave SVG -->
        <div class="ashi-banner">
            <div class="banner-overlay"></div>
            
            <!-- Wave SVG Pattern -->
            <div class="wave-pattern">
                <svg viewBox="0 0 1200 120" preserveAspectRatio="none" class="wave-svg">
                    <path d="M0,0 C150,100 350,0 600,50 C850,100 1050,0 1200,50 L1200,120 L0,120 Z" fill="rgba(16, 185, 129, 0.1)"></path>
                </svg>
                <svg viewBox="0 0 1200 120" preserveAspectRatio="none" class="wave-svg wave-2">
                    <path d="M0,20 C200,80 400,20 600,60 C800,100 1000,40 1200,80 L1200,120 L0,120 Z" fill="rgba(16, 185, 129, 0.05)"></path>
                </svg>
            </div>
            
            <div class="banner-content">
                <div class="banner-logo-circle">
                    <img src="../img/ashi.jpg" alt="ASHI Amaya Logo" class="banner-logo-img">
                </div>
                <h2>ASHI Amaya School</h2>
                <p class="banner-tagline">Empowering Education Through Technology</p>
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
                    Welcome to ASHI Amaya's classroom management system. Connect, collaborate, and create 
                    meaningful learning experiences with our comprehensive platform designed for modern education.
                </p>
            </div>
        </div>

        <!-- Getting Started Section -->
        <div class="dashboard-steps">
            <h3>Quick Start Guide</h3>
            <div class="steps-container">
                <div class="step">
                    <div class="step-num">1</div>
                    <h4>Create a Class</h4>
                    <p>Set up your first classroom and customize it</p>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <h4>Invite Students</h4>
                    <p>Share the unique class code with your students</p>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <h4>Start Teaching</h4>
                    <p>Post assignments, materials, and announcements</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- My Subjects Section -->
<div class="content-section" id="subjects">
    
    <div id="teacherSubjectContainer" class="teacher-subjects-grid"></div>
    
    <div class="teacher-subjects-empty-state" id="teacherSubjectsEmptyState" style="display: none;">
        <i class="fas fa-book-open"></i>
        <h3>No Subjects Yet</h3>
        <p>Click the + button to create your first subject</p>
    </div>
    
    <!-- Floating Add Button -->
    <button class="teacher-subject-floating-btn" id="teacherOpenCreateModal" title="Add New Subject">
        <i class="fas fa-plus"></i>
    </button>
</div>

<!-- CREATE SUBJECT MODAL -->
<div class="teacher-subject-modal hidden" id="teacherCreateModal">
    <div class="teacher-subject-modal-content">
        <h2>Create New Subject</h2>
        <div class="teacher-subject-form-group">
            <label>Subject Name <span class="form-required">*</span></label>
            <input id="teacherSubjectName" placeholder="e.g., Mathematics">
        </div>
        <div class="teacher-subject-form-group">
            <label>Grade <span class="form-required">*</span></label>
            <select id="teacherSubjectGrade">
                <option value="">Select grade</option>
                <option>Grade 7</option>
                <option>Grade 8</option>
                <option>Grade 9</option>
                <option>Grade 10</option>
                <option>Grade 11</option>
                <option>Grade 12</option>
            </select>
        </div>
        <div class="teacher-subject-form-group">
            <label>Section <span class="form-required">*</span></label>
            <select id="teacherSubjectSection" disabled>
                <option value="">Select grade first</option>
            </select>
        </div>
        <div class="teacher-subject-form-group">
            <label>Theme Color</label>
            <div class="teacher-subject-color-options">
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);" data-color="#1a73e8"></div>
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);" data-color="#34a853"></div>
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);" data-color="#9c27b0"></div>
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);" data-color="#fb8c00"></div>
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);" data-color="#e53935"></div>
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #ec4899 0%, #be185d 100%);" data-color="#d81b60"></div>
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);" data-color="#00897b"></div>
                <div class="teacher-subject-color-option" style="background: linear-gradient(135deg, #64748b 0%, #475569 100%);" data-color="#546e7a"></div>
            </div>
        </div>
        <div class="teacher-subject-form-group">
            <label>Subject Logo <span class="form-required">*</span></label>
            <input type="file" id="teacherSubjectLogo" accept="image/*">
            <img id="teacherLogoPreview" class="teacher-subject-logo-preview hidden">
        </div>
        <div class="teacher-subject-modal-actions">
            <button class="teacher-subject-btn teacher-subject-btn-text" id="teacherCancelCreate">Cancel</button>
            <button class="teacher-subject-btn teacher-subject-btn-primary" id="teacherCreateSubject" disabled>Create Subject</button>
        </div>
    </div>
</div>

<!-- My Students Section -->
<div class="content-section" id="students">
    <!-- Search and Filter Controls -->
    <div class="my-students-controls-wrapper">
        <div class="my-students-search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="myStudentsSearchInput" placeholder="Search by First Name, Last Name, or Email...">
        </div>
        
        <div class="my-students-filter-controls">
<select id="myStudentsGradeFilter" class="my-students-filter-select">
    <option value="">All Grade Levels</option>
</select>
            
            <select id="myStudentsSectionFilter" class="my-students-filter-select">
                <option value="">All Sections</option>
            </select>
            
            <select id="myStudentsSubjectFilter" class="my-students-filter-select">
                <option value="">All Subjects</option>
            </select>
            
            <button id="myStudentsResetFiltersBtn" class="my-students-btn-reset">
                <i class="fas fa-redo"></i> Reset Filters
            </button>
        </div>
    </div>

    <!-- Total Count -->
    <div class="my-students-total-count">
        <div>
            <i class="fas fa-user-graduate"></i>
            <span id="myStudentsTotalCount">0</span> Total Students Enrolled
        </div>
    </div>

    <!-- Students Table -->
    <div class="my-students-table-container">
        <table class="my-students-table">
            <thead>
                <tr>
                    <th>
                        <span class="my-students-sortable-header" data-sort-column="name">
                            Student Name
                            <span class="my-students-sort-arrows">
                                <i class="fas fa-chevron-up"></i>
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        </span>
                    </th>
                    <th>
                        <span class="my-students-sortable-header" data-sort-column="email">
                            Email
                            <span class="my-students-sort-arrows">
                                <i class="fas fa-chevron-up"></i>
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        </span>
                    </th>
                    <th>
                        <span class="my-students-sortable-header" data-sort-column="section">
                            Section
                            <span class="my-students-sort-arrows">
                                <i class="fas fa-chevron-up"></i>
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        </span>
                    </th>
                    <th>
                        <span class="my-students-sortable-header" data-sort-column="subjects">
                            Subjects Enrolled
                            <span class="my-students-sort-arrows">
                                <i class="fas fa-chevron-up"></i>
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        </span>
                    </th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="myStudentsTableBody">
                <tr>
                    <td colspan="5" class="my-students-loading-cell">
                        <i class="fas fa-spinner fa-spin"></i> Loading students...
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Pagination Controls -->
        <div id="myStudentsPaginationWrapper" class="my-students-pagination-wrapper" style="display: none;">
            <!-- Per Page Selector -->
            <div class="my-students-per-page-selector">
                <label for="myStudentsPerPageSelect">Show:</label>
                <select id="myStudentsPerPageSelect">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>

            <!-- Pagination Info & Controls -->
            <div class="my-students-pagination-controls">
                <button id="myStudentsPrevBtn" class="my-students-pagination-btn" onclick="previousPage()">
                    <i class="fas fa-chevron-left"></i>
                    Previous
                </button>
                
                <div id="myStudentsPaginationNumbers" class="my-students-pagination-numbers">
                    <!-- Page numbers will be inserted here by JavaScript -->
                </div>
                
                <button id="myStudentsNextBtn" class="my-students-pagination-btn" onclick="nextPage()">
                    Next
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <!-- Showing X to Y of Z -->
            <div id="myStudentsPaginationInfo" class="my-students-pagination-info">
                Showing 0 to 0 of 0 students
            </div>
        </div>
    </div>
</div>

<!-- Student Details Modal -->
<div id="myStudentDetailsModal" class="my-students-modal">
    <div class="my-students-modal-content">
        <span class="my-students-close-modal">&times;</span>
        <h2>Student Details</h2>
        <div id="myStudentDetailsContent"></div>
    </div>
</div>


<!-- Create Advisory Task Section -->
<div class="content-section" id="advisory">
    <div class="section-header">
        <h2 class="section-title">Create Advisory Task</h2>
        <p class="section-description">Create tasks and assignments for your advisory students.</p>
    </div>
    <div class="content-placeholder">
        <i class="fas fa-user-graduate"></i>
        <h3>This is Create Advisory Task section</h3>
    </div>
</div>

<!-- Manage Advisory Tasks Section -->
<div class="content-section" id="advisory-task-management">
    <div class="section-header">
        <h2 class="section-title">Manage Advisory Tasks</h2>
        <p class="section-description">View and manage tasks assigned to your advisory class.</p>
    </div>
    <div class="content-placeholder">
        <i class="fas fa-clipboard-check"></i>
        <h3>This is Manage Advisory Tasks section</h3>
    </div>
</div>

<!-- Submissions Section -->
<div class="content-section" id="submissions">

    <!-- Search and Filter Controls -->
    <div class="submissions-controls">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="submissionSearch" placeholder="Search by student name, subject, or assignment...">
        </div>
        
        <div class="filter-controls">
            <select id="sortSubmissions" class="sort-select">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
                <option value="student_name">Student Name (A-Z)</option>
                <option value="subject">Subject</option>
                <option value="due_date">Due Date</option>
            </select>
            
            <select id="filterSubject" class="filter-select">
                <option value="all">All Subjects</option>
                <!-- Will be populated dynamically -->
            </select>
            
            <select id="filterStatus" class="filter-select">
                <option value="ungraded">Ungraded Only</option>
                <option value="submitted">Submitted</option>
                <option value="late">Late Submissions</option>
            </select>
        </div>
    </div>

    <!-- Submissions List -->
    <div class="submissions-container" id="submissionsContainer">
        <!-- Loading State -->
        <div class="loading-state" id="loadingState">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading submissions...</p>
        </div>

        <!-- Empty State -->
        <div class="empty-state" id="emptyState" style="display: none;">
            <i class="fas fa-file-import"></i>
            <h3>No Ungraded Submissions</h3>
            <p>All submissions have been graded. Great job!</p>
        </div>

        <!-- Submissions Grid -->
        <div class="submissions-grid" id="submissionsGrid">
            <!-- Submissions will be populated here via JavaScript -->
        </div>
    </div>
</div>

<!-- Grade Submission Modal -->
<div class="modal" id="gradeModal">
    <div class="modal-content grade-modal-content">
        <div class="modal-header">
            <h3>Grade Submission</h3>
            <button class="close-modal" onclick="closeGradeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body">
            <div class="submission-details">
                <div class="detail-row">
                    <strong>Student:</strong>
                    <span id="modalStudentName"></span>
                </div>
                <div class="detail-row">
                    <strong>Assignment:</strong>
                    <span id="modalAssignmentTitle"></span>
                </div>
                <div class="detail-row">
                    <strong>Subject:</strong>
                    <span id="modalSubjectName"></span>
                </div>
                <div class="detail-row">
                    <strong>Submitted:</strong>
                    <span id="modalSubmittedAt"></span>
                </div>
                <div class="detail-row">
                    <strong>Status:</strong>
                    <span id="modalStatus" class="status-badge"></span>
                </div>
            </div>

            <div class="submission-content">
                <h4>Submission Text:</h4>
                <div class="submission-text" id="modalSubmissionText"></div>
            </div>

            <div class="submission-attachments" id="modalAttachments">
                <h4>Attachments:</h4>
                <div class="attachments-list" id="modalAttachmentsList"></div>
            </div>

            <form id="gradeForm" onsubmit="submitGrade(event)">
                <input type="hidden" id="submissionId" name="submission_id">
                
                <div class="form-group">
                    <label for="score">Score <span id="maxPoints"></span></label>
                    <input type="number" id="score" name="score" step="0.01" min="0" required>
                </div>

                <div class="form-group">
                    <label for="feedback">Teacher Feedback</label>
                    <textarea id="feedback" name="feedback" rows="5" placeholder="Enter your feedback for the student..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeGradeModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Submit Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

            <!-- Lectures Section -->
            <div class="content-section" id="lectures">
                <div class="section-header">
                    <h2 class="section-title">Lectures</h2>
                    <p class="section-description">Upload and manage lecture materials.</p>
                </div>
                <div class="content-placeholder">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3>This is Lectures section</h3>
                </div>
            </div>

            <!-- Grade Management Section -->
            <div class="content-section" id="grades">
                <div class="section-header">
                    <h2 class="section-title">Grade Management</h2>
                    <p class="section-description">Record and manage student grades.</p>
                </div>
                <div class="content-placeholder">
                    <i class="fas fa-star"></i>
                    <h3>This is Grade Management section</h3>
                </div>
            </div>

            <!-- Reports Section -->
            <div class="content-section" id="reports">
                <div class="section-header">
                    <h2 class="section-title">Reports & Analytics</h2>
                    <p class="section-description">Generate and view reports.</p>
                </div>
                <div class="content-placeholder">
                    <i class="fas fa-chart-bar"></i>
                    <h3>This is Reports section</h3>
                </div>
            </div>

            <!-- My Profile Section -->
            <div class="content-section" id="profile">
                <div class="section-header">
                </div>
                
                <!-- Profile Loading State -->
                <div class="profile-loading" id="profileLoading">
                    <i class="fas fa-spinner"></i>
                    <p>Loading profile...</p>
                </div>
                
                <!-- Profile Content -->
                <div class="profile-container" id="profileContent" style="display: none;">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-header-content">
                            <div class="profile-avatar-large" id="profileAvatarLarge">
                                <!-- Will be populated by JavaScript -->
                            </div>
                            <div class="profile-header-info">
                                <h2 id="profileFullname">Loading...</h2>
                                <p id="profileSpecialization">Loading...</p>
                                <div class="profile-stats">
                                    <div class="stat-item">
                                        <span class="stat-value" id="profileAge">-</span>
                                        <span class="stat-label">Years Old</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-value" id="profileMemberSince">-</span>
                                        <span class="stat-label">Member Since</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Body -->
                    <div class="profile-body">
                        <!-- Personal Information Section -->
                        <div class="profile-section">
                            <h3 class="profile-section-title">
                                <i class="fas fa-user"></i>
                                Personal Information
                            </h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value" id="infoFullname">-</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Email Address</span>
                                    <span class="info-value" id="infoEmail">-</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Phone Number</span>
                                    <span class="info-value" id="infoPhone">-</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Gender</span>
                                    <span class="info-value" id="infoGender">-</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Date of Birth</span>
                                    <span class="info-value" id="infoBirthdate">-</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Age</span>
                                    <span class="info-value" id="infoAge">-</span>
                                </div>
                            </div>
                        </div>

                        <!-- Professional Information Section -->
                        <div class="profile-section">
                            <h3 class="profile-section-title">
                                <i class="fas fa-briefcase"></i>
                                Professional Information
                            </h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Education</span>
                                    <span class="info-value" id="infoEducation">-</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Specialization</span>
                                    <span class="info-value" id="infoSpecialization">-</span>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information Section -->
                        <div class="profile-section">
                            <h3 class="profile-section-title">
                                <i class="fas fa-map-marker-alt"></i>
                                Contact Information
                            </h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Address</span>
                                    <span class="info-value" id="infoAddress">-</span>
                                </div>
                            </div>
                        </div>

                        <!-- Bio Section -->
                        <div class="profile-section">
                            <h3 class="profile-section-title">
                                <i class="fas fa-quote-left"></i>
                                About Me
                            </h3>
                            <div class="bio-content" id="infoBio">
                                No bio available.
                            </div>
                        </div>

                        <!-- Edit Button -->
                        <div style="text-align: center; margin-top: 30px;">
                            <button class="edit-profile-btn" onclick="editProfile()">
                                <i class="fas fa-edit"></i>
                                Edit Profile
                            </button>
                        </div>
                    </div>
                </div>
            </div>

<!-- ============================================================
     ASHI AMAYA — Archive Section (HTML + CSS + JS combined)
     Drop this block into your teacher dashboard page.
     Requires: Font Awesome, get_archive.php, get_archive_detail.php
     ============================================================ -->

<!-- ═══════════════════════════════════════════════════════════
     STYLES
     ═══════════════════════════════════════════════════════════ -->
<style>
/* ── Toolbar ─────────────────────────────────────────────── */
.archive-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    align-items: center;
}
.archive-search-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    padding: 9px 16px;
    flex: 1;
    min-width: 220px;
    transition: border-color .2s;
}
.archive-search-wrap:focus-within {
    border-color: #4a90d9;
    box-shadow: 0 0 0 3px rgba(74,144,217,.1);
}
.archive-search-wrap i  { color: #9e9e9e; font-size: 14px; }
.archive-search-wrap input {
    border: none; outline: none; font-size: 14px; color: #333;
    width: 100%; background: transparent;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.archive-filter-wrap select {
    background: #fff;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    padding: 9px 14px;
    font-size: 14px;
    color: #333;
    cursor: pointer;
    outline: none;
    transition: border-color .2s;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.archive-filter-wrap select:focus { border-color: #4a90d9; }

/* ── Card Grid ───────────────────────────────────────────── */
.archive-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

/* ── Individual Card ─────────────────────────────────────── */
.archive-card {
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.12), 0 2px 8px rgba(0,0,0,.06);
    cursor: pointer;
    transition: transform .18s ease, box-shadow .18s ease;
    position: relative;
    border: 1px solid #e8e8e8;
    display: flex;
    flex-direction: column;
}
.archive-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,.13);
}

/* Card Banner — slash pattern */
.archive-card-banner {
    height: 96px;
    position: relative;
    display: flex;
    align-items: flex-end;
    padding: 12px 16px;
    background: #607d8b;
    overflow: hidden;
}
.archive-card-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.28);
    z-index: 1;
}
.archive-card-banner::after {
    content: '';
    position: absolute;
    inset: -20px;
    background-image: repeating-linear-gradient(
        -55deg,
        transparent, transparent 10px,
        rgba(255,255,255,.08) 10px, rgba(255,255,255,.08) 20px
    );
    z-index: 0;
}
.archive-card-banner-text {
    position: relative;
    z-index: 2;
    color: #fff;
}
.archive-card-banner-text h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
    text-shadow: 0 1px 3px rgba(0,0,0,.3);
}
.archive-card-banner-text p {
    margin: 2px 0 0;
    font-size: 12px;
    opacity: .9;
    text-shadow: 0 1px 2px rgba(0,0,0,.25);
}

/* Logo — bottom-right of banner */
.archive-card-logo {
    position: absolute;
    right: 14px;
    bottom: -22px;
    z-index: 3;
    width: 46px;
    height: 46px;
    border-radius: 8px;
    border: 3px solid #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,.2);
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.archive-card-logo i   { font-size: 20px; color: #90a4ae; }
.archive-card-logo img { width: 100%; height: 100%; object-fit: cover; }

/* Archive badge */
.archive-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 3;
    background: rgba(0,0,0,.55);
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 8px;
    letter-spacing: .5px;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 5px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}

/* Card body */
.archive-card-body {
    padding: 32px 16px 14px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.archive-card-grade {
    font-size: 12px;
    color: #888;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.archive-card-code {
    font-size: 11px;
    background: #f1f3f4;
    color: #555;
    padding: 2px 8px;
    border-radius: 8px;
    display: inline-block;
    font-family: 'Roboto Mono', monospace;
    margin-top: 2px;
}

/* Card footer */
.archive-card-footer {
    padding: 10px 16px;
    border-top: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.archive-card-date {
    font-size: 11px;
    color: #aaa;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
    display: flex;
    align-items: center;
    gap: 5px;
}
.archive-card-view-btn {
    background: #f1f3f4;
    border: none;
    border-radius: 8px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 600;
    color: #444;
    cursor: pointer;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
    transition: background .15s, color .15s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.archive-card-view-btn:hover { background: #e8eaed; color: #222; }

/* ── Empty State ─────────────────────────────────────────── */
.archive-empty {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 60px 20px;
}
.archive-empty-inner { text-align: center; }
.archive-icon { font-size: 56px; color: #c5cdd8; margin-bottom: 16px; display: block; }
.archive-empty-title {
    font-size: 17px;
    font-weight: 600;
    color: #555;
    margin: 0 0 6px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.archive-empty-sub {
    font-size: 13px;
    color: #9e9e9e;
    margin: 0;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}

/* ── Skeleton Loading ────────────────────────────────────── */
.archive-loading {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}
.skeleton-card {
    height: 220px;
    border-radius: 8px;
    background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
}
@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ═══════════════════════════════════════════════════════════
   MODAL
   ═══════════════════════════════════════════════════════════ */
.arc-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
    backdrop-filter: blur(3px);
}
.arc-modal-overlay.open { display: flex; }

.arc-modal {
    background: #fff;
    border-radius: 8px;
    width: 100%;
    max-width: 820px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    animation: arcSlideUp .25s ease;
    position: relative;
}
@keyframes arcSlideUp {
    from { transform: translateY(24px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

/* Modal Banner — slash pattern */
.arc-modal-banner {
    min-height: 130px;
    display: flex;
    align-items: flex-end;
    gap: 16px;
    padding: 20px 24px;
    position: relative;
    background: #607d8b;
    overflow: hidden;
}
.arc-modal-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.3);
    z-index: 1;
}
.arc-modal-banner::after {
    content: '';
    position: absolute;
    inset: -20px;
    background-image: repeating-linear-gradient(
        -55deg,
        transparent, transparent 14px,
        rgba(255,255,255,.07) 14px, rgba(255,255,255,.07) 28px
    );
    z-index: 0;
}
.arc-modal-banner-logo {
    position: relative;
    z-index: 2;
    width: 60px;
    height: 60px;
    border-radius: 8px;
    border: 3px solid rgba(255,255,255,.6);
    background: rgba(255,255,255,.2);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.arc-modal-banner-logo i   { font-size: 26px; color: #fff; }
.arc-modal-banner-logo img { width: 100%; height: 100%; object-fit: cover; }
.arc-modal-banner-info {
    position: relative;
    z-index: 2;
    color: #fff;
    flex: 1;
}
.arc-modal-badge {
    font-size: 11px;
    font-weight: 700;
    background: rgba(255,255,255,.25);
    border: 1px solid rgba(255,255,255,.4);
    padding: 3px 10px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    letter-spacing: .4px;
    text-transform: uppercase;
    margin-bottom: 6px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.arc-modal-banner-info h2 {
    margin: 0 0 3px;
    font-size: 22px;
    font-weight: 700;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
    text-shadow: 0 1px 3px rgba(0,0,0,.3);
}
.arc-modal-banner-info p {
    margin: 0;
    font-size: 13px;
    opacity: .85;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.arc-modal-close {
    position: absolute;
    top: 14px;
    right: 14px;
    z-index: 3;
    background: rgba(255,255,255,.2);
    border: none;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    color: #fff;
    font-size: 15px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s;
}
.arc-modal-close:hover { background: rgba(255,255,255,.35); }

/* Tabs */
.arc-modal-tabs {
    display: flex;
    border-bottom: 2px solid #f0f0f0;
    padding: 0 16px;
    background: #fff;
    overflow-x: auto;
    flex-shrink: 0;
}
.arc-tab {
    background: none;
    border: none;
    padding: 13px 18px;
    font-size: 13px;
    font-weight: 600;
    color: #888;
    cursor: pointer;
    border-bottom: 2.5px solid transparent;
    margin-bottom: -2px;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 7px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
    transition: color .15s, border-color .15s;
}
.arc-tab:hover  { color: #444; }
.arc-tab.active { color: #1a73e8; border-bottom-color: #1a73e8; }

/* Modal body */
.arc-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    background: #fafafa;
}
.arc-panel        { display: none; }
.arc-panel.active { display: block; }

/* Read-only notice */
.arc-readonly-notice {
    background: #fff8e1;
    border: 1px solid #ffe082;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12.5px;
    color: #795548;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}

/* Overview grid */
.arc-overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
}
.arc-info-card {
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.arc-info-full { grid-column: 1 / -1; }
.arc-info-label {
    font-size: 11px;
    font-weight: 700;
    color: #999;
    text-transform: uppercase;
    letter-spacing: .5px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.arc-info-value {
    font-size: 15px;
    font-weight: 600;
    color: #333;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.arc-code { font-family: 'Roboto Mono', monospace; font-size: 14px; letter-spacing: 1px; }

/* Stats row */
.arc-stat-row {
    grid-column: 1 / -1;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.arc-stat {
    flex: 1;
    min-width: 100px;
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    text-align: center;
}
.arc-stat i     { font-size: 20px; color: #1a73e8; }
.arc-stat span  { font-size: 22px; font-weight: 700; color: #333; font-family: 'Google Sans', 'Segoe UI', sans-serif; }
.arc-stat small { font-size: 11px; color: #999; font-family: 'Google Sans', 'Segoe UI', sans-serif; }

/* List items */
.arc-list { display: flex; flex-direction: column; gap: 10px; }
.arc-list-item {
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}
.arc-list-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.arc-list-icon.assign { background: #e8f0fe; color: #1a73e8; }
.arc-list-icon.quiz   { background: #fce8e6; color: #d93025; }
.arc-list-icon.mat    { background: #e6f4ea; color: #188038; }

.arc-list-info        { flex: 1; min-width: 0; }
.arc-list-info h4 {
    margin: 0 0 3px;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.arc-list-info p      { margin: 0; font-size: 12px; color: #888; }
.arc-list-meta        { text-align: right; flex-shrink: 0; }
.arc-list-meta span   { font-size: 11px; color: #bbb; display: block; }
.arc-list-meta strong { font-size: 13px; color: #555; }

.arc-list-empty {
    text-align: center;
    padding: 40px 20px;
    color: #bbb;
    font-size: 14px;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}
.arc-list-empty i { font-size: 32px; }

/* Modal spinner */
.arc-modal-loading {
    display: none;
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,.9);
    z-index: 10;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 14px;
    border-radius: 8px;
}
.arc-modal-loading.show { display: flex; }
.arc-spinner {
    width: 38px;
    height: 38px;
    border: 3.5px solid #e0e0e0;
    border-top-color: #1a73e8;
    border-radius: 50%;
    animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.arc-modal-loading p {
    font-size: 13px;
    color: #888;
    font-family: 'Google Sans', 'Segoe UI', sans-serif;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 600px) {
    .archive-grid,
    .archive-loading   { grid-template-columns: 1fr; }
    .arc-modal         { border-radius: 8px; }
    .arc-modal-body    { padding: 16px; }
    .arc-overview-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════
     HTML — Archive Section
     ═══════════════════════════════════════════════════════════ -->

<div class="content-section" id="archive">

    <!-- Search & Filter Bar -->
    <div class="archive-toolbar">
        <div class="archive-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="archiveSearch" placeholder="Search archived classes…" autocomplete="off">
        </div>
        <div class="archive-filter-wrap">
            <select id="archiveGradeFilter">
                <option value="">All Grades</option>
            </select>
        </div>
    </div>

    <!-- Cards Grid -->
    <div class="archive-grid" id="archiveGrid"></div>

    <!-- Empty State -->
    <div class="archive-empty" id="archiveEmpty" style="display:none;">
        <div class="archive-empty-inner">
            <i class="fas fa-box-open archive-icon"></i>
            <p class="archive-empty-title">No archived classes yet.</p>
            <p class="archive-empty-sub">Deactivated classes will appear here.</p>
        </div>
    </div>

    <!-- Loading Skeleton -->
    <div class="archive-loading" id="archiveLoading">
        <div class="skeleton-card" aria-hidden="true"></div>
        <div class="skeleton-card" aria-hidden="true"></div>
        <div class="skeleton-card" aria-hidden="true"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     HTML — View Modal
     ═══════════════════════════════════════════════════════════ -->
<div class="arc-modal-overlay" id="arcModalOverlay" role="dialog" aria-modal="true" aria-labelledby="arcModalTitle">
    <div class="arc-modal">

        <!-- Banner -->
        <div class="arc-modal-banner" id="arcModalBanner">
            <div class="arc-modal-banner-logo" id="arcModalLogo">
                <i class="fas fa-book-open"></i>
            </div>
            <div class="arc-modal-banner-info">
                <span class="arc-modal-badge"><i class="fas fa-archive"></i> Archived</span>
                <h2 id="arcModalTitle">Subject Name</h2>
                <p id="arcModalMeta">Grade • Section</p>
            </div>
            <button class="arc-modal-close" id="arcModalClose" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Tabs -->
        <div class="arc-modal-tabs">
            <button class="arc-tab active" data-tab="overview"><i class="fas fa-info-circle"></i> Overview</button>
            <button class="arc-tab" data-tab="assignments"><i class="fas fa-tasks"></i> Assignments</button>
            <button class="arc-tab" data-tab="quizzes"><i class="fas fa-question-circle"></i> Quizzes</button>
            <button class="arc-tab" data-tab="materials"><i class="fas fa-book"></i> Materials</button>
        </div>

        <!-- Tab Panels -->
        <div class="arc-modal-body">

            <!-- Overview -->
            <div class="arc-panel active" id="tab-overview">
                <div class="arc-overview-grid">
                    <div class="arc-info-card">
                        <span class="arc-info-label"><i class="fas fa-chalkboard"></i> Subject</span>
                        <span class="arc-info-value" id="ovSubject">—</span>
                    </div>
                    <div class="arc-info-card">
                        <span class="arc-info-label"><i class="fas fa-layer-group"></i> Grade</span>
                        <span class="arc-info-value" id="ovGrade">—</span>
                    </div>
                    <div class="arc-info-card">
                        <span class="arc-info-label"><i class="fas fa-users"></i> Section</span>
                        <span class="arc-info-value" id="ovSection">—</span>
                    </div>
                    <div class="arc-info-card">
                        <span class="arc-info-label"><i class="fas fa-key"></i> Class Code</span>
                        <span class="arc-info-value arc-code" id="ovCode">—</span>
                    </div>
                    <div class="arc-info-card arc-info-full">
                        <span class="arc-info-label"><i class="fas fa-align-left"></i> Description</span>
                        <span class="arc-info-value" id="ovDesc">—</span>
                    </div>
                    <div class="arc-info-card">
                        <span class="arc-info-label"><i class="fas fa-calendar-times"></i> Deactivated On</span>
                        <span class="arc-info-value" id="ovArchived">—</span>
                    </div>
                    <div class="arc-stat-row">
                        <div class="arc-stat"><i class="fas fa-tasks"></i><span id="statAssign">0</span><small>Assignments</small></div>
                        <div class="arc-stat"><i class="fas fa-question-circle"></i><span id="statQuiz">0</span><small>Quizzes</small></div>
                        <div class="arc-stat"><i class="fas fa-book"></i><span id="statMat">0</span><small>Materials</small></div>
                    </div>
                </div>
            </div>

            <!-- Assignments -->
            <div class="arc-panel" id="tab-assignments">
                <div class="arc-readonly-notice"><i class="fas fa-lock"></i> Read-only — assignments cannot be edited in archived classes.</div>
                <div class="arc-list" id="assignmentsList"></div>
                <div class="arc-list-empty" id="assignmentsEmpty" style="display:none;"><i class="fas fa-inbox"></i> No assignments found.</div>
            </div>

            <!-- Quizzes -->
            <div class="arc-panel" id="tab-quizzes">
                <div class="arc-readonly-notice"><i class="fas fa-lock"></i> Read-only — quizzes cannot be edited in archived classes.</div>
                <div class="arc-list" id="quizzesList"></div>
                <div class="arc-list-empty" id="quizzesEmpty" style="display:none;"><i class="fas fa-inbox"></i> No quizzes found.</div>
            </div>

            <!-- Materials -->
            <div class="arc-panel" id="tab-materials">
                <div class="arc-readonly-notice"><i class="fas fa-lock"></i> Read-only — materials cannot be edited in archived classes.</div>
                <div class="arc-list" id="materialsList"></div>
                <div class="arc-list-empty" id="materialsEmpty" style="display:none;"><i class="fas fa-inbox"></i> No materials found.</div>
            </div>

        </div><!-- /arc-modal-body -->

        <!-- Spinner -->
        <div class="arc-modal-loading" id="arcModalLoading">
            <div class="arc-spinner"></div>
            <p>Loading archived class…</p>
        </div>

    </div><!-- /arc-modal -->
</div><!-- /arc-modal-overlay -->

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    /* ── DOM refs ─────────────────────────────────────────── */
    const grid          = document.getElementById('archiveGrid');
    const emptyState    = document.getElementById('archiveEmpty');
    const loadingState  = document.getElementById('archiveLoading');
    const searchInput   = document.getElementById('archiveSearch');
    const gradeFilter   = document.getElementById('archiveGradeFilter');

    const overlay       = document.getElementById('arcModalOverlay');
    const modalBanner   = document.getElementById('arcModalBanner');
    const modalLogo     = document.getElementById('arcModalLogo');
    const modalTitle    = document.getElementById('arcModalTitle');
    const modalMeta     = document.getElementById('arcModalMeta');
    const modalClose    = document.getElementById('arcModalClose');
    const modalLoading  = document.getElementById('arcModalLoading');
    const tabs          = document.querySelectorAll('.arc-tab');
    const panels        = document.querySelectorAll('.arc-panel');

    const ovSubject     = document.getElementById('ovSubject');
    const ovGrade       = document.getElementById('ovGrade');
    const ovSection     = document.getElementById('ovSection');
    const ovCode        = document.getElementById('ovCode');
    const ovDesc        = document.getElementById('ovDesc');
    const ovArchived    = document.getElementById('ovArchived');
    const statAssign    = document.getElementById('statAssign');
    const statQuiz      = document.getElementById('statQuiz');
    const statMat       = document.getElementById('statMat');

    const assignmentsList  = document.getElementById('assignmentsList');
    const assignmentsEmpty = document.getElementById('assignmentsEmpty');
    const quizzesList      = document.getElementById('quizzesList');
    const quizzesEmpty     = document.getElementById('quizzesEmpty');
    const materialsList    = document.getElementById('materialsList');
    const materialsEmpty   = document.getElementById('materialsEmpty');

    let allSubjects = [];

    /* ── Helpers ──────────────────────────────────────────── */
    function formatDate(dateStr) {
        if (!dateStr) return '—';
        return new Date(dateStr).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function logoSrc(path) {
        if (!path) return '';
        return path.startsWith('http') ? path : '../uploads/subject_logos/' + path;
    }

    /* ── Load archived subjects ───────────────────────────── */
    function loadArchive() {
        loadingState.style.display = 'grid';
        emptyState.style.display   = 'none';
        grid.innerHTML             = '';

        fetch('get_archive.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                loadingState.style.display = 'none';
                if (!data.success || !data.subjects || !data.subjects.length) {
                    emptyState.style.display = 'flex';
                    return;
                }
                allSubjects = data.subjects;
                populateGradeFilter(allSubjects);
                renderCards(allSubjects);
            })
            .catch(() => {
                loadingState.style.display = 'none';
                emptyState.style.display   = 'flex';
            });
    }

    /* ── Grade filter ─────────────────────────────────────── */
    function populateGradeFilter(subjects) {
        const grades = [...new Set(subjects.map(s => s.grade))].sort();
        gradeFilter.innerHTML = '<option value="">All Grades</option>';
        grades.forEach(g => {
            const opt = document.createElement('option');
            opt.value = g; opt.textContent = g;
            gradeFilter.appendChild(opt);
        });
    }

    /* ── Render cards ─────────────────────────────────────── */
    function renderCards(subjects) {
        grid.innerHTML = '';
        if (!subjects.length) { emptyState.style.display = 'flex'; return; }
        emptyState.style.display = 'none';

        subjects.forEach(sub => {
            const color   = sub.theme_color || '#607d8b';
            const logo    = logoSrc(sub.logo_path);
            const section = sub.section || '—';
            const date    = sub.archived_at ? formatDate(sub.archived_at) : '—';

            const card = document.createElement('div');
            card.className  = 'archive-card';
            card.dataset.id = sub.id;
            card.innerHTML  = `
                <div class="archive-card-banner" style="background:${escHtml(color)};">
                    <span class="archive-badge"><i class="fas fa-archive"></i> Archived</span>
                    <div class="archive-card-banner-text">
                        <h3>${escHtml(sub.subject_name)}</h3>
                        <p>${escHtml(sub.grade)} &bull; ${escHtml(section)}</p>
                    </div>
                    <div class="archive-card-logo">
                        ${logo
                            ? `<img src="${escHtml(logo)}" alt="" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-book-open\\'></i>'">`
                            : `<i class="fas fa-book-open"></i>`}
                    </div>
                </div>
                <div class="archive-card-body">
                    <span class="archive-card-grade">${escHtml(sub.grade)}</span>
                    <span class="archive-card-code">${escHtml(sub.class_code)}</span>
                </div>
                <div class="archive-card-footer">
                    <span class="archive-card-date"><i class="fas fa-calendar-times"></i> ${date}</span>
                    <button class="archive-card-view-btn" data-id="${sub.id}">
                        <i class="fas fa-eye"></i> View
                    </button>
                </div>`;

            card.querySelector('.archive-card-view-btn').addEventListener('click', e => {
                e.stopPropagation();
                openModal(sub.id);
            });
            card.addEventListener('click', () => openModal(sub.id));
            grid.appendChild(card);
        });
    }

    /* ── Filters ──────────────────────────────────────────── */
    function applyFilters() {
        const q     = searchInput.value.trim().toLowerCase();
        const grade = gradeFilter.value;
        const filtered = allSubjects.filter(s => {
            const matchSearch = !q
                || s.subject_name.toLowerCase().includes(q)
                || (s.section    && s.section.toLowerCase().includes(q))
                || (s.class_code && s.class_code.toLowerCase().includes(q));
            return matchSearch && (!grade || s.grade === grade);
        });
        renderCards(filtered);
    }
    searchInput.addEventListener('input', applyFilters);
    gradeFilter.addEventListener('change', applyFilters);

    /* ── Open Modal ───────────────────────────────────────── */
    function openModal(subjectId) {
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        modalLoading.classList.add('show');

        tabs.forEach(t => t.classList.remove('active'));
        panels.forEach(p => p.classList.remove('active'));
        tabs[0].classList.add('active');
        document.getElementById('tab-overview').classList.add('active');

        fetch(`get_archive_detail.php?subject_id=${subjectId}`, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                modalLoading.classList.remove('show');
                if (!data.success) { alert(data.message || 'Failed to load.'); return; }
                fillModal(data);
            })
            .catch(() => {
                modalLoading.classList.remove('show');
                alert('Network error. Please try again.');
            });
    }

    /* ── Fill Modal ───────────────────────────────────────── */
    function fillModal(data) {
        const sub     = data.subject;
        const color   = sub.theme_color || '#607d8b';
        const section = sub.section || '—';
        const logo    = logoSrc(sub.logo_path);

        /* Banner */
        modalBanner.style.background = color;
        modalTitle.textContent = sub.subject_name;
        modalMeta.textContent  = `${sub.grade} · ${section}`;
        modalLogo.innerHTML = logo
            ? `<img src="${escHtml(logo)}" alt="" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-book-open\\'></i>'">`
            : `<i class="fas fa-book-open"></i>`;

        /* Overview */
        ovSubject.textContent  = sub.subject_name;
        ovGrade.textContent    = sub.grade;
        ovSection.textContent  = section;
        ovCode.textContent     = sub.class_code;
        ovDesc.textContent     = sub.description || 'No description provided.';
        ovArchived.textContent = sub.archived_at ? formatDate(sub.archived_at) : '—';
        statAssign.textContent = data.assignments ? data.assignments.length : 0;
        statQuiz.textContent   = data.quizzes     ? data.quizzes.length     : 0;
        statMat.textContent    = data.materials   ? data.materials.length   : 0;

        /* Assignments */
        buildList(assignmentsList, assignmentsEmpty, data.assignments || [], item => `
            <div class="arc-list-icon assign"><i class="fas fa-tasks"></i></div>
            <div class="arc-list-info">
                <h4>${escHtml(item.title)}</h4>
                <p>${escHtml(item.assignment_type)} · Due ${item.due_date ? formatDate(item.due_date) : 'No due date'}</p>
            </div>
            <div class="arc-list-meta">
                <span>Max points</span>
                <strong>${item.max_points}</strong>
            </div>`);

        /* Quizzes */
        buildList(quizzesList, quizzesEmpty, data.quizzes || [], item => `
            <div class="arc-list-icon quiz"><i class="fas fa-question-circle"></i></div>
            <div class="arc-list-info">
                <h4>${escHtml(item.title)}</h4>
                <p>Due ${item.due_date ? formatDate(item.due_date) : 'No due date'} · ${item.total_points} pts</p>
            </div>
            <div class="arc-list-meta">
                <span>Status</span>
                <strong>${escHtml(item.status)}</strong>
            </div>`);

        /* Materials */
        buildList(materialsList, materialsEmpty, data.materials || [], item => `
            <div class="arc-list-icon mat"><i class="fas fa-book"></i></div>
            <div class="arc-list-info">
                <h4>${escHtml(item.title)}</h4>
                <p>${escHtml(item.material_type)} · ${formatDate(item.created_at)}</p>
            </div>
            <div class="arc-list-meta">
                <span>&nbsp;</span>
                <strong>${item.url
                    ? `<a href="${escHtml(item.url)}" target="_blank" rel="noopener" style="color:#1a73e8;font-size:12px;">Open link</a>`
                    : 'File'}</strong>
            </div>`);
    }

    function buildList(container, emptyEl, items, templateFn) {
        container.innerHTML = '';
        if (!items.length) { emptyEl.style.display = 'flex'; return; }
        emptyEl.style.display = 'none';
        items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'arc-list-item';
            div.innerHTML = templateFn(item);
            container.appendChild(div);
        });
    }

    /* ── Tab switching ────────────────────────────────────── */
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
        });
    });

    /* ── Close modal ──────────────────────────────────────── */
    function closeModal() {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }
    modalClose.addEventListener('click', closeModal);
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

    /* ── Auto-load when Archive section becomes visible ───── */
    const archiveSection = document.getElementById('archive');
    const observer = new MutationObserver(() => {
        const visible = archiveSection &&
            archiveSection.style.display !== 'none' &&
            !archiveSection.classList.contains('hidden');
        if (visible && allSubjects.length === 0) loadArchive();
    });
    if (archiveSection) {
        observer.observe(archiveSection, { attributes: true, attributeFilter: ['style', 'class'] });
    }
    if (archiveSection && getComputedStyle(archiveSection).display !== 'none') {
        loadArchive();
    }

    /* Expose for manual refresh: window.ArchiveSection.reload() */
    window.ArchiveSection = { reload: loadArchive };

})();
</script>
<!-- Help & Support Section -->
<div class="content-section" id="help">
    <div class="section-header">
        <h2 class="section-title">Help & Support</h2>
        <p class="section-description">Get assistance and support for the ASHI Amaya School platform.</p>
    </div>

    <div class="help-support-container">
        <!-- System Information Toggle -->
        <div class="system-info-toggle">
            <button class="info-toggle-btn" onclick="toggleSystemInfo()">
                <i class="fas fa-info-circle"></i>
                <span>About This System</span>
                <i class="fas fa-chevron-down toggle-arrow"></i>
            </button>
            <div class="system-info-content" id="systemInfoContent">
                <div class="system-info-inner">
                    <p class="system-title"><strong>ASHI Amaya School Management System</strong></p>
                    <p class="system-desc">A comprehensive classroom management platform designed to streamline teaching and learning processes.</p>
                    
                    <div class="system-details">
                        <div class="detail-row">
                            <span class="detail-label">Developed by:</span>
                            <span class="detail-value">CvSU Tanza Students</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Institution:</span>
                            <span class="detail-value">Cavite State University - Tanza Campus</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Version:</span>
                            <span class="detail-value">1.0.0</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Year:</span>
                            <span class="detail-value">2026</span>
                        </div>
                    </div>

                    <div class="developer-note">
                        <i class="fas fa-graduation-cap"></i>
                        <p>This system was developed as part of an academic project by students of Cavite State University - Tanza Campus, designed to enhance the educational experience at ASHI Amaya School.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Common Questions -->
        <div class="faq-section">
            <h3 class="faq-title">Frequently Asked Questions</h3>
            <div class="faq-list">
                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>How do I create a new class?</span>
                    </div>
                    <div class="faq-answer">
                        Navigate to the My Subjects section and click the Floating Plus Icon on the lower side then "Create Class". Fill in the class details including name, section, grade, choose theme and logo then click "Create Subject" to generate your class code.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>How do students join my class?</span>
                    </div>
                    <div class="faq-answer">
                        Share the unique class code with your students. They can enter this code in their student portal under "Join Class" to enroll in your class.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>How do I create and post assignments?</span>
                    </div>
                    <div class="faq-answer">
                        Go to the My Subject section, choose a subject to specifically create Task or Assignment, Click "View Details" to view the Subject class or Directly click the "Add Task". If choose view details, navigate the "Create" button then choose what kind of Task, fill in the details, set a due date, add Files if there is, Then Assign. Students will be notified automatically.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>Can I track student progress?</span>
                    </div>
                    <div class="faq-answer">
                        Yes! Visit the Students section to view individual student profiles, grades, assignment completion rates, and overall performance analytics.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>How do I communicate with students?</span>
                    </div>
                    <div class="faq-answer">
                        Use the Messages section to send direct messages to individual students or broadcast announcements to entire classes.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

        </div>
    </main>
<style>

/* Messenger Icon Styles */
.messenger-icon-link {
    text-decoration: none;
    display: inline-block;
}

.messenger-icon {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    transition: all 0.2s;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.messenger-icon:hover {
    background: #eff6ff;
    border-color: #2563eb;
    box-shadow: 0 2px 4px rgba(37, 99, 235, 0.1);
}

.messenger-icon i {
    font-size: 18px;
    color: #64748b;
}

.messenger-icon:hover i {
    color: #2563eb;
}

.messenger-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
}
/* Attachment Item Container with Actions */
.attachment-item-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 8px;
    transition: all 0.2s ease;
}

.attachment-item-container:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
}

.attachment-item {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0; /* Allow text truncation */
}

.attachment-item i.fa-file {
    color: #6b7280;
    font-size: 18px;
    flex-shrink: 0;
}

.attachment-item span:first-of-type {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #1f2937;
    font-weight: 500;
}

/* Attachment Actions Buttons */
.attachment-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.btn-view-file {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 14px;
    background: #3b82f6;
    color: white;
}

.btn-view-file:hover {
    background: #2563eb;
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);
}

.btn-view-file:active {
    transform: translateY(0);
}

/* Icon styling */
.btn-view-file i {
    font-size: 14px;
}

/* Responsive design */
@media (max-width: 640px) {
    .attachment-item-container {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }

    .attachment-actions {
        justify-content: flex-end;
    }

    .btn-view-file {
        width: 40px;
        height: 40px;
    }
}

/* Modal Attachments Section */
#modalAttachments {
    margin-top: 20px;
    padding: 16px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

#modalAttachments h4 {
    margin-bottom: 12px;
    color: #1f2937;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

#modalAttachments h4 i {
    color: #6b7280;
}

#modalAttachmentsList {
    display: flex;
    flex-direction: column;
}

/* Loading and Empty States */
.attachment-loading {
    text-align: center;
    padding: 20px;
    color: #6b7280;
}

.attachment-loading i {
    font-size: 24px;
    margin-bottom: 8px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Tooltip for button */
.btn-view-file[title] {
    position: relative;
}

.btn-view-file:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    padding: 4px 8px;
    background: #1f2937;
    color: white;
    font-size: 12px;
    border-radius: 4px;
    white-space: nowrap;
    margin-bottom: 4px;
    pointer-events: none;
    opacity: 0;
    animation: fadeIn 0.2s ease forwards;
}

@keyframes fadeIn {
    to {
        opacity: 1;
    }
}
</style>
<script src="script/my_subjectjs"></script>
<script src="script/my_section.js"></script>
<script src="my_submission.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle (Desktop collapse/expand)
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const mobileCloseBtn = document.getElementById('mobileCloseBtn');
    const mobileOverlay = document.querySelector('.mobile-overlay');

    // Desktop Sidebar Toggle
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
            }
        });
    }

    // Mobile menu toggle (Open sidebar)
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
            openMobileSidebar();
        });
    }

    // Mobile close button
    if (mobileCloseBtn) {
        mobileCloseBtn.addEventListener('click', function() {
            closeMobileSidebar();
        });
    }

    // Mobile Overlay Click (Close sidebar)
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function() {
            closeMobileSidebar();
        });
    }

    // Close sidebar when clicking outside on mobile (fallback if no overlay)
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                closeMobileSidebar();
            }
        }
    });

    // Functions for mobile sidebar
    function openMobileSidebar() {
        sidebar.classList.add('mobile-open');
        if (mobileOverlay) {
            mobileOverlay.classList.add('active');
        }
        document.body.style.overflow = 'hidden';
    }

    function closeMobileSidebar() {
        sidebar.classList.remove('mobile-open');
        if (mobileOverlay) {
            mobileOverlay.classList.remove('active');
        }
        document.body.style.overflow = '';
    }

    // Section navigation
    const menuLinks = document.querySelectorAll('.menu-link');
    const contentSections = document.querySelectorAll('.content-section');
    const pageTitle = document.getElementById('pageTitle');

    const sectionTitles = {
        'dashboard': 'Dashboard',
        'subjects': 'My Subjects',
        'students': 'My Students',
        'tasks': 'Create Task',
        'task-management': 'Manage Tasks',
        'submissions': 'Submissions',
        'lectures': 'Lectures',
        'grades': 'Grade Management',
        'reports': 'Reports',
        'profile': 'My Profile',
        'help': 'Help & Support'
    };

    menuLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (!this.hasAttribute('data-section')) {
                return;
            }
            
            e.preventDefault();
            
            const targetSection = this.getAttribute('data-section');
            
            menuLinks.forEach(l => l.classList.remove('active'));
            this.classList.add('active');
            
            contentSections.forEach(section => section.classList.remove('active'));
            
            const targetElement = document.getElementById(targetSection);
            if (targetElement) {
                targetElement.classList.add('active');
            }
            
            if (sectionTitles[targetSection]) {
                pageTitle.textContent = sectionTitles[targetSection];
            }
            
            if (targetSection === 'profile') {
                loadProfileData();
            }
            
            if (window.innerWidth <= 768) {
                closeMobileSidebar();
            }
        });
    });

    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768) {
                closeMobileSidebar();
            }
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        }, 250);
    });

    // Keyboard accessibility - ESC to close mobile sidebar
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
            closeMobileSidebar();
        }
    });

    // Load Profile Data
    function loadProfileData() {
        const profileLoading = document.getElementById('profileLoading');
        const profileContent = document.getElementById('profileContent');
        
        profileLoading.style.display = 'block';
        profileContent.style.display = 'none';
        
        fetch('php/get_user_profile.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const profile = data.data;
                    
                    const avatarLarge = document.getElementById('profileAvatarLarge');
                    if (profile.profile_picture && profile.profile_picture.trim() !== '') {
                        let imagePath = profile.profile_picture;
                        if (!imagePath.startsWith('upload')) {
                            imagePath = 'upload/' + imagePath;
                        }
                        avatarLarge.innerHTML = `<img src="${imagePath}" alt="Profile Picture">`;
                    } else {
                        avatarLarge.textContent = profile.initials;
                    }
                    
                    document.getElementById('profileFullname').textContent = profile.fullname;
                    document.getElementById('profileSpecialization').textContent = profile.specialization;
                    document.getElementById('profileAge').textContent = profile.age;
                    document.getElementById('profileMemberSince').textContent = profile.member_since;
                    
                    document.getElementById('infoFullname').textContent = profile.fullname;
                    document.getElementById('infoEmail').textContent = profile.email;
                    document.getElementById('infoPhone').textContent = profile.phone || 'Not provided';
                    document.getElementById('infoGender').textContent = profile.gender || 'Not specified';
                    
                    const birthDate = new Date(profile.birthdate);
                    const formattedDate = birthDate.toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric' 
                    });
                    document.getElementById('infoBirthdate').textContent = formattedDate;
                    document.getElementById('infoAge').textContent = profile.age + ' years old';
                    
                    document.getElementById('infoEducation').textContent = profile.education || 'Not provided';
                    document.getElementById('infoSpecialization').textContent = profile.specialization || 'Not provided';
                    document.getElementById('infoAddress').textContent = profile.address || 'Not provided';
                    
                    const bioElement = document.getElementById('infoBio');
                    if (profile.bio && profile.bio.trim() !== '') {
                        bioElement.textContent = profile.bio;
                        bioElement.classList.remove('empty');
                    } else {
                        bioElement.textContent = 'No bio available.';
                        bioElement.classList.add('empty');
                    }
                    
                    profileLoading.style.display = 'none';
                    profileContent.style.display = 'block';
                } else {
                    profileLoading.innerHTML = `
                        <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>
                        <p style="color: #ef4444;">Failed to load profile: ${data.message}</p>
                    `;
                }
            })
            .catch(error => {
                console.error('Error loading profile:', error);
                profileLoading.innerHTML = `
                    <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>
                    <p style="color: #ef4444;">An error occurred while loading your profile.</p>
                `;
            });
    }

    // Make loadProfileData globally available
    window.loadProfileData = loadProfileData;

    // Check if returning from edit_profile.php and show success toast
    (function checkProfileUpdated() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('profile_updated') === '1') {
            // Clean the URL so refreshing won't re-show the toast
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);

            // Inject toast animation style
            const style = document.createElement('style');
            style.textContent = `
                @keyframes toastIn {
                    from { opacity: 0; transform: translateY(16px); }
                    to   { opacity: 1; transform: translateY(0); }
                }
            `;
            document.head.appendChild(style);

            // Build and show toast
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 28px;
                right: 28px;
                z-index: 9999;
                display: flex;
                align-items: center;
                gap: 12px;
                background: #10b981;
                color: #fff;
                padding: 14px 20px;
                border-radius: 10px;
                font-family: inherit;
                font-size: 14px;
                font-weight: 600;
                box-shadow: 0 8px 24px rgba(16,185,129,0.35);
                animation: toastIn 0.35s ease;
            `;
            toast.innerHTML = `<i class="fas fa-circle-check" style="font-size:18px"></i> Profile updated successfully!`;
            document.body.appendChild(toast);

            // Auto-dismiss after 3.5s
            setTimeout(() => {
                toast.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(10px)';
                setTimeout(() => toast.remove(), 400);
            }, 3500);

            // Reload profile section data if already visible
            if (typeof loadProfileData === 'function') {
                loadProfileData();
            }
        }
    })();

});

// Edit Profile — redirect to edit page
function editProfile() {
    window.location.href = 'edit_profile.php';
}

// Confirm Logout Function
function confirmLogout(event) {
    if (!confirm('Are you sure you want to logout?')) {
        event.preventDefault();
        return false;
    }
    return true;
}

// Fetch unread message count and update badge
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

// System Info Toggle
function toggleSystemInfo() {
    const btn = document.querySelector('.info-toggle-btn');
    const content = document.getElementById('systemInfoContent');
    btn.classList.toggle('active');
    content.classList.toggle('active');
}

// FAQ Accordion
document.addEventListener('DOMContentLoaded', function() {
    const faqItems = document.querySelectorAll('.faq-item');
    
    faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        
        question.addEventListener('click', () => {
            faqItems.forEach(otherItem => {
                if (otherItem !== item) {
                    otherItem.classList.remove('active');
                }
            });
            item.classList.toggle('active');
        });
    });
});

(function() {
    'use strict';
    
    const teacherSubjectModal = document.getElementById("teacherCreateModal");
    const teacherOpenBtn = document.getElementById("teacherOpenCreateModal");
    const teacherCancelBtn = document.getElementById("teacherCancelCreate");
    const teacherCreateBtn = document.getElementById("teacherCreateSubject");
    const teacherSubjectContainer = document.getElementById("teacherSubjectContainer");
    const teacherEmptyState = document.getElementById("teacherSubjectsEmptyState");

    const teacherSubjectName = document.getElementById("teacherSubjectName");
    const teacherSubjectGrade = document.getElementById("teacherSubjectGrade");
    const teacherSubjectSection = document.getElementById("teacherSubjectSection");
    const teacherLogoInput = document.getElementById("teacherSubjectLogo");
    const teacherLogoPreview = document.getElementById("teacherLogoPreview");
    const teacherColorOptions = document.querySelectorAll(".teacher-subject-color-option");

    const teacherSubjectColors = ["#1a73e8","#34a853","#9c27b0","#fb8c00","#e53935","#d81b60","#00897b","#546e7a"];
    let teacherLogoData = "";
    let teacherSelectedColor = "";

    let globalDropdown = null;
    let currentDropdownSubjectId = null;

    function checkTeacherEmptyState() {
        const hasCards = teacherSubjectContainer.querySelectorAll('.teacher-subject-card').length > 0;
        teacherEmptyState.style.display = hasCards ? 'none' : 'block';
    }

    function resetTeacherForm() {
        teacherSubjectName.value = "";
        teacherSubjectGrade.value = "";
        teacherSubjectSection.innerHTML = '<option value="">Select grade first</option>';
        teacherSubjectSection.disabled = true;

        teacherLogoInput.value = "";
        teacherLogoPreview.src = "";
        teacherLogoPreview.classList.add("hidden");
        teacherLogoData = "";

        teacherSelectedColor = "";
        teacherColorOptions.forEach(opt => opt.classList.remove("color-selected"));

        teacherCreateBtn.disabled = true;
    }

    teacherColorOptions.forEach(option => {
        option.addEventListener("click", () => {
            teacherColorOptions.forEach(opt => opt.classList.remove("color-selected"));
            option.classList.add("color-selected");
            teacherSelectedColor = option.dataset.color;
        });
    });

    teacherOpenBtn.onclick = () => {
        resetTeacherForm();
        teacherSubjectModal.classList.remove("hidden");
    };

    teacherCancelBtn.onclick = () => {
        resetTeacherForm();
        teacherSubjectModal.classList.add("hidden");
    };

    teacherSubjectGrade.onchange = async () => {
        const grade = teacherSubjectGrade.value;
        teacherSubjectSection.innerHTML = '<option value="">Loading...</option>';
        teacherSubjectSection.disabled = true;

        if (!grade) {
            teacherSubjectSection.innerHTML = '<option value="">Select grade first</option>';
            validateTeacherForm();
            return;
        }

        try {
            const res = await fetch(`php/teacher_subject_operations.php?action=get_sections&grade=${encodeURIComponent(grade)}`);
            const data = await res.json();

            if (data.success && data.sections.length > 0) {
                teacherSubjectSection.innerHTML = '<option value="">Select section</option>';
                data.sections.forEach(sec => {
                    const opt = document.createElement('option');
                    opt.value = sec.section_name;
                    opt.textContent = sec.section_name;
                    teacherSubjectSection.appendChild(opt);
                });
                teacherSubjectSection.disabled = false;
            } else {
                teacherSubjectSection.innerHTML = '<option value="">No sections available</option>';
            }
        } catch (err) {
            console.error('Error fetching sections:', err);
            teacherSubjectSection.innerHTML = '<option value="">Failed to load sections</option>';
        }

        validateTeacherForm();
    };

    teacherSubjectSection.onchange = validateTeacherForm;
    teacherSubjectName.oninput = validateTeacherForm;

    function validateTeacherForm() {
        teacherCreateBtn.disabled = !teacherSubjectName.value || !teacherSubjectGrade.value || !teacherSubjectSection.value || !teacherLogoData;
    }

    teacherLogoInput.onchange = () => {
        const file = teacherLogoInput.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = () => {
            teacherLogoData = reader.result;
            teacherLogoPreview.src = teacherLogoData;
            teacherLogoPreview.classList.remove("hidden");
            validateTeacherForm();
        };
        reader.readAsDataURL(file);
    };

    function loadTeacherSubjects() {
        fetch('php/teacher_subject_operations.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    teacherSubjectContainer.innerHTML = '';
                    data.subjects.forEach(subject => {
                        createTeacherSubjectCard(subject);
                    });
                    checkTeacherEmptyState();
                } else {
                    console.error('Failed to load subjects:', data.message);
                }
            })
            .catch(err => {
                console.error('Error loading subjects:', err);
            });
    }

    function createTeacherSubjectCard(subject) {
        const card = document.createElement("div");
        card.className = "teacher-subject-card";
        card.dataset.status = subject.status;
        card.dataset.subjectId = subject.id;

        if (subject.status === 'disabled') {
            card.classList.add('subject-disabled');
        }

        const color = subject.theme_color;

        card.innerHTML = `
            <div class="teacher-subject-card-banner" style="background: linear-gradient(135deg, ${color} 0%, ${adjustTeacherColor(color, -20)} 100%);">
                <img src="php/${subject.logo_path}">
                <div class="teacher-subject-banner-info">
                    <strong>${subject.subject_name}</strong>
                    <small>Class Code: ${subject.class_code}</small>
                </div>
                <div class="teacher-subject-menu" data-subject-id="${subject.id}" data-status="${subject.status}">
                    ⋮
                </div>
            </div>

            <div class="teacher-subject-card-body">
                <div class="teacher-subject-info-cards">
                    <div class="teacher-subject-info-item"><i class="fas fa-users"></i>${subject.student_count || 0} Students</div>
                    <div class="teacher-subject-info-item"><i class="fas fa-layer-group"></i>${subject.section_count || 0} Sections</div>
                    <div class="teacher-subject-info-item"><i class="fas fa-tasks"></i>Tasks</div>
                </div>

                <div class="teacher-subject-section-area">
                    <strong>Assigned Sections</strong>
                    <p>${subject.section || 'No sections assigned'}</p>
                </div>

                <div class="teacher-subject-card-actions">
                    <button class="teacher-subject-btn teacher-subject-btn-primary" onclick="viewTeacherSubjectDetails(${subject.id})">View Details</button>
                    <button class="teacher-subject-btn teacher-subject-btn-text" onclick="addTeacherSubjectTask(${subject.id})">Add Task</button>
                </div>
            </div>
        `;

        teacherSubjectContainer.appendChild(card);
    }

    function createGlobalDropdown(subjectId, status, menuButton) {
        if (globalDropdown) {
            globalDropdown.remove();
        }

        const isDisabled = status === 'disabled';

        globalDropdown = document.createElement('div');
        globalDropdown.className = 'teacher-subject-menu-dropdown';
        globalDropdown.innerHTML = `
            <div class="teacher-subject-menu-item toggle-status ${isDisabled ? 'menu-highlight' : ''}" data-subject-id="${subjectId}" data-status="${status}">
                <i class="fas fa-${isDisabled ? 'check-circle' : 'ban'}"></i> 
                ${isDisabled ? 'Enable' : 'Disable'}
            </div>
            <div class="teacher-subject-menu-item menu-danger ${isDisabled ? 'menu-disabled' : ''}" data-subject-id="${subjectId}">
                <i class="fas fa-trash"></i> Remove
            </div>
        `;

        const rect = menuButton.getBoundingClientRect();
        globalDropdown.style.position = 'fixed';
        globalDropdown.style.top = `${rect.bottom + 8}px`;
        globalDropdown.style.left = `${rect.right - 180}px`;
        globalDropdown.style.zIndex = '9999';

        document.body.appendChild(globalDropdown);
        currentDropdownSubjectId = subjectId;
    }

    function closeGlobalDropdown() {
        if (globalDropdown) {
            globalDropdown.remove();
            globalDropdown = null;
            currentDropdownSubjectId = null;
        }
    }

    window.viewTeacherSubjectDetails = function(subjectId) {
        window.location.href = 'php/teacher_view_subject.php?id=' + subjectId;
    };

    window.addTeacherSubjectTask = function(subjectId) {
        window.location.href = '../quiz/quiz_creator.php?subject_id=' + subjectId;
    };

    teacherCreateBtn.onclick = async () => {
        const gradeValue = teacherSubjectGrade.value;
        const color = teacherSelectedColor || teacherSubjectColors[Math.floor(Math.random() * teacherSubjectColors.length)];

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('subject_name', teacherSubjectName.value);
        formData.append('section', teacherSubjectSection.value);
        formData.append('grade', gradeValue);
        formData.append('theme_color', color);
        formData.append('logo_data', teacherLogoData);

        teacherCreateBtn.disabled = true;
        teacherCreateBtn.textContent = 'Creating...';

        try {
            const response = await fetch('php/teacher_subject_operations.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                alert('Subject created successfully! Class Code: ' + data.class_code);
                teacherSubjectModal.classList.add("hidden");
                resetTeacherForm();
                loadTeacherSubjects();
            } else {
                alert('Error: ' + data.message);
            }
        } catch (error) {
            console.error('Error creating subject:', error);
            alert('Failed to create subject. Please try again.');
        } finally {
            teacherCreateBtn.disabled = false;
            teacherCreateBtn.textContent = 'Create Subject';
        }
    };

    function adjustTeacherColor(color, amount) {
        const num = parseInt(color.replace("#", ""), 16);
        const r = Math.max(0, Math.min(255, (num >> 16) + amount));
        const g = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amount));
        const b = Math.max(0, Math.min(255, (num & 0x0000FF) + amount));
        return "#" + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
    }

    document.addEventListener("click", (e) => {
        if (!e.target.closest(".teacher-subject-menu") && !e.target.closest(".teacher-subject-menu-dropdown")) {
            closeGlobalDropdown();
        }
    });

    document.addEventListener("click", (e) => {
        const menu = e.target.closest(".teacher-subject-menu");
        if (menu) {
            e.stopPropagation();
            const subjectId = menu.dataset.subjectId;
            const status = menu.dataset.status;

            if (currentDropdownSubjectId === subjectId && globalDropdown) {
                closeGlobalDropdown();
            } else {
                createGlobalDropdown(subjectId, status, menu);
            }
        }
    });

    document.addEventListener("click", async (e) => {
        if (e.target.closest(".toggle-status")) {
            const item = e.target.closest(".toggle-status");
            const subjectId = item.dataset.subjectId;
            const currentStatus = item.dataset.status;
            const newStatus = currentStatus === "active" ? "disabled" : "active";
            const confirmMsg = newStatus === "disabled" ? "Disable this subject?" : "Enable this subject?";

            if (!confirm(confirmMsg)) return;

            closeGlobalDropdown();

            try {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('subject_id', subjectId);
                formData.append('status', newStatus);

                const response = await fetch('php/teacher_subject_operations.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    loadTeacherSubjects();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error updating status:', error);
                alert('Failed to update status. Please try again.');
            }
        }

        if (e.target.closest(".menu-danger")) {
            const item = e.target.closest(".menu-danger");
            const subjectId = item.dataset.subjectId;

            if (confirm("Remove this subject permanently?")) {
                closeGlobalDropdown();

                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('subject_id', subjectId);

                    const response = await fetch('php/teacher_subject_operations.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        loadTeacherSubjects();
                    } else {
                        alert('Error: ' + data.message);
                    }
                } catch (error) {
                    console.error('Error deleting subject:', error);
                    alert('Failed to delete subject. Please try again.');
                }
            }
        }
    });

    window.addEventListener('scroll', closeGlobalDropdown, true);

    loadTeacherSubjects();
})();
    </script>
</body>
</html>