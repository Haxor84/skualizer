<?php
/**
 * Inbound Dashboard - Pannello Principale Admin
 * File: modules/inbound/inbound.php
 * 
 * Features:
 * - KPI dashboard con statistiche complete
 * - Selettore utente (dropdown multi-user)
 * - Navigazione tra moduli
 * - Stato cron live
 * - FAQ accordion collapsibile
 * - Solo ADMIN access
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
// USER SELECTION
// ============================================
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($_SESSION['inbound_selected_user'] ?? 2);
$_SESSION['inbound_selected_user'] = $selectedUserId;

// Get user info
$stmt = $db->prepare("SELECT id, nome, email FROM users WHERE id = ?");
$stmt->execute([$selectedUserId]);
$selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$selectedUser) {
    $selectedUser = ['id' => $selectedUserId, 'nome' => 'Unknown', 'email' => 'N/A'];
}

// Get available users (con token Amazon attivo)
$availableUsers = $db->query("
    SELECT DISTINCT u.id, u.nome, u.email 
    FROM users u
    INNER JOIN amazon_client_tokens t ON t.user_id = u.id
    WHERE u.is_active = 1 AND t.is_active = 1
    ORDER BY u.nome
")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// KPI CALCULATIONS
// ============================================

// KPI principali (escludi partial da complete)
$kpiQuery = "
    SELECT 
        COUNT(DISTINCT s.id) as total_shipments,
        COUNT(DISTINCT CASE 
            WHEN IFNULL(ss.sync_status, 'complete') = 'complete' THEN s.id 
        END) as complete_shipments,
        COUNT(DISTINCT CASE 
            WHEN ss.sync_status IN ('partial_loop', 'partial_no_progress', 'missing') THEN s.id 
        END) as partial_shipments,
        COUNT(DISTINCT CASE 
            WHEN ss.status_note LIKE '%boxes_v0%' THEN s.id 
        END) as boxes_v0_count,
        COUNT(DISTINCT i.id) as total_items,
        COUNT(DISTINCT b.id) as total_boxes,
        COUNT(DISTINCT s.destination_fc) as unique_fcs,
        SUM(i.quantity_shipped) as total_units,
        MAX(s.last_sync_at) as last_sync,
        MIN(s.shipment_created_date) as first_shipment,
        MAX(s.shipment_created_date) as last_shipment
    FROM inbound_shipments s
    LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
    LEFT JOIN inbound_shipment_items i ON i.shipment_id = s.id
    LEFT JOIN inbound_shipment_boxes b ON b.shipment_id = s.id
    WHERE s.user_id = ?
";

$stmt = $db->prepare($kpiQuery);
$stmt->execute([$selectedUserId]);
$kpi = $stmt->fetch(PDO::FETCH_ASSOC);

// Token status
$stmt = $db->prepare("SELECT is_active FROM amazon_client_tokens WHERE user_id = ? LIMIT 1");
$stmt->execute([$selectedUserId]);
$tokenActive = $stmt->fetchColumn();

// Sync state (cursore, circuit breaker, historical sweep)
$stmt = $db->prepare("
    SELECT last_cursor_utc, last_run_at, circuit_state, circuit_until, consecutive_errors,
           current_status, historic_cursors
    FROM sync_state 
    WHERE user_id = ?
");
$stmt->execute([$selectedUserId]);
$syncState = $stmt->fetch(PDO::FETCH_ASSOC);

// Cron status (check if locked)
$stmt = $db->prepare("
    SELECT locked_at, heartbeat_at, process_id 
    FROM sync_locks 
    WHERE user_id = ?
");
$stmt->execute([$selectedUserId]);
$cronLock = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate coverage percentage
$coveragePct = 100;
if ($kpi['total_shipments'] > 0) {
    $coveragePct = round(($kpi['complete_shipments'] / $kpi['total_shipments']) * 100, 1);
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbound Sync - Dashboard</title>
    <link rel="stylesheet" href="inbound.css">
    <style>
        /* Additional inline styles for FAQ toggle */
        #faq-toggle-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            box-shadow: var(--shadow-xl);
            z-index: 1000;
            transition: all var(--transition-base);
        }
        #faq-toggle-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }
    </style>
</head>
<body>

