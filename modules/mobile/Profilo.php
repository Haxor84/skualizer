<?php
/**
 * Mobile Profilo - Profilo Utente
 * Versione mobile semplificata di modules/margynomic/profilo_utente.php
 */

// Config e Auth
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';

// Include Mobile Cache System
require_once __DIR__ . '/helpers/mobile_cache_helper.php';

if (!isLoggedIn()) {
    redirect('/modules/margynomic/login/login.php');
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['user_id'] ?? $currentUser['id'] ?? null;
$userName = $currentUser['name'] ?? $currentUser['nome'] ?? 'Utente';
$userEmail = $currentUser['email'] ?? '';
$userRole = $currentUser['user_role'] ?? $currentUser['ruolo'] ?? 'user';

if (!$userId) {
    die('Errore: User ID non trovato nella sessione.');
}

// Redirect desktop
if (!isMobileDevice()) {
    header('Location: /modules/margynomic/profilo_utente.php');
    exit;
}

$successMsg = '';
$errorMsg = '';

// Funzione per statistiche automatiche (come desktop)
function getAutomaticStatsMobile($userId) {
    try {
        $pdo = getDbConnection();
        $stats = [
            'settlement' => ['last_sync' => null, 'total_reports' => 0, 'processed_reports' => 0],
            'inventory' => ['last_sync' => null, 'total_skus' => 0, 'total_units' => 0],
            'amazon' => ['connected' => false, 'marketplace' => null],
            'orders' => ['total_orders' => 0, 'total_revenue' => 0]
        ];
        
        // Settlement Statistics
        try {
            $stmt = $pdo->prepare("
                SELECT MAX(created_at) as last_sync, COUNT(*) as total_reports,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as processed_reports
                FROM settlement_report_queue WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $settlementData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($settlementData && $settlementData['total_reports'] > 0) {
                $stats['settlement'] = [
                    'last_sync' => $settlementData['last_sync'],
                    'total_reports' => (int)$settlementData['total_reports'],
                    'processed_reports' => (int)$settlementData['processed_reports']
                ];
            }
        } catch (Exception $e) {
            // Tabella non esiste, ignora
        }
        
        // Inventory Statistics
        try {
            $stmt = $pdo->prepare("
                SELECT MAX(last_updated) as last_sync, COUNT(*) as total_skus,
                       SUM(CAST(afn_fulfillable_quantity AS UNSIGNED)) as total_units
                FROM inventory WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $inventoryData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($inventoryData && $inventoryData['total_skus'] > 0) {
                $stats['inventory'] = [
                    'last_sync' => $inventoryData['last_sync'],
                    'total_skus' => (int)$inventoryData['total_skus'],
                    'total_units' => (int)$inventoryData['total_units']
                ];
            }
        } catch (Exception $e) {
            // Tabella non esiste, ignora
        }
        
        // Amazon Connection Status
        try {
            $stmt = $pdo->prepare("
                SELECT marketplace_id, created_at 
                FROM amazon_client_tokens 
                WHERE user_id = ? AND is_active = 1
            ");
            $stmt->execute([$userId]);
            $amazonData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($amazonData) {
                $stats['amazon'] = [
                    'connected' => true,
                    'marketplace' => $amazonData['marketplace_id'],
                    'connected_since' => $amazonData['created_at']
                ];
            }
        } catch (Exception $e) {
            // Tabella non esiste, ignora
        }
        
        // Orders Statistics (da tabella dinamica)
        try {
            $tableName = "report_settlement_" . $userId;
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT order_id) as total_orders,
                       SUM(proceeds) as total_revenue
                FROM `{$tableName}`
            ");
            $ordersData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ordersData && $ordersData['total_orders'] > 0) {
                $stats['orders'] = [
                    'total_orders' => (int)$ordersData['total_orders'],
                    'total_revenue' => (float)$ordersData['total_revenue']
                ];
            }
        } catch (Exception $e) {
            // Tabella non esiste, ignora
        }
        
        return $stats;
        
    } catch (Exception $e) {
        return [
            'settlement' => ['last_sync' => null, 'total_reports' => 0, 'processed_reports' => 0],
            'inventory' => ['last_sync' => null, 'total_skus' => 0, 'total_units' => 0],
            'amazon' => ['connected' => false, 'marketplace' => null],
            'orders' => ['total_orders' => 0, 'total_revenue' => 0]
        ];
    }
}

// === SISTEMA CACHE (TTL: 48 ore - invalidazione event-driven) ===
$cacheData = getMobileCache($userId, 'profile_stats', 172800); // 48h

if ($cacheData !== null) {
    // Cache HIT - usa dati cachati
    $userStats = $cacheData;
} else {
    // Cache MISS - calcola dati freschi
    $userStats = getAutomaticStatsMobile($userId);
    
    // Salva in cache
    setMobileCache($userId, 'profile_stats', $userStats);
}

// Funzione tempo relativo
function timeAgo($datetime) {
    if (!$datetime) return 'Mai';
    
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'Ora';
    if ($time < 3600) return floor($time/60) . ' min fa';
    if ($time < 86400) return floor($time/3600) . ' ore fa';
    return floor($time/86400) . ' giorni fa';
}

// === GESTIONE NOTIFICHE EMAIL ===
$notifications = [];
if (isset($_POST['save_notifications'])) {
    try {
        $pdo = getDbConnection();
        $email = trim($_POST['email_address']);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sendDay = (int)$_POST['send_day'];
        $sendTime = $_POST['send_time'];
        
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
        
        $successMsg = '🟢 Configurazione notifiche salvata correttamente!';
    } catch (Exception $e) {
        $errorMsg = '❌ Errore salvataggio: ' . $e->getMessage();
    }
}

// Carica configurazioni notifiche esistenti
try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT email_address, is_active, send_day, send_time 
        FROM user_notifications 
        WHERE user_id = ? AND notification_type = 'inventory_weekly'
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'email_address' => $userEmail,
        'is_active' => 0,
        'send_day' => 1,
        'send_time' => '08:00:00'
    ];
} catch (Exception $e) {
    $notifications = [
        'email_address' => $userEmail,
        'is_active' => 0,
        'send_day' => 1,
        'send_time' => '08:00:00'
    ];
}

