<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ExpenseRepositoryInterface;
use GastosNaia\Infrastructure\FirebaseWriteRepository;

class SetPensionUseCase
{
    private ExpenseRepositoryInterface $repository;
    private FirebaseWriteRepository $firebaseSync;

    public function __construct(ExpenseRepositoryInterface $repository, FirebaseWriteRepository $firebaseSync)
    {
        $this->repository = $repository;
        $this->firebaseSync = $firebaseSync;
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
            return true;
        }

        return false;
    }
}
