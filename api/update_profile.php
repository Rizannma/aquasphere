<?php
/**
 * Update User Profile
 * Updates the profile data of the currently logged-in user
 */

// Start output buffering
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers
header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'User not logged in'
    ]);
    ob_end_flush();
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    ob_end_flush();
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request data'
    ]);
    ob_end_flush();
    exit;
}

try {
    require_once 'database.php';
    
    $conn = get_db_connection();
    $user_id = $_SESSION['user_id'];
    
    // Get form data
    $email = trim($data['email'] ?? '');
    $first_name = trim($data['firstName'] ?? $data['first_name'] ?? '');
    $last_name = trim($data['lastName'] ?? $data['last_name'] ?? '');
    $gender = trim($data['gender'] ?? '');
    $date_of_birth = trim($data['birthday'] ?? $data['date_of_birth'] ?? '');
    $password = $data['password'] ?? '';
    $new_password = $data['newPassword'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($email)) {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format.';
    }
    
    if (empty($first_name)) {
        $errors['first_name'] = 'First name is required.';
    } elseif (preg_match('/\d/', $first_name) || !preg_match("/^[\p{L}\s'-]+$/u", $first_name)) {
        $errors['first_name'] = 'First name can only contain letters, spaces, apostrophes, and hyphens.';
    }
    
    if (empty($last_name)) {
        $errors['last_name'] = 'Last name is required.';
    } elseif (preg_match('/\d/', $last_name) || !preg_match("/^[\p{L}\s'-]+$/u", $last_name)) {
        $errors['last_name'] = 'Last name can only contain letters, spaces, apostrophes, and hyphens.';
    }
    
    if (empty($gender)) {
        $errors['gender'] = 'Gender is required.';
    }
    
    if (empty($date_of_birth)) {
        $errors['date_of_birth'] = 'Date of birth is required.';
    } else {
        $dob = DateTime::createFromFormat('Y-m-d', $date_of_birth);
        $errorsDate = DateTime::getLastErrors();
        if (!$dob || $errorsDate['warning_count'] > 0 || $errorsDate['error_count'] > 0) {
            $errors['date_of_birth'] = 'Invalid birthday format.';
        } else {
            $today = new DateTime();
            $age = $today->diff($dob)->y;
            if ($age < 18) {
                $errors['date_of_birth'] = 'You must be at least 18 years old.';
            }
        }
    }
    
    // If passwords are provided, validate them
    if (!empty($password) || !empty($new_password)) {
        if (empty($password)) {
            $errors['password'] = 'Current password is required to change password.';
        } elseif (empty($new_password)) {
            $errors['newPassword'] = 'New password is required.';
        } elseif (strlen($new_password) < 8) {
            $errors['newPassword'] = 'New password must be at least 8 characters.';
        } else {
            // Verify current password
            $query = "SELECT password_hash FROM users WHERE id = ?";
            $result = execute_sql($conn, $query, [$user_id]);
            
            if ($GLOBALS['use_postgres']) {
                $user = pg_fetch_assoc($result);
            } else {
                $user = $result->fetchArray(SQLITE3_ASSOC);
            }
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors['password'] = 'Current password is incorrect.';
            }
        }
    }
    
    // If there are validation errors, return them
    if (!empty($errors)) {
        close_connection($conn);
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'errors' => $errors
        ]);
        ob_end_flush();
        exit;
    }
    
    // Build update query
    $update_fields = [];
    $update_params = [];
    
    $update_fields[] = "email = ?";
    $update_params[] = $email;
    
    $update_fields[] = "first_name = ?";
    $update_params[] = $first_name;
    
    $update_fields[] = "last_name = ?";
    $update_params[] = $last_name;
    
    $update_fields[] = "gender = ?";
    $update_params[] = $gender;
    
    $update_fields[] = "date_of_birth = ?";
    $update_params[] = $date_of_birth;
    
    // Update password if provided
    if (!empty($new_password)) {
        $update_fields[] = "password_hash = ?";
        $update_params[] = password_hash($new_password, PASSWORD_DEFAULT);
    }
    
    $update_params[] = $user_id;
    
    $query = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
    
    $result = execute_sql($conn, $query, $update_params);
    
    if ($result !== false) {
        close_connection($conn);
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully!'
        ]);
        ob_end_flush();
        exit;
    } else {
        $error = $GLOBALS['use_postgres'] ? pg_last_error($conn) : 'SQLite error';
        error_log("Failed to update user profile: $error");
        close_connection($conn);
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update profile. Please try again.'
        ]);
        ob_end_flush();
        exit;
    }
} catch (Exception $e) {
    if (isset($conn)) {
        close_connection($conn);
    }
    error_log("Error updating profile: " . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    ob_end_flush();
    exit;
}
?>

