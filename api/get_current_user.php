<?php
/**
 * Get Current User Endpoint
 * Returns the currently logged-in user from session
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Return user data
echo json_encode([
    'success' => true,
    'user' => [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'is_admin' => $_SESSION['is_admin'] ?? 0
    ]
]);
?>
