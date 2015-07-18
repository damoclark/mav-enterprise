{* Smarty *}

{*********************************************************
  @ description: MAV SQL Query Template for getActivity.php
  @ project: MAV
  @ komodotemplate: 
  @ author: Damien Clark <damo.clarky@gmail.com>
  @ date: 30th May 2014
**********************************************************}
--Get list of student usernames who have accessed a particular resource
select distinct u.username,u.firstname,u.lastname from {$table.summary} ls, {$dbprefix}user u
where
  ls.userid = u.id
  and ls.url = :url
	and ls.courseid = :courseid
	order by u.lastname,u.firstname
;
