<?php
/**
 * FileUploader — Gestión de subida y descarga de recibos.
 * 
 * Almacena archivos organizados por año/mes en el servidor.
 */

namespace GastosNaia;

class FileUploader
{
    private string $basePath;
    private int $maxSize;
    private array $allowedExtensions;

    public function __construct(array $config)
    {
        $this->basePath = $config['uploads_path'];
        $this->maxSize = $config['max_file_size'];
        $this->allowedExtensions = $config['allowed_extensions'];

        // Crear directorio base si no existe
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Sube un archivo recibido vía $_FILES.
     *
     * @param array  $file   Entrada de $_FILES (name, tmp_name, size, error, type)
     * @param int    $year   Año del gasto
     * @param int    $month  Mes del gasto
     * @return array Resultado con nombre del archivo guardado
     * @throws \Exception Si hay error de validación
     */
    public function upload(array $file, int $year, int $month): array
    {
        // Validar error de subida
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception($this->uploadErrorMessage($file['error']));
        }

        // Validar tamaño
        if ($file['size'] > $this->maxSize) {
            $maxMB = $this->maxSize / (1024 * 1024);
            throw new \Exception("El archivo excede el tamaño máximo de {$maxMB} MB.");
        }

        // Validar extensión
        $originalName = basename($file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $this->allowedExtensions)) {
            $allowed = implode(', ', $this->allowedExtensions);
            throw new \Exception("Tipo de archivo no permitido (.{$extension}). Permitidos: {$allowed}");
        }

        // Crear directorio del mes
        $dir = $this->getDir($year, $month);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Generar nombre único
        $timestamp = date('Ymd_His');
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeName = substr($safeName, 0, 50); // limitar longitud
        $storedName = "{$timestamp}_{$safeName}.{$extension}";
        $destPath = $dir . '/' . $storedName;

        // Mover archivo
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \Exception("Error al guardar el archivo en el servidor.");
        }

        return [
            'success' => true,
            'filename' => $storedName,
            'original' => $originalName,
            'size' => $file['size'],
            'path' => "{$year}/{$month}/{$storedName}",
        ];
    }

    /**
     * Lista los recibos de un mes.
     */
    public function listFiles(int $year, int $month): array
    {
        $dir = $this->getDir($year, $month);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess')
                continue;

            $fullPath = $dir . '/' . $entry;
            if (!is_file($fullPath))
                continue;

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $files[] = [
                'filename' => $entry,
                'size' => filesize($fullPath),
                'size_text' => $this->formatSize(filesize($fullPath)),
                'type' => $ext,
                'path' => "{$year}/{$month}/{$entry}",
                'date' => date('Y-m-d H:i', filemtime($fullPath)),
            ];
        }

        // Ordenar por fecha descendente
        usort($files, fn($a, $b) => strcmp($b['date'], $a['date']));

        return $files;
    }

    /**
     * Devuelve la ruta completa de un archivo para descarga.
     */
    public function getFilePath(string $relativePath): ?string
    {
        // Sanitizar para evitar directory traversal
        $relativePath = str_replace('..', '', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        $fullPath = $this->basePath . '/' . $relativePath;

        if (file_exists($fullPath) && is_file($fullPath)) {
            return $fullPath;
        }

        return null;
    }

    /**
     * Elimina un archivo.
     */
    public function deleteFile(string $relativePath): bool
    {
        $fullPath = $this->getFilePath($relativePath);
        if ($fullPath && file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    // ── Helpers ──

    private function getDir(int $year, int $month): string
    {
        return $this->basePath . '/' . $year . '/' . $month;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576)
            return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)
            return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo del servidor.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo del formulario.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente.',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir en disco.',
            default => 'Error desconocido al subir el archivo.',
        };
    }
}
