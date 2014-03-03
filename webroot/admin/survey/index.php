<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';


if( !empty($_POST) ) {
	$study->changeSettings($_POST);
	redirect_to(WEBROOT."admin/survey/{$study->name}/index");
}

require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/acp_nav.php';
?>

<div class="row">
	<div class="col-lg-7">
		<h2><?=_('Study settings'); ?></h2>
	
		<form method="POST" action="<?=WEBROOT?>admin/survey/<?=$study->name?>/index">
			<table class="table table-striped editstudies">
				<thead>
					<tr>
						<th>Option</th>
						<th>Value</th>
					</tr>
				</thead>
				<tbody>
		<?php
			foreach( $study->settings as $key => $value ):
				echo "<tr>";
				echo "<td>".h($key)."</td>";

				echo "<td><input class=\"form-control\" type=\"text\" size=\"50\" name=\"".h($key)."\" value=\"".h($value)."\"/></td>";
				echo "</tr>";
			endforeach;
		?>
				</tbody>
			</table>
			<div class="row col-md-4">
				<input type="submit" value="Save settings" class="btn">
			</div>
		</form>
	</div>
</div>
<?php
require_once INCLUDE_ROOT.'View/footer.php';
