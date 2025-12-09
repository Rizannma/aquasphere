<?php
/**
 * Update User API
 * Updates user information (admin only)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../database.php';

// Check if user is admin
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$user_id = intval($input['user_id'] ?? 0);
$first_name = trim($input['first_name'] ?? '');
$last_name = trim($input['last_name'] ?? '');
$email = trim($input['email'] ?? '');
$gender = trim($input['gender'] ?? '');
$date_of_birth = trim($input['date_of_birth'] ?? '');
$is_admin = isset($input['is_admin']) ? intval($input['is_admin']) : null;
$new_password = trim($input['new_password'] ?? '');

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid user_id is required']);
    exit;
}

// Initialize database
init_db();
$conn = get_db_connection();

// Check if user exists
$check_query = "SELECT id FROM users WHERE id = ?";
$check_result = execute_sql($conn, $check_query, [$user_id]);

if ($GLOBALS['use_postgres']) {
    $user_exists = pg_fetch_assoc($check_result) !== false;
} else {
    $user_exists = $check_result->fetchArray(SQLITE3_ASSOC) !== false;
}

if (!$user_exists) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Build update query dynamically
$update_fields = [];
$update_params = [];

// Validate names (if provided)
if (!empty($first_name)) {
    if (preg_match('/\d/', $first_name) || !preg_match("/^[\p{L}\s'-]+$/u", $first_name)) {
        close_connection($conn);
        echo json_encode(['success' => false, 'message' => 'First name can only contain letters, spaces, apostrophes, and hyphens.']);
        exit;
    }
    $update_fields[] = "first_name = ?";
    $update_params[] = $first_name;
}

if (!empty($last_name)) {
    if (preg_match('/\d/', $last_name) || !preg_match("/^[\p{L}\s'-]+$/u", $last_name)) {
        close_connection($conn);
        echo json_encode(['success' => false, 'message' => 'Last name can only contain letters, spaces, apostrophes, and hyphens.']);
        exit;
    }
    $update_fields[] = "last_name = ?";
    $update_params[] = $last_name;
}

if (!empty($email)) {
    // Check if email is already taken by another user
    $email_check = "SELECT id FROM users WHERE email = ? AND id != ?";
    $email_result = execute_sql($conn, $email_check, [$email, $user_id]);
    
    if ($GLOBALS['use_postgres']) {
        $email_taken = pg_fetch_assoc($email_result) !== false;
    } else {
        $email_taken = $email_result->fetchArray(SQLITE3_ASSOC) !== false;
    }
    
    if ($email_taken) {
        close_connection($conn);
        echo json_encode(['success' => false, 'message' => 'Email already taken by another user']);
        exit;
    }
    
    $update_fields[] = "email = ?";
    $update_params[] = $email;
}

if (!empty($gender)) {
    $update_fields[] = "gender = ?";
    $update_params[] = $gender;
}

if (!empty($date_of_birth)) {
    $dob = DateTime::createFromFormat('Y-m-d', $date_of_birth);
    $errorsDate = DateTime::getLastErrors();
    if (!$dob || $errorsDate['warning_count'] > 0 || $errorsDate['error_count'] > 0) {
        close_connection($conn);
        echo json_encode(['success' => false, 'message' => 'Invalid birthday format.']);
        exit;
    }
    $today = new DateTime();
    $age = $today->diff($dob)->y;
    if ($age < 18) {
        close_connection($conn);
        echo json_encode(['success' => false, 'message' => 'User must be at least 18 years old.']);
        exit;
    }

    $update_fields[] = "date_of_birth = ?";
    $update_params[] = $date_of_birth;
}

if ($is_admin !== null) {
    $update_fields[] = "is_admin = ?";
    $update_params[] = $is_admin;
}

if (!empty($new_password)) {
    $strong = preg_match('/[a-z]/', $new_password) &&
              preg_match('/[A-Z]/', $new_password) &&
              preg_match('/\d/', $new_password) &&
              preg_match('/[ !"#$%&\'()*+,\-\.\/:;<=>?@\[\]^_`{|}~]/', $new_password) &&
              strlen($new_password) >= 8;
    if (!$strong) {
        close_connection($conn);
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 chars with upper, lower, number, and special character.']);
        exit;
    }
    $update_fields[] = "password_hash = ?";
    $update_params[] = password_hash($new_password, PASSWORD_DEFAULT);
}

if (empty($update_fields)) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    exit;
}

$update_params[] = $user_id;
$update_query = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?";
$result = execute_sql($conn, $update_query, $update_params);

if ($result === false) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to update user']);
    exit;
}

close_connection($conn);

echo json_encode([
    'success' => true,
    'message' => 'User updated successfully'
]);
?>

