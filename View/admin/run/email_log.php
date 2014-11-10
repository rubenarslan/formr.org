<?php
Template::load('header');
Template::load('acp_nav');
?>

<h2>email log <small>sent during runs</small></h2>

<?php if(!empty($emails)) { ?>
	<table class='table table-striped'>
		<thead><tr>
	<?php
	foreach(current($emails) AS $field => $value):
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		// printing table rows
		foreach($emails AS $row):
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
<?php
$pagination->render("admin/run/".$run->name."/email_log");

} else {
	echo "No emails sent yet.";
}
	?>

<?php Template::load('footer');