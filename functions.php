<?php

include '/etc/pronto.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


/*********************************************************************************************************************************************/
/**
 * Function to get database connection
 * If slave is up and running and 0 seconds behind master, use slave otherwise use master
**/
function getDatabaseConnection($slaveConn, $masterConn) {
    /* Verify if slave have connection errors */
    if ($slaveConn->connect_error) {
    
        return $masterConn;
    }
    
    /* Get the database version */
    $result  = $slaveConn->query("SELECT VERSION() AS version");
    $row     = $result->fetch_assoc();
    $version = $row['version'];
    
    /* 
    Verify if MySQL or MariaDB is used 
    MariaDB   ---> SHOW SLAVE STATUS
    MySQL >=8 ---> SHOW REPLICA STATUS
    MySQL <8  ---> SHOW SLAVE STATUS
    */
    if (stripos($version, 'MariaDB') !== false) {
        #echo "Database is MariaDB, version: $version";
        $dbVersion = 'MariaDB';
        $qry       = "SHOW SLAVE STATUS";
    } else {
        echo "Database is MySQL, version: $version";
        $dbVersion = 'MySQL';
    
        // Extract the major version number
        preg_match('/^(\d+)\./', $version, $matches);
        $mysqlVersion = isset($matches[1]) ? (int)$matches[1] : 0;
    
        if ($mysqlVersion >= 8) {
            #echo "\nMySQL version is 8 or higher.";
            $qry = "SHOW REPLICA STATUS";
        } else {
            #echo "\nMySQL version is lower than 8.";
            $qry = "SHOW SLAVE STATUS";
        }
    }
    
    /* Verify slave connection */
    $result = $slaveConn->query($qry);
    if ($result === false) {
        #echo "Error executing query: " . $slaveConn->error;
        return $masterConn;
    }
        
    if ($result->num_rows > 0) {
        
        while ($row = $result->fetch_assoc()) {
    
            if ($dbVersion == 'MySQL' && $mysqlVersion >= 8) {
                $slaveIoRunning      = $row['Replica_IO_Running'] ;
                $slaveSqlRunning     = $row['Replica_SQL_Running'] ;
                $secondsBehindMaster = $row['Seconds_Behind_Source'] ;
    
            }else {
                $slaveIoRunning      = $row['Slave_IO_Running'] ;
                $slaveSqlRunning     = $row['Slave_SQL_Running'] ;
                $secondsBehindMaster = $row['Seconds_Behind_Master'] ;
            }
                   
        }
        /* Determine if slave is behind or not running correctly */
        if ($secondsBehindMaster > 1 || $slaveIoRunning !== "Yes" || $slaveSqlRunning !== "Yes") {
            return $masterConn; 
        }
    }
    
        /* If everything is fine, echo the slave connection */
    return $slaveConn;
}
/*********************************************************************************************************************************************/

/**
 * Function to check the callType Unica/Doppia
**/
function checkCallType($dbh, $callerCode) {

    $nrChiamata ='';
    $sql = "
        WITH RECURSIVE enumerated AS (
            SELECT *, ROW_NUMBER() OVER (ORDER BY start_time) AS rn
            FROM call_log
            WHERE channel_group = 'DID_INBOUND' 
            AND caller_code = ? 
            AND uniqueid NOT IN (
                SELECT uniqueid 
                FROM vicidial_did_log vdl 
                INNER JOIN technic_inoltro_numbers tin ON tin.number_dialed = vdl.extension 
                WHERE vdl.call_date BETWEEN (DATE_SUB(NOW(), INTERVAL 7 DAY)) AND NOW() 
                AND vdl.caller_id_number = ? )
            AND start_time BETWEEN (DATE_SUB(NOW(), INTERVAL 30 DAY)) AND NOW()
        ), marked AS (
            SELECT *, start_time AS last_date, CAST('Unica' AS CHAR(6)) AS mark
            FROM enumerated 
            WHERE rn = 1
        UNION ALL
            SELECT enumerated.*, 
                   CASE WHEN marked.last_date > enumerated.start_time - INTERVAL 7 DAY 
                        THEN marked.last_date 
                        ELSE enumerated.start_time 
                   END,
                   CASE WHEN marked.last_date > enumerated.start_time - INTERVAL 7 DAY 
                        THEN 'Doppia' 
                        ELSE 'Unica' 
                   END  
            FROM enumerated
            JOIN marked ON enumerated.rn = marked.rn + 1
        )
        SELECT mark 
        FROM marked
        ORDER BY start_time DESC 
        LIMIT 1";
    
    if ($stmt = $dbh->prepare($sql)) {
        $stmt->bind_param('ss', $callerCode, $callerCode);
        $stmt->execute();
        $stmt->bind_result($nrChiamata);
        $stmt->fetch();
        $stmt->close();
        
        return $nrChiamata;

    } else {
        // Handle error
        die("Error preparing the statement: " . $dbh->error);
    }
}
/*********************************************************************************************************************************************/

