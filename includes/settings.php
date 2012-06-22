<?

// which env are we using; could extend using this constant more 
// define('FRAMEWORK_ENV',"null2");
//define('FRAMEWORK_ENV',"production");
define('FRAMEWORK_ENV',"development");

// ANALYTICS
$useanalytics = "yes";			// yes/no
$analyticsid = "UA-2102103-4";	// UA-xxxxxx-x

// MYSQ testing environment
if( FRAMEWORK_ENV == "development") {
	$usesql = "yes";			// yes/no
	$DBhost = "localhost";
	$DBuser = "root";
	$DBpass = "root";
	$DBName = "psyframework";
} elseif( FRAMEWORK_ENV == "null2") {
	$usesql = "yes";			// yes/no
	$DBhost = "localhost";
	$DBuser = "n286";
	$DBpass = "5E6Cq8lR";
	$DBName = "usr_n286_1";
} elseif( FRAMEWORK_ENV == "production") {
	$usesql = "yes";			// yes/no
	$DBhost = "localhost";
	$DBuser = "dbu1119231";
	$DBpass = "xRBtdy7GOtBEKMcg";
	$DBName = "db1119231-offen";
}	elseif( FRAMEWORK_ENV == "selfinsight") {
		$usesql = "yes";			// yes/no
		$DBhost = "localhost";
		$DBuser = "d011c47b";
		$DBpass = "rK4tqKJccWnysUeu";
		$DBName = "d011c47b";
	}




/*
// MYSQL
 */
//change this setting with each install!!

/* define('TABLEPREFIX',"test_"); */

// Imtpotant! Table settings
// for every Install, change prefix!!!
$prefix = TABLEPREFIX;

// This should probably not be changed unless you really know what you do
define('ADMINTABLE', $prefix . "admin");
define('ITEMDISPLAYTABLE', $prefix . 'itemdisplay');
define('ITEMSTABLE', $prefix . 'items');
define('RESULTSTABLE', $prefix . 'results');
define('SNRESULTSTABLE', $prefix . 'snresults');
define('VPNDATATABLE',  $prefix.'vpnueberblick');
define('STUDIESTABLE', $prefix . 'studies');
define('TIMESTABLE', $prefix . 'times');
define('EMAILSTABLE',$prefix . 'emails');
define('MESSAGEQUEUE',$prefix . 'messagequeue');
define('SUBSTABLE',$prefix . 'substitutions');

?>
