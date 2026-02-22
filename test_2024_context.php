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

// Mimic AskAiUseCase parsing logic specifically for ONE year: 2024
$year = 2024;
$monthlyTotals = $repo->getMonthlyTotals($year);

$meses = [];
$lastKnownPension = 0.0;

// First pass: find the earliest known pension in the year to use as fallback for empty months
foreach ($monthlyTotals as $mt) {
    if ($mt['total'] > 0.0) {
        $summary = $repo->getMonthlyFinancialSummary($year, $mt['month']);
        if ($summary['pension'] > 0.0) {
            $lastKnownPension = $summary['pension'];
            break;
        }
    }
}

foreach ($monthlyTotals as $mt) {
    $month = $mt['month'];
    $totalGastos = $mt['total'];
    $transferencia = $totalGastos > 0.0 ? round($totalGastos / 2, 2) : 0.0;

    // Get exact pension for THIS specific month
    $summary = $repo->getMonthlyFinancialSummary($year, $month);
    $pension = $summary['pension'] > 0.0 ? $summary['pension'] : $lastKnownPension;
    if ($pension > 0.0) {
        $lastKnownPension = $pension;
    }

    $meses[] = [
        'mes' => $month,
        'nombre' => $mt['name'],
        'total_gastos' => $totalGastos,
        'transferencia_naia' => $transferencia,
        'pension' => $pension,
        'total_final' => round($transferencia + $pension, 2)
    ];
}

$output = ['year' => 2024, 'meses' => $meses];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
