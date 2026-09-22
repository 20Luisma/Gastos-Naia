import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/secrets.dart';
import '../config/service_account.dart';
import 'package:googleapis_auth/auth_io.dart';
import '../models/expense.dart';

class GoogleSheetsService {
  String? _accessToken;
  DateTime? _tokenExpiry;

  /// Timeout global para todas las peticiones de red
  static const _timeout = Duration(seconds: 15);

  static const _months = [
    '', 'Gastos Enero', 'Gastos Febrero', 'Gastos Marzo', 'Gastos Abril',
    'Gastos Mayo', 'Gastos Junio', 'Gastos Julio', 'Gastos Agosto',
    'Gastos Septiembre', 'Gastos Octubre', 'Gastos Noviembre', 'Gastos Diciembre'
  ];

  static const monthNames = [
    '', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
  ];

  String? _spreadsheetId(int year) => Secrets.spreadsheets[year]?.toString();

  // ─── Auth ────────────────────────────────────────────────────────────────────

  Future<String> _getAccessToken() async {
    if (_accessToken != null &&
        _tokenExpiry != null &&
        DateTime.now().isBefore(_tokenExpiry!)) {
      return _accessToken!;
    }
    final accountCredentials = ServiceAccountCredentials.fromJson(ServiceAccount.json);
    final scopes = [
      'https://www.googleapis.com/auth/spreadsheets',
      'https://www.googleapis.com/auth/drive'
    ];
    final client = await clientViaServiceAccount(accountCredentials, scopes);
    
    _accessToken = client.credentials.accessToken.data;
    // Set expiry minus 60s for safety buffer
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

  // ─── Cache Invalidation ──────────────────────────────────────────────────────

  Future<void> _triggerCacheInvalidation() async {
    try {
      final url = Uri.parse('${Secrets.backendUrl}/?action=clear_cache');
      // Fire and forget, we don't await/block the UI for this
      http.post(
        url,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'secret': Secrets.webhookSecret}),
      ).timeout(const Duration(seconds: 5)).catchError((_) => http.Response('', 500));
    } catch (_) {
      // Ignoramos errores de red en la invalidación para no romper el flujo
    }
  }

  // ─── Helpers ─────────────────────────────────────────────────────────────────

  List<Map<String, dynamic>> _emptyMonths() => List.generate(
      12, (i) => {'month': i + 1, 'name': monthNames[i + 1], 'total': 0.0});

  double _parseMoney(dynamic raw) {
    if (raw is num) return raw.toDouble();
    var s = raw.toString().replaceAll(RegExp(r'[^\d.,]'), '').trim();
    if (RegExp(r'^\d{1,3}(\.\d{3})*(,\d{1,2})?$').hasMatch(s)) {
      s = s.replaceAll('.', '').replaceAll(',', '.');
    } else if (RegExp(r'^\d+(,\d{1,2})$').hasMatch(s)) {
      s = s.replaceAll(',', '.');
    }
    return double.tryParse(s) ?? 0.0;
  }

  bool _isSubtotalRow(String a, String b) {
    final combined = '$a $b'.toLowerCase();
    return combined.contains('total') ||
        combined.contains('pensión') ||
        combined.contains('pension');
  }

  // ─── Expenses ────────────────────────────────────────────────────────────────

  Future<List<Expense>> getExpenses(int year, int month) async {
    final sheetId = _spreadsheetId(year);
    if (sheetId == null) return [];

    final sheetName = _months[month];
    final range = Uri.encodeComponent('$sheetName!A2:C200');
    final url =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$range';

    final resp = await http.get(Uri.parse(url), headers: await _headers()).timeout(_timeout);
    if (resp.statusCode != 200) {
      throw Exception('Error leyendo Sheets: ${resp.body}');
    }

    final data = jsonDecode(resp.body);
    final rows = (data['values'] as List?) ?? [];
    final expenses = <Expense>[];

    for (int i = 0; i < rows.length; i++) {
      final row = rows[i] as List;
      if (row.isEmpty) continue;
      final dateStr = row[0].toString();
      final desc = row.length > 1 ? row[1].toString() : '';
      final amountStr = row.length > 2 ? row[2].toString() : '0';
      if (dateStr.isEmpty && desc.isEmpty) continue;
      if (_isSubtotalRow(dateStr, desc)) break;

      expenses.add(Expense(
        id: '${year}_${month}_${i + 2}',
        date: dateStr,
        description: desc,
        amount: _parseMoney(amountStr),
        year: year,
        month: month,
        row: i + 2,
      ));
    }
    return expenses;
  }



  Future<void> addExpense(
      int year, int month, String date, String description, double amount) async {
    final sheetId = _spreadsheetId(year);
    if (sheetId == null) throw Exception('Año $year no configurado.');
    final sheetName = _months[month];

    // 1. Añadir el gasto usando APPEND, igual que PHP
    final range = Uri.encodeComponent('$sheetName!A:C');
    final appendUrl =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$range:append'
        '?valueInputOption=USER_ENTERED&insertDataOption=OVERWRITE';

    // Sheet con locale de España espera los decimales con coma.
    // Si mandamos double (125.0) en USER_ENTERED lo pilla como texto.
    final amountWithComma = amount.toStringAsFixed(2).replaceAll('.', ',');

    final appendBody = jsonEncode({
      'range': '$sheetName!A:C',
      'values': [[date, description, amountWithComma]]
    });

    final appendResp = await http
        .post(Uri.parse(appendUrl), headers: await _headers(), body: appendBody)
        .timeout(_timeout);

    if (appendResp.statusCode != 200) {
      throw Exception('Error añadiendo gasto: ${appendResp.body}');
    }

    // 2. Limpieza exhaustiva de la tabla:
    // Para curarnos en salud (puesto que Sheets hereda el amarillo de la cabecera),
    // vamos a forzar CERO fondo (transparente) y formato MONEDA en TODO 
    // el bloque desde la fila 2 hasta la 500 para las columnas A-C.
    // De este modo, da igual dónde haya añadido el append la fila, siempre
    // caerá en una celda transparente y la C será formato €.
    final gid = await _getSheetGid(sheetId, sheetName);
    final formatUrl =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId:batchUpdate';
    final formatBody = jsonEncode({
      'requests': [
        {
          'repeatCell': {
            'range': {
              'sheetId': gid,
              'startRowIndex': 1,     // Fila 2
              'endRowIndex': 500,     // Hasta la 500
              'startColumnIndex': 2,  // Solo C
              'endColumnIndex': 3,
            },
            'cell': {
              'userEnteredFormat': {
                'numberFormat': {'type': 'CURRENCY'}, // Moneda
              }
            },
            'fields': 'userEnteredFormat.numberFormat',
          }
        }
      ]
    });
    
    // No bloqueamos si falla el formateo estético
    await http
        .post(Uri.parse(formatUrl), headers: await _headers(), body: formatBody)
        .timeout(_timeout);

    // 3. Avisar al backend PHP para que borre la caché
    _triggerCacheInvalidation();
  }



  Future<void> editExpense(int year, int month, int row, String date,
      String description, double amount) async {
    final sheetId = _spreadsheetId(year);
    if (sheetId == null) throw Exception('Año $year no configurado.');

    final sheetName = _months[month];
    final range = Uri.encodeComponent('$sheetName!A$row:C$row');
    final url =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$range'
        '?valueInputOption=USER_ENTERED';

    final amountWithComma = amount.toStringAsFixed(2).replaceAll('.', ',');

    final body = jsonEncode({
      'range': '$sheetName!A$row:C$row',
      'values': [[date, description, amountWithComma]]
    });

    final resp =
        await http.put(Uri.parse(url), headers: await _headers(), body: body).timeout(_timeout);
    if (resp.statusCode != 200) {
      throw Exception('Error editando gasto: ${resp.body}');
    }

    _triggerCacheInvalidation();
  }

  /// Obtiene el numeric sheetId (gid) de una pestaña por su título.
  Future<int> _getSheetGid(String sheetId, String sheetName) async {
    final metaUrl =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId?fields=sheets.properties';
    final metaResp =
        await http.get(Uri.parse(metaUrl), headers: await _headers()).timeout(_timeout);
    if (metaResp.statusCode != 200) {
      throw Exception('Error obteniendo metadatos del spreadsheet: ${metaResp.body}');
    }
    final metaData = jsonDecode(metaResp.body);
    final sheets = metaData['sheets'] as List;
    for (final s in sheets) {
      if (s['properties']['title'] == sheetName) {
        return s['properties']['sheetId'] as int;
      }
    }
    throw Exception('Hoja $sheetName no encontrada.');
  }

  Future<void> deleteExpense(int year, int month, int row) async {
    final sheetId = _spreadsheetId(year);
    if (sheetId == null) throw Exception('Año $year no configurado.');

    final sheetName = _months[month];
    final gid = await _getSheetGid(sheetId, sheetName);

    final url =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId:batchUpdate';
    final body = jsonEncode({
      'requests': [
        {
          'updateCells': {
            'range': {
              'sheetId': gid,
              'startRowIndex': row - 1,
              'endRowIndex': row,
              'startColumnIndex': 0,
              'endColumnIndex': 3, // Columns A, B, C
            },
            'fields': 'userEnteredValue', // only clear the value, keep formatting
          }
        }
      ]
    });

    final resp =
        await http.post(Uri.parse(url), headers: await _headers(), body: body).timeout(_timeout);
    if (resp.statusCode != 200) {
      throw Exception('Error eliminando gasto: ${resp.body}');
    }

    _triggerCacheInvalidation();
  }

  // ─── Monthly totals ──────────────────────────────────────────────────────────

  /// Calcula los totales mensuales usando batchGet (1 sola llamada a la API
  /// en lugar de 12), más rápido y sin riesgo de rate limiting.
  Future<List<Map<String, dynamic>>> getMonthlyTotals(int year) async {
    final sheetId = _spreadsheetId(year);
    if (sheetId == null) return _emptyMonths();

    // Construir los 12 ranges: "Gastos Enero!A1:C200", etc.
    final ranges = List.generate(12, (i) {
      final sheetName = Uri.encodeComponent('${_months[i + 1]}!A1:C200');
      return 'ranges=$sheetName';
    }).join('&');

    final url = 'https://sheets.googleapis.com/v4/spreadsheets/$sheetId'
        '/values:batchGet?$ranges&valueRenderOption=UNFORMATTED_VALUE';

    try {
      final resp = await http
          .get(Uri.parse(url), headers: await _headers())
          .timeout(_timeout);

      if (resp.statusCode != 200) return _emptyMonths();

      final data = jsonDecode(resp.body);
      final valueRanges = (data['valueRanges'] as List?) ?? [];

      final results = <Map<String, dynamic>>[];
      for (int m = 1; m <= 12; m++) {
        double total = 0.0;
        final rangeData = m - 1 < valueRanges.length ? valueRanges[m - 1] : null;
        final rows = (rangeData?['values'] as List?) ?? [];

        for (int i = 1; i < rows.length; i++) {
          final row = rows[i] as List;
          final cellA = row.isNotEmpty ? row[0].toString() : '';
          final cellB = row.length > 1 ? row[1].toString() : '';
          final cellC = row.length > 2 ? row[2] : null;

          if (cellA.isEmpty && cellB.isEmpty && cellC == null) continue;
          if (_isSubtotalRow(cellA, cellB)) break;

          total += _parseMoney(cellC ?? 0);
        }

        results.add({
          'month': m,
          'name': monthNames[m],
          'total': double.parse(total.toStringAsFixed(2)),
        });
      }
      return results;
    } catch (_) {
      return _emptyMonths();
    }
  }


  // ─── Annual totals ───────────────────────────────────────────────────────────

  /// PHP exact: searches ALL columns for 'Total Final:' and reads next column.
  Future<List<Map<String, dynamic>>> getAnnualTotals() async {
    final results = <Map<String, dynamic>>[];
    for (final year in Secrets.spreadsheets.keys.toList()..sort()) {
      final sheetId = _spreadsheetId(year);
      if (sheetId == null) continue;

      final range = Uri.encodeComponent('Gastos Anual!A1:Z200');
      final url =
          'https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$range'
          '?valueRenderOption=UNFORMATTED_VALUE';

      try {
        final resp = await http.get(Uri.parse(url), headers: await _headers()).timeout(_timeout);
        if (resp.statusCode != 200) continue;

        final data = jsonDecode(resp.body);
        final rows = (data['values'] as List?) ?? [];

        final foundTotals = <double>[];
        
        for (final row in rows) {
          final rowList = row as List;
          for (int col = 0; col < rowList.length; col++) {
            final cell = rowList[col].toString().trim();
            if (cell == 'Total Final:' || cell == 'Total Final') {
              final nextCol = col + 1;
              if (nextCol < rowList.length) {
                final amount = _parseMoney(rowList[nextCol]);
                if (amount > 0) {
                  foundTotals.add(amount);
                }
              }
            }
          }
        }
        
        if (foundTotals.isNotEmpty) {
          // Buscamos el mayor para asegurarnos de que coge el Total Final verdadero 
          // y no un subtotal parcial (como el "Total entre 2") que la API haya devuelto.
          double maxTotal = foundTotals.reduce((a, b) => a > b ? a : b);
          results.add({'year': year, 'total': maxTotal});
        }
      } catch (_) {
        continue;
      }
    }
    return results;
  }

  // ─── Pension ─────────────────────────────────────────────────────────────────

  /// PHP exact: scans D1:E200, finds row with 'Pensión' in col D,
  /// writes value to col E of that row.
  Future<void> setPension(int year, int month, double amount) async {
    final sheetId = _spreadsheetId(year);
    if (sheetId == null) throw Exception('Año $year no configurado.');

    final sheetName = _months[month];
    final range = Uri.encodeComponent('$sheetName!D1:E200');
    final readUrl =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$range'
        '?valueRenderOption=UNFORMATTED_VALUE';

    final resp = await http.get(Uri.parse(readUrl), headers: await _headers()).timeout(_timeout);
    if (resp.statusCode != 200) throw Exception('Error leyendo hoja.');

    final data = jsonDecode(resp.body);
    final rows = (data['values'] as List?) ?? [];

    int targetRow = -1;
    for (int i = 0; i < rows.length; i++) {
      final row = rows[i] as List;
      if (row.isEmpty) continue;
      final label = row[0].toString().toLowerCase().trim();
      if (label.contains('pensión') ||
          label.contains('pension') ||
          label.contains('pensio')) {
        targetRow = i + 1; // 1-indexed for Sheets API
        break;
      }
    }
    if (targetRow == -1) throw Exception('No se encontró la celda de Pensión.');

    final writeRange = Uri.encodeComponent('$sheetName!E$targetRow');
    final writeUrl =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$writeRange'
        '?valueInputOption=USER_ENTERED';
    final body = jsonEncode({
      'range': '$sheetName!E$targetRow',
      'values': [[amount]]
    });
    final writeResp =
        await http.put(Uri.parse(writeUrl), headers: await _headers(), body: body).timeout(_timeout);
    if (writeResp.statusCode != 200) {
      throw Exception('Error guardando pensión: ${writeResp.body}');
    }

    _triggerCacheInvalidation();
  }

  /// PHP exact: scans D1:E200, finds row with 'Pensión' in col D,
  /// returns value from col E of that row.
  Future<double> getPension(int year, int month) async {
    final sheetId = _spreadsheetId(year);
    if (sheetId == null) return 0.0;

    final sheetName = _months[month];
    final range = Uri.encodeComponent('$sheetName!D1:E200');
    final url =
        'https://sheets.googleapis.com/v4/spreadsheets/$sheetId/values/$range'
        '?valueRenderOption=UNFORMATTED_VALUE';

    try {
      final resp = await http.get(Uri.parse(url), headers: await _headers()).timeout(_timeout);
      if (resp.statusCode != 200) return 0.0;

      final data = jsonDecode(resp.body);
      final rows = (data['values'] as List?) ?? [];

      for (final row in rows) {
        final rowList = row as List;
        if (rowList.isEmpty) continue;
        final label = rowList[0].toString().toLowerCase().trim();
        if (label.contains('pensión') ||
            label.contains('pension') ||
            label.contains('pensio')) {
          if (rowList.length > 1) return _parseMoney(rowList[1]);
        }
      }
    } catch (_) {}
    return 0.0;
  }
}
