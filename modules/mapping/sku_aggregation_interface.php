<?php
/**
 * Interfaccia di Aggregazione SKU
 * File: /modules/mapping/sku_aggregation_interface.php
 *
 * Questa interfaccia permette di aggregare più SKU sotto un unico nome prodotto.
 * Ogni nome prodotto può avere solo un product_id.
 */


// Includi le dipendenze necessarie
require_once __DIR__ . '/config/mapping_config.php';
require_once __DIR__ . '/MappingRepository.php';
require_once __DIR__ . '/MappingService.php';

// Inizializza il servizio di mapping
$dbConnection = getMappingDbConnection();
$mappingConfig = getMappingConfig();
$mappingRepository = new MappingRepository($dbConnection, $mappingConfig);
$mappingService = new MappingService($mappingRepository, $mappingConfig);

// Ottieni lista utenti
$pdo = getMappingDbConnection();
$stmt = $pdo->query("SELECT id, nome, email FROM users WHERE is_active = 1 ORDER BY id");
$availableUsers = $stmt->fetchAll();

// Usa utente selezionato o default
$userId = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 2));

// Gestione delle richieste AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   header('Content-Type: application/json');
   
   $action = $_POST['action'] ?? '';
   
   switch ($action) {
       case 'get_unmapped_skus':
    $sourceTable = $_POST['source_table'] ?? 'inventory';
    $limit = (int)($_POST['limit'] ?? 50);
    $skus = $mappingService->getUnmappedSkusFromSource($sourceTable, $userId, $limit);
    echo json_encode(['success' => true, 'skus' => $skus]);
    exit;

case 'get_all_unmapped_skus':
    $limit = (int)($_POST['limit'] ?? 200);
    $skus = $mappingService->getAllUnmappedSkusCombined($userId, $limit);
    echo json_encode(['success' => true, 'skus' => $skus]);
    exit;
           
       case 'search_products':
    $searchTerm = $_POST['search_term'] ?? '';
    $products = $mappingService->findProducts($userId, $searchTerm, 'all', 20);
    echo json_encode(['success' => true, 'products' => $products]);
    exit;
           
       case 'create_product':
           $productName = $_POST['product_name'] ?? '';
           if (empty($productName)) {
               echo json_encode(['success' => false, 'error' => 'Nome prodotto richiesto']);
               exit;
           }
           $productId = $mappingService->createProduct($userId, $productName);
           if ($productId) {
               echo json_encode(['success' => true, 'product_id' => $productId]);
           } else {
               echo json_encode(['success' => false, 'error' => 'Errore nella creazione del prodotto']);
           }
           exit;
           
       case 'aggregate_skus':
           $productId = (int)($_POST['product_id'] ?? 0);
           $skusToAggregate = json_decode($_POST['skus_to_aggregate'] ?? '[]', true);
           
           if ($productId <= 0 || empty($skusToAggregate)) {
               echo json_encode(['success' => false, 'error' => 'Dati non validi']);
               exit;
           }
           
           try {
               $result = $mappingService->aggregateSkusToProduct($userId, $productId, $skusToAggregate);
               
               // Log solo errori critici
               if (!$result['success'] && !empty($result['errors'] ?? [])) {
                   error_log("AGGREGATE ERROR: " . json_encode($result['errors']));
               }
               
               echo json_encode($result);
               
           } catch (Exception $e) {
               error_log("AGGREGATE EXCEPTION: " . $e->getMessage());
               echo json_encode(['success' => false, 'error' => $e->getMessage()]);
           }
           
           exit;
           
       case 'get_product_skus':
           $productId = (int)($_POST['product_id'] ?? 0);
           if ($productId <= 0) {
               echo json_encode(['success' => false, 'error' => 'Product ID non valido']);
               exit;
           }
           
           try {
               $skus = $mappingService->getSkusByProductId($productId);
               echo json_encode(['success' => true, 'skus' => $skus]);
           } catch (Exception $e) {
               error_log("GET_PRODUCT_SKUS ERROR: " . $e->getMessage());
               echo json_encode(['success' => false, 'error' => $e->getMessage()]);
           }
           exit;

       // === NUOVE AZIONI PENDING MAPPINGS ===
       case 'get_pending_mappings':
           try {
               $mappings = $mappingService->getPendingMappingsForApproval($userId);
               $stats = $mappingService->getPendingMappingStatistics($userId);
               echo json_encode([
                   'success' => true, 
                   'mappings' => $mappings,
                   'stats' => $stats
               ]);
           } catch (Exception $e) {
               echo json_encode(['success' => false, 'error' => $e->getMessage()]);
           }
           exit;

       case 'approve_pending_mapping':
           $mappingId = (int)($_POST['mapping_id'] ?? 0);
           if ($mappingId <= 0) {
               echo json_encode(['success' => false, 'error' => 'Mapping ID non valido']);
               exit;
           }
           
           try {
               $result = $mappingService->approvePendingMapping($mappingId, $userId);
               echo json_encode($result);
           } catch (Exception $e) {
               echo json_encode(['success' => false, 'error' => $e->getMessage()]);
           }
           exit;

       case 'reject_pending_mapping':
           $mappingId = (int)($_POST['mapping_id'] ?? 0);
           if ($mappingId <= 0) {
               echo json_encode(['success' => false, 'error' => 'Mapping ID non valido']);
               exit;
           }
           
           try {
               $result = $mappingService->rejectPendingMapping($mappingId, $userId);
               echo json_encode($result);
           } catch (Exception $e) {
               echo json_encode(['success' => false, 'error' => $e->getMessage()]);
           }
           exit;

       case 'bulk_process_pending':
           $mappingIds = json_decode($_POST['mapping_ids'] ?? '[]', true);
           $action = $_POST['bulk_action'] ?? '';
           
           if (empty($mappingIds) || !in_array($action, ['approve', 'reject'])) {
               echo json_encode(['success' => false, 'error' => 'Parametri non validi']);
               exit;
           }
           
           try {
               $result = $mappingService->bulkProcessPendingMappings($mappingIds, $action, $userId);
               echo json_encode($result);
           } catch (Exception $e) {
               echo json_encode(['success' => false, 'error' => $e->getMessage()]);
           }
           exit;

       case 'get_partial_mappings':
           $limit = (int)($_POST['limit'] ?? 1000);
           $partialMappings = getPartialMappings($userId, $limit);
           echo json_encode(['success' => true, 'mappings' => $partialMappings]);
           exit;

       case 'sync_sku_across_tables':
           $sku = $_POST['sku'] ?? '';
           $sourceTables = json_decode($_POST['source_tables'] ?? '[]', true);
           $targetProductId = (int)($_POST['product_id'] ?? 0);
           
           if (empty($sku) || $targetProductId <= 0) {
               echo json_encode(['success' => false, 'error' => 'Dati non validi']);
               exit;
           }
           
           $result = syncSkuAcrossTables($userId, $sku, $targetProductId, $sourceTables);
           echo json_encode($result);
           exit;

       case 'sync_all_partial':
           $batchLimit = (int)($_POST['batch_limit'] ?? 1000);
           $result = syncAllPartialMappings($userId, $batchLimit);
           echo json_encode($result);
           exit;
           
       default:
           $action = $_POST['action'] ?? 'MISSING';
           error_log("UNKNOWN ACTION ERROR: '$action' not recognized");
           echo json_encode(['success' => false, 'error' => 'Azione non riconosciuta']);
           exit;
   } // <-- Chiude il switch
} // <-- Chiude il blocco POST

// ============================================
// FUNZIONI HELPER PER SINCRONIZZAZIONE SKU
// ============================================

/**
 * Trova SKU con mapping parziali - LOGICA CORRETTA
 * Trova SKU che sono mappati in almeno una tabella ma esistono (non mappati) in altre tabelle
 */
function getPartialMappings($userId, $limit = 1000) {
    $pdo = getMappingDbConnection();
    $settlementTable = "report_settlement_{$userId}";
    
    try {
        // STEP 1: Trova tutti gli SKU UNICI da tutte le tabelle (mappati o no)
        $allSkusSql = "
            SELECT DISTINCT sku 
            FROM (
                SELECT sku FROM inventory WHERE user_id = ? AND sku IS NOT NULL AND sku != ''
                UNION
                SELECT seller_sku as sku FROM inventory_fbm WHERE user_id = ? AND seller_sku IS NOT NULL AND seller_sku != ''
                UNION
                SELECT seller_sku as sku FROM inbound_shipment_items WHERE user_id = ? AND seller_sku IS NOT NULL AND seller_sku != ''
                UNION
                SELECT sku FROM removal_orders WHERE user_id = ? AND sku IS NOT NULL AND sku != ''
                UNION
                SELECT sku FROM `{$settlementTable}` WHERE sku IS NOT NULL AND sku != '' AND sku NOT LIKE '%Fee%'
                UNION
                SELECT msku as sku FROM shipments_trid WHERE user_id = ? AND msku IS NOT NULL AND msku != ''
            ) AS all_skus
        ";
        
        $stmt = $pdo->prepare($allSkusSql);
        $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
        $allSkus = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $results = [];
        $count = 0;
        
        // STEP 2: Per ogni SKU, analizza il mapping status
        foreach ($allSkus as $sku) {
            if ($count >= $limit) break;
            
            $skuAnalysis = analyzeSkuMapping($userId, $sku);
            
            // Includi solo se:
            // 1. Mappato in almeno una tabella (ha product_id)
            // 2. Esiste in almeno una tabella dove NON è mappato
            if ($skuAnalysis['mapped_count'] > 0 && $skuAnalysis['unmapped_exists_count'] > 0) {
                $results[] = $skuAnalysis;
                $count++;
            }
        }
        
        // Ordina per priorità (più record non mappati = più importante)
        usort($results, function($a, $b) {
            return $b['total_unmapped_records'] - $a['total_unmapped_records'];
        });
        
        return $results;
        
    } catch (PDOException $e) {
        error_log("getPartialMappings ERROR: " . $e->getMessage());
        return [];
    }
}

