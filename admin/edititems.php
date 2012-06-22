<?
require ('admin_header.php');
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

updateitems();

echo "<table width=\"" . SRVYTBLWIDTH . "\">";

$query=" SELECT * FROM ".ITEMSTABLE;
$items=mysql_query($query);
// $items=mysql_fetch_array($result);
$num=mysql_numrows($items);

$i=0;
	while($row = mysql_fetch_assoc($items)) {
		printitemsforedit($allowedtypes, $row[id], $row[variablenname], $row[wortlaut], $row[altwortlautbasedon], $row[altwortlaut], $row[typ], $row[antwortformatanzahl], $row[ratinguntererpol], $row[ratingobererpol], $row[MCalt1], $row[MCalt2], $row[MCalt3], $row[MCalt4], $row[MCalt5]);
		$i++;
	}

echo "</table>";


// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>