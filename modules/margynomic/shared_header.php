<?php
/**
 * Header condiviso per tutte le pagine del sistema
 * File: modules/margynomic/shared_header.php
 */

// Determina la pagina corrente per evidenziare il link attivo
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Rileva il modulo corrente e i suoi colori
function getCurrentModuleColors() {
    global $current_page, $current_dir;
    
    // Mappatura dei moduli con i loro colori
    $moduleColors = [
        'margynomic' => ['primary' => '#38a169', 'secondary' => '#48bb78'], // Verde
        'previsync' => ['primary' => '#FF6B35', 'secondary' => '#F7931E'], // Arancione  
        'orderinsights' => ['primary' => '#ffd700', 'secondary' => '#ffed4a'], // Giallo
        'trid' => ['primary' => '#ec4899', 'secondary' => '#f472b6'], // Rosa
        'easyship' => ['primary' => '#dc2626', 'secondary' => '#ef4444'], // Rosso
        'rendiconto' => ['primary' => '#3182ce', 'secondary' => '#2b77cb'], // Blu
        'profilo_utente' => ['primary' => '#667eea', 'secondary' => '#764ba2'], // Viola (default)
    ];
    
    // Rileva il modulo dalla pagina/directory corrente
    if ($current_page === 'margins_overview' || $current_dir === 'margini') {
        return $moduleColors['margynomic'];
    } elseif ($current_page === 'inventory' || $current_dir === 'previsync') {
        return $moduleColors['previsync'];
    } elseif ($current_page === 'overview' || $current_dir === 'orderinsights') {
        return $moduleColors['orderinsights'];
    } elseif ($current_page === 'TridScanner' || $current_dir === 'trid') {
        return $moduleColors['trid'];
    } elseif ($current_page === 'easyship' || $current_dir === 'easyship') {
        return $moduleColors['easyship'];
    } elseif ($current_dir === 'rendiconto') {
        return $moduleColors['rendiconto'];
    } else {
        return $moduleColors['profilo_utente']; // Default viola
    }
}

$moduleColors = getCurrentModuleColors();

// Funzione per determinare se un link è attivo
function isActiveLink($page, $dir = '') {
    global $current_page, $current_dir;
    
    if ($page === 'margins_overview' && $current_page === 'margins_overview') return true;
    if ($page === 'inventory' && $current_page === 'inventory') return true;
    if ($page === 'overview' && $current_dir === 'orderinsights') return true;
    if ($page === 'TridScanner' && $current_page === 'TridScanner') return true;
    if ($page === 'easyship' && $current_dir === 'easyship') return true;
    if ($page === 'rendiconto' && $current_dir === 'rendiconto') return true;
    if ($page === 'profilo_utente' && $current_page === 'profilo_utente') return true;
    
    return false;
}

