<?php
/**
 * Excel Listing Manager - Controller Principale
 * File: modules/margynomic/admin/creaexcel/ExcelListingManager.php
 * 
 * Gestisce:
 * - Upload file Excel
 * - Salvataggio modifiche
 * - Export file modificato
 * - Load dati per editor
 */

// ========================================
// DEBUG MODE - ATTIVATO
// ========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Avvia sessione se non attiva
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../admin_helpers.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/CentralLogger.php';
require_once __DIR__ . '/ExcelParser.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// CentralLogger is loaded without namespace

class ExcelListingManager {
    
    private $pdo;
    private $uploadsDir;
    
    /**
     * Costruttore
     */
    public function __construct() {
        $this->pdo = getDbConnection();
        $this->uploadsDir = __DIR__ . '/uploads/';
        
        // Crea directory uploads se non esiste
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }
    
    /**
     * Gestisci upload file Excel
     */
    public function handleUpload($file, $userId) {
        try {
            error_log("========== HANDLEUPLOAD START ==========");
            error_log("User ID: $userId");
            error_log("File info: " . json_encode($file));
            
            // Validazione input
            error_log("STEP 1: Validazione file upload");
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception('File non valido');
            }
            
            // Validazione dimensione (max 50MB)
            error_log("STEP 2: Validazione dimensione");
            $maxSize = 50 * 1024 * 1024;
            if ($file['size'] > $maxSize) {
                throw new Exception('File troppo grande (max 50MB)');
            }
            
            // Validazione estensione
            error_log("STEP 3: Validazione estensione");
            $allowedExtensions = ['xlsm', 'xlsx'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowedExtensions)) {
                throw new Exception('Estensione non permessa. Solo .xlsm o .xlsx');
            }
            
