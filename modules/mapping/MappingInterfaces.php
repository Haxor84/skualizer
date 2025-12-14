<?php
/**
 * Enterprise Mapping System - Interfaces and Implementations
 * File: /modules/mapping/MappingInterfaces.php
 *
 * Questo file definisce le interfacce per le strategie di mapping e le sorgenti dati,
 * e include le implementazioni concrete di tali interfacce.
 */

// Assicurati che MappingRepository sia disponibile
// require_once __DIR__ . 
'/MappingRepository.php'; // Sarà incluso da MappingService

/**
 * Interfaccia per le strategie di mapping.
 */
interface MappingStrategyInterface
{
    public function getName(): string;
    public function getPriority(): int;
    public function canHandle(string $sku, array $context = []): bool;
    public function executeMapping(int $userId, string $sku, array $context = []): array;
}

/**
 * Interfaccia per le sorgenti dati di mapping.
 */
interface MappingSourceInterface
{
    public function getName(): string;
    public function isAvailable(): bool;
    public function getUnmappedSkus(int $userId, int $limit): array;
    public function updateMapping(int $userId, string $sku, int $productId): bool;
}

// Implementazioni delle Strategie

/**
 * Strategia di mapping per corrispondenza esatta.
 */
class ExactMatchStrategy implements MappingStrategyInterface
{
    private MappingRepository $repository;
    private array $config;

    public function __construct(MappingRepository $repository, array $config)
    {
        $this->repository = $repository;
        $this->config = $config;
    }

    public function getName(): string
    {
        return 'auto_exact';
    }

    public function getPriority(): int
    {
        return $this->config["strategies"]["auto_exact"]["priority"] ?? 1;
    }

    public function canHandle(string $sku, array $context = []): bool
    {
        // Questa strategia può sempre tentare di gestire qualsiasi SKU
        return true;
    }

    public function executeMapping(int $userId, string $sku, array $context = []): array
    {
        // Cerca un prodotto esistente con SKU esatto
        $products = $this->repository->findProducts($userId, $sku, 'sku', 1);
        if (!empty($products)) {
            return [
                'success' => true,
                'product_id' => $products[0]["id"],
                'confidence' => $this->config["strategies"]["auto_exact"]["confidence"] ?? 1.00,
                'metadata' => ['matched_field' => 'sku']
            ];
        }

        // Cerca un prodotto esistente con ASIN esatto (se disponibile nel contesto)
        if (isset($context["asin"]) && !empty($context["asin"])) {
            $products = $this->repository->findProducts($userId, $context["asin"], 'asin', 1);
            if (!empty($products)) {
                return [
                    'success' => true,
                    'product_id' => $products[0]["id"],
                    'confidence' => $this->config["strategies"]["auto_exact"]["confidence"] ?? 1.00,
                    'metadata' => ['matched_field' => 'asin']
                ];
            }
        }

        // Cerca un prodotto esistente con FNSKU esatto (se disponibile nel contesto)
        if (isset($context["fnsku"]) && !empty($context["fnsku"])) {
            $products = $this->repository->findProducts($userId, $context["fnsku"], 'fnsku', 1);
            if (!empty($products)) {
                return [
                    'success' => true,
                    'product_id' => $products[0]["id"],
                    'confidence' => $this->config["strategies"]["auto_exact"]["confidence"] ?? 1.00,
                    'metadata' => ['matched_field' => 'fnsku']
                ];
            }
        }

        return ['success' => false, 'reason' => 'no_exact_match'];
    }
}

/**
 * Strategia di mapping per corrispondenza fuzzy (approssimata).
 */
class FuzzyMatchStrategy implements MappingStrategyInterface
{
    private MappingRepository $repository;
    private array $config;

    public function __construct(MappingRepository $repository, array $config)
    {
        $this->repository = $repository;
        $this->config = $config;
    }

    public function getName(): string
    {
        return 'auto_fuzzy';
    }

    public function getPriority(): int
    {
        return $this->config["strategies"]["auto_fuzzy"]["priority"] ?? 2;
    }

