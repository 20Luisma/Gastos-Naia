<?php

namespace GastosNaia\Infrastructure;

use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

class GoogleSheetsExpenseRepository implements ExpenseRepositoryInterface
{
    private Sheets $sheets;
    private array $config;
    private array $warnings = [];

    public function __construct(Client $client, array $config)
    {
        $this->config = $config;
        $this->sheets = new Sheets($client);
    }

    public function getAnnualTotals(): array
    {
        $results = [];

        foreach ($this->config['spreadsheets'] as $year => $spreadsheetId) {
            $total = $this->findTotalFinal($spreadsheetId);
            $results[] = [
                'year' => (int) $year,
                'total' => $total ?? 0.0,
            ];
        }

        usort($results, fn($a, $b) => $a['year'] <=> $b['year']);
        return $results;
    }

    public function getMonthlyTotals(int $year): array
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $monthLabels = $this->config['month_labels'];
        $monthSheets = $this->config['months'];

        $results = [];

        for ($m = 1; $m <= 12; $m++) {
            $sheetName = $monthSheets[$m] ?? null;
            $total = 0.0;

            if ($sheetName) {
                // Leemos columnas A, B y C para poder detectar la fila de subtotal
                $range = "{$sheetName}!A1:C200";
                try {
                    $response = $this->sheets->spreadsheets_values->get(
                        $spreadsheetId,
                        $range,
                        ['valueRenderOption' => 'UNFORMATTED_VALUE']
                    );
                    $values = $response->getValues() ?? [];

                    // Sumamos la columna C (index 2) saltando la cabecera y parando en subtotales
                    for ($i = 1; $i < count($values); $i++) {
                        $row = $values[$i];
                        $cellA = trim((string) ($row[0] ?? ''));
                        $cellB = trim((string) ($row[1] ?? ''));
                        $cellC = $row[2] ?? null;

                        if (empty($cellA) && empty($cellB) && $cellC === null) {
                            continue; // fila vacía, seguir
                        }
                        if ($this->isSubtotalRow($cellA, $cellB)) {
                            break; // llegamos al resumen, parar
                        }

                        $amount = is_numeric($cellC)
                            ? (float) $cellC
                            : ($this->parseMoneyValue((string) $cellC) ?? 0.0);

                        $total += $amount;
                    }
                } catch (\Exception $e) {
                    $this->warnings[] = "Error leyendo {$sheetName} para totales mensuales: " . $e->getMessage();
                }
            }

            $results[] = [
                'month' => $m,
                'name' => $monthLabels[$m],
                'total' => round($total, 2),
            ];
        }

