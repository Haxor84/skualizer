<?php
/**
 * AI Product Creator - Download Handler
 * Path: modules/margynomic/admin/creaexcel/ai/api/download.php
 * 
 * Gestisce download sicuro file Excel esportati
 */

session_start();

require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../config/CentralLogger.php';
require_once __DIR__ . '/../../../admin_helpers.php';

// Verifica autenticazione ADMIN
if (!isAdminLogged()) {
    http_response_code(401);
    die('Non autenticato. Effettua il login come admin.');
}

$userId = $_SESSION['admin_id'];

// Parametri
$sessionId = $_GET['session_id'] ?? null;
$filename = $_GET['filename'] ?? null;
$filepath = $_GET['filepath'] ?? null;

if (!$sessionId && !$filename && !$filepath) {
    http_response_code(400);
    die('Parametro session_id, filename o filepath richiesto');
}

try {
    $pdo = getDbConnection();
    $config = require __DIR__ . '/../config/ai_config.php';
    
    // Se fornito filepath diretto, usalo
    if ($filepath) {
        // Path già fornito, usa quello
        // Security verrà fatta dopo con realpath
    }
    // Altrimenti se fornito session_id, recupera export_path
    elseif ($sessionId) {
        $stmt = $pdo->prepare("
            SELECT export_path, ean 
            FROM ai_chat_sessions 
            WHERE session_uuid = ? AND user_id = ?
        ");
        $stmt->execute([$sessionId, $userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session || !$session['export_path']) {
            throw new Exception('File non trovato');
        }
        
        $filepath = $session['export_path'];
    } else {
        // Altrimenti costruisci path da filename
        $filepath = $config['paths']['exports'] . $userId . '/' . basename($filename);
    }
    
    // Verifica esistenza file
    if (!file_exists($filepath)) {
        throw new Exception('File non trovato sul server: ' . basename($filepath));
    }
    
    // Security: Usa realpath per prevenire directory traversal
    $realFilepath = realpath($filepath);
    $templatesDir = realpath($config['paths']['templates']);
    $exportsDir = realpath($config['paths']['exports']);
    
    if (!$realFilepath) {
        throw new Exception('Path file non valido');
    }
    
    // Verifica che il file sia dentro una delle directory consentite
    $isInTemplates = strpos($realFilepath, $templatesDir) === 0;
    $isInExports = strpos($realFilepath, $exportsDir) === 0;
    
    if (!$isInTemplates && !$isInExports) {
        CentralLogger::warning('ai_creator', 'Download blocked: unauthorized path', [
            'user_id' => $userId,
            'filepath' => $filepath,
            'realpath' => $realFilepath
        ]);
        throw new Exception('Accesso negato: file non in directory autorizzata');
    }
    
    // Usa il realpath per il download
    $filepath = $realFilepath;
    
    // Log download
    CentralLogger::info('ai_creator', 'File downloaded', [
        'user_id' => $userId,
        'filename' => basename($filepath)
    ]);
    
    // Imposta headers per download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    // Stream file
    readfile($filepath);
    exit;
    
} catch (Exception $e) {
    CentralLogger::error('ai_creator', 'Download error', [
        'user_id' => $userId,
        'error' => $e->getMessage()
    ]);
    
    http_response_code(404);
    die('Errore download: ' . $e->getMessage());
}

