<?php
/**
 * LlmClient
 * Gestisce chiamate API a Claude/OpenAI
 */
class LlmClient
{
    private $config;
    private $provider;
    private $model;
    private $lastThinking = null; // Store last thinking process

    public function __construct($config, $provider = null, $model = null)
    {
        $this->config = $config;
        $this->provider = $provider ?? ($config['default_llm_provider'] ?? 'anthropic');
        $this->model = $model ?? ($config['default_llm_model'] ?? 'claude-3-5-sonnet-20241022');
    }

    /**
     * Genera contenuto tramite LLM con fallback automatico
     * 
     * @param string $prompt Prompt da inviare
     * @param int $maxTokens Token massimi risposta
     * @return string Contenuto generato
     */
    public function generate($prompt, $maxTokens = 16384) // DEFAULT ALTO - no limiti
    {
        switch ($this->provider) {
            case 'anthropic':
            case 'claude':
                return $this->callClaudeWithFallback($prompt, $maxTokens);
            
            case 'openai':
            case 'gpt':
                return $this->callOpenAIWithFallback($prompt, $maxTokens);
            
            case 'gemini':
            case 'google':
                return $this->callGemini($prompt, $maxTokens); // NESSUN FALLBACK - SOLO gemini-3-pro-preview
            
            default:
                throw new Exception("Provider non supportato: {$this->provider}");
        }
    }
    
    /**
     * Chiamata Claude con fallback automatico su modelli alternativi
     */
    private function callClaudeWithFallback($prompt, $maxTokens)
    {
        // Modelli da provare in ordine (ultimi rilasci → versioni stabili)
        $fallbackModels = [
            $this->model, // Modello configurato
            'claude-3-5-haiku-20241022',   // Haiku 3.5 (latest, fast)
            'claude-3-5-sonnet-20241022',  // Sonnet 3.5 (latest, quality)
            'claude-3-5-sonnet-20240620',  // Sonnet 3.5 (stable)
            'claude-3-sonnet-20240229',    // Sonnet 3 (legacy)
            'claude-3-haiku-20240307'      // Haiku 3 (legacy)
        ];
        
        $lastError = null;
        
        foreach (array_unique($fallbackModels) as $model) {
            try {
                $originalModel = $this->model;
                $this->model = $model;
                
                $result = $this->callClaude($prompt, $maxTokens);
                
                // Se ha funzionato con un modello diverso, logga
                if ($model !== $originalModel) {
                    error_log("LlmClient: Fallback su modello $model (originale: $originalModel fallito)");
                }
                
                return $result;
                
            } catch (Exception $e) {
                $lastError = $e;
                // Se errore 404, prova modello successivo
                if (strpos($e->getMessage(), '404') !== false) {
                    continue;
                }
                // Altri errori (rate limit, auth, etc) rilancia subito
                throw $e;
            }
        }
        
        // Se tutti i modelli falliscono, rilancia ultimo errore
        throw new Exception("Tutti i modelli Claude falliti. Ultimo errore: " . $lastError->getMessage());
    }
    
    /**
     * Chiamata OpenAI con fallback automatico su modelli alternativi
     */
    private function callOpenAIWithFallback($prompt, $maxTokens)
    {
        // Modelli da provare in ordine
        $fallbackModels = [
            $this->model, // Modello configurato
            'o1-preview',     // O1 preview (reasoning)
            'o1-mini',        // O1 mini (reasoning economico)
            'gpt-4o',         // GPT-4o (latest)
            'gpt-4-turbo',    // GPT-4 Turbo
            'gpt-3.5-turbo'   // GPT-3.5 (economico)
        ];
        
        $lastError = null;
        
        foreach (array_unique($fallbackModels) as $model) {
            try {
                $originalModel = $this->model;
                $this->model = $model;
                
                $result = $this->callOpenAI($prompt, $maxTokens);
                
                // Se ha funzionato con un modello diverso, logga
                if ($model !== $originalModel) {
                    CentralLogger::info('llm_client', "Fallback su modello OpenAI", [
                        'original_model' => $originalModel,
                        'fallback_model' => $model
                    ]);
                }
                
                return $result;
                
            } catch (Exception $e) {
                $lastError = $e;
                
                // Se errore 404 o model not found, prova modello successivo
                if (strpos($e->getMessage(), '404') !== false || 
                    strpos($e->getMessage(), 'model') !== false) {
                    continue;
                }
                
                // Altri errori (rate limit, auth, etc) rilancia subito
                throw $e;
            }
        }
        
        // Se tutti i modelli falliscono, rilancia ultimo errore
        throw new Exception("Tutti i modelli OpenAI falliti. Ultimo errore: " . $lastError->getMessage());
    }

