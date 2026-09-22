import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:intl/intl.dart';
import '../services/google_sheets_service.dart';
import '../config/secrets.dart';

class AnnualScreen extends StatefulWidget {
  const AnnualScreen({super.key});
  @override
  State<AnnualScreen> createState() => AnnualScreenState();
}

class AnnualScreenState extends State<AnnualScreen> {
  final _sheetsService = GoogleSheetsService();
  final _currency = NumberFormat.currency(locale: 'es_ES', symbol: '€');
  
  int _selectedYear = DateTime.now().year;
  List<Map<String, dynamic>> _monthlyData = [];
  List<Map<String, dynamic>> _annualData = [];
  bool _loadingMonthly = false;
  bool _loadingAnnual = false;
  bool _isAnnualView = false; // Toggle state

  @override
  void initState() {
    super.initState();
    _loadMonthly();
    _loadAnnual();
  }

  Future<void> _loadMonthly() async {
    setState(() => _loadingMonthly = true);
    try {
      _monthlyData = await _sheetsService.getMonthlyTotals(_selectedYear);
    } catch (e) {
      debugPrint('Error monthly: $e');
    } finally {
      setState(() => _loadingMonthly = false);
    }
  }

  Future<void> _loadAnnual() async {
    setState(() => _loadingAnnual = true);
    try {
      _annualData = await _sheetsService.getAnnualTotals();
    } catch (e) {
      debugPrint('Error annual: $e');
    } finally {
      setState(() => _loadingAnnual = false);
    }
  }

