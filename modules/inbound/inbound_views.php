<?php
/**
 * Inbound Views - Router Multi-Vista
 * File: modules/inbound/inbound_views.php
 * 
 * Viste disponibili:
 * - ?view=shipments: Lista spedizioni con filtri e paginazione
 * - ?view=details&id=X: Dettagli spedizione singola (header + items + boxes)
 * - ?view=stats: Statistiche aggregate e KPI
 * - ?view=logs: Log sincronizzazione con filtri
 * 
 * Features:
 * - CSV export per tutte le viste (&export=csv)
 * - Mobile responsive (card layout <768px)
 * - Server-side pagination
 * - Filtri persistenti in session
 * 
 * @version 2.0
 * @date 2025-10-17
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';
require_once __DIR__ . '/../margynomic/admin/admin_helpers.php';

// ============================================
// AUTHENTICATION: SOLO ADMIN
// ============================================
$isAdmin = isAdminLogged();

if (!$isAdmin) {
    header('Location: ../margynomic/admin/admin_login.php');
    exit;
}

$db = getDbConnection();

// ============================================
// USER SELECTION (con selettore)
// ============================================
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($_SESSION['inbound_selected_user'] ?? 2);
$_SESSION['inbound_selected_user'] = $selectedUserId;

$userId = $selectedUserId;

// Get user info
$stmt = $db->prepare("SELECT id, nome, email FROM users WHERE id = ?");
$stmt->execute([$selectedUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $user = ['id' => $selectedUserId, 'nome' => 'Unknown', 'email' => 'N/A'];
}

// Get available users (con token Amazon attivo)
try {
    $availableUsers = $db->query("
        SELECT DISTINCT u.id, u.nome, u.email 
        FROM users u
        INNER JOIN amazon_client_tokens t ON t.user_id = u.id
        WHERE u.is_active = 1 AND t.is_active = 1
        ORDER BY u.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $availableUsers = [$user]; // Fallback: solo utente corrente
}

// ============================================
// ROUTING
// ============================================
$view = $_GET['view'] ?? 'shipments';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// ============================================
// CSV EXPORT HELPER
// ============================================
function exportCsv($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// ============================================
// VIEW: SHIPMENTS LIST
// ============================================
if ($view === 'shipments') {
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 50;
    $offset = ($page - 1) * $perPage;
    
    // Filters
    $filterStatus = $_GET['status'] ?? '';
    $filterFc = $_GET['fc'] ?? '';
    $filterSearch = $_GET['search'] ?? '';
    
    // Build query
    $whereConditions = ["s.user_id = ?"];
    $params = [$userId];
    
    if ($filterStatus) {
        $whereConditions[] = "s.shipment_status = ?";
        $params[] = $filterStatus;
    }
    
    if ($filterFc) {
        $whereConditions[] = "s.destination_fc = ?";
        $params[] = $filterFc;
    }
    
    if ($filterSearch) {
        $whereConditions[] = "(s.amazon_shipment_id LIKE ? OR s.shipment_name LIKE ?)";
        $params[] = "%{$filterSearch}%";
        $params[] = "%{$filterSearch}%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Count total
    $stmt = $db->prepare("SELECT COUNT(*) FROM inbound_shipments s WHERE {$whereClause}");
    $stmt->execute($params);
    $totalShipments = $stmt->fetchColumn();
    $totalPages = ceil($totalShipments / $perPage);
    
    // Fetch shipments
    $query = "
        SELECT 
            s.*,
            ss.sync_status,
            ss.internal_updated_at,
            ss.status_note,
            (SELECT COUNT(*) FROM inbound_shipment_items WHERE shipment_id = s.id) as items_count,
            (SELECT COUNT(*) FROM inbound_shipment_boxes WHERE shipment_id = s.id) as boxes_count
        FROM inbound_shipments s
        LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
        WHERE {$whereClause}
        ORDER BY COALESCE(ss.internal_updated_at, s.shipment_created_date, s.last_sync_at) DESC, s.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CSV Export
    if ($export) {
        $headers = ['ID', 'Shipment ID', 'Nome', 'Status', 'FC', 'Creata', 'Sync Status', 'Items', 'Boxes'];
        $rows = [];
        foreach ($shipments as $s) {
            $rows[] = [
                $s['id'],
                $s['amazon_shipment_id'],
                $s['shipment_name'],
                $s['shipment_status'],
                $s['destination_fc'],
                $s['shipment_created_date'],
                $s['sync_status'] ?? 'complete',
                $s['items_count'],
                $s['boxes_count']
            ];
        }
        exportCsv('shipments_' . date('Ymd_His') . '.csv', $headers, $rows);
    }
    
    // Get filter options
    $statusOptions = $db->query("SELECT DISTINCT shipment_status FROM inbound_shipments WHERE user_id = {$userId} AND shipment_status IS NOT NULL ORDER BY shipment_status")->fetchAll(PDO::FETCH_COLUMN);
    $fcOptions = $db->query("SELECT DISTINCT destination_fc FROM inbound_shipments WHERE user_id = {$userId} AND destination_fc IS NOT NULL ORDER BY destination_fc")->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================
// VIEW: SHIPMENT DETAILS
// ============================================
if ($view === 'details') {
    $shipmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if (!$shipmentId) {
        die("ID spedizione mancante");
    }
    
    // Fetch shipment
    $stmt = $db->prepare("
        SELECT s.*, ss.sync_status, ss.status_note, ss.retry_count, ss.last_attempt_at, ss.internal_updated_at
        FROM inbound_shipments s
        LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$shipmentId, $userId]);
    $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shipment) {
        die("Spedizione non trovata");
    }
    
    // Fetch items
    $stmt = $db->prepare("
        SELECT * FROM inbound_shipment_items 
        WHERE shipment_id = ? 
        ORDER BY seller_sku
    ");
    $stmt->execute([$shipmentId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch boxes
    $stmt = $db->prepare("
        SELECT * FROM inbound_shipment_boxes 
        WHERE shipment_id = ? 
        ORDER BY box_no
    ");
    $stmt->execute([$shipmentId]);
    $boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// VIEW: STATS
// ============================================
if ($view === 'stats') {
    // Overall stats
    $overallQuery = "
        SELECT 
            COUNT(DISTINCT s.id) as total_shipments,
            COUNT(DISTINCT CASE WHEN IFNULL(ss.sync_status, 'complete') = 'complete' THEN s.id END) as complete_shipments,
            COUNT(DISTINCT CASE WHEN ss.sync_status IN ('partial_loop','partial_no_progress','missing') THEN s.id END) as partial_shipments,
            COUNT(DISTINCT i.id) as total_items,
            SUM(i.quantity_shipped) as total_units,
            COUNT(DISTINCT b.id) as total_boxes,
            COUNT(DISTINCT s.destination_fc) as unique_fcs,
            MIN(s.shipment_created_date) as first_shipment,
            MAX(s.shipment_created_date) as last_shipment,
            MAX(s.last_sync_at) as last_sync
        FROM inbound_shipments s
        LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
        LEFT JOIN inbound_shipment_items i ON i.shipment_id = s.id
        LEFT JOIN inbound_shipment_boxes b ON b.shipment_id = s.id
        WHERE s.user_id = ?
    ";
    $stmt = $db->prepare($overallQuery);
    $stmt->execute([$userId]);
    $overall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // By status
    $statusStatsQuery = "
        SELECT 
            s.shipment_status,
            COUNT(*) as count,
            SUM((SELECT COUNT(*) FROM inbound_shipment_items WHERE shipment_id = s.id)) as items_count
        FROM inbound_shipments s
        WHERE s.user_id = ?
        GROUP BY s.shipment_status
        ORDER BY count DESC
        LIMIT 10
    ";
    $stmt = $db->prepare($statusStatsQuery);
    $stmt->execute([$userId]);
    $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top FCs
    $fcStatsQuery = "
        SELECT 
            destination_fc,
            COUNT(*) as count,
            SUM((SELECT COUNT(*) FROM inbound_shipment_items WHERE shipment_id = inbound_shipments.id)) as items_count
        FROM inbound_shipments
        WHERE user_id = ? AND destination_fc IS NOT NULL
        GROUP BY destination_fc
        ORDER BY count DESC
        LIMIT 10
    ";
    $stmt = $db->prepare($fcStatsQuery);
    $stmt->execute([$userId]);
    $fcStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// VIEW: LOGS
// ============================================
if ($view === 'logs') {
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 100;
    $offset = ($page - 1) * $perPage;
    
    // Filters
    $filterLevel = $_GET['level'] ?? '';
    $filterPhase = $_GET['phase'] ?? '';
    
    // Build query
    $whereConditions = ["user_id = ?"];
    $params = [$userId];
    
    if ($filterLevel) {
        $whereConditions[] = "log_level = ?";
        $params[] = $filterLevel;
    }
    
    if ($filterPhase) {
        $whereConditions[] = "operation_type LIKE ?";
        $params[] = "%{$filterPhase}%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Count total
    $stmt = $db->prepare("SELECT COUNT(*) FROM sync_debug_logs WHERE {$whereClause}");
    $stmt->execute($params);
    $totalLogs = $stmt->fetchColumn();
    $totalPages = ceil($totalLogs / $perPage);
    
    // Fetch logs
    $query = "
        SELECT * FROM sync_debug_logs
        WHERE {$whereClause}
        ORDER BY created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CSV Export
    if ($export) {
        $headers = ['ID', 'Timestamp', 'Level', 'Operation', 'Message'];
        $rows = [];
        foreach ($logs as $l) {
            $rows[] = [
                $l['id'],
                $l['created_at'],
                $l['log_level'],
                $l['operation_type'],
                $l['message']
            ];
        }
        exportCsv('logs_' . date('Ymd_His') . '.csv', $headers, $rows);
    }
}

// ============================================
// HTML OUTPUT START
// ============================================
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbound - <?= ucfirst($view) ?></title>
    <link rel="stylesheet" href="inbound.css">
</head>
<body>

<?php echo getAdminNavigation('inbound'); ?>

    <div class="container">
        <!-- Header -->
        <div class="inbound-header">
            <div class="header-content">
                <div class="header-title">
                    📦 Inbound - <?= ucfirst($view) ?>
                </div>
            </div>
        </div>

        <!-- User Selector -->
        <div class="card">
            <div class="card-header">👤 Seleziona Utente</div>
            <div class="card-body">
                <form method="GET" style="display: flex; gap: 1rem; align-items: end;">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                    <div class="form-group" style="flex: 1; margin: 0;">
                        <label for="user_id">Utente da visualizzare:</label>
                        <select name="user_id" id="user_id" onchange="this.form.submit()">
                            <?php foreach ($availableUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['nome']) ?> (ID: <?= $u['id'] ?>) - <?= htmlspecialchars($u['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($view === 'shipments'): ?>
        <!-- ============================================ -->
        <!-- VIEW: SHIPMENTS LIST -->
        <!-- ============================================ -->
        
        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" style="display: contents;">
                <input type="hidden" name="view" value="shipments">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                
                <div class="filter-group">
                    <label>🔍 Cerca:</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Shipment ID o nome...">
                </div>
                
                <div class="filter-group">
                    <label>📊 Status:</label>
                    <select name="status">
                        <option value="">Tutti</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?= $status ?>" <?= $filterStatus === $status ? 'selected' : '' ?>>
                                <?= $status ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>🏭 FC:</label>
                    <select name="fc">
                        <option value="">Tutti</option>
                        <?php foreach ($fcOptions as $fc): ?>
                            <option value="<?= $fc ?>" <?= $filterFc === $fc ? 'selected' : '' ?>>
                                <?= $fc ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Filtra</button>
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <a href="?view=shipments&user_id=<?= $userId ?>&export=csv<?= $filterStatus ? '&status='.$filterStatus : '' ?><?= $filterFc ? '&fc='.$filterFc : '' ?><?= $filterSearch ? '&search='.$filterSearch : '' ?>" class="btn btn-secondary">
                        📥 CSV
                    </a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                📦 Spedizioni (<?= number_format($totalShipments) ?> totali)
            </div>
            <div class="card-body">
                <?php if (empty($shipments)): ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">ℹ️</span>
                        <div>Nessuna spedizione trovata con i filtri selezionati.</div>
                    </div>
                <?php else: ?>
                
                <!-- Desktop Table -->
                <div class="table-container desktop-only">
                    <table>
                        <thead>
                            <tr>
                                <th>Shipment ID</th>
                                <th>Nome</th>
                                <th>Status</th>
                                <th>FC</th>
                                <th>Ultimo Agg.</th>
                                <th>Sync</th>
                                <th>Items</th>
                                <th>Boxes</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipments as $s): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($s['amazon_shipment_id']) ?></code></td>
                                <td><?= htmlspecialchars($s['shipment_name'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower(str_replace('_', '-', $s['shipment_status'])) ?>">
                                        <?= $s['shipment_status'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($s['destination_fc'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if ($s['internal_updated_at']): ?>
                                        <span title="Ultimo cambio interno">
                                            <?= date('d/m/Y H:i', strtotime($s['internal_updated_at'])) ?>
                                        </span>
                                    <?php elseif ($s['shipment_created_date']): ?>
                                        <span class="text-muted" title="Data creazione (mai aggiornato)">
                                            <?= date('d/m/Y', strtotime($s['shipment_created_date'])) ?>
                                        </span>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= ($s['sync_status'] ?? 'complete') === 'complete' ? 'complete' : 'partial' ?>">
                                        <?= $s['sync_status'] ?? 'complete' ?>
                                    </span>
                                </td>
                                <td><?= $s['items_count'] ?></td>
                                <td>
                                    <?php if ($s['boxes_count'] > 0): ?>
                                        <?= $s['boxes_count'] ?>
                                    <?php elseif (strpos($s['status_note'] ?? '', 'boxes_v0') !== false): ?>
                                        <span class="badge badge-info" title="Boxes non disponibili su SP-API v0">N/A (v0)</span>
                                    <?php else: ?>
                                        0
                                    <?php endif; ?>
                                </td>
                                <td class="table-actions">
                                    <a href="?view=details&id=<?= $s['id'] ?>&user_id=<?= $userId ?>" class="btn btn-sm btn-primary">
                                        Dettagli
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Cards -->
                <div class="mobile-cards">
                    <?php foreach ($shipments as $s): ?>
                    <div class="mobile-card">
                        <div class="mobile-card-header">
                            <?= htmlspecialchars($s['amazon_shipment_id']) ?>
                        </div>
                        <div class="mobile-card-row">
                            <span class="mobile-card-label">Nome:</span>
                            <span class="mobile-card-value"><?= htmlspecialchars($s['shipment_name'] ?? 'N/A') ?></span>
                        </div>
                        <div class="mobile-card-row">
                            <span class="mobile-card-label">Status:</span>
                            <span class="mobile-card-value">
                                <span class="badge badge-<?= strtolower(str_replace('_', '-', $s['shipment_status'])) ?>">
                                    <?= $s['shipment_status'] ?>
                                </span>
                            </span>
                        </div>
                        <div class="mobile-card-row">
                            <span class="mobile-card-label">FC:</span>
                            <span class="mobile-card-value"><?= htmlspecialchars($s['destination_fc'] ?? 'N/A') ?></span>
                        </div>
                        <div class="mobile-card-row">
                            <span class="mobile-card-label">Ultimo Agg.:</span>
                            <span class="mobile-card-value">
                                <?php if ($s['internal_updated_at']): ?>
                                    <?= date('d/m/Y H:i', strtotime($s['internal_updated_at'])) ?>
                                <?php elseif ($s['shipment_created_date']): ?>
                                    <span class="text-muted"><?= date('d/m/Y', strtotime($s['shipment_created_date'])) ?></span>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="mobile-card-row">
                            <span class="mobile-card-label">Items/Boxes:</span>
                            <span class="mobile-card-value">
                                <?= $s['items_count'] ?> / 
                                <?php if ($s['boxes_count'] > 0): ?>
                                    <?= $s['boxes_count'] ?>
                                <?php elseif (strpos($s['status_note'] ?? '', 'boxes_v0') !== false): ?>
                                    <span class="badge badge-info" title="Boxes non disponibili su SP-API v0">N/A (v0)</span>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </span>
                        </div>
                        <div style="margin-top: 0.5rem;">
                            <a href="?view=details&id=<?= $s['id'] ?>&user_id=<?= $userId ?>" class="btn btn-sm btn-primary" style="width: 100%;">
                                Vedi Dettagli
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?view=shipments&user_id=<?= $userId ?>&page=<?= $page - 1 ?><?= $filterStatus ? '&status='.$filterStatus : '' ?><?= $filterFc ? '&fc='.$filterFc : '' ?><?= $filterSearch ? '&search='.urlencode($filterSearch) : '' ?>">
                            ← Precedente
                        </a>
                    <?php endif; ?>
                    
                    <span>Pagina <?= $page ?> di <?= $totalPages ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?view=shipments&user_id=<?= $userId ?>&page=<?= $page + 1 ?><?= $filterStatus ? '&status='.$filterStatus : '' ?><?= $filterFc ? '&fc='.$filterFc : '' ?><?= $filterSearch ? '&search='.urlencode($filterSearch) : '' ?>">
                            Successiva →
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($view === 'details'): ?>
        <!-- ============================================ -->
        <!-- VIEW: SHIPMENT DETAILS -->
        <!-- ============================================ -->
        
        <div class="card">
            <div class="card-header">
                📦 Dettagli Spedizione: <?= htmlspecialchars($shipment['amazon_shipment_id']) ?>
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div><strong>Nome:</strong><br><?= htmlspecialchars($shipment['shipment_name'] ?? 'N/A') ?></div>
                    <div><strong>Status:</strong><br>
                        <span class="badge badge-<?= strtolower(str_replace('_', '-', $shipment['shipment_status'])) ?>">
                            <?= $shipment['shipment_status'] ?>
                        </span>
                    </div>
                    <div><strong>FC:</strong><br><?= htmlspecialchars($shipment['destination_fc'] ?? 'N/A') ?></div>
                    <div><strong>Creata:</strong><br><?= $shipment['shipment_created_date'] ? date('d/m/Y H:i', strtotime($shipment['shipment_created_date'])) : 'N/A' ?></div>
                    <div><strong>Ultimo Aggiornamento:</strong><br>
                        <?php if ($shipment['internal_updated_at']): ?>
                            <?= date('d/m/Y H:i', strtotime($shipment['internal_updated_at'])) ?>
                        <?php elseif ($shipment['last_sync_at']): ?>
                            <span class="text-muted" title="Ultimo sync (prima del fingerprint)"><?= date('d/m/Y H:i', strtotime($shipment['last_sync_at'])) ?></span>
                        <?php else: ?>
                            <span class="text-muted">Mai</span>
                        <?php endif; ?>
                    </div>
                    <div><strong>Sync Status:</strong><br>
                        <span class="badge badge-<?= ($shipment['sync_status'] ?? 'complete') === 'complete' ? 'complete' : 'partial' ?>">
                            <?= $shipment['sync_status'] ?? 'complete' ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($shipment['sync_status'] && $shipment['sync_status'] !== 'complete'): ?>
                <div class="alert alert-warning" style="margin-top: 1rem;">
                    <span class="alert-icon">⚠️</span>
                    <div>
                        <strong>Sincronizzazione Parziale</strong><br>
                        Nota: <?= htmlspecialchars($shipment['status_note'] ?? 'N/A') ?><br>
                        Tentativi: <?= $shipment['retry_count'] ?? 0 ?><br>
                        Ultimo tentativo: <?= $shipment['last_attempt_at'] ? date('d/m/Y H:i', strtotime($shipment['last_attempt_at'])) : 'N/A' ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items Tab -->
        <div class="card">
            <div class="card-header">
                📝 Items (<?= count($items) ?>)
            </div>
            <div class="card-body">
                <?php if (empty($items)): ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">ℹ️</span>
                        <div>Nessun item trovato per questa spedizione.</div>
                    </div>
                <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>FNSKU</th>
                                <th>Nome</th>
                                <th>Qta Shipped</th>
                                <th>Qta Received</th>
                                <th>Prep</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($item['seller_sku']) ?></code></td>
                                <td><code><?= htmlspecialchars($item['fnsku'] ?? 'N/A') ?></code></td>
                                <td><?= htmlspecialchars($item['product_name'] ?? 'N/A') ?></td>
                                <td><?= $item['quantity_shipped'] ?></td>
                                <td><?= $item['quantity_received'] ?? '-' ?></td>
                                <td><?= htmlspecialchars($item['prep_owner'] ?? 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Boxes Tab -->
        <div class="card">
            <div class="card-header">
                📦 Boxes (<?= count($boxes) ?>)
            </div>
            <div class="card-body">
                <?php if (empty($boxes)): ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">ℹ️</span>
                        <div>Nessun box trovato per questa spedizione.</div>
                    </div>
                <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Box No</th>
                                <th>Box ID</th>
                                <th>Tracking</th>
                                <th>Peso (kg)</th>
                                <th>Dimensioni (cm)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($boxes as $box): ?>
                            <tr>
                                <td><?= $box['box_no'] ?? 'N/A' ?></td>
                                <td><code><?= htmlspecialchars(substr($box['box_id'], 0, 20)) ?></code></td>
                                <td><?= htmlspecialchars($box['tracking_id'] ?? 'N/A') ?></td>
                                <td><?= $box['weight_kg'] ?? 'N/A' ?></td>
                                <td>
                                    <?php if ($box['length_cm']): ?>
                                        <?= $box['length_cm'] ?> × <?= $box['width_cm'] ?> × <?= $box['height_cm'] ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($box['box_status']): ?>
                                        <span class="badge badge-<?= strtolower(str_replace('_', '-', $box['box_status'])) ?>">
                                            <?= $box['box_status'] ?>
                                        </span>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top: 1rem;">
            <a href="?view=shipments&user_id=<?= $userId ?>" class="btn btn-outline">
                ← Torna alla Lista
            </a>
        </div>

        <?php elseif ($view === 'stats'): ?>
        <!-- ============================================ -->
        <!-- VIEW: STATS -->
        <!-- ============================================ -->
        
        <!-- Overall KPI -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($overall['total_shipments']) ?></div>
                <div class="stat-label">Spedizioni Totali</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success);"><?= number_format($overall['complete_shipments']) ?></div>
                <div class="stat-label">Complete</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning);"><?= number_format($overall['partial_shipments']) ?></div>
                <div class="stat-label">Parziali</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($overall['total_items']) ?></div>
                <div class="stat-label">Items Totali</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($overall['total_units']) ?></div>
                <div class="stat-label">Unità Totali</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($overall['unique_fcs']) ?></div>
                <div class="stat-label">Warehouse Unici</div>
            </div>
        </div>

        <!-- By Status -->
        <div class="card">
            <div class="card-header">📊 Distribuzione per Status</div>
            <div class="card-body">
                <?php 
                $maxCount = max(array_column($statusStats, 'count'));
                foreach ($statusStats as $stat): 
                    $percentage = ($stat['count'] / $maxCount) * 100;
                ?>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <strong><?= htmlspecialchars($stat['shipment_status']) ?></strong>
                        <span><?= $stat['count'] ?> spedizioni (<?= $stat['items_count'] ?> items)</span>
                    </div>
                    <div style="background: var(--bg-hover); border-radius: var(--radius-md); height: 24px; overflow: hidden;">
                        <div style="background: var(--primary); height: 100%; width: <?= $percentage ?>%; transition: width 0.3s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Top FCs -->
        <div class="card">
            <div class="card-header">🏭 Top 10 Fulfillment Centers</div>
            <div class="card-body">
                <?php 
                $maxCount = max(array_column($fcStats, 'count'));
                foreach ($fcStats as $stat): 
                    $percentage = ($stat['count'] / $maxCount) * 100;
                ?>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <strong><?= htmlspecialchars($stat['destination_fc']) ?></strong>
                        <span><?= $stat['count'] ?> spedizioni (<?= $stat['items_count'] ?> items)</span>
                    </div>
                    <div style="background: var(--bg-hover); border-radius: var(--radius-md); height: 24px; overflow: hidden;">
                        <div style="background: var(--success); height: 100%; width: <?= $percentage ?>%; transition: width 0.3s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php elseif ($view === 'logs'): ?>
        <!-- ============================================ -->
        <!-- VIEW: LOGS -->
        <!-- ============================================ -->
        
        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" style="display: contents;">
                <input type="hidden" name="view" value="logs">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                
                <div class="filter-group">
                    <label>📊 Level:</label>
                    <select name="level">
                        <option value="">Tutti</option>
                        <option value="ERROR" <?= $filterLevel === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                        <option value="WARNING" <?= $filterLevel === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                        <option value="INFO" <?= $filterLevel === 'INFO' ? 'selected' : '' ?>>INFO</option>
                        <option value="DEBUG" <?= $filterLevel === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>🔍 Operation:</label>
                    <input type="text" name="phase" value="<?= htmlspecialchars($filterPhase) ?>" placeholder="es: INBOUND">
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Filtra</button>
                </div>
                
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <a href="?view=logs&user_id=<?= $userId ?>&export=csv<?= $filterLevel ? '&level='.$filterLevel : '' ?><?= $filterPhase ? '&phase='.$filterPhase : '' ?>" class="btn btn-secondary">
                        📥 CSV
                    </a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                📋 Log Sincronizzazione (<?= number_format($totalLogs) ?> totali)
            </div>
            <div class="card-body">
                <?php if (empty($logs)): ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">ℹ️</span>
                        <div>Nessun log trovato.</div>
                    </div>
                <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Level</th>
                                <th>Operation</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($log['log_level']) === 'error' ? 'error' : (strtolower($log['log_level']) === 'warning' ? 'partial' : 'complete') ?>">
                                        <?= $log['log_level'] ?>
                                    </span>
                                </td>
                                <td><code><?= htmlspecialchars($log['operation_type']) ?></code></td>
                                <td><?= htmlspecialchars(substr($log['message'], 0, 100)) ?><?= strlen($log['message']) > 100 ? '...' : '' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?view=logs&user_id=<?= $userId ?>&page=<?= $page - 1 ?><?= $filterLevel ? '&level='.$filterLevel : '' ?><?= $filterPhase ? '&phase='.$filterPhase : '' ?>">
                            ← Precedente
                        </a>
                    <?php endif; ?>
                    
                    <span>Pagina <?= $page ?> di <?= $totalPages ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?view=logs&user_id=<?= $userId ?>&page=<?= $page + 1 ?><?= $filterLevel ? '&level='.$filterLevel : '' ?><?= $filterPhase ? '&phase='.$filterPhase : '' ?>">
                            Successiva →
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>
</body>
</html>

