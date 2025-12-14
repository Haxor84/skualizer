<?php
/**
 * Margins Engine - Calcolo Margini Semplificato (Versione Semplificata)
 * File: modules/margynomic/margini/margins_engine.php
 */

require_once 'config_shared.php';
require_once __DIR__ . '/../../listing/helpers.php';

class MarginsEngine {
    
    private $db;
    private $userId;
    private $tableName;
    
    public function __construct($userId) {
        $this->db = getDbConnection();
        $this->userId = $userId;
        $this->tableName = "report_settlement_{$userId}";
        
        // Include helper per ordinamento se non già caricato
        if (!function_exists('getProductOrderingClause')) {
            require_once __DIR__ . '/../../listing/helpers.php';
        }
    }
    
    /**
     * Cerca prodotti per ricerca dinamica
     */
    public function searchProducts($query) {
        try {
            if (!$this->tableExists()) {
                return [];
            }
            
            $query = '%' . $query . '%';
            
            $sql = "
                SELECT DISTINCT
                    COALESCE(p.id, 0) AS product_id,
                    COALESCE(p.nome, CONCAT('Prodotto ID: ', COALESCE(p.id, 0))) AS product_name,
                    GROUP_CONCAT(DISTINCT s.sku ORDER BY s.sku SEPARATOR ', ') AS all_skus
                FROM {$this->tableName} s
                LEFT JOIN products p ON p.id = s.product_id
                WHERE (p.nome LIKE ? OR s.sku LIKE ?)
                GROUP BY COALESCE(p.id, 0)
                ORDER BY p.nome ASC
                LIMIT 10
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$query, $query]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Calcola margini per prodotti con categorizzazione fee
     */
    public function calculateMargins($filters = []) {
        try {
            if (!$this->tableExists()) {
                return ['success' => false, 'error' => 'Tabella settlement non trovata'];
            }
            
            $categories = getAllFeeCategories();
            $data = $this->getProductMargins($filters, $categories);
            $globalFees = $this->calculateGlobalFees($filters, $categories);
            $processedData = $this->applyFeeDistribution($data, $globalFees);
            
            return [
                'success' => true,
                'data' => $processedData,
                'categories' => $categories,
                'global_fees' => $globalFees,
                'summary' => $this->calculateSummary($processedData)
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Query principale per margini prodotto - AGGREGAZIONE PER PRODUCT_ID
     */
    private function getProductMargins($filters, $categories) {
        $whereClause = $this->buildWhereClause($filters);
        
        $sql = "
            SELECT 
                p.id AS product_id,
                COALESCE(p.nome, CONCAT('Prodotto ID: ', p.id)) AS product_name,
                GROUP_CONCAT(DISTINCT s.sku ORDER BY s.sku SEPARATOR ', ') AS all_skus,
                p.asin,
                
                -- Revenue storica dal settlement (somma price_amount delle transazioni Order)
                SUM(CASE WHEN s.transaction_type = 'Order' THEN COALESCE(s.price_amount, 0) ELSE 0 END) AS revenue,
                
                -- Unità vendute aggregate per product_id
                SUM(CASE WHEN s.transaction_type = 'Order' THEN 
                    COALESCE(s.quantity_purchased, 0) ELSE 0 END) AS units_sold,
                
                -- Fee per categoria (mantieni valori originali Amazon)";
        
        foreach ($categories as $cat) {
            $categoryCode = $cat['category_code'];
            
            if ($cat['group_type'] === 'TAB1') {
                // Fee Tab1: commissioni dirette da Amazon.it, REVERSAL da tutti i marketplace
                $sql .= ",
            SUM(
              CASE 
                WHEN COALESCE(tfm_u.category, tfm_g.category) = '{$categoryCode}'
                     AND s.transaction_type NOT IN ('Refund','REVERSAL_REIMBURSEMENT')
                     AND s.marketplace_name = 'Amazon.it'
              THEN ABS(COALESCE(s.item_related_fee_amount, 0) + COALESCE(s.other_amount, 0))
              ELSE 0
              END
            ) AS fee_{$categoryCode}";
            } else {
                // Fee Tab2/Tab3: mantieni segni originali Amazon
                $sql .= ",
            SUM(CASE WHEN COALESCE(tfm_u.category, tfm_g.category) = '{$categoryCode}' THEN 
                (COALESCE(s.item_related_fee_amount, 0) + COALESCE(s.other_amount, 0))
                ELSE 0 END) AS fee_{$categoryCode}";
            }
        }
        
        $sql .= ",
        -- Dati prodotto
        COALESCE(p.costo_prodotto, 0) AS costo_prodotto,
        COALESCE(p.prezzo_attuale, 0) AS prezzo_attuale,
        
        -- Prezzo storico medio per calcolo fee accurate
        CASE 
            WHEN SUM(CASE WHEN s.transaction_type = 'Order' THEN COALESCE(s.quantity_purchased, 0) ELSE 0 END) > 0
            THEN SUM(CASE WHEN s.transaction_type = 'Order' THEN COALESCE(s.price_amount, 0) ELSE 0 END) / 
                 SUM(CASE WHEN s.transaction_type = 'Order' THEN COALESCE(s.quantity_purchased, 0) ELSE 0 END)
            ELSE COALESCE(p.prezzo_attuale, 0)
        END AS prezzo_storico_medio,
        
        -- Custom Fee fields
        COALESCE(p.custom_fee_type, 'none') AS custom_fee_type,
        COALESCE(p.custom_fee_value, 0.000) AS custom_fee_value,
        p.custom_fee_description,
        
        -- Marketplace
        GROUP_CONCAT(DISTINCT s.marketplace_name) AS marketplaces,
        
        -- Posizione per ordinamento
        pdo.position as display_position
                
            FROM products p
            LEFT JOIN `{$this->tableName}` s ON s.product_id = p.id
            LEFT JOIN product_display_order pdo ON (pdo.user_id = ? AND pdo.product_id = p.id)
            LEFT JOIN transaction_fee_mappings tfm_u ON s.transaction_type = tfm_u.transaction_type 
                AND tfm_u.user_id = ? AND tfm_u.is_active = 1
            LEFT JOIN transaction_fee_mappings tfm_g ON s.transaction_type = tfm_g.transaction_type 
                AND tfm_g.user_id IS NULL AND tfm_g.is_active = 1
            WHERE p.user_id = ? 
              AND p.nome NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
              AND p.fnsku IS NOT NULL AND p.fnsku != '' {$whereClause}
            GROUP BY p.id, p.nome, p.asin, 
                     p.prezzo_attuale, p.costo_prodotto,
                     p.custom_fee_type, p.custom_fee_value, p.custom_fee_description,
                     pdo.position
            -- HAVING revenue > 0  ← FILTRO RIMOSSO: ora mostra TUTTI i prodotti (anche senza vendite)
            ORDER BY 
                CASE 
                    WHEN pdo.position IS NOT NULL THEN 0 
                    ELSE 1 
                END,
                pdo.position ASC,
                COALESCE(p.nome, CONCAT('Prodotto ID: ', p.id)) ASC,
                p.id ASC
        ";
        
        $params = [$this->userId, $this->userId, $this->userId];
        $params = array_merge($params, $this->getWhereParams($filters));
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        return $results;
    }
    
    /**
     * Calcola fee globali per distribuzione
     */
    private function calculateGlobalFees($filters, $categories) {
        $whereClause = $this->buildWhereClause($filters);
        
        $sql = "SELECT ";
        $selectParts = [];
        
        foreach ($categories as $cat) {
            if ($cat['group_type'] === 'TAB2' || $cat['group_type'] === 'TAB3') {
                $categoryCode = $cat['category_code'];
                $selectParts[] = "
                    SUM(CASE WHEN COALESCE(tfm_u.category, tfm_g.category) = '{$categoryCode}' THEN 
                        (COALESCE(s.other_amount, 0) + COALESCE(s.item_related_fee_amount, 0))
                        ELSE 0 END) AS total_{$categoryCode}";
            }
        }
        
        $selectParts[] = "
            SUM(CASE WHEN s.transaction_type = 'Order' THEN s.price_amount ELSE 0 END) AS total_revenue";
        
        $selectParts[] = "
            SUM(CASE WHEN s.transaction_type = 'Order' THEN 
                COALESCE(s.quantity_purchased, 0) ELSE 0 END) AS total_units";
        
        // TAB1 shared (rimborsi/storni distribuiti per unità)
        $selectParts[] = "
            SUM(CASE WHEN COALESCE(tfm_u.category, tfm_g.category) IN (
                SELECT category_code FROM fee_categories WHERE group_type = 'TAB1'
            ) AND s.transaction_type IN ('Refund', 'REVERSAL_REIMBURSEMENT') THEN 
                CASE WHEN s.transaction_type = 'Refund' THEN 
                    COALESCE(s.price_amount, 0) - COALESCE(s.item_related_fee_amount, 0)
                ELSE 
                    COALESCE(s.other_amount, 0) 
                END
            ELSE 0 END) AS total_TAB1_SHARED";
        
        $sql .= implode(', ', $selectParts);
        $sql .= " FROM `{$this->tableName}` s
                  LEFT JOIN transaction_fee_mappings tfm_u ON s.transaction_type = tfm_u.transaction_type 
                      AND tfm_u.user_id = ? AND tfm_u.is_active = 1
                  LEFT JOIN transaction_fee_mappings tfm_g ON s.transaction_type = tfm_g.transaction_type 
                      AND tfm_g.user_id IS NULL AND tfm_g.is_active = 1
                  WHERE 1=1 {$whereClause}";
        
        $stmt = $this->db->prepare($sql);
        $params = [$this->userId];
        $params = array_merge($params, $this->getWhereParams($filters));
        $stmt->execute($params);
        
        return $stmt->fetch();
    }
    
    /**
     * Applica distribuzione fee globali e calcola margini finali
     */
    private function applyFeeDistribution($data, $globalFees) {
        $totalRevenue = max($globalFees['total_revenue'], 1);
        $totalUnits = max($globalFees['total_units'], 1);
        
        foreach ($data as &$row) {
            // Calcola costo totale materiale
            $row['total_cost'] = $row['costo_prodotto'] * $row['units_sold'];
            
            // Calcola fee totali
            $totalFees = 0;
            
            foreach (getAllFeeCategories() as $cat) {
                $categoryCode = $cat['category_code'];
                $feeColumn = "fee_{$categoryCode}";
                
                // Ignora categorie IGNORE
                if ($cat['group_type'] === 'IGNORE') {
                    $row[$feeColumn] = 0;
                    continue;
                }
                
                // Fee Tab1 - già calcolate per prodotto (sono costi, quindi negative)
                if ($cat['group_type'] === 'TAB1') {
                    $totalFees -= $row[$feeColumn] ?? 0;  // Sottrai perché sono costi
                    
                    // Aggiungi quota TAB1 shared (rimborsi/storni distribuiti per unità)
                    $tab1SharedGlobal = $globalFees['total_TAB1_SHARED'] ?? 0;
                    if ($tab1SharedGlobal != 0) {
                        $tab1SharedQuota = ($row['units_sold'] / $totalUnits) * $tab1SharedGlobal;
                        $totalFees += $tab1SharedQuota;
                        $row['fee_TAB1_SHARED'] = $tab1SharedQuota;
                    }
                    continue;
                }
                
                // Fee Tab2 e Tab3 - distribuzione proporzionale
                if ($cat['group_type'] === 'TAB2' || $cat['group_type'] === 'TAB3') {
                    $globalTotal = $globalFees["total_{$categoryCode}"] ?? 0;
                    $row[$feeColumn] = ($row['revenue'] / $totalRevenue) * $globalTotal;
                    $totalFees += $row[$feeColumn];
                }
            }
            
            // Calcolo Custom Fee basato su prezzo ATTUALE (non storico)
            $customFeeAmount = 0;
            if ($row['custom_fee_type'] === 'percent') {
                $unitPrice = $row['prezzo_attuale'] ?: 0; // Usa prezzo_attuale invece di prezzo_storico_medio
                $customFeeAmount = ($unitPrice * $row['custom_fee_value'] / 100) * $row['units_sold'] * -1;
            } elseif ($row['custom_fee_type'] === 'fixed') {
                $customFeeAmount = $row['custom_fee_value'] * $row['units_sold'] * -1;
            }
            
            // Fee totali con custom fee
            $totalFeesWithCustom = $totalFees + $customFeeAmount;
            
            // Salva valori per frontend
            $row['total_fees_amazon'] = $totalFees;
            $row['custom_fee_amount'] = $customFeeAmount;
            $row['total_fees'] = $totalFeesWithCustom;
            
            // Calcolo margine finale - usa revenue PROIETTATO se prezzo è cambiato
            $revenueProiettato = $row['prezzo_attuale'] * $row['units_sold'];
            $revenuePerCalcolo = ($revenueProiettato > 0) ? $revenueProiettato : $row['revenue'];
            
            $row['net_profit'] = $revenuePerCalcolo - $row['total_cost'] + $row['total_fees'];
            $row['margin_percentage'] = $revenuePerCalcolo > 0 ? 
                ($row['net_profit'] / $revenuePerCalcolo) * 100 : 0;
                
            // Salva anche i valori per debug
            $row['revenue_proiettato'] = $revenueProiettato;
            $row['revenue_storico'] = $row['revenue'];
            
            // Calcoli per unità
            if ($row['units_sold'] > 0) {
                $row['profit_per_unit'] = $row['net_profit'] / $row['units_sold'];
                $row['fees_per_unit'] = $row['total_fees'] / $row['units_sold'];
                $row['custom_fee_per_unit'] = $customFeeAmount / $row['units_sold'];
                $row['revenue_per_unit'] = $revenuePerCalcolo / $row['units_sold'];
            } else {
                $row['profit_per_unit'] = 0;
                $row['fees_per_unit'] = 0;
                $row['custom_fee_per_unit'] = 0;
            }
        }
        
        return $data;
    }
    
    /**
     * Calcola summary KPI
     */
    private function calculateSummary($data) {
        return [
            'total_revenue' => array_sum(array_column($data, 'revenue')),
            'total_profit' => array_sum(array_column($data, 'net_profit')),
            'total_fees' => array_sum(array_column($data, 'total_fees')),
            'total_custom_fees' => array_sum(array_column($data, 'custom_fee_amount')),
            'total_units' => array_sum(array_column($data, 'units_sold')),
            'products_count' => count($data),
            'avg_margin' => count($data) > 0 ? 
                array_sum(array_column($data, 'margin_percentage')) / count($data) : 0
        ];
    }
    
    /**
     * Calcola KPI per periodi temporali (7d, 30d, 90d, storico)
     * Replica la logica della query SQL query_fee_totali_periodi.sql
     */
    public function calculatePeriodKPIs() {
        if (!$this->tableExists()) {
            return [];
        }
        
        $sql = "
        WITH 
        date_info AS (
            SELECT MAX(posted_date) as ultima_sync
            FROM {$this->tableName}
            WHERE transaction_type = 'Order' AND product_id IS NOT NULL
        ),
        fee_categories_list AS (
            SELECT category_code, group_type, category_name
            FROM fee_categories
            WHERE is_active = 1
        ),
        base_transactions AS (
            SELECT 
                s.posted_date,
                s.product_id,
                s.transaction_type,
                s.marketplace_name,
                s.price_amount,
                s.quantity_purchased,
                s.item_related_fee_amount,
                s.other_amount,
                COALESCE(tfm_u.category, tfm_g.category) as fee_category,
                fc.group_type,
                p.custom_fee_type,
                p.custom_fee_value
            FROM {$this->tableName} s
            LEFT JOIN transaction_fee_mappings tfm_u 
                ON s.transaction_type = tfm_u.transaction_type 
                AND tfm_u.user_id = ? AND tfm_u.is_active = 1
            LEFT JOIN transaction_fee_mappings tfm_g 
                ON s.transaction_type = tfm_g.transaction_type 
                AND tfm_g.user_id IS NULL AND tfm_g.is_active = 1
            LEFT JOIN fee_categories_list fc 
                ON COALESCE(tfm_u.category, tfm_g.category) = fc.category_code
            LEFT JOIN products p ON s.product_id = p.id
            WHERE s.product_id IS NOT NULL
        ),
        stats_7_days AS (
            SELECT 
                '7_days' as periodo,
                ROUND(SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(price_amount, 0) ELSE 0 END), 2) as revenue_totale,
                SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(quantity_purchased, 0) ELSE 0 END) as units_totali,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type NOT IN ('Refund', 'REVERSAL_REIMBURSEMENT') AND marketplace_name = 'Amazon.it'
                    THEN ABS(COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0)) ELSE 0 END) as tab1_total,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type IN ('Refund', 'REVERSAL_REIMBURSEMENT')
                    THEN CASE WHEN transaction_type = 'Refund' THEN COALESCE(price_amount, 0) - COALESCE(item_related_fee_amount, 0)
                        ELSE COALESCE(other_amount, 0) END ELSE 0 END) as tab1_shared_total,
                SUM(CASE WHEN group_type = 'TAB2' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab2_total,
                SUM(CASE WHEN group_type = 'TAB3' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab3_total,
                SUM(CASE WHEN transaction_type = 'Order' AND custom_fee_type = 'percent' 
                    THEN COALESCE(price_amount, 0) * (custom_fee_value / 100) * -1
                    WHEN transaction_type = 'Order' AND custom_fee_type = 'fixed' 
                    THEN custom_fee_value * COALESCE(quantity_purchased, 0) * -1 ELSE 0 END) as custom_fee_total
            FROM base_transactions
            CROSS JOIN date_info
            WHERE posted_date > DATE_SUB(date_info.ultima_sync, INTERVAL 7 DAY)
        ),
        stats_30_days AS (
            SELECT 
                '30_days' as periodo,
                ROUND(SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(price_amount, 0) ELSE 0 END), 2) as revenue_totale,
                SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(quantity_purchased, 0) ELSE 0 END) as units_totali,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type NOT IN ('Refund', 'REVERSAL_REIMBURSEMENT') AND marketplace_name = 'Amazon.it'
                    THEN ABS(COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0)) ELSE 0 END) as tab1_total,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type IN ('Refund', 'REVERSAL_REIMBURSEMENT')
                    THEN CASE WHEN transaction_type = 'Refund' THEN COALESCE(price_amount, 0) - COALESCE(item_related_fee_amount, 0)
                        ELSE COALESCE(other_amount, 0) END ELSE 0 END) as tab1_shared_total,
                SUM(CASE WHEN group_type = 'TAB2' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab2_total,
                SUM(CASE WHEN group_type = 'TAB3' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab3_total,
                SUM(CASE WHEN transaction_type = 'Order' AND custom_fee_type = 'percent' 
                    THEN COALESCE(price_amount, 0) * (custom_fee_value / 100) * -1
                    WHEN transaction_type = 'Order' AND custom_fee_type = 'fixed' 
                    THEN custom_fee_value * COALESCE(quantity_purchased, 0) * -1 ELSE 0 END) as custom_fee_total
            FROM base_transactions
            CROSS JOIN date_info
            WHERE posted_date > DATE_SUB(date_info.ultima_sync, INTERVAL 30 DAY)
        ),
        stats_90_days AS (
            SELECT 
                '90_days' as periodo,
                ROUND(SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(price_amount, 0) ELSE 0 END), 2) as revenue_totale,
                SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(quantity_purchased, 0) ELSE 0 END) as units_totali,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type NOT IN ('Refund', 'REVERSAL_REIMBURSEMENT') AND marketplace_name = 'Amazon.it'
                    THEN ABS(COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0)) ELSE 0 END) as tab1_total,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type IN ('Refund', 'REVERSAL_REIMBURSEMENT')
                    THEN CASE WHEN transaction_type = 'Refund' THEN COALESCE(price_amount, 0) - COALESCE(item_related_fee_amount, 0)
                        ELSE COALESCE(other_amount, 0) END ELSE 0 END) as tab1_shared_total,
                SUM(CASE WHEN group_type = 'TAB2' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab2_total,
                SUM(CASE WHEN group_type = 'TAB3' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab3_total,
                SUM(CASE WHEN transaction_type = 'Order' AND custom_fee_type = 'percent' 
                    THEN COALESCE(price_amount, 0) * (custom_fee_value / 100) * -1
                    WHEN transaction_type = 'Order' AND custom_fee_type = 'fixed' 
                    THEN custom_fee_value * COALESCE(quantity_purchased, 0) * -1 ELSE 0 END) as custom_fee_total
            FROM base_transactions
            CROSS JOIN date_info
            WHERE posted_date > DATE_SUB(date_info.ultima_sync, INTERVAL 90 DAY)
        ),
        stats_storico AS (
            SELECT 
                'storico_completo' as periodo,
                ROUND(SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(price_amount, 0) ELSE 0 END), 2) as revenue_totale,
                SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(quantity_purchased, 0) ELSE 0 END) as units_totali,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type NOT IN ('Refund', 'REVERSAL_REIMBURSEMENT') AND marketplace_name = 'Amazon.it'
                    THEN ABS(COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0)) ELSE 0 END) as tab1_total,
                SUM(CASE WHEN group_type = 'TAB1' AND transaction_type IN ('Refund', 'REVERSAL_REIMBURSEMENT')
                    THEN CASE WHEN transaction_type = 'Refund' THEN COALESCE(price_amount, 0) - COALESCE(item_related_fee_amount, 0)
                        ELSE COALESCE(other_amount, 0) END ELSE 0 END) as tab1_shared_total,
                SUM(CASE WHEN group_type = 'TAB2' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab2_total,
                SUM(CASE WHEN group_type = 'TAB3' THEN COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0) ELSE 0 END) as tab3_total,
                SUM(CASE WHEN transaction_type = 'Order' AND custom_fee_type = 'percent' 
                    THEN COALESCE(price_amount, 0) * (custom_fee_value / 100) * -1
                    WHEN transaction_type = 'Order' AND custom_fee_type = 'fixed' 
                    THEN custom_fee_value * COALESCE(quantity_purchased, 0) * -1 ELSE 0 END) as custom_fee_total
            FROM base_transactions
        )
        SELECT periodo, revenue_totale, units_totali,
            ROUND(CASE WHEN units_totali > 0 THEN revenue_totale / units_totali ELSE 0 END, 2) as prezzo_medio,
            ROUND(CASE WHEN units_totali > 0 THEN (-tab1_total + tab1_shared_total + tab2_total + tab3_total + custom_fee_total) / units_totali ELSE 0 END, 2) as fee_per_unit
        FROM (
            SELECT * FROM stats_7_days
            UNION ALL SELECT * FROM stats_30_days
            UNION ALL SELECT * FROM stats_90_days
            UNION ALL SELECT * FROM stats_storico
        ) all_periods
        ORDER BY CASE periodo WHEN '7_days' THEN 1 WHEN '30_days' THEN 2 WHEN '90_days' THEN 3 ELSE 4 END
        ";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $kpis = [];
            foreach ($results as $row) {
                $kpis[$row['periodo']] = [
                    'prezzo_medio' => (float)$row['prezzo_medio'],
                    'fee_per_unit' => (float)$row['fee_per_unit'],
                    'revenue' => (float)$row['revenue_totale'],
                    'units' => (int)$row['units_totali']
                ];
            }
            return $kpis;
        } catch (PDOException $e) {
            error_log("Error in calculatePeriodKPIs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verifica esistenza tabella
     */
    private function tableExists() {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = DATABASE() AND table_name = ?
            ");
            $stmt->execute([$this->tableName]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Costruisce WHERE clause per filtri
     */
    private function buildWhereClause($filters) {
        $conditions = [];
        
        // Solo filtro SKU nella versione semplificata
        if (!empty($filters['sku'])) {
            $conditions[] = "(s.sku LIKE ? OR p.nome LIKE ?)";
        }
        
        return empty($conditions) ? '' : ' AND ' . implode(' AND ', $conditions);
    }
    
    /**
     * Parametri WHERE per filtri
     */
    private function getWhereParams($filters) {
        $params = [];
        
        // Solo filtro SKU nella versione semplificata
        if (!empty($filters['sku'])) {
            $params[] = '%' . $filters['sku'] . '%';
            $params[] = '%' . $filters['sku'] . '%';
        }
        
        return $params;
    }
    
    /**
     * Ottiene breakdown dettagliato fee per prodotto
     */
    public function getFeeBreakdown($productId) {
        $breakdown = [];
        $categories = getAllFeeCategories();
        
        foreach ($categories as $cat) {
            $categoryCode = $cat['category_code'];
            
            if ($cat['group_type'] === 'TAB1') {
                // Tab1: breakdown per transaction_type e fee_type
                $stmt = $this->db->prepare("
                    SELECT 
                        s.transaction_type,
                        s.item_related_fee_type,
                        CAST(SUM(ABS(COALESCE(s.item_related_fee_amount, 0) + COALESCE(s.other_amount, 0))) AS DECIMAL(10,2)) as amount,
                        COUNT(*) as occurrences
                    FROM `{$this->tableName}` s
                    LEFT JOIN transaction_fee_mappings tfm_u ON s.transaction_type = tfm_u.transaction_type 
                        AND tfm_u.user_id = ? AND tfm_u.is_active = 1
                    LEFT JOIN transaction_fee_mappings tfm_g ON s.transaction_type = tfm_g.transaction_type 
                        AND tfm_g.user_id IS NULL AND tfm_g.is_active = 1
                    WHERE s.product_id = ? 
                        AND COALESCE(tfm_u.category, tfm_g.category) = ?
                        AND s.transaction_type NOT IN ('Refund','REVERSAL_REIMBURSEMENT')
                        AND s.marketplace_name = 'Amazon.it'
                    GROUP BY s.transaction_type, s.item_related_fee_type
                    HAVING amount > 0
                    ORDER BY amount DESC
                ");
                $stmt->execute([$this->userId, $productId, $categoryCode]);
            } else {
                // Tab2/Tab3: breakdown per transaction_type
                $stmt = $this->db->prepare("
                    SELECT 
                        s.transaction_type,
                        s.item_related_fee_type,
                        CAST(SUM(COALESCE(s.item_related_fee_amount, 0) + COALESCE(s.other_amount, 0)) AS DECIMAL(10,2)) as amount,
                        COUNT(*) as occurrences
                    FROM `{$this->tableName}` s
                    LEFT JOIN transaction_fee_mappings tfm_u ON s.transaction_type = tfm_u.transaction_type 
                        AND tfm_u.user_id = ? AND tfm_u.is_active = 1
                    LEFT JOIN transaction_fee_mappings tfm_g ON s.transaction_type = tfm_g.transaction_type 
                        AND tfm_g.user_id IS NULL AND tfm_g.is_active = 1
                    WHERE s.product_id = ? 
                        AND COALESCE(tfm_u.category, tfm_g.category) = ?
                    GROUP BY s.transaction_type, s.item_related_fee_type
                    HAVING amount != 0
                    ORDER BY amount DESC
                ");
                $stmt->execute([$this->userId, $productId, $categoryCode]);
            }
            
            $results = $stmt->fetchAll();
            if (!empty($results)) {
                $breakdown[$categoryCode] = $results;
            }
        }
        
        return $breakdown;
    }

}

// Funzione logMarginsOperation già definita in config_shared.php
?>