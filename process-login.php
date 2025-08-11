<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Enhanced Login Processor with better debugging
// Version 2.1 - CS Login Fix

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log all login attempts
error_log("=== LOGIN ATTEMPT START ===");
error_log("POST data: " . print_r($_POST, true));
error_log("SESSION before: " . print_r($_SESSION, true));

try {
    // Check if request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method: ' . $_SERVER['REQUEST_METHOD']);
    }

    // Get and validate form data
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['login_role'] ?? '');

    error_log("Login attempt - Email: $email, Role: $role");

    // Validate input
    if (empty($email)) {
        throw new Exception('Email tidak boleh kosong');
    }
    
    if (empty($password)) {
        throw new Exception('Password tidak boleh kosong');
    }
    
    if (empty($role)) {
        throw new Exception('Role harus dipilih');
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Format email tidak valid');
    }

    // DEBUGGING: Check database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Database query with debugging
    $sql = "SELECT * FROM tbl_users WHERE email = ? AND role = ?";
    error_log("Executing SQL: $sql with params: $email, $role");

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $conn->errorInfo()[2]);
    }

    $result = $stmt->execute([$email, $role]);
    if (!$result) {
        throw new Exception('Database execute failed: ' . $stmt->errorInfo()[2]);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Database result: " . print_r($user, true));

    if (!$user) {
        error_log("User not found - Email: $email, Role: $role");
        
        // DEBUGGING: Check if user exists with different role
        $debugStmt = $conn->prepare("SELECT email, role FROM tbl_users WHERE email = ?");
        $debugStmt->execute([$email]);
        $debugUser = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("User with email $email exists with roles: " . print_r($debugUser, true));
        
        throw new Exception('Email, password, atau role tidak sesuai');
    }

    // Check user status (if column exists)
    if (isset($user['status']) && $user['status'] !== 'active') {
        throw new Exception('Akun tidak aktif. Hubungi administrator.');
    }

    // Enhanced password verification
    $passwordMatch = false;
    $userPassword = $user['password'];
    
    error_log("Password verification - Input: $password, Stored: $userPassword");
    
    // Try different password comparison methods
    if (is_numeric($password) && is_numeric($userPassword)) {
        // Compare as integers for numeric passwords
        $passwordMatch = (intval($password) === intval($userPassword));
        error_log("Numeric password comparison: " . ($passwordMatch ? 'MATCH' : 'NO MATCH'));
    }
    
    if (!$passwordMatch) {
        // Try string comparison
        $passwordMatch = ($password === $userPassword);
        error_log("String password comparison: " . ($passwordMatch ? 'MATCH' : 'NO MATCH'));
    }
    
    if (!$passwordMatch) {
        // Try password_verify for hashed passwords
        $passwordMatch = password_verify($password, $userPassword);
        error_log("Hash password comparison: " . ($passwordMatch ? 'MATCH' : 'NO MATCH'));
    }

    if (!$passwordMatch) {
        error_log("Password mismatch for email: $email");
        throw new Exception('Email, password, atau role tidak sesuai');
    }

    // Set session with enhanced security
    session_regenerate_id(true);
    
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $user['id_user'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    
    // Additional user data
    if (isset($user['nama'])) {
        $_SESSION['nama'] = $user['nama'];
    }

    error_log("Login successful - User ID: {$user['id_user']}, Email: $email, Role: {$user['role']}");
    error_log("SESSION after login: " . print_r($_SESSION, true));

    // Enhanced redirect logic
    $baseUrl = '';
    $redirectUrl = '';
    
    switch ($user['role']) {
        case 'admin':
            $redirectUrl = 'admin/admin-dashboard.php';
            break;
        case 'cs':
            $redirectUrl = 'cs/dashboard.php';
            break;
        case 'mahasiswa':
        default:
            $redirectUrl = 'index.php';
            break;
    }

    // Check if file exists for role-specific dashboards
    if (strpos($redirectUrl, '/') !== false) {
        $filePath = $redirectUrl;
        if (!file_exists($filePath)) {
            error_log("Dashboard file not found: $filePath, redirecting to index.php");
            $redirectUrl = 'index.php';
        }
    }

    error_log("Redirecting to: $redirectUrl");

    // Handle AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Login berhasil',
            'redirect' => $redirectUrl,
            'user' => [
                'id' => $user['id_user'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
        exit;
    }

    // Regular form submission - redirect
    header("Location: $redirectUrl?login_success=1");
    exit;

} catch (PDOException $e) {
    // Database error
    $errorMsg = 'Database error: ' . $e->getMessage();
    error_log("Database error in login: " . $e->getMessage());
    $errorMessage = 'Terjadi kesalahan sistem. Silakan coba lagi.';
    
} catch (Exception $e) {
    // General error
    $errorMsg = 'Login error: ' . $e->getMessage();
    error_log($errorMsg);
    $errorMessage = $e->getMessage();
}

error_log("=== LOGIN ATTEMPT END (FAILED) ===");

// Handle error response
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $errorMessage
    ]);
    exit;
}

// Redirect back with error for regular form submission
$errorQuery = http_build_query(['login_error' => $errorMessage]);
header("Location: index.php?$errorQuery");
exit;
?>