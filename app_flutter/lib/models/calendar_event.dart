import 'dart:convert';

class CalendarEvent {
  final String id;
  final String title;
  final String description;
  final String location;
  final DateTime start;
  final DateTime end;
  final bool allDay;
  final String? colorId;

  // Campos para repetición (extraescolares y visitas)
  final bool repeatWeekly;
  final List<int> repeatWeekdays; // 1=Lun, 2=Mar, 3=Mié, 4=Jue, 5=Vie, 6=Sáb, 7=Dom (DateTime.weekday)
  final DateTime? repeatUntil;
  final int repeatEveryNWeeks; // 1=cada semana, 2=cada 2 semanas (sábados alternos), etc.
  
  // Alarma/Recordatorio nativo
  final int? reminderMinutes;

  CalendarEvent({
    required this.id,
    required this.title,
    this.description = '',
    this.location = '',
    required this.start,
    required this.end,
    this.allDay = false,
    this.colorId,
    this.repeatWeekly = false,
    this.repeatWeekdays = const [],
    this.repeatUntil,
    this.repeatEveryNWeeks = 1,
    this.reminderMinutes,
  });

  factory CalendarEvent.fromJson(Map<String, dynamic> json) {
    return CalendarEvent(
      id: json['id'] as String? ?? '',
      title: json['title'] as String? ?? '(Sin título)',
      description: json['description'] as String? ?? '',
      location: json['location'] as String? ?? '',
      start: DateTime.tryParse(json['start']?.toString() ?? '')?.toLocal() ?? DateTime.now(),
      end: DateTime.tryParse(json['end']?.toString() ?? '')?.toLocal() ?? DateTime.now(),
      allDay: json['allDay'] as bool? ?? false,
      colorId: json['color']?.toString() ?? json['colorId']?.toString(), // 'color' from API, 'colorId' for creation/update
      reminderMinutes: json['reminderMinutes'] as int?,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'title': title,
      'description': description,
      'location': location,
      'start': start.toIso8601String(),
      'end': end.toIso8601String(),
      'allDay': allDay,
      'colorId': colorId,
      'reminderMinutes': reminderMinutes,
    };
  }

  /// Genera todas las instancias recurrentes basándose en repeatWeekdays, repeatUntil y repeatEveryNWeeks.
  List<CalendarEvent> generateRecurringInstances() {
    if (!repeatWeekly || repeatWeekdays.isEmpty || repeatUntil == null) return [this];

    final List<CalendarEvent> instances = [];
    final duration = end.difference(start);
    DateTime current = start;
    final until = DateTime(repeatUntil!.year, repeatUntil!.month, repeatUntil!.day, 23, 59, 59);
    final n = repeatEveryNWeeks < 1 ? 1 : repeatEveryNWeeks;

    while (!current.isAfter(until)) {
      if (repeatWeekdays.contains(current.weekday)) {
        // Solo añadir si la semana relativa al inicio es múltiplo de N
        final weeksElapsed = current.difference(start).inDays ~/ 7;
        if (weeksElapsed % n == 0) {
          instances.add(CalendarEvent(
            id: '',
            title: title,
            description: description,
            location: location,
            start: current,
            // Para mantener la misma hora local a pesar de cambios DST (horario de verano),
            // sumamos los atributos de fecha/tiempo al inicio original en vez de usar Duration en start.
            // Esto asegura que si start es 18:00, siempre será 18:00.
            end: DateTime(
              current.year, current.month, current.day,
              end.hour, end.minute, end.second
            ),
            allDay: allDay,
            colorId: colorId,
            reminderMinutes: reminderMinutes,
          ));
        }
      }
      // Sumar 1 día preservando el DST (wall-clock time). 
      // Duration(days: 1) suma 24h cronológicas que desplaza el reloj en días de cambio DST.
      current = DateTime(current.year, current.month, current.day + 1, current.hour, current.minute, current.second);
    }

    return instances.isEmpty ? [this] : instances;
  }

  /// Create a copy but overriding selected fields. Use this when mutating.
  CalendarEvent copyWith({
    String? id,
    String? title,
    String? description,
    String? location,
    DateTime? start,
    DateTime? end,
    bool? allDay,
    String? colorId,
    bool? repeatWeekly,
    List<int>? repeatWeekdays,
    DateTime? repeatUntil,
    int? repeatEveryNWeeks,
    int? reminderMinutes,
  }) {
    return CalendarEvent(
      id: id ?? this.id,
      title: title ?? this.title,
      description: description ?? this.description,
      location: location ?? this.location,
      start: start ?? this.start,
      end: end ?? this.end,
      allDay: allDay ?? this.allDay,
      colorId: colorId ?? this.colorId,
      repeatWeekly: repeatWeekly ?? this.repeatWeekly,
      repeatWeekdays: repeatWeekdays ?? this.repeatWeekdays,
      repeatUntil: repeatUntil ?? this.repeatUntil,
      repeatEveryNWeeks: repeatEveryNWeeks ?? this.repeatEveryNWeeks,
      reminderMinutes: reminderMinutes ?? this.reminderMinutes,
    );
  }
}

