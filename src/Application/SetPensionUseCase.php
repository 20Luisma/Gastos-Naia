<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ExpenseRepositoryInterface;
use GastosNaia\Infrastructure\FirebaseWriteRepository;
use GastosNaia\Infrastructure\FcmNotificationService;

class SetPensionUseCase
{
    private ExpenseRepositoryInterface $repository;
    private FirebaseWriteRepository $firebaseSync;
    private FcmNotificationService $fcm;

    public function __construct(ExpenseRepositoryInterface $repository, FirebaseWriteRepository $firebaseSync)
    {
        $this->repository = $repository;
        $this->firebaseSync = $firebaseSync;
        $this->fcm = new FcmNotificationService();
    }

    public function execute(int $year, int $month, float $amount): bool
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException("La pensión no puede ser negativa.");
        }

        // 1. Escribir la pensión nativamente en Google Sheets
        $success = $this->repository->setPension($year, $month, $amount);

        if ($success) {
            // 2. Disparar sincronización asíncrona hacia Firebase
            $this->firebaseSync->syncYearFast($year, $this->repository);

            // 3. Notificar al móvil vía FCM
            $body = number_format($amount, 2, ',', '.') . ' €';
            $this->fcm->notify('pension', "Pensión actualizada: {$body}", $year, $month);

            return true;
        }

        return false;
    }
}
