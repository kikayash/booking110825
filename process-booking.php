<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to user, log them instead
ini_set('log_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Log incoming request for debugging
error_log("process-booking.php called. Method: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));

try {
    // Check if user is logged in
    if (!isLoggedIn()) {
        error_log("User not logged in");
        echo json_encode(['success' => false, 'message' => 'Anda harus login terlebih dahulu']);
        exit;
    }

    // Check if request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit;
    }

    // Get form data with validation
    $tanggal = trim($_POST['tanggal'] ?? '');
    $jamMulai = trim($_POST['jam_mulai'] ?? '');
    $jamSelesai = trim($_POST['jam_selesai'] ?? '');
    $idRuang = intval($_POST['id_ruang'] ?? 0);
    $namaAcara = trim($_POST['nama_acara'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $namaPenanggungJawab = trim($_POST['nama_penanggungjawab'] ?? '');
    $noPenanggungJawab = trim($_POST['no_penanggungjawab'] ?? '');
    $autoApprove = isset($_POST['auto_approve']) ? 1 : 0;

    // Log form data
    error_log("Form data - Date: $tanggal, Room: $idRuang, Event: $namaAcara");

    // Validation
    $errors = [];
    
    if (empty($tanggal)) $errors[] = 'Tanggal harus diisi';
    if (empty($jamMulai)) $errors[] = 'Jam mulai harus diisi';
    if (empty($jamSelesai)) $errors[] = 'Jam selesai harus diisi';
    if (empty($idRuang) || $idRuang <= 0) $errors[] = 'Ruangan harus dipilih';
    if (empty($namaAcara)) $errors[] = 'Nama acara harus diisi';
    if (empty($keterangan)) $errors[] = 'Keterangan harus diisi';
    if (empty($namaPenanggungJawab)) $errors[] = 'Nama penanggung jawab harus diisi';
    if (empty($noPenanggungJawab)) $errors[] = 'No. HP penanggung jawab harus diisi';

    if (!empty($errors)) {
        error_log("Validation errors: " . implode(', ', $errors));
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        exit;
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        error_log("Invalid date format: $tanggal");
        echo json_encode(['success' => false, 'message' => 'Format tanggal tidak valid']);
        exit;
    }

    // Validate time format
    if (!preg_match('/^\d{2}:\d{2}$/', $jamMulai) || !preg_match('/^\d{2}:\d{2}$/', $jamSelesai)) {
        error_log("Invalid time format: $jamMulai - $jamSelesai");
        echo json_encode(['success' => false, 'message' => 'Format waktu tidak valid']);
        exit;
    }

    // Validate time range
    if ($jamMulai >= $jamSelesai) {
        error_log("Invalid time range: $jamMulai >= $jamSelesai");
        echo json_encode(['success' => false, 'message' => 'Jam selesai harus lebih dari jam mulai']);
        exit;
    }

    // Validate booking date (tidak boleh masa lalu)
    $today = date('Y-m-d');
    if ($tanggal < $today) {
        error_log("Booking date in the past: $tanggal < $today");
        echo json_encode(['success' => false, 'message' => 'Tidak dapat memesan ruangan untuk tanggal yang sudah lewat']);
        exit;
    }

    /* Validate booking range (maksimal 1 bulan ke depan)
    $maxDate = date('Y-m-d', strtotime('+1 month'));
    if ($tanggal > $maxDate) {
        error_log("Booking date too far: $tanggal > $maxDate");
        echo json_encode(['success' => false, 'message' => 'Booking maksimal 1 bulan ke depan']);
        exit;
    }*/

    // Check if room exists
    $stmt = $conn->prepare("SELECT * FROM tbl_ruang WHERE id_ruang = ?");
    $stmt->execute([$idRuang]);
    $room = $stmt->fetch();
    
    if (!$room) {
        error_log("Room not found: $idRuang");
        echo json_encode(['success' => false, 'message' => 'Ruangan tidak ditemukan']);
        exit;
    }

    // Check for conflicts
    if (hasBookingConflict($conn, $idRuang, $tanggal, $jamMulai, $jamSelesai)) {
        error_log("Booking conflict detected for room $idRuang on $tanggal $jamMulai-$jamSelesai");
        echo json_encode(['success' => false, 'message' => 'Ruangan sudah dibooking pada waktu tersebut']);
        exit;
    }

    // Check if it's a holiday
    $stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
    $stmt->execute([$tanggal]);
    $holiday = $stmt->fetch();
    
    if ($holiday) {
        error_log("Booking on holiday: $tanggal - " . $holiday['keterangan']);
        echo json_encode(['success' => false, 'message' => 'Tidak dapat memesan ruangan pada hari libur: ' . $holiday['keterangan']]);
        exit;
    }

    // Determine initial status and auto-approval eligibility
    $currentDateTime = new DateTime();
    $bookingDateTime = new DateTime($tanggal . ' ' . $jamMulai);
    $minutesUntilBooking = ($bookingDateTime->getTimestamp() - $currentDateTime->getTimestamp()) / 60;
    
    $initialStatus = 'pending'; // Default status
    $isAutoApproved = false;
    $autoApprovalReason = '';
    $userCanActivate = 0;
    
    // Auto-approval logic - HANYA untuk kondisi tertentu
    if ($autoApprove) {
        $userRole = $_SESSION['role'] ?? '';
        $canAutoApprove = false;
        
        // Rule 1: Dosen yang booking 5 menit sebelum waktu mulai
        if ($userRole === 'dosen' && $minutesUntilBooking <= 5 && $minutesUntilBooking > 0) {
            $canAutoApprove = true;
            $autoApprovalReason = 'Auto-approved: Dosen booking 5 menit sebelum waktu mulai';
        }
        // Rule 2: Staff/Admin privilege
        elseif (in_array($userRole, ['admin', 'cs', 'satpam'])) {
            $canAutoApprove = true;
            $autoApprovalReason = 'Auto-approved: Staff/Admin privilege';
        }
        
        if ($canAutoApprove) {
            $initialStatus = 'approve';
            $isAutoApproved = true;
            $userCanActivate = 1;
        }
    }

    // Prepare SQL with proper field names
    $sql = "INSERT INTO tbl_booking 
            (id_user, id_ruang, tanggal, jam_mulai, jam_selesai, nama_acara, keterangan, 
             nama_penanggungjawab, no_penanggungjawab, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $params = [
        $_SESSION['user_id'],
        $idRuang,
        $tanggal,
        $jamMulai,
        $jamSelesai,
        $namaAcara,
        $keterangan,
        $namaPenanggungJawab,
        $noPenanggungJawab,
        $initialStatus
    ];

    error_log("Executing SQL: $sql");
    error_log("Parameters: " . print_r($params, true));

    $stmt = $conn->prepare($sql);
    $result = $stmt->execute($params);

    if ($result) {
        $bookingId = $conn->lastInsertId();
        
        // Log the booking
        error_log("NEW BOOKING SUCCESS: ID $bookingId by user {$_SESSION['user_id']} - Status: $initialStatus");

        // Prepare response
        $response = [
            'success' => true,
            'message' => $isAutoApproved ? 
                'Peminjaman berhasil disubmit dan disetujui otomatis!' : 
                'Peminjaman berhasil disubmit. Menunggu persetujuan admin.',
            'booking_id' => $bookingId,
            'status' => $initialStatus,
            'auto_approved' => $isAutoApproved,
            'user_can_activate' => $userCanActivate
        ];
        
        if ($isAutoApproved) {
            $response['auto_approval_reason'] = $autoApprovalReason;
        }

        error_log("Sending response: " . json_encode($response));
        echo json_encode($response);
    } else {
        $errorInfo = $stmt->errorInfo();
        error_log("SQL Error: " . print_r($errorInfo, true));
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan peminjaman: ' . $errorInfo[2]]);
    }

} catch (PDOException $e) {
    error_log("Database error in process-booking.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error in process-booking.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} catch (Error $e) {
    error_log("Fatal error in process-booking.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
}

function processBookingSubmission($conn) {
    $tanggal = $_POST['tanggal'] ?? '';
    $jamMulai = $_POST['jam_mulai'] ?? '';
    $jamSelesai = $_POST['jam_selesai'] ?? '';
    $roomId = $_POST['id_ruang'] ?? '';
    
    // Validate booking date
    $dateErrors = validateBookingDate($conn, $tanggal);
    if (!empty($dateErrors)) {
        return [
            'success' => false,
            'message' => implode(' ', $dateErrors)
        ];
    }
    
    // Check for conflicts with recurring schedules
    $recurringConflicts = checkRecurringConflicts($conn, $roomId, $tanggal, $jamMulai, $jamSelesai);
    if ($recurringConflicts) {
        return [
            'success' => false,
            'message' => 'Konflik dengan jadwal perkuliahan: ' . $recurringConflicts
        ];
    }
    
    // Continue with normal booking process...
    return processNormalBooking($conn);
}

function checkRecurringConflicts($conn, $roomId, $date, $startTime, $endTime) {
    $dayOfWeek = strtolower(date('l', strtotime($date)));
    
    $stmt = $conn->prepare("
        SELECT nama_matakuliah, dosen_pengampu 
        FROM tbl_recurring_schedules 
        WHERE id_ruang = ? 
        AND day_of_week = ?
        AND status = 'active'
        AND ? BETWEEN start_date AND end_date
        AND (
            (start_time < ? AND end_time > ?) OR
            (start_time < ? AND end_time > ?) OR
            (start_time >= ? AND end_time <= ?)
        )
    ");
    
    $stmt->execute([
        $roomId, 
        $dayOfWeek, 
        $date,
        $endTime, $startTime,  // Check if recurring starts before booking ends
        $startTime, $endTime,  // Check if recurring ends after booking starts  
        $startTime, $endTime   // Check if recurring is completely within booking
    ]);
    
    $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($conflict) {
        return "Mata kuliah {$conflict['nama_matakuliah']} (Dosen: {$conflict['dosen_pengampu']})";
    }
    
    return false;
}
?>