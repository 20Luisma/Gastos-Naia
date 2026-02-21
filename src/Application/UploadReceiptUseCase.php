<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ReceiptRepositoryInterface;

class UploadReceiptUseCase
{
    private ReceiptRepositoryInterface $receiptRepository;

    public function __construct(ReceiptRepositoryInterface $receiptRepository)
    {
        $this->receiptRepository = $receiptRepository;
    }

    public function execute(int $year, int $month, string $localFilePath, string $originalName, string $mimeType): array
    {
        return $this->receiptRepository->uploadReceipt($year, $month, $localFilePath, $originalName, $mimeType);
    }
}
