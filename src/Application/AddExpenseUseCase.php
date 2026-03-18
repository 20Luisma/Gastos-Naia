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
    private ?\GastosNaia\Infrastructure\TelegramNotificationService $telegram = null;

    public function __construct(
        ExpenseRepositoryInterface $expenseRepository,
        \GastosNaia\Infrastructure\FirebaseBackupService $backupService,
        ?\GastosNaia\Infrastructure\TelegramNotificationService $telegram = null
    ) {
        $this->expenseRepository = $expenseRepository;
        $this->backupService = $backupService;
        $this->firebaseWrite = new \GastosNaia\Infrastructure\FirebaseWriteRepository();
        $this->fcm = new \GastosNaia\Infrastructure\FcmNotificationService();
        $this->telegram = $telegram;
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

            // Enviar a Telegram
            if ($this->telegram) {
                // Asegurar que la fecha se procesa bien para el mensaje
                $displayDate = date('d/m/Y', strtotime($date));
                if ($displayDate === '01/01/1970' || !$displayDate) {
                    $displayDate = $date; // Fallback al string original si falla
                }

                $msg = "<b>💳 Nuevo Gasto Universo Naia</b>\n\n";
                $msg .= "<b>Concepto:</b> {$description}\n";
                $msg .= "<b>Importe:</b> " . number_format($amount, 2, ',', '.') . " €\n";
                $msg .= "<b>Fecha:</b> " . $displayDate;
                $this->telegram->sendMessage($msg);
            }
        }

        return $success;
    }
}
