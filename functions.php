<?php

// Fungsi untuk mengecek status login
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Mendapatkan role user dengan pengecekan keamanan
function getUserRole() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : '';
}

// Fungsi untuk mengecek apakah user memiliki role tertentu
function hasRole($role) {
    return getUserRole() === $role;
}

// Mengecek untuk multiple roles
function hasAnyRole($roles) {
    $userRole = getUserRole();
    return in_array($userRole, $roles);
}

// Fungsi untuk mengecek apakah user adalah admin
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Fungsi untuk mengecek apakah user adalah CS
function isCS() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'cs';
}

// Check if user is dosen
function isDosen() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'dosen';
}

// MISSING FUNCTIONS - Added here
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ];
}

// Format date function
function formatDate($date, $format = 'd F Y') {
    $months = [
        'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
        'April' => 'April', 'May' => 'Mei', 'June' => 'Juni',
        'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September',
        'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
    ];
    
    $days = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    
    $formatted = date($format, strtotime($date));
    
    foreach ($months as $en => $id) {
        $formatted = str_replace($en, $id, $formatted);
    }
    
    foreach ($days as $en => $id) {
        $formatted = str_replace($en, $id, $formatted);
    }
    
    return $formatted;
}

// Format time function  
function formatTime($time) {
    if (empty($time)) return '-';
    
    if ($time instanceof DateTime) {
        return $time->format('H:i');
    }
    
    try {
        $timeObj = new DateTime($time);
        return $timeObj->format('H:i');
    } catch (Exception $e) {
        return $time; // Return original if parsing fails
    }
}

/**
 * Enhanced getBookingsForCalendar compatible dengan database
 */
