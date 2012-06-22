<?
require ('admin_header.php');
/* 	admin.php - Editor to add string subsitutions in $formulierung texts
June, 2010 - remember the great wheather!
 */

// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

if( !table_exists(ADMINTABLE) ) {
  setupadmin();
}

$query = "SELECT * FROM ".ADMINTABLE;
$results = mysql_query($query) or die( mysql_error() . "<br/>" . $query);
$result = array();

while($r = mysql_fetch_assoc( $results )) {
	$result[ $r['key'] ] = $r['value'];
}


if( mysql_numrows($results) > 0 ) {

		
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
			
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	echo "<p style=\"background: #CCCCCC;\"><strong>Globale Settings</strong></p>";
	echo "<form method=\"POST\" action=\"admin.php\"><table class=\"editstudies\">";
	echo "<th>Option</th> <th> - </th> <th>Value</th>";

	foreach( $result as $key => $value ) {
		echo "<tr>";
		echo "<td>".$key."</td>";
		echo "<td>-</td>";
		echo "<td><input type=\"text\" size=\"50\" name=\"".$key."\" value=\"".$value."\"/></td>";
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
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
