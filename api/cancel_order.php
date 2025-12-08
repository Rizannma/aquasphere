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
$input = json_decode(file_get_contents('php://input'), true);
$order_id = intval($input['order_id'] ?? 0);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid order_id is required']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Initialize database
init_db();
$conn = get_db_connection();

// Check if order exists and belongs to user
$check_query = "SELECT id, status FROM orders WHERE id = ? AND user_id = ?";
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

// Check if order can be cancelled (only pending, preparing, or paid status)
$cancellable_statuses = ['pending', 'preparing', 'paid'];
if (!in_array(strtolower($order['status']), $cancellable_statuses)) {
    close_connection($conn);
    echo json_encode([
        'success' => false,
        'message' => 'Order cannot be cancelled. Current status: ' . $order['status']
    ]);
    exit;
}

// Update order status to cancelled
$update_query = "UPDATE orders SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?";
$result = execute_sql($conn, $update_query, [$order_id, $user_id]);

if ($result === false) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to cancel order']);
    exit;
}

close_connection($conn);

echo json_encode([
    'success' => true,
    'message' => 'Order cancelled successfully'
]);
?>

