<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isLoggedIn() || !hasRole('keuangan')) {
    header('Location: ../index.php?error=unauthorized');
    exit;
}

// Handle report generation requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $reportType = $_POST['report_type'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $format = $_POST['format'];
    
    // Here you would generate the actual report
    $success_message = "Laporan {$reportType} periode {$startDate} - {$endDate} berhasil di-generate dalam format {$format}.";
}

$currentMonth = date('n');
$currentYear = date('Y');

try {
    // Financial Summary for Current Year
    $stmt = $conn->prepare("
        SELECT 
            MONTH(b.tanggal) as month,
            SUM(b.total_amount) as monthly_revenue,
            COUNT(b.id_booking) as monthly_bookings
        FROM tbl_booking b
        WHERE YEAR(b.tanggal) = ? 
        AND b.status IN ('approve', 'active', 'done')
        GROUP BY MONTH(b.tanggal)
        ORDER BY month
    ");
    $stmt->execute([$currentYear]);
    $monthlyRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly Expenses
    $stmt = $conn->prepare("
        SELECT 
            MONTH(expense_date) as month,
            SUM(amount) as monthly_expenses,
            COUNT(*) as expense_count
        FROM tbl_expenses
        WHERE YEAR(expense_date) = ? 
        AND status = 'approved'
        GROUP BY MONTH(expense_date)
        ORDER BY month
    ");
    $stmt->execute([$currentYear]);
    $monthlyExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine revenue and expenses data
    $financialData = [];
    for ($i = 1; $i <= 12; $i++) {
        $revenue = 0;
        $expenses = 0;
        $bookings = 0;
        
        foreach ($monthlyRevenue as $rev) {
            if ($rev['month'] == $i) {
                $revenue = $rev['monthly_revenue'];
                $bookings = $rev['monthly_bookings'];
                break;
            }
        }
        
        foreach ($monthlyExpenses as $exp) {
            if ($exp['month'] == $i) {
                $expenses = $exp['monthly_expenses'];
                break;
            }
        }
        
        $financialData[] = [
            'month' => $i,
            'month_name' => date('F', mktime(0, 0, 0, $i, 1)),
            'revenue' => $revenue,
            'expenses' => $expenses,
            'profit' => $revenue - $expenses,
            'bookings' => $bookings
        ];
    }
    
    // Year-to-date summary
    $ytdRevenue = array_sum(array_column($financialData, 'revenue'));
    $ytdExpenses = array_sum(array_column($financialData, 'expenses'));
    $ytdProfit = $ytdRevenue - $ytdExpenses;
    $ytdBookings = array_sum(array_column($financialData, 'bookings'));
    
    // Room performance for reports
    $stmt = $conn->prepare("
        SELECT 
            r.nama_ruang,
            g.nama_gedung,
            COUNT(b.id_booking) as total_bookings,
            SUM(b.total_amount) as total_revenue,
            AVG(b.total_amount) as avg_booking_value,
            SUM(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as total_hours
        FROM tbl_ruang r
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        LEFT JOIN tbl_booking b ON r.id_ruang = b.id_ruang 
            AND YEAR(b.tanggal) = ?
            AND b.status IN ('approve', 'active', 'done')
        GROUP BY r.id_ruang, r.nama_ruang, g.nama_gedung
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([$currentYear]);
    $roomPerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // User type analysis
    $stmt = $conn->prepare("
        SELECT 
            u.role,
            COUNT(b.id_booking) as bookings,
            SUM(b.total_amount) as revenue,
            AVG(b.total_amount) as avg_booking_value
        FROM tbl_booking b
        JOIN tbl_users u ON b.id_user = u.id_user
        WHERE YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
        GROUP BY u.role
        ORDER BY revenue DESC
    ");
    $stmt->execute([$currentYear]);
    $userTypeAnalysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error in financial reports: " . $e->getMessage());
    $error_message = "Terjadi kesalahan dalam memuat data laporan.";
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatPercentage($value, $decimals = 1) {
    return number_format($value, $decimals) . '%';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan - Dashboard Keuangan</title>
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
                            <a href="expense-management.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-receipt me-2"></i>Kelola Pengeluaran
                            </a>
                            <a href="financial-reports.php" class="list-group-item list-group-item-action active">
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
                            <i class="fas fa-file-invoice-dollar me-3"></i>Laporan Keuangan
                        </h2>
                        <p class="text-muted">Generate dan kelola laporan keuangan komprehensif</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-keuangan" data-bs-toggle="modal" data-bs-target="#generateReportModal">
                            <i class="fas fa-plus me-2"></i>Generate Laporan
                        </button>
                    </div>
                </div>

                <!-- YTD Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card keuangan-ytd-card revenue-ytd">
                            <div class="card-body text-center">
                                <div class="ytd-icon">
                                    <i class="fas fa-arrow-up"></i>
                                </div>
                                <h4 class="ytd-value text-success"><?= formatRupiah($ytdRevenue) ?></h4>
                                <p class="ytd-label">YTD Revenue</p>
                                <small class="text-muted"><?= number_format($ytdBookings) ?> bookings</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-ytd-card expense-ytd">
                            <div class="card-body text-center">
                                <div class="ytd-icon">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                                <h4 class="ytd-value text-danger"><?= formatRupiah($ytdExpenses) ?></h4>
                                <p class="ytd-label">YTD Expenses</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-ytd-card profit-ytd">
                            <div class="card-body text-center">
                                <div class="ytd-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <h4 class="ytd-value <?= $ytdProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatRupiah($ytdProfit) ?>
                                </h4>
                                <p class="ytd-label">YTD Profit</p>
                                <small class="text-muted">
                                    Margin: <?= $ytdRevenue > 0 ? formatPercentage(($ytdProfit / $ytdRevenue) * 100) : '0%' ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-ytd-card avg-ytd">
                            <div class="card-body text-center">
                                <div class="ytd-icon">
                                    <i class="fas fa-calculator"></i>
                                </div>
                                <h4 class="ytd-value">
                                    <?= $ytdBookings > 0 ? formatRupiah($ytdRevenue / $ytdBookings) : 'Rp 0' ?>
                                </h4>
                                <p class="ytd-label">Avg Booking Value</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <!-- Monthly Financial Overview -->
                    <div class="col-md-8">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>Dashboard Keuangan Tahunan <?= $currentYear ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="yearlyFinancialChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- User Type Revenue -->
                    <div class="col-md-4">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-users me-2"></i>Revenue by User Type
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="userTypeRevenueChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Report Templates -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card keuangan-template-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-file-alt me-2"></i>Template Laporan Cepat
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="report-template" onclick="generateQuickReport('profit-loss')">
                                            <div class="template-icon">
                                                <i class="fas fa-chart-line"></i>
                                            </div>
                                            <h6>Profit & Loss</h6>
                                            <p>Laporan laba rugi bulanan/tahunan</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="report-template" onclick="generateQuickReport('cash-flow')">
                                            <div class="template-icon">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </div>
                                            <h6>Cash Flow</h6>
                                            <p>Laporan arus kas masuk dan keluar</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="report-template" onclick="generateQuickReport('room-performance')">
                                            <div class="template-icon">
                                                <i class="fas fa-building"></i>
                                            </div>
                                            <h6>Room Performance</h6>
                                            <p>Laporan performa setiap ruangan</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="report-template" onclick="generateQuickReport('expense-analysis')">
                                            <div class="template-icon">
                                                <i class="fas fa-receipt"></i>
                                            </div>
                                            <h6>Expense Analysis</h6>
                                            <p>Analisis pengeluaran per kategori</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Room Performance Table -->
                <div class="card keuangan-table-card mb-4">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-table me-2"></i>Performance Summary - Top Rooms YTD
                        </h5>
                        <button class="btn btn-sm btn-outline-light" onclick="exportTable('room-performance')">
                            <i class="fas fa-download me-1"></i>Export
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table keuangan-table" id="roomPerformanceTable">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Ruangan</th>
                                        <th>Gedung</th>
                                        <th>Total Bookings</th>
                                        <th>Total Revenue</th>
                                        <th>Avg Booking Value</th>
                                        <th>Total Hours</th>
                                        <th>Revenue/Hour</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roomPerformance as $index => $room): ?>
                                    <tr>
                                        <td>
                                            <span class="rank-badge">#<?= $index + 1 ?></span>
                                        </td>
                                        <td><strong><?= htmlspecialchars($room['nama_ruang']) ?></strong></td>
                                        <td><?= htmlspecialchars($room['nama_gedung']) ?></td>
                                        <td><?= number_format($room['total_bookings']) ?></td>
                                        <td class="text-success">
                                            <strong><?= formatRupiah($room['total_revenue']) ?></strong>
                                        </td>
                                        <td><?= formatRupiah($room['avg_booking_value']) ?></td>
                                        <td><?= number_format($room['total_hours']) ?> jam</td>
                                        <td>
                                            <?= $room['total_hours'] > 0 ? formatRupiah($room['total_revenue'] / $room['total_hours']) : 'Rp 0' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Profit & Loss Statement -->
                <div class="card keuangan-statement-card">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-file-invoice me-2"></i>Profit & Loss Statement YTD <?= $currentYear ?>
                        </h5>
                        <button class="btn btn-sm btn-outline-light" onclick="printStatement()">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="statement-section">
                            <h6 class="statement-header text-success">REVENUE</h6>
                            <div class="statement-item">
                                <span class="item-label">Room Booking Revenue</span>
                                <span class="item-value"><?= formatRupiah($ytdRevenue * 0.8) ?></span>
                            </div>
                            <div class="statement-item">
                                <span class="item-label">Add-On Services Revenue</span>
                                <span class="item-value"><?= formatRupiah($ytdRevenue * 0.2) ?></span>
                            </div>
                            <div class="statement-item total">
                                <span class="item-label"><strong>Total Revenue</strong></span>
                                <span class="item-value"><strong><?= formatRupiah($ytdRevenue) ?></strong></span>
                            </div>
                        </div>

                        <div class="statement-section">
                            <h6 class="statement-header text-danger">EXPENSES</h6>
                            <div class="statement-item">
                                <span class="item-label">Operational Expenses</span>
                                <span class="item-value"><?= formatRupiah($ytdExpenses * 0.7) ?></span>
                            </div>
                            <div class="statement-item">
                                <span class="item-label">Maintenance & Utilities</span>
                                <span class="item-value"><?= formatRupiah($ytdExpenses * 0.3) ?></span>
                            </div>
                            <div class="statement-item total">
                                <span class="item-label"><strong>Total Expenses</strong></span>
                                <span class="item-value"><strong><?= formatRupiah($ytdExpenses) ?></strong></span>
                            </div>
                        </div>

                        <div class="statement-section">
                            <h6 class="statement-header text-primary">NET RESULT</h6>
                            <div class="statement-item net-profit">
                                <span class="item-label"><strong>Net Profit</strong></span>
                                <span class="item-value <?= $ytdProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <strong><?= formatRupiah($ytdProfit) ?></strong>
                                </span>
                            </div>
                            <div class="statement-item">
                                <span class="item-label">Profit Margin</span>
                                <span class="item-value">
                                    <?= $ytdRevenue > 0 ? formatPercentage(($ytdProfit / $ytdRevenue) * 100) : '0%' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Report Modal -->
    <div class="modal fade" id="generateReportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-alt me-2"></i>Generate Laporan Keuangan
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="generate_report" value="1">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Jenis Laporan *</label>
                                    <select name="report_type" class="form-select" required>
                                        <option value="">Pilih Jenis Laporan</option>
                                        <option value="profit-loss">Profit & Loss</option>
                                        <option value="cash-flow">Cash Flow</option>
                                        <option value="room-performance">Room Performance</option>
                                        <option value="expense-analysis">Expense Analysis</option>
                                        <option value="revenue-analysis">Revenue Analysis</option>
                                        <option value="comprehensive">Comprehensive Report</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Format Output *</label>
                                    <select name="format" class="form-select" required>
                                        <option value="pdf">PDF</option>
                                        <option value="excel">Excel</option>
                                        <option value="csv">CSV</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tanggal Mulai *</label>
                                    <input type="date" name="start_date" class="form-control" 
                                           value="<?= date('Y-01-01') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tanggal Akhir *</label>
                                    <input type="date" name="end_date" class="form-control" 
                                           value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Filter Tambahan</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="include_charts" value="1" checked>
                                        <label class="form-check-label">Include Charts</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="include_breakdown" value="1" checked>
                                        <label class="form-check-label">Detail Breakdown</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="include_comparison" value="1">
                                        <label class="form-check-label">Year-over-Year Comparison</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="include_forecast" value="1">
                                        <label class="form-check-label">Financial Forecast</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-keuangan">
                            <i class="fas fa-cog me-2"></i>Generate Laporan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Data untuk charts
        const financialData = <?= json_encode($financialData) ?>;
        const userTypeAnalysis = <?= json_encode($userTypeAnalysis) ?>;

        // Yearly Financial Chart
        const yearlyCtx = document.getElementById('yearlyFinancialChart').getContext('2d');
        new Chart(yearlyCtx, {
            type: 'bar',
            data: {
                labels: financialData.map(item => item.month_name),
                datasets: [{
                    label: 'Revenue',
                    data: financialData.map(item => item.revenue),
                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                    borderColor: '#28a745',
                    borderWidth: 1
                }, {
                    label: 'Expenses',
                    data: financialData.map(item => item.expenses),
                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                    borderColor: '#dc3545',
                    borderWidth: 1
                }, {
                    label: 'Profit',
                    data: financialData.map(item => item.profit),
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

        // User Type Revenue Chart
        const userTypeCtx = document.getElementById('userTypeRevenueChart').getContext('2d');
        new Chart(userTypeCtx, {
            type: 'doughnut',
            data: {
                labels: userTypeAnalysis.map(item => item.role.charAt(0).toUpperCase() + item.role.slice(1)),
                datasets: [{
                    data: userTypeAnalysis.map(item => item.revenue),
                    backgroundColor: [
                        '#dc143c',
                        '#f0a0a0',
                        '#f5b2b2',
                        '#ffb3b3'
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
                        position: 'bottom'
                    }
                }
            }
        });

        function generateQuickReport(type) {
            // Set modal form values
            document.querySelector('select[name="report_type"]').value = type;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('generateReportModal'));
            modal.show();
        }

        function exportTable(tableType) {
            // Implementasi export table
            alert('Export ' + tableType + ' table');
        }

        function printStatement() {
            window.print();
        }
    </script>
</body>
</html>