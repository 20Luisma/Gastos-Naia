<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ReceiptRepositoryInterface;

class DeleteReceiptUseCase
{
    private ReceiptRepositoryInterface $receiptRepository;
    private \GastosNaia\Infrastructure\FirebaseBackupService $backupService;

    public function __construct(ReceiptRepositoryInterface $receiptRepository, \GastosNaia\Infrastructure\FirebaseBackupService $backupService)
    {
        $this->receiptRepository = $receiptRepository;
        $this->backupService = $backupService;
    }

    public function execute(string $filePath): bool
    {
        $success = $this->receiptRepository->deleteReceipt($filePath);
        if ($success) {
            $this->backupService->backupFileAction('DELETE_FILE', $filePath);
        }
        return $success;
    }
}
