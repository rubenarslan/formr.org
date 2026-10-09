<?php echo $prepend ?>

<p>
    <label>if… <br>
        <textarea style="width:388px;" data-editor="r" class="form-control col-md-5" name="condition" rows="4" placeholder="Condition: You can use R here: survey1$item2 == 2"><?= $condition ?></textarea>
    </label>
</p>
<div class="row col-md-12">
    <label>…skip backward to
        <input type="number" class="form-control" style="width:100px" name="if_true" max="<?= ($position - 1) ?>" min="-32000" step="1" value="<?= h($ifTrue) ?>">
    </label>

</div>

<p><small class="text-muted"><strong>Advanced:</strong> if your R code returns a position number of <strong>2 or higher</strong> instead of TRUE/FALSE, the participant jumps straight to that position. Consult the documentation for more details. A unit created before formr v1.10.3 has its condition wrapped in <code>as.logical({ … })</code>, which keeps the TRUE/FALSE reading it always had (any non-zero number counts as TRUE, a past date TRUE, a future date FALSE); remove the wrapper to return a position.</small></p>

<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipBackward">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipBackward">Test</a>
</p>