        return $results;
    }


    public function getExpenses(int $year, int $month): array
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        $range = "{$sheetName}!A1:C200";

        try {
            $response = $this->sheets->spreadsheets_values->get(
                $spreadsheetId,
                $range,
                ['valueRenderOption' => 'FORMATTED_VALUE']
            );
            $values = $response->getValues() ?? [];
        } catch (\Exception $e) {
            $this->warnings[] = "Error leyendo gastos {$sheetName} {$year}: " . $e->getMessage();
            return [];
        }

        $expenses = [];
        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];

            $date = trim($row[0] ?? '');
            $desc = trim($row[1] ?? '');
            $amountRaw = $row[2] ?? '';

            if (empty($date) && empty($desc) && empty($amountRaw)) {
                continue;
            }
            if ($this->isSubtotalRow($date, $desc)) {
                break;
            }
            if (empty($date)) {
                continue;
            }

            $amount = $this->parseMoneyValue($amountRaw) ?? 0.0;

            $expenses[] = new Expense(
                $date,
                $desc,
                $amount,
                $i + 1
            );
        }

        return $expenses;
    }

    public function getMonthlyFinancialSummary(int $year, int $month): array
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        $empty = ['total_gastos' => 0.0, 'transferencia_naia' => 0.0, 'pension' => 0.0, 'total_final' => 0.0];

        if (!$sheetName) {
            return $empty;
        }

        // Scan columns D, E & F (up to row 200) searching for labels — handles any number of expense rows
        $range = "{$sheetName}!D1:F200";

        try {
            $response = $this->sheets->spreadsheets_values->get(
                $spreadsheetId,
                $range,
                ['valueRenderOption' => 'UNFORMATTED_VALUE']
            );
            $rows = $response->getValues() ?? [];
        } catch (\Exception $e) {
            $this->warnings[] = "Error leyendo resumen financiero {$sheetName} {$year}: " . $e->getMessage();
            return $empty;
        }

        $result = $empty;

        foreach ($rows as $row) {
            // Each row may have [labelD, valueE, extraF] or just [labelD, valueE]
            $label = is_string($row[0] ?? null) ? strtolower(trim($row[0])) : '';
            $value = null;
            // Look for a numeric value in column E (index 1) or F (index 2)
            for ($c = 1; $c <= 2; $c++) {
                if (isset($row[$c]) && is_numeric($row[$c]) && (float) $row[$c] > 0) {
                    $value = (float) $row[$c];
                    break;
                }
            }
            if ($value === null)
                continue;

            // Match labels (flexible – any variation the user might have used)
            if (str_contains($label, 'total a pagar') || str_contains($label, 'total/2') || str_contains($label, 'total /2')) {
                $result['transferencia_naia'] = $value;
            } elseif (str_contains($label, 'pensión') || str_contains($label, 'pension') || str_contains($label, 'pensio')) {
                $result['pension'] = $value;
            } elseif (str_contains($label, 'total final')) {
                $result['total_final'] = $value;
                // Also derive total_gastos = total_final * 2 - pension if not found yet
            } elseif (str_contains($label, 'total') && !str_contains($label, 'final') && !str_contains($label, 'pagar')) {
                // Generic "Total:" or "Total:" row → raw sum of expenses
                $result['total_gastos'] = $value;
            }
        }

        // If we couldn't find total_gastos explicitly, derive it from transferencia_naia * 2
        if ($result['total_gastos'] === 0.0 && $result['transferencia_naia'] > 0.0) {
            $result['total_gastos'] = round($result['transferencia_naia'] * 2, 2);
        }

        // Derive total_final if missing
        if ($result['total_final'] === 0.0 && $result['transferencia_naia'] > 0.0) {
            $result['total_final'] = round($result['transferencia_naia'] + $result['pension'], 2);
        }

        return $result;
    }

    public function setPension(int $year, int $month, float $amount): bool
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        // Leer columnas D y E (las del resumen financiero)
        $range = "{$sheetName}!D1:E200";
        try {
            $response = $this->sheets->spreadsheets_values->get(
                $spreadsheetId,
                $range,
                ['valueRenderOption' => 'UNFORMATTED_VALUE']
            );
            $rows = $response->getValues() ?? [];
        } catch (\Exception $e) {
            throw new \Exception("Error leyendo resumen financiero {$sheetName}: " . $e->getMessage());
        }

        $targetRowIndex = -1;
        $targetColumn = 'E'; // Asumimos que el valor va a la derecha inmediatamente, o E o F

        foreach ($rows as $index => $row) {
            $label = is_string($row[0] ?? null) ? strtolower(trim($row[0])) : '';
            if (str_contains($label, 'pensión') || str_contains($label, 'pension') || str_contains($label, 'pensio')) {
                // Las filas en Sheets son 1-indexed
                $targetRowIndex = $index + 1;
                break;
            }
        }

        if ($targetRowIndex === -1) {
            throw new \Exception("No se encontró la celda de Pensión en la hoja de {$sheetName}.");
        }

        $writeRange = "{$sheetName}!E{$targetRowIndex}";
        $body = new ValueRange([
            'values' => [[$amount]]
        ]);

        try {
            $this->sheets->spreadsheets_values->update(
                $spreadsheetId,
                $writeRange,
                $body,
                ['valueInputOption' => 'USER_ENTERED']
            );
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Error guardando pensión: " . $e->getMessage());
        }
    }

    public function addExpense(int $year, int $month, Expense $expense): bool
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        // Usamos la API Append de Google Sheets: encuentra automáticamente el primer
        // espacio libre al final de los datos y añade la fila ahí, sin pisar
        // filas de subtotales ni fórmulas existentes.
        $range = "{$sheetName}!A:C";
        $body = new ValueRange([
            'values' => [[$expense->getDate(), $expense->getDescription(), $expense->getAmount()]]
        ]);

        try {
            $this->sheets->spreadsheets_values->append(
                $spreadsheetId,
                $range,
                $body,
                [
                    'valueInputOption' => 'USER_ENTERED',
                    'insertDataOption' => 'INSERT_ROWS',
                ]
            );
        } catch (\Exception $e) {
            throw new \Exception("Error añadiendo gasto: " . $e->getMessage());
        }

        return true;
    }

    public function editExpense(int $year, int $month, Expense $expense): bool
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;
        $row = $expense->getRow();

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        if (!$row) {
            throw new \Exception("Row is required to edit an expense.");
        }

        $amount = $expense->getAmount();

        $writeRange = "{$sheetName}!A{$row}:C{$row}";
        $body = new ValueRange([
            'values' => [[$expense->getDate(), $expense->getDescription(), $amount]]
        ]);

        try {
            $this->sheets->spreadsheets_values->update(
                $spreadsheetId,
                $writeRange,
                $body,
                ['valueInputOption' => 'USER_ENTERED']
            );
        } catch (\Exception $e) {
            throw new \Exception("Error editando gasto: " . $e->getMessage());
        }

        return true;
    }

    public function deleteExpense(int $year, int $month, int $row): bool
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        $writeRange = "{$sheetName}!A{$row}:C{$row}";
        $body = new ValueRange([
            'values' => [['', '', '']]
        ]);

        try {
            $this->sheets->spreadsheets_values->update(
                $spreadsheetId,
                $writeRange,
                $body,
                ['valueInputOption' => 'USER_ENTERED']
            );
        } catch (\Exception $e) {
            throw new \Exception("Error eliminando gasto: " . $e->getMessage());
        }

        return true;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getAvailableYears(): array
    {
        $years = array_keys($this->config['spreadsheets']);
        sort($years);
        return $years;
    }

    private function getSpreadsheetId(int $year): string
    {
        if (!isset($this->config['spreadsheets'][$year])) {
            throw new \Exception("No hay spreadsheet configurado para el año {$year}.");
        }
        return $this->config['spreadsheets'][$year];
    }

    private function findTotalFinal(string $spreadsheetId): ?float
    {
        $sheetName = $this->config['sheet_anual'];
        $range = "{$sheetName}!{$this->config['search_range']}";

        try {
            $response = $this->sheets->spreadsheets_values->get(
                $spreadsheetId,
                $range,
                ['valueRenderOption' => 'UNFORMATTED_VALUE']
            );
        } catch (\Exception $e) {
            $this->warnings[] = "Error leyendo {$spreadsheetId}: " . $e->getMessage();
            return null;
        }

        $values = $response->getValues() ?? [];
        $searchText = $this->config['search_text'];

        foreach ($values as $row) {
            foreach ($row as $colIndex => $cell) {
                if (is_string($cell) && trim($cell) === $searchText) {
                    $nextCol = $colIndex + 1;
                    if (isset($row[$nextCol])) {
                        $raw = $row[$nextCol];
                        if (is_numeric($raw))
                            return (float) $raw;
                        return $this->parseMoneyValue($raw);
                    }
                }
            }
        }

        $this->warnings[] = "No se encontró '{$searchText}' en spreadsheet {$spreadsheetId}.";
        return null;
    }

    private function isSubtotalRow(string $cellA, string $cellB): bool
    {
        $combined = $cellA . ' ' . $cellB;
        $keywords = ['Total', 'Total/', 'Total a Pagar', 'Pensión', 'Total Final'];
        foreach ($keywords as $kw) {
            if (stripos($combined, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private function emptyMonths(): array
    {
        $results = [];
        foreach ($this->config['month_labels'] as $m => $name) {
            $results[] = ['month' => $m, 'name' => $name, 'total' => 0.0];
        }
        return $results;
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private function parseMoneyValue($value): ?float
    {
        if (!is_string($value))
            return null;

        $cleaned = preg_replace('/[€$£\s]/', '', trim($value));

        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$/', $cleaned)) {
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
            return (float) $cleaned;
        }

        if (preg_match('/^\d{1,3}(,\d{3})*(\.\d{1,2})?$/', $cleaned)) {
            $cleaned = str_replace(',', '', $cleaned);
            return (float) $cleaned;
        }

        if (preg_match('/^\d+(,\d{1,2})$/', $cleaned)) {
            $cleaned = str_replace(',', '.', $cleaned);
            return (float) $cleaned;
        }

        if (is_numeric($cleaned))
            return (float) $cleaned;

        return null;
    }
}
