<?php
/**
 * Email Notifications Cronjob - PreviSync
 * File: modules/previsync/email_notifications.php
 * 
 * Invia automaticamente report PDF rifornimenti via email
 * Esecuzione consigliata: ogni lunedì alle 08:00
 * Crontab: 0 8 * * 1 /usr/bin/php /path/to/modules/previsync/email_notifications.php
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Path assoluti per inclusioni
$rootPath = dirname(__DIR__);
require_once $rootPath . '/margynomic/config/config.php';
require_once $rootPath . '/margynomic/gestione_vendor.php';
require_once $rootPath . '/margynomic/admin_notifier.php';

// Log gestito da CentralLogger - costante rimossa

/**
 * Log Email - Utilizza CentralLogger
 */
function logEmail($message, $level = 'INFO', $context = []) {
    // Aggiungi informazioni giorno al context
    $context['day_name'] = date('l');
    $context['day_number'] = date('N');
    
    CentralLogger::log('email_notifications', $level, $message, $context);
    
    // Mantieni output CLI per retrocompatibilità
    if (php_sapi_name() === 'cli') {
        $timestamp = date('H:i:s');
        $dayName = date('l');
        echo "[{$timestamp}] [EMAIL] [{$level}] [{$dayName}] {$message}" . PHP_EOL;
    }
}

/**
 * Ottiene utenti attivi con notifiche abilitate per oggi
 */
