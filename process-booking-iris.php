<?php
// process-booking-iris.php - VERSION 3 WITH SQL SYNTAX FIX
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

function debugLog($message, $data = null) {
    $logEntry = "[BOOKING] " . date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $logEntry .= " | Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    error_log($logEntry);
}

function safeJsonOutput($data) {
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeInput($input) {
    if (is_string($input)) {
        // Remove any potential SQL injection characters
        $input = trim($input);
        $input = str_replace(['"', "'", '`', ';', '--', '/*', '*/', 'DROP', 'DELETE'], '', $input);
        return $input;
    }
    return $input;
}


try {
    // Test endpoint
    if (isset($_GET['test'])) {
        debugLog("Test request received");
        safeJsonOutput([
            'success' => true,
            'message' => 'Berhasil!',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '3.0'
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        safeJsonOutput(['success' => false, 'message' => 'Only POST method allowed']);
    }

    // Include required files
    require_once 'config.php';
    require_once 'functions.php';

    // Session validation with detailed logging
    debugLog("Session check", [
        'session_active' => session_status() === PHP_SESSION_ACTIVE,
        'user_id_exists' => isset($_SESSION['user_id']),
        'user_id_value' => $_SESSION['user_id'] ?? 'not set',
        'role_exists' => isset($_SESSION['role']),
        'role_value' => $_SESSION['role'] ?? 'not set'
    ]);

    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'dosen') {
        debugLog("Access denied - invalid session");
        safeJsonOutput(['success' => false, 'message' => 'Access denied. Please login as dosen.']);
    }

    $sessionUserId = intval($_SESSION['user_id']);
    if ($sessionUserId <= 0) {
        debugLog("Invalid user_id in session", ['user_id' => $_SESSION['user_id']]);
        safeJsonOutput(['success' => false, 'message' => 'Invalid session. Please logout and login again.']);
    }

    // Verify user in database with explicit error handling
    $userStmt = $conn->prepare("SELECT id_user, nama, nik, email FROM tbl_users WHERE id_user = ? AND role = ?");
    $userStmt->execute([$sessionUserId, 'dosen']);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        debugLog("User not found in database", ['session_user_id' => $sessionUserId]);
        safeJsonOutput(['success' => false, 'message' => 'User not found. Please logout and login again.']);
    }

    debugLog("User validated successfully", [
        'user_id' => $user['id_user'],
        'nama' => $user['nama'],
        'nik' => $user['nik'],
        'email' => $user['email']
    ]);

    // Get and sanitize input with strict validation
    $roomId = intval($_POST['id_ruang'] ?? 0);
    $tanggal = sanitizeInput($_POST['tanggal'] ?? '');
    $jamMulai = sanitizeInput($_POST['jam_mulai'] ?? '');
    $jamSelesai = sanitizeInput($_POST['jam_selesai'] ?? '');
    $mataKuliah = sanitizeInput($_POST['mata_kuliah'] ?? '');
    $kelas = sanitizeInput($_POST['kelas'] ?? '');
    $semester = sanitizeInput($_POST['semester'] ?? '');
    $tahunAkademik = sanitizeInput($_POST['tahun_akademik'] ?? '');
    $periode = sanitizeInput($_POST['periode'] ?? '');
    $catatanTambahan = sanitizeInput($_POST['catatan_tambahan'] ?? '');

    debugLog("Input received and sanitized", [
        'room_id' => $roomId,
        'tanggal' => $tanggal,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'mata_kuliah' => $mataKuliah,
        'kelas' => $kelas
    ]);

    // Enhanced validation
    if (!$roomId || $roomId <= 0) {
        safeJsonOutput(['success' => false, 'message' => 'Room ID tidak valid']);
    }
    
    if (!$tanggal || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        safeJsonOutput(['success' => false, 'message' => 'Format tanggal tidak valid']);
    }
    
    if (!$jamMulai || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jamMulai)) {
        safeJsonOutput(['success' => false, 'message' => 'Format jam mulai tidak valid']);
    }
    
    if (!$jamSelesai || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jamSelesai)) {
        safeJsonOutput(['success' => false, 'message' => 'Format jam selesai tidak valid']);
    }
    
    if (!$mataKuliah || strlen($mataKuliah) < 3) {
        safeJsonOutput(['success' => false, 'message' => 'Nama mata kuliah harus diisi minimal 3 karakter']);
    }

    // Normalize time format (ensure HH:MM:SS)
    if (strlen($jamMulai) == 5) $jamMulai .= ':00';
    if (strlen($jamSelesai) == 5) $jamSelesai .= ':00';

    // Check room exists
    $roomStmt = $conn->prepare("SELECT r.id_ruang, r.nama_ruang, r.kapasitas, g.nama_gedung 
                                FROM tbl_ruang r 
                                LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                                WHERE r.id_ruang = ?");
    $roomStmt->execute([$roomId]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        debugLog("Room not found", ['room_id' => $roomId]);
        safeJsonOutput(['success' => false, 'message' => 'Ruangan tidak ditemukan']);
    }

    debugLog("Room found", $room);

    // Check for time conflicts with detailed logging
    $conflictStmt = $conn->prepare("
        SELECT id_booking, nama_acara, jam_mulai, jam_selesai, status
        FROM tbl_booking 
        WHERE id_ruang = ? AND tanggal = ? AND status NOT IN ('cancelled', 'rejected')
        AND (
            (jam_mulai <= ? AND jam_selesai > ?) OR
            (jam_mulai < ? AND jam_selesai >= ?) OR
            (jam_mulai >= ? AND jam_selesai <= ?)
        )
    ");
    
    $conflictStmt->execute([
        $roomId, $tanggal, 
        $jamMulai, $jamMulai,
        $jamSelesai, $jamSelesai,
        $jamMulai, $jamSelesai
    ]);
    
    $conflicts = $conflictStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($conflicts)) {
        debugLog("Time conflicts found", $conflicts);
        safeJsonOutput([
            'success' => false, 
            'message' => 'Waktu bentrok dengan booking: ' . $conflicts[0]['nama_acara'] . 
                        ' (' . $conflicts[0]['jam_mulai'] . ' - ' . $conflicts[0]['jam_selesai'] . ')'
        ]);
    }

    debugLog("No conflicts found, proceeding with booking creation");

    // Prepare booking data with safe string handling
    $namaAcara = $mataKuliah;
    if (!empty($kelas)) {
        $namaAcara .= ' - Kelas ' . $kelas;
    }
    
    $keterangan = 'Perkuliahan: ' . $mataKuliah;
    if (!empty($kelas)) $keterangan .= ' - Kelas ' . $kelas;
    if (!empty($semester)) $keterangan .= ' - Semester ' . $semester;
    if (!empty($tahunAkademik)) $keterangan .= ' - ' . $tahunAkademik;
    if (!empty($periode)) $keterangan .= ' (' . $periode . ')';
    if (!empty($catatanTambahan)) {
        $keterangan .= "\n\nCatatan: " . $catatanTambahan;
    }

    $namaPenanggungJawab = $user['nama'];
    $noPenanggungJawab = !empty($user['nik']) ? 
        preg_replace('/[^\d]/', '', $user['nik']) : '0'; // Remove non-digits

    debugLog("Booking data prepared", [
        'nama_acara' => $namaAcara,
        'nama_pic' => $namaPenanggungJawab,
        'no_pic' => $noPenanggungJawab,
        'keterangan_length' => strlen($keterangan)
    ]);

    // Start transaction
    $conn->beginTransaction();

    try {
        // Simple INSERT without specifying id_booking (let AUTO_INCREMENT handle it)
        $insertStmt = $conn->prepare("
            INSERT INTO tbl_booking (
                id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai,
                keterangan, nama, no_penanggungjawab, status, 
                user_type, nik_dosen, nama_dosen, email_dosen,
                tahun_akademik_info, periode_info, catatan_dosen,
                approved_at, approved_by, auto_approved, auto_approval_reason, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                NOW(), ?, ?, ?, NOW()
            )
        ");

        $insertParams = [
            $user['id_user'],           // id_user
            $roomId,                    // id_ruang  
            $namaAcara,                 // nama_acara
            $tanggal,                   // tanggal
            $jamMulai,                  // jam_mulai
            $jamSelesai,                // jam_selesai
            $keterangan,                // keterangan
            $namaPenanggungJawab,       // nama
            $noPenanggungJawab,         // no_penanggungjawab
            'approve',                  // status
            'dosen_iris',               // user_type
            $user['nik'],               // nik_dosen
            $user['nama'],              // nama_dosen
            $user['email'],             // email_dosen
            $tahunAkademik,            // tahun_akademik_info
            $periode,                   // periode_info
            $catatanTambahan,          // catatan_dosen
            'SYSTEM_AUTO',             // approved_by
            1,                         // auto_approved
            'Auto-approved dosen booking' // auto_approval_reason
        ];

        debugLog("Executing INSERT with parameters", [
            'param_count' => count($insertParams),
            'user_id' => $insertParams[0],
            'room_id' => $insertParams[1],
            'nama_acara' => $insertParams[2]
        ]);

        $result = $insertStmt->execute($insertParams);
        
        if (!$result) {
            $errorInfo = $insertStmt->errorInfo();
            throw new Exception("INSERT failed: " . implode(' | ', $errorInfo));
        }

        $bookingId = $conn->lastInsertId();
        
        debugLog("INSERT executed", [
            'success' => $result,
            'last_insert_id' => $bookingId,
            'id_is_valid' => $bookingId > 0
        ]);

        if (!$bookingId || $bookingId <= 0) {
            throw new Exception("Invalid booking ID generated: " . $bookingId);
        }

        // Verify the booking was created correctly
        $verifyStmt = $conn->prepare("
            SELECT id_booking, id_user, nama_acara, tanggal, jam_mulai, jam_selesai, status 
            FROM tbl_booking 
            WHERE id_booking = ?
        ");
        $verifyStmt->execute([$bookingId]);
        $createdBooking = $verifyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$createdBooking) {
            throw new Exception("Verification failed: booking not found after insert");
        }

        if ($createdBooking['id_user'] != $user['id_user']) {
            throw new Exception("Verification failed: user ID mismatch");
        }

        debugLog("Booking verification successful", $createdBooking);

        // Commit transaction
        $conn->commit();

        debugLog("Transaction committed successfully", ['booking_id' => $bookingId]);

        // SUCCESS RESPONSE
        safeJsonOutput([
            'success' => true,
            'message' => 'Booking berhasil! Ruangan ' . $room['nama_ruang'] . ' telah disetujui untuk ' . $mataKuliah,
            'booking_id' => $bookingId,
            'details' => [
                'ruangan' => $room['nama_ruang'],
                'gedung' => $room['nama_gedung'] ?? 'Unknown',
                'tanggal' => date('d/m/Y', strtotime($tanggal)),
                'waktu' => date('H:i', strtotime($jamMulai)) . ' - ' . date('H:i', strtotime($jamSelesai)),
                'mata_kuliah' => $mataKuliah,
                'kelas' => $kelas ?: 'Tidak ada',
                'dosen' => $namaPenanggungJawab,
                'pic' => $namaPenanggungJawab,
                'kontak' => $noPenanggungJawab,
                'semester' => $semester ?: 'Tidak ditentukan',
                'tahun_akademik' => $tahunAkademik ?: 'Tidak ditentukan',
                'status' => 'approved',
                'booking_id_confirmed' => $bookingId
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        debugLog("Transaction rolled back due to error", ['error' => $e->getMessage()]);
        throw $e;
    }

} catch (PDOException $e) {
    debugLog("PDO Exception occurred", [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    safeJsonOutput([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'debug_info' => [
            'error_code' => $e->getCode(),
            'error_file' => basename($e->getFile()),
            'error_line' => $e->getLine()
        ]
    ]);

} catch (Exception $e) {
    debugLog("General Exception occurred", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    safeJsonOutput([
        'success' => false, 
        'message' => 'System error: ' . $e->getMessage(),
        'debug_info' => [
            'error_file' => basename($e->getFile()),
            'error_line' => $e->getLine()
        ]
    ]);
}

?>