<?php
/**
 * Dropdown Extractor - Named Ranges Amazon (VERSIONE FINALE)
 * File: modules/margynomic/admin/creaexcel/DropdownExtractor.php
 * 
 * Usa Named Ranges Amazon + Fallback diretto foglio "Dropdown Lists"
 * Codice base da ChatGPT, ottimizzato per Margynomic
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DropdownExtractor {
    
    /**
     * Pattern colonne da escludere (non devono avere dropdown)
     * SOLO codici numerici univoci + condition_type (richiesta utente)
     */
    private $excludedColumnPatterns = [
        // Codici numerici univoci (EAN, UPC, ASIN, ecc.)
        'ean', 'upc', 'gtin', 'asin', 'isbn', 'gcid',
        
        // Condition Type (escluso per richiesta esplicita utente)
        'condition_type'
    ];
    
    /**
     * Fallback mapping per campi noti (se Named Ranges non funzionano)
     * Basato su analisi file 01-IT-pasta.pistacchio-04.xlsm
     */
    private $fallbackMap = [
        // Identificazione prodotto
        'external_product_id_type' => ['Dropdown Lists', 'E4:E9'],
        
        // Paese origine
        'country_of_origin' => ['Dropdown Lists', 'D4:D271'],
        
        // Date/scadenza
        'is_expiration_dated_product' => ['Dropdown Lists', 'C4:C5'],
        
        // Parent/Child
        'parent_child' => ['Dropdown Lists', 'B4:B5'],
        
        // Update/Delete
        'update_delete' => ['Dropdown Lists', 'M4:M6'],
        
        // Unità di misura - Dimensioni
        'item_width_unit_of_measure' => ['Dropdown Lists', 'B4:B18'],
        'item_height_unit_of_measure' => ['Dropdown Lists', 'AB4:AB18'],
        'item_length_unit_of_measure' => ['Dropdown Lists', 'AM4:AM18'],
        'package_height_unit_of_measure' => ['Dropdown Lists', 'Y4:Y18'],
        'package_length_unit_of_measure' => ['Dropdown Lists', 'V4:V18'],
        'package_width_unit_of_measure' => ['Dropdown Lists', 'AL4:AL18'],
        
        // Unità di misura - Peso
        'package_weight_unit_of_measure' => ['Dropdown Lists', 'L4:L10'],
        'item_display_weight_unit_of_measure' => ['Dropdown Lists', 'AJ4:AJ10'],
        
        // Unità di misura - Volume
        'liquid_volume_unit_of_measure' => ['Dropdown Lists', 'AK4:AK21'],
        
        // Batterie
        'batteries_required' => ['Dropdown Lists', 'K4:K5'],
        'are_batteries_included' => ['Dropdown Lists', 'AT4:AT5'],
        
        // Lingua
        'language_value' => ['Dropdown Lists', 'AF4:AF543'],
        
        // Colore/Dimensione
        'color_map' => ['Dropdown Lists', 'N4:N24'],
        'size_map' => ['Dropdown Lists', 'P4:P10'],
        
        // Liquidi
        'contains_liquid_contents' => ['Dropdown Lists', 'G4:G5'],
        'is_liquid_double_sealed' => ['Dropdown Lists', 'AG4:AG5'],
        'is_heat_sensitive' => ['Dropdown Lists', 'AN4:AN5'],
        
        // Altri
        'cuisine' => ['Dropdown Lists', 'AO4:AO42'],
        'diet_type' => ['Dropdown Lists', 'W4:W9'],
        'allergen_information' => ['Dropdown Lists', 'X4:X140'],
    ];
    
    /**
     * Estrai dropdown UNIVERSALE - legge Data Validation direttamente da Excel
     */
    public function extractDropdowns(Worksheet $mainWorksheet, $headers) {
        // SOPPRIMI TUTTI I WARNING DI PHPSPREADSHEET
        $oldErrorReporting = error_reporting();
        error_reporting(E_ERROR | E_PARSE); // Solo errori fatali
        
        $dropdowns = [];
        $spreadsheet = $mainWorksheet->getParent();
        
        // Riga dati di riferimento per Data Validation (prima riga dati)
        $dataRow = 5;
        
        $processedCount = 0;
        
        // Per ogni colonna, leggi Data Validation direttamente
        foreach ($headers as $columnLetter => $headerData) {
            $headerNormalized = $headerData['normalized'] ?? '';
            $headerOriginal = $headerData['original'] ?? '';
            
            // SKIP colonne specifiche che NON dovrebbero avere dropdown
            if ($this->isExcludedColumn($headerNormalized)) {
                continue;
            }
            
            // Leggi Data Validation dalla cella
            $cell = $mainWorksheet->getCell($columnLetter . $dataRow);
            $validation = $cell->getDataValidation();
            
            // Se non ha validation TYPE_LIST, skip
            if (!$validation || $validation->getType() != \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST) {
                continue;
            }
            
            // Estrai valori dalla validazione (ora supporta INDIRECT!)
            $values = $this->extractValidationValues($validation, $spreadsheet, $mainWorksheet);
            $source = 'data_validation';
            
            // Fallback: se validazione non ha estratto valori E esiste mapping hardcoded
            if (empty($values) && isset($this->fallbackMap[$headerNormalized])) {
                list($sheetName, $range) = $this->fallbackMap[$headerNormalized];
                try {
                    $ws = $spreadsheet->getSheetByName($sheetName);
                    if (!$ws) {
                        // Prova case-insensitive
                        foreach ($spreadsheet->getAllSheets() as $sheet) {
                            if (strtolower($sheet->getTitle()) === strtolower($sheetName)) {
                                $ws = $sheet;
                                break;
                            }
                        }
                    }
                    if ($ws) {
                        $values = $this->rangeToValues($ws, $range);
                        $source = 'fallback_map';
                    }
                } catch (Exception $e) {
                    // Ignora errori
                }
            }
            
            if (empty($values)) {
                continue;
            }
            
            // Verifica se è colonna numerica (EAN/barcode)
            if ($this->isNumericColumn($mainWorksheet, $columnLetter, $dataRow)) {
                continue;
            }
            
            $dropdowns[$columnLetter] = [
                'header' => $headerOriginal,
                'values' => $values,
                'source' => $source
            ];
            
            $processedCount++;
        }
        
        // Ripristina error_reporting
        error_reporting($oldErrorReporting);
        
        return $dropdowns;
    }
    
    /**
     * Estrai Product Type da cella A5
     */
    private function getProductType(Worksheet $worksheet) {
        $spreadsheet = $worksheet->getParent();
        
        // Prova 1: Foglio "Modello" (template IT Amazon)
        try {
            $modelSheet = $spreadsheet->getSheetByName('Modello');
            if ($modelSheet) {
                $value = $modelSheet->getCell('A5')->getValue();
                $value = $this->richToString($value);
                $value = trim($value);
                
                if ($value !== '' && 
                    strtolower($value) !== 'tipo di prodotto' &&
                    strtolower($value) !== 'feed_product_type') {
                    return $value;
                }
            }
        } catch (Exception $e) {
            // Ignora errori se il foglio Modello non esiste
        }
        
        // Prova 2: Foglio principale (fallback)
        $candidates = [5, 4]; // Prova A5 poi A4
        
        foreach ($candidates as $row) {
            $value = $worksheet->getCell('A' . $row)->getValue();
            $value = $this->richToString($value);
            $value = trim($value);
            
            if ($value !== '' && 
                strtolower($value) !== 'tipo di prodotto' &&
                strtolower($value) !== 'feed_product_type') {
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Costruisci nome Named Range secondo pattern Amazon
     */
    private function buildNamedRangeName($productType, $fieldName) {
        $pt = $this->normalizeAmazonProductType($productType);
        return $pt . $fieldName;
    }
    
    /**
     * Normalizza product type secondo regole Amazon
     */
    private function normalizeAmazonProductType($productType) {
        if ($productType === null || $productType === '') {
            return '';
        }
        $pt = trim($productType);
        if ($pt === '') return $pt;
        
        $pt = str_replace(['-', ' '], ['_', ''], $pt);
        
        if (preg_match('/^\d/', $pt)) {
            $pt = '_' . $pt;
        }
        
        return $pt;
    }
    
    /**
     * Estrai dropdown per campo specifico (Named Range + Fallback)
     */
    private function getDropdownForField(Spreadsheet $spreadsheet, $productType, $fieldName) {
        $pt = $this->normalizeAmazonProductType($productType);
        $namedRangeName = $pt . $fieldName;
        
        // Metodo 1: Prova Named Range
        $values = $this->resolveNamedRange($spreadsheet, $namedRangeName);
        
        if (!empty($values)) {
            return $values;
        }
        
        // Metodo 2: Fallback diretto
        if (isset($this->fallbackMap[$fieldName])) {
            list($sheetName, $range) = $this->fallbackMap[$fieldName];
            
            try {
                $ws = $spreadsheet->getSheetByName($sheetName);
                if ($ws) {
                    $values = $this->rangeToValues($ws, $range);
                    if (!empty($values)) {
                        return $values;
                    }
                }
            } catch (Exception $e) {
                // Ignora errori fallback
            }
        }
        
        return [];
    }
    
    /**
     * Risolvi Named Range in array valori (DISABILITATO - USA SOLO FALLBACK)
     */
    private function resolveNamedRange(Spreadsheet $spreadsheet, $namedRangeName) {
        // NON USARE Named Ranges - causano loop infinito in PhpSpreadsheet
        return [];
    }
    
    /**
     * Leggi valori da range CELLA PER CELLA (no rangeToArray - causa loop!)
     */
    private function rangeToValues(Worksheet $ws, $range) {
        $values = [];
        
        try {
            // Parse range tipo "D4:D271"
            if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches)) {
                return [];
            }
            
            $startCol = $matches[1];
            $startRow = (int)$matches[2];
            $endCol = $matches[3];
            $endRow = (int)$matches[4];
            
            // Limite sicurezza
            $maxRows = 1000;
            if ($endRow - $startRow > $maxRows) {
                $endRow = $startRow + $maxRows;
            }
            
            // Leggi cella per cella (colonna singola)
            if ($startCol === $endCol) {
                for ($row = $startRow; $row <= $endRow; $row++) {
                    $cell = $ws->getCell($startCol . $row);
                    $value = $cell->getValue();
                    
                    // Converti oggetti in stringa
                    if (is_object($value)) {
                        $value = (string)$value;
                    }
                    
                    // Fix: handle null values
                    if ($value === null) {
                        $value = '';
                    }
                    
                    $value = trim($value);
                    
                    // Stop alla prima cella vuota
                    if ($value === '') {
                        break;
                    }
                    
                    $values[] = $value;
                }
            }
            
            return $values;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Fallback: estrai dropdown senza Product Type
     */
    private function extractWithFallback(Spreadsheet $spreadsheet, $headers) {
        $dropdowns = [];
        
        foreach ($headers as $columnLetter => $headerData) {
            $technicalName = $headerData['technical'] ?? '';
            
            if (isset($this->fallbackMap[$technicalName])) {
                list($sheetName, $range) = $this->fallbackMap[$technicalName];
                
                try {
                    $ws = $spreadsheet->getSheetByName($sheetName);
                    if ($ws) {
                        $values = $this->rangeToValues($ws, $range);
                        if (!empty($values)) {
                            $dropdowns[$columnLetter] = [
                                'header' => $headerData['original'],
                                'values' => $values,
                                'source' => 'fallback'
                            ];
                        }
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        
        return $dropdowns;
    }
    
    /**
     * Estrai valori da Data Validation
     */
    private function extractValidationValues($validation, Spreadsheet $spreadsheet, $currentWorksheet = null) {
        $formula = $validation->getFormula1();
        
        if (empty($formula)) {
            return [];
        }
        
        // DEBUG: Log every call
        error_log("DropdownExtractor::extractValidationValues: formula=" . substr($formula, 0, 100));
        error_log("DropdownExtractor::extractValidationValues: currentWorksheet=" . ($currentWorksheet ? $currentWorksheet->getTitle() : 'NULL'));
        
        // CASO SPECIALE: INDIRECT (usato da Amazon per Named Ranges dinamici)
        // Esempio: INDIRECT(IF(...)&"field_name") costruisce "groceryfield_name" dinamicamente
        if (stripos($formula, 'INDIRECT') !== false) {
            error_log("DropdownExtractor: INDIRECT detected in formula!");
            // Prima: prova a estrarre il suffix del field name dalla formula
            // Pattern tipico Amazon: INDIRECT(IF(...)&"field_name")
            $fieldSuffix = null;
            if (preg_match('/&\s*"([^"]+)"\s*\)/i', $formula, $matches)) {
                $fieldSuffix = $matches[1]; // Es: "country_of_origin"
            }
            
            // Se abbiamo il suffix, cerchiamo Named Range con pattern "{prefix}{suffix}"
            if ($fieldSuffix) {
                // Leggi il feed_product_type da cella A del current worksheet (riga 4 o 5)
                $feedProductType = null;
                if ($currentWorksheet) {
                    try {
                        $cellA4 = $currentWorksheet->getCell('A4')->getValue();
                        if ($cellA4 && !empty(trim((string)$cellA4))) {
                            $feedProductType = strtolower(trim((string)$cellA4));
                            // Pulisci (rimuovi spazi, trattini)
                            $feedProductType = str_replace(['-', ' '], '', $feedProductType);
                            
                            // DEBUG LOG
                            error_log("DropdownExtractor: Found feed_product_type='$feedProductType', suffix='$fieldSuffix'");
                        }
                    } catch (Exception $e) {
                        error_log("DropdownExtractor: Failed to read A4 - " . $e->getMessage());
                    }
                } else {
                    error_log("DropdownExtractor: currentWorksheet is NULL!");
                }
                
                // Costruisci il nome del Named Range: {feedProductType}{fieldSuffix}
                if ($feedProductType) {
                    $namedRangeName = $feedProductType . $fieldSuffix;
                    
                    error_log("DropdownExtractor: Looking for Named Range: '$namedRangeName'");
                    
                    try {
                        $namedRanges = $spreadsheet->getNamedRanges();
                        foreach ($namedRanges as $namedRange) {
                            if (strtolower($namedRange->getName()) === strtolower($namedRangeName)) {
                                $worksheet = $namedRange->getWorksheet();
                                $rangeValue = $namedRange->getValue();
                                // Rimuovi nome foglio se presente (es: 'Dropdown Lists'!$A$1:$A$10 -> $A$1:$A$10)
                                if (preg_match("/^'?[^'!]+'?!(.+)$/", $rangeValue, $rangeMatches)) {
                                    $rangeValue = $rangeMatches[1];
                                }
                                $rangeValue = str_replace('$', '', $rangeValue);
                                
                                error_log("DropdownExtractor: FOUND Named Range '$namedRangeName' -> $rangeValue");
                                
                                $values = $this->rangeToValues($worksheet, $rangeValue);
                                error_log("DropdownExtractor: Extracted " . count($values) . " values");
                                
                                return $values;
                            }
                        }
                        
                        error_log("DropdownExtractor: Named Range '$namedRangeName' NOT FOUND");
                    } catch (Exception $e) {
                        error_log("DropdownExtractor: Exception - " . $e->getMessage());
                    }
                }
            }
            
            // Fallback: prova pattern semplice INDIRECT("NamedRangeName")
            if (preg_match('/INDIRECT\s*\(\s*"([^"]+)"\s*\)/i', $formula, $matches)) {
                $target = $matches[1];
                
                // Prova 1: È un Named Range?
                try {
                    $namedRanges = $spreadsheet->getNamedRanges();
                    foreach ($namedRanges as $namedRange) {
                        if ($namedRange->getName() === $target) {
                            $worksheet = $namedRange->getWorksheet();
                            $rangeValue = $namedRange->getValue();
                            if (preg_match("/^'?[^'!]+'?!(.+)$/", $rangeValue, $rangeMatches)) {
                                $rangeValue = $rangeMatches[1];
                            }
                            $rangeValue = str_replace('$', '', $rangeValue);
                            return $this->rangeToValues($worksheet, $rangeValue);
                        }
                    }
                } catch (Exception $e) {
                    // Continue
                }
                
                // Prova 2: È un riferimento diretto a foglio?
                if (preg_match("/^'?([^'!]+)'?!(.+)$/", $target, $sheetMatches)) {
                    $sheetName = $sheetMatches[1];
                    $range = str_replace('$', '', $sheetMatches[2]);
                    try {
                        $ws = $spreadsheet->getSheetByName($sheetName);
                        if (!$ws) {
                            foreach ($spreadsheet->getAllSheets() as $sheet) {
                                if (strtolower($sheet->getTitle()) === strtolower($sheetName)) {
                                    $ws = $sheet;
                                    break;
                                }
                            }
                        }
                        if ($ws) {
                            return $this->rangeToValues($ws, $range);
                        }
                    } catch (Exception $e) {
                        // Continue
                    }
                }
            }
            
            // Se INDIRECT non è stato risolto, return vuoto (sarà usato fallback)
            return [];
        }
        
        // Ignora altre formule complesse (IF, OFFSET, INDEX, ecc.)
        if (stripos($formula, 'IF(') !== false || 
            stripos($formula, 'OFFSET') !== false ||
            stripos($formula, 'INDEX') !== false ||
            preg_match('/[A-Z]+\(/', $formula)) { // Altre funzioni Excel
            return [];
        }
        
        // Caso 1: Range/Named Range (es: 'Sheet'!$A$1:$A$10)
        if (preg_match("/^'?([^'!]+)'?!(.+)$/", $formula, $matches)) {
            $sheetName = $matches[1];
            $range = $matches[2];
            
            // SKIP se range contiene formule
            if (preg_match('/[A-Z]+\(/', $range)) {
                return [];
            }
            
            try {
                $ws = $spreadsheet->getSheetByName($sheetName);
                if ($ws) {
                    return $this->rangeToValues($ws, $range);
                }
            } catch (Exception $e) {
                return [];
            }
        }
        
        // Caso 2: Named Range semplice (senza foglio)
        if (preg_match('/^\$?[A-Z]+\$?\d+:\$?[A-Z]+\$?\d+$/', $formula)) {
            try {
                return $this->rangeToValues($spreadsheet->getActiveSheet(), $formula);
            } catch (Exception $e) {
                return [];
            }
        }
        
        // Caso 3: Lista diretta (es: "Sì,No" o '"Option1","Option2"')
        // SOLO se NON contiene parentesi (non è una formula)
        if (strpos($formula, '(') === false && strpos($formula, ',') !== false) {
            $values = array_map('trim', explode(',', $formula));
            // Rimuovi virgolette
            $values = array_map(function($v) { return trim($v, '"'); }, $values);
            return array_filter($values);
        }
        
        return [];
    }
    
    /**
     * Verifica se colonna è nella blacklist
     */
    private function isExcludedColumn($headerNormalized) {
        foreach ($this->excludedColumnPatterns as $pattern) {
            if (strpos($headerNormalized, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Verifica se colonna contiene valori numerici lunghi (EAN/barcode)
     */
    private function isNumericColumn($worksheet, $columnLetter, $startRow) {
        // Campiona 3 celle per verificare se contengono numeri lunghi
        $numericCount = 0;
        
        for ($row = $startRow; $row <= $startRow + 2; $row++) {
            $value = $worksheet->getCell($columnLetter . $row)->getValue();
            
            // Se valore è numerico E lungo > 6 cifre (probabile EAN/UPC)
            if (is_numeric($value) && strlen((string)$value) >= 6) {
                $numericCount++;
            }
        }
        
        // Se 2+ celle su 3 hanno numeri lunghi = è colonna numerica
        return $numericCount >= 2;
    }
    
    /**
     * Converti RichText in stringa
     */
    private function richToString($value) {
        if ($value === null) {
            return '';
        }
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        return (string)$value;
    }
}