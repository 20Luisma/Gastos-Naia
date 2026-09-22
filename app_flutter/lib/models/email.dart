class EmailAttachment {
  final String filename;
  final String type;
  final String url;

  EmailAttachment({
    required this.filename,
    required this.type,
    required this.url,
  });

  factory EmailAttachment.fromJson(Map<String, dynamic> json) {
    return EmailAttachment(
      filename: json['filename']?.toString() ?? '',
      type: json['type']?.toString() ?? '',
      url: json['url']?.toString() ?? '',
    );
  }
}

class Email {
  final String fbKey;
  final String id;
  final String subject;
  final String from;
  final String to;
  final String direction; // 'in' or 'out'
  final String date;
  final int timestamp;
  final String body;
  final List<EmailAttachment> attachments;
  final bool isRead;

  Email({
    required this.fbKey,
    required this.id,
    required this.subject,
    required this.from,
    required this.to,
    required this.direction,
    required this.date,
    required this.timestamp,
    required this.body,
    required this.attachments,
    required this.isRead,
  });

  factory Email.fromJson(Map<String, dynamic> json) {
    var attachmentsList = json['attachments'] as List? ?? [];
    List<EmailAttachment> attachments = attachmentsList
        .map((a) => EmailAttachment.fromJson(Map<String, dynamic>.from(a)))
        .toList();

    return Email(
      fbKey: json['fbKey']?.toString() ?? '',
      id: json['id']?.toString() ?? '',
      subject: json['subject']?.toString() ?? '(Sin asunto)',
      from: json['from']?.toString() ?? '',
      to: json['to']?.toString() ?? '',
      direction: json['direction']?.toString() ?? 'in',
      date: json['date']?.toString() ?? '',
      timestamp: json['timestamp'] is int
          ? json['timestamp'] as int
          : int.tryParse(json['timestamp']?.toString() ?? '0') ?? 0,
      body: json['body']?.toString() ?? '',
      attachments: attachments,
      isRead: json['isRead'] ?? true,
    );
  }
}
