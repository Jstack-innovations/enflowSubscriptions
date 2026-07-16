<?php

$envFile = __DIR__ . '/.env';

/**
 * Only load .env if it exists (local dev)
 * In production, server env vars will be used automatically
 */
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) continue;

        putenv($line);
    }
}
