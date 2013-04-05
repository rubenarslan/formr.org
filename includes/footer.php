<? 
// MySQL-Verbindung schließen (wenn wir es nutzen)
mysql_close();

// Analytics-Code setzen, wenn in Settings aktiviert.

if ($useanalytics == "yes") {
echo "<script type=\"text/javascript\">
	var gaJsHost = ((\"https:\" == document.location.protocol) ? \"https://ssl.\" : \"http://www.\");
	document.write(unescape(\"%3Cscript src='\" + gaJsHost + \"google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E\"));
</script>
<script type=\"text/javascript\">
	try{ 
		var pageTracker = _gat._getTracker(\"". $analyticsid ."\");
		pageTracker._trackPageview();
	} catch(err) {} 
</script>";
};
?>
</div>

<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
<script>window.jQuery || document.write('<script src="js/vendor/jquery-1.9.1.js"><\/script>')</script>
<script src="js/bootstrap.js"></script>
</body>
</html>

<?php 
if(OUTBUFFER) {
	ob_end_flush();
}
