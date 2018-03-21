# Setup instructions for formr

formr can run on Linux, Mac OS and Windows. However we recommend running formr on a linux environment. The installation instructions detailed
below are for a linux Environment but can be modified accordingly for other platforms.

## Installing [R](http://www.r-project.org/) and [OpenCPU](https://public.opencpu.org/pages/)

### Install R

You can install and set-up the R software by following the [installation instructions](https://cran.r-project.org/bin/linux/) on the r-project website.

### Install OpenCPU

Visit the [OpenCPU](https://github.com/jeroenooms/opencpu/) repository and follow the [installation instructions](https://github.com/jeroenooms/opencpu/blob/master/README.md) there on how to set up and configure OpenCPU.

### Install and using the formr R-package

Visit the [formr R-package repository](https://github.com/rubenarslan/formr) for installation and set up instructions.


## Installing an instance of the formr website

These are the instructions to run a local or online copy of the formr.org distribution. It is much easier to
install the [R package](https://github.com/rubenarslan/formr) if that's what you're looking for.
Those who don't mind running on a frequently-updated server, we will probably also give you access to our hosted version
at [formr.org](https://formr.org).

### 0. Requirements

The following requirements should be installed on the system you intend to install formr on:

* [Git](http://git-scm.com/) (for installation)
* PHP >= 5.4
* Apache >= 2.4
* MySQL >= 5.6
* [Composer](https://getcomposer.org/) (for installing dependencies)
* [The Sodium crypto library (Libsodium)](https://paragonie.com/book/pecl-libsodium/read/00-intro.md#installing-libsodium)
* [Gearman](http://gearman.org/) (Server + Client) *OPTIONAL* (for running background jobs)
* [Supervisor](http://supervisord.org/) *OPTIONAL*
* [smysqlin](https://bitbucket.org/cyriltata/smysqlin) *OPTIONAL* (for managing database patches)

### 1. Clone the Git repository and checkout the *desired* release (version)

You'll need [Git](http://git-scm.com/) (a version management software). After installing it, navigate to the folder where you want to place formr and run
```sh
    git clone https://github.com/rubenarslan/formr.org.git
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