  void reload() {
    _loadMonthly();
    _loadAnnual();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F0F1A),
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text('Resumen', style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold)),
        actions: [
          if (!_isAnnualView)
            PopupMenuButton<int>(
              icon: const Icon(Icons.calendar_today_rounded, color: Colors.white70),
              color: const Color(0xFF1A1A2E),
              onSelected: (y) {
                setState(() => _selectedYear = y);
                _loadMonthly();
              },
              itemBuilder: (_) => Secrets.spreadsheets.keys.toList().reversed
                  .map((y) => PopupMenuItem(value: y, child: Text('$y', style: const TextStyle(color: Colors.white))))
                  .toList(),
            ),
        ],
      ),
      body: RefreshIndicator(
        color: const Color(0xFF6C63FF),
        onRefresh: () async => reload(),
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Toggle Button
              Center(
                child: Container(
                  decoration: BoxDecoration(
                    color: const Color(0xFF1A1A2E),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.white.withOpacity(0.07)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      _buildToggleButton(title: 'Mensual', isSelected: !_isAnnualView, onTap: () => setState(() => _isAnnualView = false)),
                      _buildToggleButton(title: 'Anual', isSelected: _isAnnualView, onTap: () => setState(() => _isAnnualView = true)),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 24),

              if (_isAnnualView) ...[
                _buildSectionTitle('📈 Evolución Anual'),
                const SizedBox(height: 12),
                _buildAnnualChart(),
                const SizedBox(height: 24),
                _buildSectionTitle('📊 Totales por año'),
                const SizedBox(height: 12),
                _buildAnnualTable(),
              ] else ...[
                _buildSectionTitle('📅 Gastos mensuales $_selectedYear'),
                const SizedBox(height: 12),
                _buildMonthlyChart(),
                const SizedBox(height: 16),
                if (!_loadingMonthly)
                  ...(_monthlyData.where((m) => (m['total'] as double) > 0).map((m) => _buildMonthCard(m))),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildToggleButton({required String title, required bool isSelected, required VoidCallback onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 10),
        decoration: BoxDecoration(
          color: isSelected ? const Color(0xFF6C63FF).withOpacity(0.2) : Colors.transparent,
          borderRadius: BorderRadius.circular(11),
        ),
        child: Text(
          title,
          style: TextStyle(
            color: isSelected ? const Color(0xFF6C63FF) : Colors.white70,
            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
          ),
        ),
      ),
    );
  }

  Widget _buildSectionTitle(String title) {
    return Text(title, style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w600));
  }

  Widget _buildAnnualTable() {
    if (_loadingAnnual) return const Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF)));
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF1A1A2E),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withOpacity(0.07)),
      ),
      child: Column(
        children: [
          ..._annualData.map((a) => Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('${a['year']}', style: const TextStyle(color: Colors.white70, fontSize: 15)),
                    Text(_currency.format(a['total']), style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold)),
                  ],
                ),
              )),
          if (_annualData.isNotEmpty) ...[
            Divider(color: Colors.white.withOpacity(0.07)),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Total acumulado', style: TextStyle(color: Color(0xFF6C63FF), fontWeight: FontWeight.bold)),
                  Text(
                    _currency.format(_annualData.fold(0.0, (s, a) => s + (a['total'] as double))),
                    style: const TextStyle(color: Color(0xFF6C63FF), fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildAnnualChart() {
    if (_loadingAnnual) return const Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF)));
    if (_annualData.isEmpty) return const SizedBox.shrink();

    final maxTotal = _annualData.fold(0.0, (m, a) => (a['total'] as double) > m ? (a['total'] as double) : m);
    
    final bars = _annualData.asMap().entries.map((e) {
      final total = e.value['total'] as double;
      // Assign specific colors for years to match the web app
      final colors = [
        const Color(0xFF3B82F6), // Blue
        const Color(0xFF8B5CF6), // Purple
        const Color(0xFFD946EF), // Fuchsia
        const Color(0xFFF43F5E), // Rose
        const Color(0xFFF59E0B), // Amber
        const Color(0xFF10B981), // Emerald
        const Color(0xFF06B6D4), // Cyan
      ];
      final color = colors[e.key % colors.length];

      return BarChartGroupData(
        x: e.key,
        barRods: [
          BarChartRodData(
            toY: total,
            color: color,
            width: 20,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(6)),
            backDrawRodData: BackgroundBarChartRodData(
              show: true,
              toY: maxTotal,
              color: Colors.white.withOpacity(0.05),
            ),
          )
        ],
      );
    }).toList();

    return Container(
      height: 250,
      padding: const EdgeInsets.fromLTRB(8, 24, 16, 8),
      decoration: BoxDecoration(
        color: const Color(0xFF1A1A2E),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withOpacity(0.07)),
      ),
      child: BarChart(
        BarChartData(
          barGroups: bars,
          gridData: FlGridData(
            show: true, 
            drawVerticalLine: false,
            getDrawingHorizontalLine: (value) => FlLine(color: Colors.white.withOpacity(0.05), strokeWidth: 1),
          ),
          borderData: FlBorderData(show: false),
          titlesData: FlTitlesData(
            bottomTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                getTitlesWidget: (v, _) {
                  final i = v.toInt();
                  if (i < 0 || i >= _annualData.length) return const SizedBox.shrink();
                  return Padding(
                    padding: const EdgeInsets.only(top: 8.0),
                    child: Text('${_annualData[i]['year']}', style: TextStyle(color: Colors.white.withOpacity(0.6), fontSize: 11, fontWeight: FontWeight.bold)),
                  );
                },
              ),
            ),
            leftTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 50,
                getTitlesWidget: (v, max) {
                  if (v == 0 || v == max.max) return const SizedBox.shrink();
                  return Text('${v.toInt()}€', style: TextStyle(color: Colors.white.withOpacity(0.4), fontSize: 10));
                },
              ),
            ),
            topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
          ),
        ),
      ),
    );
  }

  Widget _buildMonthlyChart() {
    if (_loadingMonthly) return const Center(child: CircularProgressIndicator(color: Color(0xFF6C63FF)));
    if (_monthlyData.isEmpty) return const SizedBox.shrink();

    final bars = _monthlyData.asMap().entries.map((e) {
      final total = (e.value['total'] as double);
      return BarChartGroupData(
        x: e.key,
        barRods: [
          BarChartRodData(
            toY: total,
            gradient: const LinearGradient(colors: [Color(0xFF6C63FF), Color(0xFF3B82F6)], begin: Alignment.bottomCenter, end: Alignment.topCenter),
            width: 16,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(6)),
          )
        ],
      );
    }).toList();

    return Container(
      height: 200,
      padding: const EdgeInsets.fromLTRB(8, 16, 16, 8),
      decoration: BoxDecoration(
        color: const Color(0xFF1A1A2E),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withOpacity(0.07)),
      ),
      child: BarChart(
        BarChartData(
          barGroups: bars,
          gridData: FlGridData(show: false),
          borderData: FlBorderData(show: false),
          titlesData: FlTitlesData(
            bottomTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                getTitlesWidget: (v, _) {
                  const labels = ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
                  final i = v.toInt();
                  if (i < 0 || i >= labels.length) return const SizedBox.shrink();
                  return Text(labels[i], style: TextStyle(color: Colors.white.withOpacity(0.4), fontSize: 11));
                },
              ),
            ),
            leftTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
          ),
        ),
      ),
    );
  }

  Widget _buildMonthCard(Map<String, dynamic> monthData) {
    final total = monthData['total'] as double;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: const Color(0xFF1A1A2E),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(0.07)),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(monthData['name'] as String, style: const TextStyle(color: Colors.white, fontSize: 14)),
          Text(_currency.format(total), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}
