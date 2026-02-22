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

        // Mock getMonthlyFinancialSummary (new method)
        $this->expenseRepositoryMock->method('getMonthlyFinancialSummary')
            ->willReturnCallback(function ($year, $month) {
                if ($year === 2024 && $month === 1) {
                    return ['total_gastos' => 100.0, 'transferencia_naia' => 50.0, 'pension' => 238.0, 'total_final' => 288.0];
                }
                return ['total_gastos' => 0.0, 'transferencia_naia' => 0.0, 'pension' => 0.0, 'total_final' => 0.0];
            });

        // Mock getExpenses to return 1 item when called for 2024 month 1
        $this->expenseRepositoryMock->method('getExpenses')
            ->willReturnCallback(function ($year, $month) {
                if ($year === 2024 && $month === 1) {
                    return [new Expense('Enero', 'Comida', 50.5)];
                }
                return [];
            });

        // We expect it to try connecting to Gemini and fail (since it is a fake key/mock)
        // BUT we want to assert that ai_cache.json was generated correctly first.
        $this->useCase->execute("Hola");

        $this->assertFileExists($this->cacheFile);

        $cacheContent = json_decode(file_get_contents($this->cacheFile), true);
        $this->assertIsArray($cacheContent);
        $this->assertCount(1, $cacheContent); // 1 year
        $this->assertEquals(2024, $cacheContent[0]['year']);
        $this->assertCount(1, $cacheContent[0]['meses']); // only January has data
        $this->assertEquals(1, $cacheContent[0]['meses'][0]['mes']);
        $this->assertEquals(50.0, $cacheContent[0]['meses'][0]['transferencia_naia']);
        $this->assertEquals('Comida', $cacheContent[0]['meses'][0]['gastos'][0]['desc']);
    }
}
