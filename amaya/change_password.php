<?php
// No login/auth session required — this page is standalone/public.
// This is a CHANGE password page, not a forgot/reset page: the user must prove
// they already know their CURRENT password before a new one is set.
//   - Teachers:  Account (email) + Current Password
//   - Students:  Account (username/email) + LRN + Current Password
// session_start() below is used ONLY for CSRF token + rate-limit counters — not for auth gating.
session_start();

if (empty($_SESSION['cp_csrf'])) {
    $_SESSION['cp_csrf'] = bin2hex(random_bytes(32));
}

// ── Rate limiting config ──
const CP_MAX_ATTEMPTS    = 5;      // max failed attempts
const CP_LOCKOUT_SECONDS = 600;    // lockout duration (10 minutes)

if (!isset($_SESSION['cp_attempts']))     $_SESSION['cp_attempts']     = 0;
if (!isset($_SESSION['cp_locked_until'])) $_SESSION['cp_locked_until'] = 0;

$is_locked = $_SESSION['cp_locked_until'] > time();

$host    = 'localhost';
$dbname  = 'u304756553_ashi_classroom';
$db_user = 'u304756553_ashi_classroom';
$db_pass = 'Ashi_Classroom17';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    die("A server error occurred. Please try again later.");
}

$error_message   = '';
$success_message = '';

