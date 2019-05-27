<div class="import-run-dialog">

    <div class="form-group">
        <label class="form-label">Choose an existing complex building block</label>
        <div class="control-group">
            <select class="form-control" name="run_file_name">
                <option value="">Select...</option>
                <?php foreach ($exports as $file => $run_name) : ?>
                    <option value="<?php echo $file; ?>" ><?php echo $run_name; ?></option>
                <?php endforeach; ?>
            </select>
            <input name="position" type="hidden" value="1" />
        </div>
    </div>

    <div class="form-group">
        <div class="control-group">
            <label class="form-label"><b>OR</b><br /> use your own or a colleague's exported JSON here and import any run</label>
            <div class="control-group">
                <i>Select a json file</i>
                <input type="file" name="run_file" placeholder="Select JSON file.." />
            </div>
        </div>
    </div>
</div>