/**
 * Function to get the token from db
 * It expects callType and $dbh(database connection) as parameter
**/
function getToken($callType, $dbh) {

    $token = '';
    $sql = "SELECT token FROM pronto_token WHERE callType = ?"; 
   
    if ($stmt = $dbh->prepare($sql)) {
        $stmt->bind_param('s', $callType);
        $stmt->execute();
        $stmt->bind_result($token);
        $stmt->fetch();
        $stmt->close();
        
        return $token;

    } else {
        // Handle error
        die("Error preparing the statement: " . $dbh->error);
    }
}
/*********************************************************************************************************************************************/

/**
 * Function to verify if call should be displayed or not 
 * It expects $numberDialed and $dbh(database connection) as parameter
**/
function displayCall($numberDialed, $dbh) {

    $sql = "SELECT inoltro_number FROM pronto_nr_nuk_duhet_shfaqen_sistem WHERE inoltro_number = ?"; 

    if ($stmt = $dbh->prepare($sql)) { 
        $stmt->bind_param("s", $numberDialed);
        $stmt->execute();
        $stmt->store_result();
        $result = $stmt->num_rows();
    
        if ($result >0) {
            return 'NoDisplay';
        } else {
            return 'Display';
        }
    } else {
        // Handle error
        die("Error preparing the statement: " . $dbh->error);
    }
}
/*********************************************************************************************************************************************/

### Function to verify if call should be sent to tech url or not
### It expects $numberDialed and $dbh(database connection) as parameter
function inboundCallUrlToSend($numberDialed, $dbh) {

    $sql = "SELECT number_dialed FROM technic_inoltro_numbers WHERE number_dialed = ?"; 

    if ($stmt = $dbh->prepare($sql)) { 
        $stmt->bind_param("s", $numberDialed);
        $stmt->execute();
        $stmt->store_result();
        $result = $stmt->num_rows();
    
        if ($result >0) {
            return 'TechnicCall';
        } else {
            return 'InboundCall';
        }
    } else {
        // Handle error
        die("Error preparing the statement: " . $dbh->error);
    }
}
/*********************************************************************************************************************************************/

/**
 * Function to send an asynchronous HTTP POST request to another server
 *
**/

function sendPrePostRequestAsync($data, $token, $callToSend) {

    // Set the URL based on the call type
    if ($callToSend == "OutboundCall") {
        $url = 'https://xxxx.xxxx.xxxxx/api/calls/outboundCalls';
    } elseif ($callToSend == "InboundCall") {
        $url = 'https://xxxx.xxxx.xxxxx/api/calls/inboundCalls';
    } elseif ($callToSend == "TechnicCall") {
        $url = 'https://xxxx.xxxx.xxxxx/api/calls/technicCalls'; 
    } elseif ($callToSend == "TechnicToClientCall") {
        $url = 'https://xxxx.xxxx.xxxxx/api/calls/technicToClientCalls';  
    } else {
        trigger_error("Invalid call type: $callToSend", E_USER_WARNING);
        return 400;  // Return immediately with 400 status code for invalid request
    }

    // Convert the array to a JSON string
    $postData = json_encode($data, JSON_UNESCAPED_SLASHES);

    // Initialize cURL session
    $ch = curl_init($url);

    // Set the options for cURL
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ' . $token, // Include the Bearer token if needed
    ]);
#    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // false Do not return the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // true,  return the response
    curl_setopt($ch, CURLOPT_POST, true);            // Use POST method
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); // Attach the JSON data
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);    // Forbid reuse of the connection
#    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 50); // Timeout for connecting in ms (0.05 seconds)
#    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 50);        // Timeout for the entire request in ms (0.05 seconds)
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);   // Don't use cached connection
#    curl_setopt($ch, CURLOPT_NOSIGNAL, true);        // Required for timeouts below 1 second on some platforms
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification (use cautiously)
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // Execute the request asynchronously
    curl_exec($ch);

    // Get HTTP response code
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Check for errors (optional)
    if (curl_errno($ch)) {
        $displayMessage = curl_error($ch); // Capture the error
    } else {
        $displayMessage = 1; // Success
    }

    // Close the cURL session
    curl_close($ch);

    #return $displayMessage;
    return $httpCode;
}
/*********************************************************************************************************************************************/

