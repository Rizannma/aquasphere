<?php
/**
 * Resend OTP Handler
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
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$email = sanitize_email($data['email'] ?? '', 128);

// Validation
if (empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

// Check if email has pending registration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$pending_email = $_SESSION['pending_email'] ?? '';

if (empty($pending_email) || $pending_email !== $email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No pending registration found for this email']);
    exit;
}

// Initialize database first
init_db();

// Check if pending OTP exists
$pending_otp = get_pending_otp($email);

if (!$pending_otp) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No pending registration found. Please register again.']);
    ob_end_flush();
    exit;
}

// Generate new OTP
$otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
$expires_at = date('Y-m-d H:i:s', time() + (10 * 60));

// Update OTP
$updated = update_otp_code($email, $otp_code, $expires_at);

if (!$updated) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update verification code. Please try again.']);
    ob_end_flush();
    exit;
}

// Send OTP email
require_once 'email_service.php';
$username = $_SESSION['pending_username'] ?? $pending_otp['username'];
$email_sent = send_otp_email_brevo($email, $otp_code, $username);

// Check if in development mode
$is_development = empty(get_system_setting('brevo_api_key'));

// Clear any output buffer before sending JSON
ob_clean();

if ($email_sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Verification code has been resent to your email'
    ]);
} else {
    if ($is_development) {
        // Development mode: return OTP in response
        $_SESSION['dev_otp'] = $otp_code;
        echo json_encode([
            'success' => true,
            'message' => 'Email service not configured. Check server logs for OTP code.',
            'dev_mode' => true,
            'otp' => $otp_code
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send verification email. Please try again.'
        ]);
    }
}

// End output buffering
ob_end_flush();
exit;
?>

