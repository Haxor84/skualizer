<?php
/**
 * Configurazione Sistema di Mapping Refactorizzato
 * File: /modules/mapping/config/mapping_config.php
 * Ottiene la configurazione completa del sistema di mapping.
 * @return array Configurazione del mapping
 */

// Includi config principale per avere LOG_BASE_DIR
require_once dirname(__DIR__, 2) . '/margynomic/config/config.php';
function getMappingConfig(): array
{
    return [
        // === CONFIGURAZIONE GENERALE ===
        'version' => '2.0.0',
        'debug_mode' => false, // Produzione: false | Dev: true
        'batch_size' => 100,
        'max_execution_time' => 300, // 5 minuti
        'memory_limit' => '512M',
        
        // === CONFIGURAZIONE CONFIDENCE E SOGLIE ===
        'min_confidence' => 0.85,
        'auto_lock_threshold' => 0.95,
        'conflict_threshold' => 0.60,
        'mapping_max_fuzzy_results' => 5,
        
        // === CONFIGURAZIONE STRATEGIE ===
        'strategies' => [
            'auto_exact' => [
                'enabled' => true,
                'priority' => 1,
                'confidence' => 1.00,
                'timeout' => 30
            ],
            'auto_fuzzy' => [
                'enabled' => true,
                'priority' => 2,
                'confidence' => 0.85,
                'timeout' => 60,
                'min_similarity' => 0.75
            ],
            'ai_assisted' => [
                'enabled' => false, // Disabilitato di default
                'priority' => 3,
                'confidence' => 0.80,
                'timeout' => 120
            ]
        ],
        
        // === CONFIGURAZIONE APPROVAZIONI FUZZY ===
        'fuzzy_approval' => [
            'enabled' => true,
            'auto_approve_threshold' => 0.95, // Auto-approva solo match >95%
            'require_approval_below' => 0.90, // Richiede approvazione sotto 90%
            'max_pending_per_user' => 100,    // Massimo pending per utente
            'auto_reject_below' => 0.60       // Auto-rifiuta sotto 60%
        ],
        
        // === CONFIGURAZIONE AI ===
        'ai' => [
            'enabled' => false,
            'provider' => 'openai', // openai, anthropic, local
            'model' => 'gpt-3.5-turbo',
            'api_key' => '', // Impostare tramite variabile d'ambiente
            'max_tokens' => 1000,
            'temperature' => 0.3,
            'timeout' => 30
        ],
        
        // === CONFIGURAZIONE SORGENTI DATI ===
        'sources' => [
            'inventory' => [
                'enabled' => true,
                'table' => 'inventory',
                'sku_field' => 'sku',
                'product_id_field' => 'product_id',
                'user_id_field' => 'user_id',
                'additional_fields' => ['asin', 'product_name']
            ],
            'inventory_fbm' => [
                'enabled' => true,
                'table' => 'inventory_fbm',
                'sku_field' => 'seller_sku',
                'product_id_field' => 'product_id',
                'user_id_field' => 'user_id',
                'additional_fields' => ['asin1', 'item_name']
            ],
            'settlement' => [
                'enabled' => true,
                'table_pattern' => 'report_settlement_{user_id}',
                'sku_field' => 'sku',
                'product_id_field' => 'product_id',
                'user_id_field' => null, // Implicito nel nome tabella
                'additional_fields' => []
            ],
            'shipments_trid' => [
                'enabled' => true,
                'table' => 'shipments_trid',
                'sku_field' => 'msku',
                'product_id_field' => 'product_id',
                'user_id_field' => 'user_id',
                'additional_fields' => ['fnsku', 'asin', 'title']
            ]
        ],
        
        // === CONFIGURAZIONE AUDIT E LOGGING ===
        'audit' => [
            'enabled' => true,
            'retention_days' => 90,
            'log_all_actions' => true,
            'log_performance' => true
        ],
        
        'logging' => [
            'enabled' => true,
            'file' => dirname(__DIR__, 2) . '/logs/mapping.log', // Path corretto
            'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
            'max_file_size' => '10MB',
            'rotate_files' => true,
            'max_files' => 5
        ],
        
        // === CONFIGURAZIONE PERFORMANCE ===
        'performance' => [
            'enable_caching' => false, // Cache rimossa nel refactoring
            'cache_ttl' => 3600,
            'enable_parallel_processing' => false,
            'max_parallel_workers' => 4,
            'chunk_size' => 50
        ],
        
        // === CONFIGURAZIONE CONFLITTI ===
        'conflicts' => [
            'auto_resolve' => true,
            'resolution_strategies' => [
                'confidence_based' => true,
                'type_priority' => true,
                'manual_override' => true
            ],
            'escalation_threshold' => 0.5,
            'max_auto_resolution_attempts' => 3
        ],
        
        // === CONFIGURAZIONE PRODOTTI ===
        'products' => [
            'auto_create_products' => false,
            'allow_product_creation' => true,
            'require_unique_names' => false,
            'default_product_fields' => ['nome', 'sku', 'asin', 'fnsku']
        ],
        
        // === CONFIGURAZIONE SICUREZZA ===
        'security' => [
            'require_user_authentication' => true,
            'log_security_events' => true,
            'rate_limiting' => [
                'enabled' => false,
                'max_requests_per_minute' => 60,
                'max_requests_per_hour' => 1000
            ]
        ],
        
        // === CONFIGURAZIONE MANUTENZIONE ===
        'maintenance' => [
            'auto_cleanup_enabled' => true,
            'cleanup_schedule' => 'daily', // daily, weekly, monthly
            'cleanup_old_logs_days' => 30,
            'cleanup_old_states_days' => 90,
            'cleanup_resolved_conflicts_days' => 30
        ],
        
        // === CONFIGURAZIONE NOTIFICHE ===
        'notifications' => [
            'enabled' => false,
            'email_on_conflicts' => false,
            'email_on_errors' => false,
            'webhook_url' => '',
            'slack_webhook' => ''
        ],
        
        // === CONFIGURAZIONE AGGREGAZIONE SKU ===
        'aggregation' => [
            'max_skus_per_product' => 1000,
            'allow_cross_source_aggregation' => true,
            'require_confirmation' => false,
            'auto_update_product_name' => false
        ],
        
        // === CONFIGURAZIONE INTERFACCIA ===
        'ui' => [
            'items_per_page' => 50,
            'max_search_results' => 100,
            'enable_bulk_operations' => true,
            'auto_refresh_interval' => 30, // secondi
            'theme' => 'margynomic'
        ]
    ];
}

