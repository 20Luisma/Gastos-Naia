<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GastosNaia\Infrastructure\GoogleSheetsExpenseRepository;
use GastosNaia\Infrastructure\GoogleDriveReceiptRepository;
use GastosNaia\Infrastructure\FirebaseBackupService;
use GastosNaia\Domain\Expense;
use Google\Client;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// Configure Google Client
$client = new Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? '');
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
$client->fetchAccessTokenWithRefreshToken($_ENV['GOOGLE_REFRESH_TOKEN'] ?? '');

$config = require __DIR__ . '/../config.php';

echo "Iniciando volcado masivo a Firebase...\n";

$expenseRepository = new GoogleSheetsExpenseRepository($client, $config);
$receiptRepository = new GoogleDriveReceiptRepository($client, $config);
$firebaseService = new FirebaseBackupService();

if (!$firebaseService->isConfigured()) {
    die("Error: Firebase no está configurado en el archivo .env\n");
}

$years = $expenseRepository->getAvailableYears();
$totalExpenses = 0;
$totalReceipts = 0;

$allData = [
    'expenses' => [],
    'receipts' => []
];

foreach ($years as $year) {
    echo "Procesando año: {$year}\n";
    for ($month = 1; $month <= 12; $month++) {
        // Obteniendo gastos
        $expenses = $expenseRepository->getExpenses($year, $month);
        foreach ($expenses as $expense) {
            $allData['expenses'][] = [
                'year' => $year,
                'month' => $month,
                'row' => $expense->getRow(),
                'date' => $expense->getDate(),
                'description' => $expense->getDescription(),
                'amount' => $expense->getAmount()
            ];
            $totalExpenses++;
        }

        // Obteniendo recibos
        $receipts = $receiptRepository->listReceipts($year, $month);
        foreach ($receipts as $receipt) {
            $receipt['year'] = $year;
            $receipt['month'] = $month;
            $allData['receipts'][] = $receipt;
            $totalReceipts++;
        }
    }
}

echo "Volcando {$totalExpenses} gastos y {$totalReceipts} recibos a Firebase...\n";

// Usamos el contexto de stream de PHP para subir el payload gigante
$firebaseUrl = rtrim($_ENV['FIREBASE_DATABASE_URL'], '/') . '/backups/initial_sync.json?auth=' . $_ENV['FIREBASE_SECRET'];
$payloadJson = json_encode($allData, JSON_UNESCAPED_UNICODE);

$options = [
    'http' => [
        'method' => 'PUT',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payloadJson,
        'timeout' => 60 // Un minuto de margen para todo el histórico
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($firebaseUrl, false, $context);

if ($result !== false) {
    echo "¡Volcado finalizado con éxito! Datos históricos respaldados.\n";
} else {
    echo "Error al subir los datos a Firebase. Compruebe la conexión o las claves.\n";
}
