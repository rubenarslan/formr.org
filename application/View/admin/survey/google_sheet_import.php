<div class="modal fade" id="google-import" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<form action="#" method="post">
				<input type="hidden" name="google_id" value="<?php echo array_val($params, 'id'); ?>" />
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
					<h4 class="modal-title">Import From Google Sheets</h4>
				</div>
				<div class="modal-body">
					<?php if (!empty($params['id'])): ?>
						<div class="alert alert-info">
							View sheet in <a href="<?php echo $params['link']; ?>" target="_blank">google docs</a>
						</div>
					<?php endif; ?>
					<div class="form-group">
						<label>Enter Survey Name</label>
						<input name="survey_name" class="form-control" placeholder="Survey Name" value="<?php echo array_val($params, 'name'); ?>" />
					</div>
					<div class="form-group">
						<label>Enter Google Share Link</label>
						<textarea name="google_sheet" class="form-control" placeholder="Google share link"><?php echo array_val($params, 'link'); ?></textarea>
						<i>Make sure this sheet is accessible by anyone with the link</i>
					</div>

				</div>
				<div class="modal-footer">
					<button type="submit" class="btn btn-default">Import</button>
				</div>
			</form>
		</div>
	</div>
</div>
