<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

$results = $study->getItemsWithChoices();
require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>
<h2>Item table <small>currently active</small></h2>


<?php
if(count($results)>0):
?>
<div class="span12">
	
<table class='table table-striped'>
	<thead><tr>
<?php
function empty_column($col,$arr)
{
	$empty = true;
	foreach($arr AS $row):
		if(empty($row->col) OR trim($row->$col) != ''):
			$empty = false;
			break;
		endif;
	endforeach;
	
	return $empty;
}
$empty_columns = array();
$display_cols = array('type','name','label','optional','class','skipif','choices');

foreach(current($results) AS $field => $value):
	
	if(empty_column($field,$results) OR !in_array($field,$display_cols)):
		array_push($empty_columns,$field);
		continue;
	endif;
		
    echo "<th>{$field}</th>";
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
    foreach($display_cols AS $field):
		$cell = $row->$field;
		if( in_array($field, $empty_columns))
			continue;
		
		
		if(strtolower($field) == 'choices')
		{
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
		}

		
		
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
