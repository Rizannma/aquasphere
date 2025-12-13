<?php
/**
 * Get Orders Endpoint
 * Retrieves all orders for the current user
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
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

$user_id = $_SESSION['user_id'];

// Initialize database
init_db();
$conn = get_db_connection();

// Pagination inputs
$page = isset($_GET['page']) ? max(1, sanitize_int($_GET['page'])) : 1;
$limit = isset($_GET['limit']) ? max(1, sanitize_int($_GET['limit'])) : 10;
$offset = ($page - 1) * $limit;

// Get total count first
if ($GLOBALS['use_postgres']) {
    $count_query = "SELECT COUNT(*) AS total FROM orders o WHERE o.user_id = $1";
    $count_result = execute_sql($conn, $count_query, [$user_id]);
    $total = 0;
    if ($count_result && ($row = pg_fetch_assoc($count_result))) {
        $total = intval($row['total']);
    }

    $query = "
        SELECT 
            o.id,
            o.order_date,
            o.delivery_date,
            o.delivery_time,
            o.delivery_address,
            o.delivery_fee,
            o.delivery_date_range,
            o.total_amount,
            o.payment_method,
            o.status,
            o.created_at,
            o.updated_at,
            (SELECT created_at 
             FROM order_status_history 
             WHERE order_id = o.id AND status = 'delivered' 
             ORDER BY created_at DESC LIMIT 1) as delivered_at,
            COALESCE(
                json_agg(
                    json_build_object(
                        'id', oi.id,
                        'product_name', oi.product_name,
                        'product_price', oi.product_price,
                        'quantity', oi.quantity,
                        'subtotal', oi.subtotal
                    )
                ) FILTER (WHERE oi.id IS NOT NULL),
                '[]'::json
            ) as items
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.user_id = $1
        GROUP BY o.id
        ORDER BY o.order_date DESC
        LIMIT $2 OFFSET $3
    ";
    $result = execute_sql($conn, $query, [$user_id, $limit, $offset]);
    
    $orders = [];
    if ($result !== false) {
        while ($row = pg_fetch_assoc($result)) {
            $row['items'] = json_decode($row['items'], true);
            $row['delivery_address'] = json_decode($row['delivery_address'], true);
            $orders[] = $row;
        }
    }
} else {
    // SQLite version
    $count_query = "SELECT COUNT(*) AS total FROM orders o WHERE o.user_id = ?";
    $count_result = execute_sql($conn, $count_query, [$user_id]);
    $total = 0;
    if ($count_result && ($row = $count_result->fetchArray(SQLITE3_ASSOC))) {
        $total = intval($row['total']);
    }

    $query = "
        SELECT 
            o.id,
            o.order_date,
            o.delivery_date,
            o.delivery_time,
            o.delivery_address,
            o.delivery_fee,
            o.delivery_date_range,
            o.total_amount,
            o.payment_method,
            o.status,
            o.created_at,
            o.updated_at,
            (SELECT created_at 
             FROM order_status_history 
             WHERE order_id = o.id AND status = 'delivered' 
             ORDER BY created_at DESC LIMIT 1) as delivered_at
        FROM orders o
        WHERE o.user_id = ?
        ORDER BY o.order_date DESC
        LIMIT ? OFFSET ?
    ";
    $result = execute_sql($conn, $query, [$user_id, $limit, $offset]);
    
    $orders = [];
    if ($result !== false) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Get items for this order
            $items_query = "SELECT id, product_name, product_price, quantity, subtotal FROM order_items WHERE order_id = ?";
            $items_result = execute_sql($conn, $items_query, [$row['id']]);
            
            $items = [];
            if ($items_result !== false) {
                while ($item = $items_result->fetchArray(SQLITE3_ASSOC)) {
                    $items[] = $item;
                }
            }
            
            $row['items'] = $items;
            $row['delivery_address'] = json_decode($row['delivery_address'], true);
            $orders[] = $row;
        }
    }
}

close_connection($conn);

$totalPages = max(1, ceil($total / $limit));

echo json_encode([
    'success' => true,
    'orders' => $orders,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages
    ]
]);
?>

