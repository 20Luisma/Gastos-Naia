import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../models/comunicado.dart';
import '../services/diario_service.dart';
import '../widgets/diario_modal.dart';
import 'package:url_launcher/url_launcher.dart';

class DiarioScreen extends StatefulWidget {
  const DiarioScreen({super.key});

  @override
  State<DiarioScreen> createState() => _DiarioScreenState();
}

class _DiarioScreenState extends State<DiarioScreen> {
  final DiarioService _diarioService = DiarioService();
  List<Comunicado> _comunicados = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadComunicados();
  }

  Future<void> _loadComunicados() async {
    setState(() => _isLoading = true);
    try {
      final data = await _diarioService.getComunicados();
      if (mounted) {
        setState(() {
          _comunicados = data;
        });
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error cargando diario: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showComunicadoModal([Comunicado? comunicado]) async {
    final result = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => DiarioModal(comunicadoToEdit: comunicado),
    );

    if (result == true) {
      _loadComunicados();
    }
  }

  Future<void> _deleteComunicado(Comunicado comunicado) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1A1A2E),
        title: const Text('Eliminar nota', style: TextStyle(color: Colors.white)),
        content: const Text('¿Seguro de borrar este apunte del diario de Naia?', style: TextStyle(color: Colors.white70)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar', style: TextStyle(color: Colors.white54))),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Borrar', style: TextStyle(color: Color(0xFFF43F5E)))),
        ],
      ),
    );

    if (confirm != true) return;

    setState(() => _isLoading = true);
    try {
      await _diarioService.deleteComunicado(comunicado.id);
      _loadComunicados();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error borrando: $e')),
        );
        setState(() => _isLoading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F0F1A),
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Row(
          children: [
            Text('📝', style: TextStyle(fontSize: 24)),
            SizedBox(width: 12),
            Text('Diario de Naia', style: TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.bold)),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white70),
            onPressed: _loadComunicados,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF)))
          : _comunicados.isEmpty
              ? _buildEmptyState()
              : RefreshIndicator(
                  color: const Color(0xFF6C63FF),
                  backgroundColor: const Color(0xFF1A1A2E),
                  onRefresh: _loadComunicados,
                  child: ListView.builder(
                    padding: const EdgeInsets.only(top: 16, bottom: 80, left: 16, right: 16),
                    itemCount: _comunicados.length,
                    itemBuilder: (context, index) {
                      final item = _comunicados[index];
                      return _buildComunicadoCard(item);
                    },
                  ),
                ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showComunicadoModal(),
        backgroundColor: const Color(0xFF6C63FF),
        icon: const Icon(Icons.add, color: Colors.white),
        label: const Text('Nueva Nota', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
      ),
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.mark_email_unread_outlined, size: 80, color: Colors.white.withAlpha(50)),
          const SizedBox(height: 24),
          Text(
            'El diario está en blanco',
            style: TextStyle(color: Colors.white.withAlpha(200), fontSize: 20, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 12),
          Text(
            'Añade una nota o recuerdo sobre Naia para que quede guardado.',
            textAlign: TextAlign.center,
            style: TextStyle(color: Colors.white.withAlpha(120), fontSize: 16, height: 1.5),
          ),
        ],
      ),
    );
  }

  Widget _buildComunicadoCard(Comunicado item) {
    final dateFormat = DateFormat('EEEE d MMMM, y', 'es_ES');
    final formattedDate = dateFormat.format(item.date).toUpperCase();

    return Container(
      margin: const EdgeInsets.only(bottom: 20),
      decoration: BoxDecoration(
        color: const Color(0xFF1A1A2E),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withAlpha(15)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    item.title,
                    style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                ),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      icon: const Icon(Icons.edit_outlined, size: 20, color: Colors.white54),
                      onPressed: () => _showComunicadoModal(item),
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                    ),
                    const SizedBox(width: 16),
                    IconButton(
                      icon: const Icon(Icons.delete_outline, size: 20, color: Colors.white54),
                      onPressed: () => _deleteComunicado(item),
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                    ),
                  ],
                )
              ],
            ),
            const SizedBox(height: 6),
            Text(
              formattedDate,
              style: const TextStyle(color: Color(0xFF6C63FF), fontSize: 13, fontWeight: FontWeight.w600),
            ),
            if (item.description.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(
                item.description,
                style: const TextStyle(color: Colors.white70, fontSize: 15, height: 1.5),
              ),
            ],
            Builder(builder: (context) {
              List<ComunicadoAttachment> allAttachments = [];
              if (item.fileUrl != null && item.fileUrl!.isNotEmpty && item.attachments.isEmpty) {
                allAttachments.add(ComunicadoAttachment(url: item.fileUrl!, name: item.fileName ?? 'Adjunto', type: item.fileType ?? ''));
              } else if (item.attachments.isNotEmpty) {
                allAttachments.addAll(item.attachments);
              }

              if (allAttachments.isEmpty) return const SizedBox.shrink();

              return Padding(
                padding: const EdgeInsets.only(top: 16),
                child: Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  children: allAttachments.map((att) => GestureDetector(
                    onTap: () async {
                      // Usar interpolation robusta en Dart en vez de escapar la template
                      final uri = Uri.parse("https://contenido.creawebes.com/GastosNaia/" + att.url);
                      if (await canLaunchUrl(uri)) {
                        await launchUrl(uri, mode: LaunchMode.externalApplication);
                      }
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                      decoration: BoxDecoration(
                        color: const Color(0xFF6C63FF).withAlpha(30),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xFF6C63FF).withAlpha(100)),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.attach_file, color: Color(0xFF6C63FF), size: 18),
                          const SizedBox(width: 8),
                          Flexible(
                            child: Text(
                              att.name.isNotEmpty ? att.name : 'Ver Archivo Adjunto',
                              style: const TextStyle(color: Color(0xFF6C63FF), fontWeight: FontWeight.bold, fontSize: 14),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  )).toList(),
                ),
              );
            })
          ],
        ),
      ),
    );
  }
}
