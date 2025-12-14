<?php
// Forza PHP a ricaricare il file modificato
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache resettata\n";
}

// Verifica che il file sia stato aggiornato
$file = __DIR__ . '/OverviewModel.php';
echo "Ultima modifica OverviewModel.php: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
echo "File size: " . filesize($file) . " bytes\n";

// Verifica presenza fix
$content = file_get_contents($file);
$fixes = [
    'FIX #1' => strpos($content, 'FIX #1: Definisci $pt e $pa') !== false,
    'FIX #2' => strpos($content, 'FIX #2: Aggiungi price_amount') !== false,
    'FIX #3' => strpos($content, "'FEE_TAB1' => 'Ricavi Vendite'") !== false,
    'FIX #4' => strpos($content, 'FIX #4: Amazon non popola') !== false
];

echo "\nPresenza FIX nel codice:\n";
foreach ($fixes as $fix => $present) {
    echo "  $fix: " . ($present ? '✅ PRESENTE' : '❌ MANCANTE') . "\n";
}
?>
