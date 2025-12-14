<?php
/**
 * Listing Module - Helper Functions
 * File: modules/listing/helpers.php
 * 
 * Funzioni pure riutilizzabili per gestione ordinamento prodotti
 */

// Includi configurazione database
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/admin/admin_helpers.php';

/**
 * Costruisce la clausola SQL di ordering standard per prodotti
 * 
 * @param int $userId ID utente per il filtro
 * @return array ['join' => string, 'order' => string, 'params' => array]
 */
function getProductOrderingClause($userId) {
    return [
        'join' => 'LEFT JOIN product_display_order pdo ON (pdo.user_id = :userIdJoin AND pdo.product_id = products.id)',
        'order' => 'ORDER BY CASE WHEN pdo.position IS NOT NULL THEN 0 ELSE 1 END, pdo.position ASC, products.nome ASC, products.id ASC',
        'params' => ['userIdJoin' => $userId]
    ];
}

/**
 * Normalizza array di product_id in posizioni sequenziali (1, 2, 3...)
 * 
 * @param array $productIds Array ordinato di product_id
 * @param int $start Posizione iniziale (default: 1)
 * @return array [product_id => position, ...]
 */
function normalizeProductPositions($productIds, $start = 1) {
    $positions = [];
    $currentPosition = $start;
    
    foreach ($productIds as $productId) {
        $positions[$productId] = $currentPosition;
        $currentPosition += 1;
    }
    
    return $positions;
}

/**
 * Wrapper per controlli di sicurezza admin
 * Riusa le funzioni esistenti senza re-inventare
 */
function requireListingAdmin() {
    requireAdmin(); // Da admin_helpers.php
}

/**
 * Verifica se utente è admin per modulo listing
 * 
 * @return bool
 */
function isListingAdmin() {
    return isAdminLogged(); // Da admin_helpers.php
}

/**
 * Ottieni connessione database per modulo listing
 * 
 * @return PDO
 */
function getListingDbConnection() {
    return getDbConnection(); // Da config.php
}

/**
 * Salva ordine prodotti per utente in transazione
 * 
 * @param int $userId ID utente
 * @param array $productIds Array ordinato di product_id
 * @return array ['success' => bool, 'message' => string, 'updated_count' => int]
 */
function saveProductOrder($userId, $productIds) {
    try {
        $pdo = getListingDbConnection();
        $pdo->beginTransaction();
        
        // Verifica che i prodotti appartengano all'utente
        if (!empty($productIds)) {
            $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM products 
                WHERE user_id = ? AND id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$userId], $productIds));
            $validCount = $stmt->fetchColumn();
            
            if ($validCount != count($productIds)) {
                throw new Exception('Alcuni prodotti non appartengono all\'utente specificato');
            }
        }
        
        // Elimina SOLO le mappature dei prodotti specificati (non tutte)
        if (!empty($productIds)) {
            $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM product_display_order WHERE user_id = ? AND product_id IN ($placeholders)");
            $stmt->execute(array_merge([$userId], $productIds));
        }
        
        // Normalizza posizioni sequenziali (1, 2, 3...) SOLO per i prodotti specificati
        $positions = normalizeProductPositions($productIds, 1);
        
        $updatedCount = 0;
        
        // Inserisci nuove posizioni SOLO per i prodotti specificati
        $stmt = $pdo->prepare("
            INSERT INTO product_display_order (user_id, product_id, position, updated_at)
            VALUES (?, ?, ?, NOW())
        ");
        
        foreach ($positions as $productId => $position) {
            $stmt->execute([$userId, $productId, $position]);
            $updatedCount++;
        }
        
        $pdo->commit();
        
        // Log operazione admin
        logAdminOperation(
            'PRODUCT_ORDER_SAVED',
            $userId,
            "Ordine prodotti salvato con posizioni sequenziali",
            [
                'admin_session' => $_SESSION['admin_logged'] ?? false,
                'products_count' => count($productIds),
                'user_id' => $userId,
                'positions_type' => 'sequential'
            ]
        );
        
        return [
            'success' => true,
            'message' => "Ordine salvato per {$updatedCount} prodotti (posizioni 1-{$updatedCount})",
            'updated_count' => $updatedCount
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        return [
            'success' => false,
            'message' => 'Errore salvataggio: ' . $e->getMessage(),
            'updated_count' => 0
        ];
    }
}

