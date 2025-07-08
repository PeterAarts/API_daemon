<?php
// FILE: scheduler/classes/ReportOrchestrator.php
// PURPOSE: Builds a single, complete report by fetching data for each of its
// queries and passing the results to the ReportRenderer.

namespace ApiDaemon\Scheduler;

use ApiDaemon\DB;
use ApiDaemon\Log;
use Exception;

class ReportOrchestrator {
    private $db;

    /**
     * The constructor initializes the database connection.
     */
    public function __construct() {
        $this->db = DB::getInstance();
    }

    /**
     * Builds a complete report from a Report data object.
     * This is the main public method for this class.
     *
     * @param Report $report The data object containing all context for the report.
     * @return array An array containing the paths and content of the generated files.
     */
    public function build(Report $report) {
        Log::info("Orchestrator: Building report.", ['report_name' => $report->name]);
        
        // Initialize the renderer which will handle all PDF/HTML generation.
        $renderer = new ReportRenderer($report);

        // Fetch all active queries associated with this report type, in order.
        $queries = $this->db->query(
            "SELECT * FROM reporting_queries WHERE report_id = ? AND active = 1 ORDER BY sequence ASC", 
            [$report->report_id]
        )->results();

        if (empty($queries)) {
            Log::warning("No active queries found for this report.", ['report_id' => $report->report_id]);
            // Return empty files if there's nothing to render.
            return $renderer->getGeneratedFiles();
        }

        // Process each query sequentially.
        foreach ($queries as $query) {
            try {
                // Replace placeholders like _customer_ with actual values.
                $validatedQuery = $this->validateQuery($query->query, $report);
                
                // Execute the query to get the data.
                $results = $this->db->query($validatedQuery)->results(true); // true for associative array

                // Determine how to display this query's results (table, chart, etc.).
                $displayType = $query->display_type ?? 'TABLE';
                $chartOptions = json_decode($query->chart_options ?? '{}');

                // Pass the data and display instructions to the renderer.
                if (!empty($results)) {
                    $renderer->addContent(
                        $query->queryName,
                        $query->queryDescription,
                        $results,
                        $displayType,
                        $chartOptions
                    );
                } else {
                    Log::info("Query returned no results, skipping content block.", ['query_name' => $query->queryName]);
                }
            } catch (Exception $e) {
                Log::error("Failed to process a query for the report.", [
                    'report_name' => $report->name,
                    'query_name' => $query->queryName,
                    'error' => $e->getMessage()
                ]);
                // Continue to the next query even if one fails.
                continue;
            }
        }

        // Once all queries are processed, get the final files from the renderer.
        return $renderer->getGeneratedFiles();
    }

    /**
     * Replaces placeholders in the SQL query with dynamic values from the report object.
     *
     * @param string $sql The raw SQL query with placeholders.
     * @param Report $report The report data object.
     * @return string The prepared SQL query ready for execution.
     */
    private function validateQuery(string $sql, Report $report): string {
        $placeholders = [
            "_customer_",
            "_StartDate_",
            "_EndDate_",
            "_reporting_period_"
        ];

        $values = [
            $report->report_cust_id,
            $report->startDate,
            $report->endDate,
            $report->reporting_period
        ];

        return str_replace($placeholders, $values, $sql);
    }
}
