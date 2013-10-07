# Setup instructions for formr

#### Load MySQL schema
The schema is contained in schema.sql in the root folder and needs to be fed to the DB manually at this time.

#### Configuration
Rename the folder `config_default` to `config` and customise the database settings.

#### Set permissions
The following folders have to be writable: `backups` and `tmp`.

#### Work out paths
Depending on the configuration, you might have to tweak `define_root.php` and the various `.htaccess` files. 

## Problems rolling out?
* check htaccess config, commonly there are problems which can be fixed by appropriately setting RewriteBase
* is results_backups writable?
* define_root has a hardcoded path atm.
* internal server errors: check permissions (tmp, backups), case-sensitive paths, htaccess path trouble