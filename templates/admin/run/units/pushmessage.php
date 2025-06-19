<?php echo $prepend ?>
<p>
    <label title="You can use R markdown code with `r survey$variable`" class="hastooltip">Title: <br />
        <input type="text" class="form-control" placeholder="Optional title for message categorization" name="topic" value="<?= h($topic) ?>"  style="width: 388px;" >
    </label>
</p>

<p>
    <label title="You can use R markdown code with `r survey$variable`" class="hastooltip">Message: <br />
        <textarea data-editor="markdown" class="form-control" rows="4"  style="width: 415px;" 
                  placeholder="You can use R markdown code with `r survey$variable`" name="message"><?= h($message) ?></textarea>
    </label>
</p>

<div style="display: inline-block; padding: 10px; background: #efefef; margin: 10px 0; width: 388px;">

    <label title="How important is this notification? Devices will try to show urgent notifications first." class="hastooltip">Priority: <br />
        <select name="priority" class="form-control">
            <?php foreach ($priority_options as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $value === $priority ? 'selected' : '' ?>>
                    <?= h($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label title="When does the notification expire?" class="hastooltip">Time to Live: <br />
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
    
    <label title="Optional number for app badge" class="hastooltip">Badge Count: <br />
        <input type="number" class="form-control" style="width:230px" 
               placeholder="Optional number for app badge" name="badge_count" 
               value="<?= h($badge_count) ?>" min="0">
    </label>
    <br>
    <label title="Sound the notification should make">Notification Mode: <br />
        <select name="notification_mode" class="form-control">
            <option value="sound_vibration" <?= (!$silent && $vibrate) ? 'selected' : '' ?>>Sound + Vibration</option>
            <option value="sound_only" <?= (!$silent && !$vibrate) ? 'selected' : '' ?>>Sound Only</option>
            <option value="silent" <?= $silent ? 'selected' : '' ?>>Silent</option>
        </select>
    </label> 

    <label class="checkbox hastooltip" title="Require user interaction to dismiss">
        <input type="checkbox" name="require_interaction" value="1" <?= $require_interaction ? 'checked="checked"' : '' ?>>
        <i class="fa fa-hand-pointer-o"></i>
    </label>
    <label class="checkbox hastooltip" title="Show new notification even if one exists">
        <input type="checkbox" name="renotify" value="1" <?= $renotify ? 'checked="checked"' : '' ?>>
        <i class="fa fa-bell"></i>
    </label>

</div>

<div class="clearfix clear"></div>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=PushMessage">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=PushMessage">Test</a>
</p>