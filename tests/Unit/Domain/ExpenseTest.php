<?php

namespace GastosNaia\Tests\Unit\Domain;

use GastosNaia\Domain\Expense;
use PHPUnit\Framework\TestCase;

class ExpenseTest extends TestCase
{
    public function test_expense_stores_basic_properties(): void
    {
        $expense = new Expense('2025-03-15', 'Supermercado', 150.75);

        $this->assertSame('2025-03-15', $expense->getDate());
        $this->assertSame('Supermercado', $expense->getDescription());
        $this->assertSame(150.75, $expense->getAmount());
        $this->assertNull($expense->getRow());
    }

    public function test_expense_stores_row_when_provided(): void
    {
        $expense = new Expense('2025-03-15', 'Gasolina', 60.0, 5);

        $this->assertSame(5, $expense->getRow());
    }

    public function test_expense_amount_can_be_updated(): void
    {
        $expense = new Expense('2025-03-15', 'Restaurante', 45.00);
        $expense->setAmount(55.50);

        $this->assertSame(55.50, $expense->getAmount());
    }

    public function test_expense_json_serializes_correctly(): void
    {
        $expense = new Expense('2025-03-15', 'Supermercado', 150.75, 3);

        $json = $expense->jsonSerialize();

        $this->assertSame('2025-03-15', $json['date']);
        $this->assertSame('Supermercado', $json['description']);
        $this->assertSame(150.75, $json['amount']);
        $this->assertSame(3, $json['row']);
    }

    public function test_expense_json_serializable_with_json_encode(): void
    {
        $expense = new Expense('2025-01-01', 'Luz', 85.30);

        $result = json_decode(json_encode($expense), true);

        $this->assertSame('2025-01-01', $result['date']);
        $this->assertSame('Luz', $result['description']);
        $this->assertSame(85.30, $result['amount']);
    }

    public function test_expense_with_zero_amount(): void
    {
        $expense = new Expense('2025-06-01', 'Gratis', 0.0);

        $this->assertSame(0.0, $expense->getAmount());
    }
}
