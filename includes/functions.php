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

function get_study_by_id($id) {    /* generic function to look up which study an item is associated with; $id is the unique integer id of the items; */
  $query = "SELECT * FROM ".STUDIESTABLE." WHERE id='".$id."';";
  $result = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_study_by_id" ));
  $obj = mysql_fetch_object($result);
  if( $obj->id == $id ) {
    post_debug("<strong>get_study_by_id:</strong> id: " . $id . " " . $obj->name);
    return $obj;
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

/* function has_entries_for_edit_time($vpncode,$study,$starttime,$endtime) { */
/*     $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study' AND timestarted BETWEEN $starttime AND $endtime"; */
/*     post_debug("<strong>has_entries_for_edit_time</strong> 'query is': " . $query_string); */
/*     $results = mysql_query( $query_string) or die(exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in has_entries_for_edit_time" )); */
/*     if( mysql_num_rows($results) > 0) { */
/* 		post_debug("<strong>has_entries_for_edit_time:</strong> TRUE"); */
/*         return true; */
/*     } else { */
/* 		post_debug("<strong>has_entries_for_edit_time:</strong> FALSE"); */
/*         return false; */
/*     } */
/* } */

function entry_complete($id) {
    // just check whether an entry has a start AND end-time
    if(TIMEDMODE) {
        $query = "SELECT timefinished,endedsurveysmsintvar FROM ".RESULTSTABLE." WHERE `id`=".$id.";";
    } else {
        $query = "SELECT endedsurveysmsintvar FROM ".RESULTSTABLE." WHERE `id`=".$id.";";
    }
    $result = mysql_query( $query ) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in entry_complete" ));
    $row = mysql_fetch_assoc( $result );

    if( TIMEDMODE ) {
        if( $row['timefinished'] > 0 AND $row['endedsurveysmsintvar'] != NULL) {
			post_debug("<strong>entry_complete: </strong> in timedmode: TRUE ");
            return true;
        } else {
			post_debug("<strong>entry_complete: </strong> in timedmode: FALSE ");
            return false;
        }
    } else {
        if( $row['endedsurveysmsintvar'] != NULL) {
			post_debug("<strong>entry_complete:</strong> not in timedmode: TRUE ");
            return true;
        } else {
			post_debug("<strong>entry_complete:</strong> not in timedmode: FALSE");
            return false;
        }
    }
}

function last_entry_complete($vpncode) {
    $query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' ORDER BY created_at DESC LIMIT 1";
    $result = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in last_entry_complete" ));
    $obj = mysql_fetch_object($result);

    if( entry_complete( $obj->id ) ) {
		post_debug("<strong>last_entry_complete:</strong> TRUE");
        return true;
    } else {
		post_debug("<strong>last_entry_complete:</strong> FALSE");
        return false;
    }
}
/* function last_entry_complete($vpncode, $study) { */
/*     $query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study' ORDER BY created_at DESC LIMIT 1"; */
/*     $result = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in last_entry_complete" )); */
/*     $obj = mysql_fetch_object($result); */

/*     if( entry_complete( $obj->id ) ) { */
/* 		post_debug("<strong>last_entry_complete:</strong> TRUE"); */
/*         return true; */
/*     } else { */
/* 		post_debug("<strong>last_entry_complete:</strong> FALSE"); */
/*         return false; */
/*     } */
/* } */

function delete_entry($table,$id) {
    $query = "DELETE FROM ".$table." WHERE `id`=".$id;
    $result = mysql_query or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in delete_entry" ));
    return $result;
}

function get_all_item_variables() {
    // get all the columns so we have an idea what we need to write to the database (and where to)
    $query = "SHOW COLUMNS FROM ".RESULTSTABLE;
    $items=mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_all_item_variables" ));
    if(!$items) {
        echo 'Fehler beim Herausfinden der Feldnamen in Results: ' . mysql_error();
        exit;
    } else {
        return $items;
    }
}

function create_new_entry($vpncode,$timestarted,$iterate) {

    $loop = study_is_looped();
    // yikes
    // LOTS OF CODE DUPLICATION HERE, COULD DO MORE WITH STRING-INTERPOLATION TO FIX IT maybe?
    /* if $timestarted is not NULL */
    if( !empty($timestarted) ) {
        // this is loooped study
        if( $loop ) {
            // we are set to iterate the count
            if( $iterate ) {
                $iteration = get_iteration($vpncode) + 1;
            } else {
                $iteration = get_iteration($vpncode);
            }
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,timestarted,iteration,created_at,updated_at) VALUES('".$vpncode."', NOW(),".$timestarted.",".$iteration.", NOW(), NOW())";

			post_debug("<strong>create_new_entry:</strong> timestarted set and loop true");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
        } else {
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,timestarted,created_at,updated_at) VALUES('".$vpncode."', NOW(),".$timestarted.", NOW(), NOW())";

			post_debug("<strong>create_new_entry:</strong> timestarted set and loop false");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
        }
    }
    /* $timestarted is NULL */
    else {
        if( $loop AND $iterate ) {
            // we are set to iterate the count
            if( $iterate ) {
                $iteration = get_iteration($vpncode) + 1;
            } else {
                $iteration = get_iteration($vpncode);
            }
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,iteration,created_at,updated_at) VALUES('".$vpncode."', NOW(), ".$iteration.", NOW(), NOW())";
			post_debug("<strong>create_new_entry:</strong> timestarted not set and loop true");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
        } else {
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,created_at,updated_at) VALUES('".$vpncode."', NOW(),, NOW(), NOW())";
			post_debug("<strong>create_new_entry:</strong> timestarted not set and loop false");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
        }
    }

    /* insert the new entry into the db */
    $result = mysql_query($query_string) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in create_new_entry" ) );

    /*  query to get id of new entry */
    $get_id_query = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' ORDER BY created_at DESC LIMIT 1;";
    post_debug("<strong>create_new_entry:</strong> 'new entry query is': " . $get_id_query);
    $result = mysql_query($get_id_query) or die( exception_handler(mysql_error() . "<br/>" . $get_id_query . "<br/> in create_new_entry" ) );
    $entry = mysql_fetch_object($result);
    /* return new id or exit with error */
    if( !empty( $entry->id ) ) {
		post_debug("<strong>create_new_entry:</strong> id is " . $entry->id );
        return $entry->id;
    } else {
        post_debug("<strong>create_new_entry:</strong> ERROR: no ID returned by " . $get_id_query);
        exit();
    }
}

