<?php
/**
 * Excel Parser - Amazon Listing Manager (VERSIONE DEFINITIVA)
 * File: modules/margynomic/admin/creaexcel/ExcelParser.php
 * 
 * Parsa file Excel Amazon con gestione:
 * - Skip riga 1 (metadati Amazon)
 * - Riga 2 = headers italiano/readable
 * - Riga 3 = nomi tecnici campi (per Named Ranges)
 * - Riga 4 = vuota/metadata
 * - Riga 5+ = dati prodotti
 * - Fuzzy match colonne
 * - Match SKU con products table
 */

// SOPPRIMI TUTTI I WARNING PHPSPREADSHEET (incluso deprecated)
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/CentralLogger.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Settings;

class ExcelParser {
    
    private $pdo;
    private $userId;
    
    /**
     * Mapping nomi colonne Amazon (fuzzy match patterns)
     */
    private $columnPatterns = [
        'sku' => ['sku venditore', 'seller sku', 'sku', 'seller-sku', 'item_sku'],
        'title' => ['nome articolo', 'product name', 'title', 'item name', 'item_name', 'titolo'],
        'price_standard' => ['prezzo standard', 'standard price', 'price', 'your price', 'standard_price'],
        'price_sale' => ['prezzo scontato', 'sale price', 'prezzo ridotto', 'sale_price'],
        'description' => ['descrizione del prodotto', 'product description', 'description', 'product_description'],
        'bullet_1' => ['caratteristiche chiave del prodotto', 'key product features', 'bullet point', 'punti elenco', 'bullet_point'],
        'country_origin' => ['paese/regione di origine', 'paese di origine', 'country of origin', 'country_of_origin'],
        'ingredient_origin' => ['paese/regione d\'origine dell\'ingrediente', 'ingredient country', 'primary_ingredient_country_of_origin']
    ];
    
    /**
     * Costruttore
     */
    public function __construct($userId = null) {
        $this->pdo = getDbConnection();
        $this->userId = $userId;
    }
    
    /**
     * Carica Excel con fix open_basedir
     * Disabilita temporaneamente open_basedir per permettere caricamento completo
     */
    private function loadExcelSafe($filepath) {
        if (!file_exists($filepath)) {
            throw new Exception("File not found: $filepath");
        }

        try {
            // Salva e disabilita open_basedir temporaneamente
            $oldOpenBasedir = ini_get('open_basedir');
            @ini_set('open_basedir', '');

            // Carica DIRETTAMENTE dal filepath originale
            $spreadsheet = IOFactory::load($filepath);
            
            // Ripristina open_basedir
            if ($oldOpenBasedir) {
                @ini_set('open_basedir', $oldOpenBasedir);
            }
            
            return $spreadsheet;
            
        } catch (Exception $e) {
            // Ripristina open_basedir anche in caso di errore
            if (isset($oldOpenBasedir) && $oldOpenBasedir) {
                @ini_set('open_basedir', $oldOpenBasedir);
            }
            throw new Exception("Unable to load Excel: " . $e->getMessage());
        }
    }
    
