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
    // Room Performance Analysis
    $stmt = $conn->prepare("
        SELECT 
            r.id_ruang,
            r.nama_ruang,
            r.kapasitas,
            g.nama_gedung,
            COUNT(b.id_booking) as total_bookings,
            SUM(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as total_hours,
            AVG(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as avg_duration,
            SUM(b.total_amount) as total_revenue,
            AVG(b.total_amount) as avg_revenue_per_booking,
            (COUNT(b.id_booking) * 100.0 / (30 * 10)) as occupancy_rate,
            (SUM(b.total_amount) / r.kapasitas) as revenue_per_capacity
        FROM tbl_ruang r
        LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
        LEFT JOIN tbl_booking b ON r.id_ruang = b.id_ruang 
            AND MONTH(b.tanggal) = ? 
            AND YEAR(b.tanggal) = ?
            AND b.status IN ('approve', 'active', 'done')
        GROUP BY r.id_ruang, r.nama_ruang, r.kapasitas, g.nama_gedung
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $roomAnalysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Time-based Usage Analysis
    $stmt = $conn->prepare("
        SELECT 
            HOUR(b.jam_mulai) as hour_slot,
            COUNT(b.id_booking) as booking_count,
            SUM(b.total_amount) as hour_revenue,
            AVG(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as avg_duration
        FROM tbl_booking b
        WHERE MONTH(b.tanggal) = ? 
        AND YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
        GROUP BY HOUR(b.jam_mulai)
        ORDER BY hour_slot
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $timeAnalysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Weekly Usage Pattern
    $stmt = $conn->prepare("
        SELECT 
            DAYOFWEEK(b.tanggal) as day_of_week,
            DAYNAME(b.tanggal) as day_name,
            COUNT(b.id_booking) as booking_count,
            SUM(b.total_amount) as day_revenue,
            AVG(b.total_amount) as avg_booking_value
        FROM tbl_booking b
        WHERE MONTH(b.tanggal) = ? 
        AND YEAR(b.tanggal) = ?
        AND b.status IN ('approve', 'active', 'done')
        GROUP BY DAYOFWEEK(b.tanggal), DAYNAME(b.tanggal)
        ORDER BY day_of_week
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $weeklyPattern = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Room Utilization Efficiency
    $stmt = $conn->prepare("
        SELECT 
            r.nama_ruang,
            r.kapasitas,
            COUNT(b.id_booking) as bookings,
            AVG(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) as avg_hours,
            (COUNT(b.id_booking) * AVG(TIMESTAMPDIFF(HOUR, b.jam_mulai, b.jam_selesai)) * r.kapasitas) as utilization_score
        FROM tbl_ruang r
        LEFT JOIN tbl_booking b ON r.id_ruang = b.id_ruang 
            AND MONTH(b.tanggal) = ? 
            AND YEAR(b.tanggal) = ?
            AND b.status IN ('approve', 'active', 'done')
        GROUP BY r.id_ruang, r.nama_ruang, r.kapasitas
        ORDER BY utilization_score DESC
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $utilizationEfficiency = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error in room analysis: " . $e->getMessage());
    $error_message = "Terjadi kesalahan dalam memuat analisis ruangan.";
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function getPerformanceLevel($occupancy) {
    if ($occupancy >= 80) return ['level' => 'Excellent', 'class' => 'success', 'icon' => 'star'];
    if ($occupancy >= 60) return ['level' => 'Good', 'class' => 'primary', 'icon' => 'thumbs-up'];
    if ($occupancy >= 40) return ['level' => 'Average', 'class' => 'warning', 'icon' => 'minus-circle'];
    return ['level' => 'Poor', 'class' => 'danger', 'icon' => 'exclamation-triangle'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisis Ruangan - Dashboard Keuangan</title>
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
                                <i class="fas fa-tachometer-alt me-2"></i>Overview
                            </a>
                            <a href="monthly-report.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-calendar-alt me-2"></i>Laporan Bulanan
                            </a>
                            <a href="room-analysis.php" class="list-group-item list-group-item-action active">
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

                <!-- Header -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h2 class="keuangan-page-title">
                            <i class="fas fa-building me-3"></i>Analisis Ruangan
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

                <!-- Room Performance Overview -->
                <div class="row mb-4">
                    <?php foreach (array_slice($roomAnalysis, 0, 4) as $index => $room): 
                        $performance = getPerformanceLevel($room['occupancy_rate']);
                    ?>
                    <div class="col-md-3 mb-3">
                        <div class="card keuangan-room-card">
                            <div class="card-body text-center">
                                <div class="room-rank-badge">
                                    #<?= $index + 1 ?>
                                </div>
                                <div class="room-icon">
                                    <i class="fas fa-door-open"></i>
                                </div>
                                <h5 class="room-name"><?= htmlspecialchars($room['nama_ruang']) ?></h5>
                                <p class="text-muted small"><?= htmlspecialchars($room['nama_gedung']) ?></p>
                                
                                <div class="room-stats">
                                    <div class="stat-item">
                                        <span class="stat-value"><?= formatRupiah($room['total_revenue']) ?></span>
                                        <span class="stat-label">Revenue</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-value"><?= number_format($room['total_bookings']) ?></span>
                                        <span class="stat-label">Bookings</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-value"><?= number_format($room['occupancy_rate'], 1) ?>%</span>
                                        <span class="stat-label">Okupansi</span>
                                    </div>
                                </div>
                                
                                <span class="badge bg-<?= $performance['class'] ?>">
                                    <i class="fas fa-<?= $performance['icon'] ?> me-1"></i>
                                    <?= $performance['level'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Charts Section -->
                <div class="row mb-4">
                    <!-- Usage by Time -->
                    <div class="col-md-6">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-clock me-2"></i>Penggunaan per Jam
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="timeUsageChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Weekly Pattern -->
                    <div class="col-md-6">
                        <div class="card keuangan-chart-card">
                            <div class="card-header keuangan-card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-calendar-week me-2"></i>Pola Penggunaan Mingguan
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="weeklyPatternChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Room Analysis Table -->
                <div class="card keuangan-table-card mb-4">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-table me-2"></i>Analisis Detail per Ruangan
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table keuangan-table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Ruangan</th>
                                        <th>Gedung</th>
                                        <th>Kapasitas</th>
                                        <th>Booking</th>
                                        <th>Total Jam</th>
                                        <th>Okupansi</th>
                                        <th>Revenue</th>
                                        <th>Revenue/Kapasitas</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roomAnalysis as $index => $room): 
                                        $performance = getPerformanceLevel($room['occupancy_rate']);
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="room-rank">#<?= $index + 1 ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($room['nama_ruang']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($room['nama_gedung']) ?></td>
                                        <td>
                                            <span class="badge bg-info"><?= $room['kapasitas'] ?> orang</span>
                                        </td>
                                        <td><?= number_format($room['total_bookings']) ?></td>
                                        <td><?= number_format($room['total_hours']) ?> jam</td>
                                        <td>
                                            <div class="progress keuangan-progress">
                                                <div class="progress-bar bg-<?= $performance['class'] ?>" 
                                                     style="width: <?= min(100, $room['occupancy_rate']) ?>%">
                                                    <?= number_format($room['occupancy_rate'], 1) ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-success">
                                            <strong><?= formatRupiah($room['total_revenue']) ?></strong>
                                        </td>
                                        <td><?= formatRupiah($room['revenue_per_capacity']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $performance['class'] ?>">
                                                <i class="fas fa-<?= $performance['icon'] ?> me-1"></i>
                                                <?= $performance['level'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recommendations -->
                <div class="card keuangan-recommendation-card">
                    <div class="card-header keuangan-card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-lightbulb me-2"></i>Rekomendasi Optimalisasi
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-success">💡 Ruangan Berprestasi</h6>
                                <?php 
                                $topRoom = $roomAnalysis[0] ?? null;
                                if ($topRoom):
                                ?>
                                <div class="recommendation-item">
                                    <i class="fas fa-trophy text-warning me-2"></i>
                                    <strong><?= htmlspecialchars($topRoom['nama_ruang']) ?></strong> adalah ruangan dengan performa terbaik 
                                    (<?= formatRupiah($topRoom['total_revenue']) ?> revenue, <?= number_format($topRoom['occupancy_rate'], 1) ?>% okupansi).
                                    <br><small class="text-muted">Pertimbangkan untuk menaikkan tarif atau menambah fasilitas premium.</small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-warning">⚠️ Perlu Perhatian</h6>
                                <?php 
                                $lowPerformanceRooms = array_filter($roomAnalysis, function($room) {
                                    return $room['occupancy_rate'] < 40;
                                });
                                if (!empty($lowPerformanceRooms)):
                                    $lowRoom = $lowPerformanceRooms[array_key_last($lowPerformanceRooms)];
                                ?>
                                <div class="recommendation-item">
                                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                                    <strong><?= htmlspecialchars($lowRoom['nama_ruang']) ?></strong> memiliki okupansi rendah 
                                    (<?= number_format($lowRoom['occupancy_rate'], 1) ?>%).
                                    <br><small class="text-muted">Pertimbangkan strategi marketing, perbaikan fasilitas, atau penyesuaian tarif.</small>
                                </div>
                                <?php else: ?>
                                <div class="recommendation-item">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Semua ruangan menunjukkan performa yang baik!
                                </div>
                                <?php endif; ?>
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
        const timeAnalysisData = <?= json_encode($timeAnalysis) ?>;
        const weeklyPatternData = <?= json_encode($weeklyPattern) ?>;

        // Time Usage Chart
        const timeCtx = document.getElementById('timeUsageChart').getContext('2d');
        new Chart(timeCtx, {
            type: 'line',
            data: {
                labels: timeAnalysisData.map(item => item.hour_slot + ':00'),
                datasets: [{
                    label: 'Jumlah Booking',
                    data: timeAnalysisData.map(item => item.booking_count),
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
                        beginAtZero: true
                    }
                }
            }
        });

        // Weekly Pattern Chart
        const weeklyCtx = document.getElementById('weeklyPatternChart').getContext('2d');
        const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: weeklyPatternData.map(item => dayNames[item.day_of_week - 1]),
                datasets: [{
                    label: 'Revenue per Hari',
                    data: weeklyPatternData.map(item => item.day_revenue),
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
    </script>
</body>
</html>