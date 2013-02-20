<?

function renderSNEntries($vpncode,$stepcount) { // renders a table to display persons entered into social network
	
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode='".$vpncode."'";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);

	$oddeven = array("odd","even");

	// begin a table for all persons in the probands SN
	echo "";

	echo "<table width=\"800\">\n";
	// echo "<tr class=\"even\">\n\t <th>Gruppe</th>\n <th>Name</th>\n <th>Geschlecht</th>\n <th>Alter</th>\n <th>Beziehungsstatus</th>\n <th>Anzahl der Kinder</th>\n <th>Bekanntschaftsdauer</th>\n <th>Kontaktfrequenz</th>\n <th></th></tr>\n\n";
	// shortened this to:
	echo "<tr class=\"even\">\n\t <th>Gruppe</th>\n <th>Name</th>\n <th>Geschlecht</th>\n <th>Alter</th>\n <th>Anzahl der Kinder</th>\n <th></th></tr>\n\n";
	// to establish old state, uncomment corresponding lines in renderSNEntry

	for( $i=0; $i < $numitems; $i++ ) {
		$item = mysql_fetch_assoc($items);
		renderSNEntry($item,$oddeven[$i & 1],$stepcount);
	}

	echo "</table>";
	
}

function getGroup($gid) {
	$query = "SELECT * FROM ".ITEMSTABLE." WHERE variablenname=\"SN_Group\" AND special=\"snnetworkbuildup\"";
	$group = mysql_query($query);
	$numitems = mysql_numrows($group);
	
	$items = mysql_fetch_assoc($group);

	return $items["MCalt".$gid];
}



function renderSNEntry($item,$oddeven,$stepcount) {
	$mw = array("Männlich","Weiblich");

	// create a form for each entry

	echo "<tr class=\"".$oddeven."\">\n";
	echo "<td>".getGroup($item["SN_Group"])."</td>\n";
	echo "<td align=\"center\">".$item["SN_Name"]."</td>\n";
	echo "<td align=\"center\">".$mw[ $item["SN_Sex"] - 1 ]."</td>\n";
	echo "<td align=\"center\">".$item["SN_Age"]."</td>\n";
	// echo "<td>".$item["SN_Rel"]."</td>\n";
	echo "<td align=\"center\">".$item["SN_Child"]."</td>\n";
	// echo "<td>".$item["SN_Time"]."</td>\n";
	// echo "<td>".$item["SN_Freq"]."</td>\n";
	echo "<td align=\"right\">\n";
	echo "<form action=\"sn-edit.php\" method=\"post\" >\n";
	hiddeninput("vpncode",getvpncode());
	hiddeninput("id",$item["id"]);
	hiddeninput("stepcount",$stepcount);
	echo "<input type=\"submit\" value=\"Bearbeiten\" />\n";
	echo "</form></td>\n";
	echo "</tr>\n";
}

