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

        // 1. Obtener TODOS los datos (con caché de 1h)
        $cacheFile = __DIR__ . '/../../backups/ai_cache.json';
        $cacheTime = 3600; // 1 hora
        $contextData = [];

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
            $contextData = json_decode(file_get_contents($cacheFile), true);
        } else {
            $years = $this->expenseRepository->getAvailableYears();

            foreach ($years as $year) {
                $meses = [];
                for ($month = 1; $month <= 12; $month++) {
                    // Resumen financiero oficial del mes (E11/E14/E15/E16 del sheet)
                    $finSummary = $this->expenseRepository->getMonthlyFinancialSummary($year, $month);

                    // Gastos individuales del mes para preguntas de detalle
                    $expenses = $this->expenseRepository->getExpenses($year, $month);
                    $items = [];
                    foreach ($expenses as $expense) {
                        $items[] = [
                            'date' => $expense->getDate(),
                            'desc' => $expense->getDescription(),
                            'amount' => $expense->getAmount(),
                        ];
                    }

                    if (empty($items) && $finSummary['total_gastos'] === 0.0) {
                        continue; // Skip empty months
                    }

                    $meses[] = [
                        'mes' => $month,
                        'total_gastos' => $finSummary['total_gastos'],       // E11: suma de gastos
                        'transferencia_naia' => $finSummary['transferencia_naia'], // E14: lo que le paso a Naia
                        'pension' => $finSummary['pension'],            // E15: pensión alimentaria
                        'total_final' => $finSummary['total_final'],        // E16: total_a_pagar (E14+E15)
                        'gastos' => $items,                            // detalle de cada gasto
                    ];
                }

                if (!empty($meses)) {
                    $contextData[] = [
                        'year' => $year,
                        'meses' => $meses,
                    ];
                }
            }

            // Guardar caché
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0777, true);
            }
            file_put_contents($cacheFile, json_encode($contextData, JSON_UNESCAPED_UNICODE));
        }

        $dataContext = json_encode($contextData, JSON_UNESCAPED_UNICODE);

        // 2. System Prompt Corporativo
        $systemPrompt = "Eres el Asistente Contable IA de la aplicación 'Gastos Naia'.
        Analizas los gastos de la hija Naia que son compartidos entre sus dos progenitores al 50%.
        Responde siempre con precisión, tono profesional y formato Markdown.
        
        REGLAS CRÍTICAS:
        1. **Campo 'transferencia_naia'**: Es la cantidad exacta que el padre debe transferir a la madre por los gastos compartidos del mes (la mitad del total de gastos). USA ESTE CAMPO para preguntas como '¿cuánto le tengo que pasar a Naia?' o '¿cuánto debo transferir?'.
        2. **Campo 'total_gastos'**: Es el total bruto de gastos del mes (lo que gasta Naia en total). USA ESTE para preguntas sobre el gasto total de Naia.
        3. **Campo 'pension'**: Es la pensión alimentaria que paga el padre aparte de la transferencia.
        4. **Campo 'total_final'**: Es la suma de 'transferencia_naia' + 'pension'. Lo que el padre paga en total ese mes.
        5. **Campo 'gastos' (array)**: Contiene los gastos individuales (date, desc, amount). USA ESTE para detallar en qué se gastó el dinero.
        6. Fuzzy Matching: 'tetto'→'teatro', 'Iverdroa'→'Iberdrola', etc.
        7. Formato: usa tablas Markdown, negritas, listas. Deja espacio entre secciones.
        8. Responde SOLO sobre estos gastos. Rechaza amablemente otros temas.
        
        ESTRUCTURA DEL JSON DE DATOS:
        [
          {
            'year': 2026,
            'meses': [
              {
                'mes': 1,                      (enero=1, febrero=2, ...)
                'total_gastos': 250.83,        ← Total bruto de gastos de Naia ese mes
                'transferencia_naia': 125.42,  ← Lo que el padre transfiere a la madre
                'pension': 238.20,             ← Pensión alimentaria del padre
                'total_final': 363.62,         ← Todo lo que paga el padre (transferencia+pension)
                'gastos': [                    ← Detalle de cada gasto individual
                  {'date': '20/01/2026', 'desc': 'Baile', 'amount': 43.0},
                  ...
                ]
              },
              ...
            ]
          },
          ...
        ]
        
        DATOS REALES:
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
