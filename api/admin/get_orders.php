<?php
/**
 * Get All Orders API
 * Returns list of all orders for admin management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
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

// Initialize database
init_db();
$conn = get_db_connection();

// Get filter parameters
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = intval($_GET['page'] ?? 1);
$limit = intval($_GET['limit'] ?? 10);
$offset = ($page - 1) * $limit;

// Build where clause
$where_clause = "1=1";
$params = [];

if (!empty($status)) {
    $where_clause .= " AND o.status = ?";
    $params[] = $status;
}

if (!empty($search)) {
    $where_clause .= " AND (u.username LIKE ? OR u.email LIKE ? OR o.id::text LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Get total count
if ($GLOBALS['use_postgres']) {
    $count_query = "SELECT COUNT(DISTINCT o.id) as total 
                    FROM orders o 
                    LEFT JOIN users u ON o.user_id = u.id 
                    WHERE $where_clause";
} else {
    $count_query = "SELECT COUNT(DISTINCT o.id) as total 
                    FROM orders o 
                    LEFT JOIN users u ON o.user_id = u.id 
                    WHERE $where_clause";
}

$count_result = execute_sql($conn, $count_query, $params);
if ($GLOBALS['use_postgres']) {
    $count_row = pg_fetch_assoc($count_result);
    $total = $count_row['total'];
} else {
    $count_row = $count_result->fetchArray(SQLITE3_ASSOC);
    $total = $count_row['total'];
}

// Get orders with user info and items
if ($GLOBALS['use_postgres']) {
    $query = "
        SELECT 
            o.id,
            o.user_id,
            u.username,
            u.email,
            o.order_date,
            o.delivery_date,
            o.delivery_time,
            o.delivery_address,
            o.total_amount,
            o.payment_method,
            o.status,
            o.created_at,
            o.updated_at,
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
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE $where_clause
        GROUP BY o.id, u.username, u.email
        ORDER BY o.order_date DESC
        LIMIT $limit OFFSET $offset
    ";
} else {
    $query = "
        SELECT 
            o.id,
            o.user_id,
            u.username,
            u.email,
            o.order_date,
            o.delivery_date,
            o.delivery_time,
            o.delivery_address,
            o.total_amount,
            o.payment_method,
            o.status,
            o.created_at,
            o.updated_at
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE $where_clause
        ORDER BY o.order_date DESC
        LIMIT ? OFFSET ?
    ";
    $params[] = $limit;
    $params[] = $offset;
}

$result = execute_sql($conn, $query, $params);

$orders = [];
if ($result !== false) {
    if ($GLOBALS['use_postgres']) {
        while ($row = pg_fetch_assoc($result)) {
            $row['items'] = json_decode($row['items'], true);
            $row['delivery_address'] = json_decode($row['delivery_address'], true);
            $orders[] = $row;
        }
    } else {
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

echo json_encode([
    'success' => true,
    'orders' => $orders,
    'pagination' => [
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);
?>

