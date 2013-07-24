# Zentrale Todo

### what to do when rolling out
* check htaccess config, commonly there are problems which can be fixed by appropriately setting RewriteBase
* is results_backups writable?
* define_root has a hardcoded path atm.
* internal server errors: check permissions (tmp, backups), case-sensitive paths, htaccess path trouble

### debugging todo
* how to upload places: export .sql file (nonzipped), with multiple values per statement (50000 lim), but not column names, zip manually, import.