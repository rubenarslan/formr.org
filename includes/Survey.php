<?php
require_once "DB.php";

class Survey {
	public $items = array();
	public $maximum_number_displayed = null;
	public $unanswered_batch = array();
	
	public function __construct($items) {
		
	}
	
	public function post($vpncode) {
		/*
		INSERT IGNORE
		prepare statement. validate each item, insert ignore if ok.
		*/
	}
	
	public function render() {
		$this->render_form_header($vpncode,$timestarted);
		$this->render_form_items($vpncode,$timestarted);		
		$this->render_form_footer();
	}
	protected function get_next_items() {
		$dbh = new DB();
	
		$this->unanswered_batch = array();
		
		$item_query = "SELECT 
				`".ITEMSTABLE."`.*,`".
				ITEMDISPLAYTABLE."`.displaycount 
					FROM 
			`".ITEMSTABLE."` LEFT JOIN `".ITEMDISPLAYTABLE."`
		ON `".ITEMSTABLE."`.id = `".ITEMDISPLAYTABLE."`.id
		WHERE `".ITEMDISPLAYTABLE."`.answered IS NULL";s
		if($this->maximum_number_displayed) $item_query .= " LIMIT {$this->maximum_number_displayed}";
			
		foreach($dbh->query() AS $item)
		{
			$this->unanswered_batch[] = $item;
		}
		return $this->unanswered_batch;
		
		// todo add answered bool field to itemdisplaytable
	}
	protected function render_form_header($study, $run, $vpncode,$timestarted) {
		$action = "survey.php?study_id=".$study->id;
		if(isset($run))
			$action .= "&run_id=".$run->id;

		echo '<form action="'.$action.'" method="post" class="form-horizontal" accept-charset="utf-8">';

	    /* pass on hidden values */
	    echo '<input type="hidden" name="vpncode" value="' . $vpncode . '" />';
	    if( !empty( $timestarted ) ) {
	        echo '<input type="hidden" name="timestarted" value="' . $timestarted .'" />';
		} else {
			post_debug("<strong>render_form_header:</strong> timestarted was not set or empty");
		}
	
	    echo '<div class="progress">
				  <div class="bar" style="width: '.progress($vpncode).'%;"></div>
			</div>';

	}

	protected function render_items($vpncode,$starttime,$endtime,$timestarted) 
	{
	    $rows = $this->get_next_items();
    
    
	    // loope jetzt bitte durch die Itemtabelle
	    $itemsDisplayed = 0;
	    for($i=0; $i < sizeof($rows); $i++) {
	        $row = $rows[$i];

	        // fork-items sind relevant, werden aber nur behandelt, wenn sie auch an erster Stelle sind, also alles vor ihnen schon behandelt wurde
	        if ($row["typ"]=="fork" AND $itemsDisplayed==0) 
			{
	            printitem($row["id"], $row["variablenname"], $row["typ"], $formulierung, $row["antwortformatanzahl"], $row["ratinguntererpol"], $row["ratingobererpol"], $i, $row["id"]+1, $rows, $timestarted, $vpncode);
	            break;
	        } elseif ($row["typ"]=="fork" AND $itemsDisplayed>0) 
			{
	            break;
	        }

	        // Gibt es Bedingungen, unter denen das Item alternativ formuliert wird?
	        $formulierung = $row["wortlaut"];
	        if ($row["altwortlautbasedon"]!="") 
			{
	            // und prüfe, ob sie zutrifft
	            // $altwortlaut = eval('if ($result[' . preg_replace('/\s/', '] ', $row["altwortlautbasedon"], 1) .') return $row[altwortlaut];');
	            eval('if ($result[' . preg_replace('/\s/', '] ', $row['altwortlautbasedon'], 1) .') $formulierung = $row[altwortlaut];');
	            // echo 'if ($result[' . preg_replace('/\s/', '] ', $row[altwortlautbasedon], 1) .') $formulierung = $row[altwortlaut];';
	            if ($altwortlaut != "") 
				{
	                // nimm die alternative formultierung
	                $formulierung = $altwortlaut;
	            }
	        }

	        // FIX: Logik-Hack: Einsetzen des Datums
	        // Sollte einmal als grundsätzliche Funktion bereitgestellt werden
	        // $formulierung = preg_replace("/LOGIKDATE2003/",date2003($vpncode),$formulierung);
	        $formulierung = substitute($vpncode,$formulierung);

	        // Schreibe das item hin
	        printitem($row["id"], $row["variablenname"], $row["typ"], $formulierung, $row["antwortformatanzahl"], $row["ratinguntererpol"], $row["ratingobererpol"], $i, $row["id"]+1, $rows, $timestarted, $vpncode);

	        if ($row["typ"]!="instruktion") 
			{
	            $itemsDisplayed++;
	            post_debug("<strong>printitems:</strong> $itemsDisplayed of ". MAXNUMITEMS . " items per page displayed. " );
	        }

	        // merke dir, dass du das Item angezeigt hast.
	        // Das machst du auch nicht für special-items, denn die werden ja vorher schon gefiltert!
	        $query = "UPDATE " . ITEMDISPLAYTABLE . " SET displaycount=displaycount+1 WHERE vpncode='$vpncode' AND variablenname = '".$row["variablenname"]."';";
	        $itemdisplay = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in printitems" ));
	        if (mysql_affected_rows()==0) 
			{
	            $itemdisplay = mysql_query("insert into " . ITEMDISPLAYTABLE . " (variablenname,vpncode,displaycount,created_at) values ('".$row["variablenname"]."','$vpncode',1,".time().");");
	        }

	        post_debug("<strong>printitems:</strong> displaying items: " . $itemsDisplayed . " " . $row["variablenname"]);

	        // do not continue displaying items if item is relevant
	        if ($row["relevant"]==true) 
			{
	            break;
	        }

	        // when the maximum number of items to display is reached, stop
	        if ($itemsDisplayed >= MAXNUMITEMS) 
			{
	            break;
	        }
	    }
	}

