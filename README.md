

# MAV - Enterprise Edition #

Moodle Activity Viewer is an open-source Greasemonkey user script that visualises student activity within the Moodle LMS.  It does this not with tables or graphs, but instead using a heat map - colouring links lighter or darker according to how often they are accessed as illustrated below.

![MAV Image](http://damosworld.files.wordpress.com/2013/08/course1_1.png?w=468&h=341)

The heat map can represent a range of usage information including

* Total number of students to have clicked on a link
* Number of clicks on a link
* Identify students who have and haven't clicked on a link

These representations can be shown for all students, selected Moodle groups of students, and individual students.

## Requirements ##

This enterprise version of MAV requires a webserver (for example Apache) to install and run the web-service component that aggregates student activity from the Moodle Database.

Furthermore, MAV works only with the [Firefox](http://www.firefox.com) web browser at this time, although a Google Chrome version is planned.

The following is required to make use of MAV Enterprise Edition:

* Firefox 15+
* Greasemonkey Firefox Addon
* A webserver (e.g. Apache) to install the web-service component of MAV
* PHP 5.4+
* Moodle 2.6+ Source Code
* Access to the Moodle DB (or a copy) by the MAV web-service
   The web service has to query a database to get the usage information. It's currently written to use an aggregated table calculated from the Moodle database.
* A little patience, as this is still emerging and experimental software

## Installation ##

MAV Enterprise Edition was written for use at CQUniversity Australia.  Installing MAV Enterprise Edition for users at your institution will require a little effort on your part. It is advised to contact the creator, [Damien Clark](mailto:damo.clarky@gmail.com) for assistance.  An installation guide will be made available soon.

## A quick tour of the features ##

* Generate heatmaps according to the number of clicks on each link, or the number of individual students who clicked each link
* The heatmaps can be shown on any Moodle page (such as Course Home, Discussion Forums, Moodle Pages, Moodle Books, Blackboard Collaborate Recordings, and so on)
* The results can be filtered by Moodle groups (e.g. show only Distance students)
* When used with CQUniversity's Early Alerts Student Indicators (EASI) System, you can see heatmaps of individual student activity.
* At the click of a button, identify students who have and haven't accessed resources and activities directly within the page
* Using CQUniversity's EASI System, send personalised templated *nudge* emails to students to encourage re-engagement in their course studies.

## Further Information ##

A blog post - [The Moodle Activity Viewer (MAV) - Heatmaps of Student Activity](http://damosworld.wordpress.com/2013/08/30/the-moodle-activity-viewer-mav-heatmaps-of-student-activity/) provides further information about MAV.

## Licence ##

MAV is licenced under the terms of the [GPLv3](http://www.gnu.org/licenses/gpl-3.0.en.html).

## Contributions ##

Contributions are welcome - fork and push away.  Contact me ([Damien Clark](mailto:damo.clarky@gmail.com)) for further information.

