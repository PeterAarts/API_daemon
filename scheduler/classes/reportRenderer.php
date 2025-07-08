<?php
// FILE: scheduler/classes/ReportRenderer.php
// PURPOSE: Handles the actual rendering of content (tables, charts) to PDF and HTML using mPDF.
// This class replaces the procedural logic from sqlreporter.php and the CreateHeader function.

namespace ApiDaemon\Scheduler;

use Mpdf\Mpdf;
use Exception;
use ApiDaemon\Log;

// Note: We no longer need 'require_once' for JPGraph. Composer's autoloader handles this.
// We will call JPGraph classes from the global namespace using a leading backslash, e.g., \Graph.

class ReportRenderer {
    private $mpdf;
    private $htmlBody = '';
    private $embeddedImages = [];
    private $docname;
    private $report;

    /**
     * The constructor initializes the renderer for a specific report.
     * @param Report $report The data object for the report being generated.
     */
    public function __construct(Report $report) {
        $this->report = $report;
        $this->docname = $report->customerName . "_" . $report->name . '_' . date("Ymd_Hi");

        // Initialize mPDF with configuration from the report object.
        // The tempDir path is relative to this file's location, pointing to the root /tmp/ directory.
        $this->mpdf = new Mpdf([
            'orientation' => $report->orientation,
            'tempDir' => __DIR__ . '/../../tmp', 
            'default_font' => 'helvetica'
        ]);

        $this->mpdf->SetTitle($report->name);
        $this->mpdf->SetAuthor(ReportConfig::PDF_AUTHOR);
        
        // Set a dynamic header and footer for the PDF pages.
        $this->mpdf->SetHeader($report->customerName . ' | ' . $report->name . ' | {DATE j-m-Y}');
        $this->mpdf->SetFooter('{PAGENO}');

        // Start building the HTML body for the email.
        $this->htmlBody = $this->createHtmlHeader();
    }

    /**
     * The main public method to add a piece of content to the report.
     * It intelligently decides whether to draw a table or a chart.
     */
    public function addContent($queryName, $queryDescription, array $data, $displayType, $chartOptions) {
        // Add a page break before each new section in the PDF, except the very first one.
        if ($this->mpdf->page > 0) {
            $this->mpdf->AddPage();
        }

        switch (strtoupper($displayType)) {
            case 'BAR_CHART':
            case 'LINE_CHART':
                $this->addChart($queryName, $queryDescription, $data, $chartOptions, $displayType);
                break;
            case 'TABLE':
            default:
                $this->addTable($queryName, $queryDescription, $data);
                break;
        }
    }

