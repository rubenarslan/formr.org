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
                        <li class=""><a href="#data" data-toggle="tab" aria-expanded="false">Account Deletion</a></li>
                        <li class=""><a href="#2fa" data-toggle="tab" aria-expanded="false">Two Factor Authentication</a></li>
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
                                    <input class="form-control" name="affiliation" value="<?= h($user->affiliation) ?>" autocomplete="off">
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

                            <div class="clearfix"></div>
                        </div>

                        <div class="tab-pane" id="api">
                            <h4 class="lead"> <i class="fa fa-lock"></i> API Credentials</h4>
                            <p>
                                The <code>formr</code> R package is the easiest way to use these credentials. Install it from GitHub with the <code>remotes</code> package:
                            </p>
                            <pre><code class="r copy-on-click">install.packages("remotes")
remotes::install_github("rubenarslan/formr")</code></pre>
                            <p>
                                See the <a href="https://rubenarslan.github.io/formr/" target="_blank" rel="noopener">package documentation</a> for full usage.
                            </p>
                            <div id="api-credentials-panel"
                                 data-endpoint="<?= admin_url('account/api-credentials') ?>"
                                 data-has-client="<?= $api_credentials ? '1' : '0' ?>">
                                <?php if ($api_credentials): ?>
                                    <table class="table table-bordered">
                                        <tr>
                                            <td>Client ID</td>
                                            <td><code><?= h($api_credentials['client_id']) ?></code></td>
                                        </tr>
                                        <tr>
                                            <td>Client Secret</td>
                                            <td><em class="text-muted">Stored as a hash — rotate to see a new one.</em></td>
                                        </tr>
                                    </table>
                                    <button type="button" class="btn btn-warning btn-raised" id="api-rotate-btn">
                                        <i class="fa fa-refresh"></i> Rotate Client Secret
                                    </button>
                                <?php else: ?>
                                    <p class="text-muted">You do not have API credentials yet.</p>
                                    <button type="button" class="btn btn-primary btn-raised" id="api-create-btn">
                                        <i class="fa fa-key"></i> Generate API Credentials
                                    </button>
                                <?php endif; ?>
                                <div id="api-secret-once" class="hidden" style="margin-top: 20px;">
                                    <div class="alert alert-warning">
                                        <strong>One-time display.</strong> Copy the client secret now — we only store a hash, so it cannot be recovered later.
                                    </div>
                                    <table class="table table-bordered">
                                        <tr>
                                            <td>Client ID</td>
                                            <td><code class="copy-on-click api-out-client-id"></code></td>
                                        </tr>
                                        <tr>
                                            <td>Client Secret</td>
                                            <td><code class="copy-on-click api-out-client-secret"></code></td>
                                        </tr>
                                        <tr>
                                            <td>R command</td>
                                            <td><pre><code class="r copy-on-click api-out-r-cmd"></code></pre></td>
                                        </tr>
                                    </table>
                                </div>
                                <noscript>
                                    <div class="alert alert-info" style="margin-top: 15px;">
                                        JavaScript is required to generate or rotate API credentials.
                                    </div>
                                </noscript>
                            </div>
                            <p> &nbsp; </p>
                        </div>
                        <script>
                        (function () {
                            var $panel = jQuery('#api-credentials-panel');
                            if (!$panel.length) { return; }
                            var endpoint = $panel.data('endpoint');
                            var apiHost = <?= json_encode(rtrim(site_url('api'), '/')) ?>;

                            function rCommand(clientId, clientSecret) {
                                return 'library(formr)\n' +
                                    'formr_store_keys(host = "' + apiHost +
                                    '", client_id = "' + clientId +
                                    '", client_secret = "' + clientSecret + '")\n' +
                                    'formr_api_authenticate(host = "' + apiHost + '")';
                            }

                            function issue(apiAction, confirmMsg) {
                                if (confirmMsg && !confirm(confirmMsg)) { return; }
                                jQuery.ajax({
                                    type: 'POST',
                                    url: endpoint,
                                    data: { api_action: apiAction },
                                    dataType: 'json'
                                }).done(function (response) {
                                    if (!response || !response.success) {
                                        alert((response && response.message) || 'Could not issue API credentials.');
                                        return;
                                    }
                                    $panel.find('#api-create-btn, #api-rotate-btn').prop('disabled', true);
                                    $panel.find('.api-out-client-id').text(response.data.client_id);
                                    $panel.find('.api-out-client-secret').text(response.data.client_secret);
                                    $panel.find('.api-out-r-cmd').text(rCommand(response.data.client_id, response.data.client_secret));
                                    $panel.find('#api-secret-once').removeClass('hidden');
                                }).fail(function () {
                                    alert('Request failed.');
                                });
                            }

                            $panel.on('click', '#api-create-btn', function () { issue('create', null); });
                            $panel.on('click', '#api-rotate-btn', function () {
                                issue('rotate', 'Rotating will invalidate the current secret. Continue?');
                            });
                        })();
                        </script>
                        <!-- /.tab-pane -->
                        <div class="tab-pane" id="data">
                            <form method="post" action="">
                                <h4 class="lead"> <i class="fa fa-trash"></i> Account Deletion</h4>
                                <div class="alert alert-danger">
                                    <strong>Warning!</strong> This action cannot be undone. All your data, including surveys, runs, and email accounts will be permanently deleted.
                                </div>

                                <div class="form-group">
                                    <label class="control-label" for="delete_confirm">Type "I understand my data will be gone"</label>
                                    <input class="form-control" type="text" id="delete_confirm" name="delete_confirm" required
                                        placeholder="I understand my data will be gone" autocomplete="off">
                                </div>

                                <div class="form-group">
                                    <label class="control-label" for="delete_email">Type your current email address</label>
                                    <input class="form-control" type="text" id="delete_email" name="delete_email" required
                                        placeholder="<?= h($user->email) ?>" autocomplete="off">
                                </div>

                                <div class="form-group">
                                    <label class="control-label" for="delete_password">Current Password</label>
                                    <input class="form-control" type="password" id="delete_password" name="delete_password" required autocomplete="current-password">
                                </div>

                                <?php if ($user->is2FAenabled()): ?>
                                    <div class="form-group">
                                        <label class="control-label" for="delete_2fa">Two-Factor Authentication Code</label>
                                        <input class="form-control" type="text" id="delete_2fa" name="delete_2fa" required placeholder="Enter your 2FA code" autocomplete="one-time-code">
                                    </div>
                                <?php endif; ?>


                                <div class="form-group">
                                    <button type="submit" name="delete_account" value="true" class="btn btn-danger btn-raised">
                                        <i class="fa fa-trash"></i> Permanently Delete Account
                                    </button>
                                </div>
                            </form>
                        </div>
                        <!-- /.tab-pane -->
                        <div class="tab-pane" id="2fa">

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
                                <div class="form-group col-md-6">
                                    <div class="alert alert-warning">
                                        <i class="fa fa-warning"></i> Two-factor authentication is not enabled for your account. Enable it to add an extra layer of security.
                                    </div>
                                    <p>
                                        Two-factor authentication adds an extra layer of security to your account.
                                        Once enabled, you'll need both your password and a code from your authenticator app to log in.
                                    </p>
                                </div>
                                <div class="form-group col-md-12">
                                    <a href="<?= admin_url('account/setup-two-factor') ?>" class="btn btn-raised btn-primary">
                                        <i class="fa fa-lock"></i> Setup 2FA
                                    </a>
                                </div>
                            <?php endif; ?>

                            <div class="clearfix"></div>
                        </div>
                    </div>
                    <!-- /.tab-content -->
                </div>

            </div>
        </div>

    </section>


</div>

<?php Template::loadChild('admin/footer'); ?>