    /**
     * Chiamata API Claude (Anthropic)
     */
    private function callClaude($prompt, $maxTokens)
    {
        $apiKey = $this->config['anthropic_api_key'] ?? '';
        
        if (empty($apiKey)) {
            throw new Exception("Anthropic API key non configurata in ai_config.php");
        }

        $data = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];
        
        // Enable Extended Thinking for supported models (optional, gracefully degraded)
        if (strpos($this->model, 'sonnet') !== false) {
            // Only Sonnet models support extended thinking currently
            $data['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => 1000 // Conservative budget
            ];
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => $this->config['request_timeout'] ?? 600 // NESSUN LIMITE (10 min default)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL Error: $curlError");
        }

        if ($httpCode !== 200) {
            $errorDetail = json_decode($response, true);
            $errorMsg = $errorDetail['error']['message'] ?? $response;
            throw new Exception("Anthropic API Error (HTTP $httpCode): $errorMsg");
        }

        $result = json_decode($response, true);
        
        // Reset thinking
        $this->lastThinking = null;
        
        // Extract thinking if present
        if (isset($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $block) {
                if (isset($block['type']) && $block['type'] === 'thinking' && isset($block['thinking'])) {
                    $this->lastThinking = $block['thinking'];
                    break;
                }
            }
        }
        
