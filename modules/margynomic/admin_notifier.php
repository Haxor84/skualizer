<?php
/**
 * AdminNotifier - Sistema Notifiche Admin Margynomic
 * File: modules/margynomic/admin_notifier.php
 * 
 * Sistema completo di notifiche email per monitoraggio operazioni critiche:
 * - Alert immediati per fallimenti email
 * - Notifiche errori cron settlement/inventory
 * - Report giornalieri con statistiche complete
 * - Sistema cooldown anti-spam
 * - Parser log JSON per analisi metriche
 */

require_once __DIR__ . '/gestione_vendor.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/daily_report_helpers.php';

class AdminNotifier {
    
    // Configurazione
    const ADMIN_EMAIL = 'haxor84@gmail.com';
    const COOLDOWN_MINUTES = 30;
    const CACHE_DIR = __DIR__ . '/cache/notifications/';
    const LOG_RETENTION_DAYS = 7;
    
    /**
     * Inizializza directory cache se non esiste
     */
    private static function ensureCacheDir() {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }
    
    /**
     * Verifica cooldown per evitare spam
     */
    public static function checkCooldown($type, $identifier = '') {
        self::ensureCacheDir();
        
        $cacheKey = md5($type . '_' . $identifier);
        $cacheFile = self::CACHE_DIR . $cacheKey . '.cache';
        
        if (file_exists($cacheFile)) {
            $lastSent = filemtime($cacheFile);
            $cooldownExpiry = $lastSent + (self::COOLDOWN_MINUTES * 60);
            
            if (time() < $cooldownExpiry) {
                $remainingMinutes = ceil(($cooldownExpiry - time()) / 60);
                CentralLogger::log('admin_notifications', 'INFO', 
                    "Cooldown attivo per {$type}: {$remainingMinutes} minuti rimanenti", 
                    ['identifier' => $identifier]);
                return false;
            }
        }
        
        // Aggiorna cache
        touch($cacheFile);
        return true;
    }
    
