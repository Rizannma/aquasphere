<?php
/**
 * Delete User API
 * Deletes a user (admin only)
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

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid user_id is required']);
    exit;
}

// Prevent deleting yourself
if ($user_id == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
    exit;
}

// Initialize database
init_db();
$conn = get_db_connection();

// Check if user exists
$check_query = "SELECT id, is_admin FROM users WHERE id = ?";
$check_result = execute_sql($conn, $check_query, [$user_id]);

if ($GLOBALS['use_postgres']) {
    $user = pg_fetch_assoc($check_result);
} else {
    $user = $check_result->fetchArray(SQLITE3_ASSOC);
}

if (!$user) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Prevent deleting admin users (optional - you can remove this if you want to allow)
if ($user['is_admin'] == 1) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Cannot delete admin users']);
    exit;
}

// Delete user (cascade will handle related records)
$delete_query = "DELETE FROM users WHERE id = ?";
$result = execute_sql($conn, $delete_query, [$user_id]);

if ($result === false) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
    exit;
}

close_connection($conn);

echo json_encode([
    'success' => true,
    'message' => 'User deleted successfully'
]);
?>