function registerFailedAttempt(): string {
    $_SESSION['cp_attempts']++;
    if ($_SESSION['cp_attempts'] >= CP_MAX_ATTEMPTS) {
        $_SESSION['cp_locked_until'] = time() + CP_LOCKOUT_SECONDS;
        $_SESSION['cp_attempts'] = 0;
        return "Too many failed attempts. Please try again in " . ceil(CP_LOCKOUT_SECONDS / 60) . " minute(s).";
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($is_locked) {
        $wait = $_SESSION['cp_locked_until'] - time();
        $error_message = "Too many failed attempts. Please try again in " . ceil($wait / 60) . " minute(s).";
    } elseif (empty($_POST['csrf_token']) || !hash_equals($_SESSION['cp_csrf'], $_POST['csrf_token'])) {
        $error_message = "Your session has expired. Please refresh the page and try again.";
    } else {
        $account      = trim($_POST['account'] ?? '');
        $lrn          = trim($_POST['lrn'] ?? '');
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($account) || empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            $error_message = "Please fill in all required fields.";
        } elseif (strlen($new_pass) < 8) {
            $error_message = "New password must be at least 8 characters long.";
        } elseif ($new_pass !== $confirm_pass) {
            $error_message = "New password and confirmation do not match.";
        } else {
            // ── Detect account type: teachers (by email) first, then students (by username or email) ──
            // Teacher and student emails share the identical "ashi.firstname@gmail.com" pattern,
            // so account type can only be determined by an actual database lookup — never guessed
            // from the submitted text.
            $target_table  = null;
            $target_id     = null;
            $account_type  = null;
            $password_hash = null;
            $studentRow    = null;

            $stmt = $pdo->prepare("SELECT id, password FROM teachers WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$account]);
            $teacherRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($teacherRow) {
                $target_table  = 'teachers';
                $target_id     = (int)$teacherRow['id'];
                $account_type  = 'teacher';
                $password_hash = $teacherRow['password'];
            } else {
                $stmt = $pdo->prepare("SELECT id, password, lrn FROM students WHERE (username = ? OR email = ?) AND status = 'active' LIMIT 1");
                $stmt->execute([$account, $account]);
                $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($studentRow) {
                    $target_table  = 'students';
                    $target_id     = (int)$studentRow['id'];
                    $account_type  = 'student';
                    $password_hash = $studentRow['password'];
                }
            }

            if (!$target_table) {
                $error_message = "No account found with that username or email.";
                $lockMsg = registerFailedAttempt();
                if ($lockMsg) $error_message = $lockMsg;

            } elseif ($account_type === 'student' && empty($lrn)) {
                $error_message = "LRN is required to verify a student account.";
                $lockMsg = registerFailedAttempt();
                if ($lockMsg) $error_message = $lockMsg;

            } elseif ($account_type === 'student' && $lrn !== $studentRow['lrn']) {
                $error_message = "LRN does not match our records for this account.";
                $lockMsg = registerFailedAttempt();
                if ($lockMsg) $error_message = $lockMsg;

            } elseif (!password_verify($current_pass, $password_hash)) {
                // The user does not know the current password — they have no authority to change it.
                $error_message = "Current password is incorrect.";
                $lockMsg = registerFailedAttempt();
                if ($lockMsg) $error_message = $lockMsg;

            } elseif (password_verify($new_pass, $password_hash)) {
                $error_message = "New password must be different from your current password.";

            } else {
                // ── All checks passed: account exists, (LRN matches if student), current password verified ──
                $_SESSION['cp_attempts'] = 0;

                $newHash = password_hash($new_pass, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE $target_table SET password = ?, plain_password = ? WHERE id = ? AND status = 'active'");
                $upd->execute([$newHash, $new_pass, $target_id]);

                if ($upd->rowCount() > 0) {
                    $success_message = "Password updated successfully for \"$account\".";
                    $_SESSION['cp_csrf'] = bin2hex(random_bytes(32));
                } else {
                    $error_message = "Something went wrong and the password was not updated. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
    <title>Change Password - ASHI School Management System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-1: #6366f1;
            --brand-2: #4f46e5;
            --accent-1: #10b981;
            --accent-2: #059669;
            --ink: #1e293b;
            --muted: #64748b;
            --faint: #94a3b8;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(160deg, #eef2ff 0%, #f0f7ff 45%, #ecfdf5 100%);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .card-box {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(30, 41, 59, .12), 0 2px 8px rgba(30, 41, 59, .06);
            padding: 40px 40px 32px;
            width: 100%;
            max-width: 440px;
            animation: rise .35s ease-out;
        }

        @media (min-width: 720px) {
            .card-box { max-width: 680px; padding: 44px 48px 36px; }
            .form-columns {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0 28px;
            }
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .brand-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--brand-1), var(--brand-2));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.4rem;
            margin-bottom: 18px;
            box-shadow: 0 8px 18px rgba(99, 102, 241, .3);
        }

        .card-box h2 {
            font-weight: 700;
            font-size: 1.5rem;
            margin-bottom: 6px;
            color: var(--ink);
            letter-spacing: -0.01em;
        }

        .card-box p.sub {
            color: var(--muted);
            font-size: .92rem;
            margin-bottom: 26px;
            line-height: 1.5;
        }

        .form-group { margin-bottom: 18px; }

        label {
            font-weight: 600;
            font-size: .85rem;
            color: var(--ink);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        label .fa { color: var(--brand-1); font-size: .8rem; width: 14px; }

        label .optional-tag {
            font-weight: 500;
            color: var(--faint);
            font-size: .76rem;
        }

        .input-wrap { position: relative; }

        .input-wrap .fa-lock,
        .input-wrap .fa-user,
        .input-wrap .fa-id-card,
        .input-wrap .fa-shield-halved {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--faint);
            font-size: .85rem;
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px 12px 38px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: .95rem;
            font-family: inherit;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--brand-1);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, .1);
        }

        .form-control:disabled {
            background: #f8fafc;
            cursor: not-allowed;
        }

        .toggle-visibility {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--faint);
            font-size: .9rem;
            cursor: pointer;
            padding: 4px 6px;
        }

        .toggle-visibility:hover { color: var(--brand-1); }

        .help-text {
            color: var(--faint);
            font-size: .78rem;
            margin-top: 5px;
            display: block;
        }

        .btn-submit {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            font-family: inherit;
            margin-top: 6px;
            transition: opacity .2s ease, transform .1s ease;
        }

        .btn-submit:hover:not(:disabled) { opacity: .92; }
        .btn-submit:active:not(:disabled) { transform: scale(.99); }

        .btn-submit:disabled {
            background: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .alert {
            border-radius: 10px;
            font-size: .87rem;
            padding: 12px 14px;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border: 1px solid transparent;
        }

        .alert .fa { margin-top: 2px; flex-shrink: 0; }

        .alert-danger {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #15803d;
            border-color: #bbf7d0;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 20px;
            font-size: .88rem;
            color: var(--brand-1);
            text-decoration: none;
            font-weight: 500;
            transition: gap .15s ease;
        }

        .back-link:hover { gap: 9px; color: var(--brand-2); }

        @media (max-width: 400px) {
            .card-box { padding: 30px 22px 26px; border-radius: 16px; }
            .card-box h2 { font-size: 1.3rem; }
            .brand-icon { width: 48px; height: 48px; font-size: 1.2rem; border-radius: 12px; }
        }

        @media (max-height: 640px) {
            body { align-items: flex-start; padding-top: 32px; }
        }
    </style>
</head>
<body>
    <div class="card-box">
        <div class="brand-icon"><i class="fa fa-key"></i></div>
        <h2>Change Password</h2>
        <p class="sub">You must confirm your current password before setting a new one.</p>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fa fa-circle-exclamation"></i> <span><?php echo htmlspecialchars($error_message); ?></span></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fa fa-circle-check"></i> <span><?php echo htmlspecialchars($success_message); ?></span></div>
        <?php endif; ?>

        <form method="POST" action="change_password.php" id="cpForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['cp_csrf']); ?>">

            <div class="form-columns">
                <div class="form-col">
                    <div class="form-group">
                        <label for="account"><i class="fa fa-user"></i> Account (Username or Email)</label>
                        <div class="input-wrap">
                            <i class="fa fa-user"></i>
                            <input type="text" name="account" id="account" class="form-control" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="current_password"><i class="fa fa-shield-halved"></i> Current Password</label>
                        <div class="input-wrap">
                            <i class="fa fa-shield-halved"></i>
                            <input type="password" name="current_password" id="current_password" class="form-control" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="toggle-visibility" data-target="current_password" tabindex="-1" aria-label="Show password">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                        <small class="help-text">You must know your current password to change it.</small>
                    </div>

                    <div class="form-group">
                        <label for="lrn"><i class="fa fa-id-card"></i> Student LRN <span class="optional-tag">(students only)</span></label>
                        <div class="input-wrap">
                            <i class="fa fa-id-card"></i>
                            <input type="text" name="lrn" id="lrn" class="form-control" maxlength="12" placeholder="12-digit LRN" <?php echo $is_locked ? 'disabled' : ''; ?>>
                        </div>
                        <small class="help-text">Required for students. Teachers can leave this empty.</small>
                    </div>
                </div>

                <div class="form-col">
                    <div class="form-group">
                        <label for="new_password"><i class="fa fa-lock"></i> New Password</label>
                        <div class="input-wrap">
                            <i class="fa fa-lock"></i>
                            <input type="password" name="new_password" id="new_password" class="form-control" minlength="8" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="toggle-visibility" data-target="new_password" tabindex="-1" aria-label="Show password">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password"><i class="fa fa-lock"></i> Confirm New Password</label>
                        <div class="input-wrap">
                            <i class="fa fa-lock"></i>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" minlength="8" required <?php echo $is_locked ? 'disabled' : ''; ?>>
                            <button type="button" class="toggle-visibility" data-target="confirm_password" tabindex="-1" aria-label="Show password">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn" disabled <?php echo $is_locked ? 'data-locked="1"' : ''; ?>>
                <i class="fa fa-check"></i> Update Password
            </button>
        </form>
        <a href="login.php" class="back-link">
            <i class="fa fa-arrow-left"></i> Back to Login
        </a>
    </div>

    <script>
    // Enables the submit button only once account, current password, new password,
    // and confirm password are all filled and the new password meets the length requirement.
    // NOTE: LRN is intentionally NOT part of this client-side check — teacher vs. student
    // cannot be reliably guessed from the typed text (both use the same email pattern),
    // so whether LRN is actually required is decided server-side, from the real record.
    const accountInput = document.getElementById('account');
    const currentPassInput = document.getElementById('current_password');
    const lrnInput = document.getElementById('lrn');
    const newPassInput = document.getElementById('new_password');
    const confirmPassInput = document.getElementById('confirm_password');
    const submitBtn = document.getElementById('submitBtn');
    const isLocked = submitBtn.hasAttribute('data-locked');

    [accountInput, currentPassInput, lrnInput, newPassInput, confirmPassInput].forEach(el => {
        el.addEventListener('input', validateForm);
    });

    function validateForm() {
        if (isLocked) { submitBtn.disabled = true; return; }

        const account = accountInput.value.trim();
        const currentPass = currentPassInput.value;
        const newPass = newPassInput.value;
        const confirmPass = confirmPassInput.value;

        const allFilled = account.length > 0
            && currentPass.length > 0
            && newPass.length >= 8
            && confirmPass.length >= 8;

        submitBtn.disabled = !allFilled;
    }

    validateForm();

    document.querySelectorAll('.toggle-visibility').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            const icon = btn.querySelector('i');
            const showing = target.type === 'text';
            target.type = showing ? 'password' : 'text';
            icon.classList.toggle('fa-eye', showing);
            icon.classList.toggle('fa-eye-slash', !showing);
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        });
    });
    </script>
</body>
</html>