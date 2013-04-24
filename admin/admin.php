<?
require_once "includes/define_root.php";
require_once INCLUDE_ROOT.'admin/admin_header.php';
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein

require_once INCLUDE_ROOT.'includes/settings.php';
require_once INCLUDE_ROOT.'includes/variables.php';	
require_once INCLUDE_ROOT.'view_header.php';
?>
<h2><?php echo $study->name;?></h2>

<ul class="nav nav-tabs">
	<li class="active">
		<a href="<?=WEBROOT?>admin/index.php?study_id=<?php echo $study->id; ?>"><?php echo _("Admin Bereich"); ?></a>

		<ul class="nav nav-tabs">
			<li class="active"><a href="<?=WEBROOT?>admin/index.php?study_id=<?php echo $study->id; ?>"><?php echo _("Admin Bereich"); ?></a>
			</li>
			<li><a href="<?=WEBROOT?>acp/edit_study.php?id=<?php echo $study->id; ?>"><?php echo _("Veröffentlichung kontrollieren"); ?></a></li>
			<li><a href="<?=WEBROOT?>survey.php?study_id=<?php echo $study->id; ?>"><?php echo _("Studie testen"); ?></a></li>
			<li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Zurück zum ACP"); ?></a></li>	
		</ul>
	</li>
	<li><a href="<?=WEBROOT?>acp/edit_study.php?id=<?php echo $study->id; ?>"><?php echo _("Veröffentlichung kontrollieren"); ?></a></li>
	<li><a href="<?=WEBROOT?>survey.php?study_id=<?php echo $study->id; ?>"><?php echo _("Studie testen"); ?></a></li>
	<li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Zurück zum ACP"); ?></a></li>	
</ul>

<?php


if( !table_exists(ADMINTABLE) ) {
  setupadmin();
}

$query = "SELECT * FROM ".ADMINTABLE;
$results = mysql_query($query) or die( mysql_error() . "<br/>" . $query);
$result = array();

while($r = mysql_fetch_assoc( $results )) {
	$result[ $r['key'] ] = $r['value'];
}


if( mysql_num_rows($results) > 0 ) {

		
	if( isset($_POST['updateitems']) ) {	
		foreach($result as $key => $value) {
			$query = "UPDATE ".ADMINTABLE." SET `value`='".$_POST[$key]."' WHERE `key`='".$key."'";
			mysql_query( $query ) or die( mysql_error() . "<br/>" . $query);
		}
		
		$query = "SELECT * FROM ".ADMINTABLE;
		$results = mysql_query($query) or die( mysql_error() . "<br/>" . $query );	
		$result = array();

		while($r = mysql_fetch_assoc( $results )) {
			$result[ $r['key'] ] = $r['value'];
		}

	}
			
	echo "<p style=\"background: #CCCCCC;\"><strong>Globale Settings</strong></p>";
	echo "<form method=\"POST\" action=\"admin.php\"><table class=\"editstudies\">";
	echo "<th>Option</th> <th> - </th> <th>Value</th>";

	foreach( $result as $key => $value ) {
		echo "<tr>";
		echo "<td>".$key."</td>";
		echo "<td>-</td>";
		if(!is_bool($value))
			echo "<td><input type=\"text\" size=\"50\" name=\"".$key."\" value=\"".$value."\"/></td>";
		else
			echo "<td><input type=\"text\" size=\"50\" name=\"".$key."\" value=\"". $value?1:0 ."\"/></td>";
		echo "</tr>";
	}
	echo "</table><input type=\"submit\" name=\"updateitems\" value=\"Submit Changes\" /></form>";

} else {	
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	echo "something is really wrong with your setup! no admintable or data in it!!!";
}


// schließe main-div
echo "</div>\n";

// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require_once INCLUDE_ROOT.'view_footer.php';
