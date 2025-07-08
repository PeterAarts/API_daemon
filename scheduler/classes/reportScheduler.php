<?php
// FILE: scheduler/classes/ReportScheduler.php
// PURPOSE: Finds and processes all reports that are due to run. This is the main
// engine for the reporting module, replacing the logic from the old reporter.php.

namespace ApiDaemon\Scheduler;

use ApiDaemon\DB;
use ApiDaemon\Log;
use Exception;
// Assuming PHPMailer is loaded via Composer's autoloader
use PHPMailer\PHPMailer\PHPMailer;

class ReportScheduler {
    private $db;
    private $orchestrator;

    /**
     * The constructor initializes the necessary components for the scheduler.
     */
    public function __construct() {
        $this->db = DB::getInstance();
        $this->orchestrator = new ReportOrchestrator();
    }

    /**
     * The main execution method for the scheduler.
     * Finds all due reports and processes them one by one.
     * @return int The number of reports processed.
     */
    public function run() {
        Log::info("ReportScheduler: Checking for due reports...");
        echo "\n   ∙ checking reports :" . date('y-m-d H:i:s');

        // This query joins all necessary tables to get the full context for each scheduled report.
        $dueReportsQuery = $this->db->query("
            SELECT
                s.domain, s.site_name, rp.*, r.script, r.colorSchema,
                rp.attachmentType, rp.orientation, u.email, rc.name AS CustomerName,
                r.name as reportname, r.description
            FROM report_planning rp
            LEFT JOIN reporting r ON r.id = rp.report_type
            LEFT JOIN users u ON u.id = rp.creator
            LEFT JOIN customers c ON rp.cust_id = c.id
            LEFT JOIN customers rc ON rp.report_cust_id = rc.id
            LEFT JOIN settings s ON s.customer_id = c.id
            WHERE
                rp.status = 1 AND date(rp.nextRunDateTime) <= curdate()"
        );

        $dueReports = $dueReportsQuery->results();
        $processedCount = 0;

        if (empty($dueReports)) {
            Log::info("ReportScheduler: No reports due to run at this time.");
            echo ", 0 reports found.";
            return 0;
        }

        echo ', ' . count($dueReports) . ' reports found.';
        Log::info("ReportScheduler: Found " . count($dueReports) . " reports to process.");

        foreach ($dueReports as $dueReportData) {
            try {
                // Create a data object for the report for easy data passing.
                $report = new Report($dueReportData);
                
                echo "\n     * Processing report: " . $report->name . " for " . $report->customerName;
                Log::info("Processing report: " . $report->name, ['customer' => $report->customerName, 'report_id' => $report->report_id]);

                // Use the orchestrator to build the report files (PDF, HTML, etc.)
                $generatedFiles = $this->orchestrator->build($report);

                // Email the results
                $this->sendEmail($report, $generatedFiles);

                // Reschedule for the next run
                $this->reschedule($dueReportData);
                
                $processedCount++;

            } catch (Exception $e) {
                Log::error("Failed to process report.", [
                    'report_id' => $dueReportData->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        echo "\n   ∙ " . $processedCount . " reports processed";
        return $processedCount;
    }

    /**
     * Handles sending the generated report via email using PHPMailer.
     * @param Report $report The report data object.
     * @param array $files An array containing paths and content from the renderer.
     */
    private function sendEmail(Report $report, array $files) {
        echo "\n       - emailing to : " . implode(', ', $report->emailRecipients);
        Log::info("Preparing to email report.", ['recipients' => $report->emailRecipients]);

        try {
            $mail = new PHPMailer(true);

            // Server settings from .env file
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'];
            $mail->SMTPAuth   = filter_var($_ENV['SMTP_AUTH'], FILTER_VALIDATE_BOOLEAN);
            $mail->Username   = $_ENV['SMTP_USERNAME'];
            $mail->Password   = $_ENV['SMTP_PASSWORD'];
            $mail->SMTPSecure = $_ENV['SMTP_SECURE'];
            $mail->Port       = $_ENV['SMTP_PORT'];

            // Recipients
            $mail->setFrom($_ENV['FROM_EMAIL'], $_ENV['FROM_NAME']);
            foreach($report->emailRecipients as $recipient) {
                $mail->addAddress($recipient);
            }
            foreach($report->emailCC as $cc) {
                $mail->addCC($cc);
            }

            // Attachments
            if (file_exists($files['pdf_path'])) {
                 $mail->addAttachment($files['pdf_path']);
            }
            // You could add logic here to attach CSV or HTML files as well if needed.

            // Embed images (logo and charts)
            foreach ($files['embedded_images'] as $cid => $path) {
                if (file_exists($path)) {
                    $mail->addEmbeddedImage($path, $cid);
                }
            }

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Report: ' . $files['docname'];
            $mail->Body    = $files['html_body'];
            $mail->AltBody = 'Please view this email in an HTML-compatible client.';

            $mail->send();
            echo " √ ";
            Log::info("Email sent successfully.", ['subject' => $mail->Subject]);

        } catch (Exception $e) {
            echo " [EMAIL FAILED] ";
            Log::error("Mailer Error: " . $mail->ErrorInfo, ['report_name' => $report->name]);
        }
    }

    /**
     * Updates the report_planning table for the next run.
     * @param stdClass $planningRow The original row from the database.
     */
    private function reschedule($planningRow) {
        if ($planningRow->reporting_frequency != 'once') {
            $nextRun = date('Y-m-d H:i:s', strtotime('+' . $planningRow->reporting_frequency));
            $this->db->update('report_planning', $planningRow->id, [
                'LastRunDateTime' => date('Y-m-d H:i:s'),
                'nextRunDateTime' => $nextRun
            ]);
            echo "\n       - rescheduled for " . $nextRun;
            Log::info("Report rescheduled.", ['report_id' => $planningRow->id, 'next_run' => $nextRun]);
        } else {
            $this->db->deleteById('report_planning', $planningRow->id);
            echo "\n       - report set to 'once', schedule deleted.";
            Log::info("Report schedule deleted (set to 'once').", ['report_id' => $planningRow->id]);
        }
    }
}