// Determina i path relativi basandosi sulla posizione corrente
function getRelativePath($target) {
    global $current_dir;
    
    $paths = [
        'margins_overview' => '../margini/margins_overview.php',
        'inventory' => '../previsync/inventory.php', 
        'overview' => '../orderinsights/overview.php',
        'TridScanner' => '../inbound/trid/TridScanner.php',
        'easyship' => '../easyship/easyship.php',
        'rendiconto' => '../rendiconto/index.php',
        'profilo_utente' => '../profilo_utente.php',
        'logo' => '../uploads/img/MARGYNOMIC.PNG'
    ];
    
    // Aggiusta i path basandosi sulla directory corrente
    switch($current_dir) {
        case 'margini':
            $paths['margins_overview'] = 'margins_overview.php';
            $paths['inventory'] = '../../previsync/inventory.php';
            $paths['overview'] = '../../orderinsights/overview.php';
            $paths['TridScanner'] = '../../inbound/trid/TridScanner.php';
            $paths['easyship'] = '../../easyship/easyship.php';
            $paths['rendiconto'] = '../../rendiconto/index.php';
            $paths['profilo_utente'] = '../profilo_utente.php';
            $paths['logo'] = '../uploads/img/MARGYNOMIC.PNG';
            break;
            
        case 'previsync':
            $paths['margins_overview'] = '../margynomic/margini/margins_overview.php';
            $paths['inventory'] = 'inventory.php';
            $paths['overview'] = '../orderinsights/overview.php';
            $paths['TridScanner'] = '../inbound/trid/TridScanner.php';
            $paths['easyship'] = '../easyship/easyship.php';
            $paths['rendiconto'] = '../rendiconto/index.php';
            $paths['profilo_utente'] = '../margynomic/profilo_utente.php';
            $paths['logo'] = '../margynomic/uploads/img/MARGYNOMIC.PNG';
            break;
            
        case 'orderinsights':
            $paths['margins_overview'] = '../margynomic/margini/margins_overview.php';
            $paths['inventory'] = '../previsync/inventory.php';
            $paths['overview'] = 'overview.php';
            $paths['TridScanner'] = '../inbound/trid/TridScanner.php';
            $paths['easyship'] = '../easyship/easyship.php';
            $paths['rendiconto'] = '../rendiconto/index.php';
            $paths['profilo_utente'] = '../margynomic/profilo_utente.php';
            $paths['logo'] = '../margynomic/uploads/img/MARGYNOMIC.PNG';
            break;
            
        case 'rendiconto':
            $paths['margins_overview'] = '../margynomic/margini/margins_overview.php';
            $paths['inventory'] = '../previsync/inventory.php';
            $paths['overview'] = '../orderinsights/overview.php';
            $paths['TridScanner'] = '../inbound/trid/TridScanner.php';
            $paths['easyship'] = '../easyship/easyship.php';
            $paths['rendiconto'] = 'index.php';
            $paths['profilo_utente'] = '../margynomic/profilo_utente.php';
            $paths['logo'] = '../margynomic/uploads/img/MARGYNOMIC.PNG';
            break;
            
        case 'easyship':
            $paths['margins_overview'] = '../margynomic/margini/margins_overview.php';
            $paths['inventory'] = '../previsync/inventory.php';
            $paths['overview'] = '../orderinsights/overview.php';
            $paths['TridScanner'] = '../inbound/trid/TridScanner.php';
            $paths['easyship'] = 'easyship.php';
            $paths['rendiconto'] = '../rendiconto/index.php';
            $paths['profilo_utente'] = '../margynomic/profilo_utente.php';
            $paths['logo'] = '../margynomic/uploads/img/MARGYNOMIC.PNG';
            break;
            
        case 'trid':
            $paths['margins_overview'] = '../../margynomic/margini/margins_overview.php';
            $paths['inventory'] = '../../previsync/inventory.php';
            $paths['overview'] = '../../orderinsights/overview.php';
            $paths['TridScanner'] = 'TridScanner.php';
            $paths['easyship'] = '../../easyship/easyship.php';
            $paths['rendiconto'] = '../../rendiconto/index.php';
            $paths['profilo_utente'] = '../../margynomic/profilo_utente.php';
            $paths['logo'] = '../../margynomic/uploads/img/MARGYNOMIC.PNG';
            break;
            
        case 'margynomic':
        default:
            // Path per profilo_utente.php e altre pagine nella directory margynomic
            $paths['margins_overview'] = 'margini/margins_overview.php';
            $paths['inventory'] = '../previsync/inventory.php';
            $paths['overview'] = '../orderinsights/overview.php';
            $paths['TridScanner'] = '../inbound/trid/TridScanner.php';
            $paths['easyship'] = '../easyship/easyship.php';
            $paths['rendiconto'] = '../rendiconto/index.php';
            $paths['profilo_utente'] = 'profilo_utente.php';
            $paths['logo'] = 'uploads/img/MARGYNOMIC.PNG';
            break;
    }
    
    return $paths[$target] ?? '#';
}
?>

