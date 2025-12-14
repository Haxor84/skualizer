<?php
/**
 * Configurazione Condivisa Modulo Margini
 * File: modules/margynomic/margini/config_shared.php
 */

// Price constants
if (!defined('MIN_LISTING_PRICE')) define('MIN_LISTING_PRICE', 1.00);
if (!defined('MAX_LISTING_PRICE')) define('MAX_LISTING_PRICE', 999.99);
if (!defined('AMAZON_SP_API_BASE_URL')) define('AMAZON_SP_API_BASE_URL','https://sellingpartnerapi-eu.amazon.com');
if (!defined('AMAZON_MARKETPLACE_ID_IT')) define('AMAZON_MARKETPLACE_ID_IT', 'APJ6JRA9NG5V4'); // Amazon.it
if (!defined('AMAZON_MARKETPLACE_ID')) define('AMAZON_MARKETPLACE_ID', 'APJ6JRA9NG5V4'); // Backward compatibility
if (!defined('USER_AGENT')) define('USER_AGENT', 'Skualizer/1.0 (Language=PHP; Platform=Linux)');
if (!defined('AWS_REGION')) define('AWS_REGION', 'eu-west-1');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/login/auth_helpers.php';

// Initialize debug logging after config is loaded
if (file_exists(dirname(__DIR__) . '/../logs/ApiDebugLogger.php')) {
    require_once dirname(__DIR__) . '/../logs/ApiDebugLogger.php';
    
    // Debug mode per API Logger (false in produzione)
    if (!defined('API_DEBUG_MODE')) {
        define('API_DEBUG_MODE', false);
    }
    
    $logger = ApiDebugLogger::getInstance();
    
    // FORZA MODALITÀ PRODUZIONE
    if (!defined('TEST_MODE')) define('TEST_MODE', false);
    
    // Log ambiente effettivo
    $logger->info('CONFIG_BOOTSTRAP', json_encode([
        'test_mode' => TEST_MODE,
        'region' => AWS_REGION,
        'base_url' => AMAZON_SP_API_BASE_URL,
        'marketplace_ids' => [AMAZON_MARKETPLACE_ID],
        'user_agent' => USER_AGENT,
        'min_price' => MIN_LISTING_PRICE,
        'max_price' => MAX_LISTING_PRICE
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    // Log integrità credenziali per utente corrente
    try {
        if (function_exists('getCurrentUser')) {
            $currentUser = getCurrentUser();
            if ($currentUser && isset($currentUser['id'])) {
                $db = getDbConnection();
                
                // Controlla credenziali Amazon
                $stmt = $db->prepare("SELECT id, is_active, created_at FROM amazon_credentials WHERE is_active = 1 LIMIT 1");
                $stmt->execute();
                $credentials = $stmt->fetch();
                
                // Controlla token utente
                $stmt = $db->prepare("SELECT id, marketplace_id, is_active, created_at FROM amazon_client_tokens WHERE user_id = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$currentUser['id']]);
                $userToken = $stmt->fetch();
                
                $logger->info('CREDENTIALS_INTEGRITY', json_encode([
                    'user_id' => $currentUser['id'],
                    'has_amazon_credentials' => !empty($credentials),
                    'has_user_token' => !empty($userToken),
                    'marketplace_id' => $userToken['marketplace_id'] ?? null,
                    'credentials_age_days' => $credentials ? round((time() - strtotime($credentials['created_at'])) / 86400) : null,
                    'token_age_days' => $userToken ? round((time() - strtotime($userToken['created_at'])) / 86400) : null
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                
                if (empty($credentials) || empty($userToken)) {
                    $logger->error('MISSING_CREDENTIALS', json_encode([
                        'user_id' => $currentUser['id'],
                        'marketplace_requested' => defined('AMAZON_MARKETPLACE_ID') ? AMAZON_MARKETPLACE_ID : null,
                        'missing_credentials' => empty($credentials),
                        'missing_user_token' => empty($userToken)
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }
    } catch (Exception $e) {
        $logger->warning('CREDENTIALS_CHECK_FAILED', json_encode([
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

/**
 * Verifica autenticazione utente
 */
function requireUserAuth() {
    if (!isLoggedIn()) {
        header('Location: ../login/login.php');
        exit();
    }
    return getCurrentUser();
}

/**
 * Ottiene categoria per transaction_type
 */
function getTransactionCategory($transactionType, $userId = null) {
    try {
        $pdo = getDbConnection();
        
        // Prima cerca mapping user-specific
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT category 
                FROM transaction_fee_mappings 
                WHERE transaction_type = ? AND user_id = ? AND is_active = 1
            ");
            $stmt->execute([$transactionType, $userId]);
            $result = $stmt->fetchColumn();
            if ($result) return $result;
        }
        
        // Fallback a mapping globale
        $stmt = $pdo->prepare("
            SELECT category 
            FROM transaction_fee_mappings 
            WHERE transaction_type = ? AND user_id IS NULL AND is_active = 1
        ");
        $stmt->execute([$transactionType]);
        $result = $stmt->fetchColumn();
        
        return $result ?: 'UNMAPPED';
        
    } catch (Exception $e) {
        CentralLogger::log('margini', 'ERROR', 'Errore getTransactionCategory: ' . $e->getMessage());
        return 'UNMAPPED';
    }
}

/**
 * Ottiene tutte le categorie attive
 */
function getAllFeeCategories() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT category_code, category_name, group_type 
            FROM fee_categories 
            WHERE is_active = 1 
            ORDER BY sort_order, category_name
        ");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        CentralLogger::log('margini', 'ERROR', 'Errore getAllFeeCategories: ' . $e->getMessage());
        return [
            ['category_code' => 'FEE_TAB1', 'category_name' => 'Commissioni Vendita', 'group_type' => 'TAB1'],
            ['category_code' => 'FEE_TAB2', 'category_name' => 'Costi Operativi', 'group_type' => 'TAB2'],
            ['category_code' => 'FEE_TAB3', 'category_name' => 'Compensi/Danni', 'group_type' => 'TAB3']
        ];
    }
}

/**
 * Exchange rates semplificati
 */
function getExchangeRate($marketplace) {
    $rates = [
        'Amazon.it' => 1.0,
        'Amazon.de' => 1.0,
        'Amazon.fr' => 1.0,
        'Amazon.es' => 1.0,
        'Amazon.nl' => 1.0,
        'Amazon.co.uk' => 1.15,
        'Amazon.se' => 0.095,
        'Amazon.pl' => 0.23
    ];
    
    return $rates[$marketplace] ?? 1.0;
}

/**
 * Marketplace mapping
 */
function getMarketplaceIdForCountry(string $cc): string {
    $map = ['IT'=>'APJ6JRA9NG5V4','FR'=>'A13V1IB3VIYZZH','ES'=>'A1RKKUPIHCS9HS','DE'=>'A1PA6795UKMFR9','UK'=>'A1F83G8C2ARO7P'];
    return $map[strtoupper($cc)] ?? 'APJ6JRA9NG5V4';
}

/**
 * Logging semplificato
 */
function logMarginsOperation($message, $context = []) {
    CentralLogger::log('margini', 'INFO', $message, $context);
}
?>