<?php

/**
 * Settlement Parser Completo per Margynomic
 * File: sincro/settlement_parser.php
 * 
 * Replica esatta logica Skualizer: parsing TSV + anti-duplicati + validazione
 */

require_once dirname(__DIR__) . '/config/config.php';

class SettlementParser {
    
    private $fileHeaderToDbColumnMap = [
        "settlement-id" => "settlement_id",
        "settlement-start-date" => "settlement_start_date",
        "settlement-end-date" => "settlement_end_date",
        "deposit-date" => "deposit_date",
        "total-amount" => "total_amount",
        "currency" => "currency",
        "transaction-type" => "transaction_type",
        "order-id" => "order_id",
        "merchant-order-id" => "merchant_order_id",
        "adjustment-id" => "adjustment_id",
        "shipment-id" => "shipment_id",
        "marketplace-name" => "marketplace_name",
        "shipment-fee-type" => "shipment_fee_type",
        "shipment-fee-amount" => "shipment_fee_amount",
        "order-fee-type" => "order_fee_type",
        "order-fee-amount" => "order_fee_amount",
        "fulfillment-id" => "fulfillment_id",
        "posted-date" => "posted_date",
        "order-item-code" => "order_item_code",
        "merchant-order-item-id" => "merchant_order_item_id",
        "merchant-adjustment-item-id" => "merchant_adjustment_item_id",
        "sku" => "sku",
        "quantity-purchased" => "quantity_purchased",
        "price-type" => "price_type",
        "price-amount" => "price_amount",
        "item-related-fee-type" => "item_related_fee_type",
        "item-related-fee-amount" => "item_related_fee_amount",
        "misc-fee-amount" => "misc_fee_amount",
        "other-fee-amount" => "other_fee_amount",
        "other-fee-reason-description" => "other_fee_reason_description",
        "promotion-id" => "promotion_id",
        "promotion-type" => "promotion_type",
        "promotion-amount" => "promotion_amount",
        "direct-payment-type" => "direct_payment_type",
        "direct-payment-amount" => "direct_payment_amount",
        "other-amount" => "other_amount"
    ];
    
