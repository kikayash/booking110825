<?php
// File: export_bookings.php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is admin
if (!isAdmin()) {
    header('Location: index.php');
    exit;
}

// Handle PDF export
if (isset($_POST['export_pdf'])) {
    // Download and extract TCPDF library if not exists
    $tcpdfPath = 'tcpdf';
    if (!file_exists($tcpdfPath)) {
        // You can download TCPDF from https://github.com/tecnickcom/TCPDF/archive/main.zip
        // For now, we'll show a placeholder
    }
    
    // Include TCPDF library
    require_once($tcpdfPath . '/tcpdf.php');
    
    // Get filter parameters
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $status = $_POST['status'] ?? '';
    $roomId = $_POST['room_id'] ?? '';
    $buildingId = $_POST['building_id'] ?? '';
    
    // Build query
    $sql = "SELECT b.*, u.email, r.nama_ruang, g.nama_gedung
            FROM tbl_booking b 
            JOIN tbl_users u ON b.id_user = u.id_user 
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            WHERE 1=1";
    
    $params = [];
    
    if ($startDate) {
        $sql .= " AND b.tanggal >= ?";
        $params[] = $startDate;
    }
    
    if ($endDate) {
        $sql .= " AND b.tanggal <= ?";
        $params[] = $endDate;
    }
    
    if ($status) {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }
    
    if ($roomId) {
        $sql .= " AND b.id_ruang = ?";
        $params[] = $roomId;
    }
    
    if ($buildingId) {
        $sql .= " AND g.id_gedung = ?";
        $params[] = $buildingId;
    }
    
    $sql .= " ORDER BY b.tanggal DESC, b.jam_mulai ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
    
    // Create PDF
    generateBookingsPDF($bookings, $startDate, $endDate, $status);
    exit;
}

