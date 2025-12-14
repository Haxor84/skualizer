<?php
/**
 * Step3_DescriptionGen
 * 
 * STEP 3: Description Generation con HTML
 * Model: claude-3-5-haiku-20241022 (storytelling veloce)
 * 
 * Input: keywords, generated_title
 * Output: HTML description 800-1200 chars (text-only), max 2000 total
 */

require_once __DIR__ . '/../WorkflowStep.php';

class Step3_DescriptionGen extends WorkflowStep
{
    private array $structuredInstructions = [];
    
    /**
     * Set structured instructions from Step1
     */
    public function setStructuredInstructions(array $instructions): void
    {
        $this->structuredInstructions = $instructions;
        
        CentralLogger::debug('step3_description', 'Structured instructions received', [
            'has_opening_hook' => !empty($instructions['opening_hook']),
            'key_benefits_count' => count($instructions['key_benefits'] ?? []),
            'use_cases_count' => count($instructions['use_cases'] ?? [])
        ]);
    }
    
    /**
     * Esegue description generation
     * 
     * @param array $context Context workflow
     * @return array ['content' => string, 'success' => bool, 'validation' => array]
     */
    public function execute(array $context): array
    {
        return $this->executeWithRetry(function() use ($context) {
            
            $prompt = $this->buildPrompt($context);
            
            CentralLogger::debug('step3_description', 'Calling Haiku', [
                'prompt_length' => strlen($prompt),
                'max_tokens' => $this->config['max_tokens']
            ]);
            
            // Call Gemini 3 Pro
            $response = $this->llmClient->generate(
                $prompt,
                $this->config['max_tokens']
            );
            
            CentralLogger::debug('step3_description_RAW', 'Gemini 3 Pro response', [
                'response_length' => strlen($response),
                'response_preview' => substr(strip_tags($response), 0, 200)
            ]);
            
            // Cleanup HTML
            $html = $this->cleanupHtml($response);
            
            // Enforce lunghezze (text-only: 800-1200, total: max 2000)
            $html = $this->enforceLengths($html);
            
            CentralLogger::debug('step3_description', 'Description generated', [
                'html_length' => strlen($html),
                'text_length' => strlen(strip_tags($html))
            ]);
            
            // Validate
            $validator = new ContentValidator($this->policyManager);
            $validation = $validator->validate('product_description', $html);
            
            CentralLogger::debug('step3_description_VALIDATED', 'Validation result', [
                'html_length' => strlen($html),
                'text_length' => strlen(strip_tags($html)),
                'is_valid' => $validation['valid'],
                'errors' => $validation['errors'] ?? [],
                'warnings' => $validation['warnings'] ?? []
            ]);
            
            return [
                'content' => $html,
                'success' => $validation['valid'],
                'validation' => $validation
            ];
            
        }, $this->config['retry_attempts']);
    }
    
