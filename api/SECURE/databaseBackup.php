<?php
// backup.php
// Dumps the entire MySQL/MariaDB database using pure PHP (mysqli), then sends
// the resulting .sql content to a Google Apps Script Web App, which saves it
// straight into your Google Drive. No service account, no key creation needed.

// ── DB connection: reuses the same db.php every other endpoint uses ──
require_once __DIR__ . '/db.php'; // provides $conn

// EDIT THIS: paste your Apps Script Web App URL here
$apps_script_url = "https://script.google.com/macros/s/AKfycbxsY3yuJF9RSFrqs-vqfCXltRX03NQjBzMpb0l0AdP94RjEyf3xus9y72gfsx4yefD4hA/exec";
// ────────────────────────────────────────────────────────

header('Content-Type: application/json');

// ── Step 1: Dump every table to SQL using pure PHP ──────
$db_name = getenv("MYSQLDATABASE") ?: "database";
$sql = "-- Backup of $db_name — " . date('c') . "\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

$tablesResult = mysqli_query($conn, "SHOW TABLES");
$tables = [];
while ($row = mysqli_fetch_row($tablesResult)) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    $sql .= "-- Table: $table\n";
    $sql .= "DROP TABLE IF EXISTS `$table`;\n";

    $createRow = mysqli_fetch_row(mysqli_query($conn, "SHOW CREATE TABLE `$table`"));
    $sql .= $createRow[1] . ";\n\n";

    $dataResult = mysqli_query($conn, "SELECT * FROM `$table`");
    $numFields  = mysqli_num_fields($dataResult);

    while ($row = mysqli_fetch_row($dataResult)) {
        $sql .= "INSERT INTO `$table` VALUES(";
        for ($i = 0; $i < $numFields; $i++) {
            if ($row[$i] === null) {
                $sql .= "NULL";
            } else {
                $sql .= "'" . mysqli_real_escape_string($conn, $row[$i]) . "'";
            }
            if ($i < $numFields - 1) $sql .= ",";
        }
        $sql .= ");\n";
    }
    $sql .= "\n";
}

$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

$timestamp = date("Ymd_His");
$filename  = "backup_{$timestamp}.sql";
$localPath = __DIR__ . "/backups/" . $filename;

if (!is_dir(__DIR__ . "/backups")) {
    mkdir(__DIR__ . "/backups", 0777, true);
}
file_put_contents($localPath, $sql);

// ── Step 2: Send the SQL straight to the Apps Script Web App ──────
$ch = curl_init($apps_script_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $sql);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Apps Script redirects once, follow it

// FOR PRODUCTION
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// FOR LOCAL
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(["status" => "error", "message" => "curl error: $curlError", "local_file" => $localPath]);
    exit;
}

$result = json_decode($response, true);

if (isset($result['status']) && $result['status'] === 'success') {
    // Keep only the last 10 local backups so phone storage doesn't fill up
    $files = glob(__DIR__ . "/backups/*.sql");
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, 10) as $old) {
        unlink($old);
    }

    $driveFilename = $result['filename'] ?? $filename;

    // ── Send a Telegram alert confirming the backup succeeded ──────
    // EDIT THESE: your bot token, and a list of every chat id that should get the alert
    $botToken = "8929516825:AAGw1XNyT3U4H_RNHI21depIh4wrrYAQk00";
    $chatIds  = ["1843218039", "6506147307"];

    $alertMessage = "✅ *Database Backup Successful*\n\n📄 *File:* {$driveFilename}\n🕒 *Time:* " . date('Y-m-d H:i:s') . " UTC\n📁 *Location:* Google Drive — EnflowAI DB Backups";

    foreach ($chatIds as $chatId) {
        $tgCh = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
        curl_setopt($tgCh, CURLOPT_POST, true);
        curl_setopt($tgCh, CURLOPT_POSTFIELDS, http_build_query([
            "chat_id"    => $chatId,
            "text"       => $alertMessage,
            "parse_mode" => "Markdown"
        ]));
        curl_setopt($tgCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($tgCh, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($tgCh);
        curl_close($tgCh);
    }

    echo json_encode([
        "status"        => "success",
        "drive_filename" => $driveFilename
    ]);
} else {
    echo json_encode([
        "status"          => "error",
        "message"         => "Upload to Drive failed",
        "apps_script_raw" => $response,
        "local_file"      => $localPath
    ]);
}


