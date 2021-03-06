// ==UserScript==
// @name          Moodle Activity Viewer
// @namespace	    http://damos.world
// @description	  Re-render Moodle pages to show student usage
// @version       0.8.2
// @grant         GM_getValue
// @grant         GM_setValue
// @grant         GM_getResourceText
// @grant         GM_info
// @grant         GM_addStyle
// @grant         GM_xmlhttpRequest
// @require       jquery-ui/external/jquery/jquery.js
// @require       jquery-ui/jquery-ui.js
// @require       GM_XHR.js
// @require       balmi.user.js
// @require       mav_config.js
// @resource      mavConfig mav_config.json
// @resource      jQueryCSS jquery-ui/jquery-ui.css
// @resource      mavInjectHtml mavInject.html
// @resource      busyAnimationDiv busyAnimation.html
// @resource      mavCSS MAVStyles.css
// @include       https://moodle.server.com/*
// @include       http://moodle.server.com/*
// ==/UserScript==



//don't run on frames or iframes
if (window.top != window.self)  
	exit ;



//Add activityViewer javascript to page and let it do its thing
/**
 * Update the Moodle Page if is_on is set or in urlmode
 * 
 */
function mavUpdatePage()
{
	if(debug) console.log('fragment='+window.location.hash) ;
	if(debug) console.log('isurlmode = '+MAVcourseSettings.isUrlMode()) ;
	if(debug) console.log('fragment='+window.location.hash) ;
	
	//If activityViewer "is_on", then load the activityViewer from server and
	//re-render page
	if (isMavOn())
		generateJSONRequest() ;
		
}

function urlModeUpdateMoodleLinks()
{
	//If in url mode, then need to rewrite all moodle links to add the url fragment
	//so that clicking around the moodle site will return the url mode settings
	if (MAVcourseSettings.isUrlMode())
	{
		if (debug) console.log('inside isurlmode in mavupdatepage!!!') ;
		
		var moodleLinks = balmi.getMoodleElementsAsObject(true) ;
		if(debug) console.log('after getMoodleElementsAsObject inside mavupdatepage') ;
		allLinks = document.getElementsByTagName("a") ;
		
		for (var i=0; i < allLinks.length; i++)
		{
			var linkName = balmi.relativeUrlString(allLinks[i]) ;
			
			//If its not a Moodle link
			if (linkName === null) 
				continue ;

			//If this is a moodle link, then rewrite it to add the url fragment
			//but only if the link doesn't already have a fragment (hash)
			if (moodleLinks.hasOwnProperty(linkName) && allLinks[i].href.indexOf('#') == -1)
				allLinks[i].href += window.location.hash ;
		}
	}
}

///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
//Add Mav menu options to the Settings Block in moodle page
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

function mavAddActivityViewerSwitch(balmi)
{
	//Add the link option to turn on activityViewer

	//The menu structure for MAV
	var menuConfig = {
		settings_menu:
		[
			{
				text: 'Activity Viewer',
				listeners: { click: null, mouseover: null },
				submenu:
				[
					{
						id: 'mav_activityViewerElement', //id property for the url a tag
						text: //Toggle option
						{
							on:  'Turn Activity View Off',
							off: 'Turn Activity View On'
						},
						toggle: isMavOn(), //Internal state of toggle - 'on' text will be displayed
						//url: '#',
						title: 'Toggle Activity Viewer',
						listeners: { click: mavSwitchActivityViewer }
					},
					{
						text: 'Activity Viewer Settings',
						title: 'Activity Viewer Settings',
						listeners: { click: mavDisplaySettings }
					}
				]
			}
		]
	} ;
	
	//if in urlMode change the menu options
	if (MAVcourseSettings.isUrlMode())
	{
		//don't show the Activity Viewer Settings option for now
		menuConfig.settings_menu[0].submenu.splice(1,1) ;

		if(debug) console.log('in urlMode menuConfig='+JSON.stringify(menuConfig)) ;
	}

	balmi.insertMenu(menuConfig) ;
}

/**
 * Function returns true if MAV is switched on, otherwise false
 * 
 * @returns {boolean} True if MAV is on, otherwise false
 */
function isMavOn()
{
	var mav_on = GM_getValue('is_on') ;
	
	var urlMode = MAVcourseSettings.isUrlMode() ;
	
	//If either urlMode or mav_on then return true (mav is actually on)
	return (mav_on || urlMode) ;
}

/**
 * This function will set the link text for turning on or off the activity viewer, based
 * on the GM_getvalue setting is_on
 * 
 */
function mavSetMenuElementText()
{
	//Set text according to whether its already on or off (including img tag)
	var switchLinkText = (isMavOn()) ? 'Turn Activity View Off' : 'Turn Activity View On' ;

	$('#mav_activityViewerElement > span').text(switchLinkText) ;
}


///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
//Mav Settings Dialog
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

/**
 * Event handler to open new window and display optional settings for mav
 * 
 */
function mavDisplaySettings()
{
	
	//init the accordion
	initAccordion();

	//Update widgets on settings window to match the current GM_getvalue settings
	MAVcourseSettings.updateDialog() ;
	
	//Display dialog
	//$(settingsDialogDiv).dialog() ;
	$("#MAVsettingsDiv").dialog
	(
		{
			width: 720,
			height: 500,
			modal: true,
			//position: { my: "center top", at: "center top", of: $("body") },
			buttons:
			{
				"Cancel": function() { $(this).dialog("close") ; },
				"OK"    : function()
				{
					//Update GM_setvalue settings
					try
					{
						MAVcourseSettings.saveJSON() ;
					}
					catch(err)
					{
						alert(err) ;
						return ;
					}
					
					$(this).dialog("close") ;
					
					//IF MAV is already turned on, reload page to reflect settings changes
					if(isMavOn())
						window.location.reload() ;
				}
			}
		}
	) ;
	//mavUpdateSettingsWindow(settingsWindow) ;
	
}

