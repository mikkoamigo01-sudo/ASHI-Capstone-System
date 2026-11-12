<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'u304756553_ashi_classroom';
$db_username = 'u304756553_ashi_classroom'; // Change to your database username
$db_password = 'Ashi_Classroom17'; // Change to your database password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);
    
    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = "Please fill in all fields";
        header("Location: login.php");
        exit();
    }
    
    $user_found = false;
    $user_type = '';
    $user_data = null;
    
    // Check in teachers table first
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE email = ? LIMIT 1");
    $stmt->execute([$username]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($teacher) {
        // Check if account is archived
        if ($teacher['status'] === 'archived') {
            $_SESSION['login_error'] = "Your account has been archived and is no longer active. Please contact the administrator for assistance.";
            header("Location: login.php");
            exit();
        }
        
        // Verify password
        if (password_verify($password, $teacher['password'])) {
            $user_found = true;
            $user_type = 'teacher';
            $user_data = $teacher;
        }
    }
    
    // If not found in teachers, check in students table
    if (!$user_found) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            // Check if account is archived
            if ($student['status'] === 'archived') {
                $_SESSION['login_error'] = "Your account has been deactivated or lost and is no longer active. Please contact the administrator through your known Teacher for assistance.";
                header("Location: login.php");
                exit();
            }
            
            // Verify password
            if (password_verify($password, $student['password'])) {
                $user_found = true;
                $user_type = 'student';
                $user_data = $student;
            }
        }
    }
    
    if ($user_found) {
        // Set session variables based on user type
        if ($user_type == 'teacher') {
            $_SESSION['teacher_id'] = $user_data['id'];
            $_SESSION['teacher_name'] = $user_data['fullname'];
            $_SESSION['teacher_email'] = $user_data['email'];
            $_SESSION['user_type'] = 'teacher';
            
            // Set remember me cookie if checked
            if ($remember) {
                setcookie('remember_teacher', $user_data['id'], time() + (86400 * 30), "/"); // 30 days
            }
            
            // Redirect to teacher dashboard
            header("Location: teacher/teacher_dashb.php");
            exit();
            
        } else if ($user_type == 'student') {
            $_SESSION['student_id'] = $user_data['id'];
            $_SESSION['student_name'] = $user_data['first_name'] . ' ' . $user_data['last_name'];
            $_SESSION['student_email'] = $user_data['email'];
            $_SESSION['student_username'] = $user_data['username'];
            $_SESSION['user_type'] = 'student';
            
            // Set remember me cookie if checked
            if ($remember) {
                setcookie('remember_student', $user_data['id'], time() + (86400 * 30), "/"); // 30 days
            }
            
            // Redirect to student dashboard
            header("Location: student/student_dashb.php");
            exit();
        }
    } else {
        // Login failed - incorrect credentials
        $_SESSION['login_error'] = "Invalid username/email or password. Please try again.";
        header("Location: login.php");
        exit();
    }
} else {
    // If accessed directly without POST, redirect to login
    header("Location: login.php");
    exit();
}
?>