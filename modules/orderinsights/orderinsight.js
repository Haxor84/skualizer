/**
 * OrderInsights JavaScript - Story-Driven UI
 * File: modules/orderinsights/orderinsight.js
 */

// === STATO GLOBALE ===
let currentData = null;
let loadedDays = new Set();
let allDaysData = []; // Tutti i giorni disponibili
let daysOffset = 0; // Offset corrente per "Carica altri giorni"
const daysPerPage = 16; // Giorni da caricare per volta

// === CARICAMENTO PRINCIPALE ===
async function loadOverview() {
    try {
        showLoading(true);
        hideAllSections();
        
        // Reset giorni
        daysOffset = 0;
        allDaysData = [];
        
        const params = getFilterParams();
        const isFiltered = params.has('month') || params.has('start');
        
        // Mostra info se è un caricamento completo (nessun filtro)
        if (!isFiltered) {
            document.getElementById('auto-load-info').style.display = 'block';
        } else {
            document.getElementById('auto-load-info').style.display = 'none';
        }
        
        const response = await fetchAPI('month_summary', params);
        
        if (response.success) {
            currentData = response.data;
            
            // Warning EUR
            if (response.eur_conversion_warning) {
                document.getElementById('eur-warning').style.display = 'block';
            } else {
                document.getElementById('eur-warning').style.display = 'none';
            }
            
            // Render Story-Driven
            renderMonthlyKPI(currentData);
            renderSection1(currentData);
            renderSection2(currentData);
            renderSection3(currentData);
            renderSection4(currentData);
            renderSection5(currentData);
            
            // Carica ultimi 7 giorni automaticamente
            loadLast7Days();
            
            showAllSections();
        } else {
            showError('Errore caricamento dati: ' + response.error);
        }
        
    } catch (error) {
        console.error('Errore loadOverview:', error);
        showError('Errore di connessione: ' + error.message);
    } finally {
        showLoading(false);
    }
}

// === RENDER SEZIONI ===
function renderMonthlyKPI(data) {
    const kpi = data.kpi || {};
    const fc = data.fee_components || {};
    const categorie = data.categorie || [];
    
    // Ricalcola commissioni totali da fee_components (item_related + order + shipment)
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    
    // Usa categorie per operativi e perdite
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    const operativiImporto = catOperativi ? catOperativi.importo_eur : 0;
    
    const catPerdite = categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni');
    const perditeImporto = catPerdite ? catPerdite.importo_eur : 0;
    
    // Header KPI (7 card lifecycle)
    document.getElementById('kpi-incassato').textContent = formatEUR(kpi.incassato_vendite);
    document.getElementById('kpi-commissioni').textContent = formatEUR(commissioniTotali);
    document.getElementById('kpi-operativi').textContent = formatEUR(operativiImporto);
    document.getElementById('kpi-perdite').textContent = formatEUR(perditeImporto);
    document.getElementById('kpi-ordini').innerHTML = 'Pz<br>' + formatNumber(kpi.ordini);
    document.getElementById('kpi-vendute').innerHTML = 'Pz<br>' + formatNumber(kpi.unita_vendute);
    document.getElementById('kpi-rimborsate').innerHTML = 'Pz<br>' + formatNumber(kpi.unita_rimborsate);
}

function renderSection1(data) {
    const kpi = data.kpi;
    
    // Highlight principale
    document.getElementById('section1-ricavi').textContent = formatEUR(kpi.incassato_vendite);
    document.getElementById('section1-ordini').textContent = formatNumber(kpi.ordini);
    document.getElementById('section1-unita').textContent = formatNumber(kpi.unita_vendute);
    
    // KPI grid
    document.getElementById('section1-ordini2').textContent = formatNumber(kpi.ordini);
    document.getElementById('kpi-transazioni').textContent = formatNumber(kpi.transazioni);
    document.getElementById('section1-vendute').textContent = formatNumber(kpi.unita_vendute);
    document.getElementById('section1-rimborsate').textContent = formatNumber(kpi.unita_rimborsate);
    
    // Render avanzati
    renderFeeComponentsMonth(data.fee_components, kpi.incassato_vendite);
    renderFBAvsPrincipalMonth(data.fee_components);
}

function renderSection2(data) {
    const kpi = data.kpi;
    const fc = data.fee_components || {};
    
    // Ricalcola commissioni totali da fee_components (item_related + order + shipment)
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    
    document.getElementById('section2-commissioni').textContent = formatEUR(commissioniTotali);
    
    const percentCommissioni = kpi.incassato_vendite > 0 
        ? ((commissioniTotali / kpi.incassato_vendite) * 100).toFixed(1) 
        : 0;
    document.getElementById('section2-percent').textContent = percentCommissioni;
    
    // Breakdown commissioni
    renderCommissioniBreakdown('fee-breakdown-section2', data.fee_components, kpi.incassato_vendite);
}

function renderSection3(data) {
    const kpi = data.kpi;
    const catOperativi = data.categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    const operativiImporto = catOperativi ? catOperativi.importo_eur : 0;
    
    // Mostra il valore con segno "-" se negativo
    const operativiDisplay = operativiImporto < 0 ? '-' + formatEUR(Math.abs(operativiImporto)) : formatEUR(operativiImporto);
    document.getElementById('section3-operativi').textContent = operativiDisplay;
    
    // Calcola percentuale sul fatturato
    const percent = kpi.incassato_vendite > 0 ? ((Math.abs(operativiImporto) / kpi.incassato_vendite) * 100).toFixed(1) : 0;
    const contextEl = document.querySelector('#section-3 .highlight-context');
    if (contextEl) {
        contextEl.innerHTML = `Rappresentano il <strong>${percent}%</strong> del tuo fatturato lordo`;
    }
    
    // Breakdown operativi (passa anche data completa per calcolo EROGATO)
    renderOperativiBreakdown('breakdown-operativi', data.categorie, data.breakdown_by_type, data);
}

