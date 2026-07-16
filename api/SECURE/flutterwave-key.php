<?php
// flutterwave-key.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Use environment variables for security
$publicKey = getenv('FLUTTERWAVE_PUBLIC_KEY') ?: 'FLWPUBK_TEST-3b1b4a951a2556f7c3c25d24d2a087de-X';
$secretKey = getenv('FLWSECK_TEST') ?: 'FLWSECK_TEST-a763fa2c6e51571bd7acb803c6e0ec53-X';

// Only return the secret key in trusted backend calls (optional: you could restrict by IP)
echo json_encode([
    'publicKey' => $publicKey,
    'secretKey' => $secretKey
]);