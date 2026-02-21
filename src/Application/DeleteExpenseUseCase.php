<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ExpenseRepositoryInterface;

class DeleteExpenseUseCase
{
    private ExpenseRepositoryInterface $expenseRepository;

    public function __construct(ExpenseRepositoryInterface $expenseRepository)
    {
        $this->expenseRepository = $expenseRepository;
    }

    public function execute(int $year, int $month, int $row): bool
    {
        return $this->expenseRepository->deleteExpense($year, $month, $row);
    }
}
