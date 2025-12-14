<?php

class CurrencyConverter {
    private $pdo;
    private $cache = [];
    
    // Mapping fisico currency -> marketplace principale
    private const CURRENCY_TO_MARKETPLACE = [
        'EUR' => 'Amazon.it',
        'GBP' => 'Amazon.co.uk',
        'SEK' => 'Amazon.se',
        'PLN' => 'Amazon.pl',
        'USD' => 'Amazon.com',
        'DKK' => 'Amazon.dk',
        'NOK' => 'Amazon.no'
    ];
    
    // Tassi fallback se DB non disponibile (aggiornare periodicamente)
    private const FALLBACK_RATES = [
        'EUR' => 1.0,
        'GBP' => 1.19,    // 1 GBP = 1.19 EUR
        'SEK' => 0.085,   // 1 SEK = 0.085 EUR
        'PLN' => 0.23,    // 1 PLN = 0.23 EUR
        'USD' => 0.92,    // 1 USD = 0.92 EUR
        'DKK' => 0.134,   // 1 DKK = 0.134 EUR
        'NOK' => 0.084    // 1 NOK = 0.084 EUR
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Converte un importo in EUR
     * 
     * @param float $amount Importo da convertire
     * @param string $currency Valuta originale (es: 'GBP')
     * @param string|null $date Data per tasso storico (future use)
     * @return float Importo convertito in EUR
     */
    public function convertToEur($amount, $currency, $date = null) {
        // Se già in EUR, return immediato
        if ($currency === 'EUR') {
            return floatval($amount);
        }
        
        // Get tasso di cambio
        $rate = $this->getExchangeRate($currency);
        
        return floatval($amount) * $rate;
    }
    
    /**
     * Ottiene il tasso di cambio per una currency
     * 
     * @param string $currency
     * @return float
     */
    private function getExchangeRate($currency) {
        // Check cache
        if (isset($this->cache[$currency])) {
            return $this->cache[$currency];
        }
        
        // Trova marketplace corrispondente
        $marketplace = self::CURRENCY_TO_MARKETPLACE[$currency] ?? null;
        
        if (!$marketplace) {
            error_log("⚠️ Currency sconosciuta: {$currency}, uso fallback");
            return self::FALLBACK_RATES[$currency] ?? 1.0;
        }
        
        try {
            // Query su exchange_rates
            $stmt = $this->pdo->prepare("
                SELECT rate_to_eur 
                FROM exchange_rates 
                WHERE marketplace = ? 
                  AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$marketplace]);
            $rate = $stmt->fetchColumn();
            
            if ($rate !== false) {
                $this->cache[$currency] = floatval($rate);
                return $this->cache[$currency];
            }
            
            // Fallback se non trovato nel DB
            error_log("⚠️ Tasso non trovato in DB per {$marketplace}, uso fallback");
            $fallbackRate = self::FALLBACK_RATES[$currency] ?? 1.0;
            $this->cache[$currency] = $fallbackRate;
            return $fallbackRate;
            
        } catch (Exception $e) {
            error_log("❌ Errore recupero tasso per {$currency}: " . $e->getMessage());
            return self::FALLBACK_RATES[$currency] ?? 1.0;
        }
    }
    
    /**
     * Formatta importo con currency
     * 
     * @param float $amount
     * @param string $currency
     * @return string Es: "1.802,05 GBP"
     */
    public function formatAmount($amount, $currency) {
        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }
    
    /**
     * Formatta con conversione EUR
     * 
     * @param float $amount
     * @param string $currency
     * @return string Es: "1.802,05 GBP (€2.145,67)"
     */
    public function formatWithEur($amount, $currency) {
        if ($currency === 'EUR') {
            return '€' . number_format($amount, 2, ',', '.');
        }
        
        $amountEur = $this->convertToEur($amount, $currency);
        return sprintf(
            "%s (€%s)",
            $this->formatAmount($amount, $currency),
            number_format($amountEur, 2, ',', '.')
        );
    }
}

