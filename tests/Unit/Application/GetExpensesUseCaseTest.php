<?php

namespace GastosNaia\Tests\Unit\Application;

use GastosNaia\Application\GetExpensesUseCase;
use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;
use GastosNaia\Domain\ReceiptRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class GetExpensesUseCaseTest extends TestCase
{
    private MockObject $expenseRepository;
    private MockObject $receiptRepository;
    private GetExpensesUseCase $useCase;

    protected function setUp(): void
    {
        $this->expenseRepository = $this->createMock(ExpenseRepositoryInterface::class);
        $this->receiptRepository = $this->createMock(ReceiptRepositoryInterface::class);
        $this->useCase = new GetExpensesUseCase($this->expenseRepository, $this->receiptRepository);
    }

    public function test_returns_expenses_files_and_warnings(): void
    {
        $expenses = [
            new Expense('2025-03-01', 'Supermercado', 100.0, 2),
            new Expense('2025-03-05', 'Gasolina', 60.0, 3),
        ];
        $files = [['name' => 'factura.pdf', 'id' => 'abc123']];
        $warnings = [];

        $this->expenseRepository->method('getExpenses')->willReturn($expenses);
        $this->expenseRepository->method('getWarnings')->willReturn($warnings);
        $this->receiptRepository->method('listReceipts')->willReturn($files);

        $result = $this->useCase->execute(2025, 3);

        $this->assertArrayHasKey('expenses', $result);
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertCount(2, $result['expenses']);
        $this->assertCount(1, $result['files']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_calls_repositories_with_correct_year_and_month(): void
    {
        $this->expenseRepository
            ->expects($this->once())
            ->method('getExpenses')
            ->with(2026, 2)
            ->willReturn([]);

        $this->receiptRepository
            ->expects($this->once())
            ->method('listReceipts')
            ->with(2026, 2)
            ->willReturn([]);

        $this->expenseRepository->method('getWarnings')->willReturn([]);

        $this->useCase->execute(2026, 2);
    }

    public function test_returns_empty_arrays_when_no_data(): void
    {
        $this->expenseRepository->method('getExpenses')->willReturn([]);
        $this->expenseRepository->method('getWarnings')->willReturn([]);
        $this->receiptRepository->method('listReceipts')->willReturn([]);

        $result = $this->useCase->execute(2025, 1);

        $this->assertEmpty($result['expenses']);
        $this->assertEmpty($result['files']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_propagates_warnings_from_repository(): void
    {
        $warnings = ['Hoja "Gastos Enero" no encontrada en 2020'];

        $this->expenseRepository->method('getExpenses')->willReturn([]);
        $this->expenseRepository->method('getWarnings')->willReturn($warnings);
        $this->receiptRepository->method('listReceipts')->willReturn([]);

        $result = $this->useCase->execute(2020, 1);

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Gastos Enero', $result['warnings'][0]);
    }
}
