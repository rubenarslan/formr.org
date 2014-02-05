<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$resultCount = $study->getResultCount();
$results = $study->getItemDisplayResults();
require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>
<div class="row">
<div class="col-md-4">
<h2>Detailed result times <small>
		<?=(int)$resultCount['finished']?> complete,
		<?=(int)$resultCount['begun']?> begun
</small></h2>

<ul class="nav nav-pills nav-stacked span6">
	<li class="nav-header">
		Results
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/show_results"><i class="fa fa-tasks fa-fw"></i> Go back to main results.</a>
	</li>
	<li class="nav-header">
		Export detailed result times as
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_itemdisplay_tsv"><i class="fa fa-floppy-o fa-fw"></i>TSV – tab-separated, human readable as plaintext</a>
	</li>
	<li>
		<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_itemdisplay_xlsx"><i class="fa fa-floppy-o fa-fw"></i> xlsX – new excel format, higher limits</a>
	</li>
		
</ul>
</div>
</div>
<?php
if(count($results)>0):
?>
<div class="col-md-12">
	
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
	$row['created'] = '<abbr title="'.$row['created'].'">'.timetostr(strtotime($row['created'])).'</abbr>';
	$row['modified'] = '<abbr title="'.$row['modified'].'">'.timetostr(strtotime($row['modified'])).'</abbr>';
	
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
