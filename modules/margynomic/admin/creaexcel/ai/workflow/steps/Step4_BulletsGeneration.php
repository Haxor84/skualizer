<?php
/**
 * Step4_BulletsGeneration
 * 
 * STEP 4: Bullets Generation (5 bullets sequenziali)
 * Model: claude-3-5-haiku-20241022 (fast batch generation)
 * 
 * Input: keywords, generated_title, generated_description
 * Output: 5 bullets diversificati per focus
 * 
 * IMPORTANTE: Questo step genera TUTTI e 5 i bullet in una sola chiamata execute(),
 * ma WorkflowEngine può chiamarlo per singoli bullet_point1-5.
 * Usa caching interno per evitare rigenerazione.
 */

require_once __DIR__ . '/../WorkflowStep.php';

class Step4_BulletsGeneration extends WorkflowStep
{
    private array $structuredInstructions = [];
    
    private array $focuses = [
        'Qualità e Origine',
        'Processo Produzione',
        'Versatilità Uso',
        'Benefici Nutrizionali',
        'Garanzia e Certificazioni'
    ];
    
    private ?string $targetBullet = null;
    private static ?array $cachedBullets = null;
    private static ?string $cacheKey = null;
    
    /**
     * Set structured instructions from Step1
     */
    public function setStructuredInstructions(array $instructions): void
    {
        $this->structuredInstructions = $instructions;
        
        CentralLogger::debug('step4_bullets', 'Structured instructions received', [
            'bullets_count' => count($instructions)
        ]);
    }
    
    /**
     * Set target bullet specifico (bullet_point1, bullet_point2, etc)
     * 
     * @param string $bulletName Nome bullet (es: bullet_point1)
     */
    public function setTargetBullet(string $bulletName): void
    {
        $this->targetBullet = $bulletName;
    }
    
    /**
     * Esegue bullets generation
     * Se targetBullet è settato, genera tutti e ritorna solo quello richiesto
     * 
     * @param array $context Context workflow
     * @return array ['content' => string, 'success' => bool, 'validation' => array]
     */
    public function execute(array $context): array
    {
        // Cache key basato su SKU per evitare rigenerazione
        $currentCacheKey = $context['sku'] ?? uniqid();
        
        // Se già cached per questo prodotto, usa cache
        if (self::$cacheKey === $currentCacheKey && self::$cachedBullets !== null) {
            CentralLogger::debug('step4_bullets', 'Using cached bullets', [
                'cache_key' => $currentCacheKey
            ]);
            
            if ($this->targetBullet && isset(self::$cachedBullets[$this->targetBullet])) {
                return self::$cachedBullets[$this->targetBullet];
            }
            
            // Se non c'è target specifico, ritorna tutti
            return ['all_bullets' => self::$cachedBullets];
        }
        
        // Genera TUTTI e 5 i bullet
        CentralLogger::info('step4_bullets', 'Generating all 5 bullets', [
            'target_bullet' => $this->targetBullet ?? 'all'
        ]);
        
        $bullets = $this->generateAllBullets($context);
        
        // Salva in cache
        self::$cachedBullets = $bullets;
        self::$cacheKey = $currentCacheKey;
        
        // Se richiesto bullet specifico, ritorna solo quello
        if ($this->targetBullet && isset($bullets[$this->targetBullet])) {
            return $bullets[$this->targetBullet];
        }
        
        // Altrimenti ritorna tutti
        return ['all_bullets' => $bullets];
    }
    
    /**
     * Genera tutti e 5 i bullet in sequenza
     * 
     * @param array $context Context workflow
     * @return array Associative array ['bullet_point1' => [...], 'bullet_point2' => [...], ...]
     */
    private function generateAllBullets(array $context): array
    {
        $bullets = [];
        $usedKeywords = $this->extractUsedKeywords($context);
        
        foreach ($this->focuses as $i => $focus) {
            $bulletName = "bullet_point" . ($i + 1);
            
            try {
                $bullet = $this->generateSingleBullet(
                    $i + 1,
                    $focus,
                    $context,
                    $usedKeywords
                );
                
                $bullets[$bulletName] = $bullet;
                
                // Aggiungi keywords usate per evitare overlap nei bullet successivi
                if (isset($bullet['content'])) {
                    $bulletKeywords = $this->extractKeywordsFromText($bullet['content']);
                    $usedKeywords = array_merge($usedKeywords, $bulletKeywords);
                    $usedKeywords = array_unique($usedKeywords);
                }
                
            } catch (Exception $e) {
                CentralLogger::error('step4_bullets', 'Single bullet generation failed', [
                    'bullet' => $bulletName,
                    'error' => $e->getMessage()
                ]);
                
                // Usa fallback per questo bullet
                $bullets[$bulletName] = $this->fallbackSingleBullet($i + 1, $focus);
            }
        }
        
        return $bullets;
    }
    
