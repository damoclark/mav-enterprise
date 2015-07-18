<?php

/**
 * This script will create all the required tables into an existing Moodle
 * DB so that MAV is able to aggregate activity information for display
 *
 * Before running this script, the database must exist, any schemas used
 * within the database must be created, and the user account connecting to
 * this database must have write permissions to add the tables and indexes
 *
 * If using postgresql, then you may need to set the search_path for the
 * user/database combination according to your needs: eg.
 * ALTER ROLE postgres IN DATABASE moodle SET search_path = public, mav;
 */

function help($code=0)
{
	global $argv ;
	
	echo <<<EOF
Create MAV tables in Moodle DB

Options:
--dry-run                   Print what the script will do, but not do it
--debug                     Print debugging information while script running
                            (normally silent)
--help	                    Print out this help
--update=<section name>     Update the DB for the moodle install with the
                            settings given in <section name>

Example:
/usr/bin/php $argv[0] [--debug] [--help] [--dry-run] --update=<section name>

EOF;
	exit($code) ;
}

//MAV Setup
require_once('clignition.php') ;
$ignition = new clignition(false) ;
require_once('PDOdatabase.php') ;

//Class for parsing MAV ini configuration file
require_once('MavConfig.php') ;

$options = $ignition->getCmdLineOptions
(
	array
	(
		'help' => false, //Not given by default
		'debug' => false, //Not given by default
		'dry-run' => false, //Not given by default
		'update:' => false //No default (but must be provided)
	)
) ;

if ($options['help'])
	help() ;

if($options['update'] === false)
{
	error_log("ERROR: No --update option provided") ;
	help(1) ;
}

//Get our configuration options based on --update option
$mavConfig = $ignition->getAppConfig() ; //Parse config file
$mav_config = $mavConfig->getConfigSection($options['update']) ;//Select section


//If there is no pdodatabase setting, which points to the section in the
//(default: etc/database.ini) file with connection options to the DB, then barf
if(!isset($mav_config['pdodatabase']))
	throw new Exception("No pdodatabase option given in the $databaseToUpdate section of the mav.ini file",1) ;

/**
 * @var array Connection settings for the Moodle DB based on mav configuration
 */
$DBSETTINGS = $mav_config['pdodatabase'] ;


/**
 * @var PDOdatabase Object so can connect to moodle 2.7
 */
$PDOdatabase = new PDOdatabase('DBCONF') ;

/**
 * @var PDO Connection object to the Moodle 2.7 DB
 */
$pdo = $PDOdatabase->connectPDO($DBSETTINGS) ;
//If something goes wrong, throw an exception
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION) ;

/**
 * @var string The Moodle DB table prefix value from Moodle config
 */
$DBPREFIX = $mav_config['dbprefix'] ;


$adapter = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ;

//@todo create templates for this

$createMAVTables = array
(
	'pgsql' => array
	(
		"table_summary" =>
		"
--This table stores the aggregate student activity from the
--logstore_standard_log table
CREATE TABLE if not exists {$mav_config['table_summary']}
(
	courseid  bigint            NOT NULL,
	url       varchar(10000)    NOT NULL,
	userid    bigint            NOT NULL,
	component    varchar(100)      NOT NULL,
	clicks    bigint            DEFAULT 1
) ;
		",
		"table_summary_index" =>
		"
--This pgsql routine checks whether an index exists on the summary table
--and if not, creates one
--http://dba.stackexchange.com/questions/35616/create-index-if-it-does-not-exist
DO $$
BEGIN
IF NOT EXISTS
(
	SELECT 1
	FROM   pg_class c
	JOIN   pg_namespace n ON n.oid = c.relnamespace
	WHERE  c.relname = '${mav_config['table_summary_index']}'
)
THEN
CREATE UNIQUE INDEX ${mav_config['table_summary_index']}
ON {$mav_config['table_summary']}(url, courseid, userid, component) ;
END IF;

END$$;
		",
		"table_update" =>
		"
--This table stores the aggregate student activity from the
--logstore_standard_log table
CREATE TABLE if not exists {$mav_config['table_update']}
(
	courseid  bigint            NOT NULL,
	url       varchar(10000)    NOT NULL,
	userid    bigint            NOT NULL,
	component    varchar(100)      NOT NULL,
	clicks    bigint            DEFAULT 1
) ;
		",
		"table_update_index" =>
		"
--This pgsql routine checks whether an index exists on the summary table
--and if not, creates one
--http://dba.stackexchange.com/questions/35616/create-index-if-it-does-not-exist
DO $$
BEGIN
IF NOT EXISTS
(
	SELECT 1
	FROM   pg_class c
	JOIN   pg_namespace n ON n.oid = c.relnamespace
	WHERE  c.relname = '${mav_config['table_update_index']}'
)
THEN
CREATE UNIQUE INDEX ${mav_config['table_update_index']}
ON {$mav_config['table_update']}(url, courseid, userid, component) ;
END IF;

END$$;
		",
		"table_state" =>
		"
--This table stores the id field for every row from the
--mdl_logstore_standard_log table that has been included into the
--mdl_logstore_standard_log_summary table
CREATE TABLE if not exists {$mav_config['table_state']}
(
	id bigint primary key
) ;
		",
		"table_batch" =>
		"
CREATE TABLE if not exists {$mav_config['table_batch']}
(
	id bigint primary key
) ;
		"
	)
) ;

echo "Using adapter $adapter\n" ;

$pdo->beginTransaction() ;
foreach($createMAVTables[$adapter] as $table => $sql)
{
	echo "Creating $table\n" ;
	//If an error occurs, PDO has been instructed to throw an exception
	if($options['dry-run'])
	{
		echo "Would Execute:\n" ;
		echo implode("\n",preg_replace('/--.*$/','',explode("\n",$sql))) . "\n" ;
		echo str_repeat('-',80) . "\n" ;
	}
	else
	{
		if($options['debug'])
			echo "Executing: $sql\n" ;
		$result = $pdo->exec($sql) ;
		if($result === false)
		{
			echo implode("\n",$pdo->errorInfo()) . "\n" ;
			exit(1) ;
		}
		echo str_repeat('-',80) . "\n" ;
	}
}
$pdo->commit() ;
	
?>
