<?php Template::loadChild('admin/header'); ?>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>Content Settings</h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">

                    <div class="box-body">
                        <?php Template::loadChild('public/alerts'); ?>
                        <div class="nav-tabs-custom">
                            <ul class="nav nav-tabs">
                                <li class="active"><a href="#about-page" data-toggle="tab" aria-expanded="true">About Page</a></li>
                                <li><a href="#docu-page" data-toggle="tab" aria-expanded="true">Docu Page</a></li>
                                <li><a href="#studies-page" data-toggle="tab" aria-expanded="true">Studies Page</a></li>
                                <li><a href="#publications-page" data-toggle="tab" aria-expanded="true">Publications Page</a></li>
                                <li><a href="#footer-page" data-toggle="tab" aria-expanded="true">Footer</a></li>
                                <li><a href="#signup-page" data-toggle="tab" aria-expanded="true">Sign-Ups</a></li>
                                <li><a href="#privacy-policy" data-toggle="tab" aria-expanded="true">Privacy Policy</a></li>
                                <li><a href="#js-page" data-toggle="tab" aria-expanded="false">JavaScript Configuration</a></li>
                            </ul>
                            <div class="tab-content">
                                <div class="tab-pane active" id="about-page">
                                    <?php Template::loadChild('admin/advanced/settings/about-page'); ?>
                                </div>
                                <div class="tab-pane" id="docu-page">
                                    <?php Template::loadChild('admin/advanced/settings/docu-page'); ?>
                                </div>
                                <div class="tab-pane" id="studies-page">
                                    <?php Template::loadChild('admin/advanced/settings/studies-page'); ?>
                                </div>
                                <div class="tab-pane" id="publications-page">
                                    <?php Template::loadChild('admin/advanced/settings/publications-page'); ?>
                                </div>
                                <div class="tab-pane" id="footer-page">
                                    <?php Template::loadChild('admin/advanced/settings/footer'); ?>
                                </div>
                                <div class="tab-pane" id="signup-page">
                                    <?php Template::loadChild('admin/advanced/settings/signup'); ?>
                                </div>
                                <div class="tab-pane" id="js-page">
                                    <?php Template::loadChild('admin/advanced/settings/js'); ?>
                                </div>
                                <div class="tab-pane" id="privacy-policy">
                                    <?php Template::loadChild('admin/advanced/settings/privacy-policy'); ?>
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