<?php

include_once('ignition.php') ;

//Use ignition
$ignition = new ignition() ;

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

error_log("courseid=$courseid") ;

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

error_log('courselink='.$input['courselink']) ;

////////////////////////////////////////////////////////////////////////////////
// Get number of students enrolled in course
////////////////////////////////////////////////////////////////////////////////
$select =
"
	select id,name from {$DBPREFIX}groups
	where courseid = :course
" ;

$stmt = $pdo->prepare($select) ;
$stmt->execute(array(':course'=>$courseid)) ;

//Return as
//array
//(
//	id => groupname,
//	id2 => groupname2,
//	...
//)
$groups = array() ;

while($row = $stmt->fetch(PDO::FETCH_NUM))
{
	$groups[$row[0]] = $row[1] ;
}

//Sort them sensibly like a human would (natural sort)
natsort($groups) ;

$output['data'] = $groups ;

$smarty = new ignition_Smarty() ;

$smarty->assign('groups',$groups) ;

$output['html'] = $smarty->fetch('getCourseGroups.tpl') ;

header('Content-Type: application/json');
echo json_encode($output) ;

error_log('output='.json_encode($output)) ;

?>
