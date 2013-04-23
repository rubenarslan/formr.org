<?php


function study_is_looped() {
    $query_string = "SELECT ".STUDIESTABLE.".loop FROM ".STUDIESTABLE." ;";
    $result=mysql_query($query_string) or die( exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in study_is_looped" ));
    $study_config = mysql_fetch_object($result);
    if( $study_config->loop == 0 ) {
		debug("<strong>study_is_looped:</strong> FALSE");
        return false;
    } elseif( $study_config->loop == 1 ) {
		debug("<strong>study_is_looped:</strong> TRUE");
        return true;
    } else {
        debug("<strong>study_is_looped:</strong> something went really wrong");
        exit;
    }
}

function get_iteration($vpncode) {
    $query_string = "SELECT MAX(iteration) as iteration FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' ";
    $result = mysql_query( $query_string ) or die( exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in get_iteration" ) );
    $count = mysql_fetch_object($result);
    if( !is_null($count->iteration) ) {
		debug("<strong>get_iteration:</strong> " . $count->iteration);
        return $count->iteration;
    } else {
		debug("<strong>get_iteration:</strong> " . $count->iteration);
        return 1;
    }
}

