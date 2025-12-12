<?php
/**
 * Forgot Password Handler for AquaSphere
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once 'database.php';
require_once 'sanitize.php';
require_once 'email_service.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = sanitize_email($input['email'] ?? '', 128);

if (empty($email)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email is required.']);
    ob_end_flush();
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    ob_end_flush();
    exit;
}

try {
    $conn = get_db_connection();
    
    // Check if user exists with this email
    $query = "SELECT id, username, email FROM users WHERE email = ?";
    $result = execute_sql($conn, $query, [$email]);
    
    if ($GLOBALS['use_postgres']) {
        $user = pg_fetch_assoc($result);
    } else {
        $user = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    if (!$user) {
        // Email is not registered
        close_connection($conn);
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Looks like this email isn\'t linked to an account yet.']);
        ob_end_flush();
        exit;
    }
    
    // Generate 6-digit OTP
    $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Set expiration (10 minutes from now)
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    // Log for debugging
    error_log("Forgot password - Email: " . $email . ", User ID: " . $user['id'] . ", Username: " . ($user['username'] ?? 'NOT FOUND'));
    
    // Store OTP in database
    $stored = store_password_reset_otp($email, $otp_code, $user['id'], $expires_at);
    
    if (!$stored) {
        close_connection($conn);
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to generate reset code. Please try again.']);
        ob_end_flush();
        exit;
    }
    
    // Send OTP email
    $email_sent = send_password_reset_otp_email_brevo($email, $otp_code, $user['username']);
    
    close_connection($conn);
    
    // Always return success (don't reveal if email was sent or not)
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'If an account exists with this email, a reset code has been sent.']);
    ob_end_flush();
    exit;
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
    ob_end_flush();
    exit;
}
?>

