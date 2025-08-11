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
    // Add or edit room
    if (isset($_POST['save_room'])) {
        $roomId = $_POST['room_id'] ?? '';
        $buildingId = intval($_POST['id_gedung'] ?? 0);
        $roomName = trim($_POST['nama_ruang'] ?? '');
        $roomNumber = trim($_POST['room_number'] ?? '');
        $capacity = intval($_POST['kapasitas'] ?? 0);
        $location = trim($_POST['lokasi'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $allowedRoles = $_POST['allowed_roles'] ?? [];
        $facilities = $_POST['facilities'] ?? [];
        
        // Get building code for room name format
        $stmt = $conn->prepare("SELECT nama_gedung FROM tbl_gedung WHERE id_gedung = ?");
        $stmt->execute([$buildingId]);
        $buildingName = $stmt->fetchColumn();
        $buildingCode = substr($buildingName, -1);
        
        // Construct room name if not provided
        if (empty($roomName) && !empty($roomNumber)) {
            $roomName = $buildingCode . '-' . $roomNumber;
        }
        
        // Validate inputs
        if (empty($roomName) || $capacity <= 0 || empty($location) || $buildingId <= 0) {
            $message = 'Semua field harus diisi dengan benar.';
            $alertType = 'danger';
        } else {
            try {
                // Check if building exists
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_gedung WHERE id_gedung = ?");
                $stmt->execute([$buildingId]);
                $buildingExists = $stmt->fetchColumn() > 0;
                
                if (!$buildingExists) {
                    $message = 'Gedung tidak ditemukan.';
                    $alertType = 'danger';
                } else {
                    // Check if room name follows the pattern X-Y
                    if (!preg_match('/^[A-Z]-\d+$/', $roomName)) {
                        $message = 'Nama ruangan harus mengikuti format: {Kode Gedung}-{Nomor}, contoh: K-1, L-2';
                        $alertType = 'danger';
                    } else {
                        // Check if first character of room name matches building code
                        $roomCode = substr($roomName, 0, 1);
                        if ($roomCode !== $buildingCode) {
                            $message = 'Kode ruangan (' . $roomCode . ') harus sesuai dengan kode gedung (' . $buildingCode . ').';
                            $alertType = 'danger';
                        } else {
                            // Process allowed roles
                            $allowedRolesStr = implode(',', $allowedRoles);
                            if (empty($allowedRolesStr)) {
                                $allowedRolesStr = 'admin,mahasiswa,dosen,karyawan'; // Default all roles
                            }
                            
                            // Process facilities
                            $facilitiesJson = json_encode($facilities);
                            
                            // Check if it's an edit or add
                            if ($roomId) {
                                // Update existing room
                                $stmt = $conn->prepare("UPDATE tbl_ruang SET id_gedung = ?, nama_ruang = ?, kapasitas = ?, lokasi = ?, description = ?, allowed_roles = ?, fasilitas = ? WHERE id_ruang = ?");
                                $result = $stmt->execute([$buildingId, $roomName, $capacity, $location, $description, $allowedRolesStr, $facilitiesJson, $roomId]);
                                
                                if ($result) {
                                    $message = 'Ruangan berhasil diperbarui.';
                                    $alertType = 'success';
                                } else {
                                    $message = 'Gagal memperbarui ruangan.';
                                    $alertType = 'danger';
                                }
                            } else {
                                // Check if room already exists
                                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_ruang WHERE nama_ruang = ? AND id_gedung = ?");
                                $stmt->execute([$roomName, $buildingId]);
                                $roomExists = $stmt->fetchColumn() > 0;
                                
                                if ($roomExists) {
                                    $message = 'Ruangan dengan nama ' . $roomName . ' sudah ada di gedung ini.';
                                    $alertType = 'danger';
                                } else {
                                    // Add new room
                                    $stmt = $conn->prepare("INSERT INTO tbl_ruang (id_gedung, nama_ruang, kapasitas, lokasi, description, allowed_roles, fasilitas) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                    $result = $stmt->execute([$buildingId, $roomName, $capacity, $location, $description, $allowedRolesStr, $facilitiesJson]);
                                    
                                    if ($result) {
                                        $message = 'Ruangan berhasil ditambahkan.';
                                        $alertType = 'success';
                                    } else {
                                        $message = 'Gagal menambahkan ruangan.';
                                        $alertType = 'danger';
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
    
    // Delete room
    if (isset($_POST['delete_room'])) {
        $roomId = $_POST['room_id'] ?? '';
        
        if ($roomId) {
            try {
                // Check if room has bookings
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_booking WHERE id_ruang = ?");
                $stmt->execute([$roomId]);
                $hasBookings = $stmt->fetchColumn() > 0;
                
                if ($hasBookings) {
                    $message = 'Tidak dapat menghapus ruangan karena masih ada peminjaman yang terkait.';
                    $alertType = 'warning';
                } else {
                    // Delete room
                    $stmt = $conn->prepare("DELETE FROM tbl_ruang WHERE id_ruang = ?");
                    $result = $stmt->execute([$roomId]);
                    
                    if ($result) {
                        $message = 'Ruangan berhasil dihapus.';
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal menghapus ruangan.';
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

// Get all rooms with building info
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung FROM tbl_ruang r 
                       JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                       ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

// Get all buildings
$stmt = $conn->prepare("SELECT * FROM tbl_gedung ORDER BY nama_gedung");
$stmt->execute();
$buildings = $stmt->fetchAll();

// Available facilities
$availableFacilities = [
    'AC', 'Proyektor', 'Whiteboard', 'Smart Board', 'Sound System', 
    'Microphone', 'Speaker', 'WiFi', 'Kursi', 'Meja', 'Podium',
    'LCD TV', 'Komputer', 'Printer', 'CCTV', 'Kamera'
];

// Available roles
$availableRoles = [
    'admin' => 'Administrator',
    'mahasiswa' => 'Mahasiswa', 
    'dosen' => 'Dosen',
    'karyawan' => 'Karyawan',
    'cs' => 'Customer Service',
    'satpam' => 'Satpam'
];

// Set back path for header
$backPath = '../';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Ruangan - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .facility-item {
            display: inline-block;
            margin: 2px;
        }
        .facility-badge {
            background-color: #e3f2fd;
            color: #0277bd;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            border: 1px solid #81d4fa;
        }
        .role-restriction {
            font-size: 0.85rem;
        }
        .room-card {
            transition: all 0.3s ease;
        }
        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
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
                        <a href="rooms.php" class="list-group-item list-group-item-action active">
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
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Kelola Ruangan</h4>
                        <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                            <i class="fas fa-plus me-1"></i> Tambah Ruangan
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <?= $message ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Room Cards Grid -->
                        <div class="row">
                            <?php if (count($rooms) > 0): ?>
                                <?php foreach ($rooms as $room): ?>
                                    <?php 
                                    $facilities = getRoomFacilities($conn, $room['id_ruang']);
                                    $allowedRoles = explode(',', $room['allowed_roles']);
                                    ?>
                                    <div class="col-lg-6 col-xl-4 mb-4">
                                        <div class="card room-card h-100">
                                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h5 class="mb-0">
                                                        <span class="badge bg-primary"><?= htmlspecialchars($room['nama_ruang']) ?></span>
                                                    </h5>
                                                    <small class="text-muted"><?= htmlspecialchars($room['nama_gedung']) ?></small>
                                                </div>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-cog"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#editRoomModal<?= $room['id_ruang'] ?>">
                                                                <i class="fas fa-edit me-2"></i>Edit
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#deleteRoomModal<?= $room['id_ruang'] ?>">
                                                                <i class="fas fa-trash me-2"></i>Hapus
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="row mb-3">
                                                    <div class="col-6">
                                                        <i class="fas fa-users text-primary me-2"></i>
                                                        <strong>Kapasitas:</strong><br>
                                                        <span class="fs-5"><?= $room['kapasitas'] ?></span> orang
                                                    </div>
                                                    <div class="col-6">
                                                        <i class="fas fa-map-marker-alt text-primary me-2"></i>
                                                        <strong>Lokasi:</strong><br>
                                                        <?= htmlspecialchars($room['lokasi']) ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($room['description'])): ?>
                                                    <div class="mb-3">
                                                        <i class="fas fa-info-circle text-primary me-2"></i>
                                                        <strong>Deskripsi:</strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($room['description']) ?></small>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <!-- Facilities -->
                                                <div class="mb-3">
                                                    <i class="fas fa-cogs text-primary me-2"></i>
                                                    <strong>Fasilitas:</strong><br>
                                                    <?php if (!empty($facilities)): ?>
                                                        <?php foreach ($facilities as $facility): ?>
                                                            <span class="facility-badge facility-item"><?= htmlspecialchars($facility) ?></span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <small class="text-muted">Tidak ada info fasilitas</small>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <!-- Role Restrictions -->
                                                <div class="role-restriction">
                                                    <i class="fas fa-user-shield text-primary me-2"></i>
                                                    <strong>Akses:</strong><br>
                                                    <?php if (count($allowedRoles) >= 4): ?>
                                                        <span class="badge bg-success">Semua Role</span>
                                                    <?php else: ?>
                                                        <?php foreach ($allowedRoles as $role): ?>
                                                            <span class="badge bg-secondary me-1"><?= ucfirst(trim($role)) ?></span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="card-footer text-center">
                                                <small class="text-muted">ID: <?= $room['id_ruang'] ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Edit Modal for each room -->
                                    <div class="modal fade" id="editRoomModal<?= $room['id_ruang'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title">Edit Ruangan <?= htmlspecialchars($room['nama_ruang']) ?></h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="post">
                                                        <input type="hidden" name="room_id" value="<?= $room['id_ruang'] ?>">
                                                        
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Gedung</label>
                                                                    <select class="form-select" name="id_gedung" required>
                                                                        <?php foreach ($buildings as $building): ?>
                                                                            <option value="<?= $building['id_gedung'] ?>" 
                                                                                    <?= $room['id_gedung'] == $building['id_gedung'] ? 'selected' : '' ?>>
                                                                                <?= htmlspecialchars($building['nama_gedung']) ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Nama Ruangan</label>
                                                                    <input type="text" class="form-control" name="nama_ruang" 
                                                                           value="<?= htmlspecialchars($room['nama_ruang']) ?>" required>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Kapasitas</label>
                                                                    <input type="number" class="form-control" name="kapasitas" 
                                                                           value="<?= $room['kapasitas'] ?>" min="1" required>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="mb-3">
                                                                    <label class="form-label">Lokasi</label>
                                                                    <input type="text" class="form-control" name="lokasi" 
                                                                           value="<?= htmlspecialchars($room['lokasi']) ?>" required>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Deskripsi</label>
                                                            <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($room['description']) ?></textarea>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Fasilitas</label>
                                                            <div class="row">
                                                                <?php foreach ($availableFacilities as $facility): ?>
                                                                    <div class="col-md-4 col-sm-6">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" name="facilities[]" 
                                                                                   value="<?= $facility ?>" id="edit_facility_<?= $room['id_ruang'] ?>_<?= $facility ?>"
                                                                                   <?= in_array($facility, $facilities) ? 'checked' : '' ?>>
                                                                            <label class="form-check-label" for="edit_facility_<?= $room['id_ruang'] ?>_<?= $facility ?>">
                                                                                <?= $facility ?>
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Role yang Dapat Mengakses</label>
                                                            <div class="row">
                                                                <?php foreach ($availableRoles as $roleKey => $roleName): ?>
                                                                    <div class="col-md-6">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" name="allowed_roles[]" 
                                                                                   value="<?= $roleKey ?>" id="edit_role_<?= $room['id_ruang'] ?>_<?= $roleKey ?>"
                                                                                   <?= in_array($roleKey, $allowedRoles) ? 'checked' : '' ?>>
                                                                            <label class="form-check-label" for="edit_role_<?= $room['id_ruang'] ?>_<?= $roleKey ?>">
                                                                                <?= $roleName ?>
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="d-grid">
                                                            <button type="submit" name="save_room" class="btn btn-primary">
                                                                <i class="fas fa-save me-2"></i>Update Ruangan
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Delete Modal for each room -->
                                    <div class="modal fade" id="deleteRoomModal<?= $room['id_ruang'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title">Hapus Ruangan</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="alert alert-warning">
                                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                                        <strong>Peringatan!</strong> Apakah Anda yakin ingin menghapus ruangan ini?
                                                    </div>
                                                    <p><strong>Ruangan:</strong> <?= htmlspecialchars($room['nama_ruang']) ?> - <?= htmlspecialchars($room['nama_gedung']) ?></p>
                                                    <p><strong>Lokasi:</strong> <?= htmlspecialchars($room['lokasi']) ?></p>
                                                    <p class="text-danger"><strong>Aksi ini tidak dapat dibatalkan!</strong></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="room_id" value="<?= $room['id_ruang'] ?>">
                                                        <button type="submit" name="delete_room" class="btn btn-danger">
                                                            <i class="fas fa-trash me-1"></i>Ya, Hapus
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="text-center py-5">
                                        <i class="fas fa-door-open fa-5x text-muted mb-3"></i>
                                        <h4 class="text-muted">Belum ada ruangan</h4>
                                        <p class="text-muted">Klik tombol "Tambah Ruangan" untuk menambah ruangan baru</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Room Modal -->
    <div class="modal fade" id="addRoomModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Tambah Ruangan Baru</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="post">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="id_gedung_new" class="form-label">Gedung</label>
                                    <select class="form-select" id="id_gedung_new" name="id_gedung" required onchange="updateRoomNamePrefix()">
                                        <option value="">-- Pilih Gedung --</option>
                                        <?php foreach ($buildings as $building): ?>
                                            <option value="<?= $building['id_gedung'] ?>" data-code="<?= substr($building['nama_gedung'], -1) ?>">
                                                <?= htmlspecialchars($building['nama_gedung']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="room_number_new" class="form-label">Nomor Ruangan</label>
                                    <div class="input-group">
                                        <span class="input-group-text" id="room-prefix">?-</span>
                                        <input type="text" class="form-control" id="room_number_new" name="room_number" placeholder="Nomor" required>
                                        <input type="hidden" id="nama_ruang_new" name="nama_ruang" value="">
                                    </div>
                                    <div class="form-text">Format: {Kode Gedung}-{Nomor}, contoh: K-1, L-2</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="kapasitas_new" class="form-label">Kapasitas</label>
                                    <input type="number" class="form-control" id="kapasitas_new" name="kapasitas" min="1" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="lokasi_new" class="form-label">Lokasi</label>
                                    <input type="text" class="form-control" id="lokasi_new" name="lokasi" placeholder="Contoh: Lantai 1, Lantai 2" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description_new" class="form-label">Deskripsi</label>
                            <textarea class="form-control" id="description_new" name="description" rows="2" placeholder="Deskripsi ruangan (opsional)"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Fasilitas</label>
                            <div class="row">
                                <?php foreach ($availableFacilities as $facility): ?>
                                    <div class="col-md-4 col-sm-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="facilities[]" 
                                                   value="<?= $facility ?>" id="facility_<?= $facility ?>">
                                            <label class="form-check-label" for="facility_<?= $facility ?>">
                                                <?= $facility ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Role yang Dapat Mengakses</label>
                            <div class="row">
                                <?php foreach ($availableRoles as $roleKey => $roleName): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="allowed_roles[]" 
                                                   value="<?= $roleKey ?>" id="role_<?= $roleKey ?>" 
                                                   <?= $roleKey !== 'admin' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="role_<?= $roleKey ?>">
                                                <?= $roleName ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Centang role yang boleh memesan ruangan ini</div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" name="save_room" class="btn btn-primary" onclick="combineRoomName()">
                                <i class="fas fa-save me-2"></i>Tambah Ruangan
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
    <script>
        function updateRoomNamePrefix() {
            const selectedGedung = document.getElementById('id_gedung_new');
            const roomPrefix = document.getElementById('room-prefix');
            
            if (selectedGedung.selectedIndex > 0) {
                const gedungCode = selectedGedung.options[selectedGedung.selectedIndex].getAttribute('data-code');
                roomPrefix.textContent = gedungCode + '-';
            } else {
                roomPrefix.textContent = '?-';
            }
        }

        function combineRoomName() {
            const gedungSelect = document.getElementById('id_gedung_new');
            const roomNumber = document.getElementById('room_number_new').value;
            const namaRuangInput = document.getElementById('nama_ruang_new');
            
            if (gedungSelect.selectedIndex > 0 && roomNumber) {
                const gedungCode = gedungSelect.options[gedungSelect.selectedIndex].getAttribute('data-code');
                namaRuangInput.value = gedungCode + '-' + roomNumber;
            }
        }
    </script>
</body>
</html>