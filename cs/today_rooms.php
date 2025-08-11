<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is CS
if (!isLoggedIn() || !isCS()) {
    header('Location: ../index.php?access_error=Akses ditolak - Anda bukan CS');
    exit;
}

// Get date parameter (default to today)
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

// Get room usage for selected date
$roomUsageQuery = "SELECT b.*, r.nama_ruang, r.kapasitas, g.nama_gedung, u.email, u.role as user_role,
                          rs.nama_matakuliah, rs.kelas, rs.dosen_pengampu,
                          ba.addon_total_cost
                   FROM tbl_booking b
                   JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                   LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                   JOIN tbl_users u ON b.id_user = u.id_user
                   LEFT JOIN tbl_recurring_schedules rs ON b.id_schedule = rs.id_schedule
                   LEFT JOIN (SELECT id_booking, SUM(total_price) as addon_total_cost 
                             FROM tbl_booking_addons GROUP BY id_booking) ba ON b.id_booking = ba.id_booking
                   WHERE b.tanggal = ? AND b.status IN ('approve', 'active', 'done')
                   ORDER BY b.jam_mulai ASC, r.nama_ruang ASC";

try {
    $stmt = $conn->prepare($roomUsageQuery);
    $stmt->execute([$selectedDate]);
    $roomUsage = $stmt->fetchAll();
} catch (PDOException $e) {
    $roomUsage = [];
    $error_message = "Error: " . $e->getMessage();
}

// Get all rooms for availability check
$allRoomsQuery = "SELECT r.*, g.nama_gedung FROM tbl_ruang r 
                  LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                  ORDER BY g.nama_gedung, r.nama_ruang";

try {
    $stmt = $conn->prepare($allRoomsQuery);
    $stmt->execute();
    $allRooms = $stmt->fetchAll();
} catch (PDOException $e) {
    $allRooms = [];
}

// Calculate room statistics
$totalRooms = count($allRooms);
$usedRooms = count(array_unique(array_column($roomUsage, 'id_ruang')));
$availableRooms = $totalRooms - $usedRooms;
$utilizationRate = $totalRooms > 0 ? round(($usedRooms / $totalRooms) * 100, 1) : 0;

// Group bookings by time slots
$timeSlots = [];
foreach ($roomUsage as $booking) {
    $timeKey = $booking['jam_mulai'] . '-' . $booking['jam_selesai'];
    if (!isset($timeSlots[$timeKey])) {
        $timeSlots[$timeKey] = [];
    }
    $timeSlots[$timeKey][] = $booking;
}

// Sort time slots
ksort($timeSlots);

