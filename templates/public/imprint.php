<?php
Template::loadChild('public/header', array(
    'headerClass' => 'fmr-small-header',
));
?>

<section id="fmr-projects" style="padding-top: 2em;">
    <div class="container">
        <div class="row text-center row-bottom-padded-md">
            <div class="col-md-8 col-md-offset-2">
                <h2 class="fmr-lead animate-box">Imprint</h2>
                <p class="fmr-sub-lead animate-box">Imprint for formr</p>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <?php
                    echo nl2br(Site::getSettings('footer:imprint'));
                ?>
            </div>
        </div>
        <div class="row" style="margin-top: 2em;">
            <div class="col-md-12">
                <p>
                    <a href="<?= site_url('terms_of_service') ?>">Terms of Service</a> |
                    <a href="<?= site_url('privacy_policy') ?>">Privacy Policy</a>
                </p>
            </div>
        </div>
    </div>
</section>

<?php Template::loadChild('public/footer'); ?>
