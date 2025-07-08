<?php


 function rdw_kenteken_info($Request){
    echo "\n   ∙ collecting vehicles for updating";
    $R	= CollectVehicles($Request);
    $counter=0;
    // Initialize a proper result array for the final logging
    $Result = [
        'count' => 0,
        'httpcode' => '200',
        'timeneeded' => '00:00:00',
        'downloadsize' => 0,
        'endpoint' => $Request->name_EndPoint ?? 'rdw_kenteken_info'
    ];
    $Request->header = array($Request->username .': '.$Request->password);

    if (count($R) > 0 ){
        echo "\n   ∙ accessing API (".count($R).")";
        $db = DB::getInstance();

        foreach($R as $val) {
            $Request->url_ext = ExtractLicensePlate($val->LicensePlate);
            $apiResult = Connect2API($Request); // Use a different variable to not conflict with final Result

            if ($apiResult['httpcode'] == '200'){
                $counter++;
                $data = json_decode($apiResult['data'],true);

                if (count($data) > 0){
                    // Case 1: RDW API returned data, so we INSERT or UPDATE with full details.
                    $rdwval = $data[0]; // Assuming one result per license plate
                    $nsd = new DateTimeImmutable($rdwval['vervaldatum_apk_dt']);
                    $frd = new DateTimeImmutable($rdwval['datum_eerste_tenaamstelling_in_nederland_dt']);
                    $vtd = new DateTimeImmutable($rdwval['vervaldatum_tachograaf_dt']);

                    $sql = "INSERT INTO mot_register (vehicle, country, createdDate, next_service_date, first_registration_date, tachograph_revoke_date) 
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                country = VALUES(country), 
                                createdDate = VALUES(createdDate), 
                                next_service_date = VALUES(next_service_date),
                                first_registration_date = VALUES(first_registration_date),
                                tachograph_revoke_date = VALUES(tachograph_revoke_date)";

                    $params = [
                        $val->vin,
                        '31', // country
                        date('Y-m-d H:i:s'),
                        $nsd->format('Y-m-d'),
                        $frd->format('Y-m-d'),
                        $vtd->format('Y-m-d')
                    ];
                    $db->query($sql, $params);
                    echo  "\n          * RDW -> vehicle updated : ".$val->vin." | ";

                } else {
                    // Case 2: RDW API found no data, so we INSERT or UPDATE with minimal details.
                    $sql = "INSERT INTO mot_register (vehicle, createdDate, country) 
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                createdDate = VALUES(createdDate)"; // Just update the checked timestamp

                    $params = [$val->vin, date('Y-m-d H:i:s'), null];
                    $db->query($sql, $params);
                    echo  "\n          * RDW -> unknown vehicle in NL : ".$val->vin." | ";
                }
            }
            else {
                echo " Error http-code : ". $apiResult['httpcode']." errors :".$apiResult['error'];
            }
            sleep(2);
        }
        $Result['count'] = $counter;
    }
    else {
        echo "\n   ∙ no vehicles need updating";
    }
    Update_Finished_SchedulerTask($Request,$Result);
}
function CollectVehicles($Request){
    $db = DB::getInstance();
    
    // This query has been updated to only select vehicles that have never been checked
    // (mr.vehicle IS NULL) or were last checked more than 7 days ago.
    $query  = 
    "   SELECT v.LicensePlate, v.vin, mr.id as id 
        FROM vehicles v 
        LEFT JOIN customer_vehicle cv ON v.VIN = cv.vehicleVin
        LEFT JOIN customers c ON cv.custId = c.id
        LEFT JOIN mot_register mr ON mr.vehicle = v.vin
        WHERE 
            c.country_id=31 AND 
            v.LicensePlate != '-' AND 
            cv.custId = ? AND
            (mr.createdDate IS NULL OR mr.createdDate < NOW() - INTERVAL 7 DAY)
    ";

    // Use a prepared statement for security by passing the customer_id as a parameter.
    $Q  = $db->query($query, [$Request->customer_id]);
    return $Q->results();
}
    function ExtractLicensePlate($value) {
        $v=str_replace('-','',$value);
        $v=str_replace('/','',$v);
        $v=str_replace(' ','',$v);
        return "?kenteken=".$v;
    }


?>