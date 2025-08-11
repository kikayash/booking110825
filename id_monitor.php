<?php
// id_monitor.php - Real-time monitoring untuk ID sistem
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ID System Monitor</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .status-good { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .status-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .status-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f8f9fa; font-weight: bold; }
        .metric-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin: 10px 0; display: inline-block; min-width: 200px; }
        .metric-value { font-size: 2em; font-weight: bold; color: #007bff; }
        .metric-label { color: #6c757d; font-size: 0.9em; }
        .refresh-btn { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 10px 5px; }
        .test-btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 10px 5px; }
        .danger-btn { background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 10px 5px; }
        .log-area { background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 5px; height: 300px; overflow-y: scroll; font-family: monospace; font-size: 0.9em; }
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { background-color: rgba(0,123,255,0.1); } 50% { background-color: rgba(0,123,255,0.3); } 100% { background-color: rgba(0,123,255,0.1); } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 ID System Monitor</h1>
            <p>Real-time monitoring untuk User ID dan Booking ID sistem</p>
            <p><strong>Last Updated:</strong> <?= date('Y-m-d H:i:s') ?></p>
        </div>

        <div style="text-align: center; margin: 20px 0;">
            <button class="refresh-btn" onclick="location.reload()">🔄 Refresh Data</button>
            <button class="test-btn" onclick="testIdGeneration()">🧪 Test ID Generation</button>
            <button class="danger-btn" onclick="runEmergencyFix()">🚨 Emergency Fix</button>
        </div>

        <?php
        try {
            // ===== SYSTEM HEALTH CHECK =====
            echo "<h2>📊 System Health Overview</h2>\n";
            
            $healthCheck = $conn->prepare("
                SELECT 
                    'Users' as entity,
                    COUNT(*) as total,
                    COUNT(CASE WHEN id_user = 0 THEN 1 END) as zero_count,
                    COUNT(CASE WHEN id_user > 0 THEN 1 END) as valid_count,
                    MIN(CASE WHEN id_user > 0 THEN id_user END) as min_valid,
                    MAX(id_user) as max_id
                FROM tbl_users
                
                UNION ALL
                
                SELECT 
                    'Bookings' as entity,
                    COUNT(*) as total,
                    COUNT(CASE WHEN id_booking = 0 THEN 1 END) as zero_count,
                    COUNT(CASE WHEN id_booking > 0 THEN 1 END) as valid_count,
                    MIN(CASE WHEN id_booking > 0 THEN id_booking END) as min_valid,
                    MAX(id_booking) as max_id
                FROM tbl_booking
            ");
            $healthCheck->execute();
            $healthData = $healthCheck->fetchAll(PDO::FETCH_ASSOC);
            
            $overallHealth = true;
            foreach ($healthData as $data) {
                if ($data['zero_count'] > 0) {
                    $overallHealth = false;
                    break;
                }
            }
            
            $healthStatus = $overallHealth ? 'status-good' : 'status-danger';
            $healthIcon = $overallHealth ? '✅' : '❌';
            $healthMessage = $overallHealth ? 'All systems healthy! No zero IDs detected.' : 'WARNING: Zero IDs detected in system!';
            
            echo "<div class='$healthStatus'>
                    <h3>$healthIcon System Status</h3>
                    <p><strong>$healthMessage</strong></p>
                  </div>\n";
            
            // ===== METRICS CARDS =====
            echo "<div style='text-align: center;'>\n";
            foreach ($healthData as $data) {
                $statusColor = $data['zero_count'] > 0 ? '#dc3545' : '#28a745';
                echo "<div class='metric-card'>
                        <div class='metric-value' style='color: $statusColor;'>{$data['zero_count']}</div>
                        <div class='metric-label'>Zero IDs in {$data['entity']}</div>
                      </div>\n";
            }
            echo "</div>\n";
            
            // ===== DETAILED TABLE =====
            echo "<h3>📋 Detailed Metrics</h3>\n";
            echo "<table>\n";
            echo "<thead><tr><th>Entity</th><th>Total Records</th><th>Zero IDs</th><th>Valid IDs</th><th>Min Valid ID</th><th>Max ID</th><th>Health</th></tr></thead>\n";
            echo "<tbody>\n";
            
            foreach ($healthData as $data) {
                $healthIcon = $data['zero_count'] == 0 ? '✅' : '❌';
                $rowClass = $data['zero_count'] > 0 ? 'pulse' : '';
                
                echo "<tr class='$rowClass'>
                        <td><strong>{$data['entity']}</strong></td>
                        <td>{$data['total']}</td>
                        <td style='color: " . ($data['zero_count'] > 0 ? '#dc3545' : '#28a745') . "; font-weight: bold;'>{$data['zero_count']}</td>
                        <td>{$data['valid_count']}</td>
                        <td>{$data['min_valid']}</td>
                        <td>{$data['max_id']}</td>
                        <td>{$healthIcon}</td>
                      </tr>\n";
            }
            echo "</tbody></table>\n";
            
            // ===== AUTO_INCREMENT STATUS =====
            echo "<h3>⚙️ AUTO_INCREMENT Status</h3>\n";
            
            $autoIncrementCheck = $conn->prepare("
                SELECT TABLE_NAME, AUTO_INCREMENT 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME IN ('tbl_users', 'tbl_booking')
                ORDER BY TABLE_NAME
            ");
            $autoIncrementCheck->execute();
            $autoIncrements = $autoIncrementCheck->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table>\n";
            echo "<thead><tr><th>Table</th><th>AUTO_INCREMENT Value</th><th>Status</th></tr></thead>\n";
            echo "<tbody>\n";
            
            foreach ($autoIncrements as $ai) {
                // Get max ID for comparison
                $tableName = $ai['TABLE_NAME'];
                $idColumn = $tableName === 'tbl_users' ? 'id_user' : 'id_booking';
                
                $maxIdStmt = $conn->prepare("SELECT MAX($idColumn) as max_id FROM $tableName");
                $maxIdStmt->execute();
                $maxId = $maxIdStmt->fetchColumn();
                
                $autoIncrementValue = $ai['AUTO_INCREMENT'];
                $isHealthy = $autoIncrementValue > $maxId;
                $healthIcon = $isHealthy ? '✅' : '⚠️';
                $healthNote = $isHealthy ? 'Good' : 'Should be > ' . $maxId;
                
                echo "<tr>
                        <td><strong>$tableName</strong></td>
                        <td>$autoIncrementValue</td>
                        <td>$healthIcon $healthNote</td>
                      </tr>\n";
            }
            echo "</tbody></table>\n";
            
            // ===== RECENT PROBLEMATIC RECORDS =====
            if (!$overallHealth) {
                echo "<h3>🚨 Problematic Records</h3>\n";
                
                // Check for zero ID users
                $zeroUsersStmt = $conn->prepare("SELECT * FROM tbl_users WHERE id_user = 0 LIMIT 5");
                $zeroUsersStmt->execute();
                $zeroUsers = $zeroUsersStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($zeroUsers) > 0) {
                    echo "<h4>❌ Users with ID = 0:</h4>\n";
                    echo "<table>\n";
                    echo "<thead><tr><th>ID</th><th>Email</th><th>NIK</th><th>Name</th><th>Role</th></tr></thead>\n";
                    echo "<tbody>\n";
                    
                    foreach ($zeroUsers as $user) {
                        echo "<tr style='background: #f8d7da;'>
                                <td>{$user['id_user']}</td>
                                <td>{$user['email']}</td>
                                <td>{$user['nik']}</td>
                                <td>{$user['nama']}</td>
                                <td>{$user['role']}</td>
                              </tr>\n";
                    }
                    echo "</tbody></table>\n";
                }
                
                // Check for zero ID bookings
                $zeroBookingsStmt = $conn->prepare("SELECT * FROM tbl_booking WHERE id_booking = 0 LIMIT 5");
                $zeroBookingsStmt->execute();
                $zeroBookings = $zeroBookingsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($zeroBookings) > 0) {
                    echo "<h4>❌ Bookings with ID = 0:</h4>\n";
                    echo "<table>\n";
                    echo "<thead><tr><th>Booking ID</th><th>User ID</th><th>Event</th><th>Date</th><th>Dosen NIK</th></tr></thead>\n";
                    echo "<tbody>\n";
                    
                    foreach ($zeroBookings as $booking) {
                        echo "<tr style='background: #f8d7da;'>
                                <td>{$booking['id_booking']}</td>
                                <td>{$booking['id_user']}</td>
                                <td>{$booking['nama_acara']}</td>
                                <td>{$booking['tanggal']}</td>
                                <td>{$booking['nik_dosen']}</td>
                              </tr>\n";
                    }
                    echo "</tbody></table>\n";
                }
            }
            
            // ===== RECENT SUCCESSFUL RECORDS =====
            echo "<h3>✅ Recent Successful Records</h3>\n";
            
            // Recent users
            $recentUsersStmt = $conn->prepare("
                SELECT id_user, email, nama, role, created_at 
                FROM tbl_users 
                WHERE id_user > 0 
                ORDER BY id_user DESC 
                LIMIT 5
            ");
            $recentUsersStmt->execute();
            $recentUsers = $recentUsersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h4>👤 Recent Users (Valid IDs):</h4>\n";
            echo "<table>\n";
            echo "<thead><tr><th>User ID</th><th>Email</th><th>Name</th><th>Role</th></tr></thead>\n";
            echo "<tbody>\n";
            
            foreach ($recentUsers as $user) {
                echo "<tr style='background: #d4edda;'>
                        <td><strong>{$user['id_user']}</strong></td>
                        <td>{$user['email']}</td>
                        <td>{$user['nama']}</td>
                        <td>{$user['role']}</td>
                      </tr>\n";
            }
            echo "</tbody></table>\n";
            
            // Recent bookings
            $recentBookingsStmt = $conn->prepare("
                SELECT id_booking, id_user, nama_acara, tanggal, created_at 
                FROM tbl_booking 
                WHERE id_booking > 0 
                ORDER BY id_booking DESC 
                LIMIT 5
            ");
            $recentBookingsStmt->execute();
            $recentBookings = $recentBookingsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h4>📅 Recent Bookings (Valid IDs):</h4>\n";
            echo "<table>\n";
            echo "<thead><tr><th>Booking ID</th><th>User ID</th><th>Event</th><th>Date</th></tr></thead>\n";
            echo "<tbody>\n";
            
            foreach ($recentBookings as $booking) {
                echo "<tr style='background: #d4edda;'>
                        <td><strong>{$booking['id_booking']}</strong></td>
                        <td><strong>{$booking['id_user']}</strong></td>
                        <td>{$booking['nama_acara']}</td>
                        <td>{$booking['tanggal']}</td>
                      </tr>\n";
            }
            echo "</tbody></table>\n";
            
            // ===== REAL-TIME TESTING =====
            echo "<h3>🧪 Real-time Testing</h3>\n";
            echo "<div id='testResults'>";
            echo "<p>Click 'Test ID Generation' button above to run real-time tests.</p>";
            echo "</div>\n";
            
            // ===== MONITORING LOG =====
            echo "<h3>📝 System Log</h3>\n";
            echo "<div class='log-area' id='systemLog'>\n";
            echo "[" . date('H:i:s') . "] System monitor initialized\n";
            echo "[" . date('H:i:s') . "] Health check completed - Status: " . ($overallHealth ? 'HEALTHY' : 'ISSUES_DETECTED') . "\n";
            echo "[" . date('H:i:s') . "] Total users: " . $healthData[0]['total'] . " (Zero IDs: " . $healthData[0]['zero_count'] . ")\n";
            echo "[" . date('H:i:s') . "] Total bookings: " . $healthData[1]['total'] . " (Zero IDs: " . $healthData[1]['zero_count'] . ")\n";
            echo "[" . date('H:i:s') . "] Monitoring active...\n";
            echo "</div>\n";
            
        } catch (Exception $e) {
            echo "<div class='status-danger'>";
            echo "<h3>💥 Monitor Error</h3>";
            echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
            echo "<p><strong>File:</strong> " . $e->getFile() . ":" . $e->getLine() . "</p>";
            echo "</div>";
        }
        ?>
    </div>

    <script>
    function addLog(message) {
        const logArea = document.getElementById('systemLog');
        const timestamp = new Date().toLocaleTimeString();
        logArea.innerHTML += `[${timestamp}] ${message}\n`;
        logArea.scrollTop = logArea.scrollHeight;
    }

    function testIdGeneration() {
        const testResults = document.getElementById('testResults');
        testResults.innerHTML = '<p>🧪 Running ID generation tests...</p>';
        addLog('Starting ID generation tests...');
        
        // Test user creation
        fetch('test_id_generation.php?test=user', {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                testResults.innerHTML += `<div class="status-good">✅ User ID Test: Generated ID ${data.user_id} (Valid: ${data.user_id > 0})</div>`;
                addLog(`User ID test passed: Generated ID ${data.user_id}`);
            } else {
                testResults.innerHTML += `<div class="status-danger">❌ User ID Test Failed: ${data.message}</div>`;
                addLog(`User ID test failed: ${data.message}`);
            }
        })
        .catch(error => {
            testResults.innerHTML += `<div class="status-danger">❌ User ID Test Error: ${error.message}</div>`;
            addLog(`User ID test error: ${error.message}`);
        });

        // Test booking creation (if user is logged in)
        fetch('test_id_generation.php?test=booking', {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                testResults.innerHTML += `<div class="status-good">✅ Booking ID Test: Generated ID ${data.booking_id} (Valid: ${data.booking_id > 0})</div>`;
                addLog(`Booking ID test passed: Generated ID ${data.booking_id}`);
            } else {
                testResults.innerHTML += `<div class="status-warning">⚠️ Booking ID Test: ${data.message}</div>`;
                addLog(`Booking ID test: ${data.message}`);
            }
        })
        .catch(error => {
            testResults.innerHTML += `<div class="status-danger">❌ Booking ID Test Error: ${error.message}</div>`;
            addLog(`Booking ID test error: ${error.message}`);
        });
    }

    function runEmergencyFix() {
        if (confirm('⚠️ WARNING: This will run emergency fix procedures. Continue?')) {
            addLog('EMERGENCY FIX: Starting automated fix procedures...');
            
            window.open('comprehensive_fix.php', '_blank');
            
            setTimeout(() => {
                addLog('EMERGENCY FIX: Fix script opened in new window');
                addLog('Please check the fix results and refresh this monitor');
            }, 1000);
        }
    }

    // Auto-refresh every 30 seconds
    setInterval(() => {
        addLog('Auto-refresh in 5 seconds...');
        setTimeout(() => {
            location.reload();
        }, 5000);
    }, 30000);

    addLog('Monitor script loaded - Auto-refresh enabled every 30 seconds');
    </script>
</body>
</html>