<?php
session_start();
require_once '../db_connection/ashi_dbcnnt.php';

// Handle session clear
if (isset($_POST['clear_session'])) {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true]);
    exit();
}

// Handle AJAX email check
if (isset($_POST['check_email'])) {
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);

    $stmt1 = $conn->prepare("SELECT id FROM students WHERE email = ?");
    $stmt1->bind_param("s", $email);
    $stmt1->execute();
    $stmt1->store_result();

    $stmt2 = $conn->prepare("SELECT id FROM teachers WHERE email = ?");
    $stmt2->bind_param("s", $email);
    $stmt2->execute();
    $stmt2->store_result();

    echo json_encode(['exists' => ($stmt1->num_rows > 0 || $stmt2->num_rows > 0)]);
    $stmt1->close();
    $stmt2->close();
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['check_email']) && !isset($_POST['clear_session'])) {
    $fname            = trim($_POST['fname']);
    $lname            = trim($_POST['lname']);
    $email            = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $username         = trim($_POST['username']);
    $grade            = trim($_POST['grade']);
    $section          = trim($_POST['section']);
    $birthdate        = trim($_POST['birthdate']);
    $contact          = trim($_POST['contact']);
    $guardian_name    = trim($_POST['guardian_name']);
    $relationship     = trim($_POST['relationship']);
    $guardian_contact = trim($_POST['guardian_contact']);
    $password         = $_POST['password'];
    $confirm          = $_POST['confirm'];
    $bio              = trim($_POST['bio']);

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

    // Validate password length
    if (strlen($password) < 6) {
        $_SESSION['error'] = 'Password must be at least 6 characters long.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Profile picture is required
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Profile picture is required.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Handle profile picture upload
    $profile_picture = null;
    $upload_dir      = 'uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type     = $_FILES['profile_picture']['type'];

    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['error'] = 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $new_filename   = $username . '_' . time() . '.' . $file_extension;
    $upload_path    = $upload_dir . $new_filename;

    if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
        $_SESSION['error'] = 'Failed to upload profile picture.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $profile_picture = $upload_path;

    // Check if username or email already exists in both tables
    $stmt1 = $conn->prepare("SELECT id FROM students WHERE username = ? OR email = ?");
    $stmt1->bind_param("ss", $username, $email);
    $stmt1->execute();
    $stmt1->store_result();

    $stmt2 = $conn->prepare("SELECT id FROM teachers WHERE email = ?");
    $stmt2->bind_param("s", $email);
    $stmt2->execute();
    $stmt2->store_result();

    if ($stmt1->num_rows > 0 || $stmt2->num_rows > 0) {
        $stmt1->close();
        $stmt2->close();
        $_SESSION['error'] = 'Username or email already exists.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $stmt1->close();
    $stmt2->close();

    // Insert student into database
    $stmt = $conn->prepare("
        INSERT INTO students (
            first_name, last_name, email, username, grade_level, section, 
            birthdate, contact_number, guardian_name, relationship, 
            guardian_contact, password, bio, profile_picture, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        $_SESSION['error'] = 'Registration failed. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $stmt->bind_param(
        "ssssssssssssss",
        $fname, $lname, $email, $username, $grade, $section,
        $birthdate, $contact, $guardian_name, $relationship,
        $guardian_contact, $hashed_password, $bio, $profile_picture
    );

    if ($stmt->execute()) {
        $stmt->close();
        $_SESSION['registration_success'] = true;
        $_SESSION['registered_email']     = $email;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
        exit();
    } else {
        $stmt->close();
        $_SESSION['error'] = 'Registration failed. Please try again.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Check for success message
$show_success = isset($_GET['success']) && $_GET['success'] == 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
  <title>Student Registration Form</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>
<body>

<style>
      * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cpath d='M30 30l15-15M30 30l-15 15M30 30l15 15M30 30l-15-15' stroke='%236b46c1' stroke-width='0.5' stroke-opacity='0.1'/%3E%3Cpath d='M15 15h30v30H15z' stroke='%23ed64a6' stroke-width='0.5' stroke-opacity='0.1'/%3E%3C/g%3E%3C/svg%3E");
      color: #1e293b;
      line-height: 1.6;
      padding: 30px 20px;
    }

    .container {
      max-width: 900px;
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
      border: 4px solid #10b981;
    }

    .camera-btn {
      position: absolute;
      bottom: 0;
      right: 0;
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
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
      color: #10b981;
      width: 16px;
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
      color: #10b981;
    }

    .card-body {
      padding: 25px;
    }

    /* Form Grid */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
      border-color: #10b981;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
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

    /* Password Field - FIXED */
    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-wrapper input {
      width: 100%;
      padding-right: 45px; /* Make room for the eye icon */
    }

    .password-toggle {
      position: absolute;
      right: 15px;
      cursor: pointer;
      color: #64748b;
      transition: color 0.3s;
      z-index: 10; /* Ensure it's above the input */
      pointer-events: all; /* Make sure it's clickable */
      font-size: 1rem;
    }

    .password-toggle:hover {
      color: #10b981;
    }

    .strength-indicator {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-top: 8px;
      font-size: 0.85rem;
    }

    .strength-text {
      font-weight: 500;
    }

    .strength-weak { color: #ef4444; }
    .strength-medium { color: #f59e0b; }
    .strength-strong { color: #10b981; }

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
      accent-color: #10b981;
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
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
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
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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

    /* Info Box */
    .info-box {
      background: #f0fdf4;
      border: 1px solid #86efac;
      border-radius: 10px;
      padding: 15px;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 20px;
    }

    .info-box i {
      color: #10b981;
      font-size: 1.2rem;
      flex-shrink: 0;
    }

    .info-box p {
      color: #166534;
      font-size: 0.9rem;
      line-height: 1.6;
    }

    /* Alert Styles */
    .alert {
      padding: 1rem;
      border-radius: 10px;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .alert-error {
      background: #fee;
      color: #c33;
      border: 1px solid #fcc;
    }

    /* Error Message */
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
    }
</style>

  <div class="container">
    <?php if (isset($_SESSION['error'])): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <!-- Registration Form - FORM STARTS HERE -->
    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data" onsubmit="return validateForm(event)">
      
      <!-- Profile Picture Section - NOW INSIDE FORM -->
      <div class="profile-card">
        <div class="profile-img-wrapper">
          <img id="preview" src="https://via.placeholder.com/120" alt="Profile" class="profile-img">
          <label class="camera-btn">
            <i class="fas fa-camera"></i>
            <input type="file" name="profile_picture" id="profile_picture" accept="image/*" onchange="previewImg(event)">
          </label>
        </div>
        <div class="profile-info">
          <h2 class="profile-name" id="displayName">Student Name</h2>
          <div class="profile-details">
            <div class="detail-item">
              <i class="fas fa-envelope"></i>
              <span id="displayEmail">Email Address</span>
            </div>
            <div class="detail-item">
              <i class="fas fa-graduation-cap"></i>
              <span id="displayGrade">Grade Level</span>
            </div>
            <div class="detail-item">
              <i class="fas fa-users"></i>
              <span id="displaySection">Section</span>
            </div>
          </div>
          <div style="margin-top: 10px; color: #dc2626; font-size: 13px; font-weight: 500;">
            <i class="fas fa-asterisk" style="font-size: 8px;"></i> Profile picture is required
          </div>
        </div>
      </div>

      <!-- Student Information -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-user"></i>
            Student Information
          </h3>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <div class="form-group">
              <label><i class="fas fa-user"></i> First Name</label>
              <input type="text" name="fname" id="fname" placeholder="Enter first name" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-user"></i> Last Name</label>
              <input type="text" name="lname" id="lname" placeholder="Enter last name" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-envelope"></i> Email Address (Gmail only)</label>
              <input type="email" name="email" id="email" placeholder="yourname@gmail.com" required>
              <span class="error-message" id="email-error">
                <i class="fas fa-exclamation-circle"></i> Email already exists
              </span>
              <small style="color: #64748b; font-size: 0.85rem;">Must be a valid @gmail.com address</small>
            </div>
            <div class="form-group">
              <label><i class="fas fa-at"></i> Username</label>
              <input type="text" name="username" id="username" placeholder="Auto-generated" readonly required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-graduation-cap"></i> Grade Level</label>
              <select name="grade" id="grade" required>
                <option value="">Select grade level</option>
                <option>Grade 7</option>
                <option>Grade 8</option>
                <option>Grade 9</option>
                <option>Grade 10</option>
                <option>Grade 11</option>
                <option>Grade 12</option>
                <option>1st Year</option>
                <option>2nd Year</option>
                <option>3rd Year</option>
                <option>4th Year</option>
              </select>
            </div>
            <div class="form-group">
              <label><i class="fas fa-users"></i> Section</label>
              <input type="text" name="section" id="section" placeholder="e.g., Section A" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-calendar"></i> Date of Birth</label>
              <input type="date" name="birthdate" id="birthdate" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-phone"></i> Contact Number <span style="color: #64748b;">(optional)</span></label>
              <input type="tel" name="contact" id="contact" placeholder="+63 XXX XXX XXXX">
            </div>
          </div>
        </div>
      </div>

      <!-- Guardian Information -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-user-shield"></i>
            Guardian / Parent Information
          </h3>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <div class="form-group">
              <label><i class="fas fa-user"></i> Parent / Guardian Name</label>
              <input type="text" name="guardian_name" id="guardian-name" placeholder="Enter full name" required>
            </div>
            <div class="form-group">
              <label><i class="fas fa-heart"></i> Relationship</label>
              <input type="text" name="relationship" id="relationship" placeholder="e.g., Mother, Father" required>
            </div>
            <div class="form-group full-width">
              <label><i class="fas fa-phone"></i> Contact Number <span style="color: #64748b;">(optional)</span></label>
              <input type="tel" name="guardian_contact" id="guardian-contact" placeholder="+63 XXX XXX XXXX">
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
          <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <p>Create a strong password with at least 6 characters, including uppercase letters and numbers.</p>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label><i class="fas fa-key"></i> Password</label>
              <div class="password-wrapper">
                <input type="password" name="password" id="password" placeholder="Enter password" required onkeyup="checkStrength()">
                <i class="fas fa-eye password-toggle" onclick="togglePass('password')"></i>
              </div>
              <div id="strength" class="strength-indicator"></div>
            </div>
            <div class="form-group">
              <label><i class="fas fa-check-circle"></i> Verify Password</label>
              <div class="password-wrapper">
                <input type="password" name="confirm" id="confirm" placeholder="Re-enter password" required>
                <i class="fas fa-eye password-toggle" onclick="togglePass('confirm')"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Bio Section -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-file-alt"></i>
            Bio <span style="color: #64748b; font-weight: 400; font-size: 0.9rem;">(Optional)</span>
          </h3>
        </div>
        <div class="card-body">
          <div class="form-group full-width">
            <textarea name="bio" id="bio" placeholder="Tell us about yourself..."></textarea>
          </div>
        </div>
      </div>

      <!-- Terms & Agreement -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">
            <i class="fas fa-file-contract"></i>
            Terms & Agreement
          </h3>
        </div>
        <div class="card-body">
          <div class="checkbox-group">
            <div class="checkbox-item">
              <input type="checkbox" id="terms1" required>
              <label for="terms1">I agree to the <strong>Code of Conduct</strong> and will follow all school rules and regulations</label>
            </div>
            <div class="checkbox-item">
              <input type="checkbox" id="terms2" required>
              <label for="terms2">I agree to the <strong>Online Learning Policy</strong> and understand my responsibilities as a student</label>
            </div>
            <div class="checkbox-item">
              <input type="checkbox" id="terms3" required>
              <label for="terms3">I agree to the <strong>School Data Privacy Policy</strong> and consent to the collection and use of my information</label>
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
          <i class="fas fa-user-check"></i>
          Register
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
      <h2>Registration Successful!</h2>
      <p>Your account has been created. Redirecting to login...</p>
    </div>
  </div>

  <script>
    <?php if ($show_success): ?>
    setTimeout(() => {
      // Clear session first to prevent auto-login
      fetch('<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'clear_session=1'
      }).then(() => {
        window.location.href = 'student_dashb.php';
      });
    }, 2000);
    <?php endif; ?>

    let emailCheckTimeout;
    let emailExists = false;

    // Check if email exists in database
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

    // Email input event listener
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

    // Check password strength
    function checkStrength() {
      const p = document.getElementById('password').value;
      const strengthDiv = document.getElementById('strength');
      
      if (p.length === 0) {
        strengthDiv.innerHTML = '';
        return;
      }
      
      let strength = 'Weak';
      let className = 'strength-weak';
      
      if (p.length >= 6 && /[A-Z]/.test(p) && /[0-9]/.test(p)) {
        strength = 'Strong';
        className = 'strength-strong';
      } else if (p.length >= 6) {
        strength = 'Medium';
        className = 'strength-medium';
      }
      
      strengthDiv.innerHTML = `<span class="strength-text ${className}">Password Strength: ${strength}</span>`;
    }

    // Update display preview
    function updateDisplay() {
      const firstName = document.getElementById('fname').value;
      const lastName = document.getElementById('lname').value;
      const email = document.getElementById('email').value;
      const grade = document.getElementById('grade').value;
      const section = document.getElementById('section').value;

      if (firstName || lastName) {
        document.getElementById('displayName').textContent = 
          `${firstName} ${lastName}`.trim() || 'Student Name';
      }

      if (email) {
        document.getElementById('displayEmail').textContent = email;
      }

      if (grade) {
        document.getElementById('displayGrade').textContent = grade;
      }

      if (section) {
        document.getElementById('displaySection').textContent = section;
      }
    }

    // Generate username
    function genUser() {
      const fname = document.getElementById('fname');
      const lname = document.getElementById('lname');
      const username = document.getElementById('username');
      
      if (fname.value && lname.value) {
        const r = Math.floor(100 + Math.random() * 900);
        username.value = fname.value.toLowerCase() + '.' + lname.value.toLowerCase() + r;
      }
      updateDisplay();
    }

    // Event listeners for real-time updates
    document.getElementById('fname').addEventListener('input', updateDisplay);
    document.getElementById('lname').addEventListener('input', updateDisplay);
    document.getElementById('grade').addEventListener('change', updateDisplay);
    document.getElementById('section').addEventListener('input', updateDisplay);

    document.getElementById('fname').addEventListener('blur', genUser);
    document.getElementById('lname').addEventListener('blur', genUser);

    // Reset form
    function resetForm() {
      if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.querySelector('form').reset();
        document.getElementById('preview').src = 'https://via.placeholder.com/120';
        document.getElementById('strength').innerHTML = '';
        document.getElementById('displayName').textContent = 'Student Name';
        document.getElementById('displayEmail').textContent = 'Email Address';
        document.getElementById('displayGrade').textContent = 'Grade Level';
        document.getElementById('displaySection').textContent = 'Section';
        document.getElementById('email-error').classList.remove('show');
        document.getElementById('email').classList.remove('input-error');
        emailExists = false;
        document.getElementById('submit-btn').disabled = false;
        document.getElementById('submit-btn').style.opacity = '1';
        document.getElementById('submit-btn').style.cursor = 'pointer';
        
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

      if (emailExists) {
        console.error('❌ VALIDATION FAILED: Email already exists');
        alert('❌ Email already exists. Please use a different email address.');
        e.preventDefault();
        return false;
      }

      console.log('✅ Email check passed');

      const passwordValue = document.getElementById('password').value;
      const confirmValue = document.getElementById('confirm').value;
      
      if (passwordValue !== confirmValue) {
        console.error('❌ VALIDATION FAILED: Passwords do not match');
        alert('❌ Passwords do not match. Please check and try again.');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ Password match check passed');

      if (passwordValue.length < 6) {
        console.error('❌ VALIDATION FAILED: Password too short -', passwordValue.length, 'characters');
        alert('❌ Password must be at least 6 characters long.');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ Password length check passed');
      
      // Check if file is selected and valid
      const file = fileInput.files[0];
      const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
      
      if (!validTypes.includes(file.type)) {
        console.error('❌ VALIDATION FAILED: Invalid file type -', file.type);
        alert('❌ Please upload a valid image file (JPEG, PNG, GIF, or WEBP).');
        e.preventDefault();
        return false;
      }
      
      console.log('✅ File type check passed');
      
      // Check file size (max 5MB)
      if (file.size > 5 * 1024 * 1024) {
        console.error('❌ VALIDATION FAILED: File size too large -', (file.size / 1024 / 1024).toFixed(2) + 'MB');
        alert('❌ Image size must be less than 5MB.');
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
    console.log('📋 Student Registration Form loaded');
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