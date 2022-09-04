<?php header('Content-type: text/html; charset=utf-8'); ?><!DOCTYPE html>
<html class="no_js">
    <head>
        <?php Template::loadChild('public/head') ?>
    </head>

    <body class="<?php echo isset($bodyClass) ? $bodyClass : ''; ?>" data-url="<?php echo run_url($run->name); ?>">

        <div id="fmr-page" class="fmr-about">
            <div class="container run-container">
                <div class="row">
                    <div class="col-lg-12 run_position_<?php echo $run_session->position; ?> run_unit_type_<?php echo $run_session->currentUnitSession ? $run_session->currentUnitSession->runUnit->type : 'missing'; ?> run_content">	
                        <header class="run_content_header">
                            <?php if ($run->header_image_path): ?>
                                <img src="<?php echo $run->header_image_path; ?>" alt="<?php echo $run->name; ?> header image">
                            <?php endif; ?>
                        </header>

                        <div class="alerts-container">
                            <?php Template::loadChild('public/alerts'); ?>
                        </div>

                        <?php echo $run_content; ?>
                    </div>
                </div>
            </div>
        </div>

        <script id="tpl-feedback-modal" type="text/formr">
            <div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="FormR.org Modal" aria-hidden="true">
            <div class="modal-dialog">                         
            <div class="modal-content">                              
            <div class="modal-header">                                 
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>                                 
            <h3>%{header}</h3>                             
            </div>                             
            <div class="modal-body">%{body}</div>
            <div class="modal-footer">                             
            <button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>                         
            </div>                     
            </div>                 
            </div>
            </div>
        </script>
    </body>
</html>