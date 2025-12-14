<?php
/**
 * SIMPLE PROMPT GENERATOR
 * Versione semplificata: form → prompt formattato → copia
 */

session_start();

// Avvia sessione e richiede autenticazione admin
require_once __DIR__ . '/../modules/margynomic/config/config.php';
require_once __DIR__ . '/../modules/margynomic/admin/admin_helpers.php';

// Controllo sessione (compatibile con creator.php)
if (!isset($_SESSION['admin_id'])) {
    header('Location: /modules/margynomic/admin/admin_login.php');
    exit;
}

$generatedPrompt = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_POST['file'] ?? '';
    $note = $_POST['note'] ?? '';
    $fase = $_POST['fase'] ?? 'ricognizione';
    $regole = $_POST['regole'] ?? [];
    $ambito = $_POST['ambito'] ?? [];
    $outputMode = $_POST['output_mode'] ?? 'mcp';
    
    // ANALIZZA FILE PER CONTESTO
    $contesto = analyzeFile($file);
    
    // GENERA PROMPT
    $prompt = "═══════════════════════════════════════════════════════════\n";
    $prompt .= "🎯 RUOLO\n";
    $prompt .= "═══════════════════════════════════════════════════════════\n\n";
    $prompt .= "Sei un senior full-stack debugger (PHP + JS). Ricostruisci il flusso reale e trova il punto esatto dove si interrompe.\n\n";
    
    $prompt .= "═══════════════════════════════════════════════════════════\n";
    $prompt .= "🎯 SCOPO\n";
    $prompt .= "═══════════════════════════════════════════════════════════\n\n";
    
    if ($fase === 'ricognizione') {
        $prompt .= "Mappare il flusso completo e identificare i punti di rottura probabili SENZA proporre fix.\n\n";
    } elseif ($fase === 'strumentazione') {
        $prompt .= "Definire esattamente dove inserire logging per trasformare errore generico in errore diagnosticabile.\n\n";
    } elseif ($fase === 'fix') {
        $prompt .= "Proporre la patch minima con codice CERCA/SOSTITUISCI pronto.\n\n";
    }
    
    $prompt .= "═══════════════════════════════════════════════════════════\n";
    $prompt .= "📋 CONTESTO\n";
    $prompt .= "═══════════════════════════════════════════════════════════\n\n";
    $prompt .= "File: {$file}\n";
    $prompt .= "Problema: {$note}\n\n";
    
    // AGGIUNGI CONTESTO AUTOMATICO
    if (!empty($contesto['js_files'])) {
        $prompt .= "File JS caricati:\n";
        foreach ($contesto['js_files'] as $js) {
            $prompt .= "  - {$js}\n";
        }
        $prompt .= "\n";
    }
    
    if (!empty($contesto['functions'])) {
        $prompt .= "Funzioni chiave individuate:\n";
        foreach (array_slice($contesto['functions'], 0, 5) as $func) {
            $prompt .= "  - {$func}\n";
        }
        $prompt .= "\n";
    }
    
    if (!empty($contesto['endpoints'])) {
        $prompt .= "Endpoint chiamati:\n";
        foreach ($contesto['endpoints'] as $endpoint) {
            $prompt .= "  - {$endpoint}\n";
        }
        $prompt .= "\n";
    }
    
    if (!empty($contesto['dom_ids'])) {
        $prompt .= "Elementi DOM rilevanti:\n";
        foreach (array_slice($contesto['dom_ids'], 0, 5) as $id) {
            $prompt .= "  - #{$id}\n";
        }
        $prompt .= "\n";
    }
    
    if (!empty($regole) || $outputMode === 'mcp') {
        $prompt .= "═══════════════════════════════════════════════════════════\n";
        $prompt .= "⚠️ VINCOLI\n";
        $prompt .= "═══════════════════════════════════════════════════════════\n\n";
        
        // Output mode vincolo
        if ($outputMode === 'mcp') {
            $prompt .= "- Effettua modifiche DIRETTE tramite MCP (priorità)\n";
            $prompt .= "- Se MCP non disponibile: usa CERCA/SOSTITUISCI\n";
        } else {
            $prompt .= "- Output SOLO in formato CERCA/SOSTITUISCI\n";
            $prompt .= "- NO modifiche dirette, solo proposta per revisione manuale\n";
        }
        
        foreach ($regole as $regola) {
            $prompt .= "- " . str_replace('_', ' ', $regola) . "\n";
        }
        
        // Aggiungi vincoli standard da .cursorrules
        $prompt .= "- Performance > Eleganza, Semplicità > Complessità\n";
        $prompt .= "- NO refactoring non richiesto\n";
        $prompt .= "- DRY: non ripetere logica esistente\n";
        $prompt .= "- Analizza codice REALE prima di modificare\n";
        $prompt .= "\n";
    }
    
    if (!empty($ambito)) {
        $prompt .= "═══════════════════════════════════════════════════════════\n";
        $prompt .= "🔍 AMBITO ISPEZIONE\n";
        $prompt .= "═══════════════════════════════════════════════════════════\n\n";
        foreach ($ambito as $a) {
            $prompt .= "- " . strtoupper($a) . "\n";
        }
        $prompt .= "\n";
    }
    
    $prompt .= "═══════════════════════════════════════════════════════════\n";
    $prompt .= "📤 CONTRATTO OUTPUT\n";
    $prompt .= "═══════════════════════════════════════════════════════════\n\n";
    
    if ($fase === 'ricognizione') {
        $prompt .= "SEZIONE A: FLUSSO IDEALE (max 10 passi)\n";
        $prompt .= "SEZIONE B: PUNTI DI ROTTURA PROBABILI (3 più probabili)\n";
        $prompt .= "SEZIONE C: FILE DA APRIRE DOPO\n";
        $prompt .= "SEZIONE D: CONTROLLI IMMEDIATI BROWSER\n\n";
    } elseif ($fase === 'strumentazione') {
        $prompt .= "SEZIONE A: PUNTI DI LOG FRONTEND (file, funzione, linea, tag univoco)\n";
        $prompt .= "SEZIONE B: PUNTI DI LOG BACKEND (file, funzione, linea, tag univoco)\n";
        $prompt .= "SEZIONE C: TEMPLATE LOG DA USARE (snippet copy-paste ready)\n\n";
    } elseif ($fase === 'fix') {
        $prompt .= "CERCA:\n[blocco codice univoco 3-5 righe]\n\n";
        $prompt .= "SOSTITUISCI CON:\n[blocco modificato]\n\n";
        $prompt .= "FILE: [percorso]\n";
        $prompt .= "LINEA: ~[numero]\n";
        $prompt .= "MOTIVAZIONE: [max 200 char]\n\n";
    }
    
    $prompt .= "Niente introduzioni, niente conclusioni, niente spiegoni.\nSolo output operativo diretto.\n\n";
    
    $prompt .= "═══════════════════════════════════════════════════════════\n";
    $prompt .= "FINE PROMPT - Procedi con l'analisi\n";
    $prompt .= "═══════════════════════════════════════════════════════════\n";
    
    $generatedPrompt = $prompt;
}

