<?php

namespace GastosNaia\Infrastructure;

use GastosNaia\Domain\ReceiptRepositoryInterface;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveReceiptRepository implements ReceiptRepositoryInterface
{
    private Drive $drive;
    private array $config;

    public function __construct(Client $client, array $config)
    {
        $this->config = $config;
        $this->drive = new Drive($client);
    }

    private function getOrCreateFolder(string $parentFolderId, int $month, string $monthName, int $year): string
    {
        // 1. Obtener todas las subcarpetas del año
        $query = sprintf(
            "'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            $parentFolderId
        );

        $results = $this->drive->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        // 2. Buscar coincidencias flexibles usando regex Local
        // Acepta: "1) Recibos Enero 2024", "1)Recibos Enero 2024", " 1) recibos enero 2024" etc.
        $regex = "/^\s*{$month}\)\s*Recibos\s+{$monthName}\s+{$year}\s*$/ui";

        foreach ($results->getFiles() as $folder) {
            if (preg_match($regex, $folder->getName())) {
                return $folder->getId(); // Encontrada!
            }
        }

        // 3. Si no existe, crear con nombre canónico
        $canonicalName = sprintf("%d) Recibos %s %d", $month, $monthName, $year);
        $fileMetadata = new DriveFile([
            'name' => $canonicalName,
            'parents' => [$parentFolderId],
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);

        $folder = $this->drive->files->create($fileMetadata, [
            'fields' => 'id',
            'supportsAllDrives' => true,
        ]);
        return $folder->getId();
    }

    public function getMonthFolderId(int $year, int $month): string
    {
        if (!isset($this->config['drive_folders'][$year])) {
            throw new \Exception("No hay una carpeta de Drive configurada en config.php para el año $year.");
        }

        $yearFolderId = $this->config['drive_folders'][$year];
        $monthName = $this->config['month_labels'][$month] ?? 'Unknown';

        return $this->getOrCreateFolder($yearFolderId, $month, $monthName, $year);
    }

    public function uploadReceipt(int $year, int $month, string $localFilePath, string $originalName, string $mimeType): array
    {
        $folderId = $this->getMonthFolderId($year, $month);

        $fileMetadata = new DriveFile([
            'name' => $originalName,
            'parents' => [$folderId]
        ]);

        $content = file_get_contents($localFilePath);

        $file = $this->drive->files->create($fileMetadata, [
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id, name, webViewLink, size, createdTime',
            'supportsAllDrives' => true,
        ]);

        $size = (int) $file->getSize();
        $sizeText = round($size / 1024, 1) . ' KB';
        if ($size > 1024 * 1024) {
            $sizeText = round($size / (1024 * 1024), 2) . ' MB';
        }
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);

        return [
            'id' => $file->getId(),
            'filename' => $file->getName(),
            'path' => $file->getId(),
            'url' => $file->getWebViewLink(),
            'sizeBytes' => $size,
            'size_text' => $sizeText,
            'date' => date('d/m/Y H:i', strtotime($file->getCreatedTime())),
            'type' => strtolower($ext) ?: 'unknown'
        ];
    }

    public function listReceipts(int $year, int $month): array
    {
        try {
            if (!isset($this->config['drive_folders'][$year]))
                return [];

            $folderId = $this->getMonthFolderId($year, $month);

            $query = sprintf("'%s' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'", $folderId);
            $results = $this->drive->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name, webViewLink, size, createdTime, fileExtension, mimeType)',
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ]);

            $files = [];
            foreach ($results->getFiles() as $file) {
                $ext = $file->getFileExtension() ?: 'unknown';
                $size = (int) $file->getSize();
                $sizeText = round($size / 1024, 1) . ' KB';
                if ($size > 1024 * 1024) {
                    $sizeText = round($size / (1024 * 1024), 2) . ' MB';
                }

                $files[] = [
                    'id' => $file->getId(),
                    'filename' => $file->getName(),
                    'path' => $file->getId(),
                    'url' => $file->getWebViewLink(),
                    'sizeBytes' => $size,
                    'size_text' => $sizeText,
                    'date' => date('d/m/Y H:i', strtotime($file->getCreatedTime())),
                    'type' => strtolower($ext)
                ];
            }
            return $files;
        } catch (\Exception $e) {
            file_put_contents(__DIR__ . '/drive_error.log', $e->getMessage() . "\n", FILE_APPEND);
            return [];
        }
    }

    public function deleteReceipt(string $filePath): bool
    {
        try {
            $this->drive->files->delete($filePath);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
