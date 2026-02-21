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
        $sheetName = $this->config['sheet_anual'];
        $range = "{$sheetName}!A1:B13";

        try {
            $response = $this->sheets->spreadsheets_values->get(
                $spreadsheetId,
                $range,
                ['valueRenderOption' => 'UNFORMATTED_VALUE']
            );
            $values = $response->getValues() ?? [];
        } catch (\Exception $e) {
            $this->warnings[] = "Error leyendo totales mensuales de {$year}: " . $e->getMessage();
            return $this->emptyMonths();
        }

        $results = [];
        $monthLabels = $this->config['month_labels'];

        for ($m = 1; $m <= 12; $m++) {
            $rowIndex = $m;
            $total = 0.0;

            if (isset($values[$rowIndex]) && isset($values[$rowIndex][1])) {
                $raw = $values[$rowIndex][1];
                $total = is_numeric($raw) ? (float) $raw : $this->parseMoneyValue($raw) ?? 0.0;
            }

            $results[] = [
                'month' => $m,
                'name' => $monthLabels[$m],
                'total' => $total,
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

    public function addExpense(int $year, int $month, Expense $expense): bool
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
            throw new \Exception("Error leyendo hoja {$sheetName}: " . $e->getMessage());
        }

        $insertRow = 2;
        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];
            $cellA = trim($row[0] ?? '');
            $cellB = trim($row[1] ?? '');
            $cellC = trim($row[2] ?? '');

            if ((empty($cellA) && empty($cellB) && empty($cellC)) || $this->isSubtotalRow($cellA, $cellB)) {
                $insertRow = $i + 1;
                break;
            }
            $insertRow = $i + 2;
        }

        $amount = $expense->getAmount();

        $writeRange = "{$sheetName}!A{$insertRow}:C{$insertRow}";
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
            throw new \Exception("Error escribiendo gasto: " . $e->getMessage());
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
