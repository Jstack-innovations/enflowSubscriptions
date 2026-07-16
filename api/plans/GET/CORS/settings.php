<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../../../SECURE/config.php';

$setting = $pdo->query("SELECT setting_value FROM enflow_settings WHERE setting_key = 'trial_days' LIMIT 1")->fetch();
$trialDays = (int)($setting["setting_value"] ?? 5);

echo json_encode([
    "trial_days" => $trialDays
]);