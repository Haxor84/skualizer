<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
require_once '../margynomic/config/config.php';
require_once '../margynomic/login/auth_helpers.php';

// Verifica autenticazione
if (!isLoggedIn()) {
    header('Location: ../margynomic/login/login.php');
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Redirect mobile
if (isMobileDevice()) {
    header('Location: /modules/mobile/Rendiconto.php');
    exit;
}

$dbConfig = [
    'host' => DB_HOST,
    'dbname' => DB_NAME,
    'username' => DB_USER,
    'password' => DB_PASS,
    'charset' => DB_CHARSET
];

// Create PDO connection
try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

require_once 'RendicontoController.php';

$controller = new RendicontoController($pdo, $userId);

// Get action from request
$action = $_GET['action'] ?? $_POST['action'] ?? 'render';

// Handle different actions
switch ($action) {
    case 'save':
        // Handle POST request to save data
        header('Content-Type: application/json');
        
        $input = file_get_contents('php://input');
        $payload = json_decode($input, true);
        
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON payload']);
            exit;
        }
        
        $result = $controller->saveDocument($payload);
        
        if ($result['success']) {
            http_response_code(200);
        } else {
            http_response_code(400);
        }
        
        echo json_encode($result);
        break;
        
    case 'load':
        // Handle GET request to load data by year
        header('Content-Type: application/json');
        
        $anno = $_GET['anno'] ?? null;
        $brand = $_GET['brand'] ?? 'PROFUMI YESENSY';
        
        if (!$anno || !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter is required and must be numeric']);
            exit;
        }
        
        $documento = $controller->loadDocumentByYear($anno, $brand);
        
        if ($documento) {
            $totali = $controller->computeYearTotals($documento['righe']);
            $kpi = $controller->computeKpi($totali);
            
            echo json_encode([
                'success' => true,
                'documento' => $documento,
                'totali' => $totali,
                'kpi' => $kpi
            ]);
        } else {
            // Return empty document structure for new year
            $emptyDocument = [
                'documento' => [
                    'id' => null,
                    'anno' => $anno,
                    'brand' => $brand,
                    'valuta' => 'EUR'
                ],
                'righe' => []
            ];
            
            // Create empty righe for all 12 months
            for ($mese = 1; $mese <= 12; $mese++) {
                $emptyDocument['righe'][$mese] = [
                    'id' => null,
                    'documento_id' => null,
                    'mese' => $mese,
                    'data' => null,
                    'entrate_fatturato' => 0,
                    'entrate_unita' => 0,
                    'erogato_importo' => 0,
                    'accantonamento_percentuale' => 0,
                    'accantonamento_euro' => 0,
                    'tasse_euro' => 0,
                    'diversi_euro' => 0,
                    'materia1_euro' => 0,
                    'materia1_unita' => 0,
                    'sped_euro' => 0,
                    'sped_unita' => 0,
                    'varie_euro' => 0,
                    'utile_netto_mese' => 0
                ];
            }
            
            $totali = $controller->computeYearTotals($emptyDocument['righe']);
            $kpi = $controller->computeKpi($totali);
            
            echo json_encode([
                'success' => true,
                'documento' => $emptyDocument,
                'totali' => $totali,
                'kpi' => $kpi
            ]);
        }
        break;
        
    case 'load_by_id':
        // Handle GET request to load data by document ID
        header('Content-Type: application/json');
        
        $id = $_GET['id'] ?? null;
        
        if (!$id || !is_numeric($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID parameter is required and must be numeric']);
            exit;
        }
        
        $documento = $controller->loadDocument($id);
        
        if ($documento) {
            $totali = $controller->computeYearTotals($documento['righe']);
            $kpi = $controller->computeKpi($totali);
            
            echo json_encode([
                'success' => true,
                'documento' => $documento,
                'totali' => $totali,
                'kpi' => $kpi
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Document not found']);
        }
        break;
        
    case 'duplicate':
        // Handle POST request to duplicate a year
        header('Content-Type: application/json');
        
        $sourceAnno = $_POST['source_anno'] ?? null;
        $targetAnno = $_POST['target_anno'] ?? null;
        $brand = $_POST['brand'] ?? 'PROFUMI YESENSY';
        
        if (!$sourceAnno || !$targetAnno || !is_numeric($sourceAnno) || !is_numeric($targetAnno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Source and target years are required and must be numeric']);
            exit;
        }
        
        $sourceDoc = $controller->loadDocumentByYear($sourceAnno, $brand);
        
        if (!$sourceDoc) {
            http_response_code(404);
            echo json_encode(['error' => 'Source document not found']);
            exit;
        }
        
        // Prepare payload for new year
        $payload = [
            'anno' => $targetAnno,
            'brand' => $brand,
            'valuta' => $sourceDoc['documento']['valuta'],
            'righe' => []
        ];
        
        foreach ($sourceDoc['righe'] as $riga) {
            $payload['righe'][] = [
                'mese' => $riga['mese'],
                'data' => null, // Reset dates for new year
                'entrate_fatturato' => $riga['entrate_fatturato'],
                'entrate_unita' => $riga['entrate_unita'],
                'erogato_importo' => $riga['erogato_importo'],
                'accantonamento_percentuale' => $riga['accantonamento_percentuale'],
                'accantonamento_euro' => $riga['accantonamento_euro'],
                'tasse_euro' => $riga['tasse_euro'],
                'diversi_euro' => $riga['diversi_euro'],
                'materia1_euro' => $riga['materia1_euro'],
                'materia1_unita' => $riga['materia1_unita'],
                'sped_euro' => $riga['sped_euro'],
                'sped_unita' => $riga['sped_unita'],
                'varie_euro' => $riga['varie_euro']
            ];
        }
        
        $result = $controller->saveDocument($payload);
        echo json_encode($result);
        break;
        
    case 'years':
        // Handle GET request to get available years
        header('Content-Type: application/json');
        
        $years = $controller->getAvailableYears();
        echo json_encode(['years' => $years]);
        break;
        
    case 'get_fatturato_settlement':
        // Handle GET request to get fatturato data from settlement table
        header('Content-Type: application/json');
        
        $anno = $_GET['anno'] ?? null;
        
        if (!$anno || !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter is required and must be numeric']);
            exit;
        }
        
        $result = $controller->getFatturatoFromSettlement($anno);
        
        if ($result['success']) {
            http_response_code(200);
        } else {
            http_response_code(400);
        }
        
        echo json_encode($result);
        break;
        
    case 'get_input_utente':
        header('Content-Type: application/json');
        
        $id = $_GET['id'] ?? null;
        $anno = $_GET['anno'] ?? null;
        $tipoInput = $_GET['tipo_input'] ?? null;
        $mese = $_GET['mese'] ?? null;
        
        // Anno è opzionale: se non fornito, restituisce tutti gli anni
        if ($anno && !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter must be numeric']);
            exit;
        }
        
        $result = $controller->getInputUtente($anno, $tipoInput, $mese, $id);
        echo json_encode($result);
        break;
        
    case 'save_input_utente':
        header('Content-Type: application/json');
        
        $input = file_get_contents('php://input');
        $payload = json_decode($input, true);
        
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON payload']);
            exit;
        }
        
        $result = $controller->saveInputUtente($payload);
        echo json_encode($result);
        break;
        
    case 'delete_input_utente':
        header('Content-Type: application/json');
        
        $id = $_GET['id'] ?? $_POST['id'] ?? null;
        
        if (!$id || !is_numeric($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID parameter is required']);
            exit;
        }
        
        $result = $controller->deleteInputUtente($id);
        echo json_encode($result);
        break;
        
    case 'get_unita_vendute':
        header('Content-Type: application/json');
        
        $anno = $_GET['anno'] ?? null;
        
        if (!$anno || !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter is required']);
            exit;
        }
        
        $result = $controller->getUnitaVendute($anno);
        echo json_encode($result);
        break;
        
    case 'get_available_years':
        header('Content-Type: application/json');
        
        $result = $controller->getAvailableYears();
        echo json_encode($result);
        break;
        
    default:
        // Default action: render the view
        $anno = $_GET['anno'] ?? date('Y');
        $id = $_GET['id'] ?? null;
        $brand = $_GET['brand'] ?? 'PROFUMI YESENSY';
        
        $data = ['documento' => [], 'righe' => []];
        
        if ($id && is_numeric($id)) {
            // Load by ID
            $documento = $controller->loadDocument($id);
            if ($documento) {
                $data = $documento;
            }
        } else {
            // Load by year or create empty
            $documento = $controller->loadDocumentByYear($anno, $brand);
            if ($documento) {
                $data = $documento;
            } else {
                // Create empty document structure
                $data = [
                    'documento' => [
                        'id' => null,
                        'anno' => $anno,
                        'brand' => $brand,
                        'valuta' => 'EUR'
                    ],
                    'righe' => []
                ];
                
                // Create empty righe for all 12 months
                for ($mese = 1; $mese <= 12; $mese++) {
                    $data['righe'][$mese] = [
                        'id' => null,
                        'documento_id' => null,
                        'mese' => $mese,
                        'data' => null,
                        'entrate_fatturato' => 0,
                        'entrate_unita' => 0,
                        'erogato_importo' => 0,
                        'accantonamento_percentuale' => 0,
                        'accantonamento_euro' => 0,
                        'tasse_euro' => 0,
                        'diversi_euro' => 0,
                        'materia1_euro' => 0,
                        'materia1_unita' => 0,
                        'sped_euro' => 0,
                        'sped_unita' => 0,
                        'varie_euro' => 0,
                        'utile_netto_mese' => 0
                    ];
                }
            }
        }
        
        // Include the view
        include 'views/rendiconto_view.php';
        break;
} 