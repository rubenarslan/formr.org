<?
require ('admin_header.php');
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');


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
		echo "<li>";
		$action();
		echo "</li>";
	} else {
		// Meckere
		echo "What the <i>beep</i> do you want from me?";
	}
}

echo '<nav>
	<ul class="nav nav-pills nav-stacked">';

if( !table_exists(ADMINTABLE,$DBhost) ) {
	setupadmin();
}

echo "<li><a href=\"admin.php\">Globale Einstellungen</a></li>";

 if (!table_exists(ITEMSTABLE, $DBhost)) {
	echo "<li><a href=\"uploaditems.php\">Itemtabelle importieren</a></li>";
} else {
	echo "<li><a href=\"uploaditems.php\">Itemtabelle importieren</a></li>";
	
	if (!table_exists(RESULTSTABLE, $DBhost)) {
		echo "<li><a href=\"index.php?action=createresulttab\">Ergebnistabelle erstellen</a></li>";
	} else {
		$danger_zone[] = "<li><a href=\"index.php?action=deleteresults\">Ergebnistabelle löschen</a></li>";
		echo "<li><a href=\"csv_export.php\">Ergebnistabelle exportieren</a></li>";
	}


	if (!table_exists(VPNDATATABLE, $DBhost)) {
		echo "<li><a href=\"index.php?action=createvpndatatab\">VPNDatatabelle erstellen</a></li>";
	} else {
		$danger_zone[] = "<li><a href=\"index.php?action=deletevpndatatab\">VPNDatatabelle löschen</a></li>";
	}

	if (table_exists(RESULTSTABLE, $DBhost)) {
		echo "<li><a href=\"displayresults.php\">Ergebnisse anzeigen</a></li>";
	}
	echo '<li class="nav-header">For more complex studies</li>';
	echo "<li><a href=\"editstudies.php\">Studien editieren</a></li>";
	echo "<li><a href=\"edittimes.php\">Edit-Zeiten editieren</a></li>";
	echo "<li><a href=\"editemails.php\">Emails editieren</a></li>";
	echo "<li><a href=\"editsubstitutions.php\">Subsitutionen editieren</a></li>";	

	echo "<li><a href=\"vpncodes.php\">Vpncodes bearbeiten</a></li>";
	echo "<li><a href=\"../acp/acp.php\">Zurück zur Studienübersicht</a></li>";
	
	echo '<li class="nav-header">Danger Zone</li>';
	echo implode($danger_zone);
}

echo "</ul></nav>";

// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');#

?>