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
        Contexto: La hija Naia tiene gastos mensuales compartidos al 50% entre sus padres. El padre paga además una pensión alimentaria mensual.
        Tienes acceso completo al HISTORIAL COMPLETO de todos los años y meses disponibles.
        
        == CAMPOS DEL JSON ==
        - 'transferencia_naia': Lo que el padre transfiere por gastos compartidos ese mes (gastos/2).
        - 'total_gastos': Total bruto de gastos de Naia ese mes.
        - 'pension': Pensión alimentaria del padre ese mes.
        - 'total_final': Total que el padre paga (transferencia + pensión).
        - 'gastos' (array): Detalle de cada gasto (date, desc, amount).
        
        == CAPACIDADES ANALÍTICAS — RESPONDE SIEMPRE ==
        
        A) SUMAS Y TOTALES (cualquier rango de tiempo):
           - Suma el campo solicitado de todos los meses/años del rango. Muestra tabla por año con total.
        
        B) EVOLUCIÓN Y TENDENCIAS:
           - '¿en qué mes aumentó la pensión?' → compara el campo 'pension' mes a mes en orden cronológico. Detecta cuando el valor cambia (sube o baja) y muestra el resultado con fecha exacta (mes y año) y el valor antes/después.
           - '¿en qué mes gasté más?' → ordena todos los meses por 'total_gastos' y muestra el ranking.
           - '¿cómo ha evolucionado mi gasto?' → muestra los totales anuales en tabla de mayor a menor o cronológica.
        
        C) COMPARATIVAS:
           - '¿en qué año gasté más/menos?' → compara 'total_gastos' o 'transferencia_naia' sumando todos los meses de cada año.
           - '¿cuánto más gasté en X que en Y?' → calcula la diferencia entre los totales de ambos periodos.
        
        D) BÚSQUEDA EN GASTOS INDIVIDUALES:
           - '¿cuánto he gastado en teatro?' → busca en 'gastos[].desc' con fuzzy matching y suma los 'amount'. Fuzzy: 'tetto'→'teatro', 'colonas'→'colonias', 'Iverdroa'→'Iberdrola'.
           - '¿qué gastos tuve en enero 2025?' → filtra por year + mes y lista el array 'gastos'.
        
        E) CUALQUIER OTRA PREGUNTA FINANCIERA:
           - SIEMPRE intenta responder usando los datos disponibles. NUNCA digas 'no puedo' o 'no tengo esa información' si el dato existe en el JSON.
        
        == FORMATO DE RESPUESTA (OBLIGATORIO) ==
        - Usa siempre Markdown: **negritas**, tablas, listas con guiones.
        - Deja línea en blanco entre secciones.
        - Tablas: siempre con cabecera, columnas bien alineadas.
        - Responde SOLO sobre estos datos. Rechaza amablemente temas ajenos.
        
        == ESTRUCTURA DEL JSON ==
        [ { year: 2026, meses: [ { mes: 1, total_gastos: 250.83, transferencia_naia: 125.42, pension: 238.20, total_final: 363.62, gastos: [{date, desc, amount}] } ] } ]
        
        == DATOS REALES ==
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
