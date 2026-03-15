<?php
require __DIR__ . '/vendor/autoload.php';

// Cargar .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$config = require __DIR__ . '/config.php';

try {
    echo "1. Iniciando cliente...\n";
    $client = new \Google\Client();
    $client->setAuthConfig(__DIR__ . '/credentials/service-account.json');
    $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);

    echo "2. Creando servicio Sheets...\n";

    $guzzleClient = new \GuzzleHttp\Client(['timeout' => 15]);
    $client->setHttpClient($guzzleClient);

    $service = new \Google\Service\Sheets($client);

    $spreadsheetId = $config['spreadsheets'][2026] ?? '';
    echo "3. Consultando Spreadsheet ID: " . $spreadsheetId . "...\n";

    $start = microtime(true);
    $result = $service->spreadsheets->get($spreadsheetId);
    $time = round(microtime(true) - $start, 2);

    echo "¡ÉXITO! Conexión a Google Sheets establecida en {$time} segundos.\n";
    echo "Título del documento: " . $result->getProperties()->getTitle() . "\n";

} catch (Exception $e) {
    echo "ERROR CATCH: " . $e->getMessage() . "\n";
}
