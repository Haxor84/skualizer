<?php
/**
 * Mobile Rendiconto - Versione Mobile della Dashboard Desktop
 * Funzionalità identiche al desktop, ottimizzato per mobile
 */

// Config e Auth
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';

if (!isLoggedIn()) {
    redirect('/modules/margynomic/login/login.php');
}

$currentUser = getCurrentUser();
$userId = $currentUser['user_id'] ?? $currentUser['id'] ?? null;

if (!$userId) {
    die('Errore: User ID non trovato nella sessione.');
}

// Redirect desktop
if (!isMobileDevice()) {
    header('Location: /modules/rendiconto/index.php');
    exit;
}

// Include Cache Helper
require_once __DIR__ . '/helpers/mobile_cache_helper.php';

// Database connection
    try {
        $pdo = getDbConnection();
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

require_once __DIR__ . '/../rendiconto/RendicontoController.php';
$controller = new RendicontoController($pdo, $userId);

// Gestione API Calls (identiche al desktop index.php)
$action = $_GET['action'] ?? $_POST['action'] ?? 'render';

switch ($action) {
    case 'load':
        header('Content-Type: application/json');
        $anno = $_GET['anno'] ?? null;
        $brand = $_GET['brand'] ?? 'PROFUMI YESENSY';
        
        if (!$anno || !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter is required and must be numeric']);
            exit;
        }
        
        $documento = $controller->loadDocumentByYear($anno, $brand);
        
        if ($documento) {
            $totali = $controller->computeYearTotals($documento['righe']);
            $kpi = $controller->computeKpi($totali);
            echo json_encode(['success' => true, 'documento' => $documento, 'totali' => $totali, 'kpi' => $kpi]);
} else {
            echo json_encode(['success' => false, 'error' => 'Documento non trovato per l\'anno ' . $anno]);
        }
        exit;
        
    case 'get_fatturato_settlement':
        header('Content-Type: application/json');
        $anno = $_GET['anno'] ?? null;
        $mese = $_GET['mese'] ?? null;
        
        if (!$anno || !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter is required and must be numeric']);
            exit;
        }
        
        // If mese parameter is provided, get detailed EROGATO transactions for drawer (NO CACHE)
        if ($mese && is_numeric($mese)) {
            // CORRECT: Get EROGATO from rendiconto_input_utente (same as desktop)
            // NOT from report_settlement (those are individual orders, not settlements)
            
            $meseStr = str_pad($mese, 2, '0', STR_PAD_LEFT);
            $dataInizio = "{$anno}-{$meseStr}-01";
            $dataFine = date('Y-m-d', strtotime("{$anno}-{$meseStr}-01 + 1 month - 1 day"));
            
            // Get erogato transactions from rendiconto_input_utente
        $stmt = $pdo->prepare("
            SELECT 
                    data as settlement_date,
                    data,
                    settlement_id,
                    importo_eur,
                    importo as amount,
                    note as descrizione,
                    currency
                FROM rendiconto_input_utente
            WHERE user_id = ?
                  AND anno = ?
                  AND mese = ?
                  AND tipo_input = 'erogato'
                ORDER BY data DESC
            ");
            
            $stmt->execute([$userId, $anno, $mese]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $transactions
            ]);
            exit;
        }
        
        // === CACHE: Settlement data per anno (TTL: 48h) ===
        $cacheKey = "rendiconto_settlement_{$anno}";
        $cachedData = getMobileCache($userId, $cacheKey, 172800); // 48h
        
        if ($cachedData !== null) {
            echo json_encode($cachedData);
            exit;
        }
        
        // Otherwise, get aggregated monthly data
        $result = $controller->getFatturatoFromSettlement($anno);
        
        // Save to cache if successful
        if ($result['success'] ?? false) {
            setMobileCache($userId, $cacheKey, $result);
        }
        
        echo json_encode($result);
        exit;
        
    case 'get_input_utente':
        header('Content-Type: application/json');
        $id = $_GET['id'] ?? null;
        $anno = $_GET['anno'] ?? null;
        $tipoInput = $_GET['tipo_input'] ?? null;
        $mese = $_GET['mese'] ?? null;
        
        if ($anno && !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter must be numeric']);
            exit;
        }
        
        // If specific ID requested, don't use cache (for edit/delete operations)
        if ($id || $mese) {
            $result = $controller->getInputUtente($anno, $tipoInput, $mese, $id);
            echo json_encode($result);
            exit;
        }
        
        // === CACHE: Transazioni per anno (TTL: 48h) ===
        if ($anno) {
            $cacheKey = "rendiconto_transactions_{$anno}";
            $cachedData = getMobileCache($userId, $cacheKey, 172800); // 48h
            
            if ($cachedData !== null) {
                echo json_encode($cachedData);
                exit;
            }
            
            $result = $controller->getInputUtente($anno, $tipoInput, $mese, $id);
            
            // Save to cache if successful
            if ($result['success'] ?? false) {
                setMobileCache($userId, $cacheKey, $result);
            }
            
            echo json_encode($result);
            exit;
        }
        
        // Fallback (no cache)
        $result = $controller->getInputUtente($anno, $tipoInput, $mese, $id);
        echo json_encode($result);
        exit;
        
    case 'save_input_utente':
        header('Content-Type: application/json');
        $input = file_get_contents('php://input');
        $payload = json_decode($input, true);
        
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON payload']);
            exit;
        }
        
        $result = $controller->saveInputUtente($payload);
        
        // === INVALIDATE CACHE: transazioni e settlement per anno modificato ===
        if ($result['success'] ?? false) {
            $annoModificato = $payload['anno'] ?? null;
            if ($annoModificato) {
                invalidateMobileCache($userId, "rendiconto_transactions_{$annoModificato}");
                invalidateMobileCache($userId, "rendiconto_settlement_{$annoModificato}");
                // Invalida anche cache anni (in caso di nuovo anno)
                invalidateMobileCache($userId, "rendiconto_years");
            }
        }
        
        echo json_encode($result);
        exit;
        
    case 'delete_input_utente':
        header('Content-Type: application/json');
        $id = $_GET['id'] ?? $_POST['id'] ?? null;
        
        if (!$id || !is_numeric($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'ID parameter is required']);
            exit;
        }
        
        // Get transaction details before deletion to know which year to invalidate
        try {
            $stmt = $pdo->prepare("SELECT anno FROM rendiconto_input_utente WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            $annoToInvalidate = $transaction['anno'] ?? null;
        } catch (Exception $e) {
            $annoToInvalidate = null;
        }
        
        $result = $controller->deleteInputUtente($id);
        
        // === INVALIDATE CACHE: transazioni e settlement per anno modificato ===
        if (($result['success'] ?? false) && $annoToInvalidate) {
            invalidateMobileCache($userId, "rendiconto_transactions_{$annoToInvalidate}");
            invalidateMobileCache($userId, "rendiconto_settlement_{$annoToInvalidate}");
        }
        
        echo json_encode($result);
        exit;
        
    case 'get_unita_vendute':
        header('Content-Type: application/json');
        $anno = $_GET['anno'] ?? null;
        
        if (!$anno || !is_numeric($anno)) {
            http_response_code(400);
            echo json_encode(['error' => 'Anno parameter is required']);
            exit;
        }
        
        $result = $controller->getUnitaVendute($anno);
        echo json_encode($result);
        exit;
        
    case 'get_available_years':
        header('Content-Type: application/json');
        
        // === CACHE: Lista anni disponibili (TTL: 48h) ===
        $cacheKey = "rendiconto_years";
        $cachedData = getMobileCache($userId, $cacheKey, 172800); // 48h
        
        if ($cachedData !== null) {
            echo json_encode($cachedData);
            exit;
        }
        
        $result = $controller->getAvailableYears();
        
        // Save to cache if successful
        if ($result['success'] ?? false) {
            setMobileCache($userId, $cacheKey, $result);
        }
        
        echo json_encode($result);
        exit;
        
        
    default:
        // Render view
        $anno = $_GET['anno'] ?? date('Y');
        $brand = $_GET['brand'] ?? 'PROFUMI YESENSY';
        
        $data = ['documento' => [], 'righe' => []];
        
        $documento = $controller->loadDocumentByYear($anno, $brand);
        if ($documento) {
            $data = $documento;
        } else {
            // Create empty document structure
            $data = [
                'documento' => [
                    'id' => null,
                    'anno' => $anno,
                    'brand' => $brand,
                    'valuta' => 'EUR'
                ],
                'righe' => []
            ];
            
            for ($mese = 1; $mese <= 12; $mese++) {
                $data['righe'][$mese] = [
                    'id' => null,
                    'documento_id' => null,
                    'mese' => $mese,
                    'data' => null,
                    'entrate_fatturato' => 0,
                    'entrate_unita' => 0,
                    'erogato_importo' => 0,
                    'accantonamento_percentuale' => 0,
                    'accantonamento_euro' => 0,
                    'tasse_euro' => 0,
                    'diversi_euro' => 0,
                    'materia1_euro' => 0,
                    'materia1_unita' => 0,
                    'sped_euro' => 0,
                    'sped_unita' => 0,
                    'varie_euro' => 0,
                    'utile_netto_mese' => 0
                ];
            }
        }
        break;
}

