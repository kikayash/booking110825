<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// Get status filter
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Pagination setup
$recordsPerPage = 10; // Jumlah record per halaman
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, $currentPage); // Ensure page is at least 1
$offset = ($currentPage - 1) * $recordsPerPage;

// Build query based on status filter
$whereClause = "";
$params = [];

if ($statusFilter !== 'all') {
    $whereClause = "WHERE b.status = ?";
    $params[] = $statusFilter;
}

// Count total records for pagination
$countQuery = "SELECT COUNT(*) as total 
               FROM tbl_booking b 
               JOIN tbl_users u ON b.id_user = u.id_user 
               JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
               JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
               $whereClause";

$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Get bookings with pagination
$query = "SELECT b.*, u.email, r.nama_ruang, g.nama_gedung, r.lokasi, r.kapasitas
          FROM tbl_booking b 
          JOIN tbl_users u ON b.id_user = u.id_user 
          JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
          JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
          $whereClause
          ORDER BY b.tanggal DESC, b.jam_mulai DESC
          LIMIT $recordsPerPage OFFSET $offset";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get status counts for filter buttons
$statusCounts = [];
$statusQueries = [
    'all' => "SELECT COUNT(*) as count FROM tbl_booking",
    'pending' => "SELECT COUNT(*) as count FROM tbl_booking WHERE status = 'pending'",
    'approve' => "SELECT COUNT(*) as count FROM tbl_booking WHERE status = 'approve'",
    'active' => "SELECT COUNT(*) as count FROM tbl_booking WHERE status = 'active'",
    'done' => "SELECT COUNT(*) as count FROM tbl_booking WHERE status = 'done'",
    'cancel' => "SELECT COUNT(*) as count FROM tbl_booking WHERE status = 'cancel'"
];

foreach ($statusQueries as $status => $query) {
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $statusCounts[$status] = $stmt->fetch()['count'];
}

// Get bookings status filter
$status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$validStatuses = ['pending', 'approve', 'rejected', 'cancelled', 'active', 'done', 'all'];

if (!in_array($status, $validStatuses)) {
    $status = 'pending';
}

// Get search parameter - FIXED
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query based on status and search - IMPROVED
$sql = "SELECT b.*, u.email, r.nama_ruang, r.kapasitas, g.nama_gedung,
               b.checkout_status, b.checkout_time, b.checked_out_by, b.completion_note,
               b.cancelled_by, b.cancellation_reason, b.approved_at, b.approved_by
        FROM tbl_booking b 
        JOIN tbl_users u ON b.id_user = u.id_user 
        JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung";

$params = [];
$whereConditions = [];

// Add status filter
if ($status !== 'all') {
    $whereConditions[] = "b.status = ?";
    $params[] = $status;
}

// Add search filter - FIXED AND ENHANCED
if (!empty($search)) {
    $whereConditions[] = "(b.nama_acara LIKE ? OR b.nama_penanggungjawab LIKE ? OR b.keterangan LIKE ? OR u.email LIKE ? OR r.nama_ruang LIKE ? OR g.nama_gedung LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

// Combine WHERE conditions
if (!empty($whereConditions)) {
    $sql .= " WHERE " . implode(" AND ", $whereConditions);
}

$sql .= " ORDER BY b.created_at ASC, b.tanggal ASC, b.jam_mulai ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error in admin dashboard query: " . $e->getMessage());
    $bookings = [];
    $search_error = "Terjadi kesalahan dalam pencarian. Silakan coba lagi.";
}