function renderSection4(data) {
    const kpi = data.kpi;
    const catPerdite = data.categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni');
    
    if (!catPerdite || !data.breakdown_by_type['Perdite e Rimborsi/Danni']) {
        document.getElementById('section-4').style.display = 'none';
        return;
    }
    
    document.getElementById('section-4').style.display = 'block';
    
    // Mostra il valore con segno "-" se negativo, "+" se positivo
    const perditeDisplay = catPerdite.importo_eur < 0 
        ? '-' + formatEUR(Math.abs(catPerdite.importo_eur)) 
        : (catPerdite.importo_eur > 0 ? '+' + formatEUR(catPerdite.importo_eur) : formatEUR(0));
    document.getElementById('section4-perdite').textContent = perditeDisplay;
    
    // Calcola percentuale sul fatturato
    const percent = kpi.incassato_vendite > 0 ? ((Math.abs(catPerdite.importo_eur) / kpi.incassato_vendite) * 100).toFixed(1) : 0;
    const contextEl = document.querySelector('#section-4 .highlight-context');
    if (contextEl) {
        contextEl.innerHTML = `Rappresentano il <strong>${percent}%</strong> del tuo fatturato lordo`;
    }
    
    // Breakdown perdite
    renderPerditeBreakdown('breakdown-perdite', data.categorie, data.breakdown_by_type, data);
}

function renderSection5(data) {
    const kpi = data.kpi;
    const categorie = data.categorie;
    const fc = data.fee_components;
    
    // Calcolo corretto di tutte le componenti
    // 1. Incassato dai Clienti (Sezione 1)
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    
    // 2. Commissioni Amazon (Sezione 2) - sempre negative
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    
    // 3. Altri Costi Operativi (Sezione 3) - già negativi
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    const totaleOperativi = catOperativi ? catOperativi.importo_eur : 0;
    
    // 4. Perdite e Rimborsi/Danni (Sezione 4) - possono essere positivi (rimborsi) o negativi (perdite)
    const catPerdite = categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni');
    const totalePerdite = catPerdite ? catPerdite.importo_eur : 0;
    
    // NETTO OPERATIVO = Incassato - Commissioni + Operativi + Perdite/Danni
    const nettoOperativo = incassatoDaiClienti - commissioniTotali + totaleOperativi + totalePerdite;
    
    // Aggiorna visualizzazione
    const nettoEl = document.getElementById('kpi-netto');
    nettoEl.textContent = formatEUR(nettoOperativo);
    nettoEl.className = 'highlight-value ' + (nettoOperativo >= 0 ? 'positive' : 'negative');
    
    // Formula breakdown
    document.getElementById('result-ricavi').textContent = formatEUR(incassatoDaiClienti);
    
    // Costi totali (solo valori negativi)
    const costiTotali = commissioniTotali + Math.abs(totaleOperativi) + (totalePerdite < 0 ? Math.abs(totalePerdite) : 0);
    document.getElementById('result-costi').textContent = '-' + formatEUR(costiTotali);
    
    const margine = incassatoDaiClienti > 0 
        ? ((nettoOperativo / incassatoDaiClienti) * 100).toFixed(1) 
        : 0;
    document.getElementById('result-margine').textContent = margine + '%';
    
    // Messaggio costo materia prima
    const costoMateriaPrima = kpi.costo_materia_prima || 0;
    const warningEl = document.getElementById('materia-prima-warning');
    if (warningEl && costoMateriaPrima > 0) {
        // Usa il netto operativo VISUALIZZATO (calcolato localmente), non quello dal backend
        const utileNettoFinale = nettoOperativo - costoMateriaPrima;
        warningEl.innerHTML = `
            <div style="background: #fff3cd; border-left: 4px solid #ff9800; padding: 1rem; border-radius: 8px; margin-top: 1rem;">
                <strong>⚠️ Importante:</strong> Da questo Netto Operativo devi ancora sottrarre il <strong>costo della materia prima</strong> che ammonta a <strong style="color: #dc2626;">${formatEUR(costoMateriaPrima)}</strong>.<br>
                <small style="display: block; margin-top: 0.5rem;">Il tuo <strong>utile netto finale</strong> sarà quindi: <strong style="color: ${utileNettoFinale >= 0 ? '#16a34a' : '#dc2626'};">${formatEUR(utileNettoFinale)}</strong></small>
            </div>
        `;
        warningEl.style.display = 'block';
    } else if (warningEl) {
        warningEl.style.display = 'none';
    }
}

