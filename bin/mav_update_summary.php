<?php

/**
 * This script will update the MAV summary table (default:
 * mdl_logstore_standard_log_summary) based on changes that have been written
 * to the {mdl}_logstore_standard_log table since the last running of this script
 *
 * The config file for MAV (default: etc/mav.ini) contains a separate section
 * for each Moodle install (and matching database) that MAV is required to work
 * with.  View the mav.ini.sample file for examples.
 *
 * This script must be passed a section name for the Moodle install/database
 * the script is to update via the --update option.
 *
 * Usage:
 * php update_db_summary.php [--help] [--debug] [--jobs=1] --update=<SECTIONNAME>
 * 
 */

function help($code=0)
{
	global $argv ;
	echo <<<EOF
Usage:
php $argv[0] [--help] [--debug] [--jobs=1] [--progress] [--purge]
    [--rows-per-job=10000] --update=<SECTIONNAME>

Update MAV tables according to activity in moodle activity logs

Options:
--debug         Print extra debugging information while script running 
--help	        Print out this help
--jobs          Number of concurrent processes to aggregate the click activity
                data (default: 1)
--rows-per-job  How many rows from log to process per job (default: 10000)
--update        The section name from the mav.ini config file from which to
                the settings for the Moodle install to be updated (mandatory)
--progress      Output progress markers as each job completes its rows
--purge         Delete all existing MAV data and regenerate anew (this can take)
                a substantial amount of time with a large log table
--list          List the sections in the mav.ini configuration file for all
                the configured Moodle installs

Example:
/usr/bin/php $argv[0] --debug --jobs=2 --update=MOODLE

This would run in debug mode, using 2 concurrent update tasks using the
configuration under the MOODLE section name in the etc/mav.ini configuration
file

EOF;
	exit($code) ;
}

////////////////////////////////////////////////////////////////////////////////
//MAV Setup
////////////////////////////////////////////////////////////////////////////////

require_once('clignition.php') ;
$ignition = new clignition(false) ;
require_once('PDOdatabase.php') ;

//Use multiprocessing library
use Arara\Process\Child ;
use Arara\Process\Control ;
use Arara\Process\Pool ;
use Arara\Process\Action\Callback ;
use Arara\Process\Action\Action ;
use Arara\Process\Control\Status ;

//Multitasking action class to do the counting
require_once('UpdateAction.php') ;

//Function for loading moodle config into memory
require_once('require_moodle.php') ;

//Class for parsing MAV ini configuration file
require_once('MavConfig.php') ;

////////////////////////////////////////////////////////////////////////////////
//Script setup
////////////////////////////////////////////////////////////////////////////////

$options = $ignition->getCmdLineOptions
(
	array
	(
		'help' => false, //Not given by default
		'debug' => false, //Not given by default
		'jobs:' => 1, //Default to running only 1 concurrent calculation jobs
		'rows-per-job:' => 10000, //Default number rows for each child process
		'progress' => false, //Output progress markers (not given by default)
		'update:' => false, //No default (but must be provided)
		'purge' => false,
		'list' => false
	)
) ;

if ($options['help'])
	help() ;

if($options['update'] === false and !$options['list'])
{
	error_log("ERROR: No --update option provided") ;
	help(1) ;
}

//Get our configuration options based on --update option
/**
 * @var MavConfig ignition_app_config object for Mav
 */
$mavConfig = $ignition->getAppConfig() ; //Parse config file

if($options['list'])
{
	//Output mav.ini configuration section headings
	$sections = $mavConfig->getConfigSectionNames() ;
	foreach($sections as $section)
	{
		echo "[{$section}]\n" ;
		echo "  moodle_home_url: " . $mavConfig->getConfigValue($section,'moodle_home_url') . "\n" ;
		echo "  pdodatabase: " . $mavConfig->getConfigValue($section,'pdodatabase') . "\n\n" ;
	}
	exit(0) ;
}


$mav_config = $mavConfig->getConfigSection($options['update']) ;//Select section

/**
 * @var PDOdatabase Object so can connect to Moodle DB.
 */
$PDOdatabase = new PDOdatabase('DBCONF') ;

//If there is no pdodatabase setting, which points to the section in the
//(default: etc/database.ini) file with connection options to the DB, then barf
if(!isset($mav_config['pdodatabase']))
	throw new Exception("No pdodatabase option given in the $databaseToUpdate section of the mav.ini file",1) ;

/**
 * @var array Connection settings for the Moodle DB based on mav configuration
 */
$DBSETTINGS = $mav_config['pdodatabase'] ;

/**
 * @var string The Moodle DB table prefix value from Moodle config
 */
$DBPREFIX = $mav_config['dbprefix'] ;

/**
 * @var PDO Connection object to the Moodle 2.7 DB
 */
$pdo = $PDOdatabase->connectPDO($DBSETTINGS) ;
//If something goes wrong, throw an exception
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION) ;

	
///////////////////////////////////////////////////////////////////////////////
//Let's generate the batch table with all the new events to be added to summary
///////////////////////////////////////////////////////////////////////////////

