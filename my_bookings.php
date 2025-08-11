<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Get user bookings
$userId = $_SESSION['user_id'];

// Filter by status if provided
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$validStatuses = ['pending', 'approve', 'rejected', 'cancelled', 'active', 'done', 'all'];

if (!in_array($status, $validStatuses)) {
    $status = 'all';
}

// Prepare query based on status
if ($status === 'all') {
    $sql = "SELECT b.*, r.nama_ruang 
            FROM tbl_booking b 
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
            WHERE b.id_user = ? 
            ORDER BY b.tanggal DESC, b.jam_mulai DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$userId]);
} else {
    $sql = "SELECT b.*, r.nama_ruang 
            FROM tbl_booking b 
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang 
            WHERE b.id_user = ? AND b.status = ? 
            ORDER BY b.tanggal DESC, b.jam_mulai DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$userId, $status]);
}

$bookings = $stmt->fetchAll();

// Handle booking cancellation
$message = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $bookingId = $_POST['booking_id'] ?? 0;
    
    if ($bookingId) {
        $booking = getBookingById($conn, $bookingId);
        
        if ($booking && $booking['id_user'] == $userId) {
            if (in_array($booking['status'], ['pending', 'approve'])) {
                $stmt = $conn->prepare("UPDATE tbl_booking SET status = 'cancelled' WHERE id_booking = ?");
                $result = $stmt->execute([$bookingId]);
                
                if ($result) {
                    $message = 'Peminjaman berhasil dibatalkan.';
                    $alertType = 'success';
                    
                    // Redirect to refresh the page
                    header("Location: my_bookings.php?status=$status&cancelled=1");
                    exit;
                } else {
                    $message = 'Gagal membatalkan peminjaman.';
                    $alertType = 'danger';
                }
            } else {
                $message = 'Peminjaman tidak dapat dibatalkan.';
                $alertType = 'warning';
            }
        } else {
            $message = 'Peminjaman tidak ditemukan.';
            $alertType = 'danger';
        }
    }
}

// Handle booking checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout_booking'])) {
    $bookingId = $_POST['booking_id'] ?? 0;
    
    if ($bookingId) {
        $booking = getBookingById($conn, $bookingId);
        
        if ($booking && $booking['id_user'] == $userId) {
            if ($booking['status'] === 'active') {
                $stmt = $conn->prepare("UPDATE tbl_booking 
                                      SET status = 'done', 
                                          checkout_status = 'done', 
                                          checkout_time = NOW() 
                                      WHERE id_booking = ?");
                $result = $stmt->execute([$bookingId]);
                
                if ($result) {
                    $message = 'Checkout berhasil.';
                    $alertType = 'success';
                    
                    // Redirect to refresh the page
                    header("Location: my_bookings.php?status=$status&checkout=1");
                    exit;
                } else {
                    $message = 'Gagal melakukan checkout.';
                    $alertType = 'danger';
                }
            } else {
                $message = 'Peminjaman tidak dalam status aktif.';
                $alertType = 'warning';
            }
        } else {
            $message = 'Peminjaman tidak ditemukan.';
            $alertType = 'danger';
        }
    }
}

// Check for URL parameters
if (isset($_GET['cancelled']) && $_GET['cancelled'] == 1) {
    $message = 'Peminjaman berhasil dibatalkan.';
    $alertType = 'success';
}

