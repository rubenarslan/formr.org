<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1><?php echo $run->name; ?> <small><a target="_blank" title="The official link to your run, which you can share with prospective users." href="<?php echo run_url($run->name, null, null) ?>"><?php echo run_url($run->name, null, null) ?></a></small> </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::loadChild('admin/run/menu'); ?>
            </div>
            <div class="col-md-7">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title" style="display: block">
                            Edit Run 
                            <a href="javascript:void(0);" class="btn btn-danger btn-panic pull-right" id="btn-panic" title="Don't panic! Click this button for an explanation.">I am panicking :-(</a>
                        </h3>
                    </div>
                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <form class="form-horizontal edit_run form-inline" enctype="multipart/form-data" name="edit_run" method="post" action="<?= admin_run_url($run->name) ?>" data-units='<?php echo json_encode($run->getAllUnitIds()); ?>'>
                            <input type="hidden" value="<?php echo h($run->name); ?>" name="old_run_name" class="run_name" />
                            <div class="edit-run-header pull-right">

                                <h5>Publicness: &nbsp; </h5>
                                <span class="btn-group">

                                    <a href="<?= admin_run_url($run->name, 'ajax_run_public_toggle?public=0') ?>" class="btn btn-default public-toggle hastooltip <?= ($run->public == 0) ? 'btn-checked' : '' ?>" title="" 
                                    data-original-title="Only you and test users can access the study.">
                                    <i class="fa fa-eject"></i>
                                    </a>
                                    <a href="<?= admin_run_url($run->name, 'ajax_run_public_toggle?public=1') ?>" class="btn btn-default public-toggle hastooltip <?= ($run->public == 1) ? 'btn-checked' : '' ?>" title="" data-original-title="You and people who already have an access code can access (no new users can enrol).">
                                        <i class="fa fa-volume-off"></i>
                                    </a>
                                    <a href="<?= admin_run_url($run->name, 'ajax_run_public_toggle?public=2') ?>" class="btn btn-default public-toggle hastooltip <?= ($run->public == 2) ? 'btn-checked' : '' ?> " title="" data-original-title="People who have the link can access.">
                                        <i class="fa fa-volume-down"></i>
                                    </a>
                                    <a href="<?= admin_run_url($run->name, 'ajax_run_public_toggle?public=3') ?>" class="btn btn-default public-toggle hastooltip <?= ($run->public == 3) ? 'btn-checked' : '' ?>" title="" data-original-title="Link is public, everyone can access. Define a public blurb for the studies page first!">
                                        <i class="fa fa-volume-up"></i>
                                    </a>
                                </span>
                            </div>
                            <div class="edit-run-header">
                                <div class="btn-group">
                                    <a class="reorder_units btn btn-default hastooltip" title="" href="<?= admin_run_url($run->name, 'ajax_reorder') ?>" data-original-title="Save new positions">
                                        <i class="fa fa-exchange fa-rotate-90 fa-larger"></i> Reorder
                                    </a>
                                    <a href="<?= admin_run_url($run->name, 'ajax_run_locked_toggle') ?>" class="btn btn-default lock-toggle hastooltip <?= ($run->locked) ? 'btn-checked' : '' ?>" title="" data-original-title="Lock the controls on this page, so you cannot accidentally change anything.">
                                        <i class="fa fa-unlock"></i> Lock
                                    </a>
                                    <a id="export_run_units" class="export_run_units hastooltip btn btn-default" title="" data-original-title="Export these run units as JSON">
                                        <i class="fa fa-suitcase"></i> Export
                                    </a>
                                    <a id="import_run_units" class="import_run_units hastooltip btn btn-default" title="" data-original-title="Import run units into current run">
                                        <i class="fa fa-upload"></i> Import
                                    </a>
                                </div>
                            </div>
                            <div class="clearfix"></div>
                            <hr />

                            <div class="run_units"></div>

                            <div class="clear clearfix"></div>

                            <div id="run-unit-choices">
                                <div class="form-group col-lg-12 text-center">
                                    <div class="btn-group">
                                        <?php foreach ($add_unit_buttons as $name => $button): ?>
                                            <a class="add_<?= strtolower($name)?> add_run_unit btn btn-default btn-lg hastooltip" title="<?= $button['title'] ?>" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=' . $name) ?>" >
                                                <i class="fa fa-2x <?= $button['icon'] ?>"></i>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <h5 class="text-center">click one of the symbols above to add a module</h5> 
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
            <div class="col-md-3">
                <div class="box box-primary">
                    <div class="box-body">
                        <?php Template::loadChild('public/documentation/run_module_explanations'); ?>
                        <div class="panel-group">
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <a class="accordion-toggle" data-toggle="collapse" href="#r_helpers">How to use R in formr<br></a>
                                </div>
                                <div id="r_helpers" class="panel-collapse collapse">
                                    <div class="panel-body">
                                    <?php Template::loadChild('public/documentation/r_helpers'); ?>
                                </div>
                            </div>
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <a class="accordion-toggle" data-toggle="collapse" href="#markdown">Knitr & Markdown<br></a>
                                </div>
                                <div id="markdown" class="panel-collapse collapse">
                                    <div class="panel-body">
                                    <?php Template::loadChild('public/documentation/knitr_markdown'); ?>
                                </div>
                            </div>
                        </div>
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