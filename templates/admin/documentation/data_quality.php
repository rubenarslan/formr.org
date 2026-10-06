<?php
// Admin-only documentation (AdminDocumentationController::dataQualityAction).
// Plain language for researchers; the implementation notes are in
// documentation/agent_doc/data_quality_plan.md.
Template::loadChild('admin/header');
// The parts see this page's variables (Template::loadChild).
$part = function ($name) {
    Template::loadChild('admin/documentation/data_quality/' . $name);
};
?>

<style>
    .fmr-admin-doc pre { white-space: pre-wrap; word-break: normal; }
    .fmr-admin-doc h3 { margin-top: 2em; }
    .fmr-admin-doc td code { white-space: nowrap; }
</style>
<div class="content-wrapper" id="admin-documentation-data-quality">
    <section class="content-header">
        <h1><?= h($title) ?> <small><a href="<?= admin_url('documentation') ?>">Admin documentation</a></small></h1>
    </section>

    <section class="content">
        <?php Template::loadChild('public/alerts'); ?>
        <div class="row">
            <div class="col-md-9">
                <div class="box box-primary">
                    <div class="box-body fmr-admin-doc">
                        <p class="lead">
                            Two item types let formr flag AI agents and automated tools that fill in your survey, and
                            careless responding by people. They need no outside service and store only counts,
                            durations and yes/no values. They do nothing in a study that does not use them.
                        </p>
                        <p>
                            This page is for logged-in admins only. Please do not publish it: the more widely the details
                            are known, the easier they are to get around.
                        </p>
                        <ol>
                            <li><a href="#dq-checks">The four checks at a glance</a></li>
                            <li><a href="#dq-setup">Setting it up: the rows to add</a></li>
                            <li><a href="#dq-consent">The consent text</a></li>
                            <li><a href="#dq-results">Reading the results</a></li>
                            <li><a href="#dq-tests">What our tests showed</a></li>
                            <li><a href="#dq-ecosystem">What the bot ecosystem looks like</a></li>
                            <li><a href="#dq-limits">Privacy and limits</a></li>
                            <li><a href="#dq-botcheck">How this relates to <code>bot_check</code></a></li>
                            <li><a href="#dq-codebook">Codebook: what a record holds</a></li>
                        </ol>

                        <?php
                        $part('checks');
                        $part('setup');
                        $part('consent');
                        $part('results');
                        $part('findings');
                        $part('limits');
                        $part('codebook');
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php Template::loadChild('admin/footer'); ?>
