<?php
/**
 * ContentValidator
 * Valida contenuti generati contro policy Amazon
 */
class ContentValidator
{
    private $policyManager;

    public function __construct(PolicyManager $policyManager)
    {
        $this->policyManager = $policyManager;
    }

    /**
     * Valida contenuto per campo
     * 
     * @param string $fieldName Nome campo
     * @param string $content Contenuto da validare
     * @return array ['valid' => bool, 'errors' => [], 'warnings' => []]
     */
    public function validate($fieldName, $content)
    {
        $policy = $this->policyManager->getPolicyForField($fieldName);
        
        $errors = [];
        $warnings = [];
        
        // Validazione lunghezza
        $lengthCheck = $this->validateLength($content, $policy);
        $errors = array_merge($errors, $lengthCheck['errors']);
        $warnings = array_merge($warnings, $lengthCheck['warnings']);
        
        // Validazione completezza (NO troncamenti)
        $completenessCheck = $this->validateCompleteness($content);
        $warnings = array_merge($warnings, $completenessCheck);
        
        // Validazione parole vietate
        $forbiddenCheck = $this->validateForbiddenWords($content);
        $errors = array_merge($errors, $forbiddenCheck);
        
        // Validazione caratteri speciali
        $charCheck = $this->validateSpecialChars($content, $fieldName);
        $warnings = array_merge($warnings, $charCheck);
        
        // Validazione pattern titolo (SOLO per item_name)
        if ($fieldName === 'item_name') {
            $patternCheck = $this->validateTitlePattern($content);
            $errors = array_merge($errors, $patternCheck);
        }
        
        // Validazione HTML (se campo supporta HTML)
        if ($this->fieldSupportsHtml($fieldName)) {
            $htmlCheck = $this->validateHtml($content);
            $warnings = array_merge($warnings, $htmlCheck);
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'length' => $lengthCheck['length'] ?? strlen($content),
            'char_count' => mb_strlen($content, 'UTF-8')
        ];
    }
    
    /**
     * Verifica se testo è troncato o incompleto
     */
    private function validateCompleteness($content)
    {
        $warnings = [];
        $content = trim($content);
        
        // Pattern REALMENTE sospetti di troncamento
        $suspiciousPatterns = [
            '/\.\.\.$/',                        // Termina con ... (ellipsis)
            '/\s{3,}$/',                        // 3+ spazi trailing
            '/\b(in|per|con|su|di|da|e|del|della|delle|alla|alle)\s*$/i', // Preposizioni/articoli
            '/,\s*$/',                          // Termina con virgola
            '/:\s*$/',                          // Termina con due punti
            '/\b[a-z]{1,2}\s*$/i'              // Termina con parola MOLTO corta (1-2 lettere)
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $warnings[] = "⚠️ Testo potenzialmente troncato o incompleto";
                break;
            }
        }
        
        // NO check punteggiatura per bullets/keywords (solo description)
        // Questo check verrà fatto in base al campo specifico nella funzione validate()
        
