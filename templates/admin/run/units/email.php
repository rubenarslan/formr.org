<?php echo $prepend ?>

<?php if (!empty($email_accounts)): ?>
    <div class="form-group">
        <label>Account</label>
        <select class="select2" name="account_id" style="width: 410px;">
            <option value=""></option>
            <?php
            foreach ($email_accounts as $acc):
                $selected = isset($account_id) && $account_id == $acc['id'];
                ?>
                <option value="<?= $acc['id'] ?>" <?= ($selected ? 'selected' : '') ?>><?= $acc['from'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <p></p>

    <div class="form-group">
        <label>Subject</label>
        <input class="form-control full_width" type="text" placeholder="Email subject" name="subject" value="<?= h($subject) ?>">
    </div>
    <div class="clear clearfix"></div>

    <div class="form-group">
        <label>Recipient</label>
        <input class="full_width select2recipient" type="text" placeholder="survey_users$email" name="recipient_field" value="<?= h($recipient_field) ?>" data-select2init="<?= htmlentities(json_encode($potentialRecipientFields, JSON_UNESCAPED_UNICODE)) ?>">
    </div>

    <div class="form-group">
        <label>Body</label>
        <textarea style="width: 388px;"  data-editor="markdown" placeholder="You can use Markdown" name="body" rows="7" cols="60" class="form-control"><?= h($body) ?></textarea>
        <br /><div class="clearfix"></div>
        <p><code>{{login_link}}</code> will be replaced by a personalised link to this run, <code>{{login_code}}</code> will be replaced with this user's session code.</p>
    </div>
    <div class="clear clearfix"></div>

    <div class="form-group">
        <label><input type="checkbox" name="cron_only" value="1" <?= ($cron_only ? ' checked ' : '') ?>> Send e-mails only when cron is running</label>
    </div>

    <div class="form-group btn-group">
        <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Email">Save</a>
        <a class="btn btn-default unit_test" href="ajax_test_unit?type=Email">Test</a>
    </div>

<?php else: ?>

    <h5>No email accounts. <a href="<?= admin_url('mail') ?>">Add some here.</a></h5>

<?php endif; ?>