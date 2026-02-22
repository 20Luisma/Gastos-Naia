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

            // Annual grand totals (from "Total Final:" label in annual sheet — reliable)
            $annualTotals = $this->expenseRepository->getAnnualTotals();
            $annualByYear = [];
            foreach ($annualTotals as $a) {
                $annualByYear[$a['year']] = $a['total'];
            }

            foreach ($years as $year) {
                // Monthly totals from the annual summary sheet (reliable for total_gastos)
                $monthlyTotals = $this->expenseRepository->getMonthlyTotals($year);
                $annualTotal = $annualByYear[$year] ?? 0.0;

                $meses = [];
                $lastKnownPension = 0.0;

                // First pass: find the earliest known pension in the year to use as fallback for empty months
                foreach ($monthlyTotals as $mt) {
                    $summary = $this->expenseRepository->getMonthlyFinancialSummary($year, $mt['month']);
                    if ($summary['pension'] > 0.0) {
                        $lastKnownPension = $summary['pension'];
                        break;
                    }
                }

                foreach ($monthlyTotals as $mt) {
                    $month = $mt['month'];
                    $totalGastos = $mt['total'];
                    $transferencia = $totalGastos > 0.0 ? round($totalGastos / 2, 2) : 0.0;

                    // Get exact pension for THIS specific month (handles mid-year increases like in 2024)
                    $summary = $this->expenseRepository->getMonthlyFinancialSummary($year, $month);
                    $pension = $summary['pension'] > 0.0 ? $summary['pension'] : $lastKnownPension;
                    if ($pension > 0.0) {
                        $lastKnownPension = $pension; // keep updating fallback for future empty months
                    }

                    $totalFinal = round($transferencia + $pension, 2);

                    // Individual expenses for detail queries
                    $expenses = $this->expenseRepository->getExpenses($year, $month);
                    $items = [];
                    foreach ($expenses as $expense) {
                        $items[] = [
                            'date' => $expense->getDate(),
                            'desc' => $expense->getDescription(),
                            'amount' => $expense->getAmount(),
                        ];
                    }

                    $meses[] = [
                        'mes' => $month,
                        'nombre' => $mt['name'],
                        'total_gastos' => $totalGastos,
                        'transferencia_naia' => $transferencia,
                        'pension' => $pension,        // Exact pension for this month
                        'total_final' => $totalFinal,
                        'gastos' => $items,
                    ];
                }

                if ($annualTotal > 0.0 || !empty(array_filter($meses, fn($m) => !empty($m['gastos'])))) {
                    $contextData[] = [
                        'year' => $year,
                        'total_anual' => $annualTotal,
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
        
        == COMPORTAMIENTO PROACTIVO (MUY IMPORTANTE) ==
        Cuando la pregunta sea genérica o ambigua (ej. '¿cuánto me cuesta Naia?', '¿cuánto gasto?', '¿cuánto pago?'):
        - NO elijas un solo campo y te arriesgues a equivocarte.
        - Muestra TODOS los ángulos financieros relevantes con sus valores, claramente etiquetados.
        - Ejemplo de respuesta proactiva para '¿cuánto me cuesta Naia al mes en promedio?':
          📊 **Aquí tienes el desglose completo del coste mensual medio:**
          - **Gastos compartidos de Naia** (total_gastos / 2): X€ — lo que pagas de los gastos de actividades, comedor, etc.
          - **Pensión alimentaria** (pension): Y€ — cuota fija mensual
          - **Total que desembolsas** (total_final): Z€ — la suma de todo lo anterior
          *¿Quieres el detalle por año o por mes?*
        - Al final, invita al usuario a afinar si lo desea.
        - Si aún así la pregunta es completamente ambigua entre dos campos, muéstralos ambos con una explicación de la diferencia.
        
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
        - Cuenta exactamente los meses que aparecen en el JSON con total_final > 0 dentro del rango pedido.
        - NUNCA uses años × 12 como denominador. 2026 puede tener solo 1 mes de datos — ese 1 mes es el denominador para 2026.
        - Ejemplo: si 2020 tiene 9 meses y 2026 tiene 1 mes → divide la suma por esos meses reales (no por 84).
        - Para promedios multi-año: muestra el promedio anual (total_año / meses_con_datos_ese_año) y el promedio global.
        
        REGLA 3 — SUMAS CORRECTAS:
        - Suma todos los total_final de los meses con datos en el rango solicitado.
        - Los meses que NO están en el JSON (futuro o sin datos) no cuentan ni en suma ni en denominador.
        
        REGLA 4 — PENSIÓN:
        - El campo 'pension' es la pensión mensual de ese mes. Para el total anual de pensión: suma los valores de 'pension' de cada mes del año.
        - NUNCA restes pension de total_final (ya está incluida). total_final = transferencia_naia + pension.
        

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

        // 3. Preparar la llamada a la API de OpenAI (gpt-4o)
        $url = 'https://api.openai.com/v1/chat/completions';

        $payload = [
            'model' => 'gpt-4o',
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
