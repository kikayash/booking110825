<?php
// File: auto_status_manager.php
// Sistema otomatis untuk mengelola status booking

session_start();
require_once 'config.php';
require_once 'functions.php';

class BookingStatusManager {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Auto-approve bookings yang pending lebih dari 5 menit
     */
    public function autoApproveExpiredPending() {
        try {
            $stmt = $this->conn->prepare("
                UPDATE tbl_booking 
                SET status = 'approve', 
                    approved_at = NOW(),
                    approved_by = 'SYSTEM_AUTO',
                    approval_reason = 'Auto-approved: Admin tidak merespons dalam 5 menit'
                WHERE status = 'pending' 
                AND created_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            
            $stmt->execute();
            $affectedRows = $stmt->rowCount();
            
            if ($affectedRows > 0) {
                error_log("AUTO-APPROVAL: $affectedRows bookings auto-approved due to 5-minute timeout");
            }
            
            return $affectedRows;
        } catch (PDOException $e) {
            error_log("Error in autoApproveExpiredPending: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Auto-activate bookings yang sudah tiba waktunya
     */
    public function autoActivateCurrentBookings() {
        try {
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            
            $stmt = $this->conn->prepare("
                UPDATE tbl_booking 
                SET status = 'active',
                    activated_at = NOW(),
                    activated_by = 'SYSTEM_AUTO',
                    activation_note = 'Auto-activated: Waktu booking telah tiba'
                WHERE status = 'approve' 
                AND tanggal = ? 
                AND jam_mulai <= ? 
                AND jam_selesai > ?
            ");
            
            $stmt->execute([$currentDate, $currentTime, $currentTime]);
            $affectedRows = $stmt->rowCount();
            
            if ($affectedRows > 0) {
                error_log("AUTO-ACTIVATION: $affectedRows bookings auto-activated for current time");
            }
            
            return $affectedRows;
        } catch (PDOException $e) {
            error_log("Error in autoActivateCurrentBookings: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Auto-complete bookings yang sudah melewati waktu selesai
     */
    public function autoCompleteExpiredBookings() {
        try {
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            
            // Get expired active bookings
            $stmt = $this->conn->prepare("
                SELECT id_booking, nama_acara, nama_ruang, tanggal, jam_selesai, id_user
                FROM tbl_booking b
                JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                WHERE b.status = 'active' 
                AND (
                    (b.tanggal < ?) OR 
                    (b.tanggal = ? AND b.jam_selesai < ?)
                )
            ");
            
            $stmt->execute([$currentDate, $currentDate, $currentTime]);
            $expiredBookings = $stmt->fetchAll();
            
            $completedCount = 0;
            
            foreach ($expiredBookings as $booking) {
                // Auto-complete without checkout
                $updateStmt = $this->conn->prepare("
                    UPDATE tbl_booking 
                    SET status = 'done',
                        checkout_status = 'auto_completed',
                        checkout_time = NOW(),
                        completion_note = 'Auto-completed: Ruangan selesai dipakai tanpa checkout dari mahasiswa'
                    WHERE id_booking = ?
                ");
                
                if ($updateStmt->execute([$booking['id_booking']])) {
                    $completedCount++;
                    error_log("AUTO-COMPLETE: Booking ID {$booking['id_booking']} auto-completed without checkout");
                    
                    // Send notification to user
                    $this->sendAutoCompleteNotification($booking);
                }
            }
            
            return $completedCount;
        } catch (PDOException $e) {
            error_log("Error in autoCompleteExpiredBookings: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Send notification for auto-completed booking
     */
    private function sendAutoCompleteNotification($booking) {
        try {
            // Get user email
            $stmt = $this->conn->prepare("SELECT email FROM tbl_users WHERE id_user = ?");
            $stmt->execute([$booking['id_user']]);
            $user = $stmt->fetch();
            
            if ($user) {
                $booking['email'] = $user['email'];
                $booking['checkout_method'] = 'auto_complete';
                sendBookingNotification($booking['email'], $booking, 'auto_complete');
            }
        } catch (Exception $e) {
            error_log("Error sending auto-complete notification: " . $e->getMessage());
        }
    }
    
    /**
     * Get current booking statistics
     */
    public function getBookingStats() {
        try {
            $stats = [];
            
            // Count by status
            $stmt = $this->conn->prepare("
                SELECT status, COUNT(*) as count 
                FROM tbl_booking 
                WHERE tanggal >= CURDATE() 
                GROUP BY status
            ");
            $stmt->execute();
            $statusCounts = $stmt->fetchAll();
            
            foreach ($statusCounts as $status) {
                $stats[$status['status']] = $status['count'];
            }
            
            // Active bookings right now
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as active_now
                FROM tbl_booking 
                WHERE status = 'active' 
                AND tanggal = ? 
                AND jam_mulai <= ? 
                AND jam_selesai > ?
            ");
            $stmt->execute([$currentDate, $currentTime, $currentTime]);
            $activeNow = $stmt->fetchColumn();
            
            $stats['active_now'] = $activeNow;
            
            return $stats;
        } catch (PDOException $e) {
            error_log("Error in getBookingStats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Run all automatic processes
     */
    public function runAllAutoProcesses() {
        $results = [
            'auto_approved' => $this->autoApproveExpiredPending(),
            'auto_activated' => $this->autoActivateCurrentBookings(), 
            'auto_completed' => $this->autoCompleteExpiredBookings()
        ];
        
        // Log summary
        if (array_sum($results) > 0) {
            error_log("AUTO-PROCESSES SUMMARY: " . json_encode($results));
        }
        
        return $results;
    }
}

// Initialize and run if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $statusManager = new BookingStatusManager($conn);
    
    // Check if this is an AJAX call for stats
    if (isset($_GET['action']) && $_GET['action'] === 'stats') {
        header('Content-Type: application/json');
        echo json_encode($statusManager->getBookingStats());
        exit;
    }
    
    // Run auto processes
    if (isset($_GET['action']) && $_GET['action'] === 'run') {
        header('Content-Type: application/json');
        $results = $statusManager->runAllAutoProcesses();
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
    
    // Default: run auto processes
    $statusManager->runAllAutoProcesses();
}
?>