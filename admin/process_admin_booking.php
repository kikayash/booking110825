<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_add_booking.php?error=invalid_method');
    exit;
}

// Get booking data from POST
$userId = $_POST['id_user'] ?? '';
$roomId = $_POST['id_ruang'] ?? '';
$date = $_POST['tanggal'] ?? '';
$startTime = $_POST['jam_mulai'] ?? '';
$endTime = $_POST['jam_selesai'] ?? '';
$eventName = $_POST['nama_acara'] ?? '';
$description = $_POST['keterangan'] ?? '';
$picName = $_POST['nama_penanggungjawab'] ?? '';
$picPhone = $_POST['no_penanggungjawab'] ?? '';
$status = $_POST['status'] ?? 'pending';
$isExternal = isset($_POST['is_external']) ? 1 : 0; // Menangkap nilai checkbox eksternal

// Jika booking eksternal, tambahkan informasi ini ke deskripsi
if ($isExternal) {
    $description = "[EKSTERNAL] " . $description;
}

// Validate inputs
if (empty($userId) || empty($roomId) || empty($date) || empty($startTime) || empty($endTime) || 
    empty($eventName) || empty($picName) || empty($picPhone)) {
    header('Location: admin_add_booking.php?error=missing_fields');
    exit;
}

// Validate date (not in the past and within booking range)
$todayDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+1 month'));
if ($date < $todayDate || $date > $maxDate) {
    header('Location: admin_add_booking.php?error=invalid_date');
    exit;
}

// Validate time (within business hours and end time after start time)
if ($startTime >= $endTime) {
    header('Location: admin_add_booking.php?error=invalid_time_range');
    exit;
}

// Check if date is a holiday
if (isHoliday($conn, $date)) {
    header('Location: admin_add_booking.php?error=holiday');
    exit;
}

// Check if room exists
$room = getRoomById($conn, $roomId);
if (!$room) {
    header('Location: admin_add_booking.php?error=invalid_room');
    exit;
}

// Check if user exists
$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE id_user = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    header('Location: admin_add_booking.php?error=invalid_user');
    exit;
}

// Check for booking conflicts if status is approve or active
if (in_array($status, ['approve', 'active'])) {
    if (hasBookingConflict($conn, $roomId, $date, $startTime, $endTime)) {
        header('Location: admin_add_booking.php?error=booking_conflict');
        exit;
    }
}

// Insert booking
try {
    $stmt = $conn->prepare("INSERT INTO tbl_booking 
                       (id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai, 
                        keterangan, nama_penanggungjawab, no_penanggungjawab, status, is_external) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$result = $stmt->execute([
    $userId, $roomId, $eventName, $date, $startTime, $endTime, 
    $description, $picName, $picPhone, $status, $isExternal
]);
    
    if ($result) {
        // Get the booking ID
        $bookingId = $conn->lastInsertId();
        
        // Get booking details
        $booking = getBookingById($conn, $bookingId);
        
        // Send email notification if status is approve
        if ($status === 'approve') {
            sendBookingNotification($user['email'], $booking, 'approval');
        }
        
        header('Location: admin_add_booking.php?success=booking_added');
    } else {
        header('Location: admin_add_booking.php?error=booking_failed');
    }
} catch (PDOException $e) {
    header('Location: admin_add_booking.php?error=database_error&message=' . urlencode($e->getMessage()));
}