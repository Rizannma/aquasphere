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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');
$otp_code = trim($data['otp_code'] ?? '');

// Validation
if (empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

if (empty($otp_code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Verification code is required']);
    exit;
}

// Initialize database first
init_db();

// Verify OTP
$user_data = verify_otp_code($email, $otp_code);

// Clear any output buffer before sending JSON
ob_clean();

if ($user_data) {
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
        session_start();
        unset($_SESSION['pending_email']);
        unset($_SESSION['pending_username']);
        unset($_SESSION['dev_otp']);
        
        // Clean up expired OTP records
        cleanup_expired_otp();
        
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Please log in.'
        ]);
    } else {
        close_connection($conn);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Registration failed. Please try again.'
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired verification code. Please try again.'
    ]);
}

// End output buffering
ob_end_flush();
exit;
?>

