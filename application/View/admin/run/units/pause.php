<?php echo $prepend ?>

<p>
    <label class="inline hastooltip" title="Leave empty so that this does not apply"> wait until time: 
        <input style="width:200px" class="form-control" type="time" placeholder="e.g. 12:00" name="wait_until_time" value="<?= h($wait_until_time) ?>">
    </label>
    <strong>and</strong>

</p>
<p>
    <label class="inline hastooltip" title="Leave empty so that this does not apply"> wait until date: 
        <input style="width:200px" class="form-control" type="date" placeholder="e.g. 01.01.2000" name="wait_until_date" value="<?= h($wait_until_date) ?>">
    </label>
    <strong>and</strong>

</p>
<div class="well well-sm">
    <span class="input-group">
        <input class="form-control" type="number" style="width:230px" placeholder="wait this many minutes" name="wait_minutes" value="<?= h($wait_minutes) ?>">
        <span class="input-group-btn">
            <button class="btn btn-default from_days hastooltip" title="Enter a number of days and press this button to convert them to minutes (*60*24)"><small>convert days</small></button>
        </span>
    </span>
    <br />
    <label>relative to <br />
        <textarea data-editor="r" style="width:368px;" rows="2" class="form-control" placeholder="arriving at this pause" name="relative_to"><?= h($relative_to) ?></textarea>
    </label>
</div> 

<p>
    <label>Text to show while waiting: <br />
        <textarea style="width:388px;"  data-editor="markdown" class="form-control col-md-5" placeholder="You can use Markdown" name="body" rows="10"><?= h($body) ?></textarea>
    </label>
</p>

<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Pause">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=Pause">Test</a>
</p>