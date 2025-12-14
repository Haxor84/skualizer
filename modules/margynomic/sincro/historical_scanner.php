<?php
/**
 * Historical Scanner Engine
 * File: sincro/historical_scanner.php
 * 
 * Engine per scansionare e processare file settlement storici
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once 'settlement_parser.php';
class HistoricalScanner {
    
    
    /**
     * Scansiona file storici per utente
     */
    public function scanUserHistoricalFiles($userId) {
        try {
            $downloadPath = dirname(__DIR__) . "/downloads/user_{$userId}/GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE";
            
            if (!is_dir($downloadPath)) {
                return [
                    'success' => false, 
                    'error' => "Cartella download non trovata: {$downloadPath}"
                ];
            }
            
            // Scansiona tutti i file TSV nella cartella
            $files = glob($downloadPath . '/*.tsv');
            $fileList = [];
            
            foreach ($files as $filePath) {
                $fileInfo = $this->analyzeFile($userId, $filePath);
                if ($fileInfo) {
                    $fileList[] = $fileInfo;
                }
            }
            
            // Ordina per data (più recenti prima)
            usort($fileList, function($a, $b) {
                return $b['modified'] - $a['modified'];
            });
            
            // Calcola statistiche
            $stats = $this->calculateStats($fileList);
            
            return [
                'success' => true,
                'files' => $fileList,
                'stats' => $stats,
                'scan_path' => $downloadPath
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Errore scansione: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Analizza singolo file
     */
    private function analyzeFile($userId, $filePath) {
        try {
            if (!file_exists($filePath)) {
                return null;
            }
            
            $fileName = basename($filePath);
            $fileSize = filesize($filePath);
            $fileModified = filemtime($filePath);
            
            // Verifica se è un file settlement valido
            if (!$this->isValidSettlementFile($filePath)) {
                return null;
            }
            
            // Estrai Settlement ID dal file
            $settlementId = $this->extractSettlementId($filePath);
            
            // Verifica stato del file
            $status = $this->getFileStatus($userId, $fileName, $settlementId);
            
            return [
                'name' => $fileName,
                'path' => $filePath,
                'relative_path' => str_replace(dirname(__DIR__), '', $filePath),
                'size' => $fileSize,
                'modified' => $fileModified,
                'settlement_id' => $settlementId,
                'status' => $status
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('historical_scanner', 'ERROR', 
                sprintf('Errore analisi file %s: %s', basename($filePath), $e->getMessage()));
            return null;
        }
    }
    
    /**
     * Verifica se il file è un settlement TSV valido
     */
    private function isValidSettlementFile($filePath) {
        try {
            // Leggi prime righe per validazione
            $handle = fopen($filePath, 'r');
            if (!$handle) return false;
            
            $firstLine = fgets($handle);
            fclose($handle);
            
            // Verifica header Amazon settlement
            $requiredHeaders = [
                'settlement-id',
                'settlement-start-date', 
                'settlement-end-date',
                'transaction-type',
                'order-id',
                'sku'
            ];
            
            $headers = str_getcsv($firstLine, "\t");
            $headers = array_map('trim', $headers);
            
            foreach ($requiredHeaders as $required) {
                if (!in_array($required, $headers)) {
                    return false;
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Estrae Settlement ID dal file TSV
     */
    private function extractSettlementId($filePath) {
        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) return null;
            
            // Prima riga = headers
            $headers = fgets($handle);
            // Seconda riga = dati summary con settlement-id
            $summaryLine = fgets($handle);
            fclose($handle);
            
            if (!$summaryLine) return null;
            
            $headerArray = str_getcsv($headers, "\t");
            $summaryArray = str_getcsv($summaryLine, "\t");
            
            $settlementIndex = array_search('settlement-id', $headerArray);
            
            if ($settlementIndex !== false && isset($summaryArray[$settlementIndex])) {
                return trim($summaryArray[$settlementIndex]);
            }
            
            return null;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Determina stato del file (new, processed, error)
     */
    private function getFileStatus($userId, $fileName, $settlementId) {
        try {
            $pdo = getDbConnection();
            
            // Controllo 1: File già importato per nome
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM import_log 
                WHERE user_id = ? AND filename = ?
            ");
            $stmt->execute([$userId, $fileName]);
            
            if ($stmt->fetchColumn() > 0) {
                return 'processed';
            }
            
            // Controllo 2: Settlement ID già importato
            if ($settlementId) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM import_log 
                    WHERE user_id = ? AND settlement_id = ?
                ");
                $stmt->execute([$userId, $settlementId]);
                
                if ($stmt->fetchColumn() > 0) {
                    return 'processed';
                }
            }
            
            return 'new';
            
        } catch (Exception $e) {
            CentralLogger::log('historical_scanner', 'ERROR', 
                sprintf('Errore verifica stato file: %s', $e->getMessage()));
            return 'error';
        }
    }
    
    /**
     * Calcola statistiche sui file
     */
    private function calculateStats($fileList) {
        $stats = [
            'total_files' => count($fileList),
            'new_files' => 0,
            'processed_files' => 0,
            'error_files' => 0,
            'total_size' => 0
        ];
        
        foreach ($fileList as $file) {
            $stats['total_size'] += $file['size'];
            
            switch ($file['status']) {
                case 'new':
                    $stats['new_files']++;
                    break;
                case 'processed':
                    $stats['processed_files']++;
                    break;
                case 'error':
                    $stats['error_files']++;
                    break;
            }
        }
        
        return $stats;
    }
    
    /**
     * Processa singolo file storico
     */
    public function processHistoricalFile($userId, $filePath) {
        try {
            // Verifica file esiste
            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'error' => 'File non trovato: ' . $filePath
                ];
            }
            
            // Verifica formato valido
            if (!$this->isValidSettlementFile($filePath)) {
                return [
                    'success' => false,
                    'error' => 'Formato file non valido (non è un settlement Amazon)'
                ];
            }
            
            // Usa il settlement parser esistente
            $result = parseSettlementFile($userId, $filePath);
            
            if ($result['success']) {
                // Log operazione storica
                $this->logHistoricalImport($userId, $filePath, $result);
                
                // Auto-mapping se disponibile
                $this->triggerAutoMapping($userId);
                
                return [
                    'success' => true,
                    'processed' => $result['processed'],
                    'skipped' => $result['skipped'] ?? 0,
                    'settlement_id' => $result['settlement_id'] ?? null,
                    'message' => "File processato con successo: {$result['processed']} righe"
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $result['error']
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Errore processing: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Processa batch di file storici
     */
    public function processHistoricalBatch($userId, $filePaths) {
        $results = [];
        $totalProcessed = 0;
        $totalErrors = 0;
        
        foreach ($filePaths as $filePath) {
            $result = $this->processHistoricalFile($userId, $filePath);
            
            $results[] = [
                'file' => basename($filePath),
                'success' => $result['success'],
                'processed' => $result['processed'] ?? 0,
                'error' => $result['error'] ?? null
            ];
            
            if ($result['success']) {
                $totalProcessed += $result['processed'] ?? 0;
            } else {
                $totalErrors++;
            }
            
            // Piccola pausa tra file per evitare sovraccarico
            usleep(100000); // 100ms
        }
        
        return [
            'success' => true,
            'results' => $results,
            'total_processed' => $totalProcessed,
            'total_errors' => $totalErrors,
            'files_count' => count($filePaths)
        ];
    }
    
    /**
     * Aggiunge file alla coda settlement (opzionale)
     */
    public function addFilesToQueue($userId, $filePaths) {
        try {
            $pdo = getDbConnection();
            $addedCount = 0;
            
            foreach ($filePaths as $filePath) {
                $settlementId = $this->extractSettlementId($filePath);
                
                if ($settlementId) {
                    // Simula un report_id basato su settlement_id
                    $reportId = 'HIST_' . $settlementId . '_' . time();
                    
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO settlement_report_queue 
                        (user_id, report_id, status, source, file_path, created_at) 
                        VALUES (?, ?, 'pending', 'historical', ?, NOW())
                    ");
                    
                    if ($stmt->execute([$userId, $reportId, $filePath])) {
                        $addedCount += $stmt->rowCount();
                    }
                }
            }
            
            return [
                'success' => true,
                'added_count' => $addedCount
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Errore aggiunta coda: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Log import storico
     */
    private function logHistoricalImport($userId, $filePath, $result) {
        try {
            // Log consolidato su sync_debug_logs
            if (function_exists('logSyncOperation')) {
                logSyncOperation(
                    $userId,
                    'historical_import_completed',
                    'info',
                    sprintf('Historical settlement imported: %d righe processate', $result['processed'] ?? 0),
                    [
                        'file_path' => basename($filePath),
                        'processed_rows' => $result['processed'] ?? 0,
                        'skipped_rows' => $result['skipped'] ?? 0,
                        'settlement_id' => $result['settlement_id'] ?? null
                    ]
                );
            }
        } catch (Exception $e) {
            CentralLogger::log('historical_scanner', 'ERROR', 
                sprintf('Errore log historical import: %s', $e->getMessage()));
        }
    }
    
    /**
     * Triggera auto-mapping dopo import
     */
    private function triggerAutoMapping($userId) {
    // Cattura tutto l'output per evitare interferenze con JSON
    ob_start();
    
    try {
        // Auto-mapping con sistema enterprise
        try {
            require_once dirname(__DIR__, 2) . '/mapping/MappingService.php';
            require_once dirname(__DIR__, 2) . '/mapping/MappingRepository.php';
            require_once dirname(__DIR__, 2) . '/mapping/config/mapping_config.php';
            
            $pdo = getDbConnection();
            $config = getMappingConfig();
            $mappingRepo = new MappingRepository($pdo, $config);
            $mappingService = new MappingService($mappingRepo, $config);
            $standardResult = $mappingService->executeFullMapping($userId);
            
            if ($standardResult['success']) {
                
                if (function_exists('logSyncOperation')) {
    logSyncOperation(
        $userId,
        'historical_enterprise_mapping',
        'info',
        'Auto-mapping enterprise post-import',
        ['mapped_skus' => $standardResult['mapped_skus'] ?? 0]
    );
                }
            }
        }
        
        // AI mapping se disponibile
        if (file_exists(__DIR__ . '/ai_sku_processor.php')) {
            require_once __DIR__ . '/ai_sku_processor.php';
            
            if (class_exists('AiSkuProcessor')) {
                $aiProcessor = new AiSkuProcessor();
                $aiResult = $aiProcessor->processUnmappedSkus($userId);
                
                if (function_exists('logSyncOperation')) {
                    logSyncOperation(
                        $userId,
                        'historical_ai_mapping',
                        'info',
                        'AI mapping post-import',
                        ['processed_count' => $aiResult['processed_count'] ?? 0]
                    );
                }
                // Log già gestito da logSyncOperation sopra
            }
        }
        
    } catch (Exception $e) {
        CentralLogger::log('historical_scanner', 'ERROR', 
            sprintf('Errore auto-mapping post historical import: %s', $e->getMessage()));
    } finally {
        // Pulisci output buffer senza visualizzare nulla
        ob_end_clean();
    }
}
    
    /**
     * Verifica integrità cartelle download
     */
    public function verifyDownloadStructure($userId) {
        $basePath = dirname(__DIR__) . "/downloads/user_{$userId}";
        $settlementPath = $basePath . "/GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE";
        
        $structure = [
            'base_exists' => is_dir($basePath),
            'settlement_exists' => is_dir($settlementPath),
            'base_writable' => is_writable($basePath),
            'settlement_writable' => is_writable($settlementPath),
            'base_path' => $basePath,
            'settlement_path' => $settlementPath
        ];
        
        // Crea cartelle se non esistono
        if (!$structure['base_exists']) {
            mkdir($basePath, 0755, true);
            $structure['base_exists'] = is_dir($basePath);
            $structure['base_writable'] = is_writable($basePath);
        }
        
        if (!$structure['settlement_exists']) {
            mkdir($settlementPath, 0755, true);
            $structure['settlement_exists'] = is_dir($settlementPath);
            $structure['settlement_writable'] = is_writable($settlementPath);
        }
        
        return $structure;
    }
    
    /**
     * Ottieni statistiche globali sistema
     */
    public function getGlobalStats() {
        try {
            $pdo = getDbConnection();
            
            // Conta file processati nel sistema
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_imports,
                    COUNT(DISTINCT user_id) as users_with_imports,
                    SUM(row_count_db) as total_rows
                FROM import_log
            ");
            $importStats = $stmt->fetch();
            
            // Conta utenti con cartelle
            $downloadDir = dirname(__DIR__) . '/downloads';
            $userDirs = glob($downloadDir . '/user_*', GLOB_ONLYDIR);
            $usersWithFolders = count($userDirs);
            
            return [
                'total_imports' => $importStats['total_imports'] ?? 0,
                'users_with_imports' => $importStats['users_with_imports'] ?? 0,
                'total_rows_imported' => $importStats['total_rows'] ?? 0,
                'users_with_folders' => $usersWithFolders,
                'download_base_path' => $downloadDir
            ];
            
        } catch (Exception $e) {
            return [
                'error' => 'Errore statistiche: ' . $e->getMessage()
            ];
        }
    }
}

// === FUNZIONI HELPER GLOBALI ===

/**
 * Scansiona file storici per utente
 */
function scanHistoricalFiles($userId) {
    $scanner = new HistoricalScanner();
    return $scanner->scanUserHistoricalFiles($userId);
}

/**
 * Processa singolo file storico
 */
function processHistoricalFile($userId, $filePath) {
    $scanner = new HistoricalScanner();
    return $scanner->processHistoricalFile($userId, $filePath);
}

/**
 * Processa batch file storici
 */
function processHistoricalBatch($userId, $filePaths) {
    $scanner = new HistoricalScanner();
    return $scanner->processHistoricalBatch($userId, $filePaths);
}

/**
 * Verifica struttura cartelle
 */
function verifyUserDownloadStructure($userId) {
    $scanner = new HistoricalScanner();
    return $scanner->verifyDownloadStructure($userId);
}

/**
 * Ottieni statistiche globali
 */
function getHistoricalGlobalStats() {
    $scanner = new HistoricalScanner();
    return $scanner->getGlobalStats();
}

?>