function addSNRelations($vpncode,$allowedtypes,$instruction_number,$special,$currentid) {
	// write the correct instruction
	$instrquery = "SELECT * FROM ".ITEMSTABLE." WHERE special='sninstruction' AND typ='instruktion'";
	$instructions = mysql_query($instrquery);
	$numinstr = mysql_numrows($instructions);

	echo "<table width=\"800\">\n";

	for($i=0; $i < $numinstr; $i++) {
		$instruction = mysql_fetch_assoc($instructions);
		
		$formulierung = $instruction["wortlaut"];
		if ($instruction["altwortlautbasedon"]!=NULL) {
			// und prüfe, ob sie zutrifft
			$formulierung = $instruction["wortlaut"];
			eval('if ($diesepersonarray[' . preg_replace('/\s/', '] ', $instruction["altwortlautbasedon"], 1) .') $formulierung = $instruction[altwortlaut];');
		}

		if( $instruction["variablenname"] == "Instr_SN_".$instruction_number) {			
			snPrintItem($instruction["id"], $instruction["variablenname"], $instruction["typ"], $formulierung, $instruction["antwortformatanzahl"], $instruction["ratinguntererpol"], $instruction["ratingobererpol"], $allowedtypes, $i, $instruction["id"]+1, $instructions,$currentid,$vpncode);
			if($special == "snrelations" OR $special == "snquestion") {
			echo "<tr><td id=\"instruktion\" colspan=\"2\"><strong>Name der Person:\t".getName($vpncode,$currentid)."</strong></td></tr>";
			}
		}
	}
	
	// write the SN form 
	$query = "SELECT * FROM ".ITEMSTABLE." WHERE special!='' AND typ!=''";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);
	
	for($i=0; $i < $numitems; $i++) {
		$item = mysql_fetch_assoc($items);
		
		$formulierung = $item["wortlaut"];
		if ($item["altwortlautbasedon"]!=NULL) {
			// und prüfe, ob sie zutrifft
			$formulierung = $item["wortlaut"];
			eval('if ($diesepersonarray[' . preg_replace('/\s/', '] ', $item["altwortlautbasedon"], 1) .') $formulierung = $item[altwortlaut];');
		}

		if( $item["special"] == $special ) {
			//only write questions to build up network, nothing else
			snPrintItem($item["id"], $item["variablenname"], $item["typ"], $formulierung, $item["antwortformatanzahl"], $item["ratinguntererpol"], $item["ratingobererpol"], $allowedtypes, $i, $item["id"]+1, $item, $currentid,$vpncode);
		}
	}

	echo "<tr class=\"bottomsubmit\">";
	echo "<td class=\"bottomsubmit\" colspan=\"2\">";

	// if its the first page/person thats being added, only show 1 button to add the data
	if( $instruction_number == 1 || $instruction_number == 2 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Nächste Person!\">&nbsp;&nbsp;&nbsp;<input type=\"submit\" name=\"snbuildupdone\" value=\"Dies ist meine letzte Person.\">";
	} elseif( $instruction_number == 0 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Nächste Person!\">&nbsp;&nbsp;&nbsp;";
	} elseif( $instruction_number == 3 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Weiter\">&nbsp;&nbsp;&nbsp;";
	} elseif( $instruction_number == 4 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Weiter\">&nbsp;&nbsp;&nbsp;";
	} elseif( $instruction_number == 5 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Weiter\">&nbsp;&nbsp;&nbsp;";
	} 
	
	echo "</td></tr>";

	echo "</table>";	
}

function writeName($vpncode,$currentid) {
	echo "<table width=\"800\">\n";
	echo "<tr><td id=\"instruktion\">Name der Person:\t".getName($vpncode,$id)."</td></tr>\n";
	echo "</table>";
}

function addSNPerson($vpncode,$allowedtypes,$instruction_number,$special,$currentid) { // returns a form to add a person
		
	// write the correct instruction
	$instrquery = "SELECT * FROM ".ITEMSTABLE." WHERE special='sninstruction' AND typ='instruktion'";
	$instructions = mysql_query($instrquery);
	$numinstr = mysql_numrows($instructions);

	echo "<table width=\"800\">\n";

	for($i=0; $i < $numinstr; $i++) {
		$instruction = mysql_fetch_assoc($instructions);
		
		$formulierung = $instruction["wortlaut"];
		if ($instruction["altwortlautbasedon"]!=NULL) {
			// und prüfe, ob sie zutrifft
			$formulierung = $instruction["wortlaut"];
			eval('if ($diesepersonarray[' . preg_replace('/\s/', '] ', $instruction["altwortlautbasedon"], 1) .') $formulierung = $instruction[altwortlaut];');
		}

		if( $instruction["variablenname"] == "Instr_SN_".$instruction_number) {			
			snPrintItem($instruction["id"], $instruction["variablenname"], $instruction["typ"], $formulierung, $instruction["antwortformatanzahl"], $instruction["ratinguntererpol"], $instruction["ratingobererpol"], $allowedtypes, $i, $instruction["id"]+1, $instructions,$currentid,$vpncode);
		}		
	}
	
	// write the SN form 
	$query = "SELECT * FROM ".ITEMSTABLE." WHERE special!='' AND typ!=''";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);
	
	for($i=0; $i < $numitems; $i++) {
		$item = mysql_fetch_assoc($items);
		
		$formulierung = $item["wortlaut"];
		if ($item["altwortlautbasedon"]!=NULL) {
			// und prüfe, ob sie zutrifft
			$formulierung = $item["wortlaut"];
			eval('if ($diesepersonarray[' . preg_replace('/\s/', '] ', $item["altwortlautbasedon"], 1) .') $formulierung = $item[altwortlaut];');
		}

		if( $item["special"] == $special ) {
			//only write questions to build up network, nothing else
			snPrintItem($item["id"], $item["variablenname"], $item["typ"], $formulierung, $item["antwortformatanzahl"], $item["ratinguntererpol"], $item["ratingobererpol"], $allowedtypes, $i, $item["id"]+1, $item,$currentid,$vpncode);
		}
	}
	
	echo "<tr class=\"bottomsubmit\">";
	echo "<td class=\"bottomsubmit\" colspan=\"2\">";

	// if its the first page/person thats being added, only show 1 button to add the data
	if( $instruction_number == 1 || $instruction_number == 2 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Nächste Person!\">&nbsp;&nbsp;&nbsp;<input type=\"submit\" name=\"snbuildupdone\" value=\"Dies ist meine letzte Person.\">";
	} elseif( $instruction_number == 0 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Nächste Person!\">&nbsp;&nbsp;&nbsp;";
	} elseif( $instruction_number > 2 ) {
		echo "<input type=\"submit\" name=\"nextperson\" value=\"Weiter\">&nbsp;&nbsp;&nbsp;";
	}

	echo "</td></tr>";
	echo "</table>";
}

