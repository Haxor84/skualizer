<?php
/**
 * Inventory Strategy Manager - Logica Avanzata Rifornimenti
 * File: modules/previsync/inventory_strategy_manager.php
 * 
 * Gestisce strategie avanzate per calcolo rifornimenti basate su:
 * - Storico vendite 
 * - Tempo ultimo rifornimento (last_charge)
 * - Test di mercato per prodotti esauriti
 * - Eliminazione intelligente prodotti morti
 */

class InventoryStrategyManager {
    
    private $db;
    private $userId;
    
    public function __construct($db, $userId) {
        $this->db = $db;
        $this->userId = $userId;
    }
    
    /**
     * Calcola strategia rifornimento avanzata
     * 
     * @param array $item Dati prodotto da inventory
     * @return array Risultato con invio_suggerito e criticita aggiornati
     */
    public function calculateAdvancedStrategy($item) {
        $disponibili = (int)$item['disponibili'];
        $in_arrivo = (int)$item['in_arrivo'];
        $media_vendite_1d = (float)$item['media_vendite_1d'];
        $vendite_90gg = (int)$item['vendite_90gg'];
        
        // Calcola giorni dall'ultimo rifornimento
        $giorni_ultimo_rifornimento = $this->getGiorniUltimoRifornimento($item);
        
        // STRATEGIA 1: Prodotti con vendite > 0 (logica normale)
        if ($media_vendite_1d > 0) {
            return $this->strategiaProdottiConVendite($disponibili, $in_arrivo, $media_vendite_1d);
        }
        
        // STRATEGIA 2: Prodotti senza vendite (logica avanzata)
return $this->strategiaProdottiSenzaVendite(
    $disponibili,
    $in_arrivo, 
    $vendite_90gg, 
    $giorni_ultimo_rifornimento
);
    }
    
    /**
     * Strategia per prodotti con vendite attive
     */
    private function strategiaProdottiConVendite($disponibili, $in_arrivo, $media_vendite_1d) {
        // Calcolo fabbisogno 60 giorni (logica esistente)
        $fabbisogno_60gg = $media_vendite_1d * 60;
        $stock_totale = $disponibili + $in_arrivo;
        $invio_suggerito = max(0, round($fabbisogno_60gg - $stock_totale));
        
        // Calcolo criticità
        if ($disponibili == 0) {
            $criticita = 'alta';
            $criticita_priority = 1;
        } else {
            $giorni_stock = round($stock_totale / $media_vendite_1d);
            if ($giorni_stock <= 15) {
                $criticita = 'alta';
                $criticita_priority = 1;
            } elseif ($giorni_stock <= 30) {
                $criticita = 'media';
                $criticita_priority = 2;
            } else {
                $criticita = 'bassa';
                $criticita_priority = 3;
            }
        }
        
        return [
            'invio_suggerito' => $invio_suggerito,
            'criticita' => $criticita,
            'criticita_priority' => $criticita_priority,
            'strategia_applicata' => 'prodotti_con_vendite'
        ];
    }
    
    /**
     * Strategia avanzata per prodotti senza vendite
     */
    private function strategiaProdottiSenzaVendite($disponibili, $in_arrivo, $vendite_90gg, $giorni_ultimo_rifornimento) {
    
    $stock_totale = $disponibili + $in_arrivo;
    
    // SCENARIO 1: Stock totale = 0 (prodotto completamente esaurito)
    if ($stock_totale == 0) {
        return [
            'invio_suggerito' => 5, // Test di mercato
            'criticita' => 'avvia',
            'criticita_priority' => 4,
            'strategia_applicata' => 'test_mercato_esaurito'
        ];
    }
    
    // SCENARIO 1B: Stock disponibile = 0 ma in arrivo > 0
    if ($disponibili == 0 && $in_arrivo > 0) {
        $target_test = 5;
        $invio_suggerito = max(0, $target_test - $in_arrivo);
        return [
            'invio_suggerito' => $invio_suggerito,
            'criticita' => $invio_suggerito > 0 ? 'avvia' : 'neutro',
            'criticita_priority' => $invio_suggerito > 0 ? 4 : 6,
            'strategia_applicata' => 'test_mercato_parziale_arrivo'
        ];
    }
        
        // SCENARIO 2: Stock > 0 ma vendite = 0
        
        // Se non abbiamo dati su ultimo rifornimento, usa logica conservativa
        if ($giorni_ultimo_rifornimento === null) {
            return [
                'invio_suggerito' => 0,
                'criticita' => 'neutro',
                'criticita_priority' => 4,
                'strategia_applicata' => 'attesa_dati_ultimo_rifornimento'
            ];
        }
        
        // SCENARIO 2A: Rifornimento recente (< 90 giorni) - Attendi conferma
        if ($giorni_ultimo_rifornimento < 90) {
            return [
                'invio_suggerito' => 0,
                'criticita' => 'neutro',
                'criticita_priority' => 4,
                'strategia_applicata' => 'attesa_conferma_domanda_zero'
            ];
        }
        
        // SCENARIO 2B: Rifornimento vecchio (>= 90 giorni) - Considera eliminazione
return [
    'invio_suggerito' => 0,
    'criticita' => 'elimina',
    'criticita_priority' => 5,
    'strategia_applicata' => 'candidato_eliminazione'
];
    }
    
