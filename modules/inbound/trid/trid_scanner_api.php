<?php
/**
 * TridScanner API - User-Facing Version
 * File: modules/margynomic/trid/trid_scanner_api.php
 * 
 * API endpoint per utenti normali (non admin)
 * Wrapper sicuro che chiama le stesse funzioni di trid_api.php
 */

session_start();
require_once __DIR__ . '/../../margynomic/config/config.php';
require_once __DIR__ . '/../../margynomic/login/auth_helpers.php';

// Include Mobile Cache System
require_once __DIR__ . '/../../mobile/helpers/mobile_cache_helper.php';

header('Content-Type: application/json');

// Check authentication - SOLO UTENTI LOGGATI
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Accesso negato. Login richiesto.']);
    exit;
}

// User può vedere solo i propri dati
$currentUser = getCurrentUser();
$userId = $currentUser['user_id'] ?? $currentUser['id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User ID non trovato nella sessione']);
    exit;
}

$db = getDbConnection();

// ============================================
// DASHBOARD DATA FUNCTIONS
// ============================================

/**
 * Dashboard globale con KPI, spedizioni e rimborsi
 * (Copia della funzione da trid_api.php per utenti normali)
 */
function getDashboardGlobalData($db, $userId) {
    try {
        // 1. KPI AGGREGATI
        $kpiData = [
            'total_shipments' => 0,
            'total_received' => 0
        ];
        
        // 1. UNITÀ SPEDITE (quantity_shipped da inbound_shipment_items)
        $stmtSpedite = $db->prepare("
            SELECT COALESCE(SUM(isi.quantity_shipped), 0) as unita_spedite
            FROM inbound_shipment_items isi
            INNER JOIN inbound_shipments ibs ON ibs.id = isi.shipment_id
            WHERE isi.user_id = ?
              AND ibs.shipment_status NOT IN ('CANCELLED', 'DELETED')
        ");
        $stmtSpedite->execute([$userId]);
        $kpiData['unita_spedite'] = (int)$stmtSpedite->fetchColumn();
        
        // 2. UNITÀ RICEVUTE (quantity_received da inbound_shipment_items)
        $stmtRicevute = $db->prepare("
            SELECT COALESCE(SUM(isi.quantity_received), 0) as unita_ricevute
            FROM inbound_shipment_items isi
            INNER JOIN inbound_shipments ibs ON ibs.id = isi.shipment_id
            WHERE isi.user_id = ?
              AND ibs.shipment_status NOT IN ('CANCELLED', 'DELETED')
        ");
        $stmtRicevute->execute([$userId]);
        $kpiData['unita_ricevute'] = (int)$stmtRicevute->fetchColumn();
        
        // 3. UNITÀ RITIRATE (shipped_quantity da removal_orders)
        $stmtRitirate = $db->prepare("
            SELECT COALESCE(SUM(shipped_quantity), 0) as unita_ritirate
            FROM removal_orders
            WHERE user_id = ?
              AND (order_status IS NULL OR order_status != 'Cancelled')
        ");
        $stmtRitirate->execute([$userId]);
        $kpiData['unita_ritirate'] = (int)$stmtRitirate->fetchColumn();
        
        // 4. GIACENZA ATTUALE (afn_warehouse_quantity da inventory)
        $stmtGiacenza = $db->prepare("
            SELECT COALESCE(SUM(afn_warehouse_quantity), 0) as giacenza
            FROM inventory
            WHERE user_id = ?
        ");
        $stmtGiacenza->execute([$userId]);
        $kpiData['giacenza'] = (int)$stmtGiacenza->fetchColumn();
        
        // 5. UNITÀ VENDUTE + RESI DEI CLIENTI + RIMBORSI AMAZON (da settlement se esiste)
        $kpiData['unita_vendute'] = 0;
        $kpiData['resi_clienti'] = 0;
        $kpiData['unita_rimborsate'] = 0;
        
        $tableName = "report_settlement_{$userId}";
        $checkTable = $db->query("SHOW TABLES LIKE '{$tableName}'");
        $tableExists = $checkTable->fetchColumn();
        
        if ($tableExists) {
            // Unità vendute NETTE
            $stmtVendute = $db->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'Order' THEN quantity_purchased ELSE 0 END), 0)
                    - (
                        (SELECT COALESCE(SUM(refund_count), 0) FROM (
                            SELECT COUNT(DISTINCT CASE WHEN transaction_type = 'Refund' AND price_type = 'Principal' THEN order_id END) as refund_count
                            FROM `{$tableName}`
                            WHERE product_id IN (SELECT id FROM products WHERE user_id = ?)
                            GROUP BY product_id
                        ) as refund_per_product)
                        - COALESCE(SUM(CASE WHEN transaction_type = 'REVERSAL_REIMBURSEMENT' THEN quantity_purchased ELSE 0 END), 0)
                    ) as unita_vendute_nette
                FROM `{$tableName}`
                WHERE product_id IN (SELECT id FROM products WHERE user_id = ?)
            ");
            $stmtVendute->execute([$userId, $userId]);
            $kpiData['unita_vendute'] = (int)$stmtVendute->fetchColumn();
            
            // Resi dei clienti
            $stmtResi = $db->prepare("
                SELECT COALESCE(SUM(refund_count), 0) as resi
                FROM (
                    SELECT COUNT(DISTINCT CASE WHEN transaction_type = 'Refund' AND price_type = 'Principal' THEN order_id END) as refund_count
                    FROM `{$tableName}`
                    WHERE product_id IN (SELECT id FROM products WHERE user_id = ?)
                    GROUP BY product_id
                ) as refund_per_product
            ");
            $stmtResi->execute([$userId]);
            $kpiData['resi_clienti'] = (int)$stmtResi->fetchColumn();
            
            // Rimborsi Amazon (TAB3)
            $stmtRimborsi = $db->prepare("
                SELECT COALESCE(SUM(SIGN(s.other_amount) * ABS(s.quantity_purchased)), 0) as unita_rimborsate
                FROM `{$tableName}` s
                INNER JOIN transaction_fee_mappings tfm 
                    ON tfm.transaction_type = s.transaction_type
                    AND (tfm.user_id = ? OR tfm.user_id IS NULL)
                INNER JOIN fee_categories fc 
                    ON fc.category_code = tfm.category
                    AND fc.group_type = 'TAB3'
                WHERE s.product_id IN (SELECT id FROM products WHERE user_id = ?)
            ");
            $stmtRimborsi->execute([$userId, $userId]);
            $kpiData['unita_rimborsate'] = (int)$stmtRimborsi->fetchColumn();
        }
        
        // 6. SPEDIZIONI TOTALI
        $stmtShipments = $db->prepare("
            SELECT COUNT(DISTINCT ibs.id) as total_shipments
            FROM inbound_shipments ibs
            WHERE ibs.user_id = ?
              AND ibs.shipment_status NOT IN ('CANCELLED', 'DELETED')
        ");
        $stmtShipments->execute([$userId]);
        $kpiData['total_shipments'] = $stmtShipments->fetchColumn() ?: 0;
        
        // 7. RIMBORSI DA RICHIEDERE (TRID events)
        $stmtReimburse = $db->prepare("
            SELECT COUNT(DISTINCT reference_id) as reimbursable_count
            FROM shipments_trid
            WHERE user_id = ?
              AND event_type = 'Adjustments'
              AND reason IN ('Q','D','E','M','O','P')
              AND reference_id IS NOT NULL
        ");
        $stmtReimburse->execute([$userId]);
        $kpiData['reimbursable_count'] = $stmtReimburse->fetchColumn() ?: 0;
        
        // 2. LISTA SPEDIZIONI
        $stmtShipmentsList = $db->prepare("
            SELECT 
                ibs.id,
                ibs.amazon_shipment_id,
                ibs.shipment_name,
                ibs.destination_fc,
                ibs.shipment_status,
                ibs.last_sync_at as receipt_date,
                COALESCE(trid_stats.event_count, 0) as trid_events,
                COALESCE(trid_stats.warehouses, ibs.destination_fc) as warehouses,
                COALESCE(items_stats.qty_received, 0) as qty_received,
                COALESCE(items_stats.qty_shipped, 0) as qty_shipped
            FROM inbound_shipments ibs
            LEFT JOIN (
                SELECT 
                    st.inbound_shipment_id,
                    COUNT(DISTINCT st.id) as event_count,
                    GROUP_CONCAT(DISTINCT st.fulfillment_center ORDER BY st.fulfillment_center) as warehouses
                FROM shipments_trid st
                WHERE st.user_id = ? AND st.event_type = 'Receipts'
                GROUP BY st.inbound_shipment_id
            ) AS trid_stats ON ibs.id = trid_stats.inbound_shipment_id
            LEFT JOIN (
                SELECT 
                    isi.shipment_id,
                    SUM(isi.quantity_received) as qty_received,
                    SUM(isi.quantity_shipped) as qty_shipped
                FROM inbound_shipment_items isi
                WHERE isi.user_id = ?
                GROUP BY isi.shipment_id
            ) AS items_stats ON ibs.id = items_stats.shipment_id
            WHERE ibs.user_id = ?
              AND ibs.shipment_status NOT IN ('CANCELLED', 'DELETED')
            ORDER BY ibs.last_sync_at DESC
            LIMIT 10000
        ");
        $stmtShipmentsList->execute([$userId, $userId, $userId]);
        $shipments = $stmtShipmentsList->fetchAll(PDO::FETCH_ASSOC);
        
        // Statistiche spedizioni
        $totalReceived = array_sum(array_column($shipments, 'qty_received'));
        $totalShipments = count($shipments);
        
        // 3. RIMBORSI PER MESE/PRODOTTO
        $stmtReimbursements = $db->prepare("
            SELECT 
                DATE_FORMAT(st.datetime, '%Y-%m') as month,
                DATE(st.datetime) as event_date,
                st.msku,
                st.fulfillment_center,
                st.disposition,
                st.reason,
                st.quantity,
                st.reference_id,
                p.nome as product_name
            FROM shipments_trid st
            LEFT JOIN products p ON st.product_id = p.id AND p.user_id = st.user_id
            WHERE st.user_id = ?
              AND st.event_type = 'Adjustments'
              AND st.reason IN ('Q','D','E','M','O','P')
              AND st.reference_id IS NOT NULL
            ORDER BY st.datetime DESC
        ");
        $stmtReimbursements->execute([$userId]);
        $reimbursements = $stmtReimbursements->fetchAll(PDO::FETCH_ASSOC);
        
        // Raggruppa rimborsi per mese
        $reimbursementsByMonth = [];
        foreach ($reimbursements as $r) {
            $month = $r['month'];
            if (!isset($reimbursementsByMonth[$month])) {
                $reimbursementsByMonth[$month] = [
                    'events' => [],
                    'count' => 0,
                    'total_qty' => 0
                ];
            }
            $reimbursementsByMonth[$month]['events'][] = $r;
            $reimbursementsByMonth[$month]['count']++;
            $reimbursementsByMonth[$month]['total_qty'] += abs($r['quantity']);
        }
        
        return [
            'kpi' => $kpiData,
            'shipments' => $shipments,
            'shipments_stats' => [
                'total_received' => $totalReceived,
                'total_shipments' => $totalShipments
            ],
            'reimbursements_by_month' => $reimbursementsByMonth,
            'total_reimbursements' => count($reimbursements)
        ];
        
    } catch (Exception $e) {
        error_log("getDashboardGlobalData Error: " . $e->getMessage());
        throw $e;
    }
}

// ============================================
// ROUTING
// ============================================

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'load_dashboard':
            // === SISTEMA CACHE (TTL: 48 ore - invalidazione event-driven) ===
            $cacheData = getMobileCache($userId, 'trid_shipments', 172800); // 48h
            
            if ($cacheData !== null) {
                // Cache HIT - ritorna dati cachati
                echo json_encode(['success' => true, 'data' => $cacheData]);
            } else {
                // Cache MISS - calcola dati freschi
                $data = getDashboardGlobalData($db, $userId);
                
                // Salva in cache
                setMobileCache($userId, 'trid_shipments', $data);
                
                echo json_encode(['success' => true, 'data' => $data]);
            }
            break;
            
        case 'shipment_items':
            $shipmentId = (int)($_GET['shipment_id'] ?? 0);
            if ($shipmentId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid shipment ID']);
                break;
            }
            
            // Get shipment info
            $stmtShipment = $db->prepare("
                SELECT 
                    ibs.amazon_shipment_id,
                    ibs.shipment_name,
                    ibs.destination_fc
                FROM inbound_shipments ibs
                WHERE ibs.id = ? AND ibs.user_id = ?
            ");
            $stmtShipment->execute([$shipmentId, $userId]);
            $shipmentInfo = $stmtShipment->fetch(PDO::FETCH_ASSOC);
            
            if (!$shipmentInfo) {
                echo json_encode(['success' => false, 'error' => 'Shipment not found']);
                break;
            }
            
            // Get items with shipped and received quantities
            $stmtItems = $db->prepare("
                SELECT 
                    COALESCE(p.nome, isi.product_name, isi.seller_sku) as product_name,
                    isi.seller_sku,
                    isi.fnsku,
                    isi.quantity_shipped,
                    isi.quantity_received
                FROM inbound_shipment_items isi
                LEFT JOIN products p ON isi.product_id = p.id AND p.user_id = isi.user_id
                WHERE isi.shipment_id = ? AND isi.user_id = ?
                ORDER BY product_name
            ");
            $stmtItems->execute([$shipmentId, $userId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'shipment_info' => $shipmentInfo,
                    'items' => $items
                ]
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Azione non valida']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("TridScanner API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore server: ' . $e->getMessage()]);
}