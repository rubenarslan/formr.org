# Setup instructions for formr

These are the instructions to run a local or online copy of the formr.org distribution. It is much easier to
install the [R package](https://github.com/rubenarslan/formr) if that's what you're looking for.
Those who don't mind running on a frequently-updated server, we will probably also give you access to our hosted version
at [formr.org](https://formr.org).

### Clone the Git repository
You'll need [Git](http://git-scm.com/) (a version management software). After installing it, navigate
to the folder where you want to place formr and run
	git clone https://github.com/rubenarslan/formr.org.git
	
You can also [download the repository as a zip file](https://github.com/rubenarslan/formr/archive/master.zip), but trust me, use Git
for this.

Once you've obtained the folder containing formr, do the following steps:

#### 1. Load MySQL schema
The database schema is contained in `schema.sql` in the root folder and needs to be fed to the DB manually at this time.

#### 2. Configuration
Duplicate (don't rename) the folder `config_default`, name it `config` and customise the database settings (and anything else
you want to change).

#### 3. Set permissions
The following folder (and its sub-folders) has to be writable: `/tmp`.

#### 4. Work out paths
Depending on your configuration, you might have to tweak `define_root.php` and the various `.htaccess` files. 

#### 5. Install Composer
[Composer](https://getcomposer.org/) is our package manager. Follow the instructions to install it locally or globally,
then run `composer install` so that all necessary packages are installed.

#### 6. Set up a cronjob on https://your_formr_host.com/admin/cron
It is probably easiest to call this script via curl (a.k.a the internet), because that way you circumvent the executing the script using the often inappropriately set up cron PHP distribution that might break paths, have a different version etc.

The cronjob is necessary to send automated email reminders and the like. If you're hoster doesn't offer cronjobs (some free hosters and universities don't), you can use a web-based cron. Some universities have those, there are free ones available too.

## Problems rolling out?
* check .htaccess config, commonly there are problems which can be fixed by appropriately setting `RewriteBase`
* is /tmp writable?
* define_root has a hardcoded path at the time.
* internal server errors: check permissions (tmp), case-sensitive paths, .htaccess path trouble
* [contact me](https://psych.uni-goettingen.de/en/biopers/team/arslan)