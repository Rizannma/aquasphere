<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';
require_once 'sanitize.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];

$page = isset($_GET['page']) ? max(1, sanitize_int($_GET['page'])) : 1;
$limit = isset($_GET['limit']) ? max(1, sanitize_int($_GET['limit'])) : 50;
$offset = ($page - 1) * $limit;

init_db();
$conn = get_db_connection();

// Count total history entries for this user
if ($GLOBALS['use_postgres']) {
    $count_query = "SELECT COUNT(*) AS total FROM order_status_history h 
                    INNER JOIN orders o ON h.order_id = o.id
                    WHERE o.user_id = $1";
    $count_result = execute_sql($conn, $count_query, [$user_id]);
    $total = 0;
    if ($count_result && ($row = pg_fetch_assoc($count_result))) {
        $total = intval($row['total']);
    }

    $query = "
        SELECT 
            h.order_id,
            h.status,
            h.payment_method,
            h.created_at
        FROM order_status_history h
        INNER JOIN orders o ON h.order_id = o.id
        WHERE o.user_id = $1
        ORDER BY h.created_at DESC, h.id DESC
        LIMIT $2 OFFSET $3
    ";
    $result = execute_sql($conn, $query, [$user_id, $limit, $offset]);
    $rows = [];
    if ($result) {
        while ($r = pg_fetch_assoc($result)) {
            $rows[] = $r;
        }
    }
} else {
    $count_query = "SELECT COUNT(*) AS total FROM order_status_history h 
                    INNER JOIN orders o ON h.order_id = o.id
                    WHERE o.user_id = ?";
    $count_result = execute_sql($conn, $count_query, [$user_id]);
    $row = $count_result ? $count_result->fetchArray(SQLITE3_ASSOC) : ['total' => 0];
    $total = intval($row['total'] ?? 0);

    $query = "
        SELECT 
            h.order_id,
            h.status,
            h.payment_method,
            h.created_at
        FROM order_status_history h
        INNER JOIN orders o ON h.order_id = o.id
        WHERE o.user_id = ?
        ORDER BY h.created_at DESC, h.id DESC
        LIMIT ? OFFSET ?
    ";
    $result = execute_sql($conn, $query, [$user_id, $limit, $offset]);
    $rows = [];
    if ($result) {
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $r;
        }
    }
}

close_connection($conn);

$totalPages = max(1, ceil($total / $limit));

echo json_encode([
    'success' => true,
    'notifications' => $rows,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages
    ]
]);


