<?php

class DriveService
{
    private \Google\Service\Drive $drive;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
        $refreshToken = $_ENV['GOOGLE_REFRESH_TOKEN'] ?? '';

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            throw new \Exception("Faltan credenciales de OAuth en el archivo .env.");
        }

        $client = new \Google\Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setAccessType('offline');
        $client->setScopes([\Google\Service\Drive::DRIVE]);
        $client->setApplicationName('Gastos Naia Recibos');

        // Configurar directamente el array que espera Google API Client
        $client->setAccessToken([
            'refresh_token' => $refreshToken,
            'access_token' => '',
            'expires_in' => 0,
            'created' => 0
        ]);

        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
        }

        $this->drive = new \Google\Service\Drive($client);
    }

    /**
     * Busca una carpeta por nombre dentro de un padre. Si no existe, la crea.
     */
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

        // Crear carpeta si no existe
        $fileMetadata = new \Google\Service\Drive\DriveFile([
            'name' => $folderName,
            'parents' => [$parentFolderId],
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);

        $folder = $this->drive->files->create($fileMetadata, ['fields' => 'id']);
        return $folder->getId();
    }

    /**
     * Obtiene (o crea) el arbol de directorios:
     * Renta YYYY -> M) Recibos Mes YYYY
     * Devuelve el ID de la subcarpeta del mes.
     */
    public function getMonthFolderId(int $year, int $month): string
    {
        if (!isset($this->config['drive_folders'][$year])) {
            throw new \Exception("No hay una carpeta de Drive configurada en config.php para el año $year.");
        }

        $yearFolderId = $this->config['drive_folders'][$year];

        // M) Recibos Enero 2026
        $monthName = $this->config['month_labels'][$month];
        $monthFolderName = sprintf("%d) Recibos %s %d", $month, $monthName, $year);
        $monthFolderId = $this->getOrCreateFolder($yearFolderId, $monthFolderName);

        return $monthFolderId;
    }

    /**
     * Sube un recibo a Drive.
     */
    public function uploadReceipt(int $year, int $month, string $tmpFilePath, string $originalFilename, string $mimeType): array
    {
        $folderId = $this->getMonthFolderId($year, $month);

        $fileMetadata = new \Google\Service\Drive\DriveFile([
            'name' => $originalFilename,
            'parents' => [$folderId]
        ]);

        $content = file_get_contents($tmpFilePath);

        $file = $this->drive->files->create($fileMetadata, [
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id, name, webViewLink, size, createdTime'
        ]);

        return [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'url' => $file->getWebViewLink(),
            'size' => $file->getSize(),
            'created' => $file->getCreatedTime()
        ];
    }

    /**
     * Lista los recibos para un mes concreto.
     */
    public function listReceipts(int $year, int $month): array
    {
        try {
            // Intentar obtener la carpeta. Si falla o no tiene archivos, silenciosamente capturar.
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
                    'path' => $file->getId(), // Hack frontend: pasamos el ID envez del path temporal para poder borrar
                    'url' => $file->getWebViewLink(),
                    'size' => $size,
                    'size_text' => $sizeText,
                    'date' => date('d/m/Y H:i', strtotime($file->getCreatedTime())),
                    'type' => strtolower($ext)
                ];
            }
            return $files;
        } catch (\Exception $e) {
            // Si hay error listando (por ejemplo, carpeta no accesible temporalmente), devolvemos vacio.
            return [];
        }
    }

    /**
     * Elimina un recibo de Google Drive por su ID de archivo
     */
    public function deleteReceipt(string $fileId): bool
    {
        try {
            $this->drive->files->delete($fileId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
