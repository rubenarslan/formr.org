<?
require_once '../define_root.php';
require_once INCLUDE_ROOT.'admin/admin_header.php';
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein

require_once INCLUDE_ROOT.'view_header.php';

require_once INCLUDE_ROOT.'admin/admin_nav.php';
?>

<div class="span8">

<p>The item table has to fulfill the following criteria:</p>

<ol>
<li>The format must be one of .csv, .xls, .xlsx, .ods (OpenOffice), .xml, .txt</li>
<li>.csv-files have to use the comma as a separator, "" as escape characters and UTF-8 as the charset. Because there are inconsistencies when creating CSV files using various spreadsheet programs (e.g. German excel), you should probably steer clear of this.</li>
<li>The first line has to contain the column names you used.</li>
<li>The following column names are used. You can add others, they will be ignored.<ul>
	<li><strong>variablenname</strong> (mandatory)</li>
	<li><strong>typ</strong> (mandatory)</li>
	<li>wortlaut</li>
	<li>antwortformatanzahl</li>
	<li>ratinguntererpol</li> 
	<li>ratingobererpol</li>
	<li>choice1, choice2,	choice3,	choice4,	choice5,	choice6,	choice7,	choice8,	choice9,	choice10, choice11,	choice12,	choice13,	choice14</li>
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
			<?php
				$res = ($resultCount['begun']+$resultCount['finished']);
				if($res>10) $class = 'btn-danger';
				elseif($res>0) $class = 'btn-warning';
				else $class = 'btn-success';
			?>
			<input class="btn <?=$class?>" required type="submit" value="<?php echo __("Studie anlegen und %d Ergebnisse überschreiben.", $res); ?>">
		</div>
	</div>
</form>


<?

require_once INCLUDE_ROOT.'view_footer.php';