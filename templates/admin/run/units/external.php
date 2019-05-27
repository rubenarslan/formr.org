<?php echo $prepend; ?>

<p>
    <label>
        External link: <br />
        <textarea style="width:388px;"  data-editor="r" class="form-control full_width" rows="2" type="text" name="external_link"><?= h($address) ?></textarea>
    </label>
</p>

<p>
    <input type="hidden" name="api_end" value="0" />
    <label><input type="checkbox" name="api_end" value="1" <?= ($api_end ? ' checked ' : '') ?>> end using <abbr class="initialism" title="Application programming interface. Better not check this if you don\'t know what it means">API</abbr></label>
</p>
<p>
    <label>Expire after <input type="number" style="width:80px" name="expire_after" class="form-control" value="<?= $expire_after ?>"> minutes</label>
</p>
<p>
    Enter a URL like <code>http://example.org?code={{login_code}}</code> and the user will be sent to that URL, 
    replacing <code>{{login_code}}</code> with that user's code. <br />
    Enter R-code to e.g. send more data along:<br/> <code>paste0('http:example.org?code={{login_link}}&age=', demographics$age)</code>.
</p>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=External">Save</a>
    <a class="btn btn-default unit_test" href="ajax_test_unit?type=External">Test</a>
</p>
