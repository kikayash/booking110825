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
    // Revenue Analysis
    $stmt = $conn->prepare("
        SELECT 
            SUM(b.total_amount) as total_revenue,
            COUNT(b.id_booking) as total_bookings,
            AVG(b.total_amount) as avg_booking_value,
            SUM(CASE WHEN b.booking_type = 'recurring' THEN b.total_amount ELSE 0 END) as academic_revenue,
            SUM(CASE WHEN b.booking_type = 'manual' THEN b.total_amount ELSE 0 END) as regular_revenue
        FROM tbl_booking b
        WHERE MONTH(b.tanggal) = ? AND YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $revenueAnalysis = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Cost Analysis
    $stmt = $conn->prepare("
        SELECT 
            category,
            SUM(amount) as total_cost,
            COUNT(*) as item_count,
            AVG(amount) as avg_cost
        FROM tbl_expenses
        WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?
        AND status = 'approved'
        GROUP BY category
        ORDER BY total_cost DESC
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $costBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Room-wise Cost-Benefit
    $stmt = $conn->prepare("
        SELECT 
            r.nama_ruang,
            r.kapasitas,
            COUNT(b.id_booking) as bookings,
            SUM(b.total_amount) as revenue,
            AVG(b.total_amount) as avg_revenue,
            SUM(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as total_hours,
            (SUM(b.total_amount) / r.kapasitas) as revenue_per_capacity,
            (SUM(b.total_amount) / NULLIF(SUM(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)), 0)) as revenue_per_hour
        FROM tbl_ruang r
        LEFT JOIN tbl_booking b ON r.id_ruang = b.id_ruang 
            AND MONTH(b.tanggal) = ? AND YEAR(b.tanggal) = ?
            AND b.status IN ('approve', 'active', 'done')
        GROUP BY r.id_ruang, r.nama_ruang, r.kapasitas
        ORDER BY revenue DESC
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $roomCostBenefit = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly Trends (6 months)
    $monthlyTrends = [];
    for ($i = 5; $i >= 0; $i--) {
        $month = $selectedMonth - $i;
        $year = $selectedYear;
        
        if ($month <= 0) {
            $month += 12;
            $year--;
        }
        
        // Revenue
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as revenue
            FROM tbl_booking 
            WHERE MONTH(tanggal) = ? AND YEAR(tanggal) = ?
            AND status IN ('approve', 'active', 'done')
        ");
        $stmt->execute([$month, $year]);
        $monthRevenue = $stmt->fetchColumn();
        
        // Costs
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as costs
            FROM tbl_expenses 
            WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?
            AND status = 'approved'
        ");
        $stmt->execute([$month, $year]);
        $monthCosts = $stmt->fetchColumn();
        
        $monthlyTrends[] = [
            'month' => date('M Y', mktime(0, 0, 0, $month, 1, $year)),
            'revenue' => $monthRevenue,
            'costs' => $monthCosts,
            'profit' => $monthRevenue - $monthCosts
        ];
    }
    
    // Calculate metrics
    $totalRevenue = $revenueAnalysis['total_revenue'] ?: 0;
    $totalCosts = array_sum(array_column($costBreakdown, 'total_cost'));
    $netProfit = $totalRevenue - $totalCosts;
    $profitMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;
    $roi = $totalCosts > 0 ? (($netProfit) / $totalCosts) * 100 : 0;
    
    // Break-even analysis
    $fixedCosts = 0;
    $variableCosts = 0;
    foreach ($costBreakdown as $cost) {
        if (in_array($cost['category'], ['utilities', 'maintenance'])) {
            $fixedCosts += $cost['total_cost'];
        } else {
            $variableCosts += $cost['total_cost'];
        }
    }
    
    $avgBookingValue = $revenueAnalysis['avg_booking_value'] ?: 0;
    $breakEvenBookings = $avgBookingValue > 0 ? $fixedCosts / $avgBookingValue : 0;
    
} catch (PDOException $e) {
    error_log("Error in cost-benefit analysis: " . $e->getMessage());
    $error_message = "Terjadi kesalahan dalam memuat analisis cost-benefit.";
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatPercentage($value, $decimals = 1) {
    return number_format($value, $decimals) . '%';
}

function getROILevel($roi) {
    if ($roi >= 30) return ['level' => 'Excellent', 'class' => 'success', 'icon' => 'arrow-up'];
    if ($roi >= 15) return ['level' => 'Good', 'class' => 'primary', 'icon' => 'thumbs-up'];
    if ($roi >= 5) return ['level' => 'Average', 'class' => 'warning', 'icon' => 'minus'];
    if ($roi >= 0) return ['level' => 'Poor', 'class' => 'danger', 'icon' => 'arrow-down'];
    return ['level' => 'Loss', 'class' => 'danger', 'icon' => 'exclamation-triangle'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost-Benefit Analysis - Dashboard Keuangan</title>
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
                            <a href="cost-benefit.php" class="list-group-item list-group-item-action active">
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

                <!-- Header -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h2 class="keuangan-page-title">
                            <i class="fas fa-balance-scale me-3"></i>Cost-Benefit Analysis
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
                    </div>
                </div>

                <!-- Key Metrics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card keuangan-metric-card profit-metric">
                            <div class="card-body text-center">
                                <div class="metric-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <h3 class="metric-value <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= formatRupiah($netProfit) ?>
                                </h3>
                                <p class="metric-label">Net Profit</p>
                                <small class="metric-detail">
                                    Margin: <?= formatPercentage($profitMargin) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-metric-card roi-metric">
                            <div class="card-body text-center">
                                <div class="metric-icon">
                                    <i class="fas fa-percentage"></i>
                                </div>
                                <h3 class="metric-value">
                                    <?= formatPercentage($roi) ?>
                                </h3>
                                <p class="metric-label">Return on Investment</p>
                                <?php $roiLevel = getROILevel($roi); ?>
                                <span class="badge bg-<?= $roiLevel['class'] ?>">
                                    <i class="fas fa-<?= $roiLevel['icon'] ?> me-1"></i>
                                    <?= $roiLevel['level'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-metric-card breakeven-metric">
                            <div class="card-body text-center">
                                <div class="metric-icon">
                                    <i class="fas fa-target"></i>
                                </div>
                                <h3 class="metric-value">
                                    <?= number_format($breakEvenBookings, 0) ?>
                                </h3>
                                <p class="metric-label">Break-even Bookings</p>
                                <small class="metric-detail">
                                    Per bulan
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card keuangan-metric-card efficiency-metric">
                            <div class="card-body text-center">
                                <div class="metric-icon">
                                    <i class="fas fa-cogs"></i>
                                </div>
                                <h3 class="metric-value">
                                    <?= $totalCosts > 0 ? formatPercentage(($totalRevenue / $totalCosts) * 100) : '0%' ?>
                                </h3>
                                <p class="metric-label">Revenue/Cost Ratio</p>
                                <small class="metric-detail">
                                    Efisiensi operasional
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <!-- Profit Trend -->
                    <div class="col-md-8">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-line me-2"></i>Trend Revenue vs Cost (6 Bulan)
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="profitTrendChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Cost Breakdown -->
                    <div class="col-md-4">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Cost Breakdown
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="costBreakdownChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Revenue Analysis -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card keuangan-analysis-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-arrow-up me-2"></i>Revenue Analysis
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="analysis-item">
                                    <div class="analysis-label">Total Revenue</div>
                                    <div class="analysis-value text-success"><?= formatRupiah($totalRevenue) ?></div>
                                </div>
                                <div class="analysis-item">
                                    <div class="analysis-label">Academic Revenue</div>
                                    <div class="analysis-value"><?= formatRupiah($revenueAnalysis['academic_revenue']) ?></div>
                                    <div class="analysis-percent">
                                        (<?= $totalRevenue > 0 ? formatPercentage(($revenueAnalysis['academic_revenue'] / $totalRevenue) * 100) : '0%' ?>)
                                    </div>
                                </div>
                                <div class="analysis-item">
                                    <div class="analysis-label">Regular Revenue</div>
                                    <div class="analysis-value"><?= formatRupiah($revenueAnalysis['regular_revenue']) ?></div>
                                    <div class="analysis-percent">
                                        (<?= $totalRevenue > 0 ? formatPercentage(($revenueAnalysis['regular_revenue'] / $totalRevenue) * 100) : '0%' ?>)
                                    </div>
                                </div>
                                <div class="analysis-item">
                                    <div class="analysis-label">Average Booking Value</div>
                                    <div class="analysis-value"><?= formatRupiah($avgBookingValue) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card keuangan-analysis-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-arrow-down me-2"></i>Cost Analysis
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="analysis-item">
                                    <div class="analysis-label">Total Costs</div>
                                    <div class="analysis-value text-danger"><?= formatRupiah($totalCosts) ?></div>
                                </div>
                                <div class="analysis-item">
                                    <div class="analysis-label">Fixed Costs</div>
                                    <div class="analysis-value"><?= formatRupiah($fixedCosts) ?></div>
                                    <div class="analysis-percent">
                                        (<?= $totalCosts > 0 ? formatPercentage(($fixedCosts / $totalCosts) * 100) : '0%' ?>)
                                    </div>
                                </div>
                                <div class="analysis-item">
                                    <div class="analysis-label">Variable Costs</div>
                                    <div class="analysis-value"><?= formatRupiah($variableCosts) ?></div>
                                    <div class="analysis-percent">
                                        (<?= $totalCosts > 0 ? formatPercentage(($variableCosts / $totalCosts) * 100) : '0%' ?>)
                                    </div>
                                </div>
                                <div class="analysis-item">
                                    <div class="analysis-label">Cost per Booking</div>
                                    <div class="analysis-value">
                                        <?= $revenueAnalysis['total_bookings'] > 0 ? formatRupiah($totalCosts / $revenueAnalysis['total_bookings']) : 'Rp 0' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Room Cost-Benefit Table -->
                <div class="card keuangan-table-card">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-building me-2"></i>Cost-Benefit Analysis per Ruangan
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table keuangan-table">
                                <thead>
                                    <tr>
                                        <th>Ruangan</th>
                                        <th>Kapasitas</th>
                                        <th>Booking</th>
                                        <th>Total Jam</th>
                                        <th>Revenue</th>
                                        <th>Revenue/Jam</th>
                                        <th>Revenue/Kapasitas</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roomCostBenefit as $room): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($room['nama_ruang']) ?></strong></td>
                                        <td>
                                            <span class="badge bg-info"><?= $room['kapasitas'] ?> orang</span>
                                        </td>
                                        <td><?= number_format($room['bookings']) ?></td>
                                        <td><?= number_format($room['total_hours']) ?> jam</td>
                                        <td class="text-success">
                                            <strong><?= formatRupiah($room['revenue']) ?></strong>
                                        </td>
                                        <td><?= formatRupiah($room['revenue_per_hour']) ?></td>
                                        <td><?= formatRupiah($room['revenue_per_capacity']) ?></td>
                                        <td>
                                            <?php 
                                            $efficiency = $room['revenue_per_capacity'];
                                            if ($efficiency > 50000) {
                                                $badgeClass = 'success';
                                                $icon = 'star';
                                                $level = 'Excellent';
                                            } elseif ($efficiency > 30000) {
                                                $badgeClass = 'primary';
                                                $icon = 'thumbs-up';
                                                $level = 'Good';
                                            } elseif ($efficiency > 10000) {
                                                $badgeClass = 'warning';
                                                $icon = 'minus';
                                                $level = 'Average';
                                            } else {
                                                $badgeClass = 'danger';
                                                $icon = 'arrow-down';
                                                $level = 'Poor';
                                            }
                                            ?>
                                            <span class="badge bg-<?= $badgeClass ?>">
                                                <i class="fas fa-<?= $icon ?> me-1"></i>
                                                <?= $level ?>
                                            </span>
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

    <?php include '../footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Data untuk charts
        const monthlyTrends = <?= json_encode($monthlyTrends) ?>;
        const costBreakdown = <?= json_encode($costBreakdown) ?>;

        // Profit Trend Chart
        const trendCtx = document.getElementById('profitTrendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: monthlyTrends.map(item => item.month),
                datasets: [{
                    label: 'Revenue',
                    data: monthlyTrends.map(item => item.revenue),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 3,
                    tension: 0.4
                }, {
                    label: 'Costs',
                    data: monthlyTrends.map(item => item.costs),
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    borderWidth: 3,
                    tension: 0.4
                }, {
                    label: 'Profit',
                    data: monthlyTrends.map(item => item.profit),
                    borderColor: '#dc143c',
                    backgroundColor: 'rgba(220, 20, 60, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true
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

        // Cost Breakdown Chart
        const costCtx = document.getElementById('costBreakdownChart').getContext('2d');
        new Chart(costCtx, {
            type: 'doughnut',
            data: {
                labels: costBreakdown.map(item => item.category.charAt(0).toUpperCase() + item.category.slice(1)),
                datasets: [{
                    data: costBreakdown.map(item => item.total_cost),
                    backgroundColor: [
                        '#dc143c',
                        '#f0a0a0',
                        '#f5b2b2',
                        '#ffb3b3',
                        '#ffc4c4'
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
    </script>
</body>
</html>