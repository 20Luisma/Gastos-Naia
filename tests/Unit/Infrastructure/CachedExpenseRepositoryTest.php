<?php

namespace GastosNaia\Tests\Unit\Infrastructure;

use GastosNaia\Infrastructure\CachedExpenseRepository;
use GastosNaia\Infrastructure\FileCache;
use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CachedExpenseRepositoryTest extends TestCase
{
    private MockObject $inner;
    private FileCache $cache;
    private CachedExpenseRepository $sut;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(ExpenseRepositoryInterface::class);
        $this->tmpDir = sys_get_temp_dir() . '/gastos_naia_test_' . uniqid();
        $this->cache = new FileCache($this->tmpDir, ttl: 60);
        $this->sut = new CachedExpenseRepository($this->inner, $this->cache);
    }

    protected function tearDown(): void
    {
        // Limpiar archivos de caché temporales
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*.json'));
            rmdir($this->tmpDir);
        }
    }

    // ── getExpenses ──────────────────────────────────────────────────────────

    public function test_get_expenses_calls_inner_on_first_request(): void
    {
        $expenses = [new Expense('2025-03-01', 'Supermercado', 100.0, 2)];

        $this->inner->expects($this->once())
            ->method('getExpenses')
            ->with(2025, 3)
            ->willReturn($expenses);

        $result = $this->sut->getExpenses(2025, 3);

        $this->assertCount(1, $result);
        $this->assertSame('Supermercado', $result[0]->getDescription());
    }

    public function test_get_expenses_uses_cache_on_second_request(): void
    {
        $expenses = [new Expense('2025-03-01', 'Supermercado', 100.0, 2)];

        $this->inner->expects($this->once()) // ← solo UNA llamada real
            ->method('getExpenses')
            ->willReturn($expenses);

        $this->sut->getExpenses(2025, 3); // ← llena el caché
        $result = $this->sut->getExpenses(2025, 3); // ← usa el caché

        $this->assertCount(1, $result);
    }

    public function test_add_expense_invalidates_cache(): void
    {
        $expenses = [new Expense('2025-03-01', 'Test', 50.0, 2)];

        $this->inner->expects($this->exactly(2)) // ← dos llamadas reales (antes y después del invalidate)
            ->method('getExpenses')
            ->willReturn($expenses);

        $this->inner->method('addExpense')->willReturn(true);

        $this->sut->getExpenses(2025, 3);                                           // llena caché
        $this->sut->addExpense(2025, 3, new Expense('2025-03-05', 'Nuevo', 20.0)); // invalida
        $this->sut->getExpenses(2025, 3);                                           // debe llamar a inner de nuevo
    }

    public function test_edit_expense_invalidates_cache(): void
    {
        $this->inner->expects($this->exactly(2))
            ->method('getExpenses')
            ->willReturn([]);

        $this->inner->method('editExpense')->willReturn(true);

        $this->sut->getExpenses(2025, 3);
        $this->sut->editExpense(2025, 3, new Expense('2025-03-01', 'Editado', 10.0, 2));
        $this->sut->getExpenses(2025, 3);
    }

    public function test_delete_expense_invalidates_cache(): void
    {
        $this->inner->expects($this->exactly(2))
            ->method('getExpenses')
            ->willReturn([]);

        $this->inner->method('deleteExpense')->willReturn(true);

        $this->sut->getExpenses(2025, 3);
        $this->sut->deleteExpense(2025, 3, 5);
        $this->sut->getExpenses(2025, 3);
    }

    // ── getAnnualTotals ──────────────────────────────────────────────────────

    public function test_annual_totals_cached_on_second_call(): void
    {
        $totals = [['year' => 2025, 'total' => 5000.0]];

        $this->inner->expects($this->once())
            ->method('getAnnualTotals')
            ->willReturn($totals);

        $this->sut->getAnnualTotals();
        $result = $this->sut->getAnnualTotals();

        $this->assertEquals(5000.0, $result[0]['total']);
    }

    // ── getWarnings ──────────────────────────────────────────────────────────

    public function test_warnings_always_delegated_to_inner(): void
    {
        $this->inner->expects($this->once())
            ->method('getWarnings')
            ->willReturn(['Hoja no encontrada']);

        $warnings = $this->sut->getWarnings();

        $this->assertSame(['Hoja no encontrada'], $warnings);
    }
}
