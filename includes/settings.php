<?
#require_once $_SERVER['DOCUMENT_ROOT']."/zwang/app/Config/database.php";
require_once(dirname(__FILE__)."/../../../Config/database.php");

$db = new DATABASE_CONFIG();
$DBhost= $db->default['host'];
$DBname= $db->default['database'];
$DBuser= $db->default['login'];
$DBpass= $db->default['password'];
$DBport= $db->default['port'];
if(strlen($DBport)>0) $DBhost .= ":$DBport";

// ANALYTICS
$useanalytics = "no";			// yes/no
$analyticsid = "UA-2102103-4";	// UA-xxxxxx-x

/*
// MYSQL
 */
//change this setting with each install!!

define('TABLEPREFIX',"zwang_");

// Important! Table settings
// for every Install, change prefix!!!
$prefix = TABLEPREFIX;

// This should probably not be changed unless you really know what you do
define('ADMINTABLE', $prefix . "admin");
define('ITEMDISPLAYTABLE', $prefix . 'itemdisplay');
define('ITEMSTABLE', $prefix . 'items');
define('RESULTSTABLE', $prefix . 'results');
define('SNRESULTSTABLE', $prefix . 'snresults');
define('VPNDATATABLE',  $prefix.'probands');
define('STUDIESTABLE', $prefix . 'studies');
define('TIMESTABLE', $prefix . 'times');
define('EMAILSTABLE',$prefix . 'emails');
define('MESSAGEQUEUE',$prefix . 'messagequeue');
define('SUBSTABLE',$prefix . 'substitutions');

?>