function getBookingsForCalendar($conn, $roomId, $startDate, $endDate) {
    try {
        // Get all bookings (both regular and recurring)
        $stmt = $conn->prepare("
            SELECT b.*, u.email, u.role,
                   r.nama_ruang, r.kapasitas, g.nama_gedung, r.lokasi,
                   CASE 
                       WHEN b.booking_type = 'recurring' THEN rs.nama_matakuliah
                       ELSE b.nama_acara
                   END as display_name,
                   CASE 
                       WHEN b.booking_type = 'recurring' THEN CONCAT(rs.nama_matakuliah, ' (', rs.kelas, ')')
                       ELSE b.nama_acara
                   END as full_name,
                   b.booking_type,
                   rs.nama_matakuliah,
                   rs.kelas,
                   rs.dosen_pengampu,
                   rs.semester,
                   rs.tahun_akademik,
                   rs.hari
            FROM tbl_booking b 
            JOIN tbl_users u ON b.id_user = u.id_user 
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
            WHERE b.id_ruang = ? 
            AND b.tanggal BETWEEN ? AND ?
            AND b.status NOT IN ('cancelled', 'rejected')
            ORDER BY b.tanggal, b.jam_mulai
        ");
        $stmt->execute([$roomId, $startDate, $endDate]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add display properties for calendar
        foreach ($bookings as &$booking) {
            if ($booking['booking_type'] === 'recurring') {
                $booking['display_class'] = 'bg-info text-white'; // Blue for academic
                $booking['display_icon'] = '📚';
                $booking['is_academic'] = true;
                $booking['tooltip'] = "Perkuliahan: {$booking['nama_matakuliah']} - {$booking['kelas']}\nDosen: {$booking['dosen_pengampu']}";
            } else {
                $booking['display_class'] = getStatusColor($booking['status']);
                $booking['display_icon'] = getStatusIcon($booking['status']);
                $booking['is_academic'] = false;
                $booking['tooltip'] = "Acara: {$booking['nama_acara']}\nPIC: {$booking['nama_penanggungjawab']}";
            }
        }
        
        return $bookings;
        
    } catch (Exception $e) {
        error_log("Error in getBookingsForCalendar: " . $e->getMessage());
        return [];
    }
}

function autoApproveDosenBooking($conn, $bookingId, $userRole) {
    if ($userRole === 'dosen') {
        try {
            $stmt = $conn->prepare("UPDATE tbl_booking 
                                   SET status = 'approve', 
                                       auto_approved = 1, 
                                       approved_at = NOW(), 
                                       approved_by = 'SYSTEM_AUTO',
                                       auto_approval_reason = 'Auto-approved: Dosen booking'
                                   WHERE id_booking = ?");
            $stmt->execute([$bookingId]);
            return true;
        } catch (Exception $e) {
            error_log("Auto-approval error: " . $e->getMessage());
            return false;
        }
    }
    return false;
}


// Get booking by ID
function getBookingById($conn, $bookingId) {
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
            g.nama_gedung
        FROM tbl_booking b 
        LEFT JOIN tbl_users u ON b.id_user = u.id_user 
        LEFT JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        WHERE b.id_booking = ?
        LIMIT 1
    ");
    
    $stmt->execute([$bookingId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get room by ID
function getRoomById($conn, $roomId) {
    try {
        $stmt = $conn->prepare("SELECT r.*, g.nama_gedung 
                               FROM tbl_ruang r 
                               JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                               WHERE r.id_ruang = ?");
        $stmt->execute([$roomId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

// Get user profile
function getUserProfile($conn, $userId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE id_user = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}
//
function isDateHoliday($conn, $date) {
    // Check manual holidays
    $stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
    $stmt->execute([$date]);
    $holiday = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($holiday) {
        return $holiday;
    }
    
    // Check weekends (Saturday = 5, Sunday = 6)
    $dayOfWeek = date('w', strtotime($date));
    if ($dayOfWeek == 5 || $dayOfWeek == 6) {
        return [
            'tanggal' => $date,
            'keterangan' => $dayOfWeek == 0 ? 'Hari Minggu' : 'Hari Sabtu',
            'is_weekend' => true
        ];
    }
    
    return false;
}

// Check if date is holiday
function isHoliday($conn, $date) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_harilibur WHERE tanggal = ?");
        $stmt->execute([$date]);
        $isManualHoliday = $stmt->fetchColumn() > 0;
        
        // Also check for weekends
        $dayOfWeek = date('w', strtotime($date));
        $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6); // Sunday = 0, Saturday = 6
        
        return $isManualHoliday || $isWeekend;
        
    } catch (Exception $e) {
        error_log("Error checking holiday: " . $e->getMessage());
        return false;
    }
}

// Validasi booking
function validateBookingDate($conn, $date) {
    $errors = [];
    
    // Check if date is in the past
    if ($date < date('Y-m-d')) {
        $errors[] = 'Tidak dapat melakukan booking untuk tanggal yang sudah berlalu.';
    }
    
    // Check if date is beyond 1 month limit
    $maxDate = date('Y-m-d', strtotime('+1 month'));
    if ($date > $maxDate) {
        $errors[] = 'Booking hanya dapat dilakukan maksimal 1 bulan ke depan (' . formatDate($maxDate) . ').';
    }
    
    // Check if date is holiday
    $holiday = isDateHoliday($conn, $date);
    if ($holiday) {
        $errors[] = 'Tidak dapat melakukan booking pada hari libur: ' . $holiday['keterangan'];
    }
    
    return $errors;
}

// Validate booking duration
function isValidBookingDuration($startTime, $endTime, $minHours, $maxHours) {
    try {
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $diff = $end->diff($start);
        
        $totalHours = $diff->h + ($diff->i / 60);
        
        return $totalHours >= $minHours && $totalHours <= $maxHours;
    } catch (Exception $e) {
        return false;
    }
}

// Check if time is within business hours
function isWithinBusinessHours($time, $startHour, $endHour) {
    try {
        $timeObj = new DateTime($time);
        $hour = (int)$timeObj->format('H');
        
        return $hour >= $startHour && $hour <= $endHour;
    } catch (Exception $e) {
        return false;
    }
}

// Fungsi untuk mengecek apakah user memiliki akses ke ruangan
function canAccessRoom($conn, $userId, $roomId) {
    // Get user role
    $userProfile = getUserProfile($conn, $userId);
    if (!$userProfile) return false;
    
    $userRole = $userProfile['role'];
    
    // Admin always can access
    if ($userRole === 'admin') return true;
    
    // Get room allowed roles
    $stmt = $conn->prepare("SELECT allowed_roles FROM tbl_ruang WHERE id_ruang = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    
    if (!$room) return false;
    
    $allowedRoles = explode(',', $room['allowed_roles']);
    return in_array($userRole, $allowedRoles);
}

// Fungsi untuk mengecek apakah ruangan terkunci
function isRoomLocked($conn, $roomId, $date) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_room_locks 
                               WHERE id_ruang = ? AND ? BETWEEN start_date AND end_date");
        $stmt->execute([$roomId, $date]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Fungsi untuk mendapatkan info lock ruangan
function getRoomLockInfo($conn, $roomId, $date) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tbl_room_locks 
                               WHERE id_ruang = ? AND ? BETWEEN start_date AND end_date
                               ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$roomId, $date]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

// Fungsi untuk mencari ruangan kosong
function findAvailableRooms($conn, $date, $startTime, $endTime, $userRole = null) {
    try {
        $sql = "SELECT r.*, g.nama_gedung,
                       CASE 
                           WHEN rl.id IS NOT NULL THEN 'locked'
                           WHEN b.id_booking IS NOT NULL THEN 'booked'
                           ELSE 'available'
                       END as availability_status,
                       rl.reason as lock_reason
                FROM tbl_ruang r
                JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                LEFT JOIN tbl_room_locks rl ON (r.id_ruang = rl.id_ruang AND ? BETWEEN rl.start_date AND rl.end_date)
                LEFT JOIN tbl_booking b ON (r.id_ruang = b.id_ruang AND b.tanggal = ? 
                                           AND b.status IN ('pending', 'approve', 'active')
                                           AND ((b.jam_mulai <= ? AND b.jam_selesai > ?) 
                                               OR (b.jam_mulai < ? AND b.jam_selesai >= ?) 
                                               OR (b.jam_mulai >= ? AND b.jam_selesai <= ?)))
                ORDER BY g.nama_gedung, r.nama_ruang";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$date, $date, $endTime, $startTime, $endTime, $startTime, $startTime, $endTime]);
        $rooms = $stmt->fetchAll();
        
        // Filter by role if specified
        if ($userRole && $userRole !== 'admin') {
            $rooms = array_filter($rooms, function($room) use ($userRole) {
                if (!isset($room['allowed_roles'])) return true; // If no restriction, allow all
                $allowedRoles = explode(',', $room['allowed_roles']);
                return in_array($userRole, $allowedRoles);
            });
        }
        
        return $rooms;
    } catch (PDOException $e) {
        return [];
    }
}

// Fungsi untuk user mengaktifkan booking sendiri
function userActivateBooking($conn, $bookingId, $userId) {
    // Get booking details
    $booking = getBookingById($conn, $bookingId);
    
    if (!$booking || $booking['id_user'] != $userId) {
        return ['success' => false, 'message' => 'Booking tidak ditemukan atau bukan milik Anda'];
    }
    
    if ($booking['status'] !== 'pending') {
        return ['success' => false, 'message' => 'Booking tidak dalam status pending'];
    }
    
    // Check if current time is within booking time
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    
    if ($booking['tanggal'] != $currentDate) {
        return ['success' => false, 'message' => 'Hanya bisa mengaktifkan booking pada hari yang sama'];
    }
    
    $bookingStart = $booking['jam_mulai'];
    $bookingEnd = $booking['jam_selesai'];
    
    // Allow activation 15 minutes before start time
    $allowedStartTime = date('H:i:s', strtotime($bookingStart . ' -15 minutes'));
    
    if ($currentTime < $allowedStartTime || $currentTime > $bookingEnd) {
        return ['success' => false, 'message' => 'Booking hanya bisa diaktifkan 15 menit sebelum jadwal dimulai'];
    }
    
    // Check for conflicts
    if (hasBookingConflict($conn, $booking['id_ruang'], $booking['tanggal'], 
                         $booking['jam_mulai'], $booking['jam_selesai'], $bookingId)) {
        return ['success' => false, 'message' => 'Terdapat konflik dengan booking lain'];
    }
    
    // Activate booking
    try {
        $stmt = $conn->prepare("UPDATE tbl_booking 
                               SET status = 'active', 
                                   activated_by_user = 1,
                                   user_can_activate = 1
                               WHERE id_booking = ?");
        
        if ($stmt->execute([$bookingId])) {
            return ['success' => true, 'message' => 'Booking berhasil diaktifkan'];
        } else {
            return ['success' => false, 'message' => 'Gagal mengaktifkan booking'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateBookingStatusToUsed($bookingId) {
    // Cek apakah booking sudah lewat waktu persetujuan
    $stmt = $pdo->prepare("SELECT status, booking_date FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if ($booking['status'] == 'Pending' && new DateTime() > new DateTime($booking['booking_date'])) {
        // Update status jadi "Used"
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'Used' WHERE id = ?");
        $stmt->execute([$bookingId]);
    }
}

// Fungsi untuk export data ke PDF (placeholder)
function exportBookingsToPDF($conn, $filters = []) {
    // This would require a PDF library like TCPDF or FPDF
    // For now, return the data that would be exported
    
    try {
        $sql = "SELECT b.*, u.email, r.nama_ruang, g.nama_gedung
                FROM tbl_booking b 
                JOIN tbl_users u ON b.id_user = u.id_user 
                JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
                JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND b.tanggal >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND b.tanggal <= ?";
            $params[] = $filters['end_date'];
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND b.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['room_id'])) {
            $sql .= " AND b.id_ruang = ?";
            $params[] = $filters['room_id'];
        }
        
        $sql .= " ORDER BY b.tanggal DESC, b.jam_mulai ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// Update fungsi hasBookingConflict untuk support lock
function hasBookingConflictWithLock($conn, $roomId, $date, $startTime, $endTime, $excludeBookingId = null) {
    // First check if room is locked
    if (isRoomLocked($conn, $roomId, $date)) {
        return true;
    }
    
    // Then check booking conflicts
    return hasBookingConflict($conn, $roomId, $date, $startTime, $endTime, $excludeBookingId);
}

// Fungsi untuk mendapatkan fasilitas ruangan
function getRoomFacilities($conn, $roomId) {
    try {
        $stmt = $conn->prepare("SELECT fasilitas FROM tbl_ruang WHERE id_ruang = ?");
        $stmt->execute([$roomId]);
        $result = $stmt->fetchColumn();
        
        if ($result) {
            $facilities = json_decode($result, true);
            return is_array($facilities) ? $facilities : [];
        }
        
        return [];
    } catch (PDOException $e) {
        return [];
    }
}

// Check booking conflicts
// Check booking conflicts - FIXED VERSION
function hasBookingConflict($conn, $roomId, $date, $startTime, $endTime, $excludeBookingId = null) {
    $sql = "SELECT COUNT(*) FROM tbl_booking 
            WHERE id_ruang = ? AND tanggal = ? 
            AND status IN ('pending', 'approve', 'active')
            AND (
                (jam_mulai < ? AND jam_selesai > ?) OR
                (jam_mulai < ? AND jam_selesai > ?) OR
                (jam_mulai >= ? AND jam_selesai <= ?)
            )";
    
    // FIXED: Parameter array yang benar
    $params = [$roomId, $date, $endTime, $startTime, $startTime, $endTime, $startTime, $endTime];
    
    if ($excludeBookingId) {
        $sql .= " AND id_booking != ?";
        $params[] = $excludeBookingId;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
}

// Function to get booking status badge HTML
function getBookingStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge bg-warning text-dark">📋 PENDING</span>',
        'approve' => '<span class="badge bg-success">✅ APPROVED</span>',
        'active' => '<span class="badge bg-danger">🔴 ONGOING</span>',
        'done' => '<span class="badge bg-info">✅ SELESAI</span>',
        'cancelled' => '<span class="badge bg-secondary">❌ DIBATALKAN</span>',
        'rejected' => '<span class="badge bg-secondary">❌ DITOLAK</span>'
    ];
    
    return $badges[$status] ?? '<span class="badge bg-secondary">❓ UNKNOWN</span>';
}

// Function to determine booking action buttons
function getBookingActionButtons($booking, $currentUserId, $userRole = null) {
    $buttons = [];
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    
    $bookingDate = $booking['tanggal'];
    $bookingStartTime = $booking['jam_mulai'];
    $bookingEndTime = $booking['jam_selesai'];
    
    // Check if booking time has passed
    $isBookingTimeExpired = false;
    if ($bookingDate < $currentDate) {
        $isBookingTimeExpired = true;
    } elseif ($bookingDate === $currentDate && $currentTime > $bookingEndTime) {
        $isBookingTimeExpired = true;
    }
    
    // Check if booking is currently active (within booking time)
    $isBookingCurrentlyActive = false;
    if ($bookingDate === $currentDate && $currentTime >= $bookingStartTime && $currentTime <= $bookingEndTime) {
        $isBookingCurrentlyActive = true;
    }
    
    switch ($booking['status']) {
        case 'pending':
            if ($booking['id_user'] == $currentUserId && !$isBookingTimeExpired) {
                $buttons[] = [
                    'type' => 'cancel',
                    'class' => 'btn btn-sm btn-danger',
                    'text' => '<i class="fas fa-times"></i> Batalkan',
                    'onclick' => "cancelBooking({$booking['id_booking']})"
                ];
            }
            if ($userRole === 'admin') {
                $buttons[] = [
                    'type' => 'approve',
                    'class' => 'btn btn-sm btn-success',
                    'text' => '<i class="fas fa-check"></i> Setujui',
                    'onclick' => "approveBooking({$booking['id_booking']})"
                ];
                $buttons[] = [
                    'type' => 'reject',
                    'class' => 'btn btn-sm btn-danger',
                    'text' => '<i class="fas fa-times"></i> Tolak',
                    'onclick' => "rejectBooking({$booking['id_booking']})"
                ];
            }
            break;
            
        case 'approve':
            if ($booking['id_user'] == $currentUserId) {
                if ($isBookingCurrentlyActive) {
                    // User can activate their own booking during booking time
                    $buttons[] = [
                        'type' => 'activate',
                        'class' => 'btn btn-sm btn-success',
                        'text' => '<i class="fas fa-play"></i> Aktifkan',
                        'onclick' => "activateBooking({$booking['id_booking']})"
                    ];
                } elseif (!$isBookingTimeExpired) {
                    $buttons[] = [
                        'type' => 'cancel',
                        'class' => 'btn btn-sm btn-danger',
                        'text' => '<i class="fas fa-times"></i> Batalkan',
                        'onclick' => "cancelBooking({$booking['id_booking']})"
                    ];
                }
            }
            if ($userRole === 'admin') {
                $buttons[] = [
                    'type' => 'cancel',
                    'class' => 'btn btn-sm btn-danger',
                    'text' => '<i class="fas fa-ban"></i> Batalkan',
                    'onclick' => "adminCancelBooking({$booking['id_booking']})"
                ];
            }
            break;
            
        case 'active':
            if ($booking['id_user'] == $currentUserId && ($isBookingCurrentlyActive || $isBookingTimeExpired)) {
                $buttons[] = [
                    'type' => 'checkout',
                    'class' => 'btn btn-sm btn-info checkout-btn',
                    'text' => '<i class="fas fa-sign-out-alt"></i> Checkout',
                    'onclick' => "showCheckoutModal({$booking['id_booking']})"
                ];
            }
            if ($userRole === 'admin') {
                $buttons[] = [
                    'type' => 'force_checkout',
                    'class' => 'btn btn-sm btn-warning',
                    'text' => '<i class="fas fa-sign-out-alt"></i> Force Checkout',
                    'onclick' => "forceCheckoutBooking({$booking['id_booking']})"
                ];
            }
            break;
            
        default:
            // For completed, rejected, cancelled bookings - no action buttons for regular users
            break;
    }
    
    return $buttons;
}

// Function to check if user can perform specific action on booking
function canUserPerformAction($booking, $action, $currentUserId, $userRole = null) {
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    
    $bookingDate = $booking['tanggal'];
    $bookingStartTime = $booking['jam_mulai'];
    $bookingEndTime = $booking['jam_selesai'];
    
    // Check if booking time has passed
    $isBookingTimeExpired = false;
    if ($bookingDate < $currentDate) {
        $isBookingTimeExpired = true;
    } elseif ($bookingDate === $currentDate && $currentTime > $bookingEndTime) {
        $isBookingTimeExpired = true;
    }
    
    // Check if booking is currently active (within booking time)
    $isBookingCurrentlyActive = false;
    if ($bookingDate === $currentDate && $currentTime >= $bookingStartTime && $currentTime <= $bookingEndTime) {
        $isBookingCurrentlyActive = true;
    }
    
    switch ($action) {
        case 'cancel':
            return ($booking['id_user'] == $currentUserId || $userRole === 'admin') && 
                   in_array($booking['status'], ['pending', 'approve']) && 
                   !$isBookingTimeExpired;
                   
        case 'checkout':
            return $booking['id_user'] == $currentUserId && 
                   $booking['status'] === 'active' && 
                   ($isBookingCurrentlyActive || $isBookingTimeExpired);
                   
        case 'activate':
            return $booking['id_user'] == $currentUserId && 
                   $booking['status'] === 'approve' && 
                   $isBookingCurrentlyActive;
                   
        case 'approve':
        case 'reject':
            return $userRole === 'admin' && $booking['status'] === 'pending';
            
        default:
            return false;
    }
}

// Enhanced Checkout and Cancellation System Functions
// Add these functions to your existing functions.php

/**
 * Enhanced checkout booking with detailed information
 */
function enhancedCheckoutBooking($conn, $bookingId, $checkoutBy = 'USER_MANUAL', $note = null) {
    try {
        // Get booking details
        $booking = getBookingById($conn, $bookingId);
        if (!$booking) {
            return [
                'success' => false,
                'message' => 'Booking tidak ditemukan'
            ];
        }
        
        // Validate current status
        if ($booking['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'Hanya booking dengan status active yang bisa di-checkout'
            ];
        }
        
        $currentDateTime = date('Y-m-d H:i:s');
        $checkoutNote = $note ?: generateCheckoutNote($booking, $checkoutBy);
        
        // Determine checkout status
        $checkoutStatus = 'manual_checkout';
        switch ($checkoutBy) {
            case 'SYSTEM_AUTO':
                $checkoutStatus = 'auto_completed';
                break;
            case 'ADMIN_FORCE':
                $checkoutStatus = 'force_checkout';
                break;
            default:
                $checkoutStatus = 'manual_checkout';
        }
        
        // Update booking status
        $stmt = $conn->prepare("
            UPDATE tbl_booking 
            SET status = 'done',
                checkout_status = ?,
                checkout_time = ?,
                checked_out_by = ?,
                completion_note = ?
            WHERE id_booking = ?
        ");
        
        $result = $stmt->execute([
            $checkoutStatus,
            $currentDateTime,
            $checkoutBy,
            $checkoutNote,
            $bookingId
        ]);
        
        if ($result) {
            // Send notification
            $notificationData = array_merge($booking, [
                'checkout_time' => $currentDateTime,
                'checkout_status' => $checkoutStatus,
                'checked_out_by' => $checkoutBy,
                'completion_note' => $checkoutNote
            ]);
            
            sendBookingNotification($booking['email'], $notificationData, 'checkout_confirmation');
            
            // Log the checkout
            error_log("CHECKOUT SUCCESS: Booking ID {$bookingId} checked out by {$checkoutBy}");
            
            return [
                'success' => true,
                'message' => getCheckoutSuccessMessage($checkoutBy),
                'checkout_info' => [
                    'checkout_time' => $currentDateTime,
                    'checkout_by' => $checkoutBy,
                    'checkout_status' => $checkoutStatus,
                    'note' => $checkoutNote
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Gagal melakukan checkout'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Checkout error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Terjadi kesalahan saat checkout: ' . $e->getMessage()
        ];
    }
}

/**
 * Generate appropriate checkout note based on checkout type
 */
function generateCheckoutNote($booking, $checkoutBy) {
    switch ($checkoutBy) {
        case 'USER_MANUAL':
            return 'Ruangan sudah selesai dipakai dengan checkout mahasiswa';
        case 'SYSTEM_AUTO':
            return 'Ruangan selesai dipakai tanpa checkout manual dari mahasiswa';
        case 'ADMIN_FORCE':
            return 'Admin melakukan force checkout ruangan';
        default:
            return 'Ruangan telah selesai digunakan';
    }
}

/**
 * Get appropriate success message for checkout
 */
function getCheckoutSuccessMessage($checkoutBy) {
    switch ($checkoutBy) {
        case 'USER_MANUAL':
            return 'Checkout berhasil! Ruangan sudah selesai dipakai dengan checkout mahasiswa. Slot waktu kini tersedia untuk user lain.';
        case 'SYSTEM_AUTO':
            return 'Auto-checkout completed! Ruangan telah otomatis di-checkout oleh sistem.';
        case 'ADMIN_FORCE':
            return 'Force checkout berhasil! Admin telah memaksa checkout ruangan.';
        default:
            return 'Checkout berhasil! Ruangan kini tersedia untuk user lain.';
    }
}

/**
 * Enhanced cancellation system with slot availability notification
 */
function enhancedCancelBooking($conn, $bookingId, $cancelledBy, $reason = null) {
    try {
        // Get booking details before cancellation
        $booking = getBookingById($conn, $bookingId);
        if (!$booking) {
            return [
                'success' => false,
                'message' => 'Booking tidak ditemukan'
            ];
        }
        
        // Check if booking can be cancelled
        if (!in_array($booking['status'], ['pending', 'approve'])) {
            return [
                'success' => false,
                'message' => 'Booking dengan status ' . $booking['status'] . ' tidak dapat dibatalkan'
            ];
        }
        
        $currentDateTime = date('Y-m-d H:i:s');
        $cancellationReason = $reason ?: 'Dibatalkan oleh ' . $cancelledBy;
        
        // Update booking status
        $stmt = $conn->prepare("
            UPDATE tbl_booking 
            SET status = 'cancelled',
                cancelled_by = ?,
                cancelled_at = ?,
                cancellation_reason = ?
            WHERE id_booking = ?
        ");
        
        $result = $stmt->execute([
            $cancelledBy,
            $currentDateTime,
            $cancellationReason,
            $bookingId
        ]);
        
        if ($result) {
            // Send cancellation notification to original booker
            $cancellationData = array_merge($booking, [
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => $currentDateTime,
                'cancellation_reason' => $cancellationReason
            ]);
            
            sendBookingNotification($booking['email'], $cancellationData, 'cancellation');
            
            // Notify potential users about available slot
            notifySlotAvailability($conn, $booking);
            
            // Log the cancellation
            error_log("CANCELLATION SUCCESS: Booking ID {$bookingId} cancelled by {$cancelledBy}");
            error_log("SLOT AVAILABLE: Room {$booking['nama_ruang']} on {$booking['tanggal']} {$booking['jam_mulai']}-{$booking['jam_selesai']}");
            
            return [
                'success' => true,
                'message' => 'Booking berhasil dibatalkan. Slot waktu kini tersedia untuk user lain.',
                'slot_info' => [
                    'room_name' => $booking['nama_ruang'],
                    'date' => $booking['tanggal'],
                    'time_start' => $booking['jam_mulai'],
                    'time_end' => $booking['jam_selesai'],
                    'available_since' => $currentDateTime
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Gagal membatalkan booking'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Cancellation error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Terjadi kesalahan saat membatalkan booking: ' . $e->getMessage()
        ];
    }
}

/**
 * Notify users about newly available slot
 */
function notifySlotAvailability($conn, $booking) {
    try {
        // Send notification about slot availability
        $slotData = [
            'nama_ruang' => $booking['nama_ruang'],
            'nama_acara' => 'Slot Tersedia - ' . $booking['nama_ruang'],
            'tanggal' => $booking['tanggal'],
            'jam_mulai' => $booking['jam_mulai'],
            'jam_selesai' => $booking['jam_selesai'],
            'nama_gedung' => $booking['nama_gedung'] ?? ''
        ];
        
        // Log slot availability for admin dashboard
        error_log("SLOT NOTIFICATION: Room {$booking['nama_ruang']} available on {$booking['tanggal']} {$booking['jam_mulai']}-{$booking['jam_selesai']}");
        
        // You can extend this to send notifications to interested users
        // For example, users who have waitlisted for this room/time
        
        return true;
    } catch (Exception $e) {
        error_log("Slot notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get detailed booking status with checkout information
 */
function getDetailedBookingStatus($booking) {
    $status = $booking['status'];
    $currentTime = new DateTime();
    $bookingStart = new DateTime($booking['tanggal'] . ' ' . $booking['jam_mulai']);
    $bookingEnd = new DateTime($booking['tanggal'] . ' ' . $booking['jam_selesai']);
    
    $statusInfo = [
        'status' => $status,
        'status_text' => '',
        'status_class' => '',
        'description' => '',
        'icon' => ''
    ];
    
    switch ($status) {
        case 'pending':
            $statusInfo['status_text'] = 'Menunggu Persetujuan';
            $statusInfo['status_class'] = 'warning';
            $statusInfo['description'] = 'Booking sedang menunggu persetujuan dari admin';
            $statusInfo['icon'] = 'fas fa-clock';
            break;
            
        case 'approve':
            $statusInfo['status_text'] = 'Disetujui';
            $statusInfo['status_class'] = 'success';
            $statusInfo['description'] = 'Booking telah disetujui dan siap digunakan';
            $statusInfo['icon'] = 'fas fa-check-circle';
            break;
            
        case 'active':
            $statusInfo['status_text'] = 'Sedang Berlangsung';
            $statusInfo['status_class'] = 'danger';
            $statusInfo['description'] = 'Ruangan sedang digunakan';
            $statusInfo['icon'] = 'fas fa-play-circle';
            break;
            
        case 'done':
            $statusInfo['status_text'] = 'Selesai';
            $statusInfo['status_class'] = 'info';
            $statusInfo['description'] = 'Booking telah selesai';
            $statusInfo['icon'] = 'fas fa-check-double';
            break;
            
        case 'cancelled':
            $statusInfo['status_text'] = 'Dibatalkan';
            $statusInfo['status_class'] = 'secondary';
            $statusInfo['description'] = 'Booking telah dibatalkan';
            $statusInfo['icon'] = 'fas fa-times-circle';
            break;
            
        case 'rejected':
            $statusInfo['status_text'] = 'Ditolak';
            $statusInfo['status_class'] = 'secondary';
            $statusInfo['description'] = 'Booking ditolak oleh admin';
            $statusInfo['icon'] = 'fas fa-ban';
            break;
            
        default:
            $statusInfo['status_text'] = 'Status Tidak Dikenal';
            $statusInfo['status_class'] = 'secondary';
            $statusInfo['description'] = 'Status booking tidak dikenal';
            $statusInfo['icon'] = 'fas fa-question-circle';
    }
    
    return $statusInfo;
}

// TAMBAHAN: Helper functions untuk handle schedule exceptions

/**
 * Check apakah tanggal tertentu di-exclude dari recurring schedule
 */
function isDateExcluded($conn, $scheduleId, $date) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_schedule_exceptions 
                              WHERE id_schedule = ? AND exception_date = ? 
                              AND exception_type = 'cancelled_by_cs'");
        $stmt->execute([$scheduleId, $date]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log("Error checking date exclusion: " . $e->getMessage());
        return false;
    }
}

/**
 * Get semua tanggal yang di-exclude untuk schedule tertentu
 */
function getExcludedDates($conn, $scheduleId) {
    try {
        $stmt = $conn->prepare("SELECT exception_date, reason FROM tbl_schedule_exceptions 
                              WHERE id_schedule = ? AND exception_type = 'cancelled_by_cs' 
                              ORDER BY exception_date");
        $stmt->execute([$scheduleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting excluded dates: " . $e->getMessage());
        return [];
    }
}

/**
 * Generate booking untuk recurring schedule dengan exclude tanggal tertentu
 */
function generateRecurringBookingsWithExceptions($conn, $scheduleId, $startDate, $endDate) {
    try {
        // Get schedule info
        $stmt = $conn->prepare("SELECT * FROM tbl_recurring_schedules WHERE id_schedule = ?");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();
        
        if (!$schedule) {
            return false;
        }
        
        // Get excluded dates
        $excludedDates = getExcludedDates($conn, $scheduleId);
        $excludedDatesArray = array_column($excludedDates, 'exception_date');
        
        $generatedCount = 0;
        $currentDate = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);
        
        while ($currentDate <= $endDateTime) {
            $dateString = $currentDate->format('Y-m-d');
            $dayOfWeek = strtolower($currentDate->format('l'));
            
            // Check if this date matches the schedule day
            if ($dayOfWeek === $schedule['hari']) {
                // Check if this date is NOT excluded
                if (!in_array($dateString, $excludedDatesArray)) {
                    // Generate booking for this date
                    $stmt = $conn->prepare("INSERT IGNORE INTO tbl_booking 
                        (id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai, keterangan, 
                         nama, no_penanggungjawab, status, id_schedule, booking_type, auto_generated) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approve', ?, 'recurring', 1)");
                    
                    $stmt->execute([
                        $schedule['created_by'],
                        $schedule['id_ruang'],
                        $schedule['nama_matakuliah'] . ' - ' . $schedule['kelas'],
                        $dateString,
                        $schedule['jam_mulai'],
                        $schedule['jam_selesai'],
                        'Jadwal Perkuliahan ' . $schedule['semester'] . ' ' . $schedule['tahun_akademik'] . ' - Dosen: ' . $schedule['dosen_pengampu'],
                        $schedule['dosen_pengampu'],
                        0,
                        $schedule['id_schedule']
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $generatedCount++;
                    }
                }
            }
            
            $currentDate->modify('+1 day');
        }
        
        return $generatedCount;
        
    } catch (Exception $e) {
        error_log("Error generating recurring bookings: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean up booking yang sudah di-cancel karena exception
 */
function cleanupCancelledRecurringBookings($conn) {
    try {
        // Cancel bookings that match exception dates
        $stmt = $conn->prepare("
            UPDATE tbl_booking b
            INNER JOIN tbl_schedule_exceptions se ON (
                b.id_schedule = se.id_schedule 
                AND b.tanggal = se.exception_date 
                AND se.exception_type = 'cancelled_by_cs'
            )
            SET b.status = 'cancelled',
                b.cancelled_by = 'SYSTEM_EXCEPTION',
                b.cancelled_at = NOW(),
                b.cancellation_reason = CONCAT('Auto-cancelled due to schedule exception: ', se.reason)
            WHERE b.booking_type = 'recurring' 
            AND b.status IN ('pending', 'approve', 'active')
        ");
        
        $stmt->execute();
        $cleanedCount = $stmt->rowCount();
        
        if ($cleanedCount > 0) {
            error_log("Cleaned up $cleanedCount recurring bookings due to exceptions");
        }
        
        return $cleanedCount;
        
    } catch (Exception $e) {
        error_log("Error cleaning up cancelled bookings: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced booking notification system
 */
function sendEnhancedBookingNotification($email, $booking, $type = 'confirmation', $additionalData = []) {
    try {
        $statusInfo = getDetailedBookingStatus($booking);
        $subject = '';
        $message = '';
        
        switch ($type) {
            case 'slot_available':
                $subject = 'Slot Ruangan Tersedia - ' . $booking['nama_ruang'];
                $message = "🎉 SLOT RUANGAN TERSEDIA! 🎉\n\n";
                $message .= "Ada slot ruangan yang baru tersedia karena pembatalan:\n\n";
                $message .= "📍 Ruangan: {$booking['nama_ruang']}\n";
                $message .= "📅 Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "⏰ Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "🏢 Gedung: " . ($booking['nama_gedung'] ?? 'Tidak diketahui') . "\n\n";
                $message .= "✅ STATUS: TERSEDIA UNTUK BOOKING\n\n";
                $message .= "Segera lakukan peminjaman jika Anda memerlukan ruangan pada waktu tersebut!\n\n";
                $message .= "🔗 Login ke sistem booking: [URL_SISTEM]\n\n";
                $message .= "Terima kasih.";
                break;
                
            case 'checkout_success':
                $subject = 'Checkout Berhasil - ' . $booking['nama_acara'];
                $message = "✅ CHECKOUT BERHASIL! ✅\n\n";
                $message .= "Checkout untuk booking ruangan '{$booking['nama_acara']}' telah berhasil dilakukan.\n\n";
                $message .= "📋 DETAIL CHECKOUT:\n";
                $message .= "📍 Ruangan: " . ($booking['nama_ruang'] ?? 'Unknown') . "\n";
                $message .= "📅 Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "⏰ Waktu Booking: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "🕐 Waktu Checkout: " . (isset($booking['checkout_time']) ? date('H:i', strtotime($booking['checkout_time'])) : 'Tidak diketahui') . "\n";
                $message .= "👤 Checkout oleh: " . ($statusInfo['description'] ?? 'User') . "\n";
                $message .= "📝 Status: " . $statusInfo['display_text'] . "\n\n";
                
                if (isset($booking['completion_note'])) {
                    $message .= "📄 Catatan: {$booking['completion_note']}\n\n";
                }
                
                $message .= "🎉 SLOT TERSEDIA LAGI!\n";
                $message .= "Ruangan kini tersedia untuk user lain.\n\n";
                $message .= "Terima kasih telah menggunakan ruangan dengan baik!\n\n";
                $message .= "Terima kasih.";
                break;
                
            case 'admin_cancellation':
                $subject = 'Booking Dibatalkan oleh Admin - ' . $booking['nama_acara'];
                $message = "❌ BOOKING DIBATALKAN OLEH ADMIN ❌\n\n";
                $message .= "Booking ruangan Anda telah dibatalkan oleh administrator.\n\n";
                $message .= "📋 DETAIL BOOKING YANG DIBATALKAN:\n";
                $message .= "🎪 Nama Acara: {$booking['nama_acara']}\n";
                $message .= "📍 Ruangan: " . ($booking['nama_ruang'] ?? 'Unknown') . "\n";
                $message .= "📅 Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "⏰ Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "🏢 Gedung: " . ($booking['nama_gedung'] ?? 'Tidak diketahui') . "\n";
                
                if (isset($booking['cancellation_reason'])) {
                    $message .= "📝 Alasan: {$booking['cancellation_reason']}\n";
                }
                
                $message .= "\n🎉 INFORMASI PENTING:\n";
                $message .= "✅ Slot waktu ini sekarang TERSEDIA UNTUK USER LAIN\n";
                $message .= "✅ Anda dapat melakukan booking ulang jika masih memerlukan ruangan\n\n";
                $message .= "Jika Anda masih memerlukan ruangan pada waktu tersebut, silakan:\n";
                $message .= "1. Login ke sistem booking\n";
                $message .= "2. Pilih waktu yang sama (jika masih tersedia)\n";
                $message .= "3. Atau pilih waktu alternatif lainnya\n";
                $message .= "4. Atau hubungi admin untuk klarifikasi\n\n";
                $message .= "Terima kasih atas pengertian Anda.";
                break;
                
            default:
                // Use original notification function
                return sendBookingNotification($email, $booking, $type);
        }
        
        // Log the notification
        error_log("ENHANCED NOTIFICATION: To: $email, Subject: $subject, Type: $type");
        error_log("ENHANCED CONTENT: $message");
        
        // TODO: Implement actual email sending here
        return true;
        
    } catch (Exception $e) {
        error_log("Error in sendEnhancedBookingNotification: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate booking summary for admin dashboard
 */
function getBookingSummaryForAdmin($conn, $date = null) {
    $date = $date ?: date('Y-m-d');
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                status,
                checkout_status,
                COUNT(*) as count,
                SUM(CASE WHEN checkout_status = 'manual_checkout' THEN 1 ELSE 0 END) as manual_checkouts,
                SUM(CASE WHEN checkout_status = 'auto_completed' THEN 1 ELSE 0 END) as auto_checkouts,
                SUM(CASE WHEN checkout_status = 'force_checkout' THEN 1 ELSE 0 END) as force_checkouts
            FROM tbl_booking 
            WHERE tanggal = ?
            GROUP BY status, checkout_status
            ORDER BY status
        ");
        $stmt->execute([$date]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $summary = [
            'date' => $date,
            'total_bookings' => 0,
            'by_status' => [],
            'checkout_stats' => [
                'manual_checkout' => 0,
                'auto_completed' => 0,
                'force_checkout' => 0,
                'total_completed' => 0
            ],
            'slot_availability' => []
        ];
        
        foreach ($results as $row) {
            $summary['total_bookings'] += $row['count'];
            $summary['by_status'][$row['status']] = $row['count'];
            
            if ($row['status'] === 'done') {
                $summary['checkout_stats']['manual_checkout'] += $row['manual_checkouts'];
                $summary['checkout_stats']['auto_completed'] += $row['auto_checkouts'];
                $summary['checkout_stats']['force_checkout'] += $row['force_checkouts'];
                $summary['checkout_stats']['total_completed'] += $row['count'];
            }
        }
        
        // Calculate completion rate
        if ($summary['checkout_stats']['total_completed'] > 0) {
            $summary['checkout_stats']['manual_rate'] = round(
                ($summary['checkout_stats']['manual_checkout'] / $summary['checkout_stats']['total_completed']) * 100, 2
            );
            $summary['checkout_stats']['auto_rate'] = round(
                ($summary['checkout_stats']['auto_completed'] / $summary['checkout_stats']['total_completed']) * 100, 2
            );
        }
        
        return $summary;
        
    } catch (Exception $e) {
        error_log("Error getting booking summary: " . $e->getMessage());
        return null;
    }
}

/**
 * Check and notify about rooms that became available
 */
function checkAndNotifyAvailableRooms($conn) {
    try {
        // Get recently cancelled or completed bookings in the last hour
        $stmt = $conn->prepare("
            SELECT b.*, r.nama_ruang, g.nama_gedung
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            WHERE b.status IN ('cancelled', 'done')
            AND (b.cancelled_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) 
                 OR b.checkout_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR))
            AND b.tanggal >= CURDATE()
            ORDER BY COALESCE(b.cancelled_at, b.checkout_time) DESC
        ");
        $stmt->execute();
        $availableSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($availableSlots as $slot) {
            // Log available slot
            error_log("AVAILABLE SLOT DETECTED: Room {$slot['nama_ruang']} on {$slot['tanggal']} {$slot['jam_mulai']}-{$slot['jam_selesai']}");
            
            // You can extend this to maintain a waitlist or send notifications
            // to users who might be interested in this slot
        }
        
        return $availableSlots;
        
    } catch (Exception $e) {
        error_log("Error checking available rooms: " . $e->getMessage());
        return [];
    }
}

function getCheckoutTypeText($checkoutStatus, $checkedOutBy) {
    switch ($checkoutStatus) {
        case 'manual_checkout':
            return 'Manual Checkout oleh ' . $checkedOutBy;
        case 'auto_completed':
            return 'Auto Checkout (Sistem)';
        default:
            return 'Checkout Normal';
    }
}

/**
 * Add these helper functions for better formatting
 */
if (!function_exists('getCheckoutTypeText')) {
    function getCheckoutTypeText($checkoutStatus, $checkedOutBy) {
        switch ($checkoutStatus) {
            case 'manual_checkout':
                return [
                    'text' => 'Manual Checkout',
                    'icon' => 'fa-user-check',
                    'class' => 'text-success',
                    'description' => 'Mahasiswa melakukan checkout sendiri'
                ];
            case 'auto_completed':
                return [
                    'text' => 'Auto-Completed',
                    'icon' => 'fa-robot',
                    'class' => 'text-warning',
                    'description' => 'Sistem otomatis menyelesaikan booking'
                ];
            case 'force_checkout':
                return [
                    'text' => 'Force Checkout',
                    'icon' => 'fa-hand-paper',
                    'class' => 'text-info',
                    'description' => 'Admin memaksa checkout'
                ];
            default:
                return [
                    'text' => 'Selesai',
                    'icon' => 'fa-check',
                    'class' => 'text-muted',
                    'description' => 'Booking selesai'
                ];
        }
    }
}

function getSlotAvailabilityMessage($booking) {
    $status = $booking['status'];
    
    switch ($status) {
        case 'done':
            return 'Slot ini sudah selesai digunakan dan tersedia untuk booking baru';
        case 'cancelled':
            return 'Slot ini tersedia karena booking sebelumnya dibatalkan';
        case 'rejected':
            return 'Slot ini tersedia karena booking sebelumnya ditolak';
        default:
            return 'Status slot tidak dikenal';
    }
}

if (!function_exists('getSlotAvailabilityMessage')) {
    function getSlotAvailabilityMessage($booking) {
        $message = "🎉 <strong>SLOT TERSEDIA LAGI!</strong><br>";
        $message .= "<small class='text-success'>";
        $message .= "<i class='fas fa-check-circle me-1'></i>";
        $message .= "Ruangan {$booking['nama_ruang']} kini dapat dibooking oleh user lain";
        $message .= "</small>";
        return $message;
    }
}

// Send booking notification
// Enhanced notification function untuk sistem booking yang lebih lengkap
function sendBookingNotification($email, $booking, $type = 'confirmation') {
    try {
        $subject = '';
        $message = '';
        
        switch ($type) {
            case 'confirmation':
                $subject = 'Booking Berhasil Disubmit - ' . $booking['nama_acara'];
                $message = "Terima kasih! Booking ruangan Anda telah berhasil disubmit.\n\n";
                $message .= "Detail Booking:\n";
                $message .= "Nama Acara: {$booking['nama_acara']}\n";
                $message .= "Ruangan: {$booking['nama_ruang']}\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: PENDING (Menunggu Persetujuan Admin)\n\n";
                $message .= "Booking Anda akan disetujui dalam waktu maksimal 5 menit. Jika tidak ada respons dari admin, booking akan disetujui otomatis.\n";
                $message .= "\nTerima kasih.";
                break;
            
            case 'auto_complete':
                $subject = 'Booking Auto-Completed - ' . $booking['nama_acara'];
                $message = "Booking ruangan Anda telah diselesaikan secara otomatis.\n\n";
                $message .= "Detail Booking:\n";
                $message .= "Nama Acara: {$booking['nama_acara']}\n";
                $message .= "Ruangan: " . ($booking['nama_ruang'] ?? 'Unknown') . "\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: COMPLETED (Auto-Completed)\n";
                $message .= "Keterangan: " . ($booking['completion_note'] ?? 'Ruangan selesai dipakai tanpa checkout dari mahasiswa') . "\n\n";
                $message .= "CATATAN: Booking telah berakhir tanpa checkout manual. Untuk masa depan, mohon lakukan checkout setelah selesai menggunakan ruangan.\n";
                $message .= "\nTerima kasih.";
                break;
                
            case 'approval':
                $subject = 'Booking Disetujui - ' . $booking['nama_acara'];
                $message = "Booking ruangan Anda telah disetujui!\n\n";
                $message .= "Detail Booking:\n";
                $message .= "Nama Acara: {$booking['nama_acara']}\n";
                $message .= "Ruangan: {$booking['nama_ruang']}\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: APPROVED (Disetujui)\n\n";
                $message .= "Booking Anda akan otomatis aktif saat waktu mulai tiba. Jangan lupa untuk checkout setelah selesai menggunakan ruangan.\n";
                $message .= "\nTerima kasih.";
                break;
                
            case 'auto_approval':
                $subject = 'Booking Auto-Approved - ' . $booking['nama_acara'];
                $message = "Booking ruangan Anda telah disetujui secara otomatis!\n\n";
                $message .= "Detail Booking:\n";
                $message .= "Nama Acara: {$booking['nama_acara']}\n";
                $message .= "Ruangan: {$booking['nama_ruang']}\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: APPROVED (Auto-Approved)\n";
                $message .= "Alasan: " . ($booking['approval_reason'] ?? 'Tidak ada respons admin dalam 5 menit') . "\n\n";
                $message .= "Booking Anda akan otomatis aktif saat waktu mulai tiba. Jangan lupa untuk checkout setelah selesai menggunakan ruangan.\n";
                $message .= "\nTerima kasih.";
                break;
                
            case 'activation':
                $subject = 'Booking Diaktifkan - ' . $booking['nama_acara'];
                $message = "Booking ruangan Anda telah diaktifkan!\n\n";
                $message .= "Detail Booking:\n";
                $message .= "Nama Acara: {$booking['nama_acara']}\n";
                $message .= "Ruangan: {$booking['nama_ruang']}\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: ACTIVE (Sedang Berlangsung)\n\n";
                $message .= "Selamat menggunakan ruangan! Jangan lupa untuk checkout setelah selesai.\n";
                $message .= "\nTerima kasih.";
                break;
                
            case 'rejection':
                $subject = 'Booking Ditolak - ' . $booking['nama_acara'];
                $message = "Maaf, booking ruangan Anda telah ditolak.\n\n";
                $message .= "Detail Booking:\n";
                $message .= "Nama Acara: {$booking['nama_acara']}\n";
                $message .= "Ruangan: {$booking['nama_ruang']}\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: REJECTED (Ditolak)\n";
                $message .= "Alasan: " . ($booking['reject_reason'] ?? 'Tidak ada alasan yang diberikan') . "\n\n";
                $message .= "Silakan hubungi admin untuk informasi lebih lanjut atau coba booking ulang dengan waktu yang berbeda.\n";
                $message .= "\nTerima kasih.";
                break;
                
            case 'cancellation':
                $subject = 'Booking Dibatalkan - ' . $booking['nama_acara'];
                $message = "Booking ruangan Anda telah dibatalkan.\n\n";
                $message .= "Detail Booking yang Dibatalkan:\n";
                $message .= "Nama Acara: {$booking['nama_acara']}\n";
                $message .= "Ruangan: {$booking['nama_ruang']}\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: CANCELLED (Dibatalkan)\n\n";
                $message .= "Slot waktu ini sekarang tersedia untuk pengguna lain.\n";
                $message .= "Jika Anda masih memerlukan ruangan, silakan lakukan booking ulang.\n";
                $message .= "\nTerima kasih.";
                break;
                
            case 'admin_cancellation':
                $subject = 'Booking Dibatalkan oleh Admin - ' . $booking['nama_acara'];
                $message = "Booking ruangan Anda telah dibatalkan oleh administrator.\n\n";
                $message .= "Detail Booking yang Dibatalkan:\n";
                $message .= "Ruangan: " . ($booking['nama_ruang'] ?? 'Unknown') . "\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Alasan: " . ($booking['cancellation_reason'] ?? 'Tidak ada alasan yang diberikan') . "\n\n";
                $message .= "INFORMASI PENTING: Slot waktu ini sekarang tersedia untuk pengguna lain.\n\n";
                $message .= "Jika Anda masih memerlukan ruangan pada waktu tersebut, silakan lakukan booking ulang atau hubungi admin.\n";
                $message .= "\nTerima kasih atas pengertian Anda.";
                break;
                
            case 'checkout_confirmation':
                $subject = 'Checkout Berhasil - ' . $booking['nama_acara'];
                $message = "Checkout untuk booking ruangan '{$booking['nama_acara']}' telah berhasil dilakukan.\n\n";
                $message .= "Detail Checkout:\n";
                $message .= "Ruangan: " . ($booking['nama_ruang'] ?? 'Unknown') . "\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu Booking: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Waktu Checkout: " . (isset($booking['checkout_time']) ? date('H:i', strtotime($booking['checkout_time'])) : 'Tidak diketahui') . "\n";
                $message .= "Status: COMPLETED (Selesai)\n";
                $message .= "Keterangan: Ruangan sudah di-checkout oleh mahasiswa\n\n";
                $message .= "Terima kasih telah menggunakan ruangan dengan baik dan melakukan checkout tepat waktu.\n";
                $message .= "\nTerima kasih.";
                break;
                
            case 'slot_available_notification':
                $subject = 'Slot Ruangan Tersedia - ' . ($booking['nama_ruang'] ?? 'Ruangan');
                $message = "Ada slot ruangan yang baru tersedia!\n\n";
                $message .= "Detail Slot:\n";
                $message .= "Ruangan: " . ($booking['nama_ruang'] ?? 'Unknown') . "\n";
                $message .= "Tanggal: " . formatDate($booking['tanggal']) . "\n";
                $message .= "Waktu: " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . "\n";
                $message .= "Status: Tersedia untuk dibooking\n\n";
                $message .= "Slot ini baru saja tersedia karena ada pembatalan. Segera lakukan peminjaman jika Anda memerlukan ruangan pada waktu tersebut.\n";
                $message .= "\nTerima kasih.";
                break;
                
            default:
                $subject = 'Notifikasi Peminjaman Ruangan - ' . $booking['nama_acara'];
                $message = "Ini adalah notifikasi terkait peminjaman ruangan Anda.\n\nTerima kasih.";
        }
        
        // Log the notification (in production, replace this with actual email sending)
        error_log("EMAIL NOTIFICATION: To: $email, Subject: $subject, Type: $type");
        error_log("EMAIL CONTENT: $message");
        
        // TODO: Implement actual email sending here
        // Example using PHP mail() function:
        /*
        $headers = "From: noreply@stie-mce.ac.id\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        if (mail($email, $subject, $message, $headers)) {
            return true;
        } else {
            error_log("Failed to send email to: $email");
            return false;
        }
        */
        
        // For now, always return true to prevent errors
        return true;
        
    } catch (Exception $e) {
        error_log("Error in sendBookingNotification: " . $e->getMessage());
        return false;
    }
}
/**
 * Calculate booking duration in hours
 */
function calculateBookingDuration($booking) {
    $start = new DateTime($booking['jam_mulai']);
    $end = new DateTime($booking['jam_selesai']);
    $interval = $start->diff($end);
    return $interval->h + ($interval->i / 60);
}

/**
 * Check if user can activate booking (enhanced for academic schedules)
 */
function canActivateBooking($booking, $currentDate, $currentTime) {
    if ($booking['status'] !== 'approve') {
        return false;
    }
    
    if ($booking['tanggal'] !== $currentDate) {
        return false;
    }
    
    // Can activate 30 minutes before start time
    $startTime = strtotime($booking['jam_mulai']);
    $currentTimestamp = strtotime($currentTime);
    $timeDiff = $startTime - $currentTimestamp;
    
    // Allow activation 30 minutes before (1800 seconds) to 5 minutes after (-300 seconds)
    return ($timeDiff <= 1800 && $timeDiff >= -300);
}

/**
 * Check if user can perform booking activation based on time and permissions
 */
function canUserActivateNow($booking, $userId) {
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    
    // Check if user owns the booking
    if ($booking['id_user'] != $userId) {
        return false;
    }
    
    // Check if booking is in approved status
    if ($booking['status'] !== 'approve') {
        return false;
    }
    
    return canActivateBooking($booking, $currentDate, $currentTime);
}

/**
 * Enhanced booking time validation
 */
function isBookingTimeValid($booking) {
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    
    $bookingDate = $booking['tanggal'];
    $bookingStartTime = $booking['jam_mulai'];
    $bookingEndTime = $booking['jam_selesai'];
    
    // Check if booking date has passed
    if ($bookingDate < $currentDate) {
        return ['valid' => false, 'reason' => 'expired', 'message' => 'Booking sudah berakhir'];
    }
    
    // Check if booking is for today
    if ($bookingDate === $currentDate) {
        // Check if current time is after booking end time
        if ($currentTime > $bookingEndTime) {
            return ['valid' => false, 'reason' => 'expired', 'message' => 'Waktu booking sudah lewat'];
        }
        
        // Check if current time is within booking time
        if ($currentTime >= $bookingStartTime && $currentTime <= $bookingEndTime) {
            return ['valid' => true, 'reason' => 'active_time', 'message' => 'Dalam waktu booking'];
        }
        
        // Check if current time is before booking time
        if ($currentTime < $bookingStartTime) {
            return ['valid' => true, 'reason' => 'before_time', 'message' => 'Belum waktu booking'];
        }
    }
    
    // Future booking
    return ['valid' => true, 'reason' => 'future', 'message' => 'Booking masa depan'];
}

/**
 * Get appropriate action buttons for booking based on user and time
 */
function getBookingActionButtonsV2($booking, $currentUserId, $userRole = null) {
    $buttons = [];
    $timeValidation = isBookingTimeValid($booking);
    
    switch ($booking['status']) {
        case 'pending':
            if ($booking['id_user'] == $currentUserId && $timeValidation['valid']) {
                $buttons[] = [
                    'type' => 'cancel',
                    'class' => 'btn btn-sm btn-danger',
                    'text' => '<i class="fas fa-times"></i> Batalkan',
                    'onclick' => "cancelBooking({$booking['id_booking']})"
                ];
            }
            if ($userRole === 'admin') {
                $buttons[] = [
                    'type' => 'approve',
                    'class' => 'btn btn-sm btn-success',
                    'text' => '<i class="fas fa-check"></i> Setujui',
                    'onclick' => "approveBooking({$booking['id_booking']})"
                ];
                $buttons[] = [
                    'type' => 'reject',
                    'class' => 'btn btn-sm btn-danger',
                    'text' => '<i class="fas fa-times"></i> Tolak',
                    'onclick' => "rejectBooking({$booking['id_booking']})"
                ];
            }
            break;
            
        case 'approve':
            if ($booking['id_user'] == $currentUserId) {
                if (canUserActivateNow($booking, $currentUserId)) {
                    $buttons[] = [
                        'type' => 'activate',
                        'class' => 'btn btn-sm btn-success activate-btn',
                        'text' => '<i class="fas fa-play"></i> Aktifkan',
                        'onclick' => "activateBooking({$booking['id_booking']})"
                    ];
                } elseif ($timeValidation['valid'] && $timeValidation['reason'] !== 'expired') {
                    $buttons[] = [
                        'type' => 'cancel',
                        'class' => 'btn btn-sm btn-danger',
                        'text' => '<i class="fas fa-times"></i> Batalkan',
                        'onclick' => "cancelBooking({$booking['id_booking']})"
                    ];
                }
            }
            if ($userRole === 'admin') {
                $buttons[] = [
                    'type' => 'cancel',
                    'class' => 'btn btn-sm btn-danger',
                    'text' => '<i class="fas fa-ban"></i> Batalkan',
                    'onclick' => "adminCancelBooking({$booking['id_booking']})"
                ];
            }
            break;
            
        case 'active':
            if ($booking['id_user'] == $currentUserId) {
                $buttons[] = [
                    'type' => 'checkout',
                    'class' => 'btn btn-sm btn-info checkout-btn',
                    'text' => '<i class="fas fa-sign-out-alt"></i> Checkout',
                    'onclick' => "showCheckoutModal({$booking['id_booking']})"
                ];
            }
            if ($userRole === 'admin') {
                $buttons[] = [
                    'type' => 'force_checkout',
                    'class' => 'btn btn-sm btn-warning',
                    'text' => '<i class="fas fa-sign-out-alt"></i> Force Checkout',
                    'onclick' => "forceCheckoutBooking({$booking['id_booking']})"
                ];
            }
            break;
            
        default:
            // For completed, rejected, cancelled bookings - no action buttons
            break;
    }
    
    return $buttons;
}

function autoCleanupHolidaySchedules($conn) {
    try {
        // Get all holidays
        $stmt = $conn->prepare("SELECT tanggal FROM tbl_harilibur");
        $stmt->execute();
        $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $cleanedCount = 0;
        
        foreach ($holidays as $holiday) {
            // Delete or cancel bookings on holidays (except for essential ones)
            $stmt = $conn->prepare("
                UPDATE tbl_booking 
                SET status = 'cancelled', 
                    cancelled_by = 'SYSTEM_AUTO',
                    cancelled_at = NOW(),
                    cancellation_reason = 'Auto-cancelled due to holiday'
                WHERE tanggal = ? 
                AND status IN ('pending', 'approve')
                AND booking_type != 'recurring'
            ");
            $stmt->execute([$holiday]);
            $cleanedCount += $stmt->rowCount();
        }
        
        return $cleanedCount;
        
    } catch (Exception $e) {
        error_log("Holiday cleanup error: " . $e->getMessage());
        return 0;
    }
}

function forceAutoCheckoutExpiredBookings($conn) {
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    $currentDateTime = date('Y-m-d H:i:s');
    
    try {
        // Find bookings that should be auto-checked out
        $stmt = $conn->prepare("
            SELECT b.id_booking, b.nama_acara, b.tanggal, b.jam_mulai, b.jam_selesai, 
                   b.no_penanggungjawab, b.id_user, b.nama_dosen,
                   r.nama_ruang, g.nama_gedung, u.email
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            JOIN tbl_users u ON b.id_user = u.id_user
            WHERE b.status = 'active' 
            AND (
                (b.tanggal < ?) OR 
                (b.tanggal = ? AND b.jam_selesai < ?)
            )
        ");
        $stmt->execute([$currentDate, $currentDate, $currentTime]);
        $expiredBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $completedCount = 0;
        
        foreach ($expiredBookings as $booking) {
            // Update to completed status
            $updateStmt = $conn->prepare("
                UPDATE tbl_booking 
                SET status = 'done',
                    checkout_status = 'auto_completed',
                    checkout_time = ?,
                    completion_note = 'Auto-completed by system - booking time expired',
                    checked_out_by = 'SYSTEM_AUTO'
                WHERE id_booking = ?
            ");
            
            if ($updateStmt->execute([$currentDateTime, $booking['id_booking']])) {
                $completedCount++;
                error_log("Auto-completed expired booking: {$booking['id_booking']} - {$booking['nama_acara']}");
            }
        }
        
        return [
            'completed_count' => $completedCount,
            'processed_bookings' => $expiredBookings
        ];
        
    } catch (Exception $e) {
        error_log("Auto checkout error: " . $e->getMessage());
        return ['completed_count' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Kirim notifikasi auto-completion
 */
function sendAutoCompletionNotification($booking, $reason) {
    try {
        $notificationData = array_merge($booking, [
            'completion_note' => $reason,
            'completion_time' => date('Y-m-d H:i:s')
        ]);
        
        sendBookingNotification($booking['email'], $notificationData, 'auto_complete');
        
        error_log("AUTO-COMPLETION NOTIFICATION: Sent to {$booking['email']} for booking #{$booking['id_booking']}");
        
    } catch (Exception $e) {
        error_log("Failed to send auto-completion notification: " . $e->getMessage());
    }
}

/**
 * Trigger auto-completion saat akses halaman
 * Fungsi ini dipanggil otomatis di index.php
 */
function triggerAutoCompletion($conn) {
    // Check apakah sudah di-trigger dalam 30 menit terakhir
    $lastCheck = $_SESSION['last_auto_completion_check'] ?? 0;
    $now = time();
    
    // Trigger setiap 30 menit sekali per session
    if (($now - $lastCheck) >= 1800) { // 30 menit = 1800 detik
        $result = forceAutoCheckoutExpiredBookings($conn);
        $_SESSION['last_auto_completion_check'] = $now;
        
        if ($result['completed_count'] > 0) {
            error_log("AUTO-COMPLETION TRIGGER: Completed {$result['completed_count']} expired bookings");
        }
        
        return $result;
    }
    
    return ['completed_count' => 0, 'updates' => []];
}

/**
 * Update status booking berdasarkan waktu real-time
 * Untuk tampilan yang akurat di kalender
 */
function updateBookingDisplayStatus($booking) {
    $currentDateTime = new DateTime();
    $currentDate = $currentDateTime->format('Y-m-d');
    $currentTime = $currentDateTime->format('H:i:s');
    
    $bookingDate = $booking['tanggal'];
    $bookingStart = $booking['jam_mulai'];
    $bookingEnd = $booking['jam_selesai'];
    
    // Academic schedule styling
    if ($booking['booking_type'] === 'recurring') {
        return [
            'display_status' => 'academic',
            'display_class' => 'bg-info',
            'display_text' => 'Perkuliahan',
            'is_academic' => true
        ];
    }
    
    // Regular booking status logic
    if ($booking['status'] === 'pending') {
        return [
            'display_status' => 'pending',
            'display_class' => 'bg-warning',
            'display_text' => 'Pending'
        ];
    } elseif ($booking['status'] === 'approve') {
        if ($bookingDate === $currentDate && 
            $currentTime >= $bookingStart && 
            $currentTime <= $bookingEnd) {
            return [
                'display_status' => 'ready_to_activate',
                'display_class' => 'bg-success',
                'display_text' => 'Siap Diaktifkan'
            ];
        } else {
            return [
                'display_status' => 'approved',
                'display_class' => 'bg-success',
                'display_text' => 'Disetujui'
            ];
        }
    } elseif ($booking['status'] === 'active') {
        return [
            'display_status' => 'ongoing',
            'display_class' => 'bg-danger',
            'display_text' => 'Sedang Berlangsung'
        ];
    } elseif ($booking['status'] === 'done') {
        return [
            'display_status' => 'completed',
            'display_class' => 'bg-secondary',
            'display_text' => 'Selesai'
        ];
    } elseif ($booking['status'] === 'cancelled') {
        return [
            'display_status' => 'cancelled',
            'display_class' => 'bg-secondary',
            'display_text' => 'Dibatalkan'
        ];
    }
    
    return [
        'display_status' => 'unknown',
        'display_class' => 'bg-light',
        'display_text' => $booking['status']
    ];
}

if (!function_exists('formatTime')) {
    function formatTime($time) {
        if (empty($time)) return '-';
        try {
            $timeObj = new DateTime($time);
            return $timeObj->format('H:i');
        } catch (Exception $e) {
            return substr($time, 0, 5);
        }
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'd/m/Y') {
        if (empty($date)) return '-';
        try {
            if ($format === 'l, d F Y') {
                setlocale(LC_TIME, 'id_ID.UTF-8');
                $dateObj = new DateTime($date);
                $dayName = [
                    'Sunday' => 'Minggu',
                    'Monday' => 'Senin', 
                    'Tuesday' => 'Selasa',
                    'Wednesday' => 'Rabu',
                    'Thursday' => 'Kamis',
                    'Friday' => 'Jumat',
                    'Saturday' => 'Sabtu'
                ];
                $monthName = [
                    'January' => 'Januari',
                    'February' => 'Februari',
                    'March' => 'Maret',
                    'April' => 'April',
                    'May' => 'Mei',
                    'June' => 'Juni',
                    'July' => 'Juli',
                    'August' => 'Agustus',
                    'September' => 'September',
                    'October' => 'Oktober',
                    'November' => 'November',
                    'December' => 'Desember'
                ];
                
                $day = $dayName[$dateObj->format('l')];
                $date_num = $dateObj->format('d');
                $month = $monthName[$dateObj->format('F')];
                $year = $dateObj->format('Y');
                
                return "$day, $date_num $month $year";
            } else {
                $dateObj = new DateTime($date);
                return $dateObj->format($format);
            }
        } catch (Exception $e) {
            return $date;
        }
    }
}

/**
 * Tambah jadwal perkuliahan berulang
 */
function addRecurringSchedule($conn, $scheduleData) {
    try {
        // PENTING: Cek apakah sudah ada transaction yang aktif
        $inTransaction = $conn->inTransaction();
        
        if (!$inTransaction) {
            $conn->beginTransaction();
        }
        
        // Validate required fields
        $required = ['id_ruang', 'nama_matakuliah', 'kelas', 'dosen_pengampu', 'hari', 'jam_mulai', 'jam_selesai'];
        foreach ($required as $field) {
            if (empty($scheduleData[$field])) {
                throw new Exception("Field '$field' tidak boleh kosong");
            }
        }
        
        // Check for time conflicts
        $conflictCheck = $conn->prepare("
            SELECT rs.nama_matakuliah, rs.kelas, r.nama_ruang
            FROM tbl_recurring_schedules rs
            JOIN tbl_ruang r ON rs.id_ruang = r.id_ruang
            WHERE rs.id_ruang = ? 
            AND rs.hari = ? 
            AND rs.status = 'active'
            AND (
                (TIME(rs.jam_mulai) < TIME(?) AND TIME(rs.jam_selesai) > TIME(?)) OR
                (TIME(rs.jam_mulai) < TIME(?) AND TIME(rs.jam_selesai) > TIME(?)) OR
                (TIME(rs.jam_mulai) >= TIME(?) AND TIME(rs.jam_selesai) <= TIME(?))
            )
            LIMIT 1
        ");
        
        $conflictCheck->execute([
            $scheduleData['id_ruang'],
            $scheduleData['hari'],
            $scheduleData['jam_mulai'], $scheduleData['jam_mulai'],
            $scheduleData['jam_selesai'], $scheduleData['jam_selesai'],
            $scheduleData['jam_mulai'], $scheduleData['jam_selesai']
        ]);
        
        $conflict = $conflictCheck->fetch();
        if ($conflict) {
            throw new Exception("Konflik waktu dengan '{$conflict['nama_matakuliah']} - {$conflict['kelas']}' di ruangan {$conflict['nama_ruang']}");
        }
        
        // Insert recurring schedule
        $stmt = $conn->prepare("
            INSERT INTO tbl_recurring_schedules 
            (id_ruang, nama_matakuliah, kelas, dosen_pengampu, hari, jam_mulai, jam_selesai, 
             semester, tahun_akademik, start_date, end_date, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
        ");
        
        $stmt->execute([
            $scheduleData['id_ruang'],
            $scheduleData['nama_matakuliah'],
            $scheduleData['kelas'],
            $scheduleData['dosen_pengampu'],
            $scheduleData['hari'],
            $scheduleData['jam_mulai'],
            $scheduleData['jam_selesai'],
            $scheduleData['semester'] ?? 'Genap',
            $scheduleData['tahun_akademik'] ?? '2024/2025',
            $scheduleData['start_date'] ?? date('Y-m-d'),
            $scheduleData['end_date'] ?? date('Y-m-d', strtotime('+6 months')),
            $scheduleData['created_by'] ?? $_SESSION['user_id']
        ]);
        
        $scheduleId = $conn->lastInsertId();
        
        // Generate bookings
        $bookingCount = generateBookingsForSchedule($conn, $scheduleId, $scheduleData);
        
        // Commit hanya jika transaction dimulai di fungsi ini
        if (!$inTransaction) {
            $conn->commit();
        }
        
        return [
            'success' => true,
            'message' => 'Jadwal berhasil ditambahkan',
            'schedule_id' => $scheduleId,
            'generated_bookings' => $bookingCount
        ];
        
    } catch (Exception $e) {
        // Rollback hanya jika transaction dimulai di fungsi ini
        if (!$inTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Generate recurring bookings - SIMPLIFIED VERSION
 */
function generateRecurringBookingsSimplified($conn, $scheduleId, $scheduleData) {
    $generatedCount = 0;
    
    try {
        error_log("=== GENERATE BOOKINGS START ===");
        
        // Check if booking_type column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM tbl_booking LIKE 'booking_type'");
        $hasBookingType = $checkColumn->rowCount() > 0;
        
        if (!$hasBookingType) {
            // Add column if it doesn't exist
            $conn->exec("ALTER TABLE tbl_booking ADD COLUMN booking_type enum('manual','recurring','external') DEFAULT 'manual' AFTER status");
            $conn->exec("ALTER TABLE tbl_booking ADD COLUMN recurring_schedule_id int(11) NULL AFTER booking_type");
            error_log("Added missing columns to tbl_booking");
        }
        
        // Get holidays to skip - simplified version
        $holidays = [];
        try {
            $stmt = $conn->prepare("SELECT tanggal FROM tbl_harilibur WHERE tanggal >= CURDATE()");
            $stmt->execute();
            $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("Could not fetch holidays: " . $e->getMessage());
        }
        
        // Convert day name to number (0 = Sunday, 1 = Monday, etc.)
        $dayNumbers = [
            'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6
        ];
        
        $targetDayNumber = $dayNumbers[$scheduleData['hari']];
        
        $currentDate = new DateTime($scheduleData['start_date']);
        $endDate = new DateTime($scheduleData['end_date']);
        
        // Find first occurrence of the target day
        while ($currentDate->format('w') != $targetDayNumber && $currentDate <= $endDate) {
            $currentDate->add(new DateInterval('P1D'));
        }
        
        // Generate bookings for each week
        while ($currentDate <= $endDate) {
            $bookingDate = $currentDate->format('Y-m-d');
            
            // Skip if it's a holiday
            if (!in_array($bookingDate, $holidays)) {
                // Check for existing booking conflicts - simplified check
                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM tbl_booking 
                    WHERE id_ruang = ? 
                    AND tanggal = ? 
                    AND status IN ('confirmed', 'approve', 'pending', 'active')
                    AND (
                        (jam_mulai < ? AND jam_selesai > ?) OR
                        (jam_mulai < ? AND jam_selesai > ?) OR
                        (jam_mulai >= ? AND jam_selesai <= ?)
                    )
                ");
                
                $stmt->execute([
                    $scheduleData['id_ruang'], $bookingDate,
                    $scheduleData['jam_selesai'], $scheduleData['jam_mulai'],    // Overlap at start
                    $scheduleData['jam_mulai'], $scheduleData['jam_mulai'],     // Overlap at end  
                    $scheduleData['jam_mulai'], $scheduleData['jam_selesai']    // Complete overlap
                ]);
                
                $conflict = $stmt->fetchColumn() > 0;
                
                if (!$conflict) {
                    // Create booking - try with different column sets based on what exists
                    $keperluan = "Perkuliahan: {$scheduleData['nama_matakuliah']} - Kelas {$scheduleData['kelas']}";
                    $nama_peminjam = "Sistem Otomatis - {$scheduleData['dosen_pengampu']}";
                    
                    if ($hasBookingType) {
                        $stmt = $conn->prepare("
                            INSERT INTO tbl_booking 
                            (id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai, keterangan, nama, no_penanggungjawab, 
                             status, booking_type, recurring_schedule_id, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'approve', 'recurring', ?, NOW())
                        ");
                        
                        $stmt->execute([
                            $scheduleData['created_by'],
                            $scheduleData['id_ruang'],
                            "{$scheduleData['nama_matakuliah']} - {$scheduleData['kelas']}",
                            $bookingDate,
                            $scheduleData['jam_mulai'],
                            $scheduleData['jam_selesai'],
                            $keperluan,
                            $nama_peminjam,
                            $scheduleId
                        ]);
                    } else {
                        // Fallback for older table structure
                        $stmt = $conn->prepare("
                            INSERT INTO tbl_booking 
                            (id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai, keterangan, nama, no_penanggungjawab, 
                             status, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'approve', NOW())
                        ");
                        
                        $stmt->execute([
                            $scheduleData['created_by'],
                            $scheduleData['id_ruang'],
                            "{$scheduleData['nama_matakuliah']} - {$scheduleData['kelas']}",
                            $bookingDate,
                            $scheduleData['jam_mulai'],
                            $scheduleData['jam_selesai'],
                            $keperluan,
                            $nama_peminjam
                        ]);
                    }
                    
                    $generatedCount++;
                    error_log("Generated booking for: $bookingDate");
                }
            }
            
            // Move to next week
            $currentDate->add(new DateInterval('P7D'));
        }
        
        error_log("=== GENERATE BOOKINGS END - Generated: $generatedCount ===");
        
    } catch (Exception $e) {
        error_log("Error generating recurring bookings: " . $e->getMessage());
        throw $e;
    }
    
    return $generatedCount;
}

/**
 * Update recurring schedule and regenerate bookings
 */
function updateRecurringSchedule($conn, $scheduleId, $scheduleData) {
    try {
        $conn->beginTransaction();
        
        // Get existing schedule data
        $stmt = $conn->prepare("SELECT * FROM tbl_recurring_schedules WHERE id_schedule = ?");
        $stmt->execute([$scheduleId]);
        $existingSchedule = $stmt->fetch();
        
        if (!$existingSchedule) {
            throw new Exception('Jadwal tidak ditemukan');
        }
        
        // Remove future bookings from this schedule
        $removedBookings = removeFutureRecurringBookings($conn, $scheduleId);
        
        // Update schedule
        $stmt = $conn->prepare("
            UPDATE tbl_recurring_schedules 
            SET id_ruang = ?, nama_matakuliah = ?, kelas = ?, dosen_pengampu = ?, 
                hari = ?, jam_mulai = ?, jam_selesai = ?, semester = ?, tahun_akademik = ?, 
                start_date = ?, end_date = ?, updated_at = NOW()
            WHERE id_schedule = ?
        ");
        
        $stmt->execute([
            $scheduleData['id_ruang'],
            $scheduleData['nama_matakuliah'],
            $scheduleData['kelas'],
            $scheduleData['dosen_pengampu'],
            $scheduleData['hari'],
            $scheduleData['jam_mulai'],
            $scheduleData['jam_selesai'],
            $scheduleData['semester'],
            $scheduleData['tahun_akademik'],
            $scheduleData['start_date'],
            $scheduleData['end_date'],
            $scheduleId
        ]);
        
        // Generate new bookings
        $newBookings = generateRecurringBookings($conn, $scheduleId, $scheduleData);
        
        $conn->commit();
        
        return [
            'success' => true,
            'removed_bookings' => $removedBookings,
            'generated_bookings' => $newBookings,
            'message' => 'Jadwal berhasil diupdate'
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error updating recurring schedule: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Gagal mengupdate jadwal: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete recurring schedule and remove future bookings
 */
function deleteRecurringSchedule($conn, $scheduleId) {
    try {
        $conn->beginTransaction();
        
        // Get schedule info for logging
        $stmt = $conn->prepare("
            SELECT rs.*, r.nama_ruang 
            FROM tbl_recurring_schedules rs 
            LEFT JOIN tbl_ruang r ON rs.id_ruang = r.id_ruang 
            WHERE rs.id_schedule = ?
        ");
        $stmt->execute([$scheduleId]);
        $schedule = $stmt->fetch();
        
        if (!$schedule) {
            throw new Exception('Jadwal tidak ditemukan');
        }
        
        // Remove future bookings
        $removedBookings = removeFutureRecurringBookings($conn, $scheduleId);
        
        // Soft delete the schedule
        $stmt = $conn->prepare("
            UPDATE tbl_recurring_schedules 
            SET status = 'deleted', updated_at = NOW() 
            WHERE id_schedule = ?
        ");
        $stmt->execute([$scheduleId]);
        
        $conn->commit();
        
        return [
            'success' => true,
            'removed_bookings' => $removedBookings,
            'message' => "Jadwal '{$schedule['nama_matakuliah']} - {$schedule['kelas']}' berhasil dihapus"
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error deleting recurring schedule: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Gagal menghapus jadwal: ' . $e->getMessage()
        ];
    }
}

/**
 * Generate recurring bookings for a schedule
 */
function generateRecurringBookings($conn, $scheduleId, $scheduleData) {
    $generatedCount = 0;
    
    try {
        // Get holidays to skip
        $holidays = getHolidays($conn);
        
        // Convert day name to number (0 = Sunday, 1 = Monday, etc.)
        $dayNumbers = [
            'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6
        ];
        
        $targetDayNumber = $dayNumbers[$scheduleData['hari']];
        
        $currentDate = new DateTime($scheduleData['start_date']);
        $endDate = new DateTime($scheduleData['end_date']);
        
        // Find first occurrence of the target day
        while ($currentDate->format('w') != $targetDayNumber && $currentDate <= $endDate) {
            $currentDate->add(new DateInterval('P1D'));
        }
        
        // Generate bookings for each week
        while ($currentDate <= $endDate) {
            $bookingDate = $currentDate->format('Y-m-d');
            
            // Skip if it's a holiday
            if (!in_array($bookingDate, $holidays)) {
                // Check for existing booking conflicts
                $conflict = checkBookingConflict($conn, $scheduleData['id_ruang'], $bookingDate, $scheduleData['jam_mulai'], $scheduleData['jam_selesai']);
                
                if (!$conflict) {
                    // Create booking
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_booking 
                        (id_ruang, tanggal, jam_mulai, jam_selesai, keperluan, nama_peminjam, 
                         email_peminjam, status, booking_type, recurring_schedule_id, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', 'recurring', ?, NOW())
                    ");
                    
                    $keperluan = "Perkuliahan: {$scheduleData['nama_matakuliah']} - Kelas {$scheduleData['kelas']}";
                    $nama_peminjam = "Sistem Otomatis - {$scheduleData['dosen_pengampu']}";
                    $email_peminjam = "auto-schedule@system.edu";
                    
                    $stmt->execute([
                        $scheduleData['id_ruang'],
                        $bookingDate,
                        $scheduleData['jam_mulai'],
                        $scheduleData['jam_selesai'],
                        $keperluan,
                        $nama_peminjam,
                        $email_peminjam,
                        $scheduleId
                    ]);
                    
                    $generatedCount++;
                }
            }
            
            // Move to next week
            $currentDate->add(new DateInterval('P7D'));
        }
        
    } catch (Exception $e) {
        error_log("Error generating recurring bookings: " . $e->getMessage());
        throw $e;
    }
    
    return $generatedCount;
}

/**
 * Remove future recurring bookings for a schedule
 */
function removeFutureRecurringBookings($conn, $scheduleId) {
    try {
        $stmt = $conn->prepare("
            DELETE FROM tbl_booking 
            WHERE recurring_schedule_id = ? 
            AND tanggal >= CURDATE() 
            AND booking_type = 'recurring'
        ");
        $stmt->execute([$scheduleId]);
        
        return $stmt->rowCount();
        
    } catch (Exception $e) {
        error_log("Error removing future bookings: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check for booking conflicts
 */
function checkBookingConflict($conn, $roomId, $date, $startTime, $endTime) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_booking 
            WHERE id_ruang = ? 
            AND tanggal = ? 
            AND status IN ('confirmed', 'pending')
            AND (
                (jam_mulai < ? AND jam_selesai > ?) OR
                (jam_mulai < ? AND jam_selesai > ?) OR
                (jam_mulai >= ? AND jam_selesai <= ?)
            )
        ");
        
        $stmt->execute([
            $roomId, $date,
            $endTime, $startTime,    // Overlap at start
            $startTime, $startTime,  // Overlap at end  
            $startTime, $endTime     // Complete overlap
        ]);
        
        return $stmt->fetchColumn() > 0;
        
    } catch (Exception $e) {
        error_log("Error checking booking conflict: " . $e->getMessage());
        return true; // Assume conflict to be safe
    }
}
/**
 * Sync recurring schedules (maintenance function)
 */
function syncRecurringSchedules($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM tbl_recurring_schedules 
            WHERE status = 'active' 
            AND end_date >= CURDATE()
        ");
        $stmt->execute();
        $schedules = $stmt->fetchAll();
        
        $syncedCount = 0;
        
        foreach ($schedules as $schedule) {
            // Check if there are missing bookings for this schedule
            $missingBookings = findMissingBookings($conn, $schedule);
            
            if ($missingBookings > 0) {
                generateRecurringBookings($conn, $schedule['id_schedule'], $schedule);
                $syncedCount++;
            }
        }
        
        return [
            'success' => true,
            'synced_schedules' => $syncedCount,
            'message' => "Synchronized {$syncedCount} schedules"
        ];
        
    } catch (Exception $e) {
        error_log("Sync error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get holidays from database
 */
function getHolidays($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT tanggal_libur 
            FROM tbl_holidays 
            WHERE status = 'active' 
            AND tanggal_libur >= CURDATE()
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
        
    } catch (Exception $e) {
        error_log("Error getting holidays: " . $e->getMessage());
        return [];
    }
}

function hasBookingConflictEnhanced($conn, $roomId, $date, $startTime, $endTime, $excludeBookingId = null, $excludeScheduleId = null) {
    $sql = "SELECT b.id_booking, b.nama_acara, b.jam_mulai, b.jam_selesai, b.booking_type, rs.nama_matakuliah, rs.kelas
            FROM tbl_booking b
            LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
            WHERE b.id_ruang = ? AND b.tanggal = ? 
            AND b.status NOT IN ('cancelled', 'rejected')
            AND (
                (b.jam_mulai < ? AND b.jam_selesai > ?) OR
                (b.jam_mulai < ? AND b.jam_selesai > ?) OR
                (b.jam_mulai >= ? AND b.jam_selesai <= ?) OR
                (b.jam_mulai <= ? AND b.jam_selesai >= ?)
            )";
    
    $params = [
        $roomId, $date, 
        $endTime, $startTime,    // overlap start
        $startTime, $endTime,    // overlap end  
        $startTime, $endTime,    // inside
        $startTime, $endTime     // outside
    ];
    
    if ($excludeBookingId) {
        $sql .= " AND b.id_booking != ?";
        $params[] = $excludeBookingId;
    }
    
    if ($excludeScheduleId) {
        $sql .= " AND (b.id_schedule IS NULL OR b.id_schedule != ?)";
        $params[] = $excludeScheduleId;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($conflicts) > 0) {
        // Log detail konflik untuk debugging
        foreach ($conflicts as $conflict) {
            $conflictName = $conflict['booking_type'] === 'recurring' ? 
                ($conflict['nama_matakuliah'] . ' - ' . $conflict['kelas']) : 
                $conflict['nama_acara'];
            error_log("CONFLICT DETECTED: {$conflictName} at {$conflict['jam_mulai']}-{$conflict['jam_selesai']} on {$date}");
        }
        return true;
    }
    
    return false;
}


function hasBookingConflictExcludeSchedule($conn, $roomId, $date, $startTime, $endTime, $excludeScheduleId = null) {
    $sql = "SELECT COUNT(*) FROM tbl_booking 
            WHERE id_ruang = ? AND tanggal = ? 
            AND status IN ('pending', 'approve', 'active')
            AND (
                (jam_mulai < ? AND jam_selesai > ?) OR
                (jam_mulai < ? AND jam_selesai > ?) OR
                (jam_mulai >= ? AND jam_selesai <= ?)
            )";
    
    $params = [$roomId, $date, $endTime, $startTime, $startTime, $endTime, $startTime, $endTime];
    
    if ($excludeScheduleId) {
        $sql .= " AND (id_schedule IS NULL OR id_schedule != ?)";
        $params[] = $excludeScheduleId;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
}

/**
 * Generate bookings for a specific recurring schedule
 */
function generateBookingsForSchedule($conn, $scheduleId, $scheduleData) {
    $bookingCount = 0;
    $startDate = new DateTime($scheduleData['start_date'] ?? date('Y-m-d'));
    $endDate = new DateTime($scheduleData['end_date'] ?? date('Y-m-d', strtotime('+6 months')));
    
    // Day mapping
    $dayMap = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
        'friday' => 5, 'saturday' => 6, 'sunday' => 0
    ];
    
    $targetDay = $dayMap[$scheduleData['hari']] ?? 1;
    
    // Find first occurrence of the target day
    $current = clone $startDate;
    while ($current->format('w') != $targetDay && $current <= $endDate) {
        $current->add(new DateInterval('P1D'));
    }
    
    // Get holidays
    $holidayStmt = $conn->prepare("SELECT tanggal FROM tbl_harilibur");
    $holidayStmt->execute();
    $holidays = array_column($holidayStmt->fetchAll(), 'tanggal');
    
    // Generate weekly bookings
    while ($current <= $endDate) {
        $currentDateStr = $current->format('Y-m-d');
        
        // Skip holidays
        if (!in_array($currentDateStr, $holidays)) {
            try {
                // Check for existing booking on this date and time
                $existingCheck = $conn->prepare("
                    SELECT id_booking FROM tbl_booking 
                    WHERE id_ruang = ? AND tanggal = ? AND jam_mulai = ? AND jam_selesai = ?
                ");
                $existingCheck->execute([
                    $scheduleData['id_ruang'],
                    $currentDateStr,
                    $scheduleData['jam_mulai'],
                    $scheduleData['jam_selesai']
                ]);
                
                if (!$existingCheck->fetch()) {
                    $bookingStmt = $conn->prepare("
                        INSERT INTO tbl_booking 
                        (id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai, keterangan, 
                         nama, no_penanggungjawab, status, booking_type, auto_generated, id_schedule, 
                         approved_at, approved_by, created_at)
                        VALUES (5, ?, ?, ?, ?, ?, ?, ?, 0, 'approve', 'recurring', 1, ?, NOW(), 'SYSTEM_AUTO', NOW())
                    ");
                    
                    $namaAcara = $scheduleData['nama_matakuliah'] . ' - ' . $scheduleData['kelas'];
                    $keterangan = 'Jadwal Perkuliahan ' . ($scheduleData['semester'] ?? 'Genap') . ' ' . ($scheduleData['tahun_akademik'] ?? '2024/2025') . ' - Dosen: ' . $scheduleData['dosen_pengampu'];
                    
                    $bookingStmt->execute([
                        $scheduleData['id_ruang'],
                        $namaAcara,
                        $currentDateStr,
                        $scheduleData['jam_mulai'],
                        $scheduleData['jam_selesai'],
                        $keterangan,
                        $scheduleData['dosen_pengampu'],
                        $scheduleId
                    ]);
                    
                    $bookingCount++;
                    error_log("Created booking for date: $currentDateStr");
                }
            } catch (Exception $e) {
                error_log("Error creating booking for date $currentDateStr: " . $e->getMessage());
                // Continue with next week instead of failing
            }
        }
        
        $current->add(new DateInterval('P7D')); // Next week
    }
    
    return $bookingCount;
}

/**
 * Get all dates for a specific day of week between two dates
 */
function getRecurringDates($dayOfWeek, $startDate, $endDate) {
    $dates = [];
    
    // Convert day names to numbers
    $dayNumbers = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 0
    ];
    
    if (!isset($dayNumbers[$dayOfWeek])) {
        throw new Exception("Invalid day of week: $dayOfWeek");
    }
    
    $targetDay = $dayNumbers[$dayOfWeek];
    
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);
    
    // Find the first occurrence of the target day
    while ($current->format('w') != $targetDay && $current <= $end) {
        $current->modify('+1 day');
    }
    
    // Collect all dates for this day of week
    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+7 days'); // Next week
    }
    
    return $dates;
}

/**
 * Buat booking individual dari jadwal berulang
 */
function createRecurringBooking($conn, $scheduleData, $date, $scheduleId) {
    try {
        // Get system user ID for recurring bookings
        $systemUserId = getSystemUserId($conn);
        
        $stmt = $conn->prepare("
            INSERT INTO tbl_booking 
            (id_ruang, id_user, nama_acara, keterangan, tanggal, jam_mulai, jam_selesai,
             nama_penanggungjawab, no_penanggungjawab, status, created_at, 
             booking_type, schedule_id, approved_at, approved_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approve', NOW(), 'recurring', ?, NOW(), 'SYSTEM_AUTO')
        ");
        
        $namaAcara = $scheduleData['nama_matakuliah'] . ' - ' . $scheduleData['kelas'];
        $keterangan = 'Perkuliahan rutin - ' . $scheduleData['semester'] . ' ' . $scheduleData['tahun_akademik'];
        $noPenanggungjawab = 'AUTO-GENERATED'; // Default phone for auto bookings
        
        $result = $stmt->execute([
            $scheduleData['id_ruang'],
            $systemUserId,
            $namaAcara,
            $keterangan,
            $date,
            $scheduleData['jam_mulai'],
            $scheduleData['jam_selesai'],
            $scheduleData['dosen_pengampu'],
            $noPenanggungjawab,
            $scheduleId
        ]);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error creating recurring booking: " . $e->getMessage());
        return false;
    }
}

function verifyUserPassword($inputPassword, $storedPassword) {
    // Method 1: Password hash verification
    if (password_verify($inputPassword, $storedPassword)) {
        return true;
    }
    
    // Method 2: Direct comparison (for legacy passwords)
    if ($inputPassword === $storedPassword) {
        return true;
    }
    
    // Method 3: MD5 comparison (if legacy system uses MD5)
    if (md5($inputPassword) === $storedPassword) {
        return true;
    }
    
    return false;
}

/**
 * Check if dosen database is available and accessible
 */
function isDosenDatabaseAvailable() {
    global $conn_dosen;
    
    if ($conn_dosen === null) {
        return false;
    }
    
    try {
        $stmt = $conn_dosen->prepare("SELECT 1 FROM tblKaryawan LIMIT 1");
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Dosen database availability check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get atau buat system user untuk booking otomatis
 */
// Tambahkan ke functions.php jika belum ada
function getSystemUserId($conn) {
    // Check if system user exists
    $stmt = $conn->prepare("SELECT id_user FROM tbl_users WHERE email = 'system@stie-mce.ac.id'");
    $stmt->execute();
    $userId = $stmt->fetchColumn();
    
    if (!$userId) {
        // Create system user
        $stmt = $conn->prepare("
            INSERT INTO tbl_users (email, password, role) 
            VALUES ('system@stie-mce.ac.id', ?, 'admin')
        ");
        $stmt->execute([12345678]); // Password default
        $userId = $conn->lastInsertId();
    }
    
    return $userId;
}

function getRedirectUrlByRole($role) {
    switch ($role) {
        case 'admin':
            return 'admin/admin-dashboard.php';
        case 'cs':
            return 'cs/dashboard.php';
        default:
            return 'index.php';
    }
}

function initializeUserSession($user) {
    // Regenerate session ID for security
    session_regenerate_id(true);
    
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $user['id_user'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    
    // Optional user data
    if (isset($user['email'])) {
        $_SESSION['email'] = $user['email'];
    }
    
    return true;
}

function authenticateUser($conn, $email, $password, $role) {
    try {
        // Get user from database
        $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE email = ? AND role = ? LIMIT 1");
        $stmt->execute([$email, $role]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Email, password, atau role tidak sesuai',
                'user' => null
            ];
        }
        
        // Check user status
        if (isset($user['status']) && $user['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'Akun tidak aktif. Hubungi administrator.',
                'user' => null
            ];
        }
        
        // Verify password
        $passwordMatch = verifyUserPassword($password, $user['password']);
        
        if (!$passwordMatch) {
            error_log("Password mismatch for email: $email");
            return [
                'success' => false,
                'message' => 'Email, password, atau role tidak sesuai',
                'user' => null
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Login berhasil',
            'user' => $user
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in authenticateUser: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.',
            'user' => null
        ];
    }
}

function shouldRedirectUser($userRole, $currentPage) {
    // Admin should go to admin dashboard
    if ($userRole === 'admin' && $currentPage !== 'admin/admin-dashboard.php') {
        return 'admin/admin-dashboard.php';
    }
    
    // Other users stay on index.php
    if (in_array($userRole, ['mahasiswa', 'dosen', 'karyawan', 'cs', 'satpam'])) {
        if ($currentPage !== 'index.php' && $currentPage !== '') {
            return 'index.php';
        }
    }
    
    return false; // No redirect needed
}

/* untuk jadwal kuliah*/
function getRecurringSchedules($conn, $roomId, $date) {
    $stmt = $conn->prepare("
        SELECT rs.*, u.email 
        FROM tbl_recurring_schedules rs 
        JOIN tbl_users u ON rs.id_user = u.id_user 
        WHERE rs.id_ruang = ? 
        AND rs.status = 'active'
        AND ? BETWEEN rs.start_date AND rs.end_date
        AND DAYOFWEEK(?) = CASE rs.day_of_week 
            WHEN 'monday' THEN 2
            WHEN 'tuesday' THEN 3  
            WHEN 'wednesday' THEN 4
            WHEN 'thursday' THEN 5
            WHEN 'friday' THEN 6
            WHEN 'saturday' THEN 7
            WHEN 'sunday' THEN 1
        END
    ");
    $stmt->execute([$roomId, $date, $date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateScheduleForDateRange($conn, $startDate, $endDate) {
    $generatedCount = 0;
    
    try {
        // Get all active recurring schedules
        $stmt = $conn->prepare("
            SELECT * FROM tbl_recurring_schedules 
            WHERE status = 'active' 
            AND start_date <= ? AND end_date >= ?
        ");
        $stmt->execute([$endDate, $startDate]);
        $schedules = $stmt->fetchAll();
        
        foreach ($schedules as $schedule) {
            // Adjust dates to fit within the requested range
            $adjustedStartDate = max($schedule['start_date'], $startDate);
            $adjustedEndDate = min($schedule['end_date'], $endDate);
            
            if ($adjustedStartDate <= $adjustedEndDate) {
                $scheduleData = [
                    'id_ruang' => $schedule['id_ruang'],
                    'nama_matakuliah' => $schedule['nama_matakuliah'],
                    'kelas' => $schedule['kelas'],
                    'dosen_pengampu' => $schedule['dosen_pengampu'],
                    'hari' => $schedule['hari'],
                    'jam_mulai' => $schedule['jam_mulai'],
                    'jam_selesai' => $schedule['jam_selesai'],
                    'semester' => $schedule['semester'],
                    'tahun_akademik' => $schedule['tahun_akademik'],
                    'start_date' => $adjustedStartDate,
                    'end_date' => $adjustedEndDate,
                    'created_by' => $schedule['created_by']
                ];
                
                $generated = generateBookingsForSchedule($conn, $schedule['id_schedule'], $scheduleData);
                $generatedCount += $generated;
            }
        }
        
        return $generatedCount;
        
    } catch (Exception $e) {
        error_log("Error in generateScheduleForDateRange: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Auto-generate upcoming schedules (untuk cron job atau auto-trigger)
 */
function autoGenerateUpcomingSchedules($conn, $daysAhead = 30) {
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$daysAhead} days"));
    
    error_log("AUTO-GENERATE: Starting for $startDate to $endDate");
    
    return generateScheduleForDateRange($conn, $startDate, $endDate);
}

/**
 * Get academic bookings for calendar display
 */
function getAcademicBookings($conn, $startDate, $endDate, $roomId = null) {
    $sql = "SELECT b.*, r.nama_ruang, g.nama_gedung, rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            LEFT JOIN tbl_recurring_schedules rs ON b.schedule_id = rs.id_schedule
            WHERE b.tanggal BETWEEN ? AND ?
            AND b.booking_type = 'recurring'
            AND b.status IN ('approve', 'active', 'done')";
    
    $params = [$startDate, $endDate];
    
    if ($roomId) {
        $sql .= " AND b.id_ruang = ?";
        $params[] = $roomId;
    }
    
    $sql .= " ORDER BY b.tanggal, b.jam_mulai";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Helper functions untuk status colors dan icons
 */
function getStatusColor($status) {
    switch ($status) {
        case 'pending': 
            return 'bg-warning text-dark';
        case 'approve': 
            return 'bg-success';
        case 'active': 
            return 'bg-danger';
        case 'done': 
            return 'bg-info';
        case 'cancelled':
        case 'rejected': 
            return 'bg-secondary';
        default: 
            return 'bg-light';
    }
}

function getStatusIcon($status) {
    switch ($status) {
        case 'pending': 
            return '⏳';
        case 'approve': 
            return '✅';
        case 'active': 
            return '🔴';
        case 'done': 
            return '✅';
        case 'cancelled':
        case 'rejected': 
            return '❌';
        default: 
            return '📋';
    }
}

function removeRecurringSchedulesOnHoliday($conn, $holidayDate) {
    try {
        // Cari semua jadwal perkuliahan (recurring) pada tanggal hari libur
        $stmt = $conn->prepare("
            SELECT b.id_booking, b.nama_acara, r.nama_ruang, rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu,
                   b.tanggal, b.jam_mulai, b.jam_selesai
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
            WHERE b.tanggal = ? 
            AND b.booking_type = 'recurring'
            AND b.status IN ('pending', 'approve', 'active')
        ");
        $stmt->execute([$holidayDate]);
        $affectedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($affectedBookings) > 0) {
            // Hapus booking perkuliahan pada hari libur
            $deleteStmt = $conn->prepare("
                DELETE FROM tbl_booking 
                WHERE tanggal = ? 
                AND booking_type = 'recurring'
                AND status IN ('pending', 'approve', 'active')
            ");
            $deleteStmt->execute([$holidayDate]);
            
            $deletedCount = $deleteStmt->rowCount();
            
            // Log detail perubahan untuk setiap jadwal yang dihapus
            foreach ($affectedBookings as $booking) {
                $courseName = $booking['nama_matakuliah'] ?: $booking['nama_acara'];
                $className = $booking['kelas'] ?: '';
                $lecturer = $booking['dosen_pengampu'] ?: '';
                $room = $booking['nama_ruang'];
                $time = formatTime($booking['jam_mulai']) . '-' . formatTime($booking['jam_selesai']);
                
                error_log("REMOVED HOLIDAY SCHEDULE: {$courseName} {$className} oleh {$lecturer} di {$room} jam {$time} pada {$holidayDate}");
            }
            
            // Update log untuk admin
            error_log("HOLIDAY CLEANUP SUCCESS: {$deletedCount} jadwal perkuliahan dihapus dari hari libur {$holidayDate}");
            
            return [
                'success' => true,
                'removed_count' => $deletedCount,
                'affected_bookings' => $affectedBookings,
                'message' => "Berhasil menghapus {$deletedCount} jadwal perkuliahan dari hari libur"
            ];
        }
        
        return [
            'success' => true,
            'removed_count' => 0,
            'affected_bookings' => [],
            'message' => 'Tidak ada jadwal perkuliahan yang perlu dihapus'
        ];
        
    } catch (Exception $e) {
        error_log("Error removing recurring schedules on holiday: " . $e->getMessage());
        return [
            'success' => false,
            'removed_count' => 0,
            'message' => $e->getMessage()
        ];
    }
}

function getCleanupStats($conn) {
    try {
        $today = date('Y-m-d');
        
        // Count hari libur hari ini dan besok
        $stmt = $conn->prepare("
            SELECT COUNT(*) as today_holidays
            FROM tbl_harilibur 
            WHERE tanggal = ?
        ");
        $stmt->execute([$today]);
        $todayHolidays = $stmt->fetchColumn();
        
        // Count jadwal perkuliahan yang aktif hari ini
        $stmt = $conn->prepare("
            SELECT COUNT(*) as active_schedules
            FROM tbl_booking 
            WHERE tanggal = ?
            AND booking_type = 'recurring'
            AND status IN ('approve', 'active')
        ");
        $stmt->execute([$today]);
        $activeSchedules = $stmt->fetchColumn();
        
        // Count slot yang tersedia untuk booking (hari ini)
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT CONCAT(r.id_ruang, '_', hour_slot.hour)) as available_slots
            FROM tbl_ruang r
            CROSS JOIN (
                SELECT '07:00:00' as hour UNION SELECT '08:00:00' UNION SELECT '09:00:00' UNION 
                SELECT '10:00:00' UNION SELECT '11:00:00' UNION SELECT '12:00:00' UNION 
                SELECT '13:00:00' UNION SELECT '14:00:00' UNION SELECT '15:00:00' UNION 
                SELECT '16:00:00' UNION SELECT '17:00:00'
            ) hour_slot
            LEFT JOIN tbl_booking b ON (
                r.id_ruang = b.id_ruang 
                AND b.tanggal = ?
                AND hour_slot.hour >= b.jam_mulai 
                AND hour_slot.hour < b.jam_selesai
                AND b.status NOT IN ('cancelled', 'rejected')
            )
            WHERE b.id_booking IS NULL
        ");
        $stmt->execute([$today]);
        $availableSlots = $stmt->fetchColumn();
        
        return [
            'today_holidays' => $todayHolidays,
            'active_schedules' => $activeSchedules,
            'available_slots' => $availableSlots,
            'cleanup_status' => $todayHolidays > 0 ? 'holiday_mode' : 'normal'
        ];
        
    } catch (Exception $e) {
        error_log("Error getting cleanup stats: " . $e->getMessage());
        return null;
    }
}

function cleanupAcademicSchedulesOnHolidays($conn) {
    try {
        // Get all holidays
        $stmt = $conn->prepare("SELECT tanggal FROM tbl_harilibur ORDER BY tanggal");
        $stmt->execute();
        $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $cleanedCount = 0;
        
        foreach ($holidays as $holiday) {
            // Remove academic bookings on this holiday
            $stmt = $conn->prepare("
                DELETE FROM tbl_booking 
                WHERE tanggal = ? 
                AND booking_type = 'recurring'
                AND status IN ('pending', 'approve')
            ");
            $stmt->execute([$holiday]);
            
            $removedCount = $stmt->rowCount();
            if ($removedCount > 0) {
                $cleanedCount += $removedCount;
                error_log("HOLIDAY CLEANUP: Removed $removedCount academic schedules from $holiday");
            }
        }
        
        return $cleanedCount;
        
    } catch (Exception $e) {
        error_log("Error cleaning up academic schedules: " . $e->getMessage());
        return 0;
    }
}

function runHolidayCleanup($conn) {
    echo "Starting holiday cleanup...\n";
    
    // Clean up existing academic bookings on holidays
    $cleaned = cleanupAcademicSchedulesOnHolidays($conn);
    echo "Cleaned up $cleaned academic bookings from holidays\n";
    
    // Regenerate proper schedules (excluding holidays)
    $stmt = $conn->prepare("SELECT * FROM tbl_recurring_schedules WHERE status = 'active'");
    $stmt->execute();
    $schedules = $stmt->fetchAll();
    
    $totalRegenerated = 0;
    
    foreach ($schedules as $schedule) {
        $scheduleData = [
            'id_ruang' => $schedule['id_ruang'],
            'nama_matakuliah' => $schedule['nama_matakuliah'],
            'kelas' => $schedule['kelas'],
            'dosen_pengampu' => $schedule['dosen_pengampu'],
            'hari' => $schedule['hari'],
            'jam_mulai' => $schedule['jam_mulai'],
            'jam_selesai' => $schedule['jam_selesai'],
            'semester' => $schedule['semester'],
            'tahun_akademik' => $schedule['tahun_akademik'],
            'start_date' => $schedule['start_date'],
            'end_date' => $schedule['end_date'],
            'created_by' => $schedule['created_by']
        ];
        
        $generated = generateBookingsForSchedule($conn, $schedule['id_schedule'], $scheduleData);
        $totalRegenerated += $generated;
    }
    
    echo "Regenerated $totalRegenerated academic bookings (excluding holidays)\n";
    echo "Holiday cleanup completed!\n";
}

function regenerateRecurringSchedulesOnRemovedHoliday($conn, $removedHolidayDate) {
    try {
        // Cari semua recurring schedule yang aktif dan seharusnya ada di tanggal tersebut
        $dayOfWeek = strtolower(date('l', strtotime($removedHolidayDate)));
        $dayMapping = [
            'monday' => 'monday',
            'tuesday' => 'tuesday',
            'wednesday' => 'wednesday', 
            'thursday' => 'thursday',
            'friday' => 'friday',
            'saturday' => 'saturday',
            'sunday' => 'sunday'
        ];
        
        $targetDay = $dayMapping[$dayOfWeek] ?? null;
        
        if (!$targetDay) {
            return ['success' => false, 'message' => 'Invalid day of week'];
        }
        
        // Cari recurring schedule yang seharusnya ada di hari tersebut
        $stmt = $conn->prepare("
            SELECT rs.*, u.id_user as system_user_id
            FROM tbl_recurring_schedules rs
            JOIN tbl_users u ON u.email = 'system@stie-mce.ac.id'
            WHERE rs.hari = ?
            AND rs.status = 'active'
            AND ? BETWEEN rs.start_date AND rs.end_date
        ");
        $stmt->execute([$targetDay, $removedHolidayDate]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $generatedCount = 0;
        
        foreach ($schedules as $schedule) {
            // Cek apakah sudah ada booking untuk schedule ini di tanggal tersebut
            $checkStmt = $conn->prepare("
                SELECT COUNT(*) FROM tbl_booking 
                WHERE tanggal = ? AND id_schedule = ?
            ");
            $checkStmt->execute([$removedHolidayDate, $schedule['id_schedule']]);
            $exists = $checkStmt->fetchColumn() > 0;
            
            if (!$exists) {
                // Cek konflik dengan booking lain
                if (!hasBookingConflict($conn, $schedule['id_ruang'], $removedHolidayDate, 
                                      $schedule['jam_mulai'], $schedule['jam_selesai'])) {
                    
                    // Generate booking
                    $insertStmt = $conn->prepare("
                        INSERT INTO tbl_booking 
                        (id_user, id_ruang, tanggal, jam_mulai, jam_selesai, nama_acara, keterangan, 
                         nama_penanggungjawab, no_penanggungjawab, status, booking_type, id_schedule,
                         approved_at, approved_by, auto_generated) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approve', 'recurring', ?, NOW(), 'SYSTEM_AUTO', 1)
                    ");
                    
                    $eventName = $schedule['nama_matakuliah'] . ' - ' . $schedule['kelas'];
                    $description = 'Jadwal Perkuliahan ' . $schedule['semester'] . ' ' . $schedule['tahun_akademik'] . 
                                  ' - Dosen: ' . $schedule['dosen_pengampu'];
                    
                    $result = $insertStmt->execute([
                        $schedule['system_user_id'],
                        $schedule['id_ruang'],
                        $removedHolidayDate,
                        $schedule['jam_mulai'],
                        $schedule['jam_selesai'],
                        $eventName,
                        $description,
                        $schedule['dosen_pengampu'],
                        0,
                        $schedule['id_schedule']
                    ]);
                    
                    if ($result) {
                        $generatedCount++;
                        error_log("REGENERATED: $eventName on $removedHolidayDate");
                    }
                }
            }
        }
        
        return [
            'success' => true,
            'generated_count' => $generatedCount
        ];
        
    } catch (Exception $e) {
        error_log("Error regenerating schedules: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function removeDuplicateRecurringBookings($conn) {
    try {
        $stmt = $conn->prepare("
            DELETE b1 FROM tbl_booking b1
            INNER JOIN tbl_booking b2 
            WHERE b1.id_booking > b2.id_booking
            AND b1.id_ruang = b2.id_ruang
            AND b1.tanggal = b2.tanggal
            AND b1.jam_mulai = b2.jam_mulai
            AND b1.jam_selesai = b2.jam_selesai
            AND b1.booking_type = 'recurring'
            AND b2.booking_type = 'recurring'
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
        
    } catch (Exception $e) {
        error_log("Duplicate cleanup error: " . $e->getMessage());
        return 0;
    }
}

function addHolidayWithAutoCleanup($conn, $date, $description) {
    try {
        $conn->beginTransaction();
        
        // Tambah hari libur
        $stmt = $conn->prepare("
            INSERT INTO tbl_harilibur (tanggal, keterangan) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE keterangan = VALUES(keterangan)
        ");
        $result = $stmt->execute([$date, $description]);
        
        if ($result) {
            // Auto-cleanup jadwal perkuliahan pada hari libur ini
            $cleanupResult = removeRecurringSchedulesOnHoliday($conn, $date);
            
            $conn->commit();
            
            $message = "Hari libur '{$description}' berhasil ditambahkan untuk tanggal " . formatDate($date);
            if ($cleanupResult['removed_count'] > 0) {
                $message .= ". {$cleanupResult['removed_count']} jadwal perkuliahan otomatis dihapus, slot kini tersedia untuk booking lain.";
            } else {
                $message .= ". Tidak ada jadwal perkuliahan yang terpengaruh.";
            }
            
            return [
                'success' => true,
                'message' => $message,
                'cleanup_result' => $cleanupResult
            ];
        } else {
            $conn->rollBack();
            return [
                'success' => false,
                'message' => 'Gagal menambahkan hari libur.'
            ];
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error in addHolidayWithAutoCleanup: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Enhanced holiday deletion dengan regenerasi schedule
 */
function deleteHolidayWithScheduleRegen($conn, $date) {
    try {
        $conn->beginTransaction();
        
        // Hapus hari libur
        $stmt = $conn->prepare("DELETE FROM tbl_harilibur WHERE tanggal = ?");
        $result = $stmt->execute([$date]);
        
        if ($result) {
            // Regenerate recurring schedules jika perlu
            $regenResult = regenerateRecurringSchedulesOnRemovedHoliday($conn, $date);
            
            $conn->commit();
            
            return [
                'success' => true,
                'message' => 'Hari libur berhasil dihapus.',
                'regen_result' => $regenResult
            ];
        } else {
            $conn->rollBack();
            return [
                'success' => false,
                'message' => 'Gagal menghapus hari libur.'
            ];
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Enhanced function untuk menambahkan display properties ke booking
 */
function addBookingDisplayProperties(&$bookings) {
    foreach ($bookings as &$booking) {
        switch ($booking['status']) {
            case 'pending':
                $booking['display_class'] = 'bg-warning text-dark';
                $booking['display_icon'] = '📋';
                $booking['display_text'] = 'Pending';
                break;
            case 'approve':
                $booking['display_class'] = 'bg-success text-white';
                $booking['display_icon'] = '✅';
                $booking['display_text'] = 'Approved';
                break;
            case 'active':
                $booking['display_class'] = 'bg-danger text-white';
                $booking['display_icon'] = '🔴';
                $booking['display_text'] = 'Ongoing';
                break;
            case 'done':
                $booking['display_class'] = 'bg-info text-white';
                $booking['display_icon'] = '✅';
                $booking['display_text'] = 'Selesai';
                break;
            case 'cancelled':
                $booking['display_class'] = 'bg-secondary text-white';
                $booking['display_icon'] = '❌';
                $booking['display_text'] = 'Dibatalkan';
                break;
            case 'rejected':
                $booking['display_class'] = 'bg-secondary text-white';
                $booking['display_icon'] = '❌';
                $booking['display_text'] = 'Ditolak';
                break;
            default:
                $booking['display_class'] = 'bg-light text-dark';
                $booking['display_icon'] = '📋';
                $booking['display_text'] = ucfirst($booking['status']);
        }
    }
}

?>