<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

function sendResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Get room ID from GET parameter
    $roomId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if (!$roomId) {
        sendResponse([
            'success' => false,
            'message' => 'ID ruangan tidak valid'
        ]);
    }
    
    // Get room details
    $stmt = $conn->prepare("
        SELECT r.*, g.nama_gedung 
        FROM tbl_ruang r 
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
        WHERE r.id_ruang = ?
    ");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$room) {
        sendResponse([
            'success' => false,
            'message' => 'Ruangan tidak ditemukan'
        ]);
    }
    
    // Process facilities
    $facilities = [];
    if (!empty($room['fasilitas'])) {
        $facilitiesData = json_decode($room['fasilitas'], true);
        if (is_array($facilitiesData)) {
            $facilities = $facilitiesData;
        } else {
            // Handle string format like '[\"AC\",\"Proyektor\",\"WiFi\"]'
            $facilitiesStr = trim($room['fasilitas'], '[]"');
            $facilities = array_map('trim', explode('","', $facilitiesStr));
            $facilities = array_filter($facilities); // Remove empty values
        }
    }
    
    sendResponse([
        'success' => true,
        'room' => [
            'id_ruang' => $room['id_ruang'],
            'nama_ruang' => $room['nama_ruang'],
            'kapasitas' => $room['kapasitas'],
            'lokasi' => $room['lokasi'],
            'nama_gedung' => $room['nama_gedung'] ?? 'Tidak diketahui',
            'fasilitas' => $facilities,
            'allowed_roles' => $room['allowed_roles'] ?? 'admin,mahasiswa,dosen,karyawan',
            'description' => $room['description'] ?? ''
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get room info error: " . $e->getMessage());
    
    sendResponse([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>