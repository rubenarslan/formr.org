<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$resultCount = $study->getResultCount();
$results = $study->getItemDisplayResults();
require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/admin_nav.php';
?>
<h2>Detailed result times <small>
		<?=(int)$resultCount['finished']?> complete,
		<?=(int)$resultCount['begun']?> begun
</small></h2>

<ul class="nav nav-pills nav-stacked span6">
	<li class="nav-header">
		Results
	</li>
	<li>
		<a href="<?=WEBROOT?>survey/<?=$study->name?>/show_results">Go back to results.</a>
	</li>
	<li class="nav-header">
		Export results as
	</li>
	<li>
		<a href="<?=WEBROOT?>survey/<?=$study->name?>/export_itemdisplay_tsv">TSV – tab-separated, human readable as plaintext</a>
	</li>
	<li>
		<a href="<?=WEBROOT?>survey/<?=$study->name?>/export_itemdisplay_xlsx">xlsX – new excel format, higher limits</a>
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
require_once INCLUDE_ROOT.'View/footer.php';
