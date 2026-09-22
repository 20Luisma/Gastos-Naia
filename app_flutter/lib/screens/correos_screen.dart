import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:intl/intl.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter_linkify/flutter_linkify.dart';
import '../providers/email_provider.dart';
import '../models/email.dart';
import '../config/secrets.dart';

class CorreosScreen extends StatefulWidget {
  const CorreosScreen({super.key});
  @override
  State<CorreosScreen> createState() => _CorreosScreenState();
}

class _CorreosScreenState extends State<CorreosScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final p = context.read<EmailProvider>();
      if (p.emails.isEmpty) p.loadEmails();
    });
  }

  Future<void> _openAttachment(String relativeUrl) async {
    final baseUrl = Secrets.backendUrl.endsWith('/')
        ? Secrets.backendUrl.substring(0, Secrets.backendUrl.length - 1)
        : Secrets.backendUrl;
    final path = relativeUrl.startsWith('/') ? relativeUrl : '/$relativeUrl';
    final uri = Uri.parse('$baseUrl$path');

    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  void _showEmailDetail(BuildContext context, Email email) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF1A1A2E),
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).padding.bottom,
            top: 24,
            left: 20,
            right: 20),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.85,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Expanded(
                    child: Text(
                      email.subject,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.bold),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close, color: Colors.white54),
                    onPressed: () => Navigator.pop(ctx),
                  )
                ],
              ),
              const SizedBox(height: 8),
              Text(
                'De: ${email.from}',
                style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 13),
              ),
              Text(
                'Fecha: ${email.date}',
                style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 12),
              ),
              const SizedBox(height: 16),
              const Divider(color: Colors.white12),
              const SizedBox(height: 12),
              Expanded(
                child: SingleChildScrollView(
                  child: SelectableLinkify(
                    text: email.body.isEmpty ? '(Sin contenido)' : email.body.replaceAll('\r', ''),
                    onOpen: (link) async {
                      final Uri uri = Uri.parse(link.url);
                      if (await canLaunchUrl(uri)) {
                        await launchUrl(uri, mode: LaunchMode.externalApplication);
                      }
                    },
                    style: const TextStyle(color: Colors.white, fontSize: 15, height: 1.4),
                    linkStyle: const TextStyle(color: Color(0xFF3B82F6), decoration: TextDecoration.underline),
                  ),
                ),
              ),
              if (email.attachments.isNotEmpty) ...[
                const SizedBox(height: 16),
                const Text('Archivos adjuntos:',
                    style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                SizedBox(
                  height: 60,
                  child: ListView.builder(
                    scrollDirection: Axis.horizontal,
                    itemCount: email.attachments.length,
                    itemBuilder: (ctx, i) {
                      final att = email.attachments[i];
                      return GestureDetector(
                        onTap: () => _openAttachment(att.url),
                        child: Container(
                          margin: const EdgeInsets.only(right: 12),
                          padding: const EdgeInsets.symmetric(
                              horizontal: 16, vertical: 10),
                          decoration: BoxDecoration(
                            color: Colors.white.withOpacity(0.08),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(Icons.attach_file,
                                  color: Color(0xFF6C63FF), size: 18),
                              const SizedBox(width: 8),
                              Text(
                                att.filename.length > 20
                                    ? '${att.filename.substring(0, 15)}...${att.type}'
                                    : att.filename,
                                style: const TextStyle(
                                    color: Colors.white, fontSize: 13),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
                const SizedBox(height: 16),
              ],
              const SizedBox(height: 8),
              const Divider(color: Colors.white12),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: ElevatedButton.icon(
                      onPressed: () {
                        Navigator.pop(ctx);
                        final subj = email.subject.toLowerCase().startsWith('re:') ? email.subject : 'Re: ${email.subject}';
                        final b = '\\n\\n\\n--- En respuesta a ---\\nFecha: ${email.date}\\nDe: ${email.from}\\n\\n${email.body}';
                        _showComposeModal(context, type: 'reply', replyId: email.id, initialSubject: subj, initialBody: b);
                      },
                      icon: const Icon(Icons.reply_rounded, size: 18),
                      label: const Text('Responder'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF6C63FF),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: ElevatedButton.icon(
                      onPressed: () {
                        Navigator.pop(ctx);
                        final subj = email.subject.toLowerCase().startsWith('fwd:') ? email.subject : 'Fwd: ${email.subject}';
                        final b = '\\n\\n\\n--- Mensaje reenviado ---\\nFecha: ${email.date}\\nDe: ${email.from}\\n\\n${email.body}';
                        _showComposeModal(context, type: 'forward', replyId: email.id, initialSubject: subj, initialBody: b);
                      },
                      icon: const Icon(Icons.forward_to_inbox_rounded, size: 18),
                      label: const Text('Reenviar'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.white.withOpacity(0.1),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
            ],
          ),
        ),
      ),
    );
  }

  void _showComposeModal(BuildContext context, {String type = 'new', String replyId = '', String initialSubject = '', String initialBody = ''}) {
    final toCtrl = TextEditingController(text: 'ireneriv_1976@hotmail.com');
    final subjCtrl = TextEditingController(text: initialSubject);
    final bodyCtrl = TextEditingController(text: initialBody);
    List<String> attachments = [];
    bool isSending = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF1A1A2E),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Padding(
          padding: EdgeInsets.only(
              bottom: MediaQuery.of(ctx).viewInsets.bottom + MediaQuery.of(context).padding.bottom + 30,
              left: 20, right: 20, top: 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    type == 'new' ? 'Nuevo Correo' : (type == 'reply' ? 'Responder' : 'Reenviar'),
                    style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close, color: Colors.white54),
                    onPressed: () => Navigator.pop(ctx),
                  )
                ],
              ),
              const SizedBox(height: 10),
              TextField(
                controller: toCtrl,
                style: const TextStyle(color: Colors.white),
                decoration: _inputDeco('Para'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: subjCtrl,
                style: const TextStyle(color: Colors.white),
                decoration: _inputDeco('Asunto'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: bodyCtrl,
                style: const TextStyle(color: Colors.white),
                decoration: _inputDeco('Mensaje').copyWith(alignLabelWithHint: true),
                maxLines: 6,
                minLines: 4,
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  OutlinedButton.icon(
                    onPressed: () async {
                      final result = await FilePicker.platform.pickFiles(allowMultiple: true);
                      if (result != null) {
                        setModalState(() {
                          attachments.addAll(result.paths.where((p) => p != null).cast<String>());
                        });
                      }
                    },
                    icon: const Icon(Icons.attach_file, size: 18, color: Color(0xFF6C63FF)),
                    label: const Text('Adjuntar', style: TextStyle(color: Color(0xFF6C63FF))),
                    style: OutlinedButton.styleFrom(
                      side: const BorderSide(color: Color(0xFF6C63FF)),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      attachments.isEmpty ? 'Sin adjuntos' : '${attachments.length} archivo(s)',
                      style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 13),
                      textAlign: TextAlign.end,
                    ),
                  )
                ],
              ),
              const SizedBox(height: 20),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF6C63FF),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
                  onPressed: isSending
                      ? null
                      : () async {
                          if (subjCtrl.text.trim().isEmpty || bodyCtrl.text.trim().isEmpty) return;
                          setModalState(() => isSending = true);
                          
                          final p = context.read<EmailProvider>();
                          final success = await p.sendEmail(
                            type: type,
                            subject: subjCtrl.text.trim(),
                            body: bodyCtrl.text.trim(),
                            to: toCtrl.text.trim(),
                            replyToId: replyId,
                            filePaths: attachments,
                          );
                          
                          if (ctx.mounted) {
                            Navigator.pop(ctx);
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(success ? 'Correo enviado correctamente ✓' : 'Error al enviar'),
                                backgroundColor: success ? Colors.green : Colors.red,
                              ),
                            );
                            if (success) {
                              p.loadEmails(); // Refresh list to show sent email
                            }
                          }
                        },
                  child: isSending
                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                      : const Text('Enviar Correo', style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                ),
              ),
              const SizedBox(height: 20),
            ],
          ),
        ),
      ),
    );
  }

  InputDecoration _inputDeco(String label) {
    return InputDecoration(
      labelText: label,
      labelStyle: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 13),
      filled: true,
      fillColor: Colors.white.withOpacity(0.05),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFF6C63FF))),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
    );
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<EmailProvider>();

    return Scaffold(
      backgroundColor: const Color(0xFF0F0F1A),
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text('Correos Sincronizados',
            style: TextStyle(
                color: Colors.white,
                fontSize: 20,
                fontWeight: FontWeight.bold)),
      ),
      body: RefreshIndicator(
        color: const Color(0xFF6C63FF),
        onRefresh: provider.loadEmails,
        child: _buildBody(provider),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showComposeModal(context, type: 'new'),
        backgroundColor: const Color(0xFF6C63FF),
        icon: const Icon(Icons.edit_rounded, color: Colors.white),
        label: const Text('Nuevo', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
      ),
    );
  }

  Widget _buildBody(EmailProvider provider) {
    if (provider.isLoading && provider.emails.isEmpty) {
      return const Center(
          child: CircularProgressIndicator(color: Color(0xFF6C63FF)));
    }

    if (provider.error != null && provider.emails.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline_rounded,
                  color: Colors.red, size: 48),
              const SizedBox(height: 12),
              Text(provider.error!,
                  style: const TextStyle(color: Colors.red),
                  textAlign: TextAlign.center),
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed: provider.loadEmails,
                style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF6C63FF)),
                child: const Text('Reintentar', style: TextStyle(color: Colors.white)),
              ),
            ],
          ),
        ),
      );
    }

    if (provider.emails.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.mark_email_read_outlined,
                color: Colors.white.withOpacity(0.2), size: 64),
            const SizedBox(height: 12),
            Text('No hay correos sincronizados',
                style: TextStyle(color: Colors.white.withOpacity(0.4))),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.only(bottom: 100, top: 8),
      itemCount: provider.emails.length,
      itemBuilder: (ctx, i) {
        final email = provider.emails[i];
        final isOut = email.direction == 'out';
        final isUnread = !isOut && !email.isRead;

        // Use discreet colors that differentiate well
        final bgColor = isOut 
            ? const Color(0xFF1E293B) // Discreet dark blue for sent
            : (isUnread ? const Color(0xFF2D2A4A) : const Color(0xFF1A1A2E)); // distinct purple tint for unread, dark for read
            
        final borderColor = isOut 
            ? const Color(0xFF3B82F6).withOpacity(0.3) 
            : (isUnread ? const Color(0xFF6C63FF).withOpacity(0.4) : Colors.white.withOpacity(0.05));

        return GestureDetector(
          onTap: () {
            if (isUnread) {
              provider.markAsRead(email);
            }
            _showEmailDetail(context, email);
          },
          child: Container(
            margin: const EdgeInsets.symmetric(vertical: 8, horizontal: 16),
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: bgColor,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: borderColor),
              boxShadow: isUnread 
                  ? [BoxShadow(color: const Color(0xFF6C63FF).withOpacity(0.1), blurRadius: 8, spreadRadius: 1)] 
                  : [],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center, // Centrafas como antes
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(
                      isOut ? Icons.call_made_rounded : (isUnread ? Icons.mark_email_unread_rounded : Icons.call_received_rounded),
                      color: isOut ? const Color(0xFF3B82F6).withOpacity(0.8) : (isUnread ? const Color(0xFF6C63FF).withOpacity(0.9) : Colors.white.withOpacity(0.4)),
                      size: 16,
                    ),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Text(
                        isOut ? 'Para: ${email.to}' : 'De: ${email.from}',
                        style: TextStyle(
                            color: Colors.white.withOpacity(0.7),
                            fontSize: 13,
                            fontWeight: FontWeight.w500),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: TextAlign.center,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  _formatDate(email.date),
                  style: TextStyle(
                      color: Colors.white.withOpacity(0.4),
                      fontSize: 11),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 12),
                Wrap(
                  alignment: WrapAlignment.center,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (isUnread)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        margin: const EdgeInsets.only(right: 8),
                        decoration: BoxDecoration(
                          color: const Color(0xFF6C63FF), // Primary color for "NUEVO"
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text('NUEVO', style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold)),
                      ),
                    Text(
                      email.subject.isEmpty ? '(Sin asunto)' : email.subject,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 16,
                          fontWeight: FontWeight.w600),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Text(
                  email.body.replaceAll('\r', '').replaceAll('\n', ' '),
                  style: TextStyle(
                      color: Colors.white.withOpacity(0.5), fontSize: 13),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                ),
                if (email.attachments.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.attachment_rounded,
                          color: Color(0xFF3B82F6), size: 14),
                      const SizedBox(width: 4),
                      Text(
                        '${email.attachments.length} adjunto(s)',
                        style: const TextStyle(
                            color: Color(0xFF3B82F6),
                            fontSize: 12,
                            fontWeight: FontWeight.w500),
                      ),
                    ],
                  )
                ]
              ],
            ),
          ),
        );
      },
    );
  }

  String _formatDate(String dateStr) {
    try {
      final dt = DateTime.parse(dateStr);
      final formatter = DateFormat('dd/MM HH:mm');
      return formatter.format(dt);
    } catch (_) {
      return dateStr.length > 16 ? dateStr.substring(0, 16) : dateStr;
    }
  }
}
