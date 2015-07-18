#!/bin/sh

#Use this script if MAV operates out of a separate copy of the Moodle
#production database.

#The script restores a pg_dump copy of the production Moodle
#database into the copy used by MAV.  At this point it is Postgresql centric
#If you use another RDBMS, it will require adaptation
#
#It restores the latest backup copy of the production Moodle DB into
#the public schema of the destination database.
#It then drops the oldest backup schema copy of moodle
#Then renames the existing schema to a backup copy
#Then it renames the public schema just restored to 'moodle' schema
#
#Once this script completes, the update_db_summary.php script can be run to
#update the MAV totals.
#
#For the postgreql role that accesses this DB using MAV, it needs to have its
#path set to include the schema 'moodle' rather than 'public' which is the default
#To do this, use the following command logged in as the postgresql role that
#will query the DB from MAV
#ALTER ROLE <role_name> in DATABASE <database_name> SET search_path TO moodle ;

#Usage:
#update_db.sh 'db backup filename' 'destination dbname'

#Default values that can be overridden with environment variables of same name
#e.g. dumpdir=/var/backup update_db.sh 'filename' 'dbname'

test -z "$dumpdir" && dumpdir='.' #Current dir default
#Dump filename
test -z "$1" && /usr/bin/echo "No dumpFilename provided for this update" && exit 1
test -z "$2" && /usr/bin/echo "No dbname provided for this update" && exit 1
test -z "dumpretention" && dumpretention='+14' #Keep 14 days of dump files
#Connect with no options (or connect with pg user moodle to localhost)
#psqlOptions='-U moodle -h localhost'
test -z "$psqlOptions" && psqlOptions=' '
test -z "$psqlCommand" && psqlCommand='psql' #Find psql command on path
test -z "$pgrestoreCommand" && pgrestoreCommand='pg_restore' #Find pg_restore on path
#Options for pg_restore. eg. -O --no-privileges -j 4 
test -z "$pgrestoreOptions" && pgrestoreOptions='-O --no-privileges' #No owner or DB permissions restored
#Build psql command entirely with an alias
alias psqlCommand="$psqlCommand $psqlOptions "
#Build pgrestore command entirely with an alias
alias pgrestoreCommand="$pgrestoreCommand $psqlOptions $pgrestoreOptions "


echo "Importing production moodle database into Postgresql"

#Output current date and time
date

#Determine today's dumpfilename
echo -n "Determining dumpfile to import..."
dumpfile=$(ls $dumpdir/$dumpFilename|sort|tail -1) || exit 1 #If filename not exist exit

echo "$dumpfile"

test -r "$dumpfile" || /usr/bin/echo "Cannot open $dumpfile" && exit 1

echo "Dropping and recreating empty pubic schema"

#drop schema if exists public cascade
#create schema public
psqlCommand $psqlOptions $dbname <<EOF || exit 1
	--Terminate psql if there is an error with error code
  \set ON_ERROR_STOP on
	drop schema if exists public cascade ;
	create schema public ;
EOF

echo "Restoring $dumpfile into public schema"

#pg_restore
pgrestoreCommand -n public -d $dbname "$dumpfile" || exit 1

echo "Restore complete"

#Disconnect any existing connections (except our own)
#Using the postgres account via unix domain socket using ident authentication
$psqlCommand template1 <<EOF || exit 1
  --Terminate any existing connections to $dbname except ours
  select pg_terminate_backend(procpid)
  from pg_stat_activity
  where datname = '$dbname'
  and procpid <> pg_backend_pid() ;
EOF

echo "Dropping old schema, and renaming public into moodle"

#drop schema if exists moodle_2 cascade
#rename schema moodle_1 to moodle_2 ;
#rename schema moodle to moodle_1 ;
#rename schema public to moodle ;
psqlCommand $psqlOptions $dbname <<'EOF' || exit 1
  begin ;
	drop schema if exists moodle_2 cascade ;
DO $$
BEGIN
IF EXISTS
(
	SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'moodle_1' 
)
THEN
	alter schema moodle_1 rename to moodle_2 ;
END IF;

IF EXISTS
(
	SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'moodle'
)
THEN
	alter schema moodle rename to moodle_1 ;
END IF;

END$$;

	alter schema public rename to moodle ;
  commit ;
EOF


#Capture the exit code of psql command to see if rename was successful
success=$?

#Error out here if the rename failed
if [ $success -ne 0 ]
then
  echo "Renaming schemas failed"
  exit 1
fi

echo "Removing dump files $dumpretention days old"
if [ -z "$dumpdir" ]
then
  echo "Invalid dumpdir provided in script.  Cannot delete old dumpfiles"
  exit 1
fi

#Print the files to be deleted $dumpretention days or older
echo "Files to be removed..."
/bin/find "$dumpdir" -type f -mtime "$dumpretention" -ls
#Delete them
/bin/find "$dumpdir" -type f -mtime "$dumpretention" -print0|xargs -0 -r rm 
echo "Done!!!"
date


