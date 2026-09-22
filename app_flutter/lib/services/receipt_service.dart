import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import '../config/secrets.dart';
import '../config/service_account.dart';
import 'package:googleapis_auth/auth_io.dart';

class ReceiptService {
  String? _accessToken;
  DateTime? _tokenExpiry;

  static const _timeout = Duration(seconds: 20);

  Future<String> _getAccessToken() async {
    if (_accessToken != null &&
        _tokenExpiry != null &&
        DateTime.now().isBefore(_tokenExpiry!)) {
      return _accessToken!;
    }
    final accountCredentials = ServiceAccountCredentials.fromJson(ServiceAccount.json);
    final scopes = [
      'https://www.googleapis.com/auth/spreadsheets',
      'https://www.googleapis.com/auth/drive'
    ];
    final client = await clientViaServiceAccount(accountCredentials, scopes);
    
    _accessToken = client.credentials.accessToken.data;
    _tokenExpiry = client.credentials.accessToken.expiry.subtract(const Duration(seconds: 60));
    
    client.close();
    return _accessToken!;
  }

  /// OCR: scan receipt image using OpenAI GPT-4o Vision
  Future<Map<String, dynamic>> scanReceipt(String imagePath) async {
    final imageFile = File(imagePath);
    final imageBytes = await imageFile.readAsBytes();
    final base64Image = base64Encode(imageBytes);

    final body = jsonEncode({
      'model': 'gpt-4o',
      'messages': [
        {
          'role': 'user',
          'content': [
            {
              'type': 'text',
              'text':
                  '''Analiza este recibo/factura y extrae la información.
Responde ÚNICAMENTE con un JSON válido con este formato exacto:
{"date": "DD/MM/YYYY", "description": "Nombre del comercio y concepto", "amount": 0.00}
- date: fecha del ticket en formato DD/MM/YYYY
- description: nombre del comercio + concepto principal (máx 50 chars)
- amount: TOTAL a pagar en euros (número decimal sin símbolo €)
Si no puedes leer algún campo, usa "" para texto o 0 para amount.'''
            },
            {
              'type': 'image_url',
              'image_url': {
                'url': 'data:image/jpeg;base64,$base64Image',
                'detail': 'low',
              }
            }
          ]
        }
      ],
      'max_tokens': 200,
    });

    final resp = await http.post(
      Uri.parse('https://api.openai.com/v1/chat/completions'),
      headers: {
        'Authorization': 'Bearer ${Secrets.openAiApiKey}',
        'Content-Type': 'application/json',
      },
      body: body,
    ).timeout(const Duration(seconds: 30)); // OpenAI puede tardar más

    if (resp.statusCode != 200) {
      return {'error': 'Error OpenAI Vision (${resp.statusCode})'};
    }

    final data = jsonDecode(resp.body);
    final text = data['choices'][0]['message']['content'] as String;

    final jsonMatch = RegExp(r'\{[\s\S]*\}').firstMatch(text);
    if (jsonMatch != null) {
      try {
        final Map<String, dynamic> result = jsonDecode(jsonMatch.group(0)!);
        // Force date to DD/MM/YYYY just in case the AI returns YYYY-MM-DD
        if (result.containsKey('date') && result['date'] != null) {
          String rawDate = result['date'].toString();
          if (RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(rawDate)) {
            final parts = rawDate.split('-');
            result['date'] = '${parts[2]}/${parts[1]}/${parts[0]}';
          } else if (RegExp(r'^\d{4}/\d{2}/\d{2}$').hasMatch(rawDate)) {
            final parts = rawDate.split('/');
            result['date'] = '${parts[2]}/${parts[1]}/${parts[0]}';
          }
        }
        return result;
      } catch (_) {}
    }
    return {'error': 'No se pudo extraer la información del recibo.'};
  }

  /// Upload receipt file to Google Drive
  Future<String> uploadToDrive(
      String filePath, int year, int month) async {
    final folderId = Secrets.driveFolders[year];
    if (folderId == null) {
      throw Exception('Carpeta Drive no configurada para $year');
    }

    final token = await _getAccessToken();
    final file = File(filePath);
    final fileName = file.uri.pathSegments.last;
    final bytes = await file.readAsBytes();

    final boundary =
        'boundary_${DateTime.now().millisecondsSinceEpoch}';
    final metadata =
        jsonEncode({'name': fileName, 'parents': [folderId]});

    final header = '--$boundary\r\n'
        'Content-Type: application/json; charset=UTF-8\r\n\r\n'
        '$metadata\r\n'
        '--$boundary\r\n'
        'Content-Type: image/jpeg\r\n\r\n';

    final request = http.Request(
      'POST',
      Uri.parse(
          'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true'),
    );
    request.headers['Authorization'] = 'Bearer $token';
    request.headers['Content-Type'] =
        'multipart/related; boundary=$boundary';
    request.bodyBytes = [
      ...utf8.encode(header),
      ...bytes,
      ...utf8.encode('\r\n--$boundary--'),
    ];

    final streamed = await request.send();
    final response = await http.Response.fromStream(streamed);
    if (response.statusCode != 200) {
      throw Exception('Error subiendo a Drive: ${response.body}');
    }

    final data = jsonDecode(response.body);
    return data['id'] ?? '';
  }
}
