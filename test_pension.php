<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$config = require __DIR__ . '/config.php';

$client = new Google\Client();
$client->setApplicationName('Gastos Naia Debug');
$client->setScopes([Google\Service\Sheets::SPREADSHEETS_READONLY]);
$client->setAuthConfig($config['credentials_path']);

$repo = new GastosNaia\Infrastructure\GoogleSheetsExpenseRepository($client, $config);

// 1. Ver qué devuelve getMonthlyFinancialSummary nativo para Enero y Junio 2024
echo "=== RAW SHEET DATA 2024 ===\n";
$m1 = $repo->getMonthlyFinancialSummary(2024, 1);
echo "Enero 2024: Pension={$m1['pension']} EUR\n";
$m6 = $repo->getMonthlyFinancialSummary(2024, 6);
echo "Junio 2024: Pension={$m6['pension']} EUR\n";

// 2. Ejecutar AskAiUseCase y ver el JSON de caché generado
echo "\n=== CONTEXT GENERATION ===\n";
$useCase = new GastosNaia\Application\AskAiUseCase($repo);

// Forzamos borrado de la caché local primero
$cacheFile = __DIR__ . '/backups/ai_cache.json';
if (file_exists($cacheFile))
    unlink($cacheFile);

echo "Generating new cache...\n";
$useCase->execute("generame la cache"); // fallará en OpenAI pero generará el archivo

if (file_exists($cacheFile)) {
    $data = json_decode(file_get_contents($cacheFile), true);
    foreach ($data as $yearData) {
        if ($yearData['year'] == 2024) {
            echo "\n=== CACHÉ RESULTANTE PARA 2024 ===\n";
            foreach ($yearData['meses'] as $m) {
                echo "Mes {$m['mes']} ({$m['nombre']}): Pension={$m['pension']} EUR\n";
            }
        }
    }
} else {
    echo "ERROR: Caché no generada.\n";
}