function editSNPerson($vpncode,$id,$allowedtypes) { // returns a form to edit a persons entry
	
	// extract the name of the person associated with $id
	$idquery = "SELECT person FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\" AND id=\"".$id."\"";
	$iditem = mysql_query($idquery);
	$person = mysql_fetch_assoc($iditem);

	// printitemsforedits

	echo "<table width=\"800\">\n";

	// write the SN form 
	$query = "SELECT * FROM ".ITEMSTABLE." WHERE special!='' AND typ!=''";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);
	
	for($i=0; $i < $numitems; $i++) {
		$item = mysql_fetch_assoc($items);
		
		$formulierung = $item["wortlaut"];
		if ($item["altwortlautbasedon"]!=NULL) {
			// und prüfe, ob sie zutrifft
			$formulierung = $item["wortlaut"];
			eval('if ($diesepersonarray[' . preg_replace('/\s/', '] ', $item["altwortlautbasedon"], 1) .') $formulierung = $item[altwortlaut];');
		}

		/* modularize */
		if( $item["special"] == "snnetworkbuildup" ) {
			snPrintItemEdit($vpncode, $person["person"], $item["id"], $item["variablenname"], $item["typ"], $formulierung, $item["antwortformatanzahl"], $item["ratinguntererpol"], $item["ratingobererpol"], $allowedtypes, $i, $item["id"]+1);
		}
	}

	echo "<tr class=\"bottomsubmit\">";
	echo "<td class=\"bottomsubmit\" colspan=\"2\">";

	echo "<input type=\"submit\" name=\"nextperson\" value=\"Speichern\">&nbsp;&nbsp;&nbsp;";
	echo "</td></tr>";
	echo "</table>";
}


