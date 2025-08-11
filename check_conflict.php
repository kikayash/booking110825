<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is CS
if (!isLoggedIn() || !isCS()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'] ?? '';
    $jam_mulai = $_POST['jam_mulai'] ?? '';
    $jam_selesai = $_POST['jam_selesai'] ?? '';
    $id_ruang = $_POST['id_ruang'] ?? '';
    
    try {
        // Check for conflicts
        $sql = "SELECT b.*, u.email, r.nama_ruang, rs.nama_matakuliah 
                FROM tbl_booking b 
                JOIN tbl_users u ON b.id_user = u.id_user 
                JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
                LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
                WHERE b.id_ruang = ? 
                  AND b.tanggal = ? 
                  AND b.status IN ('pending', 'approve', 'active')
                  AND NOT (b.jam_selesai <= ? OR b.jam_mulai >= ?)
                ORDER BY b.jam_mulai ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id_ruang, $tanggal, $jam_mulai, $jam_selesai]);
        $conflicts = $stmt->fetchAll();
        
        $response = [
            'hasConflict' => count($conflicts) > 0,
            'conflictDetails' => []
        ];
        
        foreach ($conflicts as $conflict) {
            $response['conflictDetails'][] = [
                'nama_acara' => $conflict['nama_acara'],
                'jam_mulai' => date('H:i', strtotime($conflict['jam_mulai'])),
                'jam_selesai' => date('H:i', strtotime($conflict['jam_selesai'])),
                'peminjam' => $conflict['email'],
                'matakuliah' => $conflict['nama_matakuliah']
            ];
        }
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>