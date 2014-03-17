<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$results = $study->getItemsWithChoices();
require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>
<div class="row">
	<div class="col-md-12">
		<h2>Item table <small>currently active</small></h2>


<?php
if(count($results)>0):
?>
	<h4><a href="#download">Download item table</a></h4>
<table class='table table-striped'>
	<thead><tr>
<?php
function empty_column($col,$arr)
{
	$empty = true;
	$last = null;
	foreach($arr AS $row):
		if(!(empty($row->$col)) OR // not empty column? (also treats 0 and empty strings as empty)
		$last != $row->$col OR // any variation in this column?
		!(!is_array($row->$col) AND trim($row->$col)=='')
		):
			$empty = false;
			break;
		endif;
		$last = $row->$col;
	endforeach;
	
	return $empty;
}
$use_columns = $empty_columns = array();
$display_columns = array('type','name','label_parsed','optional','class','showif','choices','value','order');
foreach(current($results) AS $field => $value):
	
	if(in_array($field,$display_columns) AND !empty_column($field,$results)):
		array_push($use_columns,$field);
	    echo "<th>{$field}</th>";
	endif;
		
endforeach;
?>
	</tr></thead>
<tbody>

<?php
// printing table rows
$open = false;
foreach($results AS $row):
    echo "<tr>";

    // $row is array... foreach( .. ) puts every element
    // of $row to $cell variable
	$row->type = implode(" ", array('<b>'.$row->type.'</b>', ($row->choice_list == $row->name)?'': $row->choice_list, '<i>'.$row->type_options . '</i>'));
    foreach($use_columns AS $field):
		$cell = $row->$field;
		
		if(strtolower($field) == 'choices'):

			echo '<td>';
			if($cell!=='' AND $cell!==NULL):
				echo '<ol>';
				$open = true;
			endif;
			
			foreach($cell AS $name => $label):
				if($label!=='' AND $label!==NULL):
					
			        echo "<li title='$name' class='hastooltip'>$label</li>";
				endif;
			endforeach;
		
			if($open):
				echo '</ol>';
				$open = false;
			endif;
			echo '</td>';
		
			continue;
		
		else:
			if($field == 'label_parsed' AND $cell === null) $cell = $row->label;
			if(($field == 'value' OR $field == 'showif') AND $cell != '') $cell = "<pre><code class='r'>$cell</code></pre>";
	        echo "<td>$cell</td>";
		endif;
	endforeach;

    echo "</tr>\n";
endforeach;

?>
</tbody></table>
		<div class="row" id="download">
		
			<div class="list-group col-md-7">
				<h5>Export as</h5>
			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_item_table?format=xls"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
			    <p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
			  </div>

			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_item_table?format=xlsx"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
			    <p class="list-group-item-text">new excel format, higher limits</p>
			  </div>

			  <div class="list-group-item">
			    <h4 class="list-group-item-heading"><a href="<?=WEBROOT?>admin/survey/<?=$study->name?>/export_item_table?format=json"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
			    <p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use <pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON("/path/to/exported_file.json"))</code></pre></p>
			  </div>
			</div>
		</div>
<?php
endif;
?>
	</div>
</div>


<?php
require_once INCLUDE_ROOT.'View/footer.php';