/**
* Function to insert into pronto_data table
* The columns and placeholders are dynamically generated based on the keys present in params
* Only columns that have corresponding values in params are included in the SQL statement
* The SQL statement is prepared and executed with the dynamically generated placeholders and values.
**/

function insertProntoData($dbh, $log_file, $params) {
    // Define the column names
    $columns = [
       'uniqueid', 'vl_uniqueid', 'vl_lead_id', 'vl_campaign_id', 'vl_call_date', 'vl_length_in_sec', 
       'vl_status', 'vl_phone_number', 'vl_user', 'vl_user_group', 'vl_term_reason', 'vcl_closecallid', 
       'vcl_lead_id', 'vcl_campaign_id', 'vcl_call_date' ,'vcl_length_in_sec', 'vcl_status', 'vcl_phone_number', 
       'vcl_user', 'vcl_user_group', 'vcl_term_reason', 'vcl_uniqueid', 'cl_uniqueid', 'cl_extension', 
       'cl_number_dialed', 'cl_caller_code', 'cl_start_time', 'cl_end_time', 'cl_length_in_sec', 
       'cl_start_epoch', 'rl_recording_id', 'rl_start_time', 'rl_end_time', 'rl_length_in_sec', 
       'rl_filename', 'rl_location', 'rl_lead_id', 'rl_user', 'rl_vicidial_id,url', 'tipo_chiam', 
       'call_type', 'callToSend', 'stato', 'call_note', 'url_response_code', 'url_response_text', 
       'postMessageResponse', 'postSentData', 'is_sent'
    ];

    // Filter columns that exist in the $params array
    $filtered_columns = array_filter($columns, function($col) use ($params) {
        return array_key_exists($col, $params);
    });

    // Prepare the column list and placeholders
    $placeholders = implode(', ', array_fill(0, count($filtered_columns), '?'));
    $columns_list = implode(', ', $filtered_columns);

    // Prepare the SQL statement
    $query_insert_data = "INSERT INTO pronto_data ($columns_list) VALUES ($placeholders)";

    // Prepare the values array based on the filtered columns
    $values = [];
    foreach ($filtered_columns as $col) {
        $values[] = $params[$col];
    }

    // Initialize the statement
    $statement_data = $dbh->prepare($query_insert_data);

    if ($statement_data === false) {
        // Log if statement preparation fails
        file_put_contents($log_file, "Failed to prepare statement: " . $dbh->error . "\n", FILE_APPEND);
        return;
    }

    // Dynamically bind the parameters
    $types = str_repeat('s', count($values)); // Assuming all values are strings, adjust as necessary
    $statement_data->bind_param($types, ...$values);

    // Execute the query and handle errors
    if (!$statement_data->execute()) {
        // Log the error to a file
        file_put_contents($log_file, "Error occurred while inserting into pronto_data: " . $statement_data->error . "\n", FILE_APPEND);
        file_put_contents($log_file, "------------------------------------------------------------------------------------\n", FILE_APPEND);
    }

    // Close the statement
    $statement_data->close();
}
/*********************************************************************************************************************************************/

/**
* Function to insert into pre_pronto_data table
* The columns and placeholders are dynamically generated based on the keys present in params
* Only columns that have corresponding values in params are included in the SQL statement
* The SQL statement is prepared and executed with the dynamically generated placeholders and values.
**/

function insertPreProntoData($dbh, $log_file, $params) {
    // Define the column names
    $columns = [
        'uniqueid', 'vcl_phone_number', 'cl_number_dialed', 'cl_caller_code', 'pre_url', 
        'tipo_chiam', 'call_type', 'callToSend', 'stato', 'call_note', 'url_response_code', 
        'postSentData', 'is_sent'
    ];

    // Filter columns that exist in the $params array
    $filtered_columns = array_filter($columns, function($col) use ($params) {
        return array_key_exists($col, $params);
    });

    // Prepare the column list and placeholders
    $placeholders = implode(', ', array_fill(0, count($filtered_columns), '?'));
    $columns_list = implode(', ', $filtered_columns);

    // Prepare the SQL statement
    $query_insert_data = "INSERT INTO pre_pronto_data ($columns_list) VALUES ($placeholders)";

    // Prepare the values array based on the filtered columns
    $values = [];
    foreach ($filtered_columns as $col) {
        $values[] = $params[$col];
    }

    // Initialize the statement
    $statement_data = $dbh->prepare($query_insert_data);

    if ($statement_data === false) {
        // Log if statement preparation fails
        file_put_contents($log_file, "Failed to prepare statement: " . $dbh->error . "\n", FILE_APPEND);
        return;
    }

    // Dynamically bind the parameters
    $types = str_repeat('s', count($values)); // Assuming all values are strings, adjust as necessary
    $statement_data->bind_param($types, ...$values);

    // Execute the query and handle errors
    if (!$statement_data->execute()) {
        // Log the error to a file
        file_put_contents($log_file, "Error occurred while inserting into pre_pronto_data: " . $statement_data->error . "\n", FILE_APPEND);
        file_put_contents($log_file, "------------------------------------------------------------------------------------\n", FILE_APPEND);
    }

    // Close the statement
    $statement_data->close();
}
/*********************************************************************************************************************************************/