    /**
     * Parsa file Excel completo
     */
    public function parse($filepath, $userId) {
        try {
            error_log("========== EXCELPARSER::PARSE START ==========");
            error_log("Filepath: $filepath");
            error_log("User ID: $userId");
            error_log("File exists: " . (file_exists($filepath) ? 'YES' : 'NO'));
            
            $this->userId = $userId;
            
            CentralLogger::info('admin', 'Inizio parsing Excel', [
                'user_id' => $userId,
                'filepath' => basename($filepath)
            ]);
            
            // Carica file Excel con fix open_basedir
            $oldErrorReporting = error_reporting();
            error_reporting($oldErrorReporting & ~E_WARNING);
            
            $spreadsheet = $this->loadExcelSafe($filepath);
            
            error_reporting($oldErrorReporting);
            
            error_log("Spreadsheet caricato. Sheets: " . $spreadsheet->getSheetCount());
            
            // Identifica foglio dati
            $worksheet = $this->getDataSheet($spreadsheet);
            
            if (!$worksheet) {
                throw new Exception('Impossibile identificare foglio dati nel file Excel');
            }
            
            error_log("Worksheet identificato: " . $worksheet->getTitle());
            
            // Estrai headers (riga 2 + riga 3)
            $headers = $this->extractHeaders($worksheet);
            error_log("Headers estratti: " . count($headers) . " colonne");
            
            // Mappa colonne
            $columnMapping = $this->mapColumns($headers);
            error_log("Column mapping: " . json_encode($columnMapping));
            
            // Identifica colonna SKU
            $skuColumn = $columnMapping['sku'] ?? null;
            error_log("SKU column: " . ($skuColumn ?? 'NOT FOUND'));
            
            if (!$skuColumn) {
                CentralLogger::warning('admin', 'Colonna SKU non identificata nel file', [
                    'user_id' => $userId,
                    'first_headers' => array_slice($headers, 0, 10)
                ]);
            }
            
            // Conta righe e colonne
            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
            
            $numRows = $highestRow - 4; // Esclude righe 1-4
            $numColumns = $highestColumnIndex;
            
            error_log("Highest row: $highestRow, Data rows: $numRows, Columns: $numColumns");
            
            // Risultato parsing
            $result = [
                'worksheet' => $worksheet,
                'headers' => $headers,
                'column_mapping' => $columnMapping,
                'sku_column' => $skuColumn,
                'num_rows' => $numRows,
                'num_columns' => $numColumns,
                'first_data_row' => 5,
                'header_row_display' => 2,
                'header_row_technical' => 3
            ];
            
            CentralLogger::info('admin', 'Parsing Excel completato', [
                'user_id' => $userId,
                'num_rows' => $numRows,
                'num_columns' => $numColumns,
                'sku_column' => $skuColumn
            ]);
            
            error_log("========== EXCELPARSER::PARSE SUCCESS ==========");
            
            return $result;
            
        } catch (Exception $e) {
            error_log("========== EXCELPARSER::PARSE ERROR ==========");
            error_log("Exception: " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());
            
            CentralLogger::error('admin', 'Errore parsing Excel: ' . $e->getMessage(), [
                'user_id' => $userId,
                'filepath' => basename($filepath)
            ]);
            throw $e;
        }
    }
    
