<?php
// Mock PHP endpoint execution directly to see if any fatal error is thrown silently
require "vendor/autoload.php";
$config = require "config.php";

session_start();
$_SESSION['authenticated'] = true;

try {
    $api = new \GastosNaia\Presentation\ApiController($config);
    ob_start();
    // Simulate what app.js does
    $api->handle('getComunicados');
    $output = ob_get_clean();
    echo "SUCCESS OUT: " . $output . "\n";
} catch (\Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
}
