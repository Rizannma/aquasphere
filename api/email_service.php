<?php
/**
 * Email Service for AquaSphere using Brevo (formerly Sendinblue) API
 */

require_once 'database.php';

class BrevoEmailService {
    private $api_key;
    private $sender_email;
    private $sender_name;
    private $api_url = "https://api.brevo.com/v3/smtp/email";
    
    public function __construct($api_key, $sender_email, $sender_name = "AquaSphere") {
        $this->api_key = $api_key;
        $this->sender_email = $sender_email;
        $this->sender_name = $sender_name;
    }
    
    /**
     * Send email using Brevo API
     */
    public function send_email($to_email, $subject, $html_content, $text_content = null, $to_name = null) {
        if (!$this->api_key || !$this->sender_email) {
            return ['success' => false, 'message' => 'Email service not configured'];
        }
        
        $headers = [
            "accept: application/json",
            "api-key: " . $this->api_key,
            "content-type: application/json"
        ];
        
        $data = [
            "sender" => [
                "email" => $this->sender_email,
                "name" => $this->sender_name
            ],
            "to" => [
                [
                    "email" => $to_email,
                    "name" => $to_name ? $to_name : explode('@', $to_email)[0]
                ]
            ],
            "subject" => $subject,
            "htmlContent" => $html_content
        ];
        
        if ($text_content) {
            $data["textContent"] = $text_content;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'message' => 'CURL Error: ' . $error];
        }
        
        if ($http_code == 201) {
            $response_data = json_decode($response, true);
            return ['success' => true, 'message' => 'Email sent successfully', 'message_id' => $response_data['messageId'] ?? null];
        } else {
            $error_data = json_decode($response, true);
            $error_message = $error_data['message'] ?? 'Unknown error';
            return ['success' => false, 'message' => 'Brevo API Error: ' . $error_message];
        }
    }
}

/**
 * Get configured Brevo email service
 */
function get_brevo_service() {
    // Force fresh database connection to avoid caching issues
    $api_key = get_system_setting('brevo_api_key');
    $sender_email = get_system_setting('brevo_sender_email');
    $sender_name = get_system_setting('brevo_sender_name', 'AquaSphere');
    $enable_notifications = get_system_setting('enable_email_notifications', '0');
    
    // Check both '1' and 'true' for compatibility
    $enable_notifications = ($enable_notifications === '1' || $enable_notifications === 'true' || $enable_notifications === 1 || $enable_notifications === true);
    
    // Debug logging (remove in production)
    error_log("Brevo service check - API key: " . (!empty($api_key) ? "SET (" . strlen($api_key) . " chars)" : "NOT SET"));
    error_log("Brevo service check - Sender email: " . ($sender_email ?: "NOT SET"));
    error_log("Brevo service check - Enable notifications raw: " . get_system_setting('enable_email_notifications', '0'));
    error_log("Brevo service check - Enable notifications: " . ($enable_notifications ? "YES" : "NO"));
    
    if (!$enable_notifications || !$api_key || !$sender_email) {
        error_log("Brevo service not available - enable: " . ($enable_notifications ? "yes" : "no") . ", api_key: " . (!empty($api_key) ? "yes" : "no") . ", sender_email: " . (!empty($sender_email) ? "yes" : "no"));
        return null;
    }
    
    error_log("Brevo service initialized successfully");
    return new BrevoEmailService($api_key, $sender_email, $sender_name);
}

/**
 * Send OTP email using Brevo
 */
