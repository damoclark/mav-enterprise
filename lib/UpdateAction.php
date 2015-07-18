<?php

use Arara\Process\Action\Action ;
use Arara\Process\Control ;
use Arara\Process\Context ;

/**
 * Process rows from MAV batch table counting student clicks
 * 
 * This class implements the Arara\Process\Action\Action class so it can
 * be executed in a child process.  It is allocated a list of courses to
 * process from the batch table and will count the number of clicks per student
 * per link, storing the count in memory. When it has finished counting its
 * allocated courses, it then updates/inserts the information into the MAV
 * summary table and then exits.
 * 
 */
class UpdateAction implements Action
{
	/**
	 * @var integer Number of rows to request from DB at a time
	 */
	protected $limitNum = 0 ;

	/**
	 * @var array MAV configuration array
	 */
	protected $mav_config = null ;

	/**
	 * @var string SQL query for batch table
	 */
	protected $eventSql = null ;
	
	/**
	 * @var array Array keys contain the course (courseid) to process and values the number of events
	 */
	protected $job = null ;
	
	/**
	 * Constructor for UpdateAction class for MAV
	 *
	 * @param array $mav_config MAV configuration array from MavConfig class
	 * @param integer $limitNum   The maximum number of rows to request from DB at a time
	 * @param array $job	An array with key equal to courseid and value to the number of events to process. A list of courseid to process in this action
	 * 
	 * @return UpdateAction    An instance of this class
	 */
	function __construct($mav_config=null,$limitNum=0,$job)
	{
		if($mav_config === null)
			throw new Exception("Must provide mav_config array to this action class",1) ;
		
		$this->mav_config = $mav_config ;
		
		$this->job = $job ;

		$this->limitNum = $limitNum ;
	}
	
