<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is admin or CS
if (!isLoggedIn() || (!isAdmin() && !isCS())) {
    header('Location: ../index.php');
    exit;
}

// FIXED: Set refresh interval with default value
$refreshInterval = isset($_GET['refresh']) ? intval($_GET['refresh']) : 30;
$validIntervals = [10, 30, 60];
if (!in_array($refreshInterval, $validIntervals)) {
    $refreshInterval = 30;
}

// Get current date and time
$currentDate = date('Y-m-d');
$currentTime = date('H:i:s');
$currentDateTime = date('Y-m-d H:i:s');

// Get all rooms with current status - FIXED VERSION
$sql = "SELECT r.*, g.nama_gedung,
               (SELECT COUNT(*) 
                FROM tbl_booking b 
                WHERE b.id_ruang = r.id_ruang 
                AND b.tanggal = ?
                AND b.status IN ('approve', 'active')
                AND b.jam_mulai <= ? 
                AND b.jam_selesai > ?) as is_occupied
        FROM tbl_ruang r 
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
        ORDER BY g.nama_gedung, r.nama_ruang";

$stmt = $conn->prepare($sql);
$stmt->execute([$currentDate, $currentTime, $currentTime]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get detailed booking info for each room - SEPARATE QUERIES
foreach ($rooms as &$room) {
    // Get current booking
    $stmt = $conn->prepare("
        SELECT b.*, u.email
        FROM tbl_booking b 
        JOIN tbl_users u ON b.id_user = u.id_user
        WHERE b.id_ruang = ? 
        AND b.tanggal = ?
        AND b.status IN ('approve', 'active')
        AND b.jam_mulai <= ? 
        AND b.jam_selesai > ?
        LIMIT 1
    ");
    $stmt->execute([$room['id_ruang'], $currentDate, $currentTime, $currentTime]);
    $room['current_booking'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get next booking
    $stmt = $conn->prepare("
        SELECT b.*, u.email
        FROM tbl_booking b 
        JOIN tbl_users u ON b.id_user = u.id_user
        WHERE b.id_ruang = ? 
        AND b.tanggal = ?
        AND b.status IN ('pending', 'approve')
        AND b.jam_mulai > ?
        ORDER BY b.jam_mulai ASC
        LIMIT 1
    ");
    $stmt->execute([$room['id_ruang'], $currentDate, $currentTime]);
    $room['next_booking'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if room is locked
    $room['is_locked'] = isRoomLocked($conn, $room['id_ruang'], $currentDate);
    if ($room['is_locked']) {
        $room['lock_info'] = getRoomLockInfo($conn, $room['id_ruang'], $currentDate);
    }
}
unset($room); // Break reference

// Get today's bookings for summary
$stmt = $conn->prepare("
    SELECT COUNT(*) as total_bookings,
           SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_bookings,
           SUM(CASE WHEN status = 'approve' THEN 1 ELSE 0 END) as approved_bookings,
           SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_bookings
    FROM tbl_booking 
    WHERE tanggal = ?
");
$stmt->execute([$currentDate]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if there are any holidays today
$stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
$stmt->execute([$currentDate]);
$todayHoliday = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Ruangan Real-time - STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    
    <style>
        .accordion-button:not(.collapsed) {
        background: linear-gradient(135deg, #007bff, #0056b3);
        color: white;
        border-color: #007bff;
    }
    
    .accordion-button:focus {
        box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.25);
    }
    
    .accordion-item {
        border-radius: 10px;
        overflow: hidden;
    }
    
    .accordion-button {
        border-radius: 10px;
        font-weight: 500;
    }
    
    .table th {
        font-weight: 600;
        font-size: 0.9rem;
        vertical-align: middle;
    }
    
    .table td {
        vertical-align: middle;
        font-size: 0.9rem;
    }
    
    .status-indicator {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        display: inline-block;
        animation: pulse 2s infinite;
    }
    
    .badge.rounded-pill {
        font-size: 0.75rem;
        padding: 0.375em 0.75em;
    }
    
    .btn-sm {
        --bs-btn-padding-y: 0.25rem;
        --bs-btn-padding-x: 0.5rem;
    }
        
        .room-card {
            transition: all 0.3s ease;
            border-left: 5px solid;
            min-height: 200px;
        }
        
        .room-available {
            border-left-color: #28a745 !important;
            background: linear-gradient(135deg, #d4edda, #f8fff9);
            box-shadow: 0 2px 10px rgba(40, 167, 69, 0.1);
        }
        
        .room-occupied {
            border-left-color: #dc3545 !important;
            background: linear-gradient(135deg, #f8d7da, #fff5f5);
            box-shadow: 0 2px 10px rgba(220, 53, 69, 0.1);
        }
        
        .room-scheduled {
            border-left-color: #ffc107 !important;
            background: linear-gradient(135deg, #fff3cd, #fffef7);
            box-shadow: 0 2px 10px rgba(255, 193, 7, 0.1);
        }
        
        .room-locked {
            border-left-color: #6c757d !important;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            box-shadow: 0 2px 10px rgba(108, 117, 125, 0.1);
        }
        
        .status-indicator {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            animation: pulse 2s infinite;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
        }
        
        .status-available { 
            background-color: #28a745; 
            box-shadow: 0 0 10px rgba(40, 167, 69, 0.5);
        }
        .status-occupied { 
            background-color: #dc3545; 
            box-shadow: 0 0 10px rgba(220, 53, 69, 0.5);
        }
        .status-scheduled { 
            background-color: #ffc107; 
            box-shadow: 0 0 10px rgba(255, 193, 7, 0.5);
        }
        .status-locked { 
            background-color: #6c757d; 
            box-shadow: 0 0 10px rgba(108, 117, 125, 0.5);
        }
        
        @keyframes pulse {
            0% { 
                opacity: 1; 
                transform: scale(1);
            }
            50% { 
                opacity: 0.6; 
                transform: scale(1.1);
            }
            100% { 
                opacity: 1; 
                transform: scale(1);
            }
        }
        
        .refresh-indicator {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1000;
            animation: slideInRight 0.5s ease;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .time-display {
            font-size: 1.8rem;
            font-weight: bold;
            color: white;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .summary-card {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="80" r="1.5" fill="rgba(255,255,255,0.1)"/></svg>');
            opacity: 0.5;
        }
        
        .building-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 10px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
        }
        
        .room-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .alert-sm {
            padding: 8px 12px;
            font-size: 0.875rem;
            border-radius: 8px;
        }
        
        .quick-action-btn {
            transition: all 0.2s ease;
            border-radius: 20px;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .legend-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border: none;
        }
        
        .stats-card {
            background: white;
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .holiday-alert {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            border: none;
            border-radius: 10px;
        }
    </style>
    
    <!-- Auto-refresh with dynamic interval -->
    <meta http-equiv="refresh" content="<?= $refreshInterval ?>">
</head>
<body class="admin-theme">
    <header>
        <?php 
        // FIXED: Set proper variables for header.php
        $backPath = '../'; 
        include '../header.php'; 
        ?>
    </header>
<div class="container-fluid mt-4">
        <div class="row">
    <!-- Sidebar -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Menu Admin</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="admin-dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                        <a href="recurring_schedules.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-week me-2"></i> Jadwal Perkuliahan
                        </a>
                        <a href="rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-door-open me-2"></i> Kelola Ruangan
                        </a>
                        <a href="buildings.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-building me-2"></i> Kelola Gedung
                        </a>
                        <a href="holidays.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-alt me-2"></i> Kelola Hari Libur
                        </a>
                        <a href="../today_schedule.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-day me-2"></i> Jadwal Hari Ini
                        </a>
                        <a href="admin_add_booking.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="rooms_locks.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-lock me-2"></i> Kelola Lock Ruangan
                        </a>
                        <a href="room_status.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-tv me-2"></i> Status Ruangan Real-time
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>

    <!-- Refresh Indicator 
    <div class="refresh-indicator">
        <div class="alert alert-info alert-sm mb-0 py-2 px-3">
            <i class="fas fa-sync-alt me-1"></i>
            Auto-refresh: <?= $refreshInterval ?>s
            <br><small><?= date('H:i:s') ?></small>
        </div>
    </div> -->

    <div class="col-md-9 col-lg-10">
        <!-- Holiday Alert -->
        <?php if ($todayHoliday): ?>
        <div class="row mb-3">
            <div class="col-12">
                <div class="alert holiday-alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-calendar-times fa-2x me-3"></i>
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-star me-2"></i>Hari Libur: <?= htmlspecialchars($todayHoliday['keterangan']) ?>
                            </h5>
                            <p class="mb-0">Beberapa kegiatan mungkin tidak berlangsung seperti biasa</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Header dengan waktu real-time -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-lg summary-card">
                    <div class="card-body py-4">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h3 class="mb-1">
                                    <i class="fas fa-building me-2"></i>Status Ruangan
                                </h3>
                                <p class="mb-0 opacity-75">
                                    <i class="fas fa-eye me-1"></i>Monitoring ruangan saat ini
                                </p>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="time-display" id="currentTime">
                                    <?= date('H:i:s') ?>
                                </div>
                                <div class="opacity-75">
                                    <i class="fas fa-calendar me-1"></i><?= formatDate($currentDate, 'l, d F Y') ?>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="mb-2">
                                    <small class="opacity-75">Refresh Interval:</small>
                                </div>
                                <div class="d-flex gap-2 justify-content-end">
                                    <a href="?refresh=10" class="btn btn-sm <?= $refreshInterval == 10 ? 'btn-light' : 'btn-outline-light' ?> quick-action-btn">10s</a>
                                    <a href="?refresh=30" class="btn btn-sm <?= $refreshInterval == 30 ? 'btn-light' : 'btn-outline-light' ?> quick-action-btn">30s</a>
                                    <a href="?refresh=60" class="btn btn-sm <?= $refreshInterval == 60 ? 'btn-light' : 'btn-outline-light' ?> quick-action-btn">60s</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card border-0">
                    <div class="card-body text-center py-4">
                        <div class="mb-2">
                            <i class="fas fa-door-open fa-2x text-primary"></i>
                        </div>
                        <h2 class="text-primary mb-1"><?= count($rooms) ?></h2>
                        <p class="text-muted mb-0">Total Ruangan</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card border-0">
                    <div class="card-body text-center py-4">
                        <div class="mb-2">
                            <i class="fas fa-users fa-2x text-danger"></i>
                        </div>
                        <h2 class="text-danger mb-1"><?= array_sum(array_column($rooms, 'is_occupied')) ?></h2>
                        <p class="text-muted mb-0">Sedang Digunakan</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card border-0">
                    <div class="card-body text-center py-4">
                        <div class="mb-2">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>
                        <h2 class="text-success mb-1"><?= count($rooms) - array_sum(array_column($rooms, 'is_occupied')) ?></h2>
                        <p class="text-muted mb-0">Tersedia</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card border-0">
                    <div class="card-body text-center py-4">
                        <div class="mb-2">
                            <i class="fas fa-calendar-check fa-2x text-info"></i>
                        </div>
                        <h2 class="text-info mb-1"><?= $summary['total_bookings'] ?></h2>
                        <p class="text-muted mb-0">Booking Hari Ini</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Room Status Grid -->
        <div class="row">
            <div class="col-12">
                <div class="accordion" id="buildingAccordion">
                    <?php 
                    // Group rooms by building
                    $roomsByBuilding = [];
                    foreach ($rooms as $room) {
                        $building = $room['nama_gedung'] ?: 'Gedung Tidak Diketahui';
                        $roomsByBuilding[$building][] = $room;
                    }
                    
                    $accordionIndex = 0;
                    foreach ($roomsByBuilding as $buildingName => $buildingRooms):
                        $accordionIndex++;
                        $collapseId = "collapse" . $accordionIndex;
                        
                        // Count room statuses for this building
                        $buildingStats = [
                            'total' => count($buildingRooms),
                            'available' => 0,
                            'occupied' => 0,
                            'scheduled' => 0,
                            'locked' => 0
                        ];
                        
                        foreach ($buildingRooms as $room) {
                            if ($room['is_locked'] ?? false) {
                                $buildingStats['locked']++;
                            } elseif ($room['is_occupied'] > 0) {
                                $buildingStats['occupied']++;
                            } elseif ($room['next_booking']) {
                                $buildingStats['scheduled']++;
                            } else {
                                $buildingStats['available']++;
                            }
                        }
                    ?>
                    
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="heading<?= $accordionIndex ?>">
                            <button class="accordion-button <?= $accordionIndex === 1 ? '' : 'collapsed' ?>" 
                                    type="button" 
                                    data-bs-toggle="collapse" 
                                    data-bs-target="#<?= $collapseId ?>" 
                                    aria-expanded="<?= $accordionIndex === 1 ? 'true' : 'false' ?>" 
                                    aria-controls="<?= $collapseId ?>">
                                
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-building me-3 text-primary fa-lg"></i>
                                        <div>
                                            <h5 class="mb-1"><?= htmlspecialchars($buildingName) ?></h5>
                                            <small class="text-muted">
                                                <?= $buildingStats['total'] ?> ruangan total
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex gap-2 me-3">
                                        <?php if ($buildingStats['available'] > 0): ?>
                                            <span class="badge bg-success rounded-pill">
                                                <i class="fas fa-check-circle me-1"></i><?= $buildingStats['available'] ?> Tersedia
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($buildingStats['occupied'] > 0): ?>
                                            <span class="badge bg-danger rounded-pill">
                                                <i class="fas fa-users me-1"></i><?= $buildingStats['occupied'] ?> Digunakan
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($buildingStats['scheduled'] > 0): ?>
                                            <span class="badge bg-warning rounded-pill">
                                                <i class="fas fa-clock me-1"></i><?= $buildingStats['scheduled'] ?> Terjadwal
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($buildingStats['locked'] > 0): ?>
                                            <span class="badge bg-secondary rounded-pill">
                                                <i class="fas fa-lock me-1"></i><?= $buildingStats['locked'] ?> Terkunci
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        
                        <div id="<?= $collapseId ?>" 
                            class="accordion-collapse collapse <?= $accordionIndex === 1 ? 'show' : '' ?>" 
                            aria-labelledby="heading<?= $accordionIndex ?>" 
                            data-bs-parent="#buildingAccordion">
                            
                            <div class="accordion-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-primary">
                                            <tr>
                                                <th width="20%">
                                                    <i class="fas fa-door-open me-2"></i>Ruangan
                                                </th>
                                                <th width="15%">
                                                    <i class="fas fa-users me-2"></i>Kapasitas
                                                </th>
                                                <th width="15%">
                                                    <i class="fas fa-traffic-light me-2"></i>Status
                                                </th>
                                                <th width="30%">
                                                    <i class="fas fa-info-circle me-2"></i>Informasi Saat Ini
                                                </th>
                                                <th width="20%">
                                                    <i class="fas fa-cog me-2"></i>Aksi
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($buildingRooms as $room): 
                                                // Determine room status
                                                $currentBooking = $room['current_booking'];
                                                $nextBooking = $room['next_booking'];
                                                $isLocked = $room['is_locked'] ?? false;
                                                
                                                if ($isLocked) {
                                                    $statusClass = 'table-secondary';
                                                    $statusText = 'Terkunci';
                                                    $statusIcon = 'fa-lock';
                                                    $statusColor = 'secondary';
                                                } elseif ($room['is_occupied'] > 0) {
                                                    $statusClass = 'table-danger';
                                                    $statusText = 'Sedang Digunakan';
                                                    $statusIcon = 'fa-users';
                                                    $statusColor = 'danger';
                                                } elseif ($nextBooking) {
                                                    $statusClass = 'table-warning';
                                                    $statusText = 'Ada Jadwal';
                                                    $statusIcon = 'fa-clock';
                                                    $statusColor = 'warning';
                                                } else {
                                                    $statusClass = 'table-success';
                                                    $statusText = 'Tersedia';
                                                    $statusIcon = 'fa-check-circle';
                                                    $statusColor = 'success';
                                                }
                                            ?>
                                            
                                            <tr class="<?= $statusClass ?>">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="status-indicator status-<?= $isLocked ? 'locked' : ($room['is_occupied'] > 0 ? 'occupied' : ($nextBooking ? 'scheduled' : 'available')) ?> me-3"></div>
                                                        <div>
                                                            <strong><?= htmlspecialchars($room['nama_ruang']) ?></strong>
                                                            <?php if (!empty($room['lokasi'])): ?>
                                                                <br><small class="text-muted">
                                                                    <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($room['lokasi']) ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                
                                                <td>
                                                    <span class="badge bg-info rounded-pill">
                                                        <i class="fas fa-users me-1"></i><?= $room['kapasitas'] ?> orang
                                                    </span>
                                                </td>
                                                
                                                <td>
                                                    <span class="badge bg-<?= $statusColor ?> rounded-pill">
                                                        <i class="fas <?= $statusIcon ?> me-1"></i><?= $statusText ?>
                                                    </span>
                                                </td>
                                                
                                                <td>
                                                    <?php if ($isLocked): ?>
                                                        <div class="text-muted">
                                                            <i class="fas fa-lock me-2"></i>
                                                            <strong>Ruangan Terkunci</strong><br>
                                                            <small><?= htmlspecialchars($room['lock_info']['reason'] ?? 'Ruangan dikunci') ?></small>
                                                        </div>
                                                        
                                                    <?php elseif ($currentBooking): ?>
                                                        <div>
                                                            <strong class="text-danger">
                                                                <i class="fas fa-play-circle me-1"></i><?= htmlspecialchars($currentBooking['nama_acara']) ?>
                                                            </strong><br>
                                                            <small class="text-muted">
                                                                <i class="fas fa-clock me-1"></i><?= formatTime($currentBooking['jam_mulai']) ?> - <?= formatTime($currentBooking['jam_selesai']) ?><br>
                                                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($currentBooking['nama_penanggungjawab']) ?>
                                                            </small>
                                                        </div>
                                                        
                                                    <?php elseif ($nextBooking): ?>
                                                        <div>
                                                            <strong class="text-warning">
                                                                <i class="fas fa-clock me-1"></i>Jadwal Berikutnya:
                                                            </strong><br>
                                                            <small>
                                                                <?= htmlspecialchars($nextBooking['nama_acara']) ?><br>
                                                                <i class="fas fa-clock me-1"></i><?= formatTime($nextBooking['jam_mulai']) ?> - <?= formatTime($nextBooking['jam_selesai']) ?>
                                                            </small>
                                                        </div>
                                                        
                                                    <?php else: ?>
                                                        <div class="text-success">
                                                            <i class="fas fa-check-circle me-2"></i>
                                                            <strong>Ruangan Kosong</strong><br>
                                                            <small>Tersedia untuk booking</small>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <td>
                                                    <div class="d-flex gap-1 flex-wrap">
                                                        <a href="../index.php?room_id=<?= $room['id_ruang'] ?>&date=<?= $currentDate ?>" 
                                                        class="btn btn-sm btn-outline-primary" title="Lihat Jadwal">
                                                            <i class="fas fa-calendar"></i>
                                                        </a>
                                                        
                                                        <?php if (!$isLocked && $room['is_occupied'] == 0): ?>
                                                            <button class="btn btn-sm btn-success" 
                                                                    onclick="quickBook(<?= $room['id_ruang'] ?>, '<?= htmlspecialchars($room['nama_ruang']) ?>')"
                                                                    title="Quick Book">
                                                                <i class="fas fa-plus"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($currentBooking): ?>
                                                            <button class="btn btn-sm btn-info" 
                                                                    onclick="showBookingDetail(<?= $currentBooking['id_booking'] ?>)"
                                                                    title="Detail Booking">
                                                                <i class="fas fa-info"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
</div>
</div>
        
        <!-- Enhanced Legend -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card legend-card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>Keterangan Status Ruangan
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center">
                                    <span class="status-indicator status-available me-3"></span>
                                    <div>
                                        <strong class="text-success">Tersedia</strong>
                                        <br><small class="text-muted">Ruangan kosong dan bisa dibooking</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center">
                                    <span class="status-indicator status-occupied me-3"></span>
                                    <div>
                                        <strong class="text-danger">Sedang Digunakan</strong>
                                        <br><small class="text-muted">Ada kegiatan berlangsung saat ini</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center">
                                    <span class="status-indicator status-scheduled me-3"></span>
                                    <div>
                                        <strong class="text-warning">Ada Jadwal</strong>
                                        <br><small class="text-muted">Kosong sekarang, ada booking selanjutnya</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex align-items-center">
                                    <span class="status-indicator status-locked me-3"></span>
                                    <div>
                                        <strong class="text-secondary">Terkunci</strong>
                                        <br><small class="text-muted">Ruangan dikunci oleh admin</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary">
                                    <i class="fas fa-sync-alt me-2"></i>Auto-Refresh
                                </h6>
                                <p class="small text-muted mb-0">
                                    Halaman akan otomatis refresh setiap <?= $refreshInterval ?> detik untuk menampilkan data terbaru.
                                    Anda dapat mengubah interval refresh menggunakan tombol di atas.
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">
                                    <i class="fas fa-clock me-2"></i>Real-time Status
                                </h6>
                                <p class="small text-muted mb-0">
                                    Status ruangan ditampilkan berdasarkan waktu saat ini: <strong><?= date('H:i:s') ?></strong>.
                                    Data diambil langsung dari database sistem booking.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update waktu real-time
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID');
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        
        // Update setiap detik
        setInterval(updateClock, 1000);
        
        // Quick booking function - FIXED PATH
        function quickBook(roomId, roomName) {
            if (confirm(`Booking ruangan ${roomName} untuk sekarang?`)) {
                const now = new Date();
                const currentHour = now.getHours().toString().padStart(2, '0');
                const currentMinute = now.getMinutes().toString().padStart(2, '0');
                const nextHour = (now.getHours() + 1).toString().padStart(2, '0');
                
                // PERBAIKAN: Path yang benar untuk admin folder
                const url = `../index.php?room_id=${roomId}&date=<?= $currentDate ?>&quick_book=1&start_time=${currentHour}:${currentMinute}&end_time=${nextHour}:${currentMinute}`;
                window.location.href = url;
            }
        }
        
        // Enhanced room status checker
        function checkRoomStatus() {
            // Update visual indicators based on time
            const cards = document.querySelectorAll('.room-card');
            const now = new Date();
            const currentTime = now.toTimeString().substr(0, 5);
            
            cards.forEach(card => {
                const alertElements = card.querySelectorAll('.alert');
                
                alertElements.forEach(alert => {
                    if (alert.classList.contains('alert-danger')) {
                        // Ongoing booking - add pulse effect
                        if (!alert.classList.contains('pulse-ongoing')) {
                            alert.classList.add('pulse-ongoing');
                            alert.style.animation = 'pulse-red 2s infinite';
                        }
                    }
                });
            });
        }
        
        // Page visibility API untuk pause/resume refresh
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log('Page hidden - auto refresh paused');
            } else {
                console.log('Page visible - auto refresh resumed');
                checkRoomStatus();
            }
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateClock();
            checkRoomStatus();
            
            // Auto-refresh notification after 30 seconds
            setTimeout(function() {
                showRefreshNotification();
            }, 30000);
        });
        
        // Show refresh notification
        function showRefreshNotification() {
            const notification = document.createElement('div');
            notification.className = 'alert alert-info alert-sm position-fixed';
            notification.style.cssText = 'top: 120px; right: 20px; z-index: 1050; animation: slideInRight 0.5s ease;';
            notification.innerHTML = `
                <i class="fas fa-sync-alt fa-spin me-2"></i>
                <strong>Auto-Refresh Aktif</strong><br>
                <small>Halaman akan refresh otomatis setiap <?= $refreshInterval ?> detik</small>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.5s ease';
                setTimeout(() => notification.remove(), 500);
            }, 5000);
        }
        
        // Add CSS animations dynamically
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse-red {
                0% { 
                    background-color: rgba(220, 53, 69, 0.1); 
                    border-color: #dc3545;
                }
                50% { 
                    background-color: rgba(220, 53, 69, 0.2); 
                    border-color: #c82333;
                }
                100% { 
                    background-color: rgba(220, 53, 69, 0.1); 
                    border-color: #dc3545;
                }
            }
            
            @keyframes slideOutRight {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
            
            .pulse-ongoing {
                border-width: 2px;
            }
            
            .room-occupied .status-indicator {
                animation: pulse-red 1.5s infinite;
            }
            
            .room-scheduled .status-indicator {
                animation: blink 2s infinite;
            }
            
            @keyframes blink {
                0%, 50% { opacity: 1; }
                51%, 100% { opacity: 0.5; }
            }
            
            
            .nav-link {
                color: rgba(255,255,255,0.9) !important;
                font-weight: 500;
            }
            
            .nav-link:hover {
                color: white !important;
            }
        `;
        document.head.appendChild(style);
        
        // Debug function
        window.debugRoomStatus = function() {
            console.log('Current time:', new Date().toLocaleTimeString());
            console.log('Total rooms:', document.querySelectorAll('.room-card').length);
            console.log('Occupied rooms:', document.querySelectorAll('.room-occupied').length);
            console.log('Available rooms:', document.querySelectorAll('.room-available').length);
            console.log('Scheduled rooms:', document.querySelectorAll('.room-scheduled').length);
            console.log('Locked rooms:', document.querySelectorAll('.room-locked').length);
        };
    </script>

</body>
</html>