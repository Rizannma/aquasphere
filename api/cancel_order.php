<?php
/**
 * Cancel Order Endpoint
 * Cancels an order (only if status is pending or preparing)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';
require_once 'sanitize.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
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

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid order_id is required']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize database
init_db();
$conn = get_db_connection();

// Check if order exists and belongs to user (also fetch payment_method for history)
$check_query = "SELECT id, status, payment_method FROM orders WHERE id = ? AND user_id = ?";
$check_result = execute_sql($conn, $check_query, [$order_id, $user_id]);

if ($GLOBALS['use_postgres']) {
    $order = pg_fetch_assoc($check_result);
} else {
    $order = $check_result->fetchArray(SQLITE3_ASSOC);
}

if (!$order) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit;
}

// Check if order can be cancelled (only pending or preparing status)
$cancellable_statuses = ['pending', 'preparing'];
if (!in_array(strtolower($order['status']), $cancellable_statuses)) {
    close_connection($conn);
    echo json_encode([
        'success' => false,
        'message' => 'Order cannot be cancelled. Current status: ' . $order['status']
    ]);
    exit;
}

// If status is 'preparing', set to 'cancellation_requested' (requires admin approval)
// If status is 'pending', cancel immediately
$new_status = (strtolower($order['status']) === 'preparing') ? 'cancellation_requested' : 'cancelled';

// Update order status
$update_query = "UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?";
$result = execute_sql($conn, $update_query, [$new_status, $order_id, $user_id]);

if ($result === false) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to cancel order']);
    exit;
}

// Insert status history for notifications
$history_query = "INSERT INTO order_status_history (order_id, user_id, status, payment_method, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)";
$history_result = execute_sql($conn, $history_query, [$order_id, $user_id, $new_status, $order['payment_method'] ?? '']);
if ($history_result === false) {
    error_log('Failed to insert order_status_history for cancel_order order_id=' . $order_id);
}

close_connection($conn);

$message = ($new_status === 'cancellation_requested') 
    ? 'Cancellation request submitted. Waiting for admin approval.'
    : 'Order cancelled successfully';

echo json_encode([
    'success' => true,
    'message' => $message,
    'requires_approval' => ($new_status === 'cancellation_requested')
]);
?>

