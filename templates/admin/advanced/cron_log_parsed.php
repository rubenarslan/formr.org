<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>Cron Logs <small>Superadmin</small></h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-3">
                <div class="box box-solid">
                    <div class="box-header with-border">
                        <h3 class="box-title"><i class="fa fa-fa"></i> Logs</h3>
                        <div class="box-tools">
                            <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
                        </div>
                    </div>
                    <div class="box-body no-padding">
                        <ul class="nav nav-pills nav-stacked">
                            <?php foreach ($files as $file => $path): ?>
                                <li class="file <?= $file === $parse ? 'active' : '' ?>">
                                    <a href="<?php echo site_url('admin/advanced/cron_log?f=' . $file); ?>">
                                        <i class="fa fa-file"></i> <?php echo $file; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <!-- /.box-body -->
                </div>
                <!-- /.box -->
            </div>
            <div class="col-md-9">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Cron Log </h3>
                    </div>
                    <div class="box-body">
                        <div id="log-entries" class="text panel-group opencpu_accordion">
                            <?php
                            if ($parse) {
                                $parser->printCronLogFile($parse, $expand_logs);
                            }
                            ?>
                        </div>

                        <script>
                            $(document).ready(function () {
                                var $entries = $('#log-entries');
                                var items = $entries.children('.log-entry');
                                $entries.append(items.get().reverse());
                                $entries.show();
                            });
                        </script>
                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php Template::loadChild('admin/footer'); ?>