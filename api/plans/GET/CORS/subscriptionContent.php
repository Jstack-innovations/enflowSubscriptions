<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$file = __DIR__ . "/../JSON/plans.json";

if (!file_exists($file)) {
    http_response_code(404);
    echo json_encode(["error" => "plans not found"]);
    exit;
}

echo file_get_contents($file);