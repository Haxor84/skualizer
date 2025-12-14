<?php
/**
 * Cleanup Mobile Cache - Script Cron
 * Elimina cache scaduta oltre 72 ore (safety margin oltre TTL 48h)
 * 
 * Questo rimuove solo cache VERAMENTE vecchie:
 * - TTL cache: 48h
 * - Cleanup: 72h (3 giorni)
 * - Margine: 24h extra per sicurezza
 * 
 * Esegui ogni giorno: 0 4 * * * php /path/to/cleanup_mobile_cache.php
 */

require_once dirname(__DIR__) . '/../margynomic/config/config.php';
require_once dirname(__DIR__) . '/helpers/mobile_cache_helper.php';

// Esegui cleanup - 72h = 259200s (3 giorni)
// Rimuove solo cache più vecchie di 72h (24h oltre il TTL di 48h)
$deletedRows = cleanupExpiredCache(259200); // 259200s = 72 ore

// Log risultato
error_log("Mobile Cache Cleanup: {$deletedRows} righe eliminate");

echo "✅ Cache mobile pulita: {$deletedRows} entry scadute eliminate\n";

