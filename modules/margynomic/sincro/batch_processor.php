<?php

/**
 * Processore Batch Report Settlement con Auto-Mapping - VERSIONE AGGIORNATA
 * File: modules/margynomic/batch_processor.php
 * 
 * Script per processare un report alla volta dalla coda + automapping diretto
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/api_config.php';
require_once __DIR__ . '/sync_helpers.php';
require_once dirname(__DIR__, 2) . '/mapping/MappingService.php';
require_once dirname(__DIR__, 2) . '/mapping/MappingRepository.php';  
require_once dirname(__DIR__, 2) . '/mapping/config/mapping_config.php';

// Verifica se script già in esecuzione
$lockFile = __DIR__ . '/batch_processor.lock';
if (file_exists($lockFile)) {
    $pid = file_get_contents($lockFile);
    if (posix_kill($pid, 0)) {
        exit("Script già in esecuzione (PID: $pid)");
    }
}


// Crea lock file
file_put_contents($lockFile, getmypid());

try {
    $result = processaUnReportDallaCoda();
    
    if ($result && $result['success']) {
        // FASE 1: Parse settlement file
        require_once __DIR__ . '/settlement_parser.php';
        $parseResult = parseSettlementFile($result['user_id'], $result['file_path']);
        
        if ($parseResult['success']) {
            // FASE 2: Enterprise Mapping
            $pdo = getDbConnection();
            $config = getMappingConfig();
            $mappingRepo = new MappingRepository($pdo, $config);
            $mappingService = new MappingService($mappingRepo, $config);
            $mappingResult = $mappingService->executeFullMapping($result['user_id']);
            
            if ($mappingResult['success']) {
                $mappedCount = $mappingResult['mapped_skus'] ?? 0;
                // Log già gestito da MappingService
            } else {
                // Errore mapping già loggato da MappingService
            }
        } else {
            // Errore parsing già loggato da settlement_parser
        }
        
        // LOG UNICO CONSOLIDATO (enhanced for daily report)
        if ($result && $result['success']) {
            $startTime = microtime(true);
            
            // Get settlement details from DB (last import)
            $settlementDetails = [
                'settlement_id' => null,
                'period_start' => null,
                'period_end' => null,
                'total_fees' => 0,
                'file_size_kb' => 0
            ];
            
            try {
                $tableName = "report_settlement_{$result['user_id']}";
                $pdo = getDbConnection();
                
                // Get file size
                if (file_exists($result['file_path'])) {
                    $settlementDetails['file_size_kb'] = round(filesize($result['file_path']) / 1024, 2);
                }
                
                // Get latest imported data details
                $stmt = $pdo->query("
                    SELECT 
                        settlement_id,
                        MIN(posted_date) as period_start,
                        MAX(posted_date) as period_end,
                        SUM(CASE 
                            WHEN transaction_type LIKE '%Fee%' 
                            THEN ABS(total_amount) 
                            ELSE 0 
                        END) as total_fees
                    FROM {$tableName}
                    WHERE date_uploaded >= NOW() - INTERVAL 5 MINUTE
                    GROUP BY settlement_id
                    ORDER BY date_uploaded DESC
                    LIMIT 1
                ");
                
                $details = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($details) {
                    $settlementDetails = array_merge($settlementDetails, $details);
                }
            } catch (Exception $e) {
                // Silent failure - non-critical for log
            }
            
            $processingTime = round((microtime(true) - $startTime) * 1000, 0);
            
            logSyncOperation($result['user_id'], 'batch_report_processed', 'info',
                sprintf('Report processato: %d righe, %d SKU mappati | Settlement %s | Fees €%.2f',
                    $parseResult['processed'] ?? 0,
                    $mappingResult['mapped_skus'] ?? 0,
                    $settlementDetails['settlement_id'] ?? 'N/A',
                    $settlementDetails['total_fees']
                ),
                [
                    'user_id' => $result['user_id'],
                    'parsed_rows' => $parseResult['processed'] ?? 0,
                    'mapped_skus' => $mappingResult['mapped_skus'] ?? 0,
                    'mapping_success' => $mappingResult['success'] ?? false,
                    'file_path' => $result['file_path'],
                    'file_size_kb' => $settlementDetails['file_size_kb'],
                    'settlement_id' => $settlementDetails['settlement_id'],
                    'period_start' => $settlementDetails['period_start'],
                    'period_end' => $settlementDetails['period_end'],
                    'total_fees' => round($settlementDetails['total_fees'], 2),
                    'processing_time_ms' => $processingTime
                ]
            );
        }
    } else {
        // Nessun report - silenzioso
    }
    
} catch (Exception $e) {
    CentralLogger::log('batch_processor', 'ERROR', 
        sprintf('FATAL batch processor: %s', $e->getMessage()));
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

/**
 * Scarica report specifico dalla coda
 * Questa funzione non è stata modificata in questa fase, ma potrebbe richiedere refactoring
 * per integrarsi meglio con un sistema di gestione code più robusto.
 */
