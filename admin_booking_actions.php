<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is admin
if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $action = $_POST['action'] ?? '';
    $bookingId = $_POST['booking_id'] ?? 0;
    $reason = $_POST['reason'] ?? '';

    if (!$bookingId || !$action) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }

    // Get booking details
    $booking = getBookingById($conn, $bookingId);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    switch ($action) {
        case 'approve':
            handleApproveBooking($conn, $bookingId, $booking, $reason);
            break;
            
        case 'reject':
            handleRejectBooking($conn, $bookingId, $booking, $reason);
            break;
            
        case 'cancel':
            handleCancelBooking($conn, $bookingId, $booking, $reason);
            break;
            
        case 'force_checkout':
            handleForceCheckout($conn, $bookingId, $booking, $reason);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }

} catch (Exception $e) {
    error_log("Error in admin_booking_actions.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function handleApproveBooking($conn, $bookingId, $booking, $reason) {
    if ($booking['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Booking is not in pending status']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("UPDATE tbl_booking 
                               SET status = 'approve', 
                                   approved_at = NOW(),
                                   approved_by = ?,
                                   approval_reason = ?,
                                   user_can_activate = 1
                               WHERE id_booking = ?");
        
        $result = $stmt->execute([$_SESSION['user_id'], $reason ?: 'Approved by admin', $bookingId]);
        
        if ($result) {
            // Send notification to user
            $booking['approval_reason'] = $reason ?: 'Approved by admin';
            sendBookingNotification($booking['email'], $booking, 'approval');
            
            error_log("ADMIN APPROVAL: Booking ID $bookingId approved by admin {$_SESSION['user_id']}");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Booking berhasil disetujui',
                'status' => 'approve'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to approve booking']);
        }
    } catch (PDOException $e) {
        error_log("Database error in handleApproveBooking: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function handleRejectBooking($conn, $bookingId, $booking, $reason) {
    if ($booking['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Booking is not in pending status']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("UPDATE tbl_booking 
                               SET status = 'rejected', 
                                   rejected_at = NOW(),
                                   rejected_by = ?,
                                   reject_reason = ?
                               WHERE id_booking = ?");
        
        $result = $stmt->execute([$_SESSION['user_id'], $reason ?: 'Rejected by admin', $bookingId]);
        
        if ($result) {
            // Send notification to user
            $booking['reject_reason'] = $reason ?: 'Rejected by admin';
            sendBookingNotification($booking['email'], $booking, 'rejection');
            
            error_log("ADMIN REJECTION: Booking ID $bookingId rejected by admin {$_SESSION['user_id']}");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Booking berhasil ditolak',
                'status' => 'rejected'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to reject booking']);
        }
    } catch (PDOException $e) {
        error_log("Database error in handleRejectBooking: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function handleCancelBooking($conn, $bookingId, $booking, $reason) {
    if (!in_array($booking['status'], ['pending', 'approve'])) {
        echo json_encode(['success' => false, 'message' => 'Booking cannot be cancelled in current status']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("UPDATE tbl_booking 
                               SET status = 'cancelled', 
                                   cancelled_at = NOW(),
                                   cancelled_by = ?,
                                   cancellation_reason = ?
                               WHERE id_booking = ?");
        
        $result = $stmt->execute([$_SESSION['user_id'], $reason ?: 'Cancelled by admin', $bookingId]);
        
        if ($result) {
            // Send notification to user
            $booking['cancelled_by_admin'] = true;
            $booking['cancellation_reason'] = $reason ?: 'Cancelled by admin';
            sendBookingNotification($booking['email'], $booking, 'admin_cancellation');
            
            error_log("ADMIN CANCELLATION: Booking ID $bookingId cancelled by admin {$_SESSION['user_id']} - Slot now available");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Booking berhasil dibatalkan. Slot waktu tersebut sekarang tersedia untuk pengguna lain.',
                'status' => 'cancelled',
                'slot_available' => true
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to cancel booking']);
        }
    } catch (PDOException $e) {
        error_log("Database error in handleCancelBooking: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function handleForceCheckout($conn, $bookingId, $booking, $reason) {
    if ($booking['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Booking is not in active status']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("UPDATE tbl_booking 
                               SET status = 'done', 
                                   checkout_status = 'force_checkout',
                                   checkout_time = NOW(),
                                   checked_out_by = ?,
                                   completion_note = ?
                               WHERE id_booking = ?");
        
        $result = $stmt->execute([
            $_SESSION['user_id'], 
            $reason ?: 'Force checkout by admin', 
            $bookingId
        ]);
        
        if ($result) {
            // Send notification to user
            $booking['checkout_method'] = 'force_checkout';
            $booking['completion_note'] = $reason ?: 'Force checkout by admin';
            sendBookingNotification($booking['email'], $booking, 'checkout_confirmation');
            
            error_log("ADMIN FORCE CHECKOUT: Booking ID $bookingId force checked out by admin {$_SESSION['user_id']}");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Booking berhasil di-checkout secara paksa',
                'status' => 'done'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to force checkout booking']);
        }
    } catch (PDOException $e) {
        error_log("Database error in handleForceCheckout: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}
?>