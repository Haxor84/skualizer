<?php
/**
 * Fee Mapping Helpers - Funzioni condivise sistema categorie
 * File: modules/margynomic/margini/fee_mapping_helpers.php
 */

require_once dirname(__DIR__) . '/config/config.php';

/**
 * Ottiene categoria per transaction_type (con fallback utente → globale)
 */
function getTransactionCategory($transactionType, $userId = null) {
    try {
        $pdo = getDbConnection();
        
        // Prima cerca mapping utente-specifico
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT category 
                FROM transaction_fee_mappings 
                WHERE transaction_type = ? AND user_id = ? AND is_active = 1
            ");
            $stmt->execute([$transactionType, $userId]);
            $result = $stmt->fetchColumn();
            if ($result) return $result;
        }
        
        // Fallback a mapping globale
        $stmt = $pdo->prepare("
            SELECT category 
            FROM transaction_fee_mappings 
            WHERE transaction_type = ? AND user_id IS NULL AND is_active = 1
        ");
        $stmt->execute([$transactionType]);
        $result = $stmt->fetchColumn();
        if ($result) return $result;
        
        // Default per transaction_type non mappati
        return getDefaultCategory($transactionType);
        
    } catch (Exception $e) {
        logSystemError("Errore getTransactionCategory: " . $e->getMessage());
        return getDefaultCategory($transactionType);
    }
}

/**
 * Categorizzazione automatica di emergenza
 */
function getDefaultCategory($transactionType) {
    $type = strtolower(trim($transactionType));
    
    if ($type === 'order') return 'REVENUE';
    if (strpos($type, 'refund') !== false) return 'REFUNDS';
    if (strpos($type, 'reimbursement') !== false) return 'FEE_TAB1_ADJUSTMENT';
    if (strpos($type, 'storage') !== false) return 'STORAGE';
    if (strpos($type, 'transportation') !== false || strpos($type, 'inbound') !== false) return 'TRANSPORTATION';
    if (strpos($type, 'damage') !== false || strpos($type, 'lost') !== false) return 'DAMAGE_COMPENSATION';
    if (strpos($type, 'subscription') !== false || strpos($type, 'service') !== false) return 'SUBSCRIPTIONS';
    if (strpos($type, 'reserve') !== false || strpos($type, 'payable') !== false) return 'IGNORE';
    
    return 'OPERATIONAL'; // Fallback conservativo
}

/**
 * Rileva tutti i transaction_type presenti nei database utenti
 */
function discoverAllTransactionTypes() {
    try {
        $pdo = getDbConnection();
        
        // Ottieni tutte le tabelle settlement esistenti
        $stmt = $pdo->query("SHOW TABLES LIKE 'report_settlement_%'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $allTypes = [];
        
        foreach ($tables as $tableName) {
            // Estrai user_id dal nome tabella
            $userId = str_replace('report_settlement_', '', $tableName);
            
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        transaction_type,
                        COUNT(*) as occurrences,
                        SUM(ABS(COALESCE(other_amount, 0) + COALESCE(item_related_fee_amount, 0) + COALESCE(other_fee_amount, 0))) as total_impact,
                        MIN(posted_date) as first_seen,
                        MAX(posted_date) as last_seen
                    FROM `{$tableName}` 
                    WHERE transaction_type IS NOT NULL 
                    GROUP BY transaction_type
                ");
                $stmt->execute();
                $results = $stmt->fetchAll();
                
                foreach ($results as $row) {
                    $type = $row['transaction_type'];
                    if (!isset($allTypes[$type])) {
                        $allTypes[$type] = [
                            'transaction_type' => $type,
                            'total_occurrences' => 0,
                            'total_impact' => 0,
                            'affected_users' => [],
                            'first_seen' => $row['first_seen'],
                            'last_seen' => $row['last_seen']
                        ];
                    }
                    
                    $allTypes[$type]['total_occurrences'] += $row['occurrences'];
                    $allTypes[$type]['total_impact'] += $row['total_impact'];
                    $allTypes[$type]['affected_users'][] = $userId;
                    
                    // Aggiorna date estreme
                    if ($row['first_seen'] < $allTypes[$type]['first_seen']) {
                        $allTypes[$type]['first_seen'] = $row['first_seen'];
                    }
                    if ($row['last_seen'] > $allTypes[$type]['last_seen']) {
                        $allTypes[$type]['last_seen'] = $row['last_seen'];
                    }
                }
                
            } catch (Exception $e) {
                logSystemError("Errore analisi tabella {$tableName}: " . $e->getMessage());
                continue;
            }
        }
        
        // Ordina per impatto economico
        uasort($allTypes, function($a, $b) {
            return $b['total_impact'] <=> $a['total_impact'];
        });
        
        return array_values($allTypes);
        
    } catch (Exception $e) {
        logSystemError("Errore discoverAllTransactionTypes: " . $e->getMessage());
        return [];
    }
}

