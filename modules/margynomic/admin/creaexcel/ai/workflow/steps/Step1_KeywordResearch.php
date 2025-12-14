<?php
/**
 * Step1_KeywordResearch
 * 
 * STEP 1: Competitor Analysis + Keyword Extraction
 * Model: Gemini 3 Pro Preview (extended thinking)
 * 
 * Input: current_title, current_description, competitor_data (optional)
 * Output: array[100] keywords ranked by relevance
 */

require_once __DIR__ . '/../WorkflowStep.php';
require_once __DIR__ . '/../parsers/KeywordParser.php';

class Step1_KeywordResearch extends WorkflowStep
{
    /**
     * Esegue keyword research
     * 
     * @param array $context Context workflow
     * @return array ['content' => string[], 'success' => bool, 'validation' => array]
     */
    public function execute(array $context): array
    {
        return $this->executeWithRetry(function() use ($context) {
            
            // Build prompt da template
            $prompt = $this->buildPrompt($context);
            
            CentralLogger::debug('step1_keyword_research', 'Calling Gemini 3 Pro', [
                'prompt_length' => strlen($prompt),
                'max_tokens' => $this->config['max_tokens']
            ]);
            
            // Call Gemini 3 Pro con extended thinking
            $response = $this->llmClient->generate(
                $prompt,
                $this->config['max_tokens']
            );
            
            CentralLogger::debug('step1_keyword_research', 'Gemini RAW response received', [
                'response_length' => strlen($response),
                'response_first_500' => substr($response, 0, 500),
                'response_last_200' => substr($response, -200),
                'has_json_marker' => strpos($response, '{') !== false,
                'has_analysis_markdown' => strpos($response, 'analysis_markdown') !== false,
                'has_structured_prompts' => strpos($response, 'structured_prompts') !== false
            ]);
            
            // Parse JSON response (NEW: includes analysis + keywords + structured_prompts)
            $parser = new KeywordParser();
            $parsed = $parser->parse($response);
            
            CentralLogger::debug('step1_keyword_research', 'Parser result', [
                'parsed_keys' => array_keys($parsed),
                'is_array' => is_array($parsed),
                'has_analysis' => isset($parsed['analysis']) || isset($parsed['analysis_markdown']),
                'has_keywords' => isset($parsed['keywords']),
                'has_structured' => isset($parsed['structured_prompts']),
                'keywords_count' => isset($parsed['keywords']) ? count($parsed['keywords']) : 0
            ]);
            
            // Extract analysis, keywords, and structured_prompts
            $analysis = $parsed['analysis'] ?? '';
            $keywords = $parsed['keywords'] ?? $parsed; // Backward compatibility
            $structuredPrompts = $parsed['structured_prompts'] ?? []; // NEW
            
            CentralLogger::debug('step1_keyword_research', 'Extracted components', [
                'analysis_length' => strlen($analysis),
                'analysis_preview' => substr($analysis, 0, 100),
                'raw_keywords_count' => count($keywords),
                'structured_prompts_keys' => is_array($structuredPrompts) ? array_keys($structuredPrompts) : 'NOT_ARRAY',
                'has_title_prompt' => isset($structuredPrompts['title']),
                'has_bullets_prompt' => isset($structuredPrompts['bullets'])
            ]);
            
            // Post-processing: scoring, dedup, sort
            $processedKeywords = $this->processKeywords($keywords, $context);
            
            CentralLogger::info('step1_keyword_research', 'Keywords + Analysis + Structured Prompts extracted', [
                'raw_count' => count($keywords),
                'processed_count' => count($processedKeywords),
                'has_analysis' => !empty($analysis),
                'analysis_length' => strlen($analysis),
                'has_structured_prompts' => !empty($structuredPrompts),
                'structured_keys' => array_keys($structuredPrompts)
            ]);
            
            return [
                'content' => $processedKeywords,
                'analysis' => $analysis, // For display/cache
                'structured_prompts' => $structuredPrompts, // NEW: For Step2-5
                'success' => true,
                'validation' => [
                    'valid' => true,
                    'keyword_count' => count($processedKeywords),
                    'has_analysis' => !empty($analysis),
                    'has_structured' => !empty($structuredPrompts)
                ]
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
        $template = $this->loadPromptTemplate('keyword_research.txt');
        
        // Extract product type intelligently
        $productType = $this->extractProductType(
            $context['current_title'] ?? '',
            $context['current_description'] ?? '',
            $context['sku'] ?? ''
        );
        
        // Format competitor data se disponibile (DEPRECATED, but kept for backward compat)
        $competitorData = $this->formatCompetitors($context);
        
        // Replace placeholders
        return strtr($template, [
            '{SKU}' => $context['sku'] ?? 'N/D',
            '{BRAND}' => $context['brand'] ?? 'N/D',
            '{WEIGHT}' => $context['weight'] ?? 'N/D',
            '{PRODUCT_TYPE}' => $productType,
            '{CURRENT_TITLE}' => $context['current_title'] ?? 'N/D',
            '{CURRENT_DESCRIPTION}' => $this->truncateText(
                strip_tags($context['current_description'] ?? ''),
                500
            ),
            '{CATEGORY}' => $context['category'] ?? 'grocery',
            '{COMPETITOR_DATA}' => $competitorData // Legacy, will be empty for new workflow
        ]);
    }
    
    /**
     * Estrai product type intelligente da title/description
     * 
     * @param string $title Current title
     * @param string $description Current description
     * @param string $sku Fallback SKU
     * @return string Product type (2-4 words)
     */
    private function extractProductType(string $title, string $description, string $sku): string
    {
        // Rimuovi brand, peso, attributi comuni
        $text = strtolower($title . ' ' . $description);
        
        // Remove common noise
        $noise = ['premium', 'qualità', 'naturale', 'biologico', 'italiano', 
                  'artigianale', 'selezionato', 'gr', 'kg', 'ml', 'pz', 'pezzi',
                  'confezione', 'formato', 'sacchetto', 'barattolo'];
        foreach ($noise as $word) {
            $text = str_replace($word, '', $text);
        }
        
        // Extract core 2-4 word phrase
        $words = preg_split('/\s+/', trim($text));
        $words = array_filter($words, function($w) {
            return strlen($w) > 3; // skip short words
        });
        
        // Prendi prime 3-4 parole significative
        $productType = implode(' ', array_slice(array_values($words), 0, 4));
        
        // Fallback a SKU se extraction fallisce
        if (empty($productType)) {
            $productType = $sku;
        }
        
        return trim($productType);
    }
    
    /**
     * Format competitor data per prompt
     * 
     * @param array $context Context
     * @return string Formatted competitor data
     */
    private function formatCompetitors(array $context): string
    {
        $competitors = $context['competitor_data'] ?? [];
        
        if (empty($competitors)) {
            return "Nessun competitor data disponibile (usa solo dati prodotto corrente)";
        }
        
        $formatted = [];
        foreach ($competitors as $i => $comp) {
            $formatted[] = sprintf(
                "Competitor %d:\nTitle: %s\nDescription: %s\n",
                $i + 1,
                $comp['title'] ?? 'N/D',
                $this->truncateText(strip_tags($comp['description'] ?? ''), 200)
            );
        }
        
        return implode("\n", array_slice($formatted, 0, 5)); // Max 5 competitor
    }
    
    /**
     * Process keywords: ESPLOSIONE FRASI + scoring, sorting, dedup
     * 
     * @param array $keywords Raw keywords da LLM (possono essere frasi)
     * @param array $context Context per scoring
     * @return array Top 100 keywords sorted by score (PAROLE SINGOLE uniche)
     */
    private function processKeywords(array $keywords, array $context): array
    {
        // FASE 1: ESPLOSIONE FRASI → Parole singole
        $allWords = [];
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword), 'UTF-8');
            
            // Split per spazi, trattini, slash
            $words = preg_split('/[\s\-\/]+/u', $keyword);
            
            foreach ($words as $word) {
                $word = trim($word);
                
                // Skip parole troppo corte o stopwords
                if (mb_strlen($word, 'UTF-8') < 3) continue;
                if ($this->isStopword($word)) continue;
                
                $allWords[] = $word;
            }
        }
        
        // FASE 2: Deduplicazione AGGRESSIVA
        $allWords = array_unique($allWords);
        
        // FASE 3: Scoring basato su frequency
        $scored = $this->scoreKeywords($allWords, $context);
        
        // Sort by score DESC
        usort($scored, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Extract top 100 keywords
        $top100 = array_slice($scored, 0, 100);
        
        return array_column($top100, 'keyword');
    }
    
    /**
     * Score keywords basato su frequency e position weight
     * 
     * @param array $keywords Keywords da scorare
     * @param array $context Context per frequency analysis
     * @return array [['keyword' => string, 'score' => float], ...]
     */
    private function scoreKeywords(array $keywords, array $context): array
    {
        $scored = [];
        
        // Testo completo per frequency analysis
        $fullText = mb_strtolower(implode(' ', [
            $context['current_title'] ?? '',
            strip_tags($context['current_description'] ?? ''),
            implode(' ', $context['bullets'] ?? []),
            $this->extractCompetitorText($context)
        ]), 'UTF-8');
        
        // Titolo separato per position weight
        $titleText = mb_strtolower($context['current_title'] ?? '', 'UTF-8');
        
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if (strlen($keyword) < 3) continue;
            
            // Frequency score
            $frequency = substr_count($fullText, $keyword);
            
            // Position weight (× 2 se in title)
            $inTitle = substr_count($titleText, $keyword) > 0;
            $positionWeight = $inTitle ? 2.0 : 1.0;
            
            // Length bonus (parole più lunghe = più specifiche = score maggiore)
            $lengthBonus = min(strlen($keyword) / 20, 1.5);
            
            // Final score
            $score = $frequency * $positionWeight * $lengthBonus;
            
            $scored[] = [
                'keyword' => $keyword,
                'score' => $score
            ];
        }
        
        return $scored;
    }
    
