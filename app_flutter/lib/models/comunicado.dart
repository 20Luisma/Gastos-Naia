import 'dart:convert';

class ComunicadoAttachment {
  final String url;
  final String name;
  final String type;

  ComunicadoAttachment({required this.url, required this.name, required this.type});

  factory ComunicadoAttachment.fromJson(Map<String, dynamic> json) {
    return ComunicadoAttachment(
      url: json['url']?.toString() ?? '',
      name: json['name']?.toString() ?? '',
      type: json['type']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toJson() => {
        'url': url,
        'name': name,
        'type': type,
      };
}

class Comunicado {
  final String id;
  final String title;
  final String description;
  final DateTime date;
  final String? fileUrl;
  final String? fileType;
  final String? fileName;
  final List<ComunicadoAttachment> attachments;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  Comunicado({
    required this.id,
    required this.title,
    this.description = '',
    required this.date,
    this.fileUrl,
    this.fileType,
    this.fileName,
    this.attachments = const [],
    this.createdAt,
    this.updatedAt,
  });

  factory Comunicado.fromJson(Map<String, dynamic> json) {
    return Comunicado(
      id: json['id'] as String? ?? json['key'] as String? ?? '', // Firebase RTDB key o id
      title: json['title'] as String? ?? '(Sin título)',
      description: json['description'] as String? ?? '',
      date: DateTime.tryParse(json['date']?.toString() ?? '')?.toLocal() ?? DateTime.now(),
      fileUrl: json['fileUrl'] as String?,
      fileType: json['fileType'] as String?,
      fileName: json['fileName'] as String?,
      attachments: json['attachments'] != null 
          ? (json['attachments'] as List).map((e) => ComunicadoAttachment.fromJson(e as Map<String, dynamic>)).toList() 
          : [],
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? '')?.toLocal(),
      updatedAt: DateTime.tryParse(json['updated_at']?.toString() ?? '')?.toLocal(),
    );
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> json = {
      'title': title,
      'description': description,
      'date': date.toIso8601String().split('T')[0], // YYYY-MM-DD
      'fileUrl': fileUrl,
      'fileType': fileType,
      'fileName': fileName,
      'attachments': attachments.map((a) => a.toJson()).toList(),
    };
    // Sólo incluir id si existe (edición). Para nuevos, PHP genera el uniqid.
    if (id.isNotEmpty) {
      json['id'] = id;
    }
    return json;
  }

  Comunicado copyWith({
    String? id,
    String? title,
    String? description,
    DateTime? date,
    String? fileUrl,
    String? fileType,
    String? fileName,
    List<ComunicadoAttachment>? attachments,
  }) {
    return Comunicado(
      id: id ?? this.id,
      title: title ?? this.title,
      description: description ?? this.description,
      date: date ?? this.date,
      fileUrl: fileUrl ?? this.fileUrl,
      fileType: fileType ?? this.fileType,
      fileName: fileName ?? this.fileName,
      attachments: attachments ?? this.attachments,
      createdAt: this.createdAt,
      updatedAt: this.updatedAt,
    );
  }
}