function checkAllPersons($vpncode,$allowedtypes,$stepcount,$instruction_number,$canadd) {
	// write the correct instruction
	$instrquery = "SELECT * FROM ".ITEMSTABLE." WHERE special!='' AND typ='instruktion'";
	$instructions = mysql_query($instrquery);
	$numinstr = mysql_numrows($instructions);

	echo "<table width=\"800\">\n";

	for($i=0; $i < $numinstr; $i++) {
		$instruction = mysql_fetch_assoc($instructions);
		
		$formulierung = $instruction["wortlaut"];
		if ($instruction["altwortlautbasedon"]!=NULL) {
			// und prüfe, ob sie zutrifft
			$formulierung = $instruction["wortlaut"];
			eval('if ($diesepersonarray[' . preg_replace('/\s/', '] ', $instruction["altwortlautbasedon"], 1) .') $formulierung = $instruction[altwortlaut];');
		}

		if( $instruction["variablenname"] == "Instr_SN_".$instruction_number) {
			printitem($instruction["id"], $instruction["variablenname"], $instruction["typ"], $formulierung, $instruction["antwortformatanzahl"], $instruction["ratinguntererpol"], $instruction["ratingobererpol"], $allowedtypes, $i, $instruction["id"]+1, $instruction);
		}
	}
	echo "</table>";

	renderSNEntries($vpncode,$stepcount);

	// if person has not reached max amount of probants show the 
	// add-more button
	if($canadd) {
		echo "<table width=\"800\">\n";
		echo "<tr id=\"instruktion\">";
		echo "<td id=\"instruktion\" align=\"center\" colspan=\"2\">";
		echo "<form action=\"social-network.php\" method=\"post\" >\n";
		hiddeninput("vpncode",getvpncode());
		hiddeninput("stepcount",$stepcount-1);
		hiddeninput("wavecount",$_POST["wavecount"]);
		echo "<input type=\"submit\" value=\"Weitere hinzufügen\" />\n";
		echo "</form></td>\n";
		echo "</tr>\n";
		echo "</table>";
	}


	echo "<table width=\"800\">\n";
	echo "<tr class=\"bottomsubmit\">";
	echo "<td class=\"bottomsubmit\" colspan=\"2\">";
	echo "<form action=\"sn-rel.php\" method=\"post\">";
	hiddeninput("vpncode",getvpncode());
	hiddeninput("stepcount",$stepcount);
	echo "<input type=\"submit\" name=\"nextpart\" value=\"Speichern\">";
	echo "</form>";
	echo "</td></tr>";
	echo "</table>";
}

function allComplete($vpncode,$special) {

	$nrquery = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$numresults = mysql_query($nrquery);
	$nresults = mysql_numrows($numresults);

	for($n=0; $n < $nresults; $n++) {
		if( !isComplete($vpncode,$special,$n) ) {
			return false;
			break;
		}
	}
	return true;
}

function getIncomplete($vpncode,$special) {
	$nrquery = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$numresults = mysql_query($nrquery);
	$nresults = mysql_numrows($numresults);

	for($n=0; $n < $nresults; $n++) {
		$item = mysql_fetch_assoc($numresults);
		if( !isComplete($vpncode,$special,$n) ) { 
			return $item["id"];
			break;
		}
	}
}

function canAddPerson($vpncode) {

	// check whether we may create a new user at all!
	// 35 MAX! 
	$numentries = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$numentries = mysql_query($numentries);
	if( mysql_numrows($numentries) >= SNMAXENTRIES ) {

		if(DEBUG) {
			echo "canAddPerson == false!\n";
			echo mysql_numrows($numentries);
			echo SNMAXENTRIES;
		}

		return false;
	} else {

		if(DEBUG) {
			echo "canAddPerson == true!\n";
		}

		return true;
	}
}

// function to check whether a data set is complete
function isComplete($vpncode,$special,$numentry) { // function to check the completeness of an entry in the db
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$items = mysql_query($query);
	$numrows = mysql_numrows($items);

	$setItems = "SELECT variablenname FROM ".ITEMSTABLE." WHERE special=\"".$special."\"";
	$setItems = mysql_query($setItems);
	$x = mysql_numrows($setItems);

	$setArray = array();

	for($i=0; $i < $x; $i++) { 
		$setItem = mysql_fetch_row($setItems);
		$setArray[$i] = $setItem[0];
	}
	
	if( $numrows == 0 ) { // if user has not entered anything at all yet and pressed 'next'..
		return false;
	} else {		
		// if there is at all data, check if the desired set is complete
		for($i=0; $i < $numrows; $i++) {
			$item = mysql_fetch_assoc($items);
			// check only the entry specified when the function is called
			if( $numentry == $i ) {				
				// for all items in the $setItems array do a check
				for($n=0; $n < sizeof($setArray); $n++) {
					//if an item is NULL or empty return false immediately and stop everything
					if( $item[ $setArray[$n] ] == "NULL" || $item[ $setArray[$n] ] == "" ) {
						return false;
						break;
					}
				}
			}
		}
		return true;
	}
}