    /**
     * Build prompt da template
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
        
        $template = $this->loadPromptTemplate('description_generation.txt');
        
        // Extract structured prompts from Step1 (fallback dal context)
        $structured = $context['structured_prompts']['description'] ?? [];
        
        // Fallback se structured non disponibile
        if (empty($structured)) {
            CentralLogger::warning('step3_description', 'No structured prompts, using fallback', []);
            $structured = [
                'opening_hook' => 'Scopri questo prodotto premium',
                'key_benefits' => ['Qualità superiore', 'Versatile', 'Naturale'],
                'use_cases' => ['Uso quotidiano', 'Occasioni speciali'],
                'tone' => 'premium ma accessibile'
            ];
        }
        
        // Format structured fields
        $openingHook = $structured['opening_hook'] ?? '';
        $keyBenefits = implode("\n- ", $structured['key_benefits'] ?? []);
        $useCases = implode("\n- ", $structured['use_cases'] ?? []);
        $tone = $structured['tone'] ?? 'professionale';
        
        // NUOVO APPROCCIO: Non dipende da title generato - supporta righe VUOTE
        // Usa product_type + weight che sono sempre disponibili dall'analisi
        $brand = $context['brand'] ?? 'NOBRAND';
        $productType = $context['product_type'] ?? ($context['item_sku'] ?? 'prodotto');
        $weight = $context['weight'] ?? '';
        
        // Usa TUTTE le keywords (non filtrate da title che potrebbe non esistere)
        $topKeywords = array_slice($context['keywords'] ?? [], 0, 20);
        
        $prompt = strtr($template, [
            '{OPENING_HOOK}' => $openingHook,
            '{KEY_BENEFITS}' => $keyBenefits,
            '{USE_CASES}' => $useCases,
            '{TONE}' => $tone,
            '{PRODUCT_TYPE}' => $productType,
            '{WEIGHT}' => $weight,
            '{KEYWORDS}' => implode(', ', $topKeywords),
            '{BRAND}' => $brand
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
        $openingHook = $inst['opening_hook'] ?? '';
        $keyBenefits = $inst['key_benefits'] ?? [];
        $useCases = $inst['use_cases'] ?? [];
        $tone = $inst['tone'] ?? 'professionale';
        
        $prompt = <<<PROMPT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ISTRUZIONI DA PRODUCT RESEARCH (STEP 1 - GEMINI 3 PRO)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

**HOOK APERTURA:**
{$openingHook}

**BENEFICI CHIAVE DA COMUNICARE:**

PROMPT;
        
        foreach ($keyBenefits as $benefit) {
            $prompt .= "\n- {$benefit}";
        }
        
        $prompt .= "\n\n**CASI D'USO DA MOSTRARE:**\n";
        
        foreach ($useCases as $useCase) {
            $prompt .= "\n- {$useCase}";
        }
        
        $prompt .= "\n\n**TONE:** {$tone}";
        
        // NUOVO: Usa informazioni base invece di title - supporta righe VUOTE
        $brand = $context['brand'] ?? 'NOBRAND';
        $productType = $context['product_type'] ?? ($context['item_sku'] ?? 'prodotto');
        $weight = $context['weight'] ?? '';
        
        $prompt .= <<<PROMPT


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CONTEXT SPECIFICO:
- Brand: {$brand}
- Tipo prodotto: {$productType}
- Formato/Peso: {$weight}

POLICY AMAZON (HARD LIMITS):
- Lunghezza text-only: 800-1200 caratteri (NO HTML tags)
- Lunghezza totale: MAX 2000 caratteri (con HTML)
- HTML: usa SOLO <b>, <br>, <ul>, <li>
- NO emoji, NO claim salute non verificabili

TASK:
Scrivi description HTML seguendo ESATTAMENTE le istruzioni sopra.
Inizia con opening hook, sviluppa benefici chiave, mostra use cases.
Usa tone specificato.
Verifica lunghezze rispettate.

OUTPUT: Solo HTML description, NO spiegazioni.
PROMPT;
        
        return $prompt;
    }
    
    /**
     * Cleanup HTML response
     * 
     * @param string $response Response da LLM
     * @return string HTML cleaned
     */
    private function cleanupHtml(string $response): string
    {
        // Trim
        $html = trim($response);
        
        // Rimuovi markdown code blocks se presenti
        $html = preg_replace('/```html\s*/', '', $html);
        $html = preg_replace('/```\s*$/', '', $html);
        $html = trim($html);
        
        // Assicurati che abbia HTML minimo
        if (!$this->hasHtml($html)) {
            // Wrappa in <b> il primo paragrafo
            $paragraphs = explode("\n\n", $html);
            if (count($paragraphs) > 0) {
                $paragraphs[0] = "<b>" . $paragraphs[0] . "</b>";
                $html = implode("<br><br>\n", $paragraphs);
            }
        }
        
        return $html;
    }
    
    /**
     * Enforce lunghezze description
     * 
     * @param string $html HTML description
     * @return string HTML con lunghezze corrette
     */
    private function enforceLengths(string $html): string
    {
        $textOnly = strip_tags($html);
        $textLength = strlen($textOnly);
        $totalLength = strlen($html);
        
        $policy = $this->policyManager->getPolicyForField('product_description');
        $maxTextLength = $policy['max_length'] ?? 1200;
        $maxTotalLength = $policy['max_total_length'] ?? 2000;
        
        // NUOVO: Solo WARNING se supera, NO TRONCAMENTO
        // Gemini 3 Pro rispetta già i limiti nel prompt
        if ($textLength > $maxTextLength) {
            CentralLogger::warning('step3_description', 'Text-only length exceeds limit', [
                'text_length' => $textLength,
                'max_allowed' => $maxTextLength,
                'excess' => $textLength - $maxTextLength
            ]);
            // NO truncate - lascia contenuto completo
        }
        
        // CRITICO: Tronca SOLO se supera 2000 TOTALI (hard limit Amazon)
        if ($totalLength > $maxTotalLength) {
            CentralLogger::warning('step3_description', 'Total length exceeds hard limit, truncating', [
                'total_length' => $totalLength,
                'max_allowed' => $maxTotalLength
            ]);
            
            // Trova ultimo </li> o </ul> PRIMA del limite
            $cutPosition = $this->findSafeHTMLCutPosition($html, $maxTotalLength - 50);
            $html = substr($html, 0, $cutPosition);
            $html = $this->closeOpenTags($html);
        }
        
        return $html;
    }
    
