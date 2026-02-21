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

    private function getOrCreateFolder(string $parentFolderId, string $folderName): string
    {
        $query = sprintf(
            "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            str_replace("'", "\\'", $folderName),
            $parentFolderId
        );

        $results = $this->drive->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name)',
        ]);

        if (count($results->getFiles()) > 0) {
            return $results->getFiles()[0]->getId();
        }

        $fileMetadata = new DriveFile([
            'name' => $folderName,
            'parents' => [$parentFolderId],
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);

        $folder = $this->drive->files->create($fileMetadata, ['fields' => 'id']);
        return $folder->getId();
    }

    public function getMonthFolderId(int $year, int $month): string
    {
        if (!isset($this->config['drive_folders'][$year])) {
            throw new \Exception("No hay una carpeta de Drive configurada en config.php para el año $year.");
        }

        $yearFolderId = $this->config['drive_folders'][$year];

        $monthName = $this->config['month_labels'][$month] ?? 'Unknown';
        $monthFolderName = sprintf("%d) Recibos %s %d", $month, $monthName, $year);
        $monthFolderId = $this->getOrCreateFolder($yearFolderId, $monthFolderName);

        return $monthFolderId;
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
            'fields' => 'id, name, webViewLink, size, createdTime'
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
                'fields' => 'files(id, name, webViewLink, size, createdTime, fileExtension, mimeType)'
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
