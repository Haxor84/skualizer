<?php
// ODG VVF Perugia - Seen list shared storage
// GET  → restituisce seen.json
// POST ?token=SECRET → aggiorna seen.json

define('SECRET', 'odg_vvf_pg_k9x2m7');
define('JSON_FILE', __DIR__ . '/seen.json');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists(JSON_FILE)) {
        echo file_get_contents(JSON_FILE);
    } else {
        echo '[]';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_GET['token'] ?? '') !== SECRET) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $body = file_get_contents('php://input');
    $data = json_decode($body);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON array']);
        exit;
    }
    file_put_contents(JSON_FILE, json_encode($data));
    echo json_encode(['ok' => true, 'count' => count($data)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
