<?php
/**
 * Registration Handler for AquaSphere
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

// Get form data
$username = assert_safe_string($_POST['username'] ?? '', 'username', 64);
$email = sanitize_email($_POST['email'] ?? '', 128);
$first_name = assert_safe_string($_POST['first_name'] ?? '', 'first_name', 128);
$last_name = assert_safe_string($_POST['last_name'] ?? '', 'last_name', 128);
$gender = assert_safe_string($_POST['gender'] ?? '', 'gender', 32);
$birthday = assert_safe_string($_POST['birthday'] ?? '', 'birthday', 32);
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// Validation
$errors = [];

// Username validation
if (empty($username)) {
    $errors['username'] = 'Username is required.';
} elseif (strlen($username) < 4 || strlen($username) > 64) {
    $errors['username'] = 'Username must be between 4 and 64 characters long.';
} else {
    // Check if username exists
    $existing_user = get_user_by_username($username);
    if ($existing_user) {
        $errors['username'] = 'Username already exists.';
    }
}

// Email validation
if (empty($email)) {
    $errors['email'] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email.';
} else {
    // Check if email exists
    $existing_email = get_user_by_email($email);
    if ($existing_email) {
        $errors['email'] = 'Email already exists.';
    }
}

// First name validation
if (empty($first_name)) {
    $errors['first_name'] = 'First name is required.';
} elseif (preg_match('/\d/', $first_name) || !preg_match("/^[\p{L}\s'-]+$/u", $first_name)) {
    $errors['first_name'] = 'First name can only contain letters, spaces, apostrophes, and hyphens.';
}

// Last name validation
if (empty($last_name)) {
    $errors['last_name'] = 'Last name is required.';
} elseif (preg_match('/\d/', $last_name) || !preg_match("/^[\p{L}\s'-]+$/u", $last_name)) {
    $errors['last_name'] = 'Last name can only contain letters, spaces, apostrophes, and hyphens.';
}

// Gender validation
if (empty($gender)) {
    $errors['gender'] = 'Gender is required.';
} elseif (!in_array($gender, ['male', 'female'])) {
    $errors['gender'] = 'Invalid gender selection.';
}

// Birthday validation
if (empty($birthday)) {
    $errors['birthday'] = 'Birthday is required.';
} else {
    $dob = DateTime::createFromFormat('Y-m-d', $birthday);
    $errorsDate = DateTime::getLastErrors();
    if (!$dob || $errorsDate['warning_count'] > 0 || $errorsDate['error_count'] > 0) {
        $errors['birthday'] = 'Invalid birthday format.';
    } else {
        $today = new DateTime();
        $age = $today->diff($dob)->y;
        if ($age < 18) {
            $errors['birthday'] = 'You must be at least 18 years old.';
        }
    }
}

// Password validation
if (empty($password)) {
    $errors['password'] = 'Password is required.';
} else {
    $strong = preg_match('/[a-z]/', $password) &&
              preg_match('/[A-Z]/', $password) &&
              preg_match('/\d/', $password) &&
              preg_match('/[ !"#$%&\'()*+,\-\.\/:;<=>?@\[\]^_`{|}~]/', $password) &&
              strlen($password) >= 8;
    if (!$strong) {
        $errors['password'] = 'Password must be at least 8 characters and include upper, lower, number, and special character.';
    }
}

// Confirm password validation
if (empty($confirm_password)) {
    $errors['confirm_password'] = 'Password confirmation is required.';
} elseif ($password !== $confirm_password) {
    $errors['confirm_password'] = 'Passwords do not match.';
}

// If there are errors, return them
if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Generate OTP code (6 digits)
$otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

// Set expiry time (10 minutes from now)
$expires_at = date('Y-m-d H:i:s', time() + (10 * 60));

// Hash password
$password_hash = password_hash($password, PASSWORD_DEFAULT);

// Store OTP verification data
$otp_stored = store_otp_verification(
    $email,
    $otp_code,
    $username,
    $password_hash,
    $first_name,
    $last_name,
    $gender,
    $birthday,
    $expires_at
);

if (!$otp_stored) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to store verification data. Please try again.']);
    exit;
}

// Send OTP email
require_once 'email_service.php';
$email_sent = send_otp_email_brevo($email, $otp_code, $username);

// Store email in session for verification page (even if email failed, for development)
session_start();
$_SESSION['pending_email'] = $email;
$_SESSION['pending_username'] = $username;

// Clear any output buffer before sending JSON
ob_clean();

if ($email_sent) {
    echo json_encode([
        'success' => true, 
        'message' => 'Verification code sent to your email!',
        'redirect' => 'verify.html'
    ]);
} else {
    // Email service not configured - still allow registration but show OTP in response for development
    // In production, this should be an error, but for development we'll proceed
    $is_development = empty(get_system_setting('brevo_api_key'));
    
    if ($is_development) {
        // Development mode: return OTP in response (will be shown on verify page)
        $_SESSION['dev_otp'] = $otp_code; // Store in session for verify page to display
        echo json_encode([
            'success' => true, 
            'message' => 'Email service not configured. Check server logs for OTP code.',
            'redirect' => 'verify.html',
            'dev_mode' => true,
            'otp' => $otp_code // Include OTP for development
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to send verification email. Please try again or contact support.'
        ]);
    }
}

// End output buffering
ob_end_flush();
exit;
?>

