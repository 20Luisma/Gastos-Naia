<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ReceiptRepositoryInterface;

class DeleteReceiptUseCase
{
    private ReceiptRepositoryInterface $receiptRepository;

    public function __construct(ReceiptRepositoryInterface $receiptRepository)
    {
        $this->receiptRepository = $receiptRepository;
    }

    public function execute(string $filePath): bool
    {
        return $this->receiptRepository->deleteReceipt($filePath);
    }
}
