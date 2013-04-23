			</div> <!-- end of span10 div -->
			<div class="span2">
			    <img src="img/<?=LOGO?>">
			   <a href="index.php">Zurück</a>
			</div>
		</div> <!-- end of row-fluid div -->
		<div class="row-fluid">
			<div class="span12">
				Bei Problemen wenden Sie sich bitte an <strong><a href="mailto:"<?=EMAIL?>"><?=EMAIL?></a>.</strong>
			</div>
		</div>
	</div>
</div>


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


</body>
</html>

<?php 
if(OUTBUFFER) {
	ob_end_flush();
}