    public function canHandle(string $sku, array $context = []): bool
    {
        // Questa strategia può sempre tentare di gestire qualsiasi SKU
        return true;
    }

public function executeMapping(int $userId, string $sku, array $context = []): array
    {
        // Implementazione semplificata per la corrispondenza fuzzy
        // In un sistema reale, questo potrebbe usare algoritmi come Levenshtein distance o soundex
        // Per ora, cerchiamo prodotti il cui nome o SKU contenga una parte dello SKU dato

        $minConfidence = $this->config["strategies"]["auto_fuzzy"]["confidence"] ?? 0.85;
        $maxResults = $this->config["mapping_max_fuzzy_results"] ?? 5;

        $possibleMatches = [];

        // === LOGICA FUZZY MIGLIORATA ===
        
        // Estrai parole chiave dallo SKU per ricerca intelligente
        $keywords = $this->extractKeywordsFromSku($sku);
        
        // Cerca per SKU parziale
        $productsBySku = $this->repository->findProducts($userId, $sku, 'sku', $maxResults);
        foreach ($productsBySku as $product) {
            $similarity = $this->calculateSimilarity($sku, $product["sku"]);
            if ($similarity >= 0.50) { // Soglia più bassa per includere più candidati
                $possibleMatches[] = [
                    'product_id' => $product["id"],
                    'confidence' => $similarity,
                    'product_name' => $product["nome"],
                    'metadata' => ['matched_field' => 'sku', 'match_type' => 'fuzzy', 'matched_value' => $product["sku"]]
                ];
            }
        }

        // Cerca nei nomi prodotti usando parole chiave estratte dallo SKU
        if (!empty($keywords)) {
            // Cerca per ogni keyword individualmente per massimizzare i match
            foreach ($keywords as $keyword) {
                $productsByKeyword = $this->repository->findProducts($userId, $keyword, 'nome', $maxResults);
                foreach ($productsByKeyword as $product) {
                    $similarity = $this->calculateNameSimilarity($sku, $product["nome"], $keywords);
                    if ($similarity >= 0.50) {
                        $possibleMatches[] = [
                            'product_id' => $product["id"],
                            'confidence' => $similarity,
                            'product_name' => $product["nome"],
                            'metadata' => ['matched_field' => 'nome', 'match_type' => 'fuzzy_single_keyword', 'matched_value' => $product["nome"], 'keyword_used' => $keyword]
                        ];
                    }
                }
            }
        }

        // Cerca anche per nome prodotto dal contesto se disponibile
        if (isset($context["product_name"]) && !empty($context["product_name"])) {
            $productsByContextName = $this->repository->findProducts($userId, $context["product_name"], 'nome', $maxResults);
            foreach ($productsByContextName as $product) {
                $similarity = $this->calculateSimilarity($context["product_name"], $product["nome"]);
                if ($similarity >= 0.50) {
                    $possibleMatches[] = [
                        'product_id' => $product["id"],
                        'confidence' => $similarity,
                        'product_name' => $product["nome"],
                        'metadata' => ['matched_field' => 'nome', 'match_type' => 'fuzzy_context', 'matched_value' => $product["nome"]]
                    ];
                }
            }
        }

        // Rimuovi duplicati per product_id
        $uniqueMatches = [];
        foreach ($possibleMatches as $match) {
            $productId = $match['product_id'];
            if (!isset($uniqueMatches[$productId]) || $uniqueMatches[$productId]['confidence'] < $match['confidence']) {
                $uniqueMatches[$productId] = $match;
            }
        }
        $possibleMatches = array_values($uniqueMatches);

        // Ordina i risultati per confidence decrescente
        usort($possibleMatches, function ($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        // Se non ci sono match sufficienti, restituisce failure
        if (empty($possibleMatches)) {
            return ['success' => false, 'reason' => 'no_fuzzy_match'];
        }

        // Ottieni il miglior match
        $bestMatch = $possibleMatches[0];
        
        // ===== NUOVO SISTEMA APPROVAZIONE FUZZY =====
        
        // Verifica se le approvazioni fuzzy sono abilitate
        if (!($this->config['fuzzy_approval']['enabled'] ?? true)) {
            // Comportamento originale: mappa direttamente
            return array_merge(['success' => true], $bestMatch);
        }
        
        // Controllo soglie per auto-approvazione
        $autoApproveThreshold = $this->config['fuzzy_approval']['auto_approve_threshold'] ?? 0.95;
        $requireApprovalBelow = $this->config['fuzzy_approval']['require_approval_below'] ?? 0.90;
        
        // Se confidence molto alta → auto-approva
        if ($bestMatch['confidence'] >= $autoApproveThreshold) {
            return array_merge(['success' => true], $bestMatch, [
                'metadata' => array_merge($bestMatch['metadata'], ['auto_approved' => true])
            ]);
        }
        
        // Se confidence troppo bassa → auto-rifiuta
        $autoRejectBelow = $this->config['fuzzy_approval']['auto_reject_below'] ?? 0.60;
        if ($bestMatch['confidence'] < $autoRejectBelow) {
            return ['success' => false, 'reason' => 'confidence_too_low'];
        }
        
        // Se confidence media → richiede approvazione manuale
        if ($bestMatch['confidence'] < $requireApprovalBelow) {
            // Salva come pending invece di mappare direttamente
            $pendingResult = $this->repository->savePendingMapping(
                $userId, 
                $sku, 
                $context['source_table'] ?? 'unknown',
                $bestMatch['product_id'],
                $bestMatch['confidence'],
                array_merge($bestMatch['metadata'], [
                    'pending_reason' => 'fuzzy_confidence_requires_approval',
                    'suggested_product_name' => $bestMatch['product_name'] ?? '',
                    'fuzzy_details' => $bestMatch
                ])
            );
            
            if ($pendingResult['success']) {
                return [
                    'success' => false,
                    'reason' => 'pending_approval',
                    'pending_state_id' => $pendingResult['state_id'],
                    'suggested_product_id' => $bestMatch['product_id'],
                    'confidence' => $bestMatch['confidence']
                ];
            } else {
                // Fallback: mappa direttamente se salvataggio pending fallisce
                return array_merge(['success' => true], $bestMatch, [
                    'metadata' => array_merge($bestMatch['metadata'], ['pending_failed' => true])
                ]);
            }
        }
        
        // Default: mappa direttamente (confidence media-alta)
        return array_merge(['success' => true], $bestMatch);
    }

    /**
     * Calcola una semplice somiglianza tra due stringhe (es. percentuale di caratteri comuni).
     * In un'implementazione reale, si userebbero algoritmi più robusti.
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }
        // Esempio molto semplice: percentuale di caratteri comuni o Levenshtein
        $lev = levenshtein($str1, $str2);
        $maxLength = max($len1, $len2);
        return 1.0 - ($lev / $maxLength);
    }

    /**
     * Estrae parole chiave da uno SKU per ricerca intelligente
     */
    public function extractKeywordsFromSku(string $sku): array
    {
        // Rimuovi caratteri speciali e split
        $cleanSku = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $sku);
        $words = preg_split('/\s+/', $cleanSku);
        
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            // Includi solo parole di almeno 3 caratteri e non numeri puri
            if (strlen($word) >= 3 && !is_numeric($word)) {
                $keywords[] = $word;
            }
        }
        
        return array_unique($keywords);
    }

