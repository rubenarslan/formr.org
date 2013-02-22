<?php


/* php is ugly */
function render_form_header($vpncode,$timestarted) {
    /* form begins */	
  global $study;
  global $run;
  if(isset($run))
    echo "<form  action=survey.php?study_id=".$study->id."&run_id=".$run->id." method=post>";
  else
    echo "<form  action=survey.php?study_id=".$study->id." method=post>";
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


function printitems($vpncode,$allowedtypes,$specialteststrigger,$starttime,$endtime,$timestarted) {
    $already_answered = get_already_answered($vpncode,$starttime,$endtime);

    $rows = get_next_items($vpncode, $timestarted, $already_answered);
    
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
        //todo: next line breaks, temp fix in place
        /* $local_query_result = mysql_query($m_local_query) or die( exception_handler($m_local_query . " went wrong in skipif function")); */
        $local_query_result = mysql_query($m_local_query);
        if(!$local_query_result)
          return true;

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
		echo '</p><p><input type="button" value="Bild anzeigen" onclick="bildanzeigen();" id="bildzeigebutton"/><br><br>';

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
	document.getElementsByTagName("img")[1].style.display = 'none'; // hide directly
	
}
function bildanzeigen() {
	document.getElementById("bildzeigebutton").style.display = 'none';
	document.getElementsByTagName("img")[1].style.display = 'inline'; // show when button is clicked
	setTimeout(function() {
		document.getElementsByTagName("img")[1].style.display = 'none'; // hide after 10s
	},10*1000);
	
	setTimeout(function() {
		document.getElementById("weiterbutton").disabled = false; // activate button after 4
	},4*60*1000);
	setTimeout(function() { // and submit after 5
		document.getElementsByTagName("textarea")[0].readOnly = true;
		document.getElementsByTagName("textarea")[0].value = document.getElementsByTagName("textarea")[0].value + "**//autofin";
		document.forms[0].submit();
	},5*60*1000);
}
</script>
<?php
        echo "</p></div>\n";
    }


    // FORK
    if ($typ=="fork") {
		if(SUPPRESS_FORK == false) {
			define('ALTERNATESUBMIT', 'set');
                        global $study;
                        global $run;
			if(TIMEDMODE) {
                          if(isset($run))
                            $link=$ratinguntererpol."?study_id=".$study->id."&run_id=".$run->id."&ts=".$timestarted;
                          else
                            $link=$ratinguntererpol."?study_id=".$study->id."&ts=".$timestarted;
                            /* $link = $ratinguntererpol . "?vpncode=" . $vpncode . "&ts=" . $timestarted; */
			} else {
				/* $link = $ratinguntererpol . "?vpncode=" . $vpncode; */
                          if(isset($run))
                            $link=$ratinguntererpol."?study_id=".$study->id."&run_id=".$run->id;
                          else
                            $link=$ratinguntererpol."?study_id=".$study->id;
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