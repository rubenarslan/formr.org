
            <footer class="main-footer">
                <!-- To the right -->
                <div class="pull-right hidden-xs">
                    <a href="#">support formr</a>
                </div>
                <!-- Default to the left -->
                <strong>Copyright &copy; <?= date('Y') ?> <a href="<?= site_url(); ?>">formr</a></strong>
            </footer>

        </div>
        <!-- ./wrapper -->

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