    /**
     * Calcola giorni dall'ultimo rifornimento
     */
    private function getGiorniUltimoRifornimento($item) {
        // Se abbiamo product_id, cerca last_charge nella tabella inventory
        if (!empty($item['product_id'])) {
            try {
                $stmt = $this->db->prepare("
                    SELECT MIN(last_charge) as ultimo_rifornimento
                    FROM inventory 
                    WHERE product_id = ? AND user_id = ? 
                    AND last_charge IS NOT NULL
                ");
                $stmt->execute([$item['product_id'], $this->userId]);
                $result = $stmt->fetch();
                
                if ($result && $result['ultimo_rifornimento']) {
                    $data_ultimo = new DateTime($result['ultimo_rifornimento']);
                    $oggi = new DateTime();
                    return $oggi->diff($data_ultimo)->days;
                }
            } catch (Exception $e) {
                // Log errore ma continua
                error_log("Errore calcolo ultimo rifornimento: " . $e->getMessage());
            }
        }
        
        return null; // Nessun dato disponibile
    }
    
    /**
     * Aggiorna last_charge per uno SKU specifico
     * Chiamato da inventory_sync.php quando afn_warehouse_quantity > 0
     */
    public function updateLastCharge($sku) {
        try {
            // Controlla se lo SKU ha quantità > 0 ora e aveva 0 prima
            $stmt = $this->db->prepare("
                SELECT afn_warehouse_quantity, last_charge 
                FROM inventory 
                WHERE sku = ? AND user_id = ?
            ");
            $stmt->execute([$sku, $this->userId]);
            $current = $stmt->fetch();
            
            if ($current && (int)$current['afn_warehouse_quantity'] > 0) {
                // Se last_charge è NULL o se è passato più di 1 giorno, aggiorna
                $shouldUpdate = false;
                
                if ($current['last_charge'] === null) {
                    $shouldUpdate = true;
                } else {
                    $lastCharge = new DateTime($current['last_charge']);
                    $now = new DateTime();
                    $daysDiff = $now->diff($lastCharge)->days;
                    
                    // Aggiorna se sono passati più di 1 giorno
                    if ($daysDiff >= 1) {
                        $shouldUpdate = true;
                    }
                }
                
                if ($shouldUpdate) {
                    $stmt = $this->db->prepare("
                        UPDATE inventory 
                        SET last_charge = NOW() 
                        WHERE sku = ? AND user_id = ?
                    ");
                    $stmt->execute([$sku, $this->userId]);
                    
                    return true;
                }
            }
        } catch (Exception $e) {
            error_log("Errore aggiornamento last_charge per SKU {$sku}: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Ottieni statistiche strategia per debug
     */
    public function getStrategiaStats() {
        try {
            $stats = [
                'prodotti_con_last_charge' => 0,
                'prodotti_candidati_eliminazione' => 0,
                'prodotti_test_mercato' => 0,
                'ultimo_aggiornamento_last_charge' => null
            ];
            
            // Conta prodotti con last_charge
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM inventory 
                WHERE user_id = ? AND last_charge IS NOT NULL
            ");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch();
            $stats['prodotti_con_last_charge'] = (int)$result['count'];
            
            // Ultimo aggiornamento last_charge
            $stmt = $this->db->prepare("
                SELECT MAX(last_charge) as ultimo 
                FROM inventory 
                WHERE user_id = ? AND last_charge IS NOT NULL
            ");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch();
            $stats['ultimo_aggiornamento_last_charge'] = $result['ultimo'];
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Errore ottenimento statistiche strategia: " . $e->getMessage());
            return [];
        }
    }
}