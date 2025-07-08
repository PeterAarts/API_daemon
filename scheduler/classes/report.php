<?php
// FILE: scheduler/classes/Report.php
// PURPOSE: A data object (DTO) to hold all information for a single report run.
// This makes it easy to pass report context between different parts of the system.

namespace ApiDaemon\Scheduler;

use stdClass;

class Report {
    public int $id;
    public int $report_id;
    public string $name;
    public string $description;
    public int $cust_id;
    public int $report_cust_id;
    public string $customerName;
    public string $orientation;
    public string $attachmentType;
    public string $reporting_period;
    public array $emailRecipients = [];
    public array $emailCC = [];
    public string $startDate;
    public string $endDate;

    /**
     * The constructor populates the Report object from a database row.
     * @param stdClass $dbRow The row object from the report_planning query.
     */
    public function __construct(stdClass $dbRow) {
        $this->id = (int) ($dbRow->id ?? 0);
        $this->report_id = (int) ($dbRow->report_type ?? 0);
        $this->name = $dbRow->reportname ?? 'Untitled Report';
        $this->description = $dbRow->description ?? '';
        $this->cust_id = (int) ($dbRow->cust_id ?? 0);
        $this->report_cust_id = (int) ($dbRow->report_cust_id ?? 0);
        $this->customerName = $dbRow->CustomerName ?? 'N/A';
        $this->orientation = $dbRow->orientation ?? 'P';
        $this->attachmentType = $dbRow->attachmentType ?? 'PDF';
        $this->reporting_period = $dbRow->reporting_period ?? '1 day';

        if (!empty($dbRow->email)) {
            $this->emailRecipients[] = $dbRow->email;
        }

        if (!empty($dbRow->extra_email)) {
            // Split by semicolon and trim whitespace from each email address
            $this->emailCC = array_map('trim', explode(';', $dbRow->extra_email));
        }

        // Calculate date range based on the reporting period
        $this->startDate = date('Y-m-d', strtotime('-' . $this->reporting_period));
        $this->endDate = date('Y-m-d');
    }
}
?>