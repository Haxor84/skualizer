<?php
/**
 * PromptBuilder
 * Costruisce prompt AI ottimizzati per ogni campo
 */
class PromptBuilder
{
    private $policyManager;

    public function __construct(PolicyManager $policyManager)
    {
        $this->policyManager = $policyManager;
    }

    /**
     * Costruisce prompt completo per generazione campo
     * 
     * @param string $fieldName Nome campo da generare
     * @param array $context Dati prodotto e contesto
     * @return string Prompt pronto per LLM
     */
    public function buildPrompt($fieldName, $context)
    {
        // 🔍 DEBUG: Log context ricevuto
        error_log("=== PromptBuilder DEBUG ===");
        error_log("Field: $fieldName");
        error_log("Context: " . json_encode($context, JSON_UNESCAPED_UNICODE));
        
        // ⚠️ VALIDAZIONE: Verifica context non vuoto
        if (empty($context['sku']) && empty($context['current_title']) && empty($context['brand'])) {
            error_log("⚠️ WARNING: Context quasi vuoto! AI potrebbe allucinare.");
        } else {
            error_log("✅ Context OK: Brand={$context['brand']}, SKU={$context['sku']}");
        }
        
        // Se retry per brand enforcement, aggiungi warning FORTE
        $brandEnforcement = '';
        if (!empty($context['_brand_enforcement'])) {
            $brandEnforcement = "\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                "⚠️⚠️⚠️ ATTENZIONE CRITICA - SECONDO TENTATIVO ⚠️⚠️⚠️\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                "Il tentativo precedente ha usato il BRAND SBAGLIATO!\n" .
                "DEVI iniziare il titolo ESATTAMENTE con: {$context['brand']}\n" .
                "NON usare nessun altro brand!\n" .
                "NON inventare!\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            
            error_log("⚠️ BRAND ENFORCEMENT ATTIVO - Retry per brand sbagliato");
        }
        
        $policy = $this->policyManager->getPolicyForField($fieldName);
        
        $prompt = $this->buildHeader($fieldName);
        $prompt .= $brandEnforcement; // ← ENFORCEMENT SE RETRY
        
        // ⚠️ TEMPLATE OBBLIGATORIO PER TITOLO - PRIMA DI TUTTO!
        if ($fieldName === 'item_name') {
            $prompt .= $this->buildTitleTemplate($context);
        }
        
        $prompt .= $this->buildProductInfo($context);
        $prompt .= $this->buildPolicyRules($policy);
        $prompt .= $this->buildKeywords($context);
        $prompt .= $this->buildInstructions($fieldName, $policy);
        
        // 🔍 DEBUG: Log prompt finale
        error_log("Prompt final length: " . strlen($prompt) . " chars");
        error_log("Brand enforcement: " . ($brandEnforcement ? 'YES' : 'NO'));
        error_log("===========================");
        
        return $prompt;
    }

    /**
     * Header del prompt con ruolo copywriter emozionale
     */
    private function buildHeader($fieldName)
    {
        $fieldLabels = [
            'item_name' => 'TITOLO PRODOTTO',
            'product_description' => 'DESCRIZIONE PRODOTTO',
            'bullet_point1' => 'BULLET POINT',
            'bullet_point2' => 'BULLET POINT',
            'bullet_point3' => 'BULLET POINT',
            'bullet_point4' => 'BULLET POINT',
            'bullet_point5' => 'BULLET POINT',
            'generic_keywords' => 'PAROLE CHIAVE NASCOSTE'
        ];
        
        $label = $fieldLabels[$fieldName] ?? strtoupper(str_replace('_', ' ', $fieldName));
        
        $header = "═══════════════════════════════════════════════════\n";
        $header .= "🎯 SEI UN COPYWRITER ESPERTO DI MARKETING EMOZIONALE\n";
        $header .= "═══════════════════════════════════════════════════\n\n";
        
        $header .= "SPECIALIZZAZIONI:\n";
        $header .= "• Marketing persuasivo e tecniche di vendita emozionale\n";
        $header .= "• Bias cognitivi e trigger psicologici (scarsità, autorità, prova sociale)\n";
        $header .= "• Storytelling e narrative marketing per e-commerce\n";
        $header .= "• Copywriting Amazon ad alta conversione\n";
        $header .= "• SEO optimization per marketplace italiani\n\n";
        
        $header .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $header .= "📝 CAMPO DA GENERARE: $label\n";
        $header .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        return $header;
    }
    
