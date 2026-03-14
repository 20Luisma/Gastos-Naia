<?php

namespace GastosNaia\Application;

use GastosNaia\Infrastructure\GoogleDriveReceiptRepository;

class UploadComunicadoFileUseCase
{
    private GoogleDriveReceiptRepository $driveRepository;

    public function __construct(GoogleDriveReceiptRepository $driveRepository)
    {
        $this->driveRepository = $driveRepository;
    }

    public function execute(array $file, string $folderId): string
    {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            throw new \Exception("Archivo inválido o vacío.");
        }

        // Limit security checks to basic aspects (in real app, use finfo to verify mimetype)
        $maxSize = 15 * 1024 * 1024; // 15MB
        if ($file['size'] > $maxSize) {
            throw new \Exception("El archivo es demasiado grande (máx 15MB).");
        }

        $filename = basename($file['name']);

        // El driveReposistory que ya tenemos tiene un método público uploadFileToFolder
        // Asume que este repositorio existe y tiene ese método.
        return $this->driveRepository->uploadFileToFolder($file['tmp_name'], $filename, $folderId);
    }
}
