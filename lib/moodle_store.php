<?php

namespace logstore_standard\log ;

/**
 * Subclass of logstore_standard\log\store from Moodle that can take customised
 * sql query to populate the events array
 *
 * This class provides a get_events_sql method (matching the get_record_sql
 * syntax found elsewhere) that can retrieve events from the logstore according
 * to custom sql provided
 */
class mav_store extends \logstore_standard\log\store
{

	/**
	 * Get events from logstore using full sql (matches get_record_sql syntax)
	 * 
	 * @param string $sql       Query matching fields from logstore_standard_log
	 * @param array   $params    Array of optional parameters
	 * @param integer $limitfrom return a subset of records, starting at this point (optional).
	 * @param integer $limitnum  return a subset comprising this many records in total (optional, required if $limitfrom is set).
	 * 
	 * @return array    An array of event objects
	 */
	public function get_events_sql($sql, array $params=null,$limitfrom=0, $limitnum=0)
	{
		global $DB;

		$events = array();
		$records = $DB->get_records_sql($sql,$params,$limitfrom,$limitnum) ;

		foreach ($records as $data)
		{
			$extra = array('origin' => $data->origin, 'ip' => $data->ip, 'realuserid' => $data->realuserid);
			$data = (array)$data;
			$id = $data['id'];
			$data['other'] = unserialize($data['other']);
			if ($data['other'] === false)
				$data['other'] = array();

			unset($data['origin']);
			unset($data['ip']);
			unset($data['realuserid']);
			unset($data['id']);

			$event = \core\event\base::restore($data, $extra);
			// Add event to list if it's valid.
			if ($event)
				$events[$id] = $event;

		}

		return $events;
	}
	
}




?>