<?php

namespace rFMS_Trip; // Matches your composer.json

// Ensure these are available via autoloader or direct include
use Monolog\Logger;
use DateTime;
use Exception; 


class rFMS_TripCalculator {
    private string $tripId;
    private ?string $tripEngineOnTime = null;
    private ?string $tripEngineOffTime = null;
    private array $vehicleStatusMessages; // Expects array of flat associative arrays
    private $db; // Your DB wrapper or PDO instance
    private Logger $logger;

    // Constants for driver states from rFMS
    const STATE_DRIVE = 'DRIVE';
    const STATE_WORK = 'WORK';
    const STATE_REST = 'REST';
    const STATE_AVAILABLE = 'DRIVER_AVAILABLE';
    const STATE_NOT_AVAILABLE = 'NOT_AVAILABLE';
    const STATE_ERROR = 'ERROR';
    const STATE_UNKNOWN = 'UNKNOWN'; // For unhandled states

    public function __construct(string $tripId, array $flatRFMS_VehicleStatusMessages, $db, Logger $logger) {
        $this->tripId = $tripId;
        $this->db = $db;
        $this->logger = $logger;

        if (empty($flatRFMS_VehicleStatusMessages)) {
            $this->vehicleStatusMessages = [];
            $this->logger->warning("RTMSTripCalculator initialized with no vehicle status messages.", ['trip_id' => $this->tripId]);
            return;
        }

        // Ensure messages are sorted by createdDateTime (critical!)
        usort($flatRFMS_VehicleStatusMessages, function ($a, $b) {
            return strtotime($a['createdDateTime'] ?? 'now') <=> strtotime($b['createdDateTime'] ?? 'now');
        });
        $this->vehicleStatusMessages = $flatRFMS_VehicleStatusMessages;

        $this->determineTripBoundaries();

        $this->logger->debug(
            'RTMSTripCalculator: Class constructed.',
            [
                'trip_id' => $this->tripId,
                'tripEngineOnTime' => $this->tripEngineOnTime,
                'tripEngineOffTime' => $this->tripEngineOffTime,
                'message_count' => count($this->vehicleStatusMessages)
            ]
        );
    }

    private function determineTripBoundaries() {
        if (empty($this->vehicleStatusMessages)) return;

        $this->tripEngineOnTime = $this->vehicleStatusMessages[0]['createdDateTime'] ?? null;
        $this->tripEngineOffTime = end($this->vehicleStatusMessages)['createdDateTime'] ?? null;

        foreach ($this->vehicleStatusMessages as $msg) {
            if (($msg['triggerType'] ?? null) === 'ENGINE_ON' && isset($msg['createdDateTime'])) {
                $this->tripEngineOnTime = $msg['createdDateTime'];
                break; 
            }
        }
        $reversedMessages = array_reverse($this->vehicleStatusMessages);
        foreach ($reversedMessages as $msg) {
            if (($msg['triggerType'] ?? null) === 'ENGINE_OFF' && isset($msg['createdDateTime'])) {
                $this->tripEngineOffTime = $msg['createdDateTime'];
                break; 
            }
        }
    }

    // Safely gets a value from a message (flat associative array)
    private function getMessageValue(array $message, string $key, $default = null) {
        return $message[$key] ?? $default;
    }

    public function calculateTotalDistanceKm(): float {
        if (empty($this->vehicleStatusMessages)) return 0.0;

        $startOdometerMeters = null;
        $endOdometerMeters = null;

        foreach ($this->vehicleStatusMessages as $msg) {
            if (($msg['triggerType'] ?? null) === 'ENGINE_ON' && isset($msg['hrTotalVehicleDistance'])) {
                $startOdometerMeters = $msg['hrTotalVehicleDistance'];
                 // Assuming the first ENGINE_ON's odometer is the start for this trip's calculation
            }
        }
        // If no ENGINE_ON odo, use first message odo
        if($startOdometerMeters === null) {
            $startOdometerMeters = $this->getMessageValue($this->vehicleStatusMessages[0], 'hrTotalVehicleDistance');
        }

        // End odometer is the last known odometer for the trip
        $endOdometerMeters = $this->getMessageValue(end($this->vehicleStatusMessages), 'hrTotalVehicleDistance');


        if ($startOdometerMeters !== null && $endOdometerMeters !== null && is_numeric($startOdometerMeters) && is_numeric($endOdometerMeters) && (float)$endOdometerMeters >= (float)$startOdometerMeters) {
            return ((float)$endOdometerMeters - (float)$startOdometerMeters) / 1000.0;
        }
        $this->logger->warning("Could not determine valid start/end odometer for distance.", ['trip_id' => $this->tripId, 'start_odo' => $startOdometerMeters, 'end_odo' => $endOdometerMeters]);
        return 0.0;
    }
    
