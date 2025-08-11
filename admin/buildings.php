<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// Message variable for notifications
$message = '';
$alertType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add or edit building
    if (isset($_POST['save_building'])) {
        $buildingId = $_POST['id_gedung'] ?? '';
        $buildingName = trim($_POST['nama_gedung'] ?? '');
        $buildingDescription = trim($_POST['deskripsi'] ?? '');
        
        // Validate inputs
        if (empty($buildingName)) {
            $message = 'Nama gedung harus diisi.';
            $alertType = 'danger';
        } else {
            try {
                // Check if it's an edit or add
                if ($buildingId) {
                    // Check if name already exists for other buildings
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_gedung WHERE nama_gedung = ? AND id_gedung != ?");
                    $stmt->execute([$buildingName, $buildingId]);
                    $nameExists = $stmt->fetchColumn() > 0;
                    
                    if ($nameExists) {
                        $message = 'Nama gedung sudah digunakan oleh gedung lain.';
                        $alertType = 'warning';
                    } else {
                        // Update existing building
                        $stmt = $conn->prepare("UPDATE tbl_gedung SET nama_gedung = ?, deskripsi = ?, updated_at = NOW() WHERE id_gedung = ?");
                        $result = $stmt->execute([$buildingName, $buildingDescription, $buildingId]);
                        
                        if ($result) {
                            $message = 'Gedung berhasil diperbarui.';
                            $alertType = 'success';
                        } else {
                            $message = 'Gagal memperbarui gedung.';
                            $alertType = 'danger';
                        }
                    }
                } else {
                    // Check if name already exists
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_gedung WHERE nama_gedung = ?");
                    $stmt->execute([$buildingName]);
                    $nameExists = $stmt->fetchColumn() > 0;
                    
                    if ($nameExists) {
                        $message = 'Nama gedung sudah digunakan.';
                        $alertType = 'warning';
                    } else {
                        // Add new building
                        $stmt = $conn->prepare("INSERT INTO tbl_gedung (nama_gedung, deskripsi, created_at) VALUES (?, ?, ?, NOW())");
                        $result = $stmt->execute([$buildingName, $buildingDescription]);
                        
                        if ($result) {
                            $message = 'Gedung berhasil ditambahkan.';
                            $alertType = 'success';
                        } else {
                            $message = 'Gagal menambahkan gedung.';
                            $alertType = 'danger';
                        }
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
    
    // Delete building
    if (isset($_POST['delete_building'])) {
        $buildingId = $_POST['building_id'] ?? '';
        
        if ($buildingId) {
            try {
                // Check if building has rooms
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_ruang WHERE id_gedung = ?");
                $stmt->execute([$buildingId]);
                $hasRooms = $stmt->fetchColumn() > 0;
                
                if ($hasRooms) {
                    $message = 'Tidak dapat menghapus gedung karena masih ada ruangan yang terkait.';
                    $alertType = 'warning';
                } else {
                    // Delete building
                    $stmt = $conn->prepare("DELETE FROM tbl_gedung WHERE id_gedung = ?");
                    $result = $stmt->execute([$buildingId]);
                    
                    if ($result) {
                        $message = 'Gedung berhasil dihapus.';
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal menghapus gedung.';
                        $alertType = 'danger';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
}

// Get all buildings with room count
$stmt = $conn->prepare("
    SELECT g.*, 
           COUNT(r.id_ruang) as room_count,
           g.created_at,
           g.updated_at
    FROM tbl_gedung g
    LEFT JOIN tbl_ruang r ON g.id_gedung = r.id_gedung
    GROUP BY g.id_gedung
    ORDER BY g.nama_gedung
");
$stmt->execute();
$buildings = $stmt->fetchAll();

// Set back path for header
$backPath = '../';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Gedung - <?= $config['site_name'] ?? 'STIE MCE' ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .building-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #dee2e6;
        }
        .building-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .building-name {
            background: linear-gradient(135deg, #007bff, #0d6efd);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .building-stats {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }
        .stats-item {
            text-align: center;
            padding: 10px;
        }
        .stats-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #007bff;
        }
        .action-buttons .btn {
            margin: 2px;
        }
        .building-icon {
            font-size: 2rem;
            color: #007bff;
            margin-bottom: 10px;
        }
    </style>
</head>
<body class="admin-theme">
    <header>
        <?php include '../header.php'; ?>
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
                        <a href="buildings.php" class="list-group-item list-group-item-action active">
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
                <!-- Building Statistics -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="building-stats">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="stats-item">
                                        <div class="stats-number"><?= count($buildings) ?></div>
                                        <small class="text-muted">Total Gedung</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stats-item">
                                        <?php
                                        $totalRooms = 0;
                                        foreach ($buildings as $building) {
                                            $totalRooms += $building['room_count'];
                                        }
                                        ?>
                                        <div class="stats-number"><?= $totalRooms ?></div>
                                        <small class="text-muted">Total Ruangan</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stats-item">
                                        <?php
                                        $activeBuildings = 0;
                                        foreach ($buildings as $building) {
                                            if ($building['room_count'] > 0) $activeBuildings++;
                                        }
                                        ?>
                                        <div class="stats-number"><?= $activeBuildings ?></div>
                                        <small class="text-muted">Gedung Aktif</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stats-item">
                                        <div class="stats-number"><?= count($buildings) - $activeBuildings ?></div>
                                        <small class="text-muted">Gedung Kosong</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Header with Add Button -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-building me-2"></i>Kelola Gedung
                        </h4>
                        <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#addBuildingModal">
                            <i class="fas fa-plus me-1"></i> Tambah Gedung
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <i class="fas fa-<?= $alertType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                                <?= $message ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Buildings Grid -->
                        <?php if (count($buildings) > 0): ?>
                            <div class="row">
                                <?php foreach ($buildings as $building): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card building-card h-100">
                                            <div class="card-header bg-light text-center">
                                                <div class="building-icon">
                                                    <i class="fas fa-building"></i>
                                                </div>
                                                <h6 class="mb-2 text-primary"><?= htmlspecialchars($building['nama_gedung']) ?></h6>
                                                <span class="badge bg-<?= $building['room_count'] > 0 ? 'success' : 'secondary' ?>">
                                                    <?= $building['room_count'] ?> ruangan
                                                </span>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <small class="text-muted d-block">Deskripsi:</small>
                                                    <span class="fw-medium">
                                                        <?= !empty($building['deskripsi']) ? htmlspecialchars($building['deskripsi']) : 'Tidak ada informasi deskripsi' ?>
                                                    </span>
                                                </div>
                                                
                                                <?php if (!empty($building['deskripsi'])): ?>
                                                <div class="mb-3">
                                                    <small class="text-muted d-block">Deskripsi:</small>
                                                    <p class="small text-muted mb-0">
                                                        <?= nl2br(htmlspecialchars($building['deskripsi'])) ?>
                                                    </p>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="row text-center">
                                                    <div class="col-6">
                                                        <small class="text-muted d-block">Dibuat:</small>
                                                        <small class="fw-medium">
                                                            <?= $building['created_at'] ? date('d/m/Y', strtotime($building['created_at'])) : 'N/A' ?>
                                                        </small>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted d-block">Update:</small>
                                                        <small class="fw-medium">
                                                            <?= $building['updated_at'] ? date('d/m/Y', strtotime($building['updated_at'])) : 'N/A' ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card-footer bg-light">
                                                <div class="action-buttons d-flex justify-content-center">
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editBuildingModal<?= $building['id_gedung'] ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewBuildingRooms(<?= $building['id_gedung'] ?>)">
                                                        <i class="fas fa-door-open"></i> Ruangan
                                                    </button>
                                                    
                                                    <button type="button" class="btn btn-sm btn-danger" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deleteBuildingModal<?= $building['id_gedung'] ?>">
                                                        <i class="fas fa-trash"></i> Hapus
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Edit Building Modal -->
                                    <div class="modal fade" id="editBuildingModal<?= $building['id_gedung'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title">
                                                        <i class="fas fa-edit me-2"></i>Edit Gedung
                                                    </h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="post">
                                                        <input type="hidden" name="id_gedung" value="<?= $building['id_gedung'] ?>">
                                                        
                                                        <div class="mb-3">
                                                            <label for="nama_gedung<?= $building['id_gedung'] ?>" class="form-label">
                                                                Nama Gedung <span class="text-danger">*</span>
                                                            </label>
                                                            <input type="text" class="form-control" 
                                                                   id="nama_gedung<?= $building['id_gedung'] ?>" 
                                                                   name="nama_gedung" 
                                                                   value="<?= htmlspecialchars($building['nama_gedung']) ?>" 
                                                                   placeholder="Contoh: Gedung Utama"
                                                                   required>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label for="deskripsi<?= $building['id_gedung'] ?>" class="form-label">Deskripsi</label>
                                                            <textarea class="form-control" 
                                                                      id="deskripsi<?= $building['id_gedung'] ?>" 
                                                                      name="deskripsi" 
                                                                      rows="3" 
                                                                      placeholder="Deskripsi gedung, fasilitas, dll."><?= htmlspecialchars($building['deskripsi'] ?? '') ?></textarea>
                                                        </div>
                                                        
                                                        <div class="d-grid">
                                                            <button type="submit" name="save_building" class="btn btn-primary">
                                                                <i class="fas fa-save me-2"></i>Simpan Perubahan
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Delete Building Modal -->
                                    <div class="modal fade" id="deleteBuildingModal<?= $building['id_gedung'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title">
                                                        <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus
                                                    </h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="text-center mb-3">
                                                        <i class="fas fa-building fa-3x text-danger mb-3"></i>
                                                        <h6>Apakah Anda yakin ingin menghapus gedung:</h6>
                                                        <h5 class="text-primary"><?= htmlspecialchars($building['nama_gedung']) ?></h5>
                                                    </div>
                                                    
                                                    <?php if ($building['room_count'] > 0): ?>
                                                        <div class="alert alert-warning">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                                            <strong>Perhatian:</strong> Gedung ini memiliki <?= $building['room_count'] ?> ruangan. 
                                                            Hapus semua ruangan terlebih dahulu sebelum menghapus gedung.
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="alert alert-danger">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                                            <strong>Peringatan:</strong> Tindakan ini tidak dapat dibatalkan!
                                                        </div>
                                                        
                                                        <form method="post">
                                                            <input type="hidden" name="building_id" value="<?= $building['id_gedung'] ?>">
                                                            <div class="d-grid">
                                                                <button type="submit" name="delete_building" class="btn btn-danger">
                                                                    <i class="fas fa-trash me-2"></i>Ya, Hapus Gedung
                                                                </button>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-building fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Belum ada data gedung</h5>
                                <p class="text-muted">Klik tombol "Tambah Gedung" untuk menambahkan gedung baru.</p>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBuildingModal">
                                    <i class="fas fa-plus me-2"></i>Tambah Gedung Pertama
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Building Modal -->
    <div class="modal fade" id="addBuildingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Tambah Gedung Baru
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="post" id="addBuildingForm">
                        <div class="mb-3">
                            <label for="nama_gedung_new" class="form-label">
                                Nama Gedung <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="nama_gedung_new" name="nama_gedung" 
                                   placeholder="Contoh: Gedung Utama, Gedung K, Gedung Lab" required>
                            <div class="form-text">Gunakan nama yang mudah diingat dan dikenali</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="deskripsi_new" class="form-label">Deskripsi</label>
                            <textarea class="form-control" id="deskripsi_new" name="deskripsi" rows="3" 
                                      placeholder="Deskripsi gedung, fasilitas yang tersedia, dll."></textarea>
                            <div class="form-text">Informasi tambahan tentang gedung</div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" name="save_building" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Tambah Gedung
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        function viewBuildingRooms(buildingId) {
            // Redirect to rooms page with building filter
            window.location.href = `rooms.php?building_id=${buildingId}`;
        }
        
        // Auto-dismiss alerts
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
        
        // Form validation
        document.getElementById('addBuildingForm').addEventListener('submit', function(e) {
            const buildingName = document.getElementById('nama_gedung_new');
            
            if (buildingName.value.trim() === '') {
                e.preventDefault();
                alert('Nama gedung harus diisi!');
                buildingName.focus();
                return;
            }
            
            if (buildingName.value.trim().length < 2) {
                e.preventDefault();
                alert('Nama gedung minimal 2 karakter!');
                buildingName.focus();
                return;
            }
        });
        
        // Edit form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const buildingName = form.querySelector('input[name="nama_gedung"]');
                
                if (buildingName && buildingName.value.trim() === '') {
                    e.preventDefault();
                    alert('Nama gedung harus diisi!');
                    buildingName.focus();
                    return;
                }
                
                if (buildingName && buildingName.value.trim().length < 2) {
                    e.preventDefault();
                    alert('Nama gedung minimal 2 karakter!');
                    buildingName.focus();
                    return;
                }
            });
        });
    </script>
</body>
</html>