/**
 * Analizza lo stato di mapping di un singolo SKU in tutte le tabelle
 */
function analyzeSkuMapping($userId, $sku) {
    $pdo = getMappingDbConnection();
    $settlementTable = "report_settlement_{$userId}";
    
    $analysis = [
        'sku' => $sku,
        'product_id' => null,
        'product_name' => null,
        'mapped_tables' => [],
        'unmapped_tables_with_records' => [],
        'mapped_count' => 0,
        'unmapped_exists_count' => 0,
        'total_unmapped_records' => 0
    ];
    
    // Definisci le tabelle da controllare
    $tablesToCheck = [
        'inventory' => [
            'sku_column' => 'sku',
            'check_sql' => "SELECT product_id, COUNT(*) as count FROM inventory WHERE user_id = ? AND sku = ? GROUP BY product_id"
        ],
        'inventory_fbm' => [
            'sku_column' => 'seller_sku',
            'check_sql' => "SELECT product_id, COUNT(*) as count FROM inventory_fbm WHERE user_id = ? AND seller_sku = ? GROUP BY product_id"
        ],
        'inbound_shipment_items' => [
            'sku_column' => 'seller_sku',
            'check_sql' => "SELECT product_id, COUNT(*) as count FROM inbound_shipment_items WHERE user_id = ? AND seller_sku = ? GROUP BY product_id"
        ],
        'removal_orders' => [
            'sku_column' => 'sku',
            'check_sql' => "SELECT product_id, COUNT(*) as count FROM removal_orders WHERE user_id = ? AND sku = ? GROUP BY product_id"
        ],
        'settlement' => [
            'sku_column' => 'sku',
            'check_sql' => "SELECT product_id, COUNT(*) as count FROM `{$settlementTable}` WHERE sku = ? GROUP BY product_id",
            'params_count' => 1 // Solo sku, no user_id
        ],
        'shipments_trid' => [
            'sku_column' => 'msku',
            'check_sql' => "SELECT product_id, COUNT(*) as count FROM shipments_trid WHERE user_id = ? AND msku = ? GROUP BY product_id"
        ]
    ];
    
    foreach ($tablesToCheck as $tableName => $config) {
        try {
            $stmt = $pdo->prepare($config['check_sql']);
            
            if (isset($config['params_count']) && $config['params_count'] === 1) {
                $stmt->execute([$sku]);
            } else {
                $stmt->execute([$userId, $sku]);
            }
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($results)) {
                // SKU non esiste in questa tabella
                continue;
            }
            
            // Analizza i risultati
            $hasMapped = false;
            $unmappedCount = 0;
            
            foreach ($results as $row) {
                $productId = $row['product_id'];
                $recordCount = $row['count'];
                
                if ($productId !== null && $productId > 0) {
                    // Mappato
                    $hasMapped = true;
                    
                    // Salva product_id (usa il primo trovato)
                    if ($analysis['product_id'] === null) {
                        $analysis['product_id'] = $productId;
                    }
                } else {
                    // Non mappato
                    $unmappedCount += $recordCount;
                }
            }
            
            if ($hasMapped) {
                $analysis['mapped_tables'][] = $tableName;
                $analysis['mapped_count']++;
            }
            
            if ($unmappedCount > 0) {
                $analysis['unmapped_tables_with_records'][] = "{$tableName}({$unmappedCount})";
                $analysis['unmapped_exists_count']++;
                $analysis['total_unmapped_records'] += $unmappedCount;
            }
            
        } catch (PDOException $e) {
            // Ignora errori tabelle (potrebbero non esistere)
            continue;
        }
    }
    
    // Ottieni nome prodotto se disponibile
    if ($analysis['product_id']) {
        try {
            $stmt = $pdo->prepare("SELECT nome FROM products WHERE id = ?");
            $stmt->execute([$analysis['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($product) {
                $analysis['product_name'] = $product['nome'];
            }
        } catch (PDOException $e) {
            // Ignore
        }
    }
    
    // Formatta per output
    $analysis['mapped_tables_str'] = implode(', ', $analysis['mapped_tables']);
    $analysis['unmapped_tables_str'] = implode(', ', $analysis['unmapped_tables_with_records']);
    
    return $analysis;
}

/**
 * Verifica se uno SKU esiste fisicamente nelle tabelle (anche se non mappato)
 */
function checkSkuExistsInTables($userId, $sku, $tables) {
    $pdo = getMappingDbConnection();
    $exists = [];
    
    foreach ($tables as $table) {
        try {
            switch ($table) {
                case 'inventory':
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE user_id = ? AND sku = ?");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'inventory_fbm':
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_fbm WHERE user_id = ? AND seller_sku = ?");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'inbound_shipment_items':
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inbound_shipment_items WHERE user_id = ? AND seller_sku = ?");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'removal_orders':
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM removal_orders WHERE user_id = ? AND sku = ?");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'settlement':
                    $settlementTable = "report_settlement_{$userId}";
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$settlementTable}` WHERE sku = ?");
                    $stmt->execute([$sku]);
                    break;
                case 'shipments_trid':
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shipments_trid WHERE user_id = ? AND msku = ?");
                    $stmt->execute([$userId, $sku]);
                    break;
                default:
                    continue 2;
            }
            
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $exists[] = $table;
            }
            
        } catch (PDOException $e) {
            // Tabella potrebbe non esistere, skip
            continue;
        }
    }
    
    return implode(',', $exists);
}

/**
 * Verifica esistenza SKU con conteggio record SINCRONIZZABILI (non mappati)
 */
function checkSkuExistsInTablesWithCount($userId, $sku, $tables) {
    $pdo = getMappingDbConnection();
    $exists = [];
    $syncable = [];
    $totalCount = 0;
    
    foreach ($tables as $table) {
        try {
            switch ($table) {
                case 'inventory':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM inventory 
                        WHERE user_id = ? 
                          AND sku = ? 
                          AND (product_id IS NULL OR product_id = 0)
                    ");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'inventory_fbm':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM inventory_fbm 
                        WHERE user_id = ? 
                          AND seller_sku = ? 
                          AND (product_id IS NULL OR product_id = 0)
                    ");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'inbound_shipment_items':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM inbound_shipment_items 
                        WHERE user_id = ? 
                          AND seller_sku = ? 
                          AND (product_id IS NULL OR product_id = 0)
                    ");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'removal_orders':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM removal_orders 
                        WHERE user_id = ? 
                          AND sku = ? 
                          AND (product_id IS NULL OR product_id = 0)
                    ");
                    $stmt->execute([$userId, $sku]);
                    break;
                case 'settlement':
                    $settlementTable = "report_settlement_{$userId}";
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM `{$settlementTable}` 
                        WHERE sku = ? 
                          AND (product_id IS NULL OR product_id = 0)
                    ");
                    $stmt->execute([$sku]);
                    break;
                case 'shipments_trid':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM shipments_trid 
                        WHERE user_id = ? 
                          AND msku = ? 
                          AND (product_id IS NULL OR product_id = 0)
                    ");
                    $stmt->execute([$userId, $sku]);
                    break;
                default:
                    continue 2;
            }
            
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $exists[] = "{$table}({$count})";
                $syncable[] = $table;
                $totalCount += $count;
            }
            
        } catch (PDOException $e) {
            // Tabella potrebbe non esistere, skip
            continue;
        }
    }
    
    return [
        'tables' => implode(', ', $exists),
        'count' => $totalCount,
        'syncable' => $syncable
    ];
}

/**
 * Sincronizza mapping di uno SKU a tutte le tabelle dove esiste
 */
function syncSkuAcrossTables($userId, $sku, $productId, $sourceTables) {
    $pdo = getMappingDbConnection();
    $updated = 0;
    $errors = [];
    
    try {
        $pdo->beginTransaction();
        
        // Definisci le query di update per ogni tabella
        $updates = [
            'inventory' => [
                'sql' => "UPDATE inventory SET product_id = ? WHERE user_id = ? AND sku = ? AND (product_id IS NULL OR product_id != ?)",
                'params' => [$productId, $userId, $sku, $productId],
                'sku_column' => 'sku',
                'table' => 'inventory'
            ],
            'inventory_fbm' => [
                'sql' => "UPDATE inventory_fbm SET product_id = ? WHERE user_id = ? AND seller_sku = ? AND (product_id IS NULL OR product_id != ?)",
                'params' => [$productId, $userId, $sku, $productId],
                'sku_column' => 'seller_sku',
                'table' => 'inventory_fbm'
            ],
            'inbound_shipment_items' => [
                'sql' => "UPDATE inbound_shipment_items SET product_id = ? WHERE user_id = ? AND seller_sku = ? AND (product_id IS NULL OR product_id != ?)",
                'params' => [$productId, $userId, $sku, $productId],
                'sku_column' => 'seller_sku',
                'table' => 'inbound_shipment_items'
            ],
            'removal_orders' => [
                'sql' => "UPDATE removal_orders SET product_id = ? WHERE user_id = ? AND sku = ? AND (product_id IS NULL OR product_id != ?)",
                'params' => [$productId, $userId, $sku, $productId],
                'sku_column' => 'sku',
                'table' => 'removal_orders'
            ],
            'settlement' => [
                'sql' => "UPDATE `report_settlement_{$userId}` SET product_id = ? WHERE sku = ? AND (product_id IS NULL OR product_id != ?)",
                'params' => [$productId, $sku, $productId],
                'sku_column' => 'sku',
                'table' => "report_settlement_{$userId}"
            ],
            'shipments_trid' => [
                'sql' => "UPDATE shipments_trid SET product_id = ? WHERE user_id = ? AND msku = ? AND (product_id IS NULL OR product_id != ?)",
                'params' => [$productId, $userId, $sku, $productId],
                'sku_column' => 'msku',
                'table' => 'shipments_trid'
            ]
        ];
        
        // STEP 1: Usa analyzeSkuMapping per ottenere info precise
        $analysis = analyzeSkuMapping($userId, $sku);
        
        // STEP 2: Estrai le tabelle target da unmapped_tables_with_records
        $targetTables = [];
        foreach ($analysis['unmapped_tables_with_records'] as $tableInfo) {
            // Extract table name from "tablename(count)"
            if (preg_match('/^([a-z_]+)\((\d+)\)$/', $tableInfo, $matches)) {
                $targetTables[] = $matches[1];
            }
        }
        
        // STEP 3: Esegui UPDATE solo per target tables
        foreach ($targetTables as $table) {
            if (!isset($updates[$table])) {
                continue;
            }
            $config = $updates[$table];
            
            try {
                $stmt = $pdo->prepare($config['sql']);
                $stmt->execute($config['params']);
                $rowsAffected = $stmt->rowCount();
                
                if ($rowsAffected > 0) {
                    $updated += $rowsAffected;
                }
                
            } catch (PDOException $e) {
                $errorMsg = "syncSkuAcrossTables ERROR updating {$table}: " . $e->getMessage();
                error_log($errorMsg);
                $errors[] = $errorMsg;
            }
        }
        
        $pdo->commit();
        
        return [
            'success' => empty($errors),
            'updated' => $updated,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("syncSkuAcrossTables ERROR: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Sincronizza tutti gli SKU parziali in batch
 */
function syncAllPartialMappings($userId, $limit = 1000) {
    $partialMappings = getPartialMappings($userId, $limit);
    $synced = 0;
    $skipped = 0;
    $totalRecords = 0;
    $errors = [];
    
    foreach ($partialMappings as $mapping) {
        // Skip SKU senza product_id (non ancora mappati)
        if (empty($mapping['product_id'])) {
            $skipped++;
            $errors[] = "SKU {$mapping['sku']}: Non mappato (richiede mapping manuale)";
            continue;
        }
        
        // Usa i mapped_tables array (già formattato)
        $sourceTables = $mapping['mapped_tables']; // Già array
        
        $result = syncSkuAcrossTables($userId, $mapping['sku'], $mapping['product_id'], $sourceTables);
        
        if ($result['success']) {
            $synced++;
            $totalRecords += $result['updated'];
        } else {
            $errors[] = "SKU {$mapping['sku']}: " . ($result['error'] ?? 'Unknown error');
        }
    }
    
    return [
        'success' => true,
        'synced' => $synced,
        'skipped' => $skipped,
        'total' => count($partialMappings),
        'total_records_updated' => $totalRecords,
        'errors' => $errors
    ];
}

// Ottieni i dati iniziali per la pagina
$allProducts = $mappingService->getAllProducts($userId);
$allUnmappedSkus = $mappingService->getAllUnmappedSkusCombined($userId, 200);
$unmappedInventorySkus = $mappingService->getUnmappedSkusFromSource('inventory', $userId, 1000);
$unmappedFbmSkus = $mappingService->getUnmappedSkusFromSource('inventory_fbm', $userId, 1000);
$unmappedSettlementSkus = $mappingService->getUnmappedSkusFromSource('settlement', $userId, 1000);
$unmappedInboundSkus = $mappingService->getUnmappedSkusFromSource('inbound_shipments', $userId, 1000);
$unmappedRemovalSkus = $mappingService->getUnmappedSkusFromSource('removal_orders', $userId, 1000);

// Log info utenti solo se debug attivo
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    conditionalLog("User $userId: " . count($allProducts) . " products, " . 
                   count($unmappedInventorySkus) . " unmapped inventory SKUs", 
                   LOG_LEVEL_DEBUG, 'MAPPING');
}

// Attiva errori e includi admin helpers
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../margynomic/admin/admin_helpers.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aggregazione SKU - Margynomic Admin</title>
    <link rel="stylesheet" href="../margynomic/css/margynomic.css">
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            color: #333;
        }
        
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .dashboard-title {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
        }
        
        .dashboard-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1a1a1a;
            margin: 8px 0 4px 0;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }
        
        .section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f3f4;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .aggregation-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        
        .sku-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: #fafafa;
        }
        
        .sku-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.2s ease;
            cursor: pointer;
        }
        
        .sku-item:hover {
            background-color: #f8f9fa;
        }
        
        .sku-item.selected {
            background-color: #e3f2fd;
            border-left: 4px solid #667eea;
        }
        
        .sku-info {
            flex: 1;
        }
        
        .sku-code {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 14px;
        }
        
        .sku-details {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        
        .source-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #f1f3f4;
            color: #495057;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .source-badge.inventory {
            background: #d4edda;
            color: #155724;
        }
        
        .source-badge.inventory_fbm {
            background: #fff3cd;
            color: #856404;
        }
        
        .source-badge.settlement {
            background: #cce5ff;
            color: #004085;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-primary { 
            background: #667eea; 
            color: white; 
        }
        
        .btn-primary:hover { 
            background: #5a6fd8; 
        }
        
        .btn-secondary { 
            background: #6c757d; 
            color: white; 
        }
        
        .btn-secondary:hover { 
            background: #545b62; 
        }
        
        .btn-success { 
            background: #28a745; 
            color: white; 
        }
        
        .btn-success:hover { 
            background: #218838; 
        }
        
        .btn-danger { 
            background: #dc3545; 
            color: white; 
        }
        
        .btn-danger:hover { 
            background: #c82333; 
        }

        .btn-sm { 
            padding: 6px 12px; 
            font-size: 12px; 
        }
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #F3F4F6;
            color: #4B5563;
            border: 1px solid #D1D5DB;
        }
        
        .btn-secondary:hover {
            background: #E5E7EB;
        }
        
        .btn-success {
            background: #10B981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: border-color 0.2s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #008CFF;
            box-shadow: 0 0 0 3px rgba(0, 140, 255, 0.1);
        }
        
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            font-size: 0.875rem;
            background: white;
            cursor: pointer;
        }
        
        .selected-skus-container {
            margin-top: 1rem;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
            min-height: 100px;
        }
        
        .selected-skus-container.has-items {
            border-color: #667eea;
            background: #e3f2fd;
        }
        
        .selected-sku-tag {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            margin: 4px;
            background: white;
            border: 1px solid #667eea;
            border-radius: 20px;
            font-size: 12px;
            color: #667eea;
        }
        
        .selected-sku-tag .remove-btn {
            margin-left: 8px;
            cursor: pointer;
            color: #dc3545;
            font-weight: bold;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Stili Tab Navigation */
.tab-navigation {
    display: flex;
    border-bottom: 2px solid #E5E7EB;
    background: white;
    border-radius: 8px 8px 0 0;
    padding: 0 1rem;
}

.tab-btn {
    padding: 1rem 1.5rem;
    border: none;
    background: transparent;
    color: #6B7280;
    font-weight: 500;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.tab-btn:hover {
    color: #008CFF;
    background: #F8FAFC;
}

.tab-btn.active {
    color: #008CFF;
    border-bottom-color: #008CFF;
    background: #F8FAFC;
}

.tab-badge {
    background: #EF4444;
    color: white;
    border-radius: 50%;
    padding: 0.2rem 0.6rem;
    font-size: 0.75rem;
    font-weight: 600;
    min-width: 1.5rem;
    text-align: center;
}

.tab-content {
    transition: opacity 0.3s ease;
}

/* Stili Statistiche Mini */
.stat-mini {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    text-align: center;
    border: 1px solid #E5E7EB;
}

.stat-mini .stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: #008CFF;
}

.stat-mini .stat-label {
    font-size: 0.75rem;
    color: #6B7280;
    margin-top: 0.25rem;
}

/* Stili Pending Item */
.pending-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    background: white;
    transition: all 0.2s ease;
}

.pending-item:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.pending-item.selected {
    border-color: #008CFF;
    background: #EBF8FF;
}

.pending-info {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr 100px;
    gap: 1rem;
    align-items: center;
}

.pending-sku {
    font-weight: 600;
    color: #1C1C1C;
    font-family: monospace;
}

.pending-product {
    color: #4B5563;
}

.confidence-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    text-align: center;
}

.confidence-high {
    background: #D1FAE5;
    color: #065F46;
}

.confidence-medium {
    background: #FEF3C7;
    color: #92400E;
}

.confidence-low {
    background: #FEE2E2;
    color: #991B1B;
}

.pending-actions {
    display: flex;
    gap: 0.5rem;
}

@media (max-width: 768px) {
    .aggregation-grid {
        grid-template-columns: 1fr;
    }
    
    .aggregation-container {
        padding: 1rem;
    }
    
    .tab-navigation {
        flex-direction: column;
    }
    
    .pending-info {
        grid-template-columns: 1fr;
        text-align: center;
    }
}

/* ============================================
   SYNC TAB STYLES
   ============================================ */
.help-text-box {
    background: #F0F9FF;
    border-left: 4px solid #3B82F6;
    padding: 1rem;
    border-radius: 6px;
    color: #1E40AF;
    line-height: 1.6;
}

.badge-sync {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    min-width: 2rem;
    text-align: center;
}

.badge-success-sync {
    background: #10B981;
    color: white;
}

.badge-warning-sync {
    background: #F59E0B;
    color: white;
}

/* Wrapper tabella con scroll orizzontale - FIX OVERFLOW */
#partial-mappings-table-wrapper {
    width: 100%;
    max-width: 100%;
    overflow-x: auto !important;
    overflow-y: visible;
    margin: 20px 0;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    -webkit-overflow-scrolling: touch; /* Smooth scrolling su mobile */
}

#partial-mappings-table {
    min-width: 900px; /* Ridotto da 1200px */
    width: 100%;
    table-layout: auto;
}

#partial-mappings-table tbody tr:hover {
    background: #F9FAFB;
}

#partial-mappings-table td {
    white-space: normal;
    word-wrap: break-word;
    max-width: 300px; /* Limita larghezza massima celle */
}

#partial-mappings-table td small {
    font-size: 0.7rem;
    line-height: 1.3;
    display: block;
    word-break: break-word;
}

/* Fix per container parent che potrebbero bloccare scroll */
#sync-tab {
    overflow: visible !important;
}

#sync-tab .section {
    overflow: visible !important;
}
</style>
</head>
<body>
    <?php echo getAdminNavigation('aggregation'); ?>
    
    <div class="main-container">
        <!-- Header Dashboard -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">
                <i class="fas fa-project-diagram"></i>
                Aggregazione SKU
            </h1>
            <p class="dashboard-subtitle">
                Sistema di aggregazione e mapping SKU per prodotti Margynomic
            </p>
        </div>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-user"></i>
                    Seleziona Utente
                </h2>
            </div>
            <form method="GET" style="display: flex; gap: 1rem; align-items: center;">
                <select name="user_id" class="form-select" style="width: 300px;">
                    <?php foreach ($availableUsers as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $userId == $user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?> (ID: <?= $user['id'] ?>) - <?= htmlspecialchars($user['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Cambia Utente</button>
            </form>
        </div>

        <!-- Statistiche Principali -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #28a745;">
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
                <div class="stat-number"><?= count($allProducts) ?></div>
                <div class="stat-label">Prodotti Totali</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #17a2b8;">
                        <i class="fas fa-warehouse"></i>
                    </div>
                </div>
                <div class="stat-number"><?= count($unmappedInventorySkus) ?></div>
                <div class="stat-label">SKU Inventory Non Mappati</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #ffc107;">
                        <i class="fas fa-truck"></i>
                    </div>
                </div>
                <div class="stat-number"><?= count($unmappedFbmSkus) ?></div>
                <div class="stat-label">SKU FBM Non Mappati</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #6f42c1;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="stat-number"><?= count($unmappedSettlementSkus) ?></div>
                <div class="stat-label">SKU Settlement Non Mappati</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #dc3545;">
                        <i class="fas fa-shipping-fast"></i>
                    </div>
                </div>
                <div class="stat-number"><?= count($unmappedInboundSkus) ?></div>
                <div class="stat-label">SKU Inbound Non Mappati</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #e74c3c;">
                        📦
                    </div>
                </div>
                <div class="stat-number"><?= count($unmappedRemovalSkus) ?></div>
                <div class="stat-label">SKU Removal Orders Non Mappati</div>
            </div>
        </div>

<!-- Alert per messaggi -->
        <div id="alert-container"></div>

        <!-- Tab Navigation -->
        <div class="tab-navigation" style="margin-bottom: 2rem;">
            <button class="tab-btn active" data-tab="unmapped">SKU Non Mappati</button>
            <button class="tab-btn" data-tab="pending">
                Approvazioni Pending 
                <span id="pending-count" class="tab-badge">0</span>
            </button>
            <button class="tab-btn" data-tab="sync">🔄 Sincronizza SKU</button>
        </div>

        <div class="aggregation-grid">
            <!-- Pannello SKU Non Mappati -->
            <div class="section tab-content" id="unmapped-tab">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-list"></i>
                        Tutti gli SKU Non Mappati
                    </h2>
                    <div>
                        <button id="refresh-skus" class="btn btn-secondary btn-sm">
                            Aggiorna
                        </button>
                    </div>
                </div>
                
                <div class="sku-list" id="unmapped-skus-list">
                    <!-- Gli SKU verranno caricati dinamicamente -->
                </div>
                
                <div class="selected-skus-container" id="selected-skus-container">
                    <div id="selected-skus-placeholder" style="text-align: center; color: #6B7280;">
                        Seleziona gli SKU da aggregare cliccandoci sopra
                    </div>
                    <div id="selected-skus-list"></div>
                </div>
            </div>

            <!-- NUOVA TAB: Approvazioni Pending -->
            <div class="section tab-content" id="pending-tab" style="display: none;">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-clock"></i>
                        Approvazioni Pending
                    </h2>
                    <div>
                        <button id="refresh-pending" class="btn btn-secondary btn-sm">
                            Aggiorna
                        </button>
                        <button id="approve-all-btn" class="btn btn-success btn-sm" disabled>
                            ✅ Approva Selezionati
                        </button>
                        <button id="reject-all-btn" class="btn btn-danger btn-sm" disabled>
                            ❌ Rifiuta Selezionati
                        </button>
                    </div>
                </div>

                <!-- Statistiche Pending -->
                <div class="pending-stats" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem;">
                    <div class="stat-mini">
                        <div class="stat-number" id="total-pending">0</div>
                        <div class="stat-label">Totale</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-number" id="high-confidence">0</div>
                        <div class="stat-label">Alta Confidenza</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-number" id="medium-confidence">0</div>
                        <div class="stat-label">Media Confidenza</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-number" id="low-confidence">0</div>
                        <div class="stat-label">Bassa Confidenza</div>
                    </div>
                </div>
                
                <div class="sku-list" id="pending-mappings-list">
                    <!-- Mapping pending verranno caricati dinamicamente -->
                </div>
            </div>

            <!-- Tab Sincronizza SKU -->
            <div class="section tab-content" id="sync-tab" style="display: none; overflow: visible !important;">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-sync-alt"></i>
                        🔄 Sincronizza Mapping SKU Cross-Tabella
                    </h2>
                    <div>
                        <button id="load-partial-btn" class="btn btn-primary btn-sm">
                            🔍 Carica SKU Parziali
                        </button>
                        <button id="sync-all-btn" class="btn btn-success btn-sm" style="display: none;">
                            ⚡ Sincronizza Tutto (Batch)
                        </button>
                    </div>
                </div>
                
                <div class="help-text-box" style="margin-bottom: 1.5rem;">
                    <strong>ℹ️ Cosa fa questa funzione:</strong><br>
                    Questa funzione rileva SKU mappati solo parzialmente (es. mappato in settlement ma non in inventory) 
                    e permette di propagare il mapping a tutte le tabelle dove lo SKU esiste fisicamente.
                </div>
                
                <div id="sync-count-info" style="display: none; margin-bottom: 1rem; padding: 1rem; background: #EBF8FF; border-left: 4px solid #3B82F6; border-radius: 6px;">
                    <strong id="sync-count"></strong>
                </div>
                
                <div id="sync-loading" style="display: none; text-align: center; padding: 2rem; color: #6B7280;">
                    <div style="font-size: 2rem;">⏳</div>
                    <div style="margin-top: 0.5rem;">Caricamento in corso...</div>
                </div>
                
                <div id="sync-results" style="margin-bottom: 1rem;">
                    <!-- Populated via JS -->
                </div>
                
                <!-- Wrapper con scroll orizzontale -->
                <div id="partial-mappings-table-wrapper" style="display: none;">
                    <table id="partial-mappings-table" style="width: 100%; border-collapse: collapse; background: white;">
                        <thead style="background: #F3F4F6;">
                            <tr>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #374151; min-width: 250px;">SKU</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #374151; min-width: 200px;">Prodotto</th>
                                <th style="padding: 1rem; text-align: center; font-weight: 600; color: #374151; min-width: 150px;">✅ Mappato In</th>
                                <th style="padding: 1rem; text-align: center; font-weight: 600; color: #374151; min-width: 250px;">📊 Record da Sincronizzare</th>
                                <th style="padding: 1rem; text-align: center; font-weight: 600; color: #374151; min-width: 180px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="partial-mappings-body">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pannello Prodotti e Aggregazione -->
                    <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-box"></i>
                    Prodotti Esistenti
                </h2>
                    <button id="create-new-product-btn" class="btn btn-primary btn-sm">
                        + Nuovo Prodotto
                    </button>
                </div>
                
                <!-- Form per nuovo prodotto -->
                <div id="new-product-form" style="display: none; margin-bottom: 1rem; padding: 1rem; background: #F9FAFB; border-radius: 8px;">
                    <div class="form-group">
                        <label class="form-label">Nome Nuovo Prodotto</label>
                        <input type="text" id="new-product-name" class="form-input" placeholder="Inserisci il nome del prodotto">
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button id="save-new-product" class="btn btn-success btn-sm">Crea Prodotto</button>
                        <button id="cancel-new-product" class="btn btn-secondary btn-sm">Annulla</button>
                    </div>
                </div>
                
                <!-- Ricerca prodotti -->
                <div class="form-group">
                    <label class="form-label">Cerca Prodotto</label>
                    <input type="text" id="product-search" class="form-input" placeholder="Cerca per nome prodotto...">
                </div>
                
                <!-- Lista prodotti -->
                <div class="sku-list" id="products-list">
                    <?php foreach ($allProducts as $product): ?>
                        <div class="sku-item product-item" data-product-id="<?= $product['id'] ?>">
                            <div class="sku-info">
                                <div class="sku-code"><?= htmlspecialchars($product['nome']) ?></div>
                                <div class="sku-details">
                                    ID: <?= $product['id'] ?>
                                    <?php if ($product['sku']): ?>
                                        | SKU: <?= htmlspecialchars($product['sku']) ?>
                                    <?php endif; ?>
                                    <?php if ($product['asin']): ?>
                                        | ASIN: <?= htmlspecialchars($product['asin']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm select-product-btn" data-product-id="<?= $product['id'] ?>">
                                Seleziona
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Azioni di aggregazione -->
                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #E5E7EB;">
                    <div id="selected-product-info" style="display: none; margin-bottom: 1rem; padding: 1rem; background: #EBF8FF; border-radius: 8px;">
                        <strong>Prodotto Selezionato:</strong> <span id="selected-product-name"></span>
                        <div style="margin-top: 0.5rem;">
                            <button id="view-product-skus" class="btn btn-secondary btn-sm">Visualizza SKU Esistenti</button>
                        </div>
                    </div>
                    
                    <button id="aggregate-btn" class="btn btn-success" style="width: 100%;" disabled>
                        <span id="aggregate-btn-text">Aggrega SKU al Prodotto</span>
                        <span id="aggregate-btn-loading" style="display: none;" class="loading"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Stato dell'applicazione
        let selectedSkus = [];
        let selectedProductId = null;
        let currentSource = 'inventory';
        
        // === VARIABILI PENDING MAPPINGS ===
        let currentTab = 'unmapped';
        let selectedPendingMappings = [];
        let pendingMappingsData = [];

        // Elementi DOM
        const sourceFilter = document.getElementById('source-filter');
        const refreshSkusBtn = document.getElementById('refresh-skus');
        const unmappedSkusList = document.getElementById('unmapped-skus-list');
        const selectedSkusContainer = document.getElementById('selected-skus-container');
        const selectedSkusPlaceholder = document.getElementById('selected-skus-placeholder');
        const selectedSkusList = document.getElementById('selected-skus-list');
        const productSearch = document.getElementById('product-search');
        const productsList = document.getElementById('products-list');
        const createNewProductBtn = document.getElementById('create-new-product-btn');
        const newProductForm = document.getElementById('new-product-form');
        const newProductName = document.getElementById('new-product-name');
        const saveNewProductBtn = document.getElementById('save-new-product');
        const cancelNewProductBtn = document.getElementById('cancel-new-product');
        const selectedProductInfo = document.getElementById('selected-product-info');
        const selectedProductName = document.getElementById('selected-product-name');
        const viewProductSkusBtn = document.getElementById('view-product-skus');
        const aggregateBtn = document.getElementById('aggregate-btn');
        const aggregateBtnText = document.getElementById('aggregate-btn-text');
        const aggregateBtnLoading = document.getElementById('aggregate-btn-loading');
        const alertContainer = document.getElementById('alert-container');
        
        // === ELEMENTI DOM PENDING MAPPINGS ===
        const tabButtons = document.querySelectorAll('.tab-btn');
        const unmappedTab = document.getElementById('unmapped-tab');
        const pendingTab = document.getElementById('pending-tab');
        const syncTab = document.getElementById('sync-tab');
        const pendingCountBadge = document.getElementById('pending-count');
        const refreshPendingBtn = document.getElementById('refresh-pending');
        const approveAllBtn = document.getElementById('approve-all-btn');
        const rejectAllBtn = document.getElementById('reject-all-btn');
        const pendingMappingsList = document.getElementById('pending-mappings-list');
        
        // Elementi statistiche
        const totalPendingSpan = document.getElementById('total-pending');
        const highConfidenceSpan = document.getElementById('high-confidence');
        const mediumConfidenceSpan = document.getElementById('medium-confidence');
        const lowConfidenceSpan = document.getElementById('low-confidence');

        // Funzioni di utilità
        function showAlert(message, type = 'success') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.textContent = message;
            alertContainer.innerHTML = '';
            alertContainer.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }

        function makeRequest(action, data = {}) {
            return fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: action,
                    ...data
                })
            }).then(response => response.json());
        }

// Carica tutti gli SKU combinati
function loadAllUnmappedSkus() {
    makeRequest('get_all_unmapped_skus', { limit: 200 })
        .then(response => {
            if (response.success) {
                renderAllUnmappedSkus(response.skus);
            } else {
                showAlert('Errore nel caricamento degli SKU', 'error');
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            showAlert('Errore di connessione', 'error');
        });
}

// Renderizza tutti gli SKU combinati
function renderAllUnmappedSkus(skus) {
    unmappedSkusList.innerHTML = '';
    
    if (skus.length === 0) {
        unmappedSkusList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #6B7280;">Nessun SKU non mappato trovato</div>';
        return;
    }

    skus.forEach(sku => {
        const skuDiv = document.createElement('div');
        skuDiv.className = 'sku-item';
        skuDiv.dataset.sku = sku.sku;
        skuDiv.dataset.source = sku.source;
        
        skuDiv.innerHTML = `
            <div class="sku-info">
                <div class="sku-code">${sku.sku}</div>
                <div class="sku-details">
                    <span class="source-badge ${sku.source}">${sku.source.toUpperCase()}</span>
                    ${sku.asin ? `| ASIN: ${sku.asin}` : ''}
                    ${sku.product_name ? `| ${sku.product_name}` : ''}
                </div>
            </div>
        `;
        
        skuDiv.addEventListener('click', () => toggleSkuSelection(sku.sku, sku.source, skuDiv));
        unmappedSkusList.appendChild(skuDiv);
    });
}
        // Carica SKU non mappati
        function loadUnmappedSkus(source = currentSource) {
            currentSource = source;
            makeRequest('get_unmapped_skus', { source_table: source, limit: 50 })
                .then(response => {
                    if (response.success) {
                        renderUnmappedSkus(response.skus, source);
                    } else {
                        showAlert('Errore nel caricamento degli SKU', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        }

        // Renderizza SKU non mappati
        function renderUnmappedSkus(skus, source) {
            unmappedSkusList.innerHTML = '';
            
            if (skus.length === 0) {
                unmappedSkusList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #6B7280;">Nessun SKU non mappato trovato</div>';
                return;
            }

            skus.forEach(sku => {
                const skuDiv = document.createElement('div');
                skuDiv.className = 'sku-item';
                skuDiv.dataset.sku = sku.sku;
                skuDiv.dataset.source = source;
                
                skuDiv.innerHTML = `
                    <div class="sku-info">
                        <div class="sku-code">${sku.sku}</div>
                        <div class="sku-details">
                            <span class="source-badge ${source}">${source.toUpperCase()}</span>
                            ${sku.asin ? `| ASIN: ${sku.asin}` : ''}
                            ${sku.product_name ? `| ${sku.product_name}` : ''}
                        </div>
                    </div>
                `;
                
                skuDiv.addEventListener('click', () => toggleSkuSelection(sku.sku, source, skuDiv));
                unmappedSkusList.appendChild(skuDiv);
            });
        }

        // Toggle selezione SKU
        function toggleSkuSelection(sku, source, element) {
            const skuKey = `${source}:${sku}`;
            const index = selectedSkus.findIndex(s => s.key === skuKey);
            
            if (index > -1) {
                // Rimuovi dalla selezione
                selectedSkus.splice(index, 1);
                element.classList.remove('selected');
            } else {
                // Aggiungi alla selezione
                selectedSkus.push({ key: skuKey, sku: sku, source: source });
                element.classList.add('selected');
            }
            
            updateSelectedSkusDisplay();
            updateAggregateButton();
        }

        // Aggiorna visualizzazione SKU selezionati
        function updateSelectedSkusDisplay() {
            if (selectedSkus.length === 0) {
                selectedSkusPlaceholder.style.display = 'block';
                selectedSkusList.innerHTML = '';
                selectedSkusContainer.classList.remove('has-items');
            } else {
                selectedSkusPlaceholder.style.display = 'none';
                selectedSkusContainer.classList.add('has-items');
                
                selectedSkusList.innerHTML = selectedSkus.map(item => `
                    <span class="selected-sku-tag">
                        ${item.sku} (${item.source})
                        <span class="remove-btn" onclick="removeSelectedSku('${item.key}')">&times;</span>
                    </span>
                `).join('');
            }
        }

        // Rimuovi SKU selezionato
        function removeSelectedSku(skuKey) {
            const index = selectedSkus.findIndex(s => s.key === skuKey);
            if (index > -1) {
                selectedSkus.splice(index, 1);
                updateSelectedSkusDisplay();
                updateAggregateButton();
                
                // Rimuovi evidenziazione dalla lista
                const [source, sku] = skuKey.split(':');
                const skuElement = document.querySelector(`[data-sku="${sku}"][data-source="${source}"]`);
                if (skuElement) {
                    skuElement.classList.remove('selected');
                }
            }
        }

        // Aggiorna stato pulsante aggregazione
        function updateAggregateButton() {
            const canAggregate = selectedSkus.length > 0 && selectedProductId !== null;
            aggregateBtn.disabled = !canAggregate;
            
            if (canAggregate) {
                aggregateBtnText.textContent = `Aggrega ${selectedSkus.length} SKU al Prodotto`;
            } else {
                aggregateBtnText.textContent = 'Aggrega SKU al Prodotto';
            }
        }

        // === RICERCA SEMPLICE ED EFFICACE ===
        
        function searchProducts(searchTerm) {
            const productItems = document.querySelectorAll('.product-item');
            let visibleCount = 0;
            
            // Se ricerca vuota, mostra tutto
            if (!searchTerm.trim()) {
                productItems.forEach(item => {
                    item.style.display = 'flex';
                    removeHighlights(item);
                });
                updateProductSearchResults(0, '');
                return;
            }
            
            // Prepara i termini di ricerca
            const searchWords = searchTerm.toLowerCase()
                                         .normalize('NFD')
                                         .replace(/[\u0300-\u036f]/g, '') // rimuove accenti
                                         .split(/\s+/)
                                         .filter(word => word.length >= 2);
            
            productItems.forEach(item => {
                // Testo da cercare: nome prodotto + dettagli
                const productName = item.querySelector('.sku-code').textContent;
                const productDetails = item.querySelector('.sku-details').textContent;
                
                const fullText = (productName + ' ' + productDetails)
                                .toLowerCase()
                                .normalize('NFD')
                                .replace(/[\u0300-\u036f]/g, '');
                
                // Verifica se TUTTI i termini sono presenti
                const allWordsFound = searchWords.every(word => {
                    // Per i numeri: match esatto con eventuali unità
                    if (/^\d+$/.test(word)) {
                        const numberPattern = new RegExp(`\\b${word}\\s*(g|gr|kg|ml|l|mg|cl)?\\b`, 'i');
                        return numberPattern.test(fullText);
                    }
                    // Per il testo: deve essere contenuto
                    return fullText.includes(word);
                });
                
                if (allWordsFound) {
                    item.style.display = 'flex';
                    visibleCount++;
                    highlightProductWords(item.querySelector('.sku-code'), searchWords);
                } else {
                    item.style.display = 'none';
                }
            });
            
            updateProductSearchResults(visibleCount, searchTerm);
        }
        
        function highlightProductWords(element, words) {
    // Prima pulisci eventuali highlight esistenti
    removeHighlights(element.parentElement);
    
    let html = element.innerHTML || element.textContent;
    
    words.forEach(word => {
        if (!/^\d+$/.test(word)) { // evidenzia solo testo, non numeri
            const regex = new RegExp(`(${escapeProductRegex(word)})`, 'gi');
            html = html.replace(regex, '<span class="search-highlight">$1</span>');
        }
    });
    
    element.innerHTML = html;
}
        
        function removeHighlights(item) {
    const highlights = item.querySelectorAll('.search-highlight');
    highlights.forEach(span => {
        const parent = span.parentNode;
        parent.replaceChild(document.createTextNode(span.textContent), span);
        parent.normalize(); // Unisce i nodi di testo adiacenti
    });
}
        
        function escapeProductRegex(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
        function updateProductSearchResults(count, searchTerm) {
            const existingCounter = document.getElementById('product-search-results-counter');
            if (existingCounter) existingCounter.remove();
            
            if (searchTerm) {
                const counter = document.createElement('div');
                counter.id = 'product-search-results-counter';
                counter.style.cssText = 'margin: 10px 0; color: #6B7280; font-size: 0.875rem;';
                counter.innerHTML = `🔍 <strong>${count}</strong> prodotto${count !== 1 ? 'i' : ''} trovato${count !== 1 ? 'i' : ''} per "<em>${searchTerm}</em>"`;
                
                productsList.parentNode.insertBefore(counter, productsList);
            }
        }

        // Seleziona prodotto
        function selectProduct(productId, productName) {
            selectedProductId = productId;
            selectedProductName.textContent = productName;
            selectedProductInfo.style.display = 'block';
            
            // Rimuovi selezione precedente
            document.querySelectorAll('.product-item').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Aggiungi selezione corrente
            const productElement = document.querySelector(`[data-product-id="${productId}"]`);
            if (productElement) {
                productElement.classList.add('selected');
            }
            
            updateAggregateButton();
        }

        // Crea nuovo prodotto
        function createNewProduct() {
            const productName = newProductName.value.trim();
            if (!productName) {
                showAlert('Inserisci un nome per il prodotto', 'error');
                return;
            }
            
            makeRequest('create_product', { product_name: productName })
                .then(response => {
                    if (response.success) {
                        showAlert('Prodotto creato con successo');
                        newProductForm.style.display = 'none';
                        newProductName.value = '';
                        
                        // Aggiungi il nuovo prodotto alla lista
                        const newProductDiv = document.createElement('div');
                        newProductDiv.className = 'sku-item product-item';
                        newProductDiv.dataset.productId = response.product_id;
                        newProductDiv.innerHTML = `
                            <div class="sku-info">
                                <div class="sku-code">${productName}</div>
                                <div class="sku-details">ID: ${response.product_id}</div>
                            </div>
                            <button class="btn btn-primary btn-sm select-product-btn" data-product-id="${response.product_id}">
                                Seleziona
                            </button>
                        `;
                        
                        productsList.insertBefore(newProductDiv, productsList.firstChild);
                        
                        // Aggiungi event listener
                        const selectBtn = newProductDiv.querySelector('.select-product-btn');
                        selectBtn.addEventListener('click', () => selectProduct(response.product_id, productName));
                        
                        // Seleziona automaticamente il nuovo prodotto
                        selectProduct(response.product_id, productName);
                    } else {
                        showAlert(response.error || 'Errore nella creazione del prodotto', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        }

        // Aggrega SKU
        function aggregateSkus() {
            if (selectedSkus.length === 0 || !selectedProductId) {
                showAlert('Seleziona almeno un SKU e un prodotto', 'error');
                return;
            }
            
            // Prepara i dati per l'aggregazione
            const skusToAggregate = {};
            selectedSkus.forEach(item => {
                if (!skusToAggregate[item.source]) {
                    skusToAggregate[item.source] = [];
                }
                skusToAggregate[item.source].push(item.sku);
            });
            
            // Mostra loading
            aggregateBtnText.style.display = 'none';
            aggregateBtnLoading.style.display = 'inline-block';
            aggregateBtn.disabled = true;
            
            makeRequest('aggregate_skus', {
                product_id: selectedProductId,
                skus_to_aggregate: JSON.stringify(skusToAggregate)
            })
                .then(response => {
                    if (response.success) {
                        showAlert(`Aggregazione completata! ${response.updated_count} SKU aggregati.`);
                        
                        // Reset selezioni
                        selectedSkus = [];
                        updateSelectedSkusDisplay();
                        
                        // Ricarica SKU non mappati
                        loadUnmappedSkus(currentSource);
                    } else {
                        showAlert(response.errors ? response.errors.join(', ') : 'Errore nell\'aggregazione', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                })
                .finally(() => {
                    // Nascondi loading
                    aggregateBtnText.style.display = 'inline';
                    aggregateBtnLoading.style.display = 'none';
                    updateAggregateButton();
                });
        }

        // === FUNZIONI PENDING MAPPINGS ===

        // Gestione tab
        function switchTab(tabName) {
            currentTab = tabName;
            
            // Aggiorna bottoni tab
            tabButtons.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tabName);
            });
            
            // Mostra/nascondi contenuti
            unmappedTab.style.display = tabName === 'unmapped' ? 'block' : 'none';
            pendingTab.style.display = tabName === 'pending' ? 'block' : 'none';
            syncTab.style.display = tabName === 'sync' ? 'block' : 'none';
            
            // Carica dati della tab attiva
            if (tabName === 'pending') {
                loadPendingMappings();
            } else if (tabName === 'sync') {
                // Tab sincronizzazione aperta
                // L'utente deve cliccare manualmente "Carica SKU Parziali"
            }
        }

        // Carica mapping pending
        function loadPendingMappings() {
            makeRequest('get_pending_mappings')
                .then(response => {
                    if (response.success) {
                        pendingMappingsData = response.mappings;
                        renderPendingMappings(response.mappings);
                        updatePendingStats(response.stats);
                        updatePendingCount(response.stats.total_pending);
                    } else {
                        showAlert('Errore nel caricamento delle approvazioni pending', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        }

        // Renderizza mapping pending
        function renderPendingMappings(mappings) {
            pendingMappingsList.innerHTML = '';
            
            if (mappings.length === 0) {
                pendingMappingsList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #6B7280;">🎉 Nessuna approvazione pending!</div>';
                return;
            }

            mappings.forEach(mapping => {
                const confidenceClass = 
                    mapping.confidence_score >= 0.85 ? 'confidence-high' :
                    mapping.confidence_score >= 0.70 ? 'confidence-medium' : 'confidence-low';
                
                const mappingDiv = document.createElement('div');
                mappingDiv.className = 'pending-item';
                mappingDiv.dataset.mappingId = mapping.id;
                
                mappingDiv.innerHTML = `
                    <div class="pending-info">
                        <div class="pending-sku">${mapping.sku}</div>
                        <div class="pending-product">${mapping.suggested_product_name || 'Prodotto suggerito'}</div>
                        <div class="confidence-badge ${confidenceClass}">
                            ${Math.round(mapping.confidence_score * 100)}%
                        </div>
                    </div>
                    <div class="pending-actions">
                        <button class="btn btn-success btn-sm" onclick="approveSingleMapping(${mapping.id})">
                            ✅ Approva
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="rejectSingleMapping(${mapping.id})">
                            ❌ Rifiuta
                        </button>
                    </div>
                `;
                
                // Click per selezione multipla
                mappingDiv.addEventListener('click', (e) => {
                    if (!e.target.closest('.pending-actions')) {
                        togglePendingSelection(mapping.id, mappingDiv);
                    }
                });
                
                pendingMappingsList.appendChild(mappingDiv);
            });
        }

        // Toggle selezione pending mapping
        function togglePendingSelection(mappingId, element) {
            const index = selectedPendingMappings.indexOf(mappingId);
            
            if (index > -1) {
                selectedPendingMappings.splice(index, 1);
                element.classList.remove('selected');
            } else {
                selectedPendingMappings.push(mappingId);
                element.classList.add('selected');
            }
            
            updateBulkButtons();
        }

        // Aggiorna contatori e statistiche
        function updatePendingStats(stats) {
            totalPendingSpan.textContent = stats.total_pending;
            highConfidenceSpan.textContent = stats.high_confidence;
            mediumConfidenceSpan.textContent = stats.medium_confidence;
            lowConfidenceSpan.textContent = stats.low_confidence;
        }

        function updatePendingCount(count) {
            pendingCountBadge.textContent = count;
            pendingCountBadge.style.display = count > 0 ? 'block' : 'none';
        }

        function updateBulkButtons() {
            const hasSelection = selectedPendingMappings.length > 0;
            approveAllBtn.disabled = !hasSelection;
            rejectAllBtn.disabled = !hasSelection;
            
            if (hasSelection) {
                approveAllBtn.textContent = `✅ Approva ${selectedPendingMappings.length}`;
                rejectAllBtn.textContent = `❌ Rifiuta ${selectedPendingMappings.length}`;
            } else {
                approveAllBtn.textContent = '✅ Approva Selezionati';
                rejectAllBtn.textContent = '❌ Rifiuta Selezionati';
            }
        }

        // Approva singolo mapping (funzione globale per onclick)
        window.approveSingleMapping = function(mappingId) {
            makeRequest('approve_pending_mapping', { mapping_id: mappingId })
                .then(response => {
                    if (response.success) {
                        showAlert('Mapping approvato con successo!');
                        loadPendingMappings();
                        loadAllUnmappedSkus(); // Aggiorna anche tab unmapped
                    } else {
                        showAlert(response.error || 'Errore nell\'approvazione', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        };

        // Rifiuta singolo mapping (funzione globale per onclick)
        window.rejectSingleMapping = function(mappingId) {
            makeRequest('reject_pending_mapping', { mapping_id: mappingId })
                .then(response => {
                    if (response.success) {
                        showAlert('Mapping rifiutato');
                        loadPendingMappings();
                    } else {
                        showAlert(response.error || 'Errore nel rifiuto', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        };

        // Operazioni bulk
        function bulkApprove() {
            if (selectedPendingMappings.length === 0) return;
            
            makeRequest('bulk_process_pending', {
                mapping_ids: JSON.stringify(selectedPendingMappings),
                bulk_action: 'approve'
            })
                .then(response => {
                    if (response.success) {
                        showAlert(`${response.approved} mapping approvati!`);
                        selectedPendingMappings = [];
                        loadPendingMappings();
                        loadAllUnmappedSkus();
                    } else {
                        showAlert('Alcuni errori durante l\'approvazione bulk', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        }

        function bulkReject() {
            if (selectedPendingMappings.length === 0) return;
            
            makeRequest('bulk_process_pending', {
                mapping_ids: JSON.stringify(selectedPendingMappings),
                bulk_action: 'reject'
            })
                .then(response => {
                    if (response.success) {
                        showAlert(`${response.rejected} mapping rifiutati`);
                        selectedPendingMappings = [];
                        loadPendingMappings();
                    } else {
                        showAlert('Alcuni errori durante il rifiuto bulk', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        }

        // Visualizza SKU esistenti per un prodotto
        function viewProductSkus() {
            if (!selectedProductId) return;
            
            makeRequest('get_product_skus', { product_id: selectedProductId })
                .then(response => {
                    if (response.success) {
                        let message = 'SKU associati al prodotto:\n\n';
                        Object.keys(response.skus).forEach(source => {
                            if (response.skus[source].length > 0) {
                                message += `${source.toUpperCase()}:\n`;
                                response.skus[source].forEach(sku => {
                                    message += `- ${sku}\n`;
                                });
                                message += '\n';
                            }
                        });
                        
                        if (message === 'SKU associati al prodotto:\n\n') {
                            message = 'Nessun SKU associato a questo prodotto.';
                        }
                        
                        alert(message);
                    } else {
                        showAlert('Errore nel recupero degli SKU', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore:', error);
                    showAlert('Errore di connessione', 'error');
                });
        }

        // Event listeners
refreshSkusBtn.addEventListener('click', () => {
    loadAllUnmappedSkus();
});

        let productSearchTimeout;
        productSearch.addEventListener('input', (e) => {
            clearTimeout(productSearchTimeout);
            productSearchTimeout = setTimeout(() => {
                searchProducts(e.target.value);
            }, 300);
        });

        createNewProductBtn.addEventListener('click', () => {
            newProductForm.style.display = newProductForm.style.display === 'none' ? 'block' : 'none';
        });

        saveNewProductBtn.addEventListener('click', createNewProduct);

        cancelNewProductBtn.addEventListener('click', () => {
            newProductForm.style.display = 'none';
            newProductName.value = '';
        });

        aggregateBtn.addEventListener('click', aggregateSkus);

        viewProductSkusBtn.addEventListener('click', viewProductSkus);

        // Event listeners per selezione prodotti
        document.querySelectorAll('.select-product-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const productId = btn.dataset.productId;
                const productName = btn.closest('.product-item').querySelector('.sku-code').textContent;
                selectProduct(productId, productName);
            });
        });

        // === EVENT LISTENERS PENDING MAPPINGS ===
        
        // Tab navigation
        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                switchTab(btn.dataset.tab);
            });
        });

        // Bottoni pending
        refreshPendingBtn.addEventListener('click', loadPendingMappings);
        approveAllBtn.addEventListener('click', bulkApprove);
        rejectAllBtn.addEventListener('click', bulkReject);

// Caricamento iniziale - mostra tutti gli SKU
<?php if (!empty($allUnmappedSkus)): ?>
renderAllUnmappedSkus(<?= json_encode($allUnmappedSkus) ?>);
<?php else: ?>
loadAllUnmappedSkus();
<?php endif; ?>

        // Carica conteggio pending all'avvio
        setTimeout(() => {
            makeRequest('get_pending_mappings')
                .then(response => {
                    if (response.success && response.stats) {
                        updatePendingCount(response.stats.total_pending);
                    }
                })
                .catch(error => console.error('Errore caricamento conteggio pending:', error));
        }, 1000);

        // ============================================
        // SYNC TAB FUNCTIONALITY
        // ============================================

        let partialMappingsData = [];

        // Load partial mappings
        const loadPartialBtn = document.getElementById('load-partial-btn');
        const syncAllBtn = document.getElementById('sync-all-btn');
        const syncLoading = document.getElementById('sync-loading');
        const syncResults = document.getElementById('sync-results');
        const syncCountInfo = document.getElementById('sync-count-info');
        const syncCount = document.getElementById('sync-count');
        const partialMappingsTableWrapper = document.getElementById('partial-mappings-table-wrapper');
        const partialMappingsTable = document.getElementById('partial-mappings-table');
        const partialMappingsBody = document.getElementById('partial-mappings-body');

        if (loadPartialBtn) {
            loadPartialBtn.addEventListener('click', function() {
                syncLoading.style.display = 'block';
                partialMappingsTableWrapper.style.display = 'none';
                syncAllBtn.style.display = 'none';
                syncCountInfo.style.display = 'none';
                syncResults.innerHTML = '';
                
                makeRequest('get_partial_mappings', { limit: 1000 })
                    .then(response => {
                        syncLoading.style.display = 'none';
                        
                        if (response.success && response.mappings.length > 0) {
                            partialMappingsData = response.mappings;
                            renderPartialMappings(response.mappings);
                            document.getElementById('partial-mappings-table-wrapper').style.display = 'block';
                            syncAllBtn.style.display = 'inline-block';
                            syncCountInfo.style.display = 'block';
                            syncCount.textContent = `Trovati ${response.mappings.length} SKU da sincronizzare`;
                        } else {
                            syncResults.innerHTML = '<div style="background: #D1FAE5; border: 1px solid #10B981; color: #065F46; padding: 1rem; border-radius: 6px; margin: 1rem 0;">✅ Nessun mapping parziale trovato! Tutti gli SKU sono sincronizzati.</div>';
                        }
                    })
                    .catch(err => {
                        syncLoading.style.display = 'none';
                        syncResults.innerHTML = '<div style="background: #FEE2E2; border: 1px solid #EF4444; color: #991B1B; padding: 1rem; border-radius: 6px; margin: 1rem 0;">❌ Errore caricamento: ' + err.message + '</div>';
                    });
            });
        }

        // Render partial mappings table - NUOVA LOGICA
        function renderPartialMappings(mappings) {
            partialMappingsBody.innerHTML = '';
            
            mappings.forEach((m, idx) => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #E5E7EB';
                
                // Colonna "Record da Sincronizzare"
                let recordsColumnHtml = `
                    <span class="badge-sync badge-success-sync" style="background: #10B981; font-size: 0.9rem; padding: 0.3rem 0.6rem;">
                        ${m.total_unmapped_records} record
                    </span><br>
                    <small style="color: #059669; display: block; margin-top: 0.5rem; font-size: 0.75rem;">
                        ${escapeHtml(m.unmapped_tables_str || 'Nessuno')}
                    </small>
                `;
                
                // Bottone Sincronizza
                let syncButtonHtml = `
                    <button class="btn btn-primary btn-sm" 
                            style="padding: 0.5rem 1rem; font-size: 0.875rem;" 
                            onclick="syncSingleSku('${escapeHtml(m.sku).replace(/'/g, "\\'")}', ${m.product_id}, '${escapeHtml(m.mapped_tables_str)}', ${idx})"
                            title="Sincronizza ${m.total_unmapped_records} record">
                        ⚡ Sincronizza (${m.total_unmapped_records})
                    </button>
                `;
                
                tr.innerHTML = `
                    <td style="padding: 1rem;"><strong style="font-family: monospace; color: #1F2937;">${escapeHtml(m.sku)}</strong></td>
                    <td style="padding: 1rem; color: #374151;">${escapeHtml(m.product_name || 'N/A')}</td>
                    <td style="padding: 1rem; text-align: center;">
                        <span class="badge-sync badge-success-sync">${m.mapped_count}/5</span><br>
                        <small style="color: #6B7280; display: block; margin-top: 0.5rem; font-size: 0.7rem;">${escapeHtml(m.mapped_tables_str || 'N/A')}</small>
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        ${recordsColumnHtml}
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        ${syncButtonHtml}
                    </td>
                `;
                partialMappingsBody.appendChild(tr);
            });
        }

        // Sync single SKU
        window.syncSingleSku = function(sku, productId, mappedTables, rowIndex) {
            if (!confirm(`Sincronizzare "${sku}" a tutte le tabelle dove esiste?`)) {
                return;
            }
            
            const sourceTables = mappedTables.split(',');
            
            const requestData = {
                sku: sku,
                product_id: productId,
                source_tables: JSON.stringify(sourceTables)
            };
            
            makeRequest('sync_sku_across_tables', requestData)
                .then(response => {
                    if (response.success) {
                        showAlert(`✅ SKU "${sku}" sincronizzato! ${response.updated} record aggiornati.`);
                        
                        // Rimuovi riga dalla tabella
                        const row = partialMappingsBody.rows[rowIndex];
                        if (row) row.remove();
                        
                        // Aggiorna contatore
                        partialMappingsData = partialMappingsData.filter(m => m.sku !== sku);
                        syncCount.textContent = `Rimangono ${partialMappingsData.length} SKU da sincronizzare`;
                        
                        if (partialMappingsData.length === 0) {
                            document.getElementById('partial-mappings-table-wrapper').style.display = 'none';
                            syncAllBtn.style.display = 'none';
                            syncCountInfo.style.display = 'none';
                            syncResults.innerHTML = '<div style="background: #D1FAE5; border: 1px solid #10B981; color: #065F46; padding: 1rem; border-radius: 6px; margin: 1rem 0;">✅ Tutti gli SKU sono ora sincronizzati!</div>';
                        }
                    } else {
                        showAlert(`❌ Errore: ${response.error || 'Sconosciuto'}`, 'error');
                    }
                })
                .catch(err => {
                    showAlert('❌ Errore: ' + err.message, 'error');
                });
        };

        // Sync all partial mappings
        if (syncAllBtn) {
            syncAllBtn.addEventListener('click', function() {
                if (!confirm(`Sincronizzare TUTTI i ${partialMappingsData.length} SKU in batch?`)) {
                    return;
                }
                
                this.disabled = true;
                const originalText = this.textContent;
                this.textContent = '⏳ Sincronizzazione in corso...';
                
                makeRequest('sync_all_partial', { batch_limit: 1000 })
                    .then(response => {
                        
                        this.disabled = false;
                        this.textContent = originalText;
                        
                        if (response.success) {
                            let message = `✅ Batch completato! ${response.synced}/${response.total} SKU sincronizzati.`;
                            if (response.total_records_updated) {
                                message += `\n📊 Totale record aggiornati: ${response.total_records_updated}`;
                            }
                            if (response.skipped > 0) {
                                message += `\n⚠️ ${response.skipped} SKU saltati (richiedono mapping manuale).`;
                            }
                            if (response.errors && response.errors.length > 0) {
                                message += `\n❌ ${response.errors.length} errori.`;
                            }
                            showAlert(message);
                            
                            // Ricarica lista
                            loadPartialBtn.click();
                        } else {
                            showAlert(`⚠️ Batch parziale: ${response.synced}/${response.total} completati. Errori: ${response.errors.length}`, 'warning');
                        }
                    })
                    .catch(err => {
                        this.disabled = false;
                        this.textContent = originalText;
                        showAlert('❌ Errore: ' + err.message, 'error');
                    });
            });
        }

        // Helper: escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
</script>

    </div> <!-- /main-container -->
</body>
</html>