/**
 * Salva ordine prodotti con posizioni custom specificate
 * 
 * @param int $userId ID utente
 * @param array $productPositions Array associativo [product_id => position]
 * @return array ['success' => bool, 'message' => string, 'updated_count' => int]
 */
function saveProductOrderWithPositions($userId, $productPositions) {
    try {
        $pdo = getListingDbConnection();
        $pdo->beginTransaction();
        
        $productIds = array_keys($productPositions);
        
        // Verifica che i prodotti appartengano all'utente
        if (!empty($productIds)) {
            $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM products 
                WHERE user_id = ? AND id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$userId], $productIds));
            $validCount = $stmt->fetchColumn();
            
            if ($validCount != count($productIds)) {
                throw new Exception('Alcuni prodotti non appartengono all\'utente');
            }
        }
        
        // Elimina mappature esistenti per questi prodotti
        if (!empty($productIds)) {
            $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM product_display_order WHERE user_id = ? AND product_id IN ($placeholders)");
            $stmt->execute(array_merge([$userId], $productIds));
        }
        
        // Inserisci nuove posizioni CUSTOM
        $stmt = $pdo->prepare("
            INSERT INTO product_display_order (user_id, product_id, position, updated_at)
            VALUES (?, ?, ?, NOW())
        ");
        
        $updatedCount = 0;
        foreach ($productPositions as $productId => $position) {
            $stmt->execute([$userId, $productId, $position]);
            $updatedCount++;
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Ordine salvato per {$updatedCount} prodotti con posizioni custom",
            'updated_count' => $updatedCount
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        return [
            'success' => false,
            'message' => 'Errore: ' . $e->getMessage(),
            'updated_count' => 0
        ];
    }
}

/**
 * Ottieni prodotti con ordinamento per interfaccia admin
 * 
 * @param int $userId ID utente
 * @param array $filters Filtri opzionali ['search' => string, 'limit' => int, 'offset' => int]
 * @return array ['products' => array, 'total_count' => int]
 */
function getProductsWithOrder($userId, $filters = []) {
    try {
        $pdo = getListingDbConnection();
        
        // Parametri di default
        $search = $filters['search'] ?? '';
        $limit = $filters['limit'] ?? null; // null = nessun limite
        $offset = $filters['offset'] ?? 0;
        
        // Costruisci WHERE per ricerca
        $whereConditions = ['products.user_id = :userId'];
        $params = ['userId' => $userId];
        
        if (!empty($search)) {
            $whereConditions[] = '(products.nome LIKE :search OR products.sku LIKE :search OR products.asin LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        
        // Query conteggio totale
        $countSql = "
            SELECT COUNT(*) 
            FROM products 
            {$whereClause}
        ";
        
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $totalCount = $stmt->fetchColumn();
        
        // Query prodotti con ordinamento - SEMPLIFICATA
        $params['userIdJoin'] = $userId;
        
        $productsSql = "
            SELECT 
                products.id,
                products.nome,
                products.sku,
                products.asin,
                products.creato_il,
                pdo.position,
                CASE WHEN pdo.position IS NOT NULL THEN 1 ELSE 0 END as is_mapped
            FROM products
            LEFT JOIN product_display_order pdo ON products.id = pdo.product_id AND pdo.user_id = :userIdJoin
            {$whereClause}
            ORDER BY 
                CASE WHEN pdo.position IS NOT NULL THEN 0 ELSE 1 END,
                pdo.position ASC,
                products.nome ASC,
                products.id ASC
        ";
        
        // Aggiungi LIMIT solo se specificato
        if ($limit !== null) {
            $productsSql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $pdo->prepare($productsSql);
        
        // Aggiungi parametri LIMIT solo se necessari
        if ($limit !== null) {
            $params['limit'] = (int)$limit;
            $params['offset'] = (int)$offset;
        }
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'products' => $products,
            'total_count' => $totalCount
        ];
        
    } catch (Exception $e) {
        error_log("getProductsWithOrder Error: " . $e->getMessage());
        return [
            'products' => [],
            'total_count' => 0
        ];
    }
}

/**
 * Ripristina ordine alfabetico per utente
 * 
 * @param int $userId ID utente
 * @return array ['success' => bool, 'message' => string]
 */
function resetToAlphabeticalOrder($userId) {
    try {
        $pdo = getListingDbConnection();
        
        // Ottieni tutti i prodotti in ordine alfabetico
        $stmt = $pdo->prepare("
            SELECT id 
            FROM products 
            WHERE user_id = ? 
            ORDER BY nome ASC, id ASC
        ");
        $stmt->execute([$userId]);
        $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($productIds)) {
            return [
                'success' => true,
                'message' => 'Nessun prodotto da ordinare'
            ];
        }
        
        // Salva ordine alfabetico con posizioni sequenziali
        $result = saveProductOrder($userId, $productIds);
        
        if ($result['success']) {
            $result['message'] = 'Ordine alfabetico ripristinato per ' . count($productIds) . ' prodotti (posizioni 1-' . count($productIds) . ')';
        }
        
        return $result;
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Errore ripristino ordine: ' . $e->getMessage()
        ];
    }
}

