<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . "/../../SECURE/config.php";

$body           = json_decode(file_get_contents("php://input"), true);
$tx_ref         = trim($body["tx_ref"]         ?? "");
$transaction_id = trim($body["transaction_id"] ?? "");
$pack_id        = trim($body["pack_id"]        ?? "");
$email_from_local = trim($body["email"]        ?? "");

if (!$tx_ref || !$transaction_id || !$pack_id) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "step"    => "body",
        "message" => "Missing fields.",
        "got"     => [
            "tx_ref"         => $tx_ref,
            "transaction_id" => $transaction_id,
            "pack_id"        => $pack_id,
            "email"          => $email_from_local,
        ],
    ]);
    exit();
}

$PACKS = [
    "starter"    => ["credits" => 500,   "price" => 52250],
    "basic"      => ["credits" => 1000,  "price" => 101200],
    "standard"   => ["credits" => 2500,  "price" => 242000],
    "popular"    => ["credits" => 3000,  "price" => 280500],
    "pro"        => ["credits" => 5000,  "price" => 451000],
    "business"   => ["credits" => 7000,  "price" => 600600],
    "enterprise" => ["credits" => 10000, "price" => 825000],
];

if (!isset($PACKS[$pack_id])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "step" => "pack", "message" => "Invalid pack: $pack_id"]);
    exit();
}

$expectedCredits = $PACKS[$pack_id]["credits"];
$expectedPrice   = $PACKS[$pack_id]["price"];

try {
    $dupCheck = $pdo->prepare("SELECT id FROM zara_topup_logs WHERE transaction_id = :transaction_id LIMIT 1");
    $dupCheck->execute([":transaction_id" => $transaction_id]);
    if ($dupCheck->fetch()) {
        http_response_code(409);
        echo json_encode(["status" => "error", "step" => "duplicate", "message" => "Already processed."]);
        exit();
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "step" => "duplicate_check", "message" => $e->getMessage()]);
    exit();
}

ob_start();
include __DIR__ . '/../../SECURE/flutterwave-key.php';
$keyOutput  = ob_get_clean();
$keyData    = json_decode($keyOutput, true);
$FLW_SECRET = $keyData['secretKey'] ?? '';

if (!$FLW_SECRET) {
    http_response_code(500);
    echo json_encode(["status" => "error", "step" => "secret_key", "message" => "Secret key not found."]);
    exit();
}

$ch = curl_init("https://api.flutterwave.com/v3/transactions/{$transaction_id}/verify");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,

    // FOR PRODUCTION
    // CURLOPT_SSL_VERIFYPEER => true,
    // CURLOPT_SSL_VERIFYHOST => 2,

    // FOR LOCAL
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,

    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$FLW_SECRET}",
        "Content-Type: application/json",
    ],
]);
$res      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode([
        "status"    => "error",
        "step"      => "flw_verify_request",
        "http_code" => $httpCode,
        "curl_err"  => $curlErr,
    ]);
    exit();
}

$flw    = json_decode($res, true);
$txData = $flw["data"] ?? null;

$checks = [
    "flw_status"   => ($flw["status"]    ?? "") === "success",
    "tx_status"    => in_array($txData["status"] ?? "", ["successful", "completed"]),
    "tx_ref_match" => ($txData["tx_ref"] ?? "") === $tx_ref,
    "amount_ok"    => (float)($txData["amount"]   ?? 0) >= $expectedPrice,
    "currency_ok"  => strtoupper($txData["currency"] ?? "") === "NGN",
];

$allPassed = !in_array(false, $checks, true);

if (!$allPassed) {
    http_response_code(402);
    echo json_encode([
        "status"  => "error",
        "step"    => "flw_validation",
        "checks"  => $checks,
        "flw_raw" => [
            "flw_status"     => $flw["status"]      ?? null,
            "tx_status"      => $txData["status"]   ?? null,
            "tx_ref"         => $txData["tx_ref"]   ?? null,
            "amount"         => $txData["amount"]   ?? null,
            "currency"       => $txData["currency"] ?? null,
            "expected_price" => $expectedPrice,
        ],
    ]);
    exit();
}

