// ==UserScript==
// @name          Browser Addon Library for Moodle Interface
// @namespace	    http://damos.world
// @description	  A library of classes for GM scripts to re-render Moodle pages 
// @version       2.7.0.1
// @copyright     GPL version 3; http://www.gnu.org/copyleft/gpl.html
// #exclude       *
// ==/UserScript==




/**
 * Balmi - Browser Addon Library for Moodle Interface - Greasemonkey class for manipulating Moodle pages
 * 
 * @param   {boolean} If true, turn on debug logging mode, default false
 * 
 */
function balmi(d)
{
	if (d === undefined)
		d = false ;

	//////////////////////////////////////////////////////////////////////////////
	// Instance variables
	//////////////////////////////////////////////////////////////////////////////

	/**
	 * @type boolean Whether to generate debugging output from balmi class
	 */
	var debug = d ;
	
	/**
	 * @type string URL for home page of current course site
	 */
	var coursePageElement = null ;
	
	/**
	 * @type string Version of balmi javascript library
	 */
	var balmiVersion = '2.7.0.1' ;
	
	/**
	 * @type string CSS for styling menu links in blue so look like real links
	 */
	var menuCSS = 'a.makealink:visited { color: #818600 !important; } a.makealink:hover {	color: #c6006f !important; }' ;

	//////////////////////////////////////////////////////////////////////////////
	//Constructor initialisation
	//////////////////////////////////////////////////////////////////////////////
	
	//Add css to page for styling menu links in blue
	GM_addStyle(menuCSS) ;
	
	
	
	
	//////////////////////////////////////////////////////////////////////////////
	//Methods
	//////////////////////////////////////////////////////////////////////////////
	
	/**
	 * Returns the version of this balmi library file
	 * 
	 * @param   {Type} this Description
	 * 
	 * @returns {string} Version string of this library
	 */
	this.getVersion = function()
	{
		return balmiVersion ;
	}
	
	/**
	 * Returns the moodle DB courseid from the course page url
	 * 
	 * @returns {Integer} The courseid or null
	 */
	this.getCourseId = function()
	{
		var link = this.getCoursePageElement() ;
		if(debug) console.log('course homepage link='+link) ;
		if(link == null)
			return null ;
		var params = this.parseQueryString(link) ;
	
		if(debug) console.log('courseid='+params.id) ;
	
		return params.id ;
	}

	/**
	 * Method to parse given url and extract as an object the query parameters
	 * 
	 * @param   {string} function The url to parse
	 * 
	 * @returns {Object} An object with properties names as params and matching values
	 */
	this.parseQueryString = function(url)
	{
		var nvpair = {};
		var qs = url.search.replace('?', '');
		var pairs = qs.split('&');
		for(var i = 0; i < pairs.length; i++)
		{
			var pair = pairs[i].split('=') ;
			nvpair[pair[0]] = pair[1];
		}
		return nvpair;
	}

	/**
	 * This method will return as an Element the url to the base level of the
	 * entire Moodle website, without the my/ string on the end
	 * 
	 * @returns {Element} The base url of the Moodle website
	 */
	this.getMoodleBaseUrl = function()
	{
		var home = this.getMoodleHomePageBreadcrumbElement() ;
		var a = document.createElement('a') ;
		a.href = home.href ;

		//If there is only a 'my home' at the base of the breadcrumb, remove
		//the my bit from the url
		a.pathname = a.pathname.replace(/(?:my\/?)?$/,"") ;

		return a ;
	}
	
	/**
	 * This method will return a mutable in page Element in the breadcrumbs
	 * linking to the home page for the Moodle site
	 * 
	 * @returns {Element} Element object in page linking to Moodle home page
	 */
	this.getMoodleHomePageBreadcrumbElement = function()
	{
		//Find the breadcrumbs div
		var breadcrumbs = document.getElementsByClassName('breadcrumb')[0] ;
		var home = breadcrumbs.getElementsByTagName('a')[0] ;
		return home ;
	}
	
	/**
	 * This method will return the mutable in page Element in the breadcrumbs
	 * linking to the home page for the current Moodle course site
	 * 
	 * @returns {Element} Element object representing the URL to the course home page for this page's course
	 */
	this.getCoursePageElement = function()
	{
		if (coursePageElement != null)
			return coursePageElement ;

		//Find the breadcrumbs div
		var breadcrumbs = document.getElementsByClassName('breadcrumb')[0] ;

		//and then find all the child a tags
		var courseLink = breadcrumbs.getElementsByTagName('a') ;

		//and finally iterate over the a tags, and match the one that matches a course home page
		//and finally get the last one (that will be)
		for (var i=0; i < courseLink.length; i++)
		{
			//Only match the course url on the pathname and query parameters
			var s = courseLink.item(i).pathname + courseLink.item(i).search ;
			if(s.match(/\/course\/view\.php\?id=\d+$/))
			{
				courseLink = courseLink.item(i) ;
				break ;
			}
		}
		//courseLink = courseLink.item(courseLink.length-1) ;
		if (courseLink instanceof HTMLCollection)
		{
			if(debug)
				console.log('Not on course page') ;
			return null ;
		}

		if(debug)
			console.log('courselink='+courseLink) ;
		coursePageElement = courseLink ;
		
		return courseLink ;
	}

	/**
	 * Get the shortname for the current Moodle course site
	 * 
	 * @returns {string} The shortname as a string
	 */
	this.getCourseCode = function()
	{
		var courseLink = this.getCoursePageElement() ;
		var courseCode = courseLink.innerHTML ;
		if(debug)
			console.log('coursecode='+courseCode) ;
		return courseCode ;
	}
	
	/**
	 * This method returns true if the given url points to this moodle site
	 * 
	 * @param   {Element} url Url to test
	 * 
	 * @returns {boolean} True if url points to this moodle site otherwise false
	 */
	this.isMoodleUrl = function(url)
	{
		var home = this.getMoodleBaseUrl() ;
		
		//if url host & port matches and url pathname starts with home pathname
		//then its a moodle link for this moodle site
		return (home.host === url.host && url.pathname.indexOf(home.pathname) === 0) ;
	}

	/**
	 * Given the url Element object, return a string relative to the home page
	 * of the moodle site (e.g. http://moodle.com/moodle27/course/index.php?id=1
	 * becomes /course/index.php?id=1
	 * 
	 * @param   {Element} url The Moodle url to make into relative string
	 * 
	 * @returns {string} Relative to the home page of moodle site
	 */
	this.relativeUrlString = function(url)
	{
		var value = null ;
		
		if (this.isMoodleUrl(url))
		{
			var home = this.getMoodleBaseUrl() ;
			//Remove trailing slash, so it doesnt get removed from start of our relative url 
			var pathname = home.pathname.replace(/\/$/,'') ;

			value = url.pathname.replace(pathname,'') + url.search + url.hash ;
		}
		
		return value ;
	}
	
	/**
	 * Returns an object where property names are string representations of the
	 * Element objects, and the property values are the Element objects themselves
	 *
	 * These are mutable in-page Element objects that can change the page
	 *
	 * @param {Boolean} relative If true, then object property names will be string url relative to the Moodle home page, otherwise they will be the absolute url (default true)
	 * 
	 * @returns {Object} An object with property names as links and values as Element objects
	 */
	this.getMoodleElementsAsObject = function(relative)
	{
		if (relative === undefined)
			relative = true ;

		//Get all a links
		var allLinks = document.getElementsByTagName("a") ;
		//@todo investigate use of CSS3 selectors in javascript instead of DOM API
		//var allLinks = document.querySelectorAll("a")
		
		//Use an object, so resetting property names wont create duplicates
		var links = {} ;
		
		for (var i=0; i < allLinks.length; i++)
		{
			//Skip if not a moodle element
			if (!this.isMoodleUrl(allLinks[i]))
				continue ;

			//Get relative or absolute string of url
			var r = (relative) ? this.relativeUrlString(allLinks[i]) : allLinks[i].href ;

			//Store as property
			links[r] = allLinks[i] ;
		}
		
		return links ;
	}

	/**
	 * Returns an array of unique Element objects representing links to Moodle
	 * within the current page
	 * 
	 * Like getMoodleElementsAsObject, this method returns mutable in-page
	 * Element objects that can change the page
	 *
	 * @returns {array} An array of unique Element objects
	 */
	this.getMoodleElements = function()
	{
		//Return object property values as an array
		var o = this.getMoodleElementsAsObject(false) ;
		return Object.keys(o).map(function(k){return o[k]}) ;
	}
	
	/**
	 * Traverse current moodle page and retrieve all links back to Moodle as
	 * an array of strings
	 *
	 * @param {Boolean} relative If true, links will be relative to Moodle home page url, otherwise will be absolute (default: true)
	 * 
	 * data structure returned looks like an array of the form for each link
	 * where the relative option is true
	 * [
	 *   "/mod/forum/view.php?id=12345",
	 *   "/mod/page/view.php?id=12345"
	 * ]
	 * 
	 * @returns {array} List of Moodle links relative to moodle home page url
	 */
	this.getMoodleUrlStrings = function(relative)
	{
		if (relative === undefined) 
			relative = true ;

		var links = Object.getOwnPropertyNames(this.getMoodleElementsAsObject(relative)) ;

		return links ;
	}

	/**
	 * This method will scrape the moodle page and return the m_user id number for
	 * the currently logged in user
	 *
	 * @returns {integer} m_user id number for currently logged in user
	 */
	this.getLoggedInUserIdNumber = function()
	{
		var a = document.getElementsByClassName('logininfo')[0].children[0] ;
		var id = a.search.replace(/.*id=(\d+)$/,"$1") ;
		
		return id ;
	}
	
	/**
	 * This method will scrape the moodle page and return the full name for
	 * the currently logged in user
	 *
	 * @returns {string} full name for currently logged in user
	 */
	this.getLoggedInUserFullname = function()
	{
		var a = document.getElementsByClassName('logininfo')[0].children[0] ;
		var fullname = a.innerHTML ;

		return fullname ;
	}
	
	/**
	 * This method will add html to an existing block in the Moodle page
	 *
	 * Example blocks and their class names
	 *
	 * QUICKMAIL - block_quickmail
	 * EVALUATION - block_evaluation
	 * LATEST NEWS - block_news_items
	 * 
	 * @param   {string} function The unique html class name associated with the block
	 * @param   {element} html     DOM element object to be appended to contents of block
	 *
	 * @returns {boolean} True if the html was added to the block otherwise false
	 */
	this.addToBlock = function(blockclassname,html)
	{
		try
		{
			var tmp = document.createElement("p");
			tmp.appendChild(html) ;
			supportDiv = document.getElementsByClassName(blockclassname)[0].getElementsByClassName('content')[0] ;
			supportDiv.appendChild(tmp) ;
		}
		catch(error)
		{
			return false ;
		}
		return true ;
	}

	/**
	 * Method for inserting menu into page
	 *
	 * For example:
	 * <code>
 	 *	var menuConfig = {
	 *		settings_menu:
	 *		[
	 *			{
	 *				text: 'Activity Viewer',
	 *				listeners: { click: null, mouseover: null },
	 *				submenu:
	 *				[
	 *					{
	 *						id: 'mav_activityViewerElement', //id property for the url a tag
	 *						text: //Toggle option
	 *						{
	 *							on:  'Turn Activity View Off',
	 *							off: 'Turn Activity View On'
	 *						},
	 *						toggle: isMavOn(), //Internal state of toggle - 'on' text will be displayed
	 *						image: 'http://moodle.server.com/theme/image.php?theme=theme1&image=i%2Fnavigationitem&rev=391', //Custom moodle menu node icon - omit to use default
	 *						title: 'Toggle Activity Viewer',
	 *						listeners: { click: mavSwitchActivityViewer }
	 *					},
	 *					{
	 *						text: 'Activity Viewer Settings',
	 *						title: 'Activity Viewer Settings',
	 *						image: 'http://moodle.server.com/theme/image.php?theme=theme1&image=i%2Fnavigationitem&rev=391', //Custom moodle menu node icon - omit to use default
	 *						listeners: { click: mavDisplaySettings }
	 *					}
	 *				]
	 *			}
	 *		]
	 *	} ;
	 *	
	 *	balmi.insertMenu(menuConfig) ;
	 * </code>
	 * 
	 * @param   {object} menu Menu structure
	 * 
	 */
	this.insertMenu = function(menu)
	{
		if (menu == null)
			throw "No menu object provided" ;
	
		for(var menuitem in menu)
		{
			if(debug)
			{
				console.log('Working on menuitem '+menuitem) ;
				console.log(menu[menuitem]) ;
			}
			var items = parseMenu(menu[menuitem]) ;
			
			if (menuitem == 'settings_menu')
			{
				//Add to the course administration block
				//Get the settingsnav div,
				//then the first ul element,
				//then the first li element,
				//then the first ul element.
				//Then append the menu HTML to what is already there
				var menuList = document.getElementById('settingsnav').getElementsByTagName('ul')[0].getElementsByTagName('li')[0].getElementsByTagName('ul')[0] ;
	
				for (var i=0; i < items.length; i++)
				{
					var tmp = document.createElement("div");
					tmp.appendChild(items[i]);
					if(debug)
						console.log(tmp.innerHTML)
					menuList.appendChild(items[i]) ;
					if(debug)
						console.log('added '+items[i]+' to page') ;
				}
				mavSetMenuElementText() ;
			}
		}
	}

	/**
	 * Parse menu object and generate HTML to be inserted into Moodle Page
	 * 
	 * @param   {object} moodleMenu Menu structure
	 * 
	 * @returns {Array} Array of DOM HTML Elements (li) to be inserted into Moodle Menu
	 */
	function parseMenu(menuitem)
	{
		var elements = [] ;
		if(debug)
			console.log('Inside parseMenu looking at '+menuitem.text) ;
		
		//Iterate over all menu items (eg. settings and/or navigation)
		for (var i=0; i < menuitem.length; i++)
		{
			if(debug)
				console.log('working on '+menuitem[i]) ;
			//Create the outer layer list item
			var li = document.createElement('li') ;
			if (menuitem[i].hasOwnProperty('submenu')) //Then this is a menu entry only
			{
				if(debug)
					console.log('has submenu') ;
				li.setAttribute('class','type_unknown contains_branch collapsed') ;
				var p = document.createElement('p') ;
				p.setAttribute('class','tree_item branch') ;
				var span = document.createElement("span") ;
				span.setAttribute('tabindex','0') ;
				
				//Set the menu name
				span.innerHTML = menuitem[i].text ;
				//If the menu item has a title, assign it
				if (menuitem[i].hasOwnProperty('title')) 
					span.setAttribute('title',menuitem[i].title) ;
				p.appendChild(span) ;
				li.appendChild(p) ;
				
				//Now, recurse through child nodes in the menu and add them to the main
				//menu li element
				var ul = document.createElement("ul") ;
	
				if(debug)
				{
					console.log('submenu count = '+menuitem[i].submenu.length) ;
					console.log(menuitem[i].submenu) ;
				}
				var children = parseMenu(menuitem[i].submenu) ;
				for (var k=0; k < children.length; k++)
				{
					ul.appendChild(children[k]) ;
				}
				
				//for (var j=0; j < menuitem[i].submenu.length; j++)
				//{
				//	console.log(menuitem[i].submenu[j]) ;
				//	var children = this.parseMenu(menuitem[i].submenu[j]) ;
				//	for (var k=0; k < children.length; k++)
				//	{
				//		ul.appendChild(children[k]) ;
				//	}
				//}
				li.appendChild(ul) ;
			}
			else //Its just a single menu heading
			{
				if(debug)
					console.log('does not have submenu') ;
				//Set li style as a clickable menu item
				li.setAttribute('class','type_setting collapsed item_with_icon') ; 
				var p = document.createElement("p") ;
				p.setAttribute('class','tree_item leaf') ;
				var a = document.createElement("a") ;
				
				//Set url for menu item
				if (menuitem[i].hasOwnProperty('url'))
					a.setAttribute('href',menuitem[i].url) ;
				else //If no href, then make the a look like a link (blue in css)
					a.setAttribute('class','makealink') ; 
				//Set title for menu item
				if (menuitem[i].hasOwnProperty('title'))
					a.setAttribute('title',menuitem[i].title) ;
				//Set id for menu item
				if (menuitem[i].hasOwnProperty('id'))
					a.setAttribute('id',menuitem[i].id) ;
				
				//Set event handlers
				for (var event in menuitem[i].listeners)
				{
					if(debug)
						console.log('adding event '+event+' with listeners') ;
					a.addEventListener(event,menuitem[i].listeners[event]) ;
				}
				
				//Icon for little dot next to menu items
				var navigationitemIcon =
					'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAADhJREFUeNpiYBgFFANGbIIzZ858D6QE0IQ/pKenC6KrZcJhsACRYjgNIBoMXgM+ECk2CqgBAAIMAFgLBwwGlkLjAAAAAElFTkSuQmCC' ;
				var img = document.createElement('img') ;
				img.setAttribute('class','smallicon navicon') ;
				if (menuitem[i].hasOwnProperty('image'))
					img.setAttribute('src',menuitem[i].image) ;
				else
					img.setAttribute('src',navigationitemIcon) ;
				a.appendChild(img) ;
	
				//Add link to paragraph
				p.appendChild(a) ;
				//Add paragraph to list item
				li.appendChild(p) ;
				
				//Add link text to a tag
				var text = '' ;
				if (menuitem[i].hasOwnProperty('toggle'))
				{
					if(debug)
						console.log('This menu item is a toggle') ;
					//If toggle is true, then use the on property as text for menu item
					//otherwise, use of the off property
					text = (menuitem[i].toogle) ? menuitem[i].text.on : menuitem[i].text.off ;
				}
				else //Its just a single menu heading
				{
					text = menuitem[i].text ;
				}
				var textNode = document.createTextNode(text)
	
				a.appendChild(textNode) ;
			}
			var tmp = document.createElement("div");
			tmp.appendChild(li);
			if(debug)
				console.log(tmp.innerHTML)
	
			elements.push(li) ;
		}
		
		return elements ;
	}

	
	
}

