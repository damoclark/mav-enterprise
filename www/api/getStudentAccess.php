<?php

include_once('ignition.php') ;

//Use ignition
$ignition = new ignition() ;

//Get our utilities library
include_once('mav_lib.php') ;

//Get our database connection helper
include_once('PDOdatabase.php') ;

//Class for parsing MAV ini configuration file
require_once('MavConfig.php') ;


$input = json_decode($_REQUEST['json'],true) ;


if(!isset($input['moodlehomeurl']))
{
	//@todo Need to write an error message to user here
	throw new Exception("No moodlehomeurl provided by browser",1) ;
}
//Get the url to the home page of moodle itself from browser to know which
//moodle they are using
$selectedMoodle = $input['moodlehomeurl'] ;

//Get the courseid from browser
$courseid = $input['courseid'] ;
if($courseid == null)
{
	throw new Exception("No course code provided by user",1) ;
}

if(getenv('DEBUG'))
{
	error_log("getActivity: courseid=$courseid") ;
	error_log("getActivity: settings\n".$input['settings']) ;
  error_log('getActivity: courselink='.$input['courselink']) ;
}


//Get our configuration options based on moodlehomeurl from browser
$mavConfig = $ignition->getAppConfig() ; //Parse config file
try
{
	$mav_config = $mavConfig->getConfigSectionByMoodleUrl($selectedMoodle) ;//Select section
}
catch(Exception $e)
{
	throw $e ;
}

$DBPREFIX = $mav_config['dbprefix'] ;
	
/**
 * @var PDOdatabase Object so can connect to Moodle DB.
 */
$PDOdatabase = new PDOdatabase('DBCONF') ;

/**
 * @var array Connection settings for the Moodle DB based on mav configuration
 */
$DBSETTINGS = $mav_config['pdodatabase'] ;

/**
 * @var PDO Connection object to the Moodle 2.7 DB
 */
$pdo = $PDOdatabase->connectPDO($DBSETTINGS) ;
//If something goes wrong, throw an exception
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION) ;


	
////////////////////////////////////////////////////////////////////////////////
//Get the stats from the database as per input
////////////////////////////////////////////////////////////////////////////////

//Prepare SQL queries
$accessTemplate = new ignition_Smarty() ;
$noAccessTemplate = new ignition_Smarty() ;

//Get all the config options that start with table_ and turn into array omitting table_ in the key
$tableNames = $mavConfig->getTableNamesAsArrayByMoodleUrl($selectedMoodle) ;

$accessTemplate->assign('table',$tableNames) ;
$accessTemplate->assign('dbprefix',$mav_config['dbprefix']) ;
$noAccessTemplate->assign('table',$tableNames) ;
$noAccessTemplate->assign('dbprefix',$mav_config['dbprefix']) ;

//Fetch freshly baked queries
$accessQuery = $accessTemplate->fetch("queries/{$mav_config['moodle_logging']}/getStudentAccessQuery.tpl") ;
$noAccessQuery = $noAccessTemplate->fetch("queries/{$mav_config['moodle_logging']}/getStudentNoAccessQuery.tpl") ;

//Prepare access query
$accessStmt = $pdo->prepare($accessQuery) ;

//Prepare no access query
$noAccessStmt = $pdo->prepare($noAccessQuery) ;
	
if(getenv('DEBUG'))
	error_log("getStudentAccess.php access query=\n$accessQuery") ;

if(getenv('DEBUG'))
	error_log("getStudentAccess.php no access query=\n$noAccessQuery") ;
	
