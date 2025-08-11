<?php
/**
 * Auto Status Update Trigger
 * File ini di-include di halaman utama untuk memastikan status booking selalu update
 */

// Cegah akses langsung
if (!defined('BOOKING_SYSTEM_LOADED')) {
    exit('Direct access not allowed');
}

// Set flag bahwa auto update sudah dipanggil
if (!defined('AUTO_UPDATE_TRIGGERED')) {
    define('AUTO_UPDATE_TRIGGERED', true);
    
    // Hanya jalankan untuk user yang login
    if (isLoggedIn()) {
        
        // Check interval - hanya jalankan setiap 10 menit sekali per session
        $lastAutoUpdate = $_SESSION['last_auto_update'] ?? 0;
        $currentTime = time();
        
        if (($currentTime - $lastAutoUpdate) >= 600) { // 10 menit = 600 detik
            
            try {
                // 1. Auto-completion untuk booking expired
                $completionResult = forceAutoCheckoutExpiredBookings($conn);
                
                // 2. Auto-generation untuk recurring schedules
                $generationResult = 0;
                if (function_exists('autoGenerateUpcomingSchedules')) {
                    $generationResult = autoGenerateUpcomingSchedules($conn);
                }
                
                // Update session timestamp
                $_SESSION['last_auto_update'] = $currentTime;
                
                // Log hasil jika ada update
                if ($completionResult['completed_count'] > 0 || $generationResult > 0) {
                    error_log("AUTO-UPDATE TRIGGER: Completed {$completionResult['completed_count']} bookings, Generated {$generationResult} schedules");
                    
                    // Simpan info untuk notifikasi user
                    if ($completionResult['completed_count'] > 0) {
                        $_SESSION['auto_update_info'] = [
                            'completed' => $completionResult['completed_count'],
                            'generated' => $generationResult,
                            'timestamp' => date('H:i:s')
                        ];
                    }
                }
                
            } catch (Exception $e) {
                error_log("AUTO-UPDATE ERROR: " . $e->getMessage());
            }
        }
    }
}

/**
 * Tampilkan notifikasi auto-update jika ada
 */
function showAutoUpdateNotification() {
    if (isset($_SESSION['auto_update_info'])) {
        $info = $_SESSION['auto_update_info'];
        
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert" style="position: fixed; top: 80px; right: 20px; z-index: 1050; max-width: 400px;">';
        echo '<i class="fas fa-sync-alt me-2"></i>';
        echo '<strong>Auto-Update:</strong> ';
        
        if ($info['completed'] > 0) {
            echo $info['completed'] . ' booking expired telah diselesaikan. ';
        }
        
        if ($info['generated'] > 0) {
            echo $info['generated'] . ' jadwal kuliah di-generate. ';
        }
        
        echo '<small class="d-block">Waktu: ' . $info['timestamp'] . '</small>';
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        
        // Hapus notifikasi setelah ditampilkan
        unset($_SESSION['auto_update_info']);
    }
}

/**
 * JavaScript untuk auto-refresh kalender jika ada update
 */
function addAutoRefreshScript() {
    if (isset($_SESSION['auto_update_info']) && $_SESSION['auto_update_info']['completed'] > 0) {
        echo '<script>
            // Auto-refresh kalender setelah 3 detik jika ada booking yang di-update
            setTimeout(function() {
                if (window.location.pathname.includes("index.php") || window.location.pathname === "/booking/") {
                    console.log("Auto-refreshing calendar due to booking updates...");
                    window.location.reload();
                }
            }, 3000);
        </script>';
    }
}

/**
 * Get real-time booking count for dashboard
 */
function getRealTimeBookingStats($conn) {
    try {
        $today = date('Y-m-d');
        
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_today,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approve' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed
            FROM tbl_booking 
            WHERE tanggal = ?
        ");
        $stmt->execute([$today]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting real-time stats: " . $e->getMessage());
        return [
            'total_today' => 0,
            'pending' => 0,
            'approved' => 0,
            'active' => 0,
            'completed' => 0
        ];
    }
}

/**
 * Check if any room is currently being used
 */
function getCurrentlyUsedRooms($conn) {
    try {
        $currentDate = date('Y-m-d');
        $currentTime = date('H:i:s');
        
        $stmt = $conn->prepare("
            SELECT r.nama_ruang, g.nama_gedung, b.nama_acara, b.nama_penanggungjawab, 
                   b.jam_mulai, b.jam_selesai
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            WHERE b.tanggal = ? 
            AND b.status = 'active'
            AND b.jam_mulai <= ? 
            AND b.jam_selesai > ?
            ORDER BY r.nama_ruang
        ");
        $stmt->execute([$currentDate, $currentTime, $currentTime]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting currently used rooms: " . $e->getMessage());
        return [];
    }
}

// Jalankan auto-update jika belum pernah dijalankan di session ini
if (!isset($_SESSION['auto_update_initialized'])) {
    // Mark as initialized
    $_SESSION['auto_update_initialized'] = true;
    
    // Log bahwa auto-update system sudah aktif
    if (isLoggedIn()) {
        error_log("AUTO-UPDATE SYSTEM: Initialized for user " . ($_SESSION['email'] ?? 'unknown'));
    }
}
?>