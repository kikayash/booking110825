<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is CS
if (!isLoggedIn() || !isCS()) {
    header('Location: ../index.php');
    exit;
}

// Get available rooms
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung FROM tbl_ruang r LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

// Define add-on facilities with prices
$addonFacilities = [
    'sound_system' => ['name' => 'Sound System', 'price' => 150000, 'icon' => 'fas fa-volume-up'],
    'projector_screen' => ['name' => 'Projector + Screen', 'price' => 100000, 'icon' => 'fas fa-desktop'],
    'catering_snack' => ['name' => 'Catering Snack', 'price' => 25000, 'icon' => 'fas fa-cookie-bite', 'unit' => 'per orang'],
    'catering_lunch' => ['name' => 'Catering Lunch', 'price' => 50000, 'icon' => 'fas fa-utensils', 'unit' => 'per orang'],
    'decoration' => ['name' => 'Dekorasi Ruangan', 'price' => 300000, 'icon' => 'fas fa-gifts'],
    'photography' => ['name' => 'Dokumentasi Foto', 'price' => 500000, 'icon' => 'fas fa-camera'],
    'security' => ['name' => 'Keamanan Tambahan', 'price' => 75000, 'icon' => 'fas fa-shield-alt', 'unit' => 'per jam'],
    'cleaning_service' => ['name' => 'Cleaning Service', 'price' => 100000, 'icon' => 'fas fa-broom'],
    'wifi_upgrade' => ['name' => 'WiFi Premium', 'price' => 200000, 'icon' => 'fas fa-wifi'],
    'parking_valet' => ['name' => 'Valet Parking', 'price' => 300000, 'icon' => 'fas fa-car']
];

