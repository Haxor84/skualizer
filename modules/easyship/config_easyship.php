<?php
/**
 * Configurazione Modulo EasyShip
 * File: modules/easyship/config_easyship.php
 */

// Includi configurazione principale Margynomic
require_once dirname(__DIR__) . '/margynomic/config/config.php';
require_once dirname(__DIR__) . '/margynomic/login/auth_helpers.php';

// Include sistema PHPMailer per tutto EasyShip
require_once dirname(__DIR__) . '/margynomic/gestione_vendor.php';

// === CONFIGURAZIONI EASYSHIP ===
if (!defined('EASYSHIP_BASE_DIR')) {
    define('EASYSHIP_BASE_DIR', __DIR__ . '/spedizioni/bolle/');
}
if (!defined('EASYSHIP_EMAIL_ENABLED')) {
    define('EASYSHIP_EMAIL_ENABLED', true);
}
if (!defined('EASYSHIP_DEFAULT_EMAIL')) {
    define('EASYSHIP_DEFAULT_EMAIL', 'haxor84@gmail.com');
}
if (!defined('EASYSHIP_MAX_FILE_SIZE')) {
    define('EASYSHIP_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
}

/**
 * Verifica autenticazione utente per EasyShip
 */
function requireEasyShipAuth() {
    if (!isLoggedIn()) {
        // Se è una richiesta AJAX (controlla header o parametro action), restituisci errore JSON
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
                  !empty($_REQUEST['action']);
                  
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_error('Sessione scaduta. Ricarica la pagina per effettuare il login.');
            exit();
        }
        
        // Per richieste normali, fai il redirect
        header('Location: ../margynomic/login/login.php');
        exit();
    }
    return getCurrentUser();
}

/**
 * Verifica se l'utente è admin
 */
function isEasyShipAdmin($user = null) {
    if (!$user) {
        $user = getCurrentUser();
    }
    return $user && ($user['role'] === 'admin' || $user['ruolo'] === 'admin');
}

/**
 * Genera nome spedizione progressivo per il giorno corrente
 */
function generateShipmentName($userId) {
    try {
        $db = getDbConnection();
        $today = date('Y-m-d');
        
        // Conta spedizioni create oggi per questo utente
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM shipments 
            WHERE user_id = ? AND DATE(created_at) = ?
        ");
        $stmt->execute([$userId, $today]);
        $count = $stmt->fetchColumn();
        
        $progressive = str_pad($count + 1, 2, '0', STR_PAD_LEFT);
        $dateFormatted = date('d-m-Y');
        
        return "Spedizione {$progressive} del {$dateFormatted}";
        
    } catch (Exception $e) {
        error_log("Errore generateShipmentName: " . $e->getMessage());
        return "Spedizione " . date('d-m-Y H:i');
    }
}

/**
 * Normalizza nome per cartella (minuscolo, spazi->underscore, rimozione accenti)
 */
function sanitizeFolderName($name) {
    // Converti in minuscolo
    $name = strtolower($name);
    
    // Sostituisci spazi con underscore
    $name = str_replace(' ', '_', $name);
    
    // Rimuovi accenti
    $name = str_replace(
        ['à','è','é','ì','ò','ù','ç','ñ','--'], 
        ['a','e','e','i','o','u','c','n','-'], 
        $name
    );
    
    // Rimuovi caratteri speciali
    $name = preg_replace('/[^a-z0-9_-]/', '', $name);
    
    // Comprimi underscore multipli
    $name = preg_replace('/_+/', '_', $name);
    
    return trim($name, '_-');
}

/**
 * Crea cartelle per spedizione e box
 */
