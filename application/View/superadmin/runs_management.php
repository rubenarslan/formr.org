<?php
Template::load('header');
Template::load('acp_nav');
?>	
<h2>formr runs (<?= count($runs) ?>)</h2>

<?php if (!empty($runs)): ?>
<form method="post" action="" >
	<table class='table table-striped'>
		<thead>
			<tr>
				<th>ID</th>
				<th>Run Name</th>
				<th>User</th>
				<th>No. Sessions</th>
				<th>Cron Active</th>
				<th>Cron Forked</th>
				<th>Locked</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($runs as $row): ?>
			<tr>
				<td><?= $row['run_id'] ?></td>
				<td><?= $row['name'] ?></td>
				<td><?= $row['email'] ?></td>
				<td><?= $row['sessions'] ?></td>
				<td>
					<?php $checked = $row['cron_active'] ? 'checked="checked"' : null ?>
					<input type="checkbox" name="runs[<?=$row['run_id']?>][cron_active]" value="<?= $row['cron_active'] ?>" <?=$checked?> />
				</td>
				<td>
					<?php $checked = $row['cron_fork'] ? 'checked="checked"' : null ?>
					<input type="checkbox" name="runs[<?=$row['run_id']?>][cron_fork]" value="<?= $row['cron_fork'] ?>" <?=$checked?> />
				</td>
				<td>
					<?php $checked = $row['locked'] ? 'checked="checked"' : null ?>
					<input type="checkbox" name="runs[<?=$row['run_id']?>][locked]" value="<?= $row['locked'] ?>" <?=$checked?> />
				</td>
			</tr>
			<?php endforeach; ?>
			<tr>
				<td colspan="7">
					<button type="submit" class="btn btn-primary pull-right"><i class="fa fa-save"></i> Save Changes</button>
				</td>
			</tr>
		</tbody>
	</table>
</form>
<?php endif; ?>
</div>
</div>

<?php
Template::load('footer');
