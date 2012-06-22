<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

include ('includes/social-network.php');

// TODO !! don't display the contacts that have already been found to be in a relationship with each other
// need a hiddeninput with a global counter for the while loo

// create the counter if its not in $_POST
if( isset($_POST["counter"]) && $_POST["counter"] != "" ) {
	if(DEBUG) {
		echo "counter is set in POST array! ";
		echo "+++ counter=".$_POST["counter"]." +++";
	}
	$counter = $_POST["counter"];
} else {
	if(DEBUG) {
		echo "counter is NOT set in POST array!\n";
		echo "setting to 0\n";
	}
	$counter = 0;
	if(DEBUG) {
		echo "counter is: ".$counter."\n";
	}
}

// create a second (q)counter if its not in $_POST
if( isset($_POST["qcounter"]) && $_POST["qcounter"] != "" ) {
	if(DEBUG) {
		echo "qcounter is set in POST array! ";
		echo "+++ qcounter=".$_POST["qcounter"]." +++";
	}
	$qcounter = $_POST["qcounter"];
} else {
	if(DEBUG) {
		echo "qcounter is NOT set in POST array!\n";
		echo "setting to 0\n";
	}
	$qcounter = 0;
	if(DEBUG) {
		echo "qcounter is: ".$qcounter."\n";
	}
}


// build the query for getting all the users
$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
$persons = mysql_query($query);
// how many entries are there for current user?
$personcount = mysql_numrows($persons);

$previousid = $_POST["currentid"];

snUpdatePostedRels($vpncode,$previousid);

// get the id of the person we're working on based on the global counter
for($n=0; $n < $personcount; $n++) {
	if( !isMarried($vpncode,getEntryId($vpncode,$n)) ) { 

		$currentid = getEntryId($vpncode,$n);

		$id = $currentid;
		$_POST["id"] = $id;		

		if(DEBUG) {
			echo "currentid is: \t".$currentid."\t";
		}
		break;
	}
}

if(DEBUG) {
	foreach( $_POST as $key => $value ) {
		echo "\n key:\t".$key."\t value:\t".$value."\n\n";
	}
	echo "personcount is: ".$personcount."\n";
}


/* all things to do with _POST */

// write answer into everybody's fields
if( isset($_POST["SN_Rel_NW1"]) AND $_POST["SN_Rel_NW1"] != "" ) {
	$wquery = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$people = mysql_query($wquery);
	$numpeople = mysql_numrows($people);
	for($n=0; $n < $numpeople; $n++) {
		$person = mysql_fetch_assoc($people);
		$writeYN = "UPDATE ".SNRESULTSTABLE." SET SN_Rel_NW1=".$_POST["SN_Rel_NW1"]." WHERE id=\"".$person["id"]."\"";
		mysql_query($writeYN);
	}
}

snUpdatePostedRels($vpncode,$previousid);

/* all things to do with _POST end */

// if at first page/person display the instruction with a question whether some
// people are generally at all liaised with each other
if( 
   $counter < $personcount 
   AND 
   ( !isset($_POST["SN_Rel_NW1"]) 
     OR $_POST["SN_Rel_NW1"] == "" 
     OR $_POST["SN_Rel_NW1"] == 1 )
   AND
   isset($currentid)
    ) {
	
	if( $counter == 0 AND ( !isset($_POST["SN_Rel_NW1"]) OR $_POST["SN_Rel_NW1"] == "")) {
		
		echo "<form action=\"sn-rel.php\" method=\"post\">";

		// display instruction number 4 and general question
		hiddeninput("vpncode",getvpncode());
		hiddeninput("counter",$counter);		
		hiddeninput("currentid",$currentid);

		addSNRelations($vpncode,$allowedtypes,4,"snrelationsstart",$currentid);

		echo "</form>";	

	} elseif( $counter == 0 ) {
		
		if(DEBUG) {
			echo "we are at the first persons entry....\n";
			echo "currentid is: \t".$currentid;
		}
		
		// increment global counter
		$counter = $counter + 1;

		echo "<form action=\"sn-rel.php\" method=\"post\">";

		hiddeninput("vpncode",getvpncode());
		hiddeninput("counter",$counter);
		hiddeninput("currentid",$currentid);		

		addSNRelations($vpncode,$allowedtypes,4,"snrelations",$currentid);
		
		echo "</form>";	
		
		/* if we are not yet at the last person */
	} elseif( $counter < $personcount AND $counter != ($personcount - 1)) {
		// increment global counter
		$counter = $counter + 1;

		if(DEBUG) {
			echo "we are at person no. $counter entry....\n";
		}

		echo "<form action=\"sn-rel.php\" method=\"post\">";

		hiddeninput("vpncode",getvpncode());
		hiddeninput("counter",$counter);
		hiddeninput("currentid",$currentid);		

		addSNRelations($vpncode,$allowedtypes,4,"snrelations",$currentid);
		
		echo "</form>";			

		/* if we are at the last person! */
	} elseif( $counter < $personcount AND $counter == ($personcount - 1)) {

		if(DEBUG) {
			echo "we are at the last persons entry....\n";
		}

		// increment global counter
		$counter = $counter + 1;

		echo "<form action=\"sn-rel.php\" method=\"post\">";

		hiddeninput("vpncode",getvpncode());
		hiddeninput("counter",$counter);
		hiddeninput("currentid",$currentid);		


		addSNRelations($vpncode,$allowedtypes,4,"snrelations",$currentid);
		
		echo "</form>";					
	}
} elseif ( $counter >= $personcount OR ( isset($_POST["SN_Rel_NW1"]) AND $_POST["SN_Rel_NW1"] == 2 ) OR !isset($currentid) ) {

	// build the query for getting all the users
	$query = "SELECT * FROM ".SNRESULTSTABLE." WHERE vpncode=\"".$vpncode."\"";
	$people = mysql_query($query);

	for($n=0; $n < $personcount; $n++) {
		$person = mysql_fetch_assoc($people);
		if( $n == $qcounter ) {
			if( $n < ($personcount -1) ) {
				echo "<form action=\"sn-rel.php\" method=\"post\">";

				hiddeninput("vpncode",getvpncode());
				hiddeninput("counter",$counter);
				hiddeninput("qcounter",$qcounter + 1);
				hiddeninput("currentid",$person["id"]);
				hiddeninput("SN_Rel_NW1",$_POST["SN_Rel_NW1"]);

				addSNRelations($vpncode,$allowedtypes,5,"snquestion",$person["id"]);
				echo "</form>";
			} else {
				echo "<form action=\"sn-done.php\" method=\"post\">";
				hiddeninput("vpncode",getvpncode());
				hiddeninput("currentid",$person["id"]);

				addSNRelations($vpncode,$allowedtypes,5,"snquestion",$person["id"]);
				echo "</form>";
			}
		} 
	}
	
}


// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
