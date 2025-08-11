<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

$message = '';
$alertType = '';

// Handle Excel Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_excel') {
        $result = processExcelScheduleUploadEnhanced($_FILES['excel_file'], $conn);
        
        if ($result['success']) {
            $message = "✅ Berhasil upload {$result['total_uploaded']} jadwal perkuliahan! ";
            $message .= "Generated {$result['total_bookings']} booking otomatis. ";
            if ($result['errors'] > 0) {
                $message .= "⚠️ {$result['errors']} baris diabaikan karena error.";
            }
            $alertType = 'success';
        } else {
            $message = "❌ " . $result['message'];
            $alertType = 'danger';
        }
    }
}

// Handle other form submissions (existing code)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['excel_file'])) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_schedule') {
        $scheduleData = [
            'id_ruang' => intval($_POST['id_ruang']),
            'nama_matakuliah' => trim($_POST['nama_matakuliah']),
            'kelas' => trim($_POST['kelas']),
            'dosen_pengampu' => trim($_POST['dosen_pengampu']),
            'hari' => $_POST['hari'],
            'jam_mulai' => $_POST['jam_mulai'],
            'jam_selesai' => $_POST['jam_selesai'],
            'semester' => trim($_POST['semester']),
            'tahun_akademik' => trim($_POST['tahun_akademik']),
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date'],
            'created_by' => $_SESSION['user_id']
        ];
        
        $result = addRecurringSchedule($conn, $scheduleData);
        
        if ($result['success']) {
            $message = "Jadwal perkuliahan berhasil ditambahkan! Generated {$result['generated_bookings']} booking otomatis.";
            $alertType = 'success';
        } else {
            $message = "Gagal menambahkan jadwal: " . $result['message'];
            $alertType = 'danger';
        }
    }
    
    if ($action === 'delete_schedule') {
    $scheduleId = intval($_POST['schedule_id']);
    
    if ($scheduleId <= 0) {
        $message = "❌ ID jadwal tidak valid";
        $alertType = 'danger';
    } else {
        $result = deleteRecurringSchedule($conn, $scheduleId);
        
        if ($result['success']) {
            $message = "✅ " . $result['message'];
            if (isset($result['removed_bookings']) && $result['removed_bookings'] > 0) {
                $message .= " {$result['removed_bookings']} booking kedepannya juga dihapus.";
            }
            $alertType = 'success';
        } else {
            $message = "❌ " . $result['message'];
            $alertType = 'danger';
        }
    }
}

if ($action === 'edit_schedule') {
    $scheduleId = intval($_POST['schedule_id']);
    $scheduleData = [
        'id_ruang' => intval($_POST['id_ruang']),
        'nama_matakuliah' => trim($_POST['nama_matakuliah']),
        'kelas' => trim($_POST['kelas']),
        'dosen_pengampu' => trim($_POST['dosen_pengampu']),
        'hari' => $_POST['hari'],
        'jam_mulai' => $_POST['jam_mulai'],
        'jam_selesai' => $_POST['jam_selesai'],
        'semester' => trim($_POST['semester']),
        'tahun_akademik' => trim($_POST['tahun_akademik']),
        'start_date' => $_POST['start_date'],
        'end_date' => $_POST['end_date']
    ];
    
    $result = updateRecurringSchedule($conn, $scheduleId, $scheduleData);
    
    if ($result['success']) {
        $message = "Jadwal berhasil diupdate! ";
        $message .= "Dihapus {$result['removed_bookings']} booking lama, ";
        $message .= "dibuat {$result['generated_bookings']} booking baru.";
        $alertType = 'success';
    } else {
        $message = "Gagal mengupdate jadwal: " . $result['message'];
        $alertType = 'danger';
    }
}
}

