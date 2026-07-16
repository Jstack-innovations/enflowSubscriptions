<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit(); }

require_once __DIR__ . '/../../../SECURE/config.php';
require_once __DIR__ . '/../../../SECURE/auth.php';

$user = authenticate($pdo);

// --- Fetch local stats if local_server_url exists ---
$local_stats = null;
if (!empty($user["local_server_url"])) {
    $local_url = rtrim($user["local_server_url"], "/") . "/api/admins/GET/stats.php";
    
    
    //FOR PRODUCTION LIVE 
   // $local_stats = null;
//if (!empty($user["local_server_url"])) {
   // $local_url = rtrim($user["local_server_url"], "/") . "/stats";
    
$ch = curl_init($local_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-User-Email: " . $user["email"]
]);

// PRODUCTION (active)
//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
//curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// LOCAL DEV (uncomment these two + comment out the two above)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

    if ($http_code === 200 && $response) {
        $decoded = json_decode($response, true);
        if (isset($decoded["stats"])) {
            $local_stats = $decoded["stats"];
        }
    }
}

echo json_encode([
    "status" => "ok",
    "dashboard" => [
        "business" => [
            "name"     => $user["business_name"],
            "type"     => $user["business_type"],
            "logo_url" => $user["logo_url"],
            "country"  => $user["country"],
            "currency" => $user["currency"],
            "website"  => $user["website"],
            "software_url" => $user["software_url"],
        ],
        "account" => [
            "id"                => $user["id"],
            "fullname"          => $user["fullname"],
            "email"             => $user["email"],
            "phone"             => $user["phone"],
            "plan"              => $user["plan"],
            "status"            => $user["status"],
            "trial_ends_at"     => $user["trial_ends_at"],
            "renewal_date"      => $user["renewal_date"],
            "subscription_code" => $user["subscription_code"],
        ],
        "zara" => [
            "credits"      => $user["zara_credits"],
            "credits_used" => $user["zara_credits_used"],
            "credits_left" => $user["zara_credits"] - $user["zara_credits_used"],
        ],
        "team"            => $user["team_members"]    ? json_decode($user["team_members"])    : [],
        "connected_tools" => $user["connected_tools"] ? json_decode($user["connected_tools"]) : [],
        "zara_config" => [
            "brand_voice" => $user["zara_brand_voice"],
            "primary_lang"=> $user["zara_primary_lang"],
            "also_speaks" => $user["zara_also_speaks"] ? json_decode($user["zara_also_speaks"]) : [],
            "top_goals"   => $user["zara_top_goals"]   ? json_decode($user["zara_top_goals"])   : [],
            "hours"       => $user["zara_hours"]       ? json_decode($user["zara_hours"])       : [],
        ],
        "stats" => $local_stats,
    ],
]);
