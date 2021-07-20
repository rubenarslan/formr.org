<footer id="fmr-footer">
	<div class="container">
		<div class="row row-bottom-padded-md">
			<div class="col-md-3 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>formr</h3>
					<ul class="fmr-links">
						<li><a href="<?= site_url('about')?>">About</a></li>
						<li><a href="<?= site_url('documentation')?>">Documentation</a></li>
						<li><a href="<?= site_url('studies')?>">Studies</a></li>
						<li><a href="<?= Site::getSettings('links:policyurl') ?>" target="_blank">Privacy Policy</a></li>
					</ul>
				</div>
			</div>

			<div class="col-md-3 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>Get support</h3>
					<ul class="fmr-links">
						<li><a href="<?= site_url('documentation#help')?>">How to get help</a></li>
						<li><a href="https://groups.google.com/d/forum/formr">formr google group</a></li>
						<li><a href="https://github.com/rubenarslan/formr.org">formr github</a></li>
					</ul>
				</div>
			</div>

			<div class="col-md-4 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>Imprint</h3>
					<p>
						<?= nl2br(Site::getSettings('content:footerimprint')) ?>
					</p>
				</div>
			</div>

			<div class="col-md-2 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<ul class="fmr-links">
						<li><a href="<?= Site::getSettings('links:logolink') ?>" target="_blank"><img src="<?= Site::getSettings('links:logourl') ?>" alt="Uni Göttingen logo"></a></li>
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
