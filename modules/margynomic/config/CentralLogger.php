<?php
/**
 * CentralLogger - Sistema di Logging Centralizzato per Margynomic
 * File: modules/margynomic/config/CentralLogger.php
 * 
 * Gestisce tutti i log in formato JSON strutturato con rotazione automatica
 * e doppia scrittura (file + database per compatibilità admin_log.php)
 */

class CentralLogger {
    
    private static $modules = [
        'settlement' => 'settlement.log',
        'inventory' => 'inventory.log', 
        'oauth' => 'oauth.log',
        'email_notifications' => 'email_notifications.log',
        'admin' => 'admin.log',
        'orderinsights' => 'orderinsights.log',
        'mapping' => 'mapping.log',
        'historical' => 'historical.log',
        'ai' => 'ai.log',
        'token_refresh' => 'oauth.log' // Redirect to oauth
    ];
    
    private static $logDir = null;
    private static $maxFileSize = 10485760; // 10MB
    private static $maxBackupFiles = 5;
    
    /**
     * Inizializza il logger e determina la directory dei log
     */
    private static function init() {
        if (self::$logDir === null) {
            if (defined('LOG_BASE_DIR')) {
                self::$logDir = LOG_BASE_DIR;
            } else {
                // Fallback se LOG_BASE_DIR non è definito
                $currentDir = __DIR__;
                while (basename($currentDir) !== 'modules' && $currentDir !== dirname($currentDir)) {
                    $currentDir = dirname($currentDir);
                }
                self::$logDir = $currentDir . '/logs/';
            }
            
            // Crea directory se non esiste
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
        }
    }
    
