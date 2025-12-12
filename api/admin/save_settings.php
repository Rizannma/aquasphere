<?php
/**
 * Save System Settings API
 */

// Start output buffering to catch any unexpected output
ob_start();

// Set headers first
header('Content-Type: application/json');

// Suppress any warnings/notices that might break JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../database.php';
require_once '../sanitize.php';

// Check if user is admin (you should implement proper session/auth check)
// For now, we'll just save the settings

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = sanitize_array_recursive(json_decode(file_get_contents('php://input'), true));

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

$user_id = 1; // TODO: Get from session
$api_key_saved = false;

// Save all settings
$settings_to_save = [
    'brevo_sender_email',
    'brevo_sender_name',
    'enable_email_notifications',
    'site_name',
    'site_description',
    'max_users',
    'session_timeout',
    'password_min_length',
    'max_login_attempts',
    'enable_two_factor'
];

$saved_settings = [];
$errors = [];

// Initialize database to ensure table exists
init_db();

foreach ($settings_to_save as $key) {
    if (isset($data[$key])) {
        $value = $data[$key];
        // Strings: enforce safe text; numerics/bools are fine as-is
        if (is_string($value)) {
            $value = assert_safe_string($value, $key, 255);
        }
        error_log("Saving setting: $key = " . (in_array($key, ['brevo_api_key']) ? '***HIDDEN***' : $value));
        $result = update_system_setting($key, $value, $user_id);
        if ($result) {
            $saved_settings[] = $key;
            // Verify it was saved
            $verify = get_system_setting($key);
            if ($verify !== $value) {
                error_log("Setting $key verification failed - expected: " . (in_array($key, ['brevo_api_key']) ? '***HIDDEN***' : $value) . ", got: " . (in_array($key, ['brevo_api_key']) ? '***HIDDEN***' : $verify));
                $errors[] = "Setting $key was not saved correctly (expected: " . strlen($value) . " chars, got: " . strlen($verify) . " chars)";
            } else {
                error_log("Setting $key saved and verified successfully");
            }
        } else {
            error_log("Failed to save setting: $key");
            $errors[] = "Failed to save setting: $key";
        }
    }
}

// Handle API key separately (only save if provided)
if (isset($data['brevo_api_key']) && !empty($data['brevo_api_key']) && $data['brevo_api_key'] !== '***SAVED***') {
    $api_key_value = trim($data['brevo_api_key']);
    error_log("Saving API key (length: " . strlen($api_key_value) . ")");
    $result = update_system_setting('brevo_api_key', $api_key_value, $user_id);
    if ($result) {
        $api_key_saved = true;
        $saved_settings[] = 'brevo_api_key';
        // Verify API key was saved (check length, not value for security)
        $saved_key = get_system_setting('brevo_api_key');
        error_log("API key verification - saved length: " . strlen($saved_key) . ", expected length: " . strlen($api_key_value));
        if (empty($saved_key) || strlen($saved_key) !== strlen($api_key_value)) {
            error_log("API key verification FAILED");
            $errors[] = "API key was not saved correctly (expected length: " . strlen($api_key_value) . ", got: " . strlen($saved_key) . ")";
        } else {
            error_log("API key saved and verified successfully");
        }
    } else {
        error_log("Failed to save API key");
        $errors[] = "Failed to save API key";
    }
}

// Log the save operation
error_log("Settings saved: " . implode(', ', $saved_settings));
if (!empty($errors)) {
    error_log("Settings save errors: " . implode(', ', $errors));
}

// Clear any output buffer before sending JSON
ob_clean();

if (empty($errors)) {
    echo json_encode([
        'success' => true,
        'message' => 'Settings saved successfully',
        'api_key_saved' => $api_key_saved,
        'saved_count' => count($saved_settings)
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Some settings may not have been saved correctly',
        'errors' => $errors,
        'saved_count' => count($saved_settings)
    ]);
}

// End output buffering
ob_end_flush();
exit;
?>