/**
 * Toggle mav on or off
 * 
 */
function mavSwitchActivityViewer()
{
	//If we are in urlMode, then just reload page without url fragment
	if (MAVcourseSettings.isUrlMode())
	{
		GM_setValue('is_on',false) ; //Explicitly turn it off
		mavTurnOffUrlMode() ;
		return ;
	}
	
	GM_setValue('is_on',!GM_getValue('is_on')) ;
	
	if (GM_getValue('is_on'))
	{
		//Toggle the text on the menu
		mavSetMenuElementText() ;
		//Fire updatePage function
		mavUpdatePage() ;
	}
	else
	{
		//Reload the page
		window.location.reload() ;
	}
}

/**
 * Switch off urlMode by reloading page without url fragment options
 * 
 */
function mavTurnOffUrlMode()
{
	//Set the window.location url without the hash (fragment) to reload page
	//http://stackoverflow.com/questions/1397329/how-to-remove-the-hash-from-window-location-with-javascript-without-page-refresh
	var l = window.location.href.substr(0, window.location.href.indexOf('#')) ;
	if(debug) console.log('location='+l) ;
	window.location = l ;
	return ;
}

///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
//Accordion init and config for the dialog
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

function initAccordion() 
{
	
	$("#MAVsettingsForm").accordion({
		collapsible: true,
		header: "h2",
		active: false,
		heightStyle: "content"
	});
	
	//init the select weeks jquery buttons
	$("input:button").button();
	$("#MAVdisplayModes, #MAVTypes").buttonset();

	//Removed timeframe code for now
	//Also commented out matching HTML in mavInjectHtml.html
	//$("#timeframe_selectall").bind("click", function(e) {			
	//	//tick all weeks
	//	$(".MAVTimeframe").attr('checked',true).prop('checked',true);
	//});
	//$("#timeframe_selectnone").bind("click", function(e) {			
	//	//untick all weeks
	//	$(".MAVTimeframe").prop("checked",false).removeAttr("checked");
	//});
	
	
}


//matchine binding for below
/*			var validated = collectSelections();	
			if (validated) {
				do something
				send array to db or something
			}
			else {
				do something else
				example: show error saying "you must select a student first"	
			}
}
*/

//collecting which students are ticked
function collectSelections() {
	
	//clear the array first	
	clearArrays();		
	
	//collect the students involved
/*	var counter = 0;
	var validate = false;		
	$(".recipients").each(function(i) {
		if ( $(this).prop("checked")==true ) {
			studentMIDs[counter] = $(this).val();		
			counter++;	
			validate = true;	
		}
	});	
*/	
	
	return validate;		
}

/**
 * Class for interacting with settings dialog
 * 
 * @param   {Integer} function Integer representing id number of course
 */
function MAVsettings(courseid)
{
	//Instance course id (from breadcrumbs)
	this.courseid = courseid ;
	//Default settings for dialog
	this.defaultJSON =
	{
		activityType: "C", //Default to clicks
		displayMode: "C", //Default to colour
		groups: [ 0 ], //Default to all students
		student: 0 //Default to no specific student
	} ;
	//Have our settings come from the a url fragment
	this.urlMode = false ;

	//Instance settings for dialog - if no settings provided through url, will
	//be set to null initially
	this.JSON = this.urlSettings() ;

}

MAVsettings.prototype.urlSettings = function()
{
	//Get the fragment from the url (but omit initial # character)
	var fragment = window.location.hash.substr(1) ;

	if (debug) console.log('fragment='+fragment) ;

	//If there is no fragment
	if (fragment == '')
		return null ;

	//Temporary variable to hold the settings
	var settings = clone(this.defaultJSON) ;

	//Temporary variable to hold the urlmode
	var mode = this.urlMode ;
	
	var parameters = fragment.split('_') ;

	parameters.forEach
	(
		function(element,index,array)
		{
			var s = element.split(':') ;
			var parameter = s.shift() ;

			//Only use parameter names that MAV already knows about
			if (settings.hasOwnProperty(parameter))
			{
				//If this parameter requires an array, then store from fragment an array
				if (settings[parameter] instanceof Array)
					settings[parameter] = s ;
				else //Otherwise, store just the first value that it happens to find
					settings[parameter] = s[0] ;
			}
			//Else if parameter is 'mav' and is set true, then we now know we are
			//definitely in mav urlmode, and its not a moodle fragment
			else if (parameter == 'mav' && s[0] == true)
				mode = true ; //Set urlmode in settings instance
		}
	) ;
	
	//Save the mode into the instance variable
	this.urlMode = mode ;
	
	//If there isn't a mav parameter set to true value (ie 1) in the fragment
	//then the fragment wasn't meant for mav, and so ignore the fragment and
	//just return null
	if (!this.urlMode)
		settings = null ;

	if (debug) console.log('mav url settings='+JSON.stringify(settings)) ;

	return settings ;	
}

/**
 * Method will return true if a url fragment has provided settings for MAV
 * 
 * @returns {boolean} True if settings provided through url otherwise false
 */
MAVsettings.prototype.isUrlMode = function()
{
	return this.urlMode ;
}

/**
 * Method for loading the activity type from GM and updating the dialog
 */
MAVsettings.prototype.loadActivityType = function()
{
	//Make sure JSON has been loaded from GM
	this.loadJSON() ;
	
	//Get the relevant info from the json for the display mode
	var type = this.JSON.activityType ;
	
	//Preset dialog with the stored settings
	//Unset all input elements
	$("#MAVTypes").children("input").prop("checked",false).removeAttr("checked");

	//Set only input element with value attribute set to 'mode'
	$("#MAVTypes > [value='" + type + "']").attr('checked',true).prop('checked',true);
	
	//Refresh the UI with the newly selected elements (sheesh - this is a bit crap of JQuery)
	//http://stackoverflow.com/questions/5145728/jquery-manually-set-buttonset-radios
	$("#MAVTypes").children("input").button("refresh") ;

	return this ;
} ;

