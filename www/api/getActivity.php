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
	throw new Exception("No moodlehomeurl provided by browser",1) ;

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



$output = array() ;


////////////////////////////////////////////////////////////////////////////////
// Get number of students enrolled in course
////////////////////////////////////////////////////////////////////////////////
$select =
"
	select count(u.id) as counter from {$DBPREFIX}role r,{$DBPREFIX}role_assignments ra,{$DBPREFIX}context con,{$DBPREFIX}user u, {$DBPREFIX}course c
	where
	con.contextlevel = 50
	and con.id = ra.contextid
	and r.id = ra.roleid
	and ra.userid = u.id
	and r.archetype = 'student'
	and con.instanceid = c.id
  and c.id = :courseid
" ;

$stmt = $pdo->prepare($select) ;
$stmt->execute(array(':courseid'=>$courseid)) ;

$row = $stmt->fetch(PDO::FETCH_ASSOC) ;
if($row != null)
	$studentCount = $row['counter'] ;

	
////////////////////////////////////////////////////////////////////////////////
//Get the stats from the database as per input
////////////////////////////////////////////////////////////////////////////////

////////////////////////////////////////////////////////////////////////////////
//Collate data to be inserted into the php query template
////////////////////////////////////////////////////////////////////////////////
$queryData = array() ;

//Get the activityType setting
$queryData['activityType'] = $input['settings']['activityType'] ;
//If not a valid value of C or S, make it C
if($queryData['activityType'] != 'C' and $queryData['activityType'] != 'S')
	$queryData['activityType'] = 'C';

//Get the specific student to query (if specified)	
if(array_key_exists('student',$input['settings']) and $input['settings']['student'])
{
	error_log("Getting activity for specific student ".$queryData['selectedStudent']) ;
	$queryData['selectedStudent'] = strtolower($input['settings']['student']) ;
}
//Else, instead some groups might have been specified (groups == 0 means no groups)
elseif($input['settings']['groups'][0] != 0) //If there are groups specified add to query
{
	$queryData['selectedGroups'] = $input['settings']['groups'] ;

	//Check all values for selectedGroups are numbers
	foreach($queryData['selectedGroups'] as $group)
	{
		if(!is_numeric($group))
			throw new Exception("selectedGroups value from json data contains a non-numeric: $group",1) ;
	}
}

$queryTemplate = new ignition_Smarty() ;

//Get all the config options that start with table_ and turn into array omitting table_ in the key
$tableNames = $mavConfig->getTableNamesAsArrayByMoodleUrl($selectedMoodle) ;

$queryTemplate->assign('table',$tableNames) ;
$queryTemplate->assign('dbprefix',$DBPREFIX) ;

$queryTemplate->assign($queryData) ;
$query = $queryTemplate->fetch("queries/{$mav_config['moodle_logging']}/getActivityQuery.tpl") ;

if(getenv('DEBUG'))
	error_log("getActivity.php query=\n$query") ;

//Run query
$stmt = $pdo->prepare($query) ;

foreach($input['links'] as $link)
{
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
	if(!$stmt->execute($queryParams))
		throw new Exception("Error executing query=$query: with query parameters: ".var_export($queryParams,true)." with error: " . join(',',$pdo->errorInfo())) ;
	
	$result = $stmt->fetch(PDO::FETCH_NUM) ;
	
	$result = $result[0] ;
	//If querying clicks, query uses sum function which returns null if there
	//are no rows returned to sum, so detect this and set to 0
	if($result === null)
		$result = 0 ;
	
	if(getenv('DEBUG'))
	{
		error_log("module=$module, url=$url, course=$courseid, count=".$result) ;
	}

	//Update input data structure (so can be added to activity log)
	$input['links'][$link][] = $result ;
	
	//Update output data structure
	$output[$link] = $result ;
}

//Log request to activity log
$input['username'] = $ignition->getAuth()->getUsername() ;
$now = new DateTime() ;
$input['timestamp'] = $now->format('U') ;
//Add the action for this record in the log
$input['action'] = 'getActivity' ;
file_put_contents
(
	$ignition->getenv('APPDIR').'/log/getActivity.txt',
	json_encode
	(
		$input,
		JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT
	)."\n", //Append a newline
	FILE_APPEND|LOCK_EX
) ;

$output = array('data' => $output) ;
$output['studentCount'] = $studentCount ;
$output['settings'] = $input['settings'] ;

header('Content-Type: application/json');

echo json_encode($output) ;

