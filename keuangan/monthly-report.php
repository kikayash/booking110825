<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isLoggedIn() || !hasRole('keuangan')) {
    header('Location: ../index.php?error=unauthorized');
    exit;
}

$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

try {
    // Monthly Revenue Detail
    $stmt = $conn->prepare("
        SELECT 
            DATE(b.tanggal) as booking_date,
            COUNT(b.id_booking) as daily_bookings,
            SUM(b.total_amount) as daily_revenue,
            AVG(b.total_amount) as avg_booking_value
        FROM tbl_booking b
        WHERE MONTH(b.tanggal) = ? AND YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
        GROUP BY DATE(b.tanggal)
        ORDER BY booking_date
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $dailyRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Revenue by User Type
    $stmt = $conn->prepare("
        SELECT 
            u.role as user_type,
            COUNT(b.id_booking) as total_bookings,
            SUM(b.total_amount) as total_revenue,
            AVG(b.total_amount) as avg_revenue_per_booking
        FROM tbl_booking b
        JOIN tbl_users u ON b.id_user = u.id_user
        WHERE MONTH(b.tanggal) = ? AND YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
        GROUP BY u.role
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $revenueByUserType = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly Expenses by Category
    $stmt = $conn->prepare("
        SELECT 
            category,
            COUNT(*) as expense_count,
            SUM(amount) as total_amount,
            AVG(amount) as avg_amount
        FROM tbl_expenses
        WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?
        AND status = 'approved'
        GROUP BY category
        ORDER BY total_amount DESC
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $expensesByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $totalMonthlyRevenue = array_sum(array_column($dailyRevenue, 'daily_revenue'));
    $totalMonthlyExpenses = array_sum(array_column($expensesByCategory, 'total_amount'));
    $totalMonthlyBookings = array_sum(array_column($dailyRevenue, 'daily_bookings'));
    $netProfit = $totalMonthlyRevenue - $totalMonthlyExpenses;
    $profitMargin = $totalMonthlyRevenue > 0 ? ($netProfit / $totalMonthlyRevenue) * 100 : 0;
    
} catch (PDOException $e) {
    error_log("Error in monthly report: " . $e->getMessage());
    $error_message = "Terjadi kesalahan dalam memuat laporan bulanan.";
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Bulanan - Dashboard Keuangan</title>
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
                            <a href="monthly-report.php" class="list-group-item list-group-item-action active">
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
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= $error_message ?>
                    </div>
                <?php endif; ?>

                <!-- Header dengan periode -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h2 class="keuangan-page-title">
                            <i class="fas fa-calendar-alt me-3"></i>Laporan Bulanan
                        </h2>
                        <p class="text-muted">Periode: <?= date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)) ?></p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="btn-group">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-calendar me-2"></i>Pilih Periode
                            </button>
                            <ul class="dropdown-menu">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <li>
                                    <a class="dropdown-item <?= $m == $selectedMonth ? 'active' : '' ?>" 
                                       href="?month=<?= $m ?>&year=<?= $selectedYear ?>">
                                        <?= date('F Y', mktime(0, 0, 0, $m, 1, $selectedYear)) ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                        <button class="btn btn-keuangan" onclick="exportReport()">
                            <i class="fas fa-download me-2"></i>Export PDF
                        </button>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card revenue-summary">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-arrow-up"></i>
                                </div>
                                <h4 class="keuangan-summary-value"><?= formatRupiah($totalMonthlyRevenue) ?></h4>
                                <p class="keuangan-summary-label">Total Pendapatan</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card expense-summary">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                                <h4 class="keuangan-summary-value"><?= formatRupiah($totalMonthlyExpenses) ?></h4>
                                <p class="keuangan-summary-label">Total Pengeluaran</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card profit-summary">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <h4 class="keuangan-summary-value <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatRupiah($netProfit) ?>
                                </h4>
                                <p class="keuangan-summary-label">Profit Bersih</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-summary-card booking-summary">
                            <div class="card-body text-center">
                                <div class="keuangan-summary-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <h4 class="keuangan-summary-value"><?= number_format($totalMonthlyBookings) ?></h4>
                                <p class="keuangan-summary-label">Total Booking</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <!-- Daily Revenue Chart -->
                    <div class="col-md-8">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>Pendapatan Harian
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="dailyRevenueChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Revenue by User Type -->
                    <div class="col-md-4">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-users me-2"></i>Pendapatan per Tipe User
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="userTypeChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Tables -->
                <div class="row mb-4">
                    <!-- Revenue by User Type Table -->
                    <div class="col-md-6">
                        <div class="card keuangan-table-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-users me-2"></i>Detail Pendapatan per Tipe User
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table keuangan-table">
                                        <thead>
                                            <tr>
                                                <th>Tipe User</th>
                                                <th>Booking</th>
                                                <th>Total Revenue</th>
                                                <th>Avg/Booking</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($revenueByUserType as $userType): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-primary"><?= ucfirst($userType['user_type']) ?></span>
                                                </td>
                                                <td><?= number_format($userType['total_bookings']) ?></td>
                                                <td class="text-success">
                                                    <strong><?= formatRupiah($userType['total_revenue']) ?></strong>
                                                </td>
                                                <td><?= formatRupiah($userType['avg_revenue_per_booking']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Expenses by Category Table -->
                    <div class="col-md-6">
                        <div class="card keuangan-table-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-receipt me-2"></i>Pengeluaran per Kategori
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table keuangan-table">
                                        <thead>
                                            <tr>
                                                <th>Kategori</th>
                                                <th>Jumlah</th>
                                                <th>Total</th>
                                                <th>Rata-rata</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($expensesByCategory as $expense): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-warning"><?= ucfirst($expense['category']) ?></span>
                                                </td>
                                                <td><?= number_format($expense['expense_count']) ?></td>
                                                <td class="text-danger">
                                                    <strong><?= formatRupiah($expense['total_amount']) ?></strong>
                                                </td>
                                                <td><?= formatRupiah($expense['avg_amount']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Summary Report -->
                <div class="card keuangan-report-card">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-file-alt me-2"></i>Ringkasan Laporan Bulanan
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary">📊 Performa Keuangan</h6>
                                <ul class="list-unstyled">
                                    <li>✅ Total Pendapatan: <strong><?= formatRupiah($totalMonthlyRevenue) ?></strong></li>
                                    <li>❌ Total Pengeluaran: <strong><?= formatRupiah($totalMonthlyExpenses) ?></strong></li>
                                    <li>💰 Profit Bersih: <strong class="<?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatRupiah($netProfit) ?></strong></li>
                                    <li>📈 Margin Keuntungan: <strong><?= number_format($profitMargin, 1) ?>%</strong></li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">📅 Statistik Booking</h6>
                                <ul class="list-unstyled">
                                    <li>📋 Total Booking: <strong><?= number_format($totalMonthlyBookings) ?></strong></li>
                                    <li>💵 Rata-rata per Booking: <strong><?= $totalMonthlyBookings > 0 ? formatRupiah($totalMonthlyRevenue / $totalMonthlyBookings) : 'Rp 0' ?></strong></li>
                                    <li>📊 Booking per Hari: <strong><?= number_format($totalMonthlyBookings / 30, 1) ?></strong></li>
                                    <li>🏆 User Terbanyak: <strong><?= !empty($revenueByUserType) ? ucfirst($revenueByUserType[0]['user_type']) : 'N/A' ?></strong></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Data untuk charts
        const dailyRevenueData = <?= json_encode($dailyRevenue) ?>;
        const userTypeData = <?= json_encode($revenueByUserType) ?>;

        // Daily Revenue Chart
        const dailyCtx = document.getElementById('dailyRevenueChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: dailyRevenueData.map(item => new Date(item.booking_date).getDate()),
                datasets: [{
                    label: 'Pendapatan Harian',
                    data: dailyRevenueData.map(item => item.daily_revenue),
                    backgroundColor: 'rgba(220, 20, 60, 0.7)',
                    borderColor: '#dc143c',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + (value / 1000000).toFixed(1) + 'Jt';
                            }
                        }
                    }
                }
            }
        });

        // User Type Chart
        const userCtx = document.getElementById('userTypeChart').getContext('2d');
        new Chart(userCtx, {
            type: 'pie',
            data: {
                labels: userTypeData.map(item => item.user_type.charAt(0).toUpperCase() + item.user_type.slice(1)),
                datasets: [{
                    data: userTypeData.map(item => item.total_revenue),
                    backgroundColor: ['#dc143c', '#f0a0a0', '#f5b2b2', '#ffb3b3'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        function exportReport() {
            // Implementasi export PDF
            window.print();
        }
    </script>
</body>
</html>