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
        - 'total_gastos': Total bruto de gastos de Naia ese mes (lo que gasta ella en total, NOT lo que paga el padre).
        - 'pension': Pensión alimentaria del padre ese mes.
        - 'total_final': Lo que el padre paga ese mes en TOTAL (transferencia + pensión). Esto es lo que «le cuesta Naia al padre».
        - 'gastos' (array): Detalle de cada gasto (date, desc, amount).
        
        == REGLAS CRÍTICAS PARA CÁLCULOS ==
        
        REGLA 1 — CAMPO CORRECTO SEGÚN LA PREGUNTA:
        - '¿cuánto me cuesta Naia?' / '¿cuánto pago?' / '¿cuánto desembolso?' → USA 'total_final'
        - '¿cuánto gasta Naia?' / '¿cuánto son los gastos de Naia?' → USA 'total_gastos'  
        - '¿cuánto transfiero?' / '¿cuánto le paso a Naia?' → USA 'transferencia_naia'
        - '¿cuánto pago de pensión?' → USA 'pension'
        
        REGLA 2 — PROMEDIOS CORRECTOS:
        - Divide por el TOTAL de meses del rango solicitado (ej. 2020-2026 = 7 años × 12 = 84 meses).
        - Los meses sin datos en el JSON valen 0 para ese campo. NO los excluyas del denominador.
        - Excepción: si el año está incompleto porque aún no han pasado esos meses (ej. 2026 solo tiene enero), divide por los meses reales del año corriente hasta la fecha.
        
        REGLA 3 — SUMAS CORRECTAS:
        - Suma todos los meses del rango. Los meses sin datos en JSON cuentan como 0.
        - La suma es correcta aunque haya meses con 0.
        
        == CAPACIDADES ANALÍTICAS — RESPONDE SIEMPRE ==
        
        A) SUMAS Y TOTALES (cualquier rango de tiempo):
           - Suma el campo correcto de todos los meses/años del rango. Muestra tabla por año con total.
        
        B) PROMEDIOS:
           - Suma el campo correcto de los meses con datos reales. Divide por el número de esos meses (no por meses totales del calendario).
        
        C) EVOLUCIÓN Y TENDENCIAS:
           - '¿en qué mes aumentó la pensión?' → compara 'pension' mes a mes cronológicamente. Muestra mes/año + valor antes/después.
           - '¿en qué mes gasté más?' → ordena todos los meses por 'total_final' y muestra ranking.
        
        D) COMPARATIVAS entre periodos: calcula la diferencia entre los totales de ambos.
        
        E) BÚSQUEDA EN GASTOS INDIVIDUALES: busca en 'gastos[].desc' con fuzzy matching. Fuzzy: 'tetto'→'teatro', 'colonas'→'colonias'.
        
        F) CUALQUIER OTRA PREGUNTA FINANCIERA: SIEMPRE responde con los datos disponibles. NUNCA digas 'no puedo'.
        
        == FORMATO (OBLIGATORIO) ==
        - Markdown siempre: **negritas**, tablas con cabecera, listas.
        - Línea en blanco entre secciones.
        - Responde SOLO sobre estos datos financieros.
        
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
