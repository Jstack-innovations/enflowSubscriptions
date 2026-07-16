<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../../SECURE/db.php';
require_once __DIR__ . '/../../SECURE/resendMail.php';

$data  = json_decode(file_get_contents("php://input"), true);
$email = trim(strtolower($data['email']          ?? ''));
$plan  = trim($data['plan']                      ?? '');
$tx_id = trim($data['transaction_id']            ?? '');

if (!$email || !$plan || !$tx_id) {
    echo json_encode(["status" => "error", "message" => "Email, plan and transaction ID are required."]);
    exit;
}

/* ===== GET SECRET KEY ===== */
ob_start();
include __DIR__ . '/../../SECURE/flutterwave-key.php';
$keyOutput = ob_get_clean();
$keyData   = json_decode($keyOutput, true);
$secretKey = $keyData['secretKey'] ?? '';

if (!$secretKey) {
    echo json_encode(["status" => "error", "message" => "Secret key not found."]);
    exit;
}

/* ===== VERIFY PAYMENT ===== */
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => "https://api.flutterwave.com/v3/transactions/$tx_id/verify",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => "GET",

    // PRODUCTION (active)
    //CURLOPT_SSL_VERIFYPEER => true,
    //CURLOPT_SSL_VERIFYHOST => 2,

    // LOCAL DEV (uncomment these two + comment out the two above)
     CURLOPT_SSL_VERIFYPEER => false,
     CURLOPT_SSL_VERIFYHOST => 0,

    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $secretKey",
        "Content-Type: application/json"
    ],
]);
$response = curl_exec($curl);
if (curl_errno($curl)) {
    echo json_encode(["status" => "error", "message" => "Payment gateway error."]);
    exit;
}
curl_close($curl);

$result = json_decode($response, true);
if (!$result || $result['status'] !== 'success' || $result['data']['status'] !== 'successful') {
    echo json_encode(["status" => "error", "message" => "Payment not verified."]);
    exit;
}

$amount = (float)$result['data']['amount'];

/* ===== DUPLICATE CHECK ===== */
$dup = $conn->prepare("SELECT id FROM subscriptions WHERE transaction_id = ?");
$dup->bind_param("s", $tx_id);
$dup->execute();
$dup->store_result();
if ($dup->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "Already processed."]);
    exit;
}

