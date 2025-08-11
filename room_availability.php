<?php
// File: room_availability.php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$userRole = getUserRole();
$searchDate = $_GET['tanggal'] ?? date('Y-m-d');
$searchStartTime = $_GET['jam_mulai'] ?? date('H:00');
$searchEndTime = $_GET['jam_selesai'] ?? date('H:00', strtotime('+1 hour'));
$buildingFilter = $_GET['nama_gedung'] ?? '';

// Get all buildings
$stmt = $conn->prepare("SELECT * FROM tbl_gedung ORDER BY nama_gedung");
$stmt->execute();
$buildings = $stmt->fetchAll();

// Search available rooms
$availableRooms = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $availableRooms = findAvailableRooms($conn, $searchDate, $searchStartTime, $searchEndTime, $userRole);
    
    // Filter by building if specified
    if ($buildingFilter) {
        $availableRooms = array_filter($availableRooms, function($room) use ($buildingFilter) {
            return $room['id_gedung'] == $buildingFilter;
        });
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Ruangan Kosong - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="logged-in">
    <header>
        <?php include 'header.php'; ?>
    </header>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-search me-2"></i>Cari Ruangan Kosong
                        </h4>
                        <small>Temukan ruangan yang tersedia berdasarkan waktu yang Anda inginkan</small>
                    </div>
                    <div class="card-body">
                        <!-- Search Form -->
                        <form method="get" class="mb-4">
                            <input type="hidden" name="search" value="1">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="tanggal" class="form-label">Tanggal</label>
                                    <input type="date" class="form-control" id="tanggal" name="tanggal" 
                                           value="<?= $searchDate ?>" 
                                           min="<?= date('Y-m-d') ?>" 
                                           max="<?= date('Y-m-d', strtotime('+1 month')) ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label for="jam_mulai" class="form-label">Jam Mulai</label>
                                    <input type="time" class="form-control" id="jam_mulai" name="jam_mulai" 
                                           value="<?= $searchStartTime ?>" min="05:00" max="22:00" required>
                                </div>
                                <div class="col-md-2">
                                    <label for="jam_selesai" class="form-label">Jam Selesai</label>
                                    <input type="time" class="form-control" id="jam_selesai" name="jam_selesai" 
                                           value="<?= $searchEndTime ?>" min="05:30" max="22:00" required>
                                </div>
                                <div class="col-md-3">
                                    <label for="nama_gedung" class="form-label">Gedung (Opsional)</label>
                                    <select class="form-select" id="nama_gedung" name="nama_gedung">
                                        <option value="">Semua Gedung</option>
                                        <?php foreach ($buildings as $building): ?>
                                            <option value="<?= $building['id_gedung'] ?>" 
                                                    <?= $buildingFilter == $building['id_gedung'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($building['nama_gedung']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-search me-1"></i>Cari
                                    </button>
                                </div>
                            </div>
                        </form>

                        <!-- Search Results -->
                        <?php if (isset($_GET['search'])): ?>
                            <hr>
                            <h5>Hasil Pencarian untuk <?= formatDate($searchDate) ?> | <?= $searchStartTime ?> - <?= $searchEndTime ?></h5>
                            
                            <?php if (count($availableRooms) > 0): ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-success"><i class="fas fa-check-circle me-1"></i>Ruangan Tersedia</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped">
                                                <thead class="table-success">
                                                    <tr>
                                                        <th>Gedung</th>
                                                        <th>Ruangan</th>
                                                        <th>Kapasitas</th>
                                                        <th>Lokasi</th>
                                                        <th>Aksi</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($availableRooms as $room): ?>
                                                        <?php if ($room['availability_status'] === 'available'): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($room['nama_gedung']) ?></td>
                                                                <td>
                                                                    <span class="badge bg-success"><?= htmlspecialchars($room['nama_ruang']) ?></span>
                                                                </td>
                                                                <td><?= $room['kapasitas'] ?> orang</td>
                                                                <td><?= htmlspecialchars($room['lokasi'] ?? '-') ?></td>
                                                                <td>
                                                                    <button class="btn btn-sm btn-primary" 
                                                                            onclick="bookRoom(<?= $room['id_ruang'] ?>)">
                                                                        <i class="fas fa-plus"></i> Pesan
                                                                    </button>
                                                                    <button class="btn btn-sm btn-info" 
                                                                            data-bs-toggle="modal" 
                                                                            data-bs-target="#roomDetailModal<?= $room['id_ruang'] ?>">
                                                                        <i class="fas fa-info"></i> Detail
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6 class="text-danger"><i class="fas fa-times-circle me-1"></i>Ruangan Tidak Tersedia</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped">
                                                <thead class="table-danger">
                                                    <tr>
                                                        <th>Gedung</th>
                                                        <th>Ruangan</th>
                                                        <th>Status</th>
                                                        <th>Keterangan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($availableRooms as $room): ?>
                                                        <?php if ($room['availability_status'] !== 'available'): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($room['nama_gedung']) ?></td>
                                                                <td>
                                                                    <span class="badge bg-danger"><?= htmlspecialchars($room['nama_ruang']) ?></span>
                                                                </td>
                                                                <td>
                                                                    <?php if ($room['availability_status'] === 'locked'): ?>
                                                                        <span class="badge bg-warning">Dikunci</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-secondary">Dibooking</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($room['availability_status'] === 'locked'): ?>
                                                                        <?= htmlspecialchars($room['lock_reason'] ?? 'Ruangan dikunci') ?>
                                                                    <?php else: ?>
                                                                        Sudah ada yang memesan
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Room Detail Modals -->
                                <?php foreach ($availableRooms as $room): ?>
                                    <?php if ($room['availability_status'] === 'available'): ?>
                                        <div class="modal fade" id="roomDetailModal<?= $room['id_ruang'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-info text-white">
                                                        <h5 class="modal-title">Detail Ruangan <?= $room['nama_ruang'] ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-12 text-center mb-3">
                                                                <div class="bg-light p-3 rounded">
                                                                    <i class="fas fa-door-open fa-3x text-info mb-2"></i>
                                                                    <h4><?= $room['nama_ruang'] ?></h4>
                                                                    <p class="text-muted"><?= $room['nama_gedung'] ?></p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <table class="table table-borderless">
                                                            <tr>
                                                                <th><i class="fas fa-users me-2"></i>Kapasitas:</th>
                                                                <td><?= $room['kapasitas'] ?> orang</td>
                                                            </tr>
                                                            <tr>
                                                                <th><i class="fas fa-map-marker-alt me-2"></i>Lokasi:</th>
                                                                <td><?= htmlspecialchars($room['lokasi'] ?? 'Tidak ada info lokasi') ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th><i class="fas fa-info-circle me-2"></i>Deskripsi:</th>
                                                                <td><?= htmlspecialchars($room['description'] ?? 'Tidak ada deskripsi') ?></td>
                                                            </tr>
                                                            <tr>
                                                                <th><i class="fas fa-cogs me-2"></i>Fasilitas:</th>
                                                                <td>
                                                                    <?php 
                                                                    $facilities = getRoomFacilities($conn, $room['id_ruang']);
                                                                    if (!empty($facilities)): 
                                                                    ?>
                                                                        <?php foreach ($facilities as $facility): ?>
                                                                            <span class="badge bg-secondary me-1"><?= htmlspecialchars($facility) ?></span>
                                                                        <?php endforeach; ?>
                                                                    <?php else: ?>
                                                                        <em class="text-muted">Tidak ada info fasilitas</em>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                        <button type="button" class="btn btn-primary" 
                                                                onclick="bookRoom(<?= $room['id_ruang'] ?>)">
                                                            <i class="fas fa-plus me-1"></i>Pesan Ruangan
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Tidak ada hasil pencarian untuk kriteria yang Anda tentukan.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include 'footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function bookRoom(roomId) {
            const date = document.getElementById('tanggal').value;
            const startTime = document.getElementById('jam_mulai').value;
            
            // Redirect to main calendar with booking modal
            window.location.href = `index.php?date=${date}&room_id=${roomId}&auto_book=1&start_time=${startTime}`;
        }
        
        // Validate time input
        document.addEventListener('DOMContentLoaded', function() {
            const startTimeInput = document.getElementById('jam_mulai');
            const endTimeInput = document.getElementById('jam_selesai');
            
            function validateTime() {
                const startTime = startTimeInput.value;
                const endTime = endTimeInput.value;
                
                if (startTime && endTime && startTime >= endTime) {
                    alert('Jam selesai harus lebih dari jam mulai');
                    endTimeInput.value = '';
                }
            }
            
            startTimeInput.addEventListener('change', validateTime);
            endTimeInput.addEventListener('change', validateTime);
        });
    </script>
</body>
</html>