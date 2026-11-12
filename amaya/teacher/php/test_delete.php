<?php
// Simple test file to verify path
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'DELETE FILE FOUND! Path is correct.',
    'server_info' => [
        'file_location' => __FILE__,
        'current_dir' => __DIR__
    ]
]);
?>