/**
 * Method for taking the activity specified in the dialog and storing in MAVsettings instance
 */
MAVsettings.prototype.saveActivityType = function()
{
	//Get the settings from the dialog
	//http://stackoverflow.com/questions/8908943/get-the-currently-selected-radio-button-in-a-jquery-ui-buttonset-without-binding
	var activityType = $("#MAVTypes :radio:checked").attr('value') ;

	if(activityType == null)
		throw "No Activity Type selected" ;

	//Store them back into instance
	this.JSON.activityType = activityType ;

	return this ;
} ;

/**
 * Method for loading the display mode from GM and updating the dialog
 */
MAVsettings.prototype.loadDisplayMode = function()
{
	//Make sure JSON has been loaded from GM
	this.loadJSON() ;
	
	//Get the relevant info from the json for the display mode
	var mode = this.JSON.displayMode ;
	
	//Preset dialog with the stored settings
	//Unset all input elements
	$("#MAVdisplayModes").children("input").prop("checked",false).removeAttr("checked");

	//Set only input element with value attribute set to 'mode'
	$("#MAVdisplayModes > [value='" + mode + "']").attr('checked',true).prop('checked',true);
	
	//show the correct legend
	if ( mode == "T" ) {
		$("#MAVdisplaySizeLegend").show();
	}
	else if ( mode == "C" ) {
		$("#MAVdisplayColourLegend").show();
	}
	
	//Refresh the UI with the newly selected elements (sheesh - this is a bit crap of JQuery)
	//http://stackoverflow.com/questions/5145728/jquery-manually-set-buttonset-radios
	$("#MAVdisplayModes").children("input").button("refresh") ;

	return this ;
} ;

/**
 * Method for taking the display mode specified in dialog and storing in MAVsettings instance
 */
MAVsettings.prototype.saveDisplayMode = function()
{
	//Get the settings from the dialog
	//http://stackoverflow.com/questions/8908943/get-the-currently-selected-radio-button-in-a-jquery-ui-buttonset-without-binding
	var displayMode = $("#MAVdisplayModes :radio:checked").attr('value') ;

	if(displayMode == null)
		throw "No Display Mode selected" ;

	//Store them back into instance
	this.JSON.displayMode = displayMode ;

	return this ;
} ;

/**
 * Method for taking the selected groups from dialog and storing in MAVsettings instance
 */
MAVsettings.prototype.saveGroups = function()
{
	//Get the settings from the dialog
	//http://stackoverflow.com/questions/8908943/get-the-currently-selected-radio-button-in-a-jquery-ui-buttonset-without-binding
	var selectedGroups = [] ;
	$("#MAVGroupData :checkbox:checked").each
	(
		function (i)
		{
			selectedGroups.push($(this).attr('value')) ;
		}
	) ;
	if(debug) console.log('selected groupids='+selectedGroups) ;

	//If no groups are selected, then force to all students which is groupid 0
	if(selectedGroups.length == 0)
		selectedGroups.push(0) ;

	//Store them back into instance
	this.JSON.groups = selectedGroups ;

	return this ;
} ;

/**
 * Method for loading the selected groups from GM and updating the dialog
 */
MAVsettings.prototype.loadGroups = function()
{
	//Make sure JSON has been loaded from GM
	this.loadJSON() ;
	
	//Get the relevant info from the json for the display mode
	var groups = this.JSON.groups ;
	
	//If groups is not yet initialised, then make it set to "All Groups"
	if(groups == null)
		groups = [ 0 ] ;

	//Preset dialog with the stored settings
	
	//Unset all input elements
	$("#MAVGroupData").children("input").prop("checked",false).removeAttr("checked");

	//Foreach group id previously selected, if it still exists set it again in dialog
	for(var i = 0 ; i < groups.length; i++)
	{
		//Set only input element with value matching group id
		$("#MAVGroupData > [value='" + groups[i] + "']").attr('checked',true).prop('checked',true);
	}

	return this ;
} ;


/**
 * Method to load JSON from Greasemonkey into object instance
 * 
 * @param   {Type} MAVsettings Description
 * 
 * @returns {MAVsettings} This object for chaining
 */
MAVsettings.prototype.loadJSON = function()
{
	if (this.JSON == null)
	{
		var GM_json = GM_getValue("course_"+this.courseid) ;
		if(GM_json == null || GM_json == '') //If no settings for this course
		{
			this.JSON = clone(this.defaultJSON) ; // Use default
		}
		else
		{
			this.JSON = $.parseJSON(GM_json) ; //Otherwise set out instance
		}
		//Make sure that student option from default isn't included as that
		//is only relevant for urlMode
		delete this.JSON.student ;
	}

	return this ;
} ;

/**
 * Method for updating the dialog with all the settings for this course stored in GM
 */
MAVsettings.prototype.updateDialog = function()
{
	//Load dialog settings for activity type
	this.loadActivityType() ;
	
	//Load dialog settings for display mode
	this.loadDisplayMode() ;
	
	//Get list of groups for course & insert into dialog
	this.getCourseGroups() ;
} ;

/**
 * Method to save JSON to Greasemonkey from object instance
 * 
 * @returns {MAVsettings} This object for chaining
 */
MAVsettings.prototype.saveJSON = function()
{
	//get activity type settings 
	this.saveActivityType() ;

	//get display mode settings
	this.saveDisplayMode() ;
	
	//get selected groups settings
	this.saveGroups() ;
	
	//set the mav version
	this.JSON.mavVersion = mavVersion ;
	
	//Don't save the student option as this is only for urlMode
	//TODO This might need to be rethought into the future
	delete this.JSON.student ;
	
	//Store in GM
	GM_setValue("course_"+this.courseid,JSON.stringify(this.JSON)) ;
	
	return this ;
} ;

