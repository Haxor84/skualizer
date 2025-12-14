<?php

/**
 * OAuth Controller per Margynomic
 * Gestisce autorizzazione Amazon e salvataggio refresh token per ogni utente
 * File: sincro/oauth_controller.php
 */

ini_set('session.cookie_secure', '1');      // HTTPS obbligatorio
ini_set('session.cookie_samesite', 'None'); // consente il cookie dopo redirect cross-site
session_start();

// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

require_once '../config/config.php';
require_once 'api_config.php';
require_once 'sync_helpers.php';

/**
 * Log OAuth - Utilizza CentralLogger
 */
function logOAuthOperation($message, $level = 'INFO', $context = []) {
    CentralLogger::log('oauth', $level, $message, $context);
}

$action = $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];

switch ($action) {
    case 'authorize':
        handleAuthorize($userId);
        break;
    case 'callback':
        handleCallback($userId);
        break;
    default:
        header('Location: ../profilo_utente.php');
        exit;
}

/**
 * STEP 1: Gestisce richiesta di autorizzazione
 */
function handleAuthorize($userId) {
    try {
        // Genera state sicuro per CSRF protection
        $state = generateSecureState($userId);

// Salva nel database invece che in sessione
$pdo = getDbConnection();
$stmt = $pdo->prepare("
    DELETE FROM oauth_states WHERE user_id = ? OR created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
");
$stmt->execute([$userId]);

$stmt = $pdo->prepare("
    INSERT INTO oauth_states (state_token, user_id, created_at) 
    VALUES (?, ?, NOW())
");
$stmt->execute([$state, $userId]);
        
        // Costruisci URL autorizzazione Amazon REALE
        $authUrl = buildAuthorizationUrl($state);
        
        // Redirect a Amazon Seller Central
        header('Location: ' . $authUrl);
        exit;
        
    } catch (Exception $e) {
        logSyncOperation($userId, 'oauth_authorize_error', 'error', 'Errore avvio autorizzazione: ' . $e->getMessage());
        
        $_SESSION['oauth_error'] = 'Errore avvio autorizzazione: ' . $e->getMessage();
        header('Location: ../profilo_utente.php');
        exit;
    }
}

/**
 * STEP 2: gestisce il callback da Amazon
 */
function handleCallback($userId)
{
    try {
        /* --- 1. verifica parametro obbligatorio "code" --- */
        if (!isset($_GET['code'])) {
            throw new Exception("Parametro 'code' mancante. Completa l'autorizzazione su Amazon.");
        }

        /* --- 2. verifica CSRF state --- */
        $receivedState = $_GET['state'] ?? '';
        $sessionState  = $_SESSION['amazon_oauth_state'] ?? '';

        if (empty($sessionState) || $receivedState !== $sessionState) {
            throw new Exception("Stato di sicurezza non valido. Riprova il processo di autorizzazione.");
        }

        /* --- 3. verifica timeout (10 minuti) --- */
        $timestamp = $_SESSION['amazon_oauth_timestamp'] ?? 0;
        if (time() - $timestamp > 600) {
            throw new Exception("Sessione di autorizzazione scaduta. Riprova il processo.");
        }

        /* --- 4. parametri utili --- */
        $authCode         = $_GET['code'];
        $sellingPartnerId = $_GET['selling_partner_id'] ?? null;   // può non esserci
        
        // Log centralizzato OAuth
        logOAuthOperation("Parametri callback validati", 'INFO', [
            'user_id' => $userId,
            'selling_partner_id' => $sellingPartnerId
        ]);

        /* --- 5. scambia code → refresh token --- */
        $refreshToken = exchangeCodeForToken($authCode);
        if (!$refreshToken) {
            throw new Exception("Impossibile ottenere il refresh token da Amazon.");
        }

        /* --- 6. salva nel DB --- */
        $saved = saveRefreshToken($userId, $refreshToken, $sellingPartnerId);
        if (!$saved) {
            throw new Exception("Errore nel salvataggio del refresh token nel database.");
        }

        /* --- 7. clean-up e redirect --- */
        unset($_SESSION['amazon_oauth_state'], $_SESSION['amazon_oauth_user_id'], $_SESSION['amazon_oauth_timestamp']);

        logSyncOperation($userId, 'oauth_completed', 'info', 
            sprintf('OAuth completato: user %d | marketplace %s', 
                $userId, $sellingPartnerId ?? 'unknown'),
            [
                'user_id' => $userId,
                'marketplace_id' => $sellingPartnerId,
                'token_saved' => true
            ]
        );

        $_SESSION['oauth_success'] = "🎉 Autorizzazione Amazon completata! Ora puoi scaricare i tuoi report personali.";
        header('Location: ../profilo_utente.php');
        exit;

    } catch (Exception $e) {
        logSyncOperation($userId, 'oauth_error', 'error', 
            sprintf('OAuth failed for user %d: %s', $userId, $e->getMessage()));

        $_SESSION['oauth_error'] = $e->getMessage();
        header('Location: ../profilo_utente.php');
        exit;
    }
}


/**
 * Genera state sicuro per OAuth CSRF protection
 */
function generateSecureState($userId) {
    return hash('sha256', $userId . time() . random_bytes(16) . AMAZON_CLIENT_ID);
}

/**
 * Costruisce URL di autorizzazione Amazon SP-API
 */
function buildAuthorizationUrl($state) {
    $redirectUri = AMAZON_REDIRECT_URI;   // definita in api_config.php → …/amazon_callback.php

$params = [
    'application_id' => AMAZON_APPLICATION_ID,
    'redirect_uri'   => AMAZON_REDIRECT_URI,   // …/amazon_callback.php
    'state'          => $state,
    'version'        => 'beta'
];


    
    $authUrl = 'https://sellercentral-europe.amazon.com/apps/authorize/consent?' . http_build_query($params);
    
    return $authUrl;
}

/**
 * Scambia authorization code con refresh token
 * Chiamata REALE all'API Amazon LWA
 */
function exchangeCodeForToken($authCode) {
    try {
        $tokenUrl = 'https://api.amazon.com/auth/o2/token';
        $redirectUri = AMAZON_REDIRECT_URI;   // …/amazon_callback.php
        
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $authCode,
            'redirect_uri' => $redirectUri,
            'client_id' => AMAZON_CLIENT_ID,
            'client_secret' => AMAZON_CLIENT_SECRET
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: Margynomic/1.0',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("Errore connessione Amazon: " . $curlError);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Errore Amazon API (HTTP {$httpCode}): " . $response);
        }
        
        $tokenData = json_decode($response, true);
        
        if (!$tokenData || !isset($tokenData['refresh_token'])) {
            throw new Exception("Risposta token non valida da Amazon: " . $response);
        }
        
        return $tokenData['refresh_token'];
        
    } catch (Exception $e) {
        error_log("Errore exchangeCodeForToken: " . $e->getMessage());
        return false;
    }
}

/**
 * Salva refresh token nella tabella amazon_client_tokens
 */
function saveRefreshToken($userId, $refreshToken, $sellingPartnerId) {
    try {
        $pdo = getDbConnection();
        
        // Elimina token precedenti per questo utente (un utente = un token)
        $deleteStmt = $pdo->prepare("DELETE FROM amazon_client_tokens WHERE user_id = ?");
        $deleteStmt->execute([$userId]);
        
        // Inserisci nuovo token
        $stmt = $pdo->prepare("
            INSERT INTO amazon_client_tokens 
            (user_id, marketplace_id, refresh_token, seller_id, is_active, created_at, updated_at) 
            VALUES (?, ?, ?, ?, 1, NOW(), NOW())
        ");
        
        $result = $stmt->execute([
            $userId,
            'APJ6JRA9NG5V4', // Amazon.it
            $refreshToken,
            $sellingPartnerId
        ]);
        
        if ($result) {
            logSyncOperation($userId, 'token_saved_db', 'info', 'Refresh token salvato nel database', [
                'seller_id' => $sellingPartnerId,
                'marketplace_id' => 'APJ6JRA9NG5V4'
            ]);
        }
        
        return $result;
        
    } catch (PDOException $e) {
        error_log("Errore salvataggio token: " . $e->getMessage());
        return false;
    }
}

/**
 * Verifica se utente ha già un token valido
 */
function hasValidToken($userId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM amazon_client_tokens 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Gestione errori non gestiti
if (!function_exists('getDbConnection')) {
    die('Errore: configurazione database non trovata');
}

if (!defined('AMAZON_CLIENT_ID') || !defined('AMAZON_CLIENT_SECRET')) {
    die('Errore: credenziali Amazon non configurate');
}

?>