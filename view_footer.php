<?php
	$_SESSION['site'] = $site;
	$_SESSION['user'] = serialize($user);

	session_write_close();
#	pr($_SESSION);
?>
</div> <!-- end of main content div -->


</body>
</html>