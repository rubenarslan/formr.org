<?php

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_POST['on'])):
		if(!$run->toggleCron((bool)$_POST['on']))
			echo 'Error!';
	endif;
endif;
