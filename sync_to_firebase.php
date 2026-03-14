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

echo "Empezando volcado ROBUSTO de Google Sheets a Firebase...\n";

function safeRead($callable, $repo, $retries = 3)
{
    for ($i = 0; $i < $retries; $i++) {
        $warningsBefore = count($repo->getWarnings());
        $result = $callable();
        $warningsAfter = count($repo->getWarnings());

        if ($warningsAfter > $warningsBefore) {
            echo "    [Warning] Falló lectura, esperando " . (5 * ($i + 1)) . " segundos y reintentando...\n";
            sleep(5 * ($i + 1));
        } else {
            return $result;
        }
    }
    return $callable(); // Last attempt
}

$years = $repo->getAvailableYears();
$annualTotals = safeRead(fn() => $repo->getAnnualTotals(), $repo);
sleep(1);

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
    $monthlyTotals = safeRead(fn() => $repo->getMonthlyTotals($year), $repo);
    sleep(1);

    $annualTotal = $annualByYear[$year] ?? 0.0;

    $mesesData = [];
    $lastKnownPension = 0.0;

    // Pass 1: find fallback pension
    foreach ($monthlyTotals as $mt) {
        if ($mt['total'] > 0.0) {
            $summary = safeRead(fn() => $repo->getMonthlyFinancialSummary($year, $mt['month']), $repo);
            sleep(1);
            if ($summary['pension'] > 0.0) {
                $lastKnownPension = $summary['pension'];
                break;
            }
        }
    }

    foreach ($monthlyTotals as $mt) {
        $month = $mt['month'];
        echo "  - Leyendo mes $month...\n";
        $summary = safeRead(fn() => $repo->getMonthlyFinancialSummary($year, $month), $repo);
        sleep(1); // 1s sleep for rate limit

        $totalGastos = $mt['total']; // mt['total'] is the exact arithmetic sum of the expense column

        // Use the explicit 'total a pagar' from the spreadsheet if available, otherwise fallback to dividing by 2
        if (isset($summary['transferencia_naia']) && $summary['transferencia_naia'] > 0.0) {
            $transferencia = $summary['transferencia_naia'];
        } else {
            $transferencia = $totalGastos > 0.0 ? round($totalGastos / 2, 2) : 0.0;
        }

        $expenses = safeRead(fn() => $repo->getExpenses($year, $month), $repo);
        sleep(1); // 1s sleep for rate limit

        if ($summary['pension'] == 0 && $transferencia == 0 && empty($expenses)) {
            $pension = 0.0;
        } else {
            if (isset($summary['pension']) && $summary['pension'] > 0.0) {
                $pension = $summary['pension'];
            } else {
                $pension = $lastKnownPension;
            }
        }

        if ($pension > 0.0) {
            $lastKnownPension = $pension;
        }

        $totalFinal = round($transferencia + $pension, 2);

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

    if ($annualTotal > 0.0 || !empty(array_filter($mesesData, fn($m) => !empty($m['gastos'])))) {
        $firebaseData['years'][(string) $year] = [
            'year' => $year,
            'total_anual' => $annualTotal,
            'meses' => (object) $mesesData
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
    echo "¡ÉXITO! Base de datos de IA volcada de forma ROBUSTA a Firebase.\n";
}
