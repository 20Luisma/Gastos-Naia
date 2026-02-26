<?php

namespace GastosNaia\Presentation;

use GastosNaia\Application\AddExpenseUseCase;
use GastosNaia\Application\DeleteExpenseUseCase;
use GastosNaia\Application\DeleteReceiptUseCase;
use GastosNaia\Application\EditExpenseUseCase;
use GastosNaia\Application\GetExpensesUseCase;
use GastosNaia\Application\UploadReceiptUseCase;
use GastosNaia\Application\SetPensionUseCase;
use GastosNaia\Infrastructure\CachedExpenseRepository;
use GastosNaia\Infrastructure\FileCache;
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
    private SetPensionUseCase $setPensionUseCase;
    private \GastosNaia\Application\AskAiUseCase $askAiUseCase;
    private \GastosNaia\Application\ScanReceiptUseCase $scanReceiptUseCase;

    // Repositories
    private CachedExpenseRepository $expenseRepository;
    private GoogleDriveReceiptRepository $receiptRepository;

    public function __construct(array $config)
    {
        $this->config = $config;

        $client = $this->createGoogleClient();

        $rawRepository = new GoogleSheetsExpenseRepository($client, $config);
        $cache = new FileCache(__DIR__ . '/../../../cache', ttl: 300);
        $this->expenseRepository = new CachedExpenseRepository($rawRepository, $cache);
        $this->receiptRepository = new GoogleDriveReceiptRepository($client, $config);

        $firebaseService = new \GastosNaia\Infrastructure\FirebaseBackupService();

        $this->getExpensesUseCase = new GetExpensesUseCase($this->expenseRepository, $this->receiptRepository);
        $this->addExpenseUseCase = new AddExpenseUseCase($this->expenseRepository, $firebaseService);
        $this->editExpenseUseCase = new EditExpenseUseCase($this->expenseRepository, $firebaseService);
        $this->deleteExpenseUseCase = new DeleteExpenseUseCase($this->expenseRepository, $firebaseService);
        $this->uploadReceiptUseCase = new UploadReceiptUseCase($this->receiptRepository);
        $this->deleteReceiptUseCase = new DeleteReceiptUseCase($this->receiptRepository, $firebaseService);

        $firebaseWriteRepo = new \GastosNaia\Infrastructure\FirebaseWriteRepository();
        $this->setPensionUseCase = new SetPensionUseCase($this->expenseRepository, $firebaseWriteRepo);

        $firebaseReadRepo = new \GastosNaia\Infrastructure\FirebaseReadRepository();
        $this->askAiUseCase = new \GastosNaia\Application\AskAiUseCase($firebaseReadRepo);

        $this->scanReceiptUseCase = new \GastosNaia\Application\ScanReceiptUseCase();
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
                        'summary' => $result['summary'],
                    ]);
                    break;

                case 'clear_cache':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    $secret = $input['secret'] ?? '';
                    if (empty($secret) || $secret !== ($this->config['webhook_secret'] ?? '')) {
                        http_response_code(403);
                        $this->jsonResponse(['error' => 'No autorizado.']);
                        return;
                    }
                    $this->expenseRepository->invalidateAll();
                    $this->jsonResponse(['success' => true]);
                    break;

                case 'ai_ask':
                    $this->requirePost();
                    // Aumentamos el límite de tiempo de PHP porque la IA puede tardar +30s en generar texto largo o analizar mucho contexto
                    set_time_limit(120);
                    $input = $this->getJsonInput();
                    $question = $input['question'] ?? '';
                    if (empty($question)) {
                        throw new \Exception('La pregunta no puede estar vacía.');
                    }
                    $answer = $this->askAiUseCase->execute($question);
                    $this->jsonResponse(['answer' => $answer]);
                    break;

                case 'add':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    $success = $this->addExpenseUseCase->execute(
                        (int) $input['year'],
                        (int) $input['month'],
                        $this->normalizeDate($input['date'] ?? ''),
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
                        $this->normalizeDate($input['date'] ?? ''),
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

                case 'set_pension':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    if (!isset($input['year']) || !isset($input['month']) || !isset($input['amount'])) {
                        throw new \Exception('Año, mes e importe son obligatorios.');
                    }
                    $success = $this->setPensionUseCase->execute(
                        (int) $input['year'],
                        (int) $input['month'],
                        (float) $input['amount']
                    );
                    $this->jsonResponse(['success' => $success]);
                    break;

                case 'scan_receipt':
                    $this->requirePost();
                    if (empty($_FILES['file'])) {
                        throw new \Exception('No se recibió ningún archivo para escanear.');
                    }
                    $tmpPath = $_FILES['file']['tmp_name'];
                    $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';

                    $data = $this->scanReceiptUseCase->execute($tmpPath, $mimeType);
                    $this->jsonResponse($data);
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

                case 'create_year':
                    $this->requirePost();
                    $input = $this->getJsonInput();
                    $newYear = (int) ($input['year'] ?? 0);

                    if ($newYear < 2020 || $newYear > 2100) {
                        throw new \Exception("Año inválido proporcionado: $newYear");
                    }

                    // 1. Verificamos si ya existe
                    if (isset($this->config['spreadsheets'][$newYear]) && !empty($this->config['spreadsheets'][$newYear])) {
                        http_response_code(400); // Bad Request
                        $this->jsonResponse(['error' => "Este año ya está creado. No se puede volver a generar."]);
                        return;
                    }

                    $templateId = $this->config['template_spreadsheet_id'] ?? '';
                    $rootFolderId = $this->config['root_drive_folder_id'] ?? '';

                    if (empty($templateId) || $templateId === 'PENDING_TEMPLATE_ID') {
                        throw new \Exception("Falta configurar el 'template_spreadsheet_id' en config.php. Por favor, especifica el ID de la plantilla.");
                    }
                    if (empty($rootFolderId)) {
                        throw new \Exception("Falta configurar el 'root_drive_folder_id' en config.php.");
                    }

                    // 2. Clonar el Spreadsheet de Plantilla
                    $client = $this->createGoogleClient();
                    $drive = new Drive($client);

                    $newSheetName = "Gastos Naia $newYear - Hojas de Cálculo";
                    $fileMetadata = new \Google\Service\Drive\DriveFile([
                        'name' => $newSheetName,
                    ]);

                    $optParams = [
                        'supportsAllDrives' => true,
                    ];

                    try {
                        $copiedFile = $drive->files->copy($templateId, $fileMetadata, $optParams);
                        $newSpreadsheetId = $copiedFile->getId();
                    } catch (\Exception $e) {
                        throw new \Exception("Error al clonar la Plantilla de Sheets: " . $e->getMessage());
                    }

                    // 3. Crear Carpeta "Renta YYYY" en el Root Folder
                    $folderMetadata = new \Google\Service\Drive\DriveFile([
                        'name' => "Renta $newYear",
                        'parents' => [$rootFolderId],
                        'mimeType' => 'application/vnd.google-apps.folder'
                    ]);

                    $optParams = [
                        'supportsAllDrives' => true,
                    ];

                    try {
                        $newFolder = $drive->files->create($folderMetadata, $optParams);
                        $newFolderId = $newFolder->getId();
                    } catch (\Exception $e) {
                        throw new \Exception("Error al crear carpeta principal 'Renta $newYear': " . $e->getMessage());
                    }

                    // 3.5 Crear las 12 subcarpetas de los meses dentro de la carpeta "Renta YYYY"
                    $monthLabels = $this->config['month_labels'] ?? [];
                    try {
                        // Creating folders sequentially (Google APIs client easily supports this for 12 items without hitting rate limits)
                        for ($m = 1; $m <= 12; $m++) {
                            $mName = $monthLabels[$m] ?? 'Mes' . $m;
                            $subFolderTitle = sprintf("%d) Recibos %s %d", $m, $mName, $newYear);
                            $subFolderMetadata = new \Google\Service\Drive\DriveFile([
                                'name' => $subFolderTitle,
                                'parents' => [$newFolderId],
                                'mimeType' => 'application/vnd.google-apps.folder'
                            ]);
                            $drive->files->create($subFolderMetadata, ['fields' => 'id']);
                        }
                    } catch (\Exception $e) {
                        // Log the error but don't stop the whole process as the year can still function
                        error_log("Error al crear subcarpetas mensuales para Renta $newYear: " . $e->getMessage());
                    }

                    // 4. Mover el Spreadsheet clonado a esa carpeta recién creada
                    try {
                        // Retrieve the existing parents to remove
                        $fileToMove = $drive->files->get($newSpreadsheetId, ['fields' => 'parents']);
                        $previousParents = join(',', $fileToMove->getParents());
                        // Move the file to the new folder
                        $drive->files->update($newSpreadsheetId, new \Google\Service\Drive\DriveFile(), [
                            'addParents' => $newFolderId,
                            'removeParents' => $previousParents,
                            'fields' => 'id, parents'
                        ]);
                    } catch (\Exception $e) {
                        // Ignoramos el error de mover, lo importante es que se ha creado
                        error_log("No se pudo mover Sheet a la carpeta: " . $e->getMessage());
                    }

                    // 5. Modificar el archivo config.php directamente para guardar los nuevos IDs
                    $configPath = __DIR__ . '/../../../config.php';
                    if (!file_exists($configPath)) {
                        $configPath = __DIR__ . '/../../config.php'; // fallback depending on structure
                    }
                    if (!is_writable($configPath)) {
                        throw new \Exception("El archivo config.php no tiene permisos de escritura en el servidor FTP.");
                    }

                    $configData = file_get_contents($configPath);
                    if (!$configData) {
                        throw new \Exception("No se pudo leer config.php.");
                    }

                    // Inyectar el Año en el Array de Spreadsheets
                    $spreadSheetsPattern = "/('spreadsheets'\s*=>\s*\[)(.*?)(\nM.*?\s*\])/is";
                    if (preg_match("/('spreadsheets'\s*=>\s*\[)(.*?)(\n\s*\])/is", $configData, $matches)) {
                        $innerSp = $matches[2];
                        $innerSp = rtrim($innerSp);
                        if (substr($innerSp, -1) !== ',') {
                            $innerSp .= ',';
                        }
                        $newLineSp = "\n        $newYear => '$newSpreadsheetId',";
                        $configData = preg_replace("/('spreadsheets'\s*=>\s*\[)(.*?)(\n\s*\])/is", "$1$innerSp$newLineSp$3", $configData);
                    }

                    // Inyectar el Año en el Array de Drive Folders
                    if (preg_match("/('drive_folders'\s*=>\s*\[)(.*?)(\n\s*\])/is", $configData, $matches)) {
                        $innerFo = $matches[2];
                        $innerFo = rtrim($innerFo);
                        if (substr($innerFo, -1) !== ',') {
                            $innerFo .= ',';
                        }
                        $newLineFo = "\n        $newYear => '$newFolderId',";
                        $configData = preg_replace("/('drive_folders'\s*=>\s*\[)(.*?)(\n\s*\])/is", "$1$innerFo$newLineFo$3", $configData);
                    }

                    $configSaved = true;
                    if (file_put_contents($configPath, $configData) === false) {
                        $configSaved = false;
                        error_log("Aviso: El servidor FTP (InfinityFree) bloqueó la auto-modificación segura de config.php");
                    }

                    $this->jsonResponse([
                        'status' => 'success',
                        'config_saved' => $configSaved,
                        'message' => 'Carpetas construidas en Google Drive.',
                        'newSpreadsheetId' => $newSpreadsheetId,
                        'newFolderId' => $newFolderId,
                        // Proveemos estos datos por si configSaved es false y el usuario tiene que copiarlos a mano
                        'manualCodeSpreadsheet' => "\n        $newYear => '$newSpreadsheetId',",
                        'manualCodeFolder' => "\n        $newYear => '$newFolderId',"
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

    /**
     * Normaliza la fecha al formato DD/MM/YYYY que usa Google Sheets.
     * El input HTML type="date" devuelve YYYY-MM-DD; lo convertimos aquí.
     * Si ya viene en DD/MM/YYYY (o cualquier otro formato) lo dejamos tal cual.
     */
    private function normalizeDate(string $date): string
    {
        // Detectar formato YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        return $date;
    }
}
