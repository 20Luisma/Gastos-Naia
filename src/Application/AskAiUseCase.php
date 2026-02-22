<?php

declare(strict_types=1);

namespace GastosNaia\Application;

use GastosNaia\Domain\ExpenseRepositoryInterface;

class AskAiUseCase
{
    private ExpenseRepositoryInterface $expenseRepository;

    public function __construct(ExpenseRepositoryInterface $expenseRepository)
    {
        $this->expenseRepository = $expenseRepository;
    }

    public function execute(string $question): string
    {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';

        if (empty($apiKey)) {
            return "Error: La clave de la API de Gemini no está configurada. Por favor, añádela al archivo `.env`.";
        }

        // 1. Obtener TODOS los gastos históricos disponibles (con caché de 1h)
        $cacheFile = __DIR__ . '/../../backups/ai_cache.json';
        $cacheTime = 3600; // 1 hora
        $allExpensesData = [];

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
            $allExpensesData = json_decode(file_get_contents($cacheFile), true);
        } else {
            $years = $this->expenseRepository->getAvailableYears();
            foreach ($years as $year) {
                for ($month = 1; $month <= 12; $month++) {
                    // Evitamos sobrecargar la API de Google de golpe con sleep si hiciera falta, 
                    // de momento tiramos normal.
                    $expenses = $this->expenseRepository->getExpenses($year, $month);
                    foreach ($expenses as $expense) {
                        $allExpensesData[] = [
                            'year' => $year,
                            'month' => $month,
                            'date' => $expense->getDate(),
                            'desc' => $expense->getDescription(),
                            'amount' => $expense->getAmount()
                        ];
                    }
                }
            }
            // Asegurarse de que el directorio existe
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0777, true);
            }
            file_put_contents($cacheFile, json_encode($allExpensesData, JSON_UNESCAPED_UNICODE));
        }

        // Si no hay gastos, o son muchísimos (>5000) podríamos cortarlos, pero para Gastos Naia los mandamos todos
        $dataContext = json_encode($allExpensesData, JSON_UNESCAPED_UNICODE);

        // 2. Construir el System Prompt Inteligente
        $systemInstruction = "Eres un Asistente y Contador IA de nivel corporativo para la aplicación 'Gastos Naia'. 
        Tu misión principal es analizar el historial de gastos del usuario y responder a sus consultas con una precisión impecable, un tono corporativo y profesional, y una presentación estructuralmente perfecta.
        
        REGLAS Y CAPACIDADES IMPORTANTES (RESPETA TODAS ESTRICTAMENTE):
        1. **Contexto Temporal:** Usa los campos 'year' (Año) y 'month' (Mes numérico) del JSON para filtrar y cruzar datos con precisión milimétrica cuando te pregunten por fechas.
        2. **Tolerancia a errores tipográficos (Fuzzy Matching):** Asume inteligentemente errores (ej. 'tetto' -> 'teatro', 'Iverdroa' -> 'Iberdrola'). Usa tu comprensión semántica para cruzar intenciones con el historial.
        3. **Análisis Matemático:** Haz cálculos meticulosos. Si te piden sumas totales o promedios, calcúlalos en base al JSON antes de responder.
        4. **Formato y Redacción Profesional (CRÍTICO):** 
           - Redacta como un alto ejecutivo financiero: educado, claro y yendo al grano.
           - Utiliza SIEMPRE formato Markdown rico. 
           - **Deja abundante aire y doble salto de línea** entre párrafos y tablas para que la lectura sea relajada y no esté amontonada.
           - Usa listas (`-` o `*`) para enumerar desgloses.
           - Cualquier tabulación o matriz de datos DEBE mostrarse SIEMPRE como una tabla Markdown pura, con columnas bien definidas.
        5. **Foco Financiero:** Rechaza educadamente cualquier tema que no sea análisis de este historial de gastos.
        
        AQUÍ TIENES TODO EL HISTORIAL DE GASTOS EN JSON (year, month, date, desc, amount):
        " . $dataContext;

        // 3. Preparar la llamada a la API de Google Gemini (gemini-2.5-flash)
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

        $payload = [
            "system_instruction" => [
                "parts" => [
                    ["text" => $systemInstruction]
                ]
            ],
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $question]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.1 // Baja temperatura para que sea analítico y preciso con los números
            ]
        ];

        // 4. Ejecutar la petición HTTP REST neta
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => 30
            ]
        ];

        $context = stream_context_create($options);
        // Suppress warnings so we can manually handle HTTP error codes
        $result = @file_get_contents($url, false, $context);

        // Check headers explicitly for 429 Rate Limit
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (strpos($header, '429 Too Many Requests') !== false) {
                    return "⏳ ¡Vaya! Has agotado las consultas gratuitas por minuto que nos impone Google Gemini. Espérate unos 60 segundos antes de volver a preguntarme algo.";
                }
            }
        }

        if ($result === false) {
            $error = error_get_last();
            return "Error de conexión con la IA de Google: " . ($error['message'] ?? 'Timeout');
        }

        $response = json_decode($result, true);

        if (isset($response['error'])) {
            return "Error de la API de Gemini (Código {$response['error']['code']}): {$response['error']['message']}";
        }

        // Extraer el texto generado
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }

        return "La IA no pudo generar una respuesta coherente.";
    }
}
