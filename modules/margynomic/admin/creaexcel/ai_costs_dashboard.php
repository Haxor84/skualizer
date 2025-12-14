<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../admin_helpers.php';
require_once __DIR__ . '/ai/core/CostCalculator.php';

// Check auth admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: /modules/margynomic/admin/admin_login.php');
    exit;
}

// Admin può vedere costi di qualsiasi user (come in creator.php)
$userId = isset($_GET['user_id']) && $_GET['user_id'] > 0 
    ? (int)$_GET['user_id']
    : (isset($_SESSION['selected_user_id']) && $_SESSION['selected_user_id'] > 0 
        ? (int)$_SESSION['selected_user_id'] 
        : $_SESSION['admin_id']);

$pdo = getDbConnection();

// Carica lista utenti per il selettore
$stmt = $pdo->query("SELECT id, nome, email FROM users WHERE is_active = 1 ORDER BY nome ASC");
$availableUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$daysBack = isset($_GET['days']) ? intval($_GET['days']) : 30;

$stats = CostCalculator::getUserStats($pdo, $userId, $daysBack);
$operations = CostCalculator::getOperationBreakdown($pdo, $userId, $daysBack);
$trend = CostCalculator::getDailyTrend($pdo, $userId, $daysBack);

// Calcola totali
$totalCost = array_sum(array_column($stats, 'total_cost'));
$totalCalls = array_sum(array_column($stats, 'total_calls'));
$totalTokens = array_sum(array_column($stats, 'total_tokens'));

// Include admin navigation
echo getAdminHeader('AI Costs Dashboard');
echo getAdminNavigation('excel_creator');
?>

<style>
        :root {
            --primary-color: #2563eb;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --bg-light: #f9fafb;
            --bg-white: #ffffff;
            --border-color: #e5e7eb;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: var(--bg-white);
            padding: 24px 30px;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        h1 {
            color: var(--primary-color);
            font-size: 28px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .user-selector-box {
            margin: 20px 0;
            padding: 16px;
            background: var(--bg-light);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .user-selector-box label {
            color: var(--primary-color);
            font-weight: 600;
            margin-right: 12px;
            font-size: 14px;
        }
        
        .user-selector-box select {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-size: 14px;
            cursor: pointer;
            background: var(--bg-white);
            color: var(--text-dark);
            transition: border-color 0.2s;
        }
        
        .user-selector-box select:hover {
            border-color: var(--primary-color);
        }
        
        .user-selector-box select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .user-selector-box span {
            margin-left: 16px;
            color: var(--text-muted);
            font-size: 13px;
        }
        
        .user-selector-box strong {
            color: var(--text-dark);
        }
        
        .period-selector {
            margin-top: 16px;
            display: flex;
            gap: 10px;
        }
        
        .period-btn {
            background: var(--bg-white);
            color: var(--text-dark);
            border: 1px solid var(--border-color);
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .period-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .period-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--bg-white);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            transition: box-shadow 0.2s;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 8px 0;
        }
        
        .stat-sublabel {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        .chart-card {
            background: var(--bg-white);
            padding: 24px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        h2 {
            color: var(--text-dark);
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            text-align: left;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background: var(--bg-light);
            font-weight: 600;
            color: var(--text-dark);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        tr:hover td {
            background: var(--bg-light);
        }
        
        tbody tr:last-child td {
            border-bottom: none;
        }
        
        .cost {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-muted);
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h2 {
            color: var(--text-dark);
            font-size: 20px;
            margin-bottom: 8px;
        }
        
        .empty-state p {
            color: var(--text-muted);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💰 AI Costs Dashboard</h1>
            <p class="subtitle">Monitoraggio costi API Gemini in tempo reale</p>
            
            <!-- User Selector -->
            <div class="user-selector-box">
                <label>👤 Utente:</label>
                <select onchange="window.location.href='?user_id=' + this.value + '&days=<?= $daysBack ?>'">
                    <?php foreach ($availableUsers as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $userId == $user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?> (ID: <?= $user['id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span>
                    Email: <strong><?php 
                        $currentUser = array_filter($availableUsers, fn($u) => $u['id'] == $userId);
                        echo !empty($currentUser) ? htmlspecialchars(reset($currentUser)['email']) : 'N/D';
                    ?></strong>
                </span>
            </div>
            
            <div class="period-selector">
                <button class="period-btn <?= $daysBack === 7 ? 'active' : '' ?>" 
                        onclick="location.href='?user_id=<?= $userId ?>&days=7'">7 Giorni</button>
                <button class="period-btn <?= $daysBack === 30 ? 'active' : '' ?>" 
                        onclick="location.href='?user_id=<?= $userId ?>&days=30'">30 Giorni</button>
                <button class="period-btn <?= $daysBack === 90 ? 'active' : '' ?>" 
                        onclick="location.href='?user_id=<?= $userId ?>&days=90'">90 Giorni</button>
            </div>
        </div>
        
        <?php if ($totalCalls > 0): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Cost</div>
                <div class="stat-value cost">$<?= number_format($totalCost, 4) ?></div>
                <div class="stat-sublabel">Ultimi <?= $daysBack ?> giorni</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">API Calls</div>
                <div class="stat-value"><?= number_format($totalCalls) ?></div>
                <div class="stat-sublabel">Chiamate totali</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Tokens</div>
                <div class="stat-value"><?= number_format($totalTokens) ?></div>
                <div class="stat-sublabel">Input + Output</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Cost/Call</div>
                <div class="stat-value cost">$<?= $totalCalls > 0 ? number_format($totalCost/$totalCalls, 4) : '0.0000' ?></div>
                <div class="stat-sublabel">Costo medio</div>
            </div>
        </div>
        
        <div class="chart-card">
            <h2>📊 Breakdown by Provider/Model</h2>
            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Model</th>
                        <th>Calls</th>
                        <th>Input Tokens</th>
                        <th>Output Tokens</th>
                        <th>Thinking Tokens</th>
                        <th>Total Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $stat): ?>
                    <tr>
                        <td><?= htmlspecialchars($stat['provider']) ?></td>
                        <td><?= htmlspecialchars($stat['model']) ?></td>
                        <td><?= number_format($stat['total_calls']) ?></td>
                        <td><?= number_format($stat['total_input_tokens']) ?></td>
                        <td><?= number_format($stat['total_output_tokens']) ?></td>
                        <td><?= number_format($stat['total_thinking_tokens']) ?></td>
                        <td class="cost">$<?= number_format($stat['total_cost'], 4) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($operations)): ?>
        <div class="chart-card">
            <h2>⚡ Breakdown by Operation</h2>
            <table>
                <thead>
                    <tr>
                        <th>Operation</th>
                        <th>Calls</th>
                        <th>Avg Cost</th>
                        <th>Total Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operations as $op): ?>
                    <tr>
                        <td><?= htmlspecialchars($op['operation']) ?></td>
                        <td><?= number_format($op['calls']) ?></td>
                        <td class="cost">$<?= number_format($op['avg_cost'], 4) ?></td>
                        <td class="cost">$<?= number_format($op['total_cost'], 4) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($trend)): ?>
        <div class="chart-card">
            <h2>📈 Daily Trend</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Calls</th>
                        <th>Tokens</th>
                        <th>Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($trend) as $day): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($day['date'])) ?></td>
                        <td><?= number_format($day['calls']) ?></td>
                        <td><?= number_format($day['tokens']) ?></td>
                        <td class="cost">$<?= number_format($day['cost'], 4) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="chart-card">
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <h2>Nessun dato disponibile</h2>
                <p>Inizia a generare contenuti con AI per vedere le statistiche dei costi.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
