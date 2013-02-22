<?
// get all settings from the database
$fetch_settings = "SELECT * FROM " . ADMINTABLE ;

$settings_query = mysql_query( $fetch_settings ) or die( mysql_error() );

$settings = array();

while( $setting = mysql_fetch_assoc($settings_query) ) {
	$settings[$setting['key']] = $setting['value'];
}

// PAGE-INFOS
define('TITLE', $settings['title']);
define('DESCRIPTION', $settings['description']);
define('KEYWORDS', $settings['keywords']);
define('AUTHOR', $settings['author']);
define('COPYRIGHT', $settings['copyright']);
define('PAGETOPIC', $settings['pagetopic']);
define('OUTBUFFER',false);

// DESIGN
// Breite der Survey-Tabelle
define('SRVYTBLWIDTH', $settings['srvytblwidth']); //pixels

//colors
define('PRIMARY_COLOR', $settings['primary_color']);
define('SECONDARY_COLOR', $settings['secondary_color']);
define('ODD_COLOR', $settings['odd_color']);
define('EVEN_COLOR', $settings['even_color']);

// Anzahl der darzustellenden items
define('MAXNUMITEMS', $settings['maxnumitems']); //pixels

// Textfelder
define('TXTBXWIDTH', $settings['txtbxwidth']); //characters

define('IMAGEFOLDER', $settings['imagefolder']);

// Studienlogo
define('LOGO', $settings['logo']); //remember, images are located in images folder

// Welcome-Message
define ('WELCOME', $settings['welcome']);

define('EMAIL', $settings['admin_email']);

// is there at a diary in this study? FIXME: should be migrated to the database hence configurable from web interface //
define('DIARYMODE', (boolean) $settings['diary']);

// ist es eine partner oder single studie? 
define('PARTNER', (boolean) $settings['partner']);

// wenn die studie nur in bestimmten zeiträumen zugänglich sein darf
define('TIMEDMODE', (boolean) $settings['timed']);

// gibt es zu randomisierende items? wenn ja bool true, sonst bool false.
define('RANDOM', (boolean) $settings['random']);

// how many emails per cron run should get sent out?
define('MAXSENDEMAILS',$settings['maxsendemails']);
define('EMAILHEADER', "From: ". $settings['email_header_from'] . "\r\nReply-To: " . $settings['email_header_reply-to'] . "\r\nCc: " . $settings['email_header_cc']);

// WICHTIG! Erlaubte Variablentypen - alle anderen werden ignoriert!
// $allowedtypes = array("rating", "offen", "mc", "instruktion","mmc","fork","snpartner");
//$allowedtypes = array("rating","offen","mc","instruktion","mmc","fork","ym","snpartner","datepicker","email","imc");
// use the value from the database
$allowedtypes = split(',', $settings['allowedtypes']);

// WICHTIG! ERLAUBTE specialtests!
// möglich sind:
// SN
$specialtestsallowed = $settings['specialtestsallowed'];

// ...und wie die specialtests getriggert werden FIXME
// FIXME FIXME FIXMEFIXMEFIXMEFIXMEFIXME
$specialteststrigger = array("SN"=>"snstart","test"=>"test");

// maximum number of persons people can enter into the SN test.
define('SNMAXENTRIES',$settings['snmaxentries']);

// Die anderen Sachen werden direkt in der Funktion geregelt!

// Spezialtests müssen entsprechende Kennzeichungen in der Spalte special haben.
// Dort werden auch Test-spezifische Itemeigenschaften benannt
// Alle zu einem Spezialtest gehörenden Items müssen in der Spezialspalte durch die ersten beiden Zeichen dem Spezialtest zugeordnet werden!
// Beispiel: snabc
// Den Start das Spezialtests markiert ein Trigger
	// Den Tabellennamen für den Test definiert der Variablenname dieses Triggers
	// eventuelle andere Paramerter können ebenfalls gegeben werden, zum Beispiel Antowrtformatanzahl bei SN-Test -> Anzahl möglicher Personen
// an dieser Stelle wir der Spezialtest aufgerufen, der sich dann um seine Items kümmert
// Das Ende ein Stopper.

define('LOGINPAGE', $settings['loginpage']);
// Ist die Studie offen, oder für einen geschlossenen Nutzer-Pool gedacht?
define('USERPOOL', $settings['userpool']); // limited / open
//define('VPNDATATABLE', $vpndatatable); // IF limited: Name of Table with user data: change this to a fixed table in case you have several studies relying on the same sample
//define('VPNDATATAVPNCODE', 'vpncode'); // IF limited: in Table: Variable name of subject-id

// Für Dateiupload
define('FILEUPLOADMAXSIZE', $settings['fileuploadmaxsize']); // in k

// debugging?
// define('DEBUG', (boolean) $settings['debug']);
if($settings['debug'] == "true") {
	define('DEBUG',true);
} else {
	define('DEBUG',false);
}

// debugging?
// define('DEBUG', (boolean) $settings['debug']);
if($settings['skipif_debug'] == "true") {
	define('SKIPIF_DEBUG',true);
} else {
	define('SKIPIF_DEBUG',false);
}

if($settings['suppress_fork'] == 'true') {
	define('SUPPRESS_FORK',true);
} else {
	define('SUPPRESS_FORK',false);
}

if(true OR DEBUG) {
    // show E_NOTICE ?
    error_reporting(E_ALL);
    // no thanks
    ini_set("display_errors",1);
    ini_set("log_errors",1);
} else {
    ini_set("display_errors",0);
    ini_set("log_errors",1);
}

// set the timezone
date_default_timezone_set($settings['timezone']);

// give me lots of output
error_reporting(E_ALL & ~E_NOTICE);
// set a custome log file
ini_set("error_log", dirname(__FILE__) . "/../log/errors.log")

// $tz = date_default_timezone_get();
// 
// if (strcmp($tz, ini_get('date.timezone'))){
//     echo 'WARNING: Script timezone differs from ini-set timezone.';
// } else {
//     echo 'Script timezone and ini-set timezone match.';
// }

?>