<?php

declare(strict_types=1);

namespace GastosNaia\Infrastructure;

use GastosNaia\Domain\ExpenseRepositoryInterface;

class FirebaseWriteRepository
{
    private string $databaseUrl;
    private string $secret;

    public function __construct()
    {
        $dbUrl = $_ENV['FIREBASE_DATABASE_URL'] ?? $_SERVER['FIREBASE_DATABASE_URL'] ?? getenv('FIREBASE_DATABASE_URL');
        $this->databaseUrl = rtrim(is_string($dbUrl) ? $dbUrl : '', '/');

        $sec = $_ENV['FIREBASE_SECRET'] ?? $_SERVER['FIREBASE_SECRET'] ?? getenv('FIREBASE_SECRET');
        $this->secret = is_string($sec) ? $sec : '';
    }

    public function isConfigured(): bool
    {
        return $this->databaseUrl !== '' && $this->secret !== '';
    }

    /**
     * Sincroniza SOLO el año especificado leyendo de Google Sheets a Firebase `/ai_context/years/{year}`.
     * Método asíncrono fire-and-forget (ignora errores de red).
     */
    public function syncYearFast(int $year, ExpenseRepositoryInterface $repo): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $monthlyTotals = $repo->getMonthlyTotals($year);
            $annualTotals = $repo->getAnnualTotals();
            $annualTotal = 0.0;
            foreach ($annualTotals as $a) {
                if ($a['year'] === $year) {
                    $annualTotal = $a['total'];
                    break;
                }
            }

            $mesesData = [];
            $lastKnownPension = 0.0;

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
                // Como el Excel ya precalcula en la primera pestaña el 'Total/2' (y de ahí saca el dato el scraper),
                // el monto recuperado ya es la transferencia.
                $transferencia = $mt['total'];
                $totalGastos = $transferencia > 0.0 ? round($transferencia * 2, 2) : 0.0;

                $summary = $repo->getMonthlyFinancialSummary($year, $month);

                // Si el mes está completamente vacío (no reporta pensión ni transferencia, ej. un mes futuro),
                // no debemos aplicarle la última pensión porque desvirtúa el total anual del año en curso.
                if ($summary['pension'] == 0 && $transferencia == 0 && empty($repo->getExpenses($year, $month))) {
                    $pension = 0.0;
                } else {
                    $pension = $summary['pension'] > 0.0 ? $summary['pension'] : $lastKnownPension;
                }
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

            // Update only this specific year in Firebase
            $firebaseData = [
                'year' => $year,
                'total_anual' => $annualTotal,
                'meses' => (object) $mesesData
            ];

            // Envía la subida en background
            $url = sprintf('%s/ai_context/years/%s.json?auth=%s', $this->databaseUrl, $year, $this->secret);
            $options = [
                'http' => [
                    'method' => 'PUT',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode($firebaseData, JSON_UNESCAPED_UNICODE),
                    'timeout' => 2 // super short timeout to not block UI
                ]
            ];
            $context = stream_context_create($options);
            @file_get_contents($url, false, $context);

            // Touch master timestamp so AI knows exactly when data changed
            $urlTime = sprintf('%s/ai_context/last_sync.json?auth=%s', $this->databaseUrl, $this->secret);
            $optionsTime = [
                'http' => [
                    'method' => 'PUT',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode(date('Y-m-d H:i:s')),
                    'timeout' => 1
                ]
            ];
            $contextTime = stream_context_create($optionsTime);
            @file_get_contents($urlTime, false, $contextTime);

        } catch (\Throwable $e) {
            // Falla en silencio - Eventual consistency on next sync or manual sync
        }
    }
}
