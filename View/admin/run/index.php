<?php
Template::load('header', array(
	'js' => '<script src="' . WEBROOT . 'assets/run.js"></script>',
	'css' => '<link rel="stylesheet" href="' . WEBROOT . 'assets/admin.css" type="text/css" media="screen">',
));
Template::load('acp_nav');
?>
<div class="row">
    <form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?= WEBROOT ?>admin/run/<?= $run->name; ?>" data-units='<?php echo json_encode($run->getAllUnitIds()); ?>'>
        <div class="col-md-7 run_dialog">
            <div class="row">
                <div class="col-md-12 run_dialog">
                    <h4>
                        Publicness:
                        <input type="hidden" value="<?= $run->name ?>" name="old_run_name" class="run_name">
                        <span class="btn-group">
                            <a href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_run_public_toggle?public=0" class="btn btn-default public-toggle hastooltip <?= ($run->public == 0) ? 'btn-checked' : '' ?>" title="Only you can access.">
                                <i class="fa fa-eject"></i>
                            </a>
                            <a href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_run_public_toggle?public=1" class="btn btn-default public-toggle hastooltip <?= ($run->public == 1) ? 'btn-checked' : '' ?>" title="You and people who have an access code can access (no new users can enrol).">
                                <i class="fa fa-volume-off"></i>
                            </a>
                            <a href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_run_public_toggle?public=2" class="btn btn-default public-toggle hastooltip <?= ($run->public == 2) ? 'btn-checked' : '' ?>" title="People who have the link can access.">
                                <i class="fa fa-volume-down"></i>
                            </a>
                            <a href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_run_public_toggle?public=3" class="btn btn-default public-toggle hastooltip <?= ($run->public == 3) ? 'btn-checked' : '' ?>" title="Link is public, everyone can access. Define a public blurb for the studies page first!">
                                <i class="fa fa-volume-up"></i>
                            </a>
                        </span>
                    </h4>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-12 run_dialog">
                    <h4>
                        <div class="btn-group">
                            <a class="reorder_units btn hastooltip" title="Save new positions" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_reorder">
                                <i class="fa fa-exchange fa-rotate-90 fa-larger"></i>
                                Reorder
                            </a>
                            <a href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_run_locked_toggle" class="btn btn-default lock-toggle hastooltip <?= ($run->locked) ? 'btn-checked' : '' ?>" title="Lock the controls on this page, so you cannot accidentally change anything.">
                                <i class="fa fa-unlock"></i> Lock
                            </a>
                            <a id="export_run_units" class="export_run_units hastooltip btn" title="Export these run units as JSON">
                                <i class="fa fa-suitcase"></i> Export
                            </a>
							<a id="import_run_units" class="import_run_units hastooltip btn" title="Import run units into current run">
                                <i class="fa fa-upload"></i> Import
                            </a>
                        </div>
                        the run modules.
                    </h4>

                </div>
            </div>
            <div class="run_units">
            </div>
            <div class="row" id="run_dialog_choices">
                <div class="form-group col-lg-12">
                    <div class="btn-group">
                        <a class="add_survey add_run_unit btn btn-lg hastooltip" title="Add survey" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=Survey">
                            <i class="fa fa-pencil-square fa-2x"></i>
                        </a>
                        <a class="add_external add_run_unit  btn btn-lg hastooltip" title="Add external link" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=External">
                            <i class="fa fa-external-link-square fa-2x"></i>
                        </a>
                        <a class="add_email add_run_unit btn btn-lg hastooltip" title="Add email" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=Email">
                            <i class="fa fa-envelope fa-2x"></i>
                        </a>
                        <a class="add_skipbackward add_run_unit btn btn-lg hastooltip" title="Add a loop (skip backwards)" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=SkipBackward">
                            <i class="fa fa-backward fa-2x"></i>
                        </a>
                        <a class="add_pause add_run_unit btn btn-lg hastooltip" title="Add pause" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=Pause">
                            <i class="fa fa-pause fa-2x"></i>
                        </a>
                        <a class="add_skipforward add_run_unit btn btn-lg hastooltip" title="Add a jump (skip forward)" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=SkipForward">
                            <i class="fa fa-forward fa-2x"></i>
                        </a>
                        <a class="add_shuffle add_run_unit btn btn-lg hastooltip" title="Add shuffle (randomise participants)" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=Shuffle">
                            <i class="fa fa-random fa-2x"></i>
                        </a>
                        <a class="add_page add_run_unit btn btn-lg hastooltip" title="Add a stop point" href="<?= WEBROOT ?>admin/run/<?= $run->name; ?>/ajax_create_run_unit?type=Page">
                            <i class="fa fa-stop fa-2x"></i>
                        </a>
                    </div>
                    <p class="center">click one of the symbols above to add a module</p> 
                </div>
            </div>
        </div>


        <div class="col-md-5 pull-right well transparent_well">
            <?php Template::load('run_module_explanations'); ?>
        </div>


        <div class="clearfix"></div>

    </form>
</div>
<!-- Run templates needed for javascript -->
<script id="tpl-export-units" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ExportUnits" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>JSON export of modules</h3>
                </div>
                <div class="modal-body">
                    <h5>Select run units to export and give your export a name (<span class="red">Important for referencing the export later</span>)</h5>
                    <div>%{export_html}</div>
					<div class="col-md-12">
						<br />
						<input class="form-control" placeholder="Name Export (a to Z, 0 to 9, _ and spaces)" name="export_name" value="" />
					</div>
					<div class="clearfix"></div>
                </div>
            <div class="modal-footer">
                <button class="btn btn-success confirm-export" aria-hidden="true">Export</button>
                <button class="btn cancel-export" data-dismiss="modal" aria-hidden="true">Close</button>
            </div>
        </div>
    </div>
</script>
<script id="tpl-test-modal" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="FormR.org Modal" aria-hidden="true">
        <div class="modal-dialog">                         
            <div class="modal-content">                              
                <div class="modal-header">                                 
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>                                 
                    <h3>%{header}</h3>                             
                </div>                             
                <div class="modal-body">%{body}</div>
                <div class="modal-footer">                             
                    <button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>                         
                </div>                     
            </div>                 
        </div>
    </div>
</script>
<script id="tpl-export-unit-block" type="text/formr">
<div class="form-group run-export-unit-block">
	<div class="select btn" data-position="%{unit_pos}" data-selected="1">
		<i class="fa fa-check fa-2x"></i>
	</div>
	<div class="col-sm-12">
		<pre><code class="hljs json">%{unit_json}</code></pre>
	</div>
	<div class="clearfix"></fix>
</div>
</script>
<script id="tpl-import-units" type="text/formr">
    <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="ImportUnits" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h3>JSON import of modules</h3>
                </div>
                <div class="modal-body">
                    <div>%{content}</div>
					<div class="clearfix"></div>
                </div>
            <div class="modal-footer">
                <button class="btn btn-success confirm-import" aria-hidden="true">Import</button>
                <button class="btn cancel-export" data-dismiss="modal" aria-hidden="true">Close</button>
            </div>
        </div>
    </div>
</script>

<?php
Template::load('footer');
