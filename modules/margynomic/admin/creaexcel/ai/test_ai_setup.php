<?php
/**
 * Test AI Setup
 * Verifica configurazione AI Content Generator
 * 
 * URL: https://www.skualizer.com/modules/margynomic/admin/creaexcel/ai/test_ai_setup.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/CentralLogger.php';
require_once __DIR__ . '/core/AiEngine.php';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🧪 Test AI Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 32px; margin-bottom: 10px; }
        .header p { opacity: 0.9; font-size: 16px; }
        .content { padding: 30px; }
        .component {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        .component h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
        }
        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-right: 10px;
        }
        .status-success { background: #d1fae5; color: #065f46; }
        .status-error { background: #fee2e2; color: #991b1b; }
        .status-warning { background: #fef3c7; color: #92400e; }
        .status-info { background: #dbeafe; color: #1e40af; }
        .detail {
            background: white;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 14px;
            font-family: 'Monaco', 'Courier New', monospace;
        }
        .detail strong { color: #667eea; }
        .summary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            font-size: 20px;
            font-weight: 600;
        }
        .summary.error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        .instructions {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 20px;
            margin-top: 20px;
            border-radius: 8px;
        }
        .instructions h4 {
            color: #92400e;
            margin-bottom: 10px;
        }
        .instructions ol {
            margin-left: 20px;
            color: #78350f;
        }
        .instructions li {
            margin: 8px 0;
        }
        .instructions code {
            background: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🧪 AI Content Generator - Test Setup</h1>
            <p>Verifica configurazione Google Gemini 3 Pro Preview + Test generazione reale</p>
        </div>
        
        <div class="content">
            <?php
            try {
                // Test 1: Carica AiEngine
                echo '<div class="component">';
                echo '<h3>1️⃣ Inizializzazione AiEngine</h3>';
                
                $testUserId = 1; // User ID di test
                $engine = new AiEngine($testUserId);
                
                echo '<span class="status status-success">✅ SUCCESS</span>';
                echo '<div class="detail"><strong>User ID:</strong> ' . $testUserId . '</div>';
                echo '</div>';
                
                // Test 2: Test configurazione completa
                echo '<div class="component">';
                echo '<h3>2️⃣ Test Configurazione AI</h3>';
                
                $configTest = $engine->testAiConfiguration();
                
                if ($configTest['all_success']) {
                    echo '<span class="status status-success">✅ ALL TESTS PASSED</span>';
                } else {
                    echo '<span class="status status-error">❌ SOME TESTS FAILED</span>';
                }
                
                echo '<div class="detail">';
                
                // Policy File
                $policyResult = $configTest['components']['policy_file'];
                if ($policyResult['success']) {
                    echo '<strong>Policy File:</strong> ✅ Caricato (' . $policyResult['fields_count'] . ' fields)<br>';
                } else {
                    echo '<strong>Policy File:</strong> ❌ ' . htmlspecialchars($policyResult['error']) . '<br>';
                }
                
                // LLM Connection
                $llmResult = $configTest['components']['llm_connection'];
                if ($llmResult['success']) {
                    echo '<strong>LLM Connection:</strong> ✅ Connesso (' . $llmResult['provider'] . ' - ' . $llmResult['model'] . ')<br>';
                    
                    // Mostra se ha usato fallback
                    if (!empty($llmResult['model_fallback'])) {
                        echo '<strong>⚠️ Fallback attivo:</strong> Modello originale (' . $llmResult['original_model'] . ') non disponibile<br>';
                        echo '<strong>Modello usato:</strong> ' . $llmResult['model'] . ' (fallback automatico)<br>';
                    }
                    
                    echo '<strong>Test Response:</strong> "' . htmlspecialchars($llmResult['response']) . '"<br>';
                    
                    // Mostra info specifiche Gemini
                    if (strpos($llmResult['provider'], 'gemini') !== false || strpos($llmResult['provider'], 'google') !== false) {
                        echo '<strong>🚀 Provider:</strong> Google Gemini<br>';
                        if (strpos($llmResult['model'], 'gemini-3') === 0) {
                            echo '<strong>🧠 Gemini 3:</strong> ✅ Latest Preview (reasoning avanzato)<br>';
                        }
                    }
                } else {
                    echo '<strong>LLM Connection:</strong> ❌ ' . htmlspecialchars($llmResult['error']) . '<br>';
                    echo '<div style="background:#fee2e2;padding:10px;border-radius:6px;margin-top:10px;">';
                    echo '<strong style="color:#991b1b;">ERRORE DETTAGLIATO:</strong><br>';
                    echo '<pre style="color:#7f1d1d;font-size:12px;white-space:pre-wrap;">' . htmlspecialchars($llmResult['error']) . '</pre>';
                    echo '</div>';
                }
                
                // Prompt Builder
                $promptResult = $configTest['components']['prompt_builder'];
                if ($promptResult['success']) {
                    echo '<strong>Prompt Builder:</strong> ✅ Funzionante (prompt: ' . $promptResult['prompt_length'] . ' chars)<br>';
                } else {
                    echo '<strong>Prompt Builder:</strong> ❌ ' . htmlspecialchars($promptResult['error']) . '<br>';
                }
                
                // Content Validator
                $validatorResult = $configTest['components']['content_validator'];
                if ($validatorResult['success']) {
                    echo '<strong>Content Validator:</strong> ✅ Funzionante<br>';
                } else {
                    echo '<strong>Content Validator:</strong> ❌ ' . htmlspecialchars($validatorResult['error']) . '<br>';
                }
                
                // Provider Info
                $providerInfo = $configTest['provider_info'];
                echo '<br><div style="background:#dbeafe;padding:12px;border-radius:8px;border-left:4px solid #1e40af;margin:10px 0;">';
                echo '<strong style="color:#1e40af;">📊 CONFIGURAZIONE ATTIVA:</strong><br>';
                echo '<strong>Provider:</strong> ' . strtoupper($providerInfo['provider']) . '<br>';
                echo '<strong>Model:</strong> <code style="background:#bfdbfe;padding:2px 6px;border-radius:4px;">' . $providerInfo['model'] . '</code><br>';
                echo '<strong>API Key:</strong> ' . ($providerInfo['has_api_key'] ? '✅ Configurata' : '❌ Mancante') . '<br>';
                
                // Calcola costo stimato per generazione
                $isGemini = (strpos($providerInfo['provider'], 'gemini') !== false || strpos($providerInfo['provider'], 'google') !== false);
                if ($isGemini) {
                    if (strpos($providerInfo['model'], 'gemini-3') === 0) {
                        echo '<strong>💰 Costo stimato:</strong> GRATIS (free tier) o ~$0.01-0.03 (paid)<br>';
                        echo '<strong>🧠 Reasoning:</strong> ✅ Avanzato (Gemini 3)<br>';
                    } elseif (strpos($providerInfo['model'], 'gemini-2') === 0) {
                        echo '<strong>💰 Costo stimato:</strong> GRATIS (free tier)<br>';
                        echo '<strong>🧠 Reasoning:</strong> ✅ Nativo<br>';
                    } else {
                        echo '<strong>💰 Costo stimato:</strong> ~$0.01-0.05 per prodotto<br>';
                    }
                } else {
                    echo '<strong>💰 Costo stimato:</strong> ~$0.03-0.08 per prodotto<br>';
                }
                echo '</div>';
                
                echo '</div>';
                echo '</div>';
                
                // Test 3: Test Policy per campo specifico
                echo '<div class="component">';
                echo '<h3>3️⃣ Policy Amazon - Esempio "item_name"</h3>';
                
                $titlePolicy = $engine->getPolicyForField('item_name');
                
                if ($titlePolicy) {
                    echo '<span class="status status-success">✅ TROVATA</span>';
                    echo '<div class="detail">';
                    echo '<strong>Campo:</strong> item_name (Titolo Prodotto)<br>';
                    echo '<strong>Min Length:</strong> ' . ($titlePolicy['min_length'] ?? 'N/D') . ' caratteri<br>';
                    echo '<strong>Max Length:</strong> ' . ($titlePolicy['max_length'] ?? 'N/D') . ' caratteri<br>';
                    echo '<strong>Required:</strong> ' . ($titlePolicy['required'] ? 'Sì' : 'No') . '<br>';
                    
                    if (!empty($titlePolicy['recommendations'])) {
                        echo '<strong>Raccomandazioni:</strong><br>';
                        foreach ($titlePolicy['recommendations'] as $rec) {
                            echo '&nbsp;&nbsp;• ' . htmlspecialchars($rec) . '<br>';
                        }
                    }
                    echo '</div>';
                } else {
                    echo '<span class="status status-error">❌ NON TROVATA</span>';
                }
                
                echo '</div>';
                
                // Test 4: Test Generazione Reale (solo se LLM funziona)
                if ($llmResult['success']) {
                    echo '<div class="component">';
                    echo '<h3>4️⃣ Test Generazione Contenuto (Gemini 3 Pro Preview)</h3>';
                    
                    try {
                        $startTime = microtime(true);
                        
                        // Test context minimale
                        $testContext = [
                            'sku' => 'TEST-001',
                            'brand' => 'TestBrand',
                            'product_type' => 'Test Product',
                            'format' => '100g',
                            'current_title' => 'Test Product 100g'
                        ];
                        
                        echo '<span class="status status-warning">⏳ Generazione in corso (può richiedere 30-120 secondi)...</span>';
                        echo '<div class="detail">';
                        echo '<strong>Test Field:</strong> item_name (titolo prodotto)<br>';
                        echo '<strong>Context:</strong> ' . json_encode($testContext) . '<br>';
                        echo '<strong>⚠️ NOTA:</strong> Gemini 3 Pro Preview è lento, attendi fino a 2 minuti<br><br>';
                        
                        // Flush output per mostrare "in corso"
                        ob_flush();
                        flush();
                        
                        // Genera campo di test
                        $result = $engine->generateFieldContent('item_name', $testContext);
                        
                        $endTime = microtime(true);
                        $duration = round($endTime - $startTime, 2);
                        
                        if ($result['success']) {
                            echo '<strong>⏱️ Tempo:</strong> ' . $duration . ' secondi<br>';
                            echo '<strong>✅ Risultato:</strong><br>';
                            echo '<div style="background:#d1fae5;padding:10px;border-radius:6px;margin-top:10px;">';
                            echo '<pre style="color:#065f46;white-space:pre-wrap;">' . htmlspecialchars($result['content']) . '</pre>';
                            echo '</div>';
                            
                            // Mostra validazione
                            if (!empty($result['validation'])) {
                                echo '<br><strong>📊 Validazione:</strong><br>';
                                echo '<div style="background:#fef3c7;padding:10px;border-radius:6px;margin-top:5px;">';
                                $val = $result['validation'];
                                echo '<strong>Lunghezza:</strong> ' . ($val['length'] ?? 'N/D') . ' caratteri<br>';
                                echo '<strong>Valido:</strong> ' . ($val['valid'] ? '✅ Sì' : '❌ No') . '<br>';
                                
                                if (!empty($val['errors'])) {
                                    echo '<strong style="color:#991b1b;">Errori:</strong><br>';
                                    foreach ($val['errors'] as $err) {
                                        echo '&nbsp;&nbsp;• ' . htmlspecialchars($err) . '<br>';
                                    }
                                }
                                
                                if (!empty($val['warnings'])) {
                                    echo '<strong style="color:#92400e;">Warnings:</strong><br>';
                                    foreach ($val['warnings'] as $warn) {
                                        echo '&nbsp;&nbsp;• ' . htmlspecialchars($warn) . '<br>';
                                    }
                                }
                                echo '</div>';
                            }
                            
                            // Mostra reasoning se disponibile
                            if (!empty($result['reasoning_tokens'])) {
                                echo '<br><strong>🧠 Reasoning Tokens:</strong> ' . $result['reasoning_tokens'] . '<br>';
                            }
                            
                        } else {
                            echo '<strong>❌ Generazione Fallita</strong><br>';
                            echo '<div style="background:#fee2e2;padding:10px;border-radius:6px;margin-top:10px;">';
                            echo '<pre style="color:#991b1b;white-space:pre-wrap;">' . htmlspecialchars($result['error'] ?? 'Errore sconosciuto') . '</pre>';
                            echo '</div>';
                        }
                        
                        echo '</div>';
                        
                    } catch (Exception $e) {
                        echo '<span class="status status-error">❌ ERRORE</span>';
                        echo '<div class="detail" style="color: #991b1b;">';
                        echo '<strong>Exception:</strong> ' . htmlspecialchars($e->getMessage()) . '<br>';
                        echo '<strong>Trace:</strong><br><pre style="font-size:11px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
                        echo '</div>';
                    }
                    
                    echo '</div>';
                }
                
                // Summary finale
                if ($configTest['all_success']) {
                    echo '<div class="summary">
                        🎉 CONFIGURAZIONE COMPLETATA CON SUCCESSO!<br>
                        <small style="font-size: 14px; opacity: 0.9; margin-top: 10px; display: block;">
                        Tutti i componenti AI sono operativi e pronti all\'uso
                        </small>
                    </div>';
                } else {
                    echo '<div class="summary error">
                        ⚠️ CONFIGURAZIONE INCOMPLETA<br>
                        <small style="font-size: 14px; opacity: 0.9; margin-top: 10px; display: block;">
                        Alcuni componenti necessitano configurazione
                        </small>
                    </div>';
                    
                    // Istruzioni se manca API key
                    if (!$providerInfo['has_api_key']) {
                        echo '<div class="instructions">
                            <h4>📝 Prossimi Passi per Attivare LLM:</h4>
                            <ol>
                                <li>Apri il file: <code>modules/margynomic/admin/creaexcel/ai/config/ai_config.php</code></li>
                                <li>Inserisci la tua API key Anthropic:<br>
                                    <code>\'anthropic_api_key\' => \'sk-ant-api03-TUA_KEY_QUI\'</code>
                                </li>
                                <li>Ricarica questa pagina per verificare la connessione</li>
                                <li>Se tutto funziona, disabilita mock_mode:<br>
                                    <code>\'debug\' => [\'mock_mode\' => false]</code>
                                </li>
                            </ol>
                        </div>';
                    }
                }
                
            } catch (Exception $e) {
                echo '<div class="component">';
                echo '<span class="status status-error">❌ ERRORE FATALE</span>';
                echo '<div class="detail" style="color: #991b1b;">';
                echo '<strong>Exception:</strong> ' . htmlspecialchars($e->getMessage()) . '<br>';
                echo '<strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '<br>';
                echo '<strong>Trace:</strong><br><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>
    </div>
</body>
</html>