    /**
     * Calcola similarità tra SKU e nome prodotto usando parole chiave
     */
    private function calculateNameSimilarity(string $sku, string $productName, array $keywords): float
    {
        $productNameLower = strtolower($productName);
        $skuLower = strtolower($sku);
        
        // 0. BLOCCO: Verifica compatibilità unità di misura
        $skuUnit = $this->extractUnit($sku);
        $nameUnit = $this->extractUnit($productName);
        if ($skuUnit && $nameUnit && $skuUnit !== $nameUnit) {
            return 0.0; // Unità diverse = no match (es: "120 ml" non può matchare "120 pz")
        }
        
        // 1. Score delle parole chiave
        $matchedKeywords = 0;
        foreach ($keywords as $keyword) {
            if (strpos($productNameLower, strtolower($keyword)) !== false) {
                $matchedKeywords++;
            }
        }
        $keywordScore = empty($keywords) ? 0 : ($matchedKeywords / count($keywords));
        
        // 2. Score numerico - estrae numeri da SKU e nome prodotto
        $skuNumbers = $this->extractNumbers($sku);
        $productNumbers = $this->extractNumbers($productName);
        $numberScore = $this->calculateNumberSimilarity($skuNumbers, $productNumbers);
        
        // 3. Score Levenshtein generale
        $levScore = $this->calculateSimilarity($skuLower, $productNameLower);
        
        // 4. Score finale ponderato: keywords 50%, numeri 30%, similarità generale 20%
        return ($keywordScore * 0.5) + ($numberScore * 0.3) + ($levScore * 0.2);
    }

