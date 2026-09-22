import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:table_calendar/table_calendar.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:http/http.dart' as http;
import '../services/calendar_service.dart';
import '../services/ai_service.dart';
import '../models/calendar_event.dart';
import '../widgets/event_modal.dart';
import '../config/secrets.dart';

class CalendarScreen extends StatefulWidget {
  const CalendarScreen({super.key});

  @override
  State<CalendarScreen> createState() => CalendarScreenState();
}

class CalendarScreenState extends State<CalendarScreen> {
  final CalendarService _calendarService = CalendarService();
  
  CalendarFormat _calendarFormat = CalendarFormat.month;
  DateTime _focusedDay = DateTime.now();
  DateTime? _selectedDay;
  
  Map<DateTime, List<CalendarEvent>> _eventsMap = {};
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _selectedDay = _focusedDay;
    _loadEventsForMonth(_focusedDay);
  }

  void reload() {
    _loadEventsForMonth(_focusedDay);
  }

  Future<void> _loadEventsForMonth(DateTime month) async {
    setState(() => _isLoading = true);
    try {
      final eventsList = await _calendarService.getEvents(month.year, month.month);
      
      final Map<DateTime, List<CalendarEvent>> newEvents = {};
      for (final event in eventsList) {
        // Normalizamos la fecha eliminando hora/minuto/segundo para agrupar por día
        final normalizedDay = DateTime(event.start.year, event.start.month, event.start.day);
        if (newEvents[normalizedDay] == null) {
          newEvents[normalizedDay] = [];
        }
        newEvents[normalizedDay]!.add(event);
      }
      
      setState(() => _eventsMap = newEvents);
    } catch (e) {
      debugPrint('Error loading events: $e');
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error cargando calendario', style: const TextStyle(color: Colors.white))),
        );
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  List<CalendarEvent> _getEventsForDay(DateTime day) {
    final normalizedDay = DateTime(day.year, day.month, day.day);
    return _eventsMap[normalizedDay] ?? [];
  }

  void _onDaySelected(DateTime selectedDay, DateTime focusedDay) {
    if (!isSameDay(_selectedDay, selectedDay)) {
      setState(() {
        _selectedDay = selectedDay;
        _focusedDay = focusedDay;
      });
    }
  }

  void _onPageChanged(DateTime focusedDay) {
    _focusedDay = focusedDay;
    _loadEventsForMonth(focusedDay);
  }

  void _showEventModal([CalendarEvent? event]) async {
    final result = await showModalBottomSheet<CalendarEvent>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => EventModal(
        selectedDay: event?.start ?? _selectedDay ?? _focusedDay,
        eventToEdit: event,
        // Callback para borrar desde dentro del modal
        onDelete: () async {
          if (event == null) return;
          setState(() => _isLoading = true);
          try {
            await _calendarService.deleteEvent(event.id);
            reload();
          } catch (e) {
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Error eliminando evento: $e')),
              );
            }
            setState(() => _isLoading = false);
          }
        },
      ),
    );

    if (result != null) {
      _saveEvent(result);
    }
  }

  Future<void> _saveEvent(CalendarEvent event) async {
    setState(() => _isLoading = true);
    try {
      if (event.id.isEmpty) {
        // Verificar si es un extraescolar con repetición semanal
        if (event.repeatWeekly && (event.colorId == '10' || event.colorId == '6')) {
          final instances = event.generateRecurringInstances();
          await _calendarService.createEventBatch(instances);
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text('✓ ${instances.length} extraescolares creados', style: const TextStyle(color: Colors.white)),
                backgroundColor: const Color(0xFF10B981), // Color extraescolar (verde)
                duration: const Duration(seconds: 3),
              ),
            );
          }
        } else {
          await _calendarService.createEvent(event);
        }
      } else {
        await _calendarService.updateEvent(event);
      }
      reload();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error guardando evento: $e')),
        );
      }
      setState(() => _isLoading = false);
    }
  }

  Future<void> _deleteEvent(CalendarEvent event) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1A1A2E),
        title: const Text('Eliminar evento', style: TextStyle(color: Colors.white)),
        content: const Text('¿Estás seguro de que deseas eliminar este evento del calendario y agenda?', style: TextStyle(color: Colors.white70)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar', style: TextStyle(color: Colors.white54))),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Eliminar', style: TextStyle(color: Color(0xFFF43F5E)))),
        ],
      ),
    );

    if (confirm != true) return;

    setState(() => _isLoading = true);
    try {
      await _calendarService.deleteEvent(event.id);
      reload();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error eliminando evento: $e')),
        );
      }
      setState(() => _isLoading = false);
    }
  }

  Future<void> _showMagicPlanDialog() async {
    final aiService = AiService();

    // ── 1. Detectar tiempo real en Barcelona via wttr.in ──────────────────
    String? weatherDesc;
    String weatherEmoji = '🌤️';
    try {
      final wRes = await http.get(
        Uri.parse('https://wttr.in/Barcelona?format=j1'),
      ).timeout(const Duration(seconds: 5));
      if (wRes.statusCode == 200) {
        final wData = jsonDecode(wRes.body);
        final current = wData['current_condition']?[0];
        if (current != null) {
          final tempC = current['temp_C'];
          final langEs = (current['lang_es'] as List?)?.firstOrNull;
          final desc = langEs?['value'] ?? current['weatherDesc']?[0]?['value'] ?? '';
          final code = int.tryParse(current['weatherCode'].toString()) ?? 0;
          if (code == 113) weatherEmoji = '☀️';
          else if ([116, 119].contains(code)) weatherEmoji = '⛅';
          else if ([122, 143, 248].contains(code)) weatherEmoji = '☁️';
          else if ([176,263,266,293,296,299,302,305,308,353,356,359].contains(code)) weatherEmoji = '🌧️';
          else if ([389, 392, 395].contains(code)) weatherEmoji = '⛈️';
          weatherDesc = '$weatherEmoji $desc, $tempC°C';
        }
      }
    } catch (_) {}

    // ── 2. Mostrar selector de clima y presupuesto ────────────────────────
    String? climaSeleccionado;
    String presupuestoSeleccionado = 'medio';

    final opcionesClima = {
      'sol': '☀️ Soleado',
      'nublado': '⛅ Nublado',
      'lluvia': '🌧️ Lluvia',
      'calor': '🌡️ Mucho calor',
      'frio': '❄️ Frío',
    };

    if (!mounted) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) {
        String? climaLocal;
        String presupLocal = 'medio';
        return StatefulBuilder(
          builder: (ctx, setS) => Dialog(
            backgroundColor: const Color(0xFF1A1A2E),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Título
                  const Row(children: [
                    Text('✨', style: TextStyle(fontSize: 22)),
                    SizedBox(width: 8),
                    Text('Plan para hoy', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                  ]),
                  const SizedBox(height: 12),

                  // Tiempo detectado
                  if (weatherDesc != null)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                      decoration: BoxDecoration(color: Colors.white.withOpacity(0.06), borderRadius: BorderRadius.circular(10)),
                      child: Row(children: [
                        const Icon(Icons.location_on, color: Color(0xFF8B5CF6), size: 16),
                        const SizedBox(width: 6),
                        Text('Barcelona · $weatherDesc', style: const TextStyle(color: Colors.white70, fontSize: 13)),
                      ]),
                    ),
                  if (weatherDesc != null) const SizedBox(height: 14),

                  // Selector clima
                  Text('¿Qué tiempo hace hoy?', style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 12)),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8, runSpacing: 8,
                    children: opcionesClima.entries.map((e) => GestureDetector(
                      onTap: () => setS(() => climaLocal = e.key),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 150),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                        decoration: BoxDecoration(
                          color: climaLocal == e.key ? const Color(0xFF8B5CF6).withOpacity(0.35) : Colors.white.withOpacity(0.06),
                          border: Border.all(color: climaLocal == e.key ? const Color(0xFF8B5CF6) : Colors.white.withOpacity(0.15)),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(e.value, style: const TextStyle(color: Colors.white, fontSize: 13)),
                      ),
                    )).toList(),
                  ),
                  const SizedBox(height: 18),

                  // Selector presupuesto
                  Text('💰 Presupuesto para el día', style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 12)),
                  const SizedBox(height: 8),
                  Row(children: [
                    _presupBtn('bajo', '💚 Bajo', 'Gratis o casi', presupLocal, (v) => setS(() => presupLocal = v)),
                    const SizedBox(width: 8),
                    _presupBtn('medio', '🟡 Medio', '~20-40€', presupLocal, (v) => setS(() => presupLocal = v)),
                    const SizedBox(width: 8),
                    _presupBtn('alto', '🔴 Alto', 'Sin límite', presupLocal, (v) => setS(() => presupLocal = v)),
                  ]),
                  const SizedBox(height: 20),

                  // Botón generar
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF8B5CF6),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        padding: const EdgeInsets.symmetric(vertical: 13),
                      ),
                      onPressed: () {
                        if (climaLocal == null && weatherDesc == null) {
                          ScaffoldMessenger.of(ctx).showSnackBar(
                            const SnackBar(content: Text('☝️ Elige el tiempo que hace hoy para continuar'), backgroundColor: Color(0xFF8B5CF6)),
                          );
                          return;
                        }
                        climaSeleccionado = climaLocal;
                        presupuestoSeleccionado = presupLocal;
                        Navigator.pop(ctx, true);
                      },
                      child: const Text('Generar plan →', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16)),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );

    if (confirmed != true) return;

    // ── 3. Construir prompt ──────────────────────────────────────────────
    final climaTexto = opcionesClima[climaSeleccionado] ?? weatherDesc ?? 'tiempo agradable';
    final presupTexto = presupuestoSeleccionado == 'bajo'
        ? 'PRESUPUESTO BAJO: prioriza actividades gratuitas o de muy bajo coste, máximo 10€ por persona'
        : presupuestoSeleccionado == 'alto'
        ? 'PRESUPUESTO ALTO: puedes incluir entradas, restaurantes y experiencias premium'
        : 'PRESUPUESTO MEDIO: mezcla gratuito con algún pago puntual, máximo 20-40€';

    final tematicas = [
      'aventura urbana y exploración de barrios',
      'cultura y museos interactivos',
      'naturaleza y aire libre',
      'creatividad y manualidades o talleres',
      'deporte y movimiento',
      'gastronomía y descubrimiento de comidas',
      'cine, libros y ocio tranquilo',
      'tecnología y ciencia',
      'historia y monumentos de Barcelona',
      'mercados, escapadas y compras divertidas',
    ];
    final tematica = (tematicas..shuffle()).first;
    final hoy = DateTime.now();
    final diasSemana = ['lunes','martes','miércoles','jueves','viernes','sábado','domingo'];
    final meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    final fechaStr = '${diasSemana[hoy.weekday - 1]} ${hoy.day} de ${meses[hoy.month - 1]}';

    final prompt = 'Actúa como un experto planificador familiar local y práctico. '
        'Hoy es $fechaStr en Barcelona. El tiempo que hace es: $climaTexto. '
        'Diseña un plan ORIGINAL de temática "$tematica", de 6 horas (aprox 12:00-18:00) '
        'para hacer con mi hija Naia de 10 años. '
        'REGLAS IMPORTANTES: '
        '(1) Todo el plan debe estar concentrado en UNA SOLA ZONA de Barcelona o alrededores (máximo 20km del centro); '
        '(2) Los desplazamientos deben ser cortos, máximo 10-15 minutos andando o en metro; '
        '(3) Adapta al clima: si llueve interior, si sol al aire libre; '
        '(4) $presupTexto; '
        '(5) Para cada lugar o restaurante añade el enlace de Google Maps: [Ver en Maps](https://maps.google.com/?q=NOMBRE+DEL+LUGAR+Barcelona). '
        'Propón lugares CONCRETOS. Incluye sugerencia para comer/merendar. '
        'Formato lista con emojis y horarios. Sin introducción.';

    // ── 4. Loading ──────────────────────────────────────────────────────
    if (!mounted) return;
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => Dialog(
        backgroundColor: const Color(0xFF1A1A2E),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        child: Padding(
          padding: const EdgeInsets.all(28.0),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const CircularProgressIndicator(color: Color(0xFF8B5CF6)),
            const SizedBox(height: 20),
            const Text('⏳ Generando plan...', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text('Adaptando el plan al tiempo de hoy...', textAlign: TextAlign.center, style: TextStyle(color: Colors.white.withOpacity(0.6), fontSize: 13)),
          ]),
        ),
      ),
    );

    // ── 5. Llamar a la IA ──────────────────────────────────────────────
    try {
      final answer = await aiService.askPlan(prompt);
      if (!mounted) return;
      Navigator.pop(context); // cerrar loading

      // ── 6. Mostrar resultado ──────────────────────────────────────────
      if (!mounted) return;
      showDialog(
        context: context,
        builder: (ctx) => Dialog(
          backgroundColor: const Color(0xFF1A1A2E),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Header con gradiente
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(colors: [Color(0xFF8B5CF6), Color(0xFFF43F5E)]),
                  borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
                ),
                width: double.infinity,
                child: Row(children: [
                  const Text('✨', style: TextStyle(fontSize: 22)),
                  const SizedBox(width: 8),
                  Expanded(child: Text('Plan · $climaTexto', style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold), overflow: TextOverflow.ellipsis)),
                ]),
              ),
              // Contenido del plan
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(18),
                  child: _buildFormattedPlan(answer),
                ),
              ),
              // Botones
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: Row(children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        side: const BorderSide(color: Color(0xFF2563EB)),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        padding: const EdgeInsets.symmetric(vertical: 11),
                      ),
                      icon: const Text('📱', style: TextStyle(fontSize: 15)),
                      label: const Text('Telegram', style: TextStyle(color: Color(0xFF60A5FA), fontSize: 13)),
                      onPressed: () async {
                        try {
                          final response = await http.post(
                            Uri.parse('https://contenido.creawebes.com/GastosNaia/?action=telegram_send_plan&secret=${Secrets.webhookSecret}'),
                            headers: {'Content-Type': 'application/json'},
                            body: jsonEncode({'plan': answer, 'clima': climaTexto}),
                          );
                          if (ctx.mounted) {
                            if (response.statusCode == 200) {
                              showDialog(
                                context: ctx,
                                builder: (_) => AlertDialog(
                                  backgroundColor: const Color(0xFF1A1A2E),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                                  title: const Row(children: [Icon(Icons.check_circle, color: Colors.green), SizedBox(width: 8), Text('¡Enviado!', style: TextStyle(color: Colors.white))]),
                                  content: const Text('El plan se ha enviado correctamente a Telegram.', style: TextStyle(color: Colors.white70, fontSize: 14)),
                                  actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Estupendo', style: TextStyle(color: Color(0xFF6C63FF))))],
                                )
                              );
                            } else {
                              showDialog(
                                context: ctx,
                                builder: (_) => AlertDialog(
                                  backgroundColor: const Color(0xFF1A1A2E),
                                  title: const Text('Error', style: TextStyle(color: Colors.red)),
                                  content: Text('Error del servidor: ${response.statusCode}', style: const TextStyle(color: Colors.white70)),
                                  actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cerrar', style: TextStyle(color: Colors.white54)))],
                                )
                              );
                            }
                          }
                        } catch (e) {
                          if (ctx.mounted) {
                            showDialog(
                              context: ctx,
                              builder: (_) => AlertDialog(
                                backgroundColor: const Color(0xFF1A1A2E),
                                title: const Text('Error de conexión', style: TextStyle(color: Colors.red)),
                                content: Text(e.toString(), style: const TextStyle(color: Colors.white70)),
                                actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cerrar', style: TextStyle(color: Colors.white54)))],
                              )
                            );
                          }
                        }
                      },
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF8B5CF6),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        padding: const EdgeInsets.symmetric(vertical: 11),
                      ),
                      onPressed: () => Navigator.pop(ctx),
                      child: const Text('¡Perfecto!', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                    ),
                  ),
                ]),
              ),
            ],
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error IA: $e')));
    }
  }

  /// Helper que parsea el texto buscando [Ver en Maps](http...) o URLs planas para crear links
  Widget _buildFormattedPlan(String text) {
    // 1. Busca enlaces Markdown [Texto](URL) y 2. Busca URLs planas http/https
    final RegExp linkRegExp = RegExp(r'\[(.*?)\]\((.*?)\)|(https?:\/\/[^\s]+)');
    final matches = linkRegExp.allMatches(text);

    if (matches.isEmpty) {
      return Text(text, style: const TextStyle(color: Colors.white, fontSize: 14, height: 1.55));
    }

    final List<InlineSpan> spans = [];
    int lastMatchEnd = 0;

    for (final match in matches) {
      // Texto antes del link
      if (match.start > lastMatchEnd) {
        spans.add(TextSpan(text: text.substring(lastMatchEnd, match.start)));
      }

      // El Link
      String linkText;
      String linkUrl;
      
      if (match.group(3) != null) {
        // Es una URL plana
        linkUrl = match.group(3)!;
        linkText = 'Ver ubicación en mapa'; 
      } else {
        // Es un enlace Markdown
        linkText = match.group(1) ?? 'Ver ubicación en mapa';
        linkUrl = match.group(2) ?? '';
      }

      spans.add(
        WidgetSpan(
          alignment: PlaceholderAlignment.middle,
          child: InkWell(
            onTap: () async {
              final uri = Uri.tryParse(linkUrl);
              if (uri != null && await canLaunchUrl(uri)) {
                await launchUrl(uri, mode: LaunchMode.externalApplication);
              }
            },
            borderRadius: BorderRadius.circular(4),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
              child: Text(
                '📍 $linkText',
                style: const TextStyle(color: Color(0xFF8B5CF6), fontSize: 13, fontWeight: FontWeight.bold, decoration: TextDecoration.underline),
              ),
            ),
          ),
        ),
      );

      lastMatchEnd = match.end;
    }

    // Texto final después del último link
    if (lastMatchEnd < text.length) {
      spans.add(TextSpan(text: text.substring(lastMatchEnd)));
    }

    return RichText(
      text: TextSpan(
        style: const TextStyle(color: Colors.white, fontSize: 14, height: 1.55),
        children: spans,
      ),
    );
  }

  /// Widget helper para los botones de presupuesto
  Widget _presupBtn(String val, String label, String sub, String selected, void Function(String) onTap) {
    final isSelected = val == selected;
    final color = val == 'bajo' ? const Color(0xFF22C55E) : val == 'alto' ? const Color(0xFFEF4444) : const Color(0xFFEAB308);
    return Expanded(
      child: GestureDetector(
        onTap: () => onTap(val),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          padding: const EdgeInsets.symmetric(vertical: 9),
          decoration: BoxDecoration(
            color: isSelected ? color.withOpacity(0.25) : Colors.white.withOpacity(0.06),
            border: Border.all(color: isSelected ? color : Colors.white.withOpacity(0.15)),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Column(children: [
            Text(label, style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w500)),
            Text(sub, style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 11)),
          ]),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final eventsForSelectedDay = _getEventsForDay(_selectedDay ?? _focusedDay);

    return Scaffold(
      backgroundColor: const Color(0xFF0F0F1A),
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text('Calendario', style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold)),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white70),
            onPressed: reload,
          )
        ],
      ),
      body: Column(
        children: [
          // Sección del Calendario
          Container(
            margin: const EdgeInsets.symmetric(horizontal: 16),
            decoration: BoxDecoration(
              color: const Color(0xFF1A1A2E),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Colors.white.withOpacity(0.07)),
            ),
            child: TableCalendar<CalendarEvent>(
              firstDay: DateTime.utc(2020, 1, 1),
              lastDay: DateTime.utc(2030, 12, 31),
              focusedDay: _focusedDay,
              selectedDayPredicate: (day) => isSameDay(_selectedDay, day),
              calendarFormat: _calendarFormat,
              startingDayOfWeek: StartingDayOfWeek.monday,
              eventLoader: _getEventsForDay,
              onDaySelected: _onDaySelected,
              onFormatChanged: (format) {
                if (_calendarFormat != format) {
                  setState(() => _calendarFormat = format);
                }
              },
              onPageChanged: _onPageChanged,
              headerStyle: HeaderStyle(
                formatButtonVisible: true,
                titleCentered: true,
                formatButtonShowsNext: false,
                titleTextStyle: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                formatButtonTextStyle: const TextStyle(color: Colors.white, fontSize: 13),
                formatButtonDecoration: BoxDecoration(
                  color: const Color(0xFF6C63FF),
                  borderRadius: BorderRadius.circular(12),
                ),
                leftChevronIcon: const Icon(Icons.chevron_left, color: Colors.white70),
                rightChevronIcon: const Icon(Icons.chevron_right, color: Colors.white70),
              ),
              calendarStyle: CalendarStyle(
                outsideDaysVisible: false,
                defaultTextStyle: const TextStyle(color: Colors.white70),
                weekendTextStyle: const TextStyle(color: Color(0xFFF43F5E)),
                selectedDecoration: const BoxDecoration(color: Color(0xFF6C63FF), shape: BoxShape.circle),
                todayDecoration: BoxDecoration(color: const Color(0xFF6C63FF).withOpacity(0.3), shape: BoxShape.circle),
              ),
              calendarBuilders: CalendarBuilders<CalendarEvent>(
                singleMarkerBuilder: (context, date, event) {
                  Color markerColor = const Color(0xFF3B82F6);
                  if (event.colorId == '10') markerColor = const Color(0xFF10B981);
                  if (event.colorId == '3') markerColor = const Color(0xFF8B5CF6);
                  if (event.colorId == '11') markerColor = const Color(0xFFF43F5E);
                  if (event.colorId == '6') markerColor = const Color(0xFFEAB308);  // Visita
                  return Container(
                    margin: const EdgeInsets.symmetric(horizontal: 1.5),
                    width: 7,
                    height: 7,
                    decoration: BoxDecoration(shape: BoxShape.circle, color: markerColor),
                  );
                },
              ),
              daysOfWeekStyle: const DaysOfWeekStyle(
                weekdayStyle: TextStyle(color: Colors.white54, fontWeight: FontWeight.bold),
                weekendStyle: TextStyle(color: Color(0xFFF43F5E), fontWeight: FontWeight.bold),
              ),
            ),
          ),
          
          const SizedBox(height: 16),
          
          // -----> Botón Varita Mágica <-----
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16.0),
            child: GestureDetector(
              onTap: _showMagicPlanDialog,
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 14),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(begin: Alignment.centerLeft, end: Alignment.centerRight, colors: [Color(0xFFFF6B6B), Color(0xFFFF8E53)]),
                  borderRadius: BorderRadius.circular(16),
                  boxShadow: [
                    BoxShadow(color: const Color(0xFFFF6B6B).withOpacity(0.4), blurRadius: 10, offset: const Offset(0, 4)),
                  ],
                ),
                child: const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text('🪄', style: TextStyle(fontSize: 20)),
                    SizedBox(width: 8),
                    Text('Sugerir Plan Sorpresa', style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: 16),

          if (_isLoading)
            const Expanded(child: Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF))))
          else
            Expanded(
              child: eventsForSelectedDay.isEmpty
                ? Center(
                    child: Text(
                      'No hay eventos para este día.',
                      style: TextStyle(color: Colors.white.withOpacity(0.4)),
                    ),
                  )
                : ListView.builder(
                    padding: const EdgeInsets.only(left: 16, right: 16, top: 16, bottom: 80), // Añadido margen inferior para el botón
                    itemCount: eventsForSelectedDay.length,
                    itemBuilder: (context, index) {
                      final event = eventsForSelectedDay[index];
                      return _buildEventCard(event);
                    },
                  ),
            ),
        ],
      ),
      floatingActionButton: FloatingActionButton(
        backgroundColor: const Color(0xFF6C63FF),
        child: const Icon(Icons.add, color: Colors.white),
        onPressed: () => _showEventModal(),
      ),
    );
  }

  Widget _buildEventCard(CalendarEvent event) {
    final timeFormat = DateFormat('HH:mm');
    final String timeStr = event.allDay 
        ? 'Todo el día' 
        : "${timeFormat.format(event.start)} - ${timeFormat.format(event.end)}";
    
    // Asignar color según el colorId que viene de Google Calendar
    Color eventColor = const Color(0xFF3B82F6); // Default Blue
    if (event.colorId == '10') eventColor = const Color(0xFF10B981); // Extraescolar
    if (event.colorId == '3') eventColor = const Color(0xFF8B5CF6);  // Cita
    if (event.colorId == '11') eventColor = const Color(0xFFF43F5E); // Importante
    if (event.colorId == '6') eventColor = const Color(0xFFEAB308);  // Visita
    
    // Eliminamos el Zero-Width Space (\u200B) que inyecta PHP para engañar a Google Calendar
    final String trimmedLoc = event.location.replaceAll('\u200B', '').trim();
    final bool isLocationUrl = trimmedLoc.toLowerCase().startsWith('http');
        
    return GestureDetector(
      onTap: () => _showEventModal(event),
      onLongPress: () => _deleteEvent(event),
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        decoration: BoxDecoration(
          color: const Color(0xFF1A1A2E),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white.withOpacity(0.07)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.2),
              blurRadius: 8,
              offset: const Offset(0, 4),
            )
          ],
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(16),
          child: IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(width: 6, color: eventColor),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                event.title,
                                style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                              ),
                            ),
                            if (event.reminderMinutes != null) ...[ 
                              const SizedBox(width: 6),
                              Icon(Icons.notifications_active, size: 15, color: eventColor.withOpacity(0.9)),
                            ],
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          children: [
                            Icon(Icons.access_time_rounded, size: 14, color: Colors.white.withOpacity(0.5)),
                            const SizedBox(width: 4),
                            Flexible(
                              child: Text(timeStr, style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 13), overflow: TextOverflow.ellipsis),
                            ),
                          ],
                        ),
                        if (event.location.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              Icon(Icons.location_on_outlined, size: 14, color: isLocationUrl ? const Color(0xFF6C63FF) : Colors.white.withOpacity(0.5)),
                              const SizedBox(width: 4),
                              Expanded(
                                child: isLocationUrl
                                  ? GestureDetector(
                                      onTap: () async {
                                        final cleanUrl = trimmedLoc.replaceAll(' ', '%20');
                                        final uri = Uri.tryParse(cleanUrl);
                                        if (uri != null && await canLaunchUrl(uri)) {
                                          await launchUrl(uri, mode: LaunchMode.externalApplication);
                                        }
                                      },
                                      child: const Text(
                                        'Ver ubicación en mapa',
                                        style: TextStyle(color: Color(0xFF6C63FF), fontSize: 13, decoration: TextDecoration.underline, decorationColor: Color(0xFF6C63FF)),
                                      ),
                                    )
                                  : _buildFormattedPlan(event.location), // Reutilizamos el parser por si mandan la URL en markdown
                              ),
                            ],
                          ),
                        ],
                        if (event.description.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          _buildFormattedPlan(event.description), // Parsear la descripción entera para que los enlaces se hagan botones
                        ]
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
