<?php
/**
 * Esegue prompt con Gemini 3 Pro + Cost Tracking
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Carica dependencies per cost tracking
require_once __DIR__ . '/../modules/margynomic/config/config.php';
require_once __DIR__ . '/../modules/margynomic/admin/creaexcel/ai/core/CostCalculator.php';

try {
    $userPrompt = $_POST['prompt'] ?? '';
    $filePath = $_POST['file'] ?? '';
    
    if (empty($userPrompt)) {
        throw new Exception('Prompt vuoto');
    }
    
    // Carica config AI
    $aiConfigPath = __DIR__ . '/../modules/margynomic/admin/creaexcel/ai/config/ai_config.php';
    
    if (!file_exists($aiConfigPath)) {
        throw new Exception('AI config non trovato');
    }
    
    $aiConfig = require $aiConfigPath;
    
    if (empty($aiConfig['gemini_api_key'])) {
        throw new Exception('Gemini API key mancante');
    }
    
    // AUTO-DISCOVERY: Trova file correlati
    $relatedFiles = [];
    $fileContent = '';
    
    error_log("[PROMPT] File richiesto: {$filePath}");
    
    if (!empty($filePath)) {
        $fullPath = dirname(__DIR__) . '/' . ltrim($filePath, '/');
        error_log("[PROMPT] Path completo: {$fullPath}");
        error_log("[PROMPT] File esiste? " . (file_exists($fullPath) ? 'SI' : 'NO'));
        
        if (file_exists($fullPath)) {
            // Leggi file principale
            $mainContent = file_get_contents($fullPath);
            $fileContent .= "═══ FILE PRINCIPALE: {$filePath} ═══\n\n";
            $fileContent .= $mainContent;
            
            // AUTO-DISCOVERY: Estrai file correlati
            $baseDir = dirname($fullPath);
            
            // 1. Trova script JS caricati
            if (preg_match_all('/<script[^>]+src=["\']([^"\']+)["\']/', $mainContent, $matches)) {
                foreach ($matches[1] as $jsPath) {
                    // Risolvi path relativo
                    if (strpos($jsPath, 'http') === false) {
                        $jsFullPath = realpath($baseDir . '/' . $jsPath);
                        if ($jsFullPath && file_exists($jsFullPath)) {
                            $relatedFiles[] = ['path' => $jsFullPath, 'type' => 'JS'];
                        }
                    }
                }
            }
            
            // 2. Trova include/require PHP
            if (preg_match_all('/(?:include|require)(?:_once)?\s*[(\'"]\s*([^)\'"\s]+)/', $mainContent, $matches)) {
                foreach ($matches[1] as $phpPath) {
                    $phpFullPath = realpath($baseDir . '/' . $phpPath);
                    if ($phpFullPath && file_exists($phpFullPath) && strpos($phpFullPath, 'config.php') === false) {
                        $relatedFiles[] = ['path' => $phpFullPath, 'type' => 'PHP'];
                    }
                }
            }
            
            // 3. Leggi file correlati (max 3 per non esplodere i token)
            foreach (array_slice($relatedFiles, 0, 3) as $related) {
                $relContent = file_get_contents($related['path']);
                $relName = basename($related['path']);
                
                $fileContent .= "\n\n═══ FILE CORRELATO ({$related['type']}): {$relName} ═══\n\n";
                
                // Limita a 30k caratteri per file
                if (strlen($relContent) > 30000) {
                    $relContent = substr($relContent, 0, 30000) . "\n\n[... file troncato ...]";
                }
                
                $fileContent .= $relContent;
            }
            
            // Limita totale finale a 100k caratteri
            if (strlen($fileContent) > 100000) {
                $fileContent = substr($fileContent, 0, 100000) . "\n\n[... contenuto totale troncato per token limit ...]";
            }
            
            error_log("[PROMPT] Caratteri letti: " . strlen($fileContent));
            error_log("[PROMPT] File correlati trovati: " . count($relatedFiles));
        } else {
            error_log("[PROMPT] ERRORE: File non trovato - {$fullPath}");
            $fileContent = "[ERRORE: File '{$filePath}' non trovato. Claude deve usare MCP read_file per leggerlo direttamente.]";
        }
    } else {
        error_log("[PROMPT] WARN: Nessun file specificato");
        $fileContent = "[NESSUN FILE SPECIFICATO: Claude deve chiedere quale file analizzare o usare MCP per trovarlo.]";
    }
    
    // META-PROMPT: chiedi a Gemini di migliorare il prompt
    $metaPrompt = "Sei un senior prompt engineer. Il tuo compito è MIGLIORARE il prompt che l'utente ha scritto, rendendolo più completo, preciso e operativo per Claude AI.

PROMPT ORIGINALE UTENTE:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$userPrompt}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

FILE DA ANALIZZARE (con auto-discovery file correlati):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$fileContent}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

FILE CORRELATI INDIVIDUATI:
" . (!empty($relatedFiles) ? implode("\n", array_map(fn($f) => "- " . basename($f['path']) . " ({$f['type']})", $relatedFiles)) : "Nessun file correlato trovato") . "
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

TASK:
1. Analizza il file per estrarre:
   - Funzioni JavaScript rilevanti
   - Endpoint chiamati
   - Elementi DOM coinvolti
   - Event listener
   - Flusso logico chiave

2. RISCRIVI il prompt originale AGGIUNGENDO:
   - Contesto tecnico estratto dai file (principale + correlati)
   - Estratti di codice rilevanti (funzioni chiave, event listener, callback)
   - Dettagli implementativi rilevanti
   - Punti critici da analizzare
   - Flusso evento specifico
   - Se problema in file principale ma soluzione in file correlato: INDICALO chiaramente

3. MANTIENI:
   - Stesso formato con sezioni (RUOLO, SCOPO, CONTESTO, ecc)
   - Stessi vincoli dell'utente
   - Stesso contratto output
   - Stile operativo diretto

4. OUTPUT FINALE:
   Restituisci SOLO il prompt migliorato, pronto per essere dato a Claude.
   NON aggiungere spiegazioni, NON fare introduzioni.
   SOLO il prompt formattato e completo.";
    
    // Chiama Gemini API
    $apiKey = $aiConfig['gemini_api_key'];
    $model = $aiConfig['default_llm_model'] ?? 'gemini-2.0-flash-thinking-exp-1219';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $metaPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => 8000
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL error: {$error}");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP {$httpCode}: " . substr($response, 0, 200));
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON decode error: ' . json_last_error_msg());
    }
    
    $output = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Nessun output';
    
    // ESTRAI TOKEN COUNT
    $inputTokens = $data['usageMetadata']['promptTokenCount'] ?? 0;
    $outputTokens = $data['usageMetadata']['candidatesTokenCount'] ?? 0;
    $thinkingTokens = $data['usageMetadata']['thoughtsTokenCount'] ?? 0;
    $totalTokens = $data['usageMetadata']['totalTokenCount'] ?? 0;
    
    // CALCOLA COSTI
    $costs = CostCalculator::calculateGeminiCost($inputTokens, $outputTokens, $thinkingTokens);
    
    // SALVA IN DATABASE
    try {
        $pdo = getDbConnection();
        
        // User ID (admin dalla sessione o 1 default)
        session_start();
        $userId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 1;
        
        CostCalculator::saveUsage($pdo, [
            'user_id' => $userId,
            'provider' => 'gemini',
            'model' => $model,
            'operation' => 'prompt_improve',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'thinking_tokens' => $thinkingTokens,
            'total_tokens' => $totalTokens,
            'input_cost' => $costs['input_cost'],
            'output_cost' => $costs['output_cost'],
            'thinking_cost' => $costs['thinking_cost'],
            'total_cost' => $costs['total_cost'],
            'field_name' => 'prompt_improvement',
            'product_sku' => null,
            'attempt' => 1,
            'was_repaired' => 0
        ]);
        
        error_log("Prompt cost tracked: {$costs['total_cost']} USD (input:{$inputTokens} out:{$outputTokens} thinking:{$thinkingTokens})");
        
    } catch (Exception $e) {
        error_log("Cost tracking failed: " . $e->getMessage());
        // Non bloccare l'esecuzione se tracking fallisce
    }
    
    echo json_encode([
        'success' => true,
        'output' => $output,
        'model' => $model,
        'tokens' => [
            'input' => $inputTokens,
            'output' => $outputTokens,
            'thinking' => $thinkingTokens,
            'total' => $totalTokens
        ],
        'cost' => $costs['total_cost']
    ]);
    
} catch (Exception $e) {
    error_log("AI Execute error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
