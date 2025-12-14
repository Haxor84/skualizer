<?php
/**
 * TRID API - Tracking Inventario Amazon
 * Download, parsing e query report GET_LEDGER_DETAIL_VIEW_DATA
 */

session_start();
require_once __DIR__ . '/../../margynomic/config/config.php';
require_once __DIR__ . '/../../margynomic/login/auth_helpers.php';
require_once __DIR__ . '/../../margynomic/admin/admin_helpers.php';

header('Content-Type: application/json');

// Check authentication - SOLO ADMIN
if (!isAdminLogged()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Accesso negato. Solo admin.']);
    exit;
}

// Admin gestisce tutti gli user - priorità: richiesta > sessione trid > altre sessioni > default
$userId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? $_SESSION['trid_user'] ?? $_SESSION['removal_selected_user'] ?? $_SESSION['inv_receipts_user'] ?? 2);
$_SESSION['trid_user'] = $userId; // Salva per coerenza
$isAdmin = true;

/**
 * Dashboard globale con KPI, spedizioni e rimborsi
 */
function getDashboardGlobalData($db, $userId) {
    try {
        // 1. KPI AGGREGATI
        $kpiData = [
            'total_shipments' => 0,
            'total_received' => 0,
            'receipts' => ['in' => 0, 'out' => 0, 'net' => 0],
            'shipments' => ['count' => 0, 'qty' => 0],
            'transfers' => ['in' => 0, 'out' => 0, 'net' => 0],
            'adjustments' => ['count' => 0, 'qty' => 0],
            'reimbursable_count' => 0
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
            // Unità vendute NETTE: Order - (Refund - REVERSAL_REIMBURSEMENT)
            // CORRETTO: Somma COUNT DISTINCT per prodotto (ordini multi-prodotto)
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
            
            // Resi dei clienti: SOMMA COUNT DISTINCT per prodotto (ordini multi-prodotto)
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
            
            // Rimborsi Amazon (TAB3): SIGN(other_amount) * ABS(quantity_purchased)
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
        
        // 6. SPEDIZIONI TOTALI (per mantenere compatibilità con UI esistente)
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
        
        // 2. LISTA SPEDIZIONI (TUTTE, NON SOLO CON EVENTI TRID)
        // Usa subquery per evitare prodotto cartesiano tra items e eventi TRID
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
        $stmtShipmentsList->execute([$userId, $userId, $userId]); // 3 volte: trid_stats, items_stats, WHERE
        $shipmentsList = $stmtShipmentsList->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. RIMBORSI RAGGRUPPATI PER MESE (TUTTI, SENZA LIMITE)
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
        $reimbursementRows = $stmtReimbursements->fetchAll(PDO::FETCH_ASSOC);
        
        // Raggruppa per mese
        $reimbursementsByMonth = [];
        foreach ($reimbursementRows as $row) {
            $month = $row['month'];
            if (!isset($reimbursementsByMonth[$month])) {
                $reimbursementsByMonth[$month] = [
                    'count' => 0,
                    'total_qty' => 0,
                    'events' => []
                ];
            }
            $reimbursementsByMonth[$month]['count']++;
            $reimbursementsByMonth[$month]['total_qty'] += abs($row['quantity']);
            $reimbursementsByMonth[$month]['events'][] = $row;
        }
        
        return [
            'kpi' => $kpiData,
            'shipments' => $shipmentsList,
            'reimbursements_by_month' => $reimbursementsByMonth,
            'total_reimbursements' => count($reimbursementRows)
        ];
        
    } catch (Exception $e) {
        error_log("TRID DASHBOARD ERROR: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

$action = $_GET['action'] ?? '';
$db = getDbConnection();

try {
    switch ($action) {
        case 'download_and_import':
            // SOLO ADMIN
            if (!$isAdmin) {
                throw new Exception('Permesso negato');
            }
            
            $startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('-18 months'));
            $endDate = $_POST['end_date'] ?? date('Y-m-d');
            
            // Usa funzione di download esistente
            $tsvContent = downloadLedgerReport($db, $userId, $startDate, $endDate);
            
            // Parse e import (include auto-assign)
            $result = parseLedgerTSV($db, $tsvContent, $userId);
            
            echo json_encode([
                'success' => true,
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'errors' => $result['errors'],
                'error_details' => $result['error_details'],
                'assigned_shipments' => $result['assigned_shipments'],
                'assigned_products' => $result['assigned_products']
            ]);
            break;
            
        case 'shipments':
            $shipments = getShipmentsWithTridStats($db, $userId);
            echo json_encode(['success' => true, 'data' => $shipments]);
            break;
            
        case 'load_aggregated':
            $shipmentId = (int)($_GET['shipment_id'] ?? 0);
            if ($shipmentId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid shipment ID']);
                break;
            }
            
            $aggregated = getAggregatedTridStats($db, $shipmentId);
            echo json_encode(['success' => true, 'data' => $aggregated]);
            break;
        
        case 'load_dashboard':
            $dashboardData = getDashboardGlobalData($db, $userId);
            if (isset($dashboardData['error'])) {
                echo json_encode(['success' => false, 'error' => $dashboardData['error']]);
            } else {
                echo json_encode(['success' => true, 'data' => $dashboardData]);
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
            
        case 'detail':
            $shipmentId = (int)($_GET['shipment_id'] ?? 0);
            if (!$shipmentId) {
                throw new Exception('shipment_id mancante');
            }
            
            $products = getShipmentByProduct($db, $shipmentId);
            echo json_encode(['success' => true, 'data' => $products]);
            break;
            
        default:
            throw new Exception('Action non valida');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ============================================================================
// FUNCTIONS
// ============================================================================

/**
 * Download report Ledger da Amazon (riutilizza logica esistente)
 */
function downloadLedgerReport($db, $userId, $startDate, $endDate) {
    // Carica credenziali
    $creds = loadCredentials($db, $userId);
    $accessToken = getAccessToken($creds);
    
    $startDateTime = $startDate . 'T00:00:00Z';
    $endDateTime = $endDate . 'T23:59:59Z';
    
    // Request report
    $body = json_encode([
        'reportType' => 'GET_LEDGER_DETAIL_VIEW_DATA',
        'marketplaceIds' => [$creds['marketplace_id']],
        'dataStartTime' => $startDateTime,
        'dataEndTime' => $endDateTime
    ]);
    
    $reportResult = callSpApi('POST', '/reports/2021-06-30/reports', [], $body, $accessToken, $creds);
    $reportId = $reportResult['reportId'] ?? throw new Exception('Report ID non ricevuto');
    
    // Poll status
    $maxAttempts = 60;
    $attempts = 0;
    
    while ($attempts < $maxAttempts) {
        sleep(5);
        $attempts++;
        
        $statusResult = callSpApi('GET', "/reports/2021-06-30/reports/{$reportId}", [], '', $accessToken, $creds);
        $status = $statusResult['processingStatus'] ?? 'UNKNOWN';
        
        if ($status === 'DONE') {
            $documentId = $statusResult['reportDocumentId'] ?? throw new Exception('Document ID mancante');
            
            // Download
            $docInfo = callSpApi('GET', "/reports/2021-06-30/documents/{$documentId}", [], '', $accessToken, $creds);
            $downloadUrl = $docInfo['url'] ?? throw new Exception('URL mancante');
            
            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || empty($content)) {
                throw new Exception("Download fallito: HTTP {$httpCode}");
            }
            
            // Decompress GZIP
            if (isset($docInfo['compressionAlgorithm']) && $docInfo['compressionAlgorithm'] === 'GZIP') {
                $content = gzdecode($content);
            }
            
            return $content;
            
        } elseif ($status === 'FATAL' || $status === 'CANCELLED') {
            throw new Exception("Report fallito: {$status}");
        }
    }
    
    throw new Exception('Timeout: report non completato');
}

/**
 * Parse TSV e insert/update DB
 */
function parseLedgerTSV($db, $tsvContent, $userId) {
    $lines = explode("\n", trim($tsvContent));
    $headerLine = array_shift($lines);
    $header = str_getcsv($headerLine, "\t");
    
    // Normalize header (rimuovi virgolette, converti in lowercase)
    $header = array_map(function($h) {
        return strtolower(trim($h, '"'));
    }, $header);
    
    // Map header to indices
    $headerMap = array_flip($header);
    
    $stmt = $db->prepare("
        INSERT INTO shipments_trid 
        (user_id, date, fnsku, asin, msku, title, event_type, reference_id, 
         quantity, fulfillment_center, disposition, reason, country, 
         reconciled_qty, unreconciled_qty, datetime)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            reconciled_qty = VALUES(reconciled_qty),
            unreconciled_qty = VALUES(unreconciled_qty),
            last_updated = CURRENT_TIMESTAMP
    ");
    
    $imported = 0;
    $updated = 0;
    $errors = 0;
    $errorDetails = [];
    
    foreach ($lines as $lineNum => $line) {
        if (empty(trim($line))) continue;
        
        $row = str_getcsv($line, "\t");
        
        // Rimuovi virgolette da ogni valore
        $row = array_map(function($val) {
            return trim($val, '"');
        }, $row);
        
        // Extract values (usa nomi normalizzati dell'header)
        $dateStr = $row[$headerMap['date'] ?? -1] ?? '';
        $datetimeStr = $row[$headerMap['date and time'] ?? -1] ?? '';
        
        // Parse date
        $date = null;
        if (!empty($dateStr)) {
            $timestamp = strtotime($dateStr);
            $date = $timestamp ? date('Y-m-d', $timestamp) : null;
        }
        
        // Parse datetime
        $datetime = null;
        if (!empty($datetimeStr)) {
            $timestamp = strtotime($datetimeStr);
            $datetime = $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
        }
        
        // Se manca datetime, usa date
        if (!$datetime && $date) {
            $datetime = $date . ' 00:00:00';
        }
        
        $fnsku = !empty($row[$headerMap['fnsku'] ?? -1] ?? '') ? $row[$headerMap['fnsku']] : null;
        $asin = !empty($row[$headerMap['asin'] ?? -1] ?? '') ? $row[$headerMap['asin']] : null;
        $msku = $row[$headerMap['msku'] ?? -1] ?? '';
        $title = $row[$headerMap['title'] ?? -1] ?? '';
        $eventType = $row[$headerMap['event type'] ?? -1] ?? '';
        $referenceId = !empty($row[$headerMap['reference id'] ?? -1] ?? '') ? $row[$headerMap['reference id']] : null;
        $quantity = (int)($row[$headerMap['quantity'] ?? -1] ?? 0);
        $fc = !empty($row[$headerMap['fulfillment center'] ?? -1] ?? '') ? $row[$headerMap['fulfillment center']] : null;
        $disposition = !empty($row[$headerMap['disposition'] ?? -1] ?? '') ? $row[$headerMap['disposition']] : null;
        $reason = !empty($row[$headerMap['reason'] ?? -1] ?? '') ? $row[$headerMap['reason']] : null;
        $country = !empty($row[$headerMap['country'] ?? -1] ?? '') ? $row[$headerMap['country']] : null;
        $reconciledQty = !empty($row[$headerMap['reconciled quantity'] ?? -1] ?? '') ? $row[$headerMap['reconciled quantity']] : null;
        $unreconciledQty = !empty($row[$headerMap['unreconciled quantity'] ?? -1] ?? '') ? $row[$headerMap['unreconciled quantity']] : null;
        
        // Skip se mancano campi obbligatori
        if (empty($msku) || empty($eventType) || empty($datetime)) {
            $errors++;
            $missingFields = [];
            if (empty($msku)) $missingFields[] = 'MSKU';
            if (empty($eventType)) $missingFields[] = 'Event Type';
            if (empty($datetime)) $missingFields[] = 'DateTime';
            $errorDetails[] = "Line {$lineNum}: Missing required fields: " . implode(', ', $missingFields);
            continue;
        }
        
        try {
            $stmt->execute([
                $userId, $date, $fnsku, $asin, $msku, $title, $eventType, $referenceId,
                $quantity, $fc, $disposition, $reason, $country,
                $reconciledQty, $unreconciledQty, $datetime
            ]);
            
            if ($stmt->rowCount() > 0) {
                $imported++;
            }
        } catch (PDOException $e) {
            error_log("TRID Parse Error Line {$lineNum}: " . $e->getMessage());
            $errors++;
            // Store error details
            $errorDetails[] = "Line {$lineNum}: " . substr($e->getMessage(), 0, 100);
        }
    }
    
    // Auto-assign shipments and products
    $assignedShipments = autoAssignReceipts($db, $userId);
    $assignedProducts = autoAssignProducts($db, $userId);
    
    return [
        'imported' => $imported,
        'updated' => 0,
        'errors' => $errors,
        'error_details' => $errorDetails,
        'assigned_shipments' => $assignedShipments,
        'assigned_products' => $assignedProducts
    ];
}

/**
 * Collega eventi Receipts a spedizioni tramite reference_id
 */
function autoAssignReceipts($db, $userId) {
    $stmt = $db->prepare("
        UPDATE shipments_trid st
        INNER JOIN inbound_shipments ibs 
            ON st.reference_id = ibs.amazon_shipment_id
            AND st.user_id = ibs.user_id
        SET st.inbound_shipment_id = ibs.id
        WHERE st.user_id = ?
          AND st.event_type = 'Receipts' 
          AND st.inbound_shipment_id IS NULL
          AND st.reference_id IS NOT NULL
    ");
    
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/**
 * Assegna product_id da inventory tramite MSKU
 * Multi-step: inventory.sku -> inventory_fbm.seller_sku -> inbound_shipment_items.seller_sku -> mapping_states.sku
 */
function autoAssignProducts($db, $userId) {
    $assigned = 0;
    
    // Step 1: Prova con inventory.sku (FBA)
    $stmt1 = $db->prepare("
        UPDATE shipments_trid st
        INNER JOIN inventory inv 
            ON st.msku = inv.sku
            AND st.user_id = inv.user_id
        SET st.product_id = inv.product_id
        WHERE st.user_id = ?
          AND st.product_id IS NULL
          AND inv.product_id IS NOT NULL
    ");
    $stmt1->execute([$userId]);
    $assigned += $stmt1->rowCount();
    
    // Step 2: Prova con inventory_fbm.seller_sku (FBM)
    try {
        $stmt2 = $db->prepare("
            UPDATE shipments_trid st
            INNER JOIN inventory_fbm ifbm
                ON st.msku = ifbm.seller_sku
                AND st.user_id = ifbm.user_id
            SET st.product_id = ifbm.product_id
            WHERE st.user_id = ?
              AND st.product_id IS NULL
              AND ifbm.product_id IS NOT NULL
        ");
        $stmt2->execute([$userId]);
        $assigned += $stmt2->rowCount();
    } catch (PDOException $e) {
        // Tabella inventory_fbm potrebbe non esistere
        error_log("TRID autoAssignProducts: inventory_fbm not available");
    }
    
    // Step 3: Prova con inbound_shipment_items.seller_sku (Spedizioni FBA)
    try {
        $stmt3 = $db->prepare("
            UPDATE shipments_trid st
            INNER JOIN inbound_shipment_items isi
                ON st.msku = isi.seller_sku
                AND st.user_id = isi.user_id
            SET st.product_id = isi.product_id
            WHERE st.user_id = ?
              AND st.product_id IS NULL
              AND isi.product_id IS NOT NULL
        ");
        $stmt3->execute([$userId]);
        $assigned += $stmt3->rowCount();
    } catch (PDOException $e) {
        // Errore inatteso
        error_log("TRID autoAssignProducts: inbound_shipment_items error - " . $e->getMessage());
    }
    
    // Step 4: Prova con mapping_states (fallback universale)
    try {
        $stmt4 = $db->prepare("
            UPDATE shipments_trid st
            INNER JOIN mapping_states ms
                ON st.msku = ms.sku
            INNER JOIN products p
                ON ms.product_id = p.id
                AND p.user_id = st.user_id
            SET st.product_id = ms.product_id
            WHERE st.user_id = ?
              AND st.product_id IS NULL
        ");
        $stmt4->execute([$userId]);
        $assigned += $stmt4->rowCount();
    } catch (PDOException $e) {
        // Tabella mapping_states potrebbe non esistere
        error_log("TRID autoAssignProducts: mapping_states not available");
    }
    
    return $assigned;
}

/**
 * Lista spedizioni con stats TRID
 */
function getShipmentsWithTridStats($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            ibs.id,
            ibs.amazon_shipment_id,
            ibs.shipment_name,
            ibs.destination_fc,
            ibs.shipment_status,
            ibs.shipment_created_date,
            COUNT(DISTINCT st.id) as trid_count,
            SUM(CASE WHEN st.event_type = 'Receipts' THEN st.quantity ELSE 0 END) as qty_received,
            SUM(CASE WHEN st.disposition = 'DEFECTIVE' THEN ABS(st.quantity) ELSE 0 END) as qty_defective,
            SUM(CASE WHEN st.event_type = 'Shipments' THEN ABS(st.quantity) ELSE 0 END) as qty_shipped,
            MIN(CASE WHEN st.event_type = 'Receipts' THEN DATE(st.datetime) END) as receipt_date
        FROM inbound_shipments ibs
        LEFT JOIN shipments_trid st 
            ON ibs.id = st.inbound_shipment_id 
            AND st.user_id = ibs.user_id
        WHERE ibs.user_id = ?
        GROUP BY ibs.id
        HAVING trid_count > 0
        ORDER BY receipt_date DESC, ibs.shipment_created_date DESC
        LIMIT 10000
    ");
    
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Timeline completa di una spedizione (raggruppata per prodotto)
 */
function getShipmentByProduct($db, $shipmentId) {
    // Get user_id from shipment
    $stmtUser = $db->prepare("SELECT user_id FROM inbound_shipments WHERE id = ?");
    $stmtUser->execute([$shipmentId]);
    $userId = $stmtUser->fetchColumn();
    
    if (!$userId) {
        return [];
    }
    
    // Step 1: Get MSKU ricevuti in questa spedizione
    $stmtMsku = $db->prepare("
        SELECT DISTINCT msku 
        FROM shipments_trid 
        WHERE inbound_shipment_id = ? 
          AND user_id = ?
          AND event_type = 'Receipts'
    ");
    $stmtMsku->execute([$shipmentId, $userId]);
    $mskuList = $stmtMsku->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($mskuList)) {
        return [];
    }
    
    // Step 2: Get ALL eventi di quegli MSKU
    $placeholders = str_repeat('?,', count($mskuList) - 1) . '?';
    $params = array_merge([$userId], $mskuList);
    
    $stmtEvents = $db->prepare("
        SELECT 
            st.*,
            COALESCE(p.nome, st.title) as display_name
        FROM shipments_trid st
        LEFT JOIN products p ON st.product_id = p.id
        WHERE st.user_id = ?
          AND st.msku IN ($placeholders)
        ORDER BY st.datetime ASC, st.event_type = 'Receipts' DESC
    ");
    $stmtEvents->execute($params);
    $events = $stmtEvents->fetchAll(PDO::FETCH_ASSOC);
    
    // Step 3: Raggruppa per nome prodotto
    $grouped = [];
    foreach ($events as $event) {
        $name = $event['display_name'] ?: ($event['title'] ?: 'Unknown Product');
        
        if (!isset($grouped[$name])) {
            $grouped[$name] = [
                'name' => $name,
                'receipts' => 0,
                'shipments' => 0,
                'defective' => 0,
                'transfers' => 0,
                'adjustments' => 0,
                'events' => []
            ];
        }
        
        // Somma quantità
        if ($event['event_type'] == 'Receipts') {
            $grouped[$name]['receipts'] += $event['quantity'];
        } elseif ($event['event_type'] == 'Shipments') {
            $grouped[$name]['shipments'] += abs($event['quantity']);
        } elseif ($event['disposition'] == 'DEFECTIVE') {
            $grouped[$name]['defective'] += abs($event['quantity']);
        } elseif (strpos($event['event_type'], 'Transfer') !== false) {
            $grouped[$name]['transfers'] += abs($event['quantity']);
        } elseif (strpos($event['event_type'], 'Adjustment') !== false) {
            $grouped[$name]['adjustments'] += abs($event['quantity']);
        }
        
        $grouped[$name]['events'][] = $event;
    }
    
    return array_values($grouped);
}

/**
 * Statistiche aggregate per visualizzazione card-based
 * Raggruppa eventi per tipo e calcola KPI principali
 */
function getAggregatedTridStats($db, $shipmentId) {
    try {
        error_log("TRID AGGREGATED: Starting for shipment_id={$shipmentId}");
        
        // Get user_id
        $stmtUser = $db->prepare("SELECT user_id FROM inbound_shipments WHERE id = ?");
        $stmtUser->execute([$shipmentId]);
        $userId = $stmtUser->fetchColumn();
        
        if (!$userId) {
            error_log("TRID AGGREGATED ERROR: Shipment not found");
            return ['error' => 'Shipment not found'];
        }
        
        error_log("TRID AGGREGATED: user_id={$userId}");
        
        // STEP 1: Get MSKU list received in this shipment (same logic as getShipmentByProduct)
        $stmtMsku = $db->prepare("
            SELECT DISTINCT msku 
            FROM shipments_trid 
            WHERE inbound_shipment_id = ? 
              AND user_id = ?
              AND event_type = 'Receipts'
        ");
        $stmtMsku->execute([$shipmentId, $userId]);
        $mskuList = $stmtMsku->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($mskuList)) {
            error_log("TRID AGGREGATED: No MSKU found for shipment");
            return [
                'kpi' => [
                    'total_received' => 0, 'receipt_batches' => 0, 'total_shipped' => 0,
                    'shipment_events' => 0, 'total_adjustments' => 0, 'total_transfers' => 0,
                    'total_defective' => 0, 'reimbursable_count' => 0
                ],
                'warehouses' => [],
                'critical_events' => [],
                'monthly_timeline' => []
            ];
        }
        
        error_log("TRID AGGREGATED: Found " . count($mskuList) . " MSKU");
        
        // Build placeholders for IN clause
        $placeholders = str_repeat('?,', count($mskuList) - 1) . '?';
        $params = array_merge([$userId], $mskuList);
        
        // 2. KPI Aggregati (ALL events for these MSKUs, not just this shipment)
    $stmtKpi = $db->prepare("
        SELECT 
            SUM(CASE WHEN event_type = 'Receipts' AND quantity > 0 THEN quantity ELSE 0 END) as total_received,
            COUNT(DISTINCT CASE WHEN event_type = 'Receipts' AND quantity > 0 THEN DATE(datetime) END) as receipt_batches,
            SUM(CASE WHEN event_type = 'Shipments' THEN ABS(quantity) ELSE 0 END) as total_shipped,
            COUNT(DISTINCT CASE WHEN event_type = 'Shipments' THEN id END) as shipment_events,
            SUM(CASE WHEN event_type = 'Adjustments' THEN ABS(quantity) ELSE 0 END) as total_adjustments,
            SUM(CASE WHEN event_type = 'WhseTransfers' THEN ABS(quantity) ELSE 0 END) as total_transfers,
            SUM(CASE WHEN disposition = 'DEFECTIVE' THEN ABS(quantity) ELSE 0 END) as total_defective,
            COUNT(DISTINCT CASE WHEN reason IN ('Q','D','E','M','O','P') AND event_type = 'Adjustments' THEN reference_id END) as reimbursable_count
        FROM shipments_trid
        WHERE user_id = ? AND msku IN ($placeholders)
    ");
    $stmtKpi->execute($params);
    $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);
    error_log("TRID AGGREGATED: KPI fetched - received={$kpi['total_received']}, shipped={$kpi['total_shipped']}");
    
    // 3. Distribuzione Warehouse (ALL events for these MSKUs)
    $stmtWh = $db->prepare("
        SELECT 
            fulfillment_center,
            SUM(CASE 
                WHEN event_type IN ('Receipts', 'WhseTransfers') AND quantity > 0 THEN quantity
                WHEN event_type IN ('Shipments', 'WhseTransfers') AND quantity < 0 THEN quantity
                ELSE 0 
            END) as net_quantity
        FROM shipments_trid
        WHERE user_id = ? AND msku IN ($placeholders)
          AND fulfillment_center IS NOT NULL
        GROUP BY fulfillment_center
        HAVING SUM(CASE 
                WHEN event_type IN ('Receipts', 'WhseTransfers') AND quantity > 0 THEN quantity
                WHEN event_type IN ('Shipments', 'WhseTransfers') AND quantity < 0 THEN quantity
                ELSE 0 
            END) != 0
        ORDER BY ABS(SUM(CASE 
                WHEN event_type IN ('Receipts', 'WhseTransfers') AND quantity > 0 THEN quantity
                WHEN event_type IN ('Shipments', 'WhseTransfers') AND quantity < 0 THEN quantity
                ELSE 0 
            END)) DESC
    ");
    $stmtWh->execute($params);
    $warehouses = $stmtWh->fetchAll(PDO::FETCH_ASSOC);
    error_log("TRID AGGREGATED: Warehouses fetched - count=" . count($warehouses));
    
    // 4. Eventi Critici (rimborsi da richiedere - ALL events for these MSKUs)
    $stmtCritical = $db->prepare("
        SELECT 
            DATE(datetime) as event_date,
            fulfillment_center,
            disposition,
            reason,
            quantity,
            reference_id,
            event_type
        FROM shipments_trid
        WHERE user_id = ?
          AND msku IN ($placeholders)
          AND event_type = 'Adjustments'
          AND reason IN ('Q','D','E','M','O','P')
        ORDER BY datetime DESC
    ");
    $stmtCritical->execute($params);
    $criticalEvents = $stmtCritical->fetchAll(PDO::FETCH_ASSOC);
    error_log("TRID AGGREGATED: Critical events fetched - count=" . count($criticalEvents));
    
    // 5. Timeline Mensile (ALL events for these MSKUs)
    $stmtTimeline = $db->prepare("
        SELECT 
            DATE_FORMAT(datetime, '%Y-%m') as month,
            event_type,
            SUM(quantity) as total_qty,
            COUNT(*) as event_count,
            MIN(DATE(datetime)) as first_date,
            MAX(DATE(datetime)) as last_date,
            GROUP_CONCAT(DISTINCT fulfillment_center ORDER BY fulfillment_center) as warehouses
        FROM shipments_trid
        WHERE user_id = ? AND msku IN ($placeholders)
        GROUP BY month, event_type
        ORDER BY month DESC, event_type
    ");
    $stmtTimeline->execute($params);
    $timeline = $stmtTimeline->fetchAll(PDO::FETCH_ASSOC);
    
    // Raggruppa timeline per mese
    $monthlyData = [];
    foreach ($timeline as $row) {
        $month = $row['month'];
        if (!isset($monthlyData[$month])) {
            $monthlyData[$month] = [
                'receipts' => 0,
                'shipments' => 0,
                'adjustments' => 0,
                'transfers' => 0,
                'first_date' => $row['first_date'],
                'last_date' => $row['last_date'],
                'events' => []
            ];
        }
        
        $type = $row['event_type'];
        $monthlyData[$month]['events'][] = $row;
        
        if ($type === 'Receipts') $monthlyData[$month]['receipts'] += $row['total_qty'];
        if ($type === 'Shipments') $monthlyData[$month]['shipments'] += abs($row['total_qty']);
        if ($type === 'Adjustments') $monthlyData[$month]['adjustments'] += abs($row['total_qty']);
        if ($type === 'WhseTransfers') $monthlyData[$month]['transfers'] += abs($row['total_qty']);
    }
    
    error_log("TRID AGGREGATED: Timeline processed - months=" . count($monthlyData));
    error_log("TRID AGGREGATED: Success - returning data");
    
    return [
        'kpi' => $kpi,
        'warehouses' => $warehouses,
        'critical_events' => $criticalEvents,
        'monthly_timeline' => $monthlyData
    ];
    
    } catch (Exception $e) {
        error_log("TRID AGGREGATED ERROR: " . $e->getMessage());
        error_log("TRID AGGREGATED ERROR TRACE: " . $e->getTraceAsString());
        return ['error' => $e->getMessage()];
    }
}

// ============================================================================
// HELPER FUNCTIONS (copied from inventory_receipts_download.php)
// ============================================================================

function loadCredentials($db, $userId) {
    $stmt = $db->query("
        SELECT aws_access_key_id, aws_secret_access_key, aws_region, 
               spapi_client_id, spapi_client_secret
        FROM amazon_credentials 
        WHERE is_active = 1 
        LIMIT 1
    ");
    $global = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$global) throw new Exception('Credenziali globali non trovate');
    
    $stmt = $db->prepare("
        SELECT refresh_token, marketplace_id, seller_id
        FROM amazon_client_tokens 
        WHERE user_id = ? AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) throw new Exception('Token utente non trovato');
    
    return [
        'aws_access_key_id' => $global['aws_access_key_id'],
        'aws_secret_access_key' => $global['aws_secret_access_key'],
        'region' => $global['aws_region'] ?? 'eu-west-1',
        'client_id' => $global['spapi_client_id'],
        'client_secret' => $global['spapi_client_secret'],
        'refresh_token' => $user['refresh_token'],
        'marketplace_id' => $user['marketplace_id'],
        'seller_id' => $user['seller_id']
    ];
}

function getAccessToken($creds) {
    $ch = curl_init('https://api.amazon.com/auth/o2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'refresh_token' => $creds['refresh_token']
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) throw new Exception("Token error: HTTP {$httpCode}");
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? throw new Exception('Access token mancante');
}

function createAwsSignature($method, $path, $queryString, $body, $accessToken, $creds) {
    $host = 'sellingpartnerapi-eu.amazon.com';
    $region = 'eu-west-1';
    $service = 'execute-api';
    $timestamp = gmdate('Ymd\THis\Z');
    $date = substr($timestamp, 0, 8);
    
    $canonicalHeaders = "host:{$host}\n";
    $canonicalHeaders .= "x-amz-access-token:{$accessToken}\n";
    $canonicalHeaders .= "x-amz-date:{$timestamp}\n";
    
    $signedHeaders = 'host;x-amz-access-token;x-amz-date';
    $payloadHash = hash('sha256', $body);
    
    $canonicalRequest = "{$method}\n{$path}\n{$queryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    
    $credentialScope = "{$date}/{$region}/{$service}/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
    
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $creds['aws_secret_access_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    
    $authorization = "AWS4-HMAC-SHA256 Credential={$creds['aws_access_key_id']}/{$credentialScope}, ";
    $authorization .= "SignedHeaders={$signedHeaders}, Signature={$signature}";
    
    return [
        'Authorization' => $authorization,
        'x-amz-date' => $timestamp,
        'x-amz-access-token' => $accessToken
    ];
}

function callSpApi($method, $path, $params, $body, $accessToken, $creds) {
    $baseUrl = 'https://sellingpartnerapi-eu.amazon.com';
    
    $queryString = '';
    if (!empty($params)) {
        ksort($params);
        $queryString = http_build_query($params);
    }
    
    $url = $baseUrl . $path;
    if ($queryString) $url .= '?' . $queryString;
    
    $signature = createAwsSignature($method, $path, $queryString, $body, $accessToken, $creds);
    
    $headers = [
        "Authorization: {$signature['Authorization']}",
        "x-amz-date: {$signature['x-amz-date']}",
        "x-amz-access-token: {$signature['x-amz-access-token']}",
        "host: sellingpartnerapi-eu.amazon.com",
        "Content-Type: application/json"
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);
    
    if (!empty($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        throw new Exception("SP-API Error: HTTP {$httpCode} - {$response}");
    }
    
    return json_decode($response, true);
}