	/**
	 * Called in the child process to begin processing the courses
	 * 
	 * @param Arara\Process\Control $control Arara control object
	 * @param Arara\Process\Context $context Arara context object
	 * 
	 * @return integer    Returns Arara\Process\Action\Action EVENT constant. Action::EVENT_SUCCESS if completely successfully
	 */
	function execute(Control $control, Context $context)
	{
		
		///////////////////////////////////////////////////////////////////////////
		//Setup execution
		///////////////////////////////////////////////////////////////////////////
		
		//Convert the course id values into a string separated by commas
		$courseids = array_keys($this->job) ;
		$courseids = join(',',$courseids) ;

		//Get the total number of events to work with
		$total = array_sum($this->job) ;
		
		//Start from first row returned (and increment by limitNum until $total reached)
		$limitFrom = 0 ;
		
		if($GLOBALS['options']['debug'] or $GLOBALS['options']['progress'])
			echo "Processing courses (courseids: $courseids): (pid:" . getmypid() . ")\n" ;
		
		//Store the mav config in local variable
		$mav_config = $this->mav_config ;
		
		//Require the moodle setup.php script located at moodle_install
		require_once('require_moodle.php') ;
		list($CFG,$DB,$USER) = require_moodle($mav_config['moodle_install']) ;

		//Get the db table prefix value from the Moodle config
		$DBPREFIX = $CFG->prefix ;
		
		/**
		 * @var array Connection settings for the Moodle DB based on mav configuration
		 */
		$DBSETTINGS = $mav_config['pdodatabase'] ;
		
		/**
		 * @var PDOdatabase Object so can connect to Moodle DB.
		 */
		$PDOdatabase = new PDOdatabase('DBCONF') ;

		//Begin transaction to insert into the summary table (with new connection)
		$pdo = $PDOdatabase->connectPDO($DBSETTINGS) ;
		$pdo->beginTransaction() ;
		
		//Get all events from the standard_log identified in the batch table
		//where the courseids match what was provided to constructor
		//ordered by id (ensuring deterministic order with subsequent queries)
		//with different limit and offset (no semi-colon because Moodle DB API)
		//adds the limit and offset keywords when necessary
		$this->eventSql =
			"
				select l.*
				from {$DBPREFIX}logstore_standard_log l
				where exists
				(
					select id from {$mav_config['table_batch']} b
					where l.id = b.id
				)
				and courseid in ({$courseids})
				order by l.id
			" ;

		//Load in our mav_moodle_store class which extends \logstore_standard\log\store
		//and provides the ability for MAV to query the logstore using the batch table
		require_once('mav_moodle_store.php') ;

		//Calling the log manager will get Moodle to load all the relevant php files
		$logmanger = get_log_manager();
	
		//Typical approach to selecting a logstore reader from Moodle
		//
		//// moodle27/admin/tool/log/classes/log/manager.php
		//$readers = $logmanger->get_readers('\core\log\sql_internal_reader');
		//// moodle27/admin/tool/log/store/standard/classes/log/store.php
		//$reader = reset($readers);
		
		/**
		 * @var array Aggregated event counts for each course
		 */
		$data = array() ;
		
		///////////////////////////////////////////////////////////////////////////
		//Get our events from Moodle and count
		///////////////////////////////////////////////////////////////////////////

		while($limitFrom < $total)
		{
			//Use our store
			$reader = new \logstore_standard\log\mav_moodle_store($logmanger) ;
	
			/**
			 * @var array of event objects to iterate and count clicks
			 */
			$events = $reader->get_events_sql($pdo,$this->eventSql,$limitFrom, $this->limitNum) ;
		
			if($GLOBALS['options']['debug'])
				echo "total events retrieved from DB by Moodle get_events_sql: " . count($events) . "\n" ;
			//For each event, add to summary counts
			foreach($events as $id => $event)
			{
				//Get array of event
				$eventData = $event->get_data() ;
	
				//Get a \moodle_url object representing this event
				$url = $event->get_url() ;
	
				/**
				 * @todo May need to create a subclass of \moodle_url that will sort the parameter
				 * names alphabetically so that they can be compared using equality within
				 * sql query
				 * e.g.
				 * $url = new mav_moodle_url($url) ;
				 *
				 * override get_query_string method to sort the keys of the $params array
				 * mav_moodle_url sorts the $params instance array by key
				 */
				
				//If the event object can't retrieve the url, report and skip
				if(! $url instanceof \moodle_url)
				{
					error_log("Unable to retrieve url for event (id:{$id}) : {$event->get_name()} " . get_class($event) . " (pid:" . getmypid() . ")") ;
					//@todo While we delete from batch so isn't added to state (as being processed) It should be added to an alternate table such as unprocessed so each night the script doesnt reattempt the same rows.  But keeping in an unprocessed table means in the future if something is fixed to allow it to be added, then they can be pulled from this table, without having to do a complete purge of the summary table
					//delete $id from batch as it wasnt added to summary (so it shouldnt be added to state)
					self::deleteTableRows($pdo,$mav_config['table_batch'],$id) ;
					continue ;
				}

				//Set $url to be relative to the root of the moodle install (false
				//means dont do html escaping on the link such as &amp;)
				$url = $url->out_as_local_url(false) ;
	
				//This is a unique index to store clicks
				//for this course, link and student (eg. 12,4,/user/index.php?id=2)
				$urlIndex = join(",",array($eventData['courseid'],$eventData['userid'],$url)) ;
		
				if($GLOBALS['options']['debug'])
					echo "Event: " . $event->get_name() . " ($url), Userid: " . $eventData['userid'] . ", Class: " . get_class($event) . ": " .getmypid() . "\n" ;
	
				//These lines were present in the moodle source, but don't believe needed here
				//kept however just in case
				//$event->data['objectid'] = isset($data['objectid']) ? $data['objectid'] : null;
				//$event->data['courseid'] = isset($data['courseid']) ? $data['courseid'] : null;
				//$event->data['userid'] = isset($data['userid']) ? $data['userid'] : $USER->id;
		
		
				//Check to see if $url points to a Moodle book chapter (or perhaps others)
				//If so, then we need to add two links to be counted.
				//The original link to the chapter,
				//and the top level link to the book itself
				//This way, the link in the main page pointing to the entire book can
				//show with the heat map, all the clicks that have occurred inside the
				//book, rather than just the clicks to the top of the book.
				//This gives a truer representation of activity within the book

				//Include the original $url from the Event
				$items = array($urlIndex => $url) ;

				//Now use regex to see if this is a book link to a chapter
				$match = array() ;
				if(preg_match('/^(\/mod\/(?:book)\/.*?\.php\?id=\d+)&/',$url,$match))
				{
					//If so, then add to our $items list, the top level link to the book
					//without the chapter information so that a click is counted overall
					//for the book
					$url = $match[1] ;
					$urlIndex = join(",",array($eventData['courseid'],$eventData['userid'],$url)) ;
					$items[$urlIndex] = $url ;
				}
				//If this is a forum link, there are two urls that map to a forum
				//One is the id= form which is what is found in the moodle course
				//home page, while the other is f= kind and found on the forums
				//page, and also constituted through the event system
				//So if we have an f= forum link, we also need to count for
				//a id= link too. The id number is derived from the contextinstanceid
				elseif(preg_match('/^(\/mod\/forum\/view\.php\?f=\d+)/',$url,$match))
				{
					$url = "/mod/forum/view.php?id={$eventData['contextinstanceid']}" ;
					$urlIndex = join(",",array($eventData['courseid'],$eventData['userid'],$url)) ;
					$items[$urlIndex] = $url ;
				}

				//Now $items will have either 1 or two entries, so add it/them
				foreach($items as $urlIndex => $url)
				{
					//If we already have a record in memory for this course/student/url
					//combination, then just increment its event counter
					if(array_key_exists($urlIndex,$data))
					{
						$data[$urlIndex]['counter']++ ;
		
						if($GLOBALS['options']['debug'])
							echo "Increment clicks={$data[$urlIndex]['counter']} for url={$data[$urlIndex]['url']} in child: " . getmypid() . "\n" ;
					}
					else //Otherwise, need to create a new in memory record for this one
					{
						if($GLOBALS['options']['debug'])
							echo "New record for url=$url in child: " . getmypid() . "\n" ;
		
						/**
						 * @var array Data to be added to shared memory
						 */
						$data[$urlIndex] = array
						(
							'courseid' => $eventData['courseid'],
							'url' => $url,
							'userid' => $eventData['userid'],
							'component' => $eventData['component'],
							'counter' => 1
						) ;
					}
				}
			}
			//Update our sliding window
			$limitFrom += $this->limitNum ;
		}
		
		//If --progress or --debug option given on command line, then output rows completed
		if($GLOBALS['options']['progress'] or $GLOBALS['options']['debug'])
		  echo "Finished counting events for courses (courseids: {$courseids}) counting a total of $total events (pid:" . getmypid() . ")\n" ;
			

		///////////////////////////////////////////////////////////////////////////
		//Add counted rows from batch table into the summary table
		///////////////////////////////////////////////////////////////////////////

		/**
		 * @var string Query to update summary table for new entries in the logstore_standard_log
		 */
		$updateSummarySql =<<<EOF
	update {$mav_config['table_update']} set clicks = clicks + :counter
	where
		courseid = :courseid
		and url = :url
		and userid = :userid
		and component = :component
	;
EOF;
		
		/**
		 * @var string Query to insert new row in summary table for new entries in the logstore_standard_log
		 */
		$insertSummarySql =<<<EOF
	insert into {$mav_config['table_update']}
		(url,userid,courseid,component,clicks)
	values
		(:url,:userid,:courseid,:component,:counter)
	;
EOF;
			
		//Prepare insert and update queries
		$insert = $pdo->prepare($insertSummarySql) ;
		$update = $pdo->prepare($updateSummarySql) ;

		$totalRows = 0 ;
		if($GLOBALS['options']['progress'] or $GLOBALS['options']['debug'])
		{
			$totalRows = count($data) ;
			echo "Inserting/updating $totalRows events (pid: " . getmypid() . ")\n" ;
		}
		
		$counter = 0 ;
		//$output is a row from the standard_log table with just the fields required
		//to go into our summary table
		foreach($data as $output)
		{
			//Attempt to update an existing row for $output
			if(!$update->execute($output))
			{
				throw new Exception("Error updating database\n".join("\n",$pdo->errorInfo()),1) ;
			}
			//If no row was updated, then it doesn't exist in the DB, so insert instead
			if($update->rowCount() == 0)
			{
					if(!$insert->execute($output))
					{
						throw new Exception("Error inserting into database\n".join("\n",$pdo->errorInfo()),1) ;
					}
		
					if($GLOBALS['options']['debug'])
						echo "Inserted row for url {$output['url']}\n" ;
			}
			elseif($GLOBALS['options']['debug']) //If update & debug
						echo "Updated row for url {$output['url']}\n" ;
			
			$counter++ ;
			//If --debug, output how many events have been updated in summary table
			if(($GLOBALS['options']['progress'] or $GLOBALS['options']['debug']) and !($counter % $this->limitNum))
				echo "Progress: Inserted/updated $counter rows of $totalRows\n" ;
		}
		
		//Finally commit the changes
		$pdo->commit() ;

		//If --debug or --progress, output how many events have been updated in summary table
		if($GLOBALS['options']['progress'] or $GLOBALS['options']['debug'])
			echo "Total inserted/updated rows committed: $counter of $totalRows (pid: " . getmypid() . ")\n" ;
		
		return Action::EVENT_SUCCESS ;
	}
	
