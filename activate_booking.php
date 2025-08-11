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

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Method tidak diizinkan'
    ]);
    exit;
}

try {
    // Get booking ID from POST data
    $bookingId = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    
    if (!$bookingId) {
        echo json_encode([
            'success' => false,
            'message' => 'ID booking tidak valid'
        ]);
        exit;
    }
    
    // Get booking details
    $booking = getBookingById($conn, $bookingId);
    
    if (!$booking) {
        echo json_encode([
            'success' => false,
            'message' => 'Booking tidak ditemukan'
        ]);
        exit;
    }
    
    // Check user permissions
    $currentUserId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'];
    $userEmail = $_SESSION['email'];
    $isOwner = ($booking['id_user'] == $currentUserId);
    $isAdmin = ($userRole === 'admin');
    
    if (!$isOwner && !$isAdmin) {
        echo json_encode([
            'success' => false,
            'message' => 'Anda tidak memiliki izin untuk mengaktifkan booking ini'
        ]);
        exit;
    }
    
    // Validate booking status
    if ($booking['status'] !== 'approve') {
        echo json_encode([
            'success' => false,
            'message' => 'Hanya booking dengan status "Disetujui" yang dapat diaktifkan. Status saat ini: ' . ucfirst($booking['status'])
        ]);
        exit;
    }
    
    // Check timing constraints
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    
    $bookingDate = $booking['tanggal'];
    $bookingStartTime = $booking['jam_mulai'];
    $bookingEndTime = $booking['jam_selesai'];
    
    // Can only activate on the booking date
    if ($bookingDate !== $currentDate) {
        echo json_encode([
            'success' => false,
            'message' => 'Booking hanya dapat diaktifkan pada hari yang sama dengan jadwal booking'
        ]);
        exit;
    }
    
    // Check if current time is within allowed activation window
    $bookingStartTimestamp = strtotime($bookingStartTime);
    $currentTimestamp = strtotime($currentTime);
    $timeDifference = $bookingStartTimestamp - $currentTimestamp;
    
    // Allow activation 30 minutes before start time until 5 minutes after start time
    $allowedBeforeMinutes = 30 * 60; // 30 minutes before
    $allowedAfterMinutes = 5 * 60;   // 5 minutes after
    
    if ($timeDifference > $allowedBeforeMinutes) {
        $minutesUntilAllowed = floor($timeDifference / 60) - 30;
        echo json_encode([
            'success' => false,
            'message' => "Booking dapat diaktifkan 30 menit sebelum jadwal dimulai. Silakan coba lagi dalam {$minutesUntilAllowed} menit."
        ]);
        exit;
    }
    
    if ($timeDifference < -$allowedAfterMinutes) {
        echo json_encode([
            'success' => false,
            'message' => 'Waktu aktivasi telah berakhir. Booking hanya dapat diaktifkan hingga 5 menit setelah jadwal dimulai.'
        ]);
        exit;
    }
    
    // Check if booking time has not ended
    if ($currentTime > $bookingEndTime) {
        echo json_encode([
            'success' => false,
            'message' => 'Booking tidak dapat diaktifkan karena waktu booking telah berakhir'
        ]);
        exit;
    }
    
    // Check for conflicts with other active bookings
    if (hasBookingConflict($conn, $booking['id_ruang'], $bookingDate, $bookingStartTime, $bookingEndTime, $bookingId)) {
        echo json_encode([
            'success' => false,
            'message' => 'Tidak dapat mengaktifkan booking karena terdapat konflik dengan booking lain yang sedang aktif'
        ]);
        exit;
    }
    
    // Check if room is locked
    if (isRoomLocked($conn, $booking['id_ruang'], $bookingDate)) {
        $lockInfo = getRoomLockInfo($conn, $booking['id_ruang'], $bookingDate);
        echo json_encode([
            'success' => false,
            'message' => 'Ruangan sedang terkunci: ' . ($lockInfo['reason'] ?? 'Ruangan tidak tersedia')
        ]);
        exit;
    }
    
    // Determine activation details
    $activatedBy = $isOwner ? "USER ({$userEmail})" : "ADMIN ({$userEmail})";
    $activationNote = $isOwner ? 
        'Booking diaktifkan oleh mahasiswa: ' . $booking['nama_penanggungjawab'] :
        'Booking diaktifkan oleh admin: ' . $userEmail;
    
    // Perform activation
    $stmt = $conn->prepare("
        UPDATE tbl_booking 
        SET status = 'active',
            activated_at = NOW(),
            activated_by = ?,
            activation_note = ?,
            activated_by_user = ?,
            user_can_activate = 1
        WHERE id_booking = ?
    ");
    
    $activatedByUser = $isOwner ? 1 : 0;
    $result = $stmt->execute([$activatedBy, $activationNote, $activatedByUser, $bookingId]);
    
    if ($result) {
        // Prepare success response
        $response = [
            'success' => true,
            'message' => 'Booking berhasil diaktifkan! Ruangan sekarang sedang digunakan.',
            'booking_info' => [
                'id' => $bookingId,
                'nama_acara' => $booking['nama_acara'],
                'nama_ruang' => $booking['nama_ruang'],
                'nama_gedung' => $booking['nama_gedung'] ?? '',
                'tanggal' => $booking['tanggal'],
                'jam_mulai' => $booking['jam_mulai'],
                'jam_selesai' => $booking['jam_selesai'],
                'nama_penanggungjawab' => $booking['nama_penanggungjawab']
            ],
            'activation_details' => [
                'activated_by' => $activatedBy,
                'activated_at' => date('Y-m-d H:i:s'),
                'activation_note' => $activationNote,
                'activated_by_user' => $isOwner
            ],
            'status_change' => 'Status berubah dari DISETUJUI menjadi AKTIF',
            'next_steps' => [
                'message' => 'Selamat menggunakan ruangan! Jangan lupa untuk melakukan checkout setelah selesai.',
                'checkout_reminder' => 'Checkout diperlukan untuk menyelesaikan booking dan membuat ruangan tersedia untuk user lain.'
            ]
        ];
        
        // Log successful activation
        error_log("ACTIVATION SUCCESS: {$activatedBy} activated booking #{$bookingId}");
        error_log("ACTIVATION TIME: " . date('Y-m-d H:i:s'));
        error_log("ROOM NOW ACTIVE: {$booking['nama_ruang']} until {$booking['jam_selesai']}");
        
        // Send activation notification
        $notificationData = array_merge($booking, [
            'activated_by' => $activatedBy,
            'activated_at' => date('Y-m-d H:i:s'),
            'activation_note' => $activationNote
        ]);
        
        sendEnhancedBookingNotification($booking['email'], $notificationData, 'activation');
        
        // Auto-schedule checkout reminder if it's a user activation
        if ($isOwner) {
            scheduleCheckoutReminder($conn, $bookingId, $booking);
        }
        
        echo json_encode($response);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengaktifkan booking. Silakan coba lagi.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Activation error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}

/**
 * Schedule checkout reminder for user
 */
function scheduleCheckoutReminder($conn, $bookingId, $booking) {
    try {
        // Calculate reminder time (15 minutes before end time)
        $endDateTime = new DateTime($booking['tanggal'] . ' ' . $booking['jam_selesai']);
        $reminderDateTime = clone $endDateTime;
        $reminderDateTime->sub(new DateInterval('PT15M')); // 15 minutes before
        
        // You can implement a reminder system here
        // For now, just log the reminder schedule
        error_log("CHECKOUT REMINDER SCHEDULED: Booking #{$bookingId} reminder at {$reminderDateTime->format('Y-m-d H:i:s')}");
        
        return true;
    } catch (Exception $e) {
        error_log("Error scheduling checkout reminder: " . $e->getMessage());
        return false;
    }
}
?>