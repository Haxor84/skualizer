/**
 * Margins Charts - Sistema Grafici Dashboard con Chart.js
 * File: modules/margynomic/margini/margins_charts.js
 */

class MarginsCharts {
    constructor() {
        this.charts = {};
        this.initialized = false;
        this.init();
    }

    init() {
        if (this.initialized) {
            return;
        }
        
        if (typeof Chart === 'undefined') {
            console.error('❌ Chart.js not loaded!');
            return;
        }
        
        // Inizializza subito se il DOM è pronto, altrimenti aspetta
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                this.initializeCharts();
            });
        } else {
            // Piccolo delay per essere sicuri che tutto sia pronto
            setTimeout(() => {
                this.initializeCharts();
            }, 50);
        }
        
        this.initialized = true;
    }

    async initializeCharts() {
        
        const revenueContainer = document.getElementById('revenueVsFeeChart');
        const topProductsContainer = document.getElementById('topProductsChart');
        
        if (!revenueContainer || !topProductsContainer) {
            console.error('❌ Container non trovati! Revenue:', !!revenueContainer, 'TopProducts:', !!topProductsContainer);
            return;
        }
        
        // Crea entrambi i grafici in parallelo
        const promises = [];
        
        if (revenueContainer) {
            promises.push(this.createRevenueVsFeeChart());
        }
        
        if (topProductsContainer) {
            promises.push(this.createTopProductsChart());
        }
        
        try {
            await Promise.all(promises);
        } catch (error) {
            console.error('❌ Errore durante la creazione dei grafici:', error);
        }
    }

    async createRevenueVsFeeChart() {
        const container = document.getElementById('revenueVsFeeChart');
        
        if (!container) {
            console.error('❌ Container revenueVsFeeChart non trovato');
            return;
        }

        try {
            // Mostra loading
            container.innerHTML = '<div style="display: flex; justify-content: center; align-items: center; height: 200px; color: #666;"><i class="fas fa-spinner fa-spin"></i> Caricamento...</div>';
            const response = await fetch('get_chart_data.php?type=revenue_vs_fee', {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('❌ JSON parse error:', parseError);
                throw new Error('Risposta non valida dal server');
            }
            
            if (!result.success) {
                throw new Error(result.error || 'Errore dal server');
            }
            
            // Pulisci container e crea canvas
            container.innerHTML = '<canvas id="revenueVsFeeCanvas" style="width: 100%; height: 400px;"></canvas>';
            
            const canvas = document.getElementById('revenueVsFeeCanvas');
            if (!canvas) {
                throw new Error('Canvas non creato correttamente');
            }
            
            const ctx = canvas.getContext('2d');
            
            // Distruggi grafico esistente se presente
            if (this.charts.revenueVsFee) {
                this.charts.revenueVsFee.destroy();
            }
            
            // Crea grafico multi-asse migliorato
            this.charts.revenueVsFee = new Chart(ctx, {
                type: 'line',
                data: result.data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: `Performance Giornaliera (${result.data.metadata?.total_days || 0} giorni) - Margine medio: ${result.data.metadata?.avg_margin || 0}%`,
                            font: { size: 14, weight: 'bold' },
                            padding: 20
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 12,
                                font: { size: 10 },
                                generateLabels: function(chart) {
                                    const original = Chart.defaults.plugins.legend.labels.generateLabels;
                                    const labels = original.call(this, chart);
                                    
                                    // Aggiungi indicatori per gli assi
                                    labels.forEach((label, index) => {
                                        if (index === 0 || index === 1) label.text += ' (sx)';
                                        if (index === 2) label.text += ' (dx)';
                                        if (index === 3) label.text += ' (dx)';
                                    });
                                    
                                    return labels;
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.9)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            borderColor: '#374151',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true,
                            callbacks: {
                                title: function(context) {
                                    return `📅 Data: ${context[0].label}`;
                                },
                                label: function(context) {
                                    const datasetLabel = context.dataset.label;
                                    const value = context.parsed.y;
                                    
                                    if (datasetLabel.includes('Revenue') || datasetLabel.includes('Fee')) {
                                        return `${datasetLabel}: €${Math.abs(value).toLocaleString('it-IT', {minimumFractionDigits: 2})}`;
                                    } else if (datasetLabel.includes('Margine')) {
                                        return `${datasetLabel}: ${value}%`;
                                    } else if (datasetLabel.includes('Unità')) {
                                        return `${datasetLabel}: ${Math.round(value)} unità`;
                                    }
                                    return `${datasetLabel}: ${value}`;
                                },
                                footer: function(context) {
                                    if (context.length > 0) {
                                        const index = context[0].dataIndex;
                                        const feePerUnit = result.data.metadata?.fee_per_unit?.[index];
                                        if (feePerUnit !== undefined) {
                                            return `💰 Fee per unità: €${Math.abs(feePerUnit).toFixed(2)}`;
                                        }
                                    }
                                    return '';
                                }
                            }
                        }
                    },
                    scales: {
                        'y-left': {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Euro (€)',
                                color: '#16a34a',
                                font: { weight: 'bold', size: 12 }
                            },
                            grid: {
                                color: 'rgba(22, 163, 74, 0.1)'
                            },
                            ticks: {
                                color: '#16a34a',
                                font: { size: 10 },
                                callback: function(value) {
                                    return '€' + Math.abs(value).toLocaleString('it-IT');
                                }
                            }
                        },
                        'y-right': {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Margine % | Unità',
                                color: '#2563eb',
                                font: { weight: 'bold', size: 12 }
                            },
                            grid: {
                                drawOnChartArea: false,
                            },
                            ticks: {
                                color: '#2563eb',
                                font: { size: 10 },
                                callback: function(value) {
                                    return Math.round(value);
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Giorni',
                                font: { weight: 'bold', size: 12 }
                            },
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: { size: 10 }
                            }
                        }
                    },
                    elements: {
                        point: {
                            radius: 3,
                            hoverRadius: 8,
                            borderWidth: 2
                        },
                        line: {
                            borderWidth: 2.5
                        }
                    }
                }
            });
            
        } catch (error) {
            console.error('❌ Errore creazione grafico Revenue:', error);
            container.innerHTML = `
                <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; height: 200px; color: #ef4444; text-align: center; padding: 20px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <div style="font-weight: bold; margin-bottom: 0.5rem;">Errore caricamento grafico</div>
                    <small style="color: #666;">${error.message}</small>
                    <button onclick="marginsCharts.createRevenueVsFeeChart()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #38a169; color: white; border: none; border-radius: 5px; cursor: pointer;">🔄 Riprova</button>
                </div>
            `;
        }
    }

    async createTopProductsChart() {
        const container = document.getElementById('topProductsChart');
        
        if (!container) {
            console.error('❌ Container topProductsChart non trovato');
            return;
        }

        try {
            // Mostra loading
            container.innerHTML = '<div style="display: flex; justify-content: center; align-items: center; height: 200px; color: #666;"><i class="fas fa-spinner fa-spin"></i> Caricamento...</div>';
            const response = await fetch('get_chart_data.php?type=top_products', {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('❌ JSON parse error:', parseError);
                throw new Error('Risposta non valida dal server');
            }
            
            if (!result.success) {
                throw new Error(result.error || 'Errore dal server');
            }
            
            // Pulisci container e crea canvas
            container.innerHTML = '<canvas id="topProductsCanvas" style="width: 100%; height: 400px;"></canvas>';
            
            const canvas = document.getElementById('topProductsCanvas');
            if (!canvas) {
                throw new Error('Canvas non creato correttamente');
            }
            
            const ctx = canvas.getContext('2d');
            
            // Distruggi grafico esistente se presente
            if (this.charts.topProducts) {
                this.charts.topProducts.destroy();
            }
            
            // Crea horizontal bar chart avanzato
            this.charts.topProducts = new Chart(ctx, {
                type: 'bar',
                data: result.data,
                options: {
                    indexAxis: 'y', // Horizontal bar chart
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'nearest',
                        intersect: false,
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: `Top ${result.data.metadata?.total_products || 7} Prodotti - Utile per Unità`,
                            font: { size: 14, weight: 'bold' },
                            padding: 20
                        },
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { size: 11 },
                                generateLabels: function(chart) {
                                    // Genera legende per le fasce di colore
                                    return [
                                        {
                                            text: '🔴 Margine < 0%',
                                            fillStyle: 'rgba(239, 68, 68, 0.8)',
                                            strokeStyle: '#ef4444',
                                            pointStyle: 'circle'
                                        },
                                        {
                                            text: '🟡 Margine 0-10%',
                                            fillStyle: 'rgba(245, 158, 11, 0.8)',
                                            strokeStyle: '#f59e0b',
                                            pointStyle: 'circle'
                                        },
                                        {
                                            text: '🟢 Margine > 10%',
                                            fillStyle: 'rgba(16, 185, 129, 0.8)',
                                            strokeStyle: '#10b981',
                                            pointStyle: 'circle'
                                        }
                                    ];
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.9)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            borderColor: '#374151',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                title: function(context) {
                                    const index = context[0].dataIndex;
                                    const productDetails = result.data.metadata?.product_details?.[index];
                                    return `📦 ${productDetails?.name || context[0].label}`;
                                },
                                label: function(context) {
                                    const index = context.dataIndex;
                                    const productDetails = result.data.metadata?.product_details?.[index];
                                    const value = context.parsed.x;
                                    
                                    if (productDetails) {
                                        return `Prodotto: ${productDetails.name} | Utile/unità: €${value.toFixed(2)} | Margine: ${productDetails.margin_percent} | Vendite totali: ${productDetails.units} unità`;
                                    }
                                    return `Utile/unità: €${value.toFixed(2)}`;
                                },
                                footer: function(context) {
                                    const index = context[0].dataIndex;
                                    const productDetails = result.data.metadata?.product_details?.[index];
                                    
                                    if (productDetails) {
                                        const marginNum = parseFloat(productDetails.margin_percent);
                                        let performance = '';
                                        if (marginNum < 0) performance = '⚠️ Prodotto in perdita';
                                        else if (marginNum <= 5) performance = '📉 Margine basso';
                                        else if (marginNum <= 10) performance = '📊 Margine medio';
                                        else performance = '🚀 Margine eccellente';
                                        
                                        return `${performance} | Utile totale: €${productDetails.total_profit.toFixed(2)}`;
                                    }
                                    return '';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            type: 'linear',
                            position: 'bottom',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Utile per Unità (€)',
                                font: { weight: 'bold', size: 12 },
                                color: '#2d3748'
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.1)',
                                drawBorder: true
                            },
                            ticks: {
                                font: { size: 10 },
                                callback: function(value) {
                                    return '€' + value.toFixed(2);
                                }
                            }
                        },
                        y: {
                            type: 'category',
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Prodotti',
                                font: { weight: 'bold', size: 12 },
                                color: '#2d3748'
                            },
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: { size: 11 },
                                callback: function(value, index) {
                                    const marginLabel = result.data.metadata?.margin_labels?.[index];
                                    return marginLabel ? `${this.getLabelForValue(value)} (${marginLabel})` : this.getLabelForValue(value);
                                }
                            }
                        }
                    },
                    elements: {
                        bar: {
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false
                        }
                    },
                    layout: {
                        padding: {
                            left: 10,
                            right: 20,
                            top: 10,
                            bottom: 10
                        }
                    }
                }
            });
            
        } catch (error) {
            console.error('❌ Errore creazione grafico Top Products:', error);
            container.innerHTML = `
                <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; height: 200px; color: #ef4444; text-align: center; padding: 20px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <div style="font-weight: bold; margin-bottom: 0.5rem;">Errore caricamento grafico</div>
                    <small style="color: #666;">${error.message}</small>
                    <button onclick="marginsCharts.createTopProductsChart()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #38a169; color: white; border: none; border-radius: 5px; cursor: pointer;">🔄 Riprova</button>
                </div>
            `;
        }
    }

    // Metodo per aggiornare i dati dei grafici
    async updateCharts() {
        await this.initializeCharts();
    }

    // Metodo per resize responsive
    handleResize() {
        Object.values(this.charts).forEach(chart => {
            if (chart && typeof chart.resize === 'function') {
                chart.resize();
            }
        });
    }

    // Metodo per distruggere i grafici (cleanup)
    destroyCharts() {
        Object.values(this.charts).forEach(chart => {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        });
        this.charts = {};
    }

    // Metodo per ricaricare manualmente un singolo grafico
    async reloadChart(chartType) {
        
        if (chartType === 'revenue') {
            await this.createRevenueVsFeeChart();
        } else if (chartType === 'products') {
            await this.createTopProductsChart();
        } else {
            console.error('❌ Chart type non valido:', chartType);
        }
    }
}

// Inizializza i grafici quando il file viene caricato
const marginsCharts = new MarginsCharts();

// Handle resize
window.addEventListener('resize', () => {
    if (marginsCharts) {
        marginsCharts.handleResize();
    }
});

// Export per uso globale
window.MarginsCharts = MarginsCharts;
window.marginsCharts = marginsCharts;