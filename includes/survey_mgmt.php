<?php


function write_post_data($id) {
  $item_variables = get_all_item_variables();
  if( !empty( $id ) ) {
    if(mysql_num_rows($item_variables) > 0) {
      while ($row = mysql_fetch_assoc($item_variables)) {
        /* foreach($row as $r) */
        /*   echo "<p>".$r."</p>"; */
        /* echo $row[Field]; */
        /* echo "<p>-------</p>"; */
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

function writepostedvars($vpncode,$starttime,$endtime,$timestarted) {
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

        /* post_debug( "<strong>writepostedvars:</strong> " . $vpncode . " has an entry for study: " . $study); */
        /* if its a diary study check if the vpncode has entries for that time period */
        if( TIMEDMODE ) {
            /* timemode is on, meaning only at certain times site can be accessed and entries created
             * now if the current study is also a looped one we have to check whether entries exist for 
             * this particular edit cycle */
            /* if vpncode has no entries for that edit time period create a bare one */
            if( !has_entries_for_edit_time($vpncode,$starttime,$endtime) ) {
                post_debug("<strong>writepostedvars:</strong> in timedmode, vpncode has no entires for edit time");
                if( last_entry_complete($vpncode) ) {
                    post_debug("<strong>writepostedvars:</strong> last entry is complete");
                    // create a new entry, don't iterate the count
                    $entry_id = create_new_entry($vpncode,$timestarted,true);
                    if( $entry_id != false ) {
                        write_post_data($entry_id);
                    }
                } else {
                    post_debug("<strong>writepostedvars:</strong> last entry is not complete");
                    // create a new entry and interate the count
                    $entry_id = create_new_entry($vpncode,$timestarted,false);
                    if( $entry_id != false ) {
                        write_post_data($entry_id);
                    }
                }
            }
            /* vpncode has entries, try and complete what is left open with what is in $_POST */
            else {
                post_debug("<strong>writepostedvars</strong> in timedmode, vpncode has entires for edit time");
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
function get_already_answered($vpncode,$starttime,$endtime) {
    if(TIMEDMODE) {             /* if TIMEDMODE true */
		$query="SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode'  AND (timestarted BETWEEN $starttime AND $endtime);";
    } else {                    /* if TIMEDMODE false */
		$query="SELECT * FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' ;";
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
function get_next_items($vpncode, $timestarted, $already_answered) { 

    // now, get all the items from the table that have not yet been answered and check whether they should be displayed or not
    $all_items = "SELECT * FROM " . ITEMSTABLE . " WHERE (typ='Instruktion' OR  (variablenname NOT IN (". implode(",", array_map('quote', $already_answered)) . ")));";

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
