<?php
// FILE: scheduler/classes/ReportConfig.php
// PURPOSE: Centralizes all configuration for the reporting module.
// This class replaces the need for the old procedural config.php file by using
// modern class constants. This makes the configuration clear, easy to find,
// and prevents global scope pollution from 'define()' statements.

namespace ApiDaemon\Scheduler;

class ReportConfig {
    /**
     * The default author for all generated PDF documents.
     * @var string
     */
    const PDF_AUTHOR = 'rFMS-Connect - Report';

    /**
     * The default attachment type if not specified in the report planning.
     * Can be 'PDF', 'CSV', 'HTML', or 'ALL'.
     * @var string
     */
    const DEFAULT_ATTACHMENT_TYPE = 'ALL';

    // You can add other static configuration properties here as needed.
    // For example, if you have standard email settings that don't change
    // per customer, they could be defined here.
    // const DEFAULT_FROM_EMAIL = 'noreply@example.com';
    // const DEFAULT_FROM_NAME = 'Automated Reporting System';
}
?>
