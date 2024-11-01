<?php
/*
The following code verify if slave is up and running, if yes it returns the slave connection 
which later can be used on the select queries. 
For performance improvement, selects can be run on the slave side.
*/
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* How to use it example*/
$slaveConn  = SlaveConn();
$masterConn = MasterConn();
$dbh        = getDatabaseConnection($slaveConn, $masterConn);

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
/* ******************* */

/*
Result 
Server Name: prontoitdbs
Server Host: prontoitdbs
*/
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
        $dbVersion = 'MariaDB';
        $qry       = "SHOW SLAVE STATUS";
    } else {
        echo "Database is MySQL, version: $version";
        $dbVersion = 'MySQL';
    
        /* Extract version number */
        preg_match('/^(\d+)\./', $version, $matches);
        $mysqlVersion = isset($matches[1]) ? (int)$matches[1] : 0;
    
        if ($mysqlVersion >= 8) {
            $qry = "SHOW REPLICA STATUS";
        } else {
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
 * Function Master connection
**/
function MasterConn() {
  $dbhost = "masterHost";
  $dbuser = "masterUser";
  $dbpass = "masterPassword";
  $db     = "masterDatabase";
  $conn   = new mysqli($dbhost, $dbuser, $dbpass,$db) or die("Connect failed: %s\n". $conn -> error);

  return $conn;
}
/*********************************************************************************************************************************************/
/**
 * Function Slave connection
**/
function SlaveConn(){
  $dbhost = "slaveHost";
  $dbuser = "slaveUser";
  $dbpass = "slavePassword";
  $db     = "slaveDatabase";
  $conn   = new mysqli($dbhost, $dbuser, $dbpass,$db) or die("Connect failed: %s\n". $conn -> error);

  return $conn;
}
