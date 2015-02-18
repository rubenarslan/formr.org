<?php
# used_opencpu(true);
# used_cache(true);
# used_nginx_cache(true);
session_over($site, $user);
?>
		</div> <!-- end of main body -->
	</div> <!-- end of sidenav container -->
</div> <!-- end of main content div -->
		<?php if ($site->inAdminArea()): ?>
		<script type="text/javascript" src="<?= WEBROOT ?>assets/<?=DEBUG?"lib":"minified"?>/ace/ace.js"></script>	
		<?php endif; ?>

</body>
</html>