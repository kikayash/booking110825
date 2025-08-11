<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID not provided']);
    exit;
}

$userId = $_GET['id'];

try {
    // Get user details
    $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE id_user = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Check if we have a profile table with additional info
        $hasProfile = false;
        try {
            $stmt = $conn->prepare("SHOW TABLES LIKE 'tbl_user_profiles'");
            $stmt->execute();
            $hasProfile = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Profile table doesn't exist, just continue
        }
        
        $name = '';
        $phone = '';
        
        // If profile table exists, get additional info
        if ($hasProfile) {
            $stmt = $conn->prepare("SELECT nama_lengkap, no_telepon FROM tbl_user_profiles WHERE id_user = ?");
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($profile) {
                $name = $profile['nama_lengkap'] ?? '';
                $phone = $profile['no_telepon'] ?? '';
            }
        }
        
        echo json_encode([
            'success' => true,
            'user_id' => $user['id_user'],
            'email' => $user['email'],
            'role' => $user['role'],
            'name' => $name,
            'phone' => $phone
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}