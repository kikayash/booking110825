<?php
// comprehensive_fix.php - Fix semua masalah ID di database
require_once 'config.php';

echo "<h2>🔧 COMPREHENSIVE ID FIX SCRIPT</h2>\n";
echo "<p>Fixing both tbl_users and tbl_booking ID problems...</p>\n";
echo "<hr>\n";

try {
    // ===== PART 1: FIX TBL_USERS =====
    echo "<h3>🔧 PART 1: FIXING TBL_USERS</h3>\n";
    
    // 1.1 Check users with id_user = 0
    $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE id_user = 0");
    $stmt->execute();
    $problemUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h4>1.1 Checking problematic users (id_user = 0)...</h4>\n";
    
    if (count($problemUsers) > 0) {
        echo "<p style='color: red;'>❌ Found " . count($problemUsers) . " users with id_user = 0</p>\n";
        
        // Fix users starting from safe ID
        $newUserIdStart = 3000;
        $userIdMapping = []; // Old ID => New ID mapping
        
        foreach ($problemUsers as $index => $user) {
            $newUserId = $newUserIdStart + $index;
            
            // Update user ID
            $updateStmt = $conn->prepare("UPDATE tbl_users SET id_user = ? WHERE email = ? AND nik = ?");
            $result = $updateStmt->execute([$newUserId, $user['email'], $user['nik']]);
            
            if ($result) {
                $userIdMapping[0] = $newUserId; // Map old 0 to new ID
                echo "<p style='color: green;'>✅ Fixed user: {$user['nama']} → ID: {$newUserId}</p>\n";
            }
        }
        
        // Set AUTO_INCREMENT for users
        $maxUserIdStmt = $conn->prepare("SELECT MAX(id_user) FROM tbl_users");
        $maxUserIdStmt->execute();
        $maxUserId = $maxUserIdStmt->fetchColumn();
        $newUserAutoIncrement = $maxUserId + 100;
        
        $conn->exec("ALTER TABLE tbl_users AUTO_INCREMENT = {$newUserAutoIncrement}");
        echo "<p style='color: green;'>✅ Set users AUTO_INCREMENT to {$newUserAutoIncrement}</p>\n";
        
    } else {
        echo "<p style='color: green;'>✅ No problematic users found</p>\n";
    }
    
    // ===== PART 2: FIX TBL_BOOKING =====
    echo "<h3>🔧 PART 2: FIXING TBL_BOOKING</h3>\n";
    
    // 2.1 Check bookings with id_booking = 0
    $stmt = $conn->prepare("SELECT * FROM tbl_booking WHERE id_booking = 0");
    $stmt->execute();
    $problemBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h4>2.1 Checking problematic bookings (id_booking = 0)...</h4>\n";
    
    if (count($problemBookings) > 0) {
        echo "<p style='color: red;'>❌ Found " . count($problemBookings) . " bookings with id_booking = 0</p>\n";
        
        // Show problematic bookings
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>\n";
        echo "<tr><th>Old ID</th><th>User ID</th><th>Nama Acara</th><th>Tanggal</th><th>NIK Dosen</th></tr>\n";
        
        foreach ($problemBookings as $booking) {
            echo "<tr>";
            echo "<td>{$booking['id_booking']}</td>";
            echo "<td>{$booking['id_user']}</td>";
            echo "<td>{$booking['nama_acara']}</td>";
            echo "<td>{$booking['tanggal']}</td>";
            echo "<td>{$booking['nik_dosen']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
        
        // FIXED: Update booking IDs directly instead of recreating records
        $newBookingIdStart = 5000;
        $bookingIdMapping = []; // Track ID changes for any related tables
        
        foreach ($problemBookings as $index => $booking) {
            $newBookingId = $newBookingIdStart + $index;
            
            try {
                // Update booking ID directly using unique combination to identify record
                $updateStmt = $conn->prepare("
                    UPDATE tbl_booking 
                    SET id_booking = ? 
                    WHERE id_booking = 0 
                    AND nama_acara = ? 
                    AND tanggal = ? 
                    AND jam_mulai = ? 
                    AND jam_selesai = ?
                    LIMIT 1
                ");
                
                $updateResult = $updateStmt->execute([
                    $newBookingId,
                    $booking['nama_acara'],
                    $booking['tanggal'],
                    $booking['jam_mulai'],
                    $booking['jam_selesai']
                ]);
                
                if ($updateResult && $updateStmt->rowCount() > 0) {
                    $bookingIdMapping[0] = $newBookingId; // Map old 0 to new ID
                    echo "<p style='color: green;'>✅ Fixed booking: {$booking['nama_acara']} → ID: {$newBookingId}</p>\n";
                } else {
                    echo "<p style='color: orange;'>⚠️ Could not update booking: {$booking['nama_acara']} (may already be fixed)</p>\n";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Error fixing booking {$booking['nama_acara']}: {$e->getMessage()}</p>\n";
            }
        }
        
        // Set AUTO_INCREMENT for bookings
        $maxBookingIdStmt = $conn->prepare("SELECT MAX(id_booking) FROM tbl_booking");
        $maxBookingIdStmt->execute();
        $maxBookingId = $maxBookingIdStmt->fetchColumn();
        $newBookingAutoIncrement = $maxBookingId + 100;
        
        $conn->exec("ALTER TABLE tbl_booking AUTO_INCREMENT = {$newBookingAutoIncrement}");
        echo "<p style='color: green;'>✅ Set bookings AUTO_INCREMENT to {$newBookingAutoIncrement}</p>\n";
        
        // Update any related tables that might reference booking IDs
        echo "<h4>2.2 Checking related table references...</h4>\n";
        
        // Check if there are any tables that reference booking IDs
        $relatedTablesStmt = $conn->prepare("
            SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME = 'tbl_booking'
            AND REFERENCED_COLUMN_NAME = 'id_booking'
        ");
        $relatedTablesStmt->execute();
        $relatedTables = $relatedTablesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($relatedTables) > 0) {
            echo "<p style='color: blue;'>📋 Found " . count($relatedTables) . " related table(s) with foreign key references:</p>\n";
            foreach ($relatedTables as $table) {
                echo "<p>- {$table['TABLE_NAME']}.{$table['COLUMN_NAME']}</p>\n";
            }
            echo "<p style='color: orange;'>⚠️ Manual review may be needed for these references</p>\n";
        } else {
            echo "<p style='color: green;'>✅ No foreign key references found - safe to proceed</p>\n";
        }
        
    } else {
        echo "<p style='color: green;'>✅ No problematic bookings found</p>\n";
    }
    
    // ===== PART 3: FIX USER-BOOKING RELATIONSHIPS =====
    echo "<h3>🔧 PART 3: FIXING USER-BOOKING RELATIONSHIPS</h3>\n";
    
    // 3.1 Fix bookings that still have id_user = 0
    $orphanedBookingsStmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM tbl_booking 
        WHERE id_user = 0
    ");
    $orphanedBookingsStmt->execute();
    $orphanedCount = $orphanedBookingsStmt->fetchColumn();
    
    if ($orphanedCount > 0) {
        echo "<p style='color: orange;'>⚠️ Found {$orphanedCount} bookings with id_user = 0</p>\n";
        
        // Try to match by NIK
        $matchByNikStmt = $conn->prepare("
            UPDATE tbl_booking b
            INNER JOIN tbl_users u ON b.nik_dosen = u.nik
            SET b.id_user = u.id_user
            WHERE b.id_user = 0 AND u.id_user > 0
        ");
        $matchByNikResult = $matchByNikStmt->execute();
        $matchedByNik = $matchByNikStmt->rowCount();
        
        if ($matchedByNik > 0) {
            echo "<p style='color: green;'>✅ Fixed {$matchedByNik} bookings by matching NIK</p>\n";
        }
        
        // Try to match by email
        $matchByEmailStmt = $conn->prepare("
            UPDATE tbl_booking b
            INNER JOIN tbl_users u ON b.email_dosen = u.email
            SET b.id_user = u.id_user
            WHERE b.id_user = 0 AND u.id_user > 0
        ");
        $matchByEmailResult = $matchByEmailStmt->execute();
        $matchedByEmail = $matchByEmailStmt->rowCount();
        
        if ($matchedByEmail > 0) {
            echo "<p style='color: green;'>✅ Fixed {$matchedByEmail} bookings by matching email</p>\n";
        }
        
    } else {
        echo "<p style='color: green;'>✅ No orphaned bookings found</p>\n";
    }
    
    // ===== PART 4: VALIDATION & TESTING =====
    echo "<h3>📊 PART 4: FINAL VALIDATION</h3>\n";
    
    // 4.1 Check final state
    $finalCheckStmt = $conn->prepare("
        SELECT 
            'users' as table_name,
            COUNT(*) as total_records,
            COUNT(CASE WHEN id_user = 0 THEN 1 END) as zero_id_count,
            MIN(CASE WHEN id_user > 0 THEN id_user END) as min_valid_id,
            MAX(id_user) as max_id
        FROM tbl_users
        
        UNION ALL
        
        SELECT 
            'bookings' as table_name,
            COUNT(*) as total_records,
            COUNT(CASE WHEN id_booking = 0 THEN 1 END) as zero_id_count,
            MIN(CASE WHEN id_booking > 0 THEN id_booking END) as min_valid_id,
            MAX(id_booking) as max_id
        FROM tbl_booking
    ");
    $finalCheckStmt->execute();
    $finalResults = $finalCheckStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr><th>Table</th><th>Total Records</th><th>Zero ID Count</th><th>Min Valid ID</th><th>Max ID</th><th>Status</th></tr>\n";
    
    $allGood = true;
    foreach ($finalResults as $result) {
        $status = $result['zero_id_count'] == 0 ? "✅" : "❌";
        if ($result['zero_id_count'] > 0) $allGood = false;
        
        echo "<tr>";
        echo "<td>{$result['table_name']}</td>";
        echo "<td>{$result['total_records']}</td>";
        echo "<td>{$result['zero_id_count']}</td>";
        echo "<td>{$result['min_valid_id']}</td>";
        echo "<td>{$result['max_id']}</td>";
        echo "<td>{$status}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    // 4.2 Check AUTO_INCREMENT values
    echo "<h4>AUTO_INCREMENT Status:</h4>\n";
    
    $autoIncrementStmt = $conn->prepare("
        SELECT TABLE_NAME, AUTO_INCREMENT 
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME IN ('tbl_users', 'tbl_booking')
    ");
    $autoIncrementStmt->execute();
    $autoIncrements = $autoIncrementStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr><th>Table</th><th>AUTO_INCREMENT</th></tr>\n";
    
    foreach ($autoIncrements as $ai) {
        echo "<tr><td>{$ai['TABLE_NAME']}</td><td>{$ai['AUTO_INCREMENT']}</td></tr>\n";
    }
    echo "</table>\n";
    
    // ===== FINAL RESULT =====
    echo "<hr>\n";
    
    if ($allGood) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 5px; margin: 20px 0;'>\n";
        echo "<h3>🎉 SUCCESS! ALL IDS FIXED!</h3>\n";
        echo "<p><strong>✅ Users table:</strong> No more id_user = 0</p>\n";
        echo "<p><strong>✅ Bookings table:</strong> No more id_booking = 0</p>\n";
        echo "<p><strong>✅ AUTO_INCREMENT:</strong> Set properly for both tables</p>\n";
        echo "<p><strong>🎯 Next:</strong> Test login dan booking untuk memastikan ID generate dengan benar</p>\n";
        echo "</div>\n";
    } else {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px; margin: 20px 0;'>\n";
        echo "<h3>⚠️ SOME ISSUES REMAIN</h3>\n";
        echo "<p>Masih ada beberapa record dengan ID = 0. Check manual atau run script lagi.</p>\n";
        echo "</div>\n";
    }
    
    echo "<h3>📝 What to do next:</h3>\n";
    echo "<ol>\n";
    echo "<li>✅ Replace <code>process-login-nik.php</code> dengan versi yang sudah diperbaiki</li>\n";
    echo "<li>✅ Replace <code>process-booking-iris.php</code> dengan versi yang sudah diperbaiki</li>\n";
    echo "<li>🧪 Test login dengan NIK dosen</li>\n";
    echo "<li>🧪 Test buat booking baru</li>\n";
    echo "<li>🔍 Verify di database: cek id_user dan id_booking > 0</li>\n";
    echo "<li>📊 Test dashboard keuangan untuk memastikan data booking dapat ditrack</li>\n";
    echo "</ol>\n";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px;'>\n";
    echo "<h3>💥 FATAL ERROR</h3>\n";
    echo "<p><strong>Error:</strong> {$e->getMessage()}</p>\n";
    echo "<p><strong>File:</strong> {$e->getFile()}:{$e->getLine()}</p>\n";
    echo "<details><summary>Stack Trace</summary><pre>{$e->getTraceAsString()}</pre></details>\n";
    echo "</div>\n";
}
?>