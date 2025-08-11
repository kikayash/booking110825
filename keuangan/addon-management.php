<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isLoggedIn() || !hasRole('keuangan')) {
    header('Location: ../index.php?error=unauthorized');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_addon':
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_addon_options (addon_name, description, category, price_per_unit, unit_type, created_by, status) 
                        VALUES (?, ?, ?, ?, ?, ?, 'active')
                    ");
                    $result = $stmt->execute([
                        $_POST['addon_name'],
                        $_POST['description'],
                        $_POST['category'],
                        $_POST['price_per_unit'],
                        $_POST['unit_type'],
                        $_SESSION['user_id']
                    ]);
                    
                    if ($result) {
                        $success_message = "Add-on berhasil ditambahkan.";
                    }
                    break;
                    
                case 'update_addon':
                    $stmt = $conn->prepare("
                        UPDATE tbl_addon_options 
                        SET addon_name = ?, description = ?, category = ?, price_per_unit = ?, unit_type = ?, updated_at = NOW()
                        WHERE id_addon = ?
                    ");
                    $result = $stmt->execute([
                        $_POST['addon_name'],
                        $_POST['description'],
                        $_POST['category'],
                        $_POST['price_per_unit'],
                        $_POST['unit_type'],
                        $_POST['addon_id']
                    ]);
                    
                    if ($result) {
                        $success_message = "Add-on berhasil diupdate.";
                    }
                    break;
                    
                case 'toggle_status':
                    $stmt = $conn->prepare("
                        UPDATE tbl_addon_options 
                        SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END, updated_at = NOW()
                        WHERE id_addon = ?
                    ");
                    $result = $stmt->execute([$_POST['addon_id']]);
                    
                    if ($result) {
                        $success_message = "Status add-on berhasil diubah.";
                    }
                    break;
                    
                case 'delete_addon':
                    $stmt = $conn->prepare("DELETE FROM tbl_addon_options WHERE id_addon = ?");
                    $result = $stmt->execute([$_POST['addon_id']]);
                    
                    if ($result) {
                        $success_message = "Add-on berhasil dihapus.";
                    }
                    break;
            }
        }
    } catch (PDOException $e) {
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
}

