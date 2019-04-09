<?php Template::load('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1><?php echo $run->name; ?> <small><a target="_blank" title="The official link to your run, which you can share with prospective users." href="<?php echo run_url($run->name, null, null) ?>"><?php echo run_url($run->name, null, null) ?></a></small> </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-2">
                <?php Template::load('admin/run/menu'); ?>
            </div>
            <div class="col-md-10">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">User Overview <small><?= $pagination->maximum ?> users</small></h3>
                        <div class="pull-right">
                            <div class="dropdown"><a  href="#" data-toggle="dropdown" aria-expanded="false" class="btn btn-primary dropdown-toggle"><i class="fa fa-save"></i> Export User Overview</a>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_overview?format=csv'); ?>"><i class="fa fa-floppy-o"></i> Download CSV</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_overview?format=csv_german'); ?>"><i class="fa fa-floppy-o"></i> Download German CSV</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_overview?format=tsv'); ?>"><i class="fa fa-floppy-o"></i> Download TSV</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_overview?format=xls'); ?>"><i class="fa fa-floppy-o"></i> Download XLS</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_overview?format=xlsx'); ?>"><i class="fa fa-floppy-o"></i> Download XLSX</a></li>
                                    <li><a href="<?= admin_run_url($run->name, 'export_user_overview?format=json'); ?>"><i class="fa fa-floppy-o"></i> Download JSON</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="box-body">
                        <?php Template::load('public/alerts'); ?>

                        <p class="lead">
                            Here you can see users' progress (on which station they currently are).
                            If you're not happy with their progress, you can send manual reminders, <a href="<?= admin_run_url($run->name, 'settings#reminder') ?>">customisable here</a>. <br>You can also shove them to a different position in a run if they veer off-track.
                        </p>
                        <p>
                            Participants who have been stuck at the same survey, external link or email for 2 days or more are highlighted in yellow at the top. Being stuck at an email module usually means that the user somehow ended up there without a valid email address, so that the email cannot be sent. Being stuck at a survey or external link usually means that the user interrupted the survey/external part before completion, you probably want to remind them manually (if you have the means to do so).
                        </p>
                        <p>
                            You can manually create <a href="<?= admin_run_url($run->name, 'create_new_test_code'); ?>">new test users</a> or <a href="<?= admin_run_url($run->name, 'create_new_named_session'); ?>">real users</a>. Test users are useful to test your run. They are like normal users, but have animal names to make them easy to re-identify and you get a bunch of tools to help you fill out faster and skip over pauses. You may want to create real users if a) you want to send users a link containing an identifier to link them up with other data sources b) you are manually enrolling participants, i.e. participants cannot enrol automatically. The identifier you choose will be displayed in the table below, making it easier to administrate users with a specific cipher/code.
                        </p>

                        <form action="<?php echo admin_run_url($run->name, 'user_overview'); ?>" method="get" class="form-inline">
                            <label class="sr-only">Name</label>
                            <div id="search-session" style="display: inline-block; position: relative;">
                                <span class="sessions-search-switch" data-active="<?php echo !empty($_GET['sessions']) ? 'multiple' : 'single'; ?>"><i class="fa fa-retweet"></i></span>
                                <div class="input-group single <?php if (!empty($_GET['sessions'])) echo 'hidden'; ?>">
                                    <div class="input-group-addon">SEARCH <i class="fa fa-user"></i></div>
                                    <input name="session" value="<?= h(array_val($_GET, 'session')) ?>" type="text" class="form-control" placeholder="Session code"  style="width: 250px;">
                                </div>
                                <div class="input-group multiple <?php if (empty($_GET['sessions'])) echo 'hidden'; ?>">
                                    <div class="input-group-addon">SEARCH <i class="fa fa-users"></i></div>
                                    <textarea name="sessions" class="form-control" placeholder="Enter each code in a new line"  style="width: 450px;"><?= h(array_val($_GET, 'sessions')) ?></textarea>
                                </div>
                            </div>

                            <label class="sr-only">Position</label>
                            <div class="input-group">
                                <div class="input-group-addon"><i class="fa fa-compass"></i></div>
                                <select type="number" placeholder="Position" name="position" class="form-control">
                                    <option value="">Position</option>
                                    <?php foreach ($unit_types as $run_unit): ?>
                                        <option value="<?= $run_unit['position'] ?>" <?= ($run_unit['position'] == array_val($_GET, 'position')) ? 'selected="selected"' : '' ?>>
                                            <?= $run_unit['position'] . " - (" . $run_unit['type'] . ") " . $run_unit['description'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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

                        <table class="table table-striped has-actions">
                            <thead>
                                <tr>
                                    <th><input id="user-overview-select-all" type="checkbox" /></th>
                                    <th>Run position</th>
                                    <th>Description</th>
                                    <th>Session</th>
                                    <th>Created</th>
                                    <th>Last Access</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <input class="ba-select-session" type="checkbox" value="<?php echo h($user['session']); ?>" />
                                        </td>
                                        <td>
                                            <span class="hastooltip" title="Current position in run"><?php echo $user['position']; ?></span> – <small><?php echo $user['unit_type']; ?></small>
                                        </td>
                                        <td>
                                            <span class="hastooltip" title="RunUnit Description"><?php echo $user['description']; ?></span> 
                                        </td>
                                        <td>
                                            <?php if ($currentUser->user_code == $user['session']): ?>
                                                <i class="fa fa-user-md" class="hastooltip" title="This is you"></i>
                                                <?php
                                            endif;

                                            $animal_end = strpos($user['session'], "XXX");
                                            if ($animal_end === false) {
                                                $animal_end = 10;
                                            }
                                            $short_session = substr($user['session'], 0, $animal_end);
                                            ?>
                                            <small><abbr class="abbreviated_session" title="Click to show the full session" data-full-session="<?php echo $user['session']; ?>"><?php echo $short_session ?>…</abbr></small>
                                        </td>
                                        <td>
                                            <small><?php echo $user['created']; ?></small>
                                        </td>
                                        <td>
                                            <small class="hastooltip" title="<?php echo $user['last_access']; ?>"> <?php echo timetostr(strtotime($user['last_access'])); ?></small>
                                        </td>
                                        <td>
                                            <form class='form-inline form-ajax' action="<?php echo admin_run_url($user['run_name'], 'ajax_send_to_position'); ?>" method='post'>
                                                <span class='input-group'>
                                                    <span class='input-group-btn'>
                                                        <a target="_blank" class="btn hastooltip" href="<?php echo run_url($user['run_name'], null, array('code' => $user['session'])); ?>" title="<?= ($user['testing'] ? 'Test using this guinea pig again.' : 'Pretend you are this user (you will really manipulate this data)'); ?>"><i class="fa fa-user-secret"></i></a>

                                                        <a class='btn hastooltip link-ajax' href='<?= site_url("admin/run/{$user['run_name']}/ajax_toggle_testing?toggle_on=" . ($user['testing'] ? 0 : 1) . "&amp;run_session_id={$user['run_session_id']}&amp;session=" . urlencode($user['session'])) ?>' 
                                                           title='Toggle testing status'><i class='fa <?= ($user['testing'] ? 'fa-stethoscope' : 'fa-heartbeat') ?>'></i></a>

                                                        <a class="btn hastooltip remind-run-session" href="javascript:void(0)" data-href="<?php echo admin_run_url($user['run_name'], "ajax_remind?run_session_id={$user['run_session_id']}&amp;session=" . urlencode($user['session'])); ?>" title="Remind this user" data-session="<?php echo h($user['session']); ?>"><i class='fa fa-bullhorn'></i></a>
                                                        <button type="submit" class="btn hastooltip" title="Send this user to that position"><i class="fa fa-hand-o-right"></i></button>
                                                    </span>
                                                    <input type="hidden" name="session" value="<?php echo $user['session']; ?>" />
                                                    <select name="new_position" class="form-control position_monkey ">
                                                        <option value="">[unknown]</option>
                                                        <?php foreach ($unit_types AS $run_unit): ?>
                                                            <option value="<?= $run_unit['position'] ?>" <?= ($run_unit['position'] === $user['position']) ? 'selected' : '' ?>>
                                                                <?= $run_unit['position'] . " - (" . $run_unit['type'] . ") " . $run_unit['description'] ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <span class='input-group-btn link-ajax-modal'>
                                                        <a class="btn hastooltip delete-run-session" href="javascript:void(0)" data-href="<?php echo admin_run_url($user['run_name'], "ajax_delete_user?run_session_id={$user['run_session_id']}&amp;session=" . urlencode($user['session'])); ?>" title="Delete this user and all their data (you'll have to confirm)" data-session="<?php echo h($user['session']); ?>"><i class='fa fa-trash-o'></i></a>
                                                        <a class="btn hastooltip" href="<?php echo admin_run_url($user['run_name'], "user_detail?session=" . urlencode(substr($user['session'], 0, 15))); ?>" title="Go to user detail"><i class="fa fa-list"></i></a>
                                                    </span>
                                                </span>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="bulk-actions-ba has-actions">
                            <form class="form-inline form-ajax form-ba" action="<?php echo admin_run_url($run->name, 'ajax_user_bulk_actions'); ?>" method="post">
                                Do with selected: 			
                                <span class="input-group">
                                    <span class="input-group-btn">
                                        <a class="btn hastooltip ba" data-action="toggleTest" title="Toggle testing status for selected users"><i class="fa fa-heartbeat"></i></a>
                                        <a class="btn hastooltip ba" data-action="sendReminder" href="javascript:void(0)" title="Remind selected users"><i class='fa fa-bullhorn'></i></a>
                                        <a class="btn hastooltip ba" data-action="deleteSessions" href="javascript:void(0)" title="Delete selected users and all their data (you'll have to confirm)"><i class='fa fa-trash-o'></i></a>
                                        <a class="btn hastooltip ba" data-action="positionSessions" title="Send users to selected position"><i class="fa fa-hand-o-right"></i></a>
                                    </span>
                                    <select name="ba_new_position" class="form-control position_monkey">
                                        <option value="">[select]</option>
                                        <?php foreach ($unit_types AS $run_unit): ?>
                                            <option value="<?= $run_unit['position'] ?>">
                                                <?= $run_unit['position'] . " - (" . $run_unit['type'] . ") " . $run_unit['description'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </span>
                            </form>
                            <p>&nbsp;</p>
                        </div>

                        <div class="pagination">
                            <?php
                            $append = !empty($querystring) ? '?' . http_build_query($querystring) . '&' : '';
                            $pagination->render("admin/run/{$run->name}/user_overview" . $append);
                            ?>
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
Template::load('admin/run/run_modals', array('reminders' => $reminders));
Template::load('admin/footer');
?>