    /**
     * Genera singolo bullet
     * 
     * @param int $index Bullet number (1-5)
     * @param string $focus Focus tematico
     * @param array $context Context
     * @param array $usedKeywords Keywords già usate
     * @return array Bullet result
     */
    private function generateSingleBullet(int $index, string $focus, array $context, array $usedKeywords): array
    {
        $prompt = $this->buildBulletPrompt($index, $focus, $context, $usedKeywords);
        
        CentralLogger::debug('step4_bullets', 'Generating bullet', [
            'index' => $index,
            'focus' => $focus
        ]);
        
        $response = $this->llmClient->generate($prompt, $this->config['max_tokens']);
        
        CentralLogger::debug('step4_bullets_RAW', 'Gemini response', [
            'bullet_index' => $index,
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 200)
        ]);
        
        // Cleanup
        $bullet = trim($response);
        
        // Enforce format: ✓ KEYWORD: description
        $bullet = $this->enforceBulletFormat($bullet);
        
        // Validate
        $validator = new ContentValidator($this->policyManager);
        $validation = $validator->validate('bullet_point1', $bullet); // Tutti i bullet hanno stessa policy
        
        CentralLogger::debug('step4_bullets_VALIDATED', 'Validation result', [
            'bullet_index' => $index,
            'length' => strlen($bullet),
            'is_valid' => $validation['valid'],
            'errors' => $validation['errors'] ?? [],
            'content_preview' => substr($bullet, 0, 100)
        ]);
        