/**
 * Ottiene statistiche mapping per dashboard
 */
function getMappingStats() {
    try {
        $pdo = getDbConnection();
        
        $allTypes = discoverAllTransactionTypes();
        $totalTypes = count($allTypes);
        
        // Conta quanti sono mappati
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT transaction_type) 
            FROM transaction_fee_mappings 
            WHERE is_active = 1
        ");
        $stmt->execute();
        $mappedTypes = $stmt->fetchColumn();
        
        // Calcola impatto economico non mappato
        $unmappedImpact = 0;
        foreach ($allTypes as $type) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM transaction_fee_mappings 
                WHERE transaction_type = ? AND is_active = 1
            ");
            $stmt->execute([$type['transaction_type']]);
            if (!$stmt->fetchColumn()) {
                $unmappedImpact += $type['total_impact'];
            }
        }
        
        return [
            'total_types' => $totalTypes,
            'mapped_types' => $mappedTypes,
            'unmapped_types' => $totalTypes - $mappedTypes,
            'mapping_percentage' => $totalTypes > 0 ? round(($mappedTypes / $totalTypes) * 100, 1) : 0,
            'unmapped_impact' => $unmappedImpact
        ];
        
    } catch (Exception $e) {
        logSystemError("Errore getMappingStats: " . $e->getMessage());
        return [
            'total_types' => 0,
            'mapped_types' => 0,
            'unmapped_types' => 0,
            'mapping_percentage' => 0,
            'unmapped_impact' => 0
        ];
    }
}

/**
 * Categorie disponibili per il sistema (dinamiche da database)
 */
function getAvailableCategories() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT category_code, category_name 
            FROM fee_categories 
            WHERE is_active = 1 
            ORDER BY category_name
        ");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        // Fallback in caso di errore
        CentralLogger::log('margini', 'ERROR', 'Errore caricamento categorie: ' . $e->getMessage());
        return [
            'REVENUE' => 'Ricavi (Ordini)',
            'OPERATIONAL' => 'Costi Operativi'
        ];
    }
}

/**
 * Crea nuova categoria
 */
