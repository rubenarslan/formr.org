<?php
/* php is ugly */
function render_form_header($vpncode,$timestarted) {
    /* form begins */	
  global $study;
  global $run;
  $action = "survey.php?study_id=".$study->id;
  if(isset($run))
    $action .= "&run_id=".$run->id;
  echo '<form action="'.$action.'" method="post" class="form-horizontal" accept-charset="utf-8">';

    /* pass on hidden values */
    echo '<input type="hidden" name="vpncode" value="' . $vpncode . '" />';
    if( !empty( $timestarted ) ) {
        echo '<input type="hidden" name="timestarted" value="' . $timestarted .'" />';
	} else {
		debug("<strong>render_form_header:</strong> timestarted was not set or empty");
	}
	
    echo '<div class="progress">
  <div class="bar" style="width: '.progress($vpncode).'%;"></div>
</div>';

}

/* php is frikkin ugly */
function render_form_footer() {
	$submit = new Item_submit('submit_button', array('text' => 'Weiter!'));
#	$id,$type,$name,$text,$reply_options,$displayed_before,$size)
	echo $submit->render();
    echo "</form>"; /* close form */	
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

    skipif_debug("item id: " . $row["id"]);
    skipif_debug("special field set, not skipping");
  } else {
    /* also test whether the skipif string contains whitespace and don't go through this if it does */
    if ($row["skipif"] != NULL && trim($row["skipif"]) != "") {

      skipif_debug("<div style='margin-top: 100px; border: 1px solid black; background-color: #fdd;'>item id: " . $row["id"] . "<br/>item variable: ".$row["variablenname"]."</div>");
      skipif_debug("skipif statement: <br/><div style='background-color:#d85;border: 1px solid black;'><code>".$row["skipif"]."</code></div>");

      $skipif_json = json_decode($row["skipif"],true);
      // global skipif tests
      if( empty($skipif_json["global"]) ) {
        skipif_debug("global part of skipif is empty");
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

        skipif_debug("global skipif query is: <br/><div style='background-color:#ddd;border:solid black 1px;'><code>". $mquery . "</code></div>");

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
				
        skipif_debug("<div style='padding-top:10px;'><strong>GLOBAL RESULTS after evaluation</strong></div>");
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
              skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
              break 2;
            } else {
              $global_skip = false;
              skipif_debug("global skipif " . $condition_name . "  is <strong>FALSE</strong>");
            }
            break 1;
          case "any_false":
            if($condition_value == false) {
              $global_skip = true;
              skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
              break 2;
            } else {
              skipif_debug("global skipif " . $condition_name . "  is <strong>FALSE</strong>");
            }
            break 1;
          case "all_true":
            if($condition_value == false) {
              $global_skip = false;
              skipif_debug("global skipif " . $condition_name . "  is <strong>FALSE</strong>");
              break 2;
            } else {
              $global_skip = true;
              skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
            }
            break 1;
          case "all_false":
            if($condition_value == true) {
              $global_skip = false;
              skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
              break 2;
            } else {
              $global_skip = true;
              skipif_debug("global skipif " . $condition_name . "  is <strong>TRUE</strong>");
            }
            break 1;
          }
        }
      }

      if($global_skip) {
        skipif_debug("<strong>FINAL</strong> global result with mode: <strong>" . $global["mode"] . "</strong> is: <strong>TRUE</strong>");
      } else {
		  if(isset($global))
	        skipif_debug("<strong>FINAL</strong> global result with mode: <strong>"  . $global["mode"] . "</strong> is: <strong>FALSE</strong>");
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

        skipif_debug("local skipif query:<br/><div style='border: 1px solid black;background-color: #aff;'><code>" . $m_local_query . "</code></div>");
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
            skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
            if($value == true) {
              $local_skip = true;
              skipif_debug("local skipif with mode: <strong>ANY_TRUE</strong> evaluates to <strong>TRUE</strong>");
              break 2;
            } else {
              $local_skip = false;
            }
            skipif_debug("local skipif with mode: <strong>ANY_TRUE</strong> evaluates to <strong>FALSE</strong>");
            break;
          case "any_false":
            skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
            if($value == false) {
              skipif_debug("local skipif with mode: <strong>ANY_FALSE</strong> evaluates to <strong>TRUE</strong>");
              $local_skip = true;
              break 2;
            } else {
              $local_skip = false;
            }
            skipif_debug("local skipif with mode: <strong>ANY_FALSE</strong> evaluates to <strong>FALSE</strong>");
            break;
          case "all_true":
            skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
            if($value == false) {
              skipif_debug("local skipif with mode: <strong>ALL_TRUE</strong> evaluates to <strong>FALSE</strong>");
              $local_skip = false;
              break 2;
            } else {
              $local_skip = true;
            }
            skipif_debug("local skipif with mode: <strong>ALL_TRUE</strong> evaluates to <strong>TRUE</strong>");
            break;
          case "all_false":
            skipif_debug("<strong>should_skip:</strong> mode is: " . $local["mode"]);
            if($value == true) {
              skipif_debug("local skipif with mode: <strong>ALL_FALSE</strong> evaluates to <strong>FALSE</strong>");
              $local_skip = false;
              break 2;
            } else {
              $local_skip = true;
            }
            skipif_debug("local skipif with mode: <strong>ALL_FALSE</strong> evaluates to <strong>TRUE</strong>");
            break;
          default:
            skipif_debug("no mode statement for local skipif found! please double check this as is considered a BUG");
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

          skipif_debug("condition contains <strong>AND</strong><br/>");

          if($local_skip && $global_skip){
            skipif_debug("TOTAL RESULT:<div class='skipif_true'>local && global = true</div>");
            return true;
          } else {
            skipif_debug("TOTAL RESULT:<div class='skipif_false'>local && global = false</div>");
            return false;
          }
        } else if( strtolower($skipif_json["mode"]) == "or") {

          skipif_debug("condition contains <strong>OR</strong><br/>");

          if($local_skip || $global_skip){
            skipif_debug("TOTAL RESULT:<div class='skipif_true'>local || global = true</div>");
            return true;
          } else {
            skipif_debug("TOTAL RESULT:<div class='skipif_false'>local || global = false</div>");
            return false;
          }
        } else {
          skipif_debug("condition contains nothing useful<br/>");

          if($local_skip || $global_skip){
            skipif_debug("TOTAL RESULT:<div class='skipif_true'>local || global = true</div>");
            return true;
          } else {
            skipif_debug("TOTAL RESULT:<div class='skipif_false'>local || global = false</div>");
            return false;
          }
        }
      } else {
        if($local_skip || $global_skip){
          skipif_debug("condition contains nothing<br/>");
          skipif_debug("TOTAL RESULT:<div class='skipif_true'>local || global = true</div>");
          return true;
        } else {
          skipif_debug("TOTAL RESULT:<div class='skipif_false' >local || global = false</div>");
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

	$check = "select displaycount from " . ITEMDISPLAYTABLE . " where variablenname='$variablenname' and vpncode='".$vpncode."';";
	$checkthis = mysql_query($check) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitem" ));
	$displayed_before = mysql_fetch_assoc($checkthis);
	$displayed_before = $displayed_before["displaycount"];
	if ($displayed_before!="" AND $displayed_before!=NULL AND $displayed_before>0) {
		debug("<strong>printitem:</strong> item was displayed before");
		$displayed_before = (int)$displayed_before;
	} else {
		$displayed_before = false;
	}

	if($typ == 'offen') $type = 'text';
	if($typ == 'instruktion') $type = 'instruction'; 
	else $type = strtolower($typ);
	
	$text = $wortlaut;
	$name = $variablenname;
	$reply_options = array();
	$size = 1;
	
	// INSTRUKTION
	switch($typ) {
		case "instruktion":
			if (
				!$displayed_before OR 
				!in_array($rows[$zeile+1]['typ'], array('instruktion','fork'))
			) {
				$item = new Item_instruction($name, array(
					'id' => $id,
					'text' => $text,
					'displayed_before' => $displayed_before,
					));
				echo $item->render();
			}
			break;
		case "rating":
			if(!isset($antwortformatanzahl)) $antwortformatanzahl = 2;
			$reply_options = array_fill(1, $antwortformatanzahl, '');
			if(isset($rows[$zeile]['ratinguntererpol']) ) {
				$lower = $rows[$zeile]['ratinguntererpol'];
				$upper = $rows[$zeile]['ratingobererpol'];
			} elseif(isset($rows[$zeile]['MCalt1']) ) {
				$lower = $rows[$zeile]['MCalt1'];
				$upper = $rows[$zeile]['MCalt2'];	
			} else {
				$reply_options = range(1, $antwortformatanzahl);
				$reply_options = array_combine($reply_options, $reply_options);
				$lower = 1;
				$upper = $antwortformatanzahl;
			}
			$reply_options[1] = $lower;
			$reply_options[$antwortformatanzahl] = $upper;
			
			$item = new Item_mc($name, array(
					'id' => $id,
					'text' => $text,
					'reply_options' => $reply_options,
					'displayed_before' => $displayed_before
					));
			echo $item->render();
		
			break;
		case "mc":
		case "mmc":
		case "select":
		case "mselect":
		case "range":
		case "btnradio":
		case "btncheckbox":
			$reply_options = array();
			if(!isset($antwortformatanzahl)) $antwortformatanzahl = 12;
			
			for($op = 1; $op <= $antwortformatanzahl; $op++) {
				if(isset($rows[$zeile]['MCalt'.$op]))
					$reply_options[ $op ] = $rows[$zeile]['MCalt'.$op];
			}
			$class = "Item_".$type;
			
			$item = new $class($name, array(
					'id' => $id,
					'text' => $text,
					'reply_options' => $reply_options,
					'displayed_before' => $displayed_before
					));
			echo $item->render();
	
			break;
		case "offen":
			if($antwortformatanzahl / 150 < 1) {
				$class = 'Item';
				$size = $antwortformatanzahl;
			}
			else {
				$class = 'Item_textarea';
				$size = round( $antwortformatanzahl / 150 );
			}
			$item = new $class($name, array(
					'id' => $id,
					'text' => $text,
					'displayed_before' => $displayed_before,
					'size' => $size,
				));
			echo $item->render();

			break;
	
		default:
			$class = "Item_".strtoupper($type);
			if(!class_exists($class)) 
				$class = 'Item';
			$item = new $class($name, array(
					'id' => $id,
					'text' => $text,
					'type' => $type,
					'reply_options' => $reply_options,
					'displayed_before' => $displayed_before,
					'size' => $size
					));
			echo $item->render();

			break;
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
}


function printitems($vpncode,$allowedtypes,$specialteststrigger,$starttime,$endtime,$timestarted) {
    $already_answered = get_already_answered($vpncode,$starttime,$endtime);

    $rows = get_next_items($vpncode, $timestarted, $already_answered);
    
    // randomized blocks of questions?
    if(RANDOM) {

        $random_items = array();
        $final = array();
        $previous = false;

        foreach($rows as $row) {
            if( strtolower($row['rand']) != true) {
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
            debug("<strong>printitems:</strong> $itemsDisplayed of ". MAXNUMITEMS . " items per page displayed. " );
        }

        // merke dir, dass du das Item angezeigt hast.
        // Das machst du auch nicht für special-items, denn die werden ja vorher schon gefiltert!
        $query = "UPDATE " . ITEMDISPLAYTABLE . " SET displaycount=displaycount+1 WHERE vpncode='$vpncode' AND variablenname = '".$row["variablenname"]."';";
        $itemdisplay = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitems" ));
        if (mysql_affected_rows()==0) {
            $itemdisplay = mysql_query("insert into " . ITEMDISPLAYTABLE . " (variablenname,vpncode,displaycount,created_at) values ('".$row["variablenname"]."','$vpncode',1,".time().");");
        }

        debug("<strong>printitems:</strong> displaying items: " . $itemsDisplayed . " " . $row["variablenname"]);

        // do not continue displaying items if item is relevant
        if ($row["relevant"]==true) {
            break;
        }

        // when the maximum number of items to display is reached, stop
        if ($itemsDisplayed >= MAXNUMITEMS) {
            break;
        }
    }
}

function progress($vpncode) {
    $query = "SELECT variablenname
        FROM ".ITEMSTABLE."
        WHERE (skipif =  '' OR skipif IS NULL)
        AND typ !=  \"instruktion\"
        AND typ !=  \"fork\"
        AND (special =  '' OR special IS NULL)";
    $items=mysql_query($query) or die(mysql_error() );
    
	$used_vars = '';
	while($item = mysql_fetch_assoc($items)) {
		$used_vars .= '`'. $item['variablenname'] . '`, ';
	}
	$used_vars .= '`endedsurveysmsintvar`';
	
    // hole dir alle Werte dieser Person
    $query="SELECT $used_vars FROM ".RESULTSTABLE." WHERE vpncode='$vpncode'";
#	var_dump($query);
    $dieseperson = mysql_query($query) or die(mysql_error());
    $result = mysql_fetch_assoc($dieseperson);
    $already_answered = $not_answered = 0;
    foreach ($result as $value) {
        if ($value != NULL)
           $already_answered++;
        else
        	$not_answered++;
    }
	$all_items = $already_answered + $not_answered;

    $progress= $already_answered / $all_items ;
    return round($progress,2)*100;
}