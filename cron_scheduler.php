<?php
/**
 * File: cron_scheduler.php
 * Automated Process Scheduler for Room Booking System
 * 
 * Jalankan dengan cron job setiap menit:
 * * * * * * /usr/bin/php /path/to/your/website/cron_scheduler.php
 * 
 * Atau bisa juga dijalankan manual untuk testing:
 * php cron_scheduler.php
 */

// Set time limit and memory limit
set_time_limit(120); // 2 minutes max
ini_set('memory_limit', '128M');

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cron_auto_manager.php';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Log start of cron job
error_log("CRON SCHEDULER STARTED: " . date('Y-m-d H:i:s'));

try {
    // Initialize the auto booking manager
    $autoManager = new AutoBookingManager($conn);
    
    // Get current time for logging
    $startTime = microtime(true);
    
    // Run all auto processes
    $results = $autoManager->runAllAutoProcesses();
    
    // Calculate execution time
    $executionTime = round((microtime(true) - $startTime) * 1000, 2); // in milliseconds
    
    // Log results
    $totalChanges = array_sum($results);
    
    if ($totalChanges > 0) {
        error_log("CRON SCHEDULER COMPLETED: {$totalChanges} changes made in {$executionTime}ms");
        error_log("CRON RESULTS: " . json_encode($results));
        
        // Detailed logging for each process
        if ($results['auto_approved'] > 0) {
            error_log("AUTO-APPROVAL: {$results['auto_approved']} bookings auto-approved");
        }
        if ($results['auto_activated'] > 0) {
            error_log("AUTO-ACTIVATION: {$results['auto_activated']} bookings auto-activated (PENDING/APPROVE → ONGOING)");
        }
        if ($results['auto_completed'] > 0) {
            error_log("AUTO-COMPLETION: {$results['auto_completed']} bookings auto-completed (ONGOING → SELESAI)");
        }
    } else {
        // Only log every 5 minutes if no changes to reduce log spam
        $minute = date('i');
        if ($minute % 5 == 0) {
            error_log("CRON SCHEDULER: No changes made (checked in {$executionTime}ms)");
        }
    }
    
    // Save execution stats to database (optional)
    try {
        $stmt = $conn->prepare("
            INSERT INTO cron_execution_log 
            (execution_time, auto_approved, auto_activated, auto_completed, execution_duration_ms) 
            VALUES (NOW(), ?, ?, ?, ?)
        ");
        $stmt->execute([
            $results['auto_approved'],
            $results['auto_activated'], 
            $results['auto_completed'],
            $executionTime
        ]);
    } catch (PDOException $e) {
        // Table might not exist, create it
        createCronLogTable($conn);
    }
    
    // Optional: Clean up old log entries (keep only last 7 days)
    cleanupOldLogs($conn);
    
    // Optional: Send notifications for important changes
    if ($totalChanges > 5) {
        sendAdminNotification($results, $totalChanges);
    }
    
    // Success exit
    exit(0);
    
} catch (Exception $e) {
    // Log error
    error_log("CRON SCHEDULER ERROR: " . $e->getMessage());
    error_log("CRON SCHEDULER STACK TRACE: " . $e->getTraceAsString());
    
    // Exit with error code
    exit(1);
}

/**
 * Create cron execution log table if it doesn't exist
 */
function createCronLogTable($conn) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS cron_execution_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            execution_time DATETIME NOT NULL,
            auto_approved INT DEFAULT 0,
            auto_activated INT DEFAULT 0,
            auto_completed INT DEFAULT 0,
            execution_duration_ms DECIMAL(10,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->exec($sql);
        error_log("CRON SCHEDULER: Created cron_execution_log table");
    } catch (PDOException $e) {
        error_log("CRON SCHEDULER: Failed to create log table - " . $e->getMessage());
    }
}

/**
 * Clean up old cron log entries
 */