function scaricaReportDallaCoda($reportInfo) {
    try {
        $credentials = getAmazonCredentials($reportInfo['user_id']);
        if (!$credentials) {
            return ['success' => false, 'error' => 'Credenziali Amazon non trovate'];
        }
        
        $accessToken = getAccessToken(
            AMAZON_CLIENT_ID,
            AMAZON_CLIENT_SECRET,
            $credentials['refresh_token']
        );
        
        // Prima ottieni il reportDocumentId dal reportId
        $reportResponse = signedRequest(
            'GET',
            'https://sellingpartnerapi-eu.amazon.com/reports/2021-06-30/reports/' . $reportInfo['report_id'],
            $accessToken,
            AWS_ACCESS_KEY_ID,
            AWS_SECRET_ACCESS_KEY
        );

        $reportData = json_decode($reportResponse, true);
        if (!isset($reportData['reportDocumentId'])) {
            return ['success' => false, 'error' => 'ReportDocumentId non trovato'];
        }

        // Ora usa il reportDocumentId corretto
        $docResponse = signedRequest(
            'GET',
            'https://sellingpartnerapi-eu.amazon.com/reports/2021-06-30/documents/' . $reportData['reportDocumentId'],
            $accessToken,
            AWS_ACCESS_KEY_ID,
            AWS_SECRET_ACCESS_KEY
        );
        
        $docData = json_decode($docResponse, true);
        if (!isset($docData['url'])) {
            return ['success' => false, 'error' => 'URL documento non trovato'];
        }
        
        // Crea directory utente
        $userDir = __DIR__ . '/../downloads/user_' . $reportInfo['user_id'];
        $reportDir = $userDir . '/GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE';
        
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        
        // Nome file univoco con timestamp
        $fileName = 'settlement_' . $reportInfo['report_id'] . '_' . date('Ymd_His') . '.tsv';
        $filePath = $reportDir . '/' . $fileName;
        
        // Scarica file
        $fileContent = file_get_contents($docData['url']);
        if ($fileContent === false) {
            return ['success' => false, 'error' => 'Errore download file'];
        }
        
        // Decomprimi se necessario
        if (isset($docData['compressionAlgorithm']) && 
            strtoupper($docData['compressionAlgorithm']) === 'GZIP') {
            $fileContent = gzdecode($fileContent);
        }
        
        if (file_put_contents($filePath, $fileContent) === false) {
            return ['success' => false, 'error' => 'Errore salvataggio file'];
        }
        
        return [
            'success' => true,
            'file_path' => $filePath,
            'file_size' => filesize($filePath),
            'user_id' => $reportInfo['user_id']
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Ottieni statistiche SKU non mappati
 * Questa funzione è ora deprecata e dovrebbe essere rimossa o la sua logica
 * integrata nel nuovo sistema di mapping se le statistiche sono ancora necessarie.
 */
function getUnmappedSkuStats($userId) {
    // Implementazione deprecata
    return [
        'total_skus' => 0,
        'mapped_skus' => 0,
        'unmapped_count' => 0,
        'mapping_percentage' => 0
    ];
}

?>