foreach($input['links'] as $link)
{
	//List of students who have accessed link
	$studentAccess = array() ;
	//List of students who haven't accessed link
	$studentNoAccess = array() ;

	//Broken down elements of the $link
	$data = array() ;
	
	//If operating on a legacy mdl_log table
	if($mav_config['moodle_logging'] === 'legacy')
	{
		$l = processLegacyLoggingLink($link) ;
		$module = $l['module'] ;
		$url = $l['url'] ;
		
		//Get the student access list first
		$queryParams = array(':module'=>$module,':url'=>$url,':courseid'=>$courseid) ;
		
		if(getenv('DEBUG'))
			error_log("module=$module, url=$url, course=$courseid") ;
	}
	else //Then operating on a mdl_logstore_standard_log
	{
		$queryParams = array(':url' => $link, ':courseid' => $courseid) ;

		if(getenv('DEBUG'))
			error_log("url=$link, course=$courseid") ;
	}
	
	//@todo Provide an error to the user
	if(!$accessStmt->execute($queryParams))
		throw new Exception("Error executing query=$accessQuery: with query parameters: ".var_export($queryParams,true)." with error: " . join(',',$pdo->errorInfo())) ;

	//Collate the list of students who have accessed the link $link
	while($result = $accessStmt->fetch(PDO::FETCH_ASSOC))
	{
		$studentAccess[] = $result ;
	}
	
	//Now get the student not access list
	//@todo Provide an error to the user
	if(!$noAccessStmt->execute($queryParams))
		throw new Exception("Error executing query=$noAccessQuery: with query parameters: ".var_export($queryParams,true)." with error: " . join(',',$pdo->errorInfo())) ;

	//Collate the list of students who have accessed the link $link
	while($result = $noAccessStmt->fetch(PDO::FETCH_ASSOC))
	{
		$studentNoAccess[] = $result ;
	}
	
	//Add the counted students to the input data structure so it can be logged
	$input['links'][$link][] = count($studentAccess) ;
	$input['links'][$link][] = count($studentNoAccess) ;

	//Assign the counted students to the output data structure
	$output[$link]['access'] = $studentAccess ;
	$output[$link]['noaccess'] = $studentNoAccess ;
}

//Log request
$input['username'] = $ignition->getAuth()->getUsername() ;
$now = new DateTime() ;
$input['timestamp'] = $now->format('U') ;
//Add the action for this record in the log
$input['action'] = 'getStudentAccess' ;
file_put_contents
(
	getenv('APPDIR').'/log/getActivity.txt',
	json_encode
	(
		$input,
		JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT
	)."\n", //Append a newline
	FILE_APPEND|LOCK_EX
) ;

$output = array('data' => $output) ;
$output['settings'] = $input['settings'] ;

header('Content-Type: application/json');

echo json_encode($output) ;

if(getenv('DEBUG'))
	error_log('output='.json_encode($output)) ;

/*
 Sample output

{
	"data": {
		"\/mod\/elluminate\/view.php?id=2013": {
			"access": [{
					"username": "s123456",
					"firstname": "Gerry",
					"lastname": "Laws"
				}, {
					"username": "s234567",
					"firstname": "Charlotte",
					"lastname": "Li"
				}, {
					"username": "s345789",
					"firstname": "Billy",
					"lastname": "Stubbs"
				}
			],
			"noaccess": [{
					"username": "s456789",
					"firstname": "Shelly",
					"lastname": "Allens"
				}, {
					"username": "s567890",
					"firstname": "Helen",
					"lastname": "Albert"
				}, {
					"username": "s6789012",
					"firstname": "Mitch",
					"lastname": "Fletcher"
				}, {
					"username": "s7890123",
					"firstname": "Margaret",
					"lastname": "Ford"
				}, {
					"username": "s8901234",
					"firstname": "Yolanda",
					"lastname": "Harrison"
				}, {
					"username": "s9012345",
					"firstname": "Billy",
					"lastname": "Howardstone"
				}, {
					"username": "s012345",
					"firstname": "Terrance",
					"lastname": "Youngberry"
				}
			]
		}
	},
	"settings": {
		"activityType": "S",
		"displayMode": "C",
		"groups": ["0"],
		"mavVersion": "0.4.4"
	}
}	
*/

/*
 Sample log output

 The links structure has 2 numbers in the array.  The first number is a count of
 the number of students who have accessed that link, while the second number is
 a count of students who haven't access the link.
 
{
	"mavVersion": "0.6.4-dev99990",
	"settings": {
		"activityType": "S",
		"displayMode": "C",
		"groups": ["0"],
		"mavVersion": "0.6.4-dev9992"
	},
	"courselink": "http:\/\/lms.server.com\/course\/view.php?id=263822",
	"courseid": "263822",
	"pagelink": "http:\/\/lms.server.com\/mod\/book\/view.php?id=166\u0026chapterid=6610",
	"links": {
		"\/mod\/book\/view.php?id=1636\u0026chapterid=6674"
	},
	"username": "nerkf",
	"timestamp": "1402372428",
	"action": "getStudentAccess"
} 
*/

?>
