<?php

namespace GastosNaia\Presentation;

use GastosNaia\Application\AddExpenseUseCase;
use GastosNaia\Application\DeleteExpenseUseCase;
use GastosNaia\Application\DeleteReceiptUseCase;
use GastosNaia\Application\EditExpenseUseCase;
use GastosNaia\Application\GetExpensesUseCase;
use GastosNaia\Application\UploadReceiptUseCase;
use GastosNaia\Infrastructure\GoogleDriveReceiptRepository;
use GastosNaia\Infrastructure\GoogleSheetsExpenseRepository;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Sheets;

class ApiController
{
    private array $config;

    // Use cases
    private GetExpensesUseCase $getExpensesUseCase;
    private AddExpenseUseCase $addExpenseUseCase;
    private EditExpenseUseCase $editExpenseUseCase;
    private DeleteExpenseUseCase $deleteExpenseUseCase;
    private UploadReceiptUseCase $uploadReceiptUseCase;
    private DeleteReceiptUseCase $deleteReceiptUseCase;

    // Repositories
    private GoogleSheetsExpenseRepository $expenseRepository;
    private GoogleDriveReceiptRepository $receiptRepository;

    public function __construct(array $config)
    {
        $this->config = $config;

        $client = $this->createGoogleClient();

        $this->expenseRepository = new GoogleSheetsExpenseRepository($client, $config);
        $this->receiptRepository = new GoogleDriveReceiptRepository($client, $config);

        $this->getExpensesUseCase = new GetExpensesUseCase($this->expenseRepository, $this->receiptRepository);
        $this->addExpenseUseCase = new AddExpenseUseCase($this->expenseRepository);
        $this->editExpenseUseCase = new EditExpenseUseCase($this->expenseRepository);
        $this->deleteExpenseUseCase = new DeleteExpenseUseCase($this->expenseRepository);
        $this->uploadReceiptUseCase = new UploadReceiptUseCase($this->receiptRepository);
        $this->deleteReceiptUseCase = new DeleteReceiptUseCase($this->receiptRepository);
    }

    private function createGoogleClient(): Client
    {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? $_SERVER['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?? '';
        $refreshToken = $_ENV['GOOGLE_REFRESH_TOKEN'] ?? $_SERVER['GOOGLE_REFRESH_TOKEN'] ?? getenv('GOOGLE_REFRESH_TOKEN') ?? '';

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            // Check if service account is available
            $credPath = $this->config['credentials_path'] ?? '';
            if (file_exists($credPath)) {
                $client = new Client();
                $client->setAuthConfig($credPath);
                $client->setScopes([Sheets::SPREADSHEETS, Drive::DRIVE]);
                $client->setApplicationName('Gastos Naia Dashboard');
                return $client;
            }
            throw new \Exception("Faltan credenciales de OAuth en el archivo .env o Service Account en configuration.");
        }

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setAccessType('offline');
        $client->setScopes([Sheets::SPREADSHEETS, Drive::DRIVE]);
        $client->setApplicationName('Gastos Naia');

        $client->setAccessToken([
            'refresh_token' => $refreshToken,
            'access_token' => '',
            'expires_in' => 0,
            'created' => 0
        ]);

        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
        }

        return $client;
    }

    public function handle(string $action): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        try {
            switch ($action) {
                case 'years':
                    $rows = $this->expenseRepository->getAnnualTotals();
                    $this->jsonResponse(['rows' => $rows, 'warnings' => $this->expenseRepository->getWarnings()]);
                    break;

                case 'months':
                    $year = (int) ($_GET['year'] ?? date('Y'));
                    $months = $this->expenseRepository->getMonthlyTotals($year);
                    $this->jsonResponse([
                        'year' => $year,
                        'months' => $months,
                        'years' => $this->expenseRepository->getAvailableYears(),
                        'warnings' => $this->expenseRepository->getWarnings(),
                    ]);
                    break;

                case 'expenses':
                    $year = (int) ($_GET['year'] ?? date('Y'));
                    $month = (int) ($_GET['month'] ?? date('n'));
                    $result = $this->getExpensesUseCase->execute($year, $month);

                    $monthLabels = $this->config['month_labels'];
                    $this->jsonResponse([
                        'year' => $year,
                        'month' => $month,
                        'monthName' => $monthLabels[$month] ?? '',
                        'expenses' => $result['expenses'],
                        'files' => $result['files'],
                        'warnings' => $result['warnings'],
                    ]);
                    break;

                case 'add':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    $success = $this->addExpenseUseCase->execute(
                        (int) $input['year'],
                        (int) $input['month'],
                        $input['date'],
                        $input['description'],
                        (float) $input['amount']
                    );
                    $this->jsonResponse(['success' => $success]);
                    break;

                case 'edit':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    $success = $this->editExpenseUseCase->execute(
                        (int) $input['year'],
                        (int) $input['month'],
                        (int) $input['row'],
                        $input['date'],
                        $input['description'],
                        (float) $input['amount']
                    );
                    $this->jsonResponse(['success' => $success]);
                    break;

                case 'delete':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    $success = $this->deleteExpenseUseCase->execute(
                        (int) $input['year'],
                        (int) $input['month'],
                        (int) $input['row']
                    );
                    $this->jsonResponse(['success' => $success]);
                    break;

                case 'upload':
                    $this->requirePost();
                    if (empty($_FILES['file'])) {
                        throw new \Exception('No se recibió ningún archivo.');
                    }
                    $year = (int) ($_POST['year'] ?? 0);
                    $month = (int) ($_POST['month'] ?? 0);
                    if ($year === 0 || $month === 0) {
                        throw new \Exception('Año y mes son obligatorios.');
                    }

                    $tmpPath = $_FILES['file']['tmp_name'];
                    $originalName = $_FILES['file']['name'];
                    $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';

                    $result = $this->uploadReceiptUseCase->execute($year, $month, $tmpPath, $originalName, $mimeType);
                    $this->jsonResponse(['success' => true, 'file' => $result]);
                    break;

                case 'delete-file':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    $deleted = $this->deleteReceiptUseCase->execute($input['path'] ?? '');
                    $this->jsonResponse(['success' => $deleted]);
                    break;

                case 'config':
                    $this->jsonResponse([
                        'years' => $this->expenseRepository->getAvailableYears(),
                        'months' => $this->config['month_labels'],
                    ]);
                    break;

                default:
                    http_response_code(400);
                    $this->jsonResponse(['error' => "Acción desconocida: {$action}"]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            $this->jsonResponse(['error' => $e->getMessage()]);
        }
    }

    private function jsonResponse(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->jsonResponse(['error' => 'Se requiere método POST.']);
        }
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \Exception('JSON inválido en el body de la petición.');
        }
        return $data;
    }
}
