<?php

namespace GastosNaia\Domain;

class Expense implements \JsonSerializable
{
    private ?int $row;
    private string $date;
    private string $description;
    private float $amount;

    public function __construct(string $date, string $description, float $amount, ?int $row = null)
    {
        $this->date = $date;
        $this->description = $description;
        $this->amount = $amount;
        $this->row = $row;
    }

    public function getRow(): ?int
    {
        return $this->row;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }

    public function jsonSerialize(): array
    {
        return [
            'row' => $this->row,
            'date' => $this->date,
            'description' => $this->description,
            'amount' => $this->amount
        ];
    }
}
