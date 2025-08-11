<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and has keuangan role
if (!isLoggedIn() || !hasRole('keuangan')) {
    header('Location: ../index.php?error=unauthorized');
    exit;
}

// Get current date and month for reports
$currentMonth = date('n');
$currentYear = date('Y');
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : $currentMonth;
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentYear;

try {
    // === DASHBOARD STATISTICS ===
    
    // 1. Total Revenue This Month
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(b.total_amount), 0) as total_revenue,
            COUNT(DISTINCT b.id_booking) as total_bookings,
            AVG(DATEDIFF(b.tanggal, b.created_at)) as avg_booking_lead_time
        FROM tbl_booking b 
        WHERE MONTH(b.tanggal) = ? 
        AND YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $currentStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Previous Month Comparison
    $prevMonth = $selectedMonth == 1 ? 12 : $selectedMonth - 1;
    $prevYear = $selectedMonth == 1 ? $selectedYear - 1 : $selectedYear;
    
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(b.total_amount), 0) as total_revenue,
            COUNT(DISTINCT b.id_booking) as total_bookings
        FROM tbl_booking b 
        WHERE MONTH(b.tanggal) = ? 
        AND YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
    ");
    $stmt->execute([$prevMonth, $prevYear]);
    $prevStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate growth percentages
    $revenueGrowth = $prevStats['total_revenue'] > 0 ? 
        (($currentStats['total_revenue'] - $prevStats['total_revenue']) / $prevStats['total_revenue']) * 100 : 0;
    $bookingGrowth = $prevStats['total_bookings'] > 0 ? 
        (($currentStats['total_bookings'] - $prevStats['total_bookings']) / $prevStats['total_bookings']) * 100 : 0;
    
    // 3. Room Occupancy Rate
    $stmt = $conn->prepare("
        SELECT 
            r.id_ruang,
            r.nama_ruang,
            COUNT(b.id_booking) as booking_count,
            SUM(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as total_hours,
            (COUNT(b.id_booking) * 100.0 / (30 * 12)) as occupancy_rate
        FROM tbl_ruang r
        LEFT JOIN tbl_booking b ON r.id_ruang = b.id_ruang 
            AND MONTH(b.tanggal) = ? 
            AND YEAR(b.tanggal) = ?
            AND b.status IN ('approve', 'active', 'done')
        GROUP BY r.id_ruang, r.nama_ruang
        ORDER BY occupancy_rate DESC
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $roomOccupancy = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Expenses This Month
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_expenses
        FROM tbl_expenses 
        WHERE MONTH(expense_date) = ? 
        AND YEAR(expense_date) = ?
        AND status = 'approved'
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $totalExpenses = $stmt->fetchColumn() ?: 0;
    
    // 5. Monthly Revenue Trend (12 months)
    $monthlyRevenue = [];
    for ($i = 11; $i >= 0; $i--) {
        $month = $selectedMonth - $i;
        $year = $selectedYear;
        
        if ($month <= 0) {
            $month += 12;
            $year--;
        }
        
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(b.total_amount), 0) as revenue
            FROM tbl_booking b
            WHERE MONTH(b.tanggal) = ? 
            AND YEAR(b.tanggal) = ?
            AND b.status IN ('approve', 'active', 'done')
        ");
        $stmt->execute([$month, $year]);
        $revenue = $stmt->fetchColumn() ?: 0;
        
        $monthlyRevenue[] = [
            'month' => date('M Y', mktime(0, 0, 0, $month, 1, $year)),
            'revenue' => $revenue
        ];
    }
    
    // 6. Top Performing Rooms
    $stmt = $conn->prepare("
        SELECT 
            r.nama_ruang,
            COUNT(b.id_booking) as total_bookings,
            COALESCE(SUM(b.total_amount), 0) as total_revenue,
            AVG(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as avg_duration
        FROM tbl_ruang r
        LEFT JOIN tbl_booking b ON r.id_ruang = b.id_ruang 
            AND MONTH(b.tanggal) = ? 
            AND YEAR(b.tanggal) = ?
            AND b.status IN ('approve', 'active', 'done')
        GROUP BY r.id_ruang, r.nama_ruang
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $topRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate Key Metrics
    $totalRevenue = $currentStats['total_revenue'];
    $netProfit = $totalRevenue - $totalExpenses;
    $profitMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;
    $avgOccupancy = !empty($roomOccupancy) ? 
        array_sum(array_column($roomOccupancy, 'occupancy_rate')) / count($roomOccupancy) : 0;
    
} catch (PDOException $e) {
    error_log("Error in keuangan dashboard: " . $e->getMessage());
    $error_message = "Terjadi kesalahan dalam memuat data dashboard.";
}

// Utility functions
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatPercentage($value, $decimals = 1) {
    return number_format($value, $decimals) . '%';
}

function getGrowthIndicator($current, $previous) {
    if ($previous == 0) return ['class' => 'text-muted', 'icon' => 'minus', 'text' => 'N/A'];
    
    $growth = (($current - $previous) / $previous) * 100;
    
    if ($growth > 0) {
        return [
            'class' => 'text-success',
            'icon' => 'arrow-up',
            'text' => '+' . number_format($growth, 1) . '%'
        ];
    } elseif ($growth < 0) {
        return [
            'class' => 'text-danger',
            'icon' => 'arrow-down',
            'text' => number_format($growth, 1) . '%'
        ];
    } else {
        return [
            'class' => 'text-muted',
            'icon' => 'minus',
            'text' => '0%'
        ];
    }
}

$revenueGrowthIndicator = getGrowthIndicator($currentStats['total_revenue'], $prevStats['total_revenue']);
$bookingGrowthIndicator = getGrowthIndicator($currentStats['total_bookings'], $prevStats['total_bookings']);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Keuangan - STIE MCE</title>
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
            <!-- Sidebar Navigation -->
            <div class="col-md-3">
                <div class="card shadow sidebar-keuangan">
                    <div class="card-body p-3">
                        <h5 class="text-center mb-4 keuangan-title">
                            <i class="fas fa-chart-line me-2"></i>Dashboard Keuangan
                        </h5>
                        <div class="list-group list-group-flush keuangan-nav">
                            <a href="dashboard.php" class="list-group-item list-group-item-action active">
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
                            <i class="fas fa-chart-line me-3"></i>Dashboard Keuangan
                        </h2>
                        <p class="text-muted">Periode: <?= date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)) ?></p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-calendar me-2"></i>Ubah Periode
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

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card keuangan-stat-card revenue-card">
                            <div class="card-body text-center">
                                <div class="keuangan-stat-icon revenue-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <h3 class="keuangan-metric"><?= formatRupiah($totalRevenue) ?></h3>
                                <p class="text-muted mb-1">Total Pendapatan</p>
                                <span class="keuangan-growth <?= $revenueGrowthIndicator['class'] ?>">
                                    <i class="fas fa-<?= $revenueGrowthIndicator['icon'] ?>"></i> 
                                    <?= $revenueGrowthIndicator['text'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card keuangan-stat-card profit-card">
                            <div class="card-body text-center">
                                <div class="keuangan-stat-icon profit-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <h3 class="keuangan-metric"><?= formatRupiah($netProfit) ?></h3>
                                <p class="text-muted mb-1">Profit Bersih</p>
                                <span class="keuangan-growth text-success">
                                    <i class="fas fa-percentage"></i> <?= formatPercentage($profitMargin) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card keuangan-stat-card booking-card">
                            <div class="card-body text-center">
                                <div class="keuangan-stat-icon booking-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <h3 class="keuangan-metric"><?= number_format($currentStats['total_bookings']) ?></h3>
                                <p class="text-muted mb-1">Total Booking</p>
                                <span class="keuangan-growth <?= $bookingGrowthIndicator['class'] ?>">
                                    <i class="fas fa-<?= $bookingGrowthIndicator['icon'] ?>"></i> 
                                    <?= $bookingGrowthIndicator['text'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card keuangan-stat-card occupancy-card">
                            <div class="card-body text-center">
                                <div class="keuangan-stat-icon occupancy-icon">
                                    <i class="fas fa-percentage"></i>
                                </div>
                                <h3 class="keuangan-metric"><?= formatPercentage($avgOccupancy) ?></h3>
                                <p class="text-muted mb-1">Tingkat Okupansi</p>
                                <span class="keuangan-growth text-info">
                                    <i class="fas fa-info-circle"></i> Rata-rata
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <!-- Revenue Trend Chart -->
                    <div class="col-md-8">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-area me-2"></i>Trend Pendapatan (12 Bulan)
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="revenueChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Rooms Chart -->
                    <div class="col-md-4">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-trophy me-2"></i>Top Performing Rooms
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="topRoomsChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions & Summary -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card keuangan-action-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-bolt me-2"></i>Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <a href="monthly-report.php" class="btn btn-keuangan btn-block">
                                            <i class="fas fa-calendar-alt me-2"></i>Laporan Bulanan
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="expense-management.php" class="btn btn-keuangan btn-block">
                                            <i class="fas fa-receipt me-2"></i>Input Pengeluaran
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="addon-management.php" class="btn btn-keuangan btn-block">
                                            <i class="fas fa-plus-circle me-2"></i>Kelola Add-On
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="financial-reports.php" class="btn btn-keuangan btn-block">
                                            <i class="fas fa-file-export me-2"></i>Export Laporan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card keuangan-summary-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Ringkasan Kinerja
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="keuangan-summary-item">
                                    <span class="label">Pendapatan Tertinggi:</span>
                                    <span class="value"><?= !empty($topRooms) ? $topRooms[0]['nama_ruang'] : 'N/A' ?></span>
                                </div>
                                <div class="keuangan-summary-item">
                                    <span class="label">Total Pengeluaran:</span>
                                    <span class="value text-danger"><?= formatRupiah($totalExpenses) ?></span>
                                </div>
                                <div class="keuangan-summary-item">
                                    <span class="label">Margin Keuntungan:</span>
                                    <span class="value <?= $profitMargin > 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= formatPercentage($profitMargin) ?>
                                    </span>
                                </div>
                                <div class="keuangan-summary-item">
                                    <span class="label">Rata-rata Booking/Hari:</span>
                                    <span class="value"><?= round($currentStats['total_bookings'] / 30, 1) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Room Performance Overview -->
                <div class="card keuangan-table-card">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-building me-2"></i>Performa Ruangan - <?= date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)) ?>
                        </h5>
                        <a href="room-analysis.php" class="btn btn-sm btn-outline-light">
                            <i class="fas fa-external-link-alt me-1"></i>Detail
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table keuangan-table">
                                <thead>
                                    <tr>
                                        <th>Ruangan</th>
                                        <th>Booking</th>
                                        <th>Okupansi</th>
                                        <th>Pendapatan</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roomOccupancy as $room): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($room['nama_ruang']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= number_format($room['booking_count']) ?></span>
                                        </td>
                                        <td>
                                            <div class="progress keuangan-progress">
                                                <div class="progress-bar" style="width: <?= min(100, $room['occupancy_rate']) ?>%">
                                                    <?= formatPercentage($room['occupancy_rate']) ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-success">
                                            <strong><?= formatRupiah($room['booking_count'] * 150000) ?></strong>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = $room['occupancy_rate'] > 70 ? 'Excellent' : 
                                                     ($room['occupancy_rate'] > 50 ? 'Good' : 'Needs Improvement');
                                            $statusClass = $room['occupancy_rate'] > 70 ? 'success' : 
                                                          ($room['occupancy_rate'] > 50 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?= $statusClass ?>"><?= $status ?></span>
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
        const monthlyRevenueData = <?= json_encode($monthlyRevenue) ?>;
        const topRoomsData = <?= json_encode($topRooms) ?>;

        // Revenue Trend Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: monthlyRevenueData.map(item => item.month),
                datasets: [{
                    label: 'Pendapatan (Rp)',
                    data: monthlyRevenueData.map(item => item.revenue),
                    borderColor: '#dc143c',
                    backgroundColor: 'rgba(220, 20, 60, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#dc143c',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
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

        // Top Rooms Chart
        const topRoomsCtx = document.getElementById('topRoomsChart').getContext('2d');
        new Chart(topRoomsCtx, {
            type: 'doughnut',
            data: {
                labels: topRoomsData.map(room => room.nama_ruang),
                datasets: [{
                    data: topRoomsData.map(room => room.total_revenue),
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

        // Auto refresh setiap 5 menit
        setInterval(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>