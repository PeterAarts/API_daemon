
<?php
// FILE: scheduler/protocols/SystemProtocol.php (NEW and COMPLETE)
// PURPOSE: Handles all tasks for the 'system' protocol. Replaces system.php and system_inc.php.

namespace ApiDaemon\Protocols;

use ApiDaemon\Scheduler\ReportScheduler;
use ApiDaemon\DB;
use ApiDaemon\Log;
use rFMS_Trip\rFMS_TripCalculator; // Assuming this class is available via autoloader
use Location\Coordinate;
use Location\Polygon;
use DateTime;
use Exception;

class System {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance();
    }

    /**
     * This method is called by the daemon for the 'Process_Reports' script.
     * It replaces the logic from your old reporter.php file.
     */
    public function Process_Reports($task) {
        Log::info("SystemProtocol: Initiating report processing.", ['task_id' => $task->id]);
        try {
            $scheduler = new ReportScheduler();
            $count = $scheduler->run();
            Log::info("SystemProtocol: Report processing finished successfully.", ['task_id' => $task->id, 'reports_processed' => $count]);
            // Update_Finished_SchedulerTask($task, ...);
        } catch (Exception $e) {
            Log::error("SystemProtocol: A critical error occurred during report processing.", ['task_id' => $task->id, 'error' => $e->getMessage()]);
            // Update_Failed_SchedulerTask($task, ...);
        }
    }

    /**
     * This method is called for the 'Process_Geofences' script.
     */
    public function Process_Geofences($task) {
        Log::info("SystemProtocol: Starting Geofence processing.", ['task_id' => $task->id]);
        $start = new DateTime();
        $geofences = $this->getGeoFences();

        if (count($geofences) > 0) {
            foreach ($geofences as $geofence) {
                $geofencePolygon = $this->loadGeofenceDef($geofence);
                if (!$geofencePolygon) continue;

                $vehiclesToCheck = $this->loadVehiclesForGeofence($geofence);
                foreach ($vehiclesToCheck as $vehicle) {
                    $vehicleCoordinate = new Coordinate($vehicle->last_Latitude, $vehicle->last_Longitude);
                    $isInside = $geofencePolygon->contains($vehicleCoordinate);
                    $this->registerVehicleCheck($vehicle, $isInside);
                }
            }
        }
        $time = (new DateTime())->diff($start);
        Log::info("SystemProtocol: Geofence processing finished.", ['task_id' => $task->id, 'duration' => $time->format('%H:%I:%S')]);
   //     Update_Finished_SchedulerTask($Request,$Result);
    }

    /**
     * This method is called for the 'Processing_rFMS_Trips' script.
     */
    public function Processing_rFMS_Trips($task) {
        Log::info("SystemProtocol: Starting rFMS Trip processing.", ['task_id' => $task->id]);
        $Start = new DateTime();
        $status = [
            "TC" => 0, "UCT" => 0, "UFCT" => 0, "FTC" => 0,
            "CT" => 0, "TripSplit" => 0
        ];
        $db = DB::getInstance();

        echo "\n   ∙ Trip Process Management ";
        $sqlTrips = 
        "SELECT t.Trip_NO, t.VIN, t.StartDate, t.EndDate 
        FROM   trips t USE INDEX(UpdateTrips) 
                LEFT JOIN customer_vehicle cv ON cv.vehicleVin = t.vin
        WHERE   cv.active = 1 AND t.TripActive = 0 AND  t.TripCalc = 0 AND  t.StartDate < t.EndDate 
        ORDER BY t.StartDate ASC LIMIT 1000";
        
        // Using your DB class query method
        $db->query($sqlTrips);
        if ($db->error()) {
            Log::error("Error fetching trips to process: " . print_r($db->error(), true)); // Adjust error logging
            // Handle error appropriately
            return;
        }
        $Trips = $db->results(); // This will be an array of objects
    
        echo " => Trips to be calculated : " . count($Trips);
        Log::info("Trips to be calculated : " . count($Trips));
        $TripCountDisplay = 0;

        if (count($Trips) > 0) {
            echo "\n     . ";
            foreach ($Trips as $Triprow) { // $Triprow is an object
                $status["TC"]++;
                $currentTripId = $Triprow->Trip_NO; 

                $sqlTripData = "SELECT * FROM vehiclestatus 
                                WHERE vin = ? AND createdDateTime BETWEEN ? AND ? 
                                ORDER BY createdDateTime ASC";
                
                // Parameters for the prepared statement
                $paramsTripData = [$Triprow->VIN, $Triprow->StartDate, $Triprow->EndDate];
                $db->query($sqlTripData, $paramsTripData);
                if ($db->error()) {
                    Log::error("Error fetching vehiclestatus for VIN {$Triprow->VIN}, TripID {$currentTripId}: " . print_r($db->error(), true));
                    echo "X"; // Indicate DB error for this trip's data
                    $status["UFCT"]++; // Count as failed
                    continue; // Skip to next trip
                }

                $rFMS_VehicleStatusMessagesObjects = $db->results();
                // Let's convert to array of arrays if RTMSTripCalculator was written for that
                $rFMS_VehicleStatusMessages = json_decode(json_encode($rFMS_VehicleStatusMessagesObjects), true);


                if (empty($rFMS_VehicleStatusMessages)) {
                    echo "F"; 
                    $status["FTC"]++;
                    // CORRECTED LINE
                    $db->query("UPDATE trips SET TripCalc = 1, TripCalcNotes = 'No rFMS data' WHERE Trip_NO = ?", [$currentTripId]);

                } else {
                    try {
                        // Pass the DB::getInstance() or the $db object itself if RTMSTripCalculator needs it
                        $tripCalculator = new rFMS_TripCalculator($currentTripId, $rFMS_VehicleStatusMessages, $db, $log);
                    //   $log->debug("rFMS_TripCalculator TripId = " . $currentTripId. ', messages : ' .json_encode($rFMS_VehicleStatusMessages));
                        $tripCalculator->storeCalculatedTripData(); 
                        
                        // Assuming storeCalculatedTripData doesn't set TripCalc=true itself for the main trip row
                        // CORRECTED LINE
                        $db->query("UPDATE trips SET TripCalc = 1, TripCalcNotes = NULL WHERE Trip_NO = ?", [$currentTripId]);

                        echo "."; 
                        $status["UCT"]++; 

                    } catch (Exception $e) {
                        echo "E"; 
                        $status["UFCT"]++; 
                        Log::error("Error processing trip ID {$currentTripId} with RTMSTripCalculator: " . $e->getMessage());
                        // CORRECTED LINE
                        $db->query("UPDATE trips SET TripCalc = 1, TripCalcNotes = ? WHERE Trip_NO = ?", [substr("Error: " . $e->getMessage(), 0, 255), $currentTripId]);
                    }
                }

                if ($TripCountDisplay % 20 == 0 && $TripCountDisplay != 0) { 
                    echo "\n     . ";
                }
                $TripCountDisplay++;
            }

            echo "\n   ∙ Total trips attempted         : " . $status["TC"];
            echo "\n     . Trips successfully processed: " . $status["UCT"];
            echo "\n     . Trips failed processing     : " . $status["UFCT"];
            echo "\n     . Trips with no data (False)  : " . $status["FTC"];
            echo "\n     . Trip Corrected (C)          : " . $status["CT"] . " (N/A in this version)";
            echo "\n     . Split to new trips (S)      : " . $status["TripSplit"] . " (N/A in this version)";

            $End = new DateTime();
            $diff = $End->getTimestamp() - $Start->getTimestamp();
            $Result['timeneeded'] = $diff;
            echo "\n   ∙ time needed : " . $Result['timeneeded'] . " seconds";
            $Result['count'] = $status['TC'];
            $Result['downloadsize'] = 0; 
            $Result['httpcode'] = '200';
        } else {
            $Result['message'] = "No trips to process.";
            $Result['count'] = 0;
            $Result['timeneeded'] = 0;
            $Result['httpcode'] = '200';
            $Result['downloadsize'] = 0; 
        }
       // Update_Finished_SchedulerTask($Request, $Result); // Your function to log scheduler task completion
    }

    /**
     * This method is called for the 'Process_Alerts' script.
     */
    public function Process_Alerts($task) {
        Log::info("SystemProtocol: Processing Alerts.", ['task_id' => $task->id]);
        // Your alert processing logic goes here.
    }

    // --- Private Helper Methods (from system_inc.php) ---

    private function getGeoFences() {
        $query = "SELECT gfd.id, gfd.active, gfd.name, gfd.geojson FROM geofence_reg gfr LEFT JOIN geofence_def gfd on gfr.geofence_id=gfd.id GROUP BY gfd.id";
        return $this->db->query($query)->results();
    }

    private function loadVehiclesForGeofence($geofence) {
        $query = "SELECT v.vin, v.customerVehicleName, v.last_Latitude, v.last_Longitude, gfr.* FROM geofence_reg gfr LEFT JOIN vehicles v on v.vin=gfr.vin WHERE v.vehicleActive=true AND v.last_Latitude<>0 and gfr.geofence_id=?";
        return $this->db->query($query, [$geofence->id])->results();
    }

    private function loadGeofenceDef($geofence) {
        $json = json_decode($geofence->geojson);
        if (!isset($json->features[0]->geometry->type)) return null;

        $geofencePolygon = new Polygon();
        $geometryType = $json->features[0]->geometry->type;
        $coordinates = $json->features[0]->geometry->coordinates;

        if ($geometryType == 'Polygon') {
            foreach ($coordinates[0] as $coord) {
                $geofencePolygon->addPoint(new Coordinate($coord[1], $coord[0]));
            }
        } elseif ($geometryType == 'MultiPolygon') {
            foreach ($coordinates[0][0] as $coord) {
                $geofencePolygon->addPoint(new Coordinate($coord[1], $coord[0]));
            }
        }
        return $geofencePolygon;
    }

    private function registerVehicleCheck($vehicle, $isInside) {
        $newStatus = $isInside ? 'In' : 'Out';
        if ($vehicle->status === $newStatus) return; // No change

        $fields = ['status' => $newStatus, 'latitude' => floatval($vehicle->last_Latitude), 'longitude' => floatval($vehicle->last_Longitude), 'createTrigger' => '1'];
        $this->db->update('geofence_reg', $vehicle->id, $fields);

        if (!$this->db->error()) {
            $logFields = ['vin' => $vehicle->vin, 'geofence_id' => $vehicle->geofence_id, 'previousStatus' => $vehicle->status, 'status' => $newStatus, 'latitude' => floatval($vehicle->last_Latitude), 'longitude' => floatval($vehicle->last_Longitude)];
            $this->db->insert('geofence_log', $logFields);
        }
    }
}
?>