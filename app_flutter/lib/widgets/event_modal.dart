import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models/calendar_event.dart';

class EventModal extends StatefulWidget {
  final DateTime selectedDay;
  final CalendarEvent? eventToEdit;
  final VoidCallback? onDelete; // Callback opcional para eliminar el evento

  const EventModal({super.key, required this.selectedDay, this.eventToEdit, this.onDelete});

  @override
  State<EventModal> createState() => _EventModalState();
}

class _EventModalState extends State<EventModal> {
  final _formKey = GlobalKey<FormState>();
  
  late TextEditingController _titleController;
  late TextEditingController _descController;
  late TextEditingController _locationController;
  
  late bool _allDay;
  late DateTime _startDate;
  late TimeOfDay _startTime;
  late DateTime _endDate;
  late TimeOfDay _endTime;
  
  late String _colorId;

  // Repetición (extraescolares y visitas)
  bool _repeatWeekly = false;
  final Set<int> _selectedWeekdays = {}; // 1=Lun..7=Dom (DateTime.weekday)
  DateTime? _repeatUntil;
  int _repeatEveryNWeeks = 1; // 1=cada semana, 2=sábados alternos

  // Recordatorios (Alarmas nativas de Google Calendar)
  int? _reminderMinutes;

  // Options matching backend types
  final List<Map<String, dynamic>> _colorOptions = [
    {'id': '1', 'name': 'Evento General', 'color': const Color(0xFF3B82F6)},
    {'id': '3', 'name': 'Cita / Agenda', 'color': const Color(0xFF8B5CF6)},
    {'id': '10', 'name': 'Extraescolar', 'color': const Color(0xFF10B981)},
    {'id': '6', 'name': 'Visita', 'color': const Color(0xFFEAB308)},
    {'id': '11', 'name': 'Importante', 'color': const Color(0xFFF43F5E)},
  ];

  static const _weekdayLabels = {
    1: 'Lun',
    2: 'Mar',
    3: 'Mié',
    4: 'Jue',
    5: 'Vie',
    6: 'Sáb',
    7: 'Dom',
  };

  @override
  void initState() {
    super.initState();
    
    final ev = widget.eventToEdit;
    
    _titleController = TextEditingController(text: ev?.title ?? '');
    _descController = TextEditingController(text: ev?.description ?? '');
    _locationController = TextEditingController(text: ev?.location ?? '');
    
    _allDay = ev?.allDay ?? false;
    _colorId = ev?.colorId ?? '1';

    if (ev != null) {
      _startDate = ev.start;
      _startTime = TimeOfDay.fromDateTime(ev.start);
      _endDate = ev.end;
      _endTime = TimeOfDay.fromDateTime(ev.end);
      _reminderMinutes = ev.reminderMinutes;
    } else {
      _startDate = widget.selectedDay;
      final now = DateTime.now();
      _startTime = TimeOfDay(hour: now.hour + 1, minute: 0); // Next hour by default
      _endDate = widget.selectedDay;
      _endTime = TimeOfDay(hour: now.hour + 2, minute: 0);
      _reminderMinutes = null; // Sin recordatorio por defecto
    }
  }

  @override
  void dispose() {
    _titleController.dispose();
    _descController.dispose();
    _locationController.dispose();
    super.dispose();
  }