if(getenv('DEBUG'))
	error_log('output='.json_encode($output)) ;

//Logging output format
/*
 The numbers in the links structure is the result from the database calculation
 (ie. either number of clicks for that link or number of students, depending on
 whether settings['activityType'] == 'S' or 'C')
 
{
	"mavVersion": "0.6.4-dev99990",
	"settings": {
		"activityType": "S",
		"displayMode": "C",
		"groups": ["0"],
		"mavVersion": "0.6.4-dev9992"
	},
	"courselink": "http:\/\/lms.server.com\/course\/view.php?id=2242",
	"courseid": "2242",
	"pagelink": "http:\/\/lms.server.com\/course\/view.php?id=2242",
	"links": {
		"\/course\/view.php?id=22287",
		"\/mod\/elluminate\/view.php?id=2013931",
		"\/mod\/forum\/view.php?id=749842",
		"\/mod\/page\/view.php?id=1666753",
		"\/mod\/page\/view.php?id=1922603",
		"\/mod\/forum\/view.php?f=365802",
		"\/mod\/book\/view.php?id=1960001",
		"\/mod\/book\/view.php?id=1707164",
		"\/mod\/resource\/view.php?id=2051417",
		"\/mod\/resource\/view.php?id=2071418",
		"\/mod\/resource\/view.php?id=1765407",
		"\/mod\/resource\/view.php?id=1766508",
		"\/mod\/resource\/view.php?id=1761509",
		"\/mod\/book\/view.php?id=1636616\u0026chapterid=6610",
		"\/mod\/forum\/view.php?id=1713655",
		"\/mod\/elluminate\/view.php?id=1636619",
		"\/mod\/book\/view.php?id=1633666\u0026chapterid=6611",
		"\/mod\/forum\/view.php?id=1664661",
		"\/mod\/elluminate\/view.php?id=1766648",
		"\/mod\/book\/view.php?id=1638666\u0026chapterid=6612",
		"\/mod\/book\/view.php?id=1653666\u0026chapterid=6613",
		"\/mod\/forum\/view.php?id=1641663",
		"\/mod\/resource\/view.php?id=2201429",
		"\/mod\/resource\/view.php?id=2013430",
		"\/mod\/elluminate\/view.php?id=1954516",
		"\/mod\/elluminate\/view.php?id=1676650",
		"\/mod\/resource\/view.php?id=2047892",
		"\/mod\/book\/view.php?id=16366\u0026chapterid=6614",
		"\/mod\/forum\/view.php?id=17664",
		"\/mod\/elluminate\/view.php?id=16662",
		"\/mod\/forum\/view.php?id=7491",
		"\/mod\/forum\/view.php?id=7482",
		"\/mod\/page\/view.php?id=7483",
		"\/mod\/book\/view.php?id=16366",
		"\/mod\/forum\/view.php?id=16662",
		"\/mod\/assignment\/view.php?id=67810",
		"\/mod\/assignment\/view.php?id=16812",
		"\/mod\/assignment\/view.php?id=17947",
		"\/mod\/resource\/view.php?id=16994",
		"\/mod\/resource\/view.php?id=17995",
		"\/mod\/resource\/view.php?id=16796",
		"\/mod\/resource\/view.php?id=20412",
		"\/mod\/resource\/view.php?id=20145",
		"\/mod\/elluminate\/view.php?id=22979",
		"\/mod\/book\/view.php?id=8947\u0026chapterid=3978",
		"\/mod\/forum\/post.php?forum=1058",
		"\/mod\/forum\/discuss.php?d=11145",
		"\/mod\/forum\/discuss.php?d=11104",
		"\/mod\/forum\/discuss.php?d=19438",
		"\/mod\/forum\/discuss.php?d=10794",
		"\/mod\/forum\/discuss.php?d=10647",
		"\/mod\/forum\/view.php?f=1098",
		"\/mod\/assignment\/index.php?id=12242",
		"\/mod\/elluminate\/index.php?id=22242",
		"\/mod\/forum\/index.php?id=22452",
		"\/mod\/forum\/view.php?id=2222",
		"\/mod\/forum\/user.php?id=212",
		"\/mod\/forum\/user.php?id=212\u0026mode=discussions",
		"\/mod\/forum\/user.php?id=2121\u0026course=22222",
		"\/mod\/forum\/user.php?id=2121\u0026course=22222\u0026mode=discussions",
	},
	"username": "nerkf",
	"timestamp": "1402372419",
	"action": "getActivity"
}
*/
	
?>