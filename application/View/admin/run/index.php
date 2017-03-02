<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
	<!-- Content Header (Page header) -->
	<section class="content-header">
		<h1><?php echo $run->name; ?> </h1>
	</section>

	<!-- Main content -->
	<section class="content">
		<div class="row">
			<div class="col-md-2">
				<?php Template::load('admin/run/menu'); ?>
			</div>
			<div class="col-md-6">
                            <div class="box box-primary">
                                <div class="box-header with-border">
                                    <h3 class="box-title" style="display: block">
                                        Edit Run 
                                        <a href="javascript:void(0);" class="btn btn-danger btn-panic pull-right" id="btn-panic">I am panicking :(</a>
                                    </h3>
                                </div>
                                <div class="box-body">
                                    <form class="form-horizontal edit_run form-inline" enctype="multipart/form-data" name="edit_run" method="post" action="<?= admin_run_url($run->name) ?>" data-units='<?php echo json_encode($run->getAllUnitIds()); ?>'>
                                        <input type="hidden" value="CyrilTestDiary" name="old_run_name" class="run_name" />
                                        <div class="edit-run-header pull-right">

                                            <h5>Publicness: &nbsp; </h5>
                                            <span class="btn-group">

                                                <a href="<?= admin_run_url($run->name, 'ajax_run_public_toggle?public=0') ?>" class="btn btn-default public-toggle hastooltip <?= ($run->public == 0) ? 'btn-checked' : '' ?>" title="" data-original-title="Only you can access.">
                                                    <i class="fa fa-eject"></i>
                                                </a>
                                                <a href="<?= admin_run_url($run->name, 'ajax_run_public_toggle?public=1') ?>" class="btn btn-default public-toggle hastooltip <?= ($run->public == 1) ? 'btn-checked' : '' ?>" title="" data-original-title="You and people who have an access code can access (no new users can enrol).">
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

                                        <hr />
                                        <div id="choices">
                                            <div class="form-group col-lg-12 text-center">
                                                <div class="btn-group">
                                                    <a class="add_survey add_run_unit btn btn-default btn-lg hastooltip" title="Add survey" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=Survey') ?>" >
                                                        <i class="fa fa-pencil-square fa-2x"></i>
                                                    </a>
                                                    <a class="add_external add_run_unit btn btn-default btn-lg hastooltip" title="Add external link" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=External') ?>">
                                                        <i class="fa fa-external-link-square fa-2x"></i>
                                                    </a>
                                                    <a class="add_email add_run_unit btn btn-default btn-lg hastooltip" title="Add email" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=Email') ?>">
                                                        <i class="fa fa-envelope fa-2x"></i>
                                                    </a>
                                                    <a class="add_skipbackward add_run_unit btn btn-default btn-lg hastooltip" title="Add a loop (skip backwards)" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=SkipBackward') ?>">
                                                        <i class="fa fa-backward fa-2x"></i>
                                                    </a>
                                                    <a class="add_pause add_run_unit btn btn-default btn-lg hastooltip" title="Add pause" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=Pause') ?>">
                                                        <i class="fa fa-pause fa-2x"></i>
                                                    </a>
                                                    <a class="add_skipforward add_run_unit btn-default btn btn-lg hastooltip" title="Add a jump (skip forward)" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=Forward') ?>">
                                                        <i class="fa fa-forward fa-2x"></i>
                                                    </a>
                                                    <a class="add_shuffle add_run_unit btn btn-default btn-lg hastooltip" title="Add shuffle (randomise participants)" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=Shuffle') ?>">
                                                        <i class="fa fa-random fa-2x"></i>
                                                    </a>
                                                    <a class="add_page add_run_unit btn btn-default btn-lg hastooltip" title="Add a stop point" href="<?= admin_run_url($run->name, 'ajax_create_run_unit?type=Page') ?>">
                                                        <i class="fa fa-stop fa-2x"></i>
                                                    </a>
                                                </div>
                                                <h5 class="text-center">click one of the symbols above to add a module</h5> 
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                        </div>
                        <div class="col-md-4">
                            <div class="box box-primary">
                                <div class="box-header with-border">
                                    <h3 class="box-title">Formr Runs </h3>
                                </div>
                                <div class="box-body">
                                    <?php Template::load('public/documentation/run_module_explanations'); ?>
                                </div>
                            </div>

                        </div>
		</div>

		<div class="clear clearfix"></div>
	</section>
	<!-- /.content -->
</div>

<?php 
	Template::load('admin/run/run_modals', array('reminders' => array()));
	Template::load('admin/footer');
?>