function cleanupOldLogs($conn) {
    try {
        $stmt = $conn->prepare("DELETE FROM cron_execution_log WHERE execution_time < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $deletedRows = $stmt->rowCount();
        
        if ($deletedRows > 0) {
            error_log("CRON SCHEDULER: Cleaned up {$deletedRows} old log entries");
        }
    } catch (PDOException $e) {
        // Ignore errors, table might not exist
    }
}

/**
 * Send notification to admin for significant changes
 */
function sendAdminNotification($results, $totalChanges) {
    try {
        $message = "Sistema Booking Ruangan - Laporan Otomatis\n\n";
        $message .= "Waktu: " . date('Y-m-d H:i:s') . "\n";
        $message .= "Total Perubahan: {$totalChanges}\n\n";
        $message .= "Detail:\n";
        $message .= "- Auto-approved: {$results['auto_approved']} booking\n";
        $message .= "- Auto-activated (ONGOING): {$results['auto_activated']} booking\n";
        $message .= "- Auto-completed (SELESAI): {$results['auto_completed']} booking\n\n";
        $message .= "Sistem berjalan normal.\n";
        
        // Log notification (implement actual email sending if needed)
        error_log("ADMIN NOTIFICATION: " . str_replace("\n", " | ", $message));
        
        // TODO: Implement actual email/SMS notification to admin
        // sendEmail('admin@stie-mce.ac.id', 'Laporan Sistem Booking', $message);
        
    } catch (Exception $e) {
        error_log("CRON SCHEDULER: Failed to send admin notification - " . $e->getMessage());
    }
}

/**
 * Monitor system health and database connections
 */
function checkSystemHealth($conn) {
    $healthStatus = [
        'database' => false,
        'disk_space' => false,
        'memory' => false
    ];
    
    try {
        // Check database connection
        $stmt = $conn->query("SELECT 1");
        $healthStatus['database'] = $stmt ? true : false;
        
        // Check disk space (at least 100MB free)
        $diskFree = disk_free_space(__DIR__);
        $healthStatus['disk_space'] = ($diskFree > 100 * 1024 * 1024);
        
        // Check memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = return_bytes($memoryLimit);
        $healthStatus['memory'] = ($memoryUsage < $memoryLimitBytes * 0.8);
        
        $allHealthy = array_reduce($healthStatus, function($carry, $item) {
            return $carry && $item;
        }, true);
        
        if (!$allHealthy) {
            error_log("CRON SCHEDULER: HEALTH CHECK FAILED - " . json_encode($healthStatus));
        }
        
        return $allHealthy;
        
    } catch (Exception $e) {
        error_log("CRON SCHEDULER: Health check error - " . $e->getMessage());
        return false;
    }
}

/**
 * Convert PHP memory limit to bytes
 */
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int) $val;
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}

/**
 * Get booking statistics for monitoring
 */
function getBookingStatistics($conn) {
    try {
        $stats = [];
        
        // Today's bookings by status
        $stmt = $conn->prepare("
            SELECT status, COUNT(*) as count 
            FROM tbl_booking 
            WHERE tanggal = CURDATE() 
            GROUP BY status
        ");
        $stmt->execute();
        $todayStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Active bookings right now
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_booking 
            WHERE status = 'active' 
            AND tanggal = CURDATE() 
            AND jam_mulai <= CURTIME() 
            AND jam_selesai > CURTIME()
        ");
        $stmt->execute();
        $activeNow = $stmt->fetchColumn();
        
        $stats = [
            'today' => $todayStats,
            'active_now' => $activeNow,
            'total_today' => array_sum($todayStats),
            'pending_today' => $todayStats['pending'] ?? 0,
            'approve_today' => $todayStats['approve'] ?? 0,
            'active_today' => $todayStats['active'] ?? 0,
            'done_today' => $todayStats['done'] ?? 0
        ];
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("CRON SCHEDULER: Failed to get statistics - " . $e->getMessage());
        return [];
    }
}

// Additional monitoring and alerting
$healthOk = checkSystemHealth($conn);
if (!$healthOk) {
    error_log("CRON SCHEDULER: SYSTEM HEALTH ISSUES DETECTED");
}

// Get and log statistics periodically (every 10 minutes)
$minute = date('i');
if ($minute % 10 == 0) {
    $stats = getBookingStatistics($conn);
    if (!empty($stats)) {
        error_log("BOOKING STATS: " . json_encode($stats));
    }
}
?>