<?
require ('admin_header.php');
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

// check if the a studies config table already exists
$check = "SHOW TABLES LIKE \"".STUDIESTABLE."\"";
$result = mysql_query($check) or die( mysql_error() );
$result = mysql_fetch_row($result);
if( empty($result) ) {
	createstudiestable();
	echo "created ".STUDIESTABLE." for you....";
} else {
	if( isset($_POST['add_studyname']) AND $_POST['add_studyname'] != "" ) {
		$query = "SELECT id FROM ".STUDIESTABLE." WHERE name='".$_POST['add_studiesname']."';";
		$result = mysql_query($query);
		$entry = mysql_fetch_row($result);

		if( empty($entry) ) {   /* no entry with that name, so enter it */
			$query_string = "`name`";
			$query_values = "'".$_POST['add_studyname']."'";

			if( (isset($_POST['add_studyloop']) AND $_POST['add_studyloop'] != "" ) AND 
			    (isset($_POST['add_studyiterations']) AND $_POST['add_studyiterations'] != "" ) ) {
				$query_string = $query_string . ",`loop`,`iterations`";
				$query_values = $query_values . "," . $_POST['add_studyloop'] . "," . $_POST['add_studyiterations'] . "";

			    if (isset($_POST['add_study_max_attempts']) AND $_POST['add_study_max_attempts'] != "" ) {
					$query_string = $query_string . ",`max_attempts`";
					$query_values = $query_values . "," . $_POST['add_study_max_attempts'];
				}
			}

			if( (isset($_POST['add_studypostemail']) AND $_POST['add_studypostemail'] != "" ) AND 
			    (isset($_POST['add_studypostemailid']) AND $_POST['add_studypostemailid'] != "" ) ) {
				$query_string = $query_string . ",`postemail`,`postemail_id`";
				$query_values = $query_values.",'".$_POST['add_studypostemail']."',".$_POST['add_studypostemailid']."";
				
			}

			if( (isset($_POST['add_studyloopemail']) AND $_POST['add_studyloopemail'] != "" ) AND 
			    (isset($_POST['add_studyloopemailid']) AND $_POST['add_studyloopemailid'] != "" ) ) {
				$query_string = $query_string . ",`loopemail`,`loopemail_id`";	
				$query_values = $query_values.",'".$_POST['add_studyloopemail']."',".$_POST['add_studyloopemailid']."";
				// echo $_POST['add_studyloopemailid'];
			}

			if( isset($_POST['add_studyorder']) AND $_POST['add_studyorder'] != "" )  {
				$query_string = $query_string . ",`order`";
				$query_values = $query_values.",".$_POST['add_studyorder']."";
			}
			
			if( (isset($_POST['deltaday']) AND $_POST['deltaday'] != "") AND 
				(isset($_POST['deltahour']) AND $_POST['deltahour'] != "") AND 
				(isset($_POST['deltaminute']) AND $_POST['deltaminute'] != "") ) {
				$query_string = $query_string . ",`delta`";
				$deltatime = $_POST['deltaday'] . ":" . $_POST['deltahour'] . ":" . $_POST['deltaminute'];
				$query_values = $query_values.",'".$deltatime."'";
			}

			if( isset($_POST['add_studyskipif']) AND $_POST['add_studyskipif'] != "" )  {
				$query_string = $query_string . ",`skipif`";
				$query_values = $query_values.",".$_POST['add_studyskipif']."";
			}
			
			$query = "INSERT INTO ".STUDIESTABLE." (".$query_string.") VALUES(".$query_values.");";
			// echo $query;
			mysql_query($query) or die( mysql_error() );
		} else {
			echo "entry with that name already exists....";
		}
	}
	
	// delete the entries selected 
	if( isset($_POST['deletestudies']) AND !empty($_POST['edit_studyselect']) ) {
		$selected = $_POST['edit_studyselect'];
		foreach($selected as $item) {
			mysql_query("DELETE FROM ".STUDIESTABLE." WHERE id=".$item.";");
		}	  
	}

	// update all edited fields
	if( isset($_POST['updatestudies']) ) {
		$numentries = mysql_query("SELECT id FROM ".STUDIESTABLE) or die( mysql_error() );
		while($row = mysql_fetch_assoc($numentries) ) {
			if( isset($_POST["edit_studyorder_".$row['id']]) AND $_POST["edit_studyorder_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `order`=".$_POST["edit_studyorder_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studyname_".$row['id']]) AND $_POST["edit_studyname_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `name`='".$_POST["edit_studyname_".$row['id']]."' WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studyloop_".$row['id']]) AND $_POST["edit_studyloop_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `loop`=".$_POST["edit_studyloop_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studyiterations_".$row['id']]) AND $_POST["edit_studyiterations_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `iterations`=".$_POST["edit_studyiterations_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_study_max_attempts_".$row['id']]) AND $_POST["edit_study_max_attempts_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `max_attempts`=".$_POST["edit_study_max_attempts_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studyloopemail_".$row['id']]) AND $_POST["edit_studyloopemail_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `loopemail`=".$_POST["edit_studyloopemail_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studyloopemailid_".$row['id']]) AND $_POST["edit_studyloopemailid_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `loopemail_id`=".$_POST["edit_studyloopemailid_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studypostemail_".$row['id']]) AND $_POST["edit_studypostemail_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `postemail`=".$_POST["edit_studypostemail_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studypostemailid_".$row['id']]) AND $_POST["edit_studypostemailid_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `postemail_id`=".$_POST["edit_studypostemailid_".$row['id']]." WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( (isset($_POST['edit_deltaday_'.$row['id']]) AND $_POST['edit_deltaday_'.$row['id']] != "") AND 
				(isset($_POST['edit_deltahour_'.$row['id']]) AND $_POST['edit_deltahour_'.$row['id']] != "") AND 
				(isset($_POST['edit_deltaminute_'.$row['id']]) AND $_POST['edit_deltaminute_'.$row['id']] != "") ) {
				$deltatime = $_POST['edit_deltaday_'.$row['id']] . ":" . $_POST['edit_deltahour_'.$row['id']] . ":" . $_POST['edit_deltaminute_'.$row['id']];
				mysql_query("UPDATE ".STUDIESTABLE." SET `delta`='".$deltatime."' WHERE id=".$row['id']) or die( mysql_error() );
			}
			if( isset($_POST["edit_studyskipif_".$row['id']]) AND $_POST["edit_studyskipif_".$row['id']] != "" ) {
				mysql_query("UPDATE ".STUDIESTABLE." SET `skipif`='".$_POST["edit_studyskipif_".$row['id']]."' WHERE id=".$row['id']) or die( mysql_error() );
			}
		}
	}
	
	// load all results from that table
	$query = "SELECT * FROM ".STUDIESTABLE." ORDER BY `order`;";
	$results = mysql_query($query) or die( mysql_error() );
	$numresults = mysql_numrows($results);
}

