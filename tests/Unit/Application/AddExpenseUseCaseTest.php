<?php

namespace GastosNaia\Tests\Unit\Application;

use GastosNaia\Application\AddExpenseUseCase;
use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AddExpenseUseCaseTest extends TestCase
{
    private MockObject $repository;
    private AddExpenseUseCase $useCase;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ExpenseRepositoryInterface::class);
        $this->useCase = new AddExpenseUseCase($this->repository);
    }

    public function test_executes_successfully_and_returns_true(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('addExpense')
            ->with(
                2025,
                3,
                $this->callback(
                    fn(Expense $e) =>
                    $e->getDate() === '2025-03-15' &&
                    $e->getDescription() === 'Supermercado' &&
                    $e->getAmount() === 99.50
                )
            )
            ->willReturn(true);

        $result = $this->useCase->execute(2025, 3, '2025-03-15', 'Supermercado', 99.50);

        $this->assertTrue($result);
    }

    public function test_returns_false_when_repository_fails(): void
    {
        $this->repository
            ->method('addExpense')
            ->willReturn(false);

        $result = $this->useCase->execute(2025, 3, '2025-03-15', 'Error', 10.0);

        $this->assertFalse($result);
    }

    public function test_creates_expense_without_row(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('addExpense')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn(Expense $e) => $e->getRow() === null)
            )
            ->willReturn(true);

        $this->useCase->execute(2025, 1, '2025-01-10', 'Test', 20.0);
    }
}
