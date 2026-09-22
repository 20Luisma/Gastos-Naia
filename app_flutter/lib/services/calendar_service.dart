import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../config/secrets.dart';
import '../models/calendar_event.dart';

class CalendarService {
  static const _timeout = Duration(seconds: 15);

  Map<String, String> _headers() => {
        'Content-Type': 'application/json',
        'X-API-Key': Secrets.webhookSecret,
      };

  /// Obtiene los eventos combinados de Google Calendar y Extraescolares para un mes.
  Future<List<CalendarEvent>> getEvents(int year, int month) async {
    final gCalUrl = Uri.parse(
        '${Secrets.backendUrl}/?action=calendar_events&year=$year&month=$month&secret=${Secrets.webhookSecret}');
        
    final extraUrl = Uri.parse(
        '${Secrets.backendUrl}/?action=extraescolar_list&secret=${Secrets.webhookSecret}');

    List<CalendarEvent> allEvents = [];

    // Lanzamos ambas peticiones en paralelo, pero las manejamos de forma independiente
    // para que un fallo en Google Calendar no impida mostrar los extraescolares locales.
    final results = await Future.wait([
      http.get(gCalUrl, headers: _headers()).timeout(_timeout).then<dynamic>((r) => r).catchError((e) {
        debugPrint('GCal fetch failed: $e');
        return null;
      }),
      http.get(extraUrl, headers: _headers()).timeout(_timeout).then<dynamic>((r) => r).catchError((e) {
        debugPrint('Extraescolares fetch failed: $e');
        return null;
      }),
    ]);

    final gCalResp = results[0];
    final extraResp = results[1];

    // Parsear Google Calendar
    if (gCalResp != null && gCalResp.statusCode == 200) {
      try {
        final data = jsonDecode(gCalResp.body);
        final eventsList = (data['events'] as List?) ?? [];
        allEvents.addAll(eventsList.map((e) => CalendarEvent.fromJson(e)));
      } catch (e) {
        debugPrint('Error parsing GCal events: $e');
      }
    }

    // Parsear Extraescolares
    if (extraResp != null && extraResp.statusCode == 200) {
      try {
        final data = jsonDecode(extraResp.body);
        final extraItems = (data['items'] as List?) ?? [];
        debugPrint('Extraescolares raw count: ${extraItems.length}');
        
        final filteredExtra = extraItems.where((item) {
           if (item is! Map) return false;
           final startDate = DateTime.tryParse(item['start']?.toString() ?? '');
           if (startDate == null) return false;
           return startDate.year == year && startDate.month == month;
        }).map((e) => CalendarEvent.fromJson(Map<String, dynamic>.from(e as Map)));
        
        debugPrint('Extraescolares filtered for $year-$month count: ${filteredExtra.length}');
        allEvents.addAll(filteredExtra);
      } catch (e) {
        debugPrint('Error parsing extraescolar events: $e');
      }
    }

    return allEvents;
  }