<style>
/* === DYNAMIC THEME COLORS === */
:root {
    --theme-primary: <?php echo $moduleColors['primary']; ?>;
    --theme-secondary: <?php echo $moduleColors['secondary']; ?>;
}

/* === SHARED HEADER STYLES === */
.dashboard-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    padding: 1rem 2rem;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
}

.header-content {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-logo {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-logo img {
    height: 50px;
    filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
}

.header-title {
    font-size: 1.5rem;
    font-weight: 700;
    background: linear-gradient(135deg, var(--theme-primary), var(--theme-secondary));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.header-nav {
    display: flex;
    gap: 0.5rem;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    text-decoration: none;
    color: #4a5568;
    border-radius: 12px;
    transition: all 0.3s ease;
    font-weight: 500;
    position: relative;
    overflow: hidden;
}

.nav-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    transition: left 0.6s;
}

.nav-link:hover::before {
    left: 100%;
}

.nav-link:hover {
    background: linear-gradient(135deg, var(--theme-primary), var(--theme-secondary));
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
}

.nav-link.active {
    background: linear-gradient(135deg, var(--theme-primary), var(--theme-secondary));
    color: white;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
}

/* Responsive */
@media (max-width: 768px) {
    .header-nav {
        display: none;
    }
    
    .header-content {
        justify-content: center;
    }
}
</style>

<!-- Header Condiviso -->
<!-- DEBUG: Modulo rilevato - Page: <?php echo $current_page; ?>, Dir: <?php echo $current_dir; ?>, Colori: <?php echo $moduleColors['primary']; ?> / <?php echo $moduleColors['secondary']; ?> -->
<header class="dashboard-header">
    <div class="header-content">
        <div class="header-logo">
            <img src="<?php echo getRelativePath('logo'); ?>" alt="Margynomic" onerror="this.style.display='none'">
            <div class="header-title"></div>
        </div>
        <nav class="header-nav">
            <a href="<?php echo getRelativePath('margins_overview'); ?>" class="nav-link <?php echo isActiveLink('margins_overview') ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> Margynomic
            </a>
            <a href="<?php echo getRelativePath('inventory'); ?>" class="nav-link <?php echo isActiveLink('inventory') ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i> Previsync
            </a>
            <a href="<?php echo getRelativePath('overview'); ?>" class="nav-link <?php echo isActiveLink('overview') ? 'active' : ''; ?>">
                <i class="fas fa-microscope"></i> OrderInsights
            </a>
            <a href="<?php echo getRelativePath('TridScanner'); ?>" class="nav-link <?php echo isActiveLink('TridScanner') ? 'active' : ''; ?>">
                <i class="fas fa-search"></i> TridScanner
            </a>
            <a href="<?php echo getRelativePath('easyship'); ?>" class="nav-link <?php echo isActiveLink('easyship') ? 'active' : ''; ?>">
                <i class="fas fa-truck"></i> EasyShip
            </a>
            <a href="<?php echo getRelativePath('rendiconto'); ?>" class="nav-link <?php echo isActiveLink('rendiconto') ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar"></i> Economics
            </a>
            <a href="<?php echo getRelativePath('profilo_utente'); ?>" class="nav-link <?php echo isActiveLink('profilo_utente') ? 'active' : ''; ?>">
                <i class="fas fa-user"></i> Profilo
            </a>
            <a href="javascript:void(0)" onclick="doLogout()" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>
</header>

<script>
// Funzione logout condivisa
function doLogout() {
    if (confirm('Sei sicuro di voler effettuare il logout?')) {
        // Determina il path corretto per logout basandosi sulla directory corrente
        let logoutPath = '../margynomic/login/logout.php';
        
        <?php if ($current_dir === 'margynomic'): ?>
        logoutPath = 'login/logout.php';
        <?php elseif ($current_dir === 'margini'): ?>
        logoutPath = '../login/logout.php';
        <?php endif; ?>
        
        window.location.href = logoutPath;
    }
}
</script> 