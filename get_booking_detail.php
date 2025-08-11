<?php
// get_booking_detail.php - IMPROVED VERSION WITH ENHANCED ERROR HANDLING
session_start();
require_once 'config.php';
require_once 'functions.php';

// PERBAIKAN 1: Set proper headers at the very beginning
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// PERBAIKAN 2: Function to send JSON response and exit
function sendJsonResponse($data) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// PERBAIKAN 3: Error handling wrapper
function handleError($message, $debug_info = null) {
    error_log("Booking Detail Error: " . $message);
    if ($debug_info) {
        error_log("Debug Info: " . print_r($debug_info, true));
    }
    
    sendJsonResponse([
        'success' => false,
        'message' => $message,
        'debug' => $debug_info,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

try {
    // PERBAIKAN 4: Enhanced input validation
    $bookingId = isset($_GET['id']) ? trim($_GET['id']) : '';
    
    if (empty($bookingId)) {
        handleError('ID booking tidak boleh kosong');
    }
    
    if (!is_numeric($bookingId) || intval($bookingId) <= 0) {
        handleError('ID booking harus berupa angka positif');
    }
    
    $bookingId = intval($bookingId);
    
    // PERBAIKAN 5: Check if user is logged in
    if (!isLoggedIn()) {
        handleError('Anda harus login terlebih dahulu');
    }
    
    // PERBAIKAN 6: Enhanced query with better error handling
    $stmt = $conn->prepare("
        SELECT DISTINCT
            b.id_booking,
            b.id_user,
            b.id_ruang,
            b.tanggal,
            b.jam_mulai,
            b.jam_selesai,
            b.nama_acara,
            b.keterangan,
            b.nama as nama_penanggungjawab,
            b.no_penanggungjawab,
            b.status,
            b.booking_type,
            b.checkout_status,
            b.checkout_time,
            b.checked_out_by,
            b.completion_note,
            b.created_at,
            b.nama_dosen,
            b.nik_dosen,
            b.email_dosen,
            u.email as user_email,
            u.role as user_role,
            u.nama as user_nama,
            r.nama_ruang,
            r.kapasitas,
            r.lokasi,
            r.fasilitas,
            g.nama_gedung,
            rs.id_schedule,
            rs.nama_matakuliah,
            rs.kelas,
            rs.dosen_pengampu,
            rs.semester,
            rs.tahun_akademik,
            CASE 
                WHEN b.booking_type = 'recurring' AND rs.nama_matakuliah IS NOT NULL 
                THEN rs.nama_matakuliah
                ELSE b.nama_acara
            END as display_name
        FROM tbl_booking b 
        LEFT JOIN tbl_users u ON b.id_user = u.id_user 
        LEFT JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        LEFT JOIN tbl_recurring_schedules rs ON (b.id_schedule = rs.id_schedule AND b.booking_type = 'recurring')
        WHERE b.id_booking = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        handleError('Database query preparation failed: ' . $conn->errorInfo()[2]);
    }
    
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        handleError('Booking dengan ID ' . $bookingId . ' tidak ditemukan');
    }
    
    // PERBAIKAN 7: Get current user info for permission checking
    $currentUserId = $_SESSION['user_id'] ?? 0;
    $userRole = $_SESSION['role'] ?? '';
    $isOwner = ($booking['id_user'] == $currentUserId);
    $isAdmin = in_array($userRole, ['admin', 'cs']);
    
    // PERBAIKAN 8: Calculate duration safely
    $duration = 'N/A';
    try {
        if ($booking['jam_mulai'] && $booking['jam_selesai']) {
            $startTime = new DateTime($booking['jam_mulai']);
            $endTime = new DateTime($booking['jam_selesai']);
            $diff = $startTime->diff($endTime);
            
            $durationText = '';
            if ($diff->h > 0) {
                $durationText .= $diff->h . ' jam ';
            }
            if ($diff->i > 0) {
                $durationText .= $diff->i . ' menit';
            }
            $duration = trim($durationText) ?: '0 menit';
        }
    } catch (Exception $e) {
        error_log('Duration calculation error: ' . $e->getMessage());
    }
    
    // PERBAIKAN 9: Format date safely
    $formattedDate = $booking['tanggal'];
    try {
        if ($booking['tanggal']) {
            $dateObj = new DateTime($booking['tanggal']);
            $formattedDate = $dateObj->format('l, d F Y');
        }
    } catch (Exception $e) {
        error_log('Date formatting error: ' . $e->getMessage());
    }
    
    // PERBAIKAN 10: Get status information
    function getBookingStatusInfo($status) {
        $statusMap = [
            'pending' => ['label' => 'Menunggu Persetujuan', 'class' => 'warning', 'icon' => 'clock'],
            'approve' => ['label' => 'Disetujui', 'class' => 'success', 'icon' => 'check'],
            'active' => ['label' => 'Sedang Berlangsung', 'class' => 'danger', 'icon' => 'play'],
            'done' => ['label' => 'Selesai', 'class' => 'info', 'icon' => 'check-circle'],
            'cancelled' => ['label' => 'Dibatalkan', 'class' => 'secondary', 'icon' => 'times'],
            'rejected' => ['label' => 'Ditolak', 'class' => 'secondary', 'icon' => 'times-circle']
        ];
        
        return $statusMap[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary', 'icon' => 'question'];
    }
    
    $statusInfo = getBookingStatusInfo($booking['status']);
    
    // PERBAIKAN 11: Determine available actions
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    $bookingDate = $booking['tanggal'];
    $bookingStartTime = $booking['jam_mulai'];
    
    $availableActions = [];
    
    // Check if user can activate booking
    if ($booking['status'] === 'approve' && ($isOwner || $isAdmin)) {
        if ($bookingDate === $currentDate) {
            $timeDiff = strtotime($bookingStartTime) - strtotime($currentTime);
            // Can activate 30 minutes before start time
            if ($timeDiff <= 1800 && $timeDiff >= -300) { // 30 min before to 5 min after
                $availableActions[] = 'activate';
            }
        }
    }
    
    // Check if user can cancel booking
    if (in_array($booking['status'], ['pending', 'approve']) && ($isOwner || $isAdmin)) {
        $bookingDateTime = new DateTime($bookingDate . ' ' . $bookingStartTime);
        if ($currentDateTime < $bookingDateTime || $isAdmin) {
            $availableActions[] = 'cancel';
        }
    }
    
    // Check if user can checkout booking
    if ($booking['status'] === 'active' && ($isOwner || $isAdmin)) {
        $availableActions[] = 'checkout';
    }
    
    // PERBAIKAN 12: Get checkout information if available
    $checkoutInfo = null;
    if (!empty($booking['checkout_time'])) {
        $checkoutInfo = [
            'checkout_time' => $booking['checkout_time'],
            'formatted_checkout_time' => date('d/m/Y H:i:s', strtotime($booking['checkout_time'])),
            'checked_out_by' => $booking['checked_out_by'],
            'checkout_status' => $booking['checkout_status'],
            'completion_note' => $booking['completion_note']
        ];
    }
    
    // PERBAIKAN 13: Process facilities safely
    $facilities = [];
    if (!empty($booking['fasilitas'])) {
        try {
            $facilitiesData = json_decode($booking['fasilitas'], true);
            if (is_array($facilitiesData)) {
                $facilities = $facilitiesData;
            } else {
                // Fallback for non-JSON format
                $facilities = explode(',', trim($booking['fasilitas'], '[]"'));
                $facilities = array_map('trim', $facilities);
                $facilities = array_filter($facilities); // Remove empty values
            }
        } catch (Exception $e) {
            error_log('Facilities processing error: ' . $e->getMessage());
        }
    }
    
    // PERBAIKAN 14: Prepare comprehensive response
    $response = [
        'success' => true,
        'booking' => [
            'id_booking' => $booking['id_booking'],
            'nama_acara' => $booking['display_name'],
            'tanggal' => $booking['tanggal'],
            'formatted_date' => $formattedDate,
            'jam_mulai' => $booking['jam_mulai'],
            'jam_selesai' => $booking['jam_selesai'],
            'duration' => $duration,
            'keterangan' => $booking['keterangan'] ?? '',
            'nama_penanggungjawab' => $booking['nama_dosen'] ?: $booking['nama_penanggungjawab'],
            'no_penanggungjawab' => $booking['no_penanggungjawab'],
            'status' => $booking['status'],
            'status_info' => $statusInfo,
            'booking_type' => $booking['booking_type'],
            'nama_ruang' => $booking['nama_ruang'],
            'nama_gedung' => $booking['nama_gedung'] ?? 'Tidak diketahui',
            'kapasitas' => $booking['kapasitas'] ?? 'Tidak diketahui',
            'lokasi' => $booking['lokasi'] ?? 'Tidak diketahui',
            'facilities' => $facilities,
            'created_at' => $booking['created_at'],
            'formatted_created_at' => date('d/m/Y H:i', strtotime($booking['created_at']))
        ],
        'checkout_info' => $checkoutInfo,
        'user_permissions' => [
            'is_owner' => $isOwner,
            'is_admin' => $isAdmin,
            'current_user_id' => $currentUserId,
            'user_role' => $userRole
        ],
        'available_actions' => $availableActions,
        'status_badge' => getBookingStatusBadge($booking['status'])
    ];
    
    // Add academic schedule info if applicable
    if ($booking['booking_type'] === 'recurring') {
        $response['academic_info'] = [
            'nama_matakuliah' => $booking['nama_matakuliah'],
            'kelas' => $booking['kelas'],
            'dosen_pengampu' => $booking['dosen_pengampu'],
            'semester' => $booking['semester'],
            'tahun_akademik' => $booking['tahun_akademik']
        ];
    }
    
    // PERBAIKAN 15: Send successful response
    sendJsonResponse($response);
    
} catch (PDOException $e) {
    handleError('Database error: ' . $e->getMessage(), [
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Exception $e) {
    handleError('System error: ' . $e->getMessage(), [
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

// Helper functions
function getBookingStatusBadge($status) {
    $info = getBookingStatusInfo($status);
    return "<span class='badge bg-{$info['class']}'><i class='fas fa-{$info['icon']} me-1'></i>{$info['label']}</span>";
}

function getBookingStatusInfo($status) {
    $statusMap = [
        'pending' => ['label' => 'Menunggu Persetujuan', 'class' => 'warning', 'icon' => 'clock'],
        'approve' => ['label' => 'Disetujui', 'class' => 'success', 'icon' => 'check'],
        'active' => ['label' => 'Sedang Berlangsung', 'class' => 'danger', 'icon' => 'play'],
        'done' => ['label' => 'Selesai', 'class' => 'info', 'icon' => 'check-circle'],
        'cancelled' => ['label' => 'Dibatalkan', 'class' => 'secondary', 'icon' => 'times'],
        'rejected' => ['label' => 'Ditolak', 'class' => 'secondary', 'icon' => 'times-circle']
    ];
    
    return $statusMap[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary', 'icon' => 'question'];
}
?>