/**
 * This method will return the settings data structure to be converted to JSON
 */
MAVsettings.prototype.getJSON = function()
{
	if(this.JSON == null)
	  this.loadJSON() ;

	return this.JSON ;
}

/**
 * Method for using ajax to retrieve all groups for this course and then it call
 * loadGroups method to update the dialog with the selected groups
 *
 */
MAVsettings.prototype.getCourseGroups = function()
{
	var data = JSON.stringify
	(
		{
			'moodlehomeurl': balmi.getMoodleBaseUrl().href,
			'version': mavVersion,
			'settings': settings,
			'courseid': balmi.getCourseId(),
			'courselink': balmi.getCoursePageElement().href,
			'pagelink': removeMavCommands(window.location).href
		}
	) ;

	if (debug)
		console.log('getCourseGroups json='+data) ;

	var settings = this ;
	
  var xhr = $.ajax
  (
    {
      url: mavServerApi+'/getCourseGroups.php',
      xhr: function(){return new GM_XHR();}, //Use GM_xmlhttpRequest
      type: 'GET',
      data: { "json": data },
      dataType: 'json', 
      success: function(data)
      {
				if(debug) console.log(data) ;
				$("#MAVGroupData").html(data.html) ;
				settings.loadGroups() ;
      },
      error: function(xhr,status,message)
      {
        if(debug) console.log('status='+status) ;
        if(debug) console.log('message='+message) ;
      },
			complete: function(xhr,status)
			{
				if(debug) console.log('status='+status) ;
				//TODO: Hide the progress spinning wheel
			}
    }
  ) ;
	
} ;


///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
//Update page contents
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

/**
 * Generate json for ajax request to server for data
 * 
 */
function generateJSONRequest()
{
	var courseLink = balmi.getCoursePageElement() ;
	//If no course link found in breadcrumbs, then not on a course page
	if (courseLink == null)
		exit ;
	
	//Get the page they are viewing
	var pageLink = removeMavCommands(window.location) ;
	
	//Get relative link to the course home page
	var relativeCourseLink = balmi.relativeUrlString(courseLink) ;
	
	if(debug) console.log('course link='+courseLink.href) ;
	if(debug) console.log('relative course link='+relativeCourseLink) ;
	if(debug) console.log('page link='+pageLink.href) ;
	
	//Parse the page for moodle links, assemble and generate a JSON request to get
	//the stats
	
	//Get relative links
	links = balmi.getMoodleUrlStrings(true) ;
	
	//RE for matching module links
	modre = /^\/mod\/([^\/]+)\/([^\/]+)$/ ;
	//RE for matching the course home page link
	coursere = /^\/(course)\/(view\.php\?.*)$/ ;

	//Filter out links we don't want
	links = links.filter
	(
		function(l)
		{
			/**
			 * @type array Description
			 */
			var info = l.match(modre) ;
			
			//If this link doesn't match a module, test if it matches course home page
			if (info == null && l == relativeCourseLink)
				info = l.match(coursere) ; //And if so, split it up
			
			//If it doesn't match a module or course home page, skip
			if (info == null)
			{
				if (debug) console.log('Link='+l+' was not a course link either skipping') ;
				return false ;
			}

			//Throw away first element (which is the entire match string)
			info.shift() ;
			
			if (debug)
				console.log('module='+info[0]) ;
			
			//Don't count evaluation links as students clicks in evaluations aren't recorded
			//in the m_log
			if(info[0] == 'evaluation')
			{
				if (debug)
					console.log('Excluded evaluation link '+info[1]) ;
				return false ;
			}
			else if (info[1].indexOf("#") != -1) //Don't include in-doc links
			{
				if (debug)
					console.log('Excluded anchor link '+info[1]) ;
				return false ;
			}
			else if (info[1].indexOf("dock=") != -1) //Don't include docking icon links
			{
				if (debug)
					console.log('Excluded dock link '+info[1]) ;
				return false ;
			}
			else if (info[1].indexOf("edit=") != -1) //Don't include editing links
			{
				if (debug)
					console.log('Excluded edit link '+info[1]) ;
				return false ;
			}
			else if (info[1].indexOf("subscribe") != -1) //Don't include forum subscription management links on forum pages
			{
				if (debug)
					console.log('Exclude forum subscription links on forum pages') ;
				return false ;
			}
			else if (info[1].indexOf("settracking") != -1)
			{
				if (debug)
					console.log('Exclude unread post tracking links on forum pages') ;
				return false ;
			}
			else if (info[1].indexOf("markposts.php") != -1)
			{
				if (debug)
					console.log('Exclude marking posts unread on forum pages') ;
				return false ;
			}
			else if (info[1].indexOf("?delete") != -1)
			{
				if (debug)
					console.log('Exclude delete forum post link on forum pages') ;
				return false ;
			}
			else if (info[1].indexOf("?prune=") != -1)
			{
				if (debug)
					console.log('Exclude prune forum post link on forum pages') ;
				return false ;
			}
			else if (info[1].indexOf("?reply=") != -1)
			{
				if(debug)
					console.log('Exclude reply forum post link on forum pages') ;
				return false ;
			}
			//Otherwise, this link is okay
			return true ;
		}
	) ;
	
	////////////////////////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////
	if(debug)
	{
		for(var i=0;i<i.length;i++)
		{
			console.log('link to be sent to server='+links[i]) ;
		}
	}

	requestData(courseLink,pageLink,links) ;
}

/**
 * Make AJAX request to server to get data to render on page
 * 
 * @param   {String} courseLink The url for the course home page
 * @param   {Object} links    Object with properties holding the links
 * 
 */