// Handle booking actions
$message = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $bookingId = $_POST['booking_id'] ?? 0;
    
    if ($bookingId && $action) {
        $booking = getBookingById($conn, $bookingId);
        
        if ($booking) {
            switch ($action) {
                case 'approve':
                    if ($booking['status'] === 'pending') {
                        // Check for conflicts before approving
                        if (hasBookingConflict($conn, $booking['id_ruang'], $booking['tanggal'], 
                                             $booking['jam_mulai'], $booking['jam_selesai'], $bookingId)) {
                            $message = 'Tidak dapat menyetujui peminjaman karena terjadi konflik jadwal.';
                            $alertType = 'danger';
                        } else {
                            // Update status dengan informasi approval
                            $stmt = $conn->prepare("UPDATE tbl_booking 
                                                  SET status = 'approve', 
                                                      approved_at = NOW(), 
                                                      approved_by = ? 
                                                  WHERE id_booking = ?");
                            $result = $stmt->execute([$_SESSION['email'], $bookingId]);
                            
                            if ($result) {
                                // Send notification
                                sendBookingNotification($booking['email'], $booking, 'approval');
                                $message = 'Peminjaman berhasil disetujui dan notifikasi telah dikirim.';
                                $alertType = 'success';
                            } else {
                                $message = 'Gagal memperbarui status peminjaman.';
                                $alertType = 'danger';
                            }
                        }
                    }
                    break;
                    
                case 'reject':
                    if ($booking['status'] === 'pending') {
                        $reject_reason = $_POST['reject_reason'] ?? 'Tidak memenuhi syarat';
                        
                        // Update status dengan alasan penolakan
                        $stmt = $conn->prepare("UPDATE tbl_booking 
                                              SET status = 'rejected', 
                                                  cancellation_reason = ?
                                              WHERE id_booking = ?");
                        $result = $stmt->execute([$reject_reason, $bookingId]);
                        
                        if ($result) {
                            // Send rejection notification
                            $booking['reject_reason'] = $reject_reason;
                            sendBookingNotification($booking['email'], $booking, 'rejection');
                            $message = 'Peminjaman berhasil ditolak dan notifikasi telah dikirim.';
                            $alertType = 'success';
                        } else {
                            $message = 'Gagal memperbarui status peminjaman.';
                            $alertType = 'danger';
                        }
                    }
                    break;
                    
                default:
                    $message = 'Tindakan tidak valid.';
                    $alertType = 'warning';
            }
        } else {
            $message = 'Peminjaman tidak ditemukan.';
            $alertType = 'danger';
        }
    }
}

// Get booking statistics for current filter
$totalBookings = count($bookings);
$bookingsByStatus = [];
foreach ($bookings as $booking) {
    $status = $booking['status'];
    $bookingsByStatus[$status] = ($bookingsByStatus[$status] ?? 0) + 1;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .search-stats {
            background: linear-gradient(135deg, #e3f2fd, #f8f9fa);
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .quick-search-btn {
            transition: all 0.2s ease;
            margin: 2px;
        }
        
        .quick-search-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .booking-card {
            transition: transform 0.2s ease;
        }
        
        .booking-card:hover {
            transform: translateY(-2px);
        }
        
        .status-badge {
            font-size: 0.9rem;
            padding: 8px 12px;
            border-radius: 20px;
        }
        
        .checkout-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px;
            margin-top: 8px;
        }
        
        .modal-xl {
            max-width: 95%;
        }
        @media (min-width: 1200px) {
            .modal-xl {
                max-width: 1140px;
            }
        }
        .modal-body .table th {
            background-color: #f8f9fa !important;
            font-weight: 600;
            color: #495057;
            border: 1px solid #dee2e6;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .modal-body .table td {
            border: 1px solid #dee2e6;
            vertical-align: middle;
            padding: 12px;
        }
        .modal-body .table thead th {
            background: linear-gradient(135deg, #007bff, #0d6efd) !important;
            color: white !important;
            font-weight: 700;
            text-align: center;
            border: none;
            padding: 15px;
        }
        .modal-body .badge.fs-6 {
            font-size: 1rem !important;
            padding: 8px 16px;
            border-radius: 20px;
        }
        .modal-footer.bg-light {
            background-color: #f8f9fa !important;
            border-top: 2px solid #dee2e6;
        }
        .modal-footer .btn {
            border-radius: 20px;
            font-weight: 600;
            padding: 8px 20px;
            margin: 0 5px;
        }
        .status-filters {
            margin-bottom: 20px;
        }
        .status-filters .btn {
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .booking-card {
            transition: all 0.3s ease;
            border-left: 4px solid #ddd;
        }
        .booking-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .booking-card.status-pending { border-left-color: #ffc107; }
        .booking-card.status-approve { border-left-color: #28a745; }
        .booking-card.status-active { border-left-color: #007bff; }
        .booking-card.status-done { border-left-color: #6c757d; }
        .booking-card.status-cancel { border-left-color: #dc3545; }
        
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .pagination .page-link {
            color: #007bff;
        }
        
        .pagination .page-item.active .page-link {
            background-color: #007bff;
            border-color: #007bff;
        }
    </style>
</head>
<body class="admin-theme">
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
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
                        <a href="admin-dashboard.php" class="list-group-item list-group-item-action active">
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
                        <a href="admin_add_booking.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="rooms_locks.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-lock me-2"></i> Kelola Lock Ruangan
                        </a>
                        <a href="room_status.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tv me-2"></i> Status Ruangan Real-time
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Dashboard Admin - Kelola Peminjaman Ruangan</h4>
                        <div>
                            <a href="../index.php" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-calendar me-1"></i> Kalender
                            </a>
                            <a href="room_status.php" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-tv me-1"></i> Status Ruangan
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <i class="fas fa-<?= $alertType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                                <?= $message ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($search_error)): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?= $search_error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Booking Status Tabs -->
                        <ul class="nav nav-tabs mb-4">
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>" href="?status=pending<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">
                                    <i></i> Menunggu Persetujuan
                                    <?php if (isset($bookingsByStatus['pending'])): ?>
                                        <span class="badge bg-warning text-dark ms-1"><?= $bookingsByStatus['pending'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'approve' ? 'active' : '' ?>" href="?status=approve<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">
                                    <i></i> Disetujui
                                    <?php if (isset($bookingsByStatus['approve'])): ?>
                                        <span class="badge bg-success ms-1"><?= $bookingsByStatus['approve'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'active' ? 'active' : '' ?>" href="?status=active<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">
                                    <i></i> Sedang Berlangsung
                                    <?php if (isset($bookingsByStatus['active'])): ?>
                                        <span class="badge bg-danger ms-1"><?= $bookingsByStatus['active'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'done' ? 'active' : '' ?>" href="?status=done<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">
                                    <i class=""></i> Selesai
                                    <?php if (isset($bookingsByStatus['done'])): ?>
                                        <span class="badge bg-info ms-1"><?= $bookingsByStatus['done'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'rejected' ? 'active' : '' ?>" href="?status=rejected<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">
                                    <i></i> Ditolak
                                    <?php if (isset($bookingsByStatus['rejected'])): ?>
                                        <span class="badge bg-danger ms-1"><?= $bookingsByStatus['rejected'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'cancelled' ? 'active' : '' ?>" href="?status=cancelled<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">
                                    <i></i> Dibatalkan
                                    <?php if (isset($bookingsByStatus['cancelled'])): ?>
                                        <span class="badge bg-secondary ms-1"><?= $bookingsByStatus['cancelled'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $status === 'all' ? 'active' : '' ?>" href="?status=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">
                                    <i></i> Semua
                                    <span class="badge bg-primary ms-1"><?= $totalBookings ?></span>
                                </a>
                            </li>
                        </ul>

                        <!-- Search Bar - FIXED -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <form method="GET" class="d-flex gap-2" action="">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                                    
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-search"></i>
                                        </span>
                                        <input type="text" 
                                               class="form-control" 
                                               name="search" 
                                               value="<?= htmlspecialchars($search) ?>" 
                                               placeholder="Cari berdasarkan nama acara, UKM, PIC, email, atau ruangan...">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="fas fa-search me-1"></i>Cari
                                        </button>
                                        
                                        <?php if (!empty($search)): ?>
                                            <a href="?status=<?= $status ?>" class="btn btn-outline-secondary">
                                                <i class="fas fa-times me-1"></i>Reset
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                            <div class="col-md-4">
                                <?php if (!empty($search)): ?>
                                    <div class="search-stats">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-search me-2 text-primary"></i>
                                            <div>
                                                <strong>Hasil pencarian:</strong> "<?= htmlspecialchars($search) ?>"<br>
                                                <small class="text-muted">Ditemukan <?= count($bookings) ?> data</small>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-end">
                                        <div class="alert alert-light mb-0 py-2">
                                            <strong>Total: <?= count($bookings) ?> data</strong><br>
                                            <small class="text-muted">Status: <?= ucfirst($status) ?></small>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        </div><br>
                        
                        <!-- Bookings Table - ENHANCED WITH CHECKOUT INFO -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="5%">No</th>
                                        <th width="20%">Nama Acara & PIC</th>
                                        <th width="15%">Ruangan</th>
                                        <th width="15%">Tanggal & Waktu</th>
                                        <th width="15%">Peminjam</th>
                                        <th width="15%">Status & Info</th>
                                        <th width="15%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($bookings) > 0): ?>
                                        <?php $i = 1; foreach ($bookings as $booking): ?>
                                            <tr class="booking-card">
                                                <td class="text-center"><strong><?= $i++ ?></strong></td>
                                                <td>
                                                    <strong class="text-primary"><?= htmlspecialchars($booking['nama_acara'] ?? '') ?></strong><br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-user me-1"></i>PIC: <?= htmlspecialchars($booking['nama_penanggungjawab'] ?? '') ?><br>
                                                        <i class="fas fa-phone me-1"></i><?= htmlspecialchars($booking['no_penanggungjawab'] ?? '') ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($booking['nama_ruang'] ?? '') ?></strong>
                                                    <?php if (!empty($booking['nama_gedung'])): ?>
                                                        <br><small class="text-muted">
                                                            <i class="fas fa-building me-1"></i><?= htmlspecialchars($booking['nama_gedung']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                    <br><small class="text-info">
                                                        <i class="fas fa-users me-1"></i>Kapasitas: <?= $booking['kapasitas'] ?? 'N/A' ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <strong><?= formatDate($booking['tanggal']) ?></strong><br>
                                                    <span class="badge bg-info">
                                                        <i class="fas fa-clock me-1"></i><?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($booking['email'] ?? '') ?></strong><br>
                                                    <small class="text-muted">
                                                        ID: #<?= $booking['id_booking'] ?><br>
                                                        <i class="fas fa-calendar-plus me-1"></i><?= date('d/m/Y H:i', strtotime($booking['created_at'])) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php
                                                    // Enhanced status display with checkout information
                                                    $status = $booking['status'] ?? 'unknown';
                                                    $checkoutStatus = $booking['checkout_status'] ?? 'pending';
                                                    $checkoutTime = $booking['checkout_time'] ?? '';
                                                    $checkedOutBy = $booking['checked_out_by'] ?? '';
                                                    $completionNote = $booking['completion_note'] ?? '';
                                                    
                                                    switch ($status) {
                                                        case 'pending':
                                                            echo '<span class="badge bg-warning text-dark status-badge">
                                                                    <i class="fas fa-clock me-1"></i>Menunggu Persetujuan
                                                                  </span>';
                                                            break;
                                                        case 'approve':
                                                            echo '<span class="badge bg-success status-badge">
                                                                    <i class="fas fa-check me-1"></i>Disetujui
                                                                  </span>';
                                                            if (!empty($booking['approved_at'])) {
                                                                echo '<br><small class="text-muted">
                                                                        <i class="fas fa-check-circle me-1"></i>Disetujui: ' . 
                                                                        date('d/m/Y H:i', strtotime($booking['approved_at'])) . 
                                                                      '</small>';
                                                            }
                                                            break;
                                                        case 'active':
                                                            echo '<span class="badge bg-danger status-badge">
                                                                    <i class="fas fa-play me-1"></i>Sedang Berlangsung
                                                                  </span>';
                                                            break;
                                                        case 'rejected':
                                                            echo '<span class="badge bg-danger status-badge">
                                                                    <i class="fas fa-times me-1"></i>Ditolak
                                                                  </span>';
                                                            if (!empty($booking['cancellation_reason'])) {
                                                                echo '<div class="checkout-info">
                                                                        <small><strong>Alasan:</strong><br>' . 
                                                                        htmlspecialchars($booking['cancellation_reason']) . 
                                                                      '</small></div>';
                                                            }
                                                            break;
                                                        case 'cancelled':
                                                            echo '<span class="badge bg-secondary status-badge">
                                                                    <i class="fas fa-ban me-1"></i>Dibatalkan
                                                                  </span>';
                                                            echo '<div class="checkout-info">
                                                                    <small class="text-success">
                                                                        <i class="fas fa-check-circle me-1"></i><strong>SLOT TERSEDIA LAGI</strong><br>
                                                                        Ruangan bisa dibooking oleh user lain
                                                                    </small>
                                                                  </div>';
                                                            if (!empty($booking['cancellation_reason'])) {
                                                                echo '<div class="mt-1">
                                                                        <small><strong>Alasan:</strong> ' . 
                                                                        htmlspecialchars($booking['cancellation_reason']) . 
                                                                      '</small></div>';
                                                            }
                                                            break;
                                                        case 'done':
                                                            // Enhanced checkout information display
                                                            if ($checkoutStatus === 'auto_completed' || $checkedOutBy === 'SYSTEM_AUTO') {
                                                                echo '<span class="badge bg-warning text-dark status-badge">
                                                                        <i class="fas fa-robot me-1"></i>Selesai (Auto)
                                                                      </span>';
                                                                echo '<div class="checkout-info">
                                                                        <small class="text-warning">
                                                                            <i class="fas fa-exclamation-triangle me-1"></i><strong>Auto-Completed oleh Sistem</strong><br>';
                                                                if (!empty($completionNote)) {
                                                                    echo htmlspecialchars($completionNote);
                                                                } else {
                                                                    echo 'Ruangan selesai dipakai tanpa checkout manual';
                                                                }
                                                                echo '</small></div>';
                                                            } elseif ($checkoutStatus === 'manual_checkout' || $checkedOutBy === 'USER_MANUAL') {
                                                                echo '<span class="badge bg-success status-badge">
                                                                        <i class="fas fa-user-check me-1"></i>Selesai (Manual)
                                                                      </span>';
                                                                echo '<div class="checkout-info">
                                                                        <small class="text-success">
                                                                            <i class="fas fa-check-circle me-1"></i><strong>Checkout oleh Mahasiswa</strong><br>
                                                                            Ruangan sudah selesai dipakai dengan checkout mahasiswa
                                                                        </small>
                                                                      </div>';
                                                            } elseif ($checkoutStatus === 'force_checkout' || $checkedOutBy === 'ADMIN_FORCE') {
                                                                echo '<span class="badge bg-info status-badge">
                                                                        <i class="fas fa-hand-paper me-1"></i>Selesai (Force)
                                                                      </span>';
                                                                echo '<div class="checkout-info">
                                                                        <small class="text-info">
                                                                            <i class="fas fa-user-shield me-1"></i><strong>Force Checkout oleh Admin</strong><br>
                                                                            Admin telah memaksa checkout ruangan
                                                                        </small>
                                                                      </div>';
                                                            } else {
                                                                echo '<span class="badge bg-info status-badge">
                                                                        <i class="fas fa-check-double me-1"></i>Selesai
                                                                      </span>';
                                                            }
                                                            
                                                            if (!empty($checkoutTime)) {
                                                                echo '<div class="mt-1">
                                                                        <small class="text-muted">
                                                                            <i class="fas fa-clock me-1"></i>Checkout: ' . 
                                                                            date('d/m/Y H:i', strtotime($checkoutTime)) . 
                                                                        '</small>
                                                                      </div>';
                                                            }
                                                            
                                                            // Show availability status for completed bookings
                                                            echo '<div class="checkout-info mt-1">
                                                                    <small class="text-success">
                                                                        <i class="fas fa-check-circle me-1"></i><strong>SLOT TERSEDIA LAGI</strong><br>
                                                                        Ruangan bisa dibooking oleh user lain
                                                                    </small>
                                                                  </div>';
                                                            break;
                                                        default:
                                                            echo '<span class="badge bg-secondary status-badge">' . 
                                                                 ucfirst(htmlspecialchars($status)) . 
                                                                 '</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-info mb-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#detailModal<?= $booking['id_booking'] ?>">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </button>
                                                    
                                                    <?php if (($booking['status'] ?? '') === 'pending'): ?>
                                                        <form method="post" class="d-inline-block">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id_booking'] ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="btn btn-sm btn-success mb-1" 
                                                                    onclick="return confirm('Setujui peminjaman ini?')">
                                                                <i class="fas fa-check"></i> Setujui
                                                            </button>
                                                        </form>
                                                        
                                                        <button type="button" class="btn btn-sm btn-danger mb-1"
                                                                onclick="showRejectModal(<?= $booking['id_booking'] ?>)">
                                                            <i class="fas fa-times"></i> Tolak
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-search fa-3x mb-3"></i>
                                                    <h5>Tidak ada data ditemukan</h5>
                                                    <?php if (!empty($search)): ?>
                                                        <p>Tidak ada peminjaman yang sesuai dengan pencarian "<strong><?= htmlspecialchars($search) ?></strong>"</p>
                                                        <a href="?status=<?= $status ?>" class="btn btn-outline-primary">
                                                            <i class="fas fa-times me-1"></i>Reset Pencarian
                                                        </a>
                                                    <?php else: ?>
                                                        <p>Tidak ada peminjaman dengan status "<strong><?= ucfirst($status) ?></strong>"</p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Details untuk setiap booking - ENHANCED -->
    <?php foreach ($bookings as $booking): ?>
        <div class="modal fade" id="detailModal<?= $booking['id_booking'] ?>" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-info-circle me-2"></i>Detail Peminjaman #<?= $booking['id_booking'] ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th colspan="4" class="text-center bg-primary text-white">
                                            <i class="fas fa-calendar-alt me-2"></i>INFORMASI LENGKAP PEMINJAMAN
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th width="15%" class="bg-light">Nama Acara</th>
                                        <td width="35%"><?= htmlspecialchars($booking['nama_acara'] ?? '') ?></td>
                                        <th width="15%" class="bg-light">Email Peminjam</th>
                                        <td width="35%"><?= htmlspecialchars($booking['email'] ?? '') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Keterangan</th>
                                        <td><?= nl2br(htmlspecialchars($booking['keterangan'] ?? 'Tidak ada keterangan')) ?></td>
                                        <th class="bg-light">Penanggung Jawab</th>
                                        <td><?= htmlspecialchars($booking['nama_penanggungjawab'] ?? '') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Tanggal</th>
                                        <td>
                                            <i class="fas fa-calendar me-2 text-primary"></i>
                                            <?= formatDate($booking['tanggal']) ?>
                                        </td>
                                        <th class="bg-light">No. HP</th>
                                        <td>
                                            <i class="fas fa-phone me-2 text-success"></i>
                                            <?= htmlspecialchars($booking['no_penanggungjawab'] ?? 'Tidak ada') ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Waktu</th>
                                        <td>
                                            <i class="fas fa-clock me-2 text-info"></i>
                                            <?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>
                                        </td>
                                        <th class="bg-light">Ruangan</th>
                                        <td>
                                            <i class="fas fa-door-open me-2 text-warning"></i>
                                            <?= htmlspecialchars($booking['nama_ruang'] ?? '') ?>
                                            <?php if (!empty($booking['nama_gedung'])): ?>
                                                <br><small class="text-muted">Gedung: <?= htmlspecialchars($booking['nama_gedung']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Status</th>
                                        <td colspan="3">
                                            <?php
                                            switch ($booking['status']) {
                                                case 'pending':
                                                    echo '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-clock me-1"></i>Menunggu Persetujuan</span>';
                                                    break;
                                                case 'approve':
                                                    echo '<span class="badge bg-success fs-6"><i class="fas fa-check me-1"></i>Disetujui</span>';
                                                    break;
                                                case 'active':
                                                    echo '<span class="badge bg-info fs-6"><i class="fas fa-play me-1"></i>Sedang Berlangsung</span>';
                                                    break;
                                                case 'rejected':
                                                    echo '<span class="badge bg-danger fs-6"><i class="fas fa-times me-1"></i>Ditolak</span>';
                                                    break;
                                                case 'cancelled':
                                                    echo '<span class="badge bg-secondary fs-6"><i class="fas fa-ban me-1"></i>Dibatalkan</span>';
                                                    echo '<br><small class="text-success mt-2"><i class="fas fa-check-circle me-1"></i><strong>Slot tersedia lagi untuk user lain</strong></small>';
                                                    break;
                                                case 'done':
                                                    echo '<span class="badge bg-success fs-6"><i class="fas fa-check-double me-1"></i>Selesai</span>';
                                                    echo '<br><small class="text-success mt-2"><i class="fas fa-check-circle me-1"></i><strong>Slot tersedia lagi untuk user lain</strong></small>';
                                                    break;
                                                default:
                                                    echo '<span class="badge bg-secondary fs-6">' . htmlspecialchars($booking['status']) . '</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Enhanced checkout information -->
                                    <?php if (!empty($booking['checkout_time']) || !empty($booking['completion_note'])): ?>
                                    <tr>
                                        <th class="bg-light">Info Checkout</th>
                                        <td colspan="3">
                                            <?php if (!empty($booking['checkout_time'])): ?>
                                                <strong>Waktu Checkout:</strong> <?= date('d/m/Y H:i:s', strtotime($booking['checkout_time'])) ?><br>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($booking['checked_out_by'])): ?>
                                                <strong>Checkout oleh:</strong> 
                                                <?php 
                                                switch ($booking['checked_out_by']) {
                                                    case 'SYSTEM_AUTO':
                                                        echo '<span class="badge bg-warning text-dark">Sistem Otomatis</span>';
                                                        break;
                                                    case 'USER_MANUAL':
                                                        echo '<span class="badge bg-success">Mahasiswa/User</span>';
                                                        break;
                                                    case 'ADMIN_FORCE':
                                                        echo '<span class="badge bg-info">Admin (Force Checkout)</span>';
                                                        break;
                                                    default:
                                                        echo htmlspecialchars($booking['checked_out_by']);
                                                }
                                                ?><br>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($booking['completion_note'])): ?>
                                                <strong>Catatan:</strong> <?= htmlspecialchars($booking['completion_note']) ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Tutup
                        </button>
                        
                        <?php if ($booking['status'] === 'pending'): ?>
                            <form method="post" class="d-inline-block">
                                <input type="hidden" name="booking_id" value="<?= $booking['id_booking'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success" 
                                        onclick="return confirm('Setujui peminjaman ini?')">
                                    <i class="fas fa-check me-2"></i>Setujui
                                </button>
                            </form>
                            
                            <button type="button" class="btn btn-danger"
                                    onclick="showRejectModal(<?= $booking['id_booking'] ?>)">
                                <i class="fas fa-times me-2"></i>Tolak
                            </button>
                        <?php endif; ?>

                        <?php if (!empty($booking['email'])): ?>
                            <a href="mailto:<?= htmlspecialchars($booking['email']) ?>" class="btn btn-info">
                                <i class="fas fa-envelope me-2"></i>Email
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-times-circle me-2"></i>Tolak Peminjaman
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="rejectForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="booking_id" id="rejectBookingId">
                        
                        <div class="mb-3">
                            <label for="reject_reason" class="form-label">Alasan Penolakan *</label>
                            <textarea class="form-control" id="reject_reason" name="reject_reason" 
                                    rows="3" required placeholder="Masukkan alasan penolakan..."></textarea>
                            <div class="form-text">Alasan ini akan dikirim ke peminjam via email</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-2"></i>Tolak Peminjaman
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        function showRejectModal(bookingId) {
            document.getElementById('rejectBookingId').value = bookingId;
            document.getElementById('reject_reason').value = '';
            
            const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
            rejectModal.show();
        }
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-light)');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
        
        // Enhanced search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') {
                        this.closest('form').submit();
                    }
                });
            }
        });
    </script>
</body>
</html>