function send_otp_email_brevo($email, $otp_code, $username) {
    $brevo_service = get_brevo_service();
    
    if (!$brevo_service) {
        // Development fallback - log the OTP so it can be retrieved from logs
        error_log("Brevo email service not configured. OTP for $username ($email): $otp_code");
        // Return false to indicate email was not sent
        // But we'll still allow registration to proceed in development
        return false;
    }
    
    $subject = "AquaSphere - Email Verification Code";
    
    $html_content = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Email Verification</title>
        <style>
            body { font-family: Inter, system-ui, Segoe UI, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #256892, #1e4f73); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background-color: #f9f9f9; border-radius: 0 0 8px 8px; }
            .otp-box { background-color: white; padding: 30px; margin: 20px 0; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .otp-code { font-size: 36px; font-weight: bold; color: #256892; letter-spacing: 8px; margin: 20px 0; }
            .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>AquaSphere Email Verification</h1>
            </div>
            <div class='content'>
                <h2>Hello $username,</h2>
                <p>Thank you for registering with AquaSphere! To complete your registration, please use the verification code below:</p>
                
                <div class='otp-box'>
                    <h3>Your Verification Code</h3>
                    <div class='otp-code'>$otp_code</div>
                    <p>Enter this code in the verification page to complete your registration.</p>
                </div>
                
                <div class='warning'>
                    <strong>Important:</strong>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>This code will expire in 10 minutes</li>
                        <li>Do not share this code with anyone</li>
                        <li>If you didn't request this code, please ignore this email</li>
                    </ul>
                </div>
                
                <p>If you have any questions, please contact our support team.</p>
            </div>
            <div class='footer'>
                <p>This is an automated message from AquaSphere. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $text_content = "
    AquaSphere Email Verification
    
    Hello $username,
    
    Thank you for registering with AquaSphere! To complete your registration, please use the verification code below:
    
    Your Verification Code: $otp_code
    
    Enter this code in the verification page to complete your registration.
    
    Important:
    - This code will expire in 10 minutes
    - Do not share this code with anyone
    - If you didn't request this code, please ignore this email
    
    If you have any questions, please contact our support team.
    
    This is an automated message from AquaSphere. Please do not reply to this email.
    ";
    
    $result = $brevo_service->send_email($email, $subject, $html_content, $text_content, $username);
    return $result['success'];
}

/**
 * Send password reset OTP email using Brevo
 */
function send_password_reset_otp_email_brevo($email, $otp_code, $username) {
    $brevo_service = get_brevo_service();
    
    if (!$brevo_service) {
        // Development fallback - log the OTP so it can be retrieved from logs
        error_log("Brevo email service not configured. Password Reset OTP for $username ($email): $otp_code");
        // Return false to indicate email was not sent
        // But we'll still allow password reset to proceed in development
        return false;
    }
    
    $subject = "AquaSphere - Password Reset Code";
    
    $html_content = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Password Reset</title>
        <style>
            body { font-family: Inter, system-ui, Segoe UI, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #256892, #1e4f73); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background-color: #f9f9f9; border-radius: 0 0 8px 8px; }
            .otp-box { background-color: white; padding: 30px; margin: 20px 0; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .otp-code { font-size: 36px; font-weight: bold; color: #256892; letter-spacing: 8px; margin: 20px 0; }
            .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>AquaSphere Password Reset</h1>
            </div>
            <div class='content'>
                <h2>Hello $username,</h2>
                <p>We received a request to reset your password. Please use the verification code below to proceed:</p>
                
                <div class='otp-box'>
                    <h3>Your Reset Code</h3>
                    <div class='otp-code'>$otp_code</div>
                    <p>Enter this code in the password reset page to continue.</p>
                </div>
                
                <div class='warning'>
                    <strong>Important:</strong>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>This code will expire in 10 minutes</li>
                        <li>Do not share this code with anyone</li>
                        <li>If you didn't request this reset, please ignore this email</li>
                    </ul>
                </div>
                
                <p>If you have any questions, please contact our support team.</p>
            </div>
            <div class='footer'>
                <p>This is an automated message from AquaSphere. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $text_content = "
    AquaSphere Password Reset
    
    Hello $username,
    
    We received a request to reset your password. Please use the verification code below to proceed:
    
    Your Reset Code: $otp_code
    
    Enter this code in the password reset page to continue.
    
    Important:
    - This code will expire in 10 minutes
    - Do not share this code with anyone
    - If you didn't request this reset, please ignore this email
    
    If you have any questions, please contact our support team.
    
    This is an automated message from AquaSphere. Please do not reply to this email.
    ";
    
    $result = $brevo_service->send_email($email, $subject, $html_content, $text_content, $username);
    return $result['success'];
}

/**
 * Send account suspension email
 */
function send_suspension_email_brevo($email, $username, $reason) {
    $brevo_service = get_brevo_service();
    if (!$brevo_service) {
        error_log("Suspension notice for $username ($email): $reason");
        return false;
    }

    $subject = "AquaSphere - Account Suspended";
    $html_content = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><title>Account Suspended</title></head>
    <body style='font-family: Inter, system-ui, Segoe UI, sans-serif; color: #0f172a;'>
        <div style='max-width:600px;margin:0 auto;padding:20px;'>
            <div style='background:linear-gradient(135deg,#256892,#1e4f73);padding:16px;border-radius:12px 12px 0 0;color:white;text-align:center;'>
                <h2 style='margin:0;'>Account Suspended</h2>
            </div>
            <div style='background:#f8fafc;padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;'>
                <p>Hello <strong>$username</strong>,</p>
                <p>Your AquaSphere account has been <strong>temporarily suspended</strong>.</p>
                <p><strong>Reason provided by admin:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>
                <p>If you believe this is a mistake or need more information, please contact support.</p>
                <p style='margin-top:24px;'>Thank you,<br>AquaSphere Team</p>
            </div>
        </div>
    </body>
    </html>";

    $text_content = "Hello $username,\n\nYour AquaSphere account has been temporarily suspended.\nReason: $reason\n\nIf you believe this is a mistake, please contact support.\n\nThank you,\nAquaSphere Team";

    $result = $brevo_service->send_email($email, $subject, $html_content, $text_content, $username);
    return $result['success'];
}

/**
 * Send account unsuspension email
 */
function send_unsuspension_email_brevo($email, $username) {
    $brevo_service = get_brevo_service();
    if (!$brevo_service) {
        error_log("Unsuspension notice for $username ($email)");
        return false;
    }

    $subject = "AquaSphere - Suspension Lifted";
    $html_content = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><title>Suspension Lifted</title></head>
    <body style='font-family: Inter, system-ui, Segoe UI, sans-serif; color: #0f172a;'>
        <div style='max-width:600px;margin:0 auto;padding:20px;'>
            <div style='background:linear-gradient(135deg,#22c55e,#16a34a);padding:16px;border-radius:12px 12px 0 0;color:white;text-align:center;'>
                <h2 style='margin:0;'>Suspension Lifted</h2>
            </div>
            <div style='background:#f8fafc;padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;'>
                <p>Hello <strong>$username</strong>,</p>
                <p>Your AquaSphere account suspension has been <strong>lifted</strong>. You can now sign in again.</p>
                <p>If you have any concerns, please contact support.</p>
                <p style='margin-top:24px;'>Thank you,<br>AquaSphere Team</p>
            </div>
        </div>
    </body>
    </html>";

    $text_content = "Hello $username,\n\nYour AquaSphere account suspension has been lifted. You can now sign in again.\n\nThank you,\nAquaSphere Team";

    $result = $brevo_service->send_email($email, $subject, $html_content, $text_content, $username);
    return $result['success'];
}
?>