outputLine('=') ;
echo "Identifying which events need to be added since last run\n" ;
outputLine('=') ;
$pdo->beginTransaction() ;

if($options['debug'])
	echo "Deleting contents of batch table from last run: " ;

//Delete batch table contents
if(UpdateAction::deleteTableContents($pdo,$mav_config['table_batch']) === false)
	throw new Exception(join("\n",$pdo->errorInfo()),1) ;

if($options['debug'])
	echo "Done\n\n" ;

//Update batch table with events to add to summary
//If --purge option given, this tells createBatchContents to ignore the
//state table, so that all events in the log are added (creating summary anew)
$countEvent = createBatchContents($pdo,$mav_config,$DBPREFIX,$options['purge']) ;

outputLine('-',1) ;

echo "Looking up event counts for courses: " ;

//Lets see how many events per course

/**
 * @var array eg. array(courseid => numevents, ...)
 */
$courseEvents = UpdateAction::getEventsPerCourse($pdo,$mav_config,$DBPREFIX) ;
$courseCount = count($courseEvents) ;

echo "$courseCount courses\n\n" ;

outputLine('-',1) ;

//Need to purge the contents of the build table (ready to be updated again
echo "Purging the summary build table: {$mav_config['table_update']}: " ;

if(UpdateAction::deleteTableContents($pdo,$mav_config['table_update']) === false)
{
	echo "\n\n" ;
	throw new Exception(join("\n",$pdo->errorInfo()),1) ;
}

echo "Done\n\n" ;

//If this run is not a purge, then copy our existing summary table to be updated
if(!$options['purge'])
{
	outputLine('-',1) ;
	//Copy over what is in the summary table into build table, so it can be updated
	echo "Copying summary table: {$mav_config['table_summary']} into build table: " ;
	
	$summaryUpdateCount = UpdateAction::copyTableContents($pdo,$mav_config['table_summary'],$mav_config['table_update']) ;
	if($summaryUpdateCount === false)
	{
		echo "\n\n" ;
		throw new Exception(join("\n",$pdo->errorInfo()),1) ;
	}
	
	echo "Done\n\n" ;
	
	echo "Summary update table repopulated with $summaryUpdateCount rows ready to be updated\n\n" ;


}

//Now that we have our batch of events to import, let's commit it to the DB
//so that the separate Moodle API DB connection from child processes can
//see it to generate our event objects to insert into our summary_build table
$pdo->commit() ;

/////////////////////////////////////////////////////////////////////////////
//Using the batch table, lets update the summary build table using child processes	
/////////////////////////////////////////////////////////////////////////////

$control = new Control() ;
$pool = new Pool($options['jobs']) ; //Pool will only allow jobs children

outputLine('=') ;
echo "Starting the processing pool with {$options['jobs']} processes\n" ;
outputLine('=') ;

//Start the pool so it can execute children processes
$pool->start() ;

/**
 * @var array Keep a copy of all the children as they are added to the pool
 */
$children = array() ;

$limitnum = $options['rows-per-job'] ;

echo "Number of rows per job: $limitnum\n\n" ;

//Assign courses to Actions (jobs) up to limitnum events each
while(count($courseEvents) > 0)
{
	//Get the top course (remaining highest number of events)
	//There has to be at least one course in this job
	reset($courseEvents) ;
	list($courseid,$count) = each($courseEvents) ;
	//Assign it to this new job
	$job = array
	(
		$courseid => $count
	) ;
	$total = $count ;
	//Remove it from the list (there has to be at least one course in this job)
	unset($courseEvents[$courseid]) ;
	
	//Now search for more courses to add provided the event count doesnt exceed
	//limitnum
	foreach($courseEvents as $courseid => $count)
	{
		//If $courseid would exceed limit, try next one
		if($total + $count > $limitnum)
			continue ;
		
		//If not, add it to this job & update total
		$job[$courseid] = $count ;
		$total += $count ;
	}

	//Now remove from the courseEvents list all courses added to this job
	foreach($job as $courseid => $count)
	{
		unset($courseEvents[$courseid]) ;
	}

	//Create child process & action to execute this job
	$child = new Child(new UpdateAction($mav_config,$limitnum,$job),$control) ;
	$children[] = $child ;
	
	if($options['debug'] or $options['progress'])
		echo "Starting job for courses (courseids: " . join(',',array_keys($job)) . ") with $total events\n\n" ;
	
	if($options['debug'])
		echo count($courseEvents) . " courses remaining\n\n" ;

	//Away it goes
	$pool->attach($child) ;
}
//Once all jobs allocated to children, wait for them to finish
$pool->wait() ;

//Go through child processes and check to see if they were all okay
$okay = true ;
foreach($children as $child)
{
	if(!$child->getStatus()->isSuccessful())
	{
		$okay = false ;
		break ;
	}
}

