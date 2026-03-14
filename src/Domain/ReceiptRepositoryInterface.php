<?php

namespace GastosNaia\Domain;

interface ReceiptRepositoryInterface
{
    /**
     * @param int $year
     * @param int $month
     * @return array Array of associative arrays with keys ['path', 'url', 'filename', 'mimeType', 'sizeBytes', 'size_text', 'date', 'type']
     */
    public function listReceipts(int $year, int $month): array;

    /**
     * @param int $year
     * @param int $month
     * @param string $localFilePath
     * @param string $originalName
     * @param string $mimeType
     * @return array Array with keys ['path', 'url', 'filename', 'mimeType', 'sizeBytes', 'size_text', 'date', 'type']
     */
    public function uploadReceipt(int $year, int $month, string $localFilePath, string $originalName, string $mimeType): array;

    /**
     * @param string $filePath
     * @return bool
     */
    public function deleteReceipt(string $filePath): bool;
}