$emails = get_all_emails();

echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"back to main menu\"></input></form>";

// render the old entries
if( $numresults > 0 AND !empty($numresults) ) {
	echo "<p style=\"background: #CCCCCC;\"><strong>Edit Studies</strong></p>";

	// build a form to edit the already existing entries //
	echo "<form method=\"POST\" action=\"editstudies.php\"><table class=\"editstudies\">";
	echo "<th> Select </th><th> Delta </th><th> Order </th><th> Name </th><th> Loop </th><th> Iterations </th><th> Max. Attempts </th><th> LoopEmail </th><th> PostEmail</th><th>Skipif</th>";

	$count = 0;
	while( $row = mysql_fetch_assoc($results) ) { /*  for each row in the results resource do this... */
		if( $count % 2 == 0 ) {
			echo "<tr style=\"background: #aaaaaa;\">"; 
		} else {
			echo "<tr style=\"background: #dddddd;\">";
		}
		$count++;

		echo "<td><input type=\"checkbox\" name=\"edit_studyselect[]\" value=\"".$row['id']."\"></input></td>";

		$deltatimes = split(":",$row['delta']);
		echo "<td><select name=\"edit_deltaday_".$row['id']."\">";
			for( $i=0; $i < 366; $i++ ) {
				if( $i == $deltatimes[0] ) {
					echo "<option selected=\"selected\" value=\"".$i."\">".$i."</option>";
				} else {
					echo "<option value=\"".$i."\">".$i."</option>";
				}
			}
		echo "</select>- Day<br />";

		echo "<select name=\"edit_deltahour_".$row['id']."\">";
			for( $i=0; $i < 24; $i++ ) {
				if( $i == $deltatimes[1] ) {
					$value = sprintf("%02d",$i);
					echo "<option selected=\"selected\" value=\"".$value."\">".$value."</option>";
				} else {
					$value = sprintf("%02d",$i);
					echo "<option value=\"".$value."\">".$value."</option>";
				}
			}
		echo "</select>- Hour<br />";

		echo "<select name=\"edit_deltaminute_".$row['id']."\">";
		for( $i=0; $i < 60; $i++ ) {
			if( $i == $deltatimes[2]) {
				$value = sprintf("%02d",$i);
				echo "<option selected=\"selected\" value=\"".$value."\">".$value."</option>";
			} else {
				$value = sprintf("%02d",$i);
				echo "<option value=\"".$value."\">".$value."</option>";
			}
		}
		
		echo "</select>- Minute</td>";
		
		echo "<td><input style=\"width:50px;\" type=\"text\" name=\"edit_studyorder_".$row['id']."\" value=\"".$row['order']."\"></input></td>";
		echo "<td><input type=\"text\" size=\"8\" name=\"edit_studyname_".$row['id']."\" value=\"".$row['name']."\"></input></td>";
		echo "<td><select name=\"edit_studyloop_".$row['id']."\" >";
		if($row['loop'] == 0) { 
			echo "<option selected=\"selected\" value=\"0\" >false</option><option value=\"1\">true</option>"; 
		} elseif ($row['loop'] == '1') { 
			echo "<option value=\"0\">false</option><option selected=\"selected\" value=\"1\" >true</option>"; 
		}
		echo "</select></td>";
		echo "<td><input style=\"width:50px;\" type=\"text\" name=\"edit_studyiterations_".$row['id']."\" value=\"".$row['iterations']."\"></input></td>";
		echo "<td><input style=\"width:50px;\" type=\"text\" name=\"edit_study_max_attempts_".$row['id']."\" value=\"".$row['max_attempts']."\"></input></td>";

		echo "<td><ul class=\"emailconfig\">";
		echo "<li><select name=\"edit_studyloopemail_".$row['id']."\" >";
		if($row['loopemail'] == 0 ) {
			echo "<option selected=\"selected\" value=\"0\">false</option><option value=\"1\">true</option>";
		} elseif($row['loopemail'] == 1) {
			echo "<option value=\"0\">false</option><option selected=\"selected\" value=\"1\">true</option>";
		}
		echo "</select> Send?</li>";
		echo "<li><select name=\"edit_studyloopemailid_".$row['id']."\" >";
		$empty_slot = true;
		
		foreach( $emails as $email ) {
			if( $email['id'] == $row['loopemail_id'] ) {
				
				$empty_slot = false;
				break;
			} else {
				$empty_slot = true;
			}
		}

		if( $empty_slot ) {
			echo "<option selected=\"selected\" value='-99'></option>";
		}
		
		foreach($emails as $email) {
			if( $email['id'] == $row['loopemail_id'] ) :
				echo "<option selected='selected' value='".$email['id']."'>".$email['name']."</option>";
			else :
				echo "<option value='".$email['id']."'>".$email['name']."</option>";
			endif;
		}
		echo "</select>Name</li></ul></td>";

		echo "<td><ul class=\"emailconfig\">";
		echo "<li><select name=\"edit_studypostemail_".$row['id']."\" >";
		if($row['postemail'] == 0 ) {
			echo "<option selected=\"selected\" value=\"0\">false</option><option value=\"1\">true</option>";
		} elseif($row['postemail'] == 1) {
			echo "<option value=\"0\">false</option><option selected=\"selected\" value=\"1\">true</option>";
		}
		echo "</select> Send?</li>";
		echo "<li><select name=\"edit_studypostemailid_".$row['id']."\" >";
		$empty_slot = true;
		
		foreach( $emails as $email ) {
			if( $email['id'] == $row['postemail_id'] ) {
				
				$empty_slot = false;
				break;
			} else {
				$empty_slot = true;
			}
		}

		if( $empty_slot ) {
			echo "<option selected=\"selected\" value='-99'></option>";
		}
		
		foreach($emails as $email) {
			if( $email['id'] == $row['postemail_id'] ) :
				echo "<option selected='selected' value='".$email['id']."'>".$email['name']."</option>";
			else :
				echo "<option value='".$email['id']."'>".$email['name']."</option>";
			endif;
		}
		echo "</select>Name</li></ul></td>";

		echo "<td><input style=\"width:50px;\" type=\"text\" name=\"edit_studyskipif_".$row['id']."\" value=\"".$row['skipif']."\"></input></td>";
		
		echo "</tr>";
	}

	echo "</table>";
	echo "<input type=\"submit\" name=\"deletestudies\" value=\"Delete Selected\"></input>";
	echo "<input type=\"submit\" name=\"updatestudies\" value=\"Update Studies\"></input></form>";
}