/**
* Function to select data from vicidial_closer_log table
**/
function selectVicidialCloserLogData($dbh, $uniqueid) {
    $data              = [];
    $vcl_closecallid   = '';
    $vcl_lead_id       = '';
    $vcl_campaign_id   = '';
    $vcl_call_date     = '';
    $vcl_length_in_sec = '';
    $vcl_status        = '';
    $vcl_phone_number  = '';
    $vcl_user          = '';
    $vcl_user_group    = '';
    $vcl_term_reason   = '';
    $vcl_uniqueid      = '';

    $sql = "SELECT closecallid, lead_id, campaign_id, call_date, length_in_sec, status, phone_number, user, user_group, term_reason, uniqueid
            FROM vicidial_closer_log 
            WHERE uniqueid = ? 
            ORDER BY closecallid DESC LIMIT 1";

    if ($stmt = $dbh->prepare($sql)) {
        $stmt->bind_param("s", $uniqueid);
        $stmt->execute();
        $stmt->bind_result($vcl_closecallid, $vcl_lead_id, $vcl_campaign_id, $vcl_call_date, $vcl_length_in_sec, $vcl_status, $vcl_phone_number, $vcl_user, $vcl_user_group, $vcl_term_reason, $vcl_uniqueid );

        // Fetch the result
        if ($stmt->fetch()) {
            // Populate the result array with mapped values
            $data = [
                'vcl_closecallid'    => $vcl_closecallid,
                'vcl_lead_id'        => $vcl_lead_id,
                'vcl_campaign_id'    => $vcl_campaign_id,
                'vcl_call_date'      => $vcl_call_date,
                'vcl_length_in_sec'  => $vcl_length_in_sec,
                'vcl_status'         => $vcl_status,
                'vcl_phone_number'   => $vcl_phone_number,
                'vcl_user'           => $vcl_user,
                'vcl_user_group'     => $vcl_user_group,
                'vcl_term_reason'    => $vcl_term_reason,
                'vcl_uniqueid'       => $vcl_uniqueid,
            ];
        }

        // Close the statement
        $stmt->close();
    } else {
        echo "Error preparing query: " . $dbh->error;
    }

    return $data;
}
/*********************************************************************************************************************************************/

/**
* Function to select data from call_log table
**/
function selectCallLogData($dbh, $uniqueid) {
    $data              = [];
    $cl_uniqueid       = '';       
    $cl_extension      = '';
    $cl_number_dialed  = ''; 
    $cl_caller_code    = ''; 
    $cl_start_time     = ''; 
    $cl_end_time       = ''; 
    $cl_length_in_sec  = ''; 
    $cl_start_epoch    = ''; 

    $sql = "SELECT uniqueid, extension, number_dialed, caller_code, start_time, end_time, length_in_sec, start_epoch
            FROM call_log 
            WHERE uniqueid = ?";

    if ($stmt = $dbh->prepare($sql)) {
        $stmt->bind_param("s", $uniqueid);
        $stmt->execute();
        $stmt->bind_result($cl_uniqueid, $cl_extension, $cl_number_dialed, $cl_caller_code, $cl_start_time, $cl_end_time, $cl_length_in_sec, $cl_start_epoch);

        // Fetch the result
        if ($stmt->fetch()) {
            // Populate the result array with mapped values
            $data = [
                'cl_uniqueid'      => $cl_uniqueid,
                'cl_extension'     => $cl_extension,
                'cl_number_dialed' => $cl_number_dialed,
                'cl_caller_code'   => $cl_caller_code,
                'cl_start_time'    => $cl_start_time,
                'cl_end_time'      => $cl_end_time,
                'cl_length_in_sec' => $cl_length_in_sec,
                'cl_start_epoch'   => $cl_start_epoch,
            ];
        }

        // Close the statement
        $stmt->close();
    } else {
        echo "Error preparing query: " . $dbh->error;
    }

    return $data;
}
/*********************************************************************************************************************************************/

