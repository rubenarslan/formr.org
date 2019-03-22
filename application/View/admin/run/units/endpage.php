<?php echo $prepend; ?>

<p>
	<label>Feedback text: <br />
		<textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown" name="body" rows="10" cols="60" class="form-control"><?= h($body) ?></textarea>
	</label>
</p>

<p class="btn-group">
	<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Page">Save</a>
	<a class="btn btn-default unit_test" href="ajax_test_unit?type=Page">Test</a>
</p>