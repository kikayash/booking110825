<?php
// process-login-nik.php - AUTO-FIX VERSION dengan built-in ID correction
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();
session_start();
require_once 'config.php';
require_once 'functions.php';

ob_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

function sendJsonResponse($data) {
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function logError($message) {
    error_log("NIK LOGIN DEBUG: " . $message);
}

// FUNCTION: Auto-fix user ID = 0 issues
function autoFixZeroUserIds($conn) {
    try {
        logError("AUTO-FIX: Checking for users with ID = 0...");
        
        // Check if there are users with id_user = 0
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE id_user = 0");
        $checkStmt->execute();
        $zeroUserCount = $checkStmt->fetchColumn();
        
        if ($zeroUserCount > 0) {
            logError("AUTO-FIX: Found {$zeroUserCount} users with ID = 0, fixing...");
            
            // Get max current ID
            $maxStmt = $conn->prepare("SELECT COALESCE(MAX(id_user), 0) FROM tbl_users WHERE id_user > 0");
            $maxStmt->execute();
            $maxId = $maxStmt->fetchColumn();
            $startId = max(10000, $maxId + 1000); // Start from safe ID
            
            // Fix users with ID = 0
            $conn->exec("SET @new_id = {$startId}");
            $fixStmt = $conn->prepare("UPDATE tbl_users SET id_user = (@new_id := @new_id + 1) WHERE id_user = 0");
            $fixResult = $fixStmt->execute();
            
            if ($fixResult) {
                $fixedCount = $fixStmt->rowCount();
                logError("AUTO-FIX: Fixed {$fixedCount} users with zero IDs");
                
                // Fix related bookings
                $bookingFixStmt = $conn->prepare("
                    UPDATE tbl_booking b
                    INNER JOIN tbl_users u ON (b.nik_dosen = u.nik OR b.email_dosen = u.email)
                    SET b.id_user = u.id_user
                    WHERE b.id_user = 0 AND u.id_user > 0
                ");
                $bookingFixResult = $bookingFixStmt->execute();
                $fixedBookings = $bookingFixStmt->rowCount();
                
                if ($fixedBookings > 0) {
                    logError("AUTO-FIX: Fixed {$fixedBookings} related bookings");
                }
                
                // Update AUTO_INCREMENT
                $newMaxStmt = $conn->prepare("SELECT MAX(id_user) FROM tbl_users");
                $newMaxStmt->execute();
                $newMaxId = $newMaxStmt->fetchColumn();
                $autoIncrement = $newMaxId + 100;
                
                $conn->exec("ALTER TABLE tbl_users AUTO_INCREMENT = {$autoIncrement}");
                logError("AUTO-FIX: Set AUTO_INCREMENT to {$autoIncrement}");
                
                return ['fixed' => true, 'count' => $fixedCount, 'bookings' => $fixedBookings];
            }
        }
        
        return ['fixed' => false, 'count' => 0, 'bookings' => 0];
        
    } catch (Exception $e) {
        logError("AUTO-FIX ERROR: " . $e->getMessage());
        return ['fixed' => false, 'error' => $e->getMessage()];
    }
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse([
            'success' => false, 
            'message' => 'Method tidak diizinkan'
        ]);
    }

    // Get and validate input
    $nik = trim($_POST['nik'] ?? '');
    $password = trim($_POST['password'] ?? '');

    logError("=== LOGIN ATTEMPT START ===");
    logError("Input NIK: '$nik'");

    if (empty($nik) || empty($password)) {
        sendJsonResponse([
            'success' => false, 
            'message' => 'NIK dan password harus diisi'
        ]);
    }

    // Clean NIK
    $cleanNik = preg_replace('/[^\d]/', '', $nik);
    logError("Cleaned NIK: '$cleanNik'");

    if (strlen($cleanNik) < 8 || strlen($cleanNik) > 18) {
        sendJsonResponse([
            'success' => false, 
            'message' => 'Format NIK tidak valid (harus 8-18 digit)'
        ]);
    }

    // ===== AUTO-FIX: Run automatic ID fixing BEFORE authentication =====
    logError("Running auto-fix for zero IDs...");
    $autoFixResult = autoFixZeroUserIds($conn);
    
    if ($autoFixResult['fixed']) {
        logError("AUTO-FIX SUCCESS: Fixed {$autoFixResult['count']} users and {$autoFixResult['bookings']} bookings");
    }

    // Check database connections
    $dosenData = null;
    $authMethod = 'fallback';
    
    // Try to authenticate with dosen database
    if (isset($conn_dosen) && $conn_dosen !== null) {
        try {
            logError("=== DOSEN DATABASE AUTHENTICATION ===");
            
            $nikVariations = [
                $nik,
                $cleanNik,
                str_pad($cleanNik, 9, '0', STR_PAD_LEFT),
                str_pad($cleanNik, 10, '0', STR_PAD_LEFT),
                substr($cleanNik, 0, 3) . '.' . substr($cleanNik, 3, 3) . '.' . substr($cleanNik, 6),
                substr($cleanNik, 0, 3) . '.' . substr($cleanNik, 3, 2) . '.' . substr($cleanNik, 5),
                ltrim($cleanNik, '0'),
            ];
            
            $nikVariations = array_unique(array_filter($nikVariations));
            
            foreach ($nikVariations as $searchNik) {
                $stmt = $conn_dosen->prepare("
                    SELECT karyawan_id, nik, nama_lengkap, email, password,
                           status_aktif, status_mengajar, gelar_awal, gelar_akhir
                    FROM tblKaryawan 
                    WHERE nik = ? AND status_aktif = 'Aktif'
                    LIMIT 1
                ");
                
                $stmt->execute([$searchNik]);
                $dosenData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dosenData) {
                    logError("FOUND DOSEN: " . $dosenData['nama_lengkap']);
                    $authMethod = 'database';
                    break;
                }
            }
            
            // Verify password
            if ($dosenData) {
                $passwordValid = false;
                
                if (!empty($dosenData['password'])) {
                    if ($password === $dosenData['password'] || password_verify($password, $dosenData['password'])) {
                        $passwordValid = true;
                    }
                }
                
                if (!$passwordValid) {
                    $testPasswords = [$cleanNik, $dosenData['nik'], str_replace('.', '', $dosenData['nik']), $nik];
                    foreach ($testPasswords as $testPwd) {
                        if ($password === $testPwd) {
                            $passwordValid = true;
                            break;
                        }
                    }
                }
                
                if (!$passwordValid) {
                    sendJsonResponse([
                        'success' => false,
                        'message' => 'Password salah. Gunakan NIK tanpa titik sebagai password.'
                    ]);
                }
            }
            
        } catch (Exception $e) {
            logError("Dosen database error: " . $e->getMessage());
        }
    }
    
    // Fallback authentication
    if (!$dosenData) {
        if ($password !== $cleanNik && $password !== $nik && $password !== str_replace('.', '', $nik)) {
            sendJsonResponse([
                'success' => false,
                'message' => "Password salah. Gunakan NIK sebagai password."
            ]);
        }
        
        $dosenData = [
            'karyawan_id' => null,
            'nik' => $nik,
            'nama_lengkap' => "Dosen (NIK: $nik)",
            'email' => strtolower($cleanNik) . '@dosen.stie-mce.ac.id',
            'status_aktif' => 'Aktif',
            'status_mengajar' => 'Ya',
            'gelar_awal' => '',
            'gelar_akhir' => ''
        ];
        $authMethod = 'fallback';
    }

    // ===== CRITICAL: Enhanced user creation with GUARANTEED valid ID =====
    logError("=== BOOKING DATABASE SYNC ===");
    
    $email = !empty($dosenData['email']) ? $dosenData['email'] : strtolower($cleanNik) . '@dosen.stie-mce.ac.id';
    $nama = $dosenData['nama_lengkap'];
    $nikForBooking = $dosenData['nik'];
    
    // Check if user exists (EXCLUDING any with id_user = 0)
    $existingUser = null;
    $userId = null;
    
    if (!empty($nikForBooking)) {
        $stmt = $conn->prepare("
            SELECT id_user, email, role, nik, nama 
            FROM tbl_users 
            WHERE nik = ? AND id_user > 0
            ORDER BY id_user DESC 
            LIMIT 1
        ");
        $stmt->execute([$nikForBooking]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$existingUser) {
        $stmt = $conn->prepare("
            SELECT id_user, email, role, nik, nama 
            FROM tbl_users 
            WHERE email = ? AND id_user > 0
            ORDER BY id_user DESC 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($existingUser) {
        $userId = $existingUser['id_user'];
        logError("Using existing user: $userId");
        
        // Update user data
        try {
            $stmt = $conn->prepare("
                UPDATE tbl_users 
                SET password = ?, nama = ?, nik = ?, role = 'dosen'
                WHERE id_user = ?
            ");
            $stmt->execute([
                password_hash($cleanNik, PASSWORD_DEFAULT), 
                $nama,
                $nikForBooking,
                $userId
            ]);
        } catch (Exception $e) {
            logError("Warning: Could not update user: " . $e->getMessage());
        }
        
    } else {
        logError("Creating new user for dosen...");
        
        // ===== CRITICAL: Force proper AUTO_INCREMENT before insert =====
        try {
            // Get current max ID and ensure AUTO_INCREMENT is safe
            $maxStmt = $conn->prepare("SELECT COALESCE(MAX(id_user), 0) FROM tbl_users WHERE id_user > 0");
            $maxStmt->execute();
            $currentMax = $maxStmt->fetchColumn();
            $safeAutoIncrement = max(10000, $currentMax + 100);
            
            // Set AUTO_INCREMENT to safe value
            $conn->exec("ALTER TABLE tbl_users AUTO_INCREMENT = {$safeAutoIncrement}");
            logError("Set AUTO_INCREMENT to: {$safeAutoIncrement}");
            
            // Insert new user (id_user will be auto-generated)
            $sql = "INSERT INTO tbl_users (email, password, role, nama, nik) VALUES (?, ?, 'dosen', ?, ?)";
            $stmt = $conn->prepare($sql);
            
            $result = $stmt->execute([
                $email, 
                password_hash($cleanNik, PASSWORD_DEFAULT), 
                $nama,
                $nikForBooking
            ]);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Failed to create user: " . $errorInfo[2]);
            }
            
            $userId = $conn->lastInsertId();
            
            // ===== CRITICAL: Validate generated ID =====
            if (!$userId || $userId <= 0) {
                // EMERGENCY: Manual ID assignment
                logError("EMERGENCY: AUTO_INCREMENT failed, assigning manual ID");
                
                $emergencyId = $safeAutoIncrement;
                $emergencyStmt = $conn->prepare("
                    UPDATE tbl_users 
                    SET id_user = ? 
                    WHERE email = ? AND nik = ? AND id_user <= 0
                ");
                $emergencyResult = $emergencyStmt->execute([$emergencyId, $email, $nikForBooking]);
                
                if ($emergencyResult) {
                    $userId = $emergencyId;
                    logError("EMERGENCY FIX: Assigned manual ID: {$userId}");
                } else {
                    throw new Exception("CRITICAL: Could not assign valid user ID");
                }
            }
            
            logError("Created new user with ID: $userId for dosen: $nama");
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                // Try to find the user again
                $stmt = $conn->prepare("
                    SELECT id_user 
                    FROM tbl_users 
                    WHERE (email = ? OR nik = ?) AND id_user > 0
                    ORDER BY id_user DESC 
                    LIMIT 1
                ");
                $stmt->execute([$email, $nikForBooking]);
                $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($foundUser && $foundUser['id_user'] > 0) {
                    $userId = $foundUser['id_user'];
                    logError("Found existing user after duplicate: $userId");
                } else {
                    throw new Exception("Could not create or find user: " . $e->getMessage());
                }
            } else {
                throw $e;
            }
        }
    }

    // FINAL VALIDATION
    if (!$userId || $userId <= 0) {
        logError("CRITICAL ERROR: Final user ID validation failed: " . $userId);
        sendJsonResponse([
            'success' => false,
            'message' => 'Terjadi kesalahan dalam sistem user ID. Silakan hubungi administrator.'
        ]);
    }

    // Set session
    session_regenerate_id(true);
    
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['nik'] = $nikForBooking;
    $_SESSION['nama'] = $nama;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = 'dosen';
    $_SESSION['login_method'] = 'nik_login';
    $_SESSION['auth_method'] = $authMethod;
    $_SESSION['karyawan_id'] = $dosenData['karyawan_id'] ?? null;
    $_SESSION['gelar_awal'] = $dosenData['gelar_awal'] ?? '';
    $_SESSION['gelar_akhir'] = $dosenData['gelar_akhir'] ?? '';
    $_SESSION['login_time'] = date('Y-m-d H:i:s');
    $_SESSION['last_activity'] = time();
    
    logError("=== LOGIN SUCCESS ===");
    logError("User ID: $userId (VERIFIED > 0)");
    logError("NIK: $nikForBooking");

    // SUCCESS RESPONSE
    sendJsonResponse([
        'success' => true,
        'message' => 'Login berhasil! Selamat datang, ' . $nama,
        'user' => [
            'id' => $userId,
            'nik' => $nikForBooking,
            'nama' => $nama,
            'email' => $email,
            'role' => 'dosen',
            'gelar_awal' => $dosenData['gelar_awal'] ?? '',
            'gelar_akhir' => $dosenData['gelar_akhir'] ?? ''
        ],
        'redirect' => 'index.php',
        'auth_method' => $authMethod,
        'auto_fix_applied' => $autoFixResult['fixed'],
        'debug_info' => [
            'user_id_valid' => $userId > 0,
            'found_in_db' => $authMethod === 'database',
            'user_created' => !$existingUser,
            'auto_fix_count' => $autoFixResult['count'] ?? 0
        ]
    ]);

} catch (Exception $e) {
    logError("FATAL EXCEPTION: " . $e->getMessage());
    
    sendJsonResponse([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>