    /**
     * Finalizes the report and returns an array with the generated file paths and HTML content.
     */
    public function getGeneratedFiles() {
        // Save the final PDF to a file in the temp/cache directory.
        $pdfPath = dirname(__FILE__).'/../../../temp/cache/'.$this->docname.'.pdf';
        $this->mpdf->Output($pdfPath, 'F');

        // Clean up any temporary chart images that were created.
        foreach ($this->embeddedImages as $cid => $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $this->htmlBody .= $this->createHtmlFooter(); // Close the HTML tags for the email body.

        return [
            'pdf_path' => $pdfPath,
            'html_body' => $this->htmlBody,
            'embedded_images' => $this->embeddedImages,
            'docname' => $this->docname
        ];
    }

    // --- Private Helper Methods ---

    private function addTable($queryName, $queryDescription, array $data) {
        if (empty($data)) return;
        $html = $this->generateHtmlForTable($queryName, $queryDescription, $data);
        $this->mpdf->WriteHTML($html);
        $this->htmlBody .= $html;
    }

    private function addChart($queryName, $queryDescription, array $data, $chartOptions, $chartType) {
        if (empty($data)) return;
        $imagePath = $this->createChartImage($data, $chartOptions, $chartType);
        
        if ($imagePath) {
            // For the PDF, we embed the image using its file path.
            $htmlForPdf = "<h3>{$queryName}</h3><p>{$queryDescription}</p><p><img src='{$imagePath}' style='width:100%;'></p>";
            $this->mpdf->WriteHTML($htmlForPdf);
            
            // For the email, we prepare the image for embedding using a Content-ID (cid).
            $cid = 'chart_'.basename($imagePath, '.png');
            $this->embeddedImages[$cid] = $imagePath;
            $this->htmlBody .= "<h3>{$queryName}</h3><p>{$queryDescription}</p><p><img src='cid:{$cid}'></p>";
        }
    }

    private function createChartImage(array $data, $chartOptions, $chartType) {
        try {
            $labels = array_column($data, $chartOptions->label_column);
            $values = array_column($data, $chartOptions->value_column);

            // Use a leading backslash to call the global JPGraph classes.
            $graph = new \Graph(800, 400);
            $graph->SetScale('textlin');
            $graph->xaxis->SetTickLabels($labels);
            $graph->title->Set($chartOptions->title ?? 'Chart');

            if (strtoupper($chartType) === 'BAR_CHART') {
                $plot = new \BarPlot($values);
                $graph->Add($plot);
            } else {
                $plot = new \LinePlot($values);
                $graph->Add($plot);
            }

            $chartFileName = dirname(__FILE__).'/../../../temp/cache/chart_' . uniqid() . '.png';
            $graph->Stroke($chartFileName);
            return $chartFileName;
        } catch (Exception $e) {
            Log::error("Failed to generate chart.", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function generateHtmlForTable($queryName, $queryDescription, array $data) {
        $html = "<h3>{$queryName}</h3><p>{$queryDescription}</p>";
        $html .= "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%; font-family: sans-serif; font-size: 10px;'>";
        
        $html .= "<thead><tr style='background-color:#f3f3f3; font-weight: bold;'>";
        foreach (array_keys($data[0]) as $header) {
            $html .= "<th>{$header}</th>";
        }
        $html .= "</tr></thead>";
        
        $html .= "<tbody>";
        foreach ($data as $row) {
            $html .= "<tr>";
            foreach ($row as $cell) {
                $align = is_numeric($cell) ? 'center' : 'left';
                $html .= "<td style='text-align: {$align};'>{$cell}</td>";
            }
            $html .= "</tr>";
        }
        $html .= "</tbody></table><br pagebreak='true' />";
        return $html;
    }

    private function createHtmlHeader() {
        // This logic is moved from your old config.php -> CreateHeader() function.
        // It uses data from the Report object passed into the constructor.
        $logoPath = ($_ENV['APP_ROOT_PATH'] ?? __DIR__ . '/../../..') . '/images/logo.png';
        $cid = 'logo_cid';
        $this->embeddedImages[$cid] = $logoPath;

        $html = "<html><head><style>
                    body { font-family: sans-serif; color: #333; }
                    .container { max-width: 1200px; margin: auto; }
                    h1 { font-size: 24px; color: #24436c; }
                    h3 { font-size: 16px; font-weight: 700; margin:15px 0; color:#24436c; }
                    p { font-size: 12px; color: #555; }
                    table { page-break-inside: auto; }
                    tr { page-break-inside: avoid; page-break-after: auto; }
                 </style></head><body>";
        $html .= "<div class='container'>";
        $html .= "<div><img src='cid:{$cid}'><h1>{$this->report->description}</h1></div>";
        $html .= "<p><strong>Report:</strong> {$this->report->name}</p>";
        $html .= "<p><strong>Customer:</strong> {$this->report->customerName}</p>";
        $html .= "<p><strong>Date:</strong> " . date('Y-m-d H:i') . "</p>";
        $html .= "<br pagebreak='true' />";
        return $html;
    }

    private function createHtmlFooter() {
        $footer = "<div style='text-align:center; font-size:9px; color:#777; margin-top:20px;'>";
        $footer .= "This is an automated report generated by the rFMSConnect system.";
        $footer .= "</div></div></body></html>";
        return $footer;
    }
}
?>