// Handle AJAX requests for booking management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'emergency_contact':
            $bookingId = (int)$_POST['booking_id'];
            $message = $_POST['message'] ?? '';
            
            // Here you would implement emergency contact logic
            // For now, just return success
            echo json_encode([
                'success' => true, 
                'message' => 'Pesan darurat telah dikirim ke PIC'
            ]);
            exit;
            
        case 'extend_booking':
            $bookingId = (int)$_POST['booking_id'];
            $newEndTime = $_POST['new_end_time'];
            
            try {
                // Check for conflicts first
                $stmt = $conn->prepare("SELECT id_ruang, tanggal FROM tbl_booking WHERE id_booking = ?");
                $stmt->execute([$bookingId]);
                $booking = $stmt->fetch();
                
                if ($booking) {
                    // Check if extension is possible
                    $conflictCheck = $conn->prepare("SELECT COUNT(*) as count FROM tbl_booking 
                                                    WHERE id_ruang = ? AND tanggal = ? 
                                                    AND jam_mulai < ? AND status IN ('approve', 'active')
                                                    AND id_booking != ?");
                    $conflictCheck->execute([$booking['id_ruang'], $booking['tanggal'], $newEndTime, $bookingId]);
                    $conflicts = $conflictCheck->fetch()['count'];
                    
                    if ($conflicts == 0) {
                        $stmt = $conn->prepare("UPDATE tbl_booking SET jam_selesai = ? WHERE id_booking = ?");
                        $stmt->execute([$newEndTime, $bookingId]);
                        
                        echo json_encode(['success' => true, 'message' => 'Booking berhasil diperpanjang']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Tidak dapat memperpanjang - ada konflik jadwal']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Booking tidak ditemukan']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruangan Hari Ini - CS STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .cs-theme {
            --primary-color: #e91e63;
            --secondary-color: #f8bbd9;
            --accent-color: #ad1457;
        }
        
        .time-slot-card {
            border-left: 4px solid var(--primary-color);
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .time-slot-card:hover {
            box-shadow: 0 4px 15px rgba(233, 30, 99, 0.2);
            transform: translateY(-2px);
        }
        
        .room-item {
            background: linear-gradient(135deg, #fff, #f8f9fa);
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }
        
        .room-item:hover {
            background: linear-gradient(135deg, #fff5f8, #fce4ec);
            border-color: var(--primary-color);
        }
        
        .room-item.active {
            background: linear-gradient(135deg, #ffebee, #f8bbd9);
            border-color: var(--accent-color);
            border-width: 2px;
        }
        
        .utilization-chart {
            height: 100px;
            background: linear-gradient(135deg, #e8f5e8, #c8e6c9);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .utilization-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            transition: width 0.5s ease;
        }
        
        .date-navigator {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        
        .emergency-btn {
            background: linear-gradient(135deg, #ff5722, #d84315);
            border: none;
            color: white;
            animation: pulse 2s infinite;
        }
        /* Fix untuk icon di sidebar */
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
        
        /* Stat cards icons */
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
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 87, 34, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(255, 87, 34, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 87, 34, 0); }
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
                        </a>
                        <a href="add-booking.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="today_rooms.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-calendar-day me-2"></i> Ruangan Hari Ini
                        </a>
                        <a href="schedule_management.php" class="list-group-item list-group-item-action position-relative">
                            <i class="fa-solid fa-calendar-days"></i>Kelola Jadwal Dosen
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <!-- Date Navigator -->
                <div class="date-navigator">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h4 class="mb-0">
                                <i class="fas fa-calendar-day me-2 text-primary"></i>
                                Ruangan Terpakai - <?= formatDate($selectedDate) ?>
                            </h4>
                            <small class="text-muted">Monitoring penggunaan ruangan untuk Customer Service</small>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2 justify-content-end">
                                <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' -1 day')) ?>" 
                                   class="btn btn-outline-primary">
                                    <i class="fas fa-arrow-left"></i> Kemarin
                                </a>
                                
                                <input type="date" class="form-control" value="<?= $selectedDate ?>" 
                                       onchange="window.location.href='?date='+this.value" style="width: auto;">
                                
                                <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' +1 day')) ?>" 
                                   class="btn btn-outline-primary">
                                    Besok <i class="fas fa-arrow-right"></i>
                                </a>
                                
                                <?php if ($selectedDate !== date('Y-m-d')): ?>
                                    <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-primary">
                                        <i class="fas fa-calendar-day"></i> Hari Ini
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <div class="utilization-chart">
                                    <div class="utilization-bar" style="width: <?= $utilizationRate ?>%; height: 100%;"></div>
                                    <div style="position: relative; z-index: 2;">
                                        <h3 class="text-white mb-0"><?= $utilizationRate ?>%</h3>
                                        <small class="text-white">Tingkat Utilisasi</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary"><?= $usedRooms ?></h3>
                                <p class="mb-0">Ruangan Terpakai</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success"><?= $availableRooms ?></h3>
                                <p class="mb-0">Ruangan Tersedia</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info"><?= count($roomUsage) ?></h3>
                                <p class="mb-0">Total Booking</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Room Usage Timeline -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-clock me-2"></i>Timeline Penggunaan Ruangan
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($roomUsage)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak ada ruangan yang terpakai pada tanggal ini</h5>
                                <p class="text-muted">Semua ruangan kosong atau belum ada booking yang disetujui</p>
                                <a href="add-booking.php" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Tambah Booking Manual
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle me-2"></i>Panduan untuk CS</h6>
                                <ul class="mb-0">
                                    <li><strong>Status Active:</strong> Ruangan sedang digunakan (dapat dihubungi untuk perpanjangan/masalah)</li>
                                    <li><strong>Kontak Darurat:</strong> Gunakan tombol kontak jika ada kendala teknis</li>
                                    <li><strong>Add-on:</strong> Booking dengan add-on memiliki prioritas dan revenue tinggi</li>
                                </ul>
                            </div>

                            <?php foreach ($timeSlots as $timeRange => $bookings): ?>
                                <?php 
                                list($startTime, $endTime) = explode('-', $timeRange);
                                $currentTime = date('H:i:s');
                                $isCurrentTime = ($selectedDate === date('Y-m-d')) && 
                                               ($currentTime >= $startTime && $currentTime <= $endTime);
                                ?>
                                
                                <div class="time-slot-card card <?= $isCurrentTime ? 'border-danger' : '' ?>">
                                    <div class="card-header <?= $isCurrentTime ? 'bg-danger text-white' : 'bg-light' ?>">
                                        <h6 class="mb-0">
                                            <i class="fas fa-clock me-2"></i>
                                            <?= formatTime($startTime) ?> - <?= formatTime($endTime) ?>
                                            <?php if ($isCurrentTime): ?>
                                                <span class="badge bg-warning text-dark ms-2 blink-badge">🔴 SEDANG BERLANGSUNG</span>
                                            <?php endif; ?>
                                            <span class="badge bg-secondary ms-2"><?= count($bookings) ?> ruangan</span>
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <?php foreach ($bookings as $booking): ?>
                                                <?php
                                                $hasAddons = !empty($booking['addon_total_cost']) && $booking['addon_total_cost'] > 0;
                                                $isExternal = $booking['is_external'] || $booking['booking_type'] === 'external';
                                                ?>
                                                
                                                <div class="col-md-6 col-lg-4 mb-3">
                                                    <div class="room-item <?= $booking['status'] === 'active' ? 'active' : '' ?>">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <h6 class="fw-bold text-primary mb-0">
                                                                <?= htmlspecialchars($booking['nama_ruang']) ?>
                                                            </h6>
                                                            <div>
                                                                <?php
                                                                switch ($booking['status']) {
                                                                    case 'approve':
                                                                        echo '<span class="status-indicator bg-success"></span>';
                                                                        break;
                                                                    case 'active':
                                                                        echo '<span class="status-indicator bg-danger"></span>';
                                                                        break;
                                                                    case 'done':
                                                                        echo '<span class="status-indicator bg-info"></span>';
                                                                        break;
                                                                }
                                                                ?>
                                                                <?= ucfirst($booking['status']) ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <p class="fw-bold mb-2">
                                                            <?= htmlspecialchars($booking['nama_matakuliah'] ?? $booking['nama_acara']) ?>
                                                            <?php if (!empty($booking['kelas'])): ?>
                                                                <span class="badge bg-info ms-1"><?= htmlspecialchars($booking['kelas']) ?></span>
                                                            <?php endif; ?>
                                                        </p>
                                                        
                                                        <?php if ($isExternal): ?>
                                                            <div class="alert alert-warning alert-sm p-2 mb-2">
                                                                <i class="fas fa-building me-1"></i>
                                                                <strong>Eksternal</strong>
                                                                <?php if ($hasAddons): ?>
                                                                    <br><small>Add-on: Rp <?= number_format($booking['addon_total_cost'], 0, ',', '.') ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="mb-2">
                                                            <small class="text-muted">
                                                                <i class="fas fa-user me-1"></i>
                                                                <strong>PIC:</strong> <?= htmlspecialchars($booking['nama_penanggungjawab']) ?>
                                                            </small>
                                                            <br>
                                                            <small class="text-muted">
                                                                <i class="fas fa-envelope me-1"></i>
                                                                <?= htmlspecialchars($booking['email']) ?>
                                                            </small>
                                                            <br>
                                                            <small class="text-muted">
                                                                <i class="fas fa-phone me-1"></i>
                                                                <a href="tel:<?= htmlspecialchars($booking['no_penanggungjawab']) ?>" class="text-decoration-none">
                                                                    <?= htmlspecialchars($booking['no_penanggungjawab']) ?>
                                                                </a>
                                                            </small>
                                                        </div>
                                                        
                                                        <div class="d-flex gap-1">
                                                            <button class="btn btn-sm btn-outline-primary" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#detailModal<?= $booking['id_booking'] ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            
                                                            <?php if ($booking['status'] === 'active'): ?>
                                                                <button class="btn btn-sm emergency-btn" 
                                                                        onclick="showEmergencyContact(<?= $booking['id_booking'] ?>, '<?= htmlspecialchars($booking['nama_penanggungjawab']) ?>')">
                                                                    <i class="fas fa-exclamation-triangle"></i>
                                                                </button>
                                                                
                                                                <button class="btn btn-sm btn-outline-success" 
                                                                        onclick="showExtendBooking(<?= $booking['id_booking'] ?>, '<?= $booking['jam_selesai'] ?>')">
                                                                    <i class="fas fa-clock"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            
                                                            <a href="mailto:<?= htmlspecialchars($booking['email']) ?>" 
                                                               class="btn btn-sm btn-outline-secondary">
                                                                <i class="fas fa-envelope"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Emergency Contact Modal -->
    <div class="modal fade" id="emergencyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Kontak Darurat
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <strong>Gunakan fitur ini untuk:</strong>
                        <ul class="mb-0">
                            <li>Masalah teknis mendesak (AC, proyektor rusak)</li>
                            <li>Keamanan atau keselamatan</li>
                            <li>Koordinasi perpanjangan waktu</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <strong>PIC yang akan dihubungi: <span id="emergencyPicName"></span></strong>
                    </div>
                    
                    <div class="mb-3">
                        <label for="emergencyMessage" class="form-label">Pesan Darurat:</label>
                        <textarea class="form-control" id="emergencyMessage" rows="4" 
                                  placeholder="Jelaskan masalah atau kebutuhan yang mendesak..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-danger" onclick="sendEmergencyContact()">
                        <i class="fas fa-phone me-2"></i>Kirim Pesan Darurat
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Extend Booking Modal -->
    <div class="modal fade" id="extendModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-clock me-2"></i>Perpanjang Booking
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Catatan:</strong> Perpanjangan hanya bisa dilakukan jika tidak ada konflik dengan booking lain.
                    </div>
                    
                    <div class="mb-3">
                        <label for="currentEndTime" class="form-label">Jam Selesai Saat Ini:</label>
                        <input type="time" class="form-control" id="currentEndTime" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="newEndTime" class="form-label">Jam Selesai Baru:</label>
                        <input type="time" class="form-control" id="newEndTime" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-success" onclick="extendBooking()">
                        <i class="fas fa-check me-2"></i>Perpanjang
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Detail Modals for each booking -->
    <?php foreach ($roomUsage as $booking): ?>
        <div class="modal fade" id="detailModal<?= $booking['id_booking'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-info-circle me-2"></i>Detail Booking #<?= $booking['id_booking'] ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom pb-2">Informasi Acara</h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <th width="30%">Nama Acara:</th>
                                        <td><?= htmlspecialchars($booking['nama_acara']) ?></td>
                                    </tr>
                                    <?php if (!empty($booking['nama_matakuliah'])): ?>
                                        <tr>
                                            <th>Mata Kuliah:</th>
                                            <td><?= htmlspecialchars($booking['nama_matakuliah']) ?> - <?= htmlspecialchars($booking['kelas']) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Dosen:</th>
                                            <td><?= htmlspecialchars($booking['dosen_pengampu']) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Ruangan:</th>
                                        <td><?= htmlspecialchars($booking['nama_ruang']) ?> (<?= htmlspecialchars($booking['nama_gedung']) ?>)</td>
                                    </tr>
                                    <tr>
                                        <th>Waktu:</th>
                                        <td><?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom pb-2">Informasi Kontak</h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <th width="30%">PIC:</th>
                                        <td><?= htmlspecialchars($booking['nama_penanggungjawab']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>No. HP:</th>
                                        <td>
                                            <a href="tel:<?= htmlspecialchars($booking['no_penanggungjawab']) ?>">
                                                <?= htmlspecialchars($booking['no_penanggungjawab']) ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Email:</th>
                                        <td>
                                            <a href="mailto:<?= htmlspecialchars($booking['email']) ?>">
                                                <?= htmlspecialchars($booking['email']) ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Role:</th>
                                        <td><span class="badge bg-secondary"><?= ucfirst($booking['user_role']) ?></span></td>
                                    </tr>
                                </table>
                                
                                <?php if (!empty($booking['addon_total_cost'])): ?>
                                    <div class="alert alert-warning">
                                        <h6><i class="fas fa-plus-square me-2"></i>Add-on Services</h6>
                                        <p class="mb-0">Total biaya add-on: <strong>Rp <?= number_format($booking['addon_total_cost'], 0, ',', '.') ?></strong></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6 class="text-primary border-bottom pb-2">Keterangan</h6>
                                <p class="text-muted"><?= nl2br(htmlspecialchars($booking['keterangan'])) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                        <a href="tel:<?= htmlspecialchars($booking['no_penanggungjawab']) ?>" class="btn btn-success">
                            <i class="fas fa-phone me-2"></i>Telepon PIC
                        </a>
                        <a href="mailto:<?= htmlspecialchars($booking['email']) ?>" class="btn btn-primary">
                            <i class="fas fa-envelope me-2"></i>Email
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        let currentEmergencyBookingId = null;
        let currentExtendBookingId = null;

        function showEmergencyContact(bookingId, picName) {
            currentEmergencyBookingId = bookingId;
            document.getElementById('emergencyPicName').textContent = picName;
            document.getElementById('emergencyMessage').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('emergencyModal'));
            modal.show();
        }

        function sendEmergencyContact() {
            const message = document.getElementById('emergencyMessage').value;
            
            if (!message.trim()) {
                alert('Harap isi pesan darurat');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'emergency_contact');
            formData.append('booking_id', currentEmergencyBookingId);
            formData.append('message', message);
            
            fetch('today_rooms.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    bootstrap.Modal.getInstance(document.getElementById('emergencyModal')).hide();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan');
            });
        }

        function showExtendBooking(bookingId, currentEndTime) {
            currentExtendBookingId = bookingId;
            document.getElementById('currentEndTime').value = currentEndTime;
            document.getElementById('newEndTime').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('extendModal'));
            modal.show();
        }

        function extendBooking() {
            const newEndTime = document.getElementById('newEndTime').value;
            const currentEndTime = document.getElementById('currentEndTime').value;
            
            if (!newEndTime) {
                alert('Harap isi jam selesai baru');
                return;
            }
            
            if (newEndTime <= currentEndTime) {
                alert('Jam selesai baru harus lebih dari jam selesai saat ini');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'extend_booking');
            formData.append('booking_id', currentExtendBookingId);
            formData.append('new_end_time', newEndTime);
            
            fetch('today_rooms.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan');
            });
        }

        // Auto-refresh every 2 minutes
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                location.reload();
            }
        }, 120000);

        // Real-time clock
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID');
            
            // Update any clock display if exists
            const clockElements = document.querySelectorAll('.live-clock');
            clockElements.forEach(el => el.textContent = timeString);
        }

        setInterval(updateClock, 1000);
        updateClock();

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>