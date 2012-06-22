<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

include ('includes/social-network.php');

// are we here the first time?
// if yes, set stepcount to 0
if( !isset($_POST["stepcount"]) ) {
	if(DEBUG) {
		echo "stepcount is NOT in '$_POST'\n";
	}
	$stepcount = 0;
} else {
	if(DEBUG) {
		echo "stepcount IS in '$_POST'\n";
	}
	$stepcount = $_POST["stepcount"];
}

snWritePostedVars($vpncode);

if( isset($_POST["snbuildupdone"]) && $_POST["snbuildupdone"] != "" ) {
	$stepcount = 2;
}

// wave counter: at which stage is vpn in adding people?
// if its not in $_POST and $vpncode already has entered people, look what is the highest wavecount 
// this is for cases when the probant comes back to the study after taking a break
if( !isset($_POST["wavecount"]) AND hasEntries($vpncode) ) {
	if(DEBUG) {
		echo "wavecount not in $_POST and person has already got entries in snresults";
	}
	$wavecount = getWaveCount($vpncode);
	$_POST["wavecount"] = $wavecount;
	
	// if wavecount is in $_POST take over that value
} elseif( isset($_POST["wavecount"]) AND $_POST["wavecount"] != "") { 
	if(DEBUG) {
		echo "wavecount is in $_POST: setting wavecount to value from $_POST";
	}
	$wavecount = $_POST["wavecount"];

	// if wavecount is not in $_POST nor in db set to 0 
	// (usually the case when the proband just starts the sn part of the survey
} else {
	if(DEBUG) {
		echo "wavecount neither in $_POST nor in db: setting wavecount to 0";
	}
	$wavecount = 0;
	$_POST["wavecount"] = $wavecount;
}

// if we come to SN test site, and no complete data set is in the db
if( $stepcount == 0 ) {
	if( hasEntries($vpncode) ) {
		if( allComplete($vpncode,'snnetworkbuildup') ) {
			// if the proband comes to site and his first data set is complete
			// increment the stepcount and store it in $_SESSION
			$stepcount = 1;

			if(DEBUG) {
				echo "wavecount: \t:" . $wavecount;
			}

			// write the entries in the db first
			// with every entry having its own form
			if( hasEntries($vpncode) ) {
				renderSNEntries($vpncode,$stepcount);
			} 

			// then write the form for the data entry form
			echo "<form  action=\"sn-krgn.php\" method=\"post\">";
			hiddeninput("vpncode",getvpncode());
			hiddeninput("stepcount",$stepcount);
			hiddeninput("wavecount",$_POST["wavecount"]);
			if( hasEntries($vpncode) ) {
				addSNPerson($vpncode,$allowedtypes,2,"snnetworkbuildup",$currentid);
			} else {
				addSNPerson($vpncode,$allowedtypes,1,"snnetworkbuildup",$currentid);
			}
		} else {
			// if there is an entry but isn't complete, 
			if(DEBUG) {
				echo "wavecount: \t:" . $wavecount;
			}

			// write the form straight away
			echo "<form  action=\"sn-krgn.php\" method=\"post\">";
			hiddeninput("vpncode",getvpncode());
			hiddeninput("stepcount",$stepcount);
			hiddeninput("wavecount",$_POST["wavecount"]);
			hiddeninput("id",getIncomplete($vpncode,'snnetworkbuildup'));

			// write the edit form to complete the incomplete data set
			echo "<table width=\"800\"><tr class=\"even\"> <td id=\"instruktion\" colspan=\"2\">Bitte füllen sie alle Informationen vollständig aus. Leere Felder werden rot dargestellt. </td></tr></table>";

			editSNPerson($vpncode,getIncomplete($vpncode,'snnetworkbuildup'),$allowedtypes);
		}
	} else {
		// then write the form for the data entry form
		echo "<form  action=\"sn-krgn.php\" method=\"post\">";
		hiddeninput("vpncode",getvpncode());
		hiddeninput("stepcount",$stepcount);
		hiddeninput("wavecount",$_POST["wavecount"]);
		addSNPerson($vpncode,$allowedtypes,1,"snnetworkbuildup",$currentid);
	}
} elseif ( $stepcount == 1 && canAddPerson($vpncode) ) {

	if( allComplete($vpncode,'snnetworkbuildup') ) {
		// if the proband comes to site and his first data set is complete
		// increment the stepcount and store it in $_SESSION
		if(DEBUG) {
			echo "wavecount: \t:" . $wavecount;
		}
			
		if( hasEntries($vpncode) ) {
			renderSNEntries($vpncode,$stepcount);
		}

		// then write the form for the data entry form
		echo "<form  action=\"sn-krgn.php\" method=\"post\">";
		hiddeninput("vpncode",getvpncode());
		hiddeninput("stepcount",$stepcount);
		hiddeninput("wavecount",$_POST["wavecount"]);
		addSNPerson($vpncode,$allowedtypes,2,"snnetworkbuildup",$currentid);
	} else {
		if(DEBUG) {
			echo "wavecount: \t:" . $wavecount;
		}

		// write the form straight away
		echo "<form  action=\"sn-krgn.php\" method=\"post\">";
		hiddeninput("vpncode",getvpncode());
		hiddeninput("stepcount",$stepcount);
		hiddeninput("wavecount",$_POST["wavecount"]);
		hiddeninput("id",getIncomplete($vpncode,'snnetworkbuildup'));

		// write the edit form to complete the incomplete data set
		echo "<table width=\"800\"><tr class=\"even\"> <td id=\"instruktion\" colspan=\"2\">Bitte füllen sie alle Informationen vollständig aus. Leere Felder werden rot dargestellt. </td></tr></table>";

		editSNPerson($vpncode,getIncomplete($vpncode,'snnetworkbuildup'),$allowedtypes);
	}

} elseif ( $stepcount == 1 && !canAddPerson($vpncode) ) {
	if(DEBUG) {
		echo "cannot add more people";
		echo "wavecount: \t:" . $wavecount;
	}
	checkAllPersons($vpncode,$allowedtypes,2,3,false);
} elseif ( $stepcount == 2 ) {
	if(DEBUG) {
		echo "wavecount: \t:" . $wavecount;;		
	}
	$wavecount = $wavecount + 1;
	$_POST["wavecount"] = $wavecount;
	checkAllPersons($vpncode,$allowedtypes,2,3,true);
}

echo "</form>";

// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
