<?php

namespace logstore_standard\log ;

use \PDO as PDO ;

/**
 * Subclass of logstore_standard\log\store from Moodle that can take customised
 * sql query to populate the events array
 *
 * This class provides a get_events_sql method ] that can retrieve events from
 * the logstore according to custom sql provided
 */
class mav_moodle_store extends \logstore_standard\log\store
{

	/**
	 * Get events from logstore using full sql with PDO.
	 *
	 * @param PDO    $pdo  PDO Database connection
	 * @param string $sql       Query matching fields from logstore_standard_log
	 * @param integer $limitfrom return a subset of records, starting at this point (optional).
	 * @param integer $limitnum  return a subset comprising this many records in total (optional, required if $limitfrom is set).
	 * 
	 * @return array    An array of event objects
	 */
	public function get_events_sql(PDO $pdo, $sql,$limitfrom=0, $limitnum=0)
	{
		/* From Moodle 2.7 pgsql_native_moodle_database.php */
		// We explicilty treat these cases as 0.
		if ($limitfrom === null || $limitfrom === '' || $limitfrom === -1) 
			$limitfrom = 0 ;

		if ($limitnum === null || $limitnum === '' || $limitnum === -1)
			$limitnum = 0 ;
		$limitfrom = (int)$limitfrom ;
		$limitnum  = (int)$limitnum ;
		$limitfrom = max(0, $limitfrom) ;
		$limitnum  = max(0, $limitnum) ;
		if ($limitfrom or $limitnum)
		{
			if ($limitnum < 1)
				$limitnum = "ALL" ;
			else if (PHP_INT_MAX - $limitnum < $limitfrom)
				// this is a workaround for weird max int problem
				$limitnum = "ALL" ;

			$sql .= " LIMIT $limitnum OFFSET $limitfrom" ;
		}

		/**
		 * @var PDOStatement $stmt 
		 */
		$stmt = $pdo->prepare($sql) ;
		$events = array();
		if(!$stmt->execute())
			throw new Exception("Error executing query=$query: with error: " . join(',',$pdo->errorInfo())) ;

		$records = $stmt->fetchAll(PDO::FETCH_ASSOC) ;

		foreach ($records as $data)
		{
			$id = $data['id'];

			$extra = array('origin' => $data['origin'], 'ip' => $data['ip'], 'realuserid' => $data['realuserid']);
			unset($data['origin']);
			unset($data['ip']);
			unset($data['realuserid']);
			unset($data['id']);

			$data['other'] = unserialize($data['other']);
			if ($data['other'] === false)
				$data['other'] = array();

			$event = \core\event\base::restore($data, $extra);
			// Add event to list if it's valid.
			if ($event)
				$events[$id] = $event;
		}

		return $events;
	}
	
}




?>