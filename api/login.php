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

// Get form data
$username = trim($_POST['username'] ?? '');
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
    
    if ($user && password_verify($password, $user['password_hash'])) {
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
        
        echo json_encode([
            'success' => true, 
            'message' => 'Login successful!',
            'redirect' => $redirect_url
        ]);
    } else {
        close_connection($conn);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }
} catch (Exception $e) {
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
}
?>

