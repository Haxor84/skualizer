<?php
/**
 * AI Product Creator - API Router
 * Path: modules/margynomic/admin/creaexcel/ai/api/ai_api.php
 * 
 * Single endpoint router per tutte le azioni AI Creator:
 * - start: Crea nuova sessione + genera EAN
 * - chat: Processa messaggio user
 * - upload_template: Upload file Excel
 * - analyze_competitors: Scraping ASIN + keyword extraction
 * - generate_field: LLM per singolo campo
 * - validate_field: Valida contenuto campo
 * - export: Genera Excel finale
 * - update_asin: Post-upload Amazon
 * - job_status: Polling job asincroni
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);  // 5 minuti
set_time_limit(300);
ini_set('max_execution_time', 300);  // 5 minuti per AI generation
set_time_limit(300);

// ✅ Error handler globale - converte TUTTI gli errori in JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $errstr,
        'debug' => [
            'file' => basename($errfile),
            'line' => $errline,
            'errno' => $errno
        ]
    ]);
    exit;
});

// ✅ Exception handler globale
set_exception_handler(function($exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $exception->getMessage(),
        'debug' => [
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine()
        ]
    ]);
    exit;
});

// ✅ Output buffering per catturare qualsiasi output indesiderato
ob_start();

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../config/CentralLogger.php';
require_once __DIR__ . '/../../../admin_helpers.php';
require_once __DIR__ . '/../core/AiEngine.php';

// Load AI config
$config = require __DIR__ . '/../config/ai_config.php';

// ============================================
// AUTHENTICATION & SETUP
// ============================================

// Verifica autenticazione ADMIN
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Non autenticato. Effettua il login come admin.',
        'debug' => [
            'session_id' => session_id(),
            'admin_logged_exists' => isset($_SESSION['admin_logged']),
            'admin_id_exists' => isset($_SESSION['admin_id']),
            'session_keys' => array_keys($_SESSION)
        ]
    ]);
    exit;
}

// Rate limiting function
function checkRateLimit($key, $window, $max) {
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [];
    }
    
    $now = time();
    $_SESSION['rate_limits'][$key] = array_filter(
        $_SESSION['rate_limits'][$key], 
        fn($t) => ($now - $t) < $window
    );
    
    if (count($_SESSION['rate_limits'][$key]) >= $max) {
        return false;
    }
    
    $_SESSION['rate_limits'][$key][] = $now;
    return true;
}

// Send JSON response safely (clear any stray output first)
// Note: formatBytes() is already defined in admin_helpers.php
function sendResponse($data, $httpCode = 200) {
    // Clean any output buffer to ensure pure JSON
    if (ob_get_length()) {
        ob_clean();
    }
    
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Parse request
$requestMethod = $_SERVER['REQUEST_METHOD'];
$input = null;

if ($requestMethod === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } elseif (strpos($contentType, 'multipart/form-data') !== false) {
        $input = $_POST;
    } else {
        // Fallback: prova prima $_POST, poi tenta JSON se POST è vuoto
        $input = !empty($_POST) ? $_POST : json_decode(file_get_contents('php://input'), true);
    }
    
    // Fallback finale: se input è ancora null, usa array vuoto
    if ($input === null) {
        $input = [];
    }
} elseif ($requestMethod === 'GET') {
    $input = $_GET;
}

$action = $input['action'] ?? $_GET['action'] ?? null;

// ============================================
// DETERMINE USER ID (dopo parsing input!)
// ============================================

// Admin può operare per conto di un altro user (passato nel POST)
// Priorità: 1) input['user_id'], 2) session['selected_user_id'], 3) session['admin_id']
$userId = isset($input['user_id']) && $input['user_id'] > 0 
    ? (int)$input['user_id'] 
    : (isset($_SESSION['selected_user_id']) && $_SESSION['selected_user_id'] > 0 
        ? (int)$_SESSION['selected_user_id'] 
        : $_SESSION['admin_id']);

// DEBUG: Log quale user_id viene usato (con dettagli completi)
CentralLogger::info('admin', 'User ID determination', [
    'input_user_id' => $input['user_id'] ?? 'NOT SET',
    'input_keys' => array_keys($input ?? []),
    'session_admin_id' => $_SESSION['admin_id'],
    'session_selected_user_id' => $_SESSION['selected_user_id'] ?? 'NOT SET',
    'final_user_id' => $userId,
    'action' => $action,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'NOT SET'
]);

// Log request
CentralLogger::info('admin', 'API Request received', [
    'action' => $action,
    'user_id' => $userId,
    'method' => $requestMethod,
    'has_input' => !empty($input)
]);

if (!$action) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Parametro "action" mancante'
    ]);
    exit;
}

// Apply rate limiting (100 requests per minute per user - very relaxed for admin use)
if (!checkRateLimit('api_general_' . $userId, 60, 100)) {
    CentralLogger::warning('ai_api', 'Rate limit exceeded', [
        'action' => $action,
        'user_id' => $userId
    ]);
    
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Rate limit superato. Max 100 richieste al minuto. Riprova tra qualche secondo.'
    ]);
    exit;
}

// Inizializza connessione database
$pdo = getDbConnection();

// Inizializza engine
$engine = new AiEngine($userId);

CentralLogger::info('ai_api', 'Engine initialized, entering switch', [
    'action' => $action,
    'user_id' => $userId
]);

// ============================================
// ROUTER ACTIONS
// ============================================

try {
    switch ($action) {
        
        // ============================================
        case 'start':
            /**
             * Crea nuova sessione + genera EAN
             * Input: { template_id: int, initial_data: {} }
             * Output: { session_id, ean, state }
             */
            $templateId = $input['template_id'] ?? null;
            $initialData = $input['initial_data'] ?? [];
            
            if (!$templateId) {
                throw new Exception('template_id richiesto');
            }
            
            // Validate template_id
            if (!is_numeric($templateId) || $templateId <= 0) {
                throw new Exception('template_id non valido');
            }
            
            // Validate initial_data structure
            if (!is_array($initialData)) {
                throw new Exception('initial_data deve essere un array');
            }
            
            $result = $engine->createSession($templateId, $initialData);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Sessione creata con successo',
                'data' => $result,
                'state' => $result['state']
            ]);
            break;
        
        // ============================================
        case 'chat':
            /**
             * Processa messaggio user (state machine logic)
             * Input: { session_id, message }
             * Output: { ai_response, state, data }
             */
            $sessionId = $input['session_id'] ?? null;
            $message = $input['message'] ?? null;
            
            if (!$sessionId || !$message) {
                throw new Exception('session_id e message richiesti');
            }
            
            // Sanitize message
            $message = strip_tags(trim($message));
            if (strlen($message) > 5000) {
                throw new Exception('Messaggio troppo lungo (max 5000 caratteri)');
            }
            if (empty($message)) {
                throw new Exception('Messaggio vuoto dopo sanitization');
            }
            
            // Carica sessione corrente
            $sessionResult = $engine->loadSession($sessionId);
            if (!$sessionResult['success']) {
                throw new Exception('Session not found');
            }
            
            $session = $sessionResult['session'];
            $currentState = $session['current_state'];
            $workingData = $session['working_data'];
            
            // State machine logic
            $response = processChatMessage($message, $currentState, $workingData, $engine);
            
            // Update session
            $engine->updateSessionState(
                $sessionId,
                $response['new_state'],
                $response['working_data'],
                [
                    'role' => 'user',
                    'content' => $message,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
            
            $engine->updateSessionState(
                $sessionId,
                $response['new_state'],
                null,
                [
                    'role' => 'assistant',
                    'content' => $response['ai_response'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
            
            echo json_encode([
                'success' => true,
                'message' => $response['ai_response'],
                'data' => $response['data'] ?? [],
                'state' => $response['new_state']
            ]);
            break;
        
        // ============================================
        case 'upload_template':
            /**
             * Upload template Excel
             * Input: multipart/form-data { file, categoria, folder }
             * Output: { template_id, metadata }
             */
            if (!isset($_FILES['file'])) {
                throw new Exception('File non caricato');
            }
            
            $file = $_FILES['file'];
            $categoria = $_POST['categoria'] ?? null;
            $folder = $_POST['folder'] ?? ''; // Cartella cliente/progetto
            
            CentralLogger::info('ai_api', 'Upload template request', [
                'user_id' => $userId,
                'filename' => $file['name'] ?? 'unknown',
                'categoria' => $categoria,
                'folder' => $folder
            ]);
            
            $result = $engine->uploadTemplate($file, $categoria, $folder);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Template caricato con successo',
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'list_templates':
            /**
             * Lista template disponibili
             * Input: { categoria? }
             * Output: { templates: [...] }
             */
            $categoria = $input['categoria'] ?? null;
            
            $result = $engine->listTemplates($categoria);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'analyze_competitors':
            /**
             * Scraping competitor ASINs (chunked)
             * Input: { session_id, asins: ["B001...", ...], offset: 0 }
             * Output: { status, progress, results, next_offset }
             */
            $sessionId = $input['session_id'] ?? null;
            $asins = $input['asins'] ?? [];
            $offset = $input['offset'] ?? 0;
            
            if (!$sessionId || empty($asins)) {
                throw new Exception('session_id e asins richiesti');
            }
            
            // Scraping chunked
            $result = $engine->analyzeCompetitors($asins, $offset, 3);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            // Se completato, estrai keywords
            if ($result['status'] === 'completed') {
                $sessionResult = $engine->loadSession($sessionId);
                $session = $sessionResult['session'];
                $workingData = $session['working_data'];
                
                $categoria = $workingData['categoria'] ?? 'Generic';
                
                $keywordsResult = $engine->extractKeywords($result['results'], $categoria);
                
                if ($keywordsResult['success']) {
                    $workingData['keywords'] = array_column($keywordsResult['keywords'], 'keyword');
                    $workingData['competitor_data'] = $result['results'];
                    
                    $engine->updateSessionState($sessionId, 'content_generation', $workingData);
                    
                    $result['keywords'] = $keywordsResult['keywords'];
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'generate_field':
            /**
             * Genera contenuto campo singolo con LLM
             * Input: { session_id, field_name, context, llm?: 'gpt4'|'claude' }
             * Output: { content, validation }
             */
            $sessionId = $input['session_id'] ?? null;
            $fieldName = $input['field_name'] ?? null;
            $context = $input['context'] ?? [];
            $llmPreference = $input['llm'] ?? 'gpt4';
            
            if (!$sessionId || !$fieldName) {
                throw new Exception('session_id e field_name richiesti');
            }
            
            // Carica session per context aggiuntivo
            $sessionResult = $engine->loadSession($sessionId);
            if ($sessionResult['success']) {
                $workingData = $sessionResult['session']['working_data'];
                $context = array_merge($workingData, $context);
            }
            
            $result = $engine->generateFieldContent($fieldName, $context, $llmPreference);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            // Salva in working_data
            if ($sessionResult['success']) {
                $workingData[$fieldName] = $result['content'];
                $engine->updateSessionState($sessionId, null, $workingData);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'validate_field':
            /**
             * Valida contenuto campo
             * Input: { field_name, content }
             * Output: { valid, errors, warnings, suggestions }
             */
            $fieldName = $input['field_name'] ?? null;
            $content = $input['content'] ?? '';
            
            if (!$fieldName) {
                throw new Exception('field_name richiesto');
            }
            
            $result = $engine->validateFieldContent($fieldName, $content);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        // CONVERSATIONAL WORKFLOW ENDPOINTS
        // ============================================
        case 'conversation_start':
            require_once __DIR__ . '/../workflow/ConversationalWorkflow.php';
            $sku = $input['sku'] ?? '';
            $context = isset($input['context']) ? json_decode($input['context'], true) : [];
            $conv = new ConversationalWorkflow($pdo, $userId);
            $result = $conv->startConversation($sku, $context);
            sendResponse(['success' => true, 'data' => $result]);
            break;
            
        case 'conversation_process_asin':
            require_once __DIR__ . '/../workflow/ConversationalWorkflow.php';
            $sku = $input['sku'] ?? '';
            $asins = isset($input['asins']) ? json_decode($input['asins'], true) : [];
            $skipResearch = ($input['skip_research'] ?? '0') === '1';
            $conv = new ConversationalWorkflow($pdo, $userId);
            $result = $conv->processAsinInput($sku, $asins, $skipResearch);
            sendResponse(['success' => true, 'data' => $result]);
            break;
            
        case 'conversation_save_keywords':
            require_once __DIR__ . '/../workflow/ConversationalWorkflow.php';
            $sku = $input['sku'] ?? '';
            $keywords = isset($input['keywords']) ? json_decode($input['keywords'], true) : [];
            $asins = isset($input['asins']) ? json_decode($input['asins'], true) : [];
            $mode = $input['mode'] ?? 'ai_generated';
            $conv = new ConversationalWorkflow($pdo, $userId);
            $conv->saveKeywordsCache($sku, $keywords, $asins, $mode);
            sendResponse(['success' => true, 'message' => 'Keywords cached']);
            break;
        
        // ============================================
        case 'generate_multiple_fields':
            /**
             * Genera multipli campi in modo coordinato
             * Input: { fields: string[], context: object }
             * Output: { fields: object, overlap_analysis: object }
             */
            // SUPPORTO MULTI-FORMAT: $input diretto (JSON body) o $_POST form-encoded
            $fields = isset($input['fields']) && is_array($input['fields']) 
                ? $input['fields'] 
                : (isset($_POST['fields']) ? json_decode($_POST['fields'], true) : []);
                
            $context = isset($input['context']) && is_array($input['context'])
                ? $input['context']
                : (isset($_POST['context']) ? json_decode($_POST['context'], true) : []);
            
            // DEBUG log per capire formato ricevuto
            CentralLogger::debug('ai_api', 'Fields parsing debug', [
                'user_id' => $userId,
                'input_fields_exists' => isset($input['fields']),
                'input_fields_type' => isset($input['fields']) ? gettype($input['fields']) : 'NOT SET',
                'post_fields_exists' => isset($_POST['fields']),
                'parsed_fields_count' => is_array($fields) ? count($fields) : 0,
                'parsed_fields' => $fields,
                'context_has_keywords' => isset($context['keywords'])
            ]);
            
            if (empty($fields) || !is_array($fields)) {
                sendResponse([
                    'success' => false, 
                    'error' => 'No fields specified',
                    'debug' => [
                        'input_keys' => array_keys($input ?? []),
                        'post_keys' => array_keys($_POST ?? []),
                        'parsed_fields' => $fields
                    ]
                ], 400);
            }
            
            if (empty($context)) {
                sendResponse([
                    'success' => false, 
                    'error' => 'Context required',
                    'debug' => [
                        'input_context_type' => isset($input['context']) ? gettype($input['context']) : 'NOT SET',
                        'parsed_context' => $context
                    ]
                ], 400);
            }
            
            CentralLogger::info('ai_api', 'Multi-field generation request', [
                'user_id' => $userId,
                'fields_count' => count($fields),
                'fields' => $fields,
                'context_sku' => $context['sku'] ?? 'N/D'
            ]);
            
            // TRY: Usa nuovo Workflow Engine
            try {
                require_once __DIR__ . '/../workflow/WorkflowEngine.php';
                
                CentralLogger::info('ai_api', 'Using WorkflowEngine', [
                    'user_id' => $userId,
                    'fields' => $fields
                ]);
                
                $workflowEngine = new WorkflowEngine($pdo, $userId);
                $workflowResults = $workflowEngine->execute($fields, $context);
                
                CentralLogger::info('ai_api', 'WorkflowEngine completed', [
                    'user_id' => $userId,
                    'fields_returned' => array_keys($workflowResults)
                ]);
                
                sendResponse([
                    'success' => true,
                    'fields' => $workflowResults,
                    'workflow_used' => true,
                    'overlap_analysis' => ['has_overlap' => false], // TODO: implementare in workflow
                    'thinking' => null // TODO: passare da Step1 se disponibile
                ]);
                
            } catch (Exception $workflowError) {
                // FALLBACK: Usa sistema legacy (monolithic)
                CentralLogger::warning('ai_api', 'WorkflowEngine failed, falling back to legacy system', [
                    'user_id' => $userId,
                    'error' => $workflowError->getMessage()
                ]);
                
                $result = $engine->generateMultipleFields($fields, $context);
                
                // Debug thinking
                $thinking = $result['thinking'] ?? null;
                $result['debug_thinking'] = [
                    'has_thinking' => !empty($thinking),
                    'thinking_length' => strlen($thinking ?? ''),
                    'thinking_preview' => substr($thinking ?? '', 0, 100)
                ];
                $result['workflow_used'] = false;
                $result['fallback_reason'] = $workflowError->getMessage();
                
                CentralLogger::info('ai_api', 'Legacy generation response', [
                    'user_id' => $userId,
                    'success' => $result['success'] ?? false,
                    'fields_returned' => isset($result['fields']) ? array_keys($result['fields']) : [],
                    'has_thinking' => !empty($thinking)
                ]);
                
                sendResponse($result);
            }
            break;
        
        // ============================================
        case 'auto_fix':
            /**
             * Auto-fix contenuto campo
             * Input: { field_name, content }
             * Output: { fixed_content }
             */
            $fieldName = $input['field_name'] ?? null;
            $content = $input['content'] ?? '';
            
            if (!$fieldName) {
                throw new Exception('field_name richiesto');
            }
            
            $validation = $engine->validateFieldContent($fieldName, $content);
            
            if (!$validation['auto_fixable']) {
                throw new Exception('Contenuto non auto-fixable');
            }
            
            $result = $engine->autoFixContent($fieldName, $content, $validation);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'export':
            /**
             * Genera Excel finale
             * Input: { session_id }
             * Output: { export_path, download_url }
             */
            $sessionId = $input['session_id'] ?? null;
            
            if (!$sessionId) {
                throw new Exception('session_id richiesto');
            }
            
            $result = $engine->exportExcel($sessionId);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Excel esportato con successo',
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'update_asin':
            /**
             * Update ASIN post-upload Amazon
             * Input: { ean, asin }
             * Output: { success }
             */
            $ean = $input['ean'] ?? null;
            $asin = $input['asin'] ?? null;
            
            if (!$ean || !$asin) {
                throw new Exception('ean e asin richiesti');
            }
            
            // Valida formato ASIN
            if (!preg_match('/^B[A-Z0-9]{9}$/', $asin)) {
                throw new Exception('Formato ASIN non valido');
            }
            
            $result = $engine->updateProductAsin($ean, $asin);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'ASIN aggiornato con successo'
            ]);
            break;
        
        // ============================================
        case 'sync_prices_from_db':
            /**
             * Sincronizza prezzi da database products
             * Input: { filepath: string }
             * Output: { success, total_rows, updated_count, not_found_count, skipped_parent_count }
             */
            CentralLogger::info('ai_api', 'Sync prices from DB request', [
                'user_id' => $userId,
                'filepath' => $input['filepath'] ?? 'missing'
            ]);
            
            try {
                $filepath = $input['filepath'] ?? null;
                
                if (!$filepath) {
                    throw new Exception('Filepath mancante');
                }
                
                if (!file_exists($filepath)) {
                    throw new Exception('File non trovato');
                }
                
                $result = $engine->syncPricesFromDatabase($filepath);
                
                if (!$result['success']) {
                    throw new Exception($result['error']);
                }
                
                CentralLogger::info('ai_api', 'Prices synced successfully', [
                    'user_id' => $userId,
                    'updated_count' => $result['updated_count'],
                    'not_found_count' => $result['not_found_count']
                ]);
                
                sendResponse([
                    'success' => true,
                    'message' => 'Prezzi sincronizzati con successo',
                    'data' => $result
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'Sync prices failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId
                ]);
                
                sendResponse([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }
            break;
        
        // ============================================
        case 'list_folders':
            /**
             * Lista cartelle disponibili per l'utente
             * Input: nessuno
             * Output: { folders: [{name, file_count, last_modified}] }
             */
            try {
                $baseTemplatesDir = $config['paths']['templates'] . $userId . '/';
                
                if (!is_dir($baseTemplatesDir)) {
                    mkdir($baseTemplatesDir, 0755, true);
                }
                
                $folders = [];
                $items = @scandir($baseTemplatesDir);
                
                if ($items) {
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }
                        
                        $itemPath = $baseTemplatesDir . $item;
                        
                        if (is_dir($itemPath)) {
                            // Count Excel files in folder
                            $excelFiles = glob($itemPath . '/*.{xlsx,xlsm,xls}', GLOB_BRACE);
                            $fileCount = count($excelFiles ?: []);
                            
                            $folders[] = [
                                'name' => $item,
                                'path' => $itemPath,
                                'file_count' => $fileCount,
                                'last_modified' => filemtime($itemPath),
                                'last_modified_formatted' => date('d/m/Y H:i', filemtime($itemPath))
                            ];
                        }
                    }
                }
                
                // Sort by last modified DESC
                usort($folders, function($a, $b) {
                    return $b['last_modified'] - $a['last_modified'];
                });
                
                sendResponse([
                    'success' => true,
                    'folders' => $folders
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'List folders failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId
                ]);
                
                sendResponse([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }
            break;
        
        // ============================================
        case 'create_folder':
            /**
             * Crea nuova cartella per cliente/progetto
             * Input: { folder_name: string }
             * Output: { success: boolean, folder_path: string }
             */
            try {
                $folderName = $input['folder_name'] ?? null;
                
                if (!$folderName) {
                    throw new Exception('Nome cartella mancante');
                }
                
                // Validate folder name
                $folderName = trim($folderName);
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $folderName)) {
                    throw new Exception('Nome cartella non valido. Usa solo lettere, numeri, trattini e underscore');
                }
                
                if (strlen($folderName) > 50) {
                    throw new Exception('Nome cartella troppo lungo (max 50 caratteri)');
                }
                
                $baseTemplatesDir = $config['paths']['templates'] . $userId . '/';
                $newFolderPath = $baseTemplatesDir . $folderName . '/';
                
                // Check if folder already exists
                if (is_dir($newFolderPath)) {
                    throw new Exception('Cartella già esistente');
                }
                
                // Create folder
                if (!mkdir($newFolderPath, 0755, true)) {
                    throw new Exception('Impossibile creare la cartella');
                }
                
                CentralLogger::info('ai_api', 'Folder created', [
                    'user_id' => $userId,
                    'folder_name' => $folderName,
                    'path' => $newFolderPath
                ]);
                
                sendResponse([
                    'success' => true,
                    'message' => 'Cartella creata con successo',
                    'folder_name' => $folderName,
                    'folder_path' => $newFolderPath
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'Create folder failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId,
                    'folder_name' => $input['folder_name'] ?? 'unknown'
                ]);
                
                sendResponse([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }
            break;
        
        // ============================================
        case 'delete_file':
            /**
             * Elimina file Excel
             * Input: { filepath: string }
             * Output: { success: boolean }
             */
            CentralLogger::info('ai_api', 'Delete file request', [
                'user_id' => $userId,
                'filepath' => $input['filepath'] ?? 'missing'
            ]);
            
            try {
                $filepath = $input['filepath'] ?? null;
                
                if (!$filepath) {
                    throw new Exception('Filepath mancante');
                }
                
                // Security check: file must be in user's templates directory
                $templatesDir = $config['paths']['templates'] . $userId . '/';
                $realFilepath = realpath($filepath);
                $realTemplatesDir = realpath($templatesDir);
                
                if (!$realFilepath || !$realTemplatesDir) {
                    throw new Exception('Path non valido');
                }
                
                if (strpos($realFilepath, $realTemplatesDir) !== 0) {
                    throw new Exception('Permesso negato: file fuori dalla directory utente');
                }
                
                if (!file_exists($filepath)) {
                    throw new Exception('File non trovato');
                }
                
                if (!is_file($filepath)) {
                    throw new Exception('Path non è un file');
                }
                
                // Delete file
                if (!@unlink($filepath)) {
                    throw new Exception('Impossibile eliminare il file');
                }
                
                CentralLogger::info('ai_api', 'File deleted successfully', [
                    'user_id' => $userId,
                    'filepath' => $filepath
                ]);
                
                sendResponse([
                    'success' => true,
                    'message' => 'File eliminato con successo'
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'Delete file failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId,
                    'filepath' => $input['filepath'] ?? 'unknown'
                ]);
                
                sendResponse([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }
            break;
        
        // ============================================
        case 'duplicate_file':
            /**
             * Duplica file Excel
             * Input: { filepath: string, new_name?: string }
             * Output: { success: boolean, new_filepath, new_filename }
             */
            $filepath = $input['filepath'] ?? null;
            $newName = $input['new_name'] ?? null;
            
            if (!$filepath) {
                sendResponse([
                    'success' => false,
                    'error' => 'Filepath mancante'
                ], 400);
            }
            
            $result = $engine->duplicateExcel($filepath, $newName);
            
            if ($result['success']) {
                sendResponse($result);
            } else {
                sendResponse($result, 500);
            }
            break;
        
        // ============================================
        case 'rename_file':
            /**
             * Rinomina file Excel
             * Input: { filepath: string, new_name: string }
             * Output: { success: boolean, new_filepath, new_filename }
             */
            $filepath = $input['filepath'] ?? null;
            $newName = $input['new_name'] ?? null;
            
            if (!$filepath || !$newName) {
                sendResponse([
                    'success' => false,
                    'error' => 'Parametri mancanti'
                ], 400);
            }
            
            $result = $engine->renameExcel($filepath, $newName);
            
            if ($result['success']) {
                sendResponse($result);
            } else {
                sendResponse($result, 500);
            }
            break;
        
        // ============================================
        // AMAZON CATEGORIES MANAGEMENT
        // ============================================
        
        case 'list_categories':
            /**
             * Lista categorie Amazon dell'utente
             * Output: { categories: [{id, name, slug}] }
             */
            $pdo = getDbConnection();
            try {
                $stmt = $pdo->prepare("
                    SELECT id, category_name as name, category_slug as slug
                    FROM amazon_categories
                    WHERE user_id = ?
                    ORDER BY category_name ASC
                ");
                $stmt->execute([$userId]);
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                sendResponse([
                    'success' => true,
                    'categories' => $categories
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'List categories failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId
                ]);
                sendResponse([
                    'success' => false,
                    'error' => $e->getMessage()
                ], 500);
            }
            break;
        
        case 'create_category':
            /**
             * Crea nuova categoria Amazon
             * Input: { category_name: string }
             * Output: { success: boolean, category_id: int, folder_created: boolean }
             */
            $pdo = getDbConnection();
            try {
                $categoryName = trim($input['category_name'] ?? '');
                
                if (empty($categoryName)) {
                    throw new Exception('Nome categoria mancante');
                }
                
                if (strlen($categoryName) > 100) {
                    throw new Exception('Nome categoria troppo lungo (max 100 caratteri)');
                }
                
                // Generate slug (nome tecnico per cartelle)
                $slug = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($categoryName));
                $slug = preg_replace('/_+/', '_', $slug); // Remove multiple underscores
                $slug = trim($slug, '_');
                
                // Insert into database
                $stmt = $pdo->prepare("
                    INSERT INTO amazon_categories (user_id, category_name, category_slug)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE category_name = VALUES(category_name)
                ");
                $stmt->execute([$userId, $categoryName, $slug]);
                $categoryId = $pdo->lastInsertId() ?: $stmt->fetch(PDO::FETCH_ASSOC)['id'];
                
                // Create filesystem folder
                $folderPath = $config['paths']['templates'] . $userId . '/' . $slug . '/';
                $folderCreated = false;
                
                if (!is_dir($folderPath)) {
                    if (mkdir($folderPath, 0755, true)) {
                        $folderCreated = true;
                    }
                }
                
                CentralLogger::info('ai_api', 'Category created', [
                    'user_id' => $userId,
                    'category_name' => $categoryName,
                    'slug' => $slug,
                    'folder_created' => $folderCreated
                ]);
                
                sendResponse([
                    'success' => true,
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'slug' => $slug,
                    'folder_created' => $folderCreated
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'Create category failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId
                ]);
                sendResponse([
                    'success' => false,
                    'error' => $e->getMessage()
                ], 500);
            }
            break;
        
        case 'delete_category':
            /**
             * Elimina categoria Amazon
             * Input: { category_id: int }
             * Output: { success: boolean }
             */
            $pdo = getDbConnection();
            try {
                $categoryId = (int)($input['category_id'] ?? 0);
                
                if ($categoryId <= 0) {
                    throw new Exception('ID categoria non valido');
                }
                
                // Get category slug before deleting
                $stmt = $pdo->prepare("
                    SELECT category_slug
                    FROM amazon_categories
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$categoryId, $userId]);
                $category = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$category) {
                    throw new Exception('Categoria non trovata');
                }
                
                // Delete from database
                $stmt = $pdo->prepare("
                    DELETE FROM amazon_categories
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$categoryId, $userId]);
                
                // Note: NON eliminare la cartella filesystem (potrebbe contenere file)
                // L'utente può eliminarla manualmente se necessario
                
                CentralLogger::info('ai_api', 'Category deleted', [
                    'user_id' => $userId,
                    'category_id' => $categoryId,
                    'slug' => $category['category_slug']
                ]);
                
                sendResponse([
                    'success' => true,
                    'message' => 'Categoria eliminata dal database. La cartella filesystem è stata preservata.'
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'Delete category failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId
                ]);
                sendResponse([
                    'success' => false,
                    'error' => $e->getMessage()
                ], 500);
            }
            break;
        
        // ============================================
        case 'list_recent_files':
            /**
             * Lista file Excel recenti per l'utente DA TABELLA ai_templates
             * Input: { folder: string (optional) }
             * Output: { files: [{name, filepath, last_modified, size, categoria}] }
             */
            $pdo = getDbConnection();
            $folder = $input['folder'] ?? '';
            
            try {
                // Query tabella ai_templates invece di filesystem
                $query = "
                    SELECT 
                        id,
                        template_name as name,
                        filepath,
                        categoria_amazon as categoria,
                        last_used,
                        updated_at,
                        created_at
                    FROM ai_templates
                    WHERE user_id = ?
                    ORDER BY COALESCE(last_used, updated_at, created_at) DESC
                    LIMIT 50
                ";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([$userId]);
                $dbFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $files = [];
                
                foreach ($dbFiles as $dbFile) {
                    $filepath = $dbFile['filepath'];
                    
                    // Verifica che file esista ancora fisicamente
                    if (!file_exists($filepath)) {
                        continue; // Skip file eliminati dal filesystem
                    }
                    
                    $filesize = filesize($filepath);
                    $lastModified = filemtime($filepath);
                    
                    $files[] = [
                        'id' => $dbFile['id'],
                        'name' => $dbFile['name'],
                        'filepath' => $filepath,
                        'categoria' => $dbFile['categoria'],
                        'size' => $filesize,
                        'size_formatted' => formatFileSize($filesize),
                        'last_modified' => $lastModified,
                        'last_modified_formatted' => date('d/m/Y H:i', $lastModified),
                        'last_used' => $dbFile['last_used']
                    ];
                }
                
                sendResponse([
                    'success' => true,
                    'files' => $files,
                    'total' => count($files)
                ]);
                
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'list_recent_files error', [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
            break;
        
        // ============================================
        case 'list_recent_files_OLD_FILESYSTEM':
            // BACKUP vecchio sistema filesystem (deprecato)
            $folder = $input['folder'] ?? '';
            
            try {
                $templatesDir = $config['paths']['templates'] . $userId . '/';
                
                if (!empty($folder)) {
                    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $folder)) {
                        throw new Exception('Nome cartella non valido');
                    }
                    $templatesDir .= $folder . '/';
                }
                
                if (!is_dir($templatesDir)) {
                    sendResponse([
                        'success' => true,
                        'files' => []
                    ]);
                }
                
                $files = [];
                $items = @scandir($templatesDir);
                
                if ($items === false) {
                    throw new Exception('Cannot read templates directory');
                }
                
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    
                    $filepath = $templatesDir . $item;
                    
                    if (!@is_file($filepath)) {
                        continue;
                    }
                    
                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['xlsx', 'xlsm', 'xls'])) {
                        continue;
                    }
                    
                    $files[] = [
                        'name' => $item,
                        'filepath' => $filepath,
                        'last_modified' => @filemtime($filepath) ?: 0,
                        'last_modified_formatted' => date('d/m/Y H:i', @filemtime($filepath) ?: time()),
                        'size' => @filesize($filepath) ?: 0,
                        'size_formatted' => formatBytes(@filesize($filepath) ?: 0)
                    ];
                }
                
                // Ordina per data modifica DESC (più recenti prima)
                usort($files, function($a, $b) {
                    return $b['last_modified'] - $a['last_modified'];
                });
                
                // Limita a 10 file più recenti
                $files = array_slice($files, 0, 10);
                
                sendResponse([
                    'success' => true,
                    'files' => $files
                ]);
            } catch (Exception $e) {
                CentralLogger::error('ai_api', 'List recent files failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId
                ]);
                
                sendResponse([
                    'success' => false,
                    'message' => 'Errore caricamento file recenti: ' . $e->getMessage()
                ], 500);
            }
            break;
        
        // ============================================
        case 'load_excel_rows':
            /**
             * Carica tutte le righe da file Excel
             * Input: { filepath }
             * Output: { rows: [...], metadata: {...} }
             */
            $pdo = getDbConnection();
            $filepath = $input['filepath'] ?? null;
            
            if (!$filepath) {
                throw new Exception('filepath richiesto');
            }
            
            // Security: verifica che filepath appartenga all'user
            if (strpos($filepath, $userId . '/') === false) {
                throw new Exception('Accesso negato al file');
            }
            
            $result = $engine->loadExcelRows($filepath);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            // Update last_used in ai_templates
            try {
                $stmt = $pdo->prepare("UPDATE ai_templates SET last_used = NOW() WHERE filepath = ? AND user_id = ?");
                $stmt->execute([$filepath, $userId]);
            } catch (Exception $e) {
                // Non blocare se update fallisce
                CentralLogger::warning('ai_api', 'Failed to update last_used', ['error' => $e->getMessage()]);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'get_row':
            /**
             * Carica dati singola riga
             * Input: { filepath, row_number }
             * Output: { data: {...} }
             */
            $filepath = $input['filepath'] ?? null;
            $rowNumber = $input['row_number'] ?? null;
            
            if (!$filepath || !$rowNumber) {
                throw new Exception('filepath e row_number richiesti');
            }
            
            $result = $engine->getRow($filepath, $rowNumber);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'save_row':
            /**
             * Salva modifiche a singola riga
             * Input: { filepath, row_number, row_data: {...} }
             * Output: { success }
             */
            // ✅ AUMENTO LIMITI per Excel pesante
            @ini_set('max_execution_time', 120);  // 2 minuti per save Excel
            @set_time_limit(120);
            @ini_set('memory_limit', '1024M');    // 1GB per PHPSpreadsheet
            
            $filepath = $input['filepath'] ?? null;
            $rowNumber = $input['row_number'] ?? null;
            $rowData = $input['row_data'] ?? [];
            
            if (!$filepath || !$rowNumber || empty($rowData)) {
                throw new Exception('filepath, row_number e row_data richiesti');
            }
            
            CentralLogger::info('ai_api', 'save_row START', [
                'filepath' => basename($filepath),
                'row_number' => $rowNumber,
                'fields_count' => count($rowData)
            ]);
            
            $result = $engine->saveRow($filepath, $rowNumber, $rowData);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            CentralLogger::info('ai_api', 'save_row SUCCESS', [
                'row_number' => $rowNumber
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Riga salvata con successo',
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'sync_ean':
            /**
             * Sincronizza codici EAN da Excel a database products.ean
             * Input: { filepath }
             * Output: { updated_count, stats }
             */
            $filepath = $input['filepath'] ?? null;
            
            if (!$filepath) {
                throw new Exception('filepath richiesto');
            }
            
            $result = $engine->syncEanCodes($filepath);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Codici EAN sincronizzati',
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'add_row':
            /**
             * Aggiungi nuova riga prodotto
             * Input: { filepath, row_data: {...}, generate_ean: bool }
             * Output: { row_number, ean }
             */
            $filepath = $input['filepath'] ?? null;
            $rowData = $input['row_data'] ?? [];
            $generateEan = $input['generate_ean'] ?? true;
            
            if (!$filepath) {
                throw new Exception('filepath richiesto');
            }
            
            $result = $engine->addRow($filepath, $rowData, $generateEan);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Nuova riga aggiunta',
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'duplicate_row':
            /**
             * Duplica riga prodotto
             * Input: { filepath, row_number }
             * Output: { success, new_row_number, row_data }
             */
            $filepath = $input['filepath'] ?? null;
            $rowNumber = $input['row_number'] ?? null;
            
            if (!$filepath || !$rowNumber) {
                throw new Exception('filepath e row_number richiesti');
            }
            
            if ($rowNumber < 4) {
                throw new Exception('Non è possibile duplicare righe di intestazione');
            }
            
            $result = $engine->duplicateRow($filepath, $rowNumber);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Riga duplicata con successo',
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'delete_row':
            /**
             * Elimina riga prodotto
             * Input: { filepath, row_number }
             * Output: { success }
             */
            $filepath = $input['filepath'] ?? null;
            $rowNumber = $input['row_number'] ?? null;
            
            if (!$filepath || !$rowNumber) {
                throw new Exception('filepath e row_number richiesti');
            }
            
            if ($rowNumber < 4) {
                throw new Exception('Non puoi eliminare righe metadata (1-3)');
            }
            
            $result = $engine->deleteRow($filepath, $rowNumber);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Riga eliminata'
            ]);
            break;
        
        // ============================================
        case 'validate_all_rows':
            /**
             * Valida tutte le righe del file
             * Input: { filepath }
             * Output: { validation_results: [...] }
             */
            $filepath = $input['filepath'] ?? null;
            
            if (!$filepath) {
                throw new Exception('filepath richiesto');
            }
            
            $result = $engine->validateAllRows($filepath);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        case 'load_session':
            /**
             * Carica sessione esistente
             * Input: { session_id }
             * Output: { session }
             */
            $sessionId = $input['session_id'] ?? null;
            
            if (!$sessionId) {
                throw new Exception('session_id richiesto');
            }
            
            $result = $engine->loadSession($sessionId);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
        
        // ============================================
        default:
            throw new Exception("Action '{$action}' non riconosciuta");
    }
    
} catch (Exception $e) {
    // Pulisci buffer output per rimuovere eventuali warning
    ob_clean();
    
    http_response_code(500);
    
    CentralLogger::error('ai_api', 'API error', [
        'user_id' => $userId ?? 'unknown',
        'action' => $action ?? 'unknown',
        'error' => $e->getMessage()
    ]);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}

// ✅ Pulisci e flush output buffer
$output = ob_get_clean();
// Se l'output è già JSON valido, stampalo, altrimenti ignora
if (!empty($output) && json_decode($output) === null) {
    // Output non è JSON valido, probabilmente warning HTML
    // Lo ignoriamo e mandiamo un errore pulito
    echo json_encode([
        'success' => false,
        'error' => 'Server error: invalid response format'
    ]);
} else {
    echo $output;
}

// ============================================
// CHAT STATE MACHINE LOGIC
// ============================================

/**
 * Processa messaggio chat basato su stato corrente
 */
function processChatMessage($message, $currentState, $workingData, $engine) {
    $message = trim($message);
    
    switch ($currentState) {
        case 'init':
            // Parse messaggio iniziale prodotto
            $workingData['product_type'] = $message;
            
            return [
                'ai_response' => "Perfetto! Vuoi creare: '{$message}'.\n\nOra carica il template Excel per la categoria del prodotto, oppure dammi 5-10 ASIN competitor da Amazon.it per l'analisi keyword.",
                'new_state' => 'template_select',
                'working_data' => $workingData
            ];
        
        case 'template_select':
            // Attende template upload o ASIN list
            if (strpos(strtoupper($message), 'B0') !== false) {
                // ASIN list forniti
                preg_match_all('/B[A-Z0-9]{9}/', strtoupper($message), $matches);
                $asins = $matches[0];
                
                if (count($asins) > 0) {
                    $workingData['competitor_asins'] = $asins;
                    
                    return [
                        'ai_response' => "Ho trovato " . count($asins) . " ASIN: " . implode(', ', array_slice($asins, 0, 5)) . (count($asins) > 5 ? '...' : '') . ".\n\nAvvio analisi competitor...",
                        'new_state' => 'keyword_research',
                        'working_data' => $workingData,
                        'data' => ['asins' => $asins]
                    ];
                }
            }
            
            return [
                'ai_response' => "Non ho trovato ASIN validi. Fornisci ASIN nel formato B0XXXXXXXX oppure carica il template Excel.",
                'new_state' => 'template_select',
                'working_data' => $workingData
            ];
        
        case 'keyword_research':
            // Attende completamento scraping
            return [
                'ai_response' => "Analisi competitor in corso...",
                'new_state' => 'keyword_research',
                'working_data' => $workingData
            ];
        
        case 'content_generation':
            // Risponde a richieste specifiche
            if (stripos($message, 'genera') !== false || stripos($message, 'crea') !== false) {
                return [
                    'ai_response' => "Genero i contenuti ottimizzati per il tuo prodotto...\n\nUsa i bottoni 'Generate with AI' per ogni campo oppure 'Ask AI' per rigenerare singoli campi.",
                    'new_state' => 'preview',
                    'working_data' => $workingData
                ];
            }
            
            return [
                'ai_response' => "Cosa vuoi fare?\n- 'Genera contenuti' per creare title, description e bullets con AI\n- Oppure modifica manualmente i campi",
                'new_state' => 'content_generation',
                'working_data' => $workingData
            ];
        
        case 'preview':
            // Conferma export
            if (stripos($message, 'export') !== false || stripos($message, 'scarica') !== false) {
                return [
                    'ai_response' => "Genero il file Excel...",
                    'new_state' => 'completed',
                    'working_data' => $workingData,
                    'data' => ['action' => 'trigger_export']
                ];
            }
            
            return [
                'ai_response' => "Rivedi i campi nel tab 'Fields'. Quando sei pronto, clicca 'Export Excel'.",
                'new_state' => 'preview',
                'working_data' => $workingData
            ];
        
        default:
            return [
                'ai_response' => "Non ho capito. Puoi ripetere?",
                'new_state' => $currentState,
                'working_data' => $workingData
            ];
    }
}

/**
 * Format file size in human readable format
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

