<?php
/**
 * Predict Delivery Time and Shipping Fee API
 * Calls Python ML model to predict delivery time and calculate shipping fee
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

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
$latitude = isset($input['latitude']) ? floatval($input['latitude']) : null;
$longitude = isset($input['longitude']) ? floatval($input['longitude']) : null;
$municipality = sanitize_string($input['municipality'] ?? '', 100);
$barangay = sanitize_string($input['barangay'] ?? '', 100);
$postal_code = sanitize_string($input['postal_code'] ?? '', 10);
$order_size = isset($input['order_size']) ? intval($input['order_size']) : 1;

// Validate coordinates
if ($latitude === null || $longitude === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Latitude and longitude are required'
    ]);
    exit;
}

if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid latitude or longitude values'
    ]);
    exit;
}

// Get time of order (current hour if not provided)
$time_of_order = isset($input['time_of_order']) ? intval($input['time_of_order']) : intval(date('G'));

// Get day of week (current day if not provided, 0=Monday, 6=Sunday)
$day_of_week = isset($input['day_of_week']) ? intval($input['day_of_week']) : (intval(date('w')) - 1);
if ($day_of_week < 0) $day_of_week = 6; // Sunday

// Prepare data for Python script
$python_input = [
    'latitude' => $latitude,
    'longitude' => $longitude,
    'municipality' => $municipality,
    'barangay' => $barangay,
    'postal_code' => $postal_code,
    'time_of_order' => $time_of_order,
    'day_of_week' => $day_of_week,
    'order_size' => $order_size,
    'model_dir' => __DIR__ . '/../ml/models'
];

// Get Python executable path (try common locations)
$python_cmd = 'python3';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows
    $python_cmd = 'python';
}

// Check if Python script exists
$python_script = __DIR__ . '/../ml/predict.py';
if (!file_exists($python_script)) {
    echo json_encode([
        'success' => false,
        'message' => 'Prediction script not found. Please ensure ML models are set up.'
    ]);
    exit;
}

// Prepare command
$json_input = json_encode($python_input);
$command = escapeshellarg($python_cmd) . ' ' . escapeshellarg($python_script) . ' ' . escapeshellarg($json_input);

// Execute Python script
$output = [];
$return_var = 0;
exec($command . ' 2>&1', $output, $return_var);

// Parse output
$output_str = implode("\n", $output);
$result = json_decode($output_str, true);

if ($return_var !== 0 || !$result || !isset($result['success'])) {
    // Fallback calculation if Python script fails
    error_log("Python prediction failed: " . $output_str);
    
    // Simple fallback calculation
    $hub_lat = 14.0703;
    $hub_lng = 121.3253;
    
    // Calculate distance using Haversine formula (simplified)
    $distance_km = calculate_distance($hub_lat, $hub_lng, $latitude, $longitude);
    
    // Simple delivery time estimate
    $base_time = 15;
    $minutes_per_km = 2.5;
    $delivery_time_minutes = $base_time + ($distance_km * $minutes_per_km) + ($order_size * 0.5);
    $delivery_time_minutes = max(20, $delivery_time_minutes);
    
    // Calculate shipping fee
    $base_fee = 50.0;
    $rate_per_minute = 0.5;
    $shipping_fee = $base_fee + ($delivery_time_minutes * $rate_per_minute);
    
    // Calculate delivery date range for fallback (supports same-day, next-day, multi-day)
    $order_datetime = new DateTime();
    $hours = $delivery_time_minutes / 60;
    $order_hour = intval($order_datetime->format('G'));
    
    // Determine delivery days based on predicted time and order time
    if ($hours < 4 && $order_hour < 14) {
        // Same-day delivery possible
        $processing_days = 0;
        $delivery_days = 0;
        $delivery_window_days = 1;
    } elseif ($hours < 8 || $order_hour >= 14) {
        // Next-day delivery
        $processing_days = ($order_hour < 14) ? 0 : 1;
        $delivery_days = 1;
        $delivery_window_days = 1;
    } else {
        // Multi-day delivery
        $delivery_days = max(1, intval($hours / 8));
        $processing_days = 1;
        $delivery_window_days = 2;
    }
    
    $start_date = clone $order_datetime;
    $start_date->modify("+{$processing_days} days");
    $start_date->modify("+{$delivery_days} days");
    
    $end_date = clone $start_date;
    $end_date->modify("+{$delivery_window_days} days");
    
    $date_range = $start_date->format('M d') . ' - ' . $end_date->format('M d');
    
    $result = [
        'success' => true,
        'delivery_time_minutes' => round($delivery_time_minutes, 2),
        'shipping_fee' => round($shipping_fee, 2),
        'delivery_time_hours' => round($delivery_time_minutes / 60, 2),
        'delivery_date_range' => $date_range,
        'delivery_start_date' => $start_date->format('Y-m-d\TH:i:s'),
        'delivery_end_date' => $end_date->format('Y-m-d\TH:i:s'),
        'delivery_start_date_formatted' => $start_date->format('M d'),
        'delivery_end_date_formatted' => $end_date->format('M d'),
        'fallback' => true
    ];
}

echo json_encode($result);

/**
 * Calculate distance between two points using Haversine formula
 */
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // Earth radius in kilometers
    
    $d_lat = deg2rad($lat2 - $lat1);
    $d_lon = deg2rad($lon2 - $lon1);
    
    $a = sin($d_lat / 2) * sin($d_lat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($d_lon / 2) * sin($d_lon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distance = $earth_radius * $c;
    
    return $distance;
}
?>


