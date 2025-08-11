<?php
session_start();
require_once '../../config.php';
require_once '../../functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to browser
ini_set('log_errors', 1);

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $scheduleText = $_POST['schedule_text'] ?? '';
    
    error_log("=== AJAX REQUEST ===");
    error_log("Action: $action");
    error_log("Schedule text length: " . strlen($scheduleText));
    error_log("User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    
    if (empty($scheduleText)) {
        echo json_encode([
            'success' => false,
            'message' => 'Input jadwal tidak boleh kosong'
        ]);
        exit;
    }
    
    switch ($action) {
        case 'parse_preview':
            $result = parseManualScheduleForPreview($conn, $scheduleText);
            echo json_encode($result);
            break;
            
        case 'generate_schedules':
            $result = generateManualSchedulesFinal($conn, $scheduleText);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Action tidak valid: ' . $action
            ]);
    }
    
} catch (Exception $e) {
    error_log("ERROR in parse_manual_schedule.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error sistem: ' . $e->getMessage()
    ]);
}

/**
 * Parse manual input untuk preview
 */
function parseManualScheduleForPreview($conn, $scheduleText) {
    try {
        error_log("=== PARSE PREVIEW START ===");
        
        // Parse text menjadi array rows
        $rows = parseManualScheduleText($scheduleText);
        error_log("Parsed rows count: " . count($rows));
        
        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'Tidak ada data valid yang bisa diproses. Pastikan format sesuai dengan contoh.'
            ];
        }
        
        // Validasi data
        $validationResult = validateManualScheduleRows($conn, $rows);
        error_log("Validation complete - Valid: " . count($validationResult['valid_data']) . ", Errors: " . count($validationResult['errors']));
        
        // Hitung statistik
        $validRows = count($validationResult['valid_data']);
        $errorRows = count($validationResult['errors']);
        $estimatedBookings = $validRows * 24; // Estimasi 24 booking per jadwal (semester)
        
        return [
            'success' => true,
            'summary' => [
                'total_rows' => count($rows),
                'valid_rows' => $validRows,
                'error_rows' => $errorRows,
                'estimated_bookings' => $estimatedBookings
            ],
            'valid_data' => array_slice($validationResult['valid_data'], 0, 10), // Max 10 untuk preview
            'errors' => $validationResult['errors'],
            'warnings' => $validationResult['warnings'] ?? []
        ];
        
    } catch (Exception $e) {
        error_log("Error in parseManualScheduleForPreview: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error parsing: ' . $e->getMessage()
        ];
    }
}

/**
 * Generate jadwal final dari manual input
 */
function generateManualSchedulesFinal($conn, $scheduleText) {
    try {
        error_log("=== GENERATE SCHEDULES START ===");
        
        // Parse text menjadi array rows
        $rows = parseManualScheduleText($scheduleText);
        
        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'Tidak ada data yang bisa diproses'
            ];
        }
        
        // Validasi ulang
        $validationResult = validateManualScheduleRows($conn, $rows);
        
        if (empty($validationResult['valid_data'])) {
            return [
                'success' => false,
                'message' => 'Tidak ada data valid untuk diproses:\n\n' . 
                           implode('\n', $validationResult['errors'])
            ];
        }
        
        // Proses data valid TANPA transaction di sini (akan dihandle di processValidatedManualScheduleData)
        $result = processValidatedManualScheduleData($conn, $validationResult['valid_data']);
        
        error_log("=== GENERATE SCHEDULES END ===");
        return $result;
        
    } catch (Exception $e) {
        error_log("Error in generateManualSchedulesFinal: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error generate: ' . $e->getMessage()
        ];
    }
}

/**
 * Parse text input menjadi array rows
 */
function parseManualScheduleText($scheduleText) {
    $lines = explode("\n", trim($scheduleText));
    $rows = [];
    
    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        
        // Skip empty lines and comments
        if (empty($line) || strpos($line, '#') === 0 || strpos($line, '//') === 0) {
            continue;
        }
        
        // Parse CSV line dengan handling quotes dan escaped commas
        $row = parseCSVLine($line);
        
        // Clean dan trim setiap kolom
        $cleanRow = array_map(function($item) {
            return trim($item, " \t\n\r\0\x0B\"'");
        }, $row);
        
        // Pastikan minimal ada 7 kolom (required fields)
        if (count($cleanRow) >= 7) {
            // Pad dengan default values jika kurang dari 11 kolom
            $defaults = [
                7 => 'Genap',                    // semester
                8 => '2024/2025',               // tahun akademik
                9 => date('Y-m-d'),             // start date
                10 => date('Y-m-d', strtotime('+6 months')) // end date
            ];
            
            for ($i = 7; $i < 11; $i++) {
                if (!isset($cleanRow[$i]) || empty($cleanRow[$i])) {
                    $cleanRow[$i] = $defaults[$i];
                }
            }
            
            $rows[] = array_slice($cleanRow, 0, 11); // Max 11 kolom
        }
    }
    
    return $rows;
}