function requestData(courseLink,pageLink,links)
{
	//Input for the getActivity.php script to work with
	var settings = MAVcourseSettings.getJSON() ;
	var data = JSON.stringify
	(
		{
			'version': mavVersion,
			'settings': settings,
			'courseid': balmi.getCourseId(),
			'courselink': courseLink.href,
			'pagelink': pageLink.href,
			'moodlehomeurl': balmi.getMoodleBaseUrl().href,
			'links': links
		}
	) ;
	
	if(debug) console.log(data) ;
	
  var xhr = $.ajax
  (
    {
      url: mavServerApi+'/getActivity.php',
      xhr: function(){return new GM_XHR();}, //Use GM_xmlhttpRequest
      type: 'POST',
      data: { "json": data },
      dataType: 'json', 
      success: function(data)
      {
				updatePage(data) ;

				//Now that the page has been updated with stats, if in urlmode, update
				//the links to include the urlmode url fragment
				if (MAVcourseSettings.isUrlMode())
					urlModeUpdateMoodleLinks() ;
      },
      error: function(xhr,status,message)
      {
        if(debug) console.log('status='+status) ;
        if(debug) console.log('message='+message) ;
      },
			complete: function(xhr,status)
			{
				if (debug)
					console.log('status='+status) ;
				
				//We need to check for updates for MAV here after the page has been
				//redrawn rather than during the mav server requests, because if there
				//is an update available and the lecturer tries to update MAV, it will
				//interrupt the ajax call (in other words, the page won't get updated)
				//So we check for updates here when mav is turned on so that if the
				//lecturer clicks to update, the page has already been redrawn
				mavSelfUpdate() ;
			}
    }
  ) ;
	
	//TODO: Display a second dialog that has spinning wheel and a cancel button
	//If cancel button is pressed, then call xhr.abort() to abort the ajax request
	//http://stackoverflow.com/questions/446594/abort-ajax-requests-using-jquery
	

}

/**
 * Request from mav server which student did and didnt access given link
 * 
 * @param   {Element} The a tag of the link we are looking up
 * 
 */
function getStudentAccess(link,linkText)
{
	//Get the link we want to look as relative to the base url of the Moodle site
	var relativeLink = balmi.relativeUrlString(link) ;

	if (debug)
		console.log('relativeLink='+relativeLink) ;
	
	var courseLink = balmi.getCoursePageElement() ;
	//If no course link found in breadcrumbs, then not on a course page
	if (courseLink == null)
		exit ;
	
	//Get the page they are viewing
	pageLink = removeMavCommands(window.location) ;
	
	var settings = MAVcourseSettings.getJSON() ;
	var data = JSON.stringify
	(
		{
			'moodlehomeurl': balmi.getMoodleBaseUrl().href,
			'version': mavVersion,
			'settings': settings,
			'courseid': balmi.getCourseId(),
			'courselink': courseLink.href,
			'pagelink': pageLink.href,
			'links': [ relativeLink ]
		}
	) ;
	
	if (debug)
		console.log('getStudentAccess json='+data) ;

  var xhr = $.ajax
  (
    {
      url: mavServerApi+'/getStudentAccess.php',
      xhr: function(){return new GM_XHR();}, //Use GM_xmlhttpRequest
      type: 'POST',
      data: { "json": data },
      dataType: 'json', 
      success: function(data)
      {  
			var courseCode = balmi.getCourseCode();
			
			//--------- put together a default html string:
			var tableHeader = "<table class='studentAccess'><thead><tr><th>Student Number</th><th>Firstname</th><th>Lastname</th></tr></thead><tbody>";
			var tableContent = "";
			var tableFooter = "</tbody></table>";					
			
			// create some arrays to push the username's into
			var accessStudents = new Array();
			var noaccessStudents = new Array();
						
			if 	( data.data[relativeLink]['access'].length > 0 ) {				
				$.each(data.data[relativeLink]['access'], function (index, value) {
					// could alternatively loop over each index to add more flexibility likewise
					tableContent += "<tr><td>" + value.username + "</td><td>" + value.firstname + "</td><td>" + value.lastname + "</td></tr>";	
					accessStudents.push(value.username);
				})
				
				// write the accessed students content to the container
				$("#studentActivityList").html("<p>These students <strong>HAVE</strong> accessed this resource/activity</p>" + tableHeader + tableContent + tableFooter) ;
			}
			else {
				$("#studentActivityList").html("<p>No students have accessed this link</p>") ;
			}
			
			
			//--------- doing the no access list now:
	
			if 	( data.data[relativeLink]['noaccess'].length > 0 ) {				
	
				tableContent = ""; // clear for the next table	
				
				$.each(data.data[relativeLink]['noaccess'], function (index, value) {
					tableContent += "<tr><td>" + value.username + "</td><td>" + value.firstname + "</td><td>" + value.lastname + "</td></tr>";
					noaccessStudents.push(value.username);
				})
				
				$("#studentNoActivityList").html("<p>These students <strong>HAVE NOT</strong> accessed this resource/activity</p>" + tableHeader + tableContent + tableFooter) ;
			}
			else {
				$("#studentNoActivityList").html("<p>All students have accessed this link</p>") ;
			}
			
			//--------- both containers now have content, build the accordion:
			$("#MAVstudentActivityTab").accordion({
				collapsible: true,
				header: "h2",
				heightStyle: "content",
				active: false
			}) ;
								
			//--------- now open the dialog to display:  
			$("#MAVstudentActivityDialog").dialog(
			{
				width: 570,
				height: 570,
				title: "Student Access ("+linkText+")",
				modal: true,
				closeOnEscape: true,
				buttons: {
					"Ok": function() {
						// STOP your greesemonkey update here
						$(this).dialog("close");
					}
				}
			
			});
      },
      error: function(xhr,status,message)
      {
        if(debug) console.log('status='+status) ;
        if(debug) console.log('message='+message) ;
      },
			complete: function(xhr,status)
			{
				if (debug)
					console.log('status='+status) ;
			}
    }
  ) ;
	
}

