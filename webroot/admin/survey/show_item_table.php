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
	$row->type = implode(" ", array('<b>'.$row->type.'</b>', $row->choice_list, '<i>'.$row->type_options . '</i>'));
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
	        echo "<td>$cell</td>";
		endif;
	endforeach;

    echo "</tr>\n";
endforeach;

?>
</tbody></table>

<?php
endif;
?>
	</div>
</div>
<?php
require_once INCLUDE_ROOT.'View/footer.php';
