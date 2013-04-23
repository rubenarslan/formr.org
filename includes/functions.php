<?php

require_once "edit_times.php";
require_once "proband_mgmt.php";
#require_once "survey_mgmt.php";
#require_once "survey_rendering.php";
require_once "loop_mgmt.php";
require_once "email_mgmt.php";

function redirect_to($location) {
	if(substr($location,0,3)!= 'http'){
		$base = $_SERVER['SERVER_NAME'] . dirname($_SERVER['REQUEST_URI']);
		if(substr($location,0,1)=='/')
			$location = $base . substr($location,1);
		else $locaton = $base . $location;
	}
	try
	{
	    header("Location: $location");
		exit;
	}
	catch (Exception $e)
	{ // legacy of not doing things properly, ie needing redirects after headers were sent. 
		echo "<script type=\"text/javascript\">document.location.href = \"$location\";</script>";
	}
}
function h($text) {
	return htmlspecialchars($text);
}


if (!function_exists('__')) {

/**
taken from cakePHP
 */
	function __($singular, $args = null) {
		if (!$singular) {
			return;
		}

		$translated = _($singular);
		if ($args === null) {
			return $translated;
		} elseif (!is_array($args)) {
			$args = array_slice(func_get_args(), 1);
		}
		return vsprintf($translated, $args);
	}
}

if (!function_exists('__n')) {

/**
taken from cakePHP
 */
	function __n($singular, $plural, $count, $args = null) {
		if (!$singular) {
			return;
		}

		$translated = ngettext($singular, $plural, null, 6, $count);
		if ($args === null) {
			return $translated;
		} elseif (!is_array($args)) {
			$args = array_slice(func_get_args(), 3);
		}
		return vsprintf($translated, $args);
	}

}

function table_exists($table) {
    $query = "SHOW TABLES LIKE '".$table."'";
    $result = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in table_exists" ));
    if( mysql_num_rows($result) == 1 ) {
        return true;
    } else {
        return false;
    }
}

function debug($string) {
    if( DEBUG ) {
		echo "<pre>";
        var_dump($string);
		echo "</pre>";
    }
}
function pr($string) {
    if( DEBUG!==-1 ) {
		echo "<pre>";
        var_dump($string);
		echo "</pre>";
    }
}

function skipif_debug($string) {
	if(SKIPIF_DEBUG) {
		echo $string."<br/>";
	}
}


// wird genutzt
function specialitemhandler($row,$specialteststrigger,$allowedtypes){
    // bekommt alle Zeilen geliefert, bei denen etwas in special steht
    // ignoriert alle, die nicht einen unterfragebogen STARTEN
    // Unterfragebogen-Trigger werden in Settings geregelt
    // echo "<br />hier bin ich ";
    // echo $row["special"];
    // echo $specialteststrigger;
    if (in_array($row["special"],$specialteststrigger)) {
        $specialtestsstopper = array("SN"=>"snstop","test"=>"test");
        $specialtestfunction = array("snstart"=>"pushtosn","test"=>"test");
        eval($specialtestfunction[$row["special"]] . "(\$row);");
        // FIX: HIER EINFACH DAS START-ITEM mit in RESULTS-TABELLE, und setzen, wenn Frageboden erledigt ist
        // FIX: ITEM NUR ANZEIGEN, wenn ALLES ANDERE erledigt. Dann kann davor auch die Instruktion
        // eval('if ($diesepersonarray[' . preg_replace('/\s/', '] ', $row[skipif], 1) .') $duerfen = "skippen";');
    } else {
        // Wenn Item nicht dazu gedacht ist, einen Spezialfragebogen zu triggern
        // ignoriere es
        return; // oder wie man eine funtion abbricht
    }
}

function get_vpn_data($vpncode) {
	$query = "SELECT * FROM ".VPNDATATABLE." WHERE vpncode='".$vpncode."'";
	$result = mysql_query( $query ) or die( exception_handler( mysql_error() . "\n\n" . $query . "\n\n in get_vpn_data"));
	return mysql_fetch_object($result);
}

//the root URL (i.e. without script name) to the current context
function get_base_path() {
	//array with elements of the resource string
	$string = explode("/",$_SERVER['SCRIPT_NAME']);
	//remove the last element (i.e. the script name)
	array_pop($string);
	//return the full path
    return $_SERVER['SERVER_NAME'] . implode("/",$string);
}

function add_vpn($vpncode,$email,$study,$type) {
    $insert_partner = "INSERT INTO ".VPNDATATABLE." SET vpncode='".$vpncode."',email='".$email."',study='".$study."'";
    mysql_query( $insert_partner ) or die( exception_handler(mysql_error() . "<br/>" . $insert_partner . "<br/> in add_vpn" ));
}

function update_partnercode($vpncode,$partnercode) {
    $update_code = "UPDATE ".VPNDATATABLE." SET partnercode='".$partnercode."' WHERE vpncode='".$vpncode."'";
    mysql_query($update_code) or die( exception_handler(mysql_error() . "<br/>" . $update_code . "<br/> in update_partnercode" ));
}

function update_email($vpncode,$email) {
    $update = "UPDATE ".VPNDATATABLE." SET email='".$email."' WHERE vpncode='".$vpncode."'";
    mysql_query($update) or die( exception_handler(mysql_error() . "<br/>" . $update . "<br/> in update_email" ));
}

function exception_handler($exception) {
	debug($exception);
	exception_mailer($exception);
}

function exception_mailer($exception) {
	$to = "rubenarslan@gmail.com";
	$subject = "SURVEY Exception";
	$body = implode("\n",(array)$exception) . "\n\n\n" . var_export(debug_backtrace(),true);
	if(DEBUG<0)
		mail($to,$subject,$body);
	else
		die($body);
}

// function to retrieve config options
function get_config() {
	$query = "SELECT `key`,`value` FROM " . ADMINTABLE;
	$data = mysql_query( $query ) or die ( exception_handler( mysql_error() . "<br/>" . $query . "<br/> in get_email_info()"));

	$rr = array();

	while( $row = mysql_fetch_assoc( $data ) ) {
		$rr[$row["key"]] = $row["value"];
	}

	return $rr;
}

function hiddeninput($name,$value) {
    echo "<input type=\"hidden\" name=\"" . $name . "\" value=\"" . $value . "\" />";
}

function is_odd( $int ) {
    return( $int & 1 );
}

?>
