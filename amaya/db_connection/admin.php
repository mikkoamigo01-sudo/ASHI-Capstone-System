<?php
// ============================================================
// db_admin.php — Ashi Amaya Admin Database Connection
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'u304756553_ashi_classroom');         // Change to your MySQL username
define('DB_PASS', 'Ashi_Classroom17');             // Change to your MySQL password
define('DB_NAME', 'u304756553_ashi_classroom');        // Your database name

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

$conn->set_charset('utf8mb4');
?>