<?php
Template::load('header');
Template::load('acp_nav');
$openCPU = new OpenCPU(Config::get('alternative_opencpu_instance'));

echo "<h2>OpenCPU test</h2>";
echo '<h5>testing '.Config::get('alternative_opencpu_instance').'</h5>';

$max = 30;
for($i=0; $i < $max; $i++):
	
$source = '{'.
mt_rand().'
'.
str_repeat(" ",$i).	
'
library(knitr)
knit2html(text = "' . addslashes("__Hello__ World `r 1`
```{r}
library(ggplot2)  
#qplot(rnorm(100))
#qplot(rnorm(1000), rnorm(1000))
#library(formr)
#'blabla' %contains% 'bla'
```
") . '",
fragment.only = T, options=c("base64_images","smartypants")
)
'.
str_repeat(" ",$max-$i).	
'
}';
$before = microtime(true);
$results = $openCPU->identity(array('x' =>  $source),'', true);
$time = $openCPU->speed();
if($openCPU->http_status > 302 OR $openCPU->http_status === 0) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';

alert("1. HTTP status: ".$openCPU->http_status,$alert_type);

$accordion = $openCPU->debugCall($results);
$after = microtime(true);
$time['before_after_php_time'] = $after - $before;
if(isset($times)):
	foreach($time AS $element => $value):
		$times[$element][] = $value;
	endforeach;
else:
	$times = array();
	foreach($time AS $element => $value):
		$times[$element] = array($value);
	endforeach;
endif;

endfor;




$source = '```{r}
library(ggplot2)
library(stringr)
library(reshape2)
just_times = times[,str_detect(names(times), "time")]
times_m = melt(just_times)
qplot(value, data = times_m) + facet_wrap(~ variable)
just_speed = times[,str_detect(names(times), "speed")]
speed_m = melt(just_speed)
qplot(value, data = speed_m) + facet_wrap(~ variable)
just_size = times[,str_detect(names(times),"_size")]
size_m = melt(just_size)
qplot(value, data = size_m) + facet_wrap(~ variable)
summary(times)
```';
unset($times['certinfo']);

$openCPU->addUserData(array("times"=>$times));
$accordion = $openCPU->knitForAdminDebug( $source);
if($openCPU->http_status > 302 OR $openCPU->http_status === 0) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';
alert("1. HTTP status: ".$openCPU->http_status,$alert_type);

echo "<h4>test knitr with plot</h4>";
echo $accordion;

$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '<div class="row"><div class="col-md-8 all-alerts">';
	echo $alerts;
	echo '</div></div>';
endif;

Template::load('footer');