try {
    // Get all add-ons dengan data sederhana
    $stmt = $conn->prepare("
        SELECT 
            ao.*,
            u.email as created_by_email,
            COUNT(ba.id_booking) as usage_count,
            SUM(ba.quantity * ba.unit_price) as total_revenue
        FROM tbl_addon_options ao
        LEFT JOIN tbl_users u ON ao.created_by = u.id_user
        LEFT JOIN tbl_booking_addons ba ON ao.id_addon = ba.id_addon
        LEFT JOIN tbl_booking b ON ba.id_booking = b.id_booking 
            AND MONTH(b.tanggal) = MONTH(CURDATE()) 
            AND YEAR(b.tanggal) = YEAR(CURDATE())
        GROUP BY ao.id_addon
        ORDER BY ao.status DESC, ao.created_at DESC
    ");
    $stmt->execute();
    $addons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Summary statistics
    $totalAddons = count($addons);
    $activeAddons = count(array_filter($addons, function($a) { return $a['status'] === 'active'; }));
    $totalRevenue = array_sum(array_column($addons, 'total_revenue'));
    
    // Get addon for editing
    $editAddon = null;
    if (isset($_GET['edit_id'])) {
        $stmt = $conn->prepare("SELECT * FROM tbl_addon_options WHERE id_addon = ?");
        $stmt->execute([$_GET['edit_id']]);
        $editAddon = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error in addon management: " . $e->getMessage());
    $error_message = "Terjadi kesalahan dalam memuat data add-on.";
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount / 1000000, 1) . ' Juta';
}

function getStatusBadge($status) {
    return $status === 'active' ? 
        '<span class="badge bg-success">Active</span>' :
        '<span class="badge bg-secondary">Inactive</span>';
}

function getCategoryIcon($category) {
    switch ($category) {
        case 'equipment': return 'fas fa-laptop';
        case 'catering': return 'fas fa-utensils';
        case 'decoration': return 'fas fa-palette';
        case 'technical': return 'fas fa-tools';
        case 'service': return 'fas fa-concierge-bell';
        default: return 'fas fa-plus-circle';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Add-On - Dashboard Keuangan</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .addon-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: white;
        }
        .addon-card.inactive {
            opacity: 0.7;
            background: #f8f9fa;
        }
        .price-display {
            font-size: 1.2em;
            font-weight: bold;
            color: #28a745;
        }
        .usage-stats {
            color: #6c757d;
            font-size: 0.9em;
        }
        .action-buttons {
            margin-top: 15px;
        }
        .action-buttons .btn {
            margin-right: 5px;
        }
        .summary-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .summary-number {
            font-size: 2em;
            font-weight: bold;
            color: #dc143c;
        }
    </style>
</head>
<body class="keuangan-theme">
    
    <?php 
    $backPath = '../';
    include '../header.php'; 
    ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
                <div class="card shadow sidebar-keuangan">
                    <div class="card-body p-3">
                        <h5 class="text-center mb-4 keuangan-title">
                            <i class="fas fa-chart-line me-2"></i>Dashboard Keuangan
                        </h5>
                        <div class="list-group list-group-flush keuangan-nav">
                            <a href="dashboard.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                            <a href="monthly-report.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-calendar-alt me-2"></i>Laporan Bulanan
                            </a>
                            <a href="room-analysis.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-building me-2"></i>Analisis Ruangan
                            </a>
                            <a href="cost-benefit.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-balance-scale me-2"></i>Cost-Benefit
                            </a>
                            <a href="addon-management.php" class="list-group-item list-group-item-action active">
                                <i class="fas fa-plus-circle me-2"></i>Kelola Add-On
                            </a>
                            <a href="expense-management.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-receipt me-2"></i>Kelola Pengeluaran
                            </a>
                            <a href="financial-reports.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-file-invoice-dollar me-2"></i>Laporan Keuangan
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9">
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= $error_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Header -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h2>
                            <i class="fas fa-plus-circle me-3"></i>Kelola Add-On Services
                        </h2>
                        <p class="text-muted">Kelola layanan tambahan untuk booking ruangan</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAddonModal">
                            <i class="fas fa-plus me-2"></i>Tambah Add-On
                        </button>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="summary-number"><?= $totalAddons ?></div>
                            <div>Total Add-On</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="summary-number"><?= $activeAddons ?></div>
                            <div>Active Add-On</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="summary-number"><?= formatRupiah($totalRevenue) ?></div>
                            <div>Revenue Bulan Ini</div>
                        </div>
                    </div>
                </div>

                <!-- Add-On List -->
                <div class="row">
                    <?php foreach ($addons as $addon): ?>
                    <div class="col-md-6 mb-3">
                        <div class="addon-card <?= $addon['status'] === 'active' ? '' : 'inactive' ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5>
                                        <i class="<?= getCategoryIcon($addon['category']) ?> me-2"></i>
                                        <?= htmlspecialchars($addon['addon_name']) ?>
                                    </h5>
                                    <div class="price-display">
                                        <?= formatRupiah($addon['price_per_unit']) ?>/<?= $addon['unit_type'] ?>
                                    </div>
                                </div>
                                <div>
                                    <?= getStatusBadge($addon['status']) ?>
                                </div>
                            </div>
                            
                            <p class="mt-2 text-muted"><?= htmlspecialchars($addon['description']) ?></p>
                            
                            <div class="usage-stats">
                                <i class="fas fa-chart-bar me-1"></i>
                                Digunakan: <?= $addon['usage_count'] ?> kali | 
                                Revenue: <?= formatRupiah($addon['total_revenue']) ?>
                            </div>
                            
                            <div class="action-buttons">
                                <a href="?edit_id=<?= $addon['id_addon'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="addon_id" value="<?= $addon['id_addon'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" 
                                            onclick="return confirm('Ubah status add-on ini?')">
                                        <i class="fas fa-toggle-<?= $addon['status'] === 'active' ? 'on' : 'off' ?>"></i>
                                        <?= $addon['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="delete_addon">
                                    <input type="hidden" name="addon_id" value="<?= $addon['id_addon'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" 
                                            onclick="return confirm('Hapus add-on ini? Tindakan ini tidak dapat dibatalkan.')">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($addons)): ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">Belum ada add-on yang tersedia</h5>
                            <p class="text-muted">Tambahkan add-on pertama Anda dengan mengklik tombol "Tambah Add-On"</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Add-On Modal -->
    <div class="modal fade" id="addAddonModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>
                        <?= $editAddon ? 'Edit Add-On' : 'Tambah Add-On Baru' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="<?= $editAddon ? 'update_addon' : 'add_addon' ?>">
                        <?php if ($editAddon): ?>
                            <input type="hidden" name="addon_id" value="<?= $editAddon['id_addon'] ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Nama Add-On *</label>
                            <input type="text" name="addon_name" class="form-control" 
                                   placeholder="Nama layanan add-on" 
                                   value="<?= $editAddon ? htmlspecialchars($editAddon['addon_name']) : '' ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="description" class="form-control" rows="3" 
                                      placeholder="Deskripsi layanan add-on"><?= $editAddon ? htmlspecialchars($editAddon['description']) : '' ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Kategori *</label>
                                    <select name="category" class="form-select" required>
                                        <option value="">Pilih Kategori</option>
                                        <option value="equipment" <?= $editAddon && $editAddon['category'] === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                                        <option value="catering" <?= $editAddon && $editAddon['category'] === 'catering' ? 'selected' : '' ?>>Catering</option>
                                        <option value="decoration" <?= $editAddon && $editAddon['category'] === 'decoration' ? 'selected' : '' ?>>Decoration</option>
                                        <option value="technical" <?= $editAddon && $editAddon['category'] === 'technical' ? 'selected' : '' ?>>Technical</option>
                                        <option value="service" <?= $editAddon && $editAddon['category'] === 'service' ? 'selected' : '' ?>>Service</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tipe Unit *</label>
                                    <select name="unit_type" class="form-select" required>
                                        <option value="unit" <?= $editAddon && $editAddon['unit_type'] === 'unit' ? 'selected' : '' ?>>Unit</option>
                                        <option value="jam" <?= $editAddon && $editAddon['unit_type'] === 'jam' ? 'selected' : '' ?>>Jam</option>
                                        <option value="paket" <?= $editAddon && $editAddon['unit_type'] === 'paket' ? 'selected' : '' ?>>Paket</option>
                                        <option value="orang" <?= $editAddon && $editAddon['unit_type'] === 'orang' ? 'selected' : '' ?>>Orang</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Harga per Unit (dalam ribuan Rupiah) *</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" name="price_per_unit" class="form-control" 
                                       placeholder="0" min="0" step="0.1" 
                                       value="<?= $editAddon ? $editAddon['price_per_unit'] / 100000 : '' ?>" required>
                                <span class="input-group-text">Ribu</span>
                            </div>
                            <small class="text-muted">Contoh: 800 untuk 800 ribu</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <?= $editAddon ? 'Update Add-On' : 'Simpan Add-On' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-show modal if editing
        <?php if ($editAddon): ?>
        document.addEventListener('DOMContentLoaded', function() {
            var modal = new bootstrap.Modal(document.getElementById('addAddonModal'));
            modal.show();
        });
        <?php endif; ?>
        
        // Convert price input to database format (multiply by 1,000,000)
        document.querySelector('form').addEventListener('submit', function(e) {
            const priceInput = document.querySelector('input[name="price_per_unit"]');
            if (priceInput && priceInput.value) {
                priceInput.value = parseFloat(priceInput.value) * 1000000;
            }
        });
    </script>
</body>
</html>