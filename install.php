<?php
/*
 * Installer for Database tables
 * admin
 * emails
 * itemdisplay
 * items
 * message_queue
 * results
 * snresults
 * studies
 * substitutions
 * times
 * vpndata
 */
require ('includes/settings.php');
require ('includes/mysql.php');
require ('includes/functions.php');
require ('admin/includes/functions.php');

$table_exists = false;

// test if tables exist already
$tables = array(ADMINTABLE,EMAILSTABLE,ITEMDISPLAYTABLE,
ITEMSTABLE,MESSAGEQUEUE,
RESULTSTABLE,SNRESULTSTABLE,STUDIESTABLE,SUBSTABLE,TIMESTABLE,VPNDATATABLE);

foreach ($tables as $table) {
    if( !table_exists($table) ) {
      create_table($table);		
    }
}

$base_url = substr($_SERVER['SCRIPT_NAME'], 0, strrpos($_SERVER['SCRIPT_NAME'], "/")+1);
$url = "http://" . $_SERVER["HTTP_HOST"] . $base_url . "/admin/index.php";
?>

<!-- <form action="<?php echo $url; ?>"> -->
<!--     <input type="submit" name="back" /> -->
<!-- </form> -->