// Get all rooms and buildings for filter
$stmt = $conn->prepare("SELECT * FROM tbl_ruang ORDER BY nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

$stmt = $conn->prepare("SELECT * FROM tbl_gedung ORDER BY nama_gedung");
$stmt->execute();
$buildings = $stmt->fetchAll();

function generateBookingsPDF($bookings, $startDate, $endDate, $status) {
    global $config;
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('STIE MCE Booking System');
    $pdf->SetAuthor('Admin STIE MCE');
    $pdf->SetTitle('Laporan Peminjaman Ruangan');
    $pdf->SetSubject('Booking Report');
    $pdf->SetKeywords('booking, ruangan, laporan, STIE MCE');
    
    // Set default header data
    $pdf->SetHeaderData('', 0, 'LAPORAN PEMINJAMAN RUANGAN', "STIE MCE\nSistem Peminjaman Ruangan\nGenerated: " . date('d/m/Y H:i:s'));
    
    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Filter information
    $filterInfo = '<h3>Filter Laporan:</h3>';
    $filterInfo .= '<table border="1" cellpadding="4">';
    $filterInfo .= '<tr><td><strong>Periode:</strong></td><td>' . 
                   ($startDate ? formatDate($startDate) : 'Semua') . ' - ' . 
                   ($endDate ? formatDate($endDate) : 'Semua') . '</td></tr>';
    $filterInfo .= '<tr><td><strong>Status:</strong></td><td>' . 
                   ($status ? ucfirst($status) : 'Semua Status') . '</td></tr>';
    $filterInfo .= '<tr><td><strong>Total Data:</strong></td><td>' . count($bookings) . ' booking</td></tr>';
    $filterInfo .= '</table><br><br>';
    
    $pdf->writeHTML($filterInfo, true, false, true, false, '');
    
    if (count($bookings) > 0) {
        // Create HTML table
        $html = '<table border="1" cellpadding="3" cellspacing="0" style="font-size: 8px;">';
        $html .= '<thead style="background-color: #f0f0f0;">';
        $html .= '<tr>';
        $html .= '<th width="3%"><strong>No</strong></th>';
        $html .= '<th width="12%"><strong>Tanggal</strong></th>';
        $html .= '<th width="10%"><strong>Waktu</strong></th>';
        $html .= '<th width="8%"><strong>Ruangan</strong></th>';
        $html .= '<th width="15%"><strong>Acara</strong></th>';
        $html .= '<th width="12%"><strong>Peminjam</strong></th>';
        $html .= '<th width="12%"><strong>PIC</strong></th>';
        $html .= '<th width="10%"><strong>No. HP</strong></th>';
        $html .= '<th width="8%"><strong>Status</strong></th>';
        $html .= '<th width="10%"><strong>Keterangan</strong></th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';
        
        $no = 1;
        foreach ($bookings as $booking) {
            // Status styling
            $statusStyle = '';
            switch ($booking['status']) {
                case 'pending':
                    $statusStyle = 'background-color: #fff3cd; color: #856404;';
                    break;
                case 'approve':
                    $statusStyle = 'background-color: #d4edda; color: #155724;';
                    break;
                case 'active':
                    $statusStyle = 'background-color: #f8d7da; color: #721c24;';
                    break;
                case 'rejected':
                case 'cancelled':
                    $statusStyle = 'background-color: #e2e3e5; color: #383d41;';
                    break;
                case 'done':
                    $statusStyle = 'background-color: #cce7f0; color: #0c5460;';
                    break;
            }
            
            $html .= '<tr>';
            $html .= '<td>' . $no++ . '</td>';
            $html .= '<td>' . formatDate($booking['tanggal']) . '</td>';
            $html .= '<td>' . formatTime($booking['jam_mulai']) . ' - ' . formatTime($booking['jam_selesai']) . '</td>';
            $html .= '<td>' . htmlspecialchars($booking['nama_ruang']) . '<br><small>' . htmlspecialchars($booking['nama_gedung']) . '</small></td>';
            $html .= '<td>' . htmlspecialchars($booking['nama_acara']) . '</td>';
            $html .= '<td>' . htmlspecialchars($booking['email']) . '</td>';
            $html .= '<td>' . htmlspecialchars($booking['nama_penanggungjawab']) . '</td>';
            $html .= '<td>' . htmlspecialchars($booking['no_penanggungjawab']) . '</td>';
            $html .= '<td style="' . $statusStyle . '">' . ucfirst($booking['status']) . '</td>';
            $html .= '<td>' . (strlen($booking['keterangan']) > 30 ? substr(htmlspecialchars($booking['keterangan']), 0, 30) . '...' : htmlspecialchars($booking['keterangan'])) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '</table>';
        
        // Write HTML table
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Add summary
        $pdf->Ln(10);
        $summary = '<h3>Ringkasan:</h3>';
        
        // Count by status
        $statusCounts = [];
        foreach ($bookings as $booking) {
            $status = $booking['status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }
        
        $summary .= '<table border="1" cellpadding="4">';
        foreach ($statusCounts as $status => $count) {
            $summary .= '<tr><td><strong>' . ucfirst($status) . ':</strong></td><td>' . $count . ' booking</td></tr>';
        }
        $summary .= '</table>';
        
        $pdf->writeHTML($summary, true, false, true, false, '');
        
    } else {
        $pdf->writeHTML('<p style="text-align: center; color: red;"><strong>Tidak ada data booking yang ditemukan untuk filter yang dipilih.</strong></p>', true, false, true, false, '');
    }
    
    // Set filename
    $filename = 'Laporan_Booking_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Output PDF
    $pdf->Output($filename, 'D'); // 'D' for download
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Laporan Booking - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="logged-in">
    <header>
        <?php include 'header.php'; ?>
    </header>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-file-pdf me-2"></i>Export Laporan Booking ke PDF
                        </h4>
                        <small>Generate laporan peminjaman ruangan dalam format PDF</small>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Filter Periode</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <label for="start_date" class="form-label">Tanggal Mulai</label>
                                                    <input type="date" class="form-control" id="start_date" name="start_date">
                                                    <div class="form-text">Kosongkan untuk semua tanggal</div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="end_date" class="form-label">Tanggal Selesai</label>
                                                    <input type="date" class="form-control" id="end_date" name="end_date">
                                                    <div class="form-text">Kosongkan untuk semua tanggal</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Filter Status & Lokasi</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label for="status" class="form-label">Status Booking</label>
                                                <select class="form-select" id="status" name="status">
                                                    <option value="">Semua Status</option>
                                                    <option value="pending">Pending</option>
                                                    <option value="approve">Disetujui</option>
                                                    <option value="active">Sedang Berlangsung</option>
                                                    <option value="done">Selesai</option>
                                                    <option value="rejected">Ditolak</option>
                                                    <option value="cancelled">Dibatalkan</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="building_id" class="form-label">Gedung</label>
                                                <select class="form-select" id="building_id" name="building_id">
                                                    <option value="">Semua Gedung</option>
                                                    <?php foreach ($buildings as $building): ?>
                                                        <option value="<?= $building['id_gedung'] ?>">
                                                            <?= htmlspecialchars($building['nama_gedung']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="room_id" class="form-label">Ruangan Spesifik</label>
                                                <select class="form-select" id="room_id" name="room_id">
                                                    <option value="">Semua Ruangan</option>
                                                    <?php foreach ($rooms as $room): ?>
                                                        <option value="<?= $room['id_ruang'] ?>">
                                                            <?= htmlspecialchars($room['nama_ruang']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <div class="d-flex">
                                    <div class="me-3">
                                        <i class="fas fa-info-circle fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6>Informasi Export PDF:</h6>
                                        <ul class="mb-0">
                                            <li>File PDF akan didownload secara otomatis setelah generate</li>
                                            <li>Laporan mencakup semua detail booking sesuai filter yang dipilih</li>
                                            <li>Format laporan sudah dioptimalkan untuk print A4</li>
                                            <li>Jika tidak ada filter yang dipilih, semua data akan di-export</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="admin/admin-dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard
                                </a>
                                <button type="submit" name="export_pdf" class="btn btn-info">
                                    <i class="fas fa-file-pdf me-2"></i>Generate & Download PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include 'footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-set default dates
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDayOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            
            // Set default to current month
            document.getElementById('start_date').value = firstDayOfMonth.toISOString().split('T')[0];
            document.getElementById('end_date').value = lastDayOfMonth.toISOString().split('T')[0];
        });
        
        // Validate date range
        function validateDateRange() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (startDate && endDate && startDate > endDate) {
                alert('Tanggal mulai tidak boleh lebih besar dari tanggal selesai');
                return false;
            }
            
            return true;
        }
        
        // Add validation to form
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!validateDateRange()) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>