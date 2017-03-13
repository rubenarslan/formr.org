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
					</ul>
				</div>
			</div>

			<div class="col-md-3 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>Support</h3>
					<ul class="fmr-links">
						<li><a href="https://groups.google.com/d/forum/formr">formr google group</a></li>
						<li><a href="https://github.com/rubenarslan/formr.org">formr github</a></li>
					</ul>
				</div>
			</div>

			<div class="col-md-3 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<h3>Contact Us</h3>
					<p>
						University of Goettingen <br />
						Goßlerstraße 14, <br>
						37073 Göttingen <br>
						Germany
					</p>
				</div>
			</div>

			<div class="col-md-3 col-sm-6 col-xs-12 animate-box">
				<div class="fmr-footer-widget">
					<ul class="fmr-links">
						<li><a href="https://www.uni-goettingen.de/" target="_blank"><img src="<?= asset_url('build/img/goettingen_uni.png') ?>" alt="Uni Göttingen logo"></a></li>
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