/**
 * Enhanced CSV line parser
 */
function parseCSVLine($line) {
    $result = [];
    $current = '';
    $inQuotes = false;
    $length = strlen($line);
    
    for ($i = 0; $i < $length; $i++) {
        $char = $line[$i];
        
        if ($char === '"') {
            $inQuotes = !$inQuotes;
        } elseif ($char === ',' && !$inQuotes) {
            $result[] = $current;
            $current = '';
        } else {
            $current .= $char;
        }
    }
    
    // Add the last field
    $result[] = $current;
    
    return $result;
}

/**
 * Validasi manual schedule rows
 */
function validateManualScheduleRows($conn, $rows) {
    $validData = [];
    $errors = [];
    $warnings = [];
    
    try {
        // Get available rooms untuk validasi
        $availableRooms = getAvailableRoomsForValidation($conn);
        $roomNames = array_column($availableRooms, 'nama_ruang');
        
        error_log("Available rooms: " . implode(', ', $roomNames));
        
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            
            try {
                // Validasi basic
                $rowErrors = validateManualScheduleRow($row, $rowNumber, $roomNames);
                
                if (!empty($rowErrors)) {
                    $errors = array_merge($errors, $rowErrors);
                    continue;
                }
                
                // Clean dan prepare data
                $scheduleData = cleanManualScheduleRow($conn, $row, $rowNumber, $availableRooms);
                
                if ($scheduleData) {
                    // Check duplicate
                    $duplicateCheck = checkScheduleDuplicateEnhanced($conn, $scheduleData);
                    if ($duplicateCheck['is_duplicate']) {
                        $errors[] = "❌ Baris $rowNumber: " . $duplicateCheck['message'];
                        continue;
                    }
                    
                    $validData[] = $scheduleData;
                }
                
            } catch (Exception $e) {
                $errors[] = "❌ Baris $rowNumber: Error - " . $e->getMessage();
            }
        }
        
        return [
            'valid_data' => $validData,
            'errors' => $errors,
            'warnings' => $warnings
        ];
        
    } catch (Exception $e) {
        error_log("Error in validateManualScheduleRows: " . $e->getMessage());
        return [
            'valid_data' => [],
            'errors' => ['Error validasi: ' . $e->getMessage()],
            'warnings' => []
        ];
    }
}

/**
 * Validasi single row
 */
function validateManualScheduleRow($row, $rowNumber, $roomNames) {
    $errors = [];
    
    // Required fields check
    $requiredFields = [
        0 => 'Mata Kuliah',
        1 => 'Kelas',
        2 => 'Dosen Pengampu',
        3 => 'Hari',
        4 => 'Jam Mulai',
        5 => 'Jam Selesai',
        6 => 'Nama Ruangan'
    ];
    
    foreach ($requiredFields as $colIndex => $fieldName) {
        if (empty($row[$colIndex] ?? '')) {
            $errors[] = "❌ Baris $rowNumber: $fieldName tidak boleh kosong";
        }
    }
    
    // Validasi hari
    if (!empty($row[3])) {
        $hari = mapDayToEnglishEnhanced(trim($row[3]));
        if (empty($hari)) {
            $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
            $errors[] = "❌ Baris $rowNumber: Hari '{$row[3]}' tidak valid. Gunakan: " . implode(', ', $validDays);
        }
    }
    
    // Validasi format waktu
    if (!empty($row[4])) {
        $jamMulai = convertTimeFormatEnhanced($row[4]);
        if (!$jamMulai || !isValidTimeFormat($jamMulai)) {
            $errors[] = "❌ Baris $rowNumber: Format jam mulai '{$row[4]}' tidak valid. Gunakan format HH:MM (contoh: 09:00)";
        }
    }
    
    if (!empty($row[5])) {
        $jamSelesai = convertTimeFormatEnhanced($row[5]);
        if (!$jamSelesai || !isValidTimeFormat($jamSelesai)) {
            $errors[] = "❌ Baris $rowNumber: Format jam selesai '{$row[5]}' tidak valid. Gunakan format HH:MM (contoh: 11:30)";
        }
    }
    
    // Validasi logika waktu
    if (!empty($row[4]) && !empty($row[5])) {
        $start = convertTimeFormatEnhanced($row[4]);
        $end = convertTimeFormatEnhanced($row[5]);
        if ($start && $end && strtotime($start) >= strtotime($end)) {
            $errors[] = "❌ Baris $rowNumber: Jam selesai harus lebih besar dari jam mulai";
        }
    }
    
    // Validasi ruangan
    if (!empty($row[6])) {
        $inputRoom = trim($row[6]);
        $exactMatch = false;
        
        // Cek exact match dulu
        foreach ($roomNames as $roomName) {
            if (strcasecmp($inputRoom, $roomName) === 0) {
                $exactMatch = true;
                break;
            }
        }
        
        if (!$exactMatch) {
            $suggestion = findClosestRoom($inputRoom, $roomNames);
            $errorMsg = "❌ Baris $rowNumber: Ruangan '$inputRoom' tidak ditemukan.";
            if ($suggestion) {
                $errorMsg .= " Mungkin maksud: '$suggestion'?";
            }
            $errorMsg .= " Ruangan tersedia: " . implode(', ', array_slice($roomNames, 0, 5)) . "...";
            $errors[] = $errorMsg;
        }
    }
    
    return $errors;
}

