<?php
session_start();
// Check if user is already logged in
if (isset($_SESSION['teacher_id'])) {
    header("Location: teacher/teacher_dashb.php");
    exit();
} else if (isset($_SESSION['student_id'])) {
    header("Location: student/student_dashb.php");
    exit();
}
// Get error message if exists
$error_message = '';
if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title>Login - ASHI School Management System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/login.css">
    <style>
                        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-image: url("data:image/svg+xml,%3Csvg width='54' height='54' viewBox='0 0 54 54' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cpath d='M27 0v27M54 27H27M27 54V27M0 27h27' stroke='%2320bf6b' stroke-width='2' stroke-opacity='0.12'/%3E%3Cpath d='M13.5 13.5h27v27h-27z' stroke='%2326de81' stroke-width='1.5' stroke-opacity='0.12'/%3E%3C/g%3E%3C/svg%3E");

            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1000px;
            padding: 20px;
        }

        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: fadeInUp 0.6s ease-out;
            display: flex;
            flex-direction: row;
            min-height: 600px;
        }

        .login-image-side {
            flex: 1;
            background: url('img/bg.jpg') center/cover no-repeat;
            position: relative;
            min-height: 600px;
        }

        .login-image-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
            backdrop-filter: blur(3px);
        }

        .image-content {
            position: relative;
            z-index: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: white;
            text-align: center;
        }

        .image-logo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255, 255, 255, 0.3);
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .image-content h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 15px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .image-content p {
            font-size: 1.1rem;
            opacity: 0.95;
            line-height: 1.6;
            max-width: 400px;
        }

        .login-form-side {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .login-header {
            padding: 30px 40px 20px;
            text-align: center;
        }

        .login-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: #1e293b;
        }

        .login-header p {
            font-size: 0.95rem;
            color: #64748b;
            margin: 0;
        }

        .login-body {
            padding: 20px 40px 40px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.1rem;
            z-index: 2;
        }

        .form-control.with-icon {
            padding-left: 45px;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1.1rem;
            transition: color 0.3s;
            z-index: 2;
        }

        .password-toggle:hover {
            color: #6366f1;
        }

        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            border: 2px solid #e2e8f0;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 8px;
        }

        .form-check-input:checked {
            background-color: #6366f1;
            border-color: #6366f1;
        }

        .form-check-label {
            color: #64748b;
            font-size: 0.9rem;
            cursor: pointer;
            user-select: none;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: 2px solid #10b981;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
            border-color: #059669;
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .divider {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }

        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: #e2e8f0;
        }

        .divider span {
            background: white;
            padding: 0 15px;
            color: #94a3b8;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        .register-link {
            text-align: center;
            color: #64748b;
            font-size: 0.95rem;
        }

        .register-link a {
            color: #6366f1;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .register-link a:hover {
            color: #4f46e5;
            text-decoration: underline;
        }

        .forgot-password {
            text-align: right;
            margin-top: -10px;
            margin-bottom: 20px;
        }

        .forgot-password a {
            color: #6366f1;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.3s;
        }

        .forgot-password a:hover {
            color: #4f46e5;
            text-decoration: underline;
        }

        .back-home {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 2;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 10px 20px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
            color: white;
            transform: translateX(-5px);
        }

        .alert {
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 20px;
            border: none;
            font-size: 0.9rem;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .login-card {
                flex-direction: column;
                min-height: auto;
            }

            .login-image-side {
                min-height: 300px;
            }

            .image-content h1 {
                font-size: 2rem;
            }

            .image-content p {
                font-size: 1rem;
            }

            .image-logo {
                width: 90px;
                height: 90px;
            }

            .login-header {
                padding: 25px 30px 15px;
            }

            .login-body {
                padding: 15px 30px 30px;
            }
        }

        @media (max-width: 576px) {
            .login-container {
                padding: 15px;
            }

            .login-header {
                padding: 20px 20px 10px;
            }

            .login-body {
                padding: 10px 20px 30px;
            }

            .back-home {
                top: 10px;
                left: 10px;
            }

            .image-content {
                padding: 30px 20px;
            }

            .btn-back {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
        }

        .modal-content {
            border-radius: 15px;
            border: none;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            border: none;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .user-type-card {
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            height: 100%;
        }
        .user-type-card:hover {
            border-color: #667eea;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.2);
        }
        .user-type-card i {
            font-size: 60px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .user-type-card h4 {
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        .user-type-card p {
            color: #665;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <!--div class="back-home">
        <a href="index.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div-->
    <div class="login-container">
        <div class="login-card">
            <!-- Left Side - Image/Banner -->
            <div class="login-image-side">
                <div class="image-content">
                    <img src="img/ashi.jpg" alt="ASHI Logo" class="image-logo">
                    <h1>ASHI System</h1>
                    <p>Amaya School of Home Industries Task & Workload Management System</p>
                </div>
            </div>
            <!-- Right Side - Login Form -->
            <div class="login-form-side">
                <div class="login-header">
                    <h2>Welcome Back!</h2>
                    <p>Login to access your account</p>
                </div>
                <div class="login-body">
                    <!-- Alert messages -->
                    <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                    <?php endif; ?>
                    <form action="login_process.php" method="POST" id="loginForm">
                        <div class="form-group">
                            <label class="form-label">Username or Email</label>
                            <div class="input-group">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" name="username" class="form-control with-icon" placeholder="Enter your username or email" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" name="password" id="password" class="form-control with-icon" placeholder="Enter your password" required>
                                <button type="button" class="password-toggle" onclick="togglePassword()">
                                    <i class="fas fa-eye" id="toggleIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="forgot-password">
                            <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                                <i class="fas fa-key"></i> Forgot Password?
                            </a>
                            &nbsp;|&nbsp;
                            <a href="change_password.php">
                                <i class="fas fa-lock"></i> Change Password
                            </a>
                        </div>
                        <!--div class="form-check">
                            <input type="checkbox" name="remember" class="form-check-input" id="remember">
                            <label class="form-check-label" for="remember">
                                Remember me on this device
                            </label>
                        </div-->
                        <button type="submit" class="btn-login">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </button>
                    </form>
                    <!--div class="divider">
                        <span>OR</span>
                    </div-->
                    <!--div class="register-link">
                        Don't have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#registrationModal">Register here</a>
                    </div-->
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">
                        <i class="fas fa-key"></i> Forgot Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <i class="fas fa-user-shield" style="font-size:50px; color:#667eea; margin-bottom:20px; display:block;"></i>
                    <p class="mb-0" style="color:#475569; font-size:.95rem; line-height:1.7;">
                        Please contact your <strong>adviser or subject teacher</strong> and inform them so they can coordinate with the <strong>Administrator</strong> to reset or provide your password.
                    </p>
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4">
                    <button type="button" class="btn-login" style="width:auto; padding:10px 30px;" data-bs-dismiss="modal">
                        Got it
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Type Modal -->
    <div class="modal fade" id="registrationModal" tabindex="-1" aria-labelledby="registrationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="registrationModalLabel">
                        <i class="fas fa-user-plus"></i> Choose Account Type
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-center mb-4">Select the type of account you want to register</p>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <a href="teacher/teacher_form.php" class="text-decoration-none">
                                <div class="user-type-card">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <h4>Teacher</h4>
                                    <p>Register as a teacher to manage courses, assignments, and student progress</p>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="student/student_form.php" class="text-decoration-none">
                                <div class="user-type-card">
                                    <i class="fas fa-user-graduate"></i>
                                    <h4>Student</h4>
                                    <p>Register as a student to access courses, submit assignments, and track your progress</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.querySelector('input[name="username"]').value.trim();
            const password = document.querySelector('input[name="password"]').value;
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
            }
        });
    </script>
</body>
</html>