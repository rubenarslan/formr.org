<?php header('Content-type: text/html; charset=utf-8'); ?><!DOCTYPE html>
<html class="no_js">
    <head>
        <?php Template::loadChild('public/head') ?>
    </head>

    <body class="fmr-run fmr-settings">

        <div id="fmr-page" class="fmr-about">
            <div class="container run-container">
                <div class="row">
                    <div class="col-lg-12 run_content">
                        <header class="run_content_header">
                            <?php if ($run->header_image_path): ?>
                                <img src="<?php echo $run->header_image_path; ?>" alt="<?php echo $run->name; ?> header image">
                            <?php endif; ?>
                            <?php if ($run->description): ?>
                                <p><?php echo $run->description; ?></p>
                            <?php endif; ?>
                        </header>

                        <div class="alerts-container">
                            <?php Template::loadChild('public/alerts'); ?>
                        </div>

                        <?php if (!empty($user_email)): ?>
                            <input type="hidden" id="session_token" value="<?= htmlentities($settings['code']); ?>" />

                        <div class="setting-item">
                            <h4>Email subscription</h4>
                            <p><i>Control whether you will receive emails through this study</i></p>
                            <form action="" method="post">
                                <select name="no_email" class="form-control" style="margin-bottom: 10px;">
                                    <?php
                                    foreach ($email_subscriptions as $key => $value):
                                        $selected = ((string) array_val($settings, 'no_email') === (string) $key) ? 'selected="selected"' : '';
                                        echo '<option value="' . $key . '" ' . $selected . '>' . $value . '</option>';
                                    endforeach;
                                    ?>
                                </select>
                                <input name="_sess" value="<?= htmlentities($settings['code']); ?>" type="hidden" />
                                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Email Settings</button>
                            </form>
                        </div>
                        <hr>
                        <?php endif; ?>

                        <div class="setting-item">
                            <h4>Manage Cookie Preferences</h4>
                            <p>You can adjust your cookie settings for this study. By default, we store only a session cookie to track your progress in the study. You can allow us to store this cookie persistently, so that you can resume your study later.</p>
                            <button type="button" id="manage_cookies_button" class="btn btn-default"><i class="fa fa-cog"></i> Open Cookie Settings</button>

                            <br><br>
                            
                            <p>You can also delete your session cookie. If you do so, your session (progress in the study) will no longer be accessible from this computer. Your data will still be saved on the server.<br>
                                If you have a login link, you can click it to re-activate your session later.</p>
                            <a href="<?= run_url($run->name, "logout"); ?>" class="btn-danger btn"><i class="fa fa-trash"></i> Delete Session Now / Logout</a>
                        </div>

                        <?php if (isset($vapid_key_exists) && $vapid_key_exists): ?>
                        <hr>
                        <div class="setting-item push-notification-settings-wrapper">
                            <h4>Push Notifications</h4>
                            <p><i>Manage your push notification preferences for this study.</i></p>
                            
                            <div class="push-notification-wrapper"> 
                                <input type="hidden" class="push-notification-permission" name="push_subscription_json_settings" id="push_subscription_json_settings" value='<?php echo htmlentities(isset($current_push_subscription) ? $current_push_subscription : ""); ?>' />

                                <div class="btn-group" style="margin-bottom: 10px;">
                                    <button type="button" id="push_notification_manage_button" class="btn btn-default push-notification-permission">
                                        <i class="fa fa-bell"></i> <span>Enable Push Notifications</span>
                                    </button>
                                    <button type="button" id="push_notification_unsubscribe_button" class="btn btn-warning" style="display: none;">
                                        <i class="fa fa-bell-slash"></i> <span>Disable Notifications</span>
                                    </button>
                                </div>
                                <div class="status-message" style="margin-top:10px;"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            <hr>
            <div class="setting-item">
                <h4>Data Deletion Request</h4>
                <p>
                    <strong>Your Session Token:</strong>
                    <code><?= htmlentities($settings['code']); ?></code>
                </p>
                
                <p>
                    If you wish to request deletion of your data, please contact the study administration and provide them with the session token shown above.
                </p>
            </div>
            <?php if ($run->footer_text_parsed): ?>
                        <hr>
                        <footer>
                        <?php echo $run->footer_text_parsed; ?>

                        <p><a href ="<?php echo run_url($run->name, ''); ?>">Back to Study</a></p>
                        </footer>
                    <?php endif; ?>
            </div>
        </div>
    </body>
</html>
