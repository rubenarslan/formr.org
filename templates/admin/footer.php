
            <footer class="main-footer">
                <div class="pull-right hidden-xs">Copyright &copy; <?= date('Y') ?> formr<?php
				if (!empty($user) && $user->loggedIn()) {
					echo ' - ' . FORMR_VERSION;
				}
					?></div>
                <ul class="nav navbar-nav">
                    <li><a href="https://github.com/rubenarslan/formr.org" target="_blank"><i class="fa fa-github-alt fa-fw"></i> Github repository </a></li>
                    <li><a href="https://github.com/rubenarslan/formr" target="_blank"><i class="fa fa-github-alt fa-fw"></i> R package on Github </a></li>
                </ul>
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