    /**
     * Estrae numeri da una stringa
     */
    private function extractNumbers(string $text): array
    {
        preg_match_all('/\d+/', $text, $matches);
        return array_map('intval', $matches[0]);
    }

    /**
     * Calcola similarità tra array di numeri
     */
    private function calculateNumberSimilarity(array $numbers1, array $numbers2): float
    {
        if (empty($numbers1) || empty($numbers2)) {
            return 0.0;
        }
        
        $matches = 0;
        foreach ($numbers1 as $num1) {
            if (in_array($num1, $numbers2)) {
                $matches++;
            }
        }
        
        return $matches / max(count($numbers1), count($numbers2));
    }

    /**
     * Estrae unità di misura da una stringa
     * @param string $text
     * @return string|null
     */
    private function extractUnit(string $text): ?string
    {
        if (preg_match('/(\d+)\s*(ml|pz|lt|kg|gr|g|l)/i', $text, $matches)) {
            return strtolower($matches[2]);
        }
        return null;
    }
}

/**
 * Strategia di mapping assistita dall'AI.
 */
class AiAssistedStrategy implements MappingStrategyInterface
{
    private MappingRepository $repository;
    private array $config;

    public function __construct(MappingRepository $repository, array $config)
    {
        $this->repository = $repository;
        $this->config = $config;
    }

    public function getName(): string
    {
        return 'ai_assisted';
    }

    public function getPriority(): int
    {
        return $this->config["strategies"]["ai_assisted"]["priority"] ?? 3;
    }

    public function canHandle(string $sku, array $context = []): bool
    {
        return ($this->config["ai"]["enabled"] ?? false);
    }

    public function executeMapping(int $userId, string $sku, array $context = []): array
    {
        if (!($this->config["ai"]["enabled"] ?? false)) {
            return ['success' => false, 'reason' => 'ai_disabled'];
        }

        // Simulazione di una chiamata a un servizio AI esterno
        // In un'implementazione reale, qui ci sarebbe una chiamata API a un modello AI
        // che suggerisce un product_id o un nuovo nome prodotto basato su SKU e contesto.

        $suggestedProductId = null;
        $aiConfidence = 0.0;
        $aiReason = 'no_ai_suggestion';

        // Esempio: l'AI suggerisce un prodotto esistente o la creazione di uno nuovo
        // Per semplicità, simuliamo che l'AI trovi un match con una certa probabilità
        if (rand(0, 100) < 70) { // 70% di probabilità di un suggerimento AI
            $allProducts = $this->repository->getAllProducts($userId);
            if (!empty($allProducts)) {
                $suggestedProduct = $allProducts[array_rand($allProducts)];
                $suggestedProductId = $suggestedProduct["id"];
                $aiConfidence = (float)rand(75, 90) / 100; // Confidence tra 0.75 e 0.90
                $aiReason = 'ai_matched_existing_product';
            } else if (($this->config["auto_create_products"] ?? false) && ($this->config["allow_product_creation"] ?? false)) {
                // Simuliamo la creazione di un nuovo prodotto suggerito dall'AI
                $newProductName = "Prodotto AI per SKU " . $sku;
                $newProductId = $this->repository->createProduct($userId, $newProductName, $sku);
                if ($newProductId) {
                    $suggestedProductId = $newProductId;
                    $aiConfidence = (float)rand(80, 95) / 100; // Confidence tra 0.80 e 0.95 per la creazione
                    $aiReason = 'ai_created_new_product';
                }
            }
        }

        if ($suggestedProductId && $aiConfidence >= ($this->config["min_confidence"] ?? 0.70)) {
            return [
                'success' => true,
                'product_id' => $suggestedProductId,
                'confidence' => $aiConfidence,
                'metadata' => ['ai_reason' => $aiReason, 'original_sku' => $sku]
            ];
        } else {
            return ['success' => false, 'reason' => $aiReason];
        }
    }
}

