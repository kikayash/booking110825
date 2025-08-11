<?php
// File: auto_checkout.php (Updated)
// Sistem Auto-Checkout untuk Booking yang Expired

require_once 'config.php';
require_once 'functions.php';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Fungsi untuk auto-checkout booking yang expired
function autoCheckoutExpiredBookings($conn) {
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    $currentDateTime = date('Y-m-d H:i:s');
    
    // Cari booking dengan status 'active' yang sudah melewati waktu selesai
    $sql = "SELECT b.id_booking, b.nama_acara, b.tanggal, b.jam_mulai, b.jam_selesai, 
                   b.nama_penanggungjawab, b.no_penanggungjawab, b.id_user,
                   r.nama_ruang, g.nama_gedung, u.email
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            JOIN tbl_users u ON b.id_user = u.id_user
            WHERE b.status = 'active' 
            AND (
                (b.tanggal < ?) OR 
                (b.tanggal = ? AND b.jam_selesai < ?)
            )";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$currentDate, $currentDate, $currentTime]);
    $expiredBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $autoCheckedOutCount = 0;
    
    foreach ($expiredBookings as $booking) {
        // Update status menjadi 'done' dengan auto-checkout
        $updateSql = "UPDATE tbl_booking 
                      SET status = 'done',
                          checkout_status = 'auto_completed',
                          checkout_time = ?,
                          completion_note = 'Ruangan selesai dipakai tanpa checkout dari mahasiswa',
                          checked_out_by = 'SYSTEM_AUTO'
                      WHERE id_booking = ?";
        
        $updateStmt = $conn->prepare($updateSql);
        $result = $updateStmt->execute([$currentDateTime, $booking['id_booking']]);
        
        if ($result) {
            $autoCheckedOutCount++;
            
            // Log untuk tracking
            error_log("AUTO-CHECKOUT: Booking ID {$booking['id_booking']} ({$booking['nama_acara']}) - Status: ACTIVE → DONE (Auto-Completed)");
            error_log("REASON: Ruangan selesai dipakai tanpa checkout dari mahasiswa");
            
            // Kirim notifikasi ke mahasiswa dan admin
            sendAutoCheckoutNotification($booking);
            sendAdminAutoCheckoutNotification($booking);
        }
    }
    
    if ($autoCheckedOutCount > 0) {
        error_log("AUTO-CHECKOUT SUMMARY: {$autoCheckedOutCount} booking(s) automatically checked out");
    }
    
    return $autoCheckedOutCount;
}

// Fungsi untuk mengirim notifikasi auto-checkout ke mahasiswa
function sendAutoCheckoutNotification($booking) {
    $subject = "Auto-Checkout: " . $booking['nama_acara'];
    $message = "Halo {$booking['nama_penanggungjawab']},\n\n";
    $message .= "Booking ruangan Anda telah di-checkout secara otomatis karena melewati waktu selesai.\n\n";
    $message .= "Detail Booking:\n";
    $message .= "Nama Acara: {$booking['nama_acara']}\n";
    $message .= "Ruangan: {$booking['nama_ruang']}\n";
    $message .= "Tanggal: " . date('d/m/Y', strtotime($booking['tanggal'])) . "\n";
    $message .= "Waktu: {$booking['jam_mulai']} - {$booking['jam_selesai']}\n";
    $message .= "Status: SELESAI (Auto-Checkout)\n\n";
    $message .= "CATATAN: Untuk masa depan, mohon lakukan checkout manual setelah selesai menggunakan ruangan.\n\n";
    $message .= "Terima kasih.";
    
    // Log notifikasi (implementasi email sesuai kebutuhan)
    error_log("AUTO-CHECKOUT NOTIFICATION: Sent to {$booking['email']} for booking #{$booking['id_booking']}");
    
    return true;
}

// Fungsi untuk mengirim notifikasi ke admin
function sendAdminAutoCheckoutNotification($booking) {
    $subject = "Admin Alert: Auto-Checkout - " . $booking['nama_acara'];
    $message = "SISTEM AUTO-CHECKOUT\n\n";
    $message .= "Booking berikut telah di-checkout secara otomatis karena mahasiswa lupa checkout:\n\n";
    $message .= "Detail Booking:\n";
    $message .= "ID Booking: {$booking['id_booking']}\n";
    $message .= "Nama Acara: {$booking['nama_acara']}\n";
    $message .= "Ruangan: {$booking['nama_ruang']}\n";
    $message .= "Tanggal: " . date('d/m/Y', strtotime($booking['tanggal'])) . "\n";
    $message .= "Waktu: {$booking['jam_mulai']} - {$booking['jam_selesai']}\n";
    $message .= "PIC: {$booking['nama_penanggungjawab']} ({$booking['no_penanggungjawab']})\n";
    $message .= "Email: {$booking['email']}\n\n";
    $message .= "Status: MAHASISWA LUPA CHECKOUT\n";
    $message .= "Auto-checkout time: " . date('d/m/Y H:i:s') . "\n\n";
    $message .= "Silakan tindak lanjuti jika diperlukan.";
    
    // Log notifikasi admin
    error_log("ADMIN AUTO-CHECKOUT ALERT: Booking ID {$booking['id_booking']} - Student forgot to checkout");
    
    return true;
}

// Fungsi untuk mendapatkan statistik checkout
function getCheckoutStatistics($conn, $date = null) {
    $date = $date ?: date('Y-m-d');
    
    try {
        // Manual checkout count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as manual_count 
            FROM tbl_booking 
            WHERE DATE(checkout_time) = ? 
            AND checkout_status = 'manual_checkout'
        ");
        $stmt->execute([$date]);
        $manualCount = $stmt->fetchColumn();
        
        // Auto checkout count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as auto_count 
            FROM tbl_booking 
            WHERE DATE(checkout_time) = ? 
            AND checkout_status = 'auto_completed'
        ");
        $stmt->execute([$date]);
        $autoCount = $stmt->fetchColumn();
        
        return [
            'date' => $date,
            'manual_checkout' => $manualCount,
            'auto_checkout' => $autoCount,
            'total_checkout' => $manualCount + $autoCount,
            'forgot_checkout_rate' => $manualCount + $autoCount > 0 ? 
                round(($autoCount / ($manualCount + $autoCount)) * 100, 2) : 0
        ];
        
    } catch (Exception $e) {
        error_log("Error getting checkout statistics: " . $e->getMessage());
        return null;
    }
}

// Jalankan auto-checkout jika file dipanggil langsung
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $count = autoCheckoutExpiredBookings($conn);
        
        if ($count > 0) {
            echo "AUTO-CHECKOUT: {$count} booking(s) processed successfully\n";
            
            // Show statistics
            $stats = getCheckoutStatistics($conn);
            if ($stats) {
                echo "TODAY'S STATS:\n";
                echo "- Manual Checkout: {$stats['manual_checkout']}\n";
                echo "- Auto Checkout (Forgot): {$stats['auto_checkout']}\n";
                echo "- Forgot Rate: {$stats['forgot_checkout_rate']}%\n";
            }
        } else {
            echo "AUTO-CHECKOUT: No expired bookings found\n";
        }
        
    } catch (Exception $e) {
        error_log("AUTO-CHECKOUT ERROR: " . $e->getMessage());
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
?>