/**
 * Enhanced time format converter
 */
function convertTimeFormatEnhanced($time) {
    if (empty($time)) return '';
    
    // Remove spaces dan normalisasi
    $time = preg_replace('/\s+/', '', trim($time));
    
    // Handle Excel decimal time (0.375 = 09:00)
    if (is_numeric($time) && $time > 0 && $time < 1) {
        $totalMinutes = $time * 24 * 60;
        $hours = floor($totalMinutes / 60);
        $minutes = $totalMinutes % 60;
        return sprintf('%02d:%02d:00', $hours, $minutes);
    }
    
    // Format HH:MM atau H:MM
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
        $hours = intval($matches[1]);
        $minutes = intval($matches[2]);
        
        if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
            return sprintf('%02d:%02d:00', $hours, $minutes);
        }
    }
    
    // Format HHMM (tanpa separator)
    if (preg_match('/^\d{3,4}$/', $time)) {
        $time = str_pad($time, 4, '0', STR_PAD_LEFT);
        $hours = intval(substr($time, 0, 2));
        $minutes = intval(substr($time, 2, 2));
        
        if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
            return sprintf('%02d:%02d:00', $hours, $minutes);
        }
    }
    
    // Format dengan titik sebagai separator (9.00)
    if (preg_match('/^(\d{1,2})\.(\d{2})$/', $time, $matches)) {
        $hours = intval($matches[1]);
        $minutes = intval($matches[2]);
        
        if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
            return sprintf('%02d:%02d:00', $hours, $minutes);
        }
    }
    
    return '';
}

/**
 * Enhanced time format validation
 */
function isValidTimeFormat($time) {
    if (empty($time)) return false;
    
    // Check HH:MM:SS format
    if (preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/', $time)) {
        return true;
    }
    
    return false;
}

/**
 * Enhanced day mapping
 */
function mapDayToEnglishEnhanced($day) {
    $dayMap = [
        'senin' => 'monday',
        'selasa' => 'tuesday',
        'rabu' => 'wednesday',
        'kamis' => 'thursday',
        'jumat' => 'friday',
        'sabtu' => 'saturday',
        'minggu' => 'sunday',
        'monday' => 'monday',
        'tuesday' => 'tuesday',
        'wednesday' => 'wednesday',
        'thursday' => 'thursday',
        'friday' => 'friday',
        'saturday' => 'saturday',
        'sunday' => 'sunday'
    ];
    
    $normalizedDay = strtolower(trim($day));
    return $dayMap[$normalizedDay] ?? '';
}

/**
 * Get available rooms for validation
 */
function getAvailableRoomsForValidation($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT r.id_ruang, r.nama_ruang, r.kapasitas, g.nama_gedung
            FROM tbl_ruang r
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            ORDER BY r.nama_ruang
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting rooms: " . $e->getMessage());
        return [];
    }
}

/**
 * Enhanced room name matching
 */
