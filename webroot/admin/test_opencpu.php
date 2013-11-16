<?php
require_once '../../define_root.php';

require_once INCLUDE_ROOT.'View/admin_header.php';


require_once INCLUDE_ROOT . "Model/OpenCPU.php";
$openCPU = new OpenCPU($settings['alternative_opencpu_instance']);

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";

echo "<h2>OpenCPU test</h2>";
echo '<h5>testing '.$settings['alternative_opencpu_instance'].'</h5>';

$source = '{
library(knitr)
knit2html(text = "' . addslashes("__Hello__ World `r 1`
```{r}
library(ggplot2)
qplot(rnorm(100))
```
") . '",
fragment.only = T, options=c("base64_images","smartypants")
)
}';
$results = $openCPU->identity(array('x' =>  $source),'', true);
		
if($openCPU->http_status > 302) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';

alert("HTTP status: ".$openCPU->http_status,$alert_type);
		
		
$accordion = $openCPU->debugCall($results);


$openCPU = new OpenCPU($settings['alternative_opencpu_instance']);
$source = '{
rnorm(10)
}';
$results = $openCPU->identity(array('x' =>  $source),'', true);
if($openCPU->http_status > 302) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';
alert("HTTP status: ".$openCPU->http_status,$alert_type);
$accordion2 = $openCPU->debugCall($results);


$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '<div class="row"><div class="col-md-8 all-alerts">';
	echo $alerts;
	echo '</div></div>';
endif;

echo "<h4>test knitr with plot</h4>";

echo $accordion;

echo "<h4>test simple function</h4>";

echo $accordion2;