    public function calculateTotalFuelUsedLiters(): float {
        $totalFuelFromAccumulatedMl = 0;
        $foundAccumulatedFuelData = false;

        // --- Primary Method: Summing interval-based accumulated fuel ---
        // (This part of the logic remains the same, summing fields like
        // 'fuelWheelbasedSpeedZero' and 'fuelWheelbasedSpeedOverZero'
        // from TIMER, ENGINE_ON, ENGINE_OFF messages if they represent interval fuel)
        foreach ($this->vehicleStatusMessages as $message) {
            if (in_array($this->getMessageValue($message, 'triggerType'), ['ENGINE_ON', 'TIMER', 'ENGINE_OFF'])) {
                
                $fuelZero = $this->getMessageValue($message, 'fuelWheelbasedSpeedZero');
                $fuelOverZero = $this->getMessageValue($message, 'fuelWheelbasedSpeedOverZero');
                
                if ($fuelZero !== null || $fuelOverZero !== null) $foundAccumulatedFuelData = true;

                if (is_numeric($fuelZero)) $totalFuelFromAccumulatedMl += (float)$fuelZero;
                if (is_numeric($fuelOverZero)) $totalFuelFromAccumulatedMl += (float)$fuelOverZero;
                
                // ... (PTO fuel summation if applicable) ...
            }
        }

        if ($foundAccumulatedFuelData && $totalFuelFromAccumulatedMl > 0) {
            $this->logger->debug("Calculated total fuel from accumulated interval data.", ['trip_id' => $this->tripId, 'fuel_ml' => $totalFuelFromAccumulatedMl]);
            return $totalFuelFromAccumulatedMl / 1000.0;
        }
        $this->logger->info("Accumulated interval fuel data (fuelWheelbasedSpeedZero/OverZero) not found or sum is zero. Attempting fallback to lifetime counters.", ['trip_id' => $this->tripId]);

        // --- Fallback Method: Delta of lifetime 'engineTotalFuelUsed' ---
        if (empty($this->vehicleStatusMessages)) return 0.0;

        $startFuelLifetimeMl = null;
        $endFuelLifetimeMl = null;

        // Find the 'engineTotalFuelUsed' from the first relevant event (ideally ENGINE_ON)
        foreach ($this->vehicleStatusMessages as $message) {
            $trigger = $this->getMessageValue($message, 'triggerType');
            if (in_array($trigger, ['ENGINE_ON', 'TIMER'])) { // Consider first TIMER if ENGINE_ON missing or no fuel
                $fuelVal = $this->getMessageValue($message, 'engineTotalFuelUsed');
                if ($fuelVal !== null && is_numeric($fuelVal)) {
                    $startFuelLifetimeMl = (float)$fuelVal;
                    $this->logger->debug("Fallback Fuel: Found potential start lifetime fuel.", ['trip_id' => $this->tripId, 'trigger' => $trigger, 'value' => $startFuelLifetimeMl, 'datetime' => $this->getMessageValue($message, 'createdDateTime')]);
                    break; // Found the earliest relevant fuel reading for trip start
                }
            }
        }

        // Find the 'engineTotalFuelUsed' from the last relevant event (ideally ENGINE_OFF, or last TIMER)
        // Iterate backwards to find the last reliable reading
        for ($i = count($this->vehicleStatusMessages) - 1; $i >= 0; $i--) {
            $message = $this->vehicleStatusMessages[$i];
            $trigger = $this->getMessageValue($message, 'triggerType');
            if (in_array($trigger, ['ENGINE_OFF', 'TIMER'])) { // ENGINE_OFF preferred, then last TIMER
                $fuelVal = $this->getMessageValue($message, 'engineTotalFuelUsed');
                if ($fuelVal !== null && is_numeric($fuelVal)) {
                    $endFuelLifetimeMl = (float)$fuelVal;
                    $this->logger->debug("Fallback Fuel: Found potential end lifetime fuel.", ['trip_id' => $this->tripId, 'trigger' => $trigger, 'value' => $endFuelLifetimeMl, 'datetime' => $this->getMessageValue($message, 'createdDateTime')]);
                    break; // Found the latest relevant fuel reading for trip end
                }
            }
        }

        if ($startFuelLifetimeMl !== null && $endFuelLifetimeMl !== null) {
            if ($endFuelLifetimeMl >= $startFuelLifetimeMl) {
                $calculatedTripFuelMl = $endFuelLifetimeMl - $startFuelLifetimeMl;
                $this->logger->info("Calculated total fuel from lifetime counter delta.", [
                    'trip_id' => $this->tripId, 
                    'start_fuel_ml' => $startFuelLifetimeMl,
                    'end_fuel_ml' => $endFuelLifetimeMl,
                    'trip_fuel_ml' => $calculatedTripFuelMl
                ]);
                return $calculatedTripFuelMl / 1000.0; // Convert mL to Liters
            } else {
                // This can happen if the lifetime counter reset or there was an anomaly
                $this->logger->warning("Fallback Fuel: End lifetime fuel is less than start lifetime fuel. Cannot calculate delta.", [
                    'trip_id' => $this->tripId, 
                    'start_fuel_ml' => $startFuelLifetimeMl, 
                    'end_fuel_ml' => $endFuelLifetimeMl
                ]);
            }
        } else {
            $this->logger->warning("Fallback Fuel: Could not determine start and/or end lifetime fuel values.", [
                'trip_id' => $this->tripId, 
                'start_fuel_ml' => $startFuelLifetimeMl, 
                'end_fuel_ml' => $endFuelLifetimeMl
            ]);
        }

        $this->logger->error("Could not determine total fuel used for trip using any method.", ['trip_id' => $this->tripId]);
        return 0.0; // Default if no method yields a result
    }


    public function calculateAverageFuelConsumptionLitersPer100Km(): float {
        $fuelUsedLiters = $this->calculateTotalFuelUsedLiters();
        $distanceKm = $this->calculateTotalDistanceKm();
        if ($distanceKm > 0 && $fuelUsedLiters >= 0) {
            return ($fuelUsedLiters / $distanceKm) * 100.0;
        }
        return 0.0;
    }

    public function calculateHighestSpeedKmh(): float {
        $highestSpeed = 0.0;
        if (empty($this->vehicleStatusMessages)) return $highestSpeed;

        foreach ($this->vehicleStatusMessages as $message) {
            $currentMsgSpeedValue = null;
            $wheelSpeedStr = $this->getMessageValue($message, 'wheelBasedSpeed');
            if ($wheelSpeedStr !== null && is_numeric($wheelSpeedStr)) {
                $currentMsgSpeedValue = (float)$wheelSpeedStr;
            }

            if ($currentMsgSpeedValue === null) {
                $tachoSpeedStr = $this->getMessageValue($message, 'tachographSpeed');
                if ($tachoSpeedStr !== null && is_numeric($tachoSpeedStr)) {
                    $currentMsgSpeedValue = (float)$tachoSpeedStr;
                }
            }
            
            if ($currentMsgSpeedValue !== null && $currentMsgSpeedValue > $highestSpeed) {
                $highestSpeed = $currentMsgSpeedValue;
            }
        }
        $this->logger->info("Highest Speed Calculated.", ['trip_id' => $this->tripId, 'highest_speed_kmh' => $highestSpeed]);
        return $highestSpeed;
    }
    
    public function calculateAverageDrivingSpeedKmh(): float {
        $totalTimeMovingSeconds = 0;
        // Sum actual moving time from accumulated data
        foreach ($this->vehicleStatusMessages as $message) {
             if (in_array($this->getMessageValue($message, 'triggerType'), ['ENGINE_ON', 'TIMER', 'ENGINE_OFF'])) {
                $durationOverZero = $this->getMessageValue($message, 'durationWheelbasedSpeedOverZero');
                if ($durationOverZero !== null && is_numeric($durationOverZero)) {
                    $totalTimeMovingSeconds += (float)$durationOverZero;
                }
            }
        }
        
        $totalDistanceKm = $this->calculateTotalDistanceKm(); 

        if ($totalTimeMovingSeconds > 0 && $totalDistanceKm > 0) {
            $avgSpeedKmh = ($totalDistanceKm / ($totalTimeMovingSeconds / 3600.0));
            return $avgSpeedKmh;
        }
        $this->logger->warning("AverageDrivingSpeed: Not enough data for calculation.", ['trip_id' => $this->tripId, 'distance_km' => $totalDistanceKm, 'moving_time_s' => $totalTimeMovingSeconds]);
        return 0.0;
    }

