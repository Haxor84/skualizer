<?php
/**
 * Esporta in PDF la lista delle varianti da rifornire
 * Percorso: /modules/previsync/inventory_export_pdf.php
 *
 * Questo script genera un PDF professionale che riporta solo i prodotti
 * per i quali il sistema suggerisce un rifornimento (invio_suggerito > 0).
 * Il layout è pensato per essere leggibile e coerente con lo stile Margynomic.
 * Viene creato un'intestazione con titolo e data e un piè di pagina con
 * numerazione delle pagine. Le righe sono evidenziate per criticità.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 1. Inclusioni base e controlli di autenticazione
// -----------------------------------------------------------------------------
require_once dirname(__DIR__) . '/margynomic/config/config.php';
require_once dirname(__DIR__) . '/margynomic/login/auth_helpers.php';

// Includiamo inventory.php solo per definire la classe InventoryAnalyzer
ob_start();
require_once dirname(__DIR__) . '/previsync/inventory.php';
ob_end_clean();

// Controllo login utente
if (!isLoggedIn()) {
    header('Location: ../margynomic/login/login.php');
    exit;
}

$currentUser = getCurrentUser();
$userId      = (int)($currentUser['id'] ?? 0);

// -----------------------------------------------------------------------------
// 2. Recupero e filtro dati inventario
// -----------------------------------------------------------------------------
$analyzer      = new InventoryAnalyzer($userId);
$inventoryData = $analyzer->getCompleteInventoryAnalysis();
$analysis      = $inventoryData['analysis'] ?? [];

// Filtra solo i prodotti con rifornimento necessario (ricalcolato)
$analysis = array_filter($analysis, static function (array $item): bool {
    $invio_aggiornato = $item['invio_suggerito'];
    if (isset($item['media_vendite_1d']) && $item['media_vendite_1d'] > 0) {
        $fabbisogno_60gg = $item['media_vendite_1d'] * 60;
        $stock_totale = $item['disponibili'] + $item['in_arrivo'];
        $invio_aggiornato = max(0, round($fabbisogno_60gg - $stock_totale));
    }
    return $invio_aggiornato > 0;
});

if (empty($analysis)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nessun prodotto da rifornire.';
    exit;
}

// Raggruppa le righe per criticità per poterle presentare in sezioni distinte
// e definisce l'ordine e le etichette dei gruppi. Gli emoji vengono
// rimossi poiché le font di default di TCPDF non li supportano.
$grouped = [];
foreach ($analysis as $row) {
    // normalizza chiave di criticità
    $level = isset($row['criticita']) ? (string)$row['criticita'] : 'neutro';
    $grouped[$level][] = $row;
}

// Ordina i gruppi secondo una sequenza logica e associa etichette descrittive.
// Le etichette non contengono emoji per evitare caratteri mancanti nel PDF.
$priorityOrder = [
    'alto'    => 'Alta criticità',
    'medio'   => 'Media criticità',
    'basso'   => 'Bassa criticità',
    'avvia'   => 'Avvia rotazione',
    'elimina' => 'Da eliminare',
    'neutro'  => 'Neutro',
];

// -----------------------------------------------------------------------------
// 3. Carica TCPDF e definisci classe personalizzata
// -----------------------------------------------------------------------------
$tcpdfPath = __DIR__ . '/../margynomic/vendor/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Errore: libreria TCPDF non trovata.';
    exit;
}
require_once $tcpdfPath;

// Estendiamo TCPDF per personalizzare header e footer
class InventoryReportPDF extends TCPDF
{
    /** @var string */
    public $reportTitle = '';

    /** @var string */
    public $reportDate = '';

    public function Header(): void
    {
        // Stampa titolo e data nell'intestazione
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(255, 107, 53); // colore primario Margynomic (arancione)
        $this->Cell(0, 7, $this->reportTitle, 0, 1, 'L', false);
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, $this->reportDate, 0, 0, 'L', false);
        // Linea di separazione
        $this->Ln(4);
        $this->SetDrawColor(255, 107, 53);
        $this->Line(10, 22, $this->getPageWidth() - 10, 22);
        $this->Ln(4);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $page  = $this->getAliasNumPage();
        $pages = $this->getAliasNbPages();
        $this->Cell(0, 10, 'Pagina ' . $page . ' di ' . $pages, 0, 0, 'C');
    }
}

