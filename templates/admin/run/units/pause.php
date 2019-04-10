<?php echo $prepend ?>

<p>
    <label class="inline hastooltip" title="Leave empty so that this does not apply"> wait until time: 
        <input style="width:200px" class="form-control" type="time" placeholder="e.g. 12:00" name="wait_until_time" value="<?= h($wait_until_time) ?>">
    </label>
    <strong> &nbsp; and</strong>

</p>
<p>
    <label class="inline hastooltip" title="Leave empty so that this does not apply"> wait until date: 
        <input style="width:200px" class="form-control" type="date" placeholder="e.g. 01.01.2000" name="wait_until_date" value="<?= h($wait_until_date) ?>">
    </label>
    <strong> &nbsp; and</strong>

</p>
<div style="display: inline-block; padding: 10px; background: #efefef; margin: 10px 0;">
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

<?php if ($type == 'Pause'): ?>
<p>
    <label>Text to show while waiting: <br />
        <textarea style="width:388px;"  data-editor="markdown" class="form-control col-md-5" placeholder="You can use Markdown" name="body" rows="10"><?= h($body) ?></textarea>
    </label>
</p>
<?php endif;?>

<?php if ($type == 'Wait'): ?>
<p>
    <label>If participant shows up before the waiting time expires, <br /> move to position &nbsp;
        <input type="number" class="form-control" style="width:70px" name="body" max="32000" step="1" value="<?= h($body) ?>">
        &nbsp; otherwise continue to next position.
    </label><br /><br/>
</p>
<?php endif;?>

<div class="clearfix clear"></div>
<p class="btn-group">
    <a class="btn btn-default xxx unit_save" href="ajax_save_run_unit?type=<?= $type ?>">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=<?= $type ?>">Test</a>
</p>
