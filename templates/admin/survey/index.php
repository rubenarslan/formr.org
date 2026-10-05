<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">

    <section class="content-header">
        <h1><?= $study->name ?> <?= study_engine_badge($study) ?> <small>Survey ID: <?= $survey_id ?></small></h1>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::loadChild('admin/survey/menu'); ?>
            </div>

            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Survey Shortcuts</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-md-4">
                                <a href="<?= admin_study_url($study->name, 'show_item_table?to=download') ?>" class="dashboard-link">
                                    <span class="icon"><i class="fa fa-download"></i></span>
                                    <span class="text">Download Items</span>
                                </a>
                            </div>

                            <div class="col-md-4">
                                <a href="<?= admin_study_url($study->name, 'show_item_table?to=show') ?>" class="dashboard-link">
                                    <span class="icon"><i class="fa fa-th"></i></span>
                                    <span class="text">Show Items</span>
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="<?= admin_study_url($study->name, 'upload_items') ?>" class="dashboard-link">
                                    <span class="icon"><i class="fa fa-upload"></i></span>
                                    <span class="text">Upload Items</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php /* Above the settings form, not in it: an alert may carry a form of
                       its own ("Switch to v1 anyway"), which a form inside a form loses. */ ?>
                    <?php Template::loadChild('public/alerts'); ?>
                    <form role="form" method="post" action="<?php echo admin_study_url($study->name); ?>">
                        <div class="box-header with-border">
                            <h3 class="box-title">Survey Settings</h3>
                        </div>

                        <div class="box-body">
                            <div class="callout callout-info">
                                <p>These are some settings for advanced users. You'll mostly need the "Import items" and the "Export results" options to the left.</p>
                            </div>


                            <table class="table editstudies">
                                <tr>
                                    <td>
                                        <label>Items Per Page</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> Do you want a certain number of items on each page? We prefer specifying pages manually (by adding submit buttons items when we want a pagebreaks) because this gives us greater manual control
                                            <?php if ($study->rendering_mode === 'v2'): ?><br><strong class="text-muted">Not used in v2: pages end at submit items.</strong><?php endif; ?>
                                        </span>
                                        <span class="col-md-6 nlp" style="padding-left: 0px;">
                                            <input type="number" class="form-control" name="maximum_number_displayed" value="<?= h($study->maximum_number_displayed) ?>" min="0" />
                                        </span>


                                    </td>
                                    <td>
                                        <label>Enable Instant Validation</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> Instant validation means that users will be alerted if their survey input is invalid right after entering their information. Otherwise, validation messages will only be shown once the user tries to submit.
                                            <?php if ($study->rendering_mode === 'v2'): ?><br><strong class="text-muted">Not used in v2: answers are checked when the participant moves to the next page.</strong><?php endif; ?>
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="enable_instant_validation" value="1" <?php if ($study->enable_instant_validation) echo 'checked="checked"'; ?>> <strong>Enable</strong> </label>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="2">
                                        <label>Percentage Display</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> Sometimes, in complex studies where several surveys are linked, you'll want to let the progress bar that the user sees only vary in a given range (e.g. first survey 0-40, second survey 40-100).
                                        </span>
                                        <div class="form-group " style="padding-left: 0px;">
                                            <div class="input-group">
                                                <div class="input-group-addon"> from </div>
                                                <input type="number" class="form-control" name="add_percentage_points" value="<?= h($study->add_percentage_points) ?>" min="0" max="100" />
                                                <div class="input-group-addon"> to</div>
                                                <input type="number" class="form-control" name="displayed_percentage_maximum" value="<?= h($study->displayed_percentage_maximum) ?>" min="0" max="100" />	<div class="input-group-addon"> %</div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        <label>Survey Unlinking</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> 
                                            Unlinking a survey means that the results will only be shown in random order, without session codes and dates and only after a minimum of 10 results are in. This is meant as a way to anonymise personally identifiable data and separate it from the survey data that you will analyze.
                                            <strong class="text-red">You can't change this settings once you select this option.</strong>
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="unlinked" value="1" <?php if ($study->unlinked) echo 'checked="checked"'; ?>> <strong>Unlink Survey</strong> </label>
                                        </div>
                                    </td>
                                    <td>
                                        <label>Disable Results Display</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> Selecting this option will disable displaying the data of this survey in formr. However the data will still be available for use.
                                            <strong class="text-red">You can't change this settings once you select this option.</strong>
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="hide_results" value="1" <?php if ($study->hide_results) echo 'checked="checked"'; ?>> <strong>Disable</strong> </label>
                                        </div>
                                        <input type="hidden" class="form-control" name="google_file_id" value="<?= h($study->google_file_id) ?>" />
                                    </td>
                                </tr>
                                <tr><td colspan="2"><h4>Survey access window</h4></td></tr>
                                <tr>
                                    <td colspan="2">
                                        <label>Access window</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            How big should the access window be for your survey? Here, you define the time a user can start the survey (usually after receiving an email invitation). By setting the second value to a value other than zero, you are saying that the user has to finish with the survey x minutes after the access window closed.<br>
                                            The sum of these values is the maximum time someone can spend on this unit, giving you more predictability than the snooze button (see below). To allow a user to keep editing indefinitely, set the finishing time and inactivity expiration to 0. If inactivity expiration is also set, a survey can expire before the end of the finish time.
                                            <a href="https://github.com/rubenarslan/formr.org/wiki/Expiry">More information</a>.
                                        </span>
                                        <div class="form-group " style="padding-left: 0px;">
                                            <div class="input-group">
                                                <div class="input-group-addon"> Start editing within </div>
                                                <input type="number" class="form-control" name="expire_invitation_after" value="<?= h($study->expire_invitation_after) ?>" min="0" max="3153600" size="20" />
                                                <div class="input-group-addon"> minutes</div>
                                                <div class="input-group-addon"> finishing editing within </div>
                                                <input type="number" class="form-control" name="expire_invitation_grace" value="<?= h($study->expire_invitation_grace) ?>" min="0" max="3153600" size="20" />
                                                <div class="input-group-addon"> minutes after the access window closed</div>
                                            </div>

                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <label>Inactivity Expiration (snooze)</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> If a user is inactive in the survey for x minutes, should the survey expire? Specify <b>0 </b>if not. If a user inactive for x minutes, the run will automatically move on. If the invitation is still valid (see above), this value doesn't count. Beware: much like with the snooze button on your alarm clock, a user can theoretically snooze indefinitely.
                                        </span>
                                        <div class="form-group col-md-3 nlp" style="padding-left: 0px;">
                                            <div class="input-group">
                                                <input type="number" class="form-control" name="expire_after" value="<?= h($study->expire_after) ?>" min="0" max="3153600" size="20" />
                                                <div class="input-group-addon"> Minutes</div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr><td colspan="2"><h4>Survey Paging</h4></td></tr>
                                <tr>
                                    <td colspan="2">
                                        <label>Custom Paging</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            By enabling custom dynamic paging, your survey items will be "grouped" in pages depending on how your <i>Submit Items</i> are defined in the items sheet. That is, each page ends at a defined submit button.
                                            Enabling this option nullifies the above "<i><b>Items Per Page</b></i>" setting, which means the number of items on a page will be determined by where <i>Submit Items</i> are placed in your <a href="<?php echo site_url('documentation#sample_survey_sheet'); ?>">items sheet</a>.
                                            <strong class="text-red">You can't change this settings once you select this option.</strong>
                                            <?php if ($study->rendering_mode === 'v2'): ?><br><strong class="text-muted">Not used in v2: pages always end at submit items.</strong><?php endif; ?>
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="use_paging" value="1" <?php if ($study->use_paging) echo 'checked="checked"'; ?>> <strong>Enable Paging</strong> </label>
                                        </div>
                                    </td>
                                </tr>
                                <?php if ($study->rendering_mode === 'v2'): ?>
                                <tr><td colspan="2"><h4>v2 settings</h4></td></tr>
                                <tr>
                                    <td>
                                        <label>Offline queue</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            If a participant loses their connection, the page they submitted is kept on their device and sent automatically once they are back online. Turn this off if answers must not be stored on the participant's device, even briefly. Participants then see an error when they submit while offline.
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="offline_mode" value="1" <?php if ($study->offline_mode) echo 'checked="checked"'; ?>> <strong>Enable offline queue</strong> </label>
                                        </div>
                                    </td>
                                    <td>
                                        <label>Allow "Previous" button</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            Lets participants go back to earlier pages and change their answers. Off by default.
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="allow_previous" value="1" <?php if ($study->allow_previous) echo 'checked="checked"'; ?>> <strong>Show "Previous" button</strong> </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <label>Layout</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            <strong>Default</strong> shows all items of a page together. <strong>Solo</strong> shows one item at a time and moves on when it is answered, which works well on phones. Answers are still saved page by page, at your submit items.
                                        </span>
                                        <select name="layout" class="form-control" style="max-width: 22em;">
                                            <option value="default" <?php if ($study->layout !== 'solo') echo 'selected'; ?>>Default — multiple items per page</option>
                                            <option value="solo" <?php if ($study->layout === 'solo') echo 'selected'; ?>>Solo — one item per screen</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <label>Participant language</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            A language code such as <code>en</code> (English), <code>de</code> (German) or <code>de-AT</code> (Austrian German). formr translates its own texts (buttons, messages, error hints) into English and German; for other languages they stay in English. Your item labels are shown as you wrote them. The code also tells browsers and screen readers which language the page is in.
                                        </span>
                                        <input type="text" class="form-control" name="language" value="<?= h($study->language) ?>" list="fmr-language-suggestions" style="max-width: 10em;" maxlength="35" pattern="[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*">
                                        <datalist id="fmr-language-suggestions">
                                            <option value="en">English</option>
                                            <option value="de">Deutsch</option>
                                        </datalist>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <label>Button labels</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            Your own wording for the page navigation. Leave empty for the built-in label in the participant language (<em><?= h(FormStrings::get($study->language, 'nav.next')) ?></em> / <em><?= h(FormStrings::get($study->language, 'nav.submit')) ?></em>). A <code>submit</code> item in your sheet still labels its own page's button.
                                        </span>
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <input type="text" class="form-control" name="next_button_label" value="<?= h((string) $study->next_button_label) ?>" maxlength="100" placeholder="<?= h(FormStrings::get($study->language, 'nav.next')) ?>" aria-label="Next button label">
                                                <small class="text-muted">Next page</small>
                                            </div>
                                            <div class="col-sm-6">
                                                <input type="text" class="form-control" name="submit_button_label" value="<?= h((string) $study->submit_button_label) ?>" maxlength="100" placeholder="<?= h(FormStrings::get($study->language, 'nav.submit')) ?>" aria-label="Submit button label">
                                                <small class="text-muted">Last page</small>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <label>Compatibility scan</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i>
                                            Checks your <code>showif</code>s and <code>value</code>s for anything v2 cannot run, and tells you how to fix it. Run it before participants start.
                                        </span>
                                        <a href="<?= admin_study_url($study->name, 'form_v2_compat_scan') ?>" class="btn btn-default"><i class="fa fa-search"></i> Check v2 compatibility</a>
                                    </td>
                                </tr>
                                <?php endif; ?>

                            </table>


                            <div class="clearfix"></div>

                        </div>
                        <!-- /.box-body -->

                        <div class="box-footer">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Settings</button>
                        </div>
                    </form>

                    <?php
                    // Rendering engine switcher. Own <form> (POST to
                    // switch_rendering_mode) — must not nest inside the
                    // settings form above. The compatibility scan runs on its own
                    // report page, on demand — not on every view of this page.
                    // Shown for a v1 study only where form_v2 authoring is
                    // enabled: elsewhere there is nothing to switch to.
                    $fmrShowEngineBox = $study->rendering_mode === 'v2' || Config::get('form_v2_enabled');
                    ?>
                    <?php if ($fmrShowEngineBox): ?>
                    <div class="box box-solid">
                        <div class="box-header"><h3 class="box-title"><i class="fa fa-wpforms"></i> Survey engine: v1 or v2</h3>
                            <a href="<?= site_url('documentation#form_v2_enable') ?>" target="_blank" class="pull-right hastooltip" title="What changes with v2, and how to move a survey to it"><i class="fa fa-question-circle"></i> v2 guide</a>
                        </div>
                        <div class="box-body">
                            <?php if ($study->rendering_mode === 'v2'): ?>
                                <p>This survey runs on <strong>v2</strong>. Participants see it in a Form unit.</p>
                                <form method="post" action="<?= admin_study_url($study->name, 'switch_rendering_mode') ?>" style="display:inline">
                                    <input type="hidden" name="target" value="v1">
                                    <?php /* data-confirm, not onsubmit: the admin CSP blocks inline handlers (admin-ui.js). */ ?>
                                    <button type="submit" class="btn btn-default" data-confirm="Switch this survey back to v1? Every run that shows it in a Form unit will show it as a classic Survey instead. You can switch again at any time.&#10;&#10;Pages that participants submitted while offline and have not sent yet will be lost."><i class="fa fa-history"></i> Switch back to v1</button>
                                </form>
                            <?php else: ?>
                                <p>This survey runs on the classic <strong>v1</strong> engine. In v2, answers are saved as participants go, items show and hide instantly as they answer, a lost connection does not lose a page, and there is a one-item-at-a-time layout for phones.</p>
                                <p>Some surveys need changes first, mostly their <code>showif</code>s. The <a href="<?= admin_study_url($study->name, 'form_v2_compat_scan') ?>">compatibility check</a> lists what to change.</p>
                                <form method="post" action="<?= admin_study_url($study->name, 'switch_rendering_mode') ?>" style="display:inline">
                                    <input type="hidden" name="target" value="v2">
                                    <button type="submit" class="btn btn-primary" data-confirm="Move this survey to v2? This only works while no run uses it as a classic Survey. You can switch back at any time."><i class="fa fa-rocket"></i> Move to v2</button>
                                </form>
                                <a href="<?= admin_study_url($study->name, 'form_v2_compat_scan') ?>" class="btn btn-default"><i class="fa fa-search"></i> Check v2 compatibility</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="clear clearfix"></div>
        </div>

    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php
Template::loadChild('admin/footer');