// Get existing data for display
$stmt = $conn->prepare("
    SELECT rs.*, r.nama_ruang, g.nama_gedung, u.email as created_by_email,
           CASE 
               WHEN rs.kelas REGEXP '^[0-9]+' THEN CONCAT('20', SUBSTRING(rs.kelas, 1, 2))
               ELSE 'Tidak Diketahui'
           END as angkatan
    FROM tbl_recurring_schedules rs
    JOIN tbl_ruang r ON rs.id_ruang = r.id_ruang
    LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
    JOIN tbl_users u ON rs.created_by = u.id_user
    WHERE rs.status = 'active'
    ORDER BY angkatan DESC, rs.hari, rs.jam_mulai
");
$stmt->execute();
$schedules = $stmt->fetchAll();

// Group schedules by angkatan
$schedulesByAngkatan = [];
foreach ($schedules as $schedule) {
    $angkatan = $schedule['angkatan'];
    if (!isset($schedulesByAngkatan[$angkatan])) {
        $schedulesByAngkatan[$angkatan] = [];
    }
    $schedulesByAngkatan[$angkatan][] = $schedule;
}

// Get all rooms for dropdown
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung FROM tbl_ruang r LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

$dayMapping = [
    'monday' => 'Senin',
    'tuesday' => 'Selasa', 
    'wednesday' => 'Rabu',
    'thursday' => 'Kamis',
    'friday' => 'Jumat',
    'saturday' => 'Sabtu',
    'sunday' => 'Minggu'
];

$totalSchedules = count($schedules);
$totalBookingsGenerated = 0;

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_booking WHERE booking_type = 'recurring' AND tanggal >= CURDATE()");
    $stmt->execute();
    $totalBookingsGenerated = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error getting booking count: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Jadwal Perkuliahan - STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .upload-area {
            border: 2px dashed #007bff;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: linear-gradient(135deg, #f8f9fa, #e3f2fd);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: #0056b3;
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        }
        
        .upload-area.dragover {
            border-color: #28a745;
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
        }
        
        .excel-template-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .template-format {
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .schedule-card {
            transition: transform 0.2s ease;
            border: 1px solid #e0e0e0;
        }
        
        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .upload-progress {
            display: none;
            margin-top: 20px;
        }
        
        .file-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            display: none;
        }
    </style>
</head>
<body class="admin-theme">
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar (same as original) -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Menu Admin</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="admin-dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                        <a href="recurring_schedules.php" class="list-group-item list-group-item-action active">
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
                <?php if ($message): ?>
                    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-<?= $alertType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Dashboard (same as original) -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="stats-card" style="background: linear-gradient(135deg, #28a745, #20c997); color: white; border-radius: 10px; padding: 20px;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?= $totalSchedules ?></h3>
                                    <p class="mb-0">Jadwal Perkuliahan Aktif</p>
                                </div>
                                <div>
                                    <i class="fas fa-calendar-week fa-3x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stats-card" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white; border-radius: 10px; padding: 20px;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0"><?= $totalBookingsGenerated ?></h3>
                                    <p class="mb-0">Booking Auto-Generated</p>
                                </div>
                                <div>
                                    <i class="fas fa-robot fa-3x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Excel Upload Section - NEW -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-success text-white">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="mb-0">
                                    <i class="fas fa-keyboard me-2"></i>Input Jadwal Mata Kuliah
                                </h5>
                                <small class="opacity-75">
                                    Ketik jadwal langsung tanpa perlu upload file - Lebih cepat dan fleksibel!
                                </small>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-light btn-sm" onclick="fillSampleData()">
                                    <i class="fas fa-magic me-2"></i>Isi Contoh Data
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
        <!-- Format Instructions -->
        <div class="alert alert-info mb-4">
            <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Format Input Yang Benar:</h6>
            <p class="mb-2">Ketik setiap jadwal dalam 1 baris, pisahkan kolom dengan koma (,). Format:</p>
            <div class="bg-light p-3 rounded mb-3" style="font-family: monospace; font-size: 13px;">
                <strong>Mata Kuliah, Kelas, Dosen, Hari, Jam Mulai, Jam Selesai, Ruangan, Semester, Tahun Akademik, Tanggal Mulai, Tanggal Selesai</strong>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-success">✅ Format Waktu yang BENAR:</h6>
                    <ul class="small mb-0">
                        <li><code>09:00</code> (dengan titik dua)</li>
                        <li><code>11:30</code> (jam:menit)</li>
                        <li><code>14:00</code> (format 24 jam)</li>
                        <li><code>08:30</code> (dengan leading zero)</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6 class="text-danger">❌ Format yang SALAH:</h6>
                    <ul class="small mb-0">
                        <li><code>09.00</code> (titik, bukan titik dua)</li>
                        <li><code>11:30:00</code> (dengan detik)</li>
                        <li><code>9:00</code> (tanpa leading zero)</li>
                        <li><code>2:30 PM</code> (format AM/PM)</li>
                    </ul>
                </div>
            </div>
            <div class="mt-3 p-3 bg-success bg-opacity-10 rounded">
                <h6 class="text-success"><i class="fas fa-check-circle me-1"></i>Contoh Input yang BENAR:</h6>
                <div style="font-family: monospace; font-size: 12px; line-height: 1.4;">
                    <code class="text-dark">Fundamental Accounting 2, B, Bu Dyah, Senin, 09:00, 11:30, K-4, Genap, 2024/2025, 01/07/2025, 30/09/2025</code><br>
                    <code class="text-dark">MACRO ECONOMICS, B, Pak Didik, Rabu, 12:00, 14:30, K-4, Genap, 2024/2025, 01/07/2025, 30/09/2025</code><br>
                    <code class="text-dark">Business Statistic I, B1, Pak Samsul, Kamis, 12:00, 14:30, M1-8, Genap, 2024/2025, 01/07/2025, 30/09/2025</code>
                </div>
            </div>
            
            <div class="mt-3">
                <small class="text-muted">
                    <i class="fas fa-lightbulb text-warning me-1"></i>
                    <strong>Tips:</strong> Gunakan tombol "Isi Contoh Data" untuk melihat format yang benar, lalu edit sesuai kebutuhan Anda.
                </small>
            </div>
        </div>

        <!-- Manual Input Form -->
        <form id="manualScheduleForm">
            <input type="hidden" name="action" value="manual_schedule_input">
            
            <!-- Textarea Input -->
            <div class="mb-4">
                <label class="form-label fw-bold">
                    <i class="fas fa-edit me-2"></i>Input Jadwal (Satu baris per jadwal)
                    <span class="text-danger">*</span>
                </label>
                <textarea 
                    id="scheduleTextInput" 
                    name="schedule_text" 
                    class="form-control" 
                    rows="12" 
                    placeholder="Ketik jadwal di sini... (contoh di bawah)
                    Fundamental Accounting 2, B, Bu Dyah, Senin, 09:00, 11:30, K-4, Genap, 2024/2025, 01/07/2025, 30/09/2025
                    Computer Practicum, B2, Pak Agus, Selasa, 13:00, 14:40, I-1, Genap, 2024/2025, 01/07/2025, 30/09/2025;"
                                        style="font-family: monospace; font-size: 14px; line-height: 1.5;"
                    required></textarea>
                <div class="form-text">
                    <i class="fas fa-lightbulb text-warning me-1"></i>
                    <strong>Tips:</strong> Pakai koma (,) sebagai pemisah. Jangan pakai koma di dalam nama mata kuliah/dosen.
                </div>
            </div>

            <!-- Input Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="input-stats-card" style="background: linear-gradient(135deg, #28a745, #20c997); color: white; border-radius: 8px; padding: 15px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0" id="lineCount">0</h4>
                                <small>Baris Data</small>
                            </div>
                            <i class="fas fa-list fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-stats-card" style="background: linear-gradient(135deg, #007bff, #0056b3); color: white; border-radius: 8px; padding: 15px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0" id="charCount">0</h4>
                                <small>Total Karakter</small>
                            </div>
                            <i class="fas fa-font fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-stats-card" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white; border-radius: 8px; padding: 15px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0" id="estimatedBookings">0</h4>
                                <small>Estimasi Booking</small>
                            </div>
                            <i class="fas fa-calendar-check fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons - Two Step Process -->
            <div class="mb-4">
                <div class="row">
                    <div class="col-md-6">
                        <button type="button" class="btn btn-primary btn-lg w-100" id="parseBtn" onclick="parseAndPreview()">
                            <i class="fas fa-search-plus me-2"></i>
                            <span class="fw-bold">STEP 1:</span> Parse & Preview Data
                        </button>
                        <small class="d-block mt-2 text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Validasi format dan preview hasil sebelum generate
                        </small>
                    </div>
                    <div class="col-md-6">
                        <button type="button" class="btn btn-success btn-lg w-100" id="generateBtn" onclick="generateSchedules()" disabled>
                            <i class="fas fa-cogs me-2"></i>
                            <span class="fw-bold">STEP 2:</span> Generate Jadwal
                        </button>
                        <small class="d-block mt-2 text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Simpan ke database dan buat booking otomatis
                        </small>
                    </div>
                </div>
            </div>
        </form>

        <!-- Preview Section (Hidden initially) -->
        <div id="previewSection" style="display: none;">
            <hr class="my-4">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-eye me-2"></i>Preview Data Yang Akan Diproses
                    </h6>
                </div>
                <div class="card-body">
                    <div id="previewContent">
                        <!-- Preview content will be loaded here -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Section (Hidden initially) -->
        <div id="progressSection" style="display: none;">
            <hr class="my-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-cogs me-2"></i>Progress Generate Jadwal
                    </h6>
                </div>
                <div class="card-body">
                    <div class="progress mb-3">
                        <div id="generateProgress" class="progress-bar progress-bar-striped progress-bar-animated bg-info" 
                             style="width: 0%" role="progressbar"></div>
                    </div>
                    <div id="progressLog">
                        <!-- Progress messages will appear here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

                <!-- Manual Add Schedule (same as original but collapsible) -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <button class="btn btn-link text-white text-decoration-none p-0 w-100 text-start" type="button" data-bs-toggle="collapse" data-bs-target="#manualForm" aria-expanded="false">
                            <h5 class="mb-0">
                                <i class="fas fa-plus me-2"></i>Atau Tambah Jadwal Manual (Satu per Satu)
                                <i class="fas fa-chevron-down float-end mt-1"></i>
                            </h5>
                        </button>
                    </div>
                    <div class="collapse" id="manualForm">
                        <div class="card-body">
                            <form method="POST" id="addScheduleForm">
                                <input type="hidden" name="action" value="add_schedule">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Mata Kuliah <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="nama_matakuliah" required 
                                                   placeholder="contoh: Financial Accounting 2">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Kelas <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="kelas" required 
                                                   placeholder="contoh: B">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Dosen Pengampu <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="dosen_pengampu" required 
                                                   placeholder="Nama dosen">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Ruangan <span class="text-danger">*</span></label>
                                            <select class="form-select" name="id_ruang" required>
                                                <option value="">-- Pilih Ruangan --</option>
                                                <?php foreach ($rooms as $room): ?>
                                                    <option value="<?= $room['id_ruang'] ?>">
                                                        <?= $room['nama_ruang'] ?> (<?= $room['nama_gedung'] ?>) - Kapasitas: <?= $room['kapasitas'] ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Hari <span class="text-danger">*</span></label>
                                            <select class="form-select" name="hari" required>
                                                <option value="">-- Pilih Hari --</option>
                                                <option value="monday">Senin</option>
                                                <option value="tuesday">Selasa</option>
                                                <option value="wednesday">Rabu</option>
                                                <option value="thursday">Kamis</option>
                                                <option value="friday">Jumat</option>
                                                <option value="saturday">Sabtu</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Jam Mulai <span class="text-danger">*</span></label>
                                            <input type="time" class="form-control" name="jam_mulai" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Jam Selesai <span class="text-danger">*</span></label>
                                            <input type="time" class="form-control" name="jam_selesai" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                                            <select class="form-select" name="semester" required>
                                                <option value="">-- Pilih --</option>
                                                <option value="Ganjil">Ganjil</option>
                                                <option value="Genap">Genap</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Tahun Akademik <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="tahun_akademik" required 
                                                   placeholder="contoh: 2024/2025" value="2024/2025">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Tanggal Mulai Perkuliahan <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="start_date" required 
                                                   value="<?= date('Y-m-d') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Tanggal Selesai Perkuliahan <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="end_date" required 
                                                   value="<?= date('Y-m-d', strtotime('+6 months')) ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Catatan:</strong> Sistem akan otomatis membuat booking untuk setiap minggu pada hari yang dipilih, 
                                    kecuali hari libur yang telah didefinisikan di sistem.
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Simpan & Generate Jadwal
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Search and Filter + Existing Schedules (same as original) -->
                <div style="background: linear-gradient(135deg, #f8f9fa, #e9ecef); border-radius: 10px; padding: 20px; margin-bottom: 20px; border: 1px solid #dee2e6;">
                    <div class="row align-items-end">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-search me-2"></i>Cari Jadwal</label>
                            <input type="text" class="form-control" id="searchInput" 
                                   placeholder="Cari mata kuliah, kelas, dosen, atau ruangan...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><i class="fas fa-filter me-2"></i>Filter Angkatan</label>
                            <select class="form-select" id="filterAngkatan">
                                <option value="">Semua Angkatan</option>
                                <?php foreach (array_keys($schedulesByAngkatan) as $angkatan): ?>
                                    <option value="<?= $angkatan ?>"><?= $angkatan ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                                <i class="fas fa-eraser me-2"></i>Reset Filter
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Existing Schedules Display -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Jadwal Perkuliahan Berulang 
                            <span class="badge bg-light text-dark ms-2"><?= count($schedules) ?> Total</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($schedules) > 0): ?>
                            <div class="accordion" id="scheduleAccordion">
                                <?php foreach ($schedulesByAngkatan as $angkatan => $angkatanSchedules): ?>
                                    <div class="accordion-item schedule-group" data-angkatan="<?= $angkatan ?>">
                                        <h2 class="accordion-header" id="heading<?= str_replace(' ', '', $angkatan) ?>">
                                            <button class="accordion-button" style="font-size: 1.1rem; font-weight: 600; padding: 8px 15px; border-radius: 25px; background: linear-gradient(135deg, #007bff, #0056b3); color: white; border: none;" type="button" 
                                                    data-bs-toggle="collapse" 
                                                    data-bs-target="#collapse<?= str_replace(' ', '', $angkatan) ?>" 
                                                    aria-expanded="true" 
                                                    aria-controls="collapse<?= str_replace(' ', '', $angkatan) ?>">
                                                <i class="fas fa-graduation-cap me-3"></i>
                                                Angkatan <?= $angkatan ?>
                                                <span style="background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 12px; font-size: 0.9rem; margin-left: 10px;"><?= count($angkatanSchedules) ?> Jadwal</span>
                                            </button>
                                        </h2>
                                        <div id="collapse<?= str_replace(' ', '', $angkatan) ?>" 
                                             class="accordion-collapse collapse <?= $angkatan === array_keys($schedulesByAngkatan)[0] ? 'show' : '' ?>" 
                                             aria-labelledby="heading<?= str_replace(' ', '', $angkatan) ?>" 
                                             data-bs-parent="#scheduleAccordion">
                                            <div class="accordion-body">
                                                <div class="row">
                                                    <?php foreach ($angkatanSchedules as $schedule): ?>
                                                        <div class="col-md-6 col-lg-4 mb-4 schedule-item" 
                                                             data-search="<?= strtolower($schedule['nama_matakuliah'] . ' ' . $schedule['kelas'] . ' ' . $schedule['dosen_pengampu'] . ' ' . $schedule['nama_ruang']) ?>">
                                                            <!-- Schedule card content here - same as original -->
                                                            <div class="card schedule-card h-100 border-primary">
                                                                <div class="card-header bg-light">
                                                                    <div class="d-flex justify-content-between align-items-start">
                                                                        <div>
                                                                            <h6 class="mb-1 text-primary"><?= htmlspecialchars($schedule['nama_matakuliah']) ?></h6>
                                                                            <small class="text-muted"><?= htmlspecialchars($schedule['kelas']) ?></small>
                                                                        </div>
                                                                        <span class="badge" style="font-size: 0.9rem; padding: 8px 12px; border-radius: 20px; background-color: #17a2b8; color: white;">
                                                                            <?= $dayMapping[$schedule['hari']] ?>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Dosen:</small>
                                                                        <strong><?= htmlspecialchars($schedule['dosen_pengampu']) ?></strong>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Waktu:</small>
                                                                        <span class="badge bg-success">
                                                                            <?= date('H:i', strtotime($schedule['jam_mulai'])) ?> - <?= date('H:i', strtotime($schedule['jam_selesai'])) ?>
                                                                        </span>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Ruangan:</small>
                                                                        <strong><?= htmlspecialchars($schedule['nama_ruang']) ?></strong><br>
                                                                        <small class="text-muted"><?= htmlspecialchars($schedule['nama_gedung']) ?></small>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <small class="text-muted d-block">Periode:</small>
                                                                        <span class="badge bg-warning text-dark">
                                                                            <?= htmlspecialchars($schedule['semester']) ?> <?= htmlspecialchars($schedule['tahun_akademik']) ?>
                                                                        </span><br>
                                                                        <small class="text-muted">
                                                                            <?= date('d/m/Y', strtotime($schedule['start_date'])) ?> - <?= date('d/m/Y', strtotime($schedule['end_date'])) ?>
                                                                        </small>
                                                                    </div>
                                                                    
                                                                    <div>
                                                                        <?php if ($schedule['status'] === 'active'): ?>
                                                                            <span class="badge bg-success">Aktif</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-secondary">Nonaktif</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                                <div class="card-footer bg-light">
                                                                    <div class="btn-group w-100" role="group">
                                                                        <button type="button" class="btn btn-sm btn-danger delete-btn" 
                                                                            onclick="confirmDeleteSchedule(<?= $schedule['id_schedule'] ?>, '<?= addslashes($schedule['nama_matakuliah'] . ' - ' . $schedule['kelas']) ?>', '<?= addslashes($schedule['dosen_pengampu']) ?>', '<?= addslashes($schedule['nama_ruang']) ?>')">
                                                                        <i class="fas fa-trash"></i> Hapus
                                                                    </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div id="noResults" class="d-none" style="text-align: center; padding: 40px; color: #6c757d;">
                                <i class="fas fa-search fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak ada jadwal yang sesuai</h5>
                                <p class="text-muted">Coba gunakan kata kunci yang berbeda atau reset filter.</p>
                            </div>
                            
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Belum ada jadwal perkuliahan berulang</h5>
                                <p class="text-muted">Tambahkan jadwal manual menggunakan form di atas.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

