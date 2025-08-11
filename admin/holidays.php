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
    // Add or edit holiday
    if (isset($_POST['save_holiday'])) {
        $oldDate = $_POST['old_date'] ?? '';
        $date = $_POST['tanggal'] ?? '';
        $description = trim($_POST['keterangan'] ?? '');
        
        // Validate inputs
        if (empty($date) || empty($description)) {
            $message = 'Tanggal dan keterangan harus diisi.';
            $alertType = 'danger';
        } else {
            try {
                if ($oldDate) {
                    // Update existing holiday
                    $stmt = $conn->prepare("UPDATE tbl_harilibur SET tanggal = ?, keterangan = ? WHERE tanggal = ?");
                    $result = $stmt->execute([$date, $description, $oldDate]);
                    
                    if ($result) {
                        // Handle schedule cleanup untuk tanggal baru dan regenerasi untuk tanggal lama
                        if ($date !== $oldDate) {
                            // Cleanup jadwal di tanggal baru
                            $cleanupNew = removeRecurringSchedulesOnHoliday($conn, $date);
                            // Regenerate jadwal di tanggal lama (jika bukan hari libur lagi)
                            $regenOld = regenerateRecurringSchedulesOnRemovedHoliday($conn, $oldDate);
                            
                            $message = "Hari libur berhasil diperbarui dari " . formatDate($oldDate) . " ke " . formatDate($date) . ".";
                            if ($cleanupNew['removed_count'] > 0) {
                                $message .= " {$cleanupNew['removed_count']} jadwal perkuliahan dihapus dari tanggal baru.";
                            }
                            if ($regenOld['generated_count'] > 0) {
                                $message .= " {$regenOld['generated_count']} jadwal perkuliahan dipulihkan di tanggal lama.";
                            }
                        } else {
                            // Hanya update keterangan
                            $message = 'Keterangan hari libur berhasil diperbarui.';
                        }
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal memperbarui hari libur.';
                        $alertType = 'danger';
                    }
                } else {
                    // Add new holiday with auto-cleanup
                    $result = addHolidayWithAutoCleanup($conn, $date, $description);
                    
                    if ($result['success']) {
                        $message = $result['message'];
                        $alertType = 'success';
                        
                        // Log untuk admin
                        error_log("HOLIDAY ADDED: {$description} on {$date} - {$result['cleanup_result']['removed_count']} schedules cleaned");
                    } else {
                        $message = $result['message'];
                        $alertType = 'danger';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
    
    // Delete holiday
    if (isset($_POST['delete_holiday'])) {
        $date = $_POST['date'] ?? '';
        
        if ($date) {
            try {
                // Check if there are non-recurring bookings on this date
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as manual_bookings, 
                           GROUP_CONCAT(nama_acara) as booking_names
                    FROM tbl_booking 
                    WHERE tanggal = ? 
                    AND (booking_type != 'recurring' OR booking_type IS NULL)
                    AND status NOT IN ('cancelled', 'rejected')
                ");
                $stmt->execute([$date]);
                $manualBookings = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($manualBookings['manual_bookings'] > 0) {
                    $message = "Tidak dapat menghapus hari libur karena masih ada {$manualBookings['manual_bookings']} booking manual pada tanggal tersebut: {$manualBookings['booking_names']}";
                    $alertType = 'warning';
                } else {
                    // Delete holiday with schedule regeneration
                    $conn->beginTransaction();
                    
                    // Delete holiday
                    $stmt = $conn->prepare("DELETE FROM tbl_harilibur WHERE tanggal = ?");
                    $deleteResult = $stmt->execute([$date]);
                    
                    if ($deleteResult) {
                        // Regenerate recurring schedules untuk tanggal tersebut
                        $regenResult = regenerateRecurringSchedulesOnRemovedHoliday($conn, $date);
                        
                        $conn->commit();
                        
                        $message = "Hari libur pada " . formatDate($date) . " berhasil dihapus.";
                        if ($regenResult['generated_count'] > 0) {
                            $message .= " {$regenResult['generated_count']} jadwal perkuliahan telah dipulihkan dan slot kembali terisi otomatis.";
                        } else {
                            $message .= " Slot waktu kini tersedia untuk booking manual.";
                        }
                        $alertType = 'success';
                        
                        // Log untuk admin
                        error_log("HOLIDAY DELETED: {$date} - {$regenResult['generated_count']} schedules regenerated");
                    } else {
                        $conn->rollBack();
                        $message = 'Gagal menghapus hari libur.';
                        $alertType = 'danger';
                    }
                }
            } catch (PDOException $e) {
                if (isset($conn) && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
    
    // Import holidays
    if (isset($_POST['import_holidays'])) {
        $year = $_POST['import_year'] ?? date('Y');
        
        if ($year) {
            try {
                // National holidays for Indonesia (example data)
                $nationalHolidays = [
                    // Format: MM-DD => Description
                    '01-01' => 'Tahun Baru',
                    '02-16' => 'Tahun Baru Imlek'
                ];
                
                $importCount = 0;
                $cleanupCount = 0;
                
                foreach ($nationalHolidays as $date => $description) {
                    $fullDate = $year . '-' . $date;
                    
                    // Check if date already exists
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_harilibur WHERE tanggal = ?");
                    $stmt->execute([$fullDate]);
                    $dateExists = $stmt->fetchColumn() > 0;
                    
                    if (!$dateExists) {
                        // Add holiday with auto-cleanup
                        $result = addHolidayWithAutoCleanup($conn, $fullDate, $description);
                        
                        if ($result['success']) {
                            $importCount++;
                            $cleanupCount += $result['cleanup_result']['removed_count'];
                        }
                    }
                }
                
                if ($importCount > 0) {
                    $message = "{$importCount} hari libur nasional berhasil diimpor untuk tahun {$year}.";
                    if ($cleanupCount > 0) {
                        $message .= " {$cleanupCount} jadwal perkuliahan otomatis dibersihkan dari hari libur.";
                    }
                    $alertType = 'success';
                } else {
                    $message = "Tidak ada hari libur baru yang perlu diimpor untuk tahun {$year}.";
                    $alertType = 'info';
                }
                
                // Log untuk admin
                error_log("HOLIDAYS IMPORTED: {$importCount} holidays for year {$year}, {$cleanupCount} schedules cleaned");
                
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
}
if (isset($_POST['manual_cleanup'])) {
        try {
            $cleanupResult = autoCleanupHolidaySchedules($conn);
            $duplicateResult = removeDuplicateRecurringBookings($conn);
            
            $message = "Manual cleanup selesai! ";
            $message .= "Jadwal pada hari libur: {$cleanupResult} dibersihkan. ";
            $message .= "Duplikat jadwal: {$duplicateResult} dihapus.";
            $alertType = 'success';
            
            error_log("MANUAL CLEANUP: Holiday cleanup: {$cleanupResult}, Duplicate cleanup: {$duplicateResult}");
            
        } catch (Exception $e) {
            $message = 'Error saat cleanup: ' . $e->getMessage();
            $alertType = 'danger';
        }
    }


// Get cleanup statistics for dashboard
$cleanupStats = getCleanupStats($conn);


// Get all holidays
$stmt = $conn->prepare("SELECT * FROM tbl_harilibur ORDER BY tanggal DESC");
$stmt->execute();
$holidays = $stmt->fetchAll();

// Get current year for import
$currentYear = date('Y');

// Set back path for header
$backPath = '../';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Hari Libur - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Tambahkan styles -->
    <style>
        .holiday-badge {
            font-size: 0.9rem;
            padding: 6px 10px;
            border-radius: 4px;
        }
        .current-month {
            background-color: var(--danger-color);
            color: white;
        }
        .current-month.weekend {
            background-color: #dc3545aa; /* Slightly transparent red */
        }
        .past-month {
            background-color: #6c757d;
            color: white;
        }
        .future-month {
            background-color: #fd7e14;
            color: white;
        }
        .table-holidays td {
            vertical-align: middle;
        }
        .weekend-note {
            color: #dc3545;
            font-style: italic;
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
                        <a href="buildings.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-building me-2"></i> Kelola Gedung
                        </a>
                        <a href="holidays.php" class="list-group-item list-group-item-action active">
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
                <div class="row">
                    <!-- Tambah Hari Libur -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0 card-title"><i class="fas fa-plus-circle me-2"></i> Tambah Hari Libur</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($message && strpos($message, 'berhasil') !== false): ?>
                                    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                        <?= $message ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="post" id="addHolidayForm">
                                    <input type="hidden" name="old_date" id="old_date">
                                    
                                    <div class="mb-3">
                                        <label for="tanggal" class="form-label">Tanggal Libur <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="tanggal" name="tanggal" required>
                                        <div class="form-text text-muted">
                                            <span class="weekend-note">Catatan: Hari Sabtu & Minggu otomatis dianggap hari libur.</span>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="keterangan" class="form-label">Keterangan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="keterangan" name="keterangan" 
                                               placeholder="Contoh: Hari Raya Idul Fitri" required>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" name="save_holiday" id="btnSaveHoliday" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i> Simpan Hari Libur
                                        </button>
                                    </div>
                                </form>
                                
                                <hr>
                                
                                <!-- Import Hari Libur Nasional -->
                                <h5 class="mt-4 mb-3"><i class="fas fa-file-import me-2"></i> Import Hari Libur</h5>
                                <form method="post">
                                    <div class="mb-3">
                                        <label for="import_year" class="form-label">Tahun</label>
                                        <select class="form-select" id="import_year" name="import_year">
                                            <?php for ($year = $currentYear - 1; $year <= $currentYear + 2; $year++): ?>
                                                <option value="<?= $year ?>" <?= $year == $currentYear ? 'selected' : '' ?>><?= $year ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" name="import_holidays" class="btn btn-outline-primary">
                                            <i class="fas fa-file-import me-2"></i> Import Hari Libur Nasional
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Daftar Hari Libur -->
                    <div class="col-md-8 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 card-title"><i class="fas fa-calendar-alt me-2"></i> Daftar Hari Libur</h5>
                                <div class="input-group" style="width: 200px;">
                                    <input type="text" class="form-control form-control-sm" id="searchHoliday" placeholder="Cari hari libur...">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($message && strpos($message, 'berhasil') === false): ?>
                                    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                        <?= $message ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped table-bordered table-holidays" id="holidaysTable">
                                        <thead class="bg-light">
                                            <tr>
                                                <th width="40%">Tanggal</th>
                                                <th width="45%">Keterangan</th>
                                                <th width="15%">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($holidays) > 0): ?>
                                                <?php 
                                                $currentMonth = date('m');
                                                $currentYear = date('Y');
                                                
                                                foreach ($holidays as $holiday): 
                                                    // Determine if date is weekend
                                                    $dayOfWeek = date('w', strtotime($holiday['tanggal']));
                                                    $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
                                                    
                                                    // Determine month type for styling
                                                    $holidayMonth = date('m', strtotime($holiday['tanggal']));
                                                    $holidayYear = date('Y', strtotime($holiday['tanggal']));
                                                    
                                                    if ($holidayYear == $currentYear && $holidayMonth == $currentMonth) {
                                                        $monthClass = 'current-month';
                                                    } elseif ($holiday['tanggal'] < date('Y-m-d')) {
                                                        $monthClass = 'past-month';
                                                    } else {
                                                        $monthClass = 'future-month';
                                                    }
                                                    
                                                    // Add weekend class if applicable
                                                    if ($isWeekend && $monthClass == 'current-month') {
                                                        $monthClass .= ' weekend';
                                                    }
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <span class="holiday-badge <?= $monthClass ?> me-2">
                                                                    <?= date('d/m/Y', strtotime($holiday['tanggal'])) ?>
                                                                </span>
                                                                <span class="text-muted">
                                                                    <?= date('l', strtotime($holiday['tanggal'])) ?>
                                                                    <?php if ($isWeekend): ?>
                                                                        <span class="weekend-note">(Weekend)</span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td><?= htmlspecialchars($holiday['keterangan']) ?></td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-primary edit-holiday" 
                                                                    data-date="<?= $holiday['tanggal'] ?>" 
                                                                    data-description="<?= htmlspecialchars($holiday['keterangan']) ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            
                                                            <button type="button" class="btn btn-sm btn-danger" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#deleteHolidayModal" 
                                                                    data-date="<?= $holiday['tanggal'] ?>" 
                                                                    data-description="<?= htmlspecialchars($holiday['keterangan']) ?>">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="3" class="text-center py-3">Tidak ada data hari libur yang ditemukan.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (count($holidays) > 10): ?>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <div>
                                            <span class="text-muted">Menampilkan <?= count($holidays) ?> hari libur</span>
                                        </div>
                                        <div class="text-muted">
                                            <span class="me-3"><i class="fas fa-circle text-danger"></i> Bulan Ini</span>
                                            <span class="me-3"><i class="fas fa-circle text-secondary"></i> Bulan Lalu</span>
                                            <span><i class="fas fa-circle text-warning"></i> Bulan Mendatang</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Holiday Modal -->
    <div class="modal fade" id="deleteHolidayModal" tabindex="-1" aria-labelledby="deleteHolidayModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteHolidayModalLabel">Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus hari libur ini?</p>
                    <div class="alert alert-secondary">
                        <div id="deleteHolidayDate" class="fw-bold"></div>
                        <div id="deleteHolidayDesc"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="post">
                        <input type="hidden" name="date" id="delete_date">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete_holiday" class="btn btn-danger">Hapus</button>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Edit Holiday
            document.querySelectorAll('.edit-holiday').forEach(button => {
                button.addEventListener('click', function() {
                    const date = this.getAttribute('data-date');
                    const description = this.getAttribute('data-description');
                    
                    document.getElementById('old_date').value = date;
                    document.getElementById('tanggal').value = date;
                    document.getElementById('keterangan').value = description;
                    document.getElementById('btnSaveHoliday').innerHTML = '<i class="fas fa-save me-2"></i> Update Hari Libur';
                    
                    // Scroll to form
                    document.getElementById('addHolidayForm').scrollIntoView({
                        behavior: 'smooth'
                    });
                });
            });
            
            // Delete Holiday Modal
            const deleteHolidayModal = document.getElementById('deleteHolidayModal');
            if (deleteHolidayModal) {
                deleteHolidayModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const date = button.getAttribute('data-date');
                    const description = button.getAttribute('data-description');
                    
                    // Format date for display
                    const formatDate = new Date(date);
                    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                    const formattedDate = formatDate.toLocaleDateString('id-ID', options);
                    
                    document.getElementById('deleteHolidayDate').textContent = formattedDate;
                    document.getElementById('deleteHolidayDesc').textContent = description;
                    document.getElementById('delete_date').value = date;
                });
            }
            
            // Reset form after submit
            document.getElementById('addHolidayForm').addEventListener('submit', function() {
                setTimeout(() => {
                    document.getElementById('old_date').value = '';
                    document.getElementById('btnSaveHoliday').innerHTML = '<i class="fas fa-save me-2"></i> Simpan Hari Libur';
                }, 100);
            });
            
            // Pencarian hari libur
            document.getElementById('searchHoliday').addEventListener('keyup', function() {
                const searchText = this.value.toLowerCase();
                const table = document.getElementById('holidaysTable');
                const rows = table.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    let found = false;
                    
                    cells.forEach(cell => {
                        if (cell.textContent.toLowerCase().indexOf(searchText) > -1) {
                            found = true;
                        }
                    });
                    
                    row.style.display = found ? '' : 'none';
                });
            });
        });
    </script>
</body>
</html>