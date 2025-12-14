<?php
/**
 * EasyShip API Endpoints
 * File: modules/easyship/easyship_api.php
 */

session_start();

// Gestione AJAX
$action = $_REQUEST['action'] ?? null;
if ($action) {
    header('Content-Type: application/json');
    error_reporting(E_ALL);
    
    // Error handlers per AJAX
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        echo json_error("Errore [$errno]: $errstr in $errfile riga $errline");
        exit;
    });
    set_exception_handler(function($ex) {
        echo json_error("Eccezione: " . $ex->getMessage());
        exit;
    });
    
    require_once 'config_easyship.php';

// Include PHPMailer per sistema email unificato
require_once '../margynomic/gestione_vendor.php';

    // Funzione di normalizzazione data per Amazon (globale)
    function normalizeToIsoDate($dateInput) {
        if (empty($dateInput) || $dateInput === 'N/A') {
            return false;
        }
        
        $dateInput = trim($dateInput);
        
        // Se già in formato ISO (YYYY-MM-DD), verifica e restituisci
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
            $timestamp = strtotime($dateInput);
            return $timestamp !== false ? $dateInput : false;
        }
        
        // Prova conversioni comuni
        $formats = [
            'd/m/Y',    // 31/12/2028
            'd-m-Y',    // 31-12-2028  
            'd.m.Y',    // 31.12.2028
            'm/d/Y',    // 12/31/2028
            'Y/m/d',    // 2028/12/31
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateInput);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        
        // Ultimo tentativo con strtotime
        $timestamp = strtotime($dateInput);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : false;
    }
    
    // Autenticazione
    $currentUser = requireEasyShipAuth();
    $userId = $currentUser['id'];
    
    switch ($action) {
        
        case 'autocomplete':
            $query = trim($_GET['q'] ?? '');
            $products = getProductsAutocomplete($userId, $query);
            echo json_success($products);
            break;

        case 'validateProduct':
            $productName = trim($_GET['name'] ?? '');
            if (empty($productName)) {
                echo json_error('Nome prodotto richiesto');
                break;
            }
            
            try {
                $db = getDbConnection();
                
                // Prima prova corrispondenza esatta
                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE user_id = ? AND nome = ?");
                $stmt->execute([$userId, $productName]);
                $exactMatch = $stmt->fetchColumn() > 0;
                
                if ($exactMatch) {
                    echo json_success(['valid' => true]);
                    break;
                }
                
                // Se non trova corrispondenza esatta, prova con LIKE (più flessibile)
                $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE user_id = ? AND nome LIKE ?");
                $stmt->execute([$userId, '%' . $productName . '%']);
                $likeMatch = $stmt->fetchColumn() > 0;
                
                if ($likeMatch) {
                    echo json_success(['valid' => true]);
                    break;
                }
                
                // Se il prodotto non esiste nel database, non è valido
                echo json_success(['valid' => false]);
                
            } catch (Exception $e) {
                echo json_error('Errore validazione prodotto: ' . $e->getMessage());
            }
            break;
            
        case 'saveDraft':
        case 'confirmShipment':
            $payload = json_decode(file_get_contents('php://input'), true);
            if (!$payload || !isset($payload['boxes'])) {
                echo json_error('Payload non valido');
                break;
            }
            
            // Validazioni
            if (empty($payload['boxes'])) {
                echo json_error('Almeno un collo è richiesto');
                break;
            }
            
            $hasProducts = false;
            foreach ($payload['boxes'] as $box) {
                if (!empty($box['prodotti'])) {
                    foreach ($box['prodotti'] as $prod) {
                        if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                            $hasProducts = true;
                            break 2;
                        }
                    }
                }
            }
            
            if (!$hasProducts) {
                echo json_error('Almeno un prodotto con quantità > 0 è richiesto');
                break;
            }
            
            // Validazioni per conferma
            if ($action === 'confirmShipment') {
                foreach ($payload['boxes'] as $boxIndex => $box) {
                    $dims = $box['dimensioni'] ?? [];
                    if (empty($dims['altezza']) || empty($dims['larghezza']) || 
                        empty($dims['lunghezza']) || empty($dims['peso'])) {
                        $boxNum = $box['numero'] ?? ($boxIndex + 1);
                        echo json_error("Box {$boxNum}: inserire altezza, larghezza, lunghezza e peso per confermare la spedizione");
                        exit;
                    }
                    
                    // Validazione valori numerici positivi
                    if (floatval($dims['altezza']) <= 0 || floatval($dims['larghezza']) <= 0 || 
                        floatval($dims['lunghezza']) <= 0 || floatval($dims['peso']) <= 0) {
                        $boxNum = $box['numero'] ?? ($boxIndex + 1);
                        echo json_error("Box {$boxNum}: le dimensioni e il peso devono essere maggiori di zero");
                        exit;
                    }
                }
            }
            
            try {
                $db = getDbConnection();
                $db->beginTransaction();
                
                // Genera nome spedizione
                $shipmentName = generateShipmentName($userId);
                $status = ($action === 'confirmShipment') ? 'Completed' : 'Draft';
                
                // Inserisci testata
                $stmt = $db->prepare("
                    INSERT INTO shipments (user_id, name, status) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$userId, $shipmentName, $status]);
                $shipmentId = $db->lastInsertId();
                
                // Inserisci items
                foreach ($payload['boxes'] as $box) {
                    $boxNo = intval($box['numero']);
                    
                    if (!empty($box['prodotti'])) {
                        foreach ($box['prodotti'] as $prod) {
                            if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                                $stmt = $db->prepare("
                                    INSERT INTO shipment_items (user_id, shipment_id, box_no, product_name, quantity, expiry_date)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                // Sanifica data: accetta solo valori validi o NULL
                                $expiryDate = null;
                                if (!empty($prod['scadenza']) && $prod['scadenza'] !== '0000-00-00') {
                                    $dateTest = DateTime::createFromFormat('Y-m-d', $prod['scadenza']);
                                    if ($dateTest && $dateTest->format('Y-m-d') === $prod['scadenza']) {
                                        $expiryDate = $prod['scadenza'];
                                    } else {
                                        error_log("EASYSHIP: Data scadenza invalida ignorata per prodotto '{$prod['nome']}': '{$prod['scadenza']}'");
                                    }
                                }
                                $stmt->execute([
                                    $userId,
                                    $shipmentId, 
                                    $boxNo, 
                                    $prod['nome'], 
                                    intval($prod['quantita']), 
                                    $expiryDate
                                ]);
                            }
                        }
                    }
                    
                    // Inserisci dimensioni se presenti
                    $dims = $box['dimensioni'] ?? [];
                    if (!empty($dims['altezza']) || !empty($dims['larghezza']) || 
                        !empty($dims['lunghezza']) || !empty($dims['peso'])) {
                        $stmt = $db->prepare("
                            INSERT INTO shipment_boxes (user_id, shipment_id, box_no, height_cm, width_cm, length_cm, weight_kg)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                            height_cm = VALUES(height_cm), 
                            width_cm = VALUES(width_cm),
                            length_cm = VALUES(length_cm), 
                            weight_kg = VALUES(weight_kg)
                        ");
                        $stmt->execute([
                            $userId,
                            $shipmentId,
                            $boxNo,
                            !empty($dims['altezza']) ? floatval($dims['altezza']) : null,
                            !empty($dims['larghezza']) ? floatval($dims['larghezza']) : null,
                            !empty($dims['lunghezza']) ? floatval($dims['lunghezza']) : null,
                            !empty($dims['peso']) ? floatval($dims['peso']) : null
                        ]);
                    }
                }
                
                // Se conferma, crea cartelle e invia email
                if ($action === 'confirmShipment') {
                    $boxCount = count($payload['boxes']);
                    createShipmentFolders($shipmentName, $boxCount);
                    
                    // Invia email di conferma
                    sendConfirmationEmail($userId, $shipmentId, $shipmentName, $payload);
                }
                
                $db->commit();
                
                logEasyShipOperation("Spedizione $action", [
                    'user_id' => $userId,
                    'shipment_id' => $shipmentId,
                    'name' => $shipmentName,
                    'status' => $status
                ]);
                
                echo json_success([
                    'shipment_id' => $shipmentId,
                    'name' => $shipmentName,
                    'status' => $status
                ], ucfirst($action) . ' completato con successo!');
                
            } catch (Exception $e) {
                $db->rollback();
                echo json_error('Errore database: ' . $e->getMessage());
            }
            break;
            
        case 'getShipments':
            try {
                $db = getDbConnection();
                $stmt = $db->prepare("
                    SELECT s.id, s.name, s.status, 
                           DATE_FORMAT(s.created_at, '%d-%m-%Y %H:%i') as created_at,
                           COUNT(DISTINCT si.box_no) as total_boxes,
                           SUM(si.quantity) as total_units
                    FROM shipments s
                    LEFT JOIN shipment_items si ON s.id = si.shipment_id
                    WHERE s.user_id = ?
                    GROUP BY s.id
                    ORDER BY s.id DESC
                ");
                $stmt->execute([$userId]);
                $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_success($shipments);
                
            } catch (Exception $e) {
                echo json_error('Errore caricamento spedizioni: ' . $e->getMessage());
            }
            break;

        case 'getFlowStats':
            try {
                $db = getDbConnection();
                $stats = [];
                
                // Card 1: Spedizioni completate
                $stmt = $db->prepare("SELECT COUNT(*) FROM shipments WHERE user_id = ? AND status = 'Completed'");
                $stmt->execute([$userId]);
                $stats['completed'] = $stmt->fetchColumn();
                
                // Card 2: Colli totali + Volume
                $stmt = $db->prepare("
                    SELECT COUNT(*) as total_boxes, 
                           SUM(height_cm * width_cm * length_cm / 1000000) as total_volume_m3
                    FROM shipment_boxes sb
                    JOIN shipments s ON sb.shipment_id = s.id
                    WHERE s.user_id = ? AND s.status = 'Completed'
                ");
                $stmt->execute([$userId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $stats['total_boxes'] = $result['total_boxes'] ?? 0;
                $stats['total_volume'] = round($result['total_volume_m3'] ?? 0, 2);
                
// Card 3: Unità spedite
$stmt = $db->prepare("
    SELECT SUM(si.quantity) as total_units
    FROM shipment_items si
    JOIN shipments s ON si.shipment_id = s.id
    WHERE s.user_id = ? AND s.status = 'Completed'
");
$stmt->execute([$userId]);
$stats['total_units'] = $stmt->fetchColumn() ?? 0;

// Peso totale (calcolo separato per evitare duplicazioni)
$stmt = $db->prepare("
    SELECT SUM(sb.weight_kg) as total_weight
    FROM shipment_boxes sb
    JOIN shipments s ON sb.shipment_id = s.id
    WHERE s.user_id = ? AND s.status = 'Completed'
");
$stmt->execute([$userId]);
$stats['total_weight'] = round($stmt->fetchColumn() ?? 0, 2);
                
                // Card 4: Bozze + Annullate
                $stmt = $db->prepare("SELECT COUNT(*) FROM shipments WHERE user_id = ? AND status = 'Draft'");
                $stmt->execute([$userId]);
                $stats['draft'] = $stmt->fetchColumn();
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM shipments WHERE user_id = ? AND status = 'Cancelled'");
                $stmt->execute([$userId]);
                $stats['cancelled'] = $stmt->fetchColumn();
                
// Card 5: Top prodotti (>50 unità in 90gg)
$stmt = $db->prepare("
    SELECT si.product_name, SUM(si.quantity) as total
    FROM shipment_items si
    JOIN shipments s ON si.shipment_id = s.id
    WHERE s.user_id = ? AND s.status = 'Completed' 
        AND s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY si.product_name
    HAVING total > 50
    ORDER BY total DESC
");
$stmt->execute([$userId]);
$allTopProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['top_products'] = array_slice($allTopProducts, 0, 3);
$stats['top_products_total'] = count($allTopProducts);

// Card 6: Prodotti regolari (10-50 unità in 90gg)
$stmt = $db->prepare("
    SELECT si.product_name, SUM(si.quantity) as total
    FROM shipment_items si
    JOIN shipments s ON si.shipment_id = s.id
    WHERE s.user_id = ? AND s.status = 'Completed' 
        AND s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY si.product_name
    HAVING total >= 10 AND total <= 50
    ORDER BY total DESC
");
$stmt->execute([$userId]);
$allRegularProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['regular_products'] = array_slice($allRegularProducts, 0, 3);
$stats['regular_products_total'] = count($allRegularProducts);

// Card 7: Prodotti scarsi (<10 unità in 90gg)
$stmt = $db->prepare("
    SELECT si.product_name, SUM(si.quantity) as total
    FROM shipment_items si
    JOIN shipments s ON si.shipment_id = s.id
    WHERE s.user_id = ? AND s.status = 'Completed' 
        AND s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY si.product_name
    HAVING total < 10
    ORDER BY total DESC
");
$stmt->execute([$userId]);
$allLowProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['low_products'] = array_slice($allLowProducts, 0, 3);
$stats['low_products_total'] = count($allLowProducts);
                
                echo json_success($stats);
                
            } catch (Exception $e) {
                echo json_error('Errore caricamento statistiche: ' . $e->getMessage());
            }
            break;
            
        case 'getShipmentDetails':
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_error('ID spedizione non valido');
                break;
            }
            
            try {
                $db = getDbConnection();
                
                // Verifica proprietà 
                $stmt = $db->prepare("SELECT name, status FROM shipments WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$shipment) {
                    echo json_error('Spedizione non trovata');
                    break;
                }
                
                // Ottieni dettagli box e prodotti
                $stmt = $db->prepare("
                    SELECT si.box_no, si.product_name, si.quantity, si.expiry_date,
                           sb.height_cm, sb.width_cm, sb.length_cm, sb.weight_kg
                    FROM shipment_items si
                    LEFT JOIN shipment_boxes sb ON si.shipment_id = sb.shipment_id AND si.box_no = sb.box_no
                    WHERE si.shipment_id = ?
                    ORDER BY si.box_no, si.id
                ");
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Raggruppa per box
                $boxes = [];
                foreach ($items as $item) {
                    $boxNo = $item['box_no'];
                    if (!isset($boxes[$boxNo])) {
                        $boxes[$boxNo] = [
                            'numero' => $boxNo,
                            'dimensioni' => [
                                'altezza' => $item['height_cm'] ?? '',
                                'larghezza' => $item['width_cm'] ?? '',
                                'lunghezza' => $item['length_cm'] ?? '',
                                'peso' => $item['weight_kg'] ?? ''
                            ],
                            'prodotti' => []
                        ];
                    }
                    
                    $boxes[$boxNo]['prodotti'][] = [
                        'nome' => $item['product_name'],
                        'quantita' => $item['quantity'],
                        'scadenza' => $item['expiry_date'] ?? ''
                    ];
                }
                
                echo json_success([
                    'shipment' => $shipment,
                    'boxes' => array_values($boxes)
                ]);
                
            } catch (Exception $e) {
                echo json_error('Errore caricamento dettagli: ' . $e->getMessage());
            }
            break;
            
        case 'updateShipmentComplete':
            $payload = json_decode(file_get_contents('php://input'), true);
            $id = intval($payload['id'] ?? 0);
            
            if ($id <= 0 || !isset($payload['boxes'])) {
                echo json_error('Dati non validi');
                break;
            }
            
            try {
                $db = getDbConnection();
                $db->beginTransaction();
                
                // Verifica proprietà e stato
                $stmt = $db->prepare("SELECT name, status FROM shipments WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$shipment) {
                    echo json_error('Spedizione non trovata');
                    break;
                }
                
                // Cancella dettagli esistenti
                $stmt = $db->prepare("DELETE FROM shipment_items WHERE shipment_id = ?");
                $stmt->execute([$id]);
                
                $stmt = $db->prepare("DELETE FROM shipment_boxes WHERE shipment_id = ?");
                $stmt->execute([$id]);
                
                // Reinserisci dettagli
                foreach ($payload['boxes'] as $box) {
                    $boxNo = intval($box['numero']);
                    
                    if (!empty($box['prodotti'])) {
                        foreach ($box['prodotti'] as $prod) {
                            if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                                $stmt = $db->prepare("
                                    INSERT INTO shipment_items (user_id, shipment_id, box_no, product_name, quantity, expiry_date)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                ");
                                // Sanifica data: accetta solo valori validi o NULL
                                $expiryDate = null;
                                if (!empty($prod['scadenza']) && $prod['scadenza'] !== '0000-00-00') {
                                    $dateTest = DateTime::createFromFormat('Y-m-d', $prod['scadenza']);
                                    if ($dateTest && $dateTest->format('Y-m-d') === $prod['scadenza']) {
                                        $expiryDate = $prod['scadenza'];
                                    } else {
                                        error_log("EASYSHIP: Data scadenza invalida ignorata per prodotto '{$prod['nome']}': '{$prod['scadenza']}'");
                                    }
                                }
                                $stmt->execute([$userId, $id, $boxNo, $prod['nome'], intval($prod['quantita']), $expiryDate]);
                            }
                        }
                    }
                    
                    // Dimensioni
                    $dims = $box['dimensioni'] ?? [];
                    if (!empty($dims['altezza']) || !empty($dims['larghezza']) || 
                        !empty($dims['lunghezza']) || !empty($dims['peso'])) {
                        $stmt = $db->prepare("
                            INSERT INTO shipment_boxes (user_id, shipment_id, box_no, height_cm, width_cm, length_cm, weight_kg)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $userId, $id, $boxNo,
                            !empty($dims['altezza']) ? floatval($dims['altezza']) : null,
                            !empty($dims['larghezza']) ? floatval($dims['larghezza']) : null,
                            !empty($dims['lunghezza']) ? floatval($dims['lunghezza']) : null,
                            !empty($dims['peso']) ? floatval($dims['peso']) : null
                        ]);
                    }
                }
                
                // Aggiorna status a Completed e timestamp
                $stmt = $db->prepare("UPDATE shipments SET status = 'Completed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                
                // Crea cartelle per spedizione completata
                $boxCount = count($payload['boxes']);
                createShipmentFolders($shipment['name'], $boxCount);
                
                $db->commit();
                
                // Invia email di aggiornamento
                sendConfirmationEmailUpdate($userId, $id, $shipment['name'], $payload);
                
                echo json_success(['shipment_id' => $id], "Spedizione completata con successo!");
                
            } catch (Exception $e) {
                $db->rollback();
                echo json_error('Errore aggiornamento: ' . $e->getMessage());
            }
            break;
            
        case 'deleteShipment':
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_error('ID non valido');
                break;
            }
            
            try {
                $db = getDbConnection();
                
                // Verifica proprietà e stato (solo Draft eliminabili)
                $stmt = $db->prepare("SELECT name, status FROM shipments WHERE id = ? AND user_id = ? AND status = 'Draft'");
                $stmt->execute([$id, $userId]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$shipment) {
                    echo json_error('Spedizione non trovata o non eliminabile');
                    break;
                }
                
                // Elimina (CASCADE eliminerà automaticamente items e boxes)
                $stmt = $db->prepare("DELETE FROM shipments WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_success([], 'Spedizione eliminata con successo!');
                
            } catch (Exception $e) {
                echo json_error('Errore eliminazione: ' . $e->getMessage());
            }
            break;
            
        case 'cancelAndDuplicate':
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_error('ID non valido');
                break;
            }
            
            try {
                $db = getDbConnection();
                $db->beginTransaction();
                
                // Verifica proprietà e stato
                $stmt = $db->prepare("SELECT name FROM shipments WHERE id = ? AND user_id = ? AND status = 'Completed'");
                $stmt->execute([$id, $userId]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$shipment) {
                    echo json_error('Spedizione non trovata o non annullabile');
                    break;
                }
                
                // 1. ANNULLA la spedizione originale
                $stmt = $db->prepare("UPDATE shipments SET status = 'Cancelled', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                
                // 2. DUPLICA la spedizione
                $newShipmentName = generateShipmentName($userId);
                
                $stmt = $db->prepare("
                    INSERT INTO shipments (user_id, name, status) 
                    VALUES (?, ?, 'Draft')
                ");
                $stmt->execute([$userId, $newShipmentName]);
                $newShipmentId = $db->lastInsertId();
                
                // Copia items
                $stmt = $db->prepare("
                    INSERT INTO shipment_items (user_id, shipment_id, box_no, product_name, quantity, expiry_date)
                    SELECT ?, ?, box_no, product_name, quantity, expiry_date
                    FROM shipment_items WHERE shipment_id = ?
                ");
                $stmt->execute([$userId, $newShipmentId, $id]);
                
                // Copia boxes
                $stmt = $db->prepare("
                    INSERT INTO shipment_boxes (user_id, shipment_id, box_no, height_cm, width_cm, length_cm, weight_kg)
                    SELECT ?, ?, box_no, height_cm, width_cm, length_cm, weight_kg
                    FROM shipment_boxes WHERE shipment_id = ?
                ");
                $stmt->execute([$userId, $newShipmentId, $id]);
                
                // 3. CARICA dati per modifica
                $stmt = $db->prepare("
                    SELECT si.box_no, si.product_name, si.quantity, si.expiry_date,
                           sb.height_cm, sb.width_cm, sb.length_cm, sb.weight_kg
                    FROM shipment_items si
                    LEFT JOIN shipment_boxes sb ON si.shipment_id = sb.shipment_id AND si.box_no = sb.box_no
                    WHERE si.shipment_id = ?
                    ORDER BY si.box_no, si.id
                ");
                $stmt->execute([$newShipmentId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Raggruppa per box
                $boxes = [];
                foreach ($items as $item) {
                    $boxNo = $item['box_no'];
                    if (!isset($boxes[$boxNo])) {
                        $boxes[$boxNo] = [
                            'numero' => $boxNo,
                            'dimensioni' => [
                                'altezza' => $item['height_cm'] ?? '',
                                'larghezza' => $item['width_cm'] ?? '',
                                'lunghezza' => $item['length_cm'] ?? '',
                                'peso' => $item['weight_kg'] ?? ''
                            ],
                            'prodotti' => []
                        ];
                    }
                    
                    $boxes[$boxNo]['prodotti'][] = [
                        'nome' => $item['product_name'],
                        'quantita' => $item['quantity'],
                        'scadenza' => $item['expiry_date'] ?? ''
                    ];
                }
                
                // 4. INVIA email admin annullamento
                sendCancellationEmail($userId, $id, $shipment['name'], $newShipmentId, $newShipmentName);
                
                $db->commit();
                
                echo json_success([
                    'new_shipment_id' => $newShipmentId,
                    'shipment_data' => [
                        'shipment' => ['name' => $newShipmentName, 'status' => 'Draft'],
                        'boxes' => array_values($boxes)
                    ]
                ], 'Spedizione annullata e duplicata!');
                
            } catch (Exception $e) {
                $db->rollback();
                echo json_error('Errore: ' . $e->getMessage());
            }
            break;

        case 'changeStatus':
            $id = intval($_POST['id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';
            
            if ($id <= 0 || !in_array($newStatus, ['Draft', 'Completed'])) {
                echo json_error('Parametri non validi');
                break;
            }
            
            try {
                $db = getDbConnection();
                
                // Verifica proprietà 
                $stmt = $db->prepare("SELECT name FROM shipments WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$shipment) {
                    echo json_error('Spedizione non trovata');
                    break;
                }
                
                // Aggiorna stato
                $stmt = $db->prepare("UPDATE shipments SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newStatus, $id]);
                
                // Se diventa Completed, crea cartelle
                if ($newStatus === 'Completed') {
                    // Conta box
                    $stmt = $db->prepare("SELECT COUNT(DISTINCT box_no) FROM shipment_items WHERE shipment_id = ?");
                    $stmt->execute([$id]);
                    $boxCount = $stmt->fetchColumn();
                    
                    createShipmentFolders($shipment['name'], $boxCount);
                }
                
                echo json_success([], 'Stato aggiornato con successo!');
                
            } catch (Exception $e) {
                echo json_error('Errore cambio stato: ' . $e->getMessage());
            }
            break;
    }
    
} else {
    echo json_error('Nessuna azione specificata');
}

/**
 * Invia email di annullamento spedizione
 */
function sendCancellationEmail($userId, $cancelledId, $cancelledName, $newId, $newName) {
    $emailTo = EASYSHIP_DEFAULT_EMAIL;
    
    $htmlContent = "
        <p><strong>La spedizione è stata annullata dall'utente e sostituita con una nuova:</strong></p>
        
        <table style='border-collapse: collapse; width: 100%; margin: 20px 0;'>
            <tr style='background: #fee2e2;'>
                <th style='border: 1px solid #ddd; padding: 10px;'>SPEDIZIONE ANNULLATA</th>
                <th style='border: 1px solid #ddd; padding: 10px;'>NUOVA SPEDIZIONE</th>
            </tr>
            <tr>
                <td style='border: 1px solid #ddd; padding: 10px; text-align: center;'>
                    <strong>#{$cancelledId}</strong><br>
                    {$cancelledName}<br>
                    <span style='color: #dc2626;'>❌ NON PROCESSARE</span>
                </td>
                <td style='border: 1px solid #ddd; padding: 10px; text-align: center;'>
                    <strong>#{$newId}</strong><br>
                    {$newName}<br>
                    <span style='color: #059669;'>✅ DA PROCESSARE QUANDO CONFERMATA</span>
                </td>
            </tr>
        </table>
        
        <p><strong>⚠️ IMPORTANTE:</strong> Ignorare completamente la spedizione annullata. L'utente sta preparando una versione corretta.</p>
    ";
    
    inviaEmailSMTP($emailTo, "🚫 SPEDIZIONE ANNULLATA: {$cancelledName}", $htmlContent);
}

/**
 * Invia email di conferma spedizione
 */
function sendConfirmationEmail($userId, $shipmentId, $shipmentName, $payload) {
    // Ottieni email utente o usa default
    $emailTo = EASYSHIP_DEFAULT_EMAIL;
    
    // Prepara contenuto HTML
    $htmlContent = "<h3>Nuova spedizione confermata: {$shipmentName}</h3>";
    
// Tabella 1: Prodotti
$htmlContent .= "<h4>📦 Prodotti nella spedizione:</h4>";
$htmlContent .= "<table>";
$htmlContent .= "<tr><th>Prodotto</th><th>Quantità</th><th>Prep Owner</th><th>Labeling Owner</th><th>Scadenza</th></tr>";

foreach ($payload['boxes'] as $box) {
    if (!empty($box['prodotti'])) {
        foreach ($box['prodotti'] as $prod) {
            if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                // Trova SKU dal nome prodotto
                $sku = getSKUFromProductName($userId, $prod['nome']);
                $displayName = $sku ?: $prod['nome']; // Fallback al nome se SKU non trovato
                
                // Usa data direttamente senza conversioni (come nel vecchio sistema)
                $scadenza = !empty($prod['scadenza']) ? $prod['scadenza'] : '';
                $htmlContent .= "<tr>";
                $htmlContent .= "<td>{$displayName}</td>";
                $htmlContent .= "<td>{$prod['quantita']}</td>";
                $htmlContent .= "<td>Seller</td>";
                $htmlContent .= "<td>Seller</td>";
                $htmlContent .= "<td>{$scadenza}</td>";
                $htmlContent .= "</tr>";
            }
        }
    }
}
$htmlContent .= "</table>";

// Tabella 2: Distribuzione per box
$htmlContent .= "<h4>📋 Distribuzione per box:</h4>";
$htmlContent .= "<table>";

// Header dinamico
$headerRow = "<tr><th>Prodotto</th>";
for ($i = 1; $i <= count($payload['boxes']); $i++) {
    $headerRow .= "<th>Pacco {$i}</th>";
}
$headerRow .= "</tr>";
$htmlContent .= $headerRow;

// Prima trasforma tutti i prodotti in SKU per ogni box
$boxesWithSku = [];
foreach ($payload['boxes'] as $boxIndex => $box) {
    $boxesWithSku[$boxIndex] = [];
    if (!empty($box['prodotti'])) {
        foreach ($box['prodotti'] as $prod) {
            if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                $sku = getSKUFromProductName($userId, $prod['nome']);
                $displayName = $sku ?: $prod['nome'];
                $boxesWithSku[$boxIndex][] = [
                    'sku' => $displayName,
                    'quantita' => intval($prod['quantita'])
                ];
            }
        }
    }
}

// Raccogli tutti gli SKU unici
$allSkus = [];
foreach ($boxesWithSku as $boxProducts) {
    foreach ($boxProducts as $prod) {
        $allSkus[$prod['sku']] = true;
    }
}

// Riga per ogni SKU
foreach ($allSkus as $sku => $dummy) {
    $htmlContent .= "<tr><td>{$sku}</td>";
    
    foreach ($boxesWithSku as $boxProducts) {
        $qty = 0;
        foreach ($boxProducts as $prod) {
            if ($prod['sku'] === $sku) {
                $qty += $prod['quantita'];
            }
        }
        $htmlContent .= "<td>" . ($qty > 0 ? $qty : '-') . "</td>";
    }
    $htmlContent .= "</tr>";
}
$htmlContent .= "</table>";
    
    // Tabella 3: Dimensioni colli
    $htmlContent .= "<h4>📏 Dimensioni colli:</h4>";
    $htmlContent .= "<table>";
    $htmlContent .= "<tr><th>Box</th><th>Peso (kg)</th><th>Larghezza (cm)</th><th>Lunghezza (cm)</th><th>Altezza (cm)</th></tr>";
    
    foreach ($payload['boxes'] as $box) {
        $dims = $box['dimensioni'] ?? [];
        $htmlContent .= "<tr>";
        $htmlContent .= "<td>Box {$box['numero']}</td>";
        $htmlContent .= "<td>" . ($dims['peso'] ?? 'N/A') . "</td>";
        $htmlContent .= "<td>" . ($dims['larghezza'] ?? 'N/A') . "</td>";
        $htmlContent .= "<td>" . ($dims['lunghezza'] ?? 'N/A') . "</td>";
        $htmlContent .= "<td>" . ($dims['altezza'] ?? 'N/A') . "</td>";
        $htmlContent .= "</tr>";
    }
    $htmlContent .= "</table>";
    
    // Genera Excel Amazon FBA
    $excelPath = null;
    $deleteAfterSend = null;
    try {
        $excelPath = generateAmazonExcel($userId, $shipmentId, $shipmentName, $payload);
        if ($excelPath && file_exists($excelPath)) {
            $deleteAfterSend = $excelPath;
        }
    } catch (Exception $e) {
        error_log("Errore generazione Excel per spedizione {$shipmentId}: " . $e->getMessage());
        // Continua con invio email normale
    }
    
    // Invia email con allegato Excel se disponibile
    inviaEmailSMTPWithAttachment($emailTo, "Nuova Spedizione: {$shipmentName}", $htmlContent, $excelPath);
    
    // Elimina file temporaneo dopo invio
    if ($deleteAfterSend && file_exists($deleteAfterSend)) {
        unlink($deleteAfterSend);
    }
}

/**
 * Invia email di aggiornamento spedizione
 */
function sendConfirmationEmailUpdate($userId, $shipmentId, $shipmentName, $payload) {
    // Prepara e invia email di conferma
    $emailTo = EASYSHIP_DEFAULT_EMAIL;
    
// Prepara contenuto HTML
$htmlContent = "<h3>📦 Prodotti nella spedizione:</h3>";

// Tabella 1: Prodotti - AGGREGATI PER SKU
$htmlContent .= "<table>";
$htmlContent .= "<tr><th>Prodotto</th><th>Quantità</th><th>Prep Owner</th><th>Labeling Owner</th><th>Scadenza</th></tr>";

// FASE 1: Usa logica MIN() del vecchio sistema mantenendo mapping
$productSkuMapping = []; // Salva mapping nome→SKU per tabella 2
$tempData = [];

foreach ($payload['boxes'] as $box) {
    if (!empty($box['prodotti'])) {
        foreach ($box['prodotti'] as $prod) {
            if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                $sku = getSKUFromProductName($userId, $prod['nome']);
                $displayName = $sku ?: $prod['nome'];
                
                // SALVA il mapping per riutilizzarlo nella tabella 2
                $productSkuMapping[$prod['nome']] = $displayName;
                
                $tempData[] = [
                    'sku' => $displayName,
                    'quantita' => intval($prod['quantita']),
                    'scadenza' => $prod['scadenza']
                ];
            }
        }
    }
}

// Aggrega usando logica MIN() del vecchio sistema
$skuGroups = [];
foreach ($tempData as $item) {
    $sku = $item['sku'];
    if (!isset($skuGroups[$sku])) {
        $skuGroups[$sku] = [
            'quantita_totale' => 0,
            'scadenze' => []
        ];
    }
    $skuGroups[$sku]['quantita_totale'] += $item['quantita'];
    if (!empty($item['scadenza']) && $item['scadenza'] !== 'N/A') {
        $skuGroups[$sku]['scadenze'][] = $item['scadenza'];
    }
}

// Applica MIN() esatta del vecchio sistema
$aggregatedProducts = [];
foreach ($skuGroups as $sku => $data) {
    $scadenza_finale = '';
    if (!empty($data['scadenze'])) {
        $scadenza_finale = normalizeToIsoDate(min($data['scadenze']));
    }
    $aggregatedProducts[$sku] = [
    'quantity' => $data['quantita_totale'],
    'expiry_date' => $scadenza_finale  // Allineato con generazione TXT
];
}

// FASE 2: Mostra prodotti aggregati
foreach ($aggregatedProducts as $sku => $data) {
    $scadenzaDisplay = !empty($data['expiry_date']) ? $data['expiry_date'] : ''; // Vuoto invece di "-"
    $htmlContent .= "<tr>";
    $htmlContent .= "<td>{$sku}</td>";
    $htmlContent .= "<td>{$data['quantity']}</td>";
    $htmlContent .= "<td>Seller</td>";
    $htmlContent .= "<td>Seller</td>";
    $htmlContent .= "<td>{$scadenzaDisplay}</td>";
    $htmlContent .= "</tr>";
}
$htmlContent .= "</table>";

// Tabella 2: Distribuzione per box - USA I MAPPING SALVATI
$htmlContent .= "<h4>📋 Distribuzione per box:</h4>";
$htmlContent .= "<table>";

// Header dinamico
$headerRow = "<tr><th>Prodotto</th>";
for ($i = 1; $i <= count($payload['boxes']); $i++) {
    $headerRow .= "<th>Pacco {$i}</th>";
}
$headerRow .= "</tr>";
$htmlContent .= $headerRow;

// Raccogli tutti gli SKU unici usando i mapping salvati
$allSkus = [];
foreach ($payload['boxes'] as $box) {
    if (!empty($box['prodotti'])) {
        foreach ($box['prodotti'] as $prod) {
            if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                // USA il mapping già calcolato invece di rifare la conversione
                $displayName = $productSkuMapping[$prod['nome']] ?? $prod['nome'];
                $allSkus[$displayName] = true;
            }
        }
    }
}

// Riga per ogni SKU
foreach ($allSkus as $sku => $dummy) {
    $htmlContent .= "<tr><td>{$sku}</td>";
    
    foreach ($payload['boxes'] as $box) {
        $qty = 0;
        if (!empty($box['prodotti'])) {
            foreach ($box['prodotti'] as $prod) {
                // Usa il mapping salvato per confrontare gli SKU
                $prodSku = $productSkuMapping[$prod['nome']] ?? $prod['nome'];
                if ($prodSku === $sku) {
                    $qty += intval($prod['quantita']);
                }
            }
        }
        $htmlContent .= "<td>" . ($qty > 0 ? $qty : '-') . "</td>";
    }
    $htmlContent .= "</tr>";
}
$htmlContent .= "</table>";
    
    // Tabella 3: Dimensioni colli
    $htmlContent .= "<h4>📏 Dimensioni colli:</h4>";
    $htmlContent .= "<table>";
    $htmlContent .= "<tr><th>Box</th><th>Peso (kg)</th><th>Larghezza (cm)</th><th>Lunghezza (cm)</th><th>Altezza (cm)</th></tr>";
    
    foreach ($payload['boxes'] as $box) {
        $dims = $box['dimensioni'] ?? [];
        $htmlContent .= "<tr>";
        $htmlContent .= "<td>Box {$box['numero']}</td>";
        $htmlContent .= "<td>" . ($dims['peso'] ?? 'N/A') . "</td>";
        $htmlContent .= "<td>" . ($dims['larghezza'] ?? 'N/A') . "</td>";
        $htmlContent .= "<td>" . ($dims['lunghezza'] ?? 'N/A') . "</td>";
        $htmlContent .= "<td>" . ($dims['altezza'] ?? 'N/A') . "</td>";
        $htmlContent .= "</tr>";
    }
    $htmlContent .= "</table>";
    
    // Genera Excel Amazon FBA
    $excelPath = null;
    $deleteAfterSend = null;
    try {
        $excelPath = generateAmazonExcel($userId, $shipmentId, $shipmentName, $payload);
        if ($excelPath && file_exists($excelPath)) {
            $deleteAfterSend = $excelPath;
        }
    } catch (Exception $e) {
        error_log("Errore generazione Excel per spedizione {$shipmentId}: " . $e->getMessage());
        // Continua con invio email normale
    }
    
    // Invia email con allegato Excel se disponibile
    inviaEmailSMTPWithAttachment($emailTo, "CREA: {$shipmentName}", $htmlContent, $excelPath);
    
    // Elimina file temporaneo dopo invio
    if ($deleteAfterSend && file_exists($deleteAfterSend)) {
        unlink($deleteAfterSend);
    }
}

/**
 * Genera file TXT Amazon FBA per spedizione (formato tabulazioni)
 */
function generateAmazonExcel($userId, $shipmentId, $shipmentName, $payload) {
    try {
        
        // FASE 1: Determina se esistono prodotti con scadenza
        $hasExpiryDates = false;
        foreach ($payload['boxes'] as $box) {
            if (!empty($box['prodotti'])) {
                foreach ($box['prodotti'] as $prod) {
                    if (!empty($prod['scadenza']) && $prod['scadenza'] !== 'N/A' && $prod['scadenza'] !== null) {
                        $hasExpiryDates = true;
                        break 2;
                    }
                }
            }
        }
        
        // FASE 2: Raccoglie e aggrega tutti i prodotti per SKU
        $aggregatedProducts = [];
        
        foreach ($payload['boxes'] as $box) {
            if (!empty($box['prodotti'])) {
                foreach ($box['prodotti'] as $prod) {
                    if (!empty($prod['nome']) && intval($prod['quantita']) > 0) {
                        
                        $sku = getSKUFromProductName($userId, $prod['nome']);
                        $merchantSku = $sku ?: $prod['nome'];
                        
                        // Normalizza data al formato ISO che Amazon accetta
$expirationDate = '';
if (!empty($prod['scadenza']) && 
    $prod['scadenza'] !== 'N/A' && 
    $prod['scadenza'] !== null && 
    $prod['scadenza'] !== '0000-00-00') {
    $expirationDate = normalizeToIsoDate($prod['scadenza']);
    // Log date invalide che non passano la normalizzazione
    if ($expirationDate === false) {
        error_log("EASYSHIP: Data scadenza non normalizzabile per export TXT, prodotto '{$prod['nome']}': '{$prod['scadenza']}'");
        $expirationDate = '';
    }
}
                        
                        $aggregationKey = $merchantSku;
                        
                        if (!isset($aggregatedProducts[$aggregationKey])) {
                            $aggregatedProducts[$aggregationKey] = [
                                'sku' => $merchantSku,
                                'quantity' => 0,
                                'expiry_date' => null  // Inizializza come null per confronto MIN
                            ];
                        }
                        
                        $aggregatedProducts[$aggregationKey]['quantity'] += intval($prod['quantita']);
                        
                        // Replica logica MIN del vecchio sistema
                        if (!empty($expirationDate) && $expirationDate !== false) {
    if (empty($aggregatedProducts[$aggregationKey]['expiry_date']) || 
        $expirationDate < $aggregatedProducts[$aggregationKey]['expiry_date']) {
        $aggregatedProducts[$aggregationKey]['expiry_date'] = $expirationDate;
    }
}
                    }
                }
            }
        }
        
        // FASE 3: Genera file TXT con tabulazioni
        $fileName = "Amazon_FBA_Shipment_{$shipmentId}_" . date('Ymd') . ".txt";
        $filePath = __DIR__ . "/temp/{$fileName}";
        
        // Assicurati che la directory temp esista
        $tempDir = __DIR__ . "/temp";
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $fp = fopen($filePath, 'wb'); // Binary mode
        if (!$fp) {
            throw new Exception('Impossibile creare file: ' . $filePath);
        }
        
        // Forza encoding ASCII e terminatori Unix
        fwrite($fp, "Controlla la scheda Example prima di compilare questo foglio.\r\n");
fwrite($fp, "\r\n");
fwrite($fp, "Default prep owner\tSeller\r\n");
fwrite($fp, "Default labeling owner\tSeller\r\n");
fwrite($fp, "\r\n");
fwrite($fp, "\r\n");
fwrite($fp, "\t\tFacoltativo\r\n");
        
        // Headers (separati da TAB)
        if ($hasExpiryDates) {
            fwrite($fp, "Merchant SKU\tQuantity\tPrep owner\tLabeling owner\tExpiration date (MM/DD/YYYY)\n");
        } else {
            fwrite($fp, "Merchant SKU\tQuantity\tPrep owner\tLabeling owner\n");
        }
        
        // Dati prodotti
        foreach ($aggregatedProducts as $data) {
            $line = $data['sku'] . "\t" . 
                   $data['quantity'] . "\t" . 
                   "Seller\t" . 
                   "Seller";
                   
            if ($hasExpiryDates) {
                $line .= "\t" . $data['expiry_date'];
            }
            
            fwrite($fp, $line . "\r\n");
        }
        
        fclose($fp);
        
        return $filePath;
        
    } catch (Exception $e) {
        error_log("Errore generateAmazonTXT: " . $e->getMessage());
        return false;
    }
}

/**
 * Ottieni SKU dal nome prodotto
 */
function getSKUFromProductName($userId, $productName) {
    try {
        $db = getDbConnection();
        
        // Prima prova: ricerca esatta
        $stmt = $db->prepare("
            SELECT sku 
            FROM products 
            WHERE user_id = ? AND nome = ? AND sku IS NOT NULL AND sku != ''
            LIMIT 1
        ");
        $stmt->execute([$userId, $productName]);
        $result = $stmt->fetchColumn();
        
        if ($result) {
            return $result;
        }
        
        // Seconda prova: ricerca normalizzata (rimuovi spazi extra)
        $normalizedName = trim(preg_replace('/\s+/', ' ', $productName));
        $stmt = $db->prepare("
            SELECT sku 
            FROM products 
            WHERE user_id = ? AND TRIM(REGEXP_REPLACE(nome, '[[:space:]]+', ' ')) = ? 
            AND sku IS NOT NULL AND sku != ''
            LIMIT 1
        ");
        $stmt->execute([$userId, $normalizedName]);
        $result = $stmt->fetchColumn();
        
        if ($result) {
            return $result;
        }
        
        // Terza prova: ricerca case-insensitive
        $stmt = $db->prepare("
            SELECT sku 
            FROM products 
            WHERE user_id = ? AND LOWER(nome) = LOWER(?) 
            AND sku IS NOT NULL AND sku != ''
            LIMIT 1
        ");
        $stmt->execute([$userId, $productName]);
        
        return $stmt->fetchColumn() ?: null;
        
    } catch (Exception $e) {
        error_log("Errore getSKUFromProductName: " . $e->getMessage());
        return null;
    }
}
?>