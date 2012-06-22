<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

include ('includes/social-network.php');

$currentid = $_POST["currentid"];

snUpdatePostedRels($vpncode,$currentid);

echo "<form action=\"survey.php\" method=\"post\">";
hiddeninput("vpncode",$vpncode);
hiddeninput("SN",1);
echo "<table width=\"800\">
<tr class=\"instruktion\"><td id=\"instruktion\" colspan=\"2\">Vielen Dank für Ihre Angaben zu Ihren Kontaktpersonen. Die folgenden Fragen beziehen sich wieder nur auf Sie persönlich. Ihnen wird außerdem auch wieder angezeigt, wieviel Prozent des Fragebogens Sie schon beantwortet haben.</td></tr>

<tr class=\"bottomsubmit\"><td class=\"bottomsubmit\" colspan=\"2\"><input type=\"submit\" name=\"nextperson\" value=\"Weiter\"></td></tr></table>";
echo "</form>";

// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
