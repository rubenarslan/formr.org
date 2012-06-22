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
<li>.csv-Datei</li>
<li>Semicolon (;) als Feldtrenner</li>
<li>Anführungszeichen (") als Textmarkierungen</li>
<li>In der ersten Zeile müssen die Variablennamen stehen (die erste Zeile wird  nicht übernommen!)</li>
<li>textkodierung muss UTF-8 sein</li>
<li>genau 14 (mögliche) Multiple-Choice-Alternativen</li>
</ol>
</td></tr>

<tr class="odd"><td>
<form enctype="multipart/form-data" action="uploadimport.php" method="POST">
Bitte Datei auswählen:<br /><input name="uploaded" type="file" /><br /><br />
<input type="submit" value="Upload & Import" />
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
