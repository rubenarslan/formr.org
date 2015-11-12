<?php
Template::load('header', array('js' => '<script src="' . asset_url('assets/js/run_users.js') . '"></script>'));
Template::load('acp_nav');
?>	
<h2>formr users</h2>

<?php if (!empty($users)): ?>
	<table class='table table-striped'>
		<thead>
			<tr>
				<?php
				foreach (current($users) AS $field => $value):
					echo "<th>{$field}</th>";
				endforeach;
				?>
			</tr>
		</thead>
		<tbody>
			<?php
			// printing table rows
			foreach ($users AS $row):
				// $row is array... foreach( .. ) puts every element
				// of $row to $cell variable
				echo "<tr>";
				foreach ($row as $cell):
					echo "<td>$cell</td>";
				endforeach;
				echo "</tr>";
			endforeach;
			?>
		</tbody>
	</table>
	<?php $pagination->render("superadmin/user_management"); ?>

<?php endif; ?>
</div>
</div>

<script id="tpl-user-api" type="text/formr">
<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="UserAPI" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				<h3>API Access %{user}</h3>
			</div>
			<div class="modal-body">
				<table class="table table-striped table-bordered">
					<tr>
						<td><b>Client ID</b></td><td>%{client_id}</td>
					</tr>
					<tr>
						<td><b>Client Secret</b></td><td>%{client_secret}</td>
					</tr>
					<tr>
						<td><b>R command</b></td><td><pre><code class="r">formr_api_access_token("%{client_id}", "%{client_secret}")</code></pre></td>
					</tr>
				</table>
				<div class="clearfix"></div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-success api-create" aria-hidden="true">Create Credentials</button>
				<button class="btn btn-default api-change" aria-hidden="true">Change Secret</button>
				<button class="btn btn-danger api-delete" aria-hidden="true">Revoke Credentials</button>
				<button class="btn api-close" data-dismiss="modal" aria-hidden="true">Close</button>
			</div>
        </div>
    </div>
</div>
</script>
<script type="text/javascript">
	var saAjaxUrl = <?php echo json_encode(site_url('superadmin/ajax_admin')); ?>
</script>

<?php
Template::load('footer');
