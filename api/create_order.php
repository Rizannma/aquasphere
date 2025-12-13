<?php
/**
 * Create Order Endpoint
 * Creates a new order in the database
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'database.php';
require_once 'sanitize.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = sanitize_array_recursive(json_decode(file_get_contents('php://input'), true));

// Validate required fields
$user_id = sanitize_int($input['user_id'] ?? 0);
$items = $input['items'] ?? [];
$delivery_address = $input['delivery_address'] ?? null;
$payment_method = assert_safe_string($input['payment_method'] ?? 'COD', 'payment_method', 32);
$delivery_date = assert_safe_string($input['delivery_date'] ?? null, 'delivery_date', 32);
$delivery_time = assert_safe_string($input['delivery_time'] ?? null, 'delivery_time', 32);

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid user_id is required']);
    exit;
}

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Order items are required']);
    exit;
}

if (!$delivery_address) {
    echo json_encode(['success' => false, 'message' => 'Delivery address is required']);
    exit;
}

// Calculate total
$subtotal = 0;
foreach ($items as $item) {
    $subtotal += floatval($item['price']) * intval($item['quantity']);
}

// Get delivery fee from request, or default to 50
$delivery_fee = isset($input['delivery_fee']) ? floatval($input['delivery_fee']) : 50;
$total_amount = $subtotal + $delivery_fee;

// Initialize database
init_db();
$conn = get_db_connection();

// Check if paymongo_source_id column exists, add if not
$check_column_query = $GLOBALS['use_postgres'] 
    ? "SELECT column_name FROM information_schema.columns WHERE table_name='orders' AND column_name='paymongo_source_id'"
    : "PRAGMA table_info(orders)";

$column_check = execute_sql($conn, $check_column_query);
$column_exists = false;

if ($GLOBALS['use_postgres']) {
    $column_exists = pg_fetch_assoc($column_check) !== false;
} else {
    while ($row = $column_check->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'paymongo_source_id') {
            $column_exists = true;
            break;
        }
    }
}

if (!$column_exists) {
    $alter_query = "ALTER TABLE orders ADD COLUMN paymongo_source_id " . get_text_type();
    execute_sql($conn, $alter_query);
}

// Check if delivery_fee column exists, add if not
$check_delivery_fee_query = $GLOBALS['use_postgres'] 
    ? "SELECT column_name FROM information_schema.columns WHERE table_name='orders' AND column_name='delivery_fee'"
    : "PRAGMA table_info(orders)";

$delivery_fee_check = execute_sql($conn, $check_delivery_fee_query);
$delivery_fee_exists = false;

if ($GLOBALS['use_postgres']) {
    $delivery_fee_exists = pg_fetch_assoc($delivery_fee_check) !== false;
} else {
    while ($row = $delivery_fee_check->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'delivery_fee') {
            $delivery_fee_exists = true;
            break;
        }
    }
}

if (!$delivery_fee_exists) {
    $alter_query = "ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10,2) DEFAULT 50.00";
    execute_sql($conn, $alter_query);
}

// Create order
$query = "INSERT INTO orders (user_id, delivery_date, delivery_time, delivery_address, delivery_fee, total_amount, payment_method, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";

$result = execute_sql($conn, $query, [
    $user_id,
    $delivery_date,
    $delivery_time,
    json_encode($delivery_address), // Store address as JSON
    $delivery_fee,
    $total_amount,
    $payment_method
]);

if ($result === false) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to create order']);
    exit;
}

// Get order ID
$order_id = last_insert_id($conn, 'orders');

// Insert order items
foreach ($items as $item) {
    $item_query = "INSERT INTO order_items (order_id, product_name, product_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?)";
    $item_price = floatval($item['price']);
    $item_quantity = intval($item['quantity']);
    $item_subtotal = $item_price * $item_quantity;
    
    $product_name = assert_safe_string($item['name'] ?? $item['product_name'] ?? 'Unknown Product', 'product_name', 255);
    execute_sql($conn, $item_query, [
        $order_id,
        $product_name,
        $item_price,
        $item_quantity,
        $item_subtotal
    ]);
}

// Insert initial "pending" status into history
$history_query = "INSERT INTO order_status_history (order_id, user_id, status, payment_method, created_at) VALUES (?, ?, 'pending', ?, CURRENT_TIMESTAMP)";
$history_result = execute_sql($conn, $history_query, [$order_id, $user_id, $payment_method]);
if ($history_result === false) {
    error_log("Failed to insert order status history: " . ($GLOBALS['use_postgres'] ? pg_last_error($conn) : "SQLite error"));
}

close_connection($conn);

echo json_encode([
    'success' => true,
    'order_id' => $order_id,
    'total_amount' => $total_amount,
    'message' => 'Order created successfully'
]);
?>

