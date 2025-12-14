<?php
/**
 * Removal Orders Dashboard
 * File: modules/inbound/removal_orders.php
 * 
 * Interfaccia web per download e visualizzazione Removal Orders
 * 
 * Features:
 * - Form download con date range
 * - Polling automatico status report
 * - Tabella risultati con filtri
 * - Stats summary
 * 
 * @version 1.0
 * @date 2025-10-25
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

// ============================================
// USER SELECTION (come inbound.php)
// ============================================
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($_SESSION['removal_selected_user'] ?? 2);
$_SESSION['removal_selected_user'] = $selectedUserId;

$userId = $selectedUserId;

$db = getDbConnection();

// Get user info
$stmt = $db->prepare("SELECT id, nome, email FROM users WHERE id = ?");
$stmt->execute([$selectedUserId]);
$selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$selectedUser) {
    $selectedUser = ['id' => $selectedUserId, 'nome' => 'Unknown', 'email' => 'N/A'];
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
    die("Database error (users query): " . $e->getMessage());
}

// Token status
try {
    $stmt = $db->prepare("SELECT is_active FROM amazon_client_tokens WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $tokenActive = $stmt->fetchColumn();
} catch (PDOException $e) {
    $tokenActive = false;
}

// ============================================
// FETCH EXISTING REMOVAL ORDERS
// ============================================

// Filtri
$filterStartDate = $_GET['filter_start'] ?? '';
$filterEndDate = $_GET['filter_end'] ?? '';
$filterSku = $_GET['filter_sku'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';

// Build query
$where = ["ro.user_id = ?"];
$params = [$userId];

if (!empty($filterStartDate)) {
    $where[] = "ro.request_date >= ?";
    $params[] = $filterStartDate . ' 00:00:00';
}

if (!empty($filterEndDate)) {
    $where[] = "ro.request_date <= ?";
    $params[] = $filterEndDate . ' 23:59:59';
}

if (!empty($filterSku)) {
    $where[] = "ro.sku LIKE ?";
    $params[] = '%' . $filterSku . '%';
}

if (!empty($filterStatus)) {
    $where[] = "ro.order_status = ?";
    $params[] = $filterStatus;
}

$whereClause = implode(' AND ', $where);

// Fetch orders
$sql = "
    SELECT 
        ro.*,
        p.nome as product_name,
        p.asin
    FROM removal_orders ro
    LEFT JOIN products p ON p.id = ro.product_id
    WHERE {$whereClause}
    ORDER BY ro.request_date DESC
    LIMIT 20000
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$removalOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// STATS
// ============================================

$statsQuery = "
    SELECT 
        COUNT(DISTINCT order_id) as total_orders,
        SUM(requested_quantity) as total_requested,
        SUM(shipped_quantity) as total_shipped,
        SUM(disposed_quantity) as total_disposed,
        SUM(cancelled_quantity) as total_cancelled
    FROM removal_orders
    WHERE user_id = ?
";

$stmt = $db->prepare($statsQuery);
$stmt->execute([$userId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Removal Orders - Dashboard</title>
    <link rel="stylesheet" href="inbound.css">
    <link rel="stylesheet" href="removal_orders.css">
</head>
<body>

<?php echo getAdminNavigation('removal_orders'); ?>

    <div class="container">
        
        <!-- Header -->
        <div class="inbound-header">
            <div class="header-content">
                <div class="header-title">
                    📦 Removal Orders Dashboard
                </div>
            </div>
        </div>

        <!-- User Selector -->
        <div class="card">
            <div class="card-header">👤 Seleziona Utente</div>
            <div class="card-body">
                <form method="GET" style="display: flex; gap: 1rem; align-items: end;">
                    <div class="form-group" style="flex: 1; margin: 0;">
                        <label for="user_id">Utente da gestire:</label>
                        <select name="user_id" id="user_id" onchange="this.form.submit()">
                            <?php foreach ($availableUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['nome']) ?> (ID: <?= $u['id'] ?>) - <?= htmlspecialchars($u['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
                
                <div style="margin-top: 1rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                    <span><strong>Utente corrente:</strong> <?= htmlspecialchars($selectedUser['nome']) ?></span>
                    <span><strong>Email:</strong> <?= htmlspecialchars($selectedUser['email']) ?></span>
                    <span>
                        <strong>Token Amazon:</strong> 
                        <span class="badge <?= $tokenActive ? 'badge-complete' : 'badge-error' ?>">
                            <?= $tokenActive ? '✓ Attivo' : '✗ Inattivo' ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <?php if (!$tokenActive): ?>
        <div class="alert alert-warning">
            <span class="alert-icon">⚠️</span>
            <div>
                <strong>Token Amazon non attivo!</strong><br>
                L'utente deve completare l'autorizzazione Amazon prima di scaricare report.
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats Summary -->
        <?php if ($stats['total_orders'] > 0): ?>
        <div class="removal-stats">
            <div class="removal-stat-card">
                <div class="removal-stat-value"><?= number_format($stats['total_orders']) ?></div>
                <div class="removal-stat-label">📋 Ordini Totali</div>
            </div>
            <div class="removal-stat-card">
                <div class="removal-stat-value"><?= number_format($stats['total_requested']) ?></div>
                <div class="removal-stat-label">📦 Richiesti</div>
            </div>
            <div class="removal-stat-card">
                <div class="removal-stat-value"><?= number_format($stats['total_shipped']) ?></div>
                <div class="removal-stat-label">✈️ Spediti</div>
            </div>
            <div class="removal-stat-card">
                <div class="removal-stat-value"><?= number_format($stats['total_disposed']) ?></div>
                <div class="removal-stat-label">🗑️ Distrutti</div>
            </div>
            <div class="removal-stat-card">
                <div class="removal-stat-value"><?= number_format($stats['total_cancelled']) ?></div>
                <div class="removal-stat-label">❌ Cancellati</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Download Form -->
        <div class="removal-form">
            <div class="removal-form-header">
                📥 Scarica Report Removal Orders da Amazon
            </div>
            
            <form id="download-form">
                <div class="removal-form-grid">
                    <div class="form-group">
                        <label for="start_date">Data Inizio:</label>
                        <input 
                            type="date" 
                            id="start_date" 
                            name="start_date" 
                            value="<?= date('Y-m-d', strtotime('-3 years')) ?>"
                            required
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">Data Fine:</label>
                        <input 
                            type="date" 
                            id="end_date" 
                            name="end_date" 
                            value="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" id="download-btn" class="btn btn-primary">
                        📥 Scarica Report Removal Orders
                    </button>
                </div>
            </form>
            
            <!-- Loading Spinner -->
            <div id="loading-spinner" class="loading-spinner">
                <div class="spinner"></div>
                <div class="spinner-text" id="spinner-text">⏳ Richiedendo report ad Amazon...</div>
            </div>
            
            <!-- Progress Messages -->
            <div id="progress-messages"></div>
        </div>

        <!-- Filters -->
        <?php if (count($removalOrders) > 0): ?>
        <div class="card">
            <div class="card-header">🔍 Filtra Risultati</div>
            <div class="card-body">
                <form method="GET" class="filters-bar">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    
                    <div class="filter-group">
                        <label for="filter_start">Data Inizio:</label>
                        <input type="date" name="filter_start" id="filter_start" value="<?= htmlspecialchars($filterStartDate) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_end">Data Fine:</label>
                        <input type="date" name="filter_end" id="filter_end" value="<?= htmlspecialchars($filterEndDate) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_sku">SKU:</label>
                        <input type="text" name="filter_sku" id="filter_sku" value="<?= htmlspecialchars($filterSku) ?>" placeholder="Cerca SKU...">
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_status">Status:</label>
                        <select name="filter_status" id="filter_status">
                            <option value="">Tutti</option>
                            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn btn-secondary">Applica Filtri</button>
                        <a href="removal_orders.php?user_id=<?= $userId ?>" class="btn btn-outline">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Results Table -->
        <?php if (count($removalOrders) > 0): ?>
        <div class="removal-table">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Data Richiesta</th>
                        <th>SKU</th>
                        <th>Prodotto</th>
                        <th>FNSKU</th>
                        <th>Disposition</th>
                        <th>Richieste</th>
                        <th>Spedite</th>
                        <th>Distrutte</th>
                        <th>Cancellate</th>
                        <th>Status</th>
                        <th>Tipo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($removalOrders as $order): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($order['order_id']) ?></strong>
                        </td>
                        <td>
                            <?= $order['request_date'] ? date('d/m/Y H:i', strtotime($order['request_date'])) : 'N/A' ?>
                        </td>
                        <td>
                            <code><?= htmlspecialchars($order['sku']) ?></code>
                        </td>
                        <td>
                            <?php if ($order['product_name']): ?>
                                <?= htmlspecialchars(substr($order['product_name'], 0, 50)) ?>
                                <?php if (strlen($order['product_name']) > 50) echo '...'; ?>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($order['fnsku'] ?? 'N/A') ?>
                        </td>
                        <td>
                            <?php if ($order['disposition']): ?>
                                <span class="disposition-badge <?= strtolower($order['disposition']) ?>">
                                    <?= htmlspecialchars($order['disposition']) ?>
                                </span>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td class="quantity-cell <?= $order['requested_quantity'] > 0 ? 'positive' : 'zero' ?>">
                            <?= number_format($order['requested_quantity']) ?>
                        </td>
                        <td class="quantity-cell <?= $order['shipped_quantity'] > 0 ? 'positive' : 'zero' ?>">
                            <?= number_format($order['shipped_quantity']) ?>
                        </td>
                        <td class="quantity-cell <?= $order['disposed_quantity'] > 0 ? 'positive' : 'zero' ?>">
                            <?= number_format($order['disposed_quantity']) ?>
                        </td>
                        <td class="quantity-cell <?= $order['cancelled_quantity'] > 0 ? 'positive' : 'zero' ?>">
                            <?= number_format($order['cancelled_quantity']) ?>
                        </td>
                        <td>
                            <?php if ($order['order_status']): ?>
                                <span class="status-badge <?= strtolower(str_replace(' ', '-', $order['order_status'])) ?>">
                                    <?= htmlspecialchars($order['order_status']) ?>
                                </span>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($order['order_type'] ?? 'Removal') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="text-align: center; color: var(--text-muted); margin: var(--spacing-lg) 0;">
            Showing <?= count($removalOrders) ?> removal orders
        </div>
        
        <?php else: ?>
        
        <!-- Empty State -->
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon">📦</div>
                <div class="empty-state-title">Nessun Removal Order Trovato</div>
                <div class="empty-state-text">
                    Scarica il report da Amazon per visualizzare i tuoi removal orders.
                </div>
            </div>
        </div>
        
        <?php endif; ?>

    </div>

    <script>
    // ============================================
    // DOWNLOAD & POLLING LOGIC
    // ============================================
    
    const userId = <?= $userId ?>;
    const form = document.getElementById('download-form');
    const downloadBtn = document.getElementById('download-btn');
    const loadingSpinner = document.getElementById('loading-spinner');
    const spinnerText = document.getElementById('spinner-text');
    const progressMessages = document.getElementById('progress-messages');
    
    let pollingInterval = null;
    let currentReportId = null;
    
    // Handle form submit
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        
        if (!startDate || !endDate) {
            alert('Inserisci entrambe le date');
            return;
        }
        
        // Disable button
        downloadBtn.disabled = true;
        loadingSpinner.classList.add('active');
        progressMessages.innerHTML = '';
        
        try {
            // Step 1: Request report
            spinnerText.textContent = '⏳ Richiedendo report ad Amazon...';
            
            const response = await fetch('removal_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=request_report&user_id=${userId}&start_date=${startDate}&end_date=${endDate}`
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Errore sconosciuto');
            }
            
            currentReportId = result.report_id;
            
            addProgressMessage('success', `✅ Report richiesto! ID: ${currentReportId}`);
            
            // Step 2: Start polling
            startPolling(currentReportId);
            
        } catch (error) {
            console.error('Error:', error);
            addProgressMessage('error', `❌ Errore: ${error.message}`);
            resetUI();
        }
    });
    
    // Start polling for report status
    function startPolling(reportId) {
        spinnerText.textContent = '⏳ Attendere... Amazon sta generando il report...';
        
        pollingInterval = setInterval(async () => {
            try {
                const response = await fetch(`removal_api.php?action=check_status&user_id=${userId}&report_id=${reportId}`);
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Errore controllo status');
                }
                
                const status = result.status;
                
                console.log('Status:', status);
                
                if (status === 'DONE') {
                    // Report ready!
                    clearInterval(pollingInterval);
                    addProgressMessage('success', '✅ Report pronto! Download in corso...');
                    
                    // Download report
                    await downloadReport(result.document_id);
                    
                } else if (status === 'FATAL' || status === 'CANCELLED') {
                    clearInterval(pollingInterval);
                    throw new Error(`Report fallito: ${status}`);
                    
                } else if (status === 'IN_QUEUE') {
                    spinnerText.textContent = '⏳ Report in coda...';
                    
                } else if (status === 'IN_PROGRESS') {
                    spinnerText.textContent = '⏳ Amazon sta generando il report...';
                }
                
            } catch (error) {
                console.error('Polling error:', error);
                clearInterval(pollingInterval);
                addProgressMessage('error', `❌ Errore: ${error.message}`);
                resetUI();
            }
        }, 5000); // Polling ogni 5 secondi
    }
    
    // Download and process report
    async function downloadReport(documentId) {
        try {
            spinnerText.textContent = '⏳ Scaricando e processando report...';
            
            const response = await fetch('removal_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=download_report&user_id=${userId}&document_id=${documentId}`
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Errore download');
            }
            
            addProgressMessage('success', `🎉 Completato! ${result.inserted} ordini salvati, ${result.skipped} saltati.`);
            
            // Reload page dopo 2 secondi
            setTimeout(() => {
                window.location.href = `removal_orders.php?user_id=${userId}`;
            }, 2000);
            
        } catch (error) {
            console.error('Download error:', error);
            addProgressMessage('error', `❌ Errore download: ${error.message}`);
            resetUI();
        }
    }
    
    // Add progress message
    function addProgressMessage(type, message) {
        const div = document.createElement('div');
        div.className = `progress-message ${type}`;
        div.textContent = message;
        progressMessages.appendChild(div);
    }
    
    // Reset UI
    function resetUI() {
        downloadBtn.disabled = false;
        loadingSpinner.classList.remove('active');
    }
    </script>

</body>
</html>

