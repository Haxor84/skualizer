<?php
/**
 * Step2_TitleGeneration
 * 
 * STEP 2: Title Generation con Template Enforcement
 * Model: claude-3-5-haiku-20241022 (fast + economico)
 * 
 * Input: keywords (da Step1), brand, weight
 * Output: string title rispettando template Brand - Prodotto Formato | Feature | Benefit
 */

require_once __DIR__ . '/../WorkflowStep.php';

class Step2_TitleGeneration extends WorkflowStep
{
    private array $structuredInstructions = [];
    
    /**
     * Set structured instructions from Step1
     */
    public function setStructuredInstructions(array $instructions): void
    {
        $this->structuredInstructions = $instructions;
        
        CentralLogger::debug('step2_title', 'Structured instructions received', [
            'has_product_essence' => !empty($instructions['product_essence']),
            'must_include_count' => count($instructions['must_include'] ?? []),
            'avoid_count' => count($instructions['avoid'] ?? [])
        ]);
    }
    
    /**
     * Esegue title generation
     * 
     * @param array $context Context workflow
     * @return array ['content' => string, 'success' => bool, 'validation' => array]
     */
    public function execute(array $context): array
    {
        return $this->executeWithRetry(function() use ($context) {
            
            $prompt = $this->buildPrompt($context);
            
            CentralLogger::debug('step2_title_generation', 'Calling Haiku', [
                'prompt_length' => strlen($prompt),
                'max_tokens' => $this->config['max_tokens']
            ]);
            
            // Call Gemini 3 Pro
            $response = $this->llmClient->generate(
                $prompt,
                $this->config['max_tokens']
            );
            
            CentralLogger::debug('step2_title_RAW', 'Gemini 3 Pro response', [
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200)
            ]);
            
            // Cleanup AI output (rimuovi newlines, trim)
            $title = trim($response);
            $title = preg_replace('/\s+/', ' ', $title); // Normalizza spazi
            
            CentralLogger::debug('step2_title_generation', 'Haiku response received', [
                'title_length' => strlen($title),
                'title_preview' => substr($title, 0, 100)
            ]);
            
            // DISABLED: Gemini 3 Pro genera già titoli ottimali con structured prompts
            // $title = $this->enforceTemplate($title, $context);
            
            // Validate con ContentValidator
            $validator = new ContentValidator($this->policyManager);
            $validation = $validator->validate('item_name', $title);
            
            CentralLogger::info('step2_title_generation', 'Title generated', [
                'title_length' => strlen($title),
                'title_preview' => $title,
                'validation_valid' => $validation['valid'],
                'validation_errors' => $validation['errors'] ?? [],
                'validation_warnings' => $validation['warnings'] ?? []
            ]);
            
            CentralLogger::debug('step2_title_VALIDATED', 'Validation result', [
                'length' => strlen($title),
                'is_valid' => $validation['valid'],
                'errors' => $validation['errors'] ?? [],
                'warnings' => $validation['warnings'] ?? [],
                'content_full' => $title
            ]);
            
            return [
                'content' => $title,
                'success' => $validation['valid'],
                'validation' => $validation
            ];
            
        }, $this->config['retry_attempts']);
    }
    
    /**
     * Build prompt da template con context substitution
     * 
     * @param array $context Context workflow
     * @return string Prompt completo
     */
    private function buildPrompt(array $context): string
    {
        // Check if we have structured instructions from WorkflowEngine (priority)
        if (!empty($this->structuredInstructions)) {
            return $this->appendRetryHint($this->buildStructuredPrompt($context));
        }
        
        $template = $this->loadPromptTemplate('title_generation.txt');
        
        // Extract structured prompts from Step1 (fallback dal context)
        $structured = $context['structured_prompts']['title'] ?? [];
        
        // Fallback se structured non disponibile (vecchie cache)
        if (empty($structured)) {
            CentralLogger::warning('step2_title', 'No structured prompts, using fallback', [
                'has_analysis' => !empty($context['analysis'])
            ]);
            
            $structured = [
                'product_essence' => '⚠️ Structured prompts non disponibili',
                'must_include' => [],
                'suggested_structure' => '{brand} - Prodotto {weight}',
                'features_priority' => [],
                'avoid' => []
            ];
        }
        
        // Format structured fields
        $productEssence = $structured['product_essence'] ?? '';
        $mustInclude = implode(', ', $structured['must_include'] ?? []);
        $suggestedStructure = $structured['suggested_structure'] ?? '';
        $featuresPriority = implode(', ', $structured['features_priority'] ?? []);
        $avoid = implode(', ', $structured['avoid'] ?? []);
        
        // Replace {brand} and {weight} in suggested_structure
        $suggestedStructure = str_replace('{brand}', $context['brand'] ?? 'NOBRAND', $suggestedStructure);
        $suggestedStructure = str_replace('{weight}', $context['weight'] ?? '100g', $suggestedStructure);
        
        // Top 20 keywords for reference
        $keywords = array_slice($context['keywords'] ?? [], 0, 20);
        $keywordsText = implode(', ', $keywords);
        
        $prompt = strtr($template, [
            '{PRODUCT_ESSENCE}' => $productEssence,
            '{MUST_INCLUDE}' => $mustInclude,
            '{SUGGESTED_STRUCTURE}' => $suggestedStructure,
            '{FEATURES_PRIORITY}' => $featuresPriority,
            '{AVOID}' => $avoid,
            '{KEYWORDS}' => $keywordsText,
            '{BRAND}' => $context['brand'] ?? 'NOBRAND',
            '{WEIGHT}' => $context['weight'] ?? '100g'
        ]);
        
        // Append retry hint se disponibile
        return $this->appendRetryHint($prompt);
    }
    
    /**
     * Build prompt usando structured instructions da Step1
     * 
     * @param array $context Context workflow
     * @return string Prompt completo
     */
    private function buildStructuredPrompt(array $context): string
    {
        $inst = $this->structuredInstructions;
        
        // Extract values
        $productEssence = $inst['product_essence'] ?? '';
        $mustInclude = $inst['must_include'] ?? [];
        $suggestedStructure = $inst['suggested_structure'] ?? '';
        $featuresPriority = $inst['features_priority'] ?? [];
        $avoid = $inst['avoid'] ?? [];
        
        // Replace placeholders in suggested_structure
        $suggestedStructure = str_replace('{brand}', $context['brand'] ?? '', $suggestedStructure);
        $suggestedStructure = str_replace('{weight}', $context['weight'] ?? '', $suggestedStructure);
        
        $prompt = <<<PROMPT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ISTRUZIONI DA PRODUCT RESEARCH (STEP 1 - GEMINI 3 PRO)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

**ESSENZA PRODOTTO:**
{$productEssence}

**DEVE INCLUDERE (obbligatorio):**

PROMPT;
        
        foreach ($mustInclude as $term) {
            $prompt .= "\n- {$term}";
        }
        
        $prompt .= "\n\n**STRUTTURA SUGGERITA:**\n{$suggestedStructure}";
        
        if (!empty($featuresPriority)) {
            $prompt .= "\n\n**FEATURES DA PRIORITIZZARE:**";
            foreach ($featuresPriority as $feature) {
                $prompt .= "\n- {$feature}";
            }
        }
        
        if (!empty($avoid)) {
            $prompt .= "\n\n**EVITA ASSOLUTAMENTE:**";
            foreach ($avoid as $term) {
                $prompt .= "\n- {$term}";
            }
        }
        
        $prompt .= <<<PROMPT


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CONTEXT SPECIFICO:
- Brand: {$context['brand']}
- Peso: {$context['weight']}
- Categoria: {$context['category']}

POLICY AMAZON (HARD LIMITS):
- Lunghezza: 150-200 caratteri MAX
- NO emoji, NO claim salute non verificabili
- Inizia SEMPRE con: "{$context['brand']} - "

TASK:
Genera titolo seguendo ESATTAMENTE le istruzioni sopra.
Usa struttura suggerita, integra must_include, rispetta avoid.
Verifica lunghezza < 200 char.

OUTPUT: Solo titolo, NO spiegazioni.
PROMPT;
        
        return $prompt;
    }
    
    /**
     * HARD enforcement template: Brand - Prodotto Formato | Feature | Benefit
     * Parse AI output e reassemble con template fisso
     * 
     * @param string $aiTitle Title generato da AI
     * @param array $context Context
     * @return string Title con template enforced
     */
    private function enforceTemplate(string $aiTitle, array $context): string
    {
        $brand = $context['brand'] ?? 'NOBRAND';
        $weight = $context['weight'] ?? '100g';
        
        CentralLogger::debug('step2_title_generation', 'Enforcing template', [
            'ai_title' => $aiTitle,
            'brand' => $brand,
            'weight' => $weight
        ]);
        
        // Parse AI output per estrarre componenti creativi
        $parsed = $this->parseComponents($aiTitle, $brand, $weight);
        
        // Reassemble con template FISSO (non negoziabile)
        $title = sprintf(
            "%s - %s %s | %s | %s",
            $brand,
            $parsed['product'],
            $weight,
            $parsed['feature'],
            $parsed['benefit']
        );
        
        // EXPANSION AGGRESSIVA: Loop fino a 150+ caratteri
        $expansions = [
            'Premium',
            'Qualità Superiore',
            'Italiano',
            'Naturale',
            'Per Tutta la Famiglia',
            'Ideale per Ricette',
            'Gusto Autentico',
            'Senza Conservanti'
        ];
        
        $expansionIndex = 0;
        while (strlen($title) < 150 && $expansionIndex < count($expansions)) {
            // Aggiungi espansione extra
            $title = sprintf(
                "%s - %s %s | %s | %s %s",
                $brand,
                $parsed['product'],
                $weight,
                $parsed['feature'],
                $parsed['benefit'],
                $expansions[$expansionIndex]
            );
            $expansionIndex++;
        }
        
        // Truncate se troppo lungo (max 200 chars)
        if (strlen($title) > 200) {
            $title = $this->truncateIntelligently($title, 200);
        }
        
        CentralLogger::debug('step2_title_generation', 'Template enforced', [
            'enforced_title' => $title,
            'length' => strlen($title)
        ]);
        
        return trim($title);
    }
    
    /**
     * Parse AI title per estrarre componenti mantenendo creatività
     * 
     * @param string $aiTitle Title generato da AI
     * @param string $brand Brand da rimuovere se presente
     * @param string $weight Weight da rimuovere se presente
     * @return array ['product' => string, 'feature' => string, 'benefit' => string, 'extra' => string]
     */
    private function parseComponents(string $aiTitle, string $brand, string $weight): array
    {
        // Rimuovi brand se presente (verrà re-inserito)
        $text = str_ireplace($brand, '', $aiTitle);
        
        // Rimuovi weight se presente
        $text = preg_replace('/\b\d+\s*(g|ml|kg|l|pz|cm|mm|m)\b/i', '', $text);
        
        // Rimuovi separatori iniziali
        $text = preg_replace('/^[\s\-|]+/', '', $text);
        $text = trim($text);
        
        // Split per separatori (-, |, :)
        $parts = preg_split('/[\-|:]+/', $text);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn($p) => strlen($p) > 0);
        $parts = array_values($parts);
        
        // Extract componenti
        $product = $parts[0] ?? 'Prodotto';
        $feature = $parts[1] ?? 'Premium';
        $benefit = $parts[2] ?? '';
        $extra = isset($parts[3]) ? implode(' ', array_slice($parts, 3)) : '';
        
        // Cleanup componenti
        $product = $this->cleanComponent($product);
        $feature = $this->cleanComponent($feature);
        $benefit = $this->cleanComponent($benefit);
        $extra = $this->cleanComponent($extra);
        
        // Se benefit è vuoto, usa feature come benefit e "Premium" come feature
        if (empty($benefit) && !empty($feature)) {
            $benefit = $feature;
            $feature = 'Premium';
        }
        
        return compact('product', 'feature', 'benefit', 'extra');
    }
    
    /**
     * Clean component rimuovendo separatori residui
     * 
     * @param string $text Text to clean
     * @return string Cleaned text
     */
    private function cleanComponent(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^[\s\-|:]+/', '', $text);
        $text = preg_replace('/[\s\-|:]+$/', '', $text);
        return trim($text);
    }
    
    /**
     * Truncate title intelligentemente (no mid-word cuts)
     * 
     * @param string $title Title to truncate
     * @param int $maxLength Max length
     * @return string Truncated title
     */
    private function truncateIntelligently(string $title, int $maxLength): string
    {
        if (strlen($title) <= $maxLength) {
            return $title;
        }
        
        $truncated = substr($title, 0, $maxLength);
        
        // Preferisci tagliare DOPO un pipe
        $lastPipe = strrpos($truncated, '|');
        if ($lastPipe !== false && $lastPipe > $maxLength * 0.7) {
            return trim(substr($truncated, 0, $lastPipe));
        }
        
        // Altrimenti taglia a parola intera
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            return trim(substr($truncated, 0, $lastSpace));
        }
        
        return $truncated;
    }
    
    /**
     * Fallback: title generico con template
     * 
     * @param array $context Context
     * @return array Fallback result
     */
    public function fallback(array $context): array
    {
        CentralLogger::warning('step2_title_generation', 'Using fallback: generic title template', []);
        
        $brand = $context['brand'] ?? 'NOBRAND';
        $weight = $context['weight'] ?? '100g';
        $productType = $context['product_type'] ?? 'Prodotto';
        
        $title = sprintf(
            "%s - %s %s | Premium Qualità | Per Uso Quotidiano",
            $brand,
            $productType,
            $weight
        );
        
        return [
            'content' => $title,
            'success' => false,
            'validation' => [
                'valid' => false,
                'warnings' => ['Fallback: generic title template used']
            ]
        ];
    }
    
    /**
     * Validate output
     * 
     * @param mixed $output Output da validare
     * @return bool True se valido
     */
    protected function validate($output): bool
    {
        // Check structure
        if (!isset($output['content']) || !is_string($output['content'])) {
            return false;
        }
        
        $title = $output['content'];
        $brand = $this->context['brand'] ?? 'NOBRAND';
        
        // Check minimum length
        if (strlen($title) < 100) {
            CentralLogger::warning('step2_title_generation', 'Validation failed: title too short', [
                'length' => strlen($title)
            ]);
            return false;
        }
        
        // Check brand at start
        if (strpos($title, $brand) !== 0) {
            CentralLogger::warning('step2_title_generation', 'Validation failed: brand not at start', [
                'title' => substr($title, 0, 50)
            ]);
            return false;
        }
        
        // Check separators present
        if (strpos($title, ' - ') === false || strpos($title, ' | ') === false) {
            CentralLogger::warning('step2_title_generation', 'Validation failed: missing separators', [
                'title' => $title
            ]);
            return false;
        }
        
        return true;
    }
}

