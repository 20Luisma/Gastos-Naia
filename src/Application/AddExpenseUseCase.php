<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;

class AddExpenseUseCase
{
    private ExpenseRepositoryInterface $expenseRepository;

    public function __construct(ExpenseRepositoryInterface $expenseRepository)
    {
        $this->expenseRepository = $expenseRepository;
    }

    public function execute(int $year, int $month, string $date, string $description, float $amount): bool
    {
        $expense = new Expense($date, $description, $amount);
        return $this->expenseRepository->addExpense($year, $month, $expense);
    }
}
