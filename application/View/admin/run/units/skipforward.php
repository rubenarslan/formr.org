<?php echo $prepend ?>

<div class="padding-below">
    <label>if... <br>
        <textarea style="width:388px;"  data-editor="r" class="form-control col-md-5" name="condition" rows="4" placeholder="Condition: You can use R here: survey1$item2 == 2"><?= $condition ?></textarea>
    </label><br />

    

    <label>...skip forward to
        <input type="number" class="form-control" style="width:70px" name="if_true" max="32000" min="<?= ($position + 2) ?>" step="1" value="<?= h($ifTrue) ?>">
    </label><br /><br/> 
    
    <?php if (!$jump || !$goOn): ?>
    <b style="display: block; margin-bottom: 7px;">How should participant skip?</b>
    <strong>Skip &nbsp; </strong>
    <select style="width:120px" name="automatically_jump">
        <option value="1" <?= ($jump ? 'selected' : '') ?>> automatically </option>
        <option value="0" <?= ($jump ? '' : 'selected') ?>> if user reacts </option>
    </select>
    
    <strong> &nbsp; else continue  &nbsp; </strong>

    <select style="width:120px" name="automatically_go_on">
        <option value="1" <?= ($goOn ? 'selected' : '') ?>>automatically</option>
        <option value="0" <?= ($goOn ? '' : 'selected') ?>>if user reacts</option>
    </select>
    <?php endif; ?>
</div>
<div class="clear clearfix"></div>
<br />
<div class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipForward">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipForward">Test</a>
</div>
<p>&nbsp;</p>