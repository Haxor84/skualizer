<?php
/**
 * Endpoint AJAX per Transaction Details
 * File: modules/margynomic/margini/fee_transaction_details.php
 */

// Headers per JSON response
header('Content-Type: application/json');

// Debug e configurazione
ini_set('display_errors', 0); // No output HTML in JSON
require_once dirname(__DIR__) . '/config/config.php';

try {
    $transactionType = $_GET['type'] ?? '';
    
    if (empty($transactionType)) {
        throw new Exception('Transaction type mancante');
    }
    
    $pdo = getDbConnection();
    
    // Per ora response di test
    $response = [
        'success' => true,
        'transaction_type' => $transactionType,
        'html' => generateDetailsHTML($transactionType)
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Genera HTML per i dettagli con query reali settlement
 */
function generateDetailsHTML($transactionType) {
    global $pdo;
    
    try {
        // Trova esempi da settlement tables
        $examples = getSettlementExamples($transactionType);
        
        $html = '
        <div style="background: white; padding: 1rem; border-radius: 6px; margin-top: 0.5rem;">
            <h5><i class="fas fa-table"></i> Esempi: ' . htmlspecialchars($transactionType) . '</h5>
            <div style="color: #666; margin-bottom: 1rem;">
                Righe reali dai settlement reports per aiutarti nella categorizzazione
            </div>';
        
        if (!empty($examples)) {
            $html .= '<div class="table-responsive">
                <div style="overflow-x: auto; max-width: 100%;">
                <table style="width: auto; font-size: 0.7rem; border-collapse: collapse; white-space: nowrap;">
                    <thead style="background: #f8f9fa;">
                        <tr>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">hash</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">settlement_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">settlement_start_date</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">settlement_end_date</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">deposit_date</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">total_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">currency</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">transaction_type</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">order_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">merchant_order_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">adjustment_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">shipment_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">marketplace_name</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">shipment_fee_type</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">shipment_fee_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">order_fee_type</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">order_fee_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">fulfillment_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">posted_date</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">order_item_code</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">merchant_order_item_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">merchant_adjustment_item_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">sku</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">quantity_purchased</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">price_type</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">price_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">item_related_fee_type</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">item_related_fee_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">misc_fee_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">other_fee_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">other_fee_reason_description</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">promotion_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">promotion_type</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">promotion_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">direct_payment_type</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">direct_payment_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">other_amount</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">product_id</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">date_uploaded</th>
                            <th style="padding: 0.2rem; border: 1px solid #dee2e6;">user_id</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            foreach ($examples as $example) {
                $html .= '<tr>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars(substr($example['hash'] ?? '', 0, 8)) . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['settlement_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['settlement_start_date'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['settlement_end_date'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['deposit_date'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['total_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['currency'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['transaction_type'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['order_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['merchant_order_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['adjustment_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['shipment_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['marketplace_name'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['shipment_fee_type'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['shipment_fee_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['order_fee_type'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['order_fee_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['fulfillment_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['posted_date'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['order_item_code'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['merchant_order_item_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['merchant_adjustment_item_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['sku'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['quantity_purchased'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['price_type'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['price_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['item_related_fee_type'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['item_related_fee_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['misc_fee_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['other_fee_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['other_fee_reason_description'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['promotion_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['promotion_type'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['promotion_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . htmlspecialchars($example['direct_payment_type'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['direct_payment_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['other_amount'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['product_id'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . ($example['date_uploaded'] ?? '') . '</td>
                    <td style="padding: 0.2rem; border: 1px solid #dee2e6;">' . $example['user_id'] . '</td>
                </tr>';
            }
            
            $html .= '</tbody></table></div>';
            
            $html .= '</tbody></table></div>';
            
            $html .= '<div style="margin-top: 1rem; padding: 0.75rem; background: #e7f3ff; border-left: 4px solid #007bff; border-radius: 4px;">
                <small><strong>💡 Suggerimento:</strong> Analizza questi esempi per scegliere la categoria più appropriata per "' . htmlspecialchars($transactionType) . '"</small>
            </div>';
        } else {
            $html .= '<div style="text-align: center; padding: 2rem; color: #6c757d;">
                <i class="fas fa-inbox"></i><br>
                Nessun esempio trovato per questo transaction type
            </div>';
        }
        
        $html .= '</div>';
        
        return $html;
        
    } catch (Exception $e) {
        return '<div class="alert alert-danger">Errore caricamento esempi: ' . $e->getMessage() . '</div>';
    }
}

/**
 * Ottiene esempi da settlement tables
 */
function getSettlementExamples($transactionType, $limit = 5) {
    global $pdo;
    
    try {
        // Trova tutte le tabelle report_settlement utenti 
        $stmt = $pdo->query("SHOW TABLES LIKE 'report_settlement_%'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $examples = [];
        
        foreach ($tables as $table) {
            // Estrai user_id dal nome tabella
            if (preg_match('/report_settlement_(\d+)/', $table, $matches)) {
                $userId = $matches[1];
                
                try {
                    // Prima controlla se esistono righe con questo transaction_type
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE transaction_type = ?");
                    $checkStmt->execute([$transactionType]);
                    $count = $checkStmt->fetchColumn();
                    
                    if ($count > 0) {
                        $stmt = $pdo->prepare("
                            SELECT 
                                id, hash, settlement_id, settlement_start_date, settlement_end_date, 
                                deposit_date, total_amount, currency, transaction_type, order_id,
                                merchant_order_id, adjustment_id, shipment_id, marketplace_name,
                                shipment_fee_type, shipment_fee_amount, order_fee_type, order_fee_amount,
                                fulfillment_id, posted_date, order_item_code, merchant_order_item_id,
                                merchant_adjustment_item_id, sku, quantity_purchased, price_type,
                                price_amount, item_related_fee_type, item_related_fee_amount,
                                misc_fee_amount, other_fee_amount, other_fee_reason_description,
                                promotion_id, promotion_type, promotion_amount, direct_payment_type,
                                direct_payment_amount, other_amount, product_id, date_uploaded,
                                ? as user_id
                            FROM `$table` 
                            WHERE transaction_type = ? 
                            ORDER BY posted_date DESC 
                            LIMIT ?
                        ");
                        $stmt->execute([$userId, $transactionType, $limit]);
                        $results = $stmt->fetchAll();
                        
                        $examples = array_merge($examples, $results);
                    }
                    
                    // Limit total examples
                    if (count($examples) >= $limit) {
                        break;
                    }
                } catch (Exception $e) {
                    // Skip table if error
                    continue;
                }
            }
        }
        
        return array_slice($examples, 0, $limit);
        
    } catch (Exception $e) {
        return [];
    }
}
?>