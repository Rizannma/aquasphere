<?php
/**
 * Reset Password Handler for AquaSphere
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once 'database.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');
$new_password = $input['new_password'] ?? '';

$errors = [];

if (empty($email)) {
    $errors['email'] = 'Email is required.';
}

if (empty($new_password)) {
    $errors['new_password'] = 'New password is required.';
} elseif (strlen($new_password) < 8) {
    $errors['new_password'] = 'Password must be at least 8 characters.';
}

if (!empty($errors)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    ob_end_flush();
    exit;
}

try {
    $conn = get_db_connection();
    
    // Check if there's a verified password reset record for this email
    // (OTP was already verified in the previous step)
    // First try to find verified record
    $query = "
        SELECT * FROM password_reset 
        WHERE email = ? AND is_verified = 1 AND expires_at > CURRENT_TIMESTAMP
        ORDER BY created_at DESC
        LIMIT 1
    ";
    
    $result = execute_sql($conn, $query, [$email]);
    
    if ($GLOBALS['use_postgres']) {
        $reset_data = pg_fetch_assoc($result);
    } else {
        $reset_data = $result->fetchArray(SQLITE3_ASSOC);
    }
    
    // If no verified record, check for any recent unverified record (in case verification step was skipped)
    if (!$reset_data) {
        $query = "
            SELECT * FROM password_reset 
            WHERE email = ? AND expires_at > CURRENT_TIMESTAMP
            ORDER BY created_at DESC
            LIMIT 1
        ";
        
        $result = execute_sql($conn, $query, [$email]);
        
        if ($GLOBALS['use_postgres']) {
            $reset_data = pg_fetch_assoc($result);
        } else {
            $reset_data = $result->fetchArray(SQLITE3_ASSOC);
        }
    }
    
    if (!$reset_data) {
        close_connection($conn);
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please verify your OTP code first.']);
        ob_end_flush();
        exit;
    }
    
    // Get user to check current password - use user_id if available, otherwise use email
    $user = null;
    $user_id = null;
    
    if (!empty($reset_data['user_id'])) {
        $user_id = $reset_data['user_id'];
        // Ensure user_id is an integer for PostgreSQL
        if ($GLOBALS['use_postgres']) {
            $user_id = (int)$user_id;
        }
        
        $query = "SELECT id, password_hash FROM users WHERE id = ?";
        $result = execute_sql($conn, $query, [$user_id]);
        
        if ($GLOBALS['use_postgres']) {
            $user = pg_fetch_assoc($result);
        } else {
            $user = $result->fetchArray(SQLITE3_ASSOC);
        }
    }
    
    // Fallback to email if user_id not found or not available
    if (!$user && !empty($email)) {
        $query = "SELECT id, password_hash FROM users WHERE email = ?";
        $result = execute_sql($conn, $query, [$email]);
        
        if ($GLOBALS['use_postgres']) {
            $user = pg_fetch_assoc($result);
        } else {
            $user = $result->fetchArray(SQLITE3_ASSOC);
        }
        
        if ($user) {
            $user_id = $user['id'];
        }
    } else if ($user) {
        $user_id = $user['id'];
    }
    
    if (!$user) {
        close_connection($conn);
        ob_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        ob_end_flush();
        exit;
    }
    
    // Check if new password is different from current password
    if (password_verify($new_password, $user['password_hash'])) {
        close_connection($conn);
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'New password must be different from your current password.']);
        ob_end_flush();
        exit;
    }
    
    // Hash new password
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Log for debugging
    error_log("Password reset - User ID: " . $user_id . ", Email: " . $email);
    error_log("Password reset - New password hash length: " . strlen($new_password_hash));
    
    // Update password
    $updateQuery = "UPDATE users SET password_hash = ? WHERE id = ?";
    
    if ($GLOBALS['use_postgres']) {
        // PostgreSQL - ensure user_id is integer
        $user_id_int = (int)$user_id;
        $pg_query = "UPDATE users SET password_hash = $1 WHERE id = $2";
        $updateResult = pg_query_params($conn, $pg_query, [$new_password_hash, $user_id_int]);
        
        if ($updateResult === false) {
            $error = pg_last_error($conn);
            error_log("Password update error: $error");
            close_connection($conn);
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reset password. Please try again.']);
            ob_end_flush();
            exit;
        }
        
        // Check if any rows were affected
        $rowsAffected = pg_affected_rows($updateResult);
        error_log("Password reset - Rows affected: " . $rowsAffected);
        
        if ($rowsAffected === 0) {
            error_log("Password reset - WARNING: No rows updated for user ID: " . $user['id']);
            close_connection($conn);
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reset password. No rows updated.']);
            ob_end_flush();
            exit;
        }
        
        // Verify the update by fetching the user again
        $verifyQuery = "SELECT password_hash FROM users WHERE id = $1";
        $verifyResult = pg_query_params($conn, $verifyQuery, [$user_id_int]);
        if ($verifyResult) {
            $verifyUser = pg_fetch_assoc($verifyResult);
            if ($verifyUser && password_verify($new_password, $verifyUser['password_hash'])) {
                error_log("Password reset - SUCCESS: Password verified after update");
            } else {
                error_log("Password reset - ERROR: Password verification failed after update");
            }
        }
    } else {
        // SQLite
        $stmt = $conn->prepare($updateQuery);
        $stmt->bindValue(1, $new_password_hash);
        $stmt->bindValue(2, $user_id);
        $updateResult = $stmt->execute();
        
        if ($updateResult === false) {
            error_log("Password update error: " . $conn->lastErrorMsg());
            close_connection($conn);
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reset password. Please try again.']);
            ob_end_flush();
            exit;
        }
        
        // Check if any rows were affected
        $rowsAffected = $conn->changes();
        error_log("Password reset - Rows affected: " . $rowsAffected);
        
        if ($rowsAffected === 0) {
            error_log("Password reset - WARNING: No rows updated for user ID: " . $user['id']);
            close_connection($conn);
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reset password. No rows updated.']);
            ob_end_flush();
            exit;
        }
        
        // Verify the update by fetching the user again
        $verifyStmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
        $verifyStmt->bindValue(1, $user_id);
        $verifyResult = $verifyStmt->execute();
        if ($verifyResult) {
            $verifyUser = $verifyResult->fetchArray(SQLITE3_ASSOC);
            if ($verifyUser && password_verify($new_password, $verifyUser['password_hash'])) {
                error_log("Password reset - SUCCESS: Password verified after update");
            } else {
                error_log("Password reset - ERROR: Password verification failed after update");
            }
        }
    }
    
    // Clean up the reset record
    cleanup_expired_password_reset();
    
    close_connection($conn);
    
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Password has been reset successfully.']);
    ob_end_flush();
    exit;
    
} catch (Exception $e) {
    error_log("Password reset error: " . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.', 'debug' => $e->getMessage()]);
    ob_end_flush();
    exit;
}
?>