    public function calculateGPSDistanceKm(): float {
        $totalDistanceKm = 0.0;
        $previousLat = null;
        $previousLon = null;
        if (empty($this->vehicleStatusMessages)) return 0.0;

        foreach ($this->vehicleStatusMessages as $message) {
            $currentLat = $this->getMessageValue($message, 'GNSS_latitude');
            $currentLon = $this->getMessageValue($message, 'GNSS_longitude');

            if ($currentLat !== null && $currentLon !== null && is_numeric($currentLat) && is_numeric($currentLon)) {
                $currentLatF = (float)$currentLat;
                $currentLonF = (float)$currentLon;
                if ($previousLat !== null && $previousLon !== null) {
                    if ($currentLatF != $previousLat || $currentLonF != $previousLon) {
                        $totalDistanceKm += $this->calculateHaversineDistance($previousLat, $previousLon, $currentLatF, $currentLonF);
                    }
                }
                $previousLat = $currentLatF;
                $previousLon = $currentLonF;
            }
        }
        return $totalDistanceKm;
    }

    private function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $a = sin($dLat / 2) * sin($dLat / 2) + sin($dLon / 2) * sin($dLon / 2) * cos($lat1Rad) * cos($lat2Rad);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }
    
    public function calculateCO2ConsumedKg(float $co2PerLiterFuel = 2.65): float {
        $totalFuelLiters = $this->calculateTotalFuelUsedLiters();
        return ($totalFuelLiters > 0) ? $totalFuelLiters * $co2PerLiterFuel : 0.0;
    }

    public function calculateExactDriverActivityTimes(): array {
        $times = array_fill_keys([
            self::STATE_DRIVE, self::STATE_WORK . '_RAW', self::STATE_WORK . '_FILTERED',
            self::STATE_REST, self::STATE_AVAILABLE, self::STATE_NOT_AVAILABLE,
            self::STATE_ERROR, self::STATE_UNKNOWN
        ], 0);

        if (empty($this->vehicleStatusMessages) || $this->tripEngineOnTime === null) {
            return $times;
        }

        $previousTimestamp = strtotime($this->tripEngineOnTime);
        $firstMsgState = $this->getMessageValue($this->vehicleStatusMessages[0], 'driver1WorkingState', self::STATE_UNKNOWN);
        // Handle empty string for driver1WorkingState as UNKNOWN or a specific default
        $previousState = ($firstMsgState === "" || $firstMsgState === null) ? self::STATE_UNKNOWN : $firstMsgState;
        
        $currentWorkSegmentStartTime = null;
        $totalFilteredWorkTimeSeconds = 0;

        foreach ($this->vehicleStatusMessages as $message) {
            $currentTimestamp = strtotime($this->getMessageValue($message, 'createdDateTime'));
            $rawCurrentState = $this->getMessageValue($message, 'driver1WorkingState', self::STATE_UNKNOWN);
            $currentState = ($rawCurrentState === "" || $rawCurrentState === null) ? self::STATE_UNKNOWN : $rawCurrentState;

            if ($currentTimestamp > $previousTimestamp) {
                $duration = $currentTimestamp - $previousTimestamp;
                $keyToIncrement = ($previousState === self::STATE_WORK) ? self::STATE_WORK . '_RAW' : $previousState;
                if (array_key_exists($keyToIncrement, $times)) {
                    $times[$keyToIncrement] += $duration;
                } else {
                    $times[self::STATE_UNKNOWN] += $duration;
                }
            }

            // WORK segment filtering
            if ($previousState === self::STATE_WORK) {
                if ($currentState !== self::STATE_WORK) { // Work segment ended
                    if ($currentWorkSegmentStartTime !== null) {
                        $workSegmentDuration = $currentTimestamp - $currentWorkSegmentStartTime;
                        if ($workSegmentDuration > 60) {
                            $totalFilteredWorkTimeSeconds += $workSegmentDuration;
                        }
                        $currentWorkSegmentStartTime = null;
                    }
                }
            }
            
            if ($currentState === self::STATE_WORK && ($previousState !== self::STATE_WORK || $currentWorkSegmentStartTime === null)) {
                $currentWorkSegmentStartTime = $currentTimestamp;
            }

            $previousTimestamp = $currentTimestamp;
            $previousState = $currentState;
        }

        // Account for the last segment until tripEngineOffTime
        if ($this->tripEngineOffTime !== null) {
            $tripEndTimestamp = strtotime($this->tripEngineOffTime);
            if ($previousState !== null && $previousTimestamp < $tripEndTimestamp) {
                $duration = $tripEndTimestamp - $previousTimestamp;
                $keyToIncrement = ($previousState === self::STATE_WORK) ? self::STATE_WORK . '_RAW' : $previousState;
                 if (array_key_exists($keyToIncrement, $times)) {
                    $times[$keyToIncrement] += $duration;
                } else {
                    $times[self::STATE_UNKNOWN] += $duration;
                }

                if ($previousState === self::STATE_WORK && $currentWorkSegmentStartTime !== null) {
                    $workSegmentDuration = $tripEndTimestamp - $currentWorkSegmentStartTime;
                    if ($workSegmentDuration > 60) {
                        $totalFilteredWorkTimeSeconds += $workSegmentDuration;
                    }
                }
            }
        }
        $times[self::STATE_WORK . '_FILTERED'] = $totalFilteredWorkTimeSeconds;
        return $times;
    }

    public function calculateCreditedActivityMinutes(): array {
        $creditedActivity = array_fill_keys([
            self::STATE_DRIVE . '_MINUTES', self::STATE_WORK . '_MINUTES_RAW',
            self::STATE_WORK . '_MINUTES_FILTERED', self::STATE_REST . '_MINUTES',
            self::STATE_AVAILABLE . '_MINUTES', self::STATE_NOT_AVAILABLE . '_MINUTES',
            self::STATE_ERROR . '_MINUTES', self::STATE_UNKNOWN . '_MINUTES'
        ], 0);

        if (empty($this->vehicleStatusMessages) || $this->tripEngineOnTime === null || $this->tripEngineOffTime === null) {
            return $creditedActivity;
        }

        $tripStartTimestamp = strtotime($this->tripEngineOnTime);
        $tripEndTimestamp = strtotime($this->tripEngineOffTime);
        if ($tripStartTimestamp >= $tripEndTimestamp) return $creditedActivity;

        $currentMinuteStartLoop = $this->floorToMinute($tripStartTimestamp);
        $eventSearchStartIndex = 0;
        $continuousWorkMinutesCount = 0;

        while ($currentMinuteStartLoop < $tripEndTimestamp) {
            $minuteActualEndTimestamp = min($currentMinuteStartLoop + 59, $tripEndTimestamp - 1);
            if ($minuteActualEndTimestamp < $currentMinuteStartLoop) break; // Avoid issues if trip is very short

            $secondsInStatesThisMinute = $this->processSingleMinuteDetails(
                $currentMinuteStartLoop,
                $minuteActualEndTimestamp,
                $eventSearchStartIndex
            );

            $driveSecs = $secondsInStatesThisMinute[self::STATE_DRIVE] ?? 0;
            $workSecs = $secondsInStatesThisMinute[self::STATE_WORK] ?? 0;
            $creditedStateForMinute = null;

            // Majority Rule for DRIVE vs WORK
            if ($driveSecs > 0 || $workSecs > 0) {
                if ($driveSecs > $workSecs) $creditedStateForMinute = self::STATE_DRIVE;
                elseif ($workSecs > $driveSecs) $creditedStateForMinute = self::STATE_WORK;
                elseif ($driveSecs === $workSecs && $driveSecs > 0) { // Tie-break
                    $stateAtEndOfMinute = $this->getStateAtTimestamp($minuteActualEndTimestamp, count($this->vehicleStatusMessages)-1);
                    if ($stateAtEndOfMinute === self::STATE_DRIVE || $stateAtEndOfMinute === self::STATE_WORK) {
                         $creditedStateForMinute = $stateAtEndOfMinute; // Prefer state at end of minute on tie
                    } else { // If ends in other state, default to DRIVE or a predefined rule
                        $creditedStateForMinute = self::STATE_DRIVE;
                    }
                }
            }

            if ($creditedStateForMinute) {
                $keyName = ($creditedStateForMinute === self::STATE_WORK) ? self::STATE_WORK . '_MINUTES_RAW' : $creditedStateForMinute . '_MINUTES';
                $creditedActivity[$keyName]++;
            } else { // Not DRIVE or WORK by majority. Check other fully occupied states.
                $maxOtherTime = 0; $primaryOtherState = null;
                foreach ([self::STATE_REST, self::STATE_AVAILABLE, self::STATE_NOT_AVAILABLE, self::STATE_ERROR] as $st) {
                    if (($secondsInStatesThisMinute[$st] ?? 0) > $maxOtherTime) {
                        $maxOtherTime = $secondsInStatesThisMinute[$st];
                        $primaryOtherState = $st;
                    }
                }
                if ($primaryOtherState && $maxOtherTime >= 58) {
                    $creditedActivity[$primaryOtherState . '_MINUTES']++;
                    $creditedStateForMinute = $primaryOtherState;
                } else if ($primaryOtherState && $driveSecs == 0 && $workSecs == 0 && $maxOtherTime > 0) {
                    $creditedActivity[$primaryOtherState . '_MINUTES']++;
                    $creditedStateForMinute = $primaryOtherState;
                } else {
                    $creditedActivity[self::STATE_UNKNOWN.'_MINUTES']++;
                }
            }

            if ($creditedStateForMinute === self::STATE_WORK) $continuousWorkMinutesCount++;
            else {
                if ($continuousWorkMinutesCount > 1) $creditedActivity[self::STATE_WORK . '_MINUTES_FILTERED'] += $continuousWorkMinutesCount;
                $continuousWorkMinutesCount = 0;
            }
            $currentMinuteStartLoop += 60;
        }
        if ($continuousWorkMinutesCount > 1) $creditedActivity[self::STATE_WORK . '_MINUTES_FILTERED'] += $continuousWorkMinutesCount;
        return $creditedActivity;
    }

    private function processSingleMinuteDetails(int $minuteStartTimestamp, int $minuteActualEndTimestamp, int &$eventSearchStartIndex): array {
        $secondsInStates = array_fill_keys([
            self::STATE_DRIVE, self::STATE_WORK, self::STATE_REST, self::STATE_AVAILABLE,
            self::STATE_NOT_AVAILABLE, self::STATE_ERROR, self::STATE_UNKNOWN
        ], 0);
        $pointerTime = $minuteStartTimestamp;
        $currentProcessingState = $this->getStateAtTimestamp($minuteStartTimestamp, $eventSearchStartIndex);

        for ($i = $eventSearchStartIndex; $i < count($this->vehicleStatusMessages); $i++) {
            $eventTimestamp = strtotime($this->getMessageValue($this->vehicleStatusMessages[$i], 'createdDateTime'));
            $rawEventState = $this->getMessageValue($this->vehicleStatusMessages[$i], 'driver1WorkingState', self::STATE_UNKNOWN);
            $eventState = ($rawEventState === "" || $rawEventState === null) ? self::STATE_UNKNOWN : $rawEventState;

            if ($eventTimestamp <= $pointerTime) { // Event defines or reconfirms state at pointerTime
                $currentProcessingState = $eventState;
                $eventSearchStartIndex = $i; // Next search can start from here
                continue;
            }
            if ($pointerTime > $minuteActualEndTimestamp) break;

            $segmentEndTime = min($eventTimestamp, $minuteActualEndTimestamp + 1);
            $duration = $segmentEndTime - $pointerTime;

            if ($duration > 0) {
                $secondsInStates[$currentProcessingState] += $duration;
            }
            
            $pointerTime = $segmentEndTime;
            $currentProcessingState = $eventState;
            $eventSearchStartIndex = $i;

            if ($pointerTime >= $minuteActualEndTimestamp + 1) break;
        }

        if ($pointerTime <= $minuteActualEndTimestamp) {
            $duration = ($minuteActualEndTimestamp + 1) - $pointerTime;
            if ($duration > 0) $secondsInStates[$currentProcessingState] += $duration;
        }
        return $secondsInStates;
    }
    
    private function getStateAtTimestamp(int $targetTimestamp, int $searchEndIndexHint): string {
        $activeState = self::STATE_UNKNOWN;
        // Look backwards from hint for efficiency, ensuring index is valid
        for ($j = min($searchEndIndexHint, count($this->vehicleStatusMessages) - 1); $j >= 0; $j--) {
            $eventTime = strtotime($this->getMessageValue($this->vehicleStatusMessages[$j], 'createdDateTime'));
            if ($eventTime <= $targetTimestamp) {
                $rawState = $this->getMessageValue($this->vehicleStatusMessages[$j], 'driver1WorkingState', self::STATE_UNKNOWN);
                $activeState = ($rawState === "" || $rawState === null) ? self::STATE_UNKNOWN : $rawState;
                return $activeState;
            }
        }
        // If not found by looking back (e.g., targetTimestamp is before first event in hint range, or hint is 0)
        // check the very first message of the trip if targetTimestamp is within trip bounds.
        if (!empty($this->vehicleStatusMessages) && $this->tripEngineOnTime && $targetTimestamp >= strtotime($this->tripEngineOnTime)) {
             $rawState = $this->getMessageValue($this->vehicleStatusMessages[0], 'driver1WorkingState', self::STATE_UNKNOWN);
             return ($rawState === "" || $rawState === null) ? self::STATE_UNKNOWN : $rawState;
        }
        return $activeState; // Should be UNKNOWN if truly before any data
    }

    private function floorToMinute(int $timestamp): int {
        return floor($timestamp / 60) * 60;
    }

    public function analyzeAdvancedDriverBehavior(): array {
            $this->logger->debug("Starting analyzeAdvancedDriverBehavior.", ['trip_id' => $this->tripId]);

            $behaviorMetrics = [
                'totalIdleTimeSeconds' => 0.0,          // Engine on, speed 0, no PTO
                'totalIdleFuelMl' => 0.0,             // Fuel consumed during totalIdleTimeSeconds
                'durationWheelbasedSpeedOverZero' => 0.0, // Total time vehicle was moving
                'fuelWheelbasedSpeedOverZero' => 0.0,   // Total fuel consumed while moving
                'cruiseControlStats' => [
                    'distanceMeters' => 0.0,
                    'durationSeconds' => 0.0,
                    'fuelMl' => 0.0
                ],
                'ptoTotalActiveSeconds' => 0.0,
                'ptoTotalFuelMl' => 0.0,
                'brakePedalCounterSpeedOverZero' => 0,
                'distanceBrakePedalActiveSpeedOverZero' => 0.0,
                'harshAccelerationEvents' => 0,         // Count based on accelerationClass
                'harshBrakingEvents' => 0,              // Count based on accelerationClass
                'coastingDurationSeconds' => 0.0,       // From drivingWithoutTorqueClass
                // You can add arrays here to store full distributions if needed:
                // 'accelerationPedalDistribution' => [],
                // 'vehicleSpeedDistribution' => [],
                // 'engineSpeedDistribution' => [],
                // 'engineTorqueDistribution' => [],
            ];

            $primaryIdleTimeSum = 0.0;
            $foundDWSZField = false; // Flag to check if 'durationWheelbasedSpeedZero' field was ever present

            if (empty($this->vehicleStatusMessages)) {
                $this->logger->warning("analyzeAdvancedDriverBehavior: No messages to process.", ['trip_id' => $this->tripId]);
                return $behaviorMetrics;
            }

            foreach ($this->vehicleStatusMessages as $message) {
                // Accumulated data is typically associated with TIMER, ENGINE_ON, or ENGINE_OFF events,
                // representing intervals. If your ENGINE_ON also contains interval data for the very
                // start, include it.
                if (!in_array($this->getMessageValue($message, 'triggerType'), ['ENGINE_ON', 'TIMER', 'ENGINE_OFF'])) {
                    // Continue if the message type isn't expected to have interval-based accumulated data relevant here.
                    // However, if *all* your messages might contain some relevant flattened accumulated fields,
                    // you might remove or adjust this condition based on your data logging strategy.
                    // For now, assuming these are the primary carriers of interval sums.
                }

                // --- Direct Accumulated Values ---
                $dwsz_raw = $this->getMessageValue($message, 'durationWheelbasedSpeedZero');
                if ($dwsz_raw !== null) {
                    $foundDWSZField = true;
                    if (is_numeric($dwsz_raw)) $primaryIdleTimeSum += (float)$dwsz_raw;
                }

                $idleFuelRaw = $this->getMessageValue($message, 'fuelWheelbasedSpeedZero');
                if (is_numeric($idleFuelRaw)) $behaviorMetrics['totalIdleFuelMl'] += (float)$idleFuelRaw;
                
                $dwsoz_raw = $this->getMessageValue($message, 'durationWheelbasedSpeedOverZero');
                if (is_numeric($dwsoz_raw)) $behaviorMetrics['durationWheelbasedSpeedOverZero'] += (float)$dwsoz_raw;

                $fuelOverZeroRaw = $this->getMessageValue($message, 'fuelWheelbasedSpeedOverZero');
                if (is_numeric($fuelOverZeroRaw)) $behaviorMetrics['fuelWheelbasedSpeedOverZero'] += (float)$fuelOverZeroRaw;

                $ccDistRaw = $this->getMessageValue($message, 'distanceCruiseControlActive');
                if (is_numeric($ccDistRaw)) $behaviorMetrics['cruiseControlStats']['distanceMeters'] += (float)$ccDistRaw;
                
                $ccDurRaw = $this->getMessageValue($message, 'durationCruiseControlActive');
                if (is_numeric($ccDurRaw)) $behaviorMetrics['cruiseControlStats']['durationSeconds'] += (float)$ccDurRaw;

                $ccFuelRaw = $this->getMessageValue($message, 'fuelConsumptionDuringCruiseActive');
                if (is_numeric($ccFuelRaw)) $behaviorMetrics['cruiseControlStats']['fuelMl'] += (float)$ccFuelRaw;

                $brakeCountRaw = $this->getMessageValue($message, 'brakePedalCounterSpeedOverZero');
                if (is_numeric($brakeCountRaw)) $behaviorMetrics['brakePedalCounterSpeedOverZero'] += (int)$brakeCountRaw;

                $brakeDistRaw = $this->getMessageValue($message, 'distanceBrakePedalActiveSpeedOverZero');
                if (is_numeric($brakeDistRaw)) $behaviorMetrics['distanceBrakePedalActiveSpeedOverZero'] += (float)$brakeDistRaw;


                // --- Class-based Accumulated Data (assuming stored as JSON strings) ---

                // PTO Activity
                $ptoClassJson = $this->getMessageValue($message, 'ptoActiveClass');
                if ($ptoClassJson && is_string($ptoClassJson)) {
                    $ptoActivities = json_decode($ptoClassJson, true);
                    if (is_array($ptoActivities)) {
                        foreach ($ptoActivities as $ptoEntry) {
                            $behaviorMetrics['ptoTotalActiveSeconds'] += (float)($this->getMessageValue($ptoEntry, 'seconds', 0.0));
                            $behaviorMetrics['ptoTotalFuelMl'] += (float)($this->getMessageValue($ptoEntry, 'milliLitres', 0.0));
                        }
                    }
                }

                // Acceleration Class (for Harsh Events)
                $accelClassJson = $this->getMessageValue($message, 'accelerationClass');
                if ($accelClassJson && is_string($accelClassJson)) {
                    $accelClasses = json_decode($accelClassJson, true);
                    if (is_array($accelClasses)) {
                        foreach ($accelClasses as $ac) {
                            $from = (float)($this->getMessageValue($ac, 'from', 0.0));
                            // $to = (float)($this->getMessageValue($ac, 'to', 0.0)); // 'to' might also be useful
                            $seconds = (float)($this->getMessageValue($ac, 'seconds', 0.0));
                            
                            // Define your thresholds for harsh events
                            if ($seconds > 0.5) { // Example: event must last at least 0.5 seconds
                                if ($from >= 2.5) { // Example: m/s^2 for harsh acceleration
                                    $behaviorMetrics['harshAccelerationEvents']++;
                                } elseif ($from <= -2.5) { // Example: m/s^2 for harsh braking
                                    $behaviorMetrics['harshBrakingEvents']++;
                                }
                            }
                        }
                    }
                }
                
                // High Acceleration Class (could also contribute to harsh events)
                $highAccelClassJson = $this->getMessageValue($message, 'highAccelerationClass');
                if ($highAccelClassJson && is_string($highAccelClassJson)) {
                    $highAccelClasses = json_decode($highAccelClassJson, true);
                    if (is_array($highAccelClasses)) {
                        foreach ($highAccelClasses as $hac) {
                            $from = (float)($this->getMessageValue($hac, 'from', 0.0));
                            $seconds = (float)($this->getMessageValue($hac, 'seconds', 0.0));
                            if ($seconds > 0.3) { // Shorter duration might be relevant for high-G
                                if ($from >= 3.0) $behaviorMetrics['harshAccelerationEvents']++; // Stricter threshold
                                if ($from <= -3.0) $behaviorMetrics['harshBrakingEvents']++; // Stricter threshold
                            }
                        }
                    }
                }


                // Driving Without Torque (Coasting)
                $coastingClassJson = $this->getMessageValue($message, 'drivingWithoutTorqueClass');
                if ($coastingClassJson && is_string($coastingClassJson)) {
                    $coastingActivities = json_decode($coastingClassJson, true);
                    if (is_array($coastingActivities)) {
                        foreach ($coastingActivities as $coastEntry) {
                            // The label is usually 'DRIVING_WITHOUT_TORQUE'
                            if (($coastEntry['label'] ?? '') === 'DRIVING_WITHOUT_TORQUE') {
                                $behaviorMetrics['coastingDurationSeconds'] += (float)($this->getMessageValue($coastEntry, 'seconds', 0.0));
                            }
                        }
                    }
                }

                // You can similarly process other class-based data like:
                // - accelerationPedalPositionClass
                // - brakePedalPositionClass
                // - retarderTorqueClass
                // - engineTorqueClass
                // - vehicleSpeedClass
                // - engineSpeedClass
                // For these, you'd typically aggregate the 'seconds', 'meters', 'milliLitres' into distribution arrays.
                // Example for vehicleSpeedClass (storing total time in each speed band)
                // $vehicleSpeedClassJson = $this->getMessageValue($message, 'vehicleSpeedClass');
                // if ($vehicleSpeedClassJson && is_string($vehicleSpeedClassJson)) {
                //     $speedClasses = json_decode($vehicleSpeedClassJson, true);
                //     if (is_array($speedClasses)) {
                //         foreach ($speedClasses as $sc) {
                //             $bandKey = "{$this->getMessageValue($sc, 'from', 'x')}-{$this->getMessageValue($sc, 'to', 'y')}";
                //             $behaviorMetrics['vehicleSpeedDistribution'][$bandKey] = 
                //                 ($behaviorMetrics['vehicleSpeedDistribution'][$bandKey] ?? 0) + 
                //                 (float)($this->getMessageValue($sc, 'seconds', 0.0));
                //         }
                //     }
                // }
            } // End foreach message loop

            $behaviorMetrics['totalIdleTimeSeconds'] = $primaryIdleTimeSum;

            // Fallback for IdleTime if durationWheelbasedSpeedZero yielded no significant data
            // $foundDWSZField indicates if the field was present at all (even if zero).
            // If it was never found, or if sum is very low and it was rarely found, fallback might be desired.
            if ($behaviorMetrics['totalIdleTimeSeconds'] < 1.0 && !$foundDWSZField) { 
                $this->logger->warning("Primary 'durationWheelbasedSpeedZero' was consistently null or not found. Attempting IdleTime fallback using exact state times.", ['trip_id' => $this->tripId, 'primary_idle_sum' => $primaryIdleTimeSum]);
                
                $exactDriverTimes = $this->calculateExactDriverActivityTimes();
                $fallbackIdleTime = 0.0;

                // Add WORK_RAW time (engine typically on and stationary for WORK)
                $workRawSeconds = $exactDriverTimes[self::STATE_WORK . '_RAW'] ?? 0.0;
                if ($workRawSeconds > 0) {
                    $fallbackIdleTime += $workRawSeconds;
                    $this->logger->debug("IdleTime fallback: Added WORK_RAW seconds.", ['trip_id' => $this->tripId, 'work_raw_seconds' => $workRawSeconds]);
                }
                
                // For REST time to be counted as IDLE: engine ON + speed ZERO.
                // This is complex to derive accurately from only daily summed REST state time.
                // The rFMS durationWheelbasedSpeedZero already accounts for this.
                // So, if that field is missing, this fallback for REST-as-idle is an approximation
                // or requires re-iterating messages to check engineSpeed & wheelBasedSpeed during REST segments.
                // For this example, we will only use WORK_RAW as a fallback, to avoid overestimating idle from REST.
                // If you have a specific business rule for REST time potentially being idle, implement it here.
                $restSeconds = $exactDriverTimes[self::STATE_REST] ?? 0.0;
                if($restSeconds > 0){
                    $this->logger->info("IdleTime fallback: Total REST duration was {$restSeconds}s. For accurate idle contribution from REST, engine state and speed during REST periods would need to be verified.", ['trip_id' => $this->tripId]);
                }


                if ($fallbackIdleTime > 0) {
                    $behaviorMetrics['totalIdleTimeSeconds'] = $fallbackIdleTime;
                    $this->logger->info("IdleTime calculated using fallback (based on WORK_RAW).", ['trip_id' => $this->tripId, 'fallback_idle_seconds' => $fallbackIdleTime]);
                } else {
                    $this->logger->warning("IdleTime fallback also resulted in zero.", ['trip_id' => $this->tripId]);
                }
            } elseif ($foundDWSZField) {
                $this->logger->info("IdleTime calculated using primary 'durationWheelbasedSpeedZero'.", ['trip_id' => $this->tripId, 'idle_seconds' => $behaviorMetrics['totalIdleTimeSeconds']]);
            } else { // Field not found, and primary sum is 0 (should be caught by the first if)
                $this->logger->warning("'durationWheelbasedSpeedZero' field not found in any relevant messages. IdleTime may be inaccurate.", ['trip_id' => $this->tripId]);
            }

            $this->logger->info("Advanced Behavior Analysis completed.", ['trip_id' => $this->tripId, 'metrics_count' => count($behaviorMetrics)]);
            return $behaviorMetrics;
        }
    
    public function countTellTales(): array {
        $redCount = 0; $yellowCount = 0;
        $activeTripTellTales = []; // To count each type of telltale once per trip at its highest severity

        foreach ($this->vehicleStatusMessages as $message) {
            $tellTaleInfoJson = $this->getMessageValue($message, 'tellTaleInfo');
            $tellTalesThisMessage = [];

            if ($tellTaleInfoJson && is_string($tellTaleInfoJson)) {
                $tellTalesThisMessage = json_decode($tellTaleInfoJson, true);
            } elseif (is_array($tellTaleInfoJson)) { // If it's already an array (less likely from flat DB)
                $tellTalesThisMessage = $tellTaleInfoJson;
            } else if ($this->getMessageValue($message, 'tellTale') && $this->getMessageValue($message, 'tellTale_State')) {
                 // Handle flattened single telltale per message if that's the case
                $tellTalesThisMessage[] = [
                    'tellTale' => $this->getMessageValue($message, 'tellTale'),
                    'state' => $this->getMessageValue($message, 'tellTale_State')
                ];
            }

            if (is_array($tellTalesThisMessage)) {
                foreach ($tellTalesThisMessage as $tt) {
                    $name = $this->getMessageValue($tt, 'tellTale');
                    $state = $this->getMessageValue($tt, 'state');
                    if ($name && $state) {
                        $isRed = ($state === 'RED');
                        $isYellow = ($state === 'YELLOW');
                        if ($isRed) {
                            if (!isset($activeTripTellTales[$name]) || $activeTripTellTales[$name] !== 'RED') {
                                $redCount++;
                                if (isset($activeTripTellTales[$name]) && $activeTripTellTales[$name] === 'YELLOW') $yellowCount--; // Avoid double counting if it escalated
                                $activeTripTellTales[$name] = 'RED';
                            }
                        } elseif ($isYellow) {
                            if (!isset($activeTripTellTales[$name])) {
                                $yellowCount++;
                                $activeTripTellTales[$name] = 'YELLOW';
                            }
                        }
                    }
                }
            }
        }
        return ['redWarningCount' => $redCount, 'yellowWarningCount' => $yellowCount];
    }

    // --- storeCalculatedTripData method to match the user's DB schema ---
    public function storeCalculatedTripData() {
        $this->logger->info("Starting to store calculated data.", ['trip_id' => $this->tripId]);

        $distanceKm = $this->calculateTotalDistanceKm();
        $totalFuelLiters = $this->calculateTotalFuelUsedLiters();
        $avgFuelConsumptionLp100Km = $this->calculateAverageFuelConsumptionLitersPer100Km();
        $co2Kg = $this->calculateCO2ConsumedKg();
        $gpsDistanceKm = $this->calculateGPSDistanceKm();
        $exactDriverTimes = $this->calculateExactDriverActivityTimes();
        // $creditedMinutes = $this->calculateCreditedActivityMinutes(); // Call if you need these values
        $advancedBehavior = $this->analyzeAdvancedDriverBehavior();
        $highestSpeed = $this->calculateHighestSpeedKmh();
        $avgDrivingSpeed = $this->calculateAverageDrivingSpeedKmh();
        $tellTales = $this->countTellTales();

        $tripDurationSeconds = 0;
        if ($this->tripEngineOnTime && $this->tripEngineOffTime) {
            $tripDurationSeconds = strtotime($this->tripEngineOffTime) - strtotime($this->tripEngineOnTime);
        }
        $tripDurationFormatted = ($tripDurationSeconds > 0) ? gmdate("H:i:s", $tripDurationSeconds) : "00:00:00";
        $driver1IdForTrip = null;
        if (!empty($this->vehicleStatusMessages)) {
            // Attempt to get Driver1ID from the first message, assuming it's consistent for the trip
            // or from a message triggered by ENGINE_ON or DRIVER_LOGIN if available
            $driver1IdForTrip = $this->getMessageValue($this->vehicleStatusMessages[0], 'driver1Id');
            // You might want more sophisticated logic if driver can change mid-trip and you want the primary driver
        }
        if (!$driver1IdForTrip && !empty($this->vehicleStatusMessages)) { // Fallback if first was null but others might have it
            foreach($this->vehicleStatusMessages as $msg) {
                $d1id = $this->getMessageValue($msg, 'driver1Id');
                if($d1id && $d1id !== "") { // Take the first non-empty driver ID
                    $driver1IdForTrip = $d1id;
                    break;
                }
            }
        }
        $this->logger->info("Driver1ID for trip storage", ['trip_id' => $this->tripId, 'driver1Id' => $driver1IdForTrip]);

        $updateData = [
            'Distance' => round($distanceKm, 3),
            'GPS_Distance' => round($gpsDistanceKm, 3),
            'Duration' => $tripDurationFormatted,
            'FuelUsed' => round($totalFuelLiters, 3),
            'FuelUsage' => round($avgFuelConsumptionLp100Km, 2),
            'CO2_emission' => round($co2Kg, 3),
            'Driver1ID' => $driver1IdForTrip,
            'DriveTime' => $exactDriverTimes[self::STATE_DRIVE] ?? 0,
            'IdleTime' => $advancedBehavior['totalIdleTimeSeconds'] ?? 0, // From accumulatedData

            'durationCruiseControlActive' => $advancedBehavior['cruiseControlStats']['durationSeconds'] ?? null,
            'distanceCruiseControlActive' => $advancedBehavior['cruiseControlStats']['distanceMeters'] ?? null,
            'durationWheelbaseSpeedOverZero' => $advancedBehavior['durationWheelbasedSpeedOverZero'] ?? null,
            'fuelConsumptionDuringCruiseActive' => $advancedBehavior['cruiseControlStats']['fuelMl'] ?? null,
            'durationWheelbaseSpeedZero' => $advancedBehavior['totalIdleTimeSeconds'] ?? null, // Matches IdleTime
            'fuelDuringWheelbaseSpeedZero' => $advancedBehavior['totalIdleFuelMl'] ?? null,
            'fuelDuringWheelbaseSpeedOverZero' => $advancedBehavior['fuelWheelbasedSpeedOverZero'] ?? null,
            'brakePedalCounterSpeedOverZero' => $advancedBehavior['brakePedalCounterSpeedOverZero'] ?? null,
            'distanceBrakePedalActiveSpeedOverZero' => $advancedBehavior['distanceBrakePedalActiveSpeedOverZero'] ?? null,

            'AverageDrivingSpeed' => round($avgDrivingSpeed, 3),
            'HighestDrivingSpeed' => round($highestSpeed, 2),
            'red_TellTale' => ($tellTales['redWarningCount'] > 0) ? 1 : 0,
            'TripCalc' => 1,
            'TripCalcNotes' => null,
            'UpdatedDB' => date('Y-m-d H:i:s') // Set update timestamp
        ];

        // Other fields like start/end odometer, fuel levels etc. from your trips table might be updated
        // here if they are derived from the first/last message, or they might be set when the trip is created/closed.
        // Example:
        if (!empty($this->vehicleStatusMessages)) {
            $updateData['start_odometer'] = (int)($this->getMessageValue($this->vehicleStatusMessages[0], 'hrTotalVehicleDistance'));
            $updateData['start_fuellevel'] = (int)($this->getMessageValue($this->vehicleStatusMessages[0], 'fuelLevel1'));
            $updateData['end_odometer'] = (int)($this->getMessageValue(end($this->vehicleStatusMessages), 'hrTotalVehicleDistance'));
            $updateData['end_fuelLevel'] = (int)($this->getMessageValue(end($this->vehicleStatusMessages), 'fuelLevel1'));
        }


        $setClauses = [];
        $parameters = [];
        foreach ($updateData as $key => $value) {
            $setClauses[] = "`{$key}` = ?";
            $parameters[] = $value;
        }
        
        if (empty($setClauses)) {
            $this->logger->info("No data to update for trip.", ['trip_id' => $this->tripId]);
            return;
        }

        $parameters[] = $this->tripId;
        $sql = "UPDATE trips SET " . implode(', ', $setClauses) . " WHERE Trip_NO = ?"; // Ensure Trip_NO is correct PK

        try {
            $this->logger->debug("Executing trip update SQL.", ['trip_id' => $this->tripId, 'sql_preview' => $sql, 'params_count' => count($parameters)]);
            if (method_exists($this->db, 'query')) {
                $this->db->query($sql, $parameters);
            } elseif (method_exists($this->db, 'prepare')) { // PDO style
                $stmt = $this->db->prepare($sql);
                $stmt->execute($parameters);
            } else {
                throw new \Exception("Database object does not have a recognized query/execute method.");
            }
            $this->logger->info("Successfully stored calculated data in 'trips' table.", ['trip_id' => $this->tripId]);

            // Now, update the cumulative daily 'drivetimes' table
            $this->updateCumulativeDriveTimes($exactDriverTimes);

        } catch (\Exception $e) {
            $this->logger->error("Failed to store calculated data.", [
                'trip_id' => $this->tripId, 'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function updateCumulativeDriveTimes(array $exactDriverTimes) {
        if (empty($this->vehicleStatusMessages)) return;

        $driverId = $this->getMessageValue($this->vehicleStatusMessages[0], 'driver1Id'); // Get driver from first message
        if (!$driverId) {
            $this->logger->warning("No Driver1ID found for trip, cannot update drivetimes.", ['trip_id' => $this->tripId]);
            return;
        }

        // Determine DriveDate based on trip start time (consider timezone if necessary)
        $driveDate = date("Y-m-d", strtotime($this->tripEngineOnTime));

        $CDRIVE = $exactDriverTimes[self::STATE_DRIVE] ?? 0;
        $CWORK = $exactDriverTimes[self::STATE_WORK . '_FILTERED'] ?? 0; // Using filtered work time
        $CAVAILABLE = $exactDriverTimes[self::STATE_AVAILABLE] ?? 0;
        $CREST = $exactDriverTimes[self::STATE_REST] ?? 0;

        // Your cumulative update SQL (ensure parameters are properly bound by your DB class)
        $sql = "INSERT INTO DriveTimes (DriverId, DriveDate, drive, work, available, rest)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    drive = drive + VALUES(drive), 
                    work = work + VALUES(work), 
                    available = available + VALUES(available), 
                    rest = rest + VALUES(rest),
                    lastUpdate = NOW()";
        
        $params = [$driverId, $driveDate, $CDRIVE, $CWORK, $CAVAILABLE, $CREST];

        try {
            $this->logger->debug("Updating cumulative DriveTimes.", ['trip_id' => $this->tripId, 'driver_id' => $driverId, 'drive_date' => $driveDate, 'params' => $params]);
            if (method_exists($this->db, 'query')) {
                $this->db->query($sql, $params);
            } elseif (method_exists($this->db, 'prepare')) {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
             $this->logger->info("Successfully updated cumulative DriveTimes.", ['trip_id' => $this->tripId, 'driver_id' => $driverId, 'drive_date' => $driveDate]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update cumulative DriveTimes.", [
                'trip_id' => $this->tripId, 'driver_id' => $driverId, 'drive_date' => $driveDate, 'error' => $e->getMessage()
            ]);
            // Decide if this error should halt further processing or just be logged
        }
    }
}