function snUpdatePostedVars($vpncode,$currentid) {
	// Prüfe, ob es überhaupt schon eine results-Tabelle gibt
	if (table_exists(SNRESULTSTABLE, $DBname)) {
		$query="SHOW COLUMNS FROM ".SNRESULTSTABLE;
		$items=mysql_query($query);
		if (!$query) {
			echo 'Fehler beim Herausfinden der Feldnamen in Results: ' . mysql_error();
			exit;
		}
		if (mysql_num_rows($items) > 0) {
			while($row = mysql_fetch_assoc($items)) {
				if (isset($_POST[$row[Field]]) AND ($_POST[$row[Field]]!="")) {
					
					$value = mysql_real_escape_string($_POST[$row[Field]]);
					$variable = $row[Field];
					
					if( $variable != "id" AND $variable != "vpncode") {

						$update = "UPDATE ".SNRESULTSTABLE." SET ".$variable."=\"".$value."\" WHERE id=\"".$currentid."\"";
						mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
						if(DEBUG) {
							echo $update;
						}
					}
					
					if( $variable == "SN_Name" ) {
						
						$update = "UPDATE ".SNRESULTSTABLE." SET person=\"".$value."\" WHERE id=\"".$currentid."\"";
						mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
						if(DEBUG) {
							echo $update;
						}
					}
				}
			}
		}
	}
}

function snUpdatePostedRels($vpncode,$currentid) {
	// Prüfe, ob es überhaupt schon eine results-Tabelle gibt
	if (table_exists(SNRESULTSTABLE, $DBname)) {
		$query="SHOW COLUMNS FROM ".SNRESULTSTABLE;
		$items=mysql_query($query);
		if (!$query) {
			echo 'Fehler beim Herausfinden der Feldnamen in Results: ' . mysql_error();
			exit;
		}
		if (mysql_num_rows($items) > 0) {


			if( isset($_POST["SN_Rel_NW2"]) AND $_POST["SN_Rel_NW2"] != "" AND $_POST["SN_Rel_NW2"] != "Keine von den genannten") { //check whether the person is married or not
				
				// if yes, you'll have to input the data of the spouse into the database too

				while($row = mysql_fetch_assoc($items)) {
					if ( isset($_POST[$row[Field]]) /* AND ($_POST[$row[Field]]!="") */ ) {
						
						$value = mysql_real_escape_string($_POST[$row[Field]]);
						$variable = $row[Field];
						
						// if the person is married to somebody, also set their spouses' data accordingly
						
						// update everything  but not vpncode and id
						if( $variable != "id" AND $variable != "vpncode") {

							// update the user
							if( $variable != "SN_Rel_NW2" ) {
								$update = "UPDATE ".SNRESULTSTABLE." SET ".$variable."=\"".$value."\" WHERE id=\"".$currentid."\"";
								mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
								if(DEBUG) {
									echo $update;
								}
							} else {
								$name = getName($vpncode,$value);
								$update = "UPDATE ".SNRESULTSTABLE." SET ".$variable."=\"".$name."\" WHERE id=\"".$currentid."\"";
								mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
								if(DEBUG) {
									echo $update;
								}
							}
						}
						
						if( $variable != "id" AND $variable != "vpncode") {
							$username = getName($vpncode, $currentid);
							$spouseid = $_POST["SN_Rel_NW2"];

							if( $variable != "SN_Rel_NW2" ) {
								$update = "UPDATE ".SNRESULTSTABLE." SET ".$variable."=\"".$value."\" WHERE id=\"".$spouseid."\"";
								mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
								if(DEBUG) {
									echo "\t\t\t\t".$variable." with ".$value;
								}
							} else {
								$update = "UPDATE ".SNRESULTSTABLE." SET ".$variable."=\"".$username."\" WHERE id=\"".$spouseid."\"";
								mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
								if(DEBUG) {
									echo "-----------------".$update."--------------------";
								}
							}
						}
					}
				}
			} else { // if the person is NOT married or has an empty entry

				while($row = mysql_fetch_assoc($items)) {
					if ( isset($_POST[$row[Field]]) /* AND ($_POST[$row[Field]]!="") */ ) {
						
						$value = mysql_real_escape_string($_POST[$row[Field]]);
						$variable = $row[Field];
						
						
						// update everything  but not vpncode and id
						if( $variable != "id" AND $variable != "vpncode") {

							$update = "UPDATE ".SNRESULTSTABLE." SET ".$variable."=\"".$value."\" WHERE id=\"".$currentid."\"";
							mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
							if(DEBUG) {
								echo $update;
							}
						}
						
						// when the name gets changed, also update the 'person' field
						if( $variable == "SN_Name" ) {
							
							$update = "UPDATE ".SNRESULTSTABLE." SET person=\"".$value."\" WHERE id=\"".$currentid."\"";
							mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
							if(DEBUG) {
								echo $update;
							}
						}
					}
				}
				
			}
		}
	}
}

