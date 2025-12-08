<?php
/**
 * Get persisted user state (cart, delivery address)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

$user_id = $_SESSION['user_id'];

init_db();
$conn = get_db_connection();

$query = "SELECT saved_cart, delivery_address FROM users WHERE id = ?";
$result = execute_sql($conn, $query, [$user_id]);

$saved_cart = [];
$delivery_address = null;

if ($result !== false) {
    if ($GLOBALS['use_postgres']) {
        $row = pg_fetch_assoc($result);
    } else {
        $row = $result->fetchArray(SQLITE3_ASSOC);
    }
    if ($row) {
        if (!empty($row['saved_cart'])) {
            $decoded = json_decode($row['saved_cart'], true);
            if (is_array($decoded)) $saved_cart = $decoded;
        }
        if (!empty($row['delivery_address'])) {
            $decodedAddr = json_decode($row['delivery_address'], true);
            if ($decodedAddr !== null) $delivery_address = $decodedAddr;
        }
    }
}

close_connection($conn);

echo json_encode([
    'success' => true,
    'cart' => $saved_cart,
    'delivery_address' => $delivery_address
]);
?>

