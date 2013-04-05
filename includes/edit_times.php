<?php

// SMS-Funktionen
function get_edit_times() {
    $query = "SELECT * FROM ".TIMESTABLE." ORDER  BY starttime ASC";
    $results = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/>in get_edit_times"));
    $arr = array();
    while($row = mysql_fetch_assoc($results)) {
        array_push($arr,$row);
    }
	post_debug("<strong>get_edit_times:</strong> Array size: " . sizeof($arr) . " items");
    return $arr;
}

function update_timestamps($vpncode,$timestarted) {
    $time = date("Y.m.d - H.i.s");
    $unixtime = time();

	post_debug("<strong>update_timestamps:</strong> $time");

    if(TIMEDMODE) {                 /* define the query with which the entry gets chosen appropriate for the edittime */
		$query = "SELECT endedsurveysmsintvar,timefinished FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND timestarted=".$timestarted.";";
        $check = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
    } else {
		$query = "SELECT endedsurveysmsintvar FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."';";
        $check = mysql_query() or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
    }

    $existententry = mysql_fetch_assoc($check);

    if(TIMEDMODE) {
        /* what is "existent" anyways??? ;) */
        if ($existententry["endedsurveysmsintvar"]==NULL AND $existententry["timefinished"]==0) {
            $query= "UPDATE ".RESULTSTABLE." SET timefinished=$unixtime, endedsurveysmsintvar='$time', updated_at=NOW() WHERE vpncode='".$vpncode."'  AND timestarted=".$timestarted.";";
            mysql_query($query) or die (exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
        }
    } else {
        if ($existententry["endedsurveysmsintvar"]==NULL) {
            $query= "UPDATE ".RESULTSTABLE." SET endedsurveysmsintvar='".$time."', updated_at=NOW() WHERE vpncode='".$vpncode."' ;";
            mysql_query($query) or die (exception_handler(mysql_error() . "<br/>" . $query . "<br/> in update_timestamps" ));
        }
    }

    mysql_free_result($check);
}


/* only tell me what I need to know;
 * function to check, wether entries exist for the given study and vpncode AND start- and endtime
 * only select id for performances' sake */
function has_entries_for_edit_time($vpncode,$starttime,$endtime) {
	if(TIMEDMODE)
	    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND timestarted BETWEEN $starttime AND $endtime";
	else
	    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='$vpncode'";
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


function can_edit_now($curtime,$times,$vpncode) { # MOOONSTER
    foreach( $times as $time ) {
		if( $time['starttime'] < $time['endtime'] ) { //everything is easy
			post_debug("can_edit_now, normal case");
	        $start = strtotime( $time['starttime'] . " seconds today");
			$end = strtotime( $time['endtime'] . " seconds today");
			$timestarted = get_timestarted($vpncode,$start, $end);
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
			$timestarted1 = get_timestarted($vpncode,$start1, $end1);
			$timestarted2 = get_timestarted($vpncode,$start2, $end2);
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

function get_entry_for_time($vpncode,$starttime,$endtime) {
	if(TIMEDMODE)
	    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='$vpncode' AND timestarted BETWEEN $starttime AND $endtime";
	else
	    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='$vpncode'";
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


function get_timestarted($vpncode,$starttime,$endtime) {
	if(!TIMEDMODE) return null;
	post_debug("<strong>get_timestarted:</strong> vpncode => " . $vpncode);
	post_debug("<strong>get_timestarted:</strong> starttime => " . $starttime);
	post_debug("<strong>get_timestarted:</strong> endtime => " . $endtime);

	$query = "SELECT timestarted FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' AND (timestarted BETWEEN ".$starttime." AND ".$endtime.") ORDER BY timestarted DESC LIMIT 1";
	
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