/**
 * Ottiene la connessione al database per il sistema di mapping.
 * @return PDO Connessione al database
 */
function getMappingDbConnection(): PDO
{
    // Utilizza la configurazione esistente di Margynomic
    require_once dirname(__DIR__, 2) . '/margynomic/config/config.php';
    return getDbConnection();
}

/**
 * Valida la configurazione del mapping.
 * @param array $config Configurazione da validare
 * @return array Array con 'valid' (bool) e 'errors' (array)
 */
function validateMappingConfig(array $config): array
{
    $errors = [];
    
    // Validazione soglie confidence
    if ($config['min_confidence'] < 0 || $config['min_confidence'] > 1) {
        $errors[] = "min_confidence deve essere tra 0 e 1";
    }
    
    if ($config['auto_lock_threshold'] < 0 || $config['auto_lock_threshold'] > 1) {
        $errors[] = "auto_lock_threshold deve essere tra 0 e 1";
    }
    
    if ($config['min_confidence'] > $config['auto_lock_threshold']) {
        $errors[] = "min_confidence non può essere maggiore di auto_lock_threshold";
    }
    
    // Validazione strategie
    foreach ($config['strategies'] as $name => $strategy) {
        if (!isset($strategy['enabled']) || !isset($strategy['priority'])) {
            $errors[] = "Strategia {$name} deve avere 'enabled' e 'priority'";
        }
        
        if (isset($strategy['confidence']) && ($strategy['confidence'] < 0 || $strategy['confidence'] > 1)) {
            $errors[] = "Confidence per strategia {$name} deve essere tra 0 e 1";
        }
    }
    
    // Validazione sorgenti
    foreach ($config['sources'] as $name => $source) {
        if (!isset($source['enabled']) || !isset($source['sku_field'])) {
            $errors[] = "Sorgente {$name} deve avere 'enabled' e 'sku_field'";
        }
    }
    
    // Validazione logging
    if ($config['logging']['enabled'] && !is_writable(dirname($config['logging']['file']))) {
        $errors[] = "Directory log non scrivibile: " . dirname($config['logging']['file']);
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Ottiene una configurazione specifica per ambiente.
 * @param string $environment Ambiente (development, staging, production)
 * @return array Configurazione specifica per ambiente
 */
function getMappingConfigForEnvironment(string $environment): array
{
    $baseConfig = getMappingConfig();
    
    switch ($environment) {
        case 'development':
            $baseConfig['debug_mode'] = true;
            $baseConfig['logging']['level'] = 'DEBUG';
            $baseConfig['ai']['enabled'] = false;
            $baseConfig['performance']['enable_caching'] = false;
            break;
            
        case 'staging':
            $baseConfig['debug_mode'] = true;
            $baseConfig['logging']['level'] = 'INFO';
            $baseConfig['ai']['enabled'] = true;
            $baseConfig['performance']['enable_caching'] = true;
            break;
            
        case 'production':
            $baseConfig['debug_mode'] = false;
            $baseConfig['logging']['level'] = 'WARNING';
            $baseConfig['ai']['enabled'] = true;
            $baseConfig['performance']['enable_caching'] = true;
            $baseConfig['maintenance']['auto_cleanup_enabled'] = true;
            break;
            
        default:
            // Usa configurazione base
            break;
    }
    
    return $baseConfig;
}

/**
 * Inizializza il sistema di logging per il mapping.
 * @param array $config Configurazione logging
 * @return bool True se inizializzazione riuscita
 */
function initializeMappingLogging(array $config): bool
{
    if (!$config['logging']['enabled']) {
        return true;
    }
    
    $logDir = dirname($config['logging']['file']);
    
    // Crea directory log se non esiste
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("Impossibile creare directory log: {$logDir}");
            return false;
        }
    }
    
    // Verifica permessi scrittura
    if (!is_writable($logDir)) {
        error_log("Directory log non scrivibile: {$logDir}");
        return false;
    }
    
    // Rotazione file se necessario
    if ($config['logging']['rotate_files'] && file_exists($config['logging']['file'])) {
        $fileSize = filesize($config['logging']['file']);
        $maxSize = $config['logging']['max_file_size'];
        
        // Converti max_file_size in bytes
        $maxSizeBytes = 0;
        if (preg_match('/^(\d+)(MB|KB|GB)?$/i', $maxSize, $matches)) {
            $size = (int)$matches[1];
            $unit = strtoupper($matches[2] ?? '');
            
            switch ($unit) {
                case 'GB':
                    $maxSizeBytes = $size * 1024 * 1024 * 1024;
                    break;
                case 'MB':
                    $maxSizeBytes = $size * 1024 * 1024;
                    break;
                case 'KB':
                    $maxSizeBytes = $size * 1024;
                    break;
                default:
                    $maxSizeBytes = $size;
            }
        }
        
        if ($fileSize > $maxSizeBytes) {
            // Ruota i file
            for ($i = $config['logging']['max_files'] - 1; $i > 0; $i--) {
                $oldFile = $config['logging']['file'] . '.' . $i;
                $newFile = $config['logging']['file'] . '.' . ($i + 1);
                
                if (file_exists($oldFile)) {
                    rename($oldFile, $newFile);
                }
            }
            
            // Sposta il file corrente
            rename($config['logging']['file'], $config['logging']['file'] . '.1');
        }
    }
    
    return true;
}

// Inizializzazione automatica se il file viene incluso
if (!defined('MAPPING_CONFIG_LOADED')) {
    define('MAPPING_CONFIG_LOADED', true);
    
    // Carica configurazione per ambiente corrente (PRODUZIONE di default)
    $environment = $_ENV['APP_ENV'] ?? 'production';
    $mappingConfig = getMappingConfigForEnvironment($environment);
    
    // Valida configurazione
    $validation = validateMappingConfig($mappingConfig);
    if (!$validation['valid']) {
        error_log("Errori configurazione mapping: " . implode(', ', $validation['errors']));
    }
    
    // Inizializza logging
    initializeMappingLogging($mappingConfig);
}

?>