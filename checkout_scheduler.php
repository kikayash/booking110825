<?php
// File: checkout_scheduler.php
// Scheduler untuk menjalankan auto-checkout setiap menit

// Jalankan dengan cron job:
// * * * * * /usr/bin/php /path/to/checkout_scheduler.php

set_time_limit(60); // Max 1 menit
ini_set('memory_limit', '64M');

require_once 'config.php';
require_once 'functions.php';
require_once 'auto_checkout.php';

// Log start
error_log("CHECKOUT SCHEDULER START: " . date('Y-m-d H:i:s'));

try {
    $startTime = microtime(true);
    
    // Jalankan auto-checkout
    $autoCheckedOut = autoCheckoutExpiredBookings($conn);
    
    // Hitung waktu eksekusi
    $executionTime = round((microtime(true) - $startTime) * 1000, 2); // dalam ms
    
    // Log hasil
    if ($autoCheckedOut > 0) {
        error_log("CHECKOUT SCHEDULER SUCCESS: {$autoCheckedOut} booking(s) auto-checked out in {$executionTime}ms");
    } else {
        // Log setiap 5 menit jika tidak ada yang di-checkout (untuk mengurangi spam log)
        $minute = date('i');
        if ($minute % 5 == 0) {
            error_log("CHECKOUT SCHEDULER: No expired bookings found (checked in {$executionTime}ms)");
        }
    }
    
    // Simpan statistik ke database (opsional)
    saveSchedulerStats($conn, $autoCheckedOut, $executionTime);
    
} catch (Exception $e) {
    error_log("CHECKOUT SCHEDULER ERROR: " . $e->getMessage());
    exit(1);
}

function saveSchedulerStats($conn, $autoCheckedOut, $executionTime) {
    try {
        // Cek apakah tabel ada, jika tidak buat
        $checkTable = $conn->query("SHOW TABLES LIKE 'scheduler_stats'");
        if ($checkTable->rowCount() == 0) {
            $createTable = "CREATE TABLE scheduler_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                auto_checkout_count INT DEFAULT 0,
                execution_time_ms DECIMAL(10,2) DEFAULT 0,
                status VARCHAR(20) DEFAULT 'success'
            )";
            $conn->exec($createTable);
        }
        
        // Insert statistik
        $stmt = $conn->prepare("INSERT INTO scheduler_stats (auto_checkout_count, execution_time_ms) VALUES (?, ?)");
        $stmt->execute([$autoCheckedOut, $executionTime]);
        
        // Hapus data lama (lebih dari 7 hari)
        $conn->exec("DELETE FROM scheduler_stats WHERE run_time < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        
    } catch (Exception $e) {
        // Jika gagal simpan statistik, tidak masalah, yang penting auto-checkout berjalan
        error_log("SCHEDULER STATS ERROR: " . $e->getMessage());
    }
}

error_log("CHECKOUT SCHEDULER END: " . date('Y-m-d H:i:s'));
?>