<?php
// FILE: scheduler/protocols/Rfms3.php
// PURPOSE: Handles all tasks for the 'rfms3' protocol. Replaces rfms3.php and rfms3_to_mysql.php.

namespace ApiDaemon\Protocols;

use ApiDaemon\ApiClient;
use ApiDaemon\DB;
use ApiDaemon\Log;
use DateTime;
use DateInterval;
use Exception;
use stdClass;

class Rfms3 {
    private $db;
    private $apiClient;

    /**
     * The ApiClient is injected, decoupling this class from authentication.
     * @param ApiClient $apiClient A pre-configured client for making API calls.
     */
    public function __construct(ApiClient $apiClient) {
        $this->db = DB::getInstance();
        $this->apiClient = $apiClient;
    }

    //================================================================================
    //== PUBLIC METHODS (Called by the Daemon)
    //================================================================================

    /**
     * Fetches the complete vehicle list, handling pagination.
     * @param stdClass $task The task object from the scheduler.
     */
    public function rFMS3_VehicleList(stdClass $task): void {
        Log::info("Rfms3: Starting VehicleList task.", ['task_id' => $task->id]);
        $moreData = true;
        $lastVin = null;
        $totalVehicles = 0;

        while ($moreData) {
            $endpoint = $task->url_address . ($lastVin ? "?lastVin={$lastVin}" : '');

            try {
                $result = $this->apiClient->get($endpoint);

                if ($result['httpcode'] !== 200) {
                    throw new Exception("API access failed with httpcode: " . $result['httpcode']);
                }

                $data = json_decode($result['data'], true);
                if (!isset($data['vehicleResponse']['vehicles'])) {
                    throw new Exception("API returned invalid data for VehicleList.");
                }

                $vehicles = $data['vehicleResponse']['vehicles'];
                $vehicleCount = count($vehicles);
                $totalVehicles += $vehicleCount;
                $moreData = $data['moreDataAvailable'] ?? false;

                if ($vehicleCount > 0) {
                    $this->_updateVehiclesInDb($vehicles);
                    $lastVin = $vehicles[$vehicleCount - 1]['vin'];
                    Log::info("Processed a page of vehicles.", ['count' => $vehicleCount, 'more_data' => $moreData]);
                } else {
                    $moreData = false; // No vehicles in response, stop.
                }

            } catch (Exception $e) {
                Log::error("Error during VehicleList fetch.", ['task_id' => $task->id, 'error' => $e->getMessage()]);
                return; // Exit the method on failure
            }
        }

        Log::info("Rfms3: VehicleList task finished successfully.", ['task_id' => $task->id, 'total_vehicles' => $totalVehicles]);
    }

