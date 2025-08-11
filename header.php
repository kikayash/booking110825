<?php
// PERBAIKAN header.php - Fixed untuk dosen login

// Pastikan session sudah dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include functions.php jika belum di-include
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/functions.php';
}

// PERBAIKAN: Deteksi path dengan lebih akurat
$isAdmin = isset($backPath) && $backPath === '../';
$basePath = $isAdmin ? '../' : '';
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Check if we're in admin directory
$inAdminDir = ($currentDir === 'admin') || strpos($_SERVER['REQUEST_URI'], '/admin/') !== false;

// TAMBAHAN: Get user info untuk display yang lebih baik
$userDisplayName = '';
$userRole = '';
if (isLoggedIn()) {
    $userDisplayName = $_SESSION['email'] ?? 'User';
    $userRole = $_SESSION['role'] ?? 'guest';
    
    // Special handling untuk dosen
    if ($userRole === 'dosen' && isset($_SESSION['nama'])) {
        $userDisplayName = $_SESSION['nama']; // Nama lengkap dosen
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= $basePath ?>index.php">
            <i class="fas fa-university me-2"></i>Booking STIE MCE
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="<?= $basePath ?>index.php">
                        <i class="fas fa-home me-1"></i> Beranda
                    </a>
                </li>

                <?php if (isLoggedIn()): ?>
                    
                    <!-- Menu khusus Admin dan CS -->
                    <?php if (isAdmin() || isCS()): ?>
                    <!-- Dashboard untuk Admin/CS -->
                         <?php if (isAdmin()): ?>
                         <li class="nav-item">
                            <a class="nav-link <?= $currentPage === 'admin/room_status.php' ? 'active' : '' ?>" href="<?= $basePath ?>admin/room_status.php">
                                <i class="fas fa-tv me-1"></i> Status Ruangan
                            </a>
                         </li>
                        <?php endif; ?>
                        <?php if (isCs()): ?>
                            <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'cs/room_availability.php' ? 'active' : '' ?>" href="<?= $basePath ?>cs/room_availability.php">
                            <i class="fas fa-search me-1"></i> Cari Ruangan Kosong
                        </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $currentPage === 'cs/today_rooms.php' ? 'active' : '' ?>" href="<?= $basePath ?>cs/today_rooms.php">
                                <i class="fas fa-tv me-1"></i> Status Ruangan
                            </a>
                        </li>
                        <?php endif; ?>

                        <!-- Dashboard untuk Admin/CS -->
                         <?php if (isAdmin()): ?>
                         <li class="nav-item">
                            <a class="nav-link <?= $currentPage === 'admin/admin-dashboard.php' ? 'active' : '' ?>" href="<?= $basePath ?>admin/admin-dashboard.php">
                                <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                            </a>
                         </li>
                        <?php endif; ?>
                        <?php if (isCs()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $currentPage === 'cs/dashboard.php' ? 'active' : '' ?>" href="<?= $basePath ?>cs/dashboard.php">
                                <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                            </a>
                        </li>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
            
            <div class="d-flex align-items-center">
                <?php if (isLoggedIn()): ?>
                    <div id="userInfo" class="text-light d-flex align-items-center">
                        <!-- PERBAIKAN: Display yang lebih baik untuk semua role -->
                        <span class="me-2 d-none d-md-inline">
                            <span class="user-name"><?= htmlspecialchars($userDisplayName) ?></span>
                            <span class="badge bg-<?= $userRole === 'admin' ? 'danger' : ($userRole === 'dosen' ? 'info' : 'success') ?> ms-1">
                                <?= ucfirst($userRole) ?>
                            </span>
                        </span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li>
                                    <span class="dropdown-item-text">
                                        <small class="text-muted">Login sebagai:</small><br>
                                        <strong><?= htmlspecialchars($userDisplayName) ?></strong>
                                        <span class="badge bg-<?= $userRole === 'admin' ? 'danger' : ($userRole === 'dosen' ? 'info' : 'success') ?> ms-1">
                                            <?= ucfirst($userRole) ?>
                                        </span>
                                        <?php if ($userRole === 'dosen' && isset($_SESSION['nik'])): ?>
                                            <br><small class="text-muted">NIK: <?= $_SESSION['nik'] ?></small>
                                        <?php endif; ?>
                                    </span>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <!--Menu Dosen-->
                                <?php if (isDosen()): ?>
                                <li><a class="dropdown-item" href="<?= $basePath ?>cs/my_bookings.php">
                                    <i class="fas fa-list me-2"></i>Peminjaman Saya</a></li>
                                
                                <li><a class="dropdown-item" href="<?= $basePath ?>room_availability.php">
                                    <i class="fas fa-search me-2"></i>Cari Ruangan Kosong</a></li>
                                    <?php endif; ?>
                                <!-- Menu khusus Admin -->
                                <?php if (isAdmin()): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?= $inAdminDir ? 'admin-dashboard.php' : 'admin/admin-dashboard.php' ?>">
                                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard Admin</a></li>
                                    <li><a class="dropdown-item" href="<?= $inAdminDir ? 'recurring_schedules.php' : 'admin/recurring_schedules.php' ?>">
                                        <i class="fas fa-calendar-week me-2"></i>Jadwal Perkuliahan</a></li>
                                <?php endif; ?>
                                
                                <!-- Menu khusus CS -->
                                <?php if (isAdmin() || isCS() || isDosen()): ?>
                                    <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?= $basePath ?>logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="fas fa-sign-in-alt me-1"></i> Login
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>