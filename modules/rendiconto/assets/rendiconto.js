/**
 * Rendiconto JavaScript - Live calculations and form handling
 * No external dependencies - vanilla JavaScript only
 */

class RendicontoApp {
    constructor() {
        this.data = {
            documento: {},
            righe: {},
            totali: {},
            kpi: {}
        };
        
        this.monthNames = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
        
        // User context per sicurezza
        this.userId = null;
        this.userName = null;
    }
    
    async init() {
        // // console.log('🎬 [INIT] START');
        this.initUserContext();
        this.bindEvents();
        await this.loadInitialData();
        // // console.log('🎬 [INIT] END');
    }
    
    initUserContext() {
        const userIdEl = document.getElementById('user-id');
        const userNameEl = document.getElementById('user-nome');
        
        this.userId = userIdEl ? userIdEl.value : null;
        this.userName = userNameEl ? userNameEl.value : 'Utente';
        
        if (!this.userId) {
            this.showMessage('Errore: sessione utente non valida', 'error');
            setTimeout(() => {
                window.location.href = '../margynomic/login/login.php';
            }, 2000);
        }
    }
    
    bindEvents() {
        // Transaction form button click
        const btnSaveTrans = document.getElementById('btn-save-trans');
        if (btnSaveTrans) {
            btnSaveTrans.addEventListener('click', (e) => {
                e.preventDefault();
                this.saveTransaction(e);
            });
        }
        
        // Tipo transazione change - mostra/nascondi campi
        const transTipo = document.getElementById('trans-tipo');
        if (transTipo) {
            transTipo.addEventListener('change', () => this.toggleTransactionFields());
        }
        
        // Button events
        const btnLoad = document.getElementById('btn-load');
        if (btnLoad) btnLoad.addEventListener('click', () => this.loadYear());
        
        const btnDuplicate = document.getElementById('btn-duplicate');
        if (btnDuplicate) btnDuplicate.addEventListener('click', () => this.duplicateYear());
        
        // Year selector change
        const annoSelect = document.getElementById('anno');
        if (annoSelect) annoSelect.addEventListener('change', () => this.loadYear());
        
        // Cell tooltips
        this.bindCellTooltips();
    }
    
    calculateRowValues(mese) {
        const riga = this.data.righe[mese];
        if (!riga) return;
        
        // Calculate accantonamento_euro if not provided but percentage is
        if (riga.accantonamento_euro === 0 && riga.accantonamento_percentuale > 0) {
            riga.accantonamento_euro = this.roundHalfUp(
                riga.entrate_fatturato * (riga.accantonamento_percentuale / 100), 2
            );
        }
        
        // Calculate utile_lordo_mese
        const utileLordoMese = riga.erogato_importo + riga.accantonamento_euro 
                              - riga.materia1_euro - riga.sped_euro - riga.varie_euro;
        
        // Calculate utile_netto_mese
        riga.utile_netto_mese = utileLordoMese - riga.tasse_euro;
    }
    
    updateRowUI(mese) {
        const riga = this.data.righe[mese];
        if (!riga) return;
        
        // Update accantonamento_euro field if it was calculated
        const accantonamentoInput = document.querySelector(`input[name="accantonamento_euro_${mese}"]`);
        if (accantonamentoInput) {
            accantonamentoInput.value = this.formatNumber(riga.accantonamento_euro, 2);
        }
        
        // Update utile_netto_mese field
        const utileNettoInput = document.querySelector(`input[name="utile_netto_mese_${mese}"]`);
        if (utileNettoInput) {
            utileNettoInput.value = this.formatNumber(riga.utile_netto_mese, 2);
        }
    }
    
    calculateTotalsAndKPIs() {
        // Calculate totals
        this.data.totali = {
            tot_entrate_fatturato: 0,
            tot_entrate_unita: 0,
            tot_erogato: 0,
            tot_accantonamento: 0,
            tot_tasse: 0,
            tot_diversi: 0,
            tot_materia1: 0,
            tot_materia1_unita: 0,
            tot_sped: 0,
            tot_sped_unita: 0,
            tot_varie: 0,
            tot_utile_netto: 0
        };
        
        for (let mese = 1; mese <= 12; mese++) {
            const riga = this.data.righe[mese];
            if (!riga) continue;
            
            this.data.totali.tot_entrate_fatturato += riga.entrate_fatturato || 0;
            this.data.totali.tot_entrate_unita += riga.entrate_unita || 0;
            this.data.totali.tot_erogato += riga.erogato_importo || 0;
            this.data.totali.tot_accantonamento += riga.accantonamento_euro || 0;
            this.data.totali.tot_tasse += riga.tasse_euro || 0;
            this.data.totali.tot_diversi += riga.diversi_euro || 0;
            this.data.totali.tot_materia1 += riga.materia1_euro || 0;
            this.data.totali.tot_materia1_unita += riga.materia1_unita || 0;
            this.data.totali.tot_sped += riga.sped_euro || 0;
            this.data.totali.tot_sped_unita += riga.sped_unita || 0;
            this.data.totali.tot_varie += riga.varie_euro || 0;
            this.data.totali.tot_utile_netto += riga.utile_netto_mese || 0;
        }
        
        // Calculate derived totals
        this.data.totali.utile_lordo_totale = this.data.totali.tot_erogato + this.data.totali.tot_accantonamento
                                            - this.data.totali.tot_materia1 - this.data.totali.tot_sped - this.data.totali.tot_varie;
        
        this.data.totali.utile_netto_totale = this.data.totali.utile_lordo_totale - this.data.totali.tot_tasse;
        
        // FBA calculation
        this.data.totali.fba_totale = this.data.totali.tot_entrate_fatturato - (this.data.totali.tot_erogato + this.data.totali.tot_accantonamento);
        
        // Calculate KPIs
        this.calculateKPIs();
        
        // Update UI
        this.updateTotalsUI(); // Ora include anche gli unitari
        this.updateKPIUI();
    }
    
    calculateKPIs() {
        const totali = this.data.totali;
        this.data.kpi = {};
        
        const categories = {
            fatturato: totali.tot_entrate_fatturato,
            erogato: totali.tot_erogato,
            fba: totali.fba_totale,
            accantonamento: totali.tot_accantonamento,
            tasse: totali.tot_tasse,
            materia1: totali.tot_materia1,
            sped: totali.tot_sped,
            varie: totali.tot_varie,
            utile_lordo: totali.utile_lordo_totale,
            utile_netto: totali.utile_netto_totale
        };
        
        Object.keys(categories).forEach(category => {
            const totale = categories[category];
            
            // Per unità calculation
            let perUnita = 0;
            if (totali.tot_entrate_unita > 0) {
                perUnita = this.roundHalfUp(totale / totali.tot_entrate_unita, 2);
            }
            
            // Percentage of fatturato
            let percFatt = 0;
            if (totali.tot_entrate_fatturato > 0) {
                percFatt = this.roundHalfUp((totale / totali.tot_entrate_fatturato) * 100, 2);
            }
            
            // Percentage of erogato
            let percErog = 0;
            if (totali.tot_erogato > 0) {
                percErog = this.roundHalfUp((totale / totali.tot_erogato) * 100, 2);
            }
            
            this.data.kpi[category] = {
                totale: this.roundHalfUp(totale, 2),
                per_unita: perUnita,
                perc_fatt: percFatt,
                perc_erog: percErog
            };
        });
    }
    
    calculateUtileMensile(anno) {
        // Legge i valori unitari dalla riga 21 (parte dopo il "/" nel formato "totale / unitario")
        const suffix = anno === parseInt(document.getElementById('anno').value) ? '' : `-${anno}`;
        
        // Funzione helper per estrarre il valore unitario dal formato "totale / unitario"
        const getUnitValue = (text) => {
            if (!text || !text.includes('/')) return 0;
            const parts = text.split('/');
            if (parts.length < 2) return 0;
            return this.parseEuroValueSimple(parts[1].trim());
        };
        
        // Legge i valori unitari dalla riga 21
        const erogatoUnitEl = document.querySelector(`#total-avg-erogato${suffix}`);
        const taxUnitEl = document.querySelector(`#total-avg-tax${suffix}`);
        const materia1UnitEl = document.querySelector(`#total-avg-materia1${suffix}`);
        const spedUnitEl = document.querySelector(`#total-avg-sped${suffix}`);
        const varieUnitEl = document.querySelector(`#total-avg-varie${suffix}`);
        
        const erogatoUnit = getUnitValue(erogatoUnitEl?.textContent || '0');
        const taxUnit = getUnitValue(taxUnitEl?.textContent || '0');
        const materia1Unit = getUnitValue(materia1UnitEl?.textContent || '0');
        const spedUnit = getUnitValue(spedUnitEl?.textContent || '0');
        const varieUnit = getUnitValue(varieUnitEl?.textContent || '0');
        
        // Calcola utile unitario: Erogato + Tax + Materia1 + Spedizione + Varie
        // (i valori di tax, materia1, sped, varie sono già negativi nella riga 21)
        const utileUnit = erogatoUnit + taxUnit + materia1Unit + spedUnit + varieUnit;
        
        let sommaUtileMensile = 0;
        
        // STEP 1: Calcola e popola colonna N (utile in €) per tutti i mesi
        for (let mese = 1; mese <= 12; mese++) {
            // Legge unità vendute del mese
            const unitaCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_unita"][data-anno="${anno}"] .cell-value`);
            const unitaMese = parseInt(unitaCell?.textContent) || 0;
            
            // Calcola utile del mese
            const utileMese = unitaMese * utileUnit;
            sommaUtileMensile += utileMese;
            
            // Popola colonna N (utile in €)
            const utileEuroCell = document.querySelector(`[data-mese="${mese}"][data-field="utile_euro"][data-anno="${anno}"] .cell-value`);
            if (utileEuroCell) {
                utileEuroCell.textContent = this.formatNumber(utileMese, 2) + ' €';
            }
        }
        
        // STEP 2: Calcola e popola N21 (Totale / Unitario)
        const totaleUtile = sommaUtileMensile;
        
        // Legge C21 (totale unità vendute) per calcolare utile unitario
        const c21El = document.querySelector(`#total-unita${suffix}`);
        const c21Value = parseInt(c21El?.textContent) || 1; // Evita divisione per 0
        
        const utileUnitarioN21 = totaleUtile / c21Value;
        
        // Popola N21 nel formato "Totale / Unitario"
        const totalAvgUtileEl = document.querySelector(`#total-avg-utile${suffix}`);
        if (totalAvgUtileEl) {
            totalAvgUtileEl.textContent = 
                this.formatNumber(totaleUtile, 2) + ' € / ' + 
                this.formatNumber(utileUnitarioN21, 2) + ' €';
        }
        
        // STEP 3: Calcola e popola colonna O (percentuale) per tutti i mesi
        // Formula: O = (N / B) × 100
        for (let mese = 1; mese <= 12; mese++) {
            // Legge N (utile del mese)
            const utileEuroCell = document.querySelector(`[data-mese="${mese}"][data-field="utile_euro"][data-anno="${anno}"] .cell-value`);
            const utileMese = this.parseEuroValueSimple(utileEuroCell?.textContent || '0');
            
            // Legge B (fatturato del mese)
            const fatturatoMeseCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_fatturato"][data-anno="${anno}"] .cell-value`);
            const fatturatoMese = this.parseEuroValueSimple(fatturatoMeseCell?.textContent || '0');
            
            // Calcola percentuale: N / B × 100
            let utilePerc = 0;
            if (fatturatoMese !== 0) {
                utilePerc = (utileMese / fatturatoMese) * 100;
            }
            
            // Popola colonna O (utile in %)
            const utilePercCell = document.querySelector(`[data-mese="${mese}"][data-field="utile_percentuale"][data-anno="${anno}"] .cell-value`);
            if (utilePercCell) {
                utilePercCell.textContent = this.formatNumber(utilePerc, 2) + '%';
            }
        }
        
        // STEP 4: Calcola e popola O21 nel formato "Percentuale totale / Percentuale unitaria"
        // Perc. totale = (N21_totale / B21_totale) × 100
        // Perc. unitaria = (N21_unitario / B21_unitario) × 100
        
        // Legge B21 (formato "totale / unitario")
        const b21El = document.querySelector(`#total-avg-fatturato${suffix}`);
        const b21Text = b21El?.textContent || '0 / 0';
        
        // Estrae totale e unitario da B21
        const b21Parts = b21Text.split('/');
        const b21Totale = this.parseEuroValueSimple(b21Parts[0]?.trim() || '0');
        const b21Unitario = b21Parts.length > 1 ? this.parseEuroValueSimple(b21Parts[1]?.trim() || '0') : 0;
        
        // Calcola percentuale totale: (N21_totale / B21_totale) × 100
        let percTotale = 0;
        if (b21Totale !== 0) {
            percTotale = (totaleUtile / b21Totale) * 100;
        }
        
        // Popola O21 - Mostra solo la percentuale totale
        const totalAvgUtilePercEl = document.querySelector(`#total-avg-utile-perc${suffix}`);
        if (totalAvgUtilePercEl) {
            totalAvgUtilePercEl.textContent = this.formatNumber(percTotale, 2) + '%';
        }
        
        // Applica stili ai valori negativi (colore rosso)
        this.applyNegativeValueStyles();
    }
    
    updateTotalsUI() {
        // Calculate totals ONLY for current year
        const annoCorrente = parseInt(document.getElementById('anno').value);
        const el = (id) => document.getElementById(id);
        
        let totals = {
            fatturato: 0,
            unita: 0,
            erogato: 0,
            accantonamento: 0,
            tasse: 0,
            materia1: 0,
            materia1_unita: 0,
            sped: 0,
            sped_unita: 0,
            varie: 0
        };
        
        // Sum values from all 12 months of CURRENT YEAR only
        for (let mese = 1; mese <= 12; mese++) {
            const fattCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_fatturato"][data-anno="${annoCorrente}"] .cell-value`);
            if (fattCell) totals.fatturato += this.parseEuroValueSimple(fattCell.textContent);
            
            const unitCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_unita"][data-anno="${annoCorrente}"] .cell-value`);
            if (unitCell) totals.unita += parseInt(unitCell.textContent) || 0;
            
            const erogCell = document.querySelector(`[data-mese="${mese}"][data-field="erogato_importo"][data-anno="${annoCorrente}"] .cell-value`);
            if (erogCell) totals.erogato += this.parseEuroValueSimple(erogCell.textContent);
            
            const accCell = document.querySelector(`[data-mese="${mese}"][data-field="accantonamento_euro"][data-anno="${annoCorrente}"] .cell-value`);
            if (accCell) totals.accantonamento += this.parseEuroValueSimple(accCell.textContent);
            
            const taxCell = document.querySelector(`[data-mese="${mese}"][data-field="tasse_euro"][data-anno="${annoCorrente}"] .cell-value`);
            if (taxCell) totals.tasse += this.parseEuroValueSimple(taxCell.textContent);
            
            const mat1Cell = document.querySelector(`[data-mese="${mese}"][data-field="materia1_euro"][data-anno="${annoCorrente}"] .cell-value`);
            if (mat1Cell) totals.materia1 += this.parseEuroValueSimple(mat1Cell.textContent);
            
            const mat1UnitCell = document.querySelector(`[data-mese="${mese}"][data-field="materia1_unita"][data-anno="${annoCorrente}"] .cell-value`);
            if (mat1UnitCell) totals.materia1_unita += parseInt(mat1UnitCell.textContent) || 0;
            
            const spedCell = document.querySelector(`[data-mese="${mese}"][data-field="sped_euro"][data-anno="${annoCorrente}"] .cell-value`);
            if (spedCell) totals.sped += this.parseEuroValueSimple(spedCell.textContent);
            
            const spedUnitCell = document.querySelector(`[data-mese="${mese}"][data-field="sped_unita"][data-anno="${annoCorrente}"] .cell-value`);
            if (spedUnitCell) totals.sped_unita += parseInt(spedUnitCell.textContent) || 0;
            
            const varieCell = document.querySelector(`[data-mese="${mese}"][data-field="varie_euro"][data-anno="${annoCorrente}"] .cell-value`);
            if (varieCell) totals.varie += this.parseEuroValueSimple(varieCell.textContent);
        }
        
        // Calculate averages
        const unitaVendute = totals.unita || 1;
        
        // Update combined Total / Average cells (formato: "Totale / Unitario")
        if (el('total-avg-fatturato')) {
            el('total-avg-fatturato').textContent = 
                this.formatNumber(totals.fatturato, 2) + ' € / ' + 
                this.formatNumber(totals.fatturato / unitaVendute, 2) + ' €';
        }
        
        if (el('total-unita')) el('total-unita').textContent = totals.unita;
        
        if (el('total-avg-erogato')) {
            el('total-avg-erogato').textContent = 
                this.formatNumber(totals.erogato, 2) + ' € / ' + 
                this.formatNumber(totals.erogato / unitaVendute, 2) + ' €';
        }
        
        // Update percentage for TAX PLAFOND
        if (el('total-percent-accant') && totals.erogato > 0) {
            const percTaxPlafond = (totals.accantonamento / totals.erogato) * 100;
            el('total-percent-accant').textContent = this.formatNumber(percTaxPlafond, 2) + '%';
        }
        
        if (el('total-avg-accant')) {
            el('total-avg-accant').textContent = 
                this.formatNumber(totals.accantonamento, 2) + ' € / ' + 
                this.formatNumber(totals.accantonamento / unitaVendute, 2) + ' €';
        }
        
        if (el('total-avg-tax')) {
            el('total-avg-tax').textContent = 
                this.formatNumber(totals.tasse, 2) + ' € / ' + 
                this.formatNumber(totals.tasse / unitaVendute, 2) + ' €';
        }
        
        if (el('total-avg-materia1')) {
            el('total-avg-materia1').textContent = 
                this.formatNumber(totals.materia1, 2) + ' € / ' + 
                this.formatNumber(totals.materia1 / unitaVendute, 2) + ' €';
        }
        
        if (el('total-materia1-unita')) el('total-materia1-unita').textContent = totals.materia1_unita;
        
        if (el('total-avg-sped')) {
            el('total-avg-sped').textContent = 
                this.formatNumber(totals.sped, 2) + ' € / ' + 
                this.formatNumber(totals.sped / unitaVendute, 2) + ' €';
        }
        
        if (el('total-sped-unita')) el('total-sped-unita').textContent = totals.sped_unita;
        
        if (el('total-avg-varie')) {
            el('total-avg-varie').textContent = 
                this.formatNumber(totals.varie, 2) + ' € / ' + 
                this.formatNumber(totals.varie / unitaVendute, 2) + ' €';
        }
        
        // Calcola utile mensile per l'anno corrente
        this.calculateUtileMensile(annoCorrente);
    }
    
