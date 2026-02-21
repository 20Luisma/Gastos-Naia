<?php

namespace GastosNaia\Domain;

interface ExpenseRepositoryInterface
{
    /**
     * @return array Array of associative arrays with keys ['year' => int, 'total' => float]
     */
    public function getAnnualTotals(): array;

    /**
     * @param int $year
     * @return array Array of associative arrays with keys ['month' => int, 'name' => string, 'total' => float]
     */
    public function getMonthlyTotals(int $year): array;

    /**
     * @param int $year
     * @param int $month
     * @return Expense[]
     */
    public function getExpenses(int $year, int $month): array;

    public function addExpense(int $year, int $month, Expense $expense): bool;

    public function editExpense(int $year, int $month, Expense $expense): bool;

    public function deleteExpense(int $year, int $month, int $row): bool;

    /**
     * @return int[]
     */
    public function getAvailableYears(): array;

    /**
     * @return string[]
     */
    public function getWarnings(): array;
}