        // Extract text content
        $textContent = '';
        if (isset($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $block) {
                if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $textContent .= $block['text'];
                }
            }
        }
        
        if (empty($textContent)) {
            throw new Exception("Risposta API non valida: nessun contenuto text trovato");
        }

        return $textContent;
    }
    
    /**
     * Get last thinking process (if available)
     */
    public function getLastThinking()
    {
        return $this->lastThinking;
    }

    /**
     * Chiamata API OpenAI
     */
    private function callOpenAI($prompt, $maxTokens)
    {
        $apiKey = $this->config['openai_api_key'] ?? '';
        
        if (empty($apiKey)) {
            throw new Exception("OpenAI API key non configurata in ai_config.php");
        }

        // Determina se è un modello O1 (reasoning)
        $isO1Model = (strpos($this->model, 'o1') === 0 || strpos($this->model, 'o3') === 0);
        
        $data = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];
        
        // O1 usa max_completion_tokens, altri modelli usano max_tokens
        if ($isO1Model) {
            $data['max_completion_tokens'] = $maxTokens;
            // O1 NON supporta temperature, system messages, function calling
        } else {
            $data['max_tokens'] = $maxTokens;
            $data['temperature'] = $this->config['temperature'] ?? 0.7;
        }

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => $this->config['request_timeout'] ?? 600 // NESSUN LIMITE
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL Error: $curlError");
        }

        if ($httpCode !== 200) {
            $errorDetail = json_decode($response, true);
            $errorMsg = $errorDetail['error']['message'] ?? $response;
            throw new Exception("OpenAI API Error (HTTP $httpCode): $errorMsg");
        }

        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception("Risposta OpenAI non valida: " . json_encode($result));
        }
        
        // Estrai reasoning tokens se presenti (O1 models)
        if ($isO1Model && isset($result['usage']['completion_tokens_details']['reasoning_tokens'])) {
            $reasoningTokens = $result['usage']['completion_tokens_details']['reasoning_tokens'];
            
            // Store thinking/reasoning info
            $this->lastThinking = "O1 Reasoning: {$reasoningTokens} tokens utilizzati per il ragionamento interno.";
            
            CentralLogger::debug('llm_client', 'O1 Reasoning captured', [
                'reasoning_tokens' => $reasoningTokens,
                'total_tokens' => $result['usage']['total_tokens'] ?? 'N/D'
            ]);
        }
        
        return $result['choices'][0]['message']['content'];
    }
    
    /**
     * Chiamata Gemini con fallback automatico su modelli alternativi
     */
    private function callGeminiWithFallback($prompt, $maxTokens)
    {
        // Modelli da provare in ordine
        $fallbackModels = [
            $this->model, // Modello configurato
            'gemini-3-pro-preview',         // Gemini 3 Pro (latest preview)
            'gemini-3-pro-image-preview',   // Gemini 3 Pro con immagini
            'gemini-2.0-flash-exp',         // Gemini 2.0 Flash
            'gemini-2.0-flash-thinking-exp', // Gemini 2.0 con thinking
            'gemini-1.5-pro',               // Gemini 1.5 Pro (stable)
            'gemini-1.5-flash'              // Gemini 1.5 Flash (economico)
        ];
        
        $lastError = null;
        
        foreach (array_unique($fallbackModels) as $model) {
            try {
                $originalModel = $this->model;
                $this->model = $model;
                
                $result = $this->callGemini($prompt, $maxTokens);
                
                // Se ha funzionato con un modello diverso, logga
                if ($model !== $originalModel) {
                    CentralLogger::info('llm_client', "Fallback su modello Gemini", [
                        'original_model' => $originalModel,
                        'fallback_model' => $model
                    ]);
                }
                
                return $result;
                
            } catch (Exception $e) {
                $lastError = $e;
                
                // Se errore 404 o model not found, prova modello successivo
                if (strpos($e->getMessage(), '404') !== false || 
                    strpos($e->getMessage(), 'model') !== false) {
                    continue;
                }
                
                // Altri errori (rate limit, auth, etc) rilancia subito
                throw $e;
            }
        }
        
        // Se tutti i modelli falliscono, rilancia ultimo errore
        throw new Exception("Tutti i modelli Gemini falliti. Ultimo errore: " . $lastError->getMessage());
    }
    
    /**
     * Chiamata API Google Gemini
     */
    private function callGemini($prompt, $maxTokens)
    {
        $apiKey = $this->config['gemini_api_key'] ?? '';
        
        if (empty($apiKey)) {
            throw new Exception("Gemini API key non configurata in ai_config.php");
        }
        
        // Costruisci URL endpoint con API key
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$apiKey}";
        
        // Formato richiesta Gemini CON GOOGLE SEARCH TOOL
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => $this->config['temperature'] ?? 0.7
            ],
            'tools' => [
                [
                    'googleSearch' => new stdClass() // Enable Google Search
                ]
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => $this->config['request_timeout'] ?? 600 // NESSUN LIMITE
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("cURL Error: $curlError");
        }
        
        if ($httpCode !== 200) {
            $errorDetail = json_decode($response, true);
            $errorMsg = $errorDetail['error']['message'] ?? $response;
            throw new Exception("Gemini API Error (HTTP $httpCode): $errorMsg");
        }
        
        $result = json_decode($response, true);
        
        // Verifica che ci siano candidates
        if (!isset($result['candidates'][0])) {
            throw new Exception("Risposta Gemini senza candidates: " . json_encode($result));
        }
        
        $candidate = $result['candidates'][0];
        
        // Verifica finishReason
        $finishReason = $candidate['finishReason'] ?? 'UNKNOWN';
        if ($finishReason === 'MAX_TOKENS') {
            throw new Exception("Gemini ha raggiunto MAX_TOKENS. Aumenta maxOutputTokens. (thoughtsTokens: " . 
                ($result['usageMetadata']['thoughtsTokenCount'] ?? 0) . ")");
        }
        
        // Gemini response format: candidates[0].content.parts[0].text
        if (empty($candidate['content']['parts']) || !isset($candidate['content']['parts'][0]['text'])) {
            // Content vuoto - controlla se è per MAX_TOKENS
            $thoughtsTokens = $result['usageMetadata']['thoughtsTokenCount'] ?? 0;
            $promptTokens = $result['usageMetadata']['promptTokenCount'] ?? 0;
            throw new Exception("Risposta Gemini vuota. finishReason: {$finishReason}, " . 
                "thoughtsTokens: {$thoughtsTokens}, promptTokens: {$promptTokens}");
        }
        
        $content = $candidate['content']['parts'][0]['text'];
        
        // Log usage e salva costi
        if (isset($result['usageMetadata'])) {
            $inputTokens = $result['usageMetadata']['promptTokenCount'] ?? 0;
            $outputTokens = $result['usageMetadata']['candidatesTokenCount'] ?? 0;
            $thinkingTokens = $result['usageMetadata']['thoughtsTokenCount'] ?? 0;
            $totalTokens = $result['usageMetadata']['totalTokenCount'] ?? 0;
            
            $usage = [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $totalTokens
            ];
            
            if ($thinkingTokens > 0) {
                $usage['thoughts_tokens'] = $thinkingTokens;
                
                // Store thinking info for Gemini 3 (non espone thinking text, solo token count)
                $this->lastThinking = "🧠 Gemini 3 Pro Preview Reasoning:\n\n";
                $this->lastThinking .= "Questo modello ha utilizzato {$thinkingTokens} tokens di ragionamento interno ";
                $this->lastThinking .= "per analizzare il prompt, pianificare la struttura dei contenuti, ";
                $this->lastThinking .= "e garantire coerenza tra i campi generati.\n\n";
                $this->lastThinking .= "Il processo di thinking permette al modello di:\n";
                $this->lastThinking .= "• Verificare i limiti di lunghezza prima di scrivere\n";
                $this->lastThinking .= "• Coordinare keywords tra title/description/bullets\n";
                $this->lastThinking .= "• Evitare ripetizioni tra i campi\n";
                $this->lastThinking .= "• Mantenere il tono marketing coerente\n\n";
                $this->lastThinking .= "Nota: Gemini 3 non espone il testo completo del reasoning, solo il conteggio token.";
            }
            
            CentralLogger::debug('llm_client', 'Gemini usage', $usage);
            
            // Salva usage con costi
            $this->saveUsageMetrics('gemini', $this->model, $inputTokens, $outputTokens, $thinkingTokens);
        }
        
        return $content;
    }

    /**
     * Test connessione API
     */
    public function testConnection()
    {
        try {
            $originalModel = $this->model;
            $response = $this->generate("Rispondi solo 'OK'", 1000); // Ampio per Gemini 3 thinking
            
            $usedModel = $this->model;
            $modelChanged = $usedModel !== $originalModel;
            
            return [
                'success' => true, 
                'provider' => $this->provider,
                'model' => $usedModel,
                'model_fallback' => $modelChanged,
                'original_model' => $originalModel,
                'response' => $response
            ];
        } catch (Exception $e) {
            return [
                'success' => false, 
                'provider' => $this->provider,
                'model' => $this->model,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Ottieni info provider corrente
     */
    public function getProviderInfo()
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'has_api_key' => !empty($this->config[$this->provider . '_api_key'])
        ];
    }
    
    /**
     * Salva metriche usage nel database
     */
    private function saveUsageMetrics($provider, $model, $inputTokens, $outputTokens, $thinkingTokens = 0)
    {
        try {
            // Require CostCalculator
            require_once __DIR__ . '/CostCalculator.php';
            
            // Calcola costi
            $costs = CostCalculator::calculateGeminiCost($inputTokens, $outputTokens, $thinkingTokens);
            
            // Get DB connection
            require_once __DIR__ . '/../../../../config/database.php';
            $pdo = Database::getInstance()->getConnection();
            
            // Get user_id from session (usa stessa logica di ai_api.php)
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Priority: selected_user_id > admin_id
            $userId = isset($_SESSION['selected_user_id']) && $_SESSION['selected_user_id'] > 0 
                ? (int)$_SESSION['selected_user_id'] 
                : ($_SESSION['admin_id'] ?? null);
            
            if (!$userId) {
                // Se no session, skippa saving (es. test CLI)
                return;
            }
            
            // Prepara dati
            $data = [
                'user_id' => $userId,
                'provider' => $provider,
                'model' => $model,
                'operation' => 'generate_content', // Default, può essere overridato
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'thinking_tokens' => $thinkingTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'input_cost' => $costs['input_cost'],
                'output_cost' => $costs['output_cost'],
                'thinking_cost' => $costs['thinking_cost'],
                'total_cost' => $costs['total_cost'],
                'field_name' => null,
                'product_sku' => null,
                'attempt' => 1,
                'was_repaired' => false
            ];
            
            // Salva nel DB
            CostCalculator::saveUsage($pdo, $data);
            
            CentralLogger::info('llm_client', 'Usage saved', [
                'provider' => $provider,
                'model' => $model,
                'cost' => $costs['total_cost']
            ]);
            
        } catch (Exception $e) {
            // Non bloccare la generazione se save fallisce
            CentralLogger::error('llm_client', 'Failed to save usage', [
                'error' => $e->getMessage()
            ]);
        }
    }
}

