# MAV Enterprise Installation Instructions #

This document is a guide to installing MAV-Enterprise with your Moodle LMS.

[TOC]

## Assumptions ##
This installation guide does assume that you have experience with the following technologies.  

1. UNIX or GNU/Linux
2. UNIX Shell
3. Postgresql
4. Apache
5. PHP

If you aren't confident that you can work with the above, seek assistance from a trusted expert, or contact the [creator of MAV](mailto:damo.clarky@gmail.com).

## Overview ##

MAV is comprised of two components:  

1. A client-side [userscript](https://github.com/OpenUserJs/OpenUserJS.org/wiki/Userscript-beginners-HOWTO) written using [Greasemonkey](https://addons.mozilla.org/en-US/firefox/addon/greasemonkey/) for the [Firefox](https://www.firefox.com) browser.
2. A web server written in [PHP](https://en.wikipedia.org/wiki/PHP) and served by [Apache](https://www.apache.org/) that has access to your Moodle Database (or a replica thereof).

Below is a visual representation of how MAV works:

![@Visual Representation of MAV Operation | center](https://www.evernote.com/l/AF1mjyGLgsVL_bKb7LPQP7X--nG84n8muTAB/image.png)

The blue boxes represent your existing Moodle components and their interaction.  While the yellow boxes represents how MAV sits alongside Moodle, both on a server, and on the teacher's web browser.  The MAV client userscript essentially applies a heat map visual over the top of your Moodle pages, after they load from the Moodle server.  

MAV can do this on any Moodle course page.
  
## Server Software Requirements ##

* Apache 2.4+
* Postgresql 9.1+
* PHP 5.4+
* Moodle 2.2+
* PHP Composer

If you are using RHEL/CentOS, then you can yum install the following packages:

* php
* php-cli
* php-ldap 
* php-mbstring 
* php-xmlrpc 
* httpd-tools 
* httpd 
* mod_ldap 
* mod_ssl 
* postgresql 
* postgresql-server 
* php-pgsql 
* git


```bash
$ sudo yum install php php-cli php-ldap php-mbstring php-xmlrpc httpd-tools httpd mod_ldap mod_ssl postgresql postgresql-server php-pgsql git
```

## Client Software Requirements ##

* [Firefox](https://www.firefox.com) 15+
* [Greasemonkey](https://addons.mozilla.org/en-US/firefox/addon/greasemonkey/) Firefox Add-on

**Note** A [Google Chrome](https://www.google.com.au/chrome/browser/desktop/) version using [Tampermonkey](https://tampermonkey.net/) is planned for the near future.

# Server Installation #

This section will focus on the server installation of MAV.  This can be performed on an existing Moodle server, or a completed separate server.  The only requirement is that the MAV server have the ability to connect to an update to date copy of (or directly to) the Moodle Database.  

For MAV to work, the server also requires access to the Moodle source code.  

## Install Moodle Source ##
MAV uses classes from the Event2 and Logging2 Moodle Frameworks to be able to parse the logstore and regenerate the original URLs accessed by the students.  Thus, a copy of the Moodle source code, commensurate with your production install is necessary.  If MAV is installed on your production Moodle server, MAV can share the existing Moodle source code.  It makes no changes to it.

In addition to the source code, the MAV server must also have access to your Moodle configuration file `config.php` in the top-level directory of your Moodle source.

It is important to note, however, that it is not necessary to 'serve' your Moodle source installation to the web if you are running MAV on a separate host to Moodle.  MAV only needs access to the code-base, and not via a web-interface.  So do not bother with configuring your Moodle code for Apache or whatever web server software you use if you install it on a dedicated MAV server.

Many organisations keep their own git repository of their Moodle installation.  This allows them to track their own customisations and configuration, and merge upstream releases from the official Moodle source.  

The following will install the vanilla version from Moodle, but you should clone from your own production Moodle source code if you have one.  This will ensure any Moodle plug-ins you are using will be available to MAV to process the logstore. 

Adapted from [Moodle.org](https://docs.moodle.org/31/en/Git_for_Administrators#Obtaining_the_code_from_Git)
```bash
$ cd /usr/local
$ sudo mkdir www
$ sudo chown `id -nu`:`id -nu` www
$ cd /usr/local/www
$ sudo mkdir moodle
$ sudo chown `id -nu`:`id -nu` moodle
$ git clone git://git.moodle.org/moodle.git                       
$ cd moodle
$ git branch -a                                                   
$ git branch --track MOODLE_31_STABLE origin/MOODLE_31_STABLE
$ git checkout MOODLE_31_STABLE
```

Next, copy your Moodle `config.php` file into your newly checked out moodle code.  The `config.php` file should contain the DB connection details for the Moodle DB you wish MAV to use.  This could be your production database, but often people choose to use a copy/replica.  An example of a config file is shown below:

```php
<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'moodle30';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = '';
$CFG->prefix	  = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => 5432,
  'dbsocket' => '',
);

$CFG->wwwroot   = 'https://moodle.server.com';
$CFG->dataroot  = '/usr/local/www/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

require_once(dirname(__FILE__) . '/lib/setup.php');
```

MAV can work with multiple Moodle sites, and of differing versions.  If you have more than one Moodle site, perform the same procedure as above for each Moodle site.  We will configure MAV to use these installs as necessary in a future step.

## Install MAV Server ##
Next, we will install the MAV software.  

First, we need to install the PHP package manager `Composer`

### Install Composer ###

Follow the [instructions](https://getcomposer.org/download/) provided by [getcomposer.org](getcomposer.org) to install.

Install composer to `/usr/local/bin` and make it executable.

```bash
sudo cp composer.phar /usr/local/bin/composer
sudo chmod a+x /usr/local/bin/composer
```

Assuming, the php command line interpreter is in your path (normally `/usr/bin/php` with distro versions), you will be able to run composer by simply typing:

```bash
$ composer --version
```

Next we need to install a dependent PHP Library for MAV called Ignition.

### Clone Ignition Library Git Repo ###

Ignition is simply a library that sets up the PHP environment for the given web app, according to environment variables in the web server configuration.  Using Apache for instance, ignition accesses `SetEnv` directives within the httpd.conf.  We will get to the Apache configuration files momentarily.

To install and configure Ignition:

```bash
$ cd /usr/local/www
$ sudo mkdir ignition
$ sudo chown `id -nu`:`id -nu` ignition/
$ git clone https://github.com/damoclark/ignition.git
$ cd ignition
$ composer install
```

Now that ignition and all its dependencies are installed, add ignition to the php path by editing the `/etc/php.ini` file and changing the following line:

```ini
;;;;;;;;;;;;;;;;;;;;;;;;;
; Paths and Directories ;
;;;;;;;;;;;;;;;;;;;;;;;;;

; UNIX: "/path1:/path2"
;include_path = ".:/php/includes"
;Add the following line
include_path = "/usr/local/www/ignition:/usr/share/pear:/usr/share/php"
;
```

### Clone the MAV Git Repo ###
```bash
$ cd /usr/local/www
$ sudo mkdir mav
$ sudo chown `id -nu`:`id -nu` mav
$ git clone https://github.com/damoclark/mav-enterprise.git mav
```

Now we have to install MAV's PHP dependencies using composer.

### Install PHP Dependencies for MAV ###
```bash
$ cd /usr/local/www/mav
$ composer install
```

### Create some caching directories required by MAV ###
MAV uses the PHP Smarty templating engine, and apache needs to be able to write compiled templates to a directory.  The following commands will create these directories, and make them writable by the Apache user.

```bash
$ cd /usr/local/www/mav/smarty
$ mkdir configs templates_c
$ chmod 755 configs templates_c
$ sudo chown apache:apache templates_c
```

## Configure MAV ##

In this step, we are going to configure MAV to use the Moodle install/s that were cloned in an [earlier step](#install-moodle-source). 

To do this, we will edit two configuration files.  They are the `{MAV}/etc/mav.ini` file and `{MAV}/etc/database.ini` file.  Let's start with `mav.ini`.  Copy the sample file and edit.

###  Configure mav.ini ###

The `mav.ini` file contains configuration settings about how MAV accesses your Moodle database, and how it aggregates student activity data.  You create start from a sample configuration file using the commands below.

```bash
$ cd /usr/local/www/mav/etc
$ cp mav.ini.sample mav.ini
$ vi mav.ini
```

The contents of the `mav.ini.sample` file are:

```ini
[moodle30]
; Moodle home page address for this Moodle site
moodle_home_url = "moodle.com/"
; Moodle software installation (local)
moodle_install = "/usr/local/www/moodle/"
; Logging system used (Moodle 2.6+) (standard|legacy)
moodle_logging = "standard"
; Moodle version (major.minor)
moodle_version = 3.0
; Moodle database table name prefix (DBPREFIX in config.php)
dbprefix = 'mdl_'
; Name of the stanza (section) in database.ini file to use to connect to the
; DB associated with the above moodle_url
pdodatabase = "MOODLE30"

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; The connected user from the pdodatabase option must have permission to:
; create, drop, select, update, delete, insert
; on these tables in their tablespace/schema
;
; Database schema defaults to 'mav'.  If you wish to use an alternate name,
; then you will need to adjust accordingly here.
;
; Name of the summary table in Moodle DB
table_summary = "mav.logstore_standard_log_summary"
; Name of the table used to update click counts (and renamed into table_summary)
table_update = "mav.logstore_standard_log_summary_update"
; Name of the summary state table in Moodle DB
table_state = "mav.logstore_standard_log_summary_state"
; Name of the batch table in Moodle DB
table_batch = "mav.logstore_standard_log_summary_batch"
; Name of the index on the summary table in Moodle DB
table_summary_index = "logstore_standard_log_summary_ix"
; Name of the index on the summary_update table in Moodle DB
table_update_index = "logstore_standard_log_summary_update_ix"

[moodle22]
; Moodle home page address for this Moodle site
moodle_home_url = "moodle-archive.com/"
; Moodle software installation (local)
; moodle_install Not needed for legacy logging system
moodle_install = ""
; Logging system used (Moodle 2.6+) (standard|legacy)
moodle_logging = "legacy"
; Moodle version (major.minor)
moodle_version = 2.2
; Moodle database table name prefix (DBPREFIX in config.php)
dbprefix = 'mdl_'
; Name of the stanza (section) in database.ini file to use to connect to the
; DB associated with the above moodle_url
pdodatabase = "MOODLE22"
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; The connected user from the pdodatabase option must have permission to:
; create, drop, select, update, delete, insert
; on these tables in their tablespace/schema
;
; Name of the summary table in Moodle DB
table_summary = "mdl_log_summary"
; Name of the index on the summary table in Moodle DB
table_summary_index = "mdl_log_summary_url_ix"
```

The sample contains two Moodle site entries.  One for an older version of Moodle (2.2) that used the older `mdl_log` table format for logging.  And a second entry for version 2.7 which use the new Logging2 framework and the `mdl_logstore_standard_log` table format.

If you decide to use the default table names for MAV, then you need only pay attention to the following settings:

```ini
[moodle30]
; Moodle home page address for this Moodle site
moodle_home_url = "moodle.com/"
; Moodle software installation (local)
moodle_install = "/usr/local/www/moodle/"
; Logging system used (Moodle 2.6+) (standard|legacy)
moodle_logging = "standard"
; Moodle version (major.minor)
moodle_version = 3.0
; Moodle database table name prefix (DBPREFIX in config.php)
dbprefix = 'mdl_'
; Name of the stanza (section) in database.ini file to use to connect to the
; DB associated with the above moodle_url
pdodatabase = "MOODLE30"
```

The stanza name (i.e. `[moodle30]`) should be descriptive of your Moodle site, especially if you have multiple Moodle sites.  `pdodatabase` points to the stanza name for your `{MAV}/etc/database.ini` file that contains the connection details for your Moodle database host.  Let's configure that next.

### Configure database.ini ###

The `database.ini` file is used by the helper library `ignition` to retrieve database connection and authentication information for MAV.  In this file, you will create a stanza for each Moodle database that MAV connects to.  The option `pdodatabase` from the `mav.ini` file specifies the stanza name containing the database connection details for that Moodle server.  

To begin, copy the sample file and edit as follows:

```bash
$ cd /usr/local/www/mav/etc
$ cp database.ini.sample database.ini
$ vi database.ini
```

The contents of the `database.ini.sample` file are:

```ini
[MOODLE30]
; moodle 3.0 database
adapter = pgsql
host = localhost
dbname = moodle30
port = 5432
username = moodle
password = ''

[MOODLE22]
; moodle 2.2 database
adapter = pgsql
host = localhost
dbname = moodle22
port = 5432
username = moodle
password = ''
```

As previously mentioned, the stanza names `MOODLE30` and `MOODLE22` match the `pdodatabase` option for each Moodle site in the `mav.ini` file.  Remove any extraneous entries in this file, and change the settings accordingly for your site.

### Configure MAV Userscript ###

The metadata at the top of the `www/moodleActivityViewer.user.js` user script tells Greasemonkey how to enact the userscript.  The existing metadata looks like so:

```javascript
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
// @include       http://mav/*
// ==/UserScript==
```

It is unnecessary to change any of these options with the exception of `@include`.  This option tells Greasemonkey on which website addresses this userscript should run.  You need to replace the existing `@include` examples in the `www/moodleActivityViewer.user.js` file with one that points to your Moodle main site.  Each of the examples has an asterisk `*` on the end.  This asterisk is a wildcard character, meaning it will match anything in its place within the url.  So by ending the `@include` option with an asterisk, the MAV userscript will execute on any page of the Moodle site.  This is what we want.  As an example, if your Moodle site is hosted at:

`https://moodle.cqu.edu.au`

Then the `@include` option will be given as:

`// @include      https://moodle.cqu.edu.au/*`

**Note** the two `//` at the start of the line with a space before the `@` are required.

## Configure Apache for MAV ##

The MAV server should be able to operate with any web server that supports PHP.  The MAV software includes a sample Apache configuration file that can be adapted to your environment.  If you choose to use a different web server platform, you will need to adapt the Apache configuration to that platform accordingly. 

The sample Apache configuration file is located in `{MAV}/etc/mav-apache.conf.sample`.  Copy the sample file and edit as follows:

```bash
$ cd /usr/local/www/mav/etc
$ sudo cp apache.conf.sample /etc/httpd/conf.d/mav.conf
$ cd /etc/httpd/conf.d
$ sudo vi mav.conf
```

Firstly, go through the file and ensure that all instances of `/usr/local/www/mav` point to where you installed mav in a [previous step](#clone-the-mav-git-repo). 

MAV presently uses HTTP Auth for authentication.  This means the browser will present a login dialog asking for a username and password whenever the MAV server is accessed by the MAV Greasemonkey Scripts.  This can be configured using whatever Apache authentication options you choose.  The sample configuration file includes options for authentication against an LDAP server.

If you have used the installation locations according to this guide, your `mav.conf` Apache configuration file should look something like:

```apache
#Apache 2.4
<Directory "/usr/local/www/mav/www">

#
# Possible values for the Options directive are "None", "All",
# or any combination of:
#   Indexes Includes FollowSymLinks SymLinksifOwnerMatch ExecCGI MultiViews
#
# Note that "MultiViews" must be named *explicitly* --- "Options All"
# doesn't give it to you.
#
#
    Options Indexes FollowSymLinks

#
# AllowOverride controls what directives may be placed in .htaccess files.
# It can be "All", "None", or any combination of the keywords:
#   Options FileInfo AuthConfig Limit
#
    AllowOverride none

#
# Controls who can get stuff from this server.
#
    Require all granted

    SetEnv APPDIR "/usr/local/www/mav"
    SetEnv PHP_INCLUDE_PATH "/usr/local/www/mav/lib"
    SetEnv SMARTYDIR "/usr/local/www/mav/smarty"
    SetEnv SMARTYSHAREDDIR "/usr/local/www/ignition/templates"
    #Not using ignition authentication - using http auth
    SetEnv AUTHCLASS "ignition_auth_http"
    SetEnv APPCONFCLASS "MavConfig"
    SetEnv APPCONF "etc/mav.ini"

    #Set debug level for development server
    #SetEnv DEBUG "9"
    
    #################################
    #################
    #This environment variable flags to the system that it is running on dev
    #box.  Do not set this env variable on production machine
    #SetEnv DEV "1"

    #Do not cache any greasemonkey file types - browser must download anew when updating
    #http://www.askapache.com/htaccess/using-http-headers-with-htaccess.html#100_Prevent_Files_cached
    <FilesMatch ".(html|js|json|css|php)$">
      FileETag None
      <ifModule mod_headers.c>
        Header unset ETag
        Header set Cache-Control "max-age=0, no-cache, no-store, must-revalidate"
        Header set Pragma "no-cache"
        Header set Expires "Wed, 11 Jan 1984 05:00:00 GMT"
      </ifModule>
    </FilesMatch>

</Directory>

<Directory /usr/local/www/mav/www/api>

    #Authentication Configuration for LDAP
    Require all granted

    AuthName "Moodle Activity Viewer"
    AuthType Basic
    AuthBasicProvider ldap
    AuthLDAPUrl ldap://ldap.server.com/ou=people?uid
    AuthLDAPGroupAttribute uniqueMember
    Require ldap-attribute affiliatetype=STAFF
    Satisfy any

    #Set path to .ini file for database configuration (used by PDOdatabase.php)
    SetEnv DBCONF "/usr/local/www/mav/etc/database.ini"
</Directory>

#Host MAV from /mav path on the server.  This is useful if hosting MAV on same
#host as Moodle
Alias /mav "/usr/local/www/mav/www"
```

**Note** the `Alias /mav` option will serve the mav installation page from `/mav` on your host.  You may wish to change this.

Also ensure that the `apache` UNIX user on your RHEL/CentOS distribution has permissions to your installation location for MAV.

```bash
$ cd /usr/local/www
$ chmod -R o+rx mav ignition
```

Now gracefully restart Apache to pick up the changes.  But it always helps to do a `configtest` first.  If there are any errors, address these before continuing.

```bash
$ sudo apachectl configtest
$ sudo apachectl graceful
```

## Configure Browscap in PHP ##
MAV detects the browser on the install page to see if the visitor is using a supported version of Firefox.  There will be future tests for compatible versions of Chrome.

Visit the [browscap.org](browscap.org) website, and download the [full_php_browscap.ini](https://browscap.org/stream?q=Full_PHP_BrowsCapINI) file and write it to ```/etc/```.  

Then in your ```/etc/php.ini``` file, add the following entry:

```ini
[browscap]
; http://php.net/browscap
browscap = /etc/full_php_browscap.ini
```

Now, do a graceful restart of Apache for mod_php to pick up the changes:

```bash
$ sudo apachectl graceful
```

## Create the MAV database tables ##

All things going well, you will now be able to create the database tables required by MAV.  If you receive any errors, go back and check previous steps to make sure you haven't missed something.

We are going to use the `initialise_db.php` script to create the tables.  Before doing this, you will need to create a separate schema within the destination database to hold the mav tables, separate from the schema used for Moodle which will be the `public` schema.  This means you can update your Moodle database schema with fresh copies from your production system, and still retain the MAV data tables all within the same database.  

```sql
create schema mav ;
```

The schema name should match the schema name given (the prefix before the period `.`) to the `table_summary`, `table_update`, `table_state`, `table_batch` settings in your `mav.ini` as [previously configured](#configure-mavini).  `mav` is used by default in this config file.  

**Note** If you are using mysql/mariadb, this database technology doesn't support schemas.  However, you are able to execute queries across databases.  So in this case, create a separate database for MAV and use the same `mav` prefix for the above `mav.ini` file options.

Now you can execute the `initialise_db.php` script.  If you want to see what it will actually do, before you run it, you can use the `--dry-run` option:

```bash
$ cd /usr/local/www/mav/bin
$ php initialise_db.php --dry-run --update=moodle30
```

`--update=moodle30` specifies the `moodle30` stanza from the `mav.ini` file [configured earlier](#configure-mav).  So you are specifying which Moodle database you wish to update.  The output will be the queries executed on the server.  When you are happy with the initialisation process, go ahead and run it:

```bash
$ php initialise_db.php --update=moodle30
```

## Restore Production Moodle DB into MAV Database ##
If MAV is accessing a copy of your Moodle database, then you will need to refresh this copy, perhaps on a nightly basis.  This is so MAV can update its activity counts.  To do this, schedule via cron, the `update_db.sh` shell script.

The script restores a `pg_dump` copy of the production Moodle
database into the Moodle database used by MAV.  At this point the script is Postgresql centric.  If you use another RDBMS, it will require adaptation.

More specifically, the script:

1. restores the latest backup copy of the production Moodle DB into
the public schema of the destination database.
2. It then drops the oldest backup schema copy of moodle
3. Then it renames the existing schema to a backup copy
4. Finally, it renames the public schema just restored to 'moodle' schema.

The `moodle` schema is what MAV will access according to your Postgresql `search_path`.

For the postgreql role that accesses this DB using MAV, it needs to have its
path set to include the schema 'moodle' rather than 'public' which is the default.  Also, including the schema name given to your mav tables in the []previous step](#create-the-mav-database-tables). To do this, and using the defaults in this guide, use the following command logged in as the postgresql role that will query the DB from MAV.

```sql
ALTER ROLE <role_name> in DATABASE <database_name> SET search_path TO moodle ;
```

Usage:

```bash
update_db.sh 'db backup filename' 'destination dbname'
```

Default values can be overridden with environment variables of same name e.g. dumpdir=/var/backup update_db.sh 'filename' 'dbname'

Once the `update_db.sh` shell script completes, the `update_db_summary.php` PHP script can be run to update the MAV totals.

## Update MAV Data from Moodle ##
MAV aggregates activity on Moodle resources and activities in its own tables.  This drastically improves performance.  The `mav_update_summary.php` performs this aggregation, and it should be scheduled to run via cron.  

The options for this script are as shown:

```bash
$ php mav_update_summary.php --help
```

```
Usage:
php mav_update_summary.php [--help] [--debug] [--jobs=1] [--progress] [--purge]
    [--rows-per-job=10000] --update=<SECTIONNAME>

Update MAV tables according to activity in moodle activity logs

Options:
--debug         Print extra debugging information while script running 
--help	        Print out this help
--jobs          Number of concurrent processes to aggregate the click activity
                data (default: 1)
--rows-per-job  How many rows from log to process per job (default: 10000)
--update        The section name from the mav.ini config file from which to
                the settings for the Moodle install to be updated (mandatory)
--progress      Output progress markers as each job completes its rows
--purge         Delete all existing MAV data and regenerate anew (this can take)
                a substantial amount of time with a large log table
--list          List the sections in the mav.ini configuration file for all
                the configured Moodle installs

Example:
/usr/bin/php mav_update_summary.php --debug --jobs=2 --update=MOODLE

This would run in debug mode, using 2 concurrent update tasks using the
configuration under the MOODLE section name in the etc/mav.ini configuration
file
```

When running the script, run as the apache user (if using RHEL or derivative such as CentOS). For example:

```bash
$ sudo -u apache php mav_update_summary.php --update=moodle30
```

If you have a substantial number of entries already existing in your activity log, then you can create multiple 'jobs' to do the processing concurrently.  This will speed up the initial run dramatically.  To do this, use the `--jobs` option.

This script should then be added to the apache user's crontab to be run nightly, or whenever makes sense.

```bash
$ sudo crontab -u apache -e
```

Enter the following if you wish to start the update at 4am, although it should be after any Moodle database restore procedure if MAV is not attached to your production Moodle database.

```
00 04 * * * php /usr/local/www/mav/bin/mav_update_summary.php --update=moodle30 | mail -s "MAV Update" adminuser@domain.edu.au
```

# Client Installation #

Now that the server software is installed and configured, we can install the software client components.  

FIrstly, we need to start the Firefox web browser.  If you don't have Firefox, [you can download](https://www.firefox.com) and install it quite easily.  

Next, using Firefox, navigate to your MAV server web address as per the [Configure Apache for MAV step](#configure-apache-for-mav).  It will look something like:

![@MAV Client Installation Page | center | 1280x0](https://www.evernote.com/l/AF34OdSeEoFEv7OVk-TN-Im9-_1UqV_oS-MB/image.png "MAV Client Installation Page")

Complete the first two steps on the installation page (install Greasemonkey, and Moodle Activity Viewer Plugin), and then you can navigate to your Moodle site.  

The menu to activate MAV will appear in  `Course administration` under the `Settings` block once you navigate to a Moodle course site.

Feedback or pull requests for this installation guide are most welcome. :)

