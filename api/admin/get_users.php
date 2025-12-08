<?php
/**
 * Get All Users API
 * Returns list of all users for admin management
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

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$page = intval($_GET['page'] ?? 1);
$limit = intval($_GET['limit'] ?? 10);
$offset = ($page - 1) * $limit;

// Build query
$where_clause = "1=1";
$params = [];

if (!empty($search)) {
    $where_clause .= " AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param, $search_param];
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM users WHERE $where_clause";
$count_result = execute_sql($conn, $count_query, $params);
if ($GLOBALS['use_postgres']) {
    $count_row = pg_fetch_assoc($count_result);
    $total = $count_row['total'];
} else {
    $count_row = $count_result->fetchArray(SQLITE3_ASSOC);
    $total = $count_row['total'];
}

// Get users
if ($GLOBALS['use_postgres']) {
    $query = "SELECT id, username, email, first_name, last_name, gender, date_of_birth, is_admin, created_at, last_login 
              FROM users 
              WHERE $where_clause 
              ORDER BY created_at DESC 
              LIMIT $limit OFFSET $offset";
} else {
    $query = "SELECT id, username, email, first_name, last_name, gender, date_of_birth, is_admin, created_at, last_login 
              FROM users 
              WHERE $where_clause 
              ORDER BY created_at DESC 
              LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
}

$result = execute_sql($conn, $query, $params);

$users = [];
if ($result !== false) {
    if ($GLOBALS['use_postgres']) {
        while ($row = pg_fetch_assoc($result)) {
            $users[] = $row;
        }
    } else {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
    }
}

close_connection($conn);

echo json_encode([
    'success' => true,
    'users' => $users,
    'pagination' => [
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]
]);
?>

