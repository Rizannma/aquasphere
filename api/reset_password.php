<?php
/**
 * Reset Password Handler for AquaSphere
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
$new_password = $input['new_password'] ?? '';
$otp_code = trim($input['otp_code'] ?? '');

$errors = [];

if (empty($email)) {
    $errors['email'] = 'Email is required.';
}

if (empty($new_password)) {
    $errors['new_password'] = 'New password is required.';
} elseif (strlen($new_password) < 8) {
    $errors['new_password'] = 'Password must be at least 8 characters.';
}

if (empty($otp_code)) {
    $errors['otp_code'] = 'Verification code is required.';
}

if (!empty($errors)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    ob_end_flush();
    exit;
}

try {
    $conn = get_db_connection();
    
    // Verify OTP first
    $reset_data = verify_password_reset_otp($email, $otp_code);
    
    if (!$reset_data) {
        close_connection($conn);
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired verification code. Please try again.']);
        ob_end_flush();
        exit;
    }
    
    // Get user to check current password
    $query = "SELECT id, password_hash FROM users WHERE id = ?";
    $result = execute_sql($conn, $query, [$reset_data['user_id']]);
    
    if ($GLOBALS['use_postgres']) {
        $user = pg_fetch_assoc($result);
    } else {
        $user = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    if (!$user) {
        close_connection($conn);
        ob_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        ob_end_flush();
        exit;
    }
    
    // Check if new password is different from current password
    if (password_verify($new_password, $user['password_hash'])) {
        close_connection($conn);
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'New password must be different from your current password.']);
        ob_end_flush();
        exit;
    }
    
    // Hash new password
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update password
    $updateQuery = "UPDATE users SET password_hash = ? WHERE id = ?";
    $updateResult = execute_sql($conn, $updateQuery, [$new_password_hash, $user['id']]);
    
    if ($updateResult === false) {
        close_connection($conn);
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to reset password. Please try again.']);
        ob_end_flush();
        exit;
    }
    
    // Clean up the reset record
    cleanup_expired_password_reset();
    
    close_connection($conn);
    
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Password has been reset successfully.']);
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

