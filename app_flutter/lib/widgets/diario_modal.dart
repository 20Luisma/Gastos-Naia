import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:file_picker/file_picker.dart';
import 'dart:typed_data';
import '../models/comunicado.dart';
import '../services/diario_service.dart';

class DiarioModal extends StatefulWidget {
  final Comunicado? comunicadoToEdit;

  const DiarioModal({
    super.key,
    this.comunicadoToEdit,
  });

  @override
  State<DiarioModal> createState() => _DiarioModalState();
}

class _DiarioModalState extends State<DiarioModal> {
  final _titleController = TextEditingController();
  final _descController = TextEditingController();
  late DateTime _selectedDate;
  
  // Manejo de archivos
  List<PlatformFile> _newSelectedFiles = [];

  bool _isLoading = false;
  final DiarioService _diarioService = DiarioService();

  @override
  void initState() {
    super.initState();
    if (widget.comunicadoToEdit != null) {
      _titleController.text = widget.comunicadoToEdit!.title;
      _descController.text = widget.comunicadoToEdit!.description;
      _selectedDate = widget.comunicadoToEdit!.date;
    } else {
      _titleController.text = 'Nueva entrada de diario';
      _selectedDate = DateTime.now();
    }
  }

  @override
  void dispose() {
    _titleController.dispose();
    _descController.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
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
        _selectedDate = picked;
      });
    }
  }

  Future<void> _pickFile() async {
    try {
      final result = await FilePicker.platform.pickFiles(
        type: FileType.any,
        withData: true, // Importante para poder subirlo desde bytes (web/movil unificado)
        allowMultiple: true,
      );

      if (result != null && result.files.isNotEmpty) {
        setState(() {
          _newSelectedFiles.addAll(result.files);
        });
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error seleccionando archivo: $e')),
        );
      }
    }
  }

  Future<void> _save() async {
    if (_titleController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Por favor, ingresa un título')),
      );
      return;
    }

    setState(() => _isLoading = true);

    try {
      String? finalFileUrl = widget.comunicadoToEdit?.fileUrl;
      String? finalFileType = widget.comunicadoToEdit?.fileType;
      String? finalFileName = widget.comunicadoToEdit?.fileName;
      List<ComunicadoAttachment> finalAttachments = List.from(widget.comunicadoToEdit?.attachments ?? []);

      // Si hay archivos NUEVOS seleccionados, subirlos uno por uno
      for (var file in _newSelectedFiles) {
        if (file.bytes != null) {
          final uploadedUrl = await _diarioService.uploadAttachment(file.bytes!, file.name);
          final ext = file.extension?.toLowerCase() ?? '';
          final type = ext == 'pdf' ? 'application/pdf' : 'image/$ext';
          
          final newAtt = ComunicadoAttachment(url: uploadedUrl, name: file.name, type: type);
          finalAttachments.add(newAtt);
          
          // Mantener fields legacy sincronizados con el primero que se sube, para retrocompatibilidad
          if (finalFileUrl == null) {
            finalFileUrl = newAtt.url;
            finalFileName = newAtt.name;
            finalFileType = newAtt.type;
          }
        }
      }

      final comunicado = Comunicado(
        id: widget.comunicadoToEdit?.id ?? '',
        title: _titleController.text.trim(),
        description: _descController.text.trim(),
        date: _selectedDate,
        fileUrl: finalFileUrl,
        fileType: finalFileType,
        fileName: finalFileName,
        attachments: finalAttachments,
      );

      await _diarioService.saveComunicado(comunicado);
      
      if (mounted) {
        Navigator.pop(context, true); // Retorna true si se guardó correctamente
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error guardando: $e')),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isEditing = widget.comunicadoToEdit != null;
    final topPadding = MediaQuery.of(context).padding.top;
    
    return Container(
      height: MediaQuery.of(context).size.height - topPadding,
      decoration: const BoxDecoration(
        color: Color(0xFF1A1A2E),
        borderRadius: BorderRadius.only(
          topLeft: Radius.circular(24),
          topRight: Radius.circular(24),
        ),
      ),
      child: Column(
        children: [
          _buildHeader(isEditing),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                   _buildDatePicker(),
                  const SizedBox(height: 24),
                  _buildTextField(
                    controller: _titleController,
                    label: 'Título (obligatorio)',
                    icon: Icons.title,
                  ),
                  const SizedBox(height: 16),
                  _buildTextField(
                    controller: _descController,
                    label: 'Tu texto o recuerdo (opcional)',
                    icon: Icons.notes,
                    maxLines: 8,
                  ),
                  const SizedBox(height: 24),
                  _buildAttachmentSection(),
                ],
              ),
            ),
          ),
          _buildFooter(),
        ],
      ),
    );
  }

  Widget _buildHeader(bool isEditing) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: const Color(0xFF0F0F1A),
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(24),
          topRight: Radius.circular(24),
        ),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(color: const Color(0xFF6C63FF).withOpacity(0.2), borderRadius: BorderRadius.circular(10)),
                child: const Icon(Icons.edit_note, color: Color(0xFF6C63FF)),
              ),
              const SizedBox(width: 12),
              Text(
                isEditing ? 'Editar Diario' : 'Nuevo Diario',
                style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          IconButton(
            icon: const Icon(Icons.close, color: Colors.white54),
            onPressed: () => Navigator.pop(context),
          ),
        ],
      ),
    );
  }

  Widget _buildDatePicker() {
    return InkWell(
      onTap: _pickDate,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        decoration: BoxDecoration(
          color: const Color(0xFF0F0F1A),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white.withOpacity(0.05)),
        ),
        child: Row(
          children: [
            const Icon(Icons.calendar_today, color: Color(0xFF6C63FF), size: 20),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Día del suceso', style: TextStyle(color: Colors.white54, fontSize: 13)),
                  const SizedBox(height: 4),
                  Text(
                    DateFormat('EEEE d MMMM, y', 'es_ES').format(_selectedDate),
                    style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w600),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTextField({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    int maxLines = 1,
  }) {
    return TextField(
      controller: controller,
      style: const TextStyle(color: Colors.white),
      maxLines: maxLines,
      decoration: InputDecoration(
        labelText: label,
        labelStyle: const TextStyle(color: Colors.white54),
        prefixIcon: icon != Icons.notes ? Icon(icon, color: const Color(0xFF6C63FF)) : null,
        filled: true,
        fillColor: const Color(0xFF0F0F1A),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none),
        contentPadding: const EdgeInsets.all(20),
      ),
    );
  }

  Widget _buildAttachmentSection() {
    List<Widget> attachmentWidgets = [];

    // Legacy fileUrl
    if (widget.comunicadoToEdit != null && widget.comunicadoToEdit!.fileUrl != null && widget.comunicadoToEdit!.attachments.isEmpty) {
      attachmentWidgets.add(_buildAttachmentItem(
        widget.comunicadoToEdit!.fileName ?? 'Archivo adjunto',
        onRemove: null,
      ));
    }

    // Existing attachments
    if (widget.comunicadoToEdit != null) {
      for (var att in widget.comunicadoToEdit!.attachments) {
        attachmentWidgets.add(_buildAttachmentItem(att.name, onRemove: null));
      }
    }

    // New selected files
    for (int i = 0; i < _newSelectedFiles.length; i++) {
      attachmentWidgets.add(_buildAttachmentItem(
        _newSelectedFiles[i].name,
        onRemove: () => setState(() => _newSelectedFiles.removeAt(i)),
        isNew: true,
      ));
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: const Color(0xFF0F0F1A),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withOpacity(0.05)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.attach_file, color: Color(0xFF6C63FF), size: 20),
              const SizedBox(width: 12),
              const Expanded(
                child: Text(
                  'Archivos adjuntos',
                  style: TextStyle(color: Colors.white54, fontSize: 14),
                ),
              ),
              TextButton(
                onPressed: _pickFile,
                style: TextButton.styleFrom(
                  foregroundColor: const Color(0xFF6C63FF),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  minimumSize: Size.zero,
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
                child: const Text('Añadir', style: TextStyle(fontWeight: FontWeight.bold)),
              ),
            ],
          ),
          if (attachmentWidgets.isNotEmpty) const SizedBox(height: 12),
          ...attachmentWidgets,
        ],
      ),
    );
  }

  Widget _buildAttachmentItem(String name, {VoidCallback? onRemove, bool isNew = false}) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: const Color(0xFF1A1A2E),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(isNew ? Icons.upload_file : Icons.insert_drive_file, color: const Color(0xFF6C63FF), size: 16),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              name,
              style: const TextStyle(color: Colors.white, fontSize: 14),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (onRemove != null)
            IconButton(
              icon: const Icon(Icons.close, color: Colors.white54, size: 18),
              onPressed: onRemove,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(),
            )
        ],
      ),
    );
  }

  Widget _buildFooter() {
    return Container(
      padding: EdgeInsets.only(
        left: 24,
        right: 24,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom > 0 
                ? MediaQuery.of(context).viewInsets.bottom + 16 
                : MediaQuery.of(context).padding.bottom + 24, // Añadido padding seguro para barra de navegación
      ),
      decoration: BoxDecoration(
        color: const Color(0xFF0F0F1A),
        border: Border(top: BorderSide(color: Colors.white.withOpacity(0.05))),
      ),
      child: SizedBox(
        width: double.infinity,
        height: 56,
        child: ElevatedButton(
          onPressed: _isLoading ? null : _save,
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF6C63FF),
            foregroundColor: Colors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            elevation: 0,
          ),
          child: _isLoading
              ? const SizedBox(height: 24, width: 24, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
              : const Text('Guardar Nota', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        ),
      ),
    );
  }
}