	protected function legacy_translate_item($item, $id, $variablenname, $typ, $wortlaut, $antwortformatanzahl, $ratinguntererpol, $ratingobererpol, $zeile, $next, $rows, $timestarted, $vpncode) {

		
		$id = $item['id'];
		$name = $item['variablenname'];
		$type = trim(strtolower($item['typ']));
		if($type == 'offen') $type = 'text';
		if($type == 'instruktion') $type = 'instruction'; 
	
		$text = $item['wortlaut'];
		$reply_options = array();
		$size = 1;
		$displayed_before = (int)$item['displayed_before'];
		
		$options = array('displayed_before'=>$display_before);
		
		if(isset($item['antwortformatanzahl']) AND strpos($type," ")!==false)
		{
			$type = preg_replace(" +"," ",$type);
			$type_options = explode(" ",$type);
			$type = $type_options[0];
			unset($type_options[0]);
		}
	
		// INSTRUKTION
		switch($type) {
			case "instruction":
				if (
					!$displayed_before OR 
					!in_array($rows[$zeile+1]['typ'], array('instruktion','fork'))
				) {
					$item = new Item_instruction($name, array(
						'id' => $id,
						'text' => $text,
						) + $options); # using the plus operand like this means that the later options will overwrite
				}
				break;
			case "rating":
				if(!isset($item['antwortformatanzahl'])) $item['antwortformatanzahl'] = 2;
				$reply_options = array_fill(1, $item['antwortformatanzahl'], '');
				if(isset($item['ratinguntererpol']) ) {
					$lower = $item['ratinguntererpol'];
					$upper = $item['ratingobererpol'];
				} elseif(isset($item['MCalt1']) ) {
					$lower = $item['MCalt1'];
					$upper = $item['MCalt2'];	
				} else {
					$reply_options = range(1, $item['antwortformatanzahl']);
					$reply_options = array_combine($reply_options, $reply_options);
					$lower = 1;
					$upper = $item['antwortformatanzahl'];
				}
				$reply_options[1] = $lower;
				$reply_options[$item['antwortformatanzahl']] = $upper;
			
				$item = new Item_mc($name, array(
						'id' => $id,
						'text' => $text,
						'reply_options' => $reply_options,
						'displayed_before' => $displayed_before
						));
		
				break;
			case "mc":
			case "mmc":
			case "select":
			case "mselect":
			case "range":
			case "btnradio":
			case "btncheckbox":
				$reply_options = array();
				if(!isset($item['antwortformatanzahl'])) $item['antwortformatanzahl'] = 12;
			
				for($op = 1; $op <= $item['antwortformatanzahl']; $op++) {
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
	
				break;
			case "text":
				if($item['antwortformatanzahl'] / 150 < 1) {
					$class = 'Item';
					$size = $item['antwortformatanzahl'];
				}
				else {
					$class = 'Item_textarea';
					$size = round( $item['antwortformatanzahl'] / 150 );
				}
				$item = new $class($name, array(
						'id' => $id,
						'text' => $text,
						'displayed_before' => $displayed_before,
						'size' => $size,
					));

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

				break;
		}


	    // FORK
	    if ($typ=="fork") { // fixme: forks should do PROPER redirects, but at the moment the primitive MVC separation makes this a problem
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
	protected function render_form_footer() {
		$submit = new Item_submit('submit_button', array('text' => 'Weiter!'));
	#	$id,$type,$name,$text,$reply_options,$displayed_before,$size)
		echo $submit->render();
	    echo "</form>"; /* close form */	
	}
	
}