<?php
session_start();

require_once '../db_connection/ashi_dbcnnt.php';
// $conn is now a mysqli object from ashi_dbcnnt.php

// Handle AJAX email check
if (isset($_POST['check_email'])) {
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);

    $stmt1 = $conn->prepare("SELECT id FROM students WHERE email = ?");
    $stmt1->bind_param('s', $email);
    $stmt1->execute();
    $stmt1->store_result();

    $stmt2 = $conn->prepare("SELECT id FROM teachers WHERE email = ?");
    $stmt2->bind_param('s', $email);
    $stmt2->execute();
    $stmt2->store_result();

    if ($stmt1->num_rows > 0 || $stmt2->num_rows > 0) {
        echo json_encode(['exists' => true]);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['check_email'])) {
    $fullname         = trim($_POST['fullname']);
    $birthdate        = trim($_POST['birthdate']);
    $gender           = trim($_POST['gender']);
    $phone            = trim($_POST['phone']);
    $email            = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $address          = trim($_POST['address']);
    $education        = trim($_POST['education']);
    $specialization   = trim($_POST['specialization']);
    $bio              = trim($_POST['bio']);
    $password         = $_POST['password'];
    $confirm          = $_POST['confirm'];
    $security_question = trim($_POST['security_question']);
    $theme_color      = trim($_POST['theme_color']);

    // Validate passwords match
    if ($password !== $confirm) {
        $_SESSION['error'] = 'Passwords do not match.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email address.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Validate Gmail format
    if (!preg_match('/@gmail\.com$/i', $email)) {
        $_SESSION['error'] = 'Only Gmail addresses are allowed. Please use a valid @gmail.com email.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Validate phone number (11 digits)
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone_clean) !== 11) {
        $_SESSION['error'] = 'Phone number must be exactly 11 digits.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Validate password length
    if (strlen($password) < 6) {
        $_SESSION['error'] = 'Password must be at least 6 characters long.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Require profile picture
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Profile picture is required.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Handle profile picture upload
    $profile_picture = null;
    $upload_dir = 'upload/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = $_FILES['profile_picture']['type'];

    if (in_array($file_type, $allowed_types)) {
        $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
        $new_filename   = 'teacher_' . time() . '_' . uniqid() . '.' . $file_extension;
        $upload_path    = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
            $profile_picture = $upload_path;
        } else {
            $_SESSION['error'] = 'Failed to upload profile picture.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    } else {
        $_SESSION['error'] = 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Check if email already exists
    $chk1 = $conn->prepare("SELECT id FROM students WHERE email = ?");
    $chk1->bind_param('s', $email);
    $chk1->execute();
    $chk1->store_result();

    $chk2 = $conn->prepare("SELECT id FROM teachers WHERE email = ?");
    $chk2->bind_param('s', $email);
    $chk2->execute();
    $chk2->store_result();

    if ($chk1->num_rows > 0 || $chk2->num_rows > 0) {
        $_SESSION['error'] = 'Email already exists.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Check if fullname already exists
    $chk3 = $conn->prepare("SELECT id FROM teachers WHERE fullname = ?");
    $chk3->bind_param('s', $fullname);
    $chk3->execute();
    $chk3->store_result();

    if ($chk3->num_rows > 0) {
        $_SESSION['error'] = 'A teacher with this name already exists. Please use a different name.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Insert into database
    $stmt = $conn->prepare("
        INSERT INTO teachers (
            fullname, birthdate, gender, phone, email, address,
            education, specialization, bio, password, security_question,
            theme_color, profile_picture, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param(
        'sssssssssssss',
        $fullname, $birthdate, $gender, $phone, $email, $address,
        $education, $specialization, $bio, $hashed_password,
        $security_question, $theme_color, $profile_picture
    );

    if ($stmt->execute()) {
        $_SESSION['success'] = true;
        $_SESSION['teacher_id'] = $conn->insert_id;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
        exit();
    } else {
        $_SESSION['error'] = 'Registration failed: ' . $conn->error;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

$show_success = isset($_GET['success']) && $_GET['success'] == 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
  <title>Teacher Registration Form</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
        * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    :root {
      --theme-color: #2563eb;
      --theme-color-dark: #1d4ed8;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Ccircle fill='%23f093fb' fill-opacity='0.1' cx='30' cy='30' r='10'/%3E%3Ccircle fill='%234facfe' fill-opacity='0.1' cx='0' cy='0' r='10'/%3E%3Ccircle fill='%23f093fb' fill-opacity='0.1' cx='60' cy='0' r='10'/%3E%3Ccircle fill='%234facfe' fill-opacity='0.1' cx='0' cy='60' r='10'/%3E%3Ccircle fill='%23f093fb' fill-opacity='0.1' cx='60' cy='60' r='10'/%3E%3C/g%3E%3C/svg%3E");h='0.5' stroke-opacity='0.1'/%3E%3C/g%3E%3C/svg%3E");
      color: #1e293b;
      line-height: 1.6;
      padding: 30px 20px;
    }

    .container {
      max-width: 950px;
      margin: 0 auto;
    }

    /* Profile Section */
    .profile-card {
      background: white;
      border-radius: 15px;
      padding: 30px;
      margin-bottom: 25px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      display: flex;
      align-items: center;
      gap: 25px;
    }

    .profile-img-wrapper {
      position: relative;
      flex-shrink: 0;
    }

    .profile-img {
      width: 120px;
      height: 120px;
      border-radius: 15px;
      object-fit: cover;
      border: 4px solid var(--theme-color);
    }

    .camera-btn {
      position: absolute;
      bottom: 0;
      right: 0;
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, var(--theme-color) 0%, var(--theme-color-dark) 100%);
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
      transition: all 0.3s;
    }

    .camera-btn:hover {
      transform: scale(1.1);
    }

    .camera-btn input {
      display: none;
    }

    .profile-info {
      flex: 1;
    }

    .profile-name {
      font-size: 1.8rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 8px;
    }

    .profile-details {
      display: flex;
      flex-direction: column;
      gap: 5px;
      color: #64748b;
      font-size: 0.95rem;
    }

    .profile-details .detail-item {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .profile-details .detail-item i {
      color: var(--theme-color);
      width: 16px;
    }

    .profile-bio {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid #e2e8f0;
      font-size: 0.85rem;
      color: #64748b;
      line-height: 1.5;
      font-style: italic;
    }

    /* Card Styles */
    .card {
      background: white;
      border-radius: 15px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      margin-bottom: 25px;
      overflow: hidden;
    }

    .card-header {
      padding: 25px;
      border-bottom: 1px solid #f1f5f9;
    }

    .card-title {
      font-size: 1.2rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 12px;
      color: #1e293b;
    }

    .card-title i {
      color: var(--theme-color);
    }

    .card-body {
      padding: 25px;
    }

    /* Form Grid */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 20px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .form-group.full-width {
      grid-column: 1 / -1;
    }

    label {
      font-size: 0.9rem;
      font-weight: 500;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    label i {
      font-size: 0.8rem;
      color: #64748b;
    }

    input[type="text"],
    input[type="email"],
    input[type="tel"],
    input[type="date"],
    input[type="password"],
    select,
    textarea {
      padding: 12px 15px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      font-size: 0.95rem;
      font-family: 'Poppins', sans-serif;
      color: #1e293b;
      transition: all 0.3s;
      outline: none;
    }

    input:focus,
    select:focus,
    textarea:focus {
      border-color: var(--theme-color);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    input:read-only {
      background: #f8fafc;
      color: #64748b;
      cursor: not-allowed;
    }

    textarea {
      resize: vertical;
      min-height: 100px;
    }

    /* Password Field */
    .password-wrapper {
      position: relative;
    }

    .password-toggle {
      position: absolute;
      right: 15px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: #64748b;
      transition: color 0.3s;
    }

    .password-toggle:hover {
      color: var(--theme-color);
    }

    /* Theme Selector */
    .theme-selector {
      display: flex;
      gap: 15px;
      flex-wrap: wrap;
    }

    .theme-option {
      flex: 1;
      min-width: 120px;
      padding: 15px;
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.3s;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
    }

    .theme-option:hover {
      border-color: var(--theme-color);
      transform: translateY(-2px);
    }

    .theme-option.active {
      border-color: var(--theme-color);
      background: #eff6ff;
    }

    .theme-color-circle {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      border: 3px solid white;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .theme-name {
      font-size: 0.9rem;
      font-weight: 500;
      color: #64748b;
    }

    .theme-option.active .theme-name {
      color: var(--theme-color);
    }

    /* Checkbox Styles */
    .checkbox-group {
      display: flex;
      flex-direction: column;
      gap: 15px;
    }

    .checkbox-item {
      display: flex;
      align-items: flex-start;
      gap: 12px;
    }

    .checkbox-item input[type="checkbox"] {
      width: 20px;
      height: 20px;
      cursor: pointer;
      accent-color: var(--theme-color);
      margin-top: 2px;
      flex-shrink: 0;
    }

    .checkbox-item label {
      font-size: 0.9rem;
      font-weight: 400;
      color: #64748b;
      cursor: pointer;
      line-height: 1.5;
    }

    /* Info Box */
    .info-box {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 15px;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 20px;
    }

    .info-box i {
      color: var(--theme-color);
      font-size: 1.2rem;
      flex-shrink: 0;
    }

    .info-box p {
      color: #1e40af;
      font-size: 0.9rem;
      line-height: 1.6;
    }

    /* Button Styles */
    .btn-group {
      display: flex;
      gap: 15px;
      margin-top: 30px;
    }

    .btn {
      padding: 14px 28px;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: all 0.3s;
      font-family: 'Poppins', sans-serif;
      flex: 1;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--theme-color) 0%, var(--theme-color-dark) 100%);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }

    .btn-secondary {
      background: #f1f5f9;
      color: #64748b;
    }

    .btn-secondary:hover {
      background: #e2e8f0;
      color: #1e293b;
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      justify-content: center;
      align-items: center;
      z-index: 1000;
    }

    .modal-content {
      background: white;
      padding: 40px;
      border-radius: 15px;
      text-align: center;
      max-width: 400px;
      width: 90%;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    }

    .modal-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, var(--theme-color) 0%, var(--theme-color-dark) 100%);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      font-size: 2.5rem;
      color: white;
    }

    .modal-content h2 {
      font-size: 1.5rem;
      color: #1e293b;
      margin-bottom: 10px;
    }

    .modal-content p {
      color: #64748b;
      font-size: 0.95rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      body {
        padding: 20px 15px;
      }

      .profile-card {
        flex-direction: column;
        text-align: center;
      }

      .profile-info {
        width: 100%;
      }

      .profile-details {
        align-items: center;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }

      .btn-group {
        flex-direction: column;
      }

      .theme-selector {
        flex-direction: column;
      }
    }
    .error-message {
      color: #dc2626;
      font-size: 0.875rem;
      margin-top: 0.25rem;
      display: none;
    }
    .error-message.show {
      display: block;
    }
    .input-error {
      border-color: #dc2626 !important;
    }
  </style>
</head>
<body>
  <div class="container">
    <?php if (isset($_SESSION['error'])): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <!-- Registration Form - FORM STARTS HERE -->
    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data" onsubmit="return validateForm(event)">
      
      <!-- Hidden field for theme color -->
      <input type="hidden" name="theme_color" id="theme_color_input" value="#2563eb">

      <!-- Profile Picture Section - INSIDE FORM -->
      <div class="profile-card">
        <div class="profile-img-wrapper">
          <img id="preview" src="https://via.placeholder.com/120" alt="Profile" class="profile-img">
          <label class="camera-btn">
            <i class="fas fa-camera"></i>
            <input type="file" name="profile_picture" id="profile_picture" accept="image/*" onchange="previewImg(event)">
          </label>
        </div>
        <div class="profile-info">
          <h2 class="profile-name" id="displayName">Teacher Name</h2>
          <div class="profile-details">
            <div class="detail-item">
              <i class="fas fa-envelope"></i>
              <span id="displayEmail">Email Address</span>
            </div>
          </div>
          <div class="profile-bio" id="displayBio">Brief professional biography...</div>
          <div style="margin-top: 10px; color: #dc2626; font-size: 13px; font-weight: 500;">
            <i class="fas fa-asterisk" style="font-size: 8px;"></i> Profile picture is required
          </div>
        </div>
      </div>

      <!-- Personal Information -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-user"></i>
            Personal Information
          </h3>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <div class="form-group">
              <label><i class="fas fa-id-card"></i> Full Name</label>
              <input type="text" name="fullname" id="fullname" placeholder="Enter full name" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-calendar"></i> Date of Birth</label>
              <input type="date" name="birthdate" id="birthdate" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-venus-mars"></i> Gender</label>
              <select name="gender" id="gender" required>
                <option value="">Select gender</option>
                <option>Male</option>
                <option>Female</option>
                <option>Other</option>
                <option>Prefer not to say</option>
              </select>
            </div>
            <div class="form-group">
              <label><i class="fas fa-phone"></i> Phone Number (11 digits)</label>
              <input type="tel" name="phone" id="phone" placeholder="09123456789" required maxlength="13">
              <small style="color: #64748b; font-size: 0.85rem;">Format: 09XXXXXXXXX (11 digits)</small>
            </div>
            <div class="form-group">
              <label><i class="fas fa-envelope"></i> Email Address (Gmail only)</label>
              <input type="email" name="email" id="email" placeholder="yourname@gmail.com" required>
              <span class="error-message" id="email-error">
                <i class="fas fa-exclamation-circle"></i> Email already exists
              </span>
              <small style="color: #64748b; font-size: 0.85rem;">Must be a valid @gmail.com address</small>
            </div>
            <div class="form-group full-width">
              <label><i class="fas fa-home"></i> Home Address</label>
              <input type="text" name="address" id="address" placeholder="Complete address" required>
            </div>
          </div>
        </div>
      </div>

      <!-- Professional Information -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-briefcase"></i>
            Professional Information
          </h3>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <div class="form-group">
              <label><i class="fas fa-graduation-cap"></i> Highest Educational Attainment</label>
              <input type="text" name="education" id="education" placeholder="e.g., Master's Degree" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-award"></i> Specialization</label>
              <input type="text" name="specialization" id="specialization" placeholder="e.g., Mathematics Education" required>
            </div>
            <div class="form-group full-width">
              <label><i class="fas fa-file-alt"></i> Short Bio</label>
              <textarea name="bio" id="bio" placeholder="Brief professional biography highlighting your teaching experience and expertise..." rows="4"></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Account Security -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-lock"></i>
            Account Security
          </h3>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <div class="form-group">
              <label><i class="fas fa-key"></i> Password</label>
              <div class="password-wrapper">
                <input type="password" name="password" id="password" placeholder="Enter password" required>
                <i class="fas fa-eye password-toggle" onclick="togglePass('password')"></i>
              </div>
            </div>
            <div class="form-group">
              <label><i class="fas fa-check-circle"></i> Confirm Password</label>
              <div class="password-wrapper">
                <input type="password" name="confirm" id="confirm" placeholder="Re-enter password" required>
                <i class="fas fa-eye password-toggle" onclick="togglePass('confirm')"></i>
              </div>
            </div>
            <div class="form-group full-width">
              <label><i class="fas fa-question-circle"></i> Security Question <span style="color: #64748b;">(optional)</span></label>
              <input type="text" name="security_question" id="security" placeholder="e.g., What is your favorite book?">
            </div>
          </div>
        </div>
      </div>

      <!-- Theme Settings -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-palette"></i>
            Theme Settings
          </h3>
        </div>
        <div class="card-body">
          <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">Choose your preferred dashboard theme color</p>
          <div class="theme-selector">
            <div class="theme-option active" onclick="changeTheme('#2563eb', this)">
              <div class="theme-color-circle" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);"></div>
              <span class="theme-name">Blue</span>
            </div>
            <div class="theme-option" onclick="changeTheme('#16a34a', this)">
              <div class="theme-color-circle" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);"></div>
              <span class="theme-name">Green</span>
            </div>
            <div class="theme-option" onclick="changeTheme('#7c3aed', this)">
              <div class="theme-color-circle" style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);"></div>
              <span class="theme-name">Purple</span>
            </div>
            <div class="theme-option" onclick="changeTheme('#dc2626', this)">
              <div class="theme-color-circle" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);"></div>
              <span class="theme-name">Red</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Confirmation -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-file-contract"></i>
            Confirmation
          </h3>
        </div>
        <div class="card-body">
          <div class="checkbox-group">
            <div class="checkbox-item">
              <input type="checkbox" id="confirm1" required>
              <label for="confirm1">I confirm that all the information provided above is <strong>accurate and complete</strong></label>
            </div>
            <div class="checkbox-item">
              <input type="checkbox" id="confirm2" required>
              <label for="confirm2">I agree to the <strong>School Data Privacy Policy</strong> and consent to the collection and use of my personal information</label>
            </div>
            <div class="checkbox-item">
              <input type="checkbox" id="confirm3" required>
              <label for="confirm3">I agree to abide by the <strong>Faculty Code of Conduct</strong> and professional teaching standards</label>
            </div>
          </div>
        </div>
      </div>

      <!-- Submit Buttons -->
      <div class="btn-group">
        <button type="button" class="btn btn-secondary" onclick="resetForm()">
          <i class="fas fa-redo"></i>
          Reset Form
        </button>
        <button type="submit" class="btn btn-primary" id="submit-btn">
          <i class="fas fa-save"></i>
          Save Registration
        </button>
      </div>
    </form>
    <!-- FORM ENDS HERE -->

  </div>

  <!-- Success Modal -->
  <div class="modal" id="modal" <?php echo $show_success ? 'style="display: flex;"' : ''; ?>>
    <div class="modal-content">
      <div class="modal-icon">
        <i class="fas fa-check"></i>
      </div>
      <h2>Registration Saved!</h2>
      <p>Your teacher account has been created. Redirecting to dashboard...</p>
    </div>
  </div>

  <script>
    <?php if ($show_success): ?>
    setTimeout(() => {
      window.location.href = '../login.php';
    }, 2000);
    <?php endif; ?>

    let emailCheckTimeout;
    let emailExists = false;

    // Check if email exists
    function checkEmailExists(email) {
      clearTimeout(emailCheckTimeout);
      
      emailCheckTimeout = setTimeout(() => {
        if (email.length > 0 && email.includes('@')) {
          fetch('<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'check_email=1&email=' + encodeURIComponent(email)
          })
          .then(response => response.json())
          .then(data => {
            const emailInput = document.getElementById('email');
            const emailError = document.getElementById('email-error');
            const submitBtn = document.getElementById('submit-btn');
            
            if (data.exists) {
              emailExists = true;
              emailInput.classList.add('input-error');
              emailError.classList.add('show');
              submitBtn.disabled = true;
              submitBtn.style.opacity = '0.5';
              submitBtn.style.cursor = 'not-allowed';
            } else {
              emailExists = false;
              emailInput.classList.remove('input-error');
              emailError.classList.remove('show');
              submitBtn.disabled = false;
              submitBtn.style.opacity = '1';
              submitBtn.style.cursor = 'pointer';
            }
          })
          .catch(error => console.error('Error:', error));
        }
      }, 500);
    }

    // Email input listener
    document.getElementById('email').addEventListener('input', function() {
      checkEmailExists(this.value);
      updateDisplay();
    });

    // Preview profile image
    function previewImg(e) {
      const file = e.target.files[0];
      const preview = document.getElementById('preview');
      
      if (file) {
        // Validate file type
        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!validTypes.includes(file.type)) {
          console.error('❌ Invalid file type:', file.type);
          alert('❌ Please upload a valid image file (JPEG, PNG, GIF, or WEBP)');
          e.target.value = '';
          return;
        }
        
        // Validate file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
          console.error('❌ File size too large:', (file.size / 1024 / 1024).toFixed(2) + 'MB');
          alert('❌ Image size must be less than 5MB');
          e.target.value = '';
          return;
        }
        
        console.log('✅ Profile picture selected:', file.name, '-', (file.size / 1024).toFixed(2) + 'KB');
        
        // Preview the image
        const reader = new FileReader();
        reader.onload = function(event) {
          preview.src = event.target.result;
        };
        reader.readAsDataURL(file);
      }
    }

    // Toggle password visibility
    function togglePass(id) {
      const input = document.getElementById(id);
      const icon = event.target;
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }

    // Update display preview
    function updateDisplay() {
      const fullname = document.getElementById('fullname').value;
      const email = document.getElementById('email').value;
      const bio = document.getElementById('bio').value;

      if (fullname) {
        document.getElementById('displayName').textContent = fullname;
      }

      if (email) {
        document.getElementById('displayEmail').textContent = email;
      }

      if (bio) {
        document.getElementById('displayBio').textContent = bio;
      }
    }

    // Event listeners for real-time updates
    document.getElementById('fullname').addEventListener('input', updateDisplay);
    document.getElementById('bio').addEventListener('input', updateDisplay);

    // Change theme color
    function changeTheme(color, element) {
      // Update theme color
      document.documentElement.style.setProperty('--theme-color', color);
      
      // Update hidden input
      document.getElementById('theme_color_input').value = color;
      
      // Determine darker shade
      let darkColor = color;
      if (color === '#2563eb') darkColor = '#1d4ed8';
      else if (color === '#16a34a') darkColor = '#059669';
      else if (color === '#7c3aed') darkColor = '#6d28d9';
      else if (color === '#dc2626') darkColor = '#b91c1c';
      
      document.documentElement.style.setProperty('--theme-color-dark', darkColor);
      
      // Update active state
      document.querySelectorAll('.theme-option').forEach(opt => {
        opt.classList.remove('active');
      });
      element.classList.add('active');
    }

    // Reset form
    function resetForm() {
      if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.querySelector('form').reset();
        document.getElementById('preview').src = 'https://via.placeholder.com/120';
        document.getElementById('displayName').textContent = 'Teacher Name';
        document.getElementById('displayEmail').textContent = 'Email Address';
        document.getElementById('displayBio').textContent = 'Brief professional biography...';
        document.getElementById('email-error').classList.remove('show');
        document.getElementById('email').classList.remove('input-error');
        emailExists = false;
        document.getElementById('submit-btn').disabled = false;
        document.getElementById('submit-btn').style.opacity = '1';
        document.getElementById('submit-btn').style.cursor = 'pointer';
        // Reset theme to blue
        changeTheme('#2563eb', document.querySelector('.theme-option'));
        
        console.log('ℹ️ Form has been reset');
      }
    }

    // Validate form before submission
    function validateForm(e) {
      console.log('🔍 Starting form validation...');
      
      // Check if profile picture is required and uploaded
      const fileInput = document.getElementById('profile_picture');
      
      if (fileInput.files.length === 0) {
        console.error('❌ VALIDATION FAILED: Profile picture is required');
        
        // Show modal alert
        showProfileRequiredModal();
        
        e.preventDefault();
        return false;
      }
      
      console.log('✅ Profile picture check passed');
      
      // Validate email is Gmail
      const email = document.getElementById('email').value;
      if (!email.toLowerCase().endsWith('@gmail.com')) {
        console.error('❌ VALIDATION FAILED: Email must be Gmail');
        alert('❌ Only Gmail addresses are allowed. Please use a valid @gmail.com email.');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ Gmail format check passed');
      
      // Validate phone number (11 digits)
      const phone = document.getElementById('phone').value;
      const phoneClean = phone.replace(/[^0-9]/g, '');
      if (phoneClean.length !== 11) {
        console.error('❌ VALIDATION FAILED: Phone must be 11 digits, got', phoneClean.length);
        alert('❌ Phone number must be exactly 11 digits.\nCurrent: ' + phoneClean.length + ' digits\nPhone: ' + phone);
        e.preventDefault();
        return false;
      }
      
      console.log('✅ Phone number check passed (11 digits)');
      
      if (emailExists) {
        console.error('❌ VALIDATION FAILED: Email already exists');
        alert('❌ Email already exists. Please use a different email address.');
        e.preventDefault();
        return false;
      }

      console.log('✅ Email check passed');

      const password = document.getElementById('password').value;
      const confirm = document.getElementById('confirm').value;
      
      if (password !== confirm) {
        console.error('❌ VALIDATION FAILED: Passwords do not match');
        alert('❌ Passwords do not match. Please check and try again.');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ Password match check passed');
      
      if (password.length < 6) {
        console.error('❌ VALIDATION FAILED: Password too short -', password.length, 'characters');
        alert('❌ Password must be at least 6 characters long.');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ Password length check passed');
      
      // Validate profile picture file type and size
      const file = fileInput.files[0];
      const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
      
      if (!validTypes.includes(file.type)) {
        console.error('❌ VALIDATION FAILED: Invalid file type -', file.type);
        alert('❌ Please upload a valid image file (JPEG, PNG, GIF, or WEBP)');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ File type check passed');
      
      if (file.size > 5 * 1024 * 1024) {
        console.error('❌ VALIDATION FAILED: File size too large -', (file.size / 1024 / 1024).toFixed(2) + 'MB');
        alert('❌ Image size must be less than 5MB');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ File size check passed');
      console.log('✅✅✅ ALL VALIDATION CHECKS PASSED ✅✅✅');
      console.log('📤 Submitting form with profile picture:', file.name, '-', (file.size / 1024).toFixed(2) + 'KB');
      
      return true;
    }

    // Show profile picture required modal
    function showProfileRequiredModal() {
      console.log('📢 Displaying profile picture required modal');
      
      // Create modal overlay
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay-custom';
      overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        animation: fadeIn 0.3s ease;
      `;
      
      // Create modal content
      const modal = document.createElement('div');
      modal.style.cssText = `
        background: white;
        padding: 30px;
        border-radius: 16px;
        max-width: 400px;
        width: 90%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: slideUp 0.3s ease;
      `;
      
      modal.innerHTML = `
        <div style="
          width: 80px;
          height: 80px;
          background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 0 auto 20px;
        ">
          <i class="fas fa-camera" style="font-size: 36px; color: white;"></i>
        </div>
        <h3 style="
          font-size: 24px;
          font-weight: 700;
          color: #1f2937;
          margin-bottom: 12px;
        ">Profile Picture Required</h3>
        <p style="
          color: #6b7280;
          font-size: 16px;
          line-height: 1.6;
          margin-bottom: 24px;
        ">
          Please upload a profile picture before submitting the registration form.
        </p>
        <button onclick="closeProfileModal()" style="
          background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
          color: white;
          border: none;
          padding: 12px 32px;
          border-radius: 8px;
          font-size: 16px;
          font-weight: 600;
          cursor: pointer;
          transition: all 0.3s ease;
        " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 16px rgba(59, 130, 246, 0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
          <i class="fas fa-check"></i> Got it
        </button>
      `;
      
      overlay.appendChild(modal);
      document.body.appendChild(overlay);
      
      // Add animations
      const style = document.createElement('style');
      style.textContent = `
        @keyframes fadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        @keyframes slideUp {
          from { 
            opacity: 0;
            transform: translateY(20px);
          }
          to { 
            opacity: 1;
            transform: translateY(0);
          }
        }
        @keyframes fadeOut {
          from { opacity: 1; }
          to { opacity: 0; }
        }
      `;
      document.head.appendChild(style);
      
      // Scroll to profile picture section
      setTimeout(() => {
        document.querySelector('.profile-card').scrollIntoView({ 
          behavior: 'smooth', 
          block: 'center' 
        });
        console.log('📍 Scrolled to profile picture section');
      }, 300);
    }

    // Close profile required modal
    function closeProfileModal() {
      console.log('❌ Closing profile picture required modal');
      const overlay = document.querySelector('.modal-overlay-custom');
      if (overlay) {
        overlay.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => overlay.remove(), 300);
      }
    }

    // Log when form loads
    console.log('📋 Teacher Registration Form loaded');
    console.log('⚠️ Profile picture is REQUIRED for registration');
    
    // Add test function in console (for debugging)
    window.testProfileModal = function() {
      console.log('🧪 Testing profile modal...');
      showProfileRequiredModal();
    };
    console.log('💡 Tip: Type testProfileModal() in console to test the modal');
  </script>
</body>
</html>