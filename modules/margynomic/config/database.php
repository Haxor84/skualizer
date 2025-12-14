<?php
// /config/database.php

class Database {
    private static $instance = null;
    private $conn;

    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset = DB_CHARSET;

    private function __construct() {
        $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Important for security
        ];

        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            // In a real app, log this error instead of echoing
            error_log("Connection Error: " . $e->getMessage()); 
            // Optionally throw an exception or display a user-friendly error page
            // Make sure config.php is included before calling die()
            if (!defined('DEBUG_MODE')) {
                 // Attempt to include config if not already defined, might fail if path is wrong
                 @include_once __DIR__ . '/config.php';
            }
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                 die("Errore di connessione al database: " . $e->getMessage());
            } else {
                 die("Errore di connessione al database. Si prega di riprovare più tardi."); 
            }
        }
    }

    public static function getInstance() {
        if (!self::$instance) {
            // Require config only when needed for the first time
            // Ensure config.php is included before instantiating Database
            if (!defined('DB_HOST')) { // Check if constants are already defined
                 require_once __DIR__ . '/config.php'; 
            }
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {}
}
?>
