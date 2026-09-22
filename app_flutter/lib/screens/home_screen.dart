import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import 'package:image_picker/image_picker.dart';
import 'package:file_picker/file_picker.dart';
import 'package:url_launcher/url_launcher.dart';
import '../providers/expense_provider.dart';
import '../models/expense.dart';
import '../services/receipt_service.dart';
import '../services/google_drive_service.dart';
import 'annual_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final _currency = NumberFormat.currency(locale: 'es_ES', symbol: '€');
  final _receiptService = ReceiptService();
  bool _scanningReceipt = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<ExpenseProvider>().loadExpenses();
    });
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<ExpenseProvider>();
    return Scaffold(
      backgroundColor: const Color(0xFF0F0F1A),
      appBar: _buildAppBar(provider),
      body: Column(
        children: [
          _buildTotalCard(provider),
          _buildMonthSelector(provider),
          Expanded(child: _buildExpenseList(provider)),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'add',
        onPressed: () => _showExpenseDialog(context, provider),
        backgroundColor: const Color(0xFF6C63FF),
        icon: const Icon(Icons.add_rounded, color: Colors.white),
        label: const Text('Añadir', style: TextStyle(color: Colors.white)),
      ),
    );
  }

  AppBar _buildAppBar(ExpenseProvider provider) {
    return AppBar(
      backgroundColor: Colors.transparent,
      elevation: 0,
      title: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Gastos', style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold)),
          Text(
            '${ExpenseProvider.monthNames[provider.selectedMonth]} ${provider.selectedYear}',
            style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 13),
          ),
        ],
      ),
      actions: [
        // Year selector
        PopupMenuButton<int>(
          icon: const Icon(Icons.calendar_today_rounded, color: Colors.white70),
          color: const Color(0xFF1A1A2E),
          onSelected: provider.selectYear,
          itemBuilder: (_) => ExpenseProvider.availableYears.reversed
              .map((y) => PopupMenuItem(
                    value: y,
                    child: Text('$y', style: const TextStyle(color: Colors.white)),
                  ))
              .toList(),
        ),
      ],
    );
  }

  Widget _buildTotalCard(ExpenseProvider provider) {
    final half = provider.halfTotal;
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF6C63FF), Color(0xFF3B82F6)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [BoxShadow(color: const Color(0xFF6C63FF).withOpacity(0.35), blurRadius: 20, offset: const Offset(0, 8))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Total del mes', style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 13)),
          const SizedBox(height: 6),
          // Total + half side by side
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(_currency.format(provider.totalMonth),
                  style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.bold, letterSpacing: -1)),
              const SizedBox(width: 10),
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Text('/ 2 = ${_currency.format(half)}',
                    style: TextStyle(color: Colors.white.withOpacity(0.75), fontSize: 16, fontWeight: FontWeight.w500)),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _infoChip('Pensión: ${_currency.format(provider.pension)}', onTap: () => _showPensionDialog(context, provider)),
              // Bizum WhatsApp button
              GestureDetector(
                onTap: () => _payBizum(provider),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                  decoration: BoxDecoration(
                    color: const Color(0xFF25D366).withOpacity(0.85), // WhatsApp green
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.send_rounded, color: Colors.white, size: 13),
                      const SizedBox(width: 4),
                      Text('Bizum ${_currency.format(half)}', style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                    ],
                  ),
                ),
              ),
              // Receipts global button
              GestureDetector(
                onTap: () => _showReceiptsModal(provider.selectedYear, provider.selectedMonth),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.receipt_long_rounded, color: Colors.white, size: 13),
                      SizedBox(width: 4),
                      Text('Recibos', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                    ],
                  ),
                ),
              ),
              // Resumen button moved from main navigation
              GestureDetector(
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const AnnualScreen())),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.bar_chart_rounded, color: Colors.white, size: 13),
                      SizedBox(width: 4),
                      Text('Resúmenes', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _infoChip(String text, {VoidCallback? onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: Colors.white.withOpacity(0.15), borderRadius: BorderRadius.circular(20)),
        child: Text(text, style: const TextStyle(color: Colors.white, fontSize: 11)),
      ),
    );
  }

  Widget _buildMonthSelector(ExpenseProvider provider) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Row(
        children: List.generate(12, (i) {
          final m = i + 1;
          final selected = m == provider.selectedMonth;
          return Padding(
            padding: const EdgeInsets.only(right: 8),
            child: Material(
              color: Colors.transparent,
              child: InkWell(
                onTap: () => provider.selectMonth(m),
                borderRadius: BorderRadius.circular(20),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                  decoration: BoxDecoration(
                    color: selected ? const Color(0xFF6C63FF) : const Color(0xFF1A1A2E),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: selected ? const Color(0xFF6C63FF) : Colors.white.withOpacity(0.1)),
                  ),
                  child: Text(
                    ExpenseProvider.monthNames[m].substring(0, 3),
                    style: TextStyle(
                      color: selected ? Colors.white : Colors.white.withOpacity(0.5),
                      fontWeight: selected ? FontWeight.bold : FontWeight.normal,
                      fontSize: 13,
                    ),
                  ),
                ),
              ),
            ),
          );
        }),
      ),
    );
  }

  Widget _buildExpenseList(ExpenseProvider provider) {
    if (provider.isLoading) {
      return const Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF)));
    }
    if (provider.error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline_rounded, color: Colors.red, size: 48),
              const SizedBox(height: 12),
              Text(provider.error!, style: const TextStyle(color: Colors.red), textAlign: TextAlign.center),
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed: provider.loadExpenses,
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF6C63FF)),
                child: const Text('Reintentar'),
              ),
            ],
          ),
        ),
      );
    }
    if (provider.expenses.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.receipt_long_outlined, color: Colors.white.withOpacity(0.2), size: 64),
            const SizedBox(height: 12),
            Text('Sin gastos este mes', style: TextStyle(color: Colors.white.withOpacity(0.4))),
          ],
        ),
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
      itemCount: provider.expenses.length,
      itemBuilder: (_, i) => _buildExpenseTile(provider.expenses[i], provider),
    );
  }

  Widget _buildExpenseTile(Expense expense, ExpenseProvider provider) {
    return Dismissible(
      key: Key(expense.id),
      direction: DismissDirection.endToStart,
      background: Container(
        alignment: Alignment.centerRight,
        padding: const EdgeInsets.only(right: 20),
        margin: const EdgeInsets.only(bottom: 10),
        decoration: BoxDecoration(color: Colors.red.withOpacity(0.8), borderRadius: BorderRadius.circular(14)),
        child: const Icon(Icons.delete_rounded, color: Colors.white),
      ),
      confirmDismiss: (_) async {
        return await showDialog<bool>(
              context: context,
              builder: (_) => AlertDialog(
                backgroundColor: const Color(0xFF1A1A2E),
                title: const Text('Eliminar gasto', style: TextStyle(color: Colors.white)),
                content: Text('¿Eliminar "${expense.description}"?', style: const TextStyle(color: Colors.white70)),
                actions: [
                  TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancelar')),
                  TextButton(
                    onPressed: () => Navigator.pop(context, true),
                    child: const Text('Eliminar', style: TextStyle(color: Colors.red)),
                  ),
                ],
              ),
            ) ??
            false;
      },
      onDismissed: (_) => provider.deleteExpense(expense),
      child: GestureDetector(
        onTap: () => _showExpenseDialog(context, provider, expense: expense),
        child: Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFF1A1A2E),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: Colors.white.withOpacity(0.07)),
          ),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: const Color(0xFF6C63FF).withOpacity(0.15),
                  borderRadius: BorderRadius.circular(11),
                ),
                child: const Icon(Icons.receipt_long_rounded, color: Color(0xFF6C63FF), size: 18),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(expense.description, style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w500), maxLines: 1, overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 2),
                    Text(expense.date, style: TextStyle(color: Colors.white.withOpacity(0.4), fontSize: 11)),
                  ],
                ),
              ),
              Text(
                _currency.format(expense.amount),
                style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showReceiptsModal(int year, int month) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF1A1A2E),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => _ReceiptsModal(year: year, month: month),
    );
  }


  void _showExpenseDialog(BuildContext context, ExpenseProvider provider, {Expense? expense}) {
    final isEditing = expense != null;
    final dateCtrl = TextEditingController(text: expense?.date ?? DateFormat('dd/MM/yyyy').format(DateTime.now()));
    final descCtrl = TextEditingController(text: expense?.description ?? '');
    final amountCtrl = TextEditingController(text: expense != null ? expense.amount.toStringAsFixed(2) : '');
    bool saving = false;
    bool scanning = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF1A1A2E),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Padding(
          padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom, left: 24, right: 24, top: 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(isEditing ? 'Editar gasto' : 'Nuevo gasto',
                      style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold)),
                  // Scan receipt button inside the dialog
                  if (!isEditing)
                    TextButton.icon(
                      onPressed: scanning
                          ? null
                          : () async {
                              setModalState(() => scanning = true);
                              final picker = ImagePicker();
                              final photo = await picker.pickImage(source: ImageSource.camera, imageQuality: 85);
                              if (photo == null) { setModalState(() => scanning = false); return; }
                              try {
                                final data = await _receiptService.scanReceipt(photo.path);
                                if (data.containsKey('error')) {
                                  if (ctx.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(data['error'])));
                                } else {
                                  dateCtrl.text = data['date'] ?? dateCtrl.text;
                                  descCtrl.text = data['description'] ?? '';
                                  amountCtrl.text = (data['amount'] as num?)?.toStringAsFixed(2) ?? '';
                                }
                              } finally {
                                setModalState(() => scanning = false);
                              }
                            },
                      icon: scanning
                          ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF6C63FF)))
                          : const Icon(Icons.document_scanner_rounded, size: 18, color: Color(0xFF6C63FF)),
                      label: Text(scanning ? 'Escaneando...' : 'Escanear recibo',
                          style: const TextStyle(color: Color(0xFF6C63FF), fontSize: 13)),
                    ),
                ],
              ),
              const SizedBox(height: 20),
              TextField(controller: dateCtrl, style: const TextStyle(color: Colors.white), decoration: _inputDeco('Fecha (DD/MM/YYYY)', Icons.calendar_today_rounded)),
              const SizedBox(height: 12),
              TextField(controller: descCtrl, style: const TextStyle(color: Colors.white), decoration: _inputDeco('Descripción', Icons.description_rounded)),
              const SizedBox(height: 12),
              TextField(
                controller: amountCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                style: const TextStyle(color: Colors.white),
                decoration: _inputDeco('Importe (€)', Icons.euro_rounded),
              ),
              const SizedBox(height: 20),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF6C63FF), padding: const EdgeInsets.symmetric(vertical: 14), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
                  onPressed: saving
                      ? null
                      : () async {
                          setModalState(() => saving = true);
                          final amount = double.tryParse(amountCtrl.text.replaceAll(',', '.')) ?? 0.0;
                          
                          // Convert YYYY-MM-DD or YYYY/MM/DD to DD/MM/YYYY
                          String finalDate = dateCtrl.text.trim();
                          if (RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(finalDate)) {
                            final parts = finalDate.split('-');
                            finalDate = '${parts[2]}/${parts[1]}/${parts[0]}';
                          } else if (RegExp(r'^\d{4}/\d{2}/\d{2}$').hasMatch(finalDate)) {
                            final parts = finalDate.split('/');
                            finalDate = '${parts[2]}/${parts[1]}/${parts[0]}';
                          } else if (RegExp(r'^\d{2}-\d{2}-\d{4}$').hasMatch(finalDate)) {
                            final parts = finalDate.split('-');
                            finalDate = '${parts[0]}/${parts[1]}/${parts[2]}';
                          }

                          bool ok;
                          if (isEditing) {
                            ok = await provider.editExpense(expense, finalDate, descCtrl.text, amount);
                          } else {
                            ok = await provider.addExpense(finalDate, descCtrl.text, amount);
                          }
                          if (ctx.mounted) Navigator.pop(ctx);
                          if (!ok && context.mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(provider.error ?? 'Error')));
                          }
                        },
                  child: saving
                      ? const CircularProgressIndicator(color: Colors.white, strokeWidth: 2)
                      : Text(isEditing ? 'Guardar cambios' : 'Añadir', style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                ),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }

  void _showPensionDialog(BuildContext context, ExpenseProvider provider) {
    final ctrl = TextEditingController(text: provider.pension.toStringAsFixed(2));
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: const Color(0xFF1A1A2E),
        title: const Text('Pensión Alimenticia', style: TextStyle(color: Colors.white)),
        content: TextField(
          controller: ctrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          style: const TextStyle(color: Colors.white),
          decoration: _inputDeco('Importe (€)', Icons.euro_rounded),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancelar')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF6C63FF)),
            onPressed: () async {
              final amount = double.tryParse(ctrl.text.replaceAll(',', '.')) ?? 0.0;
              await provider.savePension(amount);
              if (context.mounted) Navigator.pop(context);
            },
            child: const Text('Guardar'),
          ),
        ],
      ),
    );
  }

  Future<void> _scanReceipt(BuildContext context, ExpenseProvider provider) async {
    final picker = ImagePicker();
    final photo = await picker.pickImage(source: ImageSource.camera, imageQuality: 85);
    if (photo == null) return;

    setState(() => _scanningReceipt = true);
    try {
      final data = await _receiptService.scanReceipt(photo.path);
      if (!mounted) return;
      if (data.containsKey('error')) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(data['error'])));
        return;
      }
      final expense = Expense(
        id: 'ocr',
        date: data['date'] ?? '',
        description: data['description'] ?? '',
        amount: (data['amount'] as num?)?.toDouble() ?? 0.0,
        year: provider.selectedYear,
        month: provider.selectedMonth,
      );
      // Open dialog pre-filled with OCR data
      if (mounted) _showExpenseDialog(context, provider, expense: expense);
    } finally {
      if (mounted) setState(() => _scanningReceipt = false);
    }
  }

  Future<void> _payBizum(ExpenseProvider provider) async {
    final monthName = ExpenseProvider.monthNames[provider.selectedMonth].toLowerCase();
    
    final message = Uri.encodeComponent(
      'Hola Irene 👋 Aquí tienes los gastos extraescolares de $monthName.'
    );
    final phone = '34699889733'; // +34 699 889 733
    final url = Uri.parse('https://wa.me/$phone?text=$message');

    if (await canLaunchUrl(url)) {
      await launchUrl(url, mode: LaunchMode.externalApplication);
    } else {
      // Fallback: copy to clipboard
      await Clipboard.setData(ClipboardData(text: provider.halfTotal.toStringAsFixed(2)));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Importe copiado: ${_currency.format(provider.halfTotal)} (WhatsApp no disponible)')),
        );
      }
    }
  }


  InputDecoration _inputDeco(String label, IconData icon) {
    return InputDecoration(
      labelText: label,
      labelStyle: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 14),
      prefixIcon: Icon(icon, color: Colors.white.withOpacity(0.5), size: 20),
      filled: true,
      fillColor: Colors.white.withOpacity(0.05),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: const Color(0xFF6C63FF).withOpacity(0.5))),
      contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
    );
  }
}

