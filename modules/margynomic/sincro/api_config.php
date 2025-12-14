<?php

/**
 * Configurazione Amazon SP-API per Margynomic
 * File: sincro/api_config.php
 * 
 * Contiene tutte le costanti per l'autenticazione Amazon SP-API
 */

// Configurazione Amazon SP-API
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
    $query = "SELECT * FROM amazon_credentials WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $cred = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cred) {
        throw new Exception('Nessuna credenziale Amazon attiva trovata nel database');
    }

    define('AWS_ACCESS_KEY_ID', $cred['aws_access_key_id']);
    define('AWS_SECRET_ACCESS_KEY', $cred['aws_secret_access_key']);
    define('AWS_REGION', $cred['aws_region']);
    define('AMAZON_APPLICATION_ID', $cred['application_id']);
    define('AMAZON_CLIENT_ID', $cred['spapi_client_id']);
    define('AMAZON_CLIENT_SECRET', $cred['spapi_client_secret']);
    define('AMAZON_REDIRECT_URI', BASE_URL . '/sincro/amazon_callback.php');
} catch (Exception $e) {
    die("Errore nel caricamento credenziali Amazon: " . $e->getMessage());
}

// Endpoint Amazon SP-API
define('AMAZON_AUTH_URL', 'https://sellercentral.amazon.com/apps/authorize/consent');
define('AMAZON_TOKEN_URL', 'https://api.amazon.com/auth/o2/token');
define('AMAZON_SP_API_BASE_URL', 'https://sellingpartnerapi-eu.amazon.com');

// Marketplace ID per Europa
define('AMAZON_MARKETPLACE_ID', 'A1PA6795UKMFR9'); // Germania
define('AMAZON_MARKETPLACE_ID_IT', 'APJ6JRA9NG5V4'); // Italia
define('AMAZON_MARKETPLACE_ID_FR', 'A13V1IB3VIYZZH'); // Francia
define('AMAZON_MARKETPLACE_ID_ES', 'A1RKKUPIHCS9HS'); // Spagna
define('AMAZON_MARKETPLACE_ID_UK', 'A1F83G8C2ARO7P'); // Regno Unito

// Scopes richiesti
define('AMAZON_OAUTH_SCOPES', 'sellingpartnerapi::notifications sellingpartnerapi::migration');

// Configurazione report
define('AMAZON_REPORT_TYPE_SETTLEMENT', 'GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE');
define('AMAZON_REPORT_PROCESSING_STATUS_DONE', '_DONE_');
define('AMAZON_REPORT_PROCESSING_STATUS_IN_PROGRESS', '_IN_PROGRESS_');
define('AMAZON_REPORT_PROCESSING_STATUS_CANCELLED', '_CANCELLED_');

// Configurazione timeout e retry
define('AMAZON_API_TIMEOUT', 30); // secondi
define('AMAZON_API_MAX_RETRIES', 3);
define('AMAZON_TOKEN_REFRESH_THRESHOLD', 600); // 10 minuti prima della scadenza

// Configurazione logging
define('SYNC_LOG_LEVEL_DEBUG', 'DEBUG');
define('SYNC_LOG_LEVEL_INFO', 'INFO');
define('SYNC_LOG_LEVEL_WARNING', 'WARNING');
define('SYNC_LOG_LEVEL_ERROR', 'ERROR');

// Configurazione database
define('SYNC_TABLE_PREFIX', 'report_settlement_');
define('SYNC_CREDENTIALS_TABLE', 'amazon_credentials');
define('SYNC_DEBUG_LOG_TABLE', 'sync_debug_logs');

// Stati sincronizzazione
define('SYNC_STATUS_ACTIVE', 'active');
define('SYNC_STATUS_INACTIVE', 'inactive');
define('SYNC_STATUS_ERROR', 'error');
define('SYNC_STATUS_PENDING', 'pending');

// Messaggi di errore
define('SYNC_ERROR_INVALID_TOKEN', 'Token di accesso non valido o scaduto');
define('SYNC_ERROR_API_LIMIT', 'Limite API raggiunto, riprova più tardi');
define('SYNC_ERROR_NETWORK', 'Errore di connessione di rete');
define('SYNC_ERROR_PARSING', 'Errore nel parsing dei dati del report');
define('SYNC_ERROR_DATABASE', 'Errore di accesso al database');

// Configurazione user agent
define('AMAZON_USER_AGENT', 'Margynomic/1.0 (Language=PHP; Platform=' . PHP_OS . ')');

// Configurazione rate limiting
define('AMAZON_RATE_LIMIT_REQUESTS_PER_SECOND', 0.5); // 1 richiesta ogni 2 secondi
define('AMAZON_RATE_LIMIT_BURST', 10); // burst di massimo 10 richieste

// Configurazione file temporanei
define('TEMP_DOWNLOAD_DIR', __DIR__ . '/../uploads/temp/');
define('REPORT_ARCHIVE_DIR', __DIR__ . '/../uploads/reports/');

// Assicurati che le directory esistano
if (!is_dir(TEMP_DOWNLOAD_DIR)) {
    mkdir(TEMP_DOWNLOAD_DIR, 0755, true);
}

if (!is_dir(REPORT_ARCHIVE_DIR)) {
    mkdir(REPORT_ARCHIVE_DIR, 0755, true);
}

?>