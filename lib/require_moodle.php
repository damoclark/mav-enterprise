<?php

/**
 * This php file is to be 'require'd into cli scripts that need to interact
 * with Moodle DB via Moodle API
 *
 * It sets 3 global variables: $CFG, $DB & $USER
 */

/**
 * This function 'requires' the Moodle config into PHP process
 * 
 * @param string $moodle Install Directory where the Moodle config.php file located
 * 
 * @return array    array($CFG,$DB,$USER)
 */
function require_moodle($moodleInstall=null)
{
	if($moodleInstall === null)
		throw new Exception("No moodleInstall value given",1) ;

	define('CLI_SCRIPT', true);
	require(rtrim($moodleInstall,'/').'/config.php') ;
	require("$CFG->libdir/clilib.php");
	require("$CFG->libdir/adminlib.php");
	
	return array($CFG,$DB,$USER) ;
}


?>