function snWritePostedVars($vpncode){
	// Prüfe, ob es überhaupt schon eine results-Tabelle gibt
	if (table_exists(SNRESULTSTABLE, $DBname)) {
		$query="SHOW COLUMNS FROM ".SNRESULTSTABLE;
		$items=mysql_query($query);
		if (!$query) {
			echo 'Fehler beim Herausfinden der Feldnamen in Results: ' . mysql_error();
			exit;
		}
		if (mysql_num_rows($items) > 0) {
			// if no entry with that id exists, insert instead of update
			if( !personExists($vpncode,$_POST["id"]) ) {
				
				$queryStart = "INSERT INTO ".SNRESULTSTABLE." (vpncode,wave,person";
				$queryString = ") VALUES ('".$vpncode."','".$_POST["wavecount"]."',";
				$queryValues = "";
				$queryEnd = ")";

				while ($row = mysql_fetch_assoc($items)) {
					if (isset($_POST[$row[Field]]) AND ($_POST[$row[Field]]!="")) {
						
						$value = mysql_real_escape_string($_POST[$row[Field]]);
						$variable = $row[Field];

						if( $row[Field] != 'vpncode' ) {
							// add the current field variable to list;
							$queryStart = $queryStart.",".$variable;
							// add the current value of field variable to list;
							if($queryValues == "") {
								$queryValues = "'".$value."'";
							} else {
								$queryValues = $queryValues.",'".$value."'";
							}
						}
					}
				}
				
				if( isset($_POST["SN_Name"]) && $_POST["SN_Name"] != "" ) {
					$query = $queryStart.$queryString."'".$_POST["SN_Name"]."',".$queryValues.$queryEnd;			 
					mysql_query($query)  or die ("Fehler bei " . $query . mysql_error() . "<br />");
				}
			} else {

				// if entry already exists, don't insert but update!
				while($row = mysql_fetch_assoc($items)) {
					if (isset($_POST[$row[Field]]) AND ($_POST[$row[Field]]!="")) {
						
						$value = mysql_real_escape_string($_POST[$row[Field]]);
						$variable = $row[Field];

						if( $variable != "id" AND $variable != "vpncode") {

							$update = "UPDATE ".SNRESULTSTABLE." SET ".$variable."=\"".$value."\" WHERE id=\"".$_POST["id"]."\"";
							if(DEBUG) {
								echo $update;
							}
							mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
						}

						if( $variable == "SN_Name" ) {

							$update = "UPDATE ".SNRESULTSTABLE." SET person=\"".$value."\" WHERE id=\"".$_POST["id"]."\"";
							if(DEBUG) {
								echo $update;
							}
							mysql_query($update)  or die ("Fehler bei " . $update . mysql_error() . "<br />");
						}					
					}
				}
			}
		}
	} else { 
		die("Es existiert keine Results-Tabelle, kann also nichts speichern!");
	}
}

function personExists($vpncode,$id) {
	$query = "SELECT person FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\" AND id=\"".$id."\"";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);
	if( $numitems == 0 ) {
		return false;
	} else {
		return true;
	}
}

function getName($vpncode,$id) {
	$query = "SELECT person FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\" AND id=\"".$id."\"";
	$item = mysql_query($query);
	$item = mysql_fetch_assoc($item);
	if(DEBUG) {
		echo "name of person is: \t".$item["person"];
	}
	return $item["person"];
}

function getIdFromName($vpncode,$name) {
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\" and person=\"".$name."\"";
	$person = mysql_query($query);
	$entry = mysql_fetch_assoc($person);
	return $entry["id"];
}

function entryExists($vpncode,$num) {
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);
	if( $numitems > 0 ) {
		return true;
	} else {
		return false;
	}
}

function getWaveCount($vpncount) {
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$results = mysql_query($query);
	$numentries = mysql_numrows($results); // number

	$wavecount = 0;

	for($n = 0; $n < $numentries; $n++) {
		$result = mysql_fetch_assoc($results);
		if( $result["wave"] > $wavecount ) {
			$wavecount = $result["wave"];
		}
	}

	return $wavecount;
}