  Future<void> _selectDate(BuildContext context, bool isStart) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: isStart ? _startDate : _endDate,
      firstDate: DateTime(2020),
      lastDate: DateTime(2030),
      builder: (context, child) {
        return Theme(
          data: ThemeData.dark().copyWith(
            colorScheme: const ColorScheme.dark(
              primary: Color(0xFF6C63FF),
              onPrimary: Colors.white,
              surface: Color(0xFF1A1A2E),
              onSurface: Colors.white,
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null) {
      setState(() {
        if (isStart) {
          _startDate = picked;
          if (_endDate.isBefore(_startDate)) {
            _endDate = _startDate;
          }
        } else {
          _endDate = picked;
          if (_endDate.isBefore(_startDate)) {
            _startDate = _endDate;
          }
        }
      });
    }
  }

  Future<void> _selectTime(BuildContext context, bool isStart) async {
    final TimeOfDay? picked = await showTimePicker(
      context: context,
      initialTime: isStart ? _startTime : _endTime,
      builder: (context, child) {
        return Theme(
          data: ThemeData.dark().copyWith(
            colorScheme: const ColorScheme.dark(
              primary: Color(0xFF6C63FF),
              onPrimary: Colors.white,
              surface: Color(0xFF1A1A2E),
              onSurface: Colors.white,
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null) {
      setState(() {
        if (isStart) {
          _startTime = picked;
        } else {
          _endTime = picked;
        }
      });
    }
  }

  Future<void> _selectRepeatUntil(BuildContext context) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: _repeatUntil ?? _startDate.add(const Duration(days: 30)),
      firstDate: _startDate,
      lastDate: DateTime(2030),
      helpText: 'Repetir hasta',
      builder: (context, child) {
        return Theme(
          data: ThemeData.dark().copyWith(
            colorScheme: const ColorScheme.dark(
              primary: Color(0xFF10B981),
              onPrimary: Colors.white,
              surface: Color(0xFF1A1A2E),
              onSurface: Colors.white,
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null) {
      setState(() => _repeatUntil = picked);
    }
  }

  /// Cuenta cuántas instancias se generarían con la config actual de repetición
  int _countRecurringInstances() {
    if (_selectedWeekdays.isEmpty || _repeatUntil == null) return 0;
    int count = 0;
    DateTime current = _startDate;
    final until = DateTime(_repeatUntil!.year, _repeatUntil!.month, _repeatUntil!.day, 23, 59, 59);
    final n = _repeatEveryNWeeks < 1 ? 1 : _repeatEveryNWeeks;
    while (!current.isAfter(until)) {
      if (_selectedWeekdays.contains(current.weekday)) {
        final weeksElapsed = current.difference(_startDate).inDays ~/ 7;
        if (weeksElapsed % n == 0) count++;
      }
      current = current.add(const Duration(days: 1));
    }
    return count;
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    
    final finalStart = DateTime(
      _startDate.year, _startDate.month, _startDate.day,
      _allDay ? 0 : _startTime.hour, _allDay ? 0 : _startTime.minute
    );
    
    final finalEnd = DateTime(
      _endDate.year, _endDate.month, _endDate.day,
      _allDay ? 23 : _endTime.hour, _allDay ? 59 : _endTime.minute
    );

    if (!finalEnd.isAfter(finalStart) && !_allDay) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('La hora de fin debe ser posterior a la de inicio.')),
      );
      return;
    }

    // Validación de repetición
    if (_repeatWeekly && (_colorId == '10' || _colorId == '6')) {
      if (_selectedWeekdays.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Selecciona al menos un día de la semana para repetir.')),
        );
        return;
      }
      if (_repeatUntil == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Indica hasta qué fecha repetir el evento.')),
        );
        return;
      }
    }

    final needsRepeat = (_colorId == '10' || _colorId == '6') && _repeatWeekly;
    final newEvent = CalendarEvent(
      id: widget.eventToEdit?.id ?? '',
      title: _titleController.text.trim(),
      description: _descController.text.trim(),
      location: _locationController.text.trim(),
      start: finalStart,
      end: finalEnd,
      allDay: _allDay,
      colorId: _colorId,
      repeatWeekly: needsRepeat,
      repeatWeekdays: needsRepeat ? _selectedWeekdays.toList() : [],
      repeatUntil: needsRepeat ? _repeatUntil : null,
      repeatEveryNWeeks: needsRepeat ? _repeatEveryNWeeks : 1,
      reminderMinutes: _colorId != '10' ? _reminderMinutes : null, // Extraescolares no tienen alarma en GCal
    );
    
    Navigator.of(context).pop(newEvent);
  }

  Future<void> _confirmDelete() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1A1A2E),
        title: const Text('Eliminar evento', style: TextStyle(color: Colors.white)),
        content: const Text('¿Estás seguro de que deseas eliminar este evento?', style: TextStyle(color: Colors.white70)),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false), 
            child: const Text('Cancelar', style: TextStyle(color: Colors.white54))
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true), 
            child: const Text('Eliminar', style: TextStyle(color: Color(0xFFF43F5E)))
          ),
        ],
      ),
    );

    if (confirm == true && widget.onDelete != null) {
      widget.onDelete!();
      if (mounted) Navigator.of(context).pop(); // Cerramos el modal
    }
  }

  @override
  Widget build(BuildContext context) {
    final dateFormat = DateFormat('dd/MM/yyyy');
    final bool isExtraescolar = _colorId == '10';
    final bool isVisita = _colorId == '6';
    final bool hasRepeat = isExtraescolar || isVisita;
    final bool isCreating = widget.eventToEdit == null;
    final Color accentColor = isVisita ? const Color(0xFFEAB308) : const Color(0xFF10B981);
    
    return Container(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
        left: 20, right: 20, top: 24
      ),
      decoration: const BoxDecoration(
        color: Color(0xFF1A1A2E),
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      child: SafeArea(
        child: SingleChildScrollView(
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      widget.eventToEdit == null ? 'Nuevo Evento' : 'Editar Evento',
                      style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold)
                    ),
                    Row(
                      children: [
                        if (!isCreating && widget.onDelete != null)
                          IconButton(
                            icon: const Icon(Icons.delete_outline, color: Color(0xFFF43F5E)),
                            onPressed: _confirmDelete,
                            tooltip: 'Eliminar',
                          ),
                        IconButton(
                          icon: const Icon(Icons.close, color: Colors.white54),
                          onPressed: () => Navigator.of(context).pop(),
                        )
                      ],
                    )
                  ],
                ),
                const SizedBox(height: 16),
                
                // Tipo de Evento / Color
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: _colorOptions.map((opt) {
                    final isSelected = _colorId == opt['id'];
                    return GestureDetector(
                      onTap: () => setState(() {
                        _colorId = opt['id'];
                        // Reset repeat si se cambia de tipo
                        if (opt['id'] != '10' && opt['id'] != '6') {
                          _repeatWeekly = false;
                          _repeatEveryNWeeks = 1;
                        }
                        // Auto-seleccionar sábado y modo alterno al elegir Visita
                        if (opt['id'] == '6') {
                          _selectedWeekdays.clear();
                          _selectedWeekdays.add(6); // Sábado
                          _repeatEveryNWeeks = 2;
                        } else if (opt['id'] == '10') {
                          _repeatEveryNWeeks = 1;
                        }
                      }),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                        decoration: BoxDecoration(
                          color: isSelected ? opt['color'] : (opt['color'] as Color).withOpacity(0.1),
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(
                            color: isSelected ? opt['color'] : Colors.transparent,
                            width: 2
                          )
                        ),
                        child: Text(
                          opt['name'],
                          style: TextStyle(
                            color: isSelected ? Colors.white : opt['color'],
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                            fontSize: 13
                          ),
                        ),
                      ),
                    );
                  }).toList(),
                ),
                const SizedBox(height: 20),

                TextFormField(
                  controller: _titleController,
                  style: const TextStyle(color: Colors.white),
                  decoration: InputDecoration(
                    labelText: 'Título del evento',
                    labelStyle: TextStyle(color: Colors.white.withOpacity(0.5)),
                    filled: true,
                    fillColor: Colors.black.withOpacity(0.2),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                  ),
                  validator: (v) => v!.trim().isEmpty ? 'Requerido' : null,
                ),
                const SizedBox(height: 16),
                
                Row(
                  children: [
                    const Text('Todo el día', style: TextStyle(color: Colors.white70)),
                    const Spacer(),
                    Switch(
                      value: _allDay,
                      activeColor: const Color(0xFF6C63FF),
                      onChanged: (v) => setState(() => _allDay = v),
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Fechas y Horas
                Row(
                  children: [
                    Expanded(
                      flex: 3,
                      child: _buildDateTimeSelector(
                        title: 'Inicio',
                        dateText: dateFormat.format(_startDate),
                        timeText: _startTime.format(context),
                        isAllDay: _allDay,
                        onDateTap: () => _selectDate(context, true),
                        onTimeTap: () => _selectTime(context, true),
                      ),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      flex: 3,
                      child: _buildDateTimeSelector(
                        title: 'Fin',
                        dateText: dateFormat.format(_endDate),
                        timeText: _endTime.format(context),
                        isAllDay: _allDay,
                        onDateTap: () => _selectDate(context, false),
                        onTimeTap: () => _selectTime(context, false),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                
                TextFormField(
                  controller: _locationController,
                  style: const TextStyle(color: Colors.white),
                  decoration: InputDecoration(
                    labelText: 'Ubicación (Opcional)',
                    prefixIcon: const Icon(Icons.location_on_outlined, color: Colors.white54),
                    labelStyle: TextStyle(color: Colors.white.withOpacity(0.5)),
                    filled: true,
                    fillColor: Colors.black.withOpacity(0.2),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                  ),
                ),
                const SizedBox(height: 16),
                
                TextFormField(
                  controller: _descController,
                  style: const TextStyle(color: Colors.white),
                  maxLines: 3,
                  decoration: InputDecoration(
                    labelText: 'Descripción (Opcional)',
                    labelStyle: TextStyle(color: Colors.white.withOpacity(0.5)),
                    filled: true,
                    fillColor: Colors.black.withOpacity(0.2),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                  ),
                ),
                const SizedBox(height: 20),

                // ── Sección Recordatorio (Alarma nativa GCal) ──
                if (_colorId != '10') ...[
                  DropdownButtonFormField<int?>(
                    value: _reminderMinutes,
                    dropdownColor: const Color(0xFF1A1A2E),
                    style: const TextStyle(color: Colors.white),
                    decoration: InputDecoration(
                      labelText: 'Avisarme (Notificación en el móvil)',
                      labelStyle: TextStyle(color: Colors.white.withOpacity(0.5)),
                      prefixIcon: const Icon(Icons.notifications_active_outlined, color: Colors.white54),
                      filled: true,
                      fillColor: Colors.black.withOpacity(0.2),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                    ),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('Sin alarma')),
                      DropdownMenuItem(value: 0, child: Text('A la hora exacta')),
                      DropdownMenuItem(value: 10, child: Text('10 minutos antes')),
                      DropdownMenuItem(value: 30, child: Text('30 minutos antes')),
                      DropdownMenuItem(value: 60, child: Text('1 hora antes')),
                      DropdownMenuItem(value: 120, child: Text('2 horas antes')),
                      DropdownMenuItem(value: 1440, child: Text('1 día antes')),
                    ],
                    onChanged: (int? value) {
                      setState(() {
                        _reminderMinutes = value;
                      });
                    },
                  ),
                  const SizedBox(height: 20),
                ],

                // ── Sección Repetir (Extraescolar y Visita al crear) ──
                if (hasRepeat && isCreating) ...[
                  Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: accentColor.withOpacity(0.08),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: accentColor.withOpacity(0.25)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // Toggle principal
                        Row(
                          children: [
                            Icon(Icons.repeat_rounded, color: accentColor, size: 18),
                            const SizedBox(width: 8),
                            const Text(
                              'Repetir',
                              style: TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 14),
                            ),
                            const Spacer(),
                            Switch(
                              value: _repeatWeekly,
                              activeColor: accentColor,
                              onChanged: (v) => setState(() => _repeatWeekly = v),
                            ),
                          ],
                        ),

                        // Opciones (visibles solo si está activo)
                        AnimatedCrossFade(
                          duration: const Duration(milliseconds: 250),
                          crossFadeState: _repeatWeekly ? CrossFadeState.showSecond : CrossFadeState.showFirst,
                          firstChild: const SizedBox.shrink(),
                          secondChild: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              // ── Selector de frecuencia (solo Visita) ──
                              if (isVisita) ...[
                                const Text(
                                  'FRECUENCIA:',
                                  style: TextStyle(color: Colors.white54, fontSize: 11, letterSpacing: 0.8),
                                ),
                                const SizedBox(height: 8),
                                Row(
                                  children: [
                                    GestureDetector(
                                      onTap: () => setState(() {
                                        if (_repeatEveryNWeeks > 1) _repeatEveryNWeeks--;
                                      }),
                                      child: Container(
                                        width: 36, height: 36,
                                        decoration: BoxDecoration(
                                          color: Colors.white.withOpacity(0.07),
                                          borderRadius: BorderRadius.circular(8),
                                        ),
                                        child: Icon(Icons.remove, color: accentColor, size: 18),
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    Text(
                                      _repeatEveryNWeeks == 1 ? 'Cada semana' : 'Cada $_repeatEveryNWeeks semanas',
                                      style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w600),
                                    ),
                                    const SizedBox(width: 12),
                                    GestureDetector(
                                      onTap: () => setState(() {
                                        if (_repeatEveryNWeeks < 8) _repeatEveryNWeeks++;
                                      }),
                                      child: Container(
                                        width: 36, height: 36,
                                        decoration: BoxDecoration(
                                          color: Colors.white.withOpacity(0.07),
                                          borderRadius: BorderRadius.circular(8),
                                        ),
                                        child: Icon(Icons.add, color: accentColor, size: 18),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 14),
                              ],

                              // ── Día(s) de la semana ──
                              Text(
                                isExtraescolar ? 'DÍAS DE LA SEMANA:' : 'DÍA DE LA SEMANA:',
                                style: const TextStyle(color: Colors.white54, fontSize: 11, letterSpacing: 0.8),
                              ),
                              const SizedBox(height: 8),
                              Wrap(
                                spacing: 6,
                                runSpacing: 6,
                                children: _weekdayLabels.entries.map((entry) {
                                  final isSelected = _selectedWeekdays.contains(entry.key);
                                  return GestureDetector(
                                    onTap: () => setState(() {
                                      if (isExtraescolar) {
                                        // Extraescolar: multiselección
                                        if (isSelected) {
                                          _selectedWeekdays.remove(entry.key);
                                        } else {
                                          _selectedWeekdays.add(entry.key);
                                        }
                                      } else {
                                        // Visita: un solo día
                                        _selectedWeekdays
                                          ..clear()
                                          ..add(entry.key);
                                      }
                                    }),
                                    child: AnimatedContainer(
                                      duration: const Duration(milliseconds: 150),
                                      width: 40,
                                      height: 36,
                                      decoration: BoxDecoration(
                                        color: isSelected ? accentColor : Colors.white.withOpacity(0.07),
                                        borderRadius: BorderRadius.circular(8),
                                        border: Border.all(
                                          color: isSelected ? accentColor : Colors.white.withOpacity(0.15),
                                        ),
                                      ),
                                      child: Center(
                                        child: Text(
                                          entry.value,
                                          style: TextStyle(
                                            color: isSelected ? Colors.white : Colors.white60,
                                            fontSize: 11,
                                            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                                          ),
                                        ),
                                      ),
                                    ),
                                  );
                                }).toList(),
                              ),
                              const SizedBox(height: 14),

                              // Selector "Repetir hasta"
                              const Text(
                                'REPETIR HASTA:',
                                style: TextStyle(color: Colors.white54, fontSize: 11, letterSpacing: 0.8),
                              ),
                              const SizedBox(height: 8),
                              GestureDetector(
                                onTap: () => _selectRepeatUntil(context),
                                child: Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                                  decoration: BoxDecoration(
                                    color: Colors.black.withOpacity(0.2),
                                    borderRadius: BorderRadius.circular(10),
                                    border: Border.all(
                                      color: _repeatUntil != null
                                          ? accentColor.withOpacity(0.5)
                                          : Colors.white.withOpacity(0.1),
                                    ),
                                  ),
                                  child: Row(
                                    children: [
                                      Icon(
                                        Icons.calendar_today,
                                        size: 16,
                                        color: _repeatUntil != null ? accentColor : Colors.white54,
                                      ),
                                      const SizedBox(width: 10),
                                      Text(
                                        _repeatUntil != null
                                            ? DateFormat('dd/MM/yyyy').format(_repeatUntil!)
                                            : 'Seleccionar fecha límite',
                                        style: TextStyle(
                                          color: _repeatUntil != null ? Colors.white : Colors.white54,
                                          fontSize: 14,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ),

                              // Preview del número de instancias
                              if ((isVisita || _selectedWeekdays.isNotEmpty) && _repeatUntil != null) ...[
                                const SizedBox(height: 10),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                  decoration: BoxDecoration(
                                    color: accentColor.withOpacity(0.15),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: Row(
                                    children: [
                                      Icon(Icons.info_outline, size: 14, color: accentColor),
                                      const SizedBox(width: 6),
                                      Text(
                                        'Se crearán ${_countRecurringInstances()} eventos',
                                        style: TextStyle(
                                          color: accentColor,
                                          fontSize: 13,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                ],

                ElevatedButton(
                  onPressed: _submit,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF6C63FF),
                    padding: const EdgeInsets.symmetric(vertical: 16),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  child: Text(
                    widget.eventToEdit == null ? 'Guardar Evento' : 'Actualizar Evento',
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.white)
                  ),
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildDateTimeSelector({
    required String title,
    required String dateText,
    required String timeText,
    required bool isAllDay,
    required VoidCallback onDateTap,
    required VoidCallback onTimeTap,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 13)),
        const SizedBox(height: 8),
        GestureDetector(
          onTap: onDateTap,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
            decoration: BoxDecoration(
              color: Colors.black.withOpacity(0.2),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(
              children: [
                const Icon(Icons.calendar_today, size: 16, color: Colors.white54),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    dateText, 
                    style: const TextStyle(color: Colors.white, fontSize: 14),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
        ),
        if (!isAllDay) ...[
          const SizedBox(height: 8),
          GestureDetector(
            onTap: onTimeTap,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
              decoration: BoxDecoration(
                color: Colors.black.withOpacity(0.2),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                children: [
                  const Icon(Icons.access_time, size: 16, color: Colors.white54),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      timeText, 
                      style: const TextStyle(color: Colors.white, fontSize: 14),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ]
      ],
    );
  }
}
