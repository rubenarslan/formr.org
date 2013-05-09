<?php
$resultCount = $study->getResultCount();
?>
<h1><?php echo $study->name;?> <small><?= ($resultCount['begun']+$resultCount['finished'])?> results</small></h1>

<ul class="nav nav-tabs">
	<li class="active">
		<a href="<?=WEBROOT?>admin/<?php echo $study->name; ?>/index"><?php echo _("Admin Area"); ?></a>
	</li>

	<li>
		<a href="<?=WEBROOT?>admin/<?php echo $study->name; ?>/access"><?php echo _("Test study"); ?></a>
	</li>
	<li>
		<a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Back to ACP"); ?></a>
	</li>
</ul>

<nav class="span2">
	<ul class="nav nav-pills nav-stacked">

<li>
	<a href="<?=WEBROOT?>admin/<?=$study->name?>/index"><i class="icon-caret-right"></i> Global settings</a>
</li>
<li>
	<a href="<?=WEBROOT?>admin/<?=$study->name?>/upload_items"><i class="icon-caret-right"></i> Import item table</a>
</li>

<li>
	<a href="<?=WEBROOT?>admin/<?=$study->name?>/show_item_table"><i class="icon-caret-right"></i> View item table</a>
</li>

<li>
	<a href="<?=WEBROOT?>admin/<?=$study->name?>/show_results"><i class="icon-caret-right"></i> Show results</a>
</li>

<li class="dropdown">
    <a class="dropdown-toggle"
       data-toggle="dropdown"
       href="#">
        <i class="icon-caret-right"></i> Export results
        <b class="caret"></b>
      </a>
    <ul class="dropdown-menu">
		<li>
			
			<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_csv"><i class="icon-caret-down"></i> Download CSV</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_csv_german"><i class="icon-caret-down"></i> Download German CSV</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_tsv"><i class="icon-caret-down"></i> Download TSV</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_xls"><i class="icon-caret-down"></i> Download XLS</a>
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_xlsx"><i class="icon-caret-down"></i> Download XLSX</a>
		</li>
		
    </ul>
  </li>

<li class="nav-header">complex studies</li>

<li>
	<li><a href="<?=WEBROOT?>admin/<?=$study->name?>/edit_substitutions"><i class="icon-caret-right"></i> Edit substitutions</a>
</li>

<li class="nav-header">Danger Zone</li>

<li>
	<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/<?=$study->name?>/delete_study"><i class="icon-caret-right"></i> Delete study</a>
</li>

<li>
	<a class="hastooltip" title="Go to deletion dialog, does not delete yet" href="<?=WEBROOT?>admin/<?=$study->name?>/delete_results"><i class="icon-caret-right"></i> Delete <?= ($resultCount['begun']+$resultCount['finished'])?> results</a>
	
</li>

</ul>

</nav>

<?php 
$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '<div class="span8 all-alerts">';
	echo $alerts;
	echo '</div>';
endif;
?>