<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <h1>User Profile </h1>
    </section>

    <section class="content">

        <div class="row">
            <div class="col-md-3">
                <?php if (!$user->isAdmin()): ?>
                <div class="box box-warning text-center" style="background-color: #f39c12; color: #fff; padding: 25px;">
                    <div class="box-header">
                        <i class="fa fa-warning fa-2x" style="font-size: 55px; color: #fff"></i>
                    </div>
                    <div class="box-body box-profile">
                        <h3>Your account is limited. You can request for full access as specified in the documentation</h3>
                        <a href="<?= site_url('documentation/#get_started') ?>" class="btn btn-default" target="_blank"><i class="fa fa-link"></i> See Documentation</a>
                    </div>
                    <!-- /.box-body -->
                </div>
                <?php endif; ?>
                
                <div class="box box-primary">
                    <div class="box-body box-profile">
                        <div class="text-center">
                            <i class="fa fa-user fa-5x"></i>
                        </div>

                        <h3 class="profile-username text-center"><?= h($names) ?></h3>

                        <p class="text-muted text-center"><?= h($affiliation) ?></p>

                        <ul class="list-group list-group-unbordered">
                            <li class="list-group-item">
                                <b>Surveys</b> <a class="pull-right" href="<?= admin_url('survey/list'); ?>"><?= $survey_count ?></a>
                            </li>
                            <li class="list-group-item">
                                <b>Runs(Studies)</b> <a class="pull-right" href="<?= admin_url('run/list'); ?>"><?= $run_count ?></a>
                            </li>
                            <li class="list-group-item">
                                <b>Email Accounts</b> <a class="pull-right" href="<?= admin_url('mail'); ?>"><?= $mail_count ?></a>
                            </li>
                        </ul>

                    </div>
                    <!-- /.box-body -->
                </div>
            </div>

            <div class="col-md-9">

                <?php Template::loadChild('public/alerts'); ?>

                <div class="nav-tabs-custom">
                    <ul class="nav nav-tabs">
                        <li class="active"><a href="#settings" data-toggle="tab" aria-expanded="true">Account Settings</a></li>
                        <li class=""><a href="#api" data-toggle="tab" aria-expanded="false">API Credentials</a></li>
                        <li class=""><a href="#data" data-toggle="tab" aria-expanded="false">Manage collected data</a></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane active" id="settings">
                            <form method="post" action="">
                            <h4 class="lead"> <i class="fa fa-user"></i> Basic Information</h4>

                            <div class="form-group  col-md-6">
                                <label class="control-label"> First Name </label>
                                <input class="form-control" name="first_name" value="<?= h($user->first_name) ?>" autocomplete="off">
                            </div>
                            <div class="form-group  col-md-6">
                                <label class="control-label"> Last Name </label>
                                <input class="form-control" name="last_name" value="<?= h($user->last_name) ?>" autocomplete="off">
                            </div>
                            <div class="form-group  col-md-12">
                                <label class="control-label"> Affiliation </label>
                                <input class="form-control" name="affiliation"  value="<?= h($user->affiliation) ?>" autocomplete="off">
                            </div>
                            <div class="clearfix"></div>

                            <h3 class="lead"> <i class="fa fa-lock"></i> Login Details (changes are effective immediately)</h3>
                            <div class="alert alert-warning col-md-7" style="font-size: 16px;">
                                <i class="fa fa-warning"></i> &nbsp; If you do not intend to change your password, please leave the password fields empty.
                            </div>
                            <div class="clearfix"></div>
                            
                            <div class="form-group ">
                                <label class="control-label" for="email"><i class="fa fa-envelope-o fa-fw"></i> New Email</label>
                                <input class="form-control" type="email" id="email" name="new_email" value="<?= h($user->email) ?>" autocomplete="new-password">
                            </div>
                            
                            <div class="form-group ">
                                <label class="control-label" for="pass2"><i class="fa fa-key fa-fw"></i> Enter New Password (Choose a secure phrase)</label>
                                <input class="form-control" type="password" id="pass2" name="new_password" autocomplete="new-password">
                            </div>
                            <div class="form-group ">
                                <label class="control-label" for="pass3"><i class="fa fa-key fa-fw"></i> Confirm New Password</label>
                                <input class="form-control" type="password" id="pass3" name="new_password_c" autocomplete="new-password">
                            </div>
                            <p>&nbsp;</p>
                            
                            <div class="col-md-5 no-padding confirm-changes">
                                <label class="control-label" for="pass"><i class="fa fa-check-circle"></i> Enter Old Password to Save Changes</label>
                                <div class="input-group input-group">
                                    <input class="form-control" type="password" id="pass" name="password" autocomplete="new-password" placeholder="Old Password">
                                    <span class="input-group-btn">
                                      <button type="submit" class="btn btn-raised btn-primary btn-flat"><i class="fa fa-save"></i> Save Changes</button>
                                    </span>
                                </div>
                            </div>
                            <div class="clearfix"></div>
                        </form>


                        <form method="post" action="<?= admin_url('account/setupTwoFactor') ?>">
                                <h4 class="lead"> <i class="fa fa-lock"></i> Login security</h4>
                                <?php if (!Config::get('2fa.enabled', true)): ?>
                                    <div class="alert alert-info">
                                        <i class="fa fa-info-circle"></i> Two-factor authentication is not enabled on this instance.
                                    </div>
                                <?php elseif ($user->is2FAenabled()): ?>
                                    <div class="alert alert-success">
                                        <i class="fa fa-check-circle"></i> Two-factor authentication is enabled for your account.
                                    </div>
                                    <div class="form-group col-md-6">
                                        <a href="<?= admin_url('account/manage-two-factor') ?>" class="btn btn-raised btn-warning">
                                            <i class="fa fa-cog"></i> Manage 2FA Settings
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        <i class="fa fa-warning"></i> Two-factor authentication is not enabled for your account. Enable it to add an extra layer of security.
                                    </div>
                                    <div class="form-group col-md-6">
                                        <a href="<?= admin_url('account/setup-two-factor') ?>" class="btn btn-raised btn-primary">
                                            <i class="fa fa-lock"></i> Setup 2FA
                                        </a>
                                    </div>
                                <?php endif; ?>
                        </form>
                        <div class="clearfix"></div>
                        </div>

                        <div class="tab-pane" id="api">
                            <?php if ($api_credentials): ?>
                                <h4 class="lead"> <i class="fa fa-lock"></i> API Credentials</h4>
                                <table class="table table-bordered">
                                    <tr>
                                        <td>Client ID</td>
                                        <td><code><?= $api_credentials['client_id'] ?></code></td>
                                    </tr>

                                    <tr>
                                        <td>Client Secret</td>
                                        <td><code><?= $api_credentials['client_secret'] ?></code></td>
                                    </tr>
                                </table>
                                <p> &nbsp; </p>
                            <?php endif; ?>
                        </div>
                        <!-- /.tab-pane -->
                        <div class="tab-pane" id="data">
                            <form method="post" action="">
                                <h4 class="lead"> <i class="fa fa-user"></i> Data management</h4>
                                <label class="control-label" for="deleteAcc"><i class="fa fa-check-circle"></i> Delete your account and all associated data</label>
                                <div class="input-group input-group">
                                    <input class="form-control" type="password" id="deleteAcc" name="confirm-delete" autocomplete="new-password" placeholder="Type 'yes' to confirm">
                                    <span class="input-group-btn">
                                      <button type="submit" name="deleteAccBtn" class="btn btn-raised btn-primary btn-flat"><i class="fa fa-save"></i> Delete account</button>
                                    </span>
                                </div>
                                <div class="clearfix"></div>
                            </form>
                        </div>
                        <!-- /.tab-pane -->
                    </div>
                    <!-- /.tab-content -->
                </div>

            </div>
        </div>

    </section>


</div>

<?php Template::loadChild('admin/footer'); ?>