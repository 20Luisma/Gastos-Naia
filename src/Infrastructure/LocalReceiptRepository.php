<?php

namespace GastosNaia\Infrastructure;

use GastosNaia\Domain\ReceiptRepositoryInterface;

class LocalReceiptRepository implements ReceiptRepositoryInterface
{
    private string $uploadsDir;
    private string $baseUrl;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->uploadsDir = __DIR__ . '/../../../uploads';
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/') . '/uploads';
    }

    private function getMonthDir(int $year, int $month): string
    {
        return $this->uploadsDir . '/' . $year . '/' . str_pad($month, 2, '0', STR_PAD_LEFT);
    }

    public function getMonthFolderId(int $year, int $month): string
    {
        // Not used for local storage, return a pseudo-id
        return $year . '_' . $month;
    }

    public function uploadReceipt(int $year, int $month, string $localFilePath, string $originalName, string $mimeType): array
    {
        $monthDir = $this->getMonthDir($year, $month);
        if (!is_dir($monthDir)) {
            mkdir($monthDir, 0755, true);
        }

        // Generate a unique filename to avoid collisions
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $uniqueName = $safeName . '_' . uniqid() . ($ext ? '.' . $ext : '');
        $destPath = $monthDir . '/' . $uniqueName;

        if (!move_uploaded_file($localFilePath, $destPath) && !copy($localFilePath, $destPath)) {
            throw new \Exception("No se pudo guardar el archivo en el servidor.");
        }

        $size = filesize($destPath);
        $sizeText = round($size / 1024, 1) . ' KB';
        if ($size > 1024 * 1024) {
            $sizeText = round($size / (1024 * 1024), 2) . ' MB';
        }

        $relativePath = $year . '/' . str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . $uniqueName;
        $url = $this->baseUrl . '/' . $relativePath;

        return [
            'id' => $relativePath,
            'filename' => $originalName,
            'path' => $relativePath,
            'url' => $url,
            'sizeBytes' => $size,
            'size_text' => $sizeText,
            'date' => date('d/m/Y H:i'),
            'type' => strtolower($ext) ?: 'unknown'
        ];
    }

    public function listReceipts(int $year, int $month): array
    {
        $monthDir = $this->getMonthDir($year, $month);
        if (!is_dir($monthDir)) {
            return [];
        }

        $files = [];
        $entries = scandir($monthDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess')
                continue;
            $filePath = $monthDir . '/' . $entry;
            if (!is_file($filePath))
                continue;

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $size = filesize($filePath);
            $sizeText = round($size / 1024, 1) . ' KB';
            if ($size > 1024 * 1024) {
                $sizeText = round($size / (1024 * 1024), 2) . ' MB';
            }

            $relativePath = $year . '/' . str_pad($month, 2, '0', STR_PAD_LEFT) . '/' . $entry;
            $url = $this->baseUrl . '/' . $relativePath;

            // Try to get original name (everything before the last _UNIQUEID)
            $displayName = preg_replace('/_[a-f0-9]{13}(\.[^.]+)?$/', '$1', $entry);

            $files[] = [
                'id' => $relativePath,
                'filename' => $displayName ?: $entry,
                'path' => $relativePath,
                'url' => $url,
                'sizeBytes' => $size,
                'size_text' => $sizeText,
                'date' => date('d/m/Y H:i', filemtime($filePath)),
                'type' => $ext ?: 'unknown'
            ];
        }
        return $files;
    }

    public function deleteReceipt(string $filePath): bool
    {
        $fullPath = $this->uploadsDir . '/' . ltrim($filePath, '/');
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }
}
