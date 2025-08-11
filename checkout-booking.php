<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Add debugging
error_log("CHECKOUT DEBUG: Request method = " . $_SERVER['REQUEST_METHOD']);
error_log("CHECKOUT DEBUG: POST data = " . print_r($_POST, true));
error_log("CHECKOUT DEBUG: Session data = " . print_r($_SESSION, true));

// Check if user is logged in - FIXED VERSION
if (!isLoggedIn()) {
    error_log("CHECKOUT ERROR: User not logged in");
    echo json_encode([
        'success' => false,
        'message' => 'Anda harus login terlebih dahulu',
        'debug' => 'User not logged in'
    ]);
    exit;
}

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
    $isOwner = ($booking['id_user'] == $currentUserId);
    $isAdmin = ($userRole === 'admin');
    
    if (!$isOwner && !$isAdmin) {
        echo json_encode([
            'success' => false,
            'message' => 'Anda tidak memiliki izin untuk checkout booking ini'
        ]);
        exit;
    }
    
    // Validate booking status
    if ($booking['status'] !== 'active') {
        echo json_encode([
            'success' => false,
            'message' => 'Hanya booking dengan status aktif yang dapat di-checkout. Status saat ini: ' . ucfirst($booking['status'])
        ]);
        exit;
    }
    
    // Determine checkout type
    $checkoutBy = 'USER_MANUAL';
    $checkoutNote = null;
    
    if ($isAdmin && !$isOwner) {
        $checkoutBy = 'ADMIN_FORCE';
        $checkoutNote = 'Force checkout oleh admin: ' . $_SESSION['email'];
    } elseif ($isOwner) {
        $checkoutBy = 'USER_MANUAL';
        $checkoutNote = 'Checkout manual oleh mahasiswa: ' . $booking['nama_penanggungjawab'];
    }
    
    // Perform enhanced checkout
    $result = enhancedCheckoutBooking($conn, $bookingId, $checkoutBy, $checkoutNote);
    
    if ($result['success']) {
        // Add additional information to response
        $response = [
            'success' => true,
            'message' => $result['message'],
            'booking_info' => [
                'id' => $bookingId,
                'nama_acara' => $booking['nama_acara'],
                'nama_ruang' => $booking['nama_ruang'],
                'tanggal' => $booking['tanggal'],
                'jam_mulai' => $booking['jam_mulai'],
                'jam_selesai' => $booking['jam_selesai']
            ],
            'checkout_details' => $result['checkout_info'],
            'status_change' => 'Status berubah dari AKTIF menjadi SELESAI',
            'slot_available' => true,
            'availability_message' => getSlotAvailabilityMessage($booking)
        ];
        
        // Log successful checkout
        error_log("CHECKOUT SUCCESS: User {$_SESSION['email']} checked out booking #{$bookingId}");
        error_log("CHECKOUT TYPE: {$checkoutBy}");
        error_log("SLOT NOW AVAILABLE: {$booking['nama_ruang']} on {$booking['tanggal']} {$booking['jam_mulai']}-{$booking['jam_selesai']}");
        
        echo json_encode($response);
    } else {
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("Checkout error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>