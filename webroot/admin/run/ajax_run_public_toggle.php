<?php

if( env('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest' ):
	if(isset($_GET['public'])):
		if(!$run->togglePublic((int)$_GET['public']))
			echo 'Error!';
	endif;
endif;
