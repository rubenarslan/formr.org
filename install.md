# Setup instructions for formr

#### Load MySQL schema
The schema is contained in schema.sql in the root folder and needs to be fed to the DB manually at this time.

#### Configuration
Rename the folder `config_default` to `config` and customise the database settings.

#### Set permissions
The following folders (and their sub-folders) have to be writable: `backups` and `tmp`.

#### Work out paths
Depending on your configuration, you might have to tweak `define_root.php` and the various `.htaccess` files. 

#### Install Composer
[Composer](https://getcomposer.org/) is our package manager. Follow the instructions to install it locally or globally,
then run `composer install` so that all necessary packages are installed.

#### Set up a cronjob on https://your_formr_host.com/admin/cron
It is probably easiest to call this script via curl (a.k.a the internet), because that way you circumvent the executing the script using the often inappropriately set up PHP distribution that might break paths, have a different version etc.

The cronjob is necessary to send automated email reminders and the like. If you're hoster doesn't offer cronjobs (some free hosters and universities don't), you can use a web-based cron. Some universities have those, there are free ones available too.

## Problems rolling out?
* check htaccess config, commonly there are problems which can be fixed by appropriately setting RewriteBase
* is results_backups writable?
* define_root has a hardcoded path atm.
* internal server errors: check permissions (tmp, backups), case-sensitive paths, htaccess path trouble