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
                case 'add_expense':
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_expenses (category, description, amount, expense_date, vendor, receipt_number, notes, created_by, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $result = $stmt->execute([
                        $_POST['category'],
                        $_POST['description'],
                        $_POST['amount'],
                        $_POST['expense_date'],
                        $_POST['vendor'],
                        $_POST['receipt_number'],
                        $_POST['notes'],
                        $_SESSION['user_id']
                    ]);
                    
                    if ($result) {
                        $success_message = "Pengeluaran berhasil ditambahkan dan menunggu persetujuan.";
                    }
                    break;
                    
                case 'approve_expense':
                    $stmt = $conn->prepare("
                        UPDATE tbl_expenses 
                        SET status = 'approved', approved_by = ?, updated_at = NOW() 
                        WHERE id_expense = ?
                    ");
                    $result = $stmt->execute([$_SESSION['user_id'], $_POST['expense_id']]);
                    
                    if ($result) {
                        $success_message = "Pengeluaran berhasil disetujui.";
                    }
                    break;
                    
                case 'reject_expense':
                    $stmt = $conn->prepare("
                        UPDATE tbl_expenses 
                        SET status = 'rejected', approved_by = ?, updated_at = NOW() 
                        WHERE id_expense = ?
                    ");
                    $result = $stmt->execute([$_SESSION['user_id'], $_POST['expense_id']]);
                    
                    if ($result) {
                        $success_message = "Pengeluaran berhasil ditolak.";
                    }
                    break;
            }
        }
    } catch (PDOException $e) {
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
}

