<?php
require_once "../includes/define_root.php";
require_once INCLUDE_ROOT.'admin/admin_header.php';

$resultCount = $study->getResultCount();
$results = $study->getResults();
require_once INCLUDE_ROOT.'view_header.php';

require_once INCLUDE_ROOT.'admin/admin_nav.php';
?>
<h2>Results <small>
		<?=(int)$resultCount['finished']?> complete,
		<?=(int)$resultCount['begun']?> begun
</small></h2>

<ul class="nav nav-pills nav-stacked span6">
	<li class="nav-header">
		Export results as
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_csv">CSV – good for big files, problematic to import into German Excel (comma-separated)</a>
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_csv_german">CSV – good for German Excel (semicolon-separated)</a>
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_tsv">TSV – tab-separated, human readable as plaintext</a>
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_xls">XLS – old excel format, won't work with more than 16384 rows or 256 columns</a>
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/<?=$study->name?>/export_xlsx">xlsX – new excel format, higher limits</a>
	</li>
		
</ul>
<?php
if(count($results)>0):
?>
<div class="span12">
	
<table class='table table-striped'>
	<thead><tr>
<?php
foreach(current($results) AS $field => $value):
    echo "<th>{$field}</th>";
endforeach;
?>
	</tr></thead>
<tbody>

<?php
// printing table rows
foreach($results AS $row):
    echo "<tr>";

    // $row is array... foreach( .. ) puts every element
    // of $row to $cell variable
    foreach($row as $cell):
        echo "<td>$cell</td>";
	endforeach;

    echo "</tr>\n";
endforeach;

?>
</tbody></table>

</div>

<?php
endif;
require_once INCLUDE_ROOT.'view_footer.php';
