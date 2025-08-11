<?php
// File: cron_auto_manager.php
// Automated booking management - Auto-approval khusus untuk dosen

session_start();
require_once 'config.php';
require_once 'functions.php';

class AutoBookingManager {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Auto-approve bookings khusus untuk dosen yang pending lebih dari 5 menit SEBELUM waktu booking
     * atau pending normal yang sudah 5 menit tidak direspons admin
     */
    public function autoApproveExpiredPending() {
        try {
            $currentDateTime = new DateTime();
            $approvedCount = 0;
            
            // Get all pending bookings
            $stmt = $this->conn->prepare("
                SELECT b.id_booking, b.nama_acara, b.tanggal, b.jam_mulai, b.jam_selesai, 
                       b.nama_penanggungjawab, b.no_penanggungjawab, b.id_user, b.created_at,
                       u.email, u.role
                FROM tbl_booking b
                JOIN tbl_users u ON b.id_user = u.id_user
                WHERE b.status = 'pending'
            ");
            
            $stmt->execute();
            $pendingBookings = $stmt->fetchAll();
            
            foreach ($pendingBookings as $booking) {
                $shouldAutoApprove = false;
                $approvalReason = '';
                
                $bookingDateTime = new DateTime($booking['tanggal'] . ' ' . $booking['jam_mulai']);
                $createdAt = new DateTime($booking['created_at']);
                
                // Cek selisih waktu dari booking dibuat sampai sekarang
                $minutesSinceCreated = ($currentDateTime->getTimestamp() - $createdAt->getTimestamp()) / 60;
                
                // Cek selisih waktu dari sekarang sampai booking dimulai
                $minutesUntilBooking = ($bookingDateTime->getTimestamp() - $currentDateTime->getTimestamp()) / 60;
                
                // RULE 1: Auto-approve untuk DOSEN yang pending 5 menit sebelum booking dimulai
                if ($booking['role'] === 'dosen' && $minutesUntilBooking <= 5 && $minutesUntilBooking > 0) {
                    $shouldAutoApprove = true;
                    $approvalReason = 'Auto-approved: Dosen booking 5 menit sebelum waktu mulai';
                }
                // RULE 2: Auto-approve untuk semua yang pending lebih dari 5 menit (admin tidak respons)
                elseif ($minutesSinceCreated >= 5) {
                    $shouldAutoApprove = true;
                    $approvalReason = 'Auto-approved: Admin tidak merespons dalam 5 menit';
                }
                
                if ($shouldAutoApprove) {
                    // Update to approved status
                    $updateStmt = $this->conn->prepare("
                        UPDATE tbl_booking 
                        SET status = 'approve', 
                            approved_at = NOW(),
                            approved_by = 'SYSTEM_AUTO',
                            approval_reason = ?,
                            user_can_activate = 1
                        WHERE id_booking = ?
                    ");
                    
                    if ($updateStmt->execute([$approvalReason, $booking['id_booking']])) {
                        $approvedCount++;
                        
                        // Send notification to user
                        $this->sendAutoApprovalNotification($booking, $approvalReason);
                        
                        error_log("AUTO-APPROVAL: Booking ID {$booking['id_booking']} auto-approved - {$approvalReason}");
                    }
                }
            }
            
            return $approvedCount;
        } catch (PDOException $e) {
            error_log("Error in autoApproveExpiredPending: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Auto-activate bookings yang sudah tiba waktunya (status approve -> active)
     */
    public function autoActivateCurrentBookings() {
        try {
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            
            // Get bookings that should be activated now
            $stmt = $this->conn->prepare("
                SELECT id_booking, nama_acara, tanggal, jam_mulai, jam_selesai, 
                       nama_penanggungjawab, no_penanggungjawab, id_user
                FROM tbl_booking 
                WHERE status = 'approve' 
                AND tanggal = ? 
                AND jam_mulai <= ? 
                AND jam_selesai > ?
            ");
            
            $stmt->execute([$currentDate, $currentTime, $currentTime]);
            $bookingsToActivate = $stmt->fetchAll();
            
            $activatedCount = 0;
            
            foreach ($bookingsToActivate as $booking) {
                // Update to active status
                $updateStmt = $this->conn->prepare("
                    UPDATE tbl_booking 
                    SET status = 'active',
                        activated_at = NOW(),
                        activated_by = 'SYSTEM_AUTO',
                        activation_note = 'Auto-activated: Waktu booking telah tiba'
                    WHERE id_booking = ?
                ");
                
                if ($updateStmt->execute([$booking['id_booking']])) {
                    $activatedCount++;
                    
                    // Send notification to user
                    $this->sendAutoActivationNotification($booking);
                    
                    error_log("AUTO-ACTIVATION: Booking ID {$booking['id_booking']} auto-activated (ONGOING/RED)");
                }
            }
            
            return $activatedCount;
        } catch (PDOException $e) {
            error_log("Error in autoActivateCurrentBookings: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Auto-complete bookings yang sudah melewati waktu selesai (active -> done)
     */
    public function autoCompleteExpiredBookings() {
        try {
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i:s');
            
            // Get expired active bookings
            $stmt = $this->conn->prepare("
                SELECT id_booking, nama_acara, tanggal, jam_mulai, jam_selesai, 
                       nama_penanggungjawab, no_penanggungjawab, id_user
                FROM tbl_booking 
                WHERE status = 'active' 
                AND (
                    (tanggal < ?) OR 
                    (tanggal = ? AND jam_selesai < ?)
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
                        completion_note = 'Ruangan selesai dipakai tanpa checkout dari mahasiswa'
                    WHERE id_booking = ?
                ");
                
                if ($updateStmt->execute([$booking['id_booking']])) {
                    $completedCount++;
                    
                    // Send notification to user
                    $this->sendAutoCompleteNotification($booking);
                    
                    error_log("AUTO-COMPLETE: Booking ID {$booking['id_booking']} auto-completed without checkout");
                }
            }
            
            return $completedCount;
        } catch (PDOException $e) {
            error_log("Error in autoCompleteExpiredBookings: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Send notification for auto-approved booking
     */
    private function sendAutoApprovalNotification($booking, $reason) {
        try {
            $booking['email'] = $booking['email'];
            $booking['auto_approved'] = true;
            $booking['approval_reason'] = $reason;
            $booking['role'] = $booking['role'];
            sendBookingNotification($booking['email'], $booking, 'auto_approval');
        } catch (Exception $e) {
            error_log("Error sending auto-approval notification: " . $e->getMessage());
        }
    }
    
    /**
     * Send notification for auto-activated booking
     */
    private function sendAutoActivationNotification($booking) {
        try {
            // Get user email
            $stmt = $this->conn->prepare("SELECT email FROM tbl_users WHERE id_user = ?");
            $stmt->execute([$booking['id_user']]);
            $user = $stmt->fetch();
            
            if ($user) {
                $booking['email'] = $user['email'];
                $booking['activation_method'] = 'auto_activate';
                sendBookingNotification($booking['email'], $booking, 'activation');
            }
        } catch (Exception $e) {
            error_log("Error sending auto-activation notification: " . $e->getMessage());
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
                $booking['completion_note'] = 'Ruangan selesai dipakai tanpa checkout dari mahasiswa';
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
            
            // Count by status today
            $stmt = $this->conn->prepare("
                SELECT status, COUNT(*) as count 
                FROM tbl_booking 
                WHERE tanggal = CURDATE() 
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
            $stats['pending'] = $stats['pending'] ?? 0;
            $stats['approve'] = $stats['approve'] ?? 0;
            $stats['active'] = $stats['active'] ?? 0;
            $stats['done'] = $stats['done'] ?? 0;
            
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
    $autoManager = new AutoBookingManager($conn);
    
    // Check if this is an AJAX call for stats
    if (isset($_GET['action']) && $_GET['action'] === 'stats') {
        header('Content-Type: application/json');
        echo json_encode($autoManager->getBookingStats());
        exit;
    }
    
    // Run auto processes
    if (isset($_GET['action']) && $_GET['action'] === 'run') {
        header('Content-Type: application/json');
        $results = $autoManager->runAllAutoProcesses();
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
    
    // Default: run auto processes
    $autoManager->runAllAutoProcesses();
}
?>