<?php

namespace GastosNaia\Tests\Unit\Application;

use GastosNaia\Application\DeleteExpenseUseCase;
use GastosNaia\Domain\ExpenseRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class DeleteExpenseUseCaseTest extends TestCase
{
    private MockObject $repository;
    private DeleteExpenseUseCase $useCase;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ExpenseRepositoryInterface::class);
        $this->useCase = new DeleteExpenseUseCase($this->repository);
    }

    public function test_deletes_expense_by_row(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteExpense')
            ->with(2025, 3, 10)
            ->willReturn(true);

        $result = $this->useCase->execute(2025, 3, 10);

        $this->assertTrue($result);
    }

    public function test_returns_false_when_row_not_found(): void
    {
        $this->repository
            ->method('deleteExpense')
            ->willReturn(false);

        $result = $this->useCase->execute(2025, 3, 999);

        $this->assertFalse($result);
    }
}