// Handle form submission
$message = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ✅ PERBAIKAN 3: Validasi input manual yang baru
        $required_basic = ['email_peminjam', 'role_peminjam', 'id_ruang', 'nama_acara', 'tanggal', 'jam_mulai', 'jam_selesai', 'keterangan'];
        
        foreach ($required_basic as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field " . ucfirst(str_replace('_', ' ', $field)) . " harus diisi.");
            }
        }
        
        // ✅ PERBAIKAN 3: Ambil data dari input manual
        $email_peminjam = filter_var(trim($_POST['email_peminjam']), FILTER_VALIDATE_EMAIL);
        if (!$email_peminjam) {
            throw new Exception("Format email tidak valid.");
        }
        
        $role_peminjam = $_POST['role_peminjam'];
        if (!in_array($role_peminjam, ['dosen', 'mahasiswa', 'karyawan', 'external'])) {
            throw new Exception("Role peminjam tidak valid.");
        }
        
        $id_ruang = $_POST['id_ruang'];
        $nama_acara = trim($_POST['nama_acara']);
        $tanggal = $_POST['tanggal'];
        $jam_mulai = $_POST['jam_mulai'];
        $jam_selesai = $_POST['jam_selesai'];
        $keterangan = trim($_POST['keterangan']);
        $is_external = isset($_POST['is_external']) ? 1 : 0;
        
        // ✅ PERBAIKAN 2: PIC handling - berbeda untuk dosen vs non-dosen
        $isDosen = ($role_peminjam === 'dosen');
        
        if ($isDosen) {
            // ✅ PERBAIKAN 2: Untuk dosen - PIC opsional, bisa kosong
            $nama_penanggungjawab = !empty($_POST['nama_penanggungjawab']) ? 
                                  trim($_POST['nama_penanggungjawab']) : 
                                  $email_peminjam; // Default ke email jika kosong
            
            $no_penanggungjawab = !empty($_POST['no_penanggungjawab']) ? 
                                preg_replace('/[^0-9]/', '', $_POST['no_penanggungjawab']) : 
                                '0'; // Default ke 0 jika kosong
        } else {
            // ✅ PERBAIKAN 2: Untuk non-dosen - PIC WAJIB diisi
            if (empty($_POST['nama_penanggungjawab']) || empty($_POST['no_penanggungjawab'])) {
                throw new Exception("Nama dan nomor HP penanggungjawab WAJIB diisi untuk booking " . $role_peminjam . ".");
            }
            
            $nama_penanggungjawab = trim($_POST['nama_penanggungjawab']);
            $no_penanggungjawab = preg_replace('/[^0-9]/', '', $_POST['no_penanggungjawab']);
            
            // Validasi nomor telepon untuk non-dosen
            if (strlen($no_penanggungjawab) < 10 || strlen($no_penanggungjawab) > 15) {
                throw new Exception("Nomor telepon harus antara 10-15 digit.");
            }
        }
        
        // Validasi waktu dan tanggal
        if ($jam_mulai >= $jam_selesai) {
            throw new Exception("Jam selesai harus setelah jam mulai.");
        }
        
        // Validasi tanggal tidak boleh masa lalu
        if ($tanggal < date('Y-m-d')) {
            throw new Exception("Tanggal booking tidak boleh di masa lalu.");
        }
        
        // Validasi hari tidak boleh Minggu
        $selectedDate = new DateTime($tanggal);
        if ($selectedDate->format('w') == 0) {
            throw new Exception("Booking tidak diperbolehkan pada hari Minggu.");
        }
        
        // Validasi jam kerja (7-21)
        $startHour = (int)date('H', strtotime($jam_mulai));
        $endHour = (int)date('H', strtotime($jam_selesai));
        
        if ($startHour < 7 || $endHour > 21) {
            throw new Exception("Jam booking harus antara 07:00 - 21:00.");
        }
        
        // Cek konflik jadwal
        $conflictQuery = "SELECT COUNT(*) as conflict_count FROM tbl_booking 
                         WHERE id_ruang = ? AND tanggal = ? 
                         AND status NOT IN ('cancelled', 'rejected', 'done')
                         AND NOT (jam_selesai <= ? OR jam_mulai >= ?)";
        
        $stmt = $conn->prepare($conflictQuery);
        $stmt->execute([$id_ruang, $tanggal, $jam_mulai, $jam_selesai]);
        $conflictResult = $stmt->fetch();
        
        if ($conflictResult['conflict_count'] > 0) {
            throw new Exception("Ruangan sudah terpakai pada waktu yang dipilih. Silakan pilih waktu lain.");
        }
        
        $conn->beginTransaction();
        
        // ✅ PERBAIKAN 3: Insert dengan sistem baru - tidak menggunakan id_user
        // Cek apakah user sudah ada di database
        $existingUserQuery = "SELECT id_user FROM tbl_users WHERE email = ? LIMIT 1";
        $stmt = $conn->prepare($existingUserQuery);
        $stmt->execute([$email_peminjam]);
        $existingUser = $stmt->fetch();
        
        if ($existingUser) {
            // User sudah ada - gunakan id_user yang ada
            $id_user = $existingUser['id_user'];
            $user_type = 'local';
        } else {
            // User belum ada - set id_user = NULL dan simpan data manual
            $id_user = null;
            $user_type = ($role_peminjam === 'dosen') ? 'dosen_iris' : (($is_external || $role_peminjam === 'external') ? 'external' : 'local');
        }
        
        // ✅ ENHANCED: Insert booking dengan data manual
        $stmt = $conn->prepare("INSERT INTO tbl_booking 
                               (id_user, id_ruang, nama_acara, tanggal, jam_mulai, jam_selesai, keterangan, 
                                nama_penanggungjawab, no_penanggungjawab, status, is_external, 
                                created_by_cs, cs_user_id, booking_type, created_at,
                                user_type, email_peminjam, role_peminjam, auto_approved, approved_at, approved_by, auto_approval_reason) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)");
        
        $bookingType = $is_external ? 'external' : 'manual';
        
        // ✅ PERBAIKAN 2: Auto-approve untuk dosen
        if ($isDosen) {
            $status = 'approve';
            $auto_approved = 1;
            $approved_at = date('Y-m-d H:i:s');
            $approved_by = 'SYSTEM_AUTO';
            $auto_approval_reason = 'Auto-approved CS booking for dosen';
        } else {
            $status = 'pending';
            $auto_approved = 0;
            $approved_at = null;
            $approved_by = null;
            $auto_approval_reason = null;
        }
        
        $stmt->execute([
            $id_user, $id_ruang, $nama_acara, $tanggal, $jam_mulai, $jam_selesai, $keterangan, 
            $nama_penanggungjawab, $no_penanggungjawab, $status, $is_external,
            $_SESSION['user_id'], $bookingType, $user_type, $email_peminjam, $role_peminjam,
            $auto_approved, $approved_at, $approved_by, $auto_approval_reason
        ]);
        
        $bookingId = $conn->lastInsertId();
        
        // Handle add-ons jika ada
        $totalAddonCost = 0;
        if ($is_external && isset($_POST['selected_addons']) && is_array($_POST['selected_addons'])) {
            foreach ($_POST['selected_addons'] as $addonKey) {
                if (isset($addonFacilities[$addonKey])) {
                    $addon = $addonFacilities[$addonKey];
                    $quantity = isset($_POST['addon_quantities'][$addonKey]) ? (int)$_POST['addon_quantities'][$addonKey] : 1;
                    $quantity = max(1, min(100, $quantity)); // Limit 1-100
                    
                    $unitPrice = $addon['price'];
                    $totalPrice = $unitPrice * $quantity;
                    $totalAddonCost += $totalPrice;
                    
                    // Insert addon detail
                    $stmt = $conn->prepare("INSERT INTO tbl_booking_addons 
                                           (id_booking, addon_key, addon_name, quantity, unit_price, total_price, unit_type) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $bookingId, $addonKey, $addon['name'], $quantity, $unitPrice, $totalPrice, 
                        $addon['unit'] ?? 'unit'
                    ]);
                }
            }
            
            // Update total addon cost
            if ($totalAddonCost > 0) {
                $stmt = $conn->prepare("UPDATE tbl_booking SET addon_total = ? WHERE id_booking = ?");
                $stmt->execute([$totalAddonCost, $bookingId]);
            }
        }
        
        // Log CS action
        $action_desc = $isDosen ? 
            "Manual dosen booking auto-approved: $nama_acara (Email: $email_peminjam)" : 
            "Manual booking created for $role_peminjam: $nama_acara (Email: $email_peminjam)";
            
        $stmt = $conn->prepare("INSERT INTO tbl_cs_actions (cs_user_id, action_type, target_id, description) 
                               VALUES (?, 'create_manual_booking', ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $bookingId, $action_desc]);
        
        $conn->commit();
        
        // ✅ ENHANCED: Success message dengan informasi lebih detail
        if ($isDosen) {
            $message = "✅ BOOKING DOSEN BERHASIL & LANGSUNG DISETUJUI! 🎉\n\n" .
                      "📋 ID Booking: #$bookingId\n" .
                      "👨‍🏫 Email: $email_peminjam\n" .
                      "📞 PIC: $nama_penanggungjawab\n" .
                      "✅ Status: AUTO-APPROVED - Siap digunakan!\n\n" .
                      ($totalAddonCost > 0 ? "💰 Total Add-on: Rp " . number_format($totalAddonCost, 0, ',', '.') : "");
        } else {
            $message = "✅ BOOKING BERHASIL DITAMBAHKAN! 📝\n\n" .
                      "📋 ID Booking: #$bookingId\n" .
                      "👤 Email: $email_peminjam ($role_peminjam)\n" .
                      "📞 PIC: $nama_penanggungjawab ($no_penanggungjawab)\n" .
                      "⏳ Status: PENDING - Menunggu persetujuan admin\n\n" .
                      ($totalAddonCost > 0 ? "💰 Total Add-on: Rp " . number_format($totalAddonCost, 0, ',', '.') . "\n\n" : "") .
                      "📧 Notifikasi telah dikirim ke admin untuk persetujuan.";
        }
        
        $alertType = 'success';
        
        // Clear form data
        $_POST = [];
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        $message = "❌ " . $e->getMessage();
        $alertType = 'danger';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Booking Manual - CS STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        /* Existing styles */
        .addon-card { 
            border: 2px dashed #ddd; 
            border-radius: 10px; 
            padding: 15px; 
            margin: 10px 0; 
            cursor: pointer; 
            transition: all 0.3s;
        }
        .addon-card:hover { 
            border-color: #007bff; 
            background: #f8f9fa; 
        }
        .addon-card.selected { 
            border-color: #28a745; 
            background: #d4edda; 
            border-style: solid; 
        }
        .addon-price { 
            font-size: 1.2rem; 
            color: #dc3545; 
            font-weight: bold; 
        }
        /* ✅ ENHANCED: Tambahan CSS untuk animasi add-on yang lebih smooth */
        .total-display {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin: 15px 0;
            transition: all 0.3s ease;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .addon-card {
            border: 2px dashed #ddd; 
            border-radius: 10px; 
            padding: 15px; 
            margin: 10px 0; 
            cursor: pointer; 
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .addon-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }
        
        .addon-card:hover::before {
            transform: translateX(100%);
        }
        
        .addon-card:hover { 
            border-color: #007bff; 
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,123,255,0.2);
        }
        
        .addon-card.selected { 
            border-color: #28a745; 
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border-style: solid;
            box-shadow: 0 4px 15px rgba(40,167,69,0.3);
        }
        
        .addon-card.selected::after {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 15px;
            background: #28a745;
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .addon-price { 
            font-size: 1.2rem; 
            color: #dc3545; 
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .addon-card.selected .addon-price {
            color: #28a745;
            transform: scale(1.1);
        }
        
        /* ✅ Enhanced checkbox styling */
        .form-check-input:checked {
            background-color: #28a745;
            border-color: #28a745;
        }
        
        .form-check-input:focus {
            border-color: #86e5a3;
            box-shadow: 0 0 0 0.25rem rgba(40, 167, 69, 0.25);
        }
        
        /* ✅ Smooth transitions untuk semua elemen */
        .card, .alert, .btn {
            transition: all 0.3s ease;
        }
        
        /* ✅ Loading state untuk button */
        .btn.loading {
            position: relative;
            color: transparent;
        }
        
        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .list-group-item i {
            display: inline-block !important;
            width: 20px;
            text-align: center;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .sidebar-cs .list-group-item {
            padding: 12px 20px;
            border: none;
            color: #333;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .sidebar-cs .list-group-item:hover {
            background-color: #f8f9fa;
            color: #e91e63;
            padding-left: 25px;
        }
        
        .sidebar-cs .list-group-item.active {
            background-color: #e91e63;
            color: white;
            border-left: 4px solid #ad1457;
        }
        
        /* ✅ ENHANCED: New styles for improved form */
        .role-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        
        .role-option {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .role-option:hover {
            border-color: #007bff;
            background: #f8f9fa;
        }
        
        .role-option.selected {
            border-color: #28a745;
            background: #d4edda;
        }
        
        .role-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }
        
        .pic-conditional {
            transition: all 0.3s ease;
        }
        
        .pic-required {
            border-left: 4px solid #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }
        
        .pic-optional {
            border-left: 4px solid #28a745;
            background: rgba(40, 167, 69, 0.1);
        }
    </style>
</head>
<body class="cs-theme">
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- CS Sidebar -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow dashboard-sidebar sidebar-cs">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-headset me-2"></i>Menu CS</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard CS
                        </a>
                        <a href="add-booking.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="today_rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-day me-2"></i> Ruangan Hari Ini
                        </a>
                        <a href="schedule_management.php" class="list-group-item list-group-item-action position-relative">
                            <i class="fa-solid fa-calendar-days"></i>Kelola Jadwal Dosen
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>Tambah Booking Manual - Customer Service (Enhanced)
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <?= nl2br($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- ✅ ENHANCED: Informasi CS yang diperbaharui -->
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>Informasi CS - Sistem Baru</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><strong>📧 Email Manual:</strong> Ketik langsung email peminjam (tidak perlu terdaftar)</li>
                                        <li><strong>👨‍🏫 Dosen:</strong> PIC opsional, langsung auto-approved</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><strong>👥 Mahasiswa/Lainnya:</strong> PIC wajib diisi, perlu approval</li>
                                        <li><strong>🎁 Add-on:</strong> Tersedia untuk acara eksternal</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST" id="bookingForm">
                            <div class="row">
                                <!-- ✅ PERBAIKAN 3: Basic Information dengan sistem manual -->
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0">📋 Informasi Peminjam (Manual Input)</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Email Peminjam *</label>
                                                <input type="email" class="form-control" name="email_peminjam" 
                                                       value="<?= htmlspecialchars($_POST['email_peminjam'] ?? '') ?>" 
                                                       placeholder="contoh@stie-mce.ac.id atau email eksternal" required>
                                                <small class="text-muted">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    Ketik langsung email peminjam
                                                </small>
                                            </div>
                                            
                                            <!-- ✅ PERBAIKAN 3: Role selector yang lebih intuitif -->
                                            <div class="mb-3">
                                                <label class="form-label">Role/Tipe Peminjam *</label>
                                                <div class="role-selector">
                                                    <div class="role-option" data-role="dosen" onclick="selectRole('dosen')">
                                                        <i class="fas fa-chalkboard-teacher role-icon text-success"></i>
                                                        <h6>Dosen</h6>
                                                        <small>PIC Opsional<br>Auto-Approved</small>
                                                    </div>
                                                    <div class="role-option" data-role="mahasiswa" onclick="selectRole('mahasiswa')">
                                                        <i class="fas fa-user-graduate role-icon text-primary"></i>
                                                        <h6>Mahasiswa</h6>
                                                        <small>PIC Wajib<br>Perlu Approval</small>
                                                    </div>
                                                    <div class="role-option" data-role="karyawan" onclick="selectRole('karyawan')">
                                                        <i class="fas fa-user-tie role-icon text-info"></i>
                                                        <h6>Karyawan</h6>
                                                        <small>PIC Wajib<br>Perlu Approval</small>
                                                    </div>
                                                    <div class="role-option" data-role="external" onclick="selectRole('external')">
                                                        <i class="fas fa-building role-icon text-warning"></i>
                                                        <h6>Eksternal</h6>
                                                        <small>PIC Wajib<br>Add-on Tersedia</small>
                                                    </div>
                                                </div>
                                                <input type="hidden" name="role_peminjam" id="role_peminjam" required>
                                                <div id="role_info" class="mt-2"></div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Ruangan *</label>
                                                <select class="form-select" name="id_ruang" required>
                                                    <option value="">-- Pilih Ruangan --</option>
                                                    <?php foreach ($rooms as $room): ?>
                                                        <option value="<?= $room['id_ruang'] ?>" <?= (isset($_POST['id_ruang']) && $_POST['id_ruang'] == $room['id_ruang']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($room['nama_ruang']) ?> - <?= htmlspecialchars($room['nama_gedung']) ?> (<?= $room['kapasitas'] ?> orang)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Nama Acara *</label>
                                                <input type="text" class="form-control" name="nama_acara" 
                                                       value="<?= htmlspecialchars($_POST['nama_acara'] ?? '') ?>" 
                                                       placeholder="Contoh: Seminar Bisnis Digital, Rapat Himpunan" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Tanggal *</label>
                                                <input type="date" class="form-control" name="tanggal" 
                                                       value="<?= htmlspecialchars($_POST['tanggal'] ?? '') ?>"
                                                       min="<?= date('Y-m-d') ?>" 
                                                       max="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label class="form-label">Jam Mulai *</label>
                                                    <input type="time" class="form-control" name="jam_mulai" 
                                                           value="<?= htmlspecialchars($_POST['jam_mulai'] ?? '') ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Jam Selesai *</label>
                                                    <input type="time" class="form-control" name="jam_selesai" 
                                                           value="<?= htmlspecialchars($_POST['jam_selesai'] ?? '') ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- ✅ PERBAIKAN 2: Contact Information dengan kondisi PIC -->
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0">📞 Informasi Kontak & PIC</h5>
                                        </div>
                                        <div class="card-body pic-conditional" id="picSection">
                                            <!-- Dynamic PIC note -->
                                            <div id="picNote"></div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    Nama Penanggungjawab/PIC 
                                                    <span class="text-danger" id="picRequired">*</span>
                                                    <span class="text-success" id="picOptional" style="display:none;">(Opsional untuk dosen)</span>
                                                </label>
                                                <input type="text" class="form-control" name="nama_penanggungjawab" 
                                                       value="<?= htmlspecialchars($_POST['nama_penanggungjawab'] ?? '') ?>" 
                                                       placeholder="Nama lengkap penanggungjawab"
                                                       id="nama_penanggungjawab">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">
                                                    No. HP Penanggungjawab 
                                                    <span class="text-danger" id="phoneRequired">*</span>
                                                    <span class="text-success" id="phoneOptional" style="display:none;">(Opsional untuk dosen)</span>
                                                </label>
                                                <input type="tel" class="form-control" name="no_penanggungjawab" 
                                                       value="<?= htmlspecialchars($_POST['no_penanggungjawab'] ?? '') ?>"
                                                       placeholder="08xxxxxxxxxx"
                                                       id="no_penanggungjawab">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Keterangan Acara *</label>
                                                <textarea class="form-control" name="keterangan" rows="4" 
                                                          placeholder="Detail acara, jumlah peserta, kebutuhan khusus, dll" required><?= htmlspecialchars($_POST['keterangan'] ?? '') ?></textarea>
                                            </div>
                                            
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="is_external" value="1" 
                                                       id="is_external" onchange="toggleAddons()"
                                                       <?= (isset($_POST['is_external']) && $_POST['is_external']) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="is_external">
                                                    <strong>Acara Eksternal/Non-Akademik</strong>
                                                    <br><small class="text-muted">Centang untuk menambah fasilitas add-on berbayar</small>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Add-on Section (unchanged) -->
                            <div class="card mb-4" id="addonSection" style="display: none;">
                                <div class="card-header bg-warning text-dark">
                                    <h5 class="mb-0">
                                        <i class="fas fa-plus-square me-2"></i>Add-on Fasilitas Premium
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($addonFacilities as $key => $addon): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="addon-card" onclick="toggleAddon('<?= $key ?>')">
                                                    <input type="checkbox" name="selected_addons[]" value="<?= $key ?>" 
                                                           id="addon_<?= $key ?>" style="display: none;">
                                                    
                                                    <div class="text-center">
                                                        <i class="<?= $addon['icon'] ?> fa-2x text-primary mb-2"></i>
                                                        <h6 class="fw-bold"><?= $addon['name'] ?></h6>
                                                        <div class="addon-price">
                                                            Rp <?= number_format($addon['price'], 0, ',', '.') ?>
                                                            <?php if (isset($addon['unit'])): ?>
                                                                <br><small>/ <?= $addon['unit'] ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <div class="mt-2" id="quantity_<?= $key ?>" style="display: none;">
                                                            <label class="form-label small">Jumlah:</label>
                                                            <input type="number" class="form-control form-control-sm" 
                                                                   name="addon_quantities[<?= $key ?>]" 
                                                                   value="1" min="1" max="100" onchange="calculateTotal()">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="total-display">
                                        <h5 class="mb-1">Total Biaya Add-on</h5>
                                        <h3 class="mb-0">Rp <span id="totalAmount">0</span></h3>
                                        <small>Belum termasuk biaya sewa ruangan dasar</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Kembali
                                </a>
                                
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-paper-plane me-2"></i>
                                    <span id="submitText">Buat Booking</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ✅ PERBAIKAN 3: Role selection function
        function selectRole(role) {
            // Reset all role options
            document.querySelectorAll('.role-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Select the clicked role
            const selectedOption = document.querySelector(`[data-role="${role}"]`);
            selectedOption.classList.add('selected');
            
            // Set hidden input value
            document.getElementById('role_peminjam').value = role;
            
            // Update PIC requirements based on role
            updatePicRequirements(role);
            
            // Update submit button text
            updateSubmitButton(role);
        }
        
        // ✅ PERBAIKAN 2: Update PIC requirements based on role
        function updatePicRequirements(role) {
            const picSection = document.getElementById('picSection');
            const picNote = document.getElementById('picNote');
            const picRequired = document.getElementById('picRequired');
            const picOptional = document.getElementById('picOptional');
            const phoneRequired = document.getElementById('phoneRequired');
            const phoneOptional = document.getElementById('phoneOptional');
            const namaInput = document.getElementById('nama_penanggungjawab');
            const phoneInput = document.getElementById('no_penanggungjawab');
            const roleInfo = document.getElementById('role_info');
            
            if (role === 'dosen') {
                // ✅ PERBAIKAN 2: Dosen - PIC opsional
                picSection.classList.remove('pic-required');
                picSection.classList.add('pic-optional');
                
                picRequired.style.display = 'none';
                picOptional.style.display = 'inline';
                phoneRequired.style.display = 'none';
                phoneOptional.style.display = 'inline';
                
                namaInput.required = false;
                phoneInput.required = false;
                
                namaInput.placeholder = 'Akan otomatis terisi jika dikosongkan';
                phoneInput.placeholder = 'Opsional untuk dosen';
                
                picNote.innerHTML = `
                    <div class="alert alert-success">
                        <i class="fas fa-graduation-cap me-2"></i>
                        <strong>Mode Dosen:</strong> PIC opsional, akan auto-approved langsung!
                    </div>
                `;
                
                roleInfo.innerHTML = `
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Dosen:</strong> Booking langsung disetujui, PIC opsional
                    </div>
                `;
            } else {
                // ✅ PERBAIKAN 2: Non-dosen - PIC wajib
                picSection.classList.remove('pic-optional');
                picSection.classList.add('pic-required');
                
                picRequired.style.display = 'inline';
                picOptional.style.display = 'none';
                phoneRequired.style.display = 'inline';
                phoneOptional.style.display = 'none';
                
                namaInput.required = true;
                phoneInput.required = true;
                
                namaInput.placeholder = 'Nama lengkap penanggungjawab (WAJIB)';
                phoneInput.placeholder = '08xxxxxxxxxx (WAJIB)';
                
                const roleText = role.charAt(0).toUpperCase() + role.slice(1);
                picNote.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-user-tie me-2"></i>
                        <strong>Mode ${roleText}:</strong> Data PIC WAJIB diisi lengkap!
                    </div>
                `;
                
                roleInfo.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-clock me-2"></i>
                        <strong>${roleText}:</strong> Perlu persetujuan admin, PIC wajib diisi
                    </div>
                `;
            }
            
            // Show external checkbox for external role
            const externalCheckbox = document.getElementById('is_external');
            if (role === 'external') {
                externalCheckbox.checked = true;
                toggleAddons();
            }
        }
        
        // ✅ ENHANCED: Update submit button based on role
        function updateSubmitButton(role) {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            
            if (role === 'dosen') {
                submitBtn.className = 'btn btn-success btn-lg';
                submitText.textContent = 'Buat & Auto-Approve untuk Dosen';
            } else {
                submitBtn.className = 'btn btn-primary btn-lg';
                submitText.textContent = 'Buat Booking (Perlu Approval)';
            }
        }
        
        // Form validation
        function validateBookingForm() {
            const role = document.getElementById('role_peminjam').value;
            
            if (!role) {
                alert('⚠️ Silakan pilih role/tipe peminjam terlebih dahulu');
                return false;
            }
            
            // ✅ PERBAIKAN 2: Validasi PIC berdasarkan role
            if (role !== 'dosen') {
                const namaInput = document.getElementById('nama_penanggungjawab');
                const phoneInput = document.getElementById('no_penanggungjawab');
                
                if (!namaInput.value.trim()) {
                    alert('⚠️ Nama penanggungjawab harus diisi untuk role ' + role);
                    namaInput.focus();
                    return false;
                }
                
                if (!phoneInput.value.trim()) {
                    alert('⚠️ Nomor HP penanggungjawab harus diisi untuk role ' + role);
                    phoneInput.focus();
                    return false;
                }
                
                const phone = phoneInput.value.replace(/[^0-9]/g, '');
                if (phone.length < 10 || phone.length > 15) {
                    alert('⚠️ Nomor HP harus 10-15 digit');
                    phoneInput.focus();
                    return false;
                }
            }
            
            return validateTimeAndDate();
        }
        
        function validateTimeAndDate() {
            const tanggal = document.querySelector('input[name="tanggal"]').value;
            const jamMulai = document.querySelector('input[name="jam_mulai"]').value;
            const jamSelesai = document.querySelector('input[name="jam_selesai"]').value;
            
            if (jamMulai >= jamSelesai) {
                alert('⚠️ Jam selesai harus setelah jam mulai');
                return false;
            }
            
            const selectedDate = new Date(tanggal);
            const dayOfWeek = selectedDate.getDay();
            
            if (dayOfWeek === 0) {
                alert('⚠️ Booking tidak diperbolehkan pada hari Minggu');
                return false;
            }
            
            const startTime = jamMulai.split(':');
            const endTime = jamSelesai.split(':');
            const startHour = parseInt(startTime[0]);
            const endHour = parseInt(endTime[0]);
            
            if (startHour < 7 || endHour > 21) {
                alert('⚠️ Jam booking harus antara 07:00 - 21:00');
                return false;
            }
            
            const start = new Date(`2000-01-01 ${jamMulai}`);
            const end = new Date(`2000-01-01 ${jamSelesai}`);
            const diffMinutes = (end - start) / (1000 * 60);
            
            if (diffMinutes < 30) {
                alert('⚠️ Minimum durasi booking adalah 30 menit');
                return false;
            }
            
            if (diffMinutes > 480) {
                alert('⚠️ Maksimum durasi booking adalah 8 jam');
                return false;
            }
            
            return true;
        }
        
        // ✅ FIXED: Toggle add-on section dengan debug console
        function toggleAddons() {
            console.log('🔧 toggleAddons() called'); // Debug
            
            const isExternal = document.getElementById('is_external').checked;
            const addonSection = document.getElementById('addonSection');
            const addonPreview = document.getElementById('addon_preview');
            
            console.log('📋 Checkbox checked:', isExternal); // Debug
            console.log('📋 Addon section found:', !!addonSection); // Debug
            console.log('📋 Preview found:', !!addonPreview); // Debug
            
            if (isExternal) {
                console.log('✅ Showing add-ons...'); // Debug
                
                // ✅ Tampilkan preview dulu
                if (addonPreview) {
                    addonPreview.style.display = 'block';
                    console.log('✅ Preview shown'); // Debug
                }
                
                // ✅ SIMPLIFIED: Langsung tampilkan tanpa animasi dulu untuk testing
                if (addonSection) {
                    addonSection.style.display = 'block';
                    addonSection.style.opacity = '1';
                    addonSection.style.transform = 'translateY(0)';
                    console.log('✅ Add-on section shown'); // Debug
                    
                    // Scroll ke section
                    setTimeout(() => {
                        addonSection.scrollIntoView({ 
                            behavior: 'smooth', 
                            block: 'nearest' 
                        });
                    }, 100);
                }
                
                // Show notification
                showNotification('🎉 Add-on fasilitas premium tersedia! Pilih sesuai kebutuhan acara.', 'success');
                
            } else {
                console.log('❌ Hiding add-ons...'); // Debug
                
                // ✅ Sembunyikan section
                if (addonPreview) {
                    addonPreview.style.display = 'none';
                }
                
                if (addonSection) {
                    addonSection.style.display = 'none';
                    console.log('❌ Add-on section hidden'); // Debug
                }
                
                // Reset semua add-on yang terpilih
                document.querySelectorAll('input[name="selected_addons[]"]').forEach(checkbox => {
                    checkbox.checked = false;
                    const addonKey = checkbox.value;
                    const card = document.querySelector('[onclick="toggleAddon(\'' + addonKey + '\')"]');
                    if (card) {
                        card.classList.remove('selected');
                    }
                    const quantityDiv = document.getElementById('quantity_' + addonKey);
                    if (quantityDiv) {
                        quantityDiv.style.display = 'none';
                    }
                });
                
                // Reset total
                calculateTotal();
                
                showNotification('ℹ️ Add-on dihilangkan. Hanya booking ruangan standar.', 'info');
            }
        }
        
        // ✅ ADDITIONAL TEST FUNCTION
        function testToggleAddons() {
            console.log('🧪 Manual test function called');
            const checkbox = document.getElementById('is_external');
            
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                console.log('🔄 Checkbox toggled to:', checkbox.checked);
                toggleAddons();
            } else {
                console.error('❌ Checkbox not found in test function!');
                alert('❌ Checkbox element tidak ditemukan! Cek console untuk detail.');
            }
        }
        
        // ✅ SIMPLE FALLBACK FUNCTION tanpa animasi
        function simpleToggleAddons() {
            const checkbox = document.getElementById('is_external');
            const addonSection = document.getElementById('addonSection');
            
            console.log('🔧 Simple toggle called');
            console.log('📋 Checkbox checked:', checkbox?.checked);
            console.log('📋 Section found:', !!addonSection);
            
            if (!checkbox || !addonSection) {
                console.error('❌ Required elements not found!');
                alert('Error: Elements not found! Check console.');
                return;
            }
            
            if (checkbox.checked) {
                console.log('✅ Showing add-on section (simple)');
                addonSection.style.display = 'block';
                addonSection.style.visibility = 'visible';
                addonSection.style.opacity = '1';
                
                // Scroll to section
                addonSection.scrollIntoView({ behavior: 'smooth' });
                
                alert('✅ Add-on section should now be visible!');
            } else {
                console.log('❌ Hiding add-on section (simple)');
                addonSection.style.display = 'none';
                alert('❌ Add-on section hidden');
            }
        }
        
        // ✅ Function untuk show notification
        function showNotification(message, type = 'info') {
            // Remove existing notification
            const existing = document.getElementById('temp-notification');
            if (existing) {
                existing.remove();
            }
            
            // Create notification
            const notification = document.createElement('div');
            notification.id = 'temp-notification';
            notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            notification.style.cssText = `
                top: 20px; 
                right: 20px; 
                z-index: 9999; 
                max-width: 400px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            `;
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 4 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.transition = 'all 0.3s ease';
                    notification.style.opacity = '0';
                    notification.style.transform = 'translateX(100%)';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 4000);
        }
        
        // ✅ ENHANCED: Add-on selection dengan feedback visual yang lebih baik
        const addonPrices = <?= json_encode(array_map(function($addon) { return $addon['price']; }, $addonFacilities)) ?>;
        
        function toggleAddon(addonKey) {
            const checkbox = document.getElementById('addon_' + addonKey);
            const card = checkbox.closest('.addon-card');
            const quantityDiv = document.getElementById('quantity_' + addonKey);
            
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                // ✅ Add selection dengan animasi
                card.classList.add('selected');
                card.style.transform = 'scale(1.05)';
                
                // Show quantity input dengan slide down
                quantityDiv.style.display = 'block';
                quantityDiv.style.opacity = '0';
                quantityDiv.style.height = '0px';
                
                setTimeout(() => {
                    quantityDiv.style.transition = 'all 0.3s ease';
                    quantityDiv.style.opacity = '1';
                    quantityDiv.style.height = 'auto';
                }, 50);
                
                // Reset scale after animation
                setTimeout(() => {
                    card.style.transform = 'scale(1)';
                }, 200);
                
                // Show selection notification
                const addonName = card.querySelector('h6').textContent;
                showNotification(`✅ ${addonName} ditambahkan!`, 'success');
                
            } else {
                // ✅ Remove selection dengan animasi
                card.classList.remove('selected');
                card.style.transform = 'scale(0.95)';
                
                // Hide quantity dengan slide up
                quantityDiv.style.transition = 'all 0.3s ease';
                quantityDiv.style.opacity = '0';
                quantityDiv.style.height = '0px';
                
                setTimeout(() => {
                    quantityDiv.style.display = 'none';
                    quantityDiv.querySelector('input').value = 1; // Reset quantity
                }, 300);
                
                // Reset scale
                setTimeout(() => {
                    card.style.transform = 'scale(1)';
                }, 200);
                
                // Show removal notification
                const addonName = card.querySelector('h6').textContent;
                showNotification(`➖ ${addonName} dihilangkan`, 'warning');
            }
            
            // Update total dengan delay untuk smooth animation
            setTimeout(() => {
                calculateTotal();
            }, 100);
        }
        
        function calculateTotal() {
            let total = 0;
            let selectedCount = 0;
            
            document.querySelectorAll('input[name="selected_addons[]"]:checked').forEach(checkbox => {
                const addonKey = checkbox.value;
                const price = addonPrices[addonKey] || 0;
                const quantityInput = document.querySelector('input[name="addon_quantities[' + addonKey + ']"]');
                const quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
                
                total += price * quantity;
                selectedCount++;
            });
            
            // ✅ Update total dengan animasi
            const totalElement = document.getElementById('totalAmount');
            const totalDisplay = document.querySelector('.total-display');
            
            // Animate total change
            totalElement.style.transition = 'all 0.3s ease';
            totalElement.style.transform = 'scale(1.1)';
            totalElement.style.color = total > 0 ? '#28a745' : '#6c757d';
            
            totalElement.textContent = total.toLocaleString('id-ID');
            
            // Reset animation
            setTimeout(() => {
                totalElement.style.transform = 'scale(1)';
            }, 300);
            
            // Update total display visibility and style
            if (total > 0) {
                totalDisplay.style.background = 'linear-gradient(45deg, #28a745, #20c997)';
                totalDisplay.style.transform = 'scale(1.02)';
                
                // Add pulse effect for high totals
                if (total > 1000000) { // > 1 juta
                    totalDisplay.style.animation = 'pulse 2s infinite';
                }
                
                setTimeout(() => {
                    totalDisplay.style.transform = 'scale(1)';
                }, 300);
            } else {
                totalDisplay.style.background = 'linear-gradient(45deg, #6c757d, #adb5bd)';
                totalDisplay.style.animation = 'none';
            }
            
            // Update summary di bawah total
            const summaryText = totalDisplay.querySelector('small');
            if (selectedCount > 0) {
                summaryText.textContent = `${selectedCount} add-on dipilih • Belum termasuk sewa ruangan`;
            } else {
                summaryText.textContent = 'Belum ada add-on dipilih';
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Page loaded, initializing...'); // Debug
            
            // ✅ DEBUGGING: Check if all elements exist
            const checkbox = document.getElementById('is_external');
            const addonSection = document.getElementById('addonSection');
            const addonPreview = document.getElementById('addon_preview');
            
            console.log('🔍 Elements check:');
            console.log('- Checkbox:', !!checkbox);
            console.log('- Addon section:', !!addonSection);
            console.log('- Preview:', !!addonPreview);
            
            // ✅ Enhanced form submission
            document.getElementById('bookingForm').addEventListener('submit', function(e) {
                if (!validateBookingForm()) {
                    e.preventDefault();
                    return false;
                }
                
                // Show confirmation for different roles
                const role = document.getElementById('role_peminjam').value;
                const email = document.querySelector('input[name="email_peminjam"]').value;
                
                if (role === 'dosen') {
                    const confirmed = confirm(`📚 KONFIRMASI BOOKING DOSEN\n\n✅ Booking akan langsung disetujui otomatis\n👨‍🏫 Email: ${email}\n🎯 PIC: Opsional (auto-terisi jika kosong)\n\nLanjutkan booking?`);
                    if (!confirmed) {
                        e.preventDefault();
                        return false;
                    }
                } else {
                    const confirmed = confirm(`📝 KONFIRMASI BOOKING ${role.toUpperCase()}\n\n⏳ Booking akan menunggu persetujuan admin\n📧 Email: ${email}\n👤 PIC: Wajib diisi\n\nLanjutkan booking?`);
                    if (!confirmed) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
            
            // ✅ Initialize addon section if external is already checked
            if (checkbox && checkbox.checked) {
                console.log('📋 Checkbox already checked, showing add-ons...');
                toggleAddons();
            }
            
            // ✅ FIXED: Event listener untuk checkbox dengan debug
            if (checkbox) {
                checkbox.addEventListener('change', function() {
                    console.log('📋 Checkbox changed to:', this.checked);
                    toggleAddons();
                });
                
                // ✅ Also keep the onchange attribute as backup
                console.log('✅ Checkbox event listeners attached');
            } else {
                console.error('❌ Checkbox not found!');
            }
            
            // Auto-check external for external role dan show preview
            const roleInputs = document.querySelectorAll('.role-option');
            roleInputs.forEach(option => {
                option.addEventListener('click', function() {
                    const role = this.getAttribute('data-role');
                    console.log('👤 Role selected:', role);
                    
                    if (role === 'external') {
                        if (checkbox) {
                            checkbox.checked = true;
                            console.log('🔄 Auto-checking external checkbox');
                            toggleAddons();
                        }
                    } else {
                        // Reset external checkbox untuk role lain
                        if (checkbox) {
                            checkbox.checked = false;
                            console.log('🔄 Auto-unchecking external checkbox');
                            toggleAddons();
                        }
                    }
                });
            });
            
            // ✅ DEBUGGING: Add manual test buttons (remove in production)
            const debugContainer = document.createElement('div');
            debugContainer.style.cssText = 'position: fixed; top: 10px; left: 10px; z-index: 9999; display: flex; gap: 5px; flex-direction: column;';
            
            const debugButton = document.createElement('button');
            debugButton.type = 'button';
            debugButton.className = 'btn btn-warning btn-sm';
            debugButton.innerHTML = '🔧 Debug Add-ons';
            debugButton.onclick = debugAddons;
            
            const testButton = document.createElement('button');
            testButton.type = 'button';
            testButton.className = 'btn btn-info btn-sm';
            testButton.innerHTML = '🧪 Simple Toggle';
            testButton.onclick = simpleToggleAddons;
            
            debugContainer.appendChild(debugButton);
            debugContainer.appendChild(testButton);
            document.body.appendChild(debugContainer);
            
            // ✅ ENHANCED: Hover preview untuk add-on checkbox
            if (checkbox && addonPreview) {
                checkbox.addEventListener('mouseenter', function() {
                    if (!this.checked) {
                        addonPreview.style.display = 'block';
                        addonPreview.style.opacity = '0.7';
                        addonPreview.innerHTML = `
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-gift me-2"></i>
                                <strong>Preview Add-on:</strong> Sound System (150k), Projector (100k), Catering (25k/org), Dekorasi (300k), dll.
                                <br><small><em>Centang checkbox untuk melihat semua opsi</em></small>
                            </div>
                        `;
                    }
                });
                
                checkbox.addEventListener('mouseleave', function() {
                    if (!this.checked) {
                        setTimeout(() => {
                            if (addonPreview && !this.checked) {
                                addonPreview.style.display = 'none';
                            }
                        }, 2000);
                    }
                });
            }
            
            // ✅ Keyboard shortcut untuk toggle add-on (Ctrl+A)
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'a' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        console.log('⌨️ Keyboard shortcut toggled checkbox to:', checkbox.checked);
                        toggleAddons();
                    }
                }
            });
            
            console.log('✅ Initialization complete');
        });
    </script>
</body>
</html>