<?php
/**
 * Template Email HTML - PreviSync Notifications
 * File: modules/previsync/email_template.php
 * 
 * Template professionale per email automatiche rifornimenti
 * Variabili disponibili: $userName
 */

$currentDate = date('d/m/Y');
$currentDay = date('l, d F Y');

return '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Rifornimenti PreviSync</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            color: #2d3748;
            line-height: 1.6;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .header {
    background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
    color: white;
    padding: 2rem;
    text-align: center;
}
        .logo {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .tagline {
            font-size: 1rem;
            opacity: 0.9;
            margin: 0;
        }
        .content {
            padding: 2rem;
        }
        .welcome {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            color: #4a5568;
        }
        .highlight-box {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin: 1.5rem 0;
            text-align: center;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }
        .highlight-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
        }
        .highlight-subtitle {
            font-size: 0.95rem;
            opacity: 0.95;
            margin: 0;
        }
        .info-section {
            background-color: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 1rem 1.5rem;
            margin: 1.5rem 0;
            border-radius: 0 8px 8px 0;
        }
        .info-title {
            font-weight: 600;
            color: #2d3748;
            margin: 0 0 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .info-text {
            margin: 0;
            color: #4a5568;
            font-size: 0.95rem;
        }
        .features-grid {
            display: table;
            width: 100%;
            margin: 1.5rem 0;
        }
        .feature {
            display: table-row;
        }
        .feature-icon, .feature-text {
            display: table-cell;
            padding: 0.75rem 0;
            vertical-align: top;
        }
        .feature-icon {
            width: 40px;
            font-size: 1.2rem;
            color: #667eea;
        }
        .feature-text {
            color: #4a5568;
            font-size: 0.95rem;
        }
        .cta-section {
            text-align: center;
            margin: 2rem 0;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            transition: transform 0.2s ease;
        }
        .footer {
            background-color: #2d3748;
            color: #a0aec0;
            padding: 1.5rem;
            text-align: center;
            font-size: 0.875rem;
        }
        .footer-logo {
            color: #ffffff;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .footer-text {
            margin: 0.25rem 0;
        }
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, #e2e8f0 50%, transparent 100%);
            margin: 1.5rem 0;
            border: none;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                box-shadow: none;
            }
            .header, .content {
                padding: 1.5rem;
            }
            .logo {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <div class="logo">📦 PreviSync</div>
            <p class="tagline">Il tuo alleato per la gestione intelligente dell\'inventario</p>
        </div>
        
        <!-- Content -->
        <div class="content">
            <div class="welcome">
                <strong>Ciao ' . htmlspecialchars($userName) . '! 👋</strong>
            </div>
            
            <p>Il tuo business merita previsioni accurate e decisioni strategiche fondate su dati reali. Ecco perché PreviSync analizza automaticamente le tue performance per <strong>ottimizzare i tuoi rifornimenti</strong> e massimizzare la tua redditività su Amazon.</p>
            
            <div class="highlight-box">
                <div class="highlight-title">📊 Report Settimanale in allegato</div>
<div class="highlight-subtitle">Analisi automatica basata sui tuoi dati di vendita storici</div>
            </div>
            
            <div class="info-section">
                <div class="info-title">
    🤖 Perché fidarsi di PreviSync?
</div>
<div class="info-text">
    La nostra intelligenza artificiale analizza migliaia di variabili per prevedere con precisione la domanda futura. Algoritmi avanzati di machine learning studiano i tuoi pattern di vendita, la stagionalità e i trend di mercato per suggerirti le quantità ottimali da ordinare, riducendo sprechi e stockout.
</div>
            </div>
            
            <div class="features-grid">
                <div class="feature">
                    <div class="feature-icon">🔴</div>
                    <div class="feature-text"><strong>Alta Criticità:</strong> Prodotti in esaurimento entro 7 giorni</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">🟡</div>
                    <div class="feature-text"><strong>Media Criticità:</strong> Rifornimento necessario entro 15 giorni</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">🔵</div>
                    <div class="feature-text"><strong>Bassa Criticità:</strong> Pianifica ordini per il prossimo mese</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">📈</div>
                    <div class="feature-text"><strong>Quantità Ottimali:</strong> Suggerimenti basati su algoritmi predittivi</div>
                </div>
            </div>
            
            <hr class="divider">
            
            <div class="info-section">
                <div class="info-title">
                    💡 Suggerimento della settimana
                </div>
                <div class="info-text">
                    Ricorda di considerare i tempi di produzione e spedizione dei tuoi fornitori quando pianifichi gli ordini. Un buffer di sicurezza di 10/15 giorni, può aiutarti ad evitare stockout imprevisti.
                </div>
            </div>
            
            <div class="cta-section">
                <p style="margin-bottom: 1rem; color: #4a5568;">Vuoi accedere alla dashboard completa?</p>
<a href="https://www.skualizer.com/modules/previsync/inventory.php" class="cta-button" style="color: white;">
    🚀 Apri PreviSync
</a>
            </div>
            
            <hr class="divider">
            
<p style="color: #718096; font-size: 0.9rem; text-align: center; margin-bottom: 0;">
    <strong>Data report:</strong> ' . $currentDay . '<br>
    <strong>Generato da:</strong> PreviSync v2.0
</p>
        </div>
        
        <!-- Footer -->
        <div class="footer">
<div class="footer-logo">PreviSync by SkuAlizer</div>
<div class="footer-text">Gestione intelligente inventario Amazon</div>
<div class="footer-text">Questo è un messaggio automatico, non rispondere.</div>
<div class="footer-text" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #4a5568;">
    Modifica le tue preferenze di notifica dal tuo <a href="https://www.skualizer.com/modules/margynomic/profilo_utente.php" style="color: #ff6b35; text-decoration: none;"><strong>Profilo</strong></a>
</div>
        </div>
    </div>
</body>
</html>';
?>