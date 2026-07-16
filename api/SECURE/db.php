<?php
require_once __DIR__ . '/env.php';

$conn = null;

// =========================
// RAILWAY MYSQL (ENV VARIABLES)
// =========================
$host = getenv("MYSQLHOST");
$user = getenv("MYSQLUSER");
$pass = getenv("MYSQLPASSWORD");
$db   = getenv("MYSQLDATABASE");
$port = (int) getenv("MYSQLPORT");

// =========================
// CONNECTION
// =========================
$conn = new mysqli($host, $user, $pass, $db, $port);

// =========================
// CHECK CONNECTION
// =========================
if ($conn->connect_error) {
    die(json_encode([
        "status" => "error",
        "message" => "DB failed: " . $conn->connect_error
    ]));
}

// =========================
// SETTINGS
// =========================
$conn->query("SET time_zone = '+00:00'");
date_default_timezone_set("UTC");
$conn->set_charset("utf8mb4");

?>
