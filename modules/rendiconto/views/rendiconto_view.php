<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Economics - SkuAlizer</title>
    <link rel="stylesheet" href="../margynomic/css/margynomic.css">
    <!-- <link rel="stylesheet" href="assets/rendiconto.css"> Disabled to prevent conflicts with unified styling -->
    <style>
        /* Header styles are now handled by shared_header.php */
        /* Fix logo size to match other pages */
        .header-logo img {
            height: 50px !important;
            max-height: 50px !important;
        }
        
        /* Body styling to match other pages */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #3182ce 0%, #2b77cb 100%);
            min-height: 100vh;
            color: #2d3748;
            margin: 0;
            padding: 0;
        }
        
        /* Container styling */
        .rendiconto-controls,
        main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        /* === WELCOME HERO === */
        .welcome-hero {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 3rem;
            margin: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .welcome-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(102, 126, 234, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .welcome-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .welcome-title {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1rem;
            animation: fadeInUp 1s ease-out;
        }

        .welcome-subtitle {
            font-size: 1.2rem;
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.5px;
            animation: fadeInUp 1.2s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Rendiconto specific styles */
        .rendiconto-controls {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .year-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .year-selector label {
            font-weight: 600;
            color: #2d3748;
        }
        
        .year-selector select {
            padding: 0.75rem;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            color: #4a5568;
            font-weight: 600;
        }
        
        .buttons {
            display: flex;
            gap: 1rem;
        }
        
        .buttons button {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        #btn-load {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        
        #btn-duplicate {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .buttons button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        /* Form transaction-form DEVE RIMANERE SU UNA RIGA */
        #transaction-form {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            gap: 0.5rem !important;
            align-items: flex-end !important;
            overflow-x: auto !important;
        }
        
        #transaction-form > div {
            flex-shrink: 0 !important;
        }
        
        #importo-group[style*="display: block"],
        #quantita-group[style*="display: block"] {
            display: flex !important;
            flex-direction: column !important;
        }
        
        #transaction-form label {
            display: block !important;
            white-space: nowrap !important;
        }
        
        #transaction-form input,
        #transaction-form select,
        #transaction-form button {
            display: block !important;
        }
        
        /* Celle Read-Only e Tooltip */
        .cell-readonly {
            position: relative;
            cursor: help;
            transition: background 0.2s;
        }
        
        .cell-readonly:hover {
            background: rgba(102, 126, 234, 0.05);
        }
        
        .cell-readonly.has-data {
            cursor: pointer;
        }
        
        .cell-readonly.has-data:hover {
            background-color: rgba(102, 126, 234, 0.1);
        }
        
        .cell-readonly.has-data::after {
            content: '▲';
            position: absolute;
            top: 2px;
            right: 2px;
            color: #ef4444;
            font-size: 8px;
        }
        
        .cell-value {
            display: block;
            padding: 2px 3px;
        }
        
        /* Valori negativi in rosso */
        .cell-value.negative-value {
            color: #dc2626 !important;
            font-weight: 600;
        }
        
        /* Valori negativi in rosso per celle KPI (righe 2,3,4,5) */
        td.negative-value,
        td.negative-value.header-green,
        td.right.negative-value,
        td.bold.negative-value,
        span.negative-value,
        div.negative-value {
            color: #dc2626 !important;
            font-weight: 600;
        }
        
        /* ============================================
           SISTEMA COORDINATE TIPO EXCEL
           ============================================ */
        
        /* Intestazione coordinate colonne (A, B, C...) */
        .excel-col-header {
            background-color: #e5e7eb !important;
            color: #374151 !important;
            font-weight: 600;
            font-size: 0.75rem;
            text-align: center !important;
            padding: 4px 2px !important;
            border: 1px solid #d1d5db;
            min-width: 30px;
            user-select: none;
        }
        
        /* Numeri di riga (1, 2, 3...) */
        .excel-row-number {
            background-color: #e5e7eb !important;
            color: #374151 !important;
            font-weight: 600;
            font-size: 0.75rem;
            text-align: center !important;
            padding: 4px 6px !important;
            border: 1px solid #d1d5db;
            min-width: 35px;
            width: 35px;
            user-select: none;
            vertical-align: middle;
        }
        
        /* Cella angolo superiore sinistro */
        .excel-corner {
            background-color: #d1d5db !important;
            border: 1px solid #9ca3af;
        }
        
        /* Tooltip */
        .cell-tooltip {
            display: none;
            position: absolute;
            background: white;
            border: 2px solid #667eea;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 1000;
            min-width: 250px;
            max-width: 400px;
            max-height: 70vh;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .cell-tooltip.show {
            display: block;
        }
        
        /* Scrollbar personalizzata per tooltip */
        .cell-tooltip::-webkit-scrollbar {
            width: 8px;
        }
        
        .cell-tooltip::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        
        .cell-tooltip::-webkit-scrollbar-thumb {
            background: #667eea;
            border-radius: 4px;
        }
        
        .cell-tooltip::-webkit-scrollbar-thumb:hover {
            background: #5568d3;
        }
        
        .tooltip-header {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .tooltip-transaction {
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .tooltip-transaction:last-child {
            border-bottom: none;
        }
        
        .tooltip-date {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .tooltip-amount {
            font-weight: 600;
            color: #2d3748;
            font-size: 1rem;
        }
        
        .tooltip-note {
            font-size: 0.75rem;
            color: #64748b;
            font-style: italic;
            margin-top: 0.25rem;
        }
        
        /* Main content styling */
        main {
            background: transparent;
            backdrop-filter: none;
            border-radius: 0;
            border: none;
            box-shadow: none;
        }
        
        /* Rendiconto Table Section */
        .rendiconto-table-section {
            margin-top: 2rem;
        }
        
        .table-container-rendiconto {
            background: white;
            overflow-x: auto;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            border-radius: 12px;
            padding: 1rem;
        }
        
        .rendiconto-table {
            border-collapse: collapse;
            width: 100%;
            font-family: Arial, sans-serif;
            font-size: 9px;
        }
        
        .rendiconto-table td {
            border: 1px solid #ccc;
            padding: 2px 3px;
            text-align: left;
            white-space: nowrap;
        }
        
        .header-yellow {
            background-color: #FFFF00;
            font-weight: bold;
            text-align: center;
        }
        
        .header-green {
            background-color: #92D050;
            font-weight: bold;
        }
        
        .totals-row {
            background-color: #E7E6E6;
            font-weight: bold;
        }
        
        .year-header {
            background-color: #DAEEF3;
            font-weight: bold;
            font-size: 12px;
        }
        
        .section-header {
            background-color: #FDE9D9;
            font-weight: bold;
        }
        
        .right {
            text-align: right;
        }

        .left {
            text-align: left;
        }

        .center {
            text-align: center;
        }
        
        .bold {
            font-weight: bold;
        }
        
        .negative {
            color: #FF0000;
        }
        
        .percentage {
            background-color: #D9D9D9;
        }
        
        .empty-cell {
            background-color: #F2F2F2;
        }
        
        .stats-row {
            background-color: #FFFFCC;
            font-weight: bold;
        }
        
        /* Excel Input Styling */
        .excel-input {
            width: 100%;
            min-width: 45px;
            border: none;
            background: transparent;
            padding: 1px;
            font-family: Arial, sans-serif;
            font-size: 9px;
            text-align: right;
            color: #000;
        }
        
        .excel-input:focus {
            outline: 1px solid #667eea;
            background: #ffffcc;
        }
        
        /* Responsive Design */
        @media (max-width: 1600px) {
            .rendiconto-table {
                font-size: 8px;
            }
            
            .rendiconto-table td {
                padding: 2px;
            }
            
            .excel-input {
                font-size: 8px;
                min-width: 40px;
            }
        }
        
        @media (max-width: 768px) {
            .rendiconto-table {
                font-size: 7px;
            }
            
            .rendiconto-table td {
                padding: 1px 2px;
            }
            
            .excel-input {
                font-size: 7px;
                min-width: 35px;
            }
        }
        
        /* KPI Section - Strategic Flow Style */
        .kpi-section h2 {
            color: #2d3748;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
        }
        
        .strategic-flow-section {
            margin-bottom: 3rem;
        }
        
        .stats-flow-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .flow-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.5rem;
            border: 3px solid transparent;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        
        .flow-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent 0%, rgba(102, 126, 234, 0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .flow-card:hover::before {
            opacity: 1;
        }
        
        .flow-card:hover {
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
        }
        
        /* Color variations for different KPI types */
        .flow-card.success { 
            border-color: #38a169; 
            background: linear-gradient(135deg, rgba(56, 161, 105, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-card.warning { 
            border-color: #f59e0b; 
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-card.danger { 
            border-color: #ef4444; 
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-card.info { 
            border-color: #667eea; 
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-card.highlight { 
            border-color: #8b5cf6; 
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(255,255,255,0.9) 100%);
        }
        
        .flow-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.2));
        }
        
        .flow-label {
            font-size: 0.95rem;
            font-weight: 700;
            color: #2d3748;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }
        
        .flow-number {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            color: #2d3748;
        }
        
        .flow-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            width: 100%;
            margin-top: 0.75rem;
            font-size: 0.75rem;
        }
        
        .flow-metric {
            background: rgba(0,0,0,0.03);
            padding: 0.4rem;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .flow-metric-label {
            color: #64748b;
            font-weight: 600;
            font-size: 0.7rem;
            margin-bottom: 0.2rem;
        }
        
        .flow-metric-value {
            color: #2d3748;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .summary-card {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(255,255,255,0.95) 100%);
            border: 2px solid #667eea;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
        }
        
        .summary-label {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: #667eea;
            font-family: 'Courier New', monospace;
        }
        
        @media (max-width: 768px) {
            .stats-flow-grid {
                grid-template-columns: 1fr;
            }
            
            .flow-card {
                min-height: 150px;
                padding: 1rem;
            }
            
            .flow-icon {
                font-size: 2rem;
            }
        }
        
        /* Loading and messages */
        .loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 3rem;
            color: #64748b;
        }
        
        .loading.hidden {
            display: none;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .messages {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }
        
        .message {
            background: white;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideInRight 0.3s ease-out;
            border-left: 4px solid;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .message.success {
            border-left-color: #38a169;
            background: linear-gradient(135deg, rgba(56, 161, 105, 0.1), white);
        }
        
        .message.error {
            border-left-color: #ef4444;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), white);
        }
        
        .message.warning {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), white);
        }
        
        .message.info {
            border-left-color: #667eea;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), white);
        }
        
        .message-text {
            flex: 1;
            color: #2d3748;
            font-weight: 500;
        }
        
        .message-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            padding: 0 0.5rem;
            transition: color 0.2s;
        }
        
        .message-close:hover {
            color: #2d3748;
        }

        /* Fix dropdown overflow */
#transaction-form,
#transaction-form * {
    overflow: visible !important;
}

#trans-pagamento-ref {
    position: relative;
    z-index: 1000 !important;
}

/* Forza visibilità opzioni select */
#trans-pagamento-ref option {
    display: block !important;
    padding: 8px !important;
}

@media (max-width: 1200px) {
    .vertical-container {
        grid-template-columns: 1fr !important;
    }
}

    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php require_once '../margynomic/shared_header.php'; ?>
    
    <!-- Force logo size after shared_header loads -->
    <style>
        .dashboard-header .header-logo img {
            height: 50px !important;
            max-height: 50px !important;
            min-height: 50px !important;
        }
    </style>
    
    <!-- Rendiconto Controls -->
    <div class="rendiconto-controls" style="display: none;">
        <div class="year-selector">
            <label for="anno">Anno:</label>
            <select id="anno" name="anno">
                <?php 
                $currentYear = date('Y');
                $selectedYear = $data['documento']['anno'] ?? $currentYear;
                for ($year = 2020; $year <= ($currentYear + 2); $year++): 
                ?>
                    <option value="<?php echo $year; ?>" <?php echo $selectedYear == $year ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="buttons">
            <button id="btn-load" type="button">Carica Anno</button>
            <button id="btn-duplicate" type="button">Duplica Anno</button>
        </div>
    </div>
    
    <!-- Hero Welcome -->
    <div class="welcome-hero" style="max-width: 1400px; margin-left: auto; margin-right: auto;">
        <div class="welcome-content">
            <h1 class="welcome-title">
              <i class="fas fa-file-invoice-dollar"></i> Economics
            </h1>
            <p class="welcome-subtitle">
                GESTISCI ENTRATE, COSTI E SPESE VARIE DEL TUO BUSINESS!
            </p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-top: 1.5rem;">
            <style>
                @media (max-width: 1200px) {
                    .welcome-hero > div:last-child {
                        grid-template-columns: repeat(2, 1fr) !important;
                    }
                }
                @media (max-width: 768px) {
                    .welcome-hero > div:last-child {
                        grid-template-columns: 1fr !important;
                    }
                }
            </style>
            <div style="background: rgba(102, 126, 234, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #667eea;">
                <h4 style="color: #667eea; font-weight: 700; margin-bottom: 0.5rem;">📝 Registrazione Transazioni</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Inserisci manualmente costi per materie prime, spedizioni, tasse e spese varie con assegnazione automatica al mese di competenza.</p>
            </div>
            
            <div style="background: rgba(102, 126, 234, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #667eea;">
                <h4 style="color: #667eea; font-weight: 700; margin-bottom: 0.5rem;">💰 Sync Amazon Automatico</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Importa automaticamente fatturato ed erogato dai report settlement Amazon senza dover inserire manualmente i dati di vendita.</p>
            </div>
            
            <div style="background: rgba(102, 126, 234, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #667eea;">
                <h4 style="color: #667eea; font-weight: 700; margin-bottom: 0.5rem;">📊 Calcolo Margini Real-Time</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Monitora utile netto, tax plafond e percentuali su ogni categoria di costo con aggiornamento immediato e visualizzazione KPI aggregati.</p>
            </div>
            
            <div style="background: rgba(102, 126, 234, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #667eea;">
                <h4 style="color: #667eea; font-weight: 700; margin-bottom: 0.5rem;">📈 Confronto Multi-Anno</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Analizza performance e trend confrontando dati storici di più anni affiancati con evidenza immediata delle variazioni economiche.</p>
            </div>
        </div>
    </div>

        <main>
            <form id="rendiconto-form">
                <input type="hidden" id="documento-id" value="<?php echo $data['documento']['id'] ?? ''; ?>">
                <input type="hidden" id="user-id" value="<?php echo $currentUser['id'] ?? ''; ?>">
                <input type="hidden" id="user-nome" value="<?php echo htmlspecialchars($currentUser['nome'] ?? ''); ?>">
                
<!-- KPI Section - Vertical Cards Design -->
                <div class="vertical-container" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 2rem; margin-bottom: 2rem;">
                        <!-- CARD 1: FLUSSO PRINCIPALE -->
                        <div class="vertical-card primary" style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); border-radius: 20px; padding: 2rem; box-shadow: 0 8px 24px rgba(0,0,0,0.1); border-top: 6px solid #667eea;">
                            <div class="card-header" style="text-align: center; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid rgba(0,0,0,0.1);">
                                <div class="card-title" style="font-size: 1.5rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem;">💰 Flusso Principale</div>
                                <div class="card-subtitle" style="font-size: 0.875rem; color: #64748b; font-weight: 600;">Revenue & Profit Stream</div>
                            </div>

                            <div class="metric-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <div style="text-align: center;">
                                    <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">💵 Fatturato</div>
                                    <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-fatturato-totale">0.00 €</div>
                                    <div class="metric-details" style="display: grid; grid-template-columns: repeat(1, 1fr); gap: 0.5rem;">
                                        <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                            <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                            <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-fatturato-per-unita">0.00</div>
                                        </div>
                                    </div>
                                </div>
                                <div style="text-align: center;">
                                    <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">📤 Erogato</div>
                                    <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-erogato-totale">0.00 €</div>
                                    <div class="metric-details" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem;">
                                        <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                            <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                            <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-erogato-perc-fatt">0%</div>
                                        </div>
                                        <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                            <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                            <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-erogato-per-unita">0.00</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="metric-row" style="background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">📦 FBA</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-fba-totale">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-fba-perc-fatt">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%EROG</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-fba-perc-erog">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-fba-per-unita">0.00</div>
                                    </div>
                                </div>
                            </div>

                            <div class="metric-row" style="background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">✨ Utile Netto</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-utile-netto-totale">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-utile-netto-perc-fatt">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%EROG</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-utile-netto-perc-erog">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-utile-netto-per-unita">0.00</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CARD 2: COSTI OPERATIVI -->
                        <div class="vertical-card warning" style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); border-radius: 20px; padding: 2rem; box-shadow: 0 8px 24px rgba(0,0,0,0.1); border-top: 6px solid #f59e0b;">
                            <div class="card-header" style="text-align: center; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid rgba(0,0,0,0.1);">
                                <div class="card-title" style="font-size: 1.5rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem;">⚙️ Costi Operativi</div>
                                <div class="card-subtitle" style="font-size: 0.875rem; color: #64748b; font-weight: 600;">Operational Expenses</div>
                            </div>

                            <div class="metric-row" style="background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">🧪 Materia Prima</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-materia1-totale">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-materia1-perc-fatt">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%EROG</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-materia1-perc-erog">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-materia1-per-unita">0.00</div>
                                    </div>
                                </div>
                            </div>

                            <div class="metric-row" style="background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">🚚 Spedizioni</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-sped-totale">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-sped-perc-fatt">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%EROG</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-sped-perc-erog">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-sped-per-unita">0.00</div>
                                    </div>
                                </div>
                            </div>

                            <div class="metric-row" style="background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">📋 Varie</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-varie-totale">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-varie-perc-fatt">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%EROG</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-varie-perc-erog">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-varie-per-unita">0.00</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CARD 3: AREA FISCALE -->
                        <div class="vertical-card success" style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); border-radius: 20px; padding: 2rem; box-shadow: 0 8px 24px rgba(0,0,0,0.1); border-top: 6px solid #10b981;">
                            <div class="card-header" style="text-align: center; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid rgba(0,0,0,0.1);">
                                <div class="card-title" style="font-size: 1.5rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem;">🏛️ Area Fiscale</div>
                                <div class="card-subtitle" style="font-size: 0.875rem; color: #64748b; font-weight: 600;">Tax & Reserves</div>
                            </div>

                            <div class="metric-row" style="background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">💼 Accantonato</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-accantonamento-totale">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-accantonamento-perc-fatt">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%EROG</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-accantonamento-perc-erog">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-accantonamento-per-unita">0.00</div>
                                    </div>
                                </div>
                            </div>

                            <div class="metric-row" style="background: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: #64748b; font-weight: 600; margin-bottom: 0.75rem;">🏛️ Tasse</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;" id="flow-tasse-totale">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%FATT</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-tasse-perc-fatt">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">%EROG</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-tasse-perc-erog">0%</div>
                                    </div>
                                    <div class="metric-detail" style="text-align: center; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: #1e293b; font-weight: 700;" id="flow-tasse-per-unita">0.00</div>
                                    </div>
                                </div>
                            </div>

                            <div class="metric-row" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 12px; padding: 1.25rem; margin-bottom: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center;">
                                <div class="metric-label" style="font-size: 0.875rem; color: white; font-weight: 600; margin-bottom: 0.75rem;">💎 Tax Plafond</div>
                                <div class="metric-main" style="font-size: 1.4rem; font-weight: 800; color: white; margin-bottom: 0.75rem;" id="tax-plafond">0.00 €</div>
                                <div class="metric-details" style="display: grid; grid-template-columns: repeat(1, 1fr); gap: 0.5rem;">
                                    <div class="metric-detail" style="text-align: center; background: rgba(255,255,255,0.2); padding: 0.5rem; border-radius: 6px;">
                                        <div class="metric-detail-label" style="font-size: 0.7rem; color: rgba(255,255,255,0.8); font-weight: 600; margin-bottom: 0.25rem;">€/UNITÀ</div>
                                        <div class="metric-detail-value" style="font-size: 0.95rem; color: white; font-weight: 700;" id="tax-plafond-per-unit">0.00 €</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Units Summary -->
                    <div class="units-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-top: 2rem;">
                        <div class="unit-box" style="background: white; border-radius: 16px; padding: 2rem; text-align: center; box-shadow: 0 8px 24px rgba(0,0,0,0.1); border: 2px solid #667eea;">
                            <div class="unit-label" style="font-size: 1rem; color: #64748b; font-weight: 700; margin-bottom: 1rem;">🛒 Unità Acquistate</div>
                            <div class="unit-value" style="font-size: 3rem; font-weight: 900; color: #1e293b;" data-kpi="unita-acquistate">0</div>
                                </div>
                        <div class="unit-box" style="background: white; border-radius: 16px; padding: 2rem; text-align: center; box-shadow: 0 8px 24px rgba(0,0,0,0.1); border: 2px solid #10b981;">
                            <div class="unit-label" style="font-size: 1rem; color: #64748b; font-weight: 700; margin-bottom: 1rem;">📦 Unità Spedite</div>
                            <div class="unit-value" style="font-size: 3rem; font-weight: 900; color: #1e293b;" data-kpi="unita-spedite">0</div>
                                </div>
                        <div class="unit-box" style="background: white; border-radius: 16px; padding: 2rem; text-align: center; box-shadow: 0 8px 24px rgba(0,0,0,0.1); border: 2px solid #f59e0b;">
                            <div class="unit-label" style="font-size: 1rem; color: #64748b; font-weight: 700; margin-bottom: 1rem;">✅ Unità Vendute</div>
                            <div class="unit-value" style="font-size: 3rem; font-weight: 900; color: #1e293b;" data-kpi="unita-vendute">0</div>
                                </div>
                                </div>
                
                <!-- Form Input Transazione - SOPRA LA TABELLA -->
                <div style="background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); border-radius: 16px; padding: 1.5rem; margin: 2rem 0; border: 2px solid #667eea; box-shadow: 0 8px 24px rgba(0,0,0,0.1); overflow: visible !important;">
                    <h3 style="margin: 0 0 1rem 0; font-size: 1.2rem; color: #2d3748; font-weight: 600;">📝 Inserisci Transazione</h3>
                    <div id="transaction-form" style="display: flex; flex-direction: row; flex-wrap: nowrap; gap: 0.75rem; align-items: flex-end; width: 100%; overflow: visible !important; position: relative;">
                        <div style="flex: 0 0 auto; display: flex; flex-direction: column; gap: 0.3rem;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">📊 Tipo</label>
                            <select id="trans-tipo" required style="width: 180px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                                <option value="">Seleziona...</option>
                                <optgroup label="💸 Uscite">
                                    <option value="accantonamento_euro">€ Accantonato</option>
                                    <option value="tasse_pagamento">€ Tasse</option>
                                    <option value="materia_prima_acquisto">€ Materia Prima</option>
                                    <option value="spedizioni_acquisto">€ Spedizione</option>
                                    <option value="spese_varie">€ Varie</option>
                                </optgroup>
                            </select>
                        </div>
                        <div style="flex: 0 0 auto; display: flex; flex-direction: column; gap: 0.3rem;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">📅 Data</label>
                            <input type="date" id="trans-data" required style="width: 160px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                        </div>
                        <!-- Gruppo Pagamento Riferimento - Visibile solo per accantonamento_euro -->
                        <div id="pagamento-ref-group" style="flex: 0 0 auto; display: none; flex-direction: column; gap: 0.3rem;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">💰 Pagamento</label>
                            <select id="trans-pagamento-ref" style="width: 220px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                                <option value="">Seleziona pagamento...</option>
                            </select>
                        </div>
                        <!-- Gruppo Percentuale - Visibile solo per accantonamento_euro -->
                        <div id="percentuale-group" style="flex: 0 0 auto; display: none; flex-direction: column; gap: 0.3rem;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">📊 %</label>
                            <select id="trans-percentuale" style="width: 100px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                                <option value="">Scegli...</option>
                                <option value="5">5%</option>
                                <option value="10">10%</option>
                                <option value="15">15%</option>
                                <option value="20">20%</option>
                                <option value="25">25%</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <!-- Input Percentuale Custom - Visibile solo se percentuale='custom' -->
                        <div id="percentuale-custom-group" style="flex: 0 0 auto; display: none; flex-direction: column; gap: 0.3rem;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">% Custom</label>
                            <input type="number" id="trans-percentuale-custom" step="0.01" min="0" max="100" placeholder="0.00" style="width: 90px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                        </div>
                        <div id="importo-group" style="flex: 0 0 auto; display: none; flex-direction: column; gap: 0.3rem;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">💵 Importo</label>
                            <input type="number" id="trans-importo" step="0.01" placeholder="0.00" style="width: 120px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                        </div>
                        <div id="quantita-group" style="flex: 0 0 auto; display: none; flex-direction: column; gap: 0.3rem;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">📦 Unità</label>
                            <input type="number" id="trans-quantita" step="1" placeholder="0" style="width: 100px; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                        </div>
                        <div style="flex: 1 1 auto; display: flex; flex-direction: column; gap: 0.3rem; min-width: 250px;">
                            <label style="font-size: 0.75rem; font-weight: 600; color: #4a5568; white-space: nowrap; display: block;">📝 Nota</label>
                            <input type="text" id="trans-note" placeholder="Descrizione..." style="width: 100%; padding: 0.5rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; display: block;">
                        </div>
                        <button id="btn-save-trans" type="button" style="flex: 0 0 auto; padding: 0.65rem 1.5rem; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.875rem; white-space: nowrap; height: fit-content; margin-top: 1.5rem; display: block;">
                            💾 Salva
                        </button>
                    </div>
                </div>
                
                <!-- Rendiconto Table Section -->
                <section class="rendiconto-table-section">
                    <div class="table-container-rendiconto">
                        <table class="rendiconto-table">
                            <!-- ============================================
                                 COORDINATE EXCEL: INTESTAZIONE COLONNE
                                 ============================================ -->
                            <tr>
                                <td class="excel-corner"></td>
                                <td class="excel-col-header" data-col="A">A</td>
                                <td class="excel-col-header" data-col="B">B</td>
                                <td class="excel-col-header" data-col="C">C</td>
                                <td class="excel-col-header" data-col="D">D</td>
                                <td class="excel-col-header" data-col="E">E</td>
                                <td class="excel-col-header" data-col="F">F</td>
                                <td class="excel-col-header" data-col="G">G</td>
                                <td class="excel-col-header" data-col="H">H</td>
                                <td class="excel-col-header" data-col="I">I</td>
                                <td class="excel-col-header" data-col="J">J</td>
                                <td class="excel-col-header" data-col="K">K</td>
                                <td class="excel-col-header" data-col="L">L</td>
                                <td class="excel-col-header" data-col="M">M</td>
                                <td class="excel-col-header" data-col="N">N</td>
                                <td class="excel-col-header" data-col="O">O</td>
                            </tr>
                            
                            <!-- Header principale KPI -->
                            <tr data-row="1">
                                <td class="excel-row-number">1</td>
                                <td class="header-yellow" data-cell="A1">VOCE</td>
                                <td class="header-yellow" data-cell="B1">€ TOTALE</td>
                                <td class="header-yellow" data-cell="C1">€ UNITA'</td>
                                <td class="header-yellow" data-cell="D1">% FATTURATO</td>
                                <td class="header-yellow" data-cell="E1">% EROGATO</td>
                                <td class="header-yellow" data-cell="F1">VOCE</td>
                                <td class="header-yellow" data-cell="G1">€ TOTALE</td>
                                <td class="header-yellow" data-cell="H1">€ UNITA'</td>
                                <td class="header-yellow" data-cell="I1">% FATTURATO</td>
                                <td class="header-yellow" data-cell="J1">% EROGATO</td>
                                <td class="header-yellow" data-cell="K1">VOCE</td>
                                <td class="header-yellow" data-cell="L1">€ TOTALE</td>
                                <td class="header-yellow" data-cell="M1">€ UNITA'</td>
                                <td class="header-yellow" data-cell="N1">% FATTURATO</td>
                                <td class="header-yellow" data-cell="O1">% EROGATO</td>
                            </tr>
                            
                            <!-- Riga Fatturato -->
                            <tr data-row="2">
                                <td class="excel-row-number">2</td>
                                <td class="bold">€ FATTURATO:</td>
                                <td class="right bold" id="kpi-fatturato-totale">0.00 €</td>
                                <td class="right" id="kpi-fatturato-per-unita">0.00 €</td>
                                <td class="right percentage" id="kpi-fatturato-perc-fatt">100,00%</td>
                                <td></td>
                                <td class="bold">€ ACCANTONATO:</td>
                                <td class="right bold" id="kpi-accantonamento-totale">0.00 €</td>
                                <td class="right" id="kpi-accantonamento-per-unita">0.00 €</td>
                                <td class="right" id="kpi-accantonamento-perc-fatt">0.00%</td>
                                <td class="right" id="kpi-accantonamento-perc-erog">0.00%</td>
                                <td class="bold">€ FBA:</td>
                                <td class="right bold" id="kpi-fba-totale">0.00 €</td>
                                <td class="right" id="kpi-fba-per-unita">0.00 €</td>
                                <td class="right" id="kpi-fba-perc-fatt">0.00%</td>
                                <td></td>
                            </tr>
                            
                            <!-- Riga Erogato -->
                            <tr data-row="3">
                                <td class="excel-row-number">3</td>
                                <td class="bold">€ EROGATO:</td>
                                <td class="right bold" id="kpi-erogato-totale">0.00 €</td>
                                <td class="right" id="kpi-erogato-per-unita">0.00 €</td>
                                <td class="right" id="kpi-erogato-perc-fatt">0.00%</td>
                                <td class="right percentage" id="kpi-erogato-perc-erog">100,00%</td>
                                <td class="bold">€ TAX:</td>
                                <td class="right bold" id="kpi-tasse-totale">0.00 €</td>
                                <td class="right" id="kpi-tasse-per-unita">0.00 €</td>
                                <td class="right" id="kpi-tasse-perc-fatt">0.00%</td>
                                <td class="right" id="kpi-tasse-perc-erog">0.00%</td>
                                <td class="bold">€ MATERIA 1:</td>
                                <td class="right bold" id="kpi-materia1-totale">0.00 €</td>
                                <td class="right" id="kpi-materia1-per-unita">0.00 €</td>
                                <td class="right" id="kpi-materia1-perc-fatt">0.00%</td>
                                <td class="right" id="kpi-materia1-perc-erog">0.00%</td>
                            </tr>
                            
                            <!-- Riga Utile Netto -->
                            <tr data-row="4">
                                <td class="excel-row-number">4</td>
                                <td class="bold header-green">€ UTILE NETTO:</td>
                                <td class="right bold header-green" id="kpi-utile-lordo-totale">0.00 €</td>
                                <td class="right header-green" id="kpi-utile-lordo-per-unita">0.00 €</td>
                                <td class="right header-green" id="kpi-utile-lordo-perc-fatt">0.00%</td>
                                <td class="right header-green" id="kpi-utile-lordo-perc-erog">0.00%</td>
                                <td class="bold">€ VARIE:</td>
                                <td class="right bold" id="kpi-varie-totale">0.00 €</td>
                                <td class="right" id="kpi-varie-per-unita">0.00 €</td>
                                <td class="right" id="kpi-varie-perc-fatt">0.00%</td>
                                <td class="right" id="kpi-varie-perc-erog">0.00%</td>
                                <td class="bold">€ SPEDIZIONE:</td>
                                <td class="right bold" id="kpi-sped-totale">0.00 €</td>
                                <td class="right" id="kpi-sped-per-unita">0.00 €</td>
                                <td class="right" id="kpi-sped-perc-fatt">0.00%</td>
                                <td class="right" id="kpi-sped-perc-erog">0.00%</td>
                            </tr>
                            
                            <!-- Riga Varie -->
                            <tr data-row="5">
                                <td class="excel-row-number">5</td>
                                <td colspan="2" class="stats-row center">UNITA' ACQUISTATE: <span id="unita-acquistate">0</span></td>
                                <td colspan="2" class="stats-row center">UNITA' SPEDITE: <span id="unita-spedite">0</span></td>
                                <td colspan="2" class="stats-row center">UNITA' VENDUTE: <span id="unita-vendute">0</span></td>
                                <td colspan="2" class="stats-row">€ TAX PLAFOND: <span id="tax-plafond-table">0.00 €</span></td>
                                <td colspan="2" class="stats-row">€ UTILE ATTESO: <span id="utile-netto-atteso">0.00 €</span></td>
                                <td class="bold"></td>
                                <td class="right bold"></td>
                                <td class="right"></td>
                                <td class="right"></td>
                                <td class="right"></td>
                            </tr>
                            
                            <!-- Riga separatrice nera (senza numero) -->
                            <tr style="height: 2px; line-height: 2px; background-color: #000000;">
                                <td style="padding: 0; height: 2px; background-color: #000000; border: none;"></td>
                                <td colspan="15" style="padding: 0; height: 2px; background-color: #000000; border: none;"></td>
                            </tr>
                            
                            <!-- Riga statistiche
                            <tr>
                                <td></td>
                                <td colspan="2" class="stats-row center">UNITA' ACQUISTATE: <span id="unita-acquistate">0</span></td>
                                <td colspan="2" class="stats-row center">UNITA' SPEDITE: <span id="unita-spedite">0</span></td>
                                <td colspan="2" class="stats-row center">UNITA' VENDUTE: <span id="unita-vendute">0</span></td>
                                <td colspan="2" class="stats-row">€ TAX PLAFOND: <span id="tax-plafond-table">0.00 €</span></td>
                                <td colspan="2" class="stats-row">€ UTILE ATTESO: <span id="utile-netto-atteso">0.00 €</span></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr> -->
                            <!-- Anno Corrente -->
                            <tr data-row="6">
                                <td class="excel-row-number">6</td>
                                <td colspan="15" class="year-header">
                                     <div style="display: flex; justify-content: space-between;">
                                        <span><?php echo $data['documento']['anno'] ?? date('Y'); ?> <?php echo strtoupper($currentUser['nome'] ?? 'AZIENDA'); ?></span>
                                </div>
                                </td>
                            </tr> 
                            <tr data-row="7">
                                <td class="excel-row-number">7</td>
                                <td colspan="4" class="year-header" style="background-color: #4ade80; color: #166534;">
                                     <div style="display: flex; justify-content: space-between;">
                                        <span>ENTRATE:</span>
                                </div>
                                </td>
                                <td colspan="3" class="year-header" style="background-color: #f87171; color: #991b1b;">
                                     <div style="display: flex; justify-content: space-between;">
                                        <span>TAX:</span>
                                </div>
                                </td>
                                <td colspan="5" class="year-header" style="background-color: #f87171; color: #991b1b;">
                                     <div style="display: flex; justify-content: space-between;">
                                        <span>USCITE:</span>
                                </div>
                                </td>
                                <td colspan="3" class="year-header" style="background-color: #4ade80; color: #166534;">
                                     <div style="display: flex; justify-content: space-between;">
                                        <span>UTILE NETTO:</span>
                            </div>
                                </td>
                            </tr>
                            
                            <tr data-row="8">
                                <td class="excel-row-number">8</td>
                                <td class="bold left">DATA:</td>
                                <td class="bold right">€ FATTURATO:</td>
                                <td class="bold center">UNITA':</td>
                                <!-- <td class="bold">DATA:</td> -->
                                <td class="bold right">€ EROGATO:</td>
                                <!-- <td class="bold">DATA:</td> -->
                                <td class="bold right">% TAX PLAFOND:</td>
                                <td class="bold right">€ TAX PLAFOND:</td>
                                <td class="bold right">€ TAX:</td>
                                <!-- <td class="bold">DATA:</td> -->
                                <td class="bold right">€ MATERIA 1:</td>
                                <td class="bold center">UNITA':</td>
                                <td class="bold right">€ SPEDIZIONE:</td>
                                <td class="bold center">UNITA':</td>
                                <td class="bold right">€ VARIE:</td>
                                <td class="bold right">PER MESE:</td>
                                <td class="bold right">€ UTILE:</td>
                                <td class="bold left">% UTILE:</td>
                            </tr>
                            
                            <!-- Dati mensili -->
                            <?php 
                            $annoRif = $data['documento']['anno'] ?? date('Y');
                            $mesiLabels = [
                                '01/' . $annoRif, '02/' . $annoRif, '03/' . $annoRif, '04/' . $annoRif, 
                                '05/' . $annoRif, '06/' . $annoRif, '07/' . $annoRif, '08/' . $annoRif, 
                                '09/' . $annoRif, '10/' . $annoRif, '11/' . $annoRif, '12/' . $annoRif
                            ];
                            $mesiNomi = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
                            $mesiCompleti = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
                            // Ordine decrescente: da dicembre (12) a gennaio (1)
                            // Numero di riga: parte da 10 per dicembre, fino a 21 per gennaio
                            $rowNumber = 9;
                            for ($mese = 12; $mese >= 1; $mese--): 
                                $riga = $data['righe'][$mese] ?? [];
                            ?>
                            <tr data-mese="<?php echo $mese; ?>" data-row="<?php echo $rowNumber; ?>">
                                <td class="excel-row-number"><?php echo $rowNumber; $rowNumber++; ?></td>
                                <td class="left"><?php echo $mesiLabels[$mese-1]; ?></td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="entrate_fatturato">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="center cell-readonly" data-mese="<?php echo $mese; ?>" data-field="entrate_unita">
                                    <span class="cell-value">0</span>
                                </td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="erogato_importo">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="accantonamento_percentuale">
                                    <span class="cell-value">0.00%</span>
                                </td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="accantonamento_euro">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="tasse_euro">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="materia1_euro">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="center cell-readonly" data-mese="<?php echo $mese; ?>" data-field="materia1_unita">
                                    <span class="cell-value">0</span>
                                </td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="sped_euro">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="center cell-readonly" data-mese="<?php echo $mese; ?>" data-field="sped_unita">
                                    <span class="cell-value">0</span>
                                </td>
                                <td class="right cell-readonly" data-mese="<?php echo $mese; ?>" data-field="varie_euro">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="left" data-col="M"><strong><?php echo $mesiCompleti[$mese-1]; ?></strong></td>
                                <td class="right" data-col="N" data-mese="<?php echo $mese; ?>" data-field="utile_euro">
                                    <span class="cell-value">0.00 €</span>
                                </td>
                                <td class="right" data-col="O" data-mese="<?php echo $mese; ?>" data-field="utile_percentuale">
                                    <span class="cell-value">0.00%</span>
                                </td>
                            </tr>
                            <?php endfor; ?>
                            
                            <!-- Totali (include Totale / Unitario nella stessa riga) -->
                            <tr class="totals-row" data-row="21">
                                <td class="excel-row-number">21</td>
                                <td class="bold left">Totale / Unitario</td>
                                <td class="right bold" id="total-avg-fatturato">0.00 € / 0.00 €</td>
                                <td class="center bold" id="total-unita">0</td>
                                <td class="right bold" id="total-avg-erogato">0.00 € / 0.00 €</td>
                                <td class="right bold" id="total-percent-accant">0.00%</td>
                                <td class="right bold" id="total-avg-accant">0.00 € / 0.00 €</td>
                                <td class="right bold" id="total-avg-tax">0.00 € / 0.00 €</td>
                                <td class="right bold" id="total-avg-materia1">0.00 € / 0.00 €</td>
                                <td class="center bold" id="total-materia1-unita">0</td>
                                <td class="right bold" id="total-avg-sped">0.00 € / 0.00 €</td>
                                <td class="center bold" id="total-sped-unita">0</td>
                                <td class="right bold" id="total-avg-varie">0.00 € / 0.00 €</td>
                                <td class="bold center" data-col="M"></td>
                                <td class="right bold" data-col="N" id="total-avg-utile">0.00 €</td>
                                <td class="right bold" data-col="O" id="total-avg-utile-perc">0.00%</td>
                            </tr>
                        </table>
                    </div>
                </section>
            </form>
        </main>

        <!-- Loading indicator -->
        <div id="loading" class="loading hidden">
            <div class="spinner"></div>
            <p>Caricamento...</p>
        </div>

        <!-- Messages -->
        <div id="messages" class="messages"></div>
    </div>
    

    <script src="assets/rendiconto.js?v=<?php echo time(); ?>"></script>
    <script>
        // Logout function is now handled by shared_header.php
        
        // Initialize with current data
        document.addEventListener('DOMContentLoaded', async function() {
            window.rendicontoApp = new RendicontoApp();
            await window.rendicontoApp.init();
        });
    </script>
</body>
</html>