<?php echo getAdminNavigation('inbound'); ?>

    <div class="container">
        <!-- Header -->
        <div class="inbound-header">
            <div class="header-content">
                <div class="header-title">
                    🚀 Inbound Sync Dashboard
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
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == $selectedUserId ? 'selected' : '' ?>>
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
                L'utente deve completare l'autorizzazione Amazon prima di sincronizzare.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($syncState && $syncState['circuit_state'] === 'open'): ?>
        <div class="alert alert-danger">
            <span class="alert-icon">🔴</span>
            <div>
                <strong>Circuit Breaker Aperto!</strong><br>
                Troppi errori consecutivi (<?= $syncState['consecutive_errors'] ?>). 
                Sync sospeso fino a: <?= $syncState['circuit_until'] ? date('d/m/Y H:i', strtotime($syncState['circuit_until'])) : 'N/A' ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sweep Storico Status -->
        <?php if ($syncState): ?>
            <?php if ($syncState['current_status'] === null && $syncState['historic_cursors']): ?>
            <!-- Sweep completato -->
            <div class="alert alert-success" style="margin-bottom: 2rem;">
                <span class="alert-icon">🎉</span>
                <strong>Sweep Storico Completato!</strong><br>
                Tutti gli stati (CLOSED, RECEIVING, SHIPPED, WORKING, CANCELLED) sono stati processati fino a min_date.<br>
                Da ora in poi puoi usare solo <strong>Sync Incrementale</strong> per aggiornamenti giornalieri.
            </div>
            <?php elseif ($syncState['current_status']): ?>
            <!-- Sweep in pausa -->
            <div class="alert alert-warning" style="margin-bottom: 2rem;">
                <span class="alert-icon">⏸️</span>
                <strong>Sweep Storico In Pausa</strong><br>
                Stato corrente: <strong><?= htmlspecialchars($syncState['current_status']) ?></strong><br>
                <a href="inbound_sync.php?user_id=<?= $selectedUserId ?>" class="btn btn-secondary" style="margin-top: 0.5rem; display: inline-block;">
                    🔄 Continua Sweep
                </a>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- KPI Dashboard -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($kpi['total_shipments'] ?? 0) ?></div>
                <div class="stat-label">📦 Spedizioni Totali</div>
                <?php if ($kpi['complete_shipments']): ?>
                <div class="stat-sublabel">
                    <?= number_format($kpi['complete_shipments']) ?> complete
                </div>
                <?php endif; ?>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?= number_format($kpi['total_items'] ?? 0) ?></div>
                <div class="stat-label">📝 Items/Righe</div>
                <?php if ($kpi['total_units']): ?>
                <div class="stat-sublabel">
                    <?= number_format($kpi['total_units']) ?> unità
                </div>
                <?php endif; ?>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?= number_format($kpi['unique_fcs'] ?? 0) ?></div>
                <div class="stat-label">🏭 Warehouse Unici</div>
                <?php if ($kpi['total_boxes'] || $kpi['boxes_v0_count']): ?>
                <div class="stat-sublabel">
                    <?= number_format($kpi['total_boxes']) ?> box
                    <?php if ($kpi['boxes_v0_count'] > 0): ?>
                        <span title="Spedizioni con boxes non disponibili su API v0"> | <?= number_format($kpi['boxes_v0_count']) ?> N/A (v0)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($kpi['partial_shipments'] > 0): ?>
            <div class="stat-card" style="border-left: 4px solid var(--warning);">
                <div class="stat-value" style="color: var(--warning);">
                    <?= number_format($kpi['partial_shipments']) ?>
                </div>
                <div class="stat-label">⚠️ Spedizioni Parziali</div>
                <div class="stat-sublabel">Richiedono retry</div>
            </div>
            <?php endif; ?>

            <div class="stat-card">
                <div class="stat-value" style="color: <?= $coveragePct >= 95 ? 'var(--success)' : 'var(--warning)' ?>;">
                    <?= $coveragePct ?>%
                </div>
                <div class="stat-label">📊 Coverage</div>
                <div class="stat-sublabel">Completezza dati</div>
            </div>

            <div class="stat-card">
                <div class="stat-value" style="font-size: 1.5rem;">
                    <?php 
                    if ($kpi['last_sync']) {
                        $diff = time() - strtotime($kpi['last_sync']);
                        if ($diff < 3600) {
                            echo round($diff / 60) . 'm fa';
                        } elseif ($diff < 86400) {
                            echo round($diff / 3600) . 'h fa';
                        } else {
                            echo round($diff / 86400) . 'g fa';
                        }
                    } else {
                        echo 'Mai';
                    }
                    ?>
                </div>
                <div class="stat-label">⏱️ Ultimo Sync</div>
                <?php if ($kpi['last_sync']): ?>
                <div class="stat-sublabel"><?= date('d/m/Y H:i', strtotime($kpi['last_sync'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stato Cron -->
        <div class="card">
            <div class="card-header">🔄 Stato Sincronizzazione</div>
            <div class="card-body">
                <?php if ($cronLock): ?>
                    <?php 
                    $heartbeatAge = time() - strtotime($cronLock['heartbeat_at']);
                    $isStuck = $heartbeatAge > 300; // > 5 minuti = stuck
                    ?>
                    <div class="alert alert-<?= $isStuck ? 'warning' : 'info' ?>">
                        <span class="alert-icon"><?= $isStuck ? '⚠️' : '🔄' ?></span>
                        <div>
                            <strong><?= $isStuck ? 'Sync Stuck (possibile errore)' : 'Sync in corso...' ?></strong><br>
                            Processo: <?= htmlspecialchars(substr($cronLock['process_id'], 0, 30)) ?>...<br>
                            Ultimo heartbeat: <?= round($heartbeatAge / 60, 1) ?> minuti fa
                            <?php if ($isStuck): ?>
                                <br><small>Lock verrà rimosso automaticamente al prossimo tentativo</small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        <span class="alert-icon">✅</span>
                        <div>
                            <strong>Pronto per sincronizzazione</strong><br>
                            Nessun sync in corso. Sistema disponibile.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($syncState): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: var(--bg-hover); border-radius: var(--radius-md);">
                    <strong>Dettagli Sync State:</strong><br>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem; margin-top: 0.5rem; font-size: 0.875rem;">
                        <div><strong>Cursore:</strong> <?= $syncState['last_cursor_utc'] ?? 'Non impostato' ?></div>
                        <div><strong>Ultima run:</strong> <?= $syncState['last_run_at'] ? date('d/m H:i', strtotime($syncState['last_run_at'])) : 'Mai' ?></div>
                        <div><strong>Circuit:</strong> <span class="badge badge-<?= $syncState['circuit_state'] === 'closed' ? 'complete' : 'partial' ?>"><?= $syncState['circuit_state'] ?></span></div>
                        <div><strong>Errori consecutivi:</strong> <?= $syncState['consecutive_errors'] ?? 0 ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Azioni Rapide -->
        <div class="card">
            <div class="card-header">⚡ Azioni Rapide</div>
            <div class="card-body">
                <div class="btn-group">
                    <a href="inbound_sync.php?user_id=<?= $selectedUserId ?>" class="btn btn-primary">
                        🔄 Avvia Sincronizzazione
                    </a>
                    <a href="import_from_packing_list.php?user_id=<?= $selectedUserId ?>" class="btn btn-warning" style="background: #ffc107; border-color: #ffc107;">
                        📥 Import Manuale CSV
                    </a>
                    <a href="inbound_views.php?view=shipments&user_id=<?= $selectedUserId ?>" class="btn btn-secondary">
                        📦 Visualizza Spedizioni
                    </a>
                    <a href="inbound_views.php?view=stats&user_id=<?= $selectedUserId ?>" class="btn btn-outline">
                        📊 Statistiche Avanzate
                    </a>
                    <a href="inbound_views.php?view=logs&user_id=<?= $selectedUserId ?>&level=ERROR" class="btn btn-outline">
                        📋 Log Errori
                    </a>
                </div>
            </div>
        </div>

        <!-- FAQ Accordion -->
        <div id="faq-section" class="card" style="display: none;">
            <div class="card-header">❓ FAQ & Troubleshooting</div>
            <div class="card-body">
                <div class="accordion">
                    
                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>🔄 Come faccio a sincronizzare le spedizioni?</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>Clicca su <strong>"Avvia Sincronizzazione"</strong> nella sezione Azioni Rapide. 
                            Puoi scegliere sync incrementale (solo spedizioni nuove/modificate) o rebuild storico completo.</p>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>📥 Come importo spedizioni mancanti manualmente?</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>Se Amazon API non restituisce alcune spedizioni (es. errore 403), puoi importarle manualmente:</p>
                            <ol>
                                <li>Click su <strong>"📥 Import Manuale CSV"</strong> in Azioni Rapide</li>
                                <li>Scarica il <strong>template CSV</strong> (vuoto o con esempio)</li>
                                <li>Vai su <strong>Seller Central → Spedizioni FBA</strong> e copia i dati</li>
                                <li>Compila il CSV con: ShipmentId, SKU, Quantity, ASIN, FNSKU</li>
                                <li>Carica il CSV → Import automatico!</li>
                            </ol>
                            <p>Le spedizioni importate avranno badge <span class="badge badge-manual">MANUAL</span> per distinguerle da quelle sincronizzate via API.</p>
                            <p><strong>Formato supportato:</strong> CSV Amazon IT (colonne italiane: "Numero spedizione", "SKU venditore", "Unità spedite", ecc.)</p>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>⚠️ Perché vedo spedizioni "PARZIALI"?</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>Le spedizioni parziali indicano che la sincronizzazione è stata interrotta per uno di questi motivi:</p>
                            <ul>
                                <li><strong>partial_loop:</strong> Amazon ha restituito token duplicati o pagine ripetute (bug API)</li>
                                <li><strong>partial_no_progress:</strong> Errore durante il fetch degli items</li>
                                <li><strong>missing:</strong> Spedizione non trovata</li>
                            </ul>
                            <p>Il sistema riproverà automaticamente al prossimo sync. Se persiste per >3 run, verrà marcata come "permanent_fail".</p>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>🔴 Cosa significa "Circuit Breaker Aperto"?</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>Il circuit breaker si attiva automaticamente dopo <strong>5 errori consecutivi gravi</strong> (401, 403, 429 ripetuti, 5xx).</p>
                            <p>Quando aperto, il sync viene <strong>sospeso per 6 ore</strong> per evitare di sovraccaricare l'API Amazon.</p>
                            <p>Dopo 6 ore passa in stato "half-open" (1 tentativo) e se va a buon fine si richiude.</p>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>⏱️ Quanto tempo richiede una sincronizzazione completa?</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>Dipende dal numero di spedizioni:</p>
                            <ul>
                                <li><strong>Sync incrementale:</strong> 1-5 minuti (solo nuove/modificate)</li>
                                <li><strong>Primo sync storico:</strong> 10-30 minuti (tutte le spedizioni dal 2015)</li>
                            </ul>
                            <p>Il sistema applica rate limiting (0.5s tra chiamate) e limiti di budget (100 chiamate API per run).</p>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>🔐 Cosa fare se il Token Amazon è inattivo?</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>L'utente deve autorizzare l'app Amazon tramite il flusso OAuth:</p>
                            <ol>
                                <li>Vai al <strong>Profilo Utente</strong></li>
                                <li>Sezione "Autorizzazione Amazon"</li>
                                <li>Clicca "Autorizza Account Amazon"</li>
                                <li>Completa il login Amazon e conferma i permessi</li>
                            </ol>
                            <p>Il token viene salvato automaticamente e il sync sarà disponibile.</p>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>📊 Come interpreto il "Coverage %"?</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>Il coverage indica la <strong>percentuale di spedizioni completamente sincronizzate</strong>:</p>
                            <ul>
                                <li><strong>100%:</strong> Tutte le spedizioni complete</li>
                                <li><strong>95-99%:</strong> Ottimo (qualche spedizione parziale è normale)</li>
                                <li><strong><90%:</strong> Controllare log errori, possibili problemi API</li>
                            </ul>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header" onclick="toggleAccordion(this)">
                            <span>🐛 Errore 429 "Too Many Requests"</span>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <p>Amazon limita il numero di richieste API. Il sistema gestisce automaticamente:</p>
                            <ul>
                                <li><strong>Backoff esponenziale:</strong> Attesa di 1, 2, 4, 8, 16, 32 secondi con jitter ±30%</li>
                                <li><strong>Retry automatico:</strong> Fino a 5 tentativi</li>
                                <li><strong>Budget API:</strong> Max 100 chiamate per run, poi pausa</li>
                            </ul>
                            <p>Non è necessario intervenire manualmente.</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Back to Admin Dashboard -->
        <div style="text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border-color);">
            <a href="../margynomic/admin/admin_dashboard.php" style="color: var(--text-secondary);">
                ← Torna al Dashboard Admin Margynomic
            </a>
        </div>

    </div>

    <!-- FAQ Toggle Button (Floating) -->
    <button id="faq-toggle-btn" onclick="toggleFaq()" aria-label="Toggle FAQ">
        ?
    </button>

    <script>
        // Toggle FAQ section
        function toggleFaq() {
            const faqSection = document.getElementById('faq-section');
            const btn = document.getElementById('faq-toggle-btn');
            
            if (faqSection.style.display === 'none') {
                faqSection.style.display = 'block';
                faqSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                btn.textContent = '✕';
            } else {
                faqSection.style.display = 'none';
                btn.textContent = '?';
            }
        }
        
        // Toggle accordion item
        function toggleAccordion(header) {
            const item = header.parentElement;
            const wasOpen = item.classList.contains('open');
            
            // Close all
            document.querySelectorAll('.accordion-item').forEach(i => {
                i.classList.remove('open');
            });
            
            // Open clicked if was closed
            if (!wasOpen) {
                item.classList.add('open');
            }
        }
        
        // Auto-open FAQ if hash is #faq
        if (window.location.hash === '#faq') {
            toggleFaq();
        }
    </script>
</body>
</html>