  /// Crea múltiples eventos en lote
  /// - Extraescolares (colorId '10') → extraescolar_save_batch (almacenamiento local JSON)
  /// - Visitas (colorId '6') → calendar_create_batch (Google Calendar, color amarillo)
  Future<void> createEventBatch(List<CalendarEvent> events) async {
    // Separar por tipo
    final extraEvents = events.where((e) => e.colorId == '10').toList();
    final visitaEvents = events.where((e) => e.colorId == '6').toList();

    // Extraescolares → local JSON
    if (extraEvents.isNotEmpty) {
      final url = Uri.parse('${Secrets.backendUrl}/?action=extraescolar_save_batch&secret=${Secrets.webhookSecret}');
      final body = jsonEncode(extraEvents.map((e) => {
        'title': e.title,
        'description': e.description,
        'location': e.location,
        'allDay': e.allDay,
        'start': e.start.toIso8601String(),
        'end': e.end.toIso8601String(),
        'color': '10',
        'colorId': '10',
        'isLocal': true,
        'type': 'extraescolar',
      }).toList());
      final resp = await http.post(url, headers: _headers(), body: body).timeout(_timeout);
      if (resp.statusCode != 200) {
        throw Exception('Error creando extraescolares en lote: ${resp.body}');
      }
    }

    // Visitas → Google Calendar (colorId '6' = amarillo)
    if (visitaEvents.isNotEmpty) {
      final url = Uri.parse('${Secrets.backendUrl}/?action=calendar_create_batch&secret=${Secrets.webhookSecret}');
      final body = jsonEncode(visitaEvents.map((e) => {
        'title': e.title,
        'description': e.description,
        'location': e.location,           // restaurada la ubicación (antes era '')
        'allDay': e.allDay,
        // toLocal() garantiza que la hora se envía sin 'Z' (UTC),
        // así PHP la interpreta como Europe/Madrid y el cambio horario no la desplaza
        'start': e.start.toLocal().toIso8601String(),
        'end': e.end.toLocal().toIso8601String(),
        'colorId': '6',
        'reminderMinutes': e.reminderMinutes,
      }).toList());
      final resp = await http.post(url, headers: _headers(), body: body).timeout(_timeout);
      if (resp.statusCode != 200) {
        throw Exception('Error creando visitas en lote: ${resp.body}');
      }
    }
  }


  /// Crea un nuevo evento en Google Calendar o Local Extraescolar
  Future<CalendarEvent> createEvent(CalendarEvent event) async {
    final isExtraescolar = event.colorId == '10';
    // Extraescolares → endpoint local; el resto (Visitas, Citas, etc.) → Google Calendar
    final action = isExtraescolar ? 'extraescolar_save' : 'calendar_create';
    
    final url = Uri.parse('${Secrets.backendUrl}/?action=$action&secret=${Secrets.webhookSecret}');

    final body = jsonEncode(event.toJson());

    final resp = await http
        .post(url, headers: _headers(), body: body)
        .timeout(_timeout);

    if (resp.statusCode != 200) {
      throw Exception('Error creando evento: ${resp.body}');
    }

    // Para la creación, el PHP devuelve success y un `event` o un `id` (extraescolar). 
    // Por simplicidad, recargamos la lista desde el servidor en la UI.
    return event;
  }

  /// Actualiza un evento existente en Google Calendar o Extraescolares.
  Future<CalendarEvent> updateEvent(CalendarEvent event) async {
    if (event.id.isEmpty) {
      throw Exception('El evento necesita un ID válido para ser actualizado.');
    }

    final isLocal = event.id.startsWith('local-');
    final action = isLocal ? 'extraescolar_save' : 'calendar_update';

    final url = Uri.parse('${Secrets.backendUrl}/?action=$action&secret=${Secrets.webhookSecret}');

    // En calendar_update, el backend PHP espera eventId aparte del payload. 
    // En extraescolar_save, si le pasamos el id lo machaca.
    final payload = event.toJson();
    if (!isLocal) {
       payload['eventId'] = event.id; // Renombrar para Calendar
    }

    final body = jsonEncode(payload);

    final resp = await http
        .post(url, headers: _headers(), body: body)
        .timeout(_timeout);

    if (resp.statusCode != 200) {
      throw Exception('Error actualizando evento: ${resp.body}');
    }

    return event;
  }

  /// Elimina un evento en Google Calendar o Extraescolares
  Future<bool> deleteEvent(String eventId) async {
    final isLocal = eventId.startsWith('local-');
    final action = isLocal ? 'extraescolar_delete' : 'calendar_delete';
    
    final url = Uri.parse('${Secrets.backendUrl}/?action=$action&secret=${Secrets.webhookSecret}');

    final body = jsonEncode({
      isLocal ? 'id' : 'eventId': eventId,
    });

    final resp = await http
        .post(url, headers: _headers(), body: body)
        .timeout(_timeout);

    if (resp.statusCode != 200) {
      throw Exception('Error eliminando evento: ${resp.body}');
    }

    final data = jsonDecode(resp.body);
    return data['success'] == true;
  }
}
