<?
require_once "../includes/define_root.php";
require_once INCLUDE_ROOT.'admin/admin_header.php';
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein

require_once INCLUDE_ROOT.'includes/settings.php';
require_once INCLUDE_ROOT.'includes/variables.php';	
require_once INCLUDE_ROOT.'view_header.php';

?>
<h2><?php echo $study->name;?></h2>

<ul class="nav nav-tabs">
	<li class="active"><a href="<?=WEBROOT?>admin/index.php?study_id=<?php echo $study->id; ?>"><?php echo _("Admin Bereich"); ?></a></li>
	<li><a href="<?=WEBROOT?>acp/edit_study.php?id=<?php echo $study->id; ?>"><?php echo _("Veröffentlichung kontrollieren"); ?></a></li>
	<li><a href="<?=WEBROOT?>survey.php?study_id=<?php echo $study->id; ?>"><?php echo _("Studie testen"); ?></a></li>
	<li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Zurück zum ACP"); ?></a></li>	
</ul>

<?php
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

echo "<li><a href=\"uploaditems.php?study_id=".$study->id."\">Itemtabelle importieren</a></li>";
 if (!table_exists(ITEMSTABLE, $DBhost)) {
} else {
	
	if (!table_exists(RESULTSTABLE, $DBhost)) {
		echo "<li><a href=\"index.php?action=createresulttab\">Ergebnistabelle erstellen</a></li>";
	} else {
		$danger_zone[] = "<li><a href=\"index.php?action=deleteresults&study_id=".$study->id."\">Ergebnistabelle löschen</a></li>";
		echo "<li><a href=\"csv_export.php?study_id=".$study->id."\">Ergebnistabelle exportieren</a></li>";
	}


	if (!table_exists(VPNDATATABLE, $DBhost)) {
		echo "<li><a href=\"index.php?action=createvpndatatab&study_id=".$study->id."\">VPNDatatabelle erstellen</a></li>";
	} else {
		$danger_zone[] = "<li><a href=\"index.php?action=deletevpndatatab&study_id=".$study->id."\">VPNDatatabelle löschen</a></li>";
	}

	if (table_exists(RESULTSTABLE, $DBhost)) {
		echo "<li><a href=\"displayresults.php?study_id=".$study->id."\">Ergebnisse anzeigen</a></li>";
	}
	echo '<li class="nav-header">For more complex studies</li>';
	echo "<li><a href=\"editstudies.php?study_id=".$study->id."\">Studien editieren</a></li>";
	echo "<li><a href=\"edittimes.php?study_id=".$study->id."\">Edit-Zeiten editieren</a></li>";
	echo "<li><a href=\"editemails.php?study_id=".$study->id."\">Emails editieren</a></li>";
	echo "<li><a href=\"editsubstitutions.php?study_id=".$study->id."\">Subsitutionen editieren</a></li>";	

	echo "<li><a href=\"vpncodes.php?study_id=".$study->id."\">Vpncodes bearbeiten</a></li>";
	echo "<li><a href=\"../acp/acp.php\">Zurück zur Studienübersicht</a></li>";
	
	echo '<li class="nav-header">Danger Zone</li>';
	echo implode($danger_zone);
}

echo "</ul></nav>";

// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require_once INCLUDE_ROOT.'view_footer.php';
