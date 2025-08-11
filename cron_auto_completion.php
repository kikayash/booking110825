<?php
/**
 * Auto-Completion Cron Job
 * Script untuk otomatis menyelesaikan booking yang sudah expired
 * 
 * Jalankan setiap 5-15 menit via cron job:
 * * * * * * php /path/to/booking/cron/auto_completion.php*/

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "=== AUTO-COMPLETION CRON JOB ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Checking for expired bookings...\n\n";

try {
    // Jalankan auto-completion
    $result = forceAutoCheckoutExpiredBookings($conn);
    
    echo "RESULTS:\n";
    echo "- Completed bookings: {$result['completed_count']}\n";
    
    if ($result['completed_count'] > 0) {
        echo "\nDETAILS:\n";
        foreach ($result['updates'] as $update) {
            echo "  - #{$update['id']}: {$update['nama_acara']}\n";
            echo "    Status: {$update['old_status']} → {$update['new_status']}\n";
            echo "    Type: {$update['expiry_type']}\n\n";
        }
        
        // Send summary notification to admin (optional)
        sendAdminCompletionSummary($result);
    } else {
        echo "No expired bookings found.\n";
    }
    
    // Juga jalankan recurring schedule generation
    echo "\n=== RECURRING SCHEDULE GENERATION ===\n";
    $generated = autoGenerateUpcomingSchedules($conn);
    echo "Generated recurring schedules: {$generated}\n";
    
    echo "\n=== CRON JOB COMPLETED SUCCESSFULLY ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("AUTO-COMPLETION CRON ERROR: " . $e->getMessage());
    exit(1);
}

/**
 * Kirim summary completion ke admin
 */
function sendAdminCompletionSummary($result) {
    global $conn;
    
    try {
        // Get admin emails
        $stmt = $conn->prepare("SELECT email FROM tbl_users WHERE role = 'admin'");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $subject = "Auto-Completion Summary - " . date('d/m/Y H:i');
        $message = "LAPORAN AUTO-COMPLETION SISTEM\n\n";
        $message .= "Waktu: " . date('d/m/Y H:i:s') . "\n";
        $message .= "Total booking diselesaikan: {$result['completed_count']}\n\n";
        
        $message .= "DETAIL BOOKING:\n";
        foreach ($result['updates'] as $update) {
            $message .= "- #{$update['id']}: {$update['nama_acara']}\n";
            $message .= "  Status: {$update['old_status']} → {$update['new_status']}\n";
            $message .= "  Alasan: " . getExpiryReason($update['expiry_type']) . "\n\n";
        }
        
        $message .= "Sistem booking berjalan normal.\n";
        $message .= "Email otomatis dari sistem.";
        
        // Log notification (implementasi email sesuai kebutuhan)
        foreach ($admins as $adminEmail) {
            error_log("ADMIN AUTO-COMPLETION SUMMARY: Sent to $adminEmail");
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Failed to send admin completion summary: " . $e->getMessage());
        return false;
    }
}

/**
 * Get human-readable expiry reason
 */
function getExpiryReason($expiryType) {
    switch ($expiryType) {
        case 'expired_date':
            return 'Tanggal booking sudah lewat';
        case 'expired_time':
            return 'Waktu booking sudah berakhir hari ini';
        default:
            return 'Booking expired';
    }
}

// Jika dipanggil via web browser (untuk testing)
if (isset($_GET['test']) && $_GET['test'] === 'run') {
    header('Content-Type: text/plain');
    echo ob_get_clean();
}
?>