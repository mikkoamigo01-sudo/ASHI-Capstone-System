<?php
// ============================================================
// admin_register.php — Ashi Amaya Admin Registration
// ============================================================
session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: admin_dash.php');
    exit();
}

require_once '../db_connection/admin.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username']  ?? '');
    $password  = trim($_POST['password']  ?? '');
    $confirm   = trim($_POST['confirm']   ?? '');

    // Basic validation
    if (empty($username) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($username) < 4) {
        $error = 'Username must be at least 4 characters.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check if username already exists
        $check = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $check->bind_param('s', $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'Username is already taken.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt   = $conn->prepare("INSERT INTO admins (username, password, status) VALUES (?, ?, 'active')");
            $stmt->bind_param('ss', $username, $hashed);

            if ($stmt->execute()) {
                $success = 'Account created successfully. You can now sign in.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
            $stmt->close();
        }
        $check->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Register — Ashi Amaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display&display=swap" rel="stylesheet" />
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:        #f5f4f0;
            --surface:   #ffffff;
            --ink:        #1a1a1a;
            --muted:      #7a7a7a;
            --accent:     #2e5cff;
            --accent-dim: #dce5ff;
            --border:     #e4e4e0;
            --error:      #d94f4f;
            --success:    #2e7d52;
            --success-bg: #edf7f1;
            --success-bd: #b2ddc4;
            --radius:     10px;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(var(--border) 1px, transparent 1px),
                linear-gradient(90deg, var(--border) 1px, transparent 1px);
            background-size: 40px 40px;
            opacity: 0.5;
            pointer-events: none;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 48px 44px;
            width: 100%;
            max-width: 400px;
            position: relative;
            box-shadow: 0 4px 32px rgba(0,0,0,0.06);
            animation: fadeUp 0.4s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .brand {
            margin-bottom: 36px;
        }

        .brand-logo {
            width: 36px;
            height: 36px;
            background: var(--accent);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .brand-logo svg { width: 18px; height: 18px; fill: #fff; }

        .brand h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 22px;
            color: var(--ink);
            letter-spacing: -0.3px;
            line-height: 1.2;
        }

        .brand p {
            font-size: 13px;
            color: var(--muted);
            margin-top: 4px;
        }

        .alert {
            border-radius: var(--radius);
            padding: 10px 14px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert svg { flex-shrink: 0; width: 15px; height: 15px; }

        .alert-error {
            background: #fff0f0;
            border: 1px solid #f5c0c0;
            color: var(--error);
        }

        .alert-success {
            background: var(--success-bg);
            border: 1px solid var(--success-bd);
            color: var(--success);
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 7px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            color: var(--ink);
            background: var(--bg);
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 3px var(--accent-dim);
        }

        .password-wrap {
            position: relative;
        }

        .toggle-pw {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            padding: 4px;
            display: flex;
            align-items: center;
        }

        .toggle-pw:hover { color: var(--ink); }

        /* Password strength bar */
        .strength-bar {
            height: 3px;
            border-radius: 2px;
            background: var(--border);
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: width 0.3s, background 0.3s;
        }

        .strength-label {
            font-size: 11px;
            color: var(--muted);
            margin-top: 4px;
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.2s, transform 0.1s;
            letter-spacing: 0.2px;
        }

        .btn:hover  { background: #1d4de8; }
        .btn:active { transform: scale(0.98); }

        .footer-link {
            text-align: center;
            margin-top: 22px;
            font-size: 13px;
            color: var(--muted);
        }

        .footer-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }

        .footer-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <div class="brand-logo">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.18L20 8.5v7l-8 4-8-4v-7l8-4.32z"/>
            </svg>
        </div>
        <h1>Ashi Amaya</h1>
        <p>Admin Portal — Create an account</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5l-4-4 1.41-1.41L10 13.67l6.59-6.59L18 8.5l-8 8z"/></svg>
        <?= htmlspecialchars($success) ?>
        <a href="admin_login.php" style="margin-left:6px; color:inherit; font-weight:600;">Sign in →</a>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" action="" id="registerForm">
        <div class="form-group">
            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                placeholder="Choose a username"
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                autocomplete="username"
                required
            />
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="password-wrap">
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Create a password"
                    autocomplete="new-password"
                    oninput="checkStrength(this.value)"
                    required
                />
                <button type="button" class="toggle-pw" onclick="togglePassword('password', 'eye1')" aria-label="Toggle password">
                    <svg id="eye1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>
            </div>
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="strength-label" id="strengthLabel"></div>
        </div>

        <div class="form-group">
            <label for="confirm">Confirm Password</label>
            <div class="password-wrap">
                <input
                    type="password"
                    id="confirm"
                    name="confirm"
                    placeholder="Repeat your password"
                    autocomplete="new-password"
                    required
                />
                <button type="button" class="toggle-pw" onclick="togglePassword('confirm', 'eye2')" aria-label="Toggle confirm password">
                    <svg id="eye2" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>
            </div>
        </div>

        <button type="submit" class="btn">Create Account</button>
    </form>
    <?php endif; ?>

    <div class="footer-link">
        Already have an account? <a href="admin_login.php">Sign in</a>
    </div>
</div>

<script>
function togglePassword(fieldId, iconId) {
    const input = document.getElementById(fieldId);
    const icon  = document.getElementById(iconId);
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    icon.innerHTML = isHidden
        ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`
        : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
}

function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const map = [
        { pct: '0%',   color: 'transparent', text: '' },
        { pct: '25%',  color: '#d94f4f',     text: 'Weak' },
        { pct: '50%',  color: '#e8913a',     text: 'Fair' },
        { pct: '75%',  color: '#d4b12a',     text: 'Good' },
        { pct: '90%',  color: '#3ab26a',     text: 'Strong' },
        { pct: '100%', color: '#2e7d52',     text: 'Very strong' },
    ];

    const s = map[Math.min(score, 5)];
    fill.style.width      = s.pct;
    fill.style.background = s.color;
    label.textContent     = s.text;
}
</script>
</body>
</html>