        return [
            'content' => $bullet,
            'success' => $validation['valid'],
            'validation' => $validation
        ];
    }
    
    /**
     * Build prompt per singolo bullet
     * 
     * @param int $index Bullet number
     * @param string $focus Focus
     * @param array $context Context
     * @param array $usedKeywords Keywords usate
     * @return string Prompt
     */
    private function buildBulletPrompt(int $index, string $focus, array $context, array $usedKeywords): string
    {
        // Check if we have structured instructions from WorkflowEngine (priority)
        if (!empty($this->structuredInstructions)) {
            return $this->buildStructuredBulletPrompt($index, $focus, $context, $usedKeywords);
        }
        
        $template = $this->loadPromptTemplate('bullet_generation.txt');
        
        // Extract structured prompts from Step1 for this bullet (fallback dal context)
        $allBullets = $context['structured_prompts']['bullets'] ?? [];
        $bulletStructured = null;
        
        // Find bullet by index
        foreach ($allBullets as $bullet) {
            if (($bullet['index'] ?? 0) === $index) {
                $bulletStructured = $bullet;
                break;
            }
        }
        
        // Fallback se structured non disponibile
        if (empty($bulletStructured)) {
            CentralLogger::warning('step4_bullets', 'No structured prompt for bullet ' . $index, []);
            $bulletStructured = [
                'theme' => $focus,
                'focus' => 'Caratteristiche prodotto',
                'suggested_structure' => '✓ KEYWORD: descrizione',
                'keywords_integrate' => []
            ];
        }
        
        // Available keywords (non usate)
        $availableKeywords = array_diff($context['keywords'] ?? [], $usedKeywords);
        $topKeywords = array_slice($availableKeywords, 0, 15);
        
        return strtr($template, [
            '{BULLET_NUMBER}' => $index,
            '{THEME}' => $bulletStructured['theme'] ?? $focus,
            '{FOCUS}' => $bulletStructured['focus'] ?? '',
            '{SUGGESTED_STRUCTURE}' => $bulletStructured['suggested_structure'] ?? '',
            '{KEYWORDS_INTEGRATE}' => implode(', ', $bulletStructured['keywords_integrate'] ?? []),
            '{KEYWORDS}' => implode(', ', $topKeywords),
            '{USED_KEYWORDS}' => implode(', ', array_slice($usedKeywords, 0, 20))
        ]);
    }
    
    /**
     * Build bullet prompt usando structured instructions da Step1
     * 
     * @param int $index Numero bullet (1-5)
     * @param string $focus Focus fallback
     * @param array $context Context workflow
     * @param array $usedKeywords Keywords usate
     * @return string Prompt completo
     */
    private function buildStructuredBulletPrompt(int $index, string $focus, array $context, array $usedKeywords): string
    {
        $allBullets = $this->structuredInstructions;
        $bulletStructured = null;
        
        // Find bullet by index
        foreach ($allBullets as $bullet) {
            if (($bullet['index'] ?? 0) === $index) {
                $bulletStructured = $bullet;
                break;
            }
        }
        
        // Fallback se structured non disponibile per questo index
        if (empty($bulletStructured)) {
            $bulletStructured = [
                'theme' => $focus,
                'focus' => 'Caratteristiche prodotto',
                'suggested_structure' => '✓ KEYWORD: descrizione',
                'keywords_integrate' => []
            ];
        }
        
        // Extract values
        $theme = $bulletStructured['theme'] ?? $focus;
        $bulletFocus = $bulletStructured['focus'] ?? '';
        $suggestedStructure = $bulletStructured['suggested_structure'] ?? '';
        $keywordsIntegrate = $bulletStructured['keywords_integrate'] ?? [];
        
        // Available keywords (non usate)
        $availableKeywords = array_diff($context['keywords'] ?? [], $usedKeywords);
        $topKeywords = array_slice($availableKeywords, 0, 15);
        
        // NUOVO: Usa informazioni base - supporta righe VUOTE
        $brand = $context['brand'] ?? 'NOBRAND';
        $productType = $context['product_type'] ?? ($context['item_sku'] ?? 'prodotto');
        $weight = $context['weight'] ?? '';
        
        $prompt = <<<PROMPT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ISTRUZIONI DA PRODUCT RESEARCH (STEP 1 - GEMINI 3 PRO)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

**BULLET #{$index}**

**TEMA:** {$theme}

**FOCUS SPECIFICO:**
{$bulletFocus}

**STRUTTURA SUGGERITA:**
{$suggestedStructure}

**KEYWORDS DA INTEGRARE:**

PROMPT;
        
        foreach ($keywordsIntegrate as $kw) {
            $prompt .= "\n- {$kw}";
        }
        
        $prompt .= <<<PROMPT


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CONTEXT SPECIFICO:
- Brand: {$brand}
- Tipo prodotto: {$productType}
- Formato/Peso: {$weight}
- Keywords disponibili: {topKeywordsStr}
- Keywords GIÀ USATE (da evitare): {usedKeywordsStr}

POLICY AMAZON (HARD LIMITS - NON NEGOZIABILI):

🚫 LUNGHEZZA MAX ASSOLUTA: 250 caratteri
✅ TARGET OTTIMALE: 200-230 caratteri
🚫 Se superi 250 char → RIFIUTO AMAZON
⚠️ MINIMO richiesto: 180 caratteri

ESEMPIO LUNGHEZZA CORRETTA (215 char):
✓ VERSATILITÀ: Perfetto per dolci e salato, arricchisce torte, panature gourmet e pesto. Ideale per dieta keto o gluten free, dona sapore intenso senza glutine né conservanti aggiunti.

⚠️ Formato: ✓ KEYWORD_MAIUSCOLA: descrizione
⚠️ NO emoji strani, NO claim salute non verificabili

TASK:
Scrivi bullet #{$index} seguendo ESATTAMENTE le istruzioni sopra.
Usa tema specificato, integra focus, rispetta struttura suggerita.
Integra keywords_integrate in modo naturale.

⚠️⚠️⚠️ VERIFICA FINALE OBBLIGATORIA:
Conta i caratteri del tuo bullet.
Se > 250 char → ACCORCIA immediatamente.
Se < 180 char → ESPANDI con dettagli concreti.

OUTPUT: Solo bullet completo 180-230 char, NO spiegazioni.
PROMPT;
        
        // Replace placeholders
        $topKeywordsStr = implode(', ', $topKeywords);
        $usedKeywordsStr = implode(', ', array_slice($usedKeywords, 0, 20));
        
        $prompt = str_replace('{topKeywordsStr}', $topKeywordsStr, $prompt);
        $prompt = str_replace('{usedKeywordsStr}', $usedKeywordsStr, $prompt);
        
        // Append retry hint se disponibile
        return $this->appendRetryHint($prompt);
    }
    
    /**
     * Enforce bullet format: ✓ KEYWORD: description
     * 
     * @param string $aiBullet Bullet generato da AI
     * @return string Bullet formatted
     */
    private function enforceBulletFormat(string $aiBullet): string
    {
        // Rimuovi newlines multipli
        $aiBullet = preg_replace('/\s+/', ' ', $aiBullet);
        $aiBullet = trim($aiBullet);
        
        // Aggiungi ✓ se mancante
        if (!preg_match('/^[✓✔]/', $aiBullet)) {
            $aiBullet = "✓ " . $aiBullet;
        }
        
        // Enforce MAIUSCOLO keyword se mancante
        if (!preg_match('/^[✓✔]\s+[A-Z\s]+:/', $aiBullet)) {
            // Extract prime 2-4 parole come keyword
            $words = explode(' ', $aiBullet);
            $keyword = strtoupper(implode(' ', array_slice($words, 1, 3)));
            $rest = implode(' ', array_slice($words, 4));
            $aiBullet = "✓ {$keyword}: {$rest}";
        }
        
        // ✅ ENFORCEMENT CRITICO: Tronca se supera 250 caratteri
        if (strlen($aiBullet) > 250) {
            CentralLogger::warning('step4_bullets', 'Bullet exceeds 250 chars, truncating', [
                'original_length' => strlen($aiBullet),
                'excess' => strlen($aiBullet) - 250
            ]);
            
            // Tronca a 247 per lasciare spazio a "..."
            $aiBullet = substr($aiBullet, 0, 247);
            
            // Trova ultimo spazio per non tagliare a metà parola
            $lastSpace = strrpos($aiBullet, ' ');
            if ($lastSpace !== false && $lastSpace > 200) {
                $aiBullet = substr($aiBullet, 0, $lastSpace);
            }
            
            // Aggiungi punto finale se manca
            if (!preg_match('/[.!]$/', $aiBullet)) {
                $aiBullet .= '.';
            }
        }
        
        return $aiBullet;
    }
    
    /**
     * Extract keywords già usate in title e description
     * 
     * @param array $context Context
     * @return array Used keywords
     */
    private function extractUsedKeywords(array $context): array
    {
        $usedKeywords = [];
        
        // Da title
        if (isset($context['generated_item_name'])) {
            $usedKeywords = array_merge(
                $usedKeywords,
                $this->extractKeywordsFromText($context['generated_item_name'])
            );
        }
        
        // Da description
        if (isset($context['generated_product_description'])) {
            $usedKeywords = array_merge(
                $usedKeywords,
                $this->extractKeywordsFromText($context['generated_product_description'])
            );
        }
        
        return array_unique($usedKeywords);
    }
    
    /**
     * Fallback per singolo bullet
     * 
     * @param int $index Bullet index
     * @param string $focus Focus
     * @return array Fallback bullet
     */
    private function fallbackSingleBullet(int $index, string $focus): array
    {
        $genericBullets = [
            "✓ QUALITÀ PREMIUM: Prodotto selezionato con cura per garantire standard elevati di qualità e freschezza in ogni confezione",
            "✓ PROCESSO ARTIGIANALE: Lavorato secondo tradizione con tecniche naturali che preservano le proprietà organolettiche originali",
            "✓ VERSATILE: Ideale per molteplici utilizzi in cucina, colazione, snack o come ingrediente per ricette creative e gustose",
            "✓ NATURALE GENUINO: Ingredienti scelti accuratamente senza additivi artificiali per un prodotto autentico e salutare",
            "✓ SODDISFAZIONE GARANTITA: Prodotto testato e certificato per assicurare la massima qualità e rispetto degli standard"
        ];
        
        return [
            'content' => $genericBullets[$index - 1] ?? $genericBullets[0],
            'success' => false,
            'validation' => [
                'valid' => false,
                'warnings' => ['Fallback: generic bullet template']
            ]
        ];
    }
    
    /**
     * Fallback generale
     * 
     * @param array $context Context
     * @return array All bullets fallback
     */
    public function fallback(array $context): array
    {
        CentralLogger::warning('step4_bullets', 'Using fallback: all generic bullets', []);
        
        $bullets = [];
        for ($i = 1; $i <= 5; $i++) {
            $bullets["bullet_point{$i}"] = $this->fallbackSingleBullet($i, $this->focuses[$i - 1]);
        }
        
        // Se richiesto bullet specifico
        if ($this->targetBullet && isset($bullets[$this->targetBullet])) {
            return $bullets[$this->targetBullet];
        }
        
        return ['all_bullets' => $bullets];
    }
    
    /**
     * Validate output
     * 
     * @param mixed $output Output da validare
     * @return bool True se valido
     */
    protected function validate($output): bool
    {
        // Se output è singolo bullet
        if (isset($output['content']) && is_string($output['content'])) {
            $length = strlen($output['content']);
            $hasCorrectFormat = preg_match('/^✓\s+[A-Z\s]+:/', $output['content']);
            
            // CRITICAL: Check MIN and MAX length + format
            return $length >= 180 && 
                   $length <= 250 &&  // FIX: Aggiunto controllo MAX
                   $hasCorrectFormat;
        }
        
        // Se output è all_bullets
        if (isset($output['all_bullets']) && is_array($output['all_bullets'])) {
            return count($output['all_bullets']) === 5;
        }
        
        return false;
    }
}

