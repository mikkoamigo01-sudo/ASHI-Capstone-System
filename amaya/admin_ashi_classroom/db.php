<?php
// ============================================================
// ASHI AMAYA — db.php
// Place this file in the SAME folder as all other PHP files.
// Edit DB_USER and DB_PASS to match your MySQL credentials.
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 0); // Errors go to log, not into JSON output

define('DB_HOST', 'localhost');
define('DB_NAME', 'u304756553_ashi_classroom');
define('DB_USER', 'u304756553_ashi_classroom');   // <-- change this
define('DB_PASS', 'Ashi_Classroom17');       // <-- change this (empty = no password)

function getDB() {
    static $conn = null;
    if ($conn !== null) return $conn;

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode([
            'error'  => 'Database connection failed',
            'detail' => $conn->connect_error
        ]);
        exit;
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}