<!-- Modal Konfirmasi Delete -->
 <div class="modal fade confirmation-modal" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus Jadwal
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-skull-crossbones me-2"></i>PERINGATAN KERAS!</h6>
                        <p class="mb-0">Tindakan ini akan <strong>MENGHAPUS SECARA PERMANEN</strong> jadwal dan semua booking terkait.</p>
                    </div>
                    
                    <div class="schedule-info">
                        <h6 class="text-danger mb-3">📚 Detail Jadwal yang Akan Dihapus:</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Mata Kuliah:</strong><br><span id="deleteCourseName" class="text-primary"></span></p>
                                <p><strong>Dosen:</strong><br><span id="deleteLecturer" class="text-info"></span></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Ruangan:</strong><br><span id="deleteRoom" class="text-success"></span></p>
                                <p><strong>Status:</strong><br><span class="badge bg-warning">Akan Dihapus Permanen</span></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="danger-zone">
                        <h6 class="text-danger"><i class="fas fa-bomb me-2"></i>Konsekuensi Penghapusan:</h6>
                        <ul class="text-danger mb-0">
                            <li><strong>Semua booking kedepannya</strong> untuk jadwal ini akan <strong>DIHAPUS</strong></li>
                            <li><strong>Slot waktu akan TERSEDIA</strong> untuk booking user lain</li>
                            <li><strong>Data tidak dapat dipulihkan</strong> setelah dihapus</li>
                            <li><strong>Mahasiswa yang sudah booking</strong> akan kehilangan jadwal mereka</li>
                        </ul>
                    </div>
                    
                    <div class="mt-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirmUnderstand">
                            <label class="form-check-label text-danger" for="confirmUnderstand">
                                <strong>Saya memahami konsekuensi ini dan tetap ingin menghapus jadwal</strong>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Batal
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                        <i class="fas fa-bomb me-2"></i>Ya, Hapus Permanen!
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages Display -->
    <?php if ($message): ?>
        <div class="modal fade" id="resultModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-<?= $alertType ?>">
                        <h5 class="modal-title text-white">
                            <i class="fas fa-<?= $alertType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                            <?= $alertType === 'success' ? 'Berhasil' : 'Error' ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?= $message ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // === REAL-TIME INPUT SCHEDULE ===
    document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('scheduleTextInput');
    const lineCountEl = document.getElementById('lineCount');
    const charCountEl = document.getElementById('charCount');
    const estimatedBookingsEl = document.getElementById('estimatedBookings');
    
    // Real-time input validation dan statistics
    if (textarea) {
        textarea.addEventListener('input', function() {
            updateInputStatistics();
            validateInputFormat();
        });
    }
    
    function updateInputStatistics() {
        const text = textarea.value.trim();
        const lines = text ? text.split('\n').filter(line => {
            const trimmed = line.trim();
            return trimmed.length > 0 && !trimmed.startsWith('#') && !trimmed.startsWith('//');
        }).length : 0;
        
        const chars = text.length;
        const estimatedBookings = lines * 24; // 24 minggu per semester
        
        if (lineCountEl) lineCountEl.textContent = lines;
        if (charCountEl) charCountEl.textContent = chars.toLocaleString();
        if (estimatedBookingsEl) estimatedBookingsEl.textContent = estimatedBookings.toLocaleString();
        
        // Reset buttons when input changes
        const generateBtn = document.getElementById('generateBtn');
        const previewSection = document.getElementById('previewSection');
        
        if (generateBtn) generateBtn.disabled = true;
        if (previewSection) previewSection.style.display = 'none';
    }
    
    function validateInputFormat() {
        const text = textarea.value.trim();
        if (!text) return;
        
        const lines = text.split('\n').filter(line => {
            const trimmed = line.trim();
            return trimmed.length > 0 && !trimmed.startsWith('#') && !trimmed.startsWith('//');
        });
        
        // Basic format validation preview
        let hasErrors = false;
        lines.forEach((line, index) => {
            const fields = parseCSVLine(line);
            if (fields.length < 7) {
                hasErrors = true;
            }
        });
        
        // Update textarea styling
        if (hasErrors && lines.length > 0) {
            textarea.style.borderColor = '#dc3545';
            textarea.style.backgroundColor = '#fff5f5';
        } else {
            textarea.style.borderColor = '#28a745';
            textarea.style.backgroundColor = '#f8fff8';
        }
    }
    
    // Enhanced CSV parsing
    function parseCSVLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            
            if (char === '"') {
                inQuotes = !inQuotes;
            } else if (char === ',' && !inQuotes) {
                result.push(current.trim());
                current = '';
            } else {
                current += char;
            }
        }
        result.push(current.trim());
        return result;
    }
});

