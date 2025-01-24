<?php $settings = Site::getSettings(); ?>

<footer id="fmr-footer">
	<div class="container">
		<div class="row row-bottom-padded-md">
			<div class="col-md-3 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>formr</h3>
					<ul class="fmr-links">
						<li><a href="<?= site_url('about')?>">About</a></li>
						<?php if (array_val($settings, 'content:docu:show', 'true') === 'true'): ?>
                            <li><a href="<?php echo site_url('documentation'); ?>">Documentation</a></li>
                        <?php endif; ?>
						<?php if (array_val($settings, 'content:studies:show', 'true') === 'true'): ?>
                            <li><a href="<?php echo site_url('studies'); ?>">Studies</a></li>
                        <?php endif; ?>
						<li><a href="<?= site_url("privacy_policy") ?>">Privacy Policy</a></li>
					</ul>
				</div>
			</div>

			<div class="col-md-3 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>Get support</h3>
					<ul class="fmr-links">
                        <?php if (array_val($settings, 'content:docu:show', 'true') === 'true'): ?>
                            <li><a href="<?= site_url('documentation#help')?>">How to get help</a></li>
                        <?php endif; ?>
						<li><a href="https://github.com/rubenarslan/formr.org/discussions">formr Github discussions</a></li>
						<li><a href="https://github.com/rubenarslan/formr.org">formr github</a></li>
					</ul>
				</div>
			</div>

			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>Imprint</h3>
					<p>
						<?= nl2br(array_val($settings, 'footer:imprint')) ?>
					</p>
				</div>
			</div>

			<div class="col-md-2 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<ul class="fmr-links">
                        <?php if (array_val($settings, 'footer:link:logourl')): ?>
                            <li><a href="<?= array_val($settings, 'footer:link:logolink') ?>" target="_blank"><img src="<?= array_val($settings, 'footer:link:logourl') ?>" alt="LOGO"></a></li>
                        <?php endif; ?>
                    </ul>
				</div>
			</div>

		</div>

	</div>

</footer>
<!-- END #fmr-footer -->
</div>
<!-- END #fmr-page -->

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
