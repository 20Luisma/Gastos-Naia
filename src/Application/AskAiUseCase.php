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
        $totalesAbsolutos = [
            'total_final' => 0.0,
            'total_gastos' => 0.0,
            'transferencia_naia' => 0.0,
            'pension' => 0.0
        ];

        $totalesPorAno = [];

        foreach ($contextData as $yearObj) {
            $yearStr = (string) $yearObj['year'];
            $sumaAno = [
                'total_final' => isset($yearObj['total_anual']) ? (float) $yearObj['total_anual'] : 0.0,
                'total_gastos' => 0.0,
                'transferencia_naia' => 0.0,
                'pension' => 0.0
            ];

            if (isset($yearObj['meses']) && is_array($yearObj['meses'])) {
                foreach ($yearObj['meses'] as $mesObj) {
                    $sumaAno['total_gastos'] += (float) ($mesObj['total_gastos'] ?? 0);
                    $sumaAno['transferencia_naia'] += (float) ($mesObj['transferencia_naia'] ?? 0);
                    $sumaAno['pension'] += (float) ($mesObj['pension'] ?? 0);
                }
            }

            foreach (['total_final', 'total_gastos', 'transferencia_naia', 'pension'] as $k) {
                $sumaAno[$k] = round($sumaAno[$k], 2);
                $totalesAbsolutos[$k] += $sumaAno[$k];
            }

            $totalesPorAno[$yearStr] = $sumaAno;
        }

        foreach (['total_final', 'total_gastos', 'transferencia_naia', 'pension'] as $k) {
            $totalesAbsolutos[$k] = round($totalesAbsolutos[$k], 2);
        }

        $preCalculatedMetrics = [
            'SUMAS_HISTÓRICAS_ABSOLUTAS_DE_TODOS_LOS_AÑOS' => $totalesAbsolutos,
            'SUMAS_TOTALES_POR_CADA_AÑO' => $totalesPorAno
        ];

        $dataContext = json_encode($contextData, JSON_UNESCAPED_UNICODE);
        $precalcContext = json_encode($preCalculatedMetrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // 2. System Prompt Corporativo
        $systemPrompt = "Eres el Asistente Contable IA de la aplicación 'Universo Naia'.
        Contexto: La hija Naia tiene gastos mensuales compartidos al 50% entre sus padres. El padre paga además una pensión alimentaria mensual.
        Tienes acceso completo al HISTORIAL COMPLETO de todos los años y meses disponibles.
        
        == COMPORTAMIENTO PROACTIVO (MUY IMPORTANTE) ==
        Cuando la pregunta sea genérica o ambigua (ej. '¿cuánto me cuesta Naia?', '¿cuánto gasto?', '¿cuánto pago?'):
        - NO elijas un solo campo y te arriesgues a equivocarte.
        - Muestra TODOS los ángulos financieros relevantes con sus valores, claramente etiquetados.
        - Ejemplo de respuesta proactiva para '¿cuánto le deposito a Naia al mes en promedio?':
          📊 **Aquí tienes el desglose completo del coste mensual medio:**
          - **Total / 2** (transferencia_naia): X€ — tu parte de los gastos del mes.
          - **Pensión alimentaria** (pension): Y€ — cuota fija mensual.
          - **Lo que le deposito a Naia** (total_final): Z€ — la suma del Total / 2 más la pensión.
          *¿Quieres el detalle por año o por mes?*
        - Al final, invita al usuario a afinar si lo desea.
        - Si aún así la pregunta es completamente ambigua entre dos campos, muéstralos ambos con una explicación de la diferencia.
        
        == CAMPOS DEL JSON ==
        - 'transferencia_naia': **Es el \"Total / 2\"**, lo que el padre transfiere de su bolsillo por la mitad de los gastos del mes.
        - 'total_gastos': Total bruto de gastos de Naia ese mes (lo que gasta ella en total, NOT lo que paga el padre).
        - 'pension': Pensión alimentaria del padre ese mes.
        - 'total_final': **Es \"lo que le deposito a Naia\"**, y corresponde a la suma estricta del Total / 2 más la pensión de ese mes.
        - 'gastos' (array): Detalle de cada gasto individual (date, desc, amount).
        
        == REGLAS CRÍTICAS PARA CÁLCULOS Y VOCABULARIO ==
        
        REGLA 1 — VOCABULARIO OBLIGATORIO (MUY IMPORTANTE):
        - Para hablar de 'total_final': SIEMPRE di \"Lo que le deposito a Naia\" o \"Total Final\". NUNCA digas \"desembolso\", ni \"total pagado\".
        - Para hablar de 'transferencia_naia': SIEMPRE di \"Total / 2\". NUNCA digas \"gastos compartidos\", ni \"transferencia por gastos\".
        - Si sugieres preguntas al usuario, usa estrictamente este vocabulario (ej. \"¿Cuánto es el Total / 2 en 2026?\").
        
        REGLA 2 — CAMPO CORRECTO SEGÚN LA PREGUNTA:
        - '¿cuánto le deposito a Naia?' / '¿cuál es el total final?' → USA 'total_final'
        - '¿cuánto gasta Naia?' / '¿cuánto son los gastos de Naia?' → USA 'total_gastos'  
        - '¿cuánto es el Total / 2?' / '¿cuánto transfiero?' → USA 'transferencia_naia'
        - '¿cuánto pago de pensión?' → USA 'pension'
        
        REGLA 3 — PROMEDIOS CORRECTOS:
        - Cuenta exactamente los meses que aparecen en el JSON con total_final > 0 dentro del rango pedido.
        - NUNCA uses años × 12 como denominador. 2026 puede tener solo 1 mes de datos — ese 1 mes es el denominador para 2026.
        - Ejemplo: si 2020 tiene 9 meses y 2026 tiene 1 mes → divide la suma por esos meses reales (no por 84).
        - Para promedios multi-año: muestra el promedio anual (total_año / meses_con_datos_ese_año) y el promedio global.
        
        REGLA 4 — SUMAS CORRECTAS:
        - **PROHIBICIÓN ESTRICTA:** Tienes PROHIBIDO sumar mediante cálculo matemático los campos de meses individuales para responder totales anuales o totales históricos de cualquier campo.
        - **OBLIGACIÓN:** Para dar totales históricos (la suma desde que hay registros hasta hoy) o sumas completas de años, TIENES QUE LEER LITERALMENTE LOS DATOS de la sección 'MÉTRICAS MATEMÁTICAS PRE-CALCULADAS'.
        - Ahí tienes ya pre-sumados y calculados con 100% de precisión los acumulados históricos absolutos de 'total_gastos', 'pension', 'total_final', etc. ÚSALOS SIEMPRE.
        
        REGLA 5 — PENSIÓN REGLA DE EXTRACCIÓN LITERAL:
        - El campo 'pension' especifica la cuota de ese mes.
        - Cuando te pregunten por la pensión de un mes concreto (ej: Febrero 2025), busca el nodo EXACTO de ese año y mes en el JSON, localiza la propiedad 'pension' y ESCRIBE EXACTAMENTE EL NÚMERO QUE PONE AHÍ.
        - BAJO NINGÚN CONCEPTO aproximes, adivines, arrastres el valor de enero o uses tus propios cálculos matemáticos. Si dice 238.20, tu respuesta debe decir 238.20. Si te inventas los números o los cruzas de otro mes del mismo año fracasarás rotundamente.
        - TOTAL ANUAL: Para saber el total anual de pensión, suma los valores de 'pension' de cada mes del año estrictamente.
        - NUNCA restes pension de total_final (ya está incluida). total_final = transferencia_naia + pension.
        

        == CAPACIDADES ANALÍTICAS — RESPONDE SIEMPRE ==
        
        A) SUMAS Y TOTALES HISTÓRICOS Y ANUALES:
           - LEE directamente la sección 'MÉTRICAS MATEMÁTICAS PRE-CALCULADAS'. Muestra tabla por año extraída de ahí y muestra el Absoluto extraído de ahí. NUNCA SUMES TÚ.
        
        B) PROMEDIOS:
           - Suma el campo correcto de los meses con datos reales. Divide por el número de esos meses (no por meses totales del calendario).
        
        C) MÁXIMOS, MÍNIMOS Y EVOLUCIÓN (CRÍTICO):
           - Si te preguntan '¿en qué mes gasté más?', '¿cuál fue el gasto más alto?', '¿mayor cantidad?': 
             TIENES LA OBLIGACIÓN ESTRICTA de barrer visualmente TODOS los años y TODOS los meses del bloque de DATOS REALES sin saltarte ninguno.
           - Compara matemáticamente el valor exacto de 'total_final' (o el campo que pidan) de todos los nodos.
           - NO confíes en tu memoria. Extrae todos los valores, compáralos uno a uno, y devuelve estrictamente el que tenga el número más alto. (Ej: 576.68 es mayor que 333.71, por tanto Julio 2025 > Julio 2023).
           - '¿en qué mes aumentó la pensión?' → compara 'pension' mes a mes cronológicamente. Muestra mes/año + valor antes/después.
        
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
            'temperature' => 0.9,
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
