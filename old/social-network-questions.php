<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

include ('includes/social-network.php');


echo "<form action=\"heaven.php\" method=\"post\">";

hiddeninput("vpncode",getvpncode());

addSNPerson($vpncode,$allowedtypes,5,"snquestion");

echo "</form>";


// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
