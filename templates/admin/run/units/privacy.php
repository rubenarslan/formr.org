<?php echo $prepend; ?>

<?php if ($run->hasPrivacy() || $run->hasToS()): ?>
    <p>
        This unit creates a privacy policy and terms of service consent.
        Users have to accept the privacy policy and terms of service before they can continue the survey.
        Set the privacy policy, terms of service and imprint in the <a
                href="<?= admin_run_url($run->name) . '/settings#privacy' ?>">Privacy Settings</a>.
        You can use Markdown to format the text.
        <code>{privacy-url}</code> and <code>{tos-url}</code> will be replaced with the respective URLs.
        Use them to link to the respective pages in the labels. (e.g. <code>[Privacy Policy]({privacy-url})</code>
    </p>
    <p>
        <label>Privacy Policy Consent Label (e.g. <code>I have read and accept the [Privacy
                Policy]({privacy-url})</code>): <br/>
            <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown"
                      name="privacy_label" rows="10" cols="60" class="form-control"><?= h($privacy_label) ?></textarea>
        </label>
        <label>Terms of Service Consent Label (e.g. <code>I have read and accept the [Terms of
                Service]({tos-url})</code>): <br/>
            <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown"
                      name="tos_label" rows="10" cols="60" class="form-control"><?= h($tos_label) ?></textarea>
        </label>
    </p>

    <p class="btn-group">
        <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Privacy">Save</a>
        <a class="btn btn-default unit_test" href="ajax_test_unit?type=Privacy">Test</a>
    </p>
<?php else: ?>
    <p>
        No privacy policy or terms of service set.
        Set them first in the <a href="<?= admin_run_url($run->name) . '/settings#privacy' ?>">Privacy Settings</a>.
    </p>
<?php endif; ?>
