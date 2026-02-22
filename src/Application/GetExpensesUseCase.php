<?php

namespace GastosNaia\Application;

use GastosNaia\Domain\ExpenseRepositoryInterface;
use GastosNaia\Domain\ReceiptRepositoryInterface;

class GetExpensesUseCase
{
    private ExpenseRepositoryInterface $expenseRepository;
    private ReceiptRepositoryInterface $receiptRepository;

    public function __construct(ExpenseRepositoryInterface $expenseRepository, ReceiptRepositoryInterface $receiptRepository)
    {
        $this->expenseRepository = $expenseRepository;
        $this->receiptRepository = $receiptRepository;
    }

    public function execute(int $year, int $month): array
    {
        $expenses = $this->expenseRepository->getExpenses($year, $month);

        // Ordenamos cronológicamente: de fecha más antigua a más nueva
        usort($expenses, function ($a, $b) {
            return strtotime($a->getDate()) <=> strtotime($b->getDate());
        });

        $files = $this->receiptRepository->listReceipts($year, $month);
        $warnings = $this->expenseRepository->getWarnings();
        $summary = $this->expenseRepository->getMonthlyFinancialSummary($year, $month);

        return [
            'expenses' => $expenses,
            'files' => $files,
            'warnings' => $warnings,
            'summary' => $summary
        ];
    }
}
