<?
require ('header.php');
global $study;
global $run;

// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');

// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"


date_default_timezone_set('Europe/Berlin');

/* get the study the current user is in 
 * this will always be set to either the first (or only) study
 * the user will go through, in T.G. example 'pretest', or the
 * next study in line */
/* $study = get_study_by_vpn($vpncode); */
/* $study="diary"; */

/* the times the user is allowed to access the survey */
$times = get_edit_times();

/* current unix time with timezone offset */
$curtime = time();
if( TIMEDMODE AND !empty($times) ) {
    // check if we can edit now, otherwise show a message
	/* if( can_edit_now( $study, $curtime, $times, $vpncode)  ) { */
	if( can_edit_now( $curtime, $times, $vpncode)  ) {
		foreach( $times as $time ) {
			if( $time['starttime'] < $time['endtime'] ) { //everything is easy
				post_debug("can_edit_now, normal case");
		        $start = strtotime( $time['starttime'] . " seconds today");
				$end = strtotime( $time['endtime'] . " seconds today");
				/* $timestarted = get_timestarted($vpncode,$study,$start, $end); */
				$timestarted = get_timestarted($vpncode,$start, $end);
				if(( $curtime > $start AND $curtime < $end ) || ( $timestarted > $start AND $timestarted < $end )) {
		            // began in the middle of an edit time slot
					$starttime = $start;
					$endtime = $end;
		            break;
		        }

			} else {
				post_debug("can_edit_now, special case");
		        $start1 = strtotime( $time['starttime'] . " seconds today"); # could be the last part of the day
				$end1 = strtotime( $time['endtime'] . " seconds tomorrow");
				$start2 = strtotime( $time['starttime'] . " seconds yesterday");  # or the early part of the day
				$end2 = strtotime( $time['endtime'] . " seconds today");
				$timestarted1 = get_timestarted($vpncode,$start1, $end1);
				$timestarted2 = get_timestarted($vpncode,$start2, $end2);
				/* $timestarted1 = get_timestarted($vpncode,$study,$start1, $end1); */
				/* $timestarted2 = get_timestarted($vpncode,$study,$start2, $end2); */

				if( ( $curtime > $start1 AND $curtime < $end1 ) || 
				($timestarted1 > $start1 AND $timestarted1 < $end1 ) ) {
					$starttime = $start1;
					$endtime = $end1;
					$timestarted = $timestarted2;
					break;
				}
				elseif( ( $curtime > $start2 AND $curtime < $end2 ) || 
			 	($timestarted2 > $start2 AND $timestarted2 < $end2 ) ) {
					$starttime = $start2;
					$endtime = $end2;
					$timestarted = $timestarted1;
					break;
				}
			}
	    }

		/* a timestamp that will serve us to check whether the proband
		 * has had a go a this study already */
		if( isset($_POST['timestarted']) && has_entries_for_edit_time($vpncode,$starttime,$endtime) ) {
		/* if( isset($_POST['timestarted']) && has_entries_for_edit_time($vpncode,$study,$starttime,$endtime) ) { */
			post_debug("<strong>using timestarted from POST</strong>");
			// if the post value is set, we think the person is in a "session" with timestarted
			// but we need to double check this in order to prevent one session "spilling" into the other
			$timestarted = $_POST['timestarted'];
		} else {
			post_debug("<strong>using timestarted from get_timestarted()</strong>");
			// $time = date("Y.m.d - H.i.s");
			$timestarted = get_timestarted($vpncode,$starttime,$endtime);
			/* $timestarted = get_timestarted($vpncode,$study,$starttime,$endtime); */
		}


		/* don't want to see the old stuff go red*/
		remove_stale_itemsdisplayed($vpncode,$starttime);

        /* first, write everything that we get from post array before we proceed with the next questions */
		writepostedvars($vpncode,$starttime,$endtime,$timestarted);

        //todo! study is done, all items answered.
        $already_answered = get_already_answered($vpncode,$starttime,$endtime);
        $rows = get_next_items($vpncode, $timestarted, $already_answered);
        $all_inst=true;
        foreach($rows as $row) {
          if($row['typ']!="instruktion") {
            $all_inst=false;
            break;
          }
        }
        if($all_inst)
          studyDone();


/* writepostedvars($vpncode,$starttime,$endtime,$timestarted,$study); */
        /* render the form header and table opening */
        render_form_header($vpncode,$timestarted);

        /* now, print all items in the database for the current study to be answered by the proband */
        printitems($vpncode, $allowedtypes, $specialteststrigger, $starttime, $endtime,$timestarted);
        /* printitems($vpncode, $allowedtypes, $specialteststrigger, $starttime, $endtime, $study,$timestarted); */
        /* render the form footer and close table */
        render_form_footer();
	} else {
        /* sorry message: come back later when the editing is enabled again */
        echo "<div class='instruction'>Sie können das Tagebuch nur von 19:00 bis 04:00 ausfüllen. Bitte kommen Sie in diesem Zeitraum wieder. Zur Erinnerung kriegen Sie jeden Tag eine E-Mail, wenn Sie das Tagebuch ausfüllen können.</div>";
	}
} elseif( TIMEDMODE AND empty($times) ) {
    /* just for debugging the application */
	echo "timedmode = true aber keine edit-zeiten eingetragen. bitte richtiges setup machen!";
} else { 

  //todo! study is done, all items answered.
  $already_answered = get_already_answered($vpncode,$starttime,$endtime);
  $rows = get_next_items($vpncode, $timestarted, $already_answered);
  $all_inst=true;
  foreach($rows as $row) {
    if($row['typ']!="instruktion") {
      $all_inst=false;
      break;
    }
  }
  if($all_inst)
    studyDone();

    /* TIMEDMODE is FALSE */
    /* again, before we do anyting write whats left post array to database */
	writepostedvars($vpncode,$starttime,$endtime,$timestarted);
	/* writepostedvars($vpncode,$starttime,$endtime,$timestarted,$study); */
    /* render form header without $timestarted (hidden input won't be rendered) */
    render_form_header($vpncode,NULL);
    /* print all items from current study */	
	/* printitems($vpncode, $allowedtypes, $specialteststrigger, NULL, NULL, $study,$timestarted); */
	printitems($vpncode, $allowedtypes, $specialteststrigger, NULL, NULL, $timestarted);
    /* render form footer */
    render_form_footer();
}

/* close main div */
echo "</div>\n";
/* navgation */
// require ('includes/navigation.php');
/* close database connection, include ga if enabled */
require('includes/footer.php');
?>