    /**
     * Identifica foglio dati (cerca "Modello", "Template" o primo foglio)
     */
    private function getDataSheet($spreadsheet) {
        $targetNames = ['Modello', 'Template', 'Data', 'Sheet1'];
        
        foreach ($targetNames as $name) {
            try {
                $sheet = $spreadsheet->getSheetByName($name);
                if ($sheet) {
                    return $sheet;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        // Fallback: primo foglio
        return $spreadsheet->getSheet(0);
    }
    
    /**
     * Estrai headers da riga 2 (display) e riga 3 (technical)
     */
    private function extractHeaders($worksheet) {
        $headers = [];
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $columnLetter = Coordinate::stringFromColumnIndex($col);
            
            // Riga 2: Header italiano/readable
            $headerDisplay = $worksheet->getCell($columnLetter . '2')->getValue();
            
            // Riga 3: Nome tecnico campo Amazon
            $headerTechnical = $worksheet->getCell($columnLetter . '3')->getValue();
            
            // Converti RichText in stringa
            if ($headerDisplay instanceof RichText) {
                $headerDisplay = $headerDisplay->getPlainText();
            }
            if ($headerTechnical instanceof RichText) {
                $headerTechnical = $headerTechnical->getPlainText();
            }
            
            $headerDisplay = trim((string)$headerDisplay);
            $headerTechnical = trim((string)$headerTechnical);
            
            // Normalizza per matching
            $normalizedDisplay = $this->normalizeString($headerDisplay);
            
            $headers[$columnLetter] = [
                'original' => $headerDisplay,
                'normalized' => $normalizedDisplay,
                'technical' => $headerTechnical  // CRITICO per Named Ranges
            ];
        }
        
        return $headers;
    }
    
    /**
     * Mappa colonne Excel a campi Amazon usando fuzzy match
     */
    private function mapColumns($headers) {
        $mapping = [];
        
        // FASE 1: Match ESATTO su nome tecnico (priorità massima)
        foreach ($headers as $columnLetter => $headerData) {
            $technical = strtolower(trim($headerData['technical'] ?? ''));
            
            if (empty($technical)) {
                continue;
            }
            
            foreach ($this->columnPatterns as $field => $patterns) {
                // Skip se già mappato
                if (isset($mapping[$field]) && strpos($field, 'bullet') === false) {
                    continue;
                }
                
                foreach ($patterns as $pattern) {
                    $patternLower = strtolower(trim($pattern));
                    
                    // Match ESATTO sul nome tecnico
                    if ($technical === $patternLower) {
                        // Gestisci bullet points multipli
                        if (strpos($field, 'bullet') !== false) {
                            for ($i = 1; $i <= 10; $i++) {
                                if (!isset($mapping["bullet_$i"])) {
                                    $mapping["bullet_$i"] = $columnLetter;
                                    break 2;
                                }
                            }
                        } else {
                            $mapping[$field] = $columnLetter;
                            break 2;
                        }
                    }
                }
            }
        }
        
        // FASE 2: Fuzzy match solo per colonne NON ancora mappate
        foreach ($headers as $columnLetter => $headerData) {
            $normalized = $headerData['normalized'];
            $technical = strtolower($headerData['technical'] ?? '');
            
            foreach ($this->columnPatterns as $field => $patterns) {
                // Skip se già mappato
                if (isset($mapping[$field]) && strpos($field, 'bullet') === false) {
                    continue;
                }
                
                foreach ($patterns as $pattern) {
                    $patternNormalized = $this->normalizeString($pattern);
                    
                    // Fuzzy match (solo se non già mappato esattamente)
                    if (strpos($normalized, $patternNormalized) !== false || 
                        strpos($technical, $patternNormalized) !== false ||
                        $this->levenshteinMatch($normalized, $patternNormalized)) {
                        
                        // Gestisci bullet points multipli
                        if (strpos($field, 'bullet') !== false) {
                            $bulletAssigned = false;
                            for ($i = 1; $i <= 10; $i++) {
                                if (!isset($mapping["bullet_$i"])) {
                                    $mapping["bullet_$i"] = $columnLetter;
                                    $bulletAssigned = true;
                                    break;
                                }
                            }
                            if ($bulletAssigned) {
                                break 2;
                            }
                        } else {
                            $mapping[$field] = $columnLetter;
                            break 2;
                        }
                    }
                }
            }
        }
        
        return $mapping;
    }
    
    /**
     * Normalizza stringa per confronto
     */
    private function normalizeString($str) {
        if (empty($str)) return '';
        
        $str = mb_strtolower($str, 'UTF-8');
        $str = preg_replace('/\s+/', '', $str); // Rimuovi TUTTI gli spazi
        $str = preg_replace('/[^a-z0-9]/', '', $str); // Solo alfanumerici
        $str = str_replace(['dettoanche', 'anche', 'detto'], '', $str);
        
        return trim($str);
    }
    
    /**
     * Confronto Levenshtein per similarità stringhe
     */
    private function levenshteinMatch($str1, $str2, $threshold = 3) {
        if (strlen($str1) < 5 || strlen($str2) < 5) {
            return false;
        }
        
        $distance = levenshtein($str1, $str2);
        return $distance <= $threshold;
    }
    
    /**
     * Estrai tutti i dati prodotti dalle righe 5+
     */
    public function extractProductRows($worksheet, $columnMapping, $startRow = 5) {
        $rows = [];
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        
        for ($row = $startRow; $row <= $highestRow; $row++) {
            $rowData = [];
            $isEmpty = true;
            
            // Leggi tutti i valori delle celle
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                $cellValue = $worksheet->getCell($columnLetter . $row)->getValue();
                
                // Converti RichText
                if ($cellValue instanceof RichText) {
                    $cellValue = $cellValue->getPlainText();
                }
                
                // Converti oggetti data/formula in stringhe
                if (is_object($cellValue)) {
                    $cellValue = (string)$cellValue;
                }
                
                $rowData[$columnLetter] = $cellValue;
                
                if (!empty($cellValue)) {
                    $isEmpty = false;
                }
            }
            
            // Salta righe vuote
            if ($isEmpty) {
                continue;
            }
            
            // Identifica SKU
            $sku = null;
            if (isset($columnMapping['sku'])) {
                $skuColumn = $columnMapping['sku'];
                $sku = $rowData[$skuColumn] ?? null;
            }
            
            $rows[] = [
                'row_number' => $row,
                'sku' => $sku,
                'row_data' => $rowData
            ];
        }
        
        return $rows;
    }
    
    /**
     * Match SKU con tabella products
     */
    public function matchSkuToProduct($sku) {
        if (empty($sku) || !$this->userId) {
            return null;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT `id`, `nome` 
                FROM `products` 
                WHERE `user_id` = ? AND `sku` = ? 
                LIMIT 1
            ");
            $stmt->execute([$this->userId, $sku]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $product ? $product['id'] : null;
            
        } catch (Exception $e) {
            CentralLogger::error('admin', 'Errore match SKU: ' . $e->getMessage(), [
                'user_id' => $this->userId,
                'sku' => $sku
            ]);
            return null;
        }
    }
    
    /**
     * Salva dati parsing nel database
     */
    public function saveToDatabase($filepath, $filename, $parseResult, $productRows) {
        try {
            $this->pdo->beginTransaction();
            
            // Inserisci record excel_listings
            $metadata = json_encode([
                'column_mapping' => $parseResult['column_mapping'],
                'first_data_row' => $parseResult['first_data_row'],
                'header_row_display' => $parseResult['header_row_display'],
                'header_row_technical' => $parseResult['header_row_technical']
            ], JSON_UNESCAPED_UNICODE);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO `excel_listings` 
                (`user_id`, `filename_originale`, `filepath`, `num_righe`, `num_colonne`, `metadata`, `uploaded_at`)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->userId,
                $filename,
                $filepath,
                $parseResult['num_rows'],
                $parseResult['num_columns'],
                $metadata
            ]);
            
            $excelId = $this->pdo->lastInsertId();
            
            // Inserisci righe prodotti
            $stmt = $this->pdo->prepare("
                INSERT INTO `excel_listing_rows` 
                (`excel_id`, `row_number`, `sku`, `product_id`, `row_data`, `modified`, `validation_status`)
                VALUES (?, ?, ?, ?, ?, 0, 'valid')
            ");
            
            $matchedCount = 0;
            
            foreach ($productRows as $row) {
                $productId = $this->matchSkuToProduct($row['sku']);
                
                if ($productId) {
                    $matchedCount++;
                }
                
                $rowDataJson = json_encode($row['row_data'], JSON_UNESCAPED_UNICODE);
                
                $stmt->execute([
                    $excelId,
                    $row['row_number'],
                    $row['sku'],
                    $productId,
                    $rowDataJson
                ]);
            }
            
            $this->pdo->commit();
            
            CentralLogger::info('admin', 'Dati Excel salvati in database', [
                'user_id' => $this->userId,
                'excel_id' => $excelId,
                'total_rows' => count($productRows),
                'matched_skus' => $matchedCount
            ]);
            
            return [
                'excel_id' => $excelId,
                'total_rows' => count($productRows),
                'matched_skus' => $matchedCount
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            CentralLogger::error('admin', 'Errore salvataggio database: ' . $e->getMessage(), [
                'user_id' => $this->userId
            ]);
            
            throw $e;
        }
    }
}