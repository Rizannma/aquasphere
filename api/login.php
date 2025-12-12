<?php
/**
 * Login Handler for AquaSphere
 */

// Start output buffering to catch any unexpected output
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers first
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once 'sanitize.php';

// Get form data
$username = assert_safe_string($_POST['username'] ?? '', 'username', 64);
$password = $_POST['password'] ?? '';
$remember_me = isset($_POST['remember_me']) ? 1 : 0;

// Special admin login check (BEFORE requiring database)
if ($username === 'admin' && $password === 'admin123') {
    // Start session for admin
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = 0; // Special admin ID
    $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = 1;
    
    // Set remember me cookie if checked
    if ($remember_me) {
        $cookie_value = base64_encode('admin:' . hash('sha256', 'admin123'));
        setcookie('aquasphere_remember', $cookie_value, time() + (86400 * 30), '/'); // 30 days
    }
    
    // Clear any output buffer before sending JSON
    ob_clean();
    echo json_encode([
        'success' => true, 
        'message' => 'Admin login successful!',
        'redirect' => 'admin/dashboard.html'
    ]);
    ob_end_flush();
    exit;
}

// Now require database for regular users
try {
    require_once 'database.php';
    
    // Validation for regular users
    $errors = [];
    
    // Username validation
    if (empty($username)) {
        $errors['username'] = 'Username is required.';
    } elseif (strlen($username) < 4 || strlen($username) > 64) {
        $errors['username'] = 'Username must be between 4 and 64 characters long.';
    }
    
    // Password validation
    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    
    // If there are errors, return them
    if (!empty($errors)) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $errors]);
        ob_end_flush();
        exit;
    }
    
    // Verify user credentials
    $conn = get_db_connection();
    $query = "SELECT * FROM users WHERE username = ?";
    $result = execute_sql($conn, $query, [$username]);
    
    if ($GLOBALS['use_postgres']) {
        $user = pg_fetch_assoc($result);
    } else {
        $user = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    // Log for debugging
    if ($user) {
        error_log("Login attempt - Username: " . $username . ", User ID: " . $user['id'] . ", Email: " . ($user['email'] ?? 'NOT FOUND'));
        error_log("Login attempt - Stored password hash length: " . strlen($user['password_hash']));
        error_log("Login attempt - Stored password hash (first 20 chars): " . substr($user['password_hash'], 0, 20));
        $verify_result = password_verify($password, $user['password_hash']);
        error_log("Login attempt - Password verify result: " . ($verify_result ? 'TRUE' : 'FALSE'));
        
        // Also try to verify with a test hash to see if password_verify is working
        $test_hash = password_hash('test', PASSWORD_DEFAULT);
        $test_verify = password_verify('test', $test_hash);
        error_log("Login attempt - Test password_verify function: " . ($test_verify ? 'WORKING' : 'BROKEN'));
    } else {
        error_log("Login attempt - User not found for username: " . $username);
    }
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Block suspended accounts
        if (isset($user['suspended']) && intval($user['suspended']) === 1) {
            close_connection($conn);
            ob_clean();
            http_response_code(403);
            $reason = $user['suspension_reason'] ?? 'Your account is suspended.';
            echo json_encode(['success' => false, 'message' => "Account suspended: $reason"]);
            ob_end_flush();
            exit;
        }

        // Update last login time
        $updateQuery = "UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?";
        execute_sql($conn, $updateQuery, [$user['id']]);
        
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = $user['is_admin'] ?? 0;
        
        // Set remember me cookie if checked
        if ($remember_me) {
            $cookie_value = base64_encode($user['id'] . ':' . hash('sha256', $user['password_hash']));
            setcookie('aquasphere_remember', $cookie_value, time() + (86400 * 30), '/'); // 30 days
        }
        
        close_connection($conn);
        
        // Check if user is admin and redirect accordingly
        $redirect_url = ($user['is_admin'] ?? 0) ? 'admin/dashboard.html' : 'dashboard.html';
        
        // Clear any output buffer before sending JSON
        ob_clean();
        echo json_encode([
            'success' => true, 
            'message' => 'Login successful!',
            'redirect' => $redirect_url
        ]);
        ob_end_flush();
        exit;
    } else {
        close_connection($conn);
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        ob_end_flush();
        exit;
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    $error_message = $e->getMessage();
    if (empty($error_message)) {
        $error_message = 'Unknown error occurred. Check PHP error logs.';
    }
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $error_message,
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    ob_end_flush();
    exit;
}
?>