    /**
     * Template OBBLIGATORIO per titolo Amazon
     * ULTRA-VISIBILE e con esempi SBAGLIATI vs CORRETTI
     */
    private function buildTitleTemplate($context)
    {
        $brand = $context['brand'] ?? 'NOBRAND';
        $weight = $context['weight'] ?? '100g';
        
        $template = "\n\n";
        $template .= "████████████████████████████████████████████████████████████████\n";
        $template .= "██                                                            ██\n";
        $template .= "██  ⚠️⚠️⚠️  TEMPLATE OBBLIGATORIO - LEGGI PRIMA!  ⚠️⚠️⚠️  ██\n";
        $template .= "██                                                            ██\n";
        $template .= "████████████████████████████████████████████████████████████████\n\n";
        
        $template .= "IL TITOLO DEVE INIZIARE CON IL BRAND!\n";
        $template .= "NESSUNA ECCEZIONE! NESSUNA VARIAZIONE!\n\n";
        
        $template .= "STRUTTURA OBBLIGATORIA:\n";
        $template .= "┌─────────────────────────────────────────────────┐\n";
        $template .= "│  {$brand} - [Prodotto] {$weight} | [Feature] | [Benefit]  │\n";
        $template .= "│  ↑↑↑↑↑↑↑↑↑                                      │\n";
        $template .= "│  BRAND PRIMA DI TUTTO!                          │\n";
        $template .= "└─────────────────────────────────────────────────┘\n\n";
        
        $template .= "ESEMPIO CORRETTO PER QUESTO PRODOTTO:\n";
        $template .= "✅ {$brand} - Menta Piperita Essiccata {$weight} | Al Sole Premium | Per Tisane e Cucina\n\n";
        
        $template .= "❌❌❌ ERRORI DA NON FARE MAI ❌❌❌\n\n";
        $template .= "SBAGLIATO #1 (prodotto prima del brand):\n";
        $template .= "❌ Menta Piperita Essiccata al Sole - {$brand} - 100g\n";
        $template .= "   ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^\n";
        $template .= "   ERRORE: Il prodotto viene PRIMA del brand!\n\n";
        
        $template .= "SBAGLIATO #2 (formato troppo lontano):\n";
        $template .= "❌ {$brand} - Menta Piperita Essiccata Premium Naturale (100g)\n";
        $template .= "   ERRORE: Formato oltre i 60 caratteri!\n\n";
        
        $template .= "SBAGLIATO #3 (nessun separatore):\n";
        $template .= "❌ {$brand} Menta Piperita Essiccata 100g Naturale Premium\n";
        $template .= "   ERRORE: Manca il separatore ' - ' dopo il brand!\n\n";
        
        $template .= "REGOLA D'ORO:\n";
        $template .= "1. Scrivi il brand: {$brand}\n";
        $template .= "2. Aggiungi spazio-trattino-spazio: ' - '\n";
        $template .= "3. Aggiungi nome prodotto\n";
        $template .= "4. Aggiungi formato entro 50 caratteri: {$weight}\n";
        $template .= "5. Aggiungi pipe: ' | '\n";
        $template .= "6. Aggiungi caratteristica\n";
        $template .= "7. Aggiungi pipe: ' | '\n";
        $template .= "8. Aggiungi benefit\n\n";
        
        $template .= "VERIFICA PRIMA DI INVIARE:\n";
        $template .= "□ Il titolo inizia con '{$brand}'?\n";
        $template .= "□ C'è ' - ' subito dopo il brand?\n";
        $template .= "□ Il formato {$weight} è entro i primi 50 caratteri?\n";
        $template .= "□ Ci sono i separatori ' | '?\n\n";
        
        $template .= "████████████████████████████████████████████████████████████████\n\n";
        
        return $template;
    }

    /**
     * Informazioni prodotto (SUPER RICCHE E COMPLETE)
     */
    private function buildProductInfo($context)
    {
        $info = "═══════════════════════════════════════════════════\n";
        $info .= "📦 PRODOTTO DA ANALIZZARE\n";
        $info .= "═══════════════════════════════════════════════════\n\n";
        
        // ===== IDENTIFICAZIONE PRODOTTO =====
        $info .= "🏷️ IDENTIFICAZIONE:\n";
        if (!empty($context['sku'])) {
            $info .= "• SKU: {$context['sku']}\n";
        }
        
        // Brand con ENFORCEMENT FORTE e DEBUG SOURCE
        if (!empty($context['brand'])) {
            $info .= "• ⚠️ BRAND (USA ESATTAMENTE QUESTO): {$context['brand']}\n";
            
            // DEBUG: Mostra source del brand (priority chain)
            if (!empty($context['brand_from_title'])) {
                $info .= "  ✅ Source: Estratto dal titolo attuale\n";
            } elseif (!empty($context['brand_field'])) {
                $info .= "  ✅ Source: Campo brand_name\n";
            } elseif (!empty($context['manufacturer'])) {
                $info .= "  ✅ Source: Campo manufacturer\n";
            } elseif ($context['brand'] === 'NOBRAND') {
                $info .= "  ⚠️ Source: FALLBACK (nessun brand trovato nei dati)\n";
                $info .= "  ⚠️ IMPORTANTE: Usa 'NOBRAND' ma l'utente DEVE modificarlo manualmente!\n";
            }
            
            // Warning se brand_field diverso da quello usato
            if (!empty($context['brand_field']) && $context['brand'] !== $context['brand_field']) {
                $info .= "  ℹ️ Brand Amazon registrato diverso: {$context['brand_field']} (ignorato, priorità a titolo)\n";
            }
        }
        
        if (!empty($context['manufacturer'])) {
            $info .= "• Produttore: {$context['manufacturer']}\n";
        }
        if (!empty($context['external_product_id'])) {
            $info .= "• EAN/ASIN: {$context['external_product_id']}\n";
        }
        $info .= "\n";
        
        // ===== CATEGORIA E TIPO =====
        $info .= "📂 CATEGORIA:\n";
        $info .= "• Categoria Amazon: " . ($context['category'] ?? 'Food & Grocery') . "\n";
        if (!empty($context['product_type'])) {
            $info .= "• Tipo Prodotto: {$context['product_type']}\n";
        }
        $info .= "\n";
        
        // ===== FORMATO/PESO =====
        if (!empty($context['weight']) || !empty($context['unit_count'])) {
            $info .= "⚖️ FORMATO:\n";
            if (!empty($context['weight'])) {
                $info .= "• Peso/Formato: {$context['weight']}\n";
            }
            if (!empty($context['unit_count']) && !empty($context['unit_count_type'])) {
                $info .= "• Contenuto: {$context['unit_count']} {$context['unit_count_type']}\n";
            }
            $info .= "\n";
        }
        
        // ===== ORIGINE =====
        if (!empty($context['country'])) {
            $info .= "🌍 ORIGINE:\n";
            $info .= "• Paese: {$context['country']}\n\n";
        }
        
        // ===== TITOLO ATTUALE =====
        if (!empty($context['current_title'])) {
            $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $info .= "📝 TITOLO ATTUALE (DA OTTIMIZZARE):\n";
            $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $info .= "\"{$context['current_title']}\"\n";
            $info .= "Lunghezza: " . strlen($context['current_title']) . " caratteri\n";
            $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        }
        
        // ===== DESCRIZIONE ATTUALE =====
        if (!empty($context['current_description'])) {
            $desc = $context['current_description'];
            // Rimuovi HTML per analisi
            $descPlain = strip_tags($desc);
            $descExcerpt = mb_substr($descPlain, 0, 500);
            
            $info .= "📄 DESCRIZIONE ATTUALE (estratto):\n";
            $info .= $descExcerpt;
            if (mb_strlen($descPlain) > 500) {
                $info .= "...\n";
            }
            $info .= "\n\n";
        }
        
        // ===== BULLET POINTS ATTUALI =====
        if (!empty($context['bullets']) && is_array($context['bullets'])) {
            $info .= "📌 BULLET POINTS ATTUALI:\n";
            foreach ($context['bullets'] as $i => $bullet) {
                $bulletPlain = strip_tags($bullet);
                $bulletExcerpt = mb_substr($bulletPlain, 0, 150);
                $info .= ($i + 1) . ". " . $bulletExcerpt;
                if (mb_strlen($bulletPlain) > 150) {
                    $info .= "...";
                }
                $info .= "\n";
            }
            $info .= "\n";
        }
        
        // ===== KEYWORDS ATTUALI =====
        if (!empty($context['keywords']) && is_array($context['keywords'])) {
            $info .= "🔑 KEYWORDS ATTUALI:\n";
            $keywords = array_slice($context['keywords'], 0, 20); // Prime 20
            $info .= implode(", ", $keywords);
            if (count($context['keywords']) > 20) {
                $info .= ", ... (+" . (count($context['keywords']) - 20) . " altre)";
            }
            $info .= "\n\n";
        }
        
        // ===== ATTRIBUTI PRODOTTO =====
        if (!empty($context['attributes']) && is_array($context['attributes'])) {
            $info .= "✨ CARATTERISTICHE SPECIALI:\n";
            foreach ($context['attributes'] as $attr) {
                $info .= "• " . $attr . "\n";
            }
            $info .= "\n";
        }
        
        // ===== PREZZO (per context) =====
        if (!empty($context['price'])) {
            $info .= "💰 PREZZO: €{$context['price']}\n\n";
        }
        
        $info .= "═══════════════════════════════════════════════════\n\n";
        
        return $info;
    }

