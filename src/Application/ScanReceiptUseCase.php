<?php

declare(strict_types=1);

namespace GastosNaia\Application;

class ScanReceiptUseCase
{
    /**
     * @param string $tmpFilePath Path to the uploaded temporary image file
     * @param string $mimeType MIME type of the image (e.g., image/jpeg)
     * @return array Associative array with keys: date, desc, amount
     */
    public function execute(string $tmpFilePath, string $mimeType): array
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception("Clave de API de OpenAI no configurada.");
        }

        $supportedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
        if (!in_array($mimeType, $supportedMimes)) {
            throw new \Exception("Formato no soportado por el OCR. Usa JPG, PNG, WEBP o PDF.");
        }

        // Si es PDF, convertir la primera página a JPG en memoria
        if ($mimeType === 'application/pdf') {
            if (extension_loaded('imagick')) {
                try {
                    $imagick = new \Imagick();
                    $imagick->setResolution(150, 150);
                    // Leer solo la primera página del PDF
                    $imagick->readImage($tmpFilePath . '[0]');
                    $imagick->setImageFormat('jpeg');
                    $imagick->setImageCompressionQuality(85);

                    // Fondo blanco por si el PDF es transparente
                    $imagick->setImageBackgroundColor('white');
                    $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                    $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

                    $fileContents = $imagick->getImageBlob();
                    $mimeType = 'image/jpeg';
                    $imagick->clear();
                    $imagick->destroy();
                } catch (\Exception $e) {
                    throw new \Exception("Ocurrió un error al convertir el PDF a imagen con Imagick: " . $e->getMessage());
                }
            } else {
                // Fallback para macOS local development o donde esté disponible `sips`
                $sipsPath = '/usr/bin/sips';
                if (file_exists($sipsPath)) {
                    $tmpPdf = tempnam(sys_get_temp_dir(), 'ocr_') . '.pdf';
                    copy($tmpFilePath, $tmpPdf);

                    $tmpJpg = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
                    // sips por defecto renderiza la primera página de un PDF a imagen
                    exec(sprintf('%s -s format jpeg %s --out %s 2>&1', escapeshellarg($sipsPath), escapeshellarg($tmpPdf), escapeshellarg($tmpJpg)), $output, $returnVar);

                    @unlink($tmpPdf);

                    if ($returnVar === 0 && file_exists($tmpJpg)) {
                        $fileContents = file_get_contents($tmpJpg);
                        $mimeType = 'image/jpeg';
                        @unlink($tmpJpg);
                    } else {
                        @unlink($tmpJpg);
                        throw new \Exception("Falló el fallback sips local al convertir el PDF a imagen.");
                    }
                } else {
                    throw new \Exception("La extensión Imagick no está instalada y tampoco hay fallback disponible en este servidor para procesar PDFs.");
                }
            }
        } else {
            // Leer archivo original
            $fileContents = @file_get_contents($tmpFilePath);
            if ($fileContents === false) {
                throw new \Exception("Error al leer el archivo imagen temporal.");
            }
        }

        $base64Image = base64_encode($fileContents);

        $systemPrompt = "Eres un contable experto. Tu tarea es extraer de este recibo o ticket 3 campos y devolver un JSON puro (sin formato markdown ni \`\`\`json):
1. 'date': Fecha del recibo en formato YYYY-MM-DD. Si solo hay día y mes, asume el año actual o el que parezca lógico. Si no hay fecha, usa la fecha actual.
2. 'desc': Un concepto resumido muy breve (1, 2 o 3 palabras máximo). Capitaliza la primera letra. Ejemplo: 'Supermercado', 'Gasolina', 'Farmacia', 'Material escolar'.
3. 'amount': El o los importes totales pagados. Un número float válido (Ej: 14.50, 120.00).

Ejemplo de salida obligatoria:
{\"date\": \"2024-05-14\", \"desc\": \"Supermercado Mercadona\", \"amount\": 42.15}";

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.1,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $systemPrompt
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}",
                                'detail' => 'auto' // low or high or auto
                            ]
                        ]
                    ]
                ]
            ],
            'max_tokens' => 200,
            // Pedimos text o json_object si el modelo lo permite (gpt-4o-mini permite json_object con un prompt adecuado)
            'response_format' => ['type' => 'json_object']
        ];

        $url = 'https://api.openai.com/v1/chat/completions';
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'method' => 'POST',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => 30, // 30s timeout for vision
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            throw new \Exception("Error de conexión con OpenAI Vision: " . ($error['message'] ?? 'Desconocido'));
        }

        $response = json_decode($result, true);

        if (isset($response['error'])) {
            throw new \Exception("Error API OpenAI: {$response['error']['message']}");
        }

        $jsonText = $response['choices'][0]['message']['content'] ?? '';

        // Parsear el JSON devuelto
        $parsed = json_decode(trim($jsonText), true);
        if (!$parsed || !isset($parsed['amount'])) {
            throw new \Exception("La IA no devolvió un JSON válido: {$jsonText}");
        }

        return [
            'date' => $parsed['date'] ?? date('Y-m-d'),
            'description' => $parsed['desc'] ?? 'Gasto sin concepto',
            'amount' => (float) ($parsed['amount'] ?? 0.0)
        ];
    }
}
