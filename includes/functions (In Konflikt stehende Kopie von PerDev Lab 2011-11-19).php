<?
// SMS-Funktionen
function get_edit_times() {
    $query = "SELECT * FROM ".TIMESTABLE." ORDER  BY starttime ASC";
    $results = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/>in get_edit_times"));
    $arr = array();
    while($row = mysql_fetch_assoc($results)) {
        array_push($arr,$row);
    }
	post_debug("<strong>get_edit_times:</strong> $arr size: " . sizeof($arr) . " items");
    return $arr;
}

function generate_vpncode() {
    $charcters = array("∂","√","ç","∫","µ","≤","≥","†","®","∑","œ","Ω","≈","ß","ƒ","©","∆","˚","¬","¥","ø","π");
    $letters = array("a","b","c","d","e","f","g","h","i","j","k","l","m","n","o","p","q","r","s","t","u","v","w","x","y","z");
    $string = "";
    for($i=0; $i < 10; $i++) {
        $string = $string . $charcters[ rand(0,sizeof($charcters) - 1) ] . $letters[ rand(0,sizeof($letters) - 1) ];
    }
	$vpn = sha1($string);
    return $vpn;
}

function update_timestamps($vpncode,$study,$timestarted) {
    $time = date("Y.m.d - H.i.s");
    $unixtime = time();

	post_debug("<strong>update_timestamps:</strong> $time");

    if(TIMEDMODE) {                 /* define the query with which the entry gets chosen appropriate for the edittime */
		$query = "SELECT endedsurveysmsintvar,timefinished FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND study='".$study."' AND timestarted=".$timestarted.";";
        $check = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
    } else {
		$query = "SELECT endedsurveysmsintvar FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND study='".$study."';";
        $check = mysql_query() or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
    }

    $existententry = mysql_fetch_assoc($check);

    if(TIMEDMODE) {
        /* what is "existent" anyways??? ;) */
        if ($existententry["endedsurveysmsintvar"]==NULL AND $existententry["timefinished"]==0) {
            $query= "UPDATE ".RESULTSTABLE." SET timefinished=$unixtime, endedsurveysmsintvar='$time', updated_at=NOW() WHERE vpncode='".$vpncode."' AND study='".$study."' AND timestarted=".$timestarted.";";
            mysql_query($query) or die (exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
        }
    } else {
        if ($existententry["endedsurveysmsintvar"]==NULL) {
            $query= "UPDATE ".RESULTSTABLE." SET endedsurveysmsintvar='".$time."', updated_at=NOW() WHERE vpncode='".$vpncode."' AND study='".$study."';";
            mysql_query($query) or die (exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
        }
    }

    mysql_free_result($check);
}

function redirect_to($location) {
	echo "<script type=\"text/javascript\">document.location.href = \"$location\";</script>";
}

function getvpncode(){
    if (isset($_POST['vpncode']) and $_POST['vpncode']!="") {
        // vpncode was handed over through post
        $vpncode = mysql_real_escape_string ($_REQUEST['vpncode']);
		post_debug("<strong>getvpncode:</strong> got ".$vpncode." through post data");

    } elseif(isset($_REQUEST['vpncode']) and $_REQUEST['vpncode']!="") {
        // in session
        $vpncode = mysql_real_escape_string($_REQUEST['vpncode']);
		post_debug("<strong>getvpncode:</strong> got ".$vpncode." through request data");

		if( !vpn_exists($vpncode) ) {
			$goto=basename($_SERVER["SCRIPT_NAME"]);
			redirect_to("login.php?goto=$goto");
			// header("Location: login.php?goto=$goto");
			// exit();
		}
    } elseif (USERPOOL == "open") {
        // Prüfe, ob wir ihn in Stücken bekommen haben.
        if( isset($_POST['idbox1']) AND isset($_POST['idbox2']) AND isset($_POST['idbox3']) AND isset($_POST['idbox4']) ) {
            // Prüfe bitte mal, ob nicht einer leer geblieben ist...
            if(($_POST['idbox1']=="") OR ($_POST['idbox2']=="") OR ($_POST['idbox3']=="") OR ($_POST['idbox4']=="")) {
                // echo "Bitte alle Felder ausfüllen";
                $goto=basename($_SERVER["SCRIPT_NAME"]);
				redirect_to("login.php?goto=$goto");
                // header("Location: login.php?goto=$goto");
                // exit();
            }
            // (korrigiere und) übernimm ihn
            if (strlen(trim($_POST['idbox3'])) == 1) {
                $vp_idbox3 = "0" . $_POST['idbox3'];
            } else {
                $vp_idbox3 = $_POST['idbox3'];
            }
            $vpncode = mysql_real_escape_string(strtolower($_POST['idbox1'] . $_POST['idbox2'] . $vp_idbox3 . $_POST['idbox4']));
			post_debug("<strong>getvpncode:</strong> constructed ".$vpncode." for open pool");

        } elseif (basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
            // Wenn er auch nicht gestückelt übergeben wurde, dann ab zum Login
            // Wenn du nicht schon dort bist
            $goto=basename($_SERVER["SCRIPT_NAME"]);
			redirect_to("login.php?goto=$goto");
            // header("Location: login.php?goto=$goto");
            // exit();
        }
    } elseif (USERPOOL == "limited" AND basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
        // Zum Login
        $goto=basename($_SERVER["SCRIPT_NAME"]);
		redirect_to("login.php?goto=$goto");
        // header("Location: login.php?goto=$goto");
        // exit();
    } elseif(isset($_SESSION['vpncode']) and $_SESSION['vpncode']!="") {
        // Am ehesten kommt er über SESSION, und dann können wir ihn einfach nehmen
        $vpncode=mysql_real_escape_string($_SESSION['vpncode']);
		post_debug("<strong>getvpncode:</strong> got ".$vpncode." through session data");
    } elseif (basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
        // keinen Code über post, get oder Session bekommen
        // keinen gestückelten bekommen (wird nur geprüft, wenn OPEN)
        // Nicht auf Loginpage
        // Dann müssen wir da jetzt hin!
        $goto=basename($_SERVER["SCRIPT_NAME"]);
		redirect_to("login.php?goto=$goto");
        // header("Location: login.php?goto=$goto");
        // exit();
    }

    // FALLS Userpool limited ist, prüfe, ob der vpncode auch gültig ist
    if (USERPOOL == "limited") {
        if  (!table_exists(VPNDATATABLE)) {
            die("VPNDATATABLE does not exist: please check your setup");
        }
        if (!vpn_exists($vpncode)) {
            // Wenn der vpncode nicht gültig ist
            if (basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
                // Wenn du nicht schon dort bist
                $goto=basename($_SERVER["SCRIPT_NAME"]);
				redirect_to("login.php?goto=$goto");
                // header("Location: login.php?goto=$goto");
                // exit();
            }
		}
	}

    // Du hast jetzt einen gültigen vpncode. Lege mir noch kurz einen Eintrag in results an, wenn es noch keinen gibt
    if  (!table_exists(RESULTSTABLE) OR !table_exists(VPNDATATABLE)) {
        die("RESULTSTABLE does not exist: please check your setup");
    }

	// create entry for vpn in VPNDATATABLE
    if ( !vpn_exists($vpncode) ) {
		// doesn't yet exist, so create it
		$study = get_study_by_id(1);
		add_vpn($vpncode,NULL,$study->name,1);
    }

    // put the code back into the session
    $_SESSION["vpncode"]=$vpncode;
    // and return for functions waiting for it
	post_debug("<strong>getvpncode:</strong> " . $vpncode );
    return $vpncode;
}

function vpn_exists($vpncode) {
    $query="SELECT * FROM ".VPNDATATABLE." WHERE (vpncode ='$vpncode')";
    $res = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in vpn_exists" ));
    $exists=mysql_numrows($res);
    if( $exists != 0 ) {
		post_debug("<strong>vpn_exists:</strong> TRUE");
        return true;
    } else {
		post_debug("<strong>vpn_exists:</strong> FALSE");
        return false;
    }
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


/* only tell me what I need to know;
 * function to check, wether endtries exist for the given study and vpncode
 * only select id for perfomances' sake */
function has_entries_for_study($vpncode,$study) {
    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND study='".$study."';";
    $results = mysql_query( $query_string) or die(exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in has_entries_for_study" ));
    if( mysql_num_rows($results) > 0) {
		post_debug("<strong>has_entries_for_study:</strong> TRUE");
        return true;
    } else {
		post_debug("<strong>has_entries_for_study:</strong> FALSE");
        return false;
    }
}

/* only tell me what I need to know;
 * function to check, wether endtries exist for the given study and vpncode AND start- and endtime
 * only select id for performances' sake */
function has_entries_for_edit_time($vpncode,$study,$starttime,$endtime) {
    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study' AND timestarted BETWEEN $starttime AND $endtime";
    post_debug("<strong>has_entries_for_edit_time</strong> 'query is': " . $query_string);
    $results = mysql_query( $query_string) or die(exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in has_entries_for_edit_time" ));
    if( mysql_num_rows($results) > 0) {
		post_debug("<strong>has_entries_for_edit_time:</strong> TRUE");
        return true;
    } else {
		post_debug("<strong>has_entries_for_edit_time:</strong> FALSE");
        return false;
    }
}


/*
I want to know whether the study part is completely filled out in the fork.
most complicated query I ever did, it feels oh so wrong
*/
function study_part_completed($vpncode,$study,$iteration=NULL) {
	/*until i get on this
*/
if($study=='finished') return;
post_debug("<strong>$study complete</strong>");
#return true;

	# ORDER BY id LIMIT 9 weil sonst die group_concat_maxlen überschritten wird, die ich irgendwie nicht ändern kann. die ist 1024 bytes also 256 unicode zeichen, also  9 vars à 29 zeichen im schnitt. außerdem geht limit nicht bei group_concat (keine zeilen. also unterquery.)
	if(isset($iteration)) $iteration = " AND iteration=$iteration ";
	else $iteration = '';
	$query_string = "
	SET @vars = (SELECT GROUP_CONCAT(variablenname SEPARATOR ' , ') FROM (SELECT variablenname FROM selfinsight_items WHERE study='$study' AND typ!='fork' AND skipif='' ORDER BY id DESC LIMIT 9) a);
	SET @wher = (SELECT GROUP_CONCAT(variablenname SEPARATOR ' IS NOT NULL AND ') FROM (SELECT variablenname FROM selfinsight_items WHERE study='$study' AND typ!='fork' AND skipif='' ORDER BY id DESC LIMIT 9) a);
	SET @vals = 'SELECT vpncode, ';
	SET @vals2 = ' FROM selfinsight_results WHERE vpncode=\'$vpncode\' AND study=\'$study\' $iteration  AND ';
	SET @vals3 = ' IS NOT NULL ';
	SET @query = CONCAT(@vals,@vars,@vals2,@wher,@vals3);
	PREPARE my_query FROM @query;
	EXECUTE my_query;";
	
	require('settings.php');

	$mysqli = new mysqli($DBhost,$DBuser,$DBpass,$DBName);

//	check connection 
	if (mysqli_connect_errno()) {
	    printf("Connect failed: %s\n", mysqli_connect_error());
	    exit();
	}

	post_debug("<strong>part_study_completed</strong> 'query is': " . $query_string);
    
	$mysqli->multi_query($query_string) OR die("study_part_completed".$mysqli->error);
	
	$mysqli->next_result() OR die("study_part_completed1".$mysqli->error);
	$mysqli->next_result() OR die("study_part_completed2".$mysqli->error);
	$mysqli->next_result() OR die("study_part_completed3".$mysqli->error);
	$mysqli->next_result() OR die("study_part_completed4".$mysqli->error);
	$mysqli->next_result() OR die("study_part_completed5".$mysqli->error);
	$mysqli->next_result() OR die("study_part_completed6".$mysqli->error);
	$mysqli->next_result() OR die("study_part_completed7".$mysqli->error);
#	$mysqli->next_result() OR die("study_part_completed8".$mysqli->error);

	$result = $mysqli->store_result() OR die("study_part_completed".$mysqli->error);
	
	$numrows = $result->num_rows;
	$result->free();
	
//	close connection
	$mysqli->close();
	
	
    if( $numrows > 0) {
		post_debug("<strong>$study complete</strong>");
        return true;
    } else {
		post_debug("<strong>$study not complete</strong>");
        return false;
    }
}


/* older attempt at the above functions */
function has_entries($vpncode,$starttime,$endtime,$study) {
    // if its in timedmode check whether the vpncode has entries for that timeperiod or not
    if( TIMEDMODE )  {
        $query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study' AND timestarted BETWEEN $starttime AND $endtime";
    } else {
        $query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study'";
    }

    $items = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in has_entries" ));
    $num = mysql_num_rows($items);
    if( $num > 0) {
		post_debug("<strong>has_entries:</strong> TRUE");
        return true;
    } else {
		post_debug("<strong>has_entries:</strong> FALSE");
        return false;
    }
}

/* php is ugly */
function render_form_header($vpncode,$timestarted) {
    /* form begins */	
    echo '<form  action="survey.php" method="post">';
    /* pass on hidden values */
    echo '<input type="hidden" name="vpncode" value="' . $vpncode . '" />';
    if( !empty( $timestarted ) ) {
        echo '<input type="hidden" name="timestarted" value="' . $timestarted .'" />';
	} else {
		post_debug("<strong>render_form_header:</strong> timestarted was not set or empty");
	}
	echo '<div id="content">';
}

/* php is frikkin ugly */
function render_form_footer() {
	echo '</div>'; // end of <div id="main">
    echo "</form>"; /* close form */

	echo '<div id="problems">';
		echo "Bei Problemen wenden Sie sich bitte an ";
		echo "<strong><a href=\"mailto:".EMAIL."\">".EMAIL."</a> </strong><br />";
	echo '</div>';
	
}

function can_edit_now($study,$curtime,$times,$vpncode) { # MOOONSTER
    foreach( $times as $time ) {
		if( $time['starttime'] < $time['endtime'] ) { //everything is easy
			post_debug("can_edit_now, normal case");
	        $start = strtotime( $time['starttime'] . " seconds today");
			$end = strtotime( $time['endtime'] . " seconds today");
			$timestarted = get_timestarted($vpncode,$study,$start, $end);
			if(( $curtime > $start AND $curtime < $end ) || ( $timestarted > $start AND $timestarted < $end )) {
	            // began in the middle of an edit time slot
				post_debug("<strong>can_edit_now:</strong> TRUE");
	            return true;
	        }
			
		} else {
				post_debug("can_edit_now, special case");
	        $start1 = strtotime( $time['starttime'] . " seconds today"); # could be the last part of the day
			$end1 = strtotime( $time['endtime'] . " seconds tomorrow");
			$start2 = strtotime( $time['starttime'] . " seconds yesterday");  # or the early part of the day
			$end2 = strtotime( $time['endtime'] . " seconds today");
			post_debug("<strong>can_edit_now:</strong> start1 ".$start1 ."-end1 ".$end1."start2 ".$start2 ."-end2 ".$end2);
			$timestarted1 = get_timestarted($vpncode,$study,$start1, $end1);
			$timestarted2 = get_timestarted($vpncode,$study,$start2, $end2);
			post_debug("-----");
			
			if(( $curtime > $start1 AND $curtime < $end1 ) || 
			($timestarted1 > $start1 AND $timestarted1 < $end1 ) || 
			( $curtime > $start2 AND $curtime < $end2 ) || 
		 	($timestarted2 > $start2 AND $timestarted2 < $end2 ) ) {
				post_debug("<strong>can_edit_now:</strong> TRUE");
				return true;
			}
		}
    }
	post_debug("<strong>can_edit_now:</strong> FALSE");
    return false;
}

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

function last_entry_complete($vpncode, $study) {
    $query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study' ORDER BY created_at DESC LIMIT 1";
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

function study_is_looped($study) {
    $query_string = "SELECT ".STUDIESTABLE.".loop FROM ".STUDIESTABLE." WHERE `name`='".$study."';";
    $result=mysql_query($query_string) or die( exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in study_is_looped" ));
    $study_config = mysql_fetch_object($result);
    if( $study_config->loop == 0 ) {
		post_debug("<strong>study_is_looped:</strong> FALSE");
        return false;
    } elseif( $study_config->loop == 1 ) {
		post_debug("<strong>study_is_looped:</strong> TRUE");
        return true;
    } else {
        post_debug("<strong>study_is_looped:</strong> something went really wrong");
        exit;
    }
}

function get_iteration($vpncode,$study) {
    $query_string = "SELECT MAX(iteration) as iteration FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND study='".$study."'";
    $result = mysql_query( $query_string ) or die( exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in get_iteration" ) );
    $count = mysql_fetch_object($result);
    if( !is_null($count->iteration) ) {
		post_debug("<strong>get_iteration:</strong> " . $count->iteration);
        return $count->iteration;
    } else {
		post_debug("<strong>get_iteration:</strong> " . $count->iteration);
        return 1;
    }
}

function create_new_entry($vpncode,$study,$timestarted,$iterate) {

    $loop = study_is_looped($study);
    // yikes
    // LOTS OF CODE DUPLICATION HERE, COULD DO MORE WITH STRING-INTERPOLATION TO FIX IT maybe?
    /* if $timestarted is not NULL */
    if( !empty($timestarted) ) {
        // this is loooped study
        if( $loop ) {
            // we are set to iterate the count
            if( $iterate ) {
                $iteration = get_iteration($vpncode,$study) + 1;
            } else {
                $iteration = get_iteration($vpncode,$study);
            }
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,timestarted,iteration,study,created_at,updated_at) VALUES('".$vpncode."', NOW(),".$timestarted.",".$iteration.",'".$study."', NOW(), NOW())";

			post_debug("<strong>create_new_entry:</strong> timestarted set and loop true");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
            post_debug("<strong>create_new_entry:</strong> timestarted: $timestarted loop: $loop iteration: $iteration study: $study");
        } else {
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,timestarted,study,created_at,updated_at) VALUES('".$vpncode."', NOW(),".$timestarted.",'".$study."', NOW(), NOW())";

			post_debug("<strong>create_new_entry:</strong> timestarted set and loop false");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
            post_debug("<strong>create_new_entry:</strong> timestarted: $timestarted loop: $loop iteration: $iteration study: $study");
        }
    }
    /* $timestarted is NULL */
    else {
        if( $loop AND $iterate ) {
            // we are set to iterate the count
            if( $iterate ) {
                $iteration = get_iteration($vpncode,$study) + 1;
            } else {
                $iteration = get_iteration($vpncode,$study);
            }
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,study,iteration,created_at,updated_at) VALUES('".$vpncode."', NOW(), '".$study."', ".$iteration.", NOW(), NOW())";
			post_debug("<strong>create_new_entry:</strong> timestarted not set and loop true");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
            post_debug("<strong>create_new_entry:</strong> timestarted: $timestarted loop: $loop iteration: $iteration study: $study");
        } else {
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,study,created_at,updated_at) VALUES('".$vpncode."', NOW(), '".$study."', NOW(), NOW())";
			post_debug("<strong>create_new_entry:</strong> timestarted not set and loop false");
            post_debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
            post_debug("<strong>create_new_entry:</strong> timestarted: $timestarted loop: $loop iteration: $iteration study: $study");
        }
    }

    /* insert the new entry into the db */
    $result = mysql_query($query_string) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in create_new_entry" ) );

    /*  query to get id of new entry */
    $get_id_query = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND study='".$study."' ORDER BY created_at DESC LIMIT 1;";
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

function write_post_data($id) {

    $item_variables = get_all_item_variables();

    if( !empty( $id ) ) {
        if(mysql_num_rows($item_variables) > 0) {
            while ($row = mysql_fetch_assoc($item_variables)) {
                // Loope durch alle Variablennamen
                // Wenn einer davon per POST übergeben wurde, und nicht leer ist (auch ausgelassene werden als "" gepostet!)
                if (isset($_POST[$row[Field]]) AND ($_POST[$row[Field]]!="")) {
                    if( $row[Field] != "timestarted" ) {
                        /* schreibt alles außer timestarted */
                        $value = mysql_real_escape_string($_POST[$row[Field]]);
                        $variable = $row[Field];

                        if(TIMEDMODE) {
                            $query= "UPDATE ".RESULTSTABLE." SET `$variable`='$value', updated_at=NOW() WHERE id=".$id;
                        } else {
                            $query= "UPDATE ".RESULTSTABLE." SET `$variable`='$value', updated_at=NOW() WHERE id=".$id;
                        }

                        mysql_query($query) or die (exception_handler(mysql_error() . "<br/>" . $query . "<br/> in write_post_data" ));
                    }

                    // Wenn eine mmc-Antwort gepostet wurde, setze auch die Frage auf beantwortet
                    if (strpos($variable,"mmcalt")>0) {
                        // echo "if ist angeschlagen" . $variable . "<br />";
                        $hauptvariablenname = substr($variable, 0, strpos($variable, "mmcalt"));

                        if(TIMEDMODE) {
                            $query= "UPDATE ".RESULTSTABLE." SET $hauptvariablenname='99', updated_at=NOW() WHERE id=".$id;
                        } else {
                            $query= "UPDATE ".RESULTSTABLE." SET $hauptvariablenname='99', updated_at=NOW() WHERE id=".$id;
                        }

                        mysql_query($query) or die (exception_handler(mysql_error() . "<br/>" . $query . "<br/> in write_post_data" ));

                        /*  GEHT NICHT Setze alle Fragen, die aufgrund dieser Antwort übergangen werden, auf beantwortet mit 99

                            $skipifcode = $variable . " == " . $value;
                            $query="SELECT * FROM ".ITEMSTABLE." WHERE skipif='$skipifcode'";
                            while ($skip = mysql_fetch_assoc(mysql($query))) {
                            $skipthis = $skip[variablenname];
                            $query= "UPDATE ".RESULTSTABLE." SET
                            $skipthis='99'
                            WHERE vpncode='$vpncode'";
                            echo $query;
                            mysql_query($query) or die ("Fehler bei " . $query . mysql_error() . "<br />");
                         */

                    }
                } else {
                    // echo "if ist nicht angeschlagen" . $variable . "<br />";
                }
            }
        }
    } else {
        echo "ERROR: cannot update a row without an id!";
        exit();
    }
}

function get_entry_by_id($id) {
    $query_string = "SELECT * FROM ".RESULTSTABLE." WHERE id='".$id."'";
    $result = mysql_query( $query_string ) or die( exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in get_entry_by_id" ));
    return mysql_fetch_object($result);
}

function get_entry_for_time($vpncode,$study,$starttime,$endtime) {
    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study' AND timestarted BETWEEN $starttime AND $endtime";
    post_debug( "<strong>get_entry_for_time:</strong> says ''query is': " . $query_string);
    $result = mysql_query( $query_string ) or die( exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in get_entry_for_time" ) );
    $entry = mysql_fetch_object($result);
    if( !is_null( $entry->id ) ) {
        return $entry->id;
    } else {
        echo "id can't be null, something went wrong in get_entry_for_time";
        exit();
    }
}

function get_timestarted($vpncode,$study,$starttime,$endtime) {
	post_debug("<strong>get_timestarted:</strong> vpncode => " . $vpncode);
	post_debug("<strong>get_timestarted:</strong> study => " . $study);
	post_debug("<strong>get_timestarted:</strong> starttime => " . $starttime);
	post_debug("<strong>get_timestarted:</strong> endtime => " . $endtime);

	$query = "SELECT timestarted FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND study='".$study."' AND (timestarted BETWEEN ".$starttime." AND ".$endtime.") ORDER BY timestarted DESC LIMIT 1";
	$result = mysql_query($query) or die( exception_handler( mysql_error() . "<br/>" . $query . "</br>in get_timestarted"));
	$entry = mysql_fetch_assoc($result);
	if( empty($entry["timestarted"]) ) {
		post_debug("<strong>get_timestarted:</strong> didn'nt find any timestarted for this edit slot");
		return time();
	} else {
		post_debug("<strong>get_timestarted:</strong> using timestarted found in db");
		return $entry["timestarted"];
	}
}

function post_debug($string) {
    if( DEBUG ) {
        echo "<br/>" . $string . "<br/>";
    }
}

function writepostedvars($vpncode,$starttime,$endtime,$timestarted,$study){

    /* if the current user has no entires for this particular edit cycle 
     * or for this study at all (if timedmode is off the time flags are ignored) */
    if( !has_entries_for_study($vpncode,$study) ) {
        post_debug( "<strong>writepostedvars:</strong> " . $vpncode . " has no entries for study: " . $study);
        // code I'd like to have
        $entry_id = create_new_entry($vpncode,$study,$timestarted,false);
        // and create it if possible
        if( $entry_id != false ) {
            write_post_data($entry_id);
        }
    }
    /* oh, the vpncode *does* have entries for this study already */
    else {
        post_debug( "<strong>writepostedvars:</strong> " . $vpncode . " has an entry for study: " . $study);
        /* if its a diary study check if the vpncode has entries for that time period */
        if( TIMEDMODE ) {
            /* timemode is on, meaning only at certain times site can be accessed and entries created
             * now if the current study is also a looped one we have to check whether entries exist for 
             * this particular edit cycle */
            /* if vpncode has no entries for that edit time period create a bare one */
            if( !has_entries_for_edit_time($vpncode,$study,$starttime,$endtime) ) {
                post_debug("<strong>writepostedvars:</strong> in timedmode, vpncode has no entires for edit time");
                if( last_entry_complete($vpncode,$study) ) {
                    post_debug("<strong>writepostedvars:</strong> last entry is complete");
                    // create a new entry, don't iterate the count
                    $entry_id = create_new_entry($vpncode,$study,$timestarted,true);
                    if( $entry_id != false ) {
                        write_post_data($entry_id);
                    }
                } else {
                    post_debug("<strong>writepostedvars:</strong> last entry is not complete");
                    // create a new entry and interate the count
                    $entry_id = create_new_entry($vpncode,$study,$timestarted,false);
                    if( $entry_id != false ) {
                        write_post_data($entry_id);
                    }
                }
            }
            /* vpncode has entries, try and complete what is left open with what is in $_POST */
            else {
                post_debug("<strong>writepostedvars</strong> in timedmode, vpncode has entires for edit time");
                $entry_id = get_entry_for_time($vpncode,$study,$starttime,$endtime);
                if( $entry_id != false ) {
                    write_post_data($entry_id);
                }
            }
        }
        /* no diary mode, and vpncode does have entries for this study: put whats in $_POST into the database */
        else {
            $entry_id = get_entry_for_time($vpncode,$study,$starttime,$endtime);
            if( $entry_id != false ) {
                write_post_data($entry_id);
            }
        }
    }
}


function quote($s) {
    return "'".mysql_real_escape_string($s)."'";
}

function get_study_by_vpn($vpncode) {
    $query = "SELECT study FROM ".VPNDATATABLE." WHERE vpncode='$vpncode';";
    $results = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_study_by_vpn" ));
	post_debug("<strong>get_study_by_vpn:</strong> $query");
    $result = mysql_fetch_assoc($results);
    foreach($result as $item) {
        return $item;
    }
}

function get_study_by_name($name){
	$query = "SELECT * FROM ".STUDIESTABLE." WHERE `name`='$name'";
	$result = mysql_query($query) or die( exception_handler(mysql_error() . "\n\n" . $query . "\n\nin get_study_by_name"));
	return mysql_fetch_object($result);
}

function get_study_data($study) {
    $query = "SELECT * FROM ".STUDIESTABLE." WHERE name='".$study."'";
    $results = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_study_data" ));
    return mysql_fetch_assoc($results);
}

function remove_stale_itemsdisplayed($vpncode, $starttime) {
	$delete_displayed = "DELETE FROM " . ITEMDISPLAYTABLE . " WHERE vpncode='$vpncode' AND created_at < $starttime";
	mysql_query($delete_displayed) or die( exception_handler(mysql_error() . "<br/>" . $delete_displayed . "<br/> in remove_stale_itemsdisplayed" ));
}

function queue_message($vpncode,$id,$abstime,$delta) {
    //get the email address through the vpncode, the email message template from the database and calculate the absolute time the message will be sent
    // then add an entry to the message queue
    //$query = "SELECT  ".EMAILSTABLE.".subject,".EMAILSTABLE.".body,".VPNDATATABLE.".email FROM ".EMAILSTABLE." JOIN ".VPNDATATABLE." ON ".VPNDATATABLE.".vpncode='$vpncode' WHERE ".EMAILSTABLE.".id=$id;";
    $query = "SELECT email FROM ".VPNDATATABLE." WHERE vpncode='$vpncode'";
    $result = mysql_query( $query ) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in queue_message" ));
    $data = mysql_fetch_assoc( $result );

	$base_time = mktime(0,0,0,date('n'),date('j'),date('Y'));
	$time = $base_time + $delta + $abstime;

    if( $data['email'] != "" ) {
        $query = "INSERT INTO ".MESSAGEQUEUE." (email_id,vpncode,email_address,send_time) VALUES ('".$id."','".$vpncode."','".$data['email']."','".$time."');";
        mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in queue_message" ));
    }
}

/* returns an array with all items that have already been answered for that study in this edit period (when TIMEDMODE == true) */
function get_already_answered($vpncode,$study,$starttime,$endtime) {
    if(TIMEDMODE) {             /* if TIMEDMODE true */
		$query="SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode'  AND study='$study' AND (timestarted BETWEEN $starttime AND $endtime);";
    } else {                    /* if TIMEDMODE false */
		$query="SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study';";
    }

	post_debug($query);
    $dieseperson=mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_already_answered" ));
    $result=mysql_fetch_assoc($dieseperson);

    $already_answered = array();
    if( !empty($result) ) {
        foreach ($result as $key => $value) {
            if ($value != NULL) {
                array_push($already_answered, $key);
            }
        }
    }
    return $already_answered;
}


/* returns an array with the next items to be displayed */
function get_next_items($vpncode, $study, $timestarted, $already_answered) { 
    // now, get all the items from the table that have not yet been answered and check whether they should be displayed or not
    $all_items = "SELECT * FROM " . ITEMSTABLE . " WHERE study='" . $study . "' AND (typ='Instruktion' OR  (variablenname NOT IN (". implode(",", array_map('quote', $already_answered)) . ")));";
    $itemtable=mysql_query($all_items) or die(exception_handler(mysql_error() . "<br/>" . $all_items . "<br/> in get_next_items" ));

    $itemassocs = array();
    while( $item = mysql_fetch_assoc( $itemtable )) {
        array_push($itemassocs,$item);
    }

	// foreach($all_entries as $entry) {
	// 	foreach($entry as $key => $value) {
	// 		echo $key." => ".$value."<br/>";
	// 	}
	// }

    // array to keep track of items to be displayed
    $rows = array();

    // for each entry this person has in the database, check this particular value and decide whether to skip or not. biatch.
    foreach($itemassocs as $item) {
		if (!should_skip($vpncode,$item,$timestarted)) {
            array_push($rows, $item);
		}
	}

    return $rows;
}

function printitems($vpncode,$allowedtypes,$specialteststrigger,$starttime,$endtime,$study,$timestarted){

    $already_answered = get_already_answered($vpncode,$study,$starttime,$endtime);

    $rows = get_next_items($vpncode, $study, $timestarted, $already_answered);

    // randomized blocks of quesitons?
    if(RANDOM) {
        $random_items = array();
        $final = array();
        $previous = false;

        foreach($rows as $row) {
            if( strtolower($row['rand']) != 'true') {
                /* if its not a rand element, shuffle the random element array, push those items to $final and then push the current non-rand  straight to $final */
                if($previous AND !empty($random_items)) { /* if the previous element was a rand and the rand items arr is not empty (like in the beginning) */
					shuffle($random_items);               /* randomise */
					foreach($random_items as $item) {     /* write back all random items into the final array */
					array_push($final,$item);
					}
					/* reset the variables */
					$random_items = array();
					$previous = false;
                }
                array_push($final,$row);
            } else {
                array_push($random_items,$row);
                $previous = true;
            }
        }
        /* finalise operation: put all remaining random items into final array  */
        /* this is in particular true, when the last item is also a rand item, so that the first if clause doesn't get called on the last items */
        if( !empty($random_items) ) {
				shuffle($random_items);
				foreach($random_items as $item) {     /* write back all random items into the final array */
				array_push($final,$item);
            }
        }
        $rows = $final;
    }

    // loope jetzt bitte durch die Itemtabelle
    $itemsDisplayed = 0;
    for($i=0; $i < sizeof($rows); $i++) {
        $row = $rows[$i];

        // fork-items sind relevant, werden aber nur behandelt, wenn sie auch an erster Stelle sind, also alles vor ihnen schon behandelt wurde
        if ($row["typ"]=="fork" AND $itemsDisplayed==0) {
            printitem($row["id"], $row["variablenname"], $row["typ"], $formulierung, $row["antwortformatanzahl"], $row["ratinguntererpol"], $row["ratingobererpol"], $allowedtypes, $i, $row["id"]+1, $rows, $timestarted, $vpncode);
            break;
        } elseif ($row["typ"]=="fork" AND $itemsDisplayed>0) {
            break;
        }

        // Gibt es Bedingungen, unter denen das Item alternativ formuliert wird?
        $formulierung = $row["wortlaut"];
        if ($row["altwortlautbasedon"]!="") {
            // und prüfe, ob sie zutrifft
            // $altwortlaut = eval('if ($result[' . preg_replace('/\s/', '] ', $row["altwortlautbasedon"], 1) .') return $row[altwortlaut];');
            eval('if ($result[' . preg_replace('/\s/', '] ', $row[altwortlautbasedon], 1) .') $formulierung = $row[altwortlaut];');
            // echo 'if ($result[' . preg_replace('/\s/', '] ', $row[altwortlautbasedon], 1) .') $formulierung = $row[altwortlaut];';
            if ($altwortlaut != "") {
                // nimm die alternative formultierung
                $formulierung = $altwortlaut;
            }
        }

        // FIX: Logik-Hack: Einsetzen des Datums
        // Sollte einmal als grundsätzliche Funktion bereitgestellt werden
        // $formulierung = preg_replace("/LOGIKDATE2003/",date2003($vpncode),$formulierung);
        $formulierung = substitute($vpncode,$formulierung);

        // Schreibe das item hin
        printitem($row["id"], $row["variablenname"], $row["typ"], $formulierung, $row["antwortformatanzahl"], $row["ratinguntererpol"], $row["ratingobererpol"], $allowedtypes, $i, $row["id"]+1, $rows, $timestarted, $vpncode);

        if ($row["typ"]!="instruktion") {
            $itemsDisplayed++;
            post_debug("<strong>printitems:</strong> items displayed: " . $itemsDisplayed);
            post_debug("<strong>printitems:</strong> MAXNUMITEMS: " . MAXNUMITEMS);
        }

        // merke dir, dass du das Item angezeigt hast.
        // Das machst du auch nicht für special-items, denn die werden ja vorher schon gefiltert!
        $query = "UPDATE " . ITEMDISPLAYTABLE . " SET displaycount=displaycount+1 WHERE vpncode='$vpncode' AND variablenname = '".$row["variablenname"]."';";
        $itemdisplay = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitems" ));
        if (mysql_affected_rows()==0) {
            $itemdisplay = mysql_query("insert into " . ITEMDISPLAYTABLE . " (variablenname,vpncode,displaycount,created_at) values ('".$row["variablenname"]."','$vpncode',1,".time().");");
        }

        post_debug("<strong>printitems:</strong> displaying items: " . $itemsDisplayed . " " . $row["variablenname"]);

        // do not continue displaying items if item is relevant
        if ($row["relevant"]=="x") {
            break;
        }

        // when the maximum number of items to display is reached, stop
        if ($itemsDisplayed >= MAXNUMITEMS) {
            break;
        }
    }

    if (ALTERNATESUBMIT!="set") {
        standardsubmit();
    }
}

function substitute($vpncode,$formulierung) {
    //	preg_replace("/LOGIKDATE2003/",date2003($vpncode),$formulierung);
    $substtext = $formulierung;

    // get all substitutions
    $query = "SELECT * FROM ".SUBSTABLE." ORDER BY id DESC";
    $result = mysql_query( $query ) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in substitute" ));
    $substitutions = array();

    while( $subst = mysql_fetch_assoc( $result ) ) {
        array_push($substitutions,$subst); // appended at the end
    }

    foreach($substitutions as $subst) {
        switch( $subst['mode'] ) {
        case NULL: // LATEST ENTRY MODE - No, it wasn't latest entry. Maybe fixed?
            $get_data = "SELECT {$subst['value']} FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND {$subst['value']} != 'NULL' ORDER BY created_at DESC LIMIT 1;";
			break;
		default:
        	$get_data = "SELECT {$subst['value']} FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND {$subst['value']} != 'NULL' AND iteration = ".$subst['mode'].";";
			break;
        }

        $query = mysql_query( $get_data ) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in substitute $get_data" ));
        if( $data = mysql_fetch_row($query) ) {
            $substtext = preg_replace( "/".$subst['key']."/", $data[0], $substtext);
        }
    }
    return $substtext;
}




function post_skipif_debug($string) {
	if(SKIPIF_DEBUG) {
		echo $string."<br/>";
	}
}

function should_skip($vpncode,$row,$timestarted) {
    $global_skip = false;
    $local_skip = false;

	if ($row["special"] != NULL) {
        // skip all special items by default
        $global_skip = true;
        $local_skip = true;

		post_skipif_debug("item id: " . $row["id"]);
		post_skipif_debug("special field set, not skipping");
	} else {
		/* also test whether the skipif string contains whitespace and don't go through this if it does */
		if ($row["skipif"] != NULL && trim($row["skipif"]) != "") {

			post_skipif_debug("<div style='margin-top: 100px; border: 1px solid black; background-color: #fdd;'>item id: " . $row["id"] . "<br/>item variable: ".$row["variablenname"]."</div>");
			post_skipif_debug("skipif statement: <br/><div style='background-color:#d85;border: 1px solid black;'><code>".$row["skipif"]."</code></div>");

			$skipif_json = json_decode($row["skipif"],true);

			// global skipif tests
			if( empty($skipif_json["global"]) ) {
				post_skipif_debug("global part of skipif is empty");
				$global_skip = false;
			} else {
				$global = $skipif_json["global"];

				// construct our query
				$mquery = "SELECT * FROM ( SELECT ";
				$i = 0;

				// compile the query
				foreach( $global as $global_skip_name => $global_skip_command ) {
					if( $global_skip_name != "mode") {
						// just a check to see if have to add a comma
						if( $i < (sizeof($global) - 2 )) {
							$mquery = $mquery." IF(".$global_skip_command["skipif"].",true,false) as '".$global_skip_name."',";
						} else {
							if( $global_skip_name != "mode") {
								$mquery = $mquery." IF(".$global_skip_command["skipif"].",true,false) as '".$global_skip_name."'";
							}
						}
						$i++;
					}
				}

				// fire the query
				$mquery = $mquery . " FROM ".RESULTSTABLE." JOIN ".VPNDATATABLE." ON ".RESULTSTABLE.".vpncode = ".VPNDATATABLE.".vpncode WHERE ".RESULTSTABLE.".vpncode='".$vpncode."') as T1";

				post_skipif_debug("global skipif query is: <br/><div style='background-color:#ddd;border:solid black 1px;'><code>". $mquery . "</code></div>");

				$mquery_result = mysql_query($mquery) or die( exception_handler($mquery . " went wrong in skipif function"));

				$global_results = array();

				// compile our results array
				// returns array containing the rows in analogous to the rows in results table with columns representing the conditions
				while($skipif_result_row = mysql_fetch_assoc($mquery_result)) {
					array_push($global_results, $skipif_result_row);
				}

				if(SKIPIF_DEBUG) {
					echo "<strong>GLOBAL SKIPIF results</strong><br/>where columns correspond to skipif statements in the global scope, and rows to results in the results table";

					echo "<div style='border: display: block;'>";
					foreach($global_results[0] as $title => $res) {
						echo "<div style='text-align: center; margin: 1px 1px 1px 1px; padding: 4px 10px 11px 12px; border: 1px solid black; height:30px; width:60px; float: left; background-color: #9f9;'><strong>".$title."</strong><br/><strong>".$global[$title]["mode"]."</strong></div>";
					}
					echo "<div style='clear: both;'></div>";

					foreach($global_results as $res) {
						echo "<div style='border: display: block;'>";
						foreach($res as $nm => $col) {
							echo "<div style='text-align: center;margin: 1px 1px 1px 1px; padding: 4px 10px 11px 12px; border: 1px solid black; height:10px; width:60px; float: left;'>".$col."</div>";
						}
						echo "<div style='clear: both;'></div>";
						echo "</div>";
					}

				}

				/*
				 * returns something like :
				 *
				 *   1  |  2  |  3  |  4  |  5  |  6  |
				 *  ------------------------------------
				 *   0  |  0  |  1  |  1  |  0  |  0  |
				 *   1  |  1  |  0  |  0  |  1  |  1  |
				 *   1  |  1  |  0  |  0  |  1  |  1  |
				 *
				 */

				$global_results_boolean = array();

				// go through each condition and check if its true with the given mode
				foreach($global as $condition_name => $statement_assoc) {
					// don't go through this if its a mode statement
					if($condition_name != "mode") {
						// go through all results for given statement check if its true
						foreach($global_results as $result_row => $row_value) {
							$condition_value = $row_value[$condition_name];
							switch($statement_assoc["mode"]) {
							case "any_true":
								if($condition_value == true) {
									$global_results_boolean[$condition_name] = 1;
									break 2;
								} else {
									$global_results_boolean[$condition_name] = 0;
								}
								break 1;
							case "any_false":
								if($condition_value == false) {
									$global_results_boolean[$condition_name] = 1;
									break 2;
								} else {
									$global_results_boolean[$condition_name] = 0;
								}
								break 1;
							case "all_true":
								if($condition_value == false) {
									$global_results_boolean[$condition_name] = 0;
									break 2;
								} else {
									$global_results_boolean[$condition_name] = 1;
								}
								break 1;
							case "all_false":
								if($condition_value == true) {
									$global_results_boolean[$condition_name] = 0;
									break 2;
								} else {
									$global_results_boolean[$condition_name] = 1;
								}
								break 1;
							}
						}
					}
				}
				
				post_skipif_debug("<div style='padding-top:10px;'><strong>GLOBAL RESULTS after evaluation</strong></div>");
				if(SKIPIF_DEBUG) {
					echo "<div style='display: block; '>";
					foreach($global_results_boolean as $total) {
						echo "<div style='text-align: center; padding: 10px 10px 10px 10px; margin: 2px 2px 2px 2px; width:60px;float:left;border: 1px solid black; background-color: #f3a;'>".$total."</div>";
					}
					echo "</div>";
					echo "<div style='clear:both;'></div>";
				}

				foreach($global_results_boolean as $condition_name => $condition_value) {
					switch($global["mode"]) {
					case "any_true":
						if($condition_value == true) {
							$global_skip = true;
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
							break 2;
						} else {
							$global_skip = false;
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>FALSE</strong>");
						}
						break 1;
					case "any_false":
						if($condition_value == false) {
							$global_skip = true;
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
							break 2;
						} else {
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>FALSE</strong>");
						}
						break 1;
					case "all_true":
						if($condition_value == false) {
							$global_skip = false;
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>FALSE</strong>");
							break 2;
						} else {
							$global_skip = true;
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
						}
						break 1;
					case "all_false":
						if($condition_value == true) {
							$global_skip = false;
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
							break 2;
						} else {
							$global_skip = true;
							post_skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
						}
						break 1;
					}
				}
			}

			if($global_skip) {
				post_skipif_debug("<strong>FINAL</strong> global result with mode: <strong>" . $global["mode"] . "</strong> is: <strong>TRUE</strong>");
			} else {
				post_skipif_debug("<strong>FINAL</strong> global result with mode: <strong>"  . $global["mode"] . "</strong> is: <strong>FALSE</strong>");
			}

			// local skipif tests
			if( empty($skipif_json["local"]) ) {
				$local_skip = false;
			} else {
				$local = $skipif_json["local"];
				$m_local_query = "SELECT * FROM ( SELECT ";
				$i_it = 0;

				foreach( $local as $local_skip_name => $local_skip_command ) {
					// construct our query
					if( $local_skip_name != "mode") {
						if( $i_it < (sizeof($local) - 2 )) {
							$m_local_query = $m_local_query." IF(".$local_skip_command["skipif"].",true,false) as '".$local_skip_name."',";
						} else {
							$m_local_query = $m_local_query." IF(".$local_skip_command["skipif"].",true,false) as '".$local_skip_name."'";
						}
						$i_it++;
					}
				}

				$m_local_query = $m_local_query . " FROM ".RESULTSTABLE." JOIN ".VPNDATATABLE." ON ".RESULTSTABLE.".vpncode = ".VPNDATATABLE.".vpncode WHERE ".RESULTSTABLE.".vpncode='".$vpncode."' AND ".RESULTSTABLE.".timestarted=".$timestarted." ORDER BY timestarted DESC LIMIT 1) as T1";

				post_skipif_debug("local skipif query:<br/><div style='border: 1px solid black;background-color: #aff;'><code>" . $m_local_query . "</code></div>");

				$local_query_result = mysql_query($m_local_query) or die( exception_handler($m_local_query . " went wrong in skipif function"));

				$local_result_row= mysql_fetch_assoc($local_query_result);

				if(SKIPIF_DEBUG) {
					echo "<div><strong>LOCAL skipif results:</strong></div>";
					echo "<div style='border: display: block;'>";
					foreach ($local_result_row as $key => $val) {
						echo "<div style='text-align: center; font-weight: bold; padding: 10px; float: left; background-color: #fcf; width: 60px; border: 1px solid black;'>".$key."</div>";
					}
					echo "<div style='clear: both;'></div>";
					echo "<div style='display:block;'>";
					foreach ($local_result_row as $key => $val) {
						echo "<div style='text-align: center; width: 60px; margin-top: 1px;padding: 10px; float:left; border: 1px solid black;'>".$val."</div>";
					}
					echo "</div>";
					echo "<div style='clear:both;'></div>";
				}

				foreach ($local_result_row as $key => $value) {
					switch($local["mode"]) {
					case "any_true":
						post_skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
						if($value == true) {
							$local_skip = true;
							post_skipif_debug("local skipif with mode: <strong>ANY_TRUE</strong> evaluates to <strong>TRUE</strong>");
							break 2;
						} else {
							$local_skip = false;
						}
						post_skipif_debug("local skipif with mode: <strong>ANY_TRUE</strong> evaluates to <strong>FALSE</strong>");
						break;
					case "any_false":
						post_skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
						if($value == false) {
							post_skipif_debug("local skipif with mode: <strong>ANY_FALSE</strong> evaluates to <strong>TRUE</strong>");
							$local_skip = true;
							break 2;
						} else {
							$local_skip = false;
						}
						post_skipif_debug("local skipif with mode: <strong>ANY_FALSE</strong> evaluates to <strong>FALSE</strong>");
						break;
					case "all_true":
						post_skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
						if($value == false) {
							post_skipif_debug("local skipif with mode: <strong>ALL_TRUE</strong> evaluates to <strong>FALSE</strong>");
							$local_skip = false;
							break 2;
						} else {
							$local_skip = true;
						}
						post_skipif_debug("local skipif with mode: <strong>ALL_TRUE</strong> evaluates to <strong>TRUE</strong>");
						break;
					case "all_false":
						post_skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
						if($value == true) {
							post_skipif_debug("local skipif with mode: <strong>ALL_FALSE</strong> evaluates to <strong>FALSE</strong>");
							$local_skip = false;
							break 2;
						} else {
							$local_skip = true;
						}
						post_skipif_debug("local skipif with mode: <strong>ALL_FALSE</strong> evaluates to <strong>TRUE</strong>");
						break;
					default:
						post_skipif_debug("no mode statement for local skipif found! please double check this as is considered a BUG");
					}
				}
			}

			if(SKIPIF_DEBUG) {
				if($local_skip) {
					echo "<div><strong>LOCAL skipif</strong> is: <strong>TRUE</strong></div>";
				} else {
					echo "<div><strong>LOCAL skipif</strong> is: <strong>FALSE</strong></div>";
				}
			}

			// if there is something in both parts of the json look for comparison method and apply, else || it
			if( ( !empty($skipif_json["local"]) && str_replace(" ","", $skipif_json["local"]) != "") AND
				( !empty($skipif_json["global"]) && str_replace(" ","", $skipif_json["global"]) != "")) {

					if( strtolower($skipif_json["mode"]) == "and") { 

						post_skipif_debug("condition contains <strong>AND</strong><br/>");

						if($local_skip && $global_skip){
							post_skipif_debug("TOTAL RESULT:<div class='skipif_true'>local && global = true</div>");
							return true;
						} else {
							post_skipif_debug("TOTAL RESULT:<div class='skipif_false'>local && global = false</div>");
							return false;
						}
					} else if( strtolower($skipif_json["mode"]) == "or") {

						post_skipif_debug("condition contains <strong>OR</strong><br/>");

						if($local_skip || $global_skip){
							post_skipif_debug("TOTAL RESULT:<div class='skipif_true'>local || global = true</div>");
							return true;
						} else {
							post_skipif_debug("TOTAL RESULT:<div class='skipif_false'>local || global = false</div>");
							return false;
						}
					} else {
						post_skipif_debug("condition contains nothing useful<br/>");

						if($local_skip || $global_skip){
							post_skipif_debug("TOTAL RESULT:<div class='skipif_true'>local || global = true</div>");
							return true;
						} else {
							post_skipif_debug("TOTAL RESULT:<div class='skipif_false'>local || global = false</div>");
							return false;
						}
					}
				} else {
					if($local_skip || $global_skip){
						post_skipif_debug("condition contains nothing<br/>");
						post_skipif_debug("TOTAL RESULT:<div class='skipif_true'>local || global = true</div>");
						return true;
					} else {
						post_skipif_debug("TOTAL RESULT:<div class='skipif_false' >local || global = false</div>");
						return false;
					}
				}
		} else {
			// has no skipif
			return false;
		}
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

function queue_email($vpncode,$study,$type) {
    if($type == 'loop') {
        // if this looped study sends out reminders after each iteration, queue them now
        $email_queue = "SELECT ".STUDIESTABLE.".loopemail,".STUDIESTABLE.".loopemail_id,".EMAILSTABLE.".delta,".EMAILSTABLE.".abstime FROM ".STUDIESTABLE." JOIN ".EMAILSTABLE." ON ".STUDIESTABLE.".loopemail_id = ".EMAILSTABLE.".id WHERE ".STUDIESTABLE.".name = '". $study."';";
        $result = mysql_query( $email_queue ) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in queue_email" ));
        $email_data = mysql_fetch_assoc($result);
        if($email_data['loopemail']) {
            ///now queue the thing in the message queue with the right absolute timestamp and message id
            queue_message($vpncode,$email_data['loopemail_id'],$email_data['abstime'],$email_data['delta']);
        }
    } elseif($type == 'post') {
        /* do the email queuing if necessary */
        $email_queue = "SELECT ".STUDIESTABLE.".postemail,".STUDIESTABLE.".postemail_id,".EMAILSTABLE.".delta,".EMAILSTABLE.".abstime FROM ".STUDIESTABLE." JOIN ".EMAILSTABLE." ON ".STUDIESTABLE.".postemail_id = ".EMAILSTABLE.".id WHERE ".STUDIESTABLE.".name = '". $study."';";
        $result = mysql_query( $email_queue ) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in sendmail" ));
        $email_data = mysql_fetch_assoc($result);
        if($email_data['postemail']) {
            ///now queue the thing in the message queue with the right absolute timestamp and message id
            queue_message($vpncode,$email_data['postemail_id'],$email_data['abstime'],$email_data['delta']);
        }
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


function post_study_hook($vpncode,$study) {
    /* this should be done all the time */
    $query = "SELECT ".VPNDATATABLE.".id AS vpncode_id,".VPNDATATABLE.".vpncode,".VPNDATATABLE.".study AS vpncode_study,
        ".STUDIESTABLE.".loop AS study_loop,".STUDIESTABLE.".order AS study_order,".STUDIESTABLE.".iterations AS max_iterations,
        MAX(".RESULTSTABLE.".iteration) as iteration FROM ".VPNDATATABLE."
        LEFT JOIN ".STUDIESTABLE." ON (".STUDIESTABLE.".name = ".VPNDATATABLE.".study)
        LEFT JOIN ".RESULTSTABLE." ON (".VPNDATATABLE.".vpncode = ".RESULTSTABLE.".vpncode) WHERE ".RESULTSTABLE.".vpncode='$vpncode' GROUP BY vpncode_id;";

    //wenn studie in der user ist loop ist check wie viele iterationen der schon gemacht und entscheide dementsprechend ob er weiter kommt oder nicht
    $results = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in post_study_hook" ));
    $row = mysql_fetch_assoc($results);

	$num_attempts_query = 'SELECT count(*) as count FROM '.RESULTSTABLE.' WHERE vpncode="'.$vpncode.'" AND study="'.$study.'"';
	$num_attempts_result = mysql_query( $num_attempts_query ) or die( exception_handler( mysql_error() . "<br/>" . $num_attempts_query . "<br/> in post_study_hook"));
	$num_attempts = mysql_fetch_assoc( $num_attempts_result );

	$study_data = get_study_data($study);

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

function send_invitation($vpncode) {
    $query = "SELECT * FROM ".VPNDATATABLE." WHERE vpncode='".$vpncode."'";
    $data = mysql_query( $query ) or die(  exception_handler(mysql_error() . "<br/>" . $query . "<br/> in send_invitation" ));
    $data = mysql_fetch_assoc($data);

	$config = get_config();

    $url = "http://" . get_base_path() . "/survey.php?vpncode=" . $vpncode;
	// $date = "Date: " . date("d-m-Y H.i.s T");
    $from = "From: " . $config["email_header_from"];
    $reply_to = "Reply-To: " . $config["email_header_reply_to"];
	$cc = "Cc: " . $config["email_header_cc"];
	$bcc = "Bcc: " . $config["email_header_bcc"];
	$content_type = "Content-Type: text/plain; charset=utf-8";
	$header = $from . PHP_EOL . $reply_to . PHP_EOL . $cc . PHP_EOL . $bcc . PHP_EOL . $content_type . PHP_EOL . 'X-Mailer: PHP/' . phpversion();
    $to = $data["email"];
    $subject = $config["email_subject_text"];
    $body = $config["email_body_text"] . PHP_EOL . $url;

    $mail = mail($to,$subject,$body,$header);
	return $mail;
}

function send_feedback_permalink($vpncode) {
    $query = "SELECT * FROM ".VPNDATATABLE." WHERE vpncode='".$vpncode."'";
    $data = mysql_query( $query ) or die(  exception_handler(mysql_error() . "<br/>" . $query . "<br/> in send_invitation" ));
    $data = mysql_fetch_assoc($data);

	$config = get_config();

    $url = "http://" . get_base_path() . $_SERVER['REQUEST_URI'];
	// $date = "Date: " . date("d-m-Y H.i.s T");
    $from = "From: " . $config["email_header_from"];
    $reply_to = "Reply-To: " . $config["email_header_reply_to"];
	$cc = "Cc: " . $config["email_header_cc"];
	$bcc = "Bcc: " . $config["email_header_bcc"];
	$content_type = "Content-Type: text/plain; charset=utf-8";
	$header = $from . PHP_EOL . $reply_to . PHP_EOL . $cc . PHP_EOL . $bcc . PHP_EOL . $content_type . PHP_EOL . 'X-Mailer: PHP/' . phpversion();
    $to = $data["email"];
    $subject = $config["email_subject_text"];
    $body = $url;

    $mail = mail($to,$subject,$body,$header);
	return $mail;
}

function next_study($vpncode,$row) {
    // study_order will only ever be empty when the state is set to finished
    // in which case we can safely ignore doing any more work
    if( !empty($row['study_order']) ) {
        $query = "SELECT id,name FROM ".STUDIESTABLE." WHERE `order` > ".$row['study_order']." ORDER BY `order`;";
        $studies = mysql_query($query) or die(  exception_handler(mysql_error() . "<br/>" . $query . "<br/> in next_study" ));

        $query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."';";
        $results = mysql_query( $query ) or die(  exception_handler(mysql_error() . "<br/>" . $update . "<br/> in next_study" ));
        $allresults = array();
        for($i = 0; $i < mysql_num_rows($results); $i++)  {
            array_push($allresults,mysql_fetch_assoc($results));
        }

        if( mysql_num_rows($studies) > 0) {
            for($i = 0; $i < mysql_num_rows($studies); $i++) {
                $study = mysql_fetch_assoc($studies);
                if( !skip_study($vpncode,$study,$allresults) ) {
                    $next_study = $study['name'];
                    break;
                }
            }
            if( !empty($next_study) ) {
                /* finally, set the next study in the vpndata table */
                $query = "UPDATE ".VPNDATATABLE." SET study='".$next_study."' WHERE vpncode='".$vpncode."';";
                mysql_query( $query ) or die(  exception_handler(mysql_error() . "<br/>" . $query . "<br/> in next_study" ));
            } else {
                /* if all studies left have been skipped, we're done completely */
                $query = "UPDATE ".VPNDATATABLE." SET study='finished' WHERE vpncode='".$vpncode."';";
                mysql_query( $query ) or die(  exception_handler(mysql_error() . "<br/>" . $query . "<br/> in next_study" ));
            }
        } else {
            /* if there are no more studies left, set to finished */
            $query = "UPDATE ".VPNDATATABLE." SET study='finished' WHERE vpncode='".$vpncode."';";
            mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in next_study" ));
        }
    } else {
        post_debug("ERROR: study order field is empty!");
    }
}

function skip_study($vpncode,$study,$results) {
    $studyskip = false;

    foreach( $results as $result ) {
		// post_debug("<strong>skip_study</strong> fixme :)");
		// timestarted set to 0 as we cannot use local skipifs here anyways
        if( should_skip($vpncode,$result,0) ) {
            $studyskip = true;
			post_debug("<strong>skip_study:</strong> is TRUE");
            break;
        }
    }
	post_debug("<strong>skip_study:</strong> is FALSE");
    return $studyskip;
}

function printitem($id, $variablenname, $typ, $wortlaut, $antwortformatanzahl, $ratinguntererpol, $ratingobererpol, $allowedtypes, $zeile, $next, $rows, $timestarted, $vpncode) {

    // FIX: Fehlende itemtypen:
    // mcm -multiple answer - einfach neuen Typ mcm, und in printitem eine entsprechende Zeile mit Setting mehrfachnennung. In Results jede einzelne Antwort als Variable. SCHEIßE!

    // in welche Farbe wird das Item unterlegt?
    // Stelle fest, ob es schon einmal angezeigt wurde

	$check="select displaycount from " . ITEMDISPLAYTABLE . " where variablenname='$variablenname' and vpncode='".$vpncode."';";
	$checkthis = mysql_query($check) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitem" ));
	$displayedbefore = mysql_fetch_assoc($checkthis);
	if ($displayedbefore["displaycount"]!="" AND $displayedbefore["displaycount"]!=NULL AND $displayedbefore["displaycount"]>=0){
		// item was displayed before
		post_debug("<strong>printitem:</strong> item was displayed before");

		$item_disp = "item-displayed";
		if( is_odd($zeile) ) {
			$oe = "even-repeat";
		} else {
			$oe = "odd-repeat";
		}
	} else {
		// item was not yet displayed
		post_debug("<strong>printitem:</strong> item was not displayed before");
		$item_disp = "item";

		if( is_odd($zeile) ) {
			$oe = "even";
		} else {
			$oe = "odd";
		}
	}

	if (!in_array($typ,$allowedtypes)) {
		post_debug("$id -> $variablenname <strong>not a valid item</strong>");
	}

	// outmost div
	if ($typ!="instruktion") 	echo "<div class='$oe survey-item'>\n\n";

	// INSTRUKTION
	if ($typ=="instruktion") {
		if ($rows[$zeile+1]['typ'] != "instruktion" AND $rows[$zeile+1]['typ'] != "fork") {
			echo "<div class='$oe survey-item'>"."\n\n<div class=\"". $item_disp . " instruction $instruktioncss\"><p>"; 
			echo $wortlaut;
			echo "<input type=\"hidden\" name=\"$variablenname\" value=\"done\" /></p></div></div>\n<div class=\"clearer\"></div>\n";
		}
	}

    // RATING
    if ($typ=="rating") {
		echo '<div class="left">';
		echo '<div class="item-description"><p class="' . $typ . '">' . $wortlaut . '</p></div>';
		echo '</div>';
        // if both poles are numeric
        if (is_numeric($ratinguntererpol) && is_numeric($ratingobererpol)) {
            // FIX Hier gehört eine $step Variable hin!
            echo '<div class="right rating-input">';
            for ($k=$ratinguntererpol; $k <= $ratingobererpol; $k++) {
				echo "<div class=\"integerrating\">";
				echo "<input type=\"radio\" id=\"".$variablenname."_".$k."\" name=\"$variablenname\" value=\"$k\" />";
				echo "<label for=\"" . $variablenname . "_". $k ."\">" . $k . "</label>";
				echo "</div>";
            }
            echo "</div>\n";

        } elseif (is_numeric($ratinguntererpol) && !is_numeric($ratingobererpol) && ((!is_numeric(substr($ratingobererpol,-1)) or ((substr($ratingobererpol,-1)=="+"))))) {
            // Wenn unterer numerisch, oberer nicht
            // FIX: auch andersherum
            // FIXED? Aufpassen, damit wir nicht zu hoch zählen! Fall $ratinguntererpol != 0 ist ungeprüft
            if ($ratinguntererpol == 0) {
                $anzahl=$antwortformatanzahl-1;
            } else {
                $anzahl=$antwortformatanzahl;
            }

            // Mache eine kleine Table auf, damit unser Rating schön dargestellt wird
            echo "<div class='right small-table'>\n";
            for ($k=$ratinguntererpol; $k <= $anzahl; $k++) {
                if ($k < $anzahl){
                    // alle bis zur letzten Checkbox
                    echo "<div class=\"integerrating\"><input type=\"radio\" name=\"$variablenname\" value=\"$k\" />" . ($k) . "</div>";
                } else {
                    // die letzte Checkbox
                    echo "<div class=\"mixed-last-rating\"><input type=\"radio\" name=\"$variablenname\" value=\"$k\" />" . ($ratingobererpol) . "</div>";
                    break;
                }
            }
            echo "</div>\n";

        } else {
            // WENN beide Itempole Text sind
            // Mache eine kleine Table auf, damit unser Rating schön dargestellt wird
            echo "<div class='right small-table'>";
            echo "<span class=\"text-first-rating\" style='width:". round(SRVYTBLWIDTH/6,0) ."' />$ratinguntererpol</span>";
            for ($k=1; $k <= $antwortformatanzahl; $k++) {
                echo "<input type=\"radio\" name=\"" . $variablenname . "\" value=\"$k\" />";
            }
            echo "<span class=\"text-last-rating\" style='width:". round(SRVYTBLWIDTH/6,0) ."' />$ratingobererpol</span>";
            echo "</div>\n";
        }

    }

    // ym (Jahr / Monat picker)

	if ($typ=="ym") {
		echo "<div class='left'>";
		echo "<div class='item-description'><p class='$typ'>$wortlaut</p></div>\n";
		echo "</div>";
		echo "<div class='right year-month-picker'>\n";
		echo "<select name=\"" . $variablenname . "mmcaltyears\">\n";
		for ($years=0; $years <= $antwortformatanzahl; $years++) {
			echo "<option value=\"" . $years . "\">". $years . "</option>";
		}
		echo "</select> Jahre und ";
		echo "<select name=\"" . $variablenname . "mmcaltmonths\">\n";
		for ($months=0; $months <= 11; $months++) {
			echo "<option value=\"" . $months . "\">". $months . "</option>";
		}
		echo "</select> Monate\n";
		echo "</div>\n";
	}

    // datepicker

    if ($typ=="datepicker") {
		echo "<div class='left'>";
		echo "<div class='item-description'><p class='$typ'>$wortlaut</p></div>\n";
		echo "</div>";
		echo "<div class='right date-picker-answer'>\n";

        echo "<select name=\"" . $variablenname . "mmcaltday\">";
        for ($day=1; $day <= 31; $day++) {
            echo "<option value=\"" . $day . "\">". $day . "</option>";
        }
        echo "</select>";

        $months = array("Januar","Februar","März","April","Mai","Juni","Juli","August","September","Oktober","November","Dezember");
        echo "<select name=\"" . $variablenname . "mmcaltmonth\">";
        $mnr=1;
        foreach ($months as $month) {
            echo "<option value=\"" . $mnr . "\">". $month . "</option>";
            $mnr++;
        }
        echo "</select>";

        echo "<select name=\"" . $variablenname . "mmcaltyear\">";
        $year = date(Y);
        for ($printyear=$year; $printyear >= ($year-$antwortformatanzahl); $printyear--) {
            echo "<option value=\"" . $printyear . "\">". $printyear . "</option>";
        }
		echo "</select>";
        echo "</div>\n";
    }


    // MULTIPLE CHOICE
    if ($typ=="mc") {
		echo "<div class='left'>";
		echo "<div class='item-description'><p class='$typ'>$wortlaut</p></div>\n";
		echo "</div>";
		echo "<div class='right multiple-choice'>\n";

		//fetch data to corresponding item
        $query="SELECT * FROM ".ITEMSTABLE." WHERE id ='$id'";
        $mc_query_results = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitem" ));
        $thismc=mysql_fetch_array( $mc_query_results );

        if (mysql_numrows($mc_query_results) > 1) die ("massiver Fehler! Die id " . $id . " ist 2x vergeben");

		// and display it
        for ($k=1; $k <= $antwortformatanzahl; $k++) {
			echo "<div class='mc-radio-input'>";
			echo "<input type=\"radio\" id=\"".$variablenname."_".$k."\" name=\"" . $variablenname . "\" value=\"$k\" />";
			echo "<label for=\"".$variablenname."_".$k."\"> " . $thismc[MCalt.$k] . "</label>\n";
			echo "</div>";
        }
        echo "</div>\n";
    }

    // IMAGE RATING
    if ($typ=="imc") {
		echo "<div class='left'>";
		echo "<div class='item-description'><p class='$typ'>$wortlaut</p></div>\n";
		echo "</div>";

        echo "<div class='right image-rating'>";

		//fetch data to corresponding item
        $query="SELECT * FROM ".ITEMSTABLE." WHERE id ='$id'";
        $imc_rating_results = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitem" ));
        $thismc=mysql_fetch_array( $imc_rating_results );

        if (mysql_numrows( $imc_rating_results ) > 1) die ("massiver Fehler! Die id " . $id . " ist 2x vergeben");

		// and display it
        for ($k=1; $k <= $antwortformatanzahl; $k++) {
            echo "<div class='image-input'><img src=".$thismc[MCalt.$k]." ></img><input type=\"radio\" id=\"".$variablenname."_".$k."\" name=\"" . $variablenname . "\" value=\"$k\" /></div>";
        }
        echo "</div>\n";
    }

    // Multiple MULTIPLE CHOICE (mehrere Antwortmöglichkeiten gleichzeitig wählbar)
    if ($typ=="mmc") {
		echo "<div class='left'>";
		echo "<div class='item-description'><p class='$typ'>$wortlaut</p></div>\n";
		echo "</div>";

        echo "<div class='right multiple-multiple-choice'>";

		//fetch data corresponding to this item
        $query="SELECT * FROM ".ITEMSTABLE." WHERE id ='$id'";
        $mmc_results_query = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitem" ));
        $thismc=mysql_fetch_array( $mmc_results_query );

        if (mysql_numrows( $mmc_results_query ) > 1) die ("massiver Fehler! Die id " . $id . " ist 2x vergeben");

		// and display it
        for ($k=1; $k <= $antwortformatanzahl; $k++) {
			echo "<div class='mmc-input'><input type=\"checkbox\" name=\"" . $variablenname . "mmcalt" . $k . "\" value=\"1\" /></div>\n";
			echo "<div class='mmc-checkbox-label'> " . $thismc[MCalt.$k] . "</div>";
        }
        echo "</div>\n";
    }

    // OFFEN
    if ($typ=="offen") {
		echo "<div class='left'>";
		echo "<div class='item-description'><p class='$typ'>$wortlaut</p></div>\n";
		echo "</div>";

        echo "<div class='right open-answer'>";

		// how big should the box be?
        if ($antwortformatanzahl > TXTBXWIDTH) {
            $rows = round($antwortformatanzahl/TXTBXWIDTH);
            echo "<textarea cols=\"" .  TXTBXWIDTH . "\" rows=\"" . $rows ."\" name=\"" . $variablenname . "\" ></textarea>";
        } else {

            echo "<textarea cols=\"" .  $antwortformatanzahl . "\" rows=\"1\" name=\"" . $variablenname . "\" ></textarea>";
        }
        echo "</div>\n";
    }

    if ($typ=="pse") {
		echo "\n\n<div><p>"; 
		echo $wortlaut;
		echo "</p><p>";

		// how big should the box be?
        if ($antwortformatanzahl > (TXTBXWIDTH*2)) {
            $rows = round($antwortformatanzahl/(TXTBXWIDTH*2));
            echo "<textarea cols=\"" .  (TXTBXWIDTH*2) . "\" rows=\"" . $rows ."\" name=\"" . $variablenname . "\" ></textarea>";
        } else {

            echo "<textarea cols=\"" .  $antwortformatanzahl . "\" rows=\"1\" name=\"" . $variablenname . "\" ></textarea>";
        }
?>
<script type="text/javascript">
	window.onload = function () {
		document.getElementById("weiterbutton").disabled = true; // make inactive as long as we wait
		document.getElementsByTagName("img")[1].style.display = 'none'; // hide after 10s
		setTimeout(function() {
			document.getElementsByTagName("img")[1].style.display = 'inline'; // show when read
			setTimeout(function() {
				document.getElementsByTagName("img")[1].style.display = 'none'; // hide after 10s
			},10*1000);
		},20*1000);
		setTimeout(function() {
			document.getElementById("weiterbutton").disabled = false; // activate after 4
		},4.5*60*1000);
		setTimeout(function() {
			alert("Sie haben noch 20 Sekunden bis es automatisch weitergeht.");
		},5.16*60*1000);
		setTimeout(function() { // and submit after 5
			document.getElementsByTagName("textarea")[0].readOnly = true;
			document.getElementsByTagName("textarea")[0].value = document.getElementsByTagName("textarea")[0].value + "**//autofin";
			document.forms[0].submit();
		},5.49*60*1000);
	}
</script>
<?php
        echo "</p></div>\n";
    }


    // FORK
    if ($typ=="fork") {
		if(SUPPRESS_FORK == false) {
			define('ALTERNATESUBMIT', 'set');
			if(TIMEDMODE) {
				$link = $ratinguntererpol . "?vpncode=" . $vpncode . "&ts=" . $timestarted;
			} else {
				$link = $ratinguntererpol . "?vpncode=" . $vpncode;
			}
			echo "<div class=\"instruction\"><script type=\"text/javascript\">document.location.href = \"$link\";</script>" . $wortlaut . "</div>";
			echo "<div class=\"bottom-submit\"><a href=\"" . $link . "\">WEITER</a></div>";
		} else {
			echo "<div><strong>SUPPRESS FORK MODE ACTIVATED: </strong> to turn this off, visit global settings in /admin</div>";
		}
    }

	// EMAIL
	if ($typ=="email") {
		echo "<div class='$oe'>\n";
		echo "<div class='item-description'><p class='$typ'>$wortlaut</p></div>\n";

		echo "<div class='right email-answer'>";

		// if ($antwortformatanzahl > TXTBXWIDTH) {
		//     $rows = round($antwortformatanzahl/TXTBXWIDTH);
		//     echo "<table id='".$variablenname."_table'>";
		//     echo "<tr><td><textarea id='".$variablenname."_A' style='margin: 3px;' onKeyUp='checkEmail(\"".$variablenname."_table\",this,\"".$variablenname."_B\")' cols=\"" .  TXTBXWIDTH . "\" rows=\"" . $rows ."\" name=\"" . $variablenname . "\" ></textarea></td></tr>";
		//     echo "<tr><td><textarea id='".$variablenname."_B' style='margin: 3px;' onKeyUp='checkEmail(\"".$variablenname."_table\",this,\"".$variablenname."_A\")' cols=\"" .  TXTBXWIDTH . "\" rows=\"" . $rows ."\" name=\"" . $variablenname."_check". "\" ></textarea></td></tr>";
		//     echo "</table>";
		//     echo "</td></tr>";
		// } else {
		//     echo "<table id='".$variablenname."_table'>";
		//     echo "<tr><td><textarea id='".$variablenname."_A' style='margin: 3px;' onKeyUp='checkEmail(\"".$variablenname."_table\",this,\"".$variablenname."_B\")' cols=\"" .  $antwortformatanzahl . "\" rows=\"1\" name=\"" . $variablenname . "\" ></textarea></td></tr>";
		//     echo "<tr><td><textarea id='".$variablenname."_B' style='margin: 3px;' onKeyUp='checkEmail(\"".$variablenname."_table\",this,\"".$variablenname."_A\")' cols=\"" .  $antwortformatanzahl . "\" rows=\"1\" name=\"" . $variablenname."_check"."\" ></textarea></td></tr>";
		//     echo "</table>";
		//     echo "</td></tr>";
		// }

		// FIXME: js email validation in a global js file
        // echo "<script type=\"text/javascript\" src=\"js/email.js\"></script>";

		echo "<textarea id='$variablenname'></textarea>\n";
		echo "</div>\n";
		echo "</div>\n";
    }

	//close the item div
	if ($typ!="instruktion") 	echo "</div>\n";
	if ($typ!="instruktion") 	echo "<div class='clearer'></div>\n";
}

function hiddeninput($name,$value) {
    echo "<input type=\"hidden\" name=\"" . $name . "\" value=\"" . $value . "\" />";
}

function is_odd( $int ) {
    return( $int & 1 );
}

// Design-Funktionen
function Bild($name) {
    // Bild-Datei muss ich im imagefolder befinden, das in Settings bestimmt wird.
    $datei = IMAGEFOLDER . $name;
    if (file_exists($datei)) {
        list($width, $height, $type, $attr) = getimagesize("$datei");
        echo "<img src=\"$datei\" $attr alt=\"\" />";
    } else {
        echo "Bild " . $datei . "existiert nicht!";
    }
}

function progress($vpncode,$study) {
    $query = "SELECT *
        FROM ".ITEMSTABLE."
        WHERE skipif =  \"\"
        AND study = '$study'
        AND typ !=  \"instruktion\"
        AND special =  \"\"";
    $items=mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in progress" ));
    $allitems=mysql_num_rows($items);
    mysql_free_result($items);

    // hole dir alle Werte dieser Person
    $query="SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='$study'";
    $dieseperson=mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in progress" ));
    $result=mysql_fetch_assoc($dieseperson);

    $already_answered = array();
    foreach ($result as $key => $value) {
        if ($value != NULL) {
            array_push($already_answered, $key);
        }
    }
    echo mysql_error();

    $query="SELECT * FROM ".ITEMSTABLE." WHERE study='$study' AND typ!='Instruktion' AND skipif =  \"\" AND special =  \"\" AND variablenname NOT IN (". implode(",", array_map('quote', $already_answered)) . ")";
    $openitems=mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in progress" ));
    $unanswered=mysql_numrows($openitems);
    mysql_free_result($openitems);
    $answered=$allitems-$unanswered;
    $progress=$answered/$allitems;
    return round($progress,2)*100;
}

function progressbar($progress) {
    $width=round($progress, 0)*(SRVYTBLWIDTH/100);

    echo "<div class=\"progressbackground\" style=\"width:" . SRVYTBLWIDTH ."px\"><div class=\"progressbar\" style=\"width:$width"."px\"></div><div style=\"z-index: 4; position: relative; left:0px; top:-20px\">" . $progress . "% abgeschlossen</div></div>";
}

// Nicht-Standard-Funktionen
/*
function date2003($vpncode) {
    $result = mysql_query("SELECT * FROM " . VPNDATATABLE . " WHERE ". VPNDATATAVPNCODE ."='$vpncode' LIMIT 1");
    if (mysql_num_rows($result) <=0 ) {
        return "2003";
    } else {
        return date('d.m.Y',strtotime(mysql_result($result, 0, "date2003")));
    }
}
     */

function pre_post_feedback($vpncode, $coordinates, $data, $partner,$attachment) {
	switch ($data) {
	case "pretest":
		$get_values_query = "SELECT 
			`Extra1_E_pre` as Extra1_E,
			`Extra2_E_pre` as Extra2_E,
			`Extra3_E_pre` as Extra3_E,
			`Extra4_E_pre` as Extra4_E,
			`Vertr1_E_pre` as Vertr1_E,
			`Vertr2_E_pre` as Vertr2_E,
			`Vertr3_E_pre` as Vertr3_E,
			`Vertr4_E_pre` as Vertr4_E,
			`Gewiss1_E_pre` as Gewiss1_E,
			`Gewiss2_E_pre` as Gewiss2_E,
			`Gewiss3_E_pre` as Gewiss3_E,
			`Gewiss4_E_pre` as Gewiss4_E,
			`Neuro1_E_pre` as Neuro1_E,
			`Neuro2_E_pre` as Neuro2_E,
			`Neuro3_E_pre` as Neuro3_E,
			`Neuro4_E_pre` as Neuro4_E,
			`Offen1_E_pre` as Offen1_E,
			`Offen2_E_pre` as Offen2_E,
			`Offen3_E_pre` as Offen3_E,
			`Offen4_E_pre` as Offen4_E,
			`Offen5_E_pre` as Offen5_E
			FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='pretest' AND
			`Extra1_E_pre` IS NOT NULL AND
			`Extra2_E_pre` IS NOT NULL AND
			`Extra3_E_pre` IS NOT NULL AND
			`Extra4_E_pre` IS NOT NULL AND
			`Vertr1_E_pre`  IS NOT NULL AND
			`Vertr2_E_pre` IS NOT NULL AND
			`Vertr3_E_pre`  IS NOT NULL AND
			`Vertr4_E_pre`  IS NOT NULL AND
			`Gewiss1_E_pre`  IS NOT NULL AND
			`Gewiss2_E_pre`  IS NOT NULL AND
			`Gewiss3_E_pre`  IS NOT NULL AND
			`Gewiss4_E_pre`  IS NOT NULL AND
			`Neuro1_E_pre`  IS NOT NULL AND
			`Neuro2_E_pre`  IS NOT NULL AND
			`Neuro3_E_pre`  IS NOT NULL AND
			`Neuro4_E_pre`  IS NOT NULL AND
			`Offen1_E_pre`  IS NOT NULL AND
			`Offen2_E_pre`  IS NOT NULL AND
			`Offen3_E_pre`  IS NOT NULL AND
			`Offen4_E_pre`  IS NOT NULL AND
			`Offen5_E_pre` IS NOT NULL
			LIMIT 1";
		break;
	case "posttest":
		// if its the post study feedback get different values
		$get_values_query = "SELECT 
			`Extra1_E_post` as Extra1_E,
			`Extra2_E_post` as Extra2_E,
			`Extra3_E_post` as Extra3_E,
			`Extra4_E_post` as Extra4_E,
			`Vertr1_E_post` as Vertr1_E,
			`Vertr2_E_post` as Vertr2_E,
			`Vertr3_E_post` as Vertr3_E,
			`Vertr4_E_post` as Vertr4_E,
			`Gewiss1_E_post` as Gewiss1_E,
			`Gewiss2_E_post` as Gewiss2_E,
			`Gewiss3_E_post` as Gewiss3_E,
			`Gewiss4_E_post` as Gewiss4_E,
			`Neuro1_E_post` as Neuro1_E,
			`Neuro2_E_post` as Neuro2_E,
			`Neuro3_E_post` as Neuro3_E,
			`Neuro4_E_post` as Neuro4_E,
			`Offen1_E_post` as Offen1_E,
			`Offen2_E_post` as Offen2_E,
			`Offen3_E_post` as Offen3_E,
			`Offen4_E_post` as Offen4_E,
			`Offen5_E_post` as Offen5_E
			FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND study='posttest' AND
			`Extra1_E_post` IS NOT NULL AND
			`Extra2_E_post` IS NOT NULL AND
			`Extra3_E_post` IS NOT NULL AND
			`Extra4_E_post` IS NOT NULL AND
			`Vertr1_E_post`  IS NOT NULL AND
			`Vertr2_E_post` IS NOT NULL AND
			`Vertr3_E_post`  IS NOT NULL AND
			`Vertr4_E_post`  IS NOT NULL AND
			`Gewiss1_E_post`  IS NOT NULL AND
			`Gewiss2_E_post`  IS NOT NULL AND
			`Gewiss3_E_post`  IS NOT NULL AND
			`Gewiss4_E_post`  IS NOT NULL AND
			`Neuro1_E_post`  IS NOT NULL AND
			`Neuro2_E_post`  IS NOT NULL AND
			`Neuro3_E_post`  IS NOT NULL AND
			`Neuro4_E_post`  IS NOT NULL AND
			`Offen1_E_post`  IS NOT NULL AND
			`Offen2_E_post`  IS NOT NULL AND
			`Offen3_E_post`  IS NOT NULL AND
			`Offen4_E_post`  IS NOT NULL AND
			`Offen5_E_post` IS NOT NULL
			LIMIT 1";
			break;
	}

	$results = mysql_query($get_values_query) or die( exception_handler(mysql_error() . "<br/>" . $get_values_query . "<br/> in get_values query"));
	$row = mysql_fetch_assoc($results);

	if( isset($row['Extra1_E']) && isset($row['Extra2_E']) && isset($row['Extra3_E']) && isset($row['Extra4_E']) && 
		isset($row['Vertr1_E']) && isset($row['Vertr2_E']) && isset($row['Vertr3_E']) && isset($row['Vertr4_E']) && 
		isset($row['Gewiss1_E']) && isset($row['Gewiss2_E']) && isset($row['Gewiss3_E']) && isset($row['Gewiss4_E']) && 
		isset($row['Neuro1_E']) && isset($row['Neuro2_E']) && isset($row['Neuro3_E']) && isset($row['Neuro4_E']) && 
		isset($row['Offen1_E']) && isset($row['Offen2_E']) && isset($row['Offen3_E']) && isset($row['Offen4_E']) && isset($row['Offen5_E']) )
	{ 
		$is_complete = true; 
	} else { 
		$is_complete = false; 
	}


	if( $is_complete ) {
		$Extra_per = ((6 - $row['Extra1_E']) + $row['Extra2_E'] + (6 - $row['Extra3_E']) + $row['Extra4_E']) / 4;
		$Vertr_per = ((6 - $row['Vertr1_E']) + $row['Vertr2_E'] + (6 - $row['Vertr3_E']) + (6 - $row['Vertr4_E'])) / 4;
		$Gewiss_per = ($row['Gewiss1_E'] + (6 - $row['Gewiss2_E']) + $row['Gewiss3_E'] + $row['Gewiss4_E']) / 4;
		$Neuro_per = ($row['Neuro1_E'] + (6 - $row['Neuro2_E']) + $row['Neuro3_E'] + $row['Neuro4_E']) / 4;
		$Offen_per = ($row['Offen1_E'] + $row['Offen2_E'] + $row['Offen3_E'] + $row['Offen4_E'] + (6 - $row['Offen5_E'])) / 5;

		$Extra_M = 3.48;
		$Extra_SD = 0.87;
		$Vertr_M = 3.02;
		$Vertr_SD = 0.73;
		$Gewiss_M = 3.53;
		$Gewiss_SD = 0.69;
		$Neuro_M = 2.88;
		$Neuro_SD = 0.77;
		$Offen_M = 3.96;
		$Offen_SD = 0.62;

		if( $Extra_per <= ($Extra_M - (2 * $Extra_SD))) {
			$extraversion = "1";
		} elseif( ($Extra_per > ($Extra_M - (2 * $Extra_SD))) AND ($Extra_per <= ($Extra_M - $Extra_SD)) ) {
			$extraversion = "2";
		} elseif( ($Extra_per > ($Extra_M - $Extra_SD))  AND ($Extra_per < ($Extra_M + $Extra_SD)) ) {
			$extraversion = "3"; 
		} elseif( ($Extra_per >= ($Extra_M + $Extra_SD)) AND ($Extra_per < ($Extra_M + (2 * $Extra_SD))) ) {
			$extraversion = "4";
		} elseif( ($Extra_per >= ($Extra_M + (2 * $Extra_SD))) ) {
			$extraversion = "5";
		}

		if( ($Vertr_per <= ($Vertr_M - (2 * $Vertr_SD) ))) {
			$compatibility = "1";
		} elseif( ($Vertr_per > ($Vertr_M - (2 * $Vertr_SD))) AND ($Vertr_per <= ($Vertr_M - $Vertr_SD)) ) {
			$compatibility = "2";
		} elseif( ($Vertr_per > ($Vertr_M - $Vertr_SD))  AND ($Vertr_per < ($Vertr_M + $Vertr_SD)) ) {
			$compatibility = "3";
		} elseif( ($Vertr_per >= ($Vertr_M + $Vertr_SD)) AND ($Vertr_per < ($Vertr_M + (2 * $Vertr_SD)) )) {
			$compatibility = "4";
		} elseif( ($Vertr_per >= ($Vertr_M + (2 * $Vertr_SD))) ) {
			$compatibility = "5";
		}

		if( ($Gewiss_per <= ($Gewiss_M - (2 * $Gewiss_SD))) ) {
			$diligence = "1";
		} elseif( ($Gewiss_per > ($Gewiss_M - (2 * $Gewiss_SD))) AND ($Gewiss_per <= ($Gewiss_M - $Gewiss_SD)) ) {
			$diligence = "2";
		} elseif( ($Gewiss_per > ($Gewiss_M - $Gewiss_SD))  AND ($Gewiss_per < ($Gewiss_M + $Gewiss_SD ))) {
			$diligence = "3";
		} elseif( ($Gewiss_per >= ($Gewiss_M + $Gewiss_SD)) AND ($Gewiss_per < ($Gewiss_M + (2 * $Gewiss_SD))) ) {
			$diligence = "4";
		} elseif( ($Gewiss_per >= ($Gewiss_M + (2 * $Gewiss_SD))) ) {
			$diligence = "5";
		}

		if( ($Neuro_per <= ($Neuro_M - (2 * $Neuro_SD))) ) {
			$emotional = "1";
		} elseif( ($Neuro_per > ($Neuro_M - (2 * $Neuro_SD))) AND ($Neuro_per <= ($Neuro_M - $Neuro_SD)) ) {
			$emotional = "2";
		} elseif( ($Neuro_per > ($Neuro_M - $Neuro_SD))  AND ($Neuro_per < ($Neuro_M + $Neuro_SD)) ) {
			$emotional = "3";
		} elseif( ($Neuro_per >= ($Neuro_M + $Neuro_SD)) AND ($Neuro_per < ($Neuro_M + (2 * $Neuro_SD))) ) {
			$emotional = "4";
		} elseif( ($Neuro_per >= ($Neuro_M + (2 * $Neuro_SD) ))) {
			$emotional = "5";
		}

		if( ($Offen_per <= ($Offen_M - (2 * $Offen_SD) ))) {
			$openess = "1";
		} elseif( ( $Offen_per > ($Offen_M - (2 * $Offen_SD))) AND ($Offen_per <= ($Offen_M - $Offen_SD)) ) {
			$openess = "2";
		} elseif( ( $Offen_per > ($Offen_M - $Offen_SD))  AND ($Offen_per < ($Offen_M + $Offen_SD) )) {
			$openess = "3";
		} elseif( ( $Offen_per >= ($Offen_M + $Offen_SD))  AND ($Offen_per < ($Offen_M + (2 * $Offen_SD) ))) {
			$openess = "4";
		} elseif( ( $Offen_per >= ($Offen_M + (2 * $Offen_SD) ))) {
			$openess = "5";
		}
	}
	
	if((boolean)$attachment) {
		header("Content-disposition: attachment; filename=personal_feedback_$vpncode-".date("Y-m-d_H:i:s_T").".png");
	}

	header("Content-type: image/png");

	if( $partner == "true" ) {
		$im = imagecreatefrompng("images/feedback_pink.png");
	} else {
		$im = imagecreatefrompng("images/feedback_blue.png");
	}

	$color = imagecolorallocate($im,0,0,255);

	// imageantialias($im,true);

	if( $is_complete ) {
		imageline(
			$im,
			$coordinates["Extra_per"][$extraversion]["x"],
			$coordinates["Extra_per"][$extraversion]["y"],
			$coordinates["Vertr_per"][$compatibility]["x"],
			$coordinates["Vertr_per"][$compatibility]["y"],
			$color);

		imageline(
			$im,
			$coordinates["Vertr_per"][$compatibility]["x"],
			$coordinates["Vertr_per"][$compatibility]["y"],
			$coordinates["Gewiss_per"][$diligence]["x"],
			$coordinates["Gewiss_per"][$diligence]["y"],
			$color);

		imageline(
			$im,
			$coordinates["Gewiss_per"][$diligence]["x"],
			$coordinates["Gewiss_per"][$diligence]["y"],
			$coordinates["Neuro_per"][$emotional]["x"],
			$coordinates["Neuro_per"][$emotional]["y"],
			$color);

		imageline(
			$im,
			$coordinates["Neuro_per"][$emotional]["x"],
			$coordinates["Neuro_per"][$emotional]["y"],
			$coordinates["Offen_per"][$openess]["x"],
			$coordinates["Offen_per"][$openess]["y"],
			$color);
	} else {
		$black = imagecolorallocate($im,0,0,0);
		putenv('GDFONTPATH=' . realpath('..'));
		imagefttext($im,20,0,210,180,$black,"fonts/DroidSans.ttf","Keine Daten vorhanden");
	}

	imagepng($im);
	imagedestroy($im);
}

function study_feedback($vpncode, $vexed, $coordinates, $data,$attachment) {
	$study_data = get_study_data($data);

	$positives_array = array();
	for($i=0; $i < $study_data['iterations']; $i++) {
		$positive_query = "SELECT IF(PANAS01_en_stu1 IS NOT NULL, PANAS01_en_stu1, IF(PANAS01_en_stu2 IS NOT NULL, PANAS01_en_stu2, 0)) as PANAS01, IF(PANAS02_stu1 IS NOT NULL, PANAS02_stu1, IF(PANAS02_stu2 IS NOT NULL, PANAS02_stu2, 0)) as PANAS02, IF(PANAS03_en_stu1 IS NOT NULL, PANAS03_en_stu1, IF(PANAS03_en_stu2 IS NOT NULL, PANAS03_en_stu2, 0)) as PANAS03, IF(PANAS04_stu1 IS NOT NULL, PANAS04_stu1, IF(PANAS04_stu2 IS NOT NULL,PANAS04_stu2,0)) as PANAS04, IF(PANAS05_stu1 IS NOT NULL, PANAS05_stu1, IF(PANAS05_stu2 IS NOT NULL, PANAS05_stu2, 0)) as PANAS05, IF(PANAS06_stu1 IS NOT NULL, PANAS06_stu1, IF(PANAS06_stu2 IS NOT NULL, PANAS06_stu2, 0)) as PANAS06,  IF(PANAS21_z_en_stu1 IS NOT NULL, PANAS21_z_en_stu1, IF(PANAS21_z_en_stu2 IS NOT NULL, PANAS21_z_en_stu2, 0)) as PANAS21 FROM ".RESULTSTABLE." WHERE study='".$data."' AND vpncode='".$vpncode."' AND timestarted != 0 AND timefinished != 0 AND iteration=".($i + 1);
		$query = mysql_query($positive_query) or die( error_log(mysql_error()));
		$row = mysql_fetch_assoc($query);
		array_push($positives_array,$row);
	}

	$negatives_array = array();
	for($i=0; $i < $study_data['iterations']; $i++) {
		$negatives_query = "SELECT IF(PANAS07_stu1 IS NOT NULL, PANAS07_stu1, IF(PANAS07_stu2 IS NOT NULL, PANAS07_stu2, 0)) as PANAS07, IF(PANAS08_en_stu1 IS NOT NULL, PANAS08_en_stu1, IF(PANAS08_en_stu2 IS NOT NULL, PANAS08_en_stu2, 0)) as PANAS08, IF(PANAS09_stu1 IS NOT NULL, PANAS09_stu1, IF(PANAS09_stu2 IS NOT NULL, PANAS09_stu2, 0)) as PANAS09, IF(PANAS10_stu1 IS NOT NULL, PANAS10_stu1, IF(PANAS10_stu2 IS NOT NULL, PANAS10_stu2, 0)) as PANAS10, IF(PANAS11_stu1 IS NOT NULL, PANAS11_stu1, IF(PANAS11_stu2 IS NOT NULL, PANAS11_stu2, 0)) as PANAS11, IF(PANAS12_stu1 IS NOT NULL, PANAS12_stu1, IF(PANAS12_stu2 IS NOT NULL, PANAS12_stu2, 0)) as PANAS12, IF(PANAS13_stu1 IS NOT NULL, PANAS13_stu1, IF(PANAS13_stu2 IS NOT NULL, PANAS13_stu2, 0)) as PANAS13 FROM ".RESULTSTABLE." WHERE study='".$data."' AND vpncode='".$vpncode."' AND timestarted != 0 AND timefinished != 0 AND iteration=".($i + 1);
		$query = mysql_query($negatives_query) or die( error_log(mysql_error()));
		$row = mysql_fetch_assoc($query);
		array_push($negatives_array,$row);
	}

	$positive_values = array();
	$negative_values = array();

	foreach($positives_array as $row) {
		// [(PANAS01_en_stu1+PANAS02_stu1+PANAS03_en_stu1+PANAS04_stu1+PANAS05_stu1+ PANAS06_stu1+PANAS21_z_en_stu1)/7]
		$val = ($row['PANAS01']+$row['PANAS02']+$row['PANAS03']+$row['PANAS04']+$row['PANAS05']+$row['PANAS06']+$row['PANAS21']) / 7;
		array_push($positive_values, $val);
	}

	foreach($negatives_array as $row) {
		$val = ($row[ 'PANAS07' ] + $row[ 'PANAS08' ]+$row[ 'PANAS09' ]+$row[ 'PANAS10' ]+$row[ 'PANAS11' ] +$row[ 'PANAS12' ]+$row[ 'PANAS13' ]) / 7;
		array_push($negative_values, $val);
	}

	// test data
	// $positive_values = array(0.5,3.6,3.2,5.7,2.1,1.2,0.9,4.7,5.1,3.1,0.5,3.6,3.2,5.7,2.1,1.2,0.9,4.7,5.1,3.1);
	// $negative_values = array(0.5,5.1,1.2,0.9,4.7,5.1,3.1,0.5,1.2,0.9,4.7,5.7,2.1,3.6,3.2,3.6,3.2,5.7,2.1,3.1);

	$height = 282;

	
	if((boolean)$attachment) {
		header("Content-disposition: attachment; filename=personal_feedback_$vpncode-".date("Y-m-d_H:i:s_T").".png");
	}

	header("Content-type: image/png");


	$im = imagecreatefrompng("images/feedback_emo.png");

	// imageantialias($im,true);

	$pos_color = imagecolorallocate($im,0,43,255);
	$neg_color = imagecolorallocate($im,242,42,255);

	for( $i=0; $i < (sizeof($positive_values) - 1); $i++) {
		imageline(
			$im,
			$coordinates["positive"][$i]["x"], // always stays the same
			$coordinates["positive"][$i]["y"] - floor(($height * ($positive_values[$i] / 6))),
			$coordinates["positive"][$i + 1]["x"],
			$coordinates["positive"][$i + 1]["y"] - floor(($height * ($positive_values[$i + 1] / 6))),
			$pos_color);
		imagefilledrectangle(
			$im,
			$coordinates["positive"][$i]["x"] - 4, // always stays the same
			($coordinates["positive"][$i]["y"] - floor(($height * ($positive_values[$i] / 6)))) - 4,
			$coordinates["positive"][$i]["x"] + 4, // always stays the same
			($coordinates["positive"][$i]["y"] - floor(($height * ($positive_values[$i] / 6)))) + 4,
			$pos_color);

		//draw the last square
		if( !(($i + 1) < (sizeof($positive_values) - 1)) ) {
		imagefilledrectangle(
			$im,
			$coordinates["positive"][$i + 1]["x"] - 4, // always stays the same
			($coordinates["positive"][$i + 1]["y"] - floor(($height * ($positive_values[$i + 1] / 6)))) - 4,
			$coordinates["positive"][$i + 1]["x"] + 4, // always stays the same
			($coordinates["positive"][$i + 1]["y"] - floor(($height * ($positive_values[$i + 1] / 6)))) + 4,
			$pos_color);
		}
	}

	for( $i=0; $i < (sizeof($negative_values) - 1); $i++) {
		imageline(
			$im,
			$coordinates["negative"][$i]["x"], // always stays the same
			$coordinates["negative"][$i]["y"] - floor(($height * ($negative_values[$i] / 6))),
			$coordinates["negative"][$i + 1]["x"],
			$coordinates["negative"][$i + 1]["y"] - floor(($height * ($negative_values[$i + 1] / 6))),
			$neg_color);
		imagefilledrectangle(
			$im,
			$coordinates["negative"][$i]["x"] - 4, // always stays the same
			($coordinates["negative"][$i]["y"] - floor(($height * ($negative_values[$i] / 6)))) - 4,
			$coordinates["negative"][$i]["x"] + 4, // always stays the same
			($coordinates["negative"][$i]["y"] - floor(($height * ($negative_values[$i] / 6)))) + 4,
			$neg_color);
		//draw the last square
		if( !(($i + 1) < (sizeof($positive_values) - 1)) ) {
		imagefilledrectangle(
			$im,
			$coordinates["negative"][$i + 1]["x"] - 4, // always stays the same
			($coordinates["negative"][$i + 1]["y"] - floor(($height * ($negative_values[$i + 1] / 6)))) - 4,
			$coordinates["negative"][$i + 1]["x"] + 4, // always stays the same
			($coordinates["negative"][$i + 1]["y"] - floor(($height * ($negative_values[$i + 1] / 6)))) + 4,
			$neg_color);
		}
	}

	imagepng($im);
	imagedestroy($im);
}

function render_pretest_feedback($vpncode) {
	echo "<div class='feedback'>";
	echo "<h2>Ihr persönliches Feedback</h2>";
	echo "<div class='feedback-text'>";
	echo "<p>In unsere Einstiegs-Befragung haben Sie uns neben Fragen, die Ihre Partnerschaft betreffen, auch ausführlich über Ihre eigene Person berichtet. Das zur Zeit wichtigste Modell um Persönlichkeitsunterschiede zu beschreiben und zu kategorisieren ist das sogenannte „Big Five“-Modell. Dieses unterscheidet zwischen den Dimensionen <strong>Extraversion, Verträglichkeit, Gewissenhaftigkeit, Emotionale Ansprechbarkeit und Offenheit für Erfahrungen.</strong></p>";
	echo "<p>Als kleines Dankeschön für die Teilnahme an unserer Einstiegs-Befragung erfahren Sie nun in ihrem individuellen Feedback, was sich hinter diesen Dimensionen verbirgt und melden Ihnen grafisch zurück, wo Sie persönlich auf diesen Dimensionen verglichen mit anderen Personen stehen*. In einer späteren Phase der Studie haben Sie später die Gelegenheit, Ihr Persönlichkeits-Profil mit dem Ihres Partners zu vergleichen.</p>";
	echo "</div>";
	echo "<h3>Die 5 Hauptfaktoren der Persönlichkeit</h3>";
	echo "<h3>Extraversion</h3>";
	echo "<div class='feedback-text'><p>Extravertierte Personen sind eher gesellig, aktiv, gesprächig, personenorientiert, optimistisch, heiter, lieben Aufregung, gehen aus sich heraus. Wenig extravertierte (d.h. introvertierte) Personen sind dagegen eher zurückhaltend, können gut allein sein, reserviert, bleiben im Hintergrund, meiden Aufregung und große Gruppen.</p></div>";
	echo "<h3>Verträglichkeit</h3>";
	echo "<div class='feedback-text'><p>Verträgliche Personen sind eher umgänglich, altruistisch, verständnisvoll, wohlwollend, einfühlsam, hilfsbereit, harmoniebedürftig, kooperativ, nachgiebig, passiv, mitfühlend und gutmütig. Unverträgliche Personen sind eher wetteifernd, rivalisierend, widerspenstig, kritisch, misstrauisch, aggressiv, skeptisch und unsentimental.</p></div>";
	echo "<h3>Gewissenhaftigkeit</h3>";
	echo "<div class='feedback-text'><p>Gewissenhafte Personen sind eher diszipliniert, zuverlässig, pünktlich, ordentlich, pedantisch, penibel, zielstrebig, und anspruchsvoll. Wenig gewissenhafte Personen sind eher unbeschwert, nachlässig, locker, gleichgültig, unzuverlässig, unbeständig, unsystematisch und handeln ungeplant.</p></div>";
	echo "<h3>Emotionale Ansprechbarkeit</h3>";
	echo "<div class='feedback-text'><p>Emotional ansprechbare Personen sind leicht beunruhigt, emotional sensibel, eher nervös, neigen zu Ängsten und Traurigkeit, fühlen sich häufiger unsicher oder verlegen und sind um ihre Gesundheit besorgt. Personen, die emotional weniger ansprechbar sind, sind eher belastbar, entspannt, ruhig, unempfindlich, sorgenfrei, meist ausgeglichen, durch nichts aus der Ruhe zu bringen und haben wenige subjektive körperliche Beschwerden.</p></div>";
	echo "<h3>Offenheit für Erfahrungen</h3>";
	echo "<div class='feedback-text'><p>Offene Personen sind eher wortgewandt, phantasievoll, aufgeschlossen für neue Ideen, politisch eher liberal, kreativ, experimentierfreudig, vielfältig interessiert, intellektuell, und kultiviert. Personen mit einer geringen Ausprägung auf dieser Dimension lieben Fakten, bleiben beim Bekannten und Altbewährten, sind eher bodenständig und konventionell, politisch eher konservativ, traditionsbewusst, sachlich, realistisch, und eher festgelegt in der Art, wie Sie etwas unter­nehmen.</p></div>";
	echo "<br/>";
	echo "<h3>Ihr Profil</h3>";
	echo "<div class='feedback-text'><p>Ihr persönliches Profil zeigt Ihnen, wo Sie selbst verglichen mit anderen Personen auf den 5 Persönlichkeitsdimensionen stehen. Der durchschnittliche Bereich umfasst alle Werte, welche von den mittleren 68,2% aller Personen in der Vergleichsgruppe erzielt werden. Je weiter außerhalb ein Wert von diesem Durchschnittsbereich liegt, desto seltener wird er erzielt.</p></div>";
	echo "<img class='pretest-feedback' src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=pretest&partner=false\"/>";
	echo "<div class='legend'>* Die Vergleichsstichprobe umfasst 391 Personen im jungen Erwachsenenalter (zwischen 17 und 31 Jahren). </div>";
	echo "<a class='pretest-feedback' href=\"includes/single_person_feedback.php?vpncode=$vpncode&data=pretest&partner=false&attachment=true\">Profil speichern</a>";
	echo "</div>";
}

function render_study_feedback($vpncode, $vexed) {
	if( $vexed ){
		$v = 1;
	} else {
		$v = 0;
	}
	echo "<div class='feedback'>";
	echo "<h2>Ihr persönliches Stimmungs‐Feedback</h2>";
	echo "<div class='feedback-text'>";
	echo "<p>In unserer 20tägigen Tagebuch‐Befragung haben Sie neben Fragen, die die alltäglichen Ereignisse in Ihrer Partnerschaft betreffen, auch jeden Tag über Ihre Stimmung berichtet.</p>";
	echo "<p>Menschen unterscheiden sich erheblich untereinander, was das allgemeine Niveau ihrer Stimmung angeht und während sich manche Personen die meiste Zeit auf einem ähnlichen Stimmungsniveau bewegen, gibt es bei anderen ein größeres Auf und Ab.</p>";
	echo "<p>Empirisch hat sich zeigen lassen, dass das emotionale Wohlbefinden sich auf den Dimensionen <strong>Positive Stimmung</strong> und <strong>Negative Stimmung</strong> beschreiben lässt: Es ist nicht zwangsläufig so, dass wir z.B. wenn wir negative Gefühle haben keine positiven Gefühle erleben oder positive Gefühle zwangsläufig auch heißen, dass wir keinerlei negative Gefühle empfinden. Vielmehr gibt es Tage, wo wir beide Arten von Gefühlen gleichzeitig erleben können und andere Zeiten, wo Anwesenheit positiver Stimmung auch Abwesenheit negativer Stimmung bedeutet.</p>";
	echo "<p>Als Dankeschön für die Teilnahme an unserer Tagebuch‐Befragung möchten wir Ihnen auf Basis Ihrer täglich gemachten Angaben rückmelden, wie es Ihnen persönlich in den vergangenen 20 Tagen ergangen ist, also in welchem Ausmaße Sie positive und negative Stimmungen erlebt haben.</p>";
	echo "</div>";
	echo "<br/>";
	echo "<h3>Wie ging es Ihnen? ‐ Ihr Stimmung‐Profil für die letzten 20 Tage</h3>";
	echo "<div class='feedback-text'><p>Ihr persönliches Stimmungs‐Profil zeigt Ihnen, in welchem Ausmaß Sie in den letzten 20 Tagen positive und negative Gefühle erlebt haben.</p></div>";
	echo "<img class='study-feedback' src=\"includes/single_person_feedback.php?vpncode=$vpncode&vexed=$v&data=study&partner=false\"/>";
	echo "<a class='study-feedback' href=\"includes/single_person_feedback.php?vpncode=$vpncode&vexed=$v&data=study&partner=false&attachment=true\">Profil speichern</a>";
	echo "</div>";
}

function render_posttest_feedback($vpncode,$timestarted,$partner) {
	$vpndata = get_vpn_data($vpncode);

	if($partner) {
		$fake_row["skipif"] = '{ "global": { "mode": "any_true", "1": { "skipif": "((Tage_nBeh_MC_Sw = 1) AND (Schw_nBeh_Sw = 7))", "mode": "any_true"}, "2": { "skipif": "((Tra01S_stu1 = 7) OR (Tra02S_stu1 = 7) OR (Tra03S_stu1 = 7) OR (Tra04S_stu1 = 7) OR (Tra05S_stu1 = 7) OR (Tra06S_stu1 = 7) OR (Tra07S_stu1 = 7) OR (Tra08S_stu1 = 7) OR (Tra09S_stu1 = 7) OR (Tra10S_stu1 = 7) OR (Tra11S_stu1 = 7) OR (Tra12S_stu1 = 7) OR (Tra13S_stu1 = 7) OR (Tra14S_stu1 = 7) OR (Tra15S_stu1 = 7) OR (Tra16S_stu1 = 7) OR (Tra17S_stu1 = 7) OR (Tra18S_stu1 = 7) OR (Tra19S_stu1 = 7) OR (Tra20S_stu1 = 7) OR (Tra21S_stu1 = 7) OR (Tra22S_stu1 = 7) OR (Tra23S_stu1 = 7) OR (Tra24S_stu1 = 7) OR (Tra25S_z_stu1 = 7) OR (Tra26S_z_stu1 = 7) OR (Tra27S_z_stu1 = 7) OR (Tra28S_z_stu1 = 7) OR (TraFreiS_stu1 = 7))", "mode": "any_true" } } } ';
		$partner_vpn = $vpndata->partnercode;
		$partner_data = get_vpn_data($partner_vpn);
		$partner_study = $partner_data->study;
		$partner_vexed = check_vpn_results($partner_vpn,$fake_row, $timestarted);
		$vexed = check_vpn_results($vpncode, $fake_row, $timestarted);
	}

	echo "<div class='feedback'>";
	echo "<h2>Ihr Partner-Persönlichkeits-Feedback</h2>";
	echo "<div class='feedback-text'>";
	echo "<p>In unserer Einstiegs-Befragung haben Sie beide uns eine Reihe von Fragen beantwortet, die sich auf Ihre eigene Person beziehen und ein persönliches Feedback über die Ausprägung auf den Persönlichkeits-Dimensionen <strong>Extraversion, Verträglichkeit, Gewissenhaftigkeit, Emotionale Ansprechbarkeit</strong> und <strong>Offenheit für Erfahrungen</strong> erhalten.</p>";
	echo "<p>Als kleines Dankeschön dafür, dass Sie beide an dieser Befragung teilnehmen, haben Sie nun hier die Möglichkeit, Ihr Profil mit dem Ihres Partners zu vergleichen, sofern Sie beide bereits die entsprechenden Angaben gemacht haben.</p>";
	echo "<p>Als derjenige Partner, der sich zuerst zu unserer Befragung angemeldet hat, sind Sie Partner A. Als derjenige, der sich auf Einladung des Partners hin angemeldet hat, sind Sie Partner B. Sehen Sie selbst, wie ähnlich sich Ihre Persönlichkeiten sind!</p>";
	echo "<p>Falls für Ihren Partner noch keine Daten vorliegen, haben Sie die Möglichkeit, diese Rückmeldung später jederzeit wieder aufzurufen. Ihren Link zur Partner-Rückmeldung senden wir Ihnen in einer separaten Email zu.</p>";
	echo "</div>";
	echo "<br/>";
	echo "<h3>Wie ähnlich sind Sie sich? – Ihre Persönlichkeits-Profile im Vergleich</h3>";
	if( $vpn_type == 1) { /* first vpn */
		/* use the data from pretest */
		echo "<img class='posttest-feedback' src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=pretest&partner=false\"/>";
		echo "<a class='posttest-feedback' href=\"includes/single_person_feedback.php?vpncode=$vpncode&data=pretest&partner=false&attachment=true\">Profil speichern</a>";
	} else { /* this is a partner vpn */
		if( $partner && $partner_vexed ) {
			echo "Partner A";
			/* I ahve been invited by my partner, and something crazy happenend, so I use the data from posttest, as I am partner and have never completed pretest*/
			echo "<img class='posttest-feedback' src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=posttest&partner=false\"/>";
			echo "<a class='posttest-feedback' href=\"includes/single_person_feedback.php?vpncode=$vpncode&data=posttest&partner=false&attachment=true\">Profil speichern</a>";
		} else {
			echo "Partner A";
			/* I have been invited by my partner, and nothing happened, so I have completed the pretest and use that data*/
			echo "<img class='posttest-feedback' src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=pretest&partner=false\"/>";
			echo "<a class='posttest-feedback' href=\"includes/single_person_feedback.php?vpncode=$vpncode&data=pretest&partner=false&attachment=true\">Profil speichern</a>";
		}
	}
	if($partner == true) {
			echo "Partner B";
		if($vpn_type == 1) {
			if( $vexed ) {
				echo "<img class='posttest-feedback' src=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=posttest&partner=true\"/>";
				echo "<a class='posttest-feedback' href=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=posttest&partner=true&attachment=true\">Profil speichern</a>";
			} else {
				echo "<img class='posttest-feedback' src=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=pretest&partner=true\"/>";
				echo "<a class='posttest-feedback' href=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=pretest&partner=true&attachment=true\">Profil speichern</a>";
			}
		} else {
			echo "<img class='posttest-feedback' src=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=pretest&partner=true\"/>";
			echo "<a class='posttest-feedback' href=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=pretest&partner=true&attachment=true\">Profil speichern</a>";
		}
	}
}
?>
