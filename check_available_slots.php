<?php
// File: check_available_slots.php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Check if user is logged in
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $date = $_GET['date'] ?? '';
    $roomId = $_GET['room_id'] ?? 0;
    $lastCheck = $_GET['last_check'] ?? '';

    if (!$date || !$roomId) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format']);
        exit;
    }

    // Get all bookings for the specified date and room
    $stmt = $conn->prepare("SELECT b.*, r.nama_ruang, g.nama_gedung 
                           FROM tbl_booking b 
                           JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                           LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                           WHERE b.tanggal = ? AND b.id_ruang = ?
                           ORDER BY b.jam_mulai");
    $stmt->execute([$date, $roomId]);
    $bookings = $stmt->fetchAll();

    // Get recently cancelled bookings (within last 5 minutes)
    $recentCancellations = [];
    if ($lastCheck) {
        $stmt = $conn->prepare("SELECT b.*, r.nama_ruang, g.nama_gedung 
                               FROM tbl_booking b 
                               JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                               LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                               WHERE b.tanggal = ? AND b.id_ruang = ? 
                               AND b.status = 'cancelled' 
                               AND b.cancelled_at > ?
                               ORDER BY b.cancelled_at DESC");
        $stmt->execute([$date, $roomId, $lastCheck]);
        $recentCancellations = $stmt->fetchAll();
    }

    // Generate time slots and check availability
    $availableSlots = [];
    $newlyAvailableSlots = [];
    
    // Time slots from 5:00 to 22:00 with 30-minute intervals
    $startTime = strtotime('05:00:00');
    $endTime = strtotime('22:00:00');
    $interval = 30 * 60; // 30 minutes
    
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    
    // Check if date is in booking range
    $today = date('Y-m-d');
    $maxDate = date('Y-m-d', strtotime('+1 month'));
    $isDateInRange = ($date >= $today && $date <= $maxDate);

    for ($time = $startTime; $time <= $endTime; $time += $interval) {
        $timeSlot = date('H:i', $time);
        $nextTimeSlot = date('H:i', $time + $interval);
        
        // Check if this time slot is booked
        $isBooked = false;
        $wasRecentlyCancelled = false;
        
        foreach ($bookings as $booking) {
            $bookingStart = date('H:i', strtotime($booking['jam_mulai']));
            $bookingEnd = date('H:i', strtotime($booking['jam_selesai']));
            
            if ($timeSlot >= $bookingStart && $timeSlot < $bookingEnd) {
                if (in_array($booking['status'], ['pending', 'approve', 'active'])) {
                    $isBooked = true;
                    break;
                }
            }
        }
        
        // Check if this slot was recently cancelled
        foreach ($recentCancellations as $cancelled) {
            $cancelledStart = date('H:i', strtotime($cancelled['jam_mulai']));
            $cancelledEnd = date('H:i', strtotime($cancelled['jam_selesai']));
            
            if ($timeSlot >= $cancelledStart && $timeSlot < $cancelledEnd) {
                $wasRecentlyCancelled = true;
                break;
            }
        }
        
        // Check if slot is available and not in the past
        $isAvailable = !$isBooked && $isDateInRange;
        
        // Don't show past time slots for today
        if ($date === $currentDate && $timeSlot < date('H:i', strtotime($currentTime))) {
            $isAvailable = false;
        }
        
        // Check if it's a holiday
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_harilibur WHERE tanggal = ?");
        $stmt->execute([$date]);
        $isHoliday = $stmt->fetchColumn() > 0;
        
        if ($isHoliday) {
            $isAvailable = false;
        }
        
        if ($isAvailable) {
            $slot = [
                'start_time' => $timeSlot,
                'end_time' => $nextTimeSlot,
                'time_slot' => $timeSlot . ' - ' . $nextTimeSlot,
                'date' => $date,
                'room_id' => $roomId,
                'room_name' => $bookings[0]['nama_ruang'] ?? 'Unknown Room',
                'building_name' => $bookings[0]['nama_gedung'] ?? 'Unknown Building'
            ];
            
            $availableSlots[] = $slot;
            
            // Mark as newly available if recently cancelled
            if ($wasRecentlyCancelled) {
                $newlyAvailableSlots[] = $slot;
            }
        }
    }

    // Get room information
    $stmt = $conn->prepare("SELECT r.*, g.nama_gedung 
                           FROM tbl_ruang r 
                           LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                           WHERE r.id_ruang = ?");
    $stmt->execute([$roomId]);
    $roomInfo = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'date' => $date,
        'room_id' => $roomId,
        'room_info' => $roomInfo,
        'available_slots' => $availableSlots,
        'newly_available_slots' => $newlyAvailableSlots,
        'total_available' => count($availableSlots),
        'recent_cancellations' => count($recentCancellations),
        'last_check' => date('Y-m-d H:i:s'),
        'is_holiday' => $isHoliday ?? false
    ]);

} catch (PDOException $e) {
    error_log("Database error in check_available_slots.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error in check_available_slots.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} catch (Error $e) {
    error_log("Fatal error in check_available_slots.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
}
?>