// Render HTML Mobile View
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
    <title>Economics - Skualizer Mobile</title>
    
    <!-- PWA Icons -->
    <link rel="icon" type="image/png" sizes="192x192" href="/modules/mobile/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/modules/mobile/assets/icon-180.png">
    <link rel="manifest" href="/modules/mobile/manifest.json">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/modules/mobile/assets/mobile.css">
    <link rel="stylesheet" href="/modules/mobile/assets/Rendiconto.css">
    <style>
        .hamburger-overlay.active { opacity: 1 !important; visibility: visible !important; }
        .hamburger-overlay.active .hamburger-menu { transform: translateX(0) !important; }
        .hamburger-menu-link:hover { background: #f8fafc !important; border-left-color: #3b82f6 !important; }
    </style>
</head>
<body>
    <?php readfile(__DIR__ . '/assets/icons.svg'); ?>
    
    <!-- Hidden User Context -->
    <input type="hidden" id="user-id" value="<?= $userId ?>">
    <input type="hidden" id="user-nome" value="<?= htmlspecialchars($currentUser['nome'] ?? 'Utente') ?>">
    
    <!-- Messages Container -->
    <div id="messages" style="position: fixed; top: 80px; right: 16px; z-index: 10000; max-width: 400px;"></div>
    
    <!-- Hidden KPI elements for JS compatibility with desktop rendiconto.js -->
    <div style="display: none;">
        <span id="kpi-fatturato-totale">0.00 €</span>
        <span id="kpi-fatturato-per-unita">0.00 €</span>
        <span id="kpi-fatturato-perc-fatt">100.00%</span>
        <span id="kpi-erogato-totale">0.00 €</span>
        <span id="kpi-erogato-per-unita">0.00 €</span>
        <span id="kpi-erogato-perc-fatt">0.00%</span>
        <span id="kpi-erogato-perc-erog">100.00%</span>
        <span id="kpi-accantonamento-totale">0.00 €</span>
        <span id="kpi-accantonamento-per-unita">0.00 €</span>
        <span id="kpi-accantonamento-perc-fatt">0.00%</span>
        <span id="kpi-accantonamento-perc-erog">0.00%</span>
        <span id="kpi-tasse-totale">0.00 €</span>
        <span id="kpi-tasse-per-unita">0.00 €</span>
        <span id="kpi-tasse-perc-fatt">0.00%</span>
        <span id="kpi-tasse-perc-erog">0.00%</span>
        <span id="kpi-fba-totale">0.00 €</span>
        <span id="kpi-fba-per-unita">0.00 €</span>
        <span id="kpi-fba-perc-fatt">0.00%</span>
        <span id="kpi-fba-perc-erog">0.00%</span>
        <span id="kpi-materia1-totale">0.00 €</span>
        <span id="kpi-materia1-per-unita">0.00 €</span>
        <span id="kpi-materia1-perc-fatt">0.00%</span>
        <span id="kpi-materia1-perc-erog">0.00%</span>
        <span id="kpi-sped-totale">0.00 €</span>
        <span id="kpi-sped-per-unita">0.00 €</span>
        <span id="kpi-sped-perc-fatt">0.00%</span>
        <span id="kpi-sped-perc-erog">0.00%</span>
        <span id="kpi-varie-totale">0.00 €</span>
        <span id="kpi-varie-per-unita">0.00 €</span>
        <span id="kpi-varie-perc-fatt">0.00%</span>
        <span id="kpi-varie-perc-erog">0.00%</span>
        <span id="kpi-utile-lordo-totale">0.00 €</span>
        <span id="kpi-utile-lordo-per-unita">0.00 €</span>
        <span id="kpi-utile-lordo-perc-fatt">0.00%</span>
        <span id="kpi-utile-lordo-perc-erog">0.00%</span>
        <span id="hidden-unita-acquistate">0</span>
        <span id="hidden-unita-spedite">0</span>
        <span id="hidden-unita-vendute">0</span>
        <span id="tax-plafond-table">0.00 €</span>
        <span id="utile-netto-atteso">0.00 €</span>
    </div>
    
    <!-- Hamburger Menu Overlay -->
    <div class="hamburger-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s;">
        <nav class="hamburger-menu" style="position: absolute; top: 0; right: 0; width: 80%; max-width: 320px; height: 100%; background: white; transform: translateX(100%); transition: transform 0.3s; box-shadow: -4px 0 24px rgba(0,0,0,0.15);">
            <div class="hamburger-menu-header" style="background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%); padding: 24px 20px; color: white;">
                <div class="hamburger-menu-title" style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Menu</div>
                <div style="font-size: 12px; opacity: 0.9;">Navigazione rapida</div>
            </div>
            <div class="hamburger-menu-nav" style="padding: 12px 0;">
                <a href="/modules/mobile/Margynomic.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-chart-line" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>Margynomic</span>
                </a>
                <a href="/modules/mobile/Previsync.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-boxes" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>PreviSync</span>
                </a>
                <a href="/modules/mobile/OrderInsights.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-microscope" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>OrderInsight</span>
                </a>
                <a href="/modules/mobile/TridScanner.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-search" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>TridScanner</span>
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid #3b82f6; background: #dbeafe;">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>Economics</span>
                </a>
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-truck" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>EasyShip</span>
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-user" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>Profilo</span>
                </a>
                <div style="height: 1px; background: #e2e8f0; margin: 12px 20px;"></div>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #3b82f6; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-sign-out-alt" style="font-size: 20px; color: #3b82f6; width: 24px; text-align: center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <main class="mobile-content">
        <!-- Hero Header -->
<div class="hero-welcome">
    <div class="hero-header">
        <div class="hero-logo">
            <div class="hero-title"><i class="fas fa-file-invoice-dollar"></i> Economics</div>
                    <div class="hero-subtitle">ANALISI CONTABILE DEL TUO BUSINESS!</div>
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
                <div class="info-box-title">📝 Transazioni</div>
                <div class="info-box-text">Inserisci manualmente costi per materie prime, spedizioni, tasse e spese varie con data e note</div>
    </div>
    <div class="info-box">
                <div class="info-box-title">💰 Sync Amazon</div>
                <div class="info-box-text">Importa automaticamente fatturato, erogato e unità vendute dai report Amazon</div>
    </div>
    <div class="info-box">
                <div class="info-box-title">📊 Margini</div>
                <div class="info-box-text">Monitora utile netto, tax plafond, commissioni FBA calcolati per unità</div>
    </div>
    <div class="info-box">
                <div class="info-box-title">📈 Multi-Anno</div>
                <div class="info-box-text">Confronta performance economiche di più anni per analizzare trend e variazioni</div>
    </div>
</div>
</div>
        
        <!-- Year Selector (hidden, used by JS) -->
        <div class="year-selector" style="display: none;">
            <select id="anno" name="anno">
                <?php 
                $currentYear = date('Y');
                $selectedYear = $data['documento']['anno'] ?? $currentYear;
                for ($year = 2020; $year <= ($currentYear + 2); $year++): 
                ?>
                    <option value="<?= $year ?>" <?= $selectedYear == $year ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endfor; ?>
        </select>
</div>

        <!-- KPI Cards Mobile -->
        <div class="kpi-container">
            <!-- CARD 1: FLUSSO PRINCIPALE -->
            <div class="kpi-card kpi-card-primary">
                <div class="kpi-card-header" style="text-align: center;">
                    <div class="kpi-card-title">💰 Flusso Principale</div>
                    <div class="kpi-card-subtitle">Revenue & Profit</div>
    </div>
    
                <div class="kpi-row">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">💵 Fatturato</div>
                        <div class="kpi-value" id="flow-fatturato-totale">0.00 €</div>
                        <div class="kpi-detail">
                            <span class="kpi-detail-label">€/U</span>
                            <span class="kpi-detail-value" id="flow-fatturato-per-unita">0.00</span>
                        </div>
                    </div>
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">📤 Erogato</div>
                        <div class="kpi-value" id="flow-erogato-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-erogato-perc-fatt">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-erogato-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
    </div>
    
                <div class="kpi-row-single">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">📦 FBA</div>
                        <div class="kpi-value" id="flow-fba-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-fba-perc-fatt">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%E</span>
                                <span class="kpi-detail-value" id="flow-fba-perc-erog">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-fba-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
    </div>
    
                <div class="kpi-row-single">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">✨ Utile Netto</div>
                        <div class="kpi-value" id="flow-utile-netto-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-utile-netto-perc-fatt">0%</span>
        </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%E</span>
                                <span class="kpi-detail-value" id="flow-utile-netto-perc-erog">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-utile-netto-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
    </div>
</div>

            <!-- CARD 2: COSTI OPERATIVI -->
            <div class="kpi-card kpi-card-warning">
                <div class="kpi-card-header" style="text-align: center;">
                    <div class="kpi-card-title">⚙️ Costi Operativi</div>
                    <div class="kpi-card-subtitle">Operational Expenses</div>
                </div>
                
                <div class="kpi-row-single">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">🧪 Materia Prima</div>
                        <div class="kpi-value" id="flow-materia1-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-materia1-perc-fatt">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%E</span>
                                <span class="kpi-detail-value" id="flow-materia1-perc-erog">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-materia1-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="kpi-row-single">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">🚚 Spedizioni</div>
                        <div class="kpi-value" id="flow-sped-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-sped-perc-fatt">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%E</span>
                                <span class="kpi-detail-value" id="flow-sped-perc-erog">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-sped-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kpi-row-single">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">📋 Varie</div>
                        <div class="kpi-value" id="flow-varie-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-varie-perc-fatt">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%E</span>
                                <span class="kpi-detail-value" id="flow-varie-perc-erog">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-varie-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 3: AREA FISCALE -->
            <div class="kpi-card kpi-card-success">
                <div class="kpi-card-header" style="text-align: center;">
                    <div class="kpi-card-title">🏛️ Area Fiscale</div>
                    <div class="kpi-card-subtitle">Tax & Reserves</div>
                </div>
                
                <div class="kpi-row-single">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">💼 Accantonato</div>
                        <div class="kpi-value" id="flow-accantonamento-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-accantonamento-perc-fatt">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%E</span>
                                <span class="kpi-detail-value" id="flow-accantonamento-perc-erog">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-accantonamento-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="kpi-row-single">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label">🏛️ Tasse</div>
                        <div class="kpi-value" id="flow-tasse-totale">0.00 €</div>
                        <div class="kpi-detail-row" style="justify-content: center;">
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%F</span>
                                <span class="kpi-detail-value" id="flow-tasse-perc-fatt">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">%E</span>
                                <span class="kpi-detail-value" id="flow-tasse-perc-erog">0%</span>
                            </div>
                            <div class="kpi-detail">
                                <span class="kpi-detail-label">€/U</span>
                                <span class="kpi-detail-value" id="flow-tasse-per-unita">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="kpi-row-single kpi-highlight" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 12px; padding: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <div class="kpi-item" style="text-align: center;">
                        <div class="kpi-label" style="color: white; font-weight: 600;">💎 Tax Plafond</div>
                        <div class="kpi-value" id="tax-plafond" style="color: white; font-weight: 800;">0.00 €</div>
                        <div class="kpi-detail-row" style="display: flex; justify-content: center;">
                            <div class="kpi-detail" style="background: rgba(255,255,255,0.2); padding: 0.5rem 1rem; border-radius: 6px; text-align: center; min-width: 120px;">
                                <span class="kpi-detail-label" style="color: rgba(255,255,255,0.8); font-size: 0.7rem; font-weight: 600;">€/UNITÀ</span>
                                <span class="kpi-detail-value" id="tax-plafond-per-unit" style="color: white; font-weight: 700; display: block; margin-top: 0.25rem;">0.00 €</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Units Summary -->
        <div class="units-summary">
            <div class="unit-box unit-box-primary">
                <div class="unit-label">🛒 Acquist.</div>
                <div class="unit-value" id="unita-acquistate">0</div>
            </div>
            <div class="unit-box unit-box-success">
                <div class="unit-label">📦 Spedite</div>
                <div class="unit-value" id="unita-spedite">0</div>
            </div>
            <div class="unit-box unit-box-warning">
                <div class="unit-label">✅ Vendute</div>
                <div class="unit-value" id="unita-vendute">0</div>
            </div>
        </div>

        <!-- Transaction Form (Mobile Optimized) -->
        <div class="transaction-form-mobile">
            <div class="form-mobile-header">
                <h3>📝 Inserisci Transazione</h3>
            </div>
            <div id="transaction-form" class="form-mobile">
                <div class="form-mobile-field">
                    <label class="form-mobile-label">📊 Tipo</label>
                    <select id="trans-tipo" class="form-mobile-input" required>
                        <option value="">Seleziona...</option>
                        <optgroup label="💸 Uscite">
                            <option value="accantonamento_euro">💼 Accantonato</option>
                            <option value="tasse_pagamento">🏛️ Tasse</option>
                            <option value="materia_prima_acquisto">🧪 Materia Prima</option>
                            <option value="spedizioni_acquisto">🚚 Spedizione</option>
                            <option value="spese_varie">📋 Varie</option>
                        </optgroup>
                    </select>
                </div>
                
                <div class="form-mobile-field">
                    <label class="form-mobile-label">📅 Data</label>
                    <input type="date" id="trans-data" class="form-mobile-input" required>
                </div>
                
                <div id="pagamento-ref-group" class="form-mobile-field" style="display: none;">
                    <label class="form-mobile-label">💰 Pagamento</label>
                    <select id="trans-pagamento-ref" class="form-mobile-input">
                        <option value="">Seleziona...</option>
                    </select>
                </div>
                
                <div id="percentuale-group" class="form-mobile-field" style="display: none;">
                    <label class="form-mobile-label">📊 %</label>
                    <select id="trans-percentuale" class="form-mobile-input">
                        <option value="">Scegli...</option>
                        <option value="5">5%</option>
                        <option value="10">10%</option>
                        <option value="15">15%</option>
                        <option value="20">20%</option>
                        <option value="25">25%</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                
                <div id="percentuale-custom-group" class="form-mobile-field" style="display: none;">
                    <label class="form-mobile-label">% Custom</label>
                    <input type="number" id="trans-percentuale-custom" class="form-mobile-input" 
                           step="0.01" min="0" max="100" placeholder="0.00">
                </div>
                
                <div id="importo-group" class="form-mobile-field" style="display: none;">
                    <label class="form-mobile-label">💵 Importo</label>
                    <input type="number" id="trans-importo" class="form-mobile-input" 
                           step="0.01" placeholder="0.00">
                </div>
                
                <div id="quantita-group" class="form-mobile-field" style="display: none;">
                    <label class="form-mobile-label">📦 Unità</label>
                    <input type="number" id="trans-quantita" class="form-mobile-input" 
                           step="1" placeholder="0">
                </div>

                <div class="form-mobile-field">
                    <label class="form-mobile-label">📝 Nota</label>
                    <textarea id="trans-note" class="form-mobile-textarea" 
                              rows="2" placeholder="Descrizione..."></textarea>
                </div>
                
                <button type="button" id="btn-save-trans" class="btn-save-mobile">
                    💾 Salva
                </button>
            </div>
        </div>
        
        <!-- Rendiconto Tables Container (Multi-Year) -->
        <div id="rendiconto-tables-container">
            <!-- First table (current year) -->
            <div class="table-container" data-year="<?= $data['documento']['anno'] ?? date('Y') ?>">
                <div class="table-title">📊 Economics <?= $data['documento']['anno'] ?? date('Y') ?></div>
                <div class="table-scroll">
                    <table class="rendiconto-table">
            <thead>
                <tr>
                            <td class="excel-col-header">#</td>
                            <td class="excel-col-header">A</td>
                            <td class="excel-col-header">B</td>
                            <td class="excel-col-header">C</td>
                            <td class="excel-col-header">D</td>
                            <td class="excel-col-header">E</td>
                            <td class="excel-col-header">F</td>
                            <td class="excel-col-header">G</td>
                            <td class="excel-col-header">H</td>
                            <td class="excel-col-header">I</td>
                            <td class="excel-col-header">J</td>
                            <td class="excel-col-header">K</td>
                            <td class="excel-col-header">L</td>
                            <td class="excel-col-header">M</td>
                            <td class="excel-col-header">N</td>
                        </tr>
                        <tr>
                            <th class="excel-col-header">#</th>
                    <th>Mese</th>
                            <th>Fatturato</th>
                            <th>U.</th>
                            <th>Erogato</th>
                            <th>%</th>
                            <th>Accant.</th>
                            <th>Tasse</th>
                            <th>Materia</th>
                            <th>U.</th>
                            <th>Sped.</th>
                            <th>U.</th>
                            <th>Varie</th>
                            <th>Utile</th>
                            <th>%</th>
                </tr>
            </thead>
            <tbody>
                        <?php
                        $mesiNomi = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
                        $rowNumber = 1;
                        for ($mese = 12; $mese >= 1; $mese--):
                        ?>
                        <tr data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>">
                            <td class="excel-row-number"><?= $rowNumber++ ?></td>
                            <td class="month"><?= $mesiNomi[$mese-1] ?></td>
                            <td class="right cell-readonly clickable" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="entrate_fatturato">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="center cell-readonly" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="entrate_unita">
                                <span class="cell-value">0</span>
                            </td>
                            <td class="right cell-readonly clickable" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="erogato_importo">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="right cell-readonly" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="accantonamento_percentuale">
                                <span class="cell-value">0%</span>
                            </td>
                            <td class="right cell-readonly clickable" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="accantonamento_euro">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="right cell-readonly clickable" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="tasse_euro">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="right cell-readonly clickable" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="materia1_euro">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="center cell-readonly" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="materia1_unita">
                                <span class="cell-value">0</span>
                            </td>
                            <td class="right cell-readonly clickable" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="sped_euro">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="center cell-readonly" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="sped_unita">
                                <span class="cell-value">0</span>
                            </td>
                            <td class="right cell-readonly clickable" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="varie_euro">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="right cell-readonly" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="utile_euro">
                                <span class="cell-value">0.00 €</span>
                            </td>
                            <td class="right cell-readonly" data-mese="<?= $mese ?>" data-anno="<?= $data['documento']['anno'] ?? date('Y') ?>" data-field="utile_percentuale">
                                <span class="cell-value">0%</span>
                            </td>
                        </tr>
                        <?php endfor; ?>
            </tbody>
                    <tfoot>
                        <tr>
                            <td class="excel-row-number">13</td>
                            <td>TOT / U</td>
                            <td id="total-avg-fatturato">0.00 € / 0.00 €</td>
                            <td id="total-unita">0</td>
                            <td id="total-avg-erogato">0.00 € / 0.00 €</td>
                            <td id="total-percent-accant">0%</td>
                            <td id="total-avg-accant">0.00 € / 0.00 €</td>
                            <td id="total-avg-tax">0.00 € / 0.00 €</td>
                            <td id="total-avg-materia1">0.00 € / 0.00 €</td>
                            <td id="total-materia1-unita">0</td>
                            <td id="total-avg-sped">0.00 € / 0.00 €</td>
                            <td id="total-sped-unita">0</td>
                            <td id="total-avg-varie">0.00 € / 0.00 €</td>
                            <td id="total-avg-utile">0.00 € / 0.00 €</td>
                            <td id="total-avg-utile-perc">0%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <!-- Additional year tables will be inserted here by JavaScript -->
        </div>
    </main>

    <?php include __DIR__ . '/_partials/mobile_tabbar.php'; ?>
    
    <!-- Drawer Bottom Sheet (identico a TridScanner) -->
    <div class="drawer-overlay" id="drawer-overlay" onclick="closeDrawer()"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-handle"></div>
        <div class="drawer-header">
            <div class="drawer-title" id="drawer-title">Transazioni</div>
            <div class="drawer-subtitle" id="drawer-subtitle"></div>
        </div>
        <div class="drawer-content" id="drawer-content">
            <div class="loading-spinner">Caricamento...</div>
        </div>
    </div>
    
    <!-- Toast Message -->
    <div class="toast" id="toast"></div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading">
        <div class="spinner"></div>
</div>

    <!-- Include shared JavaScript from desktop -->
    <script src="/modules/rendiconto/assets/rendiconto.js"></script>
    <script>
    
    // Initialize Rendiconto App
    document.addEventListener('DOMContentLoaded', async () => {
        // Initialize with multi-year support
        await initMultiYearRendiconto();
        
        // Sync hidden KPI to visible cards (observer pattern) - Make it global
        window.syncKPI = () => {
            const copyValue = (sourceId, targetId) => {
                const source = document.getElementById(sourceId);
                const target = document.getElementById(targetId);
                if (source && target && source.textContent.trim() !== '') {
                    target.textContent = source.textContent;
                }
            };
            
            // === FLUSSO PRINCIPALE ===
            copyValue('kpi-fatturato-totale', 'flow-fatturato-totale');
            copyValue('kpi-fatturato-per-unita', 'flow-fatturato-per-unita');
            
            copyValue('kpi-erogato-totale', 'flow-erogato-totale');
            copyValue('kpi-erogato-per-unita', 'flow-erogato-per-unita');
            copyValue('kpi-erogato-perc-fatt', 'flow-erogato-perc-fatt');
            
            copyValue('kpi-fba-totale', 'flow-fba-totale');
            copyValue('kpi-fba-per-unita', 'flow-fba-per-unita');
            copyValue('kpi-fba-perc-fatt', 'flow-fba-perc-fatt');
            copyValue('kpi-fba-perc-erog', 'flow-fba-perc-erog');
            
            // === UTILE NETTO (usa kpi-utile-lordo-* perché è aggiornato da updateGlobalKPIRow) ===
            copyValue('kpi-utile-lordo-totale', 'flow-utile-netto-totale');
            copyValue('kpi-utile-lordo-per-unita', 'flow-utile-netto-per-unita');
            copyValue('kpi-utile-lordo-perc-fatt', 'flow-utile-netto-perc-fatt');
            copyValue('kpi-utile-lordo-perc-erog', 'flow-utile-netto-perc-erog');
            
            // === COSTI OPERATIVI ===
            copyValue('kpi-materia1-totale', 'flow-materia1-totale');
            copyValue('kpi-materia1-per-unita', 'flow-materia1-per-unita');
            copyValue('kpi-materia1-perc-fatt', 'flow-materia1-perc-fatt');
            copyValue('kpi-materia1-perc-erog', 'flow-materia1-perc-erog');
            
            copyValue('kpi-sped-totale', 'flow-sped-totale');
            copyValue('kpi-sped-per-unita', 'flow-sped-per-unita');
            copyValue('kpi-sped-perc-fatt', 'flow-sped-perc-fatt');
            copyValue('kpi-sped-perc-erog', 'flow-sped-perc-erog');
            
            copyValue('kpi-varie-totale', 'flow-varie-totale');
            copyValue('kpi-varie-per-unita', 'flow-varie-per-unita');
            copyValue('kpi-varie-perc-fatt', 'flow-varie-perc-fatt');
            copyValue('kpi-varie-perc-erog', 'flow-varie-perc-erog');
            
            // === AREA FISCALE ===
            copyValue('kpi-accantonamento-totale', 'flow-accantonamento-totale');
            copyValue('kpi-accantonamento-per-unita', 'flow-accantonamento-per-unita');
            copyValue('kpi-accantonamento-perc-fatt', 'flow-accantonamento-perc-fatt');
            copyValue('kpi-accantonamento-perc-erog', 'flow-accantonamento-perc-erog');
            
            copyValue('kpi-tasse-totale', 'flow-tasse-totale');
            copyValue('kpi-tasse-per-unita', 'flow-tasse-per-unita');
            copyValue('kpi-tasse-perc-fatt', 'flow-tasse-perc-fatt');
            copyValue('kpi-tasse-perc-erog', 'flow-tasse-perc-erog');
            
            // Tax Plafond
            copyValue('tax-plafond-table', 'tax-plafond');
            
            // Tax Plafond per unit - Calculate as: Tax Plafond / Unità Vendute (NOT accantonamento per unit)
            const taxPlafondTotal = document.getElementById('tax-plafond-table');
            const unitaVendute = document.getElementById('hidden-unita-vendute');
            const taxPlafondPerUnitTarget = document.getElementById('tax-plafond-per-unit');
            if (taxPlafondTotal && unitaVendute && taxPlafondPerUnitTarget) {
                const taxPlafondValue = parseFloat(taxPlafondTotal.textContent.replace(/[€\s]/g, '').replace(',', '.')) || 0;
                const unitaValue = parseInt(unitaVendute.textContent) || 1;
                const perUnit = taxPlafondValue / unitaValue;
                taxPlafondPerUnitTarget.textContent = perUnit.toFixed(2) + ' €';
            }
            
            // Apply negative value styles to KPI cards (Desktop Parity)
            applyNegativeValueStyles();
            
            // Units Summary - Copy from hidden spans to visible cards
            const unitsAcq = document.getElementById('hidden-unita-acquistate')?.textContent;
            const unitsSpd = document.getElementById('hidden-unita-spedite')?.textContent;
            const unitsVnd = document.getElementById('hidden-unita-vendute')?.textContent;
            
            copyValue('hidden-unita-acquistate', 'unita-acquistate');
            copyValue('hidden-unita-spedite', 'unita-spedite');
            copyValue('hidden-unita-vendute', 'unita-vendute');
            
        };
        
        // Sync immediatamente
        window.syncKPI();
        
        // Observer per intercettare aggiornamenti DOM
        const observer = new MutationObserver(syncKPI);
        const hiddenContainer = document.querySelector('div[style*="display: none"]');
        if (hiddenContainer) {
            observer.observe(hiddenContainer, {
                childList: true,
                subtree: true,
                characterData: true,
                characterDataOldValue: true
            });
        }
        
        // Fallback: sync ogni 500ms and re-apply negative styles
        setInterval(() => {
            window.syncKPI();
            applyNegativeValueStyles();
        }, 500);
        
        // Mobile-specific event handlers
        
        // Hamburger Menu
        document.querySelector('.hamburger-btn-hero').addEventListener('click', () => {
            document.querySelector('.hamburger-overlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        });
        
        document.querySelector('.hamburger-overlay').addEventListener('click', (e) => {
            if (e.target.classList.contains('hamburger-overlay')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
        
        // Bind click events to clickable cells (delegate for dynamic tables)
        document.addEventListener('click', function(e) {
            const cell = e.target.closest('.cell-readonly.clickable');
            if (cell) {
                e.preventDefault();
                e.stopPropagation();
                openTransactionDrawer(cell);
            }
        });
        
        // Override desktop tooltip behavior for mobile
        if (window.rendicontoApp) {
            window.rendicontoApp.showCellTooltip = function(cell, event) {
                openTransactionDrawer(cell);
            };
            
            window.rendicontoApp.hideCellTooltip = function() {
                closeDrawer();
            };
        }
    });
    
    // Refresh data silently after transaction changes
    async function refreshYearData(anno) {
        if (!anno || !window.rendicontoApp) {
            return;
        }
        
        try {
            // Reset cells for this year to avoid duplications
            const cellsToReset = document.querySelectorAll(`[data-anno="${anno}"][data-field]`);
            if (cellsToReset.length === 0) {
                return; // Table for this year doesn't exist in DOM
            }
            
            cellsToReset.forEach(cell => {
                const valueSpan = cell.querySelector('.cell-value');
                if (valueSpan && cell.dataset.field !== 'entrate_fatturato' && 
                    cell.dataset.field !== 'entrate_unita' && 
                    cell.dataset.field !== 'erogato_importo') {
                    // Reset only non-settlement fields (they will be reloaded from transactions)
                    const isUnitField = cell.dataset.field.includes('_unita');
                    valueSpan.textContent = isUnitField ? '0' : '0.00 €';
                }
            });
            
            // Reload transactions
            if (typeof window.rendicontoApp.populateCellsFromTransactions === 'function') {
                await window.rendicontoApp.populateCellsFromTransactions(anno);
            }
            
            // Reload settlement data
            if (typeof window.rendicontoApp.loadFatturatoFromSettlement === 'function') {
                await window.rendicontoApp.loadFatturatoFromSettlement(anno);
            }
            
            // Update totals for this year
            if (typeof updateYearTotals === 'function') {
                updateYearTotals(anno, anno === new Date().getFullYear());
            }
            
            // Update global KPIs
            if (typeof window.rendicontoApp.updateGlobalKPIRow === 'function') {
                window.rendicontoApp.updateGlobalKPIRow();
            }
            
            // Apply negative value styles
            if (typeof applyNegativeValueStyles === 'function') {
                applyNegativeValueStyles();
            }
        } catch (error) {
            console.error('Error refreshing data:', error);
        }
    }
    
    // Initialize Multi-Year Rendiconto (Mobile Version)
    async function initMultiYearRendiconto() {
        // Step 1: Initialize RendicontoApp
        window.rendicontoApp = new RendicontoApp();
        
        // Initialize data structure manually
        if (!window.rendicontoApp.data) {
            window.rendicontoApp.data = {
                documento: { anno: new Date().getFullYear() },
                righe: {}
            };
        }
        
        // Step 1.2: Bind form events (CRITICAL for mobile)
        try {
            window.rendicontoApp.initUserContext();
            window.rendicontoApp.bindEvents();
        } catch (error) {
            console.error('Error binding events:', error);
        }
        
        // Step 1.5: Override functions for multi-year support
        overrideLoadFatturatoForMultiYear();
        overridePopulateCellsForMultiYear();
        overrideUpdateGlobalKPIForMultiYear();
        
        // Step 2: Get available years
        const response = await fetch('?action=get_available_years');
        const result = await response.json();
        
        if (!result.success || !result.years || result.years.length === 0) {
            console.error('No years available from API');
            return;
        }
        
        const years = result.years.sort((a, b) => b - a); // Newest first
        const currentYear = years[0];
        
        const container = document.getElementById('rendiconto-tables-container');
        if (!container) {
            console.error('rendiconto-tables-container NOT FOUND in DOM!');
            return;
        }
        
        // Step 3: Get first table year (server-side rendered)
        const firstTableYear = parseInt(document.querySelector('.table-container[data-year]')?.dataset.year);
        
        // Step 3.5: Generate tables for OTHER years (excluding first table year)
        for (const year of years) {
            if (year !== firstTableYear) {
                const tableHTML = generateYearTable(year);
                container.insertAdjacentHTML('beforeend', tableHTML);
            }
        }
        
        // Step 4: Load data for ALL years
        for (const year of years) {
            try {
                await window.rendicontoApp.populateCellsFromTransactions(year);
            } catch (txError) {
                console.error(`Error loading transactions for ${year}:`, txError);
            }
            
            await window.rendicontoApp.loadFatturatoFromSettlement(year);
            // isCurrentYear = true SOLO se year corrisponde alla tabella server-side
            updateYearTotals(year, year === firstTableYear);
        }
        
        // Step 5: Update global KPI (sum of all years)
        window.rendicontoApp.updateGlobalKPIRow();
        
        // Step 6: Setup transaction refresh (AFTER everything is initialized)
        setupTransactionRefresh();
    }
    
    // Generate HTML table for a specific year
    function generateYearTable(year) {
        const mesiNomi = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
        const suffix = `-${year}`;
        
        let rows = '';
        let rowNumber = 1;
        for (let mese = 12; mese >= 1; mese--) {
            rows += `
                <tr data-mese="${mese}" data-anno="${year}">
                    <td class="excel-row-number">${rowNumber++}</td>
                    <td class="month">${mesiNomi[mese-1]}</td>
                    <td class="right cell-readonly clickable" data-mese="${mese}" data-anno="${year}" data-field="entrate_fatturato">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="center cell-readonly" data-mese="${mese}" data-anno="${year}" data-field="entrate_unita">
                        <span class="cell-value">0</span>
                    </td>
                    <td class="right cell-readonly clickable" data-mese="${mese}" data-anno="${year}" data-field="erogato_importo">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="right cell-readonly" data-mese="${mese}" data-anno="${year}" data-field="accantonamento_percentuale">
                        <span class="cell-value">0%</span>
                    </td>
                    <td class="right cell-readonly clickable" data-mese="${mese}" data-anno="${year}" data-field="accantonamento_euro">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="right cell-readonly clickable" data-mese="${mese}" data-anno="${year}" data-field="tasse_euro">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="right cell-readonly clickable" data-mese="${mese}" data-anno="${year}" data-field="materia1_euro">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="center cell-readonly" data-mese="${mese}" data-anno="${year}" data-field="materia1_unita">
                        <span class="cell-value">0</span>
                    </td>
                    <td class="right cell-readonly clickable" data-mese="${mese}" data-anno="${year}" data-field="sped_euro">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="center cell-readonly" data-mese="${mese}" data-anno="${year}" data-field="sped_unita">
                        <span class="cell-value">0</span>
                    </td>
                    <td class="right cell-readonly clickable" data-mese="${mese}" data-anno="${year}" data-field="varie_euro">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="right cell-readonly" data-mese="${mese}" data-anno="${year}" data-field="utile_euro">
                        <span class="cell-value">0.00 €</span>
                    </td>
                    <td class="right cell-readonly" data-mese="${mese}" data-anno="${year}" data-field="utile_percentuale">
                        <span class="cell-value">0%</span>
                    </td>
                </tr>
            `;
        }
        
        return `
            <div class="table-container" data-year="${year}">
                <div class="table-title">📊 Economics ${year}</div>
                <div class="table-scroll">
                    <table class="rendiconto-table">
                        <thead>
                            <tr>
                                <td class="excel-col-header">#</td>
                                <td class="excel-col-header">A</td>
                                <td class="excel-col-header">B</td>
                                <td class="excel-col-header">C</td>
                                <td class="excel-col-header">D</td>
                                <td class="excel-col-header">E</td>
                                <td class="excel-col-header">F</td>
                                <td class="excel-col-header">G</td>
                                <td class="excel-col-header">H</td>
                                <td class="excel-col-header">I</td>
                                <td class="excel-col-header">J</td>
                                <td class="excel-col-header">K</td>
                                <td class="excel-col-header">L</td>
                                <td class="excel-col-header">M</td>
                                <td class="excel-col-header">N</td>
                            </tr>
                            <tr>
                                <th class="excel-col-header">#</th>
                                <th>Mese</th>
                                <th>Fatturato</th>
                                <th>U.</th>
                                <th>Erogato</th>
                                <th>%</th>
                                <th>Accant.</th>
                                <th>Tasse</th>
                                <th>Materia</th>
                                <th>U.</th>
                                <th>Sped.</th>
                                <th>U.</th>
                                <th>Varie</th>
                                <th>Utile</th>
                                <th>%</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows}
            </tbody>
                        <tfoot>
                            <tr>
                                <td class="excel-row-number">13</td>
                                <td>TOT / U</td>
                                <td id="total-avg-fatturato${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-unita${suffix}">0</td>
                                <td id="total-avg-erogato${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-percent-accant${suffix}">0%</td>
                                <td id="total-avg-accant${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-avg-tax${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-avg-materia1${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-materia1-unita${suffix}">0</td>
                                <td id="total-avg-sped${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-sped-unita${suffix}">0</td>
                                <td id="total-avg-varie${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-avg-utile${suffix}">0.00 € / 0.00 €</td>
                                <td id="total-avg-utile-perc${suffix}">0%</td>
                            </tr>
                        </tfoot>
        </table>
        </div>
</div>
        `;
    }
    
    // Update totals row for a specific year
    function updateYearTotals(year, isCurrentYear = false) {
        const suffix = isCurrentYear ? '' : `-${year}`;
        const cells = document.querySelectorAll(`[data-anno="${year}"][data-field]`);
        
        const totals = {
            fatturato: 0, unita: 0, erogato: 0, accant: 0,
            tasse: 0, materia1: 0, materia1_unita: 0,
            sped: 0, sped_unita: 0, varie: 0
        };
        
        cells.forEach(cell => {
            const field = cell.dataset.field;
            const valueSpan = cell.querySelector('.cell-value');
            if (!valueSpan) return;
            
            const text = valueSpan.textContent;
            const value = parseFloat(text.replace(/[€\s]/g, '').replace(',', '.')) || 0;
            
            if (field === 'entrate_fatturato') totals.fatturato += value;
            else if (field === 'entrate_unita') totals.unita += parseInt(text) || 0;
            else if (field === 'erogato_importo') totals.erogato += value;
            else if (field === 'accantonamento_euro') totals.accant += value;
            else if (field === 'tasse_euro') totals.tasse += value;
            else if (field === 'materia1_euro') totals.materia1 += value;
            else if (field === 'materia1_unita') totals.materia1_unita += parseInt(text) || 0;
            else if (field === 'sped_euro') totals.sped += value;
            else if (field === 'sped_unita') totals.sped_unita += parseInt(text) || 0;
            else if (field === 'varie_euro') totals.varie += value;
        });
        
        const fmt = (n) => n.toFixed(2);
        const perUnit = (tot, units) => units > 0 ? (tot / units).toFixed(2) : '0.00';
        
        const el = (id) => document.getElementById(id);
        if (el(`total-avg-fatturato${suffix}`)) el(`total-avg-fatturato${suffix}`).textContent = `${fmt(totals.fatturato)} € / ${perUnit(totals.fatturato, totals.unita)} €`;
        if (el(`total-unita${suffix}`)) el(`total-unita${suffix}`).textContent = totals.unita;
        if (el(`total-avg-erogato${suffix}`)) el(`total-avg-erogato${suffix}`).textContent = `${fmt(totals.erogato)} € / ${perUnit(totals.erogato, totals.unita)} €`;
        // Colonna E (%) - Accantonamento / Erogato × 100 (DESKTOP PARITY)
        if (el(`total-percent-accant${suffix}`)) el(`total-percent-accant${suffix}`).textContent = totals.erogato > 0 ? `${((totals.accant / totals.erogato) * 100).toFixed(2)}%` : '0.00%';
        if (el(`total-avg-accant${suffix}`)) el(`total-avg-accant${suffix}`).textContent = `${fmt(totals.accant)} € / ${perUnit(totals.accant, totals.unita)} €`;
        if (el(`total-avg-tax${suffix}`)) el(`total-avg-tax${suffix}`).textContent = `${fmt(totals.tasse)} € / ${perUnit(totals.tasse, totals.unita)} €`;
        if (el(`total-avg-materia1${suffix}`)) el(`total-avg-materia1${suffix}`).textContent = `${fmt(totals.materia1)} € / ${perUnit(totals.materia1, totals.unita)} €`;
        if (el(`total-materia1-unita${suffix}`)) el(`total-materia1-unita${suffix}`).textContent = totals.materia1_unita;
        if (el(`total-avg-sped${suffix}`)) el(`total-avg-sped${suffix}`).textContent = `${fmt(totals.sped)} € / ${perUnit(totals.sped, totals.unita)} €`;
        if (el(`total-sped-unita${suffix}`)) el(`total-sped-unita${suffix}`).textContent = totals.sped_unita;
        if (el(`total-avg-varie${suffix}`)) el(`total-avg-varie${suffix}`).textContent = `${fmt(totals.varie)} € / ${perUnit(totals.varie, totals.unita)} €`;
        
        // Calculate utile (profit) columns - DESKTOP PARITY
        // Read unit values from row 21 (format "total / unit") - same as desktop
        const getUnitValue = (text) => {
            if (!text || !text.includes('/')) return 0;
            const parts = text.split('/');
            if (parts.length < 2) return 0;
            // Parse the part after "/" removing € and converting , to .
            return parseFloat(parts[1].trim().replace(/[€\s]/g, '').replace(',', '.')) || 0;
        };
        
        const erogatoUnitEl = el(`total-avg-erogato${suffix}`);
        const taxUnitEl = el(`total-avg-tax${suffix}`);
        const materia1UnitEl = el(`total-avg-materia1${suffix}`);
        const spedUnitEl = el(`total-avg-sped${suffix}`);
        const varieUnitEl = el(`total-avg-varie${suffix}`);
        
        const erogatoUnit = getUnitValue(erogatoUnitEl?.textContent || '0');
        const taxUnit = getUnitValue(taxUnitEl?.textContent || '0');
        const materia1Unit = getUnitValue(materia1UnitEl?.textContent || '0');
        const spedUnit = getUnitValue(spedUnitEl?.textContent || '0');
        const varieUnit = getUnitValue(varieUnitEl?.textContent || '0');
        
        // IMPORTANT: tax, materia1, sped, varie are ALREADY NEGATIVE in row 21
        // So we ADD them (not subtract) - same as desktop logic
        const utileUnit = erogatoUnit + taxUnit + materia1Unit + spedUnit + varieUnit;
        let sommaUtile = 0;
        
        for (let mese = 1; mese <= 12; mese++) {
            const unitaCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_unita"][data-anno="${year}"] .cell-value`);
            const unitaMese = parseInt(unitaCell?.textContent) || 0;
            const utileMese = unitaMese * utileUnit;
            sommaUtile += utileMese;
            
            const utileEuroCell = document.querySelector(`[data-mese="${mese}"][data-field="utile_euro"][data-anno="${year}"] .cell-value`);
            if (utileEuroCell) utileEuroCell.textContent = `${fmt(utileMese)} €`;
            
            const fattCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_fatturato"][data-anno="${year}"] .cell-value`);
            const fattMese = parseFloat(fattCell?.textContent.replace(/[€\s]/g, '').replace(',', '.')) || 0;
            const utilePerc = fattMese > 0 ? (utileMese / fattMese) * 100 : 0;
            
            const utilePercCell = document.querySelector(`[data-mese="${mese}"][data-field="utile_percentuale"][data-anno="${year}"] .cell-value`);
            if (utilePercCell) utilePercCell.textContent = `${fmt(utilePerc)}%`;
        }
        
        if (el(`total-avg-utile${suffix}`)) el(`total-avg-utile${suffix}`).textContent = `${fmt(sommaUtile)} € / ${fmt(utileUnit)} €`;
        if (el(`total-avg-utile-perc${suffix}`)) el(`total-avg-utile-perc${suffix}`).textContent = totals.fatturato > 0 ? `${((sommaUtile / totals.fatturato) * 100).toFixed(1)}%` : '0%';
    }
    
    // Override loadFatturatoFromSettlement per supporto multi-anno mobile
    function overrideLoadFatturatoForMultiYear() {
        if (!window.rendicontoApp) {
            return;
        }
        
        
        // Backup originale
        const originalLoadFatturato = window.rendicontoApp.loadFatturatoFromSettlement.bind(window.rendicontoApp);
        
        // Override con supporto data-anno
        window.rendicontoApp.loadFatturatoFromSettlement = async function(anno) {
            
            try {
                const response = await fetch(`?action=get_fatturato_settlement&anno=${anno}`);
                
                if (!response.ok) {
                    return;
                }
                
                const result = await response.json();
                
                if (!result.success) {
                    return;
                }
                
                if (!result.dati_mensili || Object.keys(result.dati_mensili).length === 0) {
                    return;
                }
                
                const monthCount = Object.keys(result.dati_mensili).length;
                
                let successCount = 0;
                let failCount = 0;
                
                // Update cells with data-anno filter
                Object.values(result.dati_mensili).forEach(datiMese => {
                    const mese = datiMese.mese;
                    const fatturato = parseFloat(datiMese.fatturato) || 0;
                    const unita = parseInt(datiMese.unita_vendute) || 0;
                    const erogato = parseFloat(datiMese.erogato) || 0;
                    
                    // CRITICAL: Add [data-anno="${anno}"] to selectors
                    const fatturatoCell = document.querySelector(
                        `.cell-readonly[data-mese="${mese}"][data-field="entrate_fatturato"][data-anno="${anno}"]`
                    );
                    const unitaCell = document.querySelector(
                        `.cell-readonly[data-mese="${mese}"][data-field="entrate_unita"][data-anno="${anno}"]`
                    );
                    const erogatoCell = document.querySelector(
                        `.cell-readonly[data-mese="${mese}"][data-field="erogato_importo"][data-anno="${anno}"]`
                    );
                    
                    if (fatturatoCell) {
                        const valueSpan = fatturatoCell.querySelector('.cell-value');
                        if (valueSpan) {
                            valueSpan.textContent = this.formatNumber(fatturato, 2) + ' €';
                            successCount++;
                        } else {
                            failCount++;
                        }
                    } else {
                        failCount++;
                    }
                    
                    if (unitaCell) {
                        const valueSpan = unitaCell.querySelector('.cell-value');
                        if (valueSpan) valueSpan.textContent = unita;
                    }
                    
                    if (erogatoCell) {
                        const valueSpan = erogatoCell.querySelector('.cell-value');
                        if (valueSpan) valueSpan.textContent = this.formatNumber(erogato, 2) + ' €';
                    }
                    
                    // Update internal data structure
                    if (!this.data.righe[mese]) {
                        this.data.righe[mese] = { mese: mese };
                    }
                    this.data.righe[mese].entrate_fatturato = fatturato;
                    this.data.righe[mese].entrate_unita = unita;
                    this.data.righe[mese].erogato_importo = erogato;
                    
                    // Calculate accantonamento_percentuale (E column) = (F / D) × 100
                    // After erogato is loaded, calculate percentage for each month
                    const calcPercForMese = (m, a) => {
                        const accantoCell = document.querySelector(`[data-mese="${m}"][data-field="accantonamento_euro"][data-anno="${a}"] .cell-value`);
                        const erogatoCell = document.querySelector(`[data-mese="${m}"][data-field="erogato_importo"][data-anno="${a}"] .cell-value`);
                        const percCell = document.querySelector(`[data-mese="${m}"][data-field="accantonamento_percentuale"][data-anno="${a}"] .cell-value`);
                        
                        if (accantoCell && erogatoCell && percCell) {
                            const accantoValue = parseFloat(accantoCell.textContent.replace(/[€\s]/g, '').replace(',', '.')) || 0;
                            const erogatoValue = parseFloat(erogatoCell.textContent.replace(/[€\s]/g, '').replace(',', '.')) || 0;
                            
                            if (erogatoValue > 0) {
                                const percentage = (accantoValue / erogatoValue) * 100;
                                percCell.textContent = percentage.toFixed(2) + '%';
                            } else {
                                percCell.textContent = '0.00%';
                            }
                        }
                    };
                    
                    setTimeout(() => calcPercForMese(mese, anno), 100);
                });
                
                
            } catch (error) {
            }
        };
        
    }
    
    // Override populateCellsFromTransactions for multi-year support
    function overridePopulateCellsForMultiYear() {
        if (!window.rendicontoApp) {
            return;
        }
        
        // Override con supporto data-anno
        window.rendicontoApp.populateCellsFromTransactions = async function(anno) {
            
            try {
                const response = await fetch(`?action=get_input_utente&anno=${anno}`);
                
                if (!response.ok) {
                    return;
                }
                
                const result = await response.json();
                
                if (!result.success || !result.data || result.data.length === 0) {
                    return;
                }
                
                // DESKTOP PARITY: Aggrega PRIMA di scrivere (evita somme duplicate)
                const aggregati = {};
                
                result.data.forEach(trans => {
                    const mese = trans.mese;
                    const tipo = trans.tipo_input;
                    
                    if (!aggregati[mese]) {
                        aggregati[mese] = {};
                    }
                    
                    if (!aggregati[mese][tipo]) {
                        aggregati[mese][tipo] = { importo: 0, quantita: 0 };
                    }
                    
                    aggregati[mese][tipo].importo += parseFloat(trans.importo) || 0;
                    aggregati[mese][tipo].quantita += parseInt(trans.quantita) || 0;
                });
                
                // Map tipo_input to field names
                const fieldMap = {
                    'accantonamento_euro': 'accantonamento_euro',
                    'tasse_pagamento': 'tasse_euro',
                    'materia_prima_acquisto': 'materia1_euro',
                    'spedizioni_acquisto': 'sped_euro',
                    'spese_varie': 'varie_euro'
                };
                
                // Aggiorna celle con valori aggregati
                for (let mese = 1; mese <= 12; mese++) {
                    if (aggregati[mese]) {
                        Object.keys(aggregati[mese]).forEach(tipo => {
                            const field = fieldMap[tipo];
                            if (!field) return;
                            
                            const value = aggregati[mese][tipo].importo;
                            const quantita = aggregati[mese][tipo].quantita;
                            
                            // Update euro cell
                            const cell = document.querySelector(
                                `.cell-readonly[data-mese="${mese}"][data-field="${field}"][data-anno="${anno}"]`
                            );
                            
                            if (cell) {
                                const valueSpan = cell.querySelector('.cell-value');
                                if (valueSpan) {
                                    valueSpan.textContent = value.toFixed(2) + ' €';
                                }
                                
                                if (value !== 0) {
                                    cell.classList.add('has-data');
                                }
                            }
                            
                            // Update quantita cells
                            if (quantita > 0 && (field === 'materia1_euro' || field === 'sped_euro')) {
                                const unitaField = field === 'materia1_euro' ? 'materia1_unita' : 'sped_unita';
                                const unitaCell = document.querySelector(
                                    `.cell-readonly[data-mese="${mese}"][data-field="${unitaField}"][data-anno="${anno}"]`
                                );
                                
                                if (unitaCell) {
                                    const valueSpan = unitaCell.querySelector('.cell-value');
                                    if (valueSpan) {
                                        valueSpan.textContent = quantita;
                                    }
                                }
                            }
                        });
                    }
                }
                
                // Apply negative value styles
                applyNegativeValueStyles();
                
            } catch (error) {
            }
        };
    }
    
    // Override updateGlobalKPIRow for multi-year (sum ALL years)
    function overrideUpdateGlobalKPIForMultiYear() {
        if (!window.rendicontoApp) {
            return;
        }
        
        // Store original function
        const originalUpdateGlobalKPIRow = window.rendicontoApp.updateGlobalKPIRow;
        
        // Override with wrapped version
        window.rendicontoApp.updateGlobalKPIRow = function() {
            
            try {
                // Local formatNumber (no dependency on this.formatNumber)
                const formatNumber = (num, decimals) => {
                    return num.toFixed(decimals).replace('.', ',');
                };
                
                // Get all available years (SYNC approach using existing data)
                const years = [];
                const containers = document.querySelectorAll('.table-container[data-year]');
                containers.forEach(container => {
                    const year = parseInt(container.dataset.year);
                    if (!isNaN(year)) years.push(year);
                });
                
                if (years.length === 0) {
                    return;
                }
                
                years.sort((a, b) => b - a);
                const currentYear = years[0];
                
                // Sum totals from ALL year footers
                let globalTotals = {
                    fatturato: 0, unita: 0, erogato: 0, accant: 0,
                    tasse: 0, materia1: 0, materia1_unita: 0,
                    sped: 0, sped_unita: 0, varie: 0
                };
                
                years.forEach(year => {
                    
                    // Sum DIRECTLY from table cells (not footer totals)
                    const cells = document.querySelectorAll(`[data-anno="${year}"][data-field]`);
                    
                    let yearTotals = {
                        fatturato: 0, unita: 0, erogato: 0, accant: 0,
                        tasse: 0, materia1: 0, materia1_unita: 0,
                        sped: 0, sped_unita: 0, varie: 0
                    };
                    
                    cells.forEach(cell => {
                        const field = cell.dataset.field;
                        const valueSpan = cell.querySelector('.cell-value');
                        if (!valueSpan) return;
                        
                        const text = valueSpan.textContent;
                        const value = parseFloat(text.replace(/[€\s]/g, '').replace(',', '.')) || 0;
                        
                        if (field === 'entrate_fatturato') yearTotals.fatturato += value;
                        else if (field === 'entrate_unita') yearTotals.unita += parseInt(text) || 0;
                        else if (field === 'erogato_importo') yearTotals.erogato += value;
                        else if (field === 'accantonamento_euro') yearTotals.accant += value;
                        else if (field === 'tasse_euro') yearTotals.tasse += value;
                        else if (field === 'materia1_euro') yearTotals.materia1 += value;
                        else if (field === 'materia1_unita') yearTotals.materia1_unita += parseInt(text) || 0;
                        else if (field === 'sped_euro') yearTotals.sped += value;
                        else if (field === 'sped_unita') yearTotals.sped_unita += parseInt(text) || 0;
                        else if (field === 'varie_euro') yearTotals.varie += value;
                    });
                    
                    // Add year totals to global
                    globalTotals.fatturato += yearTotals.fatturato;
                    globalTotals.unita += yearTotals.unita;
                    globalTotals.erogato += yearTotals.erogato;
                    globalTotals.accant += yearTotals.accant;
                    globalTotals.tasse += yearTotals.tasse;
                    globalTotals.materia1 += yearTotals.materia1;
                    globalTotals.materia1_unita += yearTotals.materia1_unita;
                    globalTotals.sped += yearTotals.sped;
                    globalTotals.sped_unita += yearTotals.sped_unita;
                    globalTotals.varie += yearTotals.varie;
                });
                
                // Calculate FBA (desktop formula)
                const fba = -(globalTotals.fatturato - globalTotals.erogato);
                
                // Calculate Utile Netto (desktop formula)
                const utileLordo = globalTotals.erogato + globalTotals.tasse + globalTotals.materia1 + globalTotals.sped + globalTotals.varie;
                
                // Tax Plafond = accant + tasse (tasse is negative)
                const taxPlafond = globalTotals.accant + globalTotals.tasse;
                
                // Update hidden KPI elements
                const fmt = (n) => formatNumber(Math.abs(n), 2) + ' €';
                const perUnit = (tot, units) => units > 0 ? formatNumber(Math.abs(tot / units), 2) + ' €' : '0,00 €';
                const perc = (part, whole) => whole !== 0 ? (Math.abs(part / whole) * 100).toFixed(2) + '%' : '0,00%';
                
                const set = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.textContent = value;
                    }
                };
                
                // Fatturato
                set('kpi-fatturato-totale', fmt(globalTotals.fatturato));
                set('kpi-fatturato-per-unita', perUnit(globalTotals.fatturato, globalTotals.unita));
                
                // Erogato
                set('kpi-erogato-totale', fmt(globalTotals.erogato));
                set('kpi-erogato-per-unita', perUnit(globalTotals.erogato, globalTotals.unita));
                set('kpi-erogato-perc-fatt', perc(globalTotals.erogato, globalTotals.fatturato));
                
                // FBA
                set('kpi-fba-totale', (fba >= 0 ? '' : '-') + fmt(fba));
                set('kpi-fba-per-unita', (fba >= 0 ? '' : '-') + perUnit(fba, globalTotals.unita));
                set('kpi-fba-perc-fatt', (fba >= 0 ? '' : '-') + perc(fba, globalTotals.fatturato));
                if (globalTotals.erogato !== 0) {
    set('kpi-fba-perc-erog', (fba >= 0 ? '' : '-') + perc(fba, globalTotals.erogato));
}
                
                // Utile
                set('kpi-utile-lordo-totale', fmt(utileLordo));
                set('kpi-utile-lordo-per-unita', perUnit(utileLordo, globalTotals.unita));
                set('kpi-utile-lordo-perc-fatt', perc(utileLordo, globalTotals.fatturato));
                set('kpi-utile-lordo-perc-erog', perc(utileLordo, globalTotals.erogato));
                
                // Varie
                set('kpi-varie-totale', (globalTotals.varie >= 0 ? '' : '-') + fmt(globalTotals.varie));
                set('kpi-varie-per-unita', (globalTotals.varie >= 0 ? '' : '-') + perUnit(globalTotals.varie, globalTotals.unita));
                set('kpi-varie-perc-fatt', (globalTotals.varie >= 0 ? '' : '-') + perc(globalTotals.varie, globalTotals.fatturato));
                set('kpi-varie-perc-erog', (globalTotals.varie >= 0 ? '' : '-') + perc(globalTotals.varie, globalTotals.erogato));
                
                // Tasse
                set('kpi-tasse-totale', (globalTotals.tasse >= 0 ? '' : '-') + fmt(globalTotals.tasse));
                set('kpi-tasse-per-unita', (globalTotals.tasse >= 0 ? '' : '-') + perUnit(globalTotals.tasse, globalTotals.unita));
                set('kpi-tasse-perc-fatt', (globalTotals.tasse >= 0 ? '' : '-') + perc(globalTotals.tasse, globalTotals.fatturato));
                set('kpi-tasse-perc-erog', (globalTotals.tasse >= 0 ? '' : '-') + perc(globalTotals.tasse, globalTotals.erogato));
                
                // Accantonamento
                set('kpi-accantonamento-totale', fmt(globalTotals.accant));
                set('kpi-accantonamento-per-unita', perUnit(globalTotals.accant, globalTotals.unita));
                set('kpi-accantonamento-perc-fatt', perc(globalTotals.accant, globalTotals.fatturato));
                set('kpi-accantonamento-perc-erog', perc(globalTotals.accant, globalTotals.erogato));
                
                // Materia1
                set('kpi-materia1-totale', (globalTotals.materia1 >= 0 ? '' : '-') + fmt(globalTotals.materia1));
                set('kpi-materia1-per-unita', (globalTotals.materia1 >= 0 ? '' : '-') + perUnit(globalTotals.materia1, globalTotals.materia1_unita));
                set('kpi-materia1-perc-fatt', (globalTotals.materia1 >= 0 ? '' : '-') + perc(globalTotals.materia1, globalTotals.fatturato));
                set('kpi-materia1-perc-erog', (globalTotals.materia1 >= 0 ? '' : '-') + perc(globalTotals.materia1, globalTotals.erogato));
                
                // Spedizioni
                set('kpi-sped-totale', (globalTotals.sped >= 0 ? '' : '-') + fmt(globalTotals.sped));
                set('kpi-sped-per-unita', (globalTotals.sped >= 0 ? '' : '-') + perUnit(globalTotals.sped, globalTotals.sped_unita));
                set('kpi-sped-perc-fatt', (globalTotals.sped >= 0 ? '' : '-') + perc(globalTotals.sped, globalTotals.fatturato));
                set('kpi-sped-perc-erog', (globalTotals.sped >= 0 ? '' : '-') + perc(globalTotals.sped, globalTotals.erogato));
                
                // Tax Plafond
                set('tax-plafond-table', fmt(taxPlafond));
                
                // Units - Write to HIDDEN spans (to avoid ID conflict with visible cards)
                set('hidden-unita-vendute', globalTotals.unita.toString());
                set('hidden-unita-acquistate', globalTotals.materia1_unita.toString());
                set('hidden-unita-spedite', globalTotals.sped_unita.toString());
                
                // Apply negative value styles (like desktop)
                applyNegativeValueStyles();
                
                // Trigger syncKPI to update visible cards from hidden elements
                if (typeof window.syncKPI === 'function') {
                    window.syncKPI();
                    
                    // Re-apply negative value styles to synced elements
                    setTimeout(() => {
                        applyNegativeValueStyles();
                    }, 100);
                }
                
            } catch (error) {
                console.error('Error in updateGlobalKPIRow:', error);
            }
        };
    }
    
    // Apply negative value styles (desktop parity)
    function applyNegativeValueStyles() {
        // Select all .cell-value spans in main table
        const allCellValues = document.querySelectorAll('.cell-value');
        
        allCellValues.forEach(span => {
            const text = span.textContent.trim();
            
            // Check if text contains negative sign (-)
            if (text.includes('-') && text !== '-') {
                span.classList.add('negative-value');
            } else {
                span.classList.remove('negative-value');
            }
        });
        
        // Handle hidden KPI elements
        const kpiElements = document.querySelectorAll('span[id^="kpi-"]');
        kpiElements.forEach(span => {
            const text = span.textContent.trim();
            if (text.includes('-') && text !== '-') {
                span.classList.add('negative-value');
            } else {
                span.classList.remove('negative-value');
            }
        });
        
        // Handle mobile KPI cards (flow-* divs)
        const flowElements = document.querySelectorAll('div[id^="flow-"]');
        flowElements.forEach(div => {
            const text = div.textContent.trim();
            if (text.includes('-') && text !== '-') {
                div.classList.add('negative-value');
            } else {
                div.classList.remove('negative-value');
            }
        });
        
        // Handle KPI card main values and detail values
        const kpiValues = document.querySelectorAll('.kpi-value, .kpi-detail-value');
        kpiValues.forEach(el => {
            const text = el.textContent.trim();
            if (text.includes('-') && text !== '-') {
                el.classList.add('negative-value');
            } else {
                el.classList.remove('negative-value');
            }
        });
        
        // Handle info-box values
        const infoBoxValues = document.querySelectorAll('.info-box-value, .info-box-secondary');
        infoBoxValues.forEach(el => {
            const text = el.textContent.trim();
            if (text.includes('-') && text !== '-') {
                el.classList.add('negative-value');
            } else {
                el.classList.remove('negative-value');
            }
        });
        
        // Handle unit values
        const unitValues = document.querySelectorAll('.unit-value');
        unitValues.forEach(el => {
            const text = el.textContent.trim();
            if (text.includes('-') && text !== '-') {
                el.classList.add('negative-value');
            } else {
                el.classList.remove('negative-value');
            }
        });
    }
    
    // Setup transaction refresh overrides (called after init)
    function setupTransactionRefresh() {
        if (!window.rendicontoApp || !window.rendicontoApp.saveTransaction) {
            return;
        }
        
        // Override loadYear and loadInitialData to make them silent on mobile
        // (prevent "Nessun dato trovato" messages)
        const originalLoadYear = window.rendicontoApp.loadYear;
        window.rendicontoApp.loadYear = async function() {
            // On mobile, do nothing - we use refreshYearData instead
            return;
        };
        
        const originalLoadInitialData = window.rendicontoApp.loadInitialData;
        window.rendicontoApp.loadInitialData = async function() {
            // On mobile, do nothing - we use refreshYearData instead
            return;
        };
        
        const originalSaveTransaction = window.rendicontoApp.saveTransaction;
        window.rendicontoApp.saveTransaction = async function(e) {
            // Store the year before the save
            const dataField = document.getElementById('trans-data');
            const yearToRefresh = dataField && dataField.value ? new Date(dataField.value).getFullYear() : null;
            
            try {
                // Call original save (but loadYear is now a no-op)
                await originalSaveTransaction.call(this, e);
                
                // Only refresh if save was successful AND year is valid
                if (yearToRefresh && typeof refreshYearData === 'function') {
                    // Wait a bit for the DOM to update
                    await new Promise(resolve => setTimeout(resolve, 200));
                    
                    // Refresh data silently
                    await refreshYearData(yearToRefresh);
                }
            } catch (error) {
                console.error('Error in saveTransaction override:', error);
                // Don't refresh if there was an error
            }
        };
        
        const originalDeleteTransaction = window.rendicontoApp.deleteTransaction;
        window.rendicontoApp.deleteTransaction = async function(id) {
            let yearToRefresh = null;
            
            try {
                // Get transaction data to extract year before deleting
                const response = await fetch(`?action=get_input_utente&id=${id}`);
                const result = await response.json();
                
                if (result.success && result.data && result.data.length > 0) {
                    const trans = result.data[0];
                    yearToRefresh = new Date(trans.data).getFullYear();
                    
                    // Call original delete
                    await originalDeleteTransaction.call(this, id);
                    
                    // Only refresh if delete was successful AND year is valid
                    if (yearToRefresh && typeof refreshYearData === 'function') {
                        // Wait a bit for the DOM to update
                        await new Promise(resolve => setTimeout(resolve, 200));
                        
                        // Refresh data silently
                        await refreshYearData(yearToRefresh);
                    }
                }
            } catch (error) {
                console.error('Error in deleteTransaction override:', error);
                // Don't refresh if there was an error
            }
        };
    }
    
    // Global closeDrawer function (TridScanner style)
    function closeDrawer() {
        const overlay = document.getElementById('drawer-overlay');
        const drawer = document.getElementById('drawer');
        
        if (overlay) overlay.classList.remove('active');
        if (drawer) drawer.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    // Open drawer with transactions (desktop showCellTooltip adapted for mobile)
    async function openTransactionDrawer(cell) {
        const mese = cell.dataset.mese;
        const field = cell.dataset.field;
        const anno = cell.dataset.anno || document.getElementById('anno')?.value || new Date().getFullYear();
        
        // Mappa campi → tipi transazione
        const fieldToTypeMap = {
            'erogato_importo': 'erogato',
            'accantonamento_euro': 'accantonamento_euro',
            'tasse_euro': 'tasse_pagamento',
            'materia1_euro': 'materia_prima_acquisto',
            'sped_euro': 'spedizioni_acquisto',
            'varie_euro': 'spese_varie'
        };
        
        const fieldNames = {
            'erogato_importo': '💰 Erogato Amazon',
            'accantonamento_euro': '💼 Accantonamenti',
            'tasse_euro': '🏛️ Tasse',
            'materia1_euro': '🧪 Materia Prima',
            'sped_euro': '🚚 Spedizioni',
            'varie_euro': '📋 Varie'
        };
        
        const tipoInput = fieldToTypeMap[field];
        if (!tipoInput) return;
        
        try {
            let response, result;
            
            // API call diversa per settlement vs transazioni normali
            if (field === 'erogato_importo') {
                response = await fetch(`?action=get_fatturato_settlement&anno=${anno}&mese=${mese}`);
                result = await response.json();
            } else {
                response = await fetch(`?action=get_input_utente&anno=${anno}&mese=${mese}&tipo_input=${tipoInput}`);
                result = await response.json();
            }
            
            if (!result.success || !result.data || result.data.length === 0) {
                return; // Nessuna transazione, non aprire drawer
            }
            
            // Popola drawer
            const monthNames = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 
                              'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
            
            const drawerTitle = document.getElementById('drawer-title');
            const drawerSubtitle = document.getElementById('drawer-subtitle');
            const drawerContent = document.getElementById('drawer-content');
            
            drawerTitle.textContent = `${fieldNames[field] || 'Transazioni'} - ${monthNames[mese - 1]}`;
            drawerSubtitle.textContent = `${result.data.length} transazione/i`;
            
            // Genera HTML transazioni
            let html = '';
            result.data.forEach(t => {
                if (field === 'erogato_importo') {
                    // Transazioni Settlement (solo visualizzazione, no edit/delete)
                    // Now reading from rendiconto_input_utente (correct source)
                    const importoEur = parseFloat(t.importo_eur || 0);
                    const importoOriginal = parseFloat(t.amount || importoEur);
                    const currency = t.currency || 'EUR';
                    const formattedAmount = Math.abs(importoEur).toFixed(2);
                    const settlementDate = t.settlement_date || t.data || 'N/D';
                    const settlementId = t.settlement_id || '';
                    const descrizione = t.descrizione || '';
                    
                    // Format: show original currency if not EUR
                    let amountDisplay = `+€${formattedAmount}`;
                    if (currency !== 'EUR') {
                        amountDisplay += ` (${currency} ${Math.abs(importoOriginal).toFixed(2)})`;
                    }
                    
                    html += `
                        <div class="transaction-item settlement">
                            <div class="transaction-date">📅 ${settlementDate}</div>
                            <div class="transaction-amount positive">${amountDisplay}</div>
                            ${settlementId ? `<div class="transaction-note">🆔 ${settlementId}</div>` : ''}
                            ${descrizione ? `<div class="transaction-note">📝 ${descrizione}</div>` : ''}
            </div>
                    `;
                } else {
                    // Transazioni normali (con edit/delete)
                    const importo = parseFloat(t.importo);
                    const isNegative = importo < 0;
                    const amountClass = isNegative ? 'negative' : 'positive';
                    const formattedAmount = Math.abs(importo).toFixed(2);
                    const unitText = t.quantita && t.quantita !== 0 ? `<span class="units">• ${t.quantita} unità</span>` : '';
                    
                    html += `
                        <div class="transaction-item">
                            <div class="transaction-date">📅 ${t.data}</div>
                            <div class="transaction-amount ${amountClass}">
                                ${isNegative ? '-' : '+'}€${formattedAmount}
                                ${unitText}
        </div>
                            ${t.note ? `<div class="transaction-note">📝 ${t.note}</div>` : ''}
                            <div class="transaction-actions">
                                <button class="btn-edit" onclick="editTransaction(${t.id})">✏️ Modifica</button>
                                <button class="btn-delete" onclick="deleteTransaction(${t.id})">🗑️ Elimina</button>
    </div>
</div>
                    `;
                }
            });
            
            drawerContent.innerHTML = html;
            
            // Apri drawer
            const overlay = document.getElementById('drawer-overlay');
            const drawer = document.getElementById('drawer');
            
            overlay.classList.add('active');
            drawer.classList.add('active');
            document.body.style.overflow = 'hidden';
            
        } catch (error) {
            console.error('Error loading transactions:', error);
        }
    }
    
    // Edit transaction (chiude drawer e popola form)
    async function editTransaction(id) {
        closeDrawer();
        if (window.rendicontoApp && window.rendicontoApp.editTransaction) {
            await window.rendicontoApp.editTransaction(id);
        }
    }
    
    // Delete transaction (chiude drawer e ricarica)
    async function deleteTransaction(id) {
        if (!confirm('Eliminare questa transazione?')) return;
        
        closeDrawer();
        if (window.rendicontoApp && window.rendicontoApp.deleteTransaction) {
            await window.rendicontoApp.deleteTransaction(id);
        }
    }
    
    // Logout function
    function doLogout() {
        if (confirm('Sei sicuro di voler uscire?')) {
            window.location.href = '/modules/margynomic/login/logout.php';
        }
    }
    </script>
</body>
</html>