<?php
/**
 * PolicyManager
 * Gestisce caricamento e parsing amazon_policy.json
 */
class PolicyManager
{
    private $policies = null;
    private $policyFile;
    
    /**
     * Mapping nomi campi Excel → nomi policy JSON
     */
    private $fieldMapping = [
        'item_name' => 'title',
        'product_description' => 'description',
        'bullet_point1' => 'bullet_point',
        'bullet_point2' => 'bullet_point',
        'bullet_point3' => 'bullet_point',
        'bullet_point4' => 'bullet_point',
        'bullet_point5' => 'bullet_point',
        'generic_keywords' => 'keywords',
        'external_product_id' => 'ean',
        'item_sku' => 'sku',
        'brand_name' => 'brand',
        'manufacturer' => 'manufacturer',
        'standard_price' => 'price',
        'quantity' => 'quantity',
        'unit_count' => 'weight',
        'country_of_origin' => 'country_origin'
    ];

    public function __construct($policyFilePath)
    {
        $this->policyFile = $policyFilePath;
    }

    /**
     * Carica tutte le policy dal JSON
     */
    private function loadPolicies()
    {
        if ($this->policies !== null) {
            return;
        }

        if (!file_exists($this->policyFile)) {
            throw new Exception("Policy file not found: {$this->policyFile}");
        }

        $content = file_get_contents($this->policyFile);
        $this->policies = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in policy file: " . json_last_error_msg());
        }
    }

    /**
     * Ottieni policy per campo specifico
     * 
     * @param string $fieldName Nome campo Excel (es. 'item_name')
     * @return array|null Policy del campo
     */
    public function getPolicyForField($fieldName)
    {
        $this->loadPolicies();
        
        // Mappa nome campo Excel → nome policy JSON
        $policyFieldName = $this->fieldMapping[$fieldName] ?? $fieldName;
        
        $policy = $this->policies['fields'][$policyFieldName] ?? null;
        
        // Aggiungi fieldName originale per riferimento
        if ($policy) {
            $policy['fieldName'] = $fieldName;
        }
        
        return $policy;
    }

    /**
     * Ottieni tutte le policy
     */
    public function getAllPolicies()
    {
        $this->loadPolicies();
        return $this->policies;
    }

    /**
     * Verifica se campo ha policy definite
     */
    public function hasPolicy($fieldName)
    {
        return $this->getPolicyForField($fieldName) !== null;
    }

    /**
     * Ottieni limiti caratteri per campo
     */
    public function getCharLimits($fieldName)
    {
        $policy = $this->getPolicyForField($fieldName);
        
        return [
            'min' => $policy['min_length'] ?? null,
            'max' => $policy['max_length'] ?? null
        ];
    }

    /**
     * Ottieni parole vietate globali
     */
    public function getForbiddenWords()
    {
        $this->loadPolicies();
        return $this->policies['global']['forbidden_words'] ?? [];
    }

    /**
     * Ottieni struttura raccomandata per campo
     */
    public function getRecommendedStructure($fieldName)
    {
        $policy = $this->getPolicyForField($fieldName);
        return $policy['recommended_structure'] ?? null;
    }

    /**
     * Ottieni raccomandazioni per campo
     */
    public function getRecommendations($fieldName)
    {
        $policy = $this->getPolicyForField($fieldName);
        return $policy['recommendations'] ?? [];
    }
    
    /**
     * Ottieni mapping completo campi Excel → policy JSON
     */
    public function getFieldMapping()
    {
        return $this->fieldMapping;
    }
    
    /**
     * Converti nome campo Excel in nome policy
     */
    public function mapFieldName($fieldName)
    {
        return $this->fieldMapping[$fieldName] ?? $fieldName;
    }
}

