<?php
// File: admin/ajax/test.php
// Pastikan tidak ada spasi atau karakter sebelum <?php

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    echo json_encode([
        'success' => true,
        'message' => 'AJAX endpoint working perfectly!',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => phpversion(),
        'file_path' => __FILE__
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>