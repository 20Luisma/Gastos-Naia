<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;

class EditExpenseUseCase
{
    private ExpenseRepositoryInterface $expenseRepository;

    public function __construct(ExpenseRepositoryInterface $expenseRepository)
    {
        $this->expenseRepository = $expenseRepository;
    }

    public function execute(int $year, int $month, int $row, string $date, string $description, float $amount): bool
    {
        $expense = new Expense($date, $description, $amount, $row);
        return $this->expenseRepository->editExpense($year, $month, $expense);
    }
}
