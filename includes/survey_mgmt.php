<?php
function get_study_by_id($id) {    /* generic function to look up which study an item is associated with; $id is the unique integer id of the items; */
  $query = "SELECT * FROM ".STUDIESTABLE." WHERE id='".$id."';";
  $result = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_study_by_id" ));
  $obj = mysql_fetch_object($result);
  if( $obj->id == $id ) {
    debug("<strong>get_study_by_id:</strong> id: " . $id . " " . $obj->name);
    return $obj;
  }
}

// todo: in the future, we should only fetch the variables that will be displayed. they can all be initialised for either display or validation. nothing else needs to be available for either display or validation, riight?

function write_post_data($id) {
  $item_variables = get_all_item_variables();
	
#  var_dump($_POST);
  if( !empty( $id ) ) {
    if(mysql_num_rows($item_variables) > 0) {
      while ($row = mysql_fetch_assoc($item_variables)) {
        /* foreach($row as $r) */
        /*   echo "<p>".$r."</p>"; */
        /* echo $row['Field']; */
        /* echo "<p>-------</p>"; */
        // Loope durch alle Variablennamen
        // Wenn einer davon per POST übergeben wurde, und nicht leer ist (auch ausgelassene werden als "" gepostet!)
#		var_dump($_POST[$row['Field']]);
        if (isset($_POST[$row['Field']]) AND ($_POST[$row['Field']]!="")) { // fixme: '' should be allowed for optional fields 
          if( $row['Field'] != "timestarted" ) {
            /* schreibt alles außer timestarted */
            $variable = $row['Field'];

			if(is_array($_POST[$variable])) $value = implode(", ",$_POST[$variable]);
			else $value = $_POST[ $variable ];
			
	        $query= "UPDATE ".RESULTSTABLE." SET `$variable`='".mysql_real_escape_string($value)."', updated_at=NOW() WHERE id=".$id;

            mysql_query($query) or die (exception_handler(mysql_error() . "<br/>" . $query . "<br/> in write_post_data" ));
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

function writepostedvars($vpncode,$starttime = NULL,$endtime = NULL,$timestarted = NULL) {
    /* if the current user has no entires for this particular edit cycle 
     * or for this study at all (if timedmode is off the time flags are ignored) */
    if( !has_entries_for_study($vpncode) ) {
        // code I'd like to have
        $entry_id = create_new_entry($vpncode,$timestarted,false);
        // and create it if possible
        if( $entry_id != false ) {
            write_post_data($entry_id);
        }
    }
    /* oh, the vpncode *does* have entries for this study already */
    else {

        /* debug( "<strong>writepostedvars:</strong> " . $vpncode . " has an entry for study: " . $study); */
        /* if its a diary study check if the vpncode has entries for that time period */
        if( TIMEDMODE ) {
            /* timemode is on, meaning only at certain times site can be accessed and entries created
             * now if the current study is also a looped one we have to check whether entries exist for 
             * this particular edit cycle */
            /* if vpncode has no entries for that edit time period create a bare one */
            if( !has_entries_for_edit_time($vpncode,$starttime,$endtime) ) {
                debug("<strong>writepostedvars:</strong> in timedmode, vpncode has no entires for edit time");
                if( last_entry_complete($vpncode) ) {
                    debug("<strong>writepostedvars:</strong> last entry is complete");
                    // create a new entry, don't iterate the count
                    $entry_id = create_new_entry($vpncode,$timestarted,true);
                    if( $entry_id != false ) {
                        write_post_data($entry_id);
                    }
                } else {
                    debug("<strong>writepostedvars:</strong> last entry is not complete");
                    // create a new entry and interate the count
                    $entry_id = create_new_entry($vpncode,$timestarted,false);
                    if( $entry_id != false ) {
                        write_post_data($entry_id);
                    }
                }
            }
            /* vpncode has entries, try and complete what is left open with what is in $_POST */
            else {
                debug("<strong>writepostedvars</strong> in timedmode, vpncode has entires for edit time");
                $entry_id = get_entry_for_time($vpncode,$starttime,$endtime);
                if( $entry_id != false ) {
                    write_post_data($entry_id);
                }
            }
        }
        /* no diary mode, and vpncode does have entries for this study: put whats in $_POST into the database */
        else {
            $entry_id = get_entry_for_time($vpncode,$starttime,$endtime);
            if( $entry_id != false ) {
                write_post_data($entry_id);
            }
        }
    }
}

function get_study_by_vpn($vpncode) {
    $query = "SELECT study FROM ".VPNDATATABLE." WHERE vpncode='$vpncode';";
    $results = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_study_by_vpn" ));
    debug("<strong>get_study_by_vpn:</strong> $query");
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

function get_study_data() {
    $query = "SELECT * FROM ".STUDIESTABLE."";
    $results = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in get_study_data" ));
    return mysql_fetch_assoc($results);
}


function remove_stale_itemsdisplayed($vpncode, $starttime) {
	$delete_displayed = "DELETE FROM " . ITEMDISPLAYTABLE . " WHERE vpncode='$vpncode' AND created_at < $starttime";
	mysql_query($delete_displayed) or die( exception_handler(mysql_error() . "<br/>" . $delete_displayed . "<br/> in remove_stale_itemsdisplayed" ));
}


/* returns an array with all items that have already been answered for that study in this edit period (when TIMEDMODE == true) */
function get_already_answered($vpncode,$starttime = NULL,$endtime = NULL) {
    if(TIMEDMODE) {             /* if TIMEDMODE true */
		$query="SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode'  AND (timestarted BETWEEN $starttime AND $endtime);";
    } else {                    /* if TIMEDMODE false */
		$query="SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' ;";
    }

	debug($query);
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

function quote($s) {
    return "'".mysql_real_escape_string($s)."'";
}

/* returns an array with the next items to be displayed */
function get_next_items($vpncode, $timestarted, $already_answered) { 

    // now, get all the items from the table that have not yet been answered and check whether they should be displayed or not
    $all_items = "SELECT * FROM " . ITEMSTABLE . " WHERE typ='Instruktion'";
	if(!empty($already_answered)) $all_items .= " OR  (variablenname NOT IN (". implode(",", array_map('quote', $already_answered)) . "))";
	
	$all_items .= ';';

    $itemtable=mysql_query($all_items) or die(exception_handler(mysql_error() . "<br/>" . $all_items . "<br/> in get_next_items" ));

    $itemassocs = array();
    while( $item = mysql_fetch_assoc( $itemtable )) {
        array_push($itemassocs,$item);
    }
    /* echo sizeof($itemassocs); */
    /*     foreach($itemassocs as $entry) { */
    /*     	foreach($entry as $key => $value) { */
    /*     		echo $key." => ".$value."<br/>"; */
    /*     	} */
    /*     } */

    // array to keep track of items to be displayed
    $rows = array();
    // for each entry this person has in the database, check this particular value and decide whether to skip or not. biatch.
    /* $c=0; */
    foreach($itemassocs as $item) {
      /* echo "<p>".$c."</p>"; */
      /* $c=$c+1; */
      if (!should_skip($vpncode,$item,$timestarted)) { //todo: rewrite skipif
        array_push($rows, $item);
      }
    }


    return $rows;
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
        debug("ERROR: study order field is empty!");
    }
}

function skip_study($vpncode,$study,$results) {
    $studyskip = false;

    foreach( $results as $result ) {
		// debug("<strong>skip_study</strong> fixme :)");
		// timestarted set to 0 as we cannot use local skipifs here anyways
        if( should_skip($vpncode,$result,0) ) {
            $studyskip = true;
			debug("<strong>skip_study:</strong> is TRUE");
            break;
        }
    }
	debug("<strong>skip_study:</strong> is FALSE");
    return $studyskip;
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
			debug("<strong>entry_complete: </strong> in timedmode: TRUE ");
            return true;
        } else {
			debug("<strong>entry_complete: </strong> in timedmode: FALSE ");
            return false;
        }
    } else {
        if( $row['endedsurveysmsintvar'] != NULL) {
			debug("<strong>entry_complete:</strong> not in timedmode: TRUE ");
            return true;
        } else {
			debug("<strong>entry_complete:</strong> not in timedmode: FALSE");
            return false;
        }
    }
}

function last_entry_complete($vpncode) {
    $query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' ORDER BY created_at DESC LIMIT 1";
    $result = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in last_entry_complete" ));
    $obj = mysql_fetch_object($result);

    if( entry_complete( $obj->id ) ) {
		debug("<strong>last_entry_complete:</strong> TRUE");
        return true;
    } else {
		debug("<strong>last_entry_complete:</strong> FALSE");
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

			debug("<strong>create_new_entry:</strong> timestarted set and loop true");
            debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
        } else {
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,timestarted,created_at,updated_at) VALUES('".$vpncode."', NOW(),".$timestarted.", NOW(), NOW())";

			debug("<strong>create_new_entry:</strong> timestarted set and loop false");
            debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
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
			debug("<strong>create_new_entry:</strong> timestarted not set and loop true");
            debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
        } else {
            $query_string = "INSERT INTO ".RESULTSTABLE." (vpncode,begansurveysmsintvar,created_at,updated_at) VALUES('".$vpncode."', NOW(), NOW(), NOW())";
			debug("<strong>create_new_entry:</strong> timestarted not set and loop false");
            debug("<strong>create_new_entry:</strong> 'insert-query is': " . $query_string);
        }
    }

    /* insert the new entry into the db */
    $result = mysql_query($query_string) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in create_new_entry" ) );

    /*  query to get id of new entry */
    $get_id_query = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' ORDER BY created_at DESC LIMIT 1;";
    debug("<strong>create_new_entry:</strong> 'new entry query is': " . $get_id_query);
    $result = mysql_query($get_id_query) or die( exception_handler(mysql_error() . "<br/>" . $get_id_query . "<br/> in create_new_entry" ) );
    $entry = mysql_fetch_object($result);
    /* return new id or exit with error */
    if( !empty( $entry->id ) ) {
		debug("<strong>create_new_entry:</strong> id is " . $entry->id );
        return $entry->id;
    } else {
        debug("<strong>create_new_entry:</strong> ERROR: no ID returned by " . $get_id_query);
        exit();
    }
}


function check_vpn_results($vpncode,$statement, $timestarted){
	$query = "SELECT * FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."'";
	$results = mysql_query($query) or die( exception_handler( mysql_error() . "\n\n" . $query . "\n\nin check_vpn_results"));

	while( $entry = mysql_fetch_assoc($results)) {
		if( should_skip($vpncode,$statement,$timestarted) ) {
			debug("<strong>check_vpn_results:</strong> TRUE");
			return true;
		}
	}
	debug("<strong>check_vpn_results:</strong> FALSE");
	return false;
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
