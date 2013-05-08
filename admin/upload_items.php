<?
require_once '../define_root.php';
require_once INCLUDE_ROOT.'admin/admin_header.php';
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein

require_once INCLUDE_ROOT.'view_header.php';

require_once INCLUDE_ROOT.'admin/admin_nav.php';
?>

<div class="span8">

<p>Die Datei muss folgenden Kriterien genügen:</p>

<ol>
<li>Datei muss entweder .csv, .xls, .xlsx, .ods (OpenOffice), .xml oder .txt sein.</li>
<li>.csv-Dateien brauchen Komma als Feldtrenner, "" als Feldmarkierung und UTF-8 als Textkodierung. Besser nicht mehr benutzen.</li>
<li>In der ersten Zeile müssen die Variablennamen stehen (die erste Zeile wird  nicht übernommen!)</li>
<li>Folgende Spalten können vorkommen (* Pflicht): <ul>
	<li>variablenname*</li>
	<li>typ*</li>
	<li>wortlaut</li>
	<li>altwortlautbasedon</li>
	<li>altwortlaut</li>
	<li>antwortformatanzahl</li>
	<li>ratinguntererpol</li> 
	<li>ratingobererpol</li>
	<li>MCalt1, MCalt2,	MCalt3,	MCalt4,	MCalt5,	MCalt6,	MCalt7,	MCalt8,	MCalt9,	MCalt10, MCalt11,	MCalt12,	MCalt13,	MCalt14</li>
	<li>skipif</li>
</ul>
</li>
</ol>

<form enctype="multipart/form-data" action="<?=WEBROOT?>admin/study_added.php" method="POST">
	<input type="hidden" name="study_id" value="<?=$study->id ?>">
	<input type="hidden" name="study_name" value="<?=$study->name ?>">
Bitte Datei auswählen:<br /><input name="uploaded" type="file" /><br /><br />
<input type="submit" class="btn btn-success" value="Upload &amp; Import" />
</form> 
</div>


<?

require_once INCLUDE_ROOT.'view_footer.php';