<?php
/**
 * Verify Password Reset OTP Handler for AquaSphere
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

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');
$otp_code = trim($input['otp_code'] ?? '');

if (empty($email) || empty($otp_code)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email and OTP code are required.']);
    ob_end_flush();
    exit;
}

if (strlen($otp_code) !== 6 || !ctype_digit($otp_code)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code. Please try again.']);
    ob_end_flush();
    exit;
}

try {
    // Verify OTP
    $reset_data = verify_password_reset_otp($email, $otp_code);
    
    if (!$reset_data) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code. Please try again.']);
        ob_end_flush();
        exit;
    }
    
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Verification code is valid.']);
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

