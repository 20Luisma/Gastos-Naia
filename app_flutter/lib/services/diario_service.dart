import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../config/secrets.dart';
import '../models/comunicado.dart';

class DiarioService {
  static const _timeout = Duration(seconds: 20);

  Map<String, String> _headers() => {
        'Content-Type': 'application/json',
        'X-API-Key': Secrets.webhookSecret,
      };

  /// Fetch all comunicados from Firebase via PHP
  Future<List<Comunicado>> getComunicados() async {
    final url = Uri.parse(
        '${Secrets.backendUrl}/?action=getComunicados&secret=${Secrets.webhookSecret}');

    try {
      final resp = await http.get(url, headers: _headers()).timeout(_timeout);
      if (resp.statusCode != 200) {
         throw Exception('HTTP ${resp.statusCode}: ${resp.body}');
      }
      
      // Imprimir los primeros chars para saber si el backend está enviando JSON u otra cosa
      debugPrint('Diario response snippet: ${resp.body.length > 200 ? resp.body.substring(0, 200) : resp.body}');

      dynamic data;
      try {
        data = jsonDecode(resp.body);
      } catch (e) {
        throw Exception('JSON Malformado: $e -> Body: ${resp.body}');
      }

      final List<Comunicado> items = [];

      if (data is Map) {
        data.forEach((key, value) {
          if (value is Map<String, dynamic>) {
            value['id'] = key;
            items.add(Comunicado.fromJson(value));
          }
        });
      } else if (data is List) {
        items.addAll(data.map((e) => Comunicado.fromJson(e as Map<String, dynamic>)));
      }

      // Sort descending by date, then created_at
      items.sort((a, b) {
        final dateCmp = b.date.compareTo(a.date);
        if (dateCmp != 0) return dateCmp;
        if (a.createdAt != null && b.createdAt != null) {
          return b.createdAt!.compareTo(a.createdAt!);
        }
        return 0;
      });

      return items;
    } catch (e) {
      debugPrint('Excepción en getComunicados: $e');
      throw Exception(e.toString());
    }
  }

  /// Create or update a comunicado (PHP sends to Firebase and Telegram)
  Future<String> saveComunicado(Comunicado comunicado) async {
    final url = Uri.parse(
        '${Secrets.backendUrl}/?action=saveComunicado&secret=${Secrets.webhookSecret}');

    final body = jsonEncode(comunicado.toJson());

    final resp = await http
        .post(url, headers: _headers(), body: body)
        .timeout(_timeout);

    if (resp.statusCode != 200) {
      throw Exception('Error guardando anotación: ${resp.body}');
    }

    final data = jsonDecode(resp.body);
    return data['id']?.toString() ?? comunicado.id;
  }

  /// Uploads a file attachment for a comunicado
  Future<String> uploadAttachment(List<int> bytes, String filename) async {
    final url = Uri.parse(
        '${Secrets.backendUrl}/?action=uploadComunicado&secret=${Secrets.webhookSecret}');

    final request = http.MultipartRequest('POST', url)
      ..files.add(http.MultipartFile.fromBytes('file', bytes, filename: filename));

    final streamedResponse = await request.send().timeout(_timeout);
    final response = await http.Response.fromStream(streamedResponse);

    if (response.statusCode != 200) {
      throw Exception('Error subiendo archivo: ${response.body}');
    }

    final data = jsonDecode(response.body);
    if (data['success'] != true || data['url'] == null) {
      throw Exception('El servidor no devolvió una URL válida: ${response.body}');
    }

    return data['url'];
  }

  /// Delete a comunicado
  Future<bool> deleteComunicado(String id) async {
    final url = Uri.parse(
        '${Secrets.backendUrl}/?action=deleteComunicado&secret=${Secrets.webhookSecret}');

    final body = jsonEncode({'id': id});

    final resp = await http
        .post(url, headers: _headers(), body: body)
        .timeout(_timeout);

    if (resp.statusCode != 200) {
      throw Exception('Error eliminando anotación: ${resp.body}');
    }

    final data = jsonDecode(resp.body);
    return data['success'] == true;
  }
}