function hasEntries($vpncode) {
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$items = mysql_query($query);
	$n = mysql_numrows($items);
	if($n == 0 ) { 
		return false;
	} else {
		return true;
	}
}

function getEntryId($vpncode,$num) {
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);
	
	for($n=0; $n < $numitems; $n++) {
		$item = mysql_fetch_assoc($items);
		if( $n == $num ) {
			return $item["id"];
		}
	}
}

function isMarried($vpncode,$id) {
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\" AND id=\"".$id."\"";
	$person = mysql_query($query);

	$numentries = mysql_numrows($person);
	$entry = mysql_fetch_assoc($person);

	if( $entry["SN_Rel_NW2"] != "" OR $entry["SN_Rel_NW2"] == "Keine von den genannten" ) {
		return true;
	} else {
		return false;
	}
}

function snPrintItemEdit($vpncode, $person, $id, $variablenname, $typ, $wortlaut, $antwortformatanzahl, $ratinguntererpol, $ratingobererpol, $allowedtypes, $zeile, $next) {
	
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode='".$vpncode."' AND person='".$person."'";
	$items = mysql_query($query);
	$numitems = mysql_numrows($items);

	$row = mysql_fetch_assoc($items);

	if( is_odd($zeile) ) {
	    $oe = "even";
	} else {
	    $oe = "odd";
	}

	echo "\n\n<tr class=\"" . $oe ."\"><td id=\"$typ\"";

	if (!in_array($typ,$allowedtypes)) {
		echo ">$id ist ein illegales item";
	}

	// instruction
	if ($typ=="instruktion") {
		echo " colspan=\"2\">" . $wortlaut;
	}
	
	// RATING
	if ($typ=="rating") {

		if($row[$variablenname] == "" OR $row[$variablenname] == "NULL" ) {
			echo " style=\"color: #ff0000;\">" . $wortlaut . "</td><td>";
		} else {
			echo " >" . $wortlaut . "</td><td>";
		}

		// WENN beide numerisch
		if (is_numeric($ratinguntererpol) && is_numeric($ratingobererpol)) {
			// FIX Hier gehört eine $step Variable hin!
			echo "<table><tr>";
			for ($k=$ratinguntererpol; $k <= $ratingobererpol; $k++) {
				echo "<td id=\"integerrating\"><input type=\"radio\" name=\"$variablenname\" value=\"$k\"".( $row[$variablenname] == $k ? "checked" : "" )."><br /></td>";
			}
			echo "</tr></table>";

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
			echo "<table><tr>";
			for ($k=$ratinguntererpol; $k <= $anzahl; $k++) {
				if ($k < $anzahl){
					// alle bis zur letzten Checkbox
					echo "<td id=\"integerrating\"><input type=\"radio\" name=\"$variablenname\" value=\"$k\"".( $row[$variablenname] == $k ? "checked" : "" )."><br />" . ($k) . "</td>";
				} else {
					// die letzte Checkbox
					echo "<td id=\"mixedlastrating\"><input type=\"radio\" name=\"$variablenname\" value=\"$k\"".( $row[$variablenname] == $k ? "checked" : "" )."><br />" . ($ratingobererpol) . "</td>";
					break;
				}
			}
			echo "</tr></table>";
			
		} else {
			// WENN beide Itempole Text sind
			// Mache eine kleine Table auf, damit unser Rating schön dargestellt wird
			echo "<table><tr>";
			echo "<td id=\"textfirstrating\" width=\"" . round(SRVYTBLWIDTH/6,0) . "\">$ratinguntererpol</td>";
			for ($k=1; $k <= $antwortformatanzahl; $k++) {
				echo "<td><input type=\"radio\" name=\"" . $variablenname . "\" value=\"$k\"".( $row[$variablenname] == $k ? "checked" : "" )."></td>";
			}
			echo "<td id=\"textlastrating\" width=\"" . round(SRVYTBLWIDTH/6,0) . "\">$ratingobererpol</td>";
			echo "</tr></table>";
		}
	}
	
	// MULTIPLE CHOICE
	if ($typ=="mc") {

		if($row[$variablenname] == "" OR $row[$variablenname] == "NULL" ) {
			echo " style=\"color: #ff0000;\">" . $wortlaut . "</td><td>";
		} else {
			echo " >" . $wortlaut . "</td><td>";
		}

		// Mache eine kleine Tabelle für unser Multiple Choice auf
		echo "<table><tr>";
		// Nimm dir die Zeile aus der item-Tabelle in der dieses Multiple Choice definiert ist.
		$query="SELECT * FROM ".ITEMSTABLE." WHERE id ='$id'";
		$thismc=mysql_fetch_array(mysql_query($query));
		// kleine Kontrolle: Wie viele sind es? id ist unique, also darf es nur 1 sein!
		$nummc=mysql_numrows(mysql_query($query));
		if ($nummc > 1) die ("massiver Fehler! Die id " . $id . " ist 2x vergeben");
		// Male die einzelnen Punkte hin, halte dich an den in Antwortformatanzahl angegebenen Wert.
		// Das heißt, wenn mehr MCalts angegeben sind, ignoriere sie dennoch!
		for ($k=1; $k <= $antwortformatanzahl; $k++) {		
			echo "<tr><td><input type=\"radio\" name=\"" . $variablenname . "\" value=\"$k\"".( $row[$variablenname] == $k ? "checked" : "" )."></td><td> " . $thismc[MCalt.$k] . "</td></tr>";
		}
		echo "</tr></table>";
	}
	
	// OFFEN
	if ($typ=="offen") {

		if($row[$variablenname] == "" OR $row[$variablenname] == "NULL" ) {
			echo " style=\"color: #ff0000;\">" . $wortlaut . "</td><td>";
		} else {
			echo " >" . $wortlaut . "</td><td>";
		}

		// Betimme die Anzahl der Zeilen aus der Antwortformatanzahl
		
		if ($antwortformatanzahl > TXTBXWIDTH) {
			$rows = round($antwortformatanzahl/TXTBXWIDTH);
			echo "<textarea cols=\"" .  TXTBXWIDTH . "\" rows=\"" . $rows ."\" name=\"" . $variablenname . "\" wrap=\"virtual\">".$row[$variablenname]."</textarea>";
		} else {
			
			echo "<textarea cols=\"" .  $antwortformatanzahl . "\" rows=\"1\" name=\"" . $variablenname . "\" wrap=\"virtual\">".$row[$variablenname]."</textarea>";
		}
	};
	// Beende die Zeile für dieses item
	echo "</td></tr>";
}

