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
                        <h3 class="box-title">Log of user activity</h3>
                        <div class="pull-right">
                            <div class="dropdown"><a  href="#" data-toggle="dropdown" aria-expanded="false" class="btn btn-primary dropdown-toggle"><i class="fa fa-save"></i> Export User Overview</a>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_detail?format=csv'); ?>"><i class="fa fa-floppy-o"></i> Download CSV</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_detail?format=csv_german'); ?>"><i class="fa fa-floppy-o"></i> Download German CSV</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_detail?format=tsv'); ?>"><i class="fa fa-floppy-o"></i> Download TSV</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_detail?format=xls'); ?>"><i class="fa fa-floppy-o"></i> Download XLS</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_detail?format=xlsx'); ?>"><i class="fa fa-floppy-o"></i> Download XLSX</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_detail?format=json'); ?>"><i class="fa fa-floppy-o"></i> Download JSON</a></li>
                                </ul>
                            </div>
                        </div>

                    </div>
                    <div class="box-body">
                        <h4>
                            Here you can see users' history of participation, i.e. when they got to certain point in a study, how long they stayed at each station and so forth. Earliest participants come first.
                        </h4>
                    </div>
                    <div class="box-body table-responsive no-padding">
                        <?php Template::loadChild('public/alerts'); ?>

                        <div class="col-md-12" style="margin: 10px;">
                            <form action="<?= admin_run_url($run->name, 'user_detail') ?>" method="get" class="form-inline">
                                <label class="sr-only">Name</label>
                                <div class="input-group" style="width: 350px;">
                                    <div class="input-group-addon">SEARCH <i class="fa fa-user"></i></div>
                                    <input name="session" value="<?= h(array_val($_GET, 'session')) ?>" type="text" class="form-control" placeholder="Session code">
                                </div>

                                <label class="sr-only" title="This refers to the user's current position!">Position</label>
                                <div class="input-group">
                                    <div class="input-group-addon"><i class="fa fa-compass"></i></div>
                                    <input name="position" value="<?= h(array_val($_GET, 'position')) ?>" type="number" class="form-control" placeholder="Position">
                                </div>

                                <label class="sr-only">Operator</label>
                                <div class="input-group">
                                    <select class="form-control" name="position_lt">
                                        <option value="=" <?= ($position_lt == '=') ? 'selected' : ''; ?>>=</option>
                                        <option value="&lt;" <?= ($position_lt == '<') ? 'selected' : ''; ?>>&lt;</option>
                                        <option value="&gt;" <?= ($position_lt == '>') ? 'selected' : ''; ?>>&gt;</option>
                                    </select>
                                </div>


                                <button type="submit" class="btn btn-default"><i class="fa fa-search"></i></button>
                            </form>
                        </div>

                        <?php if (!empty($users)): ?>
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Unit in Run</th>
                                        <th>Module Description</th>
                                        <th>Session</th>
                                        <th>Entered</th>
                                        <th>Stayed</th>
                                        <th>Left</th>
                                        <th>Expires</th>
                                        <th>Result</th>
                                        <th>Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $last_ended = $last_user = $continued = $user_class = '';
                                    // printing table rows
                                    foreach ($users as $row) :
                                        if ($row['session'] !== $last_user) { // next user
                                            $user_class = ($user_class == '') ? 'alternate' : '';
                                            $last_user = $row['session'];
                                        } elseif (round((strtotime($row['created']) - $last_ended) / 30) == 0) { // same user
                                            $continued = ' immediately_continued';
                                        }
                                        $last_ended = strtotime($row['created']);
                                        ?>
                                        <tr class="<?= $user_class . $continued ?>">
                                            <td><?= $row['unit_type'] ?> <span class="hastooltip" title="position in run <?= $row['run_name'] ?>">(<?= $row['position'] ?>)</span></td>
                                            <td><small><?= $row['description'] ?></small></td>
                                            <td><small><abbr class="abbreviated_session" title="Click to show the full session" data-full-session="<?= h($row['session']) ?>"><?= mb_substr($row['session'], 0, 10) ?>…</abbr></small></td>
                                            <td><small><?= $row['created'] ?></small></td>
                                            <td><small title="<?= $row['stay_seconds'] ?> seconds"><?= timetostr(time() + $row['stay_seconds']) ?></small></td>
                                            <td><small><?= $row['ended'] ?></small></td>
                                            <td><?php if($row['queued'] > 0) echo "<b>"; ?>
                                                <small><?= $row['expires'] ?> </small>
                                                <?php if($row['queued'] > 0) echo "</b>"; ?>
                                            </td>
                                            <td>
                                             <small class="label <?=(strpos($row['result'], "error")!==false)?'label-danger':' label-default';?> hastooltip" title="<?php echo $row['result_log']; ?>"><?php echo $row['result'];?></small>
                                            </td>
                                            <td>
                                                <a data-href="<?php echo admin_run_url($row['run_name'], 'ajax_delete_unit_session', array('session_id' => $row['session_id'])); ?>" href="javascript:void(0);" class="hastooltip delete-user-unit-session" title="<?= h($row['delete_title']) ?>"  data-msg="<?= h($row['delete_msg']) ?>" class="delete-user-unit-session"><i class="fa fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="pagination">
                                <?php
                                $append = $querystring ? "?" . http_build_query($querystring) . "&" : '';
                                $pagination->render("admin/run/" . $run->name . "/user_detail" . $append);
                                ?>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>
<?php Template::loadChild('admin/run/run_modals', array('reminders' => !empty($reminders) ? $reminders : array())); ?>
<?php Template::loadChild('admin/footer'); ?>
