import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/secrets.dart';

class AiService {
  static const _endpoint = 'https://api.openai.com/v1/chat/completions';

  Future<String> _getFirebaseContext() async {
    final url =
        '${Secrets.firebaseDatabaseUrl}/ai_context.json?auth=${Secrets.firebaseSecret}';
    final resp = await http.get(Uri.parse(url));
    if (resp.statusCode == 200) return resp.body;
    return '{}';
  }

  Future<String> ask(String question) async {
    final context = await _getFirebaseContext();

    final body = jsonEncode({
      'model': 'gpt-4o',
      'messages': [
        {
          'role': 'system',
          'content': '''Eres Alfred, el asistente contable de la familia para la app Universo Naia.
Historial completo (Métricas precalculadas desde Firebase): $context

REGLA 1: 'total_final' es "Lo que le deposito a Naia". 'transferencia_naia' es "Total / 2".
REGLA 2: Para calcular en qué mes se pagó más, qué mes fue más caro o comparar máximos: ESTÁS OBLIGADO a comprobar matemáticamente TODOS los años y TODOS los meses del periodo que te pidan, y usar la cifra más grande de 'total_final'. NO TE INVENTES RANKINGS NI APROXIMES. (Ej. Julio 2025: 576.68€ es mayor que años anteriores).
REGLA 3: PENSIÓN DE FEBRERO 2025 U OTRO MES: Abre el nodo y extrae el valor 'pension' literalmente. Si pone 238.20, di 238.20. NO aproximes, ni asumas el IPC de otros meses. NUNCA inventes números.
REGLA 4: Responde concisamente aplicando formato Markdown europeo (1.234,56 €).''',
        },
        {
          'role': 'user',
          'content': question,
        }
      ],
      'max_tokens': 1000,
      'temperature': 0.1,
    });

    final resp = await http.post(
      Uri.parse(_endpoint),
      headers: {
        'Authorization': 'Bearer ${Secrets.openAiApiKey}',
        'Content-Type': 'application/json',
      },
      body: body,
    );

    if (resp.statusCode != 200) {
      throw Exception('Error OpenAI (${resp.statusCode}): ${resp.body}');
    }

    final data = jsonDecode(resp.body);
    return data['choices'][0]['message']['content'] as String? ?? 'Sin respuesta.';
  }

  /// Genera un plan de ocio familiar. Usa temperature=0.9 para máxima creatividad y variedad.
  Future<String> askPlan(String prompt) async {
    final body = jsonEncode({
      'model': 'gpt-4o',
      'messages': [
        {
          'role': 'system',
          'content': 'Eres un experto planificador familiar local que conoce Barcelona y sus alrededores perfectamente. MUY IMPORTANTE: Cuando proporciones una ubicación, nunca des la URL cruda. Debes entregarla siempre en formato Markdown así: [📍 Cómo llegar](https://ruta-de-google-maps). Haz que el plan sea elegante y fácil de leer.',
        },
        {
          'role': 'user',
          'content': prompt,
        }
      ],
      'max_tokens': 1200,
      'temperature': 0.9,
    });

    final resp = await http.post(
      Uri.parse(_endpoint),
      headers: {
        'Authorization': 'Bearer ${Secrets.openAiApiKey}',
        'Content-Type': 'application/json',
      },
      body: body,
    );

    if (resp.statusCode != 200) {
      throw Exception('Error OpenAI (${resp.statusCode}): ${resp.body}');
    }

    final data = jsonDecode(resp.body);
    return data['choices'][0]['message']['content'] as String? ?? 'Sin respuesta.';
  }
}
