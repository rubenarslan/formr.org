<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

include ('includes/social-network.php');

snUpdatePostedVars($vpncode,$_POST["id"]);

echo "<form  action=\"sn-krgn.php\" method=\"post\">";

hiddeninput("vpncode",getvpncode());
hiddeninput("id",$_POST["id"]);
hiddeninput("stepcount",$_POST["stepcount"]);	

echo "<table width=\"800\"><tr class=\"even\"> <td id=\"instruktion\" colspan=\"2\">Bitte füllen sie alle Informationen vollständig aus. Leere Felder werden rot dargestellt. </td></tr></table>";

editSNPerson($vpncode,$_POST["id"],$allowedtypes);

echo "</form>";

// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
