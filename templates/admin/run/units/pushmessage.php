<?php echo $prepend ?>

<p>
    <label>Message: <br />
        <textarea style="width:388px;" data-editor="markdown" class="form-control" rows="4" 
                  placeholder="You can use Markdown and R code with {{ }}" name="message"><?= h($message) ?></textarea>
    </label>
</p>

<p>
    <label>Topic: <br />
        <input type="text" class="form-control" style="width:388px;" 
               placeholder="Optional topic for message categorization" name="topic" value="<?= h($topic) ?>">
    </label>
</p>

<p>
    <label>Priority: <br />
        <select name="priority" class="form-control" style="width:388px;">
            <?php foreach ($priority_options as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $value === $priority ? 'selected' : '' ?>>
                    <?= h($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</p>

<div style="display: inline-block; padding: 10px; background: #efefef; margin: 10px 0;">
    <label>Time to Live: <br />
        <span class="input-group">
            <input type="number" class="form-control" style="width:230px" 
                   placeholder="Seconds (default: 86400)" name="time_to_live" 
                   value="<?= h($time_to_live) ?>" min="0">
            <span class="input-group-btn">
                <button class="btn btn-default from_hours hastooltip" 
                        title="Enter number of hours and press to convert to seconds (*3600)">
                    <small>convert hours</small>
                </button>
            </span>
        </span>
    </label>
    
    <br />
    
    <label>Badge Count: <br />
        <input type="number" class="form-control" style="width:230px" 
               placeholder="Optional number for app badge" name="badge_count" 
               value="<?= h($badge_count) ?>" min="0">
    </label>
</div>

<p>
	Options: 
    <label class="checkbox hastooltip" title="Vibrate when delivering notification">
        <input type="checkbox" name="vibrate" value="1" <?= $vibrate ? 'checked="checked"' : '' ?>>
        <i class="fa fa-mobile"></i>
    </label>
    <label class="checkbox hastooltip" title="Require user interaction to dismiss">
        <input type="checkbox" name="require_interaction" value="1" <?= $require_interaction ? 'checked="checked"' : '' ?>>
        <i class="fa fa-hand-pointer-o"></i>
    </label>
    <label class="checkbox hastooltip" title="Show new notification even if one exists">
        <input type="checkbox" name="renotify" value="1" <?= $renotify ? 'checked="checked"' : '' ?>>
        <i class="fa fa-bell"></i>
    </label>
    <label class="checkbox hastooltip" title="Deliver silently without interruption">
        <input type="checkbox" name="silent" value="1" <?= $silent ? 'checked="checked"' : '' ?>>
        <i class="fa fa-bell-slash-o"></i>
    </label>
</p>

<div class="clearfix clear"></div>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=PushMessage">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=PushMessage">Test</a>
</p>