    updateAveragesUI() {
        // DEPRECATED: Questa funzione non è più necessaria.
        // I valori unitari sono ora gestiti direttamente da updateTotalsUI()
        // e da updateTotalsForYear() per gli anni clonati
    }
    
    updateKPIUI() {
        const kpi = this.data.kpi;
        
        Object.keys(kpi).forEach(category => {
            const data = kpi[category];
            const categoryName = category.replace('_', '-');
            
            // Update tabella gialla (KPI)
            const kpiPrefix = `kpi-${categoryName}`;
            const kpiTotaleEl = document.getElementById(`${kpiPrefix}-totale`);
            const kpiPerUnitaEl = document.getElementById(`${kpiPrefix}-per-unita`);
            const kpiPercFattEl = document.getElementById(`${kpiPrefix}-perc-fatt`);
            const kpiPercErogEl = document.getElementById(`${kpiPrefix}-perc-erog`);
            
            if (kpiTotaleEl) kpiTotaleEl.textContent = this.formatNumber(data.totale, 2) + ' €';
            if (kpiPerUnitaEl) kpiPerUnitaEl.textContent = this.formatNumber(data.per_unita, 2);
            if (kpiPercFattEl) kpiPercFattEl.textContent = this.formatNumber(data.perc_fatt, 2) + '%';
            if (kpiPercErogEl) kpiPercErogEl.textContent = this.formatNumber(data.perc_erog, 2) + '%';
            
            // Flow-cards sono popolate SOLO da updateGlobalKPIRow() con valori globali
            // NON popolarle qui per evitare di sovrascrivere con valori dell'anno corrente
        });
        
        // Update summary cards
        this.updateSummaryCards();
    }
    
    updateKPIGialli(totali) {
        // // console.log('💛 [KPI GIALLI] Aggiornamento tabella KPI gialla con totali:', totali);
        
        const el = (id) => document.getElementById(id);
        const fatturato = totali.fatturato || 0;
        const erogato = totali.erogato || 0;
        const unita = totali.unita_vendute || 0;
        const fba = -(fatturato - erogato); // FBA è un costo, quindi negativo
        
        // FATTURATO - Tabella gialla (solo anno corrente, verrà sovrascritto da updateGlobalKPIRow)
        if (el('kpi-fatturato-totale')) {
            el('kpi-fatturato-totale').textContent = this.formatNumber(fatturato, 2) + ' €';
            // // console.log('💛 [KPI GIALLI] FATTURATO (tabella) aggiornato:', fatturato);
        }
        // Flow card viene popolata da updateGlobalKPIRow() con somma di tutti gli anni
        
        // EROGATO - Tabella gialla (solo anno corrente, verrà sovrascritto da updateGlobalKPIRow)
        if (el('kpi-erogato-totale')) {
            el('kpi-erogato-totale').textContent = this.formatNumber(erogato, 2) + ' €';
            // // console.log('💛 [KPI GIALLI] EROGATO (tabella) aggiornato:', erogato);
        }
        // Flow card viene popolata da updateGlobalKPIRow() con somma di tutti gli anni
        
        // FBA - Tabella gialla (negativo perché è un costo)
        if (el('kpi-fba-totale')) {
            el('kpi-fba-totale').textContent = this.formatNumber(fba, 2) + ' €';
            // // console.log('💛 [KPI GIALLI] FBA (tabella) calcolato:', fba);
        }
        // Flow card viene popolata da updateGlobalKPIRow() con somma di tutti gli anni
        
        // Per-unità (solo tabella, flow cards popolate da updateGlobalKPIRow)
        if (unita > 0) {
            const fattPerUnita = fatturato / unita;
            const erogPerUnita = erogato / unita;
            const fbaPerUnita = fba / unita;
            
            if (el('kpi-fatturato-per-unita')) el('kpi-fatturato-per-unita').textContent = this.formatNumber(fattPerUnita, 2);
            if (el('kpi-erogato-per-unita')) el('kpi-erogato-per-unita').textContent = this.formatNumber(erogPerUnita, 2);
            if (el('kpi-fba-per-unita')) el('kpi-fba-per-unita').textContent = this.formatNumber(fbaPerUnita, 2);
        }
        
        // Percentuali (solo tabella, flow cards popolate da updateGlobalKPIRow)
        if (fatturato > 0) {
            if (el('kpi-fatturato-perc-fatt')) el('kpi-fatturato-perc-fatt').textContent = '100.00%';
            
            const erogPercFatt = (erogato / fatturato) * 100;
            const fbaPercFatt = (fba / fatturato) * 100;
            if (el('kpi-erogato-perc-fatt')) el('kpi-erogato-perc-fatt').textContent = this.formatNumber(erogPercFatt, 2) + '%';
            if (el('kpi-fba-perc-fatt')) el('kpi-fba-perc-fatt').textContent = this.formatNumber(fbaPercFatt, 2) + '%';
        }
        
        if (erogato > 0) {
            if (el('kpi-erogato-perc-erog')) el('kpi-erogato-perc-erog').textContent = '100.00%';
        }
        
        // // console.log('💛 [KPI GIALLI] Aggiornamento completato (tabella + flow-cards)');
    }
    
    updateSummaryCards() {
        const totali = this.data.totali;
        
        // Unità Acquistate (materia1_unita) - Popolato da updateGlobalKPIRow() con somma di tutti gli anni
        // const unitaAcquistateEl = document.getElementById('unita-acquistate');
        // if (unitaAcquistateEl) {
        //     unitaAcquistateEl.textContent = totali.tot_materia1_unita || 0;
        // }
        
        // Unità Spedite (sped_unita) - Popolato da updateGlobalKPIRow() con somma di tutti gli anni
        // const unitaSpediteEl = document.getElementById('unita-spedite');
        // if (unitaSpediteEl) {
        //     unitaSpediteEl.textContent = totali.tot_sped_unita || 0;
        // }
        
        // Unità Vendute (entrate_unita) - Popolato da updateGlobalKPIRow() con somma di tutti gli anni
        // const unitaVenditeEl = document.getElementById('unita-vendute');
        // if (unitaVenditeEl) {
        //     unitaVenditeEl.textContent = totali.tot_entrate_unita || 0;
        // }
        
        // Tax Plafond è popolato da updateGlobalKPIRow() con valori globali
        // NON calcolarlo qui con valori dell'anno corrente
        // const taxPlafondFinale = (totali.tot_accantonamento || 0) + (totali.tot_tasse || 0);
        // const taxPlafondEl = document.getElementById('tax-plafond');
        // if (taxPlafondEl) {
        //     taxPlafondEl.textContent = this.formatNumber(taxPlafondFinale, 2) + ' €';
        // }
        
        // G5 (tax-plafond-table) è popolato da updateGlobalKPIRow() con somma di tutti gli anni
        // const taxPlafondTableEl = document.getElementById('tax-plafond-table');
        // if (taxPlafondTableEl) {
        //     taxPlafondTableEl.textContent = this.formatNumber(taxPlafondFinale, 2) + ' €';
        // }
        
        // Utile Netto Atteso (utile_netto_totale)
        const utileNettoAttesoEl = document.getElementById('utile-netto-atteso');
        if (utileNettoAttesoEl) {
            utileNettoAttesoEl.textContent = this.formatNumber(totali.utile_netto_totale, 2) + ' €';
        }
    }
    
    async loadInitialData() {
        // // console.log('🚀🚀🚀 [INIT] INIZIO CARICAMENTO DATI INIZIALI');
        const anno = document.getElementById('anno').value;
        
        // DEBUG: Check existing has-data classes before processing
        const existingHasData = document.querySelectorAll('.cell-readonly.has-data');
        // console.log(`🔍 [INIT] Found ${existingHasData.length} cells with has-data class before processing`);
        existingHasData.forEach(cell => {
            // console.log(`🔍 [INIT] Existing has-data: Mese=${cell.dataset.mese}, Field=${cell.dataset.field}, Anno=${cell.dataset.anno}`);
        });
        
        // Add data-anno attribute to original table cells
        // // console.log(`📅 [INIT] Aggiunta attributo data-anno=${anno} alle celle originali...`);
        const originalCells = document.querySelectorAll('.rendiconto-table [data-field]');
        originalCells.forEach(cell => {
            if (!cell.dataset.anno) {
                cell.setAttribute('data-anno', anno);
            }
        });
        
        // // console.log('🚀🚀🚀 [INIT] Popolamento celle da transazioni...');
        await this.populateCellsFromTransactions(anno);
        
            // // console.log('🚀🚀🚀 [INIT] Caricamento fatturato da settlement per anno:', anno);
            await this.loadFatturatoFromSettlement(anno);
            
            await this.loadAndUpdateKPITop(anno);
        
        // Load and render additional years
        // // console.log('📅 [MULTI-YEAR] Caricamento altri anni...');
        await this.loadAndRenderOtherYears(anno);
        
        // Note: updateGlobalKPIRow() is already called inside loadAndRenderOtherYears()
        // No need to call it again here
        
        // DEBUG: Final check of all has-data cells
        const finalHasData = document.querySelectorAll('.cell-readonly.has-data');
        // console.log(`\n🏁 [FINAL] Total cells with has-data: ${finalHasData.length}`);
        const groupedByAnno = {};
        finalHasData.forEach(cell => {
            const anno = cell.dataset.anno || 'NO-ANNO';
            const mese = cell.dataset.mese || 'NO-MESE';
            const field = cell.dataset.field || 'NO-FIELD';
            const value = cell.querySelector('.cell-value')?.textContent || 'NO-VALUE';
            
            if (!groupedByAnno[anno]) groupedByAnno[anno] = [];
            groupedByAnno[anno].push({ mese, field, value });
        });
        
        Object.keys(groupedByAnno).sort().forEach(anno => {
            // console.log(`\n📅 Anno ${anno}: ${groupedByAnno[anno].length} celle con has-data`);
            groupedByAnno[anno].forEach(({ mese, field, value }) => {
                // console.log(`   Mese ${mese}, ${field}: ${value}`);
            });
        });
        
        // Applica stili ai valori negativi
        this.applyNegativeValueStyles();
        
        // // console.log('🚀🚀🚀 [INIT] DATI INIZIALI CARICATI');
    }
    
    async loadAndUpdateKPITop(anno) {
        // // console.log('📊 [KPI TOP] Caricamento KPI per anno:', anno);
        
        try {
            // 1. Carica unità vendute da settlement
            const responseVendute = await fetch(`?action=get_unita_vendute&anno=${anno}`);
            const resultVendute = await responseVendute.json();
            
            const unitaVendute = resultVendute.success ? resultVendute.unita_vendute : 0;
            // // console.log('📊 [KPI TOP] Unità vendute:', unitaVendute);
            
            // 2. Carica KPI da dati interni (unità acquistate/spedite)
            const totali = this.data.totali || {};
            const unitaAcquistate = totali.tot_materia1_unita || 0;
            const unitaSpedite = totali.tot_sped_unita || 0;
            
            // // console.log('📊 [KPI TOP] Unità acquistate:', unitaAcquistate);
            // // console.log('📊 [KPI TOP] Unità spedite:', unitaSpedite);
            
            // 3. Calcola TAX PLAFOND = (€ TAX PLAFOND totale) + (€ TAX totale)
            // Nota: tot_tasse è negativo, quindi la somma dà il valore corretto
            const taxPlafond = (totali.tot_accantonamento || 0) + (totali.tot_tasse || 0);
            // // console.log('📊 [KPI TOP] Tax Plafond:', taxPlafond);
            
            // 4. Calcola UTILE NETTO ATTESO
            // Formula: (Unità Acquistate - Unità Vendute) * Media Unitaria Utile Netto
            const giacenza = unitaAcquistate - unitaVendute;
            const mediaUnitariaUtile = unitaVendute > 0 ? (totali.tot_utile_netto || 0) / unitaVendute : 0;
            const utileNettoAtteso = giacenza * mediaUnitariaUtile;
            
            // // console.log('📊 [KPI TOP] Giacenza:', giacenza);
            // // console.log('📊 [KPI TOP] Media unitaria utile:', mediaUnitariaUtile);
            // // console.log('📊 [KPI TOP] Utile netto atteso:', utileNettoAtteso);
            
            // 5. NON aggiornare UI qui - le card vengono popolate da updateGlobalKPIRow() con valori globali
            // this.updateKPITopUI({
            //     unitaAcquistate,
            //     unitaSpedite,
            //     unitaVendute,
            //     taxPlafond,
            //     utileNettoAtteso
            // });
            
        } catch (error) {
            console.error('❌ [KPI TOP] Errore caricamento:', error);
        }
    }
    
    updateKPITopUI(kpi) {
        // Trova i box KPI nella UI
        const boxAcquistate = document.querySelector('[data-kpi="unita-acquistate"]');
        const boxSpedite = document.querySelector('[data-kpi="unita-spedite"]');
        const boxVendute = document.querySelector('[data-kpi="unita-vendute"]');
        const boxTaxPlafond = document.querySelector('[data-kpi="tax-plafond"]');
        const boxUtileAtteso = document.querySelector('[data-kpi="utile-atteso"]');
        
        if (boxAcquistate) boxAcquistate.textContent = kpi.unitaAcquistate;
        if (boxSpedite) boxSpedite.textContent = kpi.unitaSpedite;
        if (boxVendute) boxVendute.textContent = kpi.unitaVendute;
        if (boxTaxPlafond) boxTaxPlafond.textContent = this.formatNumber(kpi.taxPlafond, 2) + ' €';
        if (boxUtileAtteso) boxUtileAtteso.textContent = this.formatNumber(kpi.utileNettoAtteso, 2) + ' €';
        
        // // console.log('✅ [KPI TOP] UI aggiornata:', kpi);
    }
    
