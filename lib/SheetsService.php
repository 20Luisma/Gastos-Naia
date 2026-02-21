<?php
/**
 * SheetsService — Lectura y escritura en Google Sheets API v4.
 * 
 * Lee gastos mensuales, totales anuales, y añade nuevos gastos
 * directamente en las hojas de Google Sheets.
 */

namespace GastosNaia;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

class SheetsService
{
    private Sheets $sheets;
    private array $config;
    private array $warnings = [];

    /**
     * @param array $config Configuración completa desde config.php
     * @throws \Exception Si el archivo de credenciales no existe
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        $credPath = $config['credentials_path'];
        if (!file_exists($credPath)) {
            throw new \Exception(
                "No se encontró el archivo de credenciales: {$credPath}. " .
                "Consulta INSTALL.md para configurar el Service Account."
            );
        }

        $client = new Client();
        $client->setAuthConfig($credPath);
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setApplicationName('Gastos Naia Dashboard');

        $this->sheets = new Sheets($client);
    }

    // ─────────────────────────────────────────────
    //  Totales anuales
    // ─────────────────────────────────────────────

    /**
     * Lee el "Total Final:" de cada spreadsheet → [{year, total}]
     */
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

    // ─────────────────────────────────────────────
    //  Totales mensuales de un año
    // ─────────────────────────────────────────────

    /**
     * Lee la hoja "Gastos Anual" → [{month, name, total}] para los 12 meses
     */
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

        // Fila 1 es cabecera, filas 2-13 son los meses
        for ($m = 1; $m <= 12; $m++) {
            $rowIndex = $m; // fila 2 = índice 1, etc.
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

    // ─────────────────────────────────────────────
    //  Gastos individuales de un mes
    // ─────────────────────────────────────────────

    /**
     * Lee los gastos de una hoja mensual → [{row, date, description, amount}]
     */
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
        // Empezar desde fila 2 (índice 1), fila 1 es cabecera
        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];

            // Solo procesar filas que tengan datos de gastos (3 columnas con contenido)
            $date = trim($row[0] ?? '');
            $desc = trim($row[1] ?? '');
            $amount = $row[2] ?? '';

            // Saltar filas vacías o filas de totales (como "Total/2:", "Total a Pagar:", etc.)
            if (empty($date) && empty($desc) && empty($amount)) {
                continue;
            }
            // Detectar filas de resumen/total y parar
            if ($this->isSubtotalRow($date, $desc)) {
                break;
            }
            // Solo incluir filas con fecha real
            if (empty($date)) {
                continue;
            }

            $expenses[] = [
                'row' => $i + 1,  // número de fila en Google Sheets (1-indexed)
                'date' => $date,
                'description' => $desc,
                'amount' => $amount,
            ];
        }

        return $expenses;
    }

    // ─────────────────────────────────────────────
    //  Añadir gasto
    // ─────────────────────────────────────────────

    /**
     * Añade un gasto al final de la lista en la hoja del mes correspondiente.
     * Inserta ANTES de las filas de totales.
     */
    public function addExpense(int $year, int $month, string $date, string $description, float $amount): array
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        // Leer datos actuales para encontrar dónde insertar
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

        // Encontrar la primera fila vacía después de los datos (antes de subtotales)
        // O un "hueco" de una fila que haya sido eliminada previamente (vacía)
        $insertRow = 2; // mínimo fila 2 (después de cabecera)
        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];
            $cellA = trim($row[0] ?? '');
            $cellB = trim($row[1] ?? '');
            $cellC = trim($row[2] ?? '');

            // Si encontramos una fila vacía DENTRO o DESPUÉS de los datos, o una fila de subtotal, insertamos aquí
            if ((empty($cellA) && empty($cellB) && empty($cellC)) || $this->isSubtotalRow($cellA, $cellB)) {
                $insertRow = $i + 1; // 1-indexed
                break;
            }
            $insertRow = $i + 2; // después de la última fila con datos iterada
        }

        // Formatear el monto con 2 decimales
        $formattedAmount = number_format($amount, 2, ',', '.');

        // Escribir en la fila encontrada
        $writeRange = "{$sheetName}!A{$insertRow}:C{$insertRow}";
        $body = new ValueRange([
            'values' => [[$date, $description, $amount]]
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

        return [
            'success' => true,
            'row' => $insertRow,
            'date' => $date,
            'description' => $description,
            'amount' => $formattedAmount,
        ];
    }

    // ─────────────────────────────────────────────
    //  Editar gasto
    // ─────────────────────────────────────────────

    /**
     * Edita un gasto existente en una fila específica.
     */
    public function editExpense(int $year, int $month, int $row, string $date, string $description, float $amount): array
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        // Formatear el monto con 2 decimales
        $formattedAmount = number_format($amount, 2, ',', '.');

        // Sobrescribir la fila dada
        $writeRange = "{$sheetName}!A{$row}:C{$row}";
        $body = new ValueRange([
            'values' => [[$date, $description, $amount]]
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

        return [
            'success' => true,
            'row' => $row,
            'date' => $date,
            'description' => $description,
            'amount' => $formattedAmount,
        ];
    }

    // ─────────────────────────────────────────────
    //  Eliminar gasto
    // ─────────────────────────────────────────────

    /**
     * "Elimina" un gasto limpiando (vaciando) las celdas de su fila.
     * Es más seguro que borrar la fila entera para no romper fórmulas debajo.
     * La fila vacía resultante será reaprovechada por addExpense.
     */
    public function deleteExpense(int $year, int $month, int $row): array
    {
        $spreadsheetId = $this->getSpreadsheetId($year);
        $sheetName = $this->config['months'][$month] ?? null;

        if (!$sheetName) {
            throw new \Exception("Mes inválido: {$month}");
        }

        // Se limpian las 3 columnas
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

        return [
            'success' => true,
            'row' => $row,
        ];
    }

    // ─────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────

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

    /**
     * Busca "Total Final:" en la hoja "Gastos Anual" y devuelve el valor adyacente.
     */
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

    /**
     * Detecta filas de subtotal/resumen para no incluirlas como gastos.
     */
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

    /**
     * Genera array vacío de 12 meses.
     */
    private function emptyMonths(): array
    {
        $results = [];
        foreach ($this->config['month_labels'] as $m => $name) {
            $results[] = ['month' => $m, 'name' => $name, 'total' => 0.0];
        }
        return $results;
    }

    /**
     * Parsea valores monetarios como "1.234,56 €" o "1,234.56".
     */
    private function parseMoneyValue(mixed $value): ?float
    {
        if (!is_string($value))
            return null;

        $cleaned = preg_replace('/[€$£\s]/', '', trim($value));

        // Formato europeo: 1.234,56
        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$/', $cleaned)) {
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
            return (float) $cleaned;
        }

        // Formato anglosajón: 1,234.56
        if (preg_match('/^\d{1,3}(,\d{3})*(\.\d{1,2})?$/', $cleaned)) {
            $cleaned = str_replace(',', '', $cleaned);
            return (float) $cleaned;
        }

        // Simple con coma: 1234,56
        if (preg_match('/^\d+(,\d{1,2})$/', $cleaned)) {
            $cleaned = str_replace(',', '.', $cleaned);
            return (float) $cleaned;
        }

        if (is_numeric($cleaned))
            return (float) $cleaned;

        return null;
    }
}
