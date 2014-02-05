<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$resultCount = $study->getResultCount();
$results = $study->getResults();
require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>
<div class="row">
	<div class="col-md-12">
		<div class="row">
			<div class="col-md-8">
				<h2>Results <small>
						<?=(int)$resultCount['finished']?> complete,
						<?=(int)$resultCount['begun']?> begun
				</small></h2>

				<ul class="nav nav-pills nav-stacked span6">
					<li class="nav-header">
						Details
					</li>
					<li>
						<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/show_itemdisplay"><i class="fa fa-table fa-fw"></i> Table of item display and answer times.</a>
					</li>
					<li class="nav-header">
						Export results as
					</li>
					<li>
						<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_csv"><i class="fa fa-floppy-o fa-fw"></i> CSV – good for big files, problematic to import into German Excel (comma-separated)</a>
					</li>
					<li>
						<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_csv_german"><i class="fa fa-floppy-o fa-fw"></i> CSV – good for German Excel (semicolon-separated)</a>
					</li>
					<li>
						<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_tsv"><i class="fa fa-floppy-o fa-fw"></i> TSV – tab-separated, human readable as plaintext</a>
					</li>
					<li>
						<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_xls"><i class="fa fa-floppy-o fa-fw"></i> XLS – old excel format, won't work with more than 16384 rows or 256 columns</a>
					</li>
					<li>
						<a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_xlsx"><i class="fa fa-floppy-o fa-fw"></i> xlsX – new excel format, higher limits</a>
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
#    $row = array_reverse($row, true);
	$row['created'] = '<abbr title="'.$row['created'].'">'.timetostr(strtotime($row['created'])).'</abbr>';
	$row['ended'] = '<abbr title="'.$row['ended'].'">'.timetostr(strtotime($row['ended'])).'</abbr>';
	$row['modified'] = '<abbr title="'.$row['modified'].'">'.timetostr(strtotime($row['modified'])).'</abbr>';
#    $row = array_reverse($row, true);
	
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
?>
</div>
</div>

<?php
require_once INCLUDE_ROOT.'View/footer.php';
