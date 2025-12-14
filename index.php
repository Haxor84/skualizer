<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Margynomic - Massimizza i tuoi profitti su Amazon con l'AI dei margini</title>
    <meta name="description" content="Analizza i margini reali delle tue vendite Amazon con i report ufficiali SP-API. Prova gratuita 7 giorni, poi 19,99€/mese.">
    <meta name="keywords" content="Amazon, margini, profitti, SP-API, venditori, analisi, fee">
    
    <!-- CSS -->
    <link rel="stylesheet" href="/modules/margynomic/css/margynomic.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <!-- Open Graph per social sharing -->
    <meta property="og:title" content="Margynomic - Massimizza i tuoi profitti su Amazon">
    <meta property="og:description" content="Analizza i margini reali delle tue vendite Amazon con i report ufficiali SP-API">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.skualizer.com">
    
    <style>
        /* Stili aggiuntivi per la landing page */
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 80px 0 60px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
            opacity: 0.3;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }
        
        .hero-subtitle {
            font-size: 1.4rem;
            margin-bottom: 2.5rem;
            opacity: 0.95;
            font-weight: 300;
        }
        
        .cta-button {
            display: inline-block;
            background: #ff6b35;
            color: white;
            padding: 18px 40px;
            font-size: 1.3rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(255, 107, 53, 0.3);
            margin-bottom: 1rem;
        }
        
        .cta-button:hover {
            background: #e55a2b;
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(255, 107, 53, 0.4);
            color: white;
            text-decoration: none;
        }
        
        .login-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-size: 1.1rem;
            transition: color 0.3s ease;
        }
        
        .login-link:hover {
            color: white;
            text-decoration: underline;
        }
        
        .security-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.15);
            padding: 12px 20px;
            border-radius: 25px;
            margin-top: 2rem;
            font-size: 0.95rem;
            backdrop-filter: blur(10px);
        }
        
        .security-badge::before {
            content: "🔒";
            margin-right: 8px;
            font-size: 1.1rem;
        }
        
        .section {
            padding: 80px 0;
        }
        
        .section-title {
            text-align: center;
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: #2c3e50;
        }
        
        .section-subtitle {
            text-align: center;
            font-size: 1.3rem;
            color: #7f8c8d;
            margin-bottom: 4rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 3rem;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .step-card {
            text-align: center;
            padding: 2.5rem 1.5rem;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .step-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .step-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
            color: white;
            font-weight: bold;
        }
        
        .step-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #2c3e50;
        }
        
        .step-description {
            color: #7f8c8d;
            line-height: 1.6;
        }
        
        .testimonial-section {
            background: #f8f9fa;
            padding: 80px 0;
        }
        
        .testimonial-card {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 3rem;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            text-align: center;
            position: relative;
        }
        
        .testimonial-card::before {
            content: '"';
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 6rem;
            color: #667eea;
            font-family: serif;
            line-height: 1;
        }
        
        .testimonial-text {
            font-size: 1.3rem;
            font-style: italic;
            color: #2c3e50;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .testimonial-author {
            font-weight: 600;
            color: #667eea;
            font-size: 1.1rem;
        }
        
        .pricing-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        
        .pricing-card {
            max-width: 500px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.15);
            padding: 3rem;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .price-text {
            font-size: 1.4rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .price-highlight {
            font-size: 2.5rem;
            font-weight: 700;
            color: #ff6b35;
            display: block;
            margin: 1rem 0;
        }
        
        .footer {
            background: #2c3e50;
            color: white;
            padding: 60px 0 40px;
            text-align: center;
        }
        
        .footer-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .footer-contact {
            font-size: 1.2rem;
            margin-bottom: 2rem;
        }
        
        .footer-contact a {
            color: #ff6b35;
            text-decoration: none;
        }
        
        .footer-contact a:hover {
            text-decoration: underline;
        }
        
        .footer-disclaimer {
            font-size: 0.95rem;
            opacity: 0.8;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header con logo e menu */
        .header {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 15px 0;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: 700;
            color: #667eea;
            text-decoration: none;
        }
        
        .nav-menu {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .nav-link {
            color: #2c3e50;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .nav-link:hover {
            color: #667eea;
        }
        
        .hamburger {
            display: none;
            flex-direction: column;
            cursor: pointer;
            gap: 4px;
        }
        
        .hamburger span {
            width: 25px;
            height: 3px;
            background: #2c3e50;
            transition: 0.3s;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-subtitle {
                font-size: 1.2rem;
            }
            
            .section-title {
                font-size: 2.2rem;
            }
            
            .steps-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .nav-menu {
                display: none;
            }
            
            .hamburger {
                display: flex;
            }
            
            .testimonial-card {
                padding: 2rem;
            }
            
            .pricing-card {
                padding: 2rem;
            }
        }
        
        /* Animazioni */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.8s ease-out;
        }
        
        /* Offset per header fisso */
        body {
            padding-top: 80px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="/" class="logo">SkuAlizer</a>
            <nav class="nav-menu">
                <a href="#come-funziona" class="nav-link">Come funziona</a>
                <a href="#prezzi" class="nav-link">Prezzi</a>
                <a href="/modules/margynomic/login/login.php" class="nav-link">Accedi</a>
            </nav>
            <div class="hamburger">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-content fade-in-up">
            <h1 class="hero-title">Massimizza i tuoi profitti su Amazon con l'AI dei margini</h1>
            <p class="hero-subtitle">Scarica i tuoi report ufficiali, calcola i margini netti, smetti di perdere soldi.</p>
            
            <div>
                <a href="/modules/margynomic/login/register.php" class="cta-button">Inizia la prova gratuita</a>
                <div style="margin-top: 1rem;">
                    <a href="/modules/margynomic/login/login.php" class="login-link">Hai già un account? Accedi</a>
                </div>
            </div>
            
            <div class="security-badge">
                100% Sicuro – Accesso con autorizzazione Amazon SP-API
            </div>
        </div>
    </section>

    <!-- Come Funziona -->
    <section id="come-funziona" class="section">
        <div class="container">
            <h2 class="section-title">Come funziona</h2>
            <p class="section-subtitle">Tre semplici passaggi per iniziare a massimizzare i tuoi profitti</p>
            
            <div class="steps-grid">
                <div class="step-card fade-in-up">
                    <div class="step-icon">1</div>
                    <h3 class="step-title">Autorizza il tuo account Amazon</h3>
                    <p class="step-description">Connetti il tuo account venditore Amazon in modo sicuro tramite le API ufficiali SP-API. I tuoi dati rimangono sempre protetti.</p>
                </div>
                
                <div class="step-card fade-in-up">
                    <div class="step-icon">2</div>
                    <h3 class="step-title">Analizziamo i tuoi report in automatico</h3>
                    <p class="step-description">La nostra AI scarica e analizza automaticamente i tuoi report di settlement, calcolando fee, costi e margini reali per ogni prodotto.</p>
                </div>
                
                <div class="step-card fade-in-up">
                    <div class="step-icon">3</div>
                    <h3 class="step-title">Visualizzi margini e utile netto in tempo reale</h3>
                    <p class="step-description">Dashboard intuitiva con grafici dettagliati, analisi dei trend e insights actionable per ottimizzare la tua strategia di vendita.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonianza -->
    <section class="testimonial-section">
        <div class="container">
            <div class="testimonial-card fade-in-up">
                <p class="testimonial-text">In 3 giorni ho capito dove stavo perdendo soldi su Amazon. Margynomic mi ha fatto scoprire che alcuni prodotti che credevo profittevoli in realtà mi stavano facendo perdere denaro. Consigliatissimo!</p>
                <div class="testimonial-author">— Marco R., Venditore Amazon da 4 anni</div>
            </div>
        </div>
    </section>

    <!-- Prezzi -->
    <section id="prezzi" class="pricing-section">
        <div class="container">
            <h2 class="section-title" style="color: white;">Prezzi trasparenti</h2>
            <div class="pricing-card fade-in-up">
                <div class="price-text">
                    Provalo gratis per <strong>7 giorni</strong>.<br>
                    Poi solo <span class="price-highlight">19,99€/mese</span>
                    Nessun vincolo, puoi cancellare quando vuoi.
                </div>
                <a href="/modules/margynomic/login/register.php" class="cta-button">Inizia la prova gratuita</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-contact">
                <p>Hai domande? Contattaci: <a href="mailto:info@skualizer.com">info@skualizer.com</a></p>
            </div>
            <div class="footer-disclaimer">
                <p><strong>Disclaimer:</strong> Skualizer è una piattaforma indipendente, non affiliata ad Amazon. Amazon e il logo Amazon sono marchi registrati di Amazon.com, Inc. o delle sue affiliate.</p>
                <p style="margin-top: 1rem; opacity: 0.7;">© 2024 Skualizer. Tutti i diritti riservati.</p>
            </div>
        </div>
    </footer>

    <!-- JavaScript per interazioni -->
    <script>
        // Smooth scrolling per i link di navigazione
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Hamburger menu toggle (per mobile)
        const hamburger = document.querySelector('.hamburger');
        const navMenu = document.querySelector('.nav-menu');
        
        hamburger.addEventListener('click', () => {
            navMenu.style.display = navMenu.style.display === 'flex' ? 'none' : 'flex';
        });

        // Animazioni on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Osserva tutti gli elementi con animazione
        document.querySelectorAll('.step-card, .testimonial-card, .pricing-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
            observer.observe(el);
        });

        // Effetto parallax leggero per l'hero
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            const heroSection = document.querySelector('.hero-section');
            if (heroSection) {
                heroSection.style.transform = `translateY(${scrolled * 0.5}px)`;
            }
        });
    </script>
</body>
</html>