class _ReceiptsModal extends StatefulWidget {
  final int year;
  final int month;

  const _ReceiptsModal({required this.year, required this.month});

  @override
  State<_ReceiptsModal> createState() => _ReceiptsModalState();
}

class _ReceiptsModalState extends State<_ReceiptsModal> {
  final _driveService = GoogleDriveService();
  List<DriveFile> _files = [];
  bool _isLoading = true;
  bool _isUploading = false;

  @override
  void initState() {
    super.initState();
    _loadFiles();
  }

  Future<void> _loadFiles() async {
    setState(() => _isLoading = true);
    final files = await _driveService.listReceipts(widget.year, widget.month);
    if (mounted) {
      setState(() {
        _files = files;
        _isLoading = false;
      });
    }
  }

  Future<void> _uploadFromCamera() async {
    final picker = ImagePicker();
    final photo = await picker.pickImage(source: ImageSource.camera, imageQuality: 70);
    if (photo == null) return;
    await _uploadFile(photo.name, await photo.readAsBytes(), 'image/jpeg');
  }

  Future<void> _uploadFromGallery() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png', 'pdf'],
      withData: true,
    );
    if (result == null || result.files.isEmpty) return;
    final file = result.files.first;
    if (file.bytes == null) return;

    // determine mime
    String mime = 'application/octet-stream';
    if (file.extension == 'pdf') mime = 'application/pdf';
    else if (file.extension == 'png') mime = 'image/png';
    else if (file.extension == 'jpg' || file.extension == 'jpeg') mime = 'image/jpeg';

    await _uploadFile(file.name, file.bytes!, mime);
  }

  Future<void> _uploadFile(String originalName, List<int> bytes, String mime) async {
    setState(() => _isUploading = true);
    try {
      final safeName = 'Gasto_$originalName';
      final newFile = await _driveService.uploadReceipt(widget.year, widget.month, '', safeName, mime, bytes);
      if (mounted) {
        setState(() {
          _files.insert(0, newFile);
        });
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error subiendo: $e')));
      }
    } finally {
      if (mounted) setState(() => _isUploading = false);
    }
  }

  Future<void> _openFile(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  Future<void> _deleteFile(DriveFile file) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: const Color(0xFF1A1A2E),
        title: const Text('Eliminar archivo', style: TextStyle(color: Colors.white)),
        content: Text(
          '¿Eliminar "${file.filename}"?\nEsta acción no se puede deshacer.',
          style: const TextStyle(color: Colors.white70),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancelar'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Eliminar', style: TextStyle(color: Colors.redAccent)),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      await _driveService.deleteReceipt(file.id);
      if (mounted) {
        setState(() => _files.removeWhere((f) => f.id == file.id));
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Archivo eliminado ✓'), backgroundColor: Colors.green),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error al eliminar: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.only(top: 24, left: 24, right: 24, bottom: 40),
      height: MediaQuery.of(context).size.height * 0.7,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('Archivos de ${ExpenseProvider.monthNames[widget.month]}', style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
              IconButton(icon: const Icon(Icons.close, color: Colors.white), onPressed: () => Navigator.pop(context)),
            ],
          ),
          Text('Carpeta general del mes en Drive', style: TextStyle(color: Colors.white.withOpacity(0.5))),
          const SizedBox(height: 20),
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: _isUploading ? null : _uploadFromCamera,
                  icon: const Icon(Icons.camera_alt),
                  label: const Text('Hacer foto'),
                  style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF6C63FF), foregroundColor: Colors.white),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: _isUploading ? null : _uploadFromGallery,
                  icon: const Icon(Icons.folder),
                  label: const Text('Subir archivo'),
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.white.withOpacity(0.1), foregroundColor: Colors.white),
                ),
              ),
            ],
          ),
          const SizedBox(height: 20),
          if (_isUploading)
            const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF)))),
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF)))
                : _files.isEmpty
                    ? Center(child: Text('No hay archivos este mes.\nPuedes añadir tickets usando los botones de arriba.', textAlign: TextAlign.center, style: TextStyle(color: Colors.white.withOpacity(0.4))))
                    : ListView.builder(
                        itemCount: _files.length,
                        itemBuilder: (ctx, i) {
                          final f = _files[i];
                          final isPdf = f.type == 'pdf';
                          return ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: Icon(isPdf ? Icons.picture_as_pdf : Icons.image, color: isPdf ? Colors.redAccent : Colors.blueAccent),
                            title: Text(f.filename, style: const TextStyle(color: Colors.white), maxLines: 1, overflow: TextOverflow.ellipsis),
                            subtitle: Text('${f.sizeText} • ${f.date}', style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 12)),
                            trailing: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                IconButton(
                                  icon: const Icon(Icons.open_in_new_rounded, color: Colors.white70),
                                  tooltip: 'Abrir',
                                  onPressed: () => _openFile(f.url),
                                ),
                                IconButton(
                                  icon: const Icon(Icons.delete_outline_rounded, color: Colors.redAccent),
                                  tooltip: 'Eliminar',
                                  onPressed: () => _deleteFile(f),
                                ),
                              ],
                            ),
                          );
                        },
                      ),
          ),
        ],
      ),
    );
  }
}