    /**
 * Funzione principale: parse e import completo
 */
public function parseAndImportSettlement($userId, $filePath) {
try {
    $tableName = "report_settlement_{$userId}";
        
    // 1. Verifica se file già importato
    if ($this->isFileAlreadyImported($userId, basename($filePath))) {
        return ['success' => false, 'error' => 'File già importato precedentemente'];
    }
        
    // 2. Parse TSV file
    $result = $this->parseCsvTsv($filePath);
    if (!$result) {
        return ['success' => false, 'error' => 'Errore parsing file TSV - formato non valido'];
    }
    
    // 3. Verifica se settlement già importato
    $settlementId = $result['summary_row']['settlement-id'] ?? '';
    if ($this->isSettlementAlreadyImported($userId, $settlementId)) {
        return ['success' => false, 'error' => "Settlement {$settlementId} già importato"];
    }
        
    $processedRows = 0;
    $skippedRows = 0;
    $errors = [];
    
    // 4. Process transaction rows
    foreach ($result['rows'] as $rowIndex => $row) {
        try {
            $dbData = $this->mapRowToDatabase($row);
                
            // Generate hash anti-duplicati (logica identica Skualizer)
            $rowForHash = [];
            foreach ($this->fileHeaderToDbColumnMap as $fileKey => $dbKey) {
                $val = (string) ($dbData[$dbKey] ?? "");
                $rowForHash[$fileKey] = $val;
            }
            $dbData["hash"] = hash("sha256", json_encode($rowForHash));
            
            // Insert con gestione duplicati
            if ($this->insertRowSafe($tableName, $dbData)) {
                $processedRows++;
            } else {
                $skippedRows++;
            }
                
        } catch (Exception $e) {
            $errors[] = "Riga {$rowIndex}: " . $e->getMessage();
            $skippedRows++;
        }
    }
            
            // 5. Salva metadata settlement (total_amount, date)
            $this->saveSettlementMetadata($userId, $result['summary_row']);
            
            // 6. Log import nel database
            $this->logImport($userId, $settlementId, 
                           $result['summary_row']['settlement-start-date'] ?? null,
                           $result['summary_row']['settlement-end-date'] ?? null,
                           basename($filePath), $processedRows);
            
            return [
                'success' => true,
                'processed' => $processedRows,
                'skipped' => $skippedRows,
                'settlement_id' => $settlementId,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Parse completo file TSV Amazon (replica logica Skualizer)
     */
    private function parseCsvTsv($filePath) {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }
        
        // Gestione encoding (UTF-8, ISO, Windows)
        $encoding = mb_detect_encoding($content, ["UTF-8", "ISO-8859-1", "Windows-1252"], true);
        if ($encoding && $encoding !== "UTF-8") {
            $content = mb_convert_encoding($content, "UTF-8", $encoding);
        }
        
        // Rimozione righe vuote
        $lines = explode("\n", $content);
        $cleanedLines = [];
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (!empty($trimmedLine)) {
                $cleanedLines[] = $trimmedLine;
            }
        }
        
        if (count($cleanedLines) < 2) {
            return false;
        }
        
        // Prima riga = transaction headers
        $transactionHeadersLine = array_shift($cleanedLines);
        $delimiter = "\t"; // Amazon V2 è sempre TSV
        $transactionHeaders = str_getcsv($transactionHeadersLine, $delimiter);
        
        // Rimozione BOM UTF-8
        $transactionHeaders = array_map(function($h) {
            if (strpos($h, "\xEF\xBB\xBF") === 0) {
                $h = substr($h, 3);
            }
            return trim($h);
        }, $transactionHeaders);
        
        // Seconda riga = summary data
        $summaryDataLine = array_shift($cleanedLines);
        $summaryValues = str_getcsv($summaryDataLine, $delimiter);
        
        // Gestione righe con numero colonne diverso
        if (count($summaryValues) !== count($transactionHeaders)) {
            // Padding con valori vuoti
            while (count($summaryValues) < count($transactionHeaders)) {
                $summaryValues[] = '';
            }
        }
        
        $summaryRowAssoc = array_combine($transactionHeaders, $summaryValues);
        
        // Righe rimanenti = transaction data
        $transactionRowsAssoc = [];
        foreach ($cleanedLines as $line) {
            $values = str_getcsv($line, $delimiter);
            
            // Padding per righe incomplete
            while (count($values) < count($transactionHeaders)) {
                $values[] = '';
            }
            
            if (count($values) >= count($transactionHeaders)) {
                $transactionRowsAssoc[] = array_combine($transactionHeaders, array_slice($values, 0, count($transactionHeaders)));
            }
        }
        
        return [
            "summary_row" => $summaryRowAssoc,
            "rows" => $transactionRowsAssoc
        ];
    }
    
    /**
     * Mapping row TSV → database (logica identica Skualizer)
     */
    private function mapRowToDatabase($row) {
        $dbData = [];
        
        foreach ($this->fileHeaderToDbColumnMap as $fileKey => $dbKey) {
            $value = $row[$fileKey] ?? '';
            
            // Gestione date
            if (in_array($dbKey, ['settlement_start_date', 'settlement_end_date', 'deposit_date', 'posted_date'])) {
                if (!empty($value)) {
                    try {
                        $dt = new DateTime($value);
                        $dbData[$dbKey] = $dt->format("Y-m-d H:i:s");
                    } catch (Exception $e) {
                        $dbData[$dbKey] = null;
                    }
                } else {
                    $dbData[$dbKey] = null;
                }
            }
            // Gestione campi numerici
            elseif (in_array($dbKey, ['total_amount', 'shipment_fee_amount', 'order_fee_amount', 
                                     'quantity_purchased', 'price_amount', 'item_related_fee_amount',
                                     'misc_fee_amount', 'other_fee_amount', 'promotion_amount',
                                     'direct_payment_amount', 'other_amount'])) {
                $value = str_replace(",", ".", $value);
                $dbData[$dbKey] = is_numeric($value) ? floatval($value) : null;
            }
            // Gestione campi testo
            else {
                $dbData[$dbKey] = !empty($value) ? $value : null;
            }
        }
        
        return $dbData;
    }
    
    /**
     * Insert sicuro con gestione duplicati
     */
    private function insertRowSafe($tableName, $data) {
        try {
            $pdo = getDbConnection();
            
            $columns = array_keys($data);
            $placeholders = ':' . implode(', :', $columns);
            $columnsStr = '`' . implode('`, `', $columns) . '`';
            
            // INSERT IGNORE per evitare errori su hash duplicati
            $sql = "INSERT IGNORE INTO `{$tableName}` ({$columnsStr}) VALUES ({$placeholders})";
            $stmt = $pdo->prepare($sql);
            
            $result = $stmt->execute($data);
            
            // Return true se inserito, false se skippato (duplicato)
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            throw new Exception("Errore insert database: " . $e->getMessage());
        }
    }
    
    /**
     * Verifica se file già importato
     */
    private function isFileAlreadyImported($userId, $filename) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM import_log WHERE user_id = ? AND filename = ?");
            $stmt->execute([$userId, $filename]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Verifica se settlement già importato
     */
    private function isSettlementAlreadyImported($userId, $settlementId) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM import_log WHERE user_id = ? AND settlement_id = ?");
            $stmt->execute([$userId, $settlementId]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Salva metadata settlement (total_amount, date) in tabella separata
     */
    private function saveSettlementMetadata($userId, $summaryRow) {
        try {
            $pdo = getDbConnection();
            
            $settlementId = $summaryRow['settlement-id'] ?? null;
            $totalAmount = $summaryRow['total-amount'] ?? null;
            $currency = $summaryRow['currency'] ?? null;
            
            // Parse date
            $startDate = $this->parseDateMeta($summaryRow['settlement-start-date'] ?? null);
            $endDate = $this->parseDateMeta($summaryRow['settlement-end-date'] ?? null);
            $depositDate = $this->parseDateMeta($summaryRow['deposit-date'] ?? null);
            
            if (!$settlementId) return;
            
            $stmt = $pdo->prepare("
                INSERT INTO settlement_metadata 
                (user_id, settlement_id, settlement_start_date, settlement_end_date, 
                 deposit_date, total_amount, currency)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_amount = VALUES(total_amount),
                    settlement_start_date = VALUES(settlement_start_date),
                    settlement_end_date = VALUES(settlement_end_date),
                    deposit_date = VALUES(deposit_date),
                    currency = VALUES(currency)
            ");
            
            $stmt->execute([
                $userId, $settlementId, $startDate, $endDate, 
                $depositDate, $totalAmount, $currency
            ]);
            
        } catch (Exception $e) {
            error_log("Errore save metadata settlement: " . $e->getMessage());
        }
    }
    
    /**
     * Helper per parse date metadata
     */
    private function parseDateMeta($value) {
        if (empty($value)) return null;
        try {
            $dt = new DateTime($value);
            return $dt->format("Y-m-d H:i:s");
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Log import nel database
     */
    private function logImport($userId, $settlementId, $startDate, $endDate, $filename, $rowCount) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("
                INSERT INTO import_log 
                (user_id, settlement_id, settlement_start_date, settlement_end_date, filename, row_count_db) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $settlementId, $startDate, $endDate, $filename, $rowCount]);
        } catch (Exception $e) {
            error_log("Errore log import: " . $e->getMessage());
        }
    }
}

// Funzione helper per chiamate esterne
function parseSettlementFile($userId, $filePath) {
    $parser = new SettlementParser();
    return $parser->parseAndImportSettlement($userId, $filePath);
}
?>