function updatePage(data)
{
	//do stuff with JSON
	if(debug) console.log(data) ;
	
	var activityType = data.settings.activityType ;
	var displayMode = data.settings.displayMode ;
	
	//How to quantify the number in the page 
	var activityText;
	
	switch (activityType)
	{
		case 'C':
			activityText = ' clicks' ;
			break ;
		case 'S':
			activityText = ' students' ;
			break ;
	}

	//TODO this section we can add in a tag at the top which displays the current student or group 	
	//if in student view
	/*
		$("#fixedStudentDetails").position({
			of: $("body"),
			my: "right top",
			at: "right top"
		})
		
		//make the fixed colour legend draggable in case it's in the way
		$("#fixedStudentDetails").draggable({ containment: "window" })
			
		//add the student details - something crappy like this - can parameterise and make better later
		$("#fixedStudentDetails").html("<p>studentnumber</p>");
			
		//display the fixed colour legend
		$("#fixedStudentDetails").show();
	*/		
	//else 
		//make sure it's hidden
		//$("#fixedStudentDetails").hide();
		
		
	//If displaymode is heatmap (Colour)
	//position the fixed colour legend to the centre of the browser window
	if (displayMode == 'C')
	{
		$("#fixedColourLegend").position({
			of: $("body"),
			my: "center top",
			at: "center top"
		})
		
		//make the fixed colour legend draggable in case it's in the way
		$("#fixedColourLegend").draggable({ containment: "window" })
			
		//display the fixed colour legend
		$("#fixedColourLegend").show();
		
	}
	
	
	allLinks = document.getElementsByTagName("a") ;
	
	////Get editor divs
	var editors = $("div[contenteditable=true]") ;
	
	//Iterate over all the links in the page and update them accordingly
	$(allLinks).each(function(i,link)
	{
		//If the in page link ends with a hash (such as links on section headings)
		if (endsWith(link.href,'#'))
			return ;

		//In (at least M2.7) if a tag has class below, then its a section heading
		//no heat maps here please.
		if($(link).hasClass('the_toggle'))
			return ;
		
		//Skip if element is contained within an editor div
		for(var j=0;j<editors.length;j++)
		{
			if(debug) console.log("testing if link in editor div") ;
			if (editors[j].contains(link))
			{
				if(debug) console.log('link='+link.href+' is in editor') ;
				return ;
			}
		}

		
		var linkName = balmi.relativeUrlString(link) ;
		var linkText = $(link).text() ;
		if (data['data'].hasOwnProperty(linkName))
		{
			//Add the count to the link text (using clicks or students)
			$(link).after('&nbsp;<a id="studentActivityLink_'+i+'" class="makealink">(' + data['data'][linkName] + activityText + ')</a> ') ;
			
			//Add event handler for clicking on the student activity link to open dialog showing which students
			$("#studentActivityLink_"+i).bind('click',function(){getStudentAccess(link,linkText);}) ;

			//Add the count to the title text (using clicks or students)
			var counter = ' (' + data['data'][linkName] + activityText + ')' ;
			link.title += counter ;

			//Highlighting links that have changed not working below
			//link.style.textDecoration = 'none' ;
			link.style.borderBottom = '1px double' ; //Double underline
			//Add double underline to the student activity list link too eg. (15 clicks)
			$("#studentActivityLink_"+i).css('borderBottom','1px double') ;
			
			////////////////////////////////////////////////////////////////////
			
			if (displayMode == "T") {
				
				var fontSize = 0 ;
				
				//If activity Type is clicks, set font size based on proportion of students
				if(activityType == 'C')
				{
					fontSize = Math.round((data['data'][linkName] / data.studentCount * 10) + 12) ;
					if (fontSize > 40)
						fontSize = 40 ;
				}
				//Otherwise, if activity type is students, make a proportion of total students
				//with maximum font size of 40
				else if(activityType == 'S')
				{
					fontSize = Math.round(data['data'][linkName]*28/data.studentCount) + 12 ;
				}
	
				if (fontSize > 0)
				{
					link.style.fontSize = fontSize+"px";
				}
			}
			
			else if (displayMode == "C") {
					
				var percentile;
				
				if (data.studentCount === 0) {
					percentile = 0 ;
				}
				else if (activityType == "C") {
					percentile = data['data'][linkName] / data.studentCount;
					percentile = Math.round(percentile);
					if (percentile>10) percentile=10;
				}
				else if (activityType == "S") {
					percentile = data['data'][linkName] / data.studentCount * 10;
					percentile = Math.round(percentile);
				}
				$(link).addClass("mavColour"+percentile);
				//Add colour style to the student activity list link too
				$("#studentActivityLink_"+i).addClass("mavColour"+percentile) ;
			}
			
			
		}
	}) ;
	
}

///////////////////////////////////////////////////////////////////////////////
//Utility Functions
///////////////////////////////////////////////////////////////////////////////

/**
 * Add jquery-ui css to GM sandbox using GM_addStyle and rewrite any url(paths)
 * to be absolute according to the mav_config.getJqueryHtml() URI
 *
 * eg.
 *
 * change:
 * url(images/ui-bg_glass_75_ccdc6a_1x400.png)
 * to:
 * url(https://moodle.server.com/html/images/ui-bg_glass_75_ccdc6a_1x400.png)
 * 
 * @param   {string} jQueryCSS CSS for jquery-ui (eg. contents of jquery-ui-1.10.2.custom.css)
 */