    /**
     * Trova posizione sicura per tagliare HTML (ultimo tag chiuso completo)
     * 
     * @param string $html HTML da tagliare
     * @param int $maxPosition Posizione massima
     * @return int Posizione sicura per taglio
     */
    private function findSafeHTMLCutPosition(string $html, int $maxPosition): int
    {
        // Cerca ultimo </li> o </ul> o </p> PRIMA di maxPosition
        $safeTags = ['</ul>', '</li>', '</p>', '<br>'];
        $lastSafePos = 0;
        
        foreach ($safeTags as $tag) {
            $pos = strrpos(substr($html, 0, $maxPosition), $tag);
            if ($pos !== false && $pos > $lastSafePos) {
                $lastSafePos = $pos + strlen($tag);
            }
        }
        
        // Se non trova tag sicuri, taglia a parola
        if ($lastSafePos === 0) {
            $cutText = substr($html, 0, $maxPosition);
            $lastSpace = strrpos($cutText, ' ');
            return $lastSpace !== false ? $lastSpace : $maxPosition;
        }
        
        return $lastSafePos;
    }
    
    /**
     * Truncate HTML mantenendo text-only length sotto limite
     * 
     * @param string $html HTML to truncate
     * @param int $maxTextLength Max text length (without HTML)
     * @return string Truncated HTML
     */
    private function truncateHtmlToTextLength(string $html, int $maxTextLength): string
    {
        $textOnly = strip_tags($html);
        
        if (strlen($textOnly) <= $maxTextLength) {
            return $html;
        }
        
        // Trova ultimo spazio prima del limite
        $cutText = substr($textOnly, 0, $maxTextLength);
        $lastSpace = strrpos($cutText, ' ');
        
        if ($lastSpace !== false) {
            $cutText = substr($cutText, 0, $lastSpace);
        }
        
        // Trova posizione in HTML (approssimazione)
        $targetLength = strlen($cutText);
        $htmlCut = $this->findHtmlPositionForTextLength($html, $targetLength);
        
        return substr($html, 0, $htmlCut);
    }
    
    /**
     * Trova posizione in HTML corrispondente a text length
     * 
     * @param string $html HTML string
     * @param int $targetTextLength Target text length
     * @return int Position in HTML
     */
    private function findHtmlPositionForTextLength(string $html, int $targetTextLength): int
    {
        $textSoFar = 0;
        $inTag = false;
        
        for ($i = 0; $i < strlen($html); $i++) {
            $char = $html[$i];
            
            if ($char === '<') {
                $inTag = true;
            } elseif ($char === '>') {
                $inTag = false;
            } elseif (!$inTag) {
                $textSoFar++;
                if ($textSoFar >= $targetTextLength) {
                    return $i + 1;
                }
            }
        }
        
        return strlen($html);
    }
    
    /**
     * Chiudi tag HTML aperti
     * 
     * @param string $html HTML con possibili tag aperti
     * @return string HTML con tag chiusi
     */
    private function closeOpenTags(string $html): string
    {
        $openTags = [];
        
        preg_match_all('/<(\w+)[^>]*>|<\/(\w+)>/', $html, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            if (!empty($match[1])) {
                // Tag opened
                $openTags[] = $match[1];
            } elseif (!empty($match[2])) {
                // Tag closed
                $lastOpen = array_pop($openTags);
            }
        }
        
        // Close remaining open tags
        $openTags = array_reverse($openTags);
        foreach ($openTags as $tag) {
            $html .= "</{$tag}>";
        }
        
        return $html;
    }
    
    /**
     * Check se contiene HTML
     * 
     * @param string $text Text to check
     * @return bool True se contiene HTML
     */
    private function hasHtml(string $text): bool
    {
        return $text !== strip_tags($text);
    }
    
    /**
     * Fallback: description minimale
     * 
     * @param array $context Context
     * @return array Fallback result
     */
    public function fallback(array $context): array
    {
        CentralLogger::warning('step3_description', 'Using fallback: minimal description', []);
        
        $brand = $context['brand'] ?? 'NOBRAND';
        $productType = $context['product_type'] ?? 'prodotto';
        
        $html = sprintf(
            "<b>Scopri la qualità di %s</b><br>Un %s selezionato con cura per garantire il massimo della soddisfazione.<br>Ideale per uso quotidiano e occasioni speciali.",
            $brand,
            $productType
        );
        
        return [
            'content' => $html,
            'success' => false,
            'validation' => [
                'valid' => false,
                'warnings' => ['Fallback: minimal generic description']
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
        if (!isset($output['content']) || !is_string($output['content'])) {
            return false;
        }
        
        $html = $output['content'];
        $textOnly = strip_tags($html);
        $textLength = strlen($textOnly);
        
        // Check minimum text length
        if ($textLength < 700) {
            CentralLogger::warning('step3_description', 'Validation failed: description too short', [
                'text_length' => $textLength
            ]);
            return false;
        }
        
        // Check ha HTML
        if (!$this->hasHtml($html)) {
            CentralLogger::warning('step3_description', 'Validation failed: no HTML formatting', []);
            return false;
        }
        
        return true;
    }
}