// Get expenses data
try {
    // Recent expenses
    $stmt = $conn->prepare("
        SELECT e.*, u.email as created_by_email, u2.email as approved_by_email
        FROM tbl_expenses e
        LEFT JOIN tbl_users u ON e.created_by = u.id_user
        LEFT JOIN tbl_users u2 ON e.approved_by = u2.id_user
        ORDER BY e.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly expenses summary
    $stmt = $conn->prepare("
        SELECT 
            category,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            AVG(amount) as avg_amount
        FROM tbl_expenses
        WHERE MONTH(expense_date) = MONTH(CURDATE())
        AND YEAR(expense_date) = YEAR(CURDATE())
        AND status = 'approved'
        GROUP BY category
        ORDER BY total_amount DESC
    ");
    $stmt->execute();
    $monthlySummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pending approvals
    $stmt = $conn->prepare("
        SELECT COUNT(*) as pending_count
        FROM tbl_expenses
        WHERE status = 'pending'
    ");
    $stmt->execute();
    $pendingCount = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error in expense management: " . $e->getMessage());
    $error_message = "Terjadi kesalahan dalam memuat data pengeluaran.";
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function getStatusBadge($status) {
    switch ($status) {
        case 'approved':
            return '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Approved</span>';
        case 'rejected':
            return '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Rejected</span>';
        case 'pending':
        default:
            return '<span class="badge bg-warning"><i class="fas fa-clock me-1"></i>Pending</span>';
    }
}

function getCategoryIcon($category) {
    switch ($category) {
        case 'maintenance': return 'fas fa-tools';
        case 'utilities': return 'fas fa-bolt';
        case 'marketing': return 'fas fa-bullhorn';
        case 'supplies': return 'fas fa-box';
        case 'equipment': return 'fas fa-laptop';
        default: return 'fas fa-receipt';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengeluaran - Dashboard Keuangan</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                            <a href="addon-management.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-plus-circle me-2"></i>Kelola Add-On
                            </a>
                            <a href="expense-management.php" class="list-group-item list-group-item-action active">
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
                        <h2 class="keuangan-page-title">
                            <i class="fas fa-receipt me-3"></i>Kelola Pengeluaran
                        </h2>
                        <p class="text-muted">Kelola dan pantau semua pengeluaran operasional</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-keuangan" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                            <i class="fas fa-plus me-2"></i>Tambah Pengeluaran
                        </button>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card pending-card">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <h4 class="keuangan-summary-value"><?= $pendingCount ?></h4>
                                <p class="keuangan-summary-label">Pending Approval</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card total-expense-card">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                                <h4 class="keuangan-summary-value">
                                    <?= formatRupiah(array_sum(array_column($monthlySummary, 'total_amount'))) ?>
                                </h4>
                                <p class="keuangan-summary-label">Total Bulan Ini</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card category-card">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-tags"></i>
                                </div>
                                <h4 class="keuangan-summary-value"><?= count($monthlySummary) ?></h4>
                                <p class="keuangan-summary-label">Kategori Aktif</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card avg-expense-card">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-calculator"></i>
                                </div>
                                <h4 class="keuangan-summary-value">
                                    <?php 
                                    $avgExpense = !empty($monthlySummary) ? 
                                        array_sum(array_column($monthlySummary, 'total_amount')) / array_sum(array_column($monthlySummary, 'count')) : 0;
                                    echo formatRupiah($avgExpense);
                                    ?>
                                </h4>
                                <p class="keuangan-summary-label">Rata-rata per Item</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Summary Chart -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Pengeluaran per Kategori (Bulan Ini)
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="expenseCategoryChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card keuangan-summary-detail-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-list me-2"></i>Ringkasan Kategori
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($monthlySummary as $category): ?>
                                <div class="expense-category-item">
                                    <div class="category-info">
                                        <i class="<?= getCategoryIcon($category['category']) ?> me-2"></i>
                                        <span class="category-name"><?= ucfirst($category['category']) ?></span>
                                    </div>
                                    <div class="category-amount">
                                        <span class="amount"><?= formatRupiah($category['total_amount']) ?></span>
                                        <small class="count"><?= $category['count'] ?> item</small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expenses Table -->
                <div class="card keuangan-table-card">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-table me-2"></i>Daftar Pengeluaran
                        </h5>
                        <div class="header-actions">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-light" onclick="filterExpenses('all')">All</button>
                                <button class="btn btn-outline-light" onclick="filterExpenses('pending')">Pending</button>
                                <button class="btn btn-outline-light" onclick="filterExpenses('approved')">Approved</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table keuangan-table" id="expensesTable">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Kategori</th>
                                        <th>Deskripsi</th>
                                        <th>Vendor</th>
                                        <th>Jumlah</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expenses as $expense): ?>
                                    <tr data-status="<?= $expense['status'] ?>">
                                        <td>
                                            <span class="expense-date"><?= date('d/m/Y', strtotime($expense['expense_date'])) ?></span>
                                            <small class="text-muted d-block"><?= date('H:i', strtotime($expense['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="category-badge">
                                                <i class="<?= getCategoryIcon($expense['category']) ?> me-1"></i>
                                                <?= ucfirst($expense['category']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($expense['description']) ?></strong>
                                            <?php if (!empty($expense['notes'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($expense['notes']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($expense['vendor']) ?></td>
                                        <td class="expense-amount">
                                            <strong><?= formatRupiah($expense['amount']) ?></strong>
                                        </td>
                                        <td><?= getStatusBadge($expense['status']) ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($expense['status'] === 'pending'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="approve_expense">
                                                    <input type="hidden" name="expense_id" value="<?= $expense['id_expense'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success" 
                                                            onclick="return confirm('Setujui pengeluaran ini?')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="reject_expense">
                                                    <input type="hidden" name="expense_id" value="<?= $expense['id_expense'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" 
                                                            onclick="return confirm('Tolak pengeluaran ini?')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-info" onclick="viewExpenseDetail(<?= $expense['id_expense'] ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
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
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal fade" id="addExpenseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>Tambah Pengeluaran Baru
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_expense">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Kategori *</label>
                                    <select name="category" class="form-select" required>
                                        <option value="">Pilih Kategori</option>
                                        <option value="maintenance">Maintenance</option>
                                        <option value="utilities">Utilities</option>
                                        <option value="marketing">Marketing</option>
                                        <option value="supplies">Supplies</option>
                                        <option value="equipment">Equipment</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tanggal Pengeluaran *</label>
                                    <input type="date" name="expense_date" class="form-control" 
                                           value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi *</label>
                            <input type="text" name="description" class="form-control" 
                                   placeholder="Deskripsi pengeluaran" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Jumlah (Rp) *</label>
                                    <input type="number" name="amount" class="form-control" 
                                           placeholder="0" min="0" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Vendor/Supplier</label>
                                    <input type="text" name="vendor" class="form-control" 
                                           placeholder="Nama vendor/supplier">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nomor Kwitansi/Invoice</label>
                            <input type="text" name="receipt_number" class="form-control" 
                                   placeholder="Nomor kwitansi atau invoice">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Catatan Tambahan</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Catatan tambahan (opsional)"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-keuangan">
                            <i class="fas fa-save me-2"></i>Simpan Pengeluaran
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Data untuk chart
        const categoryData = <?= json_encode($monthlySummary) ?>;

        // Expense Category Chart
        const categoryCtx = document.getElementById('expenseCategoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: categoryData.map(item => item.category.charAt(0).toUpperCase() + item.category.slice(1)),
                datasets: [{
                    data: categoryData.map(item => item.total_amount),
                    backgroundColor: [
                        '#dc143c',
                        '#f0a0a0',
                        '#f5b2b2',
                        '#ffb3b3',
                        '#ffc4c4',
                        '#ffd4d4'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Filter expenses
        function filterExpenses(status) {
            const rows = document.querySelectorAll('#expensesTable tbody tr');
            
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update active button
            document.querySelectorAll('.btn-group button').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
        }

        function viewExpenseDetail(expenseId) {
            // Implementasi view detail
            alert('View detail untuk expense ID: ' + expenseId);
        }
    </script>
</body>
</html>