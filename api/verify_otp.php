<?php
/**
 * OTP Verification Handler
 */

// Start output buffering to catch any unexpected output
ob_start();

// Set headers first
header('Content-Type: application/json');

// Suppress any warnings/notices that might break JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'database.php';
require_once 'sanitize.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$email = sanitize_email($data['email'] ?? '', 128);
$otp_code = assert_safe_string($data['otp_code'] ?? '', 'otp_code', 16);

// Validation
if (empty($email)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    ob_end_flush();
    exit;
}

if (empty($otp_code)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Verification code is required']);
    ob_end_flush();
    exit;
}

try {
    // Initialize database first
    init_db();

    // Verify OTP
    $user_data = verify_otp_code($email, $otp_code);

    // Clear any output buffer before sending JSON
    ob_clean();
} catch (Exception $e) {
    ob_clean();
    error_log("Error in verify_otp.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired verification code. Please try again.'
    ]);
    ob_end_flush();
    exit;
}

if ($user_data) {
    try {
        // Create the user account (password is already hashed)
        $conn = get_db_connection();
        $query = "
            INSERT INTO users (username, password_hash, email, first_name, last_name, gender, date_of_birth, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ";
        
        $result = execute_sql($conn, $query, [
            $user_data['username'],
            $user_data['password_hash'],
            $email,
            $user_data['first_name'],
            $user_data['last_name'],
            $user_data['gender'],
            $user_data['date_of_birth']
        ]);
        
        if ($result !== false) {
            close_connection($conn);
            // Clean up session
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            unset($_SESSION['pending_email']);
            unset($_SESSION['pending_username']);
            unset($_SESSION['dev_otp']);
            
            // Clean up expired OTP records
            cleanup_expired_otp();
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Registration successful! Please log in.'
            ]);
            ob_end_flush();
            exit;
        } else {
            close_connection($conn);
            ob_clean();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Registration failed. Please try again.'
            ]);
            ob_end_flush();
            exit;
        }
    } catch (Exception $e) {
        error_log("Error creating user in verify_otp.php: " . $e->getMessage());
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired verification code. Please try again.'
        ]);
        ob_end_flush();
        exit;
    }
} else {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired verification code. Please try again.'
    ]);
    ob_end_flush();
    exit;
}
?>