// Gestione messaggi OAuth
if (isset($_SESSION['oauth_success'])) {
    $successMsg = $_SESSION['oauth_success'];
    unset($_SESSION['oauth_success']);
}

if (isset($_SESSION['oauth_error'])) {
    $errorMsg = $_SESSION['oauth_error'];
    unset($_SESSION['oauth_error']);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#3b82f6">
    <meta name="apple-mobile-web-app-title" content="SkuAlizer Suite">
    <meta name="format-detection" content="telephone=no">
    <title>Profilo - Skualizer Mobile</title>
    
    <!-- PWA Icons -->
    <link rel="icon" type="image/png" sizes="192x192" href="/modules/mobile/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/modules/mobile/assets/icon-180.png">
    <link rel="manifest" href="/modules/mobile/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/modules/mobile/assets/mobile.css">
    
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/modules/mobile/sw.js').catch(() => {});
    }
    </script>
    <style>
        .hamburger-overlay.active { opacity: 1 !important; visibility: visible !important; }
        .hamburger-overlay.active .hamburger-menu { transform: translateX(0) !important; }
        .hamburger-menu-link:hover { background: #f8fafc !important; border-left-color: #667eea !important; }
    </style>
    
    <style>
    body { overflow-x: hidden; padding-top: 0 !important; }
    .mobile-content { padding-top: 0 !important; }
    .hero-welcome {
        background: linear-gradient(135deg, #4a5568 0%, #667eea 100%);
        color: white;
        padding: 0;
        margin: 0 0 16px 0;
        border-radius: 0 0 20px 20px;
        text-align: left;
        box-shadow: 0 4px 12px rgba(74, 85, 104, 0.3);
        padding-top: env(safe-area-inset-top);
    }
    .hero-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 8px 16px 18px; gap: 12px; }
    .hero-logo { flex: 1; padding-top: 0; }
    .hamburger-btn-hero {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 8px;
        width: 40px;
        height: 40px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: white;
        transition: all 0.2s;
    }
    .hamburger-btn-hero:active { transform: scale(0.95); background: rgba(255, 255, 255, 0.25); }
    .hero-title { font-size: 20px; font-weight: 700; margin-bottom: 6px; padding: 0; line-height: 1.3; text-align: left; }
    .hero-subtitle { font-size: 11px; opacity: 0.95; line-height: 1.4; padding: 0; text-align: left; letter-spacing: 0.3px; font-weight: 600; }
    .info-boxes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 16px; padding: 0 16px 20px; }
    .info-box {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 8px;
        border-left: 3px solid rgba(102, 126, 234, 0.8);
        padding: 10px;
        text-align: left;
        min-width: 0;
        overflow: hidden;
    }
    .info-box-title { font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #1a202c; }
    .info-box-text { font-size: 10px; opacity: 0.75; line-height: 1.4; color: #1a202c; }
    </style>
</head>
<body>
    <?php readfile(__DIR__ . '/assets/icons.svg'); ?>
    <div class="hamburger-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s;">
        <nav class="hamburger-menu" style="position: absolute; top: 0; right: 0; width: 80%; max-width: 320px; height: 100%; background: white; transform: translateX(100%); transition: transform 0.3s; box-shadow: -4px 0 24px rgba(0,0,0,0.15);">
            <div class="hamburger-menu-header" style="background: linear-gradient(135deg, #4a5568 0%, #667eea 100%); padding: 24px 20px; color: white;">
                <div class="hamburger-menu-title" style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Menu</div>
                <div style="font-size: 12px; opacity: 0.9;">Navigazione rapida</div>
            </div>
            <div class="hamburger-menu-nav" style="padding: 12px 0;">
                <a href="/modules/mobile/Margynomic.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-chart-line" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>Margynomic</span>
                </a>
                <a href="/modules/mobile/Previsync.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-boxes" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>PreviSync</span>
                </a>
                <a href="/modules/mobile/OrderInsights.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-microscope" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>OrderInsight</span>
                </a>
                <a href="/modules/mobile/TridScanner.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-search" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>TridScanner</span>
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>Economics</span>
                </a>
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-truck" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>EasyShip</span>
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid #667eea; background: #eef2ff;">
                    <i class="fas fa-user" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>Profilo</span>
                </a>
                <div style="height: 1px; background: #e2e8f0; margin: 12px 20px;"></div>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #667eea; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-sign-out-alt" style="font-size: 20px; color: #667eea; width: 24px; text-align: center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </div>
    <main class="mobile-content" style="padding-top: 0;">

<div class="hero-welcome">
    <div class="hero-header">
        <div class="hero-logo">
            <div class="hero-title"><i class="fas fa-user"></i> Ciao <?= htmlspecialchars($userName) ?>!</div>
            <div class="hero-subtitle">DASHBOARD AUTOMATIZZATA IN TEMPO REALE!</div>
        </div>
        <button class="hamburger-btn-hero" aria-label="Menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </div>
    <div class="info-boxes">
        <div class="info-box">
            <div class="info-box-title">👤 Account Info</div>
            <div class="info-box-text">Visualizza dettagli account e impostazioni personali.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">🔐 Sicurezza</div>
            <div class="info-box-text">Gestisci password e autorizzazioni Amazon SP-API.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">📊 Statistiche</div>
            <div class="info-box-text">Performance account: ordini, prodotti, sync attività.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">💎 Abbonamento</div>
            <div class="info-box-text">Piano attivo e funzionalità disponibili.</div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const hamburgerBtn = document.querySelector('.hamburger-btn-hero');
    const overlay = document.querySelector('.hamburger-overlay');
    if (hamburgerBtn) {
        hamburgerBtn.addEventListener('click', () => {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    }
    if (overlay) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }
});
function doLogout() {
    if (confirm('Sei sicuro di voler uscire?')) {
        window.location.href = '/modules/margynomic/login/logout.php';
    }
}
</script>

<!-- Messaggi -->
<?php if ($successMsg): ?>
<div class="section" style="background: rgba(16, 185, 129, 0.1); border-left: 4px solid var(--success); color: var(--success);">
    🟢 <?= htmlspecialchars($successMsg) ?>
</div>
<?php endif; ?>

<?php if ($errorMsg): ?>
<div class="section" style="background: rgba(239, 68, 68, 0.1); border-left: 4px solid var(--danger); color: var(--danger);">
    ❌ <?= htmlspecialchars($errorMsg) ?>
</div>
<?php endif; ?>

<!-- Sincronizzazioni Recenti -->
<div class="section">
    <div style="display: grid; grid-template-columns: 1fr; gap: 12px;">
        
        <!-- Card Margynomic -->
        <div style="background: rgba(102, 126, 234, 0.05); padding: 16px; border-radius: 12px; border: 1px solid rgba(102, 126, 234, 0.2);">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="background: linear-gradient(135deg, #667eea, #764ba2); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div style="font-weight: 700; font-size: 15px; color: #2d3748;">
                    Margynomic
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Ultimo Sync</span>
                    <span style="font-weight: 600; color: #667eea;"><?= timeAgo($userStats['settlement']['last_sync']) ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Reports Totali</span>
                    <span style="font-weight: 600;"><?= $userStats['settlement']['total_reports'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Processati</span>
                    <span style="font-weight: 600; color: var(--success);"><?= $userStats['settlement']['processed_reports'] ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Prossimo Sync</span>
                    <span style="font-weight: 600;">10/11/2025<br>02:00</span>
                </div>
            </div>
        </div>
        
        <!-- Card PreviSync -->
        <div style="background: rgba(236, 72, 153, 0.05); padding: 16px; border-radius: 12px; border: 1px solid rgba(236, 72, 153, 0.2);">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="background: linear-gradient(135deg, #f093fb, #f5576c); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white;">
                    <i class="fas fa-boxes"></i>
                </div>
                <div style="font-weight: 700; font-size: 15px; color: #2d3748;">
                    PreviSync
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Ultimo Sync</span>
                    <span style="font-weight: 600; color: #ec4899;"><?= timeAgo($userStats['inventory']['last_sync']) ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">SKU Mappati</span>
                    <span style="font-weight: 600;"><?= number_format($userStats['inventory']['total_skus'], 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Unità Totali</span>
                    <span style="font-weight: 600; color: var(--success);"><?= number_format($userStats['inventory']['total_units'], 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Prossimo Sync</span>
                    <span style="font-weight: 600;">Ogni ora (14:23)</span>
                </div>
            </div>
        </div>
        
        <!-- Card OAuth Amazon -->
        <div style="background: rgba(6, 182, 212, 0.05); padding: 16px; border-radius: 12px; border: 1px solid rgba(6, 182, 212, 0.2);">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="background: linear-gradient(135deg, #4facfe, #00f2fe); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white;">
                    <i class="fab fa-amazon"></i>
                </div>
                <div style="font-weight: 700; font-size: 15px; color: #2d3748;">
                    OAuth Amazon
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 12px; align-items: center;">
                    <span style="color: var(--text-muted);">Status</span>
                    <span style="font-weight: 600; color: <?= $userStats['amazon']['connected'] ? 'var(--success)' : 'var(--danger)' ?>;">
                        <?= $userStats['amazon']['connected'] ? '🟢 Connesso' : '🔴 Non connesso' ?>
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Marketplace</span>
                    <span style="font-weight: 600;">🇮🇹 <?= $userStats['amazon']['marketplace'] ?? 'N/A' ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                    <span style="color: var(--text-muted);">Autorizzato il</span>
                    <span style="font-weight: 600;"><?= $userStats['amazon']['connected_since'] ? date('d/m/Y', strtotime($userStats['amazon']['connected_since'])) : 'N/A' ?></span>
                </div>
            </div>
        </div>

        <!-- Card Amazon SP-API Connection Status -->
        <div style="background: <?= $userStats['amazon']['connected'] ? 'rgba(16, 185, 129, 0.05)' : 'rgba(239, 68, 68, 0.05)' ?>; padding: 16px; border-radius: 12px; border: 1px solid <?= $userStats['amazon']['connected'] ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)' ?>;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <div style="font-size: 32px;">
                    <?= $userStats['amazon']['connected'] ? '🟢' : '🔴' ?>
                </div>
                <div>
                    <div style="font-weight: 700; font-size: 15px; color: #2d3748;">
                        Amazon SP-API <?= $userStats['amazon']['connected'] ? 'Connesso' : 'Non Connesso' ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                        <?= $userStats['amazon']['connected'] ? '✨ Perfetto! Il tuo account è sincronizzato correttamente.' : '🔗 Connetti il tuo account Amazon per iniziare la sincronizzazione automatica dei dati.' ?>
                    </div>
                </div>
            </div>
            <?php if (!$userStats['amazon']['connected']): ?>
                <a href="../margynomic/sincro/oauth_controller.php?action=authorize" class="btn btn-primary btn-full" style="margin-top: 8px;">
                    <i class="fab fa-amazon"></i> Connetti Amazon SP-API
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Card Notifiche Email -->
        <div style="background: rgba(102, 126, 234, 0.05); padding: 16px; border-radius: 12px; border: 1px solid rgba(102, 126, 234, 0.2);">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="background: linear-gradient(135deg, #667eea, #764ba2); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white;">
                    <i class="fas fa-envelope"></i>
                </div>
                <div style="font-weight: 700; font-size: 15px; color: #2d3748;">
                    📧 Notifiche Email
                </div>
            </div>
            
            <form method="POST" style="display: flex; flex-direction: column; gap: 12px;">
                <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 4px;">
                    Report Settimanale Inventario
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,0.1);">
                    <span style="font-size: 12px; color: var(--text-muted);">Stato</span>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" 
                               <?= $notifications['is_active'] ? 'checked' : '' ?>
                               style="transform: scale(1.3);">
                        <span style="font-weight: 600; font-size: 13px; color: <?= $notifications['is_active'] ? 'var(--success)' : 'var(--text-muted)' ?>;">
                            <?= $notifications['is_active'] ? '🟢 Attivo' : '🔴 Disattivato' ?>
                        </span>
                    </label>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,0.1);">
                    <span style="font-size: 12px; color: var(--text-muted);">Email</span>
                    <input type="email" name="email_address" 
                           value="<?= htmlspecialchars($notifications['email_address']) ?>"
                           required
                           style="width: 60%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px;">
                </div>
                
                <div style="display: flex; gap: 8px;">
                    <div style="flex: 1;">
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-bottom: 4px;">Giorno</span>
                        <select name="send_day" style="width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px;">
                            <option value="1" <?= $notifications['send_day'] == 1 ? 'selected' : '' ?>>Lunedì</option>
                            <option value="2" <?= $notifications['send_day'] == 2 ? 'selected' : '' ?>>Martedì</option>
                            <option value="3" <?= $notifications['send_day'] == 3 ? 'selected' : '' ?>>Mercoledì</option>
                            <option value="4" <?= $notifications['send_day'] == 4 ? 'selected' : '' ?>>Giovedì</option>
                            <option value="5" <?= $notifications['send_day'] == 5 ? 'selected' : '' ?>>Venerdì</option>
                            <option value="6" <?= $notifications['send_day'] == 6 ? 'selected' : '' ?>>Sabato</option>
                            <option value="7" <?= $notifications['send_day'] == 7 ? 'selected' : '' ?>>Domenica</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-bottom: 4px;">Orario</span>
                        <input type="time" name="send_time" 
                               value="<?= substr($notifications['send_time'], 0, 5) ?>"
                               style="width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px;">
                    </div>
                </div>
                
                <button type="submit" name="save_notifications" class="btn btn-primary btn-full" style="margin-top: 8px;">
                    <i class="fas fa-save"></i> Salva Configurazione
                </button>
            </form>
        </div>
        
        <!-- Card Sistema Automatico -->
        <div style="background: rgba(16, 185, 129, 0.05); padding: 16px; border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.2);">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="background: linear-gradient(135deg, #43e97b, #38f9d7); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white;">
                    <i class="fas fa-cogs"></i>
                </div>
                <div style="font-weight: 700; font-size: 15px; color: #2d3748;">
                    Sistema<br>Automatico
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 12px; align-items: center;">
                    <span style="color: var(--text-muted);">Cron Settlement</span>
                    <span style="font-weight: 600; color: var(--success);">🟢 Attivo</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px; align-items: center;">
                    <span style="color: var(--text-muted);">Cron Inventory</span>
                    <span style="font-weight: 600; color: var(--success);">⚡ Attivo</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px; align-items: center;">
                    <span style="color: var(--text-muted);">Rate Limit</span>
                    <span style="font-weight: 600; color: var(--success);">🟢 Ottimale</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px; align-items: center;">
                    <span style="color: var(--text-muted);">Performance</span>
                    <span style="font-weight: 600; color: var(--success);">🚀 Eccellente</span>
                </div>
            </div>
        </div>
        
        <!-- Sistema Automatico Footer -->
        <div style="background: linear-gradient(135deg, rgba(67, 233, 123, 0.1), rgba(56, 249, 215, 0.1)); 
                    padding: 16px; 
                    border-radius: 12px; 
                    border: 1px solid rgba(67, 233, 123, 0.3);
                    text-align: center;">
            <div style="font-size: 16px; font-weight: 700; color: #2d3748; margin-bottom: 8px;">
                ✨ Sistema Automatico Attivo ✨
            </div>
            <div style="font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                I tuoi dati vengono sincronizzati automaticamente in background.
            </div>
        </div>
        
    </div>
</div>

</main>

<?php include __DIR__ . '/_partials/mobile_tabbar.php'; ?>

</body>
</html>

