<?php
require_once '../config/config.php';

class SettlementManager {
    
    private $userId;
    private $pdo;
    
    public function __construct($userId = null) {
        $this->userId = $userId;
        $this->pdo = getDbConnection();
    }
    
    public function getAvailableUsers() {
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'report_settlement_%'");
        $users = [];
        while($row = $stmt->fetch(PDO::FETCH_NUM)) {
            if(preg_match('/report_settlement_(\d+)/', $row[0], $matches)) {
                $users[] = (int)$matches[1];
            }
        }
        sort($users);
        return $users;
    }
    
    public function getDiagnostics() {
        if(!$this->userId) return null;
        
        // File TSV
        $downloadPath = dirname(__DIR__) . "/downloads/user_{$this->userId}/GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE";
        $files = is_dir($downloadPath) ? glob($downloadPath . '/*.tsv') : [];
        
        $fileSettlements = [];
        foreach($files as $file) {
            $handle = fopen($file, 'r');
            if($handle) {
                $headers = fgetcsv($handle, 0, "\t");
                $values = fgetcsv($handle, 0, "\t");
                fclose($handle);
                
                if($headers && $values) {
                    if(isset($headers[0]) && strpos($headers[0], "\xEF\xBB\xBF") === 0) {
                        $headers[0] = substr($headers[0], 3);
                    }
                    $headers = array_map('trim', $headers);
                    while(count($values) < count($headers)) $values[] = '';
                    $row = array_combine($headers, array_slice($values, 0, count($headers)));
                    $sid = $row['settlement-id'] ?? null;
                    if($sid) {
                        if(!isset($fileSettlements[$sid])) {
                            $fileSettlements[$sid] = [];
                        }
                        $fileSettlements[$sid][] = [
                            'path' => $file,
                            'name' => basename($file),
                            'size' => filesize($file),
                            'hash' => md5_file($file),
                            'mtime' => filemtime($file),
                            'total_amount' => $row['total-amount'] ?? null
                        ];
                    }
                }
            }
        }
        
        // DB
        $stmt = $this->pdo->query("
            SELECT settlement_id, COUNT(*) as row_count 
            FROM report_settlement_{$this->userId} 
            GROUP BY settlement_id
        ");
        $dbSettlements = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dbSettlements[$row['settlement_id']] = $row['row_count'];
        }
        
        // Metadata
        $stmt = $this->pdo->prepare("
            SELECT settlement_id, total_amount 
            FROM settlement_metadata 
            WHERE user_id = ?
        ");
        $stmt->execute([$this->userId]);
        $metaSettlements = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $metaSettlements[$row['settlement_id']] = $row['total_amount'];
        }
        
        // Analisi duplicati
        $duplicates = [];
        $identicalDuplicates = 0;
        $differentDuplicates = 0;
        
        foreach($fileSettlements as $sid => $fileList) {
            if(count($fileList) > 1) {
                $hashes = array_column($fileList, 'hash');
                $isIdentical = count(array_unique($hashes)) === 1;
                
                if($isIdentical) {
                    $identicalDuplicates++;
                } else {
                    $differentDuplicates++;
                }
                
                $duplicates[$sid] = [
                    'files' => $fileList,
                    'identical' => $isIdentical
                ];
            }
        }
        
        // Discrepanze
        $allSids = array_unique(array_merge(
            array_keys($fileSettlements),
            array_keys($dbSettlements),
            array_keys($metaSettlements)
        ));
        
        $onlyInFiles = [];
        $onlyInDb = [];
        $onlyInMeta = [];
        $dbWithoutMeta = [];
        
        foreach($allSids as $sid) {
            $inFiles = isset($fileSettlements[$sid]);
            $inDb = isset($dbSettlements[$sid]);
            $inMeta = isset($metaSettlements[$sid]);
            
            if($inFiles && !$inDb && !$inMeta) $onlyInFiles[] = $sid;
            if(!$inFiles && $inDb && !$inMeta) $onlyInDb[] = $sid;
            if(!$inFiles && !$inDb && $inMeta) $onlyInMeta[] = $sid;
            if($inDb && !$inMeta) $dbWithoutMeta[] = $sid;
        }
        
        return [
            'total_files' => count($files),
            'unique_settlements_files' => count($fileSettlements),
            'total_db' => count($dbSettlements),
            'total_metadata' => count($metaSettlements),
            'duplicates' => $duplicates,
            'identical_duplicates' => $identicalDuplicates,
            'different_duplicates' => $differentDuplicates,
            'only_in_files' => $onlyInFiles,
            'only_in_db' => $onlyInDb,
            'only_in_meta' => $onlyInMeta,
            'db_without_meta' => $dbWithoutMeta
        ];
    }
    
