<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID booking tidak valid']);
    exit;
}

$bookingId = intval($_GET['id']);

try {
    // Get academic schedule details
    $stmt = $conn->prepare("
        SELECT b.*, 
               r.nama_ruang, r.kapasitas, g.nama_gedung, r.lokasi,
               rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu, 
               rs.hari, rs.semester, rs.tahun_akademik,
               u.email
        FROM tbl_booking b
        JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
        JOIN tbl_users u ON b.id_user = u.id_user
        WHERE b.id_booking = ? AND b.booking_type = 'recurring'
    ");
    
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Jadwal perkuliahan tidak ditemukan']);
        exit;
    }
    
    // Format data for display
    $dayMapping = [
        'monday' => 'Senin',
        'tuesday' => 'Selasa', 
        'wednesday' => 'Rabu',
        'thursday' => 'Kamis',
        'friday' => 'Jumat',
        'saturday' => 'Sabtu',
        'sunday' => 'Minggu'
    ];
    
    $booking['hari_indo'] = $dayMapping[$booking['hari']] ?? ucfirst($booking['hari']);
    $booking['formatted_date'] = formatDate($booking['tanggal'], 'l, d F Y');
    $booking['jam_mulai'] = formatTime($booking['jam_mulai']);
    $booking['jam_selesai'] = formatTime($booking['jam_selesai']);
    
    echo json_encode([
        'success' => true,
        'booking' => $booking
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_academic_detail.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Terjadi kesalahan saat memuat detail'
    ]);
}
?>