    async loadYear() {
        const anno = document.getElementById('anno').value;
        if (!anno) {
            this.showMessage('Seleziona un anno valido', 'warning');
            return;
        }
        
        if (!this.userId) {
            this.showMessage('Sessione scaduta, ricarica la pagina', 'error');
            return;
        }
        
        this.showLoading();
        
        try {
            // // console.log('🔍 [RENDICONTO] Loading year:', anno);
            
            // First, load from rendiconto database
            const response = await fetch(`?action=load&anno=${anno}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            // // console.log('📊 [RENDICONTO] Data from database:', result);
            // // console.log('📊 [RENDICONTO] result.documento:', result.documento);
            // // console.log('📊 [RENDICONTO] result.documento?.righe:', result.documento?.righe);
            
            if (result.success) {
                // Normalizza struttura dati dal server
                if (result.documento) {
                    this.data.documento = result.documento.documento || {};
                    this.data.righe = result.documento.righe || {};
                } else {
                    this.data.documento = {};
                    this.data.righe = {};
                }
                
                // Assicurati che righe sia sempre un oggetto con tutti i 12 mesi
                for (let mese = 1; mese <= 12; mese++) {
                    if (!this.data.righe[mese]) {
                        this.data.righe[mese] = {
                            mese: mese,
                            entrate_fatturato: 0,
                            entrate_unita: 0,
                            erogato_importo: 0,
                            accantonamento_percentuale: 0,
                            accantonamento_euro: 0,
                            tasse_euro: 0,
                            diversi_euro: 0,
                            materia1_euro: 0,
                            materia1_unita: 0,
                            sped_euro: 0,
                            sped_unita: 0,
                            varie_euro: 0,
                            utile_netto_mese: 0
                        };
                    }
                }
                
                // Popola celle da transazioni
                await this.populateCellsFromTransactions(anno);
                
                // Calcola totali e KPI
                this.calculateTotalsAndKPIs();
                
                // Now fetch fatturato from settlement
                // // console.log('💰 [SETTLEMENT] Fetching fatturato data for:', anno);
                await this.loadFatturatoFromSettlement(anno);
                
                // Update KPI top
                await this.loadAndUpdateKPITop(anno);
                
                this.showMessage(`Dati per l'anno ${anno} caricati con successo`, 'success');
            } else {
                // Gestione caso nessun dato (primo caricamento)
                if (result.error && result.error.includes('non trovato')) {
                    // // console.log('⚠️ [RENDICONTO] No data found, loading settlement data anyway');
                    this.clearForm();
                    
                    // Inizializza righe vuote per tutti i mesi
                    for (let mese = 1; mese <= 12; mese++) {
                        this.data.righe[mese] = {
                            mese: mese,
                            entrate_fatturato: 0,
                            entrate_unita: 0,
                            erogato_importo: 0,
                            accantonamento_percentuale: 0,
                            accantonamento_euro: 0,
                            tasse_euro: 0,
                            diversi_euro: 0,
                            materia1_euro: 0,
                            materia1_unita: 0,
                            sped_euro: 0,
                            sped_unita: 0,
                            varie_euro: 0,
                            utile_netto_mese: 0
                        };
                    }
                    
                    // Popola celle da transazioni
                    await this.populateCellsFromTransactions(anno);
                    
                    // Still try to load fatturato from settlement
                    await this.loadFatturatoFromSettlement(anno);
                    
                    this.showMessage(`Nessun dato trovato per l'anno ${anno}. Crea nuovo rendiconto inserendo i dati.`, 'info');
                } else {
                    this.showMessage('Errore nel caricamento: ' + (result.error || 'Errore sconosciuto'), 'error');
                }
            }
        } catch (error) {
            console.error('❌ [RENDICONTO] Load error:', error);
            this.showMessage('Errore di connessione: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }
    
    async loadFatturatoFromSettlement(anno) {
        // // console.log('💰💰💰 [SETTLEMENT] ========== INIZIO FETCH FATTURATO ==========');
        // // console.log('💰 [SETTLEMENT] Anno richiesto:', anno);
        // // console.log('💰 [SETTLEMENT] URL:', `?action=get_fatturato_settlement&anno=${anno}`);
        
        try {
            const response = await fetch(`?action=get_fatturato_settlement&anno=${anno}`);
            // // console.log('💰 [SETTLEMENT] Response status:', response.status, response.statusText);
            
            if (!response.ok) {
                console.error('❌❌❌ [SETTLEMENT] Failed to fetch! Status:', response.status);
                const errorText = await response.text();
                console.error('❌ [SETTLEMENT] Error response:', errorText);
                return;
            }
            
            const result = await response.json();
            // // console.log('💰💰💰 [SETTLEMENT] Response JSON:', JSON.stringify(result, null, 2));
            
            if (result.success && result.dati_mensili) {
                // // console.log('✅✅✅ [SETTLEMENT] SUCCESS! Processing monthly data...');
                // // console.log('📊 [SETTLEMENT] Numero mesi ricevuti:', Object.keys(result.dati_mensili).length);
                
                let inputsTrovati = 0;
                let inputsAggiornati = 0;
                
                // Update fatturato, unita_vendute and erogato for each month
                Object.values(result.dati_mensili).forEach(datiMese => {
                    const mese = datiMese.mese;
                    const fatturato = datiMese.fatturato;
                    const unita = datiMese.unita_vendute;
                    const erogato = datiMese.erogato || 0;
                    
                    // // console.log(`📅📅📅 [SETTLEMENT] Mese ${mese}: FATTURATO=€${fatturato}, UNITA=${unita}, EROGATO=€${erogato}`);
                    
                    // Find cell-readonly elements and update them
                    const fatturatoCell = document.querySelector(`.cell-readonly[data-mese="${mese}"][data-field="entrate_fatturato"]`);
                    const unitaCell = document.querySelector(`.cell-readonly[data-mese="${mese}"][data-field="entrate_unita"]`);
                    const erogatoCell = document.querySelector(`.cell-readonly[data-mese="${mese}"][data-field="erogato_importo"]`);
                    
                    // // console.log(`🔍 [SETTLEMENT] Cell fatturato mese ${mese}:`, fatturatoCell ? 'TROVATO' : '❌ NON TROVATO');
                    // // console.log(`🔍 [SETTLEMENT] Cell unita mese ${mese}:`, unitaCell ? 'TROVATO' : '❌ NON TROVATO');
                    // // console.log(`🔍 [SETTLEMENT] Cell erogato mese ${mese}:`, erogatoCell ? 'TROVATO' : '❌ NON TROVATO');
                    
                    if (fatturatoCell) {
                        inputsTrovati++;
                        const valueSpan = fatturatoCell.querySelector('.cell-value');
                        if (valueSpan) {
                            const oldValue = valueSpan.textContent;
                            valueSpan.textContent = this.formatNumber(fatturato, 2) + ' €';
                        // // console.log(`✏️✏️✏️ [SETTLEMENT] Mese ${mese} - FATTURATO aggiornato da ${oldValue} a ${fatturato}`);
                        inputsAggiornati++;
                        }
                    } else {
                        console.error(`❌❌❌ [SETTLEMENT] Mese ${mese} - Cell fatturato NON TROVATO!`);
                    }
                    
                    if (unitaCell) {
                        const valueSpan = unitaCell.querySelector('.cell-value');
                        if (valueSpan) {
                            const oldValue = valueSpan.textContent;
                            valueSpan.textContent = unita;
                        // // console.log(`✏️✏️✏️ [SETTLEMENT] Mese ${mese} - UNITA aggiornate da ${oldValue} a ${unita}`);
                        }
                    } else {
                        console.error(`❌❌❌ [SETTLEMENT] Mese ${mese} - Cell unita NON TROVATO!`);
                    }
                    
                    if (erogatoCell) {
                        const valueSpan = erogatoCell.querySelector('.cell-value');
                        if (valueSpan) {
                            const oldValue = valueSpan.textContent;
                            valueSpan.textContent = this.formatNumber(erogato, 2) + ' €';
                        // // console.log(`✏️✏️✏️ [SETTLEMENT] Mese ${mese} - EROGATO aggiornato da ${oldValue} a ${erogato}`);
                        }
                    } else {
                        console.error(`❌❌❌ [SETTLEMENT] Mese ${mese} - Cell erogato NON TROVATO!`);
                    }
                    
                    // Update internal data
                    if (!this.data.righe[mese]) {
                        this.data.righe[mese] = { mese: mese };
                    }
                    this.data.righe[mese].entrate_fatturato = fatturato;
                    this.data.righe[mese].entrate_unita = unita;
                    this.data.righe[mese].erogato_importo = erogato;
                    // // console.log(`💾 [SETTLEMENT] Mese ${mese} - Dati interni aggiornati`);
                });
                
                // // console.log(`📊 [SETTLEMENT] RIEPILOGO: ${inputsTrovati} input trovati, ${inputsAggiornati} aggiornati`);
                
                // Recalculate totals and KPIs with new data
                // // console.log('🔄 [SETTLEMENT] Ricalcolo totali e KPI...');
                this.calculateTotalsAndKPIs();
                
                // Update KPI gialli (tabella in alto) con i totali del periodo
                // // console.log('💛💛💛 [KPI GIALLI] Aggiornamento KPI tabella gialla...');
                this.updateKPIGialli(result.totali);
                
                // // console.log('✅✅✅ [SETTLEMENT] ========== COMPLETATO CON SUCCESSO ==========');
                // // console.log('📊 [SETTLEMENT] Totali finali:', result.totali);
            } else {
                console.error('❌❌❌ [SETTLEMENT] Response non valida!');
                console.error('❌ [SETTLEMENT] result.success:', result.success);
                console.error('❌ [SETTLEMENT] result.error:', result.error);
                console.error('❌ [SETTLEMENT] result.dati_mensili:', result.dati_mensili);
            }
        } catch (error) {
            console.error('❌❌❌ [SETTLEMENT] ========== ERRORE CRITICO ==========');
            console.error('❌ [SETTLEMENT] Tipo errore:', error.constructor.name);
            console.error('❌ [SETTLEMENT] Messaggio:', error.message);
            console.error('❌ [SETTLEMENT] Stack:', error.stack);
        }
    }
    
    async duplicateYear() {
        if (!this.userId) {
            this.showMessage('Sessione scaduta, ricarica la pagina', 'error');
            return;
        }
        
        const sourceAnno = document.getElementById('anno').value;
        const targetAnno = prompt('Inserisci l\'anno di destinazione:');
        
        if (!targetAnno || !sourceAnno) {
            this.showMessage('Operazione annullata', 'info');
            return;
        }
        
        // Validazioni
        if (sourceAnno === targetAnno) {
            this.showMessage('L\'anno di origine e destinazione devono essere diversi', 'error');
            return;
        }
        
        if (!targetAnno.match(/^\d{4}$/)) {
            this.showMessage('L\'anno deve essere nel formato YYYY (es: 2024)', 'error');
            return;
        }
        
        const currentYear = new Date().getFullYear();
        if (parseInt(targetAnno) < 2020 || parseInt(targetAnno) > currentYear + 5) {
            this.showMessage('L\'anno deve essere tra 2020 e ' + (currentYear + 5), 'error');
            return;
        }
        
        this.showLoading();
        
        try {
            const formData = new FormData();
            formData.append('source_anno', sourceAnno);
            formData.append('target_anno', targetAnno);
            
            const response = await fetch('?action=duplicate', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showMessage(`Anno ${targetAnno} creato con successo`, 'success');
                
                // Switch to the new year
                document.getElementById('anno').value = targetAnno;
                this.loadYear();
            } else {
                this.showMessage('Errore nella duplicazione: ' + (result.error || 'Errore sconosciuto'), 'error');
            }
        } catch (error) {
            this.showMessage('Errore di rete: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }
    
    populateForm() {
        if (!this.data.righe) return;
        
        // Update document info
        if (this.data.documento) {
            document.getElementById('anno').value = this.data.documento.anno || document.getElementById('anno').value;
            // Update documento-id only if exists (desktop only)
            const docIdEl = document.getElementById('documento-id');
            if (docIdEl) {
                docIdEl.value = this.data.documento.id || '';
            }
        }
        
        // Update form fields
        for (let mese = 1; mese <= 12; mese++) {
            const riga = this.data.righe[mese];
            if (riga) {
                const fields = [
                    'data', 'entrate_fatturato', 'entrate_unita', 'erogato_importo',
                    'accantonamento_percentuale', 'accantonamento_euro', 'tasse_euro',
                    'diversi_euro', 'materia1_euro', 'materia1_unita', 'sped_euro',
                    'sped_unita', 'varie_euro', 'utile_netto_mese'
                ];
                
                fields.forEach(field => {
                    const input = document.querySelector(`input[name="${field}_${mese}"]`);
                    if (input && riga[field] !== undefined && riga[field] !== null) {
                        if (field === 'data') {
                            input.value = riga[field] || '';
                        } else if (['entrate_unita', 'materia1_unita', 'sped_unita'].includes(field)) {
                            input.value = riga[field] || 0;
                        } else {
                            input.value = this.formatNumber(riga[field], 2);
                        }
                    }
                });
                
                // Update internal data
                this.data.righe[mese] = { ...riga };
            }
        }
    }

    clearForm() {
        // Reset internal data
        this.data = {
            documento: {},
            righe: {},
            totali: {},
            kpi: {}
        };
        
        // Clear all form inputs
        for (let mese = 1; mese <= 12; mese++) {
            const fields = [
                'data', 'entrate_fatturato', 'entrate_unita', 'erogato_importo',
                'accantonamento_percentuale', 'accantonamento_euro', 'tasse_euro',
                'diversi_euro', 'materia1_euro', 'materia1_unita', 'sped_euro',
                'sped_unita', 'varie_euro', 'utile_netto_mese'
            ];
            
            fields.forEach(field => {
                const input = document.querySelector(`input[name="${field}_${mese}"]`);
                if (input) {
                    if (field === 'data') {
                        input.value = '';
                    } else if (['entrate_unita', 'materia1_unita', 'sped_unita'].includes(field)) {
                        input.value = '0';
                    } else {
                        input.value = '0.00';
                    }
                }
            });
            
            // Reset internal data for this month
            this.data.righe[mese] = {
                mese: mese,
                data: null,
                entrate_fatturato: 0,
                entrate_unita: 0,
                erogato_importo: 0,
                accantonamento_percentuale: 0,
                accantonamento_euro: 0,
                tasse_euro: 0,
                diversi_euro: 0,
                materia1_euro: 0,
                materia1_unita: 0,
                sped_euro: 0,
                sped_unita: 0,
                varie_euro: 0,
                utile_netto_mese: 0
            };
        }
        
        // Clear document ID (only if exists - desktop only)
        const docIdEl = document.getElementById('documento-id');
        if (docIdEl) {
            docIdEl.value = '';
        }
        
        // Recalculate (will be all zeros)
        this.calculateTotalsAndKPIs();
    }
    
    showLoading() {
        document.getElementById('loading').classList.remove('hidden');
    }
    
    hideLoading() {
        document.getElementById('loading').classList.add('hidden');
    }
    
    showMessage(message, type = 'info') {
        const messagesContainer = document.getElementById('messages');
        
        // Remove existing messages of the same type
        const existingMessages = messagesContainer.querySelectorAll(`.message.${type}`);
        existingMessages.forEach(msg => msg.remove());
        
        const messageEl = document.createElement('div');
        messageEl.className = `message ${type}`;
        messageEl.innerHTML = `
            <span class="message-text">${message}</span>
            <button class="message-close" onclick="this.parentElement.remove()">×</button>
        `;
        
        messagesContainer.appendChild(messageEl);
        
        // Auto-remove after different times based on type
        const autoRemoveTime = {
            'success': 3000,
            'info': 4000,
            'warning': 5000,
            'error': 7000
        }[type] || 5000;
        
        setTimeout(() => {
            if (messageEl.parentNode) {
                messageEl.parentNode.removeChild(messageEl);
            }
        }, autoRemoveTime);
        
        // Log per debug (solo in development)
        if (window.location.hostname === 'localhost') {
            // // console.log(`[${type.toUpperCase()}] ${message}`);
        }
    }
    
    roundHalfUp(num, decimals) {
        const multiplier = Math.pow(10, decimals);
        return Math.round((num + Number.EPSILON) * multiplier) / multiplier;
    }
    
    /**
     * Load and render tables for other years
     */
    async loadAndRenderOtherYears(currentAnno) {
        try {
            // Get list of available years
            const response = await fetch('?action=get_available_years');
            const result = await response.json();
            
            if (!result.success || !result.years || result.years.length === 0) {
                // // console.log('📅 [MULTI-YEAR] Nessun altro anno disponibile. Result:', result);
                
                // ✅ FIX: Aggiorna KPI globali anche se l'API non ritorna anni
                // Questo garantisce che le flow cards vengano popolate con i dati dell'anno corrente
                this.updateGlobalKPIRow();
                
                return;
            }
            
            // // console.log('📅 [MULTI-YEAR] Anni disponibili:', result.years);
            
            // Filter out current year
            const otherYears = result.years.filter(y => parseInt(y) !== parseInt(currentAnno));
            
            // // console.log('📅 [MULTI-YEAR] Altri anni (escluso corrente):', otherYears);
            
            if (otherYears.length === 0) {
                // // console.log('📅 [MULTI-YEAR] Solo anno corrente disponibile');
                
                // ✅ FIX: Aggiorna KPI globali anche se c'è solo l'anno corrente
                // Questa chiamata è necessaria per popolare le flow cards
                this.updateGlobalKPIRow();
                
                return;
            }
            
            // Find the original table section to clone
            // The section goes from "Anno Corrente" comment to the end of TOT/U row
            const mainTable = document.querySelector('.rendiconto-table');
            if (!mainTable) {
                console.error('❌ [MULTI-YEAR] Tabella principale non trovata');
                return;
            }
            
            // Find the rows to clone (from year header to Totale / Unitario)
            const rows = Array.from(mainTable.querySelectorAll('tbody tr'));
            
            const yearHeaderIndex = rows.findIndex(row => row.querySelector('.year-header'));
            
            const totUIndex = rows.findIndex(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length === 0) return false;
                
                // Check first few cells for "Totale / Unitario" (riga unificata)
                for (let i = 0; i < Math.min(3, cells.length); i++) {
                    const text = cells[i].textContent.trim();
                    if (text === 'Totale / Unitario' || text.includes('Totale / Unitario')) {
                        return true;
                    }
                }
                return false;
            });
            
            if (yearHeaderIndex === -1 || totUIndex === -1) {
                console.error('❌ [MULTI-YEAR] Impossibile trovare sezione anno');
                return;
            }
            
            // // console.log(`📅 [MULTI-YEAR] Sezione anno: righe ${yearHeaderIndex} - ${totUIndex}`);
            
            // Get the rows to clone
            const rowsToClone = rows.slice(yearHeaderIndex, totUIndex + 1);
            
            // Find insertion point (after Totale / Unitario of current year)
            const tbody = mainTable.querySelector('tbody');
            let insertAfter = rows[totUIndex];
            
            // Clone and insert for each other year
            for (const anno of otherYears) {
                // // console.log(`📅 [MULTI-YEAR] Clonazione tabella per anno ${anno}`);
                
                // Clone all rows
                const clonedRows = rowsToClone.map(row => {
                    const cloned = row.cloneNode(true);
                    
                    // Update year in header
                    const yearHeaderCell = cloned.querySelector('.year-header span');
                    if (yearHeaderCell) {
                        const currentText = yearHeaderCell.textContent;
                        yearHeaderCell.textContent = currentText.replace(/\d{4}/, anno);
                    }
                    
                    // Update dates in month rows
                    const dateCell = cloned.querySelector('td.left');
                    if (dateCell && /\d{2}\/\d{4}/.test(dateCell.textContent)) {
                        const currentDate = dateCell.textContent;
                        dateCell.textContent = currentDate.replace(/\/\d{4}/, `/${anno}`);
                    }
                    
                    // Add data-anno attribute to all cells with data-field for tooltips
                    const cellsWithField = cloned.querySelectorAll('[data-field]');
                    cellsWithField.forEach(cell => {
                        cell.setAttribute('data-anno', anno);
                    });
                    
                    // Update IDs to avoid duplicates
                    const elementsWithId = cloned.querySelectorAll('[id]');
                    elementsWithId.forEach(el => {
                        el.id = `${el.id}-${anno}`;
                    });
                    
                    // Reset cell values AND remove has-data class from cloned cells
                    const cellValues = cloned.querySelectorAll('.cell-value');
                    cellValues.forEach(span => {
                        const parent = span.closest('[data-field]');
                        const field = parent?.dataset.field;
                        
                        // Remove has-data class from parent cell
                        parent?.classList.remove('has-data');
                        
                        if (field?.includes('percentuale')) {
                            span.textContent = '0.00%';
                        } else if (field?.includes('unita')) {
                            span.textContent = '0';
                        } else {
                            span.textContent = '0.00 €';
                        }
                    });
                    
                    return cloned;
                });
                
                // Add spacing row before new year section
                const spacingRow = document.createElement('tr');
                spacingRow.innerHTML = '<td colspan="16"></td>';
                tbody.insertBefore(spacingRow, insertAfter.nextSibling);
                insertAfter = spacingRow;
                
                // Insert all cloned rows after the spacing row
                clonedRows.forEach(clonedRow => {
                    tbody.insertBefore(clonedRow, insertAfter.nextSibling);
                    insertAfter = clonedRow; // Update insertion point for next row
                });
                
                // // console.log(`📅 [MULTI-YEAR] Tabella clonata per anno ${anno}, caricamento dati...`);
                
                // Load data for this year
                await this.loadDataForClonedYear(anno);
            }
            
            // Re-bind tooltips for all cells (including cloned ones)
            this.bindCellTooltips();
            
            // Update global KPI row (yellow header) with sum of all years
            this.updateGlobalKPIRow();
            
            // Apply negative value styles to KPI rows
            this.applyNegativeValueStyles();
            
            // // console.log('✅ [MULTI-YEAR] Tutte le tabelle sono state clonate e popolate');
            
        } catch (error) {
            console.error('❌ [MULTI-YEAR] Errore:', error);
        }
    }
    
    /**
     * Load data for a cloned year table
     */
    async loadDataForClonedYear(anno) {
        // // console.log(`📊 [YEAR-${anno}] Caricamento dati...`);
        
        try {
            // Load settlement data (FATTURATO, UNITA, EROGATO)
            const responseSettlement = await fetch(`?action=get_fatturato_settlement&anno=${anno}`);
            const resultSettlement = await responseSettlement.json();
            
            if (resultSettlement.success && resultSettlement.dati_mensili) {
                Object.values(resultSettlement.dati_mensili).forEach(datiMese => {
                    const mese = datiMese.mese;
                    const fatturato = datiMese.fatturato;
                    const unita = datiMese.unita_vendute;
                    const erogato = datiMese.erogato || 0;
                    
                    // Find cells using data-anno and data-mese attributes
                    const fattCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_fatturato"][data-anno="${anno}"] .cell-value`);
                    const unitCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_unita"][data-anno="${anno}"] .cell-value`);
                    const erogCell = document.querySelector(`[data-mese="${mese}"][data-field="erogato_importo"][data-anno="${anno}"] .cell-value`);
                    
                    if (fattCell) {
                        fattCell.textContent = this.formatNumber(fatturato, 2) + ' €';
                    }
                    if (unitCell) {
                        unitCell.textContent = unita;
                    }
                    if (erogCell) {
                        erogCell.textContent = this.formatNumber(erogato, 2) + ' €';
                        // Add has-data class if erogato > 0 (solo positivi per erogato)
                        // console.log(`🔴 [HAS-DATA CHECK] Anno=${anno}, Mese=${mese}, Erogato=${erogato}, Will add has-data: ${erogato > 0}`);
                        if (erogato > 0) {
                            const cellElement = erogCell.closest('.cell-readonly');
                            // console.log(`✅ [HAS-DATA ADD] Adding to erogato cell - Anno=${anno}, Mese=${mese}, Cell:`, cellElement);
                            cellElement?.classList.add('has-data');
                        }
                    }
                });
                
                // // console.log(`✅ [YEAR-${anno}] Settlement data loaded`);
            }
            
            // Load user transactions
            const responseTransactions = await fetch(`?action=get_input_utente&anno=${anno}`);
            const resultTransactions = await responseTransactions.json();
            
            if (resultTransactions.success && resultTransactions.data) {
                // Similar logic as populateCellsFromTransactions but for cloned rows
                const aggregati = {};
                resultTransactions.data.forEach(trans => {
                    const mese = trans.mese;
                    const tipo = trans.tipo_input;
                    
                    if (!aggregati[mese]) aggregati[mese] = {};
                    if (!aggregati[mese][tipo]) {
                        aggregati[mese][tipo] = { importo: 0, quantita: 0 };
                    }
                    
                    aggregati[mese][tipo].importo += parseFloat(trans.importo) || 0;
                    aggregati[mese][tipo].quantita += parseInt(trans.quantita) || 0;
                });
                
                // Update cells
                const tipoToField = {
                    'accantonamento_percentuale': 'accantonamento_percentuale',
                    'accantonamento_euro': 'accantonamento_euro',
                    'tasse_pagamento': 'tasse_euro',
                    'materia_prima_acquisto': ['materia1_euro', 'materia1_unita'],
                    'spedizioni_acquisto': ['sped_euro', 'sped_unita'],
                    'spese_varie': 'varie_euro'
                };
                
                for (let mese = 1; mese <= 12; mese++) {
                    if (!aggregati[mese]) continue;
                    
                    Object.keys(aggregati[mese]).forEach(tipo => {
                        const mapping = tipoToField[tipo];
                        if (!mapping) return;
                        
                        if (Array.isArray(mapping)) {
                            const [euroField, unitaField] = mapping;
                            // Find cells using data-anno and data-mese
                            const euroCell = document.querySelector(`[data-mese="${mese}"][data-field="${euroField}"][data-anno="${anno}"] .cell-value`);
                            const unitaCell = document.querySelector(`[data-mese="${mese}"][data-field="${unitaField}"][data-anno="${anno}"] .cell-value`);
                            
                            if (euroCell) {
                                const importo = aggregati[mese][tipo].importo;
                                euroCell.textContent = this.formatNumber(importo, 2) + ' €';
                                // Add has-data se importo != 0 (include negativi, utili per note)
                                // console.log(`🔴 [HAS-DATA CHECK] Anno=${anno}, Mese=${mese}, Tipo=${tipo}, Importo=${importo}, Will add: ${importo !== 0}`);
                                if (importo !== 0) {
                                    const cellElement = euroCell.closest('.cell-readonly');
                                    // console.log(`✅ [HAS-DATA ADD] Adding to ${tipo} euro - Anno=${anno}, Mese=${mese}, Cell:`, cellElement);
                                    cellElement?.classList.add('has-data');
                                }
                            }
                            if (unitaCell) {
                                const quantita = aggregati[mese][tipo].quantita;
                                unitaCell.textContent = quantita;
                                // console.log(`🔴 [HAS-DATA CHECK] Anno=${anno}, Mese=${mese}, Tipo=${tipo}, Quantita=${quantita}, Will add: ${quantita !== 0}`);
                                if (quantita !== 0) {
                                    const cellElement = unitaCell.closest('.cell-readonly');
                                    // console.log(`✅ [HAS-DATA ADD] Adding to ${tipo} unita - Anno=${anno}, Mese=${mese}, Cell:`, cellElement);
                                    cellElement?.classList.add('has-data');
                                }
                            }
                        } else {
                            // Find cell using data-anno and data-mese
                            const cell = document.querySelector(`[data-mese="${mese}"][data-field="${mapping}"][data-anno="${anno}"] .cell-value`);
                            if (cell) {
                                const value = aggregati[mese][tipo].quantita > 0 
                                    ? aggregati[mese][tipo].quantita 
                                    : aggregati[mese][tipo].importo;
                                
                                if (mapping.includes('percentuale')) {
                                    cell.textContent = this.formatNumber(value, 2) + '%';
                                } else if (mapping.includes('unita')) {
                                    cell.textContent = value;
                                } else {
                                    cell.textContent = this.formatNumber(value, 2) + ' €';
                                }
                                
                                // console.log(`🔴 [HAS-DATA CHECK] Anno=${anno}, Mese=${mese}, Tipo=${tipo}, Field=${mapping}, Value=${value}, Will add: ${value !== 0}`);
                                if (value !== 0) {
                                    const cellElement = cell.closest('.cell-readonly');
                                    // console.log(`✅ [HAS-DATA ADD] Adding to ${mapping} - Anno=${anno}, Mese=${mese}, Cell:`, cellElement);
                                    cellElement?.classList.add('has-data');
                                }
                            }
                        }
                    });
                }
                
                // // console.log(`✅ [YEAR-${anno}] Transactions loaded`);
            }
            
            // Calculate and update totals for this year
            this.updateTotalsForYear(anno);
            
            // Aggiorna le percentuali TAX PLAFOND per questo anno
            this.updateTaxPlafondPercentagesForClonedYear(anno);
            
        } catch (error) {
            console.error(`❌ [YEAR-${anno}] Errore caricamento dati:`, error);
        }
    }
    
    /**
     * Calculate and update totals row for a specific year
     */
    updateTotalsForYear(anno) {
        // // console.log(`🧮 [YEAR-${anno}] Calcolo totali...`);
        
        let totals = {
            fatturato: 0,
            unita: 0,
            erogato: 0,
            accantonamento: 0,
            tasse: 0,
            materia1: 0,
            materia1_unita: 0,
            sped: 0,
            sped_unita: 0,
            varie: 0
        };
        
        // Sum values from all 12 months
        for (let mese = 1; mese <= 12; mese++) {
            // FATTURATO
            const fattCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_fatturato"][data-anno="${anno}"] .cell-value`);
            if (fattCell) {
                const value = this.parseEuroValueSimple(fattCell.textContent);
                totals.fatturato += value;
            }
            
            // UNITA
            const unitCell = document.querySelector(`[data-mese="${mese}"][data-field="entrate_unita"][data-anno="${anno}"] .cell-value`);
            if (unitCell) {
                totals.unita += parseInt(unitCell.textContent) || 0;
            }
            
            // EROGATO
            const erogCell = document.querySelector(`[data-mese="${mese}"][data-field="erogato_importo"][data-anno="${anno}"] .cell-value`);
            if (erogCell) {
                const value = this.parseEuroValueSimple(erogCell.textContent);
                totals.erogato += value;
            }
            
            // ACCANTONAMENTO
            const accCell = document.querySelector(`[data-mese="${mese}"][data-field="accantonamento_euro"][data-anno="${anno}"] .cell-value`);
            if (accCell) {
                const value = this.parseEuroValueSimple(accCell.textContent);
                totals.accantonamento += value;
            }
            
            // TASSE
            const taxCell = document.querySelector(`[data-mese="${mese}"][data-field="tasse_euro"][data-anno="${anno}"] .cell-value`);
            if (taxCell) {
                const value = this.parseEuroValueSimple(taxCell.textContent);
                totals.tasse += value;
            }
            
            // MATERIA 1
            const mat1Cell = document.querySelector(`[data-mese="${mese}"][data-field="materia1_euro"][data-anno="${anno}"] .cell-value`);
            if (mat1Cell) {
                const value = this.parseEuroValueSimple(mat1Cell.textContent);
                totals.materia1 += value;
            }
            
            const mat1UnitCell = document.querySelector(`[data-mese="${mese}"][data-field="materia1_unita"][data-anno="${anno}"] .cell-value`);
            if (mat1UnitCell) {
                totals.materia1_unita += parseInt(mat1UnitCell.textContent) || 0;
            }
            
            // SPEDIZIONE
            const spedCell = document.querySelector(`[data-mese="${mese}"][data-field="sped_euro"][data-anno="${anno}"] .cell-value`);
            if (spedCell) {
                const value = this.parseEuroValueSimple(spedCell.textContent);
                totals.sped += value;
            }
            
            const spedUnitCell = document.querySelector(`[data-mese="${mese}"][data-field="sped_unita"][data-anno="${anno}"] .cell-value`);
            if (spedUnitCell) {
                totals.sped_unita += parseInt(spedUnitCell.textContent) || 0;
            }
            
            // VARIE
            const varieCell = document.querySelector(`[data-mese="${mese}"][data-field="varie_euro"][data-anno="${anno}"] .cell-value`);
            if (varieCell) {
                const value = this.parseEuroValueSimple(varieCell.textContent);
                totals.varie += value;
            }
        }
        
        // Update totals row
        const totalFattEl = document.querySelector(`#total-fatturato-${anno}`);
        if (totalFattEl) totalFattEl.textContent = this.formatNumber(totals.fatturato, 2) + ' €';
        
        const totalUnitEl = document.querySelector(`#total-unita-${anno}`);
        if (totalUnitEl) totalUnitEl.textContent = totals.unita;
        
        const totalErogEl = document.querySelector(`#total-erogato-${anno}`);
        if (totalErogEl) totalErogEl.textContent = this.formatNumber(totals.erogato, 2) + ' €';
        
        const totalAccEl = document.querySelector(`#total-accant-${anno}`);
        if (totalAccEl) totalAccEl.textContent = this.formatNumber(totals.accantonamento, 2) + ' €';
        
        const totalTaxEl = document.querySelector(`#total-tax-${anno}`);
        if (totalTaxEl) totalTaxEl.textContent = this.formatNumber(totals.tasse, 2) + ' €';
        
        const totalMat1El = document.querySelector(`#total-materia1-${anno}`);
        if (totalMat1El) totalMat1El.textContent = this.formatNumber(totals.materia1, 2) + ' €';
        
        const totalMat1UnitEl = document.querySelector(`#total-materia1-unita-${anno}`);
        if (totalMat1UnitEl) totalMat1UnitEl.textContent = totals.materia1_unita;
        
        const totalSpedEl = document.querySelector(`#total-sped-${anno}`);
        if (totalSpedEl) totalSpedEl.textContent = this.formatNumber(totals.sped, 2) + ' €';
        
        const totalSpedUnitEl = document.querySelector(`#total-sped-unita-${anno}`);
        if (totalSpedUnitEl) totalSpedUnitEl.textContent = totals.sped_unita;
        
        // Update combined Total / Average cells (formato: "Totale / Unitario")
        const unitaVendute = totals.unita || 1;
        
        const totalAvgFattEl = document.querySelector(`#total-avg-fatturato-${anno}`);
        if (totalAvgFattEl) {
            totalAvgFattEl.textContent = 
                this.formatNumber(totals.fatturato, 2) + ' € / ' + 
                this.formatNumber(totals.fatturato / unitaVendute, 2) + ' €';
        }
        
        const totalAvgErogEl = document.querySelector(`#total-avg-erogato-${anno}`);
        if (totalAvgErogEl) {
            totalAvgErogEl.textContent = 
                this.formatNumber(totals.erogato, 2) + ' € / ' + 
                this.formatNumber(totals.erogato / unitaVendute, 2) + ' €';
        }
        
        // Update percentage for TAX PLAFOND
        const totalPercEl = document.querySelector(`#total-percent-accant-${anno}`);
        if (totalPercEl && totals.erogato > 0) {
            const percTaxPlafond = (totals.accantonamento / totals.erogato) * 100;
            totalPercEl.textContent = this.formatNumber(percTaxPlafond, 2) + '%';
        }
        
        const totalAvgAccEl = document.querySelector(`#total-avg-accant-${anno}`);
        if (totalAvgAccEl) {
            totalAvgAccEl.textContent = 
                this.formatNumber(totals.accantonamento, 2) + ' € / ' + 
                this.formatNumber(totals.accantonamento / unitaVendute, 2) + ' €';
        }
        
        const totalAvgTaxEl = document.querySelector(`#total-avg-tax-${anno}`);
        if (totalAvgTaxEl) {
            totalAvgTaxEl.textContent = 
                this.formatNumber(totals.tasse, 2) + ' € / ' + 
                this.formatNumber(totals.tasse / unitaVendute, 2) + ' €';
        }
        
        const totalAvgMat1El = document.querySelector(`#total-avg-materia1-${anno}`);
        if (totalAvgMat1El) {
            totalAvgMat1El.textContent = 
                this.formatNumber(totals.materia1, 2) + ' € / ' + 
                this.formatNumber(totals.materia1 / unitaVendute, 2) + ' €';
        }
        
        const totalAvgSpedEl = document.querySelector(`#total-avg-sped-${anno}`);
        if (totalAvgSpedEl) {
            totalAvgSpedEl.textContent = 
                this.formatNumber(totals.sped, 2) + ' € / ' + 
                this.formatNumber(totals.sped / unitaVendute, 2) + ' €';
        }
        
        const totalAvgVarieEl = document.querySelector(`#total-avg-varie-${anno}`);
        if (totalAvgVarieEl) {
            totalAvgVarieEl.textContent = 
                this.formatNumber(totals.varie, 2) + ' € / ' + 
                this.formatNumber(totals.varie / unitaVendute, 2) + ' €';
        }
        
        // Calcola utile mensile per l'anno clonato
        this.calculateUtileMensile(anno);
        
        // // console.log(`✅ [YEAR-${anno}] Totali calcolati:`, totals);
    }
    
    /**
     * Update global KPI row (yellow header) with sum of all years
     */
    updateGlobalKPIRow() {
        // // console.log('📊 [GLOBAL-KPI] Aggiornamento KPI globali dalla riga gialla...');
        
        // Get current year from dropdown
        const currentYear = parseInt(document.getElementById('anno').value);
        
        // Get list of all years (current + cloned)
        const allYears = [currentYear];
        
        // Find all year sections (using total-avg-fatturato which is the combined Totale/Unitario row)
        const yearSections = document.querySelectorAll('[id^="total-avg-fatturato-"]');
        yearSections.forEach(el => {
            const match = el.id.match(/total-avg-fatturato-(\d{4})/);
            if (match && parseInt(match[1]) !== currentYear) {
                allYears.push(parseInt(match[1]));
            }
        });
        
        // // console.log('📊 [GLOBAL-KPI] Anni trovati:', allYears);
        
        let globalTotals = {
            fatturato: 0,
            unita: 0,
            erogato: 0,
            accantonamento: 0,
            tasse: 0,
            materia1: 0,
            materia1_unita: 0,
            sped: 0,
            sped_unita: 0,
            varie: 0
        };
        
        // Sum totals from each year
        allYears.forEach(anno => {
            const suffix = anno === currentYear ? '' : `-${anno}`;
            
            // FATTURATO (B21 - formato: "totale / unitario")
            const fattEl = document.querySelector(`#total-avg-fatturato${suffix}`);
            if (fattEl) {
                // parseEuroValueSimple prende il primo valore numerico (il totale)
                globalTotals.fatturato += this.parseEuroValueSimple(fattEl.textContent);
            }
            
            // UNITA (C21 - solo numero)
            const unitEl = document.querySelector(`#total-unita${suffix}`);
            if (unitEl) {
                const unita = parseInt(unitEl.textContent) || 0;
                // // console.log(`📊 [GLOBAL-KPI] Anno ${anno}: Unità Vendute = ${unita} (da ${unitEl.id})`);
                globalTotals.unita += unita;
            }
            
            // EROGATO (D21 - formato: "totale / unitario")
            const erogEl = document.querySelector(`#total-avg-erogato${suffix}`);
            if (erogEl) {
                globalTotals.erogato += this.parseEuroValueSimple(erogEl.textContent);
            }
            
            // ACCANTONAMENTO (F21 - formato: "totale / unitario")
            const accEl = document.querySelector(`#total-avg-accant${suffix}`);
            if (accEl) {
                const value = this.parseEuroValueSimple(accEl.textContent);
                // // console.log(`📊 [GLOBAL-KPI] Anno ${anno}: € TAX PLAFOND (F21) = ${value} (da ${accEl.id})`);
                globalTotals.accantonamento += value;
            }
            
            // TASSE (G21 - formato: "totale / unitario", GIÀ NEGATIVO)
            const taxEl = document.querySelector(`#total-avg-tax${suffix}`);
            if (taxEl) {
                const value = this.parseEuroValueSimple(taxEl.textContent);
                // // console.log(`📊 [GLOBAL-KPI] Anno ${anno}: € TAX (G21) = ${value} (da ${taxEl.id})`);
                globalTotals.tasse += value;
            }
            
            // MATERIA 1 (H21 - euro - formato: "totale / unitario")
            const mat1El = document.querySelector(`#total-avg-materia1${suffix}`);
            if (mat1El) {
                globalTotals.materia1 += this.parseEuroValueSimple(mat1El.textContent);
            }
            
            // MATERIA 1 (I21 - unità - solo numero)
            const mat1UnitEl = document.querySelector(`#total-materia1-unita${suffix}`);
            if (mat1UnitEl) {
                const unita = parseInt(mat1UnitEl.textContent) || 0;
                // // console.log(`📊 [GLOBAL-KPI] Anno ${anno}: Unità Acquistate = ${unita} (da ${mat1UnitEl.id})`);
                globalTotals.materia1_unita += unita;
            }
            
            // SPEDIZIONE (J21 - euro - formato: "totale / unitario")
            const spedEl = document.querySelector(`#total-avg-sped${suffix}`);
            if (spedEl) {
                globalTotals.sped += this.parseEuroValueSimple(spedEl.textContent);
            }
            
            // SPEDIZIONE (K21 - unità - solo numero)
            const spedUnitEl = document.querySelector(`#total-sped-unita${suffix}`);
            if (spedUnitEl) {
                const unita = parseInt(spedUnitEl.textContent) || 0;
                // // console.log(`📊 [GLOBAL-KPI] Anno ${anno}: Unità Spedite = ${unita} (da ${spedUnitEl.id})`);
                globalTotals.sped_unita += unita;
            }
            
            // VARIE (L21 - formato: "totale / unitario")
            const varieEl = document.querySelector(`#total-avg-varie${suffix}`);
            if (varieEl) {
                globalTotals.varie += this.parseEuroValueSimple(varieEl.textContent);
            }
        });
        
        // // console.log('📊 [GLOBAL-KPI] Totali globali calcolati:', globalTotals);
        
        // Update KPI row elements (yellow header row)
        const kpiFattEl = document.getElementById('kpi-fatturato-totale');
        if (kpiFattEl) kpiFattEl.textContent = this.formatNumber(globalTotals.fatturato, 2) + ' €';
        
        const kpiErogEl = document.getElementById('kpi-erogato-totale');
        if (kpiErogEl) kpiErogEl.textContent = this.formatNumber(globalTotals.erogato, 2) + ' €';
        
        const kpiAccEl = document.getElementById('kpi-accantonamento-totale');
        if (kpiAccEl) kpiAccEl.textContent = this.formatNumber(globalTotals.accantonamento, 2) + ' €';
        
        const kpiTaxEl = document.getElementById('kpi-tasse-totale');
        if (kpiTaxEl) kpiTaxEl.textContent = this.formatNumber(globalTotals.tasse, 2) + ' €';
        
        // L2: € FBA (negativo perché è un costo)
        const fbaTotal = -(globalTotals.fatturato - globalTotals.erogato);
        const kpiFbaEl = document.getElementById('kpi-fba-totale');
        if (kpiFbaEl) kpiFbaEl.textContent = this.formatNumber(fbaTotal, 2) + ' €';
        
        // L3: € MATERIA 1 (Σ H21 tutti anni)
        const kpiMat1El = document.getElementById('kpi-materia1-totale');
        if (kpiMat1El) kpiMat1El.textContent = this.formatNumber(globalTotals.materia1, 2) + ' €';
        
        // L4: € SPEDIZIONE (Σ J21 tutti anni)
        const kpiSpedEl = document.getElementById('kpi-sped-totale');
        if (kpiSpedEl) kpiSpedEl.textContent = this.formatNumber(globalTotals.sped, 2) + ' €';
        
        // L5: € VARIE (Σ L21 tutti anni)
        const kpiVarieEl = document.getElementById('kpi-varie-totale');
        if (kpiVarieEl) kpiVarieEl.textContent = this.formatNumber(globalTotals.varie, 2) + ' €';
        
        // Update UNITA' ACQUISTATE in stats row (A5)
        const unitaAcquistateEl = document.getElementById('unita-acquistate');
        if (unitaAcquistateEl) {
            // // console.log(`📊 [GLOBAL-KPI] Aggiornamento A5 (unita-acquistate) = ${globalTotals.materia1_unita}`);
            unitaAcquistateEl.textContent = globalTotals.materia1_unita;
        }
        
        // Update UNITA' ACQUISTATE in summary card (above table)
        const unitaAcquistateSummaryEl = document.getElementById('unita-acquistate-summary');
        if (unitaAcquistateSummaryEl) {
            unitaAcquistateSummaryEl.textContent = globalTotals.materia1_unita;
        }
        
        // Update UNITA' SPEDITE in stats row (C5)
        const unitaSpediteEl = document.getElementById('unita-spedite');
        if (unitaSpediteEl) {
            // // console.log(`📊 [GLOBAL-KPI] Aggiornamento C5 (unita-spedite) = ${globalTotals.sped_unita}`);
            unitaSpediteEl.textContent = globalTotals.sped_unita;
        }
        
        // Update UNITA' SPEDITE in summary card (above table)
        const unitaSpediteSummaryEl = document.getElementById('unita-spedite-summary');
        if (unitaSpediteSummaryEl) {
            unitaSpediteSummaryEl.textContent = globalTotals.sped_unita;
        }
        
        // Update UNITA' VENDUTE in stats row (E5)
        const unitaVenduteEl = document.getElementById('unita-vendute');
        if (unitaVenduteEl) {
            // // console.log(`📊 [GLOBAL-KPI] Aggiornamento E5 (unita-vendute) = ${globalTotals.unita}`);
            unitaVenduteEl.textContent = globalTotals.unita;
        }
        
        // Update UNITA' VENDUTE in summary card (above table)
        const unitaVenduteSummaryEl = document.getElementById('unita-vendute-summary');
        if (unitaVenduteSummaryEl) {
            unitaVenduteSummaryEl.textContent = globalTotals.unita;
        }
        
        // Update TAX PLAFOND in stats row (G5 - RIGA GIALLA NELLA TABELLA)
        // Formula: (F21 di tutti gli anni) + (G21 di tutti gli anni, già negativo)
        const taxPlafondFinal = globalTotals.accantonamento + globalTotals.tasse;
        // // console.log(`📊 [GLOBAL-KPI] Aggiornamento G5 (tax-plafond-table) = ${globalTotals.accantonamento} + (${globalTotals.tasse}) = ${taxPlafondFinal}`);
        const taxPlafondTableEl = document.getElementById('tax-plafond-table');
        if (taxPlafondTableEl) {
            taxPlafondTableEl.textContent = this.formatNumber(taxPlafondFinal, 2) + ' €';
        }
        
        // Calculate and update averages (per unit)
        if (globalTotals.unita > 0) {
            // C2: Fatturato per unità (B2 / E5)
            const kpiFattPerUnitaEl = document.getElementById('kpi-fatturato-per-unita');
            if (kpiFattPerUnitaEl) {
                kpiFattPerUnitaEl.textContent = this.formatNumber(globalTotals.fatturato / globalTotals.unita, 2) + ' €';
            }
            
            // C3: Erogato per unità (B3 / E5)
            const kpiErogPerUnitaEl = document.getElementById('kpi-erogato-per-unita');
            if (kpiErogPerUnitaEl) {
                kpiErogPerUnitaEl.textContent = this.formatNumber(globalTotals.erogato / globalTotals.unita, 2) + ' €';
            }
            
            // Accantonamento per unità (G2 / E5)
            const kpiAccPerUnitaEl = document.getElementById('kpi-accantonamento-per-unita');
            if (kpiAccPerUnitaEl) {
                kpiAccPerUnitaEl.textContent = this.formatNumber(globalTotals.accantonamento / globalTotals.unita, 2) + ' €';
            }
            
            // Tasse per unità (G3 / E5)
            const kpiTaxPerUnitaEl = document.getElementById('kpi-tasse-per-unita');
            if (kpiTaxPerUnitaEl) {
                kpiTaxPerUnitaEl.textContent = this.formatNumber(globalTotals.tasse / globalTotals.unita, 2) + ' €';
            }
            
            // M2: FBA per unità (L2 / E5)
            const fbaPerUnitaEl = document.getElementById('kpi-fba-per-unita');
            if (fbaPerUnitaEl) {
                const fbaPerUnita = fbaTotal / globalTotals.unita;
                fbaPerUnitaEl.textContent = this.formatNumber(fbaPerUnita, 2) + ' €';
            }
            
            // M5: Varie per unità (L5 / E5)
            const kpiVariePerUnitaEl = document.getElementById('kpi-varie-per-unita');
            if (kpiVariePerUnitaEl) {
                kpiVariePerUnitaEl.textContent = this.formatNumber(globalTotals.varie / globalTotals.unita, 2) + ' €';
            }
        }
        
        // M3: Materia 1 per unità acquistata (L3 / A5)
        if (globalTotals.materia1_unita > 0) {
            const kpiMat1PerUnitaEl = document.getElementById('kpi-materia1-per-unita');
            if (kpiMat1PerUnitaEl) {
                kpiMat1PerUnitaEl.textContent = this.formatNumber(globalTotals.materia1 / globalTotals.materia1_unita, 2) + ' €';
            }
        }
        
        // M4: Spedizione per unità spedita (L4 / C5)
        if (globalTotals.sped_unita > 0) {
            const kpiSpedPerUnitaEl = document.getElementById('kpi-sped-per-unita');
            if (kpiSpedPerUnitaEl) {
                kpiSpedPerUnitaEl.textContent = this.formatNumber(globalTotals.sped / globalTotals.sped_unita, 2) + ' €';
            }
        }
        
        // Calculate percentages on FATTURATO (Colonna N)
        if (globalTotals.fatturato > 0) {
            // D3: % EROGATO su FATTURATO (B3/B2 * 100)
            const kpiErogPercFattEl = document.getElementById('kpi-erogato-perc-fatt');
            if (kpiErogPercFattEl) {
                const perc = (globalTotals.erogato / globalTotals.fatturato) * 100;
                kpiErogPercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // I2: % ACCANTONAMENTO su FATTURATO (G2/B2 * 100)
            const kpiAccPercFattEl = document.getElementById('kpi-accantonamento-perc-fatt');
            if (kpiAccPercFattEl) {
                const perc = (globalTotals.accantonamento / globalTotals.fatturato) * 100;
                kpiAccPercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // I3: % TASSE su FATTURATO (G3/B2 * 100)
            const kpiTaxPercFattEl = document.getElementById('kpi-tasse-perc-fatt');
            if (kpiTaxPercFattEl) {
                const perc = (globalTotals.tasse / globalTotals.fatturato) * 100;
                kpiTaxPercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // N2: % FBA su FATTURATO (L2/B2 * 100)
            const kpiFbaPercFattEl = document.getElementById('kpi-fba-perc-fatt');
            if (kpiFbaPercFattEl) {
                const perc = (fbaTotal / globalTotals.fatturato) * 100;
                kpiFbaPercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // N3: % MATERIA 1 su FATTURATO (L3/B2 * 100)
            const kpiMat1PercFattEl = document.getElementById('kpi-materia1-perc-fatt');
            if (kpiMat1PercFattEl) {
                const perc = (globalTotals.materia1 / globalTotals.fatturato) * 100;
                kpiMat1PercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // N4: % SPEDIZIONE su FATTURATO (L4/B2 * 100)
            const kpiSpedPercFattEl = document.getElementById('kpi-sped-perc-fatt');
            if (kpiSpedPercFattEl) {
                const perc = (globalTotals.sped / globalTotals.fatturato) * 100;
                kpiSpedPercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // N5: % VARIE su FATTURATO (L5/B2 * 100)
            const kpiVariePercFattEl = document.getElementById('kpi-varie-perc-fatt');
            if (kpiVariePercFattEl) {
                const perc = (globalTotals.varie / globalTotals.fatturato) * 100;
                kpiVariePercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
        }
        
        // Calculate percentages on EROGATO (Colonna O)
        if (globalTotals.erogato > 0) {
            // J2: % ACCANTONAMENTO su EROGATO (G2/B3 * 100)
            const kpiAccPercErogEl = document.getElementById('kpi-accantonamento-perc-erog');
            if (kpiAccPercErogEl) {
                const perc = (globalTotals.accantonamento / globalTotals.erogato) * 100;
                kpiAccPercErogEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // J3: % TASSE su EROGATO (G3/B3 * 100)
            const kpiTaxPercErogEl = document.getElementById('kpi-tasse-perc-erog');
            if (kpiTaxPercErogEl) {
                const perc = (globalTotals.tasse / globalTotals.erogato) * 100;
                kpiTaxPercErogEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // O3: % MATERIA 1 su EROGATO (L3/B3 * 100)
            const kpiMat1PercErogEl = document.getElementById('kpi-materia1-perc-erog');
            if (kpiMat1PercErogEl) {
                const perc = (globalTotals.materia1 / globalTotals.erogato) * 100;
                kpiMat1PercErogEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // O4: % SPEDIZIONE su EROGATO (L4/B3 * 100)
            const kpiSpedPercErogEl = document.getElementById('kpi-sped-perc-erog');
            if (kpiSpedPercErogEl) {
                const perc = (globalTotals.sped / globalTotals.erogato) * 100;
                kpiSpedPercErogEl.textContent = this.formatNumber(perc, 2) + '%';
            }
            
            // O5: % VARIE su EROGATO (L5/B3 * 100)
            const kpiVariePercErogEl = document.getElementById('kpi-varie-perc-erog');
            if (kpiVariePercErogEl) {
                const perc = (globalTotals.varie / globalTotals.erogato) * 100;
                kpiVariePercErogEl.textContent = this.formatNumber(perc, 2) + '%';
            }
        }
        
        // Calculate UTILE LORDO (Riga 4)
        // B4 = B3 + G3 + L3 + L4 + L5 (Erogato + Tasse + Materia1 + Spedizioni + Varie)
        // Nota: G3, L3, L4, L5 sono già negativi, quindi la somma dà il risultato corretto
        const utileLordo = globalTotals.erogato + globalTotals.tasse + globalTotals.materia1 + globalTotals.sped + globalTotals.varie;
        
        // B4: UTILE LORDO TOTALE
        const kpiUtileLordoEl = document.getElementById('kpi-utile-lordo-totale');
        if (kpiUtileLordoEl) {
            kpiUtileLordoEl.textContent = this.formatNumber(utileLordo, 2) + ' €';
        }
        
        // C4: UTILE LORDO PER UNITA (B4 / E5)
        if (globalTotals.unita > 0) {
            const kpiUtileLordoPerUnitaEl = document.getElementById('kpi-utile-lordo-per-unita');
            if (kpiUtileLordoPerUnitaEl) {
                const utileLordoPerUnita = utileLordo / globalTotals.unita;
                kpiUtileLordoPerUnitaEl.textContent = this.formatNumber(utileLordoPerUnita, 2) + ' €';
            }
        }
        
        // D4: % UTILE LORDO su FATTURATO (B4 / B2 × 100)
        if (globalTotals.fatturato > 0) {
            const kpiUtileLordoPercFattEl = document.getElementById('kpi-utile-lordo-perc-fatt');
            if (kpiUtileLordoPercFattEl) {
                const perc = (utileLordo / globalTotals.fatturato) * 100;
                kpiUtileLordoPercFattEl.textContent = this.formatNumber(perc, 2) + '%';
            }
        }
        
        // E4: % UTILE LORDO su EROGATO (B4 / B3 × 100)
        if (globalTotals.erogato > 0) {
            const kpiUtileLordoPercErogEl = document.getElementById('kpi-utile-lordo-perc-erog');
            if (kpiUtileLordoPercErogEl) {
                const perc = (utileLordo / globalTotals.erogato) * 100;
                kpiUtileLordoPercErogEl.textContent = this.formatNumber(perc, 2) + '%';
            }
        }
        
        // ============================================
        // POPULATE FLOW CARDS (using global totals)
        // ============================================
        
        const el = (id) => document.getElementById(id);
        
        // Flusso Principale - Fatturato
        if (el('flow-fatturato-totale')) {
            el('flow-fatturato-totale').textContent = this.formatNumber(globalTotals.fatturato, 2) + ' €';
        }
        if (el('flow-fatturato-per-unita') && globalTotals.unita > 0) {
            el('flow-fatturato-per-unita').textContent = this.formatNumber(globalTotals.fatturato / globalTotals.unita, 2);
        }
        
        // Flusso Principale - Erogato
        if (el('flow-erogato-totale')) {
            el('flow-erogato-totale').textContent = this.formatNumber(globalTotals.erogato, 2) + ' €';
        }
        if (el('flow-erogato-per-unita') && globalTotals.unita > 0) {
            el('flow-erogato-per-unita').textContent = this.formatNumber(globalTotals.erogato / globalTotals.unita, 2);
        }
        if (el('flow-erogato-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-erogato-perc-fatt').textContent = this.formatNumber((globalTotals.erogato / globalTotals.fatturato) * 100, 2) + '%';
        }
        
        // Flusso Principale - FBA (già calcolato come fbaTotal)
        if (el('flow-fba-totale')) {
            el('flow-fba-totale').textContent = this.formatNumber(fbaTotal, 2) + ' €';
        }
        if (el('flow-fba-per-unita') && globalTotals.unita > 0) {
            el('flow-fba-per-unita').textContent = this.formatNumber(fbaTotal / globalTotals.unita, 2);
        }
        if (el('flow-fba-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-fba-perc-fatt').textContent = this.formatNumber((fbaTotal / globalTotals.fatturato) * 100, 2) + '%';
        }
        if (el('flow-fba-perc-erog') && globalTotals.erogato > 0) {
            el('flow-fba-perc-erog').textContent = this.formatNumber((fbaTotal / globalTotals.erogato) * 100, 2) + '%';
        }
        
        // Flusso Principale - Utile Netto (già calcolato come utileLordo)
        if (el('flow-utile-netto-totale')) {
            el('flow-utile-netto-totale').textContent = this.formatNumber(utileLordo, 2) + ' €';
        }
        if (el('flow-utile-netto-per-unita') && globalTotals.unita > 0) {
            el('flow-utile-netto-per-unita').textContent = this.formatNumber(utileLordo / globalTotals.unita, 2);
        }
        if (el('flow-utile-netto-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-utile-netto-perc-fatt').textContent = this.formatNumber((utileLordo / globalTotals.fatturato) * 100, 2) + '%';
        }
        if (el('flow-utile-netto-perc-erog') && globalTotals.erogato > 0) {
            el('flow-utile-netto-perc-erog').textContent = this.formatNumber((utileLordo / globalTotals.erogato) * 100, 2) + '%';
        }
        
        // Costi Operativi - Materia Prima
        if (el('flow-materia1-totale')) {
            el('flow-materia1-totale').textContent = this.formatNumber(globalTotals.materia1, 2) + ' €';
        }
        if (el('flow-materia1-per-unita') && globalTotals.materia1_unita > 0) {
            el('flow-materia1-per-unita').textContent = this.formatNumber(globalTotals.materia1 / globalTotals.materia1_unita, 2);
        }
        if (el('flow-materia1-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-materia1-perc-fatt').textContent = this.formatNumber((globalTotals.materia1 / globalTotals.fatturato) * 100, 2) + '%';
        }
        if (el('flow-materia1-perc-erog') && globalTotals.erogato > 0) {
            el('flow-materia1-perc-erog').textContent = this.formatNumber((globalTotals.materia1 / globalTotals.erogato) * 100, 2) + '%';
        }
        
        // Costi Operativi - Spedizioni
        if (el('flow-sped-totale')) {
            el('flow-sped-totale').textContent = this.formatNumber(globalTotals.sped, 2) + ' €';
        }
        if (el('flow-sped-per-unita') && globalTotals.sped_unita > 0) {
            el('flow-sped-per-unita').textContent = this.formatNumber(globalTotals.sped / globalTotals.sped_unita, 2);
        }
        if (el('flow-sped-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-sped-perc-fatt').textContent = this.formatNumber((globalTotals.sped / globalTotals.fatturato) * 100, 2) + '%';
        }
        if (el('flow-sped-perc-erog') && globalTotals.erogato > 0) {
            el('flow-sped-perc-erog').textContent = this.formatNumber((globalTotals.sped / globalTotals.erogato) * 100, 2) + '%';
        }
        
        // Costi Operativi - Varie
        if (el('flow-varie-totale')) {
            el('flow-varie-totale').textContent = this.formatNumber(globalTotals.varie, 2) + ' €';
        }
        if (el('flow-varie-per-unita') && globalTotals.unita > 0) {
            el('flow-varie-per-unita').textContent = this.formatNumber(globalTotals.varie / globalTotals.unita, 2);
        }
        if (el('flow-varie-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-varie-perc-fatt').textContent = this.formatNumber((globalTotals.varie / globalTotals.fatturato) * 100, 2) + '%';
        }
        if (el('flow-varie-perc-erog') && globalTotals.erogato > 0) {
            el('flow-varie-perc-erog').textContent = this.formatNumber((globalTotals.varie / globalTotals.erogato) * 100, 2) + '%';
        }
        
        // Area Fiscale - Accantonamento
        if (el('flow-accantonamento-totale')) {
            el('flow-accantonamento-totale').textContent = this.formatNumber(globalTotals.accantonamento, 2) + ' €';
        }
        if (el('flow-accantonamento-per-unita') && globalTotals.unita > 0) {
            el('flow-accantonamento-per-unita').textContent = this.formatNumber(globalTotals.accantonamento / globalTotals.unita, 2);
        }
        if (el('flow-accantonamento-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-accantonamento-perc-fatt').textContent = this.formatNumber((globalTotals.accantonamento / globalTotals.fatturato) * 100, 2) + '%';
        }
        if (el('flow-accantonamento-perc-erog') && globalTotals.erogato > 0) {
            el('flow-accantonamento-perc-erog').textContent = this.formatNumber((globalTotals.accantonamento / globalTotals.erogato) * 100, 2) + '%';
        }
        
        // Area Fiscale - Tasse
        if (el('flow-tasse-totale')) {
            el('flow-tasse-totale').textContent = this.formatNumber(globalTotals.tasse, 2) + ' €';
        }
        if (el('flow-tasse-per-unita') && globalTotals.unita > 0) {
            el('flow-tasse-per-unita').textContent = this.formatNumber(globalTotals.tasse / globalTotals.unita, 2);
        }
        if (el('flow-tasse-perc-fatt') && globalTotals.fatturato > 0) {
            el('flow-tasse-perc-fatt').textContent = this.formatNumber((globalTotals.tasse / globalTotals.fatturato) * 100, 2) + '%';
        }
        if (el('flow-tasse-perc-erog') && globalTotals.erogato > 0) {
            el('flow-tasse-perc-erog').textContent = this.formatNumber((globalTotals.tasse / globalTotals.erogato) * 100, 2) + '%';
        }
        
        // Area Fiscale - Tax Plafond (card TOP box)
        const taxPlafondGlobal = globalTotals.accantonamento + globalTotals.tasse;
        if (el('tax-plafond')) {
            el('tax-plafond').textContent = this.formatNumber(taxPlafondGlobal, 2) + ' €';
        }
        
        // Tax Plafond per unit - Calculate as: Tax Plafond / Unità Vendute
        if (el('tax-plafond-per-unit') && globalTotals.unita > 0) {
            const taxPlafondPerUnit = taxPlafondGlobal / globalTotals.unita;
            el('tax-plafond-per-unit').textContent = this.formatNumber(taxPlafondPerUnit, 2) + ' €';
        }
        
        // ============================================
        // POPULATE UNITA CARDS (using global totals)
        // ============================================
        
        const boxAcquistate = document.querySelector('[data-kpi="unita-acquistate"]');
        const boxSpedite = document.querySelector('[data-kpi="unita-spedite"]');
        const boxVendute = document.querySelector('[data-kpi="unita-vendute"]');
        const boxTaxPlafond = document.querySelector('[data-kpi="tax-plafond"]');
        const boxUtileAtteso = document.querySelector('[data-kpi="utile-atteso"]');
        
        if (boxAcquistate) boxAcquistate.textContent = globalTotals.materia1_unita;
        if (boxSpedite) boxSpedite.textContent = globalTotals.sped_unita;
        if (boxVendute) boxVendute.textContent = globalTotals.unita;
        if (boxTaxPlafond) boxTaxPlafond.textContent = this.formatNumber(taxPlafondGlobal, 2) + ' €';
        
        // Calculate Utile Atteso (giacenza * media unitaria)
        const giacenza = globalTotals.materia1_unita - globalTotals.unita;
        const mediaUnitariaUtile = globalTotals.unita > 0 ? utileLordo / globalTotals.unita : 0;
        const utileNettoAtteso = giacenza * mediaUnitariaUtile;
        if (boxUtileAtteso) boxUtileAtteso.textContent = this.formatNumber(utileNettoAtteso, 2) + ' €';
        
        // Apply negative value styles to KPI rows after updating values
        this.applyNegativeValueStyles();
        
        // // console.log('✅ [GLOBAL-KPI] KPI globali aggiornati');
    }
    
    /**
     * Parse euro value from text - handles both Italian and American formats
     */
    parseEuroValueSimple(text) {
        if (!text) return 0;
        
        // Handle "Totale / Unitario" format: extract only the first value
        if (text.includes('/')) {
            text = text.split('/')[0].trim();
        }
        
        // Remove € symbol and trim
        let cleaned = text.replace(/€/g, '').trim();
        
        // Check format by counting dots and commas
        const hasComma = cleaned.includes(',');
        const hasDot = cleaned.includes('.');
        
        if (hasComma && hasDot) {
            // Italian format: "20.852,89" → remove dots, replace comma with dot
            cleaned = cleaned.replace(/\./g, '').replace(',', '.');
        } else if (hasComma && !hasDot) {
            // Only comma: "20852,89" → replace comma with dot
            cleaned = cleaned.replace(',', '.');
        } else if (hasDot && !hasComma) {
            // Only dot: could be "20852.89" (decimal) or "20.852" (thousands)
            const dotPosition = cleaned.lastIndexOf('.');
            const afterDot = cleaned.substring(dotPosition + 1);
            
            if (afterDot.length <= 2) {
                // "20852.89" → decimal, keep as is
                // cleaned stays the same
            } else {
                // "20.852" → thousands separator, remove it
                cleaned = cleaned.replace(/\./g, '');
            }
        }
        
        return parseFloat(cleaned) || 0;
    }
    
    formatNumber(num, decimals = 2) {
        if (num === null || num === undefined || isNaN(num)) return '0.00';
        return parseFloat(num).toFixed(decimals);
    }

    // Debug helper
    debugInfo() {
        if (window.location.hostname !== 'localhost') return;
        
        // // console.log('=== RENDICONTO DEBUG ===');
        // // console.log('User ID:', this.userId);
        // // console.log('User Name:', this.userName);
        // // console.log('Current Data:', this.data);
        // // console.log('Totali:', this.data.totali);
        // // console.log('KPI:', this.data.kpi);
        // // console.log('=======================');
    }
    
    // Validazione generale dei dati
    validateData() {
        let errors = [];
        
        for (let mese = 1; mese <= 12; mese++) {
            const riga = this.data.righe[mese];
            if (!riga) continue;
            
            // Validazioni logiche
            if (riga.entrate_fatturato < 0) {
                errors.push(`Mese ${mese}: Il fatturato non può essere negativo`);
            }
            
            if (riga.entrate_unita < 0) {
                errors.push(`Mese ${mese}: Le unità non possono essere negative`);
            }
            
            if (riga.accantonamento_percentuale < 0 || riga.accantonamento_percentuale > 100) {
                errors.push(`Mese ${mese}: La percentuale di accantonamento deve essere tra 0 e 100`);
            }
        }
        
        return errors;
    }
    
    // === NUOVO SISTEMA TRANSAZIONI ===
    
    toggleTransactionFields() {
        const tipo = document.getElementById('trans-tipo').value;
        const importoGroup = document.getElementById('importo-group');
        const quantitaGroup = document.getElementById('quantita-group');
    const noteField = document.getElementById('trans-note');
    const pagamentoRefGroup = document.getElementById('pagamento-ref-group');
        const percentualeGroup = document.getElementById('percentuale-group');
    const percentualeCustomGroup = document.getElementById('percentuale-custom-group');
    const dataField = document.getElementById('trans-data');
        
    // Reset visibility e required
        importoGroup.style.display = 'none';
        quantitaGroup.style.display = 'none';
    pagamentoRefGroup.style.display = 'none';
        percentualeGroup.style.display = 'none';
    percentualeCustomGroup.style.display = 'none';
    noteField.required = false;
    noteField.placeholder = 'Descrizione...';
    noteField.readOnly = false;
    noteField.value = '';
    
    // Reset campo data (potrebbe essere stato readonly per accantonamento)
    dataField.readOnly = false;
    dataField.style.backgroundColor = '';
    dataField.value = '';
    
    // Mostra campi in base al tipo (usa 'flex' per layout orizzontale)
    if (tipo === 'accantonamento_euro') {
        // Mostra dropdown pagamenti + percentuale + importo
        pagamentoRefGroup.style.display = 'flex';
        pagamentoRefGroup.style.flexDirection = 'column';
        pagamentoRefGroup.style.flex = '0 0 auto';
        
        percentualeGroup.style.display = 'flex';
        percentualeGroup.style.flexDirection = 'column';
        percentualeGroup.style.flex = '0 0 auto';
        
        importoGroup.style.display = 'flex';
        importoGroup.style.flexDirection = 'column';
        importoGroup.style.flex = '0 0 auto';
        
        noteField.readOnly = false; // Editabile
        noteField.placeholder = 'Nota auto-generata modificabile...';
        
        // Bind eventi calcolo PRIMA di caricare i pagamenti
        this.bindAccantonamentoCalculations();
        
        // Carica lista pagamenti DOPO il binding (async)
        this.loadPagamentiErogati();
    } else if (tipo === 'tasse_pagamento') {
        importoGroup.style.display = 'flex';
        importoGroup.style.flexDirection = 'column';
        importoGroup.style.flex = '0 0 auto';
        noteField.required = true;
    } else if (tipo === 'materia_prima_acquisto' || tipo === 'spedizioni_acquisto') {
        importoGroup.style.display = 'flex';
        importoGroup.style.flexDirection = 'column';
        importoGroup.style.flex = '0 0 auto';
        quantitaGroup.style.display = 'flex';
        quantitaGroup.style.flexDirection = 'column';
        quantitaGroup.style.flex = '0 0 auto';
        
        // Solo in INSERT rendi readonly, NON in EDIT
        const formEl = document.getElementById('transaction-form');
        const isEditMode = formEl && formEl.dataset.editId;
        
        if (!isEditMode) {
            noteField.readOnly = true;
            noteField.placeholder = 'Nota generata automaticamente...';
        } else {
            noteField.readOnly = false;
            noteField.placeholder = 'Descrizione...';
        }
    } else if (tipo === 'spese_varie') {
        importoGroup.style.display = 'flex';
        importoGroup.style.flexDirection = 'column';
        importoGroup.style.flex = '0 0 auto';
        noteField.required = true;
    } else if (tipo) {
        importoGroup.style.display = 'flex';
        importoGroup.style.flexDirection = 'column';
        importoGroup.style.flex = '0 0 auto';
    }
    }
    
    async loadPagamentiErogati() {
    const select = document.getElementById('trans-pagamento-ref');
    
    if (!select) {
        console.error('❌ [ERROR] Dropdown trans-pagamento-ref non trovato nel DOM!');
        return;
    }
    
    try {
        // Carica TUTTI i pagamenti (tutti gli anni) rimuovendo il filtro anno
        const response = await fetch(`?action=get_input_utente&tipo_input=erogato`);
        const result = await response.json();
        
        if (result.success && result.data) {
            // Filtra solo pagamenti con importo_eur > 0
            const pagamenti = result.data.filter(p => parseFloat(p.importo_eur || 0) > 0);
            
            // Raggruppa per anno
            const pagamentiPerAnno = {};
            pagamenti.forEach(pag => {
                const year = new Date(pag.data).getFullYear();
                if (!pagamentiPerAnno[year]) {
                    pagamentiPerAnno[year] = [];
                }
                pagamentiPerAnno[year].push(pag);
            });
            
            // Ordina gli anni in ordine decrescente (2025, 2024, 2023...)
            const anniOrdinati = Object.keys(pagamentiPerAnno).sort((a, b) => b - a);
            
            // Popola dropdown con optgroup per anno
            select.innerHTML = '<option value="">Seleziona pagamento...</option>';
            
            anniOrdinati.forEach(anno => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = `━━━━━━ Anno ${anno} ━━━━━━`;
                
                // Ordina pagamenti per data (più recenti prima)
                pagamentiPerAnno[anno]
                    .sort((a, b) => new Date(b.data) - new Date(a.data))
                    .forEach(pag => {
                const importoEur = parseFloat(pag.importo_eur).toFixed(2).replace('.', ',');
                const date = new Date(pag.data);
                const dataFormatted = `${date.getDate().toString().padStart(2, '0')}/${(date.getMonth() + 1).toString().padStart(2, '0')}/${date.getFullYear()}`;
                        
                const option = document.createElement('option');
                option.value = pag.id;
                option.textContent = `€${importoEur} del ${dataFormatted}`;
                option.dataset.importoEur = pag.importo_eur;
                option.dataset.data = pag.data;
                        option.dataset.anno = anno;
                        optgroup.appendChild(option);
                    });
                
                select.appendChild(optgroup);
             });
         }
     } catch (error) {
         console.error('❌ [ERROR] Errore caricamento pagamenti:', error);
     }
 }
    
    bindAccantonamentoCalculations() {
        const percentualeSelect = document.getElementById('trans-percentuale');
        const percentualeCustomInput = document.getElementById('trans-percentuale-custom');
        const importoInput = document.getElementById('trans-importo');
        const pagamentoSelect = document.getElementById('trans-pagamento-ref');
        const percentualeCustomGroup = document.getElementById('percentuale-custom-group');
        
        // Rimuovi listener precedenti SOLO per campi che potrebbero averne già
        // (NON per pagamentoSelect che è appena stato mostrato)
        const newPercentualeSelect = percentualeSelect.cloneNode(true);
        percentualeSelect.parentNode.replaceChild(newPercentualeSelect, percentualeSelect);
        
        const newPercentualeCustomInput = percentualeCustomInput.cloneNode(true);
        percentualeCustomInput.parentNode.replaceChild(newPercentualeCustomInput, percentualeCustomInput);
        
        const newImportoInput = importoInput.cloneNode(true);
        importoInput.parentNode.replaceChild(newImportoInput, importoInput);
        
        // Aggiungi nuovi listener
        document.getElementById('trans-percentuale').addEventListener('change', () => {
            const val = document.getElementById('trans-percentuale').value;
            
            if (val === 'custom') {
                percentualeCustomGroup.style.display = 'flex';
                percentualeCustomGroup.style.flexDirection = 'column';
                percentualeCustomGroup.style.flex = '0 0 auto';
        } else {
                percentualeCustomGroup.style.display = 'none';
                if (val) {
                    this.calcolaImportoDaPercentuale(parseFloat(val));
                }
            }
        });
        
        document.getElementById('trans-percentuale-custom').addEventListener('input', () => {
            const perc = parseFloat(document.getElementById('trans-percentuale-custom').value) || 0;
            this.calcolaImportoDaPercentuale(perc);
        });
        
        document.getElementById('trans-importo').addEventListener('input', () => {
            this.calcolaPercentualeDaImporto();
        });
        
        document.getElementById('trans-pagamento-ref').addEventListener('change', () => {
            const selectedOption = document.getElementById('trans-pagamento-ref').options[document.getElementById('trans-pagamento-ref').selectedIndex];
            
            // Imposta automaticamente la data dal pagamento selezionato
            if (selectedOption && selectedOption.dataset.data) {
                const dataField = document.getElementById('trans-data');
                dataField.value = selectedOption.dataset.data;
                dataField.readOnly = true;
                dataField.style.backgroundColor = '#f7fafc';
            }
            
            const percAttuale = this.getPercentualeCorrente();
            if (percAttuale > 0) {
                this.calcolaImportoDaPercentuale(percAttuale);
            }
        });
    }
    
    calcolaImportoDaPercentuale(percentuale) {
        const pagamentoSelect = document.getElementById('trans-pagamento-ref');
        const selectedOption = pagamentoSelect.options[pagamentoSelect.selectedIndex];
        
        if (!selectedOption || !selectedOption.dataset.importoEur) {
            return;
        }
        
        const importoEurPagamento = parseFloat(selectedOption.dataset.importoEur);
        const importoAccantonamento = (importoEurPagamento * percentuale) / 100;
        
        document.getElementById('trans-importo').value = importoAccantonamento.toFixed(2);
        this.generaNotaAccantonamento(importoAccantonamento, percentuale, importoEurPagamento, selectedOption.dataset.data);
    }
    
    calcolaPercentualeDaImporto() {
        const pagamentoSelect = document.getElementById('trans-pagamento-ref');
        const selectedOption = pagamentoSelect.options[pagamentoSelect.selectedIndex];
        const importoInput = document.getElementById('trans-importo');
        
        if (!selectedOption || !selectedOption.dataset.importoEur) {
            return;
        }
        
        const importoEurPagamento = parseFloat(selectedOption.dataset.importoEur);
        const importoAccantonamento = parseFloat(importoInput.value) || 0;
        const percentuale = (importoAccantonamento / importoEurPagamento) * 100;
        
        const percentualeSelect = document.getElementById('trans-percentuale');
        const percentualeCustomInput = document.getElementById('trans-percentuale-custom');
        const percentualeCustomGroup = document.getElementById('percentuale-custom-group');
        
        // Se è una percentuale standard, selezionala
        const standardPerc = [5, 10, 15, 20, 25];
        if (standardPerc.includes(Math.round(percentuale))) {
            percentualeSelect.value = Math.round(percentuale).toString();
            percentualeCustomGroup.style.display = 'none';
        } else {
            percentualeSelect.value = 'custom';
            percentualeCustomInput.value = percentuale.toFixed(2);
            percentualeCustomGroup.style.display = 'flex';
            percentualeCustomGroup.style.flexDirection = 'column';
            percentualeCustomGroup.style.flex = '0 0 auto';
        }
        
        this.generaNotaAccantonamento(importoAccantonamento, percentuale, importoEurPagamento, selectedOption.dataset.data);
    }
    
    getPercentualeCorrente() {
        const percentualeSelect = document.getElementById('trans-percentuale');
        const percentualeCustomInput = document.getElementById('trans-percentuale-custom');
        
        if (percentualeSelect.value === 'custom') {
            return parseFloat(percentualeCustomInput.value) || 0;
        } else {
            return parseFloat(percentualeSelect.value) || 0;
        }
    }
    
    generaNotaAccantonamento(importo, percentuale, importoPagamento, dataPagamento) {
        const noteField = document.getElementById('trans-note');
        const date = new Date(dataPagamento);
        const dataFormatted = `${date.getDate().toString().padStart(2, '0')}/${(date.getMonth() + 1).toString().padStart(2, '0')}/${date.getFullYear()}`;
        
        const nota = `Accantonato €${importo.toFixed(2).replace('.', ',')} pari a ${percentuale.toFixed(2)}% su accredito di €${importoPagamento.toFixed(2).replace('.', ',')} del ${dataFormatted}`;
        
        noteField.value = nota;
    }
    
async saveTransaction(e) {
    e.preventDefault();
        
        const tipo = document.getElementById('trans-tipo').value;
        const data = document.getElementById('trans-data').value;
        let importo = parseFloat(document.getElementById('trans-importo').value) || 0;
        const quantita = parseInt(document.getElementById('trans-quantita').value) || 0;
    let note = document.getElementById('trans-note').value;
    
    console.log('💾 [SAVE] Tipo:', tipo);
    console.log('💾 [SAVE] Data:', data);
    console.log('💾 [SAVE] Importo originale:', importo);
    console.log('💾 [SAVE] Quantità:', quantita);
    console.log('💾 [SAVE] Note originali:', note);
    
    // Applica il segno corretto all'importo in base al tipo
    // € Accantonato → sempre POSITIVO
    // € Tasse, Materia Prima, Spedizione, Varie → sempre NEGATIVO
    const tipiNegativi = ['tasse_pagamento', 'materia_prima_acquisto', 'spedizioni_acquisto', 'spese_varie'];
    if (tipiNegativi.includes(tipo)) {
        importo = Math.abs(importo) * -1; // Forza il segno negativo
    } else if (tipo === 'accantonamento_euro') {
        importo = Math.abs(importo); // Forza il segno positivo
    }
    
    console.log('💾 [SAVE] Importo dopo segno:', importo);
    
    // Genera nota automatica SOLO se campo vuoto
    if (!note || note.trim() === '') {
        if (tipo === 'materia_prima_acquisto' && importo < 0 && quantita > 0) {
            note = `Spesa di ${Math.abs(importo).toFixed(2)}€ per acquisto di ${quantita} unità il ${data}`;
            console.log('📝 [SAVE] Nota auto-generata (materia prima)');
        } else if (tipo === 'spedizioni_acquisto' && importo < 0 && quantita > 0) {
            note = `Spesa di ${Math.abs(importo).toFixed(2)}€ per spedizione di ${quantita} unità il ${data}`;
            console.log('📝 [SAVE] Nota auto-generata (spedizioni)');
        }
    } else {
        console.log('📝 [SAVE] Nota custom mantenuta:', note);
    }
        
        // Validazioni
        if (!tipo) {
            this.showMessage('Seleziona il tipo di transazione', 'warning');
            return;
        }
        
        if (!data) {
            this.showMessage('Inserisci la data', 'warning');
            return;
        }
        
        // Estrai anno e mese dalla data della transazione (NON dal dropdown anno)
        const dataObj = new Date(data);
        const anno = dataObj.getFullYear();
        const mese = dataObj.getMonth() + 1;
        console.log('📅 [SAVE] Anno estratto:', anno);
        console.log('📅 [SAVE] Mese estratto:', mese);
        
        // Validazione valore
        if (tipo === 'unita_acquistate' || tipo === 'unita_spedite') {
            if (quantita === 0) {
                this.showMessage('Inserisci una quantità valida', 'warning');
                return;
            }
        } else if (tipo === 'accantonamento_percentuale') {
            if (percentuale === 0) {
                this.showMessage('Inserisci una percentuale valida', 'warning');
                return;
            }
        } else {
            if (importo === 0) {
                this.showMessage('Inserisci un importo valido', 'warning');
                return;
            }
        }
        
        // Check if editing
        const formEl = document.getElementById('transaction-form');
        const editId = formEl.dataset.editId;
        
        console.log('🔍 [SAVE] Edit ID:', editId);
        console.log('🔍 [SAVE] Modalità:', editId ? 'UPDATE' : 'INSERT');
        
        const payload = {
            anno: parseInt(anno),
            mese: mese,
            tipo_input: tipo,
            data: data,
            importo: tipo === 'accantonamento_percentuale' ? percentuale : importo,
            quantita: quantita,
            note: note
        };
        
        // Add ID if editing
        if (editId) {
            payload.id = parseInt(editId);
        }
        
        console.log('📤 [SAVE] Payload finale:', JSON.stringify(payload, null, 2));
        
        try {
            const response = await fetch('?action=save_input_utente', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            
            console.log('📡 [SAVE] Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const result = await response.json();
            console.log('📥 [SAVE] Response body:', result);
            
            if (result.success) {
                console.log('✅ [SAVE] Salvataggio riuscito');
                this.showMessage(editId ? '✅ Transazione aggiornata' : '✅ Transazione salvata', 'success');
                
                // Reset form
                document.getElementById('trans-tipo').value = '';
                document.getElementById('trans-data').value = '';
                document.getElementById('trans-importo').value = '';
                document.getElementById('trans-quantita').value = '';
                document.getElementById('trans-note').value = '';
                document.getElementById('btn-save-trans').innerHTML = '💾 Salva';
                delete formEl.dataset.editId;
                this.toggleTransactionFields();
                
                // Ricarica dati: anno corrente + anno della transazione (se diverso)
                const annoCorrente = parseInt(document.getElementById('anno').value);
                await this.loadYear(); // Ricarica anno corrente
                
                // Se la transazione è di un anno diverso, ricarica anche quell'anno
                if (anno !== annoCorrente) {
                    await this.loadDataForClonedYear(anno);
                }
                
                // Applica stili ai valori negativi
                this.applyNegativeValueStyles();
            } else {
                this.showMessage('❌ Errore: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('❌ [TRANSACTION] Error saving transaction:', error);
            this.showMessage('❌ Errore di connessione', 'error');
        }
    }
    
    bindCellTooltips() {
        // Crea tooltip element se non esiste
        if (!document.getElementById('cell-tooltip')) {
            const tooltip = document.createElement('div');
            tooltip.id = 'cell-tooltip';
            tooltip.className = 'cell-tooltip';
            document.body.appendChild(tooltip);
        }
        
        // Bind click su tutte le celle con dati
        document.addEventListener('click', (e) => {
            const cell = e.target.closest('.cell-readonly.has-data');
            
            if (cell) {
                // Previeni chiusura immediata del tooltip
                e.stopPropagation();
                
                // Apri/aggiorna tooltip
                this.showCellTooltip(cell, e);
            } else {
                // Click fuori dalla cella → chiudi tooltip
                this.hideCellTooltip();
            }
        });
        
        // Previeni chiusura quando si clicca sul tooltip stesso
        const tooltip = document.getElementById('cell-tooltip');
        if (tooltip) {
            tooltip.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
    }
    
    async showCellTooltip(cell, event) {
        const mese = cell.dataset.mese;
        const field = cell.dataset.field;
        // Use data-anno if available (for cloned tables), otherwise use dropdown
        const anno = cell.dataset.anno || document.getElementById('anno').value;
        
        // Map field to tipo_input
        const fieldMapping = {
            'entrate_fatturato': 'fatturato',
            'entrate_unita': 'unita_acquistate', // Nota: per unità vendute usiamo acquistate
            'erogato_importo': 'erogato',
            'accantonamento_percentuale': 'accantonamento_percentuale',
            'accantonamento_euro': 'accantonamento_euro',
            'tasse_euro': 'tasse_pagamento',
            'diversi_euro': 'diversi',
            'materia1_euro': 'materia_prima_acquisto',
            'materia1_unita': 'materia_prima_acquisto',  // Condivide note con materia1_euro
            'sped_euro': 'spedizioni_acquisto',
            'sped_unita': 'spedizioni_acquisto',         // ✅ CORRETTO: condivide note con sped_euro
            'varie_euro': 'spese_varie',                 // ✅ AGGIUNTO: mapping per colonna varie
        };
        
        const tipoInput = fieldMapping[field];
        
        if (!tipoInput) return;
        
        try {
            const response = await fetch(`?action=get_input_utente&anno=${anno}&tipo_input=${tipoInput}&mese=${mese}`);
            const result = await response.json();
            
            if (result.success && result.data && result.data.length > 0) {
                this.renderTooltip(result.data, event, field, mese, anno);
            }
        } catch (error) {
            console.error('Error loading tooltip data:', error);
        }
    }
    
    renderTooltip(transactions, event, field, mese, anno) {
        const tooltip = document.getElementById('cell-tooltip');
        
        // Filtra transazioni in base al tipo di campo
        const filteredTransactions = transactions.filter(t => {
            const importo = parseFloat(t.importo || 0);
            const importoEur = parseFloat(t.importo_eur || 0);
            
            // Per EROGATO: escludi valori negativi e zero (aggiustamenti già gestiti da Amazon)
            if (field === 'erogato_importo') {
                return importoEur > 0;
            }
            
            // Per altri campi: mostra tutti i valori diversi da zero (include negativi!)
            // I valori negativi possono contenere note importanti (es: tasse, rimborsi)
            return importoEur !== 0;
        });
        
        // Funzione per formattare data in gg/mm/yyyy
        const formatDate = (dateString) => {
            if (!dateString) return 'Data non specificata';
            
            // Parse formato yyyy-mm-dd o yyyy-mm-dd hh:mm:ss
            const parts = dateString.split(/[\s-]/);
            if (parts.length >= 3) {
                const year = parts[0];
                const month = parts[1];
                const day = parts[2];
                return `${day}/${month}/${year}`;
            }
            return dateString;
        };
        
        // Mapping nomi campi human-readable con icone
        const fieldNames = {
            'entrate_fatturato': '💰 Fatturato',
            'entrate_unita': '📦 Unità Vendute',
            'erogato_importo': '💵 Erogato',
            'accantonamento_percentuale': '📊 % Accantonato',
            'accantonamento_euro': '💰 Accantonato',
            'tasse_euro': '🏛️ Tasse',
            'diversi_euro': '💸 Diversi',
            'materia1_euro': '🏭 Materia Prima',
            'materia1_unita': '📦 Unità Materia Prima',
            'sped_euro': '🚚 Spedizione',
            'sped_unita': '📦 Unità Spedite',
            'varie_euro': '💼 Varie'
        };
        
        const fieldName = fieldNames[field] || field;
        const meseFormatted = mese.toString().padStart(2, '0');
        
        const html = `
            <div class="tooltip-header">
                ${fieldName} ${meseFormatted}/${anno}
                <div style="font-size: 0.85em; font-weight: 400; opacity: 0.8; margin-top: 0.25rem;">
                    ${filteredTransactions.length} Transazion${filteredTransactions.length > 1 ? 'i' : 'e'}
            </div>
            </div>
            ${filteredTransactions.map(t => {
                let importoText = '';
                const importo = parseFloat(t.importo || 0);
                const importoEur = parseFloat(t.importo_eur || importo);
                
                if (importo !== 0) {
                    if (t.currency && t.currency !== 'EUR') {
                        // Mostra EUR prima, poi valuta originale tra parentesi
                        importoText = `💵 ${importoEur.toFixed(2).replace('.', ',')} EUR (${importo.toFixed(2).replace('.', ',')} ${t.currency})`;
                    } else {
                        // Solo EUR
                        importoText = `💵 ${importo.toFixed(2).replace('.', ',')} EUR`;
                    }
                }
                
                return `
                <div class="tooltip-transaction" style="position: relative; padding-bottom: 2.5rem;">
                        <div class="tooltip-date">📅 Bonifico del ${formatDate(t.data)}</div>
                    <div class="tooltip-amount">
                            ${importoText}
                            ${t.quantita && t.quantita !== 0 ? '📦 ' + t.quantita + ' unità' : ''}
                    </div>
                        ${t.note ? `<div class="tooltip-note">📝 ${t.note}</div>` : ''}
                        <div style="position: absolute; bottom: 0.5rem; right: 0.5rem; display: flex; gap: 0.5rem;">
                            <button 
                                onclick="window.rendicontoApp.editTransaction(${t.id})" 
                                style="padding: 0.25rem 0.75rem; background: #667eea; color: white; border: none; border-radius: 6px; font-size: 0.75rem; cursor: pointer; font-weight: 600;"
                                title="Modifica transazione">
                                ✏️ Modifica
                            </button>
                            <button 
                                onclick="window.rendicontoApp.deleteTransaction(${t.id})" 
                                style="padding: 0.25rem 0.75rem; background: #ef4444; color: white; border: none; border-radius: 6px; font-size: 0.75rem; cursor: pointer; font-weight: 600;"
                                title="Elimina transazione">
                                🗑️ Elimina
                            </button>
                        </div>
                </div>
                `;
            }).join('')}
        `;
        
        tooltip.innerHTML = html;
        tooltip.classList.add('show');
        
        // Posizionamento intelligente del tooltip
        const offset = 15;
        const mouseX = event.pageX;
        const mouseY = event.pageY;
        
        // Calcola dimensioni dopo il rendering
        const tooltipRect = tooltip.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        const viewportWidth = window.innerWidth;
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        
        // Calcola posizione verticale (sopra o sotto il cursore)
        let finalY = mouseY + offset;
        const spaceBelow = viewportHeight - (mouseY - scrollTop);
        const spaceAbove = mouseY - scrollTop;
        
        // Se non c'è spazio sotto e c'è più spazio sopra, posiziona sopra
        if (spaceBelow < tooltipRect.height + offset && spaceAbove > spaceBelow) {
            finalY = mouseY - tooltipRect.height - offset;
        }
        
        // Calcola posizione orizzontale (destra o sinistra del cursore)
        let finalX = mouseX + offset;
        const spaceRight = viewportWidth - (mouseX - scrollLeft);
        
        // Se non c'è spazio a destra, posiziona a sinistra
        if (spaceRight < tooltipRect.width + offset) {
            finalX = mouseX - tooltipRect.width - offset;
        }
        
        // Assicurati che non esca mai dal viewport
        finalX = Math.max(scrollLeft + 10, Math.min(finalX, scrollLeft + viewportWidth - tooltipRect.width - 10));
        finalY = Math.max(scrollTop + 10, finalY);
        
        tooltip.style.left = finalX + 'px';
        tooltip.style.top = finalY + 'px';
    }
    
    hideCellTooltip() {
        const tooltip = document.getElementById('cell-tooltip');
        if (tooltip) {
            tooltip.classList.remove('show');
        }
    }
    
    async populateCellsFromTransactions(anno) {
        // // console.log('📊 [CELLS] Popolamento celle da transazioni per anno:', anno);
        
        // Carica tutti gli input utente per l'anno
        const response = await fetch(`?action=get_input_utente&anno=${anno}`);
        const result = await response.json();
        
        if (!result.success || !result.data) {
            // // console.log('⚠️ [CELLS] Nessun dato trovato');
            return;
        }
        
        // // console.log('📥 [CELLS] Dati ricevuti:', result.data.length, 'transazioni');
        
        // Aggrega per mese e tipo
        const aggregati = {};
        
        result.data.forEach(trans => {
            const mese = trans.mese;
            const tipo = trans.tipo_input;
            
            if (!aggregati[mese]) {
                aggregati[mese] = {};
            }
            
            if (!aggregati[mese][tipo]) {
                aggregati[mese][tipo] = { importo: 0, quantita: 0, count: 0 };
            }
            
            aggregati[mese][tipo].importo += parseFloat(trans.importo) || 0;
            aggregati[mese][tipo].quantita += parseInt(trans.quantita) || 0;
            aggregati[mese][tipo].count++;
        });
        
        // // console.log('📊 [CELLS] Dati aggregati:', aggregati);
        
        // Mappa tipi a campi
        const tipoToField = {
            'fatturato': 'entrate_fatturato',
            'erogato': 'erogato_importo',
            'accantonamento_percentuale': 'accantonamento_percentuale',
            'accantonamento_euro': 'accantonamento_euro',
            'tasse_pagamento': 'tasse_euro',
            'diversi': 'diversi_euro',
            'materia_prima_acquisto': 'materia1_euro',
            'unita_acquistate': 'materia1_unita',
            'spedizioni_acquisto': 'sped_euro',
            'unita_spedite': 'sped_unita',
            'spese_varie': 'varie_euro'
        };
        
        // Aggiorna celle
        for (let mese = 1; mese <= 12; mese++) {
            if (aggregati[mese]) {
                if (!this.data.righe[mese]) {
                    this.data.righe[mese] = {};
                }
                
                Object.keys(aggregati[mese]).forEach(tipo => {
                    let fieldsToUpdate = [];
                    
                    // Gestisci tipi con importo E quantità
                    if (tipo === 'materia_prima_acquisto') {
                        fieldsToUpdate = [
                            { field: 'materia1_euro', value: aggregati[mese][tipo].importo },
                            { field: 'materia1_unita', value: aggregati[mese][tipo].quantita }
                        ];
                    } else if (tipo === 'spedizioni_acquisto') {
                        fieldsToUpdate = [
                            { field: 'sped_euro', value: aggregati[mese][tipo].importo },
                            { field: 'sped_unita', value: aggregati[mese][tipo].quantita }
                        ];
                    } else {
                    const field = tipoToField[tipo];
                    if (field) {
                        const value = aggregati[mese][tipo].quantita > 0 
                            ? aggregati[mese][tipo].quantita 
                            : aggregati[mese][tipo].importo;
                            fieldsToUpdate = [{ field, value }];
                        }
                    }
                    
                    // Aggiorna ogni campo
                    fieldsToUpdate.forEach(({ field, value }) => {
                        this.data.righe[mese][field] = value;
                        
                        const cell = document.querySelector(`.cell-readonly[data-mese="${mese}"][data-field="${field}"][data-anno="${anno}"]`);
                        if (cell) {
                            const valueSpan = cell.querySelector('.cell-value');
                            if (valueSpan) {
                                if (field.includes('unita')) {
                                    valueSpan.textContent = value;
                                } else if (field === 'accantonamento_percentuale') {
                                    valueSpan.textContent = value.toFixed(2) + '%';
                                } else {
                                    valueSpan.textContent = value.toFixed(2) + ' €';
                                }
                            }
                            
                            // Add has-data se value != 0 (include negativi, tranne per erogato che è già filtrato)
                            // Per erogato manteniamo > 0 perché i negativi sono esclusi a monte
                            const shouldAddHasData = field === 'erogato_importo' ? value > 0 : value !== 0;
                            // console.log(`🔴 [HAS-DATA CHECK populateCells] Anno=${anno}, Mese=${mese}, Field=${field}, Value=${value}, Will add: ${shouldAddHasData}`);
                            if (shouldAddHasData) {
                                // console.log(`✅ [HAS-DATA ADD populateCells] Adding to ${field} - Anno=${anno}, Mese=${mese}, Cell:`, cell);
                                cell.classList.add('has-data');
                            }
                            
                            // // console.log(`✅ [CELLS] Cella aggiornata: mese=${mese}, field=${field}, value=${value}`);
                    }
                    });
                });
            }
        }
        
        // // console.log('✅ [CELLS] Popolamento celle completato');
        
        // Aggiorna le percentuali TAX PLAFOND dopo aver popolato tutte le celle
        this.updateTaxPlafondPercentages(anno);
    }
    
    /**
     * Calcola e aggiorna la colonna % TAX PLAFOND per ogni mese
     * Formula: (€ TAX PLAFOND / € EROGATO) × 100
     */
    updateTaxPlafondPercentages(anno) {
        for (let mese = 1; mese <= 12; mese++) {
            const riga = this.data.righe[mese];
            if (!riga) continue;
            
            const taxPlafondEuro = parseFloat(riga.accantonamento_euro) || 0;
            const erogato = parseFloat(riga.erogato_importo) || 0;
            
            // Calcola % TAX PLAFOND
            let percentuale = 0;
            if (erogato > 0) {
                percentuale = (taxPlafondEuro / erogato) * 100;
            }
            
            // Aggiorna il valore nel data model
            riga.accantonamento_percentuale = percentuale;
            
            // Aggiorna la cella nella tabella
            const cell = document.querySelector(`.cell-readonly[data-mese="${mese}"][data-field="accantonamento_percentuale"][data-anno="${anno}"]`);
            if (cell) {
                const valueSpan = cell.querySelector('.cell-value');
                if (valueSpan) {
                    valueSpan.textContent = percentuale.toFixed(2) + '%';
                }
            }
        }
    }
    
    /**
     * Calcola e aggiorna la colonna % TAX PLAFOND per gli anni clonati
     * Versione che legge i valori dal DOM invece che da this.data.righe
     * Formula: (€ TAX PLAFOND / € EROGATO) × 100
     */
    updateTaxPlafondPercentagesForClonedYear(anno) {
        for (let mese = 1; mese <= 12; mese++) {
            // Leggi i valori dalle celle del DOM
            const taxPlafondCell = document.querySelector(`[data-mese="${mese}"][data-field="accantonamento_euro"][data-anno="${anno}"] .cell-value`);
            const erogCell = document.querySelector(`[data-mese="${mese}"][data-field="erogato_importo"][data-anno="${anno}"] .cell-value`);
            
            const taxPlafondEuro = taxPlafondCell ? this.parseEuroValueSimple(taxPlafondCell.textContent) : 0;
            const erogato = erogCell ? this.parseEuroValueSimple(erogCell.textContent) : 0;
            
            // Calcola % TAX PLAFOND
            let percentuale = 0;
            if (erogato > 0) {
                percentuale = (taxPlafondEuro / erogato) * 100;
            }
            
            // Aggiorna la cella nella tabella
            const cell = document.querySelector(`.cell-readonly[data-mese="${mese}"][data-field="accantonamento_percentuale"][data-anno="${anno}"]`);
            if (cell) {
                const valueSpan = cell.querySelector('.cell-value');
                if (valueSpan) {
                    valueSpan.textContent = percentuale.toFixed(2) + '%';
                }
            }
        }
    }
    
    /**
     * Applica il colore rosso a tutti i valori negativi nella tabella
     */
    applyNegativeValueStyles() {
        // Seleziona tutte le celle con .cell-value (tabella principale)
        const allCellValues = document.querySelectorAll('.cell-value');
        
        allCellValues.forEach(span => {
            const text = span.textContent.trim();
            
            // Controlla se il testo contiene un segno negativo (-)
            // Supporta formati: -1000 €, -1000, -1.000,00 €, etc.
            if (text.includes('-') && text !== '-') {
                span.classList.add('negative-value');
            } else {
                span.classList.remove('negative-value');
            }
        });
        
        // Seleziona tutte le celle KPI nelle righe 2, 3, 4 con id che inizia con "kpi-"
        const kpiRows234 = document.querySelectorAll('[data-row="2"], [data-row="3"], [data-row="4"]');
        
        kpiRows234.forEach(row => {
            const kpiCells = row.querySelectorAll('td[id^="kpi-"]');
            
            kpiCells.forEach(cell => {
                const text = cell.textContent.trim();
                
                // Controlla se il testo contiene un segno negativo (-)
                if (text.includes('-') && text !== '-') {
                    cell.classList.add('negative-value');
                } else {
                    cell.classList.remove('negative-value');
                }
            });
        });
        
        // Gestisce la riga 5 che ha una struttura diversa (valori dentro <span>)
        const row5 = document.querySelector('[data-row="5"]');
        if (row5) {
            // Cerca tutti gli <span> con id nella riga 5
            const spanElements = row5.querySelectorAll('span[id]');
            
            spanElements.forEach(span => {
                const text = span.textContent.trim();
                
                // Controlla se il testo contiene un segno negativo (-)
                if (text.includes('-') && text !== '-') {
                    span.classList.add('negative-value');
                } else {
                    span.classList.remove('negative-value');
                }
            });
        }
        
        // Gestisce i div della card "Flusso Principale" (flow-fba-totale, etc.)
        const flowElements = document.querySelectorAll('div[id^="flow-"]');
        flowElements.forEach(div => {
            const text = div.textContent.trim();
            
            // Controlla se il testo contiene un segno negativo (-)
            if (text.includes('-') && text !== '-') {
                div.classList.add('negative-value');
            } else {
                div.classList.remove('negative-value');
            }
        });
    }
    
    async editTransaction(id) {
        try {
            console.log('🔄 [EDIT] Caricamento transazione ID:', id);
            
            const response = await fetch(`?action=get_input_utente&id=${id}`);
            const result = await response.json();
            
            console.log('📥 [EDIT] Response:', result);
            
            if (!result.success || !result.data || result.data.length === 0) {
                this.showMessage('Transazione non trovata', 'error');
                return;
            }
            
            const trans = result.data[0];
            console.log('📋 [EDIT] Dati transazione:', trans);
            
            // Popola form con dati transazione
            document.getElementById('trans-tipo').value = trans.tipo_input;
            document.getElementById('trans-data').value = trans.data.split(' ')[0];
            document.getElementById('trans-importo').value = Math.abs(parseFloat(trans.importo)); // Mostra valore assoluto
            document.getElementById('trans-quantita').value = trans.quantita || '';
            
            // Mostra campi appropriati PRIMA di popolare le note
            // (toggleTransactionFields svuota il campo note)
            this.toggleTransactionFields();
            
            // IMPORTANTE: Popola nota DOPO toggleTransactionFields
            const noteField = document.getElementById('trans-note');
            noteField.value = trans.note || '';
            noteField.removeAttribute('readonly');
            noteField.removeAttribute('disabled');
            noteField.placeholder = 'Descrizione...';
            
            console.log('📝 [EDIT] Campo note popolato:', noteField.value);
            console.log('📝 [EDIT] Note readonly:', noteField.hasAttribute('readonly'));
            console.log('📝 [EDIT] Note disabled:', noteField.hasAttribute('disabled'));
            
            // Chiudi tooltip
            this.hideCellTooltip();
            
            // Scroll to form
            document.getElementById('transaction-form').scrollIntoView({ behavior: 'smooth' });
            
            // Memorizza ID per update invece di insert
            document.getElementById('transaction-form').dataset.editId = id;
            
            // Cambia testo bottone
            document.getElementById('btn-save-trans').innerHTML = '✅ Aggiorna';
            
            console.log('✅ [EDIT] Form popolato, ID memorizzato:', id);
            
        } catch (error) {
            console.error('❌ [EDIT] Errore caricamento transazione:', error);
            this.showMessage('Errore caricamento transazione', 'error');
        }
    }
    
    async deleteTransaction(id) {
        if (!confirm('Sei sicuro di voler eliminare questa transazione?')) {
            return;
        }
        
        try {
            const response = await fetch(`?action=delete_input_utente&id=${id}`, {
                method: 'POST'
            });
            const result = await response.json();
            
            if (result.success) {
                this.showMessage('Transazione eliminata', 'success');
                
                // Chiudi tooltip
                this.hideCellTooltip();
                
                // Ricarica dati
                await this.loadInitialData();
            } else {
                this.showMessage(result.error || 'Errore eliminazione', 'error');
            }
        } catch (error) {
            console.error('Errore eliminazione transazione:', error);
            this.showMessage('Errore eliminazione transazione', 'error');
        }
    }
}

// Global instance
window.RendicontoApp = RendicontoApp; 