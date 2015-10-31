<?php
	Template::load('header', array('js' => '<script src="'.  asset_url('assets/js/run_users.js').'"></script>'));
    Template::load('acp_nav');
?>
<h2>formr user activity</h2>

	<?php
	
	if(!empty($users)):
		?>
		<table class='table table-striped'>
			<thead><tr>
		<?php
		foreach(current($users) AS $field => $value):
			    echo "<th>{$field}</th>";
		endforeach;
		?>
			</tr></thead>
		<tbody>
			<?php
			// printing table rows
			foreach($users AS $row):
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
	$pagination->render("superadmin/active_users");
	
	endif;
	?>
	</div>
</div>

<?php
Template::load('footer');