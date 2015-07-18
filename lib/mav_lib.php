<?php

/**
 * Split Moodle link into its components
 * 
 * @param string $link Document path of Moodle url (missing scheme, host, port etc)
 * 
 * @return array    An associative array with the keys 'module' and 'url' in that order
 */
function processLegacyLoggingLink($link)
{
	//Filter out links that aren't for modules or the moodle home page according to RE from balmi.user.js getMoodleLinks method before update for MAV2
	if(!preg_match('/^\/mod\/([^\/]+)\/([^\/]+)$/',$link,$data) and
	!preg_match('/^\/(course)\/(view\.php\?.*)$/',$link,$data))
		continue ;
	
	//Throw away first match from preg_match
	array_shift($data) ;

	$output = array() ;
	
	$output['module'] = $data[0] ;

	//If the module is glossary, then check if its the link to a particular
	//glossary (eg view.php), and if so, add &tab=-1 on end as this is what
	//appears in the logs, even though its not in the links on the page
	if($output['module'] == 'glossary' and preg_match('/view\.php\?id=\d+$/',$data[1]))
		$output['url'] = $data[1].'&tab=-1' ;
	else //Otherwise, don't change at all
		$output['url'] = $data[1] ;

	return $output ;	
}





?>
