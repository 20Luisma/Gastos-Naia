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

$dbUrl = rtrim($_ENV['FIREBASE_DATABASE_URL'] ?? '', '/');
$secret = $_ENV['FIREBASE_SECRET'] ?? '';

if (empty($dbUrl) || empty($secret)) {
    die("ERROR: Faltan FIREBASE_DATABASE_URL o FIREBASE_SECRET en .env\n");
}

echo "Empezando volcado de Google Sheets a Firebase...\n";

$years = $repo->getAvailableYears();
$annualTotals = $repo->getAnnualTotals();
$annualByYear = [];
foreach ($annualTotals as $a) {
    $annualByYear[$a['year']] = $a['total'];
}

$firebaseData = [
    'last_sync' => date('Y-m-d H:i:s'),
    'years' => []
];

foreach ($years as $year) {
    echo "\nProcesando año $year...\n";
    $monthlyTotals = $repo->getMonthlyTotals($year);
    $annualTotal = $annualByYear[$year] ?? 0.0;

    $mesesData = [];
    $lastKnownPension = 0.0;

    // Pass 1: find fallback pension
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

        $summary = $repo->getMonthlyFinancialSummary($year, $month);
        $pension = $summary['pension'] > 0.0 ? $summary['pension'] : $lastKnownPension;
        if ($pension > 0.0) {
            $lastKnownPension = $pension;
        }

        $totalFinal = round($transferencia + $pension, 2);

        $expenses = $repo->getExpenses($year, $month);
        $items = [];
        foreach ($expenses as $e) {
            $items[] = [
                'date' => $e->getDate(),
                'desc' => $e->getDescription(),
                'amount' => $e->getAmount(),
            ];
        }

        $mesesData[$month] = [
            'mes' => $month,
            'nombre' => $mt['name'],
            'total_gastos' => $totalGastos,
            'transferencia_naia' => $transferencia,
            'pension' => $pension,
            'total_final' => $totalFinal,
            'gastos' => $items,
        ];
    }

    // Only save year if it has any actual data
    if ($annualTotal > 0.0 || !empty(array_filter($mesesData, fn($m) => !empty($m['gastos'])))) {
        // En Firebase evitaremos usar arrays secuenciales para los meses y años, 
        // usaremos objetos con las keys (ej. "2024", "1") para evitar problemas de índices vacíos.
        $firebaseData['years'][(string) $year] = [
            'year' => $year,
            'total_anual' => $annualTotal,
            'meses' => (object) $mesesData // Cast a object para obligar a que sea Diccionario en JSON JSON
        ];
        echo "  - Ok, guardados " . count($mesesData) . " meses.\n";
    } else {
        echo "  - Saltado (vacío).\n";
    }
}

echo "\nSubiendo a Firebase Realtime Database...\n";
$url = sprintf('%s/ai_context.json?auth=%s', $dbUrl, $secret);
$options = [
    'http' => [
        'method' => 'PUT',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($firebaseData, JSON_UNESCAPED_UNICODE)
    ]
];
$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === false) {
    echo "ERROR: Falló subida a Firebase.\n";
} else {
    echo "¡ÉXITO! Base de datos de IA volcada a Firebase.\n";
}
