<?php

require_once 'RendicontoModel.php';
require_once 'CurrencyConverter.php';
require_once '../margynomic/config/CentralLogger.php';

class RendicontoController {
    private $model;
    private $userId;
    private $currencyConverter;
    private $pdo;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->model = new RendicontoModel($pdo);
        $this->userId = $userId;
        $this->currencyConverter = new CurrencyConverter($pdo);
    }
    
    /**
     * Load document by ID
     */
    public function loadDocument($id) {
        return $this->model->getDocumentoCompleto($id);
    }
    
    /**
     * Load document by year with aggregated input utente
     */
    public function loadDocumentByYear($anno, $brand = 'PROFUMI YESENSY') {
        $documento = $this->model->getDocumentoByAnno($this->userId, $anno, $brand);
        
        if (!$documento) {
            return null;
        }
        
        // Load aggregated input utente summary
        $inputSummary = $this->model->getInputUtenteSummary($this->userId, $anno);
        
        // Merge input utente data into righe
        foreach ($documento['righe'] as $mese => &$riga) {
            if (isset($inputSummary[$mese])) {
                $summary = $inputSummary[$mese];
                
                // Add aggregated values from input utente
                $riga['varie_euro'] = (float) ($summary['varie_euro'] ?? 0);
                $riga['tasse_euro'] += (float) ($summary['tasse_euro'] ?? 0); // Add to existing
                $riga['materia1_euro'] += (float) ($summary['materia1_euro'] ?? 0);
                $riga['materia1_unita'] += (int) ($summary['materia1_unita'] ?? 0);
                $riga['sped_euro'] += (float) ($summary['sped_euro'] ?? 0);
                $riga['sped_unita'] += (int) ($summary['sped_unita'] ?? 0);
            }
        }
        unset($riga); // Break reference
        
        // Load KPI totali anno (unita acquistate/spedite)
        $kpiTotali = $this->model->getKpiTotaliAnno($this->userId, $anno);
        $documento['kpi_totali'] = [
            'unita_acquistate' => (int) ($kpiTotali['tot_unita_acquistate'] ?? 0),
            'unita_spedite' => (int) ($kpiTotali['tot_unita_spedite'] ?? 0)
        ];
        
        return $documento;
    }
    
    /**
     * Save document with validation and calculations
     */
    public function saveDocument($payload) {
        // Log operazione
        CentralLogger::log('rendiconto', 'INFO', 'Inizio salvataggio documento', [
            'user_id' => $this->userId,
            'anno' => $payload['anno'] ?? 'unknown'
        ]);
        
        // Validate input
        $validation = $this->validate($payload);
        if (!$validation['valid']) {
            CentralLogger::log('rendiconto', 'WARNING', 'Validazione fallita', [
                'user_id' => $this->userId,
                'errors' => $validation['errors']
            ]);
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        try {
            // Get or create document
            $documentoId = $this->model->upsertDocumento(
                $this->userId,
                $payload['anno'],
                $payload['brand'] ?? 'PROFUMI YESENSY',
                $payload['valuta'] ?? 'EUR'
            );
            
            // Process each row with calculations
            $righeProcessed = [];
            foreach ($payload['righe'] as $riga) {
                $righeProcessed[] = $this->processRiga($riga);
            }
            
            // Save all righe
            $this->model->upsertRigheBatch($documentoId, $righeProcessed);
            
            // Return complete document with calculations
            $documento = $this->model->getDocumentoCompleto($documentoId);
            $totali = $this->computeYearTotals($documento['righe']);
            $kpi = $this->computeKpi($totali);
            
            CentralLogger::log('rendiconto', 'INFO', 'Documento salvato con successo', [
                'user_id' => $this->userId,
                'documento_id' => $documentoId,
                'anno' => $payload['anno']
            ]);
            
            return [
                'success' => true,
                'documento' => $documento,
                'totali' => $totali,
                'kpi' => $kpi
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('rendiconto', 'ERROR', 'Errore salvataggio documento', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'anno' => $payload['anno'] ?? 'unknown'
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process a single riga with calculations
     */
    private function processRiga($riga) {
        // Convert strings to numbers with proper precision
        $entrateFatturato = (float) ($riga['entrate_fatturato'] ?? 0);
        $entrateUnita = (int) ($riga['entrate_unita'] ?? 0);
        $erogatoImporto = (float) ($riga['erogato_importo'] ?? 0);
        $accantonamentoPercentuale = (float) ($riga['accantonamento_percentuale'] ?? 0);
        $accantonamentoEuro = (float) ($riga['accantonamento_euro'] ?? 0);
        $tasseEuro = (float) ($riga['tasse_euro'] ?? 0);
        $diversiEuro = (float) ($riga['diversi_euro'] ?? 0);
        $materia1Euro = (float) ($riga['materia1_euro'] ?? 0);
        $materia1Unita = (int) ($riga['materia1_unita'] ?? 0);
        $spedEuro = (float) ($riga['sped_euro'] ?? 0);
        $spedUnita = (int) ($riga['sped_unita'] ?? 0);
        $varieEuro = (float) ($riga['varie_euro'] ?? 0);
        
        // Calculate accantonamento_euro if not provided but percentage is
        if ($accantonamentoEuro == 0 && $accantonamentoPercentuale > 0) {
            $accantonamentoEuro = round($entrateFatturato * ($accantonamentoPercentuale / 100), 2, PHP_ROUND_HALF_UP);
        }
        
        // Calculate utile_lordo_mese
        $utileLordoMese = $erogatoImporto + $accantonamentoEuro - $materia1Euro - $spedEuro - $varieEuro;
        
        // Calculate utile_netto_mese
        $utileNettoMese = $utileLordoMese - $tasseEuro;
        
        return [
            'mese' => (int) $riga['mese'],
            'data' => $riga['data'] ?? null,
            'entrate_fatturato' => $entrateFatturato,
            'entrate_unita' => $entrateUnita,
            'erogato_importo' => $erogatoImporto,
            'accantonamento_percentuale' => $accantonamentoPercentuale,
            'accantonamento_euro' => $accantonamentoEuro,
            'tasse_euro' => $tasseEuro,
            'diversi_euro' => $diversiEuro,
            'materia1_euro' => $materia1Euro,
            'materia1_unita' => $materia1Unita,
            'sped_euro' => $spedEuro,
            'sped_unita' => $spedUnita,
            'varie_euro' => $varieEuro,
            'utile_netto_mese' => $utileNettoMese
        ];
    }
    
    /**
     * Compute year totals from righe
     */
    public function computeYearTotals($righe) {
        $totali = [
            'tot_entrate_fatturato' => 0,
            'tot_entrate_unita' => 0,
            'tot_erogato' => 0,
            'tot_accantonamento' => 0,
            'tot_tasse' => 0,
            'tot_diversi' => 0,
            'tot_materia1' => 0,
            'tot_materia1_unita' => 0,
            'tot_sped' => 0,
            'tot_sped_unita' => 0,
            'tot_varie' => 0,
            'tot_utile_netto' => 0
        ];
        
        foreach ($righe as $riga) {
            $totali['tot_entrate_fatturato'] += (float) $riga['entrate_fatturato'];
            $totali['tot_entrate_unita'] += (int) $riga['entrate_unita'];
            $totali['tot_erogato'] += (float) $riga['erogato_importo'];
            $totali['tot_accantonamento'] += (float) $riga['accantonamento_euro'];
            $totali['tot_tasse'] += (float) $riga['tasse_euro'];
            $totali['tot_diversi'] += (float) $riga['diversi_euro'];
            $totali['tot_materia1'] += (float) $riga['materia1_euro'];
            $totali['tot_materia1_unita'] += (int) $riga['materia1_unita'];
            $totali['tot_sped'] += (float) $riga['sped_euro'];
            $totali['tot_sped_unita'] += (int) $riga['sped_unita'];
            $totali['tot_varie'] += (float) $riga['varie_euro'];
            $totali['tot_utile_netto'] += (float) $riga['utile_netto_mese'];
        }
        
        // Calculate derived totals
        $totali['utile_lordo_totale'] = $totali['tot_erogato'] + $totali['tot_accantonamento'] 
                                      - $totali['tot_materia1'] - $totali['tot_sped'] - $totali['tot_varie'];
        
        $totali['utile_netto_totale'] = $totali['utile_lordo_totale'] - $totali['tot_tasse'];
        
        // FBA calculation (informational only)
        $totali['fba_totale'] = $totali['tot_entrate_fatturato'] - ($totali['tot_erogato'] + $totali['tot_accantonamento']);
        
        return $totali;
    }
    
    /**
     * Compute KPI from totals
     */
    public function computeKpi($totali) {
        $kpi = [];
        
        // Define the KPI categories
        $categories = [
            'fatturato' => $totali['tot_entrate_fatturato'],
            'erogato' => $totali['tot_erogato'],
            'fba' => $totali['fba_totale'],
            'accantonamento' => $totali['tot_accantonamento'],
            'tasse' => $totali['tot_tasse'],
            'materia1' => $totali['tot_materia1'],
            'sped' => $totali['tot_sped'],
            'varie' => $totali['tot_varie'],
            'utile_lordo' => $totali['utile_lordo_totale'],
            'utile_netto' => $totali['utile_netto_totale']
        ];
        
        foreach ($categories as $category => $totale) {
            // Per unità calculation
            $perUnita = 0;
            if ($totali['tot_entrate_unita'] > 0) {
                $perUnita = round($totale / $totali['tot_entrate_unita'], 2, PHP_ROUND_HALF_UP);
            }
            
            // Percentage of fatturato
            $percFatt = 0;
            if ($totali['tot_entrate_fatturato'] > 0) {
                $percFatt = round(($totale / $totali['tot_entrate_fatturato']) * 100, 2, PHP_ROUND_HALF_UP);
            }
            
            // Percentage of erogato
            $percErog = 0;
            if ($totali['tot_erogato'] > 0) {
                $percErog = round(($totale / $totali['tot_erogato']) * 100, 2, PHP_ROUND_HALF_UP);
            }
            
            $kpi[$category] = [
                'totale' => round($totale, 2, PHP_ROUND_HALF_UP),
                'per_unita' => $perUnita,
                'perc_fatt' => $percFatt,
                'perc_erog' => $percErog
            ];
        }
        
        return $kpi;
    }
    
    /**
     * Validate input payload
     */
    public function validate($payload) {
        $errors = [];
        
        // Check required fields
        if (empty($payload['anno']) || !is_numeric($payload['anno'])) {
            $errors[] = 'Anno is required and must be numeric';
        }
        
        if (empty($payload['righe']) || !is_array($payload['righe'])) {
            $errors[] = 'Righe data is required';
        } else {
            foreach ($payload['righe'] as $index => $riga) {
                // Validate mese
                if (!isset($riga['mese']) || $riga['mese'] < 1 || $riga['mese'] > 12) {
                    $errors[] = "Row $index: Invalid month";
                }
                
                // Validate numeric fields are non-negative where appropriate
                $numericFields = [
                    'entrate_fatturato', 'entrate_unita', 'erogato_importo',
                    'accantonamento_percentuale', 'accantonamento_euro',
                    'tasse_euro', 'diversi_euro', 'materia1_euro', 'materia1_unita',
                    'sped_euro', 'sped_unita'
                ];
                
                foreach ($numericFields as $field) {
                    if (isset($riga[$field]) && $riga[$field] !== '' && $riga[$field] !== null) {
                        if (!is_numeric($riga[$field]) || (in_array($field, ['entrate_unita', 'materia1_unita', 'sped_unita']) && $riga[$field] < 0)) {
                            $errors[] = "Row $index: $field must be a valid non-negative number";
                        }
                    }
                }
                
                // varie_euro can be negative
                if (isset($riga['varie_euro']) && $riga['varie_euro'] !== '' && $riga['varie_euro'] !== null && !is_numeric($riga['varie_euro'])) {
                    $errors[] = "Row $index: varie_euro must be numeric";
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Get available years from all sources (documenti, input_utente, settlement)
     */
    public function getAvailableYears() {
        try {
            $pdo = $this->model->getPdo();
            
            // Get distinct years from multiple sources using UNION
            $sql = "
                SELECT DISTINCT anno FROM rendiconto_documenti WHERE user_id = ?
                UNION
                SELECT DISTINCT anno FROM rendiconto_input_utente WHERE user_id = ?
                UNION
                SELECT DISTINCT YEAR(settlement_end_date) as anno 
                FROM settlement_metadata 
                WHERE user_id = ?
                ORDER BY anno DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$this->userId, $this->userId, $this->userId]);
            $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return [
                'success' => true,
                'years' => array_map('intval', $years)
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('rendiconto', 'ERROR', 'Errore recupero anni disponibili', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Duplicate year data
     */
    public function duplicateYear($sourceAnno, $targetAnno, $brand = 'PROFUMI YESENSY') {
        try {
            CentralLogger::log('rendiconto', 'INFO', 'Inizio duplicazione anno', [
                'user_id' => $this->userId,
                'source_anno' => $sourceAnno,
                'target_anno' => $targetAnno
            ]);
            
            $result = $this->model->duplicateDocumento($this->userId, $sourceAnno, $targetAnno, $brand);
            
            if ($result) {
                CentralLogger::log('rendiconto', 'INFO', 'Anno duplicato con successo', [
                    'user_id' => $this->userId,
                    'source_anno' => $sourceAnno,
                    'target_anno' => $targetAnno
                ]);
                return ['success' => true, 'message' => 'Anno duplicato con successo'];
            } else {
                return ['success' => false, 'error' => 'Errore durante la duplicazione'];
            }
            
        } catch (Exception $e) {
            CentralLogger::log('rendiconto', 'ERROR', 'Errore duplicazione anno', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'source_anno' => $sourceAnno,
                'target_anno' => $targetAnno
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get fatturato data from OrderInsights settlement table
     */
    public function getFatturatoFromSettlement($anno) {
        try {
            $tableName = "report_settlement_{$this->userId}";
            $pdo = $this->model->getPdo();
            
            // Check if table exists
            $stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($tableName) . "'");
            if (!$stmt->fetchColumn()) {
                return [
                    'success' => false,
                    'error' => 'Tabella settlement non trovata',
                    'debug' => [
                        'user_id' => $this->userId,
                        'table_name' => $tableName
                    ]
                ];
            }
            
            // Get data for each month of the year
            $datiMensili = [];
            
            for ($mese = 1; $mese <= 12; $mese++) {
                $meseStr = str_pad($mese, 2, '0', STR_PAD_LEFT);
                $dataInizio = "{$anno}-{$meseStr}-01 00:00:00";
                
                // Calculate end of month
                $dataFine = date('Y-m-d 23:59:59', strtotime("{$anno}-{$meseStr}-01 + 1 month - 1 day"));
                
                // Query for fatturato - UNIFORMATO CON margins_engine.php
                // Per transazioni Order:
                // - Somma SOLO price_amount (Principal, Tax, Shipping, GiftWrap, etc.)
                // NON include promotion_amount, direct_payment_amount, other_amount
                // NON include le fee (item_related_fee, order_fee, shipment_fee, etc.)
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(COALESCE(rs.price_amount, 0)) as fatturato,
                        SUM(
                            CASE 
                                WHEN rs.transaction_type = 'Order' 
                                     AND (rs.price_type = 'Principal' OR rs.price_type IS NULL OR rs.price_type = '')
                                THEN COALESCE(rs.quantity_purchased, 0)
                                ELSE 0
                            END
                        ) as unita_vendute
                    FROM `{$tableName}` rs
                    WHERE rs.posted_date >= ? 
                      AND rs.posted_date <= ?
                      AND rs.transaction_type = 'Order'
                ");
                
                $stmt->execute([$dataInizio, $dataFine]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Get erogato details from settlement_metadata
                // Filtra SOLO per settlement_end_date nel range del mese
                $stmtErogato = $pdo->prepare("
                    SELECT 
                        settlement_id,
                        total_amount,
                        currency,
                        settlement_end_date
                    FROM settlement_metadata
                    WHERE user_id = ?
                      AND settlement_end_date >= ?
                      AND settlement_end_date <= ?
                ");
                $stmtErogato->execute([
                    $this->userId,
                    $dataInizio,
                    $dataFine
                ]);
                $settlements = $stmtErogato->fetchAll(PDO::FETCH_ASSOC);
                
                $erogatoTotale = 0;
                foreach ($settlements as $settlement) {
                    // Salta i settlement con importo negativo o zero
                    // Gli aggiustamenti contabili negativi sono già gestiti da Amazon
                    if ($settlement['total_amount'] <= 0) {
                        continue;
                    }
                    
                    // Converti in EUR prima di sommare
                    $importoEur = $this->currencyConverter->convertToEur(
                        $settlement['total_amount'], 
                        $settlement['currency']
                    );
                    $erogatoTotale += $importoEur;
                    
                    // Estrai il mese dalla settlement_end_date (questa è la data che determina il mese)
                    $settlementEndDate = new DateTime($settlement['settlement_end_date']);
                    $meseSettlement = (int)$settlementEndDate->format('n');
                    $annoSettlement = (int)$settlementEndDate->format('Y');
                    
                    // Verifica se settlement già inserito usando settlement_id (chiave univoca)
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM rendiconto_input_utente 
                        WHERE user_id = ? 
                          AND settlement_id = ?
                          AND tipo_input = 'erogato'
                    ");
                    $checkStmt->execute([
                        $this->userId, 
                        $settlement['settlement_id']
                    ]);
                    
                    // Inserisci solo se non esiste
                    if (!$checkStmt->fetchColumn()) {
                        // Formatta nota con valuta originale + conversione EUR
                        $nota = sprintf(
                            "Accredito amazon di %s in data %s",
                            $this->currencyConverter->formatWithEur(
                                $settlement['total_amount'], 
                                $settlement['currency']
                            ),
                            $settlement['settlement_end_date']
                        );
                        
                        // Usa il mese dalla settlement_end_date, NON dal loop
                        $this->model->saveInputUtente(
                            $this->userId,
                            $annoSettlement,
                            $meseSettlement,
                            'erogato',
                            (float)$settlement['total_amount'],  // importo originale
                            0,
                            $nota,
                            $settlement['settlement_end_date'],
                            null,                                 // id (nuovo record)
                            $settlement['settlement_id'],         // settlement_id univoco
                            $settlement['currency'],              // currency originale
                            $importoEur                           // importo convertito in EUR
                        );
                    }
                }
                
                $resultErogato = ['erogato' => $erogatoTotale];
                
                $datiMensili[$mese] = [
                    'mese' => $mese,
                    'fatturato' => round((float)($result['fatturato'] ?? 0), 2),
                    'unita_vendute' => (int)($result['unita_vendute'] ?? 0),
                    'erogato' => round((float)($resultErogato['erogato'] ?? 0), 2),
                    'periodo' => [
                        'inizio' => $dataInizio,
                        'fine' => $dataFine
                    ]
                ];
            }
            
            // Calculate totals
            $totFatturato = 0;
            $totUnita = 0;
            $totErogato = 0;
            foreach ($datiMensili as $dato) {
                $totFatturato += $dato['fatturato'];
                $totUnita += $dato['unita_vendute'];
                $totErogato += $dato['erogato'];
            }
            
            return [
                'success' => true,
                'anno' => $anno,
                'dati_mensili' => $datiMensili,
                'totali' => [
                    'fatturato' => round($totFatturato, 2),
                    'unita_vendute' => $totUnita,
                    'erogato' => round($totErogato, 2)
                ],
                'debug' => [
                    'user_id' => $this->userId,
                    'table_name' => $tableName,
                    'query_executed' => true
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'debug' => [
                    'user_id' => $this->userId,
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }
    }
    
    /**
     * Get input utente per anno/tipo
     */
    public function getInputUtente($anno, $tipoInput = null, $mese = null, $id = null) {
        try {
            $data = $this->model->getInputUtente($this->userId, $anno, $tipoInput, $mese, $id);
            
            return [
                'success' => true,
                'data' => $data
            ];
        } catch (Exception $e) {
            CentralLogger::log('rendiconto', 'ERROR', 'Errore caricamento input utente', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Save input utente
     */
    public function saveInputUtente($payload) {
        try {
            $result = $this->model->saveInputUtente(
                $this->userId,
                $payload['anno'],
                $payload['mese'],
                $payload['tipo_input'],
                $payload['importo'] ?? 0,
                $payload['quantita'] ?? 0,
                $payload['note'] ?? null,
                $payload['data'] ?? null,
                $payload['id'] ?? null,
                $payload['settlement_id'] ?? null,
                $payload['currency'] ?? 'EUR',
                $payload['importo_eur'] ?? null
            );
            
            if ($result) {
                CentralLogger::log('rendiconto', 'INFO', 'Input utente salvato', [
                    'user_id' => $this->userId,
                    'tipo_input' => $payload['tipo_input'],
                    'anno' => $payload['anno'],
                    'mese' => $payload['mese']
                ]);
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Salvataggio fallito'];
            }
        } catch (Exception $e) {
            CentralLogger::log('rendiconto', 'ERROR', 'Errore salvataggio input utente', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Delete input utente
     */
    public function deleteInputUtente($id) {
        try {
            $result = $this->model->deleteInputUtente($this->userId, $id);
            
            if ($result) {
                CentralLogger::log('rendiconto', 'INFO', 'Input utente eliminato', [
                    'user_id' => $this->userId,
                    'id' => $id
                ]);
                
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Eliminazione fallita'];
            }
        } catch (Exception $e) {
            CentralLogger::log('rendiconto', 'ERROR', 'Errore eliminazione input utente', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get unità vendute da settlement per anno
     */
    public function getUnitaVendute($anno) {
        try {
            $tableName = "report_settlement_{$this->userId}";
            $pdo = $this->model->getPdo();
            
            // Check if table exists
            $stmt = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
            if (!$stmt->fetchColumn()) {
                return ['success' => false, 'error' => 'Tabella settlement non trovata'];
            }
            
            // Get total units sold from settlement
            $sql = "SELECT COALESCE(SUM(quantity_purchased), 0) as tot_unita_vendute
                    FROM {$tableName}
                    WHERE transaction_type = 'Order' 
                      AND YEAR(posted_date) = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$anno]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'unita_vendute' => (int) $result['tot_unita_vendute']
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('rendiconto', 'ERROR', 'Errore calcolo unità vendute', [
                'user_id' => $this->userId,
                'anno' => $anno,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
} 