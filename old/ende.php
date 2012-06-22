<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"
?>

<?


echo "<table width=\"" . SRVYTBLWIDTH . "\">";
echo "<tr class=\"bottomsubmit\"><td id=\"bottomsubmit\" colspan=\"2\">Vielen Dank für Ihre Teilnahme!</td></tr>";

$time = date("Y.m.d - H.i.s");
$check = mysql_query("SELECT endedsurveysmsintvar FROM ".RESULTSTABLE." WHERE vpncode='$vpncode'");
$existing_entry = mysql_fetch_assoc($check);
if ($existing_entry["endedsurveysmsintvar"]==NULL) {
	$query= "UPDATE ".RESULTSTABLE." SET endedsurveysmsintvar ='$time' WHERE vpncode='$vpncode'";
	mysql_query($query) or die ("Fehler bei " . $query . mysql_error() . "<br />");
}
mysql_free_result($check)


?>

</table>

<?
// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
