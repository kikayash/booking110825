<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is CS
if (!isLoggedIn() || !isCS()) {
    header('Location: ../index.php');
    exit;
}

// Get filter parameters
$semester_filter = $_GET['semester'] ?? 'all';
$tahun_filter = $_GET['tahun'] ?? 'all';
$focus_date = $_GET['focus_date'] ?? '';

// Get available semesters and years
$semesterQuery = "SELECT DISTINCT semester, tahun_akademik FROM tbl_recurring_schedules WHERE status = 'active' ORDER BY tahun_akademik DESC, semester DESC";
$stmt = $conn->prepare($semesterQuery);
$stmt->execute();
$availableFilters = $stmt->fetchAll();

// Build schedule query with filters
$scheduleWhere = "WHERE rs.status = 'active'";
$scheduleParams = [];

if ($semester_filter !== 'all') {
    $scheduleWhere .= " AND rs.semester = ?";
    $scheduleParams[] = $semester_filter;
}

if ($tahun_filter !== 'all') {
    $scheduleWhere .= " AND rs.tahun_akademik = ?";
    $scheduleParams[] = $tahun_filter;
}

// Enhanced: Get dosen bookings that need CS coordination (belum ditangani)
$dosenBookingsQuery = "SELECT b.*, 
                              COALESCE(u.email, b.email_dosen, 'unknown@stie-mce.ac.id') as email, 
                              COALESCE(u.role, 'dosen') as role, 
                              r.nama_ruang, g.nama_gedung, 
                              rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu, rs.hari,
                              rs.jam_mulai as original_start, rs.jam_selesai as original_end, rs.id_schedule,
                              b.created_at as request_time, b.approved_at,
                              COALESCE(b.nama_dosen, b.nama, 'Unknown') as nama_penanggungjawab,
                              COALESCE(b.no_penanggungjawab, 0) as no_penanggungjawab
                       FROM tbl_booking b
                       LEFT JOIN tbl_users u ON b.id_user = u.id_user
                       JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                       LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                       LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
                       WHERE b.status = 'approve' 
                         AND (
                             (u.role = 'dosen') OR 
                             (b.user_type = 'dosen_iris') OR
                             (b.nama_dosen IS NOT NULL) OR
                             (b.email_dosen IS NOT NULL)
                         )
                         AND b.auto_approved = 1
                         AND COALESCE(b.cs_handled, 0) = 0
                         AND b.tanggal >= CURDATE()
                       ORDER BY b.tanggal ASC, b.jam_mulai ASC";

$stmt = $conn->prepare($dosenBookingsQuery);
$stmt->execute();
$dosenBookings = $stmt->fetchAll();

// Get original schedules with filters and exception info
$originalSchedulesQuery = "SELECT rs.*, r.nama_ruang, g.nama_gedung,
                          GROUP_CONCAT(DISTINCT se.exception_date ORDER BY se.exception_date) as cancelled_dates,
                          COUNT(se.id_exception) as exception_count
                          FROM tbl_recurring_schedules rs
                          JOIN tbl_ruang r ON rs.id_ruang = r.id_ruang
                          LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                          LEFT JOIN tbl_schedule_exceptions se ON rs.id_schedule = se.id_schedule
                          $scheduleWhere
                          AND rs.id_schedule > 0  -- Tambahkan filter ini
                          GROUP BY rs.id_schedule
                          ORDER BY rs.tahun_akademik DESC, rs.semester DESC, rs.hari, rs.jam_mulai";

$stmt = $conn->prepare($originalSchedulesQuery);
$stmt->execute($scheduleParams);
$originalSchedules = $stmt->fetchAll();

// Get pending dosen requests
$pendingDosenQuery = "SELECT b.*, u.email, r.nama_ruang, g.nama_gedung, rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu
                      FROM tbl_booking b
                      JOIN tbl_users u ON b.id_user = u.id_user
                      JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                      LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                      LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
                      WHERE b.status = 'pending' AND u.role = 'dosen'
                      AND b.tanggal >= CURDATE()
                      ORDER BY b.created_at ASC";

$stmt = $conn->prepare($pendingDosenQuery);
$stmt->execute();
$pendingDosenRequests = $stmt->fetchAll();

$message = '';
$alertType = '';