function getUsersForToday(): array {
    try {
        $pdo = getDbConnection();
        $today = isset($_GET['force_today']) ? date('N') : date('N');
// Test: forza giorno se parametro presente
if (isset($_GET['test_day'])) {
    $today = (int)$_GET['test_day'];
    debugOutput("🧪 MODALITÀ TEST: Forzando giorno {$today}");
}
        $dayNames = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];
        
        // Recupera TUTTI gli utenti con notifiche attive per debug
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.nome, u.email as user_email,
                n.email_address, n.send_time, n.send_day, n.is_active
            FROM users u
            INNER JOIN user_notifications n ON u.id = n.user_id
            WHERE u.is_active = 1 
            AND n.notification_type = 'inventory_weekly'
            ORDER BY u.id ASC
        ");
        $stmt->execute();
        $allUsers = $stmt->fetchAll();
        
        foreach ($allUsers as $user) {
            $userDayName = $dayNames[$user['send_day']] ?? 'Sconosciuto';
            $status = $user['is_active'] ? '✅' : '❌';
            // Debug rimosso - log consolidato a fine processo
        }
        
        // Ora filtra solo per oggi
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.nome, u.email as user_email,
                n.email_address, n.send_time, n.send_day
            FROM users u
            INNER JOIN user_notifications n ON u.id = n.user_id
            WHERE u.is_active = 1 
            AND n.is_active = 1
            AND n.notification_type = 'inventory_weekly'
            AND n.send_day = ?
            ORDER BY u.id ASC
        ");
        
        $stmt->execute([$today]);
        $users = $stmt->fetchAll();
        
        return $users;
        
    } catch (Exception $e) {
        debugOutput("❌ Errore recupero utenti: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Genera PDF rifornimento per utente specifico
 */
function generateInventoryPDF(int $userId): ?string {
    try {
        // Simula sessione utente per il cronjob
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['logged_in'] = true;
        
        // Includi InventoryAnalyzer senza output  
        $_SERVER['HTTP_USER_AGENT'] = 'EmailNotificationsCron/1.0';
        $_GET['criticity'] = 'all';
        ob_start();
        require_once __DIR__ . '/inventory.php';
        ob_end_clean();

        debugOutput("📂 InventoryAnalyzer caricato correttamente");

        // Analizza inventario utente
        $analyzer = new InventoryAnalyzer($userId);
        $inventoryData = $analyzer->getCompleteInventoryAnalysis();
        $analysis = $inventoryData['analysis'] ?? [];
        
        // Filtra solo prodotti da rifornire
        $analysis = array_filter($analysis, function($item) {
            return isset($item['invio_suggerito']) && $item['invio_suggerito'] > 0;
        });
        
        debugOutput("📊 Analisi inventario utente {$userId}: " . count($analysis) . " prodotti da rifornire");
        
        if (empty($analysis)) {
            debugOutput("⚠️  Nessun prodotto da rifornire per utente {$userId}", 'INFO');
            return null;
        }
        
        debugOutput("✅ Prodotti trovati, generando PDF...");
        
        // Includi TCPDF
        $tcpdfPath = dirname(__DIR__) . '/margynomic/vendor/tcpdf/tcpdf.php';
        if (!file_exists($tcpdfPath)) {
            logEmail("TCPDF non trovato: {$tcpdfPath}", 'ERROR');
            return null;
        }
        require_once $tcpdfPath;
        
        // Estende TCPDF per personalizzazione
        if (!class_exists('EmailInventoryPDF')) {
        class EmailInventoryPDF extends TCPDF {
            public $reportTitle = '';
            public $reportDate = '';
            
            public function Header(): void {
                $this->SetFont('helvetica', 'B', 12);
                $this->SetTextColor(255, 107, 53);
                $this->Cell(0, 7, $this->reportTitle, 0, 1, 'L');
                $this->SetFont('helvetica', '', 9);
                $this->SetTextColor(100, 100, 100);
                $this->Cell(0, 6, $this->reportDate, 0, 0, 'L');
                $this->Ln(4);
                $this->SetDrawColor(255, 107, 53);
                $this->Line(10, 22, $this->getPageWidth() - 10, 22);
                $this->Ln(4);
            }
            
            public function Footer(): void {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->SetTextColor(150, 150, 150);
                $this->Cell(0, 10, 'Pagina ' . $this->getAliasNumPage() . ' di ' . $this->getAliasNbPages(), 0, 0, 'C');
            }
        }
    } // Fine controllo class_exists
        
        // Crea PDF
        $pdf = new EmailInventoryPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('PreviSync');
        $pdf->SetTitle('Report Rifornimento');
        $pdf->SetMargins(10, 30, 10);
        $pdf->SetAutoPageBreak(true, 20);
        
        $pdf->reportTitle = 'Report di Rifornimento Settimanale';
        $pdf->reportDate = 'Generato il: ' . date('d/m/Y H:i');
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9);
        
        // Raggruppa per criticità
        $grouped = [];
        foreach ($analysis as $row) {
            $level = $row['criticita'] ?? 'neutro';
            $grouped[$level][] = $row;
        }
        
        // Ordine priorità e colori
        $priorityOrder = [
            'alto' => 'Alta criticità',
            'medio' => 'Media criticità', 
            'basso' => 'Bassa criticità',
            'avvia' => 'Avvia rotazione',
            'elimina' => 'Da eliminare',
            'neutro' => 'Neutro'
        ];
        
        $groupColors = [
            'alto' => '#ff3547',
            'medio' => '#ffb400',
            'basso' => '#17a2b8',
            'avvia' => '#007bff',
            'elimina' => '#6c757d',
            'neutro' => '#00c851'
        ];
        
        // Genera HTML tabella
        $html = '';
        foreach ($priorityOrder as $key => $label) {
            if (empty($grouped[$key])) continue;
            
            $bgColor = $groupColors[$key] ?? '#cccccc';
            $html .= '<div style="font-size:11pt; font-weight:bold; color:#ffffff; background-color:' . $bgColor . '; padding:4px; margin-top:10px;">' . htmlspecialchars($label) . '</div>';
            
            $html .= '<table border="1" cellpadding="4" cellspacing="0" style="width:100%; font-size:8pt;">';
$html .= '<thead><tr style="background-color:#f8f9fa;">';
$html .= '<th style="width:36%;">Prodotto</th>';
$html .= '<th style="width:8%;">Prezzo</th>';
$html .= '<th style="width:8%;">Scorte</th>';
$html .= '<th style="width:12%;">FNSKU</th>';
$html .= '<th style="width:12%;">Giorni Rimanenti</th>';
$html .= '<th style="width:12%;">Urgenza</th>';
$html .= '<th style="width:12%;">Da Ordinare</th>';
$html .= '</tr></thead><tbody>';
            
            foreach ($grouped[$key] as $row) {
    $giorni = ($row['giorni_stock'] == 999) ? '∞' : number_format((float)$row['giorni_stock'], 0, ',', '.');
    
    // Query diretta per ottenere FNSKU dal nome prodotto (come in inventory_export_pdf.php)
    $fnsku = '';
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("
            SELECT p.fnsku 
            FROM products p 
            WHERE p.user_id = ? AND p.nome = ? 
            LIMIT 1
        ");
        $stmt->execute([$userId, $row['product_name']]);
        $result = $stmt->fetch();
        $fnsku = $result['fnsku'] ?? '';
    } catch (Exception $e) {
        $fnsku = '';
        debugOutput("⚠️ Errore query FNSKU per {$row['product_name']}: " . $e->getMessage(), 'WARNING');
    }
    
    $html .= '<tr>';
    $html .= '<td style="width:36%;">' . htmlspecialchars($row['product_name'] ?? 'N/A') . '</td>';
    $html .= '<td style="width:8%; text-align:right;">€' . number_format((float)($row['your_price'] ?? 0), 2, ',', '.') . '</td>';
    $html .= '<td style="width:8%; text-align:right;">' . number_format((int)($row['disponibili'] ?? 0), 0, ',', '.') . '</td>';
    
    $html .= '<td style="width:12%; text-align:left; font-family:monospace;">' . ($fnsku ? htmlspecialchars($fnsku) : '<em style="color:#999;">Non assegnato</em>') . '</td>';
    
    $html .= '<td style="width:12%; text-align:right;">' . $giorni . '</td>';
    
    $color = $groupColors[$row['criticita']] ?? '#000000';
    $html .= '<td style="width:12%; text-align:center; color:' . $color . '; font-weight:bold;">' . htmlspecialchars(ucfirst($row['criticita'])) . '</td>';
    
    $html .= '<td style="width:12%; text-align:right;">' . number_format((float)$row['invio_suggerito'], 0, ',', '.') . '</td>';
    $html .= '</tr>';
}
            
            $html .= '</tbody></table>';
        }
        
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Salva PDF temporaneo
        $tempDir = __DIR__ . '/temp/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $fileName = $tempDir . 'rifornimento_user_' . $userId . '_' . date('Ymd') . '.pdf';
        $pdf->Output($fileName, 'F');
        
        logEmail("PDF generato: {$fileName}", 'SUCCESS');
        return $fileName;
        
    } catch (Exception $e) {
        logEmail("Errore generazione PDF utente {$userId}: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Invia email con PDF allegato
 */
function sendInventoryEmail(array $user, string $pdfPath): bool {
    try {
        // Carica template email
        $templatePath = __DIR__ . '/email_template.php';
        if (!file_exists($templatePath)) {
            logEmail("Template email non trovato: {$templatePath}", 'ERROR');
            return false;
        }
        
        // Genera contenuto email
        $userName = $user['nome'];
        $emailContent = include $templatePath;
        
        $to = $user['email_address'];
        $subject = "📦 Report Rifornimenti PreviSync - " . date('d/m/Y');
        
        debugOutput("📧 Tentativo invio email a: {$to}");
        debugOutput("📋 Oggetto: {$subject}");
        debugOutput("📎 PDF allegato: " . basename($pdfPath) . " (" . filesize($pdfPath) . " bytes)");
        
        // Invia email usando PHPMailer centralizzato
        $result = inviaEmailSMTPWithAttachment($to, $subject, $emailContent, $pdfPath);
        
        if ($result) {
            logEmail("Email inviata con successo a {$to}", 'SUCCESS', [
                'user_id' => $user['id'],
                'pdf_size' => filesize($pdfPath)
            ]);
            return true;
        } else {
            logEmail("Errore invio email a {$to}", 'ERROR', ['user_id' => $user['id']]);
            
            // Notifica admin fallimento
            AdminNotifier::notifyEmailFailure($user['id'], $to, "SMTP delivery failed", [
                'pdf_path' => $pdfPath,
                'error_context' => 'email_notifications_cron'
            ]);
            
            return false;
        }
        
    } catch (Exception $e) {
        logEmail("Exception invio email utente {$user['id']}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Pulizia file temporanei
 */
function cleanupTempFiles(): void {
    try {
        $tempDir = __DIR__ . '/temp/';
        if (!is_dir($tempDir)) return;
        
        $files = glob($tempDir . '*.pdf');
        $cleaned = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < strtotime('-7 days')) {
                unlink($file);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            logEmail("Puliti {$cleaned} file PDF temporanei");
        }
        
    } catch (Exception $e) {
        logEmail("Errore pulizia file: " . $e->getMessage(), 'ERROR');
    }
}

// === ESECUZIONE PRINCIPALE ===
function main(): void {
    $startTime = microtime(true);
    $processedUsers = 0;
    $sentEmails = 0;
    $errors = 0;
    
    try {
        // Ottieni utenti per oggi
        $users = getUsersForToday();
        
        if (empty($users)) {
            // Log consolidato anche quando nessun utente
            $duration = round(microtime(true) - $startTime, 2);
            logEmail("Email notifications completato", 'INFO', [
                'day' => date('l') . ' (' . date('N') . ')',
                'users_to_notify' => 0,
                'emails_sent' => 0,
                'duration_seconds' => $duration
            ]);
            return;
        }
        
        // Processa ogni utente
        foreach ($users as $user) {
            $processedUsers++;
            
            debugOutput("👤 Processando utente {$user['id']} - {$user['nome']}", 'INFO');
            
            // Genera PDF
            $pdfPath = generateInventoryPDF($user['id']);
            if (!$pdfPath) {
                $errors++;
                continue;
            }
            
            // Invia email
            if (sendInventoryEmail($user, $pdfPath)) {
                $sentEmails++;
            } else {
                $errors++;
            }
            
            // Pausa tra utenti per non sovraccaricare
            sleep(2);
        }
        
    } catch (Exception $e) {
        logEmail("ERRORE CRITICO: " . $e->getMessage(), 'CRITICAL');
        $errors++;
    } finally {
        // Pulizia file temporanei
        cleanupTempFiles();
        
        // Log unico consolidato con tutte le statistiche
        $duration = round(microtime(true) - $startTime, 2);
        $successRate = $processedUsers > 0 ? round(($sentEmails / $processedUsers) * 100, 1) : 0;
        
        logEmail(
            sprintf('Email notifications completato: %d/%d inviati in %.2fs', $sentEmails, $processedUsers, $duration), 
            $errors > 0 ? 'WARNING' : 'SUCCESS',
            [
                'day' => date('l') . ' (' . date('N') . ')',
                'processed_users' => $processedUsers,
                'sent_emails' => $sentEmails,
                'errors' => $errors,
                'success_rate' => $successRate . '%',
                'duration_seconds' => $duration
            ]
        );
    }
}

// === DEBUG E ESECUZIONE ===
function debugOutput($message, $level = 'INFO') {
    $timestamp = date('H:i:s');
    $output = "[{$timestamp}] [{$level}] {$message}\n";
    
    // Output immediato per browser
    echo $output;
    flush();
    ob_flush();
    
    // Log su file
    logEmail($message, $level);
}

// Esecuzione da cronjob.org o CLI
if (php_sapi_name() === 'cli' || basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    // Headers per debug browser solo se non già inviati
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    
    // Buffer output per debug real-time
    if (ob_get_level() == 0) ob_start();
    
    try {
        main();
    } catch (Exception $e) {
        logEmail("ERRORE CRITICO: " . $e->getMessage(), 'CRITICAL', [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
?>