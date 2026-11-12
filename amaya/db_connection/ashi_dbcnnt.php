<?php
// File: ashi_dbcnnt.php
define('DB_HOST', 'localhost');
define('DB_USER', 'u304756553_ashi_classroom');
define('DB_PASS', 'Ashi_Classroom17');
define('DB_NAME', 'u304756553_ashi_classroom');
define('DB_CHARSET', 'utf8mb4');

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === 'ashi_dbcnnt.php') {
    http_response_code(403);
    die('Direct access not allowed.');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // Don't expose actual error details in production
    error_log('DB Connection failed: ' . $conn->connect_error);
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please try again later.'
    ]));
}
$conn->set_charset(DB_CHARSET);

// Prevent SQL injection at connection level
$conn->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
?>