        return $warnings;
    }

    /**
     * Conta caratteri escludendo tag HTML
     */
    private function countWithoutHtml($content)
    {
        return strlen(strip_tags($content));
    }
    
    /**
     * Conta caratteri testo plain
     */
    private function countPlainText($content)
    {
        return strlen($content);
    }
    
    /**
     * Validazione lunghezza con supporto count_method
     */
    private function validateLength($content, $policy)
    {
        $errors = [];
        $warnings = [];
        
        if (!$policy) {
            return ['errors' => $errors, 'warnings' => $warnings, 'length' => strlen($content)];
        }
        
        // Determina metodo di conteggio
        $countMethod = $policy['count_method'] ?? 'plain_text';
        
        if ($countMethod === 'without_html') {
            $length = $this->countWithoutHtml($content);
        } else {
            $length = $this->countPlainText($content);
        }
        
        // Tolleranza dinamica: 2-4% del max_length
        if (isset($policy['max_length'])) {
            $maxChars = $policy['max_length'];
            
            // Description: 4% tolleranza, altri campi: 2%
            $tolerancePercent = ($countMethod === 'without_html') ? 0.04 : 0.02;
            $tolerance = (int)($maxChars * $tolerancePercent);
            
            // Minimo 5 chars di tolleranza
            if ($tolerance < 5) $tolerance = 5;
            
            if ($length > $maxChars + $tolerance) {
                $errors[] = "Troppo lungo: $length caratteri (massimo {$maxChars})";
            } elseif ($length > $maxChars) {
                $warnings[] = "⚠️ Leggermente sopra limite: $length chars (ideale max {$maxChars})";
            }
        }
        
        if (isset($policy['min_length']) && $length < $policy['min_length']) {
            $errors[] = "Troppo corto: $length caratteri (minimo {$policy['min_length']})";
        }
        
        return ['errors' => $errors, 'warnings' => $warnings, 'length' => $length];
    }

    /**
     * Validazione parole vietate con context-awareness
     * Hard block solo su pattern realmente gravi
     */
    private function validateForbiddenWords($content)
    {
        $errors = [];
        
        // ═══════════════════════════════════════════════════
        // HARD BLOCK: Pattern davvero pericolosi (regex strict)
        // ═══════════════════════════════════════════════════
        
        $hardPatterns = [
            // Medical claims non supportati
            '/cura (il|la|lo|i|le|gli)\s+(cancro|tumore|diabete|malattia|covid|virus)/i' 
                => 'Claim medico non supportato scientificamente',
            
            '/guarisce (il|la|lo|i|le|gli)\s+/i' 
                => 'Claim di guarigione non ammesso',
            
            '/elimina (il|la|lo|i|le|gli)\s+(cancro|tumore|malattia|grasso|cellulite)/i' 
                => 'Claim irrealistico non ammesso',
            
            '/previene (il|la|lo|i|le|gli)\s+(cancro|tumore|malattia|covid)/i' 
                => 'Claim preventivo non supportato',
            
            // Comparative claims
            '/(migliore|best|#1|numero\s*1|nº\s*1|n\.\s*1)/i' 
                => 'Claim comparativo assoluto vietato',
            
            '/(top|best)\s*(seller|rated|choice)/i' 
                => 'Ranking claim non ammesso',
            
            // Absolute guarantees
            '/garanzia\s+(100%|totale|assoluta|completa|sicura)/i' 
                => 'Garanzia assoluta non ammessa',
            
            '/garantito\s+(100%|totalmente|completamente)/i' 
                => 'Garanzia assoluta non ammessa',
            
            // Price/shipping claims
            '/(spedizione|shipping)\s+(gratis|gratuita|free)/i' 
                => 'Claim spedizione vietato (variabile per utente)',
            
            '/(prezzo|price)\s+(più basso|lowest|minimo garantito)/i' 
                => 'Claim prezzo non ammesso',
        ];
        
        foreach ($hardPatterns as $pattern => $reason) {
            if (preg_match($pattern, $content)) {
                $errors[] = $reason;
            }
        }
        
        // ═══════════════════════════════════════════════════
        // SOFT CHECK: Parole sensibili (non bloccanti)
        // Verranno gestite dal self-repair loop con contesto
        // ═══════════════════════════════════════════════════
        
        // Esempi di parole OK in certi contesti:
        // - "cura" in "accuratamente" ✅
        // - "naturale" come aggettivo ✅
        // - "premium" come descrittore qualità ✅
        
        return $errors;
    }

    /**
     * Validazione caratteri speciali non permessi
     */
    private function validateSpecialChars($content, $fieldName)
    {
        $warnings = [];
        
        // Caratteri Amazon non permessi nei title
        if ($fieldName === 'item_name') {
            if (preg_match('/[©®™]/', $content)) {
                $warnings[] = "Contiene caratteri speciali non permessi (©®™)";
            }
            
            // Check CAPS LOCK eccessivo
            $uppercaseRatio = $this->getUppercaseRatio($content);
            if ($uppercaseRatio > 0.5) {
                $warnings[] = "Troppo MAIUSCOLO (evita CAPS LOCK eccessivo)";
            }
        }
        
        return $warnings;
    }
    
    /**
     * Validazione pattern strutturale del titolo
     * Verifica: Brand - Prodotto Formato | Caratteristica | Benefit
     */
    private function validateTitlePattern($content)
    {
        $errors = [];
        
        // Pattern regex per struttura titolo Amazon
        // Brand - Prodotto XXg/ml/pz | Feature | Benefit
        // Esempio: Valsapori - Granella Pistacchio 100g | Premium | Per Dolci
        
        // Check 1: Separatore trattino " - " presente
        if (!preg_match('/\s-\s/', $content)) {
            $errors[] = "Titolo deve contenere ' - ' dopo il brand (es: Brand - Prodotto)";
        }
        
        // Check 2: Formato (peso/volume) entro primi 60 caratteri
        // Pattern: numeri + unità (g, kg, ml, l, pz, cm, etc)
        $first60 = substr($content, 0, 60);
        if (!preg_match('/\d+\s*(g|kg|ml|l|pz|cm|mm|m)\b/i', $first60)) {
            $errors[] = "Formato prodotto (es: 100g, 250ml) deve essere nei primi 60 caratteri per visibilità mobile";
        }
        
        // Check 3: Lunghezza brand (primi 20 char prima di " - ")
        if (preg_match('/^([^-]+)\s-\s/', $content, $matches)) {
            $brand = trim($matches[1]);
            if (strlen($brand) < 2) {
                $errors[] = "Brand troppo corto o mancante all'inizio del titolo";
            }
            if (strlen($brand) > 30) {
                $errors[] = "Brand troppo lungo (max 30 caratteri prima di ' - ')";
            }
        } else {
            $errors[] = "Titolo deve iniziare con: Brand - Prodotto";
        }
        
        return $errors;
    }

    /**
     * Validazione HTML
     */
    private function validateHtml($content)
    {
        $warnings = [];
        
        // Check tag HTML non chiusi
        if (substr_count($content, '<') !== substr_count($content, '>')) {
            $warnings[] = "Possibile tag HTML non chiuso correttamente";
        }
        
        // Check tag non permessi
        $allowedTags = ['strong', 'b', 'br', 'ul', 'li', 'p', 'em', 'i'];
        preg_match_all('/<(\w+)/', $content, $matches);
        
        if (!empty($matches[1])) {
            $usedTags = array_unique($matches[1]);
            $notAllowed = array_diff($usedTags, $allowedTags);
            
            if (!empty($notAllowed)) {
                $warnings[] = "Tag HTML non permessi: " . implode(', ', $notAllowed);
            }
        }
        
        return $warnings;
    }

    /**
     * Campi che supportano HTML
     */
    private function fieldSupportsHtml($fieldName)
    {
        return in_array($fieldName, [
            'product_description',
            'bullet_point1',
            'bullet_point2',
            'bullet_point3',
            'bullet_point4',
            'bullet_point5'
        ]);
    }

    /**
     * Validazione batch (multipli campi)
     */
    public function validateMultiple($fields)
    {
        $results = [];
        $allValid = true;
        
        foreach ($fields as $fieldName => $content) {
            $result = $this->validate($fieldName, $content);
            $results[$fieldName] = $result;
            
            if (!$result['valid']) {
                $allValid = false;
            }
        }
        
        return [
            'all_valid' => $allValid,
            'results' => $results
        ];
    }

    /**
     * Calcola ratio maiuscole
     */
    private function getUppercaseRatio($text)
    {
        $letters = preg_replace('/[^a-zA-Z]/', '', $text);
        
        if (strlen($letters) === 0) {
            return 0;
        }
        
        $uppercase = preg_replace('/[^A-Z]/', '', $letters);
        
        return strlen($uppercase) / strlen($letters);
    }

    /**
     * Validazione rapida solo lunghezza (metodo pubblico)
     */
    public function validateLengthOnly($fieldName, $content)
    {
        $policy = $this->policyManager->getPolicyForField($fieldName);
        $length = strlen($content);
        
        if (!$policy) {
            return ['valid' => true, 'length' => $length];
        }
        
        $valid = true;
        
        if (isset($policy['max_length']) && $length > $policy['max_length']) {
            $valid = false;
        }
        
        if (isset($policy['min_length']) && $length < $policy['min_length']) {
            if (!isset($policy['min_length_warning_only']) || !$policy['min_length_warning_only']) {
                $valid = false;
            }
        }
        
        return [
            'valid' => $valid,
            'length' => $length,
            'min' => $policy['min_length'] ?? null,
            'max' => $policy['max_length'] ?? null
        ];
    }
}

