<?php

namespace GastosNaia\Application;

class UploadComunicadoFileUseCase
{
    private string $uploadDir;

    public function __construct(string $publicDir)
    {
        // En nuestro caso el publicDir será la raíz del servidor público (ej: .../public)
        // Guardaremos en /uploads/comunicados/
        $this->uploadDir = rtrim($publicDir, '/') . '/uploads/comunicados/';

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function execute(array $file): string
    {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            throw new \Exception("Archivo inválido o vacío.");
        }

        $maxSize = 15 * 1024 * 1024; // 15MB
        if ($file['size'] > $maxSize) {
            throw new \Exception("El archivo es demasiado grande (máx 15MB).");
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // Generar un nombre único para evitar colisiones
        $newFilename = uniqid('comunicado_') . '.' . $extension;
        $destinationPath = $this->uploadDir . $newFilename;

        // Si es una imagen JPG/PNG/WEBP, intentamos comprimirla
        $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $compressed = false;

        if ($isImage) {
            $compressed = $this->compressImage($file['tmp_name'], $destinationPath, 65);
        }

        // Si no se pudo comprimir (o no era imagen), mover normalmente
        if (!$compressed) {
            if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
                throw new \Exception("Error al guardar archivo en el servidor.");
            }
        }

        // Devolver la URL relativa para guardar en Firebase (sin barra inicial)
        return 'uploads/comunicados/' . $newFilename;
    }

    /**
     * Comprime la imagen y la guarda en el destino usando GD.
     * Retorna true si tuvo éxito, false si falló o no soporta el formato.
     */
    private function compressImage(string $source, string $destination, int $quality): bool
    {
        $info = @getimagesize($source);
        if (!$info)
            return false;

        $mime = $info['mime'];

        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($source);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source);
                break;
            case 'image/webp':
                // La función de WebP a veces no está habilitada en todas las versiones de PHP GD
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($source);
                } else {
                    return false;
                }
                break;
            default:
                return false;
        }

        if (!$image)
            return false;

        $result = false;
        // Guardar la imagen comprimida (convertimos a JPG por defecto para ahorrar máximo espacio, excepto si queremos mantener transparencia, pero para simplificar, usamos WebP o JPG según corresponda, aquí usaremos JPG)
        if ($mime == 'image/png') {
            // Manejar PNGs (compresión PNG va de 0 a 9, y 9 es max compresión, calidad no es muy útil a veces)
            $qualityPng = floor(($quality / 100) * 9);
            // Invert quality semantics for PNG
            $qualityPng = 9 - $qualityPng;
            $result = @imagepng($image, $destination, $qualityPng);
        } else if ($mime == 'image/webp') {
            $result = @imagewebp($image, $destination, $quality);
        } else if ($mime == 'image/gif') {
            // Para GIF lo movemos tal cual si no podemos comprimirlo bien
            return false;
        } else {
            // JPEG
            $result = @imagejpeg($image, $destination, $quality);
        }

        imagedestroy($image);
        return $result;
    }
}
