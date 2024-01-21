<?php echo $prepend; ?>

<p>
    <label>Privacy Statement: <br/>
        <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown" name="body" rows="10"
                  cols="60" class="form-control"><?= h($body) ?></textarea>
    </label>
    <label>Accept Button Label: <br/>
        <textarea style="width:388px;"
                  placeholder="i.e. 'I have read and agree to the Privacy Policy' (You can NOT use Markdown)"
                  name="label" rows="10" cols="60" class="form-control"><?= h($label) ?></textarea>
    </label>
</p>

<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Privacy">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=Privacy">Test</a>
</p>