echo "<p style=\"background: #CCCCCC;\"><strong>Add a new Study</strong></p>";
echo "<form method=\"POST\" action=\"editstudies.php\">";

// render a form to create entries
echo "<table class=\"editstudies\">
			<th> Delta </th>
			<th> Order </th>
			<th> Name </th>
			<th> Loop </th>
			<th> Iterations </th>
			<th> Max. Attempts </th>
			<th> LoopEmail </th>
			<th> PostEmail </th>
			<th> SkipIf </th>
			<th></th>";

echo "<tr>";
	echo "<td><select name=\"deltaday\">";
	for( $i=0; $i < 366; $i++ ) {
			echo "<option value=\"".$i."\">".$i."</option>";
		}
	echo "</select>- Day<br />";

	echo "<select name=\"deltahour\">";
		for( $i=0; $i < 24; $i++ ) {
			$value = sprintf("%02d",$i);
			echo "<option value=\"".$value."\">".$value."</option>";
		}
	echo "</select>- Hour<br />";

	echo "<select name=\"deltaminute\">";
	for( $i=0; $i < 60; $i++ ) {
		$value = sprintf("%02d",$i);
		echo "<option value=\"".$value."\">".$value."</option>";
	}
	echo "</select>- Minute</td>";
	echo "<td><input type=\"text\" name=\"add_studyorder\" style=\"width:50px;\"></input></td>
			<td><input type=\"text\" name=\"add_studyname\"></input></td>
			<td><select name=\"add_studyloop\"> <option value=\"0\">false</option><option value=\"1\">true</option> </select></td>
			<td><input type=\"text\" name=\"add_studyiterations\" style=\"width:50px;\"></input></td>
			<td><input type=\"text\" name=\"add_study_max_attempts\" style=\"width:50px;\"></input></td>
			<td><ul class=\"emailconfig\">
			<li><select name=\"add_studyloopemail\"> <option value=\"0\">false</option><option value=\"1\">true</option></select> Send</li>
			<li><select name=\"add_studyloopemailid\"><option value='-99'></option>";

foreach($emails as $email) {
	echo "<option value='".$email['id']."'>".$email['name']."</option>";
}

echo "</select> Name</li>
			</ul></td>
			
			<td><ul class=\"emailconfig\">
			<li><select name=\"add_studypostemail\"> <option value=\"0\">false</option><option value=\"1\">true</option></select> Send</li>
			<li><select name=\"add_studypostemailid\"><option value='-99'></option>";

foreach($emails as $email) {
	echo "<option value='".$email['id']."'>".$email['name']."</option>";
}

echo "</select> Name</li></ul></td>";

echo "<td><input style=\"width:50px;\" type=\"text\" name=\"add_studyskipif\"></input></td>";
		
echo "</tr></table>";

echo "<td><input type='submit' value='Enter'></input></td>";
echo "</form>";

// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
