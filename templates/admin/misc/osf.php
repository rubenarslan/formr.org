<?php Template::loadChild('admin/header'); ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>Open Science Framework &laquo;&raquo; FORMR actions</h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="panel panel-default" id="panel1">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <a data-toggle="collapse" data-target="#collapseOne"  href="#collapseOne"><i class="fa fa-cloud-upload"></i> Export run structure to OSF project </a>
                </h4>

            </div>
            <div id="collapseOne" class="panel-collapse collapse in">
                <div class="panel-body">
                    <form method="post" class="form-inline" action="<?php echo admin_url('osf'); ?>">
                        <table class="table table-responsive">
                            <thead>
                                <tr>
                                    <th>Fromr Projects</th>
                                    <th>&nbsp;</th>
                                    <th>OSF Projects</th>
                                    <th>&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="input-group">
                                            <span class="input-group-addon"><i class="fa fa-rocket"></i></span>
                                            <div class="form-group">
                                                <select class="form-control" name="formr_project">
                                                    <option value="">....</option>
                                                    <?php foreach ($runs as $run): $selected = $run_selected == $run['name'] ? 'selected' : null; ?>
                                                        <option <?= $selected; ?>><?= $run['name'] ?> </option>
                                                    <?php endforeach; ?>
                                                </select>           
                                            </div>
                                        </div>
                                        <p>
                                            <a href="<?= site_url('admin/run') ?>" target="_blank">Create an formr project (run)</a>
                                        </p>
                                    </td>
                                    <td><i class="fa fa-long-arrow-right fa-2x"></i></td>
                                    <td>
                                        <div class="input-group">
                                            <span class="input-group-addon"><i class="fa fa-rocket"></i></span>
                                            <div class="form-group">
                                                <select name="osf_project" class="form-control">
                                                    <option value="">....</option>
                                                    <?php foreach ($osf_projects as $project): ?>
                                                        <option value="<?= $project['id']; ?>"><?= $project['name']; ?> </option>
                                                    <?php endforeach; ?>
                                                </select>          
                                            </div>
                                        </div>
                                        <p>
                                            <a href="<?php echo Config::get('osf.site_url'); ?>" target="_blank">Create an OSF project</a>
                                        </p>
                                    </td>
                                    <td>
                                        <input type="hidden" name="osf_action" value="export-run" />
                                        <button type="submit" class="btn btn-primary btn-large"><i class="fa fa-mail-forward"></i> Export</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </form>
                </div>	
            </div>
        </div>
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php Template::loadChild('admin/footer'); ?>