    public function recoveryMetadata() {
        if(!$this->userId) return ['success' => false, 'error' => 'User ID non specificato'];
        
        $downloadPath = dirname(__DIR__) . "/downloads/user_{$this->userId}/GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE";
        if(!is_dir($downloadPath)) {
            return ['success' => false, 'error' => 'Cartella non trovata'];
        }
        
        $files = glob($downloadPath . '/*.tsv');
        $recovered = 0;
        $skipped = 0;
        
        foreach($files as $file) {
            $handle = fopen($file, 'r');
            if(!$handle) continue;
            
            $headers = fgetcsv($handle, 0, "\t");
            $values = fgetcsv($handle, 0, "\t");
            fclose($handle);
            
            if(!$headers || !$values) continue;
            
            if(isset($headers[0]) && strpos($headers[0], "\xEF\xBB\xBF") === 0) {
                $headers[0] = substr($headers[0], 3);
            }
            $headers = array_map('trim', $headers);
            while(count($values) < count($headers)) $values[] = '';
            $row = array_combine($headers, array_slice($values, 0, count($headers)));
            
            $sid = $row['settlement-id'] ?? null;
            if(!$sid) continue;
            
            // Verifica se già presente
            $stmt = $this->pdo->prepare("SELECT id FROM settlement_metadata WHERE user_id = ? AND settlement_id = ?");
            $stmt->execute([$this->userId, $sid]);
            if($stmt->fetch()) {
                $skipped++;
                continue;
            }
            
            // Salva
            $stmt = $this->pdo->prepare("
                INSERT INTO settlement_metadata 
                (user_id, settlement_id, settlement_start_date, settlement_end_date, deposit_date, total_amount, currency)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $this->userId,
                $sid,
                $this->parseDate($row['settlement-start-date'] ?? null),
                $this->parseDate($row['settlement-end-date'] ?? null),
                $this->parseDate($row['deposit-date'] ?? null),
                $row['total-amount'] ?? null,
                $row['currency'] ?? null
            ]);
            
            $recovered++;
        }
        
        return [
            'success' => true,
            'recovered' => $recovered,
            'skipped' => $skipped,
            'total_files' => count($files)
        ];
    }
    
    public function deleteDuplicates($dryRun = true) {
        if(!$this->userId) return ['success' => false, 'error' => 'User ID non specificato'];
        
        $downloadPath = dirname(__DIR__) . "/downloads/user_{$this->userId}/GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE";
        $files = glob($downloadPath . '/*.tsv');
        
        $settlementMap = [];
        foreach($files as $file) {
            $handle = fopen($file, 'r');
            if($handle) {
                $headers = fgetcsv($handle, 0, "\t");
                $values = fgetcsv($handle, 0, "\t");
                fclose($handle);
                
                if($headers && $values) {
                    if(isset($headers[0]) && strpos($headers[0], "\xEF\xBB\xBF") === 0) {
                        $headers[0] = substr($headers[0], 3);
                    }
                    $headers = array_map('trim', $headers);
                    while(count($values) < count($headers)) $values[] = '';
                    $row = array_combine($headers, array_slice($values, 0, count($headers)));
                    $sid = $row['settlement-id'] ?? null;
                    
                    if($sid) {
                        if(!isset($settlementMap[$sid])) {
                            $settlementMap[$sid] = [];
                        }
                        $settlementMap[$sid][] = $file;
                    }
                }
            }
        }
        
        $deleted = 0;
        $toDelete = [];
        
        foreach($settlementMap as $sid => $fileList) {
            if(count($fileList) <= 1) continue;
            
            $fileData = [];
            foreach($fileList as $file) {
                $fileData[] = [
                    'path' => $file,
                    'hash' => md5_file($file),
                    'mtime' => filemtime($file),
                    'name' => basename($file)
                ];
            }
            
            $hashes = array_column($fileData, 'hash');
            if(count(array_unique($hashes)) !== 1) continue; // Skip diversi
            
            usort($fileData, function($a, $b) {
                return $b['mtime'] - $a['mtime'];
            });
            
            array_shift($fileData); // Mantieni più recente
            
            foreach($fileData as $fd) {
                if(!$dryRun) {
                    if(unlink($fd['path'])) {
                        $deleted++;
                    }
                } else {
                    $toDelete[] = $fd['name'];
                    $deleted++;
                }
            }
        }
        
        return [
            'success' => true,
            'deleted' => $deleted,
            'dry_run' => $dryRun,
            'files' => $toDelete
        ];
    }
    
    private function parseDate($value) {
        if(empty($value)) return null;
        try {
            $dt = new DateTime($value);
            return $dt->format("Y-m-d H:i:s");
        } catch(Exception $e) {
            return null;
        }
    }
}

