<!-- Run templates needed for javascript -->
<script id="tpl-export-units" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ExportUnits" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
				<form id="export_run_units" method="post">
                <div class="modal-header" style="padding: 0px 15px;">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>Run Export</h3>
                </div>
                <div class="modal-body ">
					<div class="row">
						<div class="col-md-12">
							<div class="form-group run_export_before_alert" style="padding-left: 15px;">
								<h4>Enter a name for your export and select run units</h4>
								<input class="form-control" placeholder="Name Export (a to Z, 0 to 9, _ and spaces)" name="export_name" value="%{run_name}" style="width: 80%;" />
								<small><i>Name should contain only alpha-numeric characters, a hyphen and no spaces. It needs to start with a letter.</i></small>
							</div>
							<input type="hidden" name="units">
						</div>
					</div>
					<div class="row">
						<div class="col-md-6">
							<div class="form-group checkbox">
								<label>Export Format </label>
								<select name="format">
									<option value="json">JSON</option>
								</select>
							</div>
						</div>
						<div class="col-md-6">
							<div class="form-group checkbox">
								<label><input type="checkbox" name="include_survey_details" value="true" checked> Include survey items </label>
							</div>
						</div>
						<div class="clearfix"></div>
					</div>
                    <div class="row"><div class="col-md-12">%{export_html}</div></div>
                </div>
            <div class="modal-footer">
                <button class="btn btn-success confirm-export" aria-hidden="true" type="submit">Export</button>
                <button class="btn cancel-export" data-dismiss="modal" aria-hidden="true">Close</button>
            </div>
			</form>
        </div>
    </div>
</script>
<script id="tpl-test-modal" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="FormR.org Modal" aria-hidden="true">
        <div class="modal-dialog">                         
            <div class="modal-content">                              
                <div class="modal-header">                                 
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>                                 
                    <h3>%{header}</h3>                             
                </div>                             
                <div class="modal-body">%{body}</div>
                <div class="modal-footer">                             
                    <button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>                         
                </div>                     
            </div>                 
        </div>
    </div>
</script>
<script id="tpl-export-unit-block" type="text/formr">
<div class="form-group run-export-unit-block">
	<div class="select btn btn-default col-sm-1" data-position="%{unit_pos}" data-selected="1">
		<i class="fa fa-check fa-2x"></i>
	</div>
	<div class="col-sm-11">
		<pre><code class="hljs json">%{unit_json}</code></pre>
	</div>
	<div class="clearfix"></fix>
</div>
</script>
<script id="tpl-import-units" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ImportUnits" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
				<form action="<?php echo !empty($run->name) ? admin_run_url($run->name, 'import') : ''; ?>" enctype="multipart/form-data" method="post">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>JSON import of modules</h3>
                </div>
                <div class="modal-body">
                    <div>%{content}</div>
					<div class="clearfix"></div>
                </div>
				<div class="modal-footer">
					<button class="btn btn-success confirm-import" aria-hidden="true" type="submit">Import</button>
					<button class="btn cancel-import" data-dismiss="modal" aria-hidden="true" type="b">Close</button>
				</div>
			</form>
        </div>
    </div>
</script>
<script id="tpl-confirmation" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ImportUnits" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>Confirm</h3>
                </div>
                <div class="modal-body">
                    <div>%{content}</div>
					<div class="clearfix"></div>
                </div>
				<div class="modal-footer">
					<button data-yes="%{yes_url}" class="btn btn-success btn-yes" aria-hidden="true" type="button">Yes</button>
					<button data-no="%{no_url}" class="btn btn-no" data-dismiss="modal" aria-hidden="true" type="button">No</button>
				</div>
			</div>
        </div>
    </div>
</script>

<script id="tpl-delete-run-session" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="DeleteUser" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
				<form action="%{action}" enctype="multipart/form-data" method="post">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>Delete Run Session</h3>
                </div>
                <div class="modal-body">
                    <div>
						Are you sure you want to delete this run session and all it's data? <br />
						<pre>%{session}</pre>
				    </div>
					<div class="clearfix"></div>
                </div>
				<div class="modal-footer">
					<button class="btn btn-danger" aria-hidden="true" type="submit">Delete</button>
					<button class="btn cancel" data-dismiss="modal" aria-hidden="true" type="button">Cancel</button>
				</div>
			</form>
        </div>
    </div>
</script>
<script id="tpl-remind-run-session" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="DeleteUser" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>Send Reminder</h3>
                </div>
                <div class="modal-body">
                    <table class="table table-stripped">
						<thead>
							<tr><th>ID</th><th>Description</th><th>Select Reminder</th><th>Sent</th></tr>
						</thead>
						<tbody>
						<?php foreach ($reminders as $r): ?>
							<tr>
								<td><?= $r['unit_id'] ?></td>
								<td><?= $r['description'] ?></td>
								<td><a href="javascript:void(0);" data-reminder="<?= $r['unit_id'] ?>" class="send btn btn-default"><i class="fa fa-paper-plane"></i> send</a></td>
								<td class="reminder-row-count reminder-row-count-<?= $r['unit_id'] ?>"></td>
							</tr>
						<?php endforeach ?>
						</tbody>
					</table>
					<div class="clearfix"></div>
                </div>
        </div>
    </div>
</script>
