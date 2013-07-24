<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';

if( !empty($_POST) ) {
	$study->editSubstitutions($_POST);
	redirect_to(WEBROOT . "survey/{$study->name}/edit_substitutions");
}

require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';

$subs = $study->getSubstitutions();
?>

<div class="span8">
	<h2><?=_('Study substitutions'); ?> <small><?=count($subs)?></small></h2>
	<form method="POST" action="<?=WEBROOT?>survey/<?=$study->name?>/edit_substitutions">
		<table class="table table-striped span6 editstudies">
			<thead>
				<tr>
					<th>Delete</th>
					<th>Search</th>
					<th>Replace with <code>Study.Field</code></th>
					<th>Mode</th>
				</tr>
			</thead>
			<tbody>
	<?php
		foreach( $subs as $sub ):
			echo "<tr>";
			echo '<td>
					<label title="Delete" class="btn-remove hastooltip">
						<i class="icon-remove-sign"></i>
						<input type="checkbox" name="'.h($sub['id']).'[delete]" value="1">
					</label>
				</div>
			</td>';
			echo '<td><input type="text" size="50" name="'.h($sub['id']).'[search]" value="'.h($sub['search']).'"></td>';
			echo '<td><input type="text" size="50" name="'.h($sub['id']).'[replace]" value="'.h($sub['replace']).'"></td>';
			echo '<td><input type="text" size="3" name="'.h($sub['id']).'[mode]" value="'.h($sub['mode']).'"></td>';
			echo "</tr>";
		endforeach;
		
	?>
	<tr>
	<td>&nbsp;&nbsp;&nbsp;<i class="icon-plus-sign"></i></td>
	<td><input type="text" size="50" name="new[search]" value="" placeholder="Search"></td>
	<td><input type="text" size="50" name="new[replace]" value="" placeholder="Replace"></td>
	<td><input type="text" size="50" name="new[mode]" value="" placeholder="Mode"></td>
	</tr>
	
			</tbody>
		</table>
		<div class="row span6">
			<input class="btn btn-success" type="submit" name="updateitems" value="Change substitutions">
		</div>
	</form>
</div>
<?php
require_once INCLUDE_ROOT.'View/footer.php';
