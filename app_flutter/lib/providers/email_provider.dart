import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../config/secrets.dart';
import '../models/email.dart';

class EmailProvider extends ChangeNotifier {
  List<Email> _emails = [];
  bool _isLoading = false;
  String? _error;

  List<Email> get emails => _emails;
  bool get isLoading => _isLoading;
  String? get error => _error;

  Future<void> loadEmails() async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final url = Uri.parse(
          '${Secrets.firebaseDatabaseUrl}/emails.json?auth=${Secrets.firebaseSecret}');
      final response = await http.get(url);

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data != null && data is Map<String, dynamic>) {
          final List<Email> loaded = [];
          data.forEach((key, value) {
            var map = Map<String, dynamic>.from(value);
            map['fbKey'] = key;
            loaded.add(Email.fromJson(map));
          });
          // Sort locally and limit to 50
          loaded.sort((a, b) => b.timestamp.compareTo(a.timestamp));
          _emails = loaded.take(50).toList();
        } else {
          _emails = [];
        }
      } else {
        _error = 'Error al cargar correos: ${response.statusCode}';
      }
    } catch (e) {
      _error = 'Error de conexión: $e';
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<bool> sendEmail({
    required String type, // 'new', 'reply', 'forward'
    required String subject,
    required String body,
    String to = '',
    String replyToId = '',
    List<String> filePaths = const [],
  }) async {
    try {
      final baseUrl = Secrets.backendUrl.endsWith('/')
          ? Secrets.backendUrl.substring(0, Secrets.backendUrl.length - 1)
          : Secrets.backendUrl;
      final url = Uri.parse('$baseUrl/?action=send_email');
      
      final request = http.MultipartRequest('POST', url);
      request.headers['X-API-Key'] = Secrets.webhookSecret;
      request.fields['type'] = type;
      request.fields['to'] = to;
      request.fields['reply_to_id'] = replyToId;
      request.fields['subject'] = subject;
      request.fields['body'] = body;

      for (var path in filePaths) {
         request.files.add(await http.MultipartFile.fromPath('attachments[]', path));
      }

      final streamedResponse = await request.send();
      final response = await http.Response.fromStream(streamedResponse);
      
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        return data['success'] == true;
      }
      return false;
    } catch (e) {
      debugPrint('Error enviando correo: $e');
      return false;
    }
  }

  Future<void> markAsRead(Email email) async {
    if (email.isRead || email.fbKey.isEmpty) return;

    // Actualizar localmente de inmediato para mejorar la respuesta UI
    final index = _emails.indexWhere((e) => e.fbKey == email.fbKey);
    if (index != -1) {
      final old = _emails[index];
      _emails[index] = Email(
        fbKey: old.fbKey,
        id: old.id,
        subject: old.subject,
        from: old.from,
        to: old.to,
        direction: old.direction,
        date: old.date,
        timestamp: old.timestamp,
        body: old.body,
        attachments: old.attachments,
        isRead: true, // Marcar como leído
      );
      notifyListeners();
    }

    try {
      final url = Uri.parse(
          '${Secrets.firebaseDatabaseUrl}/emails/${email.fbKey}.json?auth=${Secrets.firebaseSecret}');
      await http.patch(
        url,
        body: json.encode({'isRead': true}),
      );
    } catch (e) {
      debugPrint('Error marcando correo como leído: $e');
    }
  }
}
