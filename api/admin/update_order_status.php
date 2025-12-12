<?php
/**
 * Update Order Status API
 * Updates order status (admin only)
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
require_once '../sanitize.php';

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
$input = sanitize_array_recursive(json_decode(file_get_contents('php://input'), true));
$order_id = sanitize_int($input['order_id'] ?? 0);
$status = sanitize_string($input['status'] ?? '', 64);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid order_id is required']);
    exit;
}

// Validate status
$valid_statuses = ['pending', 'preparing', 'shipped', 'out_for_delivery', 'delivered', 'cancelled', 'paid', 'cancellation_requested'];
if (!in_array(strtolower($status), $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status. Valid statuses: ' . implode(', ', $valid_statuses)]);
    exit;
}

// Initialize database
init_db();
$conn = get_db_connection();

// Check if order exists
$check_query = "SELECT id, user_id, payment_method FROM orders WHERE id = ?";
$check_result = execute_sql($conn, $check_query, [$order_id]);

if ($GLOBALS['use_postgres']) {
    $order_row = pg_fetch_assoc($check_result);
} else {
    $order_row = $check_result->fetchArray(SQLITE3_ASSOC);
}

if (!$order_row) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit;
}

// Update order status
$update_query = "UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
$result = execute_sql($conn, $update_query, [$status, $order_id]);

if ($result === false) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to update order status']);
    exit;
}

// Insert status history
$history_query = "INSERT INTO order_status_history (order_id, user_id, status, payment_method, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)";
$history_result = execute_sql($conn, $history_query, [$order_id, $order_row['user_id'], $status, $order_row['payment_method']]);
if ($history_result === false) {
    error_log("Failed to insert order status history: " . ($GLOBALS['use_postgres'] ? pg_last_error($conn) : "SQLite error"));
    // Don't fail the whole request if history insert fails, but log it
}

close_connection($conn);

echo json_encode([
    'success' => true,
    'message' => 'Order status updated successfully'
]);
?>