/**
 * Analizza file per estrarre contesto automatico
 */
function analyzeFile($filePath) {
    $context = [
        'js_files' => [],
        'functions' => [],
        'endpoints' => [],
        'dom_ids' => []
    ];
    
    // Path dal root Skualizer
    $fullPath = dirname(__DIR__) . '/' . ltrim($filePath, '/');
    
    if (!file_exists($fullPath)) {
        error_log("File non trovato: {$fullPath}");
        return $context;
    }
    
    $content = file_get_contents($fullPath);
    
    // Estrai file JS caricati
    if (preg_match_all('/<script[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
        $context['js_files'] = array_unique($matches[1]);
    }
    
    // Estrai funzioni JS (function nome() o const nome = )
    if (preg_match_all('/(?:function\s+(\w+)|const\s+(\w+)\s*=\s*(?:function|\(|async))/', $content, $matches)) {
        $funcs = array_merge($matches[1], $matches[2]);
        $context['functions'] = array_unique(array_filter($funcs));
    }
    
    // Estrai endpoint AJAX (fetch o $.ajax)
    if (preg_match_all('/(?:fetch|ajax)\s*\(\s*["\']([^"\']+)["\']/', $content, $matches)) {
        $context['endpoints'] = array_unique($matches[1]);
    }
    
    // Estrai ID elementi DOM importanti
    if (preg_match_all('/id=["\']([^"\']+)["\']/', $content, $matches)) {
        $context['dom_ids'] = array_unique(array_slice($matches[1], 0, 10));
    }
    
    return $context;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prompt Generator</title>
    
    <?php echo getAdminHeader('Prompt Generator'); ?>
    
    <style>
        body { background: #f5f5f5; }
        .container { max-width: 1000px; margin: 20px auto; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px 30px; }
        .header h1 { font-size: 24px; margin: 0; }
        .header p { opacity: 0.9; font-size: 14px; margin: 5px 0 0 0; }
        .content { padding: 30px; }
        .form-group { margin-bottom: 25px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #2d3748; font-size: 14px; }
        input[type="text"], textarea { width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; transition: border-color 0.2s; }
        input[type="text"]:focus, textarea:focus { outline: none; border-color: #667eea; }
        textarea { min-height: 120px; resize: vertical; }
        .checkbox-group { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .checkbox-item { display: flex; align-items: center; padding: 10px; background: #f7fafc; border-radius: 6px; }
        .checkbox-item input { margin-right: 8px; }
        select { width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; background: white; cursor: pointer; }
        .btn { padding: 14px 28px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; width: 100%; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4); }
        .output { background: #1a202c; color: #48bb78; padding: 20px; border-radius: 8px; margin-top: 20px; position: relative; }
        .output pre { white-space: pre-wrap; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6; }
        .copy-btn { position: absolute; top: 10px; right: 10px; background: #48bb78; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 12px; }
        .copy-btn:hover { background: #38a169; }
    </style>
</head>
<body>
    <?php echo getAdminNavigation('prompt_generator'); ?>
    
    <div class="container">
        <div class="header">
            <h1>⚡ Simple Prompt Generator</h1>
            <p>Genera prompt strutturati in 10 secondi</p>
        </div>
        
        <div class="content">
            <form method="POST">
                <div class="form-group">
                    <label>📁 File di riferimento</label>
                    <input type="text" name="file" value="<?= htmlspecialchars($_POST['file'] ?? '') ?>" 
                           placeholder="modules/margynomic/admin/creaexcel/ai/views/create.php" required>
                </div>
                
                <div class="form-group">
                    <label>💬 Descrizione problema</label>
                    <textarea name="note" required placeholder="Descrivi brevemente cosa non funziona..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>🎯 Fase</label>
                    <select name="fase">
                        <option value="ricognizione" <?= ($_POST['fase'] ?? '') === 'ricognizione' ? 'selected' : '' ?>>
                            Fase 1: Ricognizione (mappa flusso)
                        </option>
                        <option value="strumentazione" <?= ($_POST['fase'] ?? '') === 'strumentazione' ? 'selected' : '' ?>>
                            Fase 2: Strumentazione (dove loggare)
                        </option>
                        <option value="fix" <?= ($_POST['fase'] ?? '') === 'fix' ? 'selected' : '' ?>>
                            Fase 3: Fix (CERCA/SOSTITUISCI)
                        </option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>⚠️ Regole (vincoli comuni)</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="regole[]" value="NO_file_MD" id="r1" <?= in_array('NO_file_MD', $_POST['regole'] ?? []) ? 'checked' : '' ?>>
                            <label for="r1" style="margin: 0; font-weight: normal;">NO file .md</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="regole[]" value="NO_nuove_tabelle" id="r2" <?= in_array('NO_nuove_tabelle', $_POST['regole'] ?? []) ? 'checked' : '' ?>>
                            <label for="r2" style="margin: 0; font-weight: normal;">NO nuove tabelle</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="regole[]" value="Debug_solo_console_log" id="r3" <?= in_array('Debug_solo_console_log', $_POST['regole'] ?? []) ? 'checked' : '' ?>>
                            <label for="r3" style="margin: 0; font-weight: normal;">Debug solo console.log</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="regole[]" value="Test_in_root/test" id="r4" <?= in_array('Test_in_root/test', $_POST['regole'] ?? []) ? 'checked' : '' ?>>
                            <label for="r4" style="margin: 0; font-weight: normal;">Test in root/test</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="regole[]" value="NO_dipendenze_nuove" id="r5" <?= in_array('NO_dipendenze_nuove', $_POST['regole'] ?? []) ? 'checked' : '' ?>>
                            <label for="r5" style="margin: 0; font-weight: normal;">NO dipendenze nuove</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="regole[]" value="Stile_CERCA_SOSTITUISCI" id="r6" <?= in_array('Stile_CERCA_SOSTITUISCI', $_POST['regole'] ?? []) ? 'checked' : '' ?>>
                            <label for="r6" style="margin: 0; font-weight: normal;">Stile CERCA/SOSTITUISCI</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>🔍 Ambito</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="ambito[]" value="frontend" id="a1" <?= in_array('frontend', $_POST['ambito'] ?? []) ? 'checked' : '' ?>>
                            <label for="a1" style="margin: 0; font-weight: normal;">Frontend (JS, DOM)</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="ambito[]" value="backend" id="a2" <?= in_array('backend', $_POST['ambito'] ?? []) ? 'checked' : '' ?>>
                            <label for="a2" style="margin: 0; font-weight: normal;">Backend (PHP)</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="ambito[]" value="database" id="a3" <?= in_array('database', $_POST['ambito'] ?? []) ? 'checked' : '' ?>>
                            <label for="a3" style="margin: 0; font-weight: normal;">Database (Query)</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>🛠️ Modalità Output (Claude)</label>
                    <div style="display: flex; gap: 15px;">
                        <div style="flex: 1; padding: 15px; background: #f7fafc; border-radius: 8px; border: 2px solid #e2e8f0; cursor: pointer;" onclick="document.getElementById('mode_mcp').checked = true">
                            <input type="radio" name="output_mode" value="mcp" id="mode_mcp" <?= ($_POST['output_mode'] ?? 'mcp') === 'mcp' ? 'checked' : '' ?> style="margin-right: 8px;">
                            <label for="mode_mcp" style="margin: 0; cursor: pointer;">
                                <strong>MCP Dirette</strong><br>
                                <small style="color: #718096;">Claude modifica file direttamente (+ veloce)</small>
                            </label>
                        </div>
                        <div style="flex: 1; padding: 15px; background: #f7fafc; border-radius: 8px; border: 2px solid #e2e8f0; cursor: pointer;" onclick="document.getElementById('mode_cs').checked = true">
                            <input type="radio" name="output_mode" value="cerca_sostituisci" id="mode_cs" <?= ($_POST['output_mode'] ?? '') === 'cerca_sostituisci' ? 'checked' : '' ?> style="margin-right: 8px;">
                            <label for="mode_cs" style="margin: 0; cursor: pointer;">
                                <strong>CERCA/SOSTITUISCI</strong><br>
                                <small style="color: #718096;">Revisione manuale prima di applicare</small>
                            </label>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">⚡ Genera Prompt</button>
            </form>
            
            <?php if ($generatedPrompt): ?>
            <div class="output">
                <button class="copy-btn" onclick="copyPrompt()">📋 Copia Prompt Base</button>
                <button class="copy-btn" onclick="executeWithAi()" style="right: 180px; background: #38a169;">🤖 Migliora con Gemini</button>
                <pre id="prompt"><?= htmlspecialchars($generatedPrompt) ?></pre>
            </div>
            
            <div id="aiOutput" style="display:none; background: #1e3a28; color: #d4edda; padding: 20px; border-radius: 8px; margin-top: 20px; position: relative;">
                <h3 style="color: #48bb78; margin-bottom: 10px;">✨ Prompt Migliorato da Gemini (da dare a Claude)</h3>
                <div id="costInfo" style="color: #9ae6b4; font-size: 13px; margin-bottom: 15px; padding: 10px; background: rgba(72,187,120,0.1); border-radius: 4px; font-family: monospace;"></div>
                <button class="copy-btn" onclick="copyImproved()" style="top: 10px;">📋 Copia Prompt Migliorato</button>
                <pre id="aiResult" style="white-space: pre-wrap; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6;"></pre>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function copyPrompt() {
            const text = document.getElementById('prompt').textContent;
            navigator.clipboard.writeText(text).then(() => {
                event.target.textContent = '✓ Copiato!';
                setTimeout(() => event.target.textContent = '📋 Copia Prompt Base', 2000);
            });
        }
        
        function copyImproved() {
            const text = document.getElementById('aiResult').textContent;
            navigator.clipboard.writeText(text).then(() => {
                event.target.textContent = '✓ Copiato!';
                setTimeout(() => event.target.textContent = '📋 Copia Prompt Migliorato', 2000);
            });
        }
        
        async function executeWithAi() {
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = '⏳ Gemini sta migliorando il prompt...';
            btn.disabled = true;
            
            const prompt = document.getElementById('prompt').textContent;
            const filePath = document.querySelector('input[name="file"]').value;
            
            try {
                console.log('[AI] Chiamata Gemini per migliorare prompt...');
                
                const formData = new FormData();
                formData.append('prompt', prompt);
                formData.append('file', filePath);
                
                const response = await fetch('ai_execute.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                console.log('[AI] Prompt migliorato ricevuto');
                
                if (result.success) {
                    document.getElementById('aiOutput').style.display = 'block';
                    document.getElementById('aiResult').textContent = result.output;
                    
                    // Mostra info costi
                    const costInfo = document.getElementById('costInfo');
                    costInfo.innerHTML = `
                        <strong>📊 Token Usage:</strong><br>
                        Input: ${result.tokens.input.toLocaleString()} | 
                        Output: ${result.tokens.output.toLocaleString()} | 
                        Thinking: ${result.tokens.thinking.toLocaleString()}<br>
                        <strong>💰 Costo:</strong> $${result.cost.toFixed(6)} 
                        (<span style="color: #68d391;">${(result.cost * 100).toFixed(2)}¢</span>)
                    `;
                    
                    document.getElementById('aiOutput').scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('Errore AI: ' + result.error);
                }
            } catch (error) {
                console.error('[AI] Errore:', error);
                alert('Errore chiamata AI: ' + error.message);
            } finally {
                btn.textContent = originalText;
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>