function snPrintItem($id, $variablenname, $typ, $wortlaut, $antwortformatanzahl, $ratinguntererpol, $ratingobererpol, $allowedtypes, $zeile, $next, $rows, $currentid, $vpncode) {

	// FIX: Fehlende itemtypen:
	// mcm -multiple answer - einfach neuen Typ mcm, und in printitem eine entsprechende Zeile mit Setting mehrfachnennung. In Results jede einzelne Antwort als Variable. SCHEIßE!
	// sind wir odd oder even?
	if( is_odd($zeile) ) {
	    $oe = "even";
	} else {
	    $oe = "odd";
	}

	if($typ == "snpartner") {
		echo "<tr class=\"".$oe."\"><td>".$wortlaut."</td>\n";
		echo "<td id=\"".$typ."\">";
		renderSNPartnerSelect($vpncode,$currentid);
		echo "</td></tr>";
	} else {
		printitem($id, $variablenname, $typ, $wortlaut, $antwortformatanzahl, $ratinguntererpol, $ratingobererpol, $allowedtypes, $zeile, $next, $rows);
	}
}

function renderSNPartnerSelect($vpncode,$currentid) {
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$people = mysql_query($query);
	$numpeople = mysql_numrows($people);

	echo "<select name=\"SN_Rel_NW2\" size=\"1\">\n\n";
	echo "<option >Keine von den genannten</option>\n";
	for($n=0; $n < $numpeople; $n++) {
		$person = mysql_fetch_assoc($people);
		if( !isMarried($vpncode,$person["id"]) AND $person["id"] != $currentid) {
			echo "<option value=\"".$person["id"]."\">".$person["person"]."</option>\n";
		} 
	}
	echo "</select>\n";
}



?>
