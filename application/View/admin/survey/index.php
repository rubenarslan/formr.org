<?php Template::load('admin/header'); ?>

<div class="content-wrapper">

    <section class="content-header">
        <h1><?= $study->name ?> <small>Survey ID: <?= $survey_id ?></small></h1>
    </section>

    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::load('admin/survey/menu'); ?>
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
                    <form role="form" method="post" action="<?php echo admin_study_url($study->name); ?>">
                        <div class="box-header with-border">
                            <h3 class="box-title">Survey Settings</h3>
                        </div>

                        <div class="box-body">
                            <?php Template::load('public/alerts'); ?>

                            <div class="callout callout-info">
                                <p>These are some settings for advanced users. You'll mostly need the "Import items" and the "Export results" options to the left.</p>
                            </div>


                            <table class="table editstudies">
                                <tr>
                                    <td>
                                        <label>Items Per Page</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> Do you want a certain number of items on each page? We prefer speciyfing pages manually (by adding submit buttons items when we want a pagebreaks) because this gives us greater manual control
                                        </span>
                                        <span class="col-md-6 nlp" style="padding-left: 0px;">
                                            <input type="number" class="form-control" name="maximum_number_displayed" value="<?= h($study->settings['maximum_number_displayed']) ?>" min="0" />
                                        </span>


                                    </td>
                                    <td>
                                        <label>Enable Instant Validation</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> Instant validation means that users will be alerted if their survey input is invalid right after entering their information. Otherwise, validation messages will only be shown once the user tries to submit.
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="enable_instant_validation" value="1" <?php if ($study->settings['enable_instant_validation']) echo 'checked="checked"'; ?>> <strong>Enable</strong> </label>
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
                                                <input type="number" class="form-control" name="add_percentage_points" value="<?= h($study->settings['add_percentage_points']) ?>" min="0" max="100" />
                                                <div class="input-group-addon"> to</div>
                                                <input type="number" class="form-control" name="displayed_percentage_maximum" value="<?= h($study->settings['displayed_percentage_maximum']) ?>" min="0" max="100" />	<div class="input-group-addon"> %</div>
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
                                            <label> <input type="checkbox" name="unlinked" value="1" <?php if ($study->settings['unlinked']) echo 'checked="checked"'; ?>> <strong>Unlink Survey</strong> </label>
                                        </div>
                                    </td>
                                    <td>
                                        <label>Disable Results Display</label>
                                        <span class="help-block">
                                            <i class="fa fa-info-circle"></i> Selecting this option will disable displaying the data of this survey in formr. However the data will still be available for use.
                                            <strong class="text-red">You can't change this settings once you select this option.</strong>
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="hide_results" value="1" <?php if ($study->settings['hide_results']) echo 'checked="checked"'; ?>> <strong>Disable</strong> </label>
                                        </div>
                                        <input type="hidden" class="form-control" name="google_file_id" value="<?= h($study->settings['google_file_id']) ?>" />
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
                                                <input type="number" class="form-control" name="expire_invitation_after" value="<?= h($study->settings['expire_invitation_after']) ?>" min="0" max="3153600" size="20" />
                                                <div class="input-group-addon"> minutes</div>
                                                <div class="input-group-addon"> finishing editing within </div>
                                                <input type="number" class="form-control" name="expire_invitation_grace" value="<?= h($study->settings['expire_invitation_grace']) ?>" min="0" max="3153600" size="20" />
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
                                                <input type="number" class="form-control" name="expire_after" value="<?= h($study->settings['expire_after']) ?>" min="0" max="3153600" size="20" />
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
                                        </span>
                                        <div class="checkbox">
                                            <label> <input type="checkbox" name="use_paging" value="1" <?php if ($study->settings['use_paging']) echo 'checked="checked"'; ?>> <strong>Enable Paging</strong> </label>
                                        </div>
                                    </td>
                                </tr>

                            </table>


                            <div class="clearfix"></div>

                        </div>
                        <!-- /.box-body -->

                        <div class="box-footer">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Settings</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="clear clearfix"></div>
        </div>

    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php
Template::load('admin/footer');
