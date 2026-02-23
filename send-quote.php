<?php
/**
 * MyParkPay - Contact/Demo Request Handler
 * Sends form submissions via Gmail SMTP and saves to Supabase
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
$allowedOrigins = ['https://myparkpay.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Load configurations
$smtpConfig = require __DIR__ . '/../config.php';
$supabaseConfig = require __DIR__ . '/../supabase-config.php';

foreach ($supabaseConfig as $k => $v) { if (is_string($v)) $supabaseConfig[$k] = preg_replace('/[^\x20-\x7E]/', '', $v); }
foreach ($smtpConfig as $k => $v) { if (is_string($v)) $smtpConfig[$k] = preg_replace('/[^\x20-\x7E]/', '', $v); }

// Fetch business-specific email from Supabase
$businessId = 'fb641c4c-f52c-40f6-86d0-e30068285f93';
$bizUrl = $supabaseConfig['url'] . '/rest/v1/businesses?id=eq.' . $businessId . '&select=contact_email,name';
$bizCh = curl_init($bizUrl);
curl_setopt_array($bizCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseConfig['service_role_key'],
        'Authorization: Bearer ' . $supabaseConfig['service_role_key'],
    ],
    CURLOPT_TIMEOUT => 5,
]);
$bizResult = json_decode(curl_exec($bizCh), true);
curl_close($bizCh);

if (!empty($bizResult[0]['contact_email'])) {
    // from_email stays as SMTP authenticated account (ipwemails@gmail.com) to avoid spam filters
    $smtpConfig['from_name'] = $bizResult[0]['name'] ?? 'MyParkPay';
    $smtpConfig['to_email'] = $bizResult[0]['contact_email'];
}

// Get and sanitize form data
$name = isset($_POST['name']) ? mb_substr(htmlspecialchars(trim(str_replace(["\r", "\n"], '', $_POST['name']))), 0, 100) : '';
$email = isset($_POST['email']) ? mb_substr(filter_var(trim(str_replace(["\r", "\n"], '', $_POST['email'])), FILTER_SANITIZE_EMAIL), 0, 254) : '';
$phone = isset($_POST['phone']) ? mb_substr(htmlspecialchars(trim($_POST['phone'])), 0, 30) : 'Not provided';
$company = isset($_POST['organization']) ? mb_substr(htmlspecialchars(trim($_POST['organization'])), 0, 200) : 'Not provided';
$service = isset($_POST['org_type']) ? mb_substr(htmlspecialchars(trim($_POST['org_type'])), 0, 50) : 'Not specified';
$budget = isset($_POST['attendees']) ? mb_substr(htmlspecialchars(trim($_POST['attendees'])), 0, 50) : 'Not specified';
$timeline = isset($_POST['timeline']) ? mb_substr(htmlspecialchars(trim($_POST['timeline'])), 0, 50) : 'Not specified';
$message = isset($_POST['message']) ? mb_substr(htmlspecialchars(trim($_POST['message'])), 0, 5000) : '';

// Verify Turnstile CAPTCHA
$turnstileToken = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
if (empty($turnstileToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Security verification required. Please try again.']);
    exit();
}

$turnstileCh = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($turnstileCh, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'secret' => $smtpConfig['turnstile_secret'],
        'response' => $turnstileToken,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
$turnstileResult = curl_exec($turnstileCh);
curl_close($turnstileCh);

$turnstileData = json_decode($turnstileResult, true);
if (!$turnstileData || empty($turnstileData['success'])) {
    http_response_code(400);
    error_log('[MyParkPay] Turnstile verification failed: ' . ($turnstileResult ?: 'no response'), 3, __DIR__ . '/quote-requests.log');
    echo json_encode(['success' => false, 'message' => 'Security verification failed. Please try again.']);
    exit();
}

// Validate required fields
$errors = [];
if (empty($name)) { $errors[] = 'Name is required'; }
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Valid email is required'; }
if (empty($message)) { $errors[] = 'Message is required'; }

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit();
}

// Format display names
$serviceNames = [
    'school' => 'School / School District',
    'parks-rec' => 'Parks & Recreation Department',
    'sports-league' => 'Sports League',
    'community-center' => 'Community Center',
    'nonprofit' => 'Nonprofit Organization',
    'government' => 'Government Agency',
    'other' => 'Other'
];
$serviceDisplay = isset($serviceNames[$service]) ? $serviceNames[$service] : $service;

$budgetNames = [
    'under-5k' => 'Under 5,000',
    '5k-25k' => '5,000 - 25,000',
    '25k-100k' => '25,000 - 100,000',
    '100k-500k' => '100,000 - 500,000',
    'over-500k' => 'Over 500,000'
];
$budgetDisplay = isset($budgetNames[$budget]) ? $budgetNames[$budget] : $budget;

$timelineNames = [
    'immediate' => 'Immediate (Within 1 month)',
    '1-3months' => '1-3 Months',
    '3-6months' => '3-6 Months',
    '6-12months' => '6-12 Months',
    'planning' => 'Still Planning'
];
$timelineDisplay = isset($timelineNames[$timeline]) ? $timelineNames[$timeline] : $timeline;

// Build email content
$subject = "New Demo Request - {$serviceDisplay} - {$name}";

$htmlBody = "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #0d9488 0%, #065f46 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; }
        .field { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .field:last-child { border-bottom: none; margin-bottom: 0; }
        .label { font-weight: bold; color: #0d9488; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .value { font-size: 16px; color: #0f172a; }
        .message-box { background: white; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 10px; }
        .footer { background: #0f172a; color: #94a3b8; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 8px 8px; }
        .highlight { background: #f97316; color: white; padding: 3px 10px; border-radius: 4px; font-size: 14px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>New Demo Request</h1>
            <p style='margin: 10px 0 0 0; opacity: 0.9;'>MyParkPay</p>
        </div>
        <div class='content'>
            <div class='field'>
                <div class='label'>Contact Name</div>
                <div class='value'>{$name}</div>
            </div>
            <div class='field'>
                <div class='label'>Email Address</div>
                <div class='value'><a href='mailto:{$email}'>{$email}</a></div>
            </div>
            <div class='field'>
                <div class='label'>Phone Number</div>
                <div class='value'>{$phone}</div>
            </div>
            <div class='field'>
                <div class='label'>Organization</div>
                <div class='value'>{$company}</div>
            </div>
            <div class='field'>
                <div class='label'>Organization Type</div>
                <div class='value'><span class='highlight'>{$serviceDisplay}</span></div>
            </div>
            <div class='field'>
                <div class='label'>Expected Annual Attendees</div>
                <div class='value'>{$budgetDisplay}</div>
            </div>
            <div class='field'>
                <div class='label'>Timeline</div>
                <div class='value'>{$timelineDisplay}</div>
            </div>
            <div class='field'>
                <div class='label'>Message</div>
                <div class='message-box'>{$message}</div>
            </div>
        </div>
        <div class='footer'>
            <p>This demo request was submitted via the MyParkPay website.</p>
            <p>Submitted on: " . date('F j, Y \a\t g:i A') . "</p>
        </div>
    </div>
</body>
</html>
";

$textBody = "
NEW DEMO REQUEST - MYPARKPAY
==========================================

Contact Information:
- Name: {$name}
- Email: {$email}
- Phone: {$phone}
- Organization: {$company}

Details:
- Organization Type: {$serviceDisplay}
- Expected Attendees: {$budgetDisplay}
- Timeline: {$timelineDisplay}

Message:
{$message}

---
Submitted on: " . date('F j, Y \a\t g:i A') . "
";

// Include the SMTP mailer class (shared from DC Metro)
require_once __DIR__ . '/smtp-mailer.php';

$mailer = new SMTPMailer(
    $smtpConfig['host'],
    $smtpConfig['port'],
    $smtpConfig['username'],
    $smtpConfig['password']
);

$sent = $mailer->send(
    $smtpConfig['from_email'],
    'MyParkPay',
    $smtpConfig['to_email'],
    $subject,
    $htmlBody,
    $textBody,
    $email,
    $name
);

// Save to Supabase
try {
    $quoteData = json_encode([
        'business_id' => 'fb641c4c-f52c-40f6-86d0-e30068285f93',
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'company' => $company,
        'service' => $serviceDisplay,
        'budget' => $budgetDisplay,
        'timeline' => $timelineDisplay,
        'message' => $message
    ]);

    $ch = curl_init($supabaseConfig['url'] . '/rest/v1/quotes');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $quoteData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'apikey: ' . $supabaseConfig['service_role_key'],
            'Authorization: Bearer ' . $supabaseConfig['service_role_key'],
            'Prefer: return=minimal'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($httpCode !== 201) {
        error_log("[MyParkPay] Supabase insert HTTP {$httpCode}: {$response} {$curlError}", 3, __DIR__ . '/quote-requests.log');
    }
} catch (Exception $e) {
    error_log('[MyParkPay] Supabase insert failed: ' . $e->getMessage(), 3, __DIR__ . '/quote-requests.log');
}

// Dashboard user notifications (best-effort)
try {
    $businessId = 'fb641c4c-f52c-40f6-86d0-e30068285f93';
    $supabaseUrl = $supabaseConfig['url'];
    $serviceKey = $supabaseConfig['service_role_key'];
    $authHeaders = [
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
    ];

    $profilesUrl = $supabaseUrl . '/rest/v1/profiles?or=(business_id.eq.' . $businessId . ',role.eq.admin)&select=id,full_name';
    $ch = curl_init($profilesUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $authHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $profilesJson = curl_exec($ch);
    $profilesCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($profilesCode !== 200 || !$profilesJson) {
        throw new Exception("Profiles query HTTP {$profilesCode}");
    }

    $profiles = json_decode($profilesJson, true);
    if (empty($profiles)) {
        throw new Exception('No profiles found for notification');
    }

    $userIds = array_column($profiles, 'id');
    $nameMap = [];
    foreach ($profiles as $p) {
        $nameMap[$p['id']] = $p['full_name'] ?: 'there';
    }

    $idsParam = '(' . implode(',', $userIds) . ')';

    // Check who opted out
    $optedOutIds = [];
    $prefsUrl2 = $supabaseUrl . '/rest/v1/notification_preferences?notify_new_quote=eq.false&user_id=in.' . $idsParam . '&select=user_id';
    $ch = curl_init($prefsUrl2);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $authHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $optedOutJson = curl_exec($ch);
    curl_close($ch);
    if ($optedOutJson) {
        $optedOut = json_decode($optedOutJson, true);
        if (is_array($optedOut)) {
            $optedOutIds = array_column($optedOut, 'user_id');
        }
    }

    $notifyIds = array_diff($userIds, $optedOutIds);

    if (empty($notifyIds)) {
        throw new Exception('No users opted in for new-quote notifications');
    }

    $messagePreview = mb_substr(strip_tags($message), 0, 200);
    if (mb_strlen(strip_tags($message)) > 200) {
        $messagePreview .= '...';
    }

    foreach ($notifyIds as $userId) {
        try {
            $authUrl = $supabaseUrl . '/auth/v1/admin/users/' . $userId;
            $ch = curl_init($authUrl);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $authHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $authJson = curl_exec($ch);
            $authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($authCode !== 200 || !$authJson) continue;

            $authUser = json_decode($authJson, true);
            $userEmail = $authUser['email'] ?? '';
            if (!$userEmail) continue;
            if ($userEmail === $smtpConfig['to_email']) continue;

            $userName = $nameMap[$userId] ?? 'there';
            $notifSubject = "New Demo Request — {$name} — MyParkPay";

            $notifHtml = "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #0d9488 0%, #065f46 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; }
        .field { margin-bottom: 15px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .field:last-child { border-bottom: none; margin-bottom: 0; }
        .label { font-weight: bold; color: #0d9488; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .value { font-size: 15px; color: #0f172a; }
        .message-box { background: white; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 8px; font-size: 14px; color: #475569; }
        .cta { text-align: center; margin: 25px 0 10px 0; }
        .cta a { background: #f97316; color: white; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; }
        .footer { background: #0f172a; color: #94a3b8; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 8px 8px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>New Demo Request</h1>
            <p style='margin: 10px 0 0 0; opacity: 0.9;'>MyParkPay &mdash; IPW Dashboard</p>
        </div>
        <div class='content'>
            <p style='font-size: 16px; margin-top: 0;'>Hi {$userName},</p>
            <p>A new demo request has been submitted on MyParkPay:</p>
            <div class='field'>
                <div class='label'>Name</div>
                <div class='value'>{$name}</div>
            </div>
            <div class='field'>
                <div class='label'>Email</div>
                <div class='value'><a href='mailto:{$email}'>{$email}</a></div>
            </div>
            <div class='field'>
                <div class='label'>Organization</div>
                <div class='value'>{$company}</div>
            </div>
            <div class='field'>
                <div class='label'>Organization Type</div>
                <div class='value'>{$serviceDisplay}</div>
            </div>
            <div class='field'>
                <div class='label'>Expected Attendees</div>
                <div class='value'>{$budgetDisplay}</div>
            </div>
            <div class='field'>
                <div class='label'>Message</div>
                <div class='message-box'>{$messagePreview}</div>
            </div>
            <div class='cta'>
                <a href='https://aquamarine-peafowl-476925.hostingersite.com/dashboard/'>View in Dashboard</a>
            </div>
        </div>
        <div class='footer'>
            <p>IPW Dashboard &mdash; MyParkPay Notification</p>
            <p>You received this because you have New Quote Alerts enabled.</p>
        </div>
    </div>
</body>
</html>";

            $notifText = "NEW DEMO REQUEST — MYPARKPAY — IPW DASHBOARD
==========================================

Hi {$userName},

A new demo request has been submitted on MyParkPay:

Name: {$name}
Email: {$email}
Organization: {$company}
Type: {$serviceDisplay}
Expected Attendees: {$budgetDisplay}

Message:
{$messagePreview}

Log in to your dashboard to review and respond.

---
IPW Dashboard — MyParkPay Notification";

            $mailer->send(
                $smtpConfig['username'],
                'IPW Dashboard',
                $userEmail,
                $notifSubject,
                $notifHtml,
                $notifText,
                $email,
                $name
            );

        } catch (Exception $e) {
            error_log("[MyParkPay] Notification email failed for user {$userId}: " . $e->getMessage(), 3, __DIR__ . '/quote-requests.log');
        }
    }

} catch (Exception $e) {
    error_log('[MyParkPay] Dashboard notification failed: ' . $e->getMessage(), 3, __DIR__ . '/quote-requests.log');
}

if ($sent) {
    // Send confirmation email to the customer
    try {
        $confirmHtmlBody = "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #0d9488 0%, #065f46 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; }
        .field { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .field:last-child { border-bottom: none; margin-bottom: 0; }
        .label { font-weight: bold; color: #0d9488; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .value { font-size: 16px; color: #0f172a; }
        .message-box { background: white; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 10px; }
        .footer { background: #0f172a; color: #94a3b8; padding: 20px; text-align: center; font-size: 12px; border-radius: 0 0 8px 8px; }
        .highlight { background: #f97316; color: white; padding: 3px 10px; border-radius: 4px; font-size: 14px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>We Got Your Request!</h1>
            <p style='margin: 10px 0 0 0; opacity: 0.9;'>MyParkPay</p>
        </div>
        <div class='content'>
            <p style='font-size: 16px; margin-top: 0;'>Hi {$name},</p>
            <p>Thank you for your interest in MyParkPay! We've received your demo request and a member of our team will be in touch shortly to help you get started.</p>
            <p style='font-weight: bold; color: #0d9488;'>Here's a summary of your request:</p>
            <div class='field'>
                <div class='label'>Organization Type</div>
                <div class='value'><span class='highlight'>{$serviceDisplay}</span></div>
            </div>
            <div class='field'>
                <div class='label'>Expected Annual Attendees</div>
                <div class='value'>{$budgetDisplay}</div>
            </div>
            <div class='field'>
                <div class='label'>Timeline</div>
                <div class='value'>{$timelineDisplay}</div>
            </div>
            <div class='field'>
                <div class='label'>Your Message</div>
                <div class='message-box'>{$message}</div>
            </div>
            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
            <p style='margin-bottom: 5px;'>In the meantime, feel free to reach out:</p>
            <p style='margin: 5px 0;'>Phone: <strong>(555) 123-4567</strong></p>
            <p style='margin: 5px 0;'>Email: <strong><a href='mailto:info@myparkpay.com'>info@myparkpay.com</a></strong></p>
        </div>
        <div class='footer'>
            <p>MyParkPay &mdash; Streamline Your Event Ticketing</p>
            <p>This is an automated confirmation submitted on " . date('F j, Y \a\t g:i A') . ".</p>
        </div>
    </div>
</body>
</html>
";

        $confirmTextBody = "
REQUEST RECEIVED - MYPARKPAY
================================================

Hi {$name},

Thank you for your interest in MyParkPay! We've received your demo request and a member of our team will be in touch shortly.

Summary:
- Organization Type: {$serviceDisplay}
- Expected Attendees: {$budgetDisplay}
- Timeline: {$timelineDisplay}

Your Message:
{$message}

---

Questions? Reach out:
Phone: (555) 123-4567
Email: info@myparkpay.com

---
MyParkPay - Streamline Your Event Ticketing
Submitted on: " . date('F j, Y \a\t g:i A') . "
";

        $mailer->send(
            $smtpConfig['from_email'],
            'MyParkPay',
            $email,
            'Demo Request Received - MyParkPay',
            $confirmHtmlBody,
            $confirmTextBody,
            $smtpConfig['from_email'],
            ''
        );
    } catch (Exception $e) {
        error_log('[MyParkPay] Confirmation email failed: ' . $e->getMessage(), 3, __DIR__ . '/quote-requests.log');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your request has been sent. We\'ll be in touch shortly.'
    ]);
} else {
    http_response_code(500);
    $smtpError = $mailer->getLastError();
    error_log('[MyParkPay] Email failed: ' . $smtpError, 3, __DIR__ . '/quote-requests.log');

    try {
        $failAlertSubject = '[ALERT] MyParkPay email delivery failed — ' . $name;
        $failAlertHtml = "
<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#333;padding:20px'>
<div style='max-width:600px;margin:0 auto'>
<div style='background:#ef4444;color:white;padding:20px;border-radius:8px 8px 0 0;text-align:center'>
<h2 style='margin:0'>Email Delivery Failed</h2></div>
<div style='background:#fef2f2;padding:25px;border:1px solid #fecaca'>
<p>A MyParkPay demo request email <strong>failed to deliver</strong>. The request has been saved to the dashboard database.</p>
<p><strong>Customer:</strong> {$name} ({$email})</p>
<p><strong>Organization Type:</strong> {$serviceDisplay}</p>
<p><strong>Error:</strong> {$smtpError}</p>
<p style='margin-top:20px'><a href='https://aquamarine-peafowl-476925.hostingersite.com/dashboard/' style='background:#0d9488;color:white;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:bold'>View in Dashboard</a></p>
</div></div></body></html>";

        $failAlertText = "ALERT: MyParkPay email delivery failed\n\nCustomer: {$name} ({$email})\nOrg Type: {$serviceDisplay}\nError: {$smtpError}\n\nThe request has been saved in the dashboard.";

        $mailer->send(
            $smtpConfig['from_email'],
            'IPW Alert System',
            $smtpConfig['to_email'],
            $failAlertSubject,
            $failAlertHtml,
            $failAlertText,
            '',
            ''
        );
    } catch (Exception $e) {
        error_log('[MyParkPay] Backup failure alert also failed: ' . $e->getMessage(), 3, __DIR__ . '/quote-requests.log');
    }

    echo json_encode([
        'success' => false,
        'message' => 'Failed to send. Please call us directly at (555) 123-4567.'
    ]);
}
?>
