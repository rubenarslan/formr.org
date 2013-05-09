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

<form class="form-horizontal" enctype="multipart/form-data"  id="add_study" name="add_study" method="post" action="<?=WEBROOT?>admin/<?=$study->name?>/study_added">
	<div class="control-group">
		<label class="control-label" for="kurzname">
			<?php echo _("Studien Kurzname<br>(wird für URL und Ergebnistabelle in der Datenbank benutzt):"); ?>
		</label>
		<div class="controls">
			<input type="hidden" name="study_id" value="<?=$study->id?>">
			
			<input required type="text" placeholder="Name (a-Z0-9_)" name="study_name" id="kurzname" value="<?=$study->name?>" readonly>
		</div>
	</div>
	<div class="control-group">
		<label class="control-label" for="file_upload">
				<?php echo _("Bitte Itemtabelle auswählen:"); ?>
		</label>
		<div class="controls">
			<input name="uploaded" type="file" id="file_upload">
		</div>
	</div>
	<div class="control-group">
		<div class="controls">
			<input required type="submit" value="<?php echo _("Studie anlegen"); ?>">
		</div>
	</div>
</form>


<?

require_once INCLUDE_ROOT.'view_footer.php';