<?php
Template::loadChild('public/header', array(
    'headerClass' => 'fmr-small-header',
));
?>

<section id="fmr-projects" style="padding-top: 2em;">
    <div class="container">
        <div class="row text-center row-bottom-padded-md">
            <div class="col-md-8 col-md-offset-2">
                <h2 class="fmr-lead animate-box">Privacy Policy</h2>
                <p class="fmr-sub-lead animate-box">Privacy Policy for formr</p>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <?php
                    echo Site::getSettings('content:privacy_policy'); 
                ?>
            </div>
        </div>
    </div>
</section>

<?php Template::loadChild('public/footer'); ?> 