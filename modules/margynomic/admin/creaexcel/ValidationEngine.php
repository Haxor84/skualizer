<?php
/**
 * Validation Engine - Amazon Policy Rules
 * File: modules/margynomic/admin/creaexcel/ValidationEngine.php
 * 
 * MVP Rules:
 * - Title: min 120, max 200 chars
 * - Title: max 2 keyword repetitions
 * - Description: max 1000 chars
 * - Forbidden words
 */

// ========================================
// DEBUG MODE - ATTIVATO
// ========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/CentralLogger.php';

// CentralLogger is loaded without namespace

class ValidationEngine {
    
    private $pdo;
    
    /**
     * Parole vietate Amazon (MVP)
     */
    private $forbiddenWords = [
        'best', 'top', '#1', 'numero 1', 'migliore',
        'guaranteed', 'garanzia', 'garantito',
        'free shipping', 'spedizione gratis', 'spedizione gratuita',
        'top rated', 'best seller'
    ];
    
    /**
     * Costruttore
     */
    public function __construct() {
        $this->pdo = getDbConnection();
    }
    
    /**
     * Valida intero file Excel
     */
    public function validateExcel($excelId) {
        try {
            // Carica metadata per column mapping
            $stmt = $this->pdo->prepare("
                SELECT `user_id`, `metadata`
                FROM `excel_listings`
                WHERE `id` = ?
            ");
            $stmt->execute([$excelId]);
            $excel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$excel) {
                throw new Exception('Excel non trovato');
            }
            
            $metadata = json_decode($excel['metadata'], true);
            $columnMapping = $metadata['column_mapping'] ?? [];
            
            // Carica tutte le righe
            $stmt = $this->pdo->prepare("
                SELECT `id`, `row_data`
                FROM `excel_listing_rows`
                WHERE `excel_id` = ?
            ");
            $stmt->execute([$excelId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $validCount = 0;
            $warningCount = 0;
            $errorCount = 0;
            
            $this->pdo->beginTransaction();
            
            $updateStmt = $this->pdo->prepare("
                UPDATE `excel_listing_rows`
                SET `validation_status` = ?, `validation_errors` = ?
                WHERE `id` = ?
            ");
            
            foreach ($rows as $row) {
                $rowData = json_decode($row['row_data'], true);
                
                // Valida riga
                $errors = $this->validateRow($rowData, $columnMapping);
                
                // Determina status
                if (empty($errors)) {
                    $status = 'valid';
                    $validCount++;
                } elseif ($this->hasOnlyWarnings($errors)) {
                    $status = 'warning';
                    $warningCount++;
                } else {
                    $status = 'error';
                    $errorCount++;
                }
                
                $errorsJson = json_encode($errors, JSON_UNESCAPED_UNICODE);
                $updateStmt->execute([$status, $errorsJson, $row['id']]);
            }
            
            $this->pdo->commit();
            
            CentralLogger::info('admin', 'Validazione Excel completata', [
                'excel_id' => $excelId,
                'valid' => $validCount,
                'warnings' => $warningCount,
                'errors' => $errorCount
            ]);
            
            return [
                'success' => true,
                'valid_count' => $validCount,
                'warning_count' => $warningCount,
                'error_count' => $errorCount
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            CentralLogger::error('admin', 'Errore validazione Excel: ' . $e->getMessage(), [
                'excel_id' => $excelId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Valida singola riga prodotto
     */
    private function validateRow($rowData, $columnMapping) {
        $errors = [];
        
        // Identifica colonne
        $titleCol = $columnMapping['title'] ?? null;
        $descCol = $columnMapping['description'] ?? null;
        
        // Valida Title
        if ($titleCol && isset($rowData[$titleCol])) {
            $title = $rowData[$titleCol];
            $titleErrors = $this->validateTitle($title);
            $errors = array_merge($errors, $titleErrors);
        }
        
        // Valida Description
        if ($descCol && isset($rowData[$descCol])) {
            $description = $rowData[$descCol];
            $descErrors = $this->validateDescription($description);
            $errors = array_merge($errors, $descErrors);
        }
        
        // Valida Forbidden Words (su tutti i campi testo)
        foreach ($rowData as $col => $value) {
            if (is_string($value)) {
                $forbiddenErrors = $this->validateForbiddenWords($value, $col);
                $errors = array_merge($errors, $forbiddenErrors);
            }
        }
        
        return $errors;
    }
    
    /**
     * Valida Title
     */
    private function validateTitle($title) {
        $errors = [];
        
        if (empty($title)) {
            return $errors;
        }
        
        $length = mb_strlen($title);
        
        // Min 120 caratteri (warning)
        if ($length < 120) {
            $errors[] = [
                'field' => 'title',
                'type' => 'warning',
                'message' => "Titolo troppo corto: {$length}/120 caratteri (consigliato minimo 120)"
            ];
        }
        
        // Max 200 caratteri (error)
        if ($length > 200) {
            $errors[] = [
                'field' => 'title',
                'type' => 'error',
                'message' => "Titolo troppo lungo: {$length}/200 caratteri (massimo 200)"
            ];
        }
        
        // Keyword ridondanti (max 2 ripetizioni)
        $words = preg_split('/\s+/', mb_strtolower($title));
        $frequency = array_count_values($words);
        
        foreach ($frequency as $word => $count) {
            if (mb_strlen($word) > 3 && $count > 2) {
                $errors[] = [
                    'field' => 'title',
                    'type' => 'warning',
                    'message' => "Keyword '{$word}' ripetuta {$count} volte (max consigliato: 2)"
                ];
            }
        }
        
        return $errors;
    }
    
    /**
     * Valida Description
     */
    private function validateDescription($description) {
        $errors = [];
        
        if (empty($description)) {
            return $errors;
        }
        
        // Rimuovi tag HTML per contare caratteri
        $plainText = strip_tags($description);
        $length = mb_strlen($plainText);
        
        // Max 1000 caratteri (error)
        if ($length > 1000) {
            $errors[] = [
                'field' => 'description',
                'type' => 'error',
                'message' => "Descrizione troppo lunga: {$length}/1000 caratteri (massimo 1000)"
            ];
        }
        
        return $errors;
    }
    
    /**
     * Valida Forbidden Words
     */
    private function validateForbiddenWords($text, $field) {
        $errors = [];
        
        if (empty($text)) {
            return $errors;
        }
        
        $textLower = mb_strtolower($text);
        
        foreach ($this->forbiddenWords as $forbidden) {
            if (strpos($textLower, mb_strtolower($forbidden)) !== false) {
                $errors[] = [
                    'field' => $field,
                    'type' => 'error',
                    'message' => "Parola vietata trovata: '{$forbidden}'"
                ];
            }
        }
        
        return $errors;
    }
    
    /**
     * Controlla se ci sono solo warning (no error)
     */
    private function hasOnlyWarnings($errors) {
        foreach ($errors as $error) {
            if ($error['type'] === 'error') {
                return false;
            }
        }
        return !empty($errors);
    }
}
?>

