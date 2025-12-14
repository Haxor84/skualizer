<?php

/**
 * UserModel - Gestione utenti per Margynomic
 * File: models/UserModel.php
 */

class UserModel {
    private $pdo;
    
    public function __construct() {
    if (!function_exists('getDbConnection')) {
    require_once __DIR__ . '/../config/config.php';
    }
    $this->pdo = getDbConnection();
}
    
    /**
     * Trova utente per email
     */
    public function findByEmail($email) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, email, password_hash, nome, role, ruolo, is_active, expiry_date, scadenza, creato_il
                FROM users 
                WHERE email = ? AND is_active = 1
            ");
            $stmt->execute([$email]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Errore findByEmail: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Trova utente per ID
     */
    public function findById($id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, email, password_hash, nome, role, ruolo, is_active, expiry_date, scadenza, creato_il
                FROM users 
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Errore findById: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Crea nuovo utente
     */
    public function createUser($data) {
        try {
            // Verifica se email già esiste
            if ($this->emailExists($data['email'])) {
                return ['success' => false, 'message' => 'Email già registrata'];
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO users (email, password_hash, nome, role, ruolo, is_active, creato_il) 
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $result = $stmt->execute([
                $data['email'],
                $data['password_hash'],
                $data['nome'],
                $data['role'] ?? 'seller',
                $data['ruolo'] ?? 'user'
            ]);
            
            if ($result) {
                $userId = $this->pdo->lastInsertId();
                return [
                    'success' => true, 
                    'user_id' => $userId,
                    'message' => 'Utente creato con successo'
                ];
            }
            
            return ['success' => false, 'message' => 'Errore nella creazione utente'];
            
        } catch (PDOException $e) {
            error_log("Errore createUser: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore del database'];
        }
    }
    
    /**
     * Verifica se email esiste già
     */
    public function emailExists($email) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Errore emailExists: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Valida credenziali utente
     */
    public function validateCredentials($email, $password) {
        $user = $this->findByEmail($email);
        
        if (!$user) {
            return ['success' => false, 'message' => 'Credenziali non valide'];
        }
        
        // Verifica se account è scaduto
        if ($user['expiry_date'] && strtotime($user['expiry_date']) < time()) {
            return ['success' => false, 'message' => 'Account scaduto'];
        }
        
        if ($user['scadenza'] && strtotime($user['scadenza']) < time()) {
            return ['success' => false, 'message' => 'Account scaduto'];
        }
        
        // Verifica password
        if (!verifyPassword($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Credenziali non valide'];
        }
        
        // Rimuovi password_hash dai dati restituiti
        unset($user['password_hash']);
        
        return [
            'success' => true, 
            'user' => $user,
            'message' => 'Login effettuato con successo'
        ];
    }
    
    /**
     * Aggiorna password utente
     */
    public function updatePasswordHash($userId, $newPasswordHash) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET password_hash = ? 
                WHERE id = ?
            ");
            
            return $stmt->execute([$newPasswordHash, $userId]);
            
        } catch (PDOException $e) {
            error_log("Errore updatePasswordHash: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Salva token di reset password
     */
    public function storeResetToken($email, $token) {
        try {
            // Prima elimina eventuali token esistenti per questa email
            $this->cleanupResetTokens($email);
            
            $expiresAt = date('Y-m-d H:i:s', time() + RESET_TOKEN_EXPIRY);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO password_resets (email, token, expires_at) 
                VALUES (?, ?, ?)
            ");
            
            return $stmt->execute([$email, $token, $expiresAt]);
            
        } catch (PDOException $e) {
            error_log("Errore storeResetToken: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica token di reset password
     */
    public function verifyResetToken($token) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT email, expires_at, used 
                FROM password_resets 
                WHERE token = ? AND used = 0
            ");
            $stmt->execute([$token]);
            $result = $stmt->fetch();
            
            if (!$result) {
                return ['success' => false, 'message' => 'Token non valido'];
            }
            
            // Verifica se token è scaduto
            if (strtotime($result['expires_at']) < time()) {
                return ['success' => false, 'message' => 'Token scaduto'];
            }
            
            return [
                'success' => true, 
                'email' => $result['email'],
                'message' => 'Token valido'
            ];
            
        } catch (PDOException $e) {
            error_log("Errore verifyResetToken: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore del database'];
        }
    }
    
    /**
     * Marca token come utilizzato
     */
    public function markTokenAsUsed($token) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE password_resets 
                SET used = 1 
                WHERE token = ?
            ");
            
            return $stmt->execute([$token]);
            
        } catch (PDOException $e) {
            error_log("Errore markTokenAsUsed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Pulisci token di reset scaduti o usati
     */
    public function cleanupResetTokens($email = null) {
        try {
            if ($email) {
                // Pulisci token per email specifica
                $stmt = $this->pdo->prepare("
                    DELETE FROM password_resets 
                    WHERE email = ? AND (used = 1 OR expires_at < NOW())
                ");
                $stmt->execute([$email]);
            } else {
                // Pulisci tutti i token scaduti o usati
                $stmt = $this->pdo->prepare("
                    DELETE FROM password_resets 
                    WHERE used = 1 OR expires_at < NOW()
                ");
                $stmt->execute();
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Errore cleanupResetTokens: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna ultimo accesso utente
     */
    public function updateLastLogin($userId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET last_login = NOW() 
                WHERE id = ?
            ");
            
            return $stmt->execute([$userId]);
            
        } catch (PDOException $e) {
            error_log("Errore updateLastLogin: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ottieni statistiche utente
     */
    public function getUserStats($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    u.*,
                    (SELECT COUNT(*) FROM login_attempts WHERE email = u.email AND success = 1) as total_logins,
                    (SELECT attempted_at FROM login_attempts WHERE email = u.email AND success = 1 ORDER BY attempted_at DESC LIMIT 1) as last_login_attempt
                FROM users u 
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Errore getUserStats: " . $e->getMessage());
            return false;
        }
    }
    
    /**
 * Pulisci tentativi di login falliti per sbloccare account
 */
public function clearLoginAttempts($email) {
    try {
        $stmt = $this->pdo->prepare("
            DELETE FROM login_attempts 
            WHERE email = ? AND success = 0
        ");
        return $stmt->execute([$email]);
    } catch (PDOException $e) {
        error_log("Errore clearLoginAttempts: " . $e->getMessage());
        return false;
    }
}

    /**
     * Aggiorna profilo utente
     */
    public function updateProfile($userId, $data) {
        try {
            $allowedFields = ['nome', 'email'];
            $updateFields = [];
            $values = [];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($updateFields)) {
                return ['success' => false, 'message' => 'Nessun campo da aggiornare'];
            }
            
            // Verifica se nuova email già esiste (se email viene cambiata)
            if (isset($data['email'])) {
                $currentUser = $this->findById($userId);
                if ($currentUser['email'] !== $data['email'] && $this->emailExists($data['email'])) {
                    return ['success' => false, 'message' => 'Email già in uso'];
                }
            }
            
            $values[] = $userId;
            
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            
            if ($stmt->execute($values)) {
                return ['success' => true, 'message' => 'Profilo aggiornato con successo'];
            }
            
            return ['success' => false, 'message' => 'Errore nell\'aggiornamento'];
            
        } catch (PDOException $e) {
            error_log("Errore updateProfile: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore del database'];
        }
    }
}
?>