// Implementazioni delle Sorgenti

/**
 * Sorgente dati per l'inventario FBA.
 */
class InventoryMappingSource implements MappingSourceInterface
{
    private MappingRepository $repository;

    public function __construct(MappingRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return 'inventory';
    }

    public function isAvailable(): bool
    {
        // In un'applicazione reale, qui si verificherebbe la connessione o la disponibilità della sorgente
        return true;
    }

    public function getUnmappedSkus(int $userId, int $limit): array
    {
        return $this->repository->getUnmappedSkusFromSource($this->getName(), $userId, $limit);
    }

    public function updateMapping(int $userId, string $sku, int $productId): bool
    {
        return $this->repository->updateSourceMapping($this->getName(), $userId, $sku, $productId);
    }
}

/**
 * Sorgente dati per l'inventario FBM.
 */
class FbmMappingSource implements MappingSourceInterface
{
    private MappingRepository $repository;

    public function __construct(MappingRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return 'inventory_fbm';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnmappedSkus(int $userId, int $limit): array
    {
        return $this->repository->getUnmappedSkusFromSource($this->getName(), $userId, $limit);
    }

    public function updateMapping(int $userId, string $sku, int $productId): bool
    {
        return $this->repository->updateSourceMapping($this->getName(), $userId, $sku, $productId);
    }
}

/**
 * Sorgente dati per i report di settlement.
 */
class SettlementMappingSource implements MappingSourceInterface
{
    private MappingRepository $repository;

    public function __construct(MappingRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return 'settlement';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnmappedSkus(int $userId, int $limit): array
    {
        return $this->repository->getUnmappedSkusFromSource($this->getName(), $userId, $limit);
    }

    public function updateMapping(int $userId, string $sku, int $productId): bool
    {
        return $this->repository->updateSourceMapping($this->getName(), $userId, $sku, $productId);
    }
}

/**
 * Sorgente dati per inbound shipments.
 */
class InboundShipmentsMappingSource implements MappingSourceInterface
{
    private MappingRepository $repository;

    public function __construct(MappingRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return 'inbound_shipments';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnmappedSkus(int $userId, int $limit): array
    {
        return $this->repository->getUnmappedSkusFromSource($this->getName(), $userId, $limit);
    }

    public function updateMapping(int $userId, string $sku, int $productId): bool
    {
        return $this->repository->updateSourceMapping($this->getName(), $userId, $sku, $productId);
    }
}

/**
 * Sorgente per i Removal Orders
 */
class RemovalOrdersMappingSource implements MappingSourceInterface
{
    private MappingRepository $repository;

    public function __construct(MappingRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return 'removal_orders';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnmappedSkus(int $userId, int $limit): array
    {
        return $this->repository->getUnmappedSkusFromSource($this->getName(), $userId, $limit);
    }

    public function updateMapping(int $userId, string $sku, int $productId): bool
    {
        return $this->repository->updateSourceMapping($this->getName(), $userId, $sku, $productId);
    }
}

/**
 * Sorgente dati per tracking inventario TRID.
 */
class ShipmentsTridMappingSource implements MappingSourceInterface
{
    private MappingRepository $repository;

    public function __construct(MappingRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getName(): string
    {
        return 'shipments_trid';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getUnmappedSkus(int $userId, int $limit): array
    {
        return $this->repository->getUnmappedSkusFromSource($this->getName(), $userId, $limit);
    }

    public function updateMapping(int $userId, string $sku, int $productId): bool
    {
        return $this->repository->updateSourceMapping($this->getName(), $userId, $sku, $productId);
    }
}
?>