    /**
     * Genera template HTML base per email
     */
    private static function getEmailTemplate($title, $type = 'info') {
        $colors = [
            'error' => '#ff3547',
            'warning' => '#ffb400', 
            'success' => '#00c851',
            'info' => '#17a2b8'
        ];
        
        $bgColor = $colors[$type] ?? $colors['info'];
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$title}</title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f8f9fa; }
                .container { max-width: 600px; margin: 0 auto; background: white; }
                .header { background: linear-gradient(135deg, {$bgColor}, " . self::adjustBrightness($bgColor, -20) . "); color: white; padding: 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 24px; }
                .content { padding: 30px; }
                .card { background: #f8f9fa; border-left: 4px solid {$bgColor}; padding: 15px; margin: 15px 0; }
                .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0; }
                .stat-card { background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; text-align: center; }
                .stat-number { font-size: 24px; font-weight: bold; color: {$bgColor}; }
                .stat-label { font-size: 12px; color: #6c757d; text-transform: uppercase; }
                .footer { background: #343a40; color: white; padding: 20px; text-align: center; font-size: 12px; }
                .timestamp { color: #6c757d; font-size: 12px; }
                .error-details { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; }
                @media (max-width: 600px) {
                    .stats-grid { grid-template-columns: 1fr 1fr; }
                    .content { padding: 20px; }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚨 {$title}</h1>
                    <div class='timestamp'>Generato il " . date('d/m/Y H:i:s') . "</div>
                </div>
                <div class='content'>
                    {{CONTENT}}
                </div>
                <div class='footer'>
                    <strong>Margynomic Admin System</strong><br>
                    Sistema di monitoraggio automatico - Non rispondere a questa email
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Regola luminosità colore
     */
    private static function adjustBrightness($hex, $percent) {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        $r = max(0, min(255, $r + ($r * $percent / 100)));
        $g = max(0, min(255, $g + ($g * $percent / 100)));
        $b = max(0, min(255, $b + ($b * $percent / 100)));
        
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }
    
    /**
     * Notifica fallimento email immediato
     */
    public static function notifyEmailFailure($userId, $userEmail, $error, $context = []) {
        if (!self::checkCooldown('email_failure', $userId)) {
            return false;
        }
        
        $template = self::getEmailTemplate('Alert Fallimento Email', 'error');
        
        $content = "
        <div class='card'>
            <h3>❌ Fallimento Invio Email</h3>
            <p><strong>Utente ID:</strong> {$userId}</p>
            <p><strong>Email Destinatario:</strong> {$userEmail}</p>
            <p><strong>Timestamp:</strong> " . date('d/m/Y H:i:s') . "</p>
            </div>
            
        <div class='card'>
            <h4>🔍 Dettagli Errore</h4>
            <div class='error-details'>{$error}</div>
        </div>";
        
        if (!empty($context)) {
            $content .= "
            <div class='card'>
                <h4>📋 Contesto Aggiuntivo</h4>
                <div class='error-details'>" . json_encode($context, JSON_PRETTY_PRINT) . "</div>
            </div>";
        }
        
        $content .= "
        <div class='card'>
            <h4>🔧 Azioni Suggerite</h4>
            <ul>
                <li>Verificare configurazione SMTP in config.php</li>
                <li>Controllare validità email destinatario</li>
                <li>Verificare log SMTP per dettagli tecnici</li>
                <li>Testare invio manuale tramite gestione_vendor.php</li>
            </ul>
        </div>";
        
        $htmlBody = str_replace('{{CONTENT}}', $content, $template);
        
        $result = inviaEmailSMTP(
            self::ADMIN_EMAIL,
            "🚨 ALERT: Fallimento Email - Utente {$userId}",
            $htmlBody
        );
        
        CentralLogger::log('admin_notifications', $result ? 'INFO' : 'ERROR', 
            "Email failure notification " . ($result ? 'sent' : 'failed'), [
                'user_id' => $userId,
                'user_email' => $userEmail,
                'error' => $error,
                'context' => $context
            ]);
        
        return $result;
    }
    
    /**
     * Notifica fallimento cron settlement
     */
    public static function notifySettlementCronFailure($error, $context = []) {
        if (!self::checkCooldown('settlement_failure', date('Y-m-d-H'))) {
            return false;
        }
        
        $template = self::getEmailTemplate('Alert Fallimento Settlement Cron', 'error');
        
        $content = "
        <div class='card'>
            <h3>⚠️ Errore Settlement Sync</h3>
            <p><strong>Timestamp:</strong> " . date('d/m/Y H:i:s') . "</p>
            <p><strong>Processo:</strong> cron_settlement_margynomic.php</p>
            </div>
            
        <div class='card'>
            <h4>🔍 Dettagli Errore</h4>
            <div class='error-details'>{$error}</div>
        </div>";
        
        if (!empty($context)) {
            $content .= "
            <div class='card'>
                <h4>📋 Contesto</h4>
                <div class='error-details'>" . json_encode($context, JSON_PRETTY_PRINT) . "</div>
            </div>";
        }
        
        $content .= "
        <div class='card'>
            <h4>🔧 Azioni Suggerite</h4>
            <ul>
                <li>Verificare connessione database</li>
                <li>Controllare token Amazon utenti</li>
                <li>Verificare log settlement per dettagli</li>
                <li>Eseguire test manuale settlement sync</li>
                <li>Controllare rate limiting Amazon API</li>
            </ul>
        </div>";
        
        $htmlBody = str_replace('{{CONTENT}}', $content, $template);
        
        $result = inviaEmailSMTP(
            self::ADMIN_EMAIL,
            "🚨 ALERT: Errore Settlement Cron",
            $htmlBody
        );
        
        CentralLogger::log('admin_notifications', $result ? 'INFO' : 'ERROR', 
            "Settlement cron failure notification " . ($result ? 'sent' : 'failed'), [
                'error' => $error,
                'context' => $context
            ]);
        
        return $result;
    }
    
    /**
     * Notifica completamento cron Inbound con dettagli completi
     */
    public static function notifyInboundCronCompletion($summary, $details = []) {
        // Cooldown più permissivo (ogni run inbound può mandare email)
        if (!self::checkCooldown('inbound_completion', date('Y-m-d-H'))) {
            return false;
        }
        
        $template = self::getEmailTemplate('Inbound Sync Completato', 'success');
        
        // Determina health status
        $healthScore = 100;
        if ($summary['users_failed'] > 0) {
            $healthScore -= ($summary['users_failed'] * 20);
        }
        if ($summary['total_shipments_partial'] > 0) {
            $healthScore -= ($summary['total_shipments_partial'] * 5);
        }
        $healthScore = max(0, $healthScore);
        
        $healthColor = $healthScore >= 90 ? '#10b981' : ($healthScore >= 70 ? '#f59e0b' : '#ef4444');
        $healthEmoji = $healthScore >= 90 ? '✅' : ($healthScore >= 70 ? '⚠️' : '❌');
        
        $content = "
        <div style='background: linear-gradient(135deg, {$healthColor}, " . self::adjustBrightness($healthColor, -20) . "); color: white; padding: 25px; border-radius: 12px; margin-bottom: 25px; text-align: center;'>
            <h2 style='margin: 0 0 10px 0; font-size: 18px;'>{$healthEmoji} Inbound Sync Completato</h2>
            <h1 style='margin: 0; font-size: 42px; font-weight: bold;'>Health: {$healthScore}/100</h1>
            <p style='margin: 10px 0 0 0; opacity: 0.9;'>Eseguito: " . date('d/m/Y H:i:s') . "</p>
        </div>
        
        <div class='card'>
            <h3>📊 Summary Generale</h3>
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin: 15px 0;'>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase;'>Utenti Processati</div>
                    <div style='font-size: 28px; font-weight: bold; color: #343a40;'>{$summary['users_processed']}</div>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase;'>Utenti Successo</div>
                    <div style='font-size: 28px; font-weight: bold; color: #10b981;'>{$summary['users_success']}</div>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase;'>API Calls</div>
                    <div style='font-size: 28px; font-weight: bold; color: #17a2b8;'>{$summary['total_api_calls']}</div>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase;'>Durata</div>
                    <div style='font-size: 28px; font-weight: bold; color: #343a40;'>" . round($details['duration'] ?? 0, 1) . "s</div>
                </div>
            </div>
        </div>
        
        <div class='card'>
            <h3>📦 Shipments</h3>
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin: 15px 0;'>
                <div style='background: #f0fdf4; padding: 15px; border-radius: 6px; border-left: 4px solid #10b981;'>
                    <div style='font-size: 12px; color: #166534; text-transform: uppercase; font-weight: bold;'>Sincronizzate</div>
                    <div style='font-size: 32px; font-weight: bold; color: #10b981;'>{$summary['total_shipments_synced']}</div>
                </div>
                <div style='background: #fefce8; padding: 15px; border-radius: 6px; border-left: 4px solid #eab308;'>
                    <div style='font-size: 12px; color: #854d0e; text-transform: uppercase; font-weight: bold;'>Skipped</div>
                    <div style='font-size: 32px; font-weight: bold; color: #eab308;'>{$summary['total_shipments_skipped']}</div>
                </div>
                <div style='background: #fff7ed; padding: 15px; border-radius: 6px; border-left: 4px solid #f97316;'>
                    <div style='font-size: 12px; color: #9a3412; text-transform: uppercase; font-weight: bold;'>Parziali</div>
                    <div style='font-size: 32px; font-weight: bold; color: #f97316;'>{$summary['total_shipments_partial']}</div>
                </div>
                <div style='background: " . ($summary['total_errors'] > 0 ? '#fef2f2' : '#f8f9fa') . "; padding: 15px; border-radius: 6px; border-left: 4px solid " . ($summary['total_errors'] > 0 ? '#dc2626' : '#6c757d') . ";'>
                    <div style='font-size: 12px; color: " . ($summary['total_errors'] > 0 ? '#991b1b' : '#6c757d') . "; text-transform: uppercase; font-weight: bold;'>Errori</div>
                    <div style='font-size: 32px; font-weight: bold; color: " . ($summary['total_errors'] > 0 ? '#dc2626' : '#6c757d') . ";'>{$summary['total_errors']}</div>
                </div>
            </div>
        </div>";
        
        // Dettaglio per utente se disponibile
        if (!empty($details['users'])) {
            $content .= "
            <div class='card'>
                <h3>👥 Dettaglio per Utente</h3>";
            
            foreach ($details['users'] as $userDetail) {
                $userStatus = $userDetail['status'] ?? 'unknown';
                $userColor = $userStatus === 'success' ? '#10b981' : ($userStatus === 'skipped' ? '#eab308' : '#dc2626');
                $userIcon = $userStatus === 'success' ? '✓' : ($userStatus === 'skipped' ? '⊘' : '✗');
                
                $content .= "
                <div style='background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 6px; border-left: 4px solid {$userColor};'>
                    <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;'>
                        <h4 style='margin: 0; color: #343a40;'>{$userIcon} User {$userDetail['user_id']} - {$userDetail['user_name']}</h4>
                        <span style='background: {$userColor}; color: white; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: bold;'>" . strtoupper($userStatus) . "</span>
                    </div>
                    <div style='display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;'>
                        <div style='background: white; padding: 10px; border-radius: 4px; text-align: center;'>
                            <div style='font-size: 11px; color: #6c757d;'>Synced</div>
                            <div style='font-size: 20px; font-weight: bold; color: #10b981;'>{$userDetail['synced']}</div>
                        </div>
                        <div style='background: white; padding: 10px; border-radius: 4px; text-align: center;'>
                            <div style='font-size: 11px; color: #6c757d;'>Skipped</div>
                            <div style='font-size: 20px; font-weight: bold; color: #6c757d;'>{$userDetail['skipped']}</div>
                        </div>
                        <div style='background: white; padding: 10px; border-radius: 4px; text-align: center;'>
                            <div style='font-size: 11px; color: #6c757d;'>Partial</div>
                            <div style='font-size: 20px; font-weight: bold; color: #f97316;'>{$userDetail['partial']}</div>
                        </div>
                        <div style='background: white; padding: 10px; border-radius: 4px; text-align: center;'>
                            <div style='font-size: 11px; color: #6c757d;'>API Calls</div>
                            <div style='font-size: 20px; font-weight: bold; color: #17a2b8;'>{$userDetail['api_calls']}</div>
                        </div>
                    </div>";
                
                if (!empty($userDetail['reason'])) {
                    $content .= "
                    <div style='margin-top: 10px; padding: 8px; background: #fff3cd; border-radius: 4px; font-size: 12px; color: #856404;'>
                        ℹ️ {$userDetail['reason']}
                    </div>";
                }
                
                $content .= "</div>";
            }
            
            $content .= "</div>";
        }
        
        // Errori se presenti
        if ($summary['users_failed'] > 0 && !empty($details['errors'])) {
            $content .= "
            <div class='card'>
                <h4 style='color: #dc2626;'>🚨 Errori Rilevati</h4>";
            
            foreach ($details['errors'] as $error) {
                $content .= "
                <div class='error-details' style='margin: 10px 0;'>
                    <strong>User {$error['user_id']}:</strong> {$error['message']}
                </div>";
            }
            
            $content .= "</div>";
        }
        
        $content .= "
        <div class='card'>
            <h4>🔧 Azioni Consigliate</h4>
            <ul>
                <li>Verificare spedizioni parziali in dashboard Inbound</li>
                <li>Controllare log sync per eventuali loop detection</li>
                <li>Monitorare consumo API calls (limite giornaliero)</li>
                <li>Validare fingerprint per spedizioni skipped</li>
            </ul>
        </div>";
        
        $htmlBody = str_replace('{{CONTENT}}', $content, $template);
        
        $result = inviaEmailSMTP(
            self::ADMIN_EMAIL,
            "📦 Inbound Sync Completato - Health: {$healthScore}/100 {$healthEmoji}",
            $htmlBody
        );
        
        CentralLogger::log('admin_notifications', $result ? 'INFO' : 'ERROR', 
            "Inbound cron completion notification " . ($result ? 'sent' : 'failed'), [
                'summary' => $summary,
                'health_score' => $healthScore
            ]);
        
        return $result;
    }
    
    /**
     * Notifica fallimento cron inventory
     */
    public static function notifyInventoryCronFailure($error, $context = []) {
        if (!self::checkCooldown('inventory_failure', date('Y-m-d-H'))) {
            return false;
        }
        
        $template = self::getEmailTemplate('Alert Fallimento Inventory Cron', 'error');
        
        $content = "
        <div class='card'>
            <h3>📦 Errore Inventory Analysis</h3>
            <p><strong>Timestamp:</strong> " . date('d/m/Y H:i:s') . "</p>
            <p><strong>Processo:</strong> cron_inventory_previsync.php</p>
            </div>
            
        <div class='card'>
            <h4>🔍 Dettagli Errore</h4>
            <div class='error-details'>{$error}</div>
        </div>";
        
        if (!empty($context)) {
            $content .= "
            <div class='card'>
                <h4>📋 Contesto</h4>
                <div class='error-details'>" . json_encode($context, JSON_PRETTY_PRINT) . "</div>
            </div>";
        }
        
        $content .= "
        <div class='card'>
            <h4>🔧 Azioni Suggerite</h4>
            <ul>
                <li>Verificare queue inventory_report_queue</li>
                <li>Controllare memoria e timeout script</li>
                <li>Verificare log inventory per dettagli</li>
                <li>Testare inventory_sync.php manualmente</li>
                <li>Controllare connessione Amazon API</li>
            </ul>
        </div>";
        
        $htmlBody = str_replace('{{CONTENT}}', $content, $template);
        
        $result = inviaEmailSMTP(
            self::ADMIN_EMAIL,
            "🚨 ALERT: Errore Inventory Cron",
            $htmlBody
        );
        
        CentralLogger::log('admin_notifications', $result ? 'INFO' : 'ERROR', 
            "Inventory cron failure notification " . ($result ? 'sent' : 'failed'), [
                'error' => $error,
                'context' => $context
            ]);
        
        return $result;
    }
    
    /**
     * Get Inbound Module Breakdown (ultime 24h)
     */
    public static function getInboundBreakdown() {
        try {
            $pdo = getDbConnection();
            
            $stats = [
                'total_shipments' => 0,
                'total_items' => 0,
                'total_boxes' => 0,
                'shipments_by_status' => [],
                'users_with_data' => 0,
                'last_sync' => null,
                'partial_shipments' => 0,
                'manual_shipments' => 0,
                'api_calls_24h' => 0
            ];
            
            // Count shipments by status
            $stmt = $pdo->query("
                SELECT 
                    COUNT(DISTINCT s.id) as total_shipments,
                    COUNT(DISTINCT CASE WHEN s.shipment_status = 'MANUAL' THEN s.id END) as manual_count,
                    COUNT(DISTINCT CASE WHEN ss.sync_status IN ('partial_loop', 'partial_no_progress') THEN s.id END) as partial_count,
                    COUNT(DISTINCT i.id) as total_items,
                    COUNT(DISTINCT b.id) as total_boxes,
                    COUNT(DISTINCT s.user_id) as users_count,
                    MAX(s.last_sync_at) as last_sync
                FROM inbound_shipments s
                LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
                LEFT JOIN inbound_shipment_items i ON i.shipment_id = s.id
                LEFT JOIN inbound_shipment_boxes b ON b.shipment_id = s.id
                WHERE s.last_sync_at >= NOW() - INTERVAL 24 HOUR
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $stats['total_shipments'] = (int)$result['total_shipments'];
                $stats['total_items'] = (int)$result['total_items'];
                $stats['total_boxes'] = (int)$result['total_boxes'];
                $stats['users_with_data'] = (int)$result['users_count'];
                $stats['last_sync'] = $result['last_sync'];
                $stats['partial_shipments'] = (int)$result['partial_count'];
                $stats['manual_shipments'] = (int)$result['manual_count'];
            }
            
            // Count by shipment status
            $stmt = $pdo->query("
                SELECT 
                    shipment_status,
                    COUNT(*) as count
                FROM inbound_shipments
                WHERE last_sync_at >= NOW() - INTERVAL 24 HOUR
                GROUP BY shipment_status
                ORDER BY count DESC
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stats['shipments_by_status'][$row['shipment_status']] = (int)$row['count'];
            }
            
            // API calls from logs (ultime 24h)
            $stmt = $pdo->query("
                SELECT COUNT(*) as api_calls
                FROM sync_debug_logs
                WHERE operation_type = 'inventory'
                  AND message LIKE 'INBOUND%'
                  AND created_at >= NOW() - INTERVAL 24 HOUR
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['api_calls_24h'] = (int)($result['api_calls'] ?? 0);
            
            return $stats;
            
        } catch (Exception $e) {
            CentralLogger::log('admin_notifications', 'ERROR', 
                "Error getting Inbound breakdown: " . $e->getMessage());
            return [
                'total_shipments' => 0,
                'total_items' => 0,
                'total_boxes' => 0,
                'shipments_by_status' => [],
                'users_with_data' => 0,
                'last_sync' => null,
                'partial_shipments' => 0,
                'manual_shipments' => 0,
                'api_calls_24h' => 0
            ];
        }
    }
    
    /**
     * Analizza log giornalieri e estrae metriche
     */
    public static function analyzeDailyLogs() {
        $logFiles = [
            'email_notifications' => LOG_BASE_DIR . 'email_notifications.log',
            'settlement_sync' => LOG_BASE_DIR . 'cron_settlement_margynomic.log', 
            'inventory_sync' => LOG_BASE_DIR . 'cron_inventory_previsync.log',
            'system_errors' => LOG_BASE_DIR . 'system_errors.log'
        ];
        
        $metrics = [
            'total_operations' => 0,
            'successful_operations' => 0,
            'failed_operations' => 0,
            'users_processed' => [],
            'emails_sent' => 0,
            'emails_failed' => 0,
            'settlement_records' => 0,
            'inventory_products' => 0,
            'critical_errors' => [],
            'last_24h_summary' => [],
            // Nuove metriche dettagliate per modulo
            'inventory_details' => [
                'fba_success' => 0,
                'fbm_success' => 0,
                'users_total' => 0,
                'users_completed' => 0,
                'users_failed' => [],
                'rows_imported' => 0,
                'avg_duration_seconds' => 0
            ],
            'settlement_details' => [
                'users_synced' => 0,
                'period_start' => null,
                'period_end' => null,
                'total_rows' => 0,
                'total_fees' => 0,
                'coverage_percentage' => 0
            ],
            'easyship_details' => [
                'draft' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'total_boxes' => 0,
                'emails_sent' => 0
            ]
        ];
        
        $yesterday = date('Y-m-d', strtotime('-1 day'));
            $today = date('Y-m-d');
            
        foreach ($logFiles as $type => $logFile) {
            if (!file_exists($logFile)) {
                continue;
            }
            
            try {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                
                foreach ($lines as $line) {
                    // Parse JSON log entries
                    $logEntry = json_decode($line, true);
                    if (!$logEntry) continue;
                    
                    $logDate = date('Y-m-d', strtotime($logEntry['timestamp'] ?? ''));
                    
                    // Solo log delle ultime 24 ore
                    if ($logDate !== $yesterday && $logDate !== $today) {
                        continue;
                    }
                    
                    $metrics['total_operations']++;
                    
                    // Analizza per tipo
                    $level = $logEntry['level'] ?? 'INFO';
                    $message = $logEntry['message'] ?? '';
                    $context = $logEntry['context'] ?? [];
                    
                    // Conteggi successi/errori
                    if (in_array($level, ['ERROR', 'CRITICAL'])) {
                        $metrics['failed_operations']++;
                        
                        if ($level === 'CRITICAL') {
                            $metrics['critical_errors'][] = [
                                'timestamp' => $logEntry['timestamp'],
                                'message' => $message,
                                'type' => $type
                            ];
                        }
                    } else {
                        $metrics['successful_operations']++;
                    }
                    
                    // Estrai metriche specifiche
                    if (isset($context['user_id']) && $context['user_id'] > 0) {
                        $metrics['users_processed'][] = $context['user_id'];
                    }
                    
                    if ($type === 'email_notifications') {
                        if (strpos($message, 'Email inviata con successo') !== false) {
                            $metrics['emails_sent']++;
                        } elseif (strpos($message, 'Errore invio email') !== false) {
                            $metrics['emails_failed']++;
                        }
                    }
                    
                    if ($type === 'settlement_sync' && isset($context['settlement_reports'])) {
                        $metrics['settlement_records'] += (int)$context['settlement_reports'];
                    }
                    
                    if ($type === 'inventory_sync' && isset($context['products_count'])) {
                        $metrics['inventory_products'] += (int)$context['products_count'];
                    }
                }
            
        } catch (Exception $e) {
                CentralLogger::log('admin_notifications', 'ERROR', 
                    "Errore analisi log {$type}: " . $e->getMessage());
            }
        }
        
        // Deduplica utenti processati
        $metrics['users_processed'] = array_unique($metrics['users_processed']);
        $metrics['unique_users_count'] = count($metrics['users_processed']);
        
        // Calcola tasso successo
        $metrics['success_rate'] = $metrics['total_operations'] > 0 
            ? round(($metrics['successful_operations'] / $metrics['total_operations']) * 100, 1)
            : 0;
        
        // === QUERY DETTAGLI INVENTORY ===
        try {
            $pdo = getDbConnection();
            
            // Conta utenti totali attivi
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT user_id) as total
                FROM amazon_client_tokens 
                WHERE is_active = 1
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['inventory_details']['users_total'] = $result['total'] ?? 0;
            
            // Analizza inventory_report_queue
            $stmt = $pdo->query("
                SELECT 
                    user_id,
                    status,
                    error_message,
                    report_id,
                    updated_at
                FROM inventory_report_queue
                WHERE updated_at >= NOW() - INTERVAL 24 HOUR
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['status'] === 'completed') {
                    $metrics['inventory_details']['users_completed']++;
                    
                    // Determina FBA/FBM dal report_id
                    if (!empty($row['report_id'])) {
                        $reportIds = explode(',', $row['report_id']);
                        if (count($reportIds) > 1) {
                            // Dual sync
                            $metrics['inventory_details']['fba_success']++;
                            $metrics['inventory_details']['fbm_success']++;
                        } else {
                            $metrics['inventory_details']['fba_success']++;
                        }
                    }
                } elseif ($row['status'] === 'failed') {
                    $metrics['inventory_details']['users_failed'][] = [
                        'user_id' => $row['user_id'],
                        'error' => $row['error_message'] ?? 'Unknown error',
                        'timestamp' => $row['updated_at']
                    ];
                }
            }
            
            // Conta righe importate da sync_debug_logs (FBA + FBM)
            $stmt = $pdo->query("
SELECT SUM(
    CASE 
        WHEN JSON_VALID(context_data) AND JSON_EXTRACT(context_data, '$.processed_rows') IS NOT NULL
        THEN CAST(JSON_EXTRACT(context_data, '$.processed_rows') AS UNSIGNED)
        ELSE 0 
    END
) as total_rows,
COUNT(*) as log_entries
FROM sync_debug_logs
WHERE operation_type IN ('inventory_file_processed', 'inventory_fbm_file_processed')
  AND created_at >= NOW() - INTERVAL 24 HOUR
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['inventory_details']['rows_imported'] = $result['total_rows'] ?? 0;
            
            // Log diagnostico se nessun log trovato
            if (($result['log_entries'] ?? 0) === 0) {
                CentralLogger::log('admin_notifications', 'WARNING', 
                    "Nessun log inventory_file_processed nelle ultime 24h - possibile problema sync");
            }
            
        } catch (Exception $e) {
            CentralLogger::log('admin_notifications', 'ERROR', 
                "Error getting inventory details: " . $e->getMessage());
        }
        
        // === QUERY DETTAGLI SETTLEMENT ===
        try {
            // Query tutte le tabelle report_settlement_X per utenti attivi
            $stmt = $pdo->query("SELECT id FROM users WHERE is_active = 1");
            $activeUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($activeUsers as $userId) {
                $tableName = "report_settlement_{$userId}";
                
                // Verifica esistenza tabella usando INFORMATION_SCHEMA (sicuro con prepared statements)
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ?
                ");
                $stmt->execute([$tableName]);
                $tableExists = $stmt->fetchColumn() > 0;
                if (!$tableExists) continue;
                
                // Conta righe importate oggi
                $stmt = $pdo->query("
                    SELECT 
                        COUNT(*) as rows_today,
                        MIN(posted_date) as period_start,
                        MAX(posted_date) as period_end,
                        SUM(CASE 
                            WHEN transaction_type LIKE '%Fee%' 
                            THEN ABS(total_amount) 
                            ELSE 0 
                        END) as total_fees
                    FROM {$tableName}
                    WHERE date_uploaded >= NOW() - INTERVAL 24 HOUR
                ");
                
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && $result['rows_today'] > 0) {
                    $metrics['settlement_details']['users_synced']++;
                    $metrics['settlement_details']['total_rows'] += $result['rows_today'];
                    $metrics['settlement_details']['total_fees'] += $result['total_fees'] ?? 0;
                    
                    // Aggiorna periodo se più ampio
                    if (!$metrics['settlement_details']['period_start'] || 
                        $result['period_start'] < $metrics['settlement_details']['period_start']) {
                        $metrics['settlement_details']['period_start'] = $result['period_start'];
                    }
                    if (!$metrics['settlement_details']['period_end'] || 
                        $result['period_end'] > $metrics['settlement_details']['period_end']) {
                        $metrics['settlement_details']['period_end'] = $result['period_end'];
                    }
                }
            }
            
            // Calcola coverage percentage
            if ($metrics['inventory_details']['users_total'] > 0) {
                $metrics['settlement_details']['coverage_percentage'] = round(
                    ($metrics['settlement_details']['users_synced'] / $metrics['inventory_details']['users_total']) * 100
                );
            }
            
        } catch (Exception $e) {
            CentralLogger::log('admin_notifications', 'ERROR', 
                "Error getting settlement details: " . $e->getMessage());
        }
        
        // === QUERY DETTAGLI EASYSHIP ===
        try {
            // Conta spedizioni per status oggi
            $stmt = $pdo->query("
                SELECT status, COUNT(*) as count
                FROM shipments
                WHERE DATE(created_at) = CURDATE()
                GROUP BY status
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['status'] === 'Draft') {
                    $metrics['easyship_details']['draft'] = $row['count'];
                } elseif ($row['status'] === 'Completed') {
                    $metrics['easyship_details']['completed'] = $row['count'];
                } elseif ($row['status'] === 'Cancelled') {
                    $metrics['easyship_details']['cancelled'] = $row['count'];
                }
            }
            
            // Conta totale box oggi
            $stmt = $pdo->query("
                SELECT COUNT(*) as total_boxes
                FROM shipment_boxes sb
                INNER JOIN shipments s ON sb.shipment_id = s.id
                WHERE DATE(s.created_at) = CURDATE()
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['easyship_details']['total_boxes'] = $result['total_boxes'] ?? 0;
            
        } catch (Exception $e) {
            CentralLogger::log('admin_notifications', 'ERROR', 
                "Error getting easyship details: " . $e->getMessage());
        }
        
        return $metrics;
    }
    
    /**
     * Invia report giornaliero completo - VERSION 2.0
     * Features: User Breakdown, API Stats, Database Stats, Trend Analysis
     */
    public static function sendDailyReport() {
        // Verifica cooldown giornaliero
        if (!self::checkCooldown('daily_report', date('Y-m-d'))) {
            return false;
        }
        
        // === RACCOGLI TUTTE LE METRICHE ===
        $metrics = self::analyzeDailyLogs();
        $inventoryBreakdown = DailyReportHelpers::getInventoryUserBreakdown();
        $settlementBreakdown = DailyReportHelpers::getSettlementUserBreakdown();
        $inboundBreakdown = self::getInboundBreakdown();
        $apiMetrics = DailyReportHelpers::getApiMetrics();
        $databaseStats = DailyReportHelpers::getDatabaseStats();
        $trends = DailyReportHelpers::getTrendAnalysis();
        
        // === SALVA SNAPSHOT GIORNALIERO PER TREND FUTURI ===
        DailyReportHelpers::saveDailySnapshot('inventory', 'products_count', 
            array_sum(array_column($inventoryBreakdown, 'fba_products')) + array_sum(array_column($inventoryBreakdown, 'fbm_products')),
            ['users' => count($inventoryBreakdown)]
        );
        DailyReportHelpers::saveDailySnapshot('settlement', 'reports_count',
            array_sum(array_column($settlementBreakdown, 'reports_count')),
            ['users' => count($settlementBreakdown)]
        );
        DailyReportHelpers::saveDailySnapshot('api', 'total_calls', $apiMetrics['total_calls']);
        DailyReportHelpers::saveDailySnapshot('database', 'total_size_mb', $databaseStats['total_size_mb']);
        
        $metrics = self::analyzeDailyLogs();
        
        // === CALCOLA HEALTH SCORE PER MODULO ===
        $inventoryHealth = self::calculateModuleHealth(
            $metrics['inventory_details']['users_completed'],
            $metrics['inventory_details']['users_total'],
            count($metrics['inventory_details']['users_failed'])
        );
        
        $settlementHealth = self::calculateModuleHealth(
            $metrics['settlement_details']['users_synced'],
            $metrics['inventory_details']['users_total'],
            0 // Settlement raramente ha errori
        );
        
        $easyshipTotal = $metrics['easyship_details']['draft'] + $metrics['easyship_details']['completed'];
        $easyshipHealth = self::calculateModuleHealth(
            $metrics['easyship_details']['completed'],
            $easyshipTotal > 0 ? $easyshipTotal : 1,
            $metrics['easyship_details']['cancelled']
        );
        
        // Inbound health
        $inboundTotal = $inboundBreakdown['total_shipments'];
        $inboundComplete = $inboundTotal - $inboundBreakdown['partial_shipments'];
        $inboundHealth = self::calculateModuleHealth(
            $inboundComplete,
            $inboundTotal > 0 ? $inboundTotal : 1,
            $inboundBreakdown['partial_shipments']
        );
        
        // Health globale (pesato per importanza moduli)
        $globalHealth = round(
            ($inventoryHealth * 0.30) + 
            ($settlementHealth * 0.30) + 
            ($inboundHealth * 0.25) + 
            ($easyshipHealth * 0.15)
        );
        
        // Salva health history per trend analysis
        self::saveHealthHistory('inventory', $inventoryHealth, $metrics['inventory_details']['users_completed'], 
            count($metrics['inventory_details']['users_failed']), $metrics['inventory_details']);
        self::saveHealthHistory('settlement', $settlementHealth, $metrics['settlement_details']['users_synced'], 
            0, $metrics['settlement_details']);
        self::saveHealthHistory('inbound', $inboundHealth, $inboundBreakdown['users_with_data'], 
            $inboundBreakdown['partial_shipments'], $inboundBreakdown);
        self::saveHealthHistory('easyship', $easyshipHealth, $easyshipTotal, 
            $metrics['easyship_details']['cancelled'], $metrics['easyship_details']);
        
        // Determina colore e status
        $healthEmoji = $globalHealth >= 90 ? '🟢' : ($globalHealth >= 70 ? '🟡' : '🔴');
        $healthStatus = $globalHealth >= 90 ? 'Sistema Operativo' : ($globalHealth >= 70 ? 'Attenzione Richiesta' : 'Intervento Urgente');
        $healthColor = $globalHealth >= 90 ? '#10b981' : ($globalHealth >= 70 ? '#f59e0b' : '#ef4444');
        $healthColorDark = $globalHealth >= 90 ? '#059669' : ($globalHealth >= 70 ? '#d97706' : '#dc2626');
        
        $template = self::getEmailTemplate('Report Giornaliero Margynomic', 'info');
        
        // === EXECUTIVE SUMMARY ===
        $content = "
        <div class='executive-summary' style='background: linear-gradient(135deg, {$healthColor} 0%, {$healthColorDark} 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
            <h2 style='margin: 0 0 10px 0; font-size: 20px; font-weight: 500;'>{$healthEmoji} {$healthStatus}</h2>
            <h1 style='margin: 0; font-size: 48px; font-weight: bold;'>Health Score: {$globalHealth}/100</h1>
            <p style='margin: 15px 0 0 0; opacity: 0.9; font-size: 14px;'>Report generato: " . date('d/m/Y H:i') . "</p>
        </div>";
        
        // === USER BREAKDOWN - PRIORITY 1 ===
        $content .= self::buildUserBreakdownSection($inventoryBreakdown, $settlementBreakdown);
        
        // === INVENTORY MODULE SUMMARY ===
        $content .= "
        <!-- INVENTORY MODULE -->
        <div class='card' style='background: #f8f9fa; border-left: 4px solid " . ($inventoryHealth >= 90 ? '#10b981' : ($inventoryHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; padding: 20px; margin: 20px 0; border-radius: 8px;'>
            <h3 style='margin: 0 0 15px 0; display: flex; justify-content: space-between; align-items: center;'>
                <span>📦 Inventory Module</span>
                <span style='background: " . ($inventoryHealth >= 90 ? '#10b981' : ($inventoryHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px;'>Health: {$inventoryHealth}/100</span>
            </h3>
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 15px;'>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Utenti Processati</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['inventory_details']['users_completed']}/{$metrics['inventory_details']['users_total']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>FBA Success</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['inventory_details']['fba_success']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>FBM Success</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['inventory_details']['fbm_success']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Righe Importate</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>" . number_format($metrics['inventory_details']['rows_imported']) . "</div>
                </div>
            </div>";
        
        // Mostra errori inventory se presenti
        if (count($metrics['inventory_details']['users_failed']) > 0) {
            $content .= "
            <div style='margin-top: 15px; padding: 15px; background: #fee; border-left: 4px solid #dc2626; border-radius: 6px;'>
                <strong style='color: #991b1b;'>⚠️ Utenti con Errori:</strong><br>
                <ul style='margin: 10px 0 0 0; padding-left: 20px;'>";
            foreach ($metrics['inventory_details']['users_failed'] as $failed) {
                $content .= "<li style='margin: 5px 0; color: #dc2626;'><strong>User {$failed['user_id']}:</strong> {$failed['error']}</li>";
            }
            $content .= "</ul>
            </div>";
        }
        
        $content .= "</div>
        
        <!-- SETTLEMENT MODULE -->
        <div class='card' style='background: #f8f9fa; border-left: 4px solid " . ($settlementHealth >= 90 ? '#10b981' : ($settlementHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; padding: 20px; margin: 20px 0; border-radius: 8px;'>
            <h3 style='margin: 0 0 15px 0; display: flex; justify-content: space-between; align-items: center;'>
                <span>💰 Settlement Module</span>
                <span style='background: " . ($settlementHealth >= 90 ? '#10b981' : ($settlementHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px;'>Health: {$settlementHealth}/100</span>
            </h3>
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 15px;'>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Utenti Sincronizzati</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['settlement_details']['users_synced']}</div>
                    <div style='font-size: 11px; color: #6c757d; margin-top: 3px;'>Coverage: {$metrics['settlement_details']['coverage_percentage']}%</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Righe Importate</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>" . number_format($metrics['settlement_details']['total_rows']) . "</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px; grid-column: span 2;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Periodo Analizzato</strong>
                    <div style='font-size: 16px; font-weight: bold; color: #343a40;'>" . 
                    ($metrics['settlement_details']['period_start'] 
                        ? date('d/m/Y', strtotime($metrics['settlement_details']['period_start'])) . ' - ' . date('d/m/Y', strtotime($metrics['settlement_details']['period_end'])) 
                        : 'Nessun dato oggi') . "
                    </div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px; grid-column: span 2;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Fee Totali Parsate</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>€" . number_format($metrics['settlement_details']['total_fees'], 2) . "</div>
                </div>
            </div>
        </div>";
        
        // === API PERFORMANCE ===
        $content .= self::buildApiStatsSection($apiMetrics);
        
        // === DATABASE STATS ===
        $content .= self::buildDatabaseStatsSection($databaseStats);
        
        // === TREND ANALYSIS ===
        if (!empty($trends['inventory'])) {
            $content .= self::buildTrendAnalysisSection($trends);
        }
        
        // === INBOUND MODULE ===
        $content .= "
        <!-- INBOUND MODULE -->
        <div class='card' style='background: #f8f9fa; border-left: 4px solid " . ($inboundHealth >= 90 ? '#10b981' : ($inboundHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; padding: 20px; margin: 20px 0; border-radius: 8px;'>
            <h3 style='margin: 0 0 15px 0; display: flex; justify-content: space-between; align-items: center;'>
                <span>📦 Inbound Shipments Module</span>
                <span style='background: " . ($inboundHealth >= 90 ? '#10b981' : ($inboundHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px;'>Health: {$inboundHealth}/100</span>
            </h3>
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 15px;'>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Spedizioni (24h)</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$inboundBreakdown['total_shipments']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Items</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>" . number_format($inboundBreakdown['total_items']) . "</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Boxes</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>" . number_format($inboundBreakdown['total_boxes']) . "</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Utenti Attivi</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$inboundBreakdown['users_with_data']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Parziali</strong>
                    <div style='font-size: 24px; font-weight: bold; color: " . ($inboundBreakdown['partial_shipments'] > 0 ? '#f97316' : '#6c757d') . ";'>{$inboundBreakdown['partial_shipments']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Manual</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #6c757d;'>{$inboundBreakdown['manual_shipments']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px; grid-column: span 2;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>API Calls (24h)</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #17a2b8;'>{$inboundBreakdown['api_calls_24h']}</div>
                </div>";
        
        // Status breakdown
        if (!empty($inboundBreakdown['shipments_by_status'])) {
            $content .= "
                <div style='background: white; padding: 12px; border-radius: 6px; grid-column: span 2;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>By Status</strong>
                    <div style='display: flex; gap: 10px; margin-top: 8px; flex-wrap: wrap;'>";
            
            foreach ($inboundBreakdown['shipments_by_status'] as $status => $count) {
                $statusColor = match($status) {
                    'CLOSED' => '#6c757d',
                    'WORKING' => '#17a2b8',
                    'SHIPPED' => '#10b981',
                    'RECEIVING' => '#f59e0b',
                    'MANUAL' => '#8b5cf6',
                    default => '#343a40'
                };
                
                $content .= "
                        <div style='background: #f8f9fa; padding: 6px 12px; border-radius: 4px; border-left: 3px solid {$statusColor};'>
                            <span style='font-size: 11px; color: #6c757d;'>{$status}</span>
                            <span style='font-size: 16px; font-weight: bold; color: #343a40; margin-left: 8px;'>{$count}</span>
                        </div>";
            }
            
            $content .= "
                    </div>
                </div>";
        }
        
        $content .= "
            </div>";
        
        // Last sync info
        if ($inboundBreakdown['last_sync']) {
            $content .= "
            <div style='margin-top: 15px; padding: 10px; background: #e0f2fe; border-radius: 6px; font-size: 12px; color: #0369a1;'>
                ℹ️ Ultimo sync: " . date('d/m/Y H:i', strtotime($inboundBreakdown['last_sync'])) . "
            </div>";
        }
        
        $content .= "</div>";
        
        // === EASYSHIP MODULE ===
        $content .= "
        <!-- EASYSHIP MODULE -->
        <div class='card' style='background: #f8f9fa; border-left: 4px solid " . ($easyshipHealth >= 90 ? '#10b981' : ($easyshipHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; padding: 20px; margin: 20px 0; border-radius: 8px;'>
            <h3 style='margin: 0 0 15px 0; display: flex; justify-content: space-between; align-items: center;'>
                <span>🚚 EasyShip Module</span>
                <span style='background: " . ($easyshipHealth >= 90 ? '#10b981' : ($easyshipHealth >= 70 ? '#f59e0b' : '#ef4444')) . "; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px;'>Health: {$easyshipHealth}/100</span>
            </h3>
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 15px;'>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Spedizioni Draft</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['easyship_details']['draft']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Spedizioni Completate</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #10b981;'>{$metrics['easyship_details']['completed']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Spedizioni Annullate</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #dc2626;'>{$metrics['easyship_details']['cancelled']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Box Gestiti</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['easyship_details']['total_boxes']}</div>
                </div>
            </div>
        </div>
        
        <!-- RIEPILOGO OPERAZIONI LEGACY -->
        <div class='card' style='background: #f8f9fa; border-left: 4px solid #17a2b8; padding: 20px; margin: 20px 0; border-radius: 8px;'>
            <h3 style='margin: 0 0 15px 0;'>📊 Riepilogo Operazioni Ultime 24h</h3>
            <p style='margin: 0 0 15px 0; color: #6c757d;'><strong>Periodo:</strong> " . date('d/m/Y H:i', strtotime('-24 hours')) . " - " . date('d/m/Y H:i') . "</p>
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;'>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Operazioni Totali</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['total_operations']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Tasso Successo</strong>
                    <div style='font-size: 24px; font-weight: bold; color: " . ($metrics['success_rate'] >= 95 ? '#10b981' : ($metrics['success_rate'] >= 80 ? '#f59e0b' : '#ef4444')) . ";'>{$metrics['success_rate']}%</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Email Inviate</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['emails_sent']}</div>
                </div>
                <div style='background: white; padding: 12px; border-radius: 6px;'>
                    <strong style='color: #6c757d; font-size: 12px; text-transform: uppercase;'>Utenti Unici</strong>
                    <div style='font-size: 24px; font-weight: bold; color: #343a40;'>{$metrics['unique_users_count']}</div>
                </div>
            </div>
        </div>";
        
        // Errori critici se presenti
        if (!empty($metrics['critical_errors'])) {
            $content .= "
            <div class='card'>
                <h4>🚨 Errori Critici</h4>";
            
            foreach ($metrics['critical_errors'] as $error) {
                $content .= "
                <div class='error-details'>
                    <strong>[{$error['timestamp']}]</strong> {$error['type']}: {$error['message']}
                </div>";
            }
            
            $content .= "</div>";
        }
        
        // Email fallite se presenti
        if ($metrics['emails_failed'] > 0) {
            $content .= "
            <div class='card'>
                <h4>⚠️ Email Fallite</h4>
                <p>Sono stati rilevati <strong>{$metrics['emails_failed']}</strong> fallimenti nell'invio email.</p>
                <p>Verificare configurazione SMTP e log dettagliati.</p>
            </div>";
        }
        
        $content .= "
        <div class='card'>
            <h4>🔧 Azioni Consigliate</h4>
            <ul>
                <li>Monitorare tasso successo operazioni (target: >95%)</li>
                <li>Verificare regolarità processamento utenti</li>
                <li>Controllare log per pattern di errori ricorrenti</li>
                <li>Validare performance sistema nelle ore di picco</li>
            </ul>
        </div>";
        
        $htmlBody = str_replace('{{CONTENT}}', $content, $template);
        
        $result = inviaEmailSMTP(
            self::ADMIN_EMAIL,
            "📊 Report Giornaliero Margynomic - Health: {$globalHealth}/100 {$healthEmoji}",
            $htmlBody
        );
        
        // Salva log notifica in database
        if ($result) {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("
                    INSERT INTO admin_notifications_log 
                    (report_type, sent_at, recipient, health_score, summary, status)
                    VALUES ('daily', NOW(), ?, ?, ?, 'sent')
                ");
                
                $summary = [
                    'global_health' => $globalHealth,
                    'inventory_health' => $inventoryHealth,
                    'settlement_health' => $settlementHealth,
                    'easyship_health' => $easyshipHealth,
                    'users_total' => $metrics['inventory_details']['users_total'],
                    'users_completed' => $metrics['inventory_details']['users_completed'],
                    'errors_count' => count($metrics['inventory_details']['users_failed'])
                ];
                
                $stmt->execute([
                    self::ADMIN_EMAIL,
                    $globalHealth,
                    json_encode($summary)
                ]);
                
            } catch (Exception $e) {
                CentralLogger::log('admin_notifications', 'WARNING', 
                    "Failed to save notification log: " . $e->getMessage());
            }
        }
        
        // Log rimosso - già loggato in cron_daily_report.php
        
        return $result;
    }
    
    /**
     * Build User Breakdown Section (PRIORITY 1)
     */
    private static function buildUserBreakdownSection($inventoryBreakdown, $settlementBreakdown) {
        $html = "
        <div class='card' style='background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
            <h3 style='margin: 0 0 20px 0; color: #343a40; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;'>
                👥 User Breakdown Dettagliato
            </h3>";
        
        foreach ($inventoryBreakdown as $userInventory) {
            $userId = $userInventory['user_id'];
            $statusBadge = $userInventory['status'] === 'success' 
                ? "<span style='background: #10b981; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;'>✓ OK</span>"
                : "<span style='background: #f59e0b; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;'>⚠ WARNING</span>";
            
            // Check if user has settlement data
            $userSettlement = null;
            foreach ($settlementBreakdown as $settlement) {
                if ($settlement['user_id'] == $userId) {
                    $userSettlement = $settlement;
                    break;
                }
            }
            
            $html .= "
            <div style='background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid " . ($userInventory['status'] === 'success' ? '#10b981' : '#f59e0b') . ";'>
                <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;'>
                    <h4 style='margin: 0; color: #343a40; font-size: 16px;'>
                        User {$userId} <span style='color: #6c757d; font-size: 13px; font-weight: normal;'>({$userInventory['marketplace_id']})</span>
                    </h4>
                    {$statusBadge}
                </div>
                
                <!-- INVENTORY INFO -->
                <div style='display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 10px 0;'>
                    <div style='background: white; padding: 8px; border-radius: 4px; text-align: center;'>
                        <div style='font-size: 11px; color: #6c757d; text-transform: uppercase;'>FBA</div>
                        <div style='font-size: 18px; font-weight: bold; color: " . ($userInventory['fba_success'] ? '#10b981' : '#dc2626') . ";'>" . 
                            ($userInventory['fba_success'] ? $userInventory['fba_products'] : '✗') . 
                        "</div>
                    </div>
                    <div style='background: white; padding: 8px; border-radius: 4px; text-align: center;'>
                        <div style='font-size: 11px; color: #6c757d; text-transform: uppercase;'>FBM</div>
                        <div style='font-size: 18px; font-weight: bold; color: " . ($userInventory['fbm_success'] ? '#10b981' : '#dc2626') . ";'>" . 
                            ($userInventory['fbm_success'] ? $userInventory['fbm_products'] : '✗') . 
                        "</div>
                    </div>
                    <div style='background: white; padding: 8px; border-radius: 4px; text-align: center;'>
                        <div style='font-size: 11px; color: #6c757d; text-transform: uppercase;'>Rows</div>
                        <div style='font-size: 18px; font-weight: bold; color: #343a40;'>" . number_format($userInventory['total_rows']) . "</div>
                    </div>
                    <div style='background: white; padding: 8px; border-radius: 4px; text-align: center;'>
                        <div style='font-size: 11px; color: #6c757d; text-transform: uppercase;'>Time</div>
                        <div style='font-size: 18px; font-weight: bold; color: #343a40;'>{$userInventory['execution_time']}s</div>
                    </div>
                </div>";
            
            // SETTLEMENT INFO if available
            if ($userSettlement) {
                $html .= "
                <div style='margin-top: 10px; padding-top: 10px; border-top: 1px solid #dee2e6;'>
                    <div style='display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;'>
                        <div style='background: white; padding: 8px; border-radius: 4px;'>
                            <div style='font-size: 11px; color: #6c757d;'>Settlement Reports</div>
                            <div style='font-size: 16px; font-weight: bold; color: #343a40;'>{$userSettlement['reports_count']}</div>
                        </div>
                        <div style='background: white; padding: 8px; border-radius: 4px;'>
                            <div style='font-size: 11px; color: #6c757d;'>Rows Imported</div>
                            <div style='font-size: 16px; font-weight: bold; color: #343a40;'>" . number_format($userSettlement['rows_imported']) . "</div>
                        </div>
                        <div style='background: white; padding: 8px; border-radius: 4px;'>
                            <div style='font-size: 11px; color: #6c757d;'>Total Fees</div>
                            <div style='font-size: 16px; font-weight: bold; color: #343a40;'>€" . number_format($userSettlement['total_fees'], 2) . "</div>
                        </div>
                    </div>
                </div>";
            } else {
                $html .= "
                <div style='margin-top: 10px; padding: 8px; background: #fff3cd; border-radius: 4px; font-size: 12px; color: #856404;'>
                    ℹ️ Nessun report settlement oggi
                </div>";
            }
            
            // Last sync info
            $html .= "
                <div style='margin-top: 10px; font-size: 11px; color: #6c757d;'>
                    Last sync: " . date('d/m/Y H:i', strtotime($userInventory['last_sync'])) . "
                </div>
            </div>";
        }
        
        $html .= "</div>";
        
        return $html;
    }
    
    /**
     * Build API Stats Section
     */
    private static function buildApiStatsSection($apiMetrics) {
        $successRate = $apiMetrics['total_calls'] > 0 
            ? round(($apiMetrics['success_count'] / $apiMetrics['total_calls']) * 100, 1) 
            : 100;
        
        $html = "
        <div class='card' style='background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
            <h3 style='margin: 0 0 20px 0; color: #343a40; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;'>
                🚀 API Performance (24h)
            </h3>
            
            <div style='display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px;'>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase; margin-bottom: 5px;'>Total Calls</div>
                    <div style='font-size: 32px; font-weight: bold; color: #343a40;'>{$apiMetrics['total_calls']}</div>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase; margin-bottom: 5px;'>Success Rate</div>
                    <div style='font-size: 32px; font-weight: bold; color: " . ($successRate >= 95 ? '#10b981' : '#f59e0b') . ";'>{$successRate}%</div>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase; margin-bottom: 5px;'>Avg Latency</div>
                    <div style='font-size: 32px; font-weight: bold; color: #343a40;'>{$apiMetrics['avg_latency']}ms</div>
                </div>
            </div>";
        
        // Breakdown by endpoint
        if (!empty($apiMetrics['by_endpoint'])) {
            $html .= "
            <h4 style='margin: 20px 0 10px 0; font-size: 14px; color: #6c757d; text-transform: uppercase;'>By Endpoint</h4>
            <div style='background: #f8f9fa; padding: 10px; border-radius: 6px;'>";
            
            foreach ($apiMetrics['by_endpoint'] as $endpoint => $stats) {
                $endpointSuccessRate = $stats['count'] > 0 
                    ? round(($stats['success'] / $stats['count']) * 100, 0) 
                    : 100;
                
                $html .= "
                <div style='display: flex; justify-content: space-between; align-items: center; padding: 8px; margin: 5px 0; background: white; border-radius: 4px;'>
                    <span style='font-weight: 600; color: #343a40; font-size: 13px;'>{$endpoint}</span>
                    <div style='display: flex; gap: 15px; align-items: center;'>
                        <span style='font-size: 12px; color: #6c757d;'>{$stats['count']} calls</span>
                        <span style='font-size: 12px; color: " . ($endpointSuccessRate >= 95 ? '#10b981' : '#f59e0b') . "; font-weight: bold;'>{$endpointSuccessRate}% ✓</span>
                        <span style='font-size: 12px; color: #6c757d;'>{$stats['avg_latency']}ms</span>
                    </div>
                </div>";
            }
            
            $html .= "</div>";
        }
        
        // Show errors if present
        if ($apiMetrics['error_count'] > 0) {
            $html .= "
            <div style='margin-top: 15px; padding: 12px; background: #fee; border-left: 4px solid #dc2626; border-radius: 6px;'>
                <strong style='color: #991b1b;'>⚠️ {$apiMetrics['error_count']} Errori API</strong>";
            
            if (!empty($apiMetrics['errors'])) {
                $html .= "<ul style='margin: 10px 0 0 0; padding-left: 20px; font-size: 12px;'>";
                foreach (array_slice($apiMetrics['errors'], 0, 5) as $error) {
                    $html .= "<li style='margin: 5px 0; color: #dc2626;'>{$error['endpoint']}: {$error['error']}</li>";
                }
                if (count($apiMetrics['errors']) > 5) {
                    $html .= "<li style='color: #6c757d;'>... e altri " . (count($apiMetrics['errors']) - 5) . " errori</li>";
                }
                $html .= "</ul>";
            }
            
            $html .= "</div>";
        }
        
        $html .= "</div>";
        
        return $html;
    }
    
    /**
     * Build Database Stats Section
     */
    private static function buildDatabaseStatsSection($databaseStats) {
        $growthIndicator = $databaseStats['growth_rate_mb_per_day'] > 0 
            ? "<span style='color: #10b981;'>+{$databaseStats['growth_rate_mb_per_day']} MB/day</span>"
            : "<span style='color: #6c757d;'>--</span>";
        
        $html = "
        <div class='card' style='background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
            <h3 style='margin: 0 0 20px 0; color: #343a40; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;'>
                💾 Database Stats
            </h3>
            
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px;'>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase; margin-bottom: 5px;'>Total Size</div>
                    <div style='font-size: 32px; font-weight: bold; color: #343a40;'>{$databaseStats['total_size_mb']} MB</div>
                </div>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;'>
                    <div style='font-size: 12px; color: #6c757d; text-transform: uppercase; margin-bottom: 5px;'>Growth Rate</div>
                    <div style='font-size: 32px; font-weight: bold;'>{$growthIndicator}</div>
                </div>
            </div>";
        
        // Top tables by size
        if (!empty($databaseStats['tables'])) {
            $html .= "
            <h4 style='margin: 20px 0 10px 0; font-size: 14px; color: #6c757d; text-transform: uppercase;'>Top Tables by Size</h4>
            <div style='background: #f8f9fa; padding: 10px; border-radius: 6px;'>";
            
            foreach (array_slice($databaseStats['tables'], 0, 10) as $table) {
                $html .= "
                <div style='display: flex; justify-content: space-between; align-items: center; padding: 8px; margin: 5px 0; background: white; border-radius: 4px;'>
                    <span style='font-weight: 600; color: #343a40; font-size: 13px;'>{$table['table_name']}</span>
                    <div style='display: flex; gap: 15px; align-items: center;'>
                        <span style='font-size: 12px; color: #6c757d;'>" . number_format($table['table_rows']) . " rows</span>
                        <span style='font-size: 12px; font-weight: bold; color: #343a40;'>{$table['size_mb']} MB</span>
                    </div>
                </div>";
            }
            
            $html .= "</div>";
        }
        
        $html .= "</div>";
        
        return $html;
    }
    
    /**
     * Build Trend Analysis Section
     */
    private static function buildTrendAnalysisSection($trends) {
        $html = "
        <div class='card' style='background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
            <h3 style='margin: 0 0 20px 0; color: #343a40; border-bottom: 2px solid #e9ecef; padding-bottom: 10px;'>
                📈 Trend Analysis (vs Yesterday & 7-day avg)
            </h3>
            
            <div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;'>";
        
        // Inventory trends
        if (!empty($trends['inventory'])) {
            foreach ($trends['inventory'] as $metricName => $data) {
                if ($data['yesterday'] !== null) {
                    $yesterdayValue = $data['yesterday'];
                    $weekAvg = $data['week_avg'] ?? 0;
                    
                    $html .= "
                    <div style='background: #f8f9fa; padding: 12px; border-radius: 6px;'>
                        <div style='font-size: 11px; color: #6c757d; text-transform: uppercase; margin-bottom: 5px;'>Inventory: {$metricName}</div>
                        <div style='display: flex; justify-content: space-between; align-items: center;'>
                            <div>
                                <div style='font-size: 11px; color: #6c757d;'>Yesterday</div>
                                <div style='font-size: 18px; font-weight: bold; color: #343a40;'>{$yesterdayValue}</div>
                            </div>
                            <div style='text-align: right;'>
                                <div style='font-size: 11px; color: #6c757d;'>7-day avg</div>
                                <div style='font-size: 18px; font-weight: bold; color: #6c757d;'>{$weekAvg}</div>
                            </div>
                        </div>
                    </div>";
                }
            }
        }
        
        $html .= "
            </div>
        </div>";
        
        return $html;
    }
    
    /**
     * Pulizia cache notifiche vecchie
     */
    public static function cleanupNotificationCache() {
        self::ensureCacheDir();
        
        $files = glob(self::CACHE_DIR . '*.cache');
        $cleaned = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < strtotime('-' . self::LOG_RETENTION_DAYS . ' days')) {
                unlink($file);
                $cleaned++;
            }
        }
        
        // Log rimosso - già incluso nel riepilogo daily report
        
        return $cleaned;
    }
    
    /**
     * Calcola health score per modulo (0-100)
     * 
     * @param int $successCount Numero operazioni completate con successo
     * @param int $totalCount Numero totale operazioni previste
     * @param int $errorCount Numero errori/fallimenti
     * @return int Health score 0-100
     */
    private static function calculateModuleHealth($successCount, $totalCount, $errorCount) {
        if ($totalCount === 0) {
            return 100; // Nessuna operazione = tutto OK
        }
        
        // Calcola percentuale successo
        $successRate = ($successCount / $totalCount) * 100;
        
        // Penalità per errori (max 40 punti di penalità)
        $errorPenalty = min($errorCount * 15, 40);
        
        // Score finale (minimo 0, massimo 100)
        $healthScore = max(0, min(100, round($successRate - $errorPenalty)));
        
        return $healthScore;
    }
    
    /**
     * Salva health score in storico per trend analysis
     */
    private static function saveHealthHistory($moduleName, $healthScore, $usersProcessed, $errorsCount, $stats = []) {
        try {
            $pdo = getDbConnection();
            
            $stmt = $pdo->prepare("
                INSERT INTO module_health_history 
                (module_name, health_score, users_processed, errors_count, measured_at, stats)
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            
            $stmt->execute([
                $moduleName,
                $healthScore,
                $usersProcessed,
                $errorsCount,
                json_encode($stats)
            ]);
            
            return true;
        } catch (Exception $e) {
            CentralLogger::log('admin_notifications', 'WARNING', 
                "Failed to save health history for {$moduleName}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Test sistema notifiche
     */
    public static function testNotificationSystem() {
        $results = [];
        
        // Test email failure
        $results['email_failure'] = self::notifyEmailFailure(
            999, 
            'test@example.com', 
            'Test error message',
            ['test_mode' => true]
        );
        
        // Test daily report
        $results['daily_report'] = self::sendDailyReport();
        
        // Test log analysis
        $results['log_analysis'] = self::analyzeDailyLogs();
        
        return $results;
    }
}

?>