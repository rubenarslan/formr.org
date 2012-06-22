<?
require ('admin_header.php');
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

echo "<table width=\"" . SRVYTBLWIDTH . "\">";

?>

<tr class="adminmessage"><td>
Die Datei muss folgenden Kriterien genügen:
<ol>
<li>Datei muss entweder .csv, .xls (Excel, aber nicht .xlsx, das neue Format), .ods (OpenOffice), .xml oder .txt sein.</li>
<li>.csv-Dateien brauchen Komma als Feldtrenner, "" als Feldmarkierung und UTF-8 als Textkodierung. Besser nicht mehr benutzen.</li>
<li>In der ersten Zeile müssen die Variablennamen stehen (die erste Zeile wird  nicht übernommen!)</li>
<li>genau 14 (mögliche) Multiple-Choice-Alternativen</li>
<li>Folgende Spalten, in der Reihenfolge: <ul>
	<li> variablenname</li>
	<li>wortlaut</li>
	<li>altwortlautbasedon</li>
	<li>altwortlaut</li>
	<li>typ</li>
	<li>antwortformatanzahl</li>
	<li>ratinguntererpol</li>
	<li>ratingobererpol</li>
	<li>MCalt1, MCalt2,	MCalt3,	MCalt4,	MCalt5,	MCalt6,	MCalt7,	MCalt8,	MCalt9,	MCalt10, MCalt11,	MCalt12,	MCalt13,	MCalt14</li>
	<li>Teil</li>
	<li>relevant</li>
	<li>skipif</li>
	<li>special</li>
	<li>rand</li>
	<li>study</li>
	</ul>
</li>
</ol>
</td></tr>

<tr class="odd"><td>
<form enctype="multipart/form-data" action="uploadimport.php" method="POST">
Bitte Datei auswählen:<br /><input name="uploaded" type="file" /><br /><br />
<input type="submit" value="Upload &amp; Import" />
</form> 

</td></tr>
</table>

<?
// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
