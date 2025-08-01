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
                        <h3 class="box-title">Settings </h3>
                    </div>

                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <div class="nav-tabs-custom">
                            <ul class="nav nav-tabs">
                                <li class="active"><a href="#settings" data-toggle="tab" aria-expanded="true">General</a></li>
                                <li><a href="#privacy" data-toggle="tab" aria-expanded="false">Privacy</a></li>
                                <li><a href="#css" data-toggle="tab" aria-expanded="false">CSS</a></li>
                                <li><a href="#js" data-toggle="tab" aria-expanded="false">JS</a></li>
                                <li><a href="#manifest" data-toggle="tab" aria-expanded="false">App</a></li>
                                <li><a href="#service_message" data-toggle="tab" aria-expanded="false">Service message</a></li>
                                <li><a href="#reminder" data-toggle="tab" aria-expanded="false">Reminder</a></li>
                                <li><a href="#overview_script" data-toggle="tab" aria-expanded="false">Overview</a></li>
                                <li><a href="#osf" data-toggle="tab" aria-expanded="false">OSF</a></li>
                            </ul>
                            <div class="tab-content">
                                <div class="tab-pane active" id="settings">
                                    <form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">
                                        <p class="pull-right">
                                            <input type="submit" name="submit_settings" value="Save" class="btn btn-primary save_settings">
                                        </p>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label title="Will be shown on every page of the run">Title</label>
                                                <input type="text" maxlength="1000" placeholder="Title" name="title" class="form-control" value="<?= h($run->title); ?>" />
                                            </div>
                                            <div class="form-group">
                                                <label title="Link to your header image, shown on every run page">Header image</label>
                                                <input type="text" maxlength="255" placeholder="URL" name="header_image_path" class="form-control" value="<?= h($run->header_image_path); ?>" />
                                            </div>

                                            <div class="form-group" style="margin-bottom: 15px;">
                                                <div class="col-md-6">
                                                    <label for="expiresOn">Expires On</label>
                                                    <p>If set, the data in this run will be deleted after the specified date. You will receive email reminders before this happens. Under <abbr title="General Data Protection Regulation">GDPR</abbr>, you are required to delete personal data once it is no longer necessary for the purpose for which it was collected. It is up to you to know what period of data retention is reasonable. <br>If you did not collect personal data, you do not need to delete data. However, do consider that even seemingly anonymous data can sometimes be combined with other data to identify individuals, so deletion may still be prudent.</p>
                                                    <p>The maximum expiry date is in <?php echo Config::get('keep_study_data_for_months_maximum'); ?> months.</p>
                                                    <input class="form-control" type="date" name="expiresOn" id="expiresOn" placeholder="<?php echo date('Y-m-d', strtotime('+' . Config::get('keep_study_data_for_months_maximum') . ' months')); ?>" value="<?php echo $run->expiresOn; ?>">
                                               </div>
                                                <div class="col-md-6">
                                                    <strong>Cookie/Session Lifetime</strong>
                                                    <p>
                                                        Configure how long a user session cookie will last. Once the cookie expires, the user will be logged out.
                                                    </p>
                                                    <div class="input-group">
                                                        <div class="input-group-addon"> Expire After </div>
                                                        <input type="number" class="form-control" name="expire_cookie_value" value="<?php echo $run->expire_cookie_value; ?>" />
                                                        <div class="input-group-addon" style="width: 120px; padding: 0;">
                                                            <select name="expire_cookie_unit" class="form-control" style="padding: 0; border: none; height: 30px;">
                                                                <option value=""> Select Units.. </option>
                                                                <?php foreach ($run->expire_cookie_units as $unit => $label): ?>
                                                                    <option value="<?= $unit ?>" <?php echo $run->expire_cookie_unit === $unit ? 'selected="selected"' : '' ?>> <?= $label ?> </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>


                                            <div class="checkbox form-group"  style="margin-bottom: 15px;">
                                                <div class="col-md-6">
                                                    <strong>Automated actions</strong>
                                                    <p>Enable pause expiration, automatic email sending and other automated operations. Disable if automated actions are not desired, necessary or something seems to be going wrong.</p>
                                                    <label>
                                                        <input type="hidden" name="cron_active" value="0" />
                                                        <input type="checkbox" name="cron_active" <?= ($run->cron_active) ? 'checked' : '' ?> value="1"> Enable automated actions.
                                                    </label>
                                                </div>
                                                <div class="checkbox col-md-6">
                                                    <strong>Look &amp; Feel</strong>
                                                    <p>
                                                        We previously offered an alternative style for surveys, <a target="_blank" href="https://fezvrasta.github.io/bootstrap-material-design/">Material Design</a>. To reduce maintenance burden, we have deprecated this option. If you previously enabled this option, you can still use it, but once you turn it off, you will not be able to turn it back on.
                                                    </p>
                                                    <label title="Material Design style is deprecated as of v0.22.0.">
                                                        <input type="hidden" name="use_material_design" value="0" />
                                                        <input type="checkbox" name="use_material_design" <?= ($run->use_material_design) ? 'checked' : 'disabled' ?> value="1"> Enable Material Design.
                                                    </label>

                                                </div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="description">Description</label>
                                                <p>Will be shown at the top of every page of the study. Optional.</p>
                                                <textarea data-editor="markdown" placeholder="Description" name="description" id="description" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->description); ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <p>Your Imprint should contain information about who is responsible for the study, and how they can be contacted. It should also link to your privacy policy and in some cases to the settings page, where users can unsubscribe from emails and log out.</p>
                                                <label title="Will be shown on every page of the run, good for contact info" for="footer_text">Imprint/Footer text</label>
                                                <textarea data-editor="markdown" placeholder="Footer text" name="footer_text" id="footer_text" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->footer_text); ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label title="This will be the description of your study shown on the public page" for="public_blurb">Public blurb</label>
                                                <p>This will be the description of your study shown on the <a href="<?php echo site_url("/public/studies"); ?>" target="_blank">public page</a>. Optional.</p>
                                                <textarea data-editor="markdown" placeholder="Blurb" name="public_blurb" id="public_blurb" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->public_blurb); ?></textarea>
                                            </div>



                                        </div>
                                    </form>
                                    <div class="clear clearfix"></div>
                                </div>
                                <div class="tab-pane" id="privacy">
                                    <form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">
                                        <p class="pull-right">
                                            <input type="submit" name="submit_settings" value="Save" class="btn btn-primary save_settings">
                                        </p>
                                        <h4><i class="fa fa-vcard"></i> Privacy</h4>
                                        <p>
                                            The following settings are used by the Privacy Run Unit. They are used to inform users about the privacy policy, terms of service and imprint of your study.
                                            These are required by law in some countries. Add a Privacy Consent Unit to your run to make use of these settings.
                                            You can use Markdown to format the text.
                                        </p>
                                        <p>
                                            A Privacy Policy and Imprint are required by law in some countries. That's why we require you to provide them.
                                        </p>
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <p>
                                                    Your Privacy Policy should contain information about the data you collect, how you collect it, how you store it, how you use it and how you protect it.
                                                    You should also provide information about how users can contact you to request information about the data you have collected about them, and how they can request that you delete that data.
                                                    For more information and a template, see <a href="https://gdpr.eu/privacy-notice/">the guide at gdpr.eu</a>.
                                                </p>
                                                <label title="Used by the Privacy Run Unit">Privacy Policy (<a href="<?php echo run_url($run->name, '', ['show-privacy-page' => 'privacy-policy']); ?>" target="_blank">View</a>)</label>
                                                <textarea data-editor="markdown" placeholder="Privacy Policy" name="privacy" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->privacy); ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <p>
                                                    Your Terms of Service should contain information about how users are allowed to use your study, and what they are not allowed to do.
                                                </p>
                                                <label title="Used by the Privacy Run Unit">Terms of Service (<a href="<?php echo run_url($run->name, '', ['show-privacy-page' => 'terms-of-service']); ?>" target="_blank">View</a>)</label>
                                                <textarea data-editor="markdown" placeholder="Terms of Service" name="tos" rows="10" cols="80" class="big_ace_editor form-control"><?= h($run->tos); ?></textarea>
                                            </div>
                                        </div>
                                    </form>
                                    <div class="clear clearfix"></div>
                                </div>
                                <!-- /.tab-pane -->
                                <div class="tab-pane" id="css">
                                    <form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">
                                        <p class="pull-right">
                                            <input type="submit" name="submit_settings" value="Save" class="btn btn-primary save_settings">
                                        </p>
                                        <h4><i class="fa fa-css3"></i> Cascading style sheets</h4>
                                        <p>
                                            CSS allows you to apply custom styles to every page of your study. If you want to limit styles to
                                            certain pages, you can use CSS classes referring to either position in the run (e.g. <code class="css">.run_position_10 {}</code>) or module type (e.g. <code class="css">.run_unit_type_Survey {}</code>). Learn about <a href="https://developer.mozilla.org/en-US/docs/Learn/CSS/First_steps/Getting_started">CSS at Mozilla Developer Network</a>. A chat bot could also help you get the requested result.
                                        </p>
                                        <div class="form-group col-md-12">
                                            <textarea data-editor="css" placeholder="Enter your custom CSS here" name="custom_css" rows="40" cols="80" class="big_ace_editor form-control"><?= h($run->getCustomCSS()); ?></textarea>
                                        </div>

                                    </form>
                                    <div class="clear clearfix"></div>
                                </div>
                                <!-- /.tab-pane -->
                                <div class="tab-pane" id="js">
                                    <form class="form-horizontal" enctype="multipart/form-data"  id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">
                                        <p class="pull-right">
                                            <input type="submit" name="submit_settings" value="Save" class="btn btn-primary save_settings">
                                        </p>
                                        <h4><i class="fa fa-javascript"></i> JavaScript</h4>
                                        <p>
                                            Javascript allows you to apply custom scripts to every page of your study. This is a fully-fledged programming language. You can use it to make things move, give dynamic hints to the user and so on. Learn about <a href="https://www.codecademy.com/tracks/javascript">JS at Codecademy.com</a>.
                                        </p>
                                        <div class="form-group col-md-12">
                                            <textarea data-editor="javascript" placeholder="Enter your custom JS here" name="custom_js" rows="40" cols="80" class="big_ace_editor form-control"><?= h($run->getCustomJS()); ?></textarea>
                                        </div>

                                    </form>
                                    <div class="clear clearfix"></div>
                                </div>
                                <!-- /.tab-pane -->
                                <div class="tab-pane" id="manifest">
                                <h3 id="app_heading">App</h3>
                                <p>Formr studies can be installed as a PWA (Progressive Web App) on the home screen. This allows you to send push notifications to users to invite them to return to the study, e.g. for experience sampling studies. </p>
                                <h4><i class="fa fa-folder-open"></i> PWA Icons & Splash Screens</h4>
                                    <form class="form-horizontal" enctype="multipart/form-data" id="pwa_icons_form" method="post" action="<?php echo admin_run_url($run->name, 'ajax_upload_pwa_icon_folder'); ?>">
                                        <div class="form-group col-md-12">
                                            <label for="pwa_icon_folder_input">Upload PWA Icon/Splash Screen Folder</label>
                                            <p>
                                                Upload a folder containing your PWA icons and splash screens (e.g., <code>icon.png</code>, <code>maskable_icon.png</code>, <code>apple-touch-icon.png</code>, <code>iPhone_XR_portrait.png</code>, etc.).
                                                This will replace any previously uploaded PWA icon set. The specific filenames needed are referenced in the manifest (see above) and by the system for apple touch icons. 
                                                The uploaded folder's path will be stored. Ensure the filenames within your folder match those expected. You can use <a href="https://progressier.com/pwa-icons-and-ios-splash-screen-generator">this tool</a> to generate these images from one image with the right file names.
                                            </p>
                                            <input type="file" name="pwa_icon_files[]" id="pwa_icon_folder_input" webkitdirectory directory multiple class="form-control">
                                            <?php if ($run->getPwaIconPath()): ?>
                                                <p class="help-block">Currently set PWA icon path: <code><?php echo h($run->getPwaIconPath()); ?></code></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group col-md-12">
                                            <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> Upload PWA Icon Folder</button>
                                            <?php if ($run->getPwaIconPath()): ?>
                                                <button type="button" id="clear_pwa_icons_button" data-action-url="<?php echo admin_run_url($run->name, 'ajax_clear_pwa_icons'); ?>" class="btn btn-danger pull-right"><i class="fa fa-trash"></i> Clear PWA Icons</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                    <hr />

                                
                                    <form class="form-horizontal" enctype="multipart/form-data" id="run_settings" method="post" action="<?php echo admin_run_url($run->name, 'ajax_save_settings'); ?>">
                                        <p class="pull-right">
                                            <button data-href="<?php echo admin_run_url($run->name, 'ajax_generate_manifest'); ?>" class="btn btn-default generate-manifest"><i class="fa fa-magic"></i> Generate Manifest</button>
                                            <input type="submit" name="submit_settings" value="Save Manifest Text" class="btn btn-primary save_settings">
                                        </p>
                                        <h4><i class="fa fa-cogs"></i> App Manifest</h4>
                                        <p>
                                            To make PWAs work, you need to generate a manifest.json file in the study/run settings. Just click the button with the magic wand to generate a manifest.json file based on your run settings. You can then customize it further. A manifest.json file is required for the PWA (adding to home screen, push notifications) to work.
                                        </p>
                                        <p>
                                            If you don't eschew the effort, you can package your PWA and distribute via one of the app stores. For a report on your PWA, customize your manifest, make your study public and see <a href="https://www.pwabuilder.com/reportcard?site=<?php echo run_url($run->name); ?>" target="_blank">PWA report on PWA Builder</a>.
                                        </p>

                                    
                                        <div class="form-group col-md-12">
                                            <textarea data-editor="json" placeholder="Enter your manifest JSON here" name="manifest_json"  id="manifest_json" rows="25" cols="80" class="big_ace_editor form-control"><?= h($run->getManifestJSON()); ?></textarea>
                                        </div>
                                    </form>

                                    <div class="clear clearfix"></div>
                                </div>
                                <div class="tab-pane" id="service_message">
                                    <div class="col-md-12">
                                        <div class="add">
                                            <h3><i class="fa fa-eject"></i> Edit service message</h3>
                                            <ul class="fa-ul fa-ul-more-padding">
                                                <li><i class="fa-li fa fa-cog fa-lg fa-spin"></i> If you are making changes to your run, while it's live, you may want to keep your users from using it at the time. <br>Use this message to let them know that the run will be working again soon.</li>
                                                <li><i class="fa-li fa fa-lg fa-stop"></i> You can also use this message to end a study, so that no new users will be admitted and old users who are not finished cannot go on.</li>
                                            </ul>
                                            <?php if (empty($service_messages)): ?>
                                                <a href="<?= admin_run_url($run->name, 'create_run_unit?type=Page&special=ServiceMessagePage&redirect=settings:::service_message') ?>" class="btn btn-default pull-right add_run_unit"><i class="fa fa-plus"></i> Add Service Message</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="clearfix"></div>
                                        <div class="row special-units  reminder-cells">
                                            <?php foreach ($service_messages as $message): ?>
                                                <div class="col-md-11 single_unit_display">
                                                    <form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?php echo admin_run_url($run->name); ?>" data-units='<?php echo json_encode($message['html_units']); ?>'>
                                                        <div class="run_units"></div>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="clear clearfix"></div>
                                </div>
                                <div class="tab-pane" id="reminder">
                                    <div class="col-md-12">
                                        <div class="add">
                                            <h3><i class="fa fa-bullhorn"></i> Add/Modify Email Reminders</h3>
                                            <p>
                                                Modify the text of a reminder, which you can then send to any user using the <i class="fa fa-bullhorn"></i> reminder button in the <a href="<?php echo admin_run_url($run->name, 'user_overview'); ?>">user overview</a>.
                                            </p>
                                            <a href="<?= admin_run_url($run->name, 'create_run_unit?type=Email&special=ReminderEmail&redirect=settings:::reminder') ?>" class="btn btn-default pull-right add_run_unit"><i class="fa fa-plus"></i> Add Reminder</a>
                                        </div>
                                        <div class="clearfix"></div>
                                        <div class="row special-units  reminder-cells">
                                            <?php foreach ($reminders as $reminder): ?>
                                                <div class="col-md-6 single_unit_display">
                                                    <form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?php echo admin_run_url($run->name); ?>" data-units='<?php echo json_encode($reminder['html_units']); ?>'>

                                                        <a href="<?= admin_run_url($run->name, 'delete_run_unit?type=Email&special=ReminderEmail&redirect=settings:::reminder&unit_id=' . $reminder['id']) ?>" class="reminder-delete remove_unit_from_run" data-action="<?php echo admin_run_url($run->name); ?>" data-id="<?php echo $reminder['id']; ?>"><i class="fa fa-2x fa-trash"></i></a>
                                                        <div class="run_units"></div>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="clear clearfix"></div>
                                </div>
                                <div class="tab-pane" id="overview_script">
                                    <div class="row">
                                        <div class="add">
                                            <h3><i class="fa fa-eye"></i> Edit overview script</h3>
                                            <ul class="fa-ul fa-ul-more-padding">
                                                <li><i class="fa-li fa fa-code"></i> In here, you can use Markdown and R interspersed to make a custom overview for your study.</li>
                                                <li><i class="fa-li fa fa-lg fa-thumb-tack"></i> Useful commands to start might be <pre><code class="r">nrow(survey_name) # get the number of entries
