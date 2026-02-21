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
        $files = $this->receiptRepository->listReceipts($year, $month);
        $warnings = $this->expenseRepository->getWarnings();

        return [
            'expenses' => $expenses,
            'files' => $files,
            'warnings' => $warnings
        ];
    }
}