/* ===== PULL EXISTING ROW ===== */
$fetch = $conn->prepare("
    SELECT fullname, username, phone, country, dob, gender,
           business_type, business_name, website, currency,
           num_locations, num_staff, logo_url, connected_tools,
           team_members, zara_brand_voice, zara_primary_lang,
           zara_also_speaks, zara_top_goals, zara_hours
    FROM subscriptions
    WHERE LOWER(email) = LOWER(?)
    LIMIT 1
");
$fetch->bind_param("s", $email);
$fetch->execute();
$existing = $fetch->get_result()->fetch_assoc() ?: [];

/* ===== MERGE — existing row wins unless frontend sends override ===== */
$fullname      = $data['fullname']      ?? $existing['fullname']      ?? '';
$username      = $data['username']      ?? $existing['username']      ?? '';
$phone         = $data['phone']         ?? $existing['phone']         ?? '';
$country       = $data['country']       ?? $existing['country']       ?? '';
$dob           = $data['dob']           ?? $existing['dob']           ?? '';
$gender        = $data['gender']        ?? $existing['gender']        ?? '';
$businessType  = $data['businessType']  ?? $existing['business_type'] ?? '';
$businessName  = $data['businessName']  ?? $existing['business_name'] ?? '';
$website       = $data['website']       ?? $existing['website']       ?? '';
$currency      = $data['currency']      ?? $existing['currency']      ?? '';
$numLocations  = $data['num_locations'] ?? $existing['num_locations'] ?? null;
$numStaff      = $data['num_staff']     ?? $existing['num_staff']     ?? null;
$logoUrl       = $existing['logo_url']       ?? null;
$connectedTools= $existing['connected_tools'] ?? null;
$teamMembers   = $existing['team_members']    ?? null;
$brandVoice    = $existing['zara_brand_voice']  ?? null;
$primaryLang   = $existing['zara_primary_lang'] ?? null;
$alsoSpeaks    = $existing['zara_also_speaks']  ?? null;
$topGoals      = $existing['zara_top_goals']    ?? null;
$zaraHours     = $existing['zara_hours']        ?? null;

/* ===== RENEWAL DATE ===== */
if (stripos($plan, "annual") !== false) {
    $renewalDate = date("Y-m-d", strtotime("+1 year"));
} else {
    $renewalDate = date("Y-m-d", strtotime("+1 month"));
}

/* ===== ZARA CREDITS ===== */
if (stripos($plan, "zara + app") !== false || stripos($plan, "zara+app") !== false) {
    $zaraCredits = 1500;
} elseif (stripos($plan, "enterprise") !== false) {
    $zaraCredits = 3000;
} elseif (stripos($plan, "zara") !== false) {
    $zaraCredits = 1000;
} else {
    $zaraCredits = 0;
}

/* ===== SUB CODE ===== */
$subscriptionCode = "SUB-" . strtoupper(substr(md5(uniqid('', true)), 0, 10));

/* ===== UPDATE OR INSERT ===== */
if ($existing) {         
    $stmt = $conn->prepare("
    UPDATE subscriptions SET
        fullname          = ?,
        username          = ?,
        phone             = ?,
        country           = ?,
        dob               = ?,
        gender            = ?,
        business_type     = ?,
        business_name     = ?,
        website           = ?,
        currency          = ?,
        num_locations     = ?,
        num_staff         = ?,
        plan              = ?,
        amount            = ?,
        transaction_id    = ?,
        subscription_code = ?,
        status            = 'active',
        renewal_date      = ?,
        zara_credits      = zara_credits + ?,
        zara_credits_used = 0
    WHERE LOWER(email) = LOWER(?)
");
    $stmt->bind_param(
        "ssssssssssiisdsssis",
        $fullname, $username, $phone, $country, $dob,
        $gender, $businessType, $businessName, $website,
        $currency, $numLocations, $numStaff,
        $plan, $amount, $tx_id, $subscriptionCode,
        $renewalDate, $zaraCredits, $email
    );
} else {
    $stmt = $conn->prepare("
        INSERT INTO subscriptions (
            fullname, username, email, phone, country, dob, gender,
            business_type, business_name, website, currency,
            num_locations, num_staff,
            plan, amount, transaction_id, subscription_code,
            status, renewal_date, zara_credits, zara_credits_used
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, 0)
    ");
    $stmt->bind_param(
        "ssssssssssssiidsssi",
        $fullname, $username, $email, $phone, $country, $dob,
        $gender, $businessType, $businessName, $website,
        $currency, $numLocations, $numStaff,
        $plan, $amount, $tx_id, $subscriptionCode,
        $renewalDate, $zaraCredits
    );
}

$writeAttempts = 0;
while (true) {
    $writeAttempts++;
    try {
        if ($stmt->execute()) {
            break;
        }
        $dbError = $stmt->error;
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }

    $isDupCode = (stripos($dbError, 'subscription_code') !== false);
    if ($isDupCode && $writeAttempts < 5) {
        $subscriptionCode = "SUB-" . strtoupper(substr(md5(uniqid('', true)), 0, 10));
        if ($existing) {
            $stmt->bind_param(
                "ssssssssssiisdsssis",
                $fullname, $username, $phone, $country, $dob,
                $gender, $businessType, $businessName, $website,
                $currency, $numLocations, $numStaff,
                $plan, $amount, $tx_id, $subscriptionCode,
                $renewalDate, $zaraCredits, $email
            );
        } else {
            $stmt->bind_param(
                "ssssssssssssiidsssi",
                $fullname, $username, $email, $phone, $country, $dob,
                $gender, $businessType, $businessName, $website,
                $currency, $numLocations, $numStaff,
                $plan, $amount, $tx_id, $subscriptionCode,
                $renewalDate, $zaraCredits
            );
        }
        continue;
    }

    error_log("subPlans DB write failed: " . $dbError);
    echo json_encode(["status" => "error", "message" => "Subscription could not be saved. Please contact support."]);
    exit;
}

try {

/* ===== TELEGRAM ===== */
$botToken   = getenv("TELEGRAM_BOT_TOKEN");
$chatId     = getenv("TELEGRAM_CHAT_ID");
$txRef      = $result['data']['tx_ref'] ?? '';
$txRefSafe  = str_replace('_', '\\_', $txRef);

$message  = "
💳 *New Payment Received!*

👤 *Name:* {$fullname}
🏢 *Business:* {$businessName}
📧 *Email:* {$email}
📞 *Phone:* {$phone}
📦 *Plan:* {$plan}
💰 *Amount:* ₦{$amount}
🔑 *Sub Code:* {$subscriptionCode}
📅 *Renewal:* {$renewalDate}
⚡ *Zara Credits:* {$zaraCredits}
🔗 *Transaction ID:* {$tx_id}
📌 *TX Ref:* {$txRefSafe}
";
$url     = "https://api.telegram.org/bot{$botToken}/sendMessage";
$payload = http_build_query(["chat_id" => $chatId, "text" => $message, "parse_mode" => "Markdown"]);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_exec($ch);
curl_close($ch);

// ── ADMIN ALERT EMAIL ──
sendEmail(
    "wsamson630@gmail.com",
    "💳 New Subscription: {$businessName} — {$plan}",
    "
    <p><strong>Name:</strong> {$fullname}</p>
    <p><strong>Business:</strong> {$businessName}</p>
    <p><strong>Email:</strong> {$email}</p>
    <p><strong>Phone:</strong> {$phone}</p>
    <p><strong>Plan:</strong> {$plan}</p>
    <p><strong>Amount:</strong> ₦{$amount}</p>
    <p><strong>Transaction ID:</strong> {$tx_id}</p>
    <p><strong>TX Ref:</strong> {$txRef}</p>
    <p><strong>Sub Code:</strong> {$subscriptionCode}</p>
    <p><strong>Renewal:</strong> {$renewalDate}</p>
    <p><strong>Zara Credits:</strong> {$zaraCredits}</p>
    "
);

/* ===== EMAIL ===== */
$firstName = explode(' ', trim($fullname))[0];

$emailBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your EnflowAI Subscription is Active</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap');
  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; background-color: #eae6de; font-family: 'DM Sans', Arial, sans-serif; }
  @media only screen and (max-width: 620px) {
    .email-wrapper { padding: 16px 12px !important; }
    .hero-block { padding: 40px 24px 36px !important; }
    .body-block { padding: 36px 24px 32px !important; }
    .footer-block { padding: 24px 20px !important; }
    .hero-title { font-size: 26px !important; line-height: 34px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#eae6de;">
<table width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#eae6de">
<tr><td class="email-wrapper" align="center" style="padding:40px 16px;">
  <table width="600" border="0" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(26,24,20,0.10);">
    <tr>
      <td class="hero-block" align="center" style="background:#1a1814;padding:52px 48px 48px;">
        <img src="https://plans.getenflowai.online/logo.png" alt="EnflowAI" width="140" style="display:block;height:auto;margin-bottom:32px;" />
        <table border="0" cellspacing="0" cellpadding="0" style="margin:0 auto 24px;">
          <tr><td align="center" style="background:rgba(160,120,72,0.12);border:1px solid rgba(201,168,112,0.28);border-radius:100px;padding:7px 18px;font-size:11px;font-weight:600;letter-spacing:0.14em;text-transform:uppercase;color:#c9a870;">✦ &nbsp; Payment Confirmed</td></tr>
        </table>
        <h1 class="hero-title" style="margin:0 0 14px;font-family:'DM Serif Display',Georgia,serif;font-size:34px;font-weight:400;color:rgba(255,255,255,0.90);">
          Welcome aboard,<br><em style="font-style:italic;color:#c9a870;">{$firstName}</em>
        </h1>
        <p style="margin:0 auto;font-size:15px;line-height:1.75;color:rgba(255,255,255,0.42);font-weight:300;max-width:380px;">Your payment was successful and your subscription is now active.</p>
      </td>
    </tr>
    <tr>
      <td class="body-block" style="background:#ffffff;padding:48px 48px 44px;">
        <p style="margin:0 0 10px;font-size:11px;font-weight:600;letter-spacing:0.16em;text-transform:uppercase;color:#a07848;">Your Subscription</p>
        <h2 style="margin:0 0 24px;font-family:'DM Serif Display',Georgia,serif;font-size:24px;font-weight:400;color:#1a1814;">Plan Details</h2>
        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:36px;border-radius:12px;overflow:hidden;border:1px solid rgba(26,24,20,0.08);">
          <tr><td style="padding:16px 20px;background:#faf9f7;border-bottom:1px solid rgba(26,24,20,0.06);">
            <p style="margin:0 0 4px;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Plan</p>
            <strong style="font-size:15px;color:#1a1814;">{$plan}</strong>
          </td></tr>
          <tr><td style="padding:16px 20px;background:#faf9f7;border-bottom:1px solid rgba(26,24,20,0.06);">
            <p style="margin:0 0 4px;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Amount Paid</p>
            <strong style="font-size:15px;color:#1a1814;">&#8358;{$amount}</strong>
          </td></tr>
          <tr><td style="padding:16px 20px;background:#faf9f7;border-bottom:1px solid rgba(26,24,20,0.06);">
            <p style="margin:0 0 4px;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Subscription Code</p>
            <strong style="font-size:15px;color:#1a1814;">{$subscriptionCode}</strong>
          </td></tr>
          <tr><td style="padding:16px 20px;background:#faf9f7;border-bottom:1px solid rgba(26,24,20,0.06);">
            <p style="margin:0 0 4px;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Next Renewal</p>
            <strong style="font-size:15px;color:#1a1814;">{$renewalDate}</strong>
          </td></tr>
          <tr><td style="padding:16px 20px;background:#faf9f7;">
            <p style="margin:0 0 4px;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Zara AI Credits</p>
            <strong style="font-size:15px;color:#a07848;">{$zaraCredits} credits</strong>
          </td></tr>
        </table>
        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:36px;">
          <tr><td align="center">
            <a href="https://getenflowai.online" style="display:inline-block;background:#1a1814;color:#ede9e1;text-decoration:none;padding:16px 36px;border-radius:10px;font-size:14.5px;font-weight:500;">Access Your Dashboard &nbsp;→</a>
          </td></tr>
        </table>
        <table width="100%" border="0" cellspacing="0" cellpadding="0">
          <tr><td style="background:#1a1814;border-radius:14px;padding:28px 30px;text-align:center;">
            <img src="https://plans.getenflowai.online/logo.png" alt="" style="display:block;margin:0 auto 14px;width:150px;height:auto;opacity:0.6;" />
            <p style="margin:0;font-family:'DM Serif Display',Georgia,serif;font-size:16px;font-style:italic;color:rgba(255,255,255,0.70);">"Built for Nigeria's food industry — adaptive, intelligent, and designed to scale with you."</p>
            <p style="margin:12px 0 0;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#c9a870;">The EnflowAI Team</p>
          </td></tr>
        </table>
      </td>
    </tr>
    <tr>
      <td class="footer-block" align="center" style="background:#f5f2ec;border-top:1px solid rgba(26,24,20,0.07);padding:32px 40px;">
        <p style="margin:0 0 6px;font-size:12.5px;line-height:1.75;color:#b8b0a4;">You're receiving this because you subscribed to EnflowAI.</p>
        <p style="margin:0 0 16px;font-size:12.5px;color:#b8b0a4;">Questions? <a href="mailto:hello@getenflowai.online" style="color:#a07848;text-decoration:none;">hello@getenflowai.online</a></p>
        <p style="margin:0;font-size:11.5px;color:#c8c2b8;">© 2026 EnflowAI. All rights reserved.</p>
      </td>
    </tr>
  </table>
</td></tr>
</table>
</body>
</html>
HTML;

sendEmail($email, "Your EnflowAI Subscription is Active 🎉", $emailBody);

} catch (\Throwable $e) {
    error_log("subPlans notification failure (non-fatal): " . $e->getMessage());
}

echo json_encode([
    "status"            => "success",
    "subscription_code" => $subscriptionCode,
    "renewal_date"      => $renewalDate,
    "zara_credits"      => $zaraCredits,
]);
