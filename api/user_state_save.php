<?php
/**
 * Save persisted user state (cart, delivery address)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$cart = isset($input['cart']) ? $input['cart'] : [];
$address = isset($input['delivery_address']) ? $input['delivery_address'] : null;

init_db();
$conn = get_db_connection();

// Normalize JSON strings
$cart_json = json_encode($cart);
$address_json = $address !== null ? json_encode($address) : null;

// Upsert into users
if ($GLOBALS['use_postgres']) {
    $query = "
        UPDATE users
        SET saved_cart = $1, delivery_address = $2, updated_at = CURRENT_TIMESTAMP
        WHERE id = $3
    ";
    $result = execute_sql($conn, $query, [$cart_json, $address_json, $_SESSION['user_id']]);
    $success = $result !== false;
} else {
    $query = "
        UPDATE users
        SET saved_cart = ?, delivery_address = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ";
    $stmtResult = execute_sql($conn, $query, [$cart_json, $address_json, $_SESSION['user_id']]);
    $success = $stmtResult !== false;
}

close_connection($conn);

if (!$success) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save state']);
    exit;
}

echo json_encode(['success' => true]);
?>