	function trigger($event, Control $control, Context $context)
	{
		//Don't need to do anything here
	}
	
	//////////////////////////////////////////////////////////////////////////////
	//DB Functions
	//////////////////////////////////////////////////////////////////////////////

	/**
	 * This function will return an array with keys holding the courseid and values
	 * an array with the first (and only element) holding the number of events
	 * that occurred in that course since this script last run
	 * 
	 * @param PDO $pdo        Database connection object
	 * @param array $mav_config The mav configuration information for this run
	 * @param string $DBPREFIX The prefix for tables in Moodle DB
	 * 
	 * @return array    Array of form array( courseid => eventcount ) for each course
	 */
	static function getEventsPerCourse($pdo,$mav_config,$DBPREFIX)
	{
		$selectEventsPerCourseSql =<<<EOF
		select l.courseid,count(id)
		from {$DBPREFIX}logstore_standard_log l
		where exists
		(
			select id from {$mav_config['table_batch']} b
			where l.id = b.id
		)
		group by l.courseid
		order by count(id) desc ;
EOF;
		
		$courseEventsStmt = $pdo->prepare($selectEventsPerCourseSql) ;
		$courseEventsStmt->execute() ;
		$courseEvents = $courseEventsStmt->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_GROUP) ;
	
		//Convert the fetch_group result into a simple courseid => count mapping
		$result = array() ;
		foreach($courseEvents as $courseid => $a)
		{
			$result[$courseid] = $a[0] ;
		}
		
