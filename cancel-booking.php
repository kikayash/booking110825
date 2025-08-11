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
    $bookingId = isset($_POST['id_booking']) ? intval($_POST['id_booking']) : 0;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    
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
            'message' => 'Anda tidak memiliki izin untuk membatalkan booking ini'
        ]);
        exit;
    }
    
    // Check if booking can be cancelled
    if (!in_array($booking['status'], ['pending', 'approve'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Booking dengan status "' . ucfirst($booking['status']) . '" tidak dapat dibatalkan'
        ]);
        exit;
    }
    
    // Check booking time (prevent cancellation if booking time has passed)
    $currentDateTime = new DateTime();
    $bookingDateTime = new DateTime($booking['tanggal'] . ' ' . $booking['jam_mulai']);
    
    if ($currentDateTime > $bookingDateTime && !$isAdmin) {
        echo json_encode([
            'success' => false,
            'message' => 'Booking yang sudah melewati waktu mulai tidak dapat dibatalkan'
        ]);
        exit;
    }
    
    // Determine cancellation details
    $cancelledBy = $isAdmin ? "ADMIN ({$userEmail})" : "USER ({$userEmail})";
    $cancellationReason = '';
    
    if ($isAdmin && !$isOwner) {
        $cancellationReason = 'Dibatalkan oleh admin';
        if (!empty($reason)) {
            $cancellationReason .= ': ' . $reason;
        }
    } else {
        $cancellationReason = 'Dibatalkan oleh mahasiswa';
        if (!empty($reason)) {
            $cancellationReason .= ': ' . $reason;
        }
    }
    
    // Perform enhanced cancellation
    $result = enhancedCancelBooking($conn, $bookingId, $cancelledBy, $cancellationReason);
    
    if ($result['success']) {
        // Prepare detailed response
        $response = [
            'success' => true,
            'message' => $result['message'],
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
            'cancellation_details' => [
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'reason' => $cancellationReason,
                'cancelled_by_admin' => $isAdmin && !$isOwner
            ],
            'slot_info' => $result['slot_info'],
            'status_change' => 'Status berubah dari ' . strtoupper($booking['status']) . ' menjadi DIBATALKAN',
            'slot_available' => true,
            'availability_message' => generateSlotAvailabilityMessage($booking)
        ];
        
        // Log successful cancellation
        error_log("CANCELLATION SUCCESS: {$cancelledBy} cancelled booking #{$bookingId}");
        error_log("CANCELLATION REASON: {$cancellationReason}");
        error_log("SLOT NOW AVAILABLE: {$booking['nama_ruang']} on {$booking['tanggal']} {$booking['jam_mulai']}-{$booking['jam_selesai']}");
        
        // Send enhanced notification
        $notificationData = array_merge($booking, [
            'cancelled_by' => $cancelledBy,
            'cancellation_reason' => $cancellationReason,
            'cancelled_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($isAdmin && !$isOwner) {
            sendEnhancedBookingNotification($booking['email'], $notificationData, 'admin_cancellation');
        } else {
            sendEnhancedBookingNotification($booking['email'], $notificationData, 'cancellation');
        }
        
        echo json_encode($response);
    } else {
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("Cancellation error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}

/**
 * Generate slot availability message for cancellation
 */
function generateSlotAvailabilityMessage($booking) {
    $message = "🎉 <strong>SLOT RUANGAN TERSEDIA KEMBALI!</strong><br>";
    $message .= "<div class='alert alert-success mt-2 mb-0'>";
    $message .= "<i class='fas fa-check-circle me-2'></i>";
    $message .= "<strong>Informasi Slot:</strong><br>";
    $message .= "📍 Ruangan: {$booking['nama_ruang']}<br>";
    $message .= "📅 Tanggal: " . formatDate($booking['tanggal']) . "<br>";
    $message .= "⏰ Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "<br>";
    $message .= "<small class='text-success'>Slot ini kini dapat dibooking oleh user lain</small>";
    $message .= "</div>";
    return $message;
}
?>