    /**
     * Extract text da competitor data
     * 
     * @param array $context Context
     * @return string Competitor text concatenated
     */
    private function extractCompetitorText(array $context): string
    {
        $competitors = $context['competitor_data'] ?? [];
        $text = [];
        
        foreach ($competitors as $comp) {
            $text[] = $comp['title'] ?? '';
            $text[] = strip_tags($comp['description'] ?? '');
        }
        
        return implode(' ', $text);
    }
    
    /**
     * Truncate text intelligentemente
     * 
     * @param string $text Text to truncate
     * @param int $maxLength Max length
     * @return string Truncated text
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        
        $truncated = substr($text, 0, $maxLength);
        $lastSpace = strrpos($truncated, ' ');
        
        if ($lastSpace !== false) {
            $truncated = substr($truncated, 0, $lastSpace);
        }
        
        return $truncated . '...';
    }
    
    /**
     * Fallback: estrai keywords da contenuto esistente
     * 
     * @param array $context Context
     * @return array Fallback result
     */
    public function fallback(array $context): array
    {
        CentralLogger::warning('step1_keyword_research', 'Using fallback: extracting from existing content', []);
        
        // Estrai testo completo
        $text = implode(' ', [
            $context['current_title'] ?? '',
            strip_tags($context['current_description'] ?? ''),
            implode(' ', $context['bullets'] ?? [])
        ]);
        
        // Extract keywords usando metodo base class
        $keywords = $this->extractKeywordsFromText($text);
        
        // Limit to 100
        $keywords = array_slice($keywords, 0, 100);
        
        // Se ancora troppo poche, aggiungi keywords generiche
        if (count($keywords) < 50) {
            $genericKeywords = [
                'qualità', 'premium', 'naturale', 'italiano', 'artigianale',
                'genuino', 'tradizionale', 'autentico', 'fresco', 'selezionato',
                'biologico', 'eccellenza', 'sapore', 'gusto', 'ricetta',
                'prodotto', 'migliore', 'originale', 'puro', 'certificato'
            ];
            $keywords = array_merge($keywords, $genericKeywords);
            $keywords = array_unique($keywords);
            $keywords = array_slice($keywords, 0, 100);
        }
        
        return [
            'content' => $keywords,
            'success' => false,
            'validation' => [
                'valid' => false,
                'warnings' => ['Fallback: keywords from existing content'],
                'keyword_count' => count($keywords)
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
        if (!isset($output['content']) || !is_array($output['content'])) {
            return false;
        }
        
        // Check minimum keywords
        if (count($output['content']) < 50) {
            CentralLogger::warning('step1_keyword_research', 'Validation failed: too few keywords', [
                'count' => count($output['content'])
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check se parola è stopword italiana
     * 
     * @param string $word Parola da verificare
     * @return bool True se è stopword
     */
    private function isStopword(string $word): bool
    {
        $stopwords = [
            'per', 'con', 'senza', 'dal', 'alla', 'della', 'dello', 
            'degli', 'delle', 'una', 'uno', 'questo', 'quella',
            'più', 'molto', 'tutto', 'ogni', 'alcuni', 'altro',
            'anche', 'ancora', 'dove', 'quando', 'come', 'cosa',
            'nel', 'nei', 'sul', 'sui', 'dal', 'dai', 'tra', 'fra'
        ];
        return in_array($word, $stopwords);
    }
}

