<?php
/**
 * Test Email Configuration API
 */

header('Content-Type: application/json');
require_once '../database.php';
require_once '../email_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

// Get test email from settings or use default
$test_email = get_system_setting('brevo_test_email', $data['brevo_sender_email'] ?? 'test@example.com');

// Use provided API key or get from settings
$api_key = $data['brevo_api_key'] ?? get_system_setting('brevo_api_key');
$sender_email = $data['brevo_sender_email'] ?? get_system_setting('brevo_sender_email');
$sender_name = $data['brevo_sender_name'] ?? get_system_setting('brevo_sender_name', 'AquaSphere');

if (!$api_key || !$sender_email) {
    echo json_encode([
        'success' => false,
        'message' => 'Please provide API key and sender email to test'
    ]);
    exit;
}

// Create temporary service for testing
$brevo_service = new BrevoEmailService($api_key, $sender_email, $sender_name);

$subject = "AquaSphere - Email Configuration Test";
$html_content = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Email Test</title>
    <style>
        body { font-family: Inter, system-ui, Segoe UI, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #256892, #1e4f73); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; background-color: #f9f9f9; border-radius: 0 0 8px 8px; }
        .success-box { background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>AquaSphere Email Test</h1>
        </div>
        <div class='content'>
            <div class='success-box'>
                <h3>✓ Email Configuration Successful!</h3>
                <p>If you received this email, your Brevo API configuration is working correctly.</p>
            </div>
            <p>Your email service is properly configured and ready to send emails to users.</p>
        </div>
    </div>
</body>
</html>
";

$text_content = "
AquaSphere Email Test

✓ Email Configuration Successful!

If you received this email, your Brevo API configuration is working correctly.

Your email service is properly configured and ready to send emails to users.
";

$result = $brevo_service->send_email($test_email, $subject, $html_content, $text_content, 'Admin');

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Test email sent successfully to ' . $test_email . '. Please check your inbox.'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['message']
    ]);
}
?>

