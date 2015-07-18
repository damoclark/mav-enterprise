<?php

include_once('ignition_app_config.php') ;

/**
 * Configuration class for MAV
 *
 * This class will read the configuration ini file as passed to constructor into
 * instance and allow the application to request config parameters
 */
class MavConfig extends ignition_app_config
{
	/**
	 * @var array An index mapping moodle url strings to section names as defined by the ini file
	 */
	protected $urlLookup = null ;
	
	/**
	 * @var array An array of section headings required in the config (e.g. 'global' => "The global section is for...")
	 */
	protected $configSections = array() ; //No required section headings (can be arbitrary)
	
	/**
	 * @var array An array of required configuration options
	 */
	protected $configOptions = array
	(
		'*' => array //Config options for all sections
		(
			// Moodle home page address for this Moodle site
			'moodle_home_url' => array
			(
				'description' =>   "Moodle home page address for this moodle site (e.g. moodle.server.com)"
			),
			// Moodle software installation (local)
			'moodle_install' => array
			(
				'description' => "Moodle software installation (local) (e.g. /var/www/html/moodle"
			),
			// Logging system used (Moodle 2.6+) (standard|legacy)
			'moodle_logging' => array
			(
				'description' => "Logging system used (Moodle 2.6+ 'standard' Moodle < 2.6 'legacy')",
				'default' => "standard",
				'valid' => '/^(standard|legacy)$/i'
			),
			// Moodle version (major.minor)
			'moodle_version' => array
			(
				'description' => "Moodle version (major.minor) (e.g. 2.7)"
			),
			// Moodle database table name prefix (DBPREFIX in config.php)
			'dbprefix' => array
			(
				'description' => "Moodle database table name prefix (DBPREFIX in config.php) (default: mdl_)",
				'default' => 'mdl_'
			),
			// Name of the stanza (section) in PDOdatabase ini file to use to connect to the
			// DB associated with the above moodle_url
			'pdodatabase' => array
			(
				'description' => "Name of the stanza (section) in PDOdatabase ini file to use to connect to the DB associated with the moodle_url option"
			),
			// The connected user from the pdodatabase option must have permission to:
			// create, drop, select, update, delete, insert
			// on these tables in their tablespace/schema
			//
			// Name of the summary table in Moodle DB
			'table_summary' => array
			(
				'description' => "Name of the summary table in Moodle DB (e.g. mdl_logstore_standard_log_summary)",
				'default' => "mdl_logstore_standard_log_summary"
			),
			// Name of the table used to update click counts (and renamed into table_summary)
			'table_update' => array
			(
				'description' => "Name of the table used to update click counts (and renamed into table_summary)",
				'default' => "mdl_logstore_standard_log_summary_update"
			),
			// Name of the summary state table in Moodle DB
			'table_state' => array
			(
				'description' => "Name of the summary state table in Moodle DB",
				'default' => "mdl_logstore_standard_log_summary_state"
			),
			// Name of the batch table in Moodle DB
			'table_batch' => array
			(
				'description' => "Name of the batch table in Moodle DB for identifying next incremental update",
				'default' => "mdl_logstore_standard_log_summary_batch"
			),
			// Name of the index on the summary table in Moodle DB
			'table_summary_index' =>
			array
			(
				'description' => "Name of the index on the summary table in Moodle DB",
				'default' => "mdl_logstore_standard_log_summary_ix"
			),
			// Name of the index on the summary_update table in Moodle DB
			'table_update_index' => array
			(
				'description' => "Name of the index on the summary_update table in Moodle DB",
				'default' => "mdl_logstore_standard_log_summary_update_ix"
			)
		)
	) ;
	
	/**
	 * Constructor
	 * 
	 * @param string $configFilename Filename to load the config file
	 * @param boolean $validate Whether the config file format should be validated with correct settings
	 * 
	 * @return MavConfig    An instance of this object
	 */
	function __construct($configFilename=null)
	{
		parent::__construct($configFilename) ;

		$this->validateConfig() ;
		
		$urlLookup = array() ;
		//Index the section names by moodle_home_url
		foreach($this->config as $s => $conf)
		{
			//Remove any trailing slash on the moodle_home_url
			$this->config[$s]['moodle_home_url'] = rtrim($conf['moodle_home_url'],'/') ;
			$urlLookup[$this->config[$s]['moodle_home_url']] = $s ;
		}
		$this->urlLookup = $urlLookup ;
	}

	/**
	 * Given a moodle home page url, this method will return the section name in
	 * the config representing it
	 * 
	 * @param string $moodleUrl Url to the home page of Moodle installation
	 * 
	 * @return string    Name of the section in the config representing given moodle url
	 */
	private function getSectionNameByMoodleUrl($moodleUrl)
	{
		if($moodleUrl === null)
			throw new Exception("Need to provide the moodle url",1) ;
		
		//All we want is the hostname, port and path
		$u = parse_url($moodleUrl) ;
		$host = $u['host'] ;
		$port = (isset($u['port'])) ? $u['port'] : '' ;
		$path = (isset($u['path'])) ? $u['path'] : '/' ;
		$moodleUrl = "{$host}{$port}{$path}" ;
		//Remove any trailing slashes (sometimes there sometimes not)
		$moodleUrl = rtrim($moodleUrl,'/') ;

		if(array_key_exists($moodleUrl,$this->urlLookup))
			return $this->urlLookup[$moodleUrl] ;
		
		//Otherwise, its not found
		throw new Exception("Moodle URL '$moodleUrl' not found in config file '{$this->configFilename}'") ;
		
	}

	/**
	 * Given a moodle url home page, return the configuration as an array
	 * 
	 * @param string $moodleUrl Moodle home page url
	 *
	 * @return array    A data structure representing the section in the config ini file
	 */
	function getConfigSectionByMoodleUrl($moodleUrl=null)
	{
		$sectionName = $this->getSectionNameByMoodleUrl($moodleUrl) ;

		return $this->getConfigSection($sectionName) ;
	}
	
	/**
	 * Given a moodle url, and a variable name, return the value from the config ini
	 * 
	 * @param string $moodleUrl Moodle home page url
	 * @param string $var       Variable name from the config file to retrieve value
	 *
	 * @return mixed    The value from the config
	 */
	function getConfigValueByMoodleUrl($moodleUrl=null,$var=null)
	{
		$sectionName = $this->getSectionNameByMoodleUrl($moodleUrl) ;

		return $this->getConfigValue($sectionName,$var) ;
	}
	
	/**
	 * For the given Moodle home page url, return all the table name configuration
	 * options as an array with the table name (minus the 'table_' prefix) as key
	 * and the value the actual table name to use
	 * 
	 * @param string $moodleUrl The Moodle home page url
	 * 
	 * @return array    The table names
	 */
	function getTableNamesAsArrayByMoodleUrl($moodleUrl)
	{
		$mav_config = $this->getConfigSectionByMoodleUrl($moodleUrl) ;

		$tableNames = array() ;
		//Get all config variable names starting with 'table_' and return them
		//along with their matching value
		foreach($mav_config as $var => $val)
		{
			if(preg_match('/^table_/',$var))
			{
				$var = preg_replace('/^table_/','',$var) ;
				$tableNames[$var] = $val ;
			}
		}

		return $tableNames ;
	}
}


?>
