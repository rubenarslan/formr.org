<div class="import-run-dialog">
	
	<div class="form-group">
		<label class="form-label">Select from existing exports</label>
		<div class="control-group">
			<select class="form-control">
				<option>Select</option>
				<?php foreach ($exports as $i => $export) : ?>
					<option value="<?php echo $i; ?>" data-content='<?php echo h(json_encode($export)); ?>'><?php echo $export->name; ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	
	<div class="form-group">
		<div class="control-group">
			<label class="form-label">Enter JSON import</label>
			<div class="control-group">
				<textarea name="json" class="form-control col-md-12 code-txt" placeholder="Paste valid run JSON here.." style="min-height: 150px;"></textarea>
			</div>
		</div>
	</div>

	<!-- create hidden templates with json content to minimize requests for selected export -->
	<?php foreach ($exports as $i => $export) : ?>
	<script type=text/formr id="<?php echo 'selected-run-export-'.$i; ?>">
		<?php echo json_encode($export); ?>
	</script>
	<?php endforeach; ?>
</div>
