<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "view_header.php";
require_once INCLUDE_ROOT . "acp/acp_nav.php";
?>	
<h2>cron log</h2>
<p>
	The cron job runs every x minutes, to evaluate whether somebody needs to be sent a mail. This usually happens if a pause is over. It will then go through branches and emails, but will stop at surveys and pages, because they should be viewed by the user.<br>
	The log is only updated if something actually happened (ie a branch, a pause or an email is evaluated).
</p>


<pre>
<?php
	$file =INCLUDE_ROOT. "tmp/logs/cron.log";
	$cron = fopen($file,"r");
	$cronlog = fread($cron, filesize($file));
	echo $cronlog;
	?>
</pre>
	
<?php
require_once INCLUDE_ROOT . "view_footer.php";