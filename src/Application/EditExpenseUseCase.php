<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;

class EditExpenseUseCase
{
    private ExpenseRepositoryInterface $expenseRepository;
    private \GastosNaia\Infrastructure\FirebaseBackupService $backupService;

    public function __construct(ExpenseRepositoryInterface $expenseRepository, \GastosNaia\Infrastructure\FirebaseBackupService $backupService)
    {
        $this->expenseRepository = $expenseRepository;
        $this->backupService = $backupService;
    }

    public function execute(int $year, int $month, int $row, string $date, string $description, float $amount): bool
    {
        $expense = new Expense($date, $description, $amount, $row);
        $success = $this->expenseRepository->editExpense($year, $month, $expense);

        if ($success) {
            $this->backupService->backupExpenseAction('EDIT', [
                'year' => $year,
                'month' => $month,
                'row' => $row,
                'date' => $expense->getDate(),
                'description' => $expense->getDescription(),
                'amount' => $expense->getAmount()
            ]);

            // Invalidar caché de IA
            $cacheFile = __DIR__ . '/../../backups/ai_cache.json';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
        }

        return $success;
    }
}
