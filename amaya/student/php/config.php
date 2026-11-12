<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'u304756553_ashi_classroom');
define('DB_PASS', 'Ashi_Classroom17');
define('DB_NAME', 'u304756553_ashi_classroom');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4 for full Unicode support
$conn->set_charset("utf8mb4");

// Optional: Set timezone (adjust to your timezone)
date_default_timezone_set('Asia/Manila');
?>