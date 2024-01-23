<?php echo $prepend; ?>

<p>
    This unit creates a privacy policy, terms of service and imprint for your survey.
    Users have to accept the privacy policy and terms of service before they can continue the survey.
    Leave the terms of service empty if you don't want to use them.
    You can use Markdown to format the text.
    <code>{privacy-url}</code>, <code>{tos-url}</code> will be replaced with the respective URLs.
    Use them to link to the respective pages in the labels. (e.g. <code>[Privacy Policy]({privacy-url})</code>
</p>
<p>
    <label>Privacy Policy: <br/>
        <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown"
                  name="privacy" rows="10" cols="60" class="form-control"><?= h($privacy) ?></textarea>
    </label>
    <label>Accept Privacy Checkbox Label (e.g. <code>I have read and accept the [Privacy Policy]{privacy-url}</code>): <br/>
        <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown"
                  name="privacy_label" rows="10" cols="60" class="form-control"><?= h($privacy_label) ?></textarea>
    </label>
    <label>Terms of Service (leave empty if you don't want to use this): <br/>
        <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown"
                  name="tos" rows="10" cols="60" class="form-control"><?= h($tos) ?></textarea>
    </label>
    <label>Accept Terms of Service Checkbox Label (e.g. <code>I have read and accept the [Terms of Service]{tos-url}</code>): <br/>
        <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown"
                  name="tos_label" rows="10" cols="60" class="form-control"><?= h($tos_label) ?></textarea>
    </label>
    <label>Imprint: <br/>
        <textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown"
                  name="imprint" rows="10" cols="60" class="form-control"><?= h($imprint) ?></textarea>
    </label>
</p>

<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Privacy">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=Privacy">Test</a>
</p>