// UI
$action = $_GET['action'] ?? 'home';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

$manager = new SettlementManager($userId);
$users = $manager->getAvailableUsers();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Settlement Manager</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }
        .user-selector { background: #e8f5e9; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        select { padding: 8px; font-size: 14px; }
        .btn { padding: 10px 20px; margin: 5px; cursor: pointer; border: none; border-radius: 4px; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4CAF50; color: white; }
        .btn-warning { background: #FF9800; color: white; }
        .btn-danger { background: #f44336; color: white; }
        .btn:hover { opacity: 0.8; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-box { background: #f9f9f9; padding: 15px; border-radius: 5px; border-left: 4px solid #4CAF50; }
        .stat-box.warning { border-left-color: #FF9800; }
        .stat-box.danger { border-left-color: #f44336; }
        .stat-label { font-size: 12px; color: #666; }
        .stat-value { font-size: 24px; font-weight: bold; color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 12px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #4CAF50; color: white; }
        tr:hover { background: #f5f5f5; }
        .success { background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Settlement Manager - Margynomic</h1>
    
    <div class="user-selector">
        <form method="GET">
            <label><strong>Seleziona User ID:</strong></label>
            <select name="user_id" onchange="this.form.submit()">
                <option value="">-- Seleziona --</option>
                <?php foreach($users as $uid): ?>
                    <option value="<?= $uid ?>" <?= $uid == $userId ? 'selected' : '' ?>>User <?= $uid ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="action" value="diagnostics">
        </form>
    </div>
    
    <?php if($userId): ?>
        
        <div style="margin-bottom: 20px;">
            <a href="?user_id=<?= $userId ?>&action=diagnostics" class="btn btn-primary">📊 Diagnostica</a>
            <a href="?user_id=<?= $userId ?>&action=recovery" class="btn btn-warning">🔄 Recovery Metadata</a>
            <a href="?user_id=<?= $userId ?>&action=delete_preview" class="btn btn-danger">🗑️ Elimina Duplicati</a>
        </div>
        
        <?php
        if($action == 'recovery') {
            $result = $manager->recoveryMetadata();
            if($result['success']) {
                echo "<div class='success'><strong>✅ Recovery completato</strong><br>";
                echo "Recuperati: {$result['recovered']}<br>";
                echo "Skippati: {$result['skipped']}<br>";
                echo "File totali: {$result['total_files']}</div>";
            } else {
                echo "<div class='error'>❌ {$result['error']}</div>";
            }
        }
        
        if($action == 'delete_preview') {
            $result = $manager->deleteDuplicates(true);
            echo "<div class='success'><strong>🗑️ Preview eliminazione duplicati</strong><br>";
            echo "File da eliminare: {$result['deleted']}<br><br>";
            if(count($result['files']) > 0) {
                echo "<ul>";
                foreach(array_slice($result['files'], 0, 20) as $f) {
                    echo "<li>{$f}</li>";
                }
                if(count($result['files']) > 20) {
                    echo "<li>... e altri " . (count($result['files']) - 20) . " file</li>";
                }
                echo "</ul>";
                echo "<a href='?user_id={$userId}&action=delete_confirm' class='btn btn-danger' onclick='return confirm(\"CONFERMI ELIMINAZIONE?\")'>⚠️ CONFERMA ELIMINAZIONE</a>";
            }
            echo "</div>";
        }
        
        if($action == 'delete_confirm') {
            $result = $manager->deleteDuplicates(false);
            echo "<div class='success'><strong>✅ Eliminazione completata</strong><br>";
            echo "File eliminati: {$result['deleted']}</div>";
        }
        
        if($action == 'diagnostics' || $action == 'home') {
            $diag = $manager->getDiagnostics();
            ?>
            
            <h2>📊 Diagnostica User <?= $userId ?></h2>
            
            <div class="stats">
                <div class="stat-box">
                    <div class="stat-label">File TSV</div>
                    <div class="stat-value"><?= $diag['total_files'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Settlement in DB</div>
                    <div class="stat-value"><?= $diag['total_db'] ?></div>
                </div>
                <div class="stat-box <?= $diag['total_metadata'] == $diag['total_db'] ? '' : 'warning' ?>">
                    <div class="stat-label">Settlement Metadata</div>
                    <div class="stat-value"><?= $diag['total_metadata'] ?></div>
                </div>
                <div class="stat-box <?= $diag['identical_duplicates'] > 0 ? 'warning' : '' ?>">
                    <div class="stat-label">Duplicati Identici</div>
                    <div class="stat-value"><?= $diag['identical_duplicates'] ?></div>
                </div>
                <div class="stat-box <?= $diag['different_duplicates'] > 0 ? 'danger' : '' ?>">
                    <div class="stat-label">Duplicati Diversi</div>
                    <div class="stat-value"><?= $diag['different_duplicates'] ?></div>
                </div>
            </div>
            
            <?php if(count($diag['db_without_meta']) > 0): ?>
                <h3 style="color: #FF9800;">⚠️ Settlement in DB senza Metadata (<?= count($diag['db_without_meta']) ?>)</h3>
                <p>Questi settlement sono importati ma mancano i metadata. Esegui <strong>Recovery Metadata</strong>.</p>
                <table>
                    <tr><th>Settlement ID</th></tr>
                    <?php foreach(array_slice($diag['db_without_meta'], 0, 10) as $sid): ?>
                        <tr><td><?= $sid ?></td></tr>
                    <?php endforeach; ?>
                    <?php if(count($diag['db_without_meta']) > 10): ?>
                        <tr><td>... e altri <?= count($diag['db_without_meta']) - 10 ?></td></tr>
                    <?php endif; ?>
                </table>
            <?php endif; ?>
            
            <?php if(count($diag['only_in_meta']) > 0): ?>
                <h3 style="color: #f44336;">❌ Settlement in Metadata ma NON in DB (<?= count($diag['only_in_meta']) ?>)</h3>
                <p>Anomalia: metadata senza dati transazioni.</p>
                <table>
                    <tr><th>Settlement ID</th></tr>
                    <?php foreach($diag['only_in_meta'] as $sid): ?>
                        <tr><td><?= $sid ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            
            <?php if(count($diag['duplicates']) > 0): ?>
                <h3>🔄 Duplicati Trovati (<?= count($diag['duplicates']) ?>)</h3>
                <table>
                    <tr><th>Settlement ID</th><th>N° File</th><th>Tipo</th><th>Files</th></tr>
                    <?php foreach(array_slice($diag['duplicates'], 0, 20) as $sid => $data): ?>
                        <tr style="background: <?= $data['identical'] ? '#fff3cd' : '#f8d7da' ?>">
                            <td><?= $sid ?></td>
                            <td><?= count($data['files']) ?></td>
                            <td><?= $data['identical'] ? '✅ Identici' : '❌ Diversi' ?></td>
                            <td style="font-size: 10px;"><?= implode('<br>', array_column(array_slice($data['files'], 0, 3), 'name')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            
            <?php if($diag['total_db'] == $diag['total_metadata'] && count($diag['duplicates']) == 0): ?>
                <div class="success">
                    <h3>✅ Sistema Perfettamente Allineato</h3>
                    <p>Tutti i settlement hanno file, dati DB e metadata corrispondenti. Nessun duplicato.</p>
                </div>
            <?php endif; ?>
            
        <?php } ?>
        
    <?php else: ?>
        <p>Seleziona un user per iniziare.</p>
    <?php endif; ?>
    
</div>
</body>
</html>