function findClosestRoom($input, $roomNames) {
    $input = strtolower($input);
    $closest = '';
    $shortestDistance = -1;
    
    foreach ($roomNames as $roomName) {
        $distance = levenshtein($input, strtolower($roomName));
        
        if ($distance <= 2 && ($shortestDistance == -1 || $distance < $shortestDistance)) {
            $closest = $roomName;
            $shortestDistance = $distance;
        }
    }
    
    return $closest;
}

/**
 * Clean dan prepare schedule data dari row
 */
function cleanManualScheduleRow($conn, $row, $rowNumber, $availableRooms) {
    try {
        // Get room ID dengan fuzzy matching
        $roomName = trim($row[6] ?? '');
        $roomId = null;
        
        foreach ($availableRooms as $room) {
            if (strcasecmp($roomName, $room['nama_ruang']) === 0) {
                $roomId = $room['id_ruang'];
                $roomName = $room['nama_ruang']; // Use exact name from DB
                break;
            }
        }
        
        if (!$roomId) {
            throw new Exception("Ruangan '$roomName' tidak ditemukan dalam database");
        }
        
        // Convert and validate dates
        $startDate = convertDateFormatEnhanced($row[9] ?? '') ?: date('Y-m-d');
        $endDate = convertDateFormatEnhanced($row[10] ?? '') ?: date('Y-m-d', strtotime('+6 months'));
        
        // Ensure end date is after start date
        if (strtotime($endDate) <= strtotime($startDate)) {
            $endDate = date('Y-m-d', strtotime($startDate . ' +6 months'));
        }
        
        return [
            'id_ruang' => $roomId,
            'nama_matakuliah' => trim($row[0] ?? ''),
            'kelas' => trim($row[1] ?? ''),
            'dosen_pengampu' => trim($row[2] ?? ''),
            'hari' => mapDayToEnglishEnhanced(trim($row[3] ?? '')),
            'jam_mulai' => convertTimeFormatEnhanced($row[4] ?? ''),
            'jam_selesai' => convertTimeFormatEnhanced($row[5] ?? ''),
            'nama_ruang' => $roomName,
            'semester' => trim($row[7] ?? '') ?: 'Genap',
            'tahun_akademik' => trim($row[8] ?? '') ?: '2024/2025',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'created_by' => $_SESSION['user_id'],
            'row_number' => $rowNumber
        ];
        
    } catch (Exception $e) {
        error_log("Error cleaning row $rowNumber: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Enhanced date format converter
 */
function convertDateFormatEnhanced($date) {
    if (empty($date)) return '';
    
    $date = trim($date);
    
    // Handle various formats
    $formats = [
        'Y-m-d',      // 2025-07-01
        'd/m/Y',      // 01/07/2025
        'd-m-Y',      // 01-07-2025
        'm/d/Y',      // 07/01/2025
        'd/m/y',      // 01/07/25
        'j/n/Y',      // 1/7/2025
        'j-n-Y'       // 1-7-2025
    ];
    
    foreach ($formats as $format) {
        $d = DateTime::createFromFormat($format, $date);
        if ($d && $d->format($format) === $date) {
            return $d->format('Y-m-d');
        }
    }
    
    // Try strtotime untuk format yang lebih flexible
    $timestamp = strtotime($date);
    if ($timestamp !== false && $timestamp > strtotime('2020-01-01')) {
        return date('Y-m-d', $timestamp);
    }
    
    return '';
}

/**
 * Enhanced duplicate checking
 */
function checkScheduleDuplicateEnhanced($conn, $scheduleData) {
    try {
        $stmt = $conn->prepare("
            SELECT rs.nama_matakuliah, rs.kelas, r.nama_ruang, rs.jam_mulai, rs.jam_selesai
            FROM tbl_recurring_schedules rs
            JOIN tbl_ruang r ON rs.id_ruang = r.id_ruang
            WHERE rs.id_ruang = ? 
            AND rs.hari = ? 
            AND rs.status = 'active'
            AND (
                (TIME(rs.jam_mulai) < TIME(?) AND TIME(rs.jam_selesai) > TIME(?)) OR
                (TIME(rs.jam_mulai) < TIME(?) AND TIME(rs.jam_selesai) > TIME(?)) OR
                (TIME(rs.jam_mulai) >= TIME(?) AND TIME(rs.jam_selesai) <= TIME(?))
            )
            LIMIT 1
        ");
        
        $stmt->execute([
            $scheduleData['id_ruang'],
            $scheduleData['hari'],
            $scheduleData['jam_mulai'], $scheduleData['jam_mulai'],
            $scheduleData['jam_selesai'], $scheduleData['jam_selesai'],
            $scheduleData['jam_mulai'], $scheduleData['jam_selesai']
        ]);
        
        $duplicate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($duplicate) {
            return [
                'is_duplicate' => true,
                'message' => "Konflik waktu di ruangan {$duplicate['nama_ruang']} pada hari {$scheduleData['hari']} jam {$duplicate['jam_mulai']}-{$duplicate['jam_selesai']} dengan '{$duplicate['nama_matakuliah']} - {$duplicate['kelas']}'"
            ];
        }
        
        return ['is_duplicate' => false];
        
    } catch (Exception $e) {
        error_log("Error checking duplicate: " . $e->getMessage());
        return ['is_duplicate' => false];
    }
}

/**
 * Process validated manual schedule data - FIXED VERSION
 */
function processValidatedManualScheduleData($conn, $validData) {
    try {
        error_log("=== PROCESS VALIDATED DATA START ===");
        error_log("Processing " . count($validData) . " valid schedules");
        
        // Mulai transaction di sini
        $conn->beginTransaction();
        
        $results = [
            'total_processed' => count($validData),
            'success' => 0,
            'errors' => 0,
            'total_bookings' => 0,
            'success_details' => [],
            'error_details' => []
        ];
        
        foreach ($validData as $scheduleData) {
            try {
                error_log("Processing schedule: " . $scheduleData['nama_matakuliah'] . " - " . $scheduleData['kelas']);
                
                // Add recurring schedule menggunakan fungsi yang sudah ada di functions.php
                $result = addRecurringSchedule($conn, $scheduleData);
                
                if ($result['success']) {
                    $results['success']++;
                    $results['total_bookings'] += $result['generated_bookings'];
                    $results['success_details'][] = "✅ {$scheduleData['nama_matakuliah']} ({$scheduleData['kelas']}) - {$result['generated_bookings']} booking";
                    error_log("SUCCESS: " . $scheduleData['nama_matakuliah'] . " with " . $result['generated_bookings'] . " bookings");
                } else {
                    throw new Exception($result['message']);
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['error_details'][] = "❌ Baris {$scheduleData['row_number']}: " . $e->getMessage();
                error_log("ERROR row {$scheduleData['row_number']}: " . $e->getMessage());
                
                // Continue processing other schedules
            }
        }
        
        // Commit transaction
        $conn->commit();
        error_log("=== TRANSACTION COMMITTED ===");
        
        // Generate success message
        $successRate = $results['total_processed'] > 0 ? 
                      round(($results['success'] / $results['total_processed']) * 100, 1) : 0;
        
        $finalResult = [
            'success' => true,
            'total_uploaded' => $results['success'],
            'total_bookings' => $results['total_bookings'],
            'errors' => $results['errors'],
            'message' => generateManualScheduleSuccessMessage($results, $successRate),
            'details' => $results
        ];
        
        error_log("=== PROCESS COMPLETE ===");
        error_log("Final result: " . json_encode($finalResult));
        
        return $finalResult;
        
    } catch (Exception $e) {
        // Rollback transaction jika ada error
        try {
            $conn->rollBack();
            error_log("=== TRANSACTION ROLLED BACK ===");
        } catch (Exception $rollbackError) {
            error_log("Rollback error: " . $rollbackError->getMessage());
        }
        
        error_log("Error processing manual schedule data: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error memproses data: ' . $e->getMessage()
        ];
    }
}

/**
 * Generate success message
 */
function generateManualScheduleSuccessMessage($results, $successRate) {
    $message = "🎉 HASIL INPUT MANUAL BERHASIL!\n\n";
    $message .= "✅ Berhasil: {$results['success']} jadwal dari {$results['total_processed']} data ({$successRate}%)\n";
    $message .= "🤖 Booking dibuat: {$results['total_bookings']} booking otomatis\n";
    
    if ($results['errors'] > 0) {
        $message .= "❌ Error: {$results['errors']} baris gagal\n";
        if (!empty($results['error_details'])) {
            $message .= "\nDetail Error:\n" . implode("\n", array_slice($results['error_details'], 0, 3));
            if (count($results['error_details']) > 3) {
                $message .= "\n... dan " . (count($results['error_details']) - 3) . " error lainnya";
            }
        }
    }
    
    $message .= "\n\n🎯 Semua jadwal yang berhasil sudah aktif dan siap digunakan!";
    
    return $message;
}
?>