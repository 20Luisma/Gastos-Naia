<?php

namespace GastosNaia\Tests\Unit\Application;

use GastosNaia\Application\EditExpenseUseCase;
use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class EditExpenseUseCaseTest extends TestCase
{
    private MockObject $repository;
    private MockObject $backupService;
    private EditExpenseUseCase $useCase;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ExpenseRepositoryInterface::class);
        $this->backupService = $this->createMock(\GastosNaia\Infrastructure\FirebaseBackupService::class);
        $this->useCase = new EditExpenseUseCase($this->repository, $this->backupService);
    }

    public function test_executes_edit_with_correct_expense(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('editExpense')
            ->with(
                2025,
                6,
                $this->callback(
                    fn(Expense $e) =>
                    $e->getRow() === 7 &&
                    $e->getDate() === '2025-06-20' &&
                    $e->getDescription() === 'Farmacia' &&
                    $e->getAmount() === 35.00
                )
            )
            ->willReturn(true);

        $result = $this->useCase->execute(2025, 6, 7, '2025-06-20', 'Farmacia', 35.00);

        $this->assertTrue($result);
    }

    public function test_returns_false_when_repository_fails(): void
    {
        $this->repository
            ->method('editExpense')
            ->willReturn(false);

        $result = $this->useCase->execute(2025, 6, 7, '2025-06-20', 'Error', 35.00);

        $this->assertFalse($result);
    }
}