// === RENDER FEE COMPONENTS ===
function renderFeeComponents(elementId, fc, baseRicavi) {
    const el = document.getElementById(elementId);
    if (!el || !fc) return;
    
    const fmtP = (v) => baseRicavi ? ((v / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `<table>
        <thead>
            <tr>
                <th>Componente</th>
                <th class="number-col">Importo €</th>
                <th class="number-col">% su Ricavi</th>
            </tr>
        </thead>
        <tbody>
            <tr style="background: #e8f5e8;"><td colspan="3"><strong>💰 FATTURATO</strong></td></tr>
            <tr><td style="padding-left: 20px;">Principal</td><td class="number-col">${formatEUR(fc.price.principal)}</td><td class="number-col">${fmtP(fc.price.principal)}</td></tr>
            <tr><td style="padding-left: 20px;">Tax</td><td class="number-col">${formatEUR(fc.price.tax)}</td><td class="number-col">${fmtP(fc.price.tax)}</td></tr>`;
    
    for (const [k, v] of Object.entries(fc.price.by_type || {})) {
        html += `<tr><td style="padding-left: 20px;">${k}</td><td class="number-col">${formatEUR(v)}</td><td class="number-col">${fmtP(v)}</td></tr>`;
    }
    
    html += `<tr style="background: #d4edda; font-weight: bold;">
        <td><strong>TOTALE FATTURATO</strong></td>
        <td class="number-col"><strong>${formatEUR(fc.price.total)}</strong></td>
        <td class="number-col"><strong>${fmtP(fc.price.total)}</strong></td>
    </tr>`;
    
    html += `</tbody></table>`;
    el.innerHTML = html;
}

function renderCommissioniBreakdown(elementId, fc, baseRicavi) {
    const el = document.getElementById(elementId);
    if (!el || !fc) return;
    
    const fmtP = (v) => baseRicavi ? ((Math.abs(v) / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #dc2626; font-size: 20px; margin-bottom: 16px; border-left: 4px solid #dc2626; padding-left: 12px;">
            💸 Commissioni di Vendita/Logistica
        </h3>
        <table style="border-collapse: collapse; width: 100%;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Tipo Fee</th>
                <th style="border: 1px solid #ddd; padding: 12px; text-align: right;">Importo €</th>
                <th style="border: 1px solid #ddd; padding: 12px; text-align: right;">% su Ricavi</th>
            </tr>
        </thead>
        <tbody>`;
    
    // Item Related Fees (solo se ci sono valori)
    if (fc.item_related_fees && fc.item_related_fees.by_type) {
        let itemFeesHtml = '';
        for (const [type, value] of Object.entries(fc.item_related_fees.by_type)) {
            if (Math.abs(value) > 0.01) {
                itemFeesHtml += `<tr>
                    <td style="border: 1px solid #ddd; padding: 8px;">${type}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="negative">${formatEUR(value)}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(Math.abs(value))}</td>
                </tr>`;
            }
        }
        if (itemFeesHtml) {
            html += `<tr style="background: rgba(245, 158, 11, 0.1); font-weight: bold;">
                <td colspan="3" style="border: 1px solid #ddd; padding: 8px;"><strong>💸 FEES ITEM-RELATED</strong></td>
            </tr>`;
            html += itemFeesHtml;
        }
    }
    
    // Order Fees (solo se ci sono valori)
    if (fc.order_fees && fc.order_fees.by_type) {
        let orderFeesHtml = '';
        for (const [type, value] of Object.entries(fc.order_fees.by_type)) {
            if (Math.abs(value) > 0.01) {
                orderFeesHtml += `<tr>
                    <td style="border: 1px solid #ddd; padding: 8px;">${type}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="negative">${formatEUR(value)}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(Math.abs(value))}</td>
                </tr>`;
            }
        }
        if (orderFeesHtml) {
            html += `<tr style="background: rgba(245, 158, 11, 0.1); font-weight: bold;">
                <td colspan="3" style="border: 1px solid #ddd; padding: 8px;"><strong>💸 FEES ORDER-RELATED</strong></td>
            </tr>`;
            html += orderFeesHtml;
        }
    }
    
    // Shipment Fees (solo se ci sono valori)
    if (fc.shipment_fees && fc.shipment_fees.by_type) {
        let shipmentFeesHtml = '';
        for (const [type, value] of Object.entries(fc.shipment_fees.by_type)) {
            if (Math.abs(value) > 0.01) {
                shipmentFeesHtml += `<tr>
                    <td style="border: 1px solid #ddd; padding: 8px;">${type}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="negative">${formatEUR(value)}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(Math.abs(value))}</td>
            </tr>`;
            }
        }
        if (shipmentFeesHtml) {
            html += `<tr style="background: rgba(245, 158, 11, 0.1); font-weight: bold;">
                <td colspan="3" style="border: 1px solid #ddd; padding: 8px;"><strong>💸 FEES SHIPMENT-RELATED</strong></td>
            </tr>`;
            html += shipmentFeesHtml;
        }
    }
    
    // TOTALE COMMISSIONI (somma di tutti i componenti)
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    
    html += `<tr style="background: #f8d7da; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px;"><strong>TOTALE COMMISSIONI AMAZON</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="negative"><strong>${formatEUR(-commissioniTotali)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${fmtP(commissioniTotali)}</strong></td>
    </tr>`;
    
    html += `</tbody></table>`;
    
    // Messaggio esplicativo
    html += `<div class="info-box" style="margin-top: 1rem; background: #fff3cd; border-left: 4px solid #ff9800; padding: 1rem;">
        <strong>💡 Cosa rappresenta questa tabella:</strong><br>
        Queste sono le <strong>commissioni e fee</strong> che Amazon trattiene direttamente dal tuo account settlement. 
        Include costi di vendita (Commission), logistica FBA, servizi digitali e altre fee operative.<br><br>
        Amazon detrae automaticamente questi importi prima di accreditarti il saldo.
        </div>`;
    
    // Calcolo Margine Lordo
    const incassatoDaiClienti = baseRicavi + (fc.refund?.total || 0);
    const margineLordo = incassatoDaiClienti - commissioniTotali;
    const margineLordoPercent = baseRicavi ? ((margineLordo / baseRicavi) * 100).toFixed(2) : 0;
    
    html += `<table style="margin-top: 1.5rem;">
        <tbody>
            <tr style="background: #e3f2fd;">
                <td style="padding: 10px;"><strong>💵 Incassato dai Clienti</strong></td>
                <td class="number-col" style="padding: 10px;"><strong>${formatEUR(incassatoDaiClienti)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #ffebee;">
                <td style="padding: 10px;"><strong>➖ Commissioni Amazon</strong></td>
                <td class="number-col negative" style="padding: 10px;"><strong>${formatEUR(-commissioniTotali)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #4caf50; color: white; font-weight: bold;">
                <td style="border: 2px solid #000; padding: 12px;"><strong>💰 MARGINE LORDO</strong></td>
                <td class="number-col" style="border: 2px solid #000; padding: 12px;"><strong>${formatEUR(margineLordo)}</strong></td>
                <td class="number-col" style="border: 2px solid #000; padding: 12px;"><strong>${margineLordoPercent}%</strong></td>
            </tr>
        </tbody>
    </table>`;
    
    el.innerHTML = html;
}

function renderOperativiBreakdown(elementId, categorie, breakdown, data) {
    const el = document.getElementById(elementId);
    if (!el) return;
    
    // Trova categoria Costi Operativi
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    
    if (!catOperativi || !breakdown['Costi Operativi/Abbonamenti']) {
        el.innerHTML = '<p>Nessun costo operativo nel periodo selezionato</p>';
        return;
    }
    
    const types = breakdown['Costi Operativi/Abbonamenti'];
    const baseRicavi = data.kpi.incassato_vendite;
    const fmtP = (v) => baseRicavi ? ((Math.abs(v) / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #ea580c; font-size: 20px; margin-bottom: 16px; border-left: 4px solid #ea580c; padding-left: 12px;">
            🏢 Costi Operativi/Abbonamenti
        </h3>
        <table style="border-collapse: collapse; width: 100%;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Transaction Type</th>
                <th style="border: 1px solid #ddd; padding: 12px; text-align: right;">Importo €</th>
                <th style="border: 1px solid #ddd; padding: 12px; text-align: center;">Transazioni</th>
            </tr>
        </thead>
        <tbody>`;
    
    for (const t of types) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 8px;">${t.transaction_type}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="negative">${formatEUR(t.importo_eur)}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${t.transazioni}</td>
        </tr>`;
    }
    
    // TOTALE ALTRI COSTI OPERATIVI
    const totaleOperativi = catOperativi.importo_eur;
    html += `<tr style="background: #f8d7da; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px;"><strong>TOTALE ALTRI COSTI OPERATIVI</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="negative"><strong>${formatEUR(totaleOperativi)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><strong>${catOperativi.transazioni}</strong></td>
    </tr>`;
    
    html += `</tbody></table>`;
    
    // Box informativo
    html += `<div class="info-box" style="margin-top: 1rem; background: #fff3cd; border-left: 4px solid #ff9800; padding: 1rem;">
        <strong>💡 Cosa rappresenta questa tabella:</strong><br>
        Questi sono <strong>costi operativi aggiuntivi</strong> che Amazon addebita sul tuo account settlement oltre alle commissioni di vendita. 
        Include costi come: abbonamenti mensili, fee di trasporto inbound, fee di storage/rimozione, fee di servizio e altri costi operativi.<br><br>
        Amazon detrae dal tuo account questi importi prima di accreditarti il saldo.
    </div>`;
    
    // Calcolo EROGATO SU IBAN
    const fc = data.fee_components;
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    const erogato = incassatoDaiClienti - commissioniTotali + totaleOperativi; // totaleOperativi è negativo
    const erogatoPercent = baseRicavi ? ((erogato / baseRicavi) * 100).toFixed(2) : 0;
    
    // Tabella riepilogo finale
    html += `<table style="margin-top: 1.5rem;">
        <tbody>
            <tr style="background: #e3f2fd;">
                <td style="padding: 10px;"><strong>💵 Incassato dai Clienti</strong></td>
                <td class="number-col" style="padding: 10px;"><strong>${formatEUR(incassatoDaiClienti)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #ffebee;">
                <td style="padding: 10px;"><strong>➖ Commissioni Amazon</strong></td>
                <td class="number-col negative" style="padding: 10px;"><strong>${formatEUR(-commissioniTotali)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #fff3cd;">
                <td style="padding: 10px;"><strong>➖ Altri Costi Operativi</strong></td>
                <td class="number-col negative" style="padding: 10px;"><strong>${formatEUR(totaleOperativi)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #4caf50; color: white; font-weight: bold;">
                <td style="border: 2px solid #000; padding: 12px;"><strong>💰 EROGATO SU IBAN</strong></td>
                <td class="number-col" style="border: 2px solid #000; padding: 12px;"><strong>${formatEUR(erogato)}</strong></td>
                <td class="number-col" style="border: 2px solid #000; padding: 12px;"><strong>${erogatoPercent}%</strong></td>
            </tr>
        </tbody>
    </table>`;
    
    el.innerHTML = html;
}

function renderPerditeBreakdown(elementId, categorie, breakdown, data) {
    const el = document.getElementById(elementId);
    if (!el) return;
    
    const catPerdite = categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni');
    
    if (!catPerdite || !breakdown['Perdite e Rimborsi/Danni']) {
        el.innerHTML = '<p>Nessuna perdita o danno nel periodo selezionato</p>';
        return;
    }
    
    const types = breakdown['Perdite e Rimborsi/Danni'];
    const baseRicavi = data.kpi.incassato_vendite;
    const fmtP = (v) => baseRicavi ? ((Math.abs(v) / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #f59e0b; font-size: 20px; margin-bottom: 16px; border-left: 4px solid #f59e0b; padding-left: 12px;">
            ⚠️ Perdite e Rimborsi/Danni
        </h3>
        <table style="border-collapse: collapse; width: 100%;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Transaction Type</th>
                <th style="border: 1px solid #ddd; padding: 12px; text-align: right;">Importo €</th>
                <th style="border: 1px solid #ddd; padding: 12px; text-align: center;">Transazioni</th>
            </tr>
        </thead>
        <tbody>`;
    
    for (const t of types) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 8px;">${t.transaction_type}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="${t.importo_eur >= 0 ? 'positive' : 'negative'}">${formatEUR(t.importo_eur)}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${t.transazioni}</td>
        </tr>`;
    }
    
    // TOTALE PERDITE/DANNI
    const totalePerdite = catPerdite.importo_eur;
    html += `<tr style="background: ${totalePerdite >= 0 ? '#d4edda' : '#f8d7da'}; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px;"><strong>TOTALE PERDITE E RIMBORSI/DANNI</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;" class="${totalePerdite >= 0 ? 'positive' : 'negative'}"><strong>${formatEUR(totalePerdite)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><strong>${catPerdite.transazioni}</strong></td>
    </tr>`;
    
    html += `</tbody></table>`;
    
    // Box informativo
    html += `<div class="info-box" style="margin-top: 1rem; background: #fff3cd; border-left: 4px solid #ff9800; padding: 1rem;">
        <strong>💡 Cosa rappresenta questa tabella:</strong><br>
        Questi sono <strong>rimborsi e compensazioni</strong> che Amazon ti eroga per prodotti danneggiati, persi in magazzino, o eventi come liquidazioni. 
        Include: WAREHOUSE_DAMAGE, WAREHOUSE_LOST, MISSING_FROM_INBOUND, Liquidations e altri eventi di perdita.<br><br>
        ${totalePerdite >= 0 ? 'Questi importi positivi aumentano il tuo netto operativo.' : 'Questi importi negativi riducono il tuo netto operativo (eventi rari).'}
    </div>`;
    
    // Calcolo EROGATO SU IBAN (include anche perdite/danni)
    const fc = data.fee_components;
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    const totaleOperativi = catOperativi ? catOperativi.importo_eur : 0;
    const erogato = incassatoDaiClienti - commissioniTotali + totaleOperativi + totalePerdite; // totalePerdite può essere positivo o negativo
    const erogatoPercent = baseRicavi ? ((erogato / baseRicavi) * 100).toFixed(2) : 0;
    
    // Tabella riepilogo finale
    html += `<table style="margin-top: 1.5rem; border-collapse: collapse; width: 100%;">
        <tbody>
            <tr style="background: #e3f2fd;">
                <td style="padding: 10px;"><strong>💵 Incassato dai Clienti</strong></td>
                <td class="number-col" style="padding: 10px;"><strong>${formatEUR(incassatoDaiClienti)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #ffebee;">
                <td style="padding: 10px;"><strong>➖ Commissioni Amazon</strong></td>
                <td class="number-col negative" style="padding: 10px;"><strong>${formatEUR(-commissioniTotali)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #fff3cd;">
                <td style="padding: 10px;"><strong>➖ Altri Costi Operativi</strong></td>
                <td class="number-col negative" style="padding: 10px;"><strong>${formatEUR(totaleOperativi)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: ${totalePerdite >= 0 ? '#e8f5e9' : '#ffebee'};">
                <td style="padding: 10px;"><strong>${totalePerdite >= 0 ? '➕' : '➖'} Perdite e Rimborsi/Danni</strong></td>
                <td class="number-col ${totalePerdite >= 0 ? 'positive' : 'negative'}" style="padding: 10px;"><strong>${formatEUR(totalePerdite)}</strong></td>
                <td class="number-col" style="padding: 10px;"></td>
            </tr>
            <tr style="background: #4caf50; color: white; font-weight: bold;">
                <td style="border: 2px solid #000; padding: 12px;"><strong>💰 EROGATO SU IBAN</strong></td>
                <td class="number-col" style="border: 2px solid #000; padding: 12px;"><strong>${formatEUR(erogato)}</strong></td>
                <td class="number-col" style="border: 2px solid #000; padding: 12px;"><strong>${erogatoPercent}%</strong></td>
            </tr>
        </tbody>
    </table>`;
    
    el.innerHTML = html;
}

function renderCategoriesRicavi(elementId, categorie, breakdown) {
    const el = document.getElementById(elementId);
    if (!el) return;
    
    // Mostra TUTTE le categorie (non filtrare)
    const ricaviCats = categorie || [];
    
    if (!ricaviCats.length) {
        el.innerHTML = '<p>Nessuna categoria disponibile</p>';
        return;
    }
    
    let html = `<table>
        <thead>
            <tr>
                <th>Categoria</th>
                <th class="number-col">Importo €</th>
                <th style="text-align: center;">Ordini</th>
                <th style="text-align: center;">Transazioni</th>
            </tr>
        </thead>
        <tbody>`;
    
    for (const cat of ricaviCats) {
        const catEscaped = cat.categoria.replace(/'/g, "\\'").replace(/"/g, '&quot;');
        html += `<tr style="cursor: pointer;" data-categoria="${catEscaped}" class="categoria-row">
            <td><strong>${cat.categoria}</strong></td>
            <td class="number-col ${cat.importo_eur >= 0 ? 'positive' : 'negative'}">${formatEUR(cat.importo_eur)}</td>
            <td style="text-align: center;">${formatNumber(cat.ordini)}</td>
            <td style="text-align: center;">${formatNumber(cat.transazioni)}</td>
        </tr>`;
    }
    
    html += `</tbody></table>
    <div class="info-box" style="margin-top: 1rem;">
        <strong>ℹ️ Come leggere:</strong> Clicca su una categoria per vedere tutti i transaction types che la compongono
    </div>`;
    
    el.innerHTML = html;
    
    // Aggiungi event listeners alle righe
    document.querySelectorAll('.categoria-row').forEach(row => {
        row.addEventListener('click', function() {
            const categoria = this.getAttribute('data-categoria').replace(/\\'/g, "'");
            showBreakdown(categoria);
        });
    });
}

// === GIORNI (SEZIONE 6) ===
async function loadLast7Days() {
    try {
        // Chiedi TUTTI i dati disponibili con limite alto
        const params = new URLSearchParams();
        params.append('limit', '10000'); // Limite molto alto per ottenere tutti i giorni
        
        const resp = await fetchAPI('day_index', params);
        let rows = resp.data || [];
        
        const grid = document.getElementById('days-grid');
        grid.innerHTML = '';
        
        if (!rows.length) {
            grid.innerHTML = '<p style="text-align: center; color: var(--text-muted);">Nessun dato disponibile</p>';
            return;
        }
        
        // Rimuovi duplicati basandoti sulla data normalizzata
        const seen = new Set();
        rows = rows.filter(day => {
            const normalizedDate = day.giorno ? day.giorno.split('T')[0] : null;
            if (!normalizedDate || seen.has(normalizedDate)) {
                return false;
            }
            seen.add(normalizedDate);
            return true;
        });
        
        // Ordina per data decrescente (più recente prima)
        rows.sort((a, b) => {
            const dateA = new Date(a.giorno);
            const dateB = new Date(b.giorno);
            return dateB - dateA;
        });
        
        // Salva TUTTI i giorni disponibili
        allDaysData = rows;
        daysOffset = 0;
        
        // Mostra i primi 16 giorni
        renderDays(0, daysPerPage);
        
    } catch (err) {
        console.error('Errore caricamento giorni:', err);
        document.getElementById('days-grid').innerHTML = '<p style="color: var(--danger);">Errore caricamento dati</p>';
    }
}

// Funzione helper per renderizzare i giorni
function renderDays(start, count) {
    const grid = document.getElementById('days-grid');
    const daysToShow = allDaysData.slice(start, start + count);
    
    // Se start === 0, svuota la griglia (primo caricamento)
    if (start === 0) {
        grid.innerHTML = '';
    }
    
    for (const day of daysToShow) {
        const card = document.createElement('div');
        card.className = 'day-card';
        
        // Formatta la data in modo uniforme
        const dateObj = new Date(day.giorno);
        const formattedDate = formatDateShort(dateObj);
        
        // Mostra i RICAVI VENDITE invece del netto operativo
        const ricavi = day.incassato_vendite || 0;
        const ordini = day.ordini || 0;
        const refund = day.refund_totale || 0;
        const refundOrdini = day.refund_ordini || 0;
        
        // Prima riga: Ricavi Order
        let htmlContent = `
            <div class="day-date">${formattedDate}</div>
            <div class="day-value positive">${formatEUR(ricavi)} - ${formatNumber(ordini)} ${ordini === 1 ? 'ordine' : 'ordini'}</div>
        `;
        
        // Seconda riga: Refund (solo se presenti)
        if (refundOrdini > 0) {
            htmlContent += `<div class="day-value negative" style="font-size: 0.85rem; margin-top: 4px;">${formatEUR(refund)} - ${formatNumber(refundOrdini)} ${refundOrdini === 1 ? 'refund' : 'refund'}</div>`;
        }
        
        card.innerHTML = htmlContent;
        card.onclick = () => showDayDetail(day.giorno, day);
        grid.appendChild(card);
    }
    
    // Aggiorna offset basandosi sul numero effettivo di giorni mostrati
    daysOffset = start + daysToShow.length;
    
    // Mostra/nascondi pulsante in base ai giorni rimanenti
    const btnLoadMore = document.getElementById('btn-load-more');
    const remaining = allDaysData.length - daysOffset;
    
    if (daysOffset < allDaysData.length) {
        btnLoadMore.style.display = 'inline-block';
        btnLoadMore.textContent = `Carica altri ${Math.min(remaining, daysPerPage)} giorni`;
    } else {
        btnLoadMore.style.display = 'none';
    }
}

async function loadMoreDays() {
    // Carica i prossimi 16 giorni da allDaysData
    if (daysOffset >= allDaysData.length) {
        document.getElementById('btn-load-more').style.display = 'none';
        return;
    }
    
    renderDays(daysOffset, daysPerPage);
}

async function showDayDetail(dayDate, dayData = null) {
    try {
        const modal = document.getElementById('orders-modal');
        const title = document.getElementById('orders-title');
        const content = document.getElementById('orders-content');
        
        title.textContent = `Dettaglio: ${formatDate(dayDate)}`;
        content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Caricamento...</div>';
        
        modal.classList.add('open');
        
        const params = new URLSearchParams();
        params.append('day', dayDate);
        
        const response = await fetchAPI('day_summary', params);
        
        if (response.success) {
            const data = response.data;
            
            // Trova le categorie specifiche
            const commissioni = data.categorie.find(c => c.categoria === 'Commissioni di Vendita/Logistica') || { importo_eur: 0 };
            const costiOperativi = data.categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti') || { importo_eur: 0 };
            const perditeRimborsi = data.categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni') || { importo_eur: 0 };
            
            // USA I DATI DALLA CARD (day_index) se disponibili, altrimenti usa day_summary
            let incassoGiornata, ordiniCount, refundGiornata, refundCount;
            
            if (dayData) {
                // Usa i dati dalla card (più affidabili)
                incassoGiornata = dayData.incassato_vendite || 0;
                ordiniCount = dayData.ordini || 0;
                refundGiornata = dayData.refund_totale || 0;
                refundCount = dayData.refund_ordini || 0;
            } else {
                // Fallback su day_summary
                incassoGiornata = data.kpi.incassato_vendite || 0;
                ordiniCount = data.kpi.ordini || 0;
                refundGiornata = 0; // Non disponibile in day_summary
                refundCount = data.kpi.unita_rimborsate || 0;
            }
            
            // TOTALE COMMISSIONI AMAZON = Tab2 + Tab3 + Tab4
            const totaleCommissioniAmazon = commissioni.importo_eur + costiOperativi.importo_eur + perditeRimborsi.importo_eur;
            
            // Netto Operativo
            const nettoOperativo = data.kpi.netto_operativo || 0;
            
            let html = `
                <div style="background: linear-gradient(135deg, ${nettoOperativo >= 0 ? '#38a169' : '#e53e3e'}, ${nettoOperativo >= 0 ? '#48bb78' : '#fc8181'}); padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; color: white; text-align: center;">
                    <div style="font-size: 2.5rem; font-weight: bold; margin-bottom: 0.5rem;">${formatEUR(nettoOperativo)}</div>
                    <div style="font-size: 1.1rem; opacity: 0.95;">Netto Operativo del Giorno</div>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.3);">
                        <div>
                            <div style="font-size: 1.3rem; font-weight: bold;">${formatNumber(data.kpi.ordini)}</div>
                            <div style="font-size: 0.85rem; opacity: 0.9;">Ordini</div>
                        </div>
                        <div>
                            <div style="font-size: 1.3rem; font-weight: bold;">${formatNumber(data.kpi.transazioni)}</div>
                            <div style="font-size: 0.85rem; opacity: 0.9;">Transazioni</div>
                        </div>
                    </div>
                </div>
                
                <h3 style="color: #2d3748; font-size: 1.2rem; margin: 1.5rem 0 1rem; border-left: 4px solid #fbbf24; padding-left: 12px;">
                    📊 Calcolo Utile Operativo Giornaliero
                </h3>
                
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 1.5rem;">
                    <thead>
                        <tr style="background: #f8f9fa;">
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Voce</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: right;">Importo €</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: center;">Unità</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: #e8f5e9;">
                            <td style="border: 1px solid #ddd; padding: 10px;"><strong>💰 Incasso di Giornata</strong></td>
                            <td style="border: 1px solid #ddd; padding: 10px; text-align: right; color: #38a169; font-weight: bold;">${formatEUR(incassoGiornata)}</td>
                            <td style="border: 1px solid #ddd; padding: 10px; text-align: center;">${formatNumber(ordiniCount)}</td>
                        </tr>
                        <tr style="background: #ffebee;">
                            <td style="border: 1px solid #ddd; padding: 10px;"><strong>🔄 Refund di Giornata</strong></td>
                            <td style="border: 1px solid #ddd; padding: 10px; text-align: right; color: #e53e3e; font-weight: bold;">${formatEUR(refundGiornata)}</td>
                            <td style="border: 1px solid #ddd; padding: 10px; text-align: center;">${formatNumber(refundCount)}</td>
                        </tr>
                        <tr style="background: #fff3cd;">
                            <td style="border: 1px solid #ddd; padding: 10px;"><strong>➖ Commissioni Amazon Totali</strong></td>
                            <td style="border: 1px solid #ddd; padding: 10px; text-align: right; color: #e53e3e; font-weight: bold;">${formatEUR(totaleCommissioniAmazon)}</td>
                            <td style="border: 1px solid #ddd; padding: 10px; text-align: center;"></td>
                        </tr>
                        <tr style="background: ${nettoOperativo >= 0 ? '#4caf50' : '#f44336'}; color: white; font-weight: bold; font-size: 1.1rem;">
                            <td style="border: 2px solid #000; padding: 14px;"><strong>💎 NETTO OPERATIVO</strong></td>
                            <td style="border: 2px solid #000; padding: 14px; text-align: right;"><strong>${formatEUR(nettoOperativo)}</strong></td>
                            <td style="border: 2px solid #000; padding: 14px; text-align: center;"><strong>${formatNumber(data.kpi.transazioni)}</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem;">
                    <strong>💡 Formula:</strong><br>
                    Netto Operativo = Incasso di Giornata + Refund + Commissioni Amazon Totali (Tab2 + Tab3 + Tab4)
                </div>
                
                <details style="margin-top: 1rem; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f9fafb;">
                    <summary style="cursor: pointer; font-weight: bold; color: #4b5563;">
                        🔍 Dettaglio Commissioni (Tab2 + Tab3 + Tab4)
                    </summary>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Tab2 - Commissioni Vendita/Logistica:</strong> ${formatEUR(commissioni.importo_eur)}
                        </div>
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Tab3 - Costi Operativi/Abbonamenti:</strong> ${formatEUR(costiOperativi.importo_eur)}
                        </div>
                        <div>
                            <strong>Tab4 - Perdite e Rimborsi/Danni:</strong> ${formatEUR(perditeRimborsi.importo_eur)}
                        </div>
                    </div>
                </details>
                
                <div style="margin-top: 1.5rem; text-align: center;">
                    <button class="btn btn-primary" onclick="showDayOrders('${dayDate}')">
                        📋 Vedi Lista Ordini (${data.kpi.ordini})
                    </button>
                </div>`;
            
            content.innerHTML = html;
        }
        
    } catch (error) {
        console.error('Errore showDayDetail:', error);
        content.innerHTML = `<p style="color: #e53e3e;">Errore caricamento: ${error.message}</p>`;
    }
}

async function showDayOrders(day) {
    try {
        const modal = document.getElementById('orders-modal');
        const title = document.getElementById('orders-title');
        const content = document.getElementById('orders-content');
        
        title.textContent = `Ordini del ${formatDate(day)}`;
        content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Caricamento ordini...</div>';
        
        modal.classList.add('open');
        
        const params = new URLSearchParams();
        params.append('day', day);
        
        const response = await fetchAPI('orders', params);
        
        if (response.success && response.data.length > 0) {
            let html = `<p><strong>${response.count}</strong> ordini trovati</p><table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th class="number-col">Incassato €</th>
                        <th>Unità</th>
                        <th>Marketplace</th>
                    </tr>
                </thead>
                <tbody>`;
            
            for (const order of response.data) {
                html += `<tr style="cursor: pointer;" onclick="showOrderDetail('${order.order_id}')">
                    <td><strong>${order.order_id}</strong></td>
                    <td class="number-col ${order.incassato_eur >= 0 ? 'positive' : 'negative'}">${formatEUR(order.incassato_eur)}</td>
                    <td>${formatNumber(order.unita_vendute)}</td>
                    <td>${order.marketplace || 'N/A'}</td>
                </tr>`;
            }
            
            html += `</tbody></table>`;
            content.innerHTML = html;
        } else {
            content.innerHTML = '<p>Nessun ordine trovato</p>';
        }
        
    } catch (error) {
        console.error('Errore showDayOrders:', error);
        content.innerHTML = `<p style="color: #e53e3e;">Errore: ${error.message}</p>`;
    }
}

async function showOrderDetail(orderId) {
    try {
        const modal = document.getElementById('order-detail-modal');
        const title = document.getElementById('order-detail-title');
        const content = document.getElementById('order-detail-content');
        
        title.textContent = `Ordine: ${orderId}`;
        content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i></div>';
        
        modal.classList.add('open');
        
        const params = new URLSearchParams();
        params.append('order_id', orderId);
        
        const response = await fetchAPI('order_detail', params);
        
        if (response.success && response.data.length > 0) {
            let html = `<table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Type</th>
                        <th>SKU</th>
                        <th class="number-col">Qtà</th>
                        <th class="number-col">Amount €</th>
                    </tr>
                </thead>
                <tbody>`;
            
            for (const row of response.data) {
                html += `<tr>
                    <td>${formatDateTime(row.posted_date)}</td>
                    <td><strong>${row.transaction_type}</strong></td>
                    <td>${row.sku || 'N/A'}</td>
                    <td class="number-col">${row.quantity_purchased || 0}</td>
                    <td class="number-col">${formatEUR(row.price_amount || 0)}</td>
                </tr>`;
            }
            
            html += `</tbody></table>`;
            content.innerHTML = html;
        } else {
            content.innerHTML = '<p>Nessun dettaglio trovato</p>';
        }
        
    } catch (error) {
        console.error('Errore showOrderDetail:', error);
        content.innerHTML = `<p style="color: #e53e3e;">Errore: ${error.message}</p>`;
    }
}

// === BREAKDOWN MODAL ===
function showBreakdown(categoria) {
    const modal = document.getElementById('breakdown-modal');
    const title = document.getElementById('breakdown-title');
    const content = document.getElementById('breakdown-content');
    
    title.textContent = `Breakdown: ${categoria}`;
    
    if (!currentData || !currentData.breakdown_by_type[categoria]) {
        content.innerHTML = '<p>Nessun dato disponibile</p>';
        modal.classList.add('open');
        return;
    }
    
    const breakdownData = currentData.breakdown_by_type[categoria];
    
    let html = `<table>
        <thead>
            <tr>
                <th>Transaction Type</th>
                <th class="number-col">Importo €</th>
                <th>Transazioni</th>
            </tr>
        </thead>
        <tbody>`;
    
    for (const item of breakdownData) {
        html += `<tr>
            <td><strong>${item.transaction_type}</strong></td>
            <td class="number-col ${item.importo_eur >= 0 ? 'positive' : 'negative'}">${formatEUR(item.importo_eur)}</td>
            <td>${formatNumber(item.transazioni)}</td>
        </tr>`;
    }
    
    html += `</tbody></table>`;
    content.innerHTML = html;
    modal.classList.add('open');
}

// === UI HELPERS ===
function toggleSection(n) {
    const content = document.getElementById('section-' + n + '-content');
    if (!content) return;
    
    const icon = document.querySelector('#section-' + n + ' .toggle-icon');
    if (content.style.display === 'none') {
        content.style.display = 'block';
        if (icon) icon.classList.add('open');
    } else {
        content.style.display = 'none';
        if (icon) icon.classList.remove('open');
    }
}

function toggleExpand(trigger) {
    const content = trigger.nextElementSibling;
    const arrow = trigger.querySelector('span:first-child');
    
    if (content.classList.contains('open')) {
        content.classList.remove('open');
        arrow.textContent = '▶';
    } else {
        content.classList.add('open');
        arrow.textContent = '▼';
    }
}

function showAllSections() {
    for (let i = 1; i <= 6; i++) {
        const section = document.getElementById('section-' + i);
        if (section) section.style.display = 'block';
    }
}

function hideAllSections() {
    for (let i = 1; i <= 6; i++) {
        const section = document.getElementById('section-' + i);
        if (section) section.style.display = 'none';
    }
}

// === MODALS ===
function closeBreakdownModal() {
    document.getElementById('breakdown-modal').classList.remove('open');
}

function closeOrdersModal() {
    document.getElementById('orders-modal').classList.remove('open');
}

function closeOrderDetailModal() {
    document.getElementById('order-detail-modal').classList.remove('open');
}

// === UTILITIES ===
function getFilterParams() {
    const month = document.getElementById('month-filter').value;
    const startDate = document.getElementById('start-date').value;
    const endDate = document.getElementById('end-date').value;
    
    const params = new URLSearchParams();
    const includeReserve = '0'; // Fondi riserva sempre esclusi
    
    if (startDate && endDate) {
        params.append('start', startDate);
        params.append('end', endDate);
    } else if (month && month.trim() !== '') {
        // Invia month solo se compilato
        params.append('month', month);
    }
    // Se nessun filtro: carica tutti i dati disponibili
    
    params.append('include_reserve', includeReserve);
    return params;
}

function resetFilters() {
    document.getElementById('month-filter').value = '';
    document.getElementById('start-date').value = '';
    document.getElementById('end-date').value = '';
    
    // Reset anche i giorni caricati
    document.getElementById('days-grid').innerHTML = '';
    dayPage = 0;
    document.getElementById('btn-load-more').style.display = 'inline-block';
    
    loadOverview();
}

async function fetchAPI(action, params = new URLSearchParams()) {
    params.append('action', action);
    const response = await fetch(`OverviewController.php?${params.toString()}`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return await response.json();
}

function showLoading(show) {
    document.getElementById('loading').style.display = show ? 'block' : 'none';
}

function showError(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'warning-box';
    alertDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
    alertDiv.style.margin = '1rem';
    
    const story = document.querySelector('.story');
    if (story) story.insertBefore(alertDiv, story.firstChild);
    
    setTimeout(() => alertDiv.remove(), 5000);
}

function formatEUR(amount) {
    const v = Number(amount || 0);
    return '€ ' + v.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatNumber(num) {
    return parseInt(num || 0).toLocaleString('it-IT');
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('it-IT', { 
        weekday: 'short', 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

function formatDateShort(dateObj) {
    if (!dateObj || !(dateObj instanceof Date) || isNaN(dateObj)) {
        return 'Data non valida';
    }
    
    const options = { 
        weekday: 'short', 
        day: 'numeric', 
        month: 'short', 
        year: 'numeric' 
    };
    
    return dateObj.toLocaleDateString('it-IT', options);
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('it-IT');
}

// === RENDER AVANZATI (dal vecchio codice) ===
function renderFeeComponentsMonth(fc, baseRicavi) {
    const el = document.getElementById('fee-components-month');
    if (!el || !fc) return;
    
    const fmtP = (v) => baseRicavi ? ((v / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #16a34a; font-size: 20px; margin-bottom: 16px; border-left: 4px solid #16a34a; padding-left: 12px;">
            💰 Ricavi Vendite
        </h3>
        <table style="border-collapse: collapse; width: 100%;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Componente</th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: right;">Importo €</th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: right;">% su Ricavi</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background: #e8f5e8;">
                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">💰 FATTURATO</td>
                    <td style="border: 1px solid #ddd; padding: 8px;"></td>
                    <td style="border: 1px solid #ddd; padding: 8px;"></td>
                </tr>
                <tr>
                    <td style="border: 1px solid #ddd; padding: 8px; padding-left: 20px;"><strong>Principal</strong></td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${formatEUR(fc.price.principal)}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(fc.price.principal)}</td>
                </tr>
                <tr>
                    <td style="border: 1px solid #ddd; padding: 8px; padding-left: 20px;"><strong>Tax</strong></td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${formatEUR(fc.price.tax)}</td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(fc.price.tax)}</td>
                </tr>`;
    
    for (const [k, v] of Object.entries(fc.price.by_type || {})) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 8px; padding-left: 20px;">${k}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${formatEUR(v)}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(v)}</td>
        </tr>`;
    }
    
    html += `<tr style="background: #d4edda; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px;"><strong>TOTALE FATTURATO</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${formatEUR(fc.price.total)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${fmtP(fc.price.total)}</strong></td>
    </tr>`;
    
    // REFUND
    html += `<tr style="background: #ffe6e6;">
        <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">🔄 REFUND</td>
        <td style="border: 1px solid #ddd; padding: 8px;"></td>
        <td style="border: 1px solid #ddd; padding: 8px;"></td>
    </tr>`;
    
    // Mostra Principal e Tax del refund
    if (fc.refund?.principal && Math.abs(fc.refund.principal) > 0) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 8px; padding-left: 20px;"><strong>Principal</strong></td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${formatEUR(fc.refund.principal)}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(fc.refund.principal)}</td>
        </tr>`;
    }
    
    if (fc.refund?.tax && Math.abs(fc.refund.tax) > 0) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 8px; padding-left: 20px;"><strong>Tax</strong></td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${formatEUR(fc.refund.tax)}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(fc.refund.tax)}</td>
    </tr>`;
    }
    
    // Mostra altri tipi (Shipping, ShippingTax, ecc.)
    for (const [k, v] of Object.entries(fc.refund?.by_type || {})) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 8px; padding-left: 20px;">${k}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${formatEUR(v)}</td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: right;">${fmtP(v)}</td>
        </tr>`;
    }
    
    // TOTALE REFUND
    html += `<tr style="background: #f8d7da; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px;"><strong>TOTALE REFUND</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${formatEUR(fc.refund?.total || 0)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${fmtP(fc.refund?.total || 0)}</strong></td>
    </tr>`;
    
    // Riga vuota
    html += `<tr><td colspan="3" style="height: 10px;"></td></tr>`;
    
    // INCASSATO DAI CLIENTI (Fatturato - Refund)
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    html += `<tr style="background: #4caf50; color: white; font-weight: bold;">
        <td style="border: 2px solid #000; padding: 10px;"><strong>💵 INCASSATO DAI CLIENTI</strong></td>
        <td style="border: 2px solid #000; padding: 10px; text-align: right;"><strong>${formatEUR(incassatoDaiClienti)}</strong></td>
        <td style="border: 2px solid #000; padding: 10px; text-align: right;"><strong>${fmtP(incassatoDaiClienti)}</strong></td>
    </tr>`;
    
    html += '</tbody></table>';
    
    // Messaggio esplicativo
    html += `<div class="info-box" style="margin-top: 1rem; background: #e3f2fd; border-left: 4px solid #2196f3; padding: 1rem;">
        <strong>ℹ️ Come leggere questa tabella:</strong><br>
        Questa sezione mostra il <strong>fatturato lordo</strong> (vendite) a cui vengono sottratti i <strong>rimborsi</strong>. 
        Il risultato (€${formatEUR(incassatoDaiClienti).replace('€ ', '')}) rappresenta quanto hai effettivamente incassato dai clienti prima di pagare Amazon.<br><br>
        Nella prossima sezione vedremo quanto Amazon ha trattenuto in commissioni e fee.
    </div>`;
    el.innerHTML = html;
}

function renderFBAvsPrincipalMonth(fc) {
    // Funzione rimossa - FBA vs Principal ora integrato in Componenti & Fee
    const el = document.getElementById('fba-vs-principal-month');
    if (el) el.innerHTML = '';
}

// === EVENT LISTENERS ===
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('open');
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => m.classList.remove('open'));
    }
});

// === INIT ===
document.addEventListener('DOMContentLoaded', () => {
    // Caricamento automatico mese corrente
    loadOverview();
});