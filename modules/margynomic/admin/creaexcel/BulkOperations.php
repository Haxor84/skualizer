<?php
/**
 * Bulk Operations - Find/Replace & Price Sync
 * File: modules/margynomic/admin/creaexcel/BulkOperations.php
 */

// ========================================
// DEBUG MODE - ATTIVATO
// ========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Avvia sessione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/CentralLogger.php';

// CentralLogger is loaded without namespace

class BulkOperations {
    
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDbConnection();
    }
    
    /**
     * Find & Replace su tutte le colonne testuali o colonne specifiche
     * 
     * @param int $excelId ID del file Excel
     * @param string $search Testo da cercare
     * @param string $replace Testo sostitutivo
     * @param bool $caseSensitive Case sensitive search
     * @param array|null $columns Array di column letters (es: ['A','B']) o null per tutte
     */
    public function findReplace($excelId, $search, $replace, $caseSensitive = false, $columns = null) {
        try {
            // Carica righe Excel
            $stmt = $this->pdo->prepare("
                SELECT `id`, `row_data`
                FROM `excel_listing_rows`
                WHERE `excel_id` = ?
            ");
            $stmt->execute([$excelId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $replacedCount = 0;
            $affectedRows = 0;
            
            $this->pdo->beginTransaction();
            
            $updateStmt = $this->pdo->prepare("
                UPDATE `excel_listing_rows`
                SET `row_data` = ?, `modified` = 1
                WHERE `id` = ?
            ");
            
            foreach ($rows as $row) {
                $rowData = json_decode($row['row_data'], true);
                $rowModified = false;
                $rowReplacedCount = 0;
                
                foreach ($rowData as $col => $value) {
                    // Solo colonne testuali
                    if (!is_string($value)) {
                        continue;
                    }
                    
                    // Se colonne specifiche specificate, filtra
                    if ($columns !== null && !in_array($col, $columns)) {
                        continue;
                    }
                    
                    // Esegui replace
                    if ($caseSensitive) {
                        $newValue = str_replace($search, $replace, $value);
                    } else {
                        $newValue = str_ireplace($search, $replace, $value);
                    }
                    
                    if ($newValue !== $value) {
                        $rowData[$col] = $newValue;
                        $rowModified = true;
                        
                        // Conta occorrenze
                        if ($caseSensitive) {
                            $count = substr_count($value, $search);
                        } else {
                            $count = substr_count(strtolower($value), strtolower($search));
                        }
                        $rowReplacedCount += $count;
                    }
                }
                
                if ($rowModified) {
                    $newRowDataJson = json_encode($rowData, JSON_UNESCAPED_UNICODE);
                    $updateStmt->execute([$newRowDataJson, $row['id']]);
                    
                    $affectedRows++;
                    $replacedCount += $rowReplacedCount;
                }
            }
            
            $this->pdo->commit();
            
            $columnsMsg = $columns !== null ? implode(', ', $columns) : 'tutte';
            
            CentralLogger::info('admin', 'Bulk replace completato', [
                'excel_id' => $excelId,
                'search' => $search,
                'replace' => $replace,
                'columns' => $columnsMsg,
                'affected_rows' => $affectedRows,
                'replaced_count' => $replacedCount
            ]);
            
            return [
                'success' => true,
                'affected_rows' => $affectedRows,
                'replaced_count' => $replacedCount,
                'columns_searched' => $columns !== null ? count($columns) : 'all'
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            CentralLogger::error('admin', 'Errore bulk replace: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Sync prezzi da database products (unidirezionale DB → Excel)
     */
    public function syncPricesFromProducts($excelId) {
        try {
            // Get user_id
            $stmt = $this->pdo->prepare("SELECT `user_id`, `metadata` FROM `excel_listings` WHERE `id` = ?");
            $stmt->execute([$excelId]);
            $excel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$excel) {
                throw new Exception('Excel non trovato');
            }
            
            $userId = $excel['user_id'];
            $metadata = json_decode($excel['metadata'], true);
            $columnMapping = $metadata['column_mapping'] ?? [];
            
            // Identifica colonna prezzo
            $priceColumn = $columnMapping['price_standard'] ?? null;
            
            if (!$priceColumn) {
                throw new Exception('Colonna prezzo non identificata');
            }
            
            // Query righe con product_id mappato
            $stmt = $this->pdo->prepare("
                SELECT elr.`id`, elr.`row_data`, elr.`product_id`, p.`prezzo_attuale`
                FROM `excel_listing_rows` elr
                JOIN `products` p ON elr.`product_id` = p.`id`
                WHERE elr.`excel_id` = ? AND elr.`product_id` IS NOT NULL
            ");
            $stmt->execute([$excelId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $syncedCount = 0;
            $skippedCount = 0;
            
            $this->pdo->beginTransaction();
            
            $updateStmt = $this->pdo->prepare("
                UPDATE `excel_listing_rows`
                SET `row_data` = ?, `modified` = 1
                WHERE `id` = ?
            ");
            
            foreach ($rows as $row) {
                $rowData = json_decode($row['row_data'], true);
                $dbPrice = $row['prezzo_attuale'];
                $excelPrice = $rowData[$priceColumn] ?? null;
                
                // Normalizza prezzi per confronto
                $dbPriceFloat = (float)$dbPrice;
                $excelPriceFloat = (float)$excelPrice;
                
                // Se diversi, aggiorna Excel con prezzo DB
                if (abs($dbPriceFloat - $excelPriceFloat) > 0.01) {
                    $rowData[$priceColumn] = number_format($dbPriceFloat, 2, '.', '');
                    
                    $newRowDataJson = json_encode($rowData, JSON_UNESCAPED_UNICODE);
                    $updateStmt->execute([$newRowDataJson, $row['id']]);
                    
                    $syncedCount++;
                } else {
                    $skippedCount++;
                }
            }
            
            $this->pdo->commit();
            
            CentralLogger::info('admin', 'Price sync completato', [
                'excel_id' => $excelId,
                'synced' => $syncedCount,
                'skipped' => $skippedCount
            ]);
            
            return [
                'success' => true,
                'synced' => $syncedCount,
                'skipped' => $skippedCount
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            CentralLogger::error('admin', 'Errore price sync: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>

