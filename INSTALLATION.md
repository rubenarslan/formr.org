# Setup instructions for formr

formr can run on Linux, Mac OS and Windows. However we recommend running formr on a linux environment. The installation instructions detailed
below are for a Debian 9 Environment but can be modified accordingly for other platforms.

## Installing [R](http://www.r-project.org/) and [OpenCPU](https://public.opencpu.org/pages/)

### Install R

You can install and set-up the R software by following the [installation instructions](https://cran.r-project.org/bin/linux/) on the r-project website. The minimal Version required by OpenCPU ist 3.4.4.

The best way is to use the official R repository.

```
# CRAN server for Debian stretch (R and related stuff)
deb http://cran.rstudio.com/bin/linux/debian stretch-cran34/
```

Add it to the _/etc/apt/sources.list_.

### Install OpenCPU

Visit the [OpenCPU](https://github.com/jeroenooms/opencpu/) repository and follow the [installation instructions](https://github.com/jeroenooms/opencpu/blob/master/README.md) there on how to set up and configure OpenCPU.

```
sudo add-apt-repository -y ppa:opencpu/opencpu-2.0
sudo apt-get update 
sudo apt-get install opencpu-server rstudio-server
```

For now, there is no systemctl service script to run opencpu. You'll need to run R in a screen or tmux session:

```
$ R
> library(openCpu)
> ocpu_start_server()
```

### Install and using the formr R-package

Visit the [formr R-package repository](https://github.com/rubenarslan/formr) for installation and set up instructions.

Basically run:

```
sudo R
> install.packages("devtools")
> devtools::install_github("rubenarslan/formr")
```

## Installing an instance of the formr website

These are the instructions to run a local or online copy of the formr.org distribution. It is much easier to
install the [R package](https://github.com/rubenarslan/formr) if that's what you're looking for.
Those who don't mind running on a frequently-updated server, we will probably also give you access to our hosted version
at [formr.org](https://formr.org).

### 0. Requirements

The following requirements should be installed on the system you intend to install formr on:

* [Git](http://git-scm.com/) (for installation)
* PHP >= 5.6
	* composer
	* php-curl
	* php-fpm (often: php7.x-fpm e. g. php7.2-fpm)
	* php-mbstring (often: php7.x-mbstring e. g. php7.2-mbstring)
	* php-mysql
	* php-zip
	* php-xml
	* php-gd
	* php-intl
	* pandoc (not needed in devel branch for libsodium23)
* Apache >= 2.4
* MySQL / MariaDB >= 5.6
* [Composer](https://getcomposer.org/) (for installing dependencies)
* [The Sodium crypto library (Libsodium)](https://paragonie.com/book/pecl-libsodium/read/00-intro.md#installing-libsodium)
	* The repository version of libsodium is currently incompatible to formR. Use [these instructions](https://github.com/paragonie/halite/issues/48) to set it up.
	* The Branch devel supports libsodium23 v1.0.16 which is the default version on most current distributions.
* [Gearman](http://gearman.org/) (Server + Client) *OPTIONAL* (for running background jobs)
* [Supervisor](http://supervisord.org/) *OPTIONAL*
* [smysqlin](https://bitbucket.org/cyriltata/smysqlin) *OPTIONAL* (for managing database patches)

Paket list for copying:

```
sudo apt-get install git php apache2 mysql-server composer php-curl php-fpm php-mbstring php-mysql php-zip php-xml php-gd php-intl pandoc
```

Install libsodium now. See instructions above.

Apache needs the rewrite mod enabled:

```sh
    sudo a2enmod rewrite
```

And overrides need to be allowed for the virtual host. On a standard Ubuntu or Debian installation, insert the following block at the end of your default virtual host in `/etc/apache2/sites-enabled/000-default.conf`.

```
	<Directory /var/www/html>
		Options Indexes FollowSymlinks MultiViews
		AllowOverride All
		Order allow,deny
		allow from all

	</Directory>
```

Make sure apache2 and php7.x-fpm run.

### 1. Clone the Git repository and checkout the *desired* release (version)

The suggested file structure is as follows: Place formr.org's folder, e. g. `/var/www`, accessible for apache's user e. g. `www-data` and to create a symlink to the webroot.

You'll need [Git](http://git-scm.com/) (a version management software). After installing it, navigate to the folder where you want to place formr and run
```sh
    cd /var/www/
    git clone https://github.com/rubenarslan/formr.org.git #depending on the system you might need sudo for this
```

Create the symlink and fix access rights:

```sh
    ln -s /var/www/formr.org/webroot /var/www/html/formr 
    chown -R www-data:www-data /var/www/formr.org
```

You can also [download the repository as a zip file](https://github.com/rubenarslan/formr/archive/master.zip), but trust me, use Git for this.

To see the existing releases of formr, go to https://github.com/rubenarslan/formr.org/releases. It is recommended to run a release of formr for easy update an maintenance. To run a release use the command
```sh
    git fetch --tags && git checkout <release> -b <release>
```
Suppose for example you want to run release *v0.12.0* `git fetch --tags && git checkout v0.12.0 -b v0.12.0`

At this point you should have your formr files present in the installation directory. Go to the root of the installation directory and install the application dependencies by running

```sh
    composer install
```
	
 
### 2. Create an empty MySQL database

Login to mysql server with a user that has appropriate privileges and execute these commands to create the database

```sh
    CREATE DATABASE formr DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;
    GRANT ALL PRIVILEGES ON formr.* TO formr@localhost IDENTIFIED BY 'EnterPassword';
    FLUSH PRIVILEGES;
    quit;
```
Import the initial required database structure

```sh
    mysql formr -uformr -pEnterPassword < /path/to/formr/sql/schema.sql
```

__You'll need to apply patchset sql/patches/028_add_user_attributes.sql manually.__

Optionally, you could use [smysqlin](https://bitbucket.org/cyriltata/smysqlin) to set up and manage patches to the formr mysql database.
SQL patches are created with updates and found in the directory `/path/to/formr/sql/patches`. Any patch will be announced in the update release and you can either run this patch directly against your database or use smysqlin.

### 3. Configuration

#### Create the config folder

* Duplicate *(don't rename)* the folder `config-dist`, name it `config`.
* Edit the /path/to/formr/config/settings.php to configure the right values for the various config items. The comments in the config file should help you identify the meaning of the config items. Some common items you need to modify are
  * database connection parameters
  * the OpenCPU instance to you
  * the application timezone
  * email SMTP configuration
  * cron or deamon settings depending on how you want to process jobs in the background

#### Installing the formr cron and/or deamon

In other to process sessions in the background, you will have to setup the formr cron OR the formr deamon *BUT NOT BOTH*. The cronjob is necessary to send automated email reminders and the like.

* To install the formr crontab in a linux system, uncomment the cron command you will like to use in `/path/to/formr/config/formr_crontab` and then
create a symbolic link to install the formr crontab in the system's crontab configuration 
```sh
    ln -s /path/to/formr/config/formr_crontab /etc/cron.d/formr
```
* To use the formr deamon, you will need to install and setup [Gearman](http://gearman.org/) and [Supervisor](http://supervisord.org/).
formr comes with a programs supervisor config in `/path/to/formr/config/supervisord.conf`. Edit this file to suit your needs. This config can be added to supervisor's default
config by creating a symbolic link as follows or moving the config file to supervisor's default config directory
```sh
    ln -s /path/to/formr/config/supervisord.conf /etc/supervisor/conf.d/formr.conf
```

#### Configure smysqlin for installing db patches
If you decided to use [smysqlin](https://bitbucket.org/cyriltata/smysqlin) to manage your database patches. The you could setup a formr configuration
for smysqlin using the file in `/path/to/formr/config/formr.ini`. Modify it accordingly and add it to the smysql in configuration by copying file or creating a symbolic link
```sh
    ln -s /path/to/formr/config/formr.ini /etc/smysqlin/formr.ini
```

#### Set paths and permissions
The followings folders (and their sub-folders) have to be writable: `/tmp` and `/webroot/assets/tmp`.
You may need to modify the `.htaccess` files to suit your needs.
See config item `define_root` to specify installation path, url and test mode.

### 4. Done
You should be able to see your installation up and running by visiting the configured URL.


## Problems rolling out?
* check .htaccess config, commonly there are problems which can be fixed by appropriately setting `RewriteBase`
* is /tmp writable?
* define_root has a hardcoded path at the time.
* internal server errors: check permissions (tmp), case-sensitive paths, .htaccess path trouble
* [contact me](https://psych.uni-goettingen.de/en/biopers/team/arslan)
* If your layout seems broken, disable developer mode in the _settings.php_