function addCSS(css)
{
	//Make jQuery images load from mav server
	if(debug) console.log("css before="+css) ;
	css = css.replace(/url\(['"]?(.*?images\/ui-[^\.]+.png)['"]?\)/gm,"url('" + mavJqueryHtml + "/$1')") ;
	if(debug) console.log("css after="+css) ;
	GM_addStyle(css) ;	
}

/**
 * Function to make a copy of an object
 * 
 * @param   {Object} obj Object to copy
 * 
 * @returns {Object} Returns a copy of obj public properties
 */
function clone(obj)
{
	if (null == obj || "object" != typeof obj) return obj;
	var copy = obj.constructor();
	for (var attr in obj)
	{
		if (obj.hasOwnProperty(attr)) copy[attr] = obj[attr];
	}
	return copy;
}

/**
 * Does str string end with suffix
 *
 * http://stackoverflow.com/questions/280634/endswith-in-javascript/280644
 
 * @param   {string} str      String for substring match
 * @param   {string} suffix   String to match at end of str
 * 
 * @returns {boolean} True if str ends with suffix otherwise false
 */
function endsWith(str, suffix)
{
	return str.indexOf(suffix, str.length - suffix.length) !== -1;
}

/**
 * This is a utility method that can take either an Element object (a tag)
 * or a string representing an url, and return an unattached Element or
 * a string respectively that has had any urlMode options provided in the
 * hash property. For instance will take string /course/index.php?id=1#mav:1
 * and return /course/index.php?id=1
 *
 * If there are other hash values, they remain unchanged.
 *
 * To do translation on the link to the current page, pass in window.location
 * 
 * @param   {Element} link An optional 'a' Element to work with or a string
 * 
 * @returns {Element} An Element representing the Element link provided or a string if string provided
 */
function removeMavCommands(link)
{
	if (link === null)
		return null ;

	var a = document.createElement('a') ;

	//If we were passed a string instead of an Element work with it
	if (typeof link === "string" || link instanceof String)
		a.href = link ;
	else
		a.href = link.href ;
	
	//If we are in urlMode, remove the MAV hash parameters (ie. #mav:1)
	if (MAVcourseSettings.isUrlMode())
		a.hash = a.hash.replace(/#mav:[01].*$/,'') ;

	var result = null ;
	
	//if it was a string
	if(typeof link === "string" || link instanceof String)
	{
		//If it was relative, then don't return the protocol, host etc
		if (link.startsWith('/'))
			result = a.pathname + a.search + a.hash ;
		else //Otherwise, return the whole thing
			result = a.href ;
	}
	else //If it was an object, then cant be relative so just return
		result = a ;

	return result ;
}


function mavSelfUpdate()
{
	///////////////////////////////////////////////////////////////////////////////
	//Manually check for updates to script
	///////////////////////////////////////////////////////////////////////////////
	
	var lastCheckUpdateString = mav_config.getLastCheckUpdateAsString() ;
	if(debug) console.log('last check for updates was '+lastCheckUpdateString) ;
		
	if(debug) console.log('checking against last update time is more than 1day') ;
	//if has been 1day since last check with server to see if there is an update
	if (mav_config.needsCheckUpdate())
	{
		if(debug) console.log('We need to check for update') ;
		
		//Update the last check to now
		mav_config.setLastCheckUpdate() ;
		
		var currentMavVersion = mav_config.getVersion() ;
		
		var gmCheckUpdateScriptPath = mav_config.getGmScriptPath() ;
		//ajax in latest script version
		var xhr = $.ajax
		(
			{
				url: gmCheckUpdateScriptPath+'/checkUpdate.php',
				xhr: function(){return new GM_XHR();}, //Use GM_xmlhttpRequest
				type: 'GET',
				data: { "version": currentMavVersion },
				dataType: 'json', 
				success: function(data)
				{
					if (data.response && data.update)
					{
						if(debug) console.log('Updating now from version '+currentMavVersion+' to version '+data.version) ;
						$("#MAVselfUpdateDialog").dialog(
						{
							width: 570,
							height: 570,
							title: "Moodle Activity Viewer Update",
							modal: true,
							buttons: {
								"Not now": function() {
									// STOP your greesemonkey update here
									$(this).dialog("close");
								},
								"Install": function() {
									$(this).dialog("close") ;
									//close the loading animation
									$("#MAVbusyAnimationImage").hide();
									window.location.href = gmCheckUpdateScriptPath+'/moodleActivityViewer.user.js' ;
								}
							}
						
						});

						//if (confirm('Press okay (and then click Install) to update to latest Moodle Activity Viewer or cancel to delay until tomorrow.'))
						//{
						//	//close the loading animation
						//	$("#MAVbusyAnimationImage").hide();
						//	window.location.href = gmCheckUpdateScriptPath+'/moodleActivityViewer.user.js' ;
						//}
					}
					else if (!data.response)
					{
						console.log('Response from checkUpdate.php - updates disabled') ;
					}
					else if (!data.update)
					{
						console.log('Running latest version') ;
					}
				},
				error: function(xhr,status,message)
				{
					if(debug) console.log('error making ajax request to '+gmCheckUpdateScriptPath+'/checkUpdate.php with status='+status) ;
					if(debug) console.log('message='+message) ;
				},
			}
		) ;
		
	}
	
}

///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
//START OF SCRIPT
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////


///////////////////////////////////////////////////////////////////////////////
//Configure server paths
///////////////////////////////////////////////////////////////////////////////
var mav_config = new mav_config(GM_getResourceText('mavConfig')) ;

/**
 * @type string Absolute URI to the home page of Moodle server
 */
var moodleServer = mav_config.getMoodleServer() ;

/**
 * @type string Absolute URI to the location of balmi API scripts
 */
var mavServerApi = mav_config.getServerApi() ;

/**
 * @type string Absolute URI to the location of jQuery & jQuery-ui theme files
 */
var mavJqueryHtml = mav_config.getJqueryHtml() ;

/**
 * @type string Absolute URI to supporting HTML files for MAV (such as busy animation icon)
 */
var mavServerHtml = mav_config.getServerHtml() ;

/**
 * @type string Get version of MAV greasemonkey script
 */
var mavVersion = mav_config.getVersion() ; //GM_info.script.version ;

/**
 * @type balmi An instance of balmi object to interact with moodle page
 */
var balmi = new balmi(mav_config.getDebug()) ;

/**
 * @type MAVsettings User settings for MAV
 */
var MAVcourseSettings = new MAVsettings(balmi.getCourseId()) ;

//Let's overload some of its methods to cater for MAV's urlMode
//It's crude but will do for now
if (MAVcourseSettings.isUrlMode())
{
	balmi.balmi_getMoodleBaseUrl = balmi.getMoodleBaseUrl ;
	balmi.getMoodleBaseUrl = function()
	{
		return removeMavCommands(this.balmi_getMoodleBaseUrl()) ;
	}
	
	//Need to strip out the #mav: stuff
	balmi.balmi_relativeUrlString = balmi.relativeUrlString ;
	balmi.relativeUrlString = function(url)
	{
		var u = balmi.balmi_relativeUrlString(url) ;
		if (u === null)
			return null ;

		u = u.replace(/#mav:[01].*$/,'') ;
		return u ;
	}
	
	//Need to strip out the #mav: stuff
	balmi.balmi_getMoodleUrlStrings = balmi.getMoodleUrlStrings ;
	balmi.getMoodleUrlStrings = function(relative)
	{
		var links = balmi.balmi_getMoodleUrlStrings(relative) ;

		for(var i=0;i<links.length;i++)
		{
			links[i] = removeMavCommands(links[i]) ;
		}
		return links ;
	}
	
	balmi.balmi_getCoursePageElement = balmi.getCoursePageElement ;
	balmi.getCoursePageElement = function()
	{
		var l = this.balmi_getCoursePageElement() ;
		return removeMavCommands(l) ;
	}
}

/**
 * @type string Get version of balmi library
 */
var balmiVersion = balmi.getVersion() ;

//Turn on/off debugging
var debug = mav_config.getDebug() ;

if (debug)
{
	console.log('mavServerApi='+mavServerApi) ;
	console.log('mavJqueryHtml='+mavJqueryHtml) ;
	console.log('mavVersion='+mavVersion) ;
	console.log('userid='+balmi.getLoggedInUserIdNumber()) ;
	console.log('fullname='+balmi.getLoggedInUserFullname()) ;
}

//If there is no course home page link in the breadcrumbs, then this is not
//a course site in moodle (probably home page)
if(balmi.getCoursePageElement() == null)
	exit ;


///////////////////////////////////////////////////////////////////////////////
//Add jQuery and MAV CSS to page
///////////////////////////////////////////////////////////////////////////////
var jQueryCSS = GM_getResourceText("jQueryCSS") ;
addCSS(jQueryCSS) ;

if (debug) console.log('jquery css added') ;

var mavCSS = GM_getResourceText("mavCSS") ;
GM_addStyle(mavCSS) ;

///////////////////////////////////////////////////////////////////////////////
//Adding the dialogs to the page
///////////////////////////////////////////////////////////////////////////////
//Get the div for the dialogs
var mavInjectHtml = GM_getResourceText('mavInjectHtml') ;
$("body").append(mavInjectHtml);

//Set the src for the image in the selfupdate div
$("#MAVselfUpdateDialogImage").attr('src',mavServerHtml+'/'+$("#MAVselfUpdateDialogImage").attr('src')) ;

if (debug)
	console.log('Just before adding busy animation div') ;

///////////////////////////////////////////////////////////////////////////////
//Adding the busy animation to the page
///////////////////////////////////////////////////////////////////////////////
//Add the hidden div to the page, and set the src for the image inside the div
var busyAnimationDiv = GM_getResourceText('busyAnimationDiv') ;
$("body").append(busyAnimationDiv) ;
if (debug)
	console.log('Got after inserting busyanimationdiv') ;

$("#MAVbusyAnimationImage").attr('src',mavServerHtml+'/'+$("#MAVbusyAnimationImage").attr('src')) ;
if (debug)
	console.log('Got after updating src attribute for animation image') ;

//Configure div to show and hide during ajax calls
$(document).ajaxStart
(
	function()
	{
		$("#MAVbusyAnimationImage").show();
		//alert("Busy on") ;
	}
) ;
$(document).ajaxComplete
(
	function()
	{
		//close the loading animation
		$("#MAVbusyAnimationImage").hide();
	}
) ;

if (debug)
	console.log('Got after ajaxsetup') ;

///////////////////////////////////////////////////////////////////////////////
//Add Activity Viewer Links to page
///////////////////////////////////////////////////////////////////////////////
window.addEventListener ("load", function() {mavAddActivityViewerSwitch(balmi)}, false);

///////////////////////////////////////////////////////////////////////////////
//Add link to SSI in the Support block within course site
///////////////////////////////////////////////////////////////////////////////
//Commented out as no longer required
//window.addEventListener("load", function() {mavAddSSILink(balmi)}, false) ;

///////////////////////////////////////////////////////////////////////////////
//If activity viewer is turned on, then update the page
///////////////////////////////////////////////////////////////////////////////
window.addEventListener ("load", mavUpdatePage, false);

///////////////////////////////////////////////////////////////////////////////
//Self update - check for updates to the script on load if mav is off
//Self update - MAV calls mavSelfUpdate in requestData function as well
///////////////////////////////////////////////////////////////////////////////
if (!isMavOn())
	window.addEventListener ("load", mavSelfUpdate, false);

///////////////////////////////////////////////////////////////////////////////
//Bind functions for the dialog button clicks
///////////////////////////////////////////////////////////////////////////////

$("#MAVdisplayTextSize").bind("click", function() {
	$("#MAVdisplayColourLegend").hide();
	$("#MAVdisplaySizeLegend").fadeIn();
});
$("#MAVdisplayColour").bind("click", function() {
	$("#MAVdisplaySizeLegend").hide();
	$("#MAVdisplayColourLegend").fadeIn();
});


///////////////////////////////////////////////////////////////////////////////
//END OF PROGRAM
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////