    /**
     * Regole policy Amazon
     */
    private function buildPolicyRules($policy)
    {
        if (!$policy) {
            return "";
        }

        $rules = "POLICY AMAZON (OBBLIGATORIE):\n";
        
        if (isset($policy['min_length']) && isset($policy['max_length'])) {
            $rules .= "• Lunghezza: {$policy['min_length']}-{$policy['max_length']} caratteri\n";
        } elseif (isset($policy['max_length'])) {
            $rules .= "• Lunghezza massima: {$policy['max_length']} caratteri\n";
        }
        
        // Struttura raccomandata
        $structure = $this->policyManager->getRecommendedStructure($policy['fieldName'] ?? '');
        if ($structure) {
            $rules .= "• Struttura raccomandata: $structure\n";
        }
        
        // Raccomandazioni
        $recommendations = $this->policyManager->getRecommendations($policy['fieldName'] ?? '');
        if (!empty($recommendations)) {
            foreach ($recommendations as $rec) {
                $rules .= "• $rec\n";
            }
        }
        
        // Parole vietate
        $forbidden = $this->policyManager->getForbiddenWords();
        if (!empty($forbidden)) {
            $rules .= "• VIETATO usare: " . implode(', ', array_slice($forbidden, 0, 10)) . "\n";
        }
        
        $rules .= "\n";
        return $rules;
    }

    /**
     * Keywords da includere
     */
    private function buildKeywords($context)
    {
        if (empty($context['keywords'])) {
            return "";
        }

        $keywords = is_array($context['keywords']) ? $context['keywords'] : explode(',', $context['keywords']);
        $keywords = array_filter(array_map('trim', $keywords));
        
        if (empty($keywords)) {
            return "";
        }

        $kw = "KEYWORDS DA INCLUDERE (ordinate per rilevanza):\n";
        foreach (array_slice($keywords, 0, 15) as $i => $keyword) {
            $priority = $i < 5 ? '★★★' : ($i < 10 ? '★★' : '★');
            $kw .= "• $keyword $priority\n";
        }
        $kw .= "\n";
        
        return $kw;
    }

    /**
     * Istruzioni finali con MARKETING EMOZIONALE e BIAS
     */
    private function buildInstructions($fieldName, $policy)
    {
        $instructions = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $instructions .= "⚠️ REGOLE ASSOLUTE DA RISPETTARE\n";
        $instructions .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $instructions .= "1. 🎯 PRODOTTO E BRAND:\n";
        $instructions .= "   • Mantieni ESATTAMENTE il brand indicato sopra\n";
        $instructions .= "   • Usa ESATTAMENTE il formato da item_sku\n";
        $instructions .= "   • NON inventare claim non verificati\n";
        $instructions .= "   • NON usare \"biologico\", \"certificato\", \"DOP\" se non presenti nei dati\n\n";
        
        $instructions .= "2. 📏 LUNGHEZZA TESTO:\n";
        if ($policy) {
            $countMethod = $policy['count_method'] ?? 'plain_text';
            if ($countMethod === 'without_html') {
                $instructions .= "   • Conta caratteri ESCLUDENDO tag HTML\n";
            } else {
                $instructions .= "   • Conta caratteri totali (testo plain)\n";
            }
            
            if (isset($policy['min_length']) && isset($policy['max_length'])) {
                $instructions .= "   • Minimo: {$policy['min_length']} caratteri\n";
                $instructions .= "   • Massimo: {$policy['max_length']} caratteri\n";
            }
            $instructions .= "   • ⚠️ OBBLIGATORIO: Rispetta questi limiti STRETTAMENTE\n\n";
        }
        
        $instructions .= "3. ✅ COMPLETEZZA:\n";
        $instructions .= "   • NO testo troncato (... alla fine)\n";
        $instructions .= "   • NO frasi incomplete\n";
        $instructions .= "   • NO concetti lasciati in sospeso\n";
        $instructions .= "   • Ogni frase DEVE essere completa e di senso compiuto\n\n";
        
        $instructions .= "4. 🧠 MARKETING EMOZIONALE - USA QUESTI BIAS:\n";
        $instructions .= "   • SCARSITÀ: \"Selezionato\", \"Premium\", \"Elite\", \"Edizione Limitata\"\n";
        $instructions .= "   • AUTORITÀ: \"Artigianale\", \"Tradizionale\", \"Esperti\", \"Maestri\"\n";
        $instructions .= "   • PROVA SOCIALE: Riferimenti a successo/riconoscimenti\n";
        $instructions .= "   • BENEFICIO: Evidenzia vantaggi tangibili e risultati\n";
        $instructions .= "   • EMOZIONE: Evoca esperienze sensoriali (gusto, aroma, consistenza)\n";
        $instructions .= "   • URGENZA: \"Ideale per\", \"Perfetto per\", \"Essenziale\"\n\n";
        
        $instructions .= "5. 🎨 STILE PERSUASIVO:\n";
        $instructions .= "   • Tono: Professionale ma caldo ed emozionale\n";
        $instructions .= "   • Focus: Benefici > Caratteristiche tecniche\n";
        $instructions .= "   • Linguaggio: Sensoriale ed evocativo\n";
        $instructions .= "   • Verbi: Attivi e d'azione\n";
        $instructions .= "   • Aggettivi: Qualificanti e distintivi (non generici)\n\n";
        
        $instructions .= "6. 🇮🇹 MERCATO ITALIANO:\n";
        $instructions .= "   • Italiano perfetto, grammatica impeccabile\n";
        $instructions .= "   • Espressioni naturali per mercato IT\n";
        $instructions .= "   • SEO per Amazon.it\n\n";
        
        // Istruzioni specifiche campo
        $instructions .= $this->getFieldSpecificInstructions($fieldName, $policy);
        
        $instructions .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $instructions .= "OUTPUT RICHIESTO\n";
        $instructions .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $instructions .= "Fornisci SOLO il contenuto finale.\n";
        $instructions .= "NO preamble, NO spiegazioni, NO commenti.\n";
        $instructions .= "SOLO il testo pronto per Amazon.\n";
        $instructions .= "VERIFICA lunghezza caratteri prima di inviare.\n";
        
        return $instructions;
    }

