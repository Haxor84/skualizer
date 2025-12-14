<?php
/**
 * Profilo Utente - Dashboard Margynomic
 * File: profilo_utente.php
 * 
 * Dashboard utente con statistiche automatiche Settlement + Inventory
 * Design magico ispirato a margini.php e inventory.php
 */

require_once 'config/config.php';
require_once 'login/auth_helpers.php';

// Verifica autenticazione
if (!isLoggedIn()) {
    header('Location: login/login.php');
    exit();
}

// Ottieni dati utente
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Redirect mobile
if (isMobileDevice()) {
    header('Location: /modules/mobile/Profilo.php');
    exit;
}

// === OTTIENI STATISTICHE AUTOMATICHE ===
function getAutomaticStats($userId) {
    try {
        $pdo = getDbConnection();
        $stats = [
            'settlement' => ['last_sync' => null, 'total_reports' => 0, 'processed_reports' => 0],
            'inventory' => ['last_sync' => null, 'total_skus' => 0, 'total_units' => 0],
            'amazon' => ['connected' => false, 'marketplace' => null],
            'system' => ['next_settlement' => null, 'next_inventory' => null]
        ];
        
        // Settlement Statistics
        $stmt = $pdo->prepare("
            SELECT MAX(created_at) as last_sync, COUNT(*) as total_reports,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as processed_reports
            FROM settlement_report_queue WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $settlementData = $stmt->fetch();
        if ($settlementData) {
            $stats['settlement'] = [
                'last_sync' => $settlementData['last_sync'],
                'total_reports' => $settlementData['total_reports'] ?? 0,
                'processed_reports' => $settlementData['processed_reports'] ?? 0
            ];
        }
        
        // Inventory Statistics
        $stmt = $pdo->prepare("
            SELECT MAX(last_updated) as last_sync, COUNT(*) as total_skus,
                   SUM(CAST(afn_fulfillable_quantity AS UNSIGNED)) as total_units
            FROM inventory WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $inventoryData = $stmt->fetch();
        if ($inventoryData) {
            $stats['inventory'] = [
                'last_sync' => $inventoryData['last_sync'],
                'total_skus' => $inventoryData['total_skus'] ?? 0,
                'total_units' => $inventoryData['total_units'] ?? 0
            ];
        }
        
        // Amazon Connection Status
        $stmt = $pdo->prepare("
            SELECT marketplace_id, created_at 
            FROM amazon_client_tokens 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        $amazonData = $stmt->fetch();
        if ($amazonData) {
            $stats['amazon'] = [
                'connected' => true,
                'marketplace' => $amazonData['marketplace_id'],
                'connected_since' => $amazonData['created_at']
            ];
        }
        
        // Sistema: prossimi sync (calcolati)
        $stats['system'] = [
            'next_settlement' => date('d/m/Y H:i', strtotime('+2 days 02:00')),
            'next_inventory' => date('H:i', strtotime('+1 hour'))
        ];
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Errore statistiche utente: " . $e->getMessage());
        return null;
    }
}

$userStats = getAutomaticStats($userId);

// === GESTIONE NOTIFICHE EMAIL ===
$notifications = [];
if (isset($_POST['save_notifications'])) {
    try {
        $pdo = getDbConnection();
        $email = trim($_POST['email_address']);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sendDay = (int)$_POST['send_day'];
        $sendTime = $_POST['send_time'];
        
        // Inserisci o aggiorna configurazione
        $stmt = $pdo->prepare("
            INSERT INTO user_notifications (user_id, notification_type, email_address, is_active, send_day, send_time)
            VALUES (?, 'inventory_weekly', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            email_address = VALUES(email_address),
            is_active = VALUES(is_active),
            send_day = VALUES(send_day),
            send_time = VALUES(send_time),
            updated_at = NOW()
        ");
        $stmt->execute([$userId, $email, $isActive, $sendDay, $sendTime]);
        
        $message = '✅ Configurazione notifiche salvata correttamente!';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = '❌ Errore salvataggio: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Carica configurazioni esistenti
try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT email_address, is_active, send_day, send_time 
        FROM user_notifications 
        WHERE user_id = ? AND notification_type = 'inventory_weekly'
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetch() ?: [
        'email_address' => $currentUser['email'],
        'is_active' => 0,
        'send_day' => 1,
        'send_time' => '08:00:00'
    ];
} catch (Exception $e) {
    $notifications = [
        'email_address' => $currentUser['email'],
        'is_active' => 0,
        'send_day' => 1,
        'send_time' => '08:00:00'
    ];
}

// Gestisci messaggi di feedback
$message = isset($message) ? $message : '';
$messageType = isset($messageType) ? $messageType : '';

// Messaggi OAuth
if (isset($_SESSION['oauth_success'])) {
    $message = $_SESSION['oauth_success'];
    $messageType = 'success';
    unset($_SESSION['oauth_success']);
}

if (isset($_SESSION['oauth_error'])) {
    $message = $_SESSION['oauth_error'];
    $messageType = 'error';
    unset($_SESSION['oauth_error']);
}

// Calcola tempo relativo per display user-friendly
function timeAgo($datetime) {
    if (!$datetime) return 'Mai';
    
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'Ora';
    if ($time < 3600) return floor($time/60) . ' min fa';
    if ($time < 86400) return floor($time/3600) . ' ore fa';
    return floor($time/86400) . ' giorni fa';
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Profilo - Margynomic</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* === RESET & BASE === */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #2d3748;
        }

        /* === HEADER MAGICO === */
        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-logo img {
            height: 40px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .header-title {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-nav {
            display: flex;
            gap: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            color: #4a5568;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.6s;
        }

        .nav-link:hover::before {
            left: 100%;
        }

        .nav-link:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .nav-link.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        /* === DASHBOARD CONTAINER === */
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* === WELCOME SECTION EPICA === */
        .welcome-hero {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 3rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .welcome-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(102, 126, 234, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .welcome-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .welcome-title {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1rem;
            animation: fadeInUp 1s ease-out;
        }

        .welcome-subtitle {
            font-size: 1.2rem;
            color: #64748b;
            margin-bottom: 2rem;
            animation: fadeInUp 1s ease-out 0.2s both;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* === STATS GRID SPETTACOLARE === */
        .stats-supergrid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-supercard {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-supercard::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent 0%, rgba(102, 126, 234, 0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-supercard:hover::before {
            opacity: 1;
        }

        .stat-supercard:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 2;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .stat-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { transform: translateX(-100%); }
            50% { transform: translateX(100%); }
        }

        .stat-icon.settlement { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.inventory { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-icon.amazon { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .stat-icon.system { background: linear-gradient(135deg, #43e97b, #38f9d7); }

        .stat-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-body {
            position: relative;
            z-index: 2;
        }

        .stat-metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .stat-metric:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: #64748b;
            font-weight: 500;
        }

        .stat-value {
            font-weight: 700;
            color: #2d3748;
            font-size: 1.1rem;
        }

        .stat-value.highlight {
            color: #667eea;
        }

        .stat-value.success {
            color: #38a169;
        }

        .stat-value.warning {
            color: #ed8936;
        }

        /* === AMAZON CONNECTION SPECIALE === */
        .amazon-connection {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
            margin-bottom: 2rem;
        }

        .connection-status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .status-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            position: relative;
        }

        .status-indicator.connected {
            background: linear-gradient(135deg, #38a169, #48bb78);
            box-shadow: 0 0 20px rgba(56, 161, 105, 0.5);
            animation: pulse 2s ease-in-out infinite;
        }

        .status-indicator.disconnected {
            background: linear-gradient(135deg, #e53e3e, #fc8181);
            animation: blink 1s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .connection-text {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .connection-text.connected {
            color: #38a169;
        }

        .connection-text.disconnected {
            color: #e53e3e;
        }

        /* === BUTTON MAGICO === */
        .btn-magic {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            font-size: 1rem;
        }

        .btn-magic::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s;
        }

        .btn-magic:hover::before {
            left: 100%;
        }

        /* Bottone Margynomic - Verde */
        .btn-primary {
            background: linear-gradient(135deg, #38a169, #48bb78);
            color: white;
            box-shadow: 0 10px 30px rgba(56, 161, 105, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(56, 161, 105, 0.4);
        }

        /* Bottone Previsync - Arancione */
        .btn-success {
            background: linear-gradient(135deg, #FF6B35, #F7931E);
            color: white;
            box-shadow: 0 10px 30px rgba(255, 107, 53, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(255, 107, 53, 0.4);
        }

        /* Bottone EasyShip - Rosso */
        .btn-easyship {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: white;
            box-shadow: 0 10px 30px rgba(220, 38, 38, 0.3);
        }

        .btn-easyship:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(220, 38, 38, 0.4);
        }

        /* === MESSAGGI === */
        .message {
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            font-weight: 600;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: slideInDown 0.5s ease-out;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.success {
            background: linear-gradient(135deg, rgba(56, 161, 105, 0.9), rgba(72, 187, 120, 0.9));
            color: white;
            box-shadow: 0 15px 40px rgba(56, 161, 105, 0.3);
        }

        .message.error {
            background: linear-gradient(135deg, rgba(229, 62, 62, 0.9), rgba(252, 129, 129, 0.9));
            color: white;
            box-shadow: 0 15px 40px rgba(229, 62, 62, 0.3);
        }

        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }
            
            .welcome-hero {
                padding: 2rem 1.5rem;
            }
            
            .welcome-title {
                font-size: 2rem;
            }
            
            .stats-supergrid {
                grid-template-columns: 1fr;
            }
            
            .header-nav {
                display: none;
            }
        }

        /* === LOADING ANIMATION === */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #667eea;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php require_once 'shared_header.php'; ?>

    <!-- Main Dashboard -->
    <div class="dashboard-container">
        
        <!-- Hero Welcome -->
        <div class="welcome-hero">
            <div class="welcome-content">
                <h1 class="welcome-title">
                    <i class="fas fa-user"></i> Ciao <?php echo htmlspecialchars($currentUser['nome']); ?>! ✨
                </h1>
                <p class="welcome-subtitle">
                    La tua dashboard personale con statistiche automatiche in tempo reale
                </p>
            </div>
        </div>

        <!-- Messaggi -->
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Supergrid -->
        <div class="stats-supergrid">
            
            <!-- Settlement Card -->
            <div class="stat-supercard">
                <div class="stat-header">
                    <div class="stat-icon settlement">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-title">Margynomic</div>
                </div>
                <div class="stat-body">
                    <div class="stat-metric">
                        <span class="stat-label">Ultimo Sync</span>
                        <span class="stat-value highlight">
                            <?php echo timeAgo($userStats['settlement']['last_sync']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Reports Totali</span>
                        <span class="stat-value">
                            <?php echo number_format($userStats['settlement']['total_reports']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Processati</span>
                        <span class="stat-value success">
                            <?php echo number_format($userStats['settlement']['processed_reports']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Prossimo Sync</span>
                        <span class="stat-value">
                            <?php echo $userStats['system']['next_settlement']; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Inventory Card -->
            <div class="stat-supercard">
                <div class="stat-header">
                    <div class="stat-icon inventory">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-title">PreviSync</div>
                </div>
                <div class="stat-body">
                    <div class="stat-metric">
                        <span class="stat-label">Ultimo Sync</span>
                        <span class="stat-value highlight">
                            <?php echo timeAgo($userStats['inventory']['last_sync']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">SKU Mappati</span>
                        <span class="stat-value">
                            <?php echo number_format($userStats['inventory']['total_skus']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Unità Totali</span>
                        <span class="stat-value success">
                            <?php echo number_format($userStats['inventory']['total_units']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Prossimo Sync</span>
                        <span class="stat-value">
                            Ogni ora (<?php echo $userStats['system']['next_inventory']; ?>)
                        </span>
                    </div>
                </div>
            </div>

            <!-- Amazon Connection Card -->
            <div class="stat-supercard">
                <div class="stat-header">
                    <div class="stat-icon amazon">
                        <i class="fab fa-amazon"></i>
                    </div>
                    <div class="stat-title">OAuth Amazon</div>
                </div>
                <div class="stat-body">
                    <div class="stat-metric">
                        <span class="stat-label">Status</span>
                        <span class="stat-value <?php echo $userStats['amazon']['connected'] ? 'success' : 'warning'; ?>">
                            <?php echo $userStats['amazon']['connected'] ? '✅ Connesso' : '❌ Non Connesso'; ?>
                        </span>
                    </div>
                    <?php if ($userStats['amazon']['connected']): ?>
                        <div class="stat-metric">
                            <span class="stat-label">Marketplace</span>
                            <span class="stat-value">
                                <?php echo $userStats['amazon']['marketplace'] === 'APJ6JRA9NG5V4' ? '🇮🇹 Amazon.it' : $userStats['amazon']['marketplace']; ?>
                            </span>
                        </div>
                        <div class="stat-metric">
                            <span class="stat-label">Autorizzato il</span>
                            <span class="stat-value">
                                <?php echo date('d/m/Y', strtotime($userStats['amazon']['connected_since'])); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Status Card -->
            <div class="stat-supercard">
                <div class="stat-header">
                    <div class="stat-icon system">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <div class="stat-title">Sistema Automatico</div>
                </div>
                <div class="stat-body">
                    <div class="stat-metric">
                        <span class="stat-label">Cron Settlement</span>
                        <span class="stat-value success">
                            🔄 Attivo
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Cron Inventory</span>
                        <span class="stat-value success">
                            ⚡ Attivo
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Rate Limit</span>
                        <span class="stat-value">
                            🟢 Ottimale
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Performance</span>
                        <span class="stat-value success">
                            🚀 Eccellente
                        </span>
                    </div>
                </div>
            </div>

        </div>

        <!-- Amazon Connection Section -->
        <div class="amazon-connection">
            <div class="connection-status">
                <div class="status-indicator <?php echo $userStats['amazon']['connected'] ? 'connected' : 'disconnected'; ?>"></div>
                <div class="connection-text <?php echo $userStats['amazon']['connected'] ? 'connected' : 'disconnected'; ?>">
                    <?php if ($userStats['amazon']['connected']): ?>
                        Amazon SP-API Connesso
                    <?php else: ?>
                        Amazon SP-API Non Connesso
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($userStats['amazon']['connected']): ?>
                <p style="margin-bottom: 1.5rem; color: #64748b;">
        ✨ Perfetto! I tuoi dati Amazon vengono sincronizzati automaticamente. 
    </p>
<?php else: ?>
    <p style="margin-bottom: 1.5rem; color: #64748b;">
        🔗 Connetti il tuo account Amazon per iniziare la sincronizzazione automatica dei dati.
    </p>
                <a href="sincro/oauth_controller.php?action=authorize" class="btn-magic btn-primary">
                    <i class="fab fa-amazon"></i>
                    Connetti Amazon SP-API
                </a>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="stats-supergrid" style="margin-top: 2rem;">
            <div class="stat-supercard">
                <div class="stat-header">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                        <i class="fas fa-sync"></i>
                    </div>
                    <div class="stat-title">Azioni Rapide</div>
                </div>
                <div class="stat-body">
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <a href="margini/margins_overview.php" class="btn-magic btn-primary" style="justify-content: center;">
                            <i class="fas fa-chart-line"></i>
                            Analizza Margini
                        </a>
                        <a href="../previsync/inventory.php" class="btn-magic btn-success" style="justify-content: center;">
                            <i class="fas fa-boxes"></i>
                            Gestisci Inventory
                        </a>
                        <a href="../orderinsights/overview.php" class="btn-magic btn-orderinsights" style="justify-content: center; background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); color: white;">
                            <i class="fas fa-microscope"></i>
                            Order Insights
                        </a>
                        <a href="../inbound/trid/TridScanner.php" class="btn-magic btn-tridscanner" style="justify-content: center; background: linear-gradient(135deg, #ec4899 0%, #f472b6 100%); color: white;">
                            <i class="fas fa-search"></i>
                            TridScanner
                        </a>
                        <a href="../easyship/easyship.php" class="btn-magic btn-easyship" style="justify-content: center;">
                            <i class="fas fa-truck"></i>
                            EasyShip
                        </a>
                        <a href="../rendiconto/index.php" class="btn-magic btn-secondary" style="justify-content: center; background: linear-gradient(135deg, #3182ce 0%, #2b77cb 100%); color: white;">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Rendiconto
                        </a>
                    </div>
                </div>
            </div>

            <div class="stat-supercard">
                <div class="stat-header">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b, #38f9d7);">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <div class="stat-title">Informazioni Account</div>
                </div>
                <div class="stat-body">
                    <div class="stat-metric">
                        <span class="stat-label">Nome</span>
                        <span class="stat-value">
                            <?php echo htmlspecialchars($currentUser['nome']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Email</span>
                        <span class="stat-value">
                            <?php echo htmlspecialchars($currentUser['email']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Ruolo</span>
                        <span class="stat-value highlight">
                            <?php echo ucfirst($currentUser['ruolo']); ?>
                        </span>
                    </div>
                    <div class="stat-metric">
                        <span class="stat-label">Membro dal</span>
                        <span class="stat-value">
                            <?php echo isset($currentUser['created_at']) ? 
                                date('d/m/Y', strtotime($currentUser['created_at'])) : 
                                date('d/m/Y', strtotime($currentUser['creato_il'] ?? 'now')); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Notifiche Email Card -->
            <div class="stat-supercard">
                <div class="stat-header">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="stat-title">📧 Notifiche Email</div>
                </div>
                <div class="stat-body">
                    <form method="POST" style="display: flex; flex-direction: column; gap: 1rem;">
                        <div class="stat-metric">
                            <span class="stat-label">Report Settimanale Inventario</span>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="is_active" value="1" 
                                       <?php echo $notifications['is_active'] ? 'checked' : ''; ?>
                                       style="transform: scale(1.2);">
                                <span class="stat-value <?php echo $notifications['is_active'] ? 'success' : ''; ?>">
                                    <?php echo $notifications['is_active'] ? '✅ Attivo' : '❌ Disattivato'; ?>
                                </span>
                            </label>
                        </div>
                        
                        <div class="stat-metric">
                            <span class="stat-label">Email Destinatario</span>
                            <input type="email" name="email_address" 
                                   value="<?php echo htmlspecialchars($notifications['email_address']); ?>"
                                   required
                                   style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.9rem;">
                        </div>
                        
                        <div style="display: flex; gap: 1rem;">
                            <div style="flex: 1;">
                                <span class="stat-label">Giorno</span>
                                <select name="send_day" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                                    <option value="1" <?php echo $notifications['send_day'] == 1 ? 'selected' : ''; ?>>Lunedì</option>
                                    <option value="2" <?php echo $notifications['send_day'] == 2 ? 'selected' : ''; ?>>Martedì</option>
                                    <option value="3" <?php echo $notifications['send_day'] == 3 ? 'selected' : ''; ?>>Mercoledì</option>
                                    <option value="4" <?php echo $notifications['send_day'] == 4 ? 'selected' : ''; ?>>Giovedì</option>
                                    <option value="5" <?php echo $notifications['send_day'] == 5 ? 'selected' : ''; ?>>Venerdì</option>
                                    <option value="6" <?php echo $notifications['send_day'] == 6 ? 'selected' : ''; ?>>Sabato</option>
                                    <option value="7" <?php echo $notifications['send_day'] == 7 ? 'selected' : ''; ?>>Domenica</option>
                                </select>
                            </div>
                            <div style="flex: 1;">
                                <span class="stat-label">Orario</span>
                                <input type="time" name="send_time" 
                                       value="<?php echo substr($notifications['send_time'], 0, 5); ?>"
                                       style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                        </div>
                        
                        <button type="submit" name="save_notifications" class="btn-magic btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-save"></i>
                            Salva Configurazione
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Footer Magic -->
        <div style="text-align: center; margin-top: 3rem; padding: 2rem; background: rgba(255,255,255,0.1); backdrop-filter: blur(20px); border-radius: 20px; border: 1px solid rgba(255,255,255,0.2);">
            <p style="color: white; font-weight: 500; text-shadow: 0 2px 4px rgba(0,0,0,0.3);">
                ✨ <strong>Sistema Automatico Attivo</strong> ✨<br>
                I tuoi dati vengono sincronizzati automaticamente in background.
            </p>
        </div>

    </div>

    <!-- JavaScript Magic -->
    <script>
        // Logout function
        function doLogout() {
            if (confirm('Sei sicuro di voler uscire?')) {
                window.location.href = 'login/logout.php';
            }
        }

        // Refresh statistics
        function refreshStats() {
            const refreshText = document.getElementById('refreshText');
            const originalText = refreshText.textContent;
            
            refreshText.innerHTML = '<div class="loading"></div> Aggiornamento...';
            
            // Simulate API call delay
            setTimeout(() => {
                location.reload();
            }, 1500);
        }

        // Auto-refresh every 5 minutes
        setInterval(() => {
            // Silent refresh of specific stats without page reload
            console.log('🔄 Auto-refresh statistiche...');
        }, 5 * 60 * 1000);

        // Entrance animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-supercard');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 150);
            });
        });

        // Advanced hover effects
        document.querySelectorAll('.stat-supercard').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-10px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Particles background effect
        function createParticle() {
            const particle = document.createElement('div');
            particle.style.position = 'fixed';
            particle.style.width = '4px';
            particle.style.height = '4px';
            particle.style.background = 'rgba(102, 126, 234, 0.3)';
            particle.style.borderRadius = '50%';
            particle.style.pointerEvents = 'none';
            particle.style.zIndex = '-1';
            particle.style.left = Math.random() * window.innerWidth + 'px';
            particle.style.top = '-10px';
            
            document.body.appendChild(particle);
            
            const animation = particle.animate([
                { transform: 'translateY(0px)', opacity: 1 },
                { transform: `translateY(${window.innerHeight + 10}px)`, opacity: 0 }
            ], {
                duration: Math.random() * 3000 + 2000,
                easing: 'linear'
            });
            
            animation.onfinish = () => {
                particle.remove();
            };
        }

        // Create particles periodically
        setInterval(createParticle, 2000);

        // Status indicator pulse effect
        const statusIndicators = document.querySelectorAll('.status-indicator.connected');
        statusIndicators.forEach(indicator => {
            setInterval(() => {
                indicator.style.boxShadow = '0 0 30px rgba(56, 161, 105, 0.8)';
                setTimeout(() => {
                    indicator.style.boxShadow = '0 0 20px rgba(56, 161, 105, 0.5)';
                }, 500);
            }, 2000);
        });

        // Success message auto-hide
        const successMessage = document.querySelector('.message.success');
        if (successMessage) {
            setTimeout(() => {
                successMessage.style.opacity = '0';
                successMessage.style.transform = 'translateY(-30px)';
                setTimeout(() => {
                    successMessage.remove();
                }, 300);
            }, 5000);
        }

        console.log('🎯 Margynomic Dashboard Loaded - Sistema Automatico Attivo! ✨');
    </script>
</body>
</html>