    /**
     * Log principale - punto di accesso unico
     */
    public static function log($module, $level, $message, $context = []) {
        try {
            self::init();
            
            // Normalizza livello
            $level = strtoupper($level);
            if (!in_array($level, ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'])) {
                $level = 'INFO';
            }
            
            // Normalizza modulo
            if (!isset(self::$modules[$module])) {
                $module = 'admin'; // Default fallback
            }
            
            $logFile = self::$logDir . self::$modules[$module];
            
            // Prepara entry JSON strutturato
            $logEntry = [
                'timestamp' => date('c'), // ISO 8601
                'level' => $level,
                'module' => $module,
                'message' => $message,
                'context' => $context,
                'memory_mb' => round(memory_get_usage() / 1024 / 1024, 2),
                'user_id' => $_SESSION['user_id'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'php-cli'
            ];
            
            // Scrivi su file JSON
            self::writeToFile($logFile, $logEntry);
            
            // Scrivi in database per compatibilità admin_log.php
            self::writeToDatabase($module, $level, $message, $context);
            
            // Rotazione automatica se necessario
            self::rotateIfNeeded($logFile);
            
        } catch (Exception $e) {
            // Fallback su error_log PHP nativo se tutto fallisce
            error_log("CentralLogger FAILED: " . $e->getMessage() . " | Original: [$level] $message");
        }
    }
    
    /**
     * Scrive entry JSON nel file di log
     */
    private static function writeToFile($logFile, $logEntry) {
        $jsonLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        
        $result = file_put_contents($logFile, $jsonLine, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            throw new Exception("Impossibile scrivere nel file: $logFile");
        }
    }
    
    /**
     * Scrive in database per compatibilità con admin_log.php
     */
    private static function writeToDatabase($module, $level, $message, $context) {
        try {
            if (!function_exists('getDbConnection')) {
                return; // Skip se database non disponibile
            }
            
            $pdo = getDbConnection();
            
            // Questo permette di avere granularità alta nei log invece di tutto "cron_inventory_sync"
            $operationType = $context['operation_type'] ?? $module;
            $userId = $context['user_id'] ?? $_SESSION['user_id'] ?? null;

// Script cron/sistema senza user_id → skip DB, solo file log
if ($userId === null) {
    return;
}

// Verifica che user_id esista
$checkStmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
$checkStmt->execute([$userId]);
if (!$checkStmt->fetch()) {
    // Skip silenziosamente se user_id non esiste (es: user_id = 0 per operazioni di sistema)
    return;
}

            // Rimuovi operation_type e user_id dal context per evitare duplicazione nel JSON
            unset($context['operation_type']);
        unset($context['user_id']);
        
        // Sanitizza context: rimuovi risorse, limita dimensioni, assicura UTF-8 valido
        $cleanContext = self::sanitizeContextData($context);
        
        // Encoding JSON con flag sicurezza
        $contextJson = null;
        if (!empty($cleanContext)) {
            $contextJson = json_encode($cleanContext, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            
            // Verifica successo encoding
            if ($contextJson === false || json_last_error() !== JSON_ERROR_NONE) {
                error_log("CentralLogger: json_encode failed - " . json_last_error_msg());
                $contextJson = json_encode(['error' => 'json_encoding_failed', 'reason' => json_last_error_msg()]);
            }
            
            // Truncate se supera 60KB (sicurezza per constraint)
            if (strlen($contextJson) > 61440) {
                $contextJson = substr($contextJson, 0, 61440) . '..."[truncated]"}';
            }
            
            // Doppio check JSON_VALID prima di INSERT (prevenzione constraint violation)
            $validStmt = $pdo->prepare("SELECT JSON_VALID(?) as is_valid");
            $validStmt->execute([$contextJson]);
            if (!$validStmt->fetchColumn()) {
                error_log("CentralLogger: JSON invalid dopo encoding, forcing NULL");
                $contextJson = null;
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO sync_debug_logs 
            (user_id, operation_type, log_level, message, context_data, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$userId, $operationType, $level, $message, $contextJson]);
        
    } catch (Exception $e) {
        // Non bloccare se database fallisce, logga solo in error_log
        error_log("CentralLogger DB write failed: " . $e->getMessage());
    }
    }
    
    /**
     * Sanitizza context data per encoding JSON sicuro
     * Previene: risorse, riferimenti circolari, encoding invalido, array troppo grandi
     */
    private static function sanitizeContextData($data, $depth = 0, $maxDepth = 4) {
        // Previeni ricorsione eccessiva
        if ($depth > $maxDepth) {
            return '[max_depth_exceeded]';
        }
        
        // Array: limita numero elementi e sanitizza ricorsivamente
        if (is_array($data)) {
            $result = [];
            $count = 0;
            foreach ($data as $key => $value) {
                if ($count >= 50) {
                    $result['_truncated'] = (count($data) - 50) . ' more items';
                    break;
                }
                $result[$key] = self::sanitizeContextData($value, $depth + 1, $maxDepth);
                $count++;
            }
            return $result;
        }
        
        // Oggetti: converti in stringa rappresentazione
        if (is_object($data)) {
            return '[object:' . get_class($data) . ']';
        }
        
        // Risorse: non serializzabili
        if (is_resource($data)) {
            return '[resource:' . get_resource_type($data) . ']';
        }
        
        // Stringhe: assicura UTF-8 valido e limita lunghezza
        if (is_string($data)) {
            if (!mb_check_encoding($data, 'UTF-8')) {
                $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            }
            if (strlen($data) > 2000) {
                $data = substr($data, 0, 2000) . '...[truncated]';
            }
            return $data;
        }
        
        // Float: gestisci infinito/NaN
        if (is_float($data) && (is_infinite($data) || is_nan($data))) {
            return 0.0;
        }
        
        // Valori semplici: ritorna così come sono
        return $data;
    }

    /**
     * Rotazione file quando superano la dimensione massima
     */
    private static function rotateIfNeeded($logFile) {
        if (!file_exists($logFile) || filesize($logFile) < self::$maxFileSize) {
            return;
        }
        
        try {
            // Sposta i backup esistenti
            for ($i = self::$maxBackupFiles; $i > 1; $i--) {
                $oldFile = $logFile . '.' . ($i - 1);
                $newFile = $logFile . '.' . $i;
                
                if (file_exists($oldFile)) {
                    if (file_exists($newFile)) {
                        unlink($newFile); // Rimuovi il più vecchio
                    }
                    rename($oldFile, $newFile);
                }
            }
            
            // Sposta il file corrente al backup .1
            rename($logFile, $logFile . '.1');
            
            // Log della rotazione nel nuovo file
            self::writeToFile($logFile, [
                'timestamp' => date('c'),
                'level' => 'INFO',
                'module' => 'system',
                'message' => 'Log rotated - file size exceeded ' . (self::$maxFileSize / 1024 / 1024) . 'MB',
                'context' => ['old_file' => basename($logFile) . '.1'],
                'memory_mb' => 0,
                'user_id' => null,
                'ip' => 'system',
                'user_agent' => 'log-rotator'
            ]);
            
        } catch (Exception $e) {
            error_log("CentralLogger rotation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Metodi helper per livelli specifici
     */
    public static function debug($module, $message, $context = []) {
        self::log($module, 'DEBUG', $message, $context);
    }
    
    public static function info($module, $message, $context = []) {
        self::log($module, 'INFO', $message, $context);
    }
    
    public static function warning($module, $message, $context = []) {
        self::log($module, 'WARNING', $message, $context);
    }
    
    public static function error($module, $message, $context = []) {
        self::log($module, 'ERROR', $message, $context);
    }
    
    public static function critical($module, $message, $context = []) {
        self::log($module, 'CRITICAL', $message, $context);
    }
    
    /**
     * Pulizia automatica log vecchi (chiamata opzionale da cron)
     */
    public static function cleanup($retentionDays = 30) {
        try {
            self::init();
            
            $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
            $cleaned = 0;
            
            foreach (self::$modules as $module => $filename) {
                $logFile = self::$logDir . $filename;
                
                // Pulisci backup vecchi
                for ($i = 1; $i <= self::$maxBackupFiles; $i++) {
                    $backupFile = $logFile . '.' . $i;
                    if (file_exists($backupFile) && filemtime($backupFile) < $cutoffTime) {
                        unlink($backupFile);
                        $cleaned++;
                    }
                }
            }
            
            if ($cleaned > 0) {
                self::info('system', "Cleanup completed: removed $cleaned old log files");
            }
            
            return $cleaned;
            
        } catch (Exception $e) {
            error_log("CentralLogger cleanup failed: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Ottieni statistiche sui log
     */
    public static function getStats() {
        try {
            self::init();
            
            $stats = [];
            $totalSize = 0;
            
            foreach (self::$modules as $module => $filename) {
                $logFile = self::$logDir . $filename;
                
                if (file_exists($logFile)) {
                    $size = filesize($logFile);
                    $totalSize += $size;
                    
                    $stats[$module] = [
                        'file' => $filename,
                        'size_mb' => round($size / 1024 / 1024, 2),
                        'last_modified' => filemtime($logFile),
                        'needs_rotation' => $size > self::$maxFileSize
                    ];
                }
            }
            
            $stats['_summary'] = [
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'log_directory' => self::$logDir,
                'max_file_size_mb' => round(self::$maxFileSize / 1024 / 1024, 2),
                'max_backup_files' => self::$maxBackupFiles
            ];
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("CentralLogger getStats failed: " . $e->getMessage());
            return [];
        }
    }
}

// Auto-inizializzazione se incluso
if (defined('LOG_BASE_DIR')) {
    // Test di funzionamento al primo caricamento
    try {
        CentralLogger::info('system', 'CentralLogger initialized successfully');
    } catch (Exception $e) {
        error_log("CentralLogger initialization failed: " . $e->getMessage());
    }
}
?>