// Enhanced: Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'mark_handled') {
            $bookingId = $_POST['booking_id'];
            
            // Mark as handled by CS
            $stmt = $conn->prepare("UPDATE tbl_booking SET 
                                   cs_handled = 1, 
                                   cs_handled_at = NOW(), 
                                   cs_handled_by = ?,
                                   approval_reason = CONCAT(COALESCE(approval_reason, ''), ' | Ditangani CS: ', NOW())
                                   WHERE id_booking = ?");
            $stmt->execute([$_SESSION['user_id'], $bookingId]);
            
            // Log CS action
            $stmt = $conn->prepare("INSERT INTO tbl_cs_actions (cs_user_id, action_type, target_id, description) 
                                   VALUES (?, 'handle_dosen_booking', ?, 'CS handled dosen booking coordination from schedule management')");
            $stmt->execute([$_SESSION['user_id'], $bookingId]);
            
            $conn->commit();
            $message = "✅ Booking ditandai sudah ditangani oleh CS.";
            $alertType = 'success';
            
        } elseif ($action === 'delete_schedule') {
            error_log("DELETE SCHEDULE DEBUG:");
            error_log("POST data: " . print_r($_POST, true));
            
            $scheduleId = $_POST['schedule_id'] ?? '';
            $deleteDate = $_POST['delete_date'] ?? '';
            $reason = $_POST['delete_reason'] ?? 'Dihapus oleh CS untuk perubahan jadwal dosen';
            $bookingId = $_POST['booking_id'] ?? 0;
            
            error_log("Extracted values:");
            error_log("scheduleId: '" . $scheduleId . "'");
            error_log("deleteDate: '" . $deleteDate . "'");
            error_log("bookingId: '" . $bookingId . "'");
            
            // Enhanced validation
            if (empty($scheduleId) || $scheduleId === '0' || $scheduleId === 0) {
                throw new Exception("Schedule ID tidak valid atau kosong. Nilai diterima: '$scheduleId'");
            }
            
            if (empty($deleteDate)) {
                throw new Exception("Tanggal penghapusan tidak boleh kosong. Nilai diterima: '$deleteDate'");
            }
            
            // Validasi format tanggal
            $dateObj = DateTime::createFromFormat('Y-m-d', $deleteDate);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $deleteDate) {
                throw new Exception("Format tanggal tidak valid. Harus YYYY-MM-DD. Nilai diterima: '$deleteDate'");
            }
            
            // Validasi tanggal tidak boleh masa lalu
            if ($dateObj < new DateTime('today')) {
                throw new Exception("Tidak dapat menghapus jadwal untuk tanggal yang sudah berlalu");
            }
            
            $dayOfWeek = strtolower($dateObj->format('l'));
            
            // Get original schedule info
            $stmt = $conn->prepare("SELECT * FROM tbl_recurring_schedules WHERE id_schedule = ?");
            $stmt->execute([$scheduleId]);
            $originalSchedule = $stmt->fetch();
            
            if (!$originalSchedule) {
                throw new Exception("Jadwal dengan ID '$scheduleId' tidak ditemukan di database");
            }
            
            error_log("Found schedule: " . print_r($originalSchedule, true));
            
            // Validasi hari sesuai
            if ($originalSchedule['hari'] !== $dayOfWeek) {
                throw new Exception("Tanggal yang dipilih ($dayOfWeek) tidak sesuai dengan hari jadwal kuliah (" . $originalSchedule['hari'] . ")");
            }
            
            // Check if already cancelled for this date
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_schedule_exceptions WHERE id_schedule = ? AND exception_date = ?");
            $stmt->execute([$scheduleId, $deleteDate]);
            if ($stmt->fetch()['count'] > 0) {
                throw new Exception("Jadwal untuk tanggal tersebut sudah pernah dihapus sebelumnya");
            }
            
            // ===== KUNCI UTAMA: HANYA INSERT EXCEPTION, JANGAN DELETE SCHEDULE ASLI =====
            
            // 1. Insert schedule exception untuk tanggal tertentu
            $stmt = $conn->prepare("INSERT INTO tbl_schedule_exceptions 
                                (id_schedule, exception_date, exception_type, reason, created_by, created_at) 
                                VALUES (?, ?, 'cancelled_by_cs', ?, ?, NOW())");
            $stmt->execute([$scheduleId, $deleteDate, $reason, $_SESSION['user_id']]);
            
            error_log("✅ Exception inserted for date: $deleteDate");
            
            // 2. Cancel booking yang auto-generated untuk tanggal tersebut (jika ada)
            $stmt = $conn->prepare("UPDATE tbl_booking SET 
                                status = 'cancelled', 
                                cancelled_by = ?, 
                                cancelled_at = NOW(),
                                cancellation_reason = ?
                                WHERE id_schedule = ? 
                                AND tanggal = ? 
                                AND auto_generated = 1
                                AND status IN ('pending', 'approve')");
            $stmt->execute([$_SESSION['email'], $reason, $scheduleId, $deleteDate]);
            
            $cancelledBookings = $stmt->rowCount();
            error_log("✅ Cancelled $cancelledBookings auto-generated bookings for date: $deleteDate");
            
            // 3. Log CS action
            $stmt = $conn->prepare("INSERT INTO tbl_cs_actions (cs_user_id, action_type, target_id, description) 
                                VALUES (?, 'cancel_schedule_date', ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $scheduleId, "Cancelled schedule for $deleteDate: $reason"]);
            
            // 4. Mark replacement booking as handled if provided
            if ($bookingId > 0) {
                $stmt = $conn->prepare("UPDATE tbl_booking SET 
                                    cs_handled = 1, 
                                    cs_handled_at = NOW(), 
                                    cs_handled_by = ?
                                    WHERE id_booking = ?");
                $stmt->execute([$_SESSION['user_id'], $bookingId]);
                error_log("✅ Marked booking $bookingId as handled");
            }
            
            $conn->commit();
            
            $message = "✅ Jadwal kuliah {$originalSchedule['nama_matakuliah']} ({$originalSchedule['kelas']}) berhasil dihapus untuk tanggal " . formatDate($deleteDate) . " saja. Jadwal di hari lain tetap normal.";
            $alertType = 'success';
            
            error_log("✅ SUCCESS: Schedule exception created for single date only");
        } elseif ($action === 'approve_dosen_request') {
            $bookingId = $_POST['booking_id'];
            $approvalNote = $_POST['approval_note'] ?? 'Disetujui oleh CS';
            
            // Approve the booking
            $stmt = $conn->prepare("UPDATE tbl_booking SET 
                                   status = 'approve', 
                                   approved_at = NOW(), 
                                   approved_by = ?, 
                                   approval_reason = ?
                                   WHERE id_booking = ? AND status = 'pending'");
            $stmt->execute([$_SESSION['email'], $approvalNote, $bookingId]);
            
            if ($stmt->rowCount() > 0) {
                // Log CS action
                $stmt = $conn->prepare("INSERT INTO tbl_cs_actions (cs_user_id, action_type, target_id, description) 
                                       VALUES (?, 'approve_booking', ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $bookingId, "Approved dosen booking request: $approvalNote"]);
                
                $message = "✅ Booking dosen berhasil disetujui.";
                $alertType = 'success';
            } else {
                throw new Exception("Booking tidak ditemukan atau sudah diproses");
            }
            
            $conn->commit();
            
        } elseif ($action === 'reject_dosen_request') {
            $bookingId = $_POST['booking_id'];
            $rejectionNote = $_POST['rejection_note'] ?? 'Ditolak oleh CS';
            
            // Reject the booking
            $stmt = $conn->prepare("UPDATE tbl_booking SET 
                                   status = 'rejected', 
                                   rejected_at = NOW(), 
                                   rejected_by = ?, 
                                   rejection_reason = ?
                                   WHERE id_booking = ? AND status = 'pending'");
            $stmt->execute([$_SESSION['email'], $rejectionNote, $bookingId]);
            
            if ($stmt->rowCount() > 0) {
                // Log CS action
                $stmt = $conn->prepare("INSERT INTO tbl_cs_actions (cs_user_id, action_type, target_id, description) 
                                       VALUES (?, 'reject_booking', ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $bookingId, "Rejected dosen booking request: $rejectionNote"]);
                
                $message = "❌ Booking dosen telah ditolak.";
                $alertType = 'warning';
            } else {
                throw new Exception("Booking tidak ditemukan atau sudah diproses");
            }
            
            $conn->commit();
        }
        
        // Refresh data after actions
        $stmt = $conn->prepare($dosenBookingsQuery);
        $stmt->execute();
        $dosenBookings = $stmt->fetchAll();
        
        $stmt = $conn->prepare($pendingDosenQuery);
        $stmt->execute();
        $pendingDosenRequests = $stmt->fetchAll();
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        $message = "❌ Error: " . $e->getMessage();
        $alertType = 'danger';
    }
}

// Get day names mapping
$dayNames = [
    'monday' => 'Senin',
    'tuesday' => 'Selasa', 
    'wednesday' => 'Rabu',
    'thursday' => 'Kamis',
    'friday' => 'Jumat',
    'saturday' => 'Sabtu',
    'sunday' => 'Minggu'
];

// Group schedules by semester/year for better display
$groupedSchedules = [];
foreach ($originalSchedules as $schedule) {
    $key = $schedule['tahun_akademik'] . ' - ' . $schedule['semester'];
    $groupedSchedules[$key][] = $schedule;
}

// Function to get next occurrence of a day
function getNextDateForDay($dayName, $startDate = null) {
    $startDate = $startDate ?: date('Y-m-d');
    $targetDay = ucfirst(strtolower($dayName));
    
    // Convert to timestamp
    $timestamp = strtotime("next $targetDay", strtotime($startDate));
    
    // If today is the target day and we haven't passed it yet, use today
    if (date('l') === $targetDay && date('Y-m-d') === $startDate) {
        return $startDate;
    }
    
    return date('Y-m-d', $timestamp);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Jadwal Dosen - CS STIE MCE (Enhanced)</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        /* Enhanced styling for better UX */
        .list-group-item i {
            display: inline-block !important;
            width: 20px;
            text-align: center;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .sidebar-cs .list-group-item {
            padding: 12px 20px;
            border: none;
            color: #333;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .sidebar-cs .list-group-item:hover {
            background-color: #f8f9fa;
            color: #e91e63;
            padding-left: 25px;
        }
        
        .sidebar-cs .list-group-item.active {
            background-color: #e91e63;
            color: white;
            border-left: 4px solid #ad1457;
        }
        
        .notification-badge {
            background: #dc3545;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .cs-stat-icon-primary {
            background: linear-gradient(135deg, #e91e63, #ad1457);
            color: white;
        }
        
        .priority-card {
            border-left: 5px solid #28a745;
            background: linear-gradient(135deg, #f0fff4, #ffffff);
            transition: all 0.3s ease;
        }
        
        .priority-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
        }
        
        .schedule-card {
            transition: all 0.3s ease;
        }
        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .status-approved {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        .focus-highlight {
            animation: focusPulse 3s ease-in-out;
            border: 2px solid #ffc107 !important;
        }
        @keyframes focusPulse {
            0%, 100% { border-color: #ffc107; }
            50% { border-color: #ff6b35; }
        }
        .semester-badge {
            background: linear-gradient(135deg, #6f42c1, #e83e8c);
            color: white;
            padding: 5px 15px;
            border-radius: 25px;
            font-weight: bold;
            margin: 5px;
            display: inline-block;
        }
        
        .original-schedule-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 12px;
            margin: 10px 0;
        }
        
        .replacement-schedule-info {
            background: #d1ecf1;
            border: 1px solid #b8daff;
            border-radius: 8px;
            padding: 12px;
            margin: 10px 0;
        }
        
        .delete-schedule-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        
        .delete-schedule-btn:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        
        .exception-badge {
            background: #ffc107;
            color: #000;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        .handled-item {
            opacity: 0.7;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }
        
        .pending-urgent {
            border-left: 4px solid #ff6b35;
            background: rgba(255, 107, 53, 0.1);
            animation: urgentPulse 2s infinite;
        }
        
        @keyframes urgentPulse {
            0%, 100% { border-left-color: #ff6b35; }
            50% { border-left-color: #ff9800; }
        }
    </style>
</head>
<body class="cs-theme">
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- CS Sidebar -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow dashboard-sidebar sidebar-cs">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-headset me-2"></i>Menu CS</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard CS
                            <?php if (count($dosenBookings) > 0): ?>
                                <span class="notification-badge"><?= count($dosenBookings) ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="add-booking.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="today_rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-day"></i>Ruangan Hari Ini
                        </a>
                        <a href="schedule_management.php" class="list-group-item list-group-item-action active">
                            <i class="fa-solid fa-calendar-days"></i> Kelola Jadwal Dosen
                            <?php if (count($dosenBookings) > 0 || count($pendingDosenRequests) > 0): ?>
                                <span class="badge bg-success ms-2"><?= count($dosenBookings) + count($pendingDosenRequests) ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Enhanced: Pending Dosen Requests (Highest Priority) -->
                <?php if (count($pendingDosenRequests) > 0): ?>
                <div class="card shadow mb-4 pending-urgent">
                    <div class="card-header" style="background: linear-gradient(135deg, #ff6b35, #ff9800); color: white;">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            🚨 URGENT: Perubahan Jadwal Dosen Menunggu Persetujuan
                            <span class="badge bg-light text-dark ms-2"><?= count($pendingDosenRequests) ?> pending</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-clock me-2"></i>
                            <strong>Prioritas Tertinggi:</strong> Dosen meminta perubahan jadwal dan menunggu persetujuan CS.
                        </div>
                        
                        <?php foreach ($pendingDosenRequests as $request): ?>
                            <div class="card mb-3 border-warning">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <h6 class="text-warning">
                                                <i class="fas fa-hourglass-half me-1"></i>
                                                <?= htmlspecialchars($request['nama_acara']) ?>
                                                <?php if ($request['nama_matakuliah']): ?>
                                                    <span class="badge bg-info ms-2"><?= htmlspecialchars($request['nama_matakuliah']) ?> - <?= htmlspecialchars($request['kelas']) ?></span>
                                                <?php endif; ?>
                                            </h6>
                                            <p class="mb-2">
                                                <i class="fas fa-user me-1"></i><strong>Dosen:</strong> <?= htmlspecialchars($request['email']) ?><br>
                                                <i class="fas fa-door-open me-1"></i><strong>Ruangan:</strong> <?= htmlspecialchars($request['nama_ruang']) ?><br>
                                                <i class="fas fa-calendar me-1"></i><strong>Jadwal yang Diminta:</strong> <?= formatDate($request['tanggal']) ?>, <?= formatTime($request['jam_mulai']) ?> - <?= formatTime($request['jam_selesai']) ?><br>
                                                <i class="fas fa-user-tie me-1"></i><strong>PIC:</strong> <?= htmlspecialchars($request['nama_penanggungjawab']) ?> (<?= htmlspecialchars($request['no_penanggungjawab']) ?>)
                                            </p>
                                            <div class="mt-2">
                                                <strong>📝 Alasan:</strong><br>
                                                <em><?= nl2br(htmlspecialchars($request['keterangan'])) ?></em>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>Diminta: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                            </small>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="d-grid gap-2">
                                                <button class="btn btn-success" 
                                                        onclick="showApprovalModal(<?= $request['id_booking'] ?>, '<?= htmlspecialchars($request['nama_acara']) ?>')">
                                                    <i class="fas fa-check me-1"></i>Setujui
                                                </button>
                                                <button class="btn btn-danger" 
                                                        onclick="showRejectionModal(<?= $request['id_booking'] ?>, '<?= htmlspecialchars($request['nama_acara']) ?>')">
                                                    <i class="fas fa-times me-1"></i>Tolak
                                                </button>
                                                <a href="mailto:<?= htmlspecialchars($request['email']) ?>" class="btn btn-outline-primary">
                                                    <i class="fas fa-envelope me-1"></i>Email
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Enhanced: Dosen Auto-Approved Bookings -->
                <?php if (count($dosenBookings) > 0): ?>
                <div class="card shadow mb-4" style="border-left: 4px solid #28a745;">
                    <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                        <h5 class="mb-0">
                            <i class="fas fa-chalkboard-teacher me-2"></i>
                            Booking Dosen Auto-Approved - Perlu Koordinasi CS
                            <span class="badge bg-light text-dark ms-2"><?= count($dosenBookings) ?> booking</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-success">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Tugas CS:</strong> Koordinasi dengan dosen dan kelola jadwal asli jika diperlukan.
                        </div>
                        
                        <?php foreach ($dosenBookings as $booking): ?>
                            <div class="card priority-card schedule-card mb-4 <?= $focus_date && $booking['tanggal'] === $focus_date ? 'focus-highlight' : '' ?>">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-3">
                                                <h5 class="text-success mb-0 me-3">
                                                    <i class="fas fa-check-circle me-1"></i>
                                                    <?= htmlspecialchars($booking['nama_acara']) ?>
                                                </h5>
                                                <span class="status-approved">AUTO-APPROVED</span>
                                            </div>
                                            
                                            <!-- Enhanced: Schedule Comparison -->
                                            <?php if ($booking['nama_matakuliah'] && $booking['id_schedule']): ?>
                                                <div class="row mb-3">
                                                    <div class="col-md-6">
                                                        <div class="original-schedule-info">
                                                            <h6 class="text-warning mb-2">
                                                                <i class="fas fa-calendar-times me-1"></i>Jadwal Asli
                                                            </h6>
                                                            <p class="mb-1"><strong>📚 Mata Kuliah:</strong> <?= htmlspecialchars($booking['nama_matakuliah']) ?> - <?= htmlspecialchars($booking['kelas']) ?></p>
                                                            <p class="mb-1"><strong>📅 Hari:</strong> <?= $dayNames[$booking['hari']] ?? ucfirst($booking['hari']) ?></p>
                                                            <p class="mb-1"><strong>⏰ Waktu:</strong> <?= date('H:i', strtotime($booking['original_start'])) ?> - <?= date('H:i', strtotime($booking['original_end'])) ?></p>
                                                            <p class="mb-0"><strong>👨‍🏫 Dosen:</strong> <?= htmlspecialchars($booking['dosen_pengampu']) ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="replacement-schedule-info">
                                                            <h6 class="text-info mb-2">
                                                                <i class="fas fa-calendar-plus me-1"></i>Jadwal Pengganti
                                                            </h6>
                                                            <p class="mb-1"><strong>📅 Tanggal:</strong> <?= formatDate($booking['tanggal']) ?></p>
                                                            <p class="mb-1"><strong>⏰ Waktu:</strong> <?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?></p>
                                                            <p class="mb-1"><strong>📍 Ruangan:</strong> <?= htmlspecialchars($booking['nama_ruang']) ?></p>
                                                            <p class="mb-0"><strong>📞 PIC:</strong> <?= htmlspecialchars($booking['nama_penanggungjawab']) ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <th width="20%">📧 Email Dosen:</th>
                                                    <td><?= htmlspecialchars($booking['email']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>🤖 Auto-Approved:</th>
                                                    <td><?= date('d/m/Y H:i', strtotime($booking['approved_at'])) ?> WIB</td>
                                                </tr>
                                            </table>
                                            
                                            <div class="mt-2">
                                                <strong>📝 Keterangan:</strong><br>
                                                <em><?= nl2br(htmlspecialchars($booking['keterangan'])) ?></em>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="d-grid gap-2">
                                                <!-- Enhanced: Delete Original Schedule Button -->
                                                <?php if ($booking['id_schedule'] && intval($booking['id_schedule']) > 0): ?>
                                                <?php 
                                                $nextOriginalDate = getNextDateForDay($booking['hari'], $booking['tanggal']);
                                                ?>
                                                <button class="btn delete-schedule-btn" 
                                                        onclick="showDeleteScheduleModal(
                                                            <?= intval($booking['id_booking']) ?>, 
                                                            <?= intval($booking['id_schedule']) ?>, 
                                                            '<?= htmlspecialchars($booking['nama_matakuliah'], ENT_QUOTES) ?>', 
                                                            '<?= htmlspecialchars($booking['kelas'], ENT_QUOTES) ?>', 
                                                            '<?= $nextOriginalDate ?>', 
                                                            '<?= $dayNames[$booking['hari']] ?? ucfirst($booking['hari']) ?>'
                                                        )"
                                                        title="Hapus jadwal asli untuk koordinasi"
                                                        data-schedule-id="<?= $booking['id_schedule'] ?>"
                                                        data-booking-id="<?= $booking['id_booking'] ?>">
                                                    <i class="fas fa-calendar-times me-2"></i>Hapus Jadwal <?= $dayNames[$booking['hari']] ?? ucfirst($booking['hari']) ?>
                                                    <br><small><?= formatDate($nextOriginalDate) ?></small>
                                                </button>
                                            <?php else: ?>
                                                <div class="alert alert-warning">
                                                    <small>⚠️ Schedule ID tidak tersedia untuk booking ini</small>
                                                </div>
                                            <?php endif; ?>
                                                <!-- Untuk jadwal kuliah reguler di tabel -->
                                                    <button class="btn btn-outline-danger btn-sm" 
                                                            onclick="showDeleteScheduleModal(
                                                                0, 
                                                                <?= intval($schedule['id_schedule']) ?>, 
                                                                '<?= addslashes($schedule['nama_matakuliah']) ?>', 
                                                                '<?= addslashes($schedule['kelas']) ?>', 
                                                                '', 
                                                                '<?= $dayNames[$schedule['hari']] ?? ucfirst($schedule['hari']) ?>'
                                                            )"
                                                            data-schedule-id="<?= $schedule['id_schedule'] ?>">
                                                        <i class="fas fa-calendar-times me-1"></i>Hapus Hari Tertentu
                                                    </button>
                                                    <br><small class="text-muted">ID: <?= $schedule['id_schedule'] ?></small>
                                                
                                                <!-- Enhanced: Mark as Handled Button -->
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="mark_handled">
                                                    <input type="hidden" name="booking_id" value="<?= $booking['id_booking'] ?>">
                                                    <button type="submit" class="btn btn-success w-100" 
                                                            onclick="return confirm('Tandai booking ini sudah ditangani?\n\n✓ Booking akan hilang dari daftar prioritas\n✓ Jadwal pengganti tetap aktif\n✓ Status akan tercatat di sistem')">
                                                        <i class="fas fa-check me-1"></i>Sudah Ditangani
                                                    </button>
                                                </form>
                                                
                                                <!-- Enhanced: Email Dosen Button -->
                                                <a href="mailto:<?= htmlspecialchars($booking['email']) ?>?subject=Konfirmasi Perubahan Jadwal - <?= htmlspecialchars($booking['nama_matakuliah']) ?>&body=Yth. <?= htmlspecialchars($booking['dosen_pengampu']) ?>,%0A%0ABooking pengganti Anda telah disetujui:%0A- Mata Kuliah: <?= htmlspecialchars($booking['nama_matakuliah']) ?> (<?= htmlspecialchars($booking['kelas']) ?>)%0A- Tanggal: <?= formatDate($booking['tanggal']) ?>%0A- Waktu: <?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>%0A- Ruangan: <?= htmlspecialchars($booking['nama_ruang']) ?>%0A%0AMohon konfirmasi:%0A1. Apakah jadwal asli hari <?= $dayNames[$booking['hari']] ?? ucfirst($booking['hari']) ?> perlu dihapus?%0A2. Apakah ada mahasiswa yang perlu diberitahu?%0A%0ATerima kasih,%0ACustomer Service STIE MCE" 
                                                   class="btn btn-outline-primary">
                                                    <i class="fas fa-envelope me-1"></i>Email Dosen
                                                </a>
                                                
                                                <a href="tel:<?= htmlspecialchars($booking['no_penanggungjawab']) ?>" 
                                                   class="btn btn-outline-success">
                                                    <i class="fas fa-phone me-1"></i>Telepon PIC
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- No Priority Items -->
                <?php if (count($dosenBookings) == 0 && count($pendingDosenRequests) == 0): ?>
                <div class="card shadow mb-4" style="border-left: 4px solid #17a2b8;">
                    <div class="card-header" style="background: linear-gradient(135deg, #17a2b8, #007bff); color: white;">
                        <h5 class="mb-0">
                            <i class="fas fa-check-circle me-2"></i>
                            Status: Semua Terkendali ✨
                        </h5>
                    </div>
                    <div class="card-body text-center py-5">
                        <i class="fas fa-thumbs-up fa-4x text-success mb-3"></i>
                        <h4>Excellent Work! 🎉</h4>
                        <p class="text-muted mb-4">Tidak ada booking dosen yang memerlukan koordinasi CS saat ini.</p>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <i class="fas fa-calendar-check fa-2x text-primary mb-2"></i>
                                        <h6>Semua Booking Terkendali</h6>
                                        <small class="text-muted">Dosen bookings sudah ditangani</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <i class="fas fa-clock fa-2x text-success mb-2"></i>
                                        <h6>Tidak Ada Pending</h6>
                                        <small class="text-muted">Tidak ada request menunggu</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <i class="fas fa-shield-alt fa-2x text-info mb-2"></i>
                                        <h6>Sistem Optimal</h6>
                                        <small class="text-muted">Ready untuk request berikutnya</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Filter Section -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-filter me-2"></i>Filter Semester & Tahun Akademik
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Semester:</label>
                                <select class="form-select" name="semester">
                                    <option value="all" <?= $semester_filter === 'all' ? 'selected' : '' ?>>Semua Semester</option>
                                    <option value="Ganjil" <?= $semester_filter === 'Ganjil' ? 'selected' : '' ?>>Ganjil</option>
                                    <option value="Genap" <?= $semester_filter === 'Genap' ? 'selected' : '' ?>>Genap</option>
                                    <option value="Pendek" <?= $semester_filter === 'Pendek' ? 'selected' : '' ?>>Pendek</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tahun Akademik:</label>
                                <select class="form-select" name="tahun">
                                    <option value="all" <?= $tahun_filter === 'all' ? 'selected' : '' ?>>Semua Tahun</option>
                                    <?php 
                                    $years = array_unique(array_column($availableFilters, 'tahun_akademik'));
                                    rsort($years);
                                    foreach ($years as $year): 
                                    ?>
                                        <option value="<?= $year ?>" <?= $tahun_filter === $year ? 'selected' : '' ?>>
                                            <?= $year ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-info me-2">
                                    <i class="fas fa-search me-1"></i>Filter
                                </button>
                                <a href="schedule_management.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo me-1"></i>Reset
                                </a>
                            </div>
                        </form>
                        
                        <!-- Active Filter Display -->
                        <?php if ($semester_filter !== 'all' || $tahun_filter !== 'all'): ?>
                            <div class="mt-3">
                                <strong>Filter Aktif:</strong>
                                <?php if ($semester_filter !== 'all'): ?>
                                    <span class="semester-badge"><?= $semester_filter ?></span>
                                <?php endif; ?>
                                <?php if ($tahun_filter !== 'all'): ?>
                                    <span class="semester-badge"><?= $tahun_filter ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Current Schedules Reference (Grouped) -->
                <div class="card shadow">
                    <div class="card-header bg-secondary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-calendar-week me-2"></i>Jadwal Kuliah Aktif (Referensi)
                            <?php if ($semester_filter !== 'all' || $tahun_filter !== 'all'): ?>
                                <span class="badge bg-light text-dark ms-2">Filtered</span>
                            <?php endif; ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (count($groupedSchedules) > 0): ?>
                            <?php foreach ($groupedSchedules as $groupKey => $schedules): ?>
                                <div class="mb-4">
                                    <h5 class="border-bottom pb-2">
                                        <span class="semester-badge"><?= htmlspecialchars($groupKey) ?></span>
                                        <small class="text-muted">(<?= count($schedules) ?> mata kuliah)</small>
                                    </h5>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover table-sm">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Mata Kuliah</th>
                                                    <th>Hari</th>
                                                    <th>Waktu</th>
                                                    <th>Ruangan</th>
                                                    <th>Dosen</th>
                                                    <th>Status</th>
                                                    <th>Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($schedules as $schedule): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($schedule['nama_matakuliah']) ?></strong><br>
                                                            <span class="badge bg-info"><?= htmlspecialchars($schedule['kelas']) ?></span>
                                                        </td>
                                                        <td><?= $dayNames[$schedule['hari']] ?? $schedule['hari'] ?></td>
                                                        <td>
                                                            <span class="badge bg-primary">
                                                                <?= date('H:i', strtotime($schedule['jam_mulai'])) ?> - 
                                                                <?= date('H:i', strtotime($schedule['jam_selesai'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?= htmlspecialchars($schedule['nama_ruang']) ?><br>
                                                            <small class="text-muted"><?= htmlspecialchars($schedule['nama_gedung']) ?></small>
                                                        </td>
                                                        <td><?= htmlspecialchars($schedule['dosen_pengampu']) ?></td>
                                                        <td>
                                                            <?php if ($schedule['exception_count'] > 0): ?>
                                                                <span class="exception-badge" title="<?= $schedule['exception_count'] ?> hari dikecualikan">
                                                                    <?= $schedule['exception_count'] ?> exception
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">Normal</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-outline-danger btn-sm" 
                                                                    onclick="showDeleteScheduleModal(0, <?= $schedule['id_schedule'] ?>, '<?= htmlspecialchars($schedule['nama_matakuliah']) ?>', '<?= htmlspecialchars($schedule['kelas']) ?>', '', 'Manual')">
                                                                <i class="fas fa-calendar-times me-1"></i>Hapus Hari Tertentu
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                                <h4>Tidak Ada Jadwal Ditemukan</h4>
                                <p class="text-muted">Tidak ada jadwal kuliah aktif dengan filter yang dipilih</p>
                                <a href="schedule_management.php" class="btn btn-primary">
                                    <i class="fas fa-undo me-1"></i>Reset Filter
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced: Approval Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-check me-2"></i>Setujui Perubahan Jadwal
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="approve_dosen_request">
                        <input type="hidden" name="booking_id" id="approve_booking_id">
                        
                        <div class="alert alert-success">
                            <h6>Booking: <span id="approve_booking_name"></span></h6>
                            <p class="mb-0">Dengan menyetujui, booking akan langsung aktif dan siap digunakan.</p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Catatan persetujuan (opsional):</label>
                            <textarea class="form-control" name="approval_note" rows="3" 
                                     placeholder="Contoh: Disetujui - ruangan tersedia, tidak ada konflik jadwal">Disetujui oleh CS - jadwal telah diverifikasi</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-2"></i>Ya, Setujui
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced: Rejection Modal -->
    <div class="modal fade" id="rejectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-times me-2"></i>Tolak Perubahan Jadwal
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reject_dosen_request">
                        <input type="hidden" name="booking_id" id="reject_booking_id">
                        
                        <div class="alert alert-warning">
                            <h6>Booking: <span id="reject_booking_name"></span></h6>
                            <p class="mb-0">Dosen akan menerima notifikasi penolakan via email.</p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Alasan penolakan <span class="text-danger">*</span>:</label>
                            <textarea class="form-control" name="rejection_note" rows="3" required
                                     placeholder="Contoh: Ruangan tidak tersedia, bentrok dengan acara lain, dll..."></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <small><i class="fas fa-info-circle me-1"></i>
                                Dosen dapat mengajukan request baru dengan jadwal yang berbeda.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-2"></i>Ya, Tolak
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced: Delete Schedule Modal -->
    <div class="modal fade" id="deleteScheduleModal" tabindex="-1" aria-labelledby="deleteScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="deleteScheduleForm" action="">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteScheduleModalLabel">
                            <i class="fas fa-calendar-times me-2"></i>Hapus Jadwal Kuliah
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <!-- HIDDEN INPUTS - YANG PALING PENTING -->
                        <input type="hidden" name="action" value="delete_schedule">
                        <input type="hidden" name="booking_id" id="delete_booking_id" value="0">
                        <input type="hidden" name="schedule_id" id="delete_schedule_id" value="">
                        
                        <!-- INFO DISPLAY -->
                        <div class="alert alert-warning mb-4">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Penghapusan Jadwal</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2">
                                        <strong>📚 Mata Kuliah:</strong><br>
                                        <span id="delete_schedule_name" class="text-primary">-</span>
                                    </p>
                                    <p class="mb-0">
                                        <strong>📅 Hari:</strong><br>
                                        <span id="delete_day_name" class="text-info">-</span>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2">
                                        <strong>🆔 Schedule ID:</strong><br>
                                        <span id="debug_schedule_id" class="badge bg-primary fs-6">-</span>
                                    </p>
                                    <p class="mb-0">
                                        <strong>📋 Booking ID:</strong><br>
                                        <span id="debug_booking_id" class="badge bg-secondary fs-6">-</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- TANGGAL INPUT -->
                        <div class="mb-4">
                            <label for="delete_date" class="form-label fw-bold">
                                📅 Tanggal yang akan dihapus <span class="text-danger">*</span>
                            </label>
                            <input type="date" 
                                class="form-control form-control-lg" 
                                name="delete_date" 
                                id="delete_date" 
                                required>
                            <div class="form-text">
                                Pilih tanggal spesifik yang akan dihapus (hanya 1 hari)
                            </div>
                        </div>
                        
                        <!-- ALASAN INPUT -->
                        <div class="mb-4">
                            <label for="delete_reason" class="form-label fw-bold">
                                📝 Alasan penghapusan jadwal <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" 
                                    name="delete_reason" 
                                    id="delete_reason"
                                    rows="4" 
                                    required
                                    placeholder="Contoh: Dosen berhalangan hadir, sudah ada jadwal pengganti, dll...">Jadwal diganti atas permintaan dosen - sudah ada pengganti yang disetujui</textarea>
                        </div>
                        
                        <!-- CATATAN PENTING -->
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>Catatan Penting</h6>
                            <ul class="mb-0">
                                <li>Jadwal <strong>hanya dihapus untuk 1 hari tertentu</strong></li>
                                <li>Mahasiswa tetap melihat jadwal di hari-hari lain</li>
                                <li>Sistem akan mencatat penghapusan ini secara permanen</li>
                            </ul>
                        </div>
                        
                        <!-- DEBUG INFO -->
                        <div class="alert alert-light border">
                            <details>
                                <summary class="fw-bold text-muted">🔧 Debug Information</summary>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <strong>Form Values:</strong><br>
                                        • Booking ID: <span id="debug_booking_id_display" class="text-primary fw-bold">-</span><br>
                                        • Schedule ID: <span id="debug_schedule_id_2" class="text-primary fw-bold">-</span><br>
                                        • Action: <span class="text-success fw-bold">delete_schedule</span><br>
                                        • Form ID: <span class="text-info fw-bold">deleteScheduleForm</span>
                                    </small>
                                </div>
                            </details>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-lg" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Batal
                        </button>
                        <button type="submit" class="btn btn-danger btn-lg">
                            <i class="fas fa-trash me-2"></i>Ya, Hapus Jadwal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enhanced: Show approval modal
        function showApprovalModal(bookingId, bookingName) {
            document.getElementById('approve_booking_id').value = bookingId;
            document.getElementById('approve_booking_name').textContent = bookingName;
            
            const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
            modal.show();
        }

        // Enhanced: Show rejection modal
        function showRejectionModal(bookingId, bookingName) {
            document.getElementById('reject_booking_id').value = bookingId;
            document.getElementById('reject_booking_name').textContent = bookingName;
            
            const modal = new bootstrap.Modal(document.getElementById('rejectionModal'));
            modal.show();
        }
        
        // Enhanced: Show delete schedule modal
        function showDeleteScheduleModal(bookingId, scheduleId, matakuliah, kelas, deleteDate, dayName) {
            console.log('=== MODAL DEBUG START ===');
            console.log('Raw parameters:', arguments);
            
            // Konversi dan validasi
            const cleanBookingId = parseInt(bookingId) || 0;
            const cleanScheduleId = parseInt(scheduleId) || 0;
            
            console.log('cleanBookingId:', cleanBookingId);
            console.log('cleanScheduleId:', cleanScheduleId);
            
            // VALIDASI YANG DIPERBAIKI
            if (cleanScheduleId <= 0) {
                alert('❌ Schedule ID tidak valid!\n\nSchedule ID: ' + cleanScheduleId + '\n\nSilakan refresh halaman dan coba lagi.');
                return;
            }
            
            // Cek elemen modal
            const bookingInput = document.getElementById('delete_booking_id');
            const scheduleInput = document.getElementById('delete_schedule_id');
            const scheduleName = document.getElementById('delete_schedule_name');
            const dayNameEl = document.getElementById('delete_day_name');
            const dateInput = document.getElementById('delete_date');
            
            if (!bookingInput || !scheduleInput || !scheduleName || !dayNameEl || !dateInput) {
                alert('ERROR: Modal elements tidak ditemukan! Refresh halaman dan coba lagi.');
                return;
            }
            
            // Set nilai
            bookingInput.value = cleanBookingId;
            scheduleInput.value = cleanScheduleId;
            scheduleName.textContent = (matakuliah && kelas) ? `${matakuliah} - ${kelas}` : 'Unknown Schedule';
            dayNameEl.textContent = dayName || 'Unknown Day';
            
            // Set tanggal
            if (deleteDate && deleteDate !== '' && deleteDate !== 'undefined') {
                dateInput.value = deleteDate;
            } else {
                const today = new Date().toISOString().split('T')[0];
                dateInput.value = today;
            }
            
            // Update debug info
            document.getElementById('debug_schedule_id').textContent = cleanScheduleId;
            document.getElementById('debug_booking_id').textContent = cleanBookingId;
            document.getElementById('debug_schedule_id_2').textContent = cleanScheduleId;
            document.getElementById('debug_booking_id_display').textContent = cleanBookingId;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('deleteScheduleModal'));
            modal.show();
            
            console.log('✅ Modal shown successfully');
            console.log('=== MODAL DEBUG END ===');
        }

        // Perbaiki validasi form submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('deleteScheduleForm');
            if (!form) return;
            
            form.addEventListener('submit', function(e) {
                const scheduleId = parseInt(document.getElementById('delete_schedule_id').value) || 0;
                const deleteDate = document.getElementById('delete_date').value;
                const deleteReason = document.querySelector('[name="delete_reason"]').value;
                
                console.log('Form validation - scheduleId:', scheduleId);
                
                // VALIDASI YANG DIPERBAIKI
                if (scheduleId <= 0) {
                    e.preventDefault();
                    alert('❌ Schedule ID tidak valid!\n\nNilai: ' + scheduleId + '\n\nSilakan tutup modal dan pilih jadwal yang berbeda.');
                    return false;
                }
                
                if (!deleteDate) {
                    e.preventDefault();
                    alert('❌ Tanggal harus diisi!');
                    document.getElementById('delete_date').focus();
                    return false;
                }
                
                if (!deleteReason.trim()) {
                    e.preventDefault();
                    alert('❌ Alasan penghapusan harus diisi!');
                    document.querySelector('[name="delete_reason"]').focus();
                    return false;
                }
                
                // Konfirmasi final
                const confirm = window.confirm(
                    `🗑️ KONFIRMASI PENGHAPUSAN\n\n` +
                    `📚 Mata Kuliah: ${document.getElementById('delete_schedule_name').textContent}\n` +
                    `🗓️ Tanggal: ${deleteDate}\n` +
                    `🆔 Schedule ID: ${scheduleId}\n\n` +
                    `⚠️ Yakin hapus jadwal untuk hari tersebut?`
                );
                
                if (!confirm) {
                    e.preventDefault();
                    return false;
                }
                
                console.log('✅ Form validation passed');
                return true;
            });
        });

        // Enhanced: Format date for display
        function formatDate(dateString) {
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            };
            return new Date(dateString).toLocaleDateString('id-ID', options);
        }

        // Enhanced: Real-time updates and notifications
        function checkForPriorityUpdates() {
            const pendingCount = <?= count($pendingDosenRequests) ?>;
            const approvedCount = <?= count($dosenBookings) ?>;
            
            if ((pendingCount + approvedCount) > 0 && document.visibilityState === 'visible') {
                // Auto-refresh every 3 minutes if there are priority items
                setTimeout(function() {
                    if (confirm('Ada update terbaru untuk jadwal dosen. Refresh halaman?')) {
                        location.reload();
                    }
                }, 180000); // 3 minutes
            }
        }

        // Enhanced: Auto-hide alerts and initialize
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-info):not(.alert-warning):not(.alert-success)');
                alerts.forEach(function(alert) {
                    if (alert.classList.contains('alert-success') || alert.classList.contains('alert-danger')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                });
            }, 5000);
            
            // Initialize priority update checking
            checkForPriorityUpdates();
            
            // Auto-scroll to focused booking if exists
            const focusedElement = document.querySelector('.focus-highlight');
            if (focusedElement) {
                focusedElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            // Enhanced: Add confirmation dialogs for critical actions
            const deleteButtons = document.querySelectorAll('.delete-schedule-btn');
            deleteButtons.forEach(button => {
                button.addEventListener('mouseover', function() {
                    this.setAttribute('title', 'Klik untuk menghapus jadwal kuliah pada hari tertentu');
                });
            });

            // Enhanced: Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+H for handled (mark as handled first priority item)
                if (e.ctrlKey && e.key === 'h') {
                    e.preventDefault();
                    const handledButton = document.querySelector('button[name="action"][value="mark_handled"]');
                    if (handledButton) {
                        handledButton.click();
                    }
                }
                
                // Ctrl+D for dashboard
                if (e.ctrlKey && e.key === 'd') {
                    e.preventDefault();
                    window.location.href = 'dashboard.php';
                }
            });

            // Enhanced: Priority item animations
            const priorityCards = document.querySelectorAll('.priority-card');
            priorityCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px)';
                    this.style.boxShadow = '0 6px 20px rgba(40, 167, 69, 0.3)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 15px rgba(40, 167, 69, 0.2)';
                });
            });
        });
    </script>
</body>
</html>