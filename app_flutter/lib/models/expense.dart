class Expense {
  final String id;
  final String date;
  final String description;
  final double amount;
  final int year;
  final int month;
  final int row; // row number in Google Sheets (for edit/delete)

  Expense({
    required this.id,
    required this.date,
    required this.description,
    required this.amount,
    required this.year,
    required this.month,
    this.row = 0,
  });

  Expense copyWith({
    String? id,
    String? date,
    String? description,
    double? amount,
    int? year,
    int? month,
    int? row,
  }) {
    return Expense(
      id: id ?? this.id,
      date: date ?? this.date,
      description: description ?? this.description,
      amount: amount ?? this.amount,
      year: year ?? this.year,
      month: month ?? this.month,
      row: row ?? this.row,
    );
  }

  factory Expense.fromMap(Map<String, dynamic> map) {
    return Expense(
      id: map['id'] ?? '',
      date: map['date'] ?? '',
      description: map['description'] ?? '',
      amount: (map['amount'] as num?)?.toDouble() ?? 0.0,
      year: map['year'] ?? DateTime.now().year,
      month: map['month'] ?? DateTime.now().month,
      row: map['row'] ?? 0,
    );
  }

  Map<String, dynamic> toMap() => {
        'id': id,
        'date': date,
        'description': description,
        'amount': amount,
        'year': year,
        'month': month,
        'row': row,
      };
}