if (isset($_GET['checkout']) && $_GET['checkout'] == 1) {
    $message = 'Checkout berhasil.';
    $alertType = 'success';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman Saya - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        /* CSS untuk modal detail */
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
    </style>
</head>
<body class="logged-in">
    <header>
        <?php include 'header.php'; ?>
    </header>

    <div class="container mt-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Peminjaman Saya</h4>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Status Filter Tabs -->
                <ul class="nav nav-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link <?= $status === 'all' ? 'active' : '' ?>" href="?status=all">
                            <i class="fas fa-list me-1"></i> Semua
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>" href="?status=pending">
                            <i class="fas fa-clock me-1"></i> Menunggu Persetujuan
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status === 'approve' ? 'active' : '' ?>" href="?status=approve">
                            <i class="fas fa-check me-1"></i> Disetujui
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status === 'active' ? 'active' : '' ?>" href="?status=active">
                            <i class="fas fa-play me-1"></i> Sedang Berlangsung
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $status === 'done' ? 'active' : '' ?>" href="?status=done">
                            <i class="fas fa-check-double me-1"></i> Selesai
                        </a>
                    </li>
                </ul>
                
               <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>No</th>
                                        <th>Nama Acara</th>
                                        <th>Ruangan</th>
                                        <th>Tanggal & Waktu</th>
                                        <th>Peminjam</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($bookings) > 0): ?>
                                        <?php $i = 1; foreach ($bookings as $booking): ?>
                                            <tr>
                                                <td><?= $i++ ?></td>
                                                <td><?= htmlspecialchars($booking['nama_acara'] ?? '') ?></td>
                                                <td>
                                                    <?= htmlspecialchars($booking['nama_ruang'] ?? '') ?>
                                                    <?php if (!empty($booking['nama_gedung'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($booking['nama_gedung']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= formatDate($booking['tanggal']) ?><br>
                                                    <small><?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?></small>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($booking['email'] ?? '') ?><br>
                                                    <small>PIC: <?= htmlspecialchars($booking['nama_penanggungjawab'] ?? '') ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusBadge = '';
                                                    switch ($booking['status']) {
                                                        case 'pending':
                                                            $statusBadge = '<span class="badge bg-warning">Menunggu Persetujuan</span>';
                                                            break;
                                                        case 'approve':
                                                            $statusBadge = '<span class="badge bg-success">Disetujui</span>';
                                                            break;
                                                        case 'active':
                                                            $statusBadge = '<span class="badge bg-info">Sedang Berlangsung</span>';
                                                            break;
                                                        case 'rejected':
                                                            $statusBadge = '<span class="badge bg-danger">Ditolak</span>';
                                                            break;
                                                        case 'cancelled':
                                                            $statusBadge = '<span class="badge bg-secondary">Dibatalkan</span>';
                                                            break;
                                                        case 'done':
                                                            $statusBadge = '<span class="badge bg-success">Selesai</span>';
                                                            break;
                                                    }
                                                    echo $statusBadge;
                                                    ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-info mb-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#detailModal<?= $booking['id_booking'] ?>">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </button>
                                                    
                                                    <?php if ($booking['status'] === 'pending'): ?>
                                                        <form method="post" class="d-inline-block">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id_booking'] ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="btn btn-sm btn-success mb-1" 
                                                                    onclick="return confirm('Setujui peminjaman ini?')">
                                                                <i class="fas fa-check"></i> Setujui
                                                            </button>
                                                        </form>
                                                        
                                                        <form method="post" class="d-inline-block">
                                                            <input type="hidden" name="booking_id" value="<?= $booking['id_booking'] ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <button type="submit" class="btn btn-sm btn-danger mb-1" 
                                                                    onclick="return confirm('Tolak peminjaman ini?')">
                                                                <i class="fas fa-times"></i> Tolak
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-3">Tidak ada data peminjaman yang ditemukan.</td>
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

    <!-- Modal Details untuk setiap booking -->
    <?php foreach ($bookings as $booking): ?>
        <!-- Detail Modal -->
        <div class="modal fade" id="detailModal<?= $booking['id_booking'] ?>" tabindex="-1" aria-labelledby="detailModalLabel<?= $booking['id_booking'] ?>" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="detailModalLabel<?= $booking['id_booking'] ?>">
                            <i class="fas fa-info-circle me-2"></i>Detail Peminjaman #<?= $booking['id_booking'] ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Tabel Informasi Lengkap -->
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th colspan="4" class="text-center bg-primary text-white">
                                            <i class="fas fa-calendar-alt me-2"></i>INFORMASI LENGKAP PEMINJAMAN
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Baris 1: Informasi Dasar -->
                                    <tr>
                                        <th width="15%" class="bg-light">Nama Acara</th>
                                        <td width="35%"><?= htmlspecialchars($booking['nama_acara'] ?? '') ?></td>
                                        <th width="15%" class="bg-light">Email Peminjam</th>
                                        <td width="35%"><?= htmlspecialchars($booking['email'] ?? '') ?></td>
                                    </tr>
                                    
                                    <!-- Baris 2: Keterangan dan PIC -->
                                    <tr>
                                        <th class="bg-light">Keterangan</th>
                                        <td><?= nl2br(htmlspecialchars($booking['keterangan'] ?? 'Tidak ada keterangan')) ?></td>
                                        <th class="bg-light">Penanggung Jawab</th>
                                        <td><?= htmlspecialchars($booking['nama_penanggungjawab'] ?? '') ?></td>
                                    </tr>
                                    
                                    <!-- Baris 3: Tanggal dan No HP -->
                                    <tr>
                                        <th class="bg-light">Tanggal</th>
                                        <td>
                                            <i class="fas fa-calendar me-2 text-primary"></i>
                                            <?= formatDate($booking['tanggal']) ?>
                                            <br>
                                            <small class="text-muted"><?= date('l', strtotime($booking['tanggal'])) ?></small>
                                        </td>
                                        <th class="bg-light">No. HP</th>
                                        <td>
                                            <i class="fas fa-phone me-2 text-success"></i>
                                            <?= htmlspecialchars($booking['no_penanggungjawab'] ?? 'Tidak ada') ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Baris 4: Waktu dan Ruangan -->
                                    <tr>
                                        <th class="bg-light">Waktu</th>
                                        <td>
                                            <i class="fas fa-clock me-2 text-info"></i>
                                            <?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>
                                            <br>
                                            <small class="text-muted">
                                                Durasi: <?php 
                                                $start = new DateTime($booking['jam_mulai']);
                                                $end = new DateTime($booking['jam_selesai']);
                                                $diff = $start->diff($end);
                                                echo $diff->h . ' jam ' . $diff->i . ' menit';
                                                ?>
                                            </small>
                                        </td>
                                        <th class="bg-light">Ruangan</th>
                                        <td>
                                            <i class="fas fa-door-open me-2 text-warning"></i>
                                            <?= htmlspecialchars($booking['nama_ruang'] ?? '') ?>
                                            <?php if (!empty($booking['nama_gedung'])): ?>
                                                <br><small class="text-muted">Gedung: <?= htmlspecialchars($booking['nama_gedung']) ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($booking['kapasitas'])): ?>
                                                <br><small class="text-muted">Kapasitas: <?= $booking['kapasitas'] ?> orang</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Baris 5: Status -->
                                    <tr>
                                        <th class="bg-light">Status</th>
                                        <td colspan="3">
                                            <?php
                                            $statusBadge = '';
                                            switch ($booking['status']) {
                                                case 'pending':
                                                    $statusBadge = '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-clock me-1"></i>Menunggu Persetujuan</span>';
                                                    break;
                                                case 'approve':
                                                    $statusBadge = '<span class="badge bg-success fs-6"><i class="fas fa-check me-1"></i>Disetujui</span>';
                                                    break;
                                                case 'active':
                                                    $statusBadge = '<span class="badge bg-info fs-6"><i class="fas fa-play me-1"></i>Sedang Berlangsung</span>';
                                                    break;
                                                case 'rejected':
                                                    $statusBadge = '<span class="badge bg-danger fs-6"><i class="fas fa-times me-1"></i>Ditolak</span>';
                                                    break;
                                                case 'cancelled':
                                                    $statusBadge = '<span class="badge bg-secondary fs-6"><i class="fas fa-ban me-1"></i>Dibatalkan</span>';
                                                    break;
                                                case 'done':
                                                    $statusBadge = '<span class="badge bg-success fs-6"><i class="fas fa-check-double me-1"></i>Selesai</span>';
                                                    break;
                                                default:
                                                    $statusBadge = '<span class="badge bg-secondary fs-6">' . htmlspecialchars($booking['status']) . '</span>';
                                            }
                                            echo $statusBadge;
                                            ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Baris 6: Informasi Tambahan (jika ada) -->
                                    <?php if ($booking['status'] === 'done' && !empty($booking['checkout_time'])): ?>
                                    <tr>
                                        <th class="bg-light">Waktu Checkout</th>
                                        <td colspan="3">
                                            <i class="fas fa-sign-out-alt me-2 text-danger"></i>
                                            <?= date('d/m/Y H:i', strtotime($booking['checkout_time'])) ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <!-- Baris 7: Timestamp (jika ada) -->
                                    <?php if (!empty($booking['created_at'])): ?>
                                    <tr>
                                        <th class="bg-light">Dibuat Pada</th>
                                        <td>
                                            <i class="fas fa-plus-circle me-2 text-success"></i>
                                            <?= date('d/m/Y H:i', strtotime($booking['created_at'])) ?>
                                        </td>
                                        <th class="bg-light">Terakhir Update</th>
                                        <td>
                                            <i class="fas fa-edit me-2 text-primary"></i>
                                            <?= !empty($booking['updated_at']) ? date('d/m/Y H:i', strtotime($booking['updated_at'])) : 'Belum ada update' ?>
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
                        
                        <!-- Tombol Aksi berdasarkan Status -->
                        <?php if ($booking['status'] === 'pending'): ?>
                            <form method="post" class="d-inline-block">
                                <input type="hidden" name="booking_id" value="<?= $booking['id_booking'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success" 
                                        onclick="return confirm('Setujui peminjaman ini?')">
                                    <i class="fas fa-check me-2"></i>Setujui
                                </button>
                            </form>
                            
                            <form method="post" class="d-inline-block">
                                <input type="hidden" name="booking_id" value="<?= $booking['id_booking'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn btn-danger" 
                                        onclick="return confirm('Tolak peminjaman ini?')">
                                    <i class="fas fa-times me-2"></i>Tolak
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- Tombol Email -->
                        <?php if (!empty($booking['email'])): ?>
                            <?php
                            $emailSubject = "Terkait Peminjaman Ruangan " . ($booking['nama_ruang'] ?? '');
                            $emailBody = "Halo " . ($booking['nama_penanggungjawab'] ?? '') . ",\n\n";
                            $emailBody .= "Terkait peminjaman ruangan " . ($booking['nama_ruang'] ?? '') . " ";
                            $emailBody .= "pada " . formatDate($booking['tanggal']) . " ";
                            $emailBody .= "pukul " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . ".\n\n";
                            $emailBody .= "Status: " . ucfirst($booking['status']) . "\n\n";
                            $emailBody .= "Terima kasih.\n\nSalam,\nAdmin Peminjaman Ruangan";
                            ?>
                            <a href="mailto:<?= htmlspecialchars($booking['email']) ?>?subject=<?= urlencode($emailSubject) ?>&body=<?= urlencode($emailBody) ?>" 
                               class="btn btn-info">
                                <i class="fas fa-envelope me-2"></i>Kirim Email
                            </a>
                        <?php endif; ?>

                        <!-- Tombol WhatsApp -->
                        <?php if (!empty($booking['no_penanggungjawab'])): ?>
                            <?php 
                            // Clean phone number
                            $phone = preg_replace('/[^0-9]/', '', $booking['no_penanggungjawab']);
                            if (substr($phone, 0, 1) === '0') {
                                $phone = '62' . substr($phone, 1);
                            }
                            
                            // WhatsApp message
                            $whatsappMessage = "Halo " . ($booking['nama_penanggungjawab'] ?? '') . ", ";
                            $whatsappMessage .= "terkait peminjaman ruangan " . ($booking['nama_ruang'] ?? '') . " ";
                            $whatsappMessage .= "pada " . formatDate($booking['tanggal']) . " ";
                            $whatsappMessage .= "pukul " . formatTime($booking['jam_mulai']) . " - " . formatTime($booking['jam_selesai']) . ". ";
                            $whatsappMessage .= "Status: " . ucfirst($booking['status']) . ".";
                            ?>
                            <a href="https://wa.me/<?= $phone ?>?text=<?= urlencode($whatsappMessage) ?>" 
                               target="_blank" class="btn btn-success">
                                <i class="fab fa-whatsapp me-2"></i>WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <footer class="mt-5 py-3 bg-light">
        <?php include 'footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="main.js"></script>
</body>
</html>
