<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1> Surveys </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-9">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title" style="display: block"> Survey Listing</h3>
                        <div class="box-tools">
                            <a href="<?= admin_url('survey') ?>" class="btn btn-default"><i class="fa fa-plus-circle"></i> Add New</a>
                        </div>
                    </div>
                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <div class="table-responsive">
                            <table class="table no-margin">
                                <thead>
                                    <tr>
                                        <th># ID</th>
                                        <th>Name</th>
                                        <th>Created</th>
                                        <th>Modified</th>
                                        <th>Google Sheet</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studies as $d_study): ?>
                                        <tr>
                                            <td>#<?php echo $d_study['id']; ?></td>
                                            <td><a href="<?php echo admin_study_url($d_study['name']); ?>"><?php echo $d_study['name']; ?></a></td>
                                            <td><?php echo $d_study['created']; ?></td>
                                            <td><?php echo $d_study['modified']; ?></td>
                                            <td>
                                                <?php if ($d_study['google_file_id']): ?>
                                                    <a href="<?php echo google_get_sheet_link($d_study['google_file_id']); ?>" target="_blank"><?php echo substr($d_study['google_file_id'], 0, 8); ?>...<i class="fa fa-external-link-square"></i></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>

            </div>
            <div class="col-md-3">
                <div class="box box-primary">
                    <div class="box-body">
                        <h3 class="text-center"><a href="<?php echo site_url('documentation#sample_survey_sheet'); ?>" target="_blank"><i class="fa fa-book"></i> Survey Documentation</a></h3>
                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php
Template::loadChild('admin/run/run_modals', array('reminders' => array()));
Template::loadChild('admin/footer');
?>