// Fill sample data dengan format yang benar
function fillSampleData() {
    const sampleData = `Fundamental Accounting 2, B, Bu Dyah, Senin, 09:00, 11:30, K-4, Genap, 2024/2025, 01/07/2025, 30/09/2025
MACRO ECONOMICS, B, Pak Didik, Rabu, 12:00, 14:30, K-4, Genap, 2024/2025, 01/07/2025, 30/09/2025
Business Statistic I, B1, Pak Samsul, Kamis, 12:00, 14:30, M1-8, Genap, 2024/2025, 01/07/2025, 30/09/2025
Computer Practicum, A, Pak Agus, Selasa, 13:00, 15:30, M-1, Genap, 2024/2025, 01/07/2025, 30/09/2025`;
    
    const textarea = document.getElementById('scheduleTextInput');
    if (textarea) {
        textarea.value = sampleData;
        textarea.dispatchEvent(new Event('input'));
        
        // Scroll to textarea
        textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        // Show success message
        showNotification('✅ Contoh data berhasil diisi!', 'success');
    }
}

// Step 1: Parse and Preview dengan enhanced error handling
function parseAndPreview() {
    const textarea = document.getElementById('scheduleTextInput');
    const text = textarea.value.trim();
    
    if (!text) {
        showNotification('❌ Silakan isi data jadwal terlebih dahulu!', 'error');
        textarea.focus();
        return;
    }
    
    const parseBtn = document.getElementById('parseBtn');
    const originalText = parseBtn.innerHTML;
    
    // Show loading state
    parseBtn.disabled = true;
    parseBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Parsing Data...';
    
    // Validate AJAX file exists
    fetch('ajax/parse_manual_schedule.php', {
        method: 'HEAD'
    }).then(response => {
        if (!response.ok) {
            throw new Error('File AJAX tidak ditemukan. Pastikan path admin/ajax/parse_manual_schedule.php benar.');
        }
        
        // Send actual parsing request
        return fetch('ajax/parse_manual_schedule.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=parse_preview&schedule_text=' + encodeURIComponent(text)
        });
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP Error: ${response.status} ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        parseBtn.disabled = false;
        parseBtn.innerHTML = originalText;
        
        if (data.success) {
            displayPreview(data);
            document.getElementById('generateBtn').disabled = false;
            document.getElementById('previewSection').style.display = 'block';
            
            // Scroll to preview
            document.getElementById('previewSection').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
            
            showNotification('✅ Data berhasil diparsing! Lihat preview di bawah.', 'success');
        } else {
            showNotification('❌ Error parsing data: ' + data.message, 'error');
            document.getElementById('previewSection').style.display = 'none';
        }
    })
    .catch(error => {
        parseBtn.disabled = false;
        parseBtn.innerHTML = originalText;
        
        console.error('Parse Error:', error);
        
        let errorMessage = 'Error sistem: ' + error.message;
        if (error.message.includes('File AJAX tidak ditemukan')) {
            errorMessage += '\n\nSolusi:\n1. Pastikan folder admin/ajax/ ada\n2. Pastikan file parse_manual_schedule.php ada di folder tersebut\n3. Periksa permission folder';
        }
        
        showNotification('❌ ' + errorMessage, 'error');
    });
}

