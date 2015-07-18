{* smarty *}


{*********************************************************
  @ description: MAV SQL Query Template for getActivity.php
  @ project: MAV
  @ komodotemplate: 
  @ author: Damien Clark <damo.clarky@gmail.com>
  @ date: 9th Jan 2014
**********************************************************}

select
{*----------------------*}
{if $activityType == 'C'} {* If select count of clicks *}
sum(ls.clicks) as total
{elseif $activityType == 'S'} {* If select count of students *}
count(distinct ls.userid) as total
{/if}
{*----------------------*}
{* Default tables *}
from {$table.summary} ls
{*----------------------*}
{if $selectedStudent} {* If a student selected limit to just that student *}
, {$dbprefix}user u
{/if}
{*----------------------*}
{if $selectedGroups} {* If Groups selected *}
, {$dbprefix}groups g, {$dbprefix}groups_members gm
{/if}
{* Get the specifics for the link *}
where
ls.url = :url
and ls.courseid = :courseid 
{*----------------------*}
{if $selectedGroups} {* If Groups selected limit to those *}
and ls.userid = gm.userid
and gm.groupid in ({$selectedGroups|@implode:', '}) {* Comma sep list of ids *}
and gm.groupid = g.id and g.courseid = ls.courseid
{/if}
{*----------------------*}
{if $selectedStudent} {* If a student selected limit to just that student *}
and u.id = ls.userid
and u.username = '{$selectedStudent}'
{/if}
;
