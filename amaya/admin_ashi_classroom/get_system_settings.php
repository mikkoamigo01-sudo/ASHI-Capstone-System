<?php
// reset/get_system_settings.php

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized. Admin login required.']));
}

$host    = 'localhost';
$db      = 'u304756553_ashi_classroom';
$user    = 'u304756553_ashi_classroom';
$pass    = 'Ashi_Classroom17';
$charset = 'utf8mb4';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed: ' . $conn->connect_error]);
    exit;
}
$conn->set_charset($charset);

// ── Fetch current settings ──
$result   = $conn->query("SELECT * FROM school_year_settings ORDER BY updated_at DESC LIMIT 1");
$settings = $result ? $result->fetch_assoc() : null;

if (!$settings) {
    echo json_encode(['error' => 'No settings row found in school_year_settings.']);
    $conn->close();
    exit;
}

$current_quarter = (int)$settings['current_quarter'];
$school_year     = $settings['school_year'];
$updated_at      = $settings['updated_at'] ?? '';

$action = $_GET['action'] ?? '';

// ════════════════════════════════
// ACTION: set_quarter
// Admin can freely set any quarter (1–4), no forward-only restriction
// ════════════════════════════════
if ($action === 'set_quarter') {
    $new_q = (int)($_GET['quarter'] ?? 0);

    if ($new_q < 1 || $new_q > 4) {
        echo json_encode(['success' => false, 'message' => 'Quarter must be between 1 and 4.']);
        $conn->close(); exit;
    }
    if ($new_q === $current_quarter) {
        echo json_encode(['success' => false, 'message' => 'That is already the current quarter.']);
        $conn->close(); exit;
    }

    $stmt = $conn->prepare("UPDATE school_year_settings SET current_quarter = ? WHERE id = 1");
    $stmt->bind_param('i', $new_q);
    $stmt->execute();
    $stmt->close();

    // Re-fetch updated_at
    $row = $conn->query("SELECT updated_at FROM school_year_settings WHERE id = 1")->fetch_assoc();
    echo json_encode(['success' => true, 'updated_at' => $row['updated_at'] ?? '']);
    $conn->close(); exit;
}

// ════════════════════════════════
// ACTION: set_year
// Must be forward only and span exactly one year
// ════════════════════════════════
if ($action === 'set_year') {
    $new_year = trim($_GET['year'] ?? '');

    if (!preg_match('/^\d{4}-\d{4}$/', $new_year)) {
        echo json_encode(['success' => false, 'message' => 'Invalid format. Use YYYY-YYYY (e.g. 2025-2026).']);
        $conn->close(); exit;
    }

    [$new_start, $new_end] = explode('-', $new_year);
    [$cur_start]           = explode('-', $school_year);

    if ((int)$new_start <= (int)$cur_start) {
        echo json_encode(['success' => false, 'message' => 'You cannot go back to a previous or same school year.']);
        $conn->close(); exit;
    }
    if ((int)$new_end !== (int)$new_start + 1) {
        echo json_encode(['success' => false, 'message' => 'School year must span exactly one year (e.g. 2025-2026).']);
        $conn->close(); exit;
    }

    $stmt = $conn->prepare("UPDATE school_year_settings SET school_year = ?, current_quarter = 1 WHERE id = 1");
    $stmt->bind_param('s', $new_year);
    $stmt->execute();
    $stmt->close();

    $row = $conn->query("SELECT updated_at FROM school_year_settings WHERE id = 1")->fetch_assoc();
    echo json_encode(['success' => true, 'updated_at' => $row['updated_at'] ?? '']);
    $conn->close(); exit;
}

// ════════════════════════════════
// DEFAULT: Return current settings
// ════════════════════════════════
echo json_encode([
    'school_year'     => $school_year,
    'current_quarter' => $current_quarter,
    'updated_at'      => $updated_at,
]);

$conn->close();