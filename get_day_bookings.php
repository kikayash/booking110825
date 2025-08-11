<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'Anda harus login terlebih dahulu'
    ]);
    exit;
}

try {
    // Get parameters
    $date = isset($_GET['date']) ? trim($_GET['date']) : '';
    $roomId = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;
    
    if (!$date) {
        echo json_encode([
            'success' => false,
            'message' => 'Tanggal tidak valid'
        ]);
        exit;
    }
    
    if (!$roomId) {
        echo json_encode([
            'success' => false,
            'message' => 'ID ruangan tidak valid'
        ]);
        exit;
    }
    
    // Validate date format
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        echo json_encode([
            'success' => false,
            'message' => 'Format tanggal tidak valid'
        ]);
        exit;
    }
    
    // Get bookings for the specific date and room
    $stmt = $conn->prepare("
        SELECT DISTINCT
            b.id_booking,
            b.id_user,
            b.id_ruang,
            b.tanggal,
            b.jam_mulai,
            b.jam_selesai,
            b.nama_acara,
            b.keterangan,
            b.nama as nama_penanggungjawab,              
            b.no_penanggungjawab,
            b.status,
            b.booking_type,
            b.created_at,
            b.nama_dosen,
            u.email,
            u.role,
            r.nama_ruang,
            r.kapasitas,
            g.nama_gedung,
            rs.nama_matakuliah,
            rs.kelas,
            rs.dosen_pengampu,
            CASE 
                WHEN b.booking_type = 'recurring' AND rs.nama_matakuliah IS NOT NULL 
                THEN rs.nama_matakuliah
                WHEN b.booking_type = 'recurring' AND rs.nama_matakuliah IS NULL 
                THEN 'Perkuliahan'
                ELSE b.nama_acara
            END as display_name
        FROM tbl_booking b 
        INNER JOIN tbl_users u ON b.id_user = u.id_user 
        INNER JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        LEFT JOIN tbl_recurring_schedules rs ON (b.id_schedule = rs.id_schedule AND b.booking_type = 'recurring')
        WHERE b.id_ruang = ? AND b.tanggal = ?
        AND b.status NOT IN ('cancelled', 'rejected')
        ORDER BY b.jam_mulai ASC
    ");
    
    $stmt->execute([$roomId, $date]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process bookings data
    $processedBookings = [];
    foreach ($bookings as $booking) {
        $statusBadge = getBookingStatusBadge($booking['status']);
        
        $processedBookings[] = [
            'id_booking' => $booking['id_booking'],
            'nama_acara' => $booking['display_name'],
            'jam_mulai' => $booking['jam_mulai'],
            'jam_selesai' => $booking['jam_selesai'],
            'status' => $booking['status'],
            'status_badge' => $statusBadge,
            'nama_dosen' => $booking['nama_dosen'] ?: $booking['nama_penanggungjawab'],
            'no_penanggungjawab' => $booking['no_penanggungjawab'],
            'keterangan' => $booking['keterangan'],
            'booking_type' => $booking['booking_type'],
            'is_academic' => $booking['booking_type'] === 'recurring'
        ];
    }
    
    // Format date for display
    $formattedDate = formatDate($date, 'l, d F Y');
    
    echo json_encode([
        'success' => true,
        'date' => $date,
        'formatted_date' => $formattedDate,
        'room_id' => $roomId,
        'bookings' => $processedBookings,
        'total_bookings' => count($processedBookings)
    ]);
    
} catch (Exception $e) {
    error_log("Get day bookings error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>