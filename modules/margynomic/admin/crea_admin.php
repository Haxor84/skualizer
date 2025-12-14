<?php

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();

    $email = 'info@skualizer.com';
    $password = 'Andreah19';
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $nome = 'Admin';
    $ruolo = 'admin';
    $creato_il = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, nome, ruolo, creato_il) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$email, $password_hash, $nome, $ruolo, $creato_il]);

    echo "✅ Admin creato con successo.";
} catch (PDOException $e) {
    echo "❌ Errore durante la creazione dell'admin: " . $e->getMessage();
}
