<?php
/**
 * Suspend/Unsuspend User API
 * Admin-only endpoint to suspend an account with a reason, or lift suspension.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../database.php';
require_once '../email_service.php';

// Admin check
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin only.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$user_id = intval($input['user_id'] ?? 0);
$action = $input['action'] ?? '';
$reason = trim($input['reason'] ?? '');

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid user_id is required']);
    exit;
}

if (!in_array($action, ['suspend', 'unsuspend'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

if ($action === 'suspend' && $reason === '') {
    echo json_encode(['success' => false, 'message' => 'Suspension reason is required']);
    exit;
}

// Prevent suspending yourself
if ($user_id == ($_SESSION['user_id'] ?? -1)) {
    echo json_encode(['success' => false, 'message' => 'You cannot change suspension on your own account']);
    exit;
}

init_db();
$conn = get_db_connection();

// Fetch user
$query = "SELECT id, username, email, is_admin, suspended FROM users WHERE id = ?";
$result = execute_sql($conn, $query, [$user_id]);

if ($GLOBALS['use_postgres']) {
    $user = pg_fetch_assoc($result);
} else {
    $user = $result->fetchArray(SQLITE3_ASSOC);
}

if (!$user) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Do not allow suspending admins
if (intval($user['is_admin']) === 1) {
    close_connection($conn);
    echo json_encode(['success' => false, 'message' => 'Cannot suspend admin users']);
    exit;
}

if ($action === 'suspend') {
    $update = "UPDATE users SET suspended = 1, suspension_reason = ?, suspended_at = CURRENT_TIMESTAMP, suspension_lifted_at = NULL WHERE id = ?";
    $ok = execute_sql($conn, $update, [$reason, $user_id]);
    close_connection($conn);

    if ($ok === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to suspend user']);
        exit;
    }

    // Notify user via email (best-effort)
    send_suspension_email_brevo($user['email'], $user['username'], $reason);

    echo json_encode(['success' => true, 'message' => 'User suspended successfully']);
    exit;
}

// Unsuspend
$update = "UPDATE users SET suspended = 0, suspension_reason = NULL, suspension_lifted_at = CURRENT_TIMESTAMP WHERE id = ?";
$ok = execute_sql($conn, $update, [$user_id]);
close_connection($conn);

if ($ok === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to lift suspension']);
    exit;
}

send_unsuspension_email_brevo($user['email'], $user['username']);

echo json_encode(['success' => true, 'message' => 'Suspension lifted successfully']);
?>

