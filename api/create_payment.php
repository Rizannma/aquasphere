<?php
/**
 * PayMongo Payment Source Creation
 * Creates a GCash payment source via PayMongo API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Load environment variables from .env file
require_once 'load_env.php';
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
$amount = sanitize_float($input['amount'] ?? 0);
$order_id = sanitize_int($input['order_id'] ?? null);
$redirect_url = assert_safe_string($input['redirect_url'] ?? null, 'redirect_url', 512);
$link_order = !empty($input['link_order']);
$source_id = assert_safe_string($input['source_id'] ?? null, 'source_id', 128);

// If linking order, handle that separately
if ($link_order && $order_id && $source_id) {
    $conn = get_db_connection();
    init_db();
    
    // Check if paymongo_source_id column exists
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
    
    // Update order with payment source ID
    $update_query = "UPDATE orders SET paymongo_source_id = ? WHERE id = ?";
    execute_sql($conn, $update_query, [$source_id, $order_id]);
    close_connection($conn);
    
    echo json_encode([
        'success' => true,
        'message' => 'Order linked to payment source'
    ]);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount']);
    exit;
}

if (!$redirect_url) {
    echo json_encode(['success' => false, 'message' => 'Redirect URL is required']);
    exit;
}

// PayMongo API Configuration
// Load from environment variable (set in .env file or server environment)
// Check multiple sources for the key
$paymongo_secret_key = $_ENV['PAYMONGO_SECRET_KEY'] ?? getenv('PAYMONGO_SECRET_KEY') ?? $_SERVER['PAYMONGO_SECRET_KEY'] ?? null;

// Log for debugging (without exposing the key)
if (!$paymongo_secret_key) {
    error_log("PayMongo secret key not found. Checked: _ENV, getenv(), _SERVER");
    echo json_encode([
        'success' => false,
        'message' => 'PayMongo secret key not configured. Please set PAYMONGO_SECRET_KEY in Railway environment variables.'
    ]);
    exit;
}

// Log that key was found (without exposing it)
error_log("PayMongo secret key found. Key starts with: " . substr($paymongo_secret_key, 0, 8) . "...");
$paymongo_api_url = 'https://api.paymongo.com/v1';

// Determine if using sandbox or live
$is_sandbox = strpos($paymongo_secret_key, 'sk_test_') === 0;
if ($is_sandbox) {
    $paymongo_api_url = 'https://api.paymongo.com/v1'; // Sandbox uses same URL
}

// Create payment source (GCash)
// Note: Amount must be in centavos (smallest currency unit)
// PHP 345.00 = 34500 centavos
$source_data = [
    'data' => [
        'attributes' => [
            'amount' => intval($amount * 100), // Convert to centavos
            'currency' => 'PHP',
            'type' => 'gcash'
            // Redirect URLs will be set after ensuring they're absolute
        ]
    ]
];

// Ensure redirect URLs are absolute
$success_url = $redirect_url;
$failed_url = $redirect_url;

// If redirect_url is relative, make it absolute
if (strpos($redirect_url, 'http') !== 0) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base_url = $protocol . '://' . $host;
    $success_url = $base_url . (strpos($redirect_url, '/') === 0 ? '' : '/') . $redirect_url;
    $failed_url = $success_url;
}

// Update redirect URLs in source data
$source_data['data']['attributes']['redirect'] = [
    'success' => $success_url . (strpos($success_url, '?') !== false ? '&' : '?') . 'status=success',
    'failed' => $failed_url . (strpos($failed_url, '?') !== false ? '&' : '?') . 'status=failed'
];

// Make API call to PayMongo
$ch = curl_init($paymongo_api_url . '/sources');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($source_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Basic ' . base64_encode($paymongo_secret_key . ':')
]);

// Add SSL verification (important for production)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// Log request details for debugging
error_log("PayMongo API Request - URL: " . $paymongo_api_url . '/sources');
error_log("PayMongo API Request - Amount: " . ($amount * 100));
error_log("PayMongo API Request - HTTP Code: " . $http_code);

if ($curl_error) {
    error_log("PayMongo cURL Error: " . $curl_error);
    echo json_encode([
        'success' => false,
        'message' => 'Payment gateway error: ' . $curl_error
    ]);
    exit;
}

// Check if response is empty
if (empty($response)) {
    error_log("PayMongo API returned empty response");
    echo json_encode([
        'success' => false,
        'message' => 'Payment gateway error: Empty response from PayMongo API. HTTP Code: ' . $http_code
    ]);
    exit;
}

$response_data = json_decode($response, true);

// Log full response for debugging
error_log("PayMongo API Response - HTTP Code: " . $http_code);
error_log("PayMongo API Response - Body: " . $response);
error_log("PayMongo API Response - Parsed: " . json_encode($response_data));

// PayMongo returns 200 for successful source creation (not 201)
if (($http_code === 200 || $http_code === 201) && isset($response_data['data']['attributes']['redirect']['checkout_url'])) {
    // Success - return checkout URL
    $checkout_url = $response_data['data']['attributes']['redirect']['checkout_url'];
    $source_id = $response_data['data']['id'];
    
    // Store payment source ID in order if order_id is provided
    if ($order_id) {
        $conn = get_db_connection();
        init_db();
        
        // Check if paymongo_payment_id column exists, if not add it
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
        
        // Update order with payment source ID
        $update_query = "UPDATE orders SET paymongo_source_id = ? WHERE id = ?";
        execute_sql($conn, $update_query, [$source_id, $order_id]);
        close_connection($conn);
    }
    
    echo json_encode([
        'success' => true,
        'checkout_url' => $checkout_url,
        'source_id' => $source_id
    ]);
} else {
    // Error from PayMongo - provide detailed error message
    $error_message = 'Unknown error from payment gateway';
    $error_details = [];
    
    // Try to extract error message from response
    if (isset($response_data['errors']) && is_array($response_data['errors']) && count($response_data['errors']) > 0) {
        $error = $response_data['errors'][0];
        $error_message = $error['detail'] ?? $error['message'] ?? $error['code'] ?? 'Unknown error';
        $error_details = $error;
    } elseif (isset($response_data['message'])) {
        $error_message = $response_data['message'];
    } elseif (isset($response_data['error'])) {
        $error_message = is_string($response_data['error']) ? $response_data['error'] : json_encode($response_data['error']);
    } elseif ($http_code === 401) {
        $error_message = 'Invalid API key. Please check your PayMongo secret key in Railway environment variables.';
    } elseif ($http_code === 400) {
        $error_message = 'Invalid request. Please check the payment details.';
    } elseif ($http_code === 404) {
        $error_message = 'PayMongo API endpoint not found.';
    } elseif ($http_code >= 500) {
        $error_message = 'PayMongo server error. Please try again later.';
    } elseif ($http_code === 0) {
        $error_message = 'Failed to connect to PayMongo API. Please check your internet connection.';
    }
    
    // If we still don't have a good error message, use the raw response
    if ($error_message === 'Unknown error from payment gateway') {
        if (!empty($response)) {
            $error_message = 'PayMongo API Error (HTTP ' . $http_code . '): ' . substr($response, 0, 200);
        } elseif ($response_data === null) {
            $error_message = 'PayMongo API returned invalid JSON (HTTP ' . $http_code . ')';
        } else {
            $error_message = 'PayMongo API Error (HTTP ' . $http_code . '): ' . json_encode($response_data);
        }
    }
    
    // Log full error for debugging
    error_log("PayMongo Error - HTTP: $http_code");
    error_log("PayMongo Error - Message: " . $error_message);
    error_log("PayMongo Error - Full Response: " . json_encode($response_data));
    
    // Return error with debug info in sandbox mode
    $error_response = [
        'success' => false,
        'message' => 'Payment gateway error: ' . $error_message,
        'http_code' => $http_code
    ];
    
    // Add debug info in sandbox mode
    if ($is_sandbox) {
        $error_response['debug'] = [
            'response' => $response_data,
            'raw_response' => substr($response, 0, 1000),
            'error_details' => $error_details,
            'request_data' => [
                'amount' => $amount * 100,
                'currency' => 'PHP',
                'type' => 'gcash'
            ]
        ];
    }
    
    echo json_encode($error_response);
}
?>

