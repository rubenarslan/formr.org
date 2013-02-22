<?php

require_once "edit_times.php";
require_once "proband_mgmt.php";
require_once "survey_mgmt.php";
require_once "survey_rendering.php";
require_once "loop_mgmt.php";
require_once "email_mgmt.php";

function redirect_to($location) {
	echo "<script type=\"text/javascript\">document.location.href = \"$location\";</script>";
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

function post_debug($string) {
    if( DEBUG ) {
        echo "<br/>" . $string . "<br/>";
    }
}

function quote($s) {
    return "'".mysql_real_escape_string($s)."'";
}

function post_skipif_debug($string) {
	if(SKIPIF_DEBUG) {
		echo $string."<br/>";
	}
}

function standardsubmit() {
    echo '<div class="secondary-color bottom-submit"><input type="submit" name="weiterbutton" id="weiterbutton" value="Weiter!" /></div>';
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
    $insert_partner = "INSERT INTO ".VPNDATATABLE." SET vpncode='".$vpncode."',email='".$email."',study='".$study."',vpntype=".$type;
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
	post_debug($exception);
	exception_mailer($exception);
}

function exception_mailer($exception) {
	$to = "rubenarslan@gmail.com";
	$subject = "SURVEY Exception";
	$body = $exception . "\n\n\n" . debug_backtrace();
	mail($to,$subject,$body);
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