//If one or more children had non-zero exit status, then stop
if(!$okay)
	throw new Exception("Error in one or more of the children. No changes to mav tables\n",1) ;

outputLine('-',1) ;
echo "All processing jobs completed successfully\n\n" ;

//@todo Add all sql queries as query templates and remove literals from script


////////////////////////////////////////////////////////////////////////////////
//Final tidy up and put new data into place
////////////////////////////////////////////////////////////////////////////////


//Reconnect to DB
$PDOdatabase = new PDOdatabase('DBCONF') ;

//Begin transaction to insert into the summary table (with new connection)
$pdo = $PDOdatabase->connectPDO($DBSETTINGS) ;

$pdo->beginTransaction() ;

outputLine('=') ;
echo "Moving newly updated tables into place\n" ;
outputLine('=') ;

//If told to purge, then we should delete contents of state table now (as can
//still be rolled back if something happens in this transaction)
if($options['purge'])
{
	echo "Purging contents of state table {$mav_config['table_state']} (--purge): \n" ;
	if(UpdateAction::deleteTableContents($pdo,$mav_config['table_state']) === false)
		throw new Exception(join("\n",$pdo->errorInfo()),1) ;

	echo "Done\n" ;	
	outputLine('-',1) ;
}

//Finally update the state table to include all the log id that were imported
//in this batch
echo "Updating state table {$mav_config['table_state']} with newly updated events: " ;

$rows = UpdateAction::copyTableContents($pdo,$mav_config['table_batch'],$mav_config['table_state']) ;

echo "$rows events updated\n\n" ;

outputLine('-',1) ;

echo "Moving build table {$mav_config['table_update']} into summary table {$mav_config['table_summary']}: " ;

//Now rename our newly updated table into place
if(UpdateAction::renameTable($pdo,$mav_config['table_summary'],"{$mav_config['table_summary']}_1") === false)
	throw new Exception(join("\n",$pdo->errorInfo()),1) ;

if(UpdateAction::renameTable($pdo,$mav_config['table_update'],$mav_config['table_summary']) === false)
	throw new Exception(join("\n",$pdo->errorInfo()),1) ;
	
if(UpdateAction::renameTable($pdo,"{$mav_config['table_summary']}_1",$mav_config['table_update']) === false)
	throw new Exception(join("\n",$pdo->errorInfo()),1) ;

echo "Done\n\n" ;

//If we made it to here, all good so let's commit
$pdo->commit() ;

outputLine('-',1) ;

echo "Renamed {$mav_config['table_update']} to {$mav_config['table_summary']}\n" ;

outputLine('=') ;
echo "Update completed successfully!!\n" ;
outputLine('=') ;

/**
 * This function will populate the batch table with the latest event ids from
 * the standard_log that need to be updated in the MAV summary table
 * 
 * @param PDO $pdo        Database connection object
 * @param array $mav_config The mav configuration information for this run
 * @param string $DBPREFIX The prefix for tables in Moodle DB
 * 
 * @return integer    Number of events added to the batch table
 */
function createBatchContents($pdo,$mav_config,$DBPREFIX,$purge=false)
{
	$insertBatchSql =<<<EOF
	insert into {$mav_config['table_batch']} 
	select distinct l.id
	from {$DBPREFIX}logstore_standard_log l, {$DBPREFIX}role r, {$DBPREFIX}role_assignments ra, {$DBPREFIX}context con
	where
	con.contextlevel = 50
	and con.id = ra.contextid
	and r.id = ra.roleid
	and l.crud = 'r'
	and ra.userid = l.userid
	and r.archetype = 'student'
	and con.instanceid = l.courseid
EOF;

  //If purge option not given, then limit batch to event ids in the state table
  if(!$purge)
	$insertBatchSql .=
	"
	--Dont import events that have already been imported
	and not exists
	(
		select id from {$mav_config['table_state']} c
		where l.id = c.id
	)
	;
	" ;
	
	echo "Identifying new events to add\n" ;
	
	$countEvent = UpdateAction::copyTableContents($pdo,null,null,$insertBatchSql) ;
	
	if($countEvent === false)
		throw new Exception(join("\n",$pdo->errorInfo()),1) ;
	
	echo "Events to be imported: $countEvent\n\n" ;
	
	if($countEvent === 0)
	{
		echo "No new events to add to MAV tables.. Terminating\n" ;
		exit(0) ;
	}

	return $countEvent ;
}


/**
 * This function outputs a line of $str characters 80 columns wide across
 * the screen on standard output
 * 
 * @param string $str String to use as line (default -)
 * @param integer $cr The number of carriage returns placed before & after line
 * 
 */
function outputLine($str='-',$cr=0)
{
	if(!isset($str) or strlen($str) === 0)
		$str = '-' ;
	$r = intval(80/strlen($str)) ;
	if(!is_numeric($cr))
		$cr = 0 ;

	echo str_repeat("\n",$cr) ;
	echo str_repeat($str,$r) . "\n" ;
	echo str_repeat("\n",$cr) ;
}

?>