table(is.na(survey_name$ended)) # get finished/unfinished entries
table(is.na(survey_name$modified)) # get entries where any data was entered vs not
library(ggplot2)
qplot(survey_name$created) # plot entries by startdate</code></pre></li>
                                            </ul>
                                            <?php if (empty($overview_scripts)): ?>
                                                <a href="<?= admin_run_url($run->name, 'create_run_unit?type=Page&special=OverviewScriptPage&redirect=settings:::overview_script') ?>" class="btn btn-default pull-right add_run_unit"><i class="fa fa-plus"></i> Add Overview Script</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="clearfix"></div>
                                        <div class="row special-units  reminder-cells">
                                            <?php foreach ($overview_scripts as $script): ?>
                                                <div class="col-md-11 single_unit_display">
                                                    <form class="form-horizontal edit_run" enctype="multipart/form-data" name="edit_run" method="post" action="<?php echo admin_run_url($run->name); ?>" data-units='<?php echo json_encode($script['html_units']); ?>'>
                                                        <div class="run_units"></div>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="clear clearfix"></div>
                                </div>
                                <div class="tab-pane" id="osf">
                                    <div class="col-md-12">
                                        <div class="single_unit_display">
                                            <?php if (empty($osf_token)): ?>
                                                <p>
                                                    <br /><br />
                                                    <a href="<?php echo site_url('api/osf/login?redirect=admin/run/' . $run->name . '/settings'); ?>" class="btn btn-default"><i class="fa fa-link"></i> Connect to the &nbsp;<img src="<?= asset_url('build/img/osf-icon.png') ?>" alt="OSF Icon" /> <b>Open Science Framework</b></a>
                                                </p>
                                            <?php else: ?>
                                                <br /><br />
                                                <div class="panel panel-default" id="panel1">
                                                    <div class="panel-heading">
                                                        <h4 class="panel-title">
                                                            <a data-toggle="collapse" data-target="#collapseOne"  href="#collapseOne"><i class="fa fa-cloud-upload"></i> Export run structure to OSF project </a>
                                                        </h4>
                                                    </div>
                                                    <div id="collapseOne" class="panel-collapse collapse in">
                                                        <div class="panel-body">
                                                            <form action="<?php echo admin_url('osf'); ?>" method="post" >

                                                                <div class="alert alert-info alert-dismissible">
                                                                    <a href="#" class="close" data-dismiss="alert" aria-label="close" title="close">&times;</a>
                                                                    <i class="fa fa-exclamation-circle"></i> In order to be able to export your <i>run</i> structure to the Open Science Framework,
                                                                    you will first need to create a project on the OSF platform, and then select the corresponding project from the list below.
                                                                    You may need to refresh this page to see a list of newly created projects.
                                                                </div>

                                                                <table class="table table-responsive">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Select OSF Project</th>
                                                                            <th><a class="btn btn-default pull-right" href="<?php echo Config::get('osf.site_url'); ?>" target="_blank"><img src="<?= asset_url('build/img/osf-icon.png') ?>" alt="OSF Icon" /> Create an OSF project</a></th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr>
                                                                            <td>
                                                                                <div class="input-group">
                                                                                    <span class="input-group-addon"><i class="fa fa-rocket"></i></span>
                                                                                    <div class="form-group">
                                                                                        <select name="osf_project" class="form-control">
                                                                                            <option value="">....</option>
                                                                                            <?php
                                                                                            foreach ($osf_projects as $project):
                                                                                                $selected = $project['id'] == $osf_project ? 'selected="selected"' : null
                                                                                                ?>
                                                                                                <option value="<?= $project['id']; ?>" <?= $selected ?>><?= $project['name']; ?> </option>
                                                                                            <?php endforeach; ?>
                                                                                        </select>          
                                                                                    </div>
                                                                                </div>
                                                                            </td>
                                                                            <td class="col-md-5">
                                                                                <input type="hidden" name="formr_project" value="<?php echo $run->name; ?>" />
                                                                                <input type="hidden" name="osf_action" value="export-run" />
                                                                                <input type="hidden" name="redirect" value="admin/run/<?= $run->name ?>/settings#osf" />
                                                                                <button type="submit" class="btn btn-primary btn-large"><i class="fa fa-mail-forward"></i> Export</button>
                                                                            </td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </form>
                                                        </div>	
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="clear clearfix"></div>
                                </div>
                            </div>
                            <!-- /.tab-content -->
                        </div>
                    </div>
                    <!-- /.box-body -->

                </div>

            </div>
        </div>

        <div class="clear clearfix"></div>
    </section>
    <!-- /.content -->
</div>

<?php
Template::loadChild('admin/run/run_modals', array('reminders' => array()));
Template::loadChild('admin/footer');
?>