// Enhanced preview display
function displayPreview(data) {
    const previewContent = document.getElementById('previewContent');
    
    let html = `
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="text-center p-3 bg-success text-white rounded">
                    <h4>${data.summary.valid_rows}</h4>
                    <small>Jadwal Valid</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center p-3 bg-warning text-dark rounded">
                    <h4>${data.summary.error_rows}</h4>
                    <small>Error</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center p-3 bg-info text-white rounded">
                    <h4>${data.summary.estimated_bookings}</h4>
                    <small>Est. Booking</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-center p-3 bg-primary text-white rounded">
                    <h4>${data.summary.total_rows}</h4>
                    <small>Total Baris</small>
                </div>
            </div>
        </div>
    `;
    
    // Show errors if any
    if (data.errors && data.errors.length > 0) {
        html += '<div class="alert alert-warning"><h6>⚠️ Error yang ditemukan:</h6>';
        html += '<div class="error-list" style="max-height: 200px; overflow-y: auto;"><ul class="mb-0">';
        data.errors.forEach(error => {
            html += `<li class="mb-1">${error}</li>`;
        });
        html += '</ul></div></div>';
    }
    
    // Show valid data preview
    if (data.valid_data && data.valid_data.length > 0) {
        html += '<h6 class="mt-4">✅ Data Valid (Preview):</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered table-hover">';
        html += '<thead class="table-dark"><tr>';
        html += '<th>No</th><th>Mata Kuliah</th><th>Kelas</th><th>Dosen</th><th>Hari</th><th>Waktu</th><th>Ruangan</th><th>Periode</th>';
        html += '</tr></thead><tbody>';
        
        data.valid_data.slice(0, 10).forEach((row, index) => {
            html += `<tr>
                <td>${index + 1}</td>
                <td><strong>${row.nama_matakuliah}</strong></td>
                <td><span class="badge bg-secondary">${row.kelas}</span></td>
                <td>${row.dosen_pengampu}</td>
                <td><span class="badge bg-info">${getDayInIndonesian(row.hari)}</span></td>
                <td><span class="badge bg-success">${formatTime(row.jam_mulai)} - ${formatTime(row.jam_selesai)}</span></td>
                <td><span class="badge bg-primary">${row.nama_ruang}</span></td>
                <td><small>${row.semester} ${row.tahun_akademik}</small></td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
        
        if (data.valid_data.length > 10) {
            html += `<div class="text-center mt-3">
                <small class="text-muted">... dan ${data.valid_data.length - 10} jadwal lainnya akan diproses</small>
            </div>`;
        }
    }
    
    // Show action buttons
    if (data.summary.valid_rows > 0) {
        html += `
            <div class="alert alert-success mt-4">
                <h6>🎯 Siap untuk Generate!</h6>
                <p class="mb-2">Data valid ditemukan: <strong>${data.summary.valid_rows} jadwal</strong></p>
                <p class="mb-0">Klik tombol "STEP 2: Generate Jadwal" untuk melanjutkan.</p>
            </div>
        `;
    }
    
    previewContent.innerHTML = html;
}

// Helper functions
function getDayInIndonesian(englishDay) {
    const dayMap = {
        'monday': 'Senin',
        'tuesday': 'Selasa',
        'wednesday': 'Rabu',
        'thursday': 'Kamis',
        'friday': 'Jumat',
        'saturday': 'Sabtu',
        'sunday': 'Minggu'
    };
    return dayMap[englishDay] || englishDay;
}

function formatTime(timeString) {
    if (!timeString) return '';
    return timeString.substring(0, 5); // Remove seconds (HH:MM:SS -> HH:MM)
}

function generateSchedules() {
    const textarea = document.getElementById('scheduleTextInput');
    const text = textarea.value.trim();
    
    if (!text) {
        showNotification('❌ Data tidak ditemukan!', 'error');
        return;
    }
    
    // Enhanced confirmation with more details
    if (!confirm('🤖 Akan memulai generate jadwal otomatis.\n\nProses ini akan:\n• Membuat jadwal perkuliahan berulang\n• Generate booking otomatis untuk setiap minggu\n• Mengaktifkan jadwal di sistem\n• Proses bisa memakan waktu beberapa detik\n\nLanjutkan?')) {
        return;
    }
    
    const generateBtn = document.getElementById('generateBtn');
    const originalText = generateBtn.innerHTML;
    
    // Show progress section
    const progressSection = document.getElementById('progressSection');
    const progressBar = document.getElementById('generateProgress');
    const progressLog = document.getElementById('progressLog');
    
    progressSection.style.display = 'block';
    progressSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    
    // Initialize progress
    progressLog.innerHTML = '<div class="alert alert-info"><i class="fas fa-rocket me-2"></i>🚀 Memulai proses generate jadwal...</div>';
    
    // Disable button
    generateBtn.disabled = true;
    generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating Schedules...';
    
    // Start progress animation
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress += Math.random() * 10;
        if (progress > 80) progress = 80;
        progressBar.style.width = progress + '%';
        progressBar.textContent = Math.round(progress) + '%';
    }, 300);
    
    // Enhanced AJAX request with better error handling
    const formData = new FormData();
    formData.append('action', 'generate_schedules');
    formData.append('schedule_text', text);
    
    fetch('ajax/parse_manual_schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        // Check if response is actually JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // If not JSON, get text to see what's actually returned
            return response.text().then(text => {
                console.error('Non-JSON response:', text);
                throw new Error(`Server returned non-JSON response (${response.status}). Response: ${text.substring(0, 200)}...`);
            });
        }
        
        if (!response.ok) {
            throw new Error(`HTTP Error: ${response.status} ${response.statusText}`);
        }
        
        return response.json();
    })
    .then(data => {
        clearInterval(progressInterval);
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';
        
        generateBtn.disabled = false;
        generateBtn.innerHTML = originalText;
        
        console.log('Server response:', data);
        
        if (data.success) {
            progressLog.innerHTML = `
                <div class="alert alert-success">
                    <h6><i class="fas fa-party-horn me-2"></i>🎉 BERHASIL GENERATE JADWAL!</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>📊 Statistik:</strong></p>
                            <ul class="mb-0">
                                <li>✅ Jadwal berhasil: <strong>${data.total_uploaded || 0}</strong></li>
                                <li>🤖 Booking dibuat: <strong>${data.total_bookings || 0}</strong></li>
                                <li>❌ Error: <strong>${data.errors || 0}</strong></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <p><strong>🎯 Status:</strong></p>
                            <p class="text-success mb-0">Semua jadwal sudah aktif dan siap digunakan!</p>
                        </div>
                    </div>
                </div>
            `;
            
            // Show success notification
            showNotification(`🎉 Jadwal berhasil dibuat! ${data.total_uploaded || 0} jadwal telah ditambahkan dengan ${data.total_bookings || 0} booking.`, 'success');
            
            // Ask to refresh page after success
            setTimeout(() => {
                if (confirm(`🎉 SUKSES!\n\nBerhasil membuat ${data.total_uploaded || 0} jadwal perkuliahan!\nDibuat ${data.total_bookings || 0} booking otomatis.\n\nApakah ingin refresh halaman untuk melihat hasil?`)) {
                    window.location.reload();
                }
            }, 2000);
            
        } else {
            progressLog.innerHTML = `
                <div class="alert alert-danger">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>❌ GAGAL GENERATE JADWAL!</h6>
                    <p><strong>Error:</strong> ${data.message || 'Unknown error'}</p>
                    <div class="mt-3">
                        <h6>🔍 Detail Error:</h6>
                        <div class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;">
                            <pre style="font-size: 12px; margin: 0;">${JSON.stringify(data, null, 2)}</pre>
                        </div>
                    </div>
                    <div class="mt-3">
                        <p><strong>💡 Kemungkinan penyebab:</strong></p>
                        <ul>
                            <li>Format data tidak sesuai dengan yang diminta</li>
                            <li>Konflik jadwal dengan data yang sudah ada</li>
                            <li>Ruangan tidak ditemukan dalam database</li>
                            <li>Validasi format waktu atau tanggal gagal</li>
                            <li>Database connection error</li>
                        </ul>
                        <p><small><i class="fas fa-tools me-1"></i>Periksa data input dan coba lagi, atau hubungi administrator.</small></p>
                    </div>
                </div>
            `;
            
            showNotification('❌ Gagal generate jadwal: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        clearInterval(progressInterval);
        generateBtn.disabled = false;
        generateBtn.innerHTML = originalText;
        
        console.error('Generate Error:', error);
        
        progressLog.innerHTML = `
            <div class="alert alert-danger">
                <h6><i class="fas fa-bug me-2"></i>❌ ERROR SISTEM!</h6>
                <p><strong>Error:</strong> ${error.message}</p>
                <div class="mt-3">
                    <h6>🔧 Solusi yang bisa dicoba:</h6>
                    <ol>
                        <li><strong>Periksa file AJAX:</strong> Pastikan file <code>admin/ajax/parse_manual_schedule.php</code> ada dan dapat diakses</li>
                        <li><strong>Periksa database:</strong> Pastikan koneksi database normal</li>
                        <li><strong>Periksa format data:</strong> Gunakan format yang persis sama dengan contoh</li>
                        <li><strong>Clear browser cache:</strong> Refresh halaman dengan Ctrl+F5</li>
                        <li><strong>Cek server logs:</strong> Lihat error log di server untuk detail lebih lanjut</li>
                    </ol>
                </div>
                <div class="mt-3 p-3 bg-warning bg-opacity-25 rounded">
                    <h6><i class="fas fa-info-circle me-1"></i>Debug Info:</h6>
                    <p class="mb-1"><strong>Error Type:</strong> ${error.name || 'Unknown'}</p>
                    <p class="mb-1"><strong>Error Message:</strong> ${error.message}</p>
                    <p class="mb-0"><strong>Stack:</strong> ${error.stack ? error.stack.substring(0, 200) + '...' : 'Not available'}</p>
                </div>
            </div>
        `;
        
        showNotification('❌ Error sistem: ' + error.message, 'error');
    });
}

// Enhanced notification system
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.floating-notification');
    existingNotifications.forEach(notification => notification.remove());
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `floating-notification alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        max-width: 500px;
        min-width: 300px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.25);
        border: none;
        border-radius: 15px;
        backdrop-filter: blur(10px);
        font-weight: 500;
    `;
    
    // Add icon based on type
    let icon = '';
    switch(type) {
        case 'success': icon = '<i class="fas fa-check-circle me-2"></i>'; break;
        case 'error':
        case 'danger': icon = '<i class="fas fa-exclamation-circle me-2"></i>'; break;
        case 'warning': icon = '<i class="fas fa-exclamation-triangle me-2"></i>'; break;
        default: icon = '<i class="fas fa-info-circle me-2"></i>';
    }
    
    notification.innerHTML = `
        ${icon}${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 8 seconds (longer for error messages)
    const timeout = type === 'error' ? 10000 : 6000;
    setTimeout(() => {
        if (notification.parentElement) {
            notification.classList.remove('show');
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 300);
        }
    }, timeout);
}

// Enhanced error handling
window.addEventListener('error', function(e) {
    console.error('JavaScript Error:', e.error);
    
    // Don't show notification for every minor error
    if (e.error && e.error.message && !e.error.message.includes('Script error')) {
        showNotification('⚠️ Terjadi error di sistem: ' + e.error.message, 'error');
    }
});

    // === DELETE SCHEDULE ===
    document.addEventListener('DOMContentLoaded', function() {
        let deleteConfirmModal;
        let currentDeleteId = null;
        
        // Initialize delete modal
        const deleteModalEl = document.getElementById('deleteConfirmModal');
        if (deleteModalEl) {
            deleteConfirmModal = new bootstrap.Modal(deleteModalEl);
            
            // Enable delete button only when checkbox is checked
            const confirmCheckbox = document.getElementById('confirmUnderstand');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            
            if (confirmCheckbox && confirmBtn) {
                confirmCheckbox.addEventListener('change', function() {
                    confirmBtn.disabled = !this.checked;
                    
                    if (this.checked) {
                        confirmBtn.classList.remove('btn-secondary');
                        confirmBtn.classList.add('btn-danger');
                        confirmBtn.innerHTML = '<i class="fas fa-bomb me-2"></i>Ya, Hapus Permanen!';
                    } else {
                        confirmBtn.classList.remove('btn-danger');
                        confirmBtn.classList.add('btn-secondary');
                        confirmBtn.innerHTML = '<i class="fas fa-bomb me-2"></i>Centang konfirmasi dulu';
                    }
                });
                
                // Handle delete confirmation
                confirmBtn.addEventListener('click', function() {
                    if (currentDeleteId && confirmCheckbox.checked) {
                        // Show loading state
                        this.disabled = true;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menghapus...';
                        
                        // Create and submit delete form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.innerHTML = `
                            <input type="hidden" name="action" value="delete_schedule">
                            <input type="hidden" name="schedule_id" value="${currentDeleteId}">
                        `;
                        
                        document.body.appendChild(form);
                        console.log('Submitting delete form for schedule ID:', currentDeleteId);
                        form.submit();
                    } else {
                        alert('Harap centang konfirmasi terlebih dahulu!');
                    }
                });
            }
        }
        
        // Global function for delete confirmation
        window.confirmDeleteSchedule = function(scheduleId, courseName, lecturer, room) {
            currentDeleteId = scheduleId;
            
            // Populate modal with schedule details
            const courseNameEl = document.getElementById('deleteCourseName');
            const lecturerEl = document.getElementById('deleteLecturer');
            const roomEl = document.getElementById('deleteRoom');
            
            if (courseNameEl) courseNameEl.textContent = courseName;
            if (lecturerEl) lecturerEl.textContent = lecturer;
            if (roomEl) roomEl.textContent = room;
            
            // Reset checkbox and button
            const checkbox = document.getElementById('confirmUnderstand');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            
            if (checkbox) checkbox.checked = false;
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.classList.remove('btn-danger');
                confirmBtn.classList.add('btn-secondary');
                confirmBtn.innerHTML = '<i class="fas fa-bomb me-2"></i>Centang konfirmasi dulu';
            }
            
            // Show modal
            if (deleteConfirmModal) {
                deleteConfirmModal.show();
            }
        };
    });

    // === SEARCH AND FILTER ===
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const filterAngkatan = document.getElementById('filterAngkatan');
        
        if (searchInput) {
            searchInput.addEventListener('input', filterSchedules);
        }
        
        if (filterAngkatan) {
            filterAngkatan.addEventListener('change', filterSchedules);
        }
        
        function filterSchedules() {
            const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
            const selectedAngkatan = filterAngkatan ? filterAngkatan.value : '';
            
            const scheduleGroups = document.querySelectorAll('.schedule-group');
            let visibleGroups = 0;
            
            scheduleGroups.forEach(group => {
                const angkatan = group.getAttribute('data-angkatan');
                const scheduleItems = group.querySelectorAll('.schedule-item');
                let visibleItems = 0;
                
                const angkatanMatches = !selectedAngkatan || angkatan === selectedAngkatan;
                
                if (angkatanMatches) {
                    scheduleItems.forEach(item => {
                        const searchData = item.getAttribute('data-search') || '';
                        const matchesSearch = !searchTerm || searchData.includes(searchTerm);
                        
                        if (matchesSearch) {
                            item.style.display = 'block';
                            visibleItems++;
                        } else {
                            item.style.display = 'none';
                        }
                    });
                }
                
                if (angkatanMatches && visibleItems > 0) {
                    group.style.display = 'block';
                    visibleGroups++;
                    
                    // Update count badge
                    const countBadge = group.querySelector('[style*="rgba(255,255,255,0.2)"]');
                    if (countBadge) {
                        countBadge.textContent = visibleItems + ' Jadwal';
                    }
                } else {
                    group.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            const noResults = document.getElementById('noResults');
            if (noResults) {
                if (visibleGroups === 0) {
                    noResults.classList.remove('d-none');
                } else {
                    noResults.classList.add('d-none');
                }
            }
        }
        
        // Global clear filters function
        window.clearFilters = function() {
            if (searchInput) searchInput.value = '';
            if (filterAngkatan) filterAngkatan.value = '';
            filterSchedules();
            
            // Expand all accordions
            const accordionButtons = document.querySelectorAll('.accordion-button');
            accordionButtons.forEach(button => {
                if (button.classList.contains('collapsed')) {
                    button.click();
                }
            });
        };
    });

    // === RESULT MODAL ===
    <?php if ($message): ?>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            const resultModalEl = document.getElementById('resultModal');
            if (resultModalEl) {
                const resultModal = new bootstrap.Modal(resultModalEl);
                resultModal.show();
            }
        }, 500);
    });
    <?php endif; ?>

    // === ERROR HANDLING ===
    window.addEventListener('error', function(e) {
        console.error('JavaScript Error:', e.error);
        // Don't show alerts for every JS error, just log them
    });

    // === PERFORMANCE MONITORING ===
    window.addEventListener('load', function() {
        console.log('✅ Page fully loaded');
        
        // Mark upload system as ready
        if (window.performance && window.performance.mark) {
            window.performance.mark('upload-system-ready');
        }
    });

    console.log('✅ All JavaScript modules loaded successfully');
            // Show result modal if there's a message
            <?php if ($message): ?>
            setTimeout(function() {
                const modal = new bootstrap.Modal(document.getElementById('resultModal'));
                modal.show();
            }, 500);
            <?php endif; ?>
    </script>
</body>
</html>