/**
* Function to select data from recording_log table
* We have two scenarios 
* Inbound calls we use callerCode variable filename like query
* Outbound calls we use uniqueid variable where vicidial_id = query
* $filterType is a static variable with values 'callerCode' or 'uniqueid'
**/
function selectRecordingLogData($dbh, $callerCode, $filterType) {
    $data              = [];
    $rl_recording_id   = '';
    $rl_start_time     = '';
    $rl_end_time       = '';
    $rl_length_in_sec  = '';
    $rl_filename       = '';
    $rl_location       = '';
    $rl_lead_id        = '';
    $rl_user           = '';
    $rl_vicidial_id    = '';

    if ($filterType =='callerCode') {
    
        $sql = "SELECT recording_id, start_time, end_time, length_in_sec, filename, location, lead_id, user, vicidial_id
                FROM recording_log 
                WHERE filename like ? 
                ORDER BY recording_id DESC LIMIT 1";

        $callerCode = $callerCode . '%';       
    
        if ($stmt = $dbh->prepare($sql)) {
            $stmt->bind_param("s", $callerCode);
            $stmt->execute();
            $stmt->bind_result($rl_recording_id, $rl_start_time, $rl_end_time, $rl_length_in_sec, $rl_filename, $rl_location, $rl_lead_id, $rl_user, $rl_vicidial_id);
    
            // Fetch the result
            if ($stmt->fetch()) {
                // Populate the result array with mapped values
                $data = [
                    'rl_recording_id'  => $rl_recording_id,
                    'rl_start_time'    => $rl_start_time,
                    'rl_end_time'      => $rl_end_time,
                    'rl_length_in_sec' => $rl_length_in_sec,
                    'rl_filename'      => $rl_filename,
                    'rl_location'      => $rl_location,
                    'rl_lead_id'       => $rl_lead_id,
                    'rl_user'          => $rl_user,
                    'rl_vicidial_id'   => $rl_vicidial_id,
                ];
            }
    
            // Close the statement
            $stmt->close();
        } else {
            echo "Error preparing query: " . $dbh->error;
        }
    }elseif ($filterType =='uniqueid') {

        $sql = "SELECT recording_id, start_time, end_time, length_in_sec, filename, location, lead_id, user, vicidial_id
        FROM recording_log 
        WHERE vicidial_id = ? 
        ORDER BY recording_id DESC LIMIT 1";

        if ($stmt = $dbh->prepare($sql)) {
            $stmt->bind_param("s", $callerCode);
            $stmt->execute();
            $stmt->bind_result($rl_recording_id, $rl_start_time, $rl_end_time, $rl_length_in_sec, $rl_filename, $rl_location, $rl_lead_id, $rl_user, $rl_vicidial_id);
        
            // Fetch the result
            if ($stmt->fetch()) {
                // Populate the result array with mapped values
                $data = [
                    'rl_recording_id'  => $rl_recording_id,
                    'rl_start_time'    => $rl_start_time,
                    'rl_end_time'      => $rl_end_time,
                    'rl_length_in_sec' => $rl_length_in_sec,
                    'rl_filename'      => $rl_filename,
                    'rl_location'      => $rl_location,
                    'rl_lead_id'       => $rl_lead_id,
                    'rl_user'          => $rl_user,
                    'rl_vicidial_id'   => $rl_vicidial_id,
                ];
            }

            // Close the statement
            $stmt->close();
        } else {
        echo "Error preparing query: " . $dbh->error;
        }
    }

    return $data;
}
/*********************************************************************************************************************************************/



/*
$masterConn = OpenCon();
$slaveConn  = OpenSlaveCon();

$dbh = getDatabaseConnection($slaveConn, $masterConn);



// Run your query
$query = "SELECT @@hostname AS server_name, @@global.hostname AS server_host;";
$result = $dbh->query($query);

if ($result->num_rows > 0) {
        
    while ($row = $result->fetch_assoc()) {
               
        $server_name     = $row['server_name'] ;
        $server_host    = $row['server_host'] ;

    }

    echo "Server Name: $server_name \n";
    echo "Server Host: $server_host \n";
}

$caller_code ='1000310824';
$nrChiamata = checkCallType($dbh, $caller_code);

echo "Call Type: $nrChiamata \n";

// Close DB connection
CloseCon($masterConn); 
CloseCon($slaveConn); 

*/
?>