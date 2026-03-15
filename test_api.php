<?php
require __DIR__ . '/vendor/autoload.php';
// Mocker un array de config mínimo
$config = [
    'telegram_token' => 'test',
    'telegram_chat_id' => 'test',
];
try {
    $api = new \GastosNaia\Presentation\ApiController($config);
    echo "OK";
} catch (\Throwable $e) {
    echo "ERROR CAUGHT: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
