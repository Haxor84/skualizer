<?php
/**
 * Mobile Header Partial
 * Header compatto con logo e menu hamburger
 */

// Determina la pagina attiva per evidenziare il titolo
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$pageTitle = 'Skualizer';

switch ($currentPage) {
    case 'Margynomic':
        $pageTitle = 'Margynomic';
        break;
    case 'Previsync':
        $pageTitle = 'PreviSync';
        break;
    case 'OrderInsights':
        $pageTitle = 'OrderInsights';
        break;
    case 'TridScanner':
        $pageTitle = 'TridScanner';
        break;
    case 'EasyShip':
        $pageTitle = 'EasyShip';
        break;
    case 'Rendiconto':
        $pageTitle = 'Rendiconto';
        break;
    case 'Profilo':
        $pageTitle = 'Profilo';
        break;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#667eea">
    <meta name="format-detection" content="telephone=no">
    <title><?= htmlspecialchars($pageTitle) ?> - Skualizer Mobile</title>
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="/modules/mobile/manifest.json">
    
    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" href="/modules/mobile/pwa/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/modules/mobile/pwa/icon-512.png">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/modules/mobile/assets/mobile.css">
    
    <!-- Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/modules/mobile/sw.js').catch(() => {});
    }
    </script>
</head>
<body>
    <!-- Sprite Icons -->
    <?php readfile(__DIR__ . '/../assets/icons.svg'); ?>
    
    <!-- Mobile Header -->
    <header class="mobile-header">
        <div class="mobile-header-title"><?= htmlspecialchars($pageTitle) ?></div>
        <button class="hamburger-btn" aria-label="Menu">
            <svg class="hamburger-icon">
                <use href="#ico-hamburger"></use>
            </svg>
        </button>
    </header>
    
    <!-- Hamburger Overlay Menu -->
    <div class="hamburger-overlay">
        <nav class="hamburger-menu">
            <div class="hamburger-menu-header">
                <div class="hamburger-menu-title">Menu</div>
            </div>
            <div class="hamburger-menu-nav">
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link">
                    <svg><use href="#ico-easyship"></use></svg>
                    Spedizioni
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link">
                    <svg><use href="#ico-rendiconto"></use></svg>
                    Rendiconto
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link">
                    <svg><use href="#ico-profilo"></use></svg>
                    Profilo
                </a>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link">
                    <svg><use href="#ico-logout"></use></svg>
                    Logout
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content Start -->
    <main class="mobile-content">

