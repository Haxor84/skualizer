<?php
/**
 * Overview Model - Data Layer per Dashboard OrderInsights
 * File: modules/orderinsights/OverviewModel.php
 */

// Aumenta memoria per gestire dataset grandi
ini_set('memory_limit', '768M');
set_time_limit(120);

// Debug disabilitato per evitare contaminazione JSON
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/margini/fee_mapping_helpers.php';

class OverviewModel {
    
    /**
     * Rileva colonne disponibili nella tabella settlement
     */
    public static function detectColumns($userId) {
        try {
            $tableName = "report_settlement_{$userId}";
            
            $pdo = getDbConnection();
            $stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($tableName) . "'");
            if (!$stmt->fetchColumn()) {
                return null;
            }
            
            $stmt = $pdo->query("DESCRIBE `{$tableName}`");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return [
                'has_amount_eur' => in_array('amount_eur', $columns),
                'amount_fields' => array_intersect($columns, [
                    'price_amount', 'item_related_fee_amount', 'order_fee_amount', 
                    'shipment_fee_amount', 'misc_fee_amount', 'other_fee_amount', 
                    'promotion_amount', 'direct_payment_amount', 'other_amount'
                ]),
                'has_currency' => in_array('currency', $columns),
                'has_posted_date' => in_array('posted_date', $columns),
                'table_exists' => true
            ];
        } catch (Exception $e) {
            CentralLogger::log('orderinsights', 'ERROR', 'Errore detectColumns per user ' . $userId . ': ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Helper per inizializzare la categoria se manca
     */
    private static function &ensureCategoryBucket(array &$map, string $name, bool $isReserve = false) {
        if (!isset($map[$name])) {
            $map[$name] = [
                'categoria'         => $name,
                'importo_eur'       => 0.0,
                'transazioni'       => 0,
                'unita_vendute'     => 0,
                'unita_rimborsate'  => 0,
                'unita_smarrite'    => 0,
                'is_reserve'        => $isReserve,
                '_orders'           => []
            ];
        } else {
            // garantisci le chiavi mancanti (in caso di versioni vecchie in memoria)
            $map[$name]['importo_eur']      = (float)($map[$name]['importo_eur']      ?? 0.0);
            $map[$name]['transazioni']      = (int)  ($map[$name]['transazioni']      ?? 0);
            $map[$name]['unita_vendute']    = (float)($map[$name]['unita_vendute']    ?? 0);
            $map[$name]['unita_rimborsate'] = (float)($map[$name]['unita_rimborsate'] ?? 0);
            $map[$name]['unita_smarrite']   = (float)($map[$name]['unita_smarrite']   ?? 0);
            $map[$name]['is_reserve']       = (bool) ($map[$name]['is_reserve']       ?? $isReserve);
            $map[$name]['_orders']          = (array)($map[$name]['_orders']          ?? []);
        }
        return $map[$name]; // ritorna reference
    }
    
    /**
     * Ottiene range date [from, to) da parametri mese o start/end
     */
    public static function getDateRange($month = null, $start = null, $end = null) {
        $tz = new DateTimeZone('Europe/Rome');
        try {
            if ($month) {
                // Formato YYYY-MM
                if (preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
                    $year = intval($matches[1]);
                    $monthNum = intval($matches[2]);
                    
                    $from = new DateTime("{$year}-{$monthNum}-01", $tz);
                    $to = clone $from;
                    $to->add(new DateInterval('P1M'));
                    
                    return [
                        'from' => $from->format('Y-m-d H:i:s'),
                        'to' => $to->format('Y-m-d H:i:s'),
                        'type' => 'month'
                    ];
                }
            }
            
            if ($start && $end) {
                $from = new DateTime($start, $tz);
                $to = (new DateTime($end, $tz))->modify('+1 day');
                
                return [
                    'from' => $from->format('Y-m-d H:i:s'),
                    'to' => $to->format('Y-m-d H:i:s'),
                    'type' => 'range'
                ];
            }
            
            // Default: TUTTI i dati disponibili (nessun filtro)
            // Usa range molto ampio per includere tutto
            return [
                'from' => '1900-01-01 00:00:00',
                'to' => date('Y-m-d 23:59:59', strtotime('+1 day')), // Fino a domani per includere oggi
                'type' => 'all'
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('orderinsights', 'ERROR', 'Errore getDateRange: ' . $e->getMessage());
            
            // Fallback estremo
            $now = new DateTime();
            return [
                'from' => $now->format('Y-m-01 00:00:00'),
                'to' => $now->format('Y-m-t 23:59:59'),
                'type' => 'fallback'
            ];
        }
    }
    
    /**
     * Mappa transaction_type a categoria usando fee_mapping_helpers.php
     */
    public static function mapCategory($transactionType, $userId = null) {
        if ($transactionType === null) return 'Aggiustamenti Vari';

        $cat = getTransactionCategory($transactionType, $userId);
        if (!$cat) return 'Aggiustamenti Vari';

        // normalizza
        $catTrim = trim((string)$cat);
        // pass-through se già finale (italiano)
        $final = [
            'Ricavi Vendite',
            'Commissioni di Vendita/Logistica',
            'Rimborsi/Storni Clienti',
            'Costi Operativi/Abbonamenti',
            'Perdite e Rimborsi/Danni',
            'Fondi Riserva',
            'Aggiustamenti Vari'
        ];
        if (in_array($catTrim, $final, true)) return $catTrim;

        // FIX #3: mapping da chiavi interne/sinonimi → finali italiane
        $map = [
            'REVENUE'               => 'Ricavi Vendite',
            'REVENUES'              => 'Ricavi Vendite',
            'SALES'                 => 'Ricavi Vendite',
            // Codici interni dal database transaction_fee_mappings
            'FEE_TAB1'              => 'Ricavi Vendite',               // Order, Refund, REVERSAL_REIMBURSEMENT
            'FEE_TAB2'              => 'Costi Operativi/Abbonamenti',  // Storage, Subscription, Inbound, etc.
            'FEE_TAB3'              => 'Perdite e Rimborsi/Danni',     // WAREHOUSE_DAMAGE, MISSING_FROM_INBOUND, etc.
            'FONDI'                 => 'Fondi Riserva',                // Current/Previous Reserve Amount
            // Altri mapping esistenti
            'FEE_TAB1_ADJUSTMENT'   => 'Commissioni di Vendita/Logistica',
            'FEES'                  => 'Commissioni di Vendita/Logistica',
            'TRANSPORTATION'        => 'Commissioni di Vendita/Logistica',
            'OPERATIONAL'           => 'Costi Operativi/Abbonamenti',
            'OPERATING'             => 'Costi Operativi/Abbonamenti',
            'SUBSCRIPTIONS'         => 'Costi Operativi/Abbonamenti',
            'STORAGE'               => 'Costi Operativi/Abbonamenti',
            'REFUNDS'               => 'Rimborsi/Storni Clienti',
            'REFUND'                => 'Rimborsi/Storni Clienti',
            'REVERSAL'              => 'Rimborsi/Storni Clienti',
            'DAMAGE_COMPENSATION'   => 'Perdite e Rimborsi/Danni',
            'LOSSES'                => 'Perdite e Rimborsi/Danni',
            'IGNORE'                => 'Fondi Riserva',
            'RESERVE'               => 'Fondi Riserva'
        ];

        $key = strtoupper(str_replace(' ', '_', $catTrim));
        return $map[$key] ?? 'Aggiustamenti Vari';
    }
    
    /**
     * Converte importo in EUR
     */
    public static function convertToEur($amount, $currency, $date = null) {
        if ($currency === 'EUR') {
            return floatval($amount);
        }
        
        // TODO: Implementare tabella tassi di cambio
        // Per ora fallback 1:1
        return floatval($amount);
    }
    
    /**
     * Calcola importo firmato per riga in EUR
     */
    public static function rowSignedAmountEUR($row, $userId) {
        static $catCache = [];
        $rawAmount = 0;
        
        // Somma tutti i campi importo disponibili
        $amountFields = [
            'price_amount', 'item_related_fee_amount', 'order_fee_amount',
            'shipment_fee_amount', 'misc_fee_amount', 'other_fee_amount',
            'promotion_amount', 'direct_payment_amount', 'other_amount'
        ];
        
        foreach ($amountFields as $field) {
            if (isset($row[$field]) && $row[$field] !== null) {
                $rawAmount += (float) $row[$field];
            }
        }
        
        // Clamp per evitare micro-valori
        $rawAmount = (abs($rawAmount) < 0.005) ? 0.0 : $rawAmount;
        
        // Converti in EUR
        $currency = $row['currency'] ?? 'EUR';
        $amountEur = self::convertToEur($rawAmount, $currency, $row['posted_date'] ?? null);
        
        // Clamp finale per evitare -0.00
        $amountEur = (abs($amountEur) < 0.005) ? 0.0 : $amountEur;
        
        return $amountEur;
    }
    
    /**
     * Verifica se transaction_type appartiene a Fondi Riserva
     */
    public static function isReserveFund($transactionType) {
        if ($transactionType === null) return false;
        $reservePatterns = [
            'Previous Reserve Amount Balance',
            'Current Reserve Amount',
            'Micro Deposit',
            'Transfer of funds unsuccessful: Amazon has cancelled your transfer of funds.'
        ];
        
        foreach ($reservePatterns as $pattern) {
            if ($transactionType === $pattern) {
                return true;
            }
        }
        
        // Pattern matching
        if (strpos($transactionType, 'Reserve') !== false ||
            strpos($transactionType, 'Transfer of funds unsuccessful:') === 0) {
            return true;
        }
        
        return false;
    }
    

    /**
     * Summary mensile con KPI e breakdown per categoria
     */
    public static function monthSummary($from, $to, $userId, $includeReserve = false) {
        $round = function($v, $dec = 2) {
            if ($v === null) return 0.0;
            $x = round((float)$v, $dec, PHP_ROUND_HALF_UP);
            return (abs($x) < pow(10, -$dec)) ? 0.0 : $x;
        };
        
        try {
            $tableName = "report_settlement_{$userId}";
            
            $pdo = getDbConnection();
            $stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($tableName) . "'");
            if (!$stmt->fetchColumn()) {
                throw new Exception("Tabella {$tableName} non trovata");
            }
            
            // Query più efficiente per ottenere tutti i dati necessari
            $stmt = $pdo->prepare("
    SELECT 
        rs.transaction_type,
        rs.currency,
        rs.price_type,
        rs.price_amount,
        rs.item_related_fee_type,
        rs.item_related_fee_amount,
        rs.order_fee_type,
        rs.order_fee_amount,
        rs.shipment_fee_type,
        rs.shipment_fee_amount,
        rs.misc_fee_amount,
        rs.other_fee_amount,
        rs.promotion_amount,
        rs.direct_payment_amount,
        rs.other_amount,
        rs.order_id,
        rs.quantity_purchased,
        rs.marketplace_name,
        rs.posted_date
    FROM `{$tableName}` rs
    WHERE rs.posted_date >= ? AND rs.posted_date < ?
    ORDER BY rs.posted_date ASC
");
$stmt->execute([
    $from instanceof DateTime ? $from->format('Y-m-d H:i:s') : (string)$from,
    $to   instanceof DateTime ? $to->format('Y-m-d H:i:s')   : (string)$to
]);

// STREAMING: Non usare fetchAll() - processa riga per riga
// $rawData = $stmt->fetchAll(); // RIMOSSO

// Aggregazione per categoria
$categoriesSummary = [];
            $kpiData = [
    'incassato_vendite' => 0,
    'refund_totale' => 0,
    'commissioni' => 0,
    'rimborsi_netto' => 0,
                'operativi' => 0,
                'perdite_netto' => 0,
                'netto_operativo' => 0,
                'fondi_riserva' => 0,
                'ordini' => 0,
                'transazioni' => 0,
                'unita_vendute' => 0,
                'unita_rimborsate' => 0,
                'unita_smarrite' => 0
            ];
            $breakdownByType = [];
            $allOrders = [];
            $reserveData = [];
            $catCache = [];
            
            // Inizializza categoriesSummary vuoto - la helper ensureCategoryBucket si occuperà dell'inizializzazione
            $categoriesSummary = [];
            
            // Fee Components dettagliati
$feeComponents = [
    'price' => ['principal' => 0.0, 'tax' => 0.0, 'other_price' => 0.0, 'total' => 0.0, 'by_type' => []],
    'refund' => ['principal' => 0.0, 'tax' => 0.0, 'other_price' => 0.0, 'total' => 0.0, 'by_type' => []],
    'item_related_fees' => ['total' => 0.0, 'by_type' => []],
    'order_fees'        => ['total' => 0.0, 'by_type' => []],
    'shipment_fees'     => ['total' => 0.0, 'by_type' => []],
];
            $addToMap = function(array &$map, string $key, float $val) {
                if ($key === '' || abs($val) < 0.0000001) return;
                if (!isset($map[$key])) $map[$key] = 0.0;
                $map[$key] += $val;
            };
            
            // STREAMING: fetch() invece di fetchAll()
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $transactionType = $row['transaction_type'];
                
                // FIX #1: Definisci $pt e $pa SUBITO all'inizio del loop
                $pt = (string)($row['price_type'] ?? '');
                $pa = (float)($row['price_amount'] ?? 0);
                
                if (!isset($catCache[$transactionType])) {
                    $catCache[$transactionType] = self::mapCategory($transactionType, $userId);
                }
                $categoria = $catCache[$transactionType];
                $skipRowSigned = false;
                
                // Fallback: se rimane "Aggiustamenti Vari", prova a inferire dalla riga
                if ($categoria === 'Aggiustamenti Vari') {
                    $tt = strtoupper((string)$transactionType);
                    $ptUpper = strtoupper($pt);
                    if ($tt === 'ORDER' && ($ptUpper === 'PRINCIPAL' || $ptUpper === '' || $ptUpper === 'TAX' || $ptUpper === 'SHIPPINGCREDIT' || $ptUpper === 'GIFTWRAP')) {
                        $categoria = 'Ricavi Vendite';
                    } elseif (strpos($tt, 'REFUND') !== false || strpos($tt, 'REVERSAL') !== false) {
                        $categoria = 'Rimborsi/Storni Clienti';
                    } elseif (strpos($tt, 'WAREHOUSE') !== false || strpos($tt, 'MISSING') !== false || strpos($tt, 'DAMAGE') !== false || strpos($tt, 'LIQUIDATION') !== false) {
                        $categoria = 'Perdite e Rimborsi/Danni';
                    } elseif (strpos($tt, 'RESERVE') !== false || strpos($tt, 'TRANSFER OF FUNDS UNSUCCESSFUL') !== false || $tt === 'MICRO DEPOSIT') {
                        $categoria = 'Fondi Riserva';
                    } elseif (strpos($tt, 'FEE') !== false || strpos($tt, 'TRANSPORT') !== false || strpos($tt, 'SUBSCRIPTION') !== false || strpos($tt, 'STORAGE') !== false || strpos($tt, 'PAYABLE TO AMAZON') !== false || strpos($tt, 'SUCCESSFUL CHARGE') !== false) {
                        // fee, trasporti, abbonamenti, storage, payable...
                        // Distinzione: la parte "fee logistica/vendita" confluisce in Commissioni;
                        // il resto negli Operativi. Se non distingui, metti tutto in Operativi.
                        // Per semplicità: se contiene FBA/COMMISSION/FBAPERUNIT → Commissioni, altrimenti Operativi.
                        if (strpos($tt, 'FBA') !== false || strpos($tt, 'COMMISSION') !== false || strpos($tt, 'FBAPERUNIT') !== false) {
                            $categoria = 'Commissioni di Vendita/Logistica';
                        } else {
                            $categoria = 'Costi Operativi/Abbonamenti';
                        }
                    }
                }
                
                // -------- PRICE COMPONENTS - SOLO ORDER
if ($transactionType === 'Order' && $pt !== '' && abs($pa) > 0) {
    if ($pt === 'Principal') { $feeComponents['price']['principal'] += $pa; }
    elseif ($pt === 'Tax')   { $feeComponents['price']['tax'] += $pa; }
    else { 
        $feeComponents['price']['other_price'] += $pa;
        if (!isset($feeComponents['price']['by_type'][$pt])) {
            $feeComponents['price']['by_type'][$pt] = 0.0;
        }
        $feeComponents['price']['by_type'][$pt] += $pa;
    }
    $feeComponents['price']['total'] += $pa;
}

// -------- REFUND COMPONENTS - SOLO REFUND
if ($transactionType === 'Refund' && $pt !== '' && abs($pa) > 0) {
    if ($pt === 'Principal') { $feeComponents['refund']['principal'] += $pa; }
    elseif ($pt === 'Tax')   { $feeComponents['refund']['tax'] += $pa; }
    else { 
        $feeComponents['refund']['other_price'] += $pa;
        if (!isset($feeComponents['refund']['by_type'][$pt])) {
            $feeComponents['refund']['by_type'][$pt] = 0.0;
        }
        $feeComponents['refund']['by_type'][$pt] += $pa;
    }
    $feeComponents['refund']['total'] += $pa;
}

                // -------- ITEM RELATED FEES (dettaglio per type)
                $irt = trim((string)($row['item_related_fee_type'] ?? ''));
                $ira = (float)($row['item_related_fee_amount'] ?? 0);
                if (abs($ira) > 0) {
                    // totale sempre
                    $feeComponents['item_related_fees']['total'] += $ira;
                    // breakdown solo se ho il tipo
                    if ($irt !== '') {
                        if (!isset($feeComponents['item_related_fees']['by_type'][$irt])) {
                            $feeComponents['item_related_fees']['by_type'][$irt] = 0.0;
                        }
                        $feeComponents['item_related_fees']['by_type'][$irt] += $ira;
                    }
                }

                // -------- ORDER FEES (dettaglio per type)
                $oft = trim((string)($row['order_fee_type'] ?? ''));
                $ofa = (float)($row['order_fee_amount'] ?? 0);
                if (abs($ofa) > 0) {
                    $feeComponents['order_fees']['total'] += $ofa;
                    if ($oft !== '') {
                        if (!isset($feeComponents['order_fees']['by_type'][$oft])) {
                            $feeComponents['order_fees']['by_type'][$oft] = 0.0;
                        }
                        $feeComponents['order_fees']['by_type'][$oft] += $ofa;
                    }
                }

                // -------- SHIPMENT FEES (dettaglio per type)
                $sft = trim((string)($row['shipment_fee_type'] ?? ''));
                $sfa = (float)($row['shipment_fee_amount'] ?? 0);
                if (abs($sfa) > 0) {
                    $feeComponents['shipment_fees']['total'] += $sfa;
                    if ($sft !== '') {
                        if (!isset($feeComponents['shipment_fees']['by_type'][$sft])) {
                            $feeComponents['shipment_fees']['by_type'][$sft] = 0.0;
                        }
                        $feeComponents['shipment_fees']['by_type'][$sft] += $sfa;
                    }
                }


                // ===== Logica di attribuzione per righe ORDER - UNIFORMATA CON margins_engine.php =====
                if ($transactionType === 'Order') {
                    // $pt e $pa già definiti all'inizio del loop (FIX #1)
                    $irt  = trim((string)($row['item_related_fee_type'] ?? ''));
                    $ira  = (float)($row['item_related_fee_amount'] ?? 0);
                    $oft  = trim((string)($row['order_fee_type'] ?? ''));
                    $ofa  = (float)($row['order_fee_amount'] ?? 0);
                    $sft  = trim((string)($row['shipment_fee_type'] ?? ''));
                    $sfa  = (float)($row['shipment_fee_amount'] ?? 0);
                    $misc = (float)($row['misc_fee_amount'] ?? 0);
                    $othf = (float)($row['other_fee_amount'] ?? 0);

                    // UNIFORMATO: Aggiungi SOLO price_amount ai ricavi (come margins_engine.php)
                    // NON include più: promotion_amount, direct_payment_amount, other_amount
                    if (abs($pa) > 0) {
                        $ricavi = &self::ensureCategoryBucket($categoriesSummary, 'Ricavi Vendite', false);
                        $ricavi['importo_eur'] += $pa;
                        $kpiData['incassato_vendite'] += $pa;
                    }

                    // Commissioni/logistica: tutte le fee di riga
                    $fees = 0.0;
                    foreach ([$ira, $ofa, $sfa, $misc, $othf] as $fee) { $fees += (float)$fee; }
                    if (abs($fees) > 0) {
                        $comm = &self::ensureCategoryBucket($categoriesSummary, 'Commissioni di Vendita/Logistica', false);
                        $comm['importo_eur'] += $fees;
                        $kpiData['commissioni'] += abs($fees); // commissioni sempre positive nel KPI
                    }

                    // NON usare più rowSignedAmountEUR per le righe Order (evitiamo doppio conteggio)
                    $skipRowSigned = true;
                }
                
                // ===== Logica di attribuzione per righe REFUND =====
                if ($transactionType === 'Refund') {
                    // $pt e $pa già definiti all'inizio del loop
                    $irt  = trim((string)($row['item_related_fee_type'] ?? ''));
                    $ira  = (float)($row['item_related_fee_amount'] ?? 0);
                    $oft  = trim((string)($row['order_fee_type'] ?? ''));
                    $ofa  = (float)($row['order_fee_amount'] ?? 0);
                    $sft  = trim((string)($row['shipment_fee_type'] ?? ''));
                    $sfa  = (float)($row['shipment_fee_amount'] ?? 0);
                    $misc = (float)($row['misc_fee_amount'] ?? 0);
                    $othf = (float)($row['other_fee_amount'] ?? 0);

                    // Aggiungi price_amount (negativo) a "Rimborsi/Storni Clienti"
                    if (abs($pa) > 0) {
                        $rimborsi = &self::ensureCategoryBucket($categoriesSummary, 'Rimborsi/Storni Clienti', false);
                        $rimborsi['importo_eur'] += $pa;
                        $kpiData['rimborsi_netto'] += abs($pa);
                    }

                    // Fee dei Refund (es. RefundCommission +7.25) riducono le commissioni totali
                    $refundFees = 0.0;
                    foreach ([$ira, $ofa, $sfa, $misc, $othf] as $fee) { $refundFees += (float)$fee; }
                    if (abs($refundFees) > 0) {
                        $comm = &self::ensureCategoryBucket($categoriesSummary, 'Commissioni di Vendita/Logistica', false);
                        $comm['importo_eur'] += $refundFees;  // Fee positive riducono il totale negativo
                        // Sottrai dai KPI commissioni (se positivo, riduce; se negativo, aumenta)
                        $kpiData['commissioni'] -= $refundFees;
                    }

                    // NON usare più rowSignedAmountEUR per le righe Refund (evitiamo doppio conteggio)
                    $skipRowSigned = true;
                }
                
                // Per tutte le altre transazioni, usa la logica esistente
                if (!$skipRowSigned) {
                    $importoEur = self::rowSignedAmountEUR($row, $userId);
                    
                    // Aggrega per categoria esistente
                    $bucket = &self::ensureCategoryBucket($categoriesSummary, $categoria, self::isReserveFund($transactionType));
                    $bucket['importo_eur'] += $importoEur;
                    
                    // KPI per categorie non-Order
                    $isReserve = self::isReserveFund($transactionType);
                    if (!$isReserve) {
                        switch ($categoria) {
                            case 'Rimborsi/Storni Clienti':
                                $kpiData['rimborsi_netto'] += abs($importoEur);
                                break;
                            case 'Costi Operativi/Abbonamenti':
                                $kpiData['operativi'] += abs($importoEur);
                                break;
                            case 'Perdite e Rimborsi/Danni':
                                $kpiData['perdite_netto'] += abs($importoEur);
                                break;
                        }
                    }
                } else {
                    // Per righe Order, $importoEur non serve (già distribuito sopra)
                    $importoEur = 0;
                }
                
                // Skip Fondi Riserva dai KPI  
                $isReserve = self::isReserveFund($transactionType);
                
                // Inizializza categoria se non esiste (per categorie aggiuntive come Fondi Riserva)
                if (!isset($categoriesSummary[$categoria])) {
                    $categoriesSummary[$categoria] = [
                        'categoria' => $categoria,
                        'importo_eur' => 0,
                        'incidenza_percent_su_ricavi' => 0,
                        'ordini' => 0,
                        'transazioni' => 0,
                        'unita_vendute' => 0,
                        'unita_rimborsate' => 0,
                        'unita_smarrite' => 0,
                        'is_reserve' => $isReserve,
                        '_orders' => []
                    ];
                }
                
                // Aggrega transazioni per categoria (sempre)
                $bucket = &self::ensureCategoryBucket($categoriesSummary, $categoria, self::isReserveFund($transactionType));
                $bucket['transazioni']++;
                
                // Conta ordini unici per categoria
                if (!empty($row['order_id'])) {
                    $allOrders[$row['order_id']] = true;
                    $bucket['_orders'][$row['order_id']] = true;
                }
                
                // --- UNITÀ VENDUTE ---
                if ($transactionType === 'Order') {
                    $qty = (float)($row['quantity_purchased'] ?? 0);
                    $pt  = isset($row['price_type']) ? (string)$row['price_type'] : '';
                    if ($qty > 0 && ($pt === 'Principal' || $pt === '' || $pt === null || strtoupper($pt) === 'PRINCIPAL')) {
                        $bucket = &self::ensureCategoryBucket($categoriesSummary, $categoria, self::isReserveFund($transactionType));
                        $bucket['unita_vendute'] += $qty;
                        $kpiData['unita_vendute'] += $qty;
                    }
                }

                // --- UNITÀ RIMBORSATE ---
                // FIX #4: Amazon non popola quantity_purchased per Refund
                // Workaround: conta solo righe con price_type='Principal' (1 riga = 1 articolo rimborsato)
                if (stripos((string)$transactionType, 'refund') !== false) {
                    // Conta solo righe Principal (ogni ordine ha 1 riga Principal per articolo)
                    if ($pt === 'Principal') {
                        $bucket = &self::ensureCategoryBucket($categoriesSummary, $categoria, self::isReserveFund($transactionType));
                        $bucket['unita_rimborsate'] += 1;
                        $kpiData['unita_rimborsate'] += 1;
                    }
                }
                
                // Unità smarrite (perdite/danni)
                $lossTypes = ['WAREHOUSE_LOST', 'WAREHOUSE_LOST_MANUAL', 'WAREHOUSE_DAMAGE', 
                             'WAREHOUSE_DAMAGE_EXCEPTION', 'INBOUND_CARRIER_DAMAGE', 
                             'MISSING_FROM_INBOUND', 'MISSING_FROM_INBOUND_CLAWBACK',
                             'INCORRECT_FEES_ITEMS', 'Liquidations'];
                if (in_array($transactionType, $lossTypes)) {
                    $unita = abs(floatval($row['quantity_purchased'] ?? 0));
                    $bucket = &self::ensureCategoryBucket($categoriesSummary, $categoria, self::isReserveFund($transactionType));
                    $bucket['unita_smarrite'] += $unita;
                    $kpiData['unita_smarrite'] += $unita;
                }
                
                // Breakdown per transaction_type
                if (!isset($breakdownByType[$categoria])) {
                    $breakdownByType[$categoria] = [];
                }
                
                // FIX: Per Order, usa i valori effettivi invece di $importoEur che è 0
                if ($transactionType === 'Order' && $skipRowSigned) {
                    // Aggrega price_amount in Ricavi Vendite
                    if (abs($pa) > 0 && $categoria === 'Ricavi Vendite') {
                        if (!isset($breakdownByType[$categoria][$transactionType])) {
                            $breakdownByType[$categoria][$transactionType] = [
                                'transaction_type' => $transactionType,
                                'importo_eur' => 0,
                                'transazioni' => 0
                            ];
                        }
                        $breakdownByType[$categoria][$transactionType]['importo_eur'] += $pa;
                        $breakdownByType[$categoria][$transactionType]['transazioni'] += 1;
                    }
                } else {
                    // Per tutte le altre transazioni, logica originale
                    if (!isset($breakdownByType[$categoria][$transactionType])) {
                        $breakdownByType[$categoria][$transactionType] = [
                            'transaction_type' => $transactionType,
                            'importo_eur' => 0,
                            'transazioni' => 0
                        ];
                    }
                    $breakdownByType[$categoria][$transactionType]['importo_eur'] += $importoEur;
                    $breakdownByType[$categoria][$transactionType]['transazioni'] += 1;
                }
                
                // Raccogli dati Fondi Riserva separatamente
                if ($isReserve) {
                    if (!isset($reserveData[$transactionType])) {
                        $reserveData[$transactionType] = [
                            'transaction_type' => $transactionType,
                            'importo_eur' => 0,
                            'transazioni' => 0
                        ];
                    }
                    $reserveData[$transactionType]['importo_eur'] += $importoEur;
                    $reserveData[$transactionType]['transazioni'] += 1;
                }
                
                // KPI globali (escludi Fondi Riserva) - ora gestito nella logica specifica sopra
                if (!$isReserve) {
                    $kpiData['transazioni'] += 1;
                }

            }
            unset($bucket); // FIX #8: Rimuovi reference da loop principale per evitare conflitto con loop successivi
            
            // Finalizza conteggio ordini per categoria
            foreach ($categoriesSummary as &$cat) {
                $cat['ordini'] = isset($cat['_orders']) ? count($cat['_orders']) : 0;
                unset($cat['_orders']);
            }
            unset($cat); // FIX #6: Rimuovi reference per evitare corruzione memoria
            
// Calcola KPI finali
$kpiData['ordini'] = count($allOrders);

// Netto = somma di TUTTE le categorie NON riserva (gli importi sono già con segno corretto)
$net = 0.0;
foreach ($categoriesSummary as $c) {
    if (!empty($c['is_reserve'])) continue;
    $net += (float)$c['importo_eur'];
}
$kpiData['netto_operativo'] = $net;

            // CALCOLO COSTO MATERIA PRIMA (aggregato per order_id) - VERSION 2.0
            $stmtCosto = $pdo->prepare("
                SELECT COALESCE(SUM(order_qty.total_qty * p.costo_prodotto), 0.0) as costo_materia_prima
                FROM (
                    SELECT 
                        order_id,
                        product_id,
                        SUM(COALESCE(quantity_purchased, 0)) as total_qty
                    FROM `{$tableName}`
                    WHERE posted_date >= ? 
                        AND posted_date < ?
                        AND transaction_type = 'Order'
                        AND product_id IS NOT NULL
                    GROUP BY order_id, product_id
                ) order_qty
                LEFT JOIN products p ON order_qty.product_id = p.id
                WHERE p.costo_prodotto IS NOT NULL
                    AND p.costo_prodotto > 0
                    AND order_qty.total_qty > 0
            ");
            $stmtCosto->execute([
                $from instanceof DateTime ? $from->format('Y-m-d H:i:s') : (string)$from,
                $to instanceof DateTime ? $to->format('Y-m-d H:i:s') : (string)$to
            ]);
            $costoMateriaPrima = (float)$stmtCosto->fetchColumn();
            
            // Arrotonda tutti i KPI
            $kpiData = [
                'incassato_vendite' => $round($kpiData['incassato_vendite']),
                'commissioni' => $round($kpiData['commissioni']),
                'rimborsi_netto' => $round($kpiData['rimborsi_netto']),
                'operativi' => $round($kpiData['operativi']),
                'perdite_netto' => $round($kpiData['perdite_netto']),
                'netto_operativo' => $round($kpiData['netto_operativo']),
                'fondi_riserva' => isset($kpiData['fondi_riserva']) ? $round($kpiData['fondi_riserva']) : 0.0,
                'costo_materia_prima' => $round($costoMateriaPrima),
                'ordini' => (int)$kpiData['ordini'],
                'transazioni' => (int)$kpiData['transazioni'],
                'unita_vendute' => (int)$kpiData['unita_vendute'],
                'unita_rimborsate' => (int)$kpiData['unita_rimborsate'],
                'unita_smarrite' => (int)$kpiData['unita_smarrite'],
            ];
            
            // Converte breakdown in array
            foreach ($breakdownByType as $categoria => &$types) {
                $types = array_values($types);
            }
            unset($types); // FIX #6: Rimuovi reference per evitare corruzione memoria
            
            // FIX: Popola breakdown Commissioni dai feeComponents
            $breakdownByType['Commissioni di Vendita/Logistica'] = [];
            foreach (['item_related_fees', 'order_fees', 'shipment_fees'] as $bucket) {
                if (isset($feeComponents[$bucket]['by_type'])) {
                    foreach ($feeComponents[$bucket]['by_type'] as $feeType => $amount) {
                        if (abs($amount) > 0.01) {
                            $breakdownByType['Commissioni di Vendita/Logistica'][] = [
                                'transaction_type' => $feeType,
                                'importo_eur' => $round($amount),
                                'transazioni' => 0
                            ];
                        }
                    }
                }
            }
            
            // FIX: Popola breakdown per Commissioni di Vendita/Logistica dai feeComponents
            if (!isset($breakdownByType['Commissioni di Vendita/Logistica'])) {
                $breakdownByType['Commissioni di Vendita/Logistica'] = [];
            }
            
            // Aggiungi Item Related Fees al breakdown
            if (isset($feeComponents['item_related_fees']['by_type'])) {
                foreach ($feeComponents['item_related_fees']['by_type'] as $feeType => $feeAmount) {
                    if (abs($feeAmount) > 0.01) {
                        $breakdownByType['Commissioni di Vendita/Logistica'][] = [
                            'transaction_type' => $feeType,
                            'importo_eur' => $feeAmount,
                            'transazioni' => 0 // Non disponibile a questo livello
                        ];
                    }
                }
            }
            
            // Aggiungi Order Fees al breakdown
            if (isset($feeComponents['order_fees']['by_type'])) {
                foreach ($feeComponents['order_fees']['by_type'] as $feeType => $feeAmount) {
                    if (abs($feeAmount) > 0.01) {
                        $breakdownByType['Commissioni di Vendita/Logistica'][] = [
                            'transaction_type' => $feeType,
                            'importo_eur' => $feeAmount,
                            'transazioni' => 0
                        ];
                    }
                }
            }
            
            // Aggiungi Shipment Fees al breakdown
            if (isset($feeComponents['shipment_fees']['by_type'])) {
                foreach ($feeComponents['shipment_fees']['by_type'] as $feeType => $feeAmount) {
                    if (abs($feeAmount) > 0.01) {
                        $breakdownByType['Commissioni di Vendita/Logistica'][] = [
                            'transaction_type' => $feeType,
                            'importo_eur' => $feeAmount,
                            'transazioni' => 0
                        ];
                    }
                }
            }
            
            // Calcolo incidenze % su ricavi vendite (evita divisione per 0)
            $baseRicavi = abs($kpiData['incassato_vendite']) > 0.00001 ? $kpiData['incassato_vendite'] : 1;
            foreach ($categoriesSummary as &$catTmp) {
                $catTmp['incidenza_percent_su_ricavi'] = $round(($catTmp['importo_eur'] / $baseRicavi) * 100, 2);
                // arrotonda tutte le metriche numeriche della riga categoria
                $catTmp['importo_eur'] = $round($catTmp['importo_eur']);
                $catTmp['unita_vendute'] = (int)$catTmp['unita_vendute'];
                $catTmp['unita_rimborsate'] = (int)$catTmp['unita_rimborsate'];
                $catTmp['unita_smarrite'] = (int)$catTmp['unita_smarrite'];
            }
            unset($catTmp);

            // FIX #7: Forza copia dell'array per rompere TUTTE le reference residue
            $categoriesSummary = array_values($categoriesSummary);

            // Gestione Fondi Riserva (inclusione/esclusione su richiesta)
            if (!$includeReserve) {
                $categoriesSummary = array_values(array_filter($categoriesSummary, function($c){
                    return empty($c['is_reserve']);
                }));
            } else {
                // Se inclusi, somma al netto operativo
                $fondi = 0.0;
                foreach ($categoriesSummary as $c) {
                    if (!empty($c['is_reserve'])) { $fondi += $c['importo_eur']; }
                }
                $kpiData['fondi_riserva'] = $fondi;
                $kpiData['netto_operativo'] += $fondi;
            }
            
            // Arrotonda e ordina fee components
            foreach (['principal','tax','other_price','total'] as $kPrice) {
                $feeComponents['price'][$kPrice] = $round($feeComponents['price'][$kPrice]);
            }
            unset($kPrice); // FIX #8: Rimuovi reference
            
            // FIX #8: Rinomina $bucket -> $bucketFee per evitare conflitto con reference precedenti
            foreach (['item_related_fees','order_fees','shipment_fees'] as $bucketFee) {
                $feeComponents[$bucketFee]['total'] = $round($feeComponents[$bucketFee]['total']);
                arsort($feeComponents[$bucketFee]['by_type']);
                foreach ($feeComponents[$bucketFee]['by_type'] as $kFee => $vFee) {
                    $feeComponents[$bucketFee]['by_type'][$kFee] = $round($vFee);
                }
            }
            unset($bucketFee, $kFee, $vFee); // FIX #8: Rimuovi reference
            
            return [
                'kpi' => $kpiData,
                'categorie' => $categoriesSummary,
                'breakdown_by_type' => $breakdownByType,
                'reserve' => array_values($reserveData),
                'fee_components' => $feeComponents
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('orderinsights', 'ERROR', 'Errore monthSummary: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Summary giornaliero
     */
    public static function daySummary($day, $userId) {
        $round = function($v, $dec = 2) {
            if ($v === null) return 0.0;
            $x = round((float)$v, $dec, PHP_ROUND_HALF_UP);
            return (abs($x) < pow(10, -$dec)) ? 0.0 : $x;
        };
        
        try {
            $dayStart = $day . ' 00:00:00';
            $dayEnd = $day . ' 23:59:59';
            
            $summary = self::monthSummary($dayStart, $dayEnd, $userId);
            
            // Calcola Top 5 transaction_type del giorno usando i dati già processati
            $tableName = "report_settlement_{$userId}";
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("
                SELECT 
                    rs.transaction_type,
                    rs.currency,
                    rs.price_type,
                    rs.price_amount,
                    rs.item_related_fee_type,
                    rs.item_related_fee_amount,
                    rs.order_fee_type,
                    rs.order_fee_amount,
                    rs.shipment_fee_type,
                    rs.shipment_fee_amount,
                    rs.misc_fee_amount,
                    rs.other_fee_amount,
                    rs.promotion_amount,
                    rs.direct_payment_amount,
                    rs.other_amount,
                    rs.posted_date
                FROM `{$tableName}` rs
                WHERE rs.posted_date >= ? AND rs.posted_date < ?
            ");
            $stmt->execute([
    $dayStart,
    $dayEnd
]);

// STREAMING: processa riga per riga
$rawData = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rawData[] = $row;
}
            
            // Top 5 transaction_type del giorno per importo assoluto (senza query aggiuntive)
            $byType = [];
            foreach ($rawData as $row) {
                $type = $row['transaction_type'] ?? 'N/A';
                $amt = self::rowSignedAmountEUR($row, $userId);
                if (!isset($byType[$type])) $byType[$type] = 0.0;
                $byType[$type] += $amt;
            }
            arsort($byType); // ordina per importo desc
            $topTypes = [];
            $k = 0;
            foreach ($byType as $type => $val) {
                $topTypes[] = ['transaction_type' => $type, 'importo_eur' => $round($val)];
                if (++$k >= 5) break;
            }
            
            // Aggiungi Top 5 al risultato
            $summary['top_types'] = $topTypes;
            
            return $summary;
            
        } catch (Exception $e) {
            CentralLogger::log('orderinsights', 'ERROR', 'Errore daySummary: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Lista ordini per giorno
     */
    public static function ordersByDay($day, $userId) {
        $tableName = "report_settlement_{$userId}";
        $pdo = getDbConnection();

        $tz = new DateTimeZone('Europe/Rome');
        $dayStart = (new DateTime($day, $tz))->setTime(0,0,0);
        $dayEnd   = (clone $dayStart)->modify('+1 day');

        $sql = "
            SELECT 
                rs.order_id,
                SUM(
                    COALESCE(rs.price_amount,0)+
                    COALESCE(rs.item_related_fee_amount,0)+
                    COALESCE(rs.order_fee_amount,0)+
                    COALESCE(rs.shipment_fee_amount,0)+
                    COALESCE(rs.misc_fee_amount,0)+
                    COALESCE(rs.other_fee_amount,0)+
                    COALESCE(rs.promotion_amount,0)+
                    COALESCE(rs.direct_payment_amount,0)+
                    COALESCE(rs.other_amount,0)
                ) AS incassato_eur,
                SUM(
                    CASE 
                      WHEN rs.transaction_type='Order' 
                           AND (rs.price_type='Principal' OR rs.price_type IS NULL OR rs.price_type='')
                      THEN COALESCE(rs.quantity_purchased,0) 
                      ELSE 0 
                    END
                ) AS unita_vendute,
                COUNT(*) AS transazioni,
                MIN(rs.marketplace_name) AS marketplace
            FROM `{$tableName}` rs
            WHERE rs.posted_date >= ? AND rs.posted_date < ?
              AND rs.order_id IS NOT NULL AND rs.order_id != ''
            GROUP BY rs.order_id
            ORDER BY incassato_eur DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $dayStart->format('Y-m-d H:i:s'));
        $stmt->bindValue(2, $dayEnd->format('Y-m-d H:i:s'));
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Indice giorni paginato
     */
    public static function dayIndex($from, $to, $userId, $offset = 0, $limit = 30) {
        try {
            $tableName = "report_settlement_{$userId}";
            $pdo = getDbConnection();
            
            // Recupera TUTTE le transazioni del periodo raggruppate per giorno
            // Per ogni giorno, calcola i ricavi esattamente come fa monthSummary
            $stmt = $pdo->prepare("
                SELECT
                    DATE(rs.posted_date) AS giorno,
                    COUNT(*) AS transazioni,
                    COUNT(DISTINCT CASE WHEN rs.order_id IS NOT NULL AND rs.order_id != '' THEN rs.order_id END) AS ordini,
                    -- Ricavi Vendite: somma price_amount SOLO per transazioni Order (esclude Refund e altri)
                    SUM(CASE 
                        WHEN rs.transaction_type = 'Order' AND rs.price_amount IS NOT NULL 
                        THEN rs.price_amount 
                        ELSE 0 
                    END) AS incassato_vendite,
                    -- Refund: somma price_amount SOLO per transazioni Refund
                    SUM(CASE 
                        WHEN rs.transaction_type = 'Refund' AND rs.price_amount IS NOT NULL 
                        THEN rs.price_amount 
                        ELSE 0 
                    END) AS refund_totale,
                    -- Numero ordini con refund
                    COUNT(DISTINCT CASE 
                        WHEN rs.transaction_type = 'Refund' AND rs.order_id IS NOT NULL AND rs.order_id != '' 
                        THEN rs.order_id 
                    END) AS refund_ordini,
                    -- Netto operativo: somma di tutti gli importi (per confronto)
                    SUM(
                        COALESCE(rs.price_amount,0)+COALESCE(rs.item_related_fee_amount,0)+COALESCE(rs.order_fee_amount,0)+
                        COALESCE(rs.shipment_fee_amount,0)+COALESCE(rs.misc_fee_amount,0)+COALESCE(rs.other_fee_amount,0)+
                        COALESCE(rs.promotion_amount,0)+COALESCE(rs.direct_payment_amount,0)+COALESCE(rs.other_amount,0)
                    ) AS importo_eur
                FROM `{$tableName}` rs
                WHERE rs.posted_date >= ? AND rs.posted_date < ?
                GROUP BY giorno
                ORDER BY giorno DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $from instanceof DateTime ? $from->format('Y-m-d H:i:s') : (string)$from, PDO::PARAM_STR);
            $stmt->bindValue(2, $to instanceof DateTime ? $to->format('Y-m-d H:i:s') : (string)$to, PDO::PARAM_STR);
            $stmt->bindValue(3, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(4, (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM (
                    SELECT 1 
                    FROM `{$tableName}` 
                    WHERE posted_date >= ? AND posted_date < ?
                    GROUP BY DATE(posted_date)
                ) x
            ");
            $countStmt->execute([
                $from instanceof DateTime ? $from->format('Y-m-d H:i:s') : (string)$from,
                $to instanceof DateTime ? $to->format('Y-m-d H:i:s') : (string)$to
            ]);
            $totalDays = (int)$countStmt->fetchColumn();

            return ['rows' => $rows, 'total' => $totalDays];
            
        } catch (Exception $e) {
            CentralLogger::log('orderinsights', 'ERROR', 'Errore dayIndex: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Dettaglio righe per ordine
     */
    public static function orderDetail($orderId, $userId) {
        try {
            $tableName = "report_settlement_{$userId}";
            
            $pdo = getDbConnection();
            $stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($tableName) . "'");
            if (!$stmt->fetchColumn()) {
                throw new Exception("Tabella {$tableName} non trovata");
            }
            
            $stmt = $pdo->prepare("
                SELECT *
                FROM `{$tableName}`
                WHERE order_id = ?
                ORDER BY posted_date ASC, transaction_type ASC
            ");
            $stmt->execute([$orderId]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            CentralLogger::log('orderinsights', 'ERROR', 'Errore orderDetail: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Verifica se la conversione EUR è garantita
     */
    public static function hasEurConversionWarning($userId) {
        try {
            $tableName = "report_settlement_{$userId}";
            
            $pdo = getDbConnection();
            $stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($tableName) . "'");
            if (!$stmt->fetchColumn()) {
                return false;
            }
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `{$tableName}` 
                WHERE currency != 'EUR' AND currency IS NOT NULL
                LIMIT 1
            ");
            $stmt->execute();
            
            return (bool) $stmt->fetchColumn();
            
        } catch (Exception $e) {
            return true; // In caso di errore, mostra warning
        }
    }
}
?>