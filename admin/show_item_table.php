<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT.'admin/admin_header.php';

$results = $study->getItems();
require_once INCLUDE_ROOT.'view_header.php';

require_once INCLUDE_ROOT.'admin/admin_nav.php';
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
		if(trim($row[$col]) != ''):
			$empty = false;
			break;
		endif;
	endforeach;
	
	return $empty;
}
$empty_columns = array();

foreach(current($results) AS $field => $value):
	if( in_array($field, array('id','study_id')))
		continue;
	
	if(empty_column($field,$results)):
		array_push($empty_columns,$field);
		continue;
	endif;
	
	if( !isset($choices) AND strtolower(substr($field,0,5)) === 'mcalt')
	{
		$choices = true;
		$field = 'choices';
	} elseif(strtolower(substr($field,0,5)) === 'mcalt') continue;
	
		
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
    foreach($row as $field => $cell):
		if( in_array($field, array('id','study_id'))
			OR in_array($field, $empty_columns))
			continue;
		
		if(strtolower(substr($field,0,5)) == 'mcalt')
		{
			if(strtolower(substr($field,5))==1)
				echo '<td><ol>';
			
			if($cell!='')
		        echo "<li>$cell</li>";
		
			if(strtolower(substr($field,5))==14)
				echo '</ol></td>';
		
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
require_once INCLUDE_ROOT.'view_footer.php';