/**
 * Ottieni lista utenti per dropdown admin
 * 
 * @return array
 */
function getListingUsers() {
    try {
        $pdo = getListingDbConnection();
        
        // Prima prova con role = 'seller'
        $stmt = $pdo->query("
            SELECT id, nome, email 
            FROM users 
            WHERE is_active = 1 AND role = 'seller'
            ORDER BY nome ASC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Se non trova utenti seller, prova con tutti gli utenti attivi (fallback)
        if (empty($users)) {
            $stmt = $pdo->query("
                SELECT id, nome, email 
                FROM users 
                WHERE is_active = 1
                ORDER BY nome ASC
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $users;
    } catch (Exception $e) {
        error_log("getListingUsers Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Ottieni prossima posizione disponibile per un utente
 * 
 * @param int $userId ID utente
 * @return int Prossima posizione sequenziale disponibile
 */
function getNextAvailablePosition($userId) {
    try {
        $pdo = getListingDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT MAX(position) as max_pos 
            FROM product_display_order 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        $maxPosition = $result['max_pos'] ?? 0;
        return $maxPosition + 1;
        
    } catch (Exception $e) {
        error_log("getNextAvailablePosition Error: " . $e->getMessage());
        return 1;
    }
}

/**
 * Compatta posizioni per un utente (rimuove gap)
 * 
 * @param int $userId ID utente
 * @return array ['success' => bool, 'message' => string, 'compacted_count' => int]
 */
function compactUserPositions($userId) {
    try {
        $pdo = getListingDbConnection();
        $pdo->beginTransaction();
        
        // Ottieni prodotti mappati ordinati per posizione
        $stmt = $pdo->prepare("
            SELECT product_id, position 
            FROM product_display_order 
            WHERE user_id = ? 
            ORDER BY position ASC
        ");
        $stmt->execute([$userId]);
        $mappedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($mappedProducts)) {
            $pdo->rollBack();
            return [
                'success' => true,
                'message' => 'Nessun prodotto mappato da compattare',
                'compacted_count' => 0
            ];
        }
        
        // Riassegna posizioni sequenziali 1, 2, 3...
        $stmt = $pdo->prepare("
            UPDATE product_display_order 
            SET position = ?, updated_at = NOW() 
            WHERE user_id = ? AND product_id = ?
        ");
        
        $newPosition = 1;
        foreach ($mappedProducts as $product) {
            $stmt->execute([$newPosition, $userId, $product['product_id']]);
            $newPosition++;
        }
        
        $pdo->commit();
        
        $compactedCount = count($mappedProducts);
        
        // Log operazione
        logAdminOperation(
            'POSITIONS_COMPACTED',
            $userId,
            "Posizioni compattate",
            [
                'admin_session' => $_SESSION['admin_logged'] ?? false,
                'compacted_count' => $compactedCount,
                'user_id' => $userId
            ]
        );
        
        return [
            'success' => true,
            'message' => "Posizioni compattate per {$compactedCount} prodotti (1-{$compactedCount})",
            'compacted_count' => $compactedCount
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        return [
            'success' => false,
            'message' => 'Errore compattazione: ' . $e->getMessage(),
            'compacted_count' => 0
        ];
    }
}
?>