    /**
     * Fetches the latest status for all vehicles.
     * @param stdClass $task The task object from the scheduler.
     */
    public function rFMS3_VehicleLatest(stdClass $task): void {
        Log::info("Rfms3: Starting VehicleLatest task.", ['task_id' => $task->id]);

        try {
            $result = $this->apiClient->get($task->url_address);
            if ($result['httpcode'] !== 200) {
                throw new Exception("API access failed with httpcode: " . $result['httpcode']);
            }
            
            $data = json_decode($result['data'], true);
            $statuses = $data['vehicleStatusResponse']['vehicleStatuses'] ?? [];
            $this->_writeVehicleStatuses($statuses);

            Log::info("VehicleLatest task finished.", ['task_id' => $task->id, 'count' => count($statuses)]);

        } catch (Exception $e) {
            Log::error("Error during VehicleLatest fetch.", ['task_id' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Fetches historical vehicle status, handling date ranges and pagination.
     * @param stdClass $task The task object from the scheduler.
     */
    public function rFMS3_VehicleStatus(stdClass $task): void {
        Log::info("Rfms3: Starting VehicleStatus history task.", ['task_id' => $task->id]);

        $targetDT = new DateTime('1 minutes ago');
        $ninetyDaysAgo = new DateTime('-90 days');
        $lastRun = new DateTime($task->lastUpdateDateTime ?? 'now -90 days');
        
        $startDT = ($lastRun > $ninetyDaysAgo) ? $lastRun : $ninetyDaysAgo;
        $endDT = clone $startDT;
        $endDT->add(new DateInterval('P1D'))->setTime(0, 0, 0);
        
        $loopCounter = 0;
        $maxLoops = 200;

        while ($startDT < $targetDT && $loopCounter < $maxLoops) {
            $isTodayOrFuture = ($endDT >= $targetDT);
            if ($isTodayOrFuture) {
                $endDT = clone $targetDT;
            }

            $moreDataInDay = true;
            while ($moreDataInDay && $loopCounter < $maxLoops) {
                $loopCounter++;
                $requestUrl = $task->url_address . "?starttime=" . $startDT->format('Y-m-d\TH:i:s.v\Z') . "&stoptime=" . $endDT->format('Y-m-d\TH:i:s.v\Z');
                
                try {
                    Log::info("Fetching status history page.", ['start' => $startDT->format(DateTime::ATOM), 'end' => $endDT->format(DateTime::ATOM)]);
                    $result = $this->apiClient->get($requestUrl);

                    if ($result['httpcode'] !== 200) {
                        throw new Exception("API access failed with httpcode: " . $result['httpcode']);
                    }

                    $data = json_decode($result['data'], true);
                    $statuses = $data['vehicleStatusResponse']['vehicleStatuses'] ?? [];
                    $statusCount = count($statuses);
                    $moreDataInDay = $data['moreDataAvailable'] ?? false;

                    if ($statusCount > 0) {
                        $this->_writeVehicleStatuses($statuses);
                        $lastRecordTime = $statuses[$statusCount - 1]['receivedDateTime'];
                        $startDT = new DateTime($lastRecordTime);
                        $startDT->modify('+1 millisecond');
                        Log::error("Processed page of history.", ['count' => $statusCount, 'more_data' => $moreDataInDay, 'next_start' => $startDT->format(DateTime::ATOM)]);
                    } else {
                        $moreDataInDay = false;
                    }

                    $this->db->update('api_scheduler', $task->id, [
                        'lastUpdateDateTime' => $startDT->format('Y-m-d H:i:s.u'),
                        'lastStatus' => $result['httpcode'],
                        'lastExecution' => date('Y-m-d H:i:s')
                    ]);

                } catch (Exception $e) {
                    Log::error("Error fetching status history page.", ['task_id' => $task->id, 'error' => $e->getMessage()]);
                    return;
                }
            }
            if (!$isTodayOrFuture) {
                $startDT = clone $endDT;
                $endDT->add(new DateInterval('P1D'));
            } else {
                break;
            }
        }

        Log::info("Rfms3: VehicleStatus history task finished.", ['task_id' => $task->id, 'last_processed_date' => $startDT->format('Y-m-d')]);
    }


    //================================================================================
    //== PRIVATE HELPER METHODS
    //================================================================================

    private function _updateVehiclesInDb(array $vehicles): void {
        foreach ($vehicles as $vehicleData) {
            $dataForDb = [];
            foreach ($vehicleData as $key => $val) {
                $dataForDb[$key] = is_array($val) ? json_encode($val) : $val;
            }
            if (empty($dataForDb) || !isset($dataForDb['vin'])) continue;

            $this->db->insert('vehicles', $dataForDb, true);
        }
        Log::error("Upserted vehicle records.", ['count' => count($vehicles)]);
    }

    private function _writeVehicleStatuses(array $allEvents): void {
        if (empty($allEvents)) return;

        $latestVehicleData = [];
        foreach ($allEvents as $event) {
            $this->_insertVehicleStatus($event);
            $this->_updateTripState($event); // <-- CORRECTED: Trip logic is now called here

            $vin = $event['vin'] ?? null;
            if ($vin) {
                $latestVehicleData[$vin] = array_merge($latestVehicleData[$vin] ?? [], $event);
            }
        }

        if (!empty($latestVehicleData)) {
            $this->_updateVehiclesInDb($latestVehicleData);
        }
    }

    private function _insertVehicleStatus(array $event): void {
        $data = [];
        foreach ($event as $key => $val) {
            if ($key === "driver1Id" && isset($val['tachoDriverIdentification']['driverIdentification'])) {
                $data['driver1Id'] = substr($val['tachoDriverIdentification']['driverIdentification'], 3, 14);
            } elseif ($key === "createdDateTime" || $key === "receivedDateTime") {
                $data[$key] = str_replace(['T', 'Z'], ' ', $val);
            } elseif (is_array($val)) {
                if ($key === "gnssPosition") {
                    $data['GNSS_latitude']    = $val['latitude'] ?? null;
                    $data['GNSS_longitude']   = $val['longitude'] ?? null;
                    $data['GNSS_altitude']    = $val['altitude'] ?? null;
                    $data['GNSS_heading']     = $val['heading'] ?? null;
                    $data['GNSS_Speed']       = $val['speed'] ?? null;
                    $data['GNSS_PosDateTime'] = isset($val['positionDateTime']) ? str_replace(['T', 'Z'], ' ', $val['positionDateTime']) : null;
                } elseif ($key === "tellTaleInfo" && isset($val['tellTale'])) {
                    $data['triggerInfo']    = ($val['tellTale'] ?? 'N/A') . '->' . ($val['state'] ?? 'N/A');
                    $data['tellTale']       = $val['tellTale'] ?? null;
                    $data['tellTale_State'] = $val['state'] ?? null;
                } else {
                    $data[$key] = json_encode($val);
                }
            } else {
                $data[$key] = $val;
            }
        }

        if (!empty($data)) {
            $this->db->insert('vehiclestatus', $data);
        }
    }

    /**
     * Creates or updates trips based on ENGINE_ON and ENGINE_OFF events.
     * This logic is migrated from the original UpdateVehicleTriprFMS3 function.
     * @param array $event A single vehicle status event.
     */
    private function _updateTripState(array $event): void {
        $trigger = $event['triggerType']['triggerType'] ?? null;
        if ($trigger !== 'ENGINE_ON' && $trigger !== 'ENGINE_OFF') {
            return; // Only act on engine events
        }

        $vin = $event['vin'];
        $eventTime = str_replace(['T', 'Z'], ' ', $event['createdDateTime']);
        $latitude = $event['snapshotData']['gnssPosition']['latitude'] ?? null;
        $longitude = $event['snapshotData']['gnssPosition']['longitude'] ?? null;
        $odometer = $event['hrTotalVehicleDistance'] ?? null;
        $totalFuel = $event['engineTotalFuelUsed'] ?? null;
        $fuelLevel = $event['snapshotData']['fuelLevel1'] ?? null;
        $driverId = isset($event['driver1Id']['tachoDriverIdentification']['driverIdentification'])
            ? substr($event['driver1Id']['tachoDriverIdentification']['driverIdentification'], 3, 14)
            : '';

        if ($trigger === 'ENGINE_ON') {
            // 1. Close any previously open trips for this VIN
            $this->db->query(
                "UPDATE trips SET TripActive = 0, TripCorrected = 1, EndDate = ? WHERE VIN = ? AND TripActive = 1 AND StartDate < ?",
                [$eventTime, $vin, $eventTime]
            );

            // 2. Update the main vehicle record
            $this->db->update('vehicles', ['vin' => $vin], ['TripActive' => 1]);


            // 3. Insert the new trip
            $this->db->insert('trips', [
                'VIN' => $vin,
                'StartDate' => $eventTime,
                'TripActive' => 1,
                'start_latitude' => $latitude,
                'start_longitude' => $longitude,
                'start_odometer' => $odometer,
                'start_fuelused' => $totalFuel,
                'start_fuellevel' => $fuelLevel,
                'Driver1ID' => $driverId,
                'TripDelayed' => $this->_isDelayed($event['createdDateTime'], $event['receivedDateTime'])
            ]);
            Log::info("Started new trip for VIN.", ['vin' => $vin]);

        } elseif ($trigger === 'ENGINE_OFF') {
            // 1. Update the active trip to close it
            $this->db->query(
                "UPDATE trips SET 
                    TripActive = 0, 
                    EndDate = ?, 
                    end_latitude = ?, 
                    end_longitude = ?, 
                    end_odometer = ?, 
                    end_fuelused = ?,
                    end_fuellevel = ?,
                    Driver1ID = ?
                WHERE VIN = ? AND TripActive = 1 AND StartDate < ?",
                [$eventTime, $latitude, $longitude, $odometer, $totalFuel, $fuelLevel, $driverId, $vin, $eventTime]
            );

            // 2. Update the main vehicle record
            $this->db->update('vehicles', ['vin' => $vin], ['TripActive' => 0]);
            Log::info("Closed trip for VIN.", ['vin' => $vin]);
        }
    }

    /**
     * Helper to check if a message was significantly delayed.
     * @param string $createdDateTime
     * @param string $receivedDateTime
     * @return bool
     */
    private function _isDelayed(string $createdDateTime, string $receivedDateTime): bool {
        try {
            $created = new DateTime($createdDateTime);
            $received = new DateTime($receivedDateTime);
            $diffSeconds = $received->getTimestamp() - $created->getTimestamp();
            return ($diffSeconds / 60) > 30; // True if delayed by more than 30 minutes
        } catch (Exception $e) {
            return false;
        }
    }
}
