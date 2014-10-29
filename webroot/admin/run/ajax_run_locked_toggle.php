<?php
if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_POST['on'])):
		if(!$run->toggleLocked((bool)$_POST['on']))
			echo 'Error!';
		$site->renderAlerts();
	endif;
endif;