$email = $txData["customer"]["email"] ?? "";
if (!$email || str_contains($email, 'ravesb_')) {
    $email = $email_from_local;
}

if (!$email) {
    http_response_code(400);
    echo json_encode(["status" => "error", "step" => "email", "message" => "No email found."]);
    exit();
}

$pdo->beginTransaction();

try {
    $update = $pdo->prepare("
        UPDATE subscriptions
        SET zara_credits = zara_credits + :credits
        WHERE LOWER(email) = LOWER(:email)
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $update->execute([":credits" => $expectedCredits, ":email" => $email]);

    if ($update->rowCount() === 0) {
        throw new Exception("No subscription found for email: $email");
    }

    $log = $pdo->prepare("
        INSERT INTO zara_topup_logs (email, transaction_id, pack_id, credits, amount)
        VALUES (:email, :transaction_id, :pack_id, :credits, :amount)
    ");
    $log->execute([
        ":email"          => $email,
        ":transaction_id" => $transaction_id,
        ":pack_id"        => $pack_id,
        ":credits"        => $expectedCredits,
        ":amount"         => $txData["amount"],
    ]);

    $pdo->commit();

    // Reset low credit alert so it can fire again next top-up
$pdo->prepare("
    UPDATE subscriptions SET low_credit_alert_sent = 0
    WHERE LOWER(email) = LOWER(:email)
")->execute([":email" => $email]);
    

    // ── TELEGRAM ──
    $botToken = getenv("TELEGRAM_BOT_TOKEN");
    $chatId   = getenv("TELEGRAM_CHAT_ID");
    $amount   = $txData["amount"];

    $message = "
⚡ *Zara Credits Top-Up!*

📧 *Email:* {$email}
📦 *Pack:* {$pack_id}
💳 *Credits Added:* {$expectedCredits}
💰 *Amount Paid:* ₦{$amount}
🔗 *Transaction ID:* {$transaction_id}
    ";

    $tlgUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $tlgPayload = http_build_query([
        "chat_id"    => $chatId,
        "text"       => $message,
        "parse_mode" => "Markdown"
    ]);

    $tlg = curl_init($tlgUrl);
    curl_setopt($tlg, CURLOPT_POST, true);
    curl_setopt($tlg, CURLOPT_POSTFIELDS, $tlgPayload);
    curl_setopt($tlg, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($tlg, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($tlg);
    curl_close($tlg);

    // ── EMAIL ──
    require_once __DIR__ . '/../../SECURE/resendMail.php';

    $packNames = [
        "starter"    => "Starter Pack",
        "basic"      => "Basic Pack",
        "standard"   => "Standard Pack",
        "popular"    => "Popular Pack",
        "pro"        => "Pro Pack",
        "business"   => "Business Pack",
        "enterprise" => "Enterprise Pack",
    ];
    $packName = $packNames[$pack_id] ?? ucfirst($pack_id) . " Pack";

    $emailBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zara Credits Added</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap');
  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; background-color: #eae6de; font-family: 'DM Sans', Arial, sans-serif; -webkit-font-smoothing: antialiased; }
  @media only screen and (max-width: 620px) {
    .email-wrapper { padding: 16px 12px !important; }
    .main-card { border-radius: 16px !important; }
    .hero-block { padding: 40px 24px 36px !important; }
    .body-block { padding: 36px 24px 32px !important; }
    .footer-block { padding: 24px 20px !important; }
    .hero-title { font-size: 26px !important; line-height: 34px !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background-color:#eae6de;">
<table width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#eae6de">
<tr>
<td class="email-wrapper" align="center" style="padding:40px 16px;">
  <table class="main-card" width="600" border="0" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;border-radius:20px;overflow:hidden;box-shadow:0 4px 6px rgba(26,24,20,0.04),0 20px 60px rgba(26,24,20,0.10);">

    <!-- HERO -->
    <tr>
      <td class="hero-block" align="center" style="background:#1a1814;padding:52px 48px 48px;">
        <table border="0" cellspacing="0" cellpadding="0" style="margin-bottom:32px;">
          <tr><td align="center">
            <img src="https://plans.getenflowai.online/logo.png" alt="EnflowAI" width="140" style="display:block;height:auto;max-height:48px;width:auto;max-width:140px;object-fit:contain;" />
          </td></tr>
        </table>
        <table border="0" cellspacing="0" cellpadding="0" width="48" style="margin:0 auto 28px;">
          <tr><td height="1" style="background:linear-gradient(90deg,transparent,rgba(201,168,112,0.6),transparent);line-height:1px;font-size:1px;">&nbsp;</td></tr>
        </table>
        <table border="0" cellspacing="0" cellpadding="0" style="margin:0 auto 24px;">
          <tr><td align="center" style="background:rgba(160,120,72,0.12);border:1px solid rgba(201,168,112,0.28);border-radius:100px;padding:7px 18px;font-family:'DM Sans',Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:0.14em;text-transform:uppercase;color:#c9a870;">
            ⚡ &nbsp; Credits Added Successfully
          </td></tr>
        </table>
        <table border="0" cellspacing="0" cellpadding="0" width="100%" style="margin-bottom:32px;">
          <tr><td align="center" style="border-radius:14px;overflow:hidden;line-height:0;">
            <img src="https://images.unsplash.com/photo-1677442135136-760c813028c0?q=80&w=1032&auto=format&fit=crop" alt="Restaurant" width="504" style="display:block;width:100%;max-width:504px;height:auto;border-radius:14px;filter:brightness(0.75) saturate(0.9);" />
          </td></tr>
        </table>
        <h1 class="hero-title" style="margin:0 0 14px;font-family:'DM Serif Display',Georgia,serif;font-size:34px;font-weight:400;line-height:1.2;color:rgba(255,255,255,0.90);letter-spacing:-0.01em;">
          Your Zara credits<br><em style="font-style:italic;color:#c9a870;">are ready</em>
        </h1>
        <p style="margin:0 auto;font-family:'DM Sans',Arial,sans-serif;font-size:15px;line-height:1.75;color:rgba(255,255,255,0.42);font-weight:300;max-width:380px;">
          Your top-up was successful. {$expectedCredits} Zara AI credits have been added to your account.
        </p>
      </td>
    </tr>

    <!-- BODY -->
    <tr>
      <td class="body-block" style="background:#ffffff;padding:48px 48px 44px;">

        <p style="margin:0 0 10px;font-family:'DM Sans',Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:0.16em;text-transform:uppercase;color:#a07848;">Top-Up Summary</p>
        <h2 style="margin:0 0 24px;font-family:'DM Serif Display',Georgia,serif;font-size:24px;font-weight:400;line-height:1.4;color:#1a1814;">Transaction Details</h2>

        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:36px;border-radius:12px;overflow:hidden;border:1px solid rgba(26,24,20,0.08);">
          <tr><td style="padding:16px 20px;background:#faf9f7;border-bottom:1px solid rgba(26,24,20,0.06);">
            <p style="margin:0 0 4px;font-family:'DM Sans',Arial,sans-serif;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Pack</p>
            <strong style="font-family:'DM Sans',Arial,sans-serif;font-size:15px;color:#1a1814;font-weight:500;">{$packName}</strong>
          </td></tr>
          <tr><td style="padding:16px 20px;background:#faf9f7;border-bottom:1px solid rgba(26,24,20,0.06);">
            <p style="margin:0 0 4px;font-family:'DM Sans',Arial,sans-serif;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Credits Added</p>
            <strong style="font-family:'DM Sans',Arial,sans-serif;font-size:15px;color:#a07848;font-weight:500;">{$expectedCredits} credits</strong>
          </td></tr>
          <tr><td style="padding:16px 20px;background:#faf9f7;border-bottom:1px solid rgba(26,24,20,0.06);">
            <p style="margin:0 0 4px;font-family:'DM Sans',Arial,sans-serif;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Amount Paid</p>
            <strong style="font-family:'DM Sans',Arial,sans-serif;font-size:15px;color:#1a1814;font-weight:500;">&#8358;{$amount}</strong>
          </td></tr>
          <tr><td style="padding:16px 20px;background:#faf9f7;">
            <p style="margin:0 0 4px;font-family:'DM Sans',Arial,sans-serif;font-size:11px;color:#9e9589;text-transform:uppercase;letter-spacing:0.08em;">Transaction ID</p>
            <strong style="font-family:'DM Sans',Arial,sans-serif;font-size:15px;color:#1a1814;font-weight:500;">{$transaction_id}</strong>
          </td></tr>
        </table>

        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:36px;"><tr><td height="1" style="background:rgba(26,24,20,0.07);line-height:1px;font-size:1px;">&nbsp;</td></tr></table>

        <p style="margin:0 0 10px;font-family:'DM Sans',Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:0.16em;text-transform:uppercase;color:#a07848;">What's Zara?</p>
        <h2 style="margin:0 0 20px;font-family:'DM Serif Display',Georgia,serif;font-size:22px;font-weight:400;line-height:1.4;color:#1a1814;">Your AI-powered business assistant</h2>

        <table border="0" cellspacing="0" cellpadding="0" width="100%" style="margin-bottom:28px;"><tr><td align="center" style="border-radius:12px;overflow:hidden;line-height:0;">
          <img src="https://images.unsplash.com/photo-1767950467836-ef59566126c3?q=80&w=1033&auto=format&fit=crop" alt="AI Assistant" width="504" style="display:block;width:100%;max-width:504px;height:200px;object-fit:cover;border-radius:12px;filter:brightness(0.88) saturate(0.85);" />
        </td></tr></table>

        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:36px;">
          <tr><td style="padding-bottom:14px;"><table border="0" cellspacing="0" cellpadding="0"><tr>
            <td valign="top" style="padding-right:14px;padding-top:2px;"><div style="width:22px;height:22px;background:rgba(160,120,72,0.10);border:1px solid rgba(160,120,72,0.25);border-radius:50%;text-align:center;line-height:22px;font-size:10px;color:#a07848;">✓</div></td>
            <td><p style="margin:0;font-family:'DM Sans',Arial,sans-serif;font-size:14px;font-weight:500;color:#1a1814;line-height:1.5;">Answer customer questions automatically</p><p style="margin:4px 0 0;font-family:'DM Sans',Arial,sans-serif;font-size:13px;color:#9e9589;font-weight:300;line-height:1.6;">Zara handles enquiries 24/7 so you don't have to.</p></td>
          </tr></table></td></tr>
          <tr><td style="padding-bottom:14px;"><table border="0" cellspacing="0" cellpadding="0"><tr>
            <td valign="top" style="padding-right:14px;padding-top:2px;"><div style="width:22px;height:22px;background:rgba(160,120,72,0.10);border:1px solid rgba(160,120,72,0.25);border-radius:50%;text-align:center;line-height:22px;font-size:10px;color:#a07848;">✓</div></td>
            <td><p style="margin:0;font-family:'DM Sans',Arial,sans-serif;font-size:14px;font-weight:500;color:#1a1814;line-height:1.5;">Generate reports and insights</p><p style="margin:4px 0 0;font-family:'DM Sans',Arial,sans-serif;font-size:13px;color:#9e9589;font-weight:300;line-height:1.6;">Get smart summaries of your business performance on demand.</p></td>
          </tr></table></td></tr>
          <tr><td><table border="0" cellspacing="0" cellpadding="0"><tr>
            <td valign="top" style="padding-right:14px;padding-top:2px;"><div style="width:22px;height:22px;background:rgba(160,120,72,0.10);border:1px solid rgba(160,120,72,0.25);border-radius:50%;text-align:center;line-height:22px;font-size:10px;color:#a07848;">✓</div></td>
            <td><p style="margin:0;font-family:'DM Sans',Arial,sans-serif;font-size:14px;font-weight:500;color:#1a1814;line-height:1.5;">Automate repetitive tasks</p><p style="margin:4px 0 0;font-family:'DM Sans',Arial,sans-serif;font-size:13px;color:#9e9589;font-weight:300;line-height:1.6;">From order confirmations to staff updates — Zara handles it.</p></td>
          </tr></table></td></tr>
        </table>

        <!-- CTA -->
        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom:36px;">
          <tr><td align="center">
            <a href="https://getenflowai.online" style="display:inline-block;background:#1a1814;color:#ede9e1;text-decoration:none;padding:16px 36px;border-radius:10px;font-family:'DM Sans',Arial,sans-serif;font-size:14.5px;font-weight:500;letter-spacing:0.02em;">
              Know More &nbsp;→
            </a>
          </td></tr>
        </table>

        <!-- Brand strip -->
        <table width="100%" border="0" cellspacing="0" cellpadding="0">
          <tr><td style="background:#1a1814;border-radius:14px;padding:28px 30px;text-align:center;">
            <img src="https://plans.getenflowai.online/logo.png" alt="" style="display:block;margin:0 auto 14px;width:150px;height:auto;opacity:0.6;" />
            <p style="margin:0;font-family:'DM Serif Display',Georgia,serif;font-size:16px;font-style:italic;color:rgba(255,255,255,0.70);line-height:1.7;">"Built for Nigeria's food industry — adaptive, intelligent, and designed to scale with you."</p>
            <p style="margin:12px 0 0;font-family:'DM Sans',Arial,sans-serif;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#c9a870;font-weight:500;">The EnflowAI Team</p>
          </td></tr>
        </table>
      </td>
    </tr>

    <!-- FOOTER -->
    <tr>
      <td class="footer-block" align="center" style="background:#f5f2ec;border-top:1px solid rgba(26,24,20,0.07);padding:32px 40px;">
        <table border="0" cellspacing="0" cellpadding="0" style="margin:0 auto 20px;">
          <tr><td align="center">
            <img src="https://plans.getenflowai.online/icon.png" alt="EnflowAI" width="90" style="display:block;height:auto;max-height:30px;width:auto;max-width:90px;opacity:0.45;" />
          </td></tr>
        </table>
        <p style="margin:0 0 6px;font-family:'DM Sans',Arial,sans-serif;font-size:12.5px;line-height:1.75;color:#b8b0a4;font-weight:300;">You're receiving this because you topped up Zara credits on EnflowAI.</p>
        <p style="margin:0 0 16px;font-family:'DM Sans',Arial,sans-serif;font-size:12.5px;line-height:1.75;color:#b8b0a4;font-weight:300;">Questions? Reach us at <a href="mailto:hello@getenflowai.online" style="color:#a07848;text-decoration:none;">hello@getenflowai.online</a></p>
        <table border="0" cellspacing="0" cellpadding="0" style="margin:0 auto 16px;">
          <tr>
            <td style="padding:0 8px;"><a href="#" style="font-family:'DM Sans',Arial,sans-serif;font-size:12px;color:#b8b0a4;text-decoration:none;">Privacy Policy</a></td>
            <td style="color:#d9d3c7;font-size:12px;">·</td>
            <td style="padding:0 8px;"><a href="#" style="font-family:'DM Sans',Arial,sans-serif;font-size:12px;color:#b8b0a4;text-decoration:none;">Terms</a></td>
          </tr>
        </table>
        <p style="margin:0;font-family:'DM Sans',Arial,sans-serif;font-size:11.5px;color:#c8c2b8;font-weight:300;letter-spacing:0.02em;">© 2026 EnflowAI. All rights reserved.</p>
      </td>
    </tr>

  </table>
</td>
</tr>
</table>
</body>
</html>
HTML;

    sendEmail(
        $email,
        "Your Zara Credits Have Been Added ⚡",
        $emailBody
    );

    echo json_encode([
        "status"  => "success",
        "message" => "Credits added successfully.",
        "credits" => $expectedCredits,
        "pack"    => $pack_id,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "step" => "db", "message" => $e->getMessage()]);
}