    /**
     * Istruzioni specifiche per tipo di campo con POLICY e BIAS
     */
    private function getFieldSpecificInstructions($fieldName, $policy)
    {
        // Estrai lunghezze da policy
        $minChars = $policy['min_length'] ?? 0;
        $maxChars = $policy['max_length'] ?? 0;
        $countMethod = $policy['count_method'] ?? 'plain_text';
        
        $lengthNote = "";
        if ($minChars && $maxChars) {
            if ($countMethod === 'without_html') {
                $lengthNote = "\n⚠️ LUNGHEZZA OBBLIGATORIA: $minChars-$maxChars caratteri (ESCLUSI tag HTML)\n";
            } else {
                $lengthNote = "\n⚠️ LUNGHEZZA OBBLIGATORIA: $minChars-$maxChars caratteri\n";
            }
        }
        
        $specific = [
            'item_name' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                          "📝 ISTRUZIONI TITOLO AMAZON - REGOLA MOBILE-FIRST\n" .
                          "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                          $lengthNote . "\n\n" .
                          
                          "⚠️⚠️⚠️ STRUTTURA RIGIDA OBBLIGATORIA (NON NEGOZIABILE) ⚠️⚠️⚠️\n\n" .
                          
                          "TEMPLATE FISSO:\n" .
                          "Brand - Tipo Prodotto Formato | Caratteristica Principale | Benefit/Uso\n\n" .
                          
                          "SPIEGAZIONE SEZIONI:\n" .
                          "1. BRAND: Nome brand ESATTO (da context)\n" .
                          "2. SEPARATORE: Spazio Trattino Spazio ( - )\n" .
                          "3. TIPO PRODOTTO: Nome generico (es: Granella di Pistacchio)\n" .
                          "4. FORMATO: SUBITO dopo prodotto, NO parentesi (es: 100g, 250ml, 5pz)\n" .
                          "5. SEPARATORE: Spazio Pipe Spazio ( | )\n" .
                          "6. CARATTERISTICA: Principale distintiva (es: 100% Puro, Bio, Premium)\n" .
                          "7. SEPARATORE: Spazio Pipe Spazio ( | )\n" .
                          "8. BENEFIT/USO: Utilizzo o target (es: Ideale per Pasticceria)\n\n" .
                          
                          "⚠️ FORMATO IMMEDIATO (primi 50 caratteri):\n" .
                          "Il formato DEVE essere visibile entro i primi 50 caratteri!\n" .
                          "Questo è CRITICO per visualizzazione mobile.\n\n" .
                          
                          "ESEMPI CORRETTI:\n\n" .
                          
                          "FOOD:\n" .
                          "Valsapori - Granella di Pistacchio 100g | 100% Puro Crudo | Ideale Pasticceria e Dolci\n" .
                          "         ^^BRAND  ^^PRODOTTO        ^^FORMATO immediato!\n\n" .
                          
                          "Valsapori - Pasta di Pistacchio 200g | Naturale Senza Additivi | Per Gelati e Creme\n\n" .
                          
                          "TEXTILE:\n" .
                          "CasaItalia - Tovaglia Lino 150x250cm | Tessuto Premium Italiano | Eleganza Tavola\n\n" .
                          
                          "ELECTRONICS:\n" .
                          "TechPro - Power Bank 20000mAh | Ricarica Rapida USB-C | Autonomia 5 Giorni\n\n" .
                          
                          "⚠️ ERRORI DA EVITARE:\n" .
                          "❌ SBAGLIATO: Valsapori Granella di Pistacchio Americano Premium (100g)\n" .
                          "   Problema: Formato troppo lontano (oltre 50 chars)\n\n" .
                          
                          "✅ CORRETTO: Valsapori - Granella di Pistacchio 100g | Premium Americano\n" .
                          "   Formato a 39 chars = Visibile su mobile!\n\n" .
                          
                          "❌ SBAGLIATO: Power Bank da 20000mAh TechPro USB-C Ricarica Veloce\n" .
                          "   Problema: Brand non all'inizio\n\n" .
                          
                          "✅ CORRETTO: TechPro - Power Bank 20000mAh | USB-C Ricarica Veloce\n\n" .
                          
                          "⚠️ ESTRAZIONE FORMATO:\n" .
                          "Il formato è fornito nel context come 'weight'.\n" .
                          "USA il formato fornito ESATTAMENTE come indicato.\n" .
                          "Formato breve: '100g', '250ml', '5pz' (NO spazi, NO parentesi)\n\n" .
                          
                          "⚠️ VERIFICA FINALE:\n" .
                          "Conta caratteri fino al formato:\n" .
                          "Brand - Tipo Prodotto 100g\n" .
                          "^^^^^^^^^^^^^^^^^^^^^^^^^^ deve essere < 50 caratteri!",
            
            'product_description' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                                    "📄 ISTRUZIONI DESCRIZIONE AMAZON\n" .
                                    "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                                    "⚠️⚠️⚠️ LIMITI CARATTERI (LEGGI CON ATTENZIONE) ⚠️⚠️⚠️\n\n" .
                                    "TEXT-ONLY (senza HTML): 800-1200 caratteri OBBLIGATORIO\n" .
                                    "TOTALE con HTML: max 2000 caratteri\n\n" .
                                    "COME CONTARE:\n" .
                                    "✅ '<b>Pistacchio Premium</b>' = 18 chars TEXT (conta SOLO 'Pistacchio Premium')\n" .
                                    "✅ 'Qualità<br>Superiore' = 17 chars TEXT (conta SOLO 'QualitàSuperiore')\n" .
                                    "✅ Tag HTML (<b>, <br>, <ul>, <li>) NON contano nel limite 800-1200!\n\n" .
                                    "QUESTO SIGNIFICA:\n" .
                                    "Puoi scrivere MOLTO contenuto HTML senza preoccuparti.\n" .
                                    "Focus: 1000 caratteri di TESTO PURO + tutto l'HTML che serve.\n\n" .
                                    $lengthNote . "\n" .
                                    "STRUTTURA COMPLETA (5 SEZIONI OBBLIGATORIE):\n" .
                                    "1. Hook emozionale (2-3 frasi impattanti) ~150 chars TEXT\n" .
                                    "2. Benefici principali (3-4 punti con <b>) ~300 chars TEXT\n" .
                                    "3. Caratteristiche tecniche specifiche ~200 chars TEXT\n" .
                                    "4. Modalità d'uso creative ~200 chars TEXT\n" .
                                    "5. Call-to-action finale persuasiva COMPLETA ~150 chars TEXT\n\n" .
                                    "⚠️ SEZIONE 5 OBBLIGATORIA:\n" .
                                    "La call-to-action finale DEVE essere presente e COMPLETA.\n" .
                                    "NO troncamenti tipo 'Che tu sia un pasticcere esperto o un'\n" .
                                    "Completa SEMPRE con concetto chiuso e punto finale.\n\n" .
                                    "HTML PERMESSO:\n" .
                                    "<b> <strong> <br> <ul> <li> <i> <em>\n\n" .
                                    "BIAS DA USARE:\n" .
                                    "• Scarsità: \"Selezionato con cura\", \"Edizione Limitata\"\n" .
                                    "• Autorità: \"Processo artigianale\", \"Maestri selezionatori\"\n" .
                                    "• Risultato: \"Risultati straordinari\", \"Trasforma ogni ricetta\"\n" .
                                   "• Esperienza: Evoca sensazioni sensoriali ed emotive\n\n" .
                                   "TONO:\n" .
                                   "Warm, professionale, benefit-oriented, evocativo\n\n" .
                                   "ESEMPI HOOK (ADATTA al tuo prodotto):\n\n" .
                                   "FOOD: <b>Scopri l'essenza pura del pistacchio americano</b>, selezionato con cura dai maestri artigiani per garantirti qualità superiore...\n\n" .
                                   "TEXTILE: <b>Trasforma la tua tavola in un capolavoro di eleganza</b> con tovaglie ricamate a mano secondo tradizione italiana...\n\n" .
                                   "ELECTRONICS: <b>Rivoluziona la tua esperienza di ricarica</b> con tecnologia fast charge certificata e protezione intelligente integrata...",
            
            'bullet_point1' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                              "📌 ISTRUZIONI BULLET POINT\n" .
                              "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                              $lengthNote . "\n" .
                              "FORMATO OBBLIGATORIO:\n" .
                              "✓ KEYWORD IN MAIUSCOLO: Descrizione benefit specifica e completa.\n\n" .
                              "REGOLE:\n" .
                              "• Inizia con ✓ o ✔\n" .
                              "• Keyword principale in MAIUSCOLO (2-4 parole)\n" .
                              "• Poi frase completa sul benefit\n" .
                              "• Focus su UN benefit specifico\n" .
                              "• Linguaggio emozionale e persuasivo\n\n" .
                             "BIAS:\n" .
                             "• Qualità: \"Premium\", \"Superiore\", \"Eccellente\"\n" .
                             "• Sicurezza: \"100%\", \"Garantito\", \"Certificato\"\n" .
                             "• Risultato: \"Perfetto\", \"Ideale\", \"Ottimale\"\n\n" .
                             "ESEMPI (ADATTA al tuo prodotto):\n\n" .
                             "FOOD:\n" .
                             "✓ PISTACCHIO 100% PURO: Selezioniamo esclusivamente pistacchi premium americani di prima qualità, senza aggiunta di sale, zuccheri o conservanti, per garantirti purezza assoluta e sapore autentico.\n\n" .
                             "✓ VERSATILITÀ IN CUCINA: Perfetto per gelati artigianali, creme pasticcere, mousse e semifreddi. Trasforma ogni ricetta in un'esperienza gourmet con il suo sapore intenso e naturale.\n\n" .
                             "TEXTILE:\n" .
                             "✓ COTONE 100% EGIZIANO: Tessiamo ogni tovaglia con fibre lunghe selezionate, lavorate artigianalmente secondo tradizione italiana, per garantirti morbidezza eccezionale e resistenza duratura.\n\n" .
                             "✓ ANTIMACCHIA PROFESSIONALE: Trattamento idrorepellente certificato protegge da vino, olio e liquidi. Lavabile in lavatrice a 60°C senza perdere colore o forma per anni.\n\n" .
                             "ELECTRONICS:\n" .
                             "✓ FAST CHARGE 3.0 CERTIFICATA: Chip intelligenti di ultima generazione con protezione da sovraccarico integrata per ricarica sicura al 50% in 30 minuti e autonomia superiore.\n\n" .
                             "✓ COMPATIBILITÀ UNIVERSALE: Funziona perfettamente con iPhone, Samsung, Xiaomi e tutti gli smartphone Android. Include 4 adattatori per ogni tipo di dispositivo.",
            
            'generic_keywords' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                                 "🔑 ISTRUZIONI KEYWORDS NASCOSTE\n" .
                                 "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                                 $lengthNote . "\n\n" .
                                 
                                 "⚠️ LUNGHEZZA OBBLIGATORIA: 150-180 caratteri\n" .
                                 "⚠️ CONTA i caratteri mentre scrivi - DEVI usare MINIMO 150 caratteri!\n\n" .
                                 
                                 "FORMATO:\n" .
                                 "keyword1 keyword2 keyword3 keyword4...\n" .
                                 "(NO virgole - SOLO spazi)\n\n" .
                                 
                                 "REGOLE:\n" .
                                 "• SOLO keywords MAI usate in title/description/bullets\n" .
                                 "• NO ripetere parole già presenti sopra\n" .
                                 "• Ordine strategico per formare long-tail queries\n" .
                                 "• Sinonimi, varianti, termini correlati\n" .
                                 "• Keywords compound: 'crema spalmabile colazione proteica'\n\n" .
                                 
                                 "STRATEGIA ORDINE:\n" .
                                 "1. Tipo prodotto alternativo (es: 'crema spalmabile')\n" .
                                 "2. Origine/luogo (es: 'sicilia etna bronte')\n" .
                                 "3. Modalità uso (es: 'colazione spuntino merenda')\n" .
                                 "4. Benefici (es: 'proteica energetica nutriente')\n" .
                                 "5. Target (es: 'sportivi bambini famiglia')\n\n" .
                                 
                                 "ESEMPIO ESATTO (165 caratteri):\n" .
                                 "crema spalmabile verde sicilia etna bronte colazione spuntino merenda proteica energetica nutriente sportivi bambini famiglia genuino artigianale tradizionale\n\n" .
                                 
                                 "⚠️ VERIFICA: Conta caratteri finali - DEVE essere 150-180!"
        ];
        
        // Bullet point 2-5 usano stesse istruzioni di bullet_point1
        if (preg_match('/^bullet_point[2-5]$/', $fieldName)) {
            return $specific['bullet_point1'];
        }
        
        return $specific[$fieldName] ?? "";
    }
    
