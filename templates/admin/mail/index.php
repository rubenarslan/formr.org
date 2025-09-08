<?php
Template::loadChild('admin/header');
?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>E-Mail Accounts </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <!-- survey context menu -->
            <div class="col-md-4">

                <div class="box box-solid">
                    <div class="box-header with-border">
                        <h3 class="box-title">Current Accounts</h3>
                        <div class="box-tools">
                            <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="box-body no-padding context-menu">
                        <?php if ($accs): ?>
                            <ul class="nav nav-pills nav-stacked">
                                <?php foreach ($accs as $account): 
                                    $status = $account['status'];
                                    if( $status == 1) {
                                        $label = "label-success";
                                        $text = "tested";
                                    } else if ($status == -1) {
                                        $label = "label-danger";
                                        $text = "not working";
                                    } else {
                                        $label = "label-warning";
                                        $text = "untested";    
                                    }
                                    ?>
                                    <li>
                                        <a href="<?php echo admin_url('mail', array('account_id' => $account['id'])); ?>" style="display: inline-block"><i class="fa fa-envelope"></i><small class="label <?=$label?>"><?=$text?></small> <?= $account['from'] ?></a>
                                        <a href="<?php echo admin_url('mail/delete', array('account_id' => $account['id'])); ?>" class="pull-right" style="display: inline-block" onclick="return confirm('Are you sure you want to delete this account?')"><i class="fa fa-trash text-red"></i></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <!-- /.box-body -->
                </div>
                <a href="<?php echo admin_url('mail'); ?>"class="btn btn-primary"><i class="fa fa-plus-circle"></i> Create New Account</a>

                <!-- /. box -->
            </div>

            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title"><?= $form_title ?></h3>
                    </div>
                    <form class="form-horizontal" action="" method="post" autocomplete="off">
                        <?= formr_csrf_token() ?>
                        <div class="box-body">
                            <?php Template::loadChild('public/alerts'); ?>
                            <div class="form-group">
                                <label class="col-sm-2 control-label">From (email)</label>
                                <div class="col-sm-10">
                                    <input name="from" value="<?= array_val($acc->account, 'from') ?>" type="email" class="form-control" placeholder="example@email.com" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-2 control-label">From (name)</label>
                                <div class="col-sm-10">
                                    <input name="from_name" value="<?= array_val($acc->account, 'from_name') ?>" type="text" class="form-control" placeholder="Sender Name" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-2 control-label">SMTP Host</label>
                                <div class="col-sm-10">
                                    <input  name="host" value="<?= array_val($acc->account, 'host') ?>" type="text" class="form-control" placeholder="email.gwdg.de" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-2 control-label" title="Usually 587, sometimes 465, or 25">SMTP Port</label>
                                <div class="col-sm-10">
                                    <input name="port" value="<?= array_val($acc->account, 'port') ?>" type="number" class="form-control" placeholder="25" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-2 control-label">TLS</label>
                                <div class="col-sm-10">
                                    <div class="checkbox">
                                        <label> 
                                            <input type="hidden" name="tls" value="0">
                                            <input type="checkbox"name="tls" id="tls" value="1" <?= array_val($acc->account, 'tls') ? 'checked' : ''; ?>>
                                            Use TLS </label>
                                    </div>
                                </div>
                            </div>


                            <div class="form-group">
                                <label class="col-sm-2 control-label">Username</label>
                                <div class="col-sm-10">
                                    <input name="username" value="<?= array_val($acc->account, 'username') ?>" type="text" class="form-control" placeholder="Username" autocomplete="off" />
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-2 control-label" for="reply_to" title="If you specify the Reply-To argument, responses to emails sent with this account will automatically be addressed to this Reply-To address.">
                                    <?php echo _("Reply-To:"); ?>
                                </label>
                                <div class="col-sm-10">
                                    <input class="form-control" value="<?= array_val($acc->account, 'reply_to') ?>" type="text" placeholder="Reply-To (optional)" name="reply_to" id="reply_to" value="">
                                </div>
                            </div>


                            <div class="form-group">
                                <label class="col-sm-2 control-label">Password</label>
                                <div class="col-sm-10">
                                    <input name="password" type="password" class="form-control" placeholder="Password (not shown)" autocomplete="off" />
                                </div>
                            </div>
                            <hr />
                            <div class="form-group">
                                <label class="col-sm-2 control-label">Test</label>
                                <div class="col-sm-10">
                                    <div class="checkbox">
                                        <label> <input type="checkbox"name="test_account" id="test_account" value="1" checked> Test E-mail account on save (sends a test e-mail to the above address) </label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-2 control-label">&nbsp;</label>
                                <div class="col-sm-10">
                                    <button class="btn btn-primary" name="save_account" type="submit"><i class="fa fa-save"></i> Save Account</button>
                                </div>
                            </div>

                        </div>
                        <!-- /.box-body -->
                    </form>

                </div>
            </div>
        </div>

    </section>
    <!-- /.content -->
</div>

<?php Template::loadChild('admin/footer'); ?>