function createShipmentFolders($shipmentName, $boxCount) {
    try {
        $folderName = sanitizeFolderName($shipmentName);
        $basePath = EASYSHIP_BASE_DIR . $folderName;
        
        // Crea cartella principale se non esiste
        if (!is_dir(EASYSHIP_BASE_DIR)) {
            mkdir(EASYSHIP_BASE_DIR, 0755, true);
        }
        
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }
        
        // Crea sottocartelle per ogni box
        for ($i = 1; $i <= $boxCount; $i++) {
            $boxPath = $basePath . "/box{$i}";
            if (!is_dir($boxPath)) {
                mkdir($boxPath, 0755, true);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Errore createShipmentFolders: " . $e->getMessage());
        return false;
    }
}

/**
 * Ottieni path completo file bolla
 */
function getBollaPath($shipmentName, $boxNumber) {
    $folderName = sanitizeFolderName($shipmentName);
    return EASYSHIP_BASE_DIR . "{$folderName}/box{$boxNumber}/bolla_{$folderName}.pdf";
}

/**
 * Controlla se esiste bolla per un box
 */
function bollaExists($shipmentName, $boxNumber) {
    $path = getBollaPath($shipmentName, $boxNumber);
    return file_exists($path);
}

/**
 * Ottieni URL pubblico bolla
 */
function getBollaUrl($shipmentName, $boxNumber) {
    $folderName = sanitizeFolderName($shipmentName);
    return BASE_URL . "/easyship/spedizioni/bolle/{$folderName}/box{$boxNumber}/bolla_{$folderName}.pdf";
}

/**
 * Autocomplete prodotti per utente
 */
function getProductsAutocomplete($userId, $query = '') {
    error_log("DEBUG getProductsAutocomplete - userId: {$userId}, query: {$query}");
    try {
        $db = getDbConnection();
        
        if (empty($query)) {
            $stmt = $db->prepare("
                SELECT nome 
                FROM products 
                WHERE user_id = ? 
                ORDER BY nome ASC 
                LIMIT 2000
            ");
            $stmt->execute([$userId]);
        } else {
// Dividi query in parole singole
$words = array_filter(explode(' ', strtolower(trim($query))));
$conditions = [];
$params = [$userId];

foreach ($words as $word) {
    $conditions[] = "LOWER(nome) LIKE ?";
    $params[] = "%{$word}%";
}

$whereClause = implode(' AND ', $conditions);
$stmt = $db->prepare("
    SELECT nome 
    FROM products 
    WHERE user_id = ? AND {$whereClause}
    ORDER BY nome ASC 
    LIMIT 2000
");
$stmt->execute($params);
        }
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
        
    } catch (Exception $e) {
        error_log("Errore getProductsAutocomplete: " . $e->getMessage());
        return [];
    }
}

/**
 * Risposta JSON di successo
 */
function json_success($data = [], $message = 'Success') {
    return json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Risposta JSON di errore
 */
function json_error($message, $code = 200) {
    http_response_code($code);
    return json_encode([
        'success' => false,
        'error' => $message
    ]);
}

/**
 * Invia email HTML con stile Margynomic
 */
function sendEasyShipEmail($to, $subject, $htmlContent, $fromName = 'EasyShip System') {
    if (!EASYSHIP_EMAIL_ENABLED) {
        return true; // Simulato
    }
    
    try {
        // Template completo con styling EasyShip
        $fullHtml = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
                .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
                .header { text-align: center; margin-bottom: 30px; padding: 20px; background: linear-gradient(135deg, #dc2626, #ef4444); color: white; border-radius: 10px; }
                .content { line-height: 1.6; color: #333; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                th { background: #f8f9fa; font-weight: bold; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🚚 EasyShip - Margynomic</h2>
                </div>
                <div class='content'>
                    {$htmlContent}
                </div>
                <div class='footer'>
                    Questo messaggio è stato generato automaticamente dal sistema EasyShip di Margynomic.
                </div>
            </div>
        </body>
        </html>";
        
        // Usa PHPMailer invece di mail() nativo
        return inviaEmailSMTP($to, $subject, $fullHtml);
        
    } catch (Exception $e) {
        error_log("Errore sendEasyShipEmail (PHPMailer): " . $e->getMessage());
        return false;
    }
}

/**
 * Logging operazioni EasyShip
 */
function logEasyShipOperation($message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] EASYSHIP: {$message}";
    if (!empty($context)) {
        $logEntry .= ' | ' . json_encode($context);
    }
    error_log($logEntry);
}
?> 