<?php

namespace GastosNaia\Infrastructure;

use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;

/**
 * Decorador de caché para ExpenseRepositoryInterface.
 *
 * Cachea las operaciones de LECTURA en disco (FileCache).
 * Invalida el caché automáticamente en operaciones de ESCRITURA.
 *
 * TTL por defecto: 5 minutos.
 */
class CachedExpenseRepository implements ExpenseRepositoryInterface
{
    private ExpenseRepositoryInterface $inner;
    private FileCache $cache;

    public function __construct(ExpenseRepositoryInterface $inner, FileCache $cache)
    {
        $this->inner = $inner;
        $this->cache = $cache;
    }

    public function getAnnualTotals(): array
    {
        $key = 'annual_totals';
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $data = $this->inner->getAnnualTotals();
        $this->cache->set($key, $data);
        return $data;
    }

    public function getMonthlyTotals(int $year): array
    {
        $key = "monthly_totals_{$year}";
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $data = $this->inner->getMonthlyTotals($year);
        $this->cache->set($key, $data);
        return $data;
    }

    public function getExpenses(int $year, int $month): array
    {
        $key = "expenses_{$year}_{$month}";
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            // El caché devuelve arrays planos — reconstruimos objetos Expense
            return array_map(
                fn(array $e) => new Expense($e['date'], $e['description'], $e['amount'], $e['row']),
                $cached
            );
        }

        $expenses = $this->inner->getExpenses($year, $month);

        // Serializamos como arrays para que json_encode funcione en FileCache
        $serialized = array_map(fn(Expense $e) => $e->jsonSerialize(), $expenses);
        $this->cache->set($key, $serialized);

        return $expenses;
    }

    public function getMonthlyFinancialSummary(int $year, int $month): array
    {
        $key = "monthly_financial_{$year}_{$month}";
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $data = $this->inner->getMonthlyFinancialSummary($year, $month);
        $this->cache->set($key, $data);
        return $data;
    }

    public function addExpense(int $year, int $month, Expense $expense): bool
    {
        $result = $this->inner->addExpense($year, $month, $expense);

        if ($result) {
            $this->invalidateForYear($year, $month);
        }

        return $result;
    }

    public function editExpense(int $year, int $month, Expense $expense): bool
    {
        $result = $this->inner->editExpense($year, $month, $expense);

        if ($result) {
            $this->invalidateForYear($year, $month);
        }

        return $result;
    }

    public function deleteExpense(int $year, int $month, int $row): bool
    {
        $result = $this->inner->deleteExpense($year, $month, $row);

        if ($result) {
            $this->invalidateForYear($year, $month);
        }

        return $result;
    }

    public function setPension(int $year, int $month, float $amount): bool
    {
        $result = $this->inner->setPension($year, $month, $amount);

        if ($result) {
            $this->invalidateForYear($year, $month);
            $this->cache->invalidate("monthly_financial_{$year}_{$month}");
        }

        return $result;
    }

    public function getAvailableYears(): array
    {
        $key = 'available_years';
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $years = $this->inner->getAvailableYears();
        $this->cache->set($key, $years);
        return $years;
    }

    public function getWarnings(): array
    {
        return $this->inner->getWarnings();
    }

    /**
     * Invalida todos los datos relacionados con un año/mes tras una escritura.
     */
    private function invalidateForYear(int $year, int $month): void
    {
        $this->cache->invalidate("expenses_{$year}_{$month}");
        $this->cache->invalidate("monthly_totals_{$year}");
        $this->cache->invalidate("monthly_financial_{$year}_{$month}");
        $this->cache->invalidate('annual_totals');
    }
}
