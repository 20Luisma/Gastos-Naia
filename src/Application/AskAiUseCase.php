<?php

declare(strict_types=1);

namespace GastosNaia\Application;

use GastosNaia\Infrastructure\FirebaseReadRepository;

class AskAiUseCase
{
    private FirebaseReadRepository $firebase;

    public function __construct(FirebaseReadRepository $firebase)
    {
        $this->firebase = $firebase;
    }

    public function execute(string $question): string
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        $apiKey = is_string($apiKey) ? $apiKey : '';

        if (empty($apiKey)) {
            return "Error: La clave de la API de OpenAI no está configurada. Por favor, añádela al archivo `.env` como `OPENAI_API_KEY`.";
        }

        // 1. Obtener contexto instantáneo de la Read Replica en Firebase (todo el histórico precalculado)
        $fullContext = $this->firebase->getFullContext();

        if (!$fullContext || !isset($fullContext['years'])) {
            return "Lo siento, la base de datos inteligente (Firebase) aún no ha sido sincronizada. Por favor, ejecuta la sincronización inicial.";
        }

        $contextData = [];
        // Flatten the Firebase Object "years" hashmap down to the chronological array GPT expects
        foreach ($fullContext['years'] as $yearStr => $yearData) {
            $mesesArray = [];
            if (is_array($yearData['meses'])) {
                foreach ($yearData['meses'] as $monthStr => $mesData) {
                    if (is_array($mesData)) {
                        $mesesArray[] = $mesData;
                    }
                }
            }
            // Sort months to ensure chronological order despite JSON key hashing, checking if keys exist safely
            usort($mesesArray, fn($a, $b) => ($a['mes'] ?? 0) <=> ($b['mes'] ?? 0));

            $yearData['meses'] = $mesesArray;
            $contextData[] = $yearData;
        }

        // Sort years chronologically
        usort($contextData, fn($a, $b) => $a['year'] <=> $b['year']);

        // ==============================================================
        // FEATURE: PRE-CÁLCULO EXACTO PARA EVITAR ALUCINACIÓN ARITMÉTICA
        // ==============================================================
        $totalHistoricoAbsoluto = 0.0;
        $totalesPorAno = [];

        foreach ($contextData as $yearObj) {
            $sumaAno = 0.0;
            if (isset($yearObj['meses']) && is_array($yearObj['meses'])) {
                foreach ($yearObj['meses'] as $mesObj) {
                    if (isset($mesObj['total_final']) && is_numeric($mesObj['total_final'])) {
                        $sumaAno += (float) $mesObj['total_final'];
                    }
                }
            }
            $totalesPorAno[$yearObj['year']] = round($sumaAno, 2);
            $totalHistoricoAbsoluto += $sumaAno;
        }
        $totalHistoricoAbsoluto = round($totalHistoricoAbsoluto, 2);

        $preCalculatedMetrics = [
            'TOTAL_HISTORICO_ABSOLUTO (Suma real y exacta de todos los años juntos)' => $totalHistoricoAbsoluto,
            'TOTAL_EXACTO_POR_AÑO' => $totalesPorAno
        ];

        $dataContext = json_encode($contextData, JSON_UNESCAPED_UNICODE);
        $precalcContext = json_encode($preCalculatedMetrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

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
        - **CRÍTICO: NO TENGAS ALUCINACIONES ARITMÉTICAS. USA EXCLUSIVAMENTE LOS PRE-CÁLCULOS EXACTOS DE LA SECCIÓN 'MÉTRICAS MATEMÁTICAS PRE-CALCULADAS'** para dar totales por año o el total histórico absoluto.
        - NO intentes sumar tú los totales año por año para responder a gastos totales históricos. Usa el bloque de métricas pre-calculadas que ya es matemática pura.
        
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
        
        F) REGLA ANTI-ALUCINACIONES (CRÍTICO):
           - NUNCA INVENTES DATOS. NUNCA INVENTES NÚMEROS O CONCEPTOS que no existan explícitamente en el JSON proporcionado.
           - Si para un mes no hay un gasto solicitado (ej. 'comedor'), simplemente OMITE ese mes o di explícitamente 'En [Mes] no hay gastos de [X]'.
           - Si la suma total es de los meses existentes, da solo esa suma. No rellenes datos faltantes con estimaciones matemáticas bajo ninguna circunstancia.
        
        == FORMATO (OBLIGATORIO) ==
        - Markdown siempre: **negritas**, tablas con cabecera, listas.
        - Línea en blanco entre secciones.
        - Responde SOLO sobre estos datos financieros.
        
        == ESTRUCTURA DEL JSON ==
        [ { year: 2026, meses: [ { mes: 1, total_gastos: 250.83, transferencia_naia: 125.42, pension: 238.20, total_final: 363.62, gastos: [{date, desc, amount}] } ] } ]
        
        == MÉTRICAS MATEMÁTICAS PRE-CALCULADAS (100% FIABLES) ==
        " . $precalcContext . "
        
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
