<?
require_once '../../../define_root.php';
require_once INCLUDE_ROOT.'View/admin_header.php';
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein

if( !empty($_POST) ) {
	$study->changeSettings($_POST);
	redirect_to(WEBROOT."survey/{$study->name}/index");
}

require_once INCLUDE_ROOT.'View/header.php';

require_once INCLUDE_ROOT.'View/admin_nav.php';
?>

<div class="span8">
	<h2><?=_('Study settings'); ?></h2>
	<form id="edit_form" name="edit_form" method="post" action="<?=WEBROOT?>survey/<?=$study->name?>/index" enctype="multipart/form-data" class="form-horizontal">
	
<div class="control-group">
	<p class="control-label">
		<label for="logo">Logo Upload (gif/jpg up to 1MB)</label>
	</p>
	<p class="controls">
		<input type="file" name="logo" id="logo"/>
	</p>                    
</div>

<?php /*
<div class="control-group">
	<p class="control-label">
		<?= _("Require"); ?></label>
	</p>
	<p class="controls">
		<label for="registered"><input type="checkbox" name="registered" id="registered" <?php if($study->registration_required) echo "checked";?>> registration</label><br>
		<label><input type="checkbox" name="email_required" id="email_required" <?php if($study->email_required) echo "checked";?>>  email</label><br>
		<label><input type="checkbox" name="birthday_required" id="birthday_required" <?php if($study->birthday_required) echo "checked";?>> a birthday</label>
	</p>
</div>


<div class="control-group">
	<p class="control-label">
		<label for="public"><?= _("Publish this study to the front page"); ?></label>
	</p>
	<p class="controls">
		<input type="checkbox" name="public" id="public" <?php if($study->public) echo "checked"; ?>>
	</p>
</div>
*/	
	?>

<div class="control-group">
	<p class="controls">
		<button type="submit" class="btn"><?php echo _("Save"); ?></button>
	</p>
</div>
	</form>

	
  	<h3><?=_('other settings'); ?></h3>
	
	<form method="POST" action="<?=WEBROOT?>survey/<?=$study->name?>/index">
		<table class="table table-striped span6 editstudies">
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

			echo "<td><input type=\"text\" size=\"50\" name=\"".h($key)."\" value=\"".h($value)."\"/></td>";
			echo "</tr>";
		endforeach;
	?>
			</tbody>
		</table>
		<div class="row span6">
			<input type="submit" name="updateitems" value="Submit Changes" class="btn">
		</div>
	</form>
</div>
<?php
require_once INCLUDE_ROOT.'View/footer.php';