		return $result ;
	}
	
	/**
	 * Delete a specific row from a table
	 * 
	 * @param PDO $pdo       Database handle
	 * @param string $tableName Name of table to do delete
	 * @param integer $id  id value of the row to delete
	 * 
	 * @return boolean    True if successful otherwise false
	 */
	static function deleteTableRows($pdo,$tableName,$id)
	{
		if(!is_numeric($id))
			throw new Exception("Error id number '$id' provided to deleteTableRows is invalid",1) ;

		$sql = "delete from {$tableName} where id = $id ;" ;
		
		return $pdo->exec($sql) ;
	}

	/**
	 * This function will delete all rows from the given table name
	 * 
	 * @param PDO $pdo       Database connection object
	 * @param string $tableName Name of table to delete rows from
	 * 
	 * @return integer    Number of rows deleted
	 */
	static function deleteTableContents($pdo,$tableName)
	{
		$sql = "delete from {$tableName} ;" ;
		$adapter = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ;
		if($adapter == 'pgsql')
			$sql = "truncate table {$tableName} ; " ;
			
		return $pdo->exec($sql) ;
	}
	
	/**
	 * This function will copy the contents of one table into another with the
	 * same schema
	 * 
	 * @param PDO $pdo       Database connection
	 * @param string $tableFrom Table name to copy from
	 * @param string $tableTo   Table name to copy to
	 * 
	 * @return integer    Number of rows copied
	 */
	static function copyTableContents($pdo,$tableFrom,$tableTo,$sql=null)
	{
		if(is_null($sql))
			$sql = "insert into {$tableTo} select * from {$tableFrom} ;" ;
	
		return $pdo->exec($sql) ;
	}
	
	/**
	 * This function will rename a table to another
	 * 
	 * @param PDO $pdo       Database connection
	 * @param string $tableFrom Table name to rename from
	 * @param string $tableTo   Table name to rename to
	 * 
	 * @return integer    Whether the rename worked
	 */
	static function renameTable($pdo,$tableFrom,$tableTo)
	{
		$adapter = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ;
		if($adapter == 'pgsql')
		{
			//Strip possible schema name from front of $tableTo
			$tableTo = preg_replace('/^[^.]+\./','',$tableTo) ;
			$sql = "alter table {$tableFrom} rename to {$tableTo} ;" ;
		}
		return $pdo->exec($sql) ;
	}
}

?>
