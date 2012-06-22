<?
require ('admin_header.php');
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');

// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

typesdropdown($allowedtypes, "mc");

echo "<table width=\"" . SRVYTBLWIDTH . "\">";

if( !table_exists(ADMINTABLE,$DBhost) ) {
	setupadmin();
}

//übernehme Action
if (isset($_GET['action'])) {
	$action = $_GET['action'];
	// Prüfe, ob Funktion erlaubt ist
  $allowed = array(
    "deleteresults", 
    "createresulttab", 
    "export_results", 
    "createvpndatatab", 
    "deletevpndatatab", 
    "deleteinstructionstab", 
    "createinstuctionstab", 
    "sndeleteresults", 
    "sncreateresulttab", 
    "resetitemdisplaytable");
  
	if (in_array($action, $allowed)) {
		// Führe sie aus
		echo "<tr class=\"adminmessage\"><td>";
		$action();
		echo "</td></tr>";
	} else {
		// Meckere
		echo "What the <i>beep</i> do you want from me?";
	}
}

echo "<tr class=\"even\"><td><a href=\"admin.php\">Globale Einstellungen</a></td></tr>";

 if (!table_exists(ITEMSTABLE, $DBhost)) {
	echo "<tr class=\"even\"><td><a href=\"uploaditems.php\">Itemtabelle importieren</a></td></tr>";
} else {
	echo "<tr class=\"even\"><td><a href=\"uploaditems.php\">Itemtabelle importieren</a></td></tr>";
	
	if (!table_exists(RESULTSTABLE, $DBhost)) {
		echo "<tr class=\"odd\"><td><a href=\"index.php?action=createresulttab\">Ergebnistabelle erstellen</a></td></tr>";
	} else {
		echo "<tr class=\"odd\"><td><a href=\"index.php?action=deleteresults\">Ergebnistabelle löschen</a></td></tr>";
		echo "<tr class=\"odd\"><td><a href=\"csv_export.php\">Ergebnistabelle exportieren</a></td></tr>";
	}

	if (!table_exists(SNRESULTSTABLE, $DBhost)) {
		echo "<tr class=\"even\"><td><a href=\"index.php?action=sncreateresulttab\">SN-Ergebnistabelle erstellen</a></td></tr>";
	} else {
		echo "<tr class=\"even\"><td><a href=\"index.php?action=sndeleteresults\">SN-Ergebnistabelle löschen</a></td></tr>";
	}

	if(PARTNER) {
		if (!table_exists(VPNDATATABLE, $DBhost)) {
			echo "<tr class=\"even\"><td><a href=\"index.php?action=createvpndatatab\">VPNDatatabelle erstellen</a></td></tr>";
		} else {
			echo "<tr class=\"even\"><td><a href=\"index.php?action=deletevpndatatab\">VPNDatatabelle löschen</a></td></tr>";
		}
	}

	echo "<tr class=\"odd\"><td><a href=\"edititems.php\">Items editieren</a></td></tr>";

	echo "<tr class=\"even\"><td><a href=\"index.php?action=resetitemdisplaytable\">Anzeige-Zähler zurücksetzen</a></td></tr>";

	if (table_exists(RESULTSTABLE, $DBhost)) {
		echo "<tr class=\"odd\"><td><a href=\"displayresults.php\">Ergebnisse anzeigen</a></td></tr>";
		echo "<tr class=\"even\"><td><a href=\"editstudies.php\">Studien editieren</a></td></tr>";	
		echo "<tr class=\"even\"><td><a href=\"edittimes.php\">Edit-Zeiten editieren</a></td></tr>";	
		echo "<tr class=\"even\"><td><a href=\"editemails.php\">Emails editieren</a></td></tr>";	
		echo "<tr class=\"even\"><td><a href=\"editsubstitutions.php\">Subsitutionen editieren</a></td></tr>";	
	} else {
		echo "<tr class=\"odd\"><td><a href=\"editstudies.php\">Studien editieren</a></td></tr>";	
		echo "<tr class=\"even\"><td><a href=\"edittimes.php\">Edit-Zeiten editieren</a></td></tr>";	
		echo "<tr class=\"even\"><td><a href=\"editemails.php\">Emails editieren</a></td></tr>";	
		echo "<tr class=\"even\"><td><a href=\"editsubstitutions.php\">Subsitutionen editieren</a></td></tr>";	
	}

	echo "<tr class=\"odd\"><td><a href=\"vpncodes.php\">Vpncodes bearbeiten</a></td></tr>";        
	echo "<tr class=\"even\"><td><a href=\"../acp/acp.php\">Zur&uuml;ck zur Studien&uuml;bersicht</a></td></tr>";
}

echo "</table>";


// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');#

?>