// -----------------------------------------------------------------------------
// 4. Istanzia e configura PDF
// -----------------------------------------------------------------------------
$pdf = new InventoryReportPDF('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('Previsync');
$pdf->SetAuthor('Previsync');
$pdf->SetTitle('Report Rifornimento');

// Margini: spazio extra in alto per l'intestazione
$pdf->SetMargins(10, 30, 10);
$pdf->SetAutoPageBreak(true, 20);

// Popola i dati per header
$pdf->reportTitle = 'Report di Rifornimento';
$pdf->reportDate  = 'Generato il: ' . date('d/m/Y H:i');

// Aggiunge la prima pagina
$pdf->AddPage();

// Imposta font base per il contenuto
$pdf->SetFont('helvetica', '', 9);

/* --------------------------------------------------------------------------
 * 4bis. Generazione del contenuto tramite tabelle HTML
 *
 * Per evitare problemi di overflow e pagine vuote dovuti a celle con sfondo
 * che attraversano i page break, si utilizza un approccio basato su HTML. Le
 * tabelle HTML con un elemento THEAD replicano automaticamente l’intestazione
 * su pagine successive. Ogni gruppo di criticità è separato da un titolo
 * colorato che richiama il tema Margynomic. Le icone emoji sono omesse per
 * garantire la compatibilità con i font standard.
 */
// Mappa dei colori per i titoli dei gruppi
$groupTitleColors = [
    'alto'    => '#ff3547', // rosso
    'medio'   => '#ffb400', // giallo
    'basso'   => '#17a2b8', // azzurro
    'avvia'   => '#007bff', // blu
    'elimina' => '#6c757d', // grigio
    'neutro'  => '#00c851', // verde
];

// Mappa dei colori del testo per la colonna Urgenza
$urgencyTextColors = [
    'alto'    => '#ff3547',
    'medio'   => '#ffb400',
    'basso'   => '#17a2b8',
    'avvia'   => '#007bff',
    'elimina' => '#6c757d',
    'neutro'  => '#00c851',
];

// Costruisci l'HTML per tutti i gruppi
$html = '';
foreach ($priorityOrder as $key => $label) {
    if (empty($grouped[$key])) {
        continue;
    }
    // Titolo sezione
    $bgColor = $groupTitleColors[$key] ?? '#cccccc';
    $html .= '<div style="font-size:11pt; font-weight:bold; color:#ffffff;'
            . ' background-color:' . $bgColor . '; padding:4px; margin-top:10px;">'
            . htmlspecialchars($label) . '</div>';
    // Tabella
    $html .= '<table border="1" cellpadding="4" cellspacing="0"'
          . ' style="width:100%; font-family:Helvetica,Arial,sans-serif; font-size:9pt; margin-bottom:10px;">
            <thead>
                <tr style="background-color:#f2f2f2; font-weight:bold;">
                    <th style="width:36%; text-align:left;">Nome</th>
                    <th style="width:8%; text-align:right;">Prezzo</th>
                    <th style="width:8%; text-align:right;">Scorte</th>
                    <th style="width:12%; text-align:left;">FNSKU</th>
                    <th style="width:12%; text-align:right;">Gg residui</th>
                    <th style="width:12%; text-align:center;">Urgenza</th>
                    <th style="width:12%; text-align:right;">Rifornire</th>
                </tr>
            </thead><tbody>';
    foreach ($grouped[$key] as $row) {
        $giorni = ($row['giorni_stock'] == 999) ? '∞' : number_format((float)$row['giorni_stock'], 0, ',', '.');
        // Query diretta per ottenere FNSKU dal nome prodotto
        $fnsku = '';
        try {
            $db = getDbConnection();
            $stmt = $db->prepare("
                SELECT p.fnsku 
                FROM products p 
                WHERE p.user_id = ? AND p.nome = ? 
                LIMIT 1
            ");
            $stmt->execute([$userId, $row['product_name']]);
            $result = $stmt->fetch();
            $fnsku = $result['fnsku'] ?? '';
        } catch (Exception $e) {
            $fnsku = '';
        }
        
        $html .= '<tr>';
        $html .= '<td style="width:36%;">' . htmlspecialchars($row['product_name']) . '</td>';
        $html .= '<td style="width:8%; text-align:right;">€' . number_format((float)$row['your_price'], 2, ',', '.') . '</td>';
        $html .= '<td style="width:8%; text-align:right;">' . number_format((int)$row['disponibili'], 0, ',', '.') . '</td>';
        $html .= '<td style="width:12%; text-align:left; font-family:monospace;">' . 
                 ($fnsku ? htmlspecialchars($fnsku) : '<em style="color:#999;">Non assegnato</em>') . '</td>';
        $html .= '<td style="width:12%; text-align:right;">' . $giorni . '</td>';
        // Urgenza con colore testo
        $color = $urgencyTextColors[$row['criticita']] ?? '#000000';
        $html .= '<td style="width:12%; text-align:center; color:' . $color . '; font-weight:bold;">'
              . htmlspecialchars(ucfirst($row['criticita'])) . '</td>';
        // Ricalcola invio_suggerito con logica aggiornata come in inventory.php
$invio_pdf = $row['invio_suggerito'];
if ($row['media_vendite_1d'] > 0) {
    $fabbisogno_60gg = $row['media_vendite_1d'] * 60;
    $stock_totale = $row['disponibili'] + $row['in_arrivo'];
    $invio_pdf = max(0, round($fabbisogno_60gg - $stock_totale));
}
$html .= '<td style="width:12%; text-align:right;">' . number_format((float)$invio_pdf, 0, ',', '.') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
}

// Scrive l'HTML nel PDF; true per accettare page break, false per non stampare bordo
$pdf->writeHTML($html, true, false, true, false, '');

// -----------------------------------------------------------------------------
// 5. Output del PDF al browser
// -----------------------------------------------------------------------------
while (ob_get_level()) {
    ob_end_clean();
}
$fileName = 'rifornimento_user_' . $userId . '.pdf';
$pdf->Output($fileName, 'D');
exit;