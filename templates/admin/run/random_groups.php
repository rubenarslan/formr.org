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
            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Randomization Results</h3>
                        <div class="pull-right">
                            <a href="#" data-toggle="modal" data-target="#export-random-groups" class="btn btn-primary"><i class="fa fa-save"></i> Export</a>
                        </div>
                    </div>
                    <div class="box-body table-responsive">
                        <?php Template::loadChild('public/alerts'); ?>
                        <?php if ($users): ?>
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Unit in Run</th>
                                        <th>Session</th>
                                        <th>Group</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $last_user = $tr_class = '';
                                    foreach ($users as $row) {
                                        if ($row['session'] !== $last_user) {
                                            $tr_class = ($tr_class == '') ? 'alternate' : '';
                                            $last_user = $row['session'];
                                        }
                                    ?>
                                    <tr class="<?= $tr_class ?>">
                                        <td><?= $row['unit_type'] ?> <span class='hastooltip' title='position in run <?= $row['run_name']?>'>(<?= $row['position'] ?>)</span></td>
                                        <td><small title="<?= $row['session']?>"><?= $row['session']?></small></td>
                                        <td><small title="Assigned group"><?= $row['group'] ?></small></td>
                                        <td><small><?= $row['created'] ?></small></td>
                                    </tr>
                                    <?php } ?>

                                </tbody>
                            </table>

                        <?php else: ?>
                            <h5 class="lead"><i>No users to randomize</i></h5>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<div class="modal fade" id="export-random-groups" tabindex="-1" role="dialog" aria-hidden="true" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
                <h4 class="modal-title">Export as..</h4>
            </div>
            <div class="modal-body">
                <div class="list-group">
                    <div class="list-group-item">
                        <h4 class="list-group-item-heading"><a href="<?= admin_run_url($run->name, 'random_groups_export?format=csv') ?>"><i class="fa fa-floppy-o fa-fw"></i> CSV</a></h4>
                        <p class="list-group-item-text">good for big files, problematic to import into German Excel (comma-separated)</p>
                    </div>

                    <div class="list-group-item">
                        <h4 class="list-group-item-heading"><a href="<?= admin_run_url($run->name, 'random_groups_export?format=csv_german') ?>"><i class="fa fa-floppy-o fa-fw"></i> German CSV</a></h4>
                        <p class="list-group-item-text">good for German Excel (semicolon-separated)</p>
                    </div>

                    <div class="list-group-item">
                        <h4 class="list-group-item-heading"><a href="<?= admin_run_url($run->name, 'random_groups_export?format=tsv') ?>"><i class="fa fa-floppy-o fa-fw"></i> TSV</a></h4>
                        <p class="list-group-item-text">tab-separated, human readable as plaintext</p>
                    </div>

                    <div class="list-group-item">
                        <h4 class="list-group-item-heading"><a href="<?= admin_run_url($run->name, 'random_groups_export?format=xls') ?>"><i class="fa fa-floppy-o fa-fw"></i> XLS</a></h4>
                        <p class="list-group-item-text">old excel format, won't work with more than 16384 rows or 256 columns</p>
                    </div>

                    <div class="list-group-item">
                        <h4 class="list-group-item-heading"><a href="<<?= admin_run_url($run->name, 'random_groups_export?format=xlsx') ?>"><i class="fa fa-floppy-o fa-fw"></i> XLSX</a></h4>
                        <p class="list-group-item-text">new excel format, higher limits</p>
                    </div>

                    <div class="list-group-item">
                        <h4 class="list-group-item-heading"><a href="<?= admin_run_url($run->name, 'random_groups_export?format=json') ?>"><i class="fa fa-floppy-o fa-fw"></i> JSON</a></h4>
                        <p class="list-group-item-text">not particularly human-readable, but machines love it. This is probably the fastest way to get your data into R, just use <pre><code class="r hljs">data = as.data.frame(jsonlite::fromJSON( "/path/to/exported_file.json" ))</code></pre></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php Template::loadChild('admin/footer'); ?>