function createFeeCategory($code, $name, $description = '') {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            INSERT INTO fee_categories (category_code, category_name, description) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$code, $name, $description]);
        return ['success' => true, 'message' => 'Categoria creata con successo'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Aggiorna categoria esistente (solo nome e descrizione)
 */
function updateFeeCategory($id, $code, $name, $description = '') {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            UPDATE fee_categories 
            SET category_name = ?, description = ? 
            WHERE id = ?
        ");
        $stmt->execute([$name, $description, $id]);
        return ['success' => true, 'message' => 'Categoria aggiornata'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Attiva/disattiva categoria
 */
function toggleFeeCategory($id, $active = true) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE fee_categories SET is_active = ? WHERE id = ?");
        $stmt->execute([$active ? 1 : 0, $id]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Ottieni tutte le categorie (anche inattive)
 */
function getAllFeeCategories() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT id, category_code, category_name, description, is_active, created_at 
            FROM fee_categories 
            ORDER BY category_name
        ");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Ottiene descrizione per transaction type
 */
function getTransactionTypeDescription($transactionType) {
    $descriptions = [
        'Order' => 'Accrediti di Ordini Evasi',
        'Order_Retrocharge' => 'Aggiustamenti + commissioni Ordini',
        'Refund' => 'Rimborso di Ordini Evasi',
        'Refund_Retrocharge' => 'Aggiustamenti - commissioni Ordini',
        'Storage Fee' => 'Costo di Stoccaggio e Giacenza',
        'Storage Fee - Reversal' => 'Storno Costo di Stoccaggio e Giacenza',
        'Storage Fee - Correction' => 'Correzione Costo di Stoccaggio e Giacenza',
        'StorageRenewalBilling' => 'Rinnovo Costo di Stoccaggio e Giacenza',
        'MISSING_FROM_INBOUND' => 'Rimborso Smarrimenti in entrata',
        'FBAInboundTransportationFee' => 'Costo di Trasporto',
        'Inbound Transportation Fee' => 'Costo di Trasporto',
        'FBAInboundTransportationProgramFee' => 'Costo di Trasporto',
        'INBOUND_CARRIER_DAMAGE' => 'Rimborso Danni in entrata',
        'MISSING_FROM_INBOUND_CLAWBACK' => 'Storno Rimborso Smarrimenti in entrata',
        'WAREHOUSE_DAMAGE' => 'Rimborso Danni ai Magazzini',
        'WAREHOUSE_LOST_MANUAL' => 'Rimborso Smarrimenti ai Magazzini (Manuale)',
        'WAREHOUSE_DAMAGE_EXCEPTION' => 'Rimborso Danni Eccezionali',
        'WAREHOUSE_LOST' => 'Rimborso Smarrimenti ai Magazzini',
        'ServiceFee' => 'Costo Pubblicitaio',
        'Subscription Fee' => 'Costo di Abbonamento Amazon Pro',
        'EPR Pay on Behalf service fee' => 'Costo del Servizio EPR',
        'NonSubscriptionFeeAdj' => 'Aggiustamento servizi',
        'REVERSAL_REIMBURSEMENT' => 'Storno Rimborsi',
        'Fee Adjustment' => 'Aggiustamento Commissioni Spedizione',
        'INCORRECT_FEES_ITEMS' => 'Correzione fee sbagliate',
        'INCORRECT_FEES_NON_ITEMIZED' => 'Correzione fee non itemizzate',
        'RemovalComplete' => 'Costo di Rimozione Unità dai mgazzini Amazon',
        'WarehousePrep' => 'Costo di Preparazione FBA',
        'Transfer of funds unsuccessful: Amazon has cancelled your transfer of funds.' => 'Trasferimento fallito',
        'Successful charge' => 'Addebito riuscito',
        'Debt Adjustment' => 'Aggiustamento debiti',
        'Manual Processing Fee' => 'Costo di Processamento FBA',
        'BuyerRecharge' => 'Ricarica acquirente',
        'MiscAdjustment' => 'Aggiustamenti vari',
        'Payable to Amazon' => 'Dovuto ad Amazon',
        'Previous Reserve Amount Balance' => 'Saldo riserva precedente',
        'Current Reserve Amount' => 'Saldo riserva attuale',
        'COMPENSATED_CLAWBACK' => 'Recupero compensi',
        'DisposalComplete' => 'Smaltimento prodotti',
        'Liquidations' => 'Liquidazione stock',
        'RE_EVALUATION' => 'Rivalutazione crediti',
        'EPR Pay on Behalf eco-contribution' => 'Costo del Servizio EPR',
        'Promotion Fee' => 'Costo Promozioni',
        'Micro Deposit' => 'Deposito micro',
        'Transfer of funds unsuccessful: A transfer could not be initiated because the transfer amount does n' => 'Trasferimento bloccato'
    ];
    
    return $descriptions[$transactionType] ?? 'Transazione Amazon';
}
?>