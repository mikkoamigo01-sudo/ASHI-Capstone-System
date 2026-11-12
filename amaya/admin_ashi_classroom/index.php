<?php
// ============================================================
// admin_login.php — Ashi Amaya Admin Login
// ============================================================
session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: admin_dash.php');
    exit();
}

require_once '../db_connection/admin.php';

define('MAX_ATTEMPTS',  5);
define('LOCKOUT_SECS', 30);

if (!isset($_SESSION['login_attempts']))  $_SESSION['login_attempts']  = 0;
if (!isset($_SESSION['lockout_until']))   $_SESSION['lockout_until']   = 0;

$error       = '';
$locked      = false;
$lockout_rem = 0;

if ($_SESSION['lockout_until'] > time()) {
    $locked      = true;
    $lockout_rem = $_SESSION['lockout_until'] - time();
    $error       = 'Too many failed attempts. Please wait ' . $lockout_rem . ' second(s).';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password FROM admins WHERE username = ? AND status = 'active'");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            if (password_verify($password, $admin['password'])) {
                $_SESSION['login_attempts'] = 0;
                $_SESSION['lockout_until']  = 0;

                $update = $conn->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
                $update->bind_param('i', $admin['id']);
                $update->execute();
                $update->close();

                $_SESSION['admin_id']       = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];

                header('Location: admin_dash.php');
                exit();
            } else {
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                    $_SESSION['lockout_until']  = time() + LOCKOUT_SECS;
                    $_SESSION['login_attempts'] = 0;
                    $locked      = true;
                    $lockout_rem = LOCKOUT_SECS;
                    $error       = 'Too many failed attempts. Please wait ' . LOCKOUT_SECS . ' second(s).';
                } else {
                    $remaining = MAX_ATTEMPTS - $_SESSION['login_attempts'];
                    $error     = 'Invalid username or password. ' . $remaining . ' attempt(s) remaining.';
                }
            }
        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                $_SESSION['lockout_until']  = time() + LOCKOUT_SECS;
                $_SESSION['login_attempts'] = 0;
                $locked      = true;
                $lockout_rem = LOCKOUT_SECS;
                $error       = 'Too many failed attempts. Please wait ' . LOCKOUT_SECS . ' second(s).';
            } else {
                $remaining = MAX_ATTEMPTS - $_SESSION['login_attempts'];
                $error     = 'Invalid username or password. ' . $remaining . ' attempt(s) remaining.';
            }
        }
        $stmt->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" href="img/ashi.jpg" type="image/x-icon">
  <title>Admin Login — ASHI Amaya</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet" />

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --ink:       #0d1b2a;
      --ink-mid:   #1e3352;
      --ink-soft:  #475569;
      --ink-muted: #94a3b8;
      --green:     #10b981;
      --green-d:   #059669;
      --green-pale:#ecfdf5;
      --surface:   #ffffff;
      --off:       #f8fafc;
      --border:    #e2e8f0;
      --danger:    #ef4444;
      --danger-bg: #fef2f2;
      --danger-bd: #fecaca;
      --r:         10px;
    }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--off);
      color: var(--ink);
    }

    /* ── LAYOUT ────────────────────────────────── */
    .wrap {
      display: flex;
      min-height: 100vh;
    }

    /* ── LEFT PANEL ─────────────────────────────── */
    .panel-left {
      flex: 1;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      background: var(--ink);
    }

    /* Dark atmospheric background */
    .panel-left::before {
      content: '';
      position: absolute; inset: 0;
      background:
        url('img/login-bg.jpg') center/cover no-repeat;
      opacity: .22;
      z-index: 0;
    }

    /* Gradient overlay */
    .panel-left::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(
        160deg,
        rgba(13,27,42,.92) 0%,
        rgba(13,27,42,.72) 50%,
        rgba(16,185,129,.18) 100%
      );
      z-index: 1;
    }

    /* Subtle grid texture */
    .grid-overlay {
      position: absolute; inset: 0; z-index: 2;
      background-image:
        linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
      background-size: 48px 48px;
    }

    .panel-left-content {
      position: relative; z-index: 3;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      height: 100%;
      padding: 52px 52px 48px;
    }

    /* Top badge */
    .left-top-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,.07);
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 30px;
      padding: 6px 14px 6px 8px;
      width: fit-content;
    }

    .left-top-badge img {
      width: 28px; height: 28px;
      border-radius: 50%; object-fit: cover;
      border: 1.5px solid rgba(255,255,255,.2);
    }

    .left-top-badge span {
      font-size: .75rem; font-weight: 600;
      color: rgba(255,255,255,.75);
      letter-spacing: .06em;
      text-transform: uppercase;
    }

    /* Hero text */
    .left-hero {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 60px 0 40px;
    }

    .accent-line {
      width: 32px; height: 3px;
      background: var(--green);
      border-radius: 3px;
      margin-bottom: 24px;
    }

    .left-hero h2 {
      font-family: 'DM Serif Display', serif;
      font-size: clamp(2rem, 3.2vw, 3rem);
      color: #fff;
      line-height: 1.2;
      margin-bottom: 18px;
    }

    .left-hero h2 em {
      font-style: italic;
      color: var(--green);
    }

    .left-hero p {
      font-size: .9rem;
      color: rgba(255,255,255,.5);
      line-height: 1.8;
      max-width: 300px;
    }

    /* Stats row */
    .left-stats {
      display: flex;
      gap: 32px;
    }

    .stat {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .stat-n {
      font-family: 'DM Serif Display', serif;
      font-size: 1.6rem;
      color: #fff;
      line-height: 1;
    }

    .stat-n span { color: var(--green); }

    .stat-l {
      font-size: .7rem;
      color: rgba(255,255,255,.4);
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    /* ── RIGHT PANEL ────────────────────────────── */
    .panel-right {
      flex: 0 0 460px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 64px 52px;
      background: var(--surface);
      position: relative;
      animation: slideIn .55s cubic-bezier(.22,.68,0,1.2) both;
    }

    @keyframes slideIn {
      from { opacity: 0; transform: translateX(28px); }
      to   { opacity: 1; transform: translateX(0); }
    }

    /* LOGO BLOCK */
    .logo-block {
      display: flex;
      align-items: center;
      gap: 11px;
      margin-bottom: 40px;
    }

    .logo-block img {
      width: 42px; height: 42px;
      object-fit: cover; border-radius: 10px;
      border: 1.5px solid var(--border);
    }

    .logo-name {
      font-family: 'DM Serif Display', serif;
      font-size: 1rem; color: var(--ink);
      line-height: 1.1;
    }

    .logo-sub {
      font-size: .68rem; font-weight: 600;
      color: var(--green);
      text-transform: uppercase;
      letter-spacing: .09em;
      margin-top: 3px;
    }

    /* HEADING */
    .form-head { margin-bottom: 28px; }

    .form-head h1 {
      font-family: 'DM Serif Display', serif;
      font-size: 1.75rem;
      color: var(--ink);
      margin-bottom: 6px;
    }

    .form-head p {
      font-size: .83rem;
      color: var(--ink-muted);
      line-height: 1.6;
    }

    .rule {
      height: 1px;
      background: var(--border);
      margin-bottom: 26px;
    }

    /* ALERT */
    .alert {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 11px 14px;
      border-radius: var(--r);
      font-size: .8rem;
      line-height: 1.55;
      margin-bottom: 20px;
      animation: popIn .3s ease both;
    }

    @keyframes popIn {
      from { opacity: 0; transform: translateY(-6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .alert-error {
      background: var(--danger-bg);
      border: 1px solid var(--danger-bd);
      color: #b91c1c;
    }

    .alert svg { flex-shrink:0; width:15px; height:15px; margin-top:1px; }

    /* FORM GROUPS */
    .fgroup { margin-bottom: 16px; }

    .fgroup label {
      display: block;
      font-size: .72rem;
      font-weight: 600;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--ink-soft);
      margin-bottom: 7px;
    }

    .input-row { position: relative; }

    .input-row input {
      width: 100%;
      padding: 11px 42px 11px 38px;
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      font-family: 'DM Sans', sans-serif;
      font-size: .875rem;
      color: var(--ink);
      background: var(--off);
      outline: none;
      transition: border-color .2s, box-shadow .2s, background .2s;
    }

    .input-row input:focus {
      border-color: var(--ink);
      background: var(--surface);
      box-shadow: 0 0 0 3.5px rgba(13,27,42,.07);
    }

    .input-row input:disabled {
      opacity: .5; cursor: not-allowed;
    }

    .input-row input::placeholder { color: #c4c9d4; font-size: .825rem; }

    /* leading icon */
    .input-icon {
      position: absolute;
      left: 12px; top: 50%; transform: translateY(-50%);
      color: var(--ink-muted);
      pointer-events: none;
      transition: color .2s;
    }

    .input-row:focus-within .input-icon { color: var(--ink); }

    .input-icon svg { width:15px; height:15px; display:block; }

    /* toggle pw */
    .toggle-pw {
      position: absolute;
      right: 11px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--ink-muted); padding: 4px;
      display: flex; align-items: center;
      transition: color .15s;
    }
    .toggle-pw:hover { color: var(--ink); }
    .toggle-pw svg { width:15px; height:15px; display:block; }

    /* SUBMIT */
    .btn-submit {
      width: 100%;
      padding: 12.5px;
      background: var(--ink);
      color: #fff;
      border: none; border-radius: var(--r);
      font-family: 'DM Sans', sans-serif;
      font-size: .875rem; font-weight: 600;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      margin-top: 8px;
      transition: background .2s, transform .15s, box-shadow .2s;
    }

    .btn-submit:hover {
      background: var(--ink-mid);
      box-shadow: 0 6px 20px rgba(13,27,42,.2);
      transform: translateY(-1px);
    }

    .btn-submit:active { transform: translateY(0); }

    .btn-submit:disabled {
      background: #cbd5e1;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .btn-submit svg { width:15px; height:15px; }

    /* Attempt pips */
    .attempt-pips {
      display: flex;
      align-items: center;
      gap: 5px;
      margin-top: 14px;
      justify-content: center;
    }

    .pip {
      width: 7px; height: 7px; border-radius: 50%;
      background: var(--border);
      transition: background .3s;
    }

    .pip.used { background: var(--danger); }

    /* Footer */
    .right-foot {
      position: absolute;
      bottom: 22px; left: 52px; right: 52px;
      display: flex; align-items: center; justify-content: space-between;
    }

    .right-foot span {
      font-size: .7rem; color: var(--border);
    }

    .back-link {
      font-size: .72rem; color: var(--ink-muted);
      text-decoration: none;
      display: flex; align-items: center; gap: 5px;
      transition: color .2s;
    }

    .back-link:hover { color: var(--green); }

    .back-link svg { width:12px; height:12px; }

    /* ── RESPONSIVE ─────────────────────────────── */
    @media (max-width: 860px) {
      .panel-left  { display: none; }
      .panel-right { flex: 1; padding: 48px 32px; }
      .right-foot  { left: 32px; right: 32px; }
    }

    @media (max-width: 480px) {
      .panel-right { padding: 36px 22px; }
      .right-foot  { left: 22px; right: 22px; }
    }
  </style>
</head>
<body>
<div class="wrap">

  <!-- ══ LEFT PANEL ══ -->
  <div class="panel-left">
    <div class="grid-overlay"></div>
    <div class="panel-left-content">

      <!-- Top badge -->
      <div class="left-top-badge">
        <img src="img/ashi.jpg" alt="ASHI Logo" />
        <span>ASHI Amaya</span>
      </div>

      <!-- Hero text -->
      <div class="left-hero">
        <div class="accent-line"></div>
        <h2>Admin<br><em>Control Panel</em></h2>
        <p>Unified academic management for students, faculty, and institutional records.</p>
      </div>



    </div>
  </div>

  <!-- ══ RIGHT PANEL ══ -->
  <div class="panel-right">

    <!-- Logo -->
    <div class="logo-block">
      <img src="img/logo.png" alt="ASHI Logo" />
      <div>
        <div class="logo-name">ASHI AMAYA</div>
        <div class="logo-sub">Admin Portal</div>
      </div>
    </div>

    <!-- Heading -->
    <div class="form-head">
      <h1>Sign in</h1>
      <p>Restricted to authorized administrators only.</p>
    </div>

    <div class="rule"></div>

    <!-- Error alert -->
    <?php if ($error): ?>
    <div class="alert alert-error" id="alertBox">
      <svg viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
      </svg>
      <span id="alertText"><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" autocomplete="off">

      <div class="fgroup">
        <label for="username">Username</label>
        <div class="input-row">
          <span class="input-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
          </span>
          <input
            type="text"
            id="username"
            name="username"
            placeholder="Your admin username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            autocomplete="off"
            spellcheck="false"
            <?= $locked ? 'disabled' : '' ?>
            required
          />
        </div>
      </div>

      <div class="fgroup">
        <label for="password">Password</label>
        <div class="input-row">
          <span class="input-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
          </span>
          <input
            type="password"
            id="password"
            name="password"
            placeholder="Your password"
            autocomplete="new-password"
            <?= $locked ? 'disabled' : '' ?>
            required
          />
          <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Toggle password">
            <svg id="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-submit" id="btnSubmit" <?= $locked ? 'disabled' : '' ?>>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
          <polyline points="10 17 15 12 10 7"/>
          <line x1="15" y1="12" x2="3" y2="12"/>
        </svg>
        <span id="btnLabel">Sign In</span>
      </button>

      <!-- Attempt pips -->
      <?php
        $attempts = $_SESSION['login_attempts'] ?? 0;
        if ($attempts > 0 && !$locked):
      ?>
      <div class="attempt-pips" id="pips">
        <?php for ($i = 0; $i < MAX_ATTEMPTS; $i++): ?>
          <div class="pip <?= $i < $attempts ? 'used' : '' ?>"></div>
        <?php endfor; ?>
      </div>
      <?php endif; ?>

    </form>

    <!-- Footer -->
    <div class="right-foot">
      <span>© <?= date('Y') ?> ASHI Amaya</span>
    </div>

  </div>
</div>

<script>
/* ── Toggle password ────────────────────────── */
function togglePw() {
  const inp  = document.getElementById('password');
  const icon = document.getElementById('eye-icon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;
  } else {
    inp.type = 'password';
    icon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
  }
}

/* ── Lockout countdown ──────────────────────── */
(function () {
  var rem     = <?= (int)$lockout_rem ?>;
  if (rem <= 0) return;

  var btn     = document.getElementById('btnSubmit');
  var label   = document.getElementById('btnLabel');
  var alertTx = document.getElementById('alertText');

  btn.disabled = true;

  var iv = setInterval(function () {
    rem--;
    if (alertTx) alertTx.textContent = 'Too many failed attempts. Please wait ' + rem + ' second(s).';
    label.textContent = 'Wait ' + rem + 's\u2026';

    if (rem <= 0) {
      clearInterval(iv);
      btn.disabled = false;
      label.textContent = 'Sign In';
      document.getElementById('username').disabled = false;
      document.getElementById('password').disabled = false;
      var box = document.getElementById('alertBox');
      if (box) { box.style.opacity = '0'; box.style.transition = 'opacity .4s'; setTimeout(()=>box.remove(), 400); }
    }
  }, 1000);
})();
</script>
</body>
</html>