function check_vpn_results($vpncode,$statement, $timestarted){
	$query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."'";
	$results = mysql_query($query) or die( exception_handler( mysql_error() . "\n\n" . $query . "\n\nin check_vpn_results"));

	while( $entry = mysql_fetch_assoc($results)) {
		if( should_skip($vpncode,$statement,$timestarted) ) {
			post_debug("<strong>check_vpn_results:</strong> TRUE");
			return true;
		}
	}
	post_debug("<strong>check_vpn_results:</strong> FALSE");
	return false;
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

//todo: fix $study stuff
function post_study_hook($vpncode) {
    /* this should be done all the time */
    $query = "SELECT ".VPNDATATABLE.".id AS vpncode_id,".VPNDATATABLE.".vpncode,".VPNDATATABLE.".study AS vpncode_study,
        ".STUDIESTABLE.".loop AS study_loop,".STUDIESTABLE.".order AS study_order,".STUDIESTABLE.".iterations AS max_iterations,
        MAX(".RESULTSTABLE.".iteration) as iteration FROM ".VPNDATATABLE."
        LEFT JOIN ".STUDIESTABLE." ON (".STUDIESTABLE.".name = ".VPNDATATABLE.".study)
        LEFT JOIN ".RESULTSTABLE." ON (".VPNDATATABLE.".vpncode = ".RESULTSTABLE.".vpncode) WHERE ".RESULTSTABLE.".vpncode='$vpncode' GROUP BY vpncode_id;";

    //wenn studie in der user ist loop ist check wie viele iterationen der schon gemacht und entscheide dementsprechend ob er weiter kommt oder nicht
    $results = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in post_study_hook" ));
    $row = mysql_fetch_assoc($results);

	$num_attempts_query = 'SELECT count(*) as count FROM '.RESULTSTABLE.' WHERE vpncode="'.$vpncode.'"';
	$num_attempts_result = mysql_query( $num_attempts_query ) or die( exception_handler( mysql_error() . "<br/>" . $num_attempts_query . "<br/> in post_study_hook"));
	$num_attempts = mysql_fetch_assoc( $num_attempts_result );
	/* $study_data = get_study_data($study); */
	$study_data = get_study_data();

    if( $row['study_loop'] == 1 ) { /* this study is a looped one... */
		// check whether all necessary iterations have been completed, or the person has reached the max number of allowed attempts to fill out the survey
        if( $row['iteration'] < $row['max_iterations'] AND $num_attempts["count"] < $study_data["max_attempts"] ) {
            // if its only an iteration and the study has looped emails enabled
            // queue the daily reminder
            queue_email($vpncode,$study,'loop');
        } else { 
            // queue the emails if the study has it enabled
            queue_email($vpncode,$study,'post');
            next_study($vpncode,$row);
        }
    } else {                    /* study is not a looped one */
        queue_email($vpncode,$study,'post');
        next_study($vpncode,$row);
    }
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
