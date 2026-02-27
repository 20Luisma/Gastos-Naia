<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ExpenseRepositoryInterface;

class DeleteExpenseUseCase
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

    public function execute(int $year, int $month, int $row): bool
    {
        // 1. Obtener la información del gasto ANTES de borrarlo para asegurar el backup íntegro
        $expenses = $this->expenseRepository->getExpenses($year, $month);
        $expenseToBackup = null;
        foreach ($expenses as $exp) {
            if ($exp->getRow() === $row) {
                $expenseToBackup = $exp;
                break;
            }
        }

        // 2. Realizar el borrado en el repositorio
        $success = $this->expenseRepository->deleteExpense($year, $month, $row);

        // 3. Enviar el backup a Firebase (auditoría del borrado)
        if ($success && $expenseToBackup) {
            $this->backupService->backupExpenseAction('DELETE', [
                'year' => $year,
                'month' => $month,
                'row' => $row,
                'date' => $expenseToBackup->getDate(),
                'description' => $expenseToBackup->getDescription(),
                'amount' => $expenseToBackup->getAmount()
            ]);

            // Sync specifically this year's index to Firebase Read Replica for AI
            $this->firebaseWrite->syncYearFast($year, $this->expenseRepository);

            // Enviar notificación push FCM al móvil
            $body = "{$expenseToBackup->getDescription()} — " . number_format($expenseToBackup->getAmount(), 2, ',', '.') . ' €';
            $this->fcm->notify('delete', $body, $year, $month);

        } elseif ($success) {
            // Backup fallback if exactly row wasn't matched but deleted
            $this->backupService->backupExpenseAction('DELETE', [
                'year' => $year,
                'month' => $month,
                'row' => $row,
                'status' => 'Data deleted but details not found before deletion'
            ]);

            // Notificación genérica
            $this->fcm->notify('delete', 'Gasto eliminado', $year, $month);
        }

        return $success;
    }
}
