<?php
// AGGIUNGI QUESTE RIGHE PER DEBUG
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Log per debug
function debugLogPopulate($message) {
    error_log("[POPULATE_QUEUE] " . $message);
    echo "[DEBUG] " . $message . "<br>";
}

/**
 * Script Popolamento Coda Report Settlement
 * File: sincro/populate_queue.php
 * 
 * Popola la coda con tutti i report disponibili per l'utente loggato
 */

session_start();

// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

require_once '../config/config.php';
require_once 'api_config.php';
require_once 'sync_helpers.php';

$userId = $_SESSION['user_id'];
$message = '';
$messageType = 'success';

try {
    // Verifica se utente ha token Amazon attivo
    $credentials = getAmazonCredentials($userId);
    if (!$credentials) {
        $_SESSION['oauth_error'] = 'Token Amazon non trovato. Autorizza prima il tuo account Amazon.';
        header('Location: ../profilo_utente.php');
        exit;
    }
    
 // Debug credenziali
debugLogPopulate("User ID: " . $userId);
debugLogPopulate("Verifica credenziali...");

$credentials = getAmazonCredentials($userId);
debugLogPopulate("Credenziali trovate: " . ($credentials ? 'SI' : 'NO'));

if ($credentials) {
    debugLogPopulate("Refresh token presente: " . (isset($credentials['refresh_token']) ? 'SI' : 'NO'));
}

// Popola la coda con tutti i report disponibili
debugLogPopulate("Chiamata popolaCodaReport...");
$reportAggiunti = popolaCodaReport($userId);
debugLogPopulate("Risultato popolaCodaReport: " . ($reportAggiunti === false ? 'FALSE' : $reportAggiunti));
    
    if ($reportAggiunti === false) {
        $message = 'Errore durante il recupero dei report da Amazon. Riprova più tardi.';
        $messageType = 'error';
    } else {
        if ($reportAggiunti > 0) {
            $message = "✅ Trovati e aggiunti {$reportAggiunti} nuovi report alla coda di download.";
            $messageType = 'success';
        } else {
            $message = "ℹ️ Tutti i report disponibili sono già stati aggiunti alla coda.";
            $messageType = 'info';
        }
    }
    
    // LOG UNICO CONSOLIDATO
    logSyncOperation($userId, 'populate_queue_completed', 
        $reportAggiunti === false ? 'error' : 'info',
        sprintf('Queue popolata: %d report aggiunti per user %d', 
            $reportAggiunti ?: 0, $userId),
        [
            'user_id' => $userId,
            'reports_added' => $reportAggiunti ?: 0,
            'status' => $reportAggiunti === false ? 'failed' : 'success'
        ]
    );
    
} catch (Exception $e) {
    $message = 'Errore imprevisto: ' . $e->getMessage();
    $messageType = 'error';
    
    logSyncOperation($userId, 'populate_queue_exception', 'error', 
        'Eccezione durante popolamento coda: ' . $e->getMessage());
}

// Imposta messaggio per la pagina di destinazione
if ($messageType === 'success' || $messageType === 'info') {
    $_SESSION['oauth_success'] = $message;
} else {
    $_SESSION['oauth_error'] = $message;
}

// Redirect al profilo utente
header('Location: ../profilo_utente.php');
exit;
?>