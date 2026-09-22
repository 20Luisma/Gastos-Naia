import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';
import '../config/secrets.dart';
import '../config/service_account.dart';
import 'package:googleapis_auth/auth_io.dart';

class DriveFile {
  final String id;
  final String filename;
  final String url;
  final String sizeText;
  final String date;
  final String type;

  DriveFile({
    required this.id,
    required this.filename,
    required this.url,
    required this.sizeText,
    required this.date,
    required this.type,
  });

  factory DriveFile.fromJson(Map<String, dynamic> json) {
    final sizeBytes = int.tryParse(json['size']?.toString() ?? '0') ?? 0;
    String sizeText = '${(sizeBytes / 1024).toStringAsFixed(1)} KB';
    if (sizeBytes > 1024 * 1024) {
      sizeText = '${(sizeBytes / (1024 * 1024)).toStringAsFixed(2)} MB';
    }

    final ext = json['fileExtension']?.toString() ?? 'unknown';
    
    // Format date from "2024-01-15T10:30:00.000Z" to "15/01/2024 10:30"
    String dateStr = '';
    try {
      if (json['createdTime'] != null) {
        final dt = DateTime.parse(json['createdTime']).toLocal();
        dateStr = '${dt.day.toString().padLeft(2, '0')}/${dt.month.toString().padLeft(2, '0')}/${dt.year} ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
      }
    } catch (_) {}

    return DriveFile(
      id: json['id'] ?? '',
      filename: json['name'] ?? '',
      url: json['webViewLink'] ?? '',
      sizeText: sizeText,
      date: dateStr,
      type: ext.toLowerCase(),
    );
  }
}

class GoogleDriveService {
  String? _accessToken;
  DateTime? _tokenExpiry;

  static const monthNames = [
    '', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
  ];

  Future<String> _getAccessToken() async {
    if (_accessToken != null && _tokenExpiry != null && DateTime.now().isBefore(_tokenExpiry!)) {
      return _accessToken!;
    }
    final accountCredentials = ServiceAccountCredentials.fromJson(ServiceAccount.json);
    final scopes = [
      'https://www.googleapis.com/auth/spreadsheets',
      'https://www.googleapis.com/auth/drive'
    ];
    final client = await clientViaServiceAccount(accountCredentials, scopes);
    
    _accessToken = client.credentials.accessToken.data;
    _tokenExpiry = client.credentials.accessToken.expiry.subtract(const Duration(seconds: 60));
    
    client.close();
    return _accessToken!;
  }

  Future<Map<String, String>> _headers() async {
    final token = await _getAccessToken();
    return {
      'Authorization': 'Bearer $token',
      'Content-Type': 'application/json',
    };
  }

  /// Replicates getOrCreateFolder from PHP
  Future<String> _getOrCreateMonthFolder(int year, int month) async {
    final yearFolderId = Secrets.driveFolders[year]?.toString();
    if (yearFolderId == null) throw Exception('No folder defined for year $year');

    final monthName = monthNames[month];
    final headers = await _headers();

    // 1. Search for existing folder
    final query = "'$yearFolderId' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    final searchUrl = Uri.parse('https://www.googleapis.com/drive/v3/files?q=${Uri.encodeComponent(query)}&fields=files(id,name)');
    
    final searchResp = await http.get(searchUrl, headers: headers);
    if (searchResp.statusCode == 200) {
      final data = jsonDecode(searchResp.body);
      final files = data['files'] as List? ?? [];
      
      // Flexible regex match exactly like PHP: "1) Recibos Enero 2024"
      final regex = RegExp(r'^\s*' + month.toString() + r'\)\s*Recibos\s+' + monthName + r'\s+' + year.toString() + r'\s*$', caseSensitive: false);
      
      for (final f in files) {
        if (regex.hasMatch(f['name'].toString())) {
          return f['id'].toString();
        }
      }
    }

    // 2. Create if not found
    final canonicalName = '$month) Recibos $monthName $year';
    final createUrl = Uri.parse('https://www.googleapis.com/drive/v3/files?fields=id');
    final createBody = jsonEncode({
      'name': canonicalName,
      'parents': [yearFolderId],
      'mimeType': 'application/vnd.google-apps.folder'
    });

    final createResp = await http.post(createUrl, headers: headers, body: createBody);
    if (createResp.statusCode == 200) {
      return jsonDecode(createResp.body)['id'].toString();
    }
    
    throw Exception('Could not create folder: ${createResp.body}');
  }

  Future<List<DriveFile>> listReceipts(int year, int month) async {
    try {
      final folderId = await _getOrCreateMonthFolder(year, month);
      final headers = await _headers();
      
      final query = "'$folderId' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'";
      final url = Uri.parse('https://www.googleapis.com/drive/v3/files?q=${Uri.encodeComponent(query)}&fields=files(id,name,webViewLink,size,createdTime,fileExtension,mimeType)');
      
      final resp = await http.get(url, headers: headers);
      if (resp.statusCode != 200) return [];

      final data = jsonDecode(resp.body);
      final files = data['files'] as List? ?? [];
      
      return files.map((f) => DriveFile.fromJson(f as Map<String, dynamic>)).toList();
    } catch (_) {
      return [];
    }
  }

  /// Sube el recibo a través del backend PHP (que usa OAuth personal)
  /// para evitar el error storageQuotaExceeded del Service Account.
  Future<DriveFile> uploadReceipt(int year, int month, String localFilePath, String originalName, String mimeType, List<int> fileBytes) async {
    final url = Uri.parse('${Secrets.backendUrl}/index.php?action=upload');

    final request = http.MultipartRequest('POST', url);
    request.headers['X-API-Key'] = Secrets.webhookSecret;
    request.fields['year'] = year.toString();
    request.fields['month'] = month.toString();
    request.files.add(http.MultipartFile.fromBytes(
      'file',
      fileBytes,
      filename: originalName,
      contentType: MediaType.parse(mimeType),
    ));

    final streamedResponse = await request.send();
    final response = await http.Response.fromStream(streamedResponse);

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      if (json['error'] != null) {
        throw Exception('Upload failed: ${json['error']}');
      }
      // Map backend response to DriveFile
      final sizeBytes = json['sizeBytes'] as int? ?? 0;
      String sizeText = '${(sizeBytes / 1024).toStringAsFixed(1)} KB';
      if (sizeBytes > 1024 * 1024) {
        sizeText = '${(sizeBytes / (1024 * 1024)).toStringAsFixed(2)} MB';
      }
      return DriveFile(
        id: json['id'] ?? '',
        filename: json['filename'] ?? originalName,
        url: json['url'] ?? '',
        sizeText: json['size_text'] ?? sizeText,
        date: json['date'] ?? '',
        type: json['type'] ?? 'unknown',
      );
    } else {
      final body = jsonDecode(response.body);
      throw Exception('Upload failed: ${body['error'] ?? response.body}');
    }
  }

  Future<void> deleteReceipt(String fileId) async {
    final url = Uri.parse('${Secrets.backendUrl}/index.php?action=delete-file');
    final body = jsonEncode({'path': fileId});

    final resp = await http.post(
      url, 
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': Secrets.webhookSecret,
      }, 
      body: body
    );

    if (resp.statusCode != 200) {
      throw Exception('Delete failed: ${resp.body}');
    }
    
    final json = jsonDecode(resp.body);
    if (json['success'] != true) {
      throw Exception('Delete failed: Error en el servidor');
    }
  }
}
