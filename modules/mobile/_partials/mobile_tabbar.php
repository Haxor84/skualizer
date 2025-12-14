    </main>
    <!-- Main Content End -->
    
    <!-- Mobile Tabbar -->
    <nav class="mobile-tabbar" role="navigation" aria-label="Navigazione principale">
        <a href="/modules/mobile/Margynomic.php" class="tabbar-item" aria-label="Margini">
            <i class="fas fa-chart-line tabbar-icon"></i>
            <span class="tabbar-label">Margynomic</span>
        </a>
        
        <a href="/modules/mobile/Previsync.php" class="tabbar-item" aria-label="PreviSync">
            <i class="fas fa-boxes tabbar-icon"></i>
            <span class="tabbar-label">PreviSync</span>
        </a>
        
        <a href="/modules/mobile/OrderInsights.php" class="tabbar-item" aria-label="Ordini">
            <i class="fas fa-microscope tabbar-icon"></i>
            <span class="tabbar-label">OrderInsight</span>
        </a>

        <a href="/modules/mobile/TridScanner.php" class="tabbar-item" aria-label="TRID">
            <i class="fas fa-search tabbar-icon"></i>
            <span class="tabbar-label">TridScanner</span>
        </a>

        <a href="/modules/mobile/EasyShip.php" class="tabbar-item" aria-label="EasyShip">
            <i class="fas fa-truck tabbar-icon"></i>
            <span class="tabbar-label">EasyShip</span>
        </a>

        <a href="/modules/mobile/Rendiconto.php" class="tabbar-item" aria-label="Rendiconto">
            <i class="fas fa-file-invoice-dollar tabbar-icon"></i>
            <span class="tabbar-label">Economics</span>
        </a>
        
    </nav>
    
    <script src="/modules/mobile/assets/mobile.js"></script>
    
    <!-- iOS Address Bar Hide -->
    <script>
    window.addEventListener('load', () => {
        setTimeout(() => window.scrollTo(0, 1), 100);
    });
    
    window.addEventListener('orientationchange', () => {
        setTimeout(() => window.scrollTo(0, 1), 100);
    });
    </script>
</body>
</html>

