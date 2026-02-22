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
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        $apiKey = is_string($apiKey) ? $apiKey : '';

        if (empty($apiKey)) {
            return "Error: La clave de la API de OpenAI no está configurada. Por favor, añádela al archivo `.env` como `OPENAI_API_KEY`.";
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

        $dataContext = json_encode($allExpensesData, JSON_UNESCAPED_UNICODE);

        // 2. System Prompt Corporativo
        $systemPrompt = "Eres un Asistente y Contador IA de nivel corporativo para la aplicación 'Gastos Naia'. 
        Tu misión principal es analizar el historial de gastos del usuario y responder con una precisión impecable, un tono corporativo y profesional, y una presentación estructuralmente perfecta.
        
        REGLAS Y CAPACIDADES IMPORTANTES (RESPETA TODAS ESTRICTAMENTE):
        1. **Contexto Temporal:** Usa los campos 'year' (Año) y 'month' (Mes numérico) del JSON para filtrar y cruzar datos con precisión milimétrica cuando te pregunten por fechas.
        2. **Tolerancia a errores tipográficos (Fuzzy Matching):** Asume inteligentemente errores (ej. 'tetto' -> 'teatro', 'Iverdroa' -> 'Iberdrola'). Usa tu comprensión semántica para cruzar intenciones con el historial.
        3. **Análisis Matemático:** Haz cálculos meticulosos. Si te piden sumas totales o promedios, calcúlalos en base al JSON antes de responder.
        4. **Formato y Redacción Profesional (CRÍTICO):** 
           - Redacta como un alto ejecutivo financiero: educado, claro y yendo al grano.
           - Utiliza SIEMPRE formato Markdown rico. 
           - Deja abundante aire y doble salto de línea entre párrafos y tablas.
           - Usa listas (- o *) para enumerar desgloses.
           - Cualquier tabulación DEBE mostrarse SIEMPRE como una tabla Markdown pura, con columnas bien definidas.
        5. **Foco Financiero:** Rechaza educadamente cualquier tema que no sea análisis de este historial de gastos.
        
        AQUÍ TIENES TODO EL HISTORIAL DE GASTOS EN JSON (year, month, date, desc, amount):
        " . $dataContext;

        // 3. Preparar la llamada a la API de OpenAI (gpt-4o-mini)
        $url = 'https://api.openai.com/v1/chat/completions';

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $question],
            ],
        ];

        // 4. Ejecutar la petición HTTP
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'method' => 'POST',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        // Gestión de errores HTTP
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (strpos($header, '429') !== false) {
                    return "⏳ Has superado el límite de peticiones de OpenAI. Espera un momento y vuelve a intentarlo.";
                }
                if (strpos($header, '401') !== false) {
                    return "❌ La clave de API de OpenAI no es válida o ha expirado. Revisa tu `OPENAI_API_KEY`.";
                }
            }
        }

        if ($result === false) {
            $error = error_get_last();
            return "Error de conexión con OpenAI: " . ($error['message'] ?? 'Timeout o conexión rechazada');
        }

        $response = json_decode($result, true);

        if (isset($response['error'])) {
            return "Error de OpenAI ({$response['error']['type']}): {$response['error']['message']}";
        }

        // Extraer el texto generado
        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }

        return "La IA no pudo generar una respuesta coherente.";
    }
}
