import 'dart:async';
import 'package:flutter/material.dart';
import '../models/expense.dart';
import '../services/google_sheets_service.dart';

class ExpenseProvider extends ChangeNotifier {
  final _sheetsService = GoogleSheetsService();

  int _selectedYear = DateTime.now().year;
  int _selectedMonth = DateTime.now().month;
  List<Expense> _expenses = [];
  bool _isLoading = false;
  String? _error;
  double _pension = 0.0;

  int get selectedYear => _selectedYear;
  int get selectedMonth => _selectedMonth;
  List<Expense> get expenses => List.unmodifiable(_expenses);
  bool get isLoading => _isLoading;
  String? get error => _error;
  double get pension => _pension;

  double get totalMonth => _expenses.fold(0.0, (s, e) => s + e.amount);
  double get halfTotal => totalMonth / 2;

  static const List<String> monthNames = [
    '', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
  ];

  static List<int> get availableYears =>
      [2020, 2021, 2022, 2023, 2024, 2025, 2026, 2027];

  void selectYear(int year) {
    if (_selectedYear == year) return;
    _selectedYear = year;
    loadExpenses();
  }

  void selectMonth(int month) {
    if (_selectedMonth == month) return;
    _selectedMonth = month;
    loadExpenses();
  }

  Future<void> loadExpenses() async {
    _isLoading = true;
    _error = null;
    notifyListeners();
    try {
      _expenses = await _sheetsService.getExpenses(_selectedYear, _selectedMonth);
      _pension = await _sheetsService.getPension(_selectedYear, _selectedMonth);
    } on TimeoutException {
      _error = 'Sin conexión o el servidor tardó demasiado.\nComprueba tu red y pulsa Reintentar.';
      _expenses = [];
    } catch (e) {
      _error = e.toString();
      _expenses = [];
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<bool> addExpense(String date, String description, double amount) async {
    try {
      await _sheetsService.addExpense(_selectedYear, _selectedMonth, date, description, amount);
      await loadExpenses();
      return true;
    } catch (e) {
      _error = e.toString();
      notifyListeners();
      return false;
    }
  }

  Future<bool> editExpense(Expense expense, String date, String description, double amount) async {
    try {
      await _sheetsService.editExpense(
          _selectedYear, _selectedMonth, expense.row, date, description, amount);
      await loadExpenses();
      return true;
    } catch (e) {
      _error = e.toString();
      notifyListeners();
      return false;
    }
  }

  Future<bool> deleteExpense(Expense expense) async {
    try {
      await _sheetsService.deleteExpense(_selectedYear, _selectedMonth, expense.row);
      await loadExpenses();
      return true;
    } catch (e) {
      _error = e.toString();
      notifyListeners();
      return false;
    }
  }

  Future<bool> savePension(double amount) async {
    try {
      await _sheetsService.setPension(_selectedYear, _selectedMonth, amount);
      _pension = amount;
      notifyListeners();
      return true;
    } catch (e) {
      _error = e.toString();
      notifyListeners();
      return false;
    }
  }
}
