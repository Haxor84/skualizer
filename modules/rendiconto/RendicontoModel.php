<?php

class RendicontoModel {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Insert or update a documento record
     */
    public function upsertDocumento($userId, $anno, $brand = 'PROFUMI YESENSY', $valuta = 'EUR') {
        $sql = "INSERT INTO rendiconto_documenti (user_id, anno, brand, valuta) 
                VALUES (:user_id, :anno, :brand, :valuta)
                ON DUPLICATE KEY UPDATE 
                brand = VALUES(brand), 
                valuta = VALUES(valuta),
                updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':anno' => $anno,
            ':brand' => $brand,
            ':valuta' => $valuta
        ]);
        
        // Return the document ID
        $documentId = $this->pdo->lastInsertId();
        if (!$documentId) {
            // If no insert happened (duplicate key), get existing ID
            $stmt = $this->pdo->prepare("SELECT id FROM rendiconto_documenti WHERE user_id = :user_id AND anno = :anno AND brand = :brand");
            $stmt->execute([':user_id' => $userId, ':anno' => $anno, ':brand' => $brand]);
            $documentId = $stmt->fetchColumn();
        }
        
        return $documentId;
    }
    
    /**
     * Insert or update multiple righe records in batch
     */
    public function upsertRigheBatch($documentoId, $righe) {
        if (empty($righe)) {
            return;
        }
        
        // Prepare the upsert statement
        $sql = "INSERT INTO rendiconto_righe (
                    documento_id, mese, data, entrate_fatturato, entrate_unita,
                    erogato_importo, accantonamento_percentuale, accantonamento_euro,
                    tasse_euro, diversi_euro, materia1_euro, materia1_unita,
                    sped_euro, sped_unita, varie_euro, utile_netto_mese
                ) VALUES (
                    :documento_id, :mese, :data, :entrate_fatturato, :entrate_unita,
                    :erogato_importo, :accantonamento_percentuale, :accantonamento_euro,
                    :tasse_euro, :diversi_euro, :materia1_euro, :materia1_unita,
                    :sped_euro, :sped_unita, :varie_euro, :utile_netto_mese
                ) ON DUPLICATE KEY UPDATE
                    data = VALUES(data),
                    entrate_fatturato = VALUES(entrate_fatturato),
                    entrate_unita = VALUES(entrate_unita),
                    erogato_importo = VALUES(erogato_importo),
                    accantonamento_percentuale = VALUES(accantonamento_percentuale),
                    accantonamento_euro = VALUES(accantonamento_euro),
                    tasse_euro = VALUES(tasse_euro),
                    diversi_euro = VALUES(diversi_euro),
                    materia1_euro = VALUES(materia1_euro),
                    materia1_unita = VALUES(materia1_unita),
                    sped_euro = VALUES(sped_euro),
                    sped_unita = VALUES(sped_unita),
                    varie_euro = VALUES(varie_euro),
                    utile_netto_mese = VALUES(utile_netto_mese),
                    updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($righe as $riga) {
            $stmt->execute([
                ':documento_id' => $documentoId,
                ':mese' => $riga['mese'],
                ':data' => $riga['data'] ?? null,
                ':entrate_fatturato' => $riga['entrate_fatturato'] ?? 0,
                ':entrate_unita' => $riga['entrate_unita'] ?? 0,
                ':erogato_importo' => $riga['erogato_importo'] ?? 0,
                ':accantonamento_percentuale' => $riga['accantonamento_percentuale'] ?? 0,
                ':accantonamento_euro' => $riga['accantonamento_euro'] ?? 0,
                ':tasse_euro' => $riga['tasse_euro'] ?? 0,
                ':diversi_euro' => $riga['diversi_euro'] ?? 0,
                ':materia1_euro' => $riga['materia1_euro'] ?? 0,
                ':materia1_unita' => $riga['materia1_unita'] ?? 0,
                ':sped_euro' => $riga['sped_euro'] ?? 0,
                ':sped_unita' => $riga['sped_unita'] ?? 0,
                ':varie_euro' => $riga['varie_euro'] ?? 0,
                ':utile_netto_mese' => $riga['utile_netto_mese'] ?? 0
            ]);
        }
    }
    
    /**
     * Get complete documento with all righe
     */
    public function getDocumentoCompleto($documentoId, $userId = null) {
        // Get documento info with optional user security check
        if ($userId) {
            $sqlDoc = "SELECT * FROM rendiconto_documenti WHERE id = :id AND user_id = :user_id";
            $stmtDoc = $this->pdo->prepare($sqlDoc);
            $stmtDoc->execute([':id' => $documentoId, ':user_id' => $userId]);
        } else {
            $sqlDoc = "SELECT * FROM rendiconto_documenti WHERE id = :id";
            $stmtDoc = $this->pdo->prepare($sqlDoc);
            $stmtDoc->execute([':id' => $documentoId]);
        }
        $documento = $stmtDoc->fetch(PDO::FETCH_ASSOC);
        
        if (!$documento) {
            return null;
        }
        
        // Get all righe for this documento
        $sqlRighe = "SELECT * FROM rendiconto_righe WHERE documento_id = :documento_id ORDER BY mese";
        $stmtRighe = $this->pdo->prepare($sqlRighe);
        $stmtRighe->execute([':documento_id' => $documentoId]);
        $righe = $stmtRighe->fetchAll(PDO::FETCH_ASSOC);
        
        // Create array with all 12 months, filling missing ones with defaults
        $righeComplete = [];
        for ($mese = 1; $mese <= 12; $mese++) {
            $rigaEsistente = null;
            foreach ($righe as $riga) {
                if ($riga['mese'] == $mese) {
                    $rigaEsistente = $riga;
                    break;
                }
            }
            
            if ($rigaEsistente) {
                $righeComplete[$mese] = $rigaEsistente;
            } else {
                $righeComplete[$mese] = [
                    'id' => null,
                    'documento_id' => $documentoId,
                    'mese' => $mese,
                    'data' => null,
                    'entrate_fatturato' => 0,
                    'entrate_unita' => 0,
                    'erogato_importo' => 0,
                    'accantonamento_percentuale' => 0,
                    'accantonamento_euro' => 0,
                    'tasse_euro' => 0,
                    'diversi_euro' => 0,
                    'materia1_euro' => 0,
                    'materia1_unita' => 0,
                    'sped_euro' => 0,
                    'sped_unita' => 0,
                    'varie_euro' => 0,
                    'utile_netto_mese' => 0
                ];
            }
        }
        
        return [
            'documento' => $documento,
            'righe' => $righeComplete
        ];
    }
    
    /**
     * Get documento by anno and brand
     */
    public function getDocumentoByAnno($userId, $anno, $brand = 'PROFUMI YESENSY') {
        $sql = "SELECT id FROM rendiconto_documenti WHERE user_id = :user_id AND anno = :anno AND brand = :brand";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':anno' => $anno, ':brand' => $brand]);
        $documentoId = $stmt->fetchColumn();
        
        if ($documentoId) {
            return $this->getDocumentoCompleto($documentoId);
        }
        
        return null;
    }
    
    /**
     * Get all available years
     */
    public function getAvailableYears($userId) {
        $sql = "SELECT DISTINCT anno FROM rendiconto_documenti WHERE user_id = :user_id ORDER BY anno DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Duplicate documento from one year to another
     */
    public function duplicateDocumento($userId, $sourceAnno, $targetAnno, $brand = 'PROFUMI YESENSY') {
        try {
            $this->pdo->beginTransaction();
            
            // Get source document
            $sourceDoc = $this->getDocumentoByAnno($userId, $sourceAnno, $brand);
            if (!$sourceDoc) {
                throw new Exception("Documento sorgente per l'anno {$sourceAnno} non trovato");
            }
            
            // Check if target already exists
            $targetExists = $this->getDocumentoByAnno($userId, $targetAnno, $brand);
            if ($targetExists) {
                throw new Exception("Documento per l'anno {$targetAnno} già esistente");
            }
            
            // Create target document
            $targetDocId = $this->upsertDocumento($userId, $targetAnno, $brand, $sourceDoc['documento']['valuta']);
            
            // Copy all righe with reset values but keep structure
            $righeToInsert = [];
            foreach ($sourceDoc['righe'] as $riga) {
                if ($riga['id']) { // Only copy existing righe, not empty months
                    $righeToInsert[] = [
                        'mese' => $riga['mese'],
                        'data' => null, // Reset date
                        'entrate_fatturato' => 0, // Reset all values
                        'entrate_unita' => 0,
                        'erogato_importo' => 0,
                        'accantonamento_percentuale' => $riga['accantonamento_percentuale'], // Keep percentage structure
                        'accantonamento_euro' => 0,
                        'tasse_euro' => 0,
                        'diversi_euro' => 0,
                        'materia1_euro' => 0,
                        'materia1_unita' => 0,
                        'sped_euro' => 0,
                        'sped_unita' => 0,
                        'varie_euro' => 0,
                        'utile_netto_mese' => 0
                    ];
                }
            }
            
            if (!empty($righeToInsert)) {
                $this->upsertRigheBatch($targetDocId, $righeToInsert);
            }
            
            $this->pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Delete documento and all related righe
     */
    public function deleteDocumento($userId, $documentoId) {
        try {
            $this->pdo->beginTransaction();
            
            // Verify ownership
            $stmt = $this->pdo->prepare("SELECT id FROM rendiconto_documenti WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $documentoId, ':user_id' => $userId]);
            
            if (!$stmt->fetchColumn()) {
                throw new Exception("Documento non trovato o non autorizzato");
            }
            
            // Delete righe first (due to foreign key)
            $stmt = $this->pdo->prepare("DELETE FROM rendiconto_righe WHERE documento_id = :documento_id");
            $stmt->execute([':documento_id' => $documentoId]);
            
            // Delete documento
            $stmt = $this->pdo->prepare("DELETE FROM rendiconto_documenti WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $documentoId, ':user_id' => $userId]);
            
            $this->pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get PDO connection for advanced queries
     */
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Get input utente filtrati per anno, tipo e mese (opzionale)
     */
    public function getInputUtente($userId, $anno, $tipoInput = null, $mese = null, $id = null) {
        $sql = "SELECT * FROM rendiconto_input_utente 
                WHERE user_id = :user_id";
        
        $params = [':user_id' => $userId];
        
        // Se è fornito ID, recupera solo quella transazione
        if ($id) {
            $sql .= " AND id = :id";
            $params[':id'] = $id;
        } elseif ($anno) {
            // Anno è opzionale: se fornito, aggiungi filtro
            $sql .= " AND anno = :anno";
            $params[':anno'] = $anno;
        }
        
        if ($tipoInput) {
            $sql .= " AND tipo_input = :tipo_input";
            $params[':tipo_input'] = $tipoInput;
        }
        
        if ($mese) {
            $sql .= " AND mese = :mese";
            $params[':mese'] = $mese;
        }
        
        $sql .= " ORDER BY anno DESC, mese ASC, data ASC, created_at ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Save or update input utente
     */
    public function saveInputUtente(
        $userId, $anno, $mese, $tipoInput, 
        $importo = 0, $quantita = 0, $note = null, $data = null, $id = null,
        $settlementId = null,
        $currency = 'EUR',
        $importoEur = null
    ) {
        if ($id) {
            // Update existing - AGGIORNA ANCHE ANNO E MESE dalla data!
            error_log("🔄 [UPDATE] ID: $id | Anno: $anno | Mese: $mese | Data: $data | Importo: $importo | Note: $note");
            
            $sql = "UPDATE rendiconto_input_utente 
                    SET anno = :anno, mese = :mese, tipo_input = :tipo_input,
                        importo = :importo, quantita = :quantita, note = :note, data = :data,
                        settlement_id = :settlement_id, currency = :currency, importo_eur = :importo_eur
                    WHERE id = :id AND user_id = :user_id";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':anno' => $anno,
                ':mese' => $mese,
                ':tipo_input' => $tipoInput,
                ':importo' => $importo,
                ':quantita' => $quantita,
                ':note' => $note,
                ':data' => $data,
                ':settlement_id' => $settlementId,
                ':currency' => $currency,
                ':importo_eur' => $importoEur ?: $importo
            ]);
            
            error_log("✅ [UPDATE] Righe aggiornate: " . $stmt->rowCount());
            
            return $result;
        } else {
            // Insert new
            $sql = "INSERT INTO rendiconto_input_utente 
                    (user_id, anno, mese, tipo_input, importo, quantita, note, data, settlement_id, currency, importo_eur)
                    VALUES (:user_id, :anno, :mese, :tipo_input, :importo, :quantita, :note, :data, :settlement_id, :currency, :importo_eur)";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':user_id' => $userId,
                ':anno' => $anno,
                ':mese' => $mese,
                ':tipo_input' => $tipoInput,
                ':importo' => $importo,
                ':quantita' => $quantita,
                ':note' => $note,
                ':data' => $data,
                ':settlement_id' => $settlementId,
                ':currency' => $currency,
                ':importo_eur' => $importoEur ?: $importo
            ]);
        }
    }
    
    /**
     * Delete input utente by ID
     */
    public function deleteInputUtente($userId, $id) {
        $sql = "DELETE FROM rendiconto_input_utente WHERE id = :id AND user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }
    
    /**
     * Get aggregated summary of input utente per mese
     */
    public function getInputUtenteSummary($userId, $anno) {
        $sql = "SELECT 
                    mese,
                    SUM(CASE WHEN tipo_input = 'spese_varie' THEN importo ELSE 0 END) as varie_euro,
                    SUM(CASE WHEN tipo_input = 'tasse_pagamento' THEN importo ELSE 0 END) as tasse_euro,
                    SUM(CASE WHEN tipo_input = 'materia_prima_acquisto' THEN importo ELSE 0 END) as materia1_euro,
                    SUM(CASE WHEN tipo_input = 'materia_prima_acquisto' THEN quantita ELSE 0 END) as materia1_unita,
                    SUM(CASE WHEN tipo_input = 'spedizioni_acquisto' THEN importo ELSE 0 END) as sped_euro,
                    SUM(CASE WHEN tipo_input = 'spedizioni_acquisto' THEN quantita ELSE 0 END) as sped_unita
                FROM rendiconto_input_utente
                WHERE user_id = :user_id AND anno = :anno
                GROUP BY mese
                ORDER BY mese ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':anno' => $anno]);
        
        // Organize by mese for easy access
        $summary = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $summary[$row['mese']] = $row;
        }
        
        return $summary;
    }
    
    /**
     * Get totali annuali per KPI (unita acquistate/spedite)
     */
    public function getKpiTotaliAnno($userId, $anno) {
        $sql = "SELECT 
                    SUM(CASE WHEN tipo_input = 'unita_acquistate' THEN quantita ELSE 0 END) as tot_unita_acquistate,
                    SUM(CASE WHEN tipo_input = 'unita_spedite' THEN quantita ELSE 0 END) as tot_unita_spedite
                FROM rendiconto_input_utente
                WHERE user_id = :user_id AND anno = :anno";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':anno' => $anno]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
} 