    /**
     * Costruisce prompt per generazione multi-campo coordinata
     */
    public function buildMultiFieldPrompt($fieldNames, $context)
    {
        $prompt = "═══════════════════════════════════════════════════\n";
        $prompt .= "🎯 GENERAZIONE MULTI-CAMPO COORDINATA\n";
        $prompt .= "═══════════════════════════════════════════════════\n\n";
        
        // ⚠️ REGOLA ASSOLUTA - NON NEGOZIABILE ⚠️
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "⚠️⚠️⚠️ REGOLA ASSOLUTA - NON NEGOZIABILE ⚠️⚠️⚠️\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $prompt .= "NON fare domande all'utente.\n";
        $prompt .= "NON chiedere chiarimenti.\n";
        $prompt .= "NON aspettare conferme.\n";
        $prompt .= "NON iniziare conversazioni.\n";
        $prompt .= "NON scrivere preamble o spiegazioni.\n\n";
        
        $prompt .= "IL TUO COMPITO È GENERARE CONTENUTI IMMEDIATAMENTE.\n\n";
        
        $prompt .= "TUTTE le informazioni necessarie sono nel CONTEXT fornito sotto.\n";
        $prompt .= "Se un'informazione manca, USA il tuo giudizio professionale.\n";
        $prompt .= "Prendi DECISIONI AUTONOME basate sui dati disponibili.\n\n";
        
        $prompt .= "GENERA i contenuti SUBITO usando i tag richiesti.\n";
        $prompt .= "INIZIA immediatamente con il primo tag [FIELD_NAME].\n\n";
        
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $prompt .= "SEI UN COPYWRITER ESPERTO che deve generare MULTIPLI campi per un listing Amazon.\n";
        $prompt .= "I campi devono essere COORDINATI e COERENTI tra loro.\n\n";
        
        $prompt .= "⚠️ REGOLE CRITICHE:\n";
        $prompt .= "1. COERENZA NARRATIVA: Tutti i campi raccontano la stessa storia\n";
        $prompt .= "2. NO RIPETIZIONI: Ogni campo usa parole/concetti DIVERSI\n";
        $prompt .= "3. DISTRIBUZIONE KEYWORDS: Massimizza keyword uniche distribuite\n";
        $prompt .= "4. COMPLETEZZA: Ogni campo DEVE essere completo (NO troncamenti)\n\n";
        
        // Informazioni prodotto
        $prompt .= $this->buildProductInfo($context);
        
        // Campi da generare con policy
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "CAMPI DA GENERARE\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($fieldNames as $fieldName) {
            $policy = $this->policyManager->getPolicyForField($fieldName);
            $prompt .= $this->buildFieldRequirements($fieldName, $policy);
        }
        
