<?php
/**
 * Step5_HiddenKeywords
 * 
 * STEP 5: Hidden Keywords Generation
 * Model: NESSUNO - Pure code logic
 * 
 * Input: keywords, generated_title, generated_description, generated_bullets
 * Output: string keywords non usate (150-180 chars)
 * 
 * Logica: estrae keywords dal pool Step1 che NON sono state usate
 * in title, description, bullets.
 */

require_once __DIR__ . '/../WorkflowStep.php';

class Step5_HiddenKeywords extends WorkflowStep
{
    /**
     * Esegue hidden keywords generation (NO LLM)
     * 
     * @param array $context Context workflow
     * @return array ['content' => string, 'success' => bool, 'validation' => array]
     */
    public function execute(array $context): array
    {
        CentralLogger::info('step5_hidden_keywords', 'Generating hidden keywords (pure code)', []);
        
        // Raccogli tutte le keywords usate nei contenuti generati
        $usedKeywords = $this->collectUsedKeywords($context);
        
        // Filtra keywords disponibili
        $allKeywords = $context['keywords'] ?? [];
        $availableKeywords = array_diff($allKeywords, $usedKeywords);
        
        // Se troppo poche, aggiungi sinonimi/keywords generiche
        if (count($availableKeywords) < 20) {
            $genericKeywords = $this->getGenericKeywords($context);
            $availableKeywords = array_merge($availableKeywords, $genericKeywords);
            $availableKeywords = array_unique($availableKeywords);
        }
        
        // Top 30 keywords non usate
        $hiddenKeywords = array_slice($availableKeywords, 0, 30);
        
        // Format: spazi, no virgole
        $formatted = implode(' ', $hiddenKeywords);
        
        // Enforce lunghezza 150-180 caratteri
        $formatted = $this->enforceLength($formatted);
        
        CentralLogger::info('step5_hidden_keywords', 'Hidden keywords generated', [
            'length' => strlen($formatted),
            'keywords_count' => count($hiddenKeywords)
        ]);
        
        // Validate
        $validator = new ContentValidator($this->policyManager);
        $validation = $validator->validate('generic_keywords', $formatted);
        
        return [
            'content' => $formatted,
            'success' => $validation['valid'],
            'validation' => $validation
        ];
    }
    
    /**
     * Collect keywords usate in tutti i contenuti generati
     * 
     * @param array $context Context
     * @return array Used keywords
     */
    private function collectUsedKeywords(array $context): array
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
        
        // Da bullets
        for ($i = 1; $i <= 5; $i++) {
            $bulletKey = "generated_bullet_point{$i}";
            if (isset($context[$bulletKey])) {
                $usedKeywords = array_merge(
                    $usedKeywords,
                    $this->extractKeywordsFromText($context[$bulletKey])
                );
            }
        }
        
        // Dedup e lowercase
        $usedKeywords = array_map('strtolower', $usedKeywords);
        $usedKeywords = array_unique($usedKeywords);
        
        return $usedKeywords;
    }
    
    /**
     * Get generic keywords per categoria
     * 
     * @param array $context Context
     * @return array Generic keywords
     */
    private function getGenericKeywords(array $context): array
    {
        $category = $context['category'] ?? 'grocery';
        
        $genericByCategory = [
            'grocery' => [
                'qualità', 'premium', 'naturale', 'italiano', 'artigianale',
                'genuino', 'tradizionale', 'autentico', 'fresco', 'selezionato',
                'biologico', 'eccellenza', 'sapore', 'gusto', 'ricetta',
                'cucina', 'alimentare', 'gourmet', 'delizioso', 'saporito'
            ],
            'default' => [
                'qualità', 'premium', 'migliore', 'originale', 'professionale',
                'certificato', 'garantito', 'resistente', 'durevole', 'pratico'
            ]
        ];
        
        return $genericByCategory[$category] ?? $genericByCategory['default'];
    }
    
    /**
     * Enforce lunghezza 150-180 caratteri
     * 
     * @param string $keywords Keywords string
     * @return string Keywords con lunghezza corretta
     */
    private function enforceLength(string $keywords): string
    {
        $length = strlen($keywords);
        
        // Troppo corto: expand con sinonimi
        if ($length < 150) {
            $words = explode(' ', $keywords);
            $expanded = $this->expandWithSynonyms($words);
            $keywords = implode(' ', $expanded);
            $length = strlen($keywords);
        }
        
        // Troppo lungo: trunca a parola intera
        if ($length > 180) {
            $keywords = substr($keywords, 0, 177);
            $lastSpace = strrpos($keywords, ' ');
            if ($lastSpace !== false) {
                $keywords = substr($keywords, 0, $lastSpace);
            }
        }
        
        return trim($keywords);
    }
    
    /**
     * Expand keywords con sinonimi
     * 
     * @param array $words Parole esistenti
     * @return array Parole espanse
     */
    private function expandWithSynonyms(array $words): array
    {
        $synonyms = [
            'naturale' => ['genuino', 'autentico', 'puro'],
            'premium' => ['qualità', 'eccellenza', 'superiore'],
            'italiano' => ['made in italy', 'artigianale', 'tradizionale'],
            'biologico' => ['bio', 'organic', 'naturale'],
            'fresco' => ['appena fatto', 'genuino', 'naturale']
        ];
        
        $expanded = $words;
        
        foreach ($words as $word) {
            if (isset($synonyms[$word])) {
                $expanded = array_merge($expanded, $synonyms[$word]);
            }
        }
        
        return array_unique($expanded);
    }
    
    /**
     * Fallback: keywords generiche
     * 
     * @param array $context Context
     * @return array Fallback result
     */
    public function fallback(array $context): array
    {
        CentralLogger::warning('step5_hidden_keywords', 'Using fallback: generic keywords', []);
        
        $keywords = 'qualità premium naturale artigianale tradizionale genuino italiano eccellenza sapore gusto ricetta migliore originale puro certificato';
        
        return [
            'content' => $keywords,
            'success' => false,
            'validation' => [
                'valid' => false,
                'warnings' => ['Fallback: generic keywords']
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
        
        $length = strlen($output['content']);
        
        // Check length range
        if ($length < 140 || $length > 190) {
            CentralLogger::warning('step5_hidden_keywords', 'Validation failed: length out of range', [
                'length' => $length
            ]);
            return false;
        }
        
        return true;
    }
}

