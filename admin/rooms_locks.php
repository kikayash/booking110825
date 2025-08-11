<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// Handle form submissions
$message = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'lock_room') {
        // Handle room lock
        $roomId = $_POST['room_id'] ?? 0;
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $reason = $_POST['reason'] ?? '';
        
        if ($roomId && $startDate && $endDate && $reason) {
            // Validate dates
            if (strtotime($endDate) <= strtotime($startDate)) {
                $message = 'Tanggal selesai harus lebih dari tanggal mulai.';
                $alertType = 'danger';
            } else {
                try {
                    $stmt = $conn->prepare("INSERT INTO tbl_room_locks 
                                           (id_ruang, start_date, end_date, reason, locked_by, created_at) 
                                           VALUES (?, ?, ?, ?, ?, NOW())");
                    $result = $stmt->execute([$roomId, $startDate, $endDate, $reason, $_SESSION['user_id']]);
                    
                    if ($result) {
                        $message = 'Ruangan berhasil dikunci.';
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal mengunci ruangan.';
                        $alertType = 'danger';
                    }
                } catch (PDOException $e) {
                    $message = 'Database error: ' . $e->getMessage();
                    $alertType = 'danger';
                }
            }
        } else {
            $message = 'Semua field harus diisi.';
            $alertType = 'danger';
        }
    } elseif ($action === 'unlock_room') {
        // Handle room unlock
        $lockId = $_POST['lock_id'] ?? 0;
        $unlockReason = $_POST['unlock_reason'] ?? '';
        
        if ($lockId && $unlockReason) {
            try {
                // Get lock details first
                $stmt = $conn->prepare("SELECT rl.*, r.nama_ruang, g.nama_gedung 
                                       FROM tbl_room_locks rl 
                                       JOIN tbl_ruang r ON rl.id_ruang = r.id_ruang 
                                       LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                                       WHERE rl.id = ? AND (rl.status = 'active' OR rl.status IS NULL)");
                $stmt->execute([$lockId]);
                $lock = $stmt->fetch();
                
                if ($lock) {
                    // Check if unlock columns exist, if not update only status
                    try {
                        $stmt = $conn->prepare("UPDATE tbl_room_locks 
                                               SET status = 'unlocked', 
                                                   unlocked_by = ?, 
                                                   unlocked_at = NOW(), 
                                                   unlock_reason = ? 
                                               WHERE id = ?");
                        $result = $stmt->execute([$_SESSION['user_id'], $unlockReason, $lockId]);
                    } catch (PDOException $e) {
                        // If unlock columns don't exist, just update status
                        $stmt = $conn->prepare("UPDATE tbl_room_locks 
                                               SET status = 'unlocked'
                                               WHERE id = ?");
                        $result = $stmt->execute([$lockId]);
                    }
                    
                    if ($result) {
                        // Log the unlock action
                        error_log("Room unlocked: Lock ID $lockId, Room {$lock['nama_ruang']}, by admin {$_SESSION['user_id']}");
                        
                        $message = "Ruangan {$lock['nama_ruang']} berhasil dibuka kembali. Ruangan sekarang tersedia untuk booking.";
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal membuka ruangan.';
                        $alertType = 'danger';
                    }
                } else {
                    $message = 'Lock ruangan tidak ditemukan atau sudah tidak aktif.';
                    $alertType = 'warning';
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        } else {
            $message = 'Alasan unlock harus diisi.';
            $alertType = 'danger';
        }
    }
}

// Get all rooms
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung 
                       FROM tbl_ruang r 
                       LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                       ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

// Get all room locks - check if columns exist first
try {
    $stmt = $conn->prepare("SELECT rl.*, r.nama_ruang, g.nama_gedung, u.email as locked_by_email,
                                   u2.email as unlocked_by_email
                           FROM tbl_room_locks rl 
                           JOIN tbl_ruang r ON rl.id_ruang = r.id_ruang 
                           LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                           LEFT JOIN tbl_users u ON rl.locked_by = u.id_user
                           LEFT JOIN tbl_users u2 ON rl.unlocked_by = u2.id_user
                           ORDER BY rl.created_at DESC");
} catch (PDOException $e) {
    // If unlock columns don't exist, use simpler query
    $stmt = $conn->prepare("SELECT rl.*, r.nama_ruang, g.nama_gedung, u.email as locked_by_email
                           FROM tbl_room_locks rl 
                           JOIN tbl_ruang r ON rl.id_ruang = r.id_ruang 
                           LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung 
                           LEFT JOIN tbl_users u ON rl.locked_by = u.id_user
                           ORDER BY rl.created_at DESC");
}
$stmt->execute();
$roomLocks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Lock Ruangan - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="admin-theme">
    <header>
        <?php 
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
                        <a href="admin_add_booking.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="rooms_locks.php" class="list-group-item list-group-item-action active">
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
                    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-lock me-2"></i>Kelola Lock Ruangan
                        </h4>
                        <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#lockRoomModal">
                            <i class="fas fa-plus me-1"></i> Lock Ruangan
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <?= $message ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Info Lock -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Informasi Lock Ruangan:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Lock ruangan akan mencegah peminjaman pada periode yang ditentukan</li>
                                <li>Gunakan fitur ini untuk event khusus seperti ospek, ujian, maintenance, dll</li>
                                <li>Lock aktif akan ditampilkan di kalender dengan icon gembok</li>
                                <li>Booking yang sudah ada sebelum lock tidak akan terpengaruh</li>
                                <li>Admin dapat membuka lock kapan saja sebelum periode berakhir</li>
                            </ul>
                        </div>

                        <!-- Room Locks Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>No</th>
                                        <th>Ruangan</th>
                                        <th>Gedung</th>
                                        <th>Periode Lock</th>
                                        <th>Alasan</th>
                                        <th>Dikunci Oleh</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($roomLocks) > 0): ?>
                                        <?php $i = 1; foreach ($roomLocks as $lock): ?>
                                            <?php
                                            // Check current status more accurately
                                            $today = date('Y-m-d');
                                            $currentStatus = $lock['status'] ?? 'active'; // Default to active if no status
                                            $isActive = ($currentStatus === 'active' || $currentStatus === '' || is_null($currentStatus));
                                            $isNotExpired = ($lock['end_date'] >= $today);
                                            $canUnlock = ($isActive && $isNotExpired);
                                            ?>
                                            <tr>
                                                <td><?= $i++ ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?= htmlspecialchars($lock['nama_ruang']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($lock['nama_gedung'] ?? 'Unknown') ?></td>
                                                <td>
                                                    <?= formatDate($lock['start_date']) ?> - <?= formatDate($lock['end_date']) ?><br>
                                                    <small class="text-muted">
                                                        <?php
                                                        $start = new DateTime($lock['start_date']);
                                                        $end = new DateTime($lock['end_date']);
                                                        $diff = $end->diff($start);
                                                        echo "({$diff->days} hari)";
                                                        ?>
                                                    </small>
                                                </td>
                                                <td><?= htmlspecialchars($lock['reason']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($lock['locked_by_email'] ?? 'Unknown') ?><br>
                                                    <small class="text-muted"><?= date('d/m/Y H:i', strtotime($lock['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    if ($lock['status'] === 'unlocked') {
                                                        echo '<span class="badge bg-secondary">Dibuka</span>';
                                                        if ($lock['unlocked_at']) {
                                                            echo '<br><small class="text-muted">Dibuka: ' . date('d/m/Y H:i', strtotime($lock['unlocked_at'])) . '</small>';
                                                        }
                                                    } elseif ($lock['end_date'] < $today) {
                                                        echo '<span class="badge bg-info">Expired</span>';
                                                    } elseif ($lock['start_date'] <= $today && $lock['end_date'] >= $today) {
                                                        echo '<span class="badge bg-danger">Aktif</span>';
                                                    } else {
                                                        echo '<span class="badge bg-warning">Terjadwal</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-info mb-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#detailModal<?= $lock['id'] ?>">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </button>
                                                    
                                                    <?php if ($canUnlock): ?>
                                                        <button type="button" class="btn btn-sm btn-danger mb-1" 
                                                                onclick="showUnlockModal(<?= $lock['id'] ?>, '<?= htmlspecialchars($lock['nama_ruang'], ENT_QUOTES) ?>', '<?= formatDate($lock['start_date']) ?> - <?= formatDate($lock['end_date']) ?>', '<?= htmlspecialchars($lock['reason'], ENT_QUOTES) ?>')">
                                                            <i class="fas fa-unlock"></i> Unlock
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    // Debug info - remove this in production
                                                    if (false) { // Set to true for debugging
                                                        echo "<br><small style='font-size:10px;'>Debug: Status={$lock['status']}, Today=$today, End={$lock['end_date']}, CanUnlock=" . ($canUnlock ? 'Yes' : 'No') . "</small>";
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        </tbody>
                            </table>

                                            <!-- Detail Modal -->
                                            <div class="modal fade" id="detailModal<?= $lock['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-info text-white">
                                                            <h5 class="modal-title">Detail Lock Ruangan #<?= $lock['id'] ?></h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <h6>Informasi Ruangan</h6>
                                                                    <table class="table table-borderless table-sm">
                                                                        <tr><th>Ruangan:</th><td><?= htmlspecialchars($lock['nama_ruang']) ?></td></tr>
                                                                        <tr><th>Gedung:</th><td><?= htmlspecialchars($lock['nama_gedung'] ?? 'Unknown') ?></td></tr>
                                                                        <tr><th>Periode:</th><td><?= formatDate($lock['start_date']) ?> - <?= formatDate($lock['end_date']) ?></td></tr>
                                                                        <tr><th>Durasi:</th><td>
                                                                            <?php
                                                                            $start = new DateTime($lock['start_date']);
                                                                            $end = new DateTime($lock['end_date']);
                                                                            $diff = $end->diff($start);
                                                                            echo $diff->days . ' hari';
                                                                            ?>
                                                                        </td></tr>
                                                                    </table>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <h6>Informasi Lock</h6>
                                                                    <table class="table table-borderless table-sm">
                                                                        <tr><th>Alasan:</th><td><?= htmlspecialchars($lock['reason']) ?></td></tr>
                                                                        <tr><th>Dikunci oleh:</th><td><?= htmlspecialchars($lock['locked_by_email'] ?? 'Unknown') ?></td></tr>
                                                                        <tr><th>Waktu Lock:</th><td><?= date('d/m/Y H:i', strtotime($lock['created_at'])) ?></td></tr>
                                                                        <tr><th>Status:</th><td>
                                                                            <?php
                                                                            if ($lock['status'] === 'unlocked') {
                                                                                echo '<span class="badge bg-secondary">Dibuka</span>';
                                                                            } elseif ($lock['end_date'] < $today) {
                                                                                echo '<span class="badge bg-info">Expired</span>';
                                                                            } elseif ($lock['start_date'] <= $today && $lock['end_date'] >= $today) {
                                                                                echo '<span class="badge bg-danger">Aktif</span>';
                                                                            } else {
                                                                                echo '<span class="badge bg-warning">Terjadwal</span>';
                                                                            }
                                                                            ?>
                                                                        </td></tr>
                                                                    </table>
                                                                    
                                                                    <?php if ($lock['status'] === 'unlocked'): ?>
                                                                        <h6 class="mt-3">Informasi Unlock</h6>
                                                                        <table class="table table-borderless table-sm">
                                                                            <tr><th>Dibuka oleh:</th><td><?= htmlspecialchars($lock['unlocked_by_email'] ?? 'Unknown') ?></td></tr>
                                                                            <tr><th>Waktu Unlock:</th><td><?= $lock['unlocked_at'] ? date('d/m/Y H:i', strtotime($lock['unlocked_at'])) : '-' ?></td></tr>
                                                                            <tr><th>Alasan Unlock:</th><td><?= htmlspecialchars($lock['unlock_reason'] ?? '-') ?></td></tr>
                                                                        </table>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                            <?php if ($canUnlock): ?>
                                                                <button type="button" class="btn btn-danger" 
                                                                        onclick="showUnlockModal(<?= $lock['id'] ?>, '<?= htmlspecialchars($lock['nama_ruang'], ENT_QUOTES) ?>', '<?= formatDate($lock['start_date']) ?> - <?= formatDate($lock['end_date']) ?>', '<?= htmlspecialchars($lock['reason'], ENT_QUOTES) ?>')"
                                                                        data-bs-dismiss="modal">
                                                                    <i class="fas fa-unlock"></i> Unlock Ruangan
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-3">Belum ada ruangan yang dikunci.</td>
                                        </tr>
                                    <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lock Room Modal -->
    <div class="modal fade" id="lockRoomModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="fas fa-lock me-2"></i>Lock Ruangan
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="lock_room">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="room_id" class="form-label">Pilih Ruangan</label>
                            <select class="form-select" id="room_id" name="room_id" required>
                                <option value="">-- Pilih Ruangan --</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?= $room['id_ruang'] ?>">
                                        <?= htmlspecialchars($room['nama_ruang']) ?> - <?= htmlspecialchars($room['nama_gedung'] ?? 'Unknown') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Tanggal Mulai</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" 
                                           min="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">Tanggal Selesai</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" 
                                           min="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reason" class="form-label">Alasan Lock</label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" 
                                      placeholder="Contoh: Ujian Tengah Semester, Maintenance ruangan, Event khusus, dll" required></textarea>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Perhatian:</strong> Lock ruangan akan mencegah semua peminjaman pada periode yang ditentukan. 
                            Pastikan tanggal dan alasan sudah benar.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-lock"></i> Lock Ruangan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Unlock Room Modal -->
    <div class="modal fade" id="unlockRoomModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-unlock me-2"></i>Unlock Ruangan
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="unlockForm">
                    <input type="hidden" name="action" value="unlock_room">
                    <input type="hidden" name="lock_id" id="unlock_lock_id">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Peringatan!</strong> Apakah Anda yakin ingin unlock ruangan ini?
                        </div>
                        
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Informasi Lock:</h6>
                                <p><strong>Ruangan:</strong> <span id="unlock_room_name"></span></p>
                                <p><strong>Periode:</strong> <span id="unlock_period"></span></p>
                                <p><strong>Alasan:</strong> <span id="unlock_reason"></span></p>
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3">
                            <label for="unlock_reason_input" class="form-label">Alasan Unlock</label>
                            <textarea class="form-control" id="unlock_reason_input" name="unlock_reason" rows="3" 
                                      placeholder="Berikan alasan mengapa ruangan dibuka sebelum periode berakhir..." required></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Setelah di-unlock, ruangan akan kembali tersedia untuk booking pada tanggal yang sudah di-unlock.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-unlock"></i> Ya, Unlock Ruangan
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
    <script>
        // Function to show unlock modal with room details
        function showUnlockModal(lockId, roomName, period, reason) {
            document.getElementById('unlock_lock_id').value = lockId;
            document.getElementById('unlock_room_name').textContent = roomName;
            document.getElementById('unlock_period').textContent = period;
            document.getElementById('unlock_reason').textContent = reason;
            document.getElementById('unlock_reason_input').value = '';
            
            const unlockModal = new bootstrap.Modal(document.getElementById('unlockRoomModal'));
            unlockModal.show();
        }
        
        // Validate dates in lock form
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            
            startDateInput.addEventListener('change', function() {
                endDateInput.min = this.value;
                if (endDateInput.value && endDateInput.value < this.value) {
                    endDateInput.value = this.value;
                }
            });
            
            endDateInput.addEventListener('change', function() {
                if (this.value < startDateInput.value) {
                    alert('Tanggal selesai harus lebih dari atau sama dengan tanggal mulai');
                    this.value = startDateInput.value;
                }
            });
        });
    </script>
</body>
</html>