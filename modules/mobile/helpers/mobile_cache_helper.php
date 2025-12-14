<?php
/**
 * Mobile Cache Helper
 * Sistema di cache con TTL per ottimizzare caricamento pagine mobile
 */

/**
 * Recupera dati dalla cache se validi
 * 
 * @param int $userId ID utente
 * @param string $cacheType Tipo cache (es: 'margins', 'inventory', 'orders')
 * @param int $ttl Time To Live in secondi (default: 1800 = 30 minuti)
 * @return array|null Dati dalla cache o null se cache invalida/assente
 */
function getMobileCache($userId, $cacheType, $ttl = 1800) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT cache_data, created_at 
            FROM mobile_cache 
            WHERE user_id = ? AND cache_type = ? 
            LIMIT 1
        ");
        $stmt->execute([$userId, $cacheType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null; // Cache non esiste
        }
        
        // Verifica TTL
        $cacheAge = time() - strtotime($row['created_at']);
        if ($cacheAge > $ttl) {
            // Cache scaduta, elimina
            invalidateMobileCache($userId, $cacheType);
            return null;
        }
        
        // Cache valida, deserializza
        $data = json_decode($row['cache_data'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Cache JSON decode error per user $userId, type $cacheType: " . json_last_error_msg());
            return null;
        }
        
        return $data;
        
    } catch (Exception $e) {
        error_log("getMobileCache error: " . $e->getMessage());
        return null; // In caso errore, ignora cache
    }
}

/**
 * Salva dati in cache
 * 
 * @param int $userId ID utente
 * @param string $cacheType Tipo cache
 * @param array $data Dati da cachare
 * @return bool Successo operazione
 */
function setMobileCache($userId, $cacheType, $data) {
    try {
        $pdo = getDbConnection();
        
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Cache JSON encode error: " . json_last_error_msg());
            return false;
        }
        
        // Usa REPLACE per aggiornare o inserire
        $stmt = $pdo->prepare("
            REPLACE INTO mobile_cache (user_id, cache_type, cache_data, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        
        return $stmt->execute([$userId, $cacheType, $jsonData]);
        
    } catch (Exception $e) {
        error_log("setMobileCache error: " . $e->getMessage());
        return false;
    }
}

/**
 * Invalida cache per utente e tipo specifico
 * 
 * @param int $userId ID utente
 * @param string $cacheType Tipo cache da invalidare (opzionale, se null invalida tutto)
 * @return bool Successo operazione
 */
function invalidateMobileCache($userId, $cacheType = null) {
    try {
        $pdo = getDbConnection();
        
        if ($cacheType) {
            // Invalida solo tipo specifico
            $stmt = $pdo->prepare("DELETE FROM mobile_cache WHERE user_id = ? AND cache_type = ?");
            return $stmt->execute([$userId, $cacheType]);
        } else {
            // Invalida tutta la cache utente
            $stmt = $pdo->prepare("DELETE FROM mobile_cache WHERE user_id = ?");
            return $stmt->execute([$userId]);
        }
        
    } catch (Exception $e) {
        error_log("invalidateMobileCache error: " . $e->getMessage());
        return false;
    }
}

/**
 * Pulisce cache scaduta da tutto il database (usare in cron)
 * 
 * @param int $maxAge Età massima in secondi (default: 3600 = 1 ora)
 * @return int Numero righe eliminate
 */
function cleanupExpiredCache($maxAge = 3600) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            DELETE FROM mobile_cache 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$maxAge]);
        
        return $stmt->rowCount();
        
    } catch (Exception $e) {
        error_log("cleanupExpiredCache error: " . $e->getMessage());
        return 0;
    }
}