            // Sanitize filename
            error_log("STEP 4: Sanitize filename");
            $originalFilename = $file['name'];
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFilename);
            
            // Nome file univoco
            $timestamp = time();
            $newFilename = "{$userId}_{$timestamp}_{$safeName}";
            $destinationPath = $this->uploadsDir . $newFilename;
            
            error_log("Destination path: $destinationPath");
            error_log("Uploads dir exists: " . (is_dir($this->uploadsDir) ? 'YES' : 'NO'));
            error_log("Uploads dir writable: " . (is_writable($this->uploadsDir) ? 'YES' : 'NO'));
            
            // Sposta file
            error_log("STEP 5: Sposta file");
            if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
                $error = error_get_last();
                throw new Exception('Errore spostamento file caricato. Error: ' . json_encode($error));
            }
            
            error_log("File spostato con successo");
            
            CentralLogger::info('admin', 'File Excel caricato con successo', [
                'user_id' => $userId,
                'filename' => $originalFilename,
                'size_mb' => round($file['size'] / 1024 / 1024, 2)
            ]);
            
            // Parsa file
            error_log("STEP 6: Inizializza parser");
            $parser = new ExcelParser($userId);
            
            error_log("STEP 7: Parse file");
            $parseResult = $parser->parse($destinationPath, $userId);
            error_log("Parse result: " . json_encode([
                'num_rows' => $parseResult['num_rows'],
                'num_columns' => $parseResult['num_columns']
            ]));
            
            // Estrai righe prodotti
            error_log("STEP 8: Estrai righe prodotti");
            $productRows = $parser->extractProductRows(
                $parseResult['worksheet'],
                $parseResult['column_mapping'],
                $parseResult['first_data_row']
            );
            error_log("Righe estratte: " . count($productRows));
            
            // Salva in database
            error_log("STEP 9: Salva in database");
            $saveResult = $parser->saveToDatabase(
                $destinationPath,
                $originalFilename,
                $parseResult,
                $productRows
            );
            error_log("Save result: " . json_encode($saveResult));
            
            error_log("========== HANDLEUPLOAD SUCCESS ==========");
            
            return [
                'success' => true,
                'excel_id' => $saveResult['excel_id'],
                'filename' => $originalFilename,
                'total_rows' => $saveResult['total_rows'],
                'matched_skus' => $saveResult['matched_skus'],
                'message' => 'File caricato e parsato con successo'
            ];
            
        } catch (Exception $e) {
            error_log("========== HANDLEUPLOAD ERROR ==========");
            error_log("Exception: " . $e->getMessage());
            error_log("File: " . $e->getFile());
            error_log("Line: " . $e->getLine());
            error_log("Trace: " . $e->getTraceAsString());
            
            CentralLogger::error('admin', 'Errore upload file: ' . $e->getMessage(), [
                'user_id' => $userId,
                'filename' => $file['name'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Carica dati Excel per editor
     */
    public function loadExcelForEditor($excelId) {
        try {
            // Carica metadata excel_listings
            $stmt = $this->pdo->prepare("
                SELECT `id`, `user_id`, `filename_originale`, `filepath`, `num_righe`, `num_colonne`, `metadata`, `uploaded_at`
                FROM `excel_listings`
                WHERE `id` = ?
            ");
            $stmt->execute([$excelId]);
            $excel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$excel) {
                throw new Exception('File Excel non trovato');
            }
            
            // Decodifica metadata
            $metadata = json_decode($excel['metadata'], true);
            
            // Carica righe prodotti
            $stmt = $this->pdo->prepare("
                SELECT `id`, `row_number`, `sku`, `product_id`, `row_data`, `modified`, `validation_status`, `validation_errors`
                FROM `excel_listing_rows`
                WHERE `excel_id` = ?
                ORDER BY `row_number` ASC
            ");
            $stmt->execute([$excelId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decodifica row_data per ogni riga
            foreach ($rows as &$row) {
                $row['row_data'] = json_decode($row['row_data'], true);
                $row['validation_errors'] = $row['validation_errors'] ? json_decode($row['validation_errors'], true) : [];
            }
            
            // Carica product names per SKU mappati
            $productNames = $this->loadProductNames($excel['user_id']);
            
            // Carica prime 3 righe (intestazioni Amazon) dal file originale
            $headerRows = $this->loadAmazonHeaders($excel['filepath']);
            
            // Carica dropdown values dal file Excel
            $dropdownValues = $this->loadDropdownValues($excel['filepath'], $metadata);
            
            return [
                'success' => true,
                'excel' => $excel,
                'metadata' => $metadata,
                'rows' => $rows,
                'product_names' => $productNames,
                'amazon_headers' => $headerRows,
                'dropdown_values' => $dropdownValues
            ];
            
        } catch (Exception $e) {
            CentralLogger::error('admin', 'Errore load Excel: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Carica nomi prodotti per user
     */
    private function loadProductNames($userId) {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `nome`, `sku`
            FROM `products`
            WHERE `user_id` = ?
            ORDER BY `nome`
        ");
        $stmt->execute([$userId]);
        
        $products = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $products[$row['id']] = $row['nome'];
        }
        
        return $products;
    }
    
    /**
     * Carica le prime 3 righe (intestazioni Amazon) dal file originale
     */
    private function loadAmazonHeaders($filepath) {
        try {
            if (!file_exists($filepath)) {
                return [];
            }
            
            // SOPPRIMI TUTTI GLI ERRORI PHPSPREADSHEET
            $oldErrorReporting = error_reporting();
            error_reporting(E_ERROR | E_PARSE);
            
            $spreadsheet = IOFactory::load($filepath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            error_reporting($oldErrorReporting);
            
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
            
            $headerRows = [];
            
            // Leggi righe 1, 2, 3
            for ($row = 1; $row <= 3; $row++) {
                $rowData = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $cell = $worksheet->getCell($columnLetter . $row);
                    
                    // Converti in stringa (gestisce RichText e altri oggetti)
                    $cellValue = $cell->getValue();
                    if (is_object($cellValue)) {
                        // Se è un oggetto RichText o simile, converti in stringa
                        $cellValue = (string)$cellValue;
                    }
                    
                    $rowData[] = $cellValue;
                }
                $headerRows[] = $rowData;
            }
            
            return $headerRows;
            
        } catch (Exception $e) {
            error_log("Errore caricamento header Amazon: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Carica dropdown values dalle celle Excel
     */
    private function loadDropdownValues($filepath, $metadata) {
        try {
            if (!file_exists($filepath)) {
                return [];
            }
            
            // SOPPRIMI TUTTI I WARNING DI PHPSPREADSHEET (incluso deprecated)
            $oldErrorReporting = error_reporting();
            error_reporting(E_ERROR | E_PARSE); // Solo errori fatali
            
            $spreadsheet = IOFactory::load($filepath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Prepara headers array per DropdownExtractor
            // Formato: ['A' => ['original' => 'Header', 'normalized' => 'header'], ...]
            $headersForExtractor = [];
            
            // Leggi header dalla riga 4
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
            
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                
                // Riga 3: nome tecnico (per Named Ranges)
                $technicalCell = $worksheet->getCell($columnLetter . '3');
                $technicalValue = $technicalCell->getValue();
                if (is_object($technicalValue)) {
                    $technicalValue = (string)$technicalValue;
                }
                
                // Riga 4: nome visualizzato
                $displayCell = $worksheet->getCell($columnLetter . '4');
                $displayValue = $displayCell->getValue();
                if (is_object($displayValue)) {
                    $displayValue = (string)$displayValue;
                }
                
                $headersForExtractor[$columnLetter] = [
                    'original' => $displayValue ?? '',
                    'normalized' => strtolower(trim($technicalValue ?? ''))
                ];
            }
            
            require_once __DIR__ . '/DropdownExtractor.php';
            
            $extractor = new DropdownExtractor();
            $dropdowns = $extractor->extractDropdowns($worksheet, $headersForExtractor);
            
            // Mappa dropdown per colonna (usa column letters)
            // Estrai solo i valori dall'array
            $dropdownMap = [];
            foreach ($dropdowns as $columnLetter => $data) {
                if (!empty($data['values'])) {
                    $dropdownMap[$columnLetter] = $data['values'];
                }
            }
            
            // Ripristina error_reporting
            error_reporting($oldErrorReporting);
            
            return $dropdownMap;
            
        } catch (Exception $e) {
            error_log("Errore caricamento dropdown: " . $e->getMessage());
            // Ripristina error_reporting anche in caso di errore
            if (isset($oldErrorReporting)) {
                error_reporting($oldErrorReporting);
            }
            return [];
        }
    }
    
    /**
     * Salva modifiche da editor
     */
    public function saveChanges($excelId, $changes) {
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                UPDATE `excel_listing_rows`
                SET `row_data` = ?, `modified` = 1, `validation_status` = ?
                WHERE `id` = ?
            ");
            
            $savedCount = 0;
            
            foreach ($changes as $change) {
                $rowId = $change['row_id'];
                $rowData = json_encode($change['row_data'], JSON_UNESCAPED_UNICODE);
                $validationStatus = $change['validation_status'] ?? 'valid';
                
                $stmt->execute([$rowData, $validationStatus, $rowId]);
                $savedCount++;
            }
            
            // Update last_modified timestamp
            $stmt = $this->pdo->prepare("
                UPDATE `excel_listings`
                SET `last_modified` = NOW()
                WHERE `id` = ?
            ");
            $stmt->execute([$excelId]);
            
            $this->pdo->commit();
            
            CentralLogger::info('admin', 'Modifiche Excel salvate', [
                'excel_id' => $excelId,
                'changes_count' => $savedCount
            ]);
            
            return [
                'success' => true,
                'saved_count' => $savedCount
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            CentralLogger::error('admin', 'Errore salvataggio modifiche: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Export file Excel modificato
     */
    public function exportExcel($excelId) {
        try {
            // Carica dati Excel
            $data = $this->loadExcelForEditor($excelId);
            
            if (!$data['success']) {
                throw new Exception('Impossibile caricare dati Excel');
            }
            
            $excel = $data['excel'];
            $metadata = $data['metadata'];
            $rows = $data['rows'];
            
            // Carica file originale
            if (!file_exists($excel['filepath'])) {
                throw new Exception('File originale non trovato');
            }
            
            $spreadsheet = IOFactory::load($excel['filepath']);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // NON TOCCARE RIGHE 1-3 (metadati Amazon)
            // Aggiorna solo righe dati (5+)
            
            foreach ($rows as $row) {
                $rowNumber = $row['row_number'];
                $rowData = $row['row_data'];
                
                // Scrivi valori celle
                foreach ($rowData as $columnLetter => $value) {
                    $worksheet->setCellValue($columnLetter . $rowNumber, $value);
                }
            }
            
            // Salva file sovrascrivendo originale
            $writer = new Xlsx($spreadsheet);
            $writer->save($excel['filepath']);
            
            CentralLogger::info('admin', 'File Excel esportato', [
                'excel_id' => $excelId,
                'filename' => $excel['filename_originale']
            ]);
            
            return [
                'success' => true,
                'filepath' => $excel['filepath'],
                'filename' => $excel['filename_originale']
            ];
            
        } catch (Exception $e) {
            CentralLogger::error('admin', 'Errore export Excel: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Download handler
     */
    public function downloadFile($excelId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT `filename_originale`, `filepath`
                FROM `excel_listings`
                WHERE `id` = ?
            ");
            $stmt->execute([$excelId]);
            $excel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$excel || !file_exists($excel['filepath'])) {
                throw new Exception('File non trovato');
            }
            
            // Headers download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $excel['filename_originale'] . '"');
            header('Content-Length: ' . filesize($excel['filepath']));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            
            readfile($excel['filepath']);
            exit;
            
        } catch (Exception $e) {
            CentralLogger::error('admin', 'Errore download file: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            die('Errore download file: ' . $e->getMessage());
        }
    }
    
    /**
     * Ottieni lista file Excel caricati
     */
    public function getExcelList($userId = null) {
        try {
            $sql = "
                SELECT el.*, u.nome as user_name
                FROM `excel_listings` el
                LEFT JOIN `users` u ON el.`user_id` = u.`id`
            ";
            
            if ($userId) {
                $sql .= " WHERE el.`user_id` = ?";
            }
            
            $sql .= " ORDER BY el.`uploaded_at` DESC LIMIT 50";
            
            $stmt = $this->pdo->prepare($sql);
            
            if ($userId) {
                $stmt->execute([$userId]);
            } else {
                $stmt->execute();
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            CentralLogger::error('admin', 'Errore lista Excel: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Elimina file Excel
     */
    public function deleteExcel($excelId) {
        try {
            $this->pdo->beginTransaction();
            
            // Ottieni filepath
            $stmt = $this->pdo->prepare("SELECT `filepath` FROM `excel_listings` WHERE `id` = ?");
            $stmt->execute([$excelId]);
            $filepath = $stmt->fetchColumn();
            
            // Elimina file fisico
            if ($filepath && file_exists($filepath)) {
                unlink($filepath);
            }
            
            // Elimina da database (cascade elimina anche rows)
            $stmt = $this->pdo->prepare("DELETE FROM `excel_listings` WHERE `id` = ?");
            $stmt->execute([$excelId]);
            
            $this->pdo->commit();
            
            CentralLogger::info('admin', 'File Excel eliminato', [
                'excel_id' => $excelId
            ]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            CentralLogger::error('admin', 'Errore eliminazione Excel: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
?>

