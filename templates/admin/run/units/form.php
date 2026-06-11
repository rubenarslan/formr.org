<?php echo $prepend; ?>

<div class="alert alert-info" role="alert" style="margin-bottom: 1em;">
    <i class="fa fa-wpforms"></i>
    <strong>form_v2 (beta):</strong>
    This unit renders with the new form engine. Phase 0 delegates to the legacy renderer,
    so behaviour is identical to a Survey for now. See <code>plan_form_v2.md</code>.
</div>

<?php if ($studies): ?>

    <div class="form-group">
        <select class="select2" name="study_id" style="width:300px">
            <option value=""></option>
            <?php
            foreach ($studies as $study):
                $study = (object) $study;
                $selected = ($survey && $survey->id == $study->id) ? 'selected = "selected"' : '';
                ?>
                <option value="<?= $study->id ?>" <?= $selected ?>><?= $study->name ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($survey && $survey->id): ?>
            <p>
                <?= (int) $resultCount['finished'] ?> complete results,
                <?= (int) $resultCount['begun'] ?> begun <abbr class="hastooltip" title="Median duration participants needed to complete the survey">(in ~ <?= $time ?>m)</abbr>
            </p>
            <?php if (!empty($expirationSettings)): ?>
                <p class="text-muted">
                    <strong>Form expiration:</strong> <?= h(implode(' | ', $expirationSettings)) ?>
                </p>
            <?php endif; ?>
            <p class="btn-group">
                <?php if (!empty($survey->google_file_id) && (int) array_val($surveyResultCount ?? [], 'real_users', 0) === 0): ?>
                    <a class="btn btn-default unit_update_survey_from_google"
                       href="ajax_update_survey_from_google"
                       title="Update this form from the google sheet">Update form</a>
                <?php else: ?>
                    <a class="btn btn-default" href="<?= admin_study_url($survey->name, 'upload_items') ?>" title="Upload items">Update</a>
                <?php endif; ?>
                <a class="btn btn-default" href="<?= admin_study_url($survey->name, 'show_item_table?to=show') ?>">Items</a>
                <a class="btn btn-default" href="<?= admin_study_url($survey->name, 'show_results') ?>">Results</a>
            </p>
            <div class="survey-update-alerts"></div>
            <br />
            <p class="btn-group">
                <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Form">Save</a>
                <a title="Test this form with this button for a quick look. Unless you need a quick look, you should prefer to use the 'Test run' function to test the form in the context of the run." class="btn btn-default" target="_blank" href="<?= admin_study_url($survey->name, 'access') ?>">Test</a>
            </p>
        <?php else: ?>
            <p>
            <div class="btn-group">
                <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Form">Save</a>
            </div>
        </p>
    <?php endif; ?>

    </div>

<?php else: ?>

    <h5>No studies. <a href="<?= admin_study_url() ?>">Add some first</a></h5>

<?php endif; ?>
