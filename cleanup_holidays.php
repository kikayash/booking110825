<?php
// File: cleanup_holidays.php
// Jalankan file ini SEKALI SAJA untuk membersihkan jadwal di hari libur

session_start();
require_once 'config.php';
require_once 'functions.php';

// Cek apakah user adalah admin
if (!isLoggedIn() || !isAdmin()) {
    die('Access denied. Admin only.');
}

echo "<h2>Holiday Schedule Cleanup</h2>";
echo "<p>Membersihkan jadwal perkuliahan yang ada di hari libur...</p>";

try {
    $conn->beginTransaction();
    
    // 1. Hapus semua jadwal perkuliahan di hari libur
    $sql = "DELETE b FROM tbl_booking b 
            INNER JOIN tbl_harilibur h ON b.tanggal = h.tanggal 
            WHERE b.booking_type = 'recurring'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $deletedCount = $stmt->rowCount();
    
    echo "<div class='alert alert-success'>";
    echo "<strong>✅ Berhasil!</strong> Menghapus {$deletedCount} jadwal perkuliahan dari hari libur.";
    echo "</div>";
    
    // 2. List hari libur yang ada
    $stmt = $conn->prepare("SELECT tanggal, keterangan FROM tbl_harilibur ORDER BY tanggal");
    $stmt->execute();
    $holidays = $stmt->fetchAll();
    
    if (count($holidays) > 0) {
        echo "<h4>Daftar Hari Libur:</h4>";
        echo "<ul>";
        foreach ($holidays as $holiday) {
            echo "<li>" . date('d F Y', strtotime($holiday['tanggal'])) . " - " . $holiday['keterangan'] . "</li>";
        }
        echo "</ul>";
    }
    
    // 3. Regenerate jadwal perkuliahan (tanpa hari libur)
    echo "<p>Regenerating jadwal perkuliahan...</p>";
    
    $stmt = $conn->prepare("SELECT * FROM tbl_recurring_schedules WHERE status = 'active'");
    $stmt->execute();
    $schedules = $stmt->fetchAll();
    
    $totalRegenerated = 0;
    
    foreach ($schedules as $schedule) {
        $scheduleData = [
            'id_ruang' => $schedule['id_ruang'],
            'nama_matakuliah' => $schedule['nama_matakuliah'],
            'kelas' => $schedule['kelas'],
            'dosen_pengampu' => $schedule['dosen_pengampu'],
            'hari' => $schedule['hari'],
            'jam_mulai' => $schedule['jam_mulai'],
            'jam_selesai' => $schedule['jam_selesai'],
            'semester' => $schedule['semester'],
            'tahun_akademik' => $schedule['tahun_akademik'],
            'start_date' => $schedule['start_date'],
            'end_date' => $schedule['end_date'],
            'created_by' => $schedule['created_by']
        ];
        
        // Regenerate dengan fungsi yang sudah diperbaiki
        $generated = generateBookingsForSchedule($conn, $schedule['id_schedule'], $scheduleData);
        $totalRegenerated += $generated;
        
        echo "<p>📚 {$schedule['nama_matakuliah']} - {$schedule['kelas']}: {$generated} jadwal dibuat</p>";
    }
    
    $conn->commit();
    
    echo "<div class='alert alert-success'>";
    echo "<strong>🎉 Selesai!</strong> Total {$totalRegenerated} jadwal perkuliahan baru dibuat (mengecualikan hari libur).";
    echo "</div>";
    
    echo "<p><a href='index.php' class='btn btn-primary'>Kembali ke Beranda</a></p>";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "<div class='alert alert-danger'>";
    echo "<strong>❌ Error!</strong> " . $e->getMessage();
    echo "</div>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Holiday Cleanup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <!-- Content above will be displayed here -->
    </div>
</body>
</html>