        // Strategia keyword distribution
        $prompt .= $this->buildKeywordStrategy($fieldNames);
        
        // ⚠️ LENGTH ENFORCEMENT ESPLICITO ⚠️
        $prompt .= $this->buildLengthEnforcement($fieldNames);
        
        // Formato output
        $prompt .= $this->buildOutputFormat($fieldNames);
        
        return $prompt;
    }
    
    /**
     * Requirements per singolo campo nel multi-prompt
     */
    private function buildFieldRequirements($fieldName, $policy)
    {
        $label = strtoupper(str_replace('_', ' ', $fieldName));
        $req = "📝 $label:\n";
        
        if ($policy) {
            $minChars = $policy['min_length'] ?? 0;
            $maxChars = $policy['max_length'] ?? 0;
            $countMethod = $policy['count_method'] ?? 'plain_text';
            
            if ($minChars && $maxChars) {
                $req .= "• Lunghezza: $minChars-$maxChars caratteri";
                if ($countMethod === 'without_html') {
                    $req .= " (ESCLUSI tag HTML)";
                }
                $req .= "\n";
            }
            
            // Rules principali
            if (isset($policy['rules']) && is_array($policy['rules'])) {
                foreach (array_slice($policy['rules'], 0, 3) as $rule) {
                    $req .= "• $rule\n";
                }
            }
        }
        
        $req .= "\n";
        
        return $req;
    }
    
    /**
     * Strategia distribuzione keywords
     */
    private function buildKeywordStrategy($fieldNames)
    {
        $strategy = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $strategy .= "🔑 STRATEGIA KEYWORD DISTRIBUTION\n";
        $strategy .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $strategy .= "OBIETTIVO: Massimizzare keywords uniche distribuite tra tutti i campi.\n\n";
        
        if (in_array('item_name', $fieldNames)) {
            $strategy .= "• TITLE: Keywords primarie ad alto volume (brand, prodotto, formato)\n";
        }
        
        if (in_array('product_description', $fieldNames)) {
            $strategy .= "• DESCRIPTION: Keywords secondarie + long-tail (benefici, usi)\n";
        }
        
        $bulletCount = 0;
        for ($i = 1; $i <= 5; $i++) {
            if (in_array("bullet_point$i", $fieldNames)) $bulletCount++;
        }
        
        if ($bulletCount > 0) {
            $strategy .= "• BULLETS ($bulletCount): Ogni bullet keyword/focus DIVERSO\n";
            $strategy .= "  - Bullet 1: Qualità/Purezza\n";
            $strategy .= "  - Bullet 2: Origine/Processo\n";
            $strategy .= "  - Bullet 3: Versatilità/Usi\n";
            $strategy .= "  - Bullet 4: Benefici nutrizionali\n";
            $strategy .= "  - Bullet 5: Garanzia/Certificazioni\n";
        }
        
        if (in_array('generic_keywords', $fieldNames)) {
            $strategy .= "• KEYWORDS NASCOSTE: SOLO keywords MAI usate sopra\n";
            $strategy .= "  - Sinonimi, varianti, termini correlati\n";
            $strategy .= "  - Ordine strategico per long-tail\n";
            $strategy .= "  - 150-180 caratteri, NO virgole, separate da spazio\n";
            $strategy .= "  - Esempio: 'crema verde etna spalmabile colazione proteica vita sana'\n";
        }
        
        $strategy .= "\n⚠️ VERIFICA: NO parole ripetute 3+ volte tra tutti i campi\n\n";
        
        return $strategy;
    }
    
    /**
     * Enforcement esplicito lunghezze caratteri
     */
    private function buildLengthEnforcement($fieldNames)
    {
        $enforcement = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $enforcement .= "⚠️⚠️⚠️ LUNGHEZZE CARATTERI OBBLIGATORIE ⚠️⚠️⚠️\n";
        $enforcement .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $enforcement .= "REGOLA CRITICA: USA TUTTI i caratteri disponibili!\n";
        $enforcement .= "NON essere conciso - DEVI raggiungere le lunghezze minime.\n\n";
        
        $enforcement .= "LUNGHEZZE ESATTE PER OGNI CAMPO:\n\n";
        
        // Definisci lunghezze per campo
        $lengths = [
            'item_name' => ['min' => 150, 'max' => 200, 'target' => 175],
            'product_description' => ['min' => 800, 'max' => 1200, 'target' => 1000, 'html_note' => 'senza HTML'],
            'bullet_point1' => ['min' => 180, 'max' => 250, 'target' => 220],
            'bullet_point2' => ['min' => 180, 'max' => 250, 'target' => 220],
            'bullet_point3' => ['min' => 180, 'max' => 250, 'target' => 220],
            'bullet_point4' => ['min' => 180, 'max' => 250, 'target' => 220],
            'bullet_point5' => ['min' => 180, 'max' => 250, 'target' => 220],
            'generic_keywords' => ['min' => 150, 'max' => 180, 'target' => 165]
        ];
        
        foreach ($fieldNames as $fieldName) {
            if (!isset($lengths[$fieldName])) continue;
            
            $l = $lengths[$fieldName];
            $label = strtoupper(str_replace('_', ' ', $fieldName));
            
            $enforcement .= "📝 {$label}:\n";
            $enforcement .= "   • MINIMO: {$l['min']} caratteri (OBBLIGATORIO)\n";
            $enforcement .= "   • MASSIMO: {$l['max']} caratteri\n";
            $enforcement .= "   • TARGET IDEALE: {$l['target']} caratteri";
            
            if (isset($l['html_note'])) {
                $enforcement .= " ({$l['html_note']})";
            }
            
            $enforcement .= "\n\n";
        }
        
        $enforcement .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $enforcement .= "⚠️ COME RAGGIUNGERE LE LUNGHEZZE:\n\n";
        $enforcement .= "BULLETS (180-250 chars):\n";
        $enforcement .= "• Inizia con ✓ KEYWORD MAIUSCOLA (5-8 parole)\n";
        $enforcement .= "• Poi descrizione dettagliata del benefit (15-25 parole)\n";
        $enforcement .= "• Aggiungi specificità: numeri, dettagli tecnici, esempi\n";
        $enforcement .= "• Esempio struttura (ADATTA al TUO prodotto):\n";
        $enforcement .= "  ✓ [CARATTERISTICA PRINCIPALE] 100% [QUALITÀ]: [Descrizione dettagliata del benefit con specificità numeriche, origine, processo, o certificazioni], [continuazione con vantaggi tangibili per il cliente], [chiusura con risultato/esperienza finale].\n\n";
        
        $enforcement .= "• Esempio concreto FOOD:\n";
        $enforcement .= "  ✓ PISTACCHIO 100% PURO AMERICANO: Selezioniamo esclusivamente pistacchi premium provenienti dalla California, senza sale, zuccheri o conservanti, per garantirti purezza assoluta e sapore autentico.\n\n";
        
        $enforcement .= "• Esempio concreto TEXTILE:\n";
        $enforcement .= "  ✓ COTONE 100% EGIZIANO: Tessiamo ogni tovaglia con fibre lunghe selezionate, lavorate artigianalmente secondo tradizione italiana, per garantirti morbidezza eccezionale e resistenza duratura nel tempo.\n\n";
        
        $enforcement .= "• Esempio concreto ELECTRONICS:\n";
        $enforcement .= "  ✓ TECNOLOGIA FAST CHARGE 3.0: Integriamo chip intelligenti di ultima generazione con protezione da sovraccarico certificata, per garantirti ricarica sicura al 50% in 30 minuti e autonomia superiore.\n\n";
        
        $enforcement .= "DESCRIPTION (800-1200 chars TEXT-ONLY, max 2000 TOTALI con HTML):\n\n";
        $enforcement .= "⚠️⚠️⚠️ REGOLA CRITICA CONTEGGIO CARATTERI ⚠️⚠️⚠️\n";
        $enforcement .= "• CONTA SOLO IL TESTO VISIBILE (ignora tag HTML)\n";
        $enforcement .= "• ESEMPIO: '<b>Premium</b>' = 7 caratteri (solo 'Premium'), NON 18\n";
        $enforcement .= "• HTML tags NON contano nei limiti 800-1200\n";
        $enforcement .= "• Puoi usare LIBERAMENTE <b>, <br>, <ul>, <li> senza penalità\n";
        $enforcement .= "• TOTALE FINALE: ~1000 chars TEXT + ~800 chars HTML = max 2000 totali\n\n";
        $enforcement .= "STRUTTURA COMPLETA (conta SOLO testo visibile):\n";
        $enforcement .= "• Paragrafo 1: Hook emozionale (3-4 frasi) ~150 chars TEXT\n";
        $enforcement .= "• Paragrafo 2: Benefici principali con <b> (4-5 punti) ~300 chars TEXT\n";
        $enforcement .= "• Paragrafo 3: Caratteristiche tecniche (3-4 punti) ~200 chars TEXT\n";
        $enforcement .= "• Paragrafo 4: Modalità d'uso (3-4 esempi) ~200 chars TEXT\n";
        $enforcement .= "• Paragrafo 5: Call-to-action finale COMPLETA (2-3 frasi) ~150 chars TEXT\n\n";
        $enforcement .= "⚠️ COMPLETAMENTO OBBLIGATORIO:\n";
        $enforcement .= "DEVI completare TUTTE le 5 sezioni con frasi COMPLETE.\n";
        $enforcement .= "NO troncamenti, NO frasi incomplete.\n";
        $enforcement .= "Paragrafo 5 deve finire con punto finale e concetto chiuso.\n\n";
        
        $enforcement .= "KEYWORDS (150-180 chars):\n";
        $enforcement .= "• 20-25 keywords separate da spazi (NO virgole)\n";
        $enforcement .= "• Ordina per formare long-tail queries naturali\n";
        $enforcement .= "• Conta mentre scrivi: 150 caratteri MINIMO\n\n";
        
        $enforcement .= "• Esempio FOOD:\n";
        $enforcement .= "  granella pistacchio siciliano biologica superfood spezzettata cruda decorazione torte colazione proteica energia naturale vitamina magnesio calcio snack salutare vegano\n\n";
        
        $enforcement .= "• Esempio TEXTILE:\n";
        $enforcement .= "  tovaglia ricamata elegante matrimonio cerimonia natale pasqua cotone lino antimacchia lavabile stiratura facile made italy artigianale classica moderna lusso\n\n";
        
        $enforcement .= "• Esempio ELECTRONICS:\n";
        $enforcement .= "  caricabatterie veloce universale smartphone tablet sicuro certificato portatile viaggio compatto leggero ricarica rapida wireless alimentatore usb tipo c economico\n\n";
        
        $enforcement .= "⚠️ VERIFICA PRIMA DI INVIARE:\n";
        $enforcement .= "Conta i caratteri di ogni campo.\n";
        $enforcement .= "Se sotto il minimo → AGGIUNGI dettagli, specificità, esempi.\n";
        $enforcement .= "USA tutto lo spazio disponibile!\n\n";
        
        return $enforcement;
    }
    
    /**
     * Formato output con counter e checklist
     */
    private function buildOutputFormat($fieldNames)
    {
        $format = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $format .= "⚠️ FORMATO OUTPUT CON VERIFICA LUNGHEZZA ⚠️\n";
        $format .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $format .= "DEVI rispondere ESATTAMENTE in questo formato:\n\n";
        
        // Lunghezze target
        $targets = [
            'item_name' => 175,
            'product_description' => 1000,
            'bullet_point1' => 220,
            'bullet_point2' => 220,
            'bullet_point3' => 220,
            'bullet_point4' => 220,
            'bullet_point5' => 220,
            'generic_keywords' => 165
        ];
        
        foreach ($fieldNames as $fieldName) {
            $tagName = strtoupper($fieldName);
            $target = $targets[$fieldName] ?? 200;
            
            $format .= "[{$tagName}]\n";
            $format .= "(scrivi qui il contenuto - TARGET: {$target} caratteri)\n";
            $format .= "[/{$tagName}]\n\n";
        }
        
        $format .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $format .= "⚠️ CHECKLIST FINALE PRIMA DI INVIARE:\n";
        $format .= "☐ Ogni BULLET ha almeno 180 caratteri\n";
        $format .= "☐ DESCRIPTION ha almeno 800 caratteri (senza HTML)\n";
        $format .= "☐ KEYWORDS ha almeno 150 caratteri\n";
        $format .= "☐ TITLE tra 150-200 caratteri\n";
        $format .= "☐ Tutti i campi COMPLETI e ben formattati\n";
        $format .= "☐ NO domande all'utente (VIETATO)\n";
        $format .= "☐ NO testo fuori dai tag\n\n";
        
        $format .= "INIZIA ORA LA GENERAZIONE:\n";
        
        return $format;
    }
}

