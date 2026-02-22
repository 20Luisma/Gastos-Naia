<?php

namespace Tests\Unit\Application;

use GastosNaia\Application\AskAiUseCase;
use GastosNaia\Domain\Expense;
use GastosNaia\Domain\ExpenseRepositoryInterface;
use PHPUnit\Framework\TestCase;

class AskAiUseCaseTest extends TestCase
{
    private $expenseRepositoryMock;
    private AskAiUseCase $useCase;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->expenseRepositoryMock = $this->createMock(ExpenseRepositoryInterface::class);
        $this->useCase = new AskAiUseCase($this->expenseRepositoryMock);

        // Define temporary cache file for testing
        $this->cacheFile = __DIR__ . '/../../../backups/ai_cache.json';
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function testExecuteWithoutApiKeyReturnsError()
    {
        // Temporarily unset API key for test
        $oldKey = $_ENV['OPENAI_API_KEY'] ?? null;
        $_ENV['OPENAI_API_KEY'] = '';
        unset($_ENV['OPENAI_API_KEY']);

        $response = $this->useCase->execute("¿Cuánto he gastado?");

        $this->assertStringContainsString("Error: La clave de la API de OpenAI no está configurada", $response);

        // Restore
        if ($oldKey !== null) {
            $_ENV['OPENAI_API_KEY'] = $oldKey;
        }
    }

    public function testExecuteCacheGeneration()
    {
        // Set fake API key so it passes the first check
        $_ENV['OPENAI_API_KEY'] = 'test-fake-key';

        $this->expenseRepositoryMock->method('getAvailableYears')->willReturn([2024]);

        // Mock getAnnualTotals — returns the annual "Total Final" for each year
        $this->expenseRepositoryMock->method('getAnnualTotals')
            ->willReturn([['year' => 2024, 'total' => 3456.0]]);

        // Mock getMonthlyTotals — returns list of monthly totals from the annual sheet
        $this->expenseRepositoryMock->method('getMonthlyTotals')
            ->willReturnCallback(function ($year) {
                if ($year === 2024) {
                    return [
                        ['month' => 1, 'name' => 'Enero', 'total' => 100.0],
                        ['month' => 2, 'name' => 'Febrero', 'total' => 0.0],
                    ];
                }
                return [];
            });

        // Mock getExpenses — returns 1 item for January 2024
        $this->expenseRepositoryMock->method('getExpenses')
            ->willReturnCallback(function ($year, $month) {
                if ($year === 2024 && $month === 1) {
                    return [new Expense('Enero', 'Comida', 50.5)];
                }
                return [];
            });

        // We expect it to try connecting to OpenAI and fail (fake key),
        // BUT we want to assert that ai_cache.json was generated correctly first.
        $this->useCase->execute("Hola");

        $this->assertFileExists($this->cacheFile);

        $cacheContent = json_decode(file_get_contents($this->cacheFile), true);
        $this->assertIsArray($cacheContent);
        $this->assertCount(1, $cacheContent); // 1 year
        $this->assertEquals(2024, $cacheContent[0]['year']);
        $this->assertCount(2, $cacheContent[0]['meses']); // January + February (both included)
        $this->assertEquals(1, $cacheContent[0]['meses'][0]['mes']);
        $this->assertEquals(50.0, $cacheContent[0]['meses'][0]['transferencia_naia']); // 100/2
        $this->assertEquals('Comida', $cacheContent[0]['meses'][0]['gastos'][0]['desc']);
        $this->assertArrayHasKey('total_anual', $cacheContent[0]);
    }
}
