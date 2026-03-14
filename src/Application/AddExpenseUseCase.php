<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;

class AddExpenseUseCase
{
    private ExpenseRepositoryInterface $expenseRepository;
    private \GastosNaia\Infrastructure\FirebaseBackupService $backupService;
    private \GastosNaia\Infrastructure\FirebaseWriteRepository $firebaseWrite;
    private \GastosNaia\Infrastructure\FcmNotificationService $fcm;

    public function __construct(ExpenseRepositoryInterface $expenseRepository, \GastosNaia\Infrastructure\FirebaseBackupService $backupService)
    {
        $this->expenseRepository = $expenseRepository;
        $this->backupService = $backupService;
        $this->firebaseWrite = new \GastosNaia\Infrastructure\FirebaseWriteRepository();
        $this->fcm = new \GastosNaia\Infrastructure\FcmNotificationService();
    }

    public function execute(int $year, int $month, string $date, string $description, float $amount): bool
    {
        $expense = new Expense($date, $description, $amount);
        $success = $this->expenseRepository->addExpense($year, $month, $expense);

        if ($success) {
            $this->backupService->backupExpenseAction('ADD', [
                'year' => $year,
                'month' => $month,
                'date' => $expense->getDate(),
                'description' => $expense->getDescription(),
                'amount' => $expense->getAmount()
            ]);

            // Sync specifically this year's index to Firebase Read Replica for AI
            $this->firebaseWrite->syncYearFast($year, $this->expenseRepository);

            // Enviar notificación push FCM al móvil
            $body = "{$expense->getDescription()} — " . number_format($expense->getAmount(), 2, ',', '.') . ' €';